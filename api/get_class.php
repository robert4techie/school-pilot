<?php
require_once "../auth.php";
require_once "../conn.php";

header('Content-Type: application/json');

try {
    // Get distinct classes from students table
    $query = "SELECT DISTINCT current_class FROM students WHERE current_class IS NOT NULL AND current_class != '' ORDER BY current_class";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $classes = []; // Initialize an empty array
    
    // Use a compatible while loop instead of fetch_all()
    while ($row = $result->fetch_assoc()) {
        $classes[] = $row['current_class'];
    }

    echo json_encode([
        'success' => true,
        'classes' => $classes
    ]);

} catch (Exception $e) {
    // Log the actual error to the server's error log for debugging
    error_log("Error in get_class.php: " . $e->getMessage());
    
    // Send a generic error message to the client
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while fetching classes.'
    ]);
}
?>