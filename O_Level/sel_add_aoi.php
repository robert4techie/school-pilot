<?php
/**
 * sel_add_aoi.php
 *
 * Selection form: choose Class, Term, Year, Stream(s) and Subject
 * before proceeding to add_aoi.php.
 *
 * ── BUGS FIXED ──────────────────────────────────────────────────────────────
 *   BUG-12 The "Proceed to Add AOI" submit button had no loading state.
 *          After clicking, the page could take 1–2 s to redirect while the
 *          button appeared unresponsive, inviting double-clicks that submit the
 *          form twice. Fix: disable the button and show a spinner immediately
 *          on submit (after client-side validation passes).
 *
 *   BUG-13 The CSRF error redirect (?error=csrf) was silently ignored — the
 *          page rendered with no feedback. Fix: detect the GET param on load
 *          and display a toast automatically.
 */

require_once '../auth.php';
require_once '../conn.php';

/* ── CSRF ────────────────────────────────────────────────────────────── */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');

/* ── Allowed values ──────────────────────────────────────────────────── */
$allowed_classes  = ['Senior One', 'Senior Two', 'Senior Three', 'Senior Four'];
$allowed_terms    = ['Term 1', 'Term 2', 'Term 3'];
$allowed_streams  = ['East', 'West', 'South', 'North'];
$current_year     = (int)date('Y');

/* ── Sanitize GET inputs ─────────────────────────────────────────────── */
$sel_class = in_array($_GET['class'] ?? '', $allowed_classes, true)
    ? $_GET['class'] : 'Senior One';

$term = in_array($_GET['term'] ?? '', $allowed_terms, true)
    ? $_GET['term'] : 'Term 1';

$year = (int)($_GET['year'] ?? $current_year);
if ($year < 2000 || $year > $current_year + 5) {
    $year = $current_year;
}

$streams = array_values(array_filter(
    (array)($_GET['streams'] ?? []),
    fn($s) => in_array($s, $allowed_streams, true)
));

$subject = trim($_GET['subject'] ?? '');

/* ── Subjects from DB ────────────────────────────────────────────────── */
function getSubjects(mysqli $conn): array
{
    $stmt = $conn->prepare(
        "SELECT subj_id, subj_name
         FROM subjects
         WHERE level LIKE 'O%'
         ORDER BY subj_name"
    );
    if ($stmt === false) return [];
    $stmt->execute();
    $result   = $stmt->get_result();
    $subjects = [];
    while ($row = $result->fetch_assoc()) {
        $subjects[] = $row;
    }
    $stmt->close();
    return $subjects;
}

