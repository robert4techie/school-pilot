<?php
require_once '../auth.php';
require_once '../conn.php';

header('Content-Type: application/json');

// --- HELPER FUNCTIONS (EXACT MATCH WITH REPORT CARD) ---
function getMarksTableName($year, $term_num) {
    $romans = [1 => 'i', 2 => 'ii', 3 => 'iii'];
    $term_roman = $romans[$term_num] ?? 'i';
    return "{$year}_{$term_roman}_olevel";
}

function convertToGrade($marks) {
    if (!is_numeric($marks)) return '-';
    $marks = floatval($marks);
    if ($marks >= 85) return 'A';
    if ($marks >= 70) return 'B';
    if ($marks >= 50) return 'C';
    if ($marks >= 40) return 'D';
    return 'E';
}

function getAchievementLevel($marks) {
    if (!is_numeric($marks)) return '-';
    $marks = floatval($marks);
    if ($marks >= 85) return 'Exceptional';
    if ($marks >= 70) return 'Outstanding';
    if ($marks >= 50) return 'Satisfactory';
    if ($marks >= 40) return 'Basic';
    return 'Elementary';
}

function getPerformanceColor($grade) {
    $colors = [
        'A' => '#4CAF50',
        'B' => '#8BC34A',
        'C' => '#FFC107',
        'D' => '#FF9800',
        'E' => '#F44336'
    ];
    return $colors[$grade] ?? '#999';
}

// CRITICAL: EXACT CALCULATION FROM SUMMARIZED_REPORT.PHP
function calculateFinalScore($aoi_marks, $eot_mark, $aoi_columns) {
    // Filter valid AOI marks
    $valid_aoi = array_filter($aoi_marks, fn($m) => $m !== null && $m !== '' && $m !== '-');
    
    // Calculate AOI sum and average
    $aoi_sum = !empty($valid_aoi) ? array_sum($valid_aoi) : null;
    // CRITICAL: Divide by total AOI columns, not valid marks count
    $aoi_avg = $aoi_sum !== null ? $aoi_sum / $aoi_columns : null;
    
    $total = null;
    $out_of_20 = null;
    $eot_of_80 = null;
    
    $has_aoi = $aoi_avg !== null;
    $has_eot = $eot_mark !== null && $eot_mark !== '' && $eot_mark !== '-';
    $aoi_only = $has_aoi && !$has_eot;
    
    if ($has_aoi) {
        if ($aoi_only) {
            // AOI only: (avg * 20) / 3
            $total = ($aoi_avg * 20) / 3;
            $out_of_20 = $total;
        } else if ($has_eot) {
            // Both AOI and EOT
            $out_of_20 = ($aoi_avg / 3) * 20;
            $eot_of_80 = ($eot_mark / 100) * 80;
            $total = $out_of_20 + $eot_of_80;
        } else {
            // AOI but no EOT
            $out_of_20 = ($aoi_avg / 3) * 20;
            $total = $out_of_20;
        }
    } else if ($has_eot) {
        // EOT only
        $total = $eot_mark;
        $eot_of_80 = ($eot_mark / 100) * 80;
    }
    
    return [
        'total' => $total,
        'aoi_avg' => $aoi_avg,
        'out_of_20' => $out_of_20,
        'eot_of_80' => $eot_of_80
    ];
}

