<?php

// Database connection
require_once '../conn.php';

// Initialize response
$response = array(
    'success' => false,
    'message' => ''
);

// Check if request is valid
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get submitted data
        $subjectId = isset($_POST['subject_id']) ? $_POST['subject_id'] : '';
        $subjectName = isset($_POST['subject_name']) ? $_POST['subject_name'] : '';
        $paper = isset($_POST['paper']) ? $_POST['paper'] : '';
        $class = isset($_POST['class']) ? $_POST['class'] : '';
        $action = isset($_POST['action']) ? $_POST['action'] : '';
        
        // Validate inputs
        if (empty($subjectName) || empty($paper) || empty($class) || empty($action)) {
            throw new Exception("Missing required parameters");
        }
        
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        // Check if entry already exists for this subject and class
        $checkQuery = "SELECT id, papers FROM subject_papers WHERE subject_name = ? AND class = ?";
        $checkStmt = mysqli_prepare($conn, $checkQuery);
        mysqli_stmt_bind_param($checkStmt, "ss", $subjectName, $class);
        mysqli_stmt_execute($checkStmt);
        $checkResult = mysqli_stmt_get_result($checkStmt);
        
        if (mysqli_num_rows($checkResult) > 0) {
            // Update existing entry
            $row = mysqli_fetch_assoc($checkResult);
            $existingPapers = explode(',', $row['papers']);
            
            if ($action === 'add' && !in_array($paper, $existingPapers)) {
                // Add paper
                $existingPapers[] = $paper;
            } else if ($action === 'remove' && in_array($paper, $existingPapers)) {
                // Remove paper
                $existingPapers = array_diff($existingPapers, array($paper));
            }
            
            // If no papers left, delete the record
            if (empty($existingPapers)) {
                $deleteQuery = "DELETE FROM subject_papers WHERE id = ?";
                $deleteStmt = mysqli_prepare($conn, $deleteQuery);
                mysqli_stmt_bind_param($deleteStmt, "i", $row['id']);
                mysqli_stmt_execute($deleteStmt);
            } else {
                // Sort papers in correct order (I, II, III, IV)
                usort($existingPapers, function($a, $b) {
                    $order = array('I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4);
                    return $order[$a] - $order[$b];
                });
                
                // Update with new papers list
                $newPapersString = implode(',', $existingPapers);
                $updateQuery = "UPDATE subject_papers SET papers = ?, date_updated = NOW() WHERE id = ?";
                $updateStmt = mysqli_prepare($conn, $updateQuery);
                mysqli_stmt_bind_param($updateStmt, "si", $newPapersString, $row['id']);
                mysqli_stmt_execute($updateStmt);
            }
        } else if ($action === 'add') {
            // Insert new entry
            $insertQuery = "INSERT INTO subject_papers (subject_name, class, papers, date_added, date_updated) 
                           VALUES (?, ?, ?, NOW(), NOW())";
            $insertStmt = mysqli_prepare($conn, $insertQuery);
            mysqli_stmt_bind_param($insertStmt, "sss", $subjectName, $class, $paper);
            mysqli_stmt_execute($insertStmt);
        }
        
        // Commit transaction
        mysqli_commit($conn);
        
        // Set success response
        $response['success'] = true;
        if ($action === 'add') {
            $response['message'] = "Paper {$paper} added to {$subjectName}";
        } else {
            $response['message'] = "Paper {$paper} removed from {$subjectName}";
        }
        
    } catch (Exception $e) {
        // Roll back transaction on error
        mysqli_rollback($conn);
        $response['message'] = 'Error: ' . $e->getMessage();
    }
} else {
    $response['message'] = 'Invalid request method';
}

// Send JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>