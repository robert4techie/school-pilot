<?php
// ── PERFORMANCE LIMITS ────────────────────────────────────────────────────────
// Must be set before any significant work begins.
@ini_set('memory_limit', '512M');
@set_time_limit(0);
// Start output buffering — lets us call ob_flush() to stream each report card
// to the browser as it's computed, rather than waiting for all 500 to finish.
if (ob_get_level() === 0) ob_start();

// ── VERIFIED QR-CODE ACCESS ───────────────────────────────────────────────────
$is_verified_access = false;

if (isset($_GET['verified'], $_GET['verification_token'], $_GET['student_id'], $_GET['term'], $_GET['year'])) {
    $expected_token = md5($_GET['student_id'] . $_GET['term'] . $_GET['year'] . 'OU_SECRET_KEY');
    if ($_GET['verification_token'] === $expected_token) {
        $is_verified_access = true;

        $_POST['class']  = $_GET['class']  ?? '';
        $_POST['term']   = $_GET['term']   ?? '';
        $_POST['year']   = $_GET['year']   ?? '';
        $_POST['level']  = 'A Level';
        $_POST['streams'] = isset($_GET['stream']) ? [$_GET['stream']] : [];

        if (isset($_GET['exam_sets']) && $_GET['exam_sets'] !== '') {
            $_POST['exam_sets'] = explode(',', $_GET['exam_sets']);
        } else {
            require_once '../conn.php';
            $student_id  = $_GET['student_id'];
            $term_number = filter_var($_GET['term'], FILTER_SANITIZE_NUMBER_INT);
            $romans      = ['i', 'ii', 'iii'];
            $term_roman  = $romans[$term_number - 1] ?? 'i';
            $table_name  = "{$_GET['year']}_{$term_roman}_alevel";

            $exam_sets_query = "SELECT DISTINCT exam_type FROM `$table_name` WHERE student_id = ?";
            $exam_stmt = mysqli_prepare($conn, $exam_sets_query);
            if ($exam_stmt) {
                mysqli_stmt_bind_param($exam_stmt, 's', $student_id);
                mysqli_stmt_execute($exam_stmt);
                $exam_result = mysqli_stmt_get_result($exam_stmt);
                $exam_sets   = [];
                while ($row = mysqli_fetch_assoc($exam_result)) {
                    $exam_sets[] = $row['exam_type'];
                }
                mysqli_stmt_close($exam_stmt);
                $_POST['exam_sets'] = $exam_sets;
            }
        }
        $_POST['grading_type'] = 'percentage';
    }
}

if (!$is_verified_access) {
    require_once '../auth.php';
}

// ── DATABASE & CACHE HEADERS ──────────────────────────────────────────────────
require_once '../conn.php';

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// ── PARAMETERS ────────────────────────────────────────────────────────────────
$class    = $_POST['class']   ?? '';
$term     = $_POST['term']    ?? '';
$year     = $_POST['year']    ?? '';
$level    = $_POST['level']   ?? 'A Level';
$streams  = isset($_POST['streams'])
    ? (is_array($_POST['streams']) ? $_POST['streams'] : explode(',', $_POST['streams']))
    : [];
$exam_sets = isset($_POST['exam_sets'])
    ? (is_array($_POST['exam_sets']) ? $_POST['exam_sets'] : explode(',', $_POST['exam_sets']))
    : [];

$grading_type      = $_POST['grading_type'] ?? 'percentage';
$custom_grade      = isset($_POST['custom_grade']) ? intval($_POST['custom_grade']) : 100;
$use_custom_grading = ($grading_type === 'custom' && count($exam_sets) === 1);

// ── SCHOOL PROFILE (single query — used for both header and next-term fees) ───
$school_info = ['school_name' => 'Your School Name', 'address' => 'Your Address', 'phone_number' => ''];
$school_result = mysqli_query($conn, "SELECT * FROM school_profile LIMIT 1");
if ($school_result && mysqli_num_rows($school_result) > 0) {
    $school_info = mysqli_fetch_assoc($school_result);
}
// $school_settings is the same data — alias it so old references don't break.
$school_settings = $school_info;

// ── SUBJECTS LOOKUP ───────────────────────────────────────────────────────────
$subjects_info = [];
$subjects_result = mysqli_query($conn, 'SELECT * FROM subjects');
if ($subjects_result) {
    while ($row = mysqli_fetch_assoc($subjects_result)) {
        $subjects_info[strtolower(trim($row['subj_name']))] = $row;
    }
}

// ── PRE-CACHE SUBJECT PAPER COUNTS (one query, not N×M) ───────────────────────
// Previously: getSubjectPaperCount() ran one DB query per subject per student.
// Fix: fetch ALL paper configs for this class in a single query before the loop.
$paper_count_cache = [];
$pc_stmt = mysqli_prepare($conn, 'SELECT subject_name, papers FROM subject_papers WHERE class = ?');
if ($pc_stmt) {
    mysqli_stmt_bind_param($pc_stmt, 's', $class);
    mysqli_stmt_execute($pc_stmt);
    $pc_result = mysqli_stmt_get_result($pc_stmt);
    while ($pc_row = mysqli_fetch_assoc($pc_result)) {
$paper_count_cache[strtolower(trim($pc_row['subject_name']))] = $pc_row['papers'];    }
    mysqli_stmt_close($pc_stmt);
}

// ── EXAM SETS INFO ────────────────────────────────────────────────────────────
$exam_sets_info = [];
$exam_columns   = [];

if (!empty($exam_sets)) {
    $exam_ids = implode(',', array_map(
        fn($id) => "'" . mysqli_real_escape_string($conn, $id) . "'",
        $exam_sets
    ));
    $exam_result = mysqli_query($conn, "SELECT * FROM exam_sets WHERE id IN ($exam_ids)");
    while ($row = mysqli_fetch_assoc($exam_result)) {
       $exam_sets_info[(string)$row['id']] = $row;
        $column = mapExamTypeToColumn($row['exam_set']);
        if (!in_array($column, $exam_columns) && in_array($column, ['BOT', 'MID', 'EOT', 'MOCK'])) {
            $exam_columns[] = $column;
        }
    }
}

// ── TEACHER INITIALS CACHE (single query) ────────────────────────────────────
$teacher_initials_cache = [];
$initials_result = mysqli_query($conn, "
    SELECT ta.class_name, ta.stream_name, s.subj_name,
           CONCAT(st.first_name, ' ', st.last_name) AS full_name
    FROM teaching_assignments ta
    JOIN subjects s  ON ta.subject_id = s.subj_id
    JOIN staff   st  ON ta.staff_id   = st.staff_id
    WHERE ta.class_name COLLATE utf8mb4_general_ci = '" . mysqli_real_escape_string($conn, $class) . "'
");
if ($initials_result) {
    while ($row = mysqli_fetch_assoc($initials_result)) {
        $name_parts = explode(' ', $row['full_name']);
        $initials   = '';
        if (count($name_parts) > 1) {
            $initials = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));
        } elseif (!empty($name_parts[0])) {
            $initials = strtoupper(substr($name_parts[0], 0, 2));
        }
        if (!empty($initials)) {
            $teacher_initials_cache
                [strtolower(trim($row['class_name']))]
                [strtolower(trim($row['stream_name']))]
                [strtolower(trim($row['subj_name']))] = $initials;
        }
    }
}

// ── COLUMN ORDER & REPORT TYPE ────────────────────────────────────────────────
$standard_order = ['EOT', 'BOT', 'MID', 'MOCK'];
usort($exam_columns, fn($a, $b) =>
    array_search($a, $standard_order) - array_search($b, $standard_order)
);

$isEndOfTermReport = false;
foreach ($exam_sets_info as $exam) {
    if (mapExamTypeToColumn($exam['exam_set']) === 'EOT') {
        $isEndOfTermReport = true;
        break;
    }
}

// ── TERM → TABLE NAME ─────────────────────────────────────────────────────────
$term_num = substr($term, -1);
$term_roman = match($term_num) { '1' => 'i', '2' => 'ii', '3' => 'iii', default => 'i' };
$table_name = $year . '_' . $term_roman . '_alevel';

// ── TABLE EXISTENCE GUARD ─────────────────────────────────────────────────────
$_tbl_check   = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table_name) . "'");
$_table_exists = ($_tbl_check && mysqli_num_rows($_tbl_check) > 0);

