<?php
/**
 * get_visit_reports.php
 *
 * Returns paginated, filtered sick bay visit records.
 * Each row includes the student's name and class, core vitals,
 * and the list of medications dispensed during that visit.
 *
 * Supports:
 *  - Full-text search (student name, ID, complaint)
 *  - Date range filter
 *  - Class filter
 *  - Action-taken filter
 *  - Pagination  (10 per page)
 *  - ?all=1 for export (no pagination, all matching rows)
 */

ob_start();
header('Content-Type: application/json');
require_once '../auth.php';
require_once '../conn.php';

$response = [
    'success'      => false,
    'message'      => 'An unexpected error occurred.',
    'data'         => [],
    'totalRecords' => 0,
];

// ── Helper: validate a Y-m-d string ────────────────────────────────────────
function valid_date_vr(string $d): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

try {
    if (!isset($conn) || !$conn) {
        throw new Exception('Database connection not established.');
    }

    // ── Collect & validate inputs ─────────────────────────────────────────────
    $dateFrom    = $_GET['dateFrom']    ?? null;
    $dateTo      = $_GET['dateTo']      ?? null;
    $search      = $_GET['search']      ?? null;
    $className   = $_GET['class']       ?? null;
    $actionFilter= $_GET['action']      ?? null;
    $all         = !empty($_GET['all']);
    $page        = max(1, (int)($_GET['page'] ?? 1));
    $perPage     = 15;
    $offset      = ($page - 1) * $perPage;

    // Sanitise date inputs
    if ($dateFrom && !valid_date_vr($dateFrom)) $dateFrom = null;
    if ($dateTo   && !valid_date_vr($dateTo))   $dateTo   = null;

    // ── Build WHERE clause dynamically ──────────────────────────────────────
    $where  = [];
    $params = [];
    $types  = '';

    if ($dateFrom) {
        $where[]  = "DATE(sv.visit_datetime) >= ?";
        $params[] = $dateFrom;
        $types   .= 's';
    }
    if ($dateTo) {
        $where[]  = "DATE(sv.visit_datetime) <= ?";
        $params[] = $dateTo;
        $types   .= 's';
    }
    if ($search) {
        $like = '%' . $search . '%';
        $where[]  = "(CONCAT(s.first_name,' ',s.last_name) LIKE ? OR s.student_id LIKE ? OR sv.chief_complaint LIKE ?)";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types   .= 'sss';
    }
    if ($className) {
        $where[]  = "s.current_class = ?";
        $params[] = $className;
        $types   .= 's';
    }
    if ($actionFilter) {
        $where[]  = "sv.action_taken = ?";
        $params[] = $actionFilter;
        $types   .= 's';
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // ── Count query ───────────────────────────────────────────────────────────
    $countSQL = "SELECT COUNT(sv.visit_id)
                 FROM sick_bay_visits sv
                 JOIN students s ON sv.student_id = s.student_id
                 $whereSQL";

    $totalRecords = 0;
    if (!$all) {
        $stmt_count = $conn->prepare($countSQL);
        if (!$stmt_count) throw new Exception('Prepare failed: ' . $conn->error);
        if ($params) $stmt_count->bind_param($types, ...$params);
        $stmt_count->execute();
        $totalRecords = $stmt_count->get_result()->fetch_row()[0];
        $stmt_count->close();
    }

    // ── Data query ────────────────────────────────────────────────────────────
    $dataSQL = "SELECT
                    sv.visit_id,
                    sv.visit_datetime,
                    sv.chief_complaint,
                    sv.temperature,
                    sv.blood_pressure,
                    sv.assessment_notes,
                    sv.treatment_notes,
                    sv.rest_time_minutes,
                    sv.action_taken,
                    sv.parent_notified,
                    sv.parent_notification_notes,
                    sv.followup_required,
                    sv.followup_date,
                    sv.followup_notes,
                    sv.attended_by,
                    s.student_id,
                    CONCAT(s.first_name,' ',s.last_name) AS full_name,
                    s.current_class,
                    s.stream,
                    s.gender
                FROM sick_bay_visits sv
                JOIN students s ON sv.student_id = s.student_id
                $whereSQL
                ORDER BY sv.visit_datetime DESC";

    if (!$all) {
        $dataSQL .= ' LIMIT ? OFFSET ?';
        $params[] = $perPage;
        $params[] = $offset;
        $types   .= 'ii';
    }

    $stmt_data = $conn->prepare($dataSQL);
    if (!$stmt_data) throw new Exception('Prepare failed: ' . $conn->error);
    if ($params) $stmt_data->bind_param($types, ...$params);
    $stmt_data->execute();
    $rows = $stmt_data->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_data->close();

    // ── Fetch medications for these visit IDs ────────────────────────────────
    if ($rows) {
        $ids      = array_column($rows, 'visit_id');
        $inMarks  = implode(',', array_fill(0, count($ids), '?'));
        $stmt_med = $conn->prepare(
            "SELECT visit_id, medication_name, dosage, time_given
             FROM visit_medications
             WHERE visit_id IN ($inMarks)
             ORDER BY visit_id ASC"
        );
        if ($stmt_med) {
            $stmt_med->bind_param(str_repeat('i', count($ids)), ...$ids);
            $stmt_med->execute();
            $medRows = $stmt_med->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_med->close();

            // Group by visit_id
            $medMap = [];
            foreach ($medRows as $m) {
                $medMap[$m['visit_id']][] = $m;
            }
            foreach ($rows as &$row) {
                $row['medications'] = $medMap[$row['visit_id']] ?? [];
            }
            unset($row);
        }
    }

    // ── Fetch unique classes for filter dropdown ──────────────────────────────
    $classRows = $conn->query("SELECT DISTINCT current_class FROM students WHERE current_class IS NOT NULL ORDER BY current_class ASC");
    $classes   = [];
    if ($classRows) {
        while ($c = $classRows->fetch_assoc()) $classes[] = $c['current_class'];
    }

    $response['success']      = true;
    $response['data']         = $rows;
    $response['totalRecords'] = $all ? count($rows) : (int)$totalRecords;
    $response['classes']      = $classes;
    $response['message']      = 'Visit reports fetched successfully.';

} catch (Exception $e) {
    error_log('get_visit_reports.php: ' . $e->getMessage());
    // TODO: revert to generic message after debugging
    $response['message'] = $e->getMessage();
}

ob_end_clean();
echo json_encode($response);
exit;
?>
