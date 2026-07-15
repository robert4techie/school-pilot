<?php
/*
 * sel_alevel_targets.php  –  Step 1: Select A-Level class / streams / subject / term / year
 * Place in the same directory as sel_targets.php  (O_Level/).
 */
require_once '../auth.php';
require_once '../conn.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$allowed_roles = ['developer', 'super user', 'subject teacher', 'class teacher', 'school leader'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles, true)) {
    header('Location: ../index.php'); exit;
}
$role     = $_SESSION['role'];
$staff_id = $_SESSION['staff_id'] ?? '';
$is_super = in_array($role, ['developer', 'super user', 'school leader']);

// ── Inline AJAX: get A-level subjects for selected class ──────
if (isset($_GET['get_alevel_subjects'])) {
    header('Content-Type: application/json');
    $cls = trim($_GET['class'] ?? '');
    echo json_encode(getALevelSubjects($conn, $cls, $staff_id, $is_super));
    exit;
}

$sel_class   = isset($_GET['class'])   ? htmlspecialchars(trim($_GET['class']))   : '';
$sel_term    = isset($_GET['term'])    ? htmlspecialchars(trim($_GET['term']))     : '';
$sel_year    = isset($_GET['year'])    ? (int)$_GET['year']                       : (int)date('Y');
$sel_streams = isset($_GET['streams']) ? array_map('trim', (array)$_GET['streams']) : [];
$sel_subject = isset($_GET['subject']) ? htmlspecialchars(trim($_GET['subject']))  : '';

