<?php
// analytics_api.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Database connection
require_once '../../includes/conn.php';

// Get parameters
$class = $_GET['class'] ?? '';
$streams = $_GET['streams'] ?? [];
$year = $_GET['year'] ?? date('Y');
$term = $_GET['term'] ?? '';
$subjects = $_GET['subjects'] ?? [];
$analysis_type = $_GET['analysis_type'] ?? 'overall';

// Validate required parameters
if (empty($class) || empty($streams) || empty($year) || empty($term)) {
    echo json_encode(['error' => 'Missing required parameters']);
    exit;
}

// Convert term to roman numeral for table name
$term_number = filter_var($term, FILTER_SANITIZE_NUMBER_INT);
$romans = ['I', 'II', 'III'];
$term_roman = $romans[$term_number - 1] ?? 'I';
$table_name = "{$year}_{$term_roman}_olevel";

// Check if table exists
$check_table_sql = "SHOW TABLES LIKE '$table_name'";
$check_result = mysqli_query($conn, $check_table_sql);
if (!$check_result || mysqli_num_rows($check_result) == 0) {
    echo json_encode(['error' => "No data found for {$term} {$year}"]);
    exit;
}

try {
    // Get selected topics for the class, term, and year
    $selected_topics = getSelectedTopics($conn, $class, $term, $year);
    
    // If no topics selected, get from marks table as fallback
    if (empty($selected_topics)) {
        $selected_topics = getFallbackTopics($conn, $table_name, $class);
    }
    
    // Get all students with their marks
    $students_data = getStudentsWithMarks($conn, $table_name, $class, $streams, $subjects, $selected_topics);
    
    // Calculate performance metrics
    $analytics = calculateAnalytics($students_data, $analysis_type);
    
    echo json_encode([
        'success' => true,
        'data' => $analytics
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

// Function to get selected topics
function getSelectedTopics($conn, $class, $term, $year) {
    $topics = [];
    
    $sql = "SELECT subject, topic_id FROM report_topics 
            WHERE class = ? AND term = ? AND year = ? 
            ORDER BY subject, topic_id";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "sss", $class, $term, $year);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $subject = $row['subject'];
            $topic_id = $row['topic_id'];
            
            if (!isset($topics[$subject])) {
                $topics[$subject] = [];
            }
            
            $topics[$subject][] = $topic_id;
        }
    }
    
    return $topics;
}

// Function to get fallback topics from marks table
function getFallbackTopics($conn, $table_name, $class) {
    $topics = [];
    
    $sql = "SELECT DISTINCT subject, topic_id FROM `$table_name` 
            WHERE class = ? 
            ORDER BY subject, topic_id";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $class);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $subject = $row['subject'];
            $topic_id = $row['topic_id'];
            
            if (!isset($topics[$subject])) {
                $topics[$subject] = [];
            }
            
            $topics[$subject][] = $topic_id;
        }
    }
    
    return $topics;
}

// Function to get students with their marks
function getStudentsWithMarks($conn, $table_name, $class, $streams, $subjects, $selected_topics) {
    // Build stream filter
    $stream_placeholders = implode(',', array_fill(0, count($streams), '?'));
    
    // Build subject filter if specified
    $subject_filter = '';
    $subject_params = [];
    if (!empty($subjects) && !in_array('', $subjects)) {
        $subject_placeholders = implode(',', array_fill(0, count($subjects), '?'));
        $subject_filter = " AND subject IN ($subject_placeholders)";
        $subject_params = $subjects;
    }
    
    // Get all marks data
    $sql = "SELECT m.student_id, m.subject, m.topic_id, m.marks, m.stream,
                   s.first_name, s.last_name, s.gender
            FROM `$table_name` m
            LEFT JOIN students s ON m.student_id = s.id
            WHERE m.class = ? AND m.stream IN ($stream_placeholders) $subject_filter
            ORDER BY m.student_id, m.subject, m.topic_id";
    
    $stmt = mysqli_prepare($conn, $sql);
    
    // Prepare parameters
    $params = [$class];
    $params = array_merge($params, $streams);
    $params = array_merge($params, $subject_params);
    
    $types = 's' . str_repeat('s', count($streams)) . str_repeat('s', count($subject_params));
    
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $students = [];
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $student_id = $row['student_id'];
            $subject = $row['subject'];
            $topic_id = $row['topic_id'];
            
            if (!isset($students[$student_id])) {
                $students[$student_id] = [
                    'id' => $student_id,
                    'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                    'gender' => $row['gender'] ?? 'Unknown',
                    'stream' => $row['stream'],
                    'subjects' => []
                ];
            }
            
            if (!isset($students[$student_id]['subjects'][$subject])) {
                $students[$student_id]['subjects'][$subject] = [];
            }
            
            $students[$student_id]['subjects'][$subject][$topic_id] = $row['marks'];
        }
    }
    
    return $students;
}

