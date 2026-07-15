<?php
require_once '../auth.php';
require_once '../conn.php';

// Check if the request is a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    
    // Handle based on action parameter
    switch ($action) {
        case 'save':
            saveExamSet();
            break;
        case 'update':
            updateExamSet();
            break;
        case 'delete':
            deleteExamSet();
            break;
        case 'get':
            getExamSet();
            break;
        case 'getAll':
            getAllExamSets();
            break;
        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action specified']);
            break;
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}

// Function to save a new exam set
function saveExamSet() {
    global $conn;
    
    // Get form data
    $examCode = trim($_POST['examCode']);
    $examDescription = trim($_POST['examDescription']);
    $examMark = isset($_POST['examMark']) ? intval($_POST['examMark']) : 0;
    $classes = isset($_POST['classes']) ? $_POST['classes'] : [];
    
    // Validate
    if (empty($examCode) || empty($examDescription) || empty($classes) || $examMark <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'All fields are required and exam mark must be greater than 0']);
        return;
    }
    
    // Convert classes array to string
    $classesStr = implode(',', $classes);
    
    // Convert class IDs to names for display
    $classNames = [];
    foreach ($classes as $class) {
        if ($class == '1') {
            $classNames[] = 'Senior Five';
        } elseif ($class == '2') {
            $classNames[] = 'Senior Six';
        }
    }
    $classNamesStr = implode(', ', $classNames);
    
    // Check if exam code already exists
    $stmt = $conn->prepare("SELECT id FROM exam_sets WHERE exam_set = ?");
    $stmt->bind_param("s", $examCode);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Exam code already exists']);
        return;
    }
    
    // Insert into database
    $stmt = $conn->prepare("INSERT INTO exam_sets (exam_set, description, exam_mark, classes) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssis", $examCode, $examDescription, $examMark, $classNamesStr);
    
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Exam set created successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error creating exam set: ' . $stmt->error]);
    }
    
    $stmt->close();
}

// Function to update an existing exam set
function updateExamSet() {
    global $conn;
    
    // Get form data
    $examId = trim($_POST['exam_id']);
    $examCode = trim($_POST['examCode']);
    $examDescription = trim($_POST['examDescription']);
    $examMark = isset($_POST['examMark']) ? intval($_POST['examMark']) : 0;
    $classes = isset($_POST['classes']) ? $_POST['classes'] : [];
    
    // Validate
    if (empty($examId) || empty($examCode) || empty($examDescription) || empty($classes) || $examMark <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'All fields are required and exam mark must be greater than 0']);
        return;
    }
    
    // Convert classes array to string for IDs
    $classesStr = implode(',', $classes);
    
    // Convert class IDs to names for display
    $classNames = [];
    foreach ($classes as $class) {
        if ($class == '1') {
            $classNames[] = 'Senior Five';
        } elseif ($class == '2') {
            $classNames[] = 'Senior Six';
        }
    }
    $classNamesStr = implode(', ', $classNames);
    
    // Check if exam code already exists (but not for this ID)
    $stmt = $conn->prepare("SELECT id FROM exam_sets WHERE exam_set = ? AND id != ?");
    $stmt->bind_param("si", $examCode, $examId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Exam code already exists']);
        return;
    }
    
    // Update in database
    $stmt = $conn->prepare("UPDATE exam_sets SET exam_set = ?, description = ?, exam_mark = ?, classes = ? WHERE id = ?");
    $stmt->bind_param("ssisi", $examCode, $examDescription, $examMark, $classNamesStr, $examId);
    
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Exam set updated successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error updating exam set: ' . $stmt->error]);
    }
    
    $stmt->close();
}

// Function to delete an exam set
function deleteExamSet() {
    global $conn;
    
    $examId = trim($_POST['exam_id']);
    
    if (empty($examId)) {
        echo json_encode(['status' => 'error', 'message' => 'Exam ID is required']);
        return;
    }
    
    // TODO: Check if exam set is in use before deleting
    
    $stmt = $conn->prepare("DELETE FROM exam_sets WHERE id = ?");
    $stmt->bind_param("i", $examId);
    
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Exam set deleted successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error deleting exam set: ' . $stmt->error]);
    }
    
    $stmt->close();
}

// Function to get a single exam set
function getExamSet() {
    global $conn;
    
    $examId = trim($_POST['exam_id']);
    
    if (empty($examId)) {
        echo json_encode(['status' => 'error', 'message' => 'Exam ID is required']);
        return;
    }
    
    $stmt = $conn->prepare("SELECT id, exam_set, description, exam_mark, classes FROM exam_sets WHERE id = ?");
    $stmt->bind_param("i", $examId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $examSet = $result->fetch_assoc();
        
        // Process the classes field - convert from "Senior Five, Senior Six" format
        // to an array of IDs for the form
        $classesArr = [];
        if (!empty($examSet['classes'])) {
            $classNames = explode(', ', $examSet['classes']);
            foreach ($classNames as $className) {
                if ($className === 'Senior Five') {
                    $classesArr[] = '1';
                } elseif ($className === 'Senior Six') {
                    $classesArr[] = '2';
                }
            }
        }
        
        // Add the class IDs to the exam set data
        $examSet['class_ids'] = $classesArr;
        
        echo json_encode(['status' => 'success', 'data' => $examSet]);
        } else {
        echo json_encode(['status' => 'error', 'message' => 'Exam set not found']);
    }
    
    $stmt->close();
}

// Function to get all exam sets
function getAllExamSets() {
    global $conn;
    
    $stmt = $conn->prepare("SELECT id, exam_set, description, exam_mark, classes FROM exam_sets ORDER BY id DESC");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $examSets = [];
    while ($row = $result->fetch_assoc()) {
        $examSets[] = $row;
    }
    
    echo json_encode(['status' => 'success', 'data' => $examSets]);
    
    $stmt->close();
}
?>