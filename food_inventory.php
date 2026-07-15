<?php
/**
 * Food Inventory Management
 * Production-grade: CSRF protection, prepared statements, input validation,
 * JSON API for all mutations, paginated data-fetch endpoint.
 */
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction('Food Inventory');

/* ── Security Headers ───────────────────────────────────────────────── */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

/* ── Ensure mysqli throws exceptions on errors ──────────────────────── */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ── CSRF ───────────────────────────────────────────────────────────────── */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* ── JSON API  (all mutations + data fetch) ─────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $raw    = file_get_contents('php://input');
    $body   = json_decode($raw, true) ?? [];
    $action = $body['action'] ?? ($_POST['action'] ?? '');

    /* CSRF check */
    $token = $body['csrf_token'] ?? ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], (string)$token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }

    switch ($action) {
        case 'fetch':    echo json_encode(apiFetch($conn, $body));  break;
        case 'add':      echo json_encode(apiSave($conn, $body, false)); break;
        case 'update':   echo json_encode(apiSave($conn, $body, true));  break;
        case 'delete':   echo json_encode(apiDelete($conn, $body)); break;
        case 'get_item': echo json_encode(apiGetItem($conn, $body)); break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    }
    exit;
}

/* ════════════════════════════════════════════════════════════════════════════
   API FUNCTIONS
   ════════════════════════════════════════════════════════════════════════════ */

