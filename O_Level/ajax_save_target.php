<?php
/*
 * ajax_save_target.php  –  AJAX save for student target marks (v2 schema).
 * Accepts: target_type (aoi_1|aoi_2|aoi_3|eot) + target_value.
 * Place in the same directory as enter_targets.php  (O_Level/).
 */
require_once '../auth.php';
require_once '../conn.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']); exit;
}

// CSRF
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Security check failed.']); exit;
}

// Role
$allowed = ['developer', 'super user', 'subject teacher', 'class teacher', 'school leader'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorised.']); exit;
}
$role     = $_SESSION['role'];
$staff_id = $_SESSION['staff_id'] ?? '';
$is_super = in_array($role, ['developer', 'super user', 'school leader']);

// Inputs
$student_id  = trim($_POST['student_id']   ?? '');
$class       = trim($_POST['class']        ?? '');
$stream      = trim($_POST['stream']       ?? '');
$subject     = trim($_POST['subject']      ?? '');
$term        = trim($_POST['term']         ?? '');
$year        = (int)($_POST['year']        ?? 0);
$target_type = trim($_POST['target_type']  ?? '');
$val_raw     = $_POST['target_value']      ?? null;

$valid_types = ['aoi_1', 'aoi_2', 'aoi_3', 'eot'];
if (!$student_id || !$class || !$stream || !$subject || !$term || !$year ||
    !in_array($target_type, $valid_types, true)) {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid fields.']); exit;
}
$year_s = (string)$year;

// Clear (empty value) → delete the target row
if ($val_raw === '' || $val_raw === null) {
    $del = mysqli_prepare($conn,
        "DELETE FROM student_target_marks
         WHERE student_id=? AND subject=? AND term=? AND year=? AND target_type=?");
    if ($del) {
        mysqli_stmt_execute($del, [$student_id, $subject, $term, $year_s, $target_type]);
        mysqli_stmt_close($del);
    }
    echo json_encode(['success' => true, 'cleared' => true]); exit;
}

// Validate value range
$val = (float)$val_raw;
if ($target_type === 'eot') {
    if ($val < 0 || $val > 100) {
        echo json_encode(['success' => false, 'message' => 'EOT target must be 0–100%.']); exit;
    }
} else {
    // aoi_*
    if ($val < 0 || $val > 3) {
        echo json_encode(['success' => false, 'message' => 'AOI target must be 0.0–3.0.']); exit;
    }
}

// Permission check (non-super users)
if (!$is_super) {
    $ps = mysqli_prepare($conn,
        "SELECT COUNT(*) AS cnt FROM teaching_assignments ta
         INNER JOIN subjects s ON s.subj_id = ta.subject_id
         WHERE ta.staff_id=? AND ta.class_name=? AND s.subj_name=?");
    $perm_ok = false;
    if ($ps) {
        mysqli_stmt_execute($ps, [$staff_id, $class, $subject]);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($ps));
        $perm_ok = ($row['cnt'] > 0);
        mysqli_stmt_close($ps);
    }
    if (!$perm_ok) {
        echo json_encode(['success' => false, 'message' => 'You are not assigned to this subject.']); exit;
    }
}

// Verify student in correct class/stream
$vs = mysqli_prepare($conn,
    "SELECT student_id FROM students WHERE student_id=? AND current_class=? AND stream=? AND status='active'");
$stu_ok = false;
if ($vs) {
    mysqli_stmt_execute($vs, [$student_id, $class, $stream]);
    $stu_ok = (mysqli_num_rows(mysqli_stmt_get_result($vs)) > 0);
    mysqli_stmt_close($vs);
}
if (!$stu_ok) {
    echo json_encode(['success' => false, 'message' => 'Student not found in this class/stream.']); exit;
}

// ── Ensure table exists with v2 schema ────────────────────────
mysqli_query($conn,
    "CREATE TABLE IF NOT EXISTS `student_target_marks` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `student_id` varchar(30) NOT NULL,
        `class` varchar(50) NOT NULL,
        `stream` varchar(50) NOT NULL,
        `subject` varchar(100) NOT NULL,
        `term` varchar(20) NOT NULL,
        `year` varchar(10) NOT NULL,
        `target_type` varchar(20) NOT NULL DEFAULT 'eot',
        `target_value` decimal(6,2) NOT NULL DEFAULT 0.00,
        `added_by` varchar(50) NOT NULL,
        `date_added` timestamp NOT NULL DEFAULT current_timestamp(),
        `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_student_target_v2` (`student_id`,`subject`,`term`,`year`,`target_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// ── Auto-migrate old schema if needed ─────────────────────────
$chk = mysqli_query($conn, "SHOW COLUMNS FROM `student_target_marks` LIKE 'target_type'");
if (!$chk || mysqli_num_rows($chk) === 0) {
    @mysqli_query($conn, "ALTER TABLE `student_target_marks` ADD COLUMN IF NOT EXISTS `target_type` varchar(20) NOT NULL DEFAULT 'eot' AFTER `year`");
    $chk2 = mysqli_query($conn, "SHOW COLUMNS FROM `student_target_marks` LIKE 'target_percentage'");
    if ($chk2 && mysqli_num_rows($chk2) > 0) {
        @mysqli_query($conn, "ALTER TABLE `student_target_marks` CHANGE `target_percentage` `target_value` decimal(6,2) NOT NULL DEFAULT 0.00");
    }
    @mysqli_query($conn, "ALTER TABLE `student_target_marks` DROP KEY `uk_student_target`");
    @mysqli_query($conn, "ALTER TABLE `student_target_marks` ADD UNIQUE KEY IF NOT EXISTS `uk_student_target_v2` (`student_id`,`subject`,`term`,`year`,`target_type`)");
}

// ── Upsert ────────────────────────────────────────────────────
$upsert = mysqli_prepare($conn,
    "INSERT INTO student_target_marks
         (student_id, class, stream, subject, term, year, target_type, target_value, added_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
         target_value = VALUES(target_value),
         class        = VALUES(class),
         stream       = VALUES(stream),
         added_by     = VALUES(added_by),
         last_updated = CURRENT_TIMESTAMP");
if (!$upsert) {
    echo json_encode(['success' => false, 'message' => 'DB error: '.mysqli_error($conn)]); exit;
}
mysqli_stmt_execute($upsert, [$student_id, $class, $stream, $subject, $term, $year_s, $target_type, $val, $staff_id]);
mysqli_stmt_close($upsert);

echo json_encode(['success' => true, 'target_type' => $target_type, 'target_value' => $val]);
