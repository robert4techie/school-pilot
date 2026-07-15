<?php
// api/save_teacher.php
declare(strict_types=1);
header('Content-Type: application/json');
require_once '../auth.php';
require_once '../conn.php';

$response = ['success' => false, 'message' => 'An unknown error occurred.'];

try {
    // ── CSRF Validation ──────────────────────────────────
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON payload.');
    }

    $csrfToken = $data['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        throw new Exception('Invalid or expired security token. Please refresh the page.');
    }

    // ── Input Validation ─────────────────────────────────
    $staffId     = trim((string) ($data['staff_id'] ?? ''));
    $assignments = $data['assignments'] ?? [];

    if ($staffId === '' || strlen($staffId) > 50) {
        throw new Exception('Missing or invalid staff ID.');
    }
    if (!is_array($assignments)) {
        throw new Exception('Assignments must be an array.');
    }

    // Validate each assignment before touching the database
    $validAssignments = [];
    foreach ($assignments as $assignment) {
        $class  = trim((string) ($assignment['class']   ?? ''));
        $stream = trim((string) ($assignment['stream']  ?? ''));
        $subjIds = $assignment['subjects'] ?? [];

        $allowedClasses  = ['Senior one','Senior Two','Senior Three','Senior Four','Senior Five','Senior Six'];
        $allowedStreams   = ['East','West','North','South','Arts','Sciences','All Streams'];

        if (!in_array($class,  $allowedClasses, true))  throw new Exception("Invalid class value: {$class}");
        if (!in_array($stream, $allowedStreams,  true))  throw new Exception("Invalid stream value: {$stream}");
        if (!is_array($subjIds) || empty($subjIds))      continue; // skip empty, don't error

        // Validate each subject ID is a positive integer
        $cleanSubjIds = [];
        foreach ($subjIds as $sid) {
            $sid = filter_var($sid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($sid === false) throw new Exception('Invalid subject ID supplied.');
            $cleanSubjIds[] = $sid;
        }

        if (!empty($cleanSubjIds)) {
            $validAssignments[] = ['class' => $class, 'stream' => $stream, 'subjects' => $cleanSubjIds];
        }
    }

    // ── Database Transaction ─────────────────────────────
    $conn->begin_transaction();

    // Delete all existing assignments for this staff member
    $stmt = $conn->prepare("DELETE FROM teaching_assignments WHERE staff_id = ?");
    if (!$stmt) throw new Exception('Prepare failed (DELETE).');
    $stmt->bind_param('s', $staffId);
    $stmt->execute();
    $stmt->close();

    // Insert new assignments
    if (!empty($validAssignments)) {
        $stmt = $conn->prepare(
            "INSERT INTO teaching_assignments (staff_id, subject_id, class_name, stream_name) VALUES (?, ?, ?, ?)"
        );
        if (!$stmt) throw new Exception('Prepare failed (INSERT).');

        foreach ($validAssignments as $a) {
            foreach ($a['subjects'] as $subjectId) {
                $stmt->bind_param('siss', $staffId, $subjectId, $a['class'], $a['stream']);
                if (!$stmt->execute()) {
                    throw new Exception('Insert failed. The subject or class may not exist.');
                }
            }
        }
        $stmt->close();
    }

    $conn->commit();
    $response['success'] = true;
    $response['message'] = 'Assignments saved successfully.';

} catch (Exception $e) {
    if (isset($conn) && $conn->errno) {
        $conn->rollback();
    }
    $response['message'] = $e->getMessage();
    error_log('[save_teacher] ' . $e->getMessage());
}

if (isset($conn)) $conn->close();
echo json_encode($response);
