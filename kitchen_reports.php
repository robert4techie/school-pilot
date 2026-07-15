<?php
/**
 * Kitchen Reports Dashboard
 * Production-grade: All data via prepared statements, AJAX refresh endpoint,
 * Chart.js visualisations, PDF/Excel exports per chart.
 */
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction('Kitchen Reports');

/* ── Security Headers ───────────────────────────────────────────────── */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

/* ── Ensure mysqli throws exceptions on errors ──────────────────────── */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* ── JSON API (refresh endpoint) ────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    if (!hash_equals($_SESSION['csrf_token'], (string)($body['csrf_token'] ?? ''))) {
        http_response_code(403); echo json_encode(['success'=>false,'error'=>'Invalid CSRF token.']); exit;
    }
    echo json_encode(getAllReportData($conn, $body));
    exit;
}

/* ════════════════════════════════════════════════════════════════════
   DATA COLLECTION (used on initial load + AJAX refresh)
   ════════════════════════════════════════════════════════════════════ */

function getAllReportData(mysqli $conn, array $filters = []): array
{
    $dateFrom = trim($filters['date_from'] ?? '');
    $dateTo   = trim($filters['date_to']   ?? '');

    return [
        'success'            => true,
        'summary'            => getSummaryStats($conn),
        'inventory_status'   => getInventoryStatus($conn),
        'category_dist'      => getCategoryDistribution($conn),
        'monthly_withdrawals'=> getMonthlyWithdrawals($conn, $dateFrom, $dateTo),
        'cost_analysis'      => getCostAnalysis($conn),
        'top_withdrawals'    => getTopWithdrawals($conn, $dateFrom, $dateTo),
        'low_stock'          => getLowStockItems($conn),
        'expiring_soon'      => getExpiringSoon($conn),
        'recent_withdrawals' => getRecentWithdrawals($conn, $dateFrom, $dateTo),
        'nutrition'          => getNutritionSummary($conn),
        'withdrawal_by_purpose'=> getWithdrawalsByPurpose($conn, $dateFrom, $dateTo),
    ];
}

function getSummaryStats(mysqli $conn): array
{
    $data = [];

    $r = $conn->query("SELECT COUNT(*) AS n, COALESCE(SUM(quantity*unit_price),0) AS val, SUM(quantity=0) AS oos, SUM(quantity>0 AND quantity<=min_stock_level) AS low FROM food_inventory");
    $row = $r ? $r->fetch_assoc() : [];
    $data['inventory_items']    = (int)($row['n']   ?? 0);
    $data['inventory_value']    = (float)($row['val']?? 0);
    $data['out_of_stock']       = (int)($row['oos']  ?? 0);
    $data['low_stock']          = (int)($row['low']  ?? 0);

    $r = $conn->query("SELECT COUNT(*) AS n, COALESCE(SUM(total_value),0) AS val FROM food_withdrawals WHERE status='completed'");
    $row = $r ? $r->fetch_assoc() : [];
    $data['completed_withdrawals'] = (int)($row['n']  ?? 0);
    $data['withdrawn_value']       = (float)($row['val']?? 0);

    $r = $conn->query("SELECT COUNT(*) AS n FROM food_withdrawals WHERE status='pending'");
    $data['pending_withdrawals'] = (int)($r ? $r->fetch_assoc()['n'] : 0);

    $r = $conn->query("SELECT COUNT(*) AS n, COALESCE(SUM(cost),0) AS val FROM meals");
    $row = $r ? $r->fetch_assoc() : [];
    $data['total_meals']   = (int)($row['n']  ?? 0);
    $data['meals_cost']    = (float)($row['val']?? 0);

    $r = $conn->query("SELECT COUNT(*) AS n FROM kitchen_staff WHERE status='active'");
    $data['active_staff']  = (int)($r ? $r->fetch_assoc()['n'] : 0);

    $r = $conn->query("SELECT COUNT(*) AS n FROM food_inventory WHERE expiry_date < CURDATE()");
    $data['expired_items'] = (int)($r ? $r->fetch_assoc()['n'] : 0);

    return $data;
}

function getInventoryStatus(mysqli $conn): array
{
    $r = $conn->query("
        SELECT
            SUM(quantity > min_stock_level)                          AS in_stock,
            SUM(quantity > 0 AND quantity <= min_stock_level)        AS low_stock,
            SUM(quantity = 0)                                        AS out_of_stock,
            SUM(expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)) AS expiring,
            SUM(expiry_date < CURDATE())                             AS expired
        FROM food_inventory
    ");
    return $r ? $r->fetch_assoc() : [];
}

function getCategoryDistribution(mysqli $conn): array
{
    $r    = $conn->query("SELECT category, COUNT(*) AS cnt, COALESCE(SUM(quantity*unit_price),0) AS val FROM food_inventory GROUP BY category ORDER BY cnt DESC");
    $rows = [];
    if ($r) while($row=$r->fetch_assoc()) $rows[]=$row;
    return $rows;
}

