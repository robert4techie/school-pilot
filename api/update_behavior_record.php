<?php
require_once "../auth.php";
require_once "../conn.php";


header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit();
}

// Get and validate input data
$record_id = $_POST['record_id'] ?? 0;
$student_id = trim($_POST['student_id'] ?? '');
$class = trim($_POST['class'] ?? '');
$stream = trim($_POST['stream'] ?? '');
$type = $_POST['type'] ?? '';
$date_occurred = $_POST['date_occurred'] ?? '';
$description = trim($_POST['description'] ?? '');
$action_taken = trim($_POST['action_taken'] ?? '');
$follow_up = trim($_POST['follow_up'] ?? '');

// Validation
$errors = [];

if (empty($record_id) || !is_numeric($record_id)) {
    $errors[] = 'Invalid record ID';
}

if (empty($student_id) || $student_id === '0') {
    $errors[] = 'Please enter a valid Student ID (format: XYZ-2025-STD-XXXX)';
}

if (empty($class)) {
    $errors[] = 'Class is required';
}

if (empty($stream)) {
    $errors[] = 'Stream is required';
}

if (!in_array($type, ['Positive', 'Negative'])) {
    $errors[] = 'Invalid behavior type';
}

if (empty($date_occurred)) {
    $errors[] = 'Date occurred is required';
} elseif (!DateTime::createFromFormat('Y-m-d', $date_occurred)) {
    $errors[] = 'Invalid date format';
}

if (empty($description)) {
    $errors[] = 'Description is required';
}

if (!empty($errors)) {
    echo json_encode(['status' => 'error', 'message' => implode(', ', $errors)]);
    exit();
}

try {
    // Start transaction
    mysqli_begin_transaction($conn);
    
    // Check if record exists and belongs to current user (if needed)
    $check_stmt = $conn->prepare("SELECT id FROM student_behaviors WHERE id = ?");
    $check_stmt->bind_param("i", $record_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        throw new Exception('Record not found');
    }
    
    // Update the record
    $update_stmt = $conn->prepare("
        UPDATE student_behaviors 
        SET student_id = ?, 
            class = ?, 
            stream = ?, 
            type = ?, 
            date_occurred = ?, 
            description = ?, 
            action_taken = ?, 
            follow_up = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    
    $update_stmt->bind_param(
        "ssssssssi", 
        $student_id, 
        $class, 
        $stream, 
        $type, 
        $date_occurred, 
        $description, 
        $action_taken, 
        $follow_up, 
        $record_id
    );
    
    if (!$update_stmt->execute()) {
        throw new Exception('Failed to update record: ' . $update_stmt->error);
    }
    
    if ($update_stmt->affected_rows === 0) {
        throw new Exception('No changes were made to the record');
    }
    
    // Commit transaction
    mysqli_commit($conn);
    
    echo json_encode([
        'status' => 'success', 
        'message' => 'Record updated successfully',
        'record_id' => $record_id
    ]);
    
} catch (Exception $e) {
    // Rollback transaction
    mysqli_rollback($conn);
    
    echo json_encode([
        'status' => 'error', 
        'message' => $e->getMessage()
    ]);
    
} finally {
    // Close statements
    if (isset($check_stmt)) $check_stmt->close();
    if (isset($update_stmt)) $update_stmt->close();
    $conn->close();
}
?>