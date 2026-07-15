<?php
declare(strict_types=1);

/**
 * save_mark_ajax.php
 * SchoolPilot — A-Level Marks: AJAX save endpoint.
 *
 * Security:  authenticated session, CSRF token, full input validation,
 *            parameterised queries, safe dynamic table names.
 * Protocol:  POST-only, JSON response.
 *
 * Audit:     Every successful save is written to marks_audit_log.
 *            Action is 'INSERT' when a mark is entered for the first time,
 *            'UPDATE' when an existing mark is changed.
 *            Old and new mark values are both recorded.
 */

ob_start();

require_once '../auth.php';           // Session / login gate
require_once '../conn.php';           // $conn (mysqli)
require_once 'audit_log_helper.php'; // ← AUDIT: ensureAuditTable / fetchExistingMark / logMarkAudit

// ── Always JSON ───────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache');

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Emit a JSON response and terminate.
 */
function send(bool $ok, string $msg): never
{
    ob_clean();
    echo json_encode(['success' => $ok, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Constant-time CSRF comparison.
 */
function csrfValid(string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && !empty($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Map a human-readable term string to its roman-numeral slug.
 * Returns false for unknown terms.
 */
function termSlug(string $term): string|false
{
    $t = strtolower(trim(preg_replace('/[^a-z0-9]/i', '', $term)));
    if (str_contains($term, '1') || $t === 'i'   || $t === '1') return 'i';
    if (str_contains($term, '2') || $t === 'ii'  || $t === '2') return 'ii';
    if (str_contains($term, '3') || $t === 'iii' || $t === '3') return 'iii';
    return false;
}

/**
 * Build and validate a safe table name. Pattern: YYYY_(i|ii|iii)_alevel
 */
function buildTableName(string $year, string $term): string|false
{
    if (!preg_match('/^\d{4}$/', $year)) return false;
    $slug = termSlug($term);
    if ($slug === false) return false;
    return "{$year}_{$slug}_alevel";
}

/**
 * Check table existence via information_schema (uses a prepared statement).
 */
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

/**
 * Create the marks table if it does not yet exist.
 * The table name is pre-validated before this function is called.
 */
function ensureTable(mysqli $db, string $name): bool
{
    if (tableExists($db, $name)) return true;

    $sql = "CREATE TABLE `{$name}` (
        `id`         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        `student_id` VARCHAR(50)      NOT NULL,
        `class`      VARCHAR(50)      NOT NULL,
        `stream`     VARCHAR(50)      NOT NULL DEFAULT '',
        `term`       VARCHAR(20)      NOT NULL,
        `year`       CHAR(4)          NOT NULL,
        `subject`    VARCHAR(100)     NOT NULL,
        `exam_type`  VARCHAR(50)      NOT NULL,
        `paper`      VARCHAR(10)      NOT NULL,
        `mark`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_mark` (`student_id`, `class`, `term`, `year`, `subject`, `exam_type`, `paper`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    return mysqli_query($db, $sql) !== false;
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
$mark      = trim($_POST['mark']       ?? '');
$stream    = trim($_POST['stream']     ?? '');
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

// Mark range: 0–100, integers only (empty mark is handled by delete endpoint)
if ($mark !== '' && !preg_match('/^(100|[1-9]\d|\d)$/', $mark)) {
    send(false, 'Mark must be a whole number between 0 and 100.');
}

// Student ID: alphanumeric, dashes, underscores — max 50 chars
if (!preg_match('/^[\w\-]{1,50}$/u', $studentId)) {
    send(false, 'Invalid student ID format.');
}

// Paper: roman numerals (I–VI) or Pn format
if (!preg_match('/^[IVXivx]{1,6}$/', $paper) && !preg_match('/^P[1-9]$/', $paper)) {
    send(false, 'Invalid paper identifier.');
}

// Build & validate table name
$tableName = buildTableName($year, $term);
if ($tableName === false) {
    send(false, 'Invalid year or term value.');
}

// ── AUDIT: Capture old state before the upsert ────────────────────────────────
// Read the existing mark (if any) so we can record old→new in the audit log.
// $auditOldMark is null  → this will be a fresh INSERT
// $auditOldMark is int   → this will be an UPDATE of an existing mark
$auditUser    = $_SESSION['user_name'] ?? 'unknown';
$auditOldMark = null;
$auditAction  = 'INSERT';

if (tableExists($conn, $tableName)) {
    $auditOldMark = fetchExistingMark(
        $conn, $tableName, $studentId, $class, $term, $year, $subject, $examType, $paper
    );
    if ($auditOldMark !== null) {
        $auditAction = 'UPDATE';
    }
}
// ── END AUDIT pre-capture ─────────────────────────────────────────────────────

// ── Database Operations ───────────────────────────────────────────────────────

if (!ensureTable($conn, $tableName)) {
    error_log("[SchoolPilot:save_mark] Failed to create table '{$tableName}': " . mysqli_error($conn));
    send(false, 'Database error. Please contact your system administrator.');
}

$markInt = (int) $mark;

/*
 * UPSERT — avoids the race condition between a check-then-insert pattern.
 * If the unique key (student_id, class, term, year, subject, exam_type, paper)
 * already exists, we simply update mark + stream.
 */
$sql = "INSERT INTO `{$tableName}`
            (student_id, class, stream, term, year, subject, exam_type, paper, mark)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            mark   = VALUES(mark),
            stream = VALUES(stream)";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    error_log("[SchoolPilot:save_mark] Prepare failed for '{$tableName}': " . mysqli_error($conn));
    send(false, 'Database error. Please try again.');
}

// ssssssssi — 8 strings + 1 integer
mysqli_stmt_bind_param($stmt, 'ssssssssi',
    $studentId, $class, $stream, $term, $year, $subject, $examType, $paper, $markInt
);

if (!mysqli_stmt_execute($stmt)) {
    error_log("[SchoolPilot:save_mark] Execute failed: " . mysqli_stmt_error($stmt));
    mysqli_stmt_close($stmt);
    send(false, 'Failed to save mark. Please try again.');
}

mysqli_stmt_close($stmt);

// ── AUDIT: Log the successful change ─────────────────────────────────────────
logMarkAudit(
    $conn,
    $auditAction,
    $auditUser,
    $studentId,
    $class,
    $term,
    $year,
    $subject,
    $examType,
    $paper,
    $auditOldMark,   // null for new inserts, int for updates
    $markInt         // the value just written
);
// ── END AUDIT ─────────────────────────────────────────────────────────────────

send(true, 'Mark saved successfully.');