<?php
// Start with script execution time tracking
$start_time = microtime(true);

// Enable output buffering for faster page loading
ob_start();

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once 'connection.php';

// Set higher memory limit if needed
ini_set('memory_limit', '1024M'); // Increased for processing multiple students

// Initialize variables with defaults
// student_id is now optional - if not provided, we'll generate reports for all students in class/stream
$student_id = $_GET['student_id'] ?? null;
$class = $_GET['class'] ?? 'Senior One';
$term = $_GET['term'] ?? 'Term 1';
$year = $_GET['year'] ?? date('Y');

// Fix: Make sure stream is initialized properly, accepting both array and string inputs
if (isset($_GET['stream']) && !is_array($_GET['stream'])) {
    // If stream is set but not an array, treat it as a single value
    $stream = [$_GET['stream']];
} else if (isset($_GET['stream']) && is_array($_GET['stream'])) {
    // If stream is already an array, use it as is
    $stream = $_GET['stream'];
} else {
    // Default array of streams if not provided
    $stream = ['A', 'B', 'C'];
}

$bulk_mode = empty($student_id); // Process all students if no specific student_id

// Convert term to roman numeral for table name early - reused in multiple functions
$term_number = filter_var($term, FILTER_SANITIZE_NUMBER_INT);
$romans = ['I', 'II', 'III'];
$term_roman = $romans[$term_number - 1] ?? 'I';
$table_name = "{$year}_{$term_roman}_olevel";

// Check if table exists once at the beginning
$table_exists = false;
$check_table_sql = "SHOW TABLES LIKE '$table_name'";
$check_result = mysqli_query($conn, $check_table_sql);
if ($check_result && mysqli_num_rows($check_result) > 0) {
    $table_exists = true;
}

// If table doesn't exist, show an error message
if (!$table_exists) {
    echo "<div class='alert alert-danger'>The marks table for {$term} {$year} does not exist. Please check your selections.</div>";
    exit;
}

// Initialize student_ids array to avoid undefined variable
$student_ids = [];

// Index creation for temporary performance boost (comment out in production if not needed permanently)
if ($table_exists) {
    // We'll use try/catch to avoid script termination if any errors occur
    try {
        $indexes_to_create = [
            "CREATE INDEX IF NOT EXISTS idx_{$table_name}_student_class ON `{$table_name}` (student_id, class)",
            "CREATE INDEX IF NOT EXISTS idx_{$table_name}_topic ON `{$table_name}` (topic_id)",
            "CREATE INDEX IF NOT EXISTS idx_{$table_name}_subject ON `{$table_name}` (subject)"
        ];
        
        foreach ($indexes_to_create as $index_sql) {
            mysqli_query($conn, $index_sql);
        }
    } catch (Exception $e) {
        // Silently handle any errors - these are just temporary performance optimizations
    }
}

// Get list of all students in the class/stream(s)
if ($bulk_mode && $table_exists) {
    // Fix: Check if stream exists and has elements
    if (!empty($stream)) {
        try {
            // For multiple streams
            $placeholders = implode(',', array_fill(0, count($stream), '?'));
            $sql = "SELECT DISTINCT student_id FROM `$table_name` WHERE class = ? AND stream IN ($placeholders) ORDER BY student_id";
            
            $stmt = mysqli_prepare($conn, $sql);
            
            if ($stmt) {
                // Create a proper type definition string
                $types = 's'; // Start with 's' for class
                
                // Add one 's' for each stream in the array
                foreach ($stream as $s) {
                    $types .= 's';
                }
                
                // Build the parameter array with the correct type string first
                $bind_params = array($types);
                
                // Add class parameter
                $bind_params[] = $class;
                
                // Add stream parameters 
                foreach ($stream as $s) {
                    $bind_params[] = $s;
                }
                
                // Convert to references for bind_param
                $refs = array();
                foreach($bind_params as $key => $value) {
                    $refs[$key] = &$bind_params[$key];
                }
                
                call_user_func_array(array($stmt, 'bind_param'), $refs);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                if ($result) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $student_ids[] = $row['student_id'];
                    }
                }
            }
        } catch (Exception $e) {
            // Handle error silently, student_ids will remain empty
            error_log("Error fetching student IDs: " . $e->getMessage());
        }
    } else {
        // No stream filter
        $sql = "SELECT DISTINCT student_id FROM `$table_name` WHERE class = ? ORDER BY student_id";
        $stmt = mysqli_prepare($conn, $sql);
        
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $class);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if ($result) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $student_ids[] = $row['student_id'];
                }
            }
        }
    }
} else if (!empty($student_id)) {
    // Single student mode
    $student_ids[] = $student_id;
}

