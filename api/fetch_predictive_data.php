<?php
require_once '../auth.php';
require_once '../conn.php';
header('Content-Type: application/json');

// --- HELPER FUNCTIONS ---
function getMarksTableName($year, $term_num)
{
    $romans = [1 => 'i', 2 => 'ii', 3 => 'iii'];
    $term_roman = $romans[$term_num] ?? 'i';
    return "{$year}_{$term_roman}_olevel";
}

function convertToGrade($marks)
{
    if (!is_numeric($marks)) return '-';
    $marks = floatval($marks);
    if ($marks >= 85) return 'A';
    if ($marks >= 70) return 'B';
    if ($marks >= 50) return 'C';
    if ($marks >= 40) return 'D';
    return 'E';
}

function getAchievementLevel($marks)
{
    if (!is_numeric($marks)) return '-';
    $marks = floatval($marks);
    if ($marks >= 85) return 'Exceptional';
    if ($marks >= 70) return 'Outstanding';
    if ($marks >= 50) return 'Satisfactory';
    if ($marks >= 40) return 'Basic';
    return 'Elementary';
}

function getAOIColumnsFromMarks($conn, $tableName, $subject)
{
    // Check if table exists first
    $check = mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'");
    if (!$check || mysqli_num_rows($check) == 0) {
        return 2; // Default fallback
    }

    $sql = "SELECT DISTINCT topic_id FROM `{$tableName}` WHERE subject = ? AND topic_id != 'EOT' ORDER BY topic_id";
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        error_log("Failed to prepare statement in getAOIColumnsFromMarks: " . mysqli_error($conn));
        return 2; // Default fallback
    }

    mysqli_stmt_bind_param($stmt, "s", $subject);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $count = 0;
    while (mysqli_fetch_assoc($result)) {
        $count++;
    }
    mysqli_stmt_close($stmt);

    return max(1, $count); // At least 1 AOI column
}

// UPDATED: calculateFinalScore now needs $aoi_columns parameter
function calculateFinalScore($marks, $aoi_columns)
{
    $aoi_marks = array_filter($marks, fn($key) => $key !== 'EOT', ARRAY_FILTER_USE_KEY);
    $eot_mark = $marks['EOT'] ?? null;
    $finalScore = null;

    // Filter out null/empty marks
    $valid_aoi = array_filter($aoi_marks, function ($mark) {
        return $mark !== null && $mark !== '' && $mark !== '-';
    });

    $aoi_sum = !empty($valid_aoi) ? array_sum($valid_aoi) : null;
    // CRITICAL: Divide by total AOI columns, not just valid marks
    $aoi_avg = $aoi_sum !== null ? $aoi_sum / $aoi_columns : null;

    $has_aoi = $aoi_avg !== null;
    $has_eot = $eot_mark !== null && $eot_mark !== '' && $eot_mark !== '-';
    $aoi_only = $has_aoi && !$has_eot;

    if ($has_aoi) {
        if ($aoi_only) {
            $finalScore = ($aoi_avg * 20) / 3;
        } else if ($has_eot) {
            $aoi_20_percent = ($aoi_avg / 3) * 20;
            $eot_80_percent = ($eot_mark / 100) * 80;
            $finalScore = $aoi_20_percent + $eot_80_percent;
        } else {
            $finalScore = ($aoi_avg / 3) * 20;
        }
    } else if ($has_eot) {
        $finalScore = $eot_mark;
    }

    return $finalScore;
}
// --- ADVANCED STATISTICAL ENGINE ---
class AdvancedPredictiveAnalytics
{

