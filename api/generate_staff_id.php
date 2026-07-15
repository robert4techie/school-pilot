<?php
/**
 * generate_staff_id.php
 * Returns a PREVIEW of the next sequential staff ID as JSON.
 *
 * ARCHITECTURE NOTE — why this is a preview only:
 *
 *   This endpoint exists only to show the user a likely ID while they fill
 *   the form. The authoritative ID is generated atomically INSIDE
 *   process_staff_registration.php's own transaction (FOR UPDATE → INSERT →
 *   COMMIT), so the lock is held until the row is written. The ID shown here
 *   may differ by 1 or more if a concurrent registration completes before
 *   this user submits — that is acceptable and expected.
 *
 * Pattern: OU-YYYY-STA-NNNN
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
    error_log('[generate_staff_id] DB connection failed: ' . ($conn->connect_error ?? 'null'));
    echo json_encode(['status' => 'error', 'message' => 'Database unavailable.']);
    exit;
}

/**
 * Returns a preview of what the next staff ID will likely be.
 * Pattern: OU-YYYY-STA-NNNN
 *
 * @throws RuntimeException on DB error or sequence overflow
 */
function previewNextStaffId(mysqli $conn): string
{
    $currentYear  = date('Y');
    $basePattern  = "OU-{$currentYear}-STA";
    $likePattern  = "{$basePattern}-%";

    // Plain SELECT — no transaction or lock needed for a preview read.
    $stmt = $conn->prepare(
        "SELECT staff_id
           FROM staff
          WHERE staff_id LIKE ?
          ORDER BY CAST(SUBSTRING_INDEX(staff_id, '-', -1) AS UNSIGNED) DESC
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
        $number = (int) substr($row['staff_id'], -4) + 1;
    }

    $stmt->close();

    if ($number > 9999) {
        throw new RuntimeException(
            "Staff ID sequence exhausted for year {$currentYear}. Contact the administrator."
        );
    }

    return "{$basePattern}-" . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
}

// ── Main ─────────────────────────────────────────────────────────────────────
try {
    $previewId = previewNextStaffId($conn);
    echo json_encode([
        'status'  => 'success',
        'staffId' => $previewId,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[generate_staff_id] ' . $e->getMessage());
    echo json_encode([
        'status'  => 'error',
        'message' => 'Could not generate a Staff ID. Please try again.',
    ]);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
