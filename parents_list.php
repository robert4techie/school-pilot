<?php
/**
 * parents_list.php
 * Production-ready Parents Information page.
 * UI matches view_students.php design system.
 */

require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction('Parents Information');

// ── Helper: resolve students table schema once ─────────────────────────────
function resolveStudentSchema(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $check = mysqli_query($conn, "SHOW TABLES LIKE 'students'");
    if (!$check || mysqli_num_rows($check) === 0) {
        $cache = ['exists' => false];
        return $cache;
    }

    $cols = [];
    $res  = mysqli_query($conn, "SHOW COLUMNS FROM students");
    while ($row = mysqli_fetch_assoc($res)) {
        $cols[] = $row['Field'];
    }

    $has = fn(string $c) => in_array($c, $cols, true);

    if ($has('first_name') && $has('last_name')) {
        $nameExpr = "CONCAT(s.first_name, ' ', s.last_name)";
    } elseif ($has('full_name')) {
        $nameExpr = 's.full_name';
    } elseif ($has('name')) {
        $nameExpr = 's.name';
    } else {
        $nameExpr = "'N/A'";
    }

    $cache = [
        'exists'      => true,
        'name_expr'   => $nameExpr,
        'class_col'   => $has('current_class') ? 's.current_class' : ($has('class') ? 's.class' : "'N/A'"),
        'gender_col'  => $has('gender')  ? 's.gender'  : "'N/A'",
        'stream_col'  => $has('stream')  ? 's.stream'  : "'N/A'",
    ];
    return $cache;
}

// ── Helper: build SELECT + FROM + JOIN fragment ────────────────────────────
function buildBaseQuery(mysqli $conn): string {
    $s = resolveStudentSchema($conn);
    if (!$s['exists']) {
        return "SELECT p.student_id,
                       p.full_name  AS parent_name,
                       'N/A'        AS student_name,
                       p.occupation, p.phone, p.email,
                       'N/A' AS gender, 'N/A' AS class, 'N/A' AS stream
                FROM parents p";
    }
    return "SELECT p.student_id,
                   p.full_name          AS parent_name,
                   {$s['name_expr']}    AS student_name,
                   p.occupation, p.phone, p.email,
                   {$s['gender_col']}   AS gender,
                   {$s['class_col']}    AS class,
                   {$s['stream_col']}   AS stream
            FROM parents p
            LEFT JOIN students s ON p.student_id = s.student_id";
}

// ── Helper: build WHERE clause + bind-param arrays from $_GET ──────────────
function buildWhere(array $get): array {
    $conditions = [];
    $params      = [];
    $types       = '';

    if (!empty($get['search'])) {
        $like = '%' . $get['search'] . '%';
        $conditions[] = "(p.full_name LIKE ? OR p.occupation LIKE ? OR p.phone LIKE ? OR p.email LIKE ?)";
        array_push($params, $like, $like, $like, $like);
        $types .= 'ssss';
    }
    if (!empty($get['gender'])) {
        $conditions[] = 's.gender = ?';
        $params[]      = $get['gender'];
        $types        .= 's';
    }
    if (!empty($get['class'])) {
        $conditions[] = 's.current_class = ?';
        $params[]      = $get['class'];
        $types        .= 's';
    }
    if (!empty($get['stream'])) {
        $conditions[] = 's.stream = ?';
        $params[]      = $get['stream'];
        $types        .= 's';
    }

    $clause = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
    return [$clause, $params, $types];
}

// ── Helper: run a (possibly parameterised) query safely ───────────────────
function runQuery(mysqli $conn, string $sql, array $params, string $types): mysqli_result|false {
    if (!$params) {
        return mysqli_query($conn, $sql);
    }
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    return mysqli_stmt_get_result($stmt);
}

