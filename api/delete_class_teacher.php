<?php
// api/delete_class_teacher.php
declare(strict_types=1);
header('Content-Type: application/json');
require_once '../auth.php';   // Fixed: was 'auth.php' (wrong path for an api/ subdirectory file)
require_once '../conn.php';

$response = ['success' => false, 'message' => 'An unknown error occurred.'];

try {
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
    $className = trim((string) ($data['class_name'] ?? ''));
    $stream    = trim((string) ($data['stream']     ?? ''));

    $allowedClasses = ['Senior one','Senior Two','Senior Three','Senior Four','Senior Five','Senior Six'];
    $allowedStreams  = ['East','West','South','North','Arts','Sciences','All Streams'];

    if (!in_array($className, $allowedClasses, true)) {
        throw new Exception('Invalid class name.');
    }
    if (!in_array($stream, $allowedStreams, true)) {
        throw new Exception('Invalid stream name.');
    }

    $academicYear = (string) date('Y');

    // ── Delete Record ─────────────────────────────────────
    $stmt = $conn->prepare(
        "DELETE FROM class_teachers
         WHERE class_name = ? AND stream = ? AND academic_year = ?"
    );
    if (!$stmt) throw new Exception('Database error (prepare).');
    $stmt->bind_param('sss', $className, $stream, $academicYear);

    if (!$stmt->execute()) {
        throw new Exception('Failed to remove class teacher assignment.');
    }
    $stmt->close();

    $response['success'] = true;
    $response['message'] = 'Class teacher removed successfully.';

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log('[delete_class_teacher] ' . $e->getMessage());
}

if (isset($conn)) $conn->close();
echo json_encode($response);
