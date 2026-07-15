<?php
/*
 * view_marksheet.php — A-Level Marksheet (NCDC New Curriculum)
 * Grading: A ≥ 80 · B ≥ 70 · C ≥ 60 · D ≥ 40 · E < 40
 */

require_once '../auth.php';
require_once '../conn.php';

// ── Input parameters ─────────────────────────────────────────────────────────
$class    = trim($_POST['class']    ?? '');
$term     = trim($_POST['term']     ?? '');
$year     = trim($_POST['year']     ?? '');
$exam_type    = trim($_POST['exam_type']  ?? '');
$exam_name_in = trim($_POST['exam_name']  ?? '');
$subject_filter = trim($_POST['subject_filter'] ?? 'all'); // 'all' | 'single'
$subject_name   = trim($_POST['subject_name']   ?? '');    // only used when filter='single'

// Streams: accept both comma-separated and array
$streams = [];
if (!empty($_POST['streams_csv'])) {
    $streams = array_filter(array_map('trim', explode(',', $_POST['streams_csv'])));
} elseif (!empty($_POST['streams'])) {
    $raw = $_POST['streams'];
    $streams = is_array($raw)
        ? array_filter(array_map('trim', $raw))
        : array_filter(array_map('trim', explode(',', $raw)));
}

// Guard: redirect back if required fields are missing
if (!$class || !$term || !$year || !$exam_type || empty($streams)) {
    header('Location: sel_gen_markshet.php');
    exit;
}

// ── Term → roman numeral ──────────────────────────────────────────────────────
$term_num = (int) filter_var($term, FILTER_SANITIZE_NUMBER_INT);
$romans   = [1 => 'i', 2 => 'ii', 3 => 'iii'];
$term_roman = $romans[$term_num] ?? 'i';
$table_name = $year . '_' . $term_roman . '_alevel';

// ── Table existence guard ────────────────────────────────────────────────────
$tbl_check = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table_name) . "'");
if (!$tbl_check || mysqli_num_rows($tbl_check) === 0) {
    echo '<div style="padding:3rem;text-align:center;font-family:sans-serif">
            <h2 style="color:#e53935">No data found</h2>
            <p>The marks table <code>' . htmlspecialchars($table_name) . '</code> does not exist.</p>
            <a href="sel_gen_markshet.php" style="color:#1e8449">&larr; Go back</a>
          </div>';
    exit;
}

// ── Exam set details ──────────────────────────────────────────────────────────
$exam_details = [];
$eq = mysqli_prepare($conn, "SELECT * FROM exam_sets WHERE id = ? LIMIT 1");
if ($eq) {
    mysqli_stmt_bind_param($eq, 's', $exam_type);
    mysqli_stmt_execute($eq);
    $er = mysqli_stmt_get_result($eq);
    if ($er) $exam_details = mysqli_fetch_assoc($er) ?? [];
    mysqli_stmt_close($eq);
}
$exam_label = $exam_details['exam_set'] ?? ($exam_name_in ?: 'Exam');
$exam_max   = (int)($exam_details['exam_mark'] ?? 100);

// ── School info ───────────────────────────────────────────────────────────────
$school_info = ['school_name'=>'Your School','address'=>'','phone'=>'','email'=>'','logo_path'=>''];
$sr = mysqli_query($conn, "SELECT * FROM school_profile LIMIT 1");
if ($sr && mysqli_num_rows($sr)) $school_info = mysqli_fetch_assoc($sr);

// ═══════════════════════════════════════════════════════════════════════════
//  GRADING FUNCTIONS  (NCDC New Curriculum)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Convert a percentage mark to A–E grade (NCDC new scale).
 */
function markToGrade($mark): string
{
    if ($mark === null || $mark === '' || $mark === '-') return '-';
    $m = (float)$mark;
    if ($m >= 80) return 'A';
    if ($m >= 70) return 'B';
    if ($m >= 60) return 'C';
    if ($m >= 40) return 'D';
    return 'E';
}

function achievementLevel(string $grade): string
{
    return match($grade) {
        'A' => 'Exceptional',
        'B' => 'Outstanding',
        'C' => 'Satisfactory',
        'D' => 'Basic',
        'E' => 'Elementary',
        default => '—',
    };
}

function gradeClass(string $grade): string
{
    return match($grade) {
        'A' => 'g-a', 'B' => 'g-b', 'C' => 'g-c',
        'D' => 'g-d', 'E' => 'g-e', default => '',
    };
}

// ═══════════════════════════════════════════════════════════════════════════
//  PAPER COUNT HELPER
// ═══════════════════════════════════════════════════════════════════════════
function expectedPapers($conn, string $subject, string $class): array
{
    $s = mysqli_prepare($conn, "SELECT papers FROM subject_papers WHERE subject_name=? AND class=? LIMIT 1");
    if ($s) {
        mysqli_stmt_bind_param($s, 'ss', $subject, $class);
        mysqli_stmt_execute($s);
        mysqli_stmt_bind_result($s, $papers);
        if (mysqli_stmt_fetch($s)) {
            mysqli_stmt_close($s);
            return array_filter(array_map('trim', explode(',', $papers)));
        }
        mysqli_stmt_close($s);
    }
    return ['I']; // default: one paper
}

