<?php
header('Content-Type: application/json');
require_once "conn.php"; // Your database connection

if (!isset($_POST['visit_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing Visit ID.']);
    exit;
}

$visit_id = intval($_POST['visit_id']);
// For this example, we'll update a few fields.
// You can expand this to include all fields from your form.
$chief_complaint = $_POST['chief_complaint'] ?? '';
$attended_by = $_POST['attended_by'] ?? '';

// Basic validation
if (empty($chief_complaint) || empty($attended_by)) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

$sql = "UPDATE sick_bay_visits SET chief_complaint = ?, attended_by = ? WHERE visit_id = ?";
$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("ssi", $chief_complaint, $attended_by, $visit_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Visit updated successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to execute update.']);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare statement.']);
}

$conn->close();