function getALevelSubjects(mysqli $conn, string $class, string $staff_id, bool $is_super): array {
    if (empty($class)) return [];
    if ($is_super) {
        $s = mysqli_prepare($conn, "SELECT subj_id, subj_name FROM subjects WHERE level LIKE '%A%' ORDER BY subj_name");
        mysqli_stmt_execute($s, []);
        $list = [];
        $res = mysqli_stmt_get_result($s);
        while ($r = mysqli_fetch_assoc($res)) $list[] = $r;
        mysqli_stmt_close($s);
        return $list;
    }
    $s = mysqli_prepare($conn,
        "SELECT DISTINCT sub.subj_id, sub.subj_name
         FROM subjects sub
         INNER JOIN teaching_assignments ta ON ta.subject_id = sub.subj_id
         WHERE ta.staff_id = ? AND ta.class_name = ? AND sub.level LIKE '%A%'
         ORDER BY sub.subj_name");
    mysqli_stmt_execute($s, [$staff_id, $class]);
    $list = [];
    $res = mysqli_stmt_get_result($s);
    while ($r = mysqli_fetch_assoc($res)) $list[] = $r;
    mysqli_stmt_close($s);
    return $list;
}

function getALevelStreams(mysqli $conn): array {
    $r = mysqli_query($conn, "SELECT DISTINCT stream_name FROM streams WHERE status='active' ORDER BY stream_name");
    $list = [];
    if ($r) while ($row = mysqli_fetch_assoc($r)) $list[] = $row['stream_name'];
    return $list ?: ['Arts', 'Sciences'];
}

$all_streams  = getALevelStreams($conn);
$all_subjects = !empty($sel_class) ? getALevelSubjects($conn, $sel_class, $staff_id, $is_super) : [];
$all_classes  = ['Senior Five', 'Senior Six'];
$all_terms    = ['Term 1', 'Term 2', 'Term 3'];

require_once '../nav.php';
?>
<style>
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --sp-red:#c62828;--sp-orange:#e65100;
  --sp-rad:8px;--sp-radlg:12px;
  --sp-sh:0 2px 8px rgba(0,0,0,.10);--sp-shlg:0 8px 28px rgba(0,0,0,.14);
  --sp-tr:.22s ease;
}
.tgt-page{max-width:1000px;margin:0 auto;padding:0 20px 60px}
.tgt-ph{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--sp-radlg);padding:26px 30px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:18px;margin-bottom:22px;box-shadow:var(--sp-shlg)}
.tgt-ph h1{color:#fff;font-size:1.35rem;font-weight:700;margin-bottom:3px}
.tgt-ph p{color:rgba(255,255,255,.8);font-size:.875rem}
.tgt-steps{display:flex;align-items:center;gap:6px}
.tgt-step-dot{width:32px;height:32px;border-radius:50%;border:2px solid rgba(255,255,255,.35);display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;color:rgba(255,255,255,.6)}
.tgt-step-dot.active{background:#fff;color:var(--g800);border-color:#fff}
.tgt-step-line{width:20px;height:2px;background:rgba(255,255,255,.3)}
.alevel-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:20px;padding:5px 14px;font-size:.8rem;color:#fff;font-weight:700;margin-bottom:12px}

.tgt-card{background:#fff;border-radius:var(--sp-radlg);box-shadow:var(--sp-sh);overflow:visible}
.tgt-card-header{padding:18px 22px 16px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #f0f4f1}
.tgt-card-title{font-weight:700;font-size:1rem;color:#1a1a1a;display:flex;align-items:center;gap:8px}
.tgt-card-title i{color:var(--g700)}
.tgt-card-body{padding:24px 22px}

.tgt-form-row{display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media(max-width:540px){.tgt-form-row{grid-template-columns:1fr}}
.tgt-fg{display:flex;flex-direction:column;gap:6px;margin-bottom:18px}
.tgt-lbl{font-weight:600;font-size:.85rem;color:#333}
.tgt-lbl sup{color:var(--sp-red)}
.tgt-sel,.tgt-inp{width:100%;padding:10px 14px;border:1.5px solid #d0dbd1;border-radius:var(--sp-rad);font-size:.9rem;font-family:inherit;color:#222;background:#fff;transition:border-color var(--sp-tr),box-shadow var(--sp-tr)}
.tgt-sel:focus,.tgt-inp:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.tgt-hint{font-size:.78rem;color:#888;margin-top:2px}

.ms-wrap{position:relative}
.ms-trigger{width:100%;padding:10px 38px 10px 14px;border:1.5px solid #d0dbd1;border-radius:var(--sp-rad);font-size:.9rem;font-family:inherit;color:#222;background:#fff;cursor:pointer;text-align:left;display:flex;align-items:center;justify-content:space-between;transition:border-color var(--sp-tr),box-shadow var(--sp-tr)}
.ms-trigger:focus,.ms-trigger.ms-open{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.ms-trigger:disabled{background:#f5f5f5;color:#aaa;cursor:not-allowed;border-color:#e0e0e0}
.ms-trigger .ms-arr{transition:transform .2s;flex-shrink:0}
.ms-trigger.ms-open .ms-arr{transform:rotate(180deg)}
.ms-dd{position:absolute;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1.5px solid #d0dbd1;border-radius:var(--sp-rad);box-shadow:var(--sp-shlg);z-index:500;max-height:220px;overflow-y:auto;display:none}
.ms-dd.ms-open{display:block}
.ms-opt{display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;font-size:.875rem;border-bottom:1px solid #f0f4f1;transition:background var(--sp-tr)}
.ms-opt:last-child{border-bottom:none}
.ms-opt:hover,.ms-opt.ms-sel{background:var(--g50)}
.ms-opt input{width:16px;height:16px;accent-color:var(--g700);cursor:pointer;flex-shrink:0}
.ms-footer{padding:8px 14px;display:flex;gap:12px;border-top:1px solid #f0f4f1;background:#fafafa;position:sticky;bottom:0}
.ms-footer button{background:none;border:none;font-size:.77rem;font-weight:600;color:var(--g700);cursor:pointer;padding:2px 0;font-family:inherit}
.sel-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.sel-chip{display:inline-flex;align-items:center;gap:5px;background:var(--g100);color:var(--g800);font-size:.75rem;font-weight:600;padding:4px 10px;border-radius:20px}
.subj-sb{padding:8px 14px;border-bottom:1px solid #f0f4f1;position:sticky;top:0;background:#fff;z-index:1}
.subj-sb input{width:100%;padding:7px 10px;border:1.5px solid #d0dbd1;border-radius:6px;font-size:.83rem;font-family:inherit}
.subj-sb input:focus{outline:none;border-color:var(--g600)}

.tgt-actions{display:flex;justify-content:flex-end;padding-top:20px;border-top:1px solid #e8ede9;margin-top:6px}
.tgt-btn{display:inline-flex;align-items:center;gap:7px;padding:11px 24px;border:none;border-radius:var(--sp-rad);font-size:.92rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all var(--sp-tr)}
.tgt-btn-primary{background:var(--g700);color:#fff}.tgt-btn-primary:hover{background:var(--g800)}
.tgt-btn-primary:disabled{background:#aaa;cursor:not-allowed}

.tgt-info{background:#e8f5e9;border:1px solid #a5d6a7;border-radius:var(--sp-rad);padding:13px 16px;display:flex;gap:11px;align-items:flex-start;font-size:.83rem;color:var(--g900);margin-bottom:22px}
.tgt-info i{color:var(--g700);margin-top:1px;flex-shrink:0}

#tgt-notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.tgt-notif{background:#fff;border-radius:var(--sp-rad);padding:13px 15px;box-shadow:var(--sp-shlg);display:flex;align-items:flex-start;gap:11px;border-left:4px solid var(--g600);animation:tNIn .3s ease}
.tgt-notif.error{border-left-color:var(--sp-red)}.tgt-notif.warning{border-left-color:var(--sp-orange)}
@keyframes tNIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.tgt-ni{font-size:1rem;margin-top:1px;flex-shrink:0;color:var(--g600)}
.tgt-notif.error .tgt-ni{color:var(--sp-red)}.tgt-notif.warning .tgt-ni{color:var(--sp-orange)}
.tgt-nb{flex:1}.tgt-nt{font-weight:700;font-size:.84rem;margin-bottom:2px}.tgt-nm{font-size:.78rem;color:#666}
.tgt-nc{background:none;border:none;cursor:pointer;color:#aaa;font-size:.95rem;padding:0;line-height:1}
</style>

<div class="main-content">
<div class="tgt-page">

<div class="tgt-ph">
  <div>
    <div class="alevel-badge"><i class="fas fa-graduation-cap"></i> A-LEVEL</div>
    <h1><i class="fas fa-bullseye" style="margin-right:8px"></i>A-Level Target Marks</h1>
    <p>Set MID and EOT exam targets for A-level students</p>
  </div>
  <div class="tgt-steps">
    <div class="tgt-step-dot active">1</div>
    <div class="tgt-step-line"></div>
    <div class="tgt-step-dot">2</div>
  </div>
</div>

<div class="tgt-info">
  <i class="fas fa-circle-info"></i>
  <span>Select the class, streams, subject and term. You will then enter a target percentage
  for each student for <strong>MID</strong> and <strong>EOT</strong> exams (each out of 100%).</span>
</div>

<div class="tgt-card">
  <div class="tgt-card-header">
    <span class="tgt-card-title"><i class="fas fa-sliders"></i> Select Class &amp; Subject</span>
  </div>
  <div class="tgt-card-body">

    <form id="sel-al-form" method="GET" action="enter_alevel_targets.php" autocomplete="off">

      <div class="tgt-form-row">
        <div class="tgt-fg">
          <label class="tgt-lbl" for="al-class">Class <sup>*</sup></label>
          <select name="class" id="al-class" class="tgt-sel" required>
            <option value="">— Select class —</option>
            <?php foreach ($all_classes as $c): ?>
              <option value="<?= htmlspecialchars($c) ?>"
                <?= $sel_class===$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="tgt-fg">
          <label class="tgt-lbl" for="al-term">Term <sup>*</sup></label>
          <select name="term" id="al-term" class="tgt-sel" required>
            <option value="">— Select term —</option>
            <?php foreach ($all_terms as $t): ?>
              <option value="<?= htmlspecialchars($t) ?>"
                <?= $sel_term===$t?'selected':'' ?>><?= htmlspecialchars($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="tgt-fg" style="max-width:180px">
        <label class="tgt-lbl" for="al-year">Academic Year <sup>*</sup></label>
        <input type="number" name="year" id="al-year" class="tgt-inp"
               value="<?= $sel_year ?>" min="2000" max="2099" required>
      </div>

      <!-- Streams multi-select -->
      <div class="tgt-fg">
        <label class="tgt-lbl">Streams <sup>*</sup></label>
        <div class="ms-wrap" id="al-stream-ms">
          <button type="button" class="ms-trigger" id="al-stream-trigger">
            <span id="al-stream-label">Select streams…</span>
            <i class="fas fa-chevron-down ms-arr" style="font-size:.75rem;color:#8a9a8b"></i>
          </button>
          <div class="ms-dd" id="al-stream-dd">
            <?php foreach ($all_streams as $s):
              $chk = in_array($s, $sel_streams, true); ?>
              <label class="ms-opt <?= $chk?'ms-sel':'' ?>">
                <input type="checkbox" name="streams[]" value="<?= htmlspecialchars($s) ?>"
                  <?= $chk?'checked':'' ?>>
                <?= htmlspecialchars($s) ?>
              </label>
            <?php endforeach; ?>
            <div class="ms-footer">
              <button type="button" id="al-sel-all">Select all</button>
              <button type="button" id="al-desel-all">Clear</button>
            </div>
          </div>
        </div>
        <div class="sel-chips" id="al-stream-chips"></div>
        <p class="tgt-hint">Select one or more streams</p>
      </div>

      <!-- Subject single-select -->
      <div class="tgt-fg">
        <label class="tgt-lbl">Subject <sup>*</sup></label>
        <div class="ms-wrap" id="al-subj-ms">
          <button type="button" class="ms-trigger" id="al-subj-trigger"
            <?= empty($sel_class)?'disabled':'' ?>>
            <span id="al-subj-label">
              <?= empty($sel_class)?'Select class first…':'Select subject…' ?>
            </span>
            <i class="fas fa-chevron-down ms-arr" style="font-size:.75rem;color:#8a9a8b"></i>
          </button>
          <div class="ms-dd" id="al-subj-dd">
            <div class="subj-sb"><input type="text" id="al-subj-search" placeholder="Search subject…"></div>
            <div id="al-subj-options">
              <?php foreach ($all_subjects as $sub): ?>
                <label class="ms-opt <?= $sel_subject===$sub['subj_name']?'ms-sel':'' ?>"
                       data-name="<?= htmlspecialchars(strtolower($sub['subj_name'])) ?>">
                  <input type="radio" name="subject" value="<?= htmlspecialchars($sub['subj_name']) ?>"
                    <?= $sel_subject===$sub['subj_name']?'checked':'' ?>>
                  <?= htmlspecialchars($sub['subj_name']) ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <p class="tgt-hint" id="al-subj-hint">
          <?= empty($sel_class)?'Subjects load after selecting a class'
              :count($all_subjects).' subject'.(count($all_subjects)!==1?'s':'').' available' ?>
        </p>
      </div>

      <div class="tgt-actions">
        <button type="submit" class="tgt-btn tgt-btn-primary" id="al-proceed-btn">
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
(function(){
function escH(v){return String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function notify(title,msg,type){
    type=type||'info';
    const ic={info:'fa-circle-info',error:'fa-circle-xmark',warning:'fa-triangle-exclamation'};
    const n=document.createElement('div');
    n.className='tgt-notif '+type;
    n.innerHTML=`<i class="fas ${ic[type]||ic.info} tgt-ni"></i>
      <div class="tgt-nb"><div class="tgt-nt">${escH(title)}</div><div class="tgt-nm">${escH(msg)}</div></div>
      <button class="tgt-nc" onclick="this.closest('.tgt-notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('tgt-notif-stack').prepend(n);
    setTimeout(()=>{n.style.opacity='0';n.style.transform='translateX(30px)';n.style.transition='.3s';setTimeout(()=>n.remove(),300);},4000);
}

// ── Stream multi-select ──────────────────────────────────────
const sTrigger=document.getElementById('al-stream-trigger');
const sDd=document.getElementById('al-stream-dd');
const sLabel=document.getElementById('al-stream-label');
const sChips=document.getElementById('al-stream-chips');

function updateStreamUI(){
    const checked=[...document.querySelectorAll('#al-stream-dd input:checked')].map(e=>e.value);
    sLabel.textContent=checked.length===0?'Select streams…':checked.length===1?checked[0]:checked.length+' streams selected';
    sChips.innerHTML=checked.map(v=>`<span class="sel-chip"><i class="fas fa-layer-group" style="font-size:.65rem"></i>${escH(v)}</span>`).join('');
    document.querySelectorAll('#al-stream-dd .ms-opt').forEach(o=>o.classList.toggle('ms-sel',o.querySelector('input').checked));
}

sTrigger.addEventListener('click',e=>{e.stopPropagation();sDd.classList.toggle('ms-open');sTrigger.classList.toggle('ms-open');});
document.getElementById('al-sel-all').addEventListener('click',()=>{document.querySelectorAll('#al-stream-dd input').forEach(c=>c.checked=true);updateStreamUI();});
document.getElementById('al-desel-all').addEventListener('click',()=>{document.querySelectorAll('#al-stream-dd input').forEach(c=>c.checked=false);updateStreamUI();});
document.querySelectorAll('#al-stream-dd input').forEach(c=>c.addEventListener('change',updateStreamUI));
updateStreamUI();

// ── Subject single-select ────────────────────────────────────
const sjTrigger=document.getElementById('al-subj-trigger');
const sjDd=document.getElementById('al-subj-dd');
const sjLabel=document.getElementById('al-subj-label');
const sjHint=document.getElementById('al-subj-hint');
const classEl=document.getElementById('al-class');

function bindSubjOpts(){
    document.querySelectorAll('#al-subj-options input').forEach(r=>{
        r.addEventListener('change',()=>{
            document.querySelectorAll('#al-subj-options .ms-opt').forEach(o=>o.classList.remove('ms-sel'));
            r.closest('.ms-opt').classList.add('ms-sel');
            sjLabel.textContent=r.value;
            sjDd.classList.remove('ms-open');sjTrigger.classList.remove('ms-open');
        });
    });
}
bindSubjOpts();
const chkR=document.querySelector('#al-subj-options input:checked');
if(chkR)sjLabel.textContent=chkR.value;

sjTrigger.addEventListener('click',e=>{if(sjTrigger.disabled)return;e.stopPropagation();sjDd.classList.toggle('ms-open');sjTrigger.classList.toggle('ms-open');});

document.getElementById('al-subj-search').addEventListener('input',function(){
    const q=this.value.toLowerCase();
    document.querySelectorAll('#al-subj-options .ms-opt').forEach(o=>{
        o.style.display=o.dataset.name.includes(q)?'':'none';
    });
});

classEl.addEventListener('change',function(){
    const cls=this.value;
    if(!cls){sjTrigger.disabled=true;sjLabel.textContent='Select class first…';return;}
    sjTrigger.disabled=false;sjLabel.textContent='Loading…';
    fetch('sel_alevel_targets.php?get_alevel_subjects=1&class='+encodeURIComponent(cls))
        .then(r=>r.json())
        .then(data=>{
            const opts=document.getElementById('al-subj-options');
            opts.innerHTML=data.map(s=>`<label class="ms-opt" data-name="${escH(s.subj_name.toLowerCase())}">
                <input type="radio" name="subject" value="${escH(s.subj_name)}">${escH(s.subj_name)}</label>`).join('');
            bindSubjOpts();
            sjLabel.textContent='Select subject…';
            sjHint.textContent=data.length+' subject'+(data.length!==1?'s':'')+' available';
        })
        .catch(()=>{notify('Error','Could not load subjects.','error');});
});

document.addEventListener('click',()=>{
    [sDd,sjDd].forEach(d=>d.classList.remove('ms-open'));
    [sTrigger,sjTrigger].forEach(t=>t.classList.remove('ms-open'));
});
[sDd,sjDd].forEach(d=>d.addEventListener('click',e=>e.stopPropagation()));

// Form validation
document.getElementById('sel-al-form').addEventListener('submit',function(e){
    const cls=document.getElementById('al-class').value;
    const term=document.getElementById('al-term').value;
    const streams=[...document.querySelectorAll('#al-stream-dd input:checked')];
    const subj=document.querySelector('#al-subj-options input:checked');
    if(!cls){e.preventDefault();notify('Missing','Please select a class.','warning');return;}
    if(!term){e.preventDefault();notify('Missing','Please select a term.','warning');return;}
    if(!streams.length){e.preventDefault();notify('Missing','Please select at least one stream.','warning');return;}
    if(!subj){e.preventDefault();notify('Missing','Please select a subject.','warning');return;}
    const btn=document.getElementById('al-proceed-btn');
    btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Loading…';btn.disabled=true;
});
})();
</script>
