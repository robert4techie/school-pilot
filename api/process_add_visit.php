<?php
/**
 * process_add_visit.php
 *
 * FIX: Added CSRF token validation — matches pattern already used in view_students.php.
 *
 * FIX: Moved $stmt_visit->close() INSIDE the try block so it only runs
 *      after a successful prepare, preventing fatal "call on non-object" errors
 *      if the DB fails before the statement is created.
 *
 * FIX: DB error details no longer sent to the client.
 */

header('Content-Type: application/json');
require_once '../auth.php';
require_once '../conn.php';

// ── CSRF Validation ────────────────────────────────────────────────────────────
$submitted_token = $_POST['csrf_token'] ?? '';
if (empty($submitted_token) || !hash_equals($_SESSION['csrf_token'] ?? '', $submitted_token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page and try again.']);
    exit;
}

// ── Required Field Validation ─────────────────────────────────────────────────
if (empty($_POST['student_id']) || empty($_POST['visitDate']) || empty($_POST['visitTime']) || empty($_POST['attendedBy'])) {
    echo json_encode(['success' => false, 'message' => 'Student, visit date/time, and attended by are required fields.']);
    exit;
}

// ── Begin Transaction ─────────────────────────────────────────────────────────
$conn->begin_transaction();

try {
    $student_id     = trim($_POST['student_id']);
    $visit_datetime = $_POST['visitDate'] . ' ' . $_POST['visitTime'] . ':00';

    // Combine symptom checkboxes with free-text
    $symptoms_list  = isset($_POST['symptoms']) && is_array($_POST['symptoms'])
                      ? implode(', ', array_map('trim', $_POST['symptoms']))
                      : '';
    $other_symptoms = !empty($_POST['otherSymptoms']) ? trim($_POST['otherSymptoms']) : '';
    $full_complaint = trim(
        $symptoms_list
        . (!empty($symptoms_list) && !empty($other_symptoms) ? ', ' : '')
        . $other_symptoms
    );

    $temperature       = !empty($_POST['temperature'])     ? (float)$_POST['temperature']        : null;
    $blood_pressure    = !empty($_POST['bloodPressure'])   ? trim($_POST['bloodPressure'])        : null;
    $assessment_notes  = !empty($_POST['assessmentNotes']) ? trim($_POST['assessmentNotes'])      : null;
    $treatment_notes   = !empty($_POST['treatmentNotes'])  ? trim($_POST['treatmentNotes'])       : null;
    $rest_time         = !empty($_POST['restTime'])        ? (int)$_POST['restTime']              : null;
    $action_taken      = trim($_POST['actionTaken'] ?? '');

    $parent_notified   = isset($_POST['parentNotified']) ? 1 : 0;
    $parent_notes      = ($parent_notified && !empty($_POST['parentNotes']))
                         ? trim($_POST['parentNotes']) : null;

    $followup_required = isset($_POST['followupRequired']) ? 1 : 0;
    $followup_date     = ($followup_required && !empty($_POST['followupDate']))
                         ? $_POST['followupDate'] : null;
    $followup_notes    = ($followup_required && !empty($_POST['followupNotes']))
                         ? trim($_POST['followupNotes']) : null;

    $attended_by = trim($_POST['attendedBy']);

    // ── Insert main visit record ───────────────────────────────────────────────
    $sql_visit = "INSERT INTO sick_bay_visits (
                    student_id, visit_datetime, chief_complaint, temperature, blood_pressure,
                    assessment_notes, treatment_notes, rest_time_minutes, action_taken,
                    parent_notified, parent_notification_notes, followup_required, followup_date,
                    followup_notes, attended_by
                  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt_visit = $conn->prepare($sql_visit);
    if (!$stmt_visit) {
        throw new Exception('Failed to prepare visit statement.');
    }

    $stmt_visit->bind_param(
        "sssdssssissssss",
        $student_id,
        $visit_datetime,
        $full_complaint,
        $temperature,
        $blood_pressure,
        $assessment_notes,
        $treatment_notes,
        $rest_time,
        $action_taken,
        $parent_notified,
        $parent_notes,
        $followup_required,
        $followup_date,
        $followup_notes,
        $attended_by
    );

    if (!$stmt_visit->execute()) {
        throw new Exception('Failed to insert visit record.');
    }
    $visit_id = $conn->insert_id;
    $stmt_visit->close(); // FIX: now safely inside try after successful prepare

    // ── Insert medications ─────────────────────────────────────────────────────
    if (isset($_POST['medications']) && is_array($_POST['medications'])) {
        $sql_med = "INSERT INTO visit_medications (visit_id, medication_name, dosage, time_given)
                    VALUES (?, ?, ?, ?)";
        $stmt_med = $conn->prepare($sql_med);
        if (!$stmt_med) {
            throw new Exception('Failed to prepare medication statement.');
        }

        foreach ($_POST['medications'] as $index => $med_name) {
            $med_name = trim($med_name);
            if (!empty($med_name)) {
                $dosage     = isset($_POST['dosages'][$index])        ? trim($_POST['dosages'][$index])        : null;
                $time_given = !empty($_POST['medicationTimes'][$index]) ? trim($_POST['medicationTimes'][$index]) : null;

                $stmt_med->bind_param("isss", $visit_id, $med_name, $dosage, $time_given);
                if (!$stmt_med->execute()) {
                    throw new Exception('Failed to insert medication record.');
                }
            }
        }
        $stmt_med->close();
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Visit record saved successfully.']);

} catch (Exception $e) {
    $conn->rollback();
    error_log('process_add_visit.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while saving the visit. Please try again.']);
}

$conn->close();
?>
