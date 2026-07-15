<?php


// Set the content type to JSON for all responses.
header('Content-Type: application/json');
// Include the database connection file.
require_once '../auth.php';
require_once '../conn.php';

// --- Main API Logic ---
$action = isset($_POST['action']) ? $_POST['action'] : '';

// A simple router for API actions
switch ($action) {
    case 'get_subjects':
        getSubjects($conn);
        break;
    case 'get_staff':
        getStaff($conn);
        break;
    case 'get_time_slots':
        getTimeSlots($conn);
        break;
    case 'save_time_slots':
        saveTimeSlots($conn);
        break;
    case 'load_timetable':
        loadTimetable($conn);
        break;
    case 'save_entry':
        saveEntry($conn);
        break;
    case 'delete_entry':
        deleteEntry($conn);
        break;
    case 'clear_timetable':
        clearTimetable($conn);
        break;
    case 'get_stats':
        getStats($conn);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action specified.']);
}

$conn->close();

// --- Function Definitions ---

/**
 * CORRECTED: Fetches subjects using the correct column names (subj_id, subj_name)
 * and aliases them for the frontend JavaScript.
 */
function getSubjects($conn)
{
    // Use correct column names and alias them to 'id' and 'subject_name' as expected by the frontend.
    $sql = "SELECT subj_id AS id, subj_name AS subject_name FROM subjects ORDER BY subject_name";
    $result = $conn->query($sql);

    if (!$result) {
        echo json_encode(['success' => false, 'error' => 'Database query failed: ' . $conn->error]);
        return;
    }

    // The JavaScript expects the array in a key named 'data'.
    $subjects = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'data' => $subjects]);
}


/**
 * Fetches staff from the database.
 */
function getStaff($conn)
{
    $result = $conn->query("SELECT id, CONCAT(first_name, ' ', last_name) AS full_name FROM staff ORDER BY full_name");
    if (!$result) {
        echo json_encode(['success' => false, 'error' => $conn->error]);
        return;
    }
    $staff = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['success' => true, 'data' => $staff]);
}

/**
 * Fetches all saved time slots from the database.
 */
function getTimeSlots($conn)
{
    $stmt = $conn->prepare("SELECT start_time, end_time, slot_type, time_index FROM time_slots ORDER BY time_index ASC");
    $stmt->execute();
    $result = $stmt->get_result();
    $slots = $result->fetch_all(MYSQLI_ASSOC);
    foreach ($slots as &$slot) {
        $slot['time'] = date('g:i A', strtotime($slot['start_time'])) . ' - ' . date('g:i A', strtotime($slot['end_time']));
    }
    echo json_encode(['success' => true, 'data' => $slots]);
}

/**
 * Saves the defined time slots to the database.
 */
function saveTimeSlots($conn)
{
    $time_slots = json_decode($_POST['time_slots'] ?? '[]', true);
    $conn->begin_transaction();
    try {
        $conn->query("TRUNCATE TABLE time_slots");
        $stmt = $conn->prepare("INSERT INTO time_slots (start_time, end_time, slot_type, time_index) VALUES (?, ?, ?, ?)");
        foreach ($time_slots as $slot) {
            $stmt->bind_param("sssi", $slot['start_time'], $slot['end_time'], $slot['type'], $slot['time_index']);
            $stmt->execute();
        }
        $stmt->close();
        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => 'Failed to save time slots: ' . $e->getMessage()]);
    }
}

/**
 * CORRECTED: Loads the timetable using the correct column name (subj_id) for the subjects table.
 */
