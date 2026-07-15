<?php
/**
 * lab_chemicals.php — Chemical Inventory Management
 * SchoolPilot · Production build
 *
 * Security:  CSRF on all mutations · prepared statements only ·
 *            whitelist validation · errors logged, not exposed ·
 *            server-side pagination · no schema leakage.
 *
 * DB table:  chemicals(id, name, cas, formula, supplier, quantity,
 *            unit, location, hazard_level, purchase_date,
 *            expiration_date, batch_number, minimum_stock,
 *            sds_url, notes, created_at, updated_at)
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
const HAZARD_LEVELS = ['low', 'medium', 'high', 'extreme'];
const CHEM_UNITS    = ['g', 'kg', 'mg', 'ml', 'L', 'mol', 'mmol', 'units'];
const CHEM_LOCS     = [
    'cabinet_a', 'cabinet_b', 'cabinet_c',
    'fume_hood', 'cold_storage', 'flammables_cabinet',
    'acid_cabinet', 'general_storage'
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
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $day] = explode('-', $d);
    return checkdate((int)$m, (int)$day, (int)$y);
}

/* ── AJAX dispatcher ────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    switch ($_POST['action']) {
        case 'add':     csrfGuard(); handleAdd();    break;
        case 'update':  csrfGuard(); handleUpdate(); break;
        case 'delete':  csrfGuard(); handleDelete(); break;
        case 'list':                 handleList();   break;
        case 'get_stats':            handleGetStats(); break;
        default: jsonErr('Unknown action.');
    }
}

/* ── AJAX: list (paginated, filtered) ───────────────────── */
function handleList(): void
{
    global $conn;
    $page    = max(1, (int)($_POST['page'] ?? 1));
    $perPage = 25;
    $offset  = ($page - 1) * $perPage;
    $search  = trim($_POST['search'] ?? '');
    $hazard  = $_POST['hazard'] ?? '';
    $loc     = $_POST['location'] ?? '';

    if ($hazard !== '' && !in_array($hazard, HAZARD_LEVELS, true)) $hazard = '';
    if ($loc    !== '' && !in_array($loc,    CHEM_LOCS,    true)) $loc    = '';

    // Build WHERE
    $conds  = [];
    $params = [];
    $types  = '';
    if ($search !== '') {
        $like = '%' . $search . '%';
        $conds[]  = '(name LIKE ? OR cas LIKE ? OR supplier LIKE ?)';
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types   .= 'sss';
    }
    if ($hazard !== '') { $conds[] = 'hazard_level=?'; $params[] = $hazard; $types .= 's'; }
    if ($loc    !== '') { $conds[] = 'location=?';     $params[] = $loc;    $types .= 's'; }
    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

    // Count
    $countStmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM chemicals $where");
    if ($params) mysqli_stmt_bind_param($countStmt, $types, ...$params);
    mysqli_stmt_execute($countStmt);
    $total = (int)mysqli_fetch_row(mysqli_stmt_get_result($countStmt))[0];
    mysqli_stmt_close($countStmt);

    // Data
    $dataStmt = mysqli_prepare($conn,
        "SELECT id,name,cas,formula,supplier,quantity,unit,location,
                hazard_level,purchase_date,expiration_date,
                batch_number,minimum_stock,sds_url,notes,created_at
         FROM chemicals $where
         ORDER BY name
         LIMIT ? OFFSET ?"
    );
    $dataParams = $params;
    $dataTypes  = $types . 'ii';
    $dataParams[] = $perPage;
    $dataParams[] = $offset;
    mysqli_stmt_bind_param($dataStmt, $dataTypes, ...$dataParams);
    mysqli_stmt_execute($dataStmt);
    $res  = mysqli_stmt_get_result($dataStmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    mysqli_stmt_close($dataStmt);

    jsonOk(['chemicals' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
}

/* ── AJAX: add ──────────────────────────────────────────── */
function handleAdd(): void
{
    global $conn;
    $f = collectFields();
    if ($f['error']) jsonErr($f['error']);

    $stmt = mysqli_prepare($conn,
        "INSERT INTO chemicals
         (name,cas,formula,supplier,quantity,unit,location,hazard_level,
          purchase_date,expiration_date,batch_number,minimum_stock,sds_url,notes,
          created_at,updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())"
    );
      mysqli_stmt_bind_param($stmt, 'ssssdssssssdss',
          $f['name'], $f['cas'], $f['formula'], $f['supplier'],
          $f['quantity'], $f['unit'], $f['location'], $f['hazard_level'],
          $f['purchase_date'], $f['expiration_date'], $f['batch_number'],
          $f['minimum_stock'], $f['sds_url'], $f['notes']
      );
    if (!mysqli_stmt_execute($stmt)) {
        error_log('[LabChemicals] add: ' . mysqli_stmt_error($stmt));
        jsonErr('Failed to add chemical. Please try again.');
    }
    $newId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    jsonOk(['message' => 'Chemical added successfully.', 'id' => $newId]);
}

/* ── AJAX: update ───────────────────────────────────────── */
function handleUpdate(): void
{
    global $conn;
    $id = max(0, (int)($_POST['id'] ?? 0));
    if (!$id) jsonErr('Invalid chemical ID.');
    $f = collectFields();
    if ($f['error']) jsonErr($f['error']);

    $stmt = mysqli_prepare($conn,
        "UPDATE chemicals SET
         name=?,cas=?,formula=?,supplier=?,quantity=?,unit=?,location=?,
         hazard_level=?,purchase_date=?,expiration_date=?,batch_number=?,
         minimum_stock=?,sds_url=?,notes=?,updated_at=NOW()
         WHERE id=?"
    );
    mysqli_stmt_bind_param($stmt, 'ssssdssssssdssi',
        $f['name'], $f['cas'], $f['formula'], $f['supplier'],
        $f['quantity'], $f['unit'], $f['location'], $f['hazard_level'],
        $f['purchase_date'], $f['expiration_date'], $f['batch_number'],
        $f['minimum_stock'], $f['sds_url'], $f['notes'],
        $id
    );
    if (!mysqli_stmt_execute($stmt)) {
        error_log('[LabChemicals] update: ' . mysqli_stmt_error($stmt));
        jsonErr('Failed to update chemical. Please try again.');
    }
    mysqli_stmt_close($stmt);
    jsonOk(['message' => 'Chemical updated successfully.']);
}

/* ── AJAX: delete ───────────────────────────────────────── */
function handleDelete(): void
{
    global $conn;
    $id = max(0, (int)($_POST['id'] ?? 0));
    if (!$id) jsonErr('Invalid chemical ID.');

    // Block delete if active (non-returned) withdrawal records exist
    $chk = mysqli_prepare($conn,
        "SELECT COUNT(*) FROM chemical_withdrawals WHERE chemical_id=? AND status != 'returned'"
    );
    mysqli_stmt_bind_param($chk, 'i', $id);
    mysqli_stmt_execute($chk);
    $active = (int)mysqli_fetch_row(mysqli_stmt_get_result($chk))[0];
    mysqli_stmt_close($chk);
    if ($active > 0) {
        jsonErr("Cannot delete: this chemical has $active active withdrawal record(s). Return or delete those first.");
    }

    $stmt = mysqli_prepare($conn, "DELETE FROM chemicals WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    if (!mysqli_stmt_execute($stmt)) {
        error_log('[LabChemicals] delete: ' . mysqli_stmt_error($stmt));
        jsonErr('Failed to delete chemical. Please try again.');
    }
    if (mysqli_stmt_affected_rows($stmt) === 0) jsonErr('Chemical not found.');
    mysqli_stmt_close($stmt);
    jsonOk(['message' => 'Chemical deleted successfully.']);
}

/* ── Shared field collector & validator ─────────────────── */
function collectFields(): array
{
    $err = fn(string $m) => ['error' => $m] + array_fill_keys(
        ['name','cas','formula','supplier','quantity','unit','location',
         'hazard_level','purchase_date','expiration_date',
         'batch_number','minimum_stock','sds_url','notes'], ''
    );

    $name         = trim($_POST['name']          ?? '');
    $cas          = trim($_POST['cas']           ?? '');
    $formula      = trim($_POST['formula']       ?? '');
    $supplier     = trim($_POST['supplier']      ?? '');
    $quantity     = (float)($_POST['quantity']   ?? 0);
    $unit         = trim($_POST['unit']          ?? '');
    $location     = trim($_POST['location']      ?? '');
    $hazard       = trim($_POST['hazard_level']  ?? '');
    $purchase     = trim($_POST['purchase_date'] ?? '');
    $expiration   = trim($_POST['expiration_date'] ?? '');
    $batch        = trim($_POST['batch_number']  ?? '');
    $minStock     = (float)($_POST['minimum_stock'] ?? 0);
    $sdsUrl       = trim($_POST['sds_url']       ?? '');
    $notes        = trim($_POST['notes']         ?? '');

    if ($name === '')                                     return $err('Chemical name is required.');
    if (mb_strlen($name) > 200)                          return $err('Name too long (max 200 chars).');
    if ($cas !== '' && !preg_match('/^\d{1,7}-\d{2}-\d$/', $cas))
                                                         return $err('Invalid CAS number format (e.g. 7732-18-5).');
    if (!in_array($unit, CHEM_UNITS, true))              return $err('Invalid unit selected.');
    if (!in_array($location, CHEM_LOCS, true))           return $err('Invalid storage location.');
    if (!in_array($hazard, HAZARD_LEVELS, true))         return $err('Invalid hazard level.');
    if ($quantity < 0)                                   return $err('Quantity cannot be negative.');
    if ($minStock < 0)                                   return $err('Minimum stock cannot be negative.');
    if ($purchase !== '' && !validateDate($purchase))    return $err('Invalid purchase date.');
    if ($expiration !== '' && !validateDate($expiration)) return $err('Invalid expiration date.');
    if ($sdsUrl !== '' && !filter_var($sdsUrl, FILTER_VALIDATE_URL))
                                                         return $err('Invalid SDS URL format.');
    if (mb_strlen($notes) > 1000)                        return $err('Notes too long (max 1000 chars).');

    return [
        'error'=>'','name'=>$name,'cas'=>$cas,'formula'=>$formula,
        'supplier'=>$supplier,'quantity'=>$quantity,'unit'=>$unit,
        'location'=>$location,'hazard_level'=>$hazard,
        'purchase_date'=>($purchase ?: null),'expiration_date'=>($expiration ?: null),
        'batch_number'=>$batch,'minimum_stock'=>$minStock,
        'sds_url'=>$sdsUrl,'notes'=>$notes
    ];
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
           COUNT(*)                                      AS total,
           SUM(quantity <= minimum_stock AND minimum_stock>0) AS low_stock,
           SUM(expiration_date IS NOT NULL
               AND expiration_date < DATE_ADD(CURDATE(), INTERVAL 30 DAY)
               AND expiration_date >= CURDATE())         AS expiring_soon,
           SUM(expiration_date IS NOT NULL
               AND expiration_date < CURDATE())          AS expired
         FROM chemicals"
    );
    return mysqli_fetch_assoc($r) ?: ['total'=>0,'low_stock'=>0,'expiring_soon'=>0,'expired'=>0];
}

$stats = getStats($conn);
$tracker->trackAction('Lab Chemicals');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Chemical Inventory &mdash; SchoolPilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<style>
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--red-bg:#ffebee;
  --orange:#e65100;--orange-bg:#fff3e0;
  --blue:#1565c0;--blue-bg:#e3f2fd;
  --purple:#6a1b9a;--purple-bg:#f3e5f5;
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
button,input,select,textarea{font-family:inherit;cursor:pointer}
input,select,textarea{cursor:text}

.page{max-width:100%;margin:0 auto;padding:24px 20px 60px}

/* ── Page header ─────────────────────────────────────────── */
.page-header{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--radius-lg);padding:28px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:22px;margin-top:40px;box-shadow:var(--shadow-lg)}
.hdr-left h1{color:#fff;font-size:1.55rem;font-weight:700;display:flex;align-items:center;gap:12px}
.hdr-left p{color:rgba(255,255,255,.78);font-size:.9rem;margin-top:4px}
.hdr-right{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.stat-pill{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:40px;padding:8px 18px;text-align:center;min-width:84px}
.stat-pill .n{font-size:1.35rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.72rem;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.5px}
.stat-pill.warn .n{color:#ffe082}

/* ── Alert banners ───────────────────────────────────────── */
.alert-banner{display:flex;align-items:center;gap:12px;padding:12px 18px;border-radius:var(--radius);margin-bottom:14px;font-size:.875rem;font-weight:500}
.alert-banner.danger{background:var(--red-bg);color:var(--red);border:1px solid #ef9a9a}
.alert-banner.warn{background:var(--orange-bg);color:var(--orange);border:1px solid #ffcc80}
.alert-banner i{font-size:1.1rem;flex-shrink:0}

/* ── Card ────────────────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px}
.toolbar{padding:16px 22px;border-bottom:1px solid #e8ede9;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}
.toolbar-left{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.toolbar-right{display:flex;gap:8px;flex-shrink:0}

/* ── Buttons ─────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;transition:all var(--tr);white-space:nowrap;cursor:pointer}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-secondary{background:#f5f5f5;color:#444;border:1.5px solid #ddd}.btn-secondary:hover{background:#eee}
.btn-danger{background:var(--red);color:#fff}.btn-danger:hover{background:#b71c1c}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{background:#f5f5f5;transform:none}
.btn-sm{padding:6px 12px;font-size:.8rem}
.btn-pdf{background:#c62828;color:#fff}.btn-pdf:hover{background:var(--red)}
.btn-excel{background:var(--g800);color:#fff}.btn-excel:hover{background:var(--g900)}

/* ── Search / filters ────────────────────────────────────── */
.search-wrap{position:relative;min-width:220px}
.search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.85rem;pointer-events:none}
.search-wrap input{width:100%;padding:9px 12px 9px 34px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;transition:border-color var(--tr),box-shadow var(--tr)}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.filter-select{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;background:#fff;min-width:130px;transition:border-color var(--tr)}
.filter-select:focus{outline:none;border-color:var(--g600)}
.result-count{font-size:.8rem;color:#6b7c6d;white-space:nowrap}

/* ── Table ───────────────────────────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:12px 14px;text-align:left;font-size:.8rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap;cursor:pointer;user-select:none}
thead th:hover{filter:brightness(1.1)}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--tr)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:12px 14px;font-size:.875rem;vertical-align:middle}

/* ── Badges ──────────────────────────────────────────────── */
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.badge-low{background:var(--g100);color:var(--g800)}
.badge-medium{background:var(--orange-bg);color:var(--orange)}
.badge-high{background:var(--red-bg);color:var(--red)}
.badge-extreme{background:#4a148c;color:#fff}

/* ── Quantity bar ────────────────────────────────────────── */
.qty-wrap{display:flex;align-items:center;gap:8px}
.qty-bar{width:44px;height:5px;background:#e0e0e0;border-radius:3px;overflow:hidden;flex-shrink:0}
.qty-fill{height:100%;border-radius:3px;transition:width .3s}
.qty-ok{background:var(--g600)}
.qty-low{background:var(--amber)}
.qty-crit{background:var(--red)}

/* ── Expiry ──────────────────────────────────────────────── */
.expiry-ok{color:var(--g700);font-size:.8rem}
.expiry-warn{color:var(--orange);font-size:.8rem;font-weight:600}
.expiry-exp{color:var(--red);font-size:.8rem;font-weight:700}

/* ── Icon action buttons ─────────────────────────────────── */
.action-cell{display:flex;gap:5px}
.btn-icon{width:30px;height:30px;border:none;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;transition:all var(--tr);cursor:pointer}
.btn-icon:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.18)}
.bi-edit{background:#fff3e0;color:#e65100}.bi-edit:hover{background:#e65100;color:#fff}
.bi-link{background:var(--blue-bg);color:var(--blue)}.bi-link:hover{background:var(--blue);color:#fff}
.bi-delete{background:var(--red-bg);color:var(--red)}.bi-delete:hover{background:var(--red);color:#fff}

/* ── Skeleton ────────────────────────────────────────────── */
.skeleton{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;height:14px;display:inline-block;width:80%}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ── Empty state ─────────────────────────────────────────── */
.empty-state{text-align:center;padding:60px 20px;color:#8a9a8b}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;opacity:.4}

/* ── Pagination ──────────────────────────────────────────── */
.pagination{padding:14px 22px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e8ede9;flex-wrap:wrap;gap:10px}
.page-info{font-size:.82rem;color:#6b7c6d}
.page-btns{display:flex;gap:4px}
.page-btn{width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:6px;background:#fff;font-size:.82rem;font-weight:600;color:#444;display:flex;align-items:center;justify-content:center;transition:all var(--tr);cursor:pointer}
.page-btn:hover:not(:disabled):not(.active){border-color:var(--g600);background:var(--g100);color:var(--g800)}
.page-btn.active{background:var(--g700);border-color:var(--g700);color:#fff;cursor:default}
.page-btn:disabled{opacity:.38;cursor:default}

/* ── Modal ───────────────────────────────────────────────── */
.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px)}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto;animation:fadeIn .2s ease}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:800px;box-shadow:var(--shadow-lg);animation:slideDown .25s ease;margin:auto}
@keyframes slideDown{from{transform:translateY(-20px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%);padding:20px 26px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head h2{color:#fff;font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:1.1rem;display:flex;align-items:center;justify-content:center;transition:background var(--tr);cursor:pointer}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:26px 28px}
.modal-footer{padding:14px 28px 22px;display:flex;justify-content:flex-end;gap:10px;border-top:1px solid #e8ede9}

/* ── Form ────────────────────────────────────────────────── */
.form-section-title{font-size:.78rem;font-weight:700;color:var(--g800);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;margin-top:4px;padding-bottom:6px;border-bottom:1px solid #e8ede9}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
.form-label{font-size:.82rem;font-weight:600;color:#444}
.form-label .req{color:var(--red)}
.form-control{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;background:#fff;transition:border-color var(--tr),box-shadow var(--tr);width:100%}
.form-control:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.form-control.error{border-color:var(--red)}
.form-hint{font-size:.75rem;color:#888}

/* ── Confirm dialog ──────────────────────────────────────── */
.dialog{display:none;position:fixed;inset:0;z-index:1100;background:rgba(0,0,0,.45);backdrop-filter:blur(3px)}
.dialog.active{display:flex;align-items:center;justify-content:center;padding:20px}
.dialog-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:400px;box-shadow:var(--shadow-lg);overflow:hidden;animation:slideDown .2s ease}
.dialog-head{padding:18px 22px;display:flex;align-items:center;gap:10px;background:var(--red-bg)}
.dialog-head i{font-size:1.3rem;color:var(--red)}
.dialog-head span{font-weight:700;font-size:1rem}
.dialog-body{padding:16px 22px;font-size:.9rem;color:#444;line-height:1.5}
.dialog-actions{padding:14px 22px 18px;display:flex;justify-content:flex-end;gap:8px}

/* ── Notifications ───────────────────────────────────────── */
#notif-stack{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.notif{display:flex;align-items:flex-start;gap:12px;padding:13px 16px;border-radius:var(--radius);box-shadow:0 4px 16px rgba(0,0,0,.18);min-width:280px;max-width:360px;pointer-events:auto;animation:slideNotif .3s ease}
@keyframes slideNotif{from{transform:translateX(30px);opacity:0}to{transform:translateX(0);opacity:1}}
.notif.success{background:#1b5e20;color:#fff}
.notif.error{background:#b71c1c;color:#fff}
.notif.warning{background:#e65100;color:#fff}
.notif.info{background:#1565c0;color:#fff}
.notif-icon{font-size:1rem;flex-shrink:0;margin-top:1px}
.notif-body{flex:1}
.notif-title{font-weight:700;font-size:.85rem}
.notif-msg{font-size:.8rem;opacity:.88;margin-top:2px}
.notif-close{background:none;border:none;color:inherit;opacity:.7;cursor:pointer;font-size:.9rem;padding:0}
.notif-close:hover{opacity:1}

@media(max-width:680px){.form-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php require_once 'nav.php'; ?>

<div class="page">

  <!-- ── Alerts ─────────────────────────────────────────── -->
  <div class="alert-banner danger" id="alertExpired" style="<?= (int)$stats['expired'] > 0 ? '' : 'display:none' ?>">
    <i class="fas fa-skull-crossbones"></i>
    <span id="alertExpiredText"><?= (int)$stats['expired'] ?> chemical<?= $stats['expired'] > 1 ? 's' : '' ?> have <strong>expired</strong> and must be reviewed immediately.</span>
  </div>
  <div class="alert-banner warn" id="alertLowStock" style="<?= (int)$stats['low_stock'] > 0 ? '' : 'display:none' ?>">
    <i class="fas fa-exclamation-triangle"></i>
    <span id="alertLowStockText"><?= (int)$stats['low_stock'] ?> chemical<?= $stats['low_stock'] > 1 ? 's' : '' ?> are at or below minimum stock level.</span>
  </div>
  <div class="alert-banner warn" id="alertExpiringSoon" style="<?= (int)$stats['expiring_soon'] > 0 ? '' : 'display:none' ?>">
    <i class="fas fa-clock"></i>
    <span id="alertExpiringSoonText"><?= (int)$stats['expiring_soon'] ?> chemical<?= $stats['expiring_soon'] > 1 ? 's' : '' ?> will expire within 30 days.</span>
  </div>

  <!-- ── Page Header ────────────────────────────────────── -->
  <div class="page-header">
    <div class="hdr-left">
      <h1><i class="fas fa-flask"></i> Chemical Inventory</h1>
      <p>Safety Data &amp; Stock Management</p>
    </div>
    <div class="hdr-right">
      <div class="stat-pill">
        <span class="n" id="statTotal"><?= (int)$stats['total'] ?></span>
        <span class="l">Chemicals</span>
      </div>
      <div class="stat-pill warn">
        <span class="n" id="statLowStock"><?= (int)$stats['low_stock'] ?></span>
        <span class="l">Low stock</span>
      </div>
      <div class="stat-pill warn">
        <span class="n" id="statExpired"><?= (int)$stats['expired'] ?></span>
        <span class="l">Expired</span>
      </div>
    </div>
  </div>

  <!-- ── Main Card ──────────────────────────────────────── -->
  <div class="card">
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search by name, CAS, supplier…"
                 oninput="debounce(loadChemicals, 350)()">
        </div>
        <select class="filter-select" id="hazardFilter" onchange="loadChemicals()">
          <option value="">All hazard levels</option>
          <option value="low">Low</option>
          <option value="medium">Medium</option>
          <option value="high">High</option>
          <option value="extreme">Extreme</option>
        </select>
        <select class="filter-select" id="locationFilter" onchange="loadChemicals()">
          <option value="">All locations</option>
          <option value="cabinet_a">Cabinet A</option>
          <option value="cabinet_b">Cabinet B</option>
          <option value="cabinet_c">Cabinet C</option>
          <option value="fume_hood">Fume Hood</option>
          <option value="cold_storage">Cold Storage</option>
          <option value="flammables_cabinet">Flammables Cabinet</option>
          <option value="acid_cabinet">Acid Cabinet</option>
          <option value="general_storage">General Storage</option>
        </select>
        <span class="result-count" id="resultCount"></span>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-sm btn-outline btn-pdf" onclick="exportPDF()">
          <i class="fas fa-file-pdf"></i> PDF
        </button>
        <button class="btn btn-sm btn-excel" onclick="exportExcel()">
          <i class="fas fa-file-excel"></i> Excel
        </button>
        <button class="btn btn-primary btn-sm" onclick="openModal()">
          <i class="fas fa-plus"></i> Add Chemical
        </button>
      </div>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th onclick="toggleSort('name')">Name / Formula <i class="fas fa-sort" id="sort-name" style="opacity:.4;font-size:.7rem"></i></th>
            <th>CAS</th>
            <th onclick="toggleSort('quantity')">Quantity <i class="fas fa-sort" id="sort-quantity" style="opacity:.4;font-size:.7rem"></i></th>
            <th>Hazard</th>
            <th>Location</th>
            <th>Expiration</th>
            <th>Supplier</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="chemTableBody">
          <?php for ($i = 0; $i < 8; $i++): ?>
            <tr><td><span class="skeleton"></span></td><td><span class="skeleton" style="width:60%"></span></td><td><span class="skeleton" style="width:50%"></span></td><td><span class="skeleton" style="width:55%"></span></td><td><span class="skeleton" style="width:65%"></span></td><td><span class="skeleton" style="width:55%"></span></td><td><span class="skeleton" style="width:70%"></span></td><td><span class="skeleton" style="width:70px;height:20px"></span></td></tr>
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

<!-- ── Chemical Modal ─────────────────────────────────────── -->
<div class="modal" id="chemModal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-flask"></i> <span id="modalTitle">Add Chemical</span></h2>
      <button class="modal-close" onclick="closeModal()">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="fId">

      <div class="form-section-title">Identification</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Name <span class="req">*</span></label>
          <input type="text" class="form-control" id="fName" maxlength="200" placeholder="e.g., Sodium Chloride">
        </div>
        <div class="form-group">
          <label class="form-label">CAS Number</label>
          <input type="text" class="form-control" id="fCas" placeholder="e.g., 7647-14-5">
          <span class="form-hint">Format: 1234567-89-0</span>
        </div>
        <div class="form-group">
          <label class="form-label">Chemical Formula</label>
          <input type="text" class="form-control" id="fFormula" placeholder="e.g., NaCl">
        </div>
        <div class="form-group">
          <label class="form-label">Supplier</label>
          <input type="text" class="form-control" id="fSupplier" placeholder="Supplier name">
        </div>
        <div class="form-group">
          <label class="form-label">Batch Number</label>
          <input type="text" class="form-control" id="fBatch" placeholder="e.g., BN-2024-001">
        </div>
        <div class="form-group">
          <label class="form-label">SDS URL</label>
          <input type="url" class="form-control" id="fSds" placeholder="https://…">
        </div>
      </div>

      <div class="form-section-title">Stock &amp; Storage</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Quantity <span class="req">*</span></label>
          <input type="number" class="form-control" id="fQty" min="0" step="0.001" placeholder="0">
        </div>
        <div class="form-group">
          <label class="form-label">Unit <span class="req">*</span></label>
          <select class="form-control" id="fUnit">
            <option value="">Select unit</option>
            <option value="g">g (grams)</option>
            <option value="kg">kg (kilograms)</option>
            <option value="mg">mg (milligrams)</option>
            <option value="ml">mL (millilitres)</option>
            <option value="L">L (litres)</option>
            <option value="mol">mol</option>
            <option value="mmol">mmol</option>
            <option value="units">units</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Minimum Stock Level</label>
          <input type="number" class="form-control" id="fMinStock" min="0" step="0.001" placeholder="0">
        </div>
        <div class="form-group">
          <label class="form-label">Hazard Level <span class="req">*</span></label>
          <select class="form-control" id="fHazard">
            <option value="">Select hazard level</option>
            <option value="low">Low</option>
            <option value="medium">Medium</option>
            <option value="high">High</option>
            <option value="extreme">Extreme</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Storage Location <span class="req">*</span></label>
          <select class="form-control" id="fLocation">
            <option value="">Select location</option>
            <option value="cabinet_a">Cabinet A</option>
            <option value="cabinet_b">Cabinet B</option>
            <option value="cabinet_c">Cabinet C</option>
            <option value="fume_hood">Fume Hood</option>
            <option value="cold_storage">Cold Storage</option>
            <option value="flammables_cabinet">Flammables Cabinet</option>
            <option value="acid_cabinet">Acid Cabinet</option>
            <option value="general_storage">General Storage</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Purchase Date</label>
          <input type="date" class="form-control" id="fPurchase">
        </div>
        <div class="form-group">
          <label class="form-label">Expiration Date</label>
          <input type="date" class="form-control" id="fExpiry">
        </div>
      </div>

      <div class="form-section-title" style="margin-top:4px">Additional Notes</div>
      <div class="form-grid">
        <div class="form-group full">
          <textarea class="form-control" id="fNotes" rows="3" maxlength="1000"
                    placeholder="Special handling instructions, storage conditions…"></textarea>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
      <button class="btn btn-danger" id="deleteBtnModal" style="display:none" onclick="promptDelete()">
        <i class="fas fa-trash"></i> Delete
      </button>
      <button class="btn btn-primary" id="saveBtn" onclick="saveChemical()">
        <i class="fas fa-save"></i> Save Chemical
      </button>
    </div>
  </div>
</div>

<!-- ── Confirm dialog ─────────────────────────────────────── -->
<div class="dialog" id="confirmDlg" onclick="if(event.target===this)closeDlg()">
  <div class="dialog-box">
    <div class="dialog-head"><i class="fas fa-trash-alt"></i><span>Delete Chemical</span></div>
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
const THIS_URL = 'lab_chemicals.php';

let chemicals   = [];
let currentPage = 1;
let totalRows   = 0;
let sortField   = 'name';
let sortDir     = 'asc';
let pendingDelId = 0;
const PER_PAGE  = 25;

/* ── Init ──────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', loadChemicals);

/* ── Stats refresh ─────────────────────────────────────── */
async function refreshStats() {
    const fd = new FormData();
    fd.append('action', 'get_stats');
    try {
        const res  = await fetch(THIS_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) return;
        const s = data.stats;
        document.getElementById('statTotal').textContent    = s.total;
        document.getElementById('statLowStock').textContent = s.low_stock;
        document.getElementById('statExpired').textContent  = s.expired;

        const setAlert = (id, textId, count, text) => {
            const el = document.getElementById(id);
            el.style.display = count > 0 ? '' : 'none';
            if (count > 0) document.getElementById(textId).innerHTML = text;
        };
        const n = v => parseInt(v) || 0;
        setAlert('alertExpired',     'alertExpiredText',     n(s.expired),
            `${s.expired} chemical${n(s.expired)>1?'s':''} have <strong>expired</strong> and must be reviewed immediately.`);
        setAlert('alertLowStock',    'alertLowStockText',    n(s.low_stock),
            `${s.low_stock} chemical${n(s.low_stock)>1?'s':''} are at or below minimum stock level.`);
        setAlert('alertExpiringSoon','alertExpiringSoonText', n(s.expiring_soon),
            `${s.expiring_soon} chemical${n(s.expiring_soon)>1?'s':''} will expire within 30 days.`);
    } catch(e) { /* non-critical */ }
}

/* ── Load ─────────────────────────────────────────────── */
async function loadChemicals(page = 1, silent = false) {
    currentPage = page;
    if (!silent) {
        const skRow = `<tr>${Array(8).fill('<td><span class="skeleton"></span></td>').join('')}</tr>`;
        document.getElementById('chemTableBody').innerHTML = Array(6).fill(skRow).join('');
        document.getElementById('paginationBar').style.display = 'none';
    }

    const fd = new FormData();
    fd.append('action', 'list');
    fd.append('page',   page);
    fd.append('search', document.getElementById('searchInput').value.trim());
    fd.append('hazard', document.getElementById('hazardFilter').value);
    fd.append('location', document.getElementById('locationFilter').value);

    try {
        const res  = await fetch(THIS_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) { notify('Error', data.error, 'error'); return; }
        chemicals = data.chemicals;
        totalRows = data.total;
        renderTable();
        renderPagination();
        document.getElementById('resultCount').textContent =
            `${data.total} chemical${data.total !== 1 ? 's' : ''}`;
    } catch(e) {
        notify('Error', 'Failed to load chemicals.', 'error');
    }
}

/* ── Render table ─────────────────────────────────────── */
function renderTable() {
    const tbody = document.getElementById('chemTableBody');
    if (!chemicals.length) {
        tbody.innerHTML = `<tr><td colspan="8"><div class="empty-state">
            <i class="fas fa-flask"></i><p>No chemicals found.</p></div></td></tr>`;
        return;
    }
    tbody.innerHTML = chemicals.map(c => {
        const qtyPct = c.minimum_stock > 0
            ? Math.min(100, (c.quantity / c.minimum_stock) * 100)
            : 100;
        const qtyClass = qtyPct <= 25 ? 'qty-crit' : qtyPct <= 60 ? 'qty-low' : 'qty-ok';
        const expHtml  = expiryBadge(c.expiration_date);
        const sdsBtn   = c.sds_url
            ? `<button class="btn-icon bi-link" title="Safety Data Sheet" onclick="window.open('${esc(c.sds_url)}','_blank')"><i class="fas fa-external-link-alt"></i></button>`
            : '';
        return `<tr>
          <td>
            <div style="font-weight:600">${esc(c.name)}</div>
            ${c.formula ? `<div style="font-size:.78rem;color:#888">${esc(c.formula)}</div>` : ''}
          </td>
          <td style="font-size:.82rem;color:#666">${esc(c.cas || '—')}</td>
          <td>
            <div class="qty-wrap">
              <span style="font-weight:600">${parseFloat(c.quantity).toLocaleString('en-UG',{maximumFractionDigits:3})} ${esc(c.unit)}</span>
              <div class="qty-bar"><div class="qty-fill ${qtyClass}" style="width:${qtyPct}%"></div></div>
            </div>
            ${parseFloat(c.minimum_stock) > 0 ? `<div style="font-size:.72rem;color:#888">Min: ${c.minimum_stock} ${esc(c.unit)}</div>` : ''}
          </td>
          <td><span class="badge badge-${c.hazard_level}">${ucf(c.hazard_level)}</span></td>
          <td style="font-size:.82rem">${fmtLoc(c.location)}</td>
          <td>${expHtml}</td>
          <td style="font-size:.82rem">${esc(c.supplier || '—')}</td>
          <td>
            <div class="action-cell">
              <button class="btn-icon bi-edit" title="Edit" onclick='openModal(${JSON.stringify(c)})'><i class="fas fa-edit"></i></button>
              ${sdsBtn}
              <button class="btn-icon bi-delete" title="Delete" onclick="promptDelete(${c.id},'${esc(c.name)}')"><i class="fas fa-trash"></i></button>
            </div>
          </td>
        </tr>`;
    }).join('');
}

function expiryBadge(d) {
    if (!d) return '<span style="color:#bbb;font-size:.8rem">—</span>';
    const diff = Math.floor((new Date(d) - new Date()) / 86400000);
    if (diff < 0)   return `<span class="expiry-exp"><i class="fas fa-times-circle"></i> Expired</span>`;
    if (diff <= 30) return `<span class="expiry-warn"><i class="fas fa-exclamation-circle"></i> ${fmtDate(d)}</span>`;
    return `<span class="expiry-ok">${fmtDate(d)}</span>`;
}

/* ── Pagination ─────────────────────────────────────────── */
function renderPagination() {
    const bar  = document.getElementById('paginationBar');
    const info = document.getElementById('pageInfo');
    const btns = document.getElementById('pageBtns');
    const total = totalRows;
    const pages = Math.ceil(total / PER_PAGE);

    if (pages <= 1) { bar.style.display = 'none'; return; }
    bar.style.display = 'flex';

    const from = (currentPage - 1) * PER_PAGE + 1;
    const to   = Math.min(currentPage * PER_PAGE, total);
    info.textContent = `Showing ${from}–${to} of ${total}`;

    let html = `<button class="page-btn" ${currentPage===1?'disabled':''} onclick="loadChemicals(${currentPage-1})"><i class="fas fa-chevron-left"></i></button>`;
    for (let p = 1; p <= pages; p++) {
        if (pages > 7 && Math.abs(p - currentPage) > 2 && p !== 1 && p !== pages) {
            if (p === 2 || p === pages - 1) html += `<button class="page-btn" disabled>…</button>`;
            continue;
        }
        html += `<button class="page-btn ${p===currentPage?'active':''}" onclick="loadChemicals(${p})">${p}</button>`;
    }
    html += `<button class="page-btn" ${currentPage===pages?'disabled':''} onclick="loadChemicals(${currentPage+1})"><i class="fas fa-chevron-right"></i></button>`;
    btns.innerHTML = html;
}

/* ── Sort ────────────────────────────────────────────────── */
function toggleSort(field) {
    sortDir = (sortField === field && sortDir === 'asc') ? 'desc' : 'asc';
    sortField = field;
    chemicals.sort((a, b) => {
        const va = String(a[field] || '');
        const vb = String(b[field] || '');
        return sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
    });
    renderTable();
}

/* ── Modal ───────────────────────────────────────────────── */
function openModal(chem = null) {
    clearForm();
    if (chem) {
        document.getElementById('modalTitle').textContent = 'Edit Chemical';
        document.getElementById('fId').value       = chem.id;
        document.getElementById('fName').value     = chem.name;
        document.getElementById('fCas').value      = chem.cas || '';
        document.getElementById('fFormula').value  = chem.formula || '';
        document.getElementById('fSupplier').value = chem.supplier || '';
        document.getElementById('fQty').value      = chem.quantity;
        document.getElementById('fUnit').value     = chem.unit;
        document.getElementById('fMinStock').value = chem.minimum_stock || '';
        document.getElementById('fHazard').value   = chem.hazard_level;
        document.getElementById('fLocation').value = chem.location;
        document.getElementById('fPurchase').value = chem.purchase_date || '';
        document.getElementById('fExpiry').value   = chem.expiration_date || '';
        document.getElementById('fBatch').value    = chem.batch_number || '';
        document.getElementById('fSds').value      = chem.sds_url || '';
        document.getElementById('fNotes').value    = chem.notes || '';
        document.getElementById('deleteBtnModal').style.display = '';
    } else {
        document.getElementById('modalTitle').textContent = 'Add Chemical';
        document.getElementById('deleteBtnModal').style.display = 'none';
    }
    document.getElementById('chemModal').classList.add('active');
}
function closeModal() {
    document.getElementById('chemModal').classList.remove('active');
}
function clearForm() {
    ['fId','fName','fCas','fFormula','fSupplier','fQty','fUnit','fMinStock',
     'fHazard','fLocation','fPurchase','fExpiry','fBatch','fSds','fNotes']
     .forEach(id => { const el = document.getElementById(id); el.value = ''; el.classList.remove('error'); });
}

/* ── Save ────────────────────────────────────────────────── */
async function saveChemical() {
    const id = document.getElementById('fId').value;
    const fd = new FormData();
    fd.append('action', id ? 'update' : 'add');
    fd.append('csrf_token',  CSRF);
    if (id) fd.append('id', id);
    fd.append('name',          document.getElementById('fName').value.trim());
    fd.append('cas',           document.getElementById('fCas').value.trim());
    fd.append('formula',       document.getElementById('fFormula').value.trim());
    fd.append('supplier',      document.getElementById('fSupplier').value.trim());
    fd.append('quantity',      document.getElementById('fQty').value || '0');
    fd.append('unit',          document.getElementById('fUnit').value);
    fd.append('minimum_stock', document.getElementById('fMinStock').value || '0');
    fd.append('hazard_level',  document.getElementById('fHazard').value);
    fd.append('location',      document.getElementById('fLocation').value);
    fd.append('purchase_date', document.getElementById('fPurchase').value);
    fd.append('expiration_date', document.getElementById('fExpiry').value);
    fd.append('batch_number',  document.getElementById('fBatch').value.trim());
    fd.append('sds_url',       document.getElementById('fSds').value.trim());
    fd.append('notes',         document.getElementById('fNotes').value.trim());

    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    try {
        const res  = await fetch(THIS_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            notify('Saved', data.message, 'success');
            closeModal();
            loadChemicals(currentPage, true);
            refreshStats();
        } else {
            notify('Error', data.error, 'error');
        }
    } catch(e) {
        notify('Error', 'Network error. Please try again.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Chemical';
    }
}

/* ── Delete ──────────────────────────────────────────────── */
function promptDelete(id, name) {
    pendingDelId = id;
    document.getElementById('dlgBody').innerHTML =
        `Delete <strong>${esc(String(name || ''))}</strong>? Any withdrawal records referencing this chemical will be affected. This cannot be undone.`;
    document.getElementById('confirmDlg').classList.add('active');
}
function closeDlg() {
    document.getElementById('confirmDlg').classList.remove('active');
    pendingDelId = 0;
}
async function doDelete() {
    if (!pendingDelId) return;
    const idToDelete = pendingDelId;
    closeDlg();
    closeModal();
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('csrf_token', CSRF);
    fd.append('id', idToDelete);
    try {
        const res  = await fetch(THIS_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            notify('Deleted', data.message, 'success');
            loadChemicals(currentPage, true);
            refreshStats();
        } else {
            notify('Error', data.error, 'error');
        }
    } catch(e) {
        notify('Error', 'Network error.', 'error');
    }
}
/* ── Exports ─────────────────────────────────────────────── */
function exportExcel() {
    if (!chemicals.length) { notify('Export', 'No data to export.', 'warning'); return; }
    const data = [['Name','Formula','CAS','Quantity','Unit','Hazard Level','Location','Expiration','Supplier','Batch','Min Stock','SDS URL','Notes']];
    chemicals.forEach(c => data.push([c.name,c.formula,c.cas,c.quantity,c.unit,c.hazard_level,fmtLoc(c.location),c.expiration_date||'',c.supplier||'',c.batch_number||'',c.minimum_stock||'',c.sds_url||'',c.notes||'']));
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [{wch:25},{wch:10},{wch:14},{wch:10},{wch:8},{wch:12},{wch:18},{wch:13},{wch:20},{wch:14},{wch:12},{wch:30},{wch:30}];
    XLSX.utils.book_append_sheet(wb, ws, 'Chemicals');
    XLSX.writeFile(wb, `chemicals-${stamp()}.xlsx`);
    notify('Exported', 'Excel file downloaded.', 'success');
}
function exportPDF() {
    if (typeof window.jspdf === 'undefined') { notify('PDF', 'PDF library loading…', 'warning'); return; }
    if (!chemicals.length) { notify('PDF', 'No data to export.', 'warning'); return; }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');
    doc.setFontSize(16); doc.setTextColor(27,94,32);
    doc.text('SchoolPilot — Chemical Inventory', 14, 16);
    doc.setFontSize(9); doc.setTextColor(100);
    doc.text(`Generated: ${new Date().toLocaleDateString('en-UG')}`, 14, 23);
    doc.autoTable({
        head:[['Name','CAS','Qty','Unit','Hazard','Location','Expiry','Supplier']],
        body: chemicals.map(c=>[c.name,c.cas||'—',c.quantity,c.unit,ucf(c.hazard_level),fmtLoc(c.location),c.expiration_date||'—',c.supplier||'—']),
        startY:28,theme:'grid',
        headStyles:{fillColor:[56,142,60],fontSize:8,fontStyle:'bold'},
        bodyStyles:{fontSize:7.5},
        alternateRowStyles:{fillColor:[232,245,233]},
        margin:{left:10,right:10}
    });
    doc.save(`chemicals-${stamp()}.pdf`);
    notify('Exported', 'PDF downloaded.', 'success');
}

/* ── Notifications ───────────────────────────────────────── */
function notify(title, msg, type='success', dur=4500) {
    const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
    const n=document.createElement('div');
    n.className=`notif ${type}`;
    n.innerHTML=`<i class="fas ${icons[type]} notif-icon"></i>
      <div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div>
      <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('notif-stack').prepend(n);
    setTimeout(()=>{n.style.opacity='0';n.style.transform='translateX(30px)';n.style.transition='.3s';setTimeout(()=>n.remove(),320);},dur);
}

/* ── Utilities ───────────────────────────────────────────── */
function esc(v){return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function ucf(v){return v?v.charAt(0).toUpperCase()+v.slice(1):'';}
function stamp(){return new Date().toISOString().slice(0,10);}
function fmtDate(d){if(!d)return'—';try{return new Date(d+'T00:00:00').toLocaleDateString('en-UG',{day:'2-digit',month:'short',year:'numeric'});}catch(_){return d;}}
function fmtLoc(v){const map={'cabinet_a':'Cabinet A','cabinet_b':'Cabinet B','cabinet_c':'Cabinet C','fume_hood':'Fume Hood','cold_storage':'Cold Storage','flammables_cabinet':'Flammables Cabinet','acid_cabinet':'Acid Cabinet','general_storage':'General Storage'};return map[v]||v||'—';}

const debounce=(fn,ms)=>{let t;return(...a)=>{clearTimeout(t);t=setTimeout(()=>fn(...a),ms);};};

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeModal(); closeDlg(); }
});
</script>
</body>
</html>