$all_subjects = getSubjects($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= $csrf ?>">
<title>Select AOI Parameters &mdash; SchoolPilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── Variables ───────────────────────────────────────────────────────── */
:root {
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--orange:#e65100;--gray:#546e7a;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);
  --shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --tr:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}

/* ── Layout ──────────────────────────────────────────────────────────── */
.page{padding:24px 20px 48px;max-width:1200px;margin:0 auto}

/* ── Page Header ─────────────────────────────────────────────────────── */
.page-header{
  background:linear-gradient(135deg,var(--g900),var(--g700));
  border-radius:var(--radius-lg);padding:28px 32px;
  margin-bottom:24px;margin-top:40px;box-shadow:var(--shadow-lg)
}
.page-header h1{color:#fff;font-size:1.45rem;font-weight:700;display:flex;align-items:center;gap:10px}
.page-header p{color:rgba(255,255,255,.78);font-size:.9rem;margin-top:4px}

/* ── Card ────────────────────────────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden}
.card-head{background:linear-gradient(135deg,var(--g800),var(--g600));padding:18px 24px;display:flex;align-items:center;gap:10px}
.card-head h2{color:#fff;font-size:1rem;font-weight:700}
.card-body{padding:28px 28px 24px}

/* ── Form ────────────────────────────────────────────────────────────── */
.form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:20px}
.form-group label{font-size:.8rem;font-weight:700;color:#3a4a3b;text-transform:uppercase;letter-spacing:.4px;display:flex;align-items:center;gap:6px}
.form-group label .req{color:var(--red)}
.form-control{
  padding:10px 14px;border:1.5px solid #d0dbd1;border-radius:var(--radius);
  font-size:.9rem;font-family:inherit;width:100%;background:#fff;
  transition:border-color var(--tr),box-shadow var(--tr)
}
.form-control:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.1)}
.form-control.is-invalid{border-color:var(--red)}
select.form-control{cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%23388e3c' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;background-size:18px;padding-right:40px}

/* ── Stream selector ─────────────────────────────────────────────────── */
.stream-selector{position:relative;border:1.5px solid #d0dbd1;border-radius:var(--radius);background:#fff;overflow:hidden;transition:border-color var(--tr)}
.stream-selector:focus-within,.stream-selector.open{border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.1)}
.stream-selector.is-invalid{border-color:var(--red)}
.stream-trigger{
  padding:10px 14px;display:flex;align-items:center;justify-content:space-between;
  cursor:pointer;font-size:.9rem;font-family:inherit;background:none;border:none;
  width:100%;text-align:left;color:#555;transition:background var(--tr)
}
.stream-trigger:hover{background:var(--g50)}
.stream-trigger .trigger-text{flex:1}
.stream-trigger .chevron{font-size:.75rem;color:var(--g700);transition:transform var(--tr)}
.stream-selector.open .chevron{transform:rotate(180deg)}
.stream-options{
  max-height:0;overflow:hidden;border-top:0 solid #e0ede0;
  transition:max-height .25s ease,border-top-width .25s ease;background:#fff
}
.stream-selector.open .stream-options{max-height:260px;border-top-width:1px}
.stream-option{
  padding:12px 14px;display:flex;align-items:center;gap:10px;
  cursor:pointer;transition:background var(--tr);font-size:.875rem
}
.stream-option:hover{background:var(--g50)}
.stream-option.selected{background:var(--g100)}
.stream-option input[type=checkbox]{
  width:16px;height:16px;accent-color:var(--g700);cursor:pointer;flex-shrink:0;
  pointer-events:none
}
.stream-option .option-label{flex:1;color:#333}
.stream-option .check-icon{color:var(--g700);font-size:.8rem;visibility:hidden}
.stream-option.selected .check-icon{visibility:visible}
.stream-actions{display:flex;gap:12px;margin-top:8px}
.stream-actions button{background:none;border:none;font-size:.82rem;font-weight:600;color:var(--g700);cursor:pointer;padding:4px 8px;border-radius:4px;font-family:inherit;transition:background var(--tr)}
.stream-actions button:hover{background:var(--g100)}

/* ── Divider ─────────────────────────────────────────────────────────── */
.divider{border:none;border-top:1px solid #e8ede9;margin:24px 0}

/* ── Form actions ────────────────────────────────────────────────────── */
.form-actions{display:flex;justify-content:flex-end;gap:12px;margin-top:4px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border:none;border-radius:var(--radius);font-size:.9rem;font-weight:700;font-family:inherit;cursor:pointer;transition:all var(--tr)}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn:disabled{opacity:.65;cursor:not-allowed;transform:none;box-shadow:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover:not(:disabled){background:var(--g800)}
.btn-outline{background:#fff;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover:not(:disabled){border-color:var(--gray);background:#f5f5f5;transform:none}

/* ── Toasts ──────────────────────────────────────────────────────────── */
#notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.notif{background:#fff;border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow-lg);display:flex;align-items:flex-start;gap:12px;border-left:4px solid var(--g600);animation:notifIn .3s ease}
.notif.error{border-left-color:var(--red)}.notif.warning{border-left-color:var(--orange)}
@keyframes notifIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.notif-icon{font-size:1.1rem;flex-shrink:0;margin-top:1px}
.notif.success .notif-icon{color:var(--g700)}.notif.error .notif-icon{color:var(--red)}.notif.warning .notif-icon{color:var(--orange)}
.notif-body{flex:1}.notif-title{font-weight:700;font-size:.85rem;margin-bottom:2px}.notif-msg{font-size:.8rem;color:#666}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:1rem;line-height:1;flex-shrink:0}

@media(max-width:600px){.page{padding:16px 12px 48px}.page-header{padding:20px}.card-body{padding:20px}}
</style>
</head>

<body>
<?php require_once '../nav.php'; ?>

<div class="page">

  <!-- Page Header -->
  <div class="page-header">
    <h1><i class="fas fa-list-check"></i> Activity of Integration</h1>
    <p>Select the class, term, year, stream(s) and subject to manage activities</p>
  </div>

  <!-- Form Card -->
  <div class="card">
    <div class="card-head">
      <i class="fas fa-sliders" style="color:#fff;font-size:1.1rem"></i>
      <h2>Selection Parameters</h2>
    </div>
    <div class="card-body">
      <form id="sel-form" method="post" action="add_aoi.php" novalidate>
        <!-- CSRF -->
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <!-- Class -->
        <div class="form-group">
          <label for="class"><i class="fas fa-chalkboard-teacher"></i> Select Class <span class="req">*</span></label>
          <select name="class" id="class" class="form-control" required>
            <option value="">— Select Class —</option>
            <?php foreach ($allowed_classes as $c): ?>
              <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>"
                <?= ($sel_class === $c) ? 'selected' : '' ?>>
                <?= htmlspecialchars($c, ENT_QUOTES) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Term -->
        <div class="form-group">
          <label for="term"><i class="fas fa-calendar"></i> Term <span class="req">*</span></label>
          <select name="term" id="term" class="form-control" required>
            <option value="">— Select Term —</option>
            <?php foreach ($allowed_terms as $t): ?>
              <option value="<?= htmlspecialchars($t, ENT_QUOTES) ?>"
                <?= ($term === $t) ? 'selected' : '' ?>>
                <?= htmlspecialchars($t, ENT_QUOTES) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Year -->
        <div class="form-group">
          <label for="year"><i class="fas fa-calendar-days"></i> Academic Year <span class="req">*</span></label>
          <input type="number" name="year" id="year" class="form-control"
                 value="<?= $year ?>" min="2000" max="<?= $current_year + 5 ?>" required>
        </div>

        <!-- Streams -->
        <div class="form-group">
          <label><i class="fas fa-code-branch"></i> Select Stream(s) <span class="req">*</span></label>
          <div class="stream-selector" id="streamSelector">
            <button type="button" class="stream-trigger" id="streamTrigger" aria-haspopup="listbox" aria-expanded="false">
              <span class="trigger-text" id="triggerText">Select streams…</span>
              <i class="fas fa-chevron-down chevron"></i>
            </button>
            <div class="stream-options" role="listbox" aria-multiselectable="true" id="streamOptions">
              <?php foreach ($allowed_streams as $s): ?>
                <div class="stream-option <?= in_array($s, $streams, true) ? 'selected' : '' ?>"
                     data-value="<?= htmlspecialchars($s, ENT_QUOTES) ?>" role="option"
                     aria-selected="<?= in_array($s, $streams, true) ? 'true' : 'false' ?>">
                  <input type="checkbox" name="streams[]"
                         value="<?= htmlspecialchars($s, ENT_QUOTES) ?>"
                         id="stream-<?= htmlspecialchars($s, ENT_QUOTES) ?>"
                         <?= in_array($s, $streams, true) ? 'checked' : '' ?>>
                  <span class="option-label"><?= htmlspecialchars($s, ENT_QUOTES) ?></span>
                  <i class="fas fa-check check-icon"></i>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="stream-actions">
            <button type="button" id="selectAllStreams">Select All</button>
            <button type="button" id="clearStreams">Clear All</button>
          </div>
        </div>

        <!-- Subject -->
        <div class="form-group">
          <label for="subject"><i class="fas fa-book"></i> Subject <span class="req">*</span></label>
          <select name="subject" id="subject" class="form-control" required>
            <option value="">— Select Subject —</option>
            <?php foreach ($all_subjects as $subj): ?>
              <option value="<?= htmlspecialchars($subj['subj_name'], ENT_QUOTES) ?>"
                <?= ($subject === $subj['subj_name']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($subj['subj_name'], ENT_QUOTES) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <hr class="divider">

        <div class="form-actions">
          <a href="index.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
          <!-- FIX BUG-12: spinner applied by JS after validation passes -->
          <button type="submit" class="btn btn-primary" id="proceedBtn">
            <i class="fas fa-arrow-right"></i> Proceed to Add AOI
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<div id="notif-stack" aria-live="polite"></div>

<script src="../assets/js/jquery.min.js"></script>
<script>
/* ── XSS-safe escaper ───────────────────────────────────────────────── */
function esc(s){ const d=document.createElement('div'); d.textContent=String(s??''); return d.innerHTML; }

/* ── Toasts ─────────────────────────────────────────────────────────── */
function toast(message, type='warning', duration=5000) {
    const icons  = { success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
    const titles = { success:'Success', error:'Error', warning:'Required', info:'Note' };
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

// FIX BUG-13: show CSRF error toast if redirected back with ?error=csrf
(function() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('error') === 'csrf') {
        toast('Your session has expired or the security token was invalid. Please try again.', 'error', 8000);
        // Clean the URL without reloading
        history.replaceState(null, '', window.location.pathname);
    }
})();

/* ── Stream dropdown ────────────────────────────────────────────────── */
const selector = document.getElementById('streamSelector');
const trigger  = document.getElementById('streamTrigger');

function toggleDropdown(open) {
    const isOpen = (open !== undefined) ? open : !selector.classList.contains('open');
    selector.classList.toggle('open', isOpen);
    trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
}

function updateTriggerText() {
    const checked = [...document.querySelectorAll('.stream-option input[type=checkbox]:checked')];
    const text = checked.length === 0 ? 'Select streams…'
               : checked.length === 1 ? checked[0].value
               : `${checked.length} streams selected`;
    document.getElementById('triggerText').textContent = text;
    if (checked.length > 0) selector.classList.remove('is-invalid');
}

trigger.addEventListener('click', () => toggleDropdown());

document.querySelectorAll('.stream-option').forEach(opt => {
    opt.addEventListener('click', () => {
        const cb = opt.querySelector('input[type=checkbox]');
        cb.checked = !cb.checked;
        opt.classList.toggle('selected', cb.checked);
        opt.setAttribute('aria-selected', cb.checked ? 'true' : 'false');
        updateTriggerText();
    });
});

document.getElementById('selectAllStreams').addEventListener('click', () => {
    document.querySelectorAll('.stream-option').forEach(opt => {
        opt.querySelector('input').checked = true;
        opt.classList.add('selected');
        opt.setAttribute('aria-selected', 'true');
    });
    updateTriggerText();
});
document.getElementById('clearStreams').addEventListener('click', () => {
    document.querySelectorAll('.stream-option').forEach(opt => {
        opt.querySelector('input').checked = false;
        opt.classList.remove('selected');
        opt.setAttribute('aria-selected', 'false');
    });
    updateTriggerText();
});

document.addEventListener('click', e => {
    if (!selector.contains(e.target)) toggleDropdown(false);
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') toggleDropdown(false);
});

updateTriggerText();

/* ── Form validation + submit spinner ──────────────────────────────── */
document.getElementById('sel-form').addEventListener('submit', function(e) {
    let firstError = null;

    const classEl   = document.getElementById('class');
    const termEl    = document.getElementById('term');
    const yearEl    = document.getElementById('year');
    const subjectEl = document.getElementById('subject');
    const streamsChecked = document.querySelectorAll('.stream-option input[type=checkbox]:checked').length > 0;

    // Clear previous invalid state
    [classEl, termEl, yearEl, subjectEl].forEach(el => el.classList.remove('is-invalid'));
    selector.classList.remove('is-invalid');

    if (!classEl.value) {
        classEl.classList.add('is-invalid');
        if (!firstError) { toast('Please select a class.'); firstError = classEl; }
    }
    if (!termEl.value) {
        termEl.classList.add('is-invalid');
        if (!firstError) { toast('Please select a term.'); firstError = termEl; }
    }
    const yr = parseInt(yearEl.value, 10);
    if (!yearEl.value || yr < 2000 || yr > <?= $current_year + 5 ?>) {
        yearEl.classList.add('is-invalid');
        if (!firstError) { toast('Please enter a valid academic year.'); firstError = yearEl; }
    }
    if (!streamsChecked) {
        selector.classList.add('is-invalid');
        toggleDropdown(true);
        if (!firstError) { toast('Please select at least one stream.'); firstError = trigger; }
    }
    if (!subjectEl.value) {
        subjectEl.classList.add('is-invalid');
        if (!firstError) { toast('Please select a subject.'); firstError = subjectEl; }
    }

    if (firstError) {
        e.preventDefault();
        firstError.focus();
        return; // do NOT show spinner — form has errors
    }

    // FIX BUG-12: all fields valid — show spinner and disable to prevent double-submit
    const btn = document.getElementById('proceedBtn');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading…';
    // Form submission proceeds naturally (no e.preventDefault())
});
</script>
</body>
</html>
