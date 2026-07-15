<?php
// Prevent any output before JSON
ob_start();

header('Content-Type: application/json');
require_once '../conn.php';
require_once '../auth.php';

$response = ['success' => false, 'message' => 'An unexpected error occurred.', 'data' => [], 'totalRecords' => 0];

try {
    // Check if database connection exists (assuming $conn is your MySQLi connection variable)
    if (!isset($conn) || !$conn) {
        throw new Exception('Database connection not established');
    }

    // Collect and sanitize filter parameters
    $dateFrom = $_GET['dateFrom'] ?? null;
    $dateTo = $_GET['dateTo'] ?? null;
    $studentId = $_GET['studentId'] ?? null;
    $itemId = $_GET['itemId'] ?? null;
    $search = $_GET['search'] ?? null;
    $all = $_GET['all'] ?? null; // For export functionality
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
    $recordsPerPage = 10;
    $offset = ($page - 1) * $recordsPerPage;

    // Base query to count total records (for pagination)
    $countQuery = "SELECT COUNT(t1.withdrawal_id) FROM withdrawals t1
                   JOIN inventory_items t2 ON t1.item_id = t2.id
                   JOIN students t3 ON t1.student_id = t3.student_id
                   WHERE 1=1";

    // Base query to fetch data
    $dataQuery = "SELECT t1.withdrawal_id, t1.withdrawal_date, t1.quantity_withdrawn, t1.notes,
                         t2.item_name, t2.unit, t3.student_id, CONCAT(t3.first_name, ' ', t3.last_name) AS full_name
                  FROM withdrawals t1
                  JOIN inventory_items t2 ON t1.item_id = t2.id
                  JOIN students t3 ON t1.student_id = t3.student_id
                  WHERE 1=1";

    $whereConditions = [];
    $params = [];
    $types = '';

    // Add filters
    if ($dateFrom) {
        $whereConditions[] = "DATE(t1.withdrawal_date) >= ?";
        $params[] = $dateFrom;
        $types .= 's';
    }

    if ($dateTo) {
        $whereConditions[] = "DATE(t1.withdrawal_date) <= ?";
        $params[] = $dateTo;
        $types .= 's';
    }

    if ($studentId) {
        $whereConditions[] = "t3.student_id = ?";
        $params[] = $studentId;
        $types .= 's';
    }

    if ($itemId) {
        $whereConditions[] = "t2.id = ?";
        $params[] = $itemId;
        $types .= 'i';
    }
    
    if ($search) {
        $whereConditions[] = "(CONCAT(t3.first_name, ' ', t3.last_name) LIKE ? OR t3.student_id LIKE ? OR t2.item_name LIKE ?)";
        $searchParam = "%" . $search . "%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= 'sss';
    }

    // Build the complete WHERE clause
    if (!empty($whereConditions)) {
        $whereClause = " AND " . implode(" AND ", $whereConditions);
        $countQuery .= $whereClause;
        $dataQuery .= $whereClause;
    }

    // Get total records (only if not exporting all)
    $totalRecords = 0;
    if (!$all) {
        $stmt_count = $conn->prepare($countQuery);
        if (!$stmt_count) {
            throw new Exception('Failed to prepare count query: ' . $conn->error);
        }

        if (!empty($params)) {
            $stmt_count->bind_param($types, ...$params);
        }

        $stmt_count->execute();
        $result = $stmt_count->get_result();
        $totalRecords = $result->fetch_row()[0];
        $stmt_count->close();
    }

    // Add ordering and pagination to data query
    if (!$all) {
        $dataQuery .= " ORDER BY t1.withdrawal_date DESC LIMIT ? OFFSET ?";
        $params[] = $recordsPerPage;
        $params[] = $offset;
        $types .= 'ii';
    } else {
        $dataQuery .= " ORDER BY t1.withdrawal_date DESC";
    }

    // Get data
    $stmt_data = $conn->prepare($dataQuery);
    if (!$stmt_data) {
        throw new Exception('Failed to prepare data query: ' . $conn->error);
    }

    if (!empty($params)) {
        $stmt_data->bind_param($types, ...$params);
    }

    $stmt_data->execute();
    $result = $stmt_data->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt_data->close();

    $response['success'] = true;
    $response['data'] = $data;
    $response['totalRecords'] = $all ? count($data) : $totalRecords;
    $response['message'] = 'Reports fetched successfully.';

} catch (mysqli_sql_exception $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log('Database error in get_dispensed_reports.php: ' . $e->getMessage());
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log('Error in get_dispensed_reports.php: ' . $e->getMessage());
}

// Clear any output buffer and send JSON
ob_end_clean();
echo json_encode($response);
exit;
?>