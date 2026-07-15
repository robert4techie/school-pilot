<?php
/**
 * manage_subjects.php
 * Bugs fixed vs previous version:
 *  1. Level toggle double-fire: replaced <label>+hidden-checkbox pattern with
 *     <div role=checkbox> + programmatic toggle via setLevelToggle().
 *     Previously the browser auto-toggled the checkbox on label click, then our
 *     handler triggered 'change' again — causing the section to open then close.
 *  2. refreshStats() now calls get_subject_stats.php to live-update header pills.
 *  3. No stale showMessage / old deleteSubject / old resetForm code anywhere.
 */

require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction('Manage school subjects');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');

$stats = ['total' => 0, 'o_level' => 0, 'a_level' => 0, 'both' => 0, 'compulsory' => 0];
$sRow = $conn->query("SELECT COUNT(*) AS total, SUM(level IN ('O','O,A')) AS o_level, SUM(level IN ('A','O,A')) AS a_level, SUM(level='O,A') AS both_lvl, SUM(compulsory=1) AS compulsory FROM subjects");
if ($sRow) {
    $r = $sRow->fetch_assoc();
    $stats = ['total' => (int)$r['total'], 'o_level' => (int)$r['o_level'], 'a_level' => (int)$r['a_level'], 'both' => (int)$r['both_lvl'], 'compulsory' => (int)$r['compulsory']];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= $csrf ?>">
<title>Manage Subjects &mdash; SchoolPilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{--g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;--g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;--red:#d32f2f;--orange:#e65100;--blue:#1565c0;--gray:#546e7a;--radius:8px;--radius-lg:12px;--shadow:0 2px 8px rgba(0,0,0,.10);--shadow-lg:0 8px 28px rgba(0,0,0,.14);--tr:.22s ease}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}
a{color:inherit;text-decoration:none}
.page{padding:24px 20px 48px}
/* Header */
.page-header{background:linear-gradient(135deg,var(--g900),var(--g700));border-radius:var(--radius-lg);padding:28px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;margin-bottom:24px;margin-top:40px;box-shadow:var(--shadow-lg)}
.page-header h1{color:#fff;font-size:1.55rem;font-weight:700;display:flex;align-items:center;gap:10px}
.page-header p{color:rgba(255,255,255,.78);font-size:.9rem;margin-top:3px}
.stats-row{display:flex;gap:12px;flex-wrap:wrap}
.stat-pill{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:40px;padding:8px 18px;text-align:center;min-width:80px;cursor:default;transition:background var(--tr)}
.stat-pill:hover{background:rgba(255,255,255,.22)}
.stat-pill .n{font-size:1.35rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.72rem;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.5px}
/* Card */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden}
/* Toolbar */
.toolbar{padding:18px 24px;border-bottom:1px solid #e8ede9;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.toolbar-left{display:flex;flex-wrap:wrap;gap:10px;align-items:center;flex:1 1 auto}
.toolbar-right{display:flex;gap:10px;align-items:center;flex-shrink:0;flex-wrap:wrap}
.search-wrap{position:relative;min-width:220px}
.search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.85rem;pointer-events:none}
.search-wrap input{width:100%;padding:9px 12px 9px 34px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;transition:border-color var(--tr),box-shadow var(--tr)}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.filter-select{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;background:#fff;cursor:pointer;transition:border-color var(--tr)}
.filter-select:focus{outline:none;border-color:var(--g600)}
.entries-wrap{display:flex;align-items:center;gap:6px;font-size:.82rem;color:#6b7c6d;white-space:nowrap}
.entries-wrap .filter-select{min-width:70px}
.result-count{font-size:.8rem;color:#6b7c6d;white-space:nowrap}
/* Buttons */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all var(--tr);white-space:nowrap}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-secondary{background:#fff;color:var(--gray);border:1.5px solid #d0dbd1}.btn-secondary:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-danger{background:var(--red);color:#fff}.btn-danger:hover{background:#b71c1c}
/* Icon buttons */
.action-cell{display:flex;gap:5px;align-items:center}
.btn-icon{width:30px;height:30px;border:none;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;transition:all var(--tr);flex-shrink:0}
.btn-icon:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.18)}
.bi-edit{background:#fff3e0;color:var(--orange)}.bi-edit:hover{background:var(--orange);color:#fff}
.bi-delete{background:#ffebee;color:var(--red)}.bi-delete:hover{background:var(--red);color:#fff}
/* Table */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700),var(--g600))}
thead th{padding:13px 14px;text-align:left;font-size:.8rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--tr)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:12px 14px;font-size:.875rem;vertical-align:middle}
.subject-name{font-weight:600;color:var(--g800)}
.subject-abbr{font-size:.75rem;color:#6b7c6d;margin-top:2px}
/* Badges */
.badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
.badge-o{background:#e3f2fd;color:#1565c0}
.badge-a{background:#fce4ec;color:#880e4f}
.badge-yes{background:#e8f5e9;color:#2e7d32}
.badge-no{background:#f5f5f5;color:#757575}
.badges-wrap{display:flex;gap:4px;flex-wrap:wrap}
.code-chip{display:inline-block;background:#f5f8f5;border:1px solid #dde8dd;border-radius:4px;padding:2px 6px;font-size:.72rem;font-family:monospace;color:#2e7d32}
.code-empty{color:#bbb;font-style:italic;font-size:.75rem}
/* Skeleton */
.skeleton-cell{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;height:14px;display:inline-block;width:80%}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
/* Empty state */
.empty-state{text-align:center;padding:60px 20px;color:#8a9a8b}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;opacity:.45}
.empty-state p{font-size:.95rem}
/* Pagination */
.pagination{padding:16px 24px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e8ede9;flex-wrap:wrap;gap:10px}
.page-info{font-size:.82rem;color:#6b7c6d}
.page-btns{display:flex;gap:4px}
.page-btn{width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:6px;background:#fff;cursor:pointer;font-size:.82rem;font-weight:600;color:#444;font-family:inherit;display:flex;align-items:center;justify-content:center;transition:all var(--tr)}
.page-btn:hover:not(:disabled){border-color:var(--g600);background:var(--g100);color:var(--g800)}
.page-btn.active{background:var(--g700);border-color:var(--g700);color:#fff}
.page-btn:disabled{opacity:.38;cursor:default}
/* Modal */
.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px)}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto;animation:fadeOverlay .2s ease}
@keyframes fadeOverlay{from{opacity:0}to{opacity:1}}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:560px;box-shadow:var(--shadow-lg);animation:slideDown .25s ease;margin:auto}
@keyframes slideDown{from{transform:translateY(-24px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{background:linear-gradient(135deg,var(--g800),var(--g600));padding:20px 24px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head h2{color:#fff;font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.modal-close-btn{background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background var(--tr)}
.modal-close-btn:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:28px 28px 24px}
/* Form sections */
.form-section{margin-bottom:22px}
.form-section-title{font-size:.78rem;font-weight:700;color:var(--g700);text-transform:uppercase;letter-spacing:.6px;margin-bottom:14px;padding-bottom:7px;border-bottom:2px solid var(--g100);display:flex;align-items:center;gap:8px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-grid .full{grid-column:1/-1}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group label{font-size:.8rem;font-weight:600;color:#3a4a3b}
.form-group label .req{color:var(--red)}
.form-control{padding:9px 13px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;width:100%;transition:border-color var(--tr),box-shadow var(--tr);background:#fff}
.form-control:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.1)}
.form-control.is-invalid{border-color:var(--red)}
/* Level toggles — plain divs, toggled programmatically (not label+hidden-cb) */
.level-group{display:flex;gap:10px;flex-wrap:wrap}
.level-toggle{flex:1;min-width:100px;border:1.5px solid #d0dbd1;border-radius:var(--radius);padding:10px 14px;cursor:pointer;transition:all var(--tr);display:flex;align-items:center;gap:8px;font-size:.875rem;font-weight:600;background:#fff;color:#546e7a;user-select:none}
.level-toggle:hover{border-color:var(--g600);background:var(--g50)}
.level-toggle input[type=checkbox]{display:none}
.level-toggle.checked-o{background:#e3f2fd;border-color:#1565c0;color:#1565c0}
.level-toggle.checked-a{background:#fce4ec;border-color:#880e4f;color:#880e4f}
/* Code sections */
.code-section{border-radius:var(--radius);padding:16px;margin-top:12px;animation:fadeIn .2s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.code-section-label{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;display:flex;align-items:center;gap:6px}
.cs-o{background:#e8f4fd;border:1px solid #90caf9}.cs-o .code-section-label{color:#1565c0}
.cs-a{background:#fce4ec;border:1px solid #f48fb1}.cs-a .code-section-label{color:#880e4f}
.check-row{display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.875rem;font-weight:500;color:#3a4a3b;margin-top:8px}
.check-row input[type=checkbox]{width:16px;height:16px;accent-color:var(--g700);cursor:pointer;flex-shrink:0}
.form-actions{display:flex;gap:12px;justify-content:flex-end;padding-top:20px;border-top:1px solid #eef2ee;margin-top:20px}
/* Dialog */
.dialog{display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);align-items:center;justify-content:center}
.dialog.active{display:flex}
.dialog-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:420px;box-shadow:var(--shadow-lg);animation:slideDown .22s ease;overflow:hidden}
.dialog-head{padding:18px 22px;display:flex;align-items:center;gap:12px;color:#fff}
.dialog-head.danger{background:linear-gradient(135deg,#c62828,#ef5350)}
.dialog-head i{font-size:1.2rem}.dialog-head h3{font-size:1rem;font-weight:700}
.dialog-body{padding:22px;text-align:center}
.dialog-body p{font-size:.9rem;color:#555;line-height:1.55;margin-bottom:20px}
.dialog-actions{display:flex;gap:10px;justify-content:center}
/* Toasts */
#notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.notif{background:#fff;border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow-lg);display:flex;align-items:flex-start;gap:12px;border-left:4px solid var(--g600);animation:notifIn .3s ease}
.notif.error{border-left-color:var(--red)}.notif.warning{border-left-color:var(--orange)}.notif.info{border-left-color:var(--blue)}
@keyframes notifIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.notif-icon{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.notif.success .notif-icon{color:var(--g700)}.notif.error .notif-icon{color:var(--red)}.notif.warning .notif-icon{color:var(--orange)}.notif.info .notif-icon{color:var(--blue)}
.notif-body{flex:1}.notif-title{font-weight:700;font-size:.85rem;margin-bottom:2px}.notif-msg{font-size:.8rem;color:#666}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:1rem;padding:0;line-height:1;flex-shrink:0}
@media(max-width:700px){.form-grid{grid-template-columns:1fr}.toolbar{flex-direction:column;align-items:stretch}.toolbar-right{flex-wrap:wrap}.page-header{flex-direction:column;padding:20px}.modal-box{margin:12px}}
</style>
</head>
<body>
<?php require_once 'nav.php'; ?>
<div class="page">
  <div class="page-header">
    <div>
      <h1><i class="fas fa-book-open"></i> Manage Subjects</h1>
      <p>Add, edit, and remove O Level &amp; A Level subjects</p>
    </div>
    <div class="stats-row">
      <div class="stat-pill"><span class="n" id="statTotal"><?= $stats['total'] ?></span><span class="l">Total</span></div>
      <div class="stat-pill"><span class="n" id="statO"><?= $stats['o_level'] ?></span><span class="l">O Level</span></div>
      <div class="stat-pill"><span class="n" id="statA"><?= $stats['a_level'] ?></span><span class="l">A Level</span></div>
      <div class="stat-pill"><span class="n" id="statBoth"><?= $stats['both'] ?></span><span class="l">Both</span></div>
      <div class="stat-pill"><span class="n" id="statComp"><?= $stats['compulsory'] ?></span><span class="l">Compulsory</span></div>
    </div>
  </div>

  <div class="card">
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search subjects by name, code, level…" autocomplete="off">
        </div>
        <select id="levelFilter" class="filter-select">
          <option value="">All Levels</option>
          <option value="O">O Level only</option>
          <option value="A">A Level only</option>
          <option value="O,A">Both Levels</option>
        </select>
        <span id="resultCount" class="result-count"></span>
      </div>
      <div class="toolbar-right">
        <div class="entries-wrap">
          Show
          <select id="entriesSelect" class="filter-select">
            <option value="10" selected>10</option>
            <option value="20">20</option>
            <option value="50">50</option>
          </select>
          entries
        </div>
        <button class="btn btn-primary" id="addSubjectBtn"><i class="fas fa-plus"></i> Add Subject</button>
      </div>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th><th>Subject</th><th>Level</th><th>Codes</th><th>Compulsory</th><th>Actions</th>
          </tr>
        </thead>
        <tbody id="subjectsBody"></tbody>
      </table>
    </div>

    <div class="pagination">
      <span class="page-info" id="pageInfo">—</span>
      <div class="page-btns" id="pageBtns"></div>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal" id="subjectModal" role="dialog" aria-modal="true">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-book"></i> <span id="modalTitleText">Add Subject</span></h2>
      <button class="modal-close-btn" id="modalCloseBtn" aria-label="Close"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form id="subjectForm" novalidate autocomplete="off">
        <input type="hidden" id="subj_id" name="subj_id">

        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-tag"></i> Basic Information</div>
          <div class="form-grid">
            <div class="form-group full">
              <label for="subj_name">Subject Name <span class="req">*</span></label>
              <input type="text" id="subj_name" name="subj_name" class="form-control" maxlength="100" placeholder="e.g. Mathematics, Biology…">
            </div>
            <div class="form-group full">
              <label for="subj_abbr">Short Code <span class="req">*</span></label>
              <input type="text" id="subj_abbr" name="subj_abbr" class="form-control" maxlength="10" placeholder="e.g. MTH, BIO, PHY…">
            </div>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-title"><i class="fas fa-layer-group"></i> Level Assignment <span class="req">*</span></div>
          <!--
            FIX: These are <div> elements, NOT <label> elements wrapping the checkbox.
            Clicking a <label> that contains a checkbox causes the browser to toggle the
            checkbox automatically (1 toggle), then our click handler called trigger('change')
            a second time (2nd toggle) — net result: nothing changed visually but the
            internal state was correct, causing the section to flash.
            Using a plain <div> means we handle all toggling ourselves via setLevelToggle().
          -->
          <div class="level-group">
            <div class="level-toggle" id="toggleO" role="checkbox" aria-checked="false" tabindex="0">
              <input type="checkbox" id="level_o" name="level_o" value="O" aria-hidden="true">
              <i class="fas fa-graduation-cap"></i> O Level
            </div>
            <div class="level-toggle" id="toggleA" role="checkbox" aria-checked="false" tabindex="0">
              <input type="checkbox" id="level_a" name="level_a" value="A" aria-hidden="true">
              <i class="fas fa-university"></i> A Level
            </div>
          </div>

          <div id="olevelSection" class="code-section cs-o" style="display:none">
            <div class="code-section-label"><i class="fas fa-hashtag"></i> O Level Details</div>
            <div class="form-grid">
              <div class="form-group full">
                <label for="code">O Level Code <span class="req">*</span></label>
                <input type="text" id="code" name="code" class="form-control" maxlength="10" placeholder="e.g. 4024">
              </div>
              <div class="form-group full">
                <label class="check-row">
                  <input type="checkbox" id="compulsory" name="compulsory" value="1"> Mark as Compulsory Subject
                </label>
              </div>
            </div>
          </div>

          <div id="alevelSection" class="code-section cs-a" style="display:none">
            <div class="code-section-label"><i class="fas fa-hashtag"></i> A Level Details</div>
            <div class="form-group">
              <label for="codea">A Level Code <span class="req">*</span></label>
              <input type="text" id="codea" name="codea" class="form-control" maxlength="10" placeholder="e.g. 9709">
            </div>
          </div>
        </div>

        <div class="form-actions">
          <button type="button" class="btn btn-secondary" id="cancelModalBtn"><i class="fas fa-times"></i> Cancel</button>
          <button type="submit" class="btn btn-primary" id="saveBtn"><i class="fas fa-save"></i> Save Subject</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Dialog -->
<div class="dialog" id="deleteDialog" role="alertdialog" aria-modal="true">
  <div class="dialog-box">
    <div class="dialog-head danger"><i class="fas fa-trash-alt"></i><h3>Confirm Delete</h3></div>
    <div class="dialog-body">
      <p id="deleteMsg">Are you sure you want to delete this subject? This action cannot be undone.</p>
      <div class="dialog-actions">
        <button class="btn btn-secondary" id="cancelDeleteBtn"><i class="fas fa-times"></i> Cancel</button>
        <button class="btn btn-danger" id="confirmDeleteBtn"><i class="fas fa-trash-alt"></i> Delete</button>
      </div>
    </div>
  </div>
</div>

<div id="notif-stack" aria-live="polite"></div>

<script src="assets/js/jquery.min.js"></script>
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
let currentPage = 1, entriesPerPage = 10, searchTerm = '', levelFilter = '';
let pendingDeleteId = null, debounceTimer = null;

function esc(s) { const d = document.createElement('div'); d.textContent = String(s ?? ''); return d.innerHTML; }

/* ── Toasts ─────────────────────────────────────────────────────────── */
function toast(message, type = 'success', duration = 5000) {
    const icons  = { success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
    const titles = { success:'Success', error:'Error', warning:'Warning', info:'Info' };
    const el = document.createElement('div');
    el.className = `notif ${type}`;
    el.innerHTML = `<i class="fas ${icons[type]||'fa-circle-info'} notif-icon"></i><div class="notif-body"><div class="notif-title">${esc(titles[type]||'Notice')}</div><div class="notif-msg">${esc(message)}</div></div><button class="notif-close" aria-label="Dismiss">&times;</button>`;
    el.querySelector('.notif-close').addEventListener('click', () => dismiss(el));
    document.getElementById('notif-stack').appendChild(el);
    if (duration > 0) setTimeout(() => dismiss(el), duration);
}
function dismiss(el) {
    el.style.cssText += 'transition:opacity .3s,transform .3s;opacity:0;transform:translateX(30px)';
    setTimeout(() => el.remove(), 300);
}

/* ── Modal / Dialog ─────────────────────────────────────────────────── */
function openModal()  { document.getElementById('subjectModal').classList.add('active'); document.body.style.overflow='hidden'; setTimeout(()=>document.getElementById('subj_name').focus(),120); }
function closeModal() { document.getElementById('subjectModal').classList.remove('active'); document.body.style.overflow=''; resetForm(); }
function openDeleteDialog(id, name) { pendingDeleteId = id; document.getElementById('deleteMsg').textContent = `Are you sure you want to delete "${name}"? This action cannot be undone.`; document.getElementById('deleteDialog').classList.add('active'); }
function closeDeleteDialog() { document.getElementById('deleteDialog').classList.remove('active'); pendingDeleteId = null; }

/* ── Level toggles (programmatic — no label auto-toggle issue) ─────── */
function setLevelToggle(toggleId, cbId, sectionId, cls, force) {
    const toggle = document.getElementById(toggleId);
    const cb     = document.getElementById(cbId);
    const sect   = document.getElementById(sectionId);
    const state  = (force !== undefined) ? force : !cb.checked;
    cb.checked = state;
    toggle.setAttribute('aria-checked', state ? 'true' : 'false');
    toggle.classList.toggle(cls, state);
    sect.style.display = state ? '' : 'none';
    if (!state) {
        if (cbId === 'level_o') { document.getElementById('code').value=''; document.getElementById('compulsory').checked=false; }
        if (cbId === 'level_a') { document.getElementById('codea').value=''; }
    }
}

/* ── Load & Render ──────────────────────────────────────────────────── */
function loadSubjects() {
    renderSkeleton();
    $.ajax({
        url:'get_school_subjects.php', type:'GET',
        data:{ page:currentPage, limit:entriesPerPage, search:searchTerm, level:levelFilter },
        dataType:'json',
        success:function(res) { res.success ? renderTable(res.subjects, res.totalItems) : (renderError(res.message||'Error'), toast(res.message||'Error','error')); },
        error:function(xhr)   { const m=safeMsg(xhr)||'Network error.'; renderError(m); toast(m,'error'); }
    });
}

function renderSkeleton() {
    let rows='';
    for(let i=0;i<entriesPerPage;i++) rows+=`<tr>
        <td><span class="skeleton-cell" style="width:22px"></span></td>
        <td><span class="skeleton-cell" style="width:140px;display:block;margin-bottom:5px"></span><span class="skeleton-cell" style="width:60px;height:11px"></span></td>
        <td><span class="skeleton-cell" style="width:80px"></span></td>
        <td><span class="skeleton-cell" style="width:100px"></span></td>
        <td><span class="skeleton-cell" style="width:45px"></span></td>
        <td><span class="skeleton-cell" style="width:65px"></span></td>
    </tr>`;
    document.getElementById('subjectsBody').innerHTML = rows;
    document.getElementById('pageInfo').textContent = '—';
}

function renderTable(subjects, totalItems) {
    const tbody = document.getElementById('subjectsBody');
    if (!subjects.length) {
        tbody.innerHTML = `<tr><td colspan="6"><div class="empty-state"><i class="fas fa-book-open"></i><p>No subjects found${searchTerm ? ' matching your search' : ''}.</p></div></td></tr>`;
    } else {
        const start = (currentPage-1)*entriesPerPage+1;
        tbody.innerHTML = subjects.map((s,i)=>`<tr data-id="${esc(s.subj_id)}">
            <td>${start+i}</td>
            <td><div class="subject-name">${esc(s.subj_name)}</div><div class="subject-abbr">${esc(s.subj_abbr)}</div></td>
            <td>${makeLevelBadge(s.level)}</td>
            <td>${makeCodeCells(s)}</td>
            <td>${s.compulsory?'<span class="badge badge-yes">Yes</span>':'<span class="badge badge-no">No</span>'}</td>
            <td><div class="action-cell">
                <button class="btn-icon bi-edit edit-btn" data-id="${esc(s.subj_id)}" title="Edit ${esc(s.subj_name)}"><i class="fas fa-pencil"></i></button>
                <button class="btn-icon bi-delete delete-btn" data-id="${esc(s.subj_id)}" data-name="${esc(s.subj_name)}" title="Delete ${esc(s.subj_name)}"><i class="fas fa-trash-alt"></i></button>
            </div></td>
        </tr>`).join('');
    }
    const start = subjects.length>0?(currentPage-1)*entriesPerPage+1:0;
    const end   = Math.min(start+subjects.length-1, totalItems);
    document.getElementById('pageInfo').textContent    = `Showing ${start}–${end} of ${totalItems} entries`;
    document.getElementById('resultCount').textContent = totalItems>0?`${totalItems} subject${totalItems!==1?'s':''}`:'';
    renderPagination(Math.ceil(totalItems/entriesPerPage));
}

function renderError(msg) {
    document.getElementById('subjectsBody').innerHTML=`<tr><td colspan="6"><div class="empty-state"><i class="fas fa-circle-exclamation"></i><p>${esc(msg)}</p></div></td></tr>`;
    document.getElementById('pageInfo').textContent='—';
    document.getElementById('pageBtns').innerHTML='';
}

function makeLevelBadge(level) {
    const map={'O':'<span class="badge badge-o">O Level</span>','A':'<span class="badge badge-a">A Level</span>','O,A':'<span class="badge badge-o">O Level</span><span class="badge badge-a">A Level</span>'};
    return `<div class="badges-wrap">${map[level]||`<span class="badge">${esc(level)}</span>`}</div>`;
}
function makeCodeCells(s) {
    const p=[];
    if(s.code)  p.push(`O: <span class="code-chip">${esc(s.code)}</span>`);
    if(s.codea) p.push(`A: <span class="code-chip">${esc(s.codea)}</span>`);
    return p.length?`<div style="display:flex;gap:6px;flex-wrap:wrap">${p.join('')}</div>`:'<span class="code-empty">—</span>';
}

/* ── Pagination ─────────────────────────────────────────────────────── */
function renderPagination(totalPages) {
    const c=document.getElementById('pageBtns'); c.innerHTML='';
    if(totalPages<=1) return;
    const mk=(html,page,dis,act)=>{ const b=document.createElement('button'); b.className='page-btn'+(act?' active':''); b.innerHTML=html; b.disabled=dis; if(!dis&&!act) b.addEventListener('click',()=>{currentPage=page;loadSubjects();}); return b; };
    c.appendChild(mk('<i class="fas fa-chevron-left"></i>',currentPage-1,currentPage===1,false));
    const s=Math.max(1,currentPage-2), e=Math.min(s+4,totalPages);
    for(let p=s;p<=e;p++) c.appendChild(mk(p,p,false,p===currentPage));
    c.appendChild(mk('<i class="fas fa-chevron-right"></i>',currentPage+1,currentPage===totalPages,false));
}

/* ── Form helpers ───────────────────────────────────────────────────── */
function resetForm() {
    document.getElementById('subjectForm').reset();
    document.getElementById('subj_id').value='';
    setLevelToggle('toggleO','level_o','olevelSection','checked-o',false);
    setLevelToggle('toggleA','level_a','alevelSection','checked-a',false);
    document.getElementById('modalTitleText').textContent='Add Subject';
    document.getElementById('saveBtn').innerHTML='<i class="fas fa-save"></i> Save Subject';
    document.querySelectorAll('.form-control.is-invalid').forEach(el=>el.classList.remove('is-invalid'));
}

function populateForm(s) {
    document.getElementById('subj_id').value   = s.subj_id;
    document.getElementById('subj_name').value = s.subj_name;
    document.getElementById('subj_abbr').value = s.subj_abbr;
    document.getElementById('modalTitleText').textContent = 'Edit Subject';
    document.getElementById('saveBtn').innerHTML = '<i class="fas fa-save"></i> Update Subject';
    const hasO = s.level.split(',').includes('O');
    const hasA = s.level.split(',').includes('A');
    setLevelToggle('toggleO','level_o','olevelSection','checked-o',hasO);
    setLevelToggle('toggleA','level_a','alevelSection','checked-a',hasA);
    if(hasO) { document.getElementById('code').value=s.code||''; document.getElementById('compulsory').checked=s.compulsory===1; }
    if(hasA) { document.getElementById('codea').value=s.codea||''; }
}

function validateForm() {
    const mark=(el,bad,msg)=>{ el.classList.toggle('is-invalid',bad); if(bad){toast(msg,'warning');el.focus();} return !bad; };
    const name=document.getElementById('subj_name'), abbr=document.getElementById('subj_abbr');
    if(!mark(name,!name.value.trim(),'Subject Name is required.')) return false;
    if(!mark(abbr,!abbr.value.trim(),'Short Code is required.')) return false;
    const hasO=document.getElementById('level_o').checked, hasA=document.getElementById('level_a').checked;
    if(!hasO&&!hasA){ toast('Please select at least one level.','warning'); return false; }
    if(hasO){ const c=document.getElementById('code'); if(!mark(c,!c.value.trim(),'O Level Code is required.')) return false; }
    if(hasA){ const c=document.getElementById('codea'); if(!mark(c,!c.value.trim(),'A Level Code is required.')) return false; }
    return true;
}

/* ── Stats pills ────────────────────────────────────────────────────── */
function refreshStats() {
    $.getJSON('get_subject_stats.php', function(res) {
        if(!res.success) return;
        const s=res.stats;
        document.getElementById('statTotal').textContent = s.total;
        document.getElementById('statO').textContent     = s.o_level;
        document.getElementById('statA').textContent     = s.a_level;
        document.getElementById('statBoth').textContent  = s.both;
        document.getElementById('statComp').textContent  = s.compulsory;
    });
}

/* ── CRUD ───────────────────────────────────────────────────────────── */
function editSubject(id) {
    document.getElementById('modalTitleText').textContent='Loading…'; openModal();
    $.ajax({ url:'get_subject.php', type:'GET', data:{id}, dataType:'json',
        success:function(res){ res.success?populateForm(res.subject):(closeModal(),toast(res.message||'Error','error')); },
        error:function(xhr){ closeModal(); toast(safeMsg(xhr)||'Network error.','error'); }
    });
}

function saveSubject() {
    if(!validateForm()) return;
    const hasO=document.getElementById('level_o').checked, hasA=document.getElementById('level_a').checked;
    const parts=[]; if(hasO) parts.push('O'); if(hasA) parts.push('A');
    const payload={ csrf_token:CSRF, subj_id:document.getElementById('subj_id').value, subj_name:document.getElementById('subj_name').value.trim(), subj_abbr:document.getElementById('subj_abbr').value.trim().toUpperCase(), level:parts.join(','), code:hasO?document.getElementById('code').value.trim():'', codea:hasA?document.getElementById('codea').value.trim():'', compulsory:(hasO&&document.getElementById('compulsory').checked)?1:0 };
    const btn=document.getElementById('saveBtn'); btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving…';
    $.ajax({ url:'process_subject.php', type:'POST', data:payload, dataType:'json',
        success:function(res){ res.success?(toast(res.message,'success'),closeModal(),currentPage=1,loadSubjects(),refreshStats()):toast(res.message||'Error','error'); },
        error:function(xhr){ toast(safeMsg(xhr)||'Network error.','error'); },
        complete:function(){ btn.disabled=false; const e=document.getElementById('subj_id').value!==''; btn.innerHTML=`<i class="fas fa-save"></i> ${e?'Update':'Save'} Subject`; }
    });
}

function deleteSubject(id) {
    const btn=document.getElementById('confirmDeleteBtn'); btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Deleting…';
    $.ajax({ url:'delete_subject.php', type:'POST', data:{csrf_token:CSRF,id}, dataType:'json',
        success:function(res){ closeDeleteDialog(); if(res.success){ toast(res.message,'success'); const rc=document.querySelectorAll('#subjectsBody tr[data-id]').length; if(rc===1&&currentPage>1) currentPage--; loadSubjects(); refreshStats(); } else toast(res.message||'Error','error'); },
        error:function(xhr){ closeDeleteDialog(); toast(safeMsg(xhr)||'Network error.','error'); },
        complete:function(){ btn.disabled=false; btn.innerHTML='<i class="fas fa-trash-alt"></i> Delete'; }
    });
}

function safeMsg(xhr){ try{ return JSON.parse(xhr.responseText).message||null; }catch(_){ return null; } }

/* ── Event bindings ─────────────────────────────────────────────────── */
$(function(){
    loadSubjects();

    $('#searchInput').on('input',function(){ clearTimeout(debounceTimer); debounceTimer=setTimeout(()=>{ searchTerm=$('#searchInput').val().trim(); currentPage=1; loadSubjects(); },350); });
    $('#levelFilter').on('change',function(){ levelFilter=$(this).val(); currentPage=1; loadSubjects(); });
    $('#entriesSelect').on('change',function(){ entriesPerPage=parseInt($(this).val(),10); currentPage=1; loadSubjects(); });

    $('#addSubjectBtn').on('click',()=>{ resetForm(); openModal(); });
    $('#modalCloseBtn,#cancelModalBtn').on('click',closeModal);
    $('#subjectModal').on('click',function(e){ if(e.target===this) closeModal(); });

    /* Level toggles — click AND keyboard (Space/Enter) on the <div role=checkbox> */
    $('#toggleO').on('click keydown',function(e){
        if(e.type==='keydown'&&e.key!==' '&&e.key!=='Enter') return;
        e.preventDefault();
        setLevelToggle('toggleO','level_o','olevelSection','checked-o');
    });
    $('#toggleA').on('click keydown',function(e){
        if(e.type==='keydown'&&e.key!==' '&&e.key!=='Enter') return;
        e.preventDefault();
        setLevelToggle('toggleA','level_a','alevelSection','checked-a');
    });

    /* Auto-uppercase abbreviation */
    $('#subj_abbr').on('input',function(){ const p=this.selectionStart; this.value=this.value.toUpperCase(); this.setSelectionRange(p,p); });

    $('#subjectForm').on('submit',function(e){ e.preventDefault(); saveSubject(); });

    $(document).on('click','.edit-btn',function(){ editSubject($(this).data('id')); });
    $(document).on('click','.delete-btn',function(){ openDeleteDialog($(this).data('id'),$(this).data('name')); });

    $('#cancelDeleteBtn').on('click',closeDeleteDialog);
    $('#deleteDialog').on('click',function(e){ if(e.target===this) closeDeleteDialog(); });
    $('#confirmDeleteBtn').on('click',function(){ if(pendingDeleteId!==null) deleteSubject(pendingDeleteId); });

    $(document).on('keydown',function(e){ if(e.key!=='Escape') return; if($('#subjectModal').hasClass('active')) closeModal(); if($('#deleteDialog').hasClass('active')) closeDeleteDialog(); });
});
</script>
</body>
</html>