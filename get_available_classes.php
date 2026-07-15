<?php
require_once 'auth.php';
require_once 'conn.php';
header('Content-Type: application/json');

try {
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // All possible class-stream combinations
    $allClasses = [
        ['class' => 'Senior one', 'stream' => 'East'],
        ['class' => 'Senior one', 'stream' => 'West'],
        ['class' => 'Senior Two', 'stream' => 'East'],
        ['class' => 'Senior Two', 'stream' => 'West'],
        ['class' => 'Senior Three', 'stream' => 'East'],
        ['class' => 'Senior Three', 'stream' => 'West'],
        ['class' => 'Senior Four', 'stream' => 'East'],
        ['class' => 'Senior Four', 'stream' => 'West'],
        ['class' => 'Senior Five', 'stream' => 'Arts'],
        ['class' => 'Senior Five', 'stream' => 'Sciences'],
        ['class' => 'Senior Six', 'stream' => 'Arts'],
        ['class' => 'Senior Six', 'stream' => 'Sciences']
    ];
    
    $academicYear = date('Y');
    
    // Get already assigned class teachers using prepared statement
    $assignedQuery = "SELECT class_name, stream FROM class_teachers WHERE academic_year = ?";
    $stmt = $conn->prepare($assignedQuery);
    $stmt->bind_param("s", $academicYear);
    $stmt->execute();
    $assignedResult = $stmt->get_result();
    
    $assigned = [];
    while ($row = $assignedResult->fetch_assoc()) {
        $assigned[] = $row['class_name'] . '|' . $row['stream'];
    }
    
    $stmt->close();
    
    // Filter available classes
    $available = array_filter($allClasses, function($combo) use ($assigned) {
        $key = $combo['class'] . '|' . $combo['stream'];
        return !in_array($key, $assigned);
    });
    
    echo json_encode(['success' => true, 'available' => array_values($available)]);
    
    $conn->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>