if (!$_table_exists) {
    $term_labels = ['1' => 'Term 1', '2' => 'Term 2', '3' => 'Term 3'];
    $term_label  = $term_labels[$term_num] ?? "Term {$term_num}";
    ob_end_clean();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400..800&display=swap" rel="stylesheet">
    <title>No Records Found</title>
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Sen',sans-serif;background:#f0f4f8;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem;color:#1e293b}
        .card{background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.10);max-width:520px;width:100%;padding:3rem 2.5rem;text-align:center}
        .icon-wrap{width:80px;height:80px;border-radius:50%;background:#fef9c3;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem}
        .icon-wrap svg{width:40px;height:40px;color:#ca8a04}
        h1{font-size:1.5rem;font-weight:700;margin-bottom:.75rem;color:#0f172a}
        p{font-size:.975rem;line-height:1.65;color:#475569}
        .detail-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:1rem 1.25rem;margin:1.5rem 0;text-align:left;font-size:.875rem}
        .detail-box dl{display:grid;grid-template-columns:auto 1fr;gap:.4rem 1rem}
        .detail-box dt{color:#64748b;font-weight:600;white-space:nowrap}
        .detail-box dd{color:#1e293b;font-weight:500}
        code{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:4px;padding:.15rem .4rem;font-size:.8rem;color:#334155}
        .actions{display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;margin-top:1.75rem}
        .btn{display:inline-flex;align-items:center;gap:.4rem;padding:.6rem 1.4rem;border-radius:8px;font-size:.9rem;font-weight:600;text-decoration:none;cursor:pointer;border:none;transition:opacity .15s}
        .btn:hover{opacity:.85}
        .btn-primary{background:#1d4ed8;color:#fff}
        .btn-ghost{background:#e2e8f0;color:#334155}
    </style>
</head>
<body>
<div class="card">
    <div class="icon-wrap">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
        </svg>
    </div>
    <h1>No Exam Records Found</h1>
    <p>The marks table for the period you selected has not been created yet, or no data has been entered.</p>
    <div class="detail-box">
        <dl>
            <dt>Academic Year</dt><dd><?php echo htmlspecialchars($year); ?></dd>
            <dt>Term</dt><dd><?php echo htmlspecialchars($term_label); ?></dd>
            <?php if (!empty($class)): ?><dt>Class</dt><dd><?php echo htmlspecialchars($class); ?></dd><?php endif; ?>
        </dl>
    </div>
    <p>If marks have been entered, contact your administrator and quote reference <code><?php echo htmlspecialchars($table_name); ?></code>.</p>
    <div class="actions">
        <button class="btn btn-ghost" onclick="window.history.back()">&larr; Go Back</button>
        <a href="sel_gen_reports.php" class="btn btn-primary">Return to Reports</a>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}
// ── END TABLE EXISTENCE GUARD ─────────────────────────────────────────────────

// ══════════════════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS — ALL CALCULATION LOGIC IS PRESERVED EXACTLY
// ══════════════════════════════════════════════════════════════════════════════

function normalizePaperName($paper)
{
    $paper = strtoupper(trim($paper));
    switch ($paper) {
        case 'P1': case 'PI':  case '1': return 'I';
        case 'P2': case 'PII': case '2': return 'II';
        case 'P3': case 'PIII':case '3': return 'III';
        case 'P4': case 'PIV': case '4': return 'IV';
        default:
            return in_array($paper, ['I', 'II', 'III', 'IV', 'V', 'VI']) ? $paper : $paper;
    }
}

function mapExamTypeToColumn($exam_type_name)
{
    $exam_type_name = strtoupper($exam_type_name);
    if (strpos($exam_type_name, 'BOT') !== false || strpos($exam_type_name, 'BEGINNING') !== false) return 'BOT';
    elseif (strpos($exam_type_name, 'MID') !== false)  return 'MID';
    elseif (strpos($exam_type_name, 'EOT') !== false || strpos($exam_type_name, 'END') !== false) return 'EOT';
    elseif (strpos($exam_type_name, 'MOCK') !== false) return 'MOCK';
    else return 'OTHER';
}

// Stubs kept — calculation logic references these signatures in some builds
function calculatePaperGrade($marks) { return '-'; }
function gradeToNumber($grade)       { return 0; }

/**
 * NCDC CBC Scale (Ministry, 2024):
 *   A = 80–100 (Exceptional)
 *   B = 70–79  (Outstanding)
 *   C = 60–69  (Satisfactory)
 *   D = 50–59  (Basic / Minimum Competence)
 *   E = 0–49   (Elementary / No Competence)
 * Applies to BOTH principal and subsidiary subjects.
 */
function markToGrade($mark)
{
    if ($mark === null || $mark === '-') return '-';
    $mark = (float) $mark;
    if ($mark >= 80) return 'A';
    if ($mark >= 70) return 'B';
    if ($mark >= 60) return 'C';
    if ($mark >= 50) return 'D';
    return 'E';
}

/**
 * Averages all paper totals then maps to A–E.
 * UNCHANGED — do not modify.
 */
function calculateSubjectGrade($subject, $paperTotals)
{
    $valid = array_filter($paperTotals, fn($t) => $t !== '-' && $t !== null);
    if (empty($valid)) return '-';
    $avg = round(array_sum($valid) / count($valid));
    return markToGrade($avg);
}

/**
 * NCDC CBC Points System (Ministry, 2024):
 *   Principal  → A=5, B=4, C=3, D=2, E=1
 *   Subsidiary → D/C/B/A (50–100) = 1 pt; E (0–49) = 0 pts
 */
function calculatePoints($grade, $isSubsidiary)
{
    if ($grade === '-' || $grade === null) return 0;
    if ($isSubsidiary) {
        return in_array($grade, ['A', 'B', 'C', 'D']) ? 1 : 0;
    }
    $map = ['A' => 5, 'B' => 4, 'C' => 3, 'D' => 2, 'E' => 1];
    return $map[$grade] ?? 0;
}

/**
 * Determines if a subject is Subsidiary per NCDC CBC.
 * Ministry subsidiary subjects: General Paper (GP), Subsidiary ICT, Subsidiary Mathematics (Sub-Math).
 * Covers all realistic naming variations entered by schools.
 * UNCHANGED logic — only expanding the match list for completeness.
 */
function isSubsidiarySubject($subject)
{
    $s = strtolower(trim($subject));

    // Exact known codes / short names
    $exact = [
        'gp', 'ict', 'sict', 'smath', 'subict', 'submath',
        'sub math', 'sub-math', 'sub ict', 'sub-ict',
    ];
    if (in_array($s, $exact)) return true;

    // Keyword-based matching for longer names
    // A subject is subsidiary if it contains 'general paper', 'subsidiary', or 'sub math'/'sub-math'
    if (str_contains($s, 'general paper'))   return true;
    if (str_contains($s, 'subsidiary'))      return true;  // subsidiary ict, subsidiary mathematics, etc.
    if (str_contains($s, 'sub math'))        return true;
    if (str_contains($s, 'sub-math'))        return true;

    return false;
}

function getSubjectCode($subject, $subjects_info, $isAlevel = true)
{
    $subject_lc = strtolower(trim($subject));
    if (isset($subjects_info[$subject_lc])) {
        return $isAlevel ? $subjects_info[$subject_lc]['codea'] : $subjects_info[$subject_lc]['code'];
    }
    return 'SUBJ';
}

/**
 * Converts raw mark to scaled display value. UNCHANGED.
 */
function convertMarkToScale($percentage_mark, $max_mark, $use_custom_grading = false, $custom_grade = 100)
{
    if ($percentage_mark === null || $percentage_mark === '') return '-';
    if ($use_custom_grading) {
        return round(($percentage_mark / 100) * $custom_grade);
    }
    return round(($percentage_mark / 100) * $max_mark);
}

/**
 * Calculates the average percentage for the TOTAL column. UNCHANGED.
 */
function calculateTotal($paper_data, $exam_columns, $use_custom_grading = false, $custom_grade = 100)
{
    if (count($exam_columns) === 1 && $use_custom_grading) {
        $column = $exam_columns[0];
        $mark   = $paper_data[$column . '_display'] ?? null;
        return ($mark !== null && $mark !== '-') ? round(($mark / $custom_grade) * 100) : '-';
    }
    if (count($exam_columns) === 1) {
        $column = $exam_columns[0];
        return $paper_data[$column] ?? '-';
    }
    $total_marks = 0;
    $count       = 0;
    foreach ($exam_columns as $column) {
        if (isset($paper_data[$column]) && $paper_data[$column] !== '-') {
            $total_marks += $paper_data[$column];
            $count++;
        }
    }
    return $count > 0 ? round($total_marks / $count) : '-';
}

/**
 * Calculates the scaled display value for the TOTAL column. UNCHANGED.
 */
function calculateTotalDisplay($paper_data, $exam_columns)
{
    $display_marks = [];
    foreach ($exam_columns as $column) {
        $val = $paper_data[$column . '_display'] ?? '-';
        if ($val !== '-' && $val !== null) {
            $display_marks[] = (float) $val;
        }
    }
    if (empty($display_marks)) return '-';
    return count($display_marks) === 1
        ? $display_marks[0]
        : round(array_sum($display_marks) / count($display_marks));
}

/**
 * Looks up teacher initials from the pre-fetched cache. UNCHANGED.
 */
function getTeacherInitials($cache, $class, $stream, $subject)
{
    $class_key   = strtolower(trim($class));
    $stream_key  = strtolower(trim($stream));
    $subject_key = strtolower(trim($subject));
    if (isset($cache[$class_key][$stream_key][$subject_key]))    return $cache[$class_key][$stream_key][$subject_key];
    if (isset($cache[$class_key]['all streams'][$subject_key]))  return $cache[$class_key]['all streams'][$subject_key];
    return '-';
}

function getExpectedPaperNumbers($papers_string)
{
    return array_map('trim', explode(',', $papers_string));
}

// ══════════════════════════════════════════════════════════════════════════════
// OPTIMISED getStudentData()
//
// WHAT CHANGED (performance only — zero logic changes):
//   OLD: 1 query for subjects + 1 query per subject for marks
//        + 1 DB query per subject for paper counts  (N×M queries total)
//   NEW: 1 query for ALL marks for the student at once
//        + paper counts come from the pre-built $paper_count_cache (0 DB calls)
//
// ALL calculation logic (markToGrade, calculateTotal, calculateTotalDisplay,
// always_one_paper, subject averaging, final_grade) is IDENTICAL to original.
// ══════════════════════════════════════════════════════════════════════════════
function getStudentData(
    $conn, $table_name, $student_id, $exam_sets, $exam_sets_info,
    $exam_columns, $subjects_info, $class, $stream,
    $teacher_cache, $use_custom_grading = false, $custom_grade = 100,
    $paper_count_cache = []
) {
    $subjects          = [];
    $use_direct_scoring = (count($exam_sets) == 1);
    $exam_ids = implode(',', array_map(
        fn($id) => "'" . mysqli_real_escape_string($conn, $id) . "'",
        $exam_sets
    ));
    $safe_student_id = mysqli_real_escape_string($conn, $student_id);

    // ── ONE QUERY: fetch all marks for this student across all exam types ──────
    // Previously we queried: (1) distinct subjects, then (2) marks per subject.
    // Now we get everything in a single round-trip and group in PHP.
    $sql_all = "SELECT subject, paper, mark, exam_type
                FROM `$table_name`
                WHERE student_id COLLATE utf8mb4_general_ci = '$safe_student_id'
                  AND exam_type  COLLATE utf8mb4_general_ci IN ($exam_ids)";
    $res_all = mysqli_query($conn, $sql_all);

    $marks_by_subject = [];
    if ($res_all) {
        while ($row = mysqli_fetch_assoc($res_all)) {
            $marks_by_subject[$row['subject']][] = $row;
        }
    }

    if (empty($marks_by_subject)) return [];

    // ── Process each subject found for this student ───────────────────────────
    foreach ($marks_by_subject as $subject => $mark_rows) {

        // Paper count from cache (no DB call) — falls back to 'I' if not found
        $expected_paper_data    = $paper_count_cache[strtolower(trim($subject))] ?? 'I';
        $expected_paper_numbers = getExpectedPaperNumbers($expected_paper_data);

        $subjects[$subject] = [
            'papers'           => [],
            'code'             => getSubjectCode($subject, $subjects_info, true),
            'name'             => $subject,
            'teacher_initials' => getTeacherInitials($teacher_cache, $class, $stream, $subject),
            'is_subsidiary'    => isSubsidiarySubject($subject),
            'has_marks'        => false,
        ];

        // Initialise paper slots
        foreach ($expected_paper_numbers as $paper_number) {
            $subjects[$subject]['papers'][$paper_number] = ['total' => '-', 'grade' => '-'];
            foreach ($exam_columns as $col) {
                $subjects[$subject]['papers'][$paper_number][$col]              = null;
                $subjects[$subject]['papers'][$paper_number][$col . '_display'] = '-';
            }
        }

        // Apply marks from the batched result
        foreach ($mark_rows as $row) {
            $normalized_paper = normalizePaperName($row['paper']);
            if (!in_array($normalized_paper, $expected_paper_numbers)) continue;

            $subjects[$subject]['has_marks'] = true;
            $exam_type      = $row['exam_type'];
            $exam_set_entry = $exam_sets_info[$exam_type] ?? null;
            if ($exam_set_entry === null) continue;
            $exam_column     = mapExamTypeToColumn($exam_set_entry['exam_set']);
            $exam_mark_scale = (int) ($exam_set_entry['exam_mark'] ?? 100);

            $subjects[$subject]['papers'][$normalized_paper][$exam_column] = $row['mark'];
            $display_mark = convertMarkToScale(
                $row['mark'], $exam_mark_scale,
                $use_direct_scoring && $use_custom_grading, $custom_grade
            );
            $subjects[$subject]['papers'][$normalized_paper][$exam_column . '_display'] = $display_mark;
        }

        // ── Totals & final grade — LOGIC IDENTICAL TO ORIGINAL ───────────────
        if ($subjects[$subject]['has_marks']) {
            $paper_totals = [];

            foreach ($subjects[$subject]['papers'] as $paper_number => &$paper_data) {
                $paper_data['total']         = calculateTotal($paper_data, $exam_columns, $use_custom_grading, $custom_grade);
                $paper_data['total_display'] = calculateTotalDisplay($paper_data, $exam_columns);
                if ($paper_data['total'] !== '-') {
                    $paper_totals[] = (float) $paper_data['total'];
                }
            }
            unset($paper_data);

            if (!empty($paper_totals)) {
                // Subjects that ALWAYS have one paper — use Paper I mark directly.
                // (Preserved exactly from original.)
                $always_one_paper = [
                    'general paper', 'gp', 'sub math', 'smath',
                    'subsidiary math', 'subsidiary mathematics'
                ];
                $subject_key = strtolower(trim($subject));

                if (in_array($subject_key, $always_one_paper)) {
                    $subject_avg = round($paper_totals[0]);
                } else {
                    $subject_avg = round(array_sum($paper_totals) / count($paper_totals));
                }

                $subjects[$subject]['final_mark']  = $subject_avg;
                $subjects[$subject]['final_grade'] = markToGrade($subject_avg);
            } else {
                $subjects[$subject]['final_mark']  = '-';
                $subjects[$subject]['final_grade'] = '-';
            }
        }
    }

    return array_filter($subjects, fn($data) => $data['has_marks']);
}

// ══════════════════════════════════════════════════════════════════════════════
// DISPLAY HELPER FUNCTIONS — ALL UNCHANGED
// ══════════════════════════════════════════════════════════════════════════════

function getRomanNumeral($number)
{
    $map = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
    return $map[$number - 1] ?? $number;
}

function getClassTeacherComment($grade, $student_name = "This student") {
    // Extract just the first name for a slightly more personal touch from the class teacher
    $name_parts = explode(' ', trim($student_name));
    $first_name = $name_parts[0] ?: "This student";

    $comments = [
        'A' => [
            "$first_name has delivered a truly outstanding performance this term. They show exceptional dedication to their studies and act as a genuine inspiration to their peers. Keep reaching for the very best.",
            "An exemplary term for $first_name. Their discipline and focus have paid off brilliantly across all subjects. I highly encourage them to maintain this high standard.",
            "I am incredibly proud of $first_name's dedication this term. They consistently go above and beyond expectations. Keep up the fantastic work!"
        ],
        'B' => [
            "A highly commendable effort this term. $first_name demonstrates a strong understanding of the curriculum and a positive attitude. Excellence is well within their reach.",
            "$first_name is making great progress and working diligently. With continued focus and targeted revision, they can definitely achieve top marks next term.",
            "A very solid performance. $first_name is a focused learner who participates well. A little extra push in their revision will bridge the gap to exceptional results."
        ],
        'C' => [
            "A satisfactory performance this term. $first_name has shown fair effort, but I encourage them to push a little harder. The potential is clearly there—consistent study will make the difference.",
            "$first_name is grasping the basic concepts well, but needs to aim for deeper understanding. I encourage more active participation in class and consistent evening revision.",
            "A fair term overall. For $first_name to unlock their full potential, they will need to commit more deeply to independent study and ask questions when challenged."
        ],
        'D' => [
            "This term has been a challenging one, but I believe strongly in $first_name's ability to improve. With greater commitment, better study habits, and early engagement with teachers, better results are achievable.",
            "$first_name's performance is currently below their true potential. We need to see a stronger commitment to academics next term. Please do not hesitate to ask subject teachers for extra help.",
            "A basic performance this term. $first_name needs to dedicate significantly more time to their books. I encourage them to form study groups and utilize the library more effectively."
        ],
        'E' => [
            "This has been a very difficult term for $first_name, and they need our collective support. I urge them not to lose heart, to seek help from teachers immediately, and to come back next term with renewed determination.",
            "Academic results this term are a serious concern. $first_name requires urgent academic intervention and closer monitoring. Let us work together to help them regain their footing.",
            "$first_name has struggled to meet the core competencies this term. I encourage them to reflect on their study habits and ask for help. We are fully committed to supporting their academic recovery."
        ]
    ];

    $grade_key = strtoupper(trim($grade));
    if (!array_key_exists($grade_key, $comments)) {
        $grade_key = 'E'; // Default to E if grade is missing or invalid
    }

    $selected_comments = $comments[$grade_key];
    return $selected_comments[array_rand($selected_comments)];
}

function getHeadTeacherComment($grade, $student_name = "This student") {
    $comments = [
        'A' => [
            "Exemplary results. This school takes great pride in $student_name's achievements. You have demonstrated what hard work and focus can accomplish.",
            "An outstanding display of academic excellence. $student_name continues to set the standard for others to follow. We are immensely proud.",
            "Exceptional work this term. $student_name is a testament to the values of our institution. Keep aiming for the stars."
        ],
        'B' => [
            "A very pleasing performance this term. $student_name is clearly working hard and making excellent progress. We encourage them to aim for the very top.",
            "Commendable academic discipline. We recognize and appreciate $student_name's efforts this term. Keep up the momentum.",
            "A strong and consistent performance. $student_name has shown great character and focus. We expect even greater achievements next term."
        ],
        'C' => [
            "An acceptable performance, though we know $student_name is capable of much more. We urge parents and guardians to offer closer academic support at home.",
            "Satisfactory results this term. We encourage $student_name to elevate their focus and maximize the resources available at school.",
            "$student_name has met the basic requirements, but we expect a higher level of dedication moving forward. Let us partner to push for better grades."
        ],
        'D' => [
            "Performance this term has fallen below the expected standard. We call upon $student_name and their family to work closely with our staff to address these academic challenges.",
            "These results do not reflect the standard we expect. $student_name must urgently re-evaluate their approach to their studies next term.",
            "A challenging term. We advise $student_name to utilize all available remedial programs and urge parents to closely monitor their academic progress at home."
        ],
        'E' => [
            "This has been a very difficult term academically. The school is fully committed to supporting $student_name's recovery, but we earnestly appeal to the family for strong partnership.",
            "These results are of great concern to the administration. We request an urgent collaborative effort between the school and $student_name's guardians to forge a path forward.",
            "$student_name requires immediate academic intervention. We ask the family to partner with us closely next term to provide the encouragement and strict supervision needed to succeed."
        ]
    ];

    $grade_key = strtoupper(trim($grade));
    if (!array_key_exists($grade_key, $comments)) {
        $grade_key = 'E';
    }

    $selected_comments = $comments[$grade_key];
    return $selected_comments[array_rand($selected_comments)];
}

function getSubjectComment($grade, $is_subsidiary = false)
{
    switch ($grade) {
        case 'A': return 'Exceptional';
        case 'B': return 'Outstanding';
        case 'C': return 'Satisfactory';
        case 'D': return 'Basic';
        case 'E': return $is_subsidiary ? 'No Competence' : 'Elementary';
        default:  return 'No Marks';
    }
}

function displayFinalGrade($grade, $is_subsidiary = false)
{
    return ($grade !== '' && $grade !== null) ? $grade : '-';
}

function getNextClassName($currentClassName)
{
    if (stripos($currentClassName, 'Senior Five') !== false) {
        return str_ireplace('Senior Five', 'Senior Six', $currentClassName);
    }
    return null;
}

function convertTermToWord($termNumber)
{
    switch ($termNumber) {
        case 1:  return 'Term One';
        case 2:  return 'Term Two';
        case 3:  return 'Term Three';
        default: return 'Term One';
    }
}

function getFee($conn, $className, $termName, $year)
{
    if ($className === null) return 'N/A (Graduating)';
    $sql  = 'SELECT amount FROM fee_structures WHERE class_name = ? AND term = ? AND year = ? LIMIT 1';
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ssi', $className, $termName, $year);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $amount);
        if (mysqli_stmt_fetch($stmt)) {
            mysqli_stmt_close($stmt);
            return number_format($amount) . '/=';
        }
        mysqli_stmt_close($stmt);
    }
    return 'Not Set';
}

// ── NEXT TERM INFO (only for EOT reports) ────────────────────────────────────
$fees_amount    = '';
$term_end_date  = '';
$next_term_start = '';

if ($isEndOfTermReport) {
    $currentTermNumber = (int) preg_replace('/[^0-9]/', '', $term);
    if ($currentTermNumber == 1 || $currentTermNumber == 2) {
        $targetClassName  = $class;
        $targetTermNumber = $currentTermNumber + 1;
        $targetYear       = $year;
    } else {
        $targetClassName  = getNextClassName($class);
        $targetTermNumber = 1;
        $targetYear       = $year + 1;
    }
    $targetTermWord  = convertTermToWord($targetTermNumber);
    $fees_amount     = getFee($conn, $targetClassName, $targetTermWord, $targetYear);
    $term_end_date   = !empty($school_info['next_term_ends'])
        ? date('jS F Y', strtotime($school_info['next_term_ends'])) : 'Not Set';
    $next_term_start = !empty($school_info['next_term_date'])
        ? date('jS F Y', strtotime($school_info['next_term_date'])) : 'Not Set';
}

// ── EXAM TITLE & FILENAME ─────────────────────────────────────────────────────
$exam_names  = array_column($exam_sets_info, 'exam_set');
$exam_title  = implode(' & ', $exam_names);
$filename_parts = [$class];
if (count($streams) === 1) $filename_parts[] = $streams[0];
$filename_parts  = array_merge($filename_parts, [$exam_title, $term, $year, 'Reports']);
$report_filename = implode(' - ', $filename_parts);

// ── STUDENT QUERY ─────────────────────────────────────────────────────────────
if ($is_verified_access && isset($_GET['student_id'])) {
    $verified_student_id = mysqli_real_escape_string($conn, $_GET['student_id']);
    $students_sql = "SELECT student_id, first_name, last_name, stream, profile_photo, subject_combination
                     FROM students
                     WHERE student_id COLLATE utf8mb4_general_ci = '$verified_student_id'";
} else {
    $streams_str  = "'" . implode("','", array_map(fn($s) => mysqli_real_escape_string($conn, $s), $streams)) . "'";
    $exam_ids_str = implode(',', array_map(fn($id) => "'" . mysqli_real_escape_string($conn, $id) . "'", $exam_sets));

    $students_sql = "SELECT DISTINCT s.student_id, s.first_name, s.last_name,
                            m.stream, s.profile_photo, s.subject_combination
                     FROM students s
                     INNER JOIN `$table_name` m
                         ON s.student_id COLLATE utf8mb4_general_ci = m.student_id COLLATE utf8mb4_general_ci
                     WHERE m.class     COLLATE utf8mb4_general_ci = '" . mysqli_real_escape_string($conn, $class) . "'
                       AND m.stream    COLLATE utf8mb4_general_ci IN ($streams_str)
                       AND m.exam_type COLLATE utf8mb4_general_ci IN ($exam_ids_str)
                     ORDER BY s.last_name, s.first_name";
}
$students_result = mysqli_query($conn, $students_sql);

// ── BASE PATH (used in school logo URLs) ─────────────────────────────────────
$base_path   = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
$logo_path   = $school_info['logo_path'] ?? '';
$logo_url    = $base_path . '/' . ltrim($logo_path, '/');
$org_logo_url = $base_path . '/images/org_logo.png';

// ═════════════════════════════════════════════════════════════════════════════
// HTML OUTPUT — streamed to browser one report card at a time
// ob_flush() after each card means the browser renders progressively instead
// of waiting for all 500 students to be computed.
// ═════════════════════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($report_filename); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/fonts/fontawesome-all.min.css">
    <style>
        :root {
            --primary:      #58BB43;
            --primary-light:#e8f5ee;
            --accent:       #b8972a;
            --accent-light: #fdf8ec;
            --border:       #58BB43;
            --score-bg:     #e8f5e9;
            --points-bg:    #fff3e0;
            --comment-bg:   #f3e5f5;
            --header-stripe:#58BB43;
            --danger-red:   #f50905;
        }
        *{padding:0;margin:0;box-sizing:border-box;font-family:"Sen",sans-serif}
body{background-color:#cde8d8;padding:10px;font-size:17px;line-height:1.4}

        /* ── LOADING OVERLAY ── */
        #gen-overlay{
            position:fixed;inset:0;z-index:9999;
            background:rgba(26,71,49,.88);
            display:flex;flex-direction:column;
            align-items:center;justify-content:center;gap:18px;
            backdrop-filter:blur(3px);
        }
        #gen-overlay .ov-spinner{
            width:56px;height:56px;
            border:5px solid rgba(255,255,255,.25);
            border-top-color:#fff;
            border-radius:50%;
            animation:ov-spin .8s linear infinite;
        }
        @keyframes ov-spin{to{transform:rotate(360deg)}}
        #gen-overlay p{color:#fff;font-size:15px;font-weight:700;letter-spacing:.4px;margin:0}
        #gen-overlay small{color:rgba(255,255,255,.7);font-size:12px}

        /* ── OUTER DECORATIVE BORDER ── */
        .report-card{
            background-color:white;max-width:1350px;margin:15px auto;
            padding:18px 22px;box-shadow:0 4px 18px rgba(0,0,0,.18);
            page-break-after:always;position:relative;
            min-height:calc(100vh - 30px);
            display:flex;flex-direction:column;
            border:5px double var(--primary);overflow:hidden;
        }
           .report-card::before{
    content:'';position:absolute;top:0;left:0;
    width:100%;height:100%;
    background-image:var(--watermark-url);
    background-size:650px 650px;background-repeat:no-repeat;
    background-position:center;opacity:.15;
    pointer-events:none;z-index:0;
}
        .report-content{
            flex:1;position:relative;z-index:1;
            display:flex;flex-direction:column;justify-content:space-between;
        }
        .school-header{border-bottom:4px solid var(--danger-red);padding-bottom:10px;margin-bottom:12px}
        .school-name{font-weight:800;font-size:32px;text-transform:uppercase;letter-spacing:1px;color:#222;margin:4px 0 2px}
        .main-school-name{font-size:30px;font-weight:900;margin:0;padding:0;color:#333;text-transform:uppercase;letter-spacing:2px}
        .school-location{font-size:17px;font-weight:700;color:#333;text-transform:uppercase;margin-top:-5px;letter-spacing:1px}
        .school-motto{font-size:14px;font-style:italic;color:#666;margin-top:5px}
        .school-logo{max-height:200px;width:auto}
        .school-address{font-size:14px;color:#444;line-height:1.5}
        .contact-badge{display:inline-block;color:black;border-radius:3px;padding:1px 4px;font-size:12px;font-weight:700;letter-spacing:.5px;margin-right:3px}
        .report-title{text-align:center;font-weight:800;font-style:italic;margin:8px 0 0;font-size:16px;text-decoration:underline;color:var(--primary);text-transform:uppercase;letter-spacing:1px}
        .student-info{background:var(--primary);color:white;border-radius:4px;margin:4px 0;padding:8px 14px;display:flex;justify-content:space-between;align-items:center;font-size:14px;font-weight:600;flex-wrap:wrap;gap:4px}
        .student-info strong{color:#00000093;font-weight:800}
        .student-photo{max-width:90px;max-height:110px;border-radius:3px}
        .grades-table{width:100%;border-collapse:collapse;margin-bottom:8px;font-size:13px;font-weight:500;border:1px solid var(--primary)}
        .grades-table th,.grades-table td{border:1px solid #b0bec5;padding:10px 9px;text-align:center;vertical-align:middle;line-height:1.2}
        .grades-table thead tr{background:var(--primary);color:white}
        .grades-table th{font-weight:700;font-size:12px;text-transform:uppercase;color:white}
        .grades-table tbody tr{background-color:#fff3e0}
        .subject-code{text-align:center;font-weight:bold;font-size:13px;background-color:var(--primary-light);color:var(--primary)}
        .subject-name{text-align:left;font-size:13px;font-weight:600;background-color:var(--primary-light);color:#222;padding-left:8px !important}
        .grades-table td.cell-score{background:var(--score-bg);font-weight:700;color:#58BB43}
        .grades-table td.cell-points{background:#fce4ec;font-weight:800;color:#c62828;font-size:14px}
        .grades-table td.cell-grade{background:var(--accent-light);font-weight:800;color:var(--primary)}
        .grades-table td.cell-comment{background:var(--comment-bg);font-size:13px;color:#4a148c}
        /* Subject type column */
        .cell-type{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
        .principal-type{background:#e8f5e9;color:#2e7d32}
        .subsidiary-type{background:#fff8e1;color:#e65100}
        /* Points badges in summary row */
        .points-badge{display:inline-block;background:var(--danger-red);color:#fff;font-weight:800;font-size:15px;border-radius:4px;padding:2px 10px;letter-spacing:.5px}
        .points-badge-sm{display:inline-block;background:var(--accent);color:#fff;font-weight:700;font-size:13px;border-radius:3px;padding:1px 7px}
        .summary-table,.comments-table,.grading-table{width:100%;border-collapse:collapse;margin-bottom:8px;font-size:14px;font-weight:500;border:1px solid #f50905}
        .grading-table{border:1px solid #fce4ec}
        .grading-table th{background:#fce4ec;color:#c62828;font-weight:700;font-size:13px;padding:3px 3px;text-align:center}
.grading-table thead th{background:#fce4ec !important;color:#c62828 !important;font-weight:700;padding:3px 3px;text-align:center}
        .section-gap{height:6px}
        .summary-table th,.summary-table td,.comments-table th,.comments-table td,.grading-table th,.grading-table td{border:1px solid #b0bec5;padding:6px 8px;vertical-align:middle}
        .summary-table td{background:var(--primary-light);font-weight:700;color:var(--primary);text-align:center}
        .comments-table th{width:28%;background:var(--primary);color:white;text-align:left;font-weight:700;font-size:13px}
        .comments-table td{background:#fffde7;font-size:14px}
        .comments-table td:last-child{height:50px;vertical-align:bottom;text-align:center;font-weight:bold;background:var(--primary-light)}
        .grading-table th,.grading-table td{background:var(--primary);color:white;font-weight:700;font-size:13px;padding:3px 3px;text-align:center}
        .grading-table td{background:var(--accent-light);color:#333;font-weight:600;border:1px solid #b0bec5}
        .grading-table tbody tr th{background:#fce4ec;color:#c62828;text-align:left;padding-left:10px}
        .qrcode-container{padding:6px}
        .qrcode-wrapper{display:flex;justify-content:flex-end;margin:10px 0 4px}
        .report-footer-note{text-align:center;font-size:12px;font-style:italic;color:black;margin-top:auto;font-weight:600;padding:5px 10px;border-radius:3px;letter-spacing:.3px}
        .print-buttons{text-align:center;margin:15px 0}
        /* ══════════════ PRINT STYLES ══════════════ */
        @media print {
            body{background:white;margin:0;padding:0}
            .print-buttons,#gen-overlay{display:none !important}
            .report-card{width:100% !important;max-width:none !important;height:auto !important;min-height:100vh !important;padding:.3cm .4cm !important;margin:0 !important;box-shadow:none !important;border:2px double var(--primary) !important;page-break-after:always !important;overflow:visible !important;zoom:var(--print-zoom, 1) !important}
            .report-content{justify-content:space-evenly !important}
            .school-header{padding-bottom:6px !important;margin-bottom:6px !important}
            .student-info{margin:2px 0 !important;font-size:12px !important;margin:6px 0 !important;padding:5px 10px !important}
            .report-title{font-size:14px !important;margin:4px 0 0 !important}
            .grades-table{font-size:11px !important;margin-bottom:10px !important;flex-grow:0 !important}
            .grades-table th{font-size:10px !important;padding:4px 3px !important;line-height:1.15 !important}
            .grades-table td{font-size:11px !important;padding:4px 3px !important;line-height:1.15 !important}
            .subject-code,.subject-name{font-size:11px !important}
            .section-gap{height:4px !important}
            .summary-table,.comments-table,.grading-table{font-size:11px !important;margin-bottom:14px !important}
            .grading-table{margin-bottom:8px !important}
            .summary-table th,.summary-table td,.comments-table th,.comments-table td,.grading-table th,.grading-table td{padding:5px 5px !important;font-size:11px !important}
            .comments-table td:last-child{height:45px !important}
           .grading-table th,.grading-table td{padding:2px 3px !important;font-size:10px !important}
            .qrcode-wrapper{margin:6px 0 3px !important}
            .qrcode-container canvas,.qrcode-container img{display:block !important;width:80px !important;height:80px !important}
            .qrcode-container>*:not(:first-child){display:none !important}
            .report-footer-note{font-size:10px !important;padding:3px 8px !important}
            .student-photo{max-width:70px !important;max-height:85px !important}
            .school-logo{max-height:115px !important;width:auto !important}
            .school-name{font-size:24px !important}
            .school-address{font-size:11px !important}
            .points-badge{font-size:11px !important;padding:1px 6px !important}
            .points-badge-sm{font-size:10px !important;padding:1px 4px !important}
            -webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact;
            @page{size:A4;margin:.25cm}
            .school-header,.student-info,.grades-table tr,.summary-table,.comments-table,.grading-table,.qrcode-wrapper,.report-footer-note{page-break-inside:avoid !important}

            /* ── COMPACT MODE — auto-applied when a student has 9-11 total paper rows ── */
            .compact-print .school-name{font-size:20px !important}
            .compact-print .school-logo{max-height:90px !important}
            .compact-print .student-photo{max-width:55px !important;max-height:70px !important}
            .compact-print .school-header{padding-bottom:4px !important;margin-bottom:4px !important}
            .compact-print .report-title{font-size:12px !important;margin:2px 0 0 !important}
            .compact-print .student-info{padding:4px 8px !important;font-size:11px !important;margin:4px 0 !important}
            .compact-print .grades-table th{padding:3px 2px !important;font-size:9px !important}
            .compact-print .grades-table td{padding:3px 2px !important;font-size:10px !important;line-height:1.05 !important}
            .compact-print .subject-code,.compact-print .subject-name{font-size:10px !important}
            .compact-print .section-gap{height:2px !important}
            .compact-print .grades-table,.compact-print .summary-table,.compact-print .comments-table,.compact-print .grading-table{margin-bottom:6px !important}
            .compact-print .summary-table th,.compact-print .summary-table td,.compact-print .comments-table th,.compact-print .comments-table td,.compact-print .grading-table th,.compact-print .grading-table td{padding:3px 4px !important;font-size:9px !important}
            .compact-print .comments-table td:last-child{height:30px !important}
            .compact-print .report-footer-note{font-size:8px !important;padding:2px 6px !important}
            .compact-print .qrcode-container canvas,.compact-print .qrcode-container img{width:60px !important;height:60px !important}
            .compact-print .qrcode-wrapper{margin:3px 0 2px !important}

            /* ── EXTRA-COMPACT MODE — auto-applied when a student has 12+ total paper rows ── */
            .compact-print-lg .school-name{font-size:18px !important}
            .compact-print-lg .school-logo{max-height:75px !important}
            .compact-print-lg .student-photo{max-width:45px !important;max-height:60px !important}
            .compact-print-lg .school-header{padding-bottom:3px !important;margin-bottom:3px !important}
            .compact-print-lg .report-title{font-size:11px !important;margin:2px 0 0 !important}
            .compact-print-lg .student-info{padding:3px 6px !important;font-size:10px !important;margin:3px 0 !important}
            .compact-print-lg .grades-table th{padding:2px 1px !important;font-size:8px !important}
            .compact-print-lg .grades-table td{padding:2px 1px !important;font-size:9px !important;line-height:1 !important}
            .compact-print-lg .subject-code,.compact-print-lg .subject-name{font-size:9px !important}
            .compact-print-lg .section-gap{height:1px !important}
            .compact-print-lg .grades-table,.compact-print-lg .summary-table,.compact-print-lg .comments-table,.compact-print-lg .grading-table{margin-bottom:4px !important}
            .compact-print-lg .summary-table th,.compact-print-lg .summary-table td,.compact-print-lg .comments-table th,.compact-print-lg .comments-table td,.compact-print-lg .grading-table th,.compact-print-lg .grading-table td{padding:2px 3px !important;font-size:8px !important}
            .compact-print-lg .comments-table td:last-child{height:24px !important}
            .compact-print-lg .report-footer-note{font-size:7px !important;padding:1px 4px !important}
            .compact-print-lg .qrcode-container canvas,.compact-print-lg .qrcode-container img{width:50px !important;height:50px !important}
            .compact-print-lg .qrcode-wrapper{margin:2px 0 1px !important}
    </style>
</head>
<body>

    <!-- LOADING OVERLAY — hidden once all report cards are in the DOM -->
    <div id="gen-overlay">
        <div class="ov-spinner"></div>
        <p>Building Report Cards&hellip;</p>
        <small>Please wait — large batches may take a moment</small>
    </div>

    <div class="print-buttons">
        <button class="btn btn-primary btn-print" onclick="window.print()">
            <i class="fas fa-print"></i> Print All Reports
        </button>
        <a href="sel_gen_reports.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

<?php
// Flush the HTML head + overlay to the browser immediately so the user
// sees the loading state rather than a blank screen.
ob_flush(); flush();

// ── STREAMING STUDENT RENDER LOOP ─────────────────────────────────────────────
// Instead of collecting all students into a $students[] array and rendering
// at the end, we compute and echo each report card immediately then flush.
// This keeps memory flat regardless of student count.

$student_count = 0;

if ($students_result) {
    while ($raw = mysqli_fetch_assoc($students_result)) {

        $subjects = getStudentData(
            $conn, $table_name, $raw['student_id'],
            $exam_sets, $exam_sets_info, $exam_columns,
            $subjects_info, $class, $raw['stream'],
            $teacher_initials_cache, $use_custom_grading, $custom_grade,
            $paper_count_cache           // ← pre-built cache, no extra DB calls
        );

        if (empty($subjects)) continue; // Skip students with no marks
        
        
       // ── Count total paper rows to decide print scaling ────────────────────
        $total_paper_rows = 0;
        foreach ($subjects as $data) { $total_paper_rows += count($data['papers']); }
        $card_print_class = 'report-card';

        // Continuous zoom instead of fixed tiers — scales down proportionally
        // to how many paper rows this specific student has, so nobody sits
        // right on a threshold boundary and spills onto a second page.
        $print_zoom = 1.0;
        if ($total_paper_rows > 6) {
            $print_zoom = max(0.68, 1 - (($total_paper_rows - 6) * 0.035));
        }
        $print_zoom = round($print_zoom, 3);
        // ── Aggregate calculations — IDENTICAL TO ORIGINAL LOGIC ─────────────
        $subject_final_marks  = [];
        $subject_final_grades = [];
        $total_points      = 0;
        $principal_points  = 0;
        $subsidiary_points = 0;

        foreach ($subjects as $data) {
            if (isset($data['final_mark'])  && $data['final_mark']  !== '-') $subject_final_marks[]  = (float) $data['final_mark'];
            if (isset($data['final_grade']) && $data['final_grade'] !== '-') {
                $subject_final_grades[] = $data['final_grade'];
                $is_sub = $data['is_subsidiary'];
                $pts    = calculatePoints($data['final_grade'], $is_sub);
                if ($is_sub) { $subsidiary_points += $pts; } else { $principal_points += $pts; }
                $total_points += $pts;
            }
        }

        $overall_avg_mark  = !empty($subject_final_marks)
            ? round(array_sum($subject_final_marks) / count($subject_final_marks)) : 0;
        $overall_avg_grade = markToGrade($overall_avg_mark);

        $dominant_grade = 'E';
        if (!empty($subject_final_grades)) {
            $grade_counts = array_count_values($subject_final_grades);
            $grade_order  = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5];
            uksort($grade_counts, function ($a, $b) use ($grade_counts, $grade_order) {
                if ($grade_counts[$a] !== $grade_counts[$b]) return $grade_counts[$b] - $grade_counts[$a];
                return ($grade_order[$a] ?? 9) - ($grade_order[$b] ?? 9);
            });
            $dominant_grade = array_key_first($grade_counts);
        }

        $full_name        = strtoupper($raw['last_name'] . ' ' . $raw['first_name']);
        $student_id_safe  = htmlspecialchars($raw['student_id']);
        $student_count++;

        // ── Output the report card HTML ───────────────────────────────────────
?>
<div class="<?php echo $card_print_class; ?>" style="--watermark-url: url('<?php echo !empty($logo_path) ? htmlspecialchars($base_path . '/' . ltrim($logo_path, '/')) : ''; ?>'); --print-zoom: <?php echo $print_zoom; ?>;">
        <div class="report-content">

            <!-- School Header -->
            <div class="school-header">
                <div style="display:flex;justify-content:space-between;align-items:center;width:100%">
                    <div style="flex:0 0 150px;text-align:left">
                        <?php if (!empty($logo_path)): ?>
                            <img src="<?php echo htmlspecialchars($logo_url); ?>"
                                 alt="School Logo"
                                 style="max-width:100%;height:auto;max-height:130px"
                                 onerror="this.style.display='none'">
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;text-align:center;padding:0 15px">
                        <h1 class="main-school-name">ONWARDS AND UPWARDS</h1>
                        <div class="school-location">SECONDARY SCHOOL - BULOBA</div>
                        <p class="school-motto">"<?php echo htmlspecialchars($school_info['school_motto'] ?? 'Celebrating Achievements'); ?>"</p>
                        <div class="school-address">
                            <p class="school-contact">P.O. BOX <?php echo htmlspecialchars($school_info['pobox'] ?? '1234'); ?><br>
                                <span class="contact-badge">TEL:</span><?php echo !empty($school_info['phone']) ? $school_info['phone'] : '07XX XXX XXX'; ?> &nbsp;&nbsp;
                                <span class="contact-badge">EMAIL:</span><?php echo !empty($school_info['email']) ? $school_info['email'] : 'yourschool@email.com'; ?>
                                <?php if (!empty($school_info['website'])): ?>
                                    &nbsp;&nbsp;<span class="contact-badge">WEB:</span><?php echo $school_info['website']; ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="report-title"><?php echo $exam_title; ?> '<?php echo $level; ?>' REPORT</div>
                    </div>
                    <div style="flex:0 0 100px;text-align:right">
                        <?php if (!empty($raw['profile_photo'])): ?>
                            <img src="<?php echo htmlspecialchars($raw['profile_photo']); ?>"
                                 alt="Student Photo" class="student-photo"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                            <div class="student-photo" style="display:none;align-items:center;justify-content:center;height:70px;width:70px;background:#f5f5f5;margin-left:auto">
                                <i class="fas fa-user fa-2x text-muted"></i>
                            </div>
                        <?php else: ?>
                            <div class="student-photo" style="display:flex;align-items:center;justify-content:center;height:70px;width:70px;background:#f5f5f5;margin-left:auto">
                                <i class="fas fa-user fa-2x text-muted"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Org logo + subject combination strip -->
            <div style="display:flex;align-items:center;justify-content:space-between;padding:2px;margin-bottom:0">
                <img src="<?php echo htmlspecialchars($org_logo_url); ?>"
                     alt="Organization Logo"
                     style="max-height:24px;width:auto"
                     onerror="this.style.display='none'">
                <span style="font-size:13px;font-weight:700;color:var(--primary)">
                    <span style="color:#555;font-weight:600">Combination:</span>
                    <?php echo htmlspecialchars($raw['subject_combination'] ?? '-'); ?>
                </span>
            </div>

            <!-- Student Information Bar -->
            <div class="student-info">
                <div><strong>Name:</strong> <?php echo htmlspecialchars($full_name); ?></div>
                <div><strong>Student ID:</strong> <?php echo $student_id_safe; ?></div>
                <div><strong>Class:</strong> <?php echo htmlspecialchars($class); ?> <?php echo htmlspecialchars($raw['stream']); ?></div>
                <div><strong>Term:</strong> <?php echo getRomanNumeral((int)$term_num); ?></div>
                <div><strong>Year:</strong> <?php echo htmlspecialchars($year); ?></div>
            </div>

            <!-- Grades Table -->
            <table class="grades-table table-striped">
                <thead>
                    <tr>
                        <th colspan="2">SUBJECT</th>
                        <th>TYPE</th>
                        <th>PAPER</th>
                        <?php foreach ($exam_columns as $column): ?>
                            <th>
                                <?php
                                $exam_mark = 100;
                                foreach ($exam_sets_info as $info) {
                                    if (mapExamTypeToColumn($info['exam_set']) === $column) {
                                        $exam_mark = (int) $info['exam_mark'];
                                        break;
                                    }
                                }
                                echo $column . '<br>(' . (count($exam_sets) == 1 ? ($use_custom_grading ? $custom_grade : '100%') : $exam_mark) . ')';
                                ?>
                            </th>
                        <?php endforeach; ?>
                        <th>FINAL<br>AVERAGE</th>
                        <th>GRADE</th>
                        <th>POINTS</th>
                        <th>ACHIEVEMENT<br>LEVEL</th>
                        <th>INIT.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subjects as $subject => $data):
                        $rowspan    = count($data['papers']);
                        $first_row  = true;
                        $is_sub     = $data['is_subsidiary'];
                        $subject_total_display = (isset($data['final_mark']) && $data['final_mark'] !== '-' && $data['final_mark'] !== null)
                            ? $data['final_mark'] : '-';
                        $subj_pts   = ($data['final_grade'] !== '-' && $data['final_grade'] !== null)
                            ? calculatePoints($data['final_grade'], $is_sub) : '-';
                        $subj_pts_label = ($subj_pts !== '-')
                            ? $subj_pts . '/' . ($is_sub ? '1' : '5') : '-';
                        foreach ($data['papers'] as $paper => $paper_data): ?>
                        <tr>
                            <?php if ($first_row): ?>
                                <td rowspan="<?php echo $rowspan; ?>" class="subject-code"><?php echo htmlspecialchars($data['code']); ?></td>
                                <td rowspan="<?php echo $rowspan; ?>" class="subject-name"><?php echo htmlspecialchars($data['name']); ?></td>
                                <td rowspan="<?php echo $rowspan; ?>" class="cell-type <?php echo $is_sub ? 'subsidiary-type' : 'principal-type'; ?>">
                                    <?php echo $is_sub ? 'Subsidiary' : 'Principal'; ?>
                                </td>
                            <?php endif; ?>
                            <td><?php echo htmlspecialchars($paper); ?></td>
                            <?php foreach ($exam_columns as $column): ?>
                                <td><?php echo $paper_data[$column . '_display'] ?? '-'; ?></td>
                            <?php endforeach; ?>
                            <?php if ($first_row): ?>
                                <td rowspan="<?php echo $rowspan; ?>" class="cell-score"><?php echo $subject_total_display; ?></td>
                                <td rowspan="<?php echo $rowspan; ?>" class="cell-grade"><?php echo displayFinalGrade($data['final_grade']); ?></td>
                                <td rowspan="<?php echo $rowspan; ?>" class="cell-points"><?php echo $subj_pts_label; ?></td>
                                <td rowspan="<?php echo $rowspan; ?>" class="cell-comment"><?php echo getSubjectComment($data['final_grade'], $is_sub); ?></td>
                                <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($data['teacher_initials']); ?></td>
                            <?php endif; ?>
                        </tr>
                        <?php $first_row = false; endforeach; endforeach; ?>
                </tbody>
            </table>

            <!-- Summary -->
            <table class="summary-table">
                <tr>
                    <td><strong>Overall Average:</strong> <?php echo $overall_avg_mark; ?>% &nbsp;|&nbsp; Grade <?php echo $overall_avg_grade; ?></td>
                    <td>
                        <strong>Total Points:</strong>
                        <span class="points-badge"><?php echo $total_points; ?> / 17</span>
                    </td>
                    <td>
                        <strong>Principal:</strong> <span class="points-badge-sm"><?php echo $principal_points; ?>/15</span>
                        &nbsp;
                        <strong>Subsidiary:</strong> <span class="points-badge-sm"><?php echo $subsidiary_points; ?>/2</span>
                    </td>
                    <td><strong>Subjects:</strong> <?php echo count($subjects); ?></td>
                </tr>
            </table>

            <!-- Comments -->
                        <table class="comments-table">
                <tr>
                    <th>Class Teacher's Comment:</th>
                    <td><?php echo getClassTeacherComment($dominant_grade, $full_name); ?></td>
                    <td align="center"><strong>Signature</strong></td>
                </tr>
                <tr>
                    <th>Head Teacher's Comment:</th>
                    <td><?php echo getHeadTeacherComment($dominant_grade, $full_name); ?></td>
                    <td align="center"><strong>Signature</strong></td>
                </tr>
            </table>

            <!-- Next Term Info (EOT only) -->
            <?php if ($isEndOfTermReport): ?>
            <table class="summary-table">
                <tr>
                    <td><strong>Next Term Fees:</strong> <?php echo $fees_amount; ?></td>
                    <td><strong>Next Term Begins:</strong> <?php echo $next_term_start; ?></td>
                    <td><strong>Term Ends:</strong> <?php echo $term_end_date; ?></td>
                </tr>
            </table>
            <?php endif; ?>

            <!-- Grading Scale — NCDC CBC dual system -->
            <table class="grading-table">
                <thead>
                    <tr>
                        <th colspan="6" style="text-align:left;padding-left:10px;font-size:12px;letter-spacing:.5px;">
                            GRADING KEY &mdash; NEW A-LEVEL CBC SYSTEM (Uganda NCDC)
                        </th>
                    </tr>
                    <tr>
                        <th>GRADE</th><th>A</th><th>B</th><th>C</th><th>D</th><th>E</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th>PRINCIPAL &mdash; Score Range</th>
                        <td>80 – 100</td><td>70 – 79</td><td>60 – 69</td><td>50 – 59</td><td>0 – 49</td>
                    </tr>
                    <tr>
                        <th>PRINCIPAL &mdash; Points</th>
                        <td><strong>5</strong></td><td><strong>4</strong></td><td><strong>3</strong></td><td><strong>2</strong></td><td><strong>1</strong></td>
                    </tr>
                    <tr>
                        <th>SUBSIDIARY &mdash; Score Range</th>
                        <td>80 – 100</td><td>70 – 79</td><td>60 – 69</td><td>50 – 59</td><td style="color:#c62828;font-weight:700;">0 – 49</td>
                    </tr>
                    <tr>
                        <th>SUBSIDIARY &mdash; Points</th>
                        <td><strong>1</strong></td><td><strong>1</strong></td><td><strong>1</strong></td><td><strong>1</strong></td><td style="color:#c62828;font-weight:700;"><strong>0</strong></td>
                    </tr>
                    <tr>
                        <th>ACHIEVEMENT LEVEL</th>
                        <td>EXCEPTIONAL</td><td>OUTSTANDING</td><td>SATISFACTORY</td><td>BASIC</td>
                        <td>ELEMENTARY<br><small style="color:#c62828;font-weight:700;"></small></td>
                    </tr>
                </tbody>
            </table>
           <div style="font-size:10px;font-weight:bold;color:#555;margin-top:1px;margin-bottom:2px;font-style:italic;">
                Max: 3 Principal subjects (max 15 pts) + 2 Subsidiary subjects (max 2 pts) = <strong>17 pts total</strong>.
                Subsidiary E (0–49) = 0 pts (No Competence). Subsidiary D and above = 1 pt each.
            </div>

            <!-- QR Code — generated lazily by IntersectionObserver in JS below -->
            <div class="qrcode-wrapper">
                <div class="qrcode-container"
                     id="qrcode-<?php echo $student_id_safe; ?>"
                     data-student-id="<?php echo $student_id_safe; ?>"
                     data-year="<?php echo htmlspecialchars($year); ?>"
                     data-term="<?php echo htmlspecialchars($term); ?>"
                     data-class="<?php echo htmlspecialchars($class); ?>"
                     data-stream="<?php echo htmlspecialchars($raw['stream']); ?>">
                </div>
            </div>

        </div><!-- /report-content -->
        <div class="report-footer-note">Report card is invalid without an original school stamp</div>
    </div><!-- /report-card -->

<?php
        // Flush this report card to the browser immediately.
        // The user sees cards appear progressively rather than waiting for all 500.
        ob_flush(); flush();

    } // end while students
} // end if students_result

if ($student_count === 0): ?>
    <div class="alert alert-warning" style="max-width:700px;margin:40px auto">
        No students found with marks in the selected exam sets.
    </div>
<?php endif; ?>

    <script src="../assets/js/jquery.min.js"></script>
    <script src="../assets/bootstrap/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script>
    (function () {
        'use strict';

        var examSets = <?php echo json_encode($exam_sets); ?>;

        // ── Helper: build the verification URL for one container ─────────────
        function buildUrl(el) {
            var protocol   = window.location.protocol;
            var host       = window.location.host;
            var pathParts  = window.location.pathname.split('/');
            pathParts.pop();
            var base = pathParts.join('/');

            return protocol + '//' + host + base +
                '/verify_alevel_report.php' +
                '?student_id='  + encodeURIComponent(el.dataset.studentId) +
                '&term='        + encodeURIComponent(el.dataset.term) +
                '&year='        + encodeURIComponent(el.dataset.year) +
                '&class='       + encodeURIComponent(el.dataset.class) +
                '&stream='      + encodeURIComponent(el.dataset.stream) +
                '&exam_sets='   + encodeURIComponent(examSets.join(','));
        }

        // ── Helper: generate one QR code ─────────────────────────────────────
        function generateQR(el) {
            if (el.children.length > 0) return; // already generated
            new QRCode(el, {
                text:         buildUrl(el),
                width:        120,
                height:       120,
                correctLevel: QRCode.CorrectLevel.M,
                colorDark:    '#000000',
                colorLight:   '#ffffff'
            });
        }

        // ── LAZY QR GENERATION via IntersectionObserver ───────────────────────
        // QR codes are only generated when a report card scrolls into view
        // (+ 300px root margin so they're ready just before reaching the viewport).
        // This prevents generating 500 QR codes simultaneously on DOM ready,
        // which previously froze the browser for 10-30 seconds.
        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        generateQR(entry.target);
                        observer.unobserve(entry.target);
                    }
                });
            }, { rootMargin: '300px 0px' });

            document.querySelectorAll('.qrcode-container').forEach(function (el) {
                observer.observe(el);
            });
        } else {
            // Fallback for older browsers: generate all at once
            document.querySelectorAll('.qrcode-container').forEach(generateQR);
        }

        // ── Hide the loading overlay once all HTML is parsed ──────────────────
        var overlay = document.getElementById('gen-overlay');
        if (overlay) overlay.style.display = 'none';

        // ── Reset loading overlay on browser back-button ──────────────────────
        window.addEventListener('pageshow', function () {
            if (overlay) overlay.style.display = 'none';
        });

        // ── FORCE-GENERATE ALL QR CODES BEFORE PRINTING ───────────────────────
        // IntersectionObserver only builds a QR code once its card scrolls into
        // view — but printing never scrolls through the page, so most cards'
        // QR codes were never triggered. This catches every container right
        // before the print dialog opens (covers both the Print button and Ctrl+P).
        window.addEventListener('beforeprint', function () {
            document.querySelectorAll('.qrcode-container').forEach(generateQR);
        });
    })();
    </script>

</body>
</html>
<?php
// Final flush — ensures the closing tags reach the browser.
ob_end_flush();
?>