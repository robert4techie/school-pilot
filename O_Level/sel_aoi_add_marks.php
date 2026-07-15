<?php
require_once '../auth.php';
require_once '../conn.php';
require_once 'teacher_auth_check.php';

$class         = isset($_GET['class'])      ? trim($_GET['class'])     : '';
$term          = isset($_GET['term'])       ? trim($_GET['term'])      : '';
$year          = isset($_GET['year'])       ? (int)$_GET['year']                         : (int)date('Y');
$subject       = isset($_GET['subject'])    ? trim($_GET['subject'])   : '';
$streams       = isset($_GET['streams'])    ? array_map(fn($s)=>trim($s), (array)$_GET['streams']) : [];
$selected_aois = isset($_GET['aoi_topics']) ? array_map('trim', (array)$_GET['aoi_topics']) : [];

if (empty($class) || empty($term) || empty($subject) || empty($streams)) {
    header('Location: sel_add_marks.php?error=missing_params');
    exit;
}

// ── Load AOI topics ───────────────────────────────────────
function getAOI(mysqli $conn, int $year, string $term, string $class, string $subject): array {
    $sql  = "SELECT * FROM aoi WHERE year=? AND term=? AND class=? AND subject=? ORDER BY id";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, 'isss', $year, $term, $class, $subject);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $areas  = [];
    while ($row = mysqli_fetch_assoc($result)) $areas[] = $row;
    mysqli_stmt_close($stmt);
    return $areas;
}

$areas = getAOI($conn, $year, $term, $class, $subject);

require_once 'marks_design_system.php';

