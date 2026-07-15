<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');


if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}
require_once '../auth.php';
require_once '../conn.php';

try {
    // Check if connection exists and is valid
    if (!$conn) {
        throw new Exception("Database connection failed");
    }

    // Prepare the SQL query to fetch all students
    $sql = "SELECT 
                student_id,
                first_name,
                last_name,
                date_of_birth,
                gender,
                nationality,
                religion,
                profile_photo,
                residential_address,
                current_class,
                stream,
                school_pay_code,
                date_of_enrolment,
                previous_school,
                subject_combination,
                created_at
            FROM students 
            ORDER BY first_name ASC, last_name ASC";

    $result = mysqli_query($conn, $sql);
    
    if (!$result) {
        throw new Exception("Query failed: " . mysqli_error($conn));
    }

    $students = array();
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Clean and format the data
        $student = array(
            'student_id' => htmlspecialchars($row['student_id']),
            'first_name' => htmlspecialchars($row['first_name']),
            'last_name' => htmlspecialchars($row['last_name']),
            'date_of_birth' => $row['date_of_birth'],
            'gender' => htmlspecialchars($row['gender']),
            'nationality' => htmlspecialchars($row['nationality']),
            'religion' => htmlspecialchars($row['religion'] ?? ''),
            'profile_photo' => htmlspecialchars($row['profile_photo'] ?? ''),
            'residential_address' => htmlspecialchars($row['residential_address']),
            'current_class' => htmlspecialchars($row['current_class']),
            'stream' => htmlspecialchars($row['stream']),
            'school_pay_code' => htmlspecialchars($row['school_pay_code']),
            'date_of_enrolment' => $row['date_of_enrolment'],
            'previous_school' => htmlspecialchars($row['previous_school'] ?? ''),
            'subject_combination' => htmlspecialchars($row['subject_combination']),
            'created_at' => $row['created_at']
        );
        
        $students[] = $student;
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'students' => $students,
        'total_count' => count($students),
        'message' => 'Students loaded successfully'
    ]);

} catch (Exception $e) {
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'students' => [],
        'total_count' => 0,
        'message' => $e->getMessage()
    ]);
} finally {
    // Close the database connection if it exists
    if (isset($conn) && $conn) {
        mysqli_close($conn);
    }
}
?>