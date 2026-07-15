<?php
// api/get_teaching_staff.php
declare(strict_types=1);
header('Content-Type: application/json');
require_once '../auth.php';
require_once '../conn.php';

$response = ['success' => false, 'staff' => []];

try {
    $result = $conn->query(
        "SELECT staff_id, first_name, last_name, phone_number
         FROM staff
         ORDER BY first_name, last_name"
    );

    if ($result === false) {
        throw new Exception('Query failed: ' . $conn->error);
    }

    $staff = [];
    while ($row = $result->fetch_assoc()) {
        $staff[] = [
            'id'           => $row['staff_id'],
            'full_name'    => trim($row['first_name'] . ' ' . $row['last_name']),
            'phone_number' => $row['phone_number'] ?? '',
        ];
    }

    $response['success'] = true;
    $response['staff']   = $staff;

} catch (Exception $e) {
    $response['message'] = 'Could not load staff list.';
    error_log('[get_teaching_staff] ' . $e->getMessage());
}

if (isset($conn)) $conn->close();
echo json_encode($response);