    // Linear Regression with R-squared
    public static function linearRegression($data)
    {
        $n = count($data);
        if ($n < 2) return null;

        $sumX = $sumY = $sumXY = $sumX2 = 0;
        for ($i = 0; $i < $n; $i++) {
            $x = $i + 1;
            $y = $data[$i];
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        $denominator = ($n * $sumX2 - $sumX * $sumX);
        if ($denominator == 0) return null;

        $slope = ($n * $sumXY - $sumX * $sumY) / $denominator;
        $intercept = ($sumY - $slope * $sumX) / $n;

        // Calculate R-squared (goodness of fit)
        $meanY = $sumY / $n;
        $ssTotal = array_sum(array_map(fn($y) => pow($y - $meanY, 2), $data));
        $ssResidual = 0;
        for ($i = 0; $i < $n; $i++) {
            $predicted = $slope * ($i + 1) + $intercept;
            $ssResidual += pow($data[$i] - $predicted, 2);
        }
        $rSquared = $ssTotal > 0 ? 1 - ($ssResidual / $ssTotal) : 0;

        return [
            'slope' => $slope,
            'intercept' => $intercept,
            'rSquared' => $rSquared,
            'confidence' => min(100, max(0, $rSquared * 100))
        ];
    }

    // Moving Average (smoother predictions)
    public static function movingAverage($data, $window = 3)
    {
        if (count($data) < $window) return end($data);
        $recent = array_slice($data, -$window);
        return array_sum($recent) / count($recent);
    }

    // Exponential Smoothing
    public static function exponentialSmoothing($data, $alpha = 0.3)
    {
        if (empty($data)) return null;
        $forecast = $data[0];
        foreach ($data as $value) {
            $forecast = $alpha * $value + (1 - $alpha) * $forecast;
        }
        return $forecast;
    }

    // Standard Deviation
    public static function standardDeviation($data)
    {
        if (count($data) < 2) return 0;
        $mean = array_sum($data) / count($data);
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $data)) / (count($data) - 1);
        return sqrt($variance);
    }

    // Calculate Confidence Interval
    public static function confidenceInterval($data, $prediction, $confidenceLevel = 95)
    {
        $stdDev = self::standardDeviation($data);
        $n = count($data);

        // Z-score for 95% confidence is approximately 1.96
        $zScore = $confidenceLevel == 95 ? 1.96 : 1.645;
        $marginOfError = $zScore * ($stdDev / sqrt($n));

        return [
            'lower' => max(0, $prediction - $marginOfError),
            'upper' => min(100, $prediction + $marginOfError)
        ];
    }

    // Calculate success probability (probability of passing)
    public static function calculateSuccessProbability($currentScore, $trend)
    {
        $baseProbability = ($currentScore / 100) * 100;

        if ($trend > 5) {
            $baseProbability += 15; // Strong improvement trend
        } elseif ($trend > 2) {
            $baseProbability += 8;  // Moderate improvement
        } elseif ($trend < -5) {
            $baseProbability -= 15; // Strong decline
        } elseif ($trend < -2) {
            $baseProbability -= 8;  // Moderate decline
        }

        return max(0, min(100, round($baseProbability)));
    }
}

// --- PARAMETER VALIDATION ---
if (!isset($_GET['student_id'], $_GET['subject'])) {
    echo json_encode(['error' => 'Missing student or subject parameter.']);
    exit;
}

$studentId = $_GET['student_id'];
$subject = $_GET['subject'];
$currentYear = date('Y');

$response = [
    'studentInfo' => null,
    'subjectInfo' => [
        'name' => $subject,
        'isCompulsory' => false
    ],
    'historicalPerformance' => [],
    'predictions' => [
        'linear' => null,
        'movingAverage' => null,
        'exponential' => null,
        'ensemble' => null,  // Weighted average of all methods
        'scenarios' => [
            'optimistic' => null,
            'realistic' => null,
            'pessimistic' => null
        ]
    ],
    'chartData' => [],
    'riskAssessment' => [
        'level' => 'Unknown',
        'score' => 0,
        'factors' => []
    ],
    'classComparison' => [
        'studentAverage' => 0,
        'classAverage' => 0,
        'percentile' => 0,
        'position' => null
    ],
    'insights' => [],
    'recommendations' => [],
    'interventionPlan' => [],
    'successMetrics' => [
        'passingProbability' => 0,
        'gradeAProbability' => 0,
        'improvementNeeded' => 0
    ]
];

