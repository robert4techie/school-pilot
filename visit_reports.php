<?php
/**
 * visit_reports.php
 *
 * Complete sick bay visit history page.
 * - Header stat pills (total / fever / referred / follow-up pending)
 * - Search + date range + class + action-taken filters
 * - Paginated table with colour-coded outcome badges
 * - Rich detail modal showing vitals, notes, medications dispensed
 * - PDF and Excel export of the current filter set
 */

require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction('Visit Reports');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Visit Reports — School Pilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js" defer></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sen:wght@400;600;700;800&display=swap');
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--red-bg:#ffebee;
  --orange:#e65100;--orange-bg:#fff3e0;
  --blue:#1565c0;--blue-bg:#e3f2fd;
  --purple:#6a1b9a;--purple-bg:#f3e5f5;
  --gray:#546e7a;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);
  --shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --transition:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}
a{color:inherit;text-decoration:none}

/* ── Layout ──────────────────────────────────────────────────────────────────*/
.page{padding:24px 20px 52px;margin-top:40px}

/* ── Page Header ─────────────────────────────────────────────────────────────*/
.page-header{
  background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);
  border-radius:var(--radius-lg);padding:28px 32px;
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:16px;margin-bottom:24px;box-shadow:var(--shadow-lg)
}
.page-header h1{color:#fff;font-size:1.55rem;font-weight:700}
.page-header p{color:rgba(255,255,255,.75);font-size:.88rem;margin-top:3px}
.stat-pills{display:flex;gap:10px;flex-wrap:wrap}
.stat-pill{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:40px;padding:7px 16px;text-align:center;transition:background var(--transition)}
.stat-pill:hover{background:rgba(255,255,255,.22)}
.stat-pill .n{font-size:1.2rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.7rem;color:rgba(255,255,255,.72);text-transform:uppercase;letter-spacing:.5px}

/* ── Card ────────────────────────────────────────────────────────────────────*/
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden}

