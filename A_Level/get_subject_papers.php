<?php
// This file handles AJAX requests to get papers for a specific subject

// Set headers for JSON response
header('Content-Type: application/json');

// Database connection
require_once '../auth.php';
require_once '../conn.php';

// Get subject from GET request
$subject = isset($_GET['subject']) ? $_GET['subject'] : '';

// Validate input
if (empty($subject)) {
    echo json_encode([]);
    exit;
}

// Prepare and execute query
$sql = "SELECT papers FROM subject_papers WHERE subject_name = '" . mysqli_real_escape_string($conn, $subject) . "' AND class = 'Senior Five'";
$result = mysqli_query($conn, $sql);

$response = [];

if ($result && mysqli_num_rows($result) > 0) {
    $row = mysqli_fetch_assoc($result);
    
    // Papers are stored as comma-separated values (e.g., "I,II,III")
    if (isset($row['papers']) && !empty($row['papers'])) {
        $papers = explode(',', $row['papers']);
        
        // Format the papers as Paper I, Paper II, etc.
        foreach ($papers as $paper) {
            $response[] = [
                'id' => trim($paper),
                'name' => 'Paper ' . trim($paper)
            ];
        }
    }
}

// Return JSON response
echo json_encode($response);