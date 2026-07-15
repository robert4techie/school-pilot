<?php
/**
 * fetch_alevel_analysis.php  –  v3 CBC Edition
 * ═══════════════════════════════════════════════════════════════════════════
 * A-Level Results Analysis  —  JSON Data Provider
 *
 * Grading (NCDC CBC New Curriculum — Ministry 2024):
 *   Principal:  A=80–100(5pts) · B=70–79(4pts) · C=60–69(3pts)
 *               D=50–59(2pts)  · E=0–49(1pt)
 *   Subsidiary: D–A (50–100) = 1pt · E (0–49) = 0pts
 *   Max Points: 17  (15 principal + 2 subsidiary)
 *
 *   Competence    = Grade D or above  (all levels earn ≥ 1pt)
 *   At-risk flag  = Grade E in ANY principal subject
 *
 * GET Parameters
 * ──────────────
 *   class           e.g. "Senior Five" | "Senior Six"
 *   stream          e.g. "A" | "B" | "All"
 *   term            1 | 2 | 3
 *   year            e.g. 2026
 *   exam_sets       Comma-separated exam_sets.id values (optional – auto-detect if omitted)
 *   target          School target competence-rate %     (optional – default 80)
 *
 * Response Keys (consumed by alevel_analysis.php)
 * ─────────────────────────────────────────────────
 *   filters, examSets
 *   totalStudents, studentsWithMarks
 *   classMean, avgTotalPoints, competenceRate, atRiskCount, vsLastTerm
 *   gradeDistribution, pointsDistribution
 *   subjectPerformance   (includes points per subject/paper, is_subsidiary)
 *   combinationAnalysis, genderBreakdown
 *   topStudents          (ranked by total_points desc)
 *   atRiskStudents       (grade E in any principal subject)
 *   needsWorkStudents
 *   trendData, pointsTrendData, streamComparison
 *   insights, actions
 * ═══════════════════════════════════════════════════════════════════════════
 */

require_once '../auth.php';
require_once '../conn.php';
header('Content-Type: application/json; charset=utf-8');

// ───────────────────────────────────────────────────────────────────────────
// COLLATION — normalise to utf8mb4_unicode_ci for the entire request so
//             JOINs between tables with mixed default collations never throw
//             "Illegal mix of collations".
// ───────────────────────────────────────────────────────────────────────────
mysqli_set_charset($conn, 'utf8mb4');
mysqli_query($conn, "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// ═══════════════════════════════════════════════════════════════════════════
//  PURE HELPER FUNCTIONS  (all mirror reports.php exactly)
// ═══════════════════════════════════════════════════════════════════════════

function al_tableName(int $year, int $term): string
{
    return $year . '_' . ([1 => 'i', 2 => 'ii', 3 => 'iii'][$term] ?? 'i') . '_alevel';
}

function al_previousTerm(int $term, int $year): array
{
    if ($term === 1) return ['term' => 3, 'year' => $year - 1];
    if ($term === 2) return ['term' => 1, 'year' => $year];
    return                  ['term' => 2, 'year' => $year];
}

/**
 * NCDC CBC percentage → letter grade.
 * A=80–100 · B=70–79 · C=60–69 · D=50–59 · E=0–49
 * Identical to markToGrade() in reports.php.
 */
function al_grade($mark): string
{
    if ($mark === null || $mark === '' || $mark === '-') return '-';
    $m = (float) $mark;
    if ($m >= 80) return 'A';
    if ($m >= 70) return 'B';
    if ($m >= 60) return 'C';
    if ($m >= 50) return 'D';
    return 'E';
}

/**
 * Competence = Grade D or above (all earn ≥ 1 point under CBC).
 * Used for competence rate calculation.
 */
function al_isCompetent(string $grade): bool
{
    return in_array($grade, ['A', 'B', 'C', 'D'], true);
}

/**
 * Principal points: A=5, B=4, C=3, D=2, E=1
 */
function al_principalPoints(string $grade): int
{
    return ['A' => 5, 'B' => 4, 'C' => 3, 'D' => 2, 'E' => 1][$grade] ?? 0;
}

/**
 * Subsidiary points: D–A = 1pt, E = 0pts
 */
function al_subsidiaryPoints(string $grade): int
{
    return in_array($grade, ['A', 'B', 'C', 'D'], true) ? 1 : 0;
}

/**
 * Mirror of isSubsidiarySubject() in reports.php.
 * Covers all realistic naming variations schools enter.
 */
function al_isSub(string $subject): bool
{
    $s = strtolower(trim($subject));
    $exact = ['gp','ict','sict','smath','subict','submath','sub math','sub-math','sub ict','sub-ict'];
    if (in_array($s, $exact, true)) return true;
    if (str_contains($s, 'general paper')) return true;
    if (str_contains($s, 'subsidiary'))   return true;
    if (str_contains($s, 'sub math'))     return true;
    if (str_contains($s, 'sub-math'))     return true;
    return false;
}

/** Mirror of normalizePaperName() in reports.php */
function al_paper(string $p): string
{
    $map = [
        'P1' => 'I',   'PI'   => 'I',   '1' => 'I',
        'P2' => 'II',  'PII'  => 'II',  '2' => 'II',
        'P3' => 'III', 'PIII' => 'III', '3' => 'III',
        'P4' => 'IV',  'PIV'  => 'IV',  '4' => 'IV',
    ];
    $u = strtoupper(trim($p));
    return $map[$u] ?? $u;
}

function al_tableExists(mysqli $conn, string $table): bool
{
    $r = mysqli_query(
        $conn,
        "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'"
    );
    return $r && mysqli_num_rows($r) > 0;
}

/** Safe mean — returns null when the array is empty or all values are null */
function al_avg(array $values): ?float
{
    $v = array_filter($values, fn ($x) => $x !== null && $x !== '');
    return count($v) > 0 ? array_sum($v) / count($v) : null;
}

/** Empty-grade-distribution template */
function al_emptyGrades(): array
{
    return ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
}

// ═══════════════════════════════════════════════════════════════════════════
//  INPUT VALIDATION
// ═══════════════════════════════════════════════════════════════════════════

if (!isset($_GET['class'], $_GET['term'], $_GET['year'])) {
    echo json_encode(['error' => 'Missing required parameters: class, term, year.']);
    exit;
}

$class     = mysqli_real_escape_string($conn, trim($_GET['class']));
$term      = max(1, min(3, (int) $_GET['term']));
$year      = (int) $_GET['year'];
$rawStream = trim($_GET['stream'] ?? 'All');
$stream    = ($rawStream === 'All' || $rawStream === '')
             ? '' : mysqli_real_escape_string($conn, $rawStream);
$target    = isset($_GET['target'])
             ? max(1.0, min(100.0, (float) $_GET['target'])) : 80.0;

// Exam set IDs — positive integers only
$examSetIds = [];
if (!empty($_GET['exam_sets'])) {
    foreach (explode(',', $_GET['exam_sets']) as $eid) {
        $i = (int) trim($eid);
        if ($i > 0) $examSetIds[] = $i;
    }
}

$tableName = al_tableName($year, $term);

if (!al_tableExists($conn, $tableName)) {
    echo json_encode([
        'error' => "No data table found for Term {$term}, {$year}. Expected: '{$tableName}'.",
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
//  EXAM SETS
// ═══════════════════════════════════════════════════════════════════════════

$examSetsInfo = [];

if (!empty($examSetIds)) {
    $ids_str = implode(',', $examSetIds);
    $res = mysqli_query($conn, "SELECT * FROM exam_sets WHERE id IN ($ids_str)");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $examSetsInfo[(int) $r['id']] = $r;
        }
    }
}

// Auto-detect when none specified
if (empty($examSetsInfo)) {
    $res = mysqli_query($conn, "SELECT DISTINCT exam_type FROM `$tableName` LIMIT 20");
    $autoIds = [];
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) $autoIds[] = (int) $r['exam_type'];
    }
    if (!empty($autoIds)) {
        $ids_str = implode(',', $autoIds);
        $res2 = mysqli_query($conn, "SELECT * FROM exam_sets WHERE id IN ($ids_str)");
        if ($res2) {
            while ($r = mysqli_fetch_assoc($res2)) {
                $examSetsInfo[(int) $r['id']] = $r;
                $examSetIds[]                 = (int) $r['id'];
            }
        }
    }
}

