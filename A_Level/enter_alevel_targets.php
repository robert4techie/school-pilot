<?php
/*
 * enter_alevel_targets.php  –  Step 2: A-Level target marks entry.
 * Tabs: MID (exam_type '5') and EOT (exam_type '3'), both 0–100%.
 * Students filtered via student_alevel_subjects.
 * Place in the same directory as enter_targets.php  (O_Level/).
 */
require_once '../auth.php';
require_once '../conn.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$allowed_roles = ['developer', 'super user', 'subject teacher', 'class teacher', 'school leader'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles, true)) {
    header('Location: ../index.php'); exit;
}
$role     = $_SESSION['role'];
$staff_id = $_SESSION['staff_id'] ?? '';
$is_super = in_array($role, ['developer', 'super user', 'school leader']);

$class   = isset($_GET['class'])   ? htmlspecialchars(trim($_GET['class']))   : '';
$term    = isset($_GET['term'])    ? htmlspecialchars(trim($_GET['term']))     : '';
$year    = isset($_GET['year'])    ? (int)$_GET['year']                       : (int)date('Y');
$streams = isset($_GET['streams']) ? array_filter(array_map('trim',(array)$_GET['streams'])) : [];
$subject = isset($_GET['subject']) ? htmlspecialchars(trim($_GET['subject']))  : '';

$alevel_classes = ['Senior Five', 'Senior Six'];
if (!$class || !$term || !$year || empty($streams) || !$subject ||
    !in_array($class, $alevel_classes)) {
    header('Location: sel_alevel_targets.php'); exit;
}

