<?php
require_once '../auth.php'; 
require_once '../conn.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in JSON responses
ini_set('log_errors', 1);

// Set JSON header
header('Content-Type: application/json');

// Define promotion order (REVERSE - highest class first)
$PROMOTION_ORDER = [
    'Senior Five' => 'Senior Six',
    'Senior Four' => 'Senior Five', 
    'Senior Three' => 'Senior Four',
    'Senior Two' => 'Senior Three',
    'Senior One' => 'Senior Two'
];

// Classes that CAN be promoted
$PROMOTABLE_CLASSES = ['Senior One', 'Senior Two', 'Senior Three', 'Senior Four', 'Senior Five'];

// Step 1: Check promotion status and what can be promoted next
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'check_status') {
    $academic_year = mysqli_real_escape_string($conn, $_GET['academic_year']);
    
    // Get all completed promotions for this year
    $query = "SELECT DISTINCT from_class, to_class, COUNT(*) as student_count
              FROM promotion_log 
              WHERE academic_year = ? 
              GROUP BY from_class, to_class
              ORDER BY 
                CASE from_class
                    WHEN 'Senior Five' THEN 1
                    WHEN 'Senior Four' THEN 2
                    WHEN 'Senior Three' THEN 3
                    WHEN 'Senior Two' THEN 4
                    WHEN 'Senior One' THEN 5
                END";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit;
    }
    
    $stmt->bind_param("s", $academic_year);
    $stmt->execute();
    $result = $stmt->get_result();
    $completed = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Determine which classes have been promoted
    $promoted_classes = [];
    foreach ($completed as $promo) {
        $promoted_classes[] = $promo['from_class'];
    }
    
    // Determine next allowed class to promote (must follow order)
    $next_allowed = null;
    foreach ($PROMOTION_ORDER as $from => $to) {
        if (!in_array($from, $promoted_classes)) {
            $next_allowed = $from;
            break;
        }
    }
    
    // Get all allowed classes (those that can still be promoted in order)
    $allowed_classes = [];
    if ($next_allowed) {
        $found = false;
        foreach ($PROMOTION_ORDER as $from => $to) {
            if ($from === $next_allowed) $found = true;
            if ($found && !in_array($from, $promoted_classes)) {
                $allowed_classes[] = $from;
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'completed_promotions' => $completed,
        'promoted_classes' => $promoted_classes,
        'next_required' => $next_allowed,
        'allowed_classes' => $allowed_classes,
        'all_complete' => count($promoted_classes) === count($PROMOTION_ORDER)
    ]);
    exit;
}

// Step 2: Get students for a specific class/stream
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_students') {
    $current_class = mysqli_real_escape_string($conn, $_GET['current_class']);
    $current_stream = mysqli_real_escape_string($conn, $_GET['stream']);
    $academic_year = mysqli_real_escape_string($conn, $_GET['academic_year']);
    
    // Debug: Log what we're searching for
    error_log("Searching for students: class=$current_class, stream=$current_stream, year=$academic_year");
    
    // Check if this class has already been promoted
    $check_query = "SELECT COUNT(*) as count FROM promotion_log WHERE from_class = ? AND academic_year = ?";
    $check = $conn->prepare($check_query);
    
    if (!$check) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit;
    }
    
    $check->bind_param("ss", $current_class, $academic_year);
    $check->execute();
    $check_result = $check->get_result();
    $count = $check_result->fetch_assoc()['count'];
    $check->close();
    
    if ($count > 0) {
        echo json_encode([
            'success' => false, 
            'message' => "$current_class has already been promoted this academic year!"
        ]);
        exit;
    }
    
    // Get students who haven't been promoted yet
    $query = "SELECT 
                s.student_id,
                s.first_name,
                s.last_name,
                s.current_class,
                s.stream,
                s.section
              FROM students s
              WHERE s.current_class = ? 
                AND s.stream = ?
                AND s.status = 'active'
                AND NOT EXISTS (
                    SELECT 1 FROM promotion_log pl 
                    WHERE pl.student_id = s.student_id 
                    AND pl.academic_year = ?
                )
              ORDER BY s.last_name, s.first_name";
    
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit;
    }
    
    $stmt->bind_param("sss", $current_class, $current_stream, $academic_year);
    $stmt->execute();
    $result = $stmt->get_result();
    $students = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Debug: Log how many students found
    error_log("Found " . count($students) . " students");
    
    echo json_encode([
        'success' => true, 
        'students' => $students,
        'debug' => [
            'class' => $current_class,
            'stream' => $current_stream,
            'count' => count($students)
        ]
    ]);
    exit;
}

