<?php
// api/get_teachers.php
declare(strict_types=1);
header('Content-Type: application/json');
require_once '../auth.php';
require_once '../conn.php';

$response = ['success' => false, 'teachers' => []];

try {
    $sql = "
        SELECT
            st.staff_id,
            st.first_name,
            st.last_name,
            st.phone_number,
            ta.class_name,
            ta.stream_name,
            su.subj_id,
            su.subj_name
        FROM staff st
        INNER JOIN teaching_assignments ta ON st.staff_id = ta.staff_id
        INNER JOIN subjects su ON ta.subject_id = su.subj_id
        ORDER BY st.first_name, st.last_name, ta.class_name, ta.stream_name
    ";

    $result = $conn->query($sql);
    if ($result === false) {
        throw new Exception('Query failed: ' . $conn->error);
    }

    $teachers = [];
    while ($row = $result->fetch_assoc()) {
        $staffId = $row['staff_id'];

        if (!isset($teachers[$staffId])) {
            $teachers[$staffId] = [
                'id'          => $staffId,
                'name'        => trim($row['first_name'] . ' ' . $row['last_name']),
                'contact'     => $row['phone_number'] ?? '',
                'assignments' => [],
            ];
        }

        $teachers[$staffId]['assignments'][] = [
            'class'        => $row['class_name'],
            'stream'       => $row['stream_name'],
            'subject_id'   => (int) $row['subj_id'],
            'subject_name' => $row['subj_name'],
        ];
    }

    $response['success']  = true;
    $response['teachers'] = array_values($teachers);

} catch (Exception $e) {
    $response['message'] = 'Could not load teacher data.';
    error_log('[get_teachers] ' . $e->getMessage());
}

if (isset($conn)) $conn->close();
echo json_encode($response);
