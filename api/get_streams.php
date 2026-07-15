<?php
// api/get_streams.php
require_once '../conn.php'; // Adjust path as needed

header('Content-Type: application/json');

$class = $_GET['class'] ?? '';

if (empty($class)) {
    echo json_encode([]);
    exit;
}

$stmt = $conn->prepare("SELECT DISTINCT stream FROM students WHERE current_class = ? AND stream IS NOT NULL AND stream != '' ORDER BY stream ASC");
$stmt->bind_param("s", $class);
$stmt->execute();
$result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($result);
?>