<?php
require_once '../auth.php';
require_once '../conn.php';
require_once 'teacher_scope_helpers.php';
requireAllowedRole();

$is_admin = isAdminRole();
$staff_id = $_SESSION['staff_id'] ?? '';

// ── Sanitised inputs ─────────────────────────────────────
$class   = isset($_GET['class'])   ? htmlspecialchars(trim($_GET['class']))   : '';
$term    = isset($_GET['term'])    ? htmlspecialchars(trim($_GET['term']))     : '';
$year    = isset($_GET['year'])    ? (int)$_GET['year']                       : (int)date('Y');
$streams = isset($_GET['streams']) ? array_map('trim', (array)$_GET['streams']) : [];
$subject = isset($_GET['subject']) ? htmlspecialchars(trim($_GET['subject']))  : '';

// ── Fetch subjects dynamically based on class level ──────
function getSubjects(mysqli $conn, string $class): array {
    $level_sql = "SELECT level FROM classes WHERE LOWER(class_name) = LOWER(?) LIMIT 1";
    $stmt = mysqli_prepare($conn, $level_sql);
    $level = '';
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $class);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($res)) $level = $row['level'];
        mysqli_stmt_close($stmt);
    }
    // If class not found in DB yet, derive level from name
    if (empty($level)) {
        $level = (stripos($class, 'senior five') !== false || stripos($class, 'senior six') !== false) ? 'A' : 'O';
    }
    $sql = "SELECT subj_id, subj_name FROM subjects WHERE level LIKE ? ORDER BY subj_name";
    $stmt = mysqli_prepare($conn, $sql);
    $like = $level . '%';
    mysqli_stmt_bind_param($stmt, 's', $like);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $subjects = [];
    while ($row = mysqli_fetch_assoc($result)) $subjects[] = $row;
    mysqli_stmt_close($stmt);
    return $subjects;
}

// ── Fetch streams from DB (with static fallback) ─────────
function getStreams(mysqli $conn): array {
    $result = mysqli_query($conn, "SELECT DISTINCT stream_name FROM streams WHERE status='active' ORDER BY stream_name");
    $streams = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) $streams[] = $row['stream_name'];
    }
    return $streams ?: ['East', 'West', 'South', 'North'];
}

$global_streams = getStreams($conn);

if ($is_admin) {
    $all_classes  = ['Senior One','Senior Two','Senior Three','Senior Four','Senior Five','Senior Six'];
    $all_subjects = !empty($class) ? getSubjects($conn, $class) : [];
    $available_streams = $global_streams;
} else {
    // O-Level module only — exclude Senior Five/Six even if the teacher
    // happens to have an A-Level assignment row.
    $all_classes = array_values(array_filter(
        getAssignedClasses($conn, $staff_id),
        fn($c) => stripos($c, 'senior five') === false && stripos($c, 'senior six') === false
    ));

    $all_subjects = !empty($class)
        ? getAssignedSubjectsForClass($conn, $staff_id, $class)
        : [];

    if (!empty($class) && !empty($subject)) {
        $available_streams = getAssignedStreamsForClassSubject($conn, $staff_id, $class, $subject, $global_streams);
    } elseif (!empty($class)) {
        $available_streams = getAssignedStreamsForClass($conn, $staff_id, $class, $global_streams);
    } else {
        $available_streams = [];
    }
}

$all_terms = ['Term 1','Term 2','Term 3'];

