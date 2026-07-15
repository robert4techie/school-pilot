<?php
/**
 * API: Get All Students with Attendance Rates
 * File: api/get_all_students.php
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

    // Get all active students with their attendance rates
    $sql = "SELECT 
                s.student_id,
                CONCAT(s.first_name, ' ', s.last_name) as name,
                s.current_class,
                s.stream,
                s.gender,
                s.section,
                COUNT(a.attendance_id) as total_days,
                COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_days,
                CASE 
                    WHEN COUNT(a.attendance_id) > 0 THEN 
                        ROUND((COUNT(CASE WHEN a.status = 'present' THEN 1 END) / COUNT(a.attendance_id)) * 100, 1)
                    ELSE 0 
                END as attendance_rate
            FROM students s
            LEFT JOIN attendance a ON s.student_id = a.student_id
            WHERE s.status = 'active'
            GROUP BY s.student_id, s.first_name, s.last_name, s.current_class, s.stream, s.gender, s.section
            ORDER BY s.current_class, s.stream, s.last_name, s.first_name";
    
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception("Database query failed: " . $conn->error);
    }
    
    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = [
            'student_id' => $row['student_id'],
            'name' => $row['name'],
            'current_class' => $row['current_class'] ?? 'N/A',
            'stream' => $row['stream'] ?? 'N/A',
            'gender' => $row['gender'] ?? 'N/A',
            'section' => $row['section'] ?? 'N/A',
            'total_days' => (int)$row['total_days'],
            'present_days' => (int)$row['present_days'],
            'attendance_rate' => (float)$row['attendance_rate']
        ];
    }
    
    $response['success'] = true;
    $response['students'] = $students;
    $response['total'] = count($students);

} catch (Exception $e) {
    error_log("Get All Students API Error: " . $e->getMessage());
    $response['message'] = "Failed to load students: " . $e->getMessage();
    http_response_code(500);
}

echo json_encode($response);
exit;
?>