// If no students found, show message
if (empty($student_ids)) {
    // Format the streams for display
    $stream_display = is_array($stream) ? implode(', ', $stream) : $stream;
    echo "<div class='alert alert-warning'>No students found in {$class} Stream(s) {$stream_display} for {$term} {$year}</div>";
    exit;
}

// Function to get student details
function getStudentDetails($conn, $student_id) {
    $sql = "SELECT * FROM students WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $student_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result && $row = mysqli_fetch_assoc($result)) {
        return $row;
    }
    
    return null;
}

// Function to get selected topics for a class, term, and year
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

// Function to get topic name from AOI table
function getTopicName($conn, $topic_id, $subject, &$topic_names_cache) {
    $cache_key = "{$topic_id}_{$subject}";
    
    if (isset($topic_names_cache[$cache_key])) {
        return $topic_names_cache[$cache_key];
    }
    
    // If it's EOT, return End of Term
    if ($topic_id === 'EOT') {
        $topic_names_cache[$cache_key] = 'End of Term';
        return 'End of Term';
    }
    
    // Try to get topic name from aoi table
    $sql = "SELECT topic FROM aoi WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $topic_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $topic_names_cache[$cache_key] = $row['topic'];
        return $row['topic'];
    }
    
    $default_name = "Topic " . $topic_id;
    $topic_names_cache[$cache_key] = $default_name;
    return $default_name;
}

// Function to get student marks for a specific subject and topic
function getStudentMark($all_marks, $subject, $topic_id) {
    return $all_marks[$subject][$topic_id] ?? null;
}

// Function to calculate subject average for AOI (excluding EOT)
function calculateAOIAverage($marks_array) {
    $total = 0;
    $count = 0;
    
    foreach ($marks_array as $mark) {
        if ($mark !== null && $mark !== '' && $mark !== '-') {
            $total += floatval($mark);
            $count++;
        }
    }
    
    return $count > 0 ? $total / $count : null;
}

// Function to convert marks to grade
function convertToGrade($marks) {
    if ($marks === null || $marks === '') {
        return '-';
    }
    
    $marks = floatval($marks);
    
    if ($marks >= 80) return 'A';
    if ($marks >= 70) return 'B';
    if ($marks >= 60) return 'C';
    if ($marks >= 50) return 'D';
    if ($marks >= 40) return 'E';
    return 'F';
}

// Function to get subject name and compulsory flag
function getSubjectInfo($conn, $subject_names_cache, $compulsory_cache) {
    // If cache is already populated, return it
    if (!empty($subject_names_cache)) {
        return [$subject_names_cache, $compulsory_cache];
    }
    
    $subject_names = [];
    $compulsory_subjects = [];
    
    $sql = "SELECT subj_name, subj_abbr, compulsory FROM subjects";
    $result = mysqli_query($conn, $sql);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            // Store subject name
            $subject_names[$row['subj_name']] = $row['subj_name'];
            $subject_names[$row['subj_abbr']] = $row['subj_name'];
            
            // Store compulsory flag
            $is_compulsory = (int)$row['compulsory'] === 1;
            $compulsory_subjects[$row['subj_name']] = $is_compulsory;
            $compulsory_subjects[$row['subj_abbr']] = $is_compulsory;
        }
    }
    
    return [$subject_names, $compulsory_subjects];
}

// Function to get teacher initials for a subject
function getTeacherInitials($conn, $subject, $class, &$cache) {
    $cache_key = "{$subject}_{$class}";
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }
    
    // Get subject abbreviation from subjects table
    $sql = "SELECT subj_abbr FROM subjects WHERE subj_name = ?";
    $stmt = mysqli_prepare($conn, $sql);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $subject);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($result && $row = mysqli_fetch_assoc($result)) {
            $cache[$cache_key] = $row['subj_abbr'];
            return $row['subj_abbr'];
        }
    }
    
    // If we can't find the subject abbreviation, use the first letter
    // of each word in the subject name as initials
    $words = explode(' ', $subject);
    $initials = '';
    
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
    }
    
    $cache[$cache_key] = $initials;
    return $initials;
}

