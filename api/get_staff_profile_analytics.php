<?php
/**
 * API: Get Individual Staff Profile Analytics
 * File: api/get_staff_profile_analytics.php
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
    $staff_id = filter_input(INPUT_GET, 'staff_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    if (!$staff_id) {
        throw new Exception("Staff ID is required");
    }

    // Verify staff exists
    $verify_sql = "SELECT staff_id FROM staff WHERE staff_id = ? AND Status = 'active'";
    $stmt = $conn->prepare($verify_sql);
    $stmt->bind_param("s", $staff_id);
    $stmt->execute();
    $verify_result = $stmt->get_result();
    
    if ($verify_result->num_rows === 0) {
        throw new Exception("Staff member not found");
    }
    $stmt->close();

    // Date range - last 90 days
    $date_from = date('Y-m-d', strtotime('-90 days'));
    $date_to = date('Y-m-d');

    // ==================== STATISTICS ====================
    $stats_sql = "
        SELECT 
            COUNT(CASE WHEN status = 'present' THEN 1 END) as present_days,
            COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_days,
            COUNT(CASE WHEN status = 'late' THEN 1 END) as late_days,
            COUNT(CASE WHEN status = 'on_leave' THEN 1 END) as on_leave_days,
            COUNT(attendance_id) as total_days,
            ROUND(
                (COUNT(CASE WHEN status = 'present' THEN 1 END) * 100.0) / 
                NULLIF(COUNT(attendance_id), 0), 
                1
            ) as attendance_rate
        FROM staff_attendance
        WHERE staff_id = ?
            AND date BETWEEN ? AND ?
    ";

    $stmt = $conn->prepare($stats_sql);
    $stmt->bind_param("sss", $staff_id, $date_from, $date_to);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // ==================== ALERTS ====================
    $alerts = [];

    // Check for consecutive absences
    $consecutive_sql = "
        SELECT 
            MAX(consecutive) as max_consecutive
        FROM (
            SELECT 
                date,
                status,
                @row := IF(@staff = ? AND status = 'absent', @row + 1, 1) as consecutive,
                @staff := ?
            FROM staff_attendance
            WHERE staff_id = ?
                AND date BETWEEN ? AND ?
            ORDER BY date DESC
        ) t
    ";

    $stmt = $conn->prepare($consecutive_sql);
    $stmt->bind_param("sssss", $staff_id, $staff_id, $staff_id, $date_from, $date_to);
    $stmt->execute();
    $consecutive_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $consecutive_absences = (int)($consecutive_result['max_consecutive'] ?? 0);

    if ($consecutive_absences >= 5) {
        $alerts[] = [
            'severity' => 'critical',
            'icon' => '🚨',
            'title' => 'Critical Alert',
            'message' => "This staff member has {$consecutive_absences} consecutive absences. Immediate attention required."
        ];
    } elseif ($consecutive_absences >= 3) {
        $alerts[] = [
            'severity' => 'warning',
            'icon' => '⚠️',
            'title' => 'Warning',
            'message' => "This staff member has {$consecutive_absences} consecutive absences. Please monitor closely."
        ];
    }

    // Check attendance rate
    $attendance_rate = (float)($stats['attendance_rate'] ?? 0);
    if ($attendance_rate < 75) {
        $alerts[] = [
            'severity' => 'warning',
            'icon' => '📉',
            'title' => 'Low Attendance Rate',
            'message' => "Attendance rate is {$attendance_rate}%, which is below the acceptable threshold of 75%."
        ];
    }

    // ==================== BREAKDOWN FOR PIE CHART ====================
    $breakdown = [
        'present' => (int)($stats['present_days'] ?? 0),
        'absent' => (int)($stats['absent_days'] ?? 0),
        'late' => (int)($stats['late_days'] ?? 0),
        'on_leave' => (int)($stats['on_leave_days'] ?? 0)
    ];

    // ==================== MONTHLY TREND ====================
    $monthly_sql = "
        SELECT 
            DATE_FORMAT(date, '%Y-%m') as month,
            ROUND(
                (COUNT(CASE WHEN status = 'present' THEN 1 END) * 100.0) / 
                NULLIF(COUNT(attendance_id), 0), 
                1
            ) as rate
        FROM staff_attendance
        WHERE staff_id = ?
            AND date BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(date, '%Y-%m')
        ORDER BY month
    ";

    $stmt = $conn->prepare($monthly_sql);
    $stmt->bind_param("sss", $staff_id, $date_from, $date_to);
    $stmt->execute();
    $monthly_result = $stmt->get_result();
    
    $monthly_data = [];
    while ($row = $monthly_result->fetch_assoc()) {
        $monthly_data[] = [
            'month' => $row['month'],
            'rate' => (float)($row['rate'] ?? 0)
        ];
    }
    $stmt->close();

    // ==================== DAILY PATTERN (Last 60 days) ====================
    $pattern_from = date('Y-m-d', strtotime('-60 days'));
    
    $pattern_sql = "
        SELECT date, status
        FROM staff_attendance
        WHERE staff_id = ?
            AND date BETWEEN ? AND ?
        ORDER BY date ASC
    ";

    $stmt = $conn->prepare($pattern_sql);
    $stmt->bind_param("sss", $staff_id, $pattern_from, $date_to);
    $stmt->execute();
    $pattern_result = $stmt->get_result();
    
    $daily_pattern = [];
    while ($row = $pattern_result->fetch_assoc()) {
        $daily_pattern[] = [
            'date' => $row['date'],
            'status' => $row['status']
        ];
    }
    $stmt->close();

    // ==================== ATTENDANCE HISTORY (Last 100 records) ====================
    $history_sql = "
        SELECT 
            date,
            status,
            TIME_FORMAT(created_at, '%h:%i %p') as time,
            remarks,
            recorded_by
        FROM staff_attendance
        WHERE staff_id = ?
        ORDER BY date DESC
        LIMIT 100
    ";

    $stmt = $conn->prepare($history_sql);
    $stmt->bind_param("s", $staff_id);
    $stmt->execute();
    $history_result = $stmt->get_result();
    
    $history = [];
    while ($row = $history_result->fetch_assoc()) {
        $history[] = [
            'date' => $row['date'],
            'status' => $row['status'],
            'time' => $row['time'],
            'remarks' => $row['remarks'],
            'recorded_by' => $row['recorded_by']
        ];
    }
    $stmt->close();

    // ==================== RETURN RESPONSE ====================
    echo json_encode([
        'success' => true,
        'data' => [
            'stats' => [
                'attendance_rate' => (float)($stats['attendance_rate'] ?? 0),
                'present_days' => (int)($stats['present_days'] ?? 0),
                'absent_days' => (int)($stats['absent_days'] ?? 0),
                'late_days' => (int)($stats['late_days'] ?? 0),
                'on_leave_days' => (int)($stats['on_leave_days'] ?? 0),
                'total_days' => (int)($stats['total_days'] ?? 0)
            ],
            'alerts' => $alerts,
            'charts' => [
                'breakdown' => $breakdown,
                'monthly' => $monthly_data,
                'daily_pattern' => $daily_pattern
            ],
            'history' => $history
        ]
    ]);

} catch (Exception $e) {
    error_log("Staff Profile API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>