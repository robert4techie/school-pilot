<?php
require_once "../auth.php";
require_once "../conn.php";


header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Only POST method allowed']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['visit_id'])) {
    echo json_encode(['success' => false, 'message' => 'Visit ID is required']);
    exit;
}

$visitId = $data['visit_id'];

// Begin transaction
$conn->begin_transaction();

try {
    // Delete medications first (foreign key constraint)
    $deleteMedQuery = "DELETE FROM visit_medications WHERE visit_id = ?";
    $stmt = $conn->prepare($deleteMedQuery);
    if ($stmt === false) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $visitId);
    $stmt->execute();
    $stmt->close();
    
    // Delete the visit
    $deleteVisitQuery = "DELETE FROM sick_bay_visits WHERE visit_id = ?";
    $stmt = $conn->prepare($deleteVisitQuery);
    if ($stmt === false) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $visitId);
    $stmt->execute();
    
    if ($stmt->affected_rows === 0) {
        throw new Exception('Visit not found or already deleted');
    }
    
    $stmt->close();
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Visit record deleted successfully'
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("Delete visit error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting visit: ' . $e->getMessage()
    ]);
}

$conn->close();
?>

<?php
// Create: get_visit_details.php

require_once "conn.php";
require_once "auth.php";

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Visit ID is required']);
    exit;
}

$visitId = $_GET['id'];

try {
    // Get visit details
    $query = "SELECT sbv.*, 
                     CONCAT_WS(' ', s.first_name, s.last_name) as student_name, 
                     s.current_class, s.stream,
                     u.name as attended_by
              FROM sick_bay_visits sbv
              LEFT JOIN students s ON sbv.student_id = s.student_id
              LEFT JOIN users u ON sbv.attended_by_user_id = u.user_id
              WHERE sbv.visit_id = ?";
    
    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $visitId);
    $stmt->execute();
    $result = $stmt->get_result();
    $visit = $result->fetch_assoc();
    $stmt->close();
    
    if (!$visit) {
        echo json_encode(['success' => false, 'message' => 'Visit not found']);
        exit;
    }
    
    // Format boolean values
    $visit['parent_notified'] = (bool)$visit['parent_notified'];
    $visit['followup_required'] = (bool)$visit['followup_required'];
    
    // Get medications for this visit
    $medQuery = "SELECT * FROM visit_medications WHERE visit_id = ? ORDER BY time_given";
    $stmt = $conn->prepare($medQuery);
    if ($stmt === false) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $visitId);
    $stmt->execute();
    $result = $stmt->get_result();
    $medications = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'visit' => $visit,
        'medications' => $medications
    ]);
    
} catch (Exception $e) {
    error_log("Get visit details error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching visit details: ' . $e->getMessage()
    ]);
}

$conn->close();
?>

<?php
// Create: get_class.php (if you don't have it)

require_once "conn.php";
require_once "auth.php";

header('Content-Type: application/json');

try {
    $query = "SELECT DISTINCT current_class FROM students WHERE current_class IS NOT NULL AND current_class != '' ORDER BY current_class";
    $result = $conn->query($query);
    
    if (!$result) {
        throw new Exception("Query failed: " . $conn->error);
    }
    
    $classes = [];
    while ($row = $result->fetch_assoc()) {
        $classes[] = $row['current_class'];
    }
    
    echo json_encode([
        'success' => true,
        'classes' => $classes
    ]);
    
} catch (Exception $e) {
    error_log("Get classes error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching classes: ' . $e->getMessage()
    ]);
}

$conn->close();
?>

<?php
// Create: get_sick_bay_stats.php (if you don't have it)

require_once "conn.php";
require_once "auth.php";

header('Content-Type: application/json');

try {
    $currentMonth = date('Y-m');
    
    // Total visits this month
    $totalQuery = "SELECT COUNT(*) as total FROM sick_bay_visits WHERE DATE_FORMAT(visit_datetime, '%Y-%m') = ?";
    $stmt = $conn->prepare($totalQuery);
    $stmt->bind_param("s", $currentMonth);
    $stmt->execute();
    $stmt->bind_result($totalVisits);
    $stmt->fetch();
    $stmt->close();
    
    // Fever cases (temperature >= 37.5)
    $feverQuery = "SELECT COUNT(*) as fever FROM sick_bay_visits WHERE DATE_FORMAT(visit_datetime, '%Y-%m') = ? AND temperature >= 37.5";
    $stmt = $conn->prepare($feverQuery);
    $stmt->bind_param("s", $currentMonth);
    $stmt->execute();
    $stmt->bind_result($feverCases);
    $stmt->fetch();
    $stmt->close();
    
    // Hospital referrals
    $referralQuery = "SELECT COUNT(*) as referrals FROM sick_bay_visits WHERE DATE_FORMAT(visit_datetime, '%Y-%m') = ? AND action_taken = 'ReferredToHospital'";
    $stmt = $conn->prepare($referralQuery);
    $stmt->bind_param("s", $currentMonth);
    $stmt->execute();
    $stmt->bind_result($hospitalReferrals);
    $stmt->fetch();
    $stmt->close();
    
    // Students sent home
    $homeQuery = "SELECT COUNT(*) as home FROM sick_bay_visits WHERE DATE_FORMAT(visit_datetime, '%Y-%m') = ? AND action_taken = 'SentHome'";
    $stmt = $conn->prepare($homeQuery);
    $stmt->bind_param("s", $currentMonth);
    $stmt->execute();
    $stmt->bind_result($sentHome);
    $stmt->fetch();
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'total_visits' => (int)$totalVisits,
            'fever_cases' => (int)$feverCases,
            'hospital_referrals' => (int)$hospitalReferrals,
            'sent_home' => (int)$sentHome
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Get stats error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching statistics: ' . $e->getMessage()
    ]);
}

$conn->close();
?>