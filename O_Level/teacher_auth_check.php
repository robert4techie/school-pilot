<?php

/**
 * This file acts as a security checkpoint.
 * It ensures that the currently logged-in user has a role that is permitted
 * to access the marks entry pages.
 *
 * It must be included AFTER conn.php and auth.php.
 */

if (!isset($_SESSION['user_id'])) {
    die("Access Denied. You are not logged in.");
}

$allowed_roles = [
    'super user',
    'school leader',
    'class teacher',
    'subject teacher',
    'developer'
];

if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], $allowed_roles)) {
    die("Access Denied. Your user role does not have permission to access this page.");
}

function canTeacherAccessAssignment($conn, $staff_id, $class, $subject, $streams)
{
    if (empty($streams) || empty($class) || empty($subject)) {
        return false;
    }

    $lower_streams = array_values(array_unique(array_map('strtolower', $streams)));
    $placeholders  = str_repeat('?,', count($lower_streams) - 1) . '?';

    $sql = "
        SELECT DISTINCT LOWER(ta.stream_name) AS stream_name
        FROM teaching_assignments ta
        JOIN subjects s ON ta.subject_id = s.subj_id
        WHERE ta.staff_id = ?
          AND LOWER(ta.class_name) = LOWER(?)
          AND LOWER(s.subj_name) = LOWER(?)
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log("canTeacherAccessAssignment SQL prepare failed: " . mysqli_error($conn));
        return false;
    }

    // staff_id, class, subject are all VARCHAR — bind as strings ('s').
    // Binding staff_id as 'i' silently truncates "OU-2026-STA-0030" to 0,
    // which is why every non-admin teacher was being denied access.
    mysqli_stmt_bind_param($stmt, 'sss', $staff_id, $class, $subject);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $assigned_streams = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $assigned_streams[] = $row['stream_name'];
    }
    mysqli_stmt_close($stmt);

    if (empty($assigned_streams)) {
        return false;
    }

    // A teacher assigned via the 'All Streams' sentinel covers every stream
    // for that class/subject.
    if (in_array('all streams', $assigned_streams, true)) {
        return true;
    }

    // Require EVERY requested stream to be covered — previously an IN(...)
    // check let a teacher assigned to just one selected stream get access
    // to all the others too.
    $missing = array_diff($lower_streams, $assigned_streams);
    return empty($missing);
}

// --- Main Security Check Logic ---
$is_admin_role = in_array($_SESSION['user_type'], ['super user', 'school leader', 'developer']);

if (!$is_admin_role) {
    $loggedInStaffID = $_SESSION['staff_id'];

    $classToCheck   = isset($_REQUEST['class'])   ? trim($_REQUEST['class'])   : '';
    $subjectToCheck = isset($_REQUEST['subject'])  ? trim($_REQUEST['subject']) : '';

    // Support both streams[] (array POST from add_marks.php / sel_aoi_add_marks.php)
    // and stream (singular POST from save_marks.php fetch calls)
    if (!empty($_REQUEST['streams'])) {
        $streamsToCheck = array_map('trim', (array)$_REQUEST['streams']);
    } elseif (!empty($_REQUEST['stream'])) {
        $streamsToCheck = [trim($_REQUEST['stream'])];
    } else {
        $streamsToCheck = [];
    }

    if (!canTeacherAccessAssignment($conn, $loggedInStaffID, $classToCheck, $subjectToCheck, $streamsToCheck)) {
        error_log("SECURITY ALERT: Staff ID {$loggedInStaffID} ({$_SESSION['user_type']}) tried to access marks for a non-assigned class. Class: {$classToCheck}, Subject: {$subjectToCheck}");
        header("Location: access_denied.php");
        exit();
    }
}