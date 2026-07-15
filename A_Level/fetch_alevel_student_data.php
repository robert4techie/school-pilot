<?php
/**
 * fetch_alevel_student_data.php
 * ─────────────────────────────────────────────────────────────────────────────
 * A-Level Individual Student Analysis — Combined Diagnostic + Predictive
 * JSON data provider for student_analysis.php
 *
 * NCDC CBC New Curriculum — Uganda Ministry of Education
 *   Principal:  A=80–100(5pts) · B=70–79(4pts) · C=60–69(3pts)
 *               D=50–59(2pts)  · E=0–49(1pt)
 *   Subsidiary: D–A (50–100) = 1pt · E (0–49) = 0pts
 *   Max Points: 17 (15 principal + 2 subsidiary)
 *
 * GET Parameters:
 *   student_id  — required
 *   term        — 1|2|3  (selected term for diagnostic snapshot)
 *   year        — e.g. 2026
 *
 * Response Keys:
 *   studentInfo, overallStats, subjectPerformance,
 *   performanceTrend, termHistory, strengthsWeaknesses,
 *   classRanking, insights, predictive
 */
require_once '../auth.php';
require_once '../conn.php';
header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');
mysqli_query($conn, "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// ═══════════════════════════════════════════════════════════════════════════
//  HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════════

/** Build A-Level marks table name — e.g. 2026_ii_alevel */
function al_table(int $year, int $term): string
{
    return $year . '_' . ['i' => 'i', 1 => 'i', 2 => 'ii', 3 => 'iii'][$term] . '_alevel';
}

/** Check whether a table exists */
function al_tableExists($conn, string $table): bool
{
    $safe = mysqli_real_escape_string($conn, $table);
    $r    = mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");
    return $r && mysqli_num_rows($r) > 0;
}

/**
 * CBC grade from percentage.
 * A=80–100 · B=70–79 · C=60–69 · D=50–59 · E=0–49
 */
function al_grade($mark): string
{
    if ($mark === null || !is_numeric($mark)) return '-';
    $m = (float) $mark;
    if ($m >= 80) return 'A';
    if ($m >= 70) return 'B';
    if ($m >= 60) return 'C';
    if ($m >= 50) return 'D';
    return 'E';
}

/** Achievement level label — subsidiary E = No Competence */
function al_achievement(string $grade, bool $isSub = false): string
{
    return match ($grade) {
        'A' => 'Exceptional',
        'B' => 'Outstanding',
        'C' => 'Satisfactory',
        'D' => 'Basic',
        'E' => $isSub ? 'No Competence' : 'Elementary',
        default => '—',
    };
}

/** Points per CBC rules */
function al_points(string $grade, bool $isSub): int
{
    if ($isSub) return in_array($grade, ['A','B','C','D']) ? 1 : 0;
    return ['A'=>5,'B'=>4,'C'=>3,'D'=>2,'E'=>1][$grade] ?? 0;
}

/**
 * Is the subject subsidiary?
 * Mirrors isSubsidiarySubject() in reports.php exactly.
 */
function al_isSub(string $subject): bool
{
    $s = strtolower(trim($subject));
    $exact = ['gp','ict','sict','smath','subict','submath',
              'sub math','sub-math','sub ict','sub-ict'];
    if (in_array($s, $exact, true)) return true;
    if (str_contains($s, 'general paper')) return true;
    if (str_contains($s, 'subsidiary'))   return true;
    if (str_contains($s, 'sub math'))     return true;
    if (str_contains($s, 'sub-math'))     return true;
    return false;
}

/**
 * Fetch all marks for a student in one table.
 * Returns [ subject => [ paper => mark, ... ], ... ]
 */
function al_fetchMarks($conn, string $table, string $studentId): array
{
    $safe = mysqli_real_escape_string($conn, $studentId);
    $res  = mysqli_query($conn,
        "SELECT subject, paper, mark FROM `{$table}`
         WHERE student_id COLLATE utf8mb4_unicode_ci = '{$safe}'");
    $out  = [];
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $out[$r['subject']][$r['paper']] = ($r['mark'] !== null && $r['mark'] !== '') ? (float) $r['mark'] : null;
        }
    }
    return $out;
}

/**
 * Calculate subject average across papers.
 * A subject with multiple papers averages them.
 */
function al_subjectAvg(array $papers): ?float
{
    $vals = array_filter(array_values($papers), fn($v) => $v !== null);
    if (empty($vals)) return null;
    return round(array_sum($vals) / count($vals), 1);
}

// ── Statistical engine ────────────────────────────────────────────────────────

