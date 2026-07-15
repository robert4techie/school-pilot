<?php
// Start with script execution time tracking
$start_time = microtime(true);

// Enable output buffering for faster page loading
ob_start();

// CHECK FOR VERIFIED QR CODE ACCESS
$is_verified_access = false;
if (isset($_GET['verified']) && isset($_GET['verification_token'])) {
    $expected_token = md5($_GET['student_id'] . $_GET['term'] . $_GET['year'] . 'CNK_SECRET_KEY');
    if ($_GET['verification_token'] === $expected_token) {
        $is_verified_access = true;
    }
}

// Only require auth if NOT verified
if (!$is_verified_access) {
    require_once 'auth.php';
}
require_once '../conn.php';

// Set higher memory limit if needed
ini_set('memory_limit', '1024M');

// CORRECTED CODE BLOCK
function getSchoolDetails($conn)
{
    $sql = "SELECT school_name, address, phone, email, pobox, logo_path FROM school_profile LIMIT 1";
    $result = mysqli_query($conn, $sql);
    if ($result && $row = mysqli_fetch_assoc($result)) {
        return $row;
    }
    return null;
}
$school_details = getSchoolDetails($conn);

// Set a default logo path first
$logoSrc = '../assets/img/logo.jpg';

// Check if school details and a specific logo path exist
if ($school_details && !empty($school_details['logo_path'])) {
    // Construct the correct ABSOLUTE path from the web root
    // This removes any leading slashes from the DB path to prevent '//'
    $logoSrc = '/' . ltrim(htmlspecialchars($school_details['logo_path']), '/');
}

// Initialize variables with defaults
$class = $_GET['class'] ?? 'Senior One';
$term = $_GET['term'] ?? 'Term 1';
$year = $_GET['year'] ?? date('Y');
$report_type = $_GET['report_type'] ?? 'summarized';

// Get the number of AOI columns to generate
$aoi_columns = isset($_GET['aoi_columns']) ? (int)$_GET['aoi_columns'] : 2;
$aoi_columns = max(1, min(10, $aoi_columns)); // Limit between 1 and 10 columns

// Get display_student_position parameter
$display_student_position = isset($_GET['display_student_position']) && $_GET['display_student_position'] == '1';

// Handle both single student and multiple students from form
$student_ids = [];

if (isset($_GET['students']) && is_array($_GET['students'])) {
    $student_ids = array_filter($_GET['students']);
} elseif (isset($_GET['students']) && !empty($_GET['students'])) {
    $student_ids = [$_GET['students']];
} elseif (isset($_GET['student_id']) && !empty($_GET['student_id'])) {
    $student_ids = [$_GET['student_id']];
}

// Handle streams
if (isset($_GET['streams']) && is_array($_GET['streams'])) {
    $stream = $_GET['streams'];
} elseif (isset($_GET['stream']) && !is_array($_GET['stream'])) {
    $stream = [$_GET['stream']];
} elseif (isset($_GET['stream']) && is_array($_GET['stream'])) {
    $stream = $_GET['stream'];
} else {
    $stream = ['East', 'West', 'North', 'South'];
}

// Validate required parameters
if (empty($class) || empty($term) || empty($student_ids)) {
    die('Missing required parameters: class, term, and at least one student must be selected');
}

// Convert term to roman numeral for table name
$term_number = filter_var($term, FILTER_SANITIZE_NUMBER_INT);
$romans = ['i', 'ii', 'iii'];
$term_roman = $romans[$term_number - 1] ?? 'i';
$table_name = "{$year}_{$term_roman}_olevel";

// Check if table exists
$table_exists = false;
$check_table_sql = "SHOW TABLES LIKE '$table_name'";
$check_result = mysqli_query($conn, $check_table_sql);
if ($check_result && mysqli_num_rows($check_result) > 0) {
    $table_exists = true;
}

if (!$table_exists) {
    die("<div class='alert alert-danger'>The marks table for {$term} {$year} does not exist.</div>");
}


function getNextTermDetails($conn, $class, $term, $year)
{
    $details = [
        'next_term_date' => 'Not available',
        'next_term_ends' => 'Not available',
        'next_term_fees' => 'Not available',
    ];

    // Get dates from school_profile table
    $sql_dates = "SELECT next_term_date, next_term_ends FROM school_profile LIMIT 1";
    $result_dates = mysqli_query($conn, $sql_dates);
    if ($result_dates && $row = mysqli_fetch_assoc($result_dates)) {
        $details['next_term_date'] = $row['next_term_date'] ?? 'Not available';
        $details['next_term_ends'] = $row['next_term_ends'] ?? 'Not available';
    }

    // Determine next term's class and term
    $terms = ['Term 1', 'Term 2', 'Term 3'];
    $classes = ['Senior One', 'Senior Two', 'Senior Three', 'Senior Four', 'Senior Five', 'Senior Six'];

    $current_term_index = array_search($term, $terms);
    $current_class_index = array_search($class, $classes);

    $next_class = $class;
    $next_term = $term;
    $next_year = $year;

    if ($current_term_index !== false) {
        if ($current_term_index < 2) {
            // If current term is Term 1 or 2, the next term is the next one in the same class
            $next_term = $terms[$current_term_index + 1];
        } else {
            // If current term is Term 3, the next term is Term 1 of the next class (or current class if last)
            $next_term = 'Term 1';
            if ($current_class_index !== false && $current_class_index < count($classes) - 1) {
                $next_class = $classes[$current_class_index + 1];
            }
            $next_year = (string)((int)$year + 1);
        }
    }

    // New: Map the numeric term to the word term used in the database
    $term_map = [
        'Term 1' => 'Term One',
        'Term 2' => 'Term Two',
        'Term 3' => 'Term Three'
    ];
    $db_term_name = $term_map[$next_term] ?? $next_term;

    // Fetch fees from fee_structures table
    $sql_fees = "SELECT amount FROM fee_structures WHERE class_name = ? AND term = ? AND year = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql_fees);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "sss", $next_class, $db_term_name, $next_year);
        mysqli_stmt_execute($stmt);
        $result_fees = mysqli_stmt_get_result($stmt);
        if ($result_fees && $row_fees = mysqli_fetch_assoc($result_fees)) {
            $details['next_term_fees'] = 'UGX ' . number_format($row_fees['amount']);
        }
        mysqli_stmt_close($stmt);
    }

    return $details;
}