// 1. Get Student Info
$stmtStudent = mysqli_prepare($conn, "SELECT student_id, first_name, last_name, current_class, stream, section FROM students WHERE student_id = ?");
mysqli_stmt_bind_param($stmtStudent, "s", $studentId);
mysqli_stmt_execute($stmtStudent);
$resultStudent = mysqli_stmt_get_result($stmtStudent);
if (!($response['studentInfo'] = mysqli_fetch_assoc($resultStudent))) {
    echo json_encode(['error' => 'Student not found.']);
    exit;
}
mysqli_stmt_close($stmtStudent);

$studentClass = $response['studentInfo']['current_class'];
$studentStream = $response['studentInfo']['stream'];

// Get subject compulsory status
$sqlSubject = "SELECT compulsory FROM subjects WHERE subj_name = ? LIMIT 1";
$stmtSubject = mysqli_prepare($conn, $sqlSubject);
mysqli_stmt_bind_param($stmtSubject, "s", $subject);
mysqli_stmt_execute($stmtSubject);
$resultSubject = mysqli_stmt_get_result($stmtSubject);
if ($rowSubject = mysqli_fetch_assoc($resultSubject)) {
    $response['subjectInfo']['isCompulsory'] = ($rowSubject['compulsory'] == 1);
}
mysqli_stmt_close($stmtSubject);

// 2. Collect Historical Performance Data
$historicalScores = [];
$historicalAOI = [];
$historicalEOT = [];
$labels = [];
$termDetails = [];

