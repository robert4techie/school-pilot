<?php
require_once '../auth.php';
require_once '../conn.php';

header('Content-Type: application/json');

$class = isset($_GET['class']) ? trim($_GET['class']) : '';

if (empty($class)) {
    echo json_encode([]);
    exit;
}

// Derive level from class name
if (stripos($class, 'senior five') !== false || stripos($class, 'senior six') !== false) {
    $level_like = 'A%';
} else {
    $level_like = 'O%';
}

$sql  = "SELECT subj_id, subj_name FROM subjects WHERE level LIKE ? ORDER BY subj_name";
$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    echo json_encode([]);
    exit;
}

mysqli_stmt_bind_param($stmt, 's', $level_like);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$subjects = [];
while ($row = mysqli_fetch_assoc($result)) {
    $subjects[] = ['subj_id' => $row['subj_id'], 'subj_name' => $row['subj_name']];
}

mysqli_stmt_close($stmt);
echo json_encode($subjects);