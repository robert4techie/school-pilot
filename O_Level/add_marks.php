<?php
// Production: never display errors to users
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

require_once '../auth.php';
require_once '../conn.php';
require_once 'teacher_auth_check.php';

// ── Sanitised inputs ──────────────────────────────────────
$class      = isset($_POST['class'])      ? trim($_POST['class'])    : '';
$term       = isset($_POST['term'])       ? trim($_POST['term'])     : '';
$year       = isset($_POST['year'])       ? (int)$_POST['year']                        : (int)date('Y');
$subject    = isset($_POST['subject'])    ? trim($_POST['subject'])  : '';
$streams    = isset($_POST['streams'])    ? array_map(fn($s)=>trim($s), (array)$_POST['streams']) : [];
$aoi_topics = isset($_POST['aoi_topics']) ? array_map('trim', (array)$_POST['aoi_topics']) : [];

if (empty($class) || empty($term) || empty($subject) || empty($streams) || empty($aoi_topics)) {
    header('Location: sel_aoi_add_marks.php?error=missing_params');
    exit;
}

// ── EOT mutual exclusion ──────────────────────────────────
$eot_selected = in_array('EOT', $aoi_topics, true);
if ($eot_selected) $aoi_topics = ['EOT'];

// ── Helper functions ──────────────────────────────────────
function termToRoman(string $term): string {
    $n = filter_var($term, FILTER_SANITIZE_NUMBER_INT);
    return ['i','ii','iii'][$n-1] ?? 'i';
}
function getLevel(string $class): string {
    return (stripos($class,'senior five')!==false||stripos($class,'senior six')!==false) ? 'alevel' : 'olevel';
}
function isOptionalSubject(mysqli $conn, string $subject): bool {
    $stmt = mysqli_prepare($conn, "SELECT compulsory FROM subjects WHERE subj_name=?");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 's', $subject);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result && $row = mysqli_fetch_assoc($result)) { mysqli_stmt_close($stmt); return ($row['compulsory']==0); }
    mysqli_stmt_close($stmt);
    return false;
}

// ── Results table ─────────────────────────────────────────
$term_roman    = termToRoman($term);
$level         = getLevel($class);
$results_table = "{$year}_{$term_roman}_{$level}";

function createResultsTableIfNotExists(mysqli $conn, string $table_name): bool {
    if (!preg_match('/^\d{4}_(i|ii|iii)_(olevel|alevel)$/', $table_name)) return false;
    $check = mysqli_query($conn, "SHOW TABLES LIKE '$table_name'");
    if ($check && mysqli_num_rows($check)==0) {
        $sql = "CREATE TABLE `$table_name` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `student_id` varchar(25) NOT NULL,
            `class` varchar(50) NOT NULL,
            `stream` varchar(10) NOT NULL,
            `subject` varchar(100) NOT NULL,
            `topic_id` varchar(20) NOT NULL,
            `marks` decimal(5,2) DEFAULT NULL,
            `max_marks` decimal(5,2) DEFAULT 3.00,
            `date_added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `student_id` (`student_id`),
            KEY `subject` (`subject`),
            KEY `topic_id` (`topic_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        return (bool)mysqli_query($conn, $sql);
    }
    return true;
}
createResultsTableIfNotExists($conn, $results_table);

