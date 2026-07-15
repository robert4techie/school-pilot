<?php
require_once '../auth.php'; 
require_once '../conn.php'; 

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit();
}

$id = $_GET['id'] ?? 0;

if ($id === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid record ID.']);
    exit();
}

$stmt = $conn->prepare("DELETE FROM student_behaviors WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'Record deleted successfully.']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to delete record.']);
}

$stmt->close();
$conn->close();
?>
