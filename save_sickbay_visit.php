<?php
header('Content-Type: application/json');

// Enable error reporting for development (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once "conn.php";

$response = [
    'success' => false,
    'message' => '',
    'errors' => [],
    'debug' => [] // Added for debugging purposes
];

// Input sanitization function
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method. Only POST requests are allowed.', 405);
    }

    // Log raw input for debugging
    $response['debug']['raw_input'] = file_get_contents('php://input');
    $response['debug']['post_data'] = $_POST;

    // Verify database connection
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error, 500);
    }

    // Validate required fields
    $requiredFields = [
        'visitDate' => 'Visit date',
        'visitTime' => 'Visit time',
        'student_id' => 'Student',
        'attendedBy' => 'Staff member'
    ];

    foreach ($requiredFields as $field => $label) {
        if (empty($_POST[$field])) {
            $response['errors'][$field] = "$label is required";
        }
    }

    if (!empty($response['errors'])) {
        throw new Exception('Validation failed. Please check all required fields.', 400);
    }

    // Process and sanitize form data
    $visit_date = $conn->real_escape_string(sanitizeInput($_POST['visitDate']));
    $visit_time = $conn->real_escape_string(sanitizeInput($_POST['visitTime']));
    $student_id = (int)$_POST['student_id'];
    $attended_by = $conn->real_escape_string(sanitizeInput($_POST['attendedBy']));
    
    // Get student details
    $student_name = '';
    $student_class = '';
    $student_stream = '';
    
    $stmt = $conn->prepare("SELECT first_name, last_name, class, stream FROM students WHERE id = ?");
    if (!$stmt) {
        throw new Exception("Error preparing student query: " . $conn->error, 500);
    }
    $stmt->bind_param("i", $student_id);
    if (!$stmt->execute()) {
        throw new Exception("Error executing student query: " . $stmt->error, 500);
    }
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $student_name = $row['first_name'] . ' ' . $row['last_name'];
        $student_class = $row['class'];
        $student_stream = $row['stream'];
    } else {
        throw new Exception("Student not found with ID: $student_id", 404);
    }
    $stmt->close();

    // Process symptoms (handle both array and string input)
    $symptoms = [];
    if (isset($_POST['symptoms'])) {
        $symptoms = is_array($_POST['symptoms']) ? $_POST['symptoms'] : [$_POST['symptoms']];
        $symptoms = array_map('sanitizeInput', $symptoms);
    }
    $symptoms_json = json_encode($symptoms);
    if ($symptoms_json === false) {
        throw new Exception("Error encoding symptoms data", 500);
    }

    $other_symptoms = sanitizeInput($_POST['otherSymptoms'] ?? '');
    
    // Process numeric fields with validation
    $temperature = null;
    if (isset($_POST['temperature']) && $_POST['temperature'] !== '') {
        if (!is_numeric($_POST['temperature'])) {
            $response['errors']['temperature'] = "Temperature must be a number";
        } else {
            $temperature = (float)$_POST['temperature'];
            if ($temperature < 30 || $temperature > 45) { // Reasonable range check
                $response['errors']['temperature'] = "Temperature must be between 30 and 45";
            }
        }
    }

    $blood_pressure = sanitizeInput($_POST['bloodPressure'] ?? '');
    $assessment_notes = sanitizeInput($_POST['assessmentNotes'] ?? '');
    
    // Process medications data
    $medications = [];
    $dosages = [];
    $medication_times = [];
    
    if (isset($_POST['medications']) && is_array($_POST['medications'])) {
        $medications = array_map('sanitizeInput', $_POST['medications']);
    }
    if (isset($_POST['dosages']) && is_array($_POST['dosages'])) {
        $dosages = array_map('sanitizeInput', $_POST['dosages']);
    }
    if (isset($_POST['medicationTimes']) && is_array($_POST['medicationTimes'])) {
        $medication_times = array_map('sanitizeInput', $_POST['medicationTimes']);
    }
    
    $medications_json = json_encode($medications);
    $dosages_json = json_encode($dosages);
    $medication_times_json = json_encode($medication_times);
    
    if ($medications_json === false || $dosages_json === false || $medication_times_json === false) {
        throw new Exception("Error encoding medication data", 500);
    }
    
    $treatment_notes = sanitizeInput($_POST['treatmentNotes'] ?? '');
    
    // Process rest time with validation
    $rest_time = 0;
    if (isset($_POST['restTime'])) {
        $rest_time = (int)$_POST['restTime'];
        if ($rest_time < 0 || $rest_time > 1440) { // 24 hours max in minutes
            $response['errors']['restTime'] = "Rest time must be between 0 and 1440 minutes";
        }
    }

    $action_taken = sanitizeInput($_POST['actionTaken'] ?? 'ReturnedToClass');
    $parent_notified = isset($_POST['parentNotified']) ? 1 : 0;
    $parent_notes = sanitizeInput($_POST['parentNotes'] ?? '');
    $followup_required = isset($_POST['followupRequired']) ? 1 : 0;
    
    // Process followup date if needed
    $followup_date = null;
    if ($followup_required && !empty($_POST['followupDate'])) {
        $followup_date = sanitizeInput($_POST['followupDate']);
        if (!strtotime($followup_date)) {
            $response['errors']['followupDate'] = "Invalid follow-up date format";
        }
    }
    
    $followup_notes = sanitizeInput($_POST['followupNotes'] ?? '');

    // Check if we have any validation errors at this point
    if (!empty($response['errors'])) {
        throw new Exception('Data validation failed', 400);
    }

    // Prepare SQL statement
    $sql = "INSERT INTO sick_bay_visits (
        visit_date, visit_time, student_id, student_name, student_class, student_stream,
        symptoms, other_symptoms, temperature, blood_pressure, assessment_notes,
        medications, dosages, medication_times, treatment_notes, rest_time,
        action_taken, parent_notified, parent_notes,
        followup_required, followup_date, followup_notes, attended_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Error preparing statement: " . $conn->error, 500);
    }

    $bindResult = $stmt->bind_param(
        "ssiisssssdssssssisissis",
        $visit_date, $visit_time, $student_id, $student_name, $student_class, $student_stream,
        $symptoms_json, $other_symptoms, $temperature, $blood_pressure, $assessment_notes,
        $medications_json, $dosages_json, $medication_times_json, $treatment_notes, $rest_time,
        $action_taken, $parent_notified, $parent_notes,
        $followup_required, $followup_date, $followup_notes, $attended_by
    );
    
    if (!$bindResult) {
        throw new Exception("Error binding parameters: " . $stmt->error, 500);
    }

    if (!$stmt->execute()) {
        throw new Exception("Error executing statement: " . $stmt->error, 500);
    }

    $response['success'] = true;
    $response['message'] = "Sick bay visit record saved successfully!";
    $response['visit_id'] = $conn->insert_id;
    http_response_code(200);

} catch (Exception $e) {
    error_log("Sick Bay Form Error: " . $e->getMessage());
    $response['message'] = $e->getMessage();
    $response['error_details'] = $e->getTraceAsString();
    http_response_code($e->getCode() ?: 500);
    
    if (empty($response['errors'])) {
        $response['errors'] = ['general' => $response['message']];
    }
} finally {
    if (isset($conn)) {
        $conn->close();
    }
    
    // In development, show debug info; in production, remove or secure this
    if ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['SERVER_NAME'] === 'localhost') {
        $response['debug']['post_data'] = $_POST;
    } else {
        unset($response['debug']); // Remove debug info in production
    }
    
    echo json_encode($response);
    exit;
}