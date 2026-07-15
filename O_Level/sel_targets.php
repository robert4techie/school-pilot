<?php
/*
 * sel_targets.php  –  Step 1: Select class / streams / subject / term / year
 * Place in the same directory as sel_add_marks.php  (O_Level/).
 */
require_once '../auth.php';
require_once '../conn.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$allowed_roles = ['developer', 'super user', 'subject teacher', 'class teacher', 'school leader'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles, true)) {
    header('Location: ../index.php');
    exit;
}

$role     = $_SESSION['role'];
$staff_id = $_SESSION['staff_id'] ?? '';
$is_super = in_array($role, ['developer', 'super user', 'school leader']);

$sel_class   = isset($_GET['class'])   ? htmlspecialchars(trim($_GET['class']))   : '';
$sel_term    = isset($_GET['term'])    ? htmlspecialchars(trim($_GET['term']))     : '';
$sel_year    = isset($_GET['year'])    ? (int)$_GET['year']                       : (int)date('Y');
$sel_streams = isset($_GET['streams']) ? array_map('trim', (array)$_GET['streams']) : [];
$sel_subject = isset($_GET['subject']) ? htmlspecialchars(trim($_GET['subject']))  : '';

function getStreamsForTargets(mysqli $conn): array {
    $r = mysqli_query($conn, "SELECT DISTINCT stream_name FROM streams WHERE status='active' ORDER BY stream_name");
    $list = [];
    if ($r) while ($row = mysqli_fetch_assoc($r)) $list[] = $row['stream_name'];
    return $list ?: ['East', 'West', 'South', 'North'];
}