require_once 'marks_design_system.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Add Marks — Step 1 — SchoolPilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<?php marks_head_styles(); ?>
<style>
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.form-panel{max-width:100%;margin:0 auto}
/* Multi-select dropdown */
.multi-select{position:relative}
.ms-trigger{width:100%;padding:10px 38px 10px 14px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.9rem;font-family:inherit;color:#222;background:#fff;cursor:pointer;text-align:left;display:flex;align-items:center;justify-content:space-between;transition:border-color var(--transition),box-shadow var(--transition)}
.ms-trigger:focus,.ms-trigger.open{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.ms-trigger .arrow{transition:transform .2s}
.ms-trigger.open .arrow{transform:rotate(180deg)}
.ms-dropdown{position:absolute;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1.5px solid #d0dbd1;border-radius:var(--radius);box-shadow:var(--shadow-lg);z-index:200;max-height:220px;overflow-y:auto;display:none}
.ms-dropdown.open{display:block}
.ms-option{display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;font-size:.875rem;border-bottom:1px solid #f0f4f1;transition:background var(--transition)}
.ms-option:last-child{border-bottom:none}
.ms-option:hover{background:var(--g50)}
.ms-option input{width:16px;height:16px;accent-color:var(--g700);cursor:pointer;flex-shrink:0}
.ms-option.selected{background:var(--g50)}
.ms-footer{padding:8px 14px;display:flex;gap:12px;border-top:1px solid #f0f4f1;background:#fafafa}
.ms-footer button{background:none;border:none;font-size:.77rem;font-weight:600;color:var(--g700);cursor:pointer;padding:2px 0;font-family:inherit}
.ms-footer button:hover{color:var(--g900)}
.sel-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;min-height:0}
.sel-chip{display:inline-flex;align-items:center;gap:5px;background:var(--g100);color:var(--g800);font-size:.75rem;font-weight:600;padding:4px 10px;border-radius:20px}
/* Subject search */
.subject-search{padding:8px 14px;border-bottom:1px solid #f0f4f1;position:sticky;top:0;background:#fff;z-index:1}
.subject-search input{width:100%;padding:7px 10px;border:1.5px solid #d0dbd1;border-radius:6px;font-size:.83rem;font-family:inherit}
.subject-search input:focus{outline:none;border-color:var(--g600)}
/* Form actions */
.form-actions{padding:20px 0 0;display:flex;justify-content:flex-end;gap:10px;border-top:1px solid #e8ede9;margin-top:8px}
</style>
</head>
<body>
<?php require_once '../nav.php'; ?>

<div class="page">
<?php marks_page_header(
    'Manage Student Marks',
    'Select the class, term and subject to begin entering marks',
    1
); ?>

<div class="form-panel">
<div class="card">
<div class="card-header">
    <span class="card-title"><i class="fas fa-list-check"></i> Class & Subject Selection</span>
</div>
<div class="card-body">

<form id="sel-form" method="GET" action="sel_aoi_add_marks.php" autocomplete="off">

    <div class="form-row">
        <div class="form-group">
            <label class="form-label" for="class">Class</label>
            <select name="class" id="class" class="form-select" required>
                <option value="">— Select class —</option>
                <?php foreach($all_classes as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?= $class===$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label" for="term">Term</label>
            <select name="term" id="term" class="form-select" required>
                <option value="">— Select term —</option>
                <?php foreach($all_terms as $t): ?>
                    <option value="<?= htmlspecialchars($t) ?>" <?= $term===$t?'selected':'' ?>><?= htmlspecialchars($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="form-group">
        <label class="form-label" for="year">Academic Year</label>
        <input type="number" name="year" id="year" class="form-control" value="<?= $year ?>"
               min="2000" max="2099" style="max-width:160px" required>
    </div>

    <div class="form-group">
        <label class="form-label">Streams</label>
        <div class="multi-select" id="stream-ms">
            <button type="button" class="ms-trigger" id="stream-trigger" aria-expanded="false">
                <span id="stream-label">Select streams…</span>
                <i class="fas fa-chevron-down arrow" style="font-size:.75rem;color:#8a9a8b"></i>
            </button>
            <div class="ms-dropdown" id="stream-dropdown">
                <?php foreach($available_streams as $s): $checked=in_array($s,$streams); ?>
                <label class="ms-option <?= $checked?'selected':'' ?>">
                    <input type="checkbox" name="streams[]" value="<?= htmlspecialchars($s) ?>" <?= $checked?'checked':'' ?>>
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
        <p class="form-hint">Select one or more streams to include</p>
    </div>

    <div class="form-group">
        <label class="form-label" for="subject">Subject</label>
        <div class="multi-select" id="subj-ms">
            <button type="button" class="ms-trigger" id="subj-trigger" aria-expanded="false"
                <?= empty($class)?'disabled title="Select a class first"':'' ?>>
                <span id="subj-label"><?= empty($class)?'Select class first…':'Select subject…' ?></span>
                <i class="fas fa-chevron-down arrow" style="font-size:.75rem;color:#8a9a8b"></i>
            </button>
            <div class="ms-dropdown" id="subj-dropdown">
                <div class="subject-search"><input type="text" id="subj-search" placeholder="Search subject…"></div>
                <div id="subj-options">
                    <?php foreach($all_subjects as $subj): ?>
                    <label class="ms-option <?= $subject===$subj['subj_name']?'selected':'' ?>"
                           data-name="<?= htmlspecialchars(strtolower($subj['subj_name'])) ?>">
                        <input type="radio" name="subject" value="<?= htmlspecialchars($subj['subj_name']) ?>"
                               <?= $subject===$subj['subj_name']?'checked':'' ?>>
                        <?= htmlspecialchars($subj['subj_name']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <p class="form-hint" id="subj-hint"><?= empty($class)?'Subjects will load after selecting a class':'Choose one subject' ?></p>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg" id="proceed-btn">
            <i class="fas fa-arrow-right"></i> Proceed to Topics
        </button>
    </div>

</form>
</div>
</div>
</div>
</div>

<?php marks_notify_js(); ?>
<script>
(function(){
// ── Stream multi-select ───────────────────────────────────
const streamTrigger=document.getElementById('stream-trigger');
const streamDd=document.getElementById('stream-dropdown');
const streamLabel=document.getElementById('stream-label');
const streamChips=document.getElementById('stream-chips');

function renderStreamOptions(streams, checkedValues){
    const dd = document.getElementById('stream-dropdown');
    const footer = dd.querySelector('.ms-footer');
    dd.querySelectorAll('.ms-option').forEach(el=>el.remove());
    const optsHtml = streams.map(s=>{
        const isChecked = checkedValues.includes(s);
        return `<label class="ms-option ${isChecked?'selected':''}">
            <input type="checkbox" name="streams[]" value="${escH(s)}" ${isChecked?'checked':''}>
            ${escH(s)}
        </label>`;
    }).join('');
    footer.insertAdjacentHTML('beforebegin', optsHtml);
    document.querySelectorAll('#stream-dropdown input').forEach(cb=>cb.addEventListener('change',updateStreamUI));
    updateStreamUI();
}

function reloadStreams(){
    const cls = classEl.value;
    const subjInput = document.querySelector('#subj-options input:checked');
    const subj = subjInput ? subjInput.value : '';
    if(!cls){ renderStreamOptions([], []); return; }
    let url = 'get_streams.php?class='+encodeURIComponent(cls);
    if(subj) url += '&subject='+encodeURIComponent(subj);
    fetch(url).then(r=>r.json()).then(list=>{
        const currentlyChecked=[...document.querySelectorAll('#stream-dropdown input:checked')].map(el=>el.value);
        renderStreamOptions(list, currentlyChecked.filter(v=>list.includes(v)));
    }).catch(()=>{});
}

function updateStreamUI(){
    const checked=[...document.querySelectorAll('#stream-dropdown input:checked')].map(el=>el.value);
    streamLabel.textContent=checked.length===0?'Select streams…':checked.length===1?checked[0]:checked.length+' streams selected';
    streamChips.innerHTML=checked.map(v=>`<span class="sel-chip"><i class="fas fa-layer-group" style="font-size:.65rem"></i>${escH(v)}</span>`).join('');
    document.querySelectorAll('#stream-dropdown .ms-option').forEach(opt=>{
        opt.classList.toggle('selected',opt.querySelector('input').checked);
    });
}

streamTrigger.addEventListener('click',e=>{e.stopPropagation();streamDd.classList.toggle('open');streamTrigger.classList.toggle('open');});
document.getElementById('sel-all-streams').addEventListener('click',()=>{document.querySelectorAll('#stream-dropdown input').forEach(cb=>cb.checked=true);updateStreamUI();});
document.getElementById('desel-all-streams').addEventListener('click',()=>{document.querySelectorAll('#stream-dropdown input').forEach(cb=>cb.checked=false);updateStreamUI();});
document.querySelectorAll('#stream-dropdown input').forEach(cb=>cb.addEventListener('change',updateStreamUI));
updateStreamUI();

// ── Subject single-select with AJAX reload on class change ─
const subjTrigger=document.getElementById('subj-trigger');
const subjDd=document.getElementById('subj-dropdown');
const subjLabel=document.getElementById('subj-label');
const subjHint=document.getElementById('subj-hint');
const classEl=document.getElementById('class');
let currentSubject='<?= addslashes($subject) ?>';

function updateSubjLabel(){
    const checked=document.querySelector('#subj-options input:checked');
    subjLabel.textContent=checked?checked.value:'Select subject…';
}

subjTrigger.addEventListener('click',e=>{
    if(subjTrigger.disabled)return;
    e.stopPropagation();subjDd.classList.toggle('open');subjTrigger.classList.toggle('open');
});

document.getElementById('subj-search').addEventListener('input',function(){
    const q=this.value.toLowerCase();
    document.querySelectorAll('#subj-options .ms-option').forEach(opt=>{
        opt.style.display=opt.dataset.name.includes(q)?'':'none';
    });
});

document.getElementById('subj-options').addEventListener('change',function(e){
    if(e.target.type==='radio'){
        document.querySelectorAll('#subj-options .ms-option').forEach(o=>o.classList.remove('selected'));
        e.target.closest('.ms-option').classList.add('selected');
        subjLabel.textContent=e.target.value;
        subjDd.classList.remove('open');subjTrigger.classList.remove('open');
    }
});

classEl.addEventListener('change',function(){
    const cls=this.value;
    if(!cls){subjTrigger.disabled=true;subjLabel.textContent='Select class first…';subjHint.textContent='Subjects will load after selecting a class';renderStreamOptions([],[]);return;}
    subjTrigger.disabled=false;
    subjLabel.textContent='Loading subjects…';
    subjHint.textContent='';
    fetch('get_subjects.php?class='+encodeURIComponent(cls))
        .then(r=>r.json())
        .then(data=>{
            const opts=document.getElementById('subj-options');
            opts.innerHTML=data.map(s=>`<label class="ms-option" data-name="${escH(s.subj_name.toLowerCase())}">
                <input type="radio" name="subject" value="${escH(s.subj_name)}">${escH(s.subj_name)}</label>`).join('');
            opts.querySelectorAll('input').forEach(i=>i.addEventListener('change',()=>{
                opts.querySelectorAll('.ms-option').forEach(o=>o.classList.remove('selected'));
                i.closest('.ms-option').classList.add('selected');
                subjLabel.textContent=i.value;
                subjDd.classList.remove('open');subjTrigger.classList.remove('open');
                reloadStreams();
            }));
            subjLabel.textContent=data.length?'Select subject…':'No subjects assigned for this class';
            subjHint.textContent=data.length+' subject'+(data.length!==1?'s':'')+' available';
        })
        .catch(()=>{subjHint.textContent='Failed to load subjects';notify('Error','Could not load subjects for this class.','error');});
    reloadStreams();
});

// ── Close dropdowns on outside click ─────────────────────
document.addEventListener('click',()=>{
    [streamDd,subjDd].forEach(dd=>dd.classList.remove('open'));
    [streamTrigger,subjTrigger].forEach(t=>t.classList.remove('open'));
});
[streamDd,subjDd].forEach(dd=>dd.addEventListener('click',e=>e.stopPropagation()));

// ── Form validation ───────────────────────────────────────
document.getElementById('sel-form').addEventListener('submit',function(e){
    const cls=document.getElementById('class').value;
    const term=document.getElementById('term').value;
    const streams=[...document.querySelectorAll('#stream-dropdown input:checked')];
    const subj=document.querySelector('#subj-options input:checked');

    if(!cls){e.preventDefault();notify('Missing field','Please select a class.','warning');document.getElementById('class').focus();return;}
    if(!term){e.preventDefault();notify('Missing field','Please select a term.','warning');document.getElementById('term').focus();return;}
    if(streams.length===0){e.preventDefault();notify('Missing field','Please select at least one stream.','warning');return;}
    if(!subj){e.preventDefault();notify('Missing field','Please select a subject.','warning');return;}

    document.getElementById('proceed-btn').innerHTML='<i class="fas fa-spinner fa-spin"></i> Loading…';
    document.getElementById('proceed-btn').disabled=true;
});

function escH(v){return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
})();
</script>
</body>
</html>