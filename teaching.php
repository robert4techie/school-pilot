<?php
require_once 'auth.php';
require_once 'tracking.php';
$tracker->trackAction("Staff Roles");

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
  <title>Teaching Staff &mdash; School Pilot</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
  <style>
  /* ── Variables ─────────────────────────────────────── */
  :root {
    --g900:#1b5e20; --g800:#2e7d32; --g700:#388e3c; --g600:#43a047;
    --g400:#66bb6a; --g100:#e8f5e9; --g50:#f1f8f1;
    --blue:#1565c0; --red:#d32f2f; --orange:#e65100; --gray:#546e7a;
    --radius:8px; --radius-lg:12px;
    --shadow:0 2px 8px rgba(0,0,0,.10);
    --shadow-lg:0 8px 28px rgba(0,0,0,.14);
    --transition:.22s ease;
  }
  *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:"Sen",system-ui,sans-serif; background:#f0f4f1; min-height:100vh; color:#222; }
  a { color:inherit; text-decoration:none; }

  /* ── Layout ─────────────────────────────────────────── */
  .page { max-width:100%; margin:0 auto; padding:24px 20px 48px; }

  /* ── Page Header ────────────────────────────────────── */
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

  /* ── Card ───────────────────────────────────────────── */
  .card { background:#fff; border-radius:var(--radius-lg); box-shadow:var(--shadow); overflow:hidden; }

  /* ── Toolbar ────────────────────────────────────────── */
  .toolbar { padding:18px 24px; border-bottom:1px solid #e8ede9; display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
  .toolbar-left  { display:flex; flex-wrap:wrap; gap:10px; align-items:center; flex:1 1 auto; }
  .toolbar-right { display:flex; gap:10px; align-items:center; flex-shrink:0; }
  .search-wrap { position:relative; min-width:260px; }
  .search-wrap i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#8a9a8b; font-size:.85rem; }
  .search-wrap input {
    width:100%; padding:9px 12px 9px 34px;
    border:1.5px solid #d0dbd1; border-radius:var(--radius);
    font-size:.875rem; font-family:inherit;
    transition:border-color var(--transition), box-shadow var(--transition);
  }
  .search-wrap input:focus { outline:none; border-color:var(--g600); box-shadow:0 0 0 3px rgba(67,160,71,.12); }
  .result-count { font-size:.8rem; color:#6b7c6d; white-space:nowrap; }

  /* ── Buttons ────────────────────────────────────────── */
  .btn {
    display:inline-flex; align-items:center; gap:7px;
    padding:9px 16px; border:none; border-radius:var(--radius);
    font-size:.85rem; font-weight:600; font-family:inherit;
    cursor:pointer; transition:all var(--transition); white-space:nowrap;
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
  .btn-sm { padding:7px 12px; font-size:.8rem; }

  /* ── Icon Buttons ───────────────────────────────────── */
  .action-cell { display:flex; gap:5px; align-items:center; }
  .btn-icon {
    width:30px; height:30px; border:none; border-radius:6px;
    cursor:pointer; display:inline-flex; align-items:center;
    justify-content:center; font-size:.78rem;
    transition:all var(--transition); flex-shrink:0;
  }
  .btn-icon:hover { transform:translateY(-1px); box-shadow:0 3px 8px rgba(0,0,0,.18); }
  .bi-edit   { background:#fff3e0; color:var(--orange); }
  .bi-edit:hover   { background:var(--orange); color:#fff; }
  .bi-delete { background:#ffebee; color:var(--red); }
  .bi-delete:hover { background:var(--red); color:#fff; }

  /* ── Table ──────────────────────────────────────────── */
  .table-wrap { overflow-x:auto; }
  table { width:100%; border-collapse:collapse; }
  thead tr { background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%); }
  thead th { padding:13px 14px; text-align:left; font-size:.8rem; font-weight:600; color:#fff; letter-spacing:.4px; white-space:nowrap; }
  tbody tr { border-bottom:1px solid #f0f4f1; transition:background var(--transition); }
  tbody tr:hover { background:#f5fbf5; }
  tbody td { padding:13px 14px; font-size:.875rem; vertical-align:middle; }

  /* ── Badges ─────────────────────────────────────────── */
  .badge { display:inline-block; padding:4px 10px; border-radius:20px; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.4px; }
  .badge-subject  { background:var(--g100); color:var(--g800); margin:2px; display:inline-block; }
  .badge-classteacher { background:#e3f2fd; color:var(--blue); }

  .class-combo {
    background:#f8faf8; border:1px solid #e0e8e0;
    border-left:3px solid var(--g500,var(--g600));
    border-radius:var(--radius); padding:8px 10px;
    margin-bottom:6px; font-size:.82rem;
  }
  .class-combo:last-child { margin-bottom:0; }
  .combo-header { font-weight:700; color:var(--g800); margin-bottom:3px; display:flex; align-items:center; gap:6px; }

  /* ── Skeleton ───────────────────────────────────────── */
  .skeleton-cell {
    background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);
    background-size:200% 100%; animation:shimmer 1.4s infinite;
    border-radius:4px; height:14px; display:inline-block; width:80%;
  }
  @keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }

  /* ── Empty State ────────────────────────────────────── */
  .empty-state { text-align:center; padding:60px 20px; color:#8a9a8b; }
  .empty-state i { font-size:3rem; margin-bottom:14px; display:block; opacity:.45; }
  .empty-state p { font-size:.95rem; }

  /* ── Modal ──────────────────────────────────────────── */
  .modal {
    display:none; position:fixed; inset:0; z-index:1000;
    background:rgba(0,0,0,.45); backdrop-filter:blur(3px);
    animation:fadeOverlay .2s ease;
  }
  @keyframes fadeOverlay { from{opacity:0} to{opacity:1} }
  .modal.active { display:flex; align-items:flex-start; justify-content:center; padding:20px 16px; overflow-y:auto; }
  .modal-box {
    background:#fff; border-radius:var(--radius-lg);
    width:100%; max-width:820px; box-shadow:var(--shadow-lg);
    animation:slideDown .25s ease; margin:auto;
  }
  .modal-box-sm { max-width:460px; }
  @keyframes slideDown { from{transform:translateY(-24px);opacity:0} to{transform:translateY(0);opacity:1} }
  .modal-head {
    background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%);
    padding:20px 24px; border-radius:var(--radius-lg) var(--radius-lg) 0 0;
    display:flex; align-items:center; justify-content:space-between;
  }
  .modal-head h2 { color:#fff; font-size:1.1rem; font-weight:700; display:flex; align-items:center; gap:10px; }
  .modal-close {
    background:rgba(255,255,255,.15); border:none; color:#fff;
    width:32px; height:32px; border-radius:50%; font-size:1.1rem;
    cursor:pointer; display:flex; align-items:center; justify-content:center;
    transition:background var(--transition);
  }
  .modal-close:hover { background:rgba(255,255,255,.3); }
  .modal-body { padding:24px 28px; }
  .modal-footer {
    padding:16px 28px; border-top:1px solid #eef2ee;
    display:flex; gap:10px; justify-content:flex-end;
  }

  /* ── Form ───────────────────────────────────────────── */
  .form-section { margin-bottom:22px; }
  .form-section-title {
    font-size:.8rem; font-weight:700; color:var(--g700);
    text-transform:uppercase; letter-spacing:.6px;
    margin-bottom:14px; padding-bottom:7px; border-bottom:2px solid var(--g100);
    display:flex; align-items:center; gap:8px;
  }
  .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
  .form-grid .full { grid-column:1/-1; }
  .form-group { display:flex; flex-direction:column; gap:5px; }
  .form-group label { font-size:.8rem; font-weight:600; color:#3a4a3b; }
  .form-group label .req { color:var(--red); }
  .form-control, .form-select {
    padding:9px 13px; border:1.5px solid #d0dbd1; border-radius:var(--radius);
    font-size:.875rem; font-family:inherit; width:100%;
    transition:border-color var(--transition), box-shadow var(--transition);
    background:#fff;
  }
  .form-control:focus, .form-select:focus {
    outline:none; border-color:var(--g600);
    box-shadow:0 0 0 3px rgba(67,160,71,.1);
  }
  .form-control[readonly] { background:#f5f8f5; color:#555; cursor:default; }

  /* ── Assignment Row ─────────────────────────────────── */
  .assignment-row {
    border:1.5px solid #e0e8e0; border-radius:var(--radius-lg);
    padding:18px; margin-bottom:14px;
    background:linear-gradient(135deg,#f8fff8 0%,#fff 100%);
    position:relative;
  }
  .assignment-row-header {
    display:flex; justify-content:space-between; align-items:center;
    margin-bottom:14px;
  }
  .assignment-row-title { font-weight:700; font-size:.85rem; color:var(--g700); }
  .stream-grid { display:flex; flex-wrap:wrap; gap:8px; margin-top:6px; }
  .stream-option { display:flex; align-items:center; gap:6px; }
  .stream-option input[type="radio"] { accent-color:var(--g700); width:15px; height:15px; cursor:pointer; }
  .stream-option label { font-size:.85rem; cursor:pointer; }

  .subject-grid {
    display:grid; grid-template-columns:repeat(auto-fill, minmax(190px, 1fr));
    gap:8px; margin-top:8px;
  }
  .subject-option { display:flex; align-items:center; gap:7px; }
  .subject-option input[type="checkbox"] { accent-color:var(--g700); width:15px; height:15px; cursor:pointer; flex-shrink:0; }
  .subject-option label { font-size:.83rem; cursor:pointer; line-height:1.3; }

  .class-teacher-panel {
    background:linear-gradient(135deg,#e3f2fd 0%,#bbdefb 100%);
    border:1.5px solid #90caf9; border-radius:var(--radius);
    padding:12px 14px; margin-top:14px;
    display:flex; align-items:center; gap:10px;
  }
  .class-teacher-panel input[type="checkbox"] { accent-color:var(--blue); width:17px; height:17px; cursor:pointer; flex-shrink:0; }
  .class-teacher-panel label { font-size:.85rem; font-weight:600; color:#1565c0; cursor:pointer; }

  /* ── Confirm Dialog ─────────────────────────────────── */
  .dialog { display:none; position:fixed; inset:0; z-index:2000; background:rgba(0,0,0,.5); backdrop-filter:blur(4px); align-items:center; justify-content:center; }
  .dialog.active { display:flex; }
  .dialog-box { background:#fff; border-radius:var(--radius-lg); width:100%; max-width:420px; box-shadow:var(--shadow-lg); animation:slideDown .22s ease; overflow:hidden; }
  .dialog-head { padding:18px 22px; display:flex; align-items:center; gap:12px; color:#fff; }
  .dialog-head.danger { background:linear-gradient(135deg,#c62828,#ef5350); }
  .dialog-head i { font-size:1.2rem; }
  .dialog-head h3 { font-size:1rem; font-weight:700; }
  .dialog-body { padding:22px; text-align:center; }
  .dialog-body p { font-size:.9rem; color:#555; line-height:1.55; margin-bottom:20px; }
  .dialog-actions { display:flex; gap:10px; justify-content:center; }

  /* ── Notifications ──────────────────────────────────── */
  #notif-stack { position:fixed; top:20px; right:20px; z-index:3000; display:flex; flex-direction:column; gap:10px; max-width:360px; }
  .notif {
    background:#fff; border-radius:var(--radius); padding:14px 16px;
    box-shadow:var(--shadow-lg); display:flex; align-items:flex-start;
    gap:12px; border-left:4px solid var(--g600); animation:notifIn .3s ease;
  }
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
  .notif-close { background:none; border:none; cursor:pointer; color:#aaa; font-size:1rem; padding:0; line-height:1; flex-shrink:0; }

  /* ── Responsive ─────────────────────────────────────── */
  @media (max-width:700px) {
    .page-header { flex-direction:column; }
    .form-grid { grid-template-columns:1fr; }
    .toolbar { flex-direction:column; align-items:stretch; }
    .toolbar-right { flex-wrap:wrap; }
    .table-wrap table { min-width:700px; }
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
      <h1><i class="fas fa-chalkboard-teacher" style="margin-right:10px;opacity:.85"></i>Teaching Staff</h1>
      <p>Assign classes, subjects and class-teacher roles to teaching staff</p>
    </div>
    <div class="header-actions">
      <button class="btn btn-pdf"   id="exportPdfBtn"><i class="fas fa-file-pdf"></i> PDF</button>
      <button class="btn btn-excel" id="exportExcelBtn"><i class="fas fa-file-excel"></i> Excel</button>
      <button class="btn btn-primary" id="addTeacherBtn"><i class="fas fa-plus"></i> Add Assignment</button>
    </div>
  </div>

  <!-- Main Card -->
  <div class="card">

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search teacher, subject, class…" autocomplete="off">
        </div>
        <span class="result-count" id="resultCount"></span>
      </div>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:40px">#</th>
            <th>Teacher Name</th>
            <th>Initials</th>
            <th>Contact</th>
            <th>Subjects</th>
            <th>Class / Stream Assignments</th>
            <th style="width:100px">Actions</th>
          </tr>
        </thead>
        <tbody id="tBody"></tbody>
      </table>
    </div>
  </div>
</div><!-- /page -->

<!-- ══ TEACHER MODAL ══════════════════════════════════════ -->
<div id="teacherModal" class="modal" onclick="modalOutsideClick(event,'teacherModal')">
  <div class="modal-box">
    <div class="modal-head">
      <h2 id="teacherModalTitle"><i class="fas fa-chalkboard-teacher"></i> Add Teacher Assignment</h2>
      <button class="modal-close" onclick="closeModal('teacherModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-section">
        <div class="form-section-title"><i class="fas fa-user"></i> Select Teacher</div>
        <div class="form-grid">
          <div class="form-group">
            <label for="teacherSelect">Teacher <span class="req">*</span></label>
            <select id="teacherSelect" class="form-select">
              <option value="">— Select teacher —</option>
            </select>
          </div>
          <div class="form-group">
            <label>Contact</label>
            <input type="text" id="teacherContact" class="form-control" readonly>
          </div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title"><i class="fas fa-book-open"></i> Teaching Assignments <span class="req">*</span></div>
        <div id="assignmentContainer"></div>
        <button class="btn btn-outline btn-sm" id="addAssignmentBtn" style="margin-top:6px">
          <i class="fas fa-plus"></i> Add Class Assignment
        </button>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('teacherModal')">Cancel</button>
      <button class="btn btn-primary" id="saveTeacherBtn">
        <i class="fas fa-save" id="saveBtnIcon"></i>
        <span id="saveBtnLabel">Save Assignments</span>
      </button>
    </div>
  </div>
</div>

<!-- ══ DELETE DIALOG ══════════════════════════════════════ -->
<div id="confirmDlg" class="dialog">
  <div class="dialog-box">
    <div class="dialog-head danger">
      <i class="fas fa-trash"></i>
      <h3>Delete Assignments</h3>
    </div>
    <div class="dialog-body">
      <p id="dlgMsg">Are you sure you want to delete all assignments for this teacher?</p>
      <div class="dialog-actions">
        <button class="btn btn-outline" onclick="closeDlg()">Cancel</button>
        <button class="btn btn-danger" id="dlgConfirmBtn">Delete</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ ASSIGNMENT ROW TEMPLATE ════════════════════════════ -->
<template id="assignmentTpl">
  <div class="assignment-row">
    <div class="assignment-row-header">
      <span class="assignment-row-title">Class Assignment</span>
      <button type="button" class="btn btn-sm btn-danger remove-assignment-btn" style="padding:5px 10px;">
        <i class="fas fa-times"></i> Remove
      </button>
    </div>
    <div class="form-grid" style="margin-bottom:12px">
      <div class="form-group">
        <label>Class <span class="req">*</span></label>
        <select class="form-select class-select" required>
          <option value="">— Select class —</option>
          <option value="Senior one">Senior One</option>
          <option value="Senior Two">Senior Two</option>
          <option value="Senior Three">Senior Three</option>
          <option value="Senior Four">Senior Four</option>
          <option value="Senior Five">Senior Five</option>
          <option value="Senior Six">Senior Six</option>
        </select>
      </div>
      <div class="form-group">
        <label>Stream <span class="req">*</span></label>
        <div class="stream-grid">
          <label class="stream-option"><input type="radio" class="stream-radio" value="East"> East</label>
          <label class="stream-option"><input type="radio" class="stream-radio" value="West"> West</label>
          <label class="stream-option"><input type="radio" class="stream-radio" value="North"> North</label>
          <label class="stream-option"><input type="radio" class="stream-radio" value="South"> South</label>
          <label class="stream-option"><input type="radio" class="stream-radio" value="Arts"> Arts</label>
          <label class="stream-option"><input type="radio" class="stream-radio" value="Sciences"> Sciences</label>
          <label class="stream-option"><input type="radio" class="stream-radio" value="All Streams"> All</label>
        </div>
      </div>
    </div>
    <div class="form-group">
      <label>Subjects <span class="req">*</span></label>
      <div class="subject-grid"></div>
    </div>
    <div class="class-teacher-panel">
      <input type="checkbox" class="class-teacher-check">
      <label>Assign as <strong>Class Teacher</strong> for this class / stream</label>
    </div>
  </div>
</template>

<script>
const CSRF = '<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>';

// ── Helpers ──────────────────────────────────────────────
function esc(v) {
  return String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function notify(title, msg, type = 'success', dur = 4500) {
  const icons = {success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
  const n = document.createElement('div');
  n.className = `notif ${type}`;
  n.innerHTML = `
    <i class="fas ${icons[type] || icons.info} notif-icon"></i>
    <div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div>
    <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
  document.getElementById('notif-stack').prepend(n);
  setTimeout(() => {
    n.style.cssText += 'opacity:0;transform:translateX(30px);transition:.3s';
    setTimeout(() => n.remove(), 300);
  }, dur);
}
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function modalOutsideClick(e, id) { if (e.target.id === id) closeModal(id); }

// ── State ────────────────────────────────────────────────
let allTeachers   = [];
let allSubjects   = [];
let classTeachers = [];
let staffList     = [];
let rowCounter    = 0;
let pendingDelete = null;

// ── Init ─────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  loadAll();

  document.getElementById('searchInput').addEventListener('input', function() {
    renderTable(this.value.trim().toLowerCase());
  });

  document.getElementById('addTeacherBtn').addEventListener('click', () => openAddModal());
  document.getElementById('addAssignmentBtn').addEventListener('click', () => addRow());

  document.getElementById('assignmentContainer').addEventListener('click', e => {
    if (e.target.closest('.remove-assignment-btn')) {
      const rows = document.querySelectorAll('.assignment-row');
      if (rows.length > 1) {
        e.target.closest('.assignment-row').remove();
      } else {
        notify('Warning', 'At least one assignment is required.', 'warning');
      }
    }
  });

  document.getElementById('teacherSelect').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    document.getElementById('teacherContact').value = opt.dataset.phone || '';
  });

  document.getElementById('saveTeacherBtn').addEventListener('click', handleSave);
  document.getElementById('dlgConfirmBtn').addEventListener('click', runDelete);

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      closeModal('teacherModal');
      closeDlg();
    }
  });
});

// ── Load All Data ─────────────────────────────────────────
function loadAll() {
  showSkeleton();
  Promise.all([
    fetch('api/get_teachers.php').then(r => r.json()),
    fetch('api/get_teaching_staff.php').then(r => r.json()),
    fetch('api/get_subjects.php').then(r => r.json()),
    fetch('api/get_class_teachers.php').then(r => r.json()),
  ]).then(([teachers, staff, subjects, ct]) => {
    if (teachers.success)  allTeachers   = teachers.teachers;
    if (staff.success)     staffList     = staff.staff;
    if (subjects.success)  allSubjects   = subjects.subjects;
    if (ct.success)        classTeachers = ct.classTeachers;

    populateStaffDropdown();
    renderTable('');
  }).catch(() => {
    notify('Error', 'Failed to load page data. Please refresh.', 'error');
    document.getElementById('tBody').innerHTML =
      `<tr><td colspan="7"><div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Failed to load data. Please refresh the page.</p></div></td></tr>`;
  });
}

// ── Skeleton ──────────────────────────────────────────────
function showSkeleton() {
  document.getElementById('tBody').innerHTML =
    Array.from({length:5}, () =>
      `<tr>${Array.from({length:7}, () =>
        `<td><span class="skeleton-cell" style="width:${50+Math.random()*40}%"></span></td>`).join('')}</tr>`
    ).join('');
}

// ── Populate Staff Dropdown ───────────────────────────────
function populateStaffDropdown() {
  const sel = document.getElementById('teacherSelect');
  sel.innerHTML = '<option value="">— Select teacher —</option>';
  staffList.forEach(s => {
    const opt = document.createElement('option');
    opt.value = s.id;
    opt.textContent = s.full_name;
    opt.dataset.phone = s.phone_number || '';
    sel.appendChild(opt);
  });
}

// ── Render Table ──────────────────────────────────────────
function renderTable(q = '') {
  const tbody = document.getElementById('tBody');
  const filtered = q
    ? allTeachers.filter(t => {
        const hay = [t.name, t.contact, ...t.assignments.map(a => a.subject_name + ' ' + a.class + ' ' + a.stream)]
          .join(' ').toLowerCase();
        return hay.includes(q);
      })
    : allTeachers;

  document.getElementById('resultCount').textContent =
    filtered.length < allTeachers.length
      ? `${filtered.length} of ${allTeachers.length} teachers`
      : `${allTeachers.length} teacher${allTeachers.length !== 1 ? 's' : ''}`;

  if (!filtered.length) {
    tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state">
      <i class="fas fa-chalkboard-teacher"></i>
      <p>${allTeachers.length === 0 ? 'No teaching assignments added yet.' : 'No teachers match your search.'}</p>
    </div></td></tr>`;
    return;
  }

  tbody.innerHTML = filtered.map((t, i) => {
    const initials = t.name.split(' ').map(w => w[0] || '').join('').toUpperCase().slice(0, 3);

    // Unique subjects
    const uniqueSubjects = [...new Map(t.assignments.map(a => [a.subject_id, a.subject_name])).values()];
    const subjectBadges = uniqueSubjects.map(n => `<span class="badge badge-subject">${esc(n)}</span>`).join(' ');

    // Group assignments by class/stream
    const comboMap = {};
    t.assignments.forEach(a => {
      const key = `${a.class}||${a.stream}`;
      if (!comboMap[key]) comboMap[key] = { class: a.class, stream: a.stream, subjects: [] };
      comboMap[key].subjects.push(a.subject_name);
    });

    const ctIds = classTeachers.filter(c => c.staff_id == t.id).map(c => `${c.class_name}||${c.stream}`);

    const combos = Object.values(comboMap).map(c => {
      const isCT = ctIds.includes(`${c.class}||${c.stream}`);
      return `<div class="class-combo">
        <div class="combo-header">
          ${esc(c.class)} &ndash; ${esc(c.stream)}
          ${isCT ? '<span class="badge badge-classteacher">Class Teacher</span>' : ''}
        </div>
        <div style="color:#546e7a;font-size:.8rem">${c.subjects.map(esc).join(', ')}</div>
      </div>`;
    }).join('');

    return `<tr>
      <td style="color:#9aaa9b;font-size:.78rem">${i + 1}</td>
      <td style="font-weight:700;color:var(--g800)">${esc(t.name)}</td>
      <td style="font-size:.82rem;color:#6b7c6d;font-weight:600">${esc(initials)}</td>
      <td style="font-size:.82rem">${esc(t.contact || '—')}</td>
      <td>${subjectBadges || '<span style="color:#bbb">—</span>'}</td>
      <td>${combos}</td>
      <td>
        <div class="action-cell">
          <button class="btn-icon bi-edit" data-id="${esc(t.id)}" title="Edit assignments"><i class="fas fa-pen"></i></button>
          <button class="btn-icon bi-delete" data-id="${esc(t.id)}" data-name="${esc(t.name)}" title="Delete all assignments"><i class="fas fa-trash"></i></button>
        </div>
      </td>
    </tr>`;
  }).join('');

  // Bind action buttons
  tbody.querySelectorAll('.bi-edit').forEach(btn =>
    btn.addEventListener('click', () => openEditModal(allTeachers.find(t => t.id == btn.dataset.id)))
  );
  tbody.querySelectorAll('.bi-delete').forEach(btn =>
    btn.addEventListener('click', () => promptDelete(btn.dataset.id, btn.dataset.name))
  );
}

// ── Open Add Modal ────────────────────────────────────────
function openAddModal() {
  document.getElementById('teacherModalTitle').innerHTML = '<i class="fas fa-plus"></i> Add Teacher Assignment';
  document.getElementById('saveBtnLabel').textContent = 'Save Assignments';
  document.getElementById('teacherSelect').value = '';
  document.getElementById('teacherSelect').disabled = false;
  document.getElementById('teacherContact').value = '';
  document.getElementById('assignmentContainer').innerHTML = '';
  rowCounter = 0;
  addRow();
  openModal('teacherModal');
}

// ── Open Edit Modal ───────────────────────────────────────
function openEditModal(teacher) {
  if (!teacher) return;
  document.getElementById('teacherModalTitle').innerHTML = '<i class="fas fa-pen"></i> Edit Teacher Assignment';
  document.getElementById('saveBtnLabel').textContent = 'Save Changes';
  document.getElementById('teacherSelect').value = teacher.id;
  document.getElementById('teacherSelect').disabled = true;
  document.getElementById('teacherContact').value = teacher.contact || '';
  document.getElementById('assignmentContainer').innerHTML = '';
  rowCounter = 0;

  // Group by class/stream
  const comboMap = {};
  teacher.assignments.forEach(a => {
    const key = `${a.class}||${a.stream}`;
    if (!comboMap[key]) comboMap[key] = { class: a.class, stream: a.stream, subject_ids: [] };
    comboMap[key].subject_ids.push(String(a.subject_id));
  });

  const ctSet = new Set(classTeachers.filter(c => c.staff_id == teacher.id).map(c => `${c.class_name}||${c.stream}`));

  Object.values(comboMap).forEach(combo => {
    addRow({
      class:       combo.class,
      stream:      combo.stream,
      subject_ids: combo.subject_ids,
      isClassTeacher: ctSet.has(`${combo.class}||${combo.stream}`),
    });
  });

  openModal('teacherModal');
}

// ── Add Assignment Row ────────────────────────────────────
function addRow(data = null) {
  rowCounter++;
  const n = rowCounter;
  const tpl = document.getElementById('assignmentTpl').content.cloneNode(true);

  // Unique radio names
  tpl.querySelectorAll('.stream-radio').forEach(r => r.name = `stream_${n}`);

  // Populate subjects
  const subjectGrid = tpl.querySelector('.subject-grid');
  subjectGrid.innerHTML = allSubjects.map(s => {
    const checked = data && data.subject_ids.includes(String(s.subject_id)) ? 'checked' : '';
    return `<label class="subject-option">
      <input type="checkbox" class="subject-check" value="${esc(s.subject_id)}" ${checked}>
      ${esc(s.name)}
    </label>`;
  }).join('');

  // Pre-fill if editing
  if (data) {
    tpl.querySelector('.class-select').value = data.class;
    const radio = tpl.querySelector(`.stream-radio[value="${CSS.escape(data.stream)}"]`);
    if (radio) radio.checked = true;
    if (data.isClassTeacher) tpl.querySelector('.class-teacher-check').checked = true;
  }

  document.getElementById('assignmentContainer').appendChild(tpl);
}

// ── Handle Save ───────────────────────────────────────────
function handleSave() {
  const staffId = document.getElementById('teacherSelect').value;
  if (!staffId) { notify('Validation', 'Please select a teacher.', 'warning'); return; }

  const assignments = [];
  const classTeacherAssignments = [];
  let valid = true;

  document.querySelectorAll('.assignment-row').forEach(row => {
    const cls    = row.querySelector('.class-select').value;
    const stream = row.querySelector('.stream-radio:checked')?.value;
    const subs   = [...row.querySelectorAll('.subject-check:checked')].map(c => c.value);
    const isCT   = row.querySelector('.class-teacher-check')?.checked ?? false;

    if (!cls || !stream || !subs.length) { valid = false; return; }

    assignments.push({ class: cls, stream, subjects: subs });
    if (isCT) classTeacherAssignments.push({ class: cls, stream });
  });

  if (!valid) { notify('Validation', 'Please fill all required fields in every assignment row.', 'warning'); return; }

  const btn  = document.getElementById('saveTeacherBtn');
  const icon = document.getElementById('saveBtnIcon');
  btn.disabled = true;
  icon.className = 'fas fa-spinner fa-spin';

  fetch('api/save_teacher.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ staff_id: staffId, assignments, csrf_token: CSRF }),
  })
  .then(r => r.json())
  .then(d => {
    if (!d.success) throw new Error(d.message);

    // Remove stale class-teacher records for this staff that are no longer ticked
    const currentCTs = classTeachers.filter(c => c.staff_id == staffId);
    const removeOld  = currentCTs
      .filter(c => !classTeacherAssignments.some(n => n.class === c.class_name && n.stream === c.stream))
      .map(c => fetch('api/delete_class_teacher.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ class_name: c.class_name, stream: c.stream, csrf_token: CSRF }),
      }));

    const saveNew = classTeacherAssignments.map(ct =>
      fetch('api/save_class_teacher.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ staff_id: staffId, class_name: ct.class, stream: ct.stream, csrf_token: CSRF }),
      })
    );

    return Promise.all([...removeOld, ...saveNew]);
  })
  .then(() => {
    notify('Success', 'Teaching assignments saved successfully.', 'success');
    closeModal('teacherModal');
    loadAll();
  })
  .catch(err => notify('Error', err.message || 'Failed to save assignments.', 'error'))
  .finally(() => {
    btn.disabled = false;
    icon.className = 'fas fa-save';
  });
}

// ── Delete ────────────────────────────────────────────────
function promptDelete(id, name) {
  pendingDelete = id;
  document.getElementById('dlgMsg').innerHTML =
    `Delete <strong>all assignments</strong> for <strong>${esc(name)}</strong>? This cannot be undone.`;
  document.getElementById('confirmDlg').classList.add('active');
}
function closeDlg() {
  pendingDelete = null;
  document.getElementById('confirmDlg').classList.remove('active');
}
function runDelete() {
  if (!pendingDelete) return;
  const btn = document.getElementById('dlgConfirmBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting…';

  fetch('api/delete_teacher.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ staff_id: pendingDelete, csrf_token: CSRF }),
  })
  .then(r => r.json())
  .then(d => {
    if (!d.success) throw new Error(d.message);
    notify('Deleted', 'All assignments removed successfully.', 'success');
    closeDlg();
    loadAll();
  })
  .catch(err => notify('Error', err.message || 'Delete failed.', 'error'))
  .finally(() => {
    btn.disabled = false;
    btn.innerHTML = 'Delete';
  });
}

// ── Export PDF ────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('exportPdfBtn').addEventListener('click', () => {
    if (!allTeachers.length) { notify('Empty', 'No data to export.', 'warning'); return; }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    doc.setFontSize(16); doc.setTextColor(46, 125, 50);
    doc.text('Teaching Staff Assignments', 14, 18);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text('Generated: ' + new Date().toLocaleDateString('en-UG'), 14, 25);
    doc.autoTable({
      head: [['#', 'Name', 'Initials', 'Contact', 'Subjects']],
      body: allTeachers.map((t, i) => {
        const initials = t.name.split(' ').map(w => w[0] || '').join('').toUpperCase();
        const subjects = [...new Set(t.assignments.map(a => a.subject_name))].join(', ');
        return [i + 1, t.name, initials, t.contact || '—', subjects];
      }),
      startY: 30, theme: 'grid',
      headStyles: { fillColor: [67, 160, 71], fontSize: 8 },
      bodyStyles: { fontSize: 7.5 },
    });
    doc.save('teaching-staff-' + new Date().toISOString().split('T')[0] + '.pdf');
    notify('Exported', 'PDF downloaded.', 'success');
  });

  document.getElementById('exportExcelBtn').addEventListener('click', () => {
    if (!allTeachers.length) { notify('Empty', 'No data to export.', 'warning'); return; }
    const data = [['Teacher', 'Initials', 'Contact', 'Subjects', 'Class/Stream Assignments']];
    allTeachers.forEach(t => {
      const initials = t.name.split(' ').map(w => w[0] || '').join('').toUpperCase();
      const subjects = [...new Set(t.assignments.map(a => a.subject_name))].join(', ');
      const combos   = [...new Set(t.assignments.map(a => `${a.class} (${a.stream})`))].join('; ');
      data.push([t.name, initials, t.contact || '', subjects, combos]);
    });
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [{wch:22},{wch:10},{wch:16},{wch:30},{wch:40}];
    XLSX.utils.book_append_sheet(wb, ws, 'Teaching Staff');
    XLSX.writeFile(wb, 'teaching-staff-' + new Date().toISOString().split('T')[0] + '.xlsx');
    notify('Exported', 'Excel file downloaded.', 'success');
  });
});
</script>
</body>
</html>