// Function to check if a subject has any marks
function subjectHasMarks($marks_array, $eot_mark) {
    // Check if any AOI marks exist
    foreach ($marks_array as $mark) {
        if ($mark !== null && $mark !== '' && $mark !== '-') {
            return true;
        }
    }
    
    // Check if EOT mark exists
    if ($eot_mark !== null && $eot_mark !== '' && $eot_mark !== '-') {
        return true;
    }
    
    return false;
}

// Precompute comment and achievement maps for repeated use
$commentsMap = [
    'A' => 'Outstanding. The student expresses ideas clearly, both verbally and through writing.',
    'B' => 'Very good. The student shows a strong understanding of the subject material.',
    'C' => 'Satisfactory. The student is capable but needs to work on consistency.',
    'D' => 'Fair. The student needs to improve their understanding of key concepts.',
    'E' => 'Below average. The student requires additional support to grasp fundamental concepts.',
    'F' => 'Needs improvement. The student must dedicate more time to study and practice.',
    '-' => 'Missed Examinations & Assessments'
];

$achievementMap = [
    'A' => 'Distinction',
    'B' => 'Credit',
    'C' => 'Satisfactory',
    'D' => 'Pass',
    'E' => 'Elementary',
    'F' => 'Fail',
    '-' => 'Not Assessed'
];

// Get selected topics for class - only need to do this once
$selected_topics = getSelectedTopics($conn, $class, $term, $year);

// Get all subjects, compulsory flags, and teacher initials once
[$subject_names_cache, $compulsory_cache] = getSubjectInfo($conn, [], []);
$teacher_initials_cache = [];
$topic_names_cache = [];

// Preload all topic IDs and names
$topic_ids = [];
foreach ($selected_topics as $subject => $topics) {
    foreach ($topics as $topic_id) {
        if ($topic_id !== 'EOT' && !in_array($topic_id, $topic_ids)) {
            $topic_ids[] = $topic_id;
        }
    }
}

if (!empty($topic_ids)) {
    $placeholders = implode(',', array_fill(0, count($topic_ids), '?'));
    $sql = "SELECT id, topic FROM aoi WHERE id IN ($placeholders)";
    $stmt = mysqli_prepare($conn, $sql);
    
    if ($stmt) {
        $types = str_repeat('s', count($topic_ids));
        mysqli_stmt_bind_param($stmt, $types, ...$topic_ids);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                foreach ($selected_topics as $subject => $topics) {
                    if (in_array($row['id'], $topics)) {
                        $cache_key = "{$row['id']}_{$subject}";
                        $topic_names_cache[$cache_key] = $row['topic'];
                    }
                }
            }
        }
    }
    
    // Add 'End of Term' for all EOT topics
    foreach ($selected_topics as $subject => $topics) {
        if (in_array('EOT', $topics)) {
            $cache_key = "EOT_{$subject}";
            $topic_names_cache[$cache_key] = 'End of Term';
        }
    }
}

// Calculate total number of students in the class/stream
$total_students = count($student_ids);

// Format the streams for display in the title
$stream_display = is_array($stream) ? implode(', ', $stream) : $stream;

