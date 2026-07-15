<?php
/**
 * add_aoi.php
 *
 * Displays the AOI management page for a chosen subject/class/term/year.
 * Table and form content are loaded via AJAX from the ajax_handlers/ folder.
 *
 * ── BUGS FIXED ──────────────────────────────────────────────────────────────
 *   BUG-8  loadFormContent() was called eagerly on page load, wasting an AJAX
 *          round-trip for users who never click "Add New Activity".
 *          Fix: lazy-load on first openModal() call; a `formLoaded` flag
 *          prevents double-fetching on subsequent opens.
 *
 *   BUG-9  showLoading(btn) on tiny .btn-icon buttons (30×30 px) replaced the
 *          icon with "Loading…" text, overflowing the fixed-size button and
 *          breaking the layout.
 *          Fix: icon buttons use a dedicated showIconLoading / hideIconLoading
 *          pair that swaps just the <i> tag to a spinner and sets pointer-events
 *          none, leaving the button dimensions intact.
 *
 *   BUG-10 In the delete success handler, closeDeleteDialog() was called BEFORE
 *          hideLoading(btn). Although the button is not removed from the DOM
 *          (just hidden), restoring _origHTML on a visually-hidden element is
 *          misleading and order-dependent. Fixed: hideLoading first, then close.
 *
 *   BUG-11 The CSRF token was appended to the serialised form string via JS
 *          string concatenation. aoi_form.php now embeds it as a hidden field,
 *          so $(form).serialize() always carries it. The JS append is kept as a
 *          no-op fallback (duplicate POST keys are harmless in PHP).
 */

require_once '../auth.php';
require_once '../conn.php';

/* ── CSRF validation ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim($_POST['csrf_token'] ?? '');
    if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        header('Location: sel_add_aoi.php?error=csrf');
        exit;
    }
}

/* ── Allowed values ──────────────────────────────────────────────────── */
$allowed_classes  = ['Senior One', 'Senior Two', 'Senior Three', 'Senior Four'];
$allowed_terms    = ['Term 1', 'Term 2', 'Term 3'];
$allowed_streams  = ['East', 'West', 'South', 'North'];
$current_year     = (int)date('Y');

/* ── Sanitize POST inputs ────────────────────────────────────────────── */
$sel_class = in_array($_POST['class'] ?? '', $allowed_classes, true)
    ? $_POST['class'] : '';

$term = in_array($_POST['term'] ?? '', $allowed_terms, true)
    ? $_POST['term'] : '';

$year = (int)($_POST['year'] ?? $current_year);
if ($year < 2000 || $year > $current_year + 5) {
    $year = $current_year;
}

$streams = array_values(array_filter(
    (array)($_POST['streams'] ?? []),
    fn($s) => in_array($s, $allowed_streams, true)
));

$subject_name = trim($_POST['subject'] ?? '');

/* ── Redirect if required fields missing ────────────────────────────── */
if (!$sel_class || !$term || !$subject_name || empty($streams)) {
    header('Location: sel_add_aoi.php');
    exit;
}

/* ── CSRF for AJAX calls ─────────────────────────────────────────────── */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');

/* ── Safe display values ─────────────────────────────────────────────── */
$display_class   = htmlspecialchars($sel_class,    ENT_QUOTES, 'UTF-8');
$display_term    = htmlspecialchars($term,          ENT_QUOTES, 'UTF-8');
$display_subject = htmlspecialchars($subject_name, ENT_QUOTES, 'UTF-8');

