<?php
require_once '../auth.php';
require_once '../conn.php';
require_once 'teacher_scope_helpers.php';
requireAllowedRole();

header('Content-Type: application/json');

function getStreams(mysqli $conn): array {
    $result = mysqli_query($conn, "SELECT DISTINCT stream_name FROM streams WHERE status='active' ORDER BY stream_name");
    $streams = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) $streams[] = $row['stream_name'];
    }
    return $streams ?: ['East', 'West', 'South', 'North'];
}

$global_streams = getStreams($conn);

if (isAdminRole()) {
    echo json_encode($global_streams);
    exit;
}

$class   = isset($_GET['class'])   ? trim($_GET['class'])   : '';
$subject = isset($_GET['subject']) ? trim($_GET['subject']) : '';
$staff_id = $_SESSION['staff_id'] ?? '';

if ($class === '') { echo json_encode([]); exit; }

$streams = ($subject !== '')
    ? getAssignedStreamsForClassSubject($conn, $staff_id, $class, $subject, $global_streams)
    : getAssignedStreamsForClass($conn, $staff_id, $class, $global_streams);

echo json_encode(array_values($streams));