function normalizePaper(string $p): string
{
    $p = strtoupper(trim($p));
    return match($p) {
        'P1','PI','1' => 'I',
        'P2','PII','2' => 'II',
        'P3','PIII','3' => 'III',
        'P4','PIV','4' => 'IV',
        default => $p,
    };
}

// ═══════════════════════════════════════════════════════════════════════════
//  FETCH ALL STUDENTS + THEIR MARKS
// ═══════════════════════════════════════════════════════════════════════════
function fetchStudents($conn, string $table, string $class, array $streams, string $exam_type): array
{
    $esc_streams = implode("','", array_map(fn($s)=>mysqli_real_escape_string($conn,$s), $streams));
    $esc_class   = mysqli_real_escape_string($conn, $class);
    $esc_exam    = mysqli_real_escape_string($conn, $exam_type);

    // Pull students that actually have marks for this exam
    $sql = "SELECT DISTINCT s.student_id, s.first_name, s.last_name, m.stream
            FROM students s
            INNER JOIN `$table` m ON s.student_id = m.student_id
            WHERE m.class = '$esc_class'
              AND m.stream IN ('$esc_streams')
              AND m.exam_type = '$esc_exam'
            ORDER BY s.last_name ASC, s.first_name ASC";

    $res = mysqli_query($conn, $sql);
    $students = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $students[$row['student_id']] = [
                'student_id' => $row['student_id'],
                'full_name'  => strtoupper(trim($row['last_name'] . ' ' . $row['first_name'])),
                'stream'     => $row['stream'],
                'subjects'   => [],
            ];
        }
    }

    if (empty($students)) return [];

    // Pull all marks for those students
    $id_list = implode("','", array_map(fn($id)=>mysqli_real_escape_string($conn,$id), array_keys($students)));
    $marks_sql = "SELECT student_id, subject, paper, mark
                  FROM `$table`
                  WHERE student_id IN ('$id_list')
                    AND class = '$esc_class'
                    AND stream IN ('$esc_streams')
                    AND exam_type = '$esc_exam'";
    $mr = mysqli_query($conn, $marks_sql);
    if ($mr) {
        while ($row = mysqli_fetch_assoc($mr)) {
            $sid  = $row['student_id'];
            $subj = $row['subject'];
            $pap  = normalizePaper($row['paper']);
            $mark = ($row['mark'] !== null && $row['mark'] !== '') ? (float)$row['mark'] : null;
            if (!isset($students[$sid]['subjects'][$subj])) {
                $students[$sid]['subjects'][$subj] = [];
            }
            $students[$sid]['subjects'][$subj][$pap] = $mark;
        }
    }

    return $students;
}

// ═══════════════════════════════════════════════════════════════════════════
//  CALCULATE SUBJECT SUMMARY FOR EACH STUDENT
//  Returns: avg_mark (float|null), grade (string), achievement (string)
// ═══════════════════════════════════════════════════════════════════════════
function calcSubject($conn, string $class, string $subject, array $paperMarks): array
{
    $expected = expectedPapers($conn, $subject, $class);
    $marks     = [];

    foreach ($expected as $p) {
        if (isset($paperMarks[$p]) && $paperMarks[$p] !== null) {
            $marks[] = (float)$paperMarks[$p];
        }
    }

    if (empty($marks)) {
        return ['avg'=>null,'grade'=>'-','achievement'=>'—','expected'=>$expected,'has_all'=>false];
    }

    $avg  = round(array_sum($marks) / count($marks), 1);
    $grade = markToGrade($avg);
    return [
        'avg'         => $avg,
        'grade'       => $grade,
        'achievement' => achievementLevel($grade),
        'expected'    => $expected,
        'has_all'     => count($marks) >= count($expected),
    ];
}

// ── Fetch data ────────────────────────────────────────────────────────────────
$all_students = fetchStudents($conn, $table_name, $class, $streams, $exam_type);

// ── Enrich students with subject summaries ─────────────────────────────────
$all_subjects_set = []; // Track every unique subject that appears

foreach ($all_students as &$stu) {
    $stu['subject_summaries'] = [];
    $marks_list = [];
    foreach ($stu['subjects'] as $subj => $papers) {
        $info = calcSubject($conn, $class, $subj, $papers);
        $stu['subject_summaries'][$subj] = $info;
        if ($info['avg'] !== null) $marks_list[] = $info['avg'];
        $all_subjects_set[$subj] = true;
    }
    $stu['overall_avg']   = !empty($marks_list) ? round(array_sum($marks_list)/count($marks_list), 1) : null;
    $stu['overall_grade'] = markToGrade($stu['overall_avg']);
}
unset($stu);
ksort($all_subjects_set);