// Step 3: Promote students (with order validation)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'promote') {
    $student_ids = json_decode($_POST['student_ids'], true);
    $from_class = mysqli_real_escape_string($conn, $_POST['from_class']);
    $from_stream = mysqli_real_escape_string($conn, $_POST['from_stream']);
    $target_class = mysqli_real_escape_string($conn, $_POST['target_class']);
    $target_stream = mysqli_real_escape_string($conn, $_POST['target_stream']);
    $academic_year = mysqli_real_escape_string($conn, $_POST['academic_year']);
    
    // Validate student IDs array
    if (!is_array($student_ids) || empty($student_ids)) {
        echo json_encode(['success' => false, 'message' => 'No students selected']);
        exit;
    }
    
    // Validate: Check if from_class is in the allowed promotion order
    if (!isset($PROMOTION_ORDER[$from_class])) {
        echo json_encode([
            'success' => false, 
            'message' => "$from_class cannot be promoted (only S1-S5 can be promoted)"
        ]);
        exit;
    }
    
    // Validate: Target class must match the promotion order
    if ($PROMOTION_ORDER[$from_class] !== $target_class) {
        echo json_encode([
            'success' => false,
            'message' => "$from_class must be promoted to {$PROMOTION_ORDER[$from_class]}, not $target_class"
        ]);
        exit;
    }
    
    // Validate: Check this class hasn't been promoted already
    $check = $conn->prepare("SELECT COUNT(*) as count FROM promotion_log WHERE from_class = ? AND academic_year = ?");
    $check->bind_param("ss", $from_class, $academic_year);
    $check->execute();
    $check_result = $check->get_result();
    $count = $check_result->fetch_assoc()['count'];
    $check->close();
    
    if ($count > 0) {
        echo json_encode([
            'success' => false,
            'message' => "$from_class has already been promoted this year!"
        ]);
        exit;
    }
    
    // Validate: Check that higher classes have been promoted first
    $higher_classes = [];
    foreach ($PROMOTION_ORDER as $fc => $tc) {
        if ($fc === $from_class) break;
        $higher_classes[] = $fc;
    }
    
    if (!empty($higher_classes)) {
        // Check if any higher class hasn't been promoted yet
        $placeholders = implode(',', array_fill(0, count($higher_classes), '?'));
        $check_order_query = "SELECT from_class 
                             FROM (
                                 SELECT 'Senior Five' as from_class
                                 UNION ALL SELECT 'Senior Four' 
                                 UNION ALL SELECT 'Senior Three' 
                                 UNION ALL SELECT 'Senior Two' 
                                 UNION ALL SELECT 'Senior One'
                             ) classes
                             WHERE from_class IN ($placeholders)
                             AND from_class NOT IN (
                                 SELECT DISTINCT from_class FROM promotion_log WHERE academic_year = ?
                             )";
        
        $check_order = $conn->prepare($check_order_query);
        
        // Create type string and bind parameters
        $types = str_repeat('s', count($higher_classes)) . 's';
        $params = array_merge($higher_classes, [$academic_year]);
        
        // Bind parameters using call_user_func_array
        $bind_names[] = $types;
        for ($i = 0; $i < count($params); $i++) {
            $bind_name = 'bind' . $i;
            $$bind_name = $params[$i];
            $bind_names[] = &$$bind_name;
        }
        call_user_func_array(array($check_order, 'bind_param'), $bind_names);
        
        $check_order->execute();
        $unpromoted_result = $check_order->get_result();
        $unpromoted = [];
        while ($row = $unpromoted_result->fetch_assoc()) {
            $unpromoted[] = $row['from_class'];
        }
        $check_order->close();
        
        if (!empty($unpromoted)) {
            echo json_encode([
                'success' => false,
                'message' => "You must promote " . implode(', ', $unpromoted) . " first before promoting $from_class!"
            ]);
            exit;
        }
    }
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Update students one by one for safety
        $update_query = "UPDATE students 
                        SET current_class = ?, 
                            stream = ?,
                            updated_at = NOW()
                        WHERE student_id = ?
                        AND status = 'active'
                        AND current_class = ?";
        
        $stmt = $conn->prepare($update_query);
        $updated_count = 0;
        
        foreach ($student_ids as $student_id) {
            $student_id = mysqli_real_escape_string($conn, $student_id);
            $stmt->bind_param("ssss", $target_class, $target_stream, $student_id, $from_class);
            $stmt->execute();
            $updated_count += $stmt->affected_rows;
        }
        $stmt->close();
        
        // Log each promotion
        $log_query = "INSERT INTO promotion_log 
                     (student_id, from_class, from_stream, to_class, to_stream, promoted_by, academic_year)
                     VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $log_stmt = $conn->prepare($log_query);
        
        foreach ($student_ids as $student_id) {
            $student_id = mysqli_real_escape_string($conn, $student_id);
            $promoted_by = $_SESSION['user_id'];
            
            $log_stmt->bind_param(
                "sssssss",
                $student_id,
                $from_class,
                $from_stream,
                $target_class,
                $target_stream,
                $promoted_by,
                $academic_year
            );
            $log_stmt->execute();
        }
        $log_stmt->close();
        
        mysqli_commit($conn);
        
        echo json_encode([
            'success' => true,
            'message' => "$updated_count student(s) promoted from $from_class to $target_class successfully!"
        ]);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => 'Promotion failed: ' . $e->getMessage()]);
    }
    exit;
}

