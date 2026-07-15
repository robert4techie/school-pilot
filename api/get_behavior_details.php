<?php
require_once "../auth.php";
require_once "../conn.php";

header('Content-Type: application/json');

$id = $_GET['id'] ?? 0;

if ($id === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid record ID.']);
    exit();
}

try {
    // Simple query without reporter name complexity
    $stmt = $conn->prepare("SELECT 
                           b.id,
                           b.student_id,
                           b.class,
                           b.stream,
                           b.type,
                           b.date_occurred,
                           b.description,
                           b.action_taken,
                           b.follow_up,
                           b.created_at,
                           b.created_by,
                           b.reporter,
                           COALESCE(CONCAT(s.first_name, ' ', s.last_name), 'Student Not Found') AS student_name
                           FROM student_behaviors b
                           LEFT JOIN students s ON b.student_id = s.student_id
                           WHERE b.id = ?");
    
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // Debug: Log the raw data before any modifications
        error_log("Raw data from database: " . print_r($row, true));
        
        // Check if student_id is actually empty or zero
        if (is_null($row['student_id']) || $row['student_id'] === '' || $row['student_id'] === '0') {
            error_log("Student ID is empty/null/zero: '" . $row['student_id'] . "'");
            $row['student_id'] = 'Unknown';
        }
        
        // If student_name is "Student Not Found", it means the JOIN didn't work
        if ($row['student_name'] === 'Student Not Found') {
            error_log("Student not found in students table for student_id: '" . $row['student_id'] . "'");
        }
        
        echo json_encode(['status' => 'success', 'data' => $row]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Record not found']);
    }
    
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
} finally {
    if (isset($stmt)) $stmt->close();
    $conn->close();
}
?>