// ── Fetch students ────────────────────────────────────────
function getStudents(mysqli $conn, string $class, array $streams, string $subject): array {
    if (empty($streams)) return [];
    $is_optional = isOptionalSubject($conn, $subject);
    $placeholders = implode(',', array_fill(0, count($streams), '?'));

    if ($is_optional) {
        $sql  = "SELECT DISTINCT s.student_id, s.first_name, s.last_name, s.stream
                 FROM students s
                 INNER JOIN student_subjects ss ON s.student_id=ss.student_id
                 WHERE LOWER(s.current_class)=LOWER(?) AND LOWER(s.stream) IN ($placeholders)
                 AND ss.subject=? AND LOWER(ss.class)=LOWER(?) AND s.status='active'
                 ORDER BY s.first_name, s.last_name";
        $types = 's'.str_repeat('s',count($streams)).'ss';
        $params = array_merge([$class], array_map('strtolower',$streams), [$subject, $class]);
    } else {
        $sql  = "SELECT student_id, first_name, last_name, stream
                 FROM students
                 WHERE LOWER(current_class)=LOWER(?) AND LOWER(stream) IN ($placeholders) AND status='active'
                 ORDER BY first_name, last_name";
        $types = 's'.str_repeat('s',count($streams));
        $params = array_merge([$class], array_map('strtolower',$streams));
    }

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    if (!mysqli_stmt_execute($stmt)) return [];
    $result = mysqli_stmt_get_result($stmt);
    $students = [];
    while ($row = mysqli_fetch_assoc($result)) $students[] = $row;
    mysqli_stmt_close($stmt);
    return $students;
}

// ── Fetch AOI topic details ───────────────────────────────
function getAOITopics(mysqli $conn, array $topic_ids): array {
    if (empty($topic_ids)) return [];
    if (count($topic_ids)===1 && $topic_ids[0]==='EOT') {
        return ['EOT'=>['id'=>'EOT','topic'=>'End of Term (EOT)','description'=>'End of Term Examination','max_marks'=>100.0]];
    }
    $placeholders = implode(',', array_fill(0, count($topic_ids), '?'));
    $sql  = "SELECT * FROM aoi WHERE id IN ($placeholders) ORDER BY id";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, str_repeat('i',count($topic_ids)), ...$topic_ids);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $topics = [];
    while ($row = mysqli_fetch_assoc($result)) { $row['max_marks']=3.0; $topics[$row['id']]=$row; }
    mysqli_stmt_close($stmt);
    return $topics;
}

// ── Fetch existing marks ──────────────────────────────────
function getExistingMarks(mysqli $conn, string $table, array $student_ids, array $topic_ids, string $subject): array {
    if (empty($student_ids)||empty($topic_ids)) return [];
    if (!preg_match('/^\d{4}_(i|ii|iii)_(olevel|alevel)$/', $table)) return [];
    $marks = [];

    $db_topics = array_filter($topic_ids, fn($id)=>$id!=='EOT');
    $has_eot   = in_array('EOT', $topic_ids, true);
    $sp = implode(',', array_fill(0, count($student_ids), '?'));

    if (!empty($db_topics)) {
        $tp   = implode(',', array_fill(0, count($db_topics), '?'));
        $sql  = "SELECT student_id,topic_id,marks FROM `$table` WHERE student_id IN ($sp) AND topic_id IN ($tp) AND subject=?";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            $types = str_repeat('s',count($student_ids)).str_repeat('s',count($db_topics)).'s';
            mysqli_stmt_bind_param($stmt, $types, ...array_merge($student_ids, $db_topics, [$subject]));
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($row=mysqli_fetch_assoc($res)) $marks[$row['student_id']][$row['topic_id']]=$row['marks'];
            mysqli_stmt_close($stmt);
        }
    }
    if ($has_eot) {
        $sql  = "SELECT student_id,marks FROM `$table` WHERE student_id IN ($sp) AND topic_id='EOT' AND subject=?";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            $types = str_repeat('s',count($student_ids)).'s';
            mysqli_stmt_bind_param($stmt, $types, ...array_merge($student_ids, [$subject]));
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($row=mysqli_fetch_assoc($res)) $marks[$row['student_id']]['EOT']=$row['marks'];
            mysqli_stmt_close($stmt);
        }
    }
    return $marks;
}

$students          = getStudents($conn, $class, $streams, $subject);
$aoi_topic_details = getAOITopics($conn, $aoi_topics);
$student_ids       = array_column($students, 'student_id');
$existing_marks    = getExistingMarks($conn, $results_table, $student_ids, $aoi_topics, $subject);

$total_students  = count($students);
$marked_students = 0;
foreach ($student_ids as $sid) {
    foreach ($aoi_topics as $tid) { if (isset($existing_marks[$sid][$tid])) { $marked_students++; break; } }
}

