<?php
/**
 * Get Subjects
 * 
 * This script retrieves subjects from the database with pagination and search.
 */

// Prevent any output before headers
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Set header to JSON - must be before any output
header('Content-Type: application/json');

// Include database connection
require_once 'conn.php';

// Initialize response array
$response = array(
    'success' => false,
    'message' => 'An error occurred while retrieving subjects.',
    'subjects' => array(),
    'totalItems' => 0
);

try {
    // Get pagination parameters
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
    $search = isset($_GET['search']) ? trim(mysqli_real_escape_string($conn, $_GET['search'])) : '';
    
    // Ensure valid pagination values
    if ($page < 1) $page = 1;
    if ($limit < 1) $limit = 10;
    if ($limit > 100) $limit = 100; // Set a reasonable upper limit
    
    // Calculate offset
    $offset = ($page - 1) * $limit;
    
    // Prepare search condition
    $searchCondition = '';
    if (!empty($search)) {
        $searchCondition = " WHERE subj_id LIKE '%$search%' OR 
                                  subj_abbr LIKE '%$search%' OR 
                                  subj_name LIKE '%$search%' OR 
                                  code LIKE '%$search%' OR 
                                  codea LIKE '%$search%' OR 
                                  level LIKE '%$search%'";
    }
    
    // Get total count query
    $countQuery = "SELECT COUNT(*) as total FROM subjects$searchCondition";
    $countResult = mysqli_query($conn, $countQuery);
    
    if (!$countResult) {
        throw new Exception("Error counting subjects: " . mysqli_error($conn));
    }
    
    $totalRow = mysqli_fetch_assoc($countResult);
    $totalItems = $totalRow['total'];
    
    // Get subjects query with pagination
    $query = "SELECT * FROM subjects$searchCondition ORDER BY subj_id DESC LIMIT $offset, $limit";
    $result = mysqli_query($conn, $query);
    
    if (!$result) {
        throw new Exception("Error retrieving subjects: " . mysqli_error($conn));
    }
    
    // Fetch subjects
    $subjects = array();
    while ($row = mysqli_fetch_assoc($result)) {
        $subjects[] = array(
            'subj_id' => $row['subj_id'],
            'subj_abbr' => $row['subj_abbr'],
            'subj_name' => $row['subj_name'],
            'level' => $row['level'],
            'code' => $row['code'],
            'codea' => $row['codea'],
            'compulsory' => $row['compulsory']
        );
    }
    
    // Set success response
    $response['success'] = true;
    $response['message'] = 'Subjects retrieved successfully.';
    $response['subjects'] = $subjects;
    $response['totalItems'] = $totalItems;
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

// Close database connection
if (isset($conn) && $conn) {
    mysqli_close($conn);
}

// Return JSON response
echo json_encode($response);
exit;
?>