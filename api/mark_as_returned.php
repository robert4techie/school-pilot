<?php
require_once '../auth.php';
require_once '../conn.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Read JSON body ──────────────────────────────────────────────────────────
// JavaScript sends fetch() with Content-Type: application/json, so PHP's $_POST
// is always empty. The body must be read and decoded from php://input.
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request body.']);
    exit;
}

// ── CSRF validation ─────────────────────────────────────────────────────────
if (
    empty($input['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'] ?? '', $input['csrf_token'])
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing CSRF token.']);
    exit;
}

// ── Validate ID ─────────────────────────────────────────────────────────────
if (empty($input['id']) || !ctype_digit((string)$input['id'])) {
    echo json_encode(['success' => false, 'error' => 'A valid Gate Pass ID is required.']);
    exit;
}

$id          = (int)$input['id'];
$currentTime = date('Y-m-d H:i:s');

// Only update passes that are currently 'issued' or 'overdue' — prevents accidentally
// overwriting actual_return on a pass that was already marked returned.
$stmt = mysqli_prepare(
    $conn,
    "UPDATE gate_passes
     SET status = 'returned', actual_return = ?, returned_at = ?
     WHERE id = ? AND status IN ('issued', 'overdue')"
);

if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Database prepare failed: ' . mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param($stmt, 'ssi', $currentTime, $currentTime, $id);

if (!mysqli_stmt_execute($stmt)) {
    echo json_encode(['success' => false, 'error' => 'Failed to update gate pass: ' . mysqli_stmt_error($stmt)]);
    mysqli_stmt_close($stmt);
    exit;
}

$affected = mysqli_stmt_affected_rows($stmt);
mysqli_stmt_close($stmt);
mysqli_close($conn);

if ($affected > 0) {
    echo json_encode(['success' => true, 'message' => 'Gate pass marked as returned.']);
} else {
    echo json_encode(['success' => false, 'error' => 'Gate pass not found, already returned, or cancelled.']);
}