$context_pills = [
    'fa-school'        => htmlspecialchars($class),
    'fa-calendar-days' => htmlspecialchars($term . ' ' . $year),
    'fa-book-open'     => htmlspecialchars($subject),
];
foreach ($streams as $s) $context_pills['fa-layer-group'] = htmlspecialchars(implode(', ', $streams));
foreach ($streams as $s) $context_pills['fa-layer-group'] = implode(', ', $streams);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Select Topics — Step 2 — SchoolPilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<?php marks_head_styles(); ?>
<style>
.page{max-width:100%;}
.two-col{display:flex;gap:20px;align-items:flex-start}
.col-topics{flex:0 0 400px}
.col-preview{flex:1}
.topic-item label{cursor:pointer;width:100%;display:flex;align-items:flex-start;gap:12px}
.eot-badge{display:inline-block;background:#e3f2fd;color:#1565c0;font-size:.68rem;font-weight:700;padding:2px 7px;border-radius:20px;vertical-align:middle;margin-left:6px}
.max-badge{display:inline-block;font-size:.72rem;font-weight:600;padding:2px 8px;border-radius:20px;background:var(--g100);color:var(--g800);white-space:nowrap}
.no-topics{padding:24px;text-align:center;color:#8a9a8b;font-size:.875rem}
.no-topics i{font-size:1.8rem;display:block;margin-bottom:8px;opacity:.4}
.form-actions{display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-top:1px solid #e8ede9;background:#fafafa}
.empty-preview{padding:40px 20px;text-align:center;color:#8a9a8b;font-size:.875rem}
.empty-preview i{font-size:2rem;display:block;margin-bottom:8px;opacity:.35}
@media(max-width:900px){.two-col{flex-direction:column}.col-topics{flex:none;width:100%}}
</style>
</head>
<body>
<?php require_once '../nav.php'; ?>

<div class="page">
<?php marks_page_header('Select Assessment Topics', 'Choose which topics to enter marks for', 2, $context_pills); ?>

<form id="aoi-form" method="POST" action="add_marks.php">
    <?php foreach(['class','term','subject'] as $f): ?>
        <input type="hidden" name="<?= $f ?>" value="<?= htmlspecialchars($$f) ?>">
    <?php endforeach; ?>
    <input type="hidden" name="year" value="<?= $year ?>">
    <?php foreach($streams as $s): ?>
        <input type="hidden" name="streams[]" value="<?= htmlspecialchars($s) ?>">
    <?php endforeach; ?>

    <div class="two-col">
        <!-- Left: topic list -->
        <div class="col-topics">
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i class="fas fa-tags"></i> Available Topics</span>
                    <span class="badge-count" id="sel-count">0 selected</span>
                </div>

                <!-- EOT always first -->
                <div class="topic-item eot-item">
                    <label>
                        <input type="checkbox" class="topic-cb" id="eot-cb" name="aoi_topics[]" value="EOT"
                               <?= in_array('EOT', $selected_aois)?'checked':'' ?>>
                        <div class="topic-item-body">
                            <div class="topic-item-title">
                                End of Term (EOT) <span class="eot-badge">EOT</span>
                            </div>
                            <div class="topic-item-desc">End of Term examination — marks out of 100%</div>
                        </div>
                    </label>
                </div>

                <div class="divider-label">Regular Assessment Topics</div>

                <?php if(empty($areas)): ?>
                <div class="no-topics">
                    <i class="fas fa-inbox"></i>
                    No regular topics found for these parameters.<br>
                    You can still proceed with EOT only.
                </div>
                <?php else: ?>
                    <?php foreach($areas as $area): $checked=in_array((string)$area['id'], $selected_aois); ?>
                    <div class="topic-item regular-item">
                        <label>
                            <input type="checkbox" class="topic-cb regular-cb" name="aoi_topics[]"
                                   value="<?= (int)$area['id'] ?>"
                                   data-topic="<?= htmlspecialchars($area['topic']) ?>"
                                   data-desc="<?= htmlspecialchars($area['description']) ?>"
                                   <?= $checked?'checked':'' ?>>
                            <div class="topic-item-body">
                                <div class="topic-item-title"><?= htmlspecialchars($area['topic']) ?></div>
                                <div class="topic-item-desc"><?= htmlspecialchars($area['description']) ?></div>
                            </div>
                        </label>
                    </div>
                    <?php endforeach; ?>

                    <div style="padding:10px 18px;display:flex;gap:12px;border-top:1px solid #f0f4f1;background:#fafafa">
                        <button type="button" id="sel-all" style="background:none;border:none;font-size:.78rem;font-weight:600;color:var(--g700);cursor:pointer;font-family:inherit;padding:0">Select all topics</button>
                        <span style="color:#ddd">|</span>
                        <button type="button" id="desel-all" style="background:none;border:none;font-size:.78rem;font-weight:600;color:#8a9a8b;cursor:pointer;font-family:inherit;padding:0">Clear</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right: preview -->
        <div class="col-preview">
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i class="fas fa-table-list"></i> Selected Topics Preview</span>
                </div>
                <div id="preview-empty" class="empty-preview">
                    <i class="fas fa-list-check"></i>
                    No topics selected yet.<br>Choose from the left panel.
                </div>
                <div class="preview-wrap" id="preview-wrap" style="display:none">
                    <table class="preview-table" id="preview-table">
                        <thead>
                            <tr>
                                <th style="width:32%">Topic</th>
                                <th>Description</th>
                                <th style="width:18%;text-align:center">Max marks</th>
                            </tr>
                        </thead>
                        <tbody id="preview-body"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="sel_add_marks.php?class=<?= urlencode($class) ?>&term=<?= urlencode($term) ?>&year=<?= $year ?>&subject=<?= urlencode($subject) ?><?php foreach($streams as $s) echo '&streams[]='.urlencode($s); ?>"
           class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <button type="submit" class="btn btn-primary btn-lg" id="proceed-btn">
            <i class="fas fa-pen-to-square"></i> Proceed to Enter Marks
        </button>
    </div>
</form>
</div>

<?php marks_notify_js(); ?>
<script>
(function(){
const eotCb=document.getElementById('eot-cb');
const regularCbs=[...document.querySelectorAll('.regular-cb')];
const allCbs=[...document.querySelectorAll('.topic-cb')];
const selCount=document.getElementById('sel-count');
const previewBody=document.getElementById('preview-body');
const previewWrap=document.getElementById('preview-wrap');
const previewEmpty=document.getElementById('preview-empty');

function getTopicData(cb){
    if(cb.id==='eot-cb') return {topic:'End of Term (EOT)',desc:'End of Term examination results',max:'100.0',type:'eot'};
    return{topic:cb.dataset.topic||'—',desc:cb.dataset.desc||'',max:'3.0',type:'aoi'};
}

function refresh(){
    const checked=allCbs.filter(c=>c.checked);
    selCount.textContent=checked.length+(checked.length===1?' topic selected':' topics selected');
    if(checked.length===0){previewWrap.style.display='none';previewEmpty.style.display='';previewBody.innerHTML='';return;}
    previewWrap.style.display='';previewEmpty.style.display='none';
    previewBody.innerHTML=checked.map(cb=>{
        const d=getTopicData(cb);
        const badge=d.type==='eot'?`<span class="badge badge-eot">EOT</span>`:`<span class="badge badge-aoi">AOI</span>`;
        return `<tr><td>${badge} ${escH(d.topic)}</td><td style="color:#546e7a;font-size:.82rem">${escH(d.desc)}</td><td style="text-align:center"><span class="max-badge">${d.max}</span></td></tr>`;
    }).join('');
}

// EOT mutual exclusion
eotCb.addEventListener('change',()=>{if(eotCb.checked)regularCbs.forEach(c=>c.checked=false);refresh();});
regularCbs.forEach(c=>c.addEventListener('change',()=>{if(c.checked)eotCb.checked=false;refresh();}));

const selAllBtn=document.getElementById('sel-all');
const deselAllBtn=document.getElementById('desel-all');
if(selAllBtn) selAllBtn.addEventListener('click',()=>{regularCbs.forEach(c=>c.checked=true);eotCb.checked=false;refresh();});
if(deselAllBtn) deselAllBtn.addEventListener('click',()=>{allCbs.forEach(c=>c.checked=false);refresh();});

refresh();

document.getElementById('aoi-form').addEventListener('submit',function(e){
    if(allCbs.filter(c=>c.checked).length===0){
        e.preventDefault();
        notify('No topics selected','Please select at least one topic before proceeding.','warning');
        return;
    }
    document.getElementById('proceed-btn').innerHTML='<i class="fas fa-spinner fa-spin"></i> Loading…';
    document.getElementById('proceed-btn').disabled=true;
});

function escH(v){return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
})();
</script>
</body>
</html>