// Function to generate professional comments based on grade
function getTeacherComment($student_name, $grade, $type = 'class_teacher')
{
    // Extract only the first name
    $first_name = explode(' ', $student_name)[0];

    $class_teacher_comments = [
        'A' => [
            "$first_name has demonstrated exceptional excellence. Keep up the outstanding work!",
            "$first_name consistently exhibits exemplary performance. Well done!",
            "$first_name shows remarkable dedication and achievement. Excellent work!",
            "$first_name has excelled remarkably this term. Truly impressive!",
            "$first_name demonstrates exceptional competency. Outstanding performance!"
        ],
        'B' => [
            "$first_name has shown commendable progress this term. Well done!",
            "$first_name displays good understanding and consistent effort.",
            "$first_name has performed admirably. Keep up the good work!",
            "$first_name demonstrates solid competency and dedication.",
            "$first_name has achieved notable success through hard work."
        ],
        'C' => [
            "$first_name has made satisfactory progress this term.",
            "$first_name demonstrates adequate understanding. Keep working hard!",
            "$first_name has performed reasonably well this term.",
            "$first_name displays acceptable competency. More focus needed.",
            "$first_name has achieved satisfactory results. Keep improving."
        ],
        'D' => [
            "$first_name needs to put in more effort to improve.",
            "$first_name requires more dedication and consistent study.",
            "$first_name needs to focus more on studies next term.",
            "$first_name should seek additional help to improve.",
            "$first_name must work harder to achieve better results."
        ],
        'E' => [
            "$first_name needs significant improvement.",
            "$first_name requires intensive remedials.",
            "$first_name must work harder and seek extra help.",
            "$first_name needs urgent intervention to improve.",
            "$first_name should attend extra lessons regularly."
        ],
        '-' => [
            "$first_name's performance could not be fully assessed.",
            "$first_name requires complete assessment data.",
            "$first_name needs to complete all assessments."
        ]
    ];

    $head_teacher_comments = [
        'A' => [
            "$first_name is an exemplary student. Excellent work!",
            "$first_name demonstrates outstanding academic prowess.",
            "$first_name's exceptional performance is commendable.",
            "$first_name shows remarkable intellectual capacity.",
            "$first_name is a role model student. Keep excelling!"
        ],
        'B' => [
            "$first_name is a diligent student. Keep it up!",
            "$first_name shows strong academic ability.",
            "$first_name demonstrates admirable dedication.",
            "$first_name is a reliable student. Well done!",
            "$first_name shows great promise. Keep striving!"
        ],
        'C' => [
            "$first_name has shown acceptable progress.",
            "$first_name demonstrates adequate performance.",
            "$first_name has performed satisfactorily.",
            "$first_name shows potential for improvement.",
            "$first_name needs more dedication for advancement."
        ],
        'D' => [
            "$first_name must improve significantly next term.",
            "$first_name needs greater commitment to studies.",
            "$first_name should work closely with teachers.",
            "$first_name requires immediate attention to studies.",
            "$first_name must show marked improvement."
        ],
        'E' => [
            "$first_name requires immediate intervention.",
            "$first_name's performance requires urgent attention.",
            "$first_name must make drastic improvements.",
            "$first_name needs intensive supervision.",
            "$first_name requires serious commitment to studies."
        ],
        '-' => [
            "$first_name requires complete assessment.",
            "$first_name needs comprehensive evaluation.",
            "$first_name should complete all assessments."
        ]
    ];

    $comments = $type === 'head_teacher' ? $head_teacher_comments : $class_teacher_comments;

    // Get the array of comments for this grade
    $grade_comments = $comments[$grade] ?? $comments['C'];

    // Return a random comment from the array for variety
    return $grade_comments[array_rand($grade_comments)];
}

// Function to get student details
function getStudentDetails($conn, $student_id)
{
    $sql = "SELECT * FROM students WHERE student_id = ?"; // Corrected from 'id' to 'student_id'
    $stmt = mysqli_prepare($conn, $sql);

    // Add error checking for prepare statement
    if (!$stmt) {
        error_log("Failed to prepare statement in getStudentDetails: " . mysqli_error($conn));
        return null;
    }

    mysqli_stmt_bind_param($stmt, "s", $student_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result && $row = mysqli_fetch_assoc($result)) {
        mysqli_stmt_close($stmt);
        return $row;
    }

    mysqli_stmt_close($stmt);
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

    // Add error checking for prepare statement
    if (!$stmt) {
        error_log("Failed to prepare statement in getSelectedTopics: " . mysqli_error($conn));
        return [];
    }

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

    mysqli_stmt_close($stmt);
    return $topics;
}

// Function to get subject info
function getSubjectInfo($conn, $subject_names_cache, $compulsory_cache)
{
    if (!empty($subject_names_cache)) {
        return [$subject_names_cache, $compulsory_cache];
    }

    $subject_names = [];
    $compulsory_subjects = [];

    $sql = "SELECT subj_name, subj_abbr, compulsory FROM subjects";
    $result = mysqli_query($conn, $sql);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $subject_names[$row['subj_name']] = $row['subj_name'];
            $subject_names[$row['subj_abbr']] = $row['subj_name'];

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

    // CORRECTED SQL: Join on staff_id (alphanumeric) instead of id (numeric)
    $sql = "
        SELECT 
            UPPER(CONCAT(SUBSTRING(s.first_name, 1, 1), SUBSTRING(s.last_name, 1, 1))) AS initials
        FROM teaching_assignments ta
        JOIN staff s ON ta.staff_id = s.staff_id
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
    } else {
        // Add error logging to help debug
        error_log("Failed to prepare getTeacherInitials query: " . mysqli_error($conn));
        error_log("Parameters: subject=$subject, class=$class, stream=$stream");
    }

    // Cache the result and return it
    $cache[$cache_key] = $initials;
    return $initials;
}

// Function to convert marks to grade
function convertToGrade($marks)
{
    if ($marks === null || $marks === '') {
        return '-';
    }

    $marks = floatval($marks);

    if ($marks >= 85) return 'A';
    if ($marks >= 70) return 'B';
    if ($marks >= 50) return 'C';
    if ($marks >= 40) return 'D';
    return 'E';
}

// Function to check if subject has marks
function subjectHasMarks($marks_array, $eot_mark)
{
    // Note: $marks_array here might contain nulls from reordering for summarized report
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

// Get selected topics and subject info
$selected_topics = getSelectedTopics($conn, $class, $term, $year);

// FALLBACK: If no topics are selected, get them directly from the marks table
if (empty($selected_topics) && !empty($student_ids) && $table_exists) {
    $fallback_sql = "SELECT DISTINCT subject, topic_id FROM `$table_name` 
                     WHERE class = ? 
                     ORDER BY subject, topic_id";
    $stmt = mysqli_prepare($conn, $fallback_sql);

    if (!$stmt) {
        error_log("Failed to prepare fallback topics query: " . mysqli_error($conn));
        // Continue with empty selected_topics, will result in empty report
    } else {
        mysqli_stmt_bind_param($stmt, "s", $class);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $subject = $row['subject'];
                $topic_id = $row['topic_id'];

                if (!isset($selected_topics[$subject])) {
                    $selected_topics[$subject] = [];
                }

                $selected_topics[$subject][] = $topic_id;
            }
        }
        mysqli_stmt_close($stmt);
    }
}

[$subject_names_cache, $compulsory_cache] = getSubjectInfo($conn, [], []);
$teacher_initials_cache = [];

$total_students = count($student_ids);
$stream_display = is_array($stream) ? implode(', ', $stream) : $stream;

// Get all students marks in a single query
$all_students_marks = [];
if (!empty($student_ids) && $table_exists) {
    $id_list_placeholders = implode(',', array_fill(0, count($student_ids), '?')); // Changed to use placeholders
    $marks_sql = "SELECT student_id, subject, topic_id, marks, stream FROM `$table_name` 
                 WHERE student_id IN ($id_list_placeholders) AND class = ?";

    $marks_stmt = mysqli_prepare($conn, $marks_sql);

    if (!$marks_stmt) {
        error_log("Failed to prepare all_students_marks query: " . mysqli_error($conn));
    } else {
        $types = str_repeat('s', count($student_ids)) . 's';
        $bind_params = array_merge($student_ids, [$class]); // Merge student_ids and class for binding

        // Need to pass parameters by reference for call_user_func_array
        $refs = [];
        foreach ($bind_params as $key => $value) {
            $refs[$key] = &$bind_params[$key];
        }

        call_user_func_array([$marks_stmt, 'bind_param'], array_merge([$types], $refs));

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
        mysqli_stmt_close($marks_stmt);
    }
}

// ADDITIONAL FALLBACK: If still empty, create a basic structure from actual marks data
if (empty($selected_topics) && !empty($all_students_marks)) {
    foreach ($all_students_marks as $student_data) {
        if (isset($student_data['subjects'])) {
            foreach ($student_data['subjects'] as $subject => $topic_marks) {
                if (!isset($selected_topics[$subject])) {
                    $selected_topics[$subject] = [];
                }
                foreach ($topic_marks as $topic_id => $mark) {
                    if (!in_array($topic_id, $selected_topics[$subject])) {
                        $selected_topics[$subject][] = $topic_id;
                    }
                }
            }
        }
    }

    // Sort the topics for each subject (EOT should come last)
    foreach ($selected_topics as $subject => $topics) {
        usort($selected_topics[$subject], function ($a, $b) {
            if ($a === 'EOT') return 1;
            if ($b === 'EOT') return -1;
            return strcmp($a, $b);
        });
    }
}

