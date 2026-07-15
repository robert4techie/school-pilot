<?php
header('Content-Type: application/json');
require_once 'conn.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$response = ['success' => false, 'message' => ''];

// Modified query to match your exact column name
$query = "SELECT DISTINCT current_class FROM students WHERE current_class IS NOT NULL AND current_class != '' ORDER BY current_class";
$result = $conn->query($query);

if ($result === false) {
    $response['message'] = 'Query failed: ' . $conn->error;
    error_log($response['message']);
} elseif ($result->num_rows === 0) {
    $response['message'] = 'No classes found in the database';
} else {
    $classes = [];
    while ($row = $result->fetch_assoc()) {
        $classes[] = $row['current_class'];
    }
    $response = [
        'success' => true,
        'classes' => $classes,
        'debug' => ['query' => $query, 'count' => count($classes)]
    ];
}

echo json_encode($response);
$conn->close();
?>