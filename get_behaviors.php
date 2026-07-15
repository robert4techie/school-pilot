<?php
// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "school_pilotdb";

// Create connection
$conn = mysqli_connect($servername, $username, $password, $dbname);

// Check connection
if (!$conn) {
    die(json_encode([
        'status' => 'error',
        'message' => 'Connection failed: ' . mysqli_connect_error()
    ]));
}

// Default parameters
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
$offset = ($page - 1) * $limit;

// Build WHERE clause based on filters
$where_clauses = [];
$params = [];
$types = "";

// Student filter
if (!empty($_GET['student_id'])) {
    $where_clauses[] = "b.student_id = ?";
    $params[] = $_GET['student_id'];
    $types .= "i";
}

// Class filter
if (!empty($_GET['class'])) {
    $where_clauses[] = "b.class = ?";
    $params[] = $_GET['class'];
    $types .= "s";
}

// Stream filter
if (!empty($_GET['stream'])) {
    $where_clauses[] = "b.stream = ?";
    $params[] = $_GET['stream'];
    $types .= "s";
}

// Behavior type filter
if (!empty($_GET['type'])) {
    $where_clauses[] = "b.type = ?";
    $params[] = $_GET['type'];
    $types .= "s";
}

// Date range filter
if (!empty($_GET['date_from']) && !empty($_GET['date_to'])) {
    $where_clauses[] = "b.date_occurred BETWEEN ? AND ?";
    $params[] = $_GET['date_from'];
    $params[] = $_GET['date_to'];
    $types .= "ss";
}

// Build the WHERE clause string
$where_sql = "";
if (count($where_clauses) > 0) {
    $where_sql = " WHERE " . implode(" AND ", $where_clauses);
}

// Build the SQL query with JOIN to get student names
$sql = "SELECT b.*, 
               s.first_name, 
               s.last_name, 
               s.admission_number,
               s.photo_url
        FROM student_behaviors b
        LEFT JOIN students s ON b.student_id = s.id
        $where_sql
        ORDER BY b.date_occurred DESC
        LIMIT ? OFFSET ?";

// Add limit and offset parameters
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

// Count total records (for pagination)
$count_sql = "SELECT COUNT(*) as total FROM student_behaviors b $where_sql";
$count_stmt = mysqli_prepare($conn, $count_sql);

// Bind parameters for count query if needed
if (count($params) > 2) {
    // Remove limit and offset parameters from the bind
    $count_params = array_slice($params, 0, -2);
    $count_types = substr($types, 0, -2);
    
    if (!empty($count_types)) {
        mysqli_stmt_bind_param($count_stmt, $count_types, ...$count_params);
    }
}

mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$count_row = mysqli_fetch_assoc($count_result);
$total_records = $count_row['total'];
$total_pages = ceil($total_records / $limit);

// Prepare and execute the main query
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$behaviors = [];
while ($row = mysqli_fetch_assoc($result)) {
    // Format date for display
    $date = new DateTime($row['date_occurred']);
    $row['formatted_date'] = $date->format('M d, Y - h:i A');
    
    $behaviors[] = $row;
}

// Return data as JSON
echo json_encode([
    'status' => 'success',
    'data' => $behaviors,
    'pagination' => [
        'total_records' => $total_records,
        'total_pages' => $total_pages,
        'current_page' => $page,
        'limit' => $limit
    ]
]);

// Close statement and connection
mysqli_stmt_close($stmt);
mysqli_stmt_close($count_stmt);
mysqli_close($conn);
?>