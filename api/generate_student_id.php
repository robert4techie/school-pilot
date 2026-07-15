<?php
/**
 * generate_student_id.php
 * Returns a PREVIEW of the next sequential student ID as JSON.
 *
 * ARCHITECTURE NOTE — why this is now a preview only:
 *
 *   The previous version used SELECT … FOR UPDATE inside a transaction but
 *   committed WITHOUT inserting anything. This released the lock immediately,
 *   leaving a TOCTOU window: two concurrent page-loads would both read the
 *   same "last ID", compute the same next ID, and submit two registrations
 *   with colliding IDs seconds later.
 *
 *   Fix: the authoritative ID is now generated atomically inside
 *   process_registration.php's own transaction (FOR UPDATE → INSERT → COMMIT),
 *   so the lock is held until the row is actually written. This endpoint
 *   exists only to show the user a likely ID while they fill the form.
 *   The ID shown here may differ by 1 or more if a concurrent registration
 *   completes before this user submits — that is acceptable.
 */

declare(strict_types=1);

require_once '../auth.php'; // starts session, validates user
require_once '../conn.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

if (!$conn || $conn->connect_error) {
    http_response_code(503);
    error_log('[generate_student_id] DB connection failed: ' . ($conn->connect_error ?? 'null'));
    echo json_encode(['status' => 'error', 'message' => 'Database unavailable.']);
    exit;
}

/**
 * Returns a preview of what the next student ID will likely be.
 * Pattern: OU-STD-YYYY-NNNN
 *
 * @throws RuntimeException on DB error or sequence overflow
 */
function previewNextStudentId(mysqli $conn): string
{
    $currentYear  = date('Y');
    $schoolPrefix = 'OU';
    $basePattern  = "{$schoolPrefix}-STD-{$currentYear}";
    $likePattern  = "{$basePattern}-%";

    // Plain SELECT — no transaction or lock needed for a preview read.
    $stmt = $conn->prepare(
        "SELECT student_id
           FROM students
          WHERE student_id LIKE ?
          ORDER BY CAST(SUBSTRING_INDEX(student_id, '-', -1) AS UNSIGNED) DESC
          LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('s', $likePattern);

    if (!$stmt->execute()) {
        throw new RuntimeException('Execute failed: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $number = 1;

    if ($result && $result->num_rows > 0) {
        $row    = $result->fetch_assoc();
        $number = (int) substr($row['student_id'], -4) + 1;
    }

    $stmt->close();

    if ($number > 9999) {
        throw new RuntimeException(
            "Student ID sequence exhausted for year {$currentYear}. Contact the administrator."
        );
    }

    return "{$basePattern}-" . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
}

// ── Main ─────────────────────────────────────────────────────────────────────
try {
    $previewId = previewNextStudentId($conn);
    echo json_encode([
        'status'    => 'success',
        'studentId' => $previewId,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[generate_student_id] ' . $e->getMessage());
    echo json_encode([
        'status'  => 'error',
        'message' => 'Could not generate a Student ID. Please try again.',
    ]);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}