<?php
require_once "../auth.php"; 
require_once "../conn.php";

header('Content-Type: application/json');

try {
    if (!isset($_GET['id']) || empty($_GET['id'])) {
        throw new Exception('Visit ID is required');
    }
    $visitId = $_GET['id'];

    // 1. Get visit details using a mysqlnd-compatible method
    $visitQuery = "
        SELECT 
            sbv.visit_id, sbv.student_id, sbv.visit_datetime, sbv.chief_complaint, 
            sbv.temperature, sbv.blood_pressure, sbv.assessment_notes, 
            sbv.treatment_notes, sbv.rest_time_minutes, sbv.action_taken, 
            sbv.parent_notified, sbv.parent_notification_notes, sbv.followup_required, 
            sbv.followup_date, sbv.followup_notes, sbv.attended_by, sbv.created_at,
            CONCAT(s.first_name, ' ', s.last_name) as student_name,
            s.current_class,
            s.stream
        FROM sick_bay_visits sbv
        LEFT JOIN students s ON sbv.student_id = s.student_id
        WHERE sbv.visit_id = ?
    ";

    $stmt = $conn->prepare($visitQuery);
    if ($stmt === false) {
        throw new Exception("Failed to prepare statement: " . $conn->error);
    }

    $stmt->bind_param("s", $visitId);
    $stmt->execute();

    $stmt->bind_result(
        $r_visit_id, $r_student_id, $r_visit_datetime, $r_chief_complaint,
        $r_temperature, $r_blood_pressure, $r_assessment_notes,
        $r_treatment_notes, $r_rest_time_minutes, $r_action_taken,
        $r_parent_notified, $r_parent_notification_notes, $r_followup_required,
        $r_followup_date, $r_followup_notes, $r_attended_by, $r_created_at,
        $r_student_name, $r_current_class, $r_stream
    );

    $visit = null;
    if ($stmt->fetch()) {
        $visit = [
            'visit_id' => $r_visit_id, 'student_id' => $r_student_id,
            'visit_date' => date('Y-m-d H:i:s', strtotime($r_visit_datetime)),
            'chief_complaint' => $r_chief_complaint, 'temperature' => $r_temperature,
            'blood_pressure' => $r_blood_pressure, 'assessment_notes' => $r_assessment_notes,
            'treatment_notes' => $r_treatment_notes, 'rest_time_minutes' => $r_rest_time_minutes,
            'action_taken' => $r_action_taken, 'parent_notified' => (bool)$r_parent_notified,
            'parent_notification_notes' => $r_parent_notification_notes,
            'followup_required' => (bool)$r_followup_required,
            'followup_date' => $r_followup_date ? date('Y-m-d', strtotime($r_followup_date)) : null,
            'followup_notes' => $r_followup_notes, 'attended_by' => $r_attended_by,
            'created_at' => $r_created_at, 'student_name' => $r_student_name,
            'current_class' => $r_current_class, 'stream' => $r_stream
        ];
    }
    $stmt->close();

    if ($visit === null) {
        throw new Exception('Visit not found');
    }

    // 2. Get medications for this visit
    $medicationsQuery = "SELECT medication_name, dosage, time_given FROM visit_medications WHERE visit_id = ? ORDER BY time_given";
    $stmt = $conn->prepare($medicationsQuery);
    $stmt->bind_param("s", $visitId);
    $stmt->execute();
    $stmt->bind_result($med_name, $med_dosage, $med_time);
    
    $medications = [];
    while ($stmt->fetch()) {
        $medications[] = [
            'medication_name' => $med_name, 'dosage' => $med_dosage, 'time_given' => $med_time
        ];
    }
    $stmt->close();

    echo json_encode(['success' => true, 'visit' => $visit, 'medications' => $medications]);

} catch (Exception $e) {
    error_log("Error in get_visit_details.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}

if (isset($conn)) {
    $conn->close();
}
?>