<?php
require_once '../auth.php';
require_once '../conn.php';
header('Content-Type: application/json');

$sql = "SELECT DISTINCT subj_name FROM subjects WHERE level LIKE '%O%' ORDER BY subj_name";
$result = mysqli_query($conn, $sql);

$subjects = [];
while ($row = mysqli_fetch_assoc($result)) {
    $subjects[] = $row['subj_name'];
}

echo json_encode($subjects);
mysqli_close($conn);
