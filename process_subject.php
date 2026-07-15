<?php
/**
 * POST /process_subject.php
 *
 * Creates or updates a subject record.
 *
 * Required POST fields:
 *   csrf_token  string  Must match $_SESSION['csrf_token']
 *   subj_abbr   string  Max 10 chars
 *   subj_name   string  Max 100 chars
 *   level       string  "O" | "A" | "O,A"
 *   code        string  Required when level includes "O"
 *   codea       string  Required when level includes "A"
 *   compulsory  int     1 | 0
 *   subj_id     int     Present for updates; empty/absent for inserts
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once 'auth.php';  // Starts session + enforces authentication
require_once 'conn.php';

$response = [
    'success' => false,
    'message' => 'An error occurred while processing your request.',
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

    /* ── Input collection ─────────────────────────────────────────────── */
    $subj_id    = (isset($_POST['subj_id']) && $_POST['subj_id'] !== '') ? (int)$_POST['subj_id'] : null;
    $subj_abbr  = trim($_POST['subj_abbr'] ?? '');
    $subj_name  = trim($_POST['subj_name'] ?? '');
    $level_raw  = trim($_POST['level'] ?? '');
    $code       = trim($_POST['code']  ?? '');
    $codea      = trim($_POST['codea'] ?? '');
    $compulsory = (isset($_POST['compulsory']) && (int)$_POST['compulsory'] === 1) ? 1 : 0;

    /* ── Field validation ─────────────────────────────────────────────── */
    if ($subj_abbr === '') {
        throw new InvalidArgumentException('Subject Short Code is required.');
    }
    if (mb_strlen($subj_abbr) > 10) {
        throw new InvalidArgumentException('Subject Short Code must not exceed 10 characters.');
    }

    if ($subj_name === '') {
        throw new InvalidArgumentException('Subject Name is required.');
    }
    if (mb_strlen($subj_name) > 100) {
        throw new InvalidArgumentException('Subject Name must not exceed 100 characters.');
    }

    // Whitelist-validate level to prevent injection / unexpected values
    $allowedLevels = ['O', 'A', 'O,A'];
    if (!in_array($level_raw, $allowedLevels, true)) {
        throw new InvalidArgumentException('Please select at least one valid level (O Level or A Level).');
    }

    $hasO = (str_contains($level_raw, 'O'));
    $hasA = (str_contains($level_raw, 'A'));

    if ($hasO) {
        if ($code === '') {
            throw new InvalidArgumentException('O Level Code is required when O Level is selected.');
        }
        if (mb_strlen($code) > 10) {
            throw new InvalidArgumentException('O Level Code must not exceed 10 characters.');
        }
    } else {
        // Clear O-Level fields if level doesn't include O
        $code       = '';
        $compulsory = 0;
    }

    if ($hasA) {
        if ($codea === '') {
            throw new InvalidArgumentException('A Level Code is required when A Level is selected.');
        }
        if (mb_strlen($codea) > 10) {
            throw new InvalidArgumentException('A Level Code must not exceed 10 characters.');
        }
    } else {
        $codea = '';
    }

    /* ── Duplicate abbreviation check ────────────────────────────────── */
    if ($subj_id === null) {
        $stmt = $conn->prepare('SELECT subj_id FROM subjects WHERE subj_abbr = ? LIMIT 1');
        if ($stmt === false) throw new RuntimeException('Query preparation failed.');
        $stmt->bind_param('s', $subj_abbr);
    } else {
        $stmt = $conn->prepare('SELECT subj_id FROM subjects WHERE subj_abbr = ? AND subj_id <> ? LIMIT 1');
        if ($stmt === false) throw new RuntimeException('Query preparation failed.');
        $stmt->bind_param('si', $subj_abbr, $subj_id);
    }

    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        throw new InvalidArgumentException('Subject Short Code already exists. Please use a different code.');
    }
    $stmt->close();

    /* ── Persist ──────────────────────────────────────────────────────── */
    if ($subj_id === null) {
        // INSERT
        $stmt = $conn->prepare(
            'INSERT INTO subjects (subj_abbr, subj_name, level, code, codea, compulsory)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) throw new RuntimeException('Query preparation failed.');
        $stmt->bind_param('sssssi', $subj_abbr, $subj_name, $level_raw, $code, $codea, $compulsory);
        $stmt->execute();
        $newId = $conn->insert_id;
        $stmt->close();

        $response = [
            'success'    => true,
            'message'    => 'Subject added successfully.',
            'subject_id' => $newId,
        ];
    } else {
        // Verify the record exists before updating
        $chk = $conn->prepare('SELECT subj_id FROM subjects WHERE subj_id = ? LIMIT 1');
        if ($chk === false) throw new RuntimeException('Query preparation failed.');
        $chk->bind_param('i', $subj_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            throw new RuntimeException('Subject not found. It may have been deleted.');
        }
        $chk->close();

        // UPDATE
        $stmt = $conn->prepare(
            'UPDATE subjects
             SET subj_abbr = ?, subj_name = ?, level = ?, code = ?, codea = ?, compulsory = ?
             WHERE subj_id = ?'
        );
        if ($stmt === false) throw new RuntimeException('Query preparation failed.');
        $stmt->bind_param('sssssii', $subj_abbr, $subj_name, $level_raw, $code, $codea, $compulsory, $subj_id);
        $stmt->execute();
        $stmt->close();

        $response = [
            'success'    => true,
            'message'    => 'Subject updated successfully.',
            'subject_id' => $subj_id,
        ];
    }

} catch (InvalidArgumentException $e) {
    $response['message'] = $e->getMessage();
} catch (RuntimeException $e) {
    $response['message'] = $e->getMessage();
} catch (Throwable $e) {
    error_log('[process_subject] ' . $e->getMessage());
    $response['message'] = 'An unexpected error occurred. Please try again.';
}

$conn->close();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