// ── Filter to single subject if requested ──────────────────────────────────
$is_single = ($subject_filter === 'single' && $subject_name !== '');
$subject_expected_papers = $is_single
    ? expectedPapers($conn, $subject_name, $class)
    : [];

// Sort students for single-subject view: by subject avg desc (rank)
if ($is_single) {
    uasort($all_students, function($a, $b) use ($subject_name) {
        $avgA = $a['subject_summaries'][$subject_name]['avg'] ?? -1;
        $avgB = $b['subject_summaries'][$subject_name]['avg'] ?? -1;
        return $avgB <=> $avgA;
    });
}

// Compute grade distribution for statistics
$grade_dist = ['A'=>0,'B'=>0,'C'=>0,'D'=>0,'E'=>0];
if ($is_single) {
    foreach ($all_students as $stu) {
        $g = $stu['subject_summaries'][$subject_name]['grade'] ?? '-';
        if (isset($grade_dist[$g])) $grade_dist[$g]++;
    }
} else {
    foreach ($all_students as $stu) {
        $g = $stu['overall_grade'];
        if (isset($grade_dist[$g])) $grade_dist[$g]++;
    }
}

$base_path   = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
$logo_url    = $base_path . '/' . ltrim($school_info['logo_path'] ?? '', '/');
$report_title = htmlspecialchars(strtoupper($class)) . ' — ' .
                htmlspecialchars($term) . ' ' . htmlspecialchars($exam_label) .
                ' MARKSHEET (' . htmlspecialchars($year) . ')';
