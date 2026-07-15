<?php
/**
 * Individual Student Analytics API
 * File: api/get_student_analytics.php
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
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        throw new Exception('Unauthorized');
    }

    $student_id = filter_input(INPUT_GET, 'student_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    
    if (!$student_id) {
        throw new Exception('Student ID required');
    }

    // Verify student exists
    $verify_sql = "SELECT student_id FROM students WHERE student_id = ? AND status = 'active'";
    $stmt = $conn->prepare($verify_sql);
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        $stmt->close();
        throw new Exception('Student not found');
    }
    $stmt->close();

    // Calculate stats - FIXED to use attendance_id instead of id
    $stats_sql = "SELECT 
                    COUNT(attendance_id) as total_days,
                    COUNT(CASE WHEN status = 'present' THEN 1 END) as present_days,
                    COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_days,
                    COUNT(CASE WHEN status = 'late' THEN 1 END) as late_days,
                    COUNT(CASE WHEN status = 'sick' THEN 1 END) as sick_days
                  FROM attendance
                  WHERE student_id = ?";
    
    $stmt = $conn->prepare($stats_sql);
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $attendance_rate = $stats['total_days'] > 0 ? 
        round(($stats['present_days'] / $stats['total_days']) * 100, 1) : 0;

    // Check for consecutive absences - FIXED query
    $consecutive_sql = "SELECT COUNT(*) as consecutive_count
                        FROM (
                            SELECT date,
                                   status,
                                   @counter := IF(status = 'absent', @counter + 1, 0) as streak,
                                   @max_streak := GREATEST(@max_streak, @counter) as max_consecutive
                            FROM attendance
                            CROSS JOIN (SELECT @counter := 0, @max_streak := 0) vars
                            WHERE student_id = ?
                            ORDER BY date DESC
                        ) subquery
                        LIMIT 1";
    
    $stmt = $conn->prepare($consecutive_sql);
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $consecutive_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Get the actual max consecutive - simpler approach
    $recent_absences_sql = "SELECT date, status 
                            FROM attendance 
                            WHERE student_id = ? 
                            ORDER BY date DESC 
                            LIMIT 30";
    
    $stmt = $conn->prepare($recent_absences_sql);
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $recent_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Calculate consecutive absences manually
    $max_consecutive = 0;
    $current_streak = 0;
    foreach ($recent_records as $record) {
        if ($record['status'] === 'absent') {
            $current_streak++;
            $max_consecutive = max($max_consecutive, $current_streak);
        } else {
            $current_streak = 0;
        }
    }

    // Generate alerts
    $alerts = [];
    if ($max_consecutive >= 5) {
        $alerts[] = [
            'severity' => 'critical',
            'icon' => '',
            'title' => 'Critical Alert',
            'message' => "This student has {$max_consecutive} consecutive absences. Immediate intervention required."
        ];
    } elseif ($max_consecutive >= 3) {
        $alerts[] = [
            'severity' => 'warning',
            'icon' => '⚠️',
            'title' => 'Warning',
            'message' => "This student has {$max_consecutive} consecutive absences. Monitor closely."
        ];
    }

    if ($attendance_rate < 75) {
        $alerts[] = [
            'severity' => 'warning',
            'icon' => '',
            'title' => 'Low Attendance Rate',
            'message' => "Overall attendance rate is {$attendance_rate}%, below the 75% threshold."
        ];
    }

    // Monthly trend
    $monthly_sql = "SELECT 
                        DATE_FORMAT(date, '%Y-%m') as month,
                        COUNT(CASE WHEN status = 'present' THEN 1 END) as present,
                        COUNT(attendance_id) as total
                    FROM attendance
                    WHERE student_id = ?
                    GROUP BY month
                    ORDER BY month DESC
                    LIMIT 12";
    
    $stmt = $conn->prepare($monthly_sql);
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $monthly_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $monthly_trend = array_map(function($row) {
        $rate = $row['total'] > 0 ? round(($row['present'] / $row['total']) * 100, 1) : 0;
        return [
            'month' => $row['month'],
            'rate' => (float)$rate
        ];
    }, $monthly_data);

    // Daily pattern (last 60 days)
    $pattern_sql = "SELECT date, status
                    FROM attendance
                    WHERE student_id = ?
                    ORDER BY date DESC
                    LIMIT 60";
    
    $stmt = $conn->prepare($pattern_sql);
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $pattern_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $daily_pattern = array_reverse($pattern_data);

    // Full history
    $history_sql = "SELECT 
                        date,
                        status,
                        TIME_FORMAT(created_at, '%h:%i %p') as time,
                        remarks
                    FROM attendance
                    WHERE student_id = ?
                    ORDER BY date DESC
                    LIMIT 100";
    
    $stmt = $conn->prepare($history_sql);
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $response['success'] = true;
    $response['data'] = [
        'stats' => [
            'total_days' => (int)$stats['total_days'],
            'present_days' => (int)$stats['present_days'],
            'absent_days' => (int)$stats['absent_days'],
            'late_days' => (int)$stats['late_days'],
            'sick_days' => (int)$stats['sick_days'],
            'attendance_rate' => (float)$attendance_rate
        ],
        'alerts' => $alerts,
        'charts' => [
            'breakdown' => [
                'present' => (int)$stats['present_days'],
                'absent' => (int)$stats['absent_days'],
                'late' => (int)$stats['late_days'],
                'sick' => (int)$stats['sick_days']
            ],
            'monthly' => $monthly_trend,
            'daily_pattern' => $daily_pattern
        ],
        'history' => $history
    ];

} catch (Exception $e) {
    error_log("Student Analytics API Error: " . $e->getMessage());
    $response['message'] = "Failed to load student data: " . $e->getMessage();
    http_response_code(500);
}

echo json_encode($response);
exit;
?>