function generateRecommendation($subject, $score, $grade, $aoiAvg, $eotScore, $classAverage, $trend) {
    $recommendations = [];
    $priority = 'low';
    
    if ($grade === 'E') {
        $priority = 'critical';
        $recommendations[] = "URGENT: Immediate intervention required for {$subject}.";
        $recommendations[] = "Schedule intensive remedial sessions.";
        $recommendations[] = "Arrange one-on-one tutoring with subject teacher.";
        $recommendations[] = "Consider peer support or study group participation.";
    } elseif ($grade === 'D') {
        $priority = 'high';
        $recommendations[] = "HIGH PRIORITY: {$subject} needs significant improvement.";
        $recommendations[] = "Increase study time and practice frequency.";
        $recommendations[] = "Attend extra lessons and consultation hours.";
    } elseif ($grade === 'C') {
        $priority = 'medium';
        $recommendations[] = "MODERATE: {$subject} performance is satisfactory but can improve.";
        $recommendations[] = "Focus on exam technique and time management.";
        $recommendations[] = "Review weak topics identified in assessments.";
    } elseif ($grade === 'B') {
        $priority = 'low';
        $recommendations[] = "GOOD: Strong performance in {$subject}.";
        $recommendations[] = "Continue current study approach.";
        $recommendations[] = "Challenge yourself with advanced practice questions.";
    } else {
        $priority = 'maintain';
        $recommendations[] = "EXCELLENT: Outstanding performance in {$subject}!";
        $recommendations[] = "Maintain this exceptional standard.";
        $recommendations[] = "Consider helping struggling classmates through peer teaching.";
    }
    
    if ($aoiAvg !== null && $eotScore !== null) {
        $aoiPercent = ($aoiAvg / 3) * 100;
        $diff = abs($aoiPercent - $eotScore);
        
        if ($diff > 20) {
            if ($aoiPercent > $eotScore) {
                $recommendations[] = "Strong coursework but weak exam performance. Focus on exam technique and time management.";
            } else {
                $recommendations[] = "Weak coursework but strong exam performance. Improve consistency in continuous assessment.";
            }
        }
    }
    
    if ($classAverage !== null && $classAverage > 0) {
        $diff = $score - $classAverage;
        if ($diff < -10) {
            $recommendations[] = "Performing significantly below class average. Extra support recommended.";
        } elseif ($diff > 10) {
            $recommendations[] = "Performing above class average. Excellent work!";
        }
    }
    
    if ($trend !== null) {
        if ($trend > 10) {
            $recommendations[] = "Great improvement! Keep up the momentum.";
        } elseif ($trend < -10) {
            $recommendations[] = "Performance declining. Identify and address challenges immediately.";
        }
    }
    
    return [
        'priority' => $priority,
        'items' => $recommendations
    ];
}

// --- PARAMETER VALIDATION ---
if (!isset($_GET['student_id'], $_GET['year'], $_GET['term'])) {
    echo json_encode(['error' => 'Missing required parameters.']);
    exit;
}

$studentId = $_GET['student_id'];
$year = $_GET['year'];
$selectedTerm = (int)$_GET['term'];

// Get AOI columns configuration (default to 2)
$aoi_columns = isset($_GET['aoi_columns']) ? (int)$_GET['aoi_columns'] : 2;
$aoi_columns = max(1, min(10, $aoi_columns));

$data = [
    'studentInfo' => null,
    'overallStats' => [],
    'subjectPerformance' => [],
    'performanceTrend' => [],
    'strengthsWeaknesses' => [
        'topSubjects' => [],
        'weakSubjects' => [],
        'mostImproved' => null,
        'mostDeclined' => null
    ],
    'historicalComparison' => [],
    'classRanking' => [
        'position' => null,
        'totalStudents' => 0,
        'percentile' => 0
    ]
];

// 1. Get Student Info
$stmtStudent = mysqli_prepare($conn, "SELECT student_id, first_name, last_name, current_class, stream, section, gender FROM students WHERE student_id = ?");
mysqli_stmt_bind_param($stmtStudent, "s", $studentId);
mysqli_stmt_execute($stmtStudent);
$resultStudent = mysqli_stmt_get_result($stmtStudent);
if (!($data['studentInfo'] = mysqli_fetch_assoc($resultStudent))) {
    echo json_encode(['error' => 'Student not found.']);
    exit;
}
mysqli_stmt_close($stmtStudent);

$studentClass = $data['studentInfo']['current_class'];
$studentStream = $data['studentInfo']['stream'];

// --- 2. GET SUBJECTS INFO (compulsory status) ---
$subject_names_cache = [];
$compulsory_cache = [];

$sql = "SELECT subj_name, subj_abbr, compulsory FROM subjects";
$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $subject_names_cache[$row['subj_name']] = $row['subj_name'];
        $subject_names_cache[$row['subj_abbr']] = $row['subj_name'];
        
        $is_compulsory = (int)$row['compulsory'] === 1;
        $compulsory_cache[$row['subj_name']] = $is_compulsory;
        $compulsory_cache[$row['subj_abbr']] = $is_compulsory;
    }
}

// --- 3. CALCULATE CLASS AVERAGES FOR EACH SUBJECT (Selected Term) ---
$tableName = getMarksTableName($year, $selectedTerm);
$classAverages = [];