// Function to calculate analytics
function calculateAnalytics($students_data, $analysis_type) {
    $analytics = [
        'summary' => [],
        'students' => [],
        'gender' => [],
        'subjects' => [],
        'grade_distribution' => []
    ];
    
    $processed_students = [];
    
    foreach ($students_data as $student_id => $student) {
        $student_performance = calculateStudentPerformance($student, $analysis_type);
        
        if ($student_performance['total_score'] !== null) {
            $processed_students[] = [
                'id' => $student['id'],
                'name' => $student['name'],
                'gender' => $student['gender'],
                'stream' => $student['stream'],
                'score' => $student_performance['total_score'],
                'grade' => $student_performance['grade'],
                'subjects_taken' => $student_performance['subjects_taken']
            ];
        }
    }
    
    // Sort by score (descending)
    usort($processed_students, function($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    
    // Calculate summary statistics
    $total_students = count($processed_students);
    if ($total_students > 0) {
        $total_score = array_sum(array_column($processed_students, 'score'));
        $class_average = $total_score / $total_students;
        $highest_score = $processed_students[0]['score'];
        $pass_count = count(array_filter($processed_students, function($s) { 
            return $s['score'] >= 50; 
        }));
        $pass_rate = ($pass_count / $total_students) * 100;
        
        $analytics['summary'] = [
            'total_students' => $total_students,
            'class_average' => round($class_average, 1),
            'highest_score' => round($highest_score, 1),
            'pass_rate' => round($pass_rate, 1)
        ];
    }
    
    // Gender analysis
    $male_students = array_filter($processed_students, function($s) { 
        return strtolower($s['gender']) === 'male'; 
    });
    $female_students = array_filter($processed_students, function($s) { 
        return strtolower($s['gender']) === 'female'; 
    });
    
    $male_average = 0;
    $female_average = 0;
    
    if (count($male_students) > 0) {
        $male_average = array_sum(array_column($male_students, 'score')) / count($male_students);
    }
    
    if (count($female_students) > 0) {
        $female_average = array_sum(array_column($female_students, 'score')) / count($female_students);
    }
    
    $analytics['gender'] = [
        'male_count' => count($male_students),
        'female_count' => count($female_students),
        'male_average' => round($male_average, 1),
        'female_average' => round($female_average, 1),
        'difference' => round(abs($female_average - $male_average), 1)
    ];
    
    // Grade distribution
    $grade_counts = [];
    $grades = ['A', 'B', 'C', 'D', 'E', 'F'];
    foreach ($grades as $grade) {
        $grade_counts[$grade] = count(array_filter($processed_students, function($s) use ($grade) {
            return $s['grade'] === $grade;
        }));
    }
    
    $analytics['grade_distribution'] = $grade_counts;
    
    // Students list
    $analytics['students'] = $processed_students;
    
    return $analytics;
}

// Function to calculate individual student performance
function calculateStudentPerformance($student, $analysis_type) {
    $total_score = 0;
    $subjects_taken = 0;
    $valid_subjects = 0;
    
    foreach ($student['subjects'] as $subject => $topics) {
        $aoi_marks = [];
        $eot_mark = null;
        
        // Separate AOI and EOT marks
        foreach ($topics as $topic_id => $mark) {
            if ($topic_id === 'EOT') {
                $eot_mark = $mark;
            } else {
                if ($mark !== null && $mark !== '' && $mark !== '-') {
                    $aoi_marks[] = floatval($mark);
                }
            }
        }
        
        // Calculate subject score based on analysis type
        $subject_score = null;
        
        if ($analysis_type === 'aoi' && !empty($aoi_marks)) {
            // AOI-only analysis
            $aoi_avg = array_sum($aoi_marks) / count($aoi_marks);
            $subject_score = ($aoi_avg / 3) * 100; // Convert from 3-point to percentage
        } elseif ($analysis_type === 'overall') {
            // Combined AOI + EOT analysis
            if (!empty($aoi_marks) && $eot_mark !== null && $eot_mark !== '' && $eot_mark !== '-') {
                $aoi_avg = array_sum($aoi_marks) / count($aoi_marks);
                $aoi_20_percent = ($aoi_avg / 3) * 20;
                $eot_80_percent = (floatval($eot_mark) / 100) * 80;
                $subject_score = $aoi_20_percent + $eot_80_percent;
            } elseif (!empty($aoi_marks)) {
                // AOI only if no EOT
                $aoi_avg = array_sum($aoi_marks) / count($aoi_marks);
                $subject_score = ($aoi_avg / 3) * 100;
            } elseif ($eot_mark !== null && $eot_mark !== '' && $eot_mark !== '-') {
                // EOT only
                $subject_score = floatval($eot_mark);
            }
        }
        
        if ($subject_score !== null) {
            $total_score += $subject_score;
            $valid_subjects++;
        }
        
        $subjects_taken++;
    }
    
    $average_score = $valid_subjects > 0 ? $total_score / $valid_subjects : null;
    $grade = calculateGrade($average_score);
    
    return [
        'total_score' => $average_score,
        'grade' => $grade,
        'subjects_taken' => $subjects_taken
    ];
}

// Function to calculate grade
function calculateGrade($score) {
    if ($score === null) return '-';
    
    if ($score >= 80) return 'A';
    if ($score >= 70) return 'B';
    if ($score >= 60) return 'C';
    if ($score >= 50) return 'D';
    if ($score >= 40) return 'E';
    return 'F';
}

// Get subjects for dropdown
function getSubjects($conn) {
    $subjects = [];
    $sql = "SELECT subj_name FROM subjects WHERE level LIKE 'O%' ORDER BY subj_name";
    $result = mysqli_query($conn, $sql);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $subjects[] = $row['subj_name'];
        }
    }
    
    return $subjects;
}

// Close database connection
mysqli_close($conn);
?>