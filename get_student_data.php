<?php
require_once 'auth.php';
require_once 'conn.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get the posted data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['student_name']) || empty(trim($input['student_name']))) {
    http_response_code(400);
    echo json_encode(['error' => 'Student name is required']);
    exit;
}

$student_name = trim($input['student_name']);

try {
    // Split the student name to get first and last name
    $name_parts = explode(' ', $student_name, 2);
    $first_name = $name_parts[0];
    $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
    
    // Prepare the query to fetch student data
    $query = "SELECT student_id, first_name, last_name, current_class, stream, section 
              FROM students 
              WHERE status = 'active' 
              AND (CONCAT(first_name, ' ', last_name) = ? 
                   OR (first_name = ? AND last_name = ?)
                   OR (first_name LIKE ? AND last_name LIKE ?))
              LIMIT 1";
    
    $stmt = mysqli_prepare($conn, $query);
    
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . mysqli_error($conn));
    }
    
    $first_name_like = $first_name . '%';
    $last_name_like = $last_name . '%';
    
    mysqli_stmt_bind_param($stmt, 'sssss', 
        $student_name, 
        $first_name, 
        $last_name,
        $first_name_like,
        $last_name_like
    );
    
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $student = mysqli_fetch_assoc($result);
        
        // Return student data
        echo json_encode([
            'success' => true,
            'data' => [
                'student_id' => $student['student_id'],
                'full_name' => $student['first_name'] . ' ' . $student['last_name'],
                'class' => $student['current_class'],
                'stream' => $student['stream'],
                'section' => $student['section'] ?? ''
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Student not found or inactive'
        ]);
    }
    
    mysqli_stmt_close($stmt);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}

mysqli_close($conn);
?>