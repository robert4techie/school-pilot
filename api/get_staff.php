<?php
require_once '../auth.php';
require_once '../conn.php';
require_once '../staff_functions.php';
header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'No staff ID provided']);
    exit();
}

$staff = getStaffById((int)$_GET['id']);
if (!$staff) {
    echo json_encode(['error' => 'Staff member not found']);
    exit();
}

echo json_encode($staff);
?>