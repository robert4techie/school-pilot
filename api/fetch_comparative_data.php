<?php
require_once '../auth.php';
require_once '../conn.php';
header('Content-Type: application/json');

// --- HELPER FUNCTIONS ---
function getMarksTableName($year, $term)
{
    $romans = [1 => 'i', 2 => 'ii', 3 => 'iii'];
    $term_roman = $romans[$term] ?? 'i';
    return "{$year}_{$term_roman}_olevel";
}

function convertToGrade($marks)
{
    if ($marks === null || $marks === '') return '-';
    $marks = floatval($marks);
    if ($marks >= 85) return 'A';
    if ($marks >= 70) return 'B';
    if ($marks >= 50) return 'C';
    if ($marks >= 40) return 'D';
    return 'E';
}

function getAchievementLevel($marks)
{
    if ($marks === null || $marks === '') return '-';
    $marks = floatval($marks);
    if ($marks >= 85) return 'Exceptional';
    if ($marks >= 70) return 'Outstanding';
    if ($marks >= 50) return 'Satisfactory';
    if ($marks >= 40) return 'Basic';
    return 'Elementary';
}

// --- PARAMETER VALIDATION ---
if (!isset($_GET['class'], $_GET['subject'], $_GET['year'], $_GET['term'])) {
    echo json_encode(['error' => 'Missing required parameters.']);
    exit;
}

$class = $_GET['class'];
$subject = $_GET['subject'];
$year = $_GET['year'];
$term = $_GET['term'];
$stream = $_GET['stream'] ?? 'All';
$aoi_columns = isset($_GET['aoi_columns']) ? (int)$_GET['aoi_columns'] : 3;
$aoi_columns = max(1, min(10, $aoi_columns));

// --- DATA INITIALIZATION ---
$data = [
    // Existing metrics
    'classAverages' => [],
    'genderAverages' => [],
    'gradeDistribution' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0],
    'trendData' => [],
    'totalStudents' => 0,
    'classAverage' => 0,
    'highestScore' => 0,
    'passRate' => '0%',
    'genderCounts' => ['Male' => 0, 'Female' => 0],
    'competencyData' => [
        'achieved' => 0,
        'underachieved' => 0,
        'gradeBreakdown' => [
            'A' => 0,
            'B' => 0,
            'C' => 0,
            'D' => 0,
            'E' => 0
        ]
    ],
    'isCompulsory' => false,
    'subjectTakers' => 0,

    // NEW: Table 1 - Achievement Levels
    'achievementLevels' => [
        'Exceptional' => ['count' => 0, 'percentage' => 0],
        'Outstanding' => ['count' => 0, 'percentage' => 0],
        'Satisfactory' => ['count' => 0, 'percentage' => 0],
        'Basic' => ['count' => 0, 'percentage' => 0],
        'Elementary' => ['count' => 0, 'percentage' => 0]
    ],

    // NEW: Table 2 - Detailed Gender-Grade Breakdown with Percentages
    'detailedGradeBreakdown' => [
        'A' => ['male' => 0, 'female' => 0, 'total' => 0, 'percentage' => 0],
        'B' => ['male' => 0, 'female' => 0, 'total' => 0, 'percentage' => 0],
        'C' => ['male' => 0, 'female' => 0, 'total' => 0, 'percentage' => 0],
        'D' => ['male' => 0, 'female' => 0, 'total' => 0, 'percentage' => 0],
        'E' => ['male' => 0, 'female' => 0, 'total' => 0, 'percentage' => 0]
    ],

    // NEW: Gender-grade matrix
    'genderGradeData' => [
        'Male' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0],
        'Female' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0]
    ],

    // NEW: AOI-specific analysis
    'aoiAnalysis' => [
        'averageAOI' => 0,
        'studentsWithAOI' => 0,
        'aoiDistribution' => [],
        'aoiPerformanceByGender' => []
    ],

    // NEW: Detailed student list for diagnostics
    'studentList' => []
];

$tableName = getMarksTableName($year, $term);

// Check if marks table exists
$checkTable = mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'");
if (mysqli_num_rows($checkTable) == 0) {
    echo json_encode(['error' => "Marks table for Term {$term}, {$year} does not exist."]);
    exit;
}

