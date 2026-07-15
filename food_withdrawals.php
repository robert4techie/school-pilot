<?php
/**
 * Food Withdrawals Management
 * Production-grade: CSRF, transactions, prepared statements, approval workflow,
 * inventory deduction safety, JSON API for all mutations.
 */
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction('Food Withdrawals');

/* ── Security Headers ───────────────────────────────────────────────── */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

/* ── Ensure mysqli throws exceptions on errors ──────────────────────── */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ── CSRF ────────────────────────────────────────────────────────── */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf        = $_SESSION['csrf_token'];
$currentUser = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'Staff';

/* ── JSON API ────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    /* CSRF check */
    if (!hash_equals($_SESSION['csrf_token'], (string)($body['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }

    switch ($action) {
        case 'fetch':          echo json_encode(apiFetch($conn, $body));           break;
        case 'get_item':       echo json_encode(apiGetItem($conn, $body));         break;
        case 'get_inventory':  echo json_encode(apiGetInventory($conn));           break;
        case 'add':            echo json_encode(apiSave($conn, $body, false));     break;
        case 'update':         echo json_encode(apiSave($conn, $body, true));      break;
        case 'delete':         echo json_encode(apiDelete($conn, $body));          break;
        case 'approve':        echo json_encode(apiApprove($conn, $body, 'approved', $currentUser)); break;
        case 'reject':         echo json_encode(apiApprove($conn, $body, 'rejected', $currentUser)); break;
        case 'complete':       echo json_encode(apiApprove($conn, $body, 'completed', $currentUser)); break;
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
    $purpose = trim($p['purpose'] ?? '');
    $dept    = trim($p['department'] ?? '');
    $dateFrom= trim($p['date_from'] ?? '');
    $dateTo  = trim($p['date_to']   ?? '');

    $where  = ['1=1'];
    $params = [];
    $types  = '';

    $validStatuses = ['pending','approved','rejected','completed'];
    $validPurposes = ['cooking','staff_meal','student_meal','event','waste','sample','transfer','other'];

    if ($search !== '') {
        $where[]  = '(w.item_name LIKE ? OR w.requested_by LIKE ? OR w.department LIKE ? OR w.notes LIKE ?)';
        $like     = "%{$search}%";
        $params   = array_merge($params, [$like,$like,$like,$like]);
        $types   .= 'ssss';
    }
    if ($status !== '' && in_array($status, $validStatuses, true)) {
        $where[]  = 'w.status = ?';
        $params[] = $status;
        $types   .= 's';
    }
    if ($purpose !== '' && in_array($purpose, $validPurposes, true)) {
        $where[]  = 'w.purpose = ?';
        $params[] = $purpose;
        $types   .= 's';
    }
    if ($dept !== '') {
        /* dept is a free-text filter — limit length to prevent abuse */
        $dept     = mb_substr($dept, 0, 100);
        $where[]  = 'w.department LIKE ?';
        $params[] = "%{$dept}%";
        $types   .= 's';
    }
    if ($dateFrom !== '') {
        $where[]  = 'w.withdrawal_date >= ?';
        $params[] = $dateFrom;
        $types   .= 's';
    }
    if ($dateTo !== '') {
        $where[]  = 'w.withdrawal_date <= ?';
        $params[] = $dateTo;
        $types   .= 's';
    }

    $whereSQL = implode(' AND ', $where);

    /* total */
    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM food_withdrawals w WHERE {$whereSQL}");
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();

    /* data */
    $offset  = ($page-1)*$perPage;
    $stmt    = $conn->prepare("
        SELECT w.*, fi.quantity AS current_stock
          FROM food_withdrawals w
     LEFT JOIN food_inventory fi ON fi.food_id = w.food_id
         WHERE {$whereSQL}
      ORDER BY w.withdrawal_date DESC, w.withdrawal_time DESC
         LIMIT ? OFFSET ?
    ");
    $allTypes  = $types . 'ii';
    $allParams = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($allTypes, ...$allParams);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /* summary stats */
    $sRes  = $conn->query("
        SELECT
            COUNT(*)                                        AS total,
            SUM(status='pending')                           AS pending,
            SUM(status='approved')                          AS approved,
            SUM(status='rejected')                          AS rejected,
            SUM(status='completed')                         AS completed,
            COALESCE(SUM(CASE WHEN status='completed' THEN total_value ELSE 0 END),0) AS total_withdrawn_value
        FROM food_withdrawals
    ");
    $stats = $sRes ? $sRes->fetch_assoc() : [];

    return [
        'success'   => true,
        'data'      => $rows,
        'total'     => $total,
        'page'      => $page,
        'per_page'  => $perPage,
        'last_page' => max(1, (int)ceil($total/$perPage)),
        'stats'     => $stats,
    ];
}

function apiGetItem(mysqli $conn, array $p): array
{
    $id = (int)($p['withdraw_id'] ?? 0);
    if (!$id) return ['success'=>false,'error'=>'Invalid ID.'];

    $stmt = $conn->prepare('
        SELECT w.*, fi.quantity AS current_stock, fi.unit AS inv_unit
          FROM food_withdrawals w
     LEFT JOIN food_inventory fi ON fi.food_id = w.food_id
         WHERE w.withdraw_id = ?
    ');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? ['success'=>true,'data'=>$row] : ['success'=>false,'error'=>'Record not found.'];
}

function apiGetInventory(mysqli $conn): array
{
    $res  = $conn->query("SELECT food_id, item_name, quantity, unit, unit_price, category FROM food_inventory WHERE quantity > 0 ORDER BY item_name");
    $rows = [];
    if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC);
    return ['success'=>true,'data'=>$rows];
}

function apiSave(mysqli $conn, array $p, bool $isUpdate): array
{
    /* ── Inputs ── */
    $foodId    = (int)($p['food_id']         ?? 0);
    $qty       = $p['quantity']               ?? null;
    $purpose   = trim($p['purpose']          ?? '');
    $reqBy     = trim($p['requested_by']     ?? '');
    $date      = trim($p['withdrawal_date']  ?? '');
    $time      = trim($p['withdrawal_time']  ?? '00:00');
    $dept      = trim($p['department']       ?? '');
    $notes     = trim($p['notes']            ?? '');
    $totalVal  = $p['total_value']            ?? null;

    $validPurposes = ['cooking','staff_meal','student_meal','event','waste','sample','transfer','other'];

    /* ── Validate ── */
    $errors = [];
    if (!$foodId)                                          $errors[] = 'Please select an inventory item.';
    if (!is_numeric($qty) || (float)$qty <= 0)             $errors[] = 'Quantity must be a positive number.';
    if (!in_array($purpose, $validPurposes, true))         $errors[] = 'Invalid purpose selected.';
    if ($reqBy === '')                                     $errors[] = 'Requested by is required.';
    if ($date === '' || !strtotime($date))                 $errors[] = 'Valid withdrawal date is required.';
    if ($errors) return ['success'=>false,'error'=>implode(' ',$errors)];

    $qty      = (float)$qty;
    $totalVal = is_numeric($totalVal) ? (float)$totalVal : 0.0;

    /* ── Get inventory item ── */
    $iStmt = $conn->prepare('SELECT item_name, unit, quantity, unit_price FROM food_inventory WHERE food_id = ?');
    $iStmt->bind_param('i', $foodId);
    $iStmt->execute();
    $inv = $iStmt->get_result()->fetch_assoc();
    $iStmt->close();
    if (!$inv) return ['success'=>false,'error'=>'Selected inventory item not found.'];

    /* auto-calc total if not provided */
    if ($totalVal <= 0) $totalVal = $qty * (float)$inv['unit_price'];

    /* ── Transaction ── */
    $conn->begin_transaction();
    try {
        if ($isUpdate) {
            $wid = (int)($p['withdraw_id'] ?? 0);
            if (!$wid) throw new \RuntimeException('Invalid withdrawal ID.');

            /* fetch old record */
            $oStmt = $conn->prepare('SELECT food_id, quantity, status FROM food_withdrawals WHERE withdraw_id = ?');
            $oStmt->bind_param('i', $wid);
            $oStmt->execute();
            $old = $oStmt->get_result()->fetch_assoc();
            $oStmt->close();
            if (!$old) throw new \RuntimeException('Original record not found.');
            if (in_array($old['status'], ['approved','completed'])) {
                throw new \RuntimeException('Cannot edit an approved/completed withdrawal. Reject it first.');
            }

            /* check stock availability:
               - Same item: available = current_stock + original_qty (we'll be returning it)
               - Different item: available = current_stock of the new item only */
            if ($old['food_id'] == $foodId) {
                $newAvail = (float)$inv['quantity'] + (float)$old['quantity'];
                if ($qty > $newAvail) {
                    throw new \RuntimeException("Insufficient stock. Available after return: {$newAvail} {$inv['unit']}.");
                }
            } else {
                /* switching to a different item — check new item's stock directly */
                if ($qty > (float)$inv['quantity']) {
                    throw new \RuntimeException("Insufficient stock for new item. Available: {$inv['quantity']} {$inv['unit']}.");
                }
            }

            /* update withdrawal */
            $stmt = $conn->prepare("
                UPDATE food_withdrawals
                   SET food_id=?, item_name=?, quantity=?, unit=?, purpose=?,
                       requested_by=?, withdrawal_date=?, withdrawal_time=?,
                       department=?, notes=?, total_value=?
                 WHERE withdraw_id=?
            ");
            // types: i s d s s s s s s s d i  (12 params)
            $stmt->bind_param('isdsssssssdi',
                $foodId, $inv['item_name'], $qty, $inv['unit'], $purpose,
                $reqBy, $date, $time, $dept, $notes, $totalVal, $wid
            );
            $stmt->execute();
            if ($stmt->error) throw new \RuntimeException('Update failed: ' . $stmt->error);
            $stmt->close();

            /* restore old inventory, deduct new */
            $r1 = $conn->prepare('UPDATE food_inventory SET quantity = quantity + ? WHERE food_id = ?');
            $r1->bind_param('di', $old['quantity'], $old['food_id']);
            $r1->execute(); $r1->close();

            $r2 = $conn->prepare('UPDATE food_inventory SET quantity = quantity - ? WHERE food_id = ?');
            $r2->bind_param('di', $qty, $foodId);
            $r2->execute(); $r2->close();

        } else {
            /* check stock */
            if ($qty > (float)$inv['quantity']) {
                throw new \RuntimeException("Insufficient stock. Available: {$inv['quantity']} {$inv['unit']}.");
            }

            $stmt = $conn->prepare("
                INSERT INTO food_withdrawals
                    (food_id, item_name, quantity, unit, purpose, requested_by,
                     withdrawal_date, withdrawal_time, department, notes, total_value, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending')
            ");
            $stmt->bind_param('isdsssssssd',
                $foodId, $inv['item_name'], $qty, $inv['unit'], $purpose,
                $reqBy, $date, $time, $dept, $notes, $totalVal
            );
            $stmt->execute();
            $stmt->close();

            /* deduct inventory immediately on record creation */
            $r2 = $conn->prepare('UPDATE food_inventory SET quantity = quantity - ? WHERE food_id = ?');
            $r2->bind_param('di', $qty, $foodId);
            $r2->execute(); $r2->close();
        }

        $conn->commit();
        return ['success'=>true,'message'=> $isUpdate ? 'Withdrawal updated.' : 'Withdrawal recorded successfully.'];

    } catch (\Throwable $e) {
        $conn->rollback();
        return ['success'=>false,'error'=>$e->getMessage()];
    }
}

function apiDelete(mysqli $conn, array $p): array
{
    $id = (int)($p['withdraw_id'] ?? 0);
    if (!$id) return ['success'=>false,'error'=>'Invalid ID.'];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT food_id, quantity, status FROM food_withdrawals WHERE withdraw_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row  = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) throw new \RuntimeException('Record not found.');
        if ($row['status'] === 'completed') {
            throw new \RuntimeException('Cannot delete a completed withdrawal.');
        }

        $del = $conn->prepare('DELETE FROM food_withdrawals WHERE withdraw_id = ?');
        $del->bind_param('i', $id);
        $del->execute(); $del->close();

        /* Inventory restoration logic:
           - 'pending' / 'approved': inventory was deducted on creation, not yet restored → restore now.
           - 'rejected': inventory was ALREADY restored by apiApprove when it was rejected → do NOT restore again. */
        if ($row['status'] !== 'rejected') {
            $upd = $conn->prepare('UPDATE food_inventory SET quantity = quantity + ? WHERE food_id = ?');
            $upd->bind_param('di', $row['quantity'], $row['food_id']);
            $upd->execute(); $upd->close();
        }

        $conn->commit();
        return ['success'=>true,'message'=>'Withdrawal deleted and inventory restored.'];
    } catch (\Throwable $e) {
        $conn->rollback();
        return ['success'=>false,'error'=>$e->getMessage()];
    }
}

function apiApprove(mysqli $conn, array $p, string $newStatus, string $approver): array
{
    $id = (int)($p['withdraw_id'] ?? 0);
    if (!$id) return ['success'=>false,'error'=>'Invalid ID.'];

    $stmt = $conn->prepare('SELECT status FROM food_withdrawals WHERE withdraw_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row  = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return ['success'=>false,'error'=>'Record not found.'];

    $currentStatus = $row['status'];
    /* allowed transitions */
    $allowed = [
        'pending'   => ['approved','rejected'],
        'approved'  => ['completed','rejected'],
        'rejected'  => ['pending'],
        'completed' => [],
    ];
    if (!in_array($newStatus, $allowed[$currentStatus] ?? [], true)) {
        return ['success'=>false,'error'=>"Cannot change status from '{$currentStatus}' to '{$newStatus}'."];
    }

    $conn->begin_transaction();
    try {
        $upd = $conn->prepare("
            UPDATE food_withdrawals
               SET status=?, approved_by=?, approval_date=NOW()
             WHERE withdraw_id=?
        ");
        $upd->bind_param('ssi', $newStatus, $approver, $id);
        $upd->execute(); $upd->close();

        /* If rejecting — restore inventory (stock was deducted on creation) */
        if ($newStatus === 'rejected') {
            $w  = $conn->prepare('SELECT food_id, quantity FROM food_withdrawals WHERE withdraw_id = ?');
            $w->bind_param('i', $id);
            $w->execute();
            $wd = $w->get_result()->fetch_assoc();
            $w->close();
            if ($wd) {
                $r = $conn->prepare('UPDATE food_inventory SET quantity = quantity + ? WHERE food_id = ?');
                $r->bind_param('di', $wd['quantity'], $wd['food_id']);
                $r->execute(); $r->close();
            }
        }
        /* If un-rejecting (back to pending) — re-deduct inventory */
        if ($currentStatus === 'rejected' && $newStatus === 'pending') {
            $w  = $conn->prepare('SELECT food_id, quantity FROM food_withdrawals WHERE withdraw_id = ?');
            $w->bind_param('i', $id);
            $w->execute();
            $wd = $w->get_result()->fetch_assoc();
            $w->close();
            if ($wd) {
                $r = $conn->prepare('UPDATE food_inventory SET quantity = quantity - ? WHERE food_id = ?');
                $r->bind_param('di', $wd['quantity'], $wd['food_id']);
                $r->execute(); $r->close();
            }
        }

        $conn->commit();
        $labels = ['approved'=>'Approved','rejected'=>'Rejected','completed'=>'Marked as Completed','pending'=>'Reset to Pending'];
        return ['success'=>true,'message'=>($labels[$newStatus]??ucfirst($newStatus)) . ' successfully.'];
    } catch (\Throwable $e) {
        $conn->rollback();
        return ['success'=>false,'error'=>$e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Food Withdrawals &mdash; School Pilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
<style>
/* ── Variables ───────────────────────────────────────────────────── */
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--orange:#e65100;--blue:#1565c0;--gray:#546e7a;
  --amber:#f57f17;--purple:#6a1b9a;--teal:#00695c;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);
  --shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --transition:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}
a{color:inherit;text-decoration:none}
 
/* ── Layout ──────────────────────────────────────────────────────── */
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
 
/* ── Card ────────────────────────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden}
 
/* ── Toolbar ─────────────────────────────────────────────────────── */
.toolbar{padding:18px 24px;border-bottom:1px solid #e8ede9;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.toolbar-left{display:flex;flex-wrap:wrap;gap:10px;align-items:center;flex:1 1 auto}
.toolbar-right{display:flex;gap:10px;align-items:center;flex-shrink:0}
.search-wrap{position:relative;min-width:220px}
.search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.85rem}
.search-wrap input{width:100%;padding:9px 12px 9px 34px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;transition:border-color var(--transition),box-shadow var(--transition)}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.filter-select{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;background:#fff;cursor:pointer;min-width:130px;transition:border-color var(--transition)}
.filter-select:focus{outline:none;border-color:var(--g600)}
.date-input{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;background:#fff;min-width:140px}
.result-count{font-size:.8rem;color:#6b7c6d;white-space:nowrap}
 
/* ── Buttons ─────────────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all var(--transition);white-space:nowrap}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active,.btn:disabled{transform:none;opacity:.6;cursor:not-allowed}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-success{background:var(--g700);color:#fff}.btn-success:hover{background:var(--g800)}
.btn-warning{background:var(--orange);color:#fff}.btn-warning:hover{background:#bf360c}
.btn-danger{background:var(--red);color:#fff}.btn-danger:hover{background:#b71c1c}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-pdf{background:#c62828;color:#fff}.btn-pdf:hover{background:var(--red)}
.btn-excel{background:var(--g800);color:#fff}.btn-excel:hover{background:var(--g900)}
.btn-sm{padding:6px 12px;font-size:.78rem}
 
/* ── Action icon buttons ─────────────────────────────────────────── */
.action-cell{display:flex;gap:5px;align-items:center;flex-wrap:wrap}
.btn-icon{width:28px;height:28px;border:none;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;transition:all var(--transition);flex-shrink:0}
.btn-icon:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.18)}
.bi-view{background:var(--g100);color:var(--g700)}.bi-view:hover{background:var(--g700);color:#fff}
.bi-edit{background:#fff3e0;color:#e65100}.bi-edit:hover{background:#e65100;color:#fff}
.bi-approve{background:#e8f5e9;color:var(--g700)}.bi-approve:hover{background:var(--g700);color:#fff}
.bi-reject{background:#ffebee;color:var(--red)}.bi-reject:hover{background:var(--red);color:#fff}
.bi-complete{background:var(--g100);color:var(--g600)}.bi-complete:hover{background:var(--g600);color:#fff}
.bi-delete{background:#ffebee;color:var(--red)}.bi-delete:hover{background:var(--red);color:#fff}
 
/* ── Table ───────────────────────────────────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:13px 14px;text-align:left;font-size:.8rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--transition)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:12px 14px;font-size:.875rem;vertical-align:middle}
 
/* ── Badges ──────────────────────────────────────────────────────── */
.badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.b-pending{background:#fff3e0;color:#e65100}
.b-approved{background:#e8f5e9;color:#2e7d32}
.b-rejected{background:#ffebee;color:#c62828}
.b-completed{background:#e8f5e9;color:#2e7d32}
/* purpose badges */
.p-cooking{background:#e8f5e9;color:#2e7d32}
.p-staff_meal{background:#e8f5e9;color:#1b5e20}
.p-student_meal{background:#f3e5f5;color:#6a1b9a}
.p-event{background:#fff3e0;color:#e65100}
.p-waste{background:#ffebee;color:#c62828}
.p-sample{background:#e0f7fa;color:#006064}
.p-transfer{background:#fce7f3;color:#880e4f}
.p-other{background:#f5f5f5;color:#444}
 
/* ── Skeleton ────────────────────────────────────────────────────── */
.skeleton-cell{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;height:14px;display:inline-block;width:80%}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
 
/* ── Empty State ─────────────────────────────────────────────────── */
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
.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);animation:fadeOverlay .2s ease}
@keyframes fadeOverlay{from{opacity:0}to{opacity:1}}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:780px;box-shadow:var(--shadow-lg);animation:slideDown .25s ease;margin:auto}
@keyframes slideDown{from{transform:translateY(-24px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%);padding:20px 24px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head h2{color:#fff;font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background var(--transition)}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:28px 28px 24px}
 
/* ── Form ────────────────────────────────────────────────────────── */
.form-section{margin-bottom:22px}
.form-section-title{font-size:.8rem;font-weight:700;color:var(--g800);text-transform:uppercase;letter-spacing:.6px;margin-bottom:14px;padding-bottom:7px;border-bottom:2px solid var(--g100);display:flex;align-items:center;gap:8px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-grid .full{grid-column:1/-1}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group label{font-size:.8rem;font-weight:600;color:#3a4a3b}
.form-group label .req{color:var(--red)}
.form-control{padding:9px 13px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;width:100%;transition:border-color var(--transition),box-shadow var(--transition);background:#fff;font-family:inherit}
.form-control:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.1)}
textarea.form-control{resize:vertical;min-height:72px}
.form-actions{display:flex;gap:12px;justify-content:flex-end;padding-top:20px;border-top:1px solid #eef2ee;margin-top:20px}
 
/* ── Stock info box ──────────────────────────────────────────────── */
.stock-info{margin-top:6px;padding:8px 12px;border-radius:var(--radius);font-size:.82rem;font-weight:600;display:none}
.stock-info.ok{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7}
.stock-info.low{background:#fff3e0;color:#e65100;border:1px solid #ffcc80}
.stock-info.out{background:#ffebee;color:#c62828;border:1px solid #ef9a9a}
 
/* ── Value calculator box ─────────────────────────────────────────── */
.calc-box{background:#f5fbf5;border:1.5px solid var(--g400);border-radius:var(--radius);padding:14px 16px;margin-top:4px;display:none}
.calc-box .calc-row{display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:4px;color:#555}
.calc-box .calc-total{display:flex;justify-content:space-between;font-size:.95rem;font-weight:700;color:var(--g800);padding-top:8px;border-top:1px solid var(--g400);margin-top:8px}
 
/* ── View Modal Details ──────────────────────────────────────────── */
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid #e8ede9;border-radius:var(--radius)}
.detail-row{display:contents}
.detail-row:not(:last-child) .d-label,.detail-row:not(:last-child) .d-val{border-bottom:1px solid #e8ede9}
.d-label{padding:10px 14px;font-size:.75rem;font-weight:700;color:var(--g800);text-transform:uppercase;letter-spacing:.4px;background:var(--g50);border-right:1px solid #e8ede9}
.d-val{padding:10px 14px;font-size:.875rem;color:#333}
.view-header{display:flex;align-items:center;gap:16px;margin-bottom:22px;padding-bottom:18px;border-bottom:2px solid var(--g100)}
.view-icon{width:56px;height:56px;border-radius:var(--radius-lg);background:var(--g100);display:flex;align-items:center;justify-content:center;font-size:1.6rem;color:var(--g700);flex-shrink:0}
.view-title h3{font-size:1.2rem;font-weight:700;color:var(--g800)}
.view-title p{font-size:.85rem;color:#6b7c6d;margin-top:3px}
 
/* ── Confirm Dialog ──────────────────────────────────────────────── */
.dialog{display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);align-items:center;justify-content:center}
.dialog.active{display:flex}
.dialog-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:420px;box-shadow:var(--shadow-lg);animation:slideDown .22s ease;overflow:hidden}
.dialog-head{padding:18px 22px;display:flex;align-items:center;gap:12px;color:#fff}
.dialog-head.danger{background:linear-gradient(135deg,#c62828,#ef5350)}
.dialog-head.warning{background:linear-gradient(135deg,#e65100,#ff7043)}
.dialog-head.success{background:linear-gradient(135deg,var(--g800),var(--g600))}
.dialog-head.info{background:linear-gradient(135deg,var(--g900),var(--g700))}
.dialog-head i{font-size:1.2rem}.dialog-head h3{font-size:1rem;font-weight:700}
.dialog-body{padding:22px;text-align:center}
.dialog-body p{font-size:.9rem;color:#555;line-height:1.55;margin-bottom:20px}
.dialog-actions{display:flex;gap:10px;justify-content:center}
 
/* ── Notifications ───────────────────────────────────────────────── */
#notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.notif{background:#fff;border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow-lg);display:flex;align-items:flex-start;gap:12px;border-left:4px solid var(--g600);animation:notifIn .3s ease}
.notif.success{border-left-color:var(--g600)}.notif.error{border-left-color:var(--red)}.notif.warning{border-left-color:#e65100}
@keyframes notifIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.notif-icon{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.notif.success .notif-icon{color:var(--g700)}.notif.error .notif-icon{color:var(--red)}.notif.warning .notif-icon{color:#e65100}.notif.info .notif-icon{color:var(--g700)}
.notif-body{flex:1}.notif-title{font-weight:700;font-size:.85rem;margin-bottom:2px}.notif-msg{font-size:.8rem;color:#666}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:1rem;padding:0;line-height:1;flex-shrink:0}
 
/* ── Responsive ──────────────────────────────────────────────────── */
@media(max-width:700px){
  .form-grid{grid-template-columns:1fr}.toolbar{flex-direction:column;align-items:stretch}
  .toolbar-right{flex-wrap:wrap}.page-header{flex-direction:column}
  .detail-grid{grid-template-columns:1fr}.d-label{border-right:none}.stats-row{gap:8px}
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
      <h1><i class="fas fa-hand-holding" style="margin-right:10px;opacity:.85"></i>Food Withdrawals</h1>
      <p>Record and manage food withdrawal requests with approval workflow</p>
    </div>
    <div class="stats-row">
      <div class="stat-pill" onclick="setFilter('status','')">    <span class="n" id="sTotal">—</span>    <span class="l">Total</span></div>
      <div class="stat-pill" onclick="setFilter('status','pending')"><span class="n" id="sPending">—</span><span class="l">Pending</span></div>
      <div class="stat-pill" onclick="setFilter('status','approved')"><span class="n" id="sApproved">—</span><span class="l">Approved</span></div>
      <div class="stat-pill" onclick="setFilter('status','completed')"><span class="n" id="sCompleted">—</span><span class="l">Completed</span></div>
      <div class="stat-pill" onclick="setFilter('status','rejected')"><span class="n" id="sRejected">—</span><span class="l">Rejected</span></div>
      <div class="stat-pill" style="cursor:default"><span class="n" id="sValue" style="font-size:1rem">—</span><span class="l">Value Out</span></div>
    </div>
  </div>

  <!-- Main Card -->
  <div class="card">
    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search item, requester, dept…" autocomplete="off">
        </div>
        <select id="statusFilter" class="filter-select">
          <option value="">All Statuses</option>
          <option value="pending">Pending</option>
          <option value="approved">Approved</option>
          <option value="completed">Completed</option>
          <option value="rejected">Rejected</option>
        </select>
        <select id="purposeFilter" class="filter-select">
          <option value="">All Purposes</option>
          <option value="cooking">Cooking</option>
          <option value="staff_meal">Staff Meal</option>
          <option value="student_meal">Student Meal</option>
          <option value="event">Event</option>
          <option value="waste">Waste</option>
          <option value="sample">Sample</option>
          <option value="transfer">Transfer</option>
          <option value="other">Other</option>
        </select>
        <input type="date" id="dateFrom" class="date-input" title="From date">
        <input type="date" id="dateTo"   class="date-input" title="To date">
        <button class="btn btn-outline" id="clearFiltersBtn"><i class="fas fa-times-circle"></i> Clear</button>
        <span class="result-count" id="resultCount"></span>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-pdf"   onclick="exportToPDF()"><i class="fas fa-file-pdf"></i> PDF</button>
        <button class="btn btn-excel" onclick="exportToExcel()"><i class="fas fa-file-excel"></i> Excel</button>
        <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> New Withdrawal</button>
      </div>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <table id="wdTable">
        <thead>
          <tr>
            <th style="width:40px">#</th>
            <th>Item</th>
            <th>Quantity</th>
            <th>Purpose</th>
            <th>Requested By</th>
            <th>Department</th>
            <th>Date</th>
            <th>Total Value</th>
            <th>Status</th>
            <th style="width:155px">Actions</th>
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

<!-- ══ VIEW MODAL ═══════════════════════════════════════════════════ -->
<div id="viewModal" class="modal" onclick="modalOutsideClick(event,'viewModal')">
  <div class="modal-box" style="max-width:680px">
    <div class="modal-head">
      <h2><i class="fas fa-receipt"></i> Withdrawal Details</h2>
      <button class="modal-close" onclick="closeModal('viewModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="viewBody"></div>
  </div>
</div>

<!-- ══ ADD / EDIT MODAL ═════════════════════════════════════════════ -->
<div id="editModal" class="modal" onclick="modalOutsideClick(event,'editModal')">
  <div class="modal-box">
    <div class="modal-head">
      <h2 id="editModalTitle"><i class="fas fa-plus"></i> New Withdrawal</h2>
      <button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="f_withdraw_id">

      <div class="form-section">
        <div class="form-section-title"><i class="fas fa-box"></i> Item Selection</div>
        <div class="form-grid">
          <div class="form-group full">
            <label>Inventory Item <span class="req">*</span></label>
            <select id="f_food_id" class="form-control" required onchange="onItemChange()">
              <option value="">Loading inventory…</option>
            </select>
            <div class="stock-info" id="stockInfo"></div>
          </div>
          <div class="form-group">
            <label>Quantity <span class="req">*</span></label>
            <input type="number" id="f_quantity" class="form-control" min="0.01" step="0.01" placeholder="0" required oninput="calcValue()">
          </div>
          <div class="form-group">
            <label>Unit</label>
            <input type="text" id="f_unit" class="form-control" readonly placeholder="Auto-filled">
          </div>
          <div class="form-group full">
            <div class="calc-box" id="calcBox">
              <div class="calc-row"><span>Unit Price</span><span id="calcUnitPrice">—</span></div>
              <div class="calc-row"><span>Quantity</span><span id="calcQty">—</span></div>
              <div class="calc-total"><span>Estimated Value</span><span id="calcTotal">—</span></div>
            </div>
          </div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title"><i class="fas fa-info-circle"></i> Withdrawal Details</div>
        <div class="form-grid">
          <div class="form-group">
            <label>Purpose <span class="req">*</span></label>
            <select id="f_purpose" class="form-control" required>
              <option value="">Select purpose…</option>
              <option value="cooking">Cooking</option>
              <option value="staff_meal">Staff Meal</option>
              <option value="student_meal">Student Meal</option>
              <option value="event">Event</option>
              <option value="waste">Waste / Disposal</option>
              <option value="sample">Sample / Testing</option>
              <option value="transfer">Transfer to Another Store</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="form-group">
            <label>Department</label>
            <input type="text" id="f_department" class="form-control" placeholder="e.g. Kitchen, Events" maxlength="100">
          </div>
          <div class="form-group">
            <label>Requested By <span class="req">*</span></label>
            <input type="text" id="f_requested_by" class="form-control" placeholder="Staff name" maxlength="255" required>
          </div>
          <div class="form-group">
            <label>Withdrawal Date <span class="req">*</span></label>
            <input type="date" id="f_withdrawal_date" class="form-control" required>
          </div>
          <div class="form-group">
            <label>Time</label>
            <input type="time" id="f_withdrawal_time" class="form-control" value="12:00">
          </div>
          <div class="form-group full">
            <label>Notes</label>
            <textarea id="f_notes" class="form-control" rows="2" placeholder="Additional notes…" maxlength="1000"></textarea>
          </div>
        </div>
      </div>

      <div class="form-actions">
        <button class="btn btn-outline" onclick="closeModal('editModal')"><i class="fas fa-times"></i> Cancel</button>
        <button class="btn btn-primary" id="saveBtn" onclick="saveWithdrawal()"><i class="fas fa-save"></i> Save Withdrawal</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ CONFIRM DIALOG ═══════════════════════════════════════════════ -->
<div id="confirmDlg" class="dialog">
  <div class="dialog-box">
    <div class="dialog-head danger" id="dlgHead">
      <i id="dlgIcon" class="fas fa-exclamation-triangle"></i>
      <h3 id="dlgTitle">Confirm Action</h3>
    </div>
    <div class="dialog-body">
      <p id="dlgMsg"></p>
      <div class="dialog-actions">
        <button class="btn btn-outline" onclick="closeDlg()"><i class="fas fa-times"></i> Cancel</button>
        <button class="btn btn-danger"  id="dlgConfirmBtn" onclick="runDlgCallback()">Confirm</button>
      </div>
    </div>
  </div>
</div>

<script>
/* ════════════════════════════════════════════════════════════════════
   STATE
   ════════════════════════════════════════════════════════════════════ */
const CSRF        = <?= json_encode($csrf) ?>;
const CURRENT_USER= <?= json_encode($currentUser) ?>;
let allData       = [];
let inventoryList = [];
let currentPage   = 1;
const PER_PAGE    = 25;
let filters       = { search:'', status:'', purpose:'', date_from:'', date_to:'' };
let dlgCb         = null;

/* selected item unit price for calc */
let selectedUnitPrice = 0;

/* ════════════════════════════════════════════════════════════════════
   BOOT
   ════════════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
    loadData();
    loadInventory();

    // Set default date to today
    document.getElementById('f_withdrawal_date').value = new Date().toISOString().split('T')[0];

    let t;
    document.getElementById('searchInput').addEventListener('input', function() {
        clearTimeout(t);
        t = setTimeout(() => { filters.search = this.value.trim(); currentPage=1; loadData(); }, 320);
    });
    document.getElementById('statusFilter').addEventListener('change', function() {
        filters.status = this.value; currentPage=1; loadData();
    });
    document.getElementById('purposeFilter').addEventListener('change', function() {
        filters.purpose = this.value; currentPage=1; loadData();
    });
    document.getElementById('dateFrom').addEventListener('change', function() {
        filters.date_from = this.value; currentPage=1; loadData();
    });
    document.getElementById('dateTo').addEventListener('change', function() {
        filters.date_to = this.value; currentPage=1; loadData();
    });
    document.getElementById('clearFiltersBtn').addEventListener('click', clearFilters);
});

/* ════════════════════════════════════════════════════════════════════
   LOAD DATA
   ════════════════════════════════════════════════════════════════════ */
function loadData(page) {
    if (page !== undefined) currentPage = page;
    renderSkeleton();

    api({ action:'fetch', page: currentPage, per_page: PER_PAGE, ...filters })
    .then(d => {
        if (!d.success) throw new Error(d.error || 'Failed to load data.');
        allData   = d.data;
        renderTable();
        renderPagination(d.page, d.last_page, d.total);
        renderStats(d.stats);
        document.getElementById('resultCount').textContent = `${d.total.toLocaleString()} record${d.total!==1?'s':''}`;
    })
    .catch(err => notify('Error', err.message, 'error'));
}

function loadInventory() {
    api({ action:'get_inventory' })
    .then(d => {
        if (!d.success) return;
        inventoryList = d.data;
        const sel = document.getElementById('f_food_id');
        sel.innerHTML = '<option value="">Select an item…</option>' +
            d.data.map(i => `<option value="${i.food_id}" data-qty="${i.quantity}" data-unit="${esc(i.unit)}" data-price="${i.unit_price}">
                ${esc(i.item_name)} (${fmtNum(i.quantity)} ${esc(i.unit)} available)
            </option>`).join('');
    });
}

/* ════════════════════════════════════════════════════════════════════
   RENDER TABLE
   ════════════════════════════════════════════════════════════════════ */
function renderSkeleton() {
    const tb = document.getElementById('tBody');
    let html = '';
    for (let i=0;i<8;i++) html += `<tr>${Array(10).fill('<td><span class="skeleton-cell"></span></td>').join('')}</tr>`;
    tb.innerHTML = html;
}

function renderTable() {
    const tb = document.getElementById('tBody');
    if (!allData.length) {
        tb.innerHTML = `<tr><td colspan="10"><div class="empty-state"><i class="fas fa-receipt"></i><p>No withdrawal records found.</p></div></td></tr>`;
        return;
    }

    const offset = (currentPage-1)*PER_PAGE;
    let html = '';
    allData.forEach((w, i) => {
        const actions = buildActions(w);
        html += `
        <tr data-id="${w.withdraw_id}">
          <td style="color:#8a9a8b;font-size:.78rem">${offset+i+1}</td>
          <td><strong style="color:#1a237e">${esc(w.item_name)}</strong></td>
          <td><strong>${fmtNum(w.quantity)}</strong> <span style="color:#8a9a8b;font-size:.8rem">${esc(w.unit)}</span></td>
          <td><span class="badge p-${w.purpose}">${purposeLabel(w.purpose)}</span></td>
          <td>${esc(w.requested_by)}</td>
          <td>${w.department ? esc(w.department) : '<span style="color:#bbb">—</span>'}</td>
          <td>${fmtDate(w.withdrawal_date)}</td>
          <td>${fmtCurrency(w.total_value)}</td>
          <td><span class="badge b-${w.status}">${ucf(w.status)}</span></td>
          <td><div class="action-cell">${actions}</div></td>
        </tr>`;
    });
    tb.innerHTML = html;
}

function buildActions(w) {
    let html = `<button class="btn-icon bi-view"   title="View Details" onclick="viewWithdrawal(${w.withdraw_id})"><i class="fas fa-eye"></i></button>`;
    if (w.status === 'pending') {
        html += `<button class="btn-icon bi-edit"    title="Edit"    onclick="editWithdrawal(${w.withdraw_id})"><i class="fas fa-pen"></i></button>`;
        html += `<button class="btn-icon bi-approve" title="Approve" onclick="confirmAction('approve',${w.withdraw_id},'${esc(w.item_name)}')"><i class="fas fa-check"></i></button>`;
        html += `<button class="btn-icon bi-reject"  title="Reject"  onclick="confirmAction('reject',${w.withdraw_id},'${esc(w.item_name)}')"><i class="fas fa-times"></i></button>`;
    }
    if (w.status === 'approved') {
        html += `<button class="btn-icon bi-complete" title="Mark Complete" onclick="confirmAction('complete',${w.withdraw_id},'${esc(w.item_name)}')"><i class="fas fa-flag-checkered"></i></button>`;
        html += `<button class="btn-icon bi-reject"   title="Reject"        onclick="confirmAction('reject',${w.withdraw_id},'${esc(w.item_name)}')"><i class="fas fa-ban"></i></button>`;
    }
    if (w.status !== 'completed') {
        html += `<button class="btn-icon bi-delete" title="Delete" onclick="promptDelete(${w.withdraw_id},'${esc(w.item_name)}')"><i class="fas fa-trash"></i></button>`;
    }
    return html;
}

/* ════════════════════════════════════════════════════════════════════
   STATS
   ════════════════════════════════════════════════════════════════════ */
function renderStats(s) {
    if (!s) return;
    document.getElementById('sTotal').textContent     = s.total     || 0;
    document.getElementById('sPending').textContent   = s.pending   || 0;
    document.getElementById('sApproved').textContent  = s.approved  || 0;
    document.getElementById('sCompleted').textContent = s.completed || 0;
    document.getElementById('sRejected').textContent  = s.rejected  || 0;
    document.getElementById('sValue').textContent     = fmtCurrencyShort(s.total_withdrawn_value || 0);
}

function setFilter(key, val) {
    filters[key] = val;
    const map = { status:'statusFilter', purpose:'purposeFilter' };
    if (map[key]) document.getElementById(map[key]).value = val;
    currentPage = 1; loadData();
}

/* ════════════════════════════════════════════════════════════════════
   PAGINATION
   ════════════════════════════════════════════════════════════════════ */
function renderPagination(page, lastPage, total) {
    const from = Math.min((page-1)*PER_PAGE+1, total);
    const to   = Math.min(page*PER_PAGE, total);
    document.getElementById('pageInfo').textContent = total
        ? `Showing ${from}–${to} of ${total.toLocaleString()}`
        : 'No records';

    const pages = buildPageRange(page, lastPage);
    document.getElementById('pageBtns').innerHTML = [
        `<button class="page-btn" ${page<=1?'disabled':''} onclick="loadData(${page-1})"><i class="fas fa-chevron-left"></i></button>`,
        ...pages.map(p => p==='…'
            ? `<button class="page-btn" disabled>…</button>`
            : `<button class="page-btn ${p===page?'active':''}" onclick="loadData(${p})">${p}</button>`
        ),
        `<button class="page-btn" ${page>=lastPage?'disabled':''} onclick="loadData(${page+1})"><i class="fas fa-chevron-right"></i></button>`,
    ].join('');
}

function buildPageRange(cur, last) {
    if (last<=7) return Array.from({length:last},(_,i)=>i+1);
    const r = new Set([1,last,cur,cur-1,cur+1].filter(x=>x>=1&&x<=last));
    const sorted=[...r].sort((a,b)=>a-b);
    const out=[];
    sorted.forEach((p,i)=>{if(i>0&&p-sorted[i-1]>1)out.push('…');out.push(p);});
    return out;
}

/* ════════════════════════════════════════════════════════════════════
   VIEW
   ════════════════════════════════════════════════════════════════════ */
function viewWithdrawal(id) {
    openModal('viewModal');
    document.getElementById('viewBody').innerHTML = '<div style="text-align:center;padding:40px"><i class="fas fa-spinner fa-spin" style="font-size:2rem;color:var(--blue)"></i></div>';

    api({ action:'get_item', withdraw_id: id })
    .then(d => {
        if (!d.success) throw new Error(d.error);
        const w = d.data;
        document.getElementById('viewBody').innerHTML = `
        <div class="view-header">
          <div class="view-icon"><i class="fas fa-hand-holding"></i></div>
          <div class="view-title">
            <h3>${esc(w.item_name)}</h3>
            <p>${fmtNum(w.quantity)} ${esc(w.unit)} &bull; ${purposeLabel(w.purpose)} &bull; ${fmtDate(w.withdrawal_date)}</p>
            <span class="badge b-${w.status}" style="margin-top:6px">${ucf(w.status)}</span>
          </div>
        </div>
        <div class="detail-grid">
          <div class="detail-row"><div class="d-label">Withdrawal ID</div><div class="d-val">#${w.withdraw_id}</div></div>
          <div class="detail-row"><div class="d-label">Inventory Item</div><div class="d-val">${esc(w.item_name)} (ID #${w.food_id})</div></div>
          <div class="detail-row"><div class="d-label">Quantity Withdrawn</div><div class="d-val"><strong>${fmtNum(w.quantity)} ${esc(w.unit)}</strong></div></div>
          <div class="detail-row"><div class="d-label">Total Value</div><div class="d-val"><strong>${fmtCurrency(w.total_value)}</strong></div></div>
          <div class="detail-row"><div class="d-label">Purpose</div><div class="d-val"><span class="badge p-${w.purpose}">${purposeLabel(w.purpose)}</span></div></div>
          <div class="detail-row"><div class="d-label">Department</div><div class="d-val">${esc(w.department)||'—'}</div></div>
          <div class="detail-row"><div class="d-label">Requested By</div><div class="d-val">${esc(w.requested_by)}</div></div>
          <div class="detail-row"><div class="d-label">Date &amp; Time</div><div class="d-val">${fmtDate(w.withdrawal_date)} at ${w.withdrawal_time||'—'}</div></div>
          <div class="detail-row"><div class="d-label">Status</div><div class="d-val"><span class="badge b-${w.status}">${ucf(w.status)}</span></div></div>
          <div class="detail-row"><div class="d-label">Approved By</div><div class="d-val">${esc(w.approved_by)||'—'}</div></div>
          <div class="detail-row"><div class="d-label">Approval Date</div><div class="d-val">${fmtDate(w.approval_date)}</div></div>
          <div class="detail-row"><div class="d-label">Current Stock</div><div class="d-val">${w.current_stock !== null ? fmtNum(w.current_stock)+' '+esc(w.unit) : '—'}</div></div>
          ${w.notes ? `<div class="detail-row"><div class="d-label">Notes</div><div class="d-val">${esc(w.notes)}</div></div>` : ''}
          <div class="detail-row"><div class="d-label">Created At</div><div class="d-val">${fmtDate(w.created_at)}</div></div>
        </div>
        <div class="form-actions" style="margin-top:20px;padding-top:16px;border-top:1px solid #eef2ee">
          <button class="btn btn-outline" onclick="closeModal('viewModal')"><i class="fas fa-times"></i> Close</button>
          ${w.status==='pending' ? `<button class="btn btn-primary" onclick="closeModal('viewModal');editWithdrawal(${w.withdraw_id})"><i class="fas fa-pen"></i> Edit</button>` : ''}
          ${w.status==='pending' ? `<button class="btn btn-success btn-sm" onclick="closeModal('viewModal');confirmAction('approve',${w.withdraw_id},'${esc(w.item_name)}')"><i class="fas fa-check"></i> Approve</button>` : ''}
        </div>`;
    })
    .catch(err => notify('Error', err.message, 'error'));
}

/* ════════════════════════════════════════════════════════════════════
   ADD / EDIT
   ════════════════════════════════════════════════════════════════════ */
function openAddModal() {
    document.getElementById('f_withdraw_id').value   = '';
    document.getElementById('f_food_id').value       = '';
    document.getElementById('f_quantity').value      = '';
    document.getElementById('f_unit').value          = '';
    document.getElementById('f_purpose').value       = '';
    document.getElementById('f_requested_by').value  = CURRENT_USER;
    document.getElementById('f_department').value    = '';
    document.getElementById('f_withdrawal_date').value = new Date().toISOString().split('T')[0];
    document.getElementById('f_withdrawal_time').value = '12:00';
    document.getElementById('f_notes').value         = '';
    document.getElementById('stockInfo').style.display = 'none';
    document.getElementById('calcBox').style.display   = 'none';
    document.getElementById('editModalTitle').innerHTML = '<i class="fas fa-plus"></i> New Withdrawal';
    selectedUnitPrice = 0;
    openModal('editModal');
}

function editWithdrawal(id) {
    api({ action:'get_item', withdraw_id: id })
    .then(d => {
        if (!d.success) throw new Error(d.error);
        const w = d.data;
        document.getElementById('f_withdraw_id').value     = w.withdraw_id;
        document.getElementById('f_food_id').value         = w.food_id;
        document.getElementById('f_quantity').value        = w.quantity;
        document.getElementById('f_unit').value            = w.unit;
        document.getElementById('f_purpose').value         = w.purpose;
        document.getElementById('f_requested_by').value    = w.requested_by;
        document.getElementById('f_department').value      = w.department||'';
        document.getElementById('f_withdrawal_date').value = w.withdrawal_date||'';
        document.getElementById('f_withdrawal_time').value = w.withdrawal_time||'12:00';
        document.getElementById('f_notes').value           = w.notes||'';
        document.getElementById('editModalTitle').innerHTML = '<i class="fas fa-pen"></i> Edit Withdrawal';
        onItemChange();
        openModal('editModal');
    })
    .catch(err => notify('Error', err.message, 'error'));
}

function onItemChange() {
    const sel  = document.getElementById('f_food_id');
    const opt  = sel.options[sel.selectedIndex];
    const box  = document.getElementById('stockInfo');
    const calc = document.getElementById('calcBox');
    if (!sel.value) { box.style.display='none'; calc.style.display='none'; return; }

    const qty   = parseFloat(opt.dataset.qty   || 0);
    const unit  = opt.dataset.unit  || '';
    const price = parseFloat(opt.dataset.price || 0);
    selectedUnitPrice = price;

    document.getElementById('f_unit').value = unit;

    /* stock status */
    box.style.display = 'block';
    if (qty <= 0) {
        box.className = 'stock-info out';
        box.innerHTML = '<i class="fas fa-times-circle"></i> Out of stock — cannot withdraw';
    } else if (qty < 5) {
        box.className = 'stock-info low';
        box.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Low stock: ${fmtNum(qty)} ${esc(unit)} available`;
    } else {
        box.className = 'stock-info ok';
        box.innerHTML = `<i class="fas fa-check-circle"></i> ${fmtNum(qty)} ${esc(unit)} in stock`;
    }

    calc.style.display = 'block';
    document.getElementById('calcUnitPrice').textContent = fmtCurrency(price);
    calcValue();
}

function calcValue() {
    const qty   = parseFloat(document.getElementById('f_quantity').value || 0);
    const price = selectedUnitPrice;
    const total = qty * price;
    document.getElementById('calcQty').textContent   = fmtNum(qty);
    document.getElementById('calcTotal').textContent = fmtCurrency(total);
    if (document.getElementById('f_food_id').value) {
        document.getElementById('calcBox').style.display = 'block';
    }
}

function saveWithdrawal() {
    const id       = document.getElementById('f_withdraw_id').value;
    const isUpdate = !!id;
    const qty      = parseFloat(document.getElementById('f_quantity').value || 0);
    const price    = selectedUnitPrice;

    const payload = {
        action:          isUpdate ? 'update' : 'add',
        csrf_token:      CSRF,
        withdraw_id:     id,
        food_id:         document.getElementById('f_food_id').value,
        quantity:        document.getElementById('f_quantity').value,
        purpose:         document.getElementById('f_purpose').value,
        requested_by:    document.getElementById('f_requested_by').value.trim(),
        department:      document.getElementById('f_department').value.trim(),
        withdrawal_date: document.getElementById('f_withdrawal_date').value,
        withdrawal_time: document.getElementById('f_withdrawal_time').value,
        notes:           document.getElementById('f_notes').value.trim(),
        total_value:     (qty * price).toFixed(2),
    };

    if (!payload.food_id)       { notify('Validation', 'Please select an inventory item.', 'warning'); return; }
    if (!payload.quantity || isNaN(payload.quantity) || parseFloat(payload.quantity)<=0) { notify('Validation', 'Quantity must be greater than zero.', 'warning'); return; }
    if (!payload.purpose)       { notify('Validation', 'Please select a purpose.', 'warning'); return; }
    if (!payload.requested_by)  { notify('Validation', '"Requested by" is required.', 'warning'); return; }
    if (!payload.withdrawal_date){ notify('Validation', 'Withdrawal date is required.', 'warning'); return; }

    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    api(payload)
    .then(d => {
        if (!d.success) throw new Error(d.error);
        notify('Saved', d.message, 'success');
        closeModal('editModal');
        loadData();
        loadInventory(); // refresh stock levels
    })
    .catch(err => notify('Error', err.message, 'error'))
    .finally(() => { btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Save Withdrawal'; });
}

/* ════════════════════════════════════════════════════════════════════
   APPROVAL WORKFLOW
   ════════════════════════════════════════════════════════════════════ */
const ACTION_CONFIG = {
    approve:  { head:'success', icon:'fas fa-check-circle', title:'Approve Withdrawal',   confirmClass:'btn-success', confirmLabel:'Approve',  verb:'approve' },
    reject:   { head:'danger',  icon:'fas fa-ban',          title:'Reject Withdrawal',    confirmClass:'btn-danger',  confirmLabel:'Reject',   verb:'reject'  },
    complete: { head:'info',    icon:'fas fa-flag-checkered', title:'Complete Withdrawal', confirmClass:'btn-primary', confirmLabel:'Complete', verb:'complete' },
};

function confirmAction(action, id, name) {
    const cfg = ACTION_CONFIG[action];
    if (!cfg) return;
    dlgCb = () => doAction(action, id);
    document.getElementById('dlgHead').className = `dialog-head ${cfg.head}`;
    document.getElementById('dlgIcon').className = cfg.icon;
    document.getElementById('dlgTitle').textContent = cfg.title;
    document.getElementById('dlgMsg').innerHTML   = `Confirm ${cfg.verb} withdrawal of <strong>${esc(name)}</strong>?`;
    document.getElementById('dlgConfirmBtn').className = `btn ${cfg.confirmClass}`;
    document.getElementById('dlgConfirmBtn').innerHTML = `<i class="${cfg.icon}"></i> ${cfg.confirmLabel}`;
    document.getElementById('confirmDlg').classList.add('active');
}

function doAction(action, id) {
    api({ action, withdraw_id: id })
    .then(d => {
        if (!d.success) throw new Error(d.error);
        notify(ucf(action) + 'd', d.message, 'success');
        loadData();
        loadInventory();
    })
    .catch(err => notify('Error', err.message, 'error'));
}

/* ════════════════════════════════════════════════════════════════════
   DELETE
   ════════════════════════════════════════════════════════════════════ */
function promptDelete(id, name) {
    dlgCb = () => doDelete(id);
    document.getElementById('dlgHead').className = 'dialog-head danger';
    document.getElementById('dlgIcon').className = 'fas fa-trash';
    document.getElementById('dlgTitle').textContent = 'Delete Withdrawal';
    document.getElementById('dlgMsg').innerHTML = `Permanently delete withdrawal of <strong>${esc(name)}</strong>?<br>The quantity will be restored to inventory.`;
    document.getElementById('dlgConfirmBtn').className = 'btn btn-danger';
    document.getElementById('dlgConfirmBtn').innerHTML = '<i class="fas fa-trash"></i> Delete';
    document.getElementById('confirmDlg').classList.add('active');
}

function doDelete(id) {
    api({ action:'delete', withdraw_id: id })
    .then(d => {
        if (!d.success) throw new Error(d.error);
        notify('Deleted', d.message, 'success');
        loadData();
        loadInventory();
    })
    .catch(err => notify('Error', err.message, 'error'));
}

/* ════════════════════════════════════════════════════════════════════
   EXPORTS
   ════════════════════════════════════════════════════════════════════ */
function exportToPDF() {
    if (!allData.length) { notify('Empty', 'No data to export.', 'warning'); return; }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');
    doc.setFontSize(16); doc.setTextColor(0,60,143);
    doc.text('Food Withdrawals Report', 14, 18);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text('Generated: ' + new Date().toLocaleString('en-UG'), 14, 25);
    doc.autoTable({
        head:[['#','Item','Qty','Purpose','Requested By','Dept','Date','Value','Status']],
        body: allData.map((w,i)=>[
            i+1, w.item_name, `${fmtNum(w.quantity)} ${w.unit}`,
            purposeLabel(w.purpose), w.requested_by, w.department||'—',
            fmtDate(w.withdrawal_date), 'UGX '+fmtNum(w.total_value), ucf(w.status)
        ]),
        startY:30, theme:'grid',
        headStyles:{fillColor:[0,60,143],fontSize:7.5},
        bodyStyles:{fontSize:7},
    });
    doc.save('food-withdrawals-' + datestamp() + '.pdf');
    notify('Exported','PDF downloaded.','success');
}

function exportToExcel() {
    if (!allData.length) { notify('Empty', 'No data to export.', 'warning'); return; }
    const headers=['ID','Item','Qty','Unit','Purpose','Requested By','Department','Date','Time','Total Value (UGX)','Status','Approved By','Approval Date','Notes'];
    const rows=allData.map(w=>[
        w.withdraw_id, w.item_name, parseFloat(w.quantity), w.unit,
        purposeLabel(w.purpose), w.requested_by, w.department||'',
        w.withdrawal_date||'', w.withdrawal_time||'', parseFloat(w.total_value),
        ucf(w.status), w.approved_by||'', w.approval_date||'', w.notes||''
    ]);
    const wb=XLSX.utils.book_new();
    const ws=XLSX.utils.aoa_to_sheet([headers,...rows]);
    ws['!cols']=[{wch:6},{wch:22},{wch:8},{wch:8},{wch:14},{wch:18},{wch:14},{wch:12},{wch:8},{wch:14},{wch:10},{wch:16},{wch:16},{wch:24}];
    XLSX.utils.book_append_sheet(wb,ws,'Withdrawals');
    XLSX.writeFile(wb,'food-withdrawals-'+datestamp()+'.xlsx');
    notify('Exported','Excel downloaded.','success');
}

/* ════════════════════════════════════════════════════════════════════
   HELPERS
   ════════════════════════════════════════════════════════════════════ */
function clearFilters() {
    filters = { search:'', status:'', purpose:'', date_from:'', date_to:'' };
    document.getElementById('searchInput').value    = '';
    document.getElementById('statusFilter').value  = '';
    document.getElementById('purposeFilter').value = '';
    document.getElementById('dateFrom').value       = '';
    document.getElementById('dateTo').value         = '';
    currentPage = 1; loadData();
}

function purposeLabel(p) {
    const map={cooking:'Cooking',staff_meal:'Staff Meal',student_meal:'Student Meal',
               event:'Event',waste:'Waste',sample:'Sample',transfer:'Transfer',other:'Other'};
    return map[p] || ucf(p);
}

function api(payload) {
    return fetch(location.pathname, {
        method:'POST',
        headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({...payload, csrf_token: payload.csrf_token || CSRF }),
    }).then(r=>r.json());
}

function esc(v){ return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function ucf(v){ return v ? v.charAt(0).toUpperCase()+v.slice(1) : ''; }
function fmtNum(v){ return parseFloat(v||0).toLocaleString('en-UG',{maximumFractionDigits:2}); }
function fmtCurrency(v){ return 'UGX '+parseFloat(v||0).toLocaleString('en-UG',{minimumFractionDigits:0,maximumFractionDigits:0}); }
function fmtCurrencyShort(v){ const n=parseFloat(v||0); if(n>=1_000_000)return(n/1_000_000).toFixed(1)+'M'; if(n>=1_000)return(n/1_000).toFixed(0)+'K'; return n.toFixed(0); }
function fmtDate(d){ if(!d)return '—'; try{return new Date(d).toLocaleDateString('en-UG',{day:'2-digit',month:'short',year:'numeric'});}catch(_){return d;} }
function datestamp(){ return new Date().toISOString().split('T')[0]; }

/* Dialog / Modal helpers */
function closeDlg(){ document.getElementById('confirmDlg').classList.remove('active'); dlgCb=null; }
function runDlgCallback(){ if(dlgCb)dlgCb(); closeDlg(); }
function openModal(id){ document.getElementById(id).classList.add('active'); }
function closeModal(id){ document.getElementById(id).classList.remove('active'); }
function closeAllModals(){ document.querySelectorAll('.modal.active,.dialog.active').forEach(m=>m.classList.remove('active')); }
function modalOutsideClick(e,id){ if(e.target.id===id)closeModal(id); }

function notify(title,msg,type='success',dur=4500){
    const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
    const n=document.createElement('div');
    n.className=`notif ${type}`;
    n.innerHTML=`<i class="fas ${icons[type]||icons.info} notif-icon"></i>
      <div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div>
      <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
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
