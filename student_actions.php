<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once 'conn.php';

// Get the action from the request
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$student_id = $_GET['student_id'] ?? $_POST['student_id'] ?? '';

try {
    switch ($action) {
        case 'get_student':
            getStudentById($conn, $student_id);
            break;
            
        case 'update_student':
            updateStudent($conn, $_POST);
            break;
            
        case 'delete_student':
            deleteStudent($conn, $student_id);
            break;
            
        case 'get_stats':
            getStudentStats($conn);
            break;
            
        default:
            throw new Exception("Invalid action specified");
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function getStudentById($conn, $student_id) {
    if (empty($student_id)) {
        throw new Exception("Student ID is required");
    }
    
    $sql = "SELECT * FROM students WHERE id = ? OR student_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "is", $student_id, $student_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        echo json_encode([
            'success' => true,
            'student' => $row
        ]);
    } else {
        throw new Exception("Student not found");
    }
    
    mysqli_stmt_close($stmt);
}

function updateStudent($conn, $data) {
    if (empty($data['id'])) {
        throw new Exception("Student ID is required for update");
    }
    
    $sql = "UPDATE students SET 
            first_name = ?,
            last_name = ?,
            date_of_birth = ?,
            gender = ?,
            nationality = ?,
            religion = ?,
            residential_address = ?,
            current_class = ?,
            stream = ?,
            previous_school = ?,
            subject_combination = ?
            WHERE id = ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "sssssssssssi", 
        $data['first_name'],
        $data['last_name'],
        $data['date_of_birth'],
        $data['gender'],
        $data['nationality'],
        $data['religion'],
        $data['residential_address'],
        $data['current_class'],
        $data['stream'],
        $data['previous_school'],
        $data['subject_combination'],
        $data['id']
    );
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode([
            'success' => true,
            'message' => 'Student updated successfully'
        ]);
    } else {
        throw new Exception("Update failed: " . mysqli_stmt_error($stmt));
    }
    
    mysqli_stmt_close($stmt);
}

function deleteStudent($conn, $student_id) {
    if (empty($student_id)) {
        throw new Exception("Student ID is required for deletion");
    }
    
    $sql = "DELETE FROM students WHERE id = ? OR student_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, "is", $student_id, $student_id);
    
    if (mysqli_stmt_execute($stmt)) {
        $affected_rows = mysqli_stmt_affected_rows($stmt);
        if ($affected_rows > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Student deleted successfully'
            ]);
        } else {
            throw new Exception("No student found with that ID");
        }
    } else {
        throw new Exception("Delete failed: " . mysqli_stmt_error($stmt));
    }
    
    mysqli_stmt_close($stmt);
}

function getStudentStats($conn) {
    $stats = [];
    
    // Total students
    $result = mysqli_query($conn, "SELECT COUNT(*) as total FROM students");
    $row = mysqli_fetch_assoc($result);
    $stats['total_students'] = $row['total'];
    
    // Students by gender
    $result = mysqli_query($conn, "SELECT gender, COUNT(*) as count FROM students GROUP BY gender");
    $stats['by_gender'] = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $stats['by_gender'][$row['gender']] = $row['count'];
    }
    
    // Students by class
    $result = mysqli_query($conn, "SELECT current_class, COUNT(*) as count FROM students GROUP BY current_class ORDER BY current_class");
    $stats['by_class'] = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $stats['by_class'][$row['current_class']] = $row['count'];
    }
    
    // Students by stream
    $result = mysqli_query($conn, "SELECT stream, COUNT(*) as count FROM students GROUP BY stream ORDER BY stream");
    $stats['by_stream'] = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $stats['by_stream'][$row['stream']] = $row['count'];
    }
    
    // Recent enrollments (last 30 days)
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM students WHERE date_of_enrolment >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $row = mysqli_fetch_assoc($result);
    $stats['recent_enrollments'] = $row['count'];
    
    echo json_encode([
        'success' => true,
        'stats' => $stats
    ]);
}
?>