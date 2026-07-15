<?php
require_once '../auth.php';
require_once '../conn.php';

header('Content-Type: application/json');

// Only GET is needed for this endpoint
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    $sql = "SELECT student_id, first_name, last_name, current_class, stream
            FROM students
            WHERE status = 'active'
            ORDER BY first_name, last_name";

    $result = mysqli_query($conn, $sql);

    if ($result === false) {
        throw new RuntimeException('Database query error: ' . mysqli_error($conn));
    }

    $students = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $students[] = [
            'student_id' => $row['student_id'],
            'full_name'  => $row['first_name'] . ' ' . $row['last_name'],
            'class'      => $row['current_class'],
            'stream'     => $row['stream'],
        ];
    }

    mysqli_free_result($result);

    echo json_encode(['success' => true, 'data' => $students]);

} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

mysqli_close($conn);
