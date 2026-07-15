<?php
/**
 * staff_list.php — Staff Information Registry
 * Security: CSRF token, XSS-safe rendering via esc(), role guard in API.
 */
require_once 'auth.php';
require_once 'tracking.php';
$tracker->trackAction('Staff List');

// CSRF token (mirrors students page pattern)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Staff List &mdash; School Pilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
<style>
/* ── Variables ──────────────────────────────────────────── */
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--orange:#e65100;--blue:#1565c0;--gray:#546e7a;
  --radius:8px;--radius-lg:12px;--shadow:0 2px 8px rgba(0,0,0,.10);
  --shadow-lg:0 8px 28px rgba(0,0,0,.14);--transition:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}
a{color:inherit;text-decoration:none}

/* ── Layout ─────────────────────────────────────────────── */
.page{max-width:100%;margin:0 auto;padding:24px 20px 48px}

/* ── Page Header ────────────────────────────────────────── */
.page-header{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--radius-lg);padding:28px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;margin-bottom:24px;margin-top:40px;box-shadow:var(--shadow-lg)}
.page-header h1{color:#fff;font-size:1.55rem;font-weight:700;letter-spacing:.3px}
.page-header p{color:rgba(255,255,255,.78);font-size:.9rem;margin-top:3px}
.stats-row{display:flex;gap:12px;flex-wrap:wrap}
.stat-pill{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:40px;padding:8px 18px;text-align:center;min-width:80px;cursor:default;transition:background var(--transition)}
.stat-pill:hover{background:rgba(255,255,255,.22)}
.stat-pill .n{font-size:1.35rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.72rem;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.5px}

/* ── Card ───────────────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden}

/* ── Toolbar ────────────────────────────────────────────── */
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

/* ── Buttons ────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;transition:all var(--transition);white-space:nowrap;cursor:pointer}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-pdf{background:#c62828;color:#fff}.btn-pdf:hover{background:var(--red)}
.btn-excel{background:var(--g800);color:#fff}.btn-excel:hover{background:var(--g900)}

/* ── Table ──────────────────────────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:13px 14px;text-align:left;font-size:.8rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap;cursor:pointer;user-select:none}
thead th:hover{background:rgba(255,255,255,.1)}
thead th .sort-icon{margin-left:5px;opacity:.55;font-size:.7rem}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--transition)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:13px 14px;font-size:.875rem;vertical-align:middle}

/* ── Avatar Initials ────────────────────────────────────── */
.staff-avatar{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;color:#fff;flex-shrink:0;border:2px solid var(--g400)}
.name-cell{display:flex;align-items:center;gap:10px}
.staff-name{font-weight:600;color:var(--g800)}
.staff-id-badge{font-size:.72rem;color:#8a9a8b;margin-top:1px}

/* ── Gender Badge ───────────────────────────────────────── */
.badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.badge-male{background:#e3f2fd;color:#1565c0}
.badge-female{background:#fce4ec;color:#c2185b}
.badge-other{background:#f3e5f5;color:#7b1fa2}

/* ── Sensitive field ─────────────────────────────────────── */
.sensitive{font-family:'Courier New',monospace;font-size:.82rem;letter-spacing:.3px;color:#546e7a}
.dash{color:#bbb}

/* ── Skeleton Rows ──────────────────────────────────────── */
.skeleton-cell{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;height:14px;display:inline-block;width:80%}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ── Empty State ────────────────────────────────────────── */
.empty-state{text-align:center;padding:60px 20px;color:#8a9a8b}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;opacity:.45}
.empty-state p{font-size:.95rem}

/* ── Error State ────────────────────────────────────────── */
.error-state{text-align:center;padding:48px 20px;color:var(--red)}
.error-state i{font-size:2.5rem;margin-bottom:10px;display:block;opacity:.6}
.error-state p{font-size:.9rem;margin-bottom:16px}
.error-state button{margin:0 auto}

/* ── Pagination ─────────────────────────────────────────── */
.pagination{padding:16px 24px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e8ede9;flex-wrap:wrap;gap:10px}
.page-info{font-size:.82rem;color:#6b7c6d}
.page-btns{display:flex;gap:4px}
.page-btn{width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:6px;background:#fff;cursor:pointer;font-size:.82rem;font-weight:600;color:#444;display:flex;align-items:center;justify-content:center;transition:all var(--transition)}
.page-btn:hover:not(:disabled){border-color:var(--g600);background:var(--g100);color:var(--g800)}
.page-btn.active{background:var(--g700);border-color:var(--g700);color:#fff}
.page-btn:disabled{opacity:.38;cursor:default}

/* ── View Modal ─────────────────────────────────────────── */
.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);animation:fadeOverlay .2s ease}
@keyframes fadeOverlay{from{opacity:0}to{opacity:1}}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:680px;box-shadow:var(--shadow-lg);animation:slideDown .25s ease;margin:auto}
@keyframes slideDown{from{transform:translateY(-24px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%);padding:20px 24px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head h2{color:#fff;font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background var(--transition)}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:28px}

/* ── Profile card inside modal ──────────────────────────── */
.profile-hero{display:flex;align-items:center;gap:18px;margin-bottom:24px}
.profile-avatar-lg{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:700;color:#fff;flex-shrink:0;border:3px solid var(--g400)}
.profile-name{font-size:1.2rem;font-weight:700;color:var(--g800)}
.profile-sub{font-size:.85rem;color:#6b7c6d;margin-top:3px}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;border:1px solid #e8ede9;border-radius:var(--radius);overflow:hidden}
.detail-row{display:contents}
.detail-row:not(:last-child) .d-label,
.detail-row:not(:last-child) .d-val{border-bottom:1px solid #e8ede9}
.d-label{padding:10px 14px;font-size:.75rem;font-weight:700;color:var(--g700);text-transform:uppercase;letter-spacing:.4px;background:#f5fbf5;border-right:1px solid #e8ede9}
.d-val{padding:10px 14px;font-size:.875rem;color:#333}

/* ── Notifications ──────────────────────────────────────── */
#notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.notif{background:#fff;border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow-lg);display:flex;align-items:flex-start;gap:12px;border-left:4px solid var(--g600);animation:notifIn .3s ease}
.notif.error{border-left-color:var(--red)}.notif.warning{border-left-color:#e65100}.notif.info{border-left-color:var(--blue)}
@keyframes notifIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.notif-icon{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.notif.success .notif-icon{color:var(--g700)}.notif.error .notif-icon{color:var(--red)}.notif.warning .notif-icon{color:#e65100}.notif.info .notif-icon{color:var(--blue)}
.notif-body{flex:1}.notif-title{font-weight:700;font-size:.85rem;margin-bottom:2px}.notif-msg{font-size:.8rem;color:#666}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:1rem;padding:0;line-height:1;flex-shrink:0}

/* ── Responsive ─────────────────────────────────────────── */
@media(max-width:700px){
  .toolbar{flex-direction:column;align-items:stretch}
  .toolbar-right{flex-wrap:wrap}
  .page-header{flex-direction:column}
  .detail-grid{grid-template-columns:1fr}
  .d-label{border-right:none}
  .stats-row{gap:8px}
  .profile-hero{flex-direction:column;text-align:center}
}

@media print{
  .toolbar,.pagination,.page-header .stats-row,#notif-stack,.btn{display:none!important}
  .page{padding:0}.card{box-shadow:none;border-radius:0}
  thead tr{background:#388e3c!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
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
      <h1><i class="fas fa-users" style="margin-right:10px;opacity:.85"></i>Staff List</h1>
      <p>View, search and export all staff records</p>
    </div>
    <div class="stats-row">
      <div class="stat-pill"><span class="n" id="sTotal">—</span><span class="l">Total</span></div>
      <div class="stat-pill"><span class="n" id="sMale">—</span><span class="l">Male</span></div>
      <div class="stat-pill"><span class="n" id="sFemale">—</span><span class="l">Female</span></div>
    </div>
  </div>

  <!-- ── Main Card ────────────────────────────────────────── -->
  <div class="card">

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search name, ID or email…" autocomplete="off">
        </div>
        <select id="genderFilter" class="filter-select">
          <option value="">All Genders</option>
          <option value="Male">Male</option>
          <option value="Female">Female</option>
        </select>
        <select id="perPage" class="filter-select">
          <option value="10">10 / page</option>
          <option value="25">25 / page</option>
          <option value="50">50 / page</option>
          <option value="100">100 / page</option>
        </select>
        <button class="btn btn-outline" id="clearFiltersBtn"><i class="fas fa-times-circle"></i> Clear</button>
        <span class="result-count" id="resultCount"></span>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-pdf"   onclick="exportToPDF()"><i class="fas fa-file-pdf"></i> PDF</button>
        <button class="btn btn-excel" onclick="exportToExcel()"><i class="fas fa-file-excel"></i> Excel</button>
      </div>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:40px">#</th>
            <th data-col="full_name">Name <span class="sort-icon fas fa-sort"></span></th>
            <th data-col="gender">Gender <span class="sort-icon fas fa-sort"></span></th>
            <th data-col="phone_number">Phone</th>
            <th data-col="email">Email</th>
            <th data-col="tin_number">TIN</th>
            <th data-col="nssf_number">NSSF</th>
            <th data-col="national_id">National ID</th>
            <th style="width:80px">Actions</th>
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

<!-- ── VIEW MODAL ─────────────────────────────────────────── -->
<div id="viewModal" class="modal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-id-badge"></i> Staff Profile</h2>
      <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="viewBody"></div>
  </div>
</div>

<script>
/* ── CSRF token (available for future POST requests) ─────── */
const CSRF = <?= json_encode($csrf) ?>;

/* ── State ───────────────────────────────────────────────── */
let allStaff   = [];
let filtered   = [];
let currentPage = 1;
let sortCol    = 'full_name';
let sortDir    = 'asc';
const avatarColors = ['#388e3c','#1565c0','#6a1b9a','#c62828','#e65100','#00695c','#283593'];

/* ── Bootstrap ───────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    loadStaff();

    document.getElementById('searchInput').addEventListener('input', applyFilters);
    document.getElementById('genderFilter').addEventListener('change', applyFilters);
    document.getElementById('perPage').addEventListener('change', () => { currentPage = 1; renderTable(); });
    document.getElementById('clearFiltersBtn').addEventListener('click', clearFilters);

    // Column sort
    document.querySelectorAll('thead th[data-col]').forEach(th => {
        th.addEventListener('click', () => {
            const col = th.dataset.col;
            if (sortCol === col) { sortDir = sortDir === 'asc' ? 'desc' : 'asc'; }
            else { sortCol = col; sortDir = 'asc'; }
            applyFilters();
        });
    });
});

/* ── Fetch ───────────────────────────────────────────────── */
function loadStaff() {
    renderSkeletons();
    fetch('api/fetch_staff.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(d => {
            if (!d.success) throw new Error(d.error || 'Server error');
            allStaff = d.data;
            updateStats();
            applyFilters();
        })
        .catch(err => renderError(err.message));
}

/* ── Stats pills ─────────────────────────────────────────── */
function updateStats() {
    document.getElementById('sTotal').textContent  = allStaff.length;
    document.getElementById('sMale').textContent   = allStaff.filter(s => s.gender === 'Male').length;
    document.getElementById('sFemale').textContent = allStaff.filter(s => s.gender === 'Female').length;
}

/* ── Filter + sort ───────────────────────────────────────── */
function applyFilters() {
    const q   = document.getElementById('searchInput').value.trim().toLowerCase();
    const gen = document.getElementById('genderFilter').value;

    filtered = allStaff.filter(s => {
        const matchQ = !q ||
            s.full_name.toLowerCase().includes(q) ||
            s.staff_id.toLowerCase().includes(q) ||
            (s.email && s.email.toLowerCase().includes(q)) ||
            (s.phone_number && s.phone_number.toLowerCase().includes(q));
        const matchG = !gen || s.gender === gen;
        return matchQ && matchG;
    });

    // Sort
    filtered.sort((a, b) => {
        const va = (a[sortCol] || '').toString().toLowerCase();
        const vb = (b[sortCol] || '').toString().toLowerCase();
        return sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
    });

    // Update sort icons
    document.querySelectorAll('thead th[data-col]').forEach(th => {
        const icon = th.querySelector('.sort-icon');
        if (!icon) return;
        icon.className = 'sort-icon fas ' +
            (th.dataset.col === sortCol ? (sortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort');
        icon.style.opacity = th.dataset.col === sortCol ? '1' : '.4';
    });

    currentPage = 1;
    renderTable();
}

/* ── Render table ─────────────────────────────────────────── */
function renderTable() {
    const perPage = parseInt(document.getElementById('perPage').value);
    const total   = filtered.length;
    const pages   = Math.max(1, Math.ceil(total / perPage));
    if (currentPage > pages) currentPage = pages;

    const start = (currentPage - 1) * perPage;
    const rows  = filtered.slice(start, start + perPage);

    document.getElementById('resultCount').textContent =
        total !== allStaff.length ? `${total} of ${allStaff.length} staff` : `${total} staff`;

    const tbody = document.getElementById('tBody');

    if (total === 0) {
        tbody.innerHTML = `
          <tr><td colspan="9">
            <div class="empty-state">
              <i class="fas fa-user-slash"></i>
              <p>No staff records match your search.</p>
            </div>
          </td></tr>`;
        document.getElementById('pageInfo').textContent = '';
        document.getElementById('pageBtns').innerHTML   = '';
        return;
    }

    tbody.innerHTML = rows.map((s, i) => {
        const idx   = start + i + 1;
        const initials = initials2(s.full_name);
        const color = avatarColors[s.staff_id.charCodeAt(s.staff_id.length - 1) % avatarColors.length];
        const gBadge = genderBadge(s.gender);

        return `<tr>
          <td style="color:#aaa;font-size:.8rem">${idx}</td>
          <td>
            <div class="name-cell">
              <div class="staff-avatar" style="background:${esc(color)}">${esc(initials)}</div>
              <div>
                <div class="staff-name">${esc(s.full_name)}</div>
                <div class="staff-id-badge">${esc(s.staff_id)}</div>
              </div>
            </div>
          </td>
          <td>${gBadge}</td>
          <td>${esc(s.phone_number)}</td>
          <td style="font-size:.85rem">${esc(s.email)}</td>
          <td><span class="sensitive">${esc(s.tin_number)}</span></td>
          <td><span class="sensitive">${esc(s.nssf_number)}</span></td>
          <td><span class="sensitive">${esc(s.national_id)}</span></td>
          <td>
            <button class="btn btn-outline" style="padding:6px 10px;font-size:.78rem"
                    onclick="viewStaff(${idx - 1 + start})" title="View details">
              <i class="fas fa-eye"></i>
            </button>
          </td>
        </tr>`;
    }).join('');

    // Pagination info
    const endIdx = Math.min(start + perPage, total);
    document.getElementById('pageInfo').textContent =
        `Showing ${start + 1}–${endIdx} of ${total} entries`;

    renderPagination(pages);
}

/* ── Pagination buttons ──────────────────────────────────── */
function renderPagination(pages) {
    const container = document.getElementById('pageBtns');
    let html = '';

    const mkBtn = (label, page, disabled = false, active = false) =>
        `<button class="page-btn${active ? ' active' : ''}" ${disabled ? 'disabled' : ''}
                 onclick="goPage(${page})">${label}</button>`;

    html += mkBtn('<i class="fas fa-chevron-left"></i>', currentPage - 1, currentPage === 1);

    let s = Math.max(1, currentPage - 2);
    let e = Math.min(pages, currentPage + 2);
    if (e - s < 4) { s = Math.max(1, e - 4); e = Math.min(pages, s + 4); }

    if (s > 1)     html += mkBtn('1', 1) + (s > 2 ? '<span style="padding:0 4px;color:#aaa">…</span>' : '');
    for (let p = s; p <= e; p++) html += mkBtn(p, p, false, p === currentPage);
    if (e < pages) html += (e < pages - 1 ? '<span style="padding:0 4px;color:#aaa">…</span>' : '') + mkBtn(pages, pages);

    html += mkBtn('<i class="fas fa-chevron-right"></i>', currentPage + 1, currentPage === pages);

    container.innerHTML = html;
}

function goPage(p) { currentPage = p; renderTable(); }

/* ── Skeleton loader ─────────────────────────────────────── */
function renderSkeletons() {
    const rows = Array.from({length: 8}, (_, i) => `
      <tr>
        <td><span class="skeleton-cell" style="width:20px"></span></td>
        <td><div class="name-cell">
          <div class="staff-avatar" style="background:#e0e0e0"></div>
          <div><span class="skeleton-cell" style="width:${100 + (i % 3) * 30}px"></span></div>
        </div></td>
        ${Array.from({length: 7}, () => `<td><span class="skeleton-cell"></span></td>`).join('')}
      </tr>`).join('');
    document.getElementById('tBody').innerHTML = rows;
}

/* ── Error state ─────────────────────────────────────────── */
function renderError(msg) {
    document.getElementById('tBody').innerHTML = `
      <tr><td colspan="9">
        <div class="error-state">
          <i class="fas fa-circle-exclamation"></i>
          <p>${esc(msg)}</p>
          <button class="btn btn-outline" onclick="loadStaff()">
            <i class="fas fa-rotate-right"></i> Retry
          </button>
        </div>
      </td></tr>`;
}

/* ── View modal ──────────────────────────────────────────── */
function viewStaff(idx) {
    const s = filtered[idx];
    if (!s) return;

    const initials = initials2(s.full_name);
    const color = avatarColors[s.staff_id.charCodeAt(s.staff_id.length - 1) % avatarColors.length];

    document.getElementById('viewBody').innerHTML = `
      <div class="profile-hero">
        <div class="profile-avatar-lg" style="background:${esc(color)}">${esc(initials)}</div>
        <div>
          <div class="profile-name">${esc(s.full_name)}</div>
          <div class="profile-sub">Staff ID: ${esc(s.staff_id)}</div>
          <div style="margin-top:6px">${genderBadge(s.gender)}</div>
        </div>
      </div>

      <div class="detail-grid">
        <div class="detail-row">
          <div class="d-label"><i class="fas fa-phone" style="margin-right:5px"></i>Phone</div>
          <div class="d-val">${esc(s.phone_number)}</div>
        </div>
        <div class="detail-row">
          <div class="d-label"><i class="fas fa-envelope" style="margin-right:5px"></i>Email</div>
          <div class="d-val">${esc(s.email)}</div>
        </div>
        <div class="detail-row">
          <div class="d-label"><i class="fas fa-receipt" style="margin-right:5px"></i>TIN Number</div>
          <div class="d-val"><span class="sensitive">${esc(s.tin_number)}</span></div>
        </div>
        <div class="detail-row">
          <div class="d-label"><i class="fas fa-shield-halved" style="margin-right:5px"></i>NSSF Number</div>
          <div class="d-val"><span class="sensitive">${esc(s.nssf_number)}</span></div>
        </div>
        <div class="detail-row">
          <div class="d-label"><i class="fas fa-id-card" style="margin-right:5px"></i>National ID</div>
          <div class="d-val"><span class="sensitive">${esc(s.national_id)}</span></div>
        </div>
      </div>`;

    document.getElementById('viewModal').classList.add('active');
}

function closeModal() {
    document.getElementById('viewModal').classList.remove('active');
}

/* ── Clear filters ───────────────────────────────────────── */
function clearFilters() {
    document.getElementById('searchInput').value   = '';
    document.getElementById('genderFilter').value  = '';
    applyFilters();
}

/* ── Export PDF ──────────────────────────────────────────── */
function exportToPDF() {
    if (!filtered.length) { notify('Empty', 'No records to export.', 'warning'); return; }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');
    doc.setFontSize(16); doc.setTextColor(46,125,50);
    doc.text('Staff Registry Report', 14, 18);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text('Generated: ' + new Date().toLocaleDateString('en-UG'), 14, 25);
    doc.autoTable({
        head: [['Staff ID','Full Name','Gender','Phone','Email','TIN','NSSF','National ID']],
        body: filtered.map(s => [
            s.staff_id, s.full_name, s.gender,
            s.phone_number, s.email,
            s.tin_number, s.nssf_number, s.national_id
        ]),
        startY: 30, theme: 'grid',
        headStyles: { fillColor: [67,160,71], fontSize: 8 },
        bodyStyles: { fontSize: 7.5 },
    });
    doc.save('staff-report-' + datestamp() + '.pdf');
    notify('Exported', 'PDF report downloaded.', 'success');
}

/* ── Export Excel ────────────────────────────────────────── */
function exportToExcel() {
    if (!filtered.length) { notify('Empty', 'No records to export.', 'warning'); return; }
    const rows = [['Staff ID','Full Name','Gender','Phone','Email','TIN Number','NSSF Number','National ID']];
    filtered.forEach(s => rows.push([
        s.staff_id, s.full_name, s.gender,
        s.phone_number, s.email,
        s.tin_number, s.nssf_number, s.national_id
    ]));
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(rows);
    ws['!cols'] = [{wch:14},{wch:26},{wch:10},{wch:16},{wch:28},{wch:16},{wch:16},{wch:16}];
    XLSX.utils.book_append_sheet(wb, ws, 'Staff');
    XLSX.writeFile(wb, 'staff-' + datestamp() + '.xlsx');
    notify('Exported', 'Excel file downloaded.', 'success');
}

/* ── Notifications ───────────────────────────────────────── */
function notify(title, msg, type = 'success', dur = 4000) {
    const icons = {success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
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

/* ── Helpers ─────────────────────────────────────────────── */
// XSS-safe escaper — NEVER insert raw user data into innerHTML without this
function esc(v) {
    return String(v == null ? '—' : v)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
        .replace(/'/g,'&#39;');
}

function initials2(name) {
    const parts = (name || '').trim().split(/\s+/);
    return parts.length >= 2
        ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
        : (parts[0] || '?')[0].toUpperCase();
}

function genderBadge(g) {
    const map = { Male: 'badge-male', Female: 'badge-female' };
    return `<span class="badge ${map[g] || 'badge-other'}">${esc(g || 'N/A')}</span>`;
}

function datestamp() { return new Date().toISOString().split('T')[0]; }
</script>
</body>
</html>