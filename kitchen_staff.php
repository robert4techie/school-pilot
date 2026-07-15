<?php
/**
 * Kitchen Staff Management
 * Production-grade: JSON API, CSRF protection, prepared statements, full CRUD.
 * All staff functions are self-contained — no external staff_functions.php required.
 */
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction('Kitchen Staff');

/* ── Security Headers ───────────────────────────────────────────────── */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

/* ── Ensure mysqli throws exceptions on errors ──────────────────────── */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ── CSRF ───────────────────────────────────────────────────────── */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* ── JSON API ───────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], (string)($body['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }

    switch ($action) {
        case 'fetch':    echo json_encode(apiFetch($conn, $body));          break;
        case 'get':      echo json_encode(apiGet($conn, $body));            break;
        case 'add':      echo json_encode(apiSave($conn, $body, false));    break;
        case 'update':   echo json_encode(apiSave($conn, $body, true));     break;
        case 'delete':   echo json_encode(apiDelete($conn, $body));         break;
        case 'toggle':   echo json_encode(apiToggleStatus($conn, $body));   break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    }
    exit;
}

/* ════════════════════════════════════════════════════════════════════
   API FUNCTIONS
   ════════════════════════════════════════════════════════════════════ */

function apiFetch(mysqli $conn, array $p): array
{
    $page    = max(1, (int)($p['page'] ?? 1));
    $perPage = min(100, max(10, (int)($p['per_page'] ?? 25)));
    $search  = trim($p['search'] ?? '');
    $status  = trim($p['status'] ?? '');
    $position= trim($p['position'] ?? '');

    $where  = ['1=1'];
    $params = [];
    $types  = '';

    if ($search !== '') {
        $where[]  = '(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?)';
        $like     = "%{$search}%";
        $params   = array_merge($params, [$like, $like, $like, $like]);
        $types   .= 'ssss';
    }
    $validStatuses = ['active', 'inactive', 'on_leave'];
    if ($status !== '' && in_array($status, $validStatuses, true)) {
        $where[]  = 'status = ?';
        $params[] = $status;
        $types   .= 's';
    }
    if ($position !== '') {
        $where[]  = 'position = ?';
        $params[] = $position;
        $types   .= 's';
    }

    $whereSQL = implode(' AND ', $where);

    /* total */
    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM kitchen_staff WHERE {$whereSQL}");
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();

    /* data */
    $offset    = ($page - 1) * $perPage;
    $stmt      = $conn->prepare("SELECT * FROM kitchen_staff WHERE {$whereSQL} ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $allTypes  = $types . 'ii';
    $allParams = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($allTypes, ...$allParams);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /* stats */
    $sRes  = $conn->query("
        SELECT
            COUNT(*)                         AS total,
            SUM(status='active')             AS active,
            SUM(status='inactive')           AS inactive,
            SUM(status='on_leave')           AS on_leave,
            COUNT(DISTINCT position)         AS positions
        FROM kitchen_staff
    ");
    $stats = $sRes ? $sRes->fetch_assoc() : [];

    /* distinct positions for filter */
    $pRes      = $conn->query("SELECT DISTINCT position FROM kitchen_staff WHERE position != '' ORDER BY position");
    $positions = [];
    if ($pRes) while ($r = $pRes->fetch_assoc()) $positions[] = $r['position'];

    return [
        'success'   => true,
        'data'      => $rows,
        'total'     => $total,
        'page'      => $page,
        'per_page'  => $perPage,
        'last_page' => max(1, (int)ceil($total / $perPage)),
        'stats'     => $stats,
        'positions' => $positions,
    ];
}

function apiGet(mysqli $conn, array $p): array
{
    $id = (int)($p['staff_id'] ?? 0);
    if (!$id) return ['success' => false, 'error' => 'Invalid ID.'];

    $stmt = $conn->prepare('SELECT * FROM kitchen_staff WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? ['success' => true, 'data' => $row] : ['success' => false, 'error' => 'Staff member not found.'];
}

function apiSave(mysqli $conn, array $p, bool $isUpdate): array
{
    /* inputs */
    $firstName   = trim($p['first_name']      ?? '');
    $lastName    = trim($p['last_name']       ?? '');
    $email       = trim($p['email']           ?? '');
    $position    = trim($p['position']        ?? '');
    $phone       = trim($p['phone']           ?? '');
    $status      = trim($p['status']          ?? 'active');
    $hireDate    = trim($p['hire_date']        ?? '');
    $roleDesc    = trim($p['role_description'] ?? '');
    $skills      = trim($p['skills']          ?? '');

    /* validate */
    $errors = [];
    if ($firstName === '')                                $errors[] = 'First name is required.';
    if (strlen($firstName) > 100)                        $errors[] = 'First name too long.';
    if ($lastName === '')                                 $errors[] = 'Last name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if ($position === '')                                 $errors[] = 'Position is required.';
    $validStatuses = ['active', 'inactive', 'on_leave'];
    if (!in_array($status, $validStatuses, true))        $errors[] = 'Invalid status.';
    if ($errors) return ['success' => false, 'error' => implode(' ', $errors)];

    if ($isUpdate) {
        $id = (int)($p['staff_id'] ?? 0);
        if (!$id) return ['success' => false, 'error' => 'Invalid staff ID.'];

        /* email uniqueness check (excluding self) */
        $chk = $conn->prepare('SELECT id FROM kitchen_staff WHERE email = ? AND id != ?');
        $chk->bind_param('si', $email, $id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) return ['success' => false, 'error' => 'Email already in use by another staff member.'];
        $chk->close();

        $stmt = $conn->prepare("
            UPDATE kitchen_staff
               SET first_name=?, last_name=?, email=?, position=?, phone=?,
                   status=?, hire_date=?, role_description=?, skills=?
             WHERE id=?
        ");
        $stmt->bind_param('sssssssssi',
            $firstName, $lastName, $email, $position, $phone,
            $status, $hireDate, $roleDesc, $skills, $id
        );
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected < 0) return ['success' => false, 'error' => 'Update failed: ' . $conn->error];
        return ['success' => true, 'message' => "{$firstName} {$lastName} updated successfully."];
    } else {
        /* email uniqueness */
        $chk = $conn->prepare('SELECT id FROM kitchen_staff WHERE email = ?');
        $chk->bind_param('s', $email);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) return ['success' => false, 'error' => 'A staff member with this email already exists.'];
        $chk->close();

        $stmt = $conn->prepare("
            INSERT INTO kitchen_staff
                (first_name, last_name, email, position, phone, status, hire_date, role_description, skills)
            VALUES (?,?,?,?,?,?,?,?,?)
        ");
        $stmt->bind_param('sssssssss',
            $firstName, $lastName, $email, $position, $phone,
            $status, $hireDate, $roleDesc, $skills
        );
        $stmt->execute();
        if ($stmt->insert_id < 1) return ['success' => false, 'error' => 'Insert failed: ' . $conn->error];
        $stmt->close();
        return ['success' => true, 'message' => "{$firstName} {$lastName} added to kitchen staff."];
    }
}

function apiDelete(mysqli $conn, array $p): array
{
    $id = (int)($p['staff_id'] ?? 0);
    if (!$id) return ['success' => false, 'error' => 'Invalid ID.'];

    $stmt = $conn->prepare('DELETE FROM kitchen_staff WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    return $affected > 0
        ? ['success' => true,  'message' => 'Staff member removed.']
        : ['success' => false, 'error'   => 'Staff member not found.'];
}

function apiToggleStatus(mysqli $conn, array $p): array
{
    $id     = (int)($p['staff_id'] ?? 0);
    $status = trim($p['status']   ?? '');
    $valid  = ['active', 'inactive', 'on_leave'];
    if (!$id || !in_array($status, $valid, true)) return ['success' => false, 'error' => 'Invalid request.'];

    $stmt = $conn->prepare('UPDATE kitchen_staff SET status=? WHERE id=?');
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    $stmt->close();
    return ['success' => true, 'message' => 'Status updated to ' . ucfirst(str_replace('_',' ',$status)) . '.'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Kitchen Staff &mdash; School Pilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
<style>
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--orange:#e65100;--blue:#1565c0;--gray:#546e7a;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);
  --shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --transition:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}
a{color:inherit;text-decoration:none}
.page{max-width:100%;margin:0 auto;padding:24px 20px 48px}

/* ── Page Header ─────────────────────────────────────────────────── */
.page-header{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--radius-lg);padding:28px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;margin-bottom:24px;margin-top:40px;box-shadow:var(--shadow-lg)}
.page-header h1{color:#fff;font-size:1.55rem;font-weight:700;letter-spacing:.3px}
.page-header p{color:rgba(255,255,255,.78);font-size:.9rem;margin-top:3px}
.stats-row{display:flex;gap:12px;flex-wrap:wrap}
.stat-pill{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:40px;padding:8px 18px;text-align:center;min-width:80px;cursor:pointer;transition:background var(--transition)}
.stat-pill:hover,.stat-pill.active{background:rgba(255,255,255,.26)}
.stat-pill .n{font-size:1.35rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.72rem;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.5px}

/* ── Card / Toolbar ──────────────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden}
.toolbar{padding:18px 24px;border-bottom:1px solid #e8ede9;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.toolbar-left{display:flex;flex-wrap:wrap;gap:10px;align-items:center;flex:1 1 auto}
.toolbar-right{display:flex;gap:10px;align-items:center;flex-shrink:0}
.search-wrap{position:relative;min-width:220px}
.search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.85rem}
.search-wrap input{width:100%;padding:9px 12px 9px 34px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;transition:border-color var(--transition)}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.filter-select{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;background:#fff;cursor:pointer;min-width:130px}
.filter-select:focus{outline:none;border-color:var(--g600)}
.result-count{font-size:.8rem;color:#6b7c6d;white-space:nowrap}

/* ── Buttons ─────────────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all var(--transition);white-space:nowrap}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active,.btn:disabled{transform:none;opacity:.6}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{background:#f5f5f5;transform:none}
.btn-pdf{background:#c62828;color:#fff}.btn-pdf:hover{background:var(--red)}
.btn-excel{background:var(--g800);color:#fff}.btn-excel:hover{background:var(--g900)}
.btn-danger{background:var(--red);color:#fff}.btn-danger:hover{background:#b71c1c}
.btn-sm{padding:6px 12px;font-size:.78rem}

/* ── Table ───────────────────────────────────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:13px 14px;text-align:left;font-size:.8rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--transition)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:12px 14px;font-size:.875rem;vertical-align:middle}

/* ── Avatar ──────────────────────────────────────────────────────── */
.staff-avatar{width:38px;height:38px;border-radius:50%;background:var(--g100);border:2px solid var(--g400);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.9rem;color:var(--g700);flex-shrink:0;text-transform:uppercase}
.staff-name-cell{display:flex;align-items:center;gap:12px}

/* ── Badges ──────────────────────────────────────────────────────── */
.badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.b-active{background:#e8f5e9;color:#2e7d32}
.b-inactive{background:#ffebee;color:#c62828}
.b-on_leave{background:#fff3e0;color:#e65100}

/* ── Icon Buttons ────────────────────────────────────────────────── */
.action-cell{display:flex;gap:5px;align-items:center}
.btn-icon{width:30px;height:30px;border:none;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;transition:all var(--transition);flex-shrink:0}
.btn-icon:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.18)}
.bi-view{background:#e3f2fd;color:#1565c0}.bi-view:hover{background:#1565c0;color:#fff}
.bi-edit{background:#fff3e0;color:#e65100}.bi-edit:hover{background:#e65100;color:#fff}
.bi-toggle{background:#e8f5e9;color:var(--g700)}.bi-toggle:hover{background:var(--g700);color:#fff}
.bi-delete{background:#ffebee;color:var(--red)}.bi-delete:hover{background:var(--red);color:#fff}

/* ── Skeleton / Empty ────────────────────────────────────────────── */
.skeleton-cell{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;height:14px;display:inline-block;width:80%}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
.empty-state{text-align:center;padding:60px 20px;color:#8a9a8b}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;opacity:.45}
.empty-state p{font-size:.95rem}

/* ── Pagination ──────────────────────────────────────────────────── */
.pagination{padding:16px 24px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e8ede9;flex-wrap:wrap;gap:10px}
.page-info{font-size:.82rem;color:#6b7c6d}
.page-btns{display:flex;gap:4px}
.page-btn{width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:6px;background:#fff;cursor:pointer;font-size:.82rem;font-weight:600;color:#444;display:flex;align-items:center;justify-content:center;transition:all var(--transition)}
.page-btn:hover:not(:disabled){border-color:var(--g600);background:var(--g100);color:var(--g800)}
.page-btn.active{background:var(--g700);border-color:var(--g700);color:#fff}
.page-btn:disabled{opacity:.38;cursor:default}

/* ── Modal ───────────────────────────────────────────────────────── */
.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px)}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:720px;box-shadow:var(--shadow-lg);animation:slideDown .25s ease;margin:auto}
@keyframes slideDown{from{transform:translateY(-24px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%);padding:20px 24px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head h2{color:#fff;font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background var(--transition)}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:28px}

/* ── Form ────────────────────────────────────────────────────────── */
.form-section{margin-bottom:22px}
.form-section-title{font-size:.8rem;font-weight:700;color:var(--g700);text-transform:uppercase;letter-spacing:.6px;margin-bottom:14px;padding-bottom:7px;border-bottom:2px solid var(--g100);display:flex;align-items:center;gap:8px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-grid .full{grid-column:1/-1}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group label{font-size:.8rem;font-weight:600;color:#3a4a3b}
.form-group label .req{color:var(--red)}
.form-control{padding:9px 13px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;width:100%;transition:border-color var(--transition),box-shadow var(--transition);background:#fff;font-family:inherit}
.form-control:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.1)}
textarea.form-control{resize:vertical;min-height:72px}
.form-actions{display:flex;gap:12px;justify-content:flex-end;padding-top:20px;border-top:1px solid #eef2ee;margin-top:20px}

/* ── View Modal ──────────────────────────────────────────────────── */
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid #e8ede9;border-radius:var(--radius)}
.detail-row{display:contents}
.detail-row:not(:last-child) .d-label,.detail-row:not(:last-child) .d-val{border-bottom:1px solid #e8ede9}
.d-label{padding:10px 14px;font-size:.75rem;font-weight:700;color:var(--g700);text-transform:uppercase;letter-spacing:.4px;background:#f5fbf5;border-right:1px solid #e8ede9}
.d-val{padding:10px 14px;font-size:.875rem;color:#333}
.view-profile{display:flex;align-items:center;gap:20px;margin-bottom:22px;padding-bottom:18px;border-bottom:2px solid var(--g100)}
.view-avatar-lg{width:70px;height:70px;border-radius:50%;background:var(--g100);border:3px solid var(--g400);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.5rem;color:var(--g700);text-transform:uppercase;flex-shrink:0}
.view-meta h3{font-size:1.2rem;font-weight:700;color:var(--g800)}
.view-meta p{font-size:.85rem;color:#6b7c6d;margin-top:3px}

/* ── Dialog ──────────────────────────────────────────────────────── */
.dialog{display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);align-items:center;justify-content:center}
.dialog.active{display:flex}
.dialog-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:420px;box-shadow:var(--shadow-lg);animation:slideDown .22s ease;overflow:hidden}
.dialog-head{padding:18px 22px;display:flex;align-items:center;gap:12px;color:#fff}
.dialog-head.danger{background:linear-gradient(135deg,#c62828,#ef5350)}
.dialog-head.warning{background:linear-gradient(135deg,#e65100,#ff7043)}
.dialog-head.info{background:linear-gradient(135deg,var(--g800),var(--g600))}
.dialog-head i{font-size:1.2rem}.dialog-head h3{font-size:1rem;font-weight:700}
.dialog-body{padding:22px;text-align:center}
.dialog-body p{font-size:.9rem;color:#555;line-height:1.55;margin-bottom:20px}
.dialog-actions{display:flex;gap:10px;justify-content:center}

/* ── Notifications ───────────────────────────────────────────────── */
#notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.notif{background:#fff;border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow-lg);display:flex;align-items:flex-start;gap:12px;border-left:4px solid var(--g600);animation:notifIn .3s ease}
.notif.error{border-left-color:var(--red)}.notif.warning{border-left-color:#e65100}.notif.info{border-left-color:var(--blue)}
@keyframes notifIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.notif-icon{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.notif.success .notif-icon{color:var(--g700)}.notif.error .notif-icon{color:var(--red)}.notif.warning .notif-icon{color:#e65100}.notif.info .notif-icon{color:var(--blue)}
.notif-body{flex:1}.notif-title{font-weight:700;font-size:.85rem;margin-bottom:2px}.notif-msg{font-size:.8rem;color:#666}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:1rem;padding:0;line-height:1;flex-shrink:0}

@media(max-width:700px){
  .form-grid{grid-template-columns:1fr}.toolbar{flex-direction:column;align-items:stretch}
  .page-header{flex-direction:column}.detail-grid{grid-template-columns:1fr}.d-label{border-right:none}
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
      <h1><i class="fas fa-users-gear" style="margin-right:10px;opacity:.85"></i>Kitchen Staff</h1>
      <p>Manage kitchen team members, positions and availability</p>
    </div>
    <div class="stats-row">
      <div class="stat-pill" onclick="setFilter('status','')"><span class="n" id="sTotal">—</span><span class="l">Total</span></div>
      <div class="stat-pill" onclick="setFilter('status','active')"><span class="n" id="sActive">—</span><span class="l">Active</span></div>
      <div class="stat-pill" onclick="setFilter('status','on_leave')"><span class="n" id="sLeave">—</span><span class="l">On Leave</span></div>
      <div class="stat-pill" onclick="setFilter('status','inactive')"><span class="n" id="sInactive">—</span><span class="l">Inactive</span></div>
      <div class="stat-pill" style="cursor:default"><span class="n" id="sPositions">—</span><span class="l">Positions</span></div>
    </div>
  </div>

  <div class="card">
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search name, email, phone…" autocomplete="off">
        </div>
        <select id="statusFilter" class="filter-select">
          <option value="">All Statuses</option>
          <option value="active">Active</option>
          <option value="on_leave">On Leave</option>
          <option value="inactive">Inactive</option>
        </select>
        <select id="positionFilter" class="filter-select">
          <option value="">All Positions</option>
        </select>
        <button class="btn btn-outline" id="clearFiltersBtn"><i class="fas fa-times-circle"></i> Clear</button>
        <span class="result-count" id="resultCount"></span>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-pdf"   onclick="exportToPDF()"><i class="fas fa-file-pdf"></i> PDF</button>
        <button class="btn btn-excel" onclick="exportToExcel()"><i class="fas fa-file-excel"></i> Excel</button>
        <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-user-plus"></i> Add Staff</button>
      </div>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:40px">#</th>
            <th style="width:50px"></th>
            <th>Name</th>
            <th>Position</th>
            <th>Contact</th>
            <th>Hire Date</th>
            <th>Status</th>
            <th style="width:130px">Actions</th>
          </tr>
        </thead>
        <tbody id="tBody"></tbody>
      </table>
    </div>

    <div class="pagination">
      <span class="page-info" id="pageInfo"></span>
      <div class="page-btns" id="pageBtns"></div>
    </div>
  </div>
</div>

<!-- VIEW MODAL -->
<div id="viewModal" class="modal" onclick="modalOutsideClick(event,'viewModal')">
  <div class="modal-box" style="max-width:620px">
    <div class="modal-head">
      <h2><i class="fas fa-id-card"></i> Staff Profile</h2>
      <button class="modal-close" onclick="closeModal('viewModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="viewBody"></div>
  </div>
</div>

<!-- ADD / EDIT MODAL -->
<div id="editModal" class="modal" onclick="modalOutsideClick(event,'editModal')">
  <div class="modal-box">
    <div class="modal-head">
      <h2 id="editModalTitle"><i class="fas fa-user-plus"></i> Add Staff Member</h2>
      <button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="f_staff_id">
      <div class="form-section">
        <div class="form-section-title"><i class="fas fa-user"></i> Personal Information</div>
        <div class="form-grid">
          <div class="form-group">
            <label>First Name <span class="req">*</span></label>
            <input type="text" id="f_first_name" class="form-control" maxlength="100" required>
          </div>
          <div class="form-group">
            <label>Last Name <span class="req">*</span></label>
            <input type="text" id="f_last_name" class="form-control" maxlength="100" required>
          </div>
          <div class="form-group">
            <label>Email <span class="req">*</span></label>
            <input type="email" id="f_email" class="form-control" maxlength="255" required>
          </div>
          <div class="form-group">
            <label>Phone</label>
            <input type="tel" id="f_phone" class="form-control" maxlength="20" placeholder="+256 700 000 000">
          </div>
        </div>
      </div>
      <div class="form-section">
        <div class="form-section-title"><i class="fas fa-briefcase"></i> Employment Details</div>
        <div class="form-grid">
          <div class="form-group">
            <label>Position <span class="req">*</span></label>
            <input type="text" id="f_position" class="form-control" maxlength="100" placeholder="e.g. Head Chef, Kitchen Assistant" required>
          </div>
          <div class="form-group">
            <label>Status <span class="req">*</span></label>
            <select id="f_status" class="form-control" required>
              <option value="active">Active</option>
              <option value="on_leave">On Leave</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
          <div class="form-group">
            <label>Hire Date</label>
            <input type="date" id="f_hire_date" class="form-control">
          </div>
          <div class="form-group">
            <label>Role Description</label>
            <input type="text" id="f_role_description" class="form-control" maxlength="255" placeholder="Brief role summary">
          </div>
          <div class="form-group full">
            <label>Skills &amp; Certifications</label>
            <textarea id="f_skills" class="form-control" rows="3" placeholder="List skills, certs, specialisations…" maxlength="1000"></textarea>
          </div>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn-outline" onclick="closeModal('editModal')"><i class="fas fa-times"></i> Cancel</button>
        <button class="btn btn-primary" id="saveBtn" onclick="saveStaff()"><i class="fas fa-save"></i> Save</button>
      </div>
    </div>
  </div>
</div>

<!-- CONFIRM DIALOG -->
<div id="confirmDlg" class="dialog">
  <div class="dialog-box">
    <div class="dialog-head danger" id="dlgHead"><i id="dlgIcon" class="fas fa-trash"></i><h3 id="dlgTitle">Confirm</h3></div>
    <div class="dialog-body">
      <p id="dlgMsg"></p>
      <div class="dialog-actions">
        <button class="btn btn-outline" onclick="closeDlg()"><i class="fas fa-times"></i> Cancel</button>
        <button class="btn btn-danger"  id="dlgConfirmBtn" onclick="runDlgCallback()">Confirm</button>
      </div>
    </div>
  </div>
</div>

<!-- STATUS DIALOG -->
<div id="statusDlg" class="dialog">
  <div class="dialog-box">
    <div class="dialog-head info"><i class="fas fa-exchange-alt"></i><h3>Change Status</h3></div>
    <div class="dialog-body">
      <p id="statusDlgMsg" style="margin-bottom:16px"></p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center">
        <button class="btn" style="background:#e8f5e9;color:var(--g800)" onclick="doToggle('active')"><i class="fas fa-check-circle"></i> Active</button>
        <button class="btn" style="background:#fff3e0;color:#e65100"     onclick="doToggle('on_leave')"><i class="fas fa-clock"></i> On Leave</button>
        <button class="btn" style="background:#ffebee;color:var(--red)"  onclick="doToggle('inactive')"><i class="fas fa-times-circle"></i> Inactive</button>
      </div>
      <div style="margin-top:16px"><button class="btn btn-outline btn-sm" onclick="closeStatusDlg()">Cancel</button></div>
    </div>
  </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
let allData = [], currentPage = 1;
const PER_PAGE = 25;
let filters = { search:'', status:'', position:'' };
let dlgCb = null, statusDlgId = null;

document.addEventListener('DOMContentLoaded', () => {
    loadData();
    let t;
    document.getElementById('searchInput').addEventListener('input', function() {
        clearTimeout(t);
        t = setTimeout(() => { filters.search = this.value.trim(); currentPage=1; loadData(); }, 320);
    });
    document.getElementById('statusFilter').addEventListener('change', function() {
        filters.status = this.value; currentPage=1; loadData();
    });
    document.getElementById('positionFilter').addEventListener('change', function() {
        filters.position = this.value; currentPage=1; loadData();
    });
    document.getElementById('clearFiltersBtn').addEventListener('click', clearFilters);
});

function loadData(page) {
    if (page !== undefined) currentPage = page;
    renderSkeleton();
    api({ action:'fetch', page: currentPage, per_page: PER_PAGE, ...filters })
    .then(d => {
        if (!d.success) throw new Error(d.error);
        allData = d.data;
        renderTable();
        renderPagination(d.page, d.last_page, d.total);
        renderStats(d.stats);
        document.getElementById('resultCount').textContent = `${d.total.toLocaleString()} member${d.total!==1?'s':''}`;
        /* populate position filter */
        const sel = document.getElementById('positionFilter');
        const cur = sel.value;
        sel.innerHTML = '<option value="">All Positions</option>' + (d.positions||[]).map(p => `<option value="${esc(p)}" ${p===cur?'selected':''}>${esc(p)}</option>`).join('');
    })
    .catch(err => notify('Error', err.message, 'error'));
}

function renderSkeleton() {
    let h='';
    for(let i=0;i<8;i++) h+=`<tr>${Array(8).fill('<td><span class="skeleton-cell"></span></td>').join('')}</tr>`;
    document.getElementById('tBody').innerHTML = h;
}

function renderTable() {
    const tb = document.getElementById('tBody');
    if (!allData.length) {
        tb.innerHTML = `<tr><td colspan="8"><div class="empty-state"><i class="fas fa-users"></i><p>No staff members found.</p></div></td></tr>`;
        return;
    }
    const off = (currentPage-1)*PER_PAGE;
    tb.innerHTML = allData.map((s,i) => {
        const initials = (s.first_name||'?').charAt(0) + (s.last_name||'?').charAt(0);
        return `<tr>
          <td style="color:#8a9a8b;font-size:.78rem">${off+i+1}</td>
          <td><div class="staff-avatar">${esc(initials)}</div></td>
          <td>
            <div class="staff-name-cell">
              <div>
                <strong style="color:var(--g800)">${esc(s.first_name)} ${esc(s.last_name)}</strong>
                ${s.role_description ? `<br><span style="font-size:.75rem;color:#8a9a8b">${esc(s.role_description)}</span>` : ''}
              </div>
            </div>
          </td>
          <td>${esc(s.position)||'—'}</td>
          <td>
            ${s.email ? `<div style="font-size:.82rem">${esc(s.email)}</div>` : ''}
            ${s.phone ? `<div style="font-size:.78rem;color:#8a9a8b">${esc(s.phone)}</div>` : ''}
          </td>
          <td>${fmtDate(s.hire_date)}</td>
          <td><span class="badge b-${s.status}">${ucf(s.status.replace('_',' '))}</span></td>
          <td>
            <div class="action-cell">
              <button class="btn-icon bi-view"   title="View"   onclick="viewStaff(${s.id})"><i class="fas fa-eye"></i></button>
              <button class="btn-icon bi-edit"   title="Edit"   onclick="editStaff(${s.id})"><i class="fas fa-pen"></i></button>
              <button class="btn-icon bi-toggle" title="Status" onclick="promptToggle(${s.id},'${esc(s.first_name+' '+s.last_name)}','${s.status}')"><i class="fas fa-exchange-alt"></i></button>
              <button class="btn-icon bi-delete" title="Delete" onclick="promptDelete(${s.id},'${esc(s.first_name+' '+s.last_name)}')"><i class="fas fa-trash"></i></button>
            </div>
          </td>
        </tr>`;
    }).join('');
}

function renderStats(s) {
    if (!s) return;
    document.getElementById('sTotal').textContent     = s.total     || 0;
    document.getElementById('sActive').textContent    = s.active    || 0;
    document.getElementById('sLeave').textContent     = s.on_leave  || 0;
    document.getElementById('sInactive').textContent  = s.inactive  || 0;
    document.getElementById('sPositions').textContent = s.positions || 0;
}

function setFilter(key, val) {
    filters[key] = val;
    if (key === 'status') document.getElementById('statusFilter').value = val;
    currentPage = 1; loadData();
}

function renderPagination(page, lastPage, total) {
    const from = Math.min((page-1)*PER_PAGE+1, total), to = Math.min(page*PER_PAGE, total);
    document.getElementById('pageInfo').textContent = total ? `Showing ${from}–${to} of ${total.toLocaleString()}` : 'No results';
    const pages = buildPageRange(page, lastPage);
    document.getElementById('pageBtns').innerHTML = [
        `<button class="page-btn" ${page<=1?'disabled':''} onclick="loadData(${page-1})"><i class="fas fa-chevron-left"></i></button>`,
        ...pages.map(p => p==='…' ? `<button class="page-btn" disabled>…</button>` : `<button class="page-btn ${p===page?'active':''}" onclick="loadData(${p})">${p}</button>`),
        `<button class="page-btn" ${page>=lastPage?'disabled':''} onclick="loadData(${page+1})"><i class="fas fa-chevron-right"></i></button>`,
    ].join('');
}

function buildPageRange(cur,last){
    if(last<=7)return Array.from({length:last},(_,i)=>i+1);
    const r=new Set([1,last,cur,cur-1,cur+1].filter(x=>x>=1&&x<=last));
    const s=[...r].sort((a,b)=>a-b),o=[];
    s.forEach((p,i)=>{if(i>0&&p-s[i-1]>1)o.push('…');o.push(p);});
    return o;
}

/* VIEW */
function viewStaff(id) {
    openModal('viewModal');
    document.getElementById('viewBody').innerHTML = '<div style="text-align:center;padding:40px"><i class="fas fa-spinner fa-spin" style="font-size:2rem;color:var(--g600)"></i></div>';
    api({ action:'get', staff_id: id }).then(d => {
        if (!d.success) throw new Error(d.error);
        const s = d.data;
        const initials = (s.first_name||'?').charAt(0) + (s.last_name||'?').charAt(0);
        document.getElementById('viewBody').innerHTML = `
        <div class="view-profile">
          <div class="view-avatar-lg">${esc(initials)}</div>
          <div class="view-meta">
            <h3>${esc(s.first_name)} ${esc(s.last_name)}</h3>
            <p>${esc(s.position)||'No position set'}</p>
            <span class="badge b-${s.status}" style="margin-top:6px">${ucf(s.status.replace('_',' '))}</span>
          </div>
        </div>
        <div class="detail-grid">
          <div class="detail-row"><div class="d-label">Email</div><div class="d-val">${esc(s.email)||'—'}</div></div>
          <div class="detail-row"><div class="d-label">Phone</div><div class="d-val">${esc(s.phone)||'—'}</div></div>
          <div class="detail-row"><div class="d-label">Position</div><div class="d-val">${esc(s.position)||'—'}</div></div>
          <div class="detail-row"><div class="d-label">Hire Date</div><div class="d-val">${fmtDate(s.hire_date)}</div></div>
          <div class="detail-row"><div class="d-label">Role</div><div class="d-val">${esc(s.role_description)||'—'}</div></div>
          <div class="detail-row"><div class="d-label">Added On</div><div class="d-val">${fmtDate(s.created_at)}</div></div>
          ${s.skills ? `<div class="detail-row"><div class="d-label">Skills</div><div class="d-val" style="white-space:pre-line">${esc(s.skills)}</div></div>` : ''}
        </div>
        <div class="form-actions" style="margin-top:20px;padding-top:16px;border-top:1px solid #eef2ee">
          <button class="btn btn-outline" onclick="closeModal('viewModal')">Close</button>
          <button class="btn btn-primary" onclick="closeModal('viewModal');editStaff(${s.id})"><i class="fas fa-pen"></i> Edit</button>
        </div>`;
    }).catch(err => notify('Error', err.message, 'error'));
}

/* ADD / EDIT */
function openAddModal() {
    ['f_staff_id','f_first_name','f_last_name','f_email','f_phone','f_position','f_hire_date','f_role_description','f_skills'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('f_status').value = 'active';
    document.getElementById('editModalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Add Staff Member';
    openModal('editModal');
}

function editStaff(id) {
    api({ action:'get', staff_id: id }).then(d => {
        if (!d.success) throw new Error(d.error);
        const s = d.data;
        document.getElementById('f_staff_id').value         = s.id;
        document.getElementById('f_first_name').value       = s.first_name;
        document.getElementById('f_last_name').value        = s.last_name;
        document.getElementById('f_email').value            = s.email;
        document.getElementById('f_phone').value            = s.phone||'';
        document.getElementById('f_position').value         = s.position;
        document.getElementById('f_status').value           = s.status;
        document.getElementById('f_hire_date').value        = s.hire_date||'';
        document.getElementById('f_role_description').value = s.role_description||'';
        document.getElementById('f_skills').value           = s.skills||'';
        document.getElementById('editModalTitle').innerHTML = '<i class="fas fa-pen"></i> Edit Staff Member';
        openModal('editModal');
    }).catch(err => notify('Error', err.message, 'error'));
}

function saveStaff() {
    const id = document.getElementById('f_staff_id').value;
    const payload = {
        action: id ? 'update' : 'add',
        staff_id: id,
        first_name:       document.getElementById('f_first_name').value.trim(),
        last_name:        document.getElementById('f_last_name').value.trim(),
        email:            document.getElementById('f_email').value.trim(),
        phone:            document.getElementById('f_phone').value.trim(),
        position:         document.getElementById('f_position').value.trim(),
        status:           document.getElementById('f_status').value,
        hire_date:        document.getElementById('f_hire_date').value,
        role_description: document.getElementById('f_role_description').value.trim(),
        skills:           document.getElementById('f_skills').value.trim(),
    };
    if (!payload.first_name) { notify('Validation', 'First name is required.', 'warning'); return; }
    if (!payload.last_name)  { notify('Validation', 'Last name is required.', 'warning'); return; }
    if (!payload.email)      { notify('Validation', 'Email is required.', 'warning'); return; }
    if (!payload.position)   { notify('Validation', 'Position is required.', 'warning'); return; }

    const btn = document.getElementById('saveBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    api(payload).then(d => {
        if (!d.success) throw new Error(d.error);
        notify('Saved', d.message, 'success');
        closeModal('editModal');
        loadData();
    }).catch(err => notify('Error', err.message, 'error'))
    .finally(() => { btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Save'; });
}

/* DELETE */
function promptDelete(id, name) {
    dlgCb = () => {
        api({ action:'delete', staff_id: id }).then(d => {
            if (!d.success) throw new Error(d.error);
            notify('Deleted', d.message, 'success'); loadData();
        }).catch(err => notify('Error', err.message, 'error'));
    };
    document.getElementById('dlgMsg').innerHTML = `Permanently remove <strong>${esc(name)}</strong> from kitchen staff?`;
    document.getElementById('confirmDlg').classList.add('active');
}

/* STATUS TOGGLE */
function promptToggle(id, name, current) {
    statusDlgId = id;
    document.getElementById('statusDlgMsg').innerHTML = `Change status for <strong>${esc(name)}</strong> (currently: <strong>${ucf(current.replace('_',' '))}</strong>)`;
    document.getElementById('statusDlg').classList.add('active');
}
function doToggle(status) {
    closeStatusDlg();
    api({ action:'toggle', staff_id: statusDlgId, status }).then(d => {
        if (!d.success) throw new Error(d.error);
        notify('Updated', d.message, 'success'); loadData();
    }).catch(err => notify('Error', err.message, 'error'));
}
function closeStatusDlg() { document.getElementById('statusDlg').classList.remove('active'); statusDlgId=null; }

/* EXPORT */
function exportToPDF() {
    if (!allData.length) { notify('Empty','No data to export.','warning'); return; }
    const {jsPDF} = window.jspdf;
    const doc = new jsPDF('landscape');
    doc.setFontSize(16); doc.setTextColor(46,125,50);
    doc.text('Kitchen Staff Report', 14, 18);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text('Generated: ' + new Date().toLocaleString('en-UG'), 14, 25);
    doc.autoTable({
        head:[['#','Name','Position','Email','Phone','Hire Date','Status']],
        body: allData.map((s,i)=>[i+1,`${s.first_name} ${s.last_name}`,s.position||'',s.email||'',s.phone||'',fmtDate(s.hire_date),ucf(s.status.replace('_',' '))]),
        startY:30, theme:'grid',
        headStyles:{fillColor:[67,160,71],fontSize:7.5},
        bodyStyles:{fontSize:7},
    });
    doc.save('kitchen-staff-' + datestamp() + '.pdf');
    notify('Exported','PDF downloaded.','success');
}
function exportToExcel() {
    if (!allData.length) { notify('Empty','No data.','warning'); return; }
    const wb=XLSX.utils.book_new();
    const ws=XLSX.utils.aoa_to_sheet([
        ['ID','First Name','Last Name','Email','Phone','Position','Status','Hire Date','Role','Skills'],
        ...allData.map(s=>[s.id,s.first_name,s.last_name,s.email||'',s.phone||'',s.position||'',ucf(s.status),s.hire_date||'',s.role_description||'',s.skills||''])
    ]);
    ws['!cols']=[{wch:6},{wch:14},{wch:14},{wch:26},{wch:16},{wch:20},{wch:10},{wch:12},{wch:24},{wch:30}];
    XLSX.utils.book_append_sheet(wb,ws,'Kitchen Staff');
    XLSX.writeFile(wb,'kitchen-staff-'+datestamp()+'.xlsx');
    notify('Exported','Excel downloaded.','success');
}

/* HELPERS */
function clearFilters(){filters={search:'',status:'',position:''};document.getElementById('searchInput').value='';document.getElementById('statusFilter').value='';document.getElementById('positionFilter').value='';currentPage=1;loadData();}
function api(payload){return fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({...payload,csrf_token:CSRF})}).then(r=>r.json());}
function esc(v){return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function ucf(v){return v?v.charAt(0).toUpperCase()+v.slice(1):'';}
function fmtDate(d){if(!d)return'—';try{return new Date(d).toLocaleDateString('en-UG',{day:'2-digit',month:'short',year:'numeric'});}catch(_){return d;}}
function datestamp(){return new Date().toISOString().split('T')[0];}
function closeDlg(){document.getElementById('confirmDlg').classList.remove('active');dlgCb=null;}
function runDlgCallback(){if(dlgCb)dlgCb();closeDlg();}
function openModal(id){document.getElementById(id).classList.add('active');}
function closeModal(id){document.getElementById(id).classList.remove('active');}
function closeAllModals(){document.querySelectorAll('.modal.active,.dialog.active').forEach(m=>m.classList.remove('active'));}
function modalOutsideClick(e,id){if(e.target.id===id)closeModal(id);}
function notify(title,msg,type='success',dur=4500){
    const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
    const n=document.createElement('div');n.className=`notif ${type}`;
    n.innerHTML=`<i class="fas ${icons[type]||icons.info} notif-icon"></i><div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div><button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('notif-stack').prepend(n);
    setTimeout(()=>{n.style.opacity='0';n.style.transform='translateX(30px)';n.style.transition='.3s';setTimeout(()=>n.remove(),300);},dur);
}
document.addEventListener('keydown',e=>{
    if(e.key==='Escape')closeAllModals();
    if((e.ctrlKey||e.metaKey)&&e.key==='k'){e.preventDefault();document.getElementById('searchInput').focus();}
});
</script>
</body>
</html>