$student_grades = [];

foreach ($student_ids as $sid) {
    if (!isset($all_students_marks[$sid])) {
        $student_grades[$sid] = ['grade' => 'E', 'average' => 0];
        continue;
    }

    $displayed_subjects = [];
    foreach ($selected_topics as $subject => $topics) {
        $subject_name = $subject_names_cache[$subject] ?? $subject;
        $is_compulsory = $compulsory_cache[$subject] ?? false;

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

        $has_marks = subjectHasMarks($aoi_marks, $eot_mark);

        if ($is_compulsory || $has_marks) {
            $displayed_subjects[] = $subject;
        }
    }

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

        // Calculate total for this subject using the FAIR AOI calculation
        $valid_aoi = array_filter($aoi_marks, function ($mark) {
            return $mark !== null && $mark !== '' && $mark !== '-';
        });

        $aoi_sum = !empty($valid_aoi) ? array_sum($valid_aoi) : null;
        // CRITICAL: Divide by total AOI columns, not just valid marks
        $aoi_avg = $aoi_sum !== null ? $aoi_sum / $aoi_columns : null;

        $total = null;
        $has_aoi = $aoi_avg !== null;
        $has_eot = $eot_mark !== null && $eot_mark !== '' && $eot_mark !== '-';
        $aoi_only = $has_aoi && !$has_eot;

        if ($has_aoi) {
            if ($aoi_only) {
                $total = ($aoi_avg * 20) / 3;
            } else if ($has_eot) {
                $aoi_20_percent = ($aoi_avg / 3) * 20;
                $eot_80_percent = ($eot_mark / 100) * 80;
                $total = $aoi_20_percent + $eot_80_percent;
            } else {
                $total = ($aoi_avg / 3) * 20;
            }
        } else if ($has_eot) {
            $total = $eot_mark;
        }

        $total = $total ?? 0;
        $student_total += $total;
    }

    $average = $total_displayed_subjects > 0 ? $student_total / $total_displayed_subjects : 0;
    $final_grade = convertToGrade($average);

    $student_grades[$sid] = [
        'grade' => $final_grade,
        'average' => $average
    ];
}

// Sort students by grade (A, B, C, D, E) then by average within each grade
usort($student_ids, function ($a, $b) use ($student_grades) {
    $grade_a = $student_grades[$a]['grade'] ?? 'E';
    $grade_b = $student_grades[$b]['grade'] ?? 'E';
    $avg_a = $student_grades[$a]['average'] ?? 0;
    $avg_b = $student_grades[$b]['average'] ?? 0;

    // Define grade order
    $grade_order = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5, '-' => 6];

    $order_a = $grade_order[$grade_a] ?? 6;
    $order_b = $grade_order[$grade_b] ?? 6;

    // First sort by grade
    if ($order_a !== $order_b) {
        return $order_a - $order_b;
    }

    // If same grade, sort by average (highest first)
    return $avg_b <=> $avg_a;
});


// Start HTML output
echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Summarized Reports - {$class} Streams {$stream_display} - {$term} {$year}</title>
    
    <link rel='preconnect' href='https://fonts.googleapis.com'>
    <link rel='preconnect' href='https://fonts.gstatic.com' crossorigin='anonymous'>
    <link href='https://fonts.googleapis.com/css2?family=Sen:wght@400..800&display=swap' rel='stylesheet'>

    <script src='https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs/qrcode.min.js'></script>
    <link rel='stylesheet' href='../assets/bootstrap/css/bootstrap.min.css'>
    <link rel='stylesheet' href='../assets/fonts/fontawesome-all.min.css'>
    
    <style>
   * {
            font-family: 'Sen', sans-serif !important;
        }

    body {
        /* CHANGED: Increased base font size and line-height for better readability and spacing. */
        font-size: 13px;
        line-height: 1.5;
        margin: 0;
        padding: 20px;
        background-color: #f8f9fa;
    }

    .report-card {
        max-width: 1200px;
        /* CHANGED: Increased bottom margin to add more space between reports. */
        margin: 0 auto 40px;
        padding: 25px; /* CHANGED: Increased padding for more internal whitespace. */
        background-color: white;
        border-radius: 8px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        page-break-after: always;
        position: relative;
        overflow: hidden;
    }

    .report-card:last-child {
        page-break-after: avoid;
        margin-bottom: 20px;
    }

    .header {
        text-align: center;
        /* CHANGED: Increased bottom margin to create more space below the main header. */
        margin-bottom: 25px;
        position: relative;
        z-index: 1;
    }

    .school-logo {
        max-width: 100px;
        height: auto;
    }

    .school-info {
        margin-bottom: 15px;
        position: relative;
        z-index: 1;
    }

    .school-name {
        font-size: 22px; /* CHANGED: Increased school name font size. */
        font-weight: bold;
    }

    .student-info {
        /* CHANGED: Increased bottom margin for more space after the student details. */
        margin-bottom: 25px;
        border-top: 1px solid #000;
        border-bottom: 1px solid #000;
        padding: 10px 0; /* CHANGED: Increased padding. */
        position: relative;
        z-index: 1;
    }

    .student-photo {
        max-width: 100px; /* CHANGED: Slightly larger photo. */
        height: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 25px; /* CHANGED: Increased default bottom margin for all tables. */
        position: relative;
        z-index: 1;
        background-color: transparent !important;
    }

    th,
    td {
        border: 1px solid #333;
        /* CHANGED: Increased padding to make rows taller and spread out the content. */
        padding: 20px;
        text-align: center;
        vertical-align: middle;
        background-color: transparent !important;
        /* CHANGED: Increased table cell font size. */
        font-size: 12px;
    }

    th {
        background-color: transparent !important;
        font-weight: bold;
        /* CHANGED: Increased header font size and padding. */
        font-size: 11px;
        padding: 8px 7px;
    }

    .subject-cell {
        text-align: left;
        font-weight: bold;
        font-size: 11px;
        max-width: 100px;
        word-wrap: break-word;
    }

    .print-btn-container {
        position: fixed;
        top: 10px;
        right: 10px;
        z-index: 1000;
    }

    .summary-table {
        margin-top: 25px; /* CHANGED: Increased margin. */
        margin-bottom: 25px; /* CHANGED: Increased margin. */
        border: 1px solid #333;
    }

    .summary-table td {
        font-weight: bold;
        padding: 8px 12px; /* CHANGED: Increased padding. */
        font-size: 13px; /* CHANGED: Increased font size. */
    }

    .processing-status {
        position: fixed;
        top: 60px;
        left: 10px;
        background: #f8f9fa;
        padding: 10px;
        border-radius: 5px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        z-index: 1000;
    }

    .results-table-container {
        position: relative;
        margin-bottom: 25px; /* CHANGED: Increased margin. */
    }

    .table-watermark {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        opacity: 0.2; /* CHANGED: Made watermark slightly less prominent. */
        width: 60%;
        height: auto;
        pointer-events: none;
        z-index: 0;
    }

    .aoi-column {
        font-size: 11px; /* CHANGED: Increased font size. */
        min-width: 40px;
    }

    .eot-column {
        font-size: 11px; /* CHANGED: Increased font size. */
    }

    .total-column {
        font-weight: bold;
        font-size: 11px; /* CHANGED: Increased font size. */
    }

    .grade-column {
        font-weight: bold;
        font-size: 12px; /* CHANGED: Increased font size. */
    }

 .comments-section {
    margin-bottom: 25px;
    padding: 15px 0;
}

