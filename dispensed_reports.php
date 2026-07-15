<?php
/**
 * dispensed_reports.php — REDESIGNED
 * Design aligned with view_students.php (Sen font, green CSS vars).
 * XSS fix: all data injected into innerHTML is escaped via esc().
 * The original injected raw report.full_name, report.item_name etc. without escaping.
 */

require_once "conn.php";
require_once "auth.php";
require_once 'tracking.php';
$tracker->trackAction("Dispensed Reports");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dispensed Reports — School Pilot</title>
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
  --gray:#546e7a;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);
  --shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --transition:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}

.page{max-width:100%;padding:24px 20px 52px;margin-top:40px}

.page-header{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--radius-lg);padding:28px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:24px;box-shadow:var(--shadow-lg)}
.page-header h1{color:#fff;font-size:1.55rem;font-weight:700}
.page-header p{color:rgba(255,255,255,.75);font-size:.88rem;margin-top:3px}
.stat-pills{display:flex;gap:10px;flex-wrap:wrap}
.stat-pill{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:40px;padding:7px 16px;text-align:center}
.stat-pill .n{font-size:1.2rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.7rem;color:rgba(255,255,255,.72);text-transform:uppercase;letter-spacing:.5px}

.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden}

/* Toolbar */
.toolbar{padding:16px 22px;border-bottom:1px solid #e8ede9;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.toolbar-left{display:flex;flex-wrap:wrap;gap:10px;align-items:center;flex:1}
.toolbar-right{display:flex;gap:8px;align-items:center;flex-shrink:0}
.search-wrap{position:relative;min-width:220px}
.search-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.8rem}
.search-wrap input{width:100%;padding:9px 12px 9px 32px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;transition:border-color var(--transition)}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.date-input{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;transition:border-color var(--transition)}
.date-input:focus{outline:none;border-color:var(--g600)}
.date-sep{font-size:.75rem;color:var(--gray)}
.result-count{font-size:.78rem;color:#6b7c6d;white-space:nowrap}

.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all var(--transition);white-space:nowrap}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-danger{background:var(--red);color:#fff}.btn-danger:hover{background:#b71c1c}
.btn-pdf{background:var(--red);color:#fff}
.btn-excel{background:var(--g800);color:#fff}
.btn-icon{width:30px;height:30px;border:none;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;transition:all var(--transition);flex-shrink:0}
.btn-icon:hover{transform:translateY(-1px)}
.bi-edit{background:var(--orange-bg);color:var(--orange)}.bi-edit:hover{background:var(--orange);color:#fff}
.bi-delete{background:var(--red-bg);color:var(--red)}.bi-delete:hover{background:var(--red);color:#fff}

/* Table */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:12px 14px;text-align:left;font-size:.78rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--transition)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:12px 14px;font-size:.875rem;vertical-align:middle}
.action-cell{display:flex;gap:5px}
.student-name{font-weight:600;color:var(--g800)}
.skel{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;display:inline-block}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
.empty-state{text-align:center;padding:52px 20px;color:#8a9a8b}
.empty-state i{font-size:2.8rem;opacity:.35;display:block;margin-bottom:12px}

/* Pagination */
.pagination{padding:14px 22px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e8ede9;flex-wrap:wrap;gap:10px}
.page-info{font-size:.8rem;color:#6b7c6d}
.page-btns{display:flex;gap:4px}
.page-btn{width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:6px;background:#fff;cursor:pointer;font-size:.82rem;font-weight:600;color:#444;display:flex;align-items:center;justify-content:center;transition:all var(--transition);font-family:inherit}
.page-btn:hover:not(:disabled){border-color:var(--g600);background:var(--g100);color:var(--g800)}
.page-btn.active{background:var(--g700);border-color:var(--g700);color:#fff}
.page-btn:disabled{opacity:.38;cursor:default}

/* Modals */
.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);animation:fadeOv .2s ease}
@keyframes fadeOv{from{opacity:0}to{opacity:1}}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:480px;box-shadow:var(--shadow-lg);animation:slideD .25s ease;margin:auto}
.modal-box.sm{max-width:400px}
@keyframes slideD{from{transform:translateY(-20px);opacity:0}to{transform:none;opacity:1}}
.modal-head{background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%);padding:17px 22px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head h2{color:#fff;font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:8px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:28px;height:28px;border-radius:50%;font-size:.9rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background var(--transition)}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:22px 22px 16px}
.modal-foot{padding:14px 22px;border-top:1px solid #e8ede9;display:flex;justify-content:flex-end;gap:10px}

.fg{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.fg:last-child{margin-bottom:0}
.fg label{font-size:.78rem;font-weight:700;color:var(--g800);text-transform:uppercase;letter-spacing:.4px}
.fg input,.fg textarea{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;transition:border-color var(--transition)}
.fg input:focus,.fg textarea:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.fg input[disabled]{background:#f5f5f5;color:var(--gray);cursor:default}
.fg textarea{resize:vertical;min-height:72px}

/* Delete confirm */
.del-confirm{text-align:center;padding:8px 0}
.del-icon{width:64px;height:64px;background:var(--red-bg);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.6rem;color:var(--red);margin:0 auto 16px}

/* Notifications */
#notif-stack{position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.notif{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:var(--radius);box-shadow:var(--shadow-lg);min-width:240px;max-width:340px;pointer-events:all;background:#fff;border-left:4px solid var(--g600);animation:slideIn .25s ease}
@keyframes slideIn{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}
.notif.error{border-left-color:var(--red)}
.notif.warning{border-left-color:var(--orange)}
.notif-icon{margin-top:1px}
.notif.success .notif-icon{color:var(--g600)}
.notif.error .notif-icon{color:var(--red)}
.notif.warning .notif-icon{color:var(--orange)}
.notif-body{flex:1;font-size:.82rem}
.notif-title{font-weight:700}
.notif-msg{color:var(--gray)}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:.8rem;padding:0;margin-left:4px}
</style>
</head>
<body>
<?php require_once 'nav.php' ?>
<div id="notif-stack"></div>

<div class="page">

  <header class="page-header">
    <div>
      <h1><i class="fas fa-history" style="margin-right:10px;opacity:.85"></i>Dispensed Reports</h1>
      <p>View, edit, and export all dispensing records.</p>
    </div>
    <div class="stat-pills">
      <div class="stat-pill"><span class="n" id="totalRecordsPill">—</span><span class="l">Records</span></div>
    </div>
  </header>

  <div class="card">
    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Name, ID, or item…">
        </div>
        <input type="date" id="dateFrom" class="date-input" title="From date">
        <span class="date-sep">→</span>
        <input type="date" id="dateTo" class="date-input" title="To date">
        <span class="result-count" id="resultCount"></span>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-pdf" id="btnPdf"><i class="fas fa-file-pdf"></i> PDF</button>
        <button class="btn btn-excel" id="btnExcel"><i class="fas fa-file-excel"></i> Excel</button>
      </div>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Date &amp; Time</th>
            <th>Student</th>
            <th>Student ID</th>
            <th>Item</th>
            <th>Quantity</th>
            <th>Notes</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="tbody">
          <tr><td colspan="7" style="text-align:center;padding:36px;color:#8a9a8b">
            <span class="skel" style="width:55%;height:14px;display:inline-block"> </span>
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

</div>

<!-- Edit Modal -->
<div class="modal" id="editModal">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-pencil-alt"></i> Edit Record</h2>
      <button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="editId">
      <div class="fg"><label>Student</label><input type="text" id="editStudent" disabled></div>
      <div class="fg"><label>Item</label><input type="text" id="editItem" disabled></div>
      <div class="fg"><label>Quantity Withdrawn *</label><input type="number" id="editQty" min="1" required></div>
      <div class="fg"><label>Notes</label><textarea id="editNotes" rows="3"></textarea></div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
      <button class="btn btn-primary" id="btnSaveEdit"><i class="fas fa-save"></i> Save Changes</button>
    </div>
  </div>
</div>

<!-- Delete Modal -->
<div class="modal" id="deleteModal">
  <div class="modal-box sm">
    <div class="modal-head">
      <h2><i class="fas fa-trash-alt"></i> Confirm Deletion</h2>
      <button class="modal-close" onclick="closeModal('deleteModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="del-confirm">
        <div class="del-icon"><i class="fas fa-trash-alt"></i></div>
        <p>Delete this dispensing record?</p>
        <p style="font-size:.82rem;color:var(--gray);margin-top:8px">The dispensed quantity will be <strong>returned to inventory</strong>.</p>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
      <button class="btn btn-danger" id="btnConfirmDelete"><i class="fas fa-trash"></i> Delete &amp; Restore</button>
    </div>
  </div>
</div>

<script>
const PER_PAGE = 10;
let currentPage = 1;
let totalRecords = 0;
let deleteId = null;
let lastParams = {};

// ── Fetch reports ─────────────────────────────────────────────────────────────
async function fetchReports(page = 1) {
  currentPage = page;
  const params = {
    page,
    dateFrom: document.getElementById('dateFrom').value,
    dateTo:   document.getElementById('dateTo').value,
    search:   document.getElementById('searchInput').value,
  };
  lastParams = params;

  document.getElementById('tbody').innerHTML =
    `<tr><td colspan="7" style="text-align:center;padding:32px;color:#8a9a8b">
      <span class="skel" style="width:55%;height:14px;display:inline-block"> </span>
    </td></tr>`;

  try {
    const qs  = new URLSearchParams(params);
    const res = await fetch(`api/get_dispensed_reports.php?${qs}`);
    const data = await res.json();

    if (!data.success) throw new Error(data.message);

    totalRecords = data.totalRecords;
    document.getElementById('totalRecordsPill').textContent = totalRecords;
    document.getElementById('resultCount').textContent = totalRecords + ' record' + (totalRecords!==1?'s':'');

    renderTable(data.data);
    renderPagination(totalRecords, page);
  } catch(e) {
    document.getElementById('tbody').innerHTML =
      `<tr><td colspan="7"><div class="empty-state"><i class="fas fa-exclamation-circle" style="color:var(--red)"></i><p>${esc(e.message)}</p></div></td></tr>`;
  }
}

// ── Render table ──────────────────────────────────────────────────────────────
function renderTable(rows) {
  const tbody = document.getElementById('tbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state"><i class="fas fa-inbox"></i><p>No records match your criteria.</p></div></td></tr>`;
    return;
  }
  const fmtDt = d => { try{ return new Date(d).toLocaleString('en-UG',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}); }catch(_){return d;} };
  // XSS FIX: every value run through esc() before injecting into innerHTML
  tbody.innerHTML = rows.map(r=>`<tr data-id="${r.withdrawal_id}">
    <td style="font-size:.8rem;white-space:nowrap">${fmtDt(r.withdrawal_date)}</td>
    <td><span class="student-name">${esc(r.full_name)}</span></td>
    <td>${esc(r.student_id)}</td>
    <td>${esc(r.item_name)}</td>
    <td>${esc(r.quantity_withdrawn)} <span style="font-size:.78rem;color:var(--gray)">${esc(r.unit||'')}</span></td>
    <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(r.notes||'')}">${esc(r.notes||'—')}</td>
    <td class="action-cell">
      <button class="btn-icon bi-edit" title="Edit" onclick="openEditModal(${r.withdrawal_id},'${esc(r.full_name)}','${esc(r.item_name)}',${r.quantity_withdrawn},'${esc(r.notes||'')}')"><i class="fas fa-pencil-alt"></i></button>
      <button class="btn-icon bi-delete" title="Delete" onclick="openDeleteModal(${r.withdrawal_id})"><i class="fas fa-trash-alt"></i></button>
    </td>
  </tr>`).join('');
}

// ── Pagination ────────────────────────────────────────────────────────────────
function renderPagination(total, page) {
  const bar     = document.getElementById('paginationBar');
  const totalPg = Math.ceil(total / PER_PAGE);
  if (totalPg <= 1) { bar.style.display='none'; return; }
  bar.style.display = 'flex';

  const from = (page-1)*PER_PAGE+1;
  const to   = Math.min(page*PER_PAGE, total);
  document.getElementById('pageInfo').textContent = `Showing ${from}–${to} of ${total}`;

  let html = '';
  html += `<button class="page-btn" ${page===1?'disabled':''} onclick="fetchReports(1)"><i class="fas fa-angle-double-left"></i></button>`;
  html += `<button class="page-btn" ${page===1?'disabled':''} onclick="fetchReports(${page-1})"><i class="fas fa-chevron-left"></i></button>`;
  const start = Math.max(1,page-2), end = Math.min(totalPg,page+2);
  for(let i=start;i<=end;i++) {
    html += `<button class="page-btn${i===page?' active':''}" onclick="fetchReports(${i})">${i}</button>`;
  }
  html += `<button class="page-btn" ${page===totalPg?'disabled':''} onclick="fetchReports(${page+1})"><i class="fas fa-chevron-right"></i></button>`;
  html += `<button class="page-btn" ${page===totalPg?'disabled':''} onclick="fetchReports(${totalPg})"><i class="fas fa-angle-double-right"></i></button>`;
  document.getElementById('pageBtns').innerHTML = html;
}

// ── Edit ──────────────────────────────────────────────────────────────────────
function openEditModal(id, student, item, qty, notes) {
  document.getElementById('editId').value      = id;
  document.getElementById('editStudent').value = student;
  document.getElementById('editItem').value    = item;
  document.getElementById('editQty').value     = qty;
  document.getElementById('editNotes').value   = notes;
  openModal('editModal');
}
document.getElementById('btnSaveEdit').addEventListener('click', async () => {
  const btn = document.getElementById('btnSaveEdit');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
  try {
    const res    = await fetch('api/update_withdrawal.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({
        withdrawal_id:    parseInt(document.getElementById('editId').value),
        quantity_withdrawn: parseInt(document.getElementById('editQty').value),
        notes:            document.getElementById('editNotes').value,
      })
    });
    const result = await res.json();
    if (result.success) {
      closeModal('editModal');
      notify('Updated', result.message, 'success');
      fetchReports(currentPage);
    } else {
      notify('Error', result.message, 'error');
    }
  } catch(e) { notify('Error','Request failed.','error'); }
  finally { btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Save Changes'; }
});

// ── Delete ────────────────────────────────────────────────────────────────────
function openDeleteModal(id) { deleteId = id; openModal('deleteModal'); }
document.getElementById('btnConfirmDelete').addEventListener('click', async () => {
  if (!deleteId) return;
  const btn = document.getElementById('btnConfirmDelete');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting…';
  try {
    const res    = await fetch('api/delete_withdrawal.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ withdrawal_id: deleteId })
    });
    const result = await res.json();
    if (result.success) {
      closeModal('deleteModal');
      notify('Deleted', result.message, 'success');
      fetchReports(currentPage);
    } else {
      notify('Error', result.message, 'error');
    }
  } catch(e) { notify('Error','Request failed.','error'); }
  finally { btn.disabled=false; btn.innerHTML='<i class="fas fa-trash"></i> Delete &amp; Restore'; deleteId=null; }
});

// ── Export ────────────────────────────────────────────────────────────────────
document.getElementById('btnPdf').addEventListener('click', () => exportData('pdf'));
document.getElementById('btnExcel').addEventListener('click', () => exportData('excel'));

async function exportData(fmt) {
  try {
    const qs  = new URLSearchParams({...lastParams, all:1});
    const res = await fetch(`api/get_dispensed_reports.php?${qs}`);
    const data = await res.json();
    if (!data.success || !data.data.length) { notify('Empty','No data to export.','warning'); return; }
    const rows = data.data;
    const fmtDt = d => { try{ return new Date(d).toLocaleString('en-UG'); }catch(_){return d;} };
    if (fmt==='pdf') {
      const { jsPDF } = window.jspdf;
      const doc = new jsPDF('landscape','mm','a4');
      doc.setFontSize(16); doc.setTextColor(27,94,32);
      doc.text('Dispensed Medical Reports', 14, 18);
      doc.setFontSize(9); doc.setTextColor(100);
      doc.text('Generated: '+new Date().toLocaleDateString('en-UG'), 14, 25);
      doc.autoTable({
        head:[['Date & Time','Student','Student ID','Item','Quantity','Notes']],
        body: rows.map(r=>[fmtDt(r.withdrawal_date),r.full_name,r.student_id,r.item_name,r.quantity_withdrawn+' '+r.unit,r.notes||'—']),
        startY:30,theme:'grid',headStyles:{fillColor:[56,142,60],fontSize:8},bodyStyles:{fontSize:7.5}
      });
      doc.save('dispensed-reports-'+datestamp()+'.pdf');
    } else {
      const wb = XLSX.utils.book_new();
      const ws = XLSX.utils.json_to_sheet(rows.map(r=>({
        'Date & Time': fmtDt(r.withdrawal_date),
        'Student Name': r.full_name,
        'Student ID': r.student_id,
        'Item': r.item_name,
        'Quantity': r.quantity_withdrawn,
        'Unit': r.unit,
        'Notes': r.notes||''
      })));
      XLSX.utils.book_append_sheet(wb, ws, 'Dispensed Reports');
      XLSX.writeFile(wb, 'dispensed-reports-'+datestamp()+'.xlsx');
    }
    notify('Exported', fmt.toUpperCase()+' downloaded.', 'success');
  } catch(e) { notify('Error','Export failed: '+e.message,'error'); }
}

// ── Event listeners ───────────────────────────────────────────────────────────
let searchTimer;
document.getElementById('searchInput').addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(()=>fetchReports(1), 320);
});
document.getElementById('dateFrom').addEventListener('change', ()=>fetchReports(1));
document.getElementById('dateTo').addEventListener('change',   ()=>fetchReports(1));

// ── Modal helpers ─────────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('active'); document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('active'); document.body.style.overflow=''; }
document.querySelectorAll('.modal').forEach(m=>m.addEventListener('click',e=>{ if(e.target===m) closeModal(m.id); }));
document.addEventListener('keydown',e=>{ if(e.key==='Escape') document.querySelectorAll('.modal.active').forEach(m=>closeModal(m.id)); });

// ── Notifications ─────────────────────────────────────────────────────────────
function notify(title, msg, type='success', dur=4500) {
  const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation'};
  const n = document.createElement('div');
  n.className=`notif ${type}`;
  n.innerHTML=`<i class="fas ${icons[type]||icons.success} notif-icon"></i>
    <div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div>
    <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
  document.getElementById('notif-stack').prepend(n);
  setTimeout(()=>{n.style.opacity='0';n.style.transform='translateX(30px)';n.style.transition='.3s';setTimeout(()=>n.remove(),300);},dur);
}
function esc(v){ return String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function datestamp(){ return new Date().toISOString().split('T')[0]; }

// ── Init ──────────────────────────────────────────────────────────────────────
fetchReports(1);
</script>
</body>
</html>