require_once 'marks_design_system.php';

$context_pills = [
    'fa-school'        => htmlspecialchars($class),
    'fa-calendar-days' => htmlspecialchars($term . ' ' . $year),
    'fa-book-open'     => htmlspecialchars($subject),
    'fa-layer-group'   => htmlspecialchars(implode(', ', $streams)),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Enter Marks — Step 3 — SchoolPilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<?php marks_head_styles(); ?>
<style>
.page{max-width:100%;}
.stats-row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.stat-pill{background:#fff;border:1.5px solid #d8e8d8;border-radius:40px;padding:8px 18px;display:flex;align-items:center;gap:8px}
.stat-num{font-size:1.2rem;font-weight:700;color:var(--g800)}
.stat-lbl{font-size:.75rem;color:#6b7c6d}
.progress-bar-wrap{background:#e8ede9;border-radius:4px;height:6px;margin-top:6px;overflow:hidden}
.progress-bar{background:var(--g600);height:100%;border-radius:4px;transition:width .5s ease}
/* Save status in toolbar */
.save-indicator{display:inline-flex;align-items:center;gap:6px;font-size:.78rem;font-weight:600;padding:5px 12px;border-radius:20px;background:#f5f5f5;color:#8a9a8b;transition:all .3s}
.save-indicator.saving{background:#fff8e1;color:#e65100}
.save-indicator.saved{background:var(--g100);color:var(--g800)}
.save-indicator.error{background:#ffebee;color:var(--red)}
/* Mark column header */
.topic-col-head{text-align:center}
.topic-col-head small{display:block;font-size:.68rem;font-weight:500;opacity:.75;margin-top:2px}
/* Student name cell */
.student-no{font-size:.72rem;color:rgba(255,255,255,.6);font-weight:500}
.sname{font-weight:600;color:var(--g900);font-size:.875rem}
.sstream{font-size:.75rem;color:#6b7c6d}
/* Row highlight when fully marked */
tr.fully-marked td{background:#f8fef8 !important}
/* Back to top button */
#back-top{position:fixed;bottom:28px;right:28px;width:40px;height:40px;background:var(--g700);color:#fff;border:none;border-radius:50%;cursor:pointer;display:none;align-items:center;justify-content:center;font-size:.9rem;box-shadow:0 4px 12px rgba(0,0,0,.2);transition:all .2s;z-index:100}
#back-top:hover{background:var(--g800);transform:translateY(-2px)}
</style>
</head>
<body>
<?php require_once '../nav.php'; ?>

<div class="page">
<?php marks_page_header('Enter Student Marks', htmlspecialchars($subject).' — '.htmlspecialchars($class).' — '.htmlspecialchars($term).' '.$year, 3, $context_pills); ?>

<!-- Hidden fields passed to JS -->
<input type="hidden" id="js-class"   value="<?= htmlspecialchars($class) ?>">
<input type="hidden" id="js-subject" value="<?= htmlspecialchars($subject) ?>">
<input type="hidden" id="js-table"   value="<?= htmlspecialchars($results_table) ?>">

<!-- Stats row -->
<div class="stats-row">
    <div class="stat-pill">
        <span class="stat-num"><?= $total_students ?></span>
        <span class="stat-lbl">Students</span>
    </div>
    <div class="stat-pill">
        <span class="stat-num"><?= count($aoi_topic_details) ?></span>
        <span class="stat-lbl">Topic<?= count($aoi_topic_details)!==1?'s':'' ?></span>
    </div>
    <div class="stat-pill" style="flex-direction:column;align-items:flex-start;min-width:160px">
        <div style="display:flex;align-items:center;gap:8px">
            <span class="stat-num" id="marked-count"><?= $marked_students ?></span>
            <span class="stat-lbl">/ <?= $total_students ?> marked</span>
        </div>
        <div class="progress-bar-wrap" style="width:100%">
            <div class="progress-bar" id="progress-bar"
                 style="width:<?= $total_students>0?round($marked_students/$total_students*100):0 ?>%"></div>
        </div>
    </div>
</div>

<div class="card">
    <!-- Toolbar -->
    <div class="toolbar">
        <div class="toolbar-left">
            <div class="search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" id="student-search" placeholder="Search student name…">
            </div>
            <span class="badge-count" id="showing-count"><?= $total_students ?> students</span>
        </div>
        <div class="toolbar-right">
            <span class="save-indicator" id="save-indicator">
                <i class="fas fa-circle-check"></i> All changes saved
            </span>
            <a href="sel_aoi_add_marks.php?class=<?= urlencode($class) ?>&term=<?= urlencode($term) ?>&year=<?= $year ?>&subject=<?= urlencode($subject) ?><?php foreach($streams as $s) echo '&streams[]='.urlencode($s); ?>"
               class="btn btn-outline btn-sm" style="padding:7px 14px;font-size:.8rem">
                <i class="fas fa-arrow-left"></i> Back to Topics
            </a>
        </div>
    </div>

    <!-- Table -->
    <?php if(empty($students)): ?>
    <div class="empty-state">
        <i class="fas fa-user-graduate"></i>
        <p>No students found for the selected class and streams.</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
    <table id="marks-table">
        <thead>
            <tr>
                <th style="width:44px">#</th>
                <th>Student</th>
                <th style="width:80px">Stream</th>
                <?php foreach($aoi_topic_details as $tid=>$topic):
                    $is_eot = ($tid==='EOT'); ?>
                <th class="topic-col-head center">
                    <?= htmlspecialchars($topic['topic']) ?>
                    <small><?= $is_eot?'/ 100':'/ 3.0' ?></small>
                </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach($students as $i=>$student):
            $sid = $student['student_id'];
            $all_marked = true;
            foreach($aoi_topics as $tid) { if(!isset($existing_marks[$sid][$tid])) {$all_marked=false; break;} }
        ?>
        <tr class="student-row <?= $all_marked?'fully-marked':'' ?>"
            data-name="<?= htmlspecialchars(strtolower($student['first_name'].' '.$student['last_name'])) ?>">
            <td style="color:#8a9a8b;font-size:.78rem"><?= $i+1 ?></td>
            <td>
                <div class="sname"><?= htmlspecialchars($student['last_name'].', '.$student['first_name']) ?></div>
                <div class="sstream"><?= htmlspecialchars($sid) ?></div>
            </td>
            <td>
                <span class="badge badge-aoi"><?= htmlspecialchars($student['stream']) ?></span>
            </td>
            <?php foreach($aoi_topic_details as $tid=>$topic):
                $is_eot  = ($tid==='EOT');
                $cur_val = isset($existing_marks[$sid][$tid]) ? number_format((float)$existing_marks[$sid][$tid],1,'.','') : '';
                $has_val = $cur_val !== '';
            ?>
            <td class="center">
                <input type="text"
                       class="mark-input <?= $has_val?'has-value':'' ?>"
                       inputmode="decimal"
                       value="<?= htmlspecialchars($cur_val) ?>"
                       data-original="<?= htmlspecialchars($cur_val) ?>"
                       data-student-id="<?= htmlspecialchars($sid) ?>"
                       data-topic-id="<?= htmlspecialchars((string)$tid) ?>"
                       data-student="<?= htmlspecialchars($student['first_name'].' '.$student['last_name']) ?>"
                       data-stream="<?= htmlspecialchars($student['stream']) ?>"
                       data-is-eot="<?= $is_eot?'1':'0' ?>"
                       placeholder="<?= $is_eot?'0–100':'0.9–3' ?>"
                       autocomplete="off">
            </td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <!-- Pagination -->
    <div class="pagination">
        <span class="page-info" id="page-info"></span>
        <div class="page-btns" id="page-btns"></div>
    </div>
    <?php endif; ?>
</div>

<button id="back-top" title="Back to top"><i class="fas fa-chevron-up"></i></button>
</div>

<?php marks_notify_js(); ?>
<script>
(function(){
const CLASS   = document.getElementById('js-class').value;
const SUBJECT = document.getElementById('js-subject').value;
const TABLE   = document.getElementById('js-table').value;
const PER_PAGE = 30;
let currentPage = 1;
let saveTimer   = null;
let pendingCount = 0;

// ── Rows & search ─────────────────────────────────────────
const allRows = [...document.querySelectorAll('tr.student-row')];
let filteredRows = allRows;

document.getElementById('student-search').addEventListener('input', function(){
    const q = this.value.toLowerCase().trim();
    filteredRows = q ? allRows.filter(r=>r.dataset.name.includes(q)) : allRows;
    currentPage = 1;
    renderPage();
});

function renderPage(){
    const start = (currentPage-1)*PER_PAGE;
    const end   = start+PER_PAGE;
    allRows.forEach(r=>r.style.display='none');
    filteredRows.slice(start,end).forEach(r=>r.style.display='');
    const total = filteredRows.length;
    document.getElementById('showing-count').textContent=total+(total===1?' student':' students');
    // Page info
    const s=total===0?0:start+1, e=Math.min(start+PER_PAGE,total);
    document.getElementById('page-info').textContent=`Showing ${s}–${e} of ${total}`;
    buildPager(total);
}

function buildPager(total){
    const pages = Math.ceil(total/PER_PAGE);
    const btns  = document.getElementById('page-btns');
    btns.innerHTML='';
    if(pages<=1)return;
    const prev=mkBtn('<i class="fas fa-chevron-left"></i>',currentPage===1);
    prev.addEventListener('click',()=>{if(currentPage>1){currentPage--;renderPage();}});
    btns.appendChild(prev);
    for(let p=1;p<=pages;p++){
        const b=mkBtn(p,false);
        if(p===currentPage)b.classList.add('active');
        b.addEventListener('click',()=>{currentPage=p;renderPage();});
        btns.appendChild(b);
    }
    const next=mkBtn('<i class="fas fa-chevron-right"></i>',currentPage===pages);
    next.addEventListener('click',()=>{if(currentPage<pages){currentPage++;renderPage();}});
    btns.appendChild(next);
}

function mkBtn(html,disabled){
    const b=document.createElement('button');
    b.className='page-btn'; b.innerHTML=html; b.disabled=disabled; return b;
}

// ── Mark validation ───────────────────────────────────────
const EOT_PATTERN = /^(100(\.0)?|\d{1,2}(\.\d)?)$/;
const AOI_PATTERN = /^([0-2](\.\d)?|3(\.0)?|0\.9)$/;

function validateMark(input){
    const v = input.value.trim();
    const isEOT = input.dataset.isEot==='1';
    if(v===''){
        input.classList.remove('invalid','has-value');
        return true;
    }
    const valid = isEOT ? EOT_PATTERN.test(v) : AOI_PATTERN.test(v);
    if(!valid){
        input.classList.add('invalid'); input.classList.remove('has-value');
        notify('Invalid mark',isEOT?'EOT mark must be 0–100':'AOI mark must be 0.9–3.0','warning');
        return false;
    }
    if(!isEOT && parseFloat(v)<0.9){
        input.classList.add('invalid'); input.classList.remove('has-value');
        notify('Invalid mark','AOI mark cannot be less than 0.9','warning');
        return false;
    }
    input.classList.remove('invalid');
    input.value = parseFloat(v).toFixed(1);
    return true;
}

// ── Save indicator ────────────────────────────────────────
const indicator = document.getElementById('save-indicator');
function setSaving(){
    pendingCount++;
    indicator.className='save-indicator saving';
    indicator.innerHTML='<i class="fas fa-circle-notch fa-spin"></i> Saving…';
}
function setSaved(){
    pendingCount=Math.max(0,pendingCount-1);
    if(pendingCount>0)return;
    indicator.className='save-indicator saved';
    indicator.innerHTML='<i class="fas fa-circle-check"></i> All saved';
}
function setSaveError(){
    pendingCount=Math.max(0,pendingCount-1);
    indicator.className='save-indicator error';
    indicator.innerHTML='<i class="fas fa-circle-exclamation"></i> Save failed';
}

// ── Mark save ─────────────────────────────────────────────
function saveMark(input){
    const sid    = input.dataset.studentId;
    const tid    = input.dataset.topicId;
    const val    = input.value.trim();
    const orig   = input.dataset.original;
    const stream = input.closest('tr').querySelector('.badge-aoi').textContent.trim();

    if(val===orig) return;

    const isDelete = val==='';
    const fd = new FormData();
    fd.append('student_id', sid);
    fd.append('topic_id',   tid);
    fd.append('mark',       val);
    fd.append('class',      CLASS);
    fd.append('stream',     stream);
    fd.append('subject',    SUBJECT);
    fd.append('table',      TABLE);
    fd.append('action',     isDelete?'delete':'update');

    setSaving();
    input.classList.add('saving');

    fetch('save_marks.php',{method:'POST',body:fd})
        .then(r=>{if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
        .then(data=>{
            input.classList.remove('saving');
            if(data.status==='success'){
                if(isDelete){
                    input.classList.remove('has-value'); input.dataset.original='';
                } else {
                    input.value=data.mark; input.dataset.original=data.mark;
                    input.classList.add('has-value');
                }
                updateRowState(input.closest('tr'));
                updateProgress();
                setSaved();
            } else {
                setSaveError();
                notify('Save failed', data.message||'Could not save mark.','error');
                input.value=orig; input.classList.toggle('has-value',orig!=='');
            }
        })
        .catch(()=>{
            input.classList.remove('saving'); setSaveError();
            notify('Network error','Could not reach server. Check your connection.','error');
            input.value=orig; input.classList.toggle('has-value',orig!=='');
        });
}

// ── Row fully-marked highlight ────────────────────────────
function updateRowState(row){
    const inputs=[...row.querySelectorAll('.mark-input')];
    const allFilled=inputs.every(i=>i.dataset.original!=='');
    row.classList.toggle('fully-marked',allFilled);
}

// ── Progress counter ──────────────────────────────────────
function updateProgress(){
    const total=allRows.length;
    const marked=allRows.filter(r=>r.querySelectorAll('.mark-input.has-value').length>0).length;
    document.getElementById('marked-count').textContent=marked;
    const pct=total>0?Math.round(marked/total*100):0;
    document.getElementById('progress-bar').style.width=pct+'%';
}

// ── Event binding ─────────────────────────────────────────
document.querySelectorAll('.mark-input').forEach(input=>{
    input.addEventListener('blur',function(){
        if(!validateMark(this))return;
        clearTimeout(saveTimer);
        saveMark(this);
    });
    input.addEventListener('keydown',function(e){
        if(e.key==='Enter'){
            e.preventDefault();
            if(!validateMark(this))return;
            saveMark(this);
            // Move to next input in same column
            const inputs=[...document.querySelectorAll('.mark-input[data-topic-id="'+this.dataset.topicId+'"]')];
            const idx=inputs.indexOf(this);
            if(idx<inputs.length-1){
                let next=inputs[idx+1];
                while(next&&next.closest('tr').style.display==='none'&&idx+2<inputs.length)next=inputs[idx+2];
                if(next)next.focus();
            }
        }
        if(e.key==='Escape'){this.value=this.dataset.original;this.classList.remove('invalid');}
    });
    input.addEventListener('input',function(){
        this.classList.remove('invalid');
        if(this.value.trim()!==this.dataset.original){
            clearTimeout(saveTimer);
            saveTimer=setTimeout(()=>{if(validateMark(this))saveMark(this);},1200);
        }
    });
});

// ── Back to top ───────────────────────────────────────────
const btt=document.getElementById('back-top');
window.addEventListener('scroll',()=>{btt.style.display=window.scrollY>300?'flex':'none';});
btt.addEventListener('click',()=>window.scrollTo({top:0,behavior:'smooth'}));

renderPage();
})();
</script>
</body>
</html>