function al_linearRegression(array $data): ?array
{
    $n = count($data);
    if ($n < 2) return null;
    $sumX = $sumY = $sumXY = $sumX2 = 0;
    for ($i = 0; $i < $n; $i++) {
        $x = $i + 1; $y = $data[$i];
        $sumX += $x; $sumY += $y; $sumXY += $x * $y; $sumX2 += $x * $x;
    }
    $denom = $n * $sumX2 - $sumX * $sumX;
    if ($denom == 0) return null;
    $slope     = ($n * $sumXY - $sumX * $sumY) / $denom;
    $intercept = ($sumY - $slope * $sumX) / $n;
    $meanY     = $sumY / $n;
    $ssTot     = array_sum(array_map(fn($y) => pow($y - $meanY, 2), $data));
    $ssRes     = 0;
    for ($i = 0; $i < $n; $i++) {
        $ssRes += pow($data[$i] - ($slope * ($i+1) + $intercept), 2);
    }
    $r2 = $ssTot > 0 ? 1 - $ssRes / $ssTot : 0;
    return [
        'slope'      => round($slope, 2),
        'intercept'  => $intercept,
        'rSquared'   => round($r2, 3),
        'confidence' => min(100, max(0, round($r2 * 100))),
    ];
}

function al_movingAvg(array $data, int $window = 3): float
{
    if (count($data) < $window) return (float) end($data);
    return array_sum(array_slice($data, -$window)) / $window;
}

function al_expSmoothing(array $data, float $alpha = 0.4): float
{
    if (empty($data)) return 0;
    $s = $data[0];
    foreach ($data as $v) $s = $alpha * $v + (1 - $alpha) * $s;
    return $s;
}

function al_stdDev(array $data): float
{
    $n = count($data);
    if ($n < 2) return 0;
    $mean = array_sum($data) / $n;
    return sqrt(array_sum(array_map(fn($x) => pow($x - $mean, 2), $data)) / ($n - 1));
}

