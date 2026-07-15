<?php
require_once "../auth.php";
require_once "../conn.php";

header('Content-Type: application/json');

// Get the raw POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid input data.']);
    exit();
}

$visitId = $data['visit_id'] ?? null;

if (!$visitId) {
    echo json_encode(['success' => false, 'message' => 'Visit ID is missing.']);
    exit();
}

// Begin a transaction
$conn->begin_transaction();

try {
    // 1. Update the main sick_bay_visits table
    $updateVisitQuery = "
        UPDATE sick_bay_visits SET
            chief_complaint = ?,
            temperature = ?,
            blood_pressure = ?,
            assessment_notes = ?,
            treatment_notes = ?,
            rest_time_minutes = ?,
            action_taken = ?,
            parent_notified = ?,
            parent_notification_notes = ?,
            followup_required = ?,
            followup_date = ?,
            followup_notes = ?
        WHERE visit_id = ?
    ";

    $stmt = $conn->prepare($updateVisitQuery);
    if ($stmt === false) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    // Set values, handling potential nulls
    $temperature = !empty($data['temperature']) ? $data['temperature'] : null;
    $rest_time = !empty($data['rest_time_minutes']) ? $data['rest_time_minutes'] : null;
    $followup_date = !empty($data['followup_date']) ? $data['followup_date'] : null;
    $parent_notified = isset($data['parent_notified']) ? 1 : 0;
    $followup_required = isset($data['followup_required']) ? 1 : 0;

    $stmt->bind_param(
        "sssssisisiisi",
        $data['chief_complaint'],
        $temperature,
        $data['blood_pressure'],
        $data['assessment_notes'],
        $data['treatment_notes'],
        $rest_time,
        $data['action_taken'],
        $parent_notified,
        $data['parent_notification_notes'],
        $followup_required,
        $followup_date,
        $data['followup_notes'],
        $visitId
    );

    $stmt->execute();
    $stmt->close();

    // 2. Delete existing medications for this visit
    $deleteMedsQuery = "DELETE FROM visit_medications WHERE visit_id = ?";
    $stmt = $conn->prepare($deleteMedsQuery);
    $stmt->bind_param("i", $visitId);
    $stmt->execute();
    $stmt->close();

    // 3. Insert the new list of medications, if any
    if (!empty($data['medications']) && is_array($data['medications'])) {
        $insertMedsQuery = "INSERT INTO visit_medications (visit_id, medication_name, dosage, time_given) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($insertMedsQuery);

        foreach ($data['medications'] as $med) {
            if (!empty($med['name'])) {
                $stmt->bind_param(
                    "isss",
                    $visitId,
                    $med['name'],
                    $med['dosage'],
                    $med['time']
                );
                $stmt->execute();
            }
        }
        $stmt->close();
    }

    // If everything was successful, commit the transaction
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Visit record updated successfully!'
    ]);

} catch (Exception $e) {
    // If any error occurred, roll back the transaction
    $conn->rollback();
    error_log("Update failed: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while updating the record.',
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>
