<?php
// exam_api.php - FULLY CORRECTED

// --- ERROR HANDLING ---
error_reporting(0);
ini_set('display_errors', 0);

// Set the content type to JSON for all responses
header('Content-Type: application/json');

require_once '../auth.php';
require_once '../conn.php';
if (!isset($conn) || $conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . ($conn->connect_error ?? 'Connection object not found.')]);
    exit();
}

// Get the requested action from the frontend
$action = $_POST['action'] ?? '';

// Main controller to route actions to the correct functions
switch ($action) {
    case 'get_subjects':
        getSubjects($conn);
        break;
    case 'get_staff':
        getStaff($conn);
        break;
    case 'get_exam_sets':
        getExamSets($conn);
        break;
    case 'load_exam_timetable':
        loadExamTimetable($conn);
        break;
    case 'save_exam_entry':
        saveExamEntry($conn);
        break;
    case 'delete_exam_entry':
        deleteExamEntry($conn);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action specified.']);
}

$conn->close();

// --- Function Definitions ---

/**
 * Fetches subjects using the correct column names (subj_id, subj_name)
 */
function getSubjects($conn)
{
    $sql = "SELECT subj_id AS id, subj_name AS subject_name FROM subjects ORDER BY subject_name";
    $result = $conn->query($sql);

    if (!$result) {
        echo json_encode(['success' => false, 'error' => 'Failed to fetch subjects: ' . $conn->error]);
        return;
    }
    $subjects = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'data' => $subjects]);
}

/**
 * Fetches staff members from the database.
 */
function getStaff($conn)
{
    $result = $conn->query("SELECT id, CONCAT(first_name, ' ', last_name) AS full_name FROM staff ORDER BY full_name");
    if (!$result) {
        echo json_encode(['success' => false, 'error' => 'Failed to fetch staff: ' . $conn->error]);
        return;
    }
    $staff = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'data' => $staff]);
}

/**
 * Fetches exam sets from the database.
 */
function getExamSets($conn)
{
    $result = $conn->query("SELECT id, exam_set, description FROM exam_sets ORDER BY date_added DESC");
    if (!$result) {
        echo json_encode(['success' => false, 'error' => 'Failed to fetch exam sets: ' . $conn->error]);
        return;
    }
    $exam_sets = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'data' => $exam_sets]);
}

/**
 * Loads the exam timetable
 */
function loadExamTimetable($conn)
{
    $class_name = $_POST['class_name'] ?? '';
    $stream = $_POST['stream'] ?? '';
    $term = $_POST['term'] ?? '';
    $year = $_POST['year'] ?? '';
    $exam_type = $_POST['exam_type'] ?? '';

    $sql = "SELECT 
                ete.id, ete.exam_date, ete.start_time, ete.end_time, ete.room_number,
                ete.subject_id, ete.paper_number, ete.staff_id,
                s.subj_name AS subject_name, 
                CONCAT(st.first_name, ' ', st.last_name) AS teacher_name
            FROM exam_timetable_entries ete
            JOIN subjects s ON ete.subject_id = s.subj_id
            JOIN staff st ON ete.staff_id = st.id
            WHERE ete.class_name = ? AND ete.stream = ? AND ete.term = ? AND ete.academic_year = ? AND ete.exam_type = ?
            ORDER BY ete.exam_date, ete.start_time";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        echo json_encode(['success' => false, 'error' => 'SQL prepare failed: ' . $conn->error]);
        return;
    }
    $stmt->bind_param("sssis", $class_name, $stream, $term, $year, $exam_type);
    $stmt->execute();
    $result = $stmt->get_result();
    $entries = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'data' => $entries]);
    $stmt->close();
}

/**
 * Checks for a duplicate exam entry.
 */