function getSubjectsForTargets(mysqli $conn, string $class, string $staff_id, bool $is_super): array {
    $level = 'O';
    $s = mysqli_prepare($conn, "SELECT level FROM classes WHERE LOWER(class_name)=LOWER(?) LIMIT 1");
    if ($s) {
        mysqli_stmt_bind_param($s, 's', $class);
        mysqli_stmt_execute($s);
        $r = mysqli_stmt_get_result($s);
        if ($row = mysqli_fetch_assoc($r)) $level = $row['level'];
        mysqli_stmt_close($s);
    }
    if ($is_super) {
        $s = mysqli_prepare($conn, "SELECT subj_id, subj_name FROM subjects WHERE level LIKE ? ORDER BY subj_name");
        $like = $level . '%';
        mysqli_stmt_bind_param($s, 's', $like);
        mysqli_stmt_execute($s);
        $res = mysqli_stmt_get_result($s);
        $list = [];
        while ($row = mysqli_fetch_assoc($res)) $list[] = $row;
        mysqli_stmt_close($s);
        return $list;
    }
    $s = mysqli_prepare($conn,
        "SELECT DISTINCT sub.subj_id, sub.subj_name
         FROM subjects sub
         INNER JOIN teaching_assignments ta ON ta.subject_id = sub.subj_id
         WHERE ta.staff_id = ? AND ta.class_name = ? AND sub.level LIKE ?
         ORDER BY sub.subj_name");
    $like = $level . '%';
    mysqli_stmt_bind_param($s, 'sss', $staff_id, $class, $like);
    mysqli_stmt_execute($s);
    $res = mysqli_stmt_get_result($s);
    $list = [];
    while ($row = mysqli_fetch_assoc($res)) $list[] = $row;
    mysqli_stmt_close($s);
    return $list;
}

$all_streams  = getStreamsForTargets($conn);
$all_subjects = !empty($sel_class) ? getSubjectsForTargets($conn, $sel_class, $staff_id, $is_super) : [];
$all_classes  = ['Senior One', 'Senior Two', 'Senior Three', 'Senior Four'];
$all_terms    = ['Term 1', 'Term 2', 'Term 3'];

require_once '../nav.php';
?>
<style>
/* ══════════════════════════════════════════════════
   sel_targets.php – Page styles
   ══════════════════════════════════════════════════ */
:root {
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --sp-red:#c62828;--sp-orange:#e65100;--sp-blue:#1565c0;
  --sp-radius:8px;--sp-radius-lg:12px;
  --sp-shadow:0 2px 8px rgba(0,0,0,.10);--sp-shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --sp-transition:.22s ease;
}

.tgt-page{max-width:860px;margin:0 auto;padding:0 20px 60px}

.tgt-page-header{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--sp-radius-lg);padding:26px 30px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:18px;margin-bottom:24px;box-shadow:var(--sp-shadow-lg)}
.tgt-ph-left h1{color:#fff;font-size:1.35rem;font-weight:700;margin-bottom:3px}
.tgt-ph-left p{color:rgba(255,255,255,.8);font-size:.875rem}
.tgt-steps{display:flex;align-items:center;gap:6px}
.tgt-step-dot{width:32px;height:32px;border-radius:50%;border:2px solid rgba(255,255,255,.35);display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;color:rgba(255,255,255,.6)}
.tgt-step-dot.active{background:#fff;color:var(--g800);border-color:#fff}
.tgt-step-line{width:20px;height:2px;background:rgba(255,255,255,.3)}

.tgt-card{background:#fff;border-radius:var(--sp-radius-lg);box-shadow:var(--sp-shadow);overflow:visible}
.tgt-card-header{padding:18px 22px 16px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #f0f4f1}
.tgt-card-title{font-weight:700;font-size:1rem;color:#1a1a1a;display:flex;align-items:center;gap:8px}
.tgt-card-title i{color:var(--g700)}
.tgt-card-body{padding:24px 22px}

.tgt-form-row{display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media(max-width:540px){.tgt-form-row{grid-template-columns:1fr}}
.tgt-form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:18px}
.tgt-form-label{font-weight:600;font-size:.85rem;color:#333}
.tgt-form-label sup{color:var(--sp-red)}
.tgt-form-select,.tgt-form-control{width:100%;padding:10px 14px;border:1.5px solid #d0dbd1;border-radius:var(--sp-radius);font-size:.9rem;font-family:inherit;color:#222;background:#fff;transition:border-color var(--sp-transition),box-shadow var(--sp-transition)}
.tgt-form-select:focus,.tgt-form-control:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.tgt-form-hint{font-size:.78rem;color:#888;margin-top:2px}

.ms-wrap{position:relative}
.ms-trigger{width:100%;padding:10px 38px 10px 14px;border:1.5px solid #d0dbd1;border-radius:var(--sp-radius);font-size:.9rem;font-family:inherit;color:#222;background:#fff;cursor:pointer;text-align:left;display:flex;align-items:center;justify-content:space-between;transition:border-color var(--sp-transition),box-shadow var(--sp-transition)}
.ms-trigger:focus,.ms-trigger.ms-open{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.ms-trigger:disabled{background:#f5f5f5;color:#aaa;cursor:not-allowed;border-color:#e0e0e0}
.ms-trigger .ms-arrow{transition:transform .2s;flex-shrink:0}
.ms-trigger.ms-open .ms-arrow{transform:rotate(180deg)}
.ms-dropdown{position:absolute;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1.5px solid #d0dbd1;border-radius:var(--sp-radius);box-shadow:var(--sp-shadow-lg);z-index:500;max-height:220px;overflow-y:auto;display:none}
.ms-dropdown.ms-open{display:block}
.ms-option{display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;font-size:.875rem;border-bottom:1px solid #f0f4f1;transition:background var(--sp-transition)}
.ms-option:last-child{border-bottom:none}
.ms-option:hover{background:var(--g50)}
.ms-option.ms-selected{background:var(--g50)}
.ms-option input{width:16px;height:16px;accent-color:var(--g700);cursor:pointer;flex-shrink:0}
.ms-footer{padding:8px 14px;display:flex;gap:12px;border-top:1px solid #f0f4f1;background:#fafafa;position:sticky;bottom:0}
.ms-footer button{background:none;border:none;font-size:.77rem;font-weight:600;color:var(--g700);cursor:pointer;padding:2px 0;font-family:inherit}
.sel-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.sel-chip{display:inline-flex;align-items:center;gap:5px;background:var(--g100);color:var(--g800);font-size:.75rem;font-weight:600;padding:4px 10px;border-radius:20px}
.subj-search-bar{padding:8px 14px;border-bottom:1px solid #f0f4f1;position:sticky;top:0;background:#fff;z-index:1}
.subj-search-bar input{width:100%;padding:7px 10px;border:1.5px solid #d0dbd1;border-radius:6px;font-size:.83rem;font-family:inherit}
.subj-search-bar input:focus{outline:none;border-color:var(--g600)}

.tgt-form-actions{display:flex;justify-content:flex-end;padding-top:20px;border-top:1px solid #e8ede9;margin-top:6px}
.tgt-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border:none;border-radius:var(--sp-radius);font-size:.88rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all var(--sp-transition);white-space:nowrap}
.tgt-btn-primary{background:var(--g700);color:#fff}.tgt-btn-primary:hover{background:var(--g800)}
.tgt-btn-primary:disabled{background:#aaa;cursor:not-allowed}
.tgt-btn-lg{padding:11px 24px;font-size:.92rem}

.tgt-info-box{background:#e8f5e9;border:1px solid #a5d6a7;border-radius:var(--sp-radius);padding:13px 16px;display:flex;gap:11px;align-items:flex-start;font-size:.83rem;color:var(--g900);margin-bottom:22px}
.tgt-info-box i{color:var(--g700);margin-top:1px;flex-shrink:0}

#tgt-notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.tgt-notif{background:#fff;border-radius:var(--sp-radius);padding:14px 16px;box-shadow:var(--sp-shadow-lg);display:flex;align-items:flex-start;gap:12px;border-left:4px solid var(--g600);animation:tgtNotifIn .3s ease}
.tgt-notif.error{border-left-color:var(--sp-red)}.tgt-notif.warning{border-left-color:var(--sp-orange)}
@keyframes tgtNotifIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.tgt-notif-icon{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.tgt-notif.success .tgt-notif-icon{color:var(--g700)}.tgt-notif.error .tgt-notif-icon{color:var(--sp-red)}.tgt-notif.warning .tgt-notif-icon{color:var(--sp-orange)}
.tgt-notif-body{flex:1}.tgt-notif-title{font-weight:700;font-size:.85rem;margin-bottom:2px}.tgt-notif-msg{font-size:.8rem;color:#666}
.tgt-notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:1rem;padding:0;line-height:1;flex-shrink:0}
</style>

<div class="main-content">
<div class="tgt-page">

  <div class="tgt-page-header">
    <div class="tgt-ph-left">
      <h1><i class="fas fa-bullseye" style="margin-right:9px"></i>Target Marks</h1>
      <p>Set expected performance targets for students</p>
    </div>
    <div class="tgt-steps">
      <div class="tgt-step-dot active">1</div>
      <div class="tgt-step-line"></div>
      <div class="tgt-step-dot">2</div>
    </div>
  </div>

  <div class="tgt-info-box">
    <i class="fas fa-circle-info"></i>
    <span>Select the class, streams, subject, and term below. You will then enter a target percentage
    for each student — the system automatically breaks it into <strong>AOI (/20)</strong>
    and <strong>EOT (/80)</strong> and shows it alongside actual exam marks.</span>
  </div>

  <div class="tgt-card">
    <div class="tgt-card-header">
      <span class="tgt-card-title"><i class="fas fa-sliders"></i> Select Class &amp; Subject</span>
    </div>
    <div class="tgt-card-body">

      <form id="sel-targets-form" method="GET" action="enter_targets.php" autocomplete="off">

        <div class="tgt-form-row">
          <div class="tgt-form-group">
            <label class="tgt-form-label" for="tgt-class">Class <sup>*</sup></label>
            <select name="class" id="tgt-class" class="tgt-form-select" required>
              <option value="">— Select class —</option>
              <?php foreach ($all_classes as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"
                  <?= $sel_class === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="tgt-form-group">
            <label class="tgt-form-label" for="tgt-term">Term <sup>*</sup></label>
            <select name="term" id="tgt-term" class="tgt-form-select" required>
              <option value="">— Select term —</option>
              <?php foreach ($all_terms as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>"
                  <?= $sel_term === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="tgt-form-group" style="max-width:180px">
          <label class="tgt-form-label" for="tgt-year">Academic Year <sup>*</sup></label>
          <input type="number" name="year" id="tgt-year" class="tgt-form-control"
                 value="<?= $sel_year ?>" min="2000" max="2099" required>
        </div>

        <!-- Streams multi-select -->
        <div class="tgt-form-group">
          <label class="tgt-form-label">Streams <sup>*</sup></label>
          <div class="ms-wrap" id="stream-ms">
            <button type="button" class="ms-trigger" id="stream-trigger">
              <span id="stream-label">Select streams…</span>
              <i class="fas fa-chevron-down ms-arrow" style="font-size:.75rem;color:#8a9a8b"></i>
            </button>
            <div class="ms-dropdown" id="stream-dropdown">
              <?php foreach ($all_streams as $s):
                $chk = in_array($s, $sel_streams, true); ?>
                <label class="ms-option <?= $chk ? 'ms-selected' : '' ?>">
                  <input type="checkbox" name="streams[]" value="<?= htmlspecialchars($s) ?>"
                    <?= $chk ? 'checked' : '' ?>>
                  <?= htmlspecialchars($s) ?>
                </label>
              <?php endforeach; ?>
              <div class="ms-footer">
                <button type="button" id="sel-all-streams">Select all</button>
                <button type="button" id="desel-all-streams">Clear</button>
              </div>
            </div>
          </div>
          <div class="sel-chips" id="stream-chips"></div>
          <p class="tgt-form-hint">Select one or more streams to include</p>
        </div>

        <!-- Subject single-select -->
        <div class="tgt-form-group">
          <label class="tgt-form-label">Subject <sup>*</sup></label>
          <div class="ms-wrap" id="subj-ms">
            <button type="button" class="ms-trigger" id="subj-trigger"
              <?= empty($sel_class) ? 'disabled' : '' ?>>
              <span id="subj-label">
                <?= empty($sel_class) ? 'Select class first…' : 'Select subject…' ?>
              </span>
              <i class="fas fa-chevron-down ms-arrow" style="font-size:.75rem;color:#8a9a8b"></i>
            </button>
            <div class="ms-dropdown" id="subj-dropdown">
              <div class="subj-search-bar">
                <input type="text" id="subj-search" placeholder="Search subject…">
              </div>
              <div id="subj-options">
                <?php foreach ($all_subjects as $sub): ?>
                  <label class="ms-option <?= $sel_subject === $sub['subj_name'] ? 'ms-selected' : '' ?>"
                         data-name="<?= htmlspecialchars(strtolower($sub['subj_name'])) ?>">
                    <input type="radio" name="subject" value="<?= htmlspecialchars($sub['subj_name']) ?>"
                      <?= $sel_subject === $sub['subj_name'] ? 'checked' : '' ?>>
                    <?= htmlspecialchars($sub['subj_name']) ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <p class="tgt-form-hint" id="subj-hint">
            <?= empty($sel_class)
                  ? 'Subjects will load after selecting a class'
                  : count($all_subjects) . ' subject' . (count($all_subjects) !== 1 ? 's' : '') . ' available' ?>
          </p>
        </div>

        <div class="tgt-form-actions">
          <button type="submit" class="tgt-btn tgt-btn-primary tgt-btn-lg" id="proceed-btn">
            <i class="fas fa-arrow-right"></i> Proceed to Enter Targets
          </button>
        </div>

      </form>

    </div>
  </div>

</div><!-- /.tgt-page -->
</div><!-- /.main-content -->

<div id="tgt-notif-stack"></div>

<script>
(function () {

function escH(v) {
    return String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function notify(title, msg, type) {
    type = type || 'success';
    const icons = {success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation'};
    const n = document.createElement('div');
    n.className = 'tgt-notif ' + type;
    n.innerHTML = `<i class="fas ${icons[type]||icons.success} tgt-notif-icon"></i>
      <div class="tgt-notif-body"><div class="tgt-notif-title">${escH(title)}</div><div class="tgt-notif-msg">${escH(msg)}</div></div>
      <button class="tgt-notif-close" onclick="this.closest('.tgt-notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('tgt-notif-stack').prepend(n);
    setTimeout(() => { n.style.opacity='0'; n.style.transform='translateX(30px)'; n.style.transition='.3s'; setTimeout(()=>n.remove(),300); }, 4000);
}

// ── Stream multi-select ──────────────────────────────────────
const streamTrigger = document.getElementById('stream-trigger');
const streamDd      = document.getElementById('stream-dropdown');
const streamLabel   = document.getElementById('stream-label');
const streamChips   = document.getElementById('stream-chips');

function updateStreamUI() {
    const checked = [...document.querySelectorAll('#stream-dropdown input:checked')].map(el => el.value);
    streamLabel.textContent = checked.length === 0 ? 'Select streams…'
        : checked.length === 1 ? checked[0]
        : checked.length + ' streams selected';
    streamChips.innerHTML = checked.map(v =>
        `<span class="sel-chip"><i class="fas fa-layer-group" style="font-size:.65rem"></i>${escH(v)}</span>`
    ).join('');
    document.querySelectorAll('#stream-dropdown .ms-option').forEach(opt => {
        opt.classList.toggle('ms-selected', opt.querySelector('input').checked);
    });
}

streamTrigger.addEventListener('click', e => {
    e.stopPropagation();
    streamDd.classList.toggle('ms-open');
    streamTrigger.classList.toggle('ms-open');
});
document.getElementById('sel-all-streams').addEventListener('click', () => {
    document.querySelectorAll('#stream-dropdown input').forEach(cb => cb.checked = true);
    updateStreamUI();
});
document.getElementById('desel-all-streams').addEventListener('click', () => {
    document.querySelectorAll('#stream-dropdown input').forEach(cb => cb.checked = false);
    updateStreamUI();
});
document.querySelectorAll('#stream-dropdown input').forEach(cb => cb.addEventListener('change', updateStreamUI));
updateStreamUI();

// ── Subject single-select ────────────────────────────────────
const subjTrigger = document.getElementById('subj-trigger');
const subjDd      = document.getElementById('subj-dropdown');
const subjLabel   = document.getElementById('subj-label');
const subjHint    = document.getElementById('subj-hint');
const classEl     = document.getElementById('tgt-class');

function bindSubjOptions() {
    document.querySelectorAll('#subj-options input').forEach(radio => {
        radio.addEventListener('change', () => {
            document.querySelectorAll('#subj-options .ms-option').forEach(o => o.classList.remove('ms-selected'));
            radio.closest('.ms-option').classList.add('ms-selected');
            subjLabel.textContent = radio.value;
            subjDd.classList.remove('ms-open');
            subjTrigger.classList.remove('ms-open');
        });
    });
}
bindSubjOptions();
const checkedRadio = document.querySelector('#subj-options input:checked');
if (checkedRadio) subjLabel.textContent = checkedRadio.value;

subjTrigger.addEventListener('click', e => {
    if (subjTrigger.disabled) return;
    e.stopPropagation();
    subjDd.classList.toggle('ms-open');
    subjTrigger.classList.toggle('ms-open');
});

document.getElementById('subj-search').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#subj-options .ms-option').forEach(opt => {
        opt.style.display = opt.dataset.name.includes(q) ? '' : 'none';
    });
});

classEl.addEventListener('change', function () {
    const cls = this.value;
    if (!cls) {
        subjTrigger.disabled = true;
        subjLabel.textContent = 'Select class first…';
        subjHint.textContent  = 'Subjects will load after selecting a class';
        return;
    }
    subjTrigger.disabled  = false;
    subjLabel.textContent = 'Loading…';
    fetch('get_subjects.php?class=' + encodeURIComponent(cls))
        .then(r => r.json())
        .then(data => {
            const opts = document.getElementById('subj-options');
            opts.innerHTML = data.map(s =>
                `<label class="ms-option" data-name="${escH(s.subj_name.toLowerCase())}">
                    <input type="radio" name="subject" value="${escH(s.subj_name)}">${escH(s.subj_name)}
                 </label>`
            ).join('');
            bindSubjOptions();
            subjLabel.textContent = 'Select subject…';
            subjHint.textContent  = data.length + ' subject' + (data.length !== 1 ? 's' : '') + ' available';
        })
        .catch(() => {
            subjLabel.textContent = 'Select subject…';
            subjHint.textContent  = 'Failed to load subjects.';
            notify('Error', 'Could not load subjects. Refresh and try again.', 'error');
        });
});

// ── Close dropdowns on outside click ────────────────────────
document.addEventListener('click', () => {
    [streamDd, subjDd].forEach(dd => dd.classList.remove('ms-open'));
    [streamTrigger, subjTrigger].forEach(t => t.classList.remove('ms-open'));
});
[streamDd, subjDd].forEach(dd => dd.addEventListener('click', e => e.stopPropagation()));

// ── Form validation ──────────────────────────────────────────
document.getElementById('sel-targets-form').addEventListener('submit', function (e) {
    const cls     = document.getElementById('tgt-class').value;
    const term    = document.getElementById('tgt-term').value;
    const year    = document.getElementById('tgt-year').value;
    const streams = [...document.querySelectorAll('#stream-dropdown input:checked')];
    const subj    = document.querySelector('#subj-options input:checked');

    if (!cls)            { e.preventDefault(); notify('Missing field', 'Please select a class.', 'warning'); return; }
    if (!term)           { e.preventDefault(); notify('Missing field', 'Please select a term.', 'warning'); return; }
    if (!year)           { e.preventDefault(); notify('Missing field', 'Please enter the academic year.', 'warning'); return; }
    if (!streams.length) { e.preventDefault(); notify('Missing field', 'Please select at least one stream.', 'warning'); return; }
    if (!subj)           { e.preventDefault(); notify('Missing field', 'Please select a subject.', 'warning'); return; }

    const btn = document.getElementById('proceed-btn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading…';
    btn.disabled  = true;
});

})();
</script>
