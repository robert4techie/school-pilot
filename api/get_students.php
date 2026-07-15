<?php
// Set header to return JSON
header('Content-Type: application/json');

require_once '../auth.php'; 
require_once '../conn.php'; 

// Check connection
if ($conn->connect_error) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Connection failed: ' . $conn->connect_error
    ]);
    exit();
}

// Initialize response
$response = [
    'status' => 'error',
    'message' => 'Invalid request'
];

try {
    // Handle request to get all students
    if (isset($_GET['all']) && $_GET['all'] == 'true') {
        // The SQL query now provides the 'student_id' column directly as the JS expects.
        $sql = "SELECT CONCAT(first_name, ' ', last_name) as name, 
                current_class, stream, student_id, 
                profile_photo as photo 
                FROM students 
                ORDER BY name ASC";
                
        $result = mysqli_query($conn, $sql);
        
        if (!$result) {
             throw new Exception('Query failed: ' . mysqli_error($conn));
        }

        $students = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $students[] = $row;
        }
        
        $response = [
            'status' => 'success',
            'data' => $students
        ];
    }
    // You can add other conditions here if needed, e.g., fetching by class
    
} catch (Exception $e) {
    $response['status'] = 'error';
    $response['message'] = 'Server error: ' . $e->getMessage();
}

// Close the connection
mysqli_close($conn);

// Send JSON response
echo json_encode($response);
?>