if (mysqli_num_rows(mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'")) > 0) {
    // Get all students in same class and stream
    $stmtClassStudents = mysqli_prepare($conn, "
        SELECT DISTINCT student_id FROM `{$tableName}` 
        WHERE class = ? AND stream = ?
    ");
    mysqli_stmt_bind_param($stmtClassStudents, "ss", $studentClass, $studentStream);
    mysqli_stmt_execute($stmtClassStudents);
    $resultClassStudents = mysqli_stmt_get_result($stmtClassStudents);
    
    $classStudentIds = [];
    while ($row = mysqli_fetch_assoc($resultClassStudents)) {
        $classStudentIds[] = $row['student_id'];
    }
    mysqli_stmt_close($stmtClassStudents);
    
    // Calculate average for each subject
    $subjectTotals = [];
    $subjectCounts = [];
    
    foreach ($classStudentIds as $sid) {
        $stmtStudentMarks = mysqli_prepare($conn, "SELECT subject, topic_id, marks FROM `{$tableName}` WHERE student_id = ?");
        mysqli_stmt_bind_param($stmtStudentMarks, "s", $sid);
        mysqli_stmt_execute($stmtStudentMarks);
        $resultStudentMarks = mysqli_stmt_get_result($stmtStudentMarks);
        
        $studentSubjects = [];
        while ($mark = mysqli_fetch_assoc($resultStudentMarks)) {
            $subject = $mark['subject'];
            $topicId = $mark['topic_id'];
            $marks = $mark['marks'];
            
            if (!isset($studentSubjects[$subject])) {
                $studentSubjects[$subject] = ['aoi' => [], 'eot' => null];
            }
            
            if ($topicId === 'EOT') {
                $studentSubjects[$subject]['eot'] = $marks;
            } else {
                $studentSubjects[$subject]['aoi'][] = $marks;
            }
        }
        mysqli_stmt_close($stmtStudentMarks);
        
        // Calculate final score for each subject
        foreach ($studentSubjects as $subject => $marks) {
            $subject_name = $subject_names_cache[$subject] ?? $subject;
            $is_compulsory = $compulsory_cache[$subject] ?? false;
            
            // Check if subject has marks
            $valid_aoi = array_filter($marks['aoi'], fn($m) => $m !== null && $m !== '' && $m !== '-');
            $has_eot = $marks['eot'] !== null && $marks['eot'] !== '' && $marks['eot'] !== '-';
            
            // Only include if compulsory or has marks
            if ($is_compulsory || !empty($valid_aoi) || $has_eot) {
                $result = calculateFinalScore($marks['aoi'], $marks['eot'], $aoi_columns);
                
                if ($result['total'] !== null) {
                    if (!isset($subjectTotals[$subject])) {
                        $subjectTotals[$subject] = 0;
                        $subjectCounts[$subject] = 0;
                    }
                    $subjectTotals[$subject] += $result['total'];
                    $subjectCounts[$subject]++;
                }
            }
        }
    }
    
    // Calculate class averages
    foreach ($subjectTotals as $subject => $total) {
        $classAverages[$subject] = round($total / $subjectCounts[$subject], 1);
    }
}

// --- 4. PROCESS DATA FOR ALL THREE TERMS ---
$termHistory = [];

for ($termNum = 1; $termNum <= 3; $termNum++) {
    $tableName = getMarksTableName($year, $termNum);
    if (mysqli_num_rows(mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'")) == 0) {
        continue;
    }

    $stmtMarks = mysqli_prepare($conn, "SELECT subject, topic_id, marks FROM `{$tableName}` WHERE student_id = ?");
    mysqli_stmt_bind_param($stmtMarks, "s", $studentId);
    mysqli_stmt_execute($stmtMarks);
    $resultMarks = mysqli_stmt_get_result($stmtMarks);

    $termMarksBySubject = [];
    while ($row = mysqli_fetch_assoc($resultMarks)) {
        if (!isset($termMarksBySubject[$row['subject']])) {
            $termMarksBySubject[$row['subject']] = ['aoi' => [], 'eot' => null];
        }
        
        if ($row['topic_id'] === 'EOT') {
            $termMarksBySubject[$row['subject']]['eot'] = $row['marks'];
        } else {
            $termMarksBySubject[$row['subject']]['aoi'][] = $row['marks'];
        }
    }
    mysqli_stmt_close($stmtMarks);

    if (empty($termMarksBySubject)) continue;

    $termFinalScores = [];
    foreach ($termMarksBySubject as $subject => $marks) {
        $subject_name = $subject_names_cache[$subject] ?? $subject;
        $is_compulsory = $compulsory_cache[$subject] ?? false;
        
        // Check if subject has marks
        $valid_aoi = array_filter($marks['aoi'], fn($m) => $m !== null && $m !== '' && $m !== '-');
        $has_eot = $marks['eot'] !== null && $marks['eot'] !== '' && $marks['eot'] !== '-';
        
        // Only include if compulsory or has marks
        if ($is_compulsory || !empty($valid_aoi) || $has_eot) {
            $result = calculateFinalScore($marks['aoi'], $marks['eot'], $aoi_columns);
            
            if ($result['total'] !== null) {
                $termFinalScores[$subject] = [
                    'score' => $result['total'],
                    'aoi_avg' => $result['aoi_avg'],
                    'out_of_20' => $result['out_of_20'],
                    'eot_of_80' => $result['eot_of_80'],
                    'eot_score' => $marks['eot']
                ];
            }
        }
    }

    if (empty($termFinalScores)) continue;
    
    $termHistory[$termNum] = $termFinalScores;

    // Calculate term average
    $termAverage = round(array_sum(array_column($termFinalScores, 'score')) / count($termFinalScores), 1);
    $data['performanceTrend'][] = ['term' => "Term {$termNum}", 'average' => $termAverage];

    // If this is the selected term, populate detailed stats
    if ($termNum === $selectedTerm) {
        $scores = array_column($termFinalScores, 'score');
        $totalMarks = round(array_sum($scores));
        
        $data['overallStats'] = [
            'average' => $termAverage,
            'total' => $totalMarks,
            'grade' => convertToGrade($termAverage),
            'achievement' => getAchievementLevel($termAverage),
            'subjectCount' => count($termFinalScores)
        ];
        
        foreach($termFinalScores as $subject => $scoreData) {
            $score = round($scoreData['score'], 1);
            $grade = convertToGrade($score);
            $classAvg = $classAverages[$subject] ?? null;
            
            // Calculate trend if previous term exists
            $trend = null;
            if (isset($termHistory[$termNum - 1][$subject])) {
                $prevScore = $termHistory[$termNum - 1][$subject]['score'];
                $trend = round($score - $prevScore, 1);
            }
            
            $recommendation = generateRecommendation(
                $subject, 
                $score, 
                $grade, 
                $scoreData['aoi_avg'], 
                $scoreData['eot_score'], 
                $classAvg, 
                $trend
            );
            
            $data['subjectPerformance'][] = [
                'subject' => $subject,
                'score' => $score,
                'grade' => $grade,
                'achievement' => getAchievementLevel($score),
                'color' => getPerformanceColor($grade),
                'aoi_average' => $scoreData['aoi_avg'] !== null ? round($scoreData['aoi_avg'], 2) : null,
                'out_of_20' => $scoreData['out_of_20'] !== null ? round($scoreData['out_of_20'], 1) : null,
                'eot_of_80' => $scoreData['eot_of_80'] !== null ? round($scoreData['eot_of_80'], 1) : null,
                'eot_score' => $scoreData['eot_score'] !== null ? round($scoreData['eot_score'], 1) : null,
                'classAverage' => $classAvg,
                'difference' => $classAvg !== null ? round($score - $classAvg, 1) : null,
                'trend' => $trend,
                'recommendation' => $recommendation
            ];
        }
        
        // Sort subjects by score, descending
        usort($data['subjectPerformance'], fn($a, $b) => $b['score'] <=> $a['score']);
    }
}

// --- 5. STRENGTHS & WEAKNESSES ANALYSIS ---
if (!empty($data['subjectPerformance'])) {
    $data['strengthsWeaknesses']['topSubjects'] = array_slice($data['subjectPerformance'], 0, 3);
    $weakest = array_slice(array_reverse($data['subjectPerformance']), 0, 3);
    $data['strengthsWeaknesses']['weakSubjects'] = array_reverse($weakest);
    
    if (count($termHistory) >= 2) {
        $improvements = [];
        $declines = [];
        
        foreach ($data['subjectPerformance'] as $subj) {
            if ($subj['trend'] !== null) {
                if ($subj['trend'] > 0) {
                    $improvements[] = [
                        'subject' => $subj['subject'],
                        'improvement' => $subj['trend'],
                        'currentScore' => $subj['score'],
                        'grade' => $subj['grade']
                    ];
                } elseif ($subj['trend'] < 0) {
                    $declines[] = [
                        'subject' => $subj['subject'],
                        'decline' => abs($subj['trend']),
                        'currentScore' => $subj['score'],
                        'grade' => $subj['grade']
                    ];
                }
            }
        }
        
        usort($improvements, fn($a, $b) => $b['improvement'] <=> $a['improvement']);
        usort($declines, fn($a, $b) => $b['decline'] <=> $a['decline']);
        
        $data['strengthsWeaknesses']['mostImproved'] = $improvements[0] ?? null;
        $data['strengthsWeaknesses']['mostDeclined'] = $declines[0] ?? null;
    }
}

// --- 6. HISTORICAL COMPARISON ---
if (count($termHistory) > 1) {
    foreach ($data['subjectPerformance'] as $subj) {
        $subject = $subj['subject'];
        $history = [];
        
        for ($t = 1; $t <= 3; $t++) {
            if (isset($termHistory[$t][$subject])) {
                $history[] = [
                    'term' => "Term {$t}",
                    'score' => round($termHistory[$t][$subject]['score'], 1),
                    'grade' => convertToGrade($termHistory[$t][$subject]['score'])
                ];
            }
        }
        
        if (count($history) > 1) {
            $data['historicalComparison'][$subject] = $history;
        }
    }
}

// --- 7. CLASS RANKING CALCULATION (CORRECTED - MATCH REPORT CARD) ---
$tableName = getMarksTableName($year, $selectedTerm);
if (mysqli_num_rows(mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'")) > 0) {
    // Get ALL students' averages and grades in same class and stream
    $stmtRankingStudents = mysqli_prepare($conn, "
        SELECT DISTINCT student_id FROM `{$tableName}` 
        WHERE class = ? AND stream = ?
    ");
    mysqli_stmt_bind_param($stmtRankingStudents, "ss", $studentClass, $studentStream);
    mysqli_stmt_execute($stmtRankingStudents);
    $resultRankingStudents = mysqli_stmt_get_result($stmtRankingStudents);
    
    $allStudentData = []; // Changed to store both average AND grade
    
    while ($row = mysqli_fetch_assoc($resultRankingStudents)) {
        $sid = $row['student_id'];
        
        // Get marks for this student
        $stmtStudMarks = mysqli_prepare($conn, "SELECT subject, topic_id, marks FROM `{$tableName}` WHERE student_id = ?");
        mysqli_stmt_bind_param($stmtStudMarks, "s", $sid);
        mysqli_stmt_execute($stmtStudMarks);
        $resultStudMarks = mysqli_stmt_get_result($stmtStudMarks);
        
        $studentMarks = [];
        while ($mark = mysqli_fetch_assoc($resultStudMarks)) {
            if (!isset($studentMarks[$mark['subject']])) {
                $studentMarks[$mark['subject']] = ['aoi' => [], 'eot' => null];
            }
            if ($mark['topic_id'] === 'EOT') {
                $studentMarks[$mark['subject']]['eot'] = $mark['marks'];
            } else {
                $studentMarks[$mark['subject']]['aoi'][] = $mark['marks'];
            }
        }
        mysqli_stmt_close($stmtStudMarks);
        
        // Calculate average using EXACT formula from report card
        $studentScores = [];
        foreach ($studentMarks as $subject => $marks) {
            $subject_name = $subject_names_cache[$subject] ?? $subject;
            $is_compulsory = $compulsory_cache[$subject] ?? false;
            
            // Check if subject has marks
            $valid_aoi = array_filter($marks['aoi'], fn($m) => $m !== null && $m !== '' && $m !== '-');
            $has_eot = $marks['eot'] !== null && $marks['eot'] !== '' && $marks['eot'] !== '-';
            
            // Only include if compulsory or has marks
            if ($is_compulsory || !empty($valid_aoi) || $has_eot) {
                $result = calculateFinalScore($marks['aoi'], $marks['eot'], $aoi_columns);
                if ($result['total'] !== null) {
                    $studentScores[] = $result['total'];
                }
            }
        }
        
        if (!empty($studentScores)) {
            $average = array_sum($studentScores) / count($studentScores);
            $grade = convertToGrade($average);
            
            // Store both average and grade
            $allStudentData[$sid] = [
                'average' => $average,
                'grade' => $grade
            ];
        }
    }
    mysqli_stmt_close($stmtRankingStudents);
    
    // Sort by GRADE first, then by AVERAGE (same as report card)
    uasort($allStudentData, function($a, $b) {
        $grade_order = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5, '-' => 6];
        
        $order_a = $grade_order[$a['grade']] ?? 6;
        $order_b = $grade_order[$b['grade']] ?? 6;
        
        // First sort by grade
        if ($order_a !== $order_b) {
            return $order_a - $order_b;
        }
        
        // If same grade, sort by average (highest first)
        return $b['average'] <=> $a['average'];
    });
    
    // Find position
    $position = 1;
    foreach ($allStudentData as $sid => $data_item) {
        if ($sid === $studentId) {
            $data['classRanking']['position'] = $position;
            break;
        }
        $position++;
    }
    
    $data['classRanking']['totalStudents'] = count($allStudentData);
    $data['classRanking']['percentile'] = $data['classRanking']['totalStudents'] > 0 
        ? round((1 - ($data['classRanking']['position'] / $data['classRanking']['totalStudents'])) * 100, 1)
        : 0;
}

echo json_encode($data);
mysqli_close($conn);