// Step 4: Reverse promotion for a specific class
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reverse_class') {
    $from_class = mysqli_real_escape_string($conn, $_POST['from_class']);
    $academic_year = mysqli_real_escape_string($conn, $_POST['academic_year']);
    
    mysqli_begin_transaction($conn);
    
    try {
        // Get all students promoted from this class
        $get_promotions = $conn->prepare("SELECT student_id, from_class, from_stream FROM promotion_log WHERE from_class = ? AND academic_year = ?");
        $get_promotions->bind_param("ss", $from_class, $academic_year);
        $get_promotions->execute();
        $result = $get_promotions->get_result();
        $promotions = $result->fetch_all(MYSQLI_ASSOC);
        $get_promotions->close();
        
        if (empty($promotions)) {
            echo json_encode(['success' => false, 'message' => "No promotions found for $from_class"]);
            exit;
        }
        
        // Reverse each promotion (move students back)
        $reverse = $conn->prepare("UPDATE students SET current_class = ?, stream = ? WHERE student_id = ?");
        foreach ($promotions as $promo) {
            $reverse->bind_param("sss", $promo['from_class'], $promo['from_stream'], $promo['student_id']);
            $reverse->execute();
        }
        $reverse->close();
        
        // Delete promotion logs for this class
        $delete = $conn->prepare("DELETE FROM promotion_log WHERE from_class = ? AND academic_year = ?");
        $delete->bind_param("ss", $from_class, $academic_year);
        $delete->execute();
        $affected = $delete->affected_rows;
        $delete->close();
        
        mysqli_commit($conn);
        echo json_encode(['success' => true, 'message' => "Reversed promotion for $from_class ($affected students moved back)"]);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => 'Reverse failed: ' . $e->getMessage()]);
    }
    exit;
}

// Step 5: Reset ALL promotions for a year (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_year') {
    $academic_year = mysqli_real_escape_string($conn, $_POST['academic_year']);
    
    mysqli_begin_transaction($conn);
    
    try {
        // Get all promoted students
        $get_promotions = $conn->prepare("SELECT student_id, from_class, from_stream FROM promotion_log WHERE academic_year = ?");
        $get_promotions->bind_param("s", $academic_year);
        $get_promotions->execute();
        $result = $get_promotions->get_result();
        $promotions = $result->fetch_all(MYSQLI_ASSOC);
        $get_promotions->close();
        
        // Reverse each promotion
        if (!empty($promotions)) {
            $reverse = $conn->prepare("UPDATE students SET current_class = ?, stream = ? WHERE student_id = ?");
            foreach ($promotions as $promo) {
                $reverse->bind_param("sss", $promo['from_class'], $promo['from_stream'], $promo['student_id']);
                $reverse->execute();
            }
            $reverse->close();
        }
        
        // Delete promotion logs
        $delete = $conn->prepare("DELETE FROM promotion_log WHERE academic_year = ?");
        $delete->bind_param("s", $academic_year);
        $delete->execute();
        $delete->close();
        
        mysqli_commit($conn);
        echo json_encode(['success' => true, 'message' => 'All promotions reset successfully']);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => 'Reset failed: ' . $e->getMessage()]);
    }
    exit;
}

// If no action matches, return error
echo json_encode(['success' => false, 'message' => 'Invalid action']);
exit;
?>