<?php
/*
 * enter_targets.php  –  Step 2: Enter student target marks (v2).
 * AOI 1/2/3 targets on 0–3 scale.  EOT target as percentage 0–100%.
 * Place in the same directory as sel_add_marks.php  (O_Level/).
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

if (!$class || !$term || !$year || empty($streams) || !$subject) {
    header('Location: sel_targets.php'); exit;
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
    if (!$ok) { header('Location: sel_targets.php'); exit; }
}

// Marks table name
$term_num   = (int)preg_replace('/\D/', '', $term);
$term_roman = ['i','ii','iii'][max(0, min(2, $term_num - 1))];
$marks_table = "{$year}_{$term_roman}_olevel";

// Exact table-existence check
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

// ── Is the subject compulsory? ────────────────────────────────
// Compulsory → all active students in class/stream
// Optional   → only students enrolled in student_subjects
$is_compulsory = true; // safe default: show everyone
$sc = mysqli_prepare($conn, "SELECT compulsory FROM subjects WHERE subj_name=? LIMIT 1");
if ($sc) {
    mysqli_stmt_execute($sc, [$subject]);
    $sc_row = mysqli_fetch_assoc(mysqli_stmt_get_result($sc));
    if ($sc_row !== null) $is_compulsory = (bool)$sc_row['compulsory'];
    mysqli_stmt_close($sc);
}

// ── Fetch students ────────────────────────────────────────────
$stream_ph = implode(',', array_fill(0, count($streams), '?'));

if ($is_compulsory) {
    // All active students in the selected class and streams
    $ss = mysqli_prepare($conn,
        "SELECT student_id, first_name, last_name, stream
         FROM students
         WHERE current_class=? AND stream IN ($stream_ph) AND status='active'
         ORDER BY stream ASC, last_name ASC, first_name ASC");
    $ss_params = array_merge([$class], $streams);
} else {
    // Only students enrolled in this optional subject (via student_subjects)
    $ss = mysqli_prepare($conn,
        "SELECT s.student_id, s.first_name, s.last_name, s.stream
         FROM students s
         INNER JOIN student_subjects ss
             ON ss.student_id = s.student_id
            AND ss.subject    = ?
            AND ss.class      = s.current_class
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

// ── Ensure targets table (v2 schema) ──────────────────────────
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

// ── Auto-migrate old schema ────────────────────────────────────
$chk = mysqli_query($conn, "SHOW COLUMNS FROM `student_target_marks` LIKE 'target_type'");
if (!$chk || mysqli_num_rows($chk) === 0) {
    @mysqli_query($conn, "ALTER TABLE `student_target_marks` ADD COLUMN IF NOT EXISTS `target_type` varchar(20) NOT NULL DEFAULT 'eot' AFTER `year`");
    $chk2 = mysqli_query($conn, "SHOW COLUMNS FROM `student_target_marks` LIKE 'target_percentage'");
    if ($chk2 && mysqli_num_rows($chk2) > 0) {
        @mysqli_query($conn, "ALTER TABLE `student_target_marks` CHANGE `target_percentage` `target_value` decimal(6,2) NOT NULL DEFAULT 0.00");
    }
    @mysqli_query($conn, "ALTER TABLE `student_target_marks` DROP KEY `uk_student_target`");
    @mysqli_query($conn, "ALTER TABLE `student_target_marks` ADD UNIQUE KEY IF NOT EXISTS `uk_student_target_v2` (`student_id`,`subject`,`term`,`year`,`target_type`)");
}

// ── Get ordered AOI topic_ids (from marks table → then report_topics fallback) ──
// AOI 1 = smallest non-EOT topic_id for this subject, AOI 2 = 2nd, AOI 3 = 3rd
$aoi_topic_ids = []; // ['aoi_1' => 'topic_id_string', ...]
if ($marks_exist) {
    $aq = mysqli_prepare($conn,
        "SELECT DISTINCT topic_id FROM `$marks_table`
         WHERE class=? AND subject=? AND UPPER(topic_id) != 'EOT'
         ORDER BY (topic_id + 0) ASC, topic_id ASC
         LIMIT 3");
    if ($aq) {
        mysqli_stmt_execute($aq, [$class, $subject]);
        $res = mysqli_stmt_get_result($aq);
        $raw_ids = [];
        while ($r = mysqli_fetch_assoc($res)) $raw_ids[] = $r['topic_id'];
        mysqli_stmt_close($aq);
        if (!empty($raw_ids)) {
            $keys = ['aoi_1','aoi_2','aoi_3'];
            foreach ($raw_ids as $i => $tid) {
                if (isset($keys[$i])) $aoi_topic_ids[$keys[$i]] = $tid;
            }
        }
    }
}
// Fallback: try report_topics
if (empty($aoi_topic_ids)) {
    $rq = mysqli_prepare($conn,
        "SELECT topic_id FROM report_topics
         WHERE class=? AND subject=? AND term=? AND year=? AND UPPER(topic_id) != 'EOT'
         ORDER BY (topic_id + 0) ASC, topic_id ASC
         LIMIT 3");
    if ($rq) {
        mysqli_stmt_execute($rq, [$class, $subject, $term, (string)$year]);
        $res = mysqli_stmt_get_result($rq);
        $raw_ids = [];
        while ($r = mysqli_fetch_assoc($res)) $raw_ids[] = $r['topic_id'];
        mysqli_stmt_close($rq);
        $keys = ['aoi_1','aoi_2','aoi_3'];
        foreach ($raw_ids as $i => $tid) {
            if (isset($keys[$i])) $aoi_topic_ids[$keys[$i]] = $tid;
        }
    }
}

// ── Fetch actual marks for all types ──────────────────────────
// actual_marks[student_id][type] = float|null
$actual_marks = [];
$show_actual  = false;

if ($marks_exist && !empty($students)) {
    $ids = array_column($students, 'student_id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $mq  = mysqli_prepare($conn,
        "SELECT student_id, topic_id, marks
         FROM `$marks_table`
         WHERE student_id IN ($ph) AND class=? AND subject=?");
    if ($mq) {
        mysqli_stmt_execute($mq, array_merge($ids, [$class, $subject]));
        $res = mysqli_stmt_get_result($mq);
        $raw = []; // [student_id][topic_id] = marks
        while ($r = mysqli_fetch_assoc($res)) {
            $raw[$r['student_id']][$r['topic_id']] = $r['marks'] !== null ? round((float)$r['marks'], 2) : null;
        }
        mysqli_stmt_close($mq);

        if (!empty($raw)) {
            $show_actual = true;
            foreach ($students as $stu) {
                $sid = $stu['student_id'];
                $sm  = $raw[$sid] ?? [];
                // Map topic_id → AOI 1/2/3
                $a = [];
                foreach (['aoi_1','aoi_2','aoi_3'] as $k) {
                    $tid  = $aoi_topic_ids[$k] ?? null;
                    $a[$k] = ($tid && array_key_exists($tid, $sm)) ? $sm[$tid] : null;
                }
                // EOT: stored as percentage in DB
                $eot_val = null;
                foreach ($sm as $tid => $val) {
                    if (strtoupper($tid) === 'EOT') { $eot_val = $val; break; }
                }
                $a['eot'] = $eot_val;
                $actual_marks[$sid] = $a;
            }
        }
    }
}

// ── Fetch existing targets (all types) ────────────────────────
$targets = []; // [student_id][target_type] = value
if (!empty($students)) {
    $ids = array_column($students, 'student_id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $tq  = mysqli_prepare($conn,
        "SELECT student_id, target_type, target_value
         FROM student_target_marks
         WHERE student_id IN ($ph) AND subject=? AND term=? AND year=?");
    if ($tq) {
        mysqli_stmt_execute($tq, array_merge($ids, [$subject, $term, (string)$year]));
        $res = mysqli_stmt_get_result($tq);
        while ($r = mysqli_fetch_assoc($res)) {
            $targets[$r['student_id']][$r['target_type']] = (float)$r['target_value'];
        }
        mysqli_stmt_close($tq);
    }
}

// ── Stats per type ────────────────────────────────────────────
$count_set = ['aoi_1' => 0, 'aoi_2' => 0, 'aoi_3' => 0, 'eot' => 0];
foreach ($students as $stu) {
    $sid = $stu['student_id'];
    foreach (array_keys($count_set) as $type) {
        if (isset($targets[$sid][$type])) $count_set[$type]++;
    }
}

// School name
$school_name = 'School';
$sp = mysqli_query($conn, "SELECT school_name FROM school_profile LIMIT 1");
if ($sp && $r = mysqli_fetch_assoc($sp)) $school_name = $r['school_name'];

$total_students = count($students);
$by_stream = [];
foreach ($students as $s) $by_stream[$s['stream']][] = $s;

// Helper: PHP status
function tgtStatus(mixed $actual, mixed $target, string $type): string {
    if ($actual === null || $target === null) return 'pending';
    $diff = (float)$actual - (float)$target;
    $close_thresh = ($type === 'eot') ? -5 : -0.3;
    if ($diff >= 0) return 'achieved';
    if ($diff >= $close_thresh) return 'close';
    return 'below';
}
function tgtBadge(string $status): string {
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

/* ── Page header ─────────────────────────────────── */
.tgt-ph{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--sp-radlg);padding:22px 28px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:18px;box-shadow:var(--sp-shlg)}
.tgt-ph h1{color:#fff;font-size:1.25rem;font-weight:700;margin-bottom:2px}
.tgt-ph p{color:rgba(255,255,255,.8);font-size:.83rem}
.tgt-ph-pills{display:flex;gap:8px;flex-wrap:wrap}
.tgt-ph-pill{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22);border-radius:20px;padding:5px 13px;font-size:.78rem;color:#fff;font-weight:600;display:flex;align-items:center;gap:5px}

/* ── Tab bar ─────────────────────────────────────── */
.tgt-tabs{display:flex;gap:0;background:#fff;border-radius:var(--sp-radlg);box-shadow:var(--sp-sh);overflow:hidden;margin-bottom:16px}
.tgt-tab{flex:1;padding:13px 8px;border:none;background:#fff;cursor:pointer;font-family:inherit;font-size:.85rem;font-weight:600;color:#666;transition:all var(--sp-tr);display:flex;flex-direction:column;align-items:center;gap:4px;border-right:1px solid #f0f4f1;position:relative}
.tgt-tab:last-child{border-right:none}
.tgt-tab:hover:not(.active){background:var(--g50);color:var(--g700)}
.tgt-tab.active{background:var(--g700);color:#fff}
.tgt-tab.active::after{content:'';position:absolute;bottom:-1px;left:50%;transform:translateX(-50%);width:0;height:0;border-left:8px solid transparent;border-right:8px solid transparent;border-top:8px solid var(--g700)}
.tgt-tab-label{font-size:.9rem;font-weight:700}
.tgt-tab-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:700;background:rgba(0,0,0,.08);color:inherit;white-space:nowrap}
.tgt-tab.active .tgt-tab-badge{background:rgba(255,255,255,.22);color:#fff}
.tgt-tab-badge.complete{background:rgba(0,200,80,.18);color:var(--g800)}
.tgt-tab.active .tgt-tab-badge.complete{background:rgba(255,255,255,.28);color:#fff}

/* ── Toolbar ─────────────────────────────────────── */
.tgt-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px;background:#fff;border-radius:var(--sp-radlg);padding:12px 18px;box-shadow:var(--sp-sh)}
.tgt-tl-left{display:flex;align-items:center;gap:10px;flex:1;flex-wrap:wrap}
.tgt-tl-right{display:flex;gap:8px;flex-wrap:wrap}
.tgt-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border:none;border-radius:var(--sp-rad);font-size:.83rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all var(--sp-tr);white-space:nowrap;text-decoration:none}
.tgt-btn-outline{background:#fff;color:var(--g700);border:1.5px solid var(--g400)}.tgt-btn-outline:hover{background:var(--g50)}
.tgt-btn-pdf{background:var(--sp-red);color:#fff}.tgt-btn-pdf:hover{background:#b71c1c}
.tgt-btn-excel{background:var(--g800);color:#fff}.tgt-btn-excel:hover{background:var(--g900)}
.tgt-btn-tpl{background:#5c6bc0;color:#fff}.tgt-btn-tpl:hover{background:#3949ab}
.tgt-btn-back{background:#fff;color:#555;border:1.5px solid #d0dbd1}.tgt-btn-back:hover{border-color:var(--g600);color:var(--g700)}
.tgt-btn:disabled{opacity:.5;cursor:not-allowed}

/* ── Progress ────────────────────────────────────── */
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
.tgt-search-cnt{font-size:.82rem;color:#777;font-weight:600;white-space:nowrap}
.tgt-search-cnt span{color:var(--g700)}

/* ── Cards & table ───────────────────────────────── */
.tgt-card{background:#fff;border-radius:var(--sp-radlg);box-shadow:var(--sp-sh);overflow:hidden;margin-bottom:14px}
.tgt-card-head{background:linear-gradient(90deg,var(--g700),var(--g600));padding:10px 16px;display:flex;align-items:center;justify-content:space-between}
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

/* ── Target input ────────────────────────────────── */
.tgt-inp-wrap{position:relative;display:inline-flex;align-items:center;min-width:90px}
input.tgt-input{
  width:84px;padding:7px 26px 7px 10px;
  border:1.5px solid #d0dbd1;border-radius:var(--sp-rad);
  font-size:.92rem;font-family:inherit;font-weight:700;color:#1a1a1a;
  text-align:center;transition:border-color var(--sp-tr),box-shadow var(--sp-tr);
  -moz-appearance:textfield;
}
input.tgt-input::-webkit-outer-spin-button,
input.tgt-input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
input.tgt-input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
input.tgt-input.tgt-saved{border-color:var(--g400);background:var(--g50)}
input.tgt-input.tgt-saving{border-color:var(--sp-orange);background:#fff8f0}
input.tgt-input.tgt-err{border-color:var(--sp-red);background:#fff5f5}
.tgt-unit{position:absolute;right:7px;font-size:.72rem;color:#bbb;pointer-events:none;font-weight:600}
.tgt-save-icon{margin-left:5px;font-size:.72rem;flex-shrink:0}
.tgt-save-icon.ok{color:var(--g600)}
.tgt-save-icon.spin{color:var(--sp-orange);animation:tSpin .6s linear infinite}
.tgt-save-icon.err{color:var(--sp-red)}
@keyframes tSpin{to{transform:rotate(360deg)}}

.td-actual{font-size:.88rem;color:#555;font-weight:500}
.td-diff{font-size:.85rem;font-weight:700}
.td-diff.pos{color:var(--g700)}.td-diff.neg{color:var(--sp-red)}
.td-dash{color:#ccc}

.tgt-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:700;white-space:nowrap}
.tgt-badge.achieved{background:#e8f5e9;color:var(--g800)}
.tgt-badge.close{background:#e3f2fd;color:var(--sp-blue)}
.tgt-badge.below{background:#ffebee;color:var(--sp-red)}
.tgt-badge.pending{background:#f5f5f5;color:#aaa}

/* ── Tab panels ──────────────────────────────────── */
.tgt-panel{display:none}.tgt-panel.active{display:block}
.tgt-empty-panel{text-align:center;padding:40px 24px;color:#aaa}
.tgt-empty-panel i{font-size:2rem;display:block;margin-bottom:8px;color:#ddd}
.tgt-no-match{text-align:center;padding:18px;color:#aaa;font-size:.86rem;display:none}
.tgt-no-match.visible{display:block}

/* ── Notifications ───────────────────────────────── */
#tgt-notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.tgt-notif{background:#fff;border-radius:var(--sp-rad);padding:13px 15px;box-shadow:var(--sp-shlg);display:flex;align-items:flex-start;gap:11px;border-left:4px solid var(--g600);animation:tNIn .3s ease}
.tgt-notif.error{border-left-color:var(--sp-red)}.tgt-notif.warning{border-left-color:var(--sp-orange)}.tgt-notif.info{border-left-color:var(--sp-blue)}
@keyframes tNIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.tgt-ni{font-size:1rem;margin-top:1px;flex-shrink:0}
.tgt-notif.success .tgt-ni{color:var(--g700)}.tgt-notif.error .tgt-ni{color:var(--sp-red)}.tgt-notif.warning .tgt-ni{color:var(--sp-orange)}.tgt-notif.info .tgt-ni{color:var(--sp-blue)}
.tgt-nb{flex:1}.tgt-nt{font-weight:700;font-size:.84rem;margin-bottom:2px}.tgt-nm{font-size:.78rem;color:#666}
.tgt-nc{background:none;border:none;cursor:pointer;color:#aaa;font-size:.95rem;padding:0;line-height:1;flex-shrink:0}
</style>

<div class="main-content">
<div class="tgt-page">

<!-- ── Page header ───────────────────────────────── -->
<div class="tgt-ph">
  <div>
    <h1><i class="fas fa-bullseye" style="margin-right:8px"></i>Target Marks — <?= htmlspecialchars($class) ?></h1>
    <p>Set targets per assessment — AOI 1 / 2 / 3 (scale 0–3) and EOT (%)</p>
  </div>
  <div class="tgt-ph-pills">
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

<!-- ── Tabs ───────────────────────────────────────── -->
<div class="tgt-tabs" id="tgt-tabs">
  <?php
  $tab_defs = [
      'aoi_1' => 'AOI 1',
      'aoi_2' => 'AOI 2',
      'aoi_3' => 'AOI 3',
      'eot'   => 'EOT',
  ];
  $first = true;
  foreach ($tab_defs as $type => $label):
      $set   = $count_set[$type];
      $all   = $set === $total_students && $total_students > 0;
      $unit  = ($type === 'eot') ? '%' : '/3';
  ?>
  <button class="tgt-tab <?= $first ? 'active' : '' ?>"
          data-type="<?= $type ?>" onclick="tgtSwitchTab('<?= $type ?>')">
    <span class="tgt-tab-label"><?= $label ?></span>
    <span class="tgt-tab-badge <?= $all ? 'complete' : '' ?>" id="tab-badge-<?= $type ?>">
      <?= $all ? '<i class="fas fa-check" style="font-size:.6rem"></i>' : '' ?>
      <?= $set ?>/<?= $total_students ?>
    </span>
  </button>
  <?php $first = false; endforeach; ?>
</div>

<!-- ── Toolbar ────────────────────────────────────── -->
<div class="tgt-toolbar">
  <div class="tgt-tl-left">
    <a href="sel_targets.php" class="tgt-btn tgt-btn-back"><i class="fas fa-arrow-left"></i> Back</a>
    <div class="tgt-prog">
      <div class="tgt-prog-lbl">
        <span id="tgt-prog-type-label">AOI 1 targets</span>
        <span id="tgt-prog-lbl"><?= $count_set['aoi_1'] ?> / <?= $total_students ?></span>
      </div>
      <div class="tgt-prog-track"><div class="tgt-prog-fill" id="tgt-prog-fill"
           style="width:<?= $total_students > 0 ? round($count_set['aoi_1']/$total_students*100) : 0 ?>%"></div></div>
    </div>
  </div>
  <div class="tgt-tl-right">
    <button class="tgt-btn tgt-btn-outline" id="save-all-btn" onclick="tgtSaveAll()">
      <i class="fas fa-floppy-disk"></i> Save All
    </button>
    <button class="tgt-btn tgt-btn-tpl" onclick="tgtPrintTemplate()">
      <i class="fas fa-print"></i> Template
    </button>
    <button class="tgt-btn tgt-btn-excel" onclick="tgtExportExcel()">
      <i class="fas fa-file-excel"></i> Excel
    </button>
    <button class="tgt-btn tgt-btn-pdf" onclick="tgtExportPDF()">
      <i class="fas fa-file-pdf"></i> PDF
    </button>
  </div>
</div>

<!-- ── Search ─────────────────────────────────────── -->
<?php if ($total_students > 0): ?>
<div class="tgt-search-row">
  <div class="tgt-search-box">
    <i class="fas fa-search tgt-si-icon"></i>
    <input type="text" id="tgt-search" placeholder="Search by name or student ID…" autocomplete="off">
    <button class="tgt-si-clear" id="tgt-si-clear" onclick="tgtClearSearch()" title="Clear">
      <i class="fas fa-times"></i>
    </button>
  </div>
  <span class="tgt-search-cnt">Showing <span id="tgt-vis-count"><?= $total_students ?></span> of <?= $total_students ?></span>
</div>
<div class="tgt-no-match" id="tgt-no-match">
  <i class="fas fa-search" style="font-size:1.3rem;color:#ddd;display:block;margin-bottom:6px"></i>
  No students match. <a href="#" onclick="tgtClearSearch();return false" style="color:var(--g700)">Clear search</a>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════ -->
<!--  TAB PANELS                                     -->
<!-- ════════════════════════════════════════════════ -->
<?php
$panel_meta = [
    'aoi_1' => ['label' => 'AOI 1',    'unit' => '/3',  'unit_label' => '/3',  'max' => 3,   'step' => 0.1],
    'aoi_2' => ['label' => 'AOI 2',    'unit' => '/3',  'unit_label' => '/3',  'max' => 3,   'step' => 0.1],
    'aoi_3' => ['label' => 'AOI 3',    'unit' => '/3',  'unit_label' => '/3',  'max' => 3,   'step' => 0.1],
    'eot'   => ['label' => 'EOT',       'unit' => '%',   'unit_label' => '%',   'max' => 100, 'step' => 0.5],
];

foreach ($panel_meta as $ptype => $pmeta):
    $is_eot  = ($ptype === 'eot');
    $active  = ($ptype === 'aoi_1') ? 'active' : '';
    $p_label = $pmeta['label'];
    $p_unit  = $pmeta['unit_label'];
    $p_max   = $pmeta['max'];
    $p_step  = $pmeta['step'];
    $p_ph    = $is_eot ? 'e.g. 80' : 'e.g. 2.5';
?>
<div class="tgt-panel <?= $active ?>" id="panel-<?= str_replace('_', '-', $ptype) ?>" data-type="<?= $ptype ?>">

<?php if (empty($students)): ?>
  <div class="tgt-card"><div class="tgt-empty-panel">
    <i class="fas fa-user-slash"></i>
    <p>No active students found for the selected class and streams.</p>
  </div></div>
<?php else:
  $gRow = 0;
  foreach ($by_stream as $stream_name => $stream_students):
?>
  <div class="tgt-card">
    <div class="tgt-card-head">
      <h3><i class="fas fa-layer-group"></i><?= htmlspecialchars($class) ?>
          — <span class="tgt-stream-badge"><?= htmlspecialchars($stream_name) ?></span></h3>
      <span style="color:rgba(255,255,255,.75);font-size:.77rem">
        <?= count($stream_students) ?> students
      </span>
    </div>
    <div class="tgt-tbl-wrap">
      <table class="tgt-table">
        <thead>
          <tr>
            <th class="tc">#</th>
            <th>Student ID</th>
            <th>Full Name</th>
            <th class="tc" style="min-width:115px">
              Target <small style="font-weight:400"><?= $p_unit ?></small>
            </th>
            <?php if ($show_actual): ?>
            <th class="tc">Actual <small style="font-weight:400"><?= $p_unit ?></small></th>
            <th class="tc">Diff</th>
            <th class="tc">Status</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($stream_students as $stu):
            $gRow++;
            $sid     = $stu['student_id'];
            $tgt_val = $targets[$sid][$ptype] ?? null;
            $act_val = $actual_marks[$sid][$ptype] ?? null;
            $status  = tgtStatus($act_val, $tgt_val, $ptype);
            $diff    = ($tgt_val !== null && $act_val !== null) ? round($act_val - $tgt_val, 2) : null;
        ?>
          <tr data-student-id="<?= htmlspecialchars($sid) ?>"
              data-stream="<?= htmlspecialchars($stream_name) ?>"
              data-name="<?= htmlspecialchars(strtolower($stu['last_name'].' '.$stu['first_name'])) ?>"
              data-disp-name="<?= htmlspecialchars($stu['last_name'].' '.$stu['first_name']) ?>"
              <?php if ($show_actual): ?>
              data-actual="<?= $act_val !== null ? $act_val : '' ?>"
              <?php endif; ?>>
            <td class="td-no tc"><?= $gRow ?></td>
            <td class="td-id"><?= htmlspecialchars($sid) ?></td>
            <td class="td-name"><?= htmlspecialchars($stu['last_name'].' '.$stu['first_name']) ?></td>
            <td class="tc">
              <div class="tgt-inp-wrap">
                <input type="number"
                       class="tgt-input <?= $tgt_val !== null ? 'tgt-saved' : '' ?>"
                       data-student-id="<?= htmlspecialchars($sid) ?>"
                       data-stream="<?= htmlspecialchars($stream_name) ?>"
                       data-target-type="<?= $ptype ?>"
                       data-max="<?= $p_max ?>"
                       value="<?= $tgt_val !== null ? $tgt_val : '' ?>"
                       min="0" max="<?= $p_max ?>" step="<?= $p_step ?>"
                       placeholder="<?= $p_ph ?>">
                <span class="tgt-unit"><?= $p_unit ?></span>
                <i class="tgt-save-icon <?= $tgt_val !== null ? 'fas fa-check ok' : '' ?>"
                   id="ti-<?= htmlspecialchars($sid) ?>-<?= $ptype ?>"></i>
              </div>
            </td>
            <?php if ($show_actual): ?>
            <td class="tc td-actual">
              <?= $act_val !== null ? $act_val : '<span class="td-dash">—</span>' ?>
            </td>
            <td class="tc td-diff <?= $diff !== null ? ($diff >= 0 ? 'pos' : 'neg') : '' ?>"
                id="diff-<?= htmlspecialchars($sid) ?>-<?= $ptype ?>">
              <?php if ($diff !== null): ?>
                <?= ($diff >= 0 ? '+' : '') . $diff ?>
              <?php else: ?>
                <span class="td-dash">—</span>
              <?php endif; ?>
            </td>
            <td class="tc" id="stat-<?= htmlspecialchars($sid) ?>-<?= $ptype ?>">
              <?= tgtBadge($status) ?>
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
const TGT = {
    csrf:    '<?= htmlspecialchars($csrf) ?>',
    class:   '<?= addslashes($class) ?>',
    subject: '<?= addslashes($subject) ?>',
    term:    '<?= addslashes($term) ?>',
    year:    <?= $year ?>,
    total:   <?= $total_students ?>,
    actual:  <?= $show_actual ? 'true' : 'false' ?>,
    school:  '<?= addslashes($school_name) ?>',
    streams: <?= json_encode(array_values($streams)) ?>,
    counts:  <?= json_encode($count_set) ?>,
};
let currentTab = 'aoi_1';

(function(){
'use strict';

// ── Helpers ──────────────────────────────────────────────────
function escH(v){return String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function ds(){return new Date().toISOString().split('T')[0];}
function notify(title,msg,type,dur){
    type=type||'success';dur=dur||4000;
    const ic={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
    const n=document.createElement('div');
    n.className='tgt-notif '+type;
    n.innerHTML=`<i class="fas ${ic[type]} tgt-ni"></i>
        <div class="tgt-nb"><div class="tgt-nt">${escH(title)}</div><div class="tgt-nm">${escH(msg)}</div></div>
        <button class="tgt-nc" onclick="this.closest('.tgt-notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('tgt-notif-stack').prepend(n);
    setTimeout(()=>{n.style.opacity='0';n.style.transform='translateX(30px)';n.style.transition='.3s';setTimeout(()=>n.remove(),300);},dur);
}
function panelId(type){return 'panel-'+type.replace(/_/g,'-');}
function iconId(sid,type){return 'ti-'+sid+'-'+type;}
function diffId(sid,type){return 'diff-'+sid+'-'+type;}
function statId(sid,type){return 'stat-'+sid+'-'+type;}
function isEOT(type){return type==='eot';}
function closeThresh(type){return isEOT(type)?-5:-0.3;}
function badgeHTML(diff,hasTarget,hasActual){
    if(!hasTarget||!hasActual)return'<span class="tgt-badge pending"><i class="fas fa-clock"></i>Pending</span>';
    if(diff>=0)return'<span class="tgt-badge achieved"><i class="fas fa-check"></i>Achieved</span>';
    // close threshold determined by type (passed separately)
    return null; // caller handles close vs below
}

// ── Tab switching ─────────────────────────────────────────────
window.tgtSwitchTab = function(type){
    currentTab = type;
    // Panels
    document.querySelectorAll('.tgt-panel').forEach(p=>p.classList.remove('active'));
    document.getElementById(panelId(type)).classList.add('active');
    // Tabs
    document.querySelectorAll('.tgt-tab').forEach(t=>{
        t.classList.toggle('active', t.dataset.type===type);
    });
    // Update progress bar + label
    refreshProgress(type);
    refreshStatusStats(type);
    // Re-apply search filter to new panel
    runSearch();
};

// ── Progress ─────────────────────────────────────────────────
function refreshProgress(type){
    type = type || currentTab;
    const panel = document.getElementById(panelId(type));
    const total = panel.querySelectorAll('input.tgt-input').length;
    const set   = panel.querySelectorAll('input.tgt-input.tgt-saved').length;
    const pct   = total>0 ? Math.round(set/total*100) : 0;
    const labels = {aoi_1:'AOI 1',aoi_2:'AOI 2',aoi_3:'AOI 3',eot:'EOT'};
    document.getElementById('tgt-prog-type-label').textContent = (labels[type]||type)+' targets';
    document.getElementById('tgt-prog-lbl').textContent = set+' / '+total;
    document.getElementById('tgt-prog-fill').style.width = pct+'%';
    // Update tab badge
    const badge = document.getElementById('tab-badge-'+type);
    if(badge){
        const allDone = set===total&&total>0;
        badge.className='tgt-tab-badge'+(allDone?' complete':'');
        badge.innerHTML=(allDone?'<i class="fas fa-check" style="font-size:.6rem"></i>':'')+set+'/'+total;
    }
}

function refreshStatusStats(type){
    if(!TGT.actual)return;
    type = type || currentTab;
    const panel = document.getElementById(panelId(type));
    // count achieved/below from visible badges
    const ach  = panel.querySelectorAll('.tgt-badge.achieved:not(.row-hidden .tgt-badge)').length;
    const bel  = panel.querySelectorAll('.tgt-badge.below:not(.row-hidden .tgt-badge)').length;
    // (simple display not shown in stats bar by type — could extend if needed)
}

// ── Status update on input change ────────────────────────────
function updateStatus(input){
    const type   = input.dataset.targetType;
    const sid    = input.dataset.studentId;
    const val    = parseFloat(input.value);
    const tr     = input.closest('tr');
    const actual = tr ? parseFloat(tr.dataset.actual||'') : NaN;
    const diffEl = document.getElementById(diffId(sid,type));
    const statEl = document.getElementById(statId(sid,type));
    if(!diffEl||!statEl)return;
    if(isNaN(val)||isNaN(actual)){
        diffEl.innerHTML='<span class="td-dash">—</span>';
        diffEl.className='tc td-diff';
        statEl.innerHTML='<span class="tgt-badge pending"><i class="fas fa-clock"></i>Pending</span>';
        return;
    }
    const diff = Math.round((actual-val)*100)/100;
    const pos  = diff>=0;
    const close= diff>=closeThresh(type);
    diffEl.innerHTML = (diff>=0?'+':'')+diff;
    diffEl.className = 'tc td-diff '+(pos?'pos':'neg');
    if(pos)       statEl.innerHTML='<span class="tgt-badge achieved"><i class="fas fa-check"></i>Achieved</span>';
    else if(close)statEl.innerHTML='<span class="tgt-badge close"><i class="fas fa-equals"></i>Close</span>';
    else          statEl.innerHTML='<span class="tgt-badge below"><i class="fas fa-arrow-down"></i>Below</span>';
}

// ── AJAX save ────────────────────────────────────────────────
const timers={};
async function saveSingle(input){
    const type   = input.dataset.targetType;
    const sid    = input.dataset.studentId;
    const stream = input.dataset.stream;
    const iconEl = document.getElementById(iconId(sid,type));
    const val    = input.value.trim();
    const maxVal = parseFloat(input.dataset.max||'100');
    input.classList.remove('tgt-saved','tgt-saving','tgt-err');
    if(iconEl)iconEl.className='tgt-save-icon';

    if(val!==''){
        const num=parseFloat(val);
        if(isNaN(num)||num<0||num>maxVal){
            input.classList.add('tgt-err');
            if(iconEl)iconEl.className='tgt-save-icon fas fa-xmark err';
            notify('Invalid value',`Must be 0–${maxVal}.`,'warning');
            return;
        }
    }
    input.classList.add('tgt-saving');
    if(iconEl)iconEl.className='tgt-save-icon fas fa-spinner spin';
    try{
        const fd=new FormData();
        fd.append('csrf_token',  TGT.csrf);
        fd.append('student_id',  sid);
        fd.append('class',       TGT.class);
        fd.append('stream',      stream);
        fd.append('subject',     TGT.subject);
        fd.append('term',        TGT.term);
        fd.append('year',        TGT.year);
        fd.append('target_type', type);
        fd.append('target_value',val);
        const r=await fetch('ajax_save_target.php',{method:'POST',body:fd});
        const d=await r.json();
        input.classList.remove('tgt-saving');
        if(d.success){
            if(val!==''){input.classList.add('tgt-saved');if(iconEl)iconEl.className='tgt-save-icon fas fa-check ok';}
            else{if(iconEl)iconEl.className='';}
            if(TGT.actual)updateStatus(input);
        }else{
            input.classList.add('tgt-err');
            if(iconEl)iconEl.className='tgt-save-icon fas fa-xmark err';
            notify('Save failed',d.message||'Could not save target.','error');
        }
    }catch{
        input.classList.remove('tgt-saving');
        input.classList.add('tgt-err');
        if(iconEl)iconEl.className='tgt-save-icon fas fa-xmark err';
        notify('Network error','Check your connection.','error');
    }
    refreshProgress(type);
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
        if(e.key!=='Enter')return;
        e.preventDefault();
        const type=inp.dataset.targetType;
        const all=[...document.querySelectorAll(`#${panelId(type)} input.tgt-input:not([style*="display:none"])`)]
                  .filter(el=>!el.closest('tr')?.classList.contains('row-hidden'));
        const i=all.indexOf(inp);
        if(i>=0&&i<all.length-1)all[i+1].focus();
    });
});

// ── Save all (active tab) ─────────────────────────────────────
window.tgtSaveAll=async function(){
    const type = currentTab;
    const panel= document.getElementById(panelId(type));
    const btn  = document.getElementById('save-all-btn');
    btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
    let ok=0,fail=0;
    for(const inp of panel.querySelectorAll('input.tgt-input')){
        const key=inp.dataset.studentId+'-'+inp.dataset.targetType;
        if(timers[key]){clearTimeout(timers[key]);delete timers[key];}
        const val=inp.value.trim();
        if(!val)continue;
        const num=parseFloat(val);
        if(isNaN(num)){fail++;continue;}
        try{
            const fd=new FormData();
            fd.append('csrf_token',  TGT.csrf);fd.append('student_id',inp.dataset.studentId);
            fd.append('class',       TGT.class);fd.append('stream',inp.dataset.stream);
            fd.append('subject',     TGT.subject);fd.append('term',TGT.term);fd.append('year',TGT.year);
            fd.append('target_type', type);fd.append('target_value',num);
            const r=await fetch('ajax_save_target.php',{method:'POST',body:fd});
            const d=await r.json();
            if(d.success){inp.classList.remove('tgt-err');inp.classList.add('tgt-saved');ok++;}
            else fail++;
        }catch{fail++;}
    }
    btn.disabled=false;btn.innerHTML='<i class="fas fa-floppy-disk"></i> Save All';
    refreshProgress(type);
    fail===0?notify('Saved',ok+' target'+(ok!==1?'s':'')+' saved.','success')
            :notify('Partial',ok+' saved, '+fail+' failed.','warning');
};

// ── Search ───────────────────────────────────────────────────
const searchEl =document.getElementById('tgt-search');
const clearBtn =document.getElementById('tgt-si-clear');
const visCount =document.getElementById('tgt-vis-count');
const noMatch  =document.getElementById('tgt-no-match');

function runSearch(){
    if(!searchEl)return;
    const q=searchEl.value.toLowerCase().trim();
    if(clearBtn)clearBtn.style.display=q?'':'none';
    // Filter ALL panels simultaneously
    document.querySelectorAll('.tgt-panel').forEach(panel=>{
        panel.querySelectorAll('tbody tr').forEach(tr=>{
            const name=tr.dataset.name||'';
            const id  =(tr.dataset.studentId||'').toLowerCase();
            tr.classList.toggle('row-hidden',!(!q||name.includes(q)||id.includes(q)));
        });
        // Hide/show cards within this panel
        panel.querySelectorAll('.tgt-card').forEach(card=>{
            card.style.display=card.querySelectorAll('tbody tr:not(.row-hidden)').length>0?'':'none';
        });
    });
    // Count visible in active tab
    const activePanel=document.getElementById(panelId(currentTab));
    const vis=activePanel.querySelectorAll('tbody tr:not(.row-hidden)').length;
    if(visCount)visCount.textContent=vis;
    if(noMatch)noMatch.classList.toggle('visible',vis===0&&q!=='');
}

window.tgtClearSearch=function(){
    if(searchEl){searchEl.value='';searchEl.focus();}
    runSearch();
};
if(searchEl){
    searchEl.addEventListener('input',runSearch);
    searchEl.addEventListener('keydown',e=>{if(e.key==='Escape')tgtClearSearch();});
}

// ── Export helpers ───────────────────────────────────────────
function getActiveRows(){
    const type  = currentTab;
    const panel = document.getElementById(panelId(type));
    const rows  = [];
    let i=1;
    panel.querySelectorAll('tbody tr:not(.row-hidden)').forEach(tr=>{
        const sid   = tr.dataset.studentId||'';
        const name  = tr.dataset.dispName||tr.dataset.name||'';
        const stream= tr.dataset.stream||'';
        const inp   = tr.querySelector('input.tgt-input');
        const tgt   = inp?inp.value.trim():'';
        const unit  = isEOT(type)?'%':'/3';
        const obj   = {'#':i++,'Student ID':sid,'Full Name':name,'Stream':stream,[`Target (${unit})`]:tgt};
        if(TGT.actual){
            const tds=tr.querySelectorAll('.td-actual');
            obj[`Actual (${unit})`]=tds[0]?tds[0].textContent.trim().replace('—',''):'';
            const dEl=document.getElementById(diffId(sid,type));
            obj['Diff']=dEl?dEl.textContent.trim().replace('—',''):'';
            const sEl=document.getElementById(statId(sid,type));
            obj['Status']=sEl?sEl.textContent.trim():'';
        }
        rows.push(obj);
    });
    return rows;
}

// ── Excel export ─────────────────────────────────────────────
window.tgtExportExcel=function(){
    if(!window.XLSX){notify('Please wait','Library loading…','info');return;}
    const rows=getActiveRows();
    if(!rows.length){notify('Empty','No visible data.','warning');return;}
    const type=currentTab;
    const labels={aoi_1:'AOI 1',aoi_2:'AOI 2',aoi_3:'AOI 3',eot:'EOT'};
    const title =`${TGT.school} — ${TGT.class} — ${TGT.subject} — ${labels[type]} Targets — ${TGT.term} ${TGT.year}`;
    const wb=window.XLSX.utils.book_new();
    const ws=window.XLSX.utils.json_to_sheet([]);
    window.XLSX.utils.sheet_add_aoa(ws,[[title]],{origin:'A1'});
    window.XLSX.utils.sheet_add_json(ws,rows,{origin:'A3'});
    ws['!cols']=[{wch:4},{wch:22},{wch:30},{wch:12},{wch:14},{wch:14},{wch:8},{wch:12}];
    window.XLSX.utils.book_append_sheet(wb,ws,labels[type]+' Targets');
    window.XLSX.writeFile(wb,`targets-${TGT.class.replace(/\s+/g,'-')}-${type}-${ds()}.xlsx`);
    notify('Exported','Excel downloaded.','success');
};

// ── PDF export (current tab) ─────────────────────────────────
window.tgtExportPDF=function(){
    if(!window.jspdf){notify('Please wait','Library loading…','info');return;}
    const rows=getActiveRows();
    if(!rows.length){notify('Empty','No visible data.','warning');return;}
    const type=currentTab;
    const labels={aoi_1:'AOI 1',aoi_2:'AOI 2',aoi_3:'AOI 3',eot:'EOT'};
    const {jsPDF}=window.jspdf;
    const doc=new jsPDF({orientation:'portrait',unit:'mm'});
    const pw=doc.internal.pageSize.width;
    doc.setFontSize(13);doc.setTextColor(27,94,32);doc.text(TGT.school,pw/2,14,{align:'center'});
    doc.setFontSize(9);doc.setTextColor(80);
    doc.text(`${TGT.class} | ${TGT.subject} | ${labels[type]} Targets | ${TGT.term} ${TGT.year}`,pw/2,21,{align:'center'});
    const keys=Object.keys(rows[0]);
    doc.autoTable({
        head:[keys],body:rows.map(r=>keys.map(k=>r[k]??'')),startY:26,
        styles:{fontSize:8.5,cellPadding:2.5},
        headStyles:{fillColor:[56,142,60],textColor:255,fontStyle:'bold'},
        alternateRowStyles:{fillColor:[241,248,241]},
        margin:{left:10,right:10},
        didDrawPage:()=>{doc.setFontSize(7);doc.setTextColor(180);
            doc.text('SchoolPilot — '+new Date().toLocaleString(),10,doc.internal.pageSize.height-5);}
    });
    doc.save(`targets-${TGT.class.replace(/\s+/g,'-')}-${type}-${ds()}.pdf`);
    notify('Exported','PDF downloaded.','success');
};

// ── Print Template (PDF — all 4 types, one per section) ──────
window.tgtPrintTemplate=function(){
    if(!window.jspdf){notify('Please wait','Library loading…','info');return;}
    const {jsPDF}=window.jspdf;
    // Landscape A4: 297 × 210 mm — gives full width for Student ID without wrapping
    const doc=new jsPDF({orientation:'landscape',unit:'mm',format:'a4'});
    const pw=doc.internal.pageSize.width;   // 297
    const ph=doc.internal.pageSize.height;  // 210

    const typeDefs=[
        {type:'aoi_1',label:'AOI 1',unit:'/3', scaleNote:'Enter mark out of 3.0  (e.g.  0.9   1.5   2.0   2.5   3.0)'},
        {type:'aoi_2',label:'AOI 2',unit:'/3', scaleNote:'Enter mark out of 3.0  (e.g.  0.9   1.5   2.0   2.5   3.0)'},
        {type:'aoi_3',label:'AOI 3',unit:'/3', scaleNote:'Enter mark out of 3.0  (e.g.  0.9   1.5   2.0   2.5   3.0)'},
        {type:'eot',  label:'EOT',  unit:'%',  scaleNote:'Enter target percentage  (e.g.  50   60   70   80   90   100)'},
    ];

    // Column widths (landscape 297mm − 24mm margins = 273mm available)
    // #(10) + StudentID(48) + Name(130) + Stream(30) + Target(40) = 258  ← fits cleanly
    const COL=[10,48,130,30,40];

    typeDefs.forEach((def,idx)=>{
        if(idx>0) doc.addPage();

        // ── Plain black header (no colour fill) ─────────────────
        doc.setTextColor(0,0,0);
        doc.setFontSize(18);doc.setFont(undefined,'bold');
        doc.text(`TARGET MARKS  —  ${def.label}`,pw/2,14,{align:'center'});

        doc.setFontSize(10);doc.setFont(undefined,'normal');
        doc.text(TGT.school.toUpperCase(),pw/2,21,{align:'center'});

        doc.setFontSize(9);
        doc.text(`${TGT.class}     ${TGT.subject}     ${TGT.term} ${TGT.year}`,pw/2,27,{align:'center'});

        // Solid divider line under header
        doc.setDrawColor(0,0,0);doc.setLineWidth(0.6);
        doc.line(12,30,pw-12,30);

        // ── Meta fields ──────────────────────────────────────────
        doc.setLineWidth(0.3);doc.setFontSize(9);
        const metaY=37;
        doc.text('Teacher:',12,metaY);
        doc.line(30,metaY+1,140,metaY+1);          // teacher name line

        doc.text('Date:',150,metaY);
        doc.line(162,metaY+1,220,metaY+1);          // date line

        doc.text('Class:',228,metaY);
        doc.line(240,metaY+1,285,metaY+1);          // class confirmation line

        // ── Scale note ───────────────────────────────────────────
        doc.setFontSize(7.5);doc.setTextColor(60,60,60);
        doc.text(`Scale note:  ${def.scaleNote}`,12,44);
        doc.setTextColor(0,0,0);

        // ── Gather student rows ──────────────────────────────────
        const panel=document.getElementById(panelId(def.type));
        const bodyRows=[];let rn=1;
        panel.querySelectorAll('tbody tr').forEach(tr=>{
            bodyRows.push([
                rn++,
                tr.dataset.studentId||'',
                (tr.dataset.dispName||tr.dataset.name||'').toUpperCase(),
                tr.dataset.stream||'',
                '',   // blank — teacher writes here
            ]);
        });

        // ── Table: pure black & white, no fills ──────────────────
        doc.autoTable({
            head:[['#','Student ID','Full Name','Stream',`Target  (${def.unit})`]],
            body:bodyRows,
            startY:48,
            rowPageBreak:'avoid',
            styles:{
                fontSize:9,
                cellPadding:{top:3,right:3,bottom:3,left:3},
                textColor:[0,0,0],
                fillColor:false,          // no background colour on any cell
                lineColor:[0,0,0],
                lineWidth:0.15,
            },
            headStyles:{
                fillColor:false,          // white header background
                textColor:[0,0,0],
                fontStyle:'bold',
                fontSize:9,
                lineWidth:{bottom:0.5,top:0.5,left:0.15,right:0.15},
                lineColor:[0,0,0],
            },
            alternateRowStyles:{fillColor:false},   // no zebra stripes
            columnStyles:{
                0:{halign:'center', cellWidth:COL[0]},
                1:{cellWidth:COL[1]},               // Student ID — wide enough, no wrap
                2:{cellWidth:COL[2]},               // Full Name
                3:{cellWidth:COL[3]},               // Stream
                4:{halign:'center', cellWidth:COL[4]},  // Target (blank)
            },
            margin:{left:12,right:12},
            didDrawPage:(data)=>{
                // Page footer
                doc.setFontSize(7);doc.setTextColor(120,120,120);
                doc.text('SchoolPilot — Printed '+new Date().toLocaleDateString(),12,ph-5);
                doc.text(
                    'Page '+data.pageNumber,
                    pw-12,ph-5,{align:'right'}
                );
                doc.setTextColor(0,0,0);
            }
        });

        // ── Sign-off lines if room below the table ───────────────
        const fy=doc.lastAutoTable.finalY||170;
        if(fy < ph-32){
            const sigY=fy+16;
            doc.setDrawColor(0,0,0);doc.setLineWidth(0.3);
            doc.line(12,sigY,100,sigY);
            doc.line(130,sigY,218,sigY);
            doc.setFontSize(7.5);doc.setTextColor(60,60,60);
            doc.text('Subject Teacher (Signature & Date)',12,sigY+5);
            doc.text('Head of Department (Signature & Date)',130,sigY+5);
        }
    });

    doc.save(`target-template-${TGT.class.replace(/\s+/g,'-')}-${TGT.subject.replace(/\s+/g,'-')}-${ds()}.pdf`);
    notify('Template ready','PDF with all 4 assessment sheets downloaded.','success');
};

// ── Init ─────────────────────────────────────────────────────
document.querySelectorAll('input.tgt-input').forEach(inp=>{
    if(inp.value.trim()!==''&&TGT.actual)updateStatus(inp);
});
refreshProgress('aoi_1');

})();
</script>