// Get Subject Compulsory Status
$sql_subj = "SELECT compulsory FROM subjects WHERE subj_name = ? LIMIT 1";
$stmt_subj = mysqli_prepare($conn, $sql_subj);
mysqli_stmt_bind_param($stmt_subj, "s", $subject);
mysqli_stmt_execute($stmt_subj);
$result_subj = mysqli_stmt_get_result($stmt_subj);
if ($row_subj = mysqli_fetch_assoc($result_subj)) {
    $data['isCompulsory'] = ($row_subj['compulsory'] == 1);
}
mysqli_stmt_close($stmt_subj);

// Build student query conditions
$streamCondition = "";
$base_params = [$class];
$base_types = 's';
if ($stream !== 'All') {
    $streamCondition = " AND s.stream = ?";
    $base_params[] = $stream;
    $base_types .= 's';
}

// Get all students and their marks for the specific subject and CURRENT term
$sql = "
    SELECT 
        s.student_id, s.first_name, s.last_name, s.gender, s.stream,
        m.topic_id, m.marks
    FROM students s
    LEFT JOIN `{$tableName}` m ON s.student_id = m.student_id AND m.subject = ? AND m.class = ?
    WHERE s.current_class = ? {$streamCondition}
    ORDER BY s.student_id
";
$current_params = array_merge([$subject, $class], $base_params);
$current_types = 'ss' . $base_types;

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $current_types, ...$current_params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$studentData = [];
while ($row = mysqli_fetch_assoc($result)) {
    $studentId = $row['student_id'];
    if (!isset($studentData[$studentId])) {
        $studentData[$studentId] = [
            'name' => $row['first_name'] . ' ' . $row['last_name'],
            'gender' => ucfirst($row['gender']),
            'stream' => $row['stream'],
            'aoi_marks' => [],
            'eot_mark' => null
        ];
    }
    if ($row['topic_id'] === 'EOT') {
        $studentData[$studentId]['eot_mark'] = $row['marks'];
    } elseif ($row['topic_id'] !== null) {
        $studentData[$studentId]['aoi_marks'][] = $row['marks'];
    }
}
mysqli_stmt_close($stmt);

// --- DATA PROCESSING (CURRENT TERM) ---
$studentFinalScores = [];
$data['totalStudents'] = count($studentData);
$genders = ['Male', 'Female'];
$grades = ['A', 'B', 'C', 'D', 'E'];

foreach ($studentData as $studentId => $details) {  // ✅ CORRECT - using $studentData
    // Keep all AOI marks in order, including nulls
    $all_aoi_marks = $details['aoi_marks'];
    $eot_mark = $details['eot_mark'];
    $finalScore = null;
    
    // Ensure we have exactly $aoi_columns elements (pad with null if needed)
    while (count($all_aoi_marks) < $aoi_columns) {
        $all_aoi_marks[] = null;
    }
    // Take only the first $aoi_columns marks
    $all_aoi_marks = array_slice($all_aoi_marks, 0, $aoi_columns);
    
    // Filter valid AOI marks for calculation
    $valid_aoi = array_filter($all_aoi_marks, fn($m) => $m !== null && $m !== '' && $m !== '-');
    $aoi_sum = !empty($valid_aoi) ? array_sum($valid_aoi) : null;
    
    // CRITICAL: Divide by total AOI columns, not just valid marks (matches report card logic)
    $aoiAvg = $aoi_sum !== null ? $aoi_sum / $aoi_columns : null;
    
    $hasAoi = $aoiAvg !== null;
    $hasEot = $eot_mark !== null && $eot_mark !== '' && $eot_mark !== '-';
    
    // Calculate final score using EXACT REPORT CARD LOGIC
    if ($hasAoi || $hasEot) {
        $data['subjectTakers']++;
        
        if ($hasAoi && $hasEot) {
            // Both AOI and EOT
            $finalScore = (($aoiAvg / 3) * 20) + (($eot_mark / 100) * 80);
        } elseif ($hasEot) {
            // EOT only
            $finalScore = $eot_mark;
        } elseif ($hasAoi) {
            // AOI only
            $finalScore = ($aoiAvg * 20) / 3;
        }
    }
    
    // IMPORTANT: Store the final score in the array
    if ($finalScore !== null) {
        $studentFinalScores[$studentId] = [
            'name' => $details['name'],
            'score' => round($finalScore, 1),
            'gender' => $details['gender'],
            'stream' => $details['stream'],
            'aoiAvg' => $aoiAvg,
            'eotScore' => $eot_mark
        ];
    }
}