for ($year = 2025; $year <= $currentYear; $year++) {
    for ($termNum = 1; $termNum <= 3; $termNum++) {
        $tableName = getMarksTableName($year, $termNum);
        if (mysqli_num_rows(mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'")) == 0) continue;

        // ✅ GET AOI COLUMNS COUNT FOR THIS TERM
        $aoi_columns = getAOIColumnsFromMarks($conn, $tableName, $subject);

        $stmtMarks = mysqli_prepare($conn, "SELECT topic_id, marks FROM `{$tableName}` WHERE student_id = ? AND subject = ?");
        mysqli_stmt_bind_param($stmtMarks, "ss", $studentId, $subject);
        mysqli_stmt_execute($stmtMarks);
        $resultMarks = mysqli_stmt_get_result($stmtMarks);

        $termMarks = [];
        while ($row = mysqli_fetch_assoc($resultMarks)) {
            $termMarks[$row['topic_id']] = $row['marks'];
        }
        mysqli_stmt_close($stmtMarks);

        if (!empty($termMarks)) {
            // Calculate AOI and EOT separately
            $aoi_marks = array_filter($termMarks, fn($key) => $key !== 'EOT', ARRAY_FILTER_USE_KEY);
            $eot_mark = $termMarks['EOT'] ?? null;

            // ✅ Calculate AOI average the SAME way as in final score calculation
            $valid_aoi = array_filter($aoi_marks, fn($m) => $m !== null && $m !== '' && $m !== '-');
            $aoi_sum = !empty($valid_aoi) ? array_sum($valid_aoi) : null;
            $aoiAvg = $aoi_sum !== null ? $aoi_sum / $aoi_columns : null; // ← DIVIDE BY TOTAL COLUMNS

            // ✅ PASS AOI_COLUMNS TO CALCULATION
            $finalScore = calculateFinalScore($termMarks, $aoi_columns);

            if ($finalScore !== null) {
                $historicalScores[] = round($finalScore, 1);
                $historicalAOI[] = $aoiAvg !== null ? round($aoiAvg, 2) : null; // ← Now consistent!
                $historicalEOT[] = $eot_mark !== null ? round($eot_mark, 1) : null;
                $labels[] = "T{$termNum} '{$year}";

                $termDetails[] = [
                    'term' => "Term {$termNum}",
                    'year' => $year,
                    'score' => round($finalScore, 1),
                    'grade' => convertToGrade($finalScore),
                    'aoiAvg' => $aoiAvg !== null ? round($aoiAvg, 2) : null,
                    'eotScore' => $eot_mark !== null ? round($eot_mark, 1) : null
                ];
            }
        }
    }
}
if (count($historicalScores) < 2) {
    echo json_encode(['error' => 'Not enough historical data (at least 2 terms required) for prediction.']);
    exit;
}

$response['historicalPerformance'] = $termDetails;

// 3. ADVANCED PREDICTIVE ANALYSIS
$regression = AdvancedPredictiveAnalytics::linearRegression($historicalScores);
$movingAvg = AdvancedPredictiveAnalytics::movingAverage($historicalScores, 3);
$exponential = AdvancedPredictiveAnalytics::exponentialSmoothing($historicalScores, 0.4);
$stdDev = AdvancedPredictiveAnalytics::standardDeviation($historicalScores);

// Linear regression prediction
$linearPredictions = [];
if ($regression) {
    $n = count($historicalScores);
    for ($i = 1; $i <= 3; $i++) {
        $pred = $regression['slope'] * ($n + $i) + $regression['intercept'];
        $linearPredictions[] = max(0, min(100, round($pred, 1)));
    }
}

// Moving average prediction (assumes continuation of recent trend)
$maPredictions = [];
$lastMA = $movingAvg;
$recentTrend = count($historicalScores) >= 2 ?
    ($historicalScores[count($historicalScores) - 1] - $historicalScores[count($historicalScores) - 2]) : 0;
for ($i = 1; $i <= 3; $i++) {
    $pred = $lastMA + ($recentTrend * $i * 0.5); // Dampened trend
    $maPredictions[] = max(0, min(100, round($pred, 1)));
}

// Exponential smoothing prediction
$expPredictions = [];
$lastExp = $exponential;
for ($i = 1; $i <= 3; $i++) {
    $pred = $lastExp + ($regression['slope'] * $i * 0.7); // Use trend from regression
    $expPredictions[] = max(0, min(100, round($pred, 1)));
}

// Ensemble prediction (weighted average)
$ensemblePredictions = [];
for ($i = 0; $i < 3; $i++) {
    $weighted = ($linearPredictions[$i] * 0.5) +
        ($maPredictions[$i] * 0.3) +
        ($expPredictions[$i] * 0.2);
    $ensemblePredictions[] = round($weighted, 1);
}

// Scenario predictions
$baselinePrediction = $ensemblePredictions[0];
$optimisticPrediction = min(100, $baselinePrediction + ($stdDev * 0.5));
$pessimisticPrediction = max(0, $baselinePrediction - ($stdDev * 0.5));

$response['predictions'] = [
    'linear' => $linearPredictions[0],
    'movingAverage' => $maPredictions[0],
    'exponential' => $expPredictions[0],
    'ensemble' => $ensemblePredictions[0],
    'confidence' => round($regression['confidence']),
    'scenarios' => [
        'optimistic' => round($optimisticPrediction, 1),
        'realistic' => $ensemblePredictions[0],
        'pessimistic' => round($pessimisticPrediction, 1)
    ],
    'expectedGrade' => convertToGrade($ensemblePredictions[0]),
    'trendSlope' => round($regression['slope'], 2),
    'trendDirection' => $regression['slope'] > 1.5 ? 'Improving' : ($regression['slope'] < -1.5 ? 'Declining' : 'Stable')
];

// Calculate confidence intervals
$confidenceInterval = AdvancedPredictiveAnalytics::confidenceInterval(
    $historicalScores,
    $ensemblePredictions[0]
);
$response['predictions']['confidenceInterval'] = $confidenceInterval;

// 4. CHART DATA PREPARATION
$response['chartData'] = [
    'labels' => array_merge($labels, ['Next Term', 'Term +2', 'Term +3']),
    'historical' => array_merge($historicalScores, [null, null, null]),
    'predicted' => array_merge(array_fill(0, count($historicalScores), null), $ensemblePredictions),
    'optimistic' => array_merge(
        array_fill(0, count($historicalScores), null),
        [$optimisticPrediction, min(100, $optimisticPrediction + 2), min(100, $optimisticPrediction + 4)]
    ),
    'pessimistic' => array_merge(
        array_fill(0, count($historicalScores), null),
        [$pessimisticPrediction, max(0, $pessimisticPrediction - 2), max(0, $pessimisticPrediction - 4)]
    ),
    'aoiTrend' => $historicalAOI,
    'eotTrend' => $historicalEOT
];

// 5. CLASS COMPARISON
$classScores = [];

// Find the most recent term that has data (instead of always using Term 1)
$mostRecentYear = $currentYear;
$mostRecentTerm = 1;
for ($t = 3; $t >= 1; $t--) {
    $checkTable = getMarksTableName($currentYear, $t);
    if (mysqli_num_rows(mysqli_query($conn, "SHOW TABLES LIKE '{$checkTable}'")) > 0) {
        $mostRecentTerm = $t;
        break;
    }
}

$tableName = getMarksTableName($mostRecentYear, $mostRecentTerm);
if (mysqli_num_rows(mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'")) > 0) {
    // ✅ GET AOI COLUMNS FOR CLASS COMPARISON
    $aoi_columns_class = getAOIColumnsFromMarks($conn, $tableName, $subject);

    $sqlClass = "
        SELECT s.student_id, m.topic_id, m.marks
        FROM students s
        JOIN `{$tableName}` m ON s.student_id = m.student_id
        WHERE s.current_class = ? AND s.stream = ? AND m.subject = ?
    ";
    $stmtClass = mysqli_prepare($conn, $sqlClass);
    mysqli_stmt_bind_param($stmtClass, "sss", $studentClass, $studentStream, $subject);
    mysqli_stmt_execute($stmtClass);
    $resultClass = mysqli_stmt_get_result($stmtClass);

    $classStudentMarks = [];
    while ($row = mysqli_fetch_assoc($resultClass)) {
        $sid = $row['student_id'];
        if (!isset($classStudentMarks[$sid])) {
            $classStudentMarks[$sid] = [];
        }
        $classStudentMarks[$sid][$row['topic_id']] = $row['marks'];
    }
    mysqli_stmt_close($stmtClass);

    foreach ($classStudentMarks as $sid => $marks) {
        // ✅ PASS AOI_COLUMNS TO CALCULATION
        $score = calculateFinalScore($marks, $aoi_columns_class);
        if ($score !== null) {
            $classScores[$sid] = $score;
        }
    }


    if (!empty($classScores)) {
        $response['classComparison']['classAverage'] = round(array_sum($classScores) / count($classScores), 1);
        $response['classComparison']['studentAverage'] = end($historicalScores);

        // Calculate position and percentile
        arsort($classScores);
        $position = 1;
        foreach ($classScores as $sid => $score) {
            if ($sid === $studentId) {
                $response['classComparison']['position'] = $position;
                break;
            }
            $position++;
        }

        $total = count($classScores);
        $response['classComparison']['percentile'] = $total > 0 ?
            round((1 - ($response['classComparison']['position'] / $total)) * 100, 1) : 0;
    }
}

// 6. RISK ASSESSMENT
$riskScore = 0;
$riskFactors = [];
$latestScore = end($historicalScores);

// Factor 1: Current performance level
if ($latestScore < 40) {
    $riskScore += 40;
    $riskFactors[] = [
        'factor' => 'Critical Performance',
        'description' => 'Current score is below passing level',
        'severity' => 'Critical',
        'impact' => 40
    ];
} elseif ($latestScore < 50) {
    $riskScore += 30;
    $riskFactors[] = [
        'factor' => 'Below Pass Mark',
        'description' => 'Current score is below 50%',
        'severity' => 'High',
        'impact' => 30
    ];
} elseif ($latestScore < 60) {
    $riskScore += 15;
    $riskFactors[] = [
        'factor' => 'Borderline Performance',
        'description' => 'Score is marginally above passing',
        'severity' => 'Medium',
        'impact' => 15
    ];
}

// Factor 2: Performance trend
if ($regression['slope'] < -3) {
    $riskScore += 35;
    $riskFactors[] = [
        'factor' => 'Steep Decline',
        'description' => 'Performance declining rapidly',
        'severity' => 'Critical',
        'impact' => 35
    ];
} elseif ($regression['slope'] < -1.5) {
    $riskScore += 20;
    $riskFactors[] = [
        'factor' => 'Negative Trend',
        'description' => 'Performance showing downward trend',
        'severity' => 'High',
        'impact' => 20
    ];
}

// Factor 3: Consistency (standard deviation)
if ($stdDev > 20) {
    $riskScore += 20;
    $riskFactors[] = [
        'factor' => 'High Inconsistency',
        'description' => 'Scores vary significantly between terms',
        'severity' => 'Medium',
        'impact' => 20
    ];
} elseif ($stdDev > 15) {
    $riskScore += 10;
    $riskFactors[] = [
        'factor' => 'Moderate Inconsistency',
        'description' => 'Some variation in performance',
        'severity' => 'Low',
        'impact' => 10
    ];
}

// Factor 4: Prediction below passing
if ($ensemblePredictions[0] < 50) {
    $riskScore += 25;
    $riskFactors[] = [
        'factor' => 'Predicted Failure',
        'description' => 'Next term prediction is below pass mark',
        'severity' => 'Critical',
        'impact' => 25
    ];
}

// Factor 5: Below class average
if (!empty($response['classComparison']['classAverage'])) {
    $diff = $latestScore - $response['classComparison']['classAverage'];
    if ($diff < -15) {
        $riskScore += 15;
        $riskFactors[] = [
            'factor' => 'Significantly Below Class Average',
            'description' => "Performing {$diff} points below peers",
            'severity' => 'High',
            'impact' => 15
        ];
    } elseif ($diff < -10) {
        $riskScore += 8;
        $riskFactors[] = [
            'factor' => 'Below Class Average',
            'description' => "Performing {$diff} points below peers",
            'severity' => 'Medium',
            'impact' => 8
        ];
    }
}

$response['riskAssessment'] = [
    'level' => $riskScore >= 70 ? 'Critical' : ($riskScore >= 45 ? 'High' : ($riskScore >= 25 ? 'Medium' : 'Low')),
    'score' => $riskScore,
    'factors' => $riskFactors,
    'description' => $riskScore >= 70 ? 'Immediate intervention required' : ($riskScore >= 45 ? 'Significant support needed' : ($riskScore >= 25 ? 'Monitor closely and provide support' :
        'Student performing adequately'))
];

// 7. SUCCESS METRICS
$passingProbability = AdvancedPredictiveAnalytics::calculateSuccessProbability(
    $latestScore,
    $regression['slope']
);

$gradeAProbability = $latestScore >= 70 ?
    min(100, $passingProbability + 10) :
    max(0, $passingProbability - 30);

$improvementNeeded = max(0, 50 - $ensemblePredictions[0]);

$response['successMetrics'] = [
    'passingProbability' => $passingProbability,
    'gradeAProbability' => round($gradeAProbability),
    'improvementNeeded' => round($improvementNeeded, 1),
    'pointsToNextGrade' => 0,
    'termsToImprovement' => 0
];

// Calculate points to next grade
$currentGrade = convertToGrade($latestScore);
$gradeThresholds = ['E' => 40, 'D' => 50, 'C' => 70, 'B' => 85, 'A' => 100];
foreach ($gradeThresholds as $grade => $threshold) {
    if ($latestScore < $threshold) {
        $response['successMetrics']['pointsToNextGrade'] = round($threshold - $latestScore, 1);
        $response['successMetrics']['nextGrade'] = $grade;

        // Estimate terms needed if current trend continues
        if ($regression['slope'] > 0) {
            $termsNeeded = ceil(($threshold - $latestScore) / $regression['slope']);
            $response['successMetrics']['termsToImprovement'] = min(10, $termsNeeded);
        }
        break;
    }
}

// 8. INSIGHTS GENERATION
$insights = [];

// Performance insights
if ($regression['slope'] > 3) {
    $insights[] = [
        'category' => 'Performance',
        'type' => 'positive',
        'title' => ' Strong Upward Trajectory',
        'description' => "Student is improving by approximately {$regression['slope']} points per term. This excellent trend suggests strong learning progress.",
        'actionable' => true
    ];
} elseif ($regression['slope'] < -3) {
    $insights[] = [
        'category' => 'Performance',
        'type' => 'negative',
        'title' => 'Significant Performance Decline',
        'description' => "Student is declining by approximately {$regression['slope']} points per term. Immediate intervention is crucial.",
        'actionable' => true
    ];
}

// Consistency insights
if ($stdDev < 8) {
    $insights[] = [
        'category' => 'Consistency',
        'type' => 'positive',
        'title' => ' Highly Consistent Performance',
        'description' => "Scores are stable with minimal variation. This indicates reliable understanding and study habits.",
        'actionable' => false
    ];
} elseif ($stdDev > 20) {
    $insights[] = [
        'category' => 'Consistency',
        'type' => 'warning',
        'title' => ' Highly Variable Performance',
        'description' => "Scores fluctuate significantly (±{$stdDev} points). This suggests inconsistent preparation or understanding.",
        'actionable' => true
    ];
}

// Prediction insights
if ($ensemblePredictions[0] >= 85) {
    $insights[] = [
        'category' => 'Prediction',
        'type' => 'positive',
        'title' => 'Predicted Excellence',
        'description' => "Model predicts Grade A performance ({$ensemblePredictions[0]}%) in next term. Maintain current effort!",
        'actionable' => false
    ];
} elseif ($ensemblePredictions[0] < 50) {
    $insights[] = [
        'category' => 'Prediction',
        'type' => 'critical',
        'title' => 'Failure Risk Detected',
        'description' => "Model predicts score below passing mark ({$ensemblePredictions[0]}%). Urgent action required.",
        'actionable' => true
    ];
}

// Class comparison insights
if (!empty($response['classComparison']['percentile'])) {
    $percentile = $response['classComparison']['percentile'];
    if ($percentile >= 75) {
        $insights[] = [
            'category' => 'Comparison',
            'type' => 'positive',
            'title' => 'Above Average Performance',
            'description' => "Student ranks in top {$percentile}% of class. Performing better than most peers.",
            'actionable' => false
        ];
    } elseif ($percentile < 25) {
        $insights[] = [
            'category' => 'Comparison',
            'type' => 'warning',
            'title' => 'Below Average Performance',
            'description' => "Student ranks in bottom 25% of class. Additional support recommended.",
            'actionable' => true
        ];
    }
}

// Confidence insight
if ($regression['confidence'] < 50) {
    $insights[] = [
        'category' => 'Model',
        'type' => 'info',
        'title' => 'Low Prediction Confidence',
        'description' => "Model confidence is {$regression['confidence']}%. More consistent data would improve prediction accuracy.",
        'actionable' => false
    ];
}

$response['insights'] = $insights;

// 9. RECOMMENDATIONS & INTERVENTION PLAN
$recommendations = [];
$interventionPlan = [];

if ($response['riskAssessment']['level'] === 'Critical') {
    $recommendations[] = [
        'priority' => 'Critical',
        'category' => 'Immediate Action',
        'title' => 'Emergency Intervention Required',
        'actions' => [
            "Schedule urgent parent-teacher-student conference",
            "Design individualized learning plan for {$subject}",
            "Arrange daily one-on-one tutoring sessions",
            "Monitor progress weekly with formal assessments",
            "Consider peer mentoring program"
        ]
    ];

    $interventionPlan[] = [
        'phase' => 'Week 1-2',
        'focus' => 'Diagnostic Assessment',
        'activities' => [
            "Identify specific weak topics in {$subject}",
            "Conduct diagnostic test to pinpoint gaps",
            "Meet with student to understand challenges"
        ]
    ];

    $interventionPlan[] = [
        'phase' => 'Week 3-6',
        'focus' => 'Intensive Remediation',
        'activities' => [
            "Daily 30-minute focused practice sessions",
            "Use multimedia resources for difficult concepts",
            "Weekly mini-assessments to track progress"
        ]
    ];

    $interventionPlan[] = [
        'phase' => 'Week 7+',
        'focus' => 'Consolidation & Practice',
        'activities' => [
            "Regular practice with past exam questions",
            "Group study sessions with stronger students",
            "Monthly progress review meetings"
        ]
    ];
}

if ($response['riskAssessment']['level'] === 'High' || $response['riskAssessment']['level'] === 'Critical') {
    $recommendations[] = [
        'priority' => 'High',
        'category' => 'Academic Support',
        'title' => 'Intensive Study Program',
        'actions' => [
            "Attend all extra lessons for {$subject}",
            "Complete additional practice exercises weekly",
            "Use online resources (Khan Academy, YouTube tutorials)",
            "Form or join a study group",
            "Seek help immediately when concepts are unclear"
        ]
    ];
}

if ($stdDev > 15) {
    $recommendations[] = [
        'priority' => 'Medium',
        'category' => 'Study Habits',
        'title' => 'Improve Consistency',
        'actions' => [
            "Create and follow a regular study timetable",
            "Allocate specific time slots for {$subject} daily",
            "Review class notes within 24 hours of lessons",
            "Use spaced repetition for better retention",
            "Maintain an organized study environment"
        ]
    ];
}

if ($regression['slope'] > 2) {
    $recommendations[] = [
        'priority' => 'Low',
        'category' => 'Enrichment',
        'title' => 'Maintain Momentum',
        'actions' => [
            "Continue current study approach - it's working!",
            "Challenge yourself with advanced {$subject} problems",
            "Help struggling classmates through peer teaching",
            "Participate in subject competitions or olympiads",
            "Explore beyond curriculum topics to deepen understanding"
        ]
    ];
}

// AOI vs EOT specific recommendations
$recentAOI = end($historicalAOI);
$recentEOT = end($historicalEOT);
if ($recentAOI !== null && $recentEOT !== null) {
    $aoiPercent = ($recentAOI / 3) * 100;
    if ($aoiPercent - $recentEOT > 20) {
        $recommendations[] = [
            'priority' => 'Medium',
            'category' => 'Exam Technique',
            'title' => 'Improve Exam Performance',
            'actions' => [
                "Strong coursework but weak exam results detected",
                "Practice under timed conditions regularly",
                "Learn exam technique and time management",
                "Review marking schemes to understand expectations",
                "Reduce exam anxiety through relaxation techniques"
            ]
        ];
    } elseif ($recentEOT - $aoiPercent > 20) {
        $recommendations[] = [
            'priority' => 'Medium',
            'category' => 'Continuous Assessment',
            'title' => 'Improve Coursework Quality',
            'actions' => [
                "Good exam results but weak continuous assessment",
                "Submit all assignments on time",
                "Seek feedback on coursework and implement it",
                "Participate actively in class activities",
                "Maintain consistent effort throughout the term"
            ]
        ];
    }
}

$response['recommendations'] = $recommendations;
$response['interventionPlan'] = $interventionPlan;

echo json_encode($response);
mysqli_close($conn);
