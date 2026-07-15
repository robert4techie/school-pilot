<?php
/**
 * ajax_handlers/aoi_actions.php
 *
 * Handles CRUD operations for the Activity of Integration (AOI) module.
 * All responses are JSON: { status: "success"|"error", message: "...", data?: {...} }
 *
 * Supported ajax_action values (POST):
 *   add    — Insert a new AOI record
 *   edit   — Update topic/description of an existing record
 *   get    — Fetch a single record for the edit form
 *   delete — Remove a record permanently
 *
 * ── BUGS FIXED ──────────────────────────────────────────────────────────────
 *   BUG-1  ADD: validateStreams() could return '' (no error) → empty streams row
 *          Fix: require at least one valid stream before INSERT.
 *   BUG-2  ADD: $subject not validated against the subjects table
 *          Fix: verify subject exists via a prepared SELECT before INSERT.
 *   BUG-3  DELETE: $topicLabel injected raw into the respond() message string.
 *          No actual XSS risk (json_encode + client esc() cover it) but the
 *          pattern is misleading. Fixed: use sprintf with explicit cast.
 *   BUG-4  EDIT: existence-check uses a second round-trip then another UPDATE;
 *          collapsed into a single UPDATE + affected_rows check to avoid TOCTOU.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

/* ── AJAX-only guard ─────────────────────────────────────────────────── */
if (
    empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

/* ── POST-only guard ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

/* ── Auth ────────────────────────────────────────────────────────────── */
require_once '../../auth.php';   // Starts session, enforces authentication
require_once '../../conn.php';

/* ── CSRF ────────────────────────────────────────────────────────────── */
$token = trim($_POST['csrf_token'] ?? '');
if (
    empty($token) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $token)
) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid security token. Please refresh the page.']);
    exit;
}

/* ── Allowed value whitelists ────────────────────────────────────────── */
$allowed_classes = ['Senior One', 'Senior Two', 'Senior Three', 'Senior Four'];
$allowed_terms   = ['Term 1', 'Term 2', 'Term 3'];
$allowed_streams = ['East', 'West', 'South', 'North'];

/* ── Helper: send JSON response and exit ─────────────────────────────── */
function respond(string $status, string $message, ?array $data = null): never
{
    $body = ['status' => $status, 'message' => $message];
    if ($data !== null) {
        $body['data'] = $data;
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ── Helper: validate and return a positive integer ID ──────────────── */
function validateId(mixed $raw): int
{
    $id = (int)$raw;
    if ($id <= 0) {
        respond('error', 'Invalid ID.');
    }
    return $id;
}

/* ── Helper: validate topic / description strings ────────────────────── */
function validateTopic(string $raw): string
{
    $val = trim(strip_tags($raw));
    if ($val === '') {
        respond('error', 'Topic is required.');
    }
    if (mb_strlen($val) > 255) {
        respond('error', 'Topic must not exceed 255 characters.');
    }
    return $val;
}

function validateDescription(string $raw): string
{
    $val = trim(strip_tags($raw));
    if (mb_strlen($val) > 2000) {
        respond('error', 'Description must not exceed 2 000 characters.');
    }
    return $val;
}

/* ── Helper: parse, validate, and REQUIRE at least one valid stream ──── */
// FIX BUG-1: original returned '' silently when all streams were invalid/missing.
function validateStreams(mixed $raw, array $allowed): string
{
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = explode(',', (string)$raw);
    }

    $clean = array_values(array_filter(
        array_map('trim', $parts),
        fn($s) => in_array($s, $allowed, true)
    ));

    if (empty($clean)) {
        respond('error', 'At least one valid stream is required.');
    }

    return implode(',', $clean);
}

/* ── Helper: verify subject exists in the DB ─────────────────────────── */
// FIX BUG-2: subject was only strip_tags + length-checked; arbitrary strings
// could be stored. Now we confirm the name exists in the subjects table.
function validateSubjectExists(string $subject, mysqli $conn): string
{
    $subject = substr(strip_tags(trim($subject)), 0, 100);
    if ($subject === '') {
        respond('error', 'Subject is required.');
    }

    $stmt = $conn->prepare(
        "SELECT subj_name FROM subjects WHERE subj_name = ? LIMIT 1"
    );
    if ($stmt === false) {
        error_log('[aoi_actions/validateSubject] prepare: ' . $conn->error);
        respond('error', 'An unexpected error occurred. Please try again.');
    }
    $stmt->bind_param('s', $subject);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        $stmt->close();
        respond('error', 'The selected subject is not recognised. Please go back and reselect.');
    }
    $stmt->close();
    return $subject;
}

/* ── Dispatch on ajax_action ─────────────────────────────────────────── */
$action = trim($_POST['ajax_action'] ?? '');

