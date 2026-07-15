<?php 
header('Content-Type: application/json'); 
header('Access-Control-Allow-Origin: *'); 
header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); 
header('Access-Control-Allow-Headers: Content-Type');   

require_once 'conn.php';  

try {     
    // Get current date     
    $currentDate = date('Y-m-d');          
    
    // Query to get students with their attendance status for today     
    $query = "         
        SELECT              
            s.student_id,             
            s.first_name,             
            s.last_name,             
            s.current_class,             
            s.stream,             
            COALESCE(a.status, '') as attendance_status         
        FROM students s         
        LEFT JOIN attendance a ON s.student_id = a.student_id AND a.date = ?         
        ORDER BY s.student_id ASC     
    ";          
    
    $stmt = $conn->prepare($query);     
    if (!$stmt) {         
        throw new Exception("Prepare failed: " . $conn->error);     
    }          
    
    $stmt->bind_param("s", $currentDate);     
    $stmt->execute();          
    
    $result = $stmt->get_result();     
    $students = [];          
    
    while ($row = $result->fetch_assoc()) {         
        $students[] = [             
            'student_id' => $row['student_id'],             
            'first_name' => $row['first_name'],             
            'last_name' => $row['last_name'],             
            'current_class' => $row['current_class'],             
            'stream' => $row['stream'],             
            'attendance_status' => $row['attendance_status']         
        ];     
    }          
    
    $stmt->close();          
    
    echo json_encode([         
        'success' => true,         
        'students' => $students,         
        'date' => $currentDate,         
        'total_students' => count($students)     
    ]);      
    
} catch (Exception $e) {     
    echo json_encode([         
        'success' => false,         
        'message' => $e->getMessage()     
    ]); 
}  

$conn->close(); 
?>