// Permission check
if (!$is_super) {
    $ps = mysqli_prepare($conn,
        "SELECT COUNT(*) AS cnt FROM teaching_assignments ta
         INNER JOIN subjects s ON s.subj_id=ta.subject_id
         WHERE ta.staff_id=? AND ta.class_name=? AND s.subj_name=?");
    $ok = false;
    if ($ps) {
        mysqli_stmt_execute($ps, [$staff_id, $class, $subject]);
        $ok = ((int)mysqli_fetch_assoc(mysqli_stmt_get_result($ps))['cnt']) > 0;
        mysqli_stmt_close($ps);
    }
    if (!$ok) { header('Location: sel_alevel_targets.php'); exit; }
}

// A-level marks table: {year}_{term_roman}_alevel
$term_num   = (int)preg_replace('/\D/', '', $term);
$term_roman = ['i','ii','iii'][max(0, min(2, $term_num - 1))];
$marks_table = "{$year}_{$term_roman}_alevel";

// Exact table existence check
$marks_exist = false;
$tc = mysqli_prepare($conn,
    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
if ($tc) {
    mysqli_stmt_execute($tc, [$marks_table]);
    mysqli_stmt_store_result($tc);
    $marks_exist = mysqli_stmt_num_rows($tc) > 0;
    mysqli_stmt_close($tc);
}

// ── Fetch students via student_alevel_subjects ────────────────
// General Paper is done by ALL A-level students — bypass the enrollment JOIN
// and pull every active student in the selected class + streams directly.
$stream_ph = implode(',', array_fill(0, count($streams), '?'));
$is_general_paper = (strtolower(trim($subject)) === 'general paper');

if ($is_general_paper) {
    $ss = mysqli_prepare($conn,
        "SELECT student_id, first_name, last_name, stream
         FROM students
         WHERE current_class = ?
           AND stream IN ($stream_ph)
           AND status = 'active'
         ORDER BY stream ASC, last_name ASC, first_name ASC");
    $ss_params = array_merge([$class], $streams);
} else {
    $ss = mysqli_prepare($conn,
        "SELECT s.student_id, s.first_name, s.last_name, s.stream
         FROM students s
         INNER JOIN student_alevel_subjects sas
             ON sas.student_id = s.student_id
            AND sas.subject    = ?
            AND sas.class      = s.current_class
         WHERE s.current_class = ?
           AND s.stream IN ($stream_ph)
           AND s.status = 'active'
         ORDER BY s.stream ASC, s.last_name ASC, s.first_name ASC");
    $ss_params = array_merge([$subject, $class], $streams);
}

$students = [];
if ($ss) {
    mysqli_stmt_execute($ss, $ss_params);
    $res = mysqli_stmt_get_result($ss);
    while ($r = mysqli_fetch_assoc($res)) $students[] = $r;
    mysqli_stmt_close($ss);
}

// ── Ensure target table (v2 schema) ──────────────────────────
mysqli_query($conn,
    "CREATE TABLE IF NOT EXISTS `student_target_marks` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `student_id` varchar(30) NOT NULL,
        `class` varchar(50) NOT NULL,
        `stream` varchar(50) NOT NULL,
        `subject` varchar(100) NOT NULL,
        `term` varchar(20) NOT NULL,
        `year` varchar(10) NOT NULL,
        `target_type` varchar(20) NOT NULL DEFAULT 'eot',
        `target_value` decimal(6,2) NOT NULL DEFAULT 0.00,
        `added_by` varchar(50) NOT NULL,
        `date_added` timestamp NOT NULL DEFAULT current_timestamp(),
        `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_student_target_v2` (`student_id`,`subject`,`term`,`year`,`target_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// ── Fetch existing targets (mid + eot) ────────────────────────
$targets = [];
if (!empty($students)) {
    $ids = array_column($students, 'student_id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $tq  = mysqli_prepare($conn,
        "SELECT student_id, target_type, target_value
         FROM student_target_marks
         WHERE student_id IN ($ph) AND subject=? AND term=? AND year=?
           AND target_type IN ('mid','eot')");
    if ($tq) {
        mysqli_stmt_execute($tq, array_merge($ids, [$subject, $term, (string)$year]));
        $res = mysqli_stmt_get_result($tq);
        while ($r = mysqli_fetch_assoc($res)) {
            $targets[$r['student_id']][$r['target_type']] = (float)$r['target_value'];
        }
        mysqli_stmt_close($tq);
    }
}

// ── Fetch actual marks from alevel table ─────────────────────
// exam_type '3' = EOT,  '5' = MID  (matches exam_sets table IDs)
// Multiple papers per subject → average them
$actual_marks = [];
$show_actual  = false;

if ($marks_exist && !empty($students)) {
    $ids = array_column($students, 'student_id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $mq  = mysqli_prepare($conn,
    "SELECT student_id, exam_type, ROUND(AVG(mark), 1) AS avg_mark
     FROM `$marks_table`
     WHERE student_id IN ($ph) AND class=? AND subject=?
       AND exam_type IN ('3','5')
     GROUP BY student_id, exam_type");
if ($mq) {
    mysqli_stmt_execute($mq, array_merge($ids, [$class, $subject]));
    $res = mysqli_stmt_get_result($mq);
    $raw = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $type = $r['exam_type'] === '5' ? 'mid' : 'eot';
        $raw[$r['student_id']][$type] = (float)$r['avg_mark'];
    }
        mysqli_stmt_close($mq);
        if (!empty($raw)) {
            $show_actual = true;
            foreach ($students as $stu) {
                $sid = $stu['student_id'];
                $actual_marks[$sid] = [
                    'mid' => $raw[$sid]['mid'] ?? null,
                    'eot' => $raw[$sid]['eot'] ?? null,
                ];
            }
        }
    }
}

// ── Stats ─────────────────────────────────────────────────────
$count_set = ['mid' => 0, 'eot' => 0];
foreach ($students as $stu) {
    $sid = $stu['student_id'];
    foreach (array_keys($count_set) as $type) {
        if (isset($targets[$sid][$type])) $count_set[$type]++;
    }
}

$school_name = 'School';
$sp = mysqli_query($conn, "SELECT school_name FROM school_profile LIMIT 1");
if ($sp && $r = mysqli_fetch_assoc($sp)) $school_name = $r['school_name'];

$total_students = count($students);
$by_stream = [];
foreach ($students as $s) $by_stream[$s['stream']][] = $s;

// Status helpers
function alStatus(mixed $actual, mixed $target): string {
    if ($actual === null || $target === null) return 'pending';
    $diff = (float)$actual - (float)$target;
    if ($diff >= 0) return 'achieved';
    if ($diff >= -5) return 'close';
    return 'below';
}
function alBadge(string $status): string {
    return match($status) {
        'achieved' => '<span class="tgt-badge achieved"><i class="fas fa-check"></i>Achieved</span>',
        'close'    => '<span class="tgt-badge close"><i class="fas fa-equals"></i>Close</span>',
        'below'    => '<span class="tgt-badge below"><i class="fas fa-arrow-down"></i>Below</span>',
        default    => '<span class="tgt-badge pending"><i class="fas fa-clock"></i>Pending</span>',
    };
}

require_once '../nav.php';
?>
<style>
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g200:#c8e6c9;--g100:#e8f5e9;--g50:#f1f8f1;
  --sp-red:#c62828;--sp-red-bg:#ffebee;--sp-orange:#e65100;--sp-orange-bg:#fff3e0;
  --sp-blue:#1565c0;--sp-blue-bg:#e3f2fd;
  --sp-rad:8px;--sp-radlg:12px;
  --sp-sh:0 2px 8px rgba(0,0,0,.10);--sp-shlg:0 8px 28px rgba(0,0,0,.14);
  --sp-tr:.22s ease;
}
.tgt-page{max-width:100%;margin:0 auto;padding:0 18px 60px}

.tgt-ph{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--sp-radlg);padding:22px 28px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:18px;box-shadow:var(--sp-shlg)}
.tgt-ph h1{color:#fff;font-size:1.25rem;font-weight:700;margin-bottom:2px}
.tgt-ph p{color:rgba(255,255,255,.8);font-size:.83rem}
.tgt-ph-pills{display:flex;gap:8px;flex-wrap:wrap}
.tgt-ph-pill{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22);border-radius:20px;padding:5px 13px;font-size:.78rem;color:#fff;font-weight:600;display:flex;align-items:center;gap:5px}
.alevel-chip{background:rgba(255,255,255,.22);border:1px solid rgba(255,255,255,.35)}

/* ── Tabs ─────────────────────────────────────────── */
.tgt-tabs{display:flex;gap:0;background:#fff;border-radius:var(--sp-radlg);box-shadow:var(--sp-sh);overflow:hidden;margin-bottom:16px}
.tgt-tab{flex:1;padding:16px 8px;border:none;background:#fff;cursor:pointer;font-family:inherit;font-size:.88rem;font-weight:600;color:#666;transition:all var(--sp-tr);display:flex;flex-direction:column;align-items:center;gap:5px;border-right:1px solid #f0f4f1;position:relative}
.tgt-tab:last-child{border-right:none}
.tgt-tab:hover:not(.active){background:var(--g50);color:var(--g700)}
.tgt-tab.active{background:var(--g700);color:#fff}
.tgt-tab.active::after{content:'';position:absolute;bottom:-1px;left:50%;transform:translateX(-50%);width:0;height:0;border-left:8px solid transparent;border-right:8px solid transparent;border-top:8px solid var(--g700)}
.tgt-tab-label{font-size:1rem;font-weight:700;letter-spacing:.5px}
.tgt-tab-sub{font-size:.72rem;opacity:.8}
.tgt-tab-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 10px;border-radius:20px;font-size:.7rem;font-weight:700;background:rgba(0,0,0,.08);white-space:nowrap}
.tgt-tab.active .tgt-tab-badge{background:rgba(255,255,255,.22);color:#fff}
.tgt-tab-badge.complete{background:rgba(0,200,80,.18);color:var(--g800)}
.tgt-tab.active .tgt-tab-badge.complete{background:rgba(255,255,255,.28);color:#fff}

/* ── Toolbar ─────────────────────────────────────── */
.tgt-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px;background:#fff;border-radius:var(--sp-radlg);padding:12px 18px;box-shadow:var(--sp-sh)}
.tgt-tl-left{display:flex;align-items:center;gap:10px;flex:1;flex-wrap:wrap}
.tgt-tl-right{display:flex;gap:8px;flex-wrap:wrap}
.tgt-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border:none;border-radius:var(--sp-rad);font-size:.83rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all var(--sp-tr);white-space:nowrap;text-decoration:none}
.tgt-btn-primary{background:var(--g700);color:#fff}.tgt-btn-primary:hover{background:var(--g800)}
.tgt-btn-outline{background:#fff;color:var(--g700);border:1.5px solid var(--g400)}.tgt-btn-outline:hover{background:var(--g50)}
.tgt-btn-pdf{background:var(--sp-red);color:#fff}.tgt-btn-pdf:hover{background:#b71c1c}
.tgt-btn-excel{background:var(--g800);color:#fff}.tgt-btn-excel:hover{background:var(--g900)}
.tgt-btn-tpl{background:#5c6bc0;color:#fff}.tgt-btn-tpl:hover{background:#3949ab}
.tgt-btn-back{background:#fff;color:#555;border:1.5px solid #d0dbd1}.tgt-btn-back:hover{border-color:var(--g600);color:var(--g700)}
.tgt-btn:disabled{opacity:.5;cursor:not-allowed}

.tgt-prog{flex:1;min-width:180px;max-width:280px}
.tgt-prog-lbl{display:flex;justify-content:space-between;font-size:.77rem;font-weight:600;color:#555;margin-bottom:4px}
.tgt-prog-track{height:7px;background:#e8ede9;border-radius:20px;overflow:hidden}
.tgt-prog-fill{height:100%;background:linear-gradient(90deg,var(--g600),var(--g800));border-radius:20px;transition:width .4s ease}

/* ── Search ──────────────────────────────────────── */
.tgt-search-row{display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.tgt-search-box{position:relative;flex:1;min-width:220px;max-width:400px}
.tgt-search-box input{width:100%;padding:9px 40px 9px 38px;border:2px solid #d0dbd1;border-radius:var(--sp-radlg);font-size:.88rem;font-family:inherit;color:#222;background:#fff;transition:border-color var(--sp-tr),box-shadow var(--sp-tr);box-shadow:var(--sp-sh)}
.tgt-search-box input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.tgt-search-box input::placeholder{color:#bbb}
.tgt-si-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--g600);font-size:.9rem;pointer-events:none}
.tgt-si-clear{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#bbb;font-size:.85rem;padding:2px;display:none;line-height:1}
.tgt-si-clear:hover{color:var(--sp-red)}
.tgt-search-cnt{font-size:.82rem;color:#777;font-weight:600}
.tgt-search-cnt span{color:var(--g700)}

/* ── Stats ───────────────────────────────────────── */
.tgt-stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.tgt-stat{background:#fff;border-radius:var(--sp-rad);padding:11px 15px;box-shadow:var(--sp-sh);display:flex;align-items:center;gap:10px;flex:1;min-width:120px}
.tgt-si-pill{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0}
.tgt-si-pill.blue{background:var(--g100);color:var(--g700)}
.tgt-si-pill.green{background:#e8f5e9;color:#388e3c}
.tgt-si-pill.orange{background:var(--sp-orange-bg);color:var(--sp-orange)}
.tgt-si-pill.red{background:var(--sp-red-bg);color:var(--sp-red)}
.tgt-sv{font-size:1.15rem;font-weight:700;color:#1a1a1a;line-height:1}
.tgt-sl{font-size:.72rem;color:#777;margin-top:2px}

/* ── Cards & table ───────────────────────────────── */
.tgt-card{background:#fff;border-radius:var(--sp-radlg);box-shadow:var(--sp-sh);overflow:hidden;margin-bottom:14px}
.tgt-card-head{background:linear-gradient(90deg,var(--g900) 0%,var(--g700) 100%);padding:10px 16px;display:flex;align-items:center;justify-content:space-between}
.tgt-card-head h3{color:#fff;font-size:.9rem;font-weight:700;display:flex;align-items:center;gap:7px}
.tgt-stream-badge{background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:20px;padding:2px 10px;font-size:.72rem;font-weight:600}
.tgt-tbl-wrap{overflow-x:auto}
table.tgt-table{width:100%;border-collapse:collapse;font-size:.875rem}
.tgt-table th{padding:9px 11px;text-align:left;font-weight:700;font-size:.77rem;color:var(--g900);white-space:nowrap;border-bottom:2px solid var(--g200);background:var(--g50)}
.tgt-table th.tc,.tgt-table td.tc{text-align:center}
.tgt-table tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--sp-tr)}
.tgt-table tbody tr.row-hidden{display:none}
.tgt-table tbody tr:hover:not(.row-hidden){background:var(--g50)}
.tgt-table td{padding:8px 11px;vertical-align:middle}
.td-no{color:#aaa;font-size:.75rem;font-weight:600;width:32px}
.td-id{font-size:.77rem;color:#888;font-family:'Courier New',monospace}
.td-name{font-weight:600;color:#1a1a1a}

/* ── Input ───────────────────────────────────────── */
.tgt-inp-wrap{position:relative;display:inline-flex;align-items:center;min-width:90px}
input.tgt-input{width:84px;padding:7px 26px 7px 10px;border:1.5px solid #d0dbd1;border-radius:var(--sp-rad);font-size:.92rem;font-family:inherit;font-weight:700;color:#1a1a1a;text-align:center;transition:border-color var(--sp-tr),box-shadow var(--sp-tr);-moz-appearance:textfield}
input.tgt-input::-webkit-outer-spin-button,input.tgt-input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
input.tgt-input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
input.tgt-input.tgt-saved{border-color:var(--g400);background:var(--g50)}
input.tgt-input.tgt-saving{border-color:var(--sp-orange)}
input.tgt-input.tgt-err{border-color:var(--sp-red);background:#fff5f5}
.tgt-unit{position:absolute;right:7px;font-size:.72rem;color:#bbb;pointer-events:none;font-weight:600}
.tgt-save-icon{margin-left:5px;font-size:.72rem;flex-shrink:0}
.tgt-save-icon.ok{color:var(--g600)}.tgt-save-icon.spin{color:var(--sp-orange);animation:tSpin .6s linear infinite}.tgt-save-icon.err{color:var(--sp-red)}
@keyframes tSpin{to{transform:rotate(360deg)}}

.td-actual{font-size:.88rem;color:#555;font-weight:500}
.td-diff{font-size:.85rem;font-weight:700}
.td-diff.pos{color:var(--g700)}.td-diff.neg{color:var(--sp-red)}
.td-dash{color:#ccc}

.tgt-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:700;white-space:nowrap}
.tgt-badge.achieved{background:#e8f5e9;color:var(--g800)}
.tgt-badge.close{background:#e3f2fd;color:var(--sp-blue)}
.tgt-badge.below{background:var(--sp-red-bg);color:var(--sp-red)}
.tgt-badge.pending{background:#f5f5f5;color:#aaa}

.tgt-panel{display:none}.tgt-panel.active{display:block}
.tgt-no-match{text-align:center;padding:18px;color:#aaa;font-size:.86rem;display:none}
.tgt-no-match.visible{display:block}
.tgt-empty-panel{text-align:center;padding:40px 24px;color:#aaa}
.tgt-empty-panel i{font-size:2rem;display:block;margin-bottom:8px;color:#ddd}

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

<!-- ── Page header ───────────────────────────────── -->
<div class="tgt-ph">
  <div>
    <h1><i class="fas fa-graduation-cap" style="margin-right:8px"></i>
        A-Level Target Marks — <?= htmlspecialchars($class) ?></h1>
    <p>Set MID and EOT exam targets — each out of 100%</p>
  </div>
  <div class="tgt-ph-pills">
    <span class="tgt-ph-pill alevel-chip"><i class="fas fa-graduation-cap"></i>A-LEVEL</span>
    <span class="tgt-ph-pill"><i class="fas fa-book"></i><?= htmlspecialchars($subject) ?></span>
    <span class="tgt-ph-pill"><i class="fas fa-calendar"></i><?= htmlspecialchars($term) ?> <?= $year ?></span>
    <span class="tgt-ph-pill"><i class="fas fa-users"></i><?= $total_students ?> students</span>
    <?php if ($show_actual): ?>
    <span class="tgt-ph-pill"><i class="fas fa-check-circle"></i>Marks loaded</span>
    <?php else: ?>
    <span class="tgt-ph-pill" style="background:rgba(255,200,0,.18)"><i class="fas fa-clock"></i>No marks yet</span>
    <?php endif; ?>
  </div>
</div>

<!-- ── Tabs (MID + EOT only) ──────────────────────── -->
<div class="tgt-tabs" id="tgt-tabs">
  <?php
  $tab_defs = ['mid' => 'MID', 'eot' => 'EOT'];
  $tab_subs = ['mid' => 'Midterm exam', 'eot' => 'End of term'];
  $first = true;
  foreach ($tab_defs as $type => $label):
      $set = $count_set[$type];
      $all = ($set === $total_students && $total_students > 0);
  ?>
  <button class="tgt-tab <?= $first?'active':'' ?>"
          data-type="<?= $type ?>" onclick="alSwitchTab('<?= $type ?>')">
    <span class="tgt-tab-label"><?= $label ?></span>
    <span class="tgt-tab-sub"><?= $tab_subs[$type] ?></span>
    <span class="tgt-tab-badge <?= $all?'complete':'' ?>" id="tab-badge-<?= $type ?>">
      <?= $all?'<i class="fas fa-check" style="font-size:.6rem"></i>':'' ?>
      <?= $set ?>/<?= $total_students ?>
    </span>
  </button>
  <?php $first = false; endforeach; ?>
</div>

<!-- ── Toolbar ────────────────────────────────────── -->
<div class="tgt-toolbar">
  <div class="tgt-tl-left">
    <a href="sel_alevel_targets.php" class="tgt-btn tgt-btn-back"><i class="fas fa-arrow-left"></i> Back</a>
    <div class="tgt-prog">
      <div class="tgt-prog-lbl">
        <span id="al-prog-type">MID targets</span>
        <span id="al-prog-lbl"><?= $count_set['mid'] ?> / <?= $total_students ?></span>
      </div>
      <div class="tgt-prog-track">
        <div class="tgt-prog-fill" id="al-prog-fill"
             style="width:<?= $total_students>0?round($count_set['mid']/$total_students*100):0 ?>%"></div>
      </div>
    </div>
  </div>
  <div class="tgt-tl-right">
    <button class="tgt-btn tgt-btn-outline" id="al-save-all-btn" onclick="alSaveAll()">
      <i class="fas fa-floppy-disk"></i> Save All
    </button>
    <button class="tgt-btn tgt-btn-tpl" onclick="alPrintTemplate()">
      <i class="fas fa-print"></i> Template
    </button>
    <button class="tgt-btn tgt-btn-excel" onclick="alExportExcel()">
      <i class="fas fa-file-excel"></i> Excel
    </button>
    <button class="tgt-btn tgt-btn-pdf" onclick="alExportPDF()">
      <i class="fas fa-file-pdf"></i> PDF
    </button>
  </div>
</div>

<!-- ── Stats ─────────────────────────────────────── -->
<div class="tgt-stats">
  <div class="tgt-stat"><div class="tgt-si-pill blue"><i class="fas fa-users"></i></div>
    <div><div class="tgt-sv"><?= $total_students ?></div><div class="tgt-sl">Total Students</div></div></div>
  <div class="tgt-stat"><div class="tgt-si-pill blue"><i class="fas fa-bullseye"></i></div>
    <div><div class="tgt-sv" id="al-s-filled"><?= $count_set['mid'] ?></div><div class="tgt-sl">Targets Set</div></div></div>
  <div class="tgt-stat"><div class="tgt-si-pill orange"><i class="fas fa-hourglass-half"></i></div>
    <div><div class="tgt-sv" id="al-s-pending"><?= $total_students - $count_set['mid'] ?></div><div class="tgt-sl">Pending</div></div></div>
  <?php if ($show_actual): ?>
  <div class="tgt-stat"><div class="tgt-si-pill green"><i class="fas fa-arrow-up"></i></div>
    <div><div class="tgt-sv" id="al-s-above">—</div><div class="tgt-sl">Above Target</div></div></div>
  <div class="tgt-stat"><div class="tgt-si-pill red"><i class="fas fa-arrow-down"></i></div>
    <div><div class="tgt-sv" id="al-s-below">—</div><div class="tgt-sl">Below Target</div></div></div>
  <?php endif; ?>
</div>

<!-- ── Search ─────────────────────────────────────── -->
<?php if ($total_students > 0): ?>
<div class="tgt-search-row">
  <div class="tgt-search-box">
    <i class="fas fa-search tgt-si-icon"></i>
    <input type="text" id="al-search" placeholder="Search by name or student ID…" autocomplete="off">
    <button class="tgt-si-clear" id="al-si-clear" onclick="alClearSearch()" title="Clear">
      <i class="fas fa-times"></i>
    </button>
  </div>
  <span class="tgt-search-cnt">Showing <span id="al-vis-count"><?= $total_students ?></span> of <?= $total_students ?></span>
</div>
<div class="tgt-no-match" id="al-no-match">
  <i class="fas fa-search" style="font-size:1.3rem;color:#ddd;display:block;margin-bottom:6px"></i>
  No students match. <a href="#" onclick="alClearSearch();return false" style="color:var(--al3)">Clear search</a>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════ -->
<!--  TAB PANELS  (MID + EOT)                        -->
<!-- ════════════════════════════════════════════════ -->
<?php
$panel_meta = [
    'mid' => ['label'=>'MID',  'unit'=>'%', 'note'=>'Midterm exam'],
    'eot' => ['label'=>'EOT',  'unit'=>'%', 'note'=>'End of term exam'],
];
foreach ($panel_meta as $ptype => $pm):
    $active = ($ptype === 'mid') ? 'active' : '';
?>
<div class="tgt-panel <?= $active ?>" id="panel-<?= $ptype ?>" data-type="<?= $ptype ?>">

<?php if (empty($students)): ?>
  <div class="tgt-card"><div class="tgt-empty-panel">
    <i class="fas fa-user-slash"></i>
    <p>No active students found enrolled in <strong><?= htmlspecialchars($subject) ?></strong>
       for <strong><?= htmlspecialchars($class) ?></strong>.</p>
  </div></div>
<?php else:
  $gRow = 0;
  foreach ($by_stream as $stream_name => $stream_students):
?>
  <div class="tgt-card" id="al-card-<?= htmlspecialchars($ptype) ?>-<?= htmlspecialchars($stream_name) ?>">
    <div class="tgt-card-head">
      <h3><i class="fas fa-layer-group"></i><?= htmlspecialchars($class) ?>
          — <span class="tgt-stream-badge"><?= htmlspecialchars($stream_name) ?></span></h3>
      <span style="color:rgba(255,255,255,.75);font-size:.77rem"><?= count($stream_students) ?> students</span>
    </div>
    <div class="tgt-tbl-wrap">
      <table class="tgt-table">
        <thead>
          <tr>
            <th class="tc">#</th>
            <th>Student ID</th>
            <th>Full Name</th>
            <th class="tc" style="min-width:115px"><?= $pm['label'] ?> Target <small style="font-weight:400">%</small></th>
            <?php if ($show_actual): ?>
            <th class="tc">Actual <small style="font-weight:400">%</small></th>
            <th class="tc">Diff</th>
            <th class="tc">Status</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($stream_students as $stu):
            $gRow++;
            $sid    = $stu['student_id'];
            $tgt_v  = $targets[$sid][$ptype] ?? null;
            $act_v  = $actual_marks[$sid][$ptype] ?? null;
            $status = alStatus($act_v, $tgt_v);
            $diff   = ($tgt_v !== null && $act_v !== null) ? round($act_v - $tgt_v, 1) : null;
        ?>
          <tr data-student-id="<?= htmlspecialchars($sid) ?>"
              data-stream="<?= htmlspecialchars($stream_name) ?>"
              data-name="<?= htmlspecialchars(strtolower($stu['last_name'].' '.$stu['first_name'])) ?>"
              data-disp-name="<?= htmlspecialchars($stu['last_name'].' '.$stu['first_name']) ?>"
              <?php if ($show_actual): ?>
              data-actual="<?= $act_v !== null ? $act_v : '' ?>"
              <?php endif; ?>>
            <td class="td-no tc"><?= $gRow ?></td>
            <td class="td-id"><?= htmlspecialchars($sid) ?></td>
            <td class="td-name"><?= htmlspecialchars($stu['last_name'].' '.$stu['first_name']) ?></td>
            <td class="tc">
              <div class="tgt-inp-wrap">
                <input type="number"
                       class="tgt-input <?= $tgt_v!==null?'tgt-saved':'' ?>"
                       data-student-id="<?= htmlspecialchars($sid) ?>"
                       data-stream="<?= htmlspecialchars($stream_name) ?>"
                       data-target-type="<?= $ptype ?>"
                       value="<?= $tgt_v!==null?$tgt_v:'' ?>"
                       min="0" max="100" step="0.5" placeholder="e.g. 70">
                <span class="tgt-unit">%</span>
                <i class="tgt-save-icon <?= $tgt_v!==null?'fas fa-check ok':'' ?>"
                   id="ti-<?= htmlspecialchars($sid) ?>-<?= $ptype ?>"></i>
              </div>
            </td>
            <?php if ($show_actual): ?>
            <td class="tc td-actual">
              <?= $act_v!==null?$act_v.'%':'<span class="td-dash">—</span>' ?>
            </td>
            <td class="tc td-diff <?= $diff!==null?($diff>=0?'pos':'neg'):'' ?>"
                id="diff-<?= htmlspecialchars($sid) ?>-<?= $ptype ?>">
              <?= $diff!==null?($diff>=0?'+':'').$diff.'%':'<span class="td-dash">—</span>' ?>
            </td>
            <td class="tc" id="stat-<?= htmlspecialchars($sid) ?>-<?= $ptype ?>">
              <?= alBadge($status) ?>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; endif; ?>
</div><!-- /.tgt-panel -->
<?php endforeach; ?>

</div><!-- /.tgt-page -->
</div><!-- /.main-content -->

<div id="tgt-notif-stack"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>

<script>
const AL = {
    csrf:    '<?= htmlspecialchars($csrf) ?>',
    class:   '<?= addslashes($class) ?>',
    subject: '<?= addslashes($subject) ?>',
    term:    '<?= addslashes($term) ?>',
    year:    <?= $year ?>,
    total:   <?= $total_students ?>,
    actual:  <?= $show_actual?'true':'false' ?>,
    school:  '<?= addslashes($school_name) ?>',
    counts:  <?= json_encode($count_set) ?>,
};
let alTab = 'mid';

(function(){
'use strict';

function escH(v){return String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function ds(){return new Date().toISOString().split('T')[0];}
function notify(title,msg,type,dur){
    type=type||'info';dur=dur||4000;
    const ic={info:'fa-circle-info',success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation'};
    const n=document.createElement('div');
    n.className='tgt-notif '+type;
    n.innerHTML=`<i class="fas ${ic[type]} tgt-ni"></i>
        <div class="tgt-nb"><div class="tgt-nt">${escH(title)}</div><div class="tgt-nm">${escH(msg)}</div></div>
        <button class="tgt-nc" onclick="this.closest('.tgt-notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('tgt-notif-stack').prepend(n);
    setTimeout(()=>{n.style.opacity='0';n.style.transform='translateX(30px)';n.style.transition='.3s';setTimeout(()=>n.remove(),300);},dur);
}

// ── Tab switching ─────────────────────────────────────────────
window.alSwitchTab=function(type){
    alTab=type;
    document.querySelectorAll('.tgt-panel').forEach(p=>p.classList.remove('active'));
    document.getElementById('panel-'+type).classList.add('active');
    document.querySelectorAll('.tgt-tab').forEach(t=>t.classList.toggle('active',t.dataset.type===type));
    refreshProgress(type);
    refreshStatusStats(type);
    runSearch();
};

function refreshProgress(type){
    type=type||alTab;
    const panel=document.getElementById('panel-'+type);
    const set=panel.querySelectorAll('input.tgt-input.tgt-saved').length;
    const pct=AL.total>0?Math.round(set/AL.total*100):0;
    const labels={mid:'MID',eot:'EOT'};
    document.getElementById('al-prog-type').textContent=(labels[type]||type)+' targets';
    document.getElementById('al-prog-lbl').textContent=set+' / '+AL.total;
    document.getElementById('al-prog-fill').style.width=pct+'%';
    document.getElementById('al-s-filled').textContent=set;
    document.getElementById('al-s-pending').textContent=AL.total-set;
    const badge=document.getElementById('tab-badge-'+type);
    if(badge){
        const allDone=set===AL.total&&AL.total>0;
        badge.className='tgt-tab-badge'+(allDone?' complete':'');
        badge.innerHTML=(allDone?'<i class="fas fa-check" style="font-size:.6rem"></i>':'')+set+'/'+AL.total;
    }
}

function refreshStatusStats(type){
    if(!AL.actual)return;
    type=type||alTab;
    const panel=document.getElementById('panel-'+type);
    const aEl=document.getElementById('al-s-above');
    const bEl=document.getElementById('al-s-below');
    if(aEl)aEl.textContent=panel.querySelectorAll('.tgt-badge.achieved').length;
    if(bEl)bEl.textContent=panel.querySelectorAll('.tgt-badge.below').length;
}

// ── Update status after input change ─────────────────────────
function updateStatus(input){
    const type=input.dataset.targetType;
    const sid=input.dataset.studentId;
    const val=parseFloat(input.value);
    const tr=input.closest('tr');
    const actual=tr?parseFloat(tr.dataset.actual||''):NaN;
    const diffEl=document.getElementById('diff-'+sid+'-'+type);
    const statEl=document.getElementById('stat-'+sid+'-'+type);
    if(!diffEl||!statEl)return;
    if(isNaN(val)||isNaN(actual)){
        diffEl.innerHTML='<span class="td-dash">—</span>';diffEl.className='tc td-diff';
        statEl.innerHTML='<span class="tgt-badge pending"><i class="fas fa-clock"></i>Pending</span>';
        return;
    }
    const diff=Math.round((actual-val)*10)/10;
    diffEl.innerHTML=(diff>=0?'+':'')+diff+'%';
    diffEl.className='tc td-diff '+(diff>=0?'pos':'neg');
    if(diff>=0)       statEl.innerHTML='<span class="tgt-badge achieved"><i class="fas fa-check"></i>Achieved</span>';
    else if(diff>=-5) statEl.innerHTML='<span class="tgt-badge close"><i class="fas fa-equals"></i>Close</span>';
    else              statEl.innerHTML='<span class="tgt-badge below"><i class="fas fa-arrow-down"></i>Below</span>';
}

// ── AJAX save ────────────────────────────────────────────────
const timers={};
async function saveSingle(input){
    const type=input.dataset.targetType;
    const sid=input.dataset.studentId;
    const iconEl=document.getElementById('ti-'+sid+'-'+type);
    const val=input.value.trim();
    input.classList.remove('tgt-saved','tgt-saving','tgt-err');
    if(iconEl)iconEl.className='tgt-save-icon';
    if(val!==''){
        const num=parseFloat(val);
        if(isNaN(num)||num<0||num>100){
            input.classList.add('tgt-err');if(iconEl)iconEl.className='tgt-save-icon fas fa-xmark err';
            notify('Invalid','Target must be 0–100%.','warning');return;
        }
    }
    input.classList.add('tgt-saving');
    if(iconEl)iconEl.className='tgt-save-icon fas fa-spinner spin';
    try{
        const fd=new FormData();
        fd.append('csrf_token',AL.csrf);fd.append('student_id',sid);
        fd.append('class',AL.class);fd.append('stream',input.dataset.stream);
        fd.append('subject',AL.subject);fd.append('term',AL.term);fd.append('year',AL.year);
        fd.append('target_type',type);fd.append('target_value',val);
        const r=await fetch('ajax_save_target.php',{method:'POST',body:fd});
        const d=await r.json();
        input.classList.remove('tgt-saving');
        if(d.success){
            if(val!==''){input.classList.add('tgt-saved');if(iconEl)iconEl.className='tgt-save-icon fas fa-check ok';}
            else{if(iconEl)iconEl.className='';}
            if(AL.actual)updateStatus(input);
        }else{
            input.classList.add('tgt-err');if(iconEl)iconEl.className='tgt-save-icon fas fa-xmark err';
            notify('Save failed',d.message||'Could not save.','error');
        }
    }catch{
        input.classList.remove('tgt-saving');input.classList.add('tgt-err');
        if(iconEl)iconEl.className='tgt-save-icon fas fa-xmark err';
        notify('Network error','Check connection.','error');
    }
    refreshProgress(type);refreshStatusStats(type);
}

document.querySelectorAll('input.tgt-input').forEach(inp=>{
    inp.addEventListener('input',()=>{
        const key=inp.dataset.studentId+'-'+inp.dataset.targetType;
        if(timers[key])clearTimeout(timers[key]);
        timers[key]=setTimeout(()=>saveSingle(inp),700);
    });
    inp.addEventListener('blur',()=>{
        const key=inp.dataset.studentId+'-'+inp.dataset.targetType;
        if(timers[key]){clearTimeout(timers[key]);delete timers[key];}
        saveSingle(inp);
    });
    inp.addEventListener('keydown',e=>{
        if(e.key!=='Enter')return;e.preventDefault();
        const all=[...document.querySelectorAll('#panel-'+alTab+' input.tgt-input')]
                  .filter(el=>!el.closest('tr')?.classList.contains('row-hidden'));
        const i=all.indexOf(inp);
        if(i>=0&&i<all.length-1)all[i+1].focus();
    });
});

// ── Save all ─────────────────────────────────────────────────
window.alSaveAll=async function(){
    const panel=document.getElementById('panel-'+alTab);
    const btn=document.getElementById('al-save-all-btn');
    btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
    let ok=0,fail=0;
    for(const inp of panel.querySelectorAll('input.tgt-input')){
        const key=inp.dataset.studentId+'-'+inp.dataset.targetType;
        if(timers[key]){clearTimeout(timers[key]);delete timers[key];}
        const val=inp.value.trim();if(!val)continue;
        const num=parseFloat(val);if(isNaN(num)){fail++;continue;}
        try{
            const fd=new FormData();
            fd.append('csrf_token',AL.csrf);fd.append('student_id',inp.dataset.studentId);
            fd.append('class',AL.class);fd.append('stream',inp.dataset.stream);
            fd.append('subject',AL.subject);fd.append('term',AL.term);fd.append('year',AL.year);
            fd.append('target_type',alTab);fd.append('target_value',num);
            const r=await fetch('ajax_save_target.php',{method:'POST',body:fd});
            const d=await r.json();
            if(d.success){inp.classList.remove('tgt-err');inp.classList.add('tgt-saved');ok++;}
            else fail++;
        }catch{fail++;}
    }
    btn.disabled=false;btn.innerHTML='<i class="fas fa-floppy-disk"></i> Save All';
    refreshProgress(alTab);refreshStatusStats(alTab);
    fail===0?notify('Saved',ok+' target'+(ok!==1?'s':'')+' saved.','success')
            :notify('Partial',ok+' saved, '+fail+' failed.','warning');
};

// ── Search ───────────────────────────────────────────────────
const searchEl=document.getElementById('al-search');
const clearBtn=document.getElementById('al-si-clear');
const visCount=document.getElementById('al-vis-count');
const noMatch=document.getElementById('al-no-match');

function runSearch(){
    if(!searchEl)return;
    const q=searchEl.value.toLowerCase().trim();
    if(clearBtn)clearBtn.style.display=q?'':'none';
    document.querySelectorAll('.tgt-panel').forEach(panel=>{
        panel.querySelectorAll('tbody tr').forEach(tr=>{
            const name=tr.dataset.name||'';
            const id=(tr.dataset.studentId||'').toLowerCase();
            tr.classList.toggle('row-hidden',!(!q||name.includes(q)||id.includes(q)));
        });
        panel.querySelectorAll('.tgt-card').forEach(card=>{
            card.style.display=card.querySelectorAll('tbody tr:not(.row-hidden)').length>0?'':'none';
        });
    });
    const activePanel=document.getElementById('panel-'+alTab);
    const vis=activePanel.querySelectorAll('tbody tr:not(.row-hidden)').length;
    if(visCount)visCount.textContent=vis;
    if(noMatch)noMatch.classList.toggle('visible',vis===0&&q!=='');
}

window.alClearSearch=function(){
    if(searchEl){searchEl.value='';searchEl.focus();}
    runSearch();
};
if(searchEl){
    searchEl.addEventListener('input',runSearch);
    searchEl.addEventListener('keydown',e=>{if(e.key==='Escape')alClearSearch();});
}

// ── Export helpers ───────────────────────────────────────────
function getActiveRows(){
    const panel=document.getElementById('panel-'+alTab);
    const rows=[];let i=1;
    panel.querySelectorAll('tbody tr:not(.row-hidden)').forEach(tr=>{
        const sid=tr.dataset.studentId||'';
        const name=tr.dataset.dispName||tr.dataset.name||'';
        const stream=tr.dataset.stream||'';
        const inp=tr.querySelector('input.tgt-input');
        const tgt=inp?inp.value.trim():'';
        const obj={'#':i++,'Student ID':sid,'Full Name':name,'Stream':stream,'Target %':tgt};
        if(AL.actual){
            const tds=tr.querySelectorAll('.td-actual');
            obj['Actual %']=tds[0]?tds[0].textContent.trim().replace('—','').replace('%',''):'';
            const dEl=document.getElementById('diff-'+sid+'-'+alTab);
            obj['Diff']=dEl?dEl.textContent.trim().replace('—',''):'';
            const sEl=document.getElementById('stat-'+sid+'-'+alTab);
            obj['Status']=sEl?sEl.textContent.trim():'';
        }
        rows.push(obj);
    });
    return rows;
}

window.alExportExcel=function(){
    if(!window.XLSX){notify('Please wait','Library loading…','info');return;}
    const rows=getActiveRows();if(!rows.length){notify('Empty','No data.','warning');return;}
    const labels={mid:'MID',eot:'EOT'};
    const wb=window.XLSX.utils.book_new();
    const ws=window.XLSX.utils.json_to_sheet([]);
    window.XLSX.utils.sheet_add_aoa(ws,[[`${AL.school} — ${AL.class} A-Level — ${AL.subject} — ${labels[alTab]} Targets — ${AL.term} ${AL.year}`]],{origin:'A1'});
    window.XLSX.utils.sheet_add_json(ws,rows,{origin:'A3'});
    ws['!cols']=[{wch:4},{wch:22},{wch:30},{wch:12},{wch:12},{wch:12},{wch:8},{wch:12}];
    window.XLSX.utils.book_append_sheet(wb,ws,labels[alTab]+' Targets');
    window.XLSX.writeFile(wb,`alevel-targets-${AL.class.replace(/\s+/g,'-')}-${alTab}-${ds()}.xlsx`);
    notify('Exported','Excel downloaded.','success');
};

window.alExportPDF=function(){
    if(!window.jspdf){notify('Please wait','Library loading…','info');return;}
    const rows=getActiveRows();if(!rows.length){notify('Empty','No data.','warning');return;}
    const labels={mid:'MID',eot:'EOT'};
    const {jsPDF}=window.jspdf;
    const doc=new jsPDF({orientation:'portrait',unit:'mm'});
    const pw=doc.internal.pageSize.width;
    doc.setFontSize(13);doc.setTextColor(27,94,32);doc.text(AL.school,pw/2,14,{align:'center'});
    doc.setFontSize(9);doc.setTextColor(80);
    doc.text(`A-Level | ${AL.class} | ${AL.subject} | ${labels[alTab]} Targets | ${AL.term} ${AL.year}`,pw/2,21,{align:'center'});
    const keys=Object.keys(rows[0]);
    doc.autoTable({
        head:[keys],body:rows.map(r=>keys.map(k=>r[k]??'')),startY:26,
        styles:{fontSize:8.5,cellPadding:2.5},
        headStyles:{fillColor:[27,94,32],textColor:255,fontStyle:'bold'},
        alternateRowStyles:{fillColor:[241,248,241]},
        margin:{left:10,right:10},
        didDrawPage:()=>{doc.setFontSize(7);doc.setTextColor(180);
            doc.text('SchoolPilot — '+new Date().toLocaleString(),10,doc.internal.pageSize.height-5);}
    });
    doc.save(`alevel-targets-${AL.class.replace(/\s+/g,'-')}-${alTab}-${ds()}.pdf`);
    notify('Exported','PDF downloaded.','success');
};

// ── Print Template (both MID + EOT on separate pages) ────────
window.alPrintTemplate=function(){
    if(!window.jspdf){notify('Please wait','Library loading…','info');return;}
    const {jsPDF}=window.jspdf;
    const doc=new jsPDF({orientation:'landscape',unit:'mm',format:'a4'});
    const pw=doc.internal.pageSize.width;   // 297
    const ph=doc.internal.pageSize.height;  // 210
    const COL=[10,48,130,30,40];

    const typeDefs=[
        {type:'mid',label:'MID (Midterm Exam)',  note:'Enter target percentage  (e.g. 50  60  70  75  80  90)'},
        {type:'eot',label:'EOT (End of Term Exam)',note:'Enter target percentage  (e.g. 50  60  70  75  80  90)'},
    ];

    typeDefs.forEach((def,idx)=>{
        if(idx>0)doc.addPage();

        // Header
        doc.setTextColor(0,0,0);
        doc.setFontSize(17);doc.setFont(undefined,'bold');
        doc.text(`A-LEVEL TARGET MARKS  —  ${def.label}`,pw/2,14,{align:'center'});
        doc.setFontSize(10);doc.setFont(undefined,'normal');
        doc.text(AL.school.toUpperCase(),pw/2,21,{align:'center'});
        doc.setFontSize(9);
        doc.text(`${AL.class}     ${AL.subject}     ${AL.term} ${AL.year}`,pw/2,27,{align:'center'});
        doc.setDrawColor(0);doc.setLineWidth(0.6);
        doc.line(12,30,pw-12,30);

        // Meta fields
        doc.setLineWidth(0.3);doc.setFontSize(9);
        doc.text('Teacher:',12,38);   doc.line(30,39,140,39);
        doc.text('Date:',150,38);     doc.line(162,39,220,39);
        doc.text('Class:',228,38);    doc.line(240,39,285,39);

        doc.setFontSize(7.5);doc.setTextColor(80);
        doc.text(`Scale note:  ${def.note}`,12,45);
        doc.setTextColor(0);

        const panel=document.getElementById('panel-'+def.type);
        const bodyRows=[];let rn=1;
        panel.querySelectorAll('tbody tr').forEach(tr=>{
            bodyRows.push([rn++,tr.dataset.studentId||'',(tr.dataset.dispName||tr.dataset.name||'').toUpperCase(),tr.dataset.stream||'','']);
        });

        doc.autoTable({
            head:[['#','Student ID','Full Name','Stream','Target (%)']],
            body:bodyRows,startY:49,rowPageBreak:'avoid',
            styles:{fontSize:9,cellPadding:{top:3,right:3,bottom:3,left:3},textColor:[0,0,0],fillColor:false,lineColor:[0,0,0],lineWidth:0.15},
            headStyles:{fillColor:false,textColor:[0,0,0],fontStyle:'bold',fontSize:9,lineWidth:{bottom:0.5,top:0.5,left:0.15,right:0.15},lineColor:[0,0,0]},
            alternateRowStyles:{fillColor:false},
            columnStyles:{0:{halign:'center',cellWidth:COL[0]},1:{cellWidth:COL[1]},2:{cellWidth:COL[2]},3:{cellWidth:COL[3]},4:{halign:'center',cellWidth:COL[4]}},
            margin:{left:12,right:12},
            didDrawPage:(data)=>{
                doc.setFontSize(7);doc.setTextColor(120);
                doc.text('SchoolPilot — Printed '+new Date().toLocaleDateString(),12,ph-5);
                doc.text('Page '+data.pageNumber,pw-12,ph-5,{align:'right'});
                doc.setTextColor(0);
            }
        });

        const fy=doc.lastAutoTable.finalY||170;
        if(fy<ph-32){
            const sy=fy+16;
            doc.setDrawColor(0);doc.setLineWidth(0.3);
            doc.line(12,sy,100,sy);doc.line(130,sy,218,sy);
            doc.setFontSize(7.5);doc.setTextColor(60);
            doc.text('Subject Teacher (Signature & Date)',12,sy+5);
            doc.text('Head of Department (Signature & Date)',130,sy+5);
        }
    });

    doc.save(`alevel-template-${AL.class.replace(/\s+/g,'-')}-${AL.subject.replace(/\s+/g,'-')}-${ds()}.pdf`);
    notify('Template ready','PDF with MID & EOT sheets downloaded.','success');
};

// Init
refreshProgress('mid');
document.querySelectorAll('input.tgt-input').forEach(inp=>{
    if(inp.value.trim()!==''&&AL.actual)updateStatus(inp);
});

})();
</script>
