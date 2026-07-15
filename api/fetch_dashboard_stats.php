<?php
require_once '../auth.php';
require_once '../conn.php';
header('Content-Type: application/json');

// --- Helper function to get AOI columns count ---
function getAOIColumnsFromMarks($conn, $tableName, $subject) {
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

// --- Helper function to calculate final score (MATCHES REPORT CARD) ---
function calculateFinalScore($marks, $aoi_columns) {
    $aoi_marks = array_filter($marks, fn($key) => $key !== 'EOT', ARRAY_FILTER_USE_KEY);
    $eot_mark = $marks['EOT'] ?? null;
    $finalScore = null;

    // Filter out null/empty marks
    $valid_aoi = array_filter($aoi_marks, function($mark) {
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

$response = [
    'totalStudents' => 0,
    'overallPassRate' => 'N/A',
    'topSubject' => 'N/A',
    'bottomSubject' => 'N/A',
];

// 1. Get Total Students (S1-S4)
$sqlStudents = "SELECT COUNT(student_id) as count FROM students WHERE current_class IN ('Senior One', 'Senior Two', 'Senior Three', 'Senior Four')";
$resultStudents = mysqli_query($conn, $sqlStudents);
if ($row = mysqli_fetch_assoc($resultStudents)) {
    $response['totalStudents'] = $row['count'];
}

// 2. Find the most recent marks table
$resultTables = mysqli_query($conn, "SHOW TABLES LIKE '%_olevel'");
$latestTable = null;
$latestYear = 0;
$latestTerm = 0;
while ($row = mysqli_fetch_row($resultTables)) {
    list($year, $term_roman) = explode('_', $row[0]);
    $term_map = ['i' => 1, 'ii' => 2, 'iii' => 3];
    $term = $term_map[$term_roman] ?? 0;
    if ((int)$year > $latestYear || ((int)$year == $latestYear && $term > $latestTerm)) {
        $latestTable = $row[0];
        $latestYear = (int)$year;
        $latestTerm = $term;
    }
}

if (!$latestTable) {
    echo json_encode($response); // Return default values if no tables found
    exit;
}

// 3. Calculate KPIs from the latest table
$sqlMarks = "SELECT student_id, subject, topic_id, marks FROM `{$latestTable}`";
$resultMarks = mysqli_query($conn, $sqlMarks);

$marksByStudent = [];
while ($row = mysqli_fetch_assoc($resultMarks)) {
    $marksByStudent[$row['student_id']][$row['subject']][$row['topic_id']] = $row['marks'];
}

if (empty($marksByStudent)) {
    echo json_encode($response);
    exit;
}

// Get AOI columns count per subject (cache to avoid multiple queries)
$aoiColumnsCache = [];
foreach ($marksByStudent as $studentId => $subjects) {
    foreach ($subjects as $subject => $marks) {
        if (!isset($aoiColumnsCache[$subject])) {
            $aoiColumnsCache[$subject] = getAOIColumnsFromMarks($conn, $latestTable, $subject);
        }
    }
}

$finalScoresBySubject = [];
$totalScoresCount = 0;
$passCount = 0;

foreach ($marksByStudent as $studentId => $subjects) {
    foreach ($subjects as $subject => $marks) {
        // Get the AOI columns count for this subject
        $aoi_columns = $aoiColumnsCache[$subject] ?? 2;
        
        // Calculate final score with correct AOI columns
        $finalScore = calculateFinalScore($marks, $aoi_columns);
        
        if ($finalScore !== null) {
            $finalScoresBySubject[$subject][] = $finalScore;
            if ($finalScore >= 50) {
                $passCount++;
            }
            $totalScoresCount++;
        }
    }
}

// Calculate Pass Rate
if ($totalScoresCount > 0) {
    $response['overallPassRate'] = round(($passCount / $totalScoresCount) * 100, 1) . '%';
}

// Calculate Subject Averages
$subjectAverages = [];
foreach ($finalScoresBySubject as $subject => $scores) {
    if (!empty($scores)) {
        $subjectAverages[$subject] = array_sum($scores) / count($scores);
    }
}

// Find Top and Bottom Subjects
if (!empty($subjectAverages)) {
    arsort($subjectAverages); // Sort high to low
    $response['topSubject'] = key($subjectAverages);
    asort($subjectAverages); // Sort low to high
    $response['bottomSubject'] = key($subjectAverages);
}

echo json_encode($response);
mysqli_close($conn);