<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction('View User Logs');

// ── CSRF token ─────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── Ajax dispatcher ────────────────────────────────────────
$ajax_action = $_GET['ajax'] ?? '';

// ── DELETE logs by date range (POST) ──────────────────────
if ($ajax_action === 'delete_range') {
    header('Content-Type: application/json');

    // CSRF check
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submitted_token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }

    $date_from = $_POST['date_from'] ?? '';
    $date_to   = $_POST['date_to']   ?? '';

    // Validate date format
    $from_dt = DateTime::createFromFormat('Y-m-d', $date_from);
    $to_dt   = DateTime::createFromFormat('Y-m-d', $date_to);

    if (!$from_dt || !$to_dt) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format provided.']);
        exit;
    }

    if ($from_dt > $to_dt) {
        echo json_encode(['success' => false, 'message' => '"From" date must be before or equal to "To" date.']);
        exit;
    }

    // Count records first
    $count_stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM user_tracking
         WHERE DATE(login_time) >= ? AND DATE(login_time) <= ?"
    );
    $count_stmt->bind_param('ss', $date_from, $date_to);
    $count_stmt->execute();
    $count = (int)$count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();

    if ($count === 0) {
        echo json_encode(['success' => false, 'message' => 'No records found in the selected date range.']);
        exit;
    }

    // Only count — don't delete yet; deletion confirmed in second step
    $confirm = (int)($_POST['confirmed'] ?? 0);
    if (!$confirm) {
        echo json_encode(['success' => true, 'preview' => true, 'count' => $count]);
        exit;
    }

    // Perform deletion
    $del_stmt = $conn->prepare(
        "DELETE FROM user_tracking
         WHERE DATE(login_time) >= ? AND DATE(login_time) <= ?"
    );
    $del_stmt->bind_param('ss', $date_from, $date_to);
    $del_stmt->execute();
    $affected = $del_stmt->affected_rows;
    $del_stmt->close();

    // Regenerate CSRF after destructive action
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    echo json_encode([
        'success'  => true,
        'deleted'  => $affected,
        'new_csrf' => $_SESSION['csrf_token'],
    ]);
    exit;
}