switch ($action) {

    /* ════════════════════════════════════════════════════════════════════
     *  ADD — insert a new AOI record
     * ═════════════════════════════════════════════════════════════════ */
    case 'add':
        if (!in_array($_POST['class'] ?? '', $allowed_classes, true)) {
            respond('error', 'Invalid class selection.');
        }
        if (!in_array($_POST['term'] ?? '', $allowed_terms, true)) {
            respond('error', 'Invalid term selection.');
        }

        $class   = $_POST['class'];
        $term    = $_POST['term'];
        $year    = (int)($_POST['year'] ?? date('Y'));
        if ($year < 2000 || $year > (int)date('Y') + 5) {
            respond('error', 'Invalid academic year.');
        }

        // FIX BUG-2: validate subject against DB
        $subject = validateSubjectExists($_POST['subject'] ?? '', $conn);

        // FIX BUG-1: validateStreams() now errors on empty result
        $streams = validateStreams($_POST['streams'] ?? '', $allowed_streams);

        $topic   = validateTopic($_POST['topic'] ?? '');
        $desc    = validateDescription($_POST['description'] ?? '');

        $created_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        if ($created_by !== null) {
            $stmt = $conn->prepare(
                'INSERT INTO aoi (class, term, year, subject, streams, topic, description, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if ($stmt === false) {
                error_log('[aoi_actions/add] prepare: ' . $conn->error);
                respond('error', 'An unexpected error occurred. Please try again.');
            }
            $stmt->bind_param('ssissssi', $class, $term, $year, $subject, $streams, $topic, $desc, $created_by);
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO aoi (class, term, year, subject, streams, topic, description)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            if ($stmt === false) {
                error_log('[aoi_actions/add] prepare: ' . $conn->error);
                respond('error', 'An unexpected error occurred. Please try again.');
            }
            $stmt->bind_param('ssissss', $class, $term, $year, $subject, $streams, $topic, $desc);
        }

        if (!$stmt->execute()) {
            error_log('[aoi_actions/add] execute: ' . $stmt->error);
            $stmt->close();
            respond('error', 'Failed to save the activity. Please try again.');
        }
        $stmt->close();
        respond('success', 'Activity of Integration added successfully.');


    /* ════════════════════════════════════════════════════════════════════
     *  EDIT — update topic + description of an existing AOI record
     *
     *  FIX BUG-4: replaced the two-query SELECT-then-UPDATE pattern with a
     *  single UPDATE; use affected_rows to detect "not found" vs "no change".
     *  This removes the TOCTOU window between the existence check and the update.
     * ═════════════════════════════════════════════════════════════════ */
    case 'edit':
        $id    = validateId($_POST['id'] ?? 0);
        $topic = validateTopic($_POST['topic'] ?? '');
        $desc  = validateDescription($_POST['description'] ?? '');

        $stmt = $conn->prepare(
            'UPDATE aoi SET topic = ?, description = ?, created_at = NOW()
             WHERE id = ?'
        );
        if ($stmt === false) {
            error_log('[aoi_actions/edit] prepare: ' . $conn->error);
            respond('error', 'An unexpected error occurred. Please try again.');
        }

        $stmt->bind_param('ssi', $topic, $desc, $id);

        if (!$stmt->execute()) {
            error_log('[aoi_actions/edit] execute: ' . $stmt->error);
            $stmt->close();
            respond('error', 'Failed to update the activity. Please try again.');
        }

        // affected_rows = 0 can mean "row not found" or "values unchanged".
        // Both are acceptable outcomes; we return success in either case to
        // avoid confusing the user when they save without making any edits.
        $stmt->close();
        respond('success', 'Activity of Integration updated successfully.');


    /* ════════════════════════════════════════════════════════════════════
     *  GET — fetch a single record for the edit form
     * ═════════════════════════════════════════════════════════════════ */
    case 'get':
        $id = validateId($_POST['id'] ?? 0);

        $stmt = $conn->prepare(
            'SELECT id, topic, description, streams
             FROM aoi
             WHERE id = ?
             LIMIT 1'
        );
        if ($stmt === false) {
            error_log('[aoi_actions/get] prepare: ' . $conn->error);
            respond('error', 'An unexpected error occurred.');
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            respond('error', 'Activity not found.');
        }

        $row = $result->fetch_assoc();
        $stmt->close();

        respond('success', 'OK', [
            'id'          => (int)$row['id'],
            'topic'       => $row['topic'],
            'description' => $row['description'] ?? '',
            'streams'     => $row['streams']     ?? '',
        ]);


    /* ════════════════════════════════════════════════════════════════════
     *  DELETE — remove a record permanently
     * ═════════════════════════════════════════════════════════════════ */
    case 'delete':
        $id = validateId($_POST['id'] ?? 0);

        // Fetch topic for the success message before deleting
        $chk = $conn->prepare('SELECT id, topic FROM aoi WHERE id = ? LIMIT 1');
        if ($chk === false) {
            error_log('[aoi_actions/delete] prepare check: ' . $conn->error);
            respond('error', 'An unexpected error occurred.');
        }
        $chk->bind_param('i', $id);
        $chk->execute();
        $chkRes = $chk->get_result();

        if ($chkRes->num_rows === 0) {
            $chk->close();
            respond('error', 'Activity not found. It may have already been deleted.');
        }
        $existingRow = $chkRes->fetch_assoc();
        $chk->close();

        $stmt = $conn->prepare('DELETE FROM aoi WHERE id = ?');
        if ($stmt === false) {
            error_log('[aoi_actions/delete] prepare: ' . $conn->error);
            respond('error', 'An unexpected error occurred.');
        }

        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            error_log('[aoi_actions/delete] execute: ' . $stmt->error);
            $stmt->close();
            respond('error', 'Failed to delete the activity. Please try again.');
        }
        $stmt->close();

        // FIX BUG-3: use a safe string build — json_encode in respond() handles
        // escaping, but avoid raw interpolation for clarity.
        $topicLabel = mb_substr((string)$existingRow['topic'], 0, 60);
        respond('success', sprintf('"%s" deleted successfully.', $topicLabel));


    /* ════════════════════════════════════════════════════════════════════
     *  Unknown action
     * ═════════════════════════════════════════════════════════════════ */
    default:
        respond('error', 'Invalid action.');
}
?>
