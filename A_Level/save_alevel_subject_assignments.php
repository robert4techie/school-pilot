<?php
/**
 * save_alevel_subject_assignments.php
 * SchoolPilot — AJAX endpoint: assign or unassign a student from an A-level subject.
 *
 * Expects POST JSON params:
 *   csrf_token, class, subject, student_id, student_name, stream, assigned (1|0)
 *
 * Returns JSON: { success: bool, message: string }
 */

require_once '../auth.php';
require_once '../conn.php';

header('Content-Type: application/json; charset=utf-8');

// ── Helper ─────────────────────────────────────────────────────────────────────
function json_out(bool $success, string $message = ''): void
{
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

// ── Only accept POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(false, 'Invalid request method.');
}

// ── CSRF ───────────────────────────────────────────────────────────────────────
$csrf = trim($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    json_out(false, 'Invalid security token. Please refresh the page and try again.');
}

// ── Input ──────────────────────────────────────────────────────────────────────
$class        = trim($_POST['class']        ?? '');
$subject      = trim($_POST['subject']      ?? '');
$student_id   = trim($_POST['student_id']   ?? '');
$student_name = trim($_POST['student_name'] ?? '');
$stream       = trim($_POST['stream']       ?? '');
$assigned     = (int) ($_POST['assigned']   ?? -1);

// ── Validate ───────────────────────────────────────────────────────────────────
$validClasses  = ['Senior Five', 'Senior Six'];
$validStreams   = ['Arts', 'Sciences'];

if (!in_array($class, $validClasses, true))  json_out(false, 'Invalid class.');
if (!in_array($stream, $validStreams, true))  json_out(false, 'Invalid stream.');
if ($subject === '')                          json_out(false, 'Subject is required.');
if ($student_id === '')                       json_out(false, 'Student ID is required.');
if (!in_array($assigned, [0, 1], true))       json_out(false, 'Invalid assigned value.');

// ── Confirm subject exists and is not GP ──────────────────────────────────────
$chkSubj = mysqli_prepare($conn,
    "SELECT subj_id FROM subjects
     WHERE subj_name = ? AND subj_abbr != 'GP' AND level LIKE '%A%' LIMIT 1"
);
if (!$chkSubj) json_out(false, 'Database error (subject check).');
mysqli_stmt_bind_param($chkSubj, 's', $subject);
mysqli_stmt_execute($chkSubj);
mysqli_stmt_store_result($chkSubj);
if (mysqli_stmt_num_rows($chkSubj) === 0) json_out(false, 'Subject not found or not assignable.');
mysqli_stmt_close($chkSubj);

// ── Confirm student exists and belongs to the given class ─────────────────────
$chkStu = mysqli_prepare($conn,
    "SELECT student_id FROM students
     WHERE student_id = ? AND current_class = ? AND status = 'Active' LIMIT 1"
);
if (!$chkStu) json_out(false, 'Database error (student check).');
mysqli_stmt_bind_param($chkStu, 'ss', $student_id, $class);
mysqli_stmt_execute($chkStu);
mysqli_stmt_store_result($chkStu);
if (mysqli_stmt_num_rows($chkStu) === 0) json_out(false, 'Student not found in the specified class.');
mysqli_stmt_close($chkStu);

// ── Assigned by ────────────────────────────────────────────────────────────────
$assigned_by = $_SESSION['username'] ?? 'System';

// ── Perform assignment or removal ─────────────────────────────────────────────
if ($assigned === 1) {

    // INSERT IGNORE so duplicate calls are idempotent
    $stmt = mysqli_prepare($conn,
        "INSERT IGNORE INTO student_alevel_subjects
             (student_id, student_name, class, stream, subject, assigned_by, assigned_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())"
    );
    if (!$stmt) json_out(false, 'Database error (insert prepare).');
    mysqli_stmt_bind_param($stmt, 'ssssss',
        $student_id, $student_name, $class, $stream, $subject, $assigned_by
    );
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if (!$ok) json_out(false, 'Failed to assign student: ' . mysqli_error($conn));
    json_out(true, 'Student assigned successfully.');

} else {

    $stmt = mysqli_prepare($conn,
        "DELETE FROM student_alevel_subjects
         WHERE student_id = ? AND class = ? AND subject = ?"
    );
    if (!$stmt) json_out(false, 'Database error (delete prepare).');
    mysqli_stmt_bind_param($stmt, 'sss', $student_id, $class, $subject);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if (!$ok) json_out(false, 'Failed to unassign student: ' . mysqli_error($conn));
    json_out(true, 'Student unassigned successfully.');
}