// ══════════════════════════════════════════════════════════════════════════
// AJAX endpoint
// ══════════════════════════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {

    $perPage = 100;
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $offset  = ($page - 1) * $perPage;

    [$where, $params, $types] = buildWhere($_GET);
    $base = buildBaseQuery($conn);

    // Total count via subquery so filters apply correctly
    $countSql  = "SELECT COUNT(*) AS total FROM ($base $where) AS sub";
    $countRes  = runQuery($conn, $countSql, $params, $types);
    if (!$countRes) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database error: ' . mysqli_error($conn)]);
        exit;
    }
    $total      = (int) mysqli_fetch_assoc($countRes)['total'];
    $totalPages = (int) ceil($total / $perPage);

    // Paginated data  — LIMIT/OFFSET are integers, safe to inline
    $dataSql = "$base $where ORDER BY p.full_name ASC LIMIT $perPage OFFSET $offset";
    $dataRes = runQuery($conn, $dataSql, $params, $types);
    if (!$dataRes) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database error: ' . mysqli_error($conn)]);
        exit;
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($dataRes)) {
        $rows[] = $row;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success'       => true,
        'parents'       => $rows,
        'total_records' => $total,
        'total_pages'   => $totalPages,
        'current_page'  => $page,
        'num_rows'      => count($rows),
    ]);
    mysqli_close($conn);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// Initial page load — first 100 rows + filter option lists
// ══════════════════════════════════════════════════════════════════════════
$base                 = buildBaseQuery($conn);
$initialSql           = "$base ORDER BY p.full_name ASC LIMIT 100";
$initialResult        = mysqli_query($conn, $initialSql);

if (!$initialResult) {
    // Graceful error — do NOT expose mysqli_error() to the browser in production.
    error_log('parents_list.php query error: ' . mysqli_error($conn));
    die('<p style="font-family:sans-serif;padding:2rem;color:#c0392b">Failed to load parents data. Please try again later.</p>');
}

$parentsData = [];
while ($row = mysqli_fetch_assoc($initialResult)) {
    $parentsData[] = $row;
}

// Total count (unfiltered)
$countResult  = mysqli_query($conn, "SELECT COUNT(*) AS total FROM parents");
$totalRecords = $countResult ? (int) mysqli_fetch_assoc($countResult)['total'] : 0;
$totalPages   = (int) ceil($totalRecords / 100);
$numRows      = count($parentsData);

