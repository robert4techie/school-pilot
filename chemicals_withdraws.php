<?php
/**
 * chemicals_withdraws.php — Chemical Withdrawal Management
 * SchoolPilot · Production build
 *
 * Security:  CSRF on all mutations · prepared statements only ·
 *            whitelist validation · errors logged server-side ·
 *            overdue check done server-side on query (not client-side).
 *
 * DB tables: chemicals(id,name,cas,unit,quantity,hazard_level,minimum_stock)
 *            chemical_withdrawals(id,chemical_id,withdrawn_by,department,
 *              purpose,quantity_withdrawn,withdrawal_date,expected_return_date,
 *              actual_return_date,quantity_returned,status,notes,
 *              created_at,updated_at)
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
const WD_STATUSES = ['withdrawn', 'returned', 'overdue'];
const WD_DEPTS    = [
    'biology','chemistry','physics','computer_science',
    'geography','general','administration','other'
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
    if ($d === '') return false;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $day] = explode('-', $d);
    return checkdate((int)$m, (int)$day, (int)$y);
}

/* ── AJAX dispatcher ────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    switch ($_POST['action']) {
        case 'add':              csrfGuard(); handleAdd();              break;
        case 'update':           csrfGuard(); handleUpdate();           break;
        case 'delete':           csrfGuard(); handleDelete();           break;
        case 'list':                          handleList();             break;
        case 'get_chemicals':                 handleGetChemicals();     break;
        case 'get_stats':                     handleGetStats();         break;
        default: jsonErr('Unknown action.');
    }
}

/* ── AJAX: list withdrawals ─────────────────────────────── */
function handleList(): void
{
    global $conn;
    $page    = max(1, (int)($_POST['page'] ?? 1));
    $perPage = 25;
    $offset  = ($page - 1) * $perPage;
    $search  = trim($_POST['search'] ?? '');
    $status  = $_POST['status'] ?? '';
    $dept    = $_POST['department'] ?? '';

    if ($status !== '' && !in_array($status, WD_STATUSES, true)) $status = '';
    if ($dept   !== '' && !in_array($dept,   WD_DEPTS,    true)) $dept   = '';

    $conds  = [];
    $params = [];
    $types  = '';

    if ($search !== '') {
        $like = '%' . $search . '%';
        $conds[]  = '(w.withdrawn_by LIKE ? OR c.name LIKE ? OR w.purpose LIKE ?)';
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types   .= 'sss';
    }
    if ($status !== '') { $conds[] = 'w.status=?';     $params[] = $status; $types .= 's'; }
    if ($dept   !== '') { $conds[] = 'w.department=?'; $params[] = $dept;   $types .= 's'; }

    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

    // Count
    $cStmt = mysqli_prepare($conn,
        "SELECT COUNT(*) FROM chemical_withdrawals w JOIN chemicals c ON c.id=w.chemical_id $where"
    );
    if ($params) mysqli_stmt_bind_param($cStmt, $types, ...$params);
    mysqli_stmt_execute($cStmt);
    $total = (int)mysqli_fetch_row(mysqli_stmt_get_result($cStmt))[0];
    mysqli_stmt_close($cStmt);

    // Data — server computes overdue status inline
    $dStmt = mysqli_prepare($conn,
        "SELECT w.id, w.chemical_id, c.name AS chemical_name, c.cas, c.unit,
                c.hazard_level, w.withdrawn_by, w.department, w.purpose,
                w.quantity_withdrawn, w.withdrawal_date, w.expected_return_date,
                w.actual_return_date, w.quantity_returned, w.notes,
                IF(w.status='withdrawn' AND w.expected_return_date IS NOT NULL
                   AND w.expected_return_date < CURDATE(), 'overdue', w.status) AS status,
                w.created_at
         FROM chemical_withdrawals w
         JOIN chemicals c ON c.id = w.chemical_id
         $where
         ORDER BY w.withdrawal_date DESC, w.id DESC
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

    jsonOk(['withdrawals' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
}

/* ── AJAX: get available chemicals ─────────────────────── */
function handleGetChemicals(): void
{
    global $conn;
    $stmt = mysqli_prepare($conn,
        "SELECT id, name, cas, unit, quantity, hazard_level, minimum_stock
         FROM chemicals
         WHERE quantity > 0
         ORDER BY name
         LIMIT 500"
    );
    mysqli_stmt_execute($stmt);
    $res  = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    mysqli_stmt_close($stmt);
    jsonOk(['chemicals' => $rows]);
}

/* ── AJAX: add withdrawal ───────────────────────────────── */
function handleAdd(): void
{
    global $conn;
    $chemId  = max(0, (int)($_POST['chemical_id'] ?? 0));
    $by      = trim($_POST['withdrawn_by'] ?? '');
    $dept    = trim($_POST['department']   ?? '');
    $purpose = trim($_POST['purpose']      ?? '');
    $qty     = (float)($_POST['quantity_withdrawn'] ?? 0);
    $wDate   = trim($_POST['withdrawal_date']       ?? '');
    $retDate = trim($_POST['expected_return_date']  ?? '');
    $notes   = trim($_POST['notes']                 ?? '');

    if (!$chemId)                                   jsonErr('Please select a chemical.');
    if ($by === '')                                  jsonErr('Withdrawn by is required.');
    if (mb_strlen($by) > 150)                        jsonErr('Name too long.');
    if (!in_array($dept, WD_DEPTS, true))            jsonErr('Invalid department.');
    if ($purpose === '')                             jsonErr('Purpose is required.');
    if (mb_strlen($purpose) > 255)                   jsonErr('Purpose too long.');
    if ($qty <= 0)                                   jsonErr('Quantity must be greater than zero.');
    if (!validateDate($wDate))                       jsonErr('Invalid withdrawal date.');
    if ($retDate !== '' && !validateDate($retDate))  jsonErr('Invalid expected return date.');
    if ($retDate !== '' && $retDate < $wDate)        jsonErr('Return date cannot be before withdrawal date.');

    // Check stock (prepared)
    $chkStmt = mysqli_prepare($conn, "SELECT name, unit, quantity FROM chemicals WHERE id=? FOR UPDATE");
    mysqli_begin_transaction($conn);
    try {
        mysqli_stmt_bind_param($chkStmt, 'i', $chemId);
        mysqli_stmt_execute($chkStmt);
        $chem = mysqli_fetch_assoc(mysqli_stmt_get_result($chkStmt));
        mysqli_stmt_close($chkStmt);

        if (!$chem) { mysqli_rollback($conn); jsonErr('Chemical not found.'); }
        if ((float)$chem['quantity'] < $qty) {
            mysqli_rollback($conn);
            jsonErr("Insufficient stock. Available: {$chem['quantity']} {$chem['unit']}");
        }

        // Insert withdrawal
        $ins = mysqli_prepare($conn,
            "INSERT INTO chemical_withdrawals
             (chemical_id,withdrawn_by,department,purpose,quantity_withdrawn,
              withdrawal_date,expected_return_date,status,notes,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,'withdrawn',?,NOW(),NOW())"
        );
        $retDateVal = $retDate ?: null;
        mysqli_stmt_bind_param($ins, 'isssdsss',
            $chemId,$by,$dept,$purpose,$qty,$wDate,$retDateVal,$notes
        );
        if (!mysqli_stmt_execute($ins)) {
            throw new RuntimeException(mysqli_stmt_error($ins));
        }
        mysqli_stmt_close($ins);

        // Deduct stock
        $upd = mysqli_prepare($conn, "UPDATE chemicals SET quantity=quantity-?, updated_at=NOW() WHERE id=?");
        mysqli_stmt_bind_param($upd, 'di', $qty, $chemId);
        if (!mysqli_stmt_execute($upd)) {
            throw new RuntimeException(mysqli_stmt_error($upd));
        }
        mysqli_stmt_close($upd);

        mysqli_commit($conn);
        jsonOk(['message' => 'Withdrawal recorded successfully.']);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('[ChemWithdraw] add: ' . $e->getMessage());
        jsonErr('Failed to record withdrawal. Please try again.');
    }
}

/* ── AJAX: update withdrawal ────────────────────────────── */
function handleUpdate(): void
{
    global $conn;

    $id = max(0, (int)($_POST['id'] ?? 0));
    if (!$id) jsonErr('Invalid withdrawal ID.');

    $chemId  = max(0, (int)($_POST['chemical_id'] ?? 0));
    $by      = trim($_POST['withdrawn_by'] ?? '');
    $dept    = trim($_POST['department']   ?? '');
    $purpose = trim($_POST['purpose']      ?? '');
    $qty     = (float)($_POST['quantity_withdrawn']  ?? 0);
    $wDate   = trim($_POST['withdrawal_date']        ?? '');
    $retDate = trim($_POST['expected_return_date']   ?? '');
    $status  = trim($_POST['status']                 ?? '');
    $actDate = trim($_POST['actual_return_date']     ?? '');
    $qtyRet  = (float)($_POST['quantity_returned']   ?? 0);
    $notes   = trim($_POST['notes']                  ?? '');

    if (!$chemId)                                   jsonErr('Please select a chemical.');
    if ($by === '')                                  jsonErr('Withdrawn by is required.');
    if (mb_strlen($by) > 150)                        jsonErr('Name too long.');
    if (!in_array($dept, WD_DEPTS, true))            jsonErr('Invalid department.');
    if ($purpose === '')                             jsonErr('Purpose is required.');
    if (mb_strlen($purpose) > 255)                   jsonErr('Purpose too long.');
    if ($qty <= 0)                                   jsonErr('Quantity must be greater than zero.');
    if (!validateDate($wDate))                       jsonErr('Invalid withdrawal date.');
    if ($retDate !== '' && !validateDate($retDate))  jsonErr('Invalid expected return date.');
    if (!in_array($status, WD_STATUSES, true))       jsonErr('Invalid status.');

    $retDateVal = $retDate ?: null;
    $actDateVal = $actDate ?: null;

    mysqli_begin_transaction($conn);
    try {
        // Lock both the withdrawal and its chemical for stock reconciliation
        $sel = mysqli_prepare($conn,
            'SELECT quantity_withdrawn FROM chemical_withdrawals WHERE id=? FOR UPDATE'
        );
        mysqli_stmt_bind_param($sel, 'i', $id);
        mysqli_stmt_execute($sel);
        $old = mysqli_fetch_assoc(mysqli_stmt_get_result($sel));
        mysqli_stmt_close($sel);
        if (!$old) { mysqli_rollback($conn); jsonErr('Withdrawal record not found.'); }

        $oldQty = (float)$old['quantity_withdrawn'];
        $delta  = $qty - $oldQty; // positive = more withdrawn; negative = less withdrawn

        // Reconcile chemical stock only if quantity changed
        if (abs($delta) > 0.000001) {
            $chk = mysqli_prepare($conn,
                'SELECT quantity FROM chemicals WHERE id=? FOR UPDATE'
            );
            mysqli_stmt_bind_param($chk, 'i', $chemId);
            mysqli_stmt_execute($chk);
            $chem = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
            mysqli_stmt_close($chk);

            if (!$chem) { mysqli_rollback($conn); jsonErr('Chemical not found.'); }
            // If increasing withdrawal quantity, ensure sufficient stock
            if ($delta > 0 && (float)$chem['quantity'] < $delta) {
                mysqli_rollback($conn);
                jsonErr('Insufficient stock to increase quantity. Available: ' . $chem['quantity']);
            }

            $upd = mysqli_prepare($conn,
                'UPDATE chemicals SET quantity=quantity-?,updated_at=NOW() WHERE id=?'
            );
            mysqli_stmt_bind_param($upd, 'di', $delta, $chemId);
            if (!mysqli_stmt_execute($upd)) throw new RuntimeException(mysqli_stmt_error($upd));
            mysqli_stmt_close($upd);
        }

        $stmt = mysqli_prepare($conn,
            'UPDATE chemical_withdrawals SET
             chemical_id=?,withdrawn_by=?,department=?,purpose=?,
             quantity_withdrawn=?,withdrawal_date=?,expected_return_date=?,
             actual_return_date=?,quantity_returned=?,status=?,notes=?,
             updated_at=NOW()
             WHERE id=?'
        );
        mysqli_stmt_bind_param($stmt, 'isssdsssdssi',
            $chemId, $by, $dept, $purpose, $qty, $wDate,
            $retDateVal, $actDateVal, $qtyRet, $status, $notes, $id
        );
        if (!mysqli_stmt_execute($stmt)) throw new RuntimeException(mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);

        mysqli_commit($conn);
        jsonOk(['message' => 'Withdrawal updated successfully.']);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('[ChemWithdraw] update: ' . $e->getMessage());
        jsonErr('Failed to update withdrawal record. Please try again.');
    }
}

/* ── AJAX: delete withdrawal ────────────────────────────── */
function handleDelete(): void
{
    global $conn;
    $id = max(0, (int)($_POST['id'] ?? 0));
    if (!$id) jsonErr('Invalid record ID.');

    mysqli_begin_transaction($conn);
    try {
        $sel = mysqli_prepare($conn,
            "SELECT chemical_id, quantity_withdrawn, status FROM chemical_withdrawals WHERE id=? FOR UPDATE"
        );
        mysqli_stmt_bind_param($sel, 'i', $id);
        mysqli_stmt_execute($sel);
        $wd = mysqli_fetch_assoc(mysqli_stmt_get_result($sel));
        mysqli_stmt_close($sel);

        if (!$wd) { mysqli_rollback($conn); jsonErr('Record not found.'); }

        // Restore stock only if not returned (returned already restored stock on update)
        if ($wd['status'] !== 'returned') {
            $rst = mysqli_prepare($conn, "UPDATE chemicals SET quantity=quantity+?,updated_at=NOW() WHERE id=?");
            mysqli_stmt_bind_param($rst,'di',$wd['quantity_withdrawn'],$wd['chemical_id']);
            if (!mysqli_stmt_execute($rst)) throw new RuntimeException(mysqli_stmt_error($rst));
            mysqli_stmt_close($rst);
        }

        $del = mysqli_prepare($conn, "DELETE FROM chemical_withdrawals WHERE id=?");
        mysqli_stmt_bind_param($del, 'i', $id);
        if (!mysqli_stmt_execute($del)) throw new RuntimeException(mysqli_stmt_error($del));
        mysqli_stmt_close($del);

        mysqli_commit($conn);
        jsonOk(['message' => 'Withdrawal deleted and stock restored.']);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('[ChemWithdraw] delete: ' . $e->getMessage());
        jsonErr('Failed to delete withdrawal. Please try again.');
    }
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
           SUM(status='withdrawn') AS active,
           SUM(status='returned')  AS returned,
           SUM(status='withdrawn' AND expected_return_date IS NOT NULL
               AND expected_return_date < CURDATE()) AS overdue
         FROM chemical_withdrawals"
    );
    return mysqli_fetch_assoc($r) ?: ['total'=>0,'active'=>0,'returned'=>0,'overdue'=>0];
}

$stats = getStats($conn);
$tracker->trackAction('Chemical Withdrawals');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Chemical Withdrawals &mdash; SchoolPilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
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
.alert-banner.danger{background:var(--red-bg);color:var(--red);border:1px solid #ef9a9a}
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
.btn-pdf{background:#c62828;color:#fff}.btn-pdf:hover{background:var(--red)}

.search-wrap{position:relative;min-width:220px}
.search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.85rem;pointer-events:none}
.search-wrap input{width:100%;padding:9px 12px 9px 34px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;transition:border-color var(--tr),box-shadow var(--tr)}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.filter-select{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;background:#fff;min-width:130px;transition:border-color var(--tr)}
.filter-select:focus{outline:none;border-color:var(--g600)}
.result-count{font-size:.8rem;color:#6b7c6d;white-space:nowrap}

.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:12px 14px;text-align:left;font-size:.8rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--tr)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:12px 14px;font-size:.875rem;vertical-align:middle}
tbody tr.row-overdue{background:#fff8f1}

.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.badge-withdrawn{background:var(--amber-bg);color:var(--amber)}
.badge-returned{background:var(--g100);color:var(--g800)}
.badge-overdue{background:var(--red-bg);color:var(--red)}
.badge-low{background:var(--g100);color:var(--g800)}
.badge-medium{background:var(--orange-bg);color:var(--orange)}
.badge-high{background:var(--red-bg);color:var(--red)}
.badge-extreme{background:#4a148c;color:#fff}

.skeleton{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;height:14px;display:inline-block;width:80%}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

.empty-state{text-align:center;padding:60px 20px;color:#8a9a8b}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;opacity:.4}

.pagination{padding:14px 22px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e8ede9;flex-wrap:wrap;gap:10px}
.page-info{font-size:.82rem;color:#6b7c6d}
.page-btns{display:flex;gap:4px}
.page-btn{width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:6px;background:#fff;font-size:.82rem;font-weight:600;color:#444;display:flex;align-items:center;justify-content:center;transition:all var(--tr);cursor:pointer}
.page-btn:hover:not(:disabled):not(.active){border-color:var(--g600);background:var(--g100);color:var(--g800)}
.page-btn.active{background:var(--g700);border-color:var(--g700);color:#fff;cursor:default}
.page-btn:disabled{opacity:.38;cursor:default}

.action-cell{display:flex;gap:5px}
.btn-icon{width:30px;height:30px;border:none;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;transition:all var(--tr);cursor:pointer}
.btn-icon:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.18)}
.bi-edit{background:#fff3e0;color:#e65100}.bi-edit:hover{background:#e65100;color:#fff}
.bi-return{background:var(--g100);color:var(--g800)}.bi-return:hover{background:var(--g700);color:#fff}
.bi-delete{background:var(--red-bg);color:var(--red)}.bi-delete:hover{background:var(--red);color:#fff}

.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px)}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto;animation:fadeIn .2s ease}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:720px;box-shadow:var(--shadow-lg);animation:slideDown .25s ease;margin:auto}
@keyframes slideDown{from{transform:translateY(-20px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%);padding:20px 26px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head h2{color:#fff;font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:1.1rem;display:flex;align-items:center;justify-content:center;transition:background var(--tr);cursor:pointer}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:24px 28px}
.modal-footer{padding:14px 28px 22px;display:flex;justify-content:flex-end;gap:10px;border-top:1px solid #e8ede9}

.stock-info{display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--g100);border-radius:var(--radius);margin-bottom:16px;font-size:.875rem;color:var(--g800)}
.stock-info.warn{background:var(--orange-bg);color:var(--orange)}
.stock-info i{font-size:1rem}

.form-section-title{font-size:.78rem;font-weight:700;color:var(--g800);text-transform:uppercase;letter-spacing:.5px;margin:0 0 12px;padding-bottom:6px;border-bottom:1px solid #e8ede9}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
.form-label{font-size:.82rem;font-weight:600;color:#444}
.form-label .req{color:var(--red)}
.form-control{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;background:#fff;transition:border-color var(--tr),box-shadow var(--tr);width:100%}
.form-control:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.form-control.error{border-color:var(--red)}

/* Return panel (shown in edit when status = returned) */
.return-panel{padding:14px;background:#f5fbf5;border:1px solid #c8e6c9;border-radius:var(--radius);margin-bottom:16px;display:none}
.return-panel.show{display:block}
.return-panel-title{font-size:.8rem;font-weight:700;color:var(--g700);margin-bottom:10px;text-transform:uppercase;letter-spacing:.4px}

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

  <div class="alert-banner danger" id="overdueAlert" style="<?= (int)$stats['overdue'] > 0 ? '' : 'display:none' ?>">
    <i class="fas fa-exclamation-triangle"></i>
    <span id="overdueAlertText"><?= (int)$stats['overdue'] ?> withdrawal<?= $stats['overdue'] > 1 ? 's are' : ' is' ?> <strong>overdue</strong> for return. Please follow up immediately.</span>
  </div>

  <!-- ── Page Header ────────────────────────────────────── -->
  <div class="page-header">
    <div class="hdr-left">
      <h1><i class="fas fa-dolly"></i> Chemical Withdrawals</h1>
      <p>Stock usage tracking &amp; return management</p>
    </div>
    <div class="hdr-right">
      <div class="stat-pill"><span class="n" id="statTotal"><?= (int)$stats['total'] ?></span><span class="l">Total</span></div>
      <div class="stat-pill warn"><span class="n" id="statActive"><?= (int)$stats['active'] ?></span><span class="l">Active</span></div>
      <div class="stat-pill warn"><span class="n" id="statOverdue"><?= (int)$stats['overdue'] ?></span><span class="l">Overdue</span></div>
      <div class="stat-pill"><span class="n" id="statReturned"><?= (int)$stats['returned'] ?></span><span class="l">Returned</span></div>
    </div>
  </div>

  <!-- ── Main Card ──────────────────────────────────────── -->
  <div class="card">
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search by name, person, purpose…" oninput="debounce(loadWithdrawals, 350)()">
        </div>
        <select class="filter-select" id="statusFilter" onchange="loadWithdrawals()">
          <option value="">All statuses</option>
          <option value="withdrawn">Withdrawn</option>
          <option value="returned">Returned</option>
          <option value="overdue">Overdue</option>
        </select>
        <select class="filter-select" id="deptFilter" onchange="loadWithdrawals()">
          <option value="">All departments</option>
          <option value="biology">Biology</option>
          <option value="chemistry">Chemistry</option>
          <option value="physics">Physics</option>
          <option value="computer_science">Computer Science</option>
          <option value="geography">Geography</option>
          <option value="general">General</option>
          <option value="administration">Administration</option>
          <option value="other">Other</option>
        </select>
        <span class="result-count" id="resultCount"></span>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-sm btn-pdf" onclick="exportPDF()">
          <i class="fas fa-file-pdf"></i> PDF
        </button>
        <button class="btn btn-primary btn-sm" onclick="openModal()">
          <i class="fas fa-plus"></i> Record Withdrawal
        </button>
      </div>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Chemical</th>
            <th>Withdrawn By</th>
            <th>Department</th>
            <th>Quantity</th>
            <th>Date Withdrawn</th>
            <th>Expected Return</th>
            <th>Status</th>
            <th>Purpose</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="wdTableBody">
          <?php for ($i = 0; $i < 6; $i++): ?>
            <tr><td><span class="skeleton"></span></td><td><span class="skeleton"></span></td><td><span class="skeleton" style="width:60%"></span></td><td><span class="skeleton" style="width:50%"></span></td><td><span class="skeleton" style="width:70%"></span></td><td><span class="skeleton" style="width:70%"></span></td><td><span class="skeleton" style="width:60px;height:18px"></span></td><td><span class="skeleton" style="width:65%"></span></td><td><span class="skeleton" style="width:70px;height:22px"></span></td></tr>
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

<!-- ── Withdrawal Modal ───────────────────────────────────── -->
<div class="modal" id="wdModal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-dolly"></i> <span id="modalTitle">Record Withdrawal</span></h2>
      <button class="modal-close" onclick="closeModal()">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="fId">

      <!-- Stock info bar -->
      <div id="stockInfo" class="stock-info" style="display:none">
        <i class="fas fa-box"></i>
        <span id="stockText"></span>
      </div>

      <div class="form-section-title">Chemical &amp; Quantity</div>
      <div class="form-grid">
        <div class="form-group full">
          <label class="form-label">Chemical <span class="req">*</span></label>
          <select class="form-control" id="fChem" onchange="onChemChange()">
            <option value="">Select chemical…</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Quantity Withdrawn <span class="req">*</span></label>
          <input type="number" class="form-control" id="fQty" min="0.001" step="0.001" placeholder="0">
        </div>
        <div class="form-group">
          <label class="form-label">Withdrawal Date <span class="req">*</span></label>
          <input type="date" class="form-control" id="fWdDate">
        </div>
        <div class="form-group">
          <label class="form-label">Expected Return Date</label>
          <input type="date" class="form-control" id="fRetDate">
        </div>
      </div>

      <div class="form-section-title">Requestor Details</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Withdrawn By <span class="req">*</span></label>
          <input type="text" class="form-control" id="fBy" maxlength="150" placeholder="Full name">
        </div>
        <div class="form-group">
          <label class="form-label">Department <span class="req">*</span></label>
          <select class="form-control" id="fDept">
            <option value="">Select department</option>
            <option value="biology">Biology</option>
            <option value="chemistry">Chemistry</option>
            <option value="physics">Physics</option>
            <option value="computer_science">Computer Science</option>
            <option value="geography">Geography</option>
            <option value="general">General</option>
            <option value="administration">Administration</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="form-group full">
          <label class="form-label">Purpose <span class="req">*</span></label>
          <input type="text" class="form-control" id="fPurpose" maxlength="255" placeholder="e.g., Titration practical, S.5 Chemistry">
        </div>
        <div class="form-group full">
          <label class="form-label">Notes</label>
          <textarea class="form-control" id="fNotes" rows="2" placeholder="Special handling, observations…"></textarea>
        </div>
      </div>

      <!-- Status (edit only) -->
      <div id="statusSection" style="display:none">
        <div class="form-section-title">Status Update</div>
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Status <span class="req">*</span></label>
            <select class="form-control" id="fStatus" onchange="onStatusChange()">
              <option value="withdrawn">Withdrawn</option>
              <option value="returned">Returned</option>
              <option value="overdue">Overdue</option>
            </select>
          </div>
        </div>
        <!-- Return details panel -->
        <div class="return-panel" id="returnPanel">
          <div class="return-panel-title"><i class="fas fa-undo"></i> Return Details</div>
          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Actual Return Date</label>
              <input type="date" class="form-control" id="fActDate">
            </div>
            <div class="form-group">
              <label class="form-label">Quantity Returned</label>
              <input type="number" class="form-control" id="fQtyRet" min="0" step="0.001" placeholder="0">
            </div>
          </div>
        </div>
      </div>

    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
      <button class="btn btn-danger" id="deleteBtnModal" style="display:none" onclick="promptDelete()">
        <i class="fas fa-trash"></i> Delete
      </button>
      <button class="btn btn-primary" id="saveBtn" onclick="saveWithdrawal()">
        <i class="fas fa-save"></i> Save
      </button>
    </div>
  </div>
</div>

<!-- ── Confirm dialog ─────────────────────────────────────── -->
<div class="dialog" id="confirmDlg" onclick="if(event.target===this)closeDlg()">
  <div class="dialog-box">
    <div class="dialog-head"><i class="fas fa-trash-alt"></i><span>Delete Withdrawal</span></div>
    <div class="dialog-body" id="dlgBody"></div>
    <div class="dialog-actions">
      <button class="btn btn-secondary" onclick="closeDlg()">Cancel</button>
      <button class="btn btn-danger" onclick="doDelete()">Yes, delete &amp; restore stock</button>
    </div>
  </div>
</div>

<div id="notif-stack"></div>

<script>
'use strict';
const CSRF    = <?= json_encode($csrf, JSON_HEX_QUOT | JSON_HEX_TAG) ?>;
const THIS_URL = 'chemicals_withdraws.php';

let withdrawals  = [];
let availChems   = [];
let currentPage  = 1;
let totalRows    = 0;
let pendingDelId = 0;
const PER_PAGE   = 25;

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('fWdDate').value = new Date().toISOString().slice(0,10);
    loadChemicals();
    loadWithdrawals();
});

/* ── Load chemicals list for dropdown ───────────────────── */
async function loadChemicals() {
    const fd = new FormData();
    fd.append('action', 'get_chemicals');
    try {
        const res  = await fetch(THIS_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            availChems = data.chemicals;
            populateChemSelect();
        }
    } catch(e) { /* non-critical */ }
}
function populateChemSelect() {
    const sel = document.getElementById('fChem');
    const cur = sel.value;
    while (sel.options.length > 1) sel.remove(1);
    availChems.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = `${c.name}${c.cas ? ' (' + c.cas + ')' : ''} — ${parseFloat(c.quantity).toLocaleString('en-UG',{maximumFractionDigits:3})} ${c.unit} available`;
        opt.dataset.qty     = c.quantity;
        opt.dataset.unit    = c.unit;
        opt.dataset.hazard  = c.hazard_level;
        sel.appendChild(opt);
    });
    if (cur) sel.value = cur;
}
function onChemChange() {
    const sel  = document.getElementById('fChem');
    const opt  = sel.selectedOptions[0];
    const info = document.getElementById('stockInfo');
    const txt  = document.getElementById('stockText');
    
    // If no chemical is selected, hide the stock info bar
    if (!opt || !opt.value) { 
        info.style.display = 'none'; 
        return; 
    }
    
    // Pull the data attributes set in populateChemSelect()
    const qty  = parseFloat(opt.dataset.qty);
    const unit = opt.dataset.unit;
    const haz  = opt.dataset.hazard; 
    
    // Determine if stock is low (under 10) to apply the warning style
    const low  = qty < 10;
    
    // Update the UI
    info.className = `stock-info${low ? ' warn' : ''}`;
    info.style.display = 'flex';
    txt.innerHTML = `Available stock: <strong>${qty.toLocaleString('en-UG',{maximumFractionDigits:3})} ${esc(unit)}</strong> &nbsp;·&nbsp; Hazard: <strong>${ucf(haz)}</strong>`;
}

/* ── Load withdrawals ───────────────────────────────────── */
function showSkeletonRows(cols = 9) {
    const skRow = `<tr>${Array(cols).fill('<td><span class="skeleton"></span></td>').join('')}</tr>`;
    document.getElementById('wdTableBody').innerHTML = Array(5).fill(skRow).join('');
}

async function refreshStats() {
    const fd = new FormData();
    fd.append('action', 'get_stats');
    try {
        const res  = await fetch(THIS_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) return;
        const s = data.stats;
        document.getElementById('statTotal').textContent   = s.total;
        document.getElementById('statActive').textContent  = s.active;
        document.getElementById('statOverdue').textContent = s.overdue;
        document.getElementById('statReturned').textContent = s.returned;
        const alert = document.getElementById('overdueAlert');
        if (parseInt(s.overdue) > 0) {
            document.getElementById('overdueAlertText').innerHTML =
                `${s.overdue} withdrawal${s.overdue > 1 ? 's are' : ' is'} <strong>overdue</strong> for return. Please follow up immediately.`;
            alert.style.display = '';
        } else {
            alert.style.display = 'none';
        }
    } catch(e) { /* non-critical */ }
}

async function loadWithdrawals(page = 1, silent = false) {
    currentPage = page;
    if (!silent) {
        showSkeletonRows();
        document.getElementById('paginationBar').style.display = 'none';
    }
    const fd = new FormData();
    fd.append('action',     'list');
    fd.append('page',       page);
    fd.append('search',     document.getElementById('searchInput').value.trim());
    fd.append('status',     document.getElementById('statusFilter').value);
    fd.append('department', document.getElementById('deptFilter').value);
    try {
        const res  = await fetch(THIS_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) { notify('Error', data.error, 'error'); return; }
        withdrawals = data.withdrawals;
        totalRows   = data.total;
        renderTable();
        renderPagination();
        document.getElementById('resultCount').textContent = `${data.total} record${data.total !== 1 ? 's' : ''}`;
    } catch(e) {
        notify('Error', 'Failed to load withdrawals.', 'error');
    }
}

/* ── Render table ────────────────────────────────────────── */
function renderTable() {
    const tbody = document.getElementById('wdTableBody');
    if (!withdrawals.length) {
        tbody.innerHTML = `<tr><td colspan="9"><div class="empty-state"><i class="fas fa-dolly"></i><p>No withdrawal records found.</p></div></td></tr>`;
        return;
    }
    tbody.innerHTML = withdrawals.map(w => {
        const isOverdue = w.status === 'overdue';
        const isReturned = w.status === 'returned';
        return `<tr class="${isOverdue ? 'row-overdue' : ''}">
          <td>
            <div style="font-weight:600">${esc(w.chemical_name)}</div>
            ${w.cas ? `<div style="font-size:.75rem;color:#888">CAS: ${esc(w.cas)}</div>` : ''}
            <span class="badge badge-${w.hazard_level}" style="margin-top:3px">${ucf(w.hazard_level)} hazard</span>
          </td>
          <td style="font-weight:500">${esc(w.withdrawn_by)}</td>
          <td style="font-size:.82rem">${fmtDept(w.department)}</td>
          <td style="font-weight:600">${parseFloat(w.quantity_withdrawn).toLocaleString('en-UG',{maximumFractionDigits:3})} ${esc(w.unit)}</td>
          <td style="font-size:.82rem">${fmtDate(w.withdrawal_date)}</td>
          <td style="font-size:.82rem">${w.expected_return_date ? fmtDate(w.expected_return_date) : '<span style="color:#bbb">—</span>'}
            ${isOverdue ? '<br><span style="font-size:.7rem;color:var(--red);font-weight:700">OVERDUE</span>' : ''}
          </td>
          <td><span class="badge badge-${w.status}">${ucf(w.status)}</span>
            ${isReturned && w.quantity_returned ? `<div style="font-size:.72rem;color:#666;margin-top:2px">Returned: ${w.quantity_returned} ${esc(w.unit)}</div>` : ''}
          </td>
          <td style="font-size:.82rem;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(w.purpose)}">${esc(w.purpose)}</td>
          <td>
            <div class="action-cell">
              <button class="btn-icon bi-edit" title="Edit" onclick='openModal(${JSON.stringify(w)})'><i class="fas fa-edit"></i></button>
              <button class="btn-icon bi-delete" title="Delete" onclick="promptDelete(${w.id},'${esc(w.chemical_name)}')"><i class="fas fa-trash"></i></button>
            </div>
          </td>
        </tr>`;
    }).join('');
}

/* ── Pagination ──────────────────────────────────────────── */
function renderPagination() {
    const bar  = document.getElementById('paginationBar');
    const pages = Math.ceil(totalRows / PER_PAGE);
    if (pages <= 1) { bar.style.display = 'none'; return; }
    bar.style.display = 'flex';
    const from = (currentPage - 1) * PER_PAGE + 1;
    const to   = Math.min(currentPage * PER_PAGE, totalRows);
    document.getElementById('pageInfo').textContent = `Showing ${from}–${to} of ${totalRows}`;
    let html = `<button class="page-btn" ${currentPage===1?'disabled':''} onclick="loadWithdrawals(${currentPage-1})"><i class="fas fa-chevron-left"></i></button>`;
    for (let p = 1; p <= pages; p++) {
        if (pages > 7 && Math.abs(p - currentPage) > 2 && p !== 1 && p !== pages) {
            if (p === 2 || p === pages - 1) html += `<button class="page-btn" disabled>…</button>`;
            continue;
        }
        html += `<button class="page-btn ${p===currentPage?'active':''}" onclick="loadWithdrawals(${p})">${p}</button>`;
    }
    html += `<button class="page-btn" ${currentPage===pages?'disabled':''} onclick="loadWithdrawals(${currentPage+1})"><i class="fas fa-chevron-right"></i></button>`;
    document.getElementById('pageBtns').innerHTML = html;
}

/* ── Modal ───────────────────────────────────────────────── */
function openModal(wd = null) {
    clearForm();
    const isEdit = wd !== null;
    document.getElementById('modalTitle').textContent = isEdit ? 'Edit Withdrawal' : 'Record Withdrawal';
    document.getElementById('statusSection').style.display = isEdit ? '' : 'none';
    document.getElementById('deleteBtnModal').style.display = isEdit ? '' : 'none';
    document.getElementById('fChem').disabled = isEdit;

    if (isEdit) {
        document.getElementById('fId').value       = wd.id;
        document.getElementById('fChem').value     = wd.chemical_id;
        document.getElementById('fQty').value      = wd.quantity_withdrawn;
        document.getElementById('fWdDate').value   = wd.withdrawal_date;
        document.getElementById('fRetDate').value  = wd.expected_return_date || '';
        document.getElementById('fBy').value       = wd.withdrawn_by;
        document.getElementById('fDept').value     = wd.department;
        document.getElementById('fPurpose').value  = wd.purpose;
        document.getElementById('fNotes').value    = wd.notes || '';
        document.getElementById('fStatus').value   = wd.status === 'overdue' ? 'withdrawn' : wd.status;
        document.getElementById('fActDate').value  = wd.actual_return_date || '';
        document.getElementById('fQtyRet').value   = wd.quantity_returned || '';
        onStatusChange();
        // Show stock info
        const opt = document.querySelector(`#fChem option[value="${wd.chemical_id}"]`);
        if (opt) onChemChange(); else document.getElementById('stockInfo').style.display = 'none';
    } else {
        document.getElementById('fWdDate').value = new Date().toISOString().slice(0,10);
    }
    document.getElementById('wdModal').classList.add('active');
}
function closeModal() {
    document.getElementById('wdModal').classList.remove('active');
}
function clearForm() {
    ['fId','fChem','fQty','fWdDate','fRetDate','fBy','fDept','fPurpose',
     'fNotes','fStatus','fActDate','fQtyRet']
     .forEach(id => { const el=document.getElementById(id); if(el){el.value='';el.classList.remove('error');} });
    document.getElementById('stockInfo').style.display = 'none';
    document.getElementById('fChem').disabled = false;
    document.getElementById('returnPanel').classList.remove('show');
}
function onStatusChange() {
    const s = document.getElementById('fStatus').value;
    document.getElementById('returnPanel').classList.toggle('show', s === 'returned');
}

/* ── Save ────────────────────────────────────────────────── */
async function saveWithdrawal() {
    const id = document.getElementById('fId').value;
    const fd = new FormData();
    fd.append('action',     id ? 'update' : 'add');
    fd.append('csrf_token', CSRF);
    if (id) fd.append('id', id);
    fd.append('chemical_id',        document.getElementById('fChem').value);
    fd.append('quantity_withdrawn', document.getElementById('fQty').value || '0');
    fd.append('withdrawal_date',    document.getElementById('fWdDate').value);
    fd.append('expected_return_date', document.getElementById('fRetDate').value);
    fd.append('withdrawn_by',       document.getElementById('fBy').value.trim());
    fd.append('department',         document.getElementById('fDept').value);
    fd.append('purpose',            document.getElementById('fPurpose').value.trim());
    fd.append('notes',              document.getElementById('fNotes').value.trim());
    if (id) {
        fd.append('status',              document.getElementById('fStatus').value);
        fd.append('actual_return_date',  document.getElementById('fActDate').value);
        fd.append('quantity_returned',   document.getElementById('fQtyRet').value || '0');
    }

    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    try {
        const res  = await fetch(THIS_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            notify('Saved', data.message, 'success');
            closeModal();
            await loadChemicals();
            loadWithdrawals(currentPage, true);
            refreshStats();
        } else {
            notify('Error', data.error, 'error');
        }
    } catch(e) {
        notify('Error', 'Network error. Please try again.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save';
    }
}

/* ── Delete ──────────────────────────────────────────────── */
function promptDelete(id, chemName) {
    pendingDelId = id;
    const name = typeof chemName === 'string' ? chemName : (document.getElementById('fChem').selectedOptions[0]?.text || '');
    document.getElementById('dlgBody').innerHTML =
        `Delete this withdrawal record for <strong>${esc(name)}</strong>?<br>
         If the chemical was not returned, the quantity will be restored to inventory.`;
    document.getElementById('confirmDlg').classList.add('active');
}
function closeDlg() {
    document.getElementById('confirmDlg').classList.remove('active');
    pendingDelId = 0;
}
async function doDelete() {
    if (!pendingDelId) return;
    const idToDelete = pendingDelId; // must capture BEFORE closeDlg() resets to 0
    closeDlg(); closeModal();
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('csrf_token', CSRF);
    fd.append('id', idToDelete);
    try {
        const res  = await fetch(THIS_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            notify('Deleted', data.message, 'success');
            await loadChemicals();
            loadWithdrawals(currentPage, true);
            refreshStats();
        } else {
            notify('Error', data.error, 'error');
        }
    } catch(e) {
        notify('Error', 'Network error.', 'error');
    }
}

/* ── Export PDF ──────────────────────────────────────────── */
function exportPDF() {
    if (typeof window.jspdf === 'undefined') { notify('PDF','Library loading…','warning'); return; }
    if (!withdrawals.length) { notify('PDF','No data to export.','warning'); return; }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');
    doc.setFontSize(16); doc.setTextColor(27,94,32);
    doc.text('SchoolPilot — Chemical Withdrawal Report', 14, 16);
    doc.setFontSize(9); doc.setTextColor(100);
    doc.text(`Generated: ${new Date().toLocaleDateString('en-UG')}`, 14, 23);
    doc.autoTable({
        head:[['Chemical','Withdrawn By','Dept','Qty','Date','Expected Return','Status','Purpose']],
        body: withdrawals.map(w=>[w.chemical_name,w.withdrawn_by,fmtDept(w.department),`${w.quantity_withdrawn} ${w.unit}`,fmtDate(w.withdrawal_date),w.expected_return_date?fmtDate(w.expected_return_date):'—',ucf(w.status),w.purpose]),
        startY:28,theme:'grid',
        headStyles:{fillColor:[56,142,60],fontSize:8,fontStyle:'bold'},
        bodyStyles:{fontSize:7.5},
        alternateRowStyles:{fillColor:[232,245,233]},
        margin:{left:10,right:10}
    });
    doc.save(`withdrawals-${stamp()}.pdf`);
    notify('Exported','PDF downloaded.','success');
}

/* ── Utilities ───────────────────────────────────────────── */
function notify(title,msg,type='success',dur=4500){const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};const n=document.createElement('div');n.className=`notif ${type}`;n.innerHTML=`<i class="fas ${icons[type]} notif-icon"></i><div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div><button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;document.getElementById('notif-stack').prepend(n);setTimeout(()=>{n.style.opacity='0';n.style.transform='translateX(30px)';n.style.transition='.3s';setTimeout(()=>n.remove(),320);},dur);}
function esc(v){return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function ucf(v){return v?v.charAt(0).toUpperCase()+v.slice(1):'';}
function stamp(){return new Date().toISOString().slice(0,10);}
function fmtDate(d){if(!d)return'—';try{return new Date(d+'T00:00:00').toLocaleDateString('en-UG',{day:'2-digit',month:'short',year:'numeric'});}catch(_){return d;}}
function fmtDept(v){const m={biology:'Biology',chemistry:'Chemistry',physics:'Physics',computer_science:'Computer Science',geography:'Geography',general:'General',administration:'Administration',other:'Other'};return m[v]||v||'—';}
const debounce=(fn,ms)=>{let t;return(...a)=>{clearTimeout(t);t=setTimeout(()=>fn(...a),ms);};};
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeModal();closeDlg();}});
</script>
</body>
</html>