function al_confidenceInterval(array $data, float $pred): array
{
    $sd = al_stdDev($data);
    $n  = count($data);
    $me = 1.96 * ($sd / sqrt($n));
    return [
        'lower' => round(max(0, $pred - $me), 1),
        'upper' => round(min(100, $pred + $me), 1),
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
//  PARAMETER VALIDATION
// ═══════════════════════════════════════════════════════════════════════════

if (!isset($_GET['student_id'], $_GET['term'], $_GET['year'])) {
    echo json_encode(['error' => 'Missing required parameters: student_id, term, year.']);
    exit;
}

$studentId    = trim($_GET['student_id']);
$selectedTerm = max(1, min(3, (int) $_GET['term']));
$year         = (int) $_GET['year'];
$currentYear  = date('Y');

if (!$studentId) {
    echo json_encode(['error' => 'Invalid student_id.']);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
//  1. STUDENT INFO
// ═══════════════════════════════════════════════════════════════════════════

$stmt = mysqli_prepare($conn,
    "SELECT student_id, first_name, last_name, current_class, stream,
            gender, profile_photo, subject_combination
     FROM   students
     WHERE  student_id = ?");
mysqli_stmt_bind_param($stmt, 's', $studentId);
mysqli_stmt_execute($stmt);
$studentInfo = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$studentInfo) {
    echo json_encode(['error' => 'Student not found.']);
    exit;
}

$studentClass  = $studentInfo['current_class'];
$studentStream = $studentInfo['stream'] ?? '';

// ═══════════════════════════════════════════════════════════════════════════
//  2. PROCESS ALL THREE TERMS FOR SELECTED YEAR
//     Builds: termHistory, performanceTrend, subjectPerformance (selected term)
// ═══════════════════════════════════════════════════════════════════════════

$termHistory          = [];   // [termNum => [subject => ['avg'=>float,'grade'=>str,'pts'=>int,'is_sub'=>bool]]]
$performanceTrend     = [];   // [{label, mean, grade, total_points}]
$subjectPerformance   = [];   // Current term — detailed per-subject rows
$classAverages        = [];   // [subject => avg%] for selected term

// ── Class averages for selected term ────────────────────────────────────────
$selTable = al_table($year, $selectedTerm);
if (al_tableExists($conn, $selTable)) {
    // Get all student IDs in same class/stream
    $esc_class  = mysqli_real_escape_string($conn, $studentClass);
    $esc_stream = mysqli_real_escape_string($conn, $studentStream);
    $r = mysqli_query($conn,
        "SELECT DISTINCT student_id FROM `{$selTable}`
         WHERE class  COLLATE utf8mb4_unicode_ci = '{$esc_class}'
           AND stream COLLATE utf8mb4_unicode_ci = '{$esc_stream}'");
    $classIds = [];
    if ($r) while ($row = mysqli_fetch_assoc($r)) $classIds[] = $row['student_id'];

    // Accumulate per-subject averages
    $subjTotals = [];
    $subjCounts = [];
    foreach ($classIds as $cid) {
        $cMarks = al_fetchMarks($conn, $selTable, $cid);
        foreach ($cMarks as $subj => $papers) {
            $avg = al_subjectAvg($papers);
            if ($avg !== null) {
                $subjTotals[$subj] = ($subjTotals[$subj] ?? 0) + $avg;
                $subjCounts[$subj] = ($subjCounts[$subj] ?? 0) + 1;
            }
        }
    }
    foreach ($subjTotals as $subj => $tot) {
        $classAverages[$subj] = round($tot / $subjCounts[$subj], 1);
    }
}

// ── Process each term ────────────────────────────────────────────────────────
for ($t = 1; $t <= 3; $t++) {
    $tbl = al_table($year, $t);
    if (!al_tableExists($conn, $tbl)) continue;

    $marks = al_fetchMarks($conn, $tbl, $studentId);
    if (empty($marks)) continue;

    $termSubjs = [];
    $termMeans = [];
    $termPts   = 0;

    foreach ($marks as $subj => $papers) {
        $avg = al_subjectAvg($papers);
        if ($avg === null) continue;
        $grade  = al_grade($avg);
        $isSub  = al_isSub($subj);
        $pts    = al_points($grade, $isSub);
        $termSubjs[$subj] = [
            'avg'    => $avg,
            'grade'  => $grade,
            'pts'    => $pts,
            'is_sub' => $isSub,
            'papers' => $papers,
        ];
        $termMeans[] = $avg;
        $termPts    += $pts;
    }

    if (empty($termMeans)) continue;

    $termAvg   = round(array_sum($termMeans) / count($termMeans), 1);
    $termGrade = al_grade($termAvg);
    $termHistory[$t] = $termSubjs;

    $performanceTrend[] = [
        'label'        => "T{$t} {$year}",
        'mean'         => $termAvg,
        'grade'        => $termGrade,
        'total_points' => $termPts,
    ];

    // ── Selected term — build detailed subject rows ──────────────────────────
    if ($t === $selectedTerm) {
        $prevTermSubjs = $termHistory[$t - 1] ?? [];

        foreach ($termSubjs as $subj => $sd) {
            $classAvg  = $classAverages[$subj] ?? null;
            $diff      = $classAvg !== null ? round($sd['avg'] - $classAvg, 1) : null;
            $prevScore = isset($prevTermSubjs[$subj]) ? $prevTermSubjs[$subj]['avg'] : null;
            $trend     = $prevScore !== null ? round($sd['avg'] - $prevScore, 1) : null;

            $subjectPerformance[] = [
                'subject'     => $subj,
                'is_subsidiary'=> $sd['is_sub'],
                'score'       => $sd['avg'],
                'grade'       => $sd['grade'],
                'points'      => $sd['pts'],
                'achievement' => al_achievement($sd['grade'], $sd['is_sub']),
                'classAverage'=> $classAvg,
                'difference'  => $diff,
                'trend'       => $trend,
                'papers'      => $sd['papers'],
            ];
        }

        // Sort: principal first (by score desc), then subsidiary (by score desc)
        usort($subjectPerformance, function ($a, $b) {
            if ($a['is_subsidiary'] !== $b['is_subsidiary'])
                return $a['is_subsidiary'] <=> $b['is_subsidiary'];
            return $b['score'] <=> $a['score'];
        });
    }
}

// ═══════════════════════════════════════════════════════════════════════════
//  3. OVERALL STATS FOR SELECTED TERM
// ═══════════════════════════════════════════════════════════════════════════

$overallStats = null;
if (!empty($subjectPerformance)) {
    $scores     = array_column($subjectPerformance, 'score');
    $mean       = round(array_sum($scores) / count($scores), 1);
    $grade      = al_grade($mean);
    $totalPts   = array_sum(array_column($subjectPerformance, 'points'));
    $principalPts  = array_sum(array_map(
        fn($s) => $s['is_subsidiary'] ? 0 : $s['points'], $subjectPerformance));
    $subsidiaryPts = array_sum(array_map(
        fn($s) => $s['is_subsidiary'] ? $s['points'] : 0, $subjectPerformance));

    // Class overall average
    $classOverallScores = [];
    foreach ($classAverages as $avg) $classOverallScores[] = $avg;
    $classOverallAvg = !empty($classOverallScores)
        ? round(array_sum($classOverallScores) / count($classOverallScores), 1)
        : null;

    $overallStats = [
        'mean'            => $mean,
        'overall_grade'   => $grade,
        'achievement'     => al_achievement($grade),
        'total_points'    => $totalPts,
        'principal_points'=> $principalPts,
        'subsidiary_points'=> $subsidiaryPts,
        'subjectCount'    => count($subjectPerformance),
        'classOverallAvg' => $classOverallAvg,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
//  4. TERM COMPARISON MATRIX  [subject => [termNum => {score,grade}]]
// ═══════════════════════════════════════════════════════════════════════════

$tcMatrix = [];
foreach ($termHistory as $t => $subjs) {
    foreach ($subjs as $subj => $sd) {
        $tcMatrix[$subj][$t] = ['score' => $sd['avg'], 'grade' => $sd['grade']];
    }
}

// ═══════════════════════════════════════════════════════════════════════════
//  5. STRENGTHS & WEAKNESSES
// ═══════════════════════════════════════════════════════════════════════════

$sw = ['topSubjects' => [], 'weakSubjects' => [], 'mostImproved' => null, 'mostDeclined' => null];

if (!empty($subjectPerformance)) {
    $sorted = $subjectPerformance;
    usort($sorted, fn($a,$b) => $b['score'] <=> $a['score']);
    $sw['topSubjects']  = array_slice($sorted, 0, 3);
    $sw['weakSubjects'] = array_slice(array_reverse($sorted), 0, 3);

    $improved = [];
    $declined = [];
    foreach ($subjectPerformance as $s) {
        if ($s['trend'] === null) continue;
        $entry = ['subject' => $s['subject'], 'is_subsidiary' => $s['is_subsidiary'],
                  'currentScore' => $s['score'], 'grade' => $s['grade']];
        if ($s['trend'] > 0) {
            $improved[] = $entry + ['improvement' => $s['trend']];
        } elseif ($s['trend'] < 0) {
            $declined[] = $entry + ['decline' => abs($s['trend'])];
        }
    }
    usort($improved, fn($a,$b) => $b['improvement'] <=> $a['improvement']);
    usort($declined, fn($a,$b) => $b['decline'] <=> $a['decline']);
    $sw['mostImproved'] = $improved[0] ?? null;
    $sw['mostDeclined'] = $declined[0] ?? null;
}

// ═══════════════════════════════════════════════════════════════════════════
//  6. CLASS RANKING  — ranked by total points (CBC), tie-broken by mean %
// ═══════════════════════════════════════════════════════════════════════════

$classRanking = ['position' => null, 'totalStudents' => 0, 'percentile' => 0];

if (al_tableExists($conn, $selTable)) {
    $esc_class  = mysqli_real_escape_string($conn, $studentClass);
    $esc_stream = mysqli_real_escape_string($conn, $studentStream);
    $r = mysqli_query($conn,
        "SELECT DISTINCT student_id FROM `{$selTable}`
         WHERE class  COLLATE utf8mb4_unicode_ci = '{$esc_class}'
           AND stream COLLATE utf8mb4_unicode_ci = '{$esc_stream}'");
    $rankData = [];
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            $cid   = $row['student_id'];
            $cMrks = al_fetchMarks($conn, $selTable, $cid);
            $totPts = 0; $cMeans = [];
            foreach ($cMrks as $subj => $papers) {
                $avg = al_subjectAvg($papers);
                if ($avg === null) continue;
                $g   = al_grade($avg);
                $totPts += al_points($g, al_isSub($subj));
                $cMeans[] = $avg;
            }
            if (!empty($cMeans)) {
                $rankData[$cid] = [
                    'total_points' => $totPts,
                    'mean'         => array_sum($cMeans) / count($cMeans),
                ];
            }
        }
    }

    uasort($rankData, function ($a, $b) {
        $pd = $b['total_points'] <=> $a['total_points'];
        return $pd !== 0 ? $pd : $b['mean'] <=> $a['mean'];
    });

    $pos = 1;
    foreach ($rankData as $cid => $_) {
        if ($cid === $studentId) {
            $classRanking['position'] = $pos;
            break;
        }
        $pos++;
    }
    $classRanking['totalStudents'] = count($rankData);
    $classRanking['percentile']    = $classRanking['totalStudents'] > 0
        ? round((1 - ($classRanking['position'] / $classRanking['totalStudents'])) * 100, 1)
        : 0;
}

// ═══════════════════════════════════════════════════════════════════════════
//  7. DIAGNOSTIC INSIGHTS
// ═══════════════════════════════════════════════════════════════════════════

$insights = [];
$atRisk   = array_filter($subjectPerformance, fn($s) => !$s['is_subsidiary'] && $s['grade'] === 'E');

if ($overallStats) {
    // Overall performance level
    $type  = $overallStats['mean'] >= 70 ? 'positive' : ($overallStats['mean'] >= 50 ? 'info' : 'negative');
    $insights[] = [
        'type'        => $type,
        'title'       => 'Overall Performance — Term ' . $selectedTerm . ', ' . $year,
        'description' => "Mean " . $overallStats['mean'] . "% · Grade {$overallStats['overall_grade']} · "
                       . $overallStats['achievement'] . " · "
                       . $overallStats['total_points'] . "/17 pts "
                       . "(Principal " . $overallStats['principal_points'] . "/15 · "
                       . "Subsidiary " . $overallStats['subsidiary_points'] . "/2).",
    ];
}

// At-risk
if (!empty($atRisk)) {
    $names = implode(', ', array_column(array_values($atRisk), 'subject'));
    $insights[] = [
        'type'        => 'negative',
        'title'       => '⚠ At-Risk: Grade E in Principal Subject(s)',
        'description' => "Grade E (below minimum CBC competence) in: {$names}. "
                       . "These subjects earn only 1 pt each and need immediate attention.",
    ];
}

// vs class average
if ($overallStats && $overallStats['classOverallAvg'] !== null) {
    $diff = round($overallStats['mean'] - $overallStats['classOverallAvg'], 1);
    $dir  = $diff >= 0 ? 'above' : 'below';
    $type = $diff >= 0 ? 'positive' : 'warning';
    $insights[] = [
        'type'        => $type,
        'title'       => 'vs Class Average',
        'description' => "Student mean {$overallStats['mean']}% vs class average "
                       . "{$overallStats['classOverallAvg']}% — "
                       . abs($diff) . "% {$dir} class average.",
    ];
}

// Trend
if (count($termHistory) >= 2 && $sw['mostImproved']) {
    $insights[] = [
        'type'        => 'positive',
        'title'       => 'Biggest Subject Improvement',
        'description' => $sw['mostImproved']['subject'] . " improved by +"
                       . $sw['mostImproved']['improvement'] . "% from last term → "
                       . $sw['mostImproved']['currentScore'] . "% (Grade "
                       . $sw['mostImproved']['grade'] . ").",
    ];
}
if (count($termHistory) >= 2 && $sw['mostDeclined']) {
    $insights[] = [
        'type'        => 'warning',
        'title'       => 'Biggest Subject Decline',
        'description' => $sw['mostDeclined']['subject'] . " dropped by –"
                       . $sw['mostDeclined']['decline'] . "% from last term → "
                       . $sw['mostDeclined']['currentScore'] . "% (Grade "
                       . $sw['mostDeclined']['grade'] . ").",
    ];
}

// Ranking
if ($classRanking['position']) {
    $pct  = $classRanking['percentile'];
    $type = $pct >= 60 ? 'positive' : ($pct >= 30 ? 'info' : 'warning');
    $insights[] = [
        'type'        => $type,
        'title'       => 'Class Ranking',
        'description' => "Ranked #{$classRanking['position']} of {$classRanking['totalStudents']} students "
                       . "· {$pct}th percentile. "
                       . ($pct >= 75 ? "Top quartile — excellent standing." :
                         ($pct >= 50 ? "Above median." : "Below median — focus needed.")),
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
//  8. PREDICTIVE ANALYSIS
//     Collects ALL historical terms across years for forecasting
// ═══════════════════════════════════════════════════════════════════════════

$pred = null;
$histScores  = [];   // [float] — overall means chronologically
$histPoints  = [];   // [int]   — total points per term
$histLabels  = [];
$histRecords = [];   // For frontend history table

$scanYears = range(max(2024, $year - 2), $year);

foreach ($scanYears as $sy) {
    for ($t = 1; $t <= 3; $t++) {
        // Skip future terms in the current year
        if ($sy === $year && $t > $selectedTerm) continue;

        $tbl = al_table($sy, $t);
        if (!al_tableExists($conn, $tbl)) continue;

        $mks = al_fetchMarks($conn, $tbl, $studentId);
        if (empty($mks)) continue;

        $sMeans = []; $sPts = 0;
        foreach ($mks as $subj => $papers) {
            $avg = al_subjectAvg($papers);
            if ($avg === null) continue;
            $g    = al_grade($avg);
            $isSub = al_isSub($subj);
            $sMeans[] = $avg;
            $sPts    += al_points($g, $isSub);
        }
        if (empty($sMeans)) continue;

        $tMean  = round(array_sum($sMeans) / count($sMeans), 1);
        $tGrade = al_grade($tMean);
        $histScores[]  = $tMean;
        $histPoints[]  = $sPts;
        $histLabels[]  = "T{$t} '{$sy}";
        $histRecords[] = [
            'label'        => "Term {$t}, {$sy}",
            'mean'         => $tMean,
            'grade'        => $tGrade,
            'total_points' => $sPts,
        ];
    }
}

if (count($histScores) >= 2) {
    // Statistical models on mean %
    $reg    = al_linearRegression($histScores);
    $ma     = al_movingAvg($histScores, min(3, count($histScores)));
    $exp    = al_expSmoothing($histScores, 0.4);
    $stdDev = al_stdDev($histScores);
    $n      = count($histScores);

    // Clamp helper
    $clamp = fn($v) => round(max(0, min(100, $v)), 1);

    // Three-term predictions
    $linPreds = [];
    $maPreds  = [];
    $expPreds = [];
    $recentTrend = $histScores[$n-1] - $histScores[$n-2];

    for ($i = 1; $i <= 3; $i++) {
        $linPreds[] = $clamp($reg ? $reg['slope'] * ($n + $i) + $reg['intercept'] : end($histScores));
        $maPreds[]  = $clamp($ma + $recentTrend * $i * 0.5);
        $expPreds[] = $clamp($exp + ($reg ? $reg['slope'] * $i * 0.7 : 0));
    }

    $ensemble = [];
    for ($i = 0; $i < 3; $i++) {
        $ensemble[] = round($linPreds[$i] * 0.5 + $maPreds[$i] * 0.3 + $expPreds[$i] * 0.2, 1);
    }

    $baseline    = $ensemble[0];
    $optimistic  = $clamp($baseline + $stdDev * 0.5);
    $pessimistic = $clamp($baseline - $stdDev * 0.5);
    $ci          = al_confidenceInterval($histScores, $baseline);

    // Points prediction (same models on points series)
    $ptsReg    = al_linearRegression($histPoints);
    $ptsEnsemble = [];
    for ($i = 1; $i <= 3; $i++) {
        $pv = $ptsReg ? $ptsReg['slope'] * ($n + $i) + $ptsReg['intercept'] : end($histPoints);
        $ptsEnsemble[] = (int) round(max(0, min(17, $pv)));
    }

    $predGrade = al_grade($baseline);
    $predPts   = $ptsEnsemble[0];
    $slope     = $reg ? $reg['slope'] : 0;
    $confidence = $reg ? $reg['confidence'] : 0;

    // ── Risk assessment ──────────────────────────────────────────────────────
    $riskScore   = 0;
    $riskFactors = [];
    $latest      = end($histScores);
    $latestPts   = end($histPoints);

    if ($latest < 50) {
        $riskScore += 40;
        $riskFactors[] = ['factor' => 'Below Minimum Competence', 'severity' => 'Critical',
            'impact' => 40, 'description' => "Current mean {$latest}% is below the D-grade (50%) threshold — no principal subject earns >1pt at this level."];
    } elseif ($latest < 60) {
        $riskScore += 20;
        $riskFactors[] = ['factor' => 'Borderline Competence', 'severity' => 'High',
            'impact' => 20, 'description' => "Mean {$latest}% is at basic (D) level — at risk of slipping below competence threshold."];
    }
    if ($slope < -3) {
        $riskScore += 35;
        $riskFactors[] = ['factor' => 'Steep Decline', 'severity' => 'Critical',
            'impact' => 35, 'description' => "Performance declining ~" . abs($slope) . "% per term. Immediate intervention critical."];
    } elseif ($slope < -1.5) {
        $riskScore += 20;
        $riskFactors[] = ['factor' => 'Negative Trend', 'severity' => 'High',
            'impact' => 20, 'description' => "Steady downward trend of " . abs($slope) . "% per term."];
    }
    if ($stdDev > 15) {
        $riskScore += 15;
        $riskFactors[] = ['factor' => 'High Inconsistency', 'severity' => 'Medium',
            'impact' => 15, 'description' => "Score variation ±" . round($stdDev, 1) . "% suggests inconsistent preparation."];
    }
    if ($baseline < 50) {
        $riskScore += 25;
        $riskFactors[] = ['factor' => 'Predicted Below Competence', 'severity' => 'Critical',
            'impact' => 25, 'description' => "Next-term forecast {$baseline}% is below the 50% (D grade) CBC competence floor."];
    }
    if ($latestPts < 8) {
        $riskScore += 15;
        $riskFactors[] = ['factor' => 'Low Points Score', 'severity' => 'High',
            'impact' => 15, 'description' => "Only {$latestPts}/17 points — student is not yet demonstrating sufficient CBC competence."];
    }

    $riskLevel = $riskScore >= 70 ? 'Critical' : ($riskScore >= 45 ? 'High' : ($riskScore >= 25 ? 'Medium' : 'Low'));
    $riskDesc  = match ($riskLevel) {
        'Critical' => 'Immediate structured intervention required. Inform parents, class teacher, and HOD.',
        'High'     => 'Significant academic support needed. Set fortnightly review targets.',
        'Medium'   => 'Monitor closely and provide targeted support in weak subjects.',
        'Low'      => 'Student performing adequately. Encourage continued effort.',
    };

    // ── Success metrics ──────────────────────────────────────────────────────
    $baseProbability = min(100, max(0, ($latest / 100) * 100
        + ($slope > 5 ? 15 : ($slope > 2 ? 8 : ($slope < -5 ? -15 : ($slope < -2 ? -8 : 0))))
    ));
    $gradeAProbability = min(100, max(0, $latest >= 70 ? $baseProbability + 10 : $baseProbability - 30));

    // Points to next grade
    $nextGrade         = null;
    $improvementNeeded = 0;
    $termsToImprove    = null;
    $cbcThresholds = ['E' => 50, 'D' => 60, 'C' => 70, 'B' => 80, 'A' => 100];
    foreach ($cbcThresholds as $g => $thresh) {
        if ($latest < $thresh) {
            $nextGrade         = $g;
            $improvementNeeded = round($thresh - $latest, 1);
            if ($slope > 0) $termsToImprove = min(10, (int) ceil($improvementNeeded / $slope));
            break;
        }
    }

    // ── Chart data ───────────────────────────────────────────────────────────
    $allLabels = array_merge($histLabels, ['Next Term', 'Term+2', 'Term+3']);
    $pad       = fn($arr, $n) => array_merge($arr, array_fill(0, $n, null));
    $chartData = [
        'labels'         => $allLabels,
        'historical'     => $pad($histScores, 3),
        'predicted'      => array_merge(array_fill(0, $n, null), $ensemble),
        'optimistic'     => array_merge(array_fill(0, $n, null), [$optimistic, $clamp($optimistic+2), $clamp($optimistic+4)]),
        'pessimistic'    => array_merge(array_fill(0, $n, null), [$pessimistic, $clamp($pessimistic-2), $clamp($pessimistic-4)]),
        'pointsHistorical'=> $pad($histPoints, 3),
        'pointsPredicted' => array_merge(array_fill(0, $n, null), $ptsEnsemble),
    ];

    // ── Predictive insights ──────────────────────────────────────────────────
    $predInsights = [];
    if ($slope > 3) {
        $predInsights[] = ['type' => 'positive', 'title' => '📈 Strong Upward Trajectory',
            'description' => "Mean % improving ~{$slope}pts per term. Model confidence {$confidence}%. Maintain this momentum."];
    } elseif ($slope < -3) {
        $predInsights[] = ['type' => 'negative', 'title' => '📉 Significant Decline',
            'description' => "Mean % declining ~" . abs($slope) . "pts per term. Predicted next term: {$baseline}%. Urgent action needed."];
    } elseif (abs($slope) <= 1.5) {
        $predInsights[] = ['type' => 'info', 'title' => '➡ Stable Performance',
            'description' => "Trend is flat (slope {$slope}%/term). Performance is consistent but not improving."];
    }
    if ($stdDev < 8) {
        $predInsights[] = ['type' => 'positive', 'title' => '✔ Highly Consistent',
            'description' => "Scores vary by only ±" . round($stdDev, 1) . "% — reliable preparation and understanding."];
    } elseif ($stdDev > 15) {
        $predInsights[] = ['type' => 'warning', 'title' => '⚠ Inconsistent Performance',
            'description' => "Score variation ±" . round($stdDev, 1) . "% is high. Inconsistent preparation is limiting growth."];
    }
    if ($baseline >= 80) {
        $predInsights[] = ['type' => 'positive', 'title' => '🏆 Predicted Grade A',
            'description' => "Forecast {$baseline}% → Grade A (5 pts) next term. Exceptional trajectory."];
    } elseif ($baseline < 50) {
        $predInsights[] = ['type' => 'negative', 'title' => '🔴 Predicted Below Competence',
            'description' => "Forecast {$baseline}% → Grade E. Student risks losing points in principal subjects. Immediate intervention needed."];
    }

    // ── Recommendations ──────────────────────────────────────────────────────
    $recommendations = [];
    if ($riskLevel === 'Critical' || $riskLevel === 'High') {
        $recommendations[] = [
            'priority'  => $riskLevel,
            'category'  => 'Immediate Action',
            'title'     => $riskLevel === 'Critical' ? 'Emergency Intervention Required' : 'Intensive Support Needed',
            'actions'   => [
                'Schedule urgent parent-teacher-student conference',
                'Design an individualized improvement plan per subject',
                'Arrange weekly one-on-one sessions with subject teachers',
                'Set fortnightly measurable CBC-points targets',
                'Enroll in peer mentoring or study group programme',
            ],
        ];
    }
    if ($stdDev > 15) {
        $recommendations[] = [
            'priority' => 'Medium',
            'category' => 'Study Habits',
            'title'    => 'Build Consistency',
            'actions'  => [
                'Create and strictly follow a daily study timetable',
                'Review class notes within 24 hours of each lesson',
                'Use spaced repetition for key A-Level concepts',
                'Track your own scores each term to stay accountable',
            ],
        ];
    }
    // Weakest principal subject
    $weakPrin = array_filter($subjectPerformance, fn($s) => !$s['is_subsidiary'] && $s['score'] < 60);
    foreach (array_slice(array_values($weakPrin), 0, 2) as $wp) {
        $recommendations[] = [
            'priority' => $wp['grade'] === 'E' ? 'Critical' : 'High',
            'category' => 'Subject Remediation',
            'title'    => $wp['subject'] . ' — ' . $wp['achievement'],
            'actions'  => [
                "Score: {$wp['score']}% (Grade {$wp['grade']}, {$wp['points']}/5 pts)",
                "Attend all extra classes for {$wp['subject']}",
                "Rework past papers from the last 3 terms",
                "Identify weak papers/topics and target them weekly",
                "Ask subject teacher for additional practice exercises",
            ],
        ];
    }
    if ($slope > 2) {
        $recommendations[] = [
            'priority' => 'Low',
            'category' => 'Enrichment',
            'title'    => 'Maintain Momentum',
            'actions'  => [
                'Continue current approach — the trend is positive!',
                'Challenge yourself with advanced exam-style questions',
                'Help peers in subjects you excel at (peer teaching reinforces understanding)',
                'Set a personal target of reaching the next grade band this term',
            ],
        ];
    }

    // ── Intervention plan (only for at-risk students) ────────────────────────
    $interventionPlan = [];
    if (in_array($riskLevel, ['Critical', 'High'])) {
        $interventionPlan = [
            [
                'phase'      => 'Weeks 1–2',
                'focus'      => 'Diagnostic Assessment',
                'activities' => [
                    'Identify specific weak topics per subject using term test papers',
                    'Conduct a diagnostic test to pinpoint knowledge gaps',
                    'Hold a 1-on-1 student interview to understand personal challenges',
                    'Inform class teacher and relevant HODs',
                ],
            ],
            [
                'phase'      => 'Weeks 3–6',
                'focus'      => 'Intensive Remediation',
                'activities' => [
                    'Daily 30-minute focused practice in weakest principal subject',
                    'Weekly short quiz to track progress on targeted topics',
                    'Use UNEB past papers for exam-technique practice',
                    'Fortnightly parent update on measurable progress',
                ],
            ],
            [
                'phase'      => 'Weeks 7+',
                'focus'      => 'Consolidation & Exam Readiness',
                'activities' => [
                    'Full past-paper practice under timed conditions',
                    'Peer study sessions with higher-performing classmates',
                    'Monthly formal review of CBC points target vs actual',
                    'Re-assess risk level at start of next term',
                ],
            ],
        ];
    }

    $pred = [
        'predictions' => [
            'linear'             => $linPreds[0],
            'movingAverage'      => round($ma + $recentTrend * 0.5, 1),
            'exponential'        => $expPreds[0],
            'ensemble'           => $baseline,
            'confidence'         => $confidence,
            'confidenceInterval' => $ci,
            'scenarios'          => ['optimistic' => $optimistic, 'realistic' => $baseline, 'pessimistic' => $pessimistic],
            'expectedGrade'      => $predGrade,
            'expectedPoints'     => $predPts,
            'expectedAchievement'=> al_achievement($predGrade),
            'trendDirection'     => $slope > 1.5 ? 'Improving' : ($slope < -1.5 ? 'Declining' : 'Stable'),
            'trendSlope'         => abs($slope),
            'nextGrade'          => $nextGrade,
        ],
        'historicalPerformance' => $histRecords,
        'chartData'             => $chartData,
        'riskAssessment'        => [
            'level'       => $riskLevel,
            'score'       => min(100, $riskScore),
            'description' => $riskDesc,
            'factors'     => $riskFactors,
        ],
        'successMetrics' => [
            'passingProbability'  => round($baseProbability),
            'gradeAProbability'   => round($gradeAProbability),
            'improvementNeeded'   => $improvementNeeded,
            'nextGrade'           => $nextGrade,
            'termsToImprovement'  => $termsToImprove,
            'confidenceInterval'  => $ci,
        ],
        'insights'         => $predInsights,
        'recommendations'  => $recommendations,
        'interventionPlan' => $interventionPlan,
    ];
} else {
    $pred = ['error' => 'At least 2 terms of data are required for forecasting.'];
}

// ═══════════════════════════════════════════════════════════════════════════
//  FINAL RESPONSE
// ═══════════════════════════════════════════════════════════════════════════

echo json_encode([
    'studentInfo'        => $studentInfo,
    'overallStats'       => $overallStats,
    'subjectPerformance' => $subjectPerformance,
    'performanceTrend'   => $performanceTrend,
    'termHistory'        => $tcMatrix,
    'strengthsWeaknesses'=> $sw,
    'classRanking'       => $classRanking,
    'insights'           => $insights,
    'predictive'         => $pred,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

mysqli_close($conn);
