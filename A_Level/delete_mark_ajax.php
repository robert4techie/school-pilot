<?php
declare(strict_types=1);

/**
 * delete_mark_ajax.php
 * SchoolPilot — A-Level Marks: AJAX delete endpoint.
 *
 * Security:  authenticated session (was missing in original — CRITICAL FIX),
 *            CSRF token, full input validation, parameterised queries,
 *            safe dynamic table names.
 * Protocol:  POST-only, JSON response.
 *
 * Audit:     Every successful deletion is written to marks_audit_log.
 *            The old mark value is captured before the DELETE is executed.
 */

ob_start();

require_once '../auth.php';           // ← CRITICAL: was absent in original. Unauthenticated users could delete marks.
require_once '../conn.php';           // $conn (mysqli)
require_once 'audit_log_helper.php'; // ← AUDIT: ensureAuditTable / fetchExistingMark / logMarkAudit

// ── Always JSON ───────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache');

// ── Helpers ───────────────────────────────────────────────────────────────────

function send(bool $ok, string $msg): never
{
    ob_clean();
    echo json_encode(['success' => $ok, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function csrfValid(string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && !empty($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

function termSlug(string $term): string|false
{
    if (str_contains($term, '1') || strtolower(trim($term)) === 'i')   return 'i';
    if (str_contains($term, '2') || strtolower(trim($term)) === 'ii')  return 'ii';
    if (str_contains($term, '3') || strtolower(trim($term)) === 'iii') return 'iii';
    return false;
}

function buildTableName(string $year, string $term): string|false
{
    if (!preg_match('/^\d{4}$/', $year)) return false;
    $slug = termSlug($term);
    if ($slug === false) return false;
    return "{$year}_{$slug}_alevel";
}

function tableExists(mysqli $db, string $name): bool
{
    $sql  = 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1';
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, 's', $name);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $found = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    return $found;
}

// ── Gate Checks ───────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send(false, 'Method not allowed.');
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!csrfValid($csrfToken)) {
    send(false, 'Security token mismatch. Please refresh the page and try again.');
}

// ── Extract Inputs ────────────────────────────────────────────────────────────

$studentId = trim($_POST['student_id'] ?? '');
$paper     = trim($_POST['paper']      ?? '');
$class     = trim($_POST['class']      ?? '');
$term      = trim($_POST['term']       ?? '');
$year      = trim($_POST['year']       ?? '');
$subject   = trim($_POST['subject']    ?? '');
$examType  = trim($_POST['exam_type']  ?? '');

// ── Validate Required Fields ──────────────────────────────────────────────────

$required = [
    'student_id' => $studentId,
    'paper'      => $paper,
    'class'      => $class,
    'term'       => $term,
    'year'       => $year,
    'subject'    => $subject,
    'exam_type'  => $examType,
];

foreach ($required as $field => $value) {
    if ($value === '') {
        send(false, "Missing required field: {$field}.");
    }
}

// Student ID format guard
if (!preg_match('/^[\w\-]{1,50}$/u', $studentId)) {
    send(false, 'Invalid student ID format.');
}

// Paper format guard
if (!preg_match('/^[IVXivx]{1,6}$/', $paper) && !preg_match('/^P[1-9]$/', $paper)) {
    send(false, 'Invalid paper identifier.');
}

// Build & validate table name
$tableName = buildTableName($year, $term);
if ($tableName === false) {
    send(false, 'Invalid year or term value.');
}

// ── Database Operations ───────────────────────────────────────────────────────

// If the table doesn't exist there is nothing to delete — treat as success
if (!tableExists($conn, $tableName)) {
    send(true, 'No mark found to delete.');
}

// ── AUDIT: Capture the mark value before deletion ─────────────────────────────
// We read the existing mark now so it can be recorded in the audit trail.
// If the row doesn't exist, $auditOldMark stays null and affected rows will be 0.
$auditUser    = $_SESSION['user_name'] ?? 'unknown';
$auditOldMark = fetchExistingMark(
    $conn, $tableName, $studentId, $class, $term, $year, $subject, $examType, $paper
);
// ── END AUDIT pre-capture ─────────────────────────────────────────────────────

$sql = "DELETE FROM `{$tableName}`
        WHERE student_id = ?
          AND class      = ?
          AND term       = ?
          AND year       = ?
          AND subject    = ?
          AND exam_type  = ?
          AND paper      = ?";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    error_log("[SchoolPilot:delete_mark] Prepare failed for '{$tableName}': " . mysqli_error($conn));
    send(false, 'Database error. Please try again.');
}

// sssssss — 7 strings
mysqli_stmt_bind_param($stmt, 'sssssss',
    $studentId, $class, $term, $year, $subject, $examType, $paper
);

if (!mysqli_stmt_execute($stmt)) {
    error_log("[SchoolPilot:delete_mark] Execute failed: " . mysqli_stmt_error($stmt));
    mysqli_stmt_close($stmt);
    send(false, 'Failed to delete mark. Please try again.');
}

$affected = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);

// ── AUDIT: Log only when a row was actually removed ───────────────────────────
if ($affected > 0 && $auditOldMark !== null) {
    logMarkAudit(
        $conn,
        'DELETE',
        $auditUser,
        $studentId,
        $class,
        $term,
        $year,
        $subject,
        $examType,
        $paper,
        $auditOldMark,  // the mark that was just deleted
        null            // no new value — the record is gone
    );
}
// ── END AUDIT ─────────────────────────────────────────────────────────────────

send(true, $affected > 0 ? 'Mark deleted successfully.' : 'No mark found to delete.');
