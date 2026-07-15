<?php
header('Content-Type: application/json');
require_once "conn.php"; // Your database connection

// --- Handle Single Detail Request for Modal ---
if (isset($_POST['get_details']) && isset($_POST['visit_id'])) {
    $visit_id = intval($_POST['visit_id']);
    
    // Fetch main visit details
    $sql_main = "SELECT v.*, s.first_name, s.last_name, s.current_class, s.stream 
                 FROM sick_bay_visits v
                 JOIN students s ON v.student_id = s.student_id
                 WHERE v.visit_id = ?";
    $stmt_main = $conn->prepare($sql_main);
    $stmt_main->bind_param("i", $visit_id);
    $stmt_main->execute();
    $result_main = $stmt_main->get_result();
    $visit_data = $result_main->fetch_assoc();

    if ($visit_data) {
        $visit_data['full_name'] = $visit_data['first_name'] . ' ' . $visit_data['last_name'];
        $visit_data['class_info'] = $visit_data['current_class'] . ' ' . $visit_data['stream'];

        // Fetch medications for the visit
        $sql_meds = "SELECT * FROM visit_medications WHERE visit_id = ?";
        $stmt_meds = $conn->prepare($sql_meds);
        $stmt_meds->bind_param("i", $visit_id);
        $stmt_meds->execute();
        $result_meds = $stmt_meds->get_result();
        $medications = [];
        while ($row = $result_meds->fetch_assoc()) {
            $medications[] = $row;
        }
        $visit_data['medications'] = $medications;
        
        echo json_encode(['success' => true, 'data' => $visit_data]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Visit not found.']);
    }
    $conn->close();
    exit;
}


// --- Handle DataTables Server-Side Request ---
$draw = $_POST['draw'];
$start = $_POST['start'];
$length = $_POST['length'];
$searchValue = $_POST['search']['value']; // Global search from DataTables

// Custom filters
$searchStudent = $_POST['searchStudent'] ?? '';
$dateFrom = $_POST['dateFrom'] ?? '';
$dateTo = $_POST['dateTo'] ?? '';
$actionFilter = $_POST['actionFilter'] ?? '';

// Base query
$baseQuery = "FROM sick_bay_visits v JOIN students s ON v.student_id = s.student_id";

// Filtering
$whereClause = " WHERE 1=1 ";
$params = [];
$param_types = '';

if (!empty($searchStudent)) {
    $whereClause .= " AND (CONCAT(s.first_name, ' ', s.last_name) LIKE ? OR v.student_id LIKE ?)";
    $likeSearch = "%" . $searchStudent . "%";
    array_push($params, $likeSearch, $likeSearch);
    $param_types .= 'ss';
}
if (!empty($dateFrom)) {
    $whereClause .= " AND v.visit_datetime >= ?";
    $params[] = $dateFrom . ' 00:00:00';
    $param_types .= 's';
}
if (!empty($dateTo)) {
    $whereClause .= " AND v.visit_datetime <= ?";
    $params[] = $dateTo . ' 23:59:59';
    $param_types .= 's';
}
if (!empty($actionFilter)) {
    $whereClause .= " AND v.action_taken = ?";
    $params[] = $actionFilter;
    $param_types .= 's';
}

// Total records
$totalRecordsQuery = "SELECT COUNT(*) as total " . $baseQuery;
$totalRecordsResult = $conn->query($totalRecordsQuery);
$totalRecords = $totalRecordsResult->fetch_assoc()['total'];

// Filtered records
$filteredRecordsQuery = "SELECT COUNT(*) as total " . $baseQuery . $whereClause;
$stmt_filtered = $conn->prepare($filteredRecordsQuery);
if (count($params) > 0) {
    $stmt_filtered->bind_param($param_types, ...$params);
}
$stmt_filtered->execute();
$filteredRecordsResult = $stmt_filtered->get_result();
$totalDisplayRecords = $filteredRecordsResult->fetch_assoc()['total'];


// Data for the current page
$columns = ['visit_id', 'visit_datetime', 'v.student_id', 'full_name', 'class_info', 'chief_complaint', 'action_taken'];
$orderByColumn = $columns[$_POST['order'][0]['column']];
$orderByDirection = $_POST['order'][0]['dir'];

$dataQuery = "SELECT v.visit_id, v.visit_datetime, v.student_id, CONCAT(s.first_name, ' ', s.last_name) as full_name, CONCAT(s.current_class, ' ', s.stream) as class_info, v.chief_complaint, v.action_taken " . $baseQuery . $whereClause . " ORDER BY $orderByColumn $orderByDirection LIMIT ?, ?";

$stmt_data = $conn->prepare($dataQuery);
$limit_params = $params;
array_push($limit_params, $start, $length);
$limit_param_types = $param_types . 'ii';

$stmt_data->bind_param($limit_param_types, ...$limit_params);
$stmt_data->execute();
$result = $stmt_data->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $row['actions'] = '
        <a href="#" class="btn-action view" data-id="'.$row['visit_id'].'" title="View Details"><i class="fas fa-eye"></i></a>
        <a href="edit_visit.php?id='.$row['visit_id'].'" class="btn-action edit" title="Edit"><i class="fas fa-edit"></i></a>
        <a href="#" class="btn-action delete" data-id="'.$row['visit_id'].'" title="Delete"><i class="fas fa-trash"></i></a>
    ';
    $data[] = $row;
}

// Final JSON output
$output = [
    "draw" => intval($draw),
    "recordsTotal" => intval($totalRecords),
    "recordsFiltered" => intval($totalDisplayRecords),
    "data" => $data
];

echo json_encode($output);
$conn->close();
?>