function loadTimetable($conn)
{
    $class_name = $_POST['class_name'] ?? '';
    $stream = $_POST['stream'] ?? '';
    $term = $_POST['term'] ?? '';
    $year = $_POST['year'] ?? '';

    $stmt = $conn->prepare("
        SELECT 
            te.id, te.day_of_week, te.time_index, te.room_number,
            te.paper_number, -- MODIFICATION: Added this line
            s.subj_id AS subject_id, s.subj_name AS subject_name, 
            CONCAT(st.first_name, ' ', st.last_name) AS teacher_name, 
            st.id AS staff_id
        FROM timetable_entries te
        JOIN subjects s ON te.subject_id = s.subj_id
        JOIN staff st ON te.staff_id = st.id
        WHERE te.class_name = ? AND te.stream = ? AND te.term = ? AND te.academic_year = ?
    ");
    $stmt->bind_param("sssi", $class_name, $stream, $term, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $entries = $result->fetch_all(MYSQLI_ASSOC);

    $formatted_data = [];
    foreach ($entries as $entry) {
        $key = $entry['day_of_week'] . '-' . $entry['time_index'];
        $formatted_data[$key] = $entry;
    }

    echo json_encode(['success' => true, 'data' => $formatted_data]);
}
/**
 * Saves an entry using subject_id directly.
 */
function saveEntry($conn)
{
    $class_name = $_POST['class_name'];
    $stream = $_POST['stream'];
    $term = $_POST['term'];
    $year = $_POST['academic_year'];
    $day = $_POST['day_of_week'];
    $time_index = $_POST['time_index'];
    $subject_id = $_POST['subject_id'];
    $paper_number = $_POST['paper_number']; // MODIFICATION: Get paper number
    $staff_id = $_POST['staff_id'];
    $room_number = $_POST['room_number'];

    // Conflict Checking (no changes needed here)
    $stmt = $conn->prepare("
        SELECT class_name, stream FROM timetable_entries 
        WHERE academic_year = ? AND term = ? AND day_of_week = ? AND time_index = ? AND staff_id = ? 
        AND NOT (class_name = ? AND stream = ?)
    ");
    $stmt->bind_param("ississs", $year, $term, $day, $time_index, $staff_id, $class_name, $stream);
    $stmt->execute();
    $conflict = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($conflict) {
        echo json_encode(['success' => false, 'conflicts' => ["Teacher is already assigned to {$conflict['class_name']} {$conflict['stream']} at this time."]]);
        return;
    }

    // Insert or Update
    // MODIFICATION: Added paper_number to INSERT and UPDATE
    $stmt = $conn->prepare("
        INSERT INTO timetable_entries (class_name, stream, term, academic_year, day_of_week, time_index, subject_id, paper_number, staff_id, room_number)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        subject_id = VALUES(subject_id), paper_number = VALUES(paper_number), staff_id = VALUES(staff_id), room_number = VALUES(room_number)
    ");
    // MODIFICATION: Updated bind_param string to include paper_number (s)
    $stmt->bind_param("sssisiiiss", $class_name, $stream, $term, $year, $day, $time_index, $subject_id, $paper_number, $staff_id, $room_number);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to save entry: ' . $stmt->error]);
    }
    $stmt->close();
}
function deleteEntry($conn)
{
    $class_name = $_POST['class_name'];
    $stream = $_POST['stream'];
    $term = $_POST['term'];
    $year = $_POST['year'];
    $day = $_POST['day_of_week'];
    $time_index = $_POST['time_index'];

    $stmt = $conn->prepare("DELETE FROM timetable_entries WHERE class_name = ? AND stream = ? AND term = ? AND academic_year = ? AND day_of_week = ? AND time_index = ?");
    $stmt->bind_param("sssisi", $class_name, $stream, $term, $year, $day, $time_index);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to delete entry: ' . $stmt->error]);
    }
    $stmt->close();
}

/**
 * Clears the entire timetable for a specific class.
 */
function clearTimetable($conn)
{
    $class_name = $_POST['class_name'];
    $stream = $_POST['stream'];
    $term = $_POST['term'];
    $year = $_POST['year'];

    $stmt = $conn->prepare("DELETE FROM timetable_entries WHERE class_name = ? AND stream = ? AND term = ? AND academic_year = ?");
    $stmt->bind_param("sssi", $class_name, $stream, $term, $year);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to clear timetable: ' . $stmt->error]);
    }
    $stmt->close();
}

/**
 * Fetches statistics for the dashboard.
 */
function getStats($conn)
{
    $stats = [];
    $stats['total_subjects'] = $conn->query("SELECT COUNT(DISTINCT subject_id) as count FROM timetable_entries")->fetch_assoc()['count'] ?? 0;
    $stats['total_teachers'] = $conn->query("SELECT COUNT(DISTINCT staff_id) as count FROM timetable_entries")->fetch_assoc()['count'] ?? 0;
    $stats['total_classes'] = 6;

    echo json_encode(['success' => true, 'data' => $stats]);
}
