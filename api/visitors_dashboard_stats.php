<?php
// api/dashboard_stats.php
header('Content-Type: application/json');
require_once '../auth.php';
require_once '../conn.php';
 

// Default to 'today', but allow for other ranges like 'week', 'month'
$period = $_GET['period'] ?? 'today'; 

// --- SQL QUERIES based on the period ---
// NOTE: These are examples. You'll need to adjust the WHERE clauses for different periods.
// For 'week', you'd use `WHERE visit_date BETWEEN CURDATE() - INTERVAL 6 DAY AND CURDATE()`
// For 'month', you'd use `WHERE MONTH(visit_date) = MONTH(CURDATE()) AND YEAR(visit_date) = YEAR(CURDATE())`

// 1. KPI: Visitors on Premises (this is always real-time, independent of period)
$on_premises_q = $conn->query("SELECT COUNT(id) as count FROM visitors WHERE checkout_time IS NULL");
$on_premises = $on_premises_q->fetch_assoc()['count'];

// 2. KPI: Total Visits Today
$total_today_q = $conn->query("SELECT COUNT(id) as count FROM visitors WHERE DATE(visit_date) = CURDATE()");
$total_today = $total_today_q->fetch_assoc()['count'];

// 3. KPI: Average Visit Duration (in minutes) for checked-out visitors
$avg_duration_q = $conn->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, checkout_time)) as avg_mins FROM visitors WHERE checkout_time IS NOT NULL AND DATE(visit_date) = CURDATE()");
$avg_duration = round($avg_duration_q->fetch_assoc()['avg_mins'] ?? 0);

// 4. CHART: Visitor Traffic (example for 'today' by hour)
$traffic_q = $conn->query("SELECT HOUR(created_at) as hour, COUNT(id) as count FROM visitors WHERE DATE(visit_date) = CURDATE() GROUP BY HOUR(created_at) ORDER BY hour ASC");
$traffic_data = $traffic_q->fetch_all(MYSQLI_ASSOC);

// 5. CHART: Visits by Purpose
$purpose_q = $conn->query("SELECT visit_purpose, COUNT(id) as count FROM visitors WHERE DATE(visit_date) = CURDATE() GROUP BY visit_purpose");
$purpose_data = $purpose_q->fetch_all(MYSQLI_ASSOC);

// 6. CHART: Top 5 Hosts
$top_hosts_q = $conn->query("SELECT host, COUNT(id) as count FROM visitors WHERE DATE(visit_date) = CURDATE() GROUP BY host ORDER BY count DESC LIMIT 5");
$top_hosts_data = $top_hosts_q->fetch_all(MYSQLI_ASSOC);

// 7. LIST: Active Visitors
$active_list_q = $conn->query("SELECT first_name, last_name, company, host, TIME(created_at) as checkin_time FROM visitors WHERE checkout_time IS NULL ORDER BY created_at DESC");
$active_list = $active_list_q->fetch_all(MYSQLI_ASSOC);


// --- Compile all data into a single array ---
$response = [
    'kpi' => [
        'on_premises' => $on_premises,
        'total_today' => $total_today,
        'avg_duration' => $avg_duration
    ],
    'charts' => [
        'traffic' => $traffic_data,
        'purpose' => $purpose_data,
        'top_hosts' => $top_hosts_data
    ],
    'lists' => [
        'active_visitors' => $active_list
    ]
];

// --- Return the JSON response ---
echo json_encode($response);
?>