$examSetIds = array_values(array_unique(array_map('intval', $examSetIds)));

// ═══════════════════════════════════════════════════════════════════════════
//  FETCH STUDENTS
//  Pulls gender + subject_combination — both used by report card (reports.php)
// ═══════════════════════════════════════════════════════════════════════════

$streamClause = $stream ? "AND s.stream = '$stream'" : '';

$res_s = mysqli_query($conn, "
    SELECT s.student_id,
           s.first_name,
           s.last_name,
           s.gender,
           s.stream,
           s.subject_combination
    FROM   students s
    WHERE  s.current_class = '$class'
           $streamClause
    ORDER  BY s.student_id
");

$students   = [];
$studentIds = [];

if ($res_s) {
    while ($r = mysqli_fetch_assoc($res_s)) {
        $students[$r['student_id']] = [
            'name'        => trim($r['first_name'] . ' ' . $r['last_name']),
            'gender'      => ucfirst(strtolower($r['gender'] ?? 'Unknown')),
            'stream'      => $r['stream'] ?? '',
            'combination' => trim($r['subject_combination'] ?? 'Unknown'),
            'subjects'    => [],
        ];
        $studentIds[] = $r['student_id'];
    }
}

$totalStudents = count($students);

if ($totalStudents === 0) {
    echo json_encode([
        'error' => "No students found for class '$class'"
                 . ($stream ? ", stream '$stream'" : '') . '.',
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
//  FETCH MARKS  (current term)
// ═══════════════════════════════════════════════════════════════════════════

$escapedIds = array_map(
    fn ($id) => "'" . mysqli_real_escape_string($conn, $id) . "'",
    $studentIds
);
$ids_in    = implode(',', $escapedIds);
$examWhere = !empty($examSetIds)
             ? 'AND m.exam_type IN (' . implode(',', $examSetIds) . ')' : '';

$res_m = mysqli_query($conn, "
    SELECT m.student_id, m.subject, m.paper, m.mark, m.exam_type
    FROM   `$tableName` m
    WHERE  m.student_id IN ($ids_in)
           $examWhere
");

// Structure: $students[$sid]['subjects'][$subj]['papers'][$paper][$examId] = mark
if ($res_m) {
    while ($r = mysqli_fetch_assoc($res_m)) {
        $sid   = $r['student_id'];
        $subj  = $r['subject'];
        $paper = al_paper($r['paper']);
        $eid   = (int) $r['exam_type'];
        $mark  = ($r['mark'] !== null && $r['mark'] !== '') ? (float) $r['mark'] : null;

        if (!isset($students[$sid]) || $mark === null) continue;

        if (!isset($students[$sid]['subjects'][$subj])) {
            $students[$sid]['subjects'][$subj] = [
                'is_sub' => al_isSub($subj),
                'papers' => [],
            ];
        }
        $students[$sid]['subjects'][$subj]['papers'][$paper][$eid] = $mark;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
//  PREVIOUS-TERM MEANS  (per student — for progress delta)
// ═══════════════════════════════════════════════════════════════════════════

$prevInfo         = al_previousTerm($term, $year);
$prevTable        = al_tableName($prevInfo['year'], $prevInfo['term']);
$prevStudentMeans = [];

if (al_tableExists($conn, $prevTable) && !empty($studentIds)) {
    $res_prev = mysqli_query($conn, "
        SELECT student_id, AVG(mark) AS avg_mark
        FROM   `$prevTable`
        WHERE  student_id IN ($ids_in)
        GROUP  BY student_id
    ");
    if ($res_prev) {
        while ($r = mysqli_fetch_assoc($res_prev)) {
            $prevStudentMeans[$r['student_id']] = round((float) $r['avg_mark'], 1);
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════
//  PROCESS RESULTS  (per student)
// ═══════════════════════════════════════════════════════════════════════════

// Accumulators
$subjectStats      = [];
$studentResults    = [];
$allOverallMeans   = [];
$gradeDistribution = al_emptyGrades();

// Gender accumulator — initialise for known genders to guarantee output order
$genderAccum = [
    'Male'    => ['means' => [], 'competent' => 0, 'total' => 0, 'grades' => al_emptyGrades()],
    'Female'  => ['means' => [], 'competent' => 0, 'total' => 0, 'grades' => al_emptyGrades()],
    'Unknown' => ['means' => [], 'competent' => 0, 'total' => 0, 'grades' => al_emptyGrades()],
];

$combinationAccum = [];

foreach ($students as $sid => $sData) {
    $subjFinals         = [];
    $subjGrades         = [];
    $principalSubjects  = [];
    $principalESubjects = []; // CBC at-risk: grade E in any principal
    $totalPoints        = 0;
    $principalPts       = 0;
    $subsidiaryPts      = 0;
    $principalCompetent = 0;  // grade D or above in principal
    $principalFails     = 0;  // grade E in principal

    foreach ($sData['subjects'] as $subj => $subjData) {
        $isSub       = $subjData['is_sub'];
        $paperFinals = [];

        foreach ($subjData['papers'] as $paper => $examMarks) {
            if (!empty($examMarks)) {
                $paperFinals[$paper] = array_sum($examMarks) / count($examMarks);
            }
        }
        if (empty($paperFinals)) continue;

        $subjFinal = array_sum($paperFinals) / count($paperFinals);
        $subjGrade = al_grade($subjFinal);
        $pts       = $isSub ? al_subsidiaryPoints($subjGrade) : al_principalPoints($subjGrade);

        $subjFinals[$subj] = $subjFinal;
        $subjGrades[$subj] = $subjGrade;
        $totalPoints      += $pts;

        if (!$isSub) {
            $principalSubjects[] = $subj;
            $principalPts       += $pts;
            if (al_isCompetent($subjGrade)) {
                $principalCompetent++;
            } else {
                $principalFails++;
                $principalESubjects[] = $subj;
            }
        } else {
            $subsidiaryPts += $pts;
        }

        // Subject-wide stats
        if (!isset($subjectStats[$subj])) {
            $subjectStats[$subj] = [
                'is_sub'     => $isSub,
                'marks'      => [],
                'paperMarks' => [],
                'gradeCount' => al_emptyGrades(),
                'pointsList' => [],
                'hod_rows'   => [],
            ];
        }
        $subjectStats[$subj]['marks'][]        = $subjFinal;
        $subjectStats[$subj]['gradeCount'][$subjGrade]++;
        $subjectStats[$subj]['pointsList'][]   = $pts;
        foreach ($paperFinals as $paper => $pm) {
            $subjectStats[$subj]['paperMarks'][$paper][] = $pm;
        }
        $subjectStats[$subj]['hod_rows'][] = [
            'name'        => $sData['name'],
            'gender'      => $sData['gender'],
            'stream'      => $sData['stream'],
            'combination' => $sData['combination'],
            'mean'        => round($subjFinal, 1),
            'grade'       => $subjGrade,
            'points'      => $pts,
            'papers'      => array_map(fn ($v) => round($v, 1), $paperFinals),
        ];
    }

    $overallMean         = !empty($subjFinals) ? al_avg(array_values($subjFinals)) : null;
    $overallGrade        = al_grade($overallMean);
    $fullCombinationPass = (count($principalSubjects) > 0 && $principalFails === 0);

    $prevMean = $prevStudentMeans[$sid] ?? null;
    $delta    = ($overallMean !== null && $prevMean !== null)
                ? round($overallMean - $prevMean, 1) : null;

    if ($overallMean !== null) {
        $allOverallMeans[]               = $overallMean;
        $gradeDistribution[$overallGrade]++;
    }

    // ── Gender accumulation ──────────────────────────────────────────────
    $gender = $sData['gender'];
    if (!isset($genderAccum[$gender])) {
        $genderAccum[$gender] = [
            'means'     => [], 'competent' => 0,
            'total'     => 0,  'grades'    => al_emptyGrades(),
        ];
    }
    $genderAccum[$gender]['total']++;
    if ($overallMean !== null) {
        $genderAccum[$gender]['means'][] = $overallMean;
        $genderAccum[$gender]['grades'][$overallGrade]++;
    }
    if ($principalCompetent >= 2) $genderAccum[$gender]['competent']++;

    // ── Combination accumulation ─────────────────────────────────────────
    $combo = ($sData['combination'] !== '') ? $sData['combination'] : 'Unknown';
    if (!isset($combinationAccum[$combo])) {
        $combinationAccum[$combo] = [
            'means'             => [],
            'competentCount'    => 0,
            'fullPasses'        => 0,
            'atRisk'            => 0,
            'total'             => 0,
            'grades'            => al_emptyGrades(),
            'principalSubjects' => [],
        ];
    }
    $combinationAccum[$combo]['total']++;
    if ($overallMean !== null) {
        $combinationAccum[$combo]['means'][] = $overallMean;
        $combinationAccum[$combo]['grades'][$overallGrade]++;
    }
    if ($principalCompetent >= 2) $combinationAccum[$combo]['competentCount']++;
    if ($fullCombinationPass)     $combinationAccum[$combo]['fullPasses']++;
    if ($principalFails > 0)      $combinationAccum[$combo]['atRisk']++;
    foreach ($principalSubjects as $ps) {
        $combinationAccum[$combo]['principalSubjects'][$ps] = true;
    }

    $studentResults[$sid] = [
        'student_id'           => $sid,
        'name'                 => $sData['name'],
        'gender'               => $gender,
        'stream'               => $sData['stream'],
        'combination'          => $combo,
        'overall_mean'         => $overallMean !== null ? round($overallMean, 1) : null,
        'overall_grade'        => $overallGrade,
        'total_points'         => $totalPoints,
        'principal_points'     => $principalPts,
        'subsidiary_points'    => $subsidiaryPts,
        'principal_competent'  => $principalCompetent,
        'principal_fails'      => $principalFails,
        'principal_e_subjects' => implode(', ', $principalESubjects),
        'full_combination_pass'=> $fullCombinationPass,
        'progress_delta'       => $delta,
        'prev_term_mean'       => $prevMean,
        'subject_marks'        => array_map(fn ($m) => round($m, 1), $subjFinals),
        'subject_grades'       => $subjGrades,
    ];
}

$studentsWithMarks = count($allOverallMeans);
$classMean         = $studentsWithMarks > 0
                     ? round(array_sum($allOverallMeans) / $studentsWithMarks, 1) : 0;

// ═══════════════════════════════════════════════════════════════════════════
//  COMPETENCE RATE & POINTS METRICS  (CBC)
//  Competence = grade D or above in principal subject (earns ≥ 1pt)
//  At-risk    = grade E in ANY principal subject
// ═══════════════════════════════════════════════════════════════════════════

$competentCount    = 0; // ≥ 2 principal subjects grade D or above
$fullCombPassCount = 0; // ALL principal subjects D or above

foreach ($studentResults as $r) {
    if ($r['principal_competent'] >= 2) $competentCount++;
    if ($r['full_combination_pass'])    $fullCombPassCount++;
}

$competenceRate   = $totalStudents > 0
                    ? round($competentCount    / $totalStudents * 100, 1) : 0;
$fullCombPassRate = $totalStudents > 0
                    ? round($fullCombPassCount / $totalStudents * 100, 1) : 0;

// Average total points per student (out of 17)
$allTotalPoints   = array_column(array_values($studentResults), 'total_points');
$allTotalPoints   = array_filter($allTotalPoints, fn($p) => $p > 0);
$avgTotalPoints   = !empty($allTotalPoints)
                    ? round(array_sum($allTotalPoints) / count($allTotalPoints), 1) : 0;

// Points distribution bands for chart
$pointsBands      = ['0–4' => 0, '5–8' => 0, '9–12' => 0, '13–15' => 0, '16–17' => 0];
foreach ($allTotalPoints as $pts) {
    if      ($pts <= 4)  $pointsBands['0–4']++;
    elseif  ($pts <= 8)  $pointsBands['5–8']++;
    elseif  ($pts <= 12) $pointsBands['9–12']++;
    elseif  ($pts <= 15) $pointsBands['13–15']++;
    else                 $pointsBands['16–17']++;
}

// At-risk = grade E in any principal subject (CBC rule)
$atRiskStudents = array_values(
    array_filter($studentResults, fn ($r) => $r['principal_fails'] > 0)
);
usort($atRiskStudents, fn ($a, $b) => ($a['total_points'] ?? 0) <=> ($b['total_points'] ?? 0));
$atRiskCount = count($atRiskStudents);

// ═══════════════════════════════════════════════════════════════════════════
//  TREND DELTAS
// ═══════════════════════════════════════════════════════════════════════════

$vsLastTerm = null;
if (al_tableExists($conn, $prevTable) && !empty($studentIds)) {
    $res_lt = mysqli_query($conn, "SELECT AVG(mark) AS avg FROM `$prevTable` WHERE student_id IN ($ids_in)");
    if ($res_lt && ($rlt = mysqli_fetch_assoc($res_lt)) && $rlt['avg'] !== null) {
        $vsLastTerm = round($classMean - (float) $rlt['avg'], 1);
    }
}

$vsSameTermLastYear      = null;
$sameTermLastYearTable   = al_tableName($year - 1, $term);
if (al_tableExists($conn, $sameTermLastYearTable) && !empty($studentIds)) {
    $res_ly = mysqli_query($conn, "SELECT AVG(mark) AS avg FROM `$sameTermLastYearTable` WHERE student_id IN ($ids_in)");
    if ($res_ly && ($rly = mysqli_fetch_assoc($res_ly)) && $rly['avg'] !== null) {
        $vsSameTermLastYear = round($classMean - (float) $rly['avg'], 1);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
//  GRADE DISTRIBUTION
// ═══════════════════════════════════════════════════════════════════════════

$gradeDist = [];
foreach ($gradeDistribution as $grade => $count) {
    $gradeDist[$grade] = [
        'count'      => $count,
        'percentage' => $studentsWithMarks > 0
                        ? round($count / $studentsWithMarks * 100, 1) : 0,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
//  SUBJECT PERFORMANCE TABLE
//  — scores and grades separated per paper
//  — HOD export: ranked per-student list per subject
// ═══════════════════════════════════════════════════════════════════════════

$paperOrder         = ['I' => 0, 'II' => 1, 'III' => 2, 'IV' => 3, 'V' => 4, 'VI' => 5];
$subjectPerformance = [];

foreach ($subjectStats as $subj => $stats) {
    if (empty($stats['marks'])) continue;

    $isSub        = $stats['is_sub'];  // correct scope — from accumulator
    $overallMean  = round(al_avg($stats['marks']), 1);
    $overallGrade = al_grade($overallMean);

    $paperMeans  = [];
    $paperGrades = [];
    $paperPoints = [];
    foreach ($stats['paperMarks'] as $paper => $pmList) {
        $pm                  = round(al_avg($pmList), 1);
        $pg                  = al_grade($pm);
        $paperMeans[$paper]  = $pm;
        $paperGrades[$paper] = $pg;
        $paperPoints[$paper] = $isSub ? al_subsidiaryPoints($pg) : al_principalPoints($pg);
    }
    uksort($paperMeans,  fn ($a, $b) => ($paperOrder[$a] ?? 9) <=> ($paperOrder[$b] ?? 9));
    uksort($paperGrades, fn ($a, $b) => ($paperOrder[$a] ?? 9) <=> ($paperOrder[$b] ?? 9));
    uksort($paperPoints, fn ($a, $b) => ($paperOrder[$a] ?? 9) <=> ($paperOrder[$b] ?? 9));

    $takers         = count($stats['marks']);
    // CBC: competence = grade D or above for principal; D or above for subsidiary
    $competentCount = $stats['gradeCount']['A']
                    + $stats['gradeCount']['B']
                    + $stats['gradeCount']['C']
                    + $stats['gradeCount']['D'];

    $avgPoints = !empty($stats['pointsList'])
                 ? round(array_sum($stats['pointsList']) / count($stats['pointsList']), 1) : 0;
    $overallPts = $isSub ? al_subsidiaryPoints($overallGrade) : al_principalPoints($overallGrade);
    $ptsMax     = $isSub ? 1 : 5;

    // HOD export: ranked by mean desc
    $hodRows = $stats['hod_rows'];
    usort($hodRows, fn ($a, $b) => $b['mean'] <=> $a['mean']);
    foreach ($hodRows as $i => &$hr) $hr['rank'] = $i + 1;
    unset($hr);

    $subjectPerformance[] = [
        'subject'          => $subj,
        'is_subsidiary'    => $stats['is_sub'],
        'overall_mean'     => $overallMean,
        'overall_grade'    => $overallGrade,
        'overall_points'   => $overallPts,
        'points_max'       => $ptsMax,
        'avg_student_pts'  => $avgPoints,
        'paper_means'      => $paperMeans,
        'paper_grades'     => $paperGrades,
        'paper_points'     => $paperPoints,
        'grade_count'      => $stats['gradeCount'],
        'competence_rate'  => $takers > 0 ? round($competentCount / $takers * 100, 1) : 0,
        'pass_rate'        => $takers > 0 ? round($competentCount / $takers * 100, 1) : 0, // alias
        'student_count'    => $takers,
        'hod_export'       => $hodRows,
    ];
}

// Sort: principals first, then by mean desc
usort($subjectPerformance, function ($a, $b) {
    if ($a['is_subsidiary'] !== $b['is_subsidiary']) {
        return $a['is_subsidiary'] ? 1 : -1;
    }
    return $b['overall_mean'] <=> $a['overall_mean'];
});

// ═══════════════════════════════════════════════════════════════════════════
//  GENDER BREAKDOWN  (new)
// ═══════════════════════════════════════════════════════════════════════════

$genderBreakdown = [];
foreach ($genderAccum as $gender => $gd) {
    if ($gd['total'] === 0) continue;
    $gMean     = !empty($gd['means']) ? round(al_avg($gd['means']), 1) : null;
    $withMarks = count($gd['means']);
    $gDist     = [];
    foreach ($gd['grades'] as $grade => $cnt) {
        $gDist[$grade] = [
            'count'      => $cnt,
            'percentage' => $withMarks > 0 ? round($cnt / $withMarks * 100, 1) : 0,
        ];
    }
    $genderBreakdown[$gender] = [
        'total'                => $gd['total'],
        'students_with_marks'  => $withMarks,
        'mean'                 => $gMean,
        'grade'                => al_grade($gMean),
        'competence_rate'      => $gd['total'] > 0
                                  ? round($gd['competent'] / $gd['total'] * 100, 1) : 0,
        'grade_distribution'   => $gDist,
    ];
}
if (isset($genderBreakdown['Unknown']) && $genderBreakdown['Unknown']['total'] === 0) {
    unset($genderBreakdown['Unknown']);
}

// ═══════════════════════════════════════════════════════════════════════════
//  SUBJECT COMBINATION ANALYSIS  (new)
//  Groups students by subject_combination (same field shown on report card).
//  Provides: class mean, 2+ pass rate, full-combination pass rate, at-risk
//  count, grade distribution, and radar data for each principal subject.
// ═══════════════════════════════════════════════════════════════════════════

// Build a fast lookup: subject → class mean (all students, from subjectPerformance)
$subjectMeanLookup = [];
foreach ($subjectPerformance as $sp) {
    $subjectMeanLookup[$sp['subject']] = $sp['overall_mean'];
}

$combinationAnalysis = [];
foreach ($combinationAccum as $combo => $ca) {
    if ($ca['total'] === 0) continue;

    $cMean     = !empty($ca['means']) ? round(al_avg($ca['means']), 1) : null;
    $withMarks = count($ca['means']);
    $cDist     = [];
    foreach ($ca['grades'] as $grade => $cnt) {
        $cDist[$grade] = [
            'count'      => $cnt,
            'percentage' => $withMarks > 0 ? round($cnt / $withMarks * 100, 1) : 0,
        ];
    }

    // Radar data: per-principal-subject mean limited to students in this combination
    $comboStudentNames = array_column(
        array_filter(
            array_values($studentResults),
            fn ($r) => $r['combination'] === $combo
        ),
        'name'
    );
    $radarData = [];
    foreach (array_keys($ca['principalSubjects']) as $ps) {
        // Find hod_export row for this subject and filter to this combination
        $subjectRows = [];
        foreach ($subjectPerformance as $sp) {
            if ($sp['subject'] === $ps && !$sp['is_subsidiary']) {
                $subjectRows = array_filter(
                    $sp['hod_export'],
                    fn ($h) => in_array($h['name'], $comboStudentNames, true)
                );
                break;
            }
        }
        $radarData[$ps] = !empty($subjectRows)
            ? round(al_avg(array_column(array_values($subjectRows), 'mean')), 1)
            : null;
    }

    $combinationAnalysis[] = [
        'combination'                => $combo,
        'total_students'             => $ca['total'],
        'mean'                       => $cMean,
        'grade'                      => al_grade($cMean),
        'competence_rate'            => $ca['total'] > 0
                                        ? round($ca['competentCount'] / $ca['total'] * 100, 1) : 0,
        'full_combination_pass_rate' => $ca['total'] > 0
                                        ? round($ca['fullPasses']     / $ca['total'] * 100, 1) : 0,
        'at_risk_count'              => $ca['atRisk'],  // students with E in any principal
        'principal_subjects'         => array_keys($ca['principalSubjects']),
        'grade_distribution'         => $cDist,
        'radar_data'                 => $radarData,
    ];
}

usort($combinationAnalysis, fn ($a, $b) => $b['total_students'] <=> $a['total_students']);

// ═══════════════════════════════════════════════════════════════════════════
//  STUDENT SEGMENTS
// ═══════════════════════════════════════════════════════════════════════════

// Top 10 — ranked by total CBC points (ministry's official metric)
$sorted = $studentResults;
uasort($sorted, function ($a, $b) {
    $ptsDiff = ($b['total_points'] ?? 0) <=> ($a['total_points'] ?? 0);
    if ($ptsDiff !== 0) return $ptsDiff;
    return ($b['overall_mean'] ?? 0) <=> ($a['overall_mean'] ?? 0); // tie-break by mean
});
$topStudents = array_slice(array_values($sorted), 0, 10);

// Needs-work: below 50% overall (below grade D threshold) but no E in principal
$needsWork = array_values(array_filter(
    $studentResults,
    fn ($r) => ($r['overall_mean'] ?? 100) < 50 && $r['principal_fails'] === 0
));
usort($needsWork, fn ($a, $b) => ($a['total_points'] ?? 0) <=> ($b['total_points'] ?? 0));

// Most improved: largest positive delta (only students with previous-term data)
$improved = array_values(array_filter($studentResults, fn ($r) => ($r['progress_delta'] ?? -999) > 0));
usort($improved, fn ($a, $b) => ($b['progress_delta'] ?? 0) <=> ($a['progress_delta'] ?? 0));
$mostImproved = array_slice($improved, 0, 5);

// Most declined: largest negative delta
$declined = array_values(array_filter($studentResults, fn ($r) => ($r['progress_delta'] ?? 0) < 0));
usort($declined, fn ($a, $b) => ($a['progress_delta'] ?? 0) <=> ($b['progress_delta'] ?? 0));
$mostDeclined = array_slice($declined, 0, 5);

// ═══════════════════════════════════════════════════════════════════════════
//  TERM TREND  (Term 1 → 2 → 3 of the selected year)
// ═══════════════════════════════════════════════════════════════════════════

$trendLabels  = [];
$trendMeans   = [];
$trendPtsAvgs = [];
for ($t = 1; $t <= 3; $t++) {
    $tt = al_tableName($year, $t);
    if (!al_tableExists($conn, $tt) || empty($studentIds)) continue;
    $res_t = mysqli_query($conn, "SELECT AVG(mark) AS avg FROM `$tt` WHERE student_id IN ($ids_in)");
    if ($res_t && ($rt = mysqli_fetch_assoc($res_t)) && $rt['avg'] !== null) {
        $trendLabels[]  = "Term $t";
        $trendMeans[]   = round((float) $rt['avg'], 1);
        // Points trend: use current term's avg if same term, else null (cross-term comparison)
        $trendPtsAvgs[] = ($t === $term) ? $avgTotalPoints : null;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
//  YEAR-OVER-YEAR TREND  (same term, last 3 years)  (new)
// ═══════════════════════════════════════════════════════════════════════════

$yoyLabels = [];
$yoyMeans  = [];
for ($y = $year - 2; $y <= $year; $y++) {
    $yt        = al_tableName($y, $term);
    $yoyLabels[] = (string) $y;
    if (!al_tableExists($conn, $yt) || empty($studentIds)) {
        $yoyMeans[] = null;
        continue;
    }
    $res_y    = mysqli_query($conn, "SELECT AVG(mark) AS avg FROM `$yt` WHERE student_id IN ($ids_in)");
    $yoyMeans[] = ($res_y && ($ry = mysqli_fetch_assoc($res_y)) && $ry['avg'] !== null)
                  ? round((float) $ry['avg'], 1) : null;
}

// ═══════════════════════════════════════════════════════════════════════════
//  STREAM COMPARISON
//  Explicit COLLATE on both JOIN columns prevents "Illegal mix of collations"
//  when the dynamically-created marks table has a different default collation.
// ═══════════════════════════════════════════════════════════════════════════

$streamComparison = [];
$res_str = mysqli_query($conn, "
    SELECT DISTINCT stream
    FROM   students
    WHERE  current_class = '$class'
      AND  stream IS NOT NULL AND stream != ''
    ORDER  BY stream
");
if ($res_str) {
    while ($rstr = mysqli_fetch_assoc($res_str)) {
        $sc     = mysqli_real_escape_string($conn, $rstr['stream']);
        $res_sc = mysqli_query($conn, "
            SELECT AVG(m.mark)                  AS avg_mark,
                   COUNT(DISTINCT m.student_id) AS cnt
            FROM   `$tableName` m
            JOIN   students s
                   ON  s.student_id COLLATE utf8mb4_unicode_ci
                     = m.student_id COLLATE utf8mb4_unicode_ci
            WHERE  s.current_class = '$class'
              AND  s.stream        = '$sc'
                   $examWhere
        ");
        if ($res_sc && ($rsc = mysqli_fetch_assoc($res_sc)) && $rsc['avg_mark'] !== null) {
            $streamComparison[] = [
                'stream'   => $rstr['stream'],
                'mean'     => round((float) $rsc['avg_mark'], 1),
                'students' => (int) $rsc['cnt'],
            ];
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════
//  AUTO-GENERATED INSIGHTS  (CBC-aware)
// ═══════════════════════════════════════════════════════════════════════════

$insights = [];

// Competence rate vs target (D or above = competent)
$crDiff = round($competenceRate - $target, 1);
if ($crDiff < 0) {
    $insights[] = ['type' => 'warning', 'icon' => '📊',
        'text' => "Competence rate (grade D or above in principal subjects) is "
                . "<strong>{$competenceRate}%</strong> — "
                . abs($crDiff) . "% below the {$target}% school target."];
} else {
    $insights[] = ['type' => 'success', 'icon' => '✅',
        'text' => "Competence rate of <strong>{$competenceRate}%</strong> is "
                . "{$crDiff}% above the {$target}% target. Students are achieving "
                . "grade D or above in their principal subjects."];
}

// Average total points
$insights[] = ['type' => 'info', 'icon' => '🏆',
    'text' => "Class average total points: <strong>{$avgTotalPoints} / 17</strong>. "
            . "Full combination competence (all principals D+): <strong>{$fullCombPassRate}%</strong> "
            . "of students."];

// At-risk (E in any principal)
if ($atRiskCount > 0) {
    $insights[] = ['type' => 'danger', 'icon' => '🔴',
        'text' => "<strong>{$atRiskCount} student(s)</strong> have grade E in at least one "
                . "principal subject (0 pts from subsidiary for subsidiary E). "
                . "These students are below minimum CBC competence."];
}

// Weakest principal subject
$principals = array_filter($subjectPerformance, fn ($s) => !$s['is_subsidiary']);
if (!empty($principals)) {
    $worst = array_reduce(
        $principals,
        fn ($c, $s) => ($c === null || $s['overall_mean'] < $c['overall_mean']) ? $s : $c,
        null
    );
    if ($worst) {
        $insights[] = ['type' => 'warning', 'icon' => '⚠️',
            'text' => "Weakest principal subject: <strong>{$worst['subject']}</strong> — "
                    . "class mean {$worst['overall_mean']}% (Grade {$worst['overall_grade']}, "
                    . "avg pts {$worst['avg_student_pts']}/{$worst['points_max']}, "
                    . "competence rate {$worst['competence_rate']}%)."];
    }

    // Lowest paper
    $wpSubj = $wpPaper = '';
    $wpMean = 999;
    foreach ($principals as $ps) {
        foreach ($ps['paper_means'] as $paper => $pm) {
            if ($pm < $wpMean) {
                $wpMean  = $pm; $wpPaper = $paper; $wpSubj = $ps['subject'];
            }
        }
    }
    if ($wpSubj) {
        $insights[] = ['type' => 'warning', 'icon' => '📉',
            'text' => "Lowest-scoring paper: <strong>{$wpSubj} Paper {$wpPaper}</strong> "
                    . "at {$wpMean}% — targeted revision is recommended."];
    }
}

// Gender gap
if (isset($genderBreakdown['Male'], $genderBreakdown['Female'])) {
    $mMean = $genderBreakdown['Male']['mean']   ?? 0;
    $fMean = $genderBreakdown['Female']['mean'] ?? 0;
    $gap   = round(abs($mMean - $fMean), 1);
    if ($gap >= 5) {
        $better = $mMean > $fMean ? 'Male' : 'Female';
        $insights[] = ['type' => 'info', 'icon' => '⚥',
            'text' => "<strong>{$better}</strong> students are outperforming by {$gap}% "
                    . "(M: {$mMean}% | F: {$fMean}%). Consider gender-targeted support."];
    }
}

// Best and weakest combination
if (count($combinationAnalysis) > 1) {
    $caSorted = $combinationAnalysis;
    usort($caSorted, fn ($a, $b) => ($b['mean'] ?? 0) <=> ($a['mean'] ?? 0));
    $topCombo   = $caSorted[0];
    $worstCombo = end($caSorted);
    if ($topCombo['combination'] !== $worstCombo['combination']) {
        $insights[] = ['type' => 'info', 'icon' => '🏆',
            'text' => "Strongest combination: <strong>{$topCombo['combination']}</strong> "
                    . "at {$topCombo['mean']}% (competence rate {$topCombo['competence_rate']}%). "
                    . "Most support needed: <strong>{$worstCombo['combination']}</strong> "
                    . "at {$worstCombo['mean']}%."];
    }
}

// Term trend
if ($vsLastTerm !== null) {
    $dir  = $vsLastTerm >= 0 ? 'improved' : 'declined';
    $icon = $vsLastTerm >= 0 ? '📈' : '📉';
    $type = $vsLastTerm >= 0 ? 'success' : 'warning';
    $insights[] = ['type' => $type, 'icon' => $icon,
        'text' => "Class mean has <strong>{$dir} by " . abs($vsLastTerm)
                . "%</strong> compared to last term."];
}

// Year-over-year
if ($vsSameTermLastYear !== null) {
    $dir  = $vsSameTermLastYear >= 0 ? 'higher' : 'lower';
    $icon = $vsSameTermLastYear >= 0 ? '📈' : '📉';
    $type = $vsSameTermLastYear >= 0 ? 'success' : 'warning';
    $insights[] = ['type' => $type, 'icon' => $icon,
        'text' => "Class mean is <strong>" . abs($vsSameTermLastYear) . "% {$dir}</strong> "
                . "than the same term last year."];
}

// ═══════════════════════════════════════════════════════════════════════════
//  PRIORITISED ACTION RECOMMENDATIONS  (CBC-aware)
// ═══════════════════════════════════════════════════════════════════════════

$actions = [];

if ($atRiskCount > 0) {
    $actions[] = ['priority' => 'urgent', 'icon' => '🚨',
        'text' => "<strong>At-Risk Intervention:</strong> Schedule parent + student meetings "
                . "for the {$atRiskCount} student(s) with grade E in a principal subject. "
                . "Under CBC, grade E earns 0 pts for subsidiary and minimum pts for principal — "
                . "set weekly targets and fortnightly review."];
}

foreach (array_filter($subjectPerformance, fn ($s) => !$s['is_subsidiary'] && $s['overall_mean'] < 50) as $fs) {
    $actions[] = ['priority' => 'high', 'icon' => '📚',
        'text' => "<strong>{$fs['subject']}:</strong> Class mean {$fs['overall_mean']}% "
                . "(Grade {$fs['overall_grade']}, avg pts {$fs['avg_student_pts']}/{$fs['points_max']}, "
                . "competence rate {$fs['competence_rate']}%). "
                . "Arrange targeted revision; HOD to review per-paper data urgently."];
}

if ($competenceRate < $target) {
    $gap = round($target - $competenceRate, 1);
    $actions[] = ['priority' => 'high', 'icon' => '🎯',
        'text' => "<strong>Competence Gap ({$gap}%):</strong> Grade D is the minimum CBC "
                . "competence threshold. Set individual improvement plans "
                . "for every student currently graded E in any principal subject."];
}

if ($fullCombPassRate < ($competenceRate - 10)) {
    $actions[] = ['priority' => 'medium', 'icon' => '🔬',
        'text' => "<strong>Third Principal Subject:</strong> Many students are competent in only "
                . "2 principals. Identify the consistently weakest third subject per combination "
                . "and schedule dedicated practice to maximise total points."];
}

foreach ($combinationAnalysis as $ca) {
    if ($ca['at_risk_count'] > 0 && $ca['total_students'] >= 3) {
        $actions[] = ['priority' => 'medium', 'icon' => '📋',
            'text' => "<strong>{$ca['combination']}:</strong> {$ca['at_risk_count']} student(s) "
                    . "with grade E in a principal subject — HOD review of each principal subject "
                    . "in this combination is recommended."];
    }
}

if (!empty($mostDeclined)) {
    $names = implode(', ', array_slice(array_column($mostDeclined, 'name'), 0, 3));
    $extra = count($mostDeclined) > 3 ? ' and others' : '';
    $actions[] = ['priority' => 'medium', 'icon' => '📉',
        'text' => "<strong>Declining Students:</strong> {$names}{$extra} have declined from "
                . "last term. Class teacher one-on-one follow-up needed this week."];
}

if (isset($genderBreakdown['Male'], $genderBreakdown['Female'])) {
    $mCR = $genderBreakdown['Male']['competence_rate']   ?? 0;
    $fCR = $genderBreakdown['Female']['competence_rate'] ?? 0;
    if (abs($mCR - $fCR) >= 10) {
        $lower = $mCR < $fCR ? 'Male' : 'Female';
        $actions[] = ['priority' => 'medium', 'icon' => '⚥',
            'text' => "<strong>Gender Support:</strong> {$lower} students have a notably lower "
                    . "competence rate. Consider gender-specific revision programmes "
                    . "or peer mentorship."];
    }
}

if (empty($actions)) {
    $actions[] = ['priority' => 'low', 'icon' => '✅',
        'text' => "Performance is on track across all CBC metrics. Continue current interventions "
                . "and monitor points progress fortnightly."];
}

// ═══════════════════════════════════════════════════════════════════════════
//  FINAL JSON RESPONSE
// ═══════════════════════════════════════════════════════════════════════════

echo json_encode([

    // ── Context ────────────────────────────────────────────────────────────
    'filters' => [
        'class'  => $class,
        'stream' => $rawStream,
        'term'   => $term,
        'year'   => $year,
        'target' => $target,
    ],
    'examSets' => array_values($examSetsInfo),

    // ── Top-level KPIs (consumed directly by health cards) ─────────────────
    'totalStudents'     => $totalStudents,
    'studentsWithMarks' => $studentsWithMarks,
    'classMean'         => $classMean,
    'classGrade'        => al_grade($classMean),
    'avgTotalPoints'    => $avgTotalPoints,          // /17 — ministry CBC primary metric
    'competenceRate'    => $competenceRate,           // D or above in principal
    'fullCombPassRate'  => $fullCombPassRate,         // ALL principals D+
    'atRiskCount'       => $atRiskCount,              // E in any principal
    'vsLastTerm'        => $vsLastTerm,               // null if no prev table
    'vsSameTermLastYear'=> $vsSameTermLastYear,

    // ── Distributions ──────────────────────────────────────────────────────
    'gradeDistribution' => $gradeDist,
    'pointsDistribution'=> $pointsBands,             // for points band chart

    // ── Subject performance ────────────────────────────────────────────────
    'subjectPerformance' => $subjectPerformance,

    // ── Combination & gender analysis ─────────────────────────────────────
    'combinationAnalysis' => $combinationAnalysis,
    'genderBreakdown'     => $genderBreakdown,

    // ── Student segments ───────────────────────────────────────────────────
    'topStudents'      => $topStudents,              // ranked by total_points
    'atRiskStudents'   => array_slice($atRiskStudents, 0, 20),
    'needsWorkStudents'=> array_slice($needsWork, 0, 10),
    'mostImproved'     => $mostImproved,
    'mostDeclined'     => $mostDeclined,

    // ── Chart data ─────────────────────────────────────────────────────────
    'trendData' => [
        'labels' => $trendLabels,
        'data'   => $trendMeans,
    ],
    'pointsTrendData' => [                           // for points trend chart
        'labels' => $trendLabels,
        'data'   => $trendPtsAvgs,
    ],
    'yoyTrend' => [
        'labels' => $yoyLabels,
        'data'   => $yoyMeans,
    ],
    'streamComparison' => $streamComparison,

    // ── Narrative ──────────────────────────────────────────────────────────
    'insights' => $insights,
    'actions'  => $actions,

], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

mysqli_close($conn);