function apiFetch(mysqli $conn, array $p): array
{
    $page     = max(1, (int)($p['page'] ?? 1));
    $perPage  = min(100, max(10, (int)($p['per_page'] ?? 25)));
    $search   = trim($p['search'] ?? '');
    $category = trim($p['category'] ?? '');
    $location = trim($p['location'] ?? '');
    $stock    = trim($p['stock'] ?? '');          // all | low | out | expiring | expired

    $where  = ['1=1'];
    $params = [];
    $types  = '';

    if ($search !== '') {
        $where[]  = '(item_name LIKE ? OR supplier LIKE ? OR description LIKE ?)';
        $like     = "%{$search}%";
        $params   = array_merge($params, [$like, $like, $like]);
        $types   .= 'sss';
    }
    if ($category !== '') {
        $validCats = [
            'vegetables','fruits','meat','dairy','grains','spices','beverages','frozen',
            'flour','oils','legumes','condiments','canned','eggs','baking','cereals',
        ];
        if (!in_array($category, $validCats, true)) $category = '';
    }
    if ($category !== '') {
        $where[]  = 'category = ?';
        $params[] = $category;
        $types   .= 's';
    }
    if ($location !== '') {
        $validLocs = [
            'refrigerator','freezer','pantry','dry-storage','cool-room','warehouse',
            'shelf','storeroom','bulk-storage','cabinet','outdoor-storage',
        ];
        if (!in_array($location, $validLocs, true)) $location = '';
    }
    if ($location !== '') {
        $where[]  = 'storage_location = ?';
        $params[] = $location;
        $types   .= 's';
    }
    switch ($stock) {
        case 'low':      $where[] = 'quantity > 0 AND quantity <= min_stock_level'; break;
        case 'out':      $where[] = 'quantity = 0'; break;
        case 'expiring': $where[] = 'expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)'; break;
        case 'expired':  $where[] = 'expiry_date < CURDATE()'; break;
    }

    $whereSQL = implode(' AND ', $where);

    /* total count */
    $countSQL  = "SELECT COUNT(*) AS n FROM food_inventory WHERE {$whereSQL}";
    $stmt      = $conn->prepare($countSQL);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();

    /* data */
    $offset  = ($page - 1) * $perPage;
    $dataSQL = "SELECT * FROM food_inventory WHERE {$whereSQL}
                ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $stmt    = $conn->prepare($dataSQL);
    $allParams = array_merge($params, [$perPage, $offset]);
    $allTypes  = $types . 'ii';
    $stmt->bind_param($allTypes, ...$allParams);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /* stats */
    $stats = [];
    $sRes  = $conn->query("
        SELECT
            COUNT(*)                                                    AS total_items,
            COALESCE(SUM(quantity * unit_price), 0)                     AS total_value,
            SUM(quantity = 0)                                           AS out_of_stock,
            SUM(quantity > 0 AND quantity <= min_stock_level)           AS low_stock,
            SUM(expiry_date < CURDATE())                                AS expired,
            SUM(expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AS expiring_soon
        FROM food_inventory
    ");
    if ($sRes) $stats = $sRes->fetch_assoc();

    return [
        'success'    => true,
        'data'       => $rows,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'last_page'  => max(1, (int)ceil($total / $perPage)),
        'stats'      => $stats,
    ];
}

function apiGetItem(mysqli $conn, array $p): array
{
    $id   = (int)($p['food_id'] ?? 0);
    if (!$id) return ['success' => false, 'error' => 'Invalid ID.'];

    $stmt = $conn->prepare('SELECT * FROM food_inventory WHERE food_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row  = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row
        ? ['success' => true, 'data' => $row]
        : ['success' => false, 'error' => 'Item not found.'];
}

function apiSave(mysqli $conn, array $p, bool $isUpdate): array
{
    /* ── Validate ── */
    $errors = [];
    $name        = trim($p['item_name']       ?? '');
    $category    = trim($p['category']        ?? '');
    $quantity    = $p['quantity']             ?? null;
    $unit        = trim($p['unit']            ?? '');
    $unitPrice   = $p['unit_price']           ?? null;
    $supplier    = trim($p['supplier']        ?? '');
    $purchaseDate= trim($p['purchase_date']   ?? '');
    $expiryDate  = trim($p['expiry_date']     ?? '') ?: null;
    $minStock    = $p['min_stock_level']      ?? 5;
    $location    = trim($p['storage_location']?? '');
    $description = trim($p['description']     ?? '');

    $validCategories = [
        'vegetables','fruits','meat','dairy','grains','spices','beverages','frozen',
        'flour','oils','legumes','condiments','canned','eggs','baking','cereals',
    ];
    $validLocations  = [
        'refrigerator','freezer','pantry','dry-storage','cool-room','warehouse',
        'shelf','storeroom','bulk-storage','cabinet','outdoor-storage',
    ];

    if ($name === '')                                      $errors[] = 'Item name is required.';
    if (strlen($name) > 255)                              $errors[] = 'Item name too long (max 255 chars).';
    if (!in_array($category, $validCategories, true))     $errors[] = 'Invalid category.';
    if (!is_numeric($quantity) || (float)$quantity < 0)   $errors[] = 'Quantity must be a non-negative number.';
    if ($unit === '')                                      $errors[] = 'Unit is required.';
    if (!is_numeric($unitPrice) || (float)$unitPrice < 0) $errors[] = 'Unit price must be a non-negative number.';
    if (!in_array($location, $validLocations, true) && $location !== '') {
        $errors[] = 'Invalid storage location.';
    }
    if ($expiryDate && $purchaseDate && $expiryDate < $purchaseDate) {
        $errors[] = 'Expiry date cannot be before purchase date.';
    }

    if ($errors) return ['success' => false, 'error' => implode(' ', $errors)];

    $qty      = (float)$quantity;
    $price    = (float)$unitPrice;
    $minS     = (float)$minStock;

    if ($isUpdate) {
        $id = (int)($p['food_id'] ?? 0);
        if (!$id) return ['success' => false, 'error' => 'Invalid item ID.'];

        $stmt = $conn->prepare("
            UPDATE food_inventory
               SET item_name=?, category=?, quantity=?, unit=?, unit_price=?,
                   supplier=?, purchase_date=?, expiry_date=?, min_stock_level=?,
                   storage_location=?, description=?
             WHERE food_id=?
        ");
        $stmt->bind_param('ssdsdsssdssi',
            $name, $category, $qty, $unit, $price,
            $supplier, $purchaseDate, $expiryDate, $minS,
            $location, $description, $id
        );
        $stmt->execute();
        if ($stmt->error) return ['success' => false, 'error' => 'Update failed.'];
        if ($stmt->affected_rows === 0) {
            $stmt->close();
            /* Could be 0 because no values changed (valid) OR because ID doesn't exist.
               Re-check existence to give an accurate response. */
            $exist = $conn->prepare('SELECT food_id FROM food_inventory WHERE food_id = ?');
            $exist->bind_param('i', $id); $exist->execute();
            $found = $exist->get_result()->num_rows > 0;
            $exist->close();
            if (!$found) return ['success' => false, 'error' => 'Item not found.'];
        } else {
            $stmt->close();
        }
        return ['success' => true, 'message' => "'{$name}' updated successfully."];
    } else {
        /* check duplicate name */
        $chk = $conn->prepare('SELECT food_id FROM food_inventory WHERE item_name = ?');
        $chk->bind_param('s', $name);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $chk->close();
            return ['success' => false, 'error' => "An item named '{$name}' already exists."];
        }
        $chk->close();

        $stmt = $conn->prepare("
            INSERT INTO food_inventory
                (item_name, category, quantity, unit, unit_price, supplier,
                 purchase_date, expiry_date, min_stock_level, storage_location, description)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        // types: s s d s d s s s d s s  (11 params)
        $stmt->bind_param('ssdsdsssdss',
            $name, $category, $qty, $unit, $price,
            $supplier, $purchaseDate, $expiryDate, $minS,
            $location, $description
        );
        $stmt->execute();
        if ($stmt->insert_id < 1) return ['success' => false, 'error' => 'Insert failed: ' . $conn->error];
        $newId = $stmt->insert_id;
        $stmt->close();
        return ['success' => true, 'message' => "'{$name}' added to inventory.", 'food_id' => $newId];
    }
}

function apiDelete(mysqli $conn, array $p): array
{
    $id = (int)($p['food_id'] ?? 0);
    if (!$id) return ['success' => false, 'error' => 'Invalid ID.'];

    /* check for pending withdrawals */
    $chk = $conn->prepare("SELECT COUNT(*) AS n FROM food_withdrawals WHERE food_id = ? AND status IN ('pending','approved')");
    $chk->bind_param('i', $id);
    $chk->execute();
    $pending = (int)$chk->get_result()->fetch_assoc()['n'];
    $chk->close();
    if ($pending > 0) {
        return ['success' => false, 'error' => 'Cannot delete: this item has pending/approved withdrawal requests.'];
    }

    $stmt = $conn->prepare('DELETE FROM food_inventory WHERE food_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    return $affected > 0
        ? ['success' => true,  'message' => 'Item deleted from inventory.']
        : ['success' => false, 'error'   => 'Item not found.'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Food Inventory &mdash; School Pilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
<style>
/* ── Variables ─────────────────────────────────────────────────────── */
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--orange:#e65100;--blue:#1565c0;--gray:#546e7a;
  --amber:#f57f17;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);
  --shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --transition:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}
a{color:inherit;text-decoration:none}

/* ── Layout ────────────────────────────────────────────────────────── */
.page{max-width:100%;margin:0 auto;padding:24px 20px 48px}

/* ── Page Header ───────────────────────────────────────────────────── */
.page-header{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--radius-lg);padding:28px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;margin-bottom:24px;margin-top:40px;box-shadow:var(--shadow-lg)}
.page-header h1{color:#fff;font-size:1.55rem;font-weight:700;letter-spacing:.3px}
.page-header p{color:rgba(255,255,255,.78);font-size:.9rem;margin-top:3px}
.stats-row{display:flex;gap:12px;flex-wrap:wrap}
.stat-pill{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:40px;padding:8px 18px;text-align:center;min-width:80px;cursor:pointer;transition:background var(--transition)}
.stat-pill:hover,.stat-pill.active{background:rgba(255,255,255,.26)}
.stat-pill .n{font-size:1.35rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.72rem;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.5px}
.stat-pill.warn .n{color:#ffe082}
.stat-pill.danger .n{color:#ef9a9a}

/* ── Card ──────────────────────────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden}

/* ── Toolbar ───────────────────────────────────────────────────────── */
.toolbar{padding:18px 24px;border-bottom:1px solid #e8ede9;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.toolbar-left{display:flex;flex-wrap:wrap;gap:10px;align-items:center;flex:1 1 auto}
.toolbar-right{display:flex;gap:10px;align-items:center;flex-shrink:0}
.search-wrap{position:relative;min-width:220px}
.search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.85rem}
.search-wrap input{width:100%;padding:9px 12px 9px 34px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;transition:border-color var(--transition),box-shadow var(--transition)}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.filter-select{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;background:#fff;cursor:pointer;min-width:130px;transition:border-color var(--transition)}
.filter-select:focus{outline:none;border-color:var(--g600)}
.result-count{font-size:.8rem;color:#6b7c6d;white-space:nowrap}

/* ── Buttons ───────────────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all var(--transition);white-space:nowrap}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn:disabled{opacity:.5;cursor:not-allowed;transform:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-pdf{background:#c62828;color:#fff}.btn-pdf:hover{background:var(--red)}
.btn-excel{background:var(--g800);color:#fff}.btn-excel:hover{background:var(--g900)}
.btn-danger{background:var(--red);color:#fff}.btn-danger:hover{background:#b71c1c}

/* ── Action Buttons (table) ────────────────────────────────────────── */
.action-cell{display:flex;gap:5px;align-items:center}
.btn-icon{width:30px;height:30px;border:none;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;transition:all var(--transition);flex-shrink:0}
.btn-icon:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.18)}
.bi-view{background:#e3f2fd;color:#1565c0}.bi-view:hover{background:#1565c0;color:#fff}
.bi-edit{background:#fff3e0;color:#e65100}.bi-edit:hover{background:#e65100;color:#fff}
.bi-delete{background:#ffebee;color:var(--red)}.bi-delete:hover{background:var(--red);color:#fff}

/* ── Table ─────────────────────────────────────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:13px 14px;text-align:left;font-size:.8rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--transition)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:12px 14px;font-size:.875rem;vertical-align:middle}

/* ── Category & Status Badges ──────────────────────────────────────── */
.badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.b-vegetables{background:#e8f5e9;color:#2e7d32}
.b-fruits{background:#fff3e0;color:#e65100}
.b-meat{background:#ffebee;color:#c62828}
.b-dairy{background:#e3f2fd;color:#1565c0}
.b-grains{background:#f3e5f5;color:#6a1b9a}
.b-spices{background:#fff8e1;color:#f57f17}
.b-beverages{background:#e0f7fa;color:#006064}
.b-frozen{background:#e8eaf6;color:#283593}
.b-flour{background:#efebe9;color:#4e342e}
.b-oils{background:#fff8e1;color:#e65100}
.b-legumes{background:#f1f8e9;color:#558b2f}
.b-condiments{background:#fce4ec;color:#ad1457}
.b-canned{background:#eceff1;color:#37474f}
.b-eggs{background:#fffde7;color:#f9a825}
.b-baking{background:#fbe9e7;color:#bf360c}
.b-cereals{background:#fff3e0;color:#ef6c00}
.b-ok{background:#e8f5e9;color:#2e7d32}
.b-low{background:#fff3e0;color:#e65100}
.b-out{background:#ffebee;color:#c62828}
.b-expiring{background:#fff8e1;color:#f57f17}
.b-expired{background:#fce4ec;color:#880e4f}

/* ── Skeleton ──────────────────────────────────────────────────────── */
.skeleton-cell{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;height:14px;display:inline-block;width:80%}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ── Empty State ───────────────────────────────────────────────────── */
.empty-state{text-align:center;padding:60px 20px;color:#8a9a8b}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;opacity:.45}
.empty-state p{font-size:.95rem}

/* ── Pagination ────────────────────────────────────────────────────── */
.pagination{padding:16px 24px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e8ede9;flex-wrap:wrap;gap:10px}
.page-info{font-size:.82rem;color:#6b7c6d}
.page-btns{display:flex;gap:4px}
.page-btn{width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:6px;background:#fff;cursor:pointer;font-size:.82rem;font-weight:600;color:#444;display:flex;align-items:center;justify-content:center;transition:all var(--transition)}
.page-btn:hover:not(:disabled){border-color:var(--g600);background:var(--g100);color:var(--g800)}
.page-btn.active{background:var(--g700);border-color:var(--g700);color:#fff}
.page-btn:disabled{opacity:.38;cursor:default}

/* ── Modal ─────────────────────────────────────────────────────────── */
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

/* ── Form ──────────────────────────────────────────────────────────── */
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
.field-hint{font-size:.75rem;color:#8a9a8b;margin-top:2px}

/* ── View Modal Details ────────────────────────────────────────────── */
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid #e8ede9;border-radius:var(--radius)}
.detail-row{display:contents}
.detail-row:not(:last-child) .d-label,.detail-row:not(:last-child) .d-val{border-bottom:1px solid #e8ede9}
.d-label{padding:10px 14px;font-size:.75rem;font-weight:700;color:var(--g700);text-transform:uppercase;letter-spacing:.4px;background:#f5fbf5;border-right:1px solid #e8ede9}
.d-val{padding:10px 14px;font-size:.875rem;color:#333}
.view-header{display:flex;align-items:center;gap:16px;margin-bottom:22px;padding-bottom:18px;border-bottom:2px solid var(--g100)}
.view-icon{width:56px;height:56px;border-radius:var(--radius-lg);background:var(--g100);display:flex;align-items:center;justify-content:center;font-size:1.6rem;color:var(--g700);flex-shrink:0}
.view-title h3{font-size:1.2rem;font-weight:700;color:var(--g800)}
.view-title p{font-size:.85rem;color:#6b7c6d;margin-top:3px}

/* ── Confirm Dialog ────────────────────────────────────────────────── */
.dialog{display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);align-items:center;justify-content:center}
.dialog.active{display:flex}
.dialog-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:420px;box-shadow:var(--shadow-lg);animation:slideDown .22s ease;overflow:hidden}
.dialog-head{padding:18px 22px;display:flex;align-items:center;gap:12px;color:#fff}
.dialog-head.danger{background:linear-gradient(135deg,#c62828,#ef5350)}
.dialog-head.warning{background:linear-gradient(135deg,#e65100,#ff7043)}
.dialog-head.info{background:linear-gradient(135deg,var(--g800),var(--g600))}
.dialog-head i{font-size:1.2rem}
.dialog-head h3{font-size:1rem;font-weight:700}
.dialog-body{padding:22px;text-align:center}
.dialog-body p{font-size:.9rem;color:#555;line-height:1.55;margin-bottom:20px}
.dialog-actions{display:flex;gap:10px;justify-content:center}

/* ── Notifications ─────────────────────────────────────────────────── */
#notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.notif{background:#fff;border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow-lg);display:flex;align-items:flex-start;gap:12px;border-left:4px solid var(--g600);animation:notifIn .3s ease}
.notif.error{border-left-color:var(--red)}.notif.warning{border-left-color:#e65100}.notif.info{border-left-color:var(--blue)}
@keyframes notifIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.notif-icon{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.notif.success .notif-icon{color:var(--g700)}.notif.error .notif-icon{color:var(--red)}.notif.warning .notif-icon{color:#e65100}.notif.info .notif-icon{color:var(--blue)}
.notif-body{flex:1}.notif-title{font-weight:700;font-size:.85rem;margin-bottom:2px}.notif-msg{font-size:.8rem;color:#666}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:1rem;padding:0;line-height:1;flex-shrink:0}

/* ── Stock level row tinting ───────────────────────────────────────── */
tr.row-expired td{background:#fff5f5}
tr.row-low td{background:#fffbf0}
tr.row-out td{background:#fff0f0}

/* ── Responsive ────────────────────────────────────────────────────── */
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
      <h1><i class="fas fa-boxes-stacked" style="margin-right:10px;opacity:.85"></i>Food Inventory</h1>
      <p>Track stock levels, expiry dates and inventory value in real-time</p>
    </div>
    <div class="stats-row">
      <div class="stat-pill" onclick="setStockFilter('')"  id="pill-all">     <span class="n" id="sTotal">—</span><span class="l">Items</span></div>
      <div class="stat-pill" onclick="setStockFilter('out')" id="pill-out">   <span class="n danger" id="sOut">—</span><span class="l">Out of Stock</span></div>
      <div class="stat-pill warn" onclick="setStockFilter('low')" id="pill-low"><span class="n" id="sLow">—</span><span class="l">Low Stock</span></div>
      <div class="stat-pill warn" onclick="setStockFilter('expiring')" id="pill-exp"><span class="n" id="sExpiring">—</span><span class="l">Expiring</span></div>
      <div class="stat-pill danger" onclick="setStockFilter('expired')" id="pill-xpd"><span class="n" id="sExpired">—</span><span class="l">Expired</span></div>
      <div class="stat-pill" style="cursor:default"><span class="n" id="sValue" style="font-size:1rem">—</span><span class="l">Value (UGX)</span></div>
    </div>
  </div>

  <!-- Main Card -->
  <div class="card">
    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search item, supplier…" autocomplete="off">
        </div>
        <select id="categoryFilter" class="filter-select">
          <option value="">All Categories</option>
          <optgroup label="Fresh Produce">
            <option value="vegetables">Vegetables</option>
            <option value="fruits">Fruits</option>
            <option value="eggs">Eggs</option>
          </optgroup>
          <optgroup label="Proteins">
            <option value="meat">Meat &amp; Poultry</option>
            <option value="legumes">Legumes &amp; Pulses</option>
            <option value="dairy">Dairy</option>
          </optgroup>
          <optgroup label="Dry Goods">
            <option value="grains">Grains &amp; Rice</option>
            <option value="flour">Flour</option>
            <option value="cereals">Cereals &amp; Porridge</option>
            <option value="baking">Baking Supplies</option>
          </optgroup>
          <optgroup label="Pantry">
            <option value="oils">Oils &amp; Fats</option>
            <option value="condiments">Condiments &amp; Sauces</option>
            <option value="spices">Spices &amp; Herbs</option>
            <option value="canned">Canned Goods</option>
          </optgroup>
          <optgroup label="Other">
            <option value="beverages">Beverages</option>
            <option value="frozen">Frozen</option>
          </optgroup>
        </select>
        <select id="locationFilter" class="filter-select">
          <option value="">All Locations</option>
          <optgroup label="Cold Storage">
            <option value="refrigerator">Refrigerator</option>
            <option value="freezer">Freezer</option>
            <option value="cool-room">Cool Room</option>
          </optgroup>
          <optgroup label="Dry Storage">
            <option value="pantry">Pantry</option>
            <option value="dry-storage">Dry Storage</option>
            <option value="shelf">Shelf</option>
            <option value="cabinet">Cabinet</option>
          </optgroup>
          <optgroup label="Large Storage">
            <option value="warehouse">Warehouse</option>
            <option value="storeroom">Storeroom</option>
            <option value="bulk-storage">Bulk Storage</option>
            <option value="outdoor-storage">Outdoor Storage</option>
          </optgroup>
        </select>
        <button class="btn btn-outline" id="clearFiltersBtn"><i class="fas fa-times-circle"></i> Clear</button>
        <span class="result-count" id="resultCount"></span>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-pdf"   onclick="exportToPDF()"><i class="fas fa-file-pdf"></i> PDF</button>
        <button class="btn btn-excel" onclick="exportToExcel()"><i class="fas fa-file-excel"></i> Excel</button>
        <button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Item</button>
      </div>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <table id="invTable">
        <thead>
          <tr>
            <th style="width:40px">#</th>
            <th>Item Name</th>
            <th>Category</th>
            <th>Quantity</th>
            <th>Unit Price</th>
            <th>Total Value</th>
            <th>Location</th>
            <th>Expiry Date</th>
            <th>Stock Status</th>
            <th style="width:110px">Actions</th>
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
      <h2><i class="fas fa-box-open"></i> Item Details</h2>
      <button class="modal-close" onclick="closeModal('viewModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="viewBody"></div>
  </div>
</div>

<!-- ══ ADD / EDIT MODAL ═════════════════════════════════════════════ -->
<div id="editModal" class="modal" onclick="modalOutsideClick(event,'editModal')">
  <div class="modal-box">
    <div class="modal-head">
      <h2 id="editModalTitle"><i class="fas fa-pen"></i> Add Inventory Item</h2>
      <button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="f_food_id">

      <div class="form-section">
        <div class="form-section-title"><i class="fas fa-tag"></i> Item Information</div>
        <div class="form-grid">
          <div class="form-group full">
            <label>Item Name <span class="req">*</span></label>
            <input type="text" id="f_item_name" class="form-control" placeholder="e.g. Tomatoes" maxlength="255" required>
          </div>
          <div class="form-group">
            <label>Category <span class="req">*</span></label>
            <select id="f_category" class="form-control" required>
              <option value="">Select category…</option>
              <optgroup label="Fresh Produce">
                <option value="vegetables">Vegetables</option>
                <option value="fruits">Fruits</option>
                <option value="eggs">Eggs</option>
              </optgroup>
              <optgroup label="Proteins">
                <option value="meat">Meat &amp; Poultry</option>
                <option value="legumes">Legumes &amp; Pulses</option>
                <option value="dairy">Dairy</option>
              </optgroup>
              <optgroup label="Dry Goods">
                <option value="grains">Grains &amp; Rice</option>
                <option value="flour">Flour</option>
                <option value="cereals">Cereals &amp; Porridge</option>
                <option value="baking">Baking Supplies</option>
              </optgroup>
              <optgroup label="Pantry">
                <option value="oils">Oils &amp; Fats</option>
                <option value="condiments">Condiments &amp; Sauces</option>
                <option value="spices">Spices &amp; Herbs</option>
                <option value="canned">Canned Goods</option>
              </optgroup>
              <optgroup label="Other">
                <option value="beverages">Beverages</option>
                <option value="frozen">Frozen</option>
              </optgroup>
            </select>
          </div>
          <div class="form-group">
            <label>Storage Location</label>
            <select id="f_storage_location" class="form-control">
              <option value="">Select location…</option>
              <optgroup label="Cold Storage">
                <option value="refrigerator">Refrigerator</option>
                <option value="freezer">Freezer</option>
                <option value="cool-room">Cool Room</option>
              </optgroup>
              <optgroup label="Dry Storage">
                <option value="pantry">Pantry</option>
                <option value="dry-storage">Dry Storage</option>
                <option value="shelf">Shelf</option>
                <option value="cabinet">Cabinet</option>
              </optgroup>
              <optgroup label="Large Storage">
                <option value="warehouse">Warehouse</option>
                <option value="storeroom">Storeroom</option>
                <option value="bulk-storage">Bulk Storage</option>
                <option value="outdoor-storage">Outdoor Storage</option>
              </optgroup>
            </select>
          </div>
          <div class="form-group">
            <label>Supplier</label>
            <input type="text" id="f_supplier" class="form-control" placeholder="Supplier name" maxlength="255">
          </div>
          <div class="form-group">
            <label>Description</label>
            <textarea id="f_description" class="form-control full" rows="2" placeholder="Optional notes about this item…"></textarea>
          </div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title"><i class="fas fa-balance-scale"></i> Stock & Pricing</div>
        <div class="form-grid">
          <div class="form-group">
            <label>Quantity <span class="req">*</span></label>
            <input type="number" id="f_quantity" class="form-control" min="0" step="0.01" placeholder="0" required>
          </div>
          <div class="form-group">
            <label>Unit <span class="req">*</span></label>
            <input type="text" id="f_unit" class="form-control" placeholder="e.g. kg, litres, pcs" maxlength="50" required>
          </div>
          <div class="form-group">
            <label>Unit Price (UGX) <span class="req">*</span></label>
            <input type="number" id="f_unit_price" class="form-control" min="0" step="0.01" placeholder="0.00" required>
          </div>
          <div class="form-group">
            <label>Minimum Stock Level</label>
            <input type="number" id="f_min_stock_level" class="form-control" min="0" step="0.01" value="5">
            <span class="field-hint">Alert threshold for low-stock warnings</span>
          </div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title"><i class="fas fa-calendar-alt"></i> Dates</div>
        <div class="form-grid">
          <div class="form-group">
            <label>Purchase Date</label>
            <input type="date" id="f_purchase_date" class="form-control">
          </div>
          <div class="form-group">
            <label>Expiry Date</label>
            <input type="date" id="f_expiry_date" class="form-control">
          </div>
        </div>
      </div>

      <div class="form-actions">
        <button class="btn btn-outline" onclick="closeModal('editModal')"><i class="fas fa-times"></i> Cancel</button>
        <button class="btn btn-primary" id="saveBtn" onclick="saveItem()"><i class="fas fa-save"></i> Save Item</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ CONFIRM DIALOG ═══════════════════════════════════════════════ -->
<div id="confirmDlg" class="dialog">
  <div class="dialog-box">
    <div class="dialog-head danger" id="dlgHead">
      <i id="dlgIcon" class="fas fa-trash"></i>
      <h3 id="dlgTitle">Confirm Delete</h3>
    </div>
    <div class="dialog-body">
      <p id="dlgMsg"></p>
      <div class="dialog-actions">
        <button class="btn btn-outline" onclick="closeDlg()"><i class="fas fa-times"></i> Cancel</button>
        <button class="btn btn-danger"  id="dlgConfirmBtn" onclick="runDlgCallback()"><i class="fas fa-trash"></i> Delete</button>
      </div>
    </div>
  </div>
</div>

<script>
/* ════════════════════════════════════════════════════════════════════
   STATE
   ════════════════════════════════════════════════════════════════════ */
const CSRF = <?= json_encode($csrf) ?>;
let allData   = [];       // current page rows
let totalRows = 0;
let currentPage = 1;
const PER_PAGE  = 25;

// active filters
let filters = { search:'', category:'', location:'', stock:'' };
let dlgCb   = null;

/* ════════════════════════════════════════════════════════════════════
   BOOT
   ════════════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
    loadData();

    // Debounced search
    let t;
    document.getElementById('searchInput').addEventListener('input', function() {
        clearTimeout(t);
        t = setTimeout(() => { filters.search = this.value.trim(); currentPage=1; loadData(); }, 320);
    });

    document.getElementById('categoryFilter').addEventListener('change', function() {
        filters.category = this.value; currentPage=1; loadData();
    });
    document.getElementById('locationFilter').addEventListener('change', function() {
        filters.location = this.value; currentPage=1; loadData();
    });
    document.getElementById('clearFiltersBtn').addEventListener('click', clearFilters);
});

/* ════════════════════════════════════════════════════════════════════
   DATA LOAD
   ════════════════════════════════════════════════════════════════════ */
function loadData(page) {
    if (page !== undefined) currentPage = page;
    renderSkeleton();

    api({ action:'fetch', page: currentPage, per_page: PER_PAGE, ...filters })
    .then(d => {
        if (!d.success) throw new Error(d.error || 'Failed to load data.');
        allData   = d.data;
        totalRows = d.total;
        renderTable();
        renderPagination(d.page, d.last_page, d.total);
        renderStats(d.stats);
    })
    .catch(err => notify('Error', err.message, 'error'));
}

/* ════════════════════════════════════════════════════════════════════
   RENDER TABLE
   ════════════════════════════════════════════════════════════════════ */
function renderSkeleton() {
    const tb = document.getElementById('tBody');
    let html = '';
    for (let i = 0; i < 8; i++) {
        html += `<tr>${Array(10).fill('<td><span class="skeleton-cell"></span></td>').join('')}</tr>`;
    }
    tb.innerHTML = html;
}

function renderTable() {
    const tb = document.getElementById('tBody');
    document.getElementById('resultCount').textContent = `${totalRows.toLocaleString()} result${totalRows !== 1 ? 's' : ''}`;

    if (!allData.length) {
        tb.innerHTML = `<tr><td colspan="10"><div class="empty-state"><i class="fas fa-box-open"></i><p>No inventory items found.</p></div></td></tr>`;
        return;
    }

    const offset = (currentPage - 1) * PER_PAGE;
    let html = '';
    allData.forEach((item, i) => {
        const stockStatus = getStockStatus(item);
        const rowClass    = stockStatus.cls ? `row-${stockStatus.cls}` : '';
        const totalValue  = (parseFloat(item.quantity) * parseFloat(item.unit_price)).toFixed(0);
        html += `
        <tr class="${rowClass}" data-id="${item.food_id}">
          <td style="color:#8a9a8b;font-size:.78rem">${offset + i + 1}</td>
          <td><strong style="color:var(--g800)">${esc(item.item_name)}</strong>
              ${item.supplier ? `<br><span style="font-size:.75rem;color:#8a9a8b">${esc(item.supplier)}</span>` : ''}
          </td>
          <td><span class="badge b-${item.category}">${ucf(item.category)}</span></td>
          <td><strong>${fmtNum(item.quantity)}</strong> <span style="color:#8a9a8b;font-size:.8rem">${esc(item.unit)}</span></td>
          <td>${fmtCurrency(item.unit_price)}</td>
          <td>${fmtCurrency(totalValue)}</td>
          <td>${item.storage_location ? ucf(item.storage_location.replace('-',' ')) : '—'}</td>
          <td>${fmtExpiryDate(item.expiry_date)}</td>
          <td><span class="badge b-${stockStatus.badge}">${stockStatus.label}</span></td>
          <td>
            <div class="action-cell">
              <button class="btn-icon bi-view" title="View Details" onclick="viewItem(${item.food_id})"><i class="fas fa-eye"></i></button>
              <button class="btn-icon bi-edit" title="Edit"         onclick="editItem(${item.food_id})"><i class="fas fa-pen"></i></button>
              <button class="btn-icon bi-delete" title="Delete"     onclick="promptDelete(${item.food_id},'${esc(item.item_name)}')"><i class="fas fa-trash"></i></button>
            </div>
          </td>
        </tr>`;
    });
    tb.innerHTML = html;
}

function getStockStatus(item) {
    const qty  = parseFloat(item.quantity);
    const min  = parseFloat(item.min_stock_level || 5);
    const exp  = item.expiry_date;
    const today = new Date(); today.setHours(0,0,0,0);

    if (exp) {
        const expDate = new Date(exp);
        if (expDate < today) return { badge:'expired', label:'Expired', cls:'expired' };
        const diff = (expDate - today) / 86400000;
        if (diff <= 7)       return { badge:'expiring', label:'Expiring', cls:'' };
    }
    if (qty === 0)           return { badge:'out',      label:'Out of Stock', cls:'out' };
    if (qty <= min)          return { badge:'low',      label:'Low Stock', cls:'low' };
    return                          { badge:'ok',       label:'In Stock', cls:'' };
}

/* ════════════════════════════════════════════════════════════════════
   STATS
   ════════════════════════════════════════════════════════════════════ */
function renderStats(s) {
    if (!s) return;
    document.getElementById('sTotal').textContent    = fmtNum(s.total_items || 0);
    document.getElementById('sOut').textContent      = s.out_of_stock || 0;
    document.getElementById('sLow').textContent      = s.low_stock    || 0;
    document.getElementById('sExpiring').textContent = s.expiring_soon || 0;
    document.getElementById('sExpired').textContent  = s.expired      || 0;
    document.getElementById('sValue').textContent    = fmtCurrencyShort(s.total_value || 0);
}

function setStockFilter(val) {
    filters.stock = val; currentPage = 1; loadData();
    // visual active state
    document.querySelectorAll('.stat-pill').forEach(p => p.classList.remove('active'));
}

/* ════════════════════════════════════════════════════════════════════
   PAGINATION
   ════════════════════════════════════════════════════════════════════ */
function renderPagination(page, lastPage, total) {
    const from = Math.min((page-1)*PER_PAGE+1, total);
    const to   = Math.min(page*PER_PAGE, total);
    document.getElementById('pageInfo').textContent = total
        ? `Showing ${from}–${to} of ${total.toLocaleString()} items`
        : 'No items';

    const container = document.getElementById('pageBtns');
    const pages = buildPageRange(page, lastPage);
    container.innerHTML = [
        `<button class="page-btn" ${page<=1?'disabled':''} onclick="loadData(${page-1})"><i class="fas fa-chevron-left"></i></button>`,
        ...pages.map(p => p === '…'
            ? `<button class="page-btn" disabled>…</button>`
            : `<button class="page-btn ${p===page?'active':''}" onclick="loadData(${p})">${p}</button>`
        ),
        `<button class="page-btn" ${page>=lastPage?'disabled':''} onclick="loadData(${page+1})"><i class="fas fa-chevron-right"></i></button>`,
    ].join('');
}

function buildPageRange(cur, last) {
    if (last <= 7) return Array.from({length:last},(_,i)=>i+1);
    const r = new Set([1, last, cur, cur-1, cur+1].filter(x=>x>=1&&x<=last));
    const sorted = [...r].sort((a,b)=>a-b);
    const out = [];
    sorted.forEach((p,i) => { if (i>0 && p-sorted[i-1]>1) out.push('…'); out.push(p); });
    return out;
}

/* ════════════════════════════════════════════════════════════════════
   VIEW ITEM
   ════════════════════════════════════════════════════════════════════ */
function viewItem(id) {
    openModal('viewModal');
    document.getElementById('viewBody').innerHTML = '<div style="text-align:center;padding:40px"><i class="fas fa-spinner fa-spin" style="font-size:2rem;color:var(--g600)"></i></div>';

    api({ action:'get_item', food_id: id })
    .then(d => {
        if (!d.success) throw new Error(d.error);
        const item = d.data;
        const stockStatus = getStockStatus(item);
        const totalValue  = (parseFloat(item.quantity) * parseFloat(item.unit_price)).toFixed(2);

        document.getElementById('viewBody').innerHTML = `
        <div class="view-header">
          <div class="view-icon"><i class="fas fa-box"></i></div>
          <div class="view-title">
            <h3>${esc(item.item_name)}</h3>
            <p>${ucf(item.category)} &bull; ${item.storage_location ? ucf(item.storage_location.replace('-',' ')) : 'No location set'}</p>
            <span class="badge b-${stockStatus.badge}" style="margin-top:6px">${stockStatus.label}</span>
          </div>
        </div>
        <div class="detail-grid">
          <div class="detail-row"><div class="d-label">Food ID</div><div class="d-val">#${item.food_id}</div></div>
          <div class="detail-row"><div class="d-label">Quantity</div><div class="d-val"><strong>${fmtNum(item.quantity)}</strong> ${esc(item.unit)}</div></div>
          <div class="detail-row"><div class="d-label">Unit Price</div><div class="d-val">${fmtCurrency(item.unit_price)}</div></div>
          <div class="detail-row"><div class="d-label">Total Value</div><div class="d-val"><strong>${fmtCurrency(totalValue)}</strong></div></div>
          <div class="detail-row"><div class="d-label">Min. Stock Level</div><div class="d-val">${fmtNum(item.min_stock_level)} ${esc(item.unit)}</div></div>
          <div class="detail-row"><div class="d-label">Supplier</div><div class="d-val">${esc(item.supplier) || '—'}</div></div>
          <div class="detail-row"><div class="d-label">Purchase Date</div><div class="d-val">${fmtDate(item.purchase_date)}</div></div>
          <div class="detail-row"><div class="d-label">Expiry Date</div><div class="d-val">${fmtDate(item.expiry_date)}</div></div>
          <div class="detail-row"><div class="d-label">Added On</div><div class="d-val">${fmtDate(item.created_at)}</div></div>
          <div class="detail-row"><div class="d-label">Last Updated</div><div class="d-val">${fmtDate(item.updated_at)}</div></div>
          ${item.description ? `<div class="detail-row"><div class="d-label">Description</div><div class="d-val" style="grid-column:span 1">${esc(item.description)}</div></div>` : ''}
        </div>
        <div class="form-actions" style="margin-top:20px;padding-top:16px;border-top:1px solid #eef2ee">
          <button class="btn btn-outline" onclick="closeModal('viewModal')"><i class="fas fa-times"></i> Close</button>
          <button class="btn btn-primary" onclick="closeModal('viewModal');editItem(${item.food_id})"><i class="fas fa-pen"></i> Edit</button>
        </div>`;
    })
    .catch(err => notify('Error', err.message, 'error'));
}

/* ════════════════════════════════════════════════════════════════════
   ADD / EDIT
   ════════════════════════════════════════════════════════════════════ */
function openAddModal() {
    document.getElementById('f_food_id').value         = '';
    document.getElementById('f_item_name').value       = '';
    document.getElementById('f_category').value        = '';
    document.getElementById('f_quantity').value        = '';
    document.getElementById('f_unit').value            = '';
    document.getElementById('f_unit_price').value      = '';
    document.getElementById('f_supplier').value        = '';
    document.getElementById('f_purchase_date').value   = '';
    document.getElementById('f_expiry_date').value     = '';
    document.getElementById('f_min_stock_level').value = '5';
    document.getElementById('f_storage_location').value= '';
    document.getElementById('f_description').value     = '';
    document.getElementById('editModalTitle').innerHTML = '<i class="fas fa-plus"></i> Add Inventory Item';
    openModal('editModal');
}

function editItem(id) {
    api({ action:'get_item', food_id: id })
    .then(d => {
        if (!d.success) throw new Error(d.error);
        const item = d.data;
        document.getElementById('f_food_id').value          = item.food_id;
        document.getElementById('f_item_name').value        = item.item_name;
        document.getElementById('f_category').value         = item.category;
        document.getElementById('f_quantity').value         = item.quantity;
        document.getElementById('f_unit').value             = item.unit;
        document.getElementById('f_unit_price').value       = item.unit_price;
        document.getElementById('f_supplier').value         = item.supplier || '';
        document.getElementById('f_purchase_date').value    = item.purchase_date || '';
        document.getElementById('f_expiry_date').value      = item.expiry_date || '';
        document.getElementById('f_min_stock_level').value  = item.min_stock_level || 5;
        document.getElementById('f_storage_location').value = item.storage_location || '';
        document.getElementById('f_description').value      = item.description || '';
        document.getElementById('editModalTitle').innerHTML  = '<i class="fas fa-pen"></i> Edit Item';
        openModal('editModal');
    })
    .catch(err => notify('Error', err.message, 'error'));
}

function saveItem() {
    const id       = document.getElementById('f_food_id').value;
    const isUpdate = !!id;

    const payload = {
        action:          isUpdate ? 'update' : 'add',
        csrf_token:      CSRF,
        food_id:         id,
        item_name:       document.getElementById('f_item_name').value.trim(),
        category:        document.getElementById('f_category').value,
        quantity:        document.getElementById('f_quantity').value,
        unit:            document.getElementById('f_unit').value.trim(),
        unit_price:      document.getElementById('f_unit_price').value,
        supplier:        document.getElementById('f_supplier').value.trim(),
        purchase_date:   document.getElementById('f_purchase_date').value,
        expiry_date:     document.getElementById('f_expiry_date').value,
        min_stock_level: document.getElementById('f_min_stock_level').value,
        storage_location:document.getElementById('f_storage_location').value,
        description:     document.getElementById('f_description').value.trim(),
    };

    // Client-side quick checks
    if (!payload.item_name)  { notify('Validation', 'Item name is required.', 'warning'); return; }
    if (!payload.category)   { notify('Validation', 'Category is required.', 'warning'); return; }
    if (payload.quantity === '' || isNaN(payload.quantity)) { notify('Validation', 'Valid quantity is required.', 'warning'); return; }
    if (payload.unit === '')  { notify('Validation', 'Unit is required.', 'warning'); return; }
    if (!payload.unit_price || isNaN(payload.unit_price)) { notify('Validation', 'Valid unit price is required.', 'warning'); return; }

    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    api(payload)
    .then(d => {
        if (!d.success) throw new Error(d.error);
        notify('Saved', d.message, 'success');
        closeModal('editModal');
        loadData();
    })
    .catch(err => notify('Error', err.message, 'error'))
    .finally(() => { btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Save Item'; });
}

/* ════════════════════════════════════════════════════════════════════
   DELETE
   ════════════════════════════════════════════════════════════════════ */
function promptDelete(id, name) {
    dlgCb = () => doDelete(id, name);
    document.getElementById('dlgMsg').innerHTML = `You are about to permanently delete <strong>${esc(name)}</strong>.<br>This action cannot be undone.`;
    document.getElementById('confirmDlg').classList.add('active');
}

function doDelete(id, name) {
    api({ action:'delete', food_id: id })
    .then(d => {
        if (!d.success) throw new Error(d.error);
        notify('Deleted', d.message, 'success');
        loadData();
    })
    .catch(err => notify('Error', err.message, 'error'));
}

/* ════════════════════════════════════════════════════════════════════
   EXPORTS
   ════════════════════════════════════════════════════════════════════ */
function exportToPDF() {
    if (!allData.length) { notify('Empty', 'No data on this page to export.', 'warning'); return; }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');
    doc.setFontSize(16); doc.setTextColor(46,125,50);
    doc.text('Food Inventory Report', 14, 18);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text('Generated: ' + new Date().toLocaleString('en-UG'), 14, 25);
    doc.autoTable({
        head: [['#','Item Name','Category','Quantity','Unit Price','Total Value','Location','Expiry Date','Status']],
        body: allData.map((item,i) => {
            const st = getStockStatus(item);
            const tv = (parseFloat(item.quantity) * parseFloat(item.unit_price)).toFixed(0);
            return [i+1, item.item_name, ucf(item.category), `${fmtNum(item.quantity)} ${item.unit}`,
                    'UGX '+fmtNum(item.unit_price), 'UGX '+fmtNum(tv),
                    item.storage_location || '—', fmtDate(item.expiry_date), st.label];
        }),
        startY: 30, theme:'grid',
        headStyles:{fillColor:[67,160,71],fontSize:7.5},
        bodyStyles:{fontSize:7},
    });
    doc.save('food-inventory-' + datestamp() + '.pdf');
    notify('Exported', 'PDF downloaded.', 'success');
}

function exportToExcel() {
    if (!allData.length) { notify('Empty', 'No data to export.', 'warning'); return; }
    const headers = ['ID','Item Name','Category','Quantity','Unit','Unit Price (UGX)','Total Value (UGX)','Supplier','Location','Purchase Date','Expiry Date','Min Stock','Status'];
    const rows = allData.map(item => {
        const st = getStockStatus(item);
        const tv = (parseFloat(item.quantity) * parseFloat(item.unit_price)).toFixed(2);
        return [item.food_id, item.item_name, ucf(item.category),
                parseFloat(item.quantity), item.unit,
                parseFloat(item.unit_price), parseFloat(tv),
                item.supplier||'', item.storage_location||'',
                item.purchase_date||'', item.expiry_date||'',
                parseFloat(item.min_stock_level), st.label];
    });
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
    ws['!cols'] = [{wch:6},{wch:22},{wch:12},{wch:10},{wch:8},{wch:14},{wch:14},{wch:20},{wch:14},{wch:14},{wch:14},{wch:10},{wch:12}];
    XLSX.utils.book_append_sheet(wb, ws, 'Food Inventory');
    XLSX.writeFile(wb, 'food-inventory-' + datestamp() + '.xlsx');
    notify('Exported', 'Excel file downloaded.', 'success');
}

/* ════════════════════════════════════════════════════════════════════
   HELPERS
   ════════════════════════════════════════════════════════════════════ */
function clearFilters() {
    filters = { search:'', category:'', location:'', stock:'' };
    document.getElementById('searchInput').value    = '';
    document.getElementById('categoryFilter').value = '';
    document.getElementById('locationFilter').value = '';
    currentPage = 1; loadData();
}

function api(payload) {
    return fetch(location.pathname, {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-Requested-With':'XMLHttpRequest' },
        body: JSON.stringify({ ...payload, csrf_token: payload.csrf_token || CSRF }),
    }).then(r => r.json());
}

function esc(v) {
    return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function ucf(v) { return v ? v.charAt(0).toUpperCase() + v.slice(1) : ''; }
function fmtNum(v) { return parseFloat(v||0).toLocaleString('en-UG', {maximumFractionDigits:2}); }
function fmtCurrency(v) { return 'UGX ' + parseFloat(v||0).toLocaleString('en-UG', {minimumFractionDigits:0, maximumFractionDigits:0}); }
function fmtCurrencyShort(v) {
    const n = parseFloat(v||0);
    if (n >= 1_000_000) return (n/1_000_000).toFixed(1) + 'M';
    if (n >= 1_000)     return (n/1_000).toFixed(0) + 'K';
    return n.toFixed(0);
}
function fmtDate(d) {
    if (!d) return '—';
    try { return new Date(d).toLocaleDateString('en-UG',{day:'2-digit',month:'short',year:'numeric'}); }
    catch(_) { return d; }
}
function fmtExpiryDate(d) {
    if (!d) return '<span style="color:#aaa">—</span>';
    const today = new Date(); today.setHours(0,0,0,0);
    const exp   = new Date(d);
    const diff  = Math.ceil((exp - today)/86400000);
    let cls = '';
    if (diff < 0)   cls = 'style="color:var(--red);font-weight:700"';
    else if(diff<=7) cls = 'style="color:var(--orange);font-weight:600"';
    return `<span ${cls}>${fmtDate(d)}</span>`;
}
function datestamp() { return new Date().toISOString().split('T')[0]; }

/* Dialog / Modal Helpers */
function closeDlg() { document.getElementById('confirmDlg').classList.remove('active'); dlgCb=null; }
function runDlgCallback() { if(dlgCb) dlgCb(); closeDlg(); }
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function closeAllModals() { document.querySelectorAll('.modal.active,.dialog.active').forEach(m=>m.classList.remove('active')); }
function modalOutsideClick(e,id) { if(e.target.id===id) closeModal(id); }

/* Notification */
function notify(title, msg, type='success', dur=4500) {
    const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
    const n = document.createElement('div');
    n.className = `notif ${type}`;
    n.innerHTML = `<i class="fas ${icons[type]||icons.info} notif-icon"></i>
      <div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div>
      <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('notif-stack').prepend(n);
    setTimeout(()=>{n.style.opacity='0';n.style.transform='translateX(30px)';n.style.transition='.3s';setTimeout(()=>n.remove(),300);},dur);
}

/* Keyboard shortcuts */
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeAllModals();
    if ((e.ctrlKey||e.metaKey) && e.key==='k') { e.preventDefault(); document.getElementById('searchInput').focus(); }
});
</script>
</body>
</html>
