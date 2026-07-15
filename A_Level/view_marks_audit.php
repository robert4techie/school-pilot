<?php
declare(strict_types=1);

/**
 * view_marks_audit.php
 * SchoolPilot — A-Level Marks Audit Log
 *
 * Architecture mirrors user_logs.php:
 *   • AJAX-driven table (search / filter / date-range / paginate)
 *   • Delete by date range — two-step: preview → type DELETE → execute
 *   • PDF and Excel export
 *   • Skeleton loading, toast notifications
 */

require_once '../auth.php';
require_once '../conn.php';
require_once 'audit_log_helper.php';

ensureAuditTable($conn); // idempotent — creates table on first visit

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── AJAX dispatcher ───────────────────────────────────────────────────────────
$ajax = $_GET['ajax'] ?? '';

// ── DELETE by date range (POST, two-step) ─────────────────────────────────────
if ($ajax === 'delete_range') {
    header('Content-Type: application/json');

    $submitted = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submitted)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }

    $date_from = $_POST['date_from'] ?? '';
    $date_to   = $_POST['date_to']   ?? '';
    $from_dt   = DateTime::createFromFormat('Y-m-d', $date_from);
    $to_dt     = DateTime::createFromFormat('Y-m-d', $date_to);

    if (!$from_dt || !$to_dt) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format provided.']);
        exit;
    }
    if ($from_dt > $to_dt) {
        echo json_encode(['success' => false, 'message' => '"From" date must be before or equal to "To" date.']);
        exit;
    }

    // Count first
    $cstmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM marks_audit_log
         WHERE DATE(performed_at) >= ? AND DATE(performed_at) <= ?'
    );
    $cstmt->bind_param('ss', $date_from, $date_to);
    $cstmt->execute();
    $count = (int) $cstmt->get_result()->fetch_assoc()['total'];
    $cstmt->close();

    if ($count === 0) {
        echo json_encode(['success' => false, 'message' => 'No audit entries found in the selected date range.']);
        exit;
    }

    // Step 1 — preview only
    if (!(int) ($_POST['confirmed'] ?? 0)) {
        echo json_encode(['success' => true, 'preview' => true, 'count' => $count]);
        exit;
    }

    // Step 2 — execute
    $dstmt = $conn->prepare(
        'DELETE FROM marks_audit_log
         WHERE DATE(performed_at) >= ? AND DATE(performed_at) <= ?'
    );
    $dstmt->bind_param('ss', $date_from, $date_to);
    $dstmt->execute();
    $affected = $dstmt->affected_rows;
    $dstmt->close();

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // rotate after destructive action
    echo json_encode([
        'success'  => true,
        'deleted'  => $affected,
        'new_csrf' => $_SESSION['csrf_token'],
    ]);
    exit;
}

