<?php
/**
 * save_marks.php  — AJAX endpoint for auto-saving individual marks.
 * Returns JSON only. Never displays errors to end users.
 */

// Strict production error handling
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

header('Content-Type: application/json');

require_once '../conn.php';
require_once '../auth.php';
require_once 'teacher_auth_check.php';

// ── Helper: clean JSON exit ───────────────────────────────
function respond(bool $ok, string $message, array $extra=[]): never {
    echo json_encode(array_merge(['status'=>$ok?'success':'error','message'=>$message], $extra));
    exit;
}

// ── Validate table name against a whitelist pattern ───────
function isValidResultsTable(mysqli $conn, string $table): bool {
    // Pattern: YYYY_roman_level e.g. 2025_i_olevel
    if (!preg_match('/^\d{4}_(i|ii|iii)_(olevel|alevel)$/', $table)) return false;
    $escaped = mysqli_real_escape_string($conn, $table);
    $result  = mysqli_query($conn, "SHOW TABLES LIKE '$escaped'");
    return $result && mysqli_num_rows($result) > 0;
}

// ── Read & sanitise POST ──────────────────────────────────
$student_id = trim($_POST['student_id'] ?? '');
$topic_id   = trim($_POST['topic_id']   ?? '');
$mark       = trim($_POST['mark']       ?? '');
$class      = trim($_POST['class']      ?? '');
$stream     = trim($_POST['stream']     ?? '');
$subject    = trim($_POST['subject']    ?? '');
$table      = trim($_POST['table']      ?? '');
$action     = trim($_POST['action']     ?? 'update');

// Whitelist action
if (!in_array($action, ['update','delete'], true)) respond(false, 'Invalid action.');

// Required fields
if (empty($student_id) || empty($topic_id) || empty($table) || empty($subject)) {
    error_log("save_marks.php: missing params — student_id=$student_id topic_id=$topic_id table=$table subject=$subject");
    respond(false, 'Missing required parameters.');
}

// Validate table (security critical — prevents table injection)
if (!isValidResultsTable($conn, $table)) {
    error_log("save_marks.php: invalid/non-existent table '$table' requested by user " . ($_SESSION['user_id'] ?? 'unknown'));
    respond(false, 'Invalid results table.');
}

// ── DELETE ────────────────────────────────────────────────
if ($action === 'delete' || $mark === '') {
    $sql  = "DELETE FROM `$table` WHERE student_id=? AND topic_id=? AND subject=?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) { error_log('save_marks prepare error: '.mysqli_error($conn)); respond(false, 'Database error.'); }
    mysqli_stmt_bind_param($stmt, 'sss', $student_id, $topic_id, $subject);
    if (!mysqli_stmt_execute($stmt)) { error_log('save_marks delete error: '.mysqli_stmt_error($stmt)); respond(false, 'Failed to remove mark.'); }
    mysqli_stmt_close($stmt);
    respond(true, 'Mark removed.', ['action'=>'delete']);
}

// ── INSERT / UPDATE ───────────────────────────────────────
$mark_float = (float)$mark;
if ($topic_id === 'EOT') {
    $mark_float = min(100.0, max(0.0, $mark_float));
    $max_marks  = 100.00;
} else {
    $mark_float = min(3.0, max(0.9, $mark_float));
    $max_marks  = 3.00;
}
$mark_fmt = number_format($mark_float, 1, '.', '');

// Check if record exists
$check = mysqli_prepare($conn, "SELECT id FROM `$table` WHERE student_id=? AND topic_id=? AND subject=?");
if (!$check) respond(false, 'Database error.');
mysqli_stmt_bind_param($check, 'sss', $student_id, $topic_id, $subject);
mysqli_stmt_execute($check);
$exists = mysqli_num_rows(mysqli_stmt_get_result($check)) > 0;
mysqli_stmt_close($check);

if ($exists) {
    $sql  = "UPDATE `$table` SET marks=?, max_marks=? WHERE student_id=? AND topic_id=? AND subject=?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) respond(false, 'Database error.');
    mysqli_stmt_bind_param($stmt, 'ddsss', $mark_float, $max_marks, $student_id, $topic_id, $subject);
    if (!mysqli_stmt_execute($stmt)) { error_log('save_marks update error: '.mysqli_stmt_error($stmt)); respond(false, 'Failed to update mark.'); }
    mysqli_stmt_close($stmt);
    respond(true, 'Mark updated.', ['mark'=>$mark_fmt, 'action'=>'update']);
} else {
    $sql  = "INSERT INTO `$table` (student_id, class, stream, subject, topic_id, marks, max_marks) VALUES (?,?,?,?,?,?,?)";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) respond(false, 'Database error.');
    mysqli_stmt_bind_param($stmt, 'sssssdd', $student_id, $class, $stream, $subject, $topic_id, $mark_float, $max_marks);
    if (!mysqli_stmt_execute($stmt)) { error_log('save_marks insert error: '.mysqli_stmt_error($stmt)); respond(false, 'Failed to save mark.'); }
    mysqli_stmt_close($stmt);
    respond(true, 'Mark saved.', ['mark'=>$mark_fmt, 'action'=>'insert']);
}