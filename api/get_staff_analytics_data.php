<?php
/**
 * API: Get Staff Analytics Data - CORRECTED VERSION
 * File: api/get_staff_analytics_data.php
 * 
 * FIXES:
 * - Changed all sa.id to sa.attendance_id
 * - Changed position to designation throughout
 */

header('Content-Type: application/json');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once "../auth.php";
require_once '../conn.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Role-based access control
$allowed_roles = ['developer', 'super_user', 'school_leader', 'admin'];

if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), array_map('strtolower', $allowed_roles))) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied'
    ]);
    exit();
}

try {
    // Sanitize and validate inputs
    $date_from = filter_input(INPUT_GET, 'date_from', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: date('Y-m-01');
    $date_to = filter_input(INPUT_GET, 'date_to', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: date('Y-m-t');
    $department = filter_input(INPUT_GET, 'department', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '';
    $position = filter_input(INPUT_GET, 'position', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '';

    // Validate dates
    $date_from_obj = DateTime::createFromFormat('Y-m-d', $date_from);
    $date_to_obj = DateTime::createFromFormat('Y-m-d', $date_to);

    if (!$date_from_obj || !$date_to_obj) {
        throw new Exception("Invalid date format");
    }

    // Build WHERE clause for filters
    $where_conditions = ["s.Status = 'active'"];
    $params = [];
    $types = "";

    if (!empty($department)) {
        $where_conditions[] = "s.department = ?";
        $params[] = $department;
        $types .= "s";
    }

    if (!empty($position)) {
        $where_conditions[] = "s.designation = ?";
        $params[] = $position;
        $types .= "s";
    }

    $where_clause = implode(" AND ", $where_conditions);

    // ==================== KPIs ====================
    $kpis_sql = "
        SELECT 
            COUNT(DISTINCT s.staff_id) as total_staff,
            COUNT(CASE WHEN sa.status = 'present' THEN 1 END) as present,
            COUNT(CASE WHEN sa.status = 'absent' THEN 1 END) as absent,
            COUNT(CASE WHEN sa.status = 'late' THEN 1 END) as late,
            COUNT(CASE WHEN sa.status = 'on_leave' THEN 1 END) as on_leave,
            ROUND(
                (COUNT(CASE WHEN sa.status = 'present' THEN 1 END) * 100.0) / 
                NULLIF(COUNT(sa.attendance_id), 0), 
                1
            ) as attendance_rate
        FROM staff s
        LEFT JOIN staff_attendance sa ON s.staff_id = sa.staff_id 
            AND sa.date BETWEEN ? AND ?
        WHERE $where_clause
    ";

    $stmt = $conn->prepare($kpis_sql);
    if (!empty($params)) {
        $bind_params = array_merge([$date_from, $date_to], $params);
        $bind_types = "ss" . $types;
        $stmt->bind_param($bind_types, ...$bind_params);
    } else {
        $stmt->bind_param("ss", $date_from, $date_to);
    }
    $stmt->execute();
    $kpis_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Calculate rate change (compare with previous period)
    $period_days = (strtotime($date_to) - strtotime($date_from)) / 86400;
    $prev_date_from = date('Y-m-d', strtotime($date_from . " -$period_days days"));
    $prev_date_to = date('Y-m-d', strtotime($date_from . " -1 day"));

    $prev_rate_sql = "
        SELECT 
            ROUND(
                (COUNT(CASE WHEN sa.status = 'present' THEN 1 END) * 100.0) / 
                NULLIF(COUNT(sa.attendance_id), 0), 
                1
            ) as prev_rate
        FROM staff s
        LEFT JOIN staff_attendance sa ON s.staff_id = sa.staff_id 
            AND sa.date BETWEEN ? AND ?
        WHERE $where_clause
    ";

    $stmt = $conn->prepare($prev_rate_sql);
    if (!empty($params)) {
        $bind_params = array_merge([$prev_date_from, $prev_date_to], $params);
        $stmt->bind_param($bind_types, ...$bind_params);
    } else {
        $stmt->bind_param("ss", $prev_date_from, $prev_date_to);
    }
    $stmt->execute();
    $prev_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $rate_change = round(($kpis_result['attendance_rate'] ?? 0) - ($prev_result['prev_rate'] ?? 0), 1);

    // Count at-risk staff (3+ consecutive absences)
    $at_risk_sql = "
        SELECT COUNT(DISTINCT staff_id) as at_risk
        FROM (
            SELECT 
                sa.staff_id,
                sa.date,
                @row := IF(@staff = sa.staff_id AND sa.status = 'absent', @row + 1, 1) as consecutive,
                @staff := sa.staff_id
            FROM staff_attendance sa
            JOIN staff s ON sa.staff_id = s.staff_id
            WHERE sa.date BETWEEN ? AND ?
                AND $where_clause
            ORDER BY sa.staff_id, sa.date
        ) t
        WHERE consecutive >= 3
    ";

    $stmt = $conn->prepare($at_risk_sql);
    if (!empty($params)) {
        $bind_params = array_merge([$date_from, $date_to], $params);
        $stmt->bind_param($bind_types, ...$bind_params);
    } else {
        $stmt->bind_param("ss", $date_from, $date_to);
    }
    $stmt->execute();
    $at_risk_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $kpis = [
        'total_staff' => (int)$kpis_result['total_staff'],
        'attendance_rate' => (float)($kpis_result['attendance_rate'] ?? 0),
        'present' => (int)$kpis_result['present'],
        'absent' => (int)$kpis_result['absent'],
        'late' => (int)$kpis_result['late'],
        'on_leave' => (int)$kpis_result['on_leave'],
        'rate_change' => $rate_change,
        'at_risk' => (int)($at_risk_result['at_risk'] ?? 0)
    ];

    // ==================== Status Distribution ====================
    $status_distribution = [
        'present' => (int)$kpis_result['present'],
        'absent' => (int)$kpis_result['absent'],
        'late' => (int)$kpis_result['late'],
        'on_leave' => (int)$kpis_result['on_leave']
    ];

    // ==================== Daily Trends ====================
    $daily_sql = "
        SELECT 
            sa.date,
            COUNT(CASE WHEN sa.status = 'present' THEN 1 END) as present,
            COUNT(CASE WHEN sa.status = 'absent' THEN 1 END) as absent,
            COUNT(CASE WHEN sa.status = 'late' THEN 1 END) as late,
            COUNT(CASE WHEN sa.status = 'on_leave' THEN 1 END) as on_leave
        FROM staff_attendance sa
        JOIN staff s ON sa.staff_id = s.staff_id
        WHERE sa.date BETWEEN ? AND ?
            AND $where_clause
        GROUP BY sa.date
        ORDER BY sa.date
    ";

    $stmt = $conn->prepare($daily_sql);
    if (!empty($params)) {
        $bind_params = array_merge([$date_from, $date_to], $params);
        $stmt->bind_param($bind_types, ...$bind_params);
    } else {
        $stmt->bind_param("ss", $date_from, $date_to);
    }
    $stmt->execute();
    $daily_result = $stmt->get_result();
    
    $daily_trends = [];
    while ($row = $daily_result->fetch_assoc()) {
        $daily_trends[] = [
            'date' => $row['date'],
            'present' => (int)$row['present'],
            'absent' => (int)$row['absent'],
            'late' => (int)$row['late'],
            'on_leave' => (int)$row['on_leave']
        ];
    }
    $stmt->close();

    // ==================== Weekly Trends ====================
    $weekly_sql = "
        SELECT 
            WEEK(sa.date) as week,
            ROUND(
                (COUNT(CASE WHEN sa.status = 'present' THEN 1 END) * 100.0) / 
                NULLIF(COUNT(sa.attendance_id), 0), 
                1
            ) as rate
        FROM staff_attendance sa
        JOIN staff s ON sa.staff_id = s.staff_id
        WHERE sa.date BETWEEN ? AND ?
            AND $where_clause
        GROUP BY WEEK(sa.date)
        ORDER BY WEEK(sa.date)
    ";

    $stmt = $conn->prepare($weekly_sql);
    if (!empty($params)) {
        $stmt->bind_param($bind_types, ...$bind_params);
    } else {
        $stmt->bind_param("ss", $date_from, $date_to);
    }
    $stmt->execute();
    $weekly_result = $stmt->get_result();
    
    $weekly_trends = [];
    while ($row = $weekly_result->fetch_assoc()) {
        $weekly_trends[] = [
            'week' => (int)$row['week'],
            'rate' => (float)($row['rate'] ?? 0)
        ];
    }
    $stmt->close();

    // ==================== Monthly Comparison ====================
    $monthly_sql = "
        SELECT 
            DATE_FORMAT(sa.date, '%Y-%m') as month,
            COUNT(CASE WHEN sa.status = 'present' THEN 1 END) as present,
            COUNT(CASE WHEN sa.status = 'absent' THEN 1 END) as absent,
            COUNT(CASE WHEN sa.status = 'late' THEN 1 END) as late
        FROM staff_attendance sa
        JOIN staff s ON sa.staff_id = s.staff_id
        WHERE sa.date BETWEEN ? AND ?
            AND $where_clause
        GROUP BY DATE_FORMAT(sa.date, '%Y-%m')
        ORDER BY month
    ";

    $stmt = $conn->prepare($monthly_sql);
    if (!empty($params)) {
        $stmt->bind_param($bind_types, ...$bind_params);
    } else {
        $stmt->bind_param("ss", $date_from, $date_to);
    }
    $stmt->execute();
    $monthly_result = $stmt->get_result();
    
    $monthly_comparison = [];
    while ($row = $monthly_result->fetch_assoc()) {
        $monthly_comparison[] = [
            'month' => $row['month'],
            'present' => (int)$row['present'],
            'absent' => (int)$row['absent'],
            'late' => (int)$row['late']
        ];
    }
    $stmt->close();

    // ==================== Department Performance ====================
    $dept_sql = "
        SELECT 
            s.department,
            ROUND(
                (COUNT(CASE WHEN sa.status = 'present' THEN 1 END) * 100.0) / 
                NULLIF(COUNT(sa.attendance_id), 0), 
                1
            ) as rate
        FROM staff s
        LEFT JOIN staff_attendance sa ON s.staff_id = sa.staff_id 
            AND sa.date BETWEEN ? AND ?
        WHERE s.Status = 'active' AND s.department IS NOT NULL
        " . (!empty($department) ? "AND s.department = ?" : "") . "
        GROUP BY s.department
        ORDER BY rate DESC
    ";

    $stmt = $conn->prepare($dept_sql);
    if (!empty($department)) {
        $stmt->bind_param("sss", $date_from, $date_to, $department);
    } else {
        $stmt->bind_param("ss", $date_from, $date_to);
    }
    $stmt->execute();
    $dept_result = $stmt->get_result();
    
    $department_performance = [];
    while ($row = $dept_result->fetch_assoc()) {
        $department_performance[] = [
            'department' => $row['department'],
            'rate' => (float)($row['rate'] ?? 0)
        ];
    }
    $stmt->close();

    // ==================== Position Performance ====================
    $pos_sql = "
        SELECT 
            s.designation as position,
            ROUND(
                (COUNT(CASE WHEN sa.status = 'present' THEN 1 END) * 100.0) / 
                NULLIF(COUNT(sa.attendance_id), 0), 
                1
            ) as rate
        FROM staff s
        LEFT JOIN staff_attendance sa ON s.staff_id = sa.staff_id 
            AND sa.date BETWEEN ? AND ?
        WHERE s.Status = 'active' AND s.designation IS NOT NULL
        " . (!empty($position) ? "AND s.designation = ?" : "") . "
        GROUP BY s.designation
        ORDER BY rate DESC
    ";

    $stmt = $conn->prepare($pos_sql);
    if (!empty($position)) {
        $stmt->bind_param("sss", $date_from, $date_to, $position);
    } else {
        $stmt->bind_param("ss", $date_from, $date_to);
    }
    $stmt->execute();
    $pos_result = $stmt->get_result();
    
    $position_performance = [];
    while ($row = $pos_result->fetch_assoc()) {
        $position_performance[] = [
            'position' => $row['position'],
            'rate' => (float)($row['rate'] ?? 0)
        ];
    }
    $stmt->close();

    // ==================== Day of Week Pattern ====================
    $dow_sql = "
        SELECT 
            DAYOFWEEK(sa.date) as day_num,
            COUNT(CASE WHEN sa.status = 'present' THEN 1 END) as present_count,
            COUNT(sa.attendance_id) as total_count
        FROM staff_attendance sa
        JOIN staff s ON sa.staff_id = s.staff_id
        WHERE sa.date BETWEEN ? AND ?
            AND $where_clause
        GROUP BY DAYOFWEEK(sa.date)
        ORDER BY day_num
    ";

    $stmt = $conn->prepare($dow_sql);
    if (!empty($params)) {
        $stmt->bind_param($bind_types, ...$bind_params);
    } else {
        $stmt->bind_param("ss", $date_from, $date_to);
    }
    $stmt->execute();
    $dow_result = $stmt->get_result();
    
    $day_of_week_pattern = [0, 0, 0, 0, 0, 0, 0]; // Sun-Sat
    while ($row = $dow_result->fetch_assoc()) {
        $index = (int)$row['day_num'] - 1; // MySQL DAYOFWEEK: 1=Sunday
        $day_of_week_pattern[$index] = (int)$row['present_count'];
    }
    $stmt->close();

    // ==================== Heatmap Data ====================
    $heatmap_sql = "
        SELECT sa.date, sa.status
        FROM staff_attendance sa
        JOIN staff s ON sa.staff_id = s.staff_id
        WHERE sa.date BETWEEN ? AND ?
            AND $where_clause
        ORDER BY sa.date
    ";

    $stmt = $conn->prepare($heatmap_sql);
    if (!empty($params)) {
        $stmt->bind_param($bind_types, ...$bind_params);
    } else {
        $stmt->bind_param("ss", $date_from, $date_to);
    }
    $stmt->execute();
    $heatmap_result = $stmt->get_result();
    
    $heatmap_data = [];
    while ($row = $heatmap_result->fetch_assoc()) {
        $heatmap_data[] = [
            'date' => $row['date'],
            'status' => $row['status']
        ];
    }
    $stmt->close();

    // ==================== At-Risk Staff ====================
    $at_risk_staff_sql = "
        SELECT 
            s.staff_id,
            CONCAT(s.first_name, ' ', s.last_name) as name,
            s.department,
            s.designation as position,
            COUNT(CASE WHEN sa.status = 'absent' THEN 1 END) as total_absences,
            MAX(consecutive) as consecutive_absences,
            ROUND(
                (COUNT(CASE WHEN sa.status = 'present' THEN 1 END) * 100.0) / 
                NULLIF(COUNT(sa.attendance_id), 0), 
                1
            ) as attendance_rate,
            (SELECT remarks FROM staff_attendance 
             WHERE staff_id = s.staff_id AND status = 'absent' 
             ORDER BY date DESC LIMIT 1) as last_reason
        FROM staff s
        LEFT JOIN (
            SELECT 
                staff_id,
                date,
                status,
                remarks,
                attendance_id,
                @row := IF(@staff = staff_id AND status = 'absent', @row + 1, 1) as consecutive,
                @staff := staff_id
            FROM staff_attendance
            WHERE date BETWEEN ? AND ?
            ORDER BY staff_id, date
        ) sa ON s.staff_id = sa.staff_id
        WHERE s.Status = 'active' AND $where_clause
        GROUP BY s.staff_id
        HAVING consecutive_absences >= 3
        ORDER BY consecutive_absences DESC, total_absences DESC
        LIMIT 50
    ";

    $stmt = $conn->prepare($at_risk_staff_sql);
    if (!empty($params)) {
        $stmt->bind_param($bind_types, ...$bind_params);
    } else {
        $stmt->bind_param("ss", $date_from, $date_to);
    }
    $stmt->execute();
    $at_risk_result = $stmt->get_result();
    
    $at_risk_staff = [];
    while ($row = $at_risk_result->fetch_assoc()) {
        $at_risk_staff[] = [
            'staff_id' => $row['staff_id'],
            'name' => $row['name'],
            'department' => $row['department'],
            'position' => $row['position'],
            'consecutive_absences' => (int)$row['consecutive_absences'],
            'total_absences' => (int)$row['total_absences'],
            'attendance_rate' => (float)($row['attendance_rate'] ?? 0),
            'last_reason' => $row['last_reason']
        ];
    }
    $stmt->close();

    // Return response
    echo json_encode([
        'success' => true,
        'kpis' => $kpis,
        'status_distribution' => $status_distribution,
        'daily_trends' => $daily_trends,
        'weekly_trends' => $weekly_trends,
        'monthly_comparison' => $monthly_comparison,
        'department_performance' => $department_performance,
        'position_performance' => $position_performance,
        'day_of_week_pattern' => $day_of_week_pattern,
        'heatmap_data' => $heatmap_data,
        'at_risk_staff' => $at_risk_staff
    ]);

} catch (Exception $e) {
    error_log("Staff Analytics API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while fetching analytics data',
        'error' => $e->getMessage() // Remove in production
    ]);
}
?>