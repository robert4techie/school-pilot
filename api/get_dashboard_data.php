<?php
/**
 * get_dashboard_data.php
 *
 * FIX: End-of-day exclusion — DATETIME BETWEEN 'Y-m-d' AND 'Y-m-d' treated the
 *      end date as 00:00:00, silently dropping all records after midnight on the
 *      last day. Fixed by appending ' 23:59:59' to the end date.
 *
 * FIX: Added date format validation on GET params to prevent malformed values
 *      from reaching the database queries.
 *
 * FIX: Returns visit_datetime (not visit_time) — the frontend now formats this
 *      correctly. The old field name was undefined, causing a blank Time column.
 *
 * FIX: DB errors no longer sent to the client.
 */

ob_start();
header('Content-Type: application/json');
require_once '../auth.php';
require_once '../conn.php';

$response = [
    'success'        => false,
    'message'        => 'An unexpected error occurred.',
    'stats'          => [],
    'complaints'     => [],
    'visits_over_time' => [],
    'recent_visits'  => [],
    'low_stock'      => [],
];

// ── Helper: validate a Y-m-d date string ─────────────────────────────────────
function valid_date(string $d): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

try {
    $startDate = $_GET['start_date'] ?? date('Y-m-01');
    $endDate   = $_GET['end_date']   ?? date('Y-m-d');

    // Validate date inputs
    if (!valid_date($startDate)) {
        $startDate = date('Y-m-01');
    }
    if (!valid_date($endDate)) {
        $endDate = date('Y-m-d');
    }

    // FIX: Append time so end of day is included (DATETIME columns)
    $startDateTime = $startDate . ' 00:00:00';
    $endDateTime   = $endDate   . ' 23:59:59';

    // 1. Stats ─────────────────────────────────────────────────────────────────
    $stmt_stats = $conn->prepare("
        SELECT
            (SELECT COUNT(*)
                FROM sick_bay_visits
                WHERE visit_datetime BETWEEN ? AND ?)               AS total_visits,
            (SELECT COUNT(*)
                FROM sick_bay_visits
                WHERE chief_complaint LIKE '%fever%'
                  AND visit_datetime BETWEEN ? AND ?)               AS fever_cases,
            (SELECT COUNT(*)
                FROM sick_bay_visits
                WHERE action_taken = 'ReferredToHospital'
                  AND visit_datetime BETWEEN ? AND ?)               AS hospital_referrals,
            (SELECT COUNT(*)
                FROM inventory_items
                WHERE quantity <= threshold)                        AS low_stock_count,
            (SELECT COUNT(*) FROM inventory_items)                  AS total_items
    ");
    $stmt_stats->bind_param(
        "ssssss",
        $startDateTime, $endDateTime,
        $startDateTime, $endDateTime,
        $startDateTime, $endDateTime
    );
    $stmt_stats->execute();
    $stats = $stmt_stats->get_result()->fetch_assoc();
    $stmt_stats->close();

    // 2. Top complaints ────────────────────────────────────────────────────────
    $stmt_complaints = $conn->prepare("
        SELECT chief_complaint AS complaint, COUNT(*) AS count
        FROM sick_bay_visits
        WHERE chief_complaint IS NOT NULL
          AND chief_complaint != ''
          AND visit_datetime BETWEEN ? AND ?
        GROUP BY chief_complaint
        ORDER BY count DESC
        LIMIT 10
    ");
    $stmt_complaints->bind_param("ss", $startDateTime, $endDateTime);
    $stmt_complaints->execute();
    $complaints = $stmt_complaints->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_complaints->close();

    // 3. Visits over time ──────────────────────────────────────────────────────
    $stmt_vot = $conn->prepare("
        SELECT DATE(visit_datetime) AS date, COUNT(*) AS count
        FROM sick_bay_visits
        WHERE visit_datetime BETWEEN ? AND ?
        GROUP BY DATE(visit_datetime)
        ORDER BY visit_datetime ASC
    ");
    $stmt_vot->bind_param("ss", $startDateTime, $endDateTime);
    $stmt_vot->execute();
    $visits_over_time = $stmt_vot->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_vot->close();

    // 4. Recent visits — FIX: return visit_datetime; format in JS ─────────────
    $stmt_recent = $conn->prepare("
        SELECT
            sv.visit_id,
            sv.visit_datetime,
            sv.chief_complaint,
            sv.action_taken,
            CONCAT(s.first_name, ' ', s.last_name) AS full_name,
            s.current_class
        FROM sick_bay_visits sv
        JOIN students s ON sv.student_id = s.student_id
        ORDER BY sv.visit_datetime DESC
        LIMIT 5
    ");
    $stmt_recent->execute();
    $recent_visits = $stmt_recent->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_recent->close();

    // 5. Low-stock items ───────────────────────────────────────────────────────
    $stmt_low = $conn->prepare("
        SELECT item_name, quantity, threshold, unit
        FROM inventory_items
        WHERE quantity <= threshold
        ORDER BY quantity ASC
        LIMIT 5
    ");
    $stmt_low->execute();
    $low_stock = $stmt_low->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_low->close();

    $response['success']          = true;
    $response['stats']            = $stats;
    $response['complaints']       = $complaints;
    $response['visits_over_time'] = $visits_over_time;
    $response['recent_visits']    = $recent_visits;
    $response['low_stock']        = $low_stock;
    $response['message']          = 'Data fetched successfully.';

} catch (Exception $e) {
    error_log('Error in get_dashboard_data.php: ' . $e->getMessage());
    $response['message'] = 'Failed to load dashboard data. Please try again.';
}

echo json_encode($response);
ob_end_flush();
?>