// --- AGGREGATION AND ANALYSIS (CURRENT TERM) ---
if (!empty($studentFinalScores)) {
    $streamTotals = [];
    $streamCounts = [];
    $genderTotals = [];
    $genderCounts = [];
    $allScores = [];
    $passCount = 0;
    $aoiTotal = 0;
    $aoiCount = 0;

    foreach ($studentFinalScores as $studentId => $info) {
        $score = $info['score'];
        $gender = $info['gender'];
        $studentStream = $info['stream'];
        $grade = convertToGrade($score);
        $achievement = getAchievementLevel($score);

        $allScores[] = $score;
        if ($score >= 50) $passCount++;

        // Grade distribution
        if (isset($data['gradeDistribution'][$grade])) {
            $data['gradeDistribution'][$grade]++;
        }

        // Achievement levels (Table 1)
        if (isset($data['achievementLevels'][$achievement])) {
            $data['achievementLevels'][$achievement]['count']++;
        }

        // Competency tracking with grade breakdown
        if (in_array($grade, ['A', 'B', 'C'])) {
            $data['competencyData']['achieved']++;
            $data['competencyData']['gradeBreakdown'][$grade]++;
        } else if (in_array($grade, ['D', 'E'])) {
            $data['competencyData']['underachieved']++;
            $data['competencyData']['gradeBreakdown'][$grade]++;
        }

        // Gender-grade matrix
        if (isset($data['genderGradeData'][$gender][$grade])) {
            $data['genderGradeData'][$gender][$grade]++;
        }

        // Stream and gender aggregations
        @$streamTotals[$studentStream] += $score;
        @$streamCounts[$studentStream]++;
        @$genderTotals[$gender] += $score;
        @$genderCounts[$gender]++;

        // AOI analysis
        if ($info['aoiAvg'] !== null) {
            $aoiTotal += $info['aoiAvg'];
            $aoiCount++;

            // AOI distribution by gender
            if (!isset($data['aoiAnalysis']['aoiPerformanceByGender'][$gender])) {
                $data['aoiAnalysis']['aoiPerformanceByGender'][$gender] = [
                    'total' => 0,
                    'count' => 0,
                    'average' => 0
                ];
            }
            $data['aoiAnalysis']['aoiPerformanceByGender'][$gender]['total'] += $info['aoiAvg'];
            $data['aoiAnalysis']['aoiPerformanceByGender'][$gender]['count']++;
        }

        // Add to student list
        $data['studentList'][] = [
            'id' => $studentId,
            'name' => $info['name'],
            'score' => $score,
            'grade' => $grade,
            'gender' => $gender,
            'stream' => $studentStream
        ];
    }

    // Calculate averages
    foreach ($streamTotals as $strm => $total) {
        $data['classAverages'][$strm] = round($total / $streamCounts[$strm], 1);
    }
    foreach ($genderTotals as $gen => $total) {
        $data['genderAverages'][$gen] = round($total / $genderCounts[$gen], 1);
    }

    // Calculate percentages for achievement levels (Table 1)
    $denominator = $data['isCompulsory'] ? $data['totalStudents'] : $data['subjectTakers'];
    foreach ($data['achievementLevels'] as $level => $stats) {
        $data['achievementLevels'][$level]['percentage'] = $denominator > 0
            ? round(($stats['count'] / $denominator) * 100, 1)
            : 0;
    }

    // Calculate detailed grade breakdown with percentages (Table 2)
    foreach ($grades as $grade) {
        $maleCount = $data['genderGradeData']['Male'][$grade];
        $femaleCount = $data['genderGradeData']['Female'][$grade];
        $total = $maleCount + $femaleCount;
        $percentage = ($denominator > 0) ? round(($total / $denominator) * 100, 1) : 0;

        $data['detailedGradeBreakdown'][$grade] = [
            'male' => $maleCount,
            'female' => $femaleCount,
            'total' => $total,
            'percentage' => $percentage
        ];
    }

    // Pass rate
    if ($denominator > 0) {
        $data['passRate'] = round(($passCount / $denominator) * 100, 1) . '%';
    }

    // Overall statistics
    $data['classAverage'] = round(array_sum($allScores) / count($allScores), 1);
    $data['highestScore'] = max($allScores);
    $data['genderCounts'] = $genderCounts;

    // AOI analysis finalization
    $data['aoiAnalysis']['averageAOI'] = $aoiCount > 0 ? round($aoiTotal / $aoiCount, 2) : 0;
    $data['aoiAnalysis']['studentsWithAOI'] = $aoiCount;

    foreach ($data['aoiAnalysis']['aoiPerformanceByGender'] as $gender => $stats) {
        $data['aoiAnalysis']['aoiPerformanceByGender'][$gender]['average'] =
            $stats['count'] > 0 ? round($stats['total'] / $stats['count'], 2) : 0;
    }

    // Sort student list by score (descending)
    usort($data['studentList'], function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });
}

