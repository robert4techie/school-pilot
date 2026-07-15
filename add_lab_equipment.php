<?php
/**
 * add_lab_equipment.php — Lab Equipment Management
 * SchoolPilot · Production build
 *
 * Security:  CSRF on all mutations · prepared statements only ·
 *            whitelist validation · errors logged server-side ·
 *            self-contained (no external process_equipment.php).
 *
 * DB table:  lab_equipment(id, name, model_serial, manufacturer,
 *            purchase_date, location, status, warranty_info,
 *            maintenance_schedule, created_at, updated_at)
 */

require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';

/* ── CSRF ──────────────────────────────────────────────── */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* ── Whitelists ─────────────────────────────────────────── */
const EQ_STATUSES = ['active', 'inactive', 'needs_repair', 'decommissioned'];
const EQ_LOCS     = [
    'room_101', 'room_102', 'room_103', 'prep_room',
    'storage', 'fume_hood_area', 'microscopy_room', 'other'
];

/* ── Helpers ────────────────────────────────────────────── */
function jsonOk(array $extra = []): never
{
    echo json_encode(array_merge(['success' => true], $extra));
    exit;
}
function jsonErr(string $msg): never
{
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function csrfGuard(): void
{
    global $csrf;
    if (empty($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) {
        jsonErr('Security token mismatch. Please refresh and try again.');
    }
}
function validateDate(string $d): bool
{
    if ($d === '') return true; // optional date is OK blank
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $day] = explode('-', $d);
    return checkdate((int)$m, (int)$day, (int)$y);
}
function collectEquipFields(): array
{
    $err = fn($m) => ['error' => $m];

    $name     = trim($_POST['name']                ?? '');
    $model    = trim($_POST['model_serial']        ?? '');
    $mfg      = trim($_POST['manufacturer']        ?? '');
    $purchase = trim($_POST['purchase_date']       ?? '');
    $location = trim($_POST['location']            ?? '');
    $status   = trim($_POST['status']              ?? '');
    $warranty = trim($_POST['warranty_info']       ?? '');
    $schedule = trim($_POST['maintenance_schedule']?? '');

    if ($name === '' || mb_strlen($name) > 200)    return $err('Equipment name is required (max 200 chars).');
    if ($model === '' || mb_strlen($model) > 100)  return $err('Model/serial number is required (max 100 chars).');
    if ($mfg === '' || mb_strlen($mfg) > 150)      return $err('Manufacturer is required (max 150 chars).');
    if (!validateDate($purchase))                  return $err('Invalid purchase date.');
    if (!in_array($location, EQ_LOCS, true))       return $err('Invalid location selected.');
    if (!in_array($status, EQ_STATUSES, true))     return $err('Invalid status selected.');
    if (mb_strlen($warranty) > 1000)               return $err('Warranty info too long.');
    if (mb_strlen($schedule) > 500)                return $err('Maintenance schedule too long.');

    return compact('name','model','mfg','purchase','location','status','warranty','schedule');
}

/* ── AJAX dispatcher ────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    switch ($_POST['action']) {
        case 'add':       csrfGuard(); handleAdd();    break;
        case 'update':    csrfGuard(); handleUpdate(); break;
        case 'delete':    csrfGuard(); handleDelete(); break;
        case 'list':                   handleList();   break;
        case 'get_stats':              handleGetStats(); break;
        default: jsonErr('Unknown action.');
    }
}

/* ── AJAX: list (paginated, filtered) ───────────────────── */
function handleList(): void
{
    global $conn;
    $page    = max(1, (int)($_POST['page'] ?? 1));
    $perPage = 20;
    $offset  = ($page - 1) * $perPage;
    $search  = trim($_POST['search'] ?? '');
    $status  = $_POST['status'] ?? '';
    $loc     = $_POST['location'] ?? '';

    if ($status !== '' && !in_array($status, EQ_STATUSES, true)) $status = '';
    if ($loc    !== '' && !in_array($loc,    EQ_LOCS,     true)) $loc    = '';

    $conds  = [];
    $params = [];
    $types  = '';
    if ($search !== '') {
        $like = '%' . $search . '%';
        $conds[]  = '(name LIKE ? OR model_serial LIKE ? OR manufacturer LIKE ?)';
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types   .= 'sss';
    }
    if ($status !== '') { $conds[] = 'status=?';   $params[] = $status; $types .= 's'; }
    if ($loc    !== '') { $conds[] = 'location=?';  $params[] = $loc;    $types .= 's'; }
    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

    $cStmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM lab_equipment $where");
    if ($params) mysqli_stmt_bind_param($cStmt, $types, ...$params);
    mysqli_stmt_execute($cStmt);
    $total = (int)mysqli_fetch_row(mysqli_stmt_get_result($cStmt))[0];
    mysqli_stmt_close($cStmt);

    $dStmt = mysqli_prepare($conn,
        "SELECT id,name,model_serial,manufacturer,purchase_date,location,status,
                warranty_info,maintenance_schedule,created_at
         FROM lab_equipment $where
         ORDER BY name
         LIMIT ? OFFSET ?"
    );
    $dp = array_merge($params, [$perPage, $offset]);
    $dt = $types . 'ii';
    mysqli_stmt_bind_param($dStmt, $dt, ...$dp);
    mysqli_stmt_execute($dStmt);
    $res  = mysqli_stmt_get_result($dStmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    mysqli_stmt_close($dStmt);

    jsonOk(['equipment' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
}

/* ── AJAX: add ──────────────────────────────────────────── */
function handleAdd(): void
{
    global $conn;
    $f = collectEquipFields();
    if (isset($f['error'])) jsonErr($f['error']);

    $stmt = mysqli_prepare($conn,
        "INSERT INTO lab_equipment
         (name,model_serial,manufacturer,purchase_date,location,status,
          warranty_info,maintenance_schedule,created_at,updated_at)
         VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())"
    );
    $pd = ($f['purchase'] ?: null);
    mysqli_stmt_bind_param($stmt,'ssssssss',
        $f['name'],$f['model'],$f['mfg'],$pd,
        $f['location'],$f['status'],$f['warranty'],$f['schedule']
    );
    if (!mysqli_stmt_execute($stmt)) {
        error_log('[LabEquip] add: ' . mysqli_stmt_error($stmt));
        jsonErr('Failed to add equipment. Please try again.');
    }
    $newId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    jsonOk(['message' => 'Equipment added successfully.', 'id' => $newId]);
}

/* ── AJAX: update ───────────────────────────────────────── */
function handleUpdate(): void
{
    global $conn;
    $id = max(0, (int)($_POST['id'] ?? 0));
    if (!$id) jsonErr('Invalid equipment ID.');
    $f = collectEquipFields();
    if (isset($f['error'])) jsonErr($f['error']);

    $stmt = mysqli_prepare($conn,
        "UPDATE lab_equipment SET
         name=?,model_serial=?,manufacturer=?,purchase_date=?,location=?,status=?,
         warranty_info=?,maintenance_schedule=?,updated_at=NOW()
         WHERE id=?"
    );
    $pd = ($f['purchase'] ?: null);
    mysqli_stmt_bind_param($stmt,'ssssssssi',
        $f['name'],$f['model'],$f['mfg'],$pd,
        $f['location'],$f['status'],$f['warranty'],$f['schedule'],$id
    );
    if (!mysqli_stmt_execute($stmt)) {
        error_log('[LabEquip] update: ' . mysqli_stmt_error($stmt));
        jsonErr('Failed to update equipment. Please try again.');
    }
    mysqli_stmt_close($stmt);
    jsonOk(['message' => 'Equipment updated successfully.']);
}

/* ── AJAX: delete ───────────────────────────────────────── */
function handleDelete(): void
{
    global $conn;
    $id = max(0, (int)($_POST['id'] ?? 0));
    if (!$id) jsonErr('Invalid equipment ID.');

    // Check if linked to upcoming lab bookings
    $chk = mysqli_prepare($conn,
        "SELECT COUNT(*) FROM lab_booking_equipment lbe
         JOIN lab_bookings lb ON lb.id=lbe.booking_id
         WHERE lbe.equipment_id=? AND lb.booking_date >= CURDATE()"
    );
    mysqli_stmt_bind_param($chk,'i',$id);
    mysqli_stmt_execute($chk);
    $linked = (int)mysqli_fetch_row(mysqli_stmt_get_result($chk))[0];
    mysqli_stmt_close($chk);
    if ($linked > 0) {
        jsonErr("Cannot delete: this equipment is linked to $linked upcoming booking(s). Remove it from those bookings first.");
    }

    $del = mysqli_prepare($conn,'DELETE FROM lab_equipment WHERE id=?');
    mysqli_stmt_bind_param($del,'i',$id);
    if (!mysqli_stmt_execute($del)) {
        error_log('[LabEquip] delete: ' . mysqli_stmt_error($del));
        jsonErr('Failed to delete. Please try again.');
    }
    if (mysqli_stmt_affected_rows($del) === 0) jsonErr('Equipment not found.');
    mysqli_stmt_close($del);
    jsonOk(['message' => 'Equipment deleted successfully.']);
}

/* ── AJAX: get stats ─────────────────────────────────────── */
function handleGetStats(): void
{
    global $conn;
    jsonOk(['stats' => getStats($conn)]);
}

/* ── Page stats ─────────────────────────────────────────── */
function getStats(mysqli $db): array
{
    $r = mysqli_query($db,
        "SELECT
           COUNT(*) AS total,
           SUM(status='active') AS active,
           SUM(status='needs_repair') AS needs_repair,
           SUM(status='inactive') AS inactive
         FROM lab_equipment"
    );
    return mysqli_fetch_assoc($r) ?: ['total'=>0,'active'=>0,'needs_repair'=>0,'inactive'=>0];
}
$stats = getStats($conn);
$tracker->trackAction('Lab Equipment');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Lab Equipment &mdash; SchoolPilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--red-bg:#ffebee;
  --orange:#e65100;--orange-bg:#fff3e0;
  --blue:#1565c0;--blue-bg:#e3f2fd;
  --amber:#f57f17;--amber-bg:#fffde7;
  --gray:#546e7a;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);
  --shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --tr:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}
a{color:inherit;text-decoration:none}
button,input,select,textarea{font-family:inherit}
input,select,textarea{cursor:text}

.page{max-width:100%;margin:0 auto;padding:24px 20px 60px}
.page-header{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--radius-lg);padding:28px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:22px;margin-top:40px;box-shadow:var(--shadow-lg)}
.hdr-left h1{color:#fff;font-size:1.55rem;font-weight:700;display:flex;align-items:center;gap:12px}
.hdr-left p{color:rgba(255,255,255,.78);font-size:.9rem;margin-top:4px}
.hdr-right{display:flex;flex-wrap:wrap;gap:10px}
.stat-pill{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:40px;padding:8px 18px;text-align:center;min-width:80px}
.stat-pill .n{font-size:1.35rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.72rem;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.5px}
.stat-pill.warn .n{color:#ffe082}

.alert-banner{display:flex;align-items:center;gap:12px;padding:12px 18px;border-radius:var(--radius);margin-bottom:14px;font-size:.875rem;font-weight:500}
.alert-banner.warn{background:var(--orange-bg);color:var(--orange);border:1px solid #ffcc80}

.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px}
.toolbar{padding:16px 22px;border-bottom:1px solid #e8ede9;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}
.toolbar-left{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.toolbar-right{display:flex;gap:8px;flex-shrink:0}

.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;transition:all var(--tr);white-space:nowrap;cursor:pointer}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-secondary{background:#f5f5f5;color:#444;border:1.5px solid #ddd}.btn-secondary:hover{background:#eee}
.btn-danger{background:var(--red);color:#fff}.btn-danger:hover{background:#b71c1c}
.btn-sm{padding:6px 12px;font-size:.8rem}

.search-wrap{position:relative;min-width:220px}
.search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.85rem;pointer-events:none}
.search-wrap input{width:100%;padding:9px 12px 9px 34px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;transition:border-color var(--tr),box-shadow var(--tr)}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.filter-select{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;background:#fff;min-width:130px;transition:border-color var(--tr)}
.filter-select:focus{outline:none;border-color:var(--g600)}
.result-count{font-size:.8rem;color:#6b7c6d;white-space:nowrap}

/* ── Table (replaces card grid) ─────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:12px 14px;text-align:left;font-size:.8rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--tr)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:12px 14px;font-size:.875rem;vertical-align:middle}
.action-cell{display:flex;gap:5px}

.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.badge-active{background:var(--g100);color:var(--g800)}
.badge-inactive{background:#eceff1;color:#546e7a}
.badge-needs_repair{background:var(--orange-bg);color:var(--orange)}
.badge-decommissioned{background:var(--red-bg);color:var(--red)}

.skeleton{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;height:14px;display:inline-block;width:80%}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
.empty-state{text-align:center;padding:60px 20px;color:#8a9a8b;grid-column:1/-1}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;opacity:.4}

.pagination{padding:14px 22px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e8ede9;flex-wrap:wrap;gap:10px}
.page-info{font-size:.82rem;color:#6b7c6d}
.page-btns{display:flex;gap:4px}
.page-btn{width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:6px;background:#fff;font-size:.82rem;font-weight:600;color:#444;display:flex;align-items:center;justify-content:center;transition:all var(--tr);cursor:pointer}
.page-btn:hover:not(:disabled):not(.active){border-color:var(--g600);background:var(--g100);color:var(--g800)}
.page-btn.active{background:var(--g700);border-color:var(--g700);color:#fff;cursor:default}
.page-btn:disabled{opacity:.38;cursor:default}

.btn-icon{width:30px;height:30px;border:none;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;transition:all var(--tr);cursor:pointer}
.btn-icon:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.18)}
.bi-edit{background:#fff3e0;color:#e65100}.bi-edit:hover{background:#e65100;color:#fff}
.bi-delete{background:var(--red-bg);color:var(--red)}.bi-delete:hover{background:var(--red);color:#fff}

.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px)}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto;animation:fadeIn .2s ease}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:700px;box-shadow:var(--shadow-lg);animation:slideDown .25s ease;margin:auto}
@keyframes slideDown{from{transform:translateY(-20px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%);padding:20px 26px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head h2{color:#fff;font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:1.1rem;display:flex;align-items:center;justify-content:center;transition:background var(--tr);cursor:pointer}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:26px 28px}
.modal-footer{padding:14px 28px 22px;display:flex;justify-content:flex-end;gap:10px;border-top:1px solid #e8ede9}

.form-section-title{font-size:.78rem;font-weight:700;color:var(--g800);text-transform:uppercase;letter-spacing:.5px;margin:0 0 12px;padding-bottom:6px;border-bottom:1px solid #e8ede9}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
.form-label{font-size:.82rem;font-weight:600;color:#444}
.form-label .req{color:var(--red)}
.form-control{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;background:#fff;transition:border-color var(--tr),box-shadow var(--tr);width:100%}
.form-control:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.form-control.error{border-color:var(--red)}

.dialog{display:none;position:fixed;inset:0;z-index:1100;background:rgba(0,0,0,.45);backdrop-filter:blur(3px)}
.dialog.active{display:flex;align-items:center;justify-content:center;padding:20px}
.dialog-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:400px;box-shadow:var(--shadow-lg);overflow:hidden;animation:slideDown .2s ease}
.dialog-head{padding:18px 22px;display:flex;align-items:center;gap:10px;background:var(--red-bg)}
.dialog-head i{font-size:1.3rem;color:var(--red)}
.dialog-head span{font-weight:700;font-size:1rem}
.dialog-body{padding:16px 22px;font-size:.9rem;color:#444;line-height:1.5}
.dialog-actions{padding:14px 22px 18px;display:flex;justify-content:flex-end;gap:8px}

#notif-stack{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.notif{display:flex;align-items:flex-start;gap:12px;padding:13px 16px;border-radius:var(--radius);box-shadow:0 4px 16px rgba(0,0,0,.18);min-width:280px;max-width:360px;pointer-events:auto;animation:slideNotif .3s ease}
@keyframes slideNotif{from{transform:translateX(30px);opacity:0}to{transform:translateX(0);opacity:1}}
.notif.success{background:#1b5e20;color:#fff}.notif.error{background:#b71c1c;color:#fff}
.notif.warning{background:#e65100;color:#fff}.notif.info{background:#1565c0;color:#fff}
.notif-icon{font-size:1rem;flex-shrink:0;margin-top:1px}
.notif-body{flex:1}
.notif-title{font-weight:700;font-size:.85rem}
.notif-msg{font-size:.8rem;opacity:.88;margin-top:2px}
.notif-close{background:none;border:none;color:inherit;opacity:.7;cursor:pointer;font-size:.9rem;padding:0}

@media(max-width:680px){.form-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php require_once 'nav.php'; ?>

<div class="page">

  <div class="alert-banner warn" id="repairAlert" style="<?= (int)$stats['needs_repair'] > 0 ? '' : 'display:none' ?>">
    <i class="fas fa-tools"></i>
    <span id="repairAlertText"><?= (int)$stats['needs_repair'] ?> piece<?= $stats['needs_repair'] > 1 ? 's' : '' ?> of equipment <strong>need<?= $stats['needs_repair'] > 1 ? '' : 's' ?> repair</strong>. Schedule maintenance as soon as possible.</span>
  </div>

  <!-- ── Page Header ────────────────────────────────────── -->
  <div class="page-header">
    <div class="hdr-left">
      <h1><i class="fas fa-microscope"></i> Lab Equipment</h1>
      <p>Inventory, status &amp; maintenance tracking</p>
    </div>
    <div class="hdr-right">
      <div class="stat-pill"><span class="n" id="statTotal"><?= (int)$stats['total'] ?></span><span class="l">Total</span></div>
      <div class="stat-pill"><span class="n" id="statActive"><?= (int)$stats['active'] ?></span><span class="l">Active</span></div>
      <div class="stat-pill warn"><span class="n" id="statNeedsRepair"><?= (int)$stats['needs_repair'] ?></span><span class="l">Needs Repair</span></div>
      <div class="stat-pill warn"><span class="n" id="statInactive"><?= (int)$stats['inactive'] ?></span><span class="l">Inactive</span></div>
    </div>
  </div>

  <!-- ── Main Card ──────────────────────────────────────── -->
  <div class="card">
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search name, model, manufacturer…" oninput="debounce(loadEquipment, 350)()">
        </div>
        <select class="filter-select" id="statusFilter" onchange="loadEquipment()">
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
          <option value="needs_repair">Needs Repair</option>
          <option value="decommissioned">Decommissioned</option>
        </select>
        <select class="filter-select" id="locationFilter" onchange="loadEquipment()">
          <option value="">All locations</option>
          <option value="room_101">Room 101</option>
          <option value="room_102">Room 102</option>
          <option value="room_103">Room 103</option>
          <option value="prep_room">Prep Room</option>
          <option value="storage">Storage</option>
          <option value="fume_hood_area">Fume Hood Area</option>
          <option value="microscopy_room">Microscopy Room</option>
          <option value="other">Other</option>
        </select>
        <span class="result-count" id="resultCount"></span>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-primary btn-sm" onclick="openModal()">
          <i class="fas fa-plus"></i> Add Equipment
        </button>
      </div>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Equipment Name</th>
            <th>Model / Serial</th>
            <th>Manufacturer</th>
            <th>Location</th>
            <th>Status</th>
            <th>Purchased</th>
            <th>Maintenance</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="eqTableBody">
          <?php for ($i = 0; $i < 6; $i++): ?>
            <tr><td><span class="skeleton"></span></td><td><span class="skeleton" style="width:65%"></span></td><td><span class="skeleton" style="width:70%"></span></td><td><span class="skeleton" style="width:60%"></span></td><td><span class="skeleton" style="width:55px;height:18px"></span></td><td><span class="skeleton" style="width:55%"></span></td><td><span class="skeleton" style="width:75%"></span></td><td><span class="skeleton" style="width:60px;height:22px"></span></td></tr>
          <?php endfor; ?>
        </tbody>
      </table>
    </div>

    <div class="pagination" id="paginationBar" style="display:none">
      <span class="page-info" id="pageInfo"></span>
      <div class="page-btns" id="pageBtns"></div>
    </div>
  </div>

</div><!-- /page -->

<!-- ── Equipment Modal ───────────────────────────────────── -->
<div class="modal" id="eqModal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-microscope"></i> <span id="modalTitle">Add Equipment</span></h2>
      <button class="modal-close" onclick="closeModal()">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="fId">

      <div class="form-section-title">Basic Information</div>
      <div class="form-grid">
        <div class="form-group full">
          <label class="form-label">Equipment Name <span class="req">*</span></label>
          <input type="text" class="form-control" id="fName" maxlength="200" placeholder="e.g., Compound Light Microscope">
        </div>
        <div class="form-group">
          <label class="form-label">Model / Serial Number <span class="req">*</span></label>
          <input type="text" class="form-control" id="fModel" maxlength="100" placeholder="e.g., EM-2400 / SN-38291">
        </div>
        <div class="form-group">
          <label class="form-label">Manufacturer <span class="req">*</span></label>
          <input type="text" class="form-control" id="fMfg" maxlength="150" placeholder="e.g., Olympus">
        </div>
        <div class="form-group">
          <label class="form-label">Purchase Date</label>
          <input type="date" class="form-control" id="fPurchase">
        </div>
      </div>

      <div class="form-section-title">Location &amp; Status</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Location <span class="req">*</span></label>
          <select class="form-control" id="fLoc">
            <option value="">Select location</option>
            <option value="room_101">Room 101</option>
            <option value="room_102">Room 102</option>
            <option value="room_103">Room 103</option>
            <option value="prep_room">Prep Room</option>
            <option value="storage">Storage</option>
            <option value="fume_hood_area">Fume Hood Area</option>
            <option value="microscopy_room">Microscopy Room</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Status <span class="req">*</span></label>
          <select class="form-control" id="fStatus">
            <option value="">Select status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="needs_repair">Needs Repair</option>
            <option value="decommissioned">Decommissioned</option>
          </select>
        </div>
        <div class="form-group full">
          <label class="form-label">Maintenance Schedule</label>
          <input type="text" class="form-control" id="fSchedule" maxlength="500" placeholder="e.g., Annual calibration, every 6 months">
        </div>
      </div>

      <div class="form-section-title">Warranty Information</div>
      <div class="form-grid">
        <div class="form-group full">
          <textarea class="form-control" id="fWarranty" rows="3" maxlength="1000"
                    placeholder="Warranty details, expiry date, provider contact…"></textarea>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
      <button class="btn btn-danger" id="deleteBtnModal" style="display:none" onclick="promptDelete()">
        <i class="fas fa-trash"></i> Delete
      </button>
      <button class="btn btn-primary" id="saveBtn" onclick="saveEquipment()">
        <i class="fas fa-save"></i> Save Equipment
      </button>
    </div>
  </div>
</div>

<!-- ── Confirm dialog ─────────────────────────────────────── -->
<div class="dialog" id="confirmDlg" onclick="if(event.target===this)closeDlg()">
  <div class="dialog-box">
    <div class="dialog-head"><i class="fas fa-trash-alt"></i><span>Delete Equipment</span></div>
    <div class="dialog-body" id="dlgBody"></div>
    <div class="dialog-actions">
      <button class="btn btn-secondary" onclick="closeDlg()">Cancel</button>
      <button class="btn btn-danger" onclick="doDelete()">Yes, delete</button>
    </div>
  </div>
</div>

<div id="notif-stack"></div>

<script>
'use strict';
const CSRF    = <?= json_encode($csrf, JSON_HEX_QUOT | JSON_HEX_TAG) ?>;
const THIS_URL = 'add_lab_equipment.php';

let equipment    = [];
let currentPage  = 1;
let totalRows    = 0;
let pendingDelId = 0;
const PER_PAGE   = 20;

document.addEventListener('DOMContentLoaded', loadEquipment);

/* ── Stats refresh ─────────────────────────────────────── */
async function refreshStats() {
    const fd = new FormData();
    fd.append('action', 'get_stats');
    try {
        const res  = await fetch(THIS_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) return;
        const s = data.stats;
        document.getElementById('statTotal').textContent      = s.total;
        document.getElementById('statActive').textContent     = s.active;
        document.getElementById('statNeedsRepair').textContent = s.needs_repair;
        document.getElementById('statInactive').textContent   = s.inactive;
        const nr = parseInt(s.needs_repair) || 0;
        const alert = document.getElementById('repairAlert');
        if (nr > 0) {
            document.getElementById('repairAlertText').innerHTML =
                `${nr} piece${nr > 1 ? 's' : ''} of equipment <strong>need${nr > 1 ? '' : 's'} repair</strong>. Schedule maintenance as soon as possible.`;
            alert.style.display = '';
        } else {
            alert.style.display = 'none';
        }
    } catch(e) { /* non-critical */ }
}

/* ── Load ─────────────────────────────────────────────── */
async function loadEquipment(page = 1, silent = false) {
    currentPage = page;
    if (!silent) {
        const skRow = `<tr>${Array(8).fill('<td><span class="skeleton"></span></td>').join('')}</tr>`;
        document.getElementById('eqTableBody').innerHTML = Array(6).fill(skRow).join('');
        document.getElementById('paginationBar').style.display = 'none';
    }

    const fd = new FormData();
    fd.append('action',   'list');
    fd.append('page',     page);
    fd.append('search',   document.getElementById('searchInput').value.trim());
    fd.append('status',   document.getElementById('statusFilter').value);
    fd.append('location', document.getElementById('locationFilter').value);
    try {
        const res  = await fetch(THIS_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) { notify('Error', data.error, 'error'); return; }
        equipment = data.equipment;
        totalRows = data.total;
        renderTable();
        renderPagination();
        document.getElementById('resultCount').textContent = `${data.total} item${data.total !== 1 ? 's' : ''}`;
    } catch(e) {
        notify('Error', 'Failed to load equipment.', 'error');
    }
}

/* ── Render table rows ────────────────────────────────── */
function renderTable() {
    const tbody = document.getElementById('eqTableBody');
    if (!equipment.length) {
        tbody.innerHTML = `<tr><td colspan="8"><div class="empty-state"><i class="fas fa-microscope"></i><p>No equipment found.</p></div></td></tr>`;
        return;
    }
    tbody.innerHTML = equipment.map(e => `<tr>
      <td>
        <div style="font-weight:600">${esc(e.name)}</div>
      </td>
      <td style="font-size:.82rem;color:#555">${esc(e.model_serial)}</td>
      <td style="font-size:.82rem">${esc(e.manufacturer)}</td>
      <td style="font-size:.82rem">${fmtLoc(e.location)}</td>
      <td><span class="badge badge-${e.status}">${fmtStatus(e.status)}</span></td>
      <td style="font-size:.82rem">${e.purchase_date ? fmtDate(e.purchase_date) : '<span style="color:#bbb">—</span>'}</td>
      <td style="font-size:.82rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(e.maintenance_schedule || '')}">${e.maintenance_schedule ? esc(e.maintenance_schedule) : '<span style="color:#bbb">—</span>'}</td>
      <td>
        <div class="action-cell">
          <button class="btn-icon bi-edit" title="Edit" onclick='openModal(${JSON.stringify(e)})'><i class="fas fa-edit"></i></button>
          <button class="btn-icon bi-delete" title="Delete" onclick="promptDelete(${e.id},'${esc(e.name)}')"><i class="fas fa-trash"></i></button>
        </div>
      </td>
    </tr>`).join('');
}

/* ── Pagination ──────────────────────────────────────────── */
function renderPagination() {
    const bar   = document.getElementById('paginationBar');
    const pages = Math.ceil(totalRows / PER_PAGE);
    if (pages <= 1) { bar.style.display = 'none'; return; }
    bar.style.display = 'flex';
    const from = (currentPage-1)*PER_PAGE+1;
    const to   = Math.min(currentPage*PER_PAGE, totalRows);
    document.getElementById('pageInfo').textContent = `Showing ${from}–${to} of ${totalRows}`;
    let html = `<button class="page-btn" ${currentPage===1?'disabled':''} onclick="loadEquipment(${currentPage-1})"><i class="fas fa-chevron-left"></i></button>`;
    for (let p=1;p<=pages;p++) {
        if (pages>7 && Math.abs(p-currentPage)>2 && p!==1 && p!==pages) {
            if(p===2||p===pages-1) html+=`<button class="page-btn" disabled>…</button>`;
            continue;
        }
        html+=`<button class="page-btn ${p===currentPage?'active':''}" onclick="loadEquipment(${p})">${p}</button>`;
    }
    html+=`<button class="page-btn" ${currentPage===pages?'disabled':''} onclick="loadEquipment(${currentPage+1})"><i class="fas fa-chevron-right"></i></button>`;
    document.getElementById('pageBtns').innerHTML = html;
}

/* ── Modal ───────────────────────────────────────────────── */
function openModal(eq = null) {
    clearForm();
    const isEdit = eq !== null;
    document.getElementById('modalTitle').textContent = isEdit ? 'Edit Equipment' : 'Add Equipment';
    document.getElementById('deleteBtnModal').style.display = isEdit ? '' : 'none';
    if (isEdit) {
        document.getElementById('fId').value       = eq.id;
        document.getElementById('fName').value     = eq.name;
        document.getElementById('fModel').value    = eq.model_serial;
        document.getElementById('fMfg').value      = eq.manufacturer;
        document.getElementById('fPurchase').value = eq.purchase_date || '';
        document.getElementById('fLoc').value      = eq.location;
        document.getElementById('fStatus').value   = eq.status;
        document.getElementById('fSchedule').value = eq.maintenance_schedule || '';
        document.getElementById('fWarranty').value = eq.warranty_info || '';
    }
    document.getElementById('eqModal').classList.add('active');
}
function closeModal() {
    document.getElementById('eqModal').classList.remove('active');
}
function clearForm() {
    ['fId','fName','fModel','fMfg','fPurchase','fLoc','fStatus','fSchedule','fWarranty']
        .forEach(id => { const el=document.getElementById(id); el.value=''; el.classList.remove('error'); });
}

/* ── Save ────────────────────────────────────────────────── */
async function saveEquipment() {
    const id = document.getElementById('fId').value;
    const fd = new FormData();
    fd.append('action',              id ? 'update' : 'add');
    fd.append('csrf_token',          CSRF);
    if (id) fd.append('id', id);
    fd.append('name',                document.getElementById('fName').value.trim());
    fd.append('model_serial',        document.getElementById('fModel').value.trim());
    fd.append('manufacturer',        document.getElementById('fMfg').value.trim());
    fd.append('purchase_date',       document.getElementById('fPurchase').value);
    fd.append('location',            document.getElementById('fLoc').value);
    fd.append('status',              document.getElementById('fStatus').value);
    fd.append('maintenance_schedule', document.getElementById('fSchedule').value.trim());
    fd.append('warranty_info',       document.getElementById('fWarranty').value.trim());

    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    try {
        const res  = await fetch(THIS_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            notify('Saved', data.message, 'success');
            closeModal();
            loadEquipment(currentPage, true);
            refreshStats();
        } else {
            notify('Error', data.error, 'error');
        }
    } catch(e) {
        notify('Error', 'Network error. Please try again.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Equipment';
    }
}

/* ── Delete ──────────────────────────────────────────────── */
function promptDelete(id, name) {
    // If no ID is passed (e.g., from the modal button), grab it from the hidden input
    if (!id) {
        id = document.getElementById('fId').value;
        name = document.getElementById('fName').value;
    }

    if (!id) {
        notify('Error', 'Could not identify equipment to delete.', 'error');
        return;
    }

    pendingDelId = id;
    document.getElementById('dlgBody').innerHTML =
        `Delete <strong>${esc(String(name || 'this item'))}</strong>?<br>This cannot be undone. Equipment linked to upcoming bookings cannot be deleted.`;
    document.getElementById('confirmDlg').classList.add('active');
}
function closeDlg(){document.getElementById('confirmDlg').classList.remove('active');pendingDelId=0;}
async function doDelete() {
    // 1. Check if we have an ID
    if (!pendingDelId) return;

    // 2. Save the ID to a local variable before closeDlg resets the global one
    const idToDelete = pendingDelId;

    // 3. Now it's safe to close the UI
    closeDlg();
    closeModal();

    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('csrf_token', CSRF);
    fd.append('id', idToDelete); // Use the local variable here

    try {
        const res = await fetch(THIS_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            notify('Deleted', data.message, 'success');
            loadEquipment(currentPage, true);
            refreshStats();
        } else {
            notify('Error', data.error, 'error');
        }
    } catch (e) {
        notify('Error', 'Network error.', 'error');
    }
}

/* ── Utilities ───────────────────────────────────────────── */
function notify(title,msg,type='success',dur=4500){const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};const n=document.createElement('div');n.className=`notif ${type}`;n.innerHTML=`<i class="fas ${icons[type]} notif-icon"></i><div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div><button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;document.getElementById('notif-stack').prepend(n);setTimeout(()=>{n.style.opacity='0';n.style.transform='translateX(30px)';n.style.transition='.3s';setTimeout(()=>n.remove(),320);},dur);}
function esc(v){return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function fmtDate(d){if(!d)return'—';try{return new Date(d+'T00:00:00').toLocaleDateString('en-UG',{day:'2-digit',month:'short',year:'numeric'});}catch(_){return d;}}
function fmtLoc(v){const m={room_101:'Room 101',room_102:'Room 102',room_103:'Room 103',prep_room:'Prep Room',storage:'Storage',fume_hood_area:'Fume Hood Area',microscopy_room:'Microscopy Room',other:'Other'};return m[v]||v||'—';}
function fmtStatus(v){const m={active:'Active',inactive:'Inactive',needs_repair:'Needs Repair',decommissioned:'Decommissioned'};return m[v]||v;}
const debounce=(fn,ms)=>{let t;return(...a)=>{clearTimeout(t);t=setTimeout(()=>fn(...a),ms);};};
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeModal();closeDlg();}});
</script>
</body>
</html>
