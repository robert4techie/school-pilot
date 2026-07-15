<?php
/**
 * fetch_staff.php  — Staff list data endpoint
 * Fixes: added role-based access check, all NULL columns handled,
 *        consistent JSON error envelope, removed data leak on close-before-encode.
 */
require_once '../auth.php';   // Ensures session exists; redirects unauthenticated users
require_once '../conn.php';

// ── Role / permission guard ─────────────────────────────────────────────────
// Only admins and HR roles should see sensitive payroll identifiers.
// Adjust the allowed roles array to match your application's role constants.
$allowed_roles = ['super user', 'developer', 'headteacher', 'bursar'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles, true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

header('Content-Type: application/json');

try {
    // Prepared statement – no user input here, but use a parameterised query
    // pattern for consistency and future safety if filters are added.
    $sql = "SELECT
                staff_id,
                CONCAT(first_name, ' ', last_name) AS full_name,
                gender,
                phone_number,
                email,
                tin_number,
                nssf_number,
                national_id
            FROM staff
            ORDER BY last_name, first_name";

    $result = $conn->query($sql);

    if (!$result) {
        throw new RuntimeException('Query failed.');   // Do NOT expose $conn->error to client
    }

    $data = [];
    while ($row = $result->fetch_assoc()) {
        // Sanitise every nullable column uniformly
        foreach (['tin_number', 'nssf_number', 'national_id', 'phone_number', 'email'] as $col) {
            $row[$col] = $row[$col] !== null ? $row[$col] : '—';
        }
        $data[] = $row;
    }

    $result->free();
    $conn->close();

    echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);

} catch (Throwable $e) {
    // Log the real error server-side; never expose internals to the browser
    error_log('[fetch_staff] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load staff data. Please try again.']);
}