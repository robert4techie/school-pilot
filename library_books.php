<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction('Library Books');

// ── Access control ────────────────────────────────────────────────────────────
function checkLibraryAccess(mysqli $conn): void
{
    if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
    $uid  = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row  = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { session_destroy(); header('Location: index.php'); exit; }
    $allowed = ['librarian', 'developer', 'super user', 'school leader'];
    if (!in_array(strtolower(trim($row['role'])), $allowed, true)) {
        $_SESSION['previous_page']         = $_SERVER['REQUEST_URI'];
        $_SESSION['access_denied_message'] = "You don't have permission to access the library management system.";
        header('Location: access_denied.php');
        exit;
    }
}
checkLibraryAccess($conn);

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── AJAX guard: validate id as positive integer ───────────────────────────────
// All AJAX calls that pass an id must come through the api/books_handler.php endpoint.
// Any id used here is cast to int before passing to the API layer.
// This page itself does not process book writes — that's handled by books_handler.php.

// Notification from session
$session_notification = null;
if (isset($_SESSION['notification'])) {
    $session_notification = $_SESSION['notification'];
    unset($_SESSION['notification']);
}
$js_notification = $session_notification
    ? json_encode($session_notification, JSON_HEX_TAG | JSON_HEX_AMP)
    : 'null';
$js_csrf = json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP);

