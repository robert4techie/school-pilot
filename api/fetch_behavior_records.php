<?php
require_once "../auth.php";
require_once "../conn.php";


header('Content-Type: application/json');

// --- PARAMETERS ---
$search = $_GET['search'] ?? '';
$class = $_GET['class'] ?? '';
$stream = $_GET['stream'] ?? '';
$type = $_GET['type'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 15;
$offset = ($page - 1) * $records_per_page;

// --- BASE QUERY ---
$base_sql = "FROM student_behaviors b LEFT JOIN students s ON b.student_id = s.student_id";
$where_clause = " WHERE 1=1";
$params = [];
$types = "";

// --- FILTERING ---
if (!empty($search)) {
    $where_clause .= " AND (CONCAT(s.first_name, ' ', s.last_name) LIKE ? OR s.student_id LIKE ? OR b.description LIKE ?)";
    $search_param = "%$search%";
    array_push($params, $search_param, $search_param, $search_param);
    $types .= "sss";
}
if (!empty($class)) {
    $where_clause .= " AND b.class = ?";
    $params[] = $class;
    $types .= "s";
}
if (!empty($stream)) {
    $where_clause .= " AND b.stream = ?";
    $params[] = $stream;
    $types .= "s";
}
if (!empty($type)) {
    $where_clause .= " AND b.type = ?";
    $params[] = $type;
    $types .= "s";
}

// --- PAGINATION ---
$count_sql = "SELECT COUNT(b.id) as total " . $base_sql . $where_clause;
$count_stmt = $conn->prepare($count_sql);
if (!empty($types)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

// --- DATA FETCHING ---
$data_sql = "SELECT b.id, b.class, b.stream, b.description, b.date_occurred, b.reporter, b.reporter_name, b.action_taken, b.follow_up, b.created_at, b.type, CONCAT(s.first_name, ' ', s.last_name) AS student_name, s.student_id " . $base_sql . $where_clause . " ORDER BY b.date_occurred DESC LIMIT ?, ?";
$data_stmt = $conn->prepare($data_sql);

// Add limit and offset params for the data query
$limit_params = $params;
$limit_types = $types . "ii";
array_push($limit_params, $offset, $records_per_page);

if (!empty($limit_types)) {
    $data_stmt->bind_param($limit_types, ...$limit_params);
}
$data_stmt->execute();
$result = $data_stmt->get_result();
$records = $result->fetch_all(MYSQLI_ASSOC);

// --- RESPONSE ---
echo json_encode([
    'records' => $records,
    'pagination' => [
        'currentPage' => $page,
        'totalPages' => $total_pages,
        'totalRecords' => $total_records
    ]
]);

$conn->close();
?>
