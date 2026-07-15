<?php
require_once '../auth.php';
require_once '../conn.php';

header('Content-Type: application/json');

// Get JSON data
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid data received']);
    exit;
}

$class = $data['class'] ?? '';
$subject = $data['subject'] ?? '';
$assigned_by = $data['assigned_by'] ?? 'System';
$assignments = $data['assignments'] ?? [];

if (empty($class) || empty($subject) || empty($assignments)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters']);
    exit;
}

// Start transaction
mysqli_begin_transaction($conn);

try {
    $assigned_count = 0;
    $unassigned_count = 0;
    
    foreach ($assignments as $assignment) {
        $student_id = $assignment['student_id'];
        $stream = $assignment['stream'];
        $is_assigned = $assignment['assigned'];
        
        if ($is_assigned) {
            // Check if assignment already exists
            $check_sql = "SELECT id FROM student_subjects 
                          WHERE student_id = ? AND subject = ? AND class = ?";
            $check_stmt = mysqli_prepare($conn, $check_sql);
            mysqli_stmt_bind_param($check_stmt, "sss", $student_id, $subject, $class);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);
            
            if (mysqli_num_rows($check_result) == 0) {
                // Insert new assignment
                $insert_sql = "INSERT INTO student_subjects (student_id, subject, class, stream, assigned_by) 
                               VALUES (?, ?, ?, ?, ?)";
                $insert_stmt = mysqli_prepare($conn, $insert_sql);
                mysqli_stmt_bind_param($insert_stmt, "sssss", $student_id, $subject, $class, $stream, $assigned_by);
                
                if (mysqli_stmt_execute($insert_stmt)) {
                    $assigned_count++;
                }
                mysqli_stmt_close($insert_stmt);
            }
            mysqli_stmt_close($check_stmt);
        } else {
            // Remove assignment if it exists
            $delete_sql = "DELETE FROM student_subjects 
                          WHERE student_id = ? AND subject = ? AND class = ?";
            $delete_stmt = mysqli_prepare($conn, $delete_sql);
            mysqli_stmt_bind_param($delete_stmt, "sss", $student_id, $subject, $class);
            
            if (mysqli_stmt_execute($delete_stmt)) {
                if (mysqli_stmt_affected_rows($delete_stmt) > 0) {
                    $unassigned_count++;
                }
            }
            mysqli_stmt_close($delete_stmt);
        }
    }
    
    // Commit transaction
    mysqli_commit($conn);
    
    $message = "Assignments saved successfully!";
    if ($assigned_count > 0 || $unassigned_count > 0) {
        $message .= " (Assigned: $assigned_count, Unassigned: $unassigned_count)";
    }
    
    echo json_encode([
        'status' => 'success',
        'message' => $message,
        'assigned' => $assigned_count,
        'unassigned' => $unassigned_count
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    mysqli_rollback($conn);
    
    error_log("Error saving assignments: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to save assignments: ' . $e->getMessage()
    ]);
}

mysqli_close($conn);
?>