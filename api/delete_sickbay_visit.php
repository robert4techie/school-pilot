<?php
/**
 * api/delete_visit.php
 * DELETE a single sick bay visit (and its linked medications via FK cascade).
 * Expects POST: visit_id (int)
 */

require_once '../auth.php';
require_once '../conn.php';

header('Content-Type: application/json; charset=utf-8');

// ── Auth guard ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorised.']);
    exit;
}

// ── CSRF guard (if your system uses a token) ──────────────────────────────────
// Uncomment if you have CSRF tokens on other endpoints:
// if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
//     http_response_code(403);
//     echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
//     exit;
// }

// ── Input validation ──────────────────────────────────────────────────────────
$visit_id = (int) ($_POST['visit_id'] ?? 0);
if ($visit_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid visit ID.']);
    exit;
}

// ── Confirm record exists before deleting ─────────────────────────────────────
$check = $conn->prepare("SELECT visit_id FROM sick_bay_visits WHERE visit_id = ? LIMIT 1");
$check->bind_param('i', $visit_id);
$check->execute();
$check->store_result();

if ($check->num_rows === 0) {
    $check->close();
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Visit record not found.']);
    exit;
}
$check->close();

// ── Delete (medications removed via ON DELETE CASCADE on FK) ──────────────────
$stmt = $conn->prepare("DELETE FROM sick_bay_visits WHERE visit_id = ?");
$stmt->bind_param('i', $visit_id);

if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit;
}

$affected = $stmt->affected_rows;
$stmt->close();

if ($affected === 0) {
    echo json_encode(['success' => false, 'message' => 'No record was deleted.']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Visit deleted.']);