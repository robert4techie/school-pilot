<?php

require_once "../auth.php";
require_once "../conn.php";

header('Content-Type: application/json');

try {
    // Check if we should return all visits (for client-side filtering)
    $returnAll = isset($_GET['all']) && $_GET['all'] == '1';
    
    if ($returnAll) {
        // Return all visits for client-side filtering
        $query = "SELECT sbv.visit_id, sbv.student_id, 
                         CONCAT_WS(' ', s.first_name, s.last_name) as student_name, 
                         s.current_class, s.stream, 
                         sbv.visit_datetime as visit_date, 
                         sbv.chief_complaint, sbv.temperature, sbv.blood_pressure, 
                         sbv.assessment_notes, sbv.treatment_notes, sbv.rest_time_minutes, 
                         sbv.action_taken, sbv.parent_notified, sbv.parent_notification_notes, 
                         sbv.followup_required, sbv.followup_date, sbv.followup_notes, 
                         sbv.attended_by, sbv.created_at
                  FROM sick_bay_visits sbv 
                  LEFT JOIN students s ON sbv.student_id = s.student_id 
                  ORDER BY sbv.visit_datetime DESC";
        
        $result = $conn->query($query);
        if (!$result) {
            throw new Exception("Query failed: " . $conn->error);
        }
        
        $visits = [];
        while ($row = $result->fetch_assoc()) {
            $row['parent_notified'] = (bool)$row['parent_notified'];
            $row['followup_required'] = (bool)$row['followup_required'];
            $visits[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'visits' => $visits,
            'total' => count($visits)
        ]);
        exit;
    }
    
    // Your existing server-side filtering code continues here...
    // Get filters from request
    $dateFrom = $_GET['dateFrom'] ?? '';
    $dateTo = $_GET['dateTo'] ?? '';
    $classFilter = $_GET['classFilter'] ?? '';
    $actionFilter = $_GET['actionFilter'] ?? '';
    $searchStudent = $_GET['searchStudent'] ?? '';
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 10);
    $export = $_GET['export'] ?? false;

    // Build WHERE clause
    $whereConditions = [];
    $params = [];
    $paramTypes = '';

    if (!empty($dateFrom)) {
        $whereConditions[] = "DATE(sbv.visit_datetime) >= ?";
        $params[] = $dateFrom;
        $paramTypes .= 's';
    }
    if (!empty($dateTo)) {
        $whereConditions[] = "DATE(sbv.visit_datetime) <= ?";
        $params[] = $dateTo;
        $paramTypes .= 's';
    }
    if (!empty($classFilter)) {
        $whereConditions[] = "s.current_class = ?";
        $params[] = $classFilter;
        $paramTypes .= 's';
    }
    if (!empty($actionFilter)) {
        $whereConditions[] = "sbv.action_taken = ?";
        $params[] = $actionFilter;
        $paramTypes .= 's';
    }
    if (!empty($searchStudent)) {
        $whereConditions[] = "(s.student_id LIKE ? OR CONCAT_WS(' ', s.first_name, s.last_name) LIKE ?)";
        $searchTerm = "%$searchStudent%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $paramTypes .= 'ss';
    }

    $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : '';
    $baseQuery = "FROM sick_bay_visits sbv LEFT JOIN students s ON sbv.student_id = s.student_id $whereClause";

    // Get total count
    $countQuery = "SELECT COUNT(*) as total $baseQuery";
    $stmt = $conn->prepare($countQuery);
    if (!empty($paramTypes)) {
        $stmt->bind_param($paramTypes, ...$params);
    }
    $stmt->execute();
    $stmt->bind_result($totalRecords);
    $stmt->fetch();
    $stmt->close();

    // Main query
    $selectFields = "sbv.visit_id, sbv.student_id, CONCAT_WS(' ', s.first_name, s.last_name) as student_name, s.current_class, s.stream, sbv.visit_datetime as visit_date, sbv.chief_complaint, sbv.temperature, sbv.blood_pressure, sbv.assessment_notes, sbv.treatment_notes, sbv.rest_time_minutes, sbv.action_taken, sbv.parent_notified, sbv.parent_notification_notes, sbv.followup_required, sbv.followup_date, sbv.followup_notes, sbv.attended_by, sbv.created_at";
    $mainQuery = "SELECT $selectFields $baseQuery ORDER BY sbv.visit_datetime DESC";

    $mainParams = $params;
    $mainParamTypes = $paramTypes;

    if (!$export) {
        $offset = ($page - 1) * $limit;
        $mainQuery .= " LIMIT ? OFFSET ?";
        $mainParams[] = $limit;
        $mainParams[] = $offset;
        $mainParamTypes .= 'ii';
    }

    $stmt = $conn->prepare($mainQuery);
    if (!empty($mainParamTypes)) {
        $stmt->bind_param($mainParamTypes, ...$mainParams);
    }
    $stmt->execute();

    $result = $stmt->get_result();
    $visits = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Format data
    foreach ($visits as &$visit) {
        $visit['parent_notified'] = (bool)$visit['parent_notified'];
        $visit['followup_required'] = (bool)$visit['followup_required'];
    }

    echo json_encode([
        'success' => true,
        'visits' => $visits,
        'total' => (int)$totalRecords,
        'page' => $page,
        'limit' => $limit
    ]);

} catch (Exception $e) {
    error_log("CRITICAL ERROR in get_sick_bay_visits.php: " . $e->getMessage() . " on line " . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'An error occurred while fetching visit data. Please contact support.']);
}

$conn->close();
?>
