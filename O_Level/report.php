<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start with script execution time tracking
$start_time = microtime(true);

// Enable output buffering for faster page loading
ob_start();

require_once '../auth.php';
require_once '../conn.php';


// Set higher memory limit if needed
ini_set('memory_limit', '1024M'); // Increased for processing multiple students

// Initialize variables with defaults
// student_id is now optional - if not provided, we'll generate reports for all students in class/stream
$student_id = $_GET['student_id'] ?? null;
$class = $_GET['class'] ?? 'Senior One';
$term = $_GET['term'] ?? 'Term 1';
$year = $_GET['year'] ?? date('Y');

$school_details = getSchoolProfile($conn);

// NEW: Get display_eot_scores parameter
$display_eot_scores = isset($_GET['display_eot_scores']) && $_GET['display_eot_scores'] == '1';

// NEW: Get display_student_position parameter
$display_student_position = isset($_GET['display_student_position']) && $_GET['display_student_position'] == '1';

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

// Convert term to roman numeral for table name
$term_number = filter_var($term, FILTER_SANITIZE_NUMBER_INT);
$romans = ['i', 'ii', 'iii']; // Use lowercase Roman numerals
$term_roman = $romans[$term_number - 1] ?? 'i';
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
                foreach ($bind_params as $key => $value) {
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
function getStudentDetails($conn, $student_id)
{
    $sql = "SELECT * FROM students WHERE student_id = ?";
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
function getSelectedTopics($conn, $class, $term, $year)
{
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

// Function to get topic name and competency description from AOI table
function getTopicDetails($conn, $topic_id, $subject, &$topic_cache)
{
    $cache_key = "{$topic_id}_{$subject}";

    if (isset($topic_cache[$cache_key])) {
        return $topic_cache[$cache_key];
    }

    // If it's EOT, return End of Term with no competency
    if ($topic_id === 'EOT') {
        $topic_cache[$cache_key] = [
            'name' => 'End of Term',
            'competency' => 'End of term assessment covering all topics studied.'
        ];
        return $topic_cache[$cache_key];
    }

    // Try to get topic details from aoi table
    $sql = "SELECT topic, description FROM aoi WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $topic_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result && $row = mysqli_fetch_assoc($result)) {
        $topic_cache[$cache_key] = [
            'name' => $row['topic'],
            'competency' => $row['description'] ?? 'No competency description available.'
        ];
        return $topic_cache[$cache_key];
    }

    $default_details = [
        'name' => "Topic " . $topic_id,
        'competency' => 'Competency description not available.'
    ];
    $topic_cache[$cache_key] = $default_details;
    return $default_details;
}

// Function to get student marks for a specific subject and topic
function getStudentMark($all_marks, $subject, $topic_id)
{
    return $all_marks[$subject][$topic_id] ?? null;
}

// Function to calculate subject average for AOI (excluding EOT)
function calculateAOIAverage($marks_array)
{
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

// Function to convert AOI marks to identifier (1, 2, or 3)
function convertToIdentifier($marks)
{
    if ($marks === null || $marks === '') {
        return '-';
    }

    $marks = floatval($marks);

    if ($marks >= 2.5) return '3'; // Outstanding
    if ($marks >= 1.5) return '2'; // Moderate  
    if ($marks >= 0.9) return '1'; // Basic
    return '-'; // Below basic range
}

// Function to convert marks to grade (keep original for overall calculations)
function convertToGrade($marks)
{
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
function getSubjectInfo($conn, $subject_names_cache, $compulsory_cache)
{
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

// Function to get teacher initials
function getTeacherInitials($conn, $subject, $class, $stream, &$cache)
{
    // Create a unique cache key for each specific assignment
    $cache_key = "{$subject}_{$class}_{$stream}";
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    // SQL to get initials by joining the necessary tables
    $sql = "
        SELECT 
            UPPER(CONCAT(SUBSTRING(s.first_name, 1, 1), SUBSTRING(s.last_name, 1, 1))) AS initials
        FROM teaching_assignments ta
        JOIN staff s ON ta.staff_id = s.id
        JOIN subjects subj ON ta.subject_id = subj.subj_id
        WHERE subj.subj_name = ? AND ta.class_name = ? AND (ta.stream_name = ? OR ta.stream_name = 'All Streams')
        LIMIT 1
    ";

    $initials = '-'; // Default value if no teacher is found
    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "sss", $subject, $class, $stream);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($row = mysqli_fetch_assoc($result)) {
            $initials = $row['initials'];
        }
        mysqli_stmt_close($stmt);
    }

    // Cache the result and return it
    $cache[$cache_key] = $initials;
    return $initials;
}

// Function to check if subject has marks
function subjectHasMarks($marks_array, $eot_mark)
{
    // Note: $marks_array here might contain nulls from reordering
    // So, filter out nulls/empty/hyphens before checking for existence
    $filtered_marks = array_filter($marks_array, function ($mark) {
        return $mark !== null && $mark !== '' && $mark !== '-';
    });

    if (!empty($filtered_marks)) {
        return true;
    }

    if ($eot_mark !== null && $eot_mark !== '' && $eot_mark !== '-') {
        return true;
    }

    return false;
}

// Function to get school profile details
function getSchoolProfile($conn)
{
    // Fetches the most recently updated school profile
    $sql = "SELECT * FROM school_profile ORDER BY id DESC LIMIT 1";
    $result = mysqli_query($conn, $sql);
    if ($result && $row = mysqli_fetch_assoc($result)) {
        return $row;
    }
    return null; // Return null if no profile is found
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
$topic_cache = [];

// Preload all topic IDs, names, and competencies
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
    $sql = "SELECT id, topic, description FROM aoi WHERE id IN ($placeholders)";
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
                        $topic_cache[$cache_key] = [
                            'name' => $row['topic'],
                            'competency' => $row['description'] ?? 'No competency description available.'
                        ];
                    }
                }
            }
        }
    }

    // Add 'End of Term' details for all EOT topics (only if EOT display is enabled)
    if ($display_eot_scores) {
        foreach ($selected_topics as $subject => $topics) {
            if (in_array('EOT', $topics)) {
                $cache_key = "EOT_{$subject}";
                $topic_cache[$cache_key] = [
                    'name' => 'End of Term',
                    'competency' => 'End of term assessment covering all topics studied.'
                ];
            }
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

   <link rel='preconnect' href='https://fonts.googleapis.com'>
<link rel='preconnect' href='https://fonts.gstatic.com' crossorigin>
<link href='https://fonts.googleapis.com/css2?family=Cinzel:wght@400..900&family=Quicksand:wght@600..700&display=swap' rel='stylesheet'>

    <link rel='stylesheet' href='../assets/bootstrap/css/bootstrap.min.css'>
    <link rel='stylesheet' href='../assets/fonts/fontawesome-all.min.css'>

   <style>
   * {
   font-family: 'Quicksand', sans-serif;
}
body {
   font-size: 14px; /* Increased from 12px */
   line-height: 1.6; /* Increased from 1.5 */
}
.report-card {
   max-width: 1200px;
   margin: 0 auto 50px;
   padding: 20px;
   page-break-after: always;
   position: relative;
   overflow: hidden;
   background-color: transparent !important;
}

.header {
   text-align: center;
   margin-bottom: 20px;
   position: relative;
   z-index: 1;
}
.school-logo {
   max-width: 120px;
   height: auto;
}
.school-info {
   margin-bottom: 20px;
   position: relative;
   z-index: 1;
}
.school-name {
   font-size: 22px; /* Increased from 18px */
   font-weight: bold;
}
.school-info div {
   font-size: 14px; /* Added explicit size */
   margin: 3px 0;
}
.student-info {
   margin-bottom: 20px;
   border-top: 1px solid #000;
   border-bottom: 1px solid #000;
   padding: 10px 0;
   position: relative;
   z-index: 1;
   font-size: 14px; /* Increased from 12px */
}
.student-photo {
   max-width: 100px;
   height: auto;
}
table {
   width: 100%;
   border-collapse: collapse;
   margin-bottom: 20px;
   position: relative;
   z-index: 1;
   background-color: transparent !important;
}
th, td {
   border: 1px solid #ccc;
   padding: 8px 10px; /* Increased from 5px 8px */
   text-align: center;
   vertical-align: middle;
   background-color: rgba(255, 255, 255, 0.7) !important;
   font-size: 13px; /* Increased from 12px */
}
th {
   background-color: #f0f0f0;
   font-weight: bold;
   font-size: 14px; /* Increased from 13px */
}
.subject-name {
   text-align: left;
   font-weight: bold;
   font-size: 14px; /* Increased from 13px */
}
.topic-name {
   text-align: left;
   padding-left: 10px;
   font-size: 13px; /* Increased from 11px */
}
.competency-description {
   text-align: left;
   padding: 5px;
   font-size: 12px; /* Increased from 10px */
   line-height: 1.4; /* Increased from 1.3 */
}
.comment {
   text-align: left;
   font-size: 13px; /* Increased from 11px */
}
.print-btn-container {
   position: fixed;
   top: 10px;
   right: 10px;
   z-index: 1000;
}

@media print {
   .print-btn-container {
       display: none !important;
   }
}
.eot-mark {
   color: #333;
   font-weight: bold;
   font-size: 14px; /* Added explicit size */
}
.summary-table {
   margin-top: 30px;
   margin-bottom: 30px;
   border: 1px solid #ccc;
}
.summary-table td {
   font-weight: bold;
   padding: 10px 15px; /* Increased from 8px 15px */
   font-size: 14px; /* Increased from 13px */
}
.comments-table td {
   text-align: left;
   padding: 12px 15px; /* Increased from 10px 15px */
   font-size: 13px; /* Added explicit size */
}
.grades-table {
   background-color: #f9f9f9;
}
.grades-table th {
   text-align: center;
   font-weight: bold;
   font-size: 14px; /* Added explicit size */
}
.grades-table td {
   font-size: 13px; /* Added explicit size */
   padding: 8px 10px;
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
   font-size: 14px; /* Added explicit size */
}

.results-table-container {
   position: relative;
   margin-bottom: 20px;
}

.table-watermark {
   position: absolute;
   top: 50%;
   left: 50%;
   transform: translate(-50%, -50%);
   opacity: 0.4;
   width: 70%; 
   height: auto;
   pointer-events: none;
   z-index: 0;
}
.results-table th,
.results-table td {
   background-color: rgba(255, 255, 255, 0.8) !important;
}

.results-table {
   position: relative;
   z-index: 1;
   background-color: transparent !important;
}

/* Additional styles to ensure content prints over watermark */
table, .header, .student-info, .school-info, .summary-table, .comments-table, .grades-table {
   position: relative !important;
   z-index: 5 !important;
   -webkit-print-color-adjust: exact !important;
   color-adjust: exact !important;
   print-color-adjust: exact !important;
}

/* Force all cells to have white background in print */
th, td {
   background-color: rgba(255, 255, 255, 0.7) !important;
   -webkit-print-color-adjust: exact !important;
   color-adjust: exact !important;
   print-color-adjust: exact !important;
}

/* Print-specific styles */
@media print {
   body {
       font-size: 13px; /* Slightly smaller for print but still readable */
   }
   
   .table-watermark {
       opacity: 0.3 !important;
       -webkit-print-color-adjust: exact !important;
       print-color-adjust: exact !important;
   }
   
   th, td {
       font-size: 12px; /* Adjusted for print */
   }
   
   .subject-name {
       font-size: 13px;
   }
   
   .topic-name {
       font-size: 12px;
   }
   
   .competency-description {
       font-size: 11px;
   }
}

/* Ensure everything gets printed */
* {
   print-color-adjust: exact !important;
}
    </style>
</head>
<body>
    <div class='print-btn-container'>
        <button class='btn btn-primary' onclick='window.print();'>
            <i class='fas fa-print'></i> Print All Reports
        </button>
    </div>
    
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

// Position calculation for all students at once (only if position display is enabled)
$all_student_averages = [];
$position_map = [];

if ($display_student_position) {
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

                    if ($topic_id === 'EOT' && $display_eot_scores) {
                        $eot_mark = $mark;
                    } else if ($topic_id !== 'EOT') {
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

        // Calculate average as total AOI scores divided by number of subjects
        $total_aoi_score = 0;
        $total_displayed_subjects = count($displayed_subjects);

        foreach ($displayed_subjects as $subj) {
            $aoi_marks = [];

            if (isset($all_students_marks[$sid]['subjects'][$subj])) {
                foreach ($all_students_marks[$sid]['subjects'][$subj] as $topic => $mark) {
                    if ($topic !== 'EOT') {
                        $aoi_marks[$topic] = $mark;
                    }
                }
            }

            // Calculate AOI average for this subject
            if (!empty($aoi_marks)) {
                $aoi_total = 0;
                $aoi_count = 0;

                foreach ($aoi_marks as $mark) {
                    if ($mark !== null && $mark !== '' && $mark !== '-') {
                        $aoi_total += floatval($mark);
                        $aoi_count++;
                    }
                }

                $aoi_avg = $aoi_count > 0 ? $aoi_total / $aoi_count : 0;
            } else {
                $aoi_avg = 0;
            }

            $total_aoi_score += $aoi_avg;
        }

        // Calculate overall average AOI score
        $average = $total_displayed_subjects > 0 ? $total_aoi_score / $total_displayed_subjects : 0;
        $all_student_averages[$sid] = $average;
    }

    // Sort all averages in descending order for ranking
    arsort($all_student_averages);

    // Create position mapping
    $pos = 1;
    foreach ($all_student_averages as $sid => $avg) {
        $position_map[$sid] = $pos++;
    }
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

    // PASTE THE CODE BLOCK HERE
    // Get student's stream for display on the report
    $student_stream = $all_students_marks[$student_id]['stream'] ?? '';
    if (empty($student_stream) && $student && isset($student['stream'])) {
        $student_stream = $student['stream'];
    }

    // Current student's position (only if position display is enabled)
    $position_text = null;

    // Current student's position (only if position display is enabled)
    $position_text = null;
    if ($display_student_position) {
        $position = $position_map[$student_id] ?? $current_student;
        $position_text = $position . " out of " . $total_students;
    }

    // Prepare display data for this student
    $display_data = [];
    foreach ($selected_topics as $subject => $topics) {
        $subject_name = $subject_names_cache[$subject] ?? $subject;
        $aoi_marks = [];
        $eot_mark = null;

        // NEW: Filter topics based on display_eot_scores setting
        $filtered_topics = [];
        foreach ($topics as $topic_id) {
            if ($topic_id === 'EOT') {
                if ($display_eot_scores) {
                    $filtered_topics[] = $topic_id;
                }
            } else {
                $filtered_topics[] = $topic_id;
            }
        }

        $rowcount = count($filtered_topics);

        // Collect all marks for this subject (using filtered topics)
        if (isset($all_marks[$subject])) {
            foreach ($filtered_topics as $topic_id) {
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

            if ($aoi_only || !$display_eot_scores) {
                // NEW FEATURE: If we have AOI but no EOT (or EOT disabled), convert AOI to percentage out of 20
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

        // Get grade and identifier based on AOI average
        $grade = $total === null ? '-' : ($total >= 80 ? 'A' : ($total >= 70 ? 'B' : ($total >= 60 ? 'C' : ($total >= 50 ? 'D' : ($total >= 40 ? 'E' : 'F')))));

        // NEW: Get identifier based on AOI average (1, 2, 3 scale)
        $identifier = $aoi_avg !== null ? convertToIdentifier($aoi_avg) : '-';

        $comment = $commentsMap[$grade] ?? $commentsMap['-'];

        // Get teacher initials
        $initials = getTeacherInitials($conn, $subject_name, $class, $student_stream, $teacher_initials_cache);

        // Check if subject is compulsory
        $is_compulsory = $compulsory_cache[$subject] ?? false;

        // Check if subject has any marks
        $has_marks = subjectHasMarks($aoi_marks, $eot_mark);

        // Skip this subject if it's not compulsory and has no marks
        if (!$is_compulsory && !$has_marks) {
            continue;
        }

        // Prepare topic details with competency descriptions (using filtered topics)
        $topic_details = [];
        foreach ($filtered_topics as $topic_id) {
            $topic_info = getTopicDetails($conn, $topic_id, $subject, $topic_cache);
            $mark = $all_marks[$subject][$topic_id] ?? null;
            $is_eot = ($topic_id === 'EOT');

            $topic_details[] = [
                'id' => $topic_id,
                'name' => $topic_info['name'],
                'competency' => $topic_info['competency'],
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
            'identifier' => $identifier, // NEW: Store identifier
            'comment' => $comment,
            'initials' => $initials,
            'topics' => $topic_details,
            'has_aoi' => $has_aoi,
            'aoi_only' => $aoi_only // NEW: Flag to identify subjects with only AOI marks
        ];
    }

    // NEW: Calculate average as total AOI scores divided by number of subjects
    $displayed_subject_count = count($display_data);
    $total_aoi_score = 0;

    foreach ($display_data as $subject_data) {
        $aoi_avg = $subject_data['aoi_avg'] ?? 0;
        $total_aoi_score += $aoi_avg;
    }

    // Calculate overall average AOI score
    $overall_average = $displayed_subject_count > 0 ? $total_aoi_score / $displayed_subject_count : 0;

    // NEW: Determine achievement level based on AOI scale (Basic/Moderate/Outstanding)
    if ($overall_average >= 2.5) {
        $achievement_level = 'Outstanding';
    } else if ($overall_average >= 1.5) {
        $achievement_level = 'Moderate';
    } else if ($overall_average >= 0.9) {
        $achievement_level = 'Basic';
    } else {
        $achievement_level = 'Not Assessed';
    }

    // Remove final grade - no longer needed
    $final_grade = null;

    // Generate the HTML for this student's report
generateStudentReport($student, $display_data, $overall_average, $achievement_level, $position_text, $term, $year, $class, $student_stream, $display_eot_scores, $display_student_position, $school_details);

    // Update progress via JavaScript
    echo "<script>
        document.getElementById('current-count').textContent = {$current_student};
        document.getElementById('progress-bar').style.width = " . ($current_student / $total_students * 100) . "%;
        document.getElementById('progress-bar').setAttribute('aria-valuenow', " . ($current_student / $total_students * 100) . ");
    </script>";
}

// Function to generate a single student report
function generateStudentReport($student, $display_data, $overall_average, $achievement_level, $position_text, $term, $year, $class, $stream, $display_eot_scores, $display_student_position, $school_details)
{
    $student_name = $student ? htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) : '';
    $student_id_display = $student ? htmlspecialchars($student['student_id']) : '';
    $student_stream = !empty($stream) ? htmlspecialchars($stream) : '';

    echo "
    <div class='report-card'> 
        <div class='row header'>
    <div class='col-3'>
        <img src='" . htmlspecialchars($school_details['logo_path'] ?? '../assets/img/logo.jpg') . "' alt='School Logo' class='school-logo' loading='lazy'>
    </div>
    <div class='col-6 school-info'>
        <div class='school-name'>" . htmlspecialchars($school_details['school_name'] ?? 'School Name Not Set') . "</div>
        <div>" . htmlspecialchars($school_details['pobox'] ?? '') . " " . htmlspecialchars($school_details['address'] ?? 'Address Not Set') . "</div>
        <div>TEL: " . htmlspecialchars($school_details['phone'] ?? 'Phone Not Set') . ", EMAIL: " . htmlspecialchars($school_details['email'] ?? 'Email Not Set') . "</div>
        <div class='mt-3'>
            <strong>LEARNER'S REPORT CARD FOR TERM " . htmlspecialchars($term) . ", YEAR: " . htmlspecialchars($year) . "</strong>
        </div>
    </div>
            <div class='col-3'>";

    if ($student && isset($student['photo'])) {
        echo "<img src='" . htmlspecialchars($student['photo']) . "' alt='Student Photo' class='student-photo' loading='lazy'>";
    } else {
        echo "<img src='../assets/img/avatar.png' alt='Student Photo' class='student-photo' loading='lazy'>";
    }

    echo "</div>
        </div>
        
        <div class='student-info row'>
            <div class='col-3'>
                <strong>Student Name:</strong> {$student_name}
            </div>
            <div class='col-3'>
          <strong>Student ID:</strong> {$student_id_display}
            </div>
            <div class='col-3'>
                <strong>Class:</strong> " . htmlspecialchars($class) . "
            </div>
            <div class='col-3'>
                <strong>Stream:</strong> " . $student_stream . "
            </div>
        </div>

    <div class='results-table-container'>
        <!-- Table watermark -->
        <img src='../assets/img/logo.jpg' alt='School Logo Watermark' class='table-watermark' loading='lazy'>
        
        <table class='results-table'>
            <thead>
                <tr>
                    <th style='width: 12%;'>SUBJECT</th>
                    <th style='width: 15%;'>TOPIC</th>
                    <th style='width: 25%;'>COMPETENCY</th>
                    <th style='width: 8%;'>SCORE</th>
                    <th style='width: 10%;'>DESCRIPTOR</th>
                    <th style='width: 10%;'>IDENTIFIER</th>
                    <th style='width: 10%;'>INITIALS</th>
                </tr>
            </thead>
            <tbody>";

    // Generate table rows for each subject
    foreach ($display_data as $subject => $data) {
        $topics = $data['topics'];
        if (empty($topics)) continue;

        // Calculate correct rowspan (number of topic rows only, NOT including average row)
        $topic_rowspan = count($topics);

        $first_topic = $topics[0];

        // First row with rowspan for subject columns
        echo "<tr>";
        echo "<td rowspan='{$topic_rowspan}' class='subject-name'>" . htmlspecialchars($data['name']) . "</td>";
        echo "<td class='topic-name'>" . htmlspecialchars($first_topic['name']) . "</td>";
        echo "<td class='competency-description'>" . htmlspecialchars($first_topic['competency']) . "</td>";

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

        // Analysis columns (with rowspan) - showing descriptor based on identifier
        echo "<td rowspan='{$topic_rowspan}'>";
        // Show descriptor based on identifier instead of average
        if ($data['identifier'] === '3') {
            echo "Outstanding";
        } else if ($data['identifier'] === '2') {
            echo "Moderate";
        } else if ($data['identifier'] === '1') {
            echo "Basic";
        } else {
            echo "-";
        }
        echo "</td>";

        // IDENTIFIER column - show the identifier (1, 2, or 3)
        echo "<td rowspan='{$topic_rowspan}'>" . htmlspecialchars($data['identifier']) . "</td>";

        // TEACHER column
        echo "<td rowspan='{$topic_rowspan}'>" . htmlspecialchars($data['initials']) . "</td>";
        echo "</tr>";

        // Subsequent topic rows (only topic, competency, and score columns)
        for ($i = 1; $i < count($topics); $i++) {
            $topic = $topics[$i];

            echo "<tr>";
            echo "<td class='topic-name'>" . htmlspecialchars($topic['name']) . "</td>";
            echo "<td class='competency-description'>" . htmlspecialchars($topic['competency']) . "</td>";

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

        // Add subject average row SEPARATE from the rowspan - this closes each subject section
        echo "<tr style='background-color: rgba(240, 240, 240, 0.7);'>";
        echo "<td colspan='3' class='subject-name' style='text-align: right; font-weight: bold; background-color: rgba(240, 240, 240, 0.7);'>Subject Average AOI Score:</td>";
        echo "<td style='font-weight: bold; background-color: rgba(240, 240, 240, 0.7);'>";
        if ($data['aoi_avg'] !== null) {
            echo number_format($data['aoi_avg'], 1);
        } else {
            echo "-";
        }
        echo "</td>";
        echo "<td colspan='3' style='background-color: rgba(240, 240, 240, 0.7);'></td>";  // colspan 3 to cover descriptor, identifier, and teacher columns
        echo "</tr>";
    }

    echo "</tbody>
        </table>
        </div>";

    // Conditionally build summary table based on position display setting
    echo "<table class='summary-table'>";
    echo "<tr>";

    if ($display_student_position && $position_text !== null) {
        // If position is enabled, show all three columns
        echo "<td style='width: 33.33%;'>Average AOI Score: " . number_format($overall_average, 1) . "</td>";
        echo "<td style='width: 33.33%;'>Achievement Level: " . htmlspecialchars($achievement_level) . "</td>";
        echo "<td style='width: 33.33%;'>Position: " . htmlspecialchars($position_text) . "</td>";
    } else {
        // If position is disabled, only show average and achievement level (50% width each)
        echo "<td style='width: 50%;'>Average AOI Score: " . number_format($overall_average, 1) . "</td>";
        echo "<td style='width: 50%;'>Achievement Level: " . htmlspecialchars($achievement_level) . "</td>";
    }

    echo "</tr>";
    echo "</table>";

    echo "<!-- Comments Table -->
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
        
        <!-- Learning Outcomes Scale Table -->
        <table class='grades-table'>
            <tr>
                <th style='width: 15%;'>IDENTIFIER</th>
                <th style='width: 20%;'>RANGE</th>
                <th style='width: 65%;'>DESCRIPTOR</th>
            </tr>
            <tr>
                <td style='font-weight: bold;'>1</td>
                <td>0.9 – 1.49</td>
                <td><strong>Basic:</strong> Fewer learning outcomes achieved, but not sufficient for overall achievement</td>
            </tr>
            <tr>
                <td style='font-weight: bold;'>2</td>
                <td>1.5 – 2.49</td>
                <td><strong>Moderate:</strong> Many learning outcomes achieved. Enough for overall achievement</td>
            </tr>
            <tr>
                <td style='font-weight: bold;'>3</td>
                <td>2.5 – 3.0</td>
                <td><strong>Outstanding:</strong> Most of all learning outcomes achieved for overall achievement</td>
            </tr>
        </table>
        
        <!-- Definition Table -->
        <table class='grades-table' style='margin-top: 10px;'>
            <tr>
                <th colspan='2' style='text-align: center; background-color: #e0e0e0;'>LO – Learning Outcomes | AOI – Activity of Integration</th>
            </tr>
            <tr>
                <td style='width: 20%; font-weight: bold; vertical-align: top;'>Competency</td>
                <td>The overall expected capability of a learner at the end of a topic, term or year after being exposed to a body of knowledge, skills and value.</td>
            </tr>
            <tr>
                <td style='width: 20%; font-weight: bold; vertical-align: top;'>Descriptor</td>
                <td>Gives details on the extent to which the learner has achieved the stipulated learning outcomes in a given topic.</td>
            </tr>
            <tr>
                <td style='width: 20%; font-weight: bold; vertical-align: top;'>Generic Skills</td>
                <td>These are higher order transferable soft skills that apply to all subjects and are commonly sought after in the 21<sup>st</sup> century and the world of work.</td>
            </tr>
            <tr>
                <td style='width: 20%; font-weight: bold; vertical-align: top;'>Identifier</td>
                <td>Is a label / grade that distinguishes learners according to their learning achievement of the set competencies.</td>
            </tr>
            <tr>
                <td style='width: 20%; font-weight: bold; vertical-align: top;'>Score</td>
                <td>Refers to the average of the score attained for the different learning outcomes that makeup competency.</td>
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
