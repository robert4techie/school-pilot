<?php
declare(strict_types=1);

/**
 * audit_log_helper.php
 * SchoolPilot — A-Level Marks Audit Log: shared helper functions.
 *
 * Include this file from save_mark_ajax.php, delete_mark_ajax.php,
 * and view_marks_audit.php.
 *
 * Provides:
 *   ensureAuditTable()   — idempotent CREATE TABLE IF NOT EXISTS
 *   fetchExistingMark()  — read the current mark before a change
 *   logMarkAudit()       — write one audit entry; never throws
 */

// ── Table bootstrap ───────────────────────────────────────────────────────────

/**
 * Create marks_audit_log if it does not yet exist.
 * Uses a static flag so CREATE IF NOT EXISTS only runs once per PHP request,
 * no matter how many times logMarkAudit() is called.
 */
function ensureAuditTable(mysqli $db): bool
{
    static $done = false;
    if ($done) return true;

    $sql = "CREATE TABLE IF NOT EXISTS `marks_audit_log` (
        `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `action`       ENUM('INSERT','UPDATE','DELETE')
                                        NOT NULL COMMENT 'Type of change made',
        `performed_by` VARCHAR(100)     NOT NULL COMMENT 'Username from session (user_name)',
        `student_id`   VARCHAR(50)      NOT NULL,
        `class`        VARCHAR(50)      NOT NULL,
        `term`         VARCHAR(20)      NOT NULL,
        `year`         CHAR(4)          NOT NULL,
        `subject`      VARCHAR(100)     NOT NULL,
        `exam_type`    VARCHAR(50)      NOT NULL COMMENT 'exam_sets.id value',
        `paper`        VARCHAR(10)      NOT NULL,
        `old_mark`     TINYINT UNSIGNED NULL     DEFAULT NULL COMMENT 'NULL for brand-new inserts',
        `new_mark`     TINYINT UNSIGNED NULL     DEFAULT NULL COMMENT 'NULL for deletes',
        `performed_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_student`      (`student_id`),
        INDEX `idx_performed_by` (`performed_by`),
        INDEX `idx_performed_at` (`performed_at`),
        INDEX `idx_context`      (`class`, `subject`, `term`, `year`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
      COMMENT='Immutable audit trail of all A-level mark changes'";

    // Use utf8mb4_general_ci to match the rest of the SchoolPilot database.
    // If the table was previously created with utf8mb4_unicode_ci, convert it now
    // so that JOINs against students and exam_sets don't throw collation errors.
    $sql = str_replace('utf8mb4_unicode_ci', 'utf8mb4_general_ci', $sql);

    $ok = mysqli_query($db, $sql) !== false;
    if (!$ok) {
        error_log('[SchoolPilot:audit] ensureAuditTable failed: ' . mysqli_error($db));
        return false;
    }

    // Silently fix collation on any table that already existed with the wrong setting.
    // ALTER TABLE ... CONVERT is idempotent — safe to run even when already correct.
    mysqli_query($db,
        "ALTER TABLE `marks_audit_log`
         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
    );

    $done = true;
    return true;
}

// ── Read helper ───────────────────────────────────────────────────────────────

/**
 * Return the stored mark for a specific student/paper slot, or null if the
 * row does not exist yet.  Call this BEFORE the upsert or delete so the
 * audit entry can capture the old value.
 *
 * @param string $tableName  Already-validated name (e.g. "2025_i_alevel").
 *                           The caller must confirm the table exists before calling.
 */
function fetchExistingMark(
    mysqli $db,
    string $tableName,
    string $studentId,
    string $class,
    string $term,
    string $year,
    string $subject,
    string $examType,
    string $paper
): ?int {
    $sql  = "SELECT mark FROM `{$tableName}`
             WHERE student_id = ?
               AND class      = ?
               AND term       = ?
               AND year       = ?
               AND subject    = ?
               AND exam_type  = ?
               AND paper      = ?
             LIMIT 1";
    $stmt = mysqli_prepare($db, $sql);
    if (!$stmt) return null;

    mysqli_stmt_bind_param($stmt, 'sssssss',
        $studentId, $class, $term, $year, $subject, $examType, $paper
    );
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row    = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return ($row !== false && $row !== null) ? (int) $row['mark'] : null;
}

// ── Write helper ──────────────────────────────────────────────────────────────

/**
 * Append one row to marks_audit_log.
 *
 * Deliberately non-fatal: if the INSERT fails (e.g. disk full), the error
 * is written to the PHP error log but the calling endpoint continues normally
 * so the mark operation itself is never blocked by audit infrastructure.
 *
 * @param string   $action       'INSERT' | 'UPDATE' | 'DELETE'
 * @param string   $performedBy  Value of $_SESSION['user_name']
 * @param int|null $oldMark      Previous mark; null for brand-new inserts
 * @param int|null $newMark      New mark; null for deletes
 */
function logMarkAudit(
    mysqli  $db,
    string  $action,
    string  $performedBy,
    string  $studentId,
    string  $class,
    string  $term,
    string  $year,
    string  $subject,
    string  $examType,
    string  $paper,
    ?int    $oldMark,
    ?int    $newMark
): void {
    ensureAuditTable($db);

    $sql  = "INSERT INTO `marks_audit_log`
                 (action, performed_by, student_id, class, term, year,
                  subject, exam_type, paper, old_mark, new_mark)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = mysqli_prepare($db, $sql);
    if (!$stmt) {
        error_log('[SchoolPilot:audit] Prepare failed: ' . mysqli_error($db));
        return;
    }

    // 's' for nullable integer columns — MySQLi maps PHP null → SQL NULL
    // regardless of type hint, but 's' is the most reliable cross-version choice.
    mysqli_stmt_bind_param($stmt, 'sssssssssss',
        $action, $performedBy, $studentId, $class, $term, $year,
        $subject, $examType, $paper, $oldMark, $newMark
    );

    if (!mysqli_stmt_execute($stmt)) {
        error_log('[SchoolPilot:audit] Execute failed: ' . mysqli_stmt_error($stmt));
    }

    mysqli_stmt_close($stmt);
}