if ($ajax_action === 'logs' || $ajax_action === 'export') {
    header('Content-Type: application/json');

    // Sanitise & validate inputs
    $page      = max(1, (int)($_GET['page']      ?? 1));
    $search    = trim($_GET['search']    ?? '');
    $filter    = $_GET['filter']    ?? 'all';
    $date_from = trim($_GET['date_from'] ?? '');
    $date_to   = trim($_GET['date_to']   ?? '');

    if (!in_array($filter, ['all', 'active', 'staff', 'students'], true)) {
        $filter = 'all';
    }

    // Validate optional date inputs
    $use_date_from = false;
    $use_date_to   = false;
    if ($date_from !== '' && DateTime::createFromFormat('Y-m-d', $date_from)) {
        $use_date_from = true;
    }
    if ($date_to !== '' && DateTime::createFromFormat('Y-m-d', $date_to)) {
        $use_date_to = true;
    }

    // Build parameterised WHERE clause
    $where_parts = [];
    $bind_params = [];
    $bind_types  = '';

    if ($search !== '') {
        $like = "%{$search}%";
        $where_parts[] = '(username LIKE ? OR ip_address LIKE ? OR browser LIKE ? OR device_type LIKE ?)';
        array_push($bind_params, $like, $like, $like, $like);
        $bind_types .= 'ssss';
    }

    switch ($filter) {
        case 'active':
            $where_parts[] = 'logout_time IS NULL';
            break;
        case 'staff':
            $where_parts[] = '(username LIKE ? OR username LIKE ?)';
            array_push($bind_params, 'staff_%', 'admin_%');
            $bind_types .= 'ss';
            break;
        case 'students':
            $where_parts[] = '(username NOT LIKE ? AND username NOT LIKE ?)';
            array_push($bind_params, 'staff_%', 'admin_%');
            $bind_types .= 'ss';
            break;
    }

    // Date range filter
    if ($use_date_from) {
        $where_parts[] = 'DATE(login_time) >= ?';
        $bind_params[] = $date_from;
        $bind_types   .= 's';
    }
    if ($use_date_to) {
        $where_parts[] = 'DATE(login_time) <= ?';
        $bind_params[] = $date_to;
        $bind_types   .= 's';
    }

    $where = $where_parts ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

    // ── Count total records ────────────────────────────────
    $count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM user_tracking $where");
    if ($bind_params) {
        $count_stmt->bind_param($bind_types, ...$bind_params);
    }
    $count_stmt->execute();
    $total_rows       = (int)$count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
    $records_per_page = 20;
    $total_pages      = max(1, (int)ceil($total_rows / $records_per_page));

    if ($ajax_action === 'export') {
        $sql  = "SELECT username, login_time, logout_time, ip_address, device_type, browser,
                        latitude, longitude, actions
                 FROM user_tracking $where ORDER BY login_time DESC";
        $stmt = $conn->prepare($sql);
        if ($bind_params) {
            $stmt->bind_param($bind_types, ...$bind_params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['success' => true, 'data' => $rows]);

    } else {
        $offset      = ($page - 1) * $records_per_page;
        $page_params = $bind_params;
        $page_types  = $bind_types . 'ii';
        array_push($page_params, $offset, $records_per_page);

        $sql  = "SELECT id, username, login_time, logout_time, ip_address, device_type, browser,
                        latitude, longitude, actions
                 FROM user_tracking $where ORDER BY login_time DESC LIMIT ?, ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($page_types, ...$page_params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode([
            'success' => true,
            'data'    => $rows,
            'pagination' => [
                'current_page'     => $page,
                'total_pages'      => $total_pages,
                'total_records'    => $total_rows,
                'records_per_page' => $records_per_page,
            ],
        ]);
    }
    exit;
}

// ── Server-side stats for header pills ────────────────────
function fetchStat(mysqli $conn, string $sql): int {
    $r = $conn->query($sql);
    return $r ? (int)$r->fetch_row()[0] : 0;
}
$stat_total  = fetchStat($conn, "SELECT COUNT(*) FROM user_tracking");
$stat_online = fetchStat($conn, "SELECT COUNT(*) FROM user_tracking WHERE logout_time IS NULL");
$stat_today  = fetchStat($conn, "SELECT COUNT(*) FROM user_tracking WHERE DATE(login_time) = CURDATE()");
$stat_users  = fetchStat($conn, "SELECT COUNT(DISTINCT username) FROM user_tracking");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>User Activity Logs &mdash; School Pilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
<style>
/* ── Variables ───────────────────────────────────────────── */
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--red-light:#ffebee;--orange:#e65100;--blue:#1565c0;--gray:#546e7a;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);--shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --transition:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}
a{color:inherit;text-decoration:none}

/* ── Layout ──────────────────────────────────────────────── */
.page{max-width:100%;margin:0 auto;padding:24px 20px 48px}

/* ── Page Header ─────────────────────────────────────────── */
.page-header{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--radius-lg);padding:28px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;margin-bottom:24px;margin-top:40px;box-shadow:var(--shadow-lg)}
.page-header h1{color:#fff;font-size:1.55rem;font-weight:700;letter-spacing:.3px}
.page-header p{color:rgba(255,255,255,.78);font-size:.9rem;margin-top:3px}
.stats-row{display:flex;gap:12px;flex-wrap:wrap}
.stat-pill{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:40px;padding:8px 18px;text-align:center;min-width:80px;cursor:default;transition:background var(--transition)}
.stat-pill:hover{background:rgba(255,255,255,.22)}
.stat-pill .n{font-size:1.35rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.72rem;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.5px}

/* ── Card ────────────────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden}

/* ── Toolbar ─────────────────────────────────────────────── */
.toolbar{padding:16px 24px;border-bottom:1px solid #e8ede9;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.toolbar-left{display:flex;flex-wrap:wrap;gap:10px;align-items:center;flex:1 1 auto}
.toolbar-right{display:flex;gap:10px;align-items:center;flex-shrink:0}

/* Date range row — sits below the main toolbar row */
.date-range-bar{padding:12px 24px 14px;border-bottom:1px solid #e8ede9;background:#fafcfa;display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px}
.date-range-bar label{font-size:.78rem;font-weight:700;color:#4a6a4c;text-transform:uppercase;letter-spacing:.45px;display:block;margin-bottom:4px}
.date-group{display:flex;flex-direction:column}
.date-group input[type="date"]{padding:8px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;color:#333;background:#fff;cursor:pointer;transition:border-color var(--transition),box-shadow var(--transition);min-width:160px}
.date-group input[type="date"]:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.date-sep{color:#8a9a8b;font-size:.85rem;padding-bottom:8px;font-weight:600}
.date-range-bar .btn-apply{padding:8px 16px;font-size:.82rem}
.date-range-bar .btn-clear-date{padding:8px 14px;font-size:.82rem}
.date-active-badge{display:inline-flex;align-items:center;gap:6px;background:#e8f5e9;border:1px solid #a5d6a7;color:var(--g800);border-radius:20px;padding:4px 12px;font-size:.76rem;font-weight:700}
.date-active-badge button{background:none;border:none;cursor:pointer;color:var(--g700);padding:0;line-height:1;font-size:.85rem;margin-left:2px;display:flex;align-items:center}

/* Delete range zone */
.delete-range-bar{padding:10px 24px 14px;border-bottom:1px solid #fce4e4;background:#fffafa;display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px}
.delete-range-bar label{font-size:.78rem;font-weight:700;color:#b71c1c;text-transform:uppercase;letter-spacing:.45px;display:block;margin-bottom:4px}
.delete-range-bar input[type="date"]{padding:8px 12px;border:1.5px solid #ffcdd2;border-radius:var(--radius);font-size:.875rem;font-family:inherit;color:#333;background:#fff;cursor:pointer;transition:border-color var(--transition);min-width:160px}
.delete-range-bar input[type="date"]:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(211,47,47,.10)}
.delete-zone-header{width:100%;display:flex;align-items:center;gap:8px;font-size:.82rem;font-weight:700;color:#b71c1c;padding-bottom:4px}
.delete-zone-header i{font-size:.9rem}

.search-wrap{position:relative;min-width:220px}
.search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.85rem}
.search-wrap input{width:100%;padding:9px 12px 9px 34px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;transition:border-color var(--transition),box-shadow var(--transition)}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.filter-select{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;background:#fff;cursor:pointer;min-width:130px;transition:border-color var(--transition)}
.filter-select:focus{outline:none;border-color:var(--g600)}
.result-count{font-size:.8rem;color:#6b7c6d;white-space:nowrap}

/* ── Buttons ─────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all var(--transition);white-space:nowrap}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-danger{background:var(--red);color:#fff}.btn-danger:hover{background:#b71c1c}
.btn-danger-outline{background:transparent;color:var(--red);border:1.5px solid #ef9a9a}.btn-danger-outline:hover{background:var(--red-light);border-color:var(--red);transform:none}
.btn-pdf{background:#c62828;color:#fff}.btn-pdf:hover{background:var(--red)}
.btn-excel{background:var(--g800);color:#fff}.btn-excel:hover{background:var(--g900)}
.btn-delete-toggle{background:#fff3e0;color:#e65100;border:1.5px solid #ffcc80}.btn-delete-toggle:hover{background:#ffe0b2;transform:none}
.btn-delete-toggle.active{background:#ffebee;color:var(--red);border-color:#ef9a9a}

/* ── Icon Buttons ────────────────────────────────────────── */
.action-cell{display:flex;gap:5px;align-items:center}
.btn-icon{width:30px;height:30px;border:none;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;transition:all var(--transition);flex-shrink:0}
.btn-icon:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.18)}
.bi-view{background:#e3f2fd;color:#1565c0}.bi-view:hover{background:#1565c0;color:#fff}
.bi-map{background:#e8f5e9;color:var(--g700)}.bi-map:hover{background:var(--g700);color:#fff}

/* ── Table ───────────────────────────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:13px 14px;text-align:left;font-size:.8rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--transition)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:13px 14px;font-size:.875rem;vertical-align:middle}
.user-name{font-weight:600;color:var(--g800)}
.sub-text{font-size:.75rem;color:#7a8a7b;margin-top:2px}

/* ── Status Badges ───────────────────────────────────────── */
.badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.badge-online{background:#e8f5e9;color:#2e7d32}
.badge-offline{background:#f5f5f5;color:#546e7a}

/* ── Skeleton ────────────────────────────────────────────── */
.skeleton-cell{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;height:14px;display:inline-block;width:80%}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ── Empty State ─────────────────────────────────────────── */
.empty-state{text-align:center;padding:60px 20px;color:#8a9a8b}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;opacity:.45}
.empty-state p{font-size:.95rem}

/* ── Pagination ──────────────────────────────────────────── */
.pagination{padding:16px 24px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e8ede9;flex-wrap:wrap;gap:10px}
.page-info{font-size:.82rem;color:#6b7c6d}
.page-btns{display:flex;gap:4px}
.page-btn{width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:6px;background:#fff;cursor:pointer;font-size:.82rem;font-weight:600;color:#444;display:flex;align-items:center;justify-content:center;transition:all var(--transition);font-family:inherit}
.page-btn:hover:not(:disabled){border-color:var(--g600);background:var(--g100);color:var(--g800)}
.page-btn.active{background:var(--g700);border-color:var(--g700);color:#fff}
.page-btn:disabled{opacity:.38;cursor:default}

/* ── Modal (shared) ──────────────────────────────────────── */
.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);animation:fadeOverlay .2s ease}
@keyframes fadeOverlay{from{opacity:0}to{opacity:1}}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:720px;box-shadow:var(--shadow-lg);animation:slideDown .25s ease;margin:auto}
@keyframes slideDown{from{transform:translateY(-24px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%);padding:20px 24px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head.danger{background:linear-gradient(135deg,#b71c1c 0%,var(--red) 100%)}
.modal-head h2{color:#fff;font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background var(--transition)}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:24px 28px}
.modal-footer{padding:16px 28px 24px;display:flex;justify-content:flex-end;border-top:1px solid #eef2ee;gap:10px}

/* ── Action Log ──────────────────────────────────────────── */
.action-log{max-height:400px;overflow-y:auto;background:#f8fdf8;border:1px solid #e0ede0;border-radius:var(--radius);padding:16px;font-size:.85rem;line-height:1.7;color:#333;white-space:pre-wrap;word-break:break-word}
.action-log::-webkit-scrollbar{width:6px}
.action-log::-webkit-scrollbar-track{background:#f0f4f1}
.action-log::-webkit-scrollbar-thumb{background:var(--g400);border-radius:3px}

/* ── Delete Confirm Modal specific ───────────────────────── */
.delete-confirm-box{max-width:480px}
.del-summary{background:#fff8f8;border:1.5px solid #ffcdd2;border-radius:var(--radius);padding:18px 20px;margin:16px 0}
.del-summary .del-count{font-size:2rem;font-weight:800;color:var(--red);line-height:1}
.del-summary .del-label{font-size:.82rem;color:#888;margin-top:2px}
.del-summary .del-range{font-size:.85rem;color:#555;margin-top:10px;font-weight:600}
.del-warning{display:flex;align-items:flex-start;gap:10px;background:#fff3e0;border:1px solid #ffcc80;border-radius:var(--radius);padding:12px 14px;font-size:.82rem;color:#5d3a00;margin-bottom:4px}
.del-warning i{color:var(--orange);font-size:1rem;margin-top:1px;flex-shrink:0}
.confirm-type-wrap{margin-top:14px}
.confirm-type-wrap label{font-size:.8rem;font-weight:700;color:#555;display:block;margin-bottom:5px}
.confirm-type-wrap input{width:100%;padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;transition:border-color var(--transition)}
.confirm-type-wrap input:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(211,47,47,.10)}
.confirm-type-wrap input.confirmed{border-color:var(--red);background:#fff8f8}

/* ── Spinner ─────────────────────────────────────────────── */
.spinner{width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Notifications ───────────────────────────────────────── */
#notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.notif{background:#fff;border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow-lg);display:flex;align-items:flex-start;gap:12px;border-left:4px solid var(--g600);animation:notifIn .3s ease}
.notif.error{border-left-color:var(--red)}.notif.warning{border-left-color:var(--orange)}.notif.info{border-left-color:var(--blue)}
@keyframes notifIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.notif-icon{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.notif.success .notif-icon{color:var(--g700)}.notif.error .notif-icon{color:var(--red)}.notif.warning .notif-icon{color:var(--orange)}.notif.info .notif-icon{color:var(--blue)}
.notif-body{flex:1}.notif-title{font-weight:700;font-size:.85rem;margin-bottom:2px}.notif-msg{font-size:.8rem;color:#666}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:1rem;padding:0;line-height:1;flex-shrink:0}

/* ── Responsive ──────────────────────────────────────────── */
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
<?php require_once 'nav.php'; ?>

<div id="notif-stack"></div>

<div class="page">

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <h1><i class="fas fa-clipboard-list" style="margin-right:10px;opacity:.85"></i>User Activity Logs</h1>
      <p>Monitor logins, sessions and actions across the system</p>
    </div>
    <div class="stats-row">
      <div class="stat-pill"><span class="n"><?= number_format($stat_total) ?></span><span class="l">Total</span></div>
      <div class="stat-pill"><span class="n"><?= number_format($stat_online) ?></span><span class="l">Online</span></div>
      <div class="stat-pill"><span class="n"><?= number_format($stat_today) ?></span><span class="l">Today</span></div>
      <div class="stat-pill"><span class="n"><?= number_format($stat_users) ?></span><span class="l">Users</span></div>
    </div>
  </div>

  <!-- Main Card -->
  <div class="card">

    <!-- ── Primary Toolbar ── -->
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search user, IP, browser…" autocomplete="off">
        </div>
        <select id="filterSelect" class="filter-select">
          <option value="all">All Users</option>
          <option value="active">Active Sessions</option>
          <option value="staff">Staff &amp; Admins</option>
          <option value="students">Students</option>
        </select>
        <button class="btn btn-outline" id="clearBtn"><i class="fas fa-times-circle"></i> Clear</button>
        <button class="btn btn-outline" id="refreshBtn"><i class="fas fa-sync-alt"></i> Refresh</button>
        <span class="result-count" id="resultCount"></span>
        <span id="dateActiveBadge" style="display:none"></span>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-delete-toggle" id="toggleDeleteZone" title="Delete logs by date range">
          <i class="fas fa-trash-alt"></i> Delete Range
        </button>
        <button class="btn btn-pdf"   onclick="exportData('pdf')"><i class="fas fa-file-pdf"></i> PDF</button>
        <button class="btn btn-excel" onclick="exportData('excel')"><i class="fas fa-file-excel"></i> Excel</button>
      </div>
    </div>

    <!-- ── Date Filter Bar ── -->
    <div class="date-range-bar" id="dateFilterBar">
      <div class="date-group">
        <label>From Date</label>
        <input type="date" id="filterDateFrom" title="Filter from this date">
      </div>
      <div class="date-sep" style="padding-bottom:8px">—</div>
      <div class="date-group">
        <label>To Date</label>
        <input type="date" id="filterDateTo" title="Filter up to this date">
      </div>
      <div class="date-group" style="justify-content:flex-end">
        <label>&nbsp;</label>
        <div style="display:flex;gap:8px">
          <button class="btn btn-primary btn-apply" id="applyDateFilter">
            <i class="fas fa-filter"></i> Apply Filter
          </button>
          <button class="btn btn-outline btn-clear-date" id="clearDateFilter">
            <i class="fas fa-times"></i> Clear
          </button>
        </div>
      </div>
    </div>

    <!-- ── Delete Range Zone (hidden by default) ── -->
    <div class="delete-range-bar" id="deleteRangeBar" style="display:none">
      <div class="delete-zone-header">
        <i class="fas fa-shield-exclamation"></i>
        Delete Logs by Date Range — This action is permanent and cannot be undone
      </div>
      <div class="date-group">
        <label>Delete From</label>
        <input type="date" id="deleteDateFrom" title="Delete from this date">
      </div>
      <div class="date-sep" style="padding-bottom:8px">—</div>
      <div class="date-group">
        <label>Delete To</label>
        <input type="date" id="deleteDateTo" title="Delete up to this date">
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
            <th style="width:40px">#</th>
            <th>User</th>
            <th>Login Time</th>
            <th>Logout Time</th>
            <th>Status</th>
            <th>IP Address</th>
            <th>Device</th>
            <th>Location</th>
            <th style="width:90px">Actions</th>
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
  </div>

</div><!-- /page -->

<!-- ══ Action Log Modal ════════════════════════════════════ -->
<div id="logModal" class="modal" onclick="if(event.target.id==='logModal')closeModal()">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-list-check"></i> Action Log</h2>
      <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:.82rem;color:#7a8a7b;margin-bottom:10px">All actions recorded during this session:</p>
      <div class="action-log" id="actionLogContent"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal()">Close</button>
    </div>
  </div>
</div>

<!-- ══ Delete Confirm Modal ════════════════════════════════ -->
<div id="deleteModal" class="modal" onclick="if(event.target.id==='deleteModal')closeDeleteModal()">
  <div class="modal-box delete-confirm-box">
    <div class="modal-head danger">
      <h2><i class="fas fa-triangle-exclamation"></i> Confirm Deletion</h2>
      <button class="modal-close" onclick="closeDeleteModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <!-- Step 1: preview -->
      <div id="delStep1">
        <div class="del-summary">
          <div class="del-count" id="delCount">—</div>
          <div class="del-label">log records will be permanently deleted</div>
          <div class="del-range" id="delRangeText"></div>
        </div>
        <div class="del-warning">
          <i class="fas fa-triangle-exclamation"></i>
          <span>This action is <strong>irreversible</strong>. Deleted logs cannot be recovered. Make sure you have exported or backed up any records you need before proceeding.</span>
        </div>
        <div class="confirm-type-wrap">
          <label for="confirmInput">Type <strong>DELETE</strong> to confirm</label>
          <input type="text" id="confirmInput" placeholder="Type DELETE here…" autocomplete="off">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
      <button class="btn btn-danger" id="confirmDeleteBtn" disabled>
        <i class="fas fa-trash-alt"></i> Delete Records
      </button>
    </div>
  </div>
</div>

<script>
let CSRF = <?= json_encode($csrf) ?>;
let currentPage      = 1;
let currentSearch    = '';
let currentFilter    = 'all';
let currentDateFrom  = '';
let currentDateTo    = '';
let searchTimer;
let deleteZoneOpen   = false;

// ── Init ─────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    loadLogs();

    // Search with debounce
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

    // Clear all filters
    document.getElementById('clearBtn').addEventListener('click', () => {
        currentSearch   = '';
        currentFilter   = 'all';
        currentDateFrom = '';
        currentDateTo   = '';
        currentPage     = 1;
        document.getElementById('searchInput').value    = '';
        document.getElementById('filterSelect').value   = 'all';
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value   = '';
        updateDateBadge();
        loadLogs();
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
        currentDateFrom = from;
        currentDateTo   = to;
        currentPage     = 1;
        updateDateBadge();
        loadLogs();
    });

    // Clear date filter
    document.getElementById('clearDateFilter').addEventListener('click', () => {
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value   = '';
        currentDateFrom = '';
        currentDateTo   = '';
        currentPage     = 1;
        updateDateBadge();
        loadLogs();
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

    // Confirm input unlock
    document.getElementById('confirmInput').addEventListener('input', function () {
        const ok = this.value.trim() === 'DELETE';
        document.getElementById('confirmDeleteBtn').disabled = !ok;
        this.classList.toggle('confirmed', ok);
    });

    // Execute delete
    document.getElementById('confirmDeleteBtn').addEventListener('click', executeDelete);
});

// ── Date Range Active Badge ───────────────────────────────
function updateDateBadge() {
    const badge = document.getElementById('dateActiveBadge');
    if (currentDateFrom || currentDateTo) {
        const from = currentDateFrom || '—';
        const to   = currentDateTo   || '—';
        badge.style.display = 'inline-flex';
        badge.className     = 'date-active-badge';
        badge.innerHTML = `<i class="fas fa-calendar-check"></i> ${fmtDateShort(from)} → ${fmtDateShort(to)}
            <button onclick="clearDateBadge()" title="Remove date filter"><i class="fas fa-times"></i></button>`;
    } else {
        badge.style.display = 'none';
    }
}
function clearDateBadge() {
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value   = '';
    currentDateFrom = '';
    currentDateTo   = '';
    currentPage     = 1;
    updateDateBadge();
    loadLogs();
}
function fmtDateShort(d) {
    if (!d || d === '—') return '—';
    try {
        return new Date(d + 'T00:00:00').toLocaleDateString('en-UG', {day:'2-digit', month:'short', year:'numeric'});
    } catch(_) { return d; }
}

// ── Load Logs ─────────────────────────────────────────────
function loadLogs() {
    showSkeleton();
    const params = new URLSearchParams({
        ajax       : 'logs',
        page       : currentPage,
        search     : currentSearch,
        filter     : currentFilter,
        date_from  : currentDateFrom,
        date_to    : currentDateTo,
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
            notify('Error', 'Failed to load logs. Please try again.', 'error');
        });
}

// ── Preview Delete ────────────────────────────────────────
async function previewDelete() {
    const from = document.getElementById('deleteDateFrom').value;
    const to   = document.getElementById('deleteDateTo').value;

    if (!from || !to) {
        notify('Missing Dates', 'Please select both a "Delete From" and "Delete To" date.', 'warning');
        return;
    }
    if (from > to) {
        notify('Invalid Range', '"Delete From" date must be before or equal to "Delete To" date.', 'warning');
        return;
    }

    const btn = document.getElementById('previewDeleteBtn');
    btn.innerHTML = '<span class="spinner"></span> Checking…';
    btn.disabled  = true;

    try {
        const body = new FormData();
        body.append('csrf_token', CSRF);
        body.append('date_from',  from);
        body.append('date_to',    to);
        body.append('confirmed',  '0');

        const r = await fetch(`${window.location.pathname}?ajax=delete_range`, {method:'POST', body});
        if (!r.ok) throw new Error('Network error');
        const d = await r.json();

        if (!d.success) {
            notify('Cannot Delete', d.message, 'warning');
            return;
        }

        // Populate confirm modal
        document.getElementById('delCount').textContent   = d.count.toLocaleString();
        document.getElementById('delRangeText').textContent =
            `From ${fmtDateShort(from)} to ${fmtDateShort(to)}`;
        document.getElementById('confirmInput').value     = '';
        document.getElementById('confirmInput').classList.remove('confirmed');
        document.getElementById('confirmDeleteBtn').disabled = true;

        // Store for deletion step
        document.getElementById('deleteModal').dataset.from = from;
        document.getElementById('deleteModal').dataset.to   = to;

        document.getElementById('deleteModal').classList.add('active');

    } catch (err) {
        notify('Error', err.message, 'error');
    } finally {
        btn.innerHTML = '<i class="fas fa-trash-alt"></i> Preview &amp; Delete';
        btn.disabled  = false;
    }
}

// ── Execute Delete ────────────────────────────────────────
async function executeDelete() {
    const modal = document.getElementById('deleteModal');
    const from  = modal.dataset.from;
    const to    = modal.dataset.to;

    const btn   = document.getElementById('confirmDeleteBtn');
    btn.innerHTML = '<span class="spinner"></span> Deleting…';
    btn.disabled  = true;

    try {
        const body = new FormData();
        body.append('csrf_token', CSRF);
        body.append('date_from',  from);
        body.append('date_to',    to);
        body.append('confirmed',  '1');

        const r = await fetch(`${window.location.pathname}?ajax=delete_range`, {method:'POST', body});
        if (!r.ok) throw new Error('Network error');
        const d = await r.json();

        if (!d.success) {
            notify('Deletion Failed', d.message, 'error');
            return;
        }

        // Rotate CSRF token
        CSRF = d.new_csrf;

        closeDeleteModal();
        notify('Deleted', `${d.deleted.toLocaleString()} log record${d.deleted !== 1 ? 's' : ''} have been permanently deleted.`, 'success', 6000);

        // Clear delete inputs
        document.getElementById('deleteDateFrom').value = '';
        document.getElementById('deleteDateTo').value   = '';

        // Close delete zone and reload
        deleteZoneOpen = false;
        document.getElementById('deleteRangeBar').style.display = 'none';
        document.getElementById('toggleDeleteZone').classList.remove('active');
        document.getElementById('toggleDeleteZone').innerHTML = '<i class="fas fa-trash-alt"></i> Delete Range';

        currentPage = 1;
        loadLogs();

    } catch (err) {
        notify('Error', err.message, 'error');
        btn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete Records';
        btn.disabled  = false;
    }
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
    document.getElementById('confirmInput').value = '';
    document.getElementById('confirmDeleteBtn').disabled = true;
}

// ── Render Table ──────────────────────────────────────────
function renderTable(data, pagination) {
    const tbody = document.getElementById('tBody');
    tbody.innerHTML = '';

    const count  = document.getElementById('resultCount');
    const offset = (pagination.current_page - 1) * pagination.records_per_page;
    count.textContent = pagination.total_records
        ? `${pagination.total_records.toLocaleString()} record${pagination.total_records !== 1 ? 's' : ''}`
        : '';

    if (!data.length) {
        tbody.innerHTML = `
          <tr><td colspan="9">
            <div class="empty-state">
              <i class="fas fa-clipboard-list"></i>
              <p>No activity logs match your filters.</p>
            </div>
          </td></tr>`;
        return;
    }

    data.forEach((row, i) => {
        const num       = offset + i + 1;
        const loginFmt  = fmtDateTime(row.login_time);
        const logoutFmt = row.logout_time ? fmtDateTime(row.logout_time) : '—';
        const status    = row.logout_time
            ? '<span class="badge badge-offline"><i class="fas fa-circle" style="font-size:.5rem;margin-right:3px"></i>Offline</span>'
            : '<span class="badge badge-online"><i class="fas fa-circle" style="font-size:.5rem;margin-right:3px"></i>Online</span>';

        const lat = parseFloat(row.latitude);
        const lon = parseFloat(row.longitude);
        const location = (!isNaN(lat) && !isNaN(lon))
            ? `<a href="https://maps.google.com/?q=${lat},${lon}" target="_blank" rel="noopener noreferrer"
                  style="color:var(--g700);font-weight:600;font-size:.82rem">
                 <i class="fas fa-map-marker-alt"></i> View Map
               </a>`
            : '<span style="color:#aaa;font-size:.82rem">—</span>';

        const browser      = row.browser || 'Unknown';
        const browserShort = browser.length > 35 ? browser.substring(0, 35) + '…' : browser;

        const actionsBtn = `
          <div class="action-cell">
            <button class="btn-icon bi-view" title="View Actions" onclick="viewActions(this)"
                    data-actions="${esc(row.actions || 'No actions recorded for this session.')}">
              <i class="fas fa-list"></i>
            </button>
            ${(!isNaN(lat) && !isNaN(lon))
                ? `<a href="https://maps.google.com/?q=${lat},${lon}" target="_blank" rel="noopener noreferrer">
                     <button class="btn-icon bi-map" title="Open Map"><i class="fas fa-map-marker-alt"></i></button>
                   </a>` : ''}
          </div>`;

        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td style="color:#8a9a8b;font-size:.8rem">${num}</td>
          <td>
            <div class="user-name">${esc(row.username)}</div>
            <div class="sub-text">${esc(browserShort)}</div>
          </td>
          <td>${esc(loginFmt)}</td>
          <td>${esc(logoutFmt)}</td>
          <td>${status}</td>
          <td style="font-family:monospace;font-size:.82rem">${esc(row.ip_address || '—')}</td>
          <td>${esc(row.device_type || '—')}</td>
          <td>${location}</td>
          <td>${actionsBtn}</td>`;
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

    const prev = makePageBtn('<i class="fas fa-chevron-left"></i>', p.current_page - 1, p.current_page <= 1);
    btns.appendChild(prev);

    const pages = smartPageRange(p.current_page, p.total_pages);
    let last = 0;
    pages.forEach(pg => {
        if (pg - last > 1) {
            const dots = document.createElement('button');
            dots.className   = 'page-btn';
            dots.disabled    = true;
            dots.textContent = '…';
            btns.appendChild(dots);
        }
        btns.appendChild(makePageBtn(pg, pg, false, pg === p.current_page));
        last = pg;
    });

    const next = makePageBtn('<i class="fas fa-chevron-right"></i>', p.current_page + 1, p.current_page >= p.total_pages);
    btns.appendChild(next);
}

function makePageBtn(label, page, disabled, active = false) {
    const btn = document.createElement('button');
    btn.className = 'page-btn' + (active ? ' active' : '');
    btn.innerHTML = label;
    btn.disabled  = disabled;
    if (!disabled && !active) {
        btn.addEventListener('click', () => { currentPage = page; loadLogs(); });
    }
    return btn;
}

function smartPageRange(current, total) {
    if (total <= 7) return Array.from({length: total}, (_, i) => i + 1);
    const pages = new Set([1, total, current]);
    for (let d = 1; d <= 2; d++) {
        if (current - d >= 1) pages.add(current - d);
        if (current + d <= total) pages.add(current + d);
    }
    return [...pages].sort((a, b) => a - b);
}

// ── Skeleton ──────────────────────────────────────────────
function showSkeleton() {
    const tbody = document.getElementById('tBody');
    let html = '';
    for (let i = 0; i < 8; i++) {
        html += `<tr>${Array(9).fill('<td><span class="skeleton-cell"></span></td>').join('')}</tr>`;
    }
    tbody.innerHTML = html;
    document.getElementById('resultCount').textContent = '';
    document.getElementById('pageInfo').textContent    = '';
    document.getElementById('pageBtns').innerHTML      = '';
}

function showError(msg) {
    document.getElementById('tBody').innerHTML = `
      <tr><td colspan="9">
        <div class="empty-state">
          <i class="fas fa-triangle-exclamation" style="color:var(--red)"></i>
          <p>${esc(msg)}</p>
        </div>
      </td></tr>`;
}

// ── Action Log Modal ──────────────────────────────────────
function viewActions(btn) {
    document.getElementById('actionLogContent').textContent = btn.dataset.actions;
    document.getElementById('logModal').classList.add('active');
}
function closeModal() {
    document.getElementById('logModal').classList.remove('active');
}

// ── Export ────────────────────────────────────────────────
async function exportData(format) {
    const params = new URLSearchParams({
        ajax      : 'export',
        search    : currentSearch,
        filter    : currentFilter,
        date_from : currentDateFrom,
        date_to   : currentDateTo,
    });

    notify('Exporting', 'Fetching all matching records…', 'info');

    try {
        const r = await fetch(`${window.location.pathname}?${params}`);
        if (!r.ok) throw new Error('Network error');
        const d = await r.json();
        if (!d.success) throw new Error('Server returned an error');
        if (!d.data.length) { notify('Empty', 'No records to export.', 'warning'); return; }

        if (format === 'excel') exportToExcel(d.data);
        else                    exportToPDF(d.data);

    } catch (err) {
        notify('Export Failed', err.message, 'error');
    }
}

function exportToExcel(data) {
    const rows = [['Username','Login Time','Logout Time','Status','IP Address','Device','Browser','Location','Actions']];
    data.forEach(row => {
        const lat = parseFloat(row.latitude);
        const lon = parseFloat(row.longitude);
        rows.push([
            row.username,
            row.login_time,
            row.logout_time || 'Still online',
            row.logout_time ? 'Offline' : 'Online',
            row.ip_address,
            row.device_type || 'Unknown',
            row.browser     || 'Unknown',
            (!isNaN(lat) && !isNaN(lon)) ? `${lat}, ${lon}` : 'Not available',
            row.actions     || 'No actions recorded',
        ]);
    });
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(rows);
    ws['!cols'] = [{wch:15},{wch:22},{wch:22},{wch:10},{wch:16},{wch:14},{wch:32},{wch:22},{wch:36}];
    XLSX.utils.book_append_sheet(wb, ws, 'User Logs');
    XLSX.writeFile(wb, `user-logs-${datestamp()}.xlsx`);
    notify('Exported', 'Excel file downloaded.', 'success');
}

function exportToPDF(data) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');
    doc.setFontSize(16); doc.setTextColor(46, 125, 50);
    doc.text('User Activity Logs Report', 14, 18);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text('Generated: ' + new Date().toLocaleDateString('en-UG'), 14, 25);
    if (currentDateFrom || currentDateTo) {
        doc.text(`Date Range: ${currentDateFrom || '—'} to ${currentDateTo || '—'}`, 14, 31);
        doc.text('Total Records: ' + data.length, 14, 37);
    } else {
        doc.text('Total Records: ' + data.length, 14, 31);
    }

    doc.autoTable({
        head: [['Username','Login Time','Status','IP Address','Device','Location']],
        body: data.map(row => {
            const lat = parseFloat(row.latitude);
            const lon = parseFloat(row.longitude);
            return [
                row.username,
                fmtDateTime(row.login_time),
                row.logout_time ? 'Offline' : 'Online',
                row.ip_address,
                row.device_type || 'Unknown',
                (!isNaN(lat) && !isNaN(lon)) ? `${lat.toFixed(4)}, ${lon.toFixed(4)}` : 'N/A',
            ];
        }),
        startY: (currentDateFrom || currentDateTo) ? 44 : 38,
        theme : 'grid',
        headStyles    : {fillColor:[67,160,71], fontSize:8},
        bodyStyles    : {fontSize:7.5},
        alternateRowStyles: {fillColor:[240,248,241]},
    });
    doc.save(`user-logs-${datestamp()}.pdf`);
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
        n.style.opacity   = '0';
        n.style.transform = 'translateX(30px)';
        n.style.transition = '.3s';
        setTimeout(() => n.remove(), 300);
    }, dur);
}

// ── Utilities ─────────────────────────────────────────────
function esc(v) {
    return String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function fmtDateTime(d) {
    if (!d) return '—';
    try { return new Date(d).toLocaleDateString('en-UG', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}); }
    catch(_) { return d; }
}
function datestamp() { return new Date().toISOString().split('T')[0]; }
</script>
</body>
</html>
