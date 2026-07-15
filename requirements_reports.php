<?php
require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';

$allowedRoles = ['super user', 'school leader', 'developer', 'bursar'];
if (!in_array($_SESSION['role'], $allowedRoles)) { header("Location: dashboard.php"); exit(); }
$tracker->trackAction("Requirements Reports");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache"); header("Expires: 0");

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// ── AJAX ─────────────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action  = $_GET['action'];
    $term    = trim($_GET['term']    ?? '');
    $year    = trim($_GET['year']    ?? '');
    $class   = trim($_GET['class']   ?? '');
    $stream  = trim($_GET['stream']  ?? '');
    $section = trim($_GET['section'] ?? '');

    if (!$term || !$year) {
        echo json_encode(['success' => false, 'message' => 'Term and Year are required']); exit();
    }
    if (!preg_match('/^\d{4}$/', $year)) {
        echo json_encode(['success' => false, 'message' => 'Invalid year format']); exit();
    }
    // Whitelist section to prevent unexpected filter values
    if ($section && !in_array($section, ['Day', 'Boarding'], true)) $section = '';

    // ── Summary KPI cards ─────────────────────────────────────────────────
    if ($action === 'get_summary') {
        // Total eligible students (respects class + section filter)
        $tq = "SELECT COUNT(DISTINCT s.student_id) AS total
               FROM students s
               WHERE s.status = 'active'";
        $tp = []; $tt = '';
        if ($class)   { $tq .= " AND s.current_class = ?"; $tp[] = $class;   $tt .= 's'; }
        if ($stream)  { $tq .= " AND s.stream = ?";        $tp[] = $stream;  $tt .= 's'; }
        if ($section) { $tq .= " AND s.section = ?";       $tp[] = $section; $tt .= 's'; }
        $ts = $conn->prepare($tq); if ($tp) $ts->bind_param($tt, ...$tp); $ts->execute();
        $totalStudents = (int)$ts->get_result()->fetch_assoc()['total'];

        // Aggregate across all matching student_requirements
        $q = "SELECT
                COUNT(DISTINCT sr.student_id)                                  AS students_tracked,
                COALESCE(SUM(CASE WHEN sr.status != 'waived' THEN sr.cash_paid      ELSE 0 END), 0) AS total_cash,
                COALESCE(SUM(CASE WHEN sr.status != 'waived' THEN sr.total_value    ELSE 0 END), 0) AS total_value,
                COALESCE(SUM(CASE WHEN sr.status != 'waived' THEN sr.balance        ELSE 0 END), 0) AS total_balance,
                COALESCE(SUM(CASE WHEN sr.status != 'waived' THEN sr.items_brought  ELSE 0 END), 0) AS total_items_count,
                /* Students where EVERY requirement is completed or waived */
                COUNT(DISTINCT CASE WHEN sr.student_id NOT IN (
                    SELECT sr2.student_id
                    FROM student_requirements sr2
                    INNER JOIN school_requirements r2 ON sr2.requirement_id = r2.requirement_id
                    INNER JOIN students s2 ON sr2.student_id = s2.student_id
                    WHERE r2.term = ? AND r2.academic_year = ?
                      AND sr2.status NOT IN ('completed','waived')
                      AND s2.status = 'active'";
        // Build the subquery's dynamic filters (mirrors the outer WHERE filters)
        $subP = [$term, $year]; $subT = 'ss';
        if ($class)   { $q .= " AND s2.current_class = ?"; $subP[] = $class;   $subT .= 's'; }
        if ($stream)  { $q .= " AND s2.stream = ?";        $subP[] = $stream;  $subT .= 's'; }
        if ($section) { $q .= " AND s2.section = ?";       $subP[] = $section; $subT .= 's'; }
        $q .= "
                ) THEN sr.student_id END)                                       AS fully_completed,
                COUNT(DISTINCT CASE WHEN sr.status = 'partial'   THEN sr.student_id END)            AS partial_students,
                COUNT(DISTINCT CASE WHEN sr.status = 'pending'   THEN sr.student_id END)            AS pending_students,
                COUNT(DISTINCT CASE WHEN sr.status = 'waived'    THEN sr.student_id END)            AS waived_students,
                COUNT(sr.record_id)                                            AS total_records,
                COUNT(CASE WHEN sr.status = 'completed' THEN 1 END)           AS completed_records,
                COUNT(CASE WHEN sr.status = 'pending'   THEN 1 END)           AS pending_records,
                COUNT(CASE WHEN sr.status = 'partial'   THEN 1 END)           AS partial_records,
                COUNT(CASE WHEN sr.status = 'waived'    THEN 1 END)           AS waived_records
              FROM student_requirements sr
              INNER JOIN school_requirements r  ON sr.requirement_id = r.requirement_id
              INNER JOIN students s             ON sr.student_id     = s.student_id
              WHERE r.term = ? AND r.academic_year = ? AND s.status = 'active'";
        // Outer WHERE filters — term+year for the subquery come first, then outer term+year, then outer filters
        $p = array_merge($subP, [$term, $year]); $t = $subT . 'ss';
        if ($class)   { $q .= " AND s.current_class = ?"; $p[] = $class;   $t .= 's'; }
        if ($stream)  { $q .= " AND s.stream = ?";        $p[] = $stream;  $t .= 's'; }
        if ($section) { $q .= " AND s.section = ?";       $p[] = $section; $t .= 's'; }
        $stmt = $conn->prepare($q); $stmt->bind_param($t, ...$p); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $row['total_students'] = $totalStudents;
        echo json_encode(['success' => true, 'data' => $row]);
        exit();
    }

    // ── Class breakdown table ─────────────────────────────────────────────
    if ($action === 'get_class_summary') {
        $q = "SELECT
                s.current_class,
                s.stream,
                s.section,
                COUNT(DISTINCT s.student_id)                                   AS total_students,
                COUNT(DISTINCT sr.student_id)                                  AS students_tracked,
                COUNT(DISTINCT CASE WHEN sr.status IN('completed','waived') THEN sr.student_id END) AS completed_students,
                COALESCE(SUM(sr.cash_paid),      0)   AS total_cash,
                COALESCE(SUM(sr.items_brought),  0)   AS total_items,
                COALESCE(SUM(sr.total_value),    0)   AS total_value,
                COALESCE(SUM(sr.balance),        0)   AS total_balance,
                COUNT(CASE WHEN sr.status = 'completed' THEN 1 END) AS completed_recs,
                COUNT(CASE WHEN sr.status = 'partial'   THEN 1 END) AS partial_recs,
                COUNT(CASE WHEN sr.status = 'pending'   THEN 1 END) AS pending_recs
              FROM students s
              LEFT JOIN student_requirements sr ON s.student_id = sr.student_id
                  AND sr.requirement_id IN (
                      SELECT requirement_id FROM school_requirements
                      WHERE term = ? AND academic_year = ? AND status = 'active'
                  )
              WHERE s.status = 'active'";
        $p = [$term, $year]; $t = 'ss';
        if ($class)   { $q .= " AND s.current_class = ?"; $p[] = $class;   $t .= 's'; }
        if ($stream)  { $q .= " AND s.stream = ?";        $p[] = $stream;  $t .= 's'; }
        if ($section) { $q .= " AND s.section = ?";       $p[] = $section; $t .= 's'; }
        $q .= " GROUP BY s.current_class, s.stream, s.section
                ORDER BY s.current_class, s.stream, s.section";
        $stmt = $conn->prepare($q); $stmt->bind_param($t, ...$p); $stmt->execute();
        echo json_encode(['success' => true, 'data' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
        exit();
    }

    // ── Per-requirement inventory ─────────────────────────────────────────
    if ($action === 'get_inventory') {
        $q = "SELECT
                r.requirement_id,
                r.requirement_name,
                r.class,
                r.stream,
                r.section_type,
                r.quantity_per_student,
                r.cash_equivalent,
                COUNT(DISTINCT sr.student_id)          AS students_involved,
                COALESCE(SUM(sr.items_brought), 0)     AS total_items,
                COALESCE(SUM(sr.cash_paid),     0)     AS total_cash,
                COALESCE(SUM(sr.total_value),   0)     AS total_value,
                COALESCE(SUM(sr.balance),       0)     AS total_balance,
                COUNT(CASE WHEN sr.status='completed' THEN 1 END) AS completed_count,
                COUNT(CASE WHEN sr.status='partial'   THEN 1 END) AS partial_count,
                COUNT(CASE WHEN sr.status='pending'   THEN 1 END) AS pending_count,
                COUNT(CASE WHEN sr.status='waived'    THEN 1 END) AS waived_count,
                (SELECT COUNT(DISTINCT s2.student_id)
                 FROM students s2
                 WHERE s2.status = 'active'
                   AND (r.class IS NULL OR s2.current_class = r.class)
                   AND (r.stream IS NULL OR s2.stream = r.stream)
                   AND (r.section_type = 'All' OR s2.section = r.section_type)
                ) AS expected_students
              FROM school_requirements r
              LEFT JOIN student_requirements sr ON r.requirement_id = sr.requirement_id
              LEFT JOIN students s ON sr.student_id = s.student_id AND s.status = 'active'
              WHERE r.term = ? AND r.academic_year = ? AND r.status = 'active'";
        $p = [$term, $year]; $t = 'ss';
        if ($class)   { $q .= " AND (r.class IS NULL OR r.class = ?)"; $p[] = $class;   $t .= 's'; }
        if ($section) { $q .= " AND (r.section_type = 'All' OR r.section_type = ?)"; $p[] = $section; $t .= 's'; }
        $q .= " GROUP BY r.requirement_id ORDER BY r.requirement_name";
        $stmt = $conn->prepare($q); $stmt->bind_param($t, ...$p); $stmt->execute();
        echo json_encode(['success' => true, 'data' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
        exit();
    }

    // ── Per-student status list ───────────────────────────────────────────
    if ($action === 'get_student_list') {
        $allowedStatuses = ['', 'Completed', 'In Progress', 'No Records', 'Waived'];
        $statusFilter = trim($_GET['status_filter'] ?? '');
        if (!in_array($statusFilter, $allowedStatuses, true)) $statusFilter = '';
        $q = "SELECT
                s.student_id,
                s.first_name,
                s.last_name,
                s.current_class,
                s.stream,
                s.section,
                COUNT(sr.record_id)                                AS total_reqs,
                COUNT(CASE WHEN sr.status='completed' THEN 1 END)  AS done,
                COUNT(CASE WHEN sr.status='partial'   THEN 1 END)  AS partial,
                COUNT(CASE WHEN sr.status='pending'   THEN 1 END)  AS pending,
                COUNT(CASE WHEN sr.status='waived'    THEN 1 END)  AS waived,
                COALESCE(SUM(sr.cash_paid),   0)                   AS cash_paid,
                COALESCE(SUM(sr.total_value), 0)                   AS total_value,
                COALESCE(SUM(sr.balance),     0)                   AS balance,
                CASE
                  WHEN COUNT(sr.record_id) = 0 THEN 'No Records'
                  WHEN COUNT(CASE WHEN sr.status='pending' OR sr.status='partial' THEN 1 END) = 0
                       AND COUNT(CASE WHEN sr.status='completed' THEN 1 END) > 0 THEN 'Completed'
                  WHEN COUNT(CASE WHEN sr.status='waived' THEN 1 END) = COUNT(sr.record_id) THEN 'Waived'
                  ELSE 'In Progress'
                END AS overall
              FROM students s
              LEFT JOIN student_requirements sr ON s.student_id = sr.student_id
                  AND sr.requirement_id IN (
                      SELECT requirement_id FROM school_requirements
                      WHERE term = ? AND academic_year = ? AND status = 'active'
                  )
              WHERE s.status = 'active'";
        $p = [$term, $year]; $t = 'ss';
        if ($class)   { $q .= " AND s.current_class = ?"; $p[] = $class;   $t .= 's'; }
        if ($stream)  { $q .= " AND s.stream = ?";        $p[] = $stream;  $t .= 's'; }
        if ($section) { $q .= " AND s.section = ?";       $p[] = $section; $t .= 's'; }
        $q .= " GROUP BY s.student_id, s.first_name, s.last_name, s.current_class, s.stream, s.section";
        if ($statusFilter) {
            $q .= " HAVING overall = ?"; $p[] = $statusFilter; $t .= 's';
        }
        $q .= " ORDER BY s.current_class, s.stream, s.first_name, s.last_name LIMIT 2000";
        $stmt = $conn->prepare($q); $stmt->bind_param($t, ...$p); $stmt->execute();
        echo json_encode(['success' => true, 'data' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit();
}

// ── Page data ─────────────────────────────────────────────────────────────────
$classes     = $conn->query("SELECT DISTINCT current_class FROM students WHERE current_class IS NOT NULL AND current_class != '' ORDER BY current_class")->fetch_all(MYSQLI_ASSOC);
$streams     = $conn->query("SELECT DISTINCT stream FROM students WHERE stream IS NOT NULL AND stream != '' ORDER BY stream")->fetch_all(MYSQLI_ASSOC);
$currentYear = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Requirements Reports &mdash; School Pilot</title>
<link rel="shortcut icon" href="images/schoolcontrol_icon.png" type="image/x-icon">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
<style>
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--orange:#e65100;--blue:#1565c0;--amber:#f57c00;--purple:#6a1b9a;
  --gray:#546e7a;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);--shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --tr:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}
a{color:inherit;text-decoration:none}
.page{max-width:100%;padding:24px 20px 60px;margin-top:48px}

/* Page Header */
.page-header{
  background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);
  border-radius:var(--radius-lg);padding:28px 32px;
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:20px;margin-bottom:24px;box-shadow:var(--shadow-lg)
}
.page-header h1{color:#fff;font-size:1.55rem;font-weight:700}
.page-header p{color:rgba(255,255,255,.78);font-size:.9rem;margin-top:3px}

/* Filter Bar */
.filter-card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);padding:22px;margin-bottom:20px}
.filter-card h3{font-size:.88rem;font-weight:700;color:#444;margin-bottom:14px;display:flex;align-items:center;gap:7px}
.filter-card h3 i{color:var(--g700)}
.filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:14px}
.fg{display:flex;flex-direction:column}
.fg label{font-size:.78rem;font-weight:600;color:#555;margin-bottom:6px}
.req-mark{color:var(--red)}
.fc{
  padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);
  font-size:.875rem;background:#fff;font-family:inherit;
  transition:border-color var(--tr);cursor:pointer
}
.fc:focus{outline:none;border-color:var(--g600)}
.filter-actions{display:flex;gap:10px;flex-wrap:wrap}

/* Buttons */
.btn{
  display:inline-flex;align-items:center;gap:7px;
  padding:9px 16px;border:none;border-radius:var(--radius);
  font-size:.85rem;font-weight:600;font-family:inherit;cursor:pointer;
  transition:all var(--tr);white-space:nowrap
}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn:disabled{opacity:.55;cursor:not-allowed;transform:none;box-shadow:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-pdf  {background:#c62828;color:#fff}.btn-pdf:hover  {background:var(--red)}
.btn-excel{background:var(--g800);color:#fff}.btn-excel:hover{background:var(--g900)}

/* KPI Grid */
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
@media(max-width:900px){.kpi-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:500px){.kpi-grid{grid-template-columns:1fr}}
.kpi{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);padding:20px 22px;display:flex;align-items:center;gap:14px;border-left:4px solid transparent}
.kpi-green {border-left-color:var(--g700)}
.kpi-amber {border-left-color:var(--amber)}
.kpi-red   {border-left-color:var(--red)}
.kpi-blue  {border-left-color:var(--blue)}
.kpi-purple{border-left-color:var(--purple)}
.kpi-ico{width:46px;height:46px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0}
.ki-green {background:var(--g100);color:var(--g700)}
.ki-amber {background:#fff8e1;color:var(--amber)}
.ki-red   {background:#ffebee;color:var(--red)}
.ki-blue  {background:#e3f2fd;color:var(--blue)}
.ki-purple{background:#f3e5f5;color:var(--purple)}
.kpi-val{font-size:1.55rem;font-weight:700;color:#222;line-height:1}
.kpi-lbl{font-size:.74rem;color:#666;margin-top:4px;text-transform:uppercase;letter-spacing:.4px}
.kpi-sub{font-size:.72rem;color:#999;margin-top:2px}

/* Charts */
.charts-grid{display:grid;grid-template-columns:1fr 1fr 320px;gap:18px;margin-bottom:20px}
@media(max-width:1100px){.charts-grid{grid-template-columns:1fr 1fr}}
@media(max-width:700px) {.charts-grid{grid-template-columns:1fr}}
.chart-card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);padding:20px}
.chart-card h3{font-size:.88rem;font-weight:700;color:#333;margin-bottom:14px;display:flex;align-items:center;gap:7px}
.chart-card h3 i{color:var(--g700)}
.chart-wrap{position:relative}

/* Tabs */
.tabs{display:flex;border-bottom:2px solid #e8ede9;margin-bottom:0;background:#fff;padding:0 22px}
.tab-btn{
  padding:13px 18px;border:none;background:transparent;font-family:inherit;
  font-size:.88rem;font-weight:600;color:#888;cursor:pointer;
  border-bottom:3px solid transparent;margin-bottom:-2px;transition:all var(--tr);
  display:flex;align-items:center;gap:7px
}
.tab-btn.active{color:var(--g700);border-bottom-color:var(--g700)}
.tab-btn:hover:not(.active){color:#444}
.tab-panel{display:none}.tab-panel.active{display:block}

/* Card */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px}
.card-head{
  background:linear-gradient(90deg,var(--g700),var(--g600));
  padding:15px 22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px
}
.card-head h2{color:#fff;font-size:1rem;font-weight:700;display:flex;align-items:center;gap:9px}
.card-actions{display:flex;gap:8px;flex-wrap:wrap}

/* Section badge */
.sbadge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:.67rem;font-weight:700;letter-spacing:.3px}
.sb-all     {background:#e3f2fd;color:#1565c0}
.sb-day     {background:#fff8e1;color:#e65100}
.sb-boarding{background:#f3e5f5;color:#6a1b9a}

/* Table */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:11px 13px;text-align:left;font-size:.76rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--tr)}
tbody tr:hover{background:#f5fbf5}
tbody tr.totals-row{background:#f5fbf5;font-weight:700;border-top:2px solid var(--g400)}
tbody td{padding:11px 13px;font-size:.845rem;vertical-align:middle}

/* Progress */
.prog-bar{height:7px;background:#e8ede9;border-radius:4px;overflow:hidden}
.prog-fill{height:100%;border-radius:4px}

/* Status badges */
.badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:.69rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.b-completed{background:#e8f5e9;color:#2e7d32}
.b-in-progress{background:#fff8e1;color:#e65100}
.b-no-records{background:#f5f5f5;color:#757575}
.b-waived{background:#f3e5f5;color:#6a1b9a}

/* Toolbar search */
.tb-search{position:relative}
.tb-search input{
  padding:8px 12px 8px 34px;border:1.5px solid #d0dbd1;border-radius:var(--radius);
  font-size:.845rem;font-family:inherit;width:220px;transition:border-color var(--tr)
}
.tb-search input:focus{outline:none;border-color:var(--g600)}
.tb-search i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#aaa;font-size:.8rem;pointer-events:none}

/* Toolbar */
.toolbar{
  padding:14px 22px;border-bottom:1px solid #f0f4f1;
  display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between
}
.tl{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.result-count{font-size:.78rem;color:#6b7c6d;white-space:nowrap}

/* Empty */
.empty-state{text-align:center;padding:52px 20px;color:#8a9a8b}
.empty-state i{font-size:2.5rem;margin-bottom:12px;display:block;opacity:.4}
.idle-state{text-align:center;padding:64px 20px;color:#8a9a8b}
.idle-state i{font-size:4rem;margin-bottom:16px;display:block;opacity:.2;color:var(--g700)}
.idle-state h3{font-size:1.1rem;font-weight:600;margin-bottom:6px;color:#555}

/* Notif */
#notif-stack{position:fixed;top:68px;right:18px;z-index:9999;display:flex;flex-direction:column;gap:9px;pointer-events:none}
.notif{pointer-events:all;display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);max-width:360px;animation:ntslide .25s ease;transition:opacity .3s,transform .3s}
@keyframes ntslide{from{transform:translateX(30px);opacity:0}to{transform:translateX(0);opacity:1}}
.notif.success{background:#1b5e20;color:#fff}.notif.error{background:#b71c1c;color:#fff}
.notif.warning{background:#e65100;color:#fff}.notif.info{background:#1565c0;color:#fff}
.notif-icon{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.notif-body{flex:1}.notif-title{font-weight:700;font-size:.84rem}.notif-msg{font-size:.78rem;opacity:.9;margin-top:2px}
.notif-close{background:rgba(255,255,255,.2);border:none;color:#fff;width:22px;height:22px;border-radius:50%;font-size:.72rem;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0}

/* Page loader */
#page-loader{position:fixed;inset:0;background:rgba(255,255,255,.88);z-index:2000;display:none;align-items:center;justify-content:center;flex-direction:column;gap:12px}
#page-loader.show{display:flex}
.spin{width:42px;height:42px;border:4px solid #e8f5e9;border-top-color:var(--g700);border-radius:50%;animation:spinning .8s linear infinite}
@keyframes spinning{to{transform:rotate(360deg)}}
#page-loader p{font-size:.88rem;color:var(--g800);font-weight:600}

@media(max-width:640px){.filter-grid{grid-template-columns:1fr 1fr}}

/* Pagination */
.pagination-bar{
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;
  gap:10px;padding:12px 22px;border-top:1px solid #f0f4f1;background:#fff
}
.pg-info{font-size:.78rem;color:#6b7c6d}
.pg-controls{display:flex;align-items:center;gap:4px}
.pg-btn{
  min-width:34px;height:34px;padding:0 8px;border:1.5px solid #d0dbd1;
  border-radius:6px;background:#fff;font-size:.8rem;font-weight:600;
  color:#555;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;
  font-family:inherit;transition:all .18s ease
}
.pg-btn:hover:not(:disabled):not(.active){background:#f5fbf5;border-color:var(--g600);color:var(--g700)}
.pg-btn.active{background:var(--g700);border-color:var(--g700);color:#fff}
.pg-btn:disabled{opacity:.4;cursor:not-allowed}
.pg-size{
  padding:6px 10px;border:1.5px solid #d0dbd1;border-radius:6px;
  font-size:.78rem;font-family:inherit;background:#fff;cursor:pointer
}
.pg-size:focus{outline:none;border-color:var(--g600)}

</style>
</head>
<body>

<div id="page-loader"><div class="spin"></div><p>Generating report…</p></div>
<div id="notif-stack"></div>

<?php require_once 'nav.php'; ?>

<div class="page">

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <h1><i class="fas fa-chart-bar" style="margin-right:10px;opacity:.85"></i>Requirements Reports</h1>
      <p>Compliance analytics · Section-aware breakdowns · Export to PDF &amp; Excel</p>
    </div>
    <a href="requirements_setup.php" class="btn" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;font-size:.83rem">
      <i class="fas fa-cog"></i> Setup Requirements
    </a>
  </div>

  <!-- Filter Bar -->
  <div class="filter-card">
    <h3><i class="fas fa-sliders"></i> Report Filters</h3>
    <div class="filter-grid">
      <div class="fg">
        <label>Term <span class="req-mark">*</span></label>
        <select class="fc" id="fTerm">
          <option value="">Select Term</option>
          <option>Term 1</option><option>Term 2</option><option>Term 3</option>
        </select>
      </div>
      <div class="fg">
        <label>Academic Year <span class="req-mark">*</span></label>
        <input type="text" class="fc" id="fYear" value="<?= $currentYear ?>" placeholder="e.g. <?= $currentYear ?>" maxlength="4">
      </div>
      <div class="fg">
        <label>Class</label>
        <select class="fc" id="fClass">
          <option value="">All Classes</option>
          <?php foreach ($classes as $c): ?>
          <option value="<?= htmlspecialchars($c['current_class']) ?>"><?= htmlspecialchars($c['current_class']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label>Stream</label>
        <select class="fc" id="fStream">
          <option value="">All Streams</option>
          <?php foreach ($streams as $s): ?>
          <option value="<?= htmlspecialchars($s['stream']) ?>"><?= htmlspecialchars($s['stream']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label>Section</label>
        <select class="fc" id="fSection">
          <option value="">All (Day + Boarding)</option>
          <option value="Day">Day Students Only</option>
          <option value="Boarding">Boarding Students Only</option>
        </select>
      </div>
    </div>
    <div class="filter-actions">
      <button class="btn btn-primary" id="runReportBtn" onclick="runReport()">
        <i class="fas fa-play"></i> Generate Report
      </button>
      <button class="btn btn-outline" onclick="clearReport()">
        <i class="fas fa-rotate-left"></i> Clear
      </button>
    </div>
  </div>

  <!-- Report area (hidden until generated) -->
  <div id="reportArea" style="display:none">

    <!-- KPI Cards -->
    <div class="kpi-grid" id="kpiGrid"></div>

    <!-- Charts -->
    <div class="charts-grid">
      <div class="chart-card">
        <h3><i class="fas fa-school"></i> Completion Rate by Class</h3>
        <div class="chart-wrap" style="height:240px"><canvas id="classChart"></canvas></div>
      </div>
      <div class="chart-card">
        <h3><i class="fas fa-users-between-lines"></i> Day vs Boarding Compliance</h3>
        <div class="chart-wrap" style="height:240px"><canvas id="sectionChart"></canvas></div>
      </div>
      <div class="chart-card" id="cashChartCard">
        <h3><i class="fas fa-coins"></i> Cash &amp; Items vs Outstanding</h3>
        <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap">
          <div style="width:160px;height:160px;flex-shrink:0;position:relative">
            <canvas id="cashChart"></canvas>
          </div>
          <div id="cashLegend" style="font-size:.8rem;flex:1;min-width:140px"></div>
        </div>
      </div>
    </div>

    <!-- Tabs for the three tables -->
    <div class="card" style="overflow:visible">
      <div class="tabs" id="tabBar">
        <button class="tab-btn active" onclick="switchTab('class')"  id="tab-class">
          <i class="fas fa-chalkboard"></i> By Class
        </button>
        <button class="tab-btn" onclick="switchTab('inventory')" id="tab-inventory">
          <i class="fas fa-boxes-stacked"></i> By Requirement
        </button>
        <button class="tab-btn" onclick="switchTab('students')" id="tab-students">
          <i class="fas fa-users"></i> By Student
        </button>
      </div>

      <!-- Class Summary -->
      <div class="tab-panel active" id="panel-class">
        <div class="card-head" style="border-radius:0">
          <h2><i class="fas fa-table"></i> Class Compliance Summary</h2>
          <div class="card-actions">
            <button class="btn btn-excel" onclick="exportClassExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn btn-pdf"   onclick="exportClassPDF()"><i class="fas fa-file-pdf"></i> PDF</button>
          </div>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Class</th><th>Stream</th><th>Section</th>
                <th>Total Students</th><th>Tracked</th><th>Completed</th>
                <th>Cash (UGX)</th><th>Items</th><th>Value (UGX)</th>
                <th>Balance (UGX)</th><th>% Done</th>
              </tr>
            </thead>
            <tbody id="classBody"></tbody>
          </table>
        </div>
      </div>

      <!-- Inventory -->
      <div class="tab-panel" id="panel-inventory">
        <div class="card-head" style="border-radius:0">
          <h2><i class="fas fa-clipboard-list"></i> Requirement Inventory</h2>
          <div class="card-actions">
            <button class="btn btn-excel" onclick="exportInventoryExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn btn-pdf"   onclick="exportInventoryPDF()"><i class="fas fa-file-pdf"></i> PDF</button>
          </div>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Requirement</th><th>Scope</th><th>Applies To</th>
                <th>Expected Students</th><th>Tracked</th>
                <th>Items Received</th><th>Cash (UGX)</th>
                <th>Value (UGX)</th><th>Balance (UGX)</th>
                <th>Completion</th>
              </tr>
            </thead>
            <tbody id="inventoryBody"></tbody>
          </table>
        </div>
      </div>

      <!-- Student list -->
      <div class="tab-panel" id="panel-students">
        <div class="card-head" style="border-radius:0">
          <h2><i class="fas fa-users"></i> Student Status List</h2>
          <div class="card-actions">
            <button class="btn btn-excel" onclick="exportStudentExcel()"><i class="fas fa-file-excel"></i> Excel</button>
            <button class="btn btn-pdf"   onclick="exportStudentPDF()"><i class="fas fa-file-pdf"></i> PDF</button>
          </div>
        </div>
        <div class="toolbar">
          <div class="tl">
            <div class="tb-search">
              <i class="fas fa-search"></i>
              <input type="text" id="stuSearch" placeholder="Search student…" oninput="filterStudentTable()">
            </div>
            <select class="fc" id="stuStatusFilter" onchange="filterStudentTable()" style="min-width:140px">
              <option value="">All Statuses</option>
              <option value="Completed">Completed</option>
              <option value="In Progress">In Progress</option>
              <option value="No Records">No Records</option>
              <option value="Waived">Waived</option>
            </select>
          </div>
          <span class="result-count" id="stuCount"></span>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Student</th><th>ID</th><th>Class</th><th>Stream</th><th>Section</th>
                <th>Reqs</th><th>Done</th><th>Partial</th><th>Pending</th>
                <th>Cash (UGX)</th><th>Balance (UGX)</th><th>Status</th>
              </tr>
            </thead>
            <tbody id="studentBody"></tbody>
          </table>
        </div>
        <!-- Pagination -->
        <div class="pagination-bar" id="stuPagBar" style="display:none">
          <div class="pg-info" id="stuPagInfo"></div>
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <div style="display:flex;align-items:center;gap:6px">
              <label style="font-size:.76rem;color:#666;white-space:nowrap">Rows per page</label>
              <select class="pg-size" id="stuPageSize" onchange="stuSetPageSize()">
                <option value="25">25</option>
                <option value="50" selected>50</option>
                <option value="100">100</option>
                <option value="200">200</option>
              </select>
            </div>
            <div class="pg-controls" id="stuPagControls"></div>
          </div>
        </div>
      </div>

    </div><!-- end tabbed card -->
  </div><!-- end reportArea -->

  <!-- Idle State -->
  <div id="idleState">
    <div class="idle-state">
      <i class="fas fa-chart-column"></i>
      <h3>No Report Generated Yet</h3>
      <p>Select a Term and Year above, then click <strong>Generate Report</strong></p>
    </div>
  </div>

</div><!-- end .page -->

<script>
let reportData     = { summary: null, classSummary: [], inventory: [], students: [] };
let filteredStuds  = [];
let chartClass = null, chartSection = null, chartCash = null;
let stuCurrentPage = 1;
let stuPageSize    = 50;

// ── Run report ───────────────────────────────────────────────────────────────
async function runReport() {
    const term    = document.getElementById('fTerm').value;
    const year    = document.getElementById('fYear').value.trim();
    const cls     = document.getElementById('fClass').value;
    const stream  = document.getElementById('fStream').value;
    const section = document.getElementById('fSection').value;

    if (!term || !year) { notify('Missing Filters', 'Please select both Term and Year.', 'warning'); return; }
    if (!/^\d{4}$/.test(year)) { notify('Invalid Year', 'Please enter a valid 4-digit year.', 'warning'); return; }

    const btn = document.getElementById('runReportBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading…';
    showLoader();

    try {
        const base = `requirements_reports.php?term=${enc(term)}&year=${enc(year)}&class=${enc(cls)}&stream=${enc(stream)}&section=${enc(section)}`;
        const [rSum, rClass, rInv, rStu] = await Promise.all([
            fetch(base + '&action=get_summary').then(r => r.json()),
            fetch(base + '&action=get_class_summary').then(r => r.json()),
            fetch(base + '&action=get_inventory').then(r => r.json()),
            fetch(base + '&action=get_student_list').then(r => r.json()),
        ]);

        if (!rSum.success)   throw new Error(rSum.message);
        if (!rClass.success) throw new Error(rClass.message);
        if (!rInv.success)   throw new Error(rInv.message);
        if (!rStu.success)   throw new Error(rStu.message);

        reportData.summary     = rSum.data;
        reportData.classSummary = rClass.data;
        reportData.inventory   = rInv.data;
        reportData.students    = rStu.data;
        filteredStuds          = rStu.data;
        stuCurrentPage         = 1;   // always start from page 1 on a fresh report

        renderKPIs(rSum.data);
        renderCharts(rClass.data, rSum.data);
        renderClassTable(rClass.data);
        renderInventoryTable(rInv.data);
        renderStudentTable(rStu.data);

        document.getElementById('idleState').style.display  = 'none';
        document.getElementById('reportArea').style.display = 'block';
    } catch (e) { notify('Error', e.message, 'error'); }
    finally {
        hideLoader();
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-play"></i> Generate Report';
    }
}

function clearReport() {
    document.getElementById('fTerm').value    = '';
    document.getElementById('fYear').value    = '';
    document.getElementById('fClass').value   = '';
    document.getElementById('fStream').value  = '';
    document.getElementById('fSection').value = '';
    reportData = { summary: null, classSummary: [], inventory: [], students: [] };
    filteredStuds = [];
    stuCurrentPage = 1;
    document.getElementById('reportArea').style.display = 'none';
    document.getElementById('idleState').style.display  = 'block';
}

// ── KPI Cards ────────────────────────────────────────────────────────────────
function renderKPIs(d) {
    const fmt  = v => 'UGX ' + Number(v || 0).toLocaleString('en-UG');
    const pct  = d.total_students > 0
        ? ((d.fully_completed / d.total_students) * 100).toFixed(1) + '%' : '0%';
    // total_value = cash paid + (items brought × unit price) — the true collected amount
    const collected   = parseFloat(d.total_value)   || 0;
    const outstanding = parseFloat(d.total_balance) || 0;

    document.getElementById('kpiGrid').innerHTML = `
      <div class="kpi kpi-green">
        <div class="kpi-ico ki-green"><i class="fas fa-users"></i></div>
        <div>
          <div class="kpi-val">${Number(d.total_students).toLocaleString()}</div>
          <div class="kpi-lbl">Total Students</div>
          <div class="kpi-sub">${Number(d.students_tracked).toLocaleString()} with records</div>
        </div>
      </div>
      <div class="kpi kpi-blue">
        <div class="kpi-ico ki-blue"><i class="fas fa-circle-check"></i></div>
        <div>
          <div class="kpi-val">${pct}</div>
          <div class="kpi-lbl">Compliance Rate</div>
          <div class="kpi-sub">${Number(d.fully_completed).toLocaleString()} fully done</div>
        </div>
      </div>
      <div class="kpi kpi-green">
        <div class="kpi-ico ki-green"><i class="fas fa-money-bill-wave"></i></div>
        <div>
          <div class="kpi-val" style="font-size:1.15rem">${fmt(collected)}</div>
          <div class="kpi-lbl">Value Received</div>
          <div class="kpi-sub">Cash + items · ${Number(d.completed_records).toLocaleString()} records done</div>
        </div>
      </div>
      <div class="kpi kpi-red">
        <div class="kpi-ico ki-red"><i class="fas fa-triangle-exclamation"></i></div>
        <div>
          <div class="kpi-val" style="font-size:1.15rem">${fmt(outstanding)}</div>
          <div class="kpi-lbl">Outstanding Balance</div>
          <div class="kpi-sub">${Number((+d.pending_records) + (+d.partial_records)).toLocaleString()} pending / partial</div>
        </div>
      </div>`;
}

// ── Charts ───────────────────────────────────────────────────────────────────
function renderCharts(classData, summary) {
    // Aggregate by class (merge streams/sections)
    const byClass = {};
    classData.forEach(row => {
        const k = row.current_class;
        if (!byClass[k]) byClass[k] = { total: 0, done: 0 };
        byClass[k].total += parseInt(row.total_students) || 0;
        byClass[k].done  += parseInt(row.completed_students) || 0;
    });
    const classLabels = Object.keys(byClass);
    const classPcts   = classLabels.map(k => byClass[k].total > 0 ? +((byClass[k].done / byClass[k].total) * 100).toFixed(1) : 0);

    // Chart 1 — Class completion bar
    if (chartClass) chartClass.destroy();
    chartClass = new Chart(document.getElementById('classChart'), {
        type: 'bar',
        data: {
            labels: classLabels,
            datasets: [{
                label: '% Complete',
                data: classPcts,
                backgroundColor: classPcts.map(v => v >= 80 ? '#388e3c' : v >= 50 ? '#f57c00' : '#d32f2f'),
                borderRadius: 5, borderSkipped: false,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `${c.raw}% completed` } } },
            scales: {
                y: { max: 100, ticks: { callback: v => v + '%' }, grid: { color: '#f5f5f5' } },
                x: { grid: { display: false } }
            }
        }
    });

    // Chart 2 — Day vs Boarding
    const dayTotal = classData.filter(r => r.section === 'Day').reduce((a, r) => ({ t: a.t + (+r.total_students||0), d: a.d + (+r.completed_students||0) }), { t: 0, d: 0 });
    const borTotal = classData.filter(r => r.section === 'Boarding').reduce((a, r) => ({ t: a.t + (+r.total_students||0), d: a.d + (+r.completed_students||0) }), { t: 0, d: 0 });
    if (chartSection) chartSection.destroy();
    chartSection = new Chart(document.getElementById('sectionChart'), {
        type: 'bar',
        data: {
            labels: ['Day Students', 'Boarding Students'],
            datasets: [
                { label: 'Completed', data: [dayTotal.d, borTotal.d], backgroundColor: ['#388e3c', '#388e3c'], borderRadius: 5, borderSkipped: false },
                { label: 'Remaining', data: [Math.max(0, dayTotal.t - dayTotal.d), Math.max(0, borTotal.t - borTotal.d)], backgroundColor: ['#ef9a9a', '#ef9a9a'], borderRadius: 5, borderSkipped: false },
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } },
            scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, grid: { color: '#f5f5f5' } } }
        }
    });

    // Chart 3 — 3-segment donut: Cash Paid | Items Value | Outstanding
    const cashPaid  = parseFloat(summary.total_cash)        || 0;
    const totalVal  = parseFloat(summary.total_value)       || 0;
    const out       = parseFloat(summary.total_balance)     || 0;
    const itemsVal  = Math.max(0, totalVal - cashPaid);      // value of items brought
    const itemsCnt  = parseInt(summary.total_items_count)   || 0;
    if (chartCash) chartCash.destroy();
    // Show placeholder if there's no data at all
    const noData = (cashPaid === 0 && itemsVal === 0 && out === 0);
    const donutData   = noData ? [1]                          : [cashPaid, itemsVal, out];
    const donutColors = noData ? ['#e8ede9']                  : ['#1565c0', '#388e3c', '#d32f2f'];
    const donutLabels = noData ? ['No data']                  : ['Cash Paid', 'Items Brought', 'Outstanding'];
    chartCash = new Chart(document.getElementById('cashChart'), {
        type: 'doughnut',
        data: {
            labels: donutLabels,
            datasets: [{
                data: donutData,
                backgroundColor: donutColors,
                borderWidth: 3,
                borderColor: '#fff',
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '70%',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: c => `UGX ${Number(c.raw).toLocaleString('en-UG')}` } }
            }
        }
    });
    const fmtUGX = v => 'UGX ' + Number(v).toLocaleString('en-UG');
    const grandTotal = cashPaid + itemsVal + out;
    const cashPct  = grandTotal > 0 ? ((cashPaid / grandTotal) * 100).toFixed(1) : '0.0';
    const itemsPct = grandTotal > 0 ? ((itemsVal  / grandTotal) * 100).toFixed(1) : '0.0';
    const outPct   = grandTotal > 0 ? ((out       / grandTotal) * 100).toFixed(1) : '0.0';
    document.getElementById('cashLegend').innerHTML = `
      <div style="display:flex;flex-direction:column;gap:10px">
        <div>
          <div style="display:flex;align-items:center;gap:7px;margin-bottom:3px">
            <span style="width:12px;height:12px;border-radius:3px;background:#1565c0;display:inline-block;flex-shrink:0"></span>
            <span style="font-weight:700;color:#1565c0">Cash Paid</span>
          </div>
          <div style="font-size:.82rem;font-weight:700;color:#222">${fmtUGX(cashPaid)}</div>
          <div style="font-size:.72rem;color:#888">${cashPct}% of total</div>
        </div>
        <div>
          <div style="display:flex;align-items:center;gap:7px;margin-bottom:3px">
            <span style="width:12px;height:12px;border-radius:3px;background:#388e3c;display:inline-block;flex-shrink:0"></span>
            <span style="font-weight:700;color:#2e7d32">Items Brought</span>
          </div>
          <div style="font-size:.82rem;font-weight:700;color:#222">${fmtUGX(itemsVal)}</div>
          <div style="font-size:.72rem;color:#888">${itemsPct}% of total &nbsp;·&nbsp; <strong>${itemsCnt.toLocaleString()}</strong> item${itemsCnt !== 1 ? 's' : ''}</div>
        </div>
        <div>
          <div style="display:flex;align-items:center;gap:7px;margin-bottom:3px">
            <span style="width:12px;height:12px;border-radius:3px;background:#d32f2f;display:inline-block;flex-shrink:0"></span>
            <span style="font-weight:700;color:#c62828">Outstanding</span>
          </div>
          <div style="font-size:.82rem;font-weight:700;color:#222">${fmtUGX(out)}</div>
          <div style="font-size:.72rem;color:#888">${outPct}% of total</div>
        </div>
      </div>`;
}

// ── Class table ──────────────────────────────────────────────────────────────
function renderClassTable(data) {
    const tbody = document.getElementById('classBody');
    if (!data.length) { tbody.innerHTML = `<tr><td colspan="11"><div class="empty-state"><i class="fas fa-table"></i><p>No class data found</p></div></td></tr>`; return; }

    let tot = { total:0, tracked:0, completed:0, cash:0, items:0, value:0, balance:0 };
    const rows = data.map(r => {
        const total     = +r.total_students      || 0;
        const tracked   = +r.students_tracked    || 0;
        const completed = +r.completed_students  || 0;
        const cash      = +r.total_cash          || 0;
        const items     = +r.total_items         || 0;
        const value     = +r.total_value         || 0;
        const balance   = +r.total_balance       || 0;
        const pct       = total > 0 ? Math.min((completed / total) * 100, 100) : 0;
        const col       = pct >= 80 ? '#388e3c' : pct >= 50 ? '#f57c00' : '#d32f2f';
        const sec       = r.section || '';
        const secBadge  = sec === 'Day' ? `<span class="sbadge sb-day"><i class="fas fa-sun"></i> Day</span>`
                        : sec === 'Boarding' ? `<span class="sbadge sb-boarding"><i class="fas fa-moon"></i> Boarding</span>`
                        : `<span class="sbadge sb-all">All</span>`;
        tot.total += total; tot.tracked += tracked; tot.completed += completed;
        tot.cash  += cash;  tot.items   += items;   tot.value += value; tot.balance += balance;
        return `<tr>
          <td><strong>${esc(r.current_class)}</strong></td>
          <td>${esc(r.stream || '—')}</td>
          <td>${secBadge}</td>
          <td>${total}</td><td>${tracked}</td><td>${completed}</td>
          <td>${fmtUGX(cash)}</td>
          <td>${items.toLocaleString()}</td>
          <td>${fmtUGX(value)}</td>
          <td style="color:${balance>0?'var(--red)':'var(--g700)'};font-weight:600">${fmtUGX(balance)}</td>
          <td style="min-width:100px">
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1"><div class="prog-bar"><div class="prog-fill" style="width:${pct}%;background:${col}"></div></div></div>
              <span style="font-size:.78rem;font-weight:700;min-width:36px">${pct.toFixed(0)}%</span>
            </div>
          </td>
        </tr>`;
    }).join('');

    const ovPct = tot.total > 0 ? Math.min((tot.completed / tot.total) * 100, 100) : 0;
    const totRow = `<tr class="totals-row">
      <td colspan="3"><strong>TOTAL</strong></td>
      <td><strong>${tot.total}</strong></td><td><strong>${tot.tracked}</strong></td><td><strong>${tot.completed}</strong></td>
      <td><strong>${fmtUGX(tot.cash)}</strong></td>
      <td><strong>${tot.items.toLocaleString()}</strong></td>
      <td><strong>${fmtUGX(tot.value)}</strong></td>
      <td style="color:var(--red);font-weight:700"><strong>${fmtUGX(tot.balance)}</strong></td>
      <td style="font-weight:700">${ovPct.toFixed(1)}%</td>
    </tr>`;
    tbody.innerHTML = rows + totRow;
}

// ── Inventory table ──────────────────────────────────────────────────────────
function renderInventoryTable(data) {
    const tbody = document.getElementById('inventoryBody');
    if (!data.length) { tbody.innerHTML = `<tr><td colspan="10"><div class="empty-state"><i class="fas fa-boxes-stacked"></i><p>No inventory data found</p></div></td></tr>`; return; }

    tbody.innerHTML = data.map(r => {
        const exp   = +r.expected_students || 0;
        const trk   = +r.students_involved || 0;
        const items = +r.total_items || 0;
        const cash  = +r.total_cash  || 0;
        const value = +r.total_value || 0;
        const bal   = +r.total_balance || 0;
        // Completion is value-based: (total value received) / (expected students × qty × unit price)
        // This correctly handles students who paid cash instead of bringing items
        const expValue = exp * (+r.quantity_per_student || 1) * (+r.cash_equivalent || 0);
        const pct = expValue > 0 ? Math.min((value / expValue) * 100, 100) : 0;
        const col = pct >= 80 ? '#388e3c' : pct >= 50 ? '#f57c00' : '#d32f2f';
        const sec     = r.section_type || 'All';
        const secBadge = sec === 'Day' ? `<span class="sbadge sb-day"><i class="fas fa-sun"></i> Day</span>`
                       : sec === 'Boarding' ? `<span class="sbadge sb-boarding"><i class="fas fa-moon"></i> Boarding</span>`
                       : `<span class="sbadge sb-all"><i class="fas fa-users"></i> All</span>`;
        const classScope = r['class'] ? `${esc(r['class'])}${r.stream ? ' · '+esc(r.stream) : ''}` : 'All Classes';

        return `<tr>
          <td><strong>${esc(r.requirement_name)}</strong></td>
          <td style="font-size:.78rem">${classScope}</td>
          <td>${secBadge}</td>
          <td>${exp}</td><td>${trk}</td><td>${items.toLocaleString()}</td>
          <td>${fmtUGX(cash)}</td><td>${fmtUGX(value)}</td>
          <td style="color:${bal>0?'var(--red)':'var(--g700)'};font-weight:600">${fmtUGX(bal)}</td>
          <td style="min-width:110px">
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1"><div class="prog-bar"><div class="prog-fill" style="width:${pct}%;background:${col}"></div></div></div>
              <span style="font-size:.78rem;font-weight:700;min-width:36px">${pct.toFixed(0)}%</span>
            </div>
          </td>
        </tr>`;
    }).join('');
}

// ── Student table ────────────────────────────────────────────────────────────
function renderStudentTable(data) {
    const tbody   = document.getElementById('studentBody');
    const pagBar  = document.getElementById('stuPagBar');
    const pagInfo = document.getElementById('stuPagInfo');
    const pagCtrl = document.getElementById('stuPagControls');
    const total   = data.length;

    document.getElementById('stuCount').textContent = `${total} student${total !== 1 ? 's' : ''}`;

    if (!total) {
        tbody.innerHTML = `<tr><td colspan="12"><div class="empty-state"><i class="fas fa-users"></i><p>No students found for these filters</p></div></td></tr>`;
        pagBar.style.display = 'none';
        return;
    }

    // Clamp current page
    const totalPages = Math.ceil(total / stuPageSize);
    if (stuCurrentPage > totalPages) stuCurrentPage = totalPages;
    if (stuCurrentPage < 1) stuCurrentPage = 1;

    const start = (stuCurrentPage - 1) * stuPageSize;
    const end   = Math.min(start + stuPageSize, total);
    const slice = data.slice(start, end);

    tbody.innerHTML = slice.map(s => {
        const overall   = s.overall || 'No Records';
        const badgeCls  = overall === 'Completed' ? 'b-completed' : overall === 'No Records' ? 'b-no-records' : overall === 'Waived' ? 'b-waived' : 'b-in-progress';
        const sec       = s.section || '';
        const secIcon   = sec === 'Day' ? '☀️' : sec === 'Boarding' ? '🌙' : '';
        return `<tr>
          <td style="font-weight:600">${esc(s.first_name)} ${esc(s.last_name)}</td>
          <td style="font-size:.8rem;color:#888">${esc(s.student_id)}</td>
          <td>${esc(s.current_class || '—')}</td>
          <td>${esc(s.stream || '—')}</td>
          <td>${secIcon} ${esc(sec || '—')}</td>
          <td style="text-align:center">${s.total_reqs}</td>
          <td style="text-align:center;color:var(--g700);font-weight:600">${s.done}</td>
          <td style="text-align:center;color:var(--amber);font-weight:600">${s.partial}</td>
          <td style="text-align:center;color:var(--red);font-weight:600">${s.pending}</td>
          <td>${fmtUGX(s.cash_paid)}</td>
          <td style="color:${+s.balance>0?'var(--red)':'var(--g700)'};font-weight:600">${+s.balance > 0 ? fmtUGX(s.balance) : '—'}</td>
          <td><span class="badge ${badgeCls}">${overall}</span></td>
        </tr>`;
    }).join('');

    // Pagination info
    pagInfo.textContent = `Showing ${start + 1}–${end} of ${total} students`;

    // Build page buttons (max 7 shown with ellipsis)
    let btns = '';
    btns += `<button class="pg-btn" onclick="stuGoPage(${stuCurrentPage - 1})" ${stuCurrentPage === 1 ? 'disabled' : ''}><i class="fas fa-chevron-left"></i></button>`;
    const delta = 2;
    const range = [];
    for (let i = Math.max(1, stuCurrentPage - delta); i <= Math.min(totalPages, stuCurrentPage + delta); i++) range.push(i);
    if (range[0] > 1) {
        btns += `<button class="pg-btn" onclick="stuGoPage(1)">1</button>`;
        if (range[0] > 2) btns += `<button class="pg-btn" disabled style="border:none;background:transparent;cursor:default">…</button>`;
    }
    range.forEach(p => {
        btns += `<button class="pg-btn${p === stuCurrentPage ? ' active' : ''}" onclick="stuGoPage(${p})">${p}</button>`;
    });
    if (range[range.length - 1] < totalPages) {
        if (range[range.length - 1] < totalPages - 1) btns += `<button class="pg-btn" disabled style="border:none;background:transparent;cursor:default">…</button>`;
        btns += `<button class="pg-btn" onclick="stuGoPage(${totalPages})">${totalPages}</button>`;
    }
    btns += `<button class="pg-btn" onclick="stuGoPage(${stuCurrentPage + 1})" ${stuCurrentPage === totalPages ? 'disabled' : ''}><i class="fas fa-chevron-right"></i></button>`;
    pagCtrl.innerHTML = btns;

    pagBar.style.display = totalPages > 1 || total > 25 ? 'flex' : 'none';
}

function stuGoPage(p) {
    const totalPages = Math.ceil(filteredStuds.length / stuPageSize);
    if (p < 1 || p > totalPages) return;
    stuCurrentPage = p;
    renderStudentTable(filteredStuds);
    // Scroll student table into view smoothly
    document.getElementById('panel-students').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function stuSetPageSize() {
    stuPageSize    = parseInt(document.getElementById('stuPageSize').value) || 50;
    stuCurrentPage = 1;
    renderStudentTable(filteredStuds);
}

function filterStudentTable() {
    const q   = (document.getElementById('stuSearch').value || '').toLowerCase();
    const sts = document.getElementById('stuStatusFilter').value;
    const filt = reportData.students.filter(s => {
        const name = `${s.first_name} ${s.last_name} ${s.student_id} ${s.current_class} ${s.stream}`.toLowerCase();
        const matchQ   = !q   || name.includes(q);
        const matchSts = !sts || s.overall === sts;
        return matchQ && matchSts;
    });
    filteredStuds  = filt;
    stuCurrentPage = 1;  // reset to first page whenever filter changes
    renderStudentTable(filt);
}

// ── Tabs ─────────────────────────────────────────────────────────────────────
function switchTab(name) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    document.getElementById('panel-' + name).classList.add('active');
}

// ── Export helpers ────────────────────────────────────────────────────────────
function getFilterLabel() {
    const t = document.getElementById('fTerm').value;
    const y = document.getElementById('fYear').value;
    const c = document.getElementById('fClass').value   || 'All Classes';
    const s = document.getElementById('fSection').value || 'All Sections';
    return `${t} ${y} · ${c} · ${s}`;
}
function datestamp() { return new Date().toISOString().split('T')[0]; }

// Class Excel
function exportClassExcel() {
    const rows = [['Class','Stream','Section','Total Students','Tracked','Completed','Cash (UGX)','Value (UGX)','Balance (UGX)','% Done']];
    reportData.classSummary.forEach(r => {
        const total = +r.total_students || 0;
        const done  = +r.completed_students || 0;
        rows.push([r.current_class, r.stream||'', r.section||'All', total, +r.students_tracked||0, done, +r.total_cash||0, +r.total_value||0, +r.total_balance||0, total>0?((done/total)*100).toFixed(1)+'%':'0%']);
    });
    xlsxDownload(rows, 'class-summary-' + datestamp());
}

// Inventory Excel
function exportInventoryExcel() {
    const rows = [['Requirement','Class/Stream','Section','Expected Students','Tracked','Items','Cash (UGX)','Value (UGX)','Balance (UGX)','% Done']];
    reportData.inventory.forEach(r => {
        const exp      = +r.expected_students    || 0;
        const items    = +r.total_items          || 0;
        const value    = +r.total_value          || 0;
        // Value-based %: matches the table rendering
        const expValue = exp * (+r.quantity_per_student || 1) * (+r.cash_equivalent || 0);
        const pct      = expValue > 0 ? ((value / expValue) * 100).toFixed(1) + '%' : '0%';
        rows.push([r.requirement_name, r['class'] ? (r['class'] + (r.stream ? ' / '+r.stream : '')) : 'All', r.section_type||'All', exp, +r.students_involved||0, items, +r.total_cash||0, value, +r.total_balance||0, pct]);
    });
    xlsxDownload(rows, 'inventory-' + datestamp());
}

// Student Excel
function exportStudentExcel() {
    const rows = [['Name','Student ID','Class','Stream','Section','Total Reqs','Done','Partial','Pending','Cash (UGX)','Balance (UGX)','Status']];
    filteredStuds.forEach(s => rows.push([`${s.first_name} ${s.last_name}`, s.student_id, s.current_class||'', s.stream||'', s.section||'', s.total_reqs, s.done, s.partial, s.pending, +s.cash_paid||0, +s.balance||0, s.overall]));
    xlsxDownload(rows, 'students-requirements-' + datestamp());
}

function xlsxDownload(rows, filename) {
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(rows);
    XLSX.utils.book_append_sheet(wb, ws, 'Report');
    XLSX.writeFile(wb, filename + '.xlsx');
    notify('Exported', 'Excel file downloaded.', 'success');
}

// Class PDF
function exportClassPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');
    doc.setFontSize(14); doc.setTextColor(46,125,50);
    doc.text('Class Compliance Summary', 14, 16);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text(getFilterLabel() + '  ·  Generated: ' + new Date().toLocaleDateString('en-UG'), 14, 23);
    const head = [['Class','Stream','Section','Total','Tracked','Completed','Cash (UGX)','Value (UGX)','Balance (UGX)','% Done']];
    const body = reportData.classSummary.map(r => {
        const total = +r.total_students||0, done = +r.completed_students||0;
        return [r.current_class, r.stream||'—', r.section||'All', total, +r.students_tracked||0, done, fmtUGX(r.total_cash||0), fmtUGX(r.total_value||0), fmtUGX(r.total_balance||0), total>0?((done/total)*100).toFixed(1)+'%':'0%'];
    });
    doc.autoTable({ head, body, startY: 28, theme:'grid', headStyles:{fillColor:[67,160,71],fontSize:8}, bodyStyles:{fontSize:7.5} });
    doc.save('class-summary-' + datestamp() + '.pdf');
    notify('Exported', 'PDF downloaded.', 'success');
}

// Inventory PDF
function exportInventoryPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');
    doc.setFontSize(14); doc.setTextColor(46,125,50);
    doc.text('Requirement Inventory Report', 14, 16);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text(getFilterLabel() + '  ·  Generated: ' + new Date().toLocaleDateString('en-UG'), 14, 23);
    const head = [['Requirement','Scope','Section','Expected','Tracked','Items','Cash','Value','Balance','% Done']];
    const body = reportData.inventory.map(r => {
        const exp      = +r.expected_students    || 0;
        const items    = +r.total_items          || 0;
        const value    = +r.total_value          || 0;
        const expValue = exp * (+r.quantity_per_student || 1) * (+r.cash_equivalent || 0);
        const pct      = expValue > 0 ? ((value / expValue) * 100).toFixed(0) + '%' : '0%';
        return [r.requirement_name, r['class']||(r.stream||'All Classes'), r.section_type||'All', exp, +r.students_involved||0, items, fmtUGX(r.total_cash||0), fmtUGX(value), fmtUGX(r.total_balance||0), pct];
    });
    doc.autoTable({ head, body, startY: 28, theme:'grid', headStyles:{fillColor:[67,160,71],fontSize:7.5}, bodyStyles:{fontSize:7} });
    doc.save('inventory-' + datestamp() + '.pdf');
    notify('Exported', 'PDF downloaded.', 'success');
}

// Student PDF
function exportStudentPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');
    doc.setFontSize(14); doc.setTextColor(46,125,50);
    doc.text('Student Requirements Status', 14, 16);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text(getFilterLabel() + '  ·  Generated: ' + new Date().toLocaleDateString('en-UG'), 14, 23);
    const head = [['Name','ID','Class','Stream','Section','Reqs','Done','Partial','Pending','Balance (UGX)','Status']];
    const body = filteredStuds.map(s => [`${s.first_name} ${s.last_name}`, s.student_id, s.current_class||'', s.stream||'', s.section||'', s.total_reqs, s.done, s.partial, s.pending, fmtUGX(s.balance||0), s.overall]);
    doc.autoTable({ head, body, startY: 28, theme:'grid', headStyles:{fillColor:[67,160,71],fontSize:8}, bodyStyles:{fontSize:7} });
    doc.save('students-requirements-' + datestamp() + '.pdf');
    notify('Exported', 'PDF downloaded.', 'success');
}

// ── Utilities ─────────────────────────────────────────────────────────────────
function showLoader() { document.getElementById('page-loader').classList.add('show'); }
function hideLoader() { document.getElementById('page-loader').classList.remove('show'); }
function enc(v) { return encodeURIComponent(v); }
function esc(v) {
    return String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtUGX(v) { return 'UGX ' + Number(v || 0).toLocaleString('en-UG'); }
function notify(title, msg, type = 'success', dur = 4500) {
    const icons = { success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
    const n = document.createElement('div');
    n.className = `notif ${type}`;
    n.innerHTML = `
      <i class="fas ${icons[type]||icons.info} notif-icon"></i>
      <div class="notif-body">
        <div class="notif-title">${esc(title)}</div>
        <div class="notif-msg">${esc(msg)}</div>
      </div>
      <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('notif-stack').prepend(n);
    setTimeout(() => {
        n.style.opacity = '0'; n.style.transform = 'translateX(30px)';
        setTimeout(() => n.remove(), 300);
    }, dur);
}
</script>
</body>
</html>
<?php $conn->close(); ?>