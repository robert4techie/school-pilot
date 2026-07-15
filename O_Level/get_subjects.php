<?php
require_once '../auth.php';
require_once '../conn.php';
require_once 'teacher_scope_helpers.php';
requireAllowedRole();

header('Content-Type: application/json');

$class = isset($_GET['class']) ? trim($_GET['class']) : '';
if ($class === '') { echo json_encode([]); exit; }

if (isAdminRole()) {
    $level_sql = "SELECT level FROM classes WHERE LOWER(class_name) = LOWER(?) LIMIT 1";
    $stmt = mysqli_prepare($conn, $level_sql);
    $level = '';
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $class);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($res)) $level = $row['level'];
        mysqli_stmt_close($stmt);
    }
    if (empty($level)) $level = 'O';

    // FIND_IN_SET correctly matches 'O' against a comma-list like 'O,A'
    $sql = "SELECT subj_id, subj_name FROM subjects WHERE FIND_IN_SET(?, level) ORDER BY subj_name";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 's', $level);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $subjects = [];
    while ($row = mysqli_fetch_assoc($result)) $subjects[] = $row;
    mysqli_stmt_close($stmt);
} else {
    $staff_id = $_SESSION['staff_id'] ?? '';
    $subjects = getAssignedSubjectsForClass($conn, $staff_id, $class);
}

echo json_encode($subjects);