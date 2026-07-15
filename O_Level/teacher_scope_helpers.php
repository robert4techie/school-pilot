<?php
/**
 * Helpers to scope the class/subject/stream pickers on sel_add_marks.php
 * to what the logged-in teacher is actually assigned to teach.
 * Must be included AFTER conn.php (needs $conn and $_SESSION).
 */

if (!function_exists('isAdminRole')) {
    function isAdminRole(): bool {
        return in_array($_SESSION['user_type'] ?? '', ['super user', 'school leader', 'developer'], true);
    }
}

if (!function_exists('requireAllowedRole')) {
    function requireAllowedRole(): void {
        $allowed_roles = ['super user', 'school leader', 'class teacher', 'subject teacher', 'developer'];
        if (!isset($_SESSION['user_id'])) {
            die("Access Denied. You are not logged in.");
        }
        if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], $allowed_roles, true)) {
            die("Access Denied. Your user role does not have permission to access this page.");
        }
    }
}

if (!function_exists('getAssignedClasses')) {
    function getAssignedClasses(mysqli $conn, string $staff_id): array {
        $sql = "SELECT DISTINCT class_name FROM teaching_assignments WHERE staff_id = ? ORDER BY class_name";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) return [];
        mysqli_stmt_bind_param($stmt, 's', $staff_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $classes = [];
        while ($row = mysqli_fetch_assoc($result)) $classes[] = $row['class_name'];
        mysqli_stmt_close($stmt);
        return $classes;
    }
}

if (!function_exists('getAssignedSubjectsForClass')) {
    function getAssignedSubjectsForClass(mysqli $conn, string $staff_id, string $class): array {
        $sql = "SELECT DISTINCT s.subj_id, s.subj_name
                FROM teaching_assignments ta
                JOIN subjects s ON ta.subject_id = s.subj_id
                WHERE ta.staff_id = ? AND LOWER(ta.class_name) = LOWER(?)
                ORDER BY s.subj_name";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) return [];
        mysqli_stmt_bind_param($stmt, 'ss', $staff_id, $class);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $subjects = [];
        while ($row = mysqli_fetch_assoc($result)) $subjects[] = $row;
        mysqli_stmt_close($stmt);
        return $subjects;
    }
}

if (!function_exists('getAssignedStreamsForClass')) {
    // Streams assigned to this teacher for the class, across ANY subject.
    // Used before a subject has been picked yet.
    function getAssignedStreamsForClass(mysqli $conn, string $staff_id, string $class, array $all_streams): array {
        $sql = "SELECT DISTINCT ta.stream_name
                FROM teaching_assignments ta
                WHERE ta.staff_id = ? AND LOWER(ta.class_name) = LOWER(?)";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) return [];
        mysqli_stmt_bind_param($stmt, 'ss', $staff_id, $class);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $assigned = [];
        while ($row = mysqli_fetch_assoc($result)) $assigned[] = $row['stream_name'];
        mysqli_stmt_close($stmt);

        if (in_array('all streams', array_map('strtolower', $assigned), true)) {
            return $all_streams;
        }
        return array_values(array_unique($assigned));
    }
}

if (!function_exists('getAssignedStreamsForClassSubject')) {
    // Streams assigned to this teacher for a specific class + subject.
    function getAssignedStreamsForClassSubject(mysqli $conn, string $staff_id, string $class, string $subject, array $all_streams): array {
        $sql = "SELECT DISTINCT ta.stream_name
                FROM teaching_assignments ta
                JOIN subjects s ON ta.subject_id = s.subj_id
                WHERE ta.staff_id = ?
                  AND LOWER(ta.class_name) = LOWER(?)
                  AND LOWER(s.subj_name) = LOWER(?)";
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) return [];
        mysqli_stmt_bind_param($stmt, 'sss', $staff_id, $class, $subject);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $assigned = [];
        while ($row = mysqli_fetch_assoc($result)) $assigned[] = $row['stream_name'];
        mysqli_stmt_close($stmt);

        if (in_array('all streams', array_map('strtolower', $assigned), true)) {
            return $all_streams;
        }
        return array_values(array_unique($assigned));
    }
}