<?php
/**
 * inventory.php — REDESIGNED
 * Aligned with view_students.php design system (Sen font, green CSS vars).
 * CSRF token passed with every mutating request.
 * All innerHTML injection is XSS-safe via esc() helper.
 */

require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Sickbay Inventory");

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
<title>Inventory — School Pilot</title>
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
  --gray:#546e7a;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);
  --shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --transition:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}
a{color:inherit;text-decoration:none}

.page{max-width:100%;padding:24px 20px 52px;margin-top:40px}

/* Page Header */
.page-header{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--radius-lg);padding:28px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:24px;box-shadow:var(--shadow-lg)}
.page-header h1{color:#fff;font-size:1.55rem;font-weight:700}
.page-header p{color:rgba(255,255,255,.75);font-size:.88rem;margin-top:3px}
.stat-pills{display:flex;gap:10px;flex-wrap:wrap}
.stat-pill{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:40px;padding:7px 16px;text-align:center;cursor:default}
.stat-pill .n{font-size:1.2rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.7rem;color:rgba(255,255,255,.72);text-transform:uppercase;letter-spacing:.5px}

/* Card & Toolbar */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden}
.toolbar{padding:16px 22px;border-bottom:1px solid #e8ede9;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.toolbar-left{display:flex;flex-wrap:wrap;gap:10px;align-items:center;flex:1}
.toolbar-right{display:flex;gap:8px;align-items:center}
.search-wrap{position:relative;min-width:220px}
.search-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.8rem}
.search-wrap input{width:100%;padding:9px 12px 9px 32px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;transition:border-color var(--transition)}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.filter-sel{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;background:#fff;font-family:inherit;cursor:pointer;transition:border-color var(--transition)}
.filter-sel:focus{outline:none;border-color:var(--g600)}
.result-count{font-size:.78rem;color:#6b7c6d;white-space:nowrap}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all var(--transition);white-space:nowrap}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-danger{background:var(--red);color:#fff}.btn-danger:hover{background:#b71c1c}
.btn-pdf{background:var(--red);color:#fff}
.btn-excel{background:var(--g800);color:#fff}

/* Icon Buttons */
.btn-icon{width:30px;height:30px;border:none;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;transition:all var(--transition);flex-shrink:0}
.btn-icon:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.18)}
.bi-view{background:var(--blue-bg);color:var(--blue)}.bi-view:hover{background:var(--blue);color:#fff}
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
.item-name{font-weight:600;color:var(--g800)}

/* Status badges */
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.badge-good{background:var(--g100);color:var(--g800)}
.badge-low{background:var(--orange-bg);color:var(--orange)}
.badge-out{background:var(--red-bg);color:var(--red)}
.badge-expired{background:#f3e5f5;color:#6a1b9a}

/* Skeleton */
.skel{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;display:inline-block}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* Empty/error state */
.empty-state{text-align:center;padding:52px 20px;color:#8a9a8b}
.empty-state i{font-size:2.8rem;opacity:.35;display:block;margin-bottom:12px}

/* Modal */
.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);animation:fadeOv .2s ease}
@keyframes fadeOv{from{opacity:0}to{opacity:1}}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:780px;box-shadow:var(--shadow-lg);animation:slideD .25s ease;margin:auto}
.modal-box.modal-sm{max-width:440px}
@keyframes slideD{from{transform:translateY(-22px);opacity:0}to{transform:none;opacity:1}}
.modal-head{background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%);padding:18px 24px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head h2{color:#fff;font-size:1rem;font-weight:700;display:flex;align-items:center;gap:9px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:30px;height:30px;border-radius:50%;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background var(--transition)}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:26px 26px 20px}
.modal-foot{padding:16px 26px;border-top:1px solid #e8ede9;display:flex;justify-content:flex-end;gap:10px}

/* Form */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.form-grid.full{grid-template-columns:1fr}
.fg{display:flex;flex-direction:column;gap:5px}
.fg.span2{grid-column:1/-1}
.fg label{font-size:.78rem;font-weight:700;color:var(--g800);text-transform:uppercase;letter-spacing:.4px}
.fg input,.fg select,.fg textarea{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;transition:border-color var(--transition)}
.fg input:focus,.fg select:focus,.fg textarea:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.fg textarea{resize:vertical;min-height:72px}
.form-section{margin-bottom:20px}
.form-section h4{font-size:.82rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid #e8ede9;display:flex;align-items:center;gap:7px}

/* View detail grid */
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.detail-item{background:var(--g50);border-radius:var(--radius);padding:14px 16px;border:1px solid #e0ebe1}
.detail-item.full{grid-column:1/-1}
.detail-lbl{font-size:.72rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;display:flex;align-items:center;gap:6px}
.detail-lbl i{color:var(--g600)}
.detail-val{font-size:.9rem;font-weight:600;color:#1a1a1a}

/* Delete confirm */
.delete-confirm{text-align:center;padding:10px 0}
.delete-icon-wrap{width:70px;height:70px;background:var(--red-bg);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.8rem;color:var(--red);margin:0 auto 18px}

/* Notification */
#notif-stack{position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.notif{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:var(--radius);box-shadow:var(--shadow-lg);min-width:240px;max-width:340px;pointer-events:all;background:#fff;border-left:4px solid var(--g600);animation:slideIn .25s ease}
@keyframes slideIn{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}
.notif.error{border-left-color:var(--red)}
.notif.warning{border-left-color:var(--orange)}
.notif-icon{font-size:1rem;margin-top:1px}
.notif.success .notif-icon{color:var(--g600)}
.notif.error .notif-icon{color:var(--red)}
.notif.warning .notif-icon{color:var(--orange)}
.notif-body{flex:1;font-size:.82rem}
.notif-title{font-weight:700}
.notif-msg{color:var(--gray)}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:.8rem;padding:0;margin-left:4px}

@media(max-width:600px){.form-grid{grid-template-columns:1fr}.detail-grid{grid-template-columns:1fr}.page-header{padding:18px 16px}}
</style>
</head>
<body>
<?php require_once 'nav.php' ?>
<div id="notif-stack"></div>

<div class="page">

  <!-- Page Header -->
  <header class="page-header">
    <div>
      <h1><i class="fas fa-boxes" style="margin-right:10px;opacity:.85"></i>Sickbay Inventory</h1>
      <p>Manage all medical supplies, track stock levels and expiry dates.</p>
    </div>
    <div class="stat-pills" id="headerPills">
      <div class="stat-pill"><span class="n skel" style="width:32px;height:22px;display:inline-block"> </span><span class="l">Items</span></div>
    </div>
  </header>

  <!-- Table Card -->
  <div class="card">
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search name, category, supplier…">
        </div>
        <select id="catFilter" class="filter-sel"><option value="">All Categories</option></select>
        <select id="statusFilter" class="filter-sel">
          <option value="">All Statuses</option>
          <option value="good">Good Stock</option>
          <option value="low">Low Stock</option>
          <option value="out">Out of Stock</option>
          <option value="expired">Expired</option>
        </select>
        <select id="sortFilter" class="filter-sel">
          <option value="name_asc">Name A→Z</option>
          <option value="name_desc">Name Z→A</option>
          <option value="qty_asc">Stock Low→High</option>
          <option value="qty_desc">Stock High→Low</option>
          <option value="expiry_asc">Expiry Soonest</option>
        </select>
        <span class="result-count" id="resultCount"></span>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-outline btn-pdf" id="btnPdf"><i class="fas fa-file-pdf"></i> PDF</button>
        <button class="btn btn-outline btn-excel" id="btnExcel"><i class="fas fa-file-excel"></i> Excel</button>
        <button class="btn btn-primary" id="btnAdd"><i class="fas fa-plus"></i> Add Item</button>
      </div>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Item Name</th><th>Category</th><th>Stock</th><th>Status</th>
            <th>Expiry Date</th><th>Supplier</th><th>Actions</th>
          </tr>
        </thead>
        <tbody id="tbody">
          <tr><td colspan="7" style="text-align:center;padding:36px;color:#8a9a8b">
            <span class="skel" style="width:60%;height:14px;display:inline-block"> </span>
          </td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /page -->

<!-- Add/Edit Modal -->
<div class="modal" id="itemModal">
  <div class="modal-box">
    <div class="modal-head">
      <h2 id="modalTitle"><i class="fas fa-plus-circle"></i> Add New Item</h2>
      <button class="modal-close" onclick="closeModal('itemModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form id="itemForm">
        <input type="hidden" id="itemId">
        <div class="form-section">
          <h4><i class="fas fa-info-circle"></i> Basic Information</h4>
          <div class="form-grid">
            <div class="fg"><label>Item Name *</label><input id="iName" type="text" required placeholder="e.g., Paracetamol 500mg"></div>
            <div class="fg"><label>Category *</label><input id="iCat" type="text" required placeholder="e.g., Analgesics"></div>
          </div>
        </div>
        <div class="form-section">
          <h4><i class="fas fa-cubes"></i> Stock Information</h4>
          <div class="form-grid">
            <div class="fg"><label>Quantity *</label><input id="iQty" type="number" min="0" required placeholder="0"></div>
            <div class="fg"><label>Unit *</label><input id="iUnit" type="text" required placeholder="pcs / bottles / boxes"></div>
            <div class="fg"><label>Low Stock Threshold *</label><input id="iThreshold" type="number" min="0" required placeholder="10"></div>
            <div class="fg"><label>Expiry Date</label><input id="iExpiry" type="date"></div>
          </div>
        </div>
        <div class="form-section">
          <h4><i class="fas fa-truck"></i> Supply Details</h4>
          <div class="form-grid">
            <div class="fg"><label>Supplier</label><input id="iSupplier" type="text" placeholder="Supplier name"></div>
            <div class="fg"><label>Cost per Unit (UGX)</label><input id="iCost" type="number" min="0" step="0.01" placeholder="0.00"></div>
            <div class="fg"><label>Storage Location</label><input id="iLocation" type="text" placeholder="e.g., Cabinet A, Shelf 2"></div>
            <div class="fg span2"><label>Description / Notes</label><textarea id="iDesc" rows="2" placeholder="Any additional details…"></textarea></div>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-foot">
      <button class="btn btn-outline" onclick="closeModal('itemModal')">Cancel</button>
      <button class="btn btn-primary" id="btnSave"><i class="fas fa-save"></i> Save Item</button>
    </div>
  </div>
</div>

<!-- View Modal -->
<div class="modal" id="viewModal">
  <div class="modal-box">
    <div class="modal-head">
      <h2 id="viewTitle"><i class="fas fa-eye"></i> Item Details</h2>
      <button class="modal-close" onclick="closeModal('viewModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="viewBody"></div>
    <div class="modal-foot">
      <button class="btn btn-outline" onclick="closeModal('viewModal')">Close</button>
    </div>
  </div>
</div>

<!-- Delete Modal -->
<div class="modal" id="deleteModal">
  <div class="modal-box modal-sm">
    <div class="modal-head">
      <h2><i class="fas fa-trash-alt"></i> Confirm Deletion</h2>
      <button class="modal-close" onclick="closeModal('deleteModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="delete-confirm">
        <div class="delete-icon-wrap"><i class="fas fa-trash-alt"></i></div>
        <p>Are you sure you want to delete <strong id="deleteItemName">this item</strong>?</p>
        <p style="font-size:.82rem;color:var(--gray);margin-top:8px">This action cannot be undone.</p>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
      <button class="btn btn-danger" id="btnConfirmDelete"><i class="fas fa-trash"></i> Delete</button>
    </div>
  </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;

let allItems = [];
let deleteId = null;

// ── Fetch inventory ──────────────────────────────────────────────────────────
async function loadInventory() {
  try {
    const res  = await fetch('api/get_inventory.php');
    const data = await res.json();
    if (data.error) throw new Error(data.error);
    allItems = data;
    refreshUI();
  } catch(e) {
    document.getElementById('tbody').innerHTML =
      `<tr><td colspan="7"><div class="empty-state"><i class="fas fa-exclamation-circle"></i><p style="color:var(--red)">${esc(e.message)}</p></div></td></tr>`;
  }
}

function refreshUI() {
  renderHeaderPills();
  populateCategoryFilter();
  applyFilters();
}

// ── Header pills ──────────────────────────────────────────────────────────────
function renderHeaderPills() {
  const total   = allItems.length;
  const low     = allItems.filter(i=>getStatus(i)==='low').length;
  const expired = allItems.filter(i=>getStatus(i)==='expired').length;
  document.getElementById('headerPills').innerHTML = `
    <div class="stat-pill"><span class="n">${total}</span><span class="l">Total</span></div>
    <div class="stat-pill"><span class="n">${low}</span><span class="l">Low Stock</span></div>
    <div class="stat-pill"><span class="n">${expired}</span><span class="l">Expired</span></div>
  `;
}

// ── Status helper ─────────────────────────────────────────────────────────────
function getStatus(item) {
  const today = new Date(); today.setHours(0,0,0,0);
  const exp   = item.expiry_date ? new Date(item.expiry_date+'T00:00:00') : null;
  if (exp && exp < today) return 'expired';
  if (parseInt(item.quantity) === 0) return 'out';
  if (parseInt(item.quantity) <= parseInt(item.threshold)) return 'low';
  return 'good';
}
const statusLabel = {good:'Good Stock',low:'Low Stock',out:'Out of Stock',expired:'Expired'};
const statusClass = {good:'badge-good',low:'badge-low',out:'badge-out',expired:'badge-expired'};

// ── Populate category filter ──────────────────────────────────────────────────
function populateCategoryFilter() {
  const cats = [...new Set(allItems.map(i=>i.category).filter(Boolean))].sort();
  const sel  = document.getElementById('catFilter');
  const cur  = sel.value;
  sel.innerHTML = '<option value="">All Categories</option>'
    + cats.map(c=>`<option value="${esc(c)}"${c===cur?' selected':''}>${esc(c)}</option>`).join('');
}

// ── Apply filters + sort ──────────────────────────────────────────────────────
function applyFilters() {
  const q    = document.getElementById('searchInput').value.toLowerCase();
  const cat  = document.getElementById('catFilter').value;
  const st   = document.getElementById('statusFilter').value;
  const sort = document.getElementById('sortFilter').value;

  let data = allItems.filter(item => {
    const matchQ   = !q || item.item_name.toLowerCase().includes(q)
                         || (item.category||'').toLowerCase().includes(q)
                         || (item.supplier||'').toLowerCase().includes(q);
    const matchCat = !cat || item.category === cat;
    const matchSt  = !st  || getStatus(item) === st;
    return matchQ && matchCat && matchSt;
  });

  data.sort((a,b) => {
    switch(sort) {
      case 'name_desc':   return b.item_name.localeCompare(a.item_name);
      case 'qty_asc':     return parseInt(a.quantity)-parseInt(b.quantity);
      case 'qty_desc':    return parseInt(b.quantity)-parseInt(a.quantity);
      case 'expiry_asc':
        const da = a.expiry_date ? new Date(a.expiry_date) : new Date('9999');
        const db = b.expiry_date ? new Date(b.expiry_date) : new Date('9999');
        return da-db;
      default: return a.item_name.localeCompare(b.item_name);
    }
  });

  renderTable(data);
  document.getElementById('resultCount').textContent = `${data.length} item${data.length!==1?'s':''}`;
}

// ── Render table ──────────────────────────────────────────────────────────────
function renderTable(data) {
  const tbody = document.getElementById('tbody');
  if (!data.length) {
    tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state"><i class="fas fa-search"></i><p>No items match your filters.</p></div></td></tr>`;
    return;
  }
  const fmtDate = d => {
    if(!d) return '—';
    try{ return new Date(d+'T00:00:00').toLocaleDateString('en-UG',{day:'2-digit',month:'short',year:'numeric'}); }catch(_){return d;}
  };
  tbody.innerHTML = data.map(item => {
    const st = getStatus(item);
    return `<tr>
      <td><span class="item-name">${esc(item.item_name)}</span></td>
      <td>${esc(item.category||'—')}</td>
      <td>${esc(item.quantity)} <span style="color:var(--gray);font-size:.78rem">${esc(item.unit||'')}</span></td>
      <td><span class="badge ${statusClass[st]}">${statusLabel[st]}</span></td>
      <td>${fmtDate(item.expiry_date)}</td>
      <td>${esc(item.supplier||'—')}</td>
      <td class="action-cell">
        <button class="btn-icon bi-view" title="View" onclick="viewItem(${item.id})"><i class="fas fa-eye"></i></button>
        <button class="btn-icon bi-edit" title="Edit" onclick="editItem(${item.id})"><i class="fas fa-pencil-alt"></i></button>
        <button class="btn-icon bi-delete" title="Delete" onclick="openDeleteModal(${item.id})"><i class="fas fa-trash"></i></button>
      </td>
    </tr>`;
  }).join('');
}

// ── View Item ─────────────────────────────────────────────────────────────────
function viewItem(id) {
  const item = allItems.find(i=>i.id==id);
  if(!item) return;
  const st = getStatus(item);
  document.getElementById('viewTitle').innerHTML = `<i class="fas fa-eye"></i> ${esc(item.item_name)}`;
  const fmtDate = d => d ? new Date(d+'T00:00:00').toLocaleDateString('en-UG',{day:'2-digit',month:'short',year:'numeric'}) : '—';
  const fmtCost = c => c ? 'UGX '+new Intl.NumberFormat('en-UG').format(c) : '—';
  document.getElementById('viewBody').innerHTML = `
    <div class="detail-grid">
      <div class="detail-item"><div class="detail-lbl"><i class="fas fa-tags"></i> Category</div><div class="detail-val">${esc(item.category||'—')}</div></div>
      <div class="detail-item"><div class="detail-lbl"><i class="fas fa-cubes"></i> Current Stock</div><div class="detail-val">${esc(item.quantity)} ${esc(item.unit||'')}</div></div>
      <div class="detail-item"><div class="detail-lbl"><i class="fas fa-info-circle"></i> Status</div><div class="detail-val"><span class="badge ${statusClass[st]}">${statusLabel[st]}</span></div></div>
      <div class="detail-item"><div class="detail-lbl"><i class="fas fa-exclamation-triangle"></i> Threshold</div><div class="detail-val">${esc(item.threshold)} ${esc(item.unit||'')}</div></div>
      <div class="detail-item"><div class="detail-lbl"><i class="fas fa-calendar-alt"></i> Expiry Date</div><div class="detail-val">${fmtDate(item.expiry_date)}</div></div>
      <div class="detail-item"><div class="detail-lbl"><i class="fas fa-truck"></i> Supplier</div><div class="detail-val">${esc(item.supplier||'—')}</div></div>
      <div class="detail-item"><div class="detail-lbl"><i class="fas fa-coins"></i> Cost/Unit</div><div class="detail-val">${fmtCost(item.cost)}</div></div>
      <div class="detail-item"><div class="detail-lbl"><i class="fas fa-map-marker-alt"></i> Location</div><div class="detail-val">${esc(item.location||'—')}</div></div>
      <div class="detail-item full"><div class="detail-lbl"><i class="fas fa-align-left"></i> Description</div><div class="detail-val">${esc(item.description||'—')}</div></div>
    </div>`;
  openModal('viewModal');
}

// ── Add Item ──────────────────────────────────────────────────────────────────
document.getElementById('btnAdd').addEventListener('click', () => {
  document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add New Item';
  document.getElementById('itemId').value = '';
  document.getElementById('itemForm').reset();
  openModal('itemModal');
});

// ── Edit Item ─────────────────────────────────────────────────────────────────
function editItem(id) {
  const item = allItems.find(i=>i.id==id);
  if(!item) return;
  document.getElementById('modalTitle').innerHTML = '<i class="fas fa-pencil-alt"></i> Edit Item';
  document.getElementById('itemId').value    = item.id;
  document.getElementById('iName').value     = item.item_name;
  document.getElementById('iCat').value      = item.category||'';
  document.getElementById('iQty').value      = item.quantity;
  document.getElementById('iUnit').value     = item.unit||'';
  document.getElementById('iThreshold').value= item.threshold;
  document.getElementById('iExpiry').value   = item.expiry_date||'';
  document.getElementById('iSupplier').value = item.supplier||'';
  document.getElementById('iCost').value     = item.cost||'';
  document.getElementById('iLocation').value = item.location||'';
  document.getElementById('iDesc').value     = item.description||'';
  openModal('itemModal');
}

// ── Save (Add/Edit) ───────────────────────────────────────────────────────────
document.getElementById('btnSave').addEventListener('click', async () => {
  const id = document.getElementById('itemId').value;
  const body = {
    action:      id ? 'edit' : 'add',
    csrf_token:  CSRF,
    id:          id ? parseInt(id) : undefined,
    name:        document.getElementById('iName').value.trim(),
    category:    document.getElementById('iCat').value.trim(),
    quantity:    parseInt(document.getElementById('iQty').value),
    unit:        document.getElementById('iUnit').value.trim(),
    threshold:   parseInt(document.getElementById('iThreshold').value),
    expiry:      document.getElementById('iExpiry').value||null,
    supplier:    document.getElementById('iSupplier').value.trim()||null,
    cost:        parseFloat(document.getElementById('iCost').value)||null,
    location:    document.getElementById('iLocation').value.trim()||null,
    description: document.getElementById('iDesc').value.trim()||null,
  };
  if (!body.name) { notify('Validation','Item name is required.','warning'); return; }

  const btn = document.getElementById('btnSave');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
  try {
    const res    = await fetch('api/manage_inventory.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    const result = await res.json();
    if (result.status === 'success') {
      closeModal('itemModal');
      notify('Success', result.message, 'success');
      await loadInventory();
    } else {
      notify('Error', result.message, 'error');
    }
  } catch(e) {
    notify('Error', 'Request failed. Please try again.', 'error');
  } finally {
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Item';
  }
});

// ── Delete ────────────────────────────────────────────────────────────────────
function openDeleteModal(id) {
  deleteId = id;
  const item = allItems.find(i=>i.id==id);
  document.getElementById('deleteItemName').textContent = item ? item.item_name : 'this item';
  openModal('deleteModal');
}
document.getElementById('btnConfirmDelete').addEventListener('click', async () => {
  if (!deleteId) return;
  const btn = document.getElementById('btnConfirmDelete');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting…';
  try {
    const res    = await fetch('api/manage_inventory.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',csrf_token:CSRF,id:deleteId})});
    const result = await res.json();
    if (result.status === 'success') {
      closeModal('deleteModal');
      notify('Deleted', result.message, 'success');
      await loadInventory();
    } else {
      notify('Error', result.message, 'error');
    }
  } catch(e) {
    notify('Error', 'Request failed.', 'error');
  } finally {
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-trash"></i> Delete';
    deleteId = null;
  }
});

// ── Export ────────────────────────────────────────────────────────────────────
document.getElementById('btnPdf').addEventListener('click', () => {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF('landscape');
  doc.setFontSize(16); doc.setTextColor(27,94,32);
  doc.text('Sickbay Inventory Report', 14, 18);
  doc.setFontSize(9); doc.setTextColor(100);
  doc.text('Generated: '+new Date().toLocaleDateString('en-UG'), 14, 25);
  doc.autoTable({
    head:[['Name','Category','Stock','Status','Expiry','Supplier']],
    body: allItems.map(i=>[i.item_name,i.category,i.quantity+' '+i.unit,statusLabel[getStatus(i)],i.expiry_date||'N/A',i.supplier||'N/A']),
    startY:30,theme:'grid',headStyles:{fillColor:[56,142,60],fontSize:8},bodyStyles:{fontSize:7.5}
  });
  doc.save('inventory-'+datestamp()+'.pdf');
  notify('Exported','PDF downloaded.','success');
});
document.getElementById('btnExcel').addEventListener('click', () => {
  const data = [['Item Name','Category','Quantity','Unit','Status','Threshold','Expiry','Supplier','Cost','Location','Description']];
  allItems.forEach(i=>data.push([i.item_name,i.category,i.quantity,i.unit,statusLabel[getStatus(i)],i.threshold,i.expiry_date||'',i.supplier||'',i.cost||'',i.location||'',i.description||'']));
  const wb = XLSX.utils.book_new();
  const ws = XLSX.utils.aoa_to_sheet(data);
  XLSX.utils.book_append_sheet(wb, ws, 'Inventory');
  XLSX.writeFile(wb, 'inventory-'+datestamp()+'.xlsx');
  notify('Exported','Excel downloaded.','success');
});

// ── Filter listeners ──────────────────────────────────────────────────────────
['searchInput','catFilter','statusFilter','sortFilter'].forEach(id => {
  document.getElementById(id).addEventListener(id==='searchInput'?'input':'change', applyFilters);
});

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

// ── Utilities ─────────────────────────────────────────────────────────────────
function esc(v){ return String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function datestamp(){ return new Date().toISOString().split('T')[0]; }

// ── Init ──────────────────────────────────────────────────────────────────────
loadInventory();
</script>
</body>
</html>