// Filter dropdowns
$genderResult = mysqli_query($conn,
    "SELECT DISTINCT s.gender FROM parents p
     LEFT JOIN students s ON p.student_id = s.student_id
     WHERE s.gender IS NOT NULL AND s.gender != ''
     ORDER BY s.gender");

$classResult = mysqli_query($conn,
    "SELECT DISTINCT s.current_class FROM parents p
     LEFT JOIN students s ON p.student_id = s.student_id
     WHERE s.current_class IS NOT NULL AND s.current_class != ''
     ORDER BY s.current_class");

$streamResult = mysqli_query($conn,
    "SELECT DISTINCT s.stream FROM parents p
     LEFT JOIN students s ON p.student_id = s.student_id
     WHERE s.stream IS NOT NULL AND s.stream != ''
     ORDER BY s.stream");

// Stat pills
$statsResult = mysqli_query($conn,
    "SELECT s.current_class, COUNT(*) AS cnt
     FROM parents p
     LEFT JOIN students s ON p.student_id = s.student_id
     GROUP BY s.current_class");
$statsByClass = [];
if ($statsResult) {
    while ($r = mysqli_fetch_assoc($statsResult)) {
        $statsByClass[$r['current_class'] ?? 'Unknown'] = (int) $r['cnt'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Parents Information &mdash; School Pilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
<style>
/* ── Variables (same design tokens as view_students) ─────── */
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
button{font-family:inherit;cursor:pointer}

/* ── Layout ──────────────────────────────────────────────── */
.page{max-width:100%;margin:0 auto;padding:24px 20px 48px}

/* ── Page Header ─────────────────────────────────────────── */
.page-header{
  background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);
  border-radius:var(--radius-lg);padding:28px 32px;
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:20px;margin-bottom:24px;margin-top:40px;
  box-shadow:var(--shadow-lg)
}
.page-header h1{color:#fff;font-size:1.55rem;font-weight:700;letter-spacing:.3px}
.page-header p{color:rgba(255,255,255,.78);font-size:.9rem;margin-top:3px}
.stats-row{display:flex;gap:12px;flex-wrap:wrap}
.stat-pill{
  background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);
  border-radius:40px;padding:8px 18px;text-align:center;min-width:80px;
  cursor:default;transition:background var(--transition)
}
.stat-pill:hover{background:rgba(255,255,255,.22)}
.stat-pill .n{font-size:1.35rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.72rem;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.5px}

/* ── Card ────────────────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden}

/* ── Toolbar ─────────────────────────────────────────────── */
.toolbar{padding:18px 24px;border-bottom:1px solid #e8ede9;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.toolbar-left{display:flex;flex-wrap:wrap;gap:10px;align-items:center;flex:1 1 auto}
.toolbar-right{display:flex;gap:10px;align-items:center;flex-shrink:0}
.search-wrap{position:relative;min-width:240px}
.search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.85rem}
.search-wrap input{
  width:100%;padding:9px 12px 9px 34px;
  border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;
  transition:border-color var(--transition),box-shadow var(--transition)
}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.filter-select{
  padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);
  font-size:.875rem;background:#fff;cursor:pointer;min-width:130px;
  transition:border-color var(--transition)
}
.filter-select:focus{outline:none;border-color:var(--g600)}
.result-count{font-size:.8rem;color:#6b7c6d;white-space:nowrap}

/* ── Buttons ─────────────────────────────────────────────── */
.btn{
  display:inline-flex;align-items:center;gap:7px;
  padding:9px 16px;border:none;border-radius:var(--radius);
  font-size:.85rem;font-weight:600;font-family:inherit;
  transition:all var(--transition);white-space:nowrap
}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}
.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-pdf{background:#c62828;color:#fff}.btn-pdf:hover{background:var(--red)}
.btn-excel{background:var(--g800);color:#fff}.btn-excel:hover{background:var(--g900)}

/* ── Table ───────────────────────────────────────────────── */
.table-wrap{overflow-x:auto;position:relative}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{
  padding:13px 14px;text-align:left;
  font-size:.8rem;font-weight:600;color:#fff;
  letter-spacing:.4px;white-space:nowrap
}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--transition)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:13px 14px;font-size:.875rem;vertical-align:middle}

/* ── Parent name highlight ───────────────────────────────── */
.parent-name{font-weight:600;color:var(--g800)}

/* ── Skeleton ────────────────────────────────────────────── */
.skeleton-cell{
  background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);
  background-size:200% 100%;animation:shimmer 1.4s infinite;
  border-radius:4px;height:14px;display:inline-block;width:80%
}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ── Empty state ─────────────────────────────────────────── */
.empty-state{text-align:center;padding:60px 20px;color:#8a9a8b}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;opacity:.45}
.empty-state p{font-size:.95rem}

/* ── Pagination ──────────────────────────────────────────── */
.pagination{
  padding:16px 24px;display:flex;align-items:center;
  justify-content:space-between;border-top:1px solid #e8ede9;
  flex-wrap:wrap;gap:10px
}
.page-info{font-size:.82rem;color:#6b7c6d}
.page-btns{display:flex;gap:4px}
.page-btn{
  width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:6px;
  background:#fff;cursor:pointer;font-size:.82rem;font-weight:600;color:#444;
  display:flex;align-items:center;justify-content:center;
  transition:all var(--transition)
}
.page-btn:hover:not(:disabled){border-color:var(--g600);background:var(--g100);color:var(--g800)}
.page-btn.active{background:var(--g700);border-color:var(--g700);color:#fff}
.page-btn:disabled{opacity:.38;cursor:default}

/* ── Notifications ───────────────────────────────────────── */
#notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.notif{
  background:#fff;border-radius:var(--radius);padding:14px 16px;
  box-shadow:var(--shadow-lg);display:flex;align-items:flex-start;gap:12px;
  border-left:4px solid var(--g600);animation:notifIn .3s ease
}
.notif.error{border-left-color:var(--red)}
.notif.warning{border-left-color:#e65100}
.notif.info{border-left-color:var(--blue)}
@keyframes notifIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.notif-icon{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.notif.success .notif-icon{color:var(--g700)}
.notif.error   .notif-icon{color:var(--red)}
.notif.warning .notif-icon{color:#e65100}
.notif.info    .notif-icon{color:var(--blue)}
.notif-body{flex:1}
.notif-title{font-weight:700;font-size:.85rem;margin-bottom:2px}
.notif-msg{font-size:.8rem;color:#666}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:1rem;padding:0;line-height:1;flex-shrink:0}

/* ── Responsive ──────────────────────────────────────────── */
@media(max-width:700px){
  .toolbar{flex-direction:column;align-items:stretch}
  .toolbar-right{flex-wrap:wrap}
  .page-header{flex-direction:column}
  .stats-row{gap:8px}
  thead th,tbody td{padding:10px 10px;font-size:.8rem}
}
</style>
</head>
<body>
<?php require_once 'nav.php'; ?>

<div id="notif-stack"></div>

<div class="page">

  <!-- ── Page Header ──────────────────────────────────────── -->
  <div class="page-header">
    <div>
      <h1><i class="fas fa-users" style="margin-right:10px;opacity:.85"></i>Parents Information</h1>
      <p>Search, filter and export parent &amp; guardian records</p>
    </div>
    <div class="stats-row">
      <div class="stat-pill">
        <span class="n" id="statTotal"><?= $totalRecords ?></span>
        <span class="l">Total</span>
      </div>
      <div class="stat-pill">
        <span class="n" id="statShowing"><?= $numRows ?></span>
        <span class="l">Showing</span>
      </div>
    </div>
  </div>

  <!-- ── Main Card ────────────────────────────────────────── -->
  <div class="card">

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">

        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput"
                 placeholder="Name, occupation, phone, email…"
                 autocomplete="off">
        </div>

        <select id="genderFilter" class="filter-select">
          <option value="">All Genders</option>
          <?php if ($genderResult): while ($r = mysqli_fetch_assoc($genderResult)): ?>
            <?php if (!empty($r['gender']) && $r['gender'] !== 'N/A'): ?>
              <option value="<?= htmlspecialchars($r['gender'], ENT_QUOTES) ?>">
                <?= htmlspecialchars(ucfirst($r['gender']), ENT_QUOTES) ?>
              </option>
            <?php endif; endwhile; endif; ?>
        </select>

        <select id="classFilter" class="filter-select">
          <option value="">All Classes</option>
          <?php if ($classResult): while ($r = mysqli_fetch_assoc($classResult)): ?>
            <?php if (!empty($r['current_class']) && $r['current_class'] !== 'N/A'): ?>
              <option value="<?= htmlspecialchars($r['current_class'], ENT_QUOTES) ?>">
                <?= htmlspecialchars($r['current_class'], ENT_QUOTES) ?>
              </option>
            <?php endif; endwhile; endif; ?>
        </select>

        <select id="streamFilter" class="filter-select">
          <option value="">All Streams</option>
          <?php if ($streamResult): while ($r = mysqli_fetch_assoc($streamResult)): ?>
            <?php if (!empty($r['stream']) && $r['stream'] !== 'N/A'): ?>
              <option value="<?= htmlspecialchars($r['stream'], ENT_QUOTES) ?>">
                <?= htmlspecialchars($r['stream'], ENT_QUOTES) ?>
              </option>
            <?php endif; endwhile; endif; ?>
        </select>

        <button class="btn btn-outline" id="clearFiltersBtn">
          <i class="fas fa-times-circle"></i> Clear
        </button>

        <span class="result-count" id="resultCount"></span>
      </div>

      <div class="toolbar-right">
        <button class="btn btn-pdf"   id="exportPDF">
          <i class="fas fa-file-pdf"></i> PDF
        </button>
        <button class="btn btn-excel" id="exportExcel">
          <i class="fas fa-file-excel"></i> Excel
        </button>
      </div>
    </div>

    <!-- Table -->
    <div class="table-wrap" id="tableWrap">
      <table id="parentsTable">
        <thead>
          <tr>
            <th style="width:36px">#</th>
            <th>Parent Name</th>
            <th>Student Name</th>
            <th>Occupation</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Gender</th>
            <th>Class</th>
            <th>Stream</th>
          </tr>
        </thead>
        <tbody id="tBody">
          <?php foreach ($parentsData as $i => $row): ?>
            <tr>
              <td style="color:#9aaa9b;font-size:.78rem"><?= $i + 1 ?></td>
              <td class="parent-name"><?= htmlspecialchars($row['parent_name'] ?? '', ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($row['student_name'] ?? '', ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($row['occupation']   ?? '', ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($row['phone']        ?? '', ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($row['email']        ?? '', ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($row['gender']       ?? '', ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($row['class']        ?? '', ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($row['stream']       ?? '', ENT_QUOTES) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($parentsData)): ?>
            <tr>
              <td colspan="9">
                <div class="empty-state">
                  <i class="fas fa-users"></i>
                  <p>No parent records found.</p>
                </div>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="pagination">
      <span class="page-info" id="pageInfo"></span>
      <div class="page-btns" id="pageBtns"></div>
    </div>

  </div><!-- /card -->
</div><!-- /page -->

<script>
// ── State ─────────────────────────────────────────────────
let currentPage  = 1;
let totalPages   = <?= (int) $totalPages ?>;
let totalRecords = <?= (int) $totalRecords ?>;
const PER_PAGE   = 100;

// ── DOM refs ──────────────────────────────────────────────
const searchInput  = document.getElementById('searchInput');
const genderFilter = document.getElementById('genderFilter');
const classFilter  = document.getElementById('classFilter');
const streamFilter = document.getElementById('streamFilter');
const tBody        = document.getElementById('tBody');
const pageInfo     = document.getElementById('pageInfo');
const pageBtns     = document.getElementById('pageBtns');
const resultCount  = document.getElementById('resultCount');
const statShowing  = document.getElementById('statShowing');

// ── Bootstrap ─────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    updatePagination();
    updateResultCount(<?= (int) $numRows ?>, totalRecords);

    // Search debounce
    let t;
    searchInput.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(() => loadParents(1), 320);
    });

    ['genderFilter','classFilter','streamFilter'].forEach(id => {
        document.getElementById(id).addEventListener('change', () => loadParents(1));
    });

    document.getElementById('clearFiltersBtn').addEventListener('click', clearFilters);
    document.getElementById('exportExcel').addEventListener('click', exportToExcel);
    document.getElementById('exportPDF').addEventListener('click', exportToPDF);
});

// ── Load parents via AJAX ─────────────────────────────────
async function loadParents(page = 1) {
    showSkeleton();

    const params = new URLSearchParams({
        ajax:   '1',
        page:   page,
        search: searchInput.value.trim(),
        gender: genderFilter.value,
        class:  classFilter.value,
        stream: streamFilter.value,
    });

    try {
        const res = await fetch('?' + params.toString());
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to load data.');

        currentPage  = data.current_page;
        totalPages   = data.total_pages;
        totalRecords = data.total_records;

        renderTable(data.parents, (page - 1) * PER_PAGE);
        updatePagination();
        updateResultCount(data.num_rows, data.total_records);

        document.getElementById('tableWrap').scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    } catch (err) {
        console.error(err);
        notify('Error', 'Could not load parents. Please try again.', 'error');
        hideSkeleton();
    }
}

// ── Render table rows ─────────────────────────────────────
function renderTable(parents, startIndex = 0) {
    if (!parents || parents.length === 0) {
        tBody.innerHTML = `<tr><td colspan="9">
            <div class="empty-state">
              <i class="fas fa-users"></i>
              <p>No parents match your search or filters.</p>
            </div></td></tr>`;
        return;
    }

    tBody.innerHTML = parents.map((p, i) => `
        <tr>
          <td style="color:#9aaa9b;font-size:.78rem">${startIndex + i + 1}</td>
          <td class="parent-name">${esc(p.parent_name)}</td>
          <td>${esc(p.student_name)}</td>
          <td>${esc(p.occupation)}</td>
          <td>${esc(p.phone)}</td>
          <td>${esc(p.email)}</td>
          <td>${esc(p.gender)}</td>
          <td>${esc(p.class)}</td>
          <td>${esc(p.stream)}</td>
        </tr>`).join('');
}

// ── Skeleton loader ───────────────────────────────────────
function showSkeleton() {
    const rows = Array.from({ length: 8 }, () =>
        `<tr>${Array.from({ length: 9 }, () =>
            `<td><span class="skeleton-cell" style="width:${50 + Math.random() * 40}%"></span></td>`
        ).join('')}</tr>`
    ).join('');
    tBody.innerHTML = rows;
}
function hideSkeleton() { /* renderTable replaces it */ }

// ── Pagination ────────────────────────────────────────────
function updatePagination() {
    if (totalRecords === 0) {
        pageInfo.textContent = '';
        pageBtns.innerHTML   = '';
        return;
    }

    const start = (currentPage - 1) * PER_PAGE + 1;
    const end   = Math.min(currentPage * PER_PAGE, totalRecords);
    pageInfo.textContent = `Showing ${start}–${end} of ${totalRecords}`;

    if (totalPages <= 1) { pageBtns.innerHTML = ''; return; }

    let html = `<button class="page-btn" onclick="loadParents(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>
                  <i class="fas fa-chevron-left"></i></button>`;

    for (let p = 1; p <= totalPages; p++) {
        if (totalPages > 7 && Math.abs(p - currentPage) > 2 && p !== 1 && p !== totalPages) {
            if (p === 2 || p === totalPages - 1) {
                html += `<button class="page-btn" disabled style="border:none;cursor:default">…</button>`;
            }
            continue;
        }
        html += `<button class="page-btn ${p === currentPage ? 'active' : ''}" onclick="loadParents(${p})">${p}</button>`;
    }

    html += `<button class="page-btn" onclick="loadParents(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>
               <i class="fas fa-chevron-right"></i></button>`;

    pageBtns.innerHTML = html;
}

// ── Result count display ──────────────────────────────────
function updateResultCount(shown, total) {
    statShowing.textContent = shown;
    resultCount.textContent = shown < total
        ? `${shown} of ${total} parents`
        : `${total} parent${total !== 1 ? 's' : ''}`;
}

// ── Clear filters ─────────────────────────────────────────
function clearFilters() {
    searchInput.value    = '';
    genderFilter.value   = '';
    classFilter.value    = '';
    streamFilter.value   = '';
    loadParents(1);
    notify('Filters Cleared', 'All filters have been reset.', 'info', 2500);
}

// ── Export helpers ────────────────────────────────────────
function getTableData() {
    const headers = Array.from(document.querySelectorAll('#parentsTable thead th')).map(th => th.textContent.trim());
    const rows    = Array.from(document.querySelectorAll('#parentsTable tbody tr'))
        .filter(tr => tr.cells.length > 1)  // skip empty-state row
        .map(tr => Array.from(tr.cells).map(td => td.textContent.trim()));
    return { headers, rows };
}

function exportToExcel() {
    const { headers, rows } = getTableData();
    if (!rows.length) { notify('Empty', 'No data to export.', 'warning'); return; }
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
    XLSX.utils.book_append_sheet(wb, ws, 'Parents');
    XLSX.writeFile(wb, 'parents-' + datestamp() + '.xlsx');
    notify('Exported', 'Excel file downloaded.', 'success');
}

function exportToPDF() {
    const { headers, rows } = getTableData();
    if (!rows.length) { notify('Empty', 'No data to export.', 'warning'); return; }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');
    doc.setFontSize(16); doc.setTextColor(46, 125, 50);
    doc.text('Parents Information Report', 14, 18);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text('Generated: ' + new Date().toLocaleDateString('en-UG'), 14, 25);
    doc.autoTable({
        head: [headers], body: rows, startY: 30, theme: 'grid',
        headStyles:  { fillColor: [67, 160, 71], fontSize: 8 },
        bodyStyles:  { fontSize: 7.5 },
        alternateRowStyles: { fillColor: [232, 245, 233] },
    });
    doc.save('parents-' + datestamp() + '.pdf');
    notify('Exported', 'PDF report downloaded.', 'success');
}

// ── Notifications ─────────────────────────────────────────
function notify(title, msg, type = 'success', dur = 4000) {
    const icons = { success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
    const n = document.createElement('div');
    n.className = `notif ${type}`;
    n.innerHTML = `
      <i class="fas ${icons[type] || icons.info} notif-icon"></i>
      <div class="notif-body">
        <div class="notif-title">${esc(title)}</div>
        <div class="notif-msg">${esc(msg)}</div>
      </div>
      <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('notif-stack').prepend(n);
    setTimeout(() => {
        n.style.cssText += 'opacity:0;transform:translateX(30px);transition:.3s';
        setTimeout(() => n.remove(), 300);
    }, dur);
}

// ── Utilities ─────────────────────────────────────────────
function esc(v) {
    return String(v || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function datestamp() { return new Date().toISOString().split('T')[0]; }
</script>
</body>
</html>
<?php mysqli_close($conn); ?>