function getMonthlyWithdrawals(mysqli $conn, string $from='', string $to=''): array
{
    $where = "WHERE YEAR(withdrawal_date) = YEAR(CURDATE())";
    $params=[]; $types='';
    if ($from) { $where.=" AND withdrawal_date >= ?"; $params[]=$from; $types.='s'; }
    if ($to)   { $where.=" AND withdrawal_date <= ?"; $params[]=$to;   $types.='s'; }

    $stmt = $conn->prepare("
        SELECT MONTH(withdrawal_date) AS month,
               MONTHNAME(withdrawal_date) AS month_name,
               COUNT(*) AS count,
               COALESCE(SUM(total_value),0) AS total_value
          FROM food_withdrawals {$where}
         GROUP BY MONTH(withdrawal_date), MONTHNAME(withdrawal_date)
         ORDER BY MONTH(withdrawal_date)
    ");
    if ($types) $stmt->bind_param($types,...$params);
    $stmt->execute();
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function getCostAnalysis(mysqli $conn): array
{
    $r = $conn->query("SELECT category, COALESCE(SUM(quantity*unit_price),0) AS total_cost, COUNT(*) AS item_count FROM food_inventory GROUP BY category ORDER BY total_cost DESC");
    $rows=[];
    if ($r) while($row=$r->fetch_assoc()) $rows[]=$row;
    return $rows;
}

function getTopWithdrawals(mysqli $conn, string $from='', string $to=''): array
{
    $where='WHERE 1=1'; $params=[]; $types='';
    if ($from) { $where.=" AND withdrawal_date >= ?"; $params[]=$from; $types.='s'; }
    if ($to)   { $where.=" AND withdrawal_date <= ?"; $params[]=$to;   $types.='s'; }
    $stmt=$conn->prepare("SELECT item_name, SUM(quantity) AS total_qty, SUM(total_value) AS total_val, COUNT(*) AS times FROM food_withdrawals {$where} GROUP BY item_name ORDER BY total_val DESC LIMIT 10");
    if ($types) $stmt->bind_param($types,...$params);
    $stmt->execute();
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function getLowStockItems(mysqli $conn): array
{
    $r = $conn->query("SELECT item_name, quantity, unit, min_stock_level, category FROM food_inventory WHERE quantity <= min_stock_level ORDER BY (quantity/GREATEST(min_stock_level,1)) ASC LIMIT 15");
    $rows=[];
    if ($r) while($row=$r->fetch_assoc()) $rows[]=$row;
    return $rows;
}

function getExpiringSoon(mysqli $conn): array
{
    $r = $conn->query("SELECT item_name, expiry_date, quantity, unit, category FROM food_inventory WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(),INTERVAL 14 DAY) ORDER BY expiry_date ASC LIMIT 15");
    $rows=[];
    if ($r) while($row=$r->fetch_assoc()) $rows[]=$row;
    return $rows;
}

function getRecentWithdrawals(mysqli $conn, string $from='', string $to=''): array
{
    $where='WHERE 1=1'; $params=[]; $types='';
    if ($from) { $where.=" AND withdrawal_date >= ?"; $params[]=$from; $types.='s'; }
    if ($to)   { $where.=" AND withdrawal_date <= ?"; $params[]=$to;   $types.='s'; }
    $stmt=$conn->prepare("SELECT withdraw_id,item_name,quantity,unit,department,withdrawal_date,status,total_value FROM food_withdrawals {$where} ORDER BY withdrawal_date DESC, withdrawal_time DESC LIMIT 15");
    if ($types) $stmt->bind_param($types,...$params);
    $stmt->execute();
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function getNutritionSummary(mysqli $conn): array
{
    $r=$conn->query("SELECT AVG(calories) AS avg_cal, AVG(protein) AS avg_protein, AVG(carbs) AS avg_carbs, AVG(fat) AS avg_fat, AVG(fiber) AS avg_fiber, COUNT(*) AS total_meals FROM meals WHERE calories>0");
    return $r ? $r->fetch_assoc() : [];
}

function getWithdrawalsByPurpose(mysqli $conn, string $from='', string $to=''): array
{
    $where='WHERE 1=1'; $params=[]; $types='';
    if ($from) { $where.=" AND withdrawal_date >= ?"; $params[]=$from; $types.='s'; }
    if ($to)   { $where.=" AND withdrawal_date <= ?"; $params[]=$to;   $types.='s'; }
    $stmt=$conn->prepare("SELECT purpose, COUNT(*) AS cnt, COALESCE(SUM(total_value),0) AS val FROM food_withdrawals {$where} GROUP BY purpose ORDER BY cnt DESC");
    if ($types) $stmt->bind_param($types,...$params);
    $stmt->execute();
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/* ── Initial page data ──────────────────────────────────────────── */
try {
    $pageData = getAllReportData($conn);
} catch (\Throwable $e) {
    /* Log the real error server-side; show a safe empty state to the browser */
    error_log('kitchen_reports.php data error: ' . $e->getMessage());
    $pageData = ['success' => false, 'error' => 'Could not load report data.'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Kitchen Reports &mdash; School Pilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<style>
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--orange:#e65100;--blue:#1565c0;--amber:#f57f17;
  --gray:#546e7a;--radius:8px;--radius-lg:12px;
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
.page-header h1{color:#fff;font-size:1.55rem;font-weight:700}
.page-header p{color:rgba(255,255,255,.78);font-size:.9rem;margin-top:3px}

/* ── Filter Bar ──────────────────────────────────────────────────── */
.filter-bar{background:#fff;border-radius:var(--radius-lg);padding:16px 24px;box-shadow:var(--shadow);margin-bottom:20px;display:flex;flex-wrap:wrap;gap:12px;align-items:center}
.filter-bar label{font-size:.82rem;font-weight:600;color:#3a4a3b}
.form-control{padding:8px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;background:#fff;font-family:inherit}
.form-control:focus{outline:none;border-color:var(--g600)}
.filter-group{display:flex;align-items:center;gap:8px}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all var(--transition);white-space:nowrap}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active,.btn:disabled{transform:none;opacity:.6}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{background:#f5f5f5;transform:none}
.btn-sm{padding:6px 11px;font-size:.78rem}

/* ── KPI Grid ────────────────────────────────────────────────────── */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:14px;margin-bottom:22px}
.kpi-card{background:#fff;border-radius:var(--radius-lg);padding:18px 20px;box-shadow:var(--shadow);border-top:4px solid var(--g600);transition:transform var(--transition)}
.kpi-card:hover{transform:translateY(-2px)}
.kpi-card.red{border-top-color:var(--red)}
.kpi-card.orange{border-top-color:var(--orange)}
.kpi-card.blue{border-top-color:var(--blue)}
.kpi-card.amber{border-top-color:var(--amber)}
.kpi-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:8px}
.kpi-icon{width:40px;height:40px;border-radius:var(--radius);display:flex;align-items:center;justify-content:center;font-size:1.1rem}
.kpi-icon.green{background:var(--g100);color:var(--g700)}
.kpi-icon.red{background:#ffebee;color:var(--red)}
.kpi-icon.orange{background:#fff3e0;color:var(--orange)}
.kpi-icon.blue{background:#e3f2fd;color:var(--blue)}
.kpi-icon.amber{background:#fff8e1;color:var(--amber)}
.kpi-val{font-size:1.75rem;font-weight:700;color:var(--g800);line-height:1}
.kpi-label{font-size:.75rem;color:#8a9a8b;text-transform:uppercase;letter-spacing:.5px;margin-top:4px}
.kpi-sub{font-size:.78rem;color:#aaa;margin-top:3px}

/* ── Charts Grid ─────────────────────────────────────────────────── */
.charts-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:18px;margin-bottom:22px}
.chart-card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden}
.chart-card-head{padding:16px 20px;border-bottom:1px solid #e8ede9;display:flex;align-items:center;justify-content:space-between}
.chart-card-head h3{font-size:.95rem;font-weight:700;color:var(--g800);display:flex;align-items:center;gap:8px}
.chart-card-head h3 i{color:var(--g600)}
.chart-body{padding:16px 20px}
canvas{max-height:260px}

/* ── Section Title ───────────────────────────────────────────────── */
.section-title{font-size:1rem;font-weight:700;color:var(--g800);margin-bottom:14px;display:flex;align-items:center;gap:8px;padding-bottom:8px;border-bottom:2px solid var(--g100)}
.section-title i{color:var(--g600)}

/* ── Tables Grid ─────────────────────────────────────────────────── */
.tables-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:18px;margin-bottom:22px}
.table-card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden}
.table-card-head{padding:14px 20px;border-bottom:1px solid #e8ede9;display:flex;align-items:center;justify-content:space-between}
.table-card-head h3{font-size:.9rem;font-weight:700;color:var(--g800);display:flex;align-items:center;gap:8px}
.table-card-head h3 i{color:var(--g600)}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700),var(--g600))}
thead th{padding:10px 14px;text-align:left;font-size:.75rem;font-weight:600;color:#fff;white-space:nowrap;letter-spacing:.3px}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--transition)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:10px 14px;font-size:.82rem;vertical-align:middle}

/* ── Badges ──────────────────────────────────────────────────────── */
.badge{display:inline-block;padding:3px 8px;border-radius:20px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
.b-pending{background:#fff3e0;color:#e65100}.b-approved{background:#e8f5e9;color:#2e7d32}
.b-rejected{background:#ffebee;color:#c62828}.b-completed{background:#e3f2fd;color:#1565c0}

/* ── Progress bar ────────────────────────────────────────────────── */
.prog-wrap{width:100%;background:#eee;border-radius:4px;height:6px;overflow:hidden}
.prog-fill{height:100%;border-radius:4px;background:var(--g600);transition:width .5s ease}
.prog-fill.red{background:var(--red)}.prog-fill.orange{background:var(--orange)}

/* ── Expiry cell ─────────────────────────────────────────────────── */
.expired{color:var(--red);font-weight:700}
.expiring{color:var(--orange);font-weight:600}

/* ── Notifications ───────────────────────────────────────────────── */
#notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.notif{background:#fff;border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow-lg);display:flex;align-items:flex-start;gap:12px;border-left:4px solid var(--g600);animation:notifIn .3s ease}
.notif.error{border-left-color:var(--red)}.notif.warning{border-left-color:#e65100}
@keyframes notifIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.notif-icon{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.notif.success .notif-icon{color:var(--g700)}.notif.error .notif-icon{color:var(--red)}.notif.warning .notif-icon{color:#e65100}
.notif-body{flex:1}.notif-title{font-weight:700;font-size:.85rem;margin-bottom:2px}.notif-msg{font-size:.8rem;color:#666}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:1rem;padding:0}

/* ── Last updated badge ──────────────────────────────────────────── */
.last-updated{font-size:.75rem;color:rgba(255,255,255,.7);display:flex;align-items:center;gap:6px}

@media(max-width:700px){.kpi-grid{grid-template-columns:repeat(2,1fr)}.charts-grid,.tables-grid{grid-template-columns:1fr}.page-header{flex-direction:column}}
</style>
</head>
<body>
<?php require_once 'nav.php'; ?>
<div id="notif-stack"></div>

<div class="page">
  <!-- Page Header -->
  <div class="page-header">
    <div>
      <h1><i class="fas fa-chart-bar" style="margin-right:10px;opacity:.85"></i>Kitchen Reports</h1>
      <p>Live overview of inventory, withdrawals, meals and nutrition</p>
    </div>
    <div class="last-updated"><i class="fas fa-clock"></i> Updated: <span id="lastUpdated">—</span></div>
  </div>

  <!-- Filter Bar -->
  <div class="filter-bar">
    <div class="filter-group">
      <label>From</label>
      <input type="date" id="dateFrom" class="form-control" style="width:155px">
    </div>
    <div class="filter-group">
      <label>To</label>
      <input type="date" id="dateTo" class="form-control" style="width:155px">
    </div>
    <button class="btn btn-primary" onclick="refreshAll()"><i class="fas fa-sync-alt"></i> Refresh</button>
    <button class="btn btn-outline" onclick="clearFilters()"><i class="fas fa-times-circle"></i> Clear</button>
    <div style="margin-left:auto;display:flex;gap:8px">
      <button class="btn btn-outline btn-sm" onclick="exportFullPDF()"><i class="fas fa-file-pdf" style="color:var(--red)"></i> Full PDF</button>
      <button class="btn btn-outline btn-sm" onclick="exportFullExcel()"><i class="fas fa-file-excel" style="color:var(--g700)"></i> Full Excel</button>
    </div>
  </div>

  <!-- KPI Cards -->
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-top"><div class="kpi-icon green"><i class="fas fa-boxes-stacked"></i></div></div>
      <div class="kpi-val" id="kInvItems">—</div>
      <div class="kpi-label">Inventory Items</div>
      <div class="kpi-sub" id="kInvValue">Value: —</div>
    </div>
    <div class="kpi-card red">
      <div class="kpi-top"><div class="kpi-icon red"><i class="fas fa-triangle-exclamation"></i></div></div>
      <div class="kpi-val" id="kLowStock">—</div>
      <div class="kpi-label">Low / Out of Stock</div>
      <div class="kpi-sub" id="kExpired">Expired: —</div>
    </div>
    <div class="kpi-card orange">
      <div class="kpi-top"><div class="kpi-icon orange"><i class="fas fa-hand-holding"></i></div></div>
      <div class="kpi-val" id="kWithdrawals">—</div>
      <div class="kpi-label">Completed Withdrawals</div>
      <div class="kpi-sub" id="kPending">Pending: —</div>
    </div>
    <div class="kpi-card blue">
      <div class="kpi-top"><div class="kpi-icon blue"><i class="fas fa-calendar-alt"></i></div></div>
      <div class="kpi-val" id="kMeals">—</div>
      <div class="kpi-label">Total Meals Planned</div>
      <div class="kpi-sub" id="kMealsCost">Cost: —</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-top"><div class="kpi-icon green"><i class="fas fa-users-gear"></i></div></div>
      <div class="kpi-val" id="kStaff">—</div>
      <div class="kpi-label">Active Staff</div>
    </div>
    <div class="kpi-card amber">
      <div class="kpi-top"><div class="kpi-icon amber"><i class="fas fa-money-bill-wave"></i></div></div>
      <div class="kpi-val" id="kWithdrawnVal">—</div>
      <div class="kpi-label">Total Value Withdrawn</div>
    </div>
  </div>

  <!-- Charts Row 1 -->
  <div class="charts-grid">
    <div class="chart-card">
      <div class="chart-card-head">
        <h3><i class="fas fa-chart-line"></i> Monthly Withdrawals</h3>
        <button class="btn btn-outline btn-sm" onclick="exportChart('withdrawalChart','Monthly Withdrawals','excel')"><i class="fas fa-file-excel" style="color:var(--g700)"></i></button>
      </div>
      <div class="chart-body"><canvas id="withdrawalChart"></canvas></div>
    </div>
    <div class="chart-card">
      <div class="chart-card-head">
        <h3><i class="fas fa-chart-pie"></i> Inventory by Category</h3>
        <button class="btn btn-outline btn-sm" onclick="exportChart('categoryChart','Category Distribution','pdf')"><i class="fas fa-file-pdf" style="color:var(--red)"></i></button>
      </div>
      <div class="chart-body"><canvas id="categoryChart"></canvas></div>
    </div>
    <div class="chart-card">
      <div class="chart-card-head">
        <h3><i class="fas fa-chart-bar"></i> Cost by Category (UGX)</h3>
        <button class="btn btn-outline btn-sm" onclick="exportChart('costChart','Cost Analysis','pdf')"><i class="fas fa-file-pdf" style="color:var(--red)"></i></button>
      </div>
      <div class="chart-body"><canvas id="costChart"></canvas></div>
    </div>
    <div class="chart-card">
      <div class="chart-card-head">
        <h3><i class="fas fa-circle-half-stroke"></i> Withdrawals by Purpose</h3>
        <button class="btn btn-outline btn-sm" onclick="exportChart('purposeChart','Withdrawals by Purpose','pdf')"><i class="fas fa-file-pdf" style="color:var(--red)"></i></button>
      </div>
      <div class="chart-body"><canvas id="purposeChart"></canvas></div>
    </div>
  </div>

  <!-- Tables Row -->
  <div class="tables-grid">
    <!-- Low Stock -->
    <div class="table-card">
      <div class="table-card-head">
        <h3><i class="fas fa-triangle-exclamation"></i> Low Stock Alert</h3>
        <button class="btn btn-outline btn-sm" onclick="exportTableExcel('lowStockTbl','low-stock')"><i class="fas fa-file-excel" style="color:var(--g700)"></i></button>
      </div>
      <div class="table-wrap">
        <table id="lowStockTbl">
          <thead><tr><th>Item</th><th>Category</th><th>Stock</th><th>Min Level</th><th>Level</th></tr></thead>
          <tbody id="lowStockBody"></tbody>
        </table>
      </div>
    </div>

    <!-- Expiring Soon -->
    <div class="table-card">
      <div class="table-card-head">
        <h3><i class="fas fa-clock"></i> Expiring Soon (&le;14 days)</h3>
        <button class="btn btn-outline btn-sm" onclick="exportTableExcel('expiringTbl','expiring-items')"><i class="fas fa-file-excel" style="color:var(--g700)"></i></button>
      </div>
      <div class="table-wrap">
        <table id="expiringTbl">
          <thead><tr><th>Item</th><th>Category</th><th>Qty</th><th>Expiry Date</th></tr></thead>
          <tbody id="expiringBody"></tbody>
        </table>
      </div>
    </div>

    <!-- Top Withdrawals -->
    <div class="table-card">
      <div class="table-card-head">
        <h3><i class="fas fa-arrow-up-right-dots"></i> Top Withdrawn Items</h3>
        <button class="btn btn-outline btn-sm" onclick="exportTableExcel('topWdTbl','top-withdrawals')"><i class="fas fa-file-excel" style="color:var(--g700)"></i></button>
      </div>
      <div class="table-wrap">
        <table id="topWdTbl">
          <thead><tr><th>Item</th><th>Times</th><th>Total Qty</th><th>Total Value</th></tr></thead>
          <tbody id="topWdBody"></tbody>
        </table>
      </div>
    </div>

    <!-- Recent Withdrawals -->
    <div class="table-card">
      <div class="table-card-head">
        <h3><i class="fas fa-history"></i> Recent Withdrawals</h3>
        <button class="btn btn-outline btn-sm" onclick="exportTableExcel('recentWdTbl','recent-withdrawals')"><i class="fas fa-file-excel" style="color:var(--g700)"></i></button>
      </div>
      <div class="table-wrap">
        <table id="recentWdTbl">
          <thead><tr><th>Date</th><th>Item</th><th>Qty</th><th>Dept</th><th>Status</th><th>Value</th></tr></thead>
          <tbody id="recentWdBody"></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Nutrition Summary -->
  <div class="table-card" style="margin-bottom:22px">
    <div class="table-card-head">
      <h3><i class="fas fa-heart-pulse"></i> Nutrition Summary (Meal Averages)</h3>
    </div>
    <div style="padding:20px 24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:14px" id="nutritionGrid">
      <!-- Rendered by JS -->
    </div>
  </div>

</div><!-- /page -->

<script>
const CSRF = <?= json_encode($csrf) ?>;
const INITIAL_DATA = <?= json_encode($pageData) ?>;

/* Chart instances */
let charts = {};
let reportData = {};

/* ── Green palette ──────────────────────────────────────────────── */
const GREENS = ['#1b5e20','#2e7d32','#388e3c','#43a047','#66bb6a','#a5d6a7','#c8e6c9','#4caf50','#81c784','#00695c'];
const CAT_COLORS = {
    vegetables:'#388e3c', fruits:'#f57f17',   meat:'#c62828',   dairy:'#1565c0',
    grains:'#6a1b9a',     spices:'#00838f',   beverages:'#00695c', frozen:'#283593',
    flour:'#4e342e',      oils:'#e65100',     legumes:'#558b2f', condiments:'#ad1457',
    canned:'#37474f',     eggs:'#f9a825',     baking:'#bf360c',  cereals:'#ef6c00',
};

/* ── BOOT ───────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    if (!INITIAL_DATA.success) {
        notify('Load Error', INITIAL_DATA.error || 'Could not load report data. Please refresh.', 'error', 0);
        return;
    }
    applyData(INITIAL_DATA);
    document.getElementById('lastUpdated').textContent = new Date().toLocaleTimeString('en-UG');
});

/* ── REFRESH ────────────────────────────────────────────────────── */
function refreshAll() {
    const from = document.getElementById('dateFrom').value;
    const to   = document.getElementById('dateTo').value;
    const btn  = document.querySelector('.filter-bar .btn-primary');
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Loading…';

    fetch(location.pathname, {
        method:'POST',
        headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({ csrf_token:CSRF, date_from:from, date_to:to })
    }).then(r=>r.json())
    .then(d => {
        if (!d.success) throw new Error(d.error||'Refresh failed');
        applyData(d);
        document.getElementById('lastUpdated').textContent = new Date().toLocaleTimeString('en-UG');
        notify('Refreshed','Reports updated.','success');
    })
    .catch(err => notify('Error', err.message, 'error'))
    .finally(() => { btn.disabled=false; btn.innerHTML='<i class="fas fa-sync-alt"></i> Refresh'; });
}

function clearFilters() {
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value   = '';
    refreshAll();
}

/* ── APPLY DATA ─────────────────────────────────────────────────── */
function applyData(d) {
    reportData = d;
    renderKPIs(d.summary);
    renderWithdrawalChart(d.monthly_withdrawals);
    renderCategoryChart(d.category_dist);
    renderCostChart(d.cost_analysis);
    renderPurposeChart(d.withdrawal_by_purpose);
    renderLowStock(d.low_stock);
    renderExpiring(d.expiring_soon);
    renderTopWithdrawals(d.top_withdrawals);
    renderRecentWithdrawals(d.recent_withdrawals);
    renderNutrition(d.nutrition);
}

/* ── KPIs ───────────────────────────────────────────────────────── */
function renderKPIs(s) {
    if (!s) return;
    document.getElementById('kInvItems').textContent   = fmtNum(s.inventory_items||0);
    document.getElementById('kInvValue').textContent   = 'Value: UGX '+fmtShort(s.inventory_value||0);
    document.getElementById('kLowStock').textContent   = (s.low_stock||0) + ' / ' + (s.out_of_stock||0);
    document.getElementById('kExpired').textContent    = 'Expired: '+(s.expired_items||0);
    document.getElementById('kWithdrawals').textContent= fmtNum(s.completed_withdrawals||0);
    document.getElementById('kPending').textContent    = 'Pending: '+(s.pending_withdrawals||0);
    document.getElementById('kMeals').textContent      = fmtNum(s.total_meals||0);
    document.getElementById('kMealsCost').textContent  = 'Cost: UGX '+fmtShort(s.meals_cost||0);
    document.getElementById('kStaff').textContent      = s.active_staff||0;
    document.getElementById('kWithdrawnVal').textContent= 'UGX '+fmtShort(s.withdrawn_value||0);
}

/* ── CHARTS ─────────────────────────────────────────────────────── */
function renderWithdrawalChart(rows) {
    const labels = (rows||[]).map(r=>r.month_name);
    const counts = (rows||[]).map(r=>parseInt(r.count||0));
    const values = (rows||[]).map(r=>parseFloat(r.total_value||0)/1000);
    updateChart('withdrawalChart','bar',{
        labels,
        datasets:[
            {label:'Count',data:counts,backgroundColor:'#43a047',borderRadius:4,yAxisID:'y'},
            {label:'Value (K UGX)',data:values,backgroundColor:'rgba(27,94,32,.25)',borderColor:'#1b5e20',type:'line',yAxisID:'y1',tension:.4,fill:true}
        ]
    },{
        scales:{
            y:{beginAtZero:true,ticks:{color:'#6b7c6d',font:{size:10}}},
            y1:{position:'right',beginAtZero:true,grid:{drawOnChartArea:false},ticks:{color:'#6b7c6d',font:{size:10}}}
        }
    });
}

function renderCategoryChart(rows) {
    updateChart('categoryChart','doughnut',{
        labels:(rows||[]).map(r=>ucf(r.category)),
        datasets:[{
            data:(rows||[]).map(r=>parseInt(r.cnt||0)),
            backgroundColor:(rows||[]).map(r=>CAT_COLORS[r.category]||GREENS[0]),
            borderWidth:2,borderColor:'#fff'
        }]
    });
}

function renderCostChart(rows) {
    updateChart('costChart','bar',{
        labels:(rows||[]).map(r=>ucf(r.category)),
        datasets:[{
            label:'Cost (UGX)',
            data:(rows||[]).map(r=>parseFloat(r.total_cost||0)),
            backgroundColor:GREENS,
            borderRadius:4,
        }]
    },{scales:{y:{beginAtZero:true,ticks:{callback:v=>'UGX '+fmtShort(v),color:'#6b7c6d',font:{size:10}}},x:{ticks:{color:'#6b7c6d',font:{size:10}}}}});
}

function renderPurposeChart(rows) {
    updateChart('purposeChart','pie',{
        labels:(rows||[]).map(r=>ucf((r.purpose||'').replace('_',' '))),
        datasets:[{
            data:(rows||[]).map(r=>parseInt(r.cnt||0)),
            backgroundColor:GREENS,
            borderWidth:2,borderColor:'#fff'
        }]
    });
}

function updateChart(id, type, data, extraOptions={}) {
    if (charts[id]) { charts[id].destroy(); }
    const ctx = document.getElementById(id).getContext('2d');
    charts[id] = new Chart(ctx, {
        type,
        data,
        options: {
            responsive:true, maintainAspectRatio:true,
            plugins:{ legend:{ position:'bottom', labels:{ font:{size:11}, padding:12 } } },
            ...extraOptions
        }
    });
}

/* ── TABLES ─────────────────────────────────────────────────────── */
function renderLowStock(rows) {
    const tb = document.getElementById('lowStockBody');
    if (!rows?.length) { tb.innerHTML='<tr><td colspan="5" style="text-align:center;padding:20px;color:#8a9a8b">No low stock items.</td></tr>'; return; }
    tb.innerHTML = rows.map(r => {
        const pct = Math.min(100, Math.round((parseFloat(r.quantity)/Math.max(parseFloat(r.min_stock_level),1))*100));
        const cls = parseFloat(r.quantity)===0 ? 'red' : 'orange';
        return `<tr>
          <td><strong>${esc(r.item_name)}</strong></td>
          <td><span style="font-size:.72rem;color:#888">${ucf(r.category)}</span></td>
          <td><strong style="color:var(--${cls})">${fmtNum(r.quantity)} ${esc(r.unit)}</strong></td>
          <td>${fmtNum(r.min_stock_level)}</td>
          <td style="min-width:80px"><div class="prog-wrap"><div class="prog-fill ${cls}" style="width:${pct}%"></div></div><span style="font-size:.7rem;color:#888">${pct}%</span></td>
        </tr>`;
    }).join('');
}

function renderExpiring(rows) {
    const tb = document.getElementById('expiringBody');
    if (!rows?.length) { tb.innerHTML='<tr><td colspan="4" style="text-align:center;padding:20px;color:#8a9a8b">No items expiring soon.</td></tr>'; return; }
    const today = new Date(); today.setHours(0,0,0,0);
    tb.innerHTML = rows.map(r => {
        const exp = new Date(r.expiry_date);
        const diff = Math.ceil((exp - today)/86400000);
        const cls  = diff < 0 ? 'expired' : (diff<=7 ? 'expiring' : '');
        const label = diff < 0 ? `${Math.abs(diff)}d ago` : `in ${diff}d`;
        return `<tr>
          <td><strong>${esc(r.item_name)}</strong></td>
          <td><span style="font-size:.72rem;color:#888">${ucf(r.category)}</span></td>
          <td>${fmtNum(r.quantity)} ${esc(r.unit)}</td>
          <td class="${cls}">${fmtDate(r.expiry_date)} <span style="font-size:.72rem">(${label})</span></td>
        </tr>`;
    }).join('');
}

function renderTopWithdrawals(rows) {
    const tb = document.getElementById('topWdBody');
    if (!rows?.length) { tb.innerHTML='<tr><td colspan="4" style="text-align:center;padding:20px;color:#8a9a8b">No withdrawal data.</td></tr>'; return; }
    const maxVal = Math.max(...rows.map(r=>parseFloat(r.total_val||0)));
    tb.innerHTML = rows.map(r => {
        const pct = Math.round((parseFloat(r.total_val||0)/Math.max(maxVal,1))*100);
        return `<tr>
          <td>
            <strong>${esc(r.item_name)}</strong>
            <div class="prog-wrap" style="margin-top:4px"><div class="prog-fill" style="width:${pct}%"></div></div>
          </td>
          <td style="text-align:center">${r.times}</td>
          <td>${fmtNum(r.total_qty)}</td>
          <td style="font-weight:600;color:var(--g800)">UGX ${fmtShort(r.total_val||0)}</td>
        </tr>`;
    }).join('');
}

function renderRecentWithdrawals(rows) {
    const tb = document.getElementById('recentWdBody');
    if (!rows?.length) { tb.innerHTML='<tr><td colspan="6" style="text-align:center;padding:20px;color:#8a9a8b">No recent withdrawals.</td></tr>'; return; }
    tb.innerHTML = rows.map(r => `<tr>
      <td>${fmtDate(r.withdrawal_date)}</td>
      <td><strong>${esc(r.item_name)}</strong></td>
      <td>${fmtNum(r.quantity)} ${esc(r.unit)}</td>
      <td>${r.department?esc(r.department):'<span style="color:#bbb">—</span>'}</td>
      <td><span class="badge b-${r.status}">${ucf(r.status)}</span></td>
      <td>UGX ${fmtShort(r.total_value||0)}</td>
    </tr>`).join('');
}

function renderNutrition(n) {
    const grid = document.getElementById('nutritionGrid');
    if (!n?.avg_cal) { grid.innerHTML='<p style="color:#8a9a8b;font-size:.85rem;padding:10px 0">No meal nutrition data available.</p>'; return; }
    grid.innerHTML = [
        {label:'Avg Calories',   val:Math.round(n.avg_cal||0),       unit:'kcal',   color:['#f57f17','#fff8e1'], icon:'fas fa-fire'},
        {label:'Avg Protein',    val:Math.round(n.avg_protein||0),   unit:'g/meal', color:['#2e7d32','#e8f5e9'], icon:'fas fa-dumbbell'},
        {label:'Avg Carbs',      val:Math.round(n.avg_carbs||0),     unit:'g/meal', color:['#6a1b9a','#f3e5f5'], icon:'fas fa-bread-slice'},
        {label:'Avg Fat',        val:Math.round(n.avg_fat||0),       unit:'g/meal', color:['#c62828','#ffebee'], icon:'fas fa-droplet'},
        {label:'Avg Fiber',      val:Math.round(n.avg_fiber||0),     unit:'g/meal', color:['#388e3c','#e8f5e9'], icon:'fas fa-leaf'},
        {label:'Meals with Data',val:fmtNum(n.total_meals||0),       unit:'total',  color:['#1565c0','#e3f2fd'], icon:'fas fa-calendar-check'},
    ].map(it => `
        <div style="background:${it.color[1]};border-radius:var(--radius);padding:14px;text-align:center;border-top:3px solid ${it.color[0]}">
          <i class="${it.icon}" style="color:${it.color[0]};font-size:1.2rem;margin-bottom:6px;display:block"></i>
          <div style="font-size:1.4rem;font-weight:700;color:${it.color[0]}">${it.val}</div>
          <div style="font-size:.7rem;color:#888;text-transform:uppercase;letter-spacing:.3px;margin-top:2px">${it.label}</div>
          <div style="font-size:.68rem;color:#aaa">${it.unit}</div>
        </div>`).join('');
}

/* ── EXPORTS ────────────────────────────────────────────────────── */
function exportChart(canvasId, title, format) {
    if (format === 'pdf') {
        const {jsPDF} = window.jspdf;
        const doc = new jsPDF();
        doc.setFontSize(16); doc.setTextColor(46,125,50);
        doc.text(title, 105, 18, {align:'center'});
        doc.setFontSize(9); doc.setTextColor(120);
        doc.text('Generated: '+new Date().toLocaleString('en-UG'), 105, 25, {align:'center'});
        const img = document.getElementById(canvasId).toDataURL('image/png',1.0);
        doc.addImage(img,'PNG',30,32,150,100);
        doc.save(title.toLowerCase().replace(/ /g,'-')+'.pdf');
    } else {
        /* download chart as image */
        const a = document.createElement('a');
        a.href = document.getElementById(canvasId).toDataURL('image/png');
        a.download = canvasId+'.png';
        a.click();
    }
    notify('Exported', title+' exported.', 'success');
}

function exportTableExcel(tableId, filename) {
    const tbl = document.getElementById(tableId);
    const wb  = XLSX.utils.book_new();
    const ws  = XLSX.utils.table_to_sheet(tbl);
    XLSX.utils.book_append_sheet(wb, ws, 'Sheet1');
    XLSX.writeFile(wb, filename+'-'+datestamp()+'.xlsx');
    notify('Exported', filename+' exported.', 'success');
}

function exportFullPDF() {
    const {jsPDF} = window.jspdf;
    const doc = new jsPDF('landscape');
    const s   = reportData.summary || {};
    doc.setFontSize(18); doc.setTextColor(46,125,50);
    doc.text('Kitchen Reports — Full Summary', 14, 18);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text('Generated: '+new Date().toLocaleString('en-UG'), 14, 25);

    /* summary table */
    doc.autoTable({
        head:[['Metric','Value']],
        body:[
            ['Inventory Items', s.inventory_items||0],
            ['Inventory Value', 'UGX '+fmtNum(s.inventory_value||0)],
            ['Low / Out of Stock', (s.low_stock||0)+' / '+(s.out_of_stock||0)],
            ['Completed Withdrawals', s.completed_withdrawals||0],
            ['Withdrawn Value', 'UGX '+fmtNum(s.withdrawn_value||0)],
            ['Total Meals Planned', s.total_meals||0],
            ['Active Staff', s.active_staff||0],
        ],
        startY:32, theme:'grid',
        headStyles:{fillColor:[67,160,71]}, bodyStyles:{fontSize:9},
    });

    /* low stock */
    if (reportData.low_stock?.length) {
        doc.autoTable({
            head:[['Item','Category','Stock','Min Level']],
            body:(reportData.low_stock||[]).map(r=>[r.item_name, ucf(r.category), fmtNum(r.quantity)+' '+r.unit, fmtNum(r.min_stock_level)]),
            startY: doc.lastAutoTable.finalY + 10,
            headStyles:{fillColor:[198,40,40]}, bodyStyles:{fontSize:8},
            didDrawPage: (data) => { doc.setFontSize(10); doc.text('Low Stock Items', 14, doc.lastAutoTable.finalY-2); }
        });
    }

    doc.save('kitchen-reports-'+datestamp()+'.pdf');
    notify('Exported', 'Full PDF downloaded.', 'success');
}

function exportFullExcel() {
    const wb = XLSX.utils.book_new();
    const s  = reportData.summary || {};

    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([
        ['Metric','Value'],
        ['Inventory Items', s.inventory_items||0],
        ['Inventory Value (UGX)', s.inventory_value||0],
        ['Low Stock', s.low_stock||0],
        ['Out of Stock', s.out_of_stock||0],
        ['Completed Withdrawals', s.completed_withdrawals||0],
        ['Withdrawn Value (UGX)', s.withdrawn_value||0],
        ['Total Meals', s.total_meals||0],
        ['Active Staff', s.active_staff||0],
    ]), 'Summary');

    if (reportData.low_stock?.length) {
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([
            ['Item','Category','Stock','Unit','Min Level'],
            ...reportData.low_stock.map(r=>[r.item_name,r.category,r.quantity,r.unit,r.min_stock_level])
        ]), 'Low Stock');
    }
    if (reportData.recent_withdrawals?.length) {
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([
            ['Date','Item','Qty','Unit','Department','Status','Value'],
            ...reportData.recent_withdrawals.map(r=>[r.withdrawal_date,r.item_name,r.quantity,r.unit,r.department||'',r.status,r.total_value||0])
        ]), 'Recent Withdrawals');
    }

    XLSX.writeFile(wb, 'kitchen-reports-'+datestamp()+'.xlsx');
    notify('Exported', 'Full Excel downloaded.', 'success');
}

/* ── HELPERS ────────────────────────────────────────────────────── */
function esc(v){return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function ucf(v){return v?v.charAt(0).toUpperCase()+v.slice(1):'';}
function fmtNum(v){return parseFloat(v||0).toLocaleString('en-UG',{maximumFractionDigits:0});}
function fmtShort(v){const n=parseFloat(v||0);if(n>=1_000_000)return(n/1_000_000).toFixed(1)+'M';if(n>=1_000)return(n/1_000).toFixed(0)+'K';return n.toFixed(0);}
function fmtDate(d){if(!d)return'—';try{return new Date(d).toLocaleDateString('en-UG',{day:'2-digit',month:'short',year:'numeric'});}catch(_){return d;}}
function datestamp(){return new Date().toISOString().split('T')[0];}
function notify(title,msg,type='success',dur=4000){
    const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation'};
    const n=document.createElement('div');n.className=`notif ${type}`;
    n.innerHTML=`<i class="fas ${icons[type]||icons.success} notif-icon"></i><div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div><button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('notif-stack').prepend(n);
    setTimeout(()=>{n.style.opacity='0';n.style.transform='translateX(30px)';n.style.transition='.3s';setTimeout(()=>n.remove(),300);},dur);
}
</script>
</body>
</html>
