<?php
require_once '../auth.php';
require_once '../conn.php';

$class = isset($_POST['class']) ? $_POST['class'] : '';
$response = [];

try {
    if (!empty($class)) {
        $sql = "SELECT * FROM exam_sets WHERE classes LIKE ? ORDER BY id ASC";
        $stmt = $conn->prepare($sql);
        $param = "%$class%";
        $stmt->bind_param('s', $param);
    } else {
        // Fallback: Get all sets if no class is specified
        $sql = "SELECT * FROM exam_sets ORDER BY id ASC";
        $stmt = $conn->prepare($sql);
    }
    
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $response[] = $row;
        }
        $stmt->close();
    }
} catch (Exception $e) {
    error_log($e->getMessage());
}

header('Content-Type: application/json');
echo json_encode($response);