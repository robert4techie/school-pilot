<?php
require_once '../auth.php';
require_once '../conn.php';

header('Content-Type: application/json');

// ── Role guard ────────────────────────────────────────────────────────────────
$allowed = ['developer', 'super user','school leader'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorised access.']);
    exit;
}

try {
    $sql = "
        SELECT
            id, staff_id, first_name, last_name, date_of_birth, gender,
            phone_number, marital_status, nationality, email, address,
            designation, department, joining_date, employment_type,
            qualifications, experience, national_id, tin_number, nssf_number,
            profile_photo, status, created_at
        FROM staff
        ORDER BY first_name ASC, last_name ASC
    ";

    $result = $conn->query($sql);
    if (!$result) throw new Exception('Query failed: ' . $conn->error);

    $staff = [];
    while ($row = $result->fetch_assoc()) {
        $staff[] = [
            'id'              => (int) $row['id'],
            'staff_id'        => $row['staff_id']        ?? '',
            'first_name'      => $row['first_name']      ?? '',
            'last_name'       => $row['last_name']       ?? '',
            'date_of_birth'   => $row['date_of_birth']   ?? '',
            'gender'          => $row['gender']          ?? '',
            'phone_number'    => $row['phone_number']    ?? '',
            'marital_status'  => $row['marital_status']  ?? '',
            'nationality'     => $row['nationality']     ?? '',
            'email'           => $row['email']           ?? '',
            'address'         => $row['address']         ?? '',
            'designation'     => $row['designation']     ?? '',
            'department'      => $row['department']      ?? '',
            'joining_date'    => $row['joining_date']    ?? '',
            'employment_type' => $row['employment_type'] ?? '',
            'qualifications'  => $row['qualifications']  ?? '',
            'experience'      => $row['experience']      ?? '0',
            'nin'             => $row['national_id']    ?? '',
            'tin'             => $row['tin_number']     ?? '',
            'nssf'            => $row['nssf_number']    ?? '',
            'profile_photo'   => $row['profile_photo']  ?? '',
            'Status'          => $row['status']         ?? 'inactive',
            'created_at'      => $row['created_at']      ?? '',
        ];
    }

    echo json_encode([
        'success' => true,
        'staff'   => $staff,
        'count'   => count($staff),
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
}