<?php
// Set the content type of the response to JSON
header('Content-Type: application/json');
require_once "../auth.php"; 
require_once "../conn.php";


/**
 * Fetches a list of active students from the database.
 *
 * This script retrieves essential student details to populate dropdowns or lists in the UI.
 * It focuses on fetching the student's unique ID, full name, class, and stream.
 * The results are ordered for better presentation.
 *
 * @param mysqli $conn The database connection object.
 * @return array An array of student data.
 */
function getStudents($conn) {
    // SQL query to select active students
    // Using CONCAT() to create a full name for easier display.
    $sql = "SELECT 
                student_id, 
                CONCAT(first_name, ' ', last_name) AS full_name,
                current_class, 
                stream
            FROM students
            WHERE status = 'active'
            ORDER BY first_name, last_name";

    // Prepare and execute the statement to prevent SQL injection
    $stmt = $conn->prepare($sql);
    
    // Check if the statement was prepared successfully
    if ($stmt === false) {
        // Log the error instead of echoing it to the user for better security
        error_log("MySQLi prepare failed: " . $conn->error);
        return ['success' => false, 'message' => 'An internal server error occurred.'];
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $students = [];
    // Fetch results and store them in an array
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }

    $stmt->close();
    
    return ['success' => true, 'data' => $students];
}

// Get the student data
$response = getStudents($conn);

// Close the database connection
$conn->close();

// Encode the response as JSON and output it
echo json_encode($response);
?>