// ── Fetch logs / export (GET) ─────────────────────────────────────────────────
if ($ajax === 'logs' || $ajax === 'export') {
    header('Content-Type: application/json');

    $page      = max(1, (int) ($_GET['page']      ?? 1));
    $search    = trim($_GET['search']    ?? '');
    $filter    = $_GET['filter']    ?? 'all';   // all | insert | update | delete
    $date_from = trim($_GET['date_from'] ?? '');
    $date_to   = trim($_GET['date_to']   ?? '');

    if (!in_array($filter, ['all', 'insert', 'update', 'delete'], true)) {
        $filter = 'all';
    }

    $use_from = ($date_from !== '' && DateTime::createFromFormat('Y-m-d', $date_from) !== false);
    $use_to   = ($date_to   !== '' && DateTime::createFromFormat('Y-m-d', $date_to)   !== false);

    $where_parts = [];
    $bind_params = [];
    $bind_types  = '';

    if ($search !== '') {
        $like = "%{$search}%";
        $where_parts[] = '(l.performed_by LIKE ? OR l.student_id LIKE ? OR l.subject LIKE ? OR l.class LIKE ? OR l.paper LIKE ?)';
        array_push($bind_params, $like, $like, $like, $like, $like);
        $bind_types .= 'sssss';
    }

    if ($filter !== 'all') {
        $where_parts[] = 'l.action = ?';
        $bind_params[] = strtoupper($filter);
        $bind_types   .= 's';
    }

    if ($use_from) {
        $where_parts[] = 'DATE(l.performed_at) >= ?';
        $bind_params[] = $date_from;
        $bind_types   .= 's';
    }
    if ($use_to) {
        $where_parts[] = 'DATE(l.performed_at) <= ?';
        $bind_params[] = $date_to;
        $bind_types   .= 's';
    }

    $where    = $where_parts ? ('WHERE ' . implode(' AND ', $where_parts)) : '';
    $base_sql = "FROM marks_audit_log l
                 LEFT JOIN students  s  ON s.student_id = l.student_id
                 LEFT JOIN exam_sets es ON es.id = CAST(l.exam_type AS UNSIGNED)
                 {$where}";

    // Total count
    $cstmt = $conn->prepare("SELECT COUNT(*) AS total {$base_sql}");
    if ($bind_params) $cstmt->bind_param($bind_types, ...$bind_params);
    $cstmt->execute();
    $total_rows  = (int) $cstmt->get_result()->fetch_assoc()['total'];
    $cstmt->close();

    $per_page    = 20;
    $total_pages = max(1, (int) ceil($total_rows / $per_page));

    $select = "SELECT l.id, l.action, l.performed_by, l.performed_at,
                      l.student_id, l.class, l.term, l.year,
                      l.subject, l.exam_type, l.paper, l.old_mark, l.new_mark,
                      COALESCE(CONCAT(s.first_name, ' ', s.last_name), l.student_id) AS student_name,
                      COALESCE(es.exam_set, l.exam_type) AS exam_type_name";

    if ($ajax === 'export') {
        $stmt = $conn->prepare("{$select} {$base_sql} ORDER BY l.performed_at DESC");
        if ($bind_params) $stmt->bind_param($bind_types, ...$bind_params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['success' => true, 'data' => $rows]);
    } else {
        $offset      = ($page - 1) * $per_page;
        $page_params = $bind_params;
        $page_types  = $bind_types . 'ii';
        array_push($page_params, $offset, $per_page);

        $stmt = $conn->prepare("{$select} {$base_sql} ORDER BY l.performed_at DESC LIMIT ?, ?");
        $stmt->bind_param($page_types, ...$page_params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode([
            'success'    => true,
            'data'       => $rows,
            'pagination' => [
                'current_page'     => $page,
                'total_pages'      => $total_pages,
                'total_records'    => $total_rows,
                'records_per_page' => $per_page,
            ],
        ]);
    }
    exit;
}

// ── Header stats (server-side, for pills) ─────────────────────────────────────
function fetchStat(mysqli $conn, string $sql): int
{
    $r = $conn->query($sql);
    return $r ? (int) $r->fetch_row()[0] : 0;
}
$stat_total  = fetchStat($conn, "SELECT COUNT(*) FROM marks_audit_log");
$stat_insert = fetchStat($conn, "SELECT COUNT(*) FROM marks_audit_log WHERE action='INSERT'");
$stat_update = fetchStat($conn, "SELECT COUNT(*) FROM marks_audit_log WHERE action='UPDATE'");
$stat_delete = fetchStat($conn, "SELECT COUNT(*) FROM marks_audit_log WHERE action='DELETE'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Marks Audit Log &mdash; SchoolPilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
<style>
/* ── Variables ─────────────────────────────────────────── */
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--red-light:#ffebee;--orange:#e65100;--blue:#1565c0;
  --amber:#f57c00;--amber-light:#fff8e1;
  --gray:#546e7a;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);--shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --transition:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}
a{color:inherit;text-decoration:none}

/* ── Layout ─────────────────────────────────────────────── */
.page{max-width:100%;margin:0 auto;padding:24px 20px 48px}

/* ── Page Header ────────────────────────────────────────── */
.page-header{
  background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);
  border-radius:var(--radius-lg);padding:28px 32px;
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:20px;margin-bottom:24px;margin-top:40px;
  box-shadow:var(--shadow-lg);
}
.page-header h1{color:#fff;font-size:1.55rem;font-weight:700;letter-spacing:.3px}
.page-header p{color:rgba(255,255,255,.78);font-size:.9rem;margin-top:3px}
.stats-row{display:flex;gap:12px;flex-wrap:wrap}
.stat-pill{
  background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);
  border-radius:40px;padding:8px 18px;text-align:center;min-width:80px;
  cursor:default;transition:background var(--transition);
}
.stat-pill:hover{background:rgba(255,255,255,.22)}
.stat-pill .n{font-size:1.35rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.72rem;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.5px}

/* ── Card ───────────────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden}

/* ── Toolbar ────────────────────────────────────────────── */
.toolbar{padding:16px 24px;border-bottom:1px solid #e8ede9;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.toolbar-left{display:flex;flex-wrap:wrap;gap:10px;align-items:center;flex:1 1 auto}
.toolbar-right{display:flex;gap:10px;align-items:center;flex-shrink:0}

/* ── Date filter bar ────────────────────────────────────── */
.date-range-bar{
  padding:12px 24px 14px;border-bottom:1px solid #e8ede9;
  background:#fafcfa;display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;
}
.date-range-bar label{
  font-size:.78rem;font-weight:700;color:#4a6a4c;
  text-transform:uppercase;letter-spacing:.45px;display:block;margin-bottom:4px;
}
.date-group{display:flex;flex-direction:column}
.date-group input[type="date"]{
  padding:8px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);
  font-size:.875rem;font-family:inherit;color:#333;background:#fff;cursor:pointer;
  transition:border-color var(--transition),box-shadow var(--transition);min-width:160px;
}
.date-group input[type="date"]:focus{
  outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12);
}
.date-sep{color:#8a9a8b;font-size:.85rem;padding-bottom:8px;font-weight:600}
.date-active-badge{
  display:inline-flex;align-items:center;gap:6px;
  background:#e8f5e9;border:1px solid #a5d6a7;color:var(--g800);
  border-radius:20px;padding:4px 12px;font-size:.76rem;font-weight:700;
}
.date-active-badge button{
  background:none;border:none;cursor:pointer;color:var(--g700);
  padding:0;line-height:1;font-size:.85rem;margin-left:2px;
  display:flex;align-items:center;
}

/* ── Delete range bar ───────────────────────────────────── */
.delete-range-bar{
  padding:10px 24px 14px;border-bottom:1px solid #fce4e4;
  background:#fffafa;display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;
}
.delete-range-bar label{
  font-size:.78rem;font-weight:700;color:#b71c1c;
  text-transform:uppercase;letter-spacing:.45px;display:block;margin-bottom:4px;
}
.delete-range-bar input[type="date"]{
  padding:8px 12px;border:1.5px solid #ffcdd2;border-radius:var(--radius);
  font-size:.875rem;font-family:inherit;color:#333;background:#fff;cursor:pointer;
  transition:border-color var(--transition);min-width:160px;
}
.delete-range-bar input[type="date"]:focus{
  outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(211,47,47,.10);
}
.delete-zone-header{
  width:100%;display:flex;align-items:center;gap:8px;
  font-size:.82rem;font-weight:700;color:#b71c1c;padding-bottom:4px;
}

/* ── Inputs ─────────────────────────────────────────────── */
.search-wrap{position:relative;min-width:240px}
.search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.85rem}
.search-wrap input{
  width:100%;padding:9px 12px 9px 34px;border:1.5px solid #d0dbd1;
  border-radius:var(--radius);font-size:.875rem;font-family:inherit;
  transition:border-color var(--transition),box-shadow var(--transition);
}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.filter-select{
  padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);
  font-size:.875rem;font-family:inherit;background:#fff;cursor:pointer;
  min-width:150px;transition:border-color var(--transition);
}
.filter-select:focus{outline:none;border-color:var(--g600)}
.result-count{font-size:.8rem;color:#6b7c6d;white-space:nowrap}

/* ── Buttons ────────────────────────────────────────────── */
.btn{
  display:inline-flex;align-items:center;gap:7px;
  padding:9px 16px;border:none;border-radius:var(--radius);
  font-size:.85rem;font-weight:600;font-family:inherit;
  cursor:pointer;transition:all var(--transition);white-space:nowrap;
}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}
.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-danger{background:var(--red);color:#fff}.btn-danger:hover{background:#b71c1c}
.btn-pdf{background:#c62828;color:#fff}.btn-pdf:hover{background:var(--red)}
.btn-excel{background:var(--g800);color:#fff}.btn-excel:hover{background:var(--g900)}
.btn-delete-toggle{background:#fff3e0;color:#e65100;border:1.5px solid #ffcc80}
.btn-delete-toggle:hover{background:#ffe0b2;transform:none}
.btn-delete-toggle.active{background:#ffebee;color:var(--red);border-color:#ef9a9a}

/* ── Table ──────────────────────────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:13px 14px;text-align:left;font-size:.78rem;font-weight:700;color:#fff;letter-spacing:.4px;white-space:nowrap}
thead th.th-c{text-align:center}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--transition)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:12px 14px;font-size:.855rem;vertical-align:middle}
.td-c{text-align:center}
.sub-text{font-size:.75rem;color:#7a8a7b;margin-top:2px}

/* ── Action badges ──────────────────────────────────────── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
.badge-insert{background:var(--g100);color:var(--g800);border:1px solid #a5d6a7}
.badge-update{background:var(--amber-light);color:var(--amber);border:1px solid #ffe082}
.badge-delete{background:var(--red-light);color:var(--red);border:1px solid #ef9a9a}

/* ── Mark change ────────────────────────────────────────── */
.mark-change{display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
.mark-val{
  display:inline-flex;align-items:center;justify-content:center;
  min-width:32px;height:24px;padding:0 6px;
  border-radius:5px;font-weight:700;font-size:.83rem;
}
.mark-val.mark-old        {background:#fff3e0;color:#e65100;border:1px solid #ffcc80}
.mark-val.mark-empty      {background:#f5f5f5;color:#aaa;border:1px solid #e0e0e0;font-weight:400;font-style:italic}
.mark-val.mark-new-higher {background:var(--g100);color:var(--g800);border:1px solid #b3dab9}
.mark-val.mark-new-lower  {background:var(--red-light);color:var(--red);border:1px solid #ef9a9a}
.mark-val.mark-new-same   {background:#f5f5f5;color:#555;border:1px solid #ddd}
.mark-arrow{color:#ccc;font-size:.65rem}

/* ── User chip ──────────────────────────────────────────── */
.user-chip{
  display:inline-flex;align-items:center;gap:5px;
  background:#f5f7fa;border:1px solid #e4e9ea;
  border-radius:20px;padding:3px 10px;font-size:.78rem;font-weight:600;color:#333;
}
.user-chip i{color:#8a9a8b;font-size:.72rem}

/* ── Student name ───────────────────────────────────────── */
.student-name{font-weight:600;color:var(--g800)}

/* ── Skeleton ───────────────────────────────────────────── */
.skeleton-cell{
  background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);
  background-size:200% 100%;animation:shimmer 1.4s infinite;
  border-radius:4px;height:14px;display:inline-block;width:80%;
}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ── Empty state ────────────────────────────────────────── */
.empty-state{text-align:center;padding:60px 20px;color:#8a9a8b}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;opacity:.4}
.empty-state p{font-size:.95rem}

/* ── Pagination ─────────────────────────────────────────── */
.pagination{padding:16px 24px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e8ede9;flex-wrap:wrap;gap:10px}
.page-info{font-size:.82rem;color:#6b7c6d}
.page-btns{display:flex;gap:4px}
.page-btn{
  width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:6px;
  background:#fff;cursor:pointer;font-size:.82rem;font-weight:600;color:#444;
  display:flex;align-items:center;justify-content:center;
  transition:all var(--transition);font-family:inherit;
}
.page-btn:hover:not(:disabled){border-color:var(--g600);background:var(--g100);color:var(--g800)}
.page-btn.active{background:var(--g700);border-color:var(--g700);color:#fff}
.page-btn:disabled{opacity:.38;cursor:default}

/* ── Modal ──────────────────────────────────────────────── */
.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);animation:fadeOverlay .2s ease}
@keyframes fadeOverlay{from{opacity:0}to{opacity:1}}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:520px;box-shadow:var(--shadow-lg);animation:slideDown .25s ease;margin:auto}
@keyframes slideDown{from{transform:translateY(-24px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%);padding:20px 24px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head.danger{background:linear-gradient(135deg,#b71c1c 0%,var(--red) 100%)}
.modal-head h2{color:#fff;font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background var(--transition)}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:24px 28px}
.modal-footer{padding:16px 28px 24px;display:flex;justify-content:flex-end;border-top:1px solid #eef2ee;gap:10px}

/* ── Delete confirm ─────────────────────────────────────── */
.del-summary{background:#fff8f8;border:1.5px solid #ffcdd2;border-radius:var(--radius);padding:18px 20px;margin:16px 0}
.del-summary .del-count{font-size:2rem;font-weight:800;color:var(--red);line-height:1}
.del-summary .del-label{font-size:.82rem;color:#888;margin-top:2px}
.del-summary .del-range{font-size:.85rem;color:#555;margin-top:10px;font-weight:600}
.del-warning{display:flex;align-items:flex-start;gap:10px;background:#fff3e0;border:1px solid #ffcc80;border-radius:var(--radius);padding:12px 14px;font-size:.82rem;color:#5d3a00;margin-bottom:14px}
.del-warning i{color:var(--orange);font-size:1rem;margin-top:1px;flex-shrink:0}
.confirm-type-wrap label{font-size:.8rem;font-weight:700;color:#555;display:block;margin-bottom:5px}
.confirm-type-wrap input{width:100%;padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;transition:border-color var(--transition)}
.confirm-type-wrap input:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(211,47,47,.10)}
.confirm-type-wrap input.confirmed{border-color:var(--red);background:#fff8f8}

/* ── Spinner ────────────────────────────────────────────── */
.spinner{width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Notifications ──────────────────────────────────────── */
#notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.notif{background:#fff;border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow-lg);display:flex;align-items:flex-start;gap:12px;border-left:4px solid var(--g600);animation:notifIn .3s ease}
.notif.error{border-left-color:var(--red)}.notif.warning{border-left-color:var(--orange)}.notif.info{border-left-color:var(--blue)}
@keyframes notifIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.notif-icon{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.notif.success .notif-icon{color:var(--g700)}.notif.error .notif-icon{color:var(--red)}.notif.warning .notif-icon{color:var(--orange)}.notif.info .notif-icon{color:var(--blue)}
.notif-body{flex:1}.notif-title{font-weight:700;font-size:.85rem;margin-bottom:2px}.notif-msg{font-size:.8rem;color:#666}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:1rem;padding:0;line-height:1;flex-shrink:0}

/* ── Responsive ─────────────────────────────────────────── */
@media(max-width:700px){
  .toolbar{flex-direction:column;align-items:stretch}
  .toolbar-right{flex-wrap:wrap}
  .page-header{flex-direction:column}
  .stats-row{gap:8px}
  .date-range-bar,.delete-range-bar{flex-direction:column;align-items:stretch}
  .date-sep{display:none}
}
</style>
</head>
<body>

<?php require_once '../nav.php'; ?>

<div id="notif-stack"></div>

<div class="page">

  <!-- ══ Page Header ══════════════════════════════════════ -->
  <div class="page-header">
    <div>
      <h1><i class="fas fa-shield-halved" style="margin-right:10px;opacity:.85"></i>Marks Audit Log</h1>
      <p>Complete record of every mark entry, edit and deletion — who did what and when</p>
    </div>
    <div class="stats-row">
      <div class="stat-pill"><span class="n"><?= number_format($stat_total)  ?></span><span class="l">Total</span></div>
      <div class="stat-pill"><span class="n"><?= number_format($stat_insert) ?></span><span class="l">New</span></div>
      <div class="stat-pill"><span class="n"><?= number_format($stat_update) ?></span><span class="l">Edits</span></div>
      <div class="stat-pill"><span class="n"><?= number_format($stat_delete) ?></span><span class="l">Deleted</span></div>
    </div>
  </div>

  <!-- ══ Main Card ════════════════════════════════════════ -->
  <div class="card">

    <!-- ── Primary Toolbar ── -->
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search user, student, subject, class…" autocomplete="off">
        </div>
        <select id="filterSelect" class="filter-select">
          <option value="all">All Actions</option>
          <option value="insert">New Entry (INSERT)</option>
          <option value="update">Edit (UPDATE)</option>
          <option value="delete">Deletion (DELETE)</option>
        </select>
        <button class="btn btn-outline" id="clearBtn"><i class="fas fa-times-circle"></i> Clear</button>
        <button class="btn btn-outline" id="refreshBtn"><i class="fas fa-sync-alt"></i> Refresh</button>
        <span class="result-count" id="resultCount"></span>
        <span id="dateActiveBadge" style="display:none"></span>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-delete-toggle" id="toggleDeleteZone" title="Delete audit entries by date range">
          <i class="fas fa-trash-alt"></i> Delete Range
        </button>
        <button class="btn btn-pdf"   onclick="exportData('pdf')"><i class="fas fa-file-pdf"></i> PDF</button>
        <button class="btn btn-excel" onclick="exportData('excel')"><i class="fas fa-file-excel"></i> Excel</button>
      </div>
    </div>

    <!-- ── Date Filter Bar ── -->
    <div class="date-range-bar">
      <div class="date-group">
        <label>From Date</label>
        <input type="date" id="filterDateFrom">
      </div>
      <div class="date-sep" style="padding-bottom:8px">—</div>
      <div class="date-group">
        <label>To Date</label>
        <input type="date" id="filterDateTo">
      </div>
      <div class="date-group" style="justify-content:flex-end">
        <label>&nbsp;</label>
        <div style="display:flex;gap:8px">
          <button class="btn btn-primary" id="applyDateFilter" style="padding:8px 16px;font-size:.82rem">
            <i class="fas fa-filter"></i> Apply Filter
          </button>
          <button class="btn btn-outline" id="clearDateFilter" style="padding:8px 14px;font-size:.82rem">
            <i class="fas fa-times"></i> Clear
          </button>
        </div>
      </div>
    </div>

    <!-- ── Delete Range Zone (hidden by default) ── -->
    <div class="delete-range-bar" id="deleteRangeBar" style="display:none">
      <div class="delete-zone-header">
        <i class="fas fa-shield-exclamation"></i>
        Delete Audit Entries by Date Range — This action is permanent and cannot be undone
      </div>
      <div class="date-group">
        <label>Delete From</label>
        <input type="date" id="deleteDateFrom">
      </div>
      <div class="date-sep" style="padding-bottom:8px">—</div>
      <div class="date-group">
        <label>Delete To</label>
        <input type="date" id="deleteDateTo">
      </div>
      <div class="date-group" style="justify-content:flex-end">
        <label>&nbsp;</label>
        <button class="btn btn-danger" id="previewDeleteBtn">
          <i class="fas fa-trash-alt"></i> Preview &amp; Delete
        </button>
      </div>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:38px">#</th>
            <th>Date &amp; Time</th>
            <th>Performed By</th>
            <th class="th-c">Action</th>
            <th>Student</th>
            <th>Class</th>
            <th>Subject / Paper</th>
            <th>Term &amp; Year</th>
            <th>Exam Type</th>
            <th class="th-c">Mark Change</th>
          </tr>
        </thead>
        <tbody id="tBody"></tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="pagination">
      <span class="page-info" id="pageInfo"></span>
      <div class="page-btns" id="pageBtns"></div>
    </div>

  </div><!-- /card -->

</div><!-- /page -->

<!-- ══ Delete Confirm Modal ══════════════════════════════ -->
<div id="deleteModal" class="modal" onclick="if(event.target.id==='deleteModal')closeDeleteModal()">
  <div class="modal-box">
    <div class="modal-head danger">
      <h2><i class="fas fa-triangle-exclamation"></i> Confirm Deletion</h2>
      <button class="modal-close" onclick="closeDeleteModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="del-summary">
        <div class="del-count" id="delCount">—</div>
        <div class="del-label">audit entries will be permanently deleted</div>
        <div class="del-range" id="delRangeText"></div>
      </div>
      <div class="del-warning">
        <i class="fas fa-triangle-exclamation"></i>
        <span>This action is <strong>irreversible</strong>. Deleted audit entries cannot be recovered. Export first if you need a record.</span>
      </div>
      <div class="confirm-type-wrap">
        <label for="confirmInput">Type <strong>DELETE</strong> to confirm</label>
        <input type="text" id="confirmInput" placeholder="Type DELETE here…" autocomplete="off">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
      <button class="btn btn-danger" id="confirmDeleteBtn" disabled>
        <i class="fas fa-trash-alt"></i> Delete Entries
      </button>
    </div>
  </div>
</div>

<script>
let CSRF            = <?= json_encode($csrf) ?>;
let currentPage     = 1;
let currentSearch   = '';
let currentFilter   = 'all';
let currentDateFrom = '';
let currentDateTo   = '';
let searchTimer;
let deleteZoneOpen  = false;

// ── Init ──────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    loadLogs();

    // Search (debounced)
    document.getElementById('searchInput').addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            currentSearch = this.value.trim();
            currentPage   = 1;
            loadLogs();
        }, 400);
    });

    // Filter
    document.getElementById('filterSelect').addEventListener('change', function () {
        currentFilter = this.value;
        currentPage   = 1;
        loadLogs();
    });

    // Clear all
    document.getElementById('clearBtn').addEventListener('click', () => {
        currentSearch = ''; currentFilter = 'all';
        currentDateFrom = ''; currentDateTo = ''; currentPage = 1;
        document.getElementById('searchInput').value    = '';
        document.getElementById('filterSelect').value   = 'all';
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value   = '';
        updateDateBadge(); loadLogs();
    });

    // Refresh
    document.getElementById('refreshBtn').addEventListener('click', () => loadLogs());

    // Apply date filter
    document.getElementById('applyDateFilter').addEventListener('click', () => {
        const from = document.getElementById('filterDateFrom').value;
        const to   = document.getElementById('filterDateTo').value;
        if (from && to && from > to) {
            notify('Invalid Range', '"From" date must be before or equal to "To" date.', 'warning');
            return;
        }
        currentDateFrom = from; currentDateTo = to; currentPage = 1;
        updateDateBadge(); loadLogs();
    });

    // Clear date filter
    document.getElementById('clearDateFilter').addEventListener('click', () => {
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value   = '';
        currentDateFrom = ''; currentDateTo = ''; currentPage = 1;
        updateDateBadge(); loadLogs();
    });

    // Toggle delete zone
    document.getElementById('toggleDeleteZone').addEventListener('click', () => {
        deleteZoneOpen = !deleteZoneOpen;
        const zone = document.getElementById('deleteRangeBar');
        const btn  = document.getElementById('toggleDeleteZone');
        zone.style.display = deleteZoneOpen ? 'flex' : 'none';
        btn.classList.toggle('active', deleteZoneOpen);
        btn.innerHTML = deleteZoneOpen
            ? '<i class="fas fa-times"></i> Close'
            : '<i class="fas fa-trash-alt"></i> Delete Range';
    });

    // Preview delete
    document.getElementById('previewDeleteBtn').addEventListener('click', previewDelete);

    // Confirm input gate
    document.getElementById('confirmInput').addEventListener('input', function () {
        const ok = this.value.trim() === 'DELETE';
        document.getElementById('confirmDeleteBtn').disabled = !ok;
        this.classList.toggle('confirmed', ok);
    });

    // Execute delete
    document.getElementById('confirmDeleteBtn').addEventListener('click', executeDelete);
});

// ── Date Badge ────────────────────────────────────────────
function updateDateBadge() {
    const badge = document.getElementById('dateActiveBadge');
    if (currentDateFrom || currentDateTo) {
        const from = currentDateFrom || '—';
        const to   = currentDateTo   || '—';
        badge.style.display = 'inline-flex';
        badge.className     = 'date-active-badge';
        badge.innerHTML = `<i class="fas fa-calendar-check"></i> ${fmtShort(from)} → ${fmtShort(to)}
            <button onclick="clearDateBadge()" title="Remove date filter"><i class="fas fa-times"></i></button>`;
    } else {
        badge.style.display = 'none';
    }
}
function clearDateBadge() {
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value   = '';
    currentDateFrom = ''; currentDateTo = ''; currentPage = 1;
    updateDateBadge(); loadLogs();
}
function fmtShort(d) {
    if (!d || d === '—') return '—';
    try { return new Date(d + 'T00:00:00').toLocaleDateString('en-UG', {day:'2-digit',month:'short',year:'numeric'}); }
    catch(_) { return d; }
}

// ── Load Logs ─────────────────────────────────────────────
function loadLogs() {
    showSkeleton();
    const params = new URLSearchParams({
        ajax      : 'logs',
        page      : currentPage,
        search    : currentSearch,
        filter    : currentFilter,
        date_from : currentDateFrom,
        date_to   : currentDateTo,
    });
    fetch(`${window.location.pathname}?${params}`)
        .then(r => { if (!r.ok) throw new Error('Network error'); return r.json(); })
        .then(d => {
            if (!d.success) throw new Error('Server error');
            renderTable(d.data, d.pagination);
            renderPagination(d.pagination);
        })
        .catch(err => {
            showError(err.message);
            notify('Error', 'Failed to load audit log. Please try again.', 'error');
        });
}

// ── Render Table ──────────────────────────────────────────
function renderTable(data, pagination) {
    const tbody  = document.getElementById('tBody');
    tbody.innerHTML = '';
    const count  = document.getElementById('resultCount');
    const offset = (pagination.current_page - 1) * pagination.records_per_page;

    count.textContent = pagination.total_records
        ? `${pagination.total_records.toLocaleString()} entr${pagination.total_records !== 1 ? 'ies' : 'y'}`
        : '';

    if (!data.length) {
        tbody.innerHTML = `
          <tr><td colspan="10">
            <div class="empty-state">
              <i class="fas fa-shield-halved"></i>
              <p>No audit entries match your filters.</p>
            </div>
          </td></tr>`;
        return;
    }

    data.forEach((row, i) => {
        const num = offset + i + 1;

        // Timestamp
        const dtObj  = new Date(row.performed_at);
        const dtDate = dtObj.toLocaleDateString('en-UG',  {day:'2-digit', month:'short', year:'numeric'});
        const dtTime = dtObj.toLocaleTimeString('en-UG',  {hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:false});

        // Action badge
        const badgeCfg = {
            INSERT: {cls:'badge-insert', icon:'fa-plus-circle'},
            UPDATE: {cls:'badge-update', icon:'fa-pen'},
            DELETE: {cls:'badge-delete', icon:'fa-trash'},
        };
        const bc    = badgeCfg[row.action] || badgeCfg.INSERT;
        const badge = `<span class="badge ${bc.cls}"><i class="fas ${bc.icon}"></i> ${esc(row.action)}</span>`;

        // Mark change
        const oldNum = row.old_mark !== null ? parseInt(row.old_mark) : null;
        const newNum = row.new_mark !== null ? parseInt(row.new_mark) : null;

        const oldHtml = oldNum !== null
            ? `<span class="mark-val mark-old">${oldNum}</span>`
            : `<span class="mark-val mark-empty">—</span>`;

        let newCls = 'mark-new-same';
        if (oldNum !== null && newNum !== null) {
            newCls = newNum > oldNum ? 'mark-new-higher' : newNum < oldNum ? 'mark-new-lower' : 'mark-new-same';
        } else if (oldNum === null && newNum !== null) {
            newCls = 'mark-new-higher'; // fresh INSERT
        }
        const newHtml = newNum !== null
            ? `<span class="mark-val ${newCls}">${newNum}</span>`
            : `<span class="mark-val mark-empty">—</span>`;

        const markChange = `<div class="mark-change">${oldHtml}<i class="fas fa-arrow-right mark-arrow"></i>${newHtml}</div>`;

        // Student
        const studentHtml = (row.student_name && row.student_name !== row.student_id)
            ? `<div class="student-name">${esc(row.student_name)}</div><div class="sub-text">${esc(row.student_id)}</div>`
            : `<div class="student-name">${esc(row.student_id)}</div>`;

        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td style="color:#8a9a8b;font-size:.8rem">${num}</td>
          <td>
            <div style="font-weight:600;font-size:.83rem">${esc(dtDate)}</div>
            <div class="sub-text"><i class="fas fa-clock" style="font-size:.65rem;margin-right:3px;opacity:.6"></i>${esc(dtTime)}</div>
          </td>
          <td><span class="user-chip"><i class="fas fa-user-circle"></i> ${esc(row.performed_by)}</span></td>
          <td class="td-c">${badge}</td>
          <td>${studentHtml}</td>
          <td style="white-space:nowrap">${esc(row.class)}</td>
          <td>
            <div style="font-weight:600">${esc(row.subject)}</div>
            <div class="sub-text"><i class="fas fa-file-alt" style="font-size:.65rem;margin-right:3px;opacity:.6"></i>Paper ${esc(row.paper)}</div>
          </td>
          <td>
            <div>${esc(row.term)}</div>
            <div class="sub-text">${esc(row.year)}</div>
          </td>
          <td style="font-size:.82rem">${esc(row.exam_type_name || row.exam_type)}</td>
          <td class="td-c">${markChange}</td>`;
        tbody.appendChild(tr);
    });
}

// ── Render Pagination ─────────────────────────────────────
function renderPagination(p) {
    const info = document.getElementById('pageInfo');
    const btns = document.getElementById('pageBtns');
    btns.innerHTML = '';

    const start = (p.current_page - 1) * p.records_per_page + 1;
    const end   = Math.min(p.current_page * p.records_per_page, p.total_records);
    info.textContent = p.total_records
        ? `Showing ${start}–${end} of ${p.total_records.toLocaleString()}`
        : '';

    if (p.total_pages <= 1) return;

    btns.appendChild(makePageBtn('<i class="fas fa-chevron-left"></i>', p.current_page - 1, p.current_page <= 1));

    const pages = smartPageRange(p.current_page, p.total_pages);
    let last = 0;
    pages.forEach(pg => {
        if (pg - last > 1) {
            const dots = document.createElement('button');
            dots.className = 'page-btn'; dots.disabled = true; dots.textContent = '…';
            btns.appendChild(dots);
        }
        btns.appendChild(makePageBtn(pg, pg, false, pg === p.current_page));
        last = pg;
    });

    btns.appendChild(makePageBtn('<i class="fas fa-chevron-right"></i>', p.current_page + 1, p.current_page >= p.total_pages));
}

function makePageBtn(label, page, disabled, active = false) {
    const btn = document.createElement('button');
    btn.className = 'page-btn' + (active ? ' active' : '');
    btn.innerHTML = label; btn.disabled = disabled;
    if (!disabled && !active) btn.addEventListener('click', () => { currentPage = page; loadLogs(); });
    return btn;
}

function smartPageRange(current, total) {
    if (total <= 7) return Array.from({length: total}, (_, i) => i + 1);
    const pages = new Set([1, total, current]);
    for (let d = 1; d <= 2; d++) {
        if (current - d >= 1)     pages.add(current - d);
        if (current + d <= total) pages.add(current + d);
    }
    return [...pages].sort((a, b) => a - b);
}

// ── Skeleton & Error ──────────────────────────────────────
function showSkeleton() {
    let html = '';
    for (let i = 0; i < 8; i++) {
        html += `<tr>${Array(10).fill('<td><span class="skeleton-cell"></span></td>').join('')}</tr>`;
    }
    document.getElementById('tBody').innerHTML = html;
    document.getElementById('resultCount').textContent = '';
    document.getElementById('pageInfo').textContent    = '';
    document.getElementById('pageBtns').innerHTML      = '';
}
function showError(msg) {
    document.getElementById('tBody').innerHTML = `
      <tr><td colspan="10">
        <div class="empty-state">
          <i class="fas fa-triangle-exclamation" style="color:var(--red)"></i>
          <p>${esc(msg)}</p>
        </div>
      </td></tr>`;
}

// ── Preview Delete ────────────────────────────────────────
async function previewDelete() {
    const from = document.getElementById('deleteDateFrom').value;
    const to   = document.getElementById('deleteDateTo').value;
    if (!from || !to) { notify('Missing Dates', 'Please select both a "Delete From" and "Delete To" date.', 'warning'); return; }
    if (from > to)    { notify('Invalid Range', '"Delete From" must be before or equal to "Delete To".', 'warning'); return; }

    const btn = document.getElementById('previewDeleteBtn');
    btn.innerHTML = '<span class="spinner"></span> Checking…'; btn.disabled = true;

    try {
        const body = new FormData();
        body.append('csrf_token', CSRF); body.append('date_from', from);
        body.append('date_to', to);      body.append('confirmed', '0');
        const r = await fetch(`${window.location.pathname}?ajax=delete_range`, {method:'POST', body});
        if (!r.ok) throw new Error('Network error');
        const d = await r.json();
        if (!d.success) { notify('Cannot Delete', d.message, 'warning'); return; }

        document.getElementById('delCount').textContent    = d.count.toLocaleString();
        document.getElementById('delRangeText').textContent = `From ${fmtShort(from)} to ${fmtShort(to)}`;
        document.getElementById('confirmInput').value      = '';
        document.getElementById('confirmInput').classList.remove('confirmed');
        document.getElementById('confirmDeleteBtn').disabled = true;
        document.getElementById('deleteModal').dataset.from = from;
        document.getElementById('deleteModal').dataset.to   = to;
        document.getElementById('deleteModal').classList.add('active');

    } catch (err) {
        notify('Error', err.message, 'error');
    } finally {
        btn.innerHTML = '<i class="fas fa-trash-alt"></i> Preview &amp; Delete'; btn.disabled = false;
    }
}

// ── Execute Delete ────────────────────────────────────────
async function executeDelete() {
    const modal = document.getElementById('deleteModal');
    const from  = modal.dataset.from;
    const to    = modal.dataset.to;
    const btn   = document.getElementById('confirmDeleteBtn');
    btn.innerHTML = '<span class="spinner"></span> Deleting…'; btn.disabled = true;

    try {
        const body = new FormData();
        body.append('csrf_token', CSRF); body.append('date_from', from);
        body.append('date_to', to);      body.append('confirmed', '1');
        const r = await fetch(`${window.location.pathname}?ajax=delete_range`, {method:'POST', body});
        if (!r.ok) throw new Error('Network error');
        const d = await r.json();
        if (!d.success) { notify('Deletion Failed', d.message, 'error'); return; }

        CSRF = d.new_csrf;
        closeDeleteModal();
        notify('Deleted', `${d.deleted.toLocaleString()} audit entr${d.deleted !== 1 ? 'ies' : 'y'} permanently removed.`, 'success', 6000);

        document.getElementById('deleteDateFrom').value = '';
        document.getElementById('deleteDateTo').value   = '';
        deleteZoneOpen = false;
        document.getElementById('deleteRangeBar').style.display = 'none';
        document.getElementById('toggleDeleteZone').classList.remove('active');
        document.getElementById('toggleDeleteZone').innerHTML = '<i class="fas fa-trash-alt"></i> Delete Range';
        currentPage = 1; loadLogs();

    } catch (err) {
        notify('Error', err.message, 'error');
        btn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete Entries'; btn.disabled = false;
    }
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
    document.getElementById('confirmInput').value = '';
    document.getElementById('confirmDeleteBtn').disabled = true;
}

// ── Export ────────────────────────────────────────────────
async function exportData(format) {
    const params = new URLSearchParams({
        ajax: 'export', search: currentSearch, filter: currentFilter,
        date_from: currentDateFrom, date_to: currentDateTo,
    });
    notify('Exporting', 'Fetching all matching entries…', 'info');
    try {
        const r = await fetch(`${window.location.pathname}?${params}`);
        if (!r.ok) throw new Error('Network error');
        const d = await r.json();
        if (!d.success) throw new Error('Server returned an error');
        if (!d.data.length) { notify('Empty', 'No entries to export.', 'warning'); return; }
        if (format === 'excel') exportToExcel(d.data);
        else                    exportToPDF(d.data);
    } catch (err) {
        notify('Export Failed', err.message, 'error');
    }
}

function exportToExcel(data) {
    const rows = [['Date & Time','Performed By','Action','Student Name','Student ID','Class','Subject','Paper','Term','Year','Exam Type','Old Mark','New Mark']];
    data.forEach(row => {
        rows.push([
            row.performed_at,
            row.performed_by,
            row.action,
            row.student_name || row.student_id,
            row.student_id,
            row.class,
            row.subject,
            row.paper,
            row.term,
            row.year,
            row.exam_type_name || row.exam_type,
            row.old_mark !== null ? row.old_mark : '—',
            row.new_mark !== null ? row.new_mark : '—',
        ]);
    });
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(rows);
    ws['!cols'] = [{wch:22},{wch:16},{wch:10},{wch:22},{wch:14},{wch:12},{wch:22},{wch:8},{wch:10},{wch:6},{wch:18},{wch:10},{wch:10}];
    XLSX.utils.book_append_sheet(wb, ws, 'Marks Audit Log');
    XLSX.writeFile(wb, `marks-audit-${datestamp()}.xlsx`);
    notify('Exported', 'Excel file downloaded.', 'success');
}

function exportToPDF(data) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');
    doc.setFontSize(16); doc.setTextColor(46,125,50);
    doc.text('Marks Audit Log — SchoolPilot', 14, 18);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text('Generated: ' + new Date().toLocaleDateString('en-UG'), 14, 25);
    if (currentDateFrom || currentDateTo) {
        doc.text(`Date Range: ${currentDateFrom || '—'} to ${currentDateTo || '—'}`, 14, 31);
        doc.text('Total Entries: ' + data.length, 14, 37);
    } else {
        doc.text('Total Entries: ' + data.length, 14, 31);
    }
    doc.autoTable({
        head: [['Date & Time','Performed By','Action','Student','Class','Subject / Paper','Term & Year','Old Mark','New Mark']],
        body: data.map(row => [
            row.performed_at ? new Date(row.performed_at).toLocaleString('en-UG') : '—',
            row.performed_by,
            row.action,
            (row.student_name && row.student_name !== row.student_id)
                ? `${row.student_name}\n(${row.student_id})` : row.student_id,
            row.class,
            `${row.subject} / P${row.paper}`,
            `${row.term} ${row.year}`,
            row.old_mark !== null ? row.old_mark : '—',
            row.new_mark !== null ? row.new_mark : '—',
        ]),
        startY: (currentDateFrom || currentDateTo) ? 44 : 38,
        theme : 'grid',
        headStyles      : {fillColor:[67,160,71], fontSize:8},
        bodyStyles      : {fontSize:7.5},
        alternateRowStyles: {fillColor:[240,248,241]},
        columnStyles: {2:{cellWidth:18}, 7:{cellWidth:18}, 8:{cellWidth:18}},
    });
    doc.save(`marks-audit-${datestamp()}.pdf`);
    notify('Exported', 'PDF report downloaded.', 'success');
}

// ── Notifications ─────────────────────────────────────────
function notify(title, msg, type = 'success', dur = 4000) {
    const icons = {success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
    const n = document.createElement('div');
    n.className = `notif ${type}`;
    n.innerHTML = `
      <i class="fas ${icons[type]||icons.info} notif-icon"></i>
      <div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div>
      <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('notif-stack').prepend(n);
    setTimeout(() => {
        n.style.opacity = '0'; n.style.transform = 'translateX(30px)';
        n.style.transition = '.3s'; setTimeout(() => n.remove(), 300);
    }, dur);
}

// ── Utilities ─────────────────────────────────────────────
function esc(v) {
    return String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function datestamp() { return new Date().toISOString().split('T')[0]; }
</script>
</body>
</html>
