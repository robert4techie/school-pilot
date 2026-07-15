<?php
// api/save_class_teacher.php
declare(strict_types=1);
header('Content-Type: application/json');
require_once '../auth.php';
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
    $staffId   = trim((string) ($data['staff_id']   ?? ''));
    $className = trim((string) ($data['class_name'] ?? ''));
    $stream    = trim((string) ($data['stream']     ?? ''));

    $allowedClasses = ['Senior one','Senior Two','Senior Three','Senior Four','Senior Five','Senior Six'];
    $allowedStreams  = ['East','West','South','North','Arts','Sciences','All Streams'];
    if ($staffId === '' || strlen($staffId) > 50) {
        throw new Exception('Missing or invalid staff ID.');
    }
    if (!in_array($className, $allowedClasses, true)) {
        throw new Exception('Invalid class name.');
    }
    if (!in_array($stream, $allowedStreams, true)) {
        throw new Exception('Invalid stream name.');
    }

    $academicYear = (string) date('Y');

    // ── Check Existing Record ─────────────────────────────
    $stmt = $conn->prepare(
        "SELECT id, staff_id FROM class_teachers
         WHERE class_name = ? AND stream = ? AND academic_year = ?"
    );
    if (!$stmt) throw new Exception('Database error (prepare check).');
    $stmt->bind_param('sss', $className, $stream, $academicYear);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = $result->fetch_assoc();
    $stmt->close();

    if ($existing) {
        if ($existing['staff_id'] !== $staffId) {
            // Update existing record to new staff member
            $stmt = $conn->prepare(
                "UPDATE class_teachers SET staff_id = ?
                 WHERE class_name = ? AND stream = ? AND academic_year = ?"
            );
            if (!$stmt) throw new Exception('Database error (prepare update).');
            $stmt->bind_param('ssss', $staffId, $className, $stream, $academicYear);
            if (!$stmt->execute()) throw new Exception('Failed to update class teacher assignment.');
            $stmt->close();
        }
        // Same staff already assigned — nothing to do
    } else {
        // Insert new record
        $stmt = $conn->prepare(
            "INSERT INTO class_teachers (staff_id, class_name, stream, academic_year)
             VALUES (?, ?, ?, ?)"
        );
        if (!$stmt) throw new Exception('Database error (prepare insert).');
        $stmt->bind_param('ssss', $staffId, $className, $stream, $academicYear);
        if (!$stmt->execute()) throw new Exception('Failed to save class teacher assignment.');
        $stmt->close();
    }

    $response['success'] = true;
    $response['message'] = 'Class teacher assigned successfully.';

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log('[save_class_teacher] ' . $e->getMessage());
}

if (isset($conn)) $conn->close();
echo json_encode($response);
