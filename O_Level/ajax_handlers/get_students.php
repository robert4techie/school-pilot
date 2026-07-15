<?php
/**
 * get_students.php
 * Fetches students based on class and stream for an AJAX request.
 * This version is corrected to match the user's specific table schema and file path.
 */

// Set the content type to JSON, which is required for AJAX responses.
header('Content-Type: application/json');

// Include the database connection.
// CORRECTED PATH: Go up two directories to find conn.php
require_once '../../conn.php'; 

// Initialize a default response structure.
$response = [
    'success' => false,
    'students' => [],
    'message' => ''
];

// 1. Validate that the necessary POST data was sent from the form.
if (!isset($_POST['class']) || !isset($_POST['streams'])) {
    $response['message'] = 'Error: Missing class or stream information.';
    echo json_encode($response);
    exit;
}

$class = $_POST['class'];
$streams = $_POST['streams'];

if (!is_array($streams) || empty($streams)) {
    $response['message'] = 'Error: No streams were selected.';
    echo json_encode($response);
    exit;
}

// 2. Build the SQL query safely with prepared statements.
// Create one '?' placeholder for each stream value.
$stream_placeholders = implode(',', array_fill(0, count($streams), '?'));

// This SQL query uses the EXACT column names from your 'students' table.
$sql = "SELECT 
            student_id,
            first_name,
            last_name,
            stream,
            current_class,
            profile_photo 
        FROM students
        WHERE current_class = ? 
        AND stream IN ($stream_placeholders) 
        ORDER BY first_name ASC, last_name ASC";

$stmt = mysqli_prepare($conn, $sql);

if ($stmt) {
    // 3. Bind parameters and execute the query.
    $types = 's' . str_repeat('s', count($streams)); // 's' for class, plus 's' for each stream.
    $params = array_merge([$class], $streams);
    
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result) {
        $students_data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            // 4. Build the student array for the JSON response.
            // THIS IS THE CRITICAL PART:
            // We create a key named 'id' and give it the value of 'student_id'.
            // This is what the JavaScript on the form page needs.
            $students_data[] = [
                'id'          => $row['student_id'], // Create the 'id' field for JavaScript.
                'student_id'  => $row['student_id'], // Keep 'student_id' for display purposes.
                'full_name'   => $row['first_name'] . ' ' . $row['last_name'],
                'stream'      => $row['stream'],
                'class'       => $row['current_class'],
                'photo'       => $row['profile_photo'] 
            ];
        }
        
        $response['success'] = true;
        $response['students'] = $students_data;
        $response['message'] = count($students_data) > 0 ? 'Students loaded.' : 'No students found.';

    } else {
        $response['message'] = 'Database query failed.';
    }
    
    mysqli_stmt_close($stmt);
} else {
    $response['message'] = 'Database query preparation failed.';
}

mysqli_close($conn);

// 5. Send the final JSON response back to the form.
echo json_encode($response);
?>
