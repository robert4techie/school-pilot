<?php
require_once "../auth.php"; 
require_once '../conn.php';


header('Content-Type: application/json');

// Check if the form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit();
}

try {
    // Check for logged-in user first
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("User not logged in.");
    }
    $created_by = $_SESSION['user_id'];

    // Extract reporter title from brackets, e.g., "John Doe (Teacher)" -> "Teacher"
    $reporter_title = 'Other'; // Default value
    if (preg_match('/\((\w+)\)/', $_POST['reporter_name'], $matches)) {
        $reporter_title = $matches[1];
    }

    // Insert into database
    $sql = "INSERT INTO student_behaviors 
            (student_id, class, stream, type, description, date_occurred, 
             reporter, reporter_name, action_taken, follow_up, created_by)
            VALUES 
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    // IMPORTANT: The type for student_id is now 's' for string.
    // Removed unnecessary mysqli_real_escape_string calls, as bind_param handles security.
    $stmt->bind_param(
        'ssssssssssi',
        $_POST['student_id'],
        $_POST['class'],
        $_POST['stream'],
        $_POST['type'],
        $_POST['description'],
        $_POST['date_occurred'],
        $reporter_title,
        $_POST['reporter_name'],
        $_POST['action_taken'],
        $_POST['follow_up'],
        $created_by
    );

    if ($stmt->execute()) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Behavior record saved successfully!'
        ]);
    } else {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    $stmt->close();
} catch (Exception $e) {
    // Return a generic error message and log the specific error
    error_log($e->getMessage()); // Logs the actual error to the server's error log
    echo json_encode([
        'status' => 'error',
        'message' => 'An error occurred while saving the record.'
    ]);
}

$conn->close();
