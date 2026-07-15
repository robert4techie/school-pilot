<?php
// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "school_pilotdb";


// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);

// Check connection
if (!$conn) {
    die(json_encode([
        'status' => 'error',
        'message' => 'Connection failed: ' . mysqli_connect_error()
    ]));
}

// Function to sanitize input data
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Get student ID from request
if (isset($_GET['student_id'])) {
    $student_id = sanitize_input($_GET['student_id']);
    
    // Query to get student info
    $sql = "SELECT id, CONCAT(first_name, ' ', last_name) as student_name, current_class, stream, student_id as admission_number, profile_photo as photo_url FROM students WHERE id = '$student_id'";
    $result = mysqli_query($conn, $sql);
    
    if (mysqli_num_rows($result) > 0) {
        $student = mysqli_fetch_assoc($result);
        echo json_encode([
            'status' => 'success',
            'data' => $student
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Student not found'
        ]);
    }
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Student ID is required'
    ]);
}

// Close the connection
mysqli_close($conn);
?>