/* ── JS params (via json_encode — prevents XSS and quote injection) ─── */
$js_params = json_encode([
    'class'   => $sel_class,
    'term'    => $term,
    'year'    => $year,
    'subject' => $subject_name,
    'streams' => $streams,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= $csrf ?>">
<title>Activity of Integration &mdash; SchoolPilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── Variables ───────────────────────────────────────────────────────── */
:root {
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--orange:#e65100;--blue:#1565c0;--gray:#546e7a;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);
  --shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --tr:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}
a{color:inherit;text-decoration:none}
.page{padding:24px 20px 48px}

/* ── Page Header ─────────────────────────────────────────────────────── */
.page-header{background:linear-gradient(135deg,var(--g900),var(--g700));border-radius:var(--radius-lg);padding:28px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;margin-bottom:24px;margin-top:40px;box-shadow:var(--shadow-lg)}
.page-header-left h1{color:#fff;font-size:1.45rem;font-weight:700;display:flex;align-items:center;gap:10px}
.page-header-left p{color:rgba(255,255,255,.78);font-size:.875rem;margin-top:4px}
.meta-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.meta-chip{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);border-radius:20px;padding:4px 12px;font-size:.78rem;color:#fff;display:inline-flex;align-items:center;gap:5px}

/* ── Breadcrumb ──────────────────────────────────────────────────────── */
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:.82rem;color:rgba(255,255,255,.7)}
.breadcrumb a{color:rgba(255,255,255,.85)}.breadcrumb a:hover{color:#fff}
.breadcrumb .sep{opacity:.5}

/* ── Buttons ─────────────────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;border:none;border-radius:var(--radius);font-size:.875rem;font-weight:700;font-family:inherit;cursor:pointer;transition:all var(--tr);white-space:nowrap}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn:disabled{opacity:.65;cursor:not-allowed;transform:none;box-shadow:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover:not(:disabled){background:var(--g800)}
.btn-secondary{background:#fff;color:var(--gray);border:1.5px solid #d0dbd1}.btn-secondary:hover:not(:disabled){border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-danger{background:var(--red);color:#fff}.btn-danger:hover:not(:disabled){background:#b71c1c}

/* ── Card ────────────────────────────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden;margin-bottom:24px}
.card-head{background:linear-gradient(90deg,var(--g700),var(--g600));padding:18px 24px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.card-head h2{color:#fff;font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:8px}

/* ── Table ───────────────────────────────────────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700),var(--g600))}
thead th{padding:13px 16px;text-align:left;font-size:.78rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--tr)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:13px 16px;font-size:.875rem;vertical-align:middle}

/* ── Skeleton loader ─────────────────────────────────────────────────── */
.skeleton-cell{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;height:14px;display:inline-block}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ── Form skeleton (modal) ───────────────────────────────────────────── */
.form-skeleton{padding:28px;display:flex;flex-direction:column;gap:20px}
.form-skel-label{height:12px;border-radius:4px;margin-bottom:8px}
.form-skel-input{height:40px;border-radius:8px}
.form-skel-textarea{height:100px;border-radius:8px}
.form-skel-btn-row{display:flex;justify-content:flex-end;padding-top:18px;border-top:1px solid #eef2ee;margin-top:4px}
.form-skel-btn{height:38px;width:190px;border-radius:8px}

/* ── Empty state ─────────────────────────────────────────────────────── */
.empty-state{text-align:center;padding:60px 20px;color:#8a9a8b}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;opacity:.45}
.empty-state p{font-size:.95rem}

/* ── Icon action buttons ─────────────────────────────────────────────── */
.action-cell{display:flex;gap:5px}
/* FIX BUG-9: overflow:hidden prevents spinner text from breaking layout */
.btn-icon{width:30px;height:30px;border:none;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;transition:all var(--tr);flex-shrink:0;overflow:hidden;position:relative}
.btn-icon:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.18)}
/* disabled state for icon buttons while loading */
.btn-icon:disabled,.btn-icon.is-loading{opacity:.7;cursor:not-allowed;pointer-events:none;transform:none;box-shadow:none}
.bi-edit{background:#fff3e0;color:var(--orange)}.bi-edit:hover:not(:disabled){background:var(--orange);color:#fff}
.bi-delete{background:#ffebee;color:var(--red)}.bi-delete:hover:not(:disabled){background:var(--red);color:#fff}

/* ── Modal ───────────────────────────────────────────────────────────── */
.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px)}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto;animation:fadeOverlay .2s ease}
@keyframes fadeOverlay{from{opacity:0}to{opacity:1}}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:580px;box-shadow:var(--shadow-lg);animation:slideDown .25s ease;margin:auto}
@keyframes slideDown{from{transform:translateY(-24px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{background:linear-gradient(135deg,var(--g800),var(--g600));padding:20px 24px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head h2{color:#fff;font-size:1rem;font-weight:700;display:flex;align-items:center;gap:8px}
.modal-close-btn{background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background var(--tr)}
.modal-close-btn:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:28px}
.modal-body .form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:18px}
.modal-body label{font-size:.8rem;font-weight:600;color:#3a4a3b}
.modal-body .form-control{padding:9px 13px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;width:100%;transition:border-color var(--tr),box-shadow var(--tr);background:#fff}
.modal-body .form-control:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.1)}
textarea.form-control{resize:vertical;min-height:110px}
.form-actions{display:flex;gap:12px;justify-content:flex-end;padding-top:18px;border-top:1px solid #eef2ee;margin-top:18px}
.hidden{display:none!important}

/* ── Confirm Dialog ──────────────────────────────────────────────────── */
.dialog{display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);align-items:center;justify-content:center}
.dialog.active{display:flex}
.dialog-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:400px;box-shadow:var(--shadow-lg);animation:slideDown .22s ease;overflow:hidden}
.dialog-head{padding:18px 22px;display:flex;align-items:center;gap:12px;color:#fff}
.dialog-head.danger{background:linear-gradient(135deg,#c62828,#ef5350)}
.dialog-head i{font-size:1.2rem}.dialog-head h3{font-size:1rem;font-weight:700}
.dialog-body{padding:22px;text-align:center}
.dialog-body p{font-size:.9rem;color:#555;line-height:1.55;margin-bottom:20px}
.dialog-actions{display:flex;gap:10px;justify-content:center}

/* ── Toasts ──────────────────────────────────────────────────────────── */
#notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.notif{background:#fff;border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow-lg);display:flex;align-items:flex-start;gap:12px;border-left:4px solid var(--g600);animation:notifIn .3s ease}
.notif.error{border-left-color:var(--red)}.notif.warning{border-left-color:var(--orange)}.notif.info{border-left-color:var(--blue)}
@keyframes notifIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.notif-icon{font-size:1.1rem;flex-shrink:0;margin-top:1px}
.notif.success .notif-icon{color:var(--g700)}.notif.error .notif-icon{color:var(--red)}.notif.warning .notif-icon{color:var(--orange)}.notif.info .notif-icon{color:var(--blue)}
.notif-body{flex:1}.notif-title{font-weight:700;font-size:.85rem;margin-bottom:2px}.notif-msg{font-size:.8rem;color:#666}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:1rem;line-height:1;flex-shrink:0}

@media(max-width:700px){.page-header{flex-direction:column;padding:20px}.modal-box{margin:12px}}
</style>
</head>

<body>
<?php require_once '../nav.php'; ?>

<div class="page">

  <!-- ── Page Header ────────────────────────────────────────────────── -->
  <div class="page-header">
    <div class="page-header-left">
      <div class="breadcrumb">
        <a href="index.php">Dashboard</a>
        <span class="sep">/</span>
        <a href="sel_add_aoi.php">AOI Selection</a>
        <span class="sep">/</span>
        <span><?= $display_subject ?></span>
      </div>
      <h1 style="margin-top:10px"><i class="fas fa-list-check"></i> Activity of Integration</h1>
      <div class="meta-chips">
        <span class="meta-chip"><i class="fas fa-chalkboard-teacher"></i> <?= $display_class ?></span>
        <span class="meta-chip"><i class="fas fa-calendar"></i> <?= $display_term ?></span>
        <span class="meta-chip"><i class="fas fa-calendar-days"></i> <?= (int)$year ?></span>
        <span class="meta-chip"><i class="fas fa-book"></i> <?= $display_subject ?></span>
      </div>
    </div>
    <button class="btn btn-primary" id="addBtn" style="align-self:flex-start">
      <i class="fas fa-plus"></i> Add New Activity
    </button>
  </div>

  <!-- ── AOI Table Card ─────────────────────────────────────────────── -->
  <div class="card">
    <div class="card-head">
      <h2><i class="fas fa-table-list"></i> Registered Activities of Integration</h2>
    </div>
    <div id="table-container">
      <!-- Initial skeleton shown while AJAX loads -->
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>#</th><th>Topic</th><th>Description</th><th>Streams</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php for ($i = 0; $i < 5; $i++): ?>
            <tr>
              <td><span class="skeleton-cell" style="width:22px"></span></td>
              <td><span class="skeleton-cell" style="width:160px"></span></td>
              <td>
                <span class="skeleton-cell" style="width:90%;display:block;margin-bottom:5px"></span>
                <span class="skeleton-cell" style="width:60%;height:11px"></span>
              </td>
              <td><span class="skeleton-cell" style="width:60px"></span></td>
              <td><span class="skeleton-cell" style="width:65px"></span></td>
            </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.page -->

<!-- ── Add / Edit Modal ─────────────────────────────────────────────── -->
<div class="modal" id="aoiModal" role="dialog" aria-modal="true" aria-labelledby="form-title">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-pen-to-square"></i> <span id="form-title">Add Activity of Integration</span></h2>
      <button class="modal-close-btn" id="modalCloseBtn" aria-label="Close modal"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="form-container">
      <!-- FIX BUG-8: form skeleton stays here until modal is first opened;
           replaced by real form on first openModal() call. -->
      <div class="form-skeleton" id="formSkeleton">
        <div>
          <div class="skeleton-cell form-skel-label" style="width:80px"></div>
          <div class="skeleton-cell form-skel-input" style="width:100%"></div>
        </div>
        <div>
          <div class="skeleton-cell form-skel-label" style="width:120px"></div>
          <div class="skeleton-cell form-skel-textarea" style="width:100%"></div>
        </div>
        <div class="form-skel-btn-row">
          <div class="skeleton-cell form-skel-btn"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── Delete Confirm Dialog ───────────────────────────────────────── -->
<div class="dialog" id="deleteDialog" role="alertdialog" aria-modal="true" aria-labelledby="deleteDialogTitle">
  <div class="dialog-box">
    <div class="dialog-head danger">
      <i class="fas fa-trash-alt"></i>
      <h3 id="deleteDialogTitle">Confirm Delete</h3>
    </div>
    <div class="dialog-body">
      <p id="deleteMsg">Are you sure you want to delete this activity? This action cannot be undone.</p>
      <div class="dialog-actions">
        <button class="btn btn-secondary" id="cancelDeleteBtn"><i class="fas fa-times"></i> Cancel</button>
        <button class="btn btn-danger"    id="confirmDeleteBtn"><i class="fas fa-trash-alt"></i> Delete</button>
      </div>
    </div>
  </div>
</div>

<div id="notif-stack" aria-live="polite"></div>

<script src="../assets/js/jquery.min.js"></script>
<script>
/* ═══════════════════════════════════════════════════════════════════════
 *  add_aoi.php — Client Logic
 * ═══════════════════════════════════════════════════════════════════════ */

/* ── PHP → JS (safe via json_encode) ────────────────────────────────── */
const params = <?= $js_params ?>;
const CSRF   = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

/* ── XSS-safe escaper ───────────────────────────────────────────────── */
function esc(s){ const d=document.createElement('div'); d.textContent=String(s??''); return d.innerHTML; }

/* ── Toasts ─────────────────────────────────────────────────────────── */
function toast(message, type='success', duration=5000) {
    const icons  = { success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
    const titles = { success:'Success', error:'Error', warning:'Warning', info:'Info' };
    const el = document.createElement('div');
    el.className = `notif ${type}`;
    el.innerHTML = `<i class="fas ${icons[type]||'fa-circle-info'} notif-icon"></i><div class="notif-body"><div class="notif-title">${esc(titles[type]||'Notice')}</div><div class="notif-msg">${esc(message)}</div></div><button class="notif-close" aria-label="Dismiss">&times;</button>`;
    el.querySelector('.notif-close').addEventListener('click', ()=>dismiss(el));
    document.getElementById('notif-stack').appendChild(el);
    if (duration>0) setTimeout(()=>dismiss(el), duration);
}
function dismiss(el){
    el.style.cssText+='transition:opacity .3s,transform .3s;opacity:0;transform:translateX(30px)';
    setTimeout(()=>el.remove(), 300);
}

/* ── Modal helpers ──────────────────────────────────────────────────── */
function openModal()  { document.getElementById('aoiModal').classList.add('active'); document.body.style.overflow='hidden'; }
function closeModal() { document.getElementById('aoiModal').classList.remove('active'); document.body.style.overflow=''; if(typeof resetForm==='function') resetForm(); }

/* ── Delete dialog ──────────────────────────────────────────────────── */
let pendingDeleteId = null;
function openDeleteDialog(id, topic) {
    pendingDeleteId = id;
    document.getElementById('deleteMsg').innerHTML =
        `Are you sure you want to delete <strong>${esc(topic || 'this activity')}</strong>? This action cannot be undone.`;
    document.getElementById('deleteDialog').classList.add('active');
}
function closeDeleteDialog() {
    document.getElementById('deleteDialog').classList.remove('active');
    pendingDeleteId = null;
}

/* ── Loading: full buttons (submit, delete confirm) ─────────────────── */
function showLoading(btnEl) {
    if (!btnEl) return;
    btnEl._origHTML = btnEl.innerHTML;
    btnEl.disabled  = true;
    btnEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading…';
}
function hideLoading(btnEl) {
    if (btnEl && btnEl._origHTML !== undefined) {
        btnEl.disabled  = false;
        btnEl.innerHTML = btnEl._origHTML;
        delete btnEl._origHTML;
    }
}

/* ── Loading: small icon-only buttons (.btn-icon) ────────────────────── */
// FIX BUG-9: icon buttons are 30×30 px — replacing innerHTML with text breaks
// the layout. Instead, swap just the <i> child to a spinner and mark the
// button with .is-loading (pointer-events:none in CSS).
function showIconLoading(btnEl) {
    if (!btnEl) return;
    const icon = btnEl.querySelector('i');
    if (icon) {
        icon._origClass   = icon.className;
        icon.className    = 'fas fa-spinner fa-spin';
    }
    btnEl.classList.add('is-loading');
    btnEl.disabled = true;
}
function hideIconLoading(btnEl) {
    if (!btnEl) return;
    const icon = btnEl.querySelector('i');
    if (icon && icon._origClass !== undefined) {
        icon.className = icon._origClass;
        delete icon._origClass;
    }
    btnEl.classList.remove('is-loading');
    btnEl.disabled = false;
}

/* ── Skeleton HTML for table ─────────────────────────────────────────── */
function makeTableSkeleton(rows) {
    let html = '<div class="table-wrap"><table><thead><tr><th>#</th><th>Topic</th><th>Description</th><th>Streams</th><th>Actions</th></tr></thead><tbody>';
    for(let i=0;i<rows;i++) {
        html += `<tr>
            <td><span class="skeleton-cell" style="width:22px"></span></td>
            <td><span class="skeleton-cell" style="width:160px"></span></td>
            <td><span class="skeleton-cell" style="width:90%;display:block;margin-bottom:4px"></span><span class="skeleton-cell" style="width:60%;height:11px"></span></td>
            <td><span class="skeleton-cell" style="width:60px"></span></td>
            <td><span class="skeleton-cell" style="width:65px"></span></td>
        </tr>`;
    }
    return html + '</tbody></table></div>';
}

$(function() {

    /* ── Lazy form load flag ─────────────────────────────────────────── */
    // FIX BUG-8: form is only fetched on first openModal(); subsequent opens
    // reuse the already-loaded form.
    let formLoaded = false;

    function loadFormContent(callback) {
        if (formLoaded) { if (callback) callback(); return; }

        $.ajax({
            url:  'ajax_handlers/aoi_form.php',
            type: 'POST',
            data: { ...params, csrf_token: CSRF },
            success: function(html) {
                formLoaded = true;
                $('#form-container').html(html);
                initFormEvents();
                if (callback) callback();
            },
            error: function() {
                $('#form-container').html(`<div class="empty-state"><i class="fas fa-circle-exclamation"></i><p>Failed to load the form. Please refresh.</p></div>`);
            }
        });
    }

    /* ── Load table content ───────────────────────────────────────── */
    function loadTableContent() {
        $('#table-container').html(makeTableSkeleton(5));
        $.ajax({
            url:  'ajax_handlers/aoi_table.php',
            type: 'POST',
            data: { ...params, csrf_token: CSRF },
            success: function(html) {
                $('#table-container').html(html);
                initTableEvents();
            },
            error: function() {
                $('#table-container').html(`<div class="empty-state" style="padding:40px"><i class="fas fa-circle-exclamation"></i><p>Failed to load data. Please refresh.</p></div>`);
            }
        });
    }

    /* ── Form events (called after AJAX loads form HTML) ─────────── */
    function initFormEvents() {

        window.resetForm = function() {
            const form = document.getElementById('aoi-form');
            if (form) form.reset();
            const action    = document.getElementById('form-action');
            const title     = document.getElementById('form-title');
            const submitBtn = document.getElementById('submit-btn');
            const cancelBtn = document.getElementById('cancel-btn');
            const editId    = document.getElementById('edit-id');
            if (action)    action.value    = 'add';
            if (title)     title.textContent = 'ADD NEW ACTIVITY OF INTEGRATION';
            if (submitBtn) submitBtn.innerHTML = '<i class="fas fa-plus-circle"></i> Add Activity of Integration';
            if (editId)    editId.value    = '';
            if (cancelBtn) cancelBtn.classList.add('hidden');
        };

        $('#aoi-form').off('submit').on('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('submit-btn');
            showLoading(btn);

            $.ajax({
                url:      'ajax_handlers/aoi_actions.php',
                type:     'POST',
                // BUG-11 note: csrf_token is now in the form (aoi_form.php fix),
                // so serialize() carries it. The append below is a harmless fallback.
                data:     $(this).serialize() + '&csrf_token=' + encodeURIComponent(CSRF),
                dataType: 'json',
                success: function(res) {
                    hideLoading(btn);
                    if (res.status === 'success') {
                        toast(res.message, 'success');
                        resetForm();
                        closeModal();
                        loadTableContent();
                    } else {
                        toast(res.message || 'An error occurred.', 'error');
                    }
                },
                error: function() {
                    hideLoading(btn);
                    toast('An error occurred. Please try again.', 'error');
                }
            });
        });

        $('#cancel-btn').off('click').on('click', resetForm);
    }

    /* ── Table events (called after AJAX loads table HTML) ────────── */
    function initTableEvents() {

        function setFormToEdit(id, topic, description) {
            $('#topic').val(topic);
            $('#description').val(description);
            $('#edit-id').val(id);
            $('#form-action').val('edit');
            $('#form-title').text('EDIT ACTIVITY OF INTEGRATION');
            $('#submit-btn').html('<i class="fas fa-save"></i> Update Activity of Integration');
            $('#cancel-btn').removeClass('hidden');
            openModal();
        }

        // Edit button — FIX BUG-9: use showIconLoading instead of showLoading
        $(document).off('click.edit-aoi').on('click.edit-aoi', '.edit-aoi', function() {
            const id  = $(this).data('id');
            const btn = this;
            showIconLoading(btn);

            $.ajax({
                url:      'ajax_handlers/aoi_actions.php',
                type:     'POST',
                data:     { ajax_action: 'get', id, csrf_token: CSRF },
                dataType: 'json',
                success: function(res) {
                    hideIconLoading(btn);
                    if (res.status === 'success') {
                        setFormToEdit(res.data.id, res.data.topic, res.data.description);
                    } else {
                        toast(res.message || 'Failed to load activity.', 'error');
                    }
                },
                error: function() {
                    hideIconLoading(btn);
                    toast('An error occurred. Please try again.', 'error');
                }
            });
        });

        // Delete button — opens custom confirm dialog
        $(document).off('click.delete-aoi').on('click.delete-aoi', '.delete-aoi', function() {
            const id    = $(this).data('id');
            const topic = $(this).data('topic') || 'this activity';
            openDeleteDialog(id, topic);
        });
    }

    /* ── Add button — lazy-load form on first open ─────────────────── */
    $('#addBtn').on('click', function() {
        loadFormContent(function() {
            if (typeof resetForm === 'function') resetForm();
            openModal();
        });
    });

    /* ── Modal / dialog close ────────────────────────────────────── */
    $('#modalCloseBtn').on('click', closeModal);
    $('#aoiModal').on('click', function(e) { if (e.target === this) closeModal(); });

    $('#cancelDeleteBtn').on('click', closeDeleteDialog);
    $('#deleteDialog').on('click', function(e) { if (e.target === this) closeDeleteDialog(); });

    /* ── Confirm delete ──────────────────────────────────────────── */
    $('#confirmDeleteBtn').on('click', function() {
        if (pendingDeleteId === null) return;
        const btn = this;
        showLoading(btn);

        $.ajax({
            url:      'ajax_handlers/aoi_actions.php',
            type:     'POST',
            data:     { ajax_action: 'delete', id: pendingDeleteId, csrf_token: CSRF },
            dataType: 'json',
            success: function(res) {
                // FIX BUG-10: restore button state BEFORE closing dialog so
                // the DOM mutation order is predictable.
                hideLoading(btn);
                closeDeleteDialog();
                if (res.status === 'success') {
                    toast(res.message, 'success');
                    loadTableContent();
                } else {
                    toast(res.message || 'Failed to delete activity.', 'error');
                }
            },
            error: function() {
                hideLoading(btn);
                closeDeleteDialog();
                toast('An error occurred. Please try again.', 'error');
            }
        });
    });

    /* ── Escape key ──────────────────────────────────────────────── */
    $(document).on('keydown', function(e) {
        if (e.key !== 'Escape') return;
        if ($('#aoiModal').hasClass('active'))     closeModal();
        if ($('#deleteDialog').hasClass('active')) closeDeleteDialog();
    });

    /* ── Edit button click from table also lazy-loads form ───────── */
    // The edit flow calls setFormToEdit() which assumes the form is loaded.
    // We intercept the click via initTableEvents(), but if the user clicks
    // edit before ever clicking "Add", the form hasn't been loaded yet.
    // Solution: wrap the AJAX get in initTableEvents with a loadFormContent() call.
    // NOTE: this is already handled by the re-delegated click in initTableEvents
    // which calls setFormToEdit() after the GET response. But we still need the
    // form DOM to exist. Patch: loadFormContent() in the edit click path too.
    $(document).off('click.edit-aoi-lazy').on('click.edit-aoi-lazy', '.edit-aoi', function() {
        if (!formLoaded) loadFormContent(); // pre-fetch silently; edit handler waits for GET anyway
    });

    /* ── Initial load ────────────────────────────────────────────── */
    // FIX BUG-8: do NOT call loadFormContent() here anymore.
    loadTableContent();
});
</script>
</body>
</html>
