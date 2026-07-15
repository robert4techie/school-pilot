<?php
// get_students.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');


require_once 'conn.php';
require_once 'auth.php';

// Check if required parameters are provided
if (!isset($_POST['class']) || !isset($_POST['stream'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Class and stream parameters are required'
    ]);
    exit();
}

$class = $conn->real_escape_string($_POST['class']);
$stream = $conn->real_escape_string($_POST['stream']);

try {
    // Query to get students from specified class and stream with active status
    $sql = "SELECT student_id, first_name, last_name, current_class, stream, section, date_of_enrolment 
            FROM students 
            WHERE current_class = ? 
            AND stream = ? 
            AND status = 'active' 
            ORDER BY first_name ASC, last_name ASC";
    
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Query preparation failed: ' . $conn->error);
    }
    
    $stmt->bind_param("ss", $class, $stream);
    
    if (!$stmt->execute()) {
        throw new Exception('Query execution failed: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $students = [];
    
    while ($row = $result->fetch_assoc()) {
        $students[] = [
            'student_id' => $row['student_id'],
            'first_name' => htmlspecialchars($row['first_name']),
            'last_name' => htmlspecialchars($row['last_name']),
            'current_class' => htmlspecialchars($row['current_class']),
            'stream' => htmlspecialchars($row['stream']),
            'section' => htmlspecialchars($row['section'] ?? ''),
            'date_of_enrolment' => $row['date_of_enrolment']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'students' => $students,
        'count' => count($students),
        'message' => count($students) > 0 ? 'Students loaded successfully' : 'No students found in this class and stream'
    ]);
    
    $stmt->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving students: ' . $e->getMessage()
    ]);
} finally {
    $conn->close();
}
?>