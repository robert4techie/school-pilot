<?php
/**
 * GET /get_subject.php?id={int}
 *
 * Returns a single subject record as JSON.
 * Requires an authenticated session (enforced by auth.php).
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once 'auth.php';
require_once 'conn.php';

$response = [
    'success' => false,
    'message' => 'Failed to retrieve subject.',
    'subject' => null,
];

try {
    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        throw new InvalidArgumentException('Invalid subject ID.');
    }

    $stmt = $conn->prepare(
        'SELECT subj_id, subj_abbr, subj_name, level, code, codea, compulsory
         FROM subjects
         WHERE subj_id = ?
         LIMIT 1'
    );

    if ($stmt === false) {
        throw new RuntimeException('Query preparation failed.');
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        throw new RuntimeException('Subject not found.');
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    $response = [
        'success' => true,
        'message' => 'OK',
        'subject' => [
            'subj_id'    => (int)$row['subj_id'],
            'subj_abbr'  => $row['subj_abbr'],
            'subj_name'  => $row['subj_name'],
            'level'      => $row['level'],
            'code'       => $row['code']  ?? '',
            'codea'      => $row['codea'] ?? '',
            'compulsory' => (int)$row['compulsory'],
        ],
    ];

} catch (InvalidArgumentException $e) {
    $response['message'] = $e->getMessage();
} catch (RuntimeException $e) {
    $response['message'] = $e->getMessage();
} catch (Throwable $e) {
    error_log('[get_subject] ' . $e->getMessage());
    $response['message'] = 'An unexpected error occurred. Please try again.';
}

$conn->close();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