function checkForDuplicateEntry($conn, $class_name, $stream, $subject_id, $paper_number, $exam_date, $start_time, $exam_type, $id_to_exclude = null)
{
    $sql = "SELECT id FROM exam_timetable_entries WHERE class_name = ? AND stream = ? AND subject_id = ? AND paper_number = ? AND exam_date = ? AND start_time = ? AND exam_type = ?";
    // subject_id is INT, rest are strings
    $types = "ssissss";
    $params = [$class_name, $stream, $subject_id, $paper_number, $exam_date, $start_time, $exam_type];

    if ($id_to_exclude) {
        $sql .= " AND id != ?";
        $types .= "i";
        $params[] = $id_to_exclude;
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->store_result();
    $count = $stmt->num_rows;
    $stmt->close();
    return $count > 0;
}

/**
 * Saves or updates an exam entry.
 */
function saveExamEntry($conn)
{
    $id = $_POST['id'] ?? null;
    // Treat empty string as null
    if ($id === '' || $id === 'null') {
        $id = null;
    }

    $class_name = $_POST['class_name'] ?? '';
    $stream = $_POST['stream'] ?? '';
    $term = $_POST['term'] ?? '';
    $year = $_POST['year'] ?? '';
    $exam_type = $_POST['exam_type'] ?? '';
    $exam_date = $_POST['exam_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $subject_id = $_POST['subject_id'] ?? '';
    $paper_number = $_POST['paper_number'] ?? '';
    $staff_id = $_POST['staff_id'] ?? '';
    $room_number = $_POST['room_number'] ?? '';

    // Convert empty room_number to NULL for database
    if (empty($room_number)) {
        $room_number = null;
    }

    if (checkForDuplicateEntry($conn, $class_name, $stream, $subject_id, $paper_number, $exam_date, $start_time, $exam_type, $id)) {
        echo json_encode(['success' => false, 'error' => 'Duplicate: This subject paper is already scheduled for this class at this exact date and time.']);
        return;
    }

    if (empty($class_name) || empty($stream) || empty($term) || empty($year) || empty($exam_type) || empty($exam_date) || empty($start_time) || empty($end_time) || empty($subject_id) || empty($paper_number) || empty($staff_id)) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields. Please fill all fields including Paper Number.']);
        return;
    }

    if ($id && is_numeric($id)) {
        // UPDATE existing entry
        $sql = "UPDATE exam_timetable_entries 
                SET exam_date = ?, start_time = ?, end_time = ?, subject_id = ?, paper_number = ?, staff_id = ?, room_number = ?
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            echo json_encode(['success' => false, 'error' => 'SQL prepare failed: ' . $conn->error]);
            return;
        }
        // Types: date(s), start_time(s), end_time(s), subject_id(i), paper_number(s), staff_id(s), room_number(s), id(i)
        $stmt->bind_param("ssssissi", $exam_date, $start_time, $end_time, $subject_id, $paper_number, $staff_id, $room_number, $id);
    } else {
        // INSERT new entry
        $sql = "INSERT INTO exam_timetable_entries 
                (class_name, stream, term, academic_year, exam_type, exam_date, start_time, end_time, subject_id, paper_number, staff_id, room_number)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            echo json_encode(['success' => false, 'error' => 'SQL prepare failed: ' . $conn->error]);
            return;
        }
        // Types: class_name(s), stream(s), term(s), year(i), exam_type(s), exam_date(s), start_time(s), end_time(s), subject_id(i), paper_number(s), staff_id(s), room_number(s)
        $stmt->bind_param("sssissssisss", $class_name, $stream, $term, $year, $exam_type, $exam_date, $start_time, $end_time, $subject_id, $paper_number, $staff_id, $room_number);
    }

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'id' => $id ? $id : $conn->insert_id]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database execute error: ' . $stmt->error]);
    }
    $stmt->close();
}

/**
 * Deletes a single exam paper entry by its ID.
 */
function deleteExamEntry($conn)
{
    $id = $_POST['id'] ?? null;
    if (empty($id)) {
        echo json_encode(['success' => false, 'error' => 'No entry ID provided.']);
        return;
    }
    $stmt = $conn->prepare("DELETE FROM exam_timetable_entries WHERE id = ?");
    if ($stmt === false) {
        echo json_encode(['success' => false, 'error' => 'SQL delete prepare failed: ' . $conn->error]);
        return;
    }
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Entry not found or already deleted.']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Database execute error: ' . $stmt->error]);
    }
    $stmt->close();
}
