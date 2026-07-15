<?php
// process_bursary.php
require_once '../conn.php';
require_once '../auth.php';

header('Content-Type: application/json');

function sendResponse($success, $message, $data = null)
{
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

function validateInput($data)
{
    $errors = [];

    // Required field validation
    if (empty($data['student_id'])) {
        $errors['student_name'] = 'Please select a student';
    }

    if (empty($data['bursary_discount']) || !is_numeric($data['bursary_discount']) || $data['bursary_discount'] < 0) {
        $errors['bursary_discount'] = 'Please enter a valid bursary discount amount';
    }

    if (empty($data['bursary_reason'])) {
        $errors['bursary_reason'] = 'Please provide a reason for the bursary';
    }

    if (empty($data['academic_year']) || !is_numeric($data['academic_year']) || $data['academic_year'] < 2020 || $data['academic_year'] > 2030) {
        $errors['academic_year'] = 'Please enter a valid academic year';
    }

    if (empty($data['term'])) {
        $errors['term'] = 'Please select a term';
    }

    return $errors;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Handle different POST actions
        $action = $_POST['action'] ?? 'save';

        if ($action === 'save') {
            // Validate input
            $errors = validateInput($_POST);

            if (!empty($errors)) {
                sendResponse(false, 'Please correct the following errors:', $errors);
            }

            // Get student and fee information
            $student_id = mysqli_real_escape_string($conn, $_POST['student_id']);
            $bursary_discount = (int)$_POST['bursary_discount'];
            $bursary_reason = mysqli_real_escape_string($conn, $_POST['bursary_reason']);
            $academic_year = (int)$_POST['academic_year'];
            $term = mysqli_real_escape_string($conn, $_POST['term']);
            $bursary_id = $_POST['bursary_id'] ?? null;

            // Get student details including section
            $student_query = "SELECT student_id, CONCAT(first_name, ' ', last_name) as full_name, current_class, stream, section 
                FROM students WHERE student_id = ?";
            $stmt = mysqli_prepare($conn, $student_query);
            mysqli_stmt_bind_param($stmt, "s", $student_id);
            mysqli_stmt_execute($stmt);
            $student_result = mysqli_stmt_get_result($stmt);

            if (mysqli_num_rows($student_result) === 0) {
                sendResponse(false, 'Student not found');
            }

            $student = mysqli_fetch_assoc($student_result);

            // Get student section (Day/Boarding)
            $student_type = $student['section'];

            // Get fee amount for the student's class AND student type
            $fee_query = "SELECT amount FROM fee_structures 
             WHERE class_name = ? AND student_type = ? AND term = ? AND year = ?";
            $stmt = mysqli_prepare($conn, $fee_query);
            mysqli_stmt_bind_param($stmt, "sssi", $student['current_class'], $student_type, $term, $academic_year);
            mysqli_stmt_execute($stmt);
            $fee_result = mysqli_stmt_get_result($stmt);

            if (mysqli_num_rows($fee_result) === 0) {
                $student_type_label = $student_type === 'Day' ? 'Day' : 'Boarding';
                sendResponse(false, "Fee structure not found for {$student['current_class']} ({$student_type_label}) - {$term} {$academic_year}");
            }

            $fee_structure = mysqli_fetch_assoc($fee_result);
            $fees_amount = $fee_structure['amount'];

            // Validate bursary discount doesn't exceed fees
            if ($bursary_discount > $fees_amount) {
                sendResponse(false, 'Bursary discount cannot exceed the total fees amount');
            }

            $amount_to_pay = $fees_amount - $bursary_discount;

            // Check if this is an update or new record
            if (!empty($bursary_id)) {
                // UPDATE existing record
                $bursary_id = (int)$bursary_id;

                // Check for duplicate (excluding current record)
                $duplicate_query = "SELECT id FROM fees_bursaries 
                                  WHERE student_id = ? AND term = ? AND academic_year = ? AND id != ?";
                $stmt = mysqli_prepare($conn, $duplicate_query);
                mysqli_stmt_bind_param($stmt, "ssii", $student_id, $term, $academic_year, $bursary_id);
                mysqli_stmt_execute($stmt);
                $duplicate_result = mysqli_stmt_get_result($stmt);

                if (mysqli_num_rows($duplicate_result) > 0) {
                    sendResponse(false, 'A bursary record already exists for this student in the selected term and year');
                }

                // Update the record
                $update_query = "UPDATE fees_bursaries SET 
                               student_id = ?, fees_amount = ?, bursary_discount = ?, 
                               amount_to_pay = ?, bursary_reason = ?, term = ?, 
                               academic_year = ?, updated_at = NOW()
                               WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param(
                    $stmt,
                    "siiiisii",
                    $student_id,
                    $fees_amount,
                    $bursary_discount,
                    $amount_to_pay,
                    $bursary_reason,
                    $term,
                    $academic_year,
                    $bursary_id
                );

                if (mysqli_stmt_execute($stmt)) {
                    sendResponse(true, 'Bursary record updated successfully');
                } else {
                    sendResponse(false, 'Failed to update bursary record: ' . mysqli_error($conn));
                }
            } else {
                // INSERT new record

                // Check for duplicate bursary record
                $duplicate_query = "SELECT id FROM fees_bursaries 
                                  WHERE student_id = ? AND term = ? AND academic_year = ?";
                $stmt = mysqli_prepare($conn, $duplicate_query);
                mysqli_stmt_bind_param($stmt, "ssi", $student_id, $term, $academic_year);
                mysqli_stmt_execute($stmt);
                $duplicate_result = mysqli_stmt_get_result($stmt);

                if (mysqli_num_rows($duplicate_result) > 0) {
                    sendResponse(false, 'A bursary record already exists for this student in the selected term and year');
                }

                // Insert new record
                $insert_query = "INSERT INTO fees_bursaries 
                               (student_id, fees_amount, bursary_discount, amount_to_pay, 
                                bursary_reason, term, academic_year, created_at) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = mysqli_prepare($conn, $insert_query);
                mysqli_stmt_bind_param(
                    $stmt,
                    "siiiisi",
                    $student_id,
                    $fees_amount,
                    $bursary_discount,
                    $amount_to_pay,
                    $bursary_reason,
                    $term,
                    $academic_year
                );

                if (mysqli_stmt_execute($stmt)) {
                    sendResponse(true, 'Bursary record saved successfully');
                } else {
                    sendResponse(false, 'Failed to save bursary record: ' . mysqli_error($conn));
                }
            }
        } elseif ($action === 'delete') {
            // DELETE record
            $bursary_id = $_POST['id'] ?? null;

            if (empty($bursary_id) || !is_numeric($bursary_id)) {
                sendResponse(false, 'Invalid bursary ID');
            }

            $bursary_id = (int)$bursary_id;

            // Check if record exists
            $check_query = "SELECT id FROM fees_bursaries WHERE id = ?";
            $stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($stmt, "i", $bursary_id);
            mysqli_stmt_execute($stmt);
            $check_result = mysqli_stmt_get_result($stmt);

            if (mysqli_num_rows($check_result) === 0) {
                sendResponse(false, 'Bursary record not found');
            }

            // Delete the record
            $delete_query = "DELETE FROM fees_bursaries WHERE id = ?";
            $stmt = mysqli_prepare($conn, $delete_query);
            mysqli_stmt_bind_param($stmt, "i", $bursary_id);

            if (mysqli_stmt_execute($stmt)) {
                if (mysqli_affected_rows($conn) > 0) {
                    sendResponse(true, 'Bursary record deleted successfully');
                } else {
                    sendResponse(false, 'No record was deleted');
                }
            } else {
                sendResponse(false, 'Failed to delete bursary record: ' . mysqli_error($conn));
            }
        } else {
            sendResponse(false, 'Invalid action');
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {

        // Handle GET requests for fetching data
        $action = $_GET['action'] ?? '';

        if ($action === 'get_students') {
            // Fetch all active students for dropdown
            $query = "SELECT student_id, CONCAT(first_name, ' ', last_name) as full_name, 
                    current_class, stream, section 
             FROM students 
             WHERE status = 'active' 
             ORDER BY first_name, last_name";
            $result = mysqli_query($conn, $query);

            $students = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $students[] = $row;
            }

            sendResponse(true, 'Students fetched successfully', $students);
        } elseif ($action === 'get_student_details') {
            // Fetch specific student details with fee information
            $student_id = $_GET['student_id'] ?? '';
            $term = $_GET['term'] ?? '';
            $year = $_GET['year'] ?? '';

            if (empty($student_id)) {
                sendResponse(false, 'Student ID is required');
            }

            // Get student details including section (Day/Boarding)
            $student_query = "SELECT student_id, CONCAT(first_name, ' ', last_name) as full_name, 
                           current_class, stream, section 
                    FROM students WHERE student_id = ?";
            $stmt = mysqli_prepare($conn, $student_query);
            mysqli_stmt_bind_param($stmt, "s", $student_id);
            mysqli_stmt_execute($stmt);
            $student_result = mysqli_stmt_get_result($stmt);

            if (mysqli_num_rows($student_result) === 0) {
                sendResponse(false, 'Student not found');
            }

            $student = mysqli_fetch_assoc($student_result);
            $student_type = $student['section']; // Day or Boarding

            // Get fee amount if term and year are provided
            $fees_amount = 0;
            if (!empty($term) && !empty($year)) {
                // Get fee amount based on student's class AND student type (section)
                $fee_query = "SELECT amount FROM fee_structures 
                     WHERE class_name = ? AND student_type = ? AND term = ? AND year = ?";
                $stmt = mysqli_prepare($conn, $fee_query);
                mysqli_stmt_bind_param($stmt, "sssi", $student['current_class'], $student_type, $term, $year);
                mysqli_stmt_execute($stmt);
                $fee_result = mysqli_stmt_get_result($stmt);

                if (mysqli_num_rows($fee_result) > 0) {
                    $fee_structure = mysqli_fetch_assoc($fee_result);
                    $fees_amount = $fee_structure['amount'];
                }
            }

            $student['fees_amount'] = $fees_amount;
            $student['student_type'] = $student_type;

            sendResponse(true, 'Student details fetched successfully', $student);
        } elseif ($action === 'get_bursaries') {
            // Fetch all bursary records for display
            $query = "SELECT fb.*, s.first_name, s.last_name, s.current_class, s.stream,
                            CONCAT(s.first_name, ' ', s.last_name) as student_name
                     FROM fees_bursaries fb
                     JOIN students s ON fb.student_id = s.student_id
                     ORDER BY fb.created_at DESC";
            $result = mysqli_query($conn, $query);

            $bursaries = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $bursaries[] = $row;
            }

            sendResponse(true, 'Bursaries fetched successfully', $bursaries);
        } elseif ($action === 'get_bursary') {
            // Fetch single bursary record for editing
            $bursary_id = $_GET['id'] ?? '';

            if (empty($bursary_id) || !is_numeric($bursary_id)) {
                sendResponse(false, 'Invalid bursary ID');
            }

            $bursary_id = (int)$bursary_id;

            $query = "SELECT fb.*, s.first_name, s.last_name, s.current_class, s.stream,
                            CONCAT(s.first_name, ' ', s.last_name) as student_name
                     FROM fees_bursaries fb
                     JOIN students s ON fb.student_id = s.student_id
                     WHERE fb.id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $bursary_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            if (mysqli_num_rows($result) === 0) {
                sendResponse(false, 'Bursary record not found');
            }

            $bursary = mysqli_fetch_assoc($result);
            sendResponse(true, 'Bursary record fetched successfully', $bursary);
        } else {
            sendResponse(false, 'Invalid action');
        }
    } else {
        sendResponse(false, 'Invalid request method');
    }
} catch (Exception $e) {
    sendResponse(false, 'An error occurred: ' . $e->getMessage());
}

mysqli_close($conn);