/* ── Toolbar ─────────────────────────────────────────────────────────────────*/
.toolbar{padding:14px 20px;border-bottom:1px solid #e8ede9;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.toolbar-left{display:flex;flex-wrap:wrap;gap:10px;align-items:center;flex:1}
.toolbar-right{display:flex;gap:8px;flex-shrink:0}
.search-wrap{position:relative;min-width:220px}
.search-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.8rem}
.search-wrap input{width:100%;padding:9px 12px 9px 32px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;transition:border-color var(--transition)}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.fsel,.date-in{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;background:#fff;transition:border-color var(--transition)}
.fsel:focus,.date-in:focus{outline:none;border-color:var(--g600)}
.date-sep{font-size:.75rem;color:var(--gray)}
.result-count{font-size:.78rem;color:#6b7c6d;white-space:nowrap}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all var(--transition);white-space:nowrap}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-pdf{background:var(--red);color:#fff}.btn-pdf:hover{background:#b71c1c}
.btn-excel{background:var(--g800);color:#fff}.btn-excel:hover{background:var(--g900)}
.btn-icon{width:30px;height:30px;border:none;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;transition:all var(--transition)}
.btn-icon:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.18)}
.bi-view{background:var(--blue-bg);color:var(--blue)}.bi-view:hover{background:var(--blue);color:#fff}

/* ── Table ───────────────────────────────────────────────────────────────────*/
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:12px 14px;text-align:left;font-size:.78rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--transition);cursor:pointer}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:12px 14px;font-size:.875rem;vertical-align:middle}
.student-name{font-weight:600;color:var(--g800)}
.complaint-cell{max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* ── Badges ──────────────────────────────────────────────────────────────────*/
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
.b-returned{background:var(--g100);color:var(--g800)}
.b-home{background:var(--orange-bg);color:var(--orange)}
.b-hospital{background:var(--red-bg);color:var(--red)}
.b-admitted{background:var(--blue-bg);color:var(--blue)}
.b-yes{background:var(--g100);color:var(--g800)}
.b-no{background:#f5f5f5;color:var(--gray)}
.b-pending{background:var(--orange-bg);color:var(--orange)}

/* ── Skeleton ────────────────────────────────────────────────────────────────*/
.skel{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;display:inline-block}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
.empty-state{text-align:center;padding:52px 20px;color:#8a9a8b}
.empty-state i{font-size:2.8rem;opacity:.35;display:block;margin-bottom:12px}

/* ── Pagination ──────────────────────────────────────────────────────────────*/
.pagination{padding:14px 20px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e8ede9;flex-wrap:wrap;gap:10px}
.page-info{font-size:.8rem;color:#6b7c6d}
.page-btns{display:flex;gap:4px}
.page-btn{width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:6px;background:#fff;cursor:pointer;font-size:.82rem;font-weight:600;color:#444;display:flex;align-items:center;justify-content:center;transition:all var(--transition);font-family:inherit}
.page-btn:hover:not(:disabled){border-color:var(--g600);background:var(--g100);color:var(--g800)}
.page-btn.active{background:var(--g700);border-color:var(--g700);color:#fff}
.page-btn:disabled{opacity:.38;cursor:default}

/* ── Modal ───────────────────────────────────────────────────────────────────*/
.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.46);backdrop-filter:blur(3px)}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:820px;box-shadow:var(--shadow-lg);animation:slideD .25s ease;margin:auto}
@keyframes slideD{from{transform:translateY(-20px);opacity:0}to{transform:none;opacity:1}}
.modal-head{background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%);padding:18px 24px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head h2{color:#fff;font-size:1rem;font-weight:700;display:flex;align-items:center;gap:9px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:30px;height:30px;border-radius:50%;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background var(--transition)}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:24px}
.modal-foot{padding:14px 24px;border-top:1px solid #e8ede9;display:flex;justify-content:flex-end;gap:10px}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}

/* ── Detail sections ─────────────────────────────────────────────────────────*/
.detail-head{font-size:.75rem;font-weight:700;color:var(--g700);text-transform:uppercase;letter-spacing:.5px;padding-bottom:8px;border-bottom:1px solid #e0ebe1;margin-bottom:14px;display:flex;align-items:center;gap:7px}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px}
.detail-grid.cols3{grid-template-columns:1fr 1fr 1fr}
.di{background:var(--g50);border:1px solid #e0ebe1;border-radius:var(--radius);padding:12px 14px}
.di.full{grid-column:1/-1}
.di-lbl{font-size:.7rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px}
.di-val{font-size:.875rem;font-weight:600;color:#1a1a1a;word-break:break-word}
.di-val.muted{color:var(--gray);font-weight:400}

/* ── Med table in modal ──────────────────────────────────────────────────────*/
.med-table{width:100%;border-collapse:collapse;margin-top:4px}
.med-table th{padding:8px 12px;text-align:left;font-size:.75rem;font-weight:700;color:var(--g800);background:var(--g100);text-transform:uppercase;letter-spacing:.4px}
.med-table td{padding:8px 12px;font-size:.85rem;border-bottom:1px solid #f0f4f1}
.med-table tr:last-child td{border-bottom:none}
.no-meds{font-size:.82rem;color:var(--gray);font-style:italic;padding:8px 0}

/* ── Notifications ───────────────────────────────────────────────────────────*/
#notif-stack{position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.notif{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:var(--radius);box-shadow:var(--shadow-lg);min-width:240px;max-width:340px;pointer-events:all;background:#fff;border-left:4px solid var(--g600);animation:slideIn .25s ease}
@keyframes slideIn{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}
.notif.error{border-left-color:var(--red)}.notif.warning{border-left-color:var(--orange)}
.notif-icon{margin-top:1px}
.notif.success .notif-icon{color:var(--g600)}.notif.error .notif-icon{color:var(--red)}.notif.warning .notif-icon{color:var(--orange)}
.notif-body{flex:1;font-size:.82rem}.notif-title{font-weight:700}.notif-msg{color:var(--gray)}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:.8rem;padding:0;margin-left:4px}

@media(max-width:700px){
  .page-header{padding:18px 16px}
  .detail-grid{grid-template-columns:1fr}
  .detail-grid.cols3{grid-template-columns:1fr 1fr}
  .toolbar{flex-direction:column;align-items:stretch}
}

/* ── Delete button ───────────────────────────────────────────────────────────*/
.bi-del{background:var(--red-bg);color:var(--red)}
.bi-del:hover{background:var(--red);color:#fff}

/* ── Confirm Dialog ──────────────────────────────────────────────────────────*/
.confirm-overlay{display:none;position:fixed;inset:0;z-index:2000;
  background:rgba(0,0,0,.52);backdrop-filter:blur(4px);
  align-items:center;justify-content:center}
.confirm-overlay.active{display:flex}
.confirm-box{background:#fff;border-radius:14px;width:100%;max-width:420px;
  box-shadow:0 12px 40px rgba(0,0,0,.22);animation:slideD .22s ease;margin:16px}
.confirm-icon-wrap{text-align:center;padding:32px 32px 0}
.confirm-icon-wrap .ci{width:64px;height:64px;border-radius:50%;
  background:var(--red-bg);color:var(--red);font-size:1.6rem;
  display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px}
.confirm-title{font-size:1.05rem;font-weight:700;color:#1a1a1a;text-align:center;padding:0 32px}
.confirm-msg{font-size:.875rem;color:#5a6a5b;text-align:center;
  padding:8px 32px 24px;line-height:1.5}
.confirm-student{font-weight:700;color:var(--red)}
.confirm-warn{font-size:.78rem;color:var(--orange);background:var(--orange-bg);
  border-radius:6px;padding:8px 12px;margin:0 32px 20px;text-align:center}
.confirm-foot{display:flex;gap:10px;padding:16px 24px;
  border-top:1px solid #f0f0f0;justify-content:flex-end}
.btn-cancel{background:#f5f5f5;color:#555;border:1.5px solid #ddd}
.btn-cancel:hover{background:#ebebeb;transform:none}
.btn-danger{background:var(--red);color:#fff;border:none}
.btn-danger:hover{background:#b71c1c}
.btn-danger:disabled{opacity:.6;cursor:not-allowed;transform:none !important}
</style>
</head>
<body>
<?php require_once 'nav.php' ?>
<div id="notif-stack"></div>

<div class="page">

  <!-- ── Page Header ──────────────────────────────────────────────────────────-->
  <header class="page-header">
    <div>
      <h1><i class="fas fa-notes-medical" style="margin-right:10px;opacity:.85"></i>Sick Bay Visit Reports</h1>
      <p>Complete history of all student sick bay visits, treatments, and outcomes.</p>
    </div>
    <div class="stat-pills" id="headerPills">
      <div class="stat-pill"><span class="n" id="pillTotal">—</span><span class="l">Total</span></div>
      <div class="stat-pill"><span class="n" id="pillFever">—</span><span class="l">Fever</span></div>
      <div class="stat-pill"><span class="n" id="pillReferred">—</span><span class="l">Referred</span></div>
      <div class="stat-pill"><span class="n" id="pillFollowup">—</span><span class="l">Follow-up</span></div>
    </div>
  </header>

  <!-- ── Table Card ───────────────────────────────────────────────────────────-->
  <div class="card">

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search name, ID, or complaint…">
        </div>
        <input type="date" id="dateFrom" class="date-in" title="From date">
        <span class="date-sep">→</span>
        <input type="date" id="dateTo" class="date-in" title="To date">
        <select id="classFilter" class="fsel"><option value="">All Classes</option></select>
        <select id="actionFilter" class="fsel">
          <option value="">All Outcomes</option>
          <option value="ReturnedToClass">Returned to Class</option>
          <option value="SentHome">Sent Home</option>
          <option value="ReferredToHospital">Referred to Hospital</option>
          <option value="Admitted">Admitted</option>
        </select>
        <span class="result-count" id="resultCount"></span>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-pdf" id="btnPdf"><i class="fas fa-file-pdf"></i> PDF</button>
        <button class="btn btn-excel" id="btnExcel"><i class="fas fa-file-excel"></i> Excel</button>
        <a href="Add_visit.php" class="btn btn-primary"><i class="fas fa-plus"></i> New Visit</a>
      </div>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Date &amp; Time</th>
            <th>Student</th>
            <th>Class</th>
            <th>Chief Complaint</th>
            <th>Temp °C</th>
            <th>Outcome</th>
            <th>Attended By</th>
            <th>Parent Notified</th>
            <th>Follow-up</th>
            <th style="text-align:center">Actions</th>

          </tr>
        </thead>
        <tbody id="tbody">
          <tr><td colspan="11" style="text-align:center;padding:36px">
            <span class="skel" style="width:60%;height:14px;display:inline-block"> </span>
          </td></tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="pagination" id="paginationBar" style="display:none">
      <span class="page-info" id="pageInfo"></span>
      <div class="page-btns" id="pageBtns"></div>
    </div>

  </div>
</div><!-- /page -->

<!-- ══ Detail Modal ══════════════════════════════════════════════════════════ -->
<div class="modal" id="detailModal">
  <div class="modal-box">
    <div class="modal-head">
      <h2 id="modalTitle"><i class="fas fa-file-medical-alt"></i> Visit Details</h2>
      <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="modalBody"><!-- injected by JS --></div>
    <div class="modal-foot">
  <button class="btn btn-outline" onclick="closeModal()">Close</button>
  <button class="btn btn-danger" id="btnModalDelete" onclick="confirmDeleteFromModal()"><i class="fas fa-trash-alt"></i> Delete</button>
  <button class="btn btn-pdf" id="btnPrintVisit"><i class="fas fa-print"></i> Print</button>
</div>
  </div>
</div>

<!-- ══ Delete Confirmation Dialog ════════════════════════════════════════════ -->
<div class="confirm-overlay" id="confirmOverlay">
  <div class="confirm-box">
    <div class="confirm-icon-wrap">
      <div class="ci"><i class="fas fa-trash-alt"></i></div>
      <div class="confirm-title">Delete Visit Record?</div>
    </div>
    <p class="confirm-msg">
      You are about to permanently delete the sick bay visit for
      <span class="confirm-student" id="confirmStudentName">this student</span>.
    </p>
    <div class="confirm-warn">
      <i class="fas fa-exclamation-triangle"></i>
      This action cannot be undone. Medications linked to this visit will also be removed.
    </div>
    <div class="confirm-foot">
      <button class="btn btn-cancel" onclick="closeConfirm()">
        <i class="fas fa-times"></i> Cancel
      </button>
      <button class="btn btn-danger" id="confirmDeleteBtn" onclick="executeDelete()">
        <i class="fas fa-trash-alt"></i> Yes, Delete
      </button>
    </div>
  </div>
</div>

<script>
// ── State ─────────────────────────────────────────────────────────────────────
const PER_PAGE   = 15;
let currentPage  = 1;
let totalRecords = 0;
let lastFilters  = {};
let currentVisit = null; // for modal print

// ── Pill counters (computed client-side from full dataset each load) ──────────
let pillCounts = {total:0, fever:0, referred:0, followup:0};

// ── Fetch + render ────────────────────────────────────────────────────────────
async function fetchReports(page = 1) {
  currentPage = page;
  const params = {
    page,
    dateFrom:  document.getElementById('dateFrom').value    || '',
    dateTo:    document.getElementById('dateTo').value      || '',
    search:    document.getElementById('searchInput').value || '',
    class:     document.getElementById('classFilter').value || '',
    action:    document.getElementById('actionFilter').value|| '',
  };
  lastFilters = params;

  setLoading(true);

  try {
    const qs  = new URLSearchParams(params);
    const res = await fetch(`api/get_visit_reports.php?${qs}`);
    const data = await res.json();

    if (!data.success) throw new Error(data.message);

    totalRecords = data.totalRecords;

    // Populate class filter once
    if (data.classes && data.classes.length) populateClasses(data.classes);

    renderTable(data.data);
    renderPagination();
    updatePills(data.data, data.totalRecords);
    document.getElementById('resultCount').textContent =
      `${totalRecords} visit${totalRecords !== 1 ? 's' : ''}`;

  } catch(e) {
    document.getElementById('tbody').innerHTML =
      `<tr><td colspan="11"><div class="empty-state"><i class="fas fa-exclamation-circle" style="color:var(--red)"></i><p>${esc(e.message)}</p></div></td></tr>`;
    notify('Error', e.message, 'error');
  }
}

// ── Pill stats ────────────────────────────────────────────────────────────────
function updatePills(rows, total) {
  // Total is server-side count; other stats from current page rows (best-effort)
  document.getElementById('pillTotal').textContent   = total;
  const fever    = rows.filter(r => (r.chief_complaint||'').toLowerCase().includes('fever')).length;
  const referred = rows.filter(r => r.action_taken === 'ReferredToHospital').length;
  const followup = rows.filter(r => r.followup_required == 1).length;
  // Show page counts with '+' indicator to signal partial
  document.getElementById('pillFever').textContent    = total <= PER_PAGE ? fever    : fever    + (fever    ? '+' : '');
  document.getElementById('pillReferred').textContent = total <= PER_PAGE ? referred : referred + (referred ? '+' : '');
  document.getElementById('pillFollowup').textContent = total <= PER_PAGE ? followup : followup + (followup ? '+' : '');
}

// ── Populate class filter ─────────────────────────────────────────────────────
let classesLoaded = false;
function populateClasses(classes) {
  if (classesLoaded) return;
  const sel = document.getElementById('classFilter');
  const cur = sel.value;
  classes.forEach(c => {
    const opt = document.createElement('option');
    opt.value = c; opt.textContent = c;
    if (c === cur) opt.selected = true;
    sel.appendChild(opt);
  });
  classesLoaded = true;
}

// ── Render table ──────────────────────────────────────────────────────────────
const ACTION_BADGE = {
  ReturnedToClass:   ['b-returned','Returned'],
  SentHome:          ['b-home',    'Sent Home'],
  ReferredToHospital:['b-hospital','Referred'],
  Admitted:          ['b-admitted','Admitted'],
};

function renderTable(rows) {
  rows.forEach(r => { cachedRows[r.visit_id] = r; });
  const tbody = document.getElementById('tbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="11"><div class="empty-state">
      <i class="fas fa-clipboard-list"></i>
      <p>No visit records match your filters.</p>
    </div></td></tr>`;
    return;
  }

  const offset = (currentPage - 1) * PER_PAGE;
  tbody.innerHTML = rows.map((r, i) => {
    const [badgeCls, badgeLbl] = ACTION_BADGE[r.action_taken] || ['b-returned', r.action_taken||'—'];
    const pNotified = r.parent_notified == 1
      ? `<span class="badge b-yes">Yes</span>`
      : `<span class="badge b-no">No</span>`;
    const followup = r.followup_required == 1
      ? `<span class="badge b-pending">Pending</span>`
      : `<span class="badge b-no">No</span>`;
    const temp = r.temperature ? esc(r.temperature) + '°' : '—';

    return `<tr onclick="openDetail(${r.visit_id})" data-id="${r.visit_id}" title="Click to view full record">
      <td style="color:#9aaa9b;font-size:.75rem">${offset + i + 1}</td>
      <td style="font-size:.78rem;white-space:nowrap;color:#444">${fmtDatetime(r.visit_datetime)}</td>
      <td><span class="student-name">${esc(r.full_name)}</span><br>
          <span style="font-size:.72rem;color:#8a9a8b">${esc(r.student_id)}</span></td>
      <td style="font-size:.82rem">${esc(r.current_class||'—')}</td>
      <td class="complaint-cell" title="${esc(r.chief_complaint||'')}">
        ${r.chief_complaint ? esc(r.chief_complaint) : '<span style="color:#bbb">—</span>'}
      </td>
      <td style="font-size:.85rem">${temp}</td>
      <td><span class="badge ${badgeCls}">${badgeLbl}</span></td>
      <td style="font-size:.82rem">${esc(r.attended_by||'—')}</td>
      <td>${pNotified}</td>
      <td>${followup}</td>
      <td style="text-align:center;white-space:nowrap;display:flex;gap:5px;justify-content:center;align-items:center">
  <button class="btn-icon bi-view" onclick="event.stopPropagation();openDetail(${r.visit_id})" title="View details"><i class="fas fa-eye"></i></button>
  <button class="btn-icon bi-del"  onclick="event.stopPropagation();confirmDelete(${r.visit_id},'${esc(r.full_name)}')" title="Delete visit"><i class="fas fa-trash-alt"></i></button>
</td>
    </tr>`;
  }).join('');
}

// ── Pagination ────────────────────────────────────────────────────────────────
function renderPagination() {
  const bar     = document.getElementById('paginationBar');
  const totalPg = Math.ceil(totalRecords / PER_PAGE);
  if (totalPg <= 1) { bar.style.display = 'none'; return; }
  bar.style.display = 'flex';

  const from = (currentPage - 1) * PER_PAGE + 1;
  const to   = Math.min(currentPage * PER_PAGE, totalRecords);
  document.getElementById('pageInfo').textContent = `Showing ${from}–${to} of ${totalRecords}`;

  let html = '';
  html += `<button class="page-btn" ${currentPage===1?'disabled':''} onclick="fetchReports(1)"><i class="fas fa-angle-double-left"></i></button>`;
  html += `<button class="page-btn" ${currentPage===1?'disabled':''} onclick="fetchReports(${currentPage-1})"><i class="fas fa-chevron-left"></i></button>`;
  const start = Math.max(1, currentPage - 2), end = Math.min(totalPg, currentPage + 2);
  for (let p = start; p <= end; p++) {
    html += `<button class="page-btn${p===currentPage?' active':''}" onclick="fetchReports(${p})">${p}</button>`;
  }
  html += `<button class="page-btn" ${currentPage===totalPg?'disabled':''} onclick="fetchReports(${currentPage+1})"><i class="fas fa-chevron-right"></i></button>`;
  html += `<button class="page-btn" ${currentPage===totalPg?'disabled':''} onclick="fetchReports(${totalPg})"><i class="fas fa-angle-double-right"></i></button>`;
  document.getElementById('pageBtns').innerHTML = html;
}

// ── Loading state ─────────────────────────────────────────────────────────────
function setLoading(on) {
  if (on) {
    document.getElementById('tbody').innerHTML =
      `<tr><td colspan="11" style="text-align:center;padding:32px">
        <span class="skel" style="width:55%;height:14px;display:inline-block"> </span>
      </td></tr>`;
    document.getElementById('paginationBar').style.display = 'none';
  }
}

// ── Detail Modal ──────────────────────────────────────────────────────────────
// We fetch the row we already have in memory from the last fetch.
// For the modal we re-fetch a single visit to get medications too.
let cachedRows = {};

async function openDetail(visitId) {
  // Try cache first — rows from last table render
  let visit = cachedRows[visitId];

  if (!visit) {
    // fetch single record by embedding visit_id logic
    try {
      const res  = await fetch(`api/get_visit_reports.php?visitId=${visitId}&all=1&page=1&perPage=1`);
      const data = await res.json();
      visit = data.data?.[0];
    } catch(_) {}
  }

  if (!visit) { notify('Error','Could not load visit details.','error'); return; }
  currentVisit = visit;

  // Build detail HTML
  const [badgeCls, badgeLbl] = ACTION_BADGE[visit.action_taken] || ['b-returned', visit.action_taken||'—'];

  const medsHtml = visit.medications && visit.medications.length
    ? `<table class="med-table">
        <thead><tr><th>Medication</th><th>Dosage</th><th>Time Given</th></tr></thead>
        <tbody>${visit.medications.map(m=>`<tr>
          <td>${esc(m.medication_name)}</td>
          <td>${esc(m.dosage||'—')}</td>
          <td>${esc(m.time_given||'—')}</td>
        </tr>`).join('')}</tbody>
      </table>`
    : `<p class="no-meds"><i class="fas fa-info-circle"></i> No medications recorded for this visit.</p>`;

  document.getElementById('modalTitle').innerHTML =
    `<i class="fas fa-file-medical-alt"></i> Visit — ${esc(visit.full_name)} <span style="font-weight:400;opacity:.75">${fmtDatetime(visit.visit_datetime)}</span>`;

  document.getElementById('modalBody').innerHTML = `
    <!-- Patient + Outcome -->
    <div class="detail-head"><i class="fas fa-user-circle"></i> Patient &amp; Outcome</div>
    <div class="detail-grid cols3">
      <div class="di"><div class="di-lbl">Student</div><div class="di-val">${esc(visit.full_name)}</div></div>
      <div class="di"><div class="di-lbl">Student ID</div><div class="di-val">${esc(visit.student_id)}</div></div>
      <div class="di"><div class="di-lbl">Class</div><div class="di-val">${esc(visit.current_class||'—')}</div></div>
      <div class="di"><div class="di-lbl">Visit Date &amp; Time</div><div class="di-val">${fmtDatetime(visit.visit_datetime)}</div></div>
      <div class="di"><div class="di-lbl">Attended By</div><div class="di-val">${esc(visit.attended_by||'—')}</div></div>
      <div class="di"><div class="di-lbl">Outcome</div><div class="di-val"><span class="badge ${badgeCls}">${badgeLbl}</span></div></div>
    </div>

    <!-- Complaint + Vitals -->
    <div class="detail-head"><i class="fas fa-heartbeat"></i> Complaint &amp; Vitals</div>
    <div class="detail-grid cols3">
      <div class="di full"><div class="di-lbl">Chief Complaint</div>
        <div class="di-val">${visit.chief_complaint ? esc(visit.chief_complaint) : '<span class="muted">None recorded</span>'}</div>
      </div>
      <div class="di"><div class="di-lbl">Temperature</div><div class="di-val">${visit.temperature ? esc(visit.temperature)+'°C' : '—'}</div></div>
      <div class="di"><div class="di-lbl">Blood Pressure</div><div class="di-val">${esc(visit.blood_pressure||'—')}</div></div>
      <div class="di"><div class="di-lbl">Rest Time</div><div class="di-val">${visit.rest_time_minutes ? visit.rest_time_minutes+' min' : '—'}</div></div>
    </div>

    <!-- Assessment & Treatment -->
    <div class="detail-head"><i class="fas fa-stethoscope"></i> Assessment &amp; Treatment</div>
    <div class="detail-grid">
      <div class="di"><div class="di-lbl">Assessment Notes</div>
        <div class="di-val ${visit.assessment_notes?'':'muted'}">${esc(visit.assessment_notes||'None recorded')}</div></div>
      <div class="di"><div class="di-lbl">Treatment Notes</div>
        <div class="di-val ${visit.treatment_notes?'':'muted'}">${esc(visit.treatment_notes||'None recorded')}</div></div>
    </div>

    <!-- Medications -->
    <div class="detail-head"><i class="fas fa-pills"></i> Medications Dispensed</div>
    ${medsHtml}

    <!-- Parent & Follow-up -->
    <div class="detail-head" style="margin-top:20px"><i class="fas fa-phone"></i> Parent &amp; Follow-up</div>
    <div class="detail-grid cols3">
      <div class="di"><div class="di-lbl">Parent Notified</div>
        <div class="di-val">${visit.parent_notified==1?'<span class="badge b-yes">Yes</span>':'<span class="badge b-no">No</span>'}</div></div>
      <div class="di full"><div class="di-lbl">Notification Notes</div>
        <div class="di-val ${visit.parent_notification_notes?'':'muted'}">${esc(visit.parent_notification_notes||'—')}</div></div>
      <div class="di"><div class="di-lbl">Follow-up Required</div>
        <div class="di-val">${visit.followup_required==1?'<span class="badge b-pending">Yes</span>':'<span class="badge b-no">No</span>'}</div></div>
      <div class="di"><div class="di-lbl">Follow-up Date</div>
        <div class="di-val">${visit.followup_date ? fmtDate(visit.followup_date) : '—'}</div></div>
      <div class="di"><div class="di-lbl">Follow-up Notes</div>
        <div class="di-val ${visit.followup_notes?'':'muted'}">${esc(visit.followup_notes||'—')}</div></div>
    </div>
  `;

  document.getElementById('detailModal').classList.add('active');
  document.body.style.overflow = 'hidden';
}

function closeModal() {
  document.getElementById('detailModal').classList.remove('active');
  document.body.style.overflow = '';
  currentVisit = null;
}

// ── Print single visit ────────────────────────────────────────────────────────
document.getElementById('btnPrintVisit').addEventListener('click', () => {
  if (!currentVisit) return;
  const v = currentVisit;
  const [,badgeLbl] = ACTION_BADGE[v.action_taken] || ['',''];
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF('p','mm','a4');
  doc.setFontSize(16); doc.setTextColor(27,94,32);
  doc.text('Sick Bay Visit Record', 14, 20);
  doc.setFontSize(10); doc.setTextColor(80);
  doc.text(`Student: ${v.full_name} (${v.student_id})`, 14, 30);
  doc.text(`Date: ${fmtDatetime(v.visit_datetime)}`, 14, 37);
  doc.text(`Attended by: ${v.attended_by||'—'}`, 14, 44);
  doc.text(`Outcome: ${badgeLbl}`, 14, 51);

  const details = [
    ['Chief Complaint', v.chief_complaint||'—'],
    ['Temperature',     v.temperature ? v.temperature+'°C' : '—'],
    ['Blood Pressure',  v.blood_pressure||'—'],
    ['Rest Time',       v.rest_time_minutes ? v.rest_time_minutes+' min' : '—'],
    ['Assessment Notes',v.assessment_notes||'—'],
    ['Treatment Notes', v.treatment_notes||'—'],
    ['Parent Notified', v.parent_notified==1 ? 'Yes' : 'No'],
    ['Follow-up Required', v.followup_required==1 ? 'Yes — '+fmtDate(v.followup_date) : 'No'],
  ];
  doc.autoTable({
    head:[['Field','Value']], body:details, startY:58,
    theme:'grid', headStyles:{fillColor:[46,125,50],fontSize:9},
    bodyStyles:{fontSize:8}, columnStyles:{0:{fontStyle:'bold',cellWidth:60}}
  });

  if (v.medications && v.medications.length) {
    doc.autoTable({
      head:[['Medication','Dosage','Time Given']],
      body: v.medications.map(m=>[m.medication_name, m.dosage||'—', m.time_given||'—']),
      startY: doc.lastAutoTable.finalY + 10,
      theme:'grid', headStyles:{fillColor:[46,125,50],fontSize:9}, bodyStyles:{fontSize:8}
    });
  }
  doc.save(`visit-${v.visit_id}-${v.student_id}.pdf`);
  notify('Printed','PDF downloaded.','success');
});

// ── Bulk Export ───────────────────────────────────────────────────────────────
document.getElementById('btnPdf').addEventListener('click', () => exportAll('pdf'));
document.getElementById('btnExcel').addEventListener('click', () => exportAll('excel'));

async function exportAll(fmt) {
  try {
    const qs  = new URLSearchParams({...lastFilters, all:1});
    const res = await fetch(`api/get_visit_reports.php?${qs}`);
    const data = await res.json();
    if (!data.success || !data.data.length) { notify('Empty','No records to export.','warning'); return; }
    const rows = data.data;

    if (fmt === 'pdf') {
      const { jsPDF } = window.jspdf;
      const doc = new jsPDF('landscape','mm','a4');
      doc.setFontSize(15); doc.setTextColor(27,94,32);
      doc.text('Sick Bay Visit Reports', 14, 18);
      doc.setFontSize(8); doc.setTextColor(100);
      doc.text('Generated: '+new Date().toLocaleDateString('en-UG'), 14, 24);
      doc.autoTable({
        head:[['Date','Student','ID','Class','Complaint','Temp','Outcome','Attended By','Parent','Follow-up']],
        body: rows.map(r=>[
          fmtDatetime(r.visit_datetime), r.full_name, r.student_id, r.current_class||'',
          r.chief_complaint||'—', r.temperature ? r.temperature+'°C' : '—',
          (ACTION_BADGE[r.action_taken]||['',''])[1],
          r.attended_by||'—',
          r.parent_notified==1 ? 'Yes' : 'No',
          r.followup_required==1 ? 'Yes' : 'No'
        ]),
        startY:30, theme:'grid',
        headStyles:{fillColor:[56,142,60],fontSize:7.5},
        bodyStyles:{fontSize:7}
      });
      doc.save('visit-reports-'+datestamp()+'.pdf');
    } else {
      const wb = XLSX.utils.book_new();
      const ws = XLSX.utils.json_to_sheet(rows.map(r=>({
        'Date & Time':       fmtDatetime(r.visit_datetime),
        'Student':           r.full_name,
        'Student ID':        r.student_id,
        'Class':             r.current_class||'',
        'Chief Complaint':   r.chief_complaint||'',
        'Temperature':       r.temperature||'',
        'Blood Pressure':    r.blood_pressure||'',
        'Assessment Notes':  r.assessment_notes||'',
        'Treatment Notes':   r.treatment_notes||'',
        'Outcome':           (ACTION_BADGE[r.action_taken]||['',''])[1],
        'Attended By':       r.attended_by||'',
        'Parent Notified':   r.parent_notified==1 ? 'Yes' : 'No',
        'Follow-up Required':r.followup_required==1 ? 'Yes' : 'No',
        'Follow-up Date':    r.followup_date||'',
      })));
      XLSX.utils.book_append_sheet(wb, ws, 'Visit Reports');
      XLSX.writeFile(wb, 'visit-reports-'+datestamp()+'.xlsx');
    }
    notify('Exported', fmt.toUpperCase()+' downloaded.', 'success');
  } catch(e) { notify('Error','Export failed: '+e.message,'error'); }
}

// ── Listeners ─────────────────────────────────────────────────────────────────
let searchTimer;
document.getElementById('searchInput').addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => fetchReports(1), 320);
});
['dateFrom','dateTo','classFilter','actionFilter'].forEach(id => {
  document.getElementById(id).addEventListener('change', () => fetchReports(1));
});

document.getElementById('detailModal').addEventListener('click', e => {
  if (e.target === document.getElementById('detailModal')) closeModal();
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeModal();
});

// ── Helpers ───────────────────────────────────────────────────────────────────
function esc(v) {
  return String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtDatetime(d) {
  if (!d) return '—';
  try {
    return new Date(d).toLocaleString('en-UG', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
  } catch(_) { return d; }
}
function fmtDate(d) {
  if (!d) return '—';
  try { return new Date(d+'T00:00:00').toLocaleDateString('en-UG',{day:'2-digit',month:'short',year:'numeric'}); }
  catch(_) { return d; }
}
function datestamp() { return new Date().toISOString().split('T')[0]; }
function notify(title, msg, type='success', dur=4500) {
  const icons = {success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation'};
  const n = document.createElement('div');
  n.className = `notif ${type}`;
  n.innerHTML = `<i class="fas ${icons[type]||icons.success} notif-icon"></i>
    <div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div>
    <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
  document.getElementById('notif-stack').prepend(n);
  setTimeout(() => {
    n.style.opacity='0'; n.style.transform='translateX(30px)'; n.style.transition='.3s';
    setTimeout(() => n.remove(), 300);
  }, dur);
}

// ── Delete Visit ──────────────────────────────────────────────────────────────
let deleteTargetId   = null;
let deleteOriginModal = false; // track if delete was triggered from the detail modal

function confirmDelete(visitId, studentName) {
  deleteTargetId    = visitId;
  deleteOriginModal = false;
  document.getElementById('confirmStudentName').textContent = studentName;
  document.getElementById('confirmDeleteBtn').disabled      = false;
  document.getElementById('confirmDeleteBtn').innerHTML     = '<i class="fas fa-trash-alt"></i> Yes, Delete';
  document.getElementById('confirmOverlay').classList.add('active');
  document.body.style.overflow = 'hidden';
}

function confirmDeleteFromModal() {
  if (!currentVisit) return;
  deleteOriginModal = true;
  confirmDelete(currentVisit.visit_id, currentVisit.full_name);
}

function closeConfirm() {
  document.getElementById('confirmOverlay').classList.remove('active');
  if (!deleteOriginModal) document.body.style.overflow = '';
  deleteTargetId    = null;
  deleteOriginModal = false;
}

async function executeDelete() {
  if (!deleteTargetId) return;

  const btn = document.getElementById('confirmDeleteBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting…';

  try {
    const res  = await fetch('api/delete_sickbay_visit.php', {
      method : 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body   : new URLSearchParams({ visit_id: deleteTargetId }),
    });
    const data = await res.json();

    if (!data.success) throw new Error(data.message || 'Delete failed.');

    // Clean up caches
    delete cachedRows[deleteTargetId];

    notify('Deleted', 'Visit record removed successfully.', 'success');
    closeConfirm();

    // If delete came from inside the detail modal, close it too
    if (deleteOriginModal) closeModal();

    // Refresh table — go to previous page if current page is now empty
    const remainingOnPage = (totalRecords - 1) - (currentPage - 1) * PER_PAGE;
    const goToPage = remainingOnPage <= 0 && currentPage > 1 ? currentPage - 1 : currentPage;
    fetchReports(goToPage);

  } catch (e) {
    notify('Error', e.message, 'error');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-trash-alt"></i> Yes, Delete';
  }
}

// Close confirm dialog on backdrop click or Escape
document.getElementById('confirmOverlay').addEventListener('click', e => {
  if (e.target === document.getElementById('confirmOverlay')) closeConfirm();
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && document.getElementById('confirmOverlay').classList.contains('active')) {
    closeConfirm();
  }
});

// ── Init ──────────────────────────────────────────────────────────────────────
fetchReports(1);
</script>
</body>
</html>