// Prepare for bulk processing
echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Class Reports - {$class} Streams {$stream_display} - {$term} {$year}</title>
    
    <!-- Load CSS -->
    <link rel='stylesheet' href='../assets/bootstrap/css/bootstrap.min.css'>
    <link rel='stylesheet' href='../assets/fonts/fontawesome-all.min.css'>
    
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.5;
        }
        .report-card {
            max-width: 1000px;
            margin: 0 auto 50px;
            padding: 20px;
            border: 1px solid #ccc;
            page-break-after: always;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .school-logo {
            max-width: 100px;
            height: auto;
        }
        .school-info {
            margin-bottom: 20px;
        }
        .school-name {
            font-size: 18px;
            font-weight: bold;
        }
        .student-info {
            margin-bottom: 20px;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 10px 0;
        }
        .student-photo {
            max-width: 100px;
            height: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 5px 8px;
            text-align: center;
            vertical-align: middle;
        }
        th {
            background-color: #f0f0f0;
            font-weight: bold;
        }
        .subject-name {
            text-align: left;
            font-weight: bold;
        }
        .topic-name {
            text-align: left;
            padding-left: 20px;
        }
        .comment {
            text-align: left;
            font-size: 11px;
        }
        .print-btn-container {
            position: fixed;
            top: 10px;
            right: 10px;
            z-index: 1000;
        }
        .eot-mark {
            color: #333;
            font-weight: bold;
        }
        .summary-table {
            margin-top: 30px;
            margin-bottom: 30px;
            border: 1px solid #ccc;
        }
        .summary-table td {
            font-weight: bold;
            padding: 8px 15px;
        }
        .comments-table td {
            text-align: left;
            padding: 10px 15px;
        }
        .grades-table {
            background-color: #f9f9f9;
        }
        .grades-table th {
            text-align: center;
            font-weight: bold;
        }
        .processing-status {
            position: fixed;
            top: 10px;
            left: 10px;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            z-index: 1000;
        }
        @media print {
            .print-btn-container, .processing-status {
                display: none;
            }
            .report-card {
                border: none;
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    
    <div class='processing-status'>
        <div>Processing <span id='current-count'>0</span> of <span id='total-count'>{$total_students}</span> students</div>
        <div class='progress mt-2'>
            <div id='progress-bar' class='progress-bar progress-bar-striped progress-bar-animated' role='progressbar' aria-valuenow='0' aria-valuemin='0' aria-valuemax='100' style='width: 0%'></div>
        </div>
    </div>";

// Get all students marks in a single query to improve performance
$all_students_marks = [];
if (!empty($student_ids) && $table_exists) {
    $id_list = implode("','", $student_ids);
    $marks_sql = "SELECT student_id, subject, topic_id, marks, stream FROM `$table_name` 
                 WHERE student_id IN ('$id_list') AND class = ?";
    
    $marks_stmt = mysqli_prepare($conn, $marks_sql);
    mysqli_stmt_bind_param($marks_stmt, "s", $class);
    mysqli_stmt_execute($marks_stmt);
    $marks_result = mysqli_stmt_get_result($marks_stmt);
    
    if ($marks_result) {
        while ($row = mysqli_fetch_assoc($marks_result)) {
            $sid = $row['student_id'];
            $subj = $row['subject'];
            $topic = $row['topic_id'];
            $mark = $row['marks'];
            $stream_value = $row['stream'] ?? '';
            
            if (!isset($all_students_marks[$sid])) {
                $all_students_marks[$sid] = [
                    'stream' => $stream_value,
                    'subjects' => []
                ];
            }
            if (!isset($all_students_marks[$sid]['subjects'][$subj])) {
                $all_students_marks[$sid]['subjects'][$subj] = [];
            }
            
            $all_students_marks[$sid]['subjects'][$subj][$topic] = $mark;
        }
    }
}

// Position calculation for all students at once - FIXED VERSION
$all_student_averages = [];
foreach ($student_ids as $sid) {
    if (!isset($all_students_marks[$sid])) continue;
    
    // Create a list of displayed subjects for this student
    $displayed_subjects = [];
    foreach ($selected_topics as $subject => $topics) {
        $subject_name = $subject_names_cache[$subject] ?? $subject;
        $is_compulsory = $compulsory_cache[$subject] ?? false;
        
        // Get marks for this subject
        $aoi_marks = [];
        $eot_mark = null;
        
        if (isset($all_students_marks[$sid]['subjects'][$subject])) {
            foreach ($topics as $topic_id) {
                $mark = $all_students_marks[$sid]['subjects'][$subject][$topic_id] ?? null;
                
                if ($topic_id === 'EOT') {
                    $eot_mark = $mark;
                } else {
                    $aoi_marks[$topic_id] = $mark;
                }
            }
        }
        
        // Check if subject has any marks
        $has_marks = subjectHasMarks($aoi_marks, $eot_mark);
        
        // Include this subject if it's compulsory OR if it has marks
        if ($is_compulsory || $has_marks) {
            $displayed_subjects[] = $subject;
        }
    }
    
    // Now calculate average based on all displayed subjects
    $student_total = 0;
    $total_displayed_subjects = count($displayed_subjects);
    
    foreach ($displayed_subjects as $subj) {
        $aoi_marks = [];
        $eot_mark = null;
        
        if (isset($all_students_marks[$sid]['subjects'][$subj])) {
            foreach ($all_students_marks[$sid]['subjects'][$subj] as $topic => $mark) {
                if ($topic === 'EOT') {
                    $eot_mark = $mark;
                } else {
                    $aoi_marks[$topic] = $mark;
                }
            }
        }
        
        // Calculate subject average
        $total = null;
        $has_aoi = !empty($aoi_marks);
        
        if ($has_aoi) {
            $aoi_total = 0;
            $aoi_count = 0;
            
            foreach ($aoi_marks as $mark) {
                if ($mark !== null && $mark !== '' && $mark !== '-') {
                    $aoi_total += floatval($mark);
                    $aoi_count++;
                }
            }
            
            $aoi_avg = $aoi_count > 0 ? $aoi_total / $aoi_count : null;
            
            // NEW FEATURE: If we have AOI but no EOT, convert AOI to percentage of 20
            if ($eot_mark === null || $eot_mark === '' || $eot_mark === '-') {
                if ($aoi_avg !== null) {
                    // Convert AOI average to percentage out of 20
                    $total = ($aoi_avg * 20) / 3;
                }
            } else {
                // Normal calculation with both AOI and EOT
                $aoi_20_percent = $aoi_avg !== null ? ($aoi_avg / 3 * 20) : null;
                $eot_80_percent = $eot_mark !== null ? ($eot_mark / 100 * 80) : null;
                
                if ($aoi_20_percent !== null && $eot_80_percent !== null) {
                    $total = $aoi_20_percent + $eot_80_percent;
                }
            }
        } else {
            $total = $eot_mark;
        }
        
        // If this subject has no valid marks, it contributes 0 to the average
        $total = $total ?? 0;
        $student_total += $total;
    }
    
    // Calculate average based on ALL displayed subjects, not just ones with marks
    $average = $total_displayed_subjects > 0 ? $student_total / $total_displayed_subjects : 0;
    $all_student_averages[$sid] = $average;
}

// Sort all averages in descending order for ranking
arsort($all_student_averages);

// Create position mapping
$position_map = [];
$pos = 1;
foreach ($all_student_averages as $sid => $avg) {
    $position_map[$sid] = $pos++;
}

// Process each student
$current_student = 0;
foreach ($student_ids as $student_id) {
    $current_student++;
    
    // Get student details
    $student = getStudentDetails($conn, $student_id);
    if (!$student) continue;
    
    // Skip if no marks data
    if (!isset($all_students_marks[$student_id])) continue;
    
    // Get all marks for current student
    $all_marks = $all_students_marks[$student_id]['subjects'] ?? [];
    
    // Current student's position
    $position = $position_map[$student_id] ?? $current_student;
    $position_text = $position . " out of " . $total_students;
    
    // Prepare display data for this student
    $display_data = [];
    foreach ($selected_topics as $subject => $topics) {
        $subject_name = $subject_names_cache[$subject] ?? $subject;
        $aoi_marks = [];
        $eot_mark = null;
        $rowcount = count($topics);
        
        // Collect all marks for this subject
        if (isset($all_marks[$subject])) {
            foreach ($topics as $topic_id) {
                $mark = $all_marks[$subject][$topic_id] ?? null;
                
                if ($topic_id === 'EOT') {
                    $eot_mark = $mark;
                } else {
                    $aoi_marks[$topic_id] = $mark;
                }
            }
        }
        
        // Check if we have only AOI and no EOT
        $has_aoi = !empty($aoi_marks);
        $aoi_only = $has_aoi && ($eot_mark === null || $eot_mark === '' || $eot_mark === '-');
        
        // Calculate subject data 
        $aoi_avg = null;
        $aoi_20_percent = null;
        $eot_80_percent = null;
        $total = null;
        
        if ($has_aoi) {
            $aoi_total = 0;
            $aoi_count = 0;
            
            foreach ($aoi_marks as $mark) {
                if ($mark !== null && $mark !== '' && $mark !== '-') {
                    $aoi_total += floatval($mark);
                    $aoi_count++;
                }
            }
            
            $aoi_avg = $aoi_count > 0 ? $aoi_total / $aoi_count : null;
            
            if ($aoi_only) {
                // NEW FEATURE: If only AOI marks available, convert to percentage out of 20
                $aoi_20_percent = null; // Not used in calculation
                $eot_80_percent = null; // Not used in calculation
                $total = $aoi_avg !== null ? ($aoi_avg * 20) / 3 : null;
            } else {
                // Normal calculation with both AOI and EOT
                $aoi_20_percent = $aoi_avg !== null ? ($aoi_avg / 3 * 20) : null;
                $eot_80_percent = $eot_mark !== null ? ($eot_mark / 100 * 80) : null;
                
                if ($aoi_20_percent !== null && $eot_80_percent !== null) {
                    $total = $aoi_20_percent + $eot_80_percent;
                }
            }
        } else {
            $aoi_avg = $eot_mark;
            $aoi_20_percent = $eot_mark !== null ? ($eot_mark / 100 * 20) : null;
            $eot_80_percent = $eot_mark !== null ? ($eot_mark / 100 * 80) : null;
            $total = $eot_mark;
        }
        
        // Get grade and comment
        $grade = $total === null ? '-' : 
                ($total >= 80 ? 'A' : 
                ($total >= 70 ? 'B' : 
                ($total >= 60 ? 'C' : 
                ($total >= 50 ? 'D' : 
                ($total >= 40 ? 'E' : 'F')))));
        
        $comment = $commentsMap[$grade] ?? $commentsMap['-'];
        
        // Get teacher initials
        $initials = getTeacherInitials($conn, $subject, $class, $teacher_initials_cache);
        
        // Check if subject is compulsory
        $is_compulsory = $compulsory_cache[$subject] ?? false;
        
        // Check if subject has any marks
        $has_marks = subjectHasMarks($aoi_marks, $eot_mark);
        
        // Skip this subject if it's not compulsory and has no marks
        if (!$is_compulsory && !$has_marks) {
            continue;
        }
        
        // Prepare topic details
        $topic_details = [];
        foreach ($topics as $topic_id) {
            $topic_name = getTopicName($conn, $topic_id, $subject, $topic_names_cache);
            $mark = $all_marks[$subject][$topic_id] ?? null;
            $is_eot = ($topic_id === 'EOT');
            
            $topic_details[] = [
                'id' => $topic_id,
                'name' => $topic_name,
                'mark' => $mark,
                'is_eot' => $is_eot
            ];
        }
        
        // Store all subject data for display
        $display_data[$subject] = [
            'name' => $subject_name,
            'rowcount' => $rowcount,
            'aoi_avg' => $aoi_avg,
            'aoi_20_percent' => $aoi_20_percent,
            'eot_mark' => $eot_mark,
            'eot_80_percent' => $eot_80_percent,
            'total' => $total,
            'grade' => $grade,
            'comment' => $comment,
            'initials' => $initials,
            'topics' => $topic_details,
            'has_aoi' => $has_aoi,
            'aoi_only' => $aoi_only // NEW: Flag to identify subjects with only AOI marks
        ];
    }
    
    // Recalculate average based on displayed subjects only - FIXED VERSION
    $displayed_subject_count = count($display_data);
    $student_total = 0;
    
    foreach ($display_data as $subject_data) {
        $subject_total = $subject_data['total'] ?? 0;
        $student_total += $subject_total;
    }
    
    // Calculate average based on all displayed subjects
    $overall_average = $displayed_subject_count > 0 ? $student_total / $displayed_subject_count : 0;
    
    // Calculate grade based on recalculated average
    $final_grade = $overall_average >= 80 ? 'A' : 
                   ($overall_average >= 70 ? 'B' : 
                   ($overall_average >= 60 ? 'C' : 
                   ($overall_average >= 50 ? 'D' : 
                   ($overall_average >= 40 ? 'E' : 'F'))));
    $achievement_level = $achievementMap[$final_grade] ?? $achievementMap['-'];
    
    // Get student's stream for display on the report
    $student_stream = $all_students_marks[$student_id]['stream'] ?? '';
    if (empty($student_stream) && $student && isset($student['stream'])) {
        $student_stream = $student['stream'];
    }
    
    // Generate the HTML for this student's report
    generateStudentReport($student, $display_data, $overall_average, $final_grade, $achievement_level, $position_text, $term, $year, $class, $student_stream);
    
    // Update progress via JavaScript
    echo "<script>
        document.getElementById('current-count').textContent = {$current_student};
        document.getElementById('progress-bar').style.width = " . ($current_student / $total_students * 100) . "%;
        document.getElementById('progress-bar').setAttribute('aria-valuenow', " . ($current_student / $total_students * 100) . ");
    </script>";
    
    // Flush output to show progress
    flush();
    ob_flush();
}

// Function to generate a single student report
function generateStudentReport($student, $display_data, $overall_average, $final_grade, $achievement_level, $position_text, $term, $year, $class, $stream = '') {
    $student_name = $student ? htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) : '';
    $student_id = $student ? htmlspecialchars($student['id']) : '';
    $student_stream = !empty($stream) ? htmlspecialchars($stream) : '';
    
    echo "
    <div class='report-card'>
        <div class='row header'>
            <div class='col-md-3'>
                <img src='../assets/img/logo.jpg' alt='School Logo' class='school-logo' loading='lazy'>
            </div>
            <div class='col-md-6 school-info'>
                <div class='school-name'>MACKAY MEMORIAL COLLEGE, NATEETE</div>
                <div>PO BOX 19 KAMPALA - UGANDA, 3KM FROM TOWN NATEETE ROAD</div>
                <div>TEL:0441467815, EMAIL: MACKAYCOLLEGESCHOOL@GMAIL.COM</div>
                <div class='mt-3'>
                    <strong>LEARNER'S REPORT CARD FOR TERM " . htmlspecialchars($term) . ", YEAR: " . htmlspecialchars($year) . "</strong>
                </div>
            </div>
            <div class='col-md-3'>";
    
    if ($student && isset($student['photo'])) {
        echo "<img src='" . htmlspecialchars($student['photo']) . "' alt='Student Photo' class='student-photo' loading='lazy'>";
    } else {
        echo "<img src='../assets/img/avatar.png' alt='Student Photo' class='student-photo' loading='lazy'>";
    }
    
    echo "</div>
        </div>
        
        <div class='student-info row'>
            <div class='col-md-3'>
                <strong>Student Name:</strong> {$student_name}
            </div>
            <div class='col-md-3'>
                <strong>Student ID:</strong> {$student_id}
            </div>
            <div class='col-md-3'>
                <strong>Class:</strong> " . htmlspecialchars($class) . "
            </div>
            <div class='col-md-3'>
                <strong>Stream:</strong> " . $student_stream . "
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>SUBJECT</th>
                    <th>TOPIC</th>
                    <th>SCORE</th>
                    <th>AVG</th>
                    <th>TOTAL(100%)</th>
                    <th>GRADE</th>
                    <th>COMMENT</th>
                    <th>INITIALS</th>
                </tr>
            </thead>
            <tbody>";
    
    // Generate table rows for each subject
    foreach ($display_data as $subject => $data) {
        $rowcount = $data['rowcount'];
        $topics = $data['topics'];
        
        if (empty($topics)) continue;
        
        $first_topic = $topics[0];
        
        // First row
        echo "<tr>";
        echo "<td rowspan='{$rowcount}' class='subject-name'>" . htmlspecialchars($data['name']) . "</td>";
        echo "<td class='topic-name'>" . htmlspecialchars($first_topic['name']) . "</td>";
        
        // Display mark with proper formatting
        if ($first_topic['is_eot']) {
            echo "<td class='eot-mark'>";
            if ($data['has_aoi']) {
                echo ($data['eot_80_percent'] !== null ? round($data['eot_80_percent']) : '-');
            } else {
                echo ($first_topic['mark'] !== null ? round($first_topic['mark']) : '-');
            }
            echo "</td>";
        } else {
            // For AOI topics, display mark with one decimal place as stored in DB
            echo "<td>" . ($first_topic['mark'] !== null && $first_topic['mark'] !== '' ? number_format((float)$first_topic['mark'], 1) : '-') . "</td>";
        }
        
        // Analysis columns (with rowspan)
        echo "<td rowspan='{$rowcount}'>";
        if ($data['has_aoi'] && $data['aoi_avg'] !== null) {
            // Display AOI average with one decimal place
            echo number_format($data['aoi_avg'], 1);
            
            // Display AOI percentage if not AOI-only calculation
            if (!$data['aoi_only'] && $data['aoi_20_percent'] !== null) {
                echo "<br><small>(" . round($data['aoi_20_percent']) . ")</small>";
            }
        } else if ($data['eot_mark'] !== null) {
            // For EOT marks, show as whole numbers
            echo round($data['eot_mark']);
        } else {
            echo "-";
        }
        echo "</td>";
        
        // TOTAL column - show the final total score
        echo "<td rowspan='{$rowcount}'>";
        if ($data['total'] !== null) {
            if ($data['aoi_only']) {
                // For AOI-only subjects, display total as a whole number (out of 20)
                echo round($data['total']);
            } else {
                // For normal subjects with EOT, display as whole number
                echo round($data['total']);
            }
        } else {
            echo "-";
        }
        echo "</td>";
        
        echo "<td rowspan='{$rowcount}'>" . htmlspecialchars($data['grade']) . "</td>";
        echo "<td rowspan='{$rowcount}' class='comment'>" . htmlspecialchars($data['comment']) . "</td>";
        echo "<td rowspan='{$rowcount}'>" . htmlspecialchars($data['initials']) . "</td>";
        echo "</tr>";
        
        // Subsequent topic rows
        for ($i = 1; $i < count($topics); $i++) {
            $topic = $topics[$i];
            
            echo "<tr>";
            echo "<td class='topic-name'>" . htmlspecialchars($topic['name']) . "</td>";
            
            // Display mark with proper formatting
            if ($topic['is_eot']) {
                echo "<td class='eot-mark'>";
                echo ($data['eot_80_percent'] !== null ? round($data['eot_80_percent']) : '-');
                echo "</td>";
            } else {
                // For AOI topics, display mark with one decimal place as stored in DB
                echo "<td>" . ($topic['mark'] !== null && $topic['mark'] !== '' ? number_format((float)$topic['mark'], 1) : '-') . "</td>";
            }
            
            echo "</tr>";
        }
    }
    
    echo "</tbody>
        </table>
        
        <!-- Summary Table -->
        <table class='summary-table'>
            <tr>
                <td style='width: 25%;'>Average Score: " . number_format($overall_average, 1) . "</td>
                <td style='width: 25%;'>Final Grade: " . htmlspecialchars($final_grade) . "</td>
                <td style='width: 25%;'>Achievement: " . htmlspecialchars($achievement_level) . "</td>
                <td style='width: 25%;'>Position: " . htmlspecialchars($position_text) . "</td>
            </tr>
        </table>
        
        <!-- Comments Table -->
        <table class='comments-table'>
            <tr>
                <td style='width: 30%;'>Class Teachers Comment:</td>
                <td>" . htmlspecialchars($student_name) . " needs significant support to address fundamental learning challenges.</td>
                <td style='width: 15%;'>Signature</td>
            </tr>
            <tr>
                <td>HeadTeachers Comment:</td>
                <td>" . htmlspecialchars($student_name) . " demonstrates critical academic gaps that require urgent attention.</td>
                <td>Signature</td>
            </tr>
        </table>
        
        <!-- Grades Table -->
        <table class='grades-table'>
            <tr>
                <th style='width: 20%;'>GRADES</th>
                <th style='width: 13.33%;'>A</th>
                <th style='width: 13.33%;'>B</th>
                <th style='width: 13.33%;'>C</th>
                <th style='width: 13.33%;'>D</th>
                <th style='width: 13.33%;'>E</th>
                <th style='width: 13.33%;'>F</th>
            </tr>
            <tr>
                <td style='font-weight: bold;'>SCORE RANGE</td>
                <td>80-100</td>
                <td>70-79</td>
                <td>60-69</td>
                <td>50-59</td>
                <td>40-49</td>
                <td>0-39</td>
            </tr>
        </table>
    </div>";
}

// Calculate execution time
$end_time = microtime(true);
$execution_time = round($end_time - $start_time, 2);

// Close HTML document
echo "
    <script>
        // Hide progress bar when done
        document.querySelector('.processing-status').style.display = 'none';
        
        // Show completion message
        console.log('All " . count($student_ids) . " reports generated in {$execution_time} seconds');
    </script>
    
</body>
</html>";

// Close database connection
mysqli_close($conn);
?>