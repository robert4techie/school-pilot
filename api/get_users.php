<?php
// ═══════════════════════════════════════════════════════════════════════════════
//  api/get_users.php  –  Return JSON list of non-developer users
//
//  Security fixes applied:
//    • Session/auth guard (was missing – endpoint was publicly accessible)
//    • Only privileged roles can call this endpoint
//    • OOP mysqli (consistent with user_roles.php)
//    • Raw DB errors never exposed to client
//    • Explicit column selection (no SELECT *)
// ═══════════════════════════════════════════════════════════════════════════════

header('Content-Type: application/json');

require_once '../auth.php';   // starts session
require_once '../conn.php';   // provides $conn (mysqli OOP)

// ── Auth guard ────────────────────────────────────────────────────────────────
$allowed = ['super user', 'developer'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed, true)) {
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => 'Access denied.']);
    exit;
}

// ── Query ─────────────────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT user_id    AS id,
            user_name  AS name,
            email,
            role,
            last_login
     FROM   users
     WHERE  role != 'developer'
     ORDER  BY user_id ASC"
);

if (!$stmt) {
    error_log('[get_users] prepare failed: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['error' => true, 'message' => 'A server error occurred.']);
    exit;
}

$stmt->execute();
$result = $stmt->get_result();

$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = [
        'id'         => (int) $row['id'],
        'name'       => $row['name'],
        'email'      => $row['email'],
        'role'       => $row['role'],
        'last_login' => $row['last_login'],
    ];
}

$stmt->close();
$conn->close();

echo json_encode($users);
