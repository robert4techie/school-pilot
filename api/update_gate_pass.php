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

// ── Validate required fields ────────────────────────────────────────────────
// Note: issued_by is intentionally excluded — the edit form does not allow
// changing who issued the pass; that field is set at creation time only.
$requiredFields = ['id', 'departure_time', 'expected_return', 'destination', 'reason', 'priority', 'status'];

foreach ($requiredFields as $field) {
    if (empty($input[$field])) {
        echo json_encode(['success' => false, 'error' => "Field '{$field}' is required."]);
        exit;
    }
}

// ── Validate enums ──────────────────────────────────────────────────────────
$validPriorities = ['normal', 'urgent', 'emergency'];
$validStatuses   = ['issued', 'returned', 'overdue', 'cancelled'];

if (!in_array($input['priority'], $validPriorities, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid priority value.']);
    exit;
}

if (!in_array($input['status'], $validStatuses, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status value.']);
    exit;
}

// ── Validate ID ─────────────────────────────────────────────────────────────
if (!ctype_digit((string)$input['id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid Gate Pass ID.']);
    exit;
}

// ── Assign variables (no manual escaping — prepared statements handle this) ─
$id                 = (int)$input['id'];
$departureTime      = trim($input['departure_time']);
$expectedReturn     = trim($input['expected_return']);
$destination        = trim($input['destination']);
$reason             = trim($input['reason']);
$priority           = trim($input['priority']);
$status             = trim($input['status']);
$parentContact      = isset($input['parent_contact'])      ? trim($input['parent_contact'])      : null;
$studentContact     = isset($input['student_contact'])     ? trim($input['student_contact'])     : null;
$accompanyingPerson = isset($input['accompanying_person']) ? trim($input['accompanying_person']) : null;

// ── Update ──────────────────────────────────────────────────────────────────
$sql = "UPDATE gate_passes SET
            departure_time      = ?,
            expected_return     = ?,
            destination         = ?,
            reason              = ?,
            priority            = ?,
            status              = ?,
            parent_contact      = ?,
            student_contact     = ?,
            accompanying_person = ?
        WHERE id = ?";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Database prepare failed: ' . mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param(
    $stmt, 'sssssssssi',
    $departureTime,
    $expectedReturn,
    $destination,
    $reason,
    $priority,
    $status,
    $parentContact,
    $studentContact,
    $accompanyingPerson,
    $id
);

if (!mysqli_stmt_execute($stmt)) {
    echo json_encode(['success' => false, 'error' => 'Failed to update gate pass: ' . mysqli_stmt_error($stmt)]);
    mysqli_stmt_close($stmt);
    exit;
}

// affected_rows of 0 means values were unchanged but the record exists — still a success
mysqli_stmt_close($stmt);
mysqli_close($conn);

echo json_encode(['success' => true, 'message' => 'Gate pass updated successfully.']);