// ── Catalog stats (for header pills) ─────────────────────────────────────────
try {
    $statsRow = $conn->query("
        SELECT
            COALESCE(SUM(copies),0)        AS total_copies,
            COUNT(*)                        AS total_titles,
            COUNT(DISTINCT subject)         AS total_subjects,
            COUNT(DISTINCT publisher)       AS total_publishers
        FROM books
    ")->fetch_assoc();
} catch (Throwable $e) {
    error_log('Library books stats: ' . $e->getMessage());
    $statsRow = ['total_copies' => 0, 'total_titles' => 0, 'total_subjects' => 0, 'total_publishers' => 0];
}

// Subject & class filter options for dropdowns
$subjectOptions = [];
$publisherOptions = [];
$sr = $conn->query("SELECT DISTINCT subject FROM books WHERE subject IS NOT NULL AND subject != '' ORDER BY subject");
if ($sr) while ($r = $sr->fetch_assoc()) $subjectOptions[] = $r['subject'];
$pr = $conn->query("SELECT DISTINCT publisher FROM books WHERE publisher IS NOT NULL AND publisher != '' ORDER BY publisher");
if ($pr) while ($r = $pr->fetch_assoc()) $publisherOptions[] = $r['publisher'];


$school_name = htmlspecialchars($_SESSION['school_name'] ?? 'School Pilot', ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Library Catalog &mdash; <?= $school_name ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
<style>
/* ── Tokens ──────────────────────────────────────────────── */
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#c62828;--red-lt:#ffebee;
  --amber:#e65100;--amber-lt:#fff3e0;
  --blue:#1565c0;--blue-lt:#e3f2fd;
  --gray:#546e7a;
  --radius:8px;--radius-lg:12px;--radius-xl:16px;
  --shadow:0 2px 8px rgba(0,0,0,.08);
  --shadow-lg:0 8px 28px rgba(0,0,0,.13);
  --transition:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}

/* ── Page ────────────────────────────────────────────────── */
.page{max-width:100%;margin:0 auto;padding:24px 20px 56px}

/* ── Page header ─────────────────────────────────────────── */
.page-header{
  background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);
  border-radius:var(--radius-xl);padding:28px 32px;
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:20px;margin-bottom:24px;margin-top:40px;
  box-shadow:var(--shadow-lg);position:relative;overflow:hidden
}
.page-header::before{
  content:'';position:absolute;inset:0;
  background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/svg%3E");
  pointer-events:none
}
.page-header h1{color:#fff;font-size:1.55rem;font-weight:700;display:flex;align-items:center;gap:12px;position:relative}
.page-header p{color:rgba(255,255,255,.72);font-size:.9rem;margin-top:4px;position:relative}
.stats-row{display:flex;gap:12px;flex-wrap:wrap;position:relative}
.stat-pill{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:40px;padding:8px 18px;text-align:center;min-width:86px;cursor:default;transition:background var(--transition)}
.stat-pill:hover{background:rgba(255,255,255,.22)}
.stat-pill .n{font-size:1.35rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.7rem;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.5px}

/* ── Card ────────────────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden;margin-bottom:24px}

/* ── Toolbar ─────────────────────────────────────────────── */
.toolbar{padding:16px 22px;border-bottom:1px solid #e8ede9;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.toolbar-left{display:flex;flex-wrap:wrap;gap:10px;align-items:center;flex:1}
.toolbar-right{display:flex;gap:8px;align-items:center;flex-shrink:0}
.search-wrap{position:relative;min-width:220px}
.search-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.82rem}
.search-wrap input{width:100%;padding:9px 12px 9px 32px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;transition:border-color var(--transition)}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.filter-select{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;background:#fff;cursor:pointer;min-width:130px}
.filter-select:focus{outline:none;border-color:var(--g600)}
.result-count{font-size:.8rem;color:#6b7c6d;white-space:nowrap}

/* ── Buttons ─────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;transition:all var(--transition);white-space:nowrap;cursor:pointer}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-danger{background:var(--red);color:#fff}.btn-danger:hover{background:#b71c1c}
.btn-sm{padding:6px 11px;font-size:.78rem}

/* ── Icon buttons ────────────────────────────────────────── */
.action-cell{display:flex;gap:5px;align-items:center}
.btn-icon{width:30px;height:30px;border:none;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;transition:all var(--transition);flex-shrink:0;font-family:inherit}
.btn-icon:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.18)}
.bi-view{background:#e3f2fd;color:var(--blue)}.bi-view:hover{background:var(--blue);color:#fff}
.bi-edit{background:#fff3e0;color:var(--amber)}.bi-edit:hover{background:var(--amber);color:#fff}
.bi-delete{background:#ffebee;color:var(--red)}.bi-delete:hover{background:var(--red);color:#fff}

/* ── Table ───────────────────────────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:12px 14px;text-align:left;font-size:.78rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--transition)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:12px 14px;font-size:.85rem;vertical-align:middle}

/* ── Skeleton rows ───────────────────────────────────────── */
.skeleton-cell{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;height:14px;display:inline-block;width:80%}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ── Badges ──────────────────────────────────────────────── */
.badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.badge-green{background:var(--g100);color:var(--g800)}
.badge-red{background:var(--red-lt);color:var(--red)}
.badge-amber{background:var(--amber-lt);color:var(--amber)}
.badge-blue{background:var(--blue-lt);color:var(--blue)}

/* ── Empty state ─────────────────────────────────────────── */
.empty-state{text-align:center;padding:60px 20px;color:#8a9a8b}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;opacity:.4}
.empty-state p{font-size:.95rem}

/* ── Pagination ──────────────────────────────────────────── */
.pagination{padding:14px 22px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e8ede9;flex-wrap:wrap;gap:10px}
.page-info{font-size:.82rem;color:#6b7c6d}
.page-btns{display:flex;gap:4px}
.page-btn{width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:6px;background:#fff;cursor:pointer;font-size:.82rem;font-weight:600;color:#444;display:flex;align-items:center;justify-content:center;transition:all var(--transition);font-family:inherit}
.page-btn:hover:not(:disabled){border-color:var(--g600);background:var(--g100);color:var(--g800)}
.page-btn.active{background:var(--g700);border-color:var(--g700);color:#fff}
.page-btn:disabled{opacity:.38;cursor:default}

/* ── Modal ───────────────────────────────────────────────── */
.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);animation:fadeOv .2s ease}
@keyframes fadeOv{from{opacity:0}to{opacity:1}}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:720px;box-shadow:var(--shadow-lg);animation:slideDown .25s ease;margin:auto}
@keyframes slideDown{from{transform:translateY(-24px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%);padding:18px 24px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head h2{color:#fff;font-size:1.05rem;font-weight:700;display:flex;align-items:center;gap:9px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:30px;height:30px;border-radius:50%;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background var(--transition)}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:26px}
.modal-footer{padding:16px 26px;border-top:1px solid #e8ede9;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}

/* ── Form ────────────────────────────────────────────────── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 20px}
.form-grid .full{grid-column:1/-1}
.form-group label{display:block;font-size:.8rem;font-weight:600;color:#374151;margin-bottom:5px}
.form-group input,.form-group select,.form-group textarea{
  width:100%;padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);
  font-size:.875rem;font-family:inherit;transition:border-color var(--transition)
}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{
  outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.1)
}
.form-group textarea{resize:vertical;min-height:80px}
.copies-wrap{display:flex;gap:8px;align-items:center}
.copies-wrap input{max-width:100px}
.copies-wrap span{font-size:.82rem;color:#8a9a8b}

/* ── View detail grid ────────────────────────────────────── */
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 20px}
.detail-item{border-bottom:1px solid #f0f4f1;padding-bottom:10px}
.detail-item.full{grid-column:1/-1}
.detail-label{font-size:.73rem;font-weight:600;color:#8a9a8b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px}
.detail-value{font-size:.88rem;color:#1f2937;font-weight:500}

/* ── Confirm dialog ──────────────────────────────────────── */
.dialog{display:none;position:fixed;inset:0;z-index:1100;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);animation:fadeOv .2s ease}
.dialog.active{display:flex;align-items:center;justify-content:center;padding:20px}
.dialog-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:420px;box-shadow:var(--shadow-lg);animation:slideDown .25s ease}
.dialog-head{padding:18px 22px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;gap:10px}
.dialog-head.danger{background:var(--red)}.dialog-head h3{color:#fff;font-size:.95rem;font-weight:700}.dialog-head i{color:#fff}
.dialog-body{padding:22px;font-size:.88rem;color:#374151}
.dialog-actions{padding:14px 22px;border-top:1px solid #f0f4f1;display:flex;gap:10px;justify-content:flex-end}

/* ── Notifications ───────────────────────────────────────── */
#notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.notif{background:#fff;border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow-lg);display:flex;align-items:flex-start;gap:12px;border-left:4px solid var(--g600);animation:notifIn .3s ease}
.notif.error{border-left-color:var(--red)}.notif.warning{border-left-color:var(--amber)}.notif.info{border-left-color:var(--blue)}
@keyframes notifIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.notif-icon{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.notif.success .notif-icon{color:var(--g700)}.notif.error .notif-icon{color:var(--red)}.notif.warning .notif-icon{color:var(--amber)}.notif.info .notif-icon{color:var(--blue)}
.notif-body{flex:1}.notif-title{font-weight:700;font-size:.85rem;margin-bottom:2px}.notif-msg{font-size:.8rem;color:#666}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:1rem;padding:0;line-height:1}

/* ── Loading indicator ───────────────────────────────────── */
.table-loading{text-align:center;padding:40px;color:#9aaa9b;font-size:.9rem}
.table-loading i{font-size:1.8rem;margin-bottom:10px;display:block}

/* ── Responsive ─────────────────────────────────────────── */
@media(max-width:700px){.form-grid{grid-template-columns:1fr}.detail-grid{grid-template-columns:1fr}.page-header{flex-direction:column}.stats-row{width:100%}}
</style>
</head>
<body>
<?php require_once 'nav.php' ?>

<div id="notif-stack"></div>

<div class="page">

  <!-- ── Page header ──────────────────────────────────────── -->
  <div class="page-header">
    <div>
      <h1><i class="fas fa-book"></i> Library Catalog</h1>
      <p><?= $school_name ?> — Book Collection Management</p>
    </div>
    <div class="stats-row">
      <div class="stat-pill">
        <span class="n"><?= number_format((int)$statsRow['total_copies']) ?></span>
        <span class="l">Total Copies</span>
      </div>
      <div class="stat-pill">
        <span class="n"><?= number_format((int)$statsRow['total_titles']) ?></span>
        <span class="l">Titles</span>
      </div>
      <div class="stat-pill">
        <span class="n"><?= number_format((int)$statsRow['total_subjects']) ?></span>
        <span class="l">Subjects</span>
      </div>
      <div class="stat-pill">
        <span class="n"><?= number_format((int)$statsRow['total_publishers']) ?></span>
        <span class="l">Publishers</span>
      </div>
    </div>
  </div>

  <!-- ── Table card ───────────────────────────────────────── -->
  <div class="card">
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search title, author, subject…" oninput="loadBooks()">
        </div>
        <select class="filter-select" id="subjectFilter" onchange="loadBooks()">
          <option value="">All Subjects</option>
          <?php foreach ($subjectOptions as $s): ?>
            <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
          <?php endforeach; ?>
        </select>
        <select class="filter-select" id="classFilter" onchange="loadBooks()">
          <option value="">All Classes</option>
          <optgroup label="O-Level">
            <option value="S1">Senior 1 (S1)</option>
            <option value="S2">Senior 2 (S2)</option>
            <option value="S3">Senior 3 (S3)</option>
            <option value="S4">Senior 4 (S4)</option>
            <option value="S1 - S4">S1 – S4 (O-Level)</option>
            <option value="O-Level">O-Level (General)</option>
          </optgroup>
          <optgroup label="A-Level">
            <option value="S5">Senior 5 (S5)</option>
            <option value="S6">Senior 6 (S6)</option>
            <option value="S5 - S6">S5 – S6 (A-Level)</option>
            <option value="A-Level">A-Level (General)</option>
          </optgroup>
        </select>
        <select class="filter-select" id="publisherFilter" onchange="loadBooks()">
          <option value="">All Publishers</option>
          <?php foreach ($publisherOptions as $p): ?>
            <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="result-count" id="resultCount"></span>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-outline btn-sm" onclick="exportToPDF()"><i class="fas fa-file-pdf"></i> PDF</button>
        <button class="btn btn-outline btn-sm" onclick="exportToExcel()"><i class="fas fa-file-excel"></i> Excel</button>
        <button class="btn btn-primary" onclick="openAddModal()">
          <i class="fas fa-plus"></i> Add Book
        </button>
      </div>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Date Added</th>
            <th>Title</th>
            <th>Author</th>
            <th>Subject</th>
            <th>Class</th>
            <th>Publisher</th>
            <th>Copies</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="tableBody">
          <tr><td colspan="8">
            <div class="table-loading"><i class="fas fa-spinner fa-spin"></i>Loading catalog…</div>
          </td></tr>
        </tbody>
      </table>
    </div>

    <div class="pagination">
      <span class="page-info" id="paginationInfo">Loading…</span>
      <div class="page-btns" id="paginationBtns"></div>
    </div>
  </div>

</div><!-- /page -->

<!-- ── Add Book Modal ─────────────────────────────────────── -->
<div class="modal" id="addModal" onclick="if(event.target===this)closeModal('addModal')">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-plus-circle"></i> Add New Book</h2>
      <button class="modal-close" onclick="closeModal('addModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="form-group full">
          <label>Book Title *</label>
          <input type="text" id="addTitle" placeholder="Full book title" maxlength="250">
        </div>
        <div class="form-group">
          <label>Author *</label>
          <input type="text" id="addAuthor" placeholder="Author name" maxlength="150">
        </div>
        <div class="form-group">
          <label>Subject *</label>
          <input type="text" id="addSubject" placeholder="e.g. Mathematics" list="subjectList" maxlength="100">
          <datalist id="subjectList">
            <?php foreach ($subjectOptions as $s): ?>
              <option value="<?= htmlspecialchars($s) ?>">
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="form-group">
          <label>Class / Level</label>
          <select id="addClass">
            <option value="">— Select class —</option>
            <optgroup label="O-Level">
              <option value="S1">Senior 1 (S1)</option>
              <option value="S2">Senior 2 (S2)</option>
              <option value="S3">Senior 3 (S3)</option>
              <option value="S4">Senior 4 (S4)</option>
              <option value="S1 - S4">S1 – S4 (O-Level)</option>
              <option value="O-Level">O-Level (General)</option>
            </optgroup>
            <optgroup label="A-Level">
              <option value="S5">Senior 5 (S5)</option>
              <option value="S6">Senior 6 (S6)</option>
              <option value="S5 - S6">S5 – S6 (A-Level)</option>
              <option value="A-Level">A-Level (General)</option>
            </optgroup>
          </select>
        </div>
        <div class="form-group">
          <label>Publisher</label>
          <input type="text" id="addPublisher" placeholder="Publisher name" list="publisherList" maxlength="150">
          <datalist id="publisherList">
            <?php foreach ($publisherOptions as $p): ?>
              <option value="<?= htmlspecialchars($p) ?>">
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="form-group">
          <label>Supplier</label>
          <input type="text" id="addSupplier" placeholder="Book supplier" maxlength="150">
        </div>
        <div class="form-group">
          <label>Number of Copies *</label>
          <div class="copies-wrap">
            <input type="number" id="addCopies" min="1" max="9999" value="1">
            <span>copies</span>
          </div>
        </div>
        <div class="form-group">
          <label>Date Added</label>
          <input type="datetime-local" id="addDateTime">
        </div>
        <div class="form-group full">
          <label>Notes</label>
          <textarea id="addNotes" placeholder="Any notes about this book (condition, edition, etc.)" rows="2"></textarea>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('addModal')">Cancel</button>
      <button class="btn btn-primary" onclick="saveBook()"><i class="fas fa-save"></i> Add to Catalog</button>
    </div>
  </div>
</div>

<!-- ── Edit Book Modal ────────────────────────────────────── -->
<div class="modal" id="editModal" onclick="if(event.target===this)closeModal('editModal')">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-edit"></i> Edit Book</h2>
      <button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="editBookId">
      <div class="form-grid">
        <div class="form-group full">
          <label>Book Title *</label>
          <input type="text" id="editTitle" maxlength="250">
        </div>
        <div class="form-group">
          <label>Author *</label>
          <input type="text" id="editAuthor" maxlength="150">
        </div>
        <div class="form-group">
          <label>Subject *</label>
          <input type="text" id="editSubject" list="subjectList" maxlength="100">
        </div>
        <div class="form-group">
          <label>Class / Level</label>
          <select id="editClass">
            <option value="">— Select class —</option>
            <optgroup label="O-Level">
              <option value="S1">Senior 1 (S1)</option>
              <option value="S2">Senior 2 (S2)</option>
              <option value="S3">Senior 3 (S3)</option>
              <option value="S4">Senior 4 (S4)</option>
              <option value="S1 - S4">S1 – S4 (O-Level)</option>
              <option value="O-Level">O-Level (General)</option>
            </optgroup>
            <optgroup label="A-Level">
              <option value="S5">Senior 5 (S5)</option>
              <option value="S6">Senior 6 (S6)</option>
              <option value="S5 - S6">S5 – S6 (A-Level)</option>
              <option value="A-Level">A-Level (General)</option>
            </optgroup>
          </select>
        </div>
        <div class="form-group">
          <label>Publisher</label>
          <input type="text" id="editPublisher" list="publisherList" maxlength="150">
        </div>
        <div class="form-group">
          <label>Supplier</label>
          <input type="text" id="editSupplier" maxlength="150">
        </div>
        <div class="form-group">
          <label>Number of Copies *</label>
          <div class="copies-wrap">
            <input type="number" id="editCopies" min="1" max="9999">
            <span>copies</span>
          </div>
        </div>
        <div class="form-group full">
          <label>Notes</label>
          <textarea id="editNotes" rows="2"></textarea>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
      <button class="btn btn-primary" onclick="updateBook()"><i class="fas fa-save"></i> Save Changes</button>
    </div>
  </div>
</div>

<!-- ── View Book Modal ────────────────────────────────────── -->
<div class="modal" id="viewModal" onclick="if(event.target===this)closeModal('viewModal')">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-eye"></i> Book Details</h2>
      <button class="modal-close" onclick="closeModal('viewModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="viewModalBody">
      <div style="text-align:center;padding:30px;color:#9aaa9b"><i class="fas fa-spinner fa-spin" style="font-size:2rem"></i></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('viewModal')">Close</button>
    </div>
  </div>
</div>

<!-- ── Confirm dialog ─────────────────────────────────────── -->
<div class="dialog" id="confirmDlg">
  <div class="dialog-box">
    <div class="dialog-head danger"><i class="fas fa-trash"></i><h3 id="dlgTitle">Delete Book</h3></div>
    <div class="dialog-body" id="dlgMsg"></div>
    <div class="dialog-actions">
      <button class="btn btn-outline" onclick="closeDlg()">Cancel</button>
      <button class="btn btn-danger" onclick="runDlgCb()">Delete</button>
    </div>
  </div>
</div>

<script>
const CSRF  = <?= $js_csrf ?>;
const NOTIF = <?= $js_notification ?>;

// ── Notify system ─────────────────────────────────────────
function notify(title, msg, type='success', dur=5000){
  const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
  const n=document.createElement('div');
  n.className=`notif ${type}`;
  n.innerHTML=`<i class="fas ${icons[type]||icons.info} notif-icon"></i>
    <div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div>
    <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
  document.getElementById('notif-stack').prepend(n);
  setTimeout(()=>{n.style.opacity='0';n.style.transform='translateX(30px)';n.style.transition='.3s';setTimeout(()=>n.remove(),320);},dur);
}
function esc(v){return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function fmtDate(d){if(!d)return'—';try{return new Date(d).toLocaleDateString('en-UG',{day:'2-digit',month:'short',year:'numeric'});}catch(_){return d;}}

if(NOTIF) notify(NOTIF.type==='success'?'Success':'Error', NOTIF.message, NOTIF.type);

// ── State ──────────────────────────────────────────────────
let allBooks = [], filtered = [], currentPage = 1;
const PER_PAGE = 20;

// ── Load books from API ────────────────────────────────────
let loadTimer = null;
function loadBooks(){
  clearTimeout(loadTimer);
  loadTimer = setTimeout(_loadBooks, 280);
}

async function _loadBooks(){
  const search    = document.getElementById('searchInput').value.trim();
  const subject   = document.getElementById('subjectFilter').value;
  const cls       = document.getElementById('classFilter').value;
  const publisher = document.getElementById('publisherFilter').value;
  const url       = `api/books_handler.php?action=getAllBooks&search=${encodeURIComponent(search)}&subject=${encodeURIComponent(subject)}&class=${encodeURIComponent(cls)}&publisher=${encodeURIComponent(publisher)}`;

  document.getElementById('tableBody').innerHTML = `<tr><td colspan="8"><div class="table-loading"><i class="fas fa-spinner fa-spin"></i>Loading…</div></td></tr>`;

  try {
    const r = await fetch(url);
    if(!r.ok) throw new Error('Server error '+r.status);
    const books = await r.json();
    allBooks = books;
    filtered = books;
    currentPage = 1;
    renderTable();
  } catch(e) {
    document.getElementById('tableBody').innerHTML = `<tr><td colspan="8"><div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Failed to load books. Please refresh.</p></div></td></tr>`;
    notify('Error','Could not load catalog data.','error');
  }
}

// ── Render table ───────────────────────────────────────────
function renderTable(){
  const total  = filtered.length;
  const pages  = Math.max(1,Math.ceil(total/PER_PAGE));
  currentPage  = Math.min(currentPage,pages);
  const start  = (currentPage-1)*PER_PAGE;
  const slice  = filtered.slice(start,start+PER_PAGE);

  document.getElementById('resultCount').textContent = `${total} book${total!==1?'s':''}`;
  document.getElementById('paginationInfo').textContent =
    `Showing ${Math.min(start+1,total)}–${Math.min(start+PER_PAGE,total)} of ${total}`;

  if(!slice.length){
    document.getElementById('tableBody').innerHTML=`<tr><td colspan="8"><div class="empty-state"><i class="fas fa-book-open"></i><p>No books found matching your filters.</p></div></td></tr>`;
  } else {
    document.getElementById('tableBody').innerHTML = slice.map(b=>`
      <tr>
        <td>${fmtDate(b.date_added)}</td>
        <td style="font-weight:600;color:var(--g800)">${esc(b.title||'—')}</td>
        <td>${esc(b.author||'—')}</td>
        <td>${esc(b.subject||'—')}</td>
        <td>${esc(b.class||'—')}</td>
        <td>${esc(b.publisher||'—')}</td>
        <td>
          <span class="badge ${b.copies<2?'badge-amber':'badge-green'}">${parseInt(b.copies)||0}</span>
        </td>
        <td>
          <div class="action-cell">
            <button class="btn-icon bi-view" title="View details" onclick="viewBook(${parseInt(b.book_id)})"><i class="fas fa-eye"></i></button>
            <button class="btn-icon bi-edit" title="Edit book"    onclick="editBook(${parseInt(b.book_id)})"><i class="fas fa-edit"></i></button>
            <button class="btn-icon bi-delete" title="Delete book" data-id="${parseInt(b.book_id)}" data-title="${esc(b.title)}" onclick="confirmDelete(this.dataset.id,this.dataset.title)"><i class="fas fa-trash"></i></button>
          </div>
        </td>
      </tr>`).join('');
  }

  // Pagination buttons
  const btns=document.getElementById('paginationBtns');
  btns.innerHTML='';
  const range=[];
  if(pages<=7){for(let i=1;i<=pages;i++)range.push(i);}
  else{
    range.push(1);
    if(currentPage>3)range.push('…');
    for(let i=Math.max(2,currentPage-1);i<=Math.min(pages-1,currentPage+1);i++)range.push(i);
    if(currentPage<pages-2)range.push('…');
    range.push(pages);
  }
  range.forEach(p=>{
    if(p==='…'){const s=document.createElement('span');s.style.padding='0 4px';s.textContent='…';btns.appendChild(s);return;}
    const b=document.createElement('button');
    b.className='page-btn'+(p===currentPage?' active':'');
    b.textContent=p;b.disabled=(p===currentPage);
    b.onclick=()=>{currentPage=p;renderTable();};
    btns.appendChild(b);
  });
}

// ── View book ──────────────────────────────────────────────
async function viewBook(id){
  id = parseInt(id);   // ensure integer
  if(!id){notify('Error','Invalid book ID.','error');return;}
  document.getElementById('viewModalBody').innerHTML='<div style="text-align:center;padding:30px;color:#9aaa9b"><i class="fas fa-spinner fa-spin" style="font-size:2rem"></i></div>';
  openModal('viewModal');
  try {
    const r  = await fetch(`api/books_handler.php?action=getBook&id=${id}`);
    const d  = await r.json();
    if(!d.success) throw new Error(d.message||'Not found');
    const b  = d.data;
    document.getElementById('viewModalBody').innerHTML=`
      <div class="detail-grid">
        <div class="detail-item full"><div class="detail-label">Title</div><div class="detail-value" style="font-size:1rem;font-weight:700">${esc(b.title||'—')}</div></div>
        <div class="detail-item"><div class="detail-label">Author</div><div class="detail-value">${esc(b.author||'—')}</div></div>
        <div class="detail-item"><div class="detail-label">Subject</div><div class="detail-value">${esc(b.subject||'—')}</div></div>
        <div class="detail-item"><div class="detail-label">Class / Level</div><div class="detail-value">${esc(b.class||'—')}</div></div>
        <div class="detail-item"><div class="detail-label">Publisher</div><div class="detail-value">${esc(b.publisher||'—')}</div></div>
        <div class="detail-item"><div class="detail-label">Supplier</div><div class="detail-value">${esc(b.supplier||'—')}</div></div>
        <div class="detail-item"><div class="detail-label">Copies</div><div class="detail-value"><span class="badge ${b.copies<2?'badge-amber':'badge-green'}">${parseInt(b.copies)||0} copies</span></div></div>
        <div class="detail-item"><div class="detail-label">Date Added</div><div class="detail-value">${fmtDate(b.date_added)}</div></div>
        ${b.notes?`<div class="detail-item full"><div class="detail-label">Notes</div><div class="detail-value">${esc(b.notes)}</div></div>`:''}
      </div>`;
  } catch(e){
    document.getElementById('viewModalBody').innerHTML=`<p style="color:var(--red);padding:20px">${esc(e.message)}</p>`;
  }
}

// ── Edit book ──────────────────────────────────────────────
async function editBook(id){
  id = parseInt(id);
  if(!id){notify('Error','Invalid book ID.','error');return;}
  try {
    const r = await fetch(`api/books_handler.php?action=getBook&id=${id}`);
    const d = await r.json();
    if(!d.success) throw new Error(d.message||'Not found');
    const b = d.data;
    document.getElementById('editBookId').value  = b.book_id;
    document.getElementById('editTitle').value   = b.title || '';
    document.getElementById('editAuthor').value  = b.author || '';
    document.getElementById('editSubject').value = b.subject || '';
    document.getElementById('editClass').value   = b.class || '';
    document.getElementById('editPublisher').value = b.publisher || '';
    document.getElementById('editSupplier').value  = b.supplier || '';
    document.getElementById('editCopies').value    = b.copies || 1;
    document.getElementById('editNotes').value     = b.notes || '';
    openModal('editModal');
  } catch(e){notify('Error',e.message,'error');}
}

// ── Open add modal ─────────────────────────────────────────
function openAddModal(){
  // Reset form
  ['addTitle','addAuthor','addSubject','addClass','addPublisher','addSupplier','addNotes'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('addCopies').value=1;
  const now=new Date();now.setSeconds(0,0);
  document.getElementById('addDateTime').value=now.toISOString().slice(0,16);
  openModal('addModal');
}

// ── Save new book ──────────────────────────────────────────
async function saveBook(){
  const title  = document.getElementById('addTitle').value.trim();
  const author = document.getElementById('addAuthor').value.trim();
  const subject= document.getElementById('addSubject').value.trim();
  const copies = parseInt(document.getElementById('addCopies').value) || 0;
  if(!title||!author||!subject||copies<1){
    notify('Validation Error','Title, author, subject and at least 1 copy are required.','error');
    return;
  }
  const payload={
    action:'addBook',
    csrf_token: CSRF,
    title, author, subject,
    class:     document.getElementById('addClass').value.trim(),
    publisher: document.getElementById('addPublisher').value.trim(),
    supplier:  document.getElementById('addSupplier').value.trim(),
    copies,
    notes:     document.getElementById('addNotes').value.trim(),
    date_added:document.getElementById('addDateTime').value
  };
  try {
    const r=await fetch('api/books_handler.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const d=await r.json();
    if(!d.success) throw new Error(d.message||'Save failed');
    notify('Added',`"${esc(title)}" added to catalog.`,'success');
    closeModal('addModal');
    loadBooks();
  } catch(e){notify('Error',e.message,'error');}
}

// ── Update book ────────────────────────────────────────────
async function updateBook(){
  const id     = parseInt(document.getElementById('editBookId').value)||0;
  const title  = document.getElementById('editTitle').value.trim();
  const author = document.getElementById('editAuthor').value.trim();
  const subject= document.getElementById('editSubject').value.trim();
  const copies = parseInt(document.getElementById('editCopies').value)||0;
  if(!id||!title||!author||!subject||copies<1){
    notify('Validation Error','All required fields must be filled.','error');
    return;
  }
  const payload={
    action:'updateBook',
    csrf_token:CSRF, book_id:id,
    title, author, subject,
    class:     document.getElementById('editClass').value.trim(),
    publisher: document.getElementById('editPublisher').value.trim(),
    supplier:  document.getElementById('editSupplier').value.trim(),
    copies,
    notes:     document.getElementById('editNotes').value.trim()
  };
  try {
    const r=await fetch('api/books_handler.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const d=await r.json();
    if(!d.success) throw new Error(d.message||'Update failed');
    notify('Updated',`"${esc(title)}" updated successfully.`,'success');
    closeModal('editModal');
    loadBooks();
  } catch(e){notify('Error',e.message,'error');}
}

// ── Delete book ────────────────────────────────────────────
let dlgCb=null;
function confirmDelete(id, title){
  id=parseInt(id);
  document.getElementById('dlgMsg').innerHTML=`Delete <strong>${title}</strong> from the catalog?<br><small style="color:#9aaa9b">This cannot be undone.</small>`;
  dlgCb=async()=>{
    try {
      const r=await fetch('api/books_handler.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'deleteBook',csrf_token:CSRF,book_id:id})});
      const d=await r.json();
      if(!d.success) throw new Error(d.message||'Delete failed');
      notify('Deleted',`"${title}" removed from catalog.`,'success');
      loadBooks();
    } catch(e){notify('Error',e.message,'error');}
  };
  document.getElementById('confirmDlg').classList.add('active');
}
function closeDlg(){document.getElementById('confirmDlg').classList.remove('active');dlgCb=null;}
function runDlgCb(){if(dlgCb)dlgCb();closeDlg();}

// ── Modal helpers ──────────────────────────────────────────
function openModal(id){document.getElementById(id).classList.add('active');}
function closeModal(id){document.getElementById(id).classList.remove('active');}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeModal('addModal');closeModal('editModal');closeModal('viewModal');closeDlg();}});

// ── Export PDF ─────────────────────────────────────────────
function exportToPDF(){
  if(!filtered.length){notify('Empty','No books to export.','warning');return;}
  const {jsPDF}=window.jspdf;
  const doc=new jsPDF('landscape');
  doc.setFontSize(16);doc.setTextColor(46,125,50);
  doc.text('Library Catalog Report',14,18);
  doc.setFontSize(9);doc.setTextColor(120);
  doc.text('Generated: '+new Date().toLocaleDateString('en-UG'),14,26);
  doc.autoTable({
    head:[['Title','Author','Subject','Class','Publisher','Copies','Date Added']],
    body:filtered.map(b=>[b.title,b.author,b.subject,b.class||'—',b.publisher||'—',b.copies,fmtDate(b.date_added)]),
    startY:32,theme:'grid',
    headStyles:{fillColor:[56,142,60],fontSize:8},bodyStyles:{fontSize:7.5}
  });
  doc.save('library-catalog-'+new Date().toISOString().split('T')[0]+'.pdf');
  notify('Exported','PDF downloaded.','success');
}

// ── Export Excel ───────────────────────────────────────────
function exportToExcel(){
  if(!filtered.length){notify('Empty','No books to export.','warning');return;}
  const data=[['Date Added','Title','Author','Subject','Class','Publisher','Supplier','Copies','Notes']];
  filtered.forEach(b=>data.push([fmtDate(b.date_added),b.title,b.author,b.subject,b.class||'',b.publisher||'',b.supplier||'',b.copies,b.notes||'']));
  const wb=XLSX.utils.book_new();
  const ws=XLSX.utils.aoa_to_sheet(data);
  ws['!cols']=[{wch:13},{wch:35},{wch:20},{wch:16},{wch:12},{wch:20},{wch:18},{wch:8},{wch:30}];
  XLSX.utils.book_append_sheet(wb,ws,'Books');
  XLSX.writeFile(wb,'library-catalog-'+new Date().toISOString().split('T')[0]+'.xlsx');
  notify('Exported','Excel file downloaded.','success');
}

// ── Init ───────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', loadBooks);
</script>
</body>
</html>