<?php
/**
 * API: Get All Staff Members with Attendance Rates - FIXED VERSION
 * File: api/get_all_staff.php
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
$allowed_roles = ['developer', 'super user', 'school leader'];

if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), array_map('strtolower', $allowed_roles))) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied'
    ]);
    exit();
}

try {
    // Get date range for attendance calculation (last 30 days by default)
    $date_from = date('Y-m-d', strtotime('-30 days'));
    $date_to = date('Y-m-d');

    $sql = "
        SELECT 
            s.staff_id,
            CONCAT(s.first_name, ' ', s.last_name) as name,
            s.department,
            s.designation as position,
            COUNT(sa.attendance_id) as total_records,
            COUNT(CASE WHEN sa.status = 'present' THEN 1 END) as present_count,
            ROUND(
                (COUNT(CASE WHEN sa.status = 'present' THEN 1 END) * 100.0) / 
                NULLIF(COUNT(sa.attendance_id), 0), 
                1
            ) as attendance_rate
        FROM staff s
        LEFT JOIN staff_attendance sa ON s.staff_id = sa.staff_id 
            AND sa.date BETWEEN ? AND ?
        WHERE s.Status = 'active'
        GROUP BY s.staff_id, s.first_name, s.last_name, s.department, s.designation
        ORDER BY s.last_name, s.first_name
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $conn->error);
    }

    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();

    $staff = [];
    while ($row = $result->fetch_assoc()) {
        $staff[] = [
            'staff_id' => $row['staff_id'],
            'name' => $row['name'],
            'department' => $row['department'] ?? 'N/A',
            'position' => $row['position'] ?? 'N/A',
            'attendance_rate' => (float)($row['attendance_rate'] ?? 0),
            'total_records' => (int)$row['total_records'],
            'present_count' => (int)$row['present_count']
        ];
    }

    $stmt->close();

    echo json_encode([
        'success' => true,
        'staff' => $staff,
        'total_count' => count($staff),
        'date_range' => [
            'from' => $date_from,
            'to' => $date_to
        ]
    ]);

} catch (Exception $e) {
    error_log("Get All Staff API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while fetching staff data',
        'error' => $e->getMessage() // Remove in production
    ]);
}
?>