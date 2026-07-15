<?php
header('Content-Type: application/json');
require_once 'conn.php'; 

$class = $_GET['class'] ?? '';
$response = ['success' => false, 'streams' => []];

if (empty($class)) {
    $response['message'] = 'Class parameter is required.';
    echo json_encode($response);
    exit();
}

// --- MYSQLi PREPARED STATEMENT ---
$sql = "SELECT DISTINCT stream FROM students WHERE current_class = ? AND stream IS NOT NULL AND stream != '' ORDER BY stream ASC";

$stmt = $conn->prepare($sql);

if ($stmt) {
    // Bind the class variable to the placeholder
    $stmt->bind_param("s", $class);

    // Execute the query
    $stmt->execute();

    // Get the result set
    $result = $stmt->get_result();
    
    // Fetch all streams into the response array
    $response['streams'] = $result->fetch_all(MYSQLI_ASSOC);
    $response['success'] = true;

    // Close the statement
    $stmt->close();
} else {
    $response['message'] = "Error preparing statement: " . $conn->error;
}
// --- END OF MYSQLi PREPARED STATEMENT ---

echo json_encode($response);
?>