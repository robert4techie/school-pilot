<?php
require_once '../auth.php';
require_once '../conn.php';
header('Content-Type: application/json');

$subjects = [];

$sql = "SELECT subj_name FROM subjects ORDER BY subj_name";
$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $subjects[] = $row['subj_name'];
    }
}

echo json_encode($subjects);
mysqli_close($conn);