// --- TREND DATA GENERATION (COMPETENCY-BASED) ---
$trendLabels = [];
$trendData = [];

for ($termNum = 1; $termNum <= 3; $termNum++) {
    $trendTableName = getMarksTableName($year, $termNum);
    $checkTrendTable = mysqli_query($conn, "SHOW TABLES LIKE '{$trendTableName}'");

    if (mysqli_num_rows($checkTrendTable) > 0) {
        $sql_trend = "
            SELECT s.student_id, m.topic_id, m.marks
            FROM students s
            LEFT JOIN `{$trendTableName}` m ON s.student_id = m.student_id AND m.subject = ? AND m.class = ?
            WHERE s.current_class = ? {$streamCondition}
        ";

        $trend_params = array_merge([$subject, $class], $base_params);
        $trend_types = 'ss' . $base_types;

        $trend_stmt = mysqli_prepare($conn, $sql_trend);
        mysqli_stmt_bind_param($trend_stmt, $trend_types, ...$trend_params);
        mysqli_stmt_execute($trend_stmt);
        $trend_result = mysqli_stmt_get_result($trend_stmt);

        $termStudentData = [];
        while ($row = mysqli_fetch_assoc($trend_result)) {
            $studentId = $row['student_id'];
            if (!isset($termStudentData[$studentId])) {
                $termStudentData[$studentId] = ['aoi_marks' => [], 'eot_mark' => null];
            }
            if ($row['topic_id'] === 'EOT') {
                $termStudentData[$studentId]['eot_mark'] = $row['marks'];
            } elseif ($row['topic_id'] !== null) {
                $termStudentData[$studentId]['aoi_marks'][] = $row['marks'];
            }
        }
        mysqli_stmt_close($trend_stmt);

        $achievedCount = 0;
        $totalEvaluated = 0;

        foreach ($termStudentData as $studentId => $details) {  // ✅ CORRECT! Using each term's data
            // Keep all AOI marks in order, including nulls
            $all_aoi_marks = $details['aoi_marks'];
            $eot_mark = $details['eot_mark'];
            $finalScore = null;
            
            // Ensure we have exactly $aoi_columns elements (pad with null if needed)
            while (count($all_aoi_marks) < $aoi_columns) {
                $all_aoi_marks[] = null;
            }
            // Take only the first $aoi_columns marks
            $all_aoi_marks = array_slice($all_aoi_marks, 0, $aoi_columns);
            
            // Filter valid AOI marks
            $valid_aoi = array_filter($all_aoi_marks, fn($m) => $m !== null && $m !== '' && $m !== '-');
            $aoi_sum = !empty($valid_aoi) ? array_sum($valid_aoi) : null;
            
            // CRITICAL: Divide by total AOI columns (matches report card)
            $aoiAvg = $aoi_sum !== null ? $aoi_sum / $aoi_columns : null;
            
            $hasAoi = $aoiAvg !== null;
            $hasEot = $eot_mark !== null && $eot_mark !== '' && $eot_mark !== '-';

            if ($hasAoi || $hasEot) {
                $totalEvaluated++;
                
                if ($hasAoi && $hasEot) {
                    $finalScore = (($aoiAvg / 3) * 20) + (($eot_mark / 100) * 80);
                } elseif ($hasEot) {
                    $finalScore = $eot_mark;
                } elseif ($hasAoi) {
                    $finalScore = ($aoiAvg * 20) / 3;
                }
                
                $grade = convertToGrade($finalScore);
                if (in_array($grade, ['A', 'B', 'C'])) {
                    $achievedCount++;
                }
            }
        }

        $trendLabels[] = "Term {$termNum}";
        $competencyPercentage = ($totalEvaluated > 0) ? round(($achievedCount / $totalEvaluated) * 100, 1) : 0;
        $trendData[] = $competencyPercentage;
    }
}

$data['trendData'] = [
    'labels' => $trendLabels,
    'data' => $trendData
];

echo json_encode($data);
mysqli_close($conn);