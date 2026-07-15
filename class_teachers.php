<?php
require_once 'auth.php';
require_once 'tracking.php';
$tracker->trackAction("Class Teachers");

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Class Teachers &mdash; School Pilot</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
  <style>
  /* ── Variables ─────────────────────────────────────────── */
  :root {
    --g900:#1b5e20; --g800:#2e7d32; --g700:#388e3c; --g600:#43a047;
    --g400:#66bb6a; --g100:#e8f5e9; --g50:#f1f8f1;
    --blue:#1565c0; --blue-light:#e3f2fd;
    --red:#d32f2f; --red-light:#ffebee;
    --orange:#e65100; --gray:#546e7a;
    --amber:#f57f17; --amber-light:#fff8e1;
    --radius:8px; --radius-lg:12px;
    --shadow:0 2px 8px rgba(0,0,0,.10);
    --shadow-lg:0 8px 28px rgba(0,0,0,.14);
    --tr:.22s ease;
  }
  *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:"Sen",system-ui,sans-serif; background:#f0f4f1; min-height:100vh; color:#222; }

  /* ── Layout ─────────────────────────────────────────────── */
  .page { max-width:100%; margin:0 auto; padding:24px 20px 48px; }

  /* ── Page Header ─────────────────────────────────────────── */
  .page-header {
    background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);
    border-radius:var(--radius-lg); padding:28px 32px;
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:20px; margin-bottom:24px; margin-top:40px;
    box-shadow:var(--shadow-lg);
  }
  .page-header h1 { color:#fff; font-size:1.55rem; font-weight:700; letter-spacing:.3px; }
  .page-header p  { color:rgba(255,255,255,.78); font-size:.9rem; margin-top:3px; }
  .header-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }

  /* ── Stat Pills ──────────────────────────────────────────── */
  .stats-row { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:24px; }
  .stat-card {
    background:#fff; border-radius:var(--radius-lg); padding:18px 24px;
    box-shadow:var(--shadow); border-left:4px solid var(--g600);
    flex:1 1 160px; min-width:140px;
    transition:transform var(--tr), box-shadow var(--tr);
  }
  .stat-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-lg); }
  .stat-card.vacant { border-left-color:var(--red); }
  .stat-card.all-streams { border-left-color:var(--blue); }
  .stat-val   { font-size:2rem; font-weight:800; color:var(--g800); line-height:1; }
  .stat-card.vacant .stat-val { color:var(--red); }
  .stat-card.all-streams .stat-val { color:var(--blue); }
  .stat-lbl   { font-size:.75rem; color:#6b7c6d; text-transform:uppercase; letter-spacing:.6px; margin-top:4px; }

  /* ── Card ────────────────────────────────────────────────── */
  .card { background:#fff; border-radius:var(--radius-lg); box-shadow:var(--shadow); overflow:hidden; }

  /* ── Toolbar ─────────────────────────────────────────────── */
  .toolbar { padding:16px 24px; border-bottom:1px solid #e8ede9; display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
  .toolbar-left  { display:flex; flex-wrap:wrap; gap:10px; align-items:center; flex:1 1 auto; }
  .toolbar-right { display:flex; gap:10px; align-items:center; flex-shrink:0; }
  .search-wrap { position:relative; min-width:240px; }
  .search-wrap i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#8a9a8b; font-size:.85rem; }
  .search-wrap input {
    width:100%; padding:9px 12px 9px 34px; border:1.5px solid #d0dbd1;
    border-radius:var(--radius); font-size:.875rem; font-family:inherit;
    transition:border-color var(--tr), box-shadow var(--tr);
  }
  .search-wrap input:focus { outline:none; border-color:var(--g600); box-shadow:0 0 0 3px rgba(67,160,71,.12); }
  .result-count { font-size:.8rem; color:#6b7c6d; white-space:nowrap; }

  /* ── Buttons ─────────────────────────────────────────────── */
  .btn {
    display:inline-flex; align-items:center; gap:7px;
    padding:9px 16px; border:none; border-radius:var(--radius);
    font-size:.85rem; font-weight:600; font-family:inherit;
    cursor:pointer; transition:all var(--tr); white-space:nowrap;
  }
  .btn:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(0,0,0,.15); }
  .btn:active { transform:none; }
  .btn:disabled { opacity:.6; cursor:not-allowed; transform:none !important; box-shadow:none !important; }
  .btn-primary { background:var(--g700); color:#fff; }
  .btn-primary:hover { background:var(--g800); }
  .btn-outline { background:transparent; color:var(--gray); border:1.5px solid #d0dbd1; }
  .btn-outline:hover { border-color:var(--gray); background:#f5f5f5; transform:none; }
  .btn-pdf   { background:#c62828; color:#fff; }
  .btn-pdf:hover   { background:var(--red); }
  .btn-excel { background:var(--g800); color:#fff; }
  .btn-excel:hover { background:var(--g900); }
  .btn-danger { background:var(--red); color:#fff; }
  .btn-danger:hover { background:#b71c1c; }
  .btn-sm { padding:6px 11px; font-size:.78rem; }

  /* ── Icon Buttons ────────────────────────────────────────── */
  .action-cell { display:flex; gap:5px; align-items:center; }
  .btn-icon {
    width:30px; height:30px; border:none; border-radius:6px;
    cursor:pointer; display:inline-flex; align-items:center;
    justify-content:center; font-size:.78rem; transition:all var(--tr); flex-shrink:0;
  }
  .btn-icon:hover { transform:translateY(-1px); box-shadow:0 3px 8px rgba(0,0,0,.18); }
  .bi-edit    { background:#fff3e0; color:var(--orange); }
  .bi-edit:hover    { background:var(--orange); color:#fff; }
  .bi-delete  { background:var(--red-light); color:var(--red); }
  .bi-delete:hover  { background:var(--red); color:#fff; }
  .bi-assign  { background:var(--g100); color:var(--g700); }
  .bi-assign:hover  { background:var(--g700); color:#fff; }

  /* ── Table ───────────────────────────────────────────────── */
  .table-wrap { overflow-x:auto; }
  table { width:100%; border-collapse:collapse; }
  thead tr { background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%); }
  thead th { padding:13px 14px; text-align:left; font-size:.8rem; font-weight:600; color:#fff; letter-spacing:.4px; white-space:nowrap; }
  tbody tr { border-bottom:1px solid #f0f4f1; transition:background var(--tr); }
  tbody tr:hover { background:#f5fbf5; }
  tbody td { padding:12px 14px; font-size:.875rem; vertical-align:middle; }

  /* ── Row modes ───────────────────────────────────────────── */
  tbody tr.row-all-streams { background:linear-gradient(90deg, rgba(21,101,192,.04) 0%, transparent 100%); }
  tbody tr.row-all-streams td:first-child { border-left:3px solid var(--blue); }
  tbody tr.row-vacant td:first-child { border-left:3px solid #e0e0e0; }

  /* ── Badges ──────────────────────────────────────────────── */
  .badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:20px; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.4px; }
  .badge-assigned    { background:var(--g100); color:var(--g800); }
  .badge-vacant      { background:var(--red-light); color:var(--red); }
  .badge-all-streams { background:var(--blue-light); color:var(--blue); }

  /* ── Stream Pill ─────────────────────────────────────────── */
  .stream-pill {
    display:inline-flex; align-items:center; gap:4px;
    padding:3px 9px; border-radius:12px; font-size:.72rem; font-weight:700;
    background:#f0f4f1; color:#5a6a5b; border:1px solid #d0dbd1;
  }
  .stream-pill.all { background:var(--blue-light); color:var(--blue); border-color:#90caf9; }

  /* ── Skeleton ────────────────────────────────────────────── */
  .skeleton-cell {
    background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);
    background-size:200% 100%; animation:shimmer 1.4s infinite;
    border-radius:4px; height:14px; display:inline-block; width:80%;
  }
  @keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }

  /* ── Empty ───────────────────────────────────────────────── */
  .empty-state { text-align:center; padding:60px 20px; color:#8a9a8b; }
  .empty-state i { font-size:3rem; margin-bottom:14px; display:block; opacity:.45; }

  /* ── Modal ───────────────────────────────────────────────── */
  .modal {
    display:none; position:fixed; inset:0; z-index:1000;
    background:rgba(0,0,0,.45); backdrop-filter:blur(3px);
    animation:fadeIn .2s ease;
  }
  @keyframes fadeIn { from{opacity:0} to{opacity:1} }
  .modal.active { display:flex; align-items:flex-start; justify-content:center; padding:20px 16px; overflow-y:auto; }
  .modal-box {
    background:#fff; border-radius:var(--radius-lg); width:100%;
    max-width:560px; box-shadow:var(--shadow-lg);
    animation:slideDown .25s ease; margin:auto;
  }
  @keyframes slideDown { from{transform:translateY(-24px);opacity:0} to{transform:translateY(0);opacity:1} }
  .modal-head {
    background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%);
    padding:20px 24px; border-radius:var(--radius-lg) var(--radius-lg) 0 0;
    display:flex; align-items:center; justify-content:space-between;
  }
  .modal-head h2 { color:#fff; font-size:1.1rem; font-weight:700; display:flex; align-items:center; gap:10px; }
  .modal-close {
    background:rgba(255,255,255,.15); border:none; color:#fff;
    width:32px; height:32px; border-radius:50%;
    cursor:pointer; display:flex; align-items:center; justify-content:center;
    transition:background var(--tr); font-size:1rem;
  }
  .modal-close:hover { background:rgba(255,255,255,.3); }
  .modal-body   { padding:24px 24px 8px; }
  .modal-footer { padding:16px 24px; border-top:1px solid #eef2ee; display:flex; gap:10px; justify-content:flex-end; }

  /* ── Form ────────────────────────────────────────────────── */
  .form-group { margin-bottom:18px; }
  .form-group label { display:block; margin-bottom:6px; font-size:.82rem; font-weight:600; color:#3a4a3b; }
  .form-group label .req { color:var(--red); }
  .form-control, .form-select {
    width:100%; padding:9px 13px; border:1.5px solid #d0dbd1;
    border-radius:var(--radius); font-size:.875rem; font-family:inherit;
    transition:border-color var(--tr), box-shadow var(--tr); background:#fff;
  }
  .form-control:focus, .form-select:focus {
    outline:none; border-color:var(--g600); box-shadow:0 0 0 3px rgba(67,160,71,.1);
  }

  /* ── Assignment Mode Toggle ──────────────────────────────── */
  .mode-toggle {
    display:flex; border:1.5px solid #d0dbd1; border-radius:var(--radius);
    overflow:hidden; margin-bottom:18px;
  }
  .mode-toggle input[type="radio"] { display:none; }
  .mode-toggle label {
    flex:1; padding:9px 12px; text-align:center; cursor:pointer;
    font-size:.82rem; font-weight:600; color:#6b7c6d;
    transition:all var(--tr); border:none; background:transparent;
    display:flex; align-items:center; justify-content:center; gap:6px;
  }
  .mode-toggle label:not(:last-child) { border-right:1.5px solid #d0dbd1; }
  .mode-toggle input[type="radio"]:checked + label {
    background:var(--g700); color:#fff;
  }
  #modePerStream:checked ~ .mode-label-per-stream,
  #modeAllStreams:checked ~ .mode-label-all { background:var(--g700); color:#fff; }

  /* re-implement toggle with JS classes instead */
  .mode-btn {
    flex:1; padding:9px 12px; text-align:center; cursor:pointer;
    font-size:.82rem; font-weight:600; color:#6b7c6d;
    border:none; background:transparent;
    display:flex; align-items:center; justify-content:center; gap:6px;
    transition:all var(--tr);
  }
  .mode-btn:not(:last-child) { border-right:1.5px solid #d0dbd1; }
  .mode-btn.active { background:var(--g700); color:#fff; }
  .mode-btn.active.mode-all { background:var(--blue); }

  /* ── Scope hint ──────────────────────────────────────────── */
  .scope-hint {
    background:var(--blue-light); border:1px solid #90caf9;
    border-radius:var(--radius); padding:10px 14px;
    font-size:.82rem; color:var(--blue); margin-bottom:16px;
    display:flex; align-items:flex-start; gap:8px;
  }
  .scope-hint i { margin-top:1px; flex-shrink:0; }
  .scope-hint.warning { background:var(--amber-light); border-color:#ffca28; color:var(--amber); }

  /* ── Confirm Dialog ──────────────────────────────────────── */
  .dialog { display:none; position:fixed; inset:0; z-index:2000; background:rgba(0,0,0,.5); backdrop-filter:blur(4px); align-items:center; justify-content:center; }
  .dialog.active { display:flex; }
  .dialog-box { background:#fff; border-radius:var(--radius-lg); width:100%; max-width:420px; box-shadow:var(--shadow-lg); animation:slideDown .22s ease; overflow:hidden; }
  .dialog-head { padding:18px 22px; display:flex; align-items:center; gap:12px; }
  .dialog-head.danger { background:linear-gradient(135deg,#c62828,#ef5350); color:#fff; }
  .dialog-head i { font-size:1.2rem; }
  .dialog-head h3 { font-size:1rem; font-weight:700; }
  .dialog-body { padding:22px; text-align:center; }
  .dialog-body p { font-size:.9rem; color:#555; line-height:1.6; margin-bottom:20px; }
  .dialog-actions { display:flex; gap:10px; justify-content:center; }

  /* ── Notifications ───────────────────────────────────────── */
  #notif-stack { position:fixed; top:20px; right:20px; z-index:3000; display:flex; flex-direction:column; gap:10px; max-width:360px; }
  .notif { background:#fff; border-radius:var(--radius); padding:14px 16px; box-shadow:var(--shadow-lg); display:flex; align-items:flex-start; gap:12px; border-left:4px solid var(--g600); animation:notifIn .3s ease; }
  .notif.error   { border-left-color:var(--red); }
  .notif.warning { border-left-color:var(--orange); }
  .notif.info    { border-left-color:var(--blue); }
  @keyframes notifIn { from{opacity:0;transform:translateX(30px)} to{opacity:1;transform:translateX(0)} }
  .notif-icon { font-size:1.1rem; margin-top:1px; flex-shrink:0; }
  .notif.success .notif-icon { color:var(--g700); }
  .notif.error   .notif-icon { color:var(--red); }
  .notif.warning .notif-icon { color:var(--orange); }
  .notif.info    .notif-icon { color:var(--blue); }
  .notif-body  { flex:1; }
  .notif-title { font-weight:700; font-size:.85rem; margin-bottom:2px; }
  .notif-msg   { font-size:.8rem; color:#666; }
  .notif-close { background:none; border:none; cursor:pointer; color:#aaa; font-size:1rem; padding:0; flex-shrink:0; }

  /* ── Responsive ──────────────────────────────────────────── */
  @media (max-width:700px) {
    .page-header { flex-direction:column; }
    .toolbar { flex-direction:column; align-items:stretch; }
    .stats-row { gap:10px; }
    .table-wrap table { min-width:640px; }
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
      <h1><i class="fas fa-chalkboard-teacher" style="margin-right:10px;opacity:.85"></i>Class Teachers</h1>
      <p>Assign class teachers per stream or across all streams of a class</p>
    </div>
    <div class="header-actions">
      <button class="btn btn-pdf"   id="exportPdfBtn"><i class="fas fa-file-pdf"></i> PDF</button>
      <button class="btn btn-excel" id="exportExcelBtn"><i class="fas fa-file-excel"></i> Excel</button>
      <button class="btn btn-primary" id="assignBtn"><i class="fas fa-plus"></i> Assign Class Teacher</button>
    </div>
  </div>

  <!-- Stats -->
  <div class="stats-row">
    <div class="stat-card">
      <div class="stat-val" id="sTotal">—</div>
      <div class="stat-lbl">Total Slots</div>
    </div>
    <div class="stat-card">
      <div class="stat-val" id="sAssigned">—</div>
      <div class="stat-lbl">Assigned</div>
    </div>
    <div class="stat-card vacant">
      <div class="stat-val" id="sVacant">—</div>
      <div class="stat-lbl">Vacant</div>
    </div>
    <div class="stat-card all-streams">
      <div class="stat-val" id="sAllStreams">—</div>
      <div class="stat-lbl">Whole-Class Assignments</div>
    </div>
  </div>

  <!-- Main Card -->
  <div class="card">
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search class, stream, teacher…" autocomplete="off">
        </div>
        <span class="result-count" id="resultCount"></span>
      </div>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:40px">#</th>
            <th>Class</th>
            <th>Stream</th>
            <th>Class Teacher</th>
            <th>Contact</th>
            <th>Status</th>
            <th style="width:100px">Actions</th>
          </tr>
        </thead>
        <tbody id="tBody"></tbody>
      </table>
    </div>
  </div>
</div><!-- /page -->

<!-- ══ ASSIGN MODAL ═══════════════════════════════════════ -->
<div id="assignModal" class="modal" onclick="modalOutsideClick(event,'assignModal')">
  <div class="modal-box">
    <div class="modal-head">
      <h2 id="modalTitle"><i class="fas fa-user-check"></i> Assign Class Teacher</h2>
      <button class="modal-close" onclick="closeModal('assignModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">

      <!-- Assignment Mode -->
      <label style="font-size:.82rem;font-weight:600;color:#3a4a3b;display:block;margin-bottom:8px">
        Assignment Mode <span style="color:var(--red)">*</span>
      </label>
      <div class="mode-toggle" id="modeToggle">
        <button type="button" class="mode-btn active" id="modePerStreamBtn" onclick="setMode('per-stream')">
          <i class="fas fa-stream"></i> Per Stream
        </button>
        <button type="button" class="mode-btn mode-all" id="modeAllBtn" onclick="setMode('all-streams')">
          <i class="fas fa-layer-group"></i> All Streams of Class
        </button>
      </div>

      <!-- Scope hint -->
      <div class="scope-hint" id="scopeHint">
        <i class="fas fa-info-circle"></i>
        <span id="scopeHintText">Assign one teacher to a <strong>specific stream</strong>. Other streams are unaffected.</span>
      </div>

      <div class="form-group">
        <label>Class <span class="req">*</span></label>
        <select id="classSelect" class="form-select">
          <option value="">— Select class —</option>
          <option value="Senior one">Senior One</option>
          <option value="Senior Two">Senior Two</option>
          <option value="Senior Three">Senior Three</option>
          <option value="Senior Four">Senior Four</option>
          <option value="Senior Five">Senior Five</option>
          <option value="Senior Six">Senior Six</option>
        </select>
      </div>

      <div class="form-group" id="streamGroup">
          <select id="streamSelect" class="form-select">
        <label>Stream <span class="req">*</span></label>
            <option value="">— Select stream —</option>
            <option value="East">East</option>
            <option value="West">West</option>
            <option value="South">South</option>
            <option value="North">North</option>
            <option value="Arts">Arts</option>
            <option value="Sciences">Sciences</option>
            </select>
      </div>

      <div class="form-group">
        <label>Teacher <span class="req">*</span></label>
        <select id="teacherSelect" class="form-select">
          <option value="">— Select teacher —</option>
        </select>
      </div>

    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('assignModal')">Cancel</button>
      <button class="btn btn-primary" id="saveBtn">
        <i class="fas fa-save" id="saveBtnIcon"></i>
        <span id="saveBtnLabel">Assign Teacher</span>
      </button>
    </div>
  </div>
</div>

<!-- ══ DELETE DIALOG ══════════════════════════════════════ -->
<div id="confirmDlg" class="dialog">
  <div class="dialog-box">
    <div class="dialog-head danger">
      <i class="fas fa-trash"></i>
      <h3>Remove Assignment</h3>
    </div>
    <div class="dialog-body">
      <p id="dlgMsg">Remove this class teacher assignment?</p>
      <div class="dialog-actions">
        <button class="btn btn-outline" onclick="closeDlg()">Cancel</button>
        <button class="btn btn-danger" id="dlgConfirmBtn">Remove</button>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF = '<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>';

// ── Helpers ───────────────────────────────────────────────
function esc(v) {
  return String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function notify(title, msg, type = 'success', dur = 4500) {
  const icons = {success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info'};
  const n = document.createElement('div');
  n.className = `notif ${type}`;
  n.innerHTML = `
    <i class="fas ${icons[type]||icons.info} notif-icon"></i>
    <div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div>
    <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
  document.getElementById('notif-stack').prepend(n);
  setTimeout(() => {
    n.style.cssText += 'opacity:0;transform:translateX(30px);transition:.3s';
    setTimeout(() => n.remove(), 300);
  }, dur);
}
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function closeDlg()     { pendingDelete = null; document.getElementById('confirmDlg').classList.remove('active'); }
function modalOutsideClick(e, id) { if (e.target.id === id) closeModal(id); }

// ── Class → Streams map ───────────────────────────────────
// "All Streams" is a virtual stream value stored as 'All Streams' in class_teachers.stream
const CLASS_STREAMS = {
  'Senior one':   ['East', 'West', 'South', 'North'],
  'Senior Two':   ['East', 'West', 'South', 'North'],
  'Senior Three': ['East', 'West', 'South', 'North'],
  'Senior Four':  ['East', 'West', 'South', 'North'],
  'Senior Five':  ['Arts', 'Sciences'],
  'Senior Six':   ['Arts', 'Sciences'],
};
// All rows shown in the table (per-stream level, not counting "All Streams" as a row)
const ALL_SLOTS = Object.entries(CLASS_STREAMS).flatMap(([cls, streams]) =>
  streams.map(s => ({ class: cls, stream: s }))
);

// ── State ─────────────────────────────────────────────────
let classTeachers = [];   // rows from DB: {id, class_name, stream, staff_id, full_name, phone_number}
let staffList     = [];
let pendingDelete = null;
let currentMode   = 'per-stream'; // 'per-stream' | 'all-streams'
let filteredRows  = [];           // what's currently rendered

// ── Init ──────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  loadAll();

  let searchTimer;
  document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => renderTable(this.value.trim().toLowerCase()), 250);
  });

  document.getElementById('assignBtn').addEventListener('click', openAssignModal);
  document.getElementById('saveBtn').addEventListener('click', handleSave);
  document.getElementById('dlgConfirmBtn').addEventListener('click', runDelete);

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeModal('assignModal'); closeDlg(); }
  });
});

// ── Load All ──────────────────────────────────────────────
function loadAll() {
  showSkeleton();
  Promise.all([
    fetch('api/get_class_teachers.php').then(r => r.json()),
    fetch('api/get_teaching_staff.php').then(r => r.json()),
  ]).then(([ct, staff]) => {
    if (ct.success)    classTeachers = ct.classTeachers;
    if (staff.success) staffList     = staff.staff;
    populateStaffDropdown();
    renderTable('');
    updateStats();
  }).catch(() => {
    notify('Error', 'Failed to load page data. Please refresh.', 'error');
    document.getElementById('tBody').innerHTML =
      `<tr><td colspan="7"><div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Failed to load data. Please refresh.</p></div></td></tr>`;
  });
}

function showSkeleton() {
  document.getElementById('tBody').innerHTML =
    Array.from({length:8}, () =>
      `<tr>${Array.from({length:7},()=>`<td><span class="skeleton-cell"></span></td>`).join('')}</tr>`
    ).join('');
}

// ── Populate Staff Dropdown ───────────────────────────────
function populateStaffDropdown() {
  const sel = document.getElementById('teacherSelect');
  sel.innerHTML = '<option value="">— Select teacher —</option>';
  staffList.forEach(s => {
    const opt = document.createElement('option');
    opt.value       = s.id;
    opt.textContent = s.full_name;
    opt.dataset.phone = s.phone_number || '';
    sel.appendChild(opt);
  });
}

// ── Render Table ──────────────────────────────────────────
function renderTable(q = '') {
  const tbody = document.getElementById('tBody');

  // Build display rows: per-stream slots PLUS any "All Streams" entries
  // "All Streams" rows appear at the top of each class group
  const allStreamsCTs = classTeachers.filter(c => c.stream === 'All Streams');
  const classesWithAllStreams = new Set(allStreamsCTs.map(c => c.class_name));

  // Build rows: "All Streams" rows first (collapsed), then per-stream rows
  let rows = [];

  // Group ALL_SLOTS by class
  const byClass = {};
  ALL_SLOTS.forEach(slot => {
    if (!byClass[slot.class]) byClass[slot.class] = [];
    byClass[slot.class].push(slot);
  });

  Object.entries(byClass).forEach(([cls, slots]) => {
    if (classesWithAllStreams.has(cls)) {
      // Show one collapsed "All Streams" row
      const ct = allStreamsCTs.find(c => c.class_name === cls);
      rows.push({ class: cls, stream: 'All Streams', ct, type: 'all-streams' });
    } else {
      // Show per-stream rows
      slots.forEach(slot => {
        const ct = classTeachers.find(c => c.class_name === slot.class && c.stream === slot.stream);
        rows.push({ class: slot.class, stream: slot.stream, ct: ct || null, type: ct ? 'assigned' : 'vacant' });
      });
    }
  });

  // Filter
  filteredRows = q
    ? rows.filter(r => {
        const hay = [r.class, r.stream, r.ct?.full_name || '', r.ct?.phone_number || ''].join(' ').toLowerCase();
        return hay.includes(q);
      })
    : rows;

  document.getElementById('resultCount').textContent =
    filteredRows.length < rows.length
      ? `${filteredRows.length} of ${rows.length} rows`
      : `${rows.length} row${rows.length !== 1 ? 's' : ''}`;

  if (!filteredRows.length) {
    tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state">
      <i class="fas fa-chalkboard-teacher"></i>
      <p>${rows.length === 0 ? 'No class/stream combinations configured.' : 'No rows match your search.'}</p>
    </div></td></tr>`;
    return;
  }

  tbody.innerHTML = filteredRows.map((r, i) => {
    const isAllStreams = r.type === 'all-streams';
    const isVacant    = !r.ct;

    const streamCell = isAllStreams
      ? `<span class="stream-pill all"><i class="fas fa-layer-group"></i> All Streams</span>`
      : `<span class="stream-pill">${esc(r.stream)}</span>`;

    const teacherCell = r.ct
      ? `<span style="font-weight:600;color:var(--g800)">${esc(r.ct.full_name)}</span>`
      : `<em style="color:#bbb">Not assigned</em>`;

    const contactCell = r.ct ? esc(r.ct.phone_number || '—') : '—';

    const statusBadge = isAllStreams
      ? `<span class="badge badge-all-streams"><i class="fas fa-layer-group"></i> All Streams</span>`
      : r.ct
        ? `<span class="badge badge-assigned"><i class="fas fa-check"></i> Assigned</span>`
        : `<span class="badge badge-vacant"><i class="fas fa-times"></i> Vacant</span>`;

    // Actions
    let actions = '';
    if (r.ct) {
      actions = `
        <button class="btn-icon bi-edit"   data-class="${esc(r.class)}" data-stream="${esc(r.stream)}" data-staff="${esc(r.ct.staff_id)}" title="Change teacher"><i class="fas fa-pen"></i></button>
        <button class="btn-icon bi-delete" data-class="${esc(r.class)}" data-stream="${esc(r.stream)}" data-name="${esc(r.class)} – ${esc(r.stream)}" title="Remove assignment"><i class="fas fa-trash"></i></button>`;
    } else {
      actions = `
        <button class="btn-icon bi-assign" data-class="${esc(r.class)}" data-stream="${esc(r.stream)}" title="Assign teacher"><i class="fas fa-plus"></i></button>`;
    }

    const rowClass = isAllStreams ? 'row-all-streams' : isVacant ? 'row-vacant' : '';

    return `<tr class="${rowClass}">
      <td style="color:#9aaa9b;font-size:.78rem">${i + 1}</td>
      <td style="font-weight:600">${esc(r.class)}</td>
      <td>${streamCell}</td>
      <td>${teacherCell}</td>
      <td style="font-size:.82rem;color:#546e7a">${contactCell}</td>
      <td>${statusBadge}</td>
      <td><div class="action-cell">${actions}</div></td>
    </tr>`;
  }).join('');

  // Bind action buttons
  tbody.querySelectorAll('.bi-edit, .bi-assign').forEach(btn => {
    btn.addEventListener('click', () => {
      const stream = btn.dataset.stream;
      const mode   = stream === 'All Streams' ? 'all-streams' : 'per-stream';
      openAssignModal(btn.dataset.class, stream === 'All Streams' ? '' : stream, btn.dataset.staff || '', mode);
    });
  });
  tbody.querySelectorAll('.bi-delete').forEach(btn => {
    btn.addEventListener('click', () => promptDelete(btn.dataset.class, btn.dataset.stream, btn.dataset.name));
  });
}

// ── Stats ─────────────────────────────────────────────────
function updateStats() {
  const allStreamsCTs = classTeachers.filter(c => c.stream === 'All Streams');
  const classesWithAll = new Set(allStreamsCTs.map(c => c.class_name));

  // Count "effective" slots (All Streams replaces per-stream slots for that class)
  let totalSlots    = 0;
  let assignedCount = 0;

  Object.entries(CLASS_STREAMS).forEach(([cls, streams]) => {
    if (classesWithAll.has(cls)) {
      totalSlots    += 1; // counted as one whole-class slot
      assignedCount += 1;
    } else {
      totalSlots += streams.length;
      assignedCount += streams.filter(s =>
        classTeachers.some(c => c.class_name === cls && c.stream === s)
      ).length;
    }
  });

  document.getElementById('sTotal').textContent      = totalSlots;
  document.getElementById('sAssigned').textContent   = assignedCount;
  document.getElementById('sVacant').textContent     = totalSlots - assignedCount;
  document.getElementById('sAllStreams').textContent = allStreamsCTs.length;
}

// ── Mode Toggle ───────────────────────────────────────────
function setMode(mode) {
  currentMode = mode;
  const perBtn  = document.getElementById('modePerStreamBtn');
  const allBtn  = document.getElementById('modeAllBtn');
  const sg      = document.getElementById('streamGroup');
  const hint    = document.getElementById('scopeHint');
  const hintTxt = document.getElementById('scopeHintText');

  perBtn.classList.toggle('active',      mode === 'per-stream');
  allBtn.classList.toggle('active',      mode === 'all-streams');

  if (mode === 'all-streams') {
    sg.style.display = 'none';
    hint.className   = 'scope-hint warning';
    hintTxt.innerHTML = `This teacher will be assigned as class teacher for <strong>all streams</strong> of the chosen class. Any existing per-stream assignments for that class will be replaced.`;
  } else {
    sg.style.display = '';
    hint.className   = 'scope-hint';
    hintTxt.innerHTML = `Assign one teacher to a <strong>specific stream</strong> only. Other streams are unaffected.`;
  }
}

// ── Open Assign Modal ──────────────────────────────────────
function openAssignModal(cls = '', stream = '', staffId = '', mode = 'per-stream') {
  setMode(mode);
  document.getElementById('classSelect').value   = cls;
  document.getElementById('streamSelect').value  = stream;
  document.getElementById('teacherSelect').value = staffId;
  document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-check"></i> Assign Class Teacher';
  document.getElementById('saveBtnLabel').textContent = staffId ? 'Save Changes' : 'Assign Teacher';
  openModal('assignModal');
}

// ── Handle Save ────────────────────────────────────────────
function handleSave() {
  const cls      = document.getElementById('classSelect').value;
  const stream   = currentMode === 'all-streams' ? 'All Streams' : document.getElementById('streamSelect').value;
  const staffId  = document.getElementById('teacherSelect').value;

  if (!cls)     { notify('Validation', 'Please select a class.', 'warning');   return; }
  if (!stream)  { notify('Validation', 'Please select a stream.', 'warning');  return; }
  if (!staffId) { notify('Validation', 'Please select a teacher.', 'warning'); return; }

  const btn  = document.getElementById('saveBtn');
  const icon = document.getElementById('saveBtnIcon');
  btn.disabled = true;
  icon.className = 'fas fa-spinner fa-spin';

  // If "All Streams" mode: first delete any individual per-stream records for this class
  const preparePromise = currentMode === 'all-streams'
    ? deletePerStreamAssignments(cls)
    : Promise.resolve();

  preparePromise
    .then(() => fetch('api/save_class_teacher.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ staff_id: staffId, class_name: cls, stream, csrf_token: CSRF }),
    }))
    .then(r => r.json())
    .then(d => {
      if (!d.success) throw new Error(d.message);
      notify('Success',
        currentMode === 'all-streams'
          ? `Class teacher assigned to all streams of ${cls}.`
          : 'Class teacher assigned successfully.',
        'success');
      closeModal('assignModal');
      loadAll();
    })
    .catch(err => notify('Error', err.message || 'Failed to save assignment.', 'error'))
    .finally(() => {
      btn.disabled = false;
      icon.className = 'fas fa-save';
    });
}

// Delete per-stream records when switching to "All Streams" mode
function deletePerStreamAssignments(cls) {
  const streams = CLASS_STREAMS[cls] || [];
  const existing = classTeachers.filter(c => c.class_name === cls && c.stream !== 'All Streams');
  if (!existing.length) return Promise.resolve();

  return Promise.all(existing.map(c =>
    fetch('api/delete_class_teacher.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ class_name: cls, stream: c.stream, csrf_token: CSRF }),
    })
  ));
}

// ── Delete ─────────────────────────────────────────────────
function promptDelete(cls, stream, label) {
  pendingDelete = { class_name: cls, stream };
  const streamLabel = stream === 'All Streams' ? 'all streams' : stream;
  document.getElementById('dlgMsg').innerHTML =
    `Remove the class teacher assignment for <strong>${esc(cls)}</strong> &ndash; <strong>${esc(streamLabel)}</strong>? This cannot be undone.`;
  document.getElementById('confirmDlg').classList.add('active');
}

function runDelete() {
  if (!pendingDelete) return;
  const btn = document.getElementById('dlgConfirmBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Removing…';

  fetch('api/delete_class_teacher.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ...pendingDelete, csrf_token: CSRF }),
  })
  .then(r => r.json())
  .then(d => {
    if (!d.success) throw new Error(d.message);
    notify('Removed', 'Class teacher assignment removed.', 'success');
    closeDlg();
    loadAll();
  })
  .catch(err => notify('Error', err.message || 'Removal failed.', 'error'))
  .finally(() => {
    btn.disabled = false;
    btn.innerHTML = 'Remove';
  });
}

// ── Export PDF ─────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('exportPdfBtn').addEventListener('click', () => {
    if (!filteredRows.length) { notify('Empty', 'No data to export.', 'warning'); return; }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    doc.setFontSize(16); doc.setTextColor(46,125,50);
    doc.text('Class Teachers Report', 14, 18);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text('Generated: ' + new Date().toLocaleDateString('en-UG'), 14, 25);
    doc.autoTable({
      head:[['#','Class','Stream','Teacher','Contact','Status']],
      body: filteredRows.map((r,i) => [
        i+1, r.class,
        r.stream === 'All Streams' ? 'All Streams (whole class)' : r.stream,
        r.ct ? r.ct.full_name : 'Not Assigned',
        r.ct ? (r.ct.phone_number||'—') : '—',
        r.stream === 'All Streams' ? 'Whole-Class' : (r.ct ? 'Assigned' : 'Vacant'),
      ]),
      startY:30, theme:'grid',
      headStyles:{ fillColor:[67,160,71], fontSize:8 },
      bodyStyles:{ fontSize:7.5 },
    });
    doc.save('class-teachers-' + new Date().toISOString().split('T')[0] + '.pdf');
    notify('Exported', 'PDF downloaded.', 'success');
  });

  document.getElementById('exportExcelBtn').addEventListener('click', () => {
    if (!filteredRows.length) { notify('Empty', 'No data to export.', 'warning'); return; }
    const data = [['#','Class','Stream','Teacher','Contact','Status']];
    filteredRows.forEach((r,i) => data.push([
      i+1, r.class,
      r.stream === 'All Streams' ? 'All Streams' : r.stream,
      r.ct ? r.ct.full_name : 'Not Assigned',
      r.ct ? (r.ct.phone_number||'—') : '—',
      r.stream === 'All Streams' ? 'Whole-Class' : (r.ct ? 'Assigned' : 'Vacant'),
    ]));
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [{wch:5},{wch:16},{wch:14},{wch:24},{wch:16},{wch:14}];
    XLSX.utils.book_append_sheet(wb, ws, 'Class Teachers');
    XLSX.writeFile(wb, 'class-teachers-' + new Date().toISOString().split('T')[0] + '.xlsx');
    notify('Exported', 'Excel file downloaded.', 'success');
  });
});
</script>
</body>
</html>