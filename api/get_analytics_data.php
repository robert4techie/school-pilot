<?php
/**
 * Analytics Data API - Fixed Parameter Binding
 * File: api/get_analytics_data.php
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/api_errors.log');
error_reporting(E_ALL);

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../auth.php";
require_once '../conn.php';

$response = ['success' => false, 'message' => ''];

try {
    // Check authentication
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        throw new Exception('Unauthorized');
    }

    // Get and sanitize filter parameters
    $date_from = filter_input(INPUT_GET, 'date_from', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: date('Y-m-01');
    $date_to = filter_input(INPUT_GET, 'date_to', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: date('Y-m-t');
    $selected_class = filter_input(INPUT_GET, 'class', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '';
    $selected_stream = filter_input(INPUT_GET, 'stream', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '';
    $selected_gender = filter_input(INPUT_GET, 'gender', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '';
    $selected_section = filter_input(INPUT_GET, 'section', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '';

    // Log filters for debugging
    error_log("Analytics filters - Class: $selected_class, Stream: $selected_stream, Gender: $selected_gender, Section: $selected_section");

    // Helper function to build parameters
    function buildParams($date_from, $date_to, $class = '', $stream = '', $gender = '', $section = '') {
        $params = [$date_from, $date_to];
        $types = "ss";
        
        if (!empty($class)) { 
            $params[] = $class; 
            $types .= "s"; 
        }
        if (!empty($stream)) { 
            $params[] = $stream; 
            $types .= "s"; 
        }
        if (!empty($gender)) { 
            $params[] = $gender; 
            $types .= "s"; 
        }
        if (!empty($section)) { 
            $params[] = $section; 
            $types .= "s"; 
        }
        
        return ['params' => $params, 'types' => $types];
    }

    // Build filter clauses
    $class_filter = !empty($selected_class) ? " AND s.current_class = ?" : "";
    $stream_filter = !empty($selected_stream) ? " AND s.stream = ?" : "";
    $gender_filter = !empty($selected_gender) ? " AND s.gender = ?" : "";
    $section_filter = !empty($selected_section) ? " AND s.section = ?" : "";

    // ====================
    // KPIs
    // ====================
    $kpi_sql = "SELECT 
                    COUNT(DISTINCT s.student_id) as total_students,
                    COUNT(a.attendance_id) as total_records,
                    COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present,
                    COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent,
                    COUNT(CASE WHEN a.status = 'late' THEN 1 END) as late,
                    COUNT(CASE WHEN a.status = 'sick' THEN 1 END) as sick
                FROM students s
                LEFT JOIN attendance a ON s.student_id = a.student_id 
                    AND a.date BETWEEN ? AND ?
                WHERE s.status = 'active'
                $class_filter
                $stream_filter
                $gender_filter
                $section_filter";

    $stmt = $conn->prepare($kpi_sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $bind = buildParams($date_from, $date_to, $selected_class, $selected_stream, $selected_gender, $selected_section);
    $stmt->bind_param($bind['types'], ...$bind['params']);
    $stmt->execute();
    $kpi_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $attendance_rate = $kpi_result['total_records'] > 0 ? 
        round(($kpi_result['present'] / $kpi_result['total_records']) * 100, 1) : 0;

    // Calculate previous period for comparison
    $date_diff = (strtotime($date_to) - strtotime($date_from)) / 86400;
    $prev_date_from = date('Y-m-d', strtotime($date_from) - ($date_diff * 86400));
    $prev_date_to = date('Y-m-d', strtotime($date_from) - 86400);

    $prev_sql = "SELECT 
                    COUNT(a.attendance_id) as total_records,
                    COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present
                FROM students s
                LEFT JOIN attendance a ON s.student_id = a.student_id 
                    AND a.date BETWEEN ? AND ?
                WHERE s.status = 'active'
                $class_filter
                $stream_filter
                $gender_filter
                $section_filter";

    $stmt = $conn->prepare($prev_sql);
    $bind = buildParams($prev_date_from, $prev_date_to, $selected_class, $selected_stream, $selected_gender, $selected_section);
    $stmt->bind_param($bind['types'], ...$bind['params']);
    $stmt->execute();
    $prev_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $prev_rate = $prev_result['total_records'] > 0 ? 
        round(($prev_result['present'] / $prev_result['total_records']) * 100, 1) : 0;
    $rate_change = round($attendance_rate - $prev_rate, 1);

    $kpis = [
        'total_students' => (int)$kpi_result['total_students'],
        'attendance_rate' => (float)$attendance_rate,
        'present' => (int)$kpi_result['present'],
        'absent' => (int)$kpi_result['absent'],
        'late' => (int)$kpi_result['late'],
        'sick' => (int)$kpi_result['sick'],
        'rate_change' => (float)$rate_change,
        'at_risk' => 0
    ];

    // ====================
    // STATUS DISTRIBUTION
    // ====================
    $status_distribution = [
        'present' => (int)$kpi_result['present'],
        'absent' => (int)$kpi_result['absent'],
        'late' => (int)$kpi_result['late'],
        'sick' => (int)$kpi_result['sick']
    ];

    // ====================
    // DAILY TRENDS
    // ====================
    $daily_sql = "SELECT 
                    a.date,
                    COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present,
                    COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent,
                    COUNT(CASE WHEN a.status = 'late' THEN 1 END) as late
                FROM attendance a
                INNER JOIN students s ON a.student_id = s.student_id
                WHERE a.date BETWEEN ? AND ?
                AND s.status = 'active'
                $class_filter
                $stream_filter
                $gender_filter
                $section_filter
                GROUP BY a.date
                ORDER BY a.date";

    $stmt = $conn->prepare($daily_sql);
    $bind = buildParams($date_from, $date_to, $selected_class, $selected_stream, $selected_gender, $selected_section);
    $stmt->bind_param($bind['types'], ...$bind['params']);
    $stmt->execute();
    $daily_result = $stmt->get_result();
    
    $daily_trends = [];
    while ($row = $daily_result->fetch_assoc()) {
        $daily_trends[] = [
            'date' => $row['date'],
            'present' => (int)$row['present'],
            'absent' => (int)$row['absent'],
            'late' => (int)$row['late']
        ];
    }
    $stmt->close();

    // ====================
    // WEEKLY TRENDS
    // ====================
    $weekly_sql = "SELECT 
                    WEEK(a.date) as week,
                    COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present,
                    COUNT(a.attendance_id) as total
                FROM attendance a
                INNER JOIN students s ON a.student_id = s.student_id
                WHERE a.date BETWEEN ? AND ?
                AND s.status = 'active'
                $class_filter
                $stream_filter
                $gender_filter
                $section_filter
                GROUP BY week
                ORDER BY week";

    $stmt = $conn->prepare($weekly_sql);
    $bind = buildParams($date_from, $date_to, $selected_class, $selected_stream, $selected_gender, $selected_section);
    $stmt->bind_param($bind['types'], ...$bind['params']);
    $stmt->execute();
    $weekly_result = $stmt->get_result();
    
    $weekly_trends = [];
    while ($row = $weekly_result->fetch_assoc()) {
        $rate = $row['total'] > 0 ? round(($row['present'] / $row['total']) * 100, 1) : 0;
        $weekly_trends[] = [
            'week' => (int)$row['week'],
            'rate' => (float)$rate
        ];
    }
    $stmt->close();

    // ====================
    // MONTHLY COMPARISON
    // ====================
    $monthly_sql = "SELECT 
                        DATE_FORMAT(a.date, '%Y-%m') as month,
                        COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present,
                        COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent
                    FROM attendance a
                    INNER JOIN students s ON a.student_id = s.student_id
                    WHERE a.date BETWEEN DATE_SUB(?, INTERVAL 6 MONTH) AND ?
                    AND s.status = 'active'
                    $class_filter
                    $stream_filter
                    $gender_filter
                    $section_filter
                    GROUP BY month
                    ORDER BY month";

    $stmt = $conn->prepare($monthly_sql);
    $bind = buildParams($date_from, $date_to, $selected_class, $selected_stream, $selected_gender, $selected_section);
    $stmt->bind_param($bind['types'], ...$bind['params']);
    $stmt->execute();
    $monthly_result = $stmt->get_result();
    
    $monthly_comparison = [];
    while ($row = $monthly_result->fetch_assoc()) {
        $monthly_comparison[] = [
            'month' => $row['month'],
            'present' => (int)$row['present'],
            'absent' => (int)$row['absent']
        ];
    }
    $stmt->close();

    // ====================
    // GENDER COMPARISON
    // ====================
    $gender_sql = "SELECT 
                    s.gender,
                    COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present,
                    COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent,
                    COUNT(CASE WHEN a.status = 'late' THEN 1 END) as late
                FROM students s
                LEFT JOIN attendance a ON s.student_id = a.student_id 
                    AND a.date BETWEEN ? AND ?
                WHERE s.status = 'active'
                $class_filter
                $stream_filter
                $section_filter
                GROUP BY s.gender";

    $stmt = $conn->prepare($gender_sql);
    // Note: No gender filter here since we're grouping BY gender
    $bind = buildParams($date_from, $date_to, $selected_class, $selected_stream, '', $selected_section);
    $stmt->bind_param($bind['types'], ...$bind['params']);
    $stmt->execute();
    $gender_result = $stmt->get_result();
    
    $gender_comparison = [
        'male' => ['present' => 0, 'absent' => 0, 'late' => 0],
        'female' => ['present' => 0, 'absent' => 0, 'late' => 0]
    ];
    
    while ($row = $gender_result->fetch_assoc()) {
        $gender_key = strtolower($row['gender']);
        if (isset($gender_comparison[$gender_key])) {
            $gender_comparison[$gender_key] = [
                'present' => (int)$row['present'],
                'absent' => (int)$row['absent'],
                'late' => (int)$row['late']
            ];
        }
    }
    $stmt->close();

    // ====================
    // SECTION COMPARISON (Day/Boarding)
    // ====================
    $section_sql = "SELECT 
                        s.section,
                        COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present,
                        COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent
                    FROM students s
                    LEFT JOIN attendance a ON s.student_id = a.student_id 
                        AND a.date BETWEEN ? AND ?
                    WHERE s.status = 'active'
                    $class_filter
                    $stream_filter
                    $gender_filter
                    GROUP BY s.section";

    $stmt = $conn->prepare($section_sql);
    // Note: No section filter here since we're grouping BY section
    $bind = buildParams($date_from, $date_to, $selected_class, $selected_stream, $selected_gender, '');
    $stmt->bind_param($bind['types'], ...$bind['params']);
    $stmt->execute();
    $section_result = $stmt->get_result();
    
    $section_comparison = [
        'day' => ['present' => 0, 'absent' => 0],
        'boarding' => ['present' => 0, 'absent' => 0]
    ];
    
    while ($row = $section_result->fetch_assoc()) {
        $section_key = strtolower($row['section']);
        if (isset($section_comparison[$section_key])) {
            $section_comparison[$section_key] = [
                'present' => (int)$row['present'],
                'absent' => (int)$row['absent']
            ];
        }
    }
    $stmt->close();

    // ====================
    // CLASS PERFORMANCE
    // ====================
    $class_sql = "SELECT 
                    s.current_class as class_name,
                    COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present,
                    COUNT(a.attendance_id) as total
                FROM students s
                LEFT JOIN attendance a ON s.student_id = a.student_id 
                    AND a.date BETWEEN ? AND ?
                WHERE s.status = 'active'
                $stream_filter
                $gender_filter
                $section_filter
                GROUP BY s.current_class
                ORDER BY s.current_class";

    $stmt = $conn->prepare($class_sql);
    // Note: No class filter here since we're grouping BY class
    $bind = buildParams($date_from, $date_to, '', $selected_stream, $selected_gender, $selected_section);
    $stmt->bind_param($bind['types'], ...$bind['params']);
    $stmt->execute();
    $class_result = $stmt->get_result();
    
    $class_performance = [];
    while ($row = $class_result->fetch_assoc()) {
        $rate = $row['total'] > 0 ? round(($row['present'] / $row['total']) * 100, 1) : 0;
        $class_performance[] = [
            'class_name' => $row['class_name'],
            'rate' => (float)$rate
        ];
    }
    $stmt->close();

    // ====================
    // DAY OF WEEK PATTERN
    // ====================
    $dow_sql = "SELECT 
                    DAYOFWEEK(a.date) as day_num,
                    COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present,
                    COUNT(a.attendance_id) as total
                FROM attendance a
                INNER JOIN students s ON a.student_id = s.student_id
                WHERE a.date BETWEEN ? AND ?
                AND s.status = 'active'
                $class_filter
                $stream_filter
                $gender_filter
                $section_filter
                GROUP BY day_num
                ORDER BY day_num";

    $stmt = $conn->prepare($dow_sql);
    $bind = buildParams($date_from, $date_to, $selected_class, $selected_stream, $selected_gender, $selected_section);
    $stmt->bind_param($bind['types'], ...$bind['params']);
    $stmt->execute();
    $dow_result = $stmt->get_result();
    
    $day_of_week_pattern = [0, 0, 0, 0, 0, 0, 0];
    while ($row = $dow_result->fetch_assoc()) {
        $rate = $row['total'] > 0 ? round(($row['present'] / $row['total']) * 100, 1) : 0;
        $day_index = (int)$row['day_num'] - 1;
        if ($day_index >= 0 && $day_index < 7) {
            $day_of_week_pattern[$day_index] = (float)$rate;
        }
    }
    $stmt->close();

    // ====================
    // AT-RISK STUDENTS
    // ====================
    $at_risk_sql = "SELECT 
                        s.student_id,
                        CONCAT(s.first_name, ' ', s.last_name) as name,
                        s.current_class as class,
                        COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as total_absences,
                        COUNT(a.attendance_id) as total_days,
                        ROUND((COUNT(CASE WHEN a.status = 'present' THEN 1 END) / NULLIF(COUNT(a.attendance_id), 0)) * 100, 1) as attendance_rate
                    FROM students s
                    INNER JOIN attendance a ON s.student_id = a.student_id
                    WHERE s.status = 'active' 
                        AND a.date BETWEEN ? AND ?
                    $class_filter
                    $stream_filter
                    $gender_filter
                    $section_filter
                    GROUP BY s.student_id, s.first_name, s.last_name, s.current_class
                    HAVING attendance_rate < 75 OR total_absences >= 3
                    ORDER BY total_absences DESC, attendance_rate ASC
                    LIMIT 50";

    $stmt = $conn->prepare($at_risk_sql);
    $bind = buildParams($date_from, $date_to, $selected_class, $selected_stream, $selected_gender, $selected_section);
    $stmt->bind_param($bind['types'], ...$bind['params']);
    $stmt->execute();
    $at_risk_result = $stmt->get_result();
    
    $at_risk_students = [];
    while ($row = $at_risk_result->fetch_assoc()) {
        // Calculate consecutive absences
        $consec_sql = "SELECT date, status 
                       FROM attendance 
                       WHERE student_id = ? 
                       ORDER BY date DESC 
                       LIMIT 10";
        $consec_stmt = $conn->prepare($consec_sql);
        $consec_stmt->bind_param("s", $row['student_id']);
        $consec_stmt->execute();
        $consec_result = $consec_stmt->get_result();
        
        $consecutive = 0;
        while ($consec_row = $consec_result->fetch_assoc()) {
            if ($consec_row['status'] === 'absent') {
                $consecutive++;
            } else {
                break;
            }
        }
        $consec_stmt->close();
        
        $at_risk_students[] = [
            'student_id' => $row['student_id'],
            'name' => $row['name'],
            'class' => $row['class'],
            'consecutive_absences' => $consecutive,
            'total_absences' => (int)$row['total_absences'],
            'attendance_rate' => (float)($row['attendance_rate'] ?? 0),
            'last_reason' => null
        ];
    }
    $stmt->close();

    $kpis['at_risk'] = count($at_risk_students);

    // ====================
    // HEATMAP DATA
    // ====================
    $heatmap_sql = "SELECT 
                        a.date,
                        a.status,
                        COUNT(*) as count
                    FROM attendance a
                    INNER JOIN students s ON a.student_id = s.student_id
                    WHERE a.date BETWEEN ? AND ?
                    AND s.status = 'active'
                    $class_filter
                    $stream_filter
                    $gender_filter
                    $section_filter
                    GROUP BY a.date, a.status
                    ORDER BY a.date";

    $stmt = $conn->prepare($heatmap_sql);
    $bind = buildParams($date_from, $date_to, $selected_class, $selected_stream, $selected_gender, $selected_section);
    $stmt->bind_param($bind['types'], ...$bind['params']);
    $stmt->execute();
    $heatmap_result = $stmt->get_result();
    
    $heatmap_data = [];
    while ($row = $heatmap_result->fetch_assoc()) {
        $heatmap_data[] = [
            'date' => $row['date'],
            'status' => $row['status'],
            'count' => (int)$row['count']
        ];
    }
    $stmt->close();

    // ====================
    // BUILD RESPONSE
    // ====================
    $response = [
        'success' => true,
        'kpis' => $kpis,
        'status_distribution' => $status_distribution,
        'daily_trends' => $daily_trends,
        'weekly_trends' => $weekly_trends,
        'monthly_comparison' => $monthly_comparison,
        'gender_comparison' => $gender_comparison,
        'section_comparison' => $section_comparison,
        'class_performance' => $class_performance,
        'day_of_week_pattern' => $day_of_week_pattern,
        'at_risk_students' => $at_risk_students,
        'heatmap_data' => $heatmap_data
    ];

} catch (Exception $e) {
    error_log("Analytics API Error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    $response['message'] = "Failed to load analytics data: " . $e->getMessage();
    http_response_code(500);
}

echo json_encode($response);
exit;