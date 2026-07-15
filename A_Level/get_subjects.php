<?php
// File: get_subjects.php
// This file retrieves A-Level subjects and existing paper selections via AJAX

// Database connection
require_once '../auth.php';
require_once '../conn.php';

// Initialize response
$response = array(
    'success' => false,
    'message' => '',
    'subjects' => array(),
    'existingPapers' => array()
);

try {
    // Get class from request
    $class = isset($_GET['class']) ? $_GET['class'] : 'Senior Five';
    
    // Get all A-level subjects - including both pure A and O,A levels, but excluding pure O level
    $subjectsQuery = "SELECT * FROM subjects WHERE level = 'A' OR level = 'O,A' ORDER BY subj_name";
    $subjectsResult = mysqli_query($conn, $subjectsQuery);
    
    if (!$subjectsResult) {
        throw new Exception("Error fetching subjects: " . mysqli_error($conn));
    }
    
    // Get existing paper selections for the current class
    $existingPapers = array();
    $existingQuery = "SELECT subject_name, papers FROM subject_papers WHERE class = ?";
    $existingStmt = mysqli_prepare($conn, $existingQuery);
    mysqli_stmt_bind_param($existingStmt, "s", $class);
    mysqli_stmt_execute($existingStmt);
    $existingResult = mysqli_stmt_get_result($existingStmt);
    
    while ($row = mysqli_fetch_assoc($existingResult)) {
        $existingPapers[$row['subject_name']] = explode(',', $row['papers']);
    }
    
    // Fetch all subjects
    $subjects = array();
    while ($subject = mysqli_fetch_assoc($subjectsResult)) {
        $subjects[] = $subject;
    }
    
    // Set success response
    $response['success'] = true;
    $response['subjects'] = $subjects;
    $response['existingPapers'] = $existingPapers;
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

// Send JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>