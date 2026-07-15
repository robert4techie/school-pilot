<?php
require_once '../auth.php';
require_once '../conn.php';

header('Content-Type: application/json');

// ── Role guard ────────────────────────────────────────────────────────────────
$allowed = ['developer', 'super user', 'class teacher', 'subject teacher', 'school leader'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorised access.']);
    exit;
}

try {
    // ── Single query with LEFT JOIN (one parent row per student via subquery) ──
    $sql = "
        SELECT
            s.student_id, s.first_name, s.last_name, s.date_of_birth,
            s.gender, s.nationality, s.religion, s.profile_photo,
            s.residential_address, s.current_class, s.stream, s.section,
            s.school_pay_code, s.date_of_enrolment, s.previous_school,
            s.subject_combination, s.status, s.created_at,
            p.id         AS parent_id,
            p.full_name  AS parent_name,
            p.occupation AS parent_occupation,
            p.phone      AS parent_phone,
            p.email      AS parent_email
        FROM students s
        LEFT JOIN parents p ON p.student_id = s.student_id
        ORDER BY s.last_name ASC, s.first_name ASC, s.student_id ASC
    ";

    $result = $conn->query($sql);
    if (!$result) throw new Exception('Query failed: ' . $conn->error);

    $students = [];
    $seen     = [];   // guard against duplicate rows when a student has >1 parent

    while ($r = $result->fetch_assoc()) {
        $sid = $r['student_id'];
        if (isset($seen[$sid])) continue;
        $seen[$sid] = true;

        $students[] = [
            'student_id'          => $r['student_id'],
            'first_name'          => $r['first_name'],
            'last_name'           => $r['last_name'],
            'date_of_birth'       => $r['date_of_birth']       ?? '',
            'gender'              => $r['gender'],
            'nationality'         => $r['nationality']         ?? '',
            'religion'            => $r['religion']            ?? '',
            'profile_photo'       => $r['profile_photo']       ?? '',
            'residential_address' => $r['residential_address'] ?? '',
            'current_class'       => $r['current_class'],
            'stream'              => $r['stream'],
            'section'             => $r['section'],
            'school_pay_code'     => $r['school_pay_code']     ?? '',
            'date_of_enrolment'   => $r['date_of_enrolment']   ?? '',
            'previous_school'     => $r['previous_school']     ?? '',
            'subject_combination' => $r['subject_combination'] ?? '',
            'status'              => $r['status']              ?? 'active',
            'created_at'          => $r['created_at']          ?? '',
            'parent' => [
                'id'         => $r['parent_id']         ?? null,
                'full_name'  => $r['parent_name']       ?? '',
                'occupation' => $r['parent_occupation'] ?? '',
                'phone'      => $r['parent_phone']      ?? '',
                'email'      => $r['parent_email']      ?? '',
            ],
        ];
    }

    echo json_encode([
        'success'  => true,
        'students' => $students,
        'count'    => count($students),
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
}