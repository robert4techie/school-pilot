<?php
// api/get_subjects.php
declare(strict_types=1);
header('Content-Type: application/json');
require_once '../auth.php';
require_once '../conn.php';

$response = ['success' => false, 'subjects' => []];

try {
    $result = $conn->query(
        "SELECT subj_id AS subject_id, subj_name AS name FROM subjects ORDER BY subj_name ASC"
    );

    if ($result === false) {
        throw new Exception('Query failed: ' . $conn->error);
    }

    $subjects = [];
    while ($row = $result->fetch_assoc()) {
        $subjects[] = [
            'subject_id' => (int) $row['subject_id'],
            'name'       => $row['name'],
        ];
    }

    $response['success']  = true;
    $response['subjects'] = $subjects;

} catch (Exception $e) {
    $response['message'] = 'Could not load subjects.';
    error_log('[get_subjects] ' . $e->getMessage());
}

if (isset($conn)) $conn->close();
echo json_encode($response);
