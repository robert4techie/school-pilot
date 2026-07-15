<?php
require_once '../auth.php';
require_once '../conn.php';
header('Content-Type: application/json');

$students = [];

// Fetch students from S1 to S4 only
$sql = "SELECT student_id, first_name, last_name, current_class, stream 
        FROM students 
        WHERE current_class IN ('Senior One', 'Senior Two', 'Senior Three', 'Senior Four') 
        ORDER BY current_class, stream, first_name, last_name";

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $students[] = [
            'student_id' => $row['student_id'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'current_class' => $row['current_class'],
            'stream' => $row['stream']
        ];
    }
}

echo json_encode($students);
mysqli_close($conn);