<?php
/**
 * POST /delete_subject.php
 *
 * Deletes a subject record by ID.
 *
 * Required POST fields:
 *   csrf_token  string  Must match $_SESSION['csrf_token']
 *   id          int     Subject ID to delete
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once 'auth.php';   // Starts session + enforces authentication
require_once 'conn.php';

$response = [
    'success' => false,
    'message' => 'Failed to delete subject.',
];

try {
    /* ── Method guard ─────────────────────────────────────────────────── */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Method not allowed.');
    }

    /* ── CSRF validation ──────────────────────────────────────────────── */
    $token = trim($_POST['csrf_token'] ?? '');
    if (
        empty($token) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        throw new RuntimeException('Invalid security token. Please refresh the page and try again.');
    }

    /* ── Input validation ─────────────────────────────────────────────── */
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new InvalidArgumentException('Invalid subject ID.');
    }

    /* ── Existence check ──────────────────────────────────────────────── */
    $chk = $conn->prepare('SELECT subj_id, subj_name FROM subjects WHERE subj_id = ? LIMIT 1');
    if ($chk === false) {
        throw new RuntimeException('Query preparation failed.');
    }
    $chk->bind_param('i', $id);
    $chk->execute();
    $chkResult = $chk->get_result();

    if ($chkResult->num_rows === 0) {
        throw new RuntimeException('Subject not found. It may have already been deleted.');
    }
    $subject = $chkResult->fetch_assoc();
    $chk->close();

    /* ── Dependency check ─────────────────────────────────────────────── *
     * Uncomment and adapt the block below once you identify which tables  *
     * reference subjects (e.g. student_subjects, exam_subjects, marks).  *
     * Blocking a delete here prevents orphaned foreign-key rows.          *
     *                                                                      *
     *  $dep = $conn->prepare(                                             *
     *      'SELECT COUNT(*) AS cnt FROM student_subjects WHERE subj_id = ?'
     *  );                                                                  *
     *  $dep->bind_param('i', $id);                                         *
     *  $dep->execute();                                                     *
     *  $cnt = (int)$dep->get_result()->fetch_assoc()['cnt'];               *
     *  $dep->close();                                                       *
     *  if ($cnt > 0) {                                                      *
     *      throw new RuntimeException(                                      *
     *          "Cannot delete \"{$subject['subj_name']}\": it is assigned " *
     *          . "to {$cnt} student(s). Remove those assignments first."   *
     *      );                                                               *
     *  }                                                                    *
     * ─────────────────────────────────────────────────────────────────── */

    /* ── Delete ───────────────────────────────────────────────────────── */
    $del = $conn->prepare('DELETE FROM subjects WHERE subj_id = ?');
    if ($del === false) {
        throw new RuntimeException('Query preparation failed.');
    }
    $del->bind_param('i', $id);
    $del->execute();
    $del->close();

    $response = [
        'success' => true,
        'message' => "Subject \"{$subject['subj_name']}\" deleted successfully.",
    ];

} catch (InvalidArgumentException $e) {
    $response['message'] = $e->getMessage();
} catch (RuntimeException $e) {
    $response['message'] = $e->getMessage();
} catch (Throwable $e) {
    error_log('[delete_subject] ' . $e->getMessage());
    $response['message'] = 'An unexpected error occurred. Please try again.';
}

$conn->close();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