.comment-row {
    display: flex;
    align-items: center;
    margin-bottom: 15px;
    gap: 15px;
}

.comment-label {
    font-weight: bold;
    white-space: nowrap;
    min-width: 180px;
    flex-shrink: 0;
}

.comment-text {
    flex-grow: 1;
    line-height: 1.5;
}

.signature-area {
    display: flex;
    align-items: center;
    gap: 10px;
    white-space: nowrap;
    flex-shrink: 0;
}

.signature-label {
    font-weight: bold;
}

.signature-line {
    width: 80px;
    border-bottom: 1px solid #333;
    display: inline-block;
    height: 1px;
}

/* Print styles for comments */
@media print {
    .comments-section {
        padding: 10px 0;
    }
    
    .comment-row {
        margin-bottom: 4px;
    }
}

    .grades-table {
        background-color: transparent;
        margin-bottom: 15px; /* Add this line */
    }

    .grades-table th {
        text-align: center;
        font-weight: bold;
        font-size: 11px; /* CHANGED: Increased font size. */
        background-color: transparent !important;
        padding: 10px 5px;
    }

    .grades-table td {
        font-size: 11px; /* CHANGED: Increased font size. */
        background-color: transparent !important;
        text-align: center; /* Add this line */
        padding: 8px 5px; 
    }

    .calculated {
        background-color: transparent !important;
    }

    .aoi-back-page {
        page-break-inside: avoid;
        max-height: 100vh;
        overflow: hidden;
    }

    .aoi-table {
        font-size: 12px; /* CHANGED: Increased font size. */
    }

    .aoi-table td,
    .aoi-table th {
        font-size: 12px; /* CHANGED: Increased font size. */
        line-height: 1.3; /* CHANGED: Increased line height. */
    }
    
    .aoi-table.large-content,
    .aoi-table.large-content td,
    .aoi-table.large-content th {
        font-size: 11px;
        padding: 5px;
        line-height: 1.2;
    }

    .aoi-table.extra-large-content,
    .aoi-table.extra-large-content td,
    .aoi-table.extra-large-content th {
        font-size: 10px;
        padding: 4px;
        line-height: 1.1;
    }

    /* --- CRITICAL: MODIFIED PRINT STYLES --- */
    @media print {
        body {
            background-color: white;
            padding: 0;
            font-size: 11pt; /* ADDED: Set a base print font size. */
        }

        .print-btn-container,
        .processing-status {
            display: none;
        }

        .report-card {
            box-shadow: none;
            border-radius: 0;
            margin: 0;
            padding: 20mm 15mm 20mm 15mm; /* ADDED: Use mm for print margins (Top, Right, Bottom, Left) to better control A4 layout. */
            page-break-after: always;
        }

        .report-card:last-child {
            page-break-after: avoid;
        }

        .table-watermark {
            opacity: 0.15 !important; /* CHANGED: Further reduced watermark opacity for printing. */
        }

        /* CHANGED: Updated print styles for tables to be larger and more spaced out. */
        th,
        td {
            font-size: 10pt; /* Use points (pt) for print font sizes. */
            padding: 4px 5px; /* Adjust padding for print. */
            background-color: transparent !important;
        }

        .subject-cell, .grades-table th, .grades-table td {
             font-size: 7pt;
        }

        .calculated {
            background-color: transparent !important;
        }

        .aoi-back-page {
            page-break-inside: avoid;
        }
    }
    
</style>

</head>
<body>
    <div class='print-btn-container'>
        <button class='btn btn-primary btn-sm' onclick='window.print();'>
            <i class='fas fa-print'></i> Print All Reports
        </button>
    </div>
    
    <div class='processing-status'>
        <div>Processing <span id='current-count'>0</span> of <span id='total-count'>{$total_students}</span> students</div>
        <div class='progress mt-2'>
            <div id='progress-bar' class='progress-bar progress-bar-striped progress-bar-animated' role='progressbar' style='width: 0%'></div>
        </div>
    </div>";

// Calculate rankings for all students (only if position display is enabled)
$all_student_averages = [];
$position_map = [];

if ($display_student_position) {
    foreach ($student_ids as $sid) {
        if (!isset($all_students_marks[$sid])) continue;

        $displayed_subjects = [];
        foreach ($selected_topics as $subject => $topics) {
            $subject_name = $subject_names_cache[$subject] ?? $subject;
            $is_compulsory = $compulsory_cache[$subject] ?? false;

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

            $has_marks = subjectHasMarks($aoi_marks, $eot_mark);

            if ($is_compulsory || $has_marks) {
                $displayed_subjects[] = $subject;
            }
        }

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

            $total = null;
            $has_aoi = !empty(array_filter($aoi_marks, function ($mark) {
                return $mark !== null && $mark !== '' && $mark !== '-';
            }));
            $aoi_only = $has_aoi && ($eot_mark === null || $eot_mark === '' || $eot_mark === '-');

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
                    if ($aoi_avg !== null) {
                        $total = ($aoi_avg * 20) / 3;
                    }
                } else {
                    $aoi_20_percent = $aoi_avg !== null ? ($aoi_avg / 3 * 20) : null;
                    $eot_80_percent = $eot_mark !== null ? ($eot_mark / 100 * 80) : null;

                    if ($aoi_20_percent !== null && $eot_80_percent !== null) {
                        $total = $aoi_20_percent + $eot_80_percent;
                    }
                }
            } else {
                $total = $eot_mark;
            }

            $total = $total ?? 0;
            $student_total += $total;
        }

        $average = $total_displayed_subjects > 0 ? $student_total / $total_displayed_subjects : 0;
        $all_student_averages[$sid] = $average;
    }

    arsort($all_student_averages);

    $pos = 1;
    foreach ($all_student_averages as $sid => $avg) {
        $position_map[$sid] = $pos++;
    }
}

// Process each student for summarized report
$current_student = 0;
foreach ($student_ids as $student_id_from_loop) { // Renamed loop variable to avoid confusion
    $current_student++;

    // Get student details
    $student = getStudentDetails($conn, $student_id_from_loop); // Use the correct student ID
    if (!$student) continue;

    if (!isset($all_students_marks[$student_id_from_loop])) continue;

    // All marks for current student
    $all_marks = $all_students_marks[$student_id_from_loop]['subjects'] ?? [];

    // Get the stream for the current student
    $student_stream = $all_students_marks[$student_id_from_loop]['stream'] ?? '';

    // Calculate position text (only if position display is enabled)
    $position_text = null;
    if ($display_student_position) {
        $position = $position_map[$student_id_from_loop] ?? $current_student;
        $position_text = $position . " out of " . $total_students;
    }

    // Call generation function, passing the numerical student_id and the display string
    generateSummarizedReport($student, $all_marks, $selected_topics, $subject_names_cache, $compulsory_cache, $teacher_initials_cache, $conn, $position_text, $term, $year, $class, $student_stream, $aoi_columns, $display_student_position, $school_details, $logoSrc);
    echo "<script>
        document.getElementById('current-count').textContent = {$current_student};
        document.getElementById('progress-bar').style.width = " . ($current_student / $total_students * 100) . "%;
    </script>";

    flush();
    ob_flush();
}

// Function to generate summarized report
function generateSummarizedReport($student, $all_marks, $selected_topics, $subject_names_cache, $compulsory_cache, &$teacher_initials_cache, $conn, $position_text, $term, $year, $class, $student_stream, $aoi_columns, $display_student_position, $school_details, $logoSrc)
{
    $next_term_details = getNextTermDetails($conn, $class, $term, $year);

    $subject_counter = 1;

    $student_name = $student ? htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) : '';

    // Correctly get the internal numerical student_id (from the database query)
    $internal_student_id = $student ? htmlspecialchars($student['student_id']) : '';

    // Correctly get the display student_id (the alphanumeric one)
    $display_student_id_string = $student ? htmlspecialchars($student['student_id']) : '';

    // Just escape the stream for display - don't overwrite it
    $display_stream = htmlspecialchars($student_stream);

    // Keep clean version for database queries
    $clean_stream = $student_stream;

    echo "
    <div class='report-card'> 
       <div class='row header'>
    <div class='col-2'>
    <img src='" . $logoSrc . "' alt='School Logo' class='school-logo' loading='lazy'>
