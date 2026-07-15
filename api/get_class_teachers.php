<?php
// api/get_class_teachers.php
declare(strict_types=1);
header('Content-Type: application/json');
require_once '../auth.php';
require_once '../conn.php';

$response = ['success' => false, 'classTeachers' => []];

try {
    $sql = "
        SELECT
            ct.id,
            ct.class_name,
            ct.stream,
            ct.academic_year,
            s.staff_id,
            CONCAT(s.first_name, ' ', s.last_name) AS full_name,
            s.phone_number
        FROM class_teachers ct
        INNER JOIN staff s ON ct.staff_id = s.staff_id
        ORDER BY ct.class_name, ct.stream
    ";

    $result = $conn->query($sql);
    if ($result === false) {
        throw new Exception('Query failed: ' . $conn->error);
    }

    $classTeachers = [];
    while ($row = $result->fetch_assoc()) {
        $classTeachers[] = [
            'id'            => (int) $row['id'],
            'class_name'    => $row['class_name'],
            'stream'        => $row['stream'],
            'academic_year' => $row['academic_year'],
            'staff_id'      => $row['staff_id'],
            'full_name'     => $row['full_name'],
            'phone_number'  => $row['phone_number'] ?? '',
        ];
    }

    $response['success']      = true;
    $response['classTeachers'] = $classTeachers;

} catch (Exception $e) {
    $response['message'] = 'Could not load class teacher data.';
    error_log('[get_class_teachers] ' . $e->getMessage());
}

if (isset($conn)) $conn->close();
echo json_encode($response);