if ($is_single) $report_title .= ' — ' . htmlspecialchars(strtoupper($subject_name));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $report_title ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/fonts/fontawesome-all.min.css">
    <!-- SheetJS for Excel export -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <style>
        /* ── Design tokens ─────────────────────────────────────────────── */
        :root {
            --g9: #1a4731; --g7: #1e8449; --g5: #27ae60;
            --g3: #2ecc71; --g1: #e8f5ee; --g0: #f2faf6;
            --red: #e53935; --amber: #f59e0b;
            --gr8: #1e293b; --gr6: #475569; --gr4: #94a3b8;
            --gr2: #e2e8f0; --gr1: #f1f5f9; --wh: #fff;
            --shadow: 0 4px 18px rgba(0,0,0,.10);
            --r: 8px; --trans: all .2s ease;
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body {
            font-family:'Sen',sans-serif;
            background:var(--gr1);
            color:var(--gr8);
            font-size:13px;
            padding-bottom:40px;
        }

        /* ── Toolbar (screen only) ──────────────────────────────────────── */
        .toolbar {
            background:var(--g9);
            color:#fff;
            padding:12px 20px;
            display:flex;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
            position:sticky; top:0; z-index:100;
        }
        .toolbar-title { font-weight:700; font-size:14px; flex:1; }
        .btn {
            display:inline-flex; align-items:center; gap:7px;
            padding:8px 16px; border:none; border-radius:6px;
            font-family:'Sen',sans-serif; font-size:13px;
            font-weight:700; cursor:pointer; transition:var(--trans);
            text-decoration:none;
        }
        .btn-white { background:#fff; color:var(--g9); }
        .btn-white:hover { background:var(--g1); }
        .btn-excel { background:#217346; color:#fff; }
        .btn-excel:hover { background:#1a5c37; }
        .btn-pdf   { background:var(--red); color:#fff; }
        .btn-pdf:hover   { background:#c62828; }
        .btn-back  { background:rgba(255,255,255,.15); color:#fff; }
        .btn-back:hover  { background:rgba(255,255,255,.25); }

        /* ── Page wrapper ───────────────────────────────────────────────── */
        .page { max-width:100%; margin:0 auto; padding:18px 14px; }

        /* ── Stats strip ────────────────────────────────────────────────── */
        .stats-strip {
            display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px;
        }
        .stat-card {
            background:var(--wh); border-radius:var(--r);
            padding:10px 18px; flex:1; min-width:110px;
            box-shadow:0 1px 4px rgba(0,0,0,.06);
            display:flex; flex-direction:column; align-items:center;
        }
        .stat-val { font-size:22px; font-weight:800; color:var(--g7); }
        .stat-lbl { font-size:11px; color:var(--gr6); margin-top:2px; font-weight:600; }
        .g-a-val{color:var(--g7)}
        .g-b-val{color:#1565c0}
        .g-c-val{color:#e65100}
        .g-d-val{color:#6a1a00}
        .g-e-val{color:var(--red)}

        /* ── Marksheet container ────────────────────────────────────────── */
        .ms-wrap {
            background:var(--wh); border-radius:var(--r);
            box-shadow:var(--shadow); overflow:hidden;
        }

        /* ── School header ──────────────────────────────────────────────── */
        .ms-header {
            background:linear-gradient(135deg,var(--g9),var(--g7));
            color:#fff; padding:18px 22px 14px;
            display:flex; align-items:center; gap:20px;
        }
        .ms-header img { height:70px; width:auto; border-radius:6px; background:#fff; padding:4px; }
        .ms-school-name { font-size:20px; font-weight:800; letter-spacing:.5px; }
        .ms-school-sub  { font-size:12px; opacity:.85; margin-top:2px; }
        .ms-header-right { margin-left:auto; text-align:right; }

        /* ── Report title bar ───────────────────────────────────────────── */
        .ms-title-bar {
            background:var(--red); color:#fff;
            text-align:center; padding:9px 16px;
            font-size:14px; font-weight:800;
            letter-spacing:.6px; text-transform:uppercase;
        }

        /* ── Grading key ────────────────────────────────────────────────── */
        .grade-key {
            background:var(--g0);
            padding:7px 16px;
            display:flex; gap:6px; flex-wrap:wrap;
            align-items:center; font-size:11px;
            border-bottom:1px solid var(--gr2);
        }
        .grade-key-label { font-weight:700; color:var(--g9); margin-right:4px; }
        .gk-badge {
            display:inline-flex; align-items:center; gap:4px;
            background:var(--wh); border:1px solid var(--gr2);
            border-radius:4px; padding:3px 8px; font-weight:700;
        }

        /* ── Table wrapper ──────────────────────────────────────────────── */
        .tbl-scroll { overflow-x:auto; }
        table.ms-table {
            width:100%; border-collapse:collapse;
            font-size:11.5px; min-width:600px;
        }
        .ms-table th, .ms-table td {
            border:1px solid var(--gr2);
            padding:7px 6px;
            text-align:center;
            vertical-align:middle;
            white-space:nowrap;
        }
        .ms-table thead tr:first-child th {
            background:var(--g7); color:#fff;
            font-size:11px; font-weight:700;
            text-transform:uppercase; letter-spacing:.4px;
            position:sticky; top:0; z-index:5;
        }
        .ms-table thead tr:nth-child(2) th {
            background:var(--g9); color:#fff;
            font-size:10px; font-weight:600;
        }
        .ms-table tbody tr:nth-child(even){ background:var(--g0); }
        .ms-table tbody tr:hover{ background:var(--g1); }

        /* Name column: left-aligned */
        .col-name { text-align:left!important; padding-left:10px!important; white-space:normal!important; font-weight:600; }
        .col-no   { font-weight:700; color:var(--gr6); }

        /* Grade colour classes */
        .g-a{color:var(--g7);font-weight:800}
        .g-b{color:#1565c0;font-weight:800}
        .g-c{color:#e65100;font-weight:800}
        .g-d{color:#880e4f;font-weight:800}
        .g-e{color:var(--red);font-weight:800}

        /* Mark cell highlight */
        .mark-cell { background:var(--g1)!important; font-weight:700; color:var(--g9); }
        .avg-cell  { background:#fff8e1!important; font-weight:800; color:#e65100; }
        .grade-cell{ background:var(--g0)!important; }
        .rank-cell { background:#e8eaf6!important; font-weight:800; color:#283593; }

        /* Subject header groups */
        .subj-hdr { background:var(--g7)!important; }
        .subj-hdr-alt { background:var(--g9)!important; }

        /* No data message */
        .no-data {
            text-align:center; padding:48px;
            color:var(--gr4); font-size:15px;
        }

        /* ── Footer info ────────────────────────────────────────────────── */
        .ms-footer {
            padding:10px 16px;
            font-size:11px; color:var(--gr6);
            border-top:1px solid var(--gr2);
            display:flex; justify-content:space-between; flex-wrap:wrap; gap:6px;
        }

        /* ═══════════════ PRINT ═══════════════ */
        @media print {
            body { background:#fff; font-size:9px; padding:0; }
            .toolbar, .stats-strip, .no-print { display:none!important; }
            .page { padding:0; margin:0; }
            .ms-wrap { box-shadow:none; border-radius:0; }
            .ms-header { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .ms-title-bar{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .ms-table thead tr:first-child th{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .ms-table thead tr:nth-child(2) th{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .ms-table tbody tr:nth-child(even){ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .g-a,.g-b,.g-c,.g-d,.g-e{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .mark-cell,.avg-cell,.grade-cell,.rank-cell{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            @page { size: landscape; margin:8mm; }
            .ms-table { font-size:8px!important; }
            .ms-table th, .ms-table td { padding:4px 3px!important; }
            .ms-header { padding:10px 14px; }
            .ms-school-name { font-size:15px; }
        }

        /* ═══════════════════════════════════════════════════════════════
           SKELETON LOADER
           ════════════════════════════════════════════════════════════════ */

        /* Shimmer keyframe */
        @keyframes skeleton-shimmer {
            0%   { background-position: -600px 0; }
            100% { background-position:  600px 0; }
        }

        .skeleton {
            background: linear-gradient(
                90deg,
                #e2e8f0 25%,
                #f1f5f9 50%,
                #e2e8f0 75%
            );
            background-size: 600px 100%;
            animation: skeleton-shimmer 1.4s ease-in-out infinite;
            border-radius: 5px;
        }

        /* Overlay that sits on top of the real content until JS removes it */
        #skeleton-overlay {
            position: fixed;
            inset: 0;
            background: var(--gr1);
            z-index: 999;
            overflow-y: auto;
            padding-bottom: 40px;
        }

        /* Fade-out when content is ready */
        #skeleton-overlay.fade-out {
            animation: skeleton-fade 0.35s ease forwards;
        }
        @keyframes skeleton-fade {
            to { opacity: 0; pointer-events: none; }
        }

        /* ── Skeleton toolbar ── */
        .sk-toolbar {
            background: var(--g9);
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .sk-toolbar-title { height: 16px; width: 220px; border-radius: 4px;
            background: rgba(255,255,255,.22); animation: none; }
        .sk-btn { height: 34px; border-radius: 6px;
            background: rgba(255,255,255,.18); animation: none; }

        /* ── Skeleton stats strip ── */
        .sk-stats {
            display: flex; gap: 10px; flex-wrap: wrap;
            max-width: 100%; padding: 18px 14px 0;
        }
        .sk-stat-card {
            flex: 1; min-width: 110px;
            background: var(--wh);
            border-radius: var(--r);
            padding: 10px 18px;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
            display: flex; flex-direction: column; align-items: center; gap: 6px;
        }
        .sk-stat-val  { height: 28px; width: 52px; }
        .sk-stat-lbl  { height: 11px; width: 64px; }

        /* ── Skeleton marksheet card ── */
        .sk-ms-wrap {
            background: var(--wh);
            border-radius: var(--r);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin: 14px 14px 0;
        }

        /* Header block */
        .sk-ms-header {
            background: linear-gradient(135deg, var(--g9), var(--g7));
            padding: 18px 22px 14px;
            display: flex; align-items: center; gap: 20px;
        }
        .sk-logo    { width: 70px; height: 70px; border-radius: 6px;
            background: rgba(255,255,255,.25); animation: none; }
        .sk-ms-header-body { display: flex; flex-direction: column; gap: 8px; flex: 1; }
        .sk-school-name { height: 20px; width: 240px;
            background: rgba(255,255,255,.30); animation: none; border-radius: 5px; }
        .sk-school-sub  { height: 13px; width: 180px;
            background: rgba(255,255,255,.18); animation: none; border-radius: 4px; }

        /* Title bar */
        .sk-title-bar {
            background: var(--red);
            padding: 9px 16px;
            display: flex; justify-content: center;
        }
        .sk-title-text { height: 14px; width: 340px; max-width: 80%;
            background: rgba(255,255,255,.30); animation: none; border-radius: 4px; }

        /* Grade key row */
        .sk-grade-key {
            background: var(--g0);
            padding: 7px 16px;
            display: flex; gap: 8px; flex-wrap: wrap; align-items: center;
            border-bottom: 1px solid var(--gr2);
        }
        .sk-gk-badge { height: 22px; border-radius: 4px; }

        /* Table skeleton */
        .sk-table-wrap { padding: 16px; display: flex; flex-direction: column; gap: 0; }
        .sk-thead {
            display: flex; gap: 0;
            background: var(--g7);
            border-radius: 6px 6px 0 0;
            padding: 10px 12px;
            gap: 8px;
        }
        .sk-th { height: 13px; border-radius: 4px;
            background: rgba(255,255,255,.28); animation: none; }

        .sk-tbody { display: flex; flex-direction: column; }
        .sk-row {
            display: flex; gap: 8px; padding: 9px 12px;
            border-bottom: 1px solid var(--gr2);
            align-items: center;
        }
        .sk-row:nth-child(even) { background: var(--g0); }
        .sk-cell { height: 13px; border-radius: 4px; flex-shrink: 0; }

        /* Footer */
        .sk-footer {
            padding: 10px 16px;
            display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px;
            border-top: 1px solid var(--gr2);
        }
        .sk-footer-text { height: 11px; border-radius: 4px; }
    </style>
</head>
<body>

<!-- ═══════════════════ SKELETON LOADER OVERLAY ═══════════════════ -->
<div id="skeleton-overlay" aria-hidden="true" aria-label="Loading marksheet…">

    <!-- Skeleton toolbar -->
    <div class="sk-toolbar">
        <div class="sk-toolbar-title"></div>
        <div class="sk-btn skeleton" style="width:82px"></div>
        <div class="sk-btn skeleton" style="width:140px"></div>
        <div class="sk-btn skeleton" style="width:112px"></div>
    </div>

    <!-- Skeleton stats strip -->
    <div class="sk-stats no-print">
        <?php for ($i = 0; $i < 8; $i++): ?>
        <div class="sk-stat-card">
            <div class="sk-stat-val skeleton"></div>
            <div class="sk-stat-lbl skeleton"></div>
        </div>
        <?php endfor; ?>
    </div>

    <!-- Skeleton marksheet card -->
    <div class="sk-ms-wrap">

        <!-- Header -->
        <div class="sk-ms-header">
            <div class="sk-logo"></div>
            <div class="sk-ms-header-body">
                <div class="sk-school-name skeleton"></div>
                <div class="sk-school-sub skeleton"></div>
            </div>
        </div>

        <!-- Title bar -->
        <div class="sk-title-bar">
            <div class="sk-title-text skeleton"></div>
        </div>

        <!-- Grade key -->
        <div class="sk-grade-key">
            <div class="sk-gk-badge skeleton" style="width:44px"></div>
            <?php for ($i = 0; $i < 5; $i++): ?>
            <div class="sk-gk-badge skeleton" style="width:<?= 100 + $i * 10 ?>px"></div>
            <?php endfor; ?>
        </div>

        <!-- Table skeleton -->
        <div class="sk-table-wrap">
            <div class="sk-thead">
                <div class="sk-th skeleton" style="width:28px"></div>
                <div class="sk-th skeleton" style="width:160px"></div>
                <div class="sk-th skeleton" style="width:60px"></div>
                <?php for ($i = 0; $i < 6; $i++): ?>
                <div class="sk-th skeleton" style="flex:1;min-width:50px"></div>
                <?php endfor; ?>
            </div>
            <div class="sk-tbody">
                <?php for ($row = 0; $row < 12; $row++): ?>
                <div class="sk-row">
                    <div class="sk-cell skeleton" style="width:24px"></div>
                    <div class="sk-cell skeleton" style="width:<?= 140 + ($row % 3) * 20 ?>px"></div>
                    <div class="sk-cell skeleton" style="width:54px"></div>
                    <?php for ($col = 0; $col < 6; $col++): ?>
                    <div class="sk-cell skeleton" style="flex:1;min-width:44px"></div>
                    <?php endfor; ?>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Footer -->
        <div class="sk-footer">
            <div class="sk-footer-text skeleton" style="width:220px"></div>
            <div class="sk-footer-text skeleton" style="width:280px"></div>
        </div>
    </div>

</div><!-- /#skeleton-overlay -->

<!-- ═══════════════════ TOOLBAR ═══════════════════ -->
<div class="toolbar no-print">
    <span class="toolbar-title"><i class="fas fa-table"></i> <?= htmlspecialchars($is_single ? strtoupper($subject_name).' — ' : '') ?>A-Level Marksheet</span>
    <a href="sel_gen_markshet.php" class="btn btn-back"><i class="fas fa-arrow-left"></i> Back</a>
    <button class="btn btn-excel" id="btn-excel"><i class="fas fa-file-excel"></i> Download Excel</button>
    <button class="btn btn-pdf"   onclick="window.print()"><i class="fas fa-file-pdf"></i> Print / PDF</button>
</div>

<div class="page">

<?php if (!empty($all_students)): ?>

<!-- ═══════════════════ STATS STRIP ═══════════════════ -->
<div class="stats-strip no-print">
    <div class="stat-card">
        <span class="stat-val"><?= count($all_students) ?></span>
        <span class="stat-lbl">Students</span>
    </div>
    <?php foreach ($grade_dist as $g=>$cnt): ?>
    <div class="stat-card">
        <span class="stat-val <?= 'g-'.strtolower($g).'-val' ?>"><?= $cnt ?></span>
        <span class="stat-lbl">Grade <?= $g ?></span>
    </div>
    <?php endforeach; ?>
    <div class="stat-card">
        <span class="stat-val"><?= count($all_students) > 0 ? round(($grade_dist['A']+$grade_dist['B']+$grade_dist['C'])/count($all_students)*100) : 0 ?>%</span>
        <span class="stat-lbl">Pass Rate (A–C)</span>
    </div>
</div>

<?php endif; ?>

<!-- ═══════════════════ MARKSHEET CONTAINER ═══════════════════ -->
<div class="ms-wrap" id="marksheet-container">

    <!-- School Header -->
    <div class="ms-header">
        <?php if (!empty($school_info['logo_path'])): ?>
        <img src="<?= htmlspecialchars($logo_url) ?>" alt="Logo" onerror="this.style.display='none'">
        <?php endif; ?>
        <div>
            <div class="ms-school-name"><?= htmlspecialchars($school_info['school_name'] ?? 'School Name') ?></div>
            <div class="ms-school-sub">
                <?= htmlspecialchars($school_info['address'] ?? '') ?>
                <?php if (!empty($school_info['phone'])): ?> &nbsp;|&nbsp; <?= htmlspecialchars($school_info['phone']) ?><?php endif; ?>
                <?php if (!empty($school_info['email'])): ?> &nbsp;|&nbsp; <?= htmlspecialchars($school_info['email']) ?><?php endif; ?>
            </div>
        </div>
        <div class="ms-header-right" style="font-size:12px;opacity:.85;">
            <div>Streams: <?= htmlspecialchars(implode(', ', $streams)) ?></div>
            <div><?= htmlspecialchars($term) ?> &middot; <?= htmlspecialchars($year) ?></div>
        </div>
    </div>

    <!-- Title bar -->
    <div class="ms-title-bar"><?= $report_title ?></div>

    <!-- Grading key -->
    <div class="grade-key">
        <span class="grade-key-label">NCDC GRADING KEY:</span>
        <span class="gk-badge"><span class="g-a">A</span> ≥ 80% Exceptional</span>
        <span class="gk-badge"><span class="g-b">B</span> ≥ 70% Outstanding</span>
        <span class="gk-badge"><span class="g-c">C</span> ≥ 60% Satisfactory</span>
        <span class="gk-badge"><span class="g-d">D</span> ≥ 40% Basic</span>
        <span class="gk-badge"><span class="g-e">E</span>  &lt; 40% Elementary</span>
    </div>

    <!-- Table -->
    <div class="tbl-scroll">
    <?php if (empty($all_students)): ?>
        <div class="no-data"><i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:12px"></i>No student records found for the selected criteria.</div>
    <?php elseif ($is_single): ?>
    <!-- ╔═══════════════════════════════════════════════════════╗
         ║  SINGLE SUBJECT VIEW                                  ║
         ╚═══════════════════════════════════════════════════════╝ -->
    <table class="ms-table" id="ms-table">
        <thead>
            <tr>
                <th rowspan="2">#</th>
                <th rowspan="2" class="col-name" style="text-align:left">STUDENT NAME</th>
                <th rowspan="2">STREAM</th>
                <?php foreach ($subject_expected_papers as $pap): ?>
                <th colspan="1" class="subj-hdr">PAPER <?= htmlspecialchars($pap) ?></th>
                <?php endforeach; ?>
                <th rowspan="2" class="subj-hdr">AVG MARK (%)</th>
                <th rowspan="2" class="subj-hdr">GRADE</th>
                <th rowspan="2" class="subj-hdr">ACHIEVEMENT LEVEL</th>
                <th rowspan="2" class="subj-hdr">RANK</th>
            </tr>
            <tr>
                <?php foreach ($subject_expected_papers as $pap): ?>
                <th class="subj-hdr-alt">Score (%)</th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php
        $rank = 1;
        $prev_avg = null;
        $actual_rank = 1;
        $counter = 0;
        foreach ($all_students as $stu):
            $counter++;
            $info  = $stu['subject_summaries'][$subject_name] ?? null;
            $papers= $stu['subjects'][$subject_name] ?? [];
            $avg   = $info['avg']   ?? null;
            $grade = $info['grade'] ?? '-';
            $achv  = $info['achievement'] ?? '—';

            // Ranking with tie-handling
            if ($prev_avg !== null && $avg !== $prev_avg) $actual_rank = $counter;
            if ($avg !== null) $prev_avg = $avg;
        ?>
        <tr>
            <td class="col-no"><?= $counter ?></td>
            <td class="col-name"><?= htmlspecialchars($stu['full_name']) ?></td>
            <td><?= htmlspecialchars($stu['stream']) ?></td>
            <?php foreach ($subject_expected_papers as $pap): ?>
            <td class="mark-cell"><?= isset($papers[$pap]) && $papers[$pap]!==null ? number_format((float)$papers[$pap],1) : '—' ?></td>
            <?php endforeach; ?>
            <td class="avg-cell"><?= $avg !== null ? number_format($avg,1) : '—' ?></td>
            <td class="grade-cell <?= gradeClass($grade) ?>"><?= htmlspecialchars($grade) ?></td>
            <td><?= htmlspecialchars($achv) ?></td>
            <td class="rank-cell"><?= $avg !== null ? $actual_rank : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php else: ?>
    <!-- ╔═══════════════════════════════════════════════════════╗
         ║  ALL SUBJECTS VIEW                                     ║
         ╚═══════════════════════════════════════════════════════╝ -->
    <?php
    // Build ordered subject list: principal first, subsidiary last
    $subsidiary_keys = ['gp','sict','smath','subsidiary ict','sub math','general paper','subict','subsidiary math','subsidiary mathematics'];
    $principal_subjs   = [];
    $subsidiary_subjs  = [];
    foreach (array_keys($all_subjects_set) as $s) {
        if (in_array(strtolower(trim($s)), $subsidiary_keys, true)) $subsidiary_subjs[] = $s;
        else $principal_subjs[] = $s;
    }
    $ordered_subjects = array_merge($principal_subjs, $subsidiary_subjs);
    $pcount = count($principal_subjs);
    $scount = count($subsidiary_subjs);
    ?>
    <table class="ms-table" id="ms-table">
        <thead>
            <tr>
                <th rowspan="2">#</th>
                <th rowspan="2" class="col-name" style="text-align:left">STUDENT NAME</th>
                <th rowspan="2">STREAM</th>
                <?php if ($pcount): ?>
                <th colspan="<?= $pcount * 2 ?>" class="subj-hdr">PRINCIPAL SUBJECTS</th>
                <?php endif; ?>
                <?php if ($scount): ?>
                <th colspan="<?= $scount * 2 ?>" style="background:var(--red);color:#fff;font-weight:700">SUBSIDIARY SUBJECTS</th>
                <?php endif; ?>
                <th rowspan="2" class="subj-hdr">OVERALL AVG (%)</th>
                <th rowspan="2" class="subj-hdr">OVERALL GRADE</th>
            </tr>
            <tr>
                <?php
                $col = 0;
                foreach ($ordered_subjects as $s):
                    $isPrincipal = !in_array(strtolower(trim($s)), $subsidiary_keys, true);
                    $bg = $isPrincipal ? 'var(--g9)' : 'var(--red)';
                ?>
                <th style="background:<?= $bg ?>;color:#fff;font-size:10px"><?= htmlspecialchars($s) ?></th>
                <th style="background:<?= $bg ?>;color:#fff;font-size:10px">Grade</th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php $counter=0; foreach ($all_students as $stu): $counter++; ?>
        <tr>
            <td class="col-no"><?= $counter ?></td>
            <td class="col-name"><?= htmlspecialchars($stu['full_name']) ?></td>
            <td><?= htmlspecialchars($stu['stream']) ?></td>
            <?php foreach ($ordered_subjects as $s):
                $info  = $stu['subject_summaries'][$s] ?? null;
                $avg   = $info['avg']   ?? null;
                $grade = $info['grade'] ?? '—';
            ?>
            <td class="mark-cell"><?= $avg !== null ? number_format($avg,1) : '—' ?></td>
            <td class="grade-cell <?= gradeClass($grade) ?>"><?= htmlspecialchars($grade) ?></td>
            <?php endforeach; ?>
            <td class="avg-cell"><?= $stu['overall_avg'] !== null ? number_format($stu['overall_avg'],1) : '—' ?></td>
            <td class="grade-cell <?= gradeClass($stu['overall_grade']) ?>"><?= htmlspecialchars($stu['overall_grade']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    </div><!-- /tbl-scroll -->

    <!-- Footer -->
    <div class="ms-footer">
        <span><?= htmlspecialchars($school_info['school_name'] ?? '') ?> &mdash; Generated: <?= date('d M Y, H:i') ?></span>
        <span>Grading: NCDC New Curriculum &mdash; A ≥ 80 · B ≥ 70 · C ≥ 60 · D ≥ 40 · E &lt; 40</span>
    </div>

</div><!-- /ms-wrap -->
</div><!-- /page -->

<script src="../assets/js/jquery.min.js"></script>
<script>
/**
 * 1. SKELETON LOADER DISMISSAL
 */
(function () {
    const overlay = document.getElementById('skeleton-overlay');
    if (!overlay) return;

    const dismiss = () => {
        // Prevent multiple calls if both 'load' and timer fire
        if (overlay.classList.contains('fade-out')) return;

        overlay.classList.add('fade-out');
        setTimeout(() => { 
            overlay.style.display = 'none'; 
            overlay.remove(); 
        }, 400); 
    };

    // Dismiss when page is fully loaded
    window.addEventListener('load', dismiss);

    // Fail-safe: Force dismiss after 3 seconds if resources are slow
    setTimeout(dismiss, 3000); 
})();

/**
 * 2. EXCEL EXPORT (SheetJS)
 */
document.getElementById('btn-excel').addEventListener('click', function () {
    const table = document.getElementById('ms-table');
    if (!table) { alert('No table data to export.'); return; }

    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.table_to_sheet(table, { raw: false, display: true });

    // Heuristic column width sizing
    const range = XLSX.utils.decode_range(ws['!ref']);
    ws['!cols'] = [];
    for (let C = range.s.c; C <= range.e.c; C++) {
        let maxLen = 8;
        for (let R = range.s.r; R <= range.e.r; R++) {
            const cell = ws[XLSX.utils.encode_cell({r:R,c:C})];
            if (cell && cell.v) maxLen = Math.max(maxLen, String(cell.v).length + 2);
        }
        ws['!cols'].push({ wch: Math.min(maxLen, 30) });
    }

    const filename = <?= json_encode(preg_replace('/[^a-z0-9 \-_]/i','',$report_title)) ?>;
    XLSX.utils.book_append_sheet(wb, ws, 'Marksheet');
    XLSX.writeFile(wb, filename + '.xlsx');
});
</script>
</body>
</html>