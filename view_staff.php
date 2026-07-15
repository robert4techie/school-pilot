<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction('View Staff Information');

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
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Staff Registry &mdash; School Pilot</title>
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
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-pdf{background:#c62828;color:#fff}.btn-pdf:hover{background:var(--red)}
.btn-excel{background:var(--g800);color:#fff}.btn-excel:hover{background:var(--g900)}

/* ── Icon Buttons (table actions) ───────────────────────── */
.action-cell{display:flex;gap:5px;align-items:center}
.btn-icon{width:30px;height:30px;border:none;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;transition:all var(--transition);flex-shrink:0}
.btn-icon:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.18)}
.bi-view{background:#e3f2fd;color:#1565c0}.bi-view:hover{background:#1565c0;color:#fff}
.bi-edit{background:#fff3e0;color:#e65100}.bi-edit:hover{background:#e65100;color:#fff}
.bi-toggle{background:#e8f5e9;color:var(--g700)}.bi-toggle:hover{background:var(--g700);color:#fff}
.bi-delete{background:#ffebee;color:var(--red)}.bi-delete:hover{background:var(--red);color:#fff}

/* ── Table ──────────────────────────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:13px 14px;text-align:left;font-size:.8rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--transition)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:13px 14px;font-size:.875rem;vertical-align:middle}
.staff-avatar{width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid var(--g400);display:block}
.staff-name{font-weight:600;color:var(--g800)}

/* ── Status Badges ──────────────────────────────────────── */
.badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.badge-active{background:#e8f5e9;color:#2e7d32}
.badge-inactive{background:#ffebee;color:#c62828}

/* ── Skeleton Rows ──────────────────────────────────────── */
.skeleton-cell{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;height:14px;display:inline-block;width:80%}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ── Empty State ────────────────────────────────────────── */
.empty-state{text-align:center;padding:60px 20px;color:#8a9a8b}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;opacity:.45}
.empty-state p{font-size:.95rem}

/* ── Pagination ─────────────────────────────────────────── */
.pagination{padding:16px 24px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e8ede9;flex-wrap:wrap;gap:10px}
.page-info{font-size:.82rem;color:#6b7c6d}
.page-btns{display:flex;gap:4px}
.page-btn{width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:6px;background:#fff;cursor:pointer;font-size:.82rem;font-weight:600;color:#444;display:flex;align-items:center;justify-content:center;transition:all var(--transition)}
.page-btn:hover:not(:disabled){border-color:var(--g600);background:var(--g100);color:var(--g800)}
.page-btn.active{background:var(--g700);border-color:var(--g700);color:#fff}
.page-btn:disabled{opacity:.38;cursor:default}

/* ── Modal Overlay ──────────────────────────────────────── */
.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);animation:fadeOverlay .2s ease}
@keyframes fadeOverlay{from{opacity:0}to{opacity:1}}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:820px;box-shadow:var(--shadow-lg);animation:slideDown .25s ease;margin:auto}
@keyframes slideDown{from{transform:translateY(-24px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%);padding:20px 24px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head h2{color:#fff;font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background var(--transition)}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:28px 28px 24px}

/* ── Section Title ──────────────────────────────────────── */
.form-section{margin-bottom:22px}
.form-section-title{font-size:.8rem;font-weight:700;color:var(--g700);text-transform:uppercase;letter-spacing:.6px;margin-bottom:14px;padding-bottom:7px;border-bottom:2px solid var(--g100);display:flex;align-items:center;gap:8px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-grid .full{grid-column:1/-1}

/* ── Form Controls ──────────────────────────────────────── */
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group label{font-size:.8rem;font-weight:600;color:#3a4a3b}
.form-group label .req{color:var(--red)}
.form-control{padding:9px 13px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;width:100%;transition:border-color var(--transition),box-shadow var(--transition);background:#fff;font-family:inherit}
.form-control:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.1)}
.form-control[readonly]{background:#f5f8f5;color:#555;cursor:default}

/* ── Photo Upload ───────────────────────────────────────── */
.photo-row{display:flex;align-items:center;gap:16px}
.photo-preview{width:72px;height:72px;border-radius:50%;object-fit:cover;border:3px solid var(--g400)}
.photo-upload-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border:1.5px dashed var(--g400);border-radius:var(--radius);cursor:pointer;font-size:.82rem;color:var(--g700);transition:all var(--transition)}
.photo-upload-btn:hover{background:var(--g100);border-color:var(--g700)}
.photo-upload-btn input{display:none}

/* ── Form Actions ───────────────────────────────────────── */
.form-actions{display:flex;gap:12px;justify-content:flex-end;padding-top:20px;border-top:1px solid #eef2ee;margin-top:20px}

/* ── View Modal Layout ──────────────────────────────────── */
.view-profile{display:flex;gap:22px;align-items:flex-start;margin-bottom:24px}
.view-avatar{width:100px;height:100px;border-radius:var(--radius);object-fit:cover;border:3px solid var(--g400);flex-shrink:0}
.view-meta h3{font-size:1.25rem;font-weight:700;color:var(--g800);margin-bottom:4px}
.view-meta p{font-size:.85rem;color:#6b7c6d}
.view-meta .badge{margin-top:8px}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid #e8ede9;border-radius:var(--radius)}
.detail-row{display:contents}
.detail-row:not(:last-child) .d-label,.detail-row:not(:last-child) .d-val{border-bottom:1px solid #e8ede9}
.d-label{padding:10px 14px;font-size:.75rem;font-weight:700;color:var(--g700);text-transform:uppercase;letter-spacing:.4px;background:#f5fbf5;border-right:1px solid #e8ede9}
.d-val{padding:10px 14px;font-size:.875rem;color:#333}

/* ── Confirm Dialog ─────────────────────────────────────── */
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
  .form-grid{grid-template-columns:1fr}.toolbar{flex-direction:column;align-items:stretch}
  .toolbar-right{flex-wrap:wrap}.page-header{flex-direction:column}
  .view-profile{flex-direction:column}.detail-grid{grid-template-columns:1fr}
  .d-label{border-right:none}.stats-row{gap:8px}
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
      <h1><i class="fas fa-users-cog" style="margin-right:10px;opacity:.85"></i>Staff Registry</h1>
      <p>Manage, search and export all staff members</p>
    </div>
    <div class="stats-row">
      <div class="stat-pill"><span class="n" id="sAll">—</span><span class="l">Total</span></div>
      <div class="stat-pill"><span class="n" id="sActive">—</span><span class="l">Active</span></div>
      <div class="stat-pill"><span class="n" id="sInactive">—</span><span class="l">Inactive</span></div>
    </div>
  </div>

  <!-- Main Card -->
  <div class="card">

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search name, ID or email…" autocomplete="off">
        </div>
        <select id="deptFilter"   class="filter-select"><option value="">All Departments</option></select>
        <select id="genderFilter" class="filter-select">
          <option value="">All Genders</option>
          <option value="male">Male</option>
          <option value="female">Female</option>
        </select>
        <select id="statusFilter" class="filter-select">
          <option value="">All Statuses</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
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
            <th style="width:50px"></th>
            <th>Staff ID</th>
            <th>Name</th>
            <th>Gender</th>
            <th>Designation</th>
            <th>Department</th>
            <th>Phone</th>
            <th>Status</th>
            <th style="width:130px">Actions</th>
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

<!-- ══ VIEW MODAL ══════════════════════════════════════════ -->
<div id="viewModal" class="modal" onclick="modalOutsideClick(event,'viewModal')">
  <div class="modal-box" style="max-width:700px">
    <div class="modal-head">
      <h2><i class="fas fa-id-card"></i> Staff Profile</h2>
      <button class="modal-close" onclick="closeModal('viewModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="viewBody"></div>
  </div>
</div>

<!-- ══ EDIT MODAL ══════════════════════════════════════════ -->
<div id="editModal" class="modal" onclick="modalOutsideClick(event,'editModal')">
  <div class="modal-box">
    <div class="modal-head">
      <h2 id="editModalTitle"><i class="fas fa-pen"></i> Edit Staff</h2>
      <button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form id="editForm" enctype="multipart/form-data">

        <!-- ── Personal Information ── -->
        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-circle-user"></i> Personal Information</div>
          <div class="form-grid">
            <div class="form-group">
              <label>First Name <span class="req">*</span></label>
              <input type="text" id="f_first_name" class="form-control" placeholder="e.g. Sarah" required>
            </div>
            <div class="form-group">
              <label>Last Name <span class="req">*</span></label>
              <input type="text" id="f_last_name" class="form-control" placeholder="e.g. Nakamatte" required>
            </div>
            <div class="form-group">
              <label>Date of Birth <span style="font-weight:400;color:#8a9a8b;font-size:.75rem">(optional)</span></label>
              <input type="date" id="f_date_of_birth" class="form-control">
            </div>
            <div class="form-group">
              <label>Gender <span class="req">*</span></label>
              <select id="f_gender" class="form-control" required>
                <option value="">Select gender&hellip;</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
              </select>
            </div>
            <div class="form-group">
              <label>Phone <span class="req">*</span></label>
              <input type="tel" id="f_phone" class="form-control" placeholder="+256 700 000000" required>
            </div>
            <div class="form-group">
              <label>Marital Status <span style="font-weight:400;color:#8a9a8b;font-size:.75rem">(optional)</span></label>
              <select id="f_marital_status" class="form-control">
                <option value="">Select&hellip;</option>
                <option value="single">Single</option>
                <option value="married">Married</option>
                <option value="divorced">Divorced</option>
                <option value="widowed">Widowed</option>
              </select>
            </div>
            <div class="form-group">
              <label>Nationality <span class="req">*</span></label>
              <input type="text" id="f_nationality" class="form-control" placeholder="e.g. Ugandan" required>
            </div>
            <div class="form-group">
              <label>Email <span class="req">*</span></label>
              <input type="email" id="f_email" class="form-control" placeholder="e.g. staff@email.com" required>
            </div>
            <div class="form-group full">
              <label>Residential Address <span class="req">*</span></label>
              <textarea id="f_address" class="form-control" rows="2" placeholder="Street / Village, District, Region&hellip;" style="resize:vertical;min-height:64px" required></textarea>
            </div>
            <div class="form-group full">
              <label>Profile Photo <span style="font-weight:400;color:#8a9a8b;font-size:.75rem">(optional &mdash; max 2 MB, JPEG/PNG/WebP)</span></label>
              <div class="photo-row">
                <img id="photoPreview" src="" class="photo-preview" alt="Preview"
                     onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Ccircle cx=%2250%22 cy=%2250%22 r=%2250%22 fill=%22%234caf50%22/%3E%3Ccircle cx=%2250%22 cy=%2238%22 r=%2218%22 fill=%22white%22/%3E%3Cellipse cx=%2250%22 cy=%2285%22 rx=%2232%22 ry=%2222%22 fill=%22white%22/%3E%3C/svg%3E'">
                <label class="photo-upload-btn">
                  <i class="fas fa-camera"></i> Choose Photo
                  <input type="file" id="f_photo" accept="image/jpeg,image/png,image/webp" onchange="previewPhoto(this)">
                </label>
                <small style="color:#8a9a8b;font-size:.75rem">JPEG, PNG or WebP &middot; max 2 MB</small>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Professional Information ── -->
        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-briefcase"></i> Professional Information</div>
          <div class="form-grid">
            <div class="form-group">
              <label>Designation <span class="req">*</span></label>
              <select id="f_designation" class="form-control" required>
                <option value="">Select designation&hellip;</option>
                <option value="Teacher">Teacher</option>
                <option value="Principal">Principal</option>
                <option value="Head Teacher">Head Teacher</option>
                <option value="Deputy Principal">Deputy Principal</option>
                <option value="Deputy Head Teacher">Deputy Head Teacher</option>
                <option value="Head of Department">Head of Department</option>
                <option value="Head of Studies">Head of Studies</option>
                <option value="Head of Studies A Level">Head of Studies A Level</option>
                <option value="Head of Studies O Level">Head of Studies O Level</option>
                <option value="Admin">Administrator</option>
                <option value="Librarian">Librarian</option>
                <option value="Accountant">Accountant</option>
                <option value="Secretary">Secretary</option>
                <option value="Support Staff">Support Staff</option>
              </select>
            </div>
            <div class="form-group">
              <label>Department <span class="req">*</span></label>
              <select id="f_department" class="form-control" required>
                <option value="">Select department&hellip;</option>
                <option value="Science">Science</option>
                <option value="Arts">Arts</option>
                <option value="IT">Information Technology</option>
                <option value="Administration">Administration</option>
                <option value="Support">Support Services</option>
              </select>
            </div>
            <div class="form-group">
              <label>Joining Date <span class="req">*</span></label>
              <input type="date" id="f_joining_date" class="form-control" required>
            </div>
            <div class="form-group">
              <label>Employment Type <span class="req">*</span></label>
              <select id="f_employment_type" class="form-control" required>
                <option value="">Select type&hellip;</option>
                <option value="full_time">Full-time</option>
                <option value="part_time">Part-time</option>
                <option value="contract">Contract</option>
                <option value="temporary">Temporary</option>
              </select>
            </div>
            <div class="form-group">
              <label>Qualifications <span class="req">*</span></label>
              <select id="f_qualifications" class="form-control" required>
                <option value="">Select qualifications&hellip;</option>
                <option value="Certificate">Certificate</option>
                <option value="Diploma Arts">Diploma Arts</option>
                <option value="Diploma Sciences">Diploma Sciences</option>
                <option value="Degree Arts">Degree Arts</option>
                <option value="Degree Sciences">Degree Sciences</option>
                <option value="Postgraduate Diploma">Postgraduate Diploma</option>
                <option value="Masters">Masters</option>
                <option value="PhD">PhD</option>
              </select>
            </div>
            <div class="form-group">
              <label>Experience <span style="font-weight:400;color:#8a9a8b;font-size:.75rem">(years, optional)</span></label>
              <input type="number" id="f_experience" class="form-control" min="0" max="60" placeholder="e.g. 5">
            </div>
          </div>
        </div>

        <!-- ── Official Documents ── -->
        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-id-card"></i> Official Documents</div>
          <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr">
            <div class="form-group">
              <label>National ID (NIN) <span class="req">*</span></label>
              <input type="text" id="f_nin" class="form-control" placeholder="e.g. CM90100658KDEU" required>
            </div>
            <div class="form-group">
              <label>TIN Number <span style="font-weight:400;color:#8a9a8b;font-size:.75rem">(optional)</span></label>
              <input type="text" id="f_tin" class="form-control" placeholder="e.g. 1234567890">
            </div>
            <div class="form-group">
              <label>NSSF Number <span style="font-weight:400;color:#8a9a8b;font-size:.75rem">(optional)</span></label>
              <input type="text" id="f_nssf" class="form-control" placeholder="e.g. 1234567890">
            </div>
          </div>
        </div>

        <div class="form-actions">
          <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
          <button type="submit" class="btn btn-primary" id="saveBtn">
            <i class="fas fa-save"></i> <span id="saveBtnLabel">Save Changes</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ CONFIRM DIALOG ══════════════════════════════════════ -->
<div id="confirmDlg" class="dialog">
  <div class="dialog-box">
    <div class="dialog-head danger" id="dlgHead">
      <i class="fas fa-exclamation-triangle" id="dlgIcon"></i>
      <h3 id="dlgTitle">Confirm</h3>
    </div>
    <div class="dialog-body">
      <p id="dlgMsg">Are you sure?</p>
      <div class="dialog-actions">
        <button class="btn btn-outline" onclick="closeDlg()">Cancel</button>
        <button class="btn" id="dlgConfirmBtn" onclick="runDlgCallback()">Confirm</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ SCRIPT ══════════════════════════════════════════════ -->
<script>
const CSRF = '<?= htmlspecialchars($csrf, ENT_QUOTES) ?>';
const DEFAULT_AVATAR = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ccircle cx='50' cy='50' r='50' fill='%234caf50'/%3E%3Ccircle cx='50' cy='38' r='18' fill='white'/%3E%3Cellipse cx='50' cy='85' rx='32' ry='22' fill='white'/%3E%3C/svg%3E";

// ── State ────────────────────────────────────────────────
let allStaff = [], filtered = [], dlgCb = null;
let page = 1;
const PER_PAGE = 25;

// ── Bootstrap ────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    loadStaff();

    // Search debounce
    let t;
    document.getElementById('searchInput').addEventListener('input', () => { clearTimeout(t); t = setTimeout(applyFilters, 280); });

    // Filter selects
    ['deptFilter','genderFilter','statusFilter'].forEach(id => {
        document.getElementById(id).addEventListener('change', applyFilters);
    });

    document.getElementById('clearFiltersBtn').addEventListener('click', clearFilters);

    // Table row delegation
    document.getElementById('tBody').addEventListener('click', handleTableClick);

    // Form submit
    document.getElementById('editForm').addEventListener('submit', saveStaff);

    // Keyboard ESC
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAllModals(); });
});

// ── Load Data ────────────────────────────────────────────
function loadStaff() {
    showSkeleton();
    fetch('api/fetch_staff_information.php')
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(d => {
            if (!d.success) throw new Error(d.error || 'Failed to load staff.');
            allStaff = (d.staff || []).map(s => ({
                ...s,
                id:              parseInt(s.id),
                staff_id:        s.staff_id        || ('STF' + s.id),
                first_name:      s.first_name      || '',
                last_name:       s.last_name       || '',
                date_of_birth:   s.date_of_birth   || '',
                gender:          s.gender          || '',
                phone_number:    s.phone_number    || '',
                marital_status:  s.marital_status  || '',
                nationality:     s.nationality     || '',
                email:           s.email           || '',
                address:         s.address         || '',
                designation:     s.designation     || '',
                department:      s.department      || '',
                joining_date:    s.joining_date    || '',
                employment_type: s.employment_type || '',
                qualifications:  s.qualifications  || '',
                experience:      s.experience      || '0',
                nin:             s.nin             || '',
                tin:             s.tin             || '',
                nssf:            s.nssf            || '',
                profile_photo:   s.profile_photo   || '',
                Status:          s.Status          || 'inactive',
            }));
            populateFilterOptions();
            applyFilters();
            updateStats();
        })
        .catch(err => notify('Load Error', err.message, 'error'))
        .finally(hideSkeleton);
}

function showSkeleton() {
    const cols = 10;
    const rows = Array.from({length: 6}, () =>
        `<tr>${Array.from({length:cols}, () => `<td><span class="skeleton-cell" style="width:${50+Math.random()*40}%"></span></td>`).join('')}</tr>`
    ).join('');
    document.getElementById('tBody').innerHTML = rows;
    document.getElementById('pageInfo').textContent = '';
    document.getElementById('pageBtns').innerHTML = '';
}
function hideSkeleton() { /* renderTable replaces skeleton */ }

// ── Filter Population ────────────────────────────────────
function populateFilterOptions() {
    const depts = [...new Set(allStaff.map(s => s.department).filter(Boolean))].sort();
    fillSelect('deptFilter', depts, 'All Departments');
}

function fillSelect(id, values, placeholder) {
    const sel = document.getElementById(id);
    const cur = sel.value;
    sel.innerHTML = `<option value="">${placeholder}</option>` +
        values.map(v => `<option value="${esc(v)}" ${v===cur?'selected':''}>${esc(v)}</option>`).join('');
}

// ── Filters & Search ─────────────────────────────────────
function applyFilters() {
    const q   = document.getElementById('searchInput').value.trim().toLowerCase();
    const dep = document.getElementById('deptFilter').value;
    const gen = document.getElementById('genderFilter').value;
    const sta = document.getElementById('statusFilter').value;

    filtered = allStaff.filter(s => {
        if (q) {
            const full = (s.first_name + ' ' + s.last_name).toLowerCase();
            const rev  = (s.last_name  + ' ' + s.first_name).toLowerCase();
            if (!full.includes(q) && !rev.includes(q) &&
                !s.staff_id.toLowerCase().includes(q) &&
                !s.email.toLowerCase().includes(q)) return false;
        }
        if (dep && s.department !== dep) return false;
        if (gen && s.gender     !== gen) return false;
        if (sta && s.Status     !== sta) return false;
        return true;
    });

    page = 1;
    renderTable();
    renderPagination();
    document.getElementById('resultCount').textContent =
        filtered.length < allStaff.length
            ? `${filtered.length} of ${allStaff.length} staff`
            : `${allStaff.length} staff`;
}

function clearFilters() {
    document.getElementById('searchInput').value = '';
    ['deptFilter','genderFilter','statusFilter'].forEach(id => {
        document.getElementById(id).value = '';
    });
    applyFilters();
    notify('Filters Cleared', 'All filters have been reset.', 'info', 2500);
}

// ── Render Table ─────────────────────────────────────────
function renderTable() {
    const tbody = document.getElementById('tBody');
    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="10"><div class="empty-state">
            <i class="fas fa-users-cog"></i>
            <p>${allStaff.length === 0 ? 'No staff members have been added yet.' : 'No staff match your search or filters.'}</p>
        </div></td></tr>`;
        return;
    }

    const start = (page - 1) * PER_PAGE;
    const slice = filtered.slice(start, start + PER_PAGE);

    tbody.innerHTML = slice.map((s, i) => {
        const avatar   = s.profile_photo || DEFAULT_AVATAR;
        const fullName = esc(s.first_name) + ' ' + esc(s.last_name);
        const genderDisplay = s.gender ? ucf(s.gender) : '—';
        return `<tr>
          <td style="color:#9aaa9b;font-size:.78rem">${start + i + 1}</td>
          <td><img src="${avatar}" class="staff-avatar" onerror="this.src='${DEFAULT_AVATAR}'" alt="${fullName}"></td>
          <td style="font-size:.8rem;color:#5a6a5b;font-weight:600">${esc(s.staff_id)}</td>
          <td class="staff-name">${fullName}</td>
          <td>${genderDisplay}</td>
          <td>${esc(s.designation)}</td>
          <td>${esc(s.department)}</td>
          <td style="font-size:.82rem">${esc(s.phone_number)}</td>
          <td><span class="badge badge-${s.Status}">${ucf(s.Status)}</span></td>
          <td>
            <div class="action-cell">
              <button class="btn-icon bi-view"   title="View profile"  data-action="view"   data-id="${s.id}"><i class="fas fa-eye"></i></button>
              <button class="btn-icon bi-edit"   title="Edit staff"    data-action="edit"   data-id="${s.id}"><i class="fas fa-pen"></i></button>
              <button class="btn-icon bi-toggle" title="Toggle status" data-action="toggle" data-id="${s.id}"><i class="fas fa-exchange-alt"></i></button>
              <button class="btn-icon bi-delete" title="Delete staff"  data-action="delete" data-id="${s.id}"><i class="fas fa-trash"></i></button>
            </div>
          </td>
        </tr>`;
    }).join('');
}

// ── Pagination ───────────────────────────────────────────
function renderPagination() {
    const total = filtered.length;
    const pages = Math.ceil(total / PER_PAGE);
    const start = total === 0 ? 0 : (page - 1) * PER_PAGE + 1;
    const end   = Math.min(page * PER_PAGE, total);

    document.getElementById('pageInfo').textContent =
        total > 0 ? `Showing ${start}–${end} of ${total}` : '';

    const btns = document.getElementById('pageBtns');
    if (pages <= 1) { btns.innerHTML = ''; return; }

    let html = `<button class="page-btn" onclick="goPage(${page-1})" ${page===1?'disabled':''}><i class="fas fa-chevron-left"></i></button>`;
    for (let p2 = 1; p2 <= pages; p2++) {
        if (pages > 7 && Math.abs(p2 - page) > 2 && p2 !== 1 && p2 !== pages) {
            if (p2 === 2 || p2 === pages - 1) html += `<button class="page-btn" disabled style="border:none;cursor:default">…</button>`;
            continue;
        }
        html += `<button class="page-btn ${p2===page?'active':''}" onclick="goPage(${p2})">${p2}</button>`;
    }
    html += `<button class="page-btn" onclick="goPage(${page+1})" ${page===pages?'disabled':''}><i class="fas fa-chevron-right"></i></button>`;
    btns.innerHTML = html;
}

function goPage(p) {
    const pages = Math.ceil(filtered.length / PER_PAGE);
    if (p < 1 || p > pages) return;
    page = p;
    renderTable();
    renderPagination();
    window.scrollTo({top:0, behavior:'smooth'});
}

// ── Stats ────────────────────────────────────────────────
function updateStats() {
    const counts = {active: 0, inactive: 0};
    allStaff.forEach(s => { if (counts[s.Status] !== undefined) counts[s.Status]++; });
    document.getElementById('sAll').textContent      = allStaff.length;
    document.getElementById('sActive').textContent   = counts.active;
    document.getElementById('sInactive').textContent = counts.inactive;
}

// ── Table Delegation ─────────────────────────────────────
function handleTableClick(e) {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    const action = btn.dataset.action;
    const id     = parseInt(btn.dataset.id);
    const s      = findById(id);
    if (!s) return;
    if      (action === 'view')   openViewModal(s);
    else if (action === 'edit')   openEditModal(s);
    else if (action === 'toggle') promptToggle(s);
    else if (action === 'delete') promptDelete(s);
}

function findById(id) { return allStaff.find(s => s.id === id) || null; }

// ── View Modal ───────────────────────────────────────────
function openViewModal(s) {
    const avatar = s.profile_photo || DEFAULT_AVATAR;
    const row = (label, val) => val
        ? `<div class="detail-row"><div class="d-label">${label}</div><div class="d-val">${esc(String(val))}</div></div>` : '';
    const empType = s.employment_type
        ? s.employment_type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) : '';

    document.getElementById('viewBody').innerHTML = `
      <div class="view-profile">
        <img src="${avatar}" class="view-avatar" onerror="this.src='${DEFAULT_AVATAR}'" alt="Photo">
        <div class="view-meta">
          <h3>${esc(s.first_name)} ${esc(s.last_name)}</h3>
          <p style="font-weight:600;color:#6b7c6d">${esc(s.staff_id)}</p>
          <p style="margin-top:4px">${esc(s.designation)}${s.department ? ' &mdash; ' + esc(s.department) : ''}</p>
          <span class="badge badge-${s.Status}" style="margin-top:8px;display:inline-block">${ucf(s.Status)}</span>
        </div>
      </div>

      <div class="form-section-title" style="margin-bottom:12px"><i class="fas fa-address-card"></i> Contact Details</div>
      <div class="detail-grid" style="margin-bottom:20px">
        ${row('Email', s.email)}
        ${row('Phone', s.phone_number)}
        ${row('Gender', ucf(s.gender))}
        ${row('Joining Date', fmtDate(s.joining_date))}
      </div>

      <div class="form-section-title" style="margin-bottom:12px"><i class="fas fa-briefcase"></i> Professional Details</div>
      <div class="detail-grid">
        ${row('Designation', s.designation)}
        ${row('Department', s.department)}
        ${row('Employment Type', empType)}
        ${row('Experience', s.experience ? s.experience + ' years' : '')}
        ${row('Qualifications', s.qualifications)}
      </div>
    `;
    openModal('viewModal');
}

// ── Edit Modal ───────────────────────────────────────────
function openEditModal(s) {
    document.getElementById('editModalTitle').innerHTML = '<i class="fas fa-pen"></i> Edit Staff';
    document.getElementById('saveBtnLabel').textContent = 'Save Changes';
    fillForm(s);
    openModal('editModal');
}

function fillForm(s) {
    const v = (id, val) => { const el = document.getElementById(id); if (el) el.value = val || ''; };
    // Personal
    v('f_first_name',    s.first_name);
    v('f_last_name',     s.last_name);
    v('f_date_of_birth', s.date_of_birth);
    v('f_gender',        s.gender);
    v('f_phone',         s.phone_number);
    v('f_marital_status',s.marital_status);
    v('f_nationality',   s.nationality);
    v('f_email',         s.email);
    v('f_address',       s.address);
    // Professional
    v('f_designation',    s.designation);
    v('f_department',     s.department);
    v('f_joining_date',   s.joining_date);
    v('f_employment_type',s.employment_type);
    v('f_qualifications', s.qualifications);
    v('f_experience',     s.experience);
    // Documents
    v('f_nin',  s.nin);
    v('f_tin',  s.tin);
    v('f_nssf', s.nssf);
    // Photo
    document.getElementById('photoPreview').src = s.profile_photo || DEFAULT_AVATAR;
    // Store the staff numeric ID for the submit handler
    document.getElementById('editForm').dataset.staffId = s.id;
}

function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { document.getElementById('photoPreview').src = e.target.result; };
        reader.readAsDataURL(input.files[0]);
    }
}

// ── Save Staff (form submit) ──────────────────────────────
function saveStaff(e) {
    e.preventDefault();
    const btn    = document.getElementById('saveBtn');
    const staffId = parseInt(document.getElementById('editForm').dataset.staffId);
    const orig   = findById(staffId);
    if (!orig) { notify('Error', 'Staff member not found.', 'error'); return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    const staff = {
        id:              staffId,
        // Personal
        first_name:      document.getElementById('f_first_name').value.trim(),
        last_name:       document.getElementById('f_last_name').value.trim(),
        date_of_birth:   document.getElementById('f_date_of_birth').value.trim(),
        gender:          document.getElementById('f_gender').value.trim(),
        phone_number:    document.getElementById('f_phone').value.trim(),
        marital_status:  document.getElementById('f_marital_status').value.trim(),
        nationality:     document.getElementById('f_nationality').value.trim(),
        email:           document.getElementById('f_email').value.trim(),
        address:         document.getElementById('f_address').value.trim(),
        // Professional
        designation:     document.getElementById('f_designation').value.trim(),
        department:      document.getElementById('f_department').value.trim(),
        joining_date:    document.getElementById('f_joining_date').value.trim(),
        employment_type: document.getElementById('f_employment_type').value.trim(),
        qualifications:  document.getElementById('f_qualifications').value.trim(),
        experience:      document.getElementById('f_experience').value.trim(),
        // Documents
        nin:             document.getElementById('f_nin').value.trim(),
        tin:             document.getElementById('f_tin').value.trim(),
        nssf:            document.getElementById('f_nssf').value.trim(),
        profile_photo:   orig.profile_photo, // default to existing; overridden by upload in PHP
    };

    const fd = new FormData();
    fd.append('action',     'update');
    fd.append('csrf_token', CSRF);
    fd.append('staff',      JSON.stringify(staff));
    const photo = document.getElementById('f_photo').files[0];
    if (photo) fd.append('profile_photo', photo);

    fetch('api/save_staff.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.success) throw new Error(d.error || 'Save failed.');
            closeModal('editModal');
            notify('Success', d.message, 'success');
            loadStaff();
        })
        .catch(err => notify('Save Error', err.message, 'error'))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> <span id="saveBtnLabel">Save Changes</span>';
        });
}

// ── Delete ───────────────────────────────────────────────
function promptDelete(s) {
    showDlg('danger', 'fas fa-trash', 'Delete Staff Member',
        `Delete <strong>${esc(s.first_name)} ${esc(s.last_name)}</strong> (${esc(s.staff_id)})? This cannot be undone.`,
        'btn-pdf', 'Delete', () => doDelete(s.id));
}
function doDelete(id) {
    fetch('api/save_staff.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'delete', id, csrf_token: CSRF })
    })
    .then(r => r.json())
    .then(d => {
        if (!d.success) throw new Error(d.error);
        notify('Deleted', d.message, 'success');
        loadStaff();
    })
    .catch(err => notify('Error', err.message, 'error'));
}

// ── Toggle Status ─────────────────────────────────────────
function promptToggle(s) {
    const newStatus = s.Status === 'active' ? 'inactive' : 'active';
    const actionText = newStatus === 'active' ? 'Activate' : 'Deactivate';
    showDlg('info', 'fas fa-exchange-alt', `${actionText} Staff Member`,
        `Current status: <strong>${ucf(s.Status)}</strong>. Change to <strong>${ucf(newStatus)}</strong>?`,
        'btn-primary', actionText, () => doToggle(s.id, newStatus));
}
function doToggle(id, status) {
    fetch('api/save_staff.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'toggle_status', id, status, csrf_token: CSRF })
    })
    .then(r => r.json())
    .then(d => {
        if (!d.success) throw new Error(d.error);
        notify('Updated', d.message, 'success');
        loadStaff();
    })
    .catch(err => notify('Error', err.message, 'error'));
}

// ── Confirm Dialog ────────────────────────────────────────
function showDlg(type, icon, title, msg, confirmClass, confirmLabel, cb, extraButtons) {
    document.getElementById('dlgHead').className = `dialog-head ${type}`;
    document.getElementById('dlgIcon').className = icon;
    document.getElementById('dlgTitle').textContent = title;
    document.getElementById('dlgMsg').innerHTML = msg;
    dlgCb = cb;

    const confirmBtn = document.getElementById('dlgConfirmBtn');
    if (confirmLabel && cb) {
        confirmBtn.style.display = '';
        confirmBtn.textContent   = confirmLabel;
        confirmBtn.className     = `btn ${confirmClass || 'btn-primary'}`;
    } else {
        confirmBtn.style.display = 'none';
    }

    const actions = document.querySelector('.dialog-actions');
    actions.querySelectorAll('.extra-btn-group').forEach(el => el.remove());
    if (extraButtons) {
        const g = document.createElement('div');
        g.className = 'extra-btn-group';
        g.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;justify-content:center;width:100%';
        g.innerHTML = extraButtons;
        actions.appendChild(g);
    }

    document.getElementById('confirmDlg').classList.add('active');
}
function closeDlg() {
    document.getElementById('confirmDlg').classList.remove('active');
    dlgCb = null;
}
function runDlgCallback() { if (dlgCb) { dlgCb(); } closeDlg(); }

// ── Modal Helpers ─────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
function closeAllModals() {
    document.querySelectorAll('.modal.active,.dialog.active').forEach(m => m.classList.remove('active'));
}
function modalOutsideClick(e, id) { if (e.target.id === id) closeModal(id); }

// ── Notifications ─────────────────────────────────────────
function notify(title, msg, type = 'success', dur = 4000) {
    const icons = {success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
    const n = document.createElement('div');
    n.className = `notif ${type}`;
    n.innerHTML = `
      <i class="fas ${icons[type]||icons.info} notif-icon"></i>
      <div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div>
      <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('notif-stack').prepend(n);
    setTimeout(() => { n.style.opacity='0'; n.style.transform='translateX(30px)'; n.style.transition='.3s'; setTimeout(()=>n.remove(),300); }, dur);
}

// ── Export PDF ────────────────────────────────────────────
function exportToPDF() {
    if (!filtered.length) { notify('Empty', 'No staff to export.', 'warning'); return; }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');
    doc.setFontSize(16); doc.setTextColor(46,125,50);
    doc.text('Staff Registry Report', 14, 18);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text('Generated: ' + new Date().toLocaleDateString('en-UG'), 14, 25);
    doc.autoTable({
        head: [['Staff ID','Name','Gender','Designation','Department','Phone','Status']],
        body: filtered.map(s => [
            s.staff_id,
            s.first_name + ' ' + s.last_name,
            ucf(s.gender),
            s.designation,
            s.department,
            s.phone_number,
            ucf(s.Status)
        ]),
        startY: 30, theme: 'grid',
        headStyles: {fillColor:[67,160,71], fontSize:8},
        bodyStyles: {fontSize:7.5},
    });
    doc.save('staff-report-' + datestamp() + '.pdf');
    notify('Exported', 'PDF report downloaded.', 'success');
}

// ── Export Excel ──────────────────────────────────────────
function exportToExcel() {
    if (!filtered.length) { notify('Empty', 'No staff to export.', 'warning'); return; }
    const data = [['Staff ID','First Name','Last Name','Gender','Email','Phone','Designation','Department','Employment Type','Joining Date','Status','Qualifications','Experience']];
    filtered.forEach(s => data.push([
        s.staff_id, s.first_name, s.last_name, ucf(s.gender),
        s.email, s.phone_number, s.designation, s.department,
        s.employment_type, s.joining_date, ucf(s.Status),
        s.qualifications, s.experience
    ]));
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [{wch:14},{wch:15},{wch:15},{wch:10},{wch:26},{wch:15},{wch:20},{wch:20},{wch:16},{wch:14},{wch:12},{wch:28},{wch:12}];
    XLSX.utils.book_append_sheet(wb, ws, 'Staff');
    XLSX.writeFile(wb, 'staff-' + datestamp() + '.xlsx');
    notify('Exported', 'Excel file downloaded.', 'success');
}

// ── Utilities ─────────────────────────────────────────────
function esc(v) { return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function ucf(v) { return v ? v.charAt(0).toUpperCase() + v.slice(1) : ''; }
function fmtDate(d) { if (!d) return '—'; try { return new Date(d).toLocaleDateString('en-UG',{day:'2-digit',month:'short',year:'numeric'}); } catch(_){return d;} }
function datestamp() { return new Date().toISOString().split('T')[0]; }
</script>
</body>
</html>