<?php
// api/delete_teacher.php
declare(strict_types=1);
header('Content-Type: application/json');
require_once '../auth.php';
require_once '../conn.php';

$response = ['success' => false, 'message' => 'An unknown error occurred.'];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Method not allowed.');
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON payload.');
    }

    // ── CSRF Validation ──────────────────────────────────
    $csrfToken = $data['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        throw new Exception('Invalid or expired security token. Please refresh the page.');
    }

    // ── Input Validation ─────────────────────────────────
    $staffId = trim((string) ($data['staff_id'] ?? ''));
    if ($staffId === '' || strlen($staffId) > 50) {
        throw new Exception('Missing or invalid staff ID.');
    }

    // ── Delete Assignments ────────────────────────────────
    $conn->begin_transaction();

    $stmt = $conn->prepare("DELETE FROM teaching_assignments WHERE staff_id = ?");
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt->bind_param('s', $staffId);

    if (!$stmt->execute()) {
        throw new Exception('Delete execution failed: ' . $stmt->error);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    $conn->commit();
    $response['success'] = true;
    $response['message'] = "Successfully removed {$affected} assignment(s).";

} catch (Exception $e) {
    if (isset($conn) && $conn->errno) {
        $conn->rollback();
    }
    $response['message'] = $e->getMessage();
    error_log('[delete_teacher] ' . $e->getMessage());
}

if (isset($conn)) $conn->close();
echo json_encode($response);