</div>
    <div class='col-8 school-info'>
        <div class='school-name'>" . htmlspecialchars($school_details['school_name'] ?? 'School Name Not Set') . "</div>";

    if (isset($school_details['pobox'])) {
        echo "<div>" . htmlspecialchars($school_details['pobox']) . " " . htmlspecialchars($school_details['address'] ?? '') . "</div>";
    } else {
        echo "<div>" . htmlspecialchars($school_details['address'] ?? 'Address Not Set') . "</div>";
    }

    echo "<div>TEL: " . htmlspecialchars($school_details['phone'] ?? 'Phone Not Set') . ", EMAIL: " . htmlspecialchars($school_details['email'] ?? 'Email Not Set') . "</div>";

    echo "<div class='mt-2'>
            <strong>SUMMARIZED REPORT CARD FOR TERM " . htmlspecialchars($term) . ", YEAR: " . htmlspecialchars($year) . "</strong>
        </div>
    </div>
            <div class='col-2'>";

    $photo_path = $student['profile_photo'] ?? null;

    // Check if the photo path exists AND is not empty
    if ($photo_path) {
        // Since the database contains the FULL URL (https://...), we can use it directly.
        // If it contained a relative path (e.g., /uploads/...), you would need to prepend the domain.
        echo "<img src='" . htmlspecialchars($photo_path) . "' alt='Student Photo' class='student-photo' loading='lazy'>";
    } else {
        // Fallback to the default local path for the avatar.
        echo "<img src='../images/avartor.png' alt='Student Photo' class='student-photo' loading='lazy'>";
    }

    echo "</div>
        </div>
        
        <div class='student-info row'>
            <div class='col-4'>
                <strong>Name:</strong> {$student_name}
            </div>
            <div class='col-4'>
                <strong>ID:</strong> {$display_student_id_string}
            </div>
            <div class='col-4'>
                <strong>Class:</strong> " . htmlspecialchars($class) . " (" . $display_stream . ")
            </div>
            
        </div>

        <div class='results-table-container'>
            
            
            <table class='results-table' id='results-table-{$internal_student_id}'>
                <thead>
                    <tr>
                        <th rowspan='2' class='subject-cell'>SUBJECT</th>";

    // Generate dynamic AOI column headers
    for ($i = 1; $i <= $aoi_columns; $i++) {
        echo "<th class='aoi-column'>AOI {$i}</th>";
    }

    echo "
                        <th rowspan='2' class='aoi-column'>AVG</th>
                        <th rowspan='2' class='aoi-column calculated'>Out of 20</th>
                        <th rowspan='2' class='eot-column calculated'>EOT / 80</th>
                        <th rowspan='2' class='total-column calculated'>Total (100%)</th>
                        <th rowspan='2' class='grade-column calculated'>GRADE</th>
                        <th rowspan='2'>INITIALS</th>
                    </tr>
                </thead>
                <tbody>";

    $student_total = 0;
    $displayed_subject_count = 0;
    $row_index = 0;

    foreach ($selected_topics as $subject => $topics) {
        $subject_name = $subject_names_cache[$subject] ?? $subject;
        $is_compulsory = $compulsory_cache[$subject] ?? false;

        // Separate AOI marks and EOT mark
        $aoi_marks = [];
        $eot_mark = null;

        if (isset($all_marks[$subject])) {
            foreach ($topics as $topic_id) {
                $mark = $all_marks[$subject][$topic_id] ?? null;

                if ($topic_id === 'EOT') {
                    $eot_mark = $mark;
                } else {
                    // Store AOI marks in order they appear in the database
                    $aoi_marks[] = $mark;
                }
            }
        }

        // REORDER AOI marks to remove gaps - put valid marks first
        $reordered_aoi_marks = [];

        // First, collect all non-empty marks
        foreach ($aoi_marks as $mark) {
            if ($mark !== null && $mark !== '' && $mark !== '-') {
                $reordered_aoi_marks[] = $mark;
            }
        }

        // Fill remaining positions with null up to the requested number of columns
        while (count($reordered_aoi_marks) < $aoi_columns) {
            $reordered_aoi_marks[] = null;
        }

        // Use the reordered marks for all calculations
        $aoi_marks = $reordered_aoi_marks;

        $has_marks = subjectHasMarks($aoi_marks, $eot_mark);

        if (!$is_compulsory && !$has_marks) {
            continue;
        }

        // Use the clean stream version (not HTML-escaped) for the query
        error_log("DEBUG: Calling getTeacherInitials with subject='$subject_name', class='$class', stream='$clean_stream'");
        $initials = getTeacherInitials($conn, $subject_name, $class, $clean_stream, $teacher_initials_cache);
        error_log("DEBUG: Returned initials='$initials'");
        echo "<tr data-row='{$row_index}' data-student='{$internal_student_id}'>"; // Use internal_student_id here
        echo "<td class='subject-cell'>" . htmlspecialchars($subject_name) . "</td>";

        // Display AOI marks in dynamic columns
        for ($i = 0; $i < $aoi_columns; $i++) {
            $mark = isset($aoi_marks[$i]) ? $aoi_marks[$i] : null;
            echo "<td class='aoi-column' data-aoi='{$i}'>";
            if ($mark !== null && $mark !== '' && $mark !== '-') {
                echo number_format((float)$mark, 1);
            } else {
                echo "-";
            }
            echo "</td>";
        }

        // AOI Average column (calculated by JS)
        echo "<td class='aoi-column calculated' data-avg='true'>-</td>";

        // Out of 20 column (calculated by JS)
        echo "<td class='aoi-column calculated' data-out-of-20='true'>-</td>";

        // EOT / 80 column
        echo "<td class='eot-column calculated' data-eot='" . ($eot_mark !== null ? $eot_mark : '') . "'>";
        echo "-";
        echo "</td>";

        // Total column (calculated by JS)
        echo "<td class='total-column calculated' data-total='true'>-</td>";

        // Grade column (calculated by JS)
        echo "<td class='grade-column calculated' data-grade='true'>-</td>";

        // Initials column
        // Initials column
        echo "<td>" . htmlspecialchars($initials) . "</td>";

        echo "</tr>";

        $displayed_subject_count++;
        $row_index++;
    }

    echo "</tbody>
            </table>
        </div>";

    // Conditionally build summary table based on position display setting
    echo "<table class='summary-table'>";
    echo "<tr>";

    if ($display_student_position && $position_text !== null) {
        // If position is enabled, show all four columns (25% width each)
        echo "<td style='width: 25%;'>Average Score: <span id='overall-avg-{$internal_student_id}'>0.0</span></td>"; // Use internal_student_id
        echo "<td style='width: 25%;'>Final Grade: <span id='final-grade-{$internal_student_id}'>-</span></td>"; // Use internal_student_id
        echo "<td style='width: 25%;'>Achievement: <span id='achievement-{$internal_student_id}'>Not Assessed</span></td>"; // Use internal_student_id
        echo "<td style='width: 25%;'>Position: " . htmlspecialchars($position_text) . "</td>";
    } else {
        // If position is disabled, only show three columns (33.33% width each)
        echo "<td style='width: 33.33%;'>Average Score: <span id='overall-avg-{$internal_student_id}'>0.0</span></td>"; // Use internal_student_id
        echo "<td style='width: 33.33%;'>Final Grade: <span id='final-grade-{$internal_student_id}'>-</span></td>"; // Use internal_student_id
        echo "<td style='width: 33.33%;'>Achievement: <span id='achievement-{$internal_student_id}'>Not Assessed</span></td>"; // Use internal_student_id
    }

    echo "</tr>";
    echo "</table>";

    // Calculate the final grade for comment generation
    $student_total = 0;
    $subject_count = 0;

    foreach ($selected_topics as $subject => $topics) {
        $subject_name = $subject_names_cache[$subject] ?? $subject;
        $is_compulsory = $compulsory_cache[$subject] ?? false;

        $aoi_marks = [];
        $eot_mark = null;

        if (isset($all_marks[$subject])) {
            foreach ($topics as $topic_id) {
                $mark = $all_marks[$subject][$topic_id] ?? null;
                if ($topic_id === 'EOT') {
                    $eot_mark = $mark;
                } else {
                    if ($mark !== null && $mark !== '' && $mark !== '-') {
                        $aoi_marks[] = $mark;
                    }
                }
            }
        }

        $has_marks = subjectHasMarks($aoi_marks, $eot_mark);
        if (!$is_compulsory && !$has_marks) {
            continue;
        }

        // Calculate total for this subject
        $total = null;
        $has_aoi = !empty($aoi_marks);
        $aoi_only = $has_aoi && ($eot_mark === null || $eot_mark === '' || $eot_mark === '-');

        if ($has_aoi) {
            $aoi_sum = array_sum($aoi_marks);
            $aoi_avg = $aoi_sum / count($aoi_marks);

            if ($aoi_only) {
                $total = ($aoi_avg * 20) / 3;
            } else if ($eot_mark !== null && $eot_mark !== '' && $eot_mark !== '-') {
                $aoi_20 = ($aoi_avg / 3) * 20;
                $eot_80 = ($eot_mark / 100) * 80;
                $total = $aoi_20 + $eot_80;
            }
        } else if ($eot_mark !== null && $eot_mark !== '' && $eot_mark !== '-') {
            $total = $eot_mark;
        }

        if ($total !== null) {
            $student_total += $total;
            $subject_count++;
        }
    }

    $average = $subject_count > 0 ? $student_total / $subject_count : 0;
    $final_grade = convertToGrade($average);

    // Generate unique comments for this student
    $class_teacher_comment = getTeacherComment($student_name, $final_grade, 'class_teacher');
    $head_teacher_comment = getTeacherComment($student_name, $final_grade, 'head_teacher');

    echo "
    <div class='comments-section'>
        <div class='comment-row'>
            <div class='comment-label'>Class Teacher's Comment:</div>
            <div class='comment-text'>" . htmlspecialchars($class_teacher_comment) . "</div>
            <div class='signature-area'>
                <span class='signature-label'>Sign:</span>
                <div class='signature-line'></div>
            </div>
        </div>
        
        <div class='comment-row'>
            <div class='comment-label'>Head Teacher's Comment:</div>
            <div class='comment-text'>" . htmlspecialchars($head_teacher_comment) . "</div>
            <div class='signature-area'>
                <span class='signature-label'>Sign:</span>
                <div class='signature-line'></div>
            </div>
        </div>
    </div>";

    // ADD THE TERM INFORMATION TABLE HERE
    echo "
        <table class='summary-table'>
           <tr>
            <td style='width: 33.33%;'>Next term's fees: " . htmlspecialchars($next_term_details['next_term_fees']) . "</td>
            <td style='width: 33.33%;'>Next term begins on: " . htmlspecialchars($next_term_details['next_term_date']) . "</td>
            <td style='width: 33.33%;'>Term Ends on: " . htmlspecialchars($next_term_details['next_term_ends']) . "</td>
        </tr>
        </table>";

    echo "
        
        <table class='grades-table'>
            <tr>
                <th style='width: 16.66%;'>Score Range</th>
                <th style='width: 16.66%;'>85-100</th>
                <th style='width: 16.66%;'>70-84</th>
                <th style='width: 16.66%;'>50-69</th>
                <th style='width: 16.66%;'>40-49</th>
                <th style='width: 16.66%;'><39</th>
            </tr>
            <tr>
                <td style='font-weight: bold;'>GRADE</td>
                <td style='font-weight: bold;'>A</td>
                <td style='font-weight: bold;'>B</td>
                <td style='font-weight: bold;'>C</td>
                <td style='font-weight: bold;'>D</td>
                <td style='font-weight: bold;'>E</td>
            </tr>
            <tr>
                <td style='font-weight: bold;'>Achievement</td>
                <td>Exceptional</td>
                <td>Outstanding</td>
                <td>Satisfactory</td>
                <td>Basic</td>
                <td>Elementary</td>
            </tr>
        </table>
        
        <div style='margin-top: 20px; text-align: right;'>
            <div style='display: inline-block; text-align: center;'>
                <div id='qrcode-{$internal_student_id}' style='display: inline-block;'></div>
                <div style='font-size: 10px; margin-top: 5px; font-weight: bold; color: #333;'>SCAN TO VERIFY</div>
            </div>
        </div>

       <script>
            (function() {
                const studentId = " . json_encode($display_student_id_string) . ";
                const term = " . json_encode($term) . ";
                const year = " . json_encode($year) . ";
                const studentName = " . json_encode($student_name) . ";
                const className = " . json_encode($class) . ";
                const streamName = " . json_encode($display_stream) . ";
                
                // Get the base URL dynamically
                const protocol = window.location.protocol;
                const host = window.location.host;
                const pathArray = window.location.pathname.split('/');
                pathArray.pop(); // Remove current file
                const basePath = pathArray.join('/');
                
                // Construct dynamic verification URL
                const verificationUrl = protocol + '//' + host + basePath + '/verify_report.php?student_id=' + 
                    encodeURIComponent(studentId) + 
                    '&term=' + encodeURIComponent(term) + 
                    '&year=' + encodeURIComponent(year) +
                    '&class=' + encodeURIComponent(className) +
                    '&stream=' + encodeURIComponent(streamName);
                
                // Generate high-quality QR code using QRCode.js library
                const qrContainer = document.getElementById('qrcode-{$internal_student_id}');
                if (qrContainer && typeof QRCode !== 'undefined') {
                    // Clear any existing content
                    qrContainer.innerHTML = '';
                    
                    // Create high-quality QR code with black color and transparent background
                    new QRCode(qrContainer, {
                        text: verificationUrl,
                        width: 100,
                        height: 100,
                        correctLevel: QRCode.CorrectLevel.M, // Highest error correction
                        colorDark: '#000000',  // Black QR code
                        colorLight: '#ffffff'  // White background (will appear transparent on white page)
                    });
                }
            })();
        </script>
        
        <div style='page-break-before: always;'></div>
        
        <div class='aoi-back-page'>
            <div class='header' style='margin-top: 20px;'>
                <div class='school-name'>ACTIVITIES OF INTEGRATION (AOI) KEY</div>
                <div style='margin-top: 5px;'>
                    <strong>TERM " . htmlspecialchars($term) . ", YEAR: " . htmlspecialchars($year) . " - " . htmlspecialchars($class) . "</strong>
                </div>
            </div>
            
            <table class='aoi-table' id='aoi-table-{$internal_student_id}' style='margin-top: 15px; width: 100%;'>
            <thead>
                <tr>
                    <th style='width: 10%; text-align: center;'></th>
                    <th style='width: 15%; text-align: center;'>SUBJECT</th>
                    <th style='width: 15%; text-align: center;'>AOI</th>
                    <th style='width: 25%; text-align: center;'>ACTIVITIES OF INTEGRATION</th>
                    <th style='width: 35%; text-align: center;'>COMPETENCY</th>
                </tr>
            </thead>
            <tbody>";

    $student_total = 0;
    $subject_count = 0;

    foreach ($selected_topics as $subject => $topics) {
        $subject_name = $subject_names_cache[$subject] ?? $subject;
        $is_compulsory = $compulsory_cache[$subject] ?? false;

        $aoi_marks = [];
        $eot_mark = null;

        if (isset($all_marks[$subject])) {
            foreach ($topics as $topic_id) {
                $mark = $all_marks[$subject][$topic_id] ?? null;
                if ($topic_id === 'EOT') {
                    $eot_mark = $mark;
                } else {
                    if ($mark !== null && $mark !== '' && $mark !== '-') {
                        $aoi_marks[] = $mark;
                    }
                }
            }
        }

        $has_marks = subjectHasMarks($aoi_marks, $eot_mark);
        if (!$is_compulsory && !$has_marks) {
            continue;
        }

        // Get AOI topics (exclude EOT) and limit to selected columns
        $aoi_topics_data = [];
        $aoi_count = 0;

        foreach ($topics as $topic_id) {
            if ($topic_id !== 'EOT' && $aoi_count < $aoi_columns) {
                // Get topic name from aoi table
                $topic_name = 'Topic ' . $topic_id; // Default fallback
                $description = 'Description for ' . $topic_name; // Default fallback

                // Get from aoi table - get both topic and description
                $aoi_sql = "SELECT topic, description FROM aoi WHERE id = ?";
                $aoi_stmt = mysqli_prepare($conn, $aoi_sql);
                if ($aoi_stmt) {
                    mysqli_stmt_bind_param($aoi_stmt, "s", $topic_id);
                    mysqli_stmt_execute($aoi_stmt);
                    $aoi_result = mysqli_stmt_get_result($aoi_stmt);
                    if ($aoi_result && $aoi_row = mysqli_fetch_assoc($aoi_result)) {
                        $topic_name = $aoi_row['topic'];
                        $description = $aoi_row['description'] ?? 'Description for ' . $topic_name;
                    }
                    mysqli_stmt_close($aoi_stmt); // Close statement
                }

                $aoi_topics_data[] = [
                    'id' => $topic_id,
                    'name' => $topic_name,
                    'description' => $description
                ];

                $aoi_count++;
            }
        }

        if (!empty($aoi_topics_data)) {
            $row_count = count($aoi_topics_data);

            // First row with rowspan
            $first_topic = $aoi_topics_data[0];
            echo "<tr>";
            echo "<td rowspan='{$row_count}' style='text-align: center; font-weight: bold; vertical-align: middle; padding: 8px 5px;'>";
            echo "{$subject_counter}.";
            echo "</td>";
            echo "<td rowspan='{$row_count}' style='text-align: center; font-weight: bold; vertical-align: middle; padding: 8px 5px;'>";
            echo htmlspecialchars($subject_name);
            echo "</td>";
            echo "<td style='text-align: center; font-weight: bold; padding: 6px;'>";
            echo "AOI 1";
            echo "</td>";
            echo "<td style='text-align: left; padding: 6px;'>";
            echo htmlspecialchars($first_topic['name']);
            echo "</td>";
            echo "<td style='text-align: left; padding: 6px; font-size: 10px;'>";
            echo htmlspecialchars($first_topic['description']);
            echo "</td>";
            echo "</tr>";

            // Subsequent rows
            for ($i = 1; $i < count($aoi_topics_data); $i++) {
                $topic_data = $aoi_topics_data[$i];
                $aoi_number = $i + 1;

                echo "<tr>";
                echo "<td style='text-align: center; font-weight: bold; padding: 6px;'>";
                echo "AOI {$aoi_number}";
                echo "</td>";
                echo "<td style='text-align: left; padding: 6px;'>";
                echo htmlspecialchars($topic_data['name']);
                echo "</td>";
                echo "<td style='text-align: left; padding: 6px; font-size: 10px;'>";
                echo htmlspecialchars($topic_data['description']);
                echo "</td>";
                echo "</tr>";
            }
        } else {
            // Subject with no AOI data
            echo "<tr>";
            echo "<td style='text-align: center; font-weight: bold; vertical-align: middle; padding: 8px 5px;'>";
            echo "{$subject_counter}.";
            echo "</td>";
            echo "<td style='text-align: center; font-weight: bold; vertical-align: middle; padding: 8px 5px;'>";
            echo htmlspecialchars($subject_name);
            echo "</td>";
            echo "<td style='text-align: center; padding: 6px;'>-</td>";
            echo "<td style='text-align: left; padding: 6px; font-style: italic; color: #666;'>";
            echo "No AOI activities recorded";
            echo "</td>";
            echo "<td style='text-align: left; padding: 6px;'>-</td>";
            echo "</tr>";
        }

        $subject_counter++;
    }

    echo "
            </tbody>
        </table>
        </div>";

    // Add Teacher Initials Key Section on the same page
    echo "
        <div style='margin-top: 25px; margin-bottom: 15px;'>
            <h5 style='font-weight: bold; margin-bottom: 10px; font-size: 14px; text-align: center;'>TEACHER INITIALS KEY</h5>
            <table style='width: 100%; border: 1px solid #333; font-size: 11px;'>
                <thead>
                    <tr>
                        <th style='width: 15%; padding: 6px; border: 1px solid #333;'>INITIALS</th>
                        <th style='width: 40%; padding: 6px; border: 1px solid #333;'>TEACHER NAME</th>
                        <th style='width: 45%; padding: 6px; border: 1px solid #333;'>SUBJECT(S)</th>
                    </tr>
                </thead>
                <tbody>";

    // Collect unique teacher-subject combinations for this student
    $teacher_subjects = [];

    foreach ($selected_topics as $subject => $topics) {
        $subject_name = $subject_names_cache[$subject] ?? $subject;
        $is_compulsory = $compulsory_cache[$subject] ?? false;

        $aoi_marks_check = [];
        $eot_mark_check = null;

        if (isset($all_marks[$subject])) {
            foreach ($topics as $topic_id) {
                $mark = $all_marks[$subject][$topic_id] ?? null;
                if ($topic_id === 'EOT') {
                    $eot_mark_check = $mark;
                } else {
                    if ($mark !== null && $mark !== '' && $mark !== '-') {
                        $aoi_marks_check[] = $mark;
                    }
                }
            }
        }

        $has_marks = subjectHasMarks($aoi_marks_check, $eot_mark_check);
        if (!$is_compulsory && !$has_marks) {
            continue;
        }

        // Get teacher details for this subject
        $initials = getTeacherInitials($conn, $subject_name, $class, $clean_stream, $teacher_initials_cache);

        if ($initials !== '-') {
            // Get full teacher name
            $teacher_sql = "
            SELECT CONCAT(s.first_name, ' ', s.last_name) AS full_name
            FROM teaching_assignments ta
            JOIN staff s ON ta.staff_id = s.staff_id
            JOIN subjects subj ON ta.subject_id = subj.subj_id
            WHERE subj.subj_name = ? AND ta.class_name = ? AND (ta.stream_name = ? OR ta.stream_name = 'All Streams')
            LIMIT 1
        ";

            $teacher_stmt = mysqli_prepare($conn, $teacher_sql);
            if ($teacher_stmt) {
                mysqli_stmt_bind_param($teacher_stmt, "sss", $subject_name, $class, $clean_stream);
                mysqli_stmt_execute($teacher_stmt);
                $teacher_result = mysqli_stmt_get_result($teacher_stmt);

                if ($teacher_row = mysqli_fetch_assoc($teacher_result)) {
                    $teacher_name = $teacher_row['full_name'];

                    // Group subjects by teacher
                    if (!isset($teacher_subjects[$initials])) {
                        $teacher_subjects[$initials] = [
                            'name' => $teacher_name,
                            'subjects' => []
                        ];
                    }
                    $teacher_subjects[$initials]['subjects'][] = $subject_name;
                }
                mysqli_stmt_close($teacher_stmt);
            }
        }
    }

    // Display teacher information
    foreach ($teacher_subjects as $initials => $data) {
        $subjects_list = implode(', ', $data['subjects']);
        echo "
                    <tr>
                        <td style='padding: 6px; border: 1px solid #333; text-align: center; font-weight: bold;'>" . htmlspecialchars($initials) . "</td>
                        <td style='padding: 6px; border: 1px solid #333;'>" . htmlspecialchars($data['name']) . "</td>
                        <td style='padding: 6px; border: 1px solid #333;'>" . htmlspecialchars($subjects_list) . "</td>
                    </tr>";
    }

    echo "
                </tbody>
            </table>
        </div>
    </div>";

    // Add JavaScript to auto-scale font size based on content
    echo "
    <script>
        (function() {
            const aoiTable = document.getElementById(" . json_encode("aoi-table-{$internal_student_id}") . ");
            if (aoiTable) {
                setTimeout(function() {
                    const backPage = aoiTable.closest('.aoi-back-page');
                    const tableHeight = aoiTable.offsetHeight;
                    const pageHeight = window.innerHeight || document.documentElement.clientHeight;
                    const availableHeight = pageHeight - 150;
                    
                    const totalRows = aoiTable.querySelectorAll('tbody tr').length;
                    
                    if (tableHeight > availableHeight || totalRows > 15) {
                        if (totalRows > 25 || tableHeight > availableHeight * 1.3) {
                            aoiTable.classList.add('extra-large-content');
                        } else {
                            aoiTable.classList.add('large-content');
                        }
                    }
                }, 200);
            }
        })();
    </script>";

    // Add JavaScript to auto-scale font size based on content
    echo "
    <script>
        (function() {
            const aoiTable = document.getElementById(" . json_encode("aoi-table-{$internal_student_id}") . ");
            if (aoiTable) {
                setTimeout(function() {
                    const backPage = aoiTable.closest('.aoi-back-page');
                    const tableHeight = aoiTable.offsetHeight;
                    const pageHeight = window.innerHeight || document.documentElement.clientHeight;
                    const availableHeight = pageHeight - 150;
                    
                    const totalRows = aoiTable.querySelectorAll('tbody tr').length;
                    
                    if (tableHeight > availableHeight || totalRows > 15) {
                        if (totalRows > 25 || tableHeight > availableHeight * 1.3) {
                            aoiTable.classList.add('extra-large-content');
                        } else {
                            aoiTable.classList.add('large-content');
                        }
                    }
                }, 200);
            }
        })();
    </script>";

    // Add JavaScript for this specific student
    echo "
    <script>
        (function() {
            const studentId = " . json_encode($internal_student_id) . ";
            const aoiColumns = " . json_encode((int)$aoi_columns) . ";
            
            function calculateGrade(marks) {
                if (marks === null || marks === '' || isNaN(marks)) {
                    return '-';
                }
                
                marks = parseFloat(marks);
                
                if (marks >= 85) return 'A';
                if (marks >= 70) return 'B';
                if (marks >= 50) return 'C';
                if (marks >= 40) return 'D';
                return 'E';
            }
            
            function getAchievement(grade) {
                const achievementMap = {
                    'A': 'Exceptional',
                    'B': 'Outstanding', 
                    'C': 'Satisfactory',
                    'D': 'Basic',
                    'E': 'Elementary',
                    '-': 'Not Assessed'
                };
                return achievementMap[grade] || 'Not Assessed';
            }
            
            function calculateRowValues() {
                const table = document.getElementById('results-table-' + studentId);
                if (!table) return;
                
                const rows = table.querySelectorAll('tbody tr');
                let totalSum = 0;
                let subjectCount = 0;
                
                rows.forEach(row => {
                    const aoiCells = [];
                    for (let i = 0; i < aoiColumns; i++) {
                        const cell = row.querySelector('td[data-aoi=\"' + i + '\"]');
                        if (cell) {
                            const value = cell.textContent.trim();
                            aoiCells.push(value === '-' ? null : parseFloat(value));
                        }
                    }
                    
                    const validAoi = aoiCells.filter(val => val !== null && !isNaN(val));
                   const aoiSum = validAoi.length > 0 ? validAoi.reduce((sum, val) => sum + val, 0) : null;
                   const aoiAvg = aoiSum !== null ? aoiSum / aoiColumns : null;
                    
                    const avgCell = row.querySelector('td[data-avg]');
                    if (avgCell) {
                        avgCell.textContent = aoiAvg !== null ? aoiAvg.toFixed(1) : '-';
                    }
                    
                    const eotCell = row.querySelector('td[data-eot]');
                    const eotValue = eotCell ? eotCell.getAttribute('data-eot') : '';
                    const eotMark = (eotValue && eotValue !== '') ? parseFloat(eotValue) : null;
                    
                    let outOf20 = null;
                    let eotOf80 = null;
                    let total = null;
                    
                    const hasAoi = validAoi.length > 0;
                    const hasEot = eotMark !== null && !isNaN(eotMark);
                    const aoiOnly = hasAoi && !hasEot;
                    
                    if (hasAoi) {
                        if (aoiOnly) {
                            total = (aoiAvg * 20) / 3;
                            outOf20 = total;
                        } else if (hasEot) {
                            outOf20 = (aoiAvg / 3) * 20;
                            eotOf80 = (eotMark / 100) * 80;
                            total = outOf20 + eotOf80;
                        } else {
                            outOf20 = (aoiAvg / 3) * 20;
                            total = outOf20;
                        }
                    } else if (hasEot) {
                        total = eotMark;
                        eotOf80 = (eotMark / 100) * 80;
                        outOf20 = null;
                    }
                    
                    const outOf20Cell = row.querySelector('td[data-out-of-20]');
                    if (outOf20Cell) {
                        outOf20Cell.textContent = outOf20 !== null ? Math.round(outOf20) : '-';
                    }
                    
                    if (eotCell) {
                        eotCell.textContent = eotOf80 !== null ? Math.round(eotOf80) : (aoiOnly ? '-' : '-');
                    }
                    
                    const totalCell = row.querySelector('td[data-total]');
                    if (totalCell) {
                        totalCell.textContent = total !== null ? Math.round(total) : '-';
                    }
                    
                    const gradeCell = row.querySelector('td[data-grade]');
                    if (gradeCell) {
                        gradeCell.textContent = calculateGrade(total);
                    }
                    
                    if (total !== null) {
                        totalSum += total;
                        subjectCount++;
                    }
                });
                
                const overallAvg = subjectCount > 0 ? totalSum / subjectCount : 0;
                const finalGrade = calculateGrade(overallAvg);
                const achievement = getAchievement(finalGrade);
                
                const avgSpan = document.getElementById('overall-avg-' + studentId);
                const gradeSpan = document.getElementById('final-grade-' + studentId);
                const achievementSpan = document.getElementById('achievement-' + studentId);
                
                if (avgSpan) avgSpan.textContent = overallAvg.toFixed(1);
                if (gradeSpan) gradeSpan.textContent = finalGrade;
                if (achievementSpan) achievementSpan.textContent = achievement;
            }
            
            setTimeout(calculateRowValues, 100);
        })();
    </script>";
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
        console.log('All " . count($student_ids) . " summarized reports generated in {$execution_time} seconds');
        
        // Final calculation sweep for all tables (in case any missed)
        setTimeout(function() {
            const allTables = document.querySelectorAll('[id^=\"results-table-\"]');
            allTables.forEach(table => {
                const event = new Event('recalculate');
                table.dispatchEvent(event);
            });
        }, 500);
    </script>
</body>
</html>";

// Close database connection
mysqli_close($conn);
