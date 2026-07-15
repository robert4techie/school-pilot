<?php
// Start with script execution time tracking
$start_time = microtime(true);

// Enable output buffering for faster page loading
ob_start();

// CHECK FOR VERIFIED QR CODE ACCESS
$is_verified_access = false;
if (isset($_GET['verified']) && isset($_GET['verification_token'])) {
    $expected_token = md5($_GET['student_id'] . $_GET['term'] . $_GET['year'] . 'OU_SECRET_KEY');
    if ($_GET['verification_token'] === $expected_token) {
        $is_verified_access = true;
    }
}

// Only require auth if NOT verified
if (!$is_verified_access) {
    require_once '../auth.php';
}
require_once '../conn.php';

// Set higher memory limit if needed
ini_set('memory_limit', '1024M');

// Initialize variables with defaults
$student_id = $_GET['student_id'] ?? null;
$class = $_GET['class'] ?? 'Senior One';
$term = $_GET['term'] ?? 'Term 1';
$year = $_GET['year'] ?? date('Y');
$report_type = $_GET['report_type'] ?? 'assessment';

$school_details = getSchoolProfile($conn);

// NEW: Get the number of AOI columns to generate and percentage display option
$aoi_columns = isset($_GET['aoi_columns']) ? (int)$_GET['aoi_columns'] : 3;
$aoi_columns = max(1, min(10, $aoi_columns)); // Limit between 1 and 10 columns
$display_percentage = isset($_GET['display_percentage']) && $_GET['display_percentage'] == '1';

// NEW: Get display_student_position parameter
$display_student_position = isset($_GET['display_student_position']) && $_GET['display_student_position'] == '1';

// Fix: Make sure stream is initialized properly
if (isset($_GET['stream']) && !is_array($_GET['stream'])) {
    $stream = [$_GET['stream']];
} else if (isset($_GET['stream']) && is_array($_GET['stream'])) {
    $stream = $_GET['stream'];
} else {
    $stream = ['A', 'B', 'C'];
}

$bulk_mode = empty($student_id);

// Convert term to roman numeral for table name
$term_number = filter_var($term, FILTER_SANITIZE_NUMBER_INT);
$romans = ['i', 'ii', 'iii']; // Use lowercase Roman numerals
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
    echo "<div class='alert alert-danger'>The marks table for {$term} {$year} does not exist. Please check your selections.</div>";
    exit;
}

$student_ids = [];

// Get list of all students in the class/stream(s)
if ($bulk_mode && $table_exists) {
    if (!empty($stream)) {
        try {
            $placeholders = implode(',', array_fill(0, count($stream), '?'));
            $sql = "SELECT DISTINCT student_id FROM `$table_name` WHERE class = ? AND stream IN ($placeholders) ORDER BY student_id";

            $stmt = mysqli_prepare($conn, $sql);

            if ($stmt) {
                $types = 's' . str_repeat('s', count($stream));
                $bind_params = array($types, $class);
                $bind_params = array_merge($bind_params, $stream);

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
                mysqli_stmt_close($stmt); // Close the statement
            } else {
                error_log("Failed to prepare student ID query in bulk mode: " . mysqli_error($conn)); //
            }
        } catch (Exception $e) {
            error_log("Error fetching student IDs in bulk mode: " . $e->getMessage()); //
        }
    }
} else if (!empty($student_id)) {
    $student_ids[] = $student_id;
}

if (empty($student_ids)) {
    $stream_display = is_array($stream) ? implode(', ', $stream) : $stream;
    echo "<div class='alert alert-warning'>No students found in {$class} Stream(s) {$stream_display} for {$term} {$year}</div>";
    exit;
}
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

// Function to generate professional comments based on grade
function getTeacherComment($student_name, $grade, $type = 'class_teacher')
{
    // Extract only the first name
    $first_name = explode(' ', $student_name)[0];

   $class_teacher_comments = [
        'A' => [
            "$first_name has delivered an outstanding performance this term, consistently exceeding expectations across all areas assessed.",
            "$first_name's grasp of the subject matter is exceptional, and this is reflected in a truly impressive set of results.",
            "$first_name continues to set the standard for excellence in class through disciplined effort and sharp understanding.",
            "$first_name has produced brilliant work this term; the consistency and depth shown are highly commendable.",
            "$first_name approaches every task with confidence and precision, resulting in an excellent overall performance.",
            "$first_name's dedication to learning is evident in this outstanding set of results. Keep reaching higher!",
            "$first_name has shown mastery well beyond expectation this term. A truly exceptional effort.",
            "$first_name combines strong ability with real focus, and it shows clearly in this excellent performance."
        ],
        'B' => [
            "$first_name has put in commendable work this term and shows a solid, growing command of the subject.",
            "$first_name demonstrates good understanding and consistent effort; a slightly sharper focus will push this even higher.",
            "$first_name has performed well overall, with clear strengths that, if built on, will lead to outstanding results.",
            "$first_name shows strong potential and steady progress. Continued consistency will make a real difference.",
            "$first_name has handled the term's work competently and with visible commitment.",
            "$first_name's performance reflects genuine effort and a good grasp of key concepts.",
            "$first_name is making pleasing progress and should aim to sustain this momentum next term.",
            "$first_name has shown reliable, above-average work this term. Well done."
        ],
        'C' => [
            "$first_name has made satisfactory progress this term, with room to grow through more consistent revision.",
            "$first_name shows an adequate understanding of the material; more regular practice will strengthen this further.",
            "$first_name has performed reasonably well but would benefit from sharper focus in class.",
            "$first_name's results are acceptable this term. A more disciplined study routine would raise this significantly.",
            "$first_name is capable of more than this result shows; increased effort next term is encouraged.",
            "$first_name has met the basic expectations for this term, and steady improvement is achievable with more application.",
            "$first_name shows moderate understanding overall; targeted revision in weaker areas will help.",
            "$first_name has done fair work this term. Consistent effort will bring noticeable improvement."
        ],
        'D' => [
            "$first_name needs to put in considerably more effort to meet the expected standard.",
            "$first_name's results show gaps in understanding that require focused attention and extra practice.",
            "$first_name should dedicate more time to studies next term, with support from teachers where needed.",
            "$first_name would benefit greatly from seeking help promptly whenever concepts are unclear.",
            "$first_name has struggled with parts of the term's work; a more consistent study habit is needed.",
            "$first_name must work harder and stay more engaged in class to see improvement.",
            "$first_name's performance this term falls below expectation; closer follow-up at home would help.",
            "$first_name needs to build stronger study habits to make meaningful progress next term."
        ],
        'E' => [
            "$first_name requires urgent and sustained support to improve performance significantly.",
            "$first_name's results this term are a serious concern and call for intensive remedial attention.",
            "$first_name must seek extra help immediately and commit to a structured study plan.",
            "$first_name needs close guidance from both teachers and parents to turn performance around.",
            "$first_name's foundational understanding needs urgent strengthening across most areas.",
            "$first_name should attend remedial lessons regularly and prioritize catching up on missed concepts.",
            "$first_name's performance indicates a need for a completely different approach to study next term.",
            "$first_name requires immediate, dedicated intervention to avoid falling further behind."
        ],
        '-' => [
            "$first_name's performance could not be fully assessed due to incomplete assessment data.",
            "$first_name needs to complete all outstanding assessments for a full evaluation to be made.",
            "$first_name's record for this term is incomplete; please ensure all assessments are submitted."
        ]
    ];

    $head_teacher_comments = [
        'A' => [
            "$first_name is an exemplary student whose outstanding results this term reflect real dedication.",
            "$first_name demonstrates exceptional academic ability and consistently sets a fine example for peers.",
            "$first_name's performance this term is truly commendable and a credit to the school.",
            "$first_name has shown remarkable intellectual strength; keep pursuing this standard of excellence.",
            "$first_name continues to excel impressively across the board. Outstanding work.",
            "$first_name is a role-model student whose results this term speak for themselves.",
            "$first_name's achievement this term is exceptional and thoroughly well-deserved.",
            "$first_name shows outstanding promise and should be proud of this term's results."
        ],
        'B' => [
            "$first_name is a diligent student whose good results this term are well earned.",
            "$first_name shows strong academic ability and consistent commitment to studies.",
            "$first_name has performed admirably this term and shows real promise for the future.",
            "$first_name is a dependable student whose steady progress is encouraging to see.",
            "$first_name demonstrates good discipline and understanding; keep building on this foundation.",
            "$first_name's results this term reflect solid, consistent effort. Well done.",
            "$first_name continues to grow steadily and should aim even higher next term.",
            "$first_name has shown commendable dedication this term."
        ],
        'C' => [
            "$first_name has shown acceptable progress this term, with clear room for further growth.",
            "$first_name demonstrates adequate performance and is capable of achieving more with added effort.",
            "$first_name's results this term are satisfactory; greater consistency will bring improvement.",
            "$first_name shows potential that, with more discipline, could translate into stronger results.",
            "$first_name needs continued encouragement and support to build on this term's progress.",
            "$first_name has met the basic expectations and should aim for steady improvement ahead.",
            "$first_name's performance is fair overall, and increased focus would help considerably.",
            "$first_name is encouraged to apply more consistent effort in the coming term."
        ],
        'D' => [
            "$first_name must show significant improvement in the coming term.",
            "$first_name needs greater commitment to studies and closer engagement in class.",
            "$first_name should work closely with teachers to address the gaps identified this term.",
            "$first_name's results require immediate attention from both school and home.",
            "$first_name needs a more structured and disciplined approach to studies.",
            "$first_name must take this term's results seriously and act promptly to improve.",
            "$first_name would benefit from additional support to strengthen weaker areas.",
            "$first_name needs to demonstrate marked improvement next term."
        ],
        'E' => [
            "$first_name requires immediate and sustained intervention to improve performance.",
            "$first_name's results this term are of serious concern and demand urgent action.",
            "$first_name must make drastic improvements, supported closely by teachers and parents.",
            "$first_name needs intensive supervision and remedial support going forward.",
            "$first_name requires a serious, renewed commitment to studies without delay.",
            "$first_name's performance this term calls for urgent, coordinated support at school and home.",
            "$first_name must prioritize catching up on foundational concepts immediately.",
            "$first_name needs decisive intervention to prevent further decline in performance."
        ],
        '-' => [
            "$first_name's record requires complete assessment before a full evaluation can be given.",
            "$first_name needs a comprehensive evaluation once all assessments are complete.",
            "$first_name should complete all outstanding assessments for this term."
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
        error_log("Failed to prepare statement in getStudentDetails: " . mysqli_error($conn)); //
        return null;
    }

    mysqli_stmt_bind_param($stmt, "s", $student_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result && $row = mysqli_fetch_assoc($result)) {
        mysqli_stmt_close($stmt); // Close the statement
        return $row;
    }

    mysqli_stmt_close($stmt); // Close the statement even if no results
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
        error_log("Failed to prepare statement in getSelectedTopics: " . mysqli_error($conn)); //
        return []; // Return empty array to prevent further errors
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

    mysqli_stmt_close($stmt); // Close the statement
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
// Function to convert marks to grade (for the report)
function convertToGrade($marks)
{
    if ($marks === null || $marks === '' || is_nan($marks)) { // Added is_nan check
        return '-';
    }

    $marks = floatval($marks);

    // Assessment report 5-grade scale
    if ($marks >= 2.7) return 'A'; // 2.7 - 3.0
    if ($marks >= 2.4) return 'B'; // 2.4 - 2.6
    if ($marks >= 1.8) return 'C'; // 1.8 - 2.3
    if ($marks >= 1.5) return 'D'; // 1.5 - 1.7
    if ($marks >= 0.9) return 'E'; // 0.9 - 1.4
    return '-'; // Below range / Not achieved
}

// Function to check if subject has marks
function subjectHasMarks($marks_array, $eot_mark)
{
    // Filter out null, empty string, and hyphen values
    $filtered_marks_array = array_filter($marks_array, function ($mark) {
        return $mark !== null && $mark !== '' && $mark !== '-';
    });

    if (!empty($filtered_marks_array)) {
        return true;
    }

    // Check EOT mark separately
    if ($eot_mark !== null && $eot_mark !== '' && $eot_mark !== '-') {
        return true;
    }

    return false;
}


// Helper: has this student actually registered/done this optional subject?
function isRegisteredOptionalSubject($student_registered_subjects, $student_id, $subject_name)
{
    return isset($student_registered_subjects[$student_id][$subject_name]);
}

// Helper: should this subject appear on the report at all?
function shouldDisplaySubject($is_compulsory, $has_marks, $is_registered_optional)
{
    return $is_compulsory || $has_marks || $is_registered_optional;
}

// Helper: display-only default of 0.9 when a subject qualifies to show but has no real marks.
// Never writes to the database - only affects what's rendered/calculated.
function applyDefaultMarkIfMissing($aoi_marks, $should_display, $has_marks)
{
    if ($should_display && !$has_marks) {
        if (!empty($aoi_marks)) {
            $aoi_marks[0] = 0.9;
        } else {
            $aoi_marks = [0.9];
        }
    }
    return $aoi_marks;
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
        error_log("Failed to prepare fallback topics query: " . mysqli_error($conn)); //
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
        mysqli_stmt_close($stmt); // Close statement
    }
}

[$subject_names_cache, $compulsory_cache] = getSubjectInfo($conn, [], []);
$teacher_initials_cache = [];

$total_students = count($student_ids);
$stream_display = is_array($stream) ? implode(', ', $stream) : $stream;

// Get all students marks in a single query
$all_students_marks = [];
if (!empty($student_ids) && $table_exists) {
    $id_list_placeholders = implode(',', array_fill(0, count($student_ids), '?'));
    $marks_sql = "SELECT student_id, subject, topic_id, marks, stream FROM `$table_name` 
                 WHERE student_id IN ($id_list_placeholders) AND class = ?";

    $marks_stmt = mysqli_prepare($conn, $marks_sql);

    if (!$marks_stmt) {
        error_log("Failed to prepare all_students_marks query: " . mysqli_error($conn)); //
    } else {
        $types = str_repeat('s', count($student_ids)) . 's'; // 's' for each student_id, then 's' for class
        $bind_params = array_merge($student_ids, [$class]);

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
        mysqli_stmt_close($marks_stmt); // Close the statement
    }
}

// Fetch each student's registered subjects (student_subjects table) so we know
// which optional subjects were truly "done" by them, even with no marks yet.
$student_registered_subjects = [];
if (!empty($student_ids)) {
    $reg_placeholders = implode(',', array_fill(0, count($student_ids), '?'));
    $reg_sql = "SELECT student_id, subject FROM student_subjects WHERE student_id IN ($reg_placeholders)";
    $reg_stmt = mysqli_prepare($conn, $reg_sql);

    if ($reg_stmt) {
        $reg_types = str_repeat('s', count($student_ids));
        $reg_bind_params = $student_ids;
        $reg_refs = [];
        foreach ($reg_bind_params as $key => $value) {
            $reg_refs[$key] = &$reg_bind_params[$key];
        }
        call_user_func_array([$reg_stmt, 'bind_param'], array_merge([$reg_types], $reg_refs));
        mysqli_stmt_execute($reg_stmt);
        $reg_result = mysqli_stmt_get_result($reg_stmt);

        if ($reg_result) {
            while ($row = mysqli_fetch_assoc($reg_result)) {
                $student_registered_subjects[$row['student_id']][$row['subject']] = true;
            }
        }
        mysqli_stmt_close($reg_stmt);
    } else {
        error_log("Failed to prepare student_subjects query: " . mysqli_error($conn));
    }
}

// Start HTML output
echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Assessment Reports - {$class} Streams {$stream_display} - {$term} {$year}</title>
        
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
        
        @page {
    size: A4;
    margin: 10mm;
}

    body {
        font-size: 14px;
        line-height: 1.5;
        margin: 0;
        padding: 20px;
    }

    .report-card {
        max-width: 1200px;
        margin: 0 auto 40px;
        padding: 25px;
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
        font-size: 22px;
        font-weight: bold;
    }

    .student-info {
        margin-bottom: 25px;
        border-top: 1px solid #000;
        border-bottom: 1px solid #000;
        padding: 10px 0;
        position: relative;
        z-index: 1;
    }

    .student-photo {
        max-width: 90px;
        height: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 25px;
        position: relative;
        z-index: 1;
        background-color: transparent !important;
    }

    th,
    td {
        border: 1px solid #333;
        padding: 8px;
        text-align: center;
        vertical-align: middle;
        background-color: transparent !important;
        font-size: 13px;
    }

    th {
        background-color: transparent !important;
        font-weight: bold;
        font-size: 12px;
        padding: 8px 7px;
    }

    .subject-cell {
        text-align: left;
        font-weight: bold;
        font-size: 13px;
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
        margin-top: 25px;
        margin-bottom: 25px;
        border: 1px solid #333;
    }

    .summary-table td {
        font-weight: bold;
        padding: 8px 12px;
        font-size: 14px;
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
    
    /* ============================================
       FULL-PAGE REPORT LOADER
       ============================================ */
    #report-loader-overlay {
        position: fixed;
        inset: 0;
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #ffffff;
        transition: opacity 0.5s ease, visibility 0.5s ease;
    }

    #report-loader-overlay.loader-hidden {
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
    }

    .loader-card {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        max-width: 360px;
        padding: 40px 30px;
    }

    .loader-logo {
        width: 70px;
        height: auto;
        margin-bottom: 18px;
        opacity: 0.9;
    }

    .loader-spinner {
        width: 52px;
        height: 52px;
        border: 4px solid #e5e7eb;
        border-top-color: #1b5e20;
        border-radius: 50%;
        animation: loader-spin 0.9s linear infinite;
        margin-bottom: 20px;
    }

    @keyframes loader-spin {
        to { transform: rotate(360deg); }
    }

    .loader-title {
        font-size: 17px;
        font-weight: 700;
        color: #1a1a1a;
        margin-bottom: 6px;
    }

    .loader-subtitle {
        font-size: 13px;
        color: #6b7280;
        margin-bottom: 22px;
    }

    .loader-progress-track {
        width: 100%;
        height: 6px;
        background: #eef0f2;
        border-radius: 10px;
        overflow: hidden;
        margin-bottom: 10px;
    }

    .loader-progress-fill {
        height: 100%;
        width: 0%;
        background: #1b5e20;
        border-radius: 10px;
        transition: width 0.25s ease;
    }

    .loader-progress-label {
        font-size: 12px;
        font-weight: 600;
        color: #374151;
        letter-spacing: 0.3px;
    }

    /* Keep report content invisible (but still loading in the background)
       until every photo, logo, and QR code has finished rendering */
    body:not(.reports-ready) .print-btn-container,
    body:not(.reports-ready) .processing-status,
    body:not(.reports-ready) .report-card {
        opacity: 0 !important;
        pointer-events: none;
    }

    body.reports-ready .report-card {
        opacity: 1;
        transition: opacity 0.4s ease;
    }

    @media print {
        #report-loader-overlay {
            display: none !important;
        }
    }

    .results-table-container {
        position: relative;
        margin-bottom: 25px;
    }

    .table-watermark {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        opacity: 0.2;
        width: 60%;
        height: auto;
        pointer-events: none;
        z-index: 0;
    }

    .aoi-column {
        font-size: 12px;
        min-width: 40px;
    }

    .eot-column {
        font-size: 12px;
    }

    .total-column {
        font-weight: bold;
        font-size: 12px;
    }

    .grade-column {
        font-weight: bold;
        font-size: 13px;
    }
    
    .percentage-column {
        font-weight: bold;
        font-size: 12px;
        background-color: rgba(74, 158, 255, 0.1) !important;
    }

    .comments-table td {
        text-align: left;
        padding: 10px 12px;
        font-size: 13px;
        vertical-align: top;
    }
    
    .comments-table tr:last-child td {
        padding-bottom: 50px;
    }

    .grades-table {
        background-color: transparent;
    }

    .grades-table th {
        text-align: center;
        font-weight: bold;
        font-size: 12px;
        background-color: transparent !important;
    }

    .grades-table td {
        font-size: 12px;
        background-color: transparent !important;
    }

    .calculated {
        background-color: transparent !important;
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
    .print-btn-container{
                display: none;
        }
    .comments-section {
        padding: 10px 0;
    }
    
    .comment-row {
        margin-bottom: 12px;
    }
}


/* Back page container - strict height control */
.aoi-back-page {
    page-break-inside: avoid;
    max-height: 100vh;
    overflow: hidden;
    /* CHANGED: tightened from 15px 0 to reclaim vertical space */
    padding: 10px 0;
}

/* Back page header - more compact */
.aoi-back-page .header {
    /* CHANGED: tightened from 10px */
    margin-top: 5px !important;
    /* CHANGED: tightened from 15px */
    margin-bottom: 10px !important;
}

.aoi-back-page .school-name {
    /* CHANGED: reduced from 16px */
    font-size: 14px !important;
    margin-bottom: 3px;
}

/* AOI Table - more compact */
.aoi-table {
    /* CHANGED: reduced from 12px to fix 3rd-page overflow */
    font-size: 10px !important;
    margin-top: 8px !important;
    margin-bottom: 10px !important;
}

.aoi-table td,
.aoi-table th {
    /* CHANGED: reduced from 12px */
    font-size: 10px !important;
    line-height: 1.15 !important;
    /* CHANGED: tightened padding */
    padding: 3px 4px !important;
}

/* Subject counter and subject name cells */
.aoi-table td[rowspan],
.aoi-table td[style*='font-weight: bold'] {
    padding: 4px 3px !important;
}

/* Teacher Initials Key section - more compact */
.aoi-back-page > div:last-child {
    /* CHANGED: tightened from 15px */
    margin-top: 10px !important;
    /* CHANGED: tightened from 10px */
    margin-bottom: 6px !important;
}

.aoi-back-page > div:last-child h5 {
    /* CHANGED: reduced from 14px */
    font-size: 11px !important;
    margin-bottom: 6px !important;
}

.aoi-back-page > div:last-child table {
    /* CHANGED: reduced from 11px */
    font-size: 9px !important;
}

.aoi-back-page > div:last-child table th,
.aoi-back-page > div:last-child table td {
    /* CHANGED: tightened padding */
    padding: 3px 4px !important;
    /* CHANGED: reduced from 11px */
    font-size: 9px !important;
}

/* Dynamic scaling for large content */
.aoi-table.large-content,
.aoi-table.large-content td,
.aoi-table.large-content th {
    font-size: 8px !important;
    padding: 2px 3px !important;
    line-height: 1.05 !important;
}

.aoi-table.extra-large-content,
.aoi-table.extra-large-content td,
.aoi-table.extra-large-content th {
    font-size: 7px !important;
    padding: 2px 2px !important;
    line-height: 1.0 !important;
}

/* Print-specific adjustments for back page */
@media print {
    .aoi-back-page {
        page-break-inside: avoid;
        max-height: 100vh;
        /* CHANGED: tightened from 10mm */
        padding-top: 6mm !important;
        padding-bottom: 6mm !important;
    }
    
    .aoi-back-page .header {
        /* CHANGED: tightened from 5mm */
        margin-top: 3mm !important;
        /* CHANGED: tightened from 8mm */
        margin-bottom: 5mm !important;
    }
    
    .aoi-table {
        /* CHANGED: reduced from 10pt to fix 3rd-page overflow */
        font-size: 8pt !important;
        margin-top: 3mm !important;
        margin-bottom: 5mm !important;
    }
    
    .aoi-table th,
    .aoi-table td {
        /* CHANGED: reduced from 10pt */
        font-size: 8pt !important;
        /* CHANGED: tightened padding */
        padding: 1.5mm 1.5mm !important;
        line-height: 1.1 !important;
    }
    
    /* Teacher Initials Key - compact for print */
    .aoi-back-page > div:last-child {
        /* CHANGED: tightened from 8mm */
        margin-top: 5mm !important;
        /* CHANGED: tightened from 5mm */
        margin-bottom: 3mm !important;
    }
    
    .aoi-back-page > div:last-child h5 {
        /* CHANGED: reduced from 11.5pt */
        font-size: 9pt !important;
        margin-bottom: 3mm !important;
    }
    
    .aoi-back-page > div:last-child table {
        /* CHANGED: reduced from 9pt */
        font-size: 7pt !important;
    }
    
    .aoi-back-page > div:last-child table th,
    .aoi-back-page > div:last-child table td {
        /* CHANGED: tightened padding */
        padding: 1.5mm 1.5mm !important;
        /* CHANGED: reduced from 9pt */
        font-size: 7pt !important;
    }
}
    </style>
</head>
<body>
    <div id='report-loader-overlay'>
        <div class='loader-card'>
            <img src='/" . htmlspecialchars($school_details['logo_path'] ?? '../assets/img/logo.jpg') . "' alt='School Logo' class='loader-logo'>
            <div class='loader-spinner'></div>
            <div class='loader-title'>Preparing Reports</div>
            <div class='loader-subtitle'>Loading photos, QR codes &amp; report data&hellip;</div>
            <div class='loader-progress-track'>
                <div class='loader-progress-fill' id='loader-progress-fill'></div>
            </div>
            <div class='loader-progress-label'>
                <span id='loader-progress-percent'>0</span>% &middot; <span id='loader-current-count'>0</span> of {$total_students} students
            </div>
        </div>
    </div>

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

$all_student_averages = [];
$position_map = [];

// Calculate averages for ALL students (needed for sorting)
foreach ($student_ids as $sid) {
    if (!isset($all_students_marks[$sid])) continue;

    $displayed_subjects = [];

   foreach ($selected_topics as $subject => $topics) {
        $subject_name = $subject_names_cache[$subject] ?? $subject;
        $is_compulsory = $compulsory_cache[$subject] ?? false;

        $aoi_marks_check = [];
        $eot_mark_check = null;

        if (isset($all_students_marks[$sid]['subjects'][$subject])) {
            foreach ($topics as $topic_id) {
                $mark = $all_students_marks[$sid]['subjects'][$subject][$topic_id] ?? null;

                if ($topic_id === 'EOT') {
                    $eot_mark_check = $mark;
                } else {
                    if ($mark !== null && $mark !== '' && $mark !== '-') {
                        $aoi_marks_check[] = floatval($mark);
                    }
                }
            }
        }

        $has_marks = !empty($aoi_marks_check) || ($eot_mark_check !== null && $eot_mark_check !== '' && $eot_mark_check !== '-');
        $is_registered_optional = isRegisteredOptionalSubject($student_registered_subjects, $sid, $subject_name);

        if (shouldDisplaySubject($is_compulsory, $has_marks, $is_registered_optional)) {
            $displayed_subjects[] = $subject;
        }
    }

    $student_total = 0;
    $total_displayed_subjects = count($displayed_subjects);

    foreach ($displayed_subjects as $subj) {
        $subj_name_for_avg = $subject_names_cache[$subj] ?? $subj;
        $subj_is_compulsory = $compulsory_cache[$subj] ?? false;
        $subj_is_registered = isRegisteredOptionalSubject($student_registered_subjects, $sid, $subj_name_for_avg);

        $aoi_marks_for_subject = [];

        if (isset($all_students_marks[$sid]['subjects'][$subj])) {
            foreach ($all_students_marks[$sid]['subjects'][$subj] as $topic => $mark) {
                if ($topic !== 'EOT' && $mark !== null && $mark !== '' && $mark !== '-') {
                    $aoi_marks_for_subject[] = floatval($mark);
                }
            }
        }

        $subj_has_marks = !empty($aoi_marks_for_subject);
        $aoi_marks_for_subject = applyDefaultMarkIfMissing(
            $aoi_marks_for_subject,
            shouldDisplaySubject($subj_is_compulsory, $subj_has_marks, $subj_is_registered),
            $subj_has_marks
        );

        // Calculate AOI average - divide by TOTAL columns for fairness
        if (!empty($aoi_marks_for_subject)) {
            $aoi_avg = array_sum($aoi_marks_for_subject) / $aoi_columns;
            $student_total += $aoi_avg;
        }
    }

    // Calculate overall average (on 3-point scale)
    $average = $total_displayed_subjects > 0 ? $student_total / $total_displayed_subjects : 0;
    $all_student_averages[$sid] = $average;
}

// Sort students by average score - DESCENDING (highest first)
arsort($all_student_averages, SORT_NUMERIC);

// Debug: Verify sorting is correct
error_log("=== STUDENT RANKING (Highest to Lowest) ===");
$rank = 1;
foreach ($all_student_averages as $sid => $avg) {
    error_log("Rank {$rank}: Student ID: {$sid} | Average: " . number_format($avg, 3));
    if ($rank >= 10) break;
    $rank++;
}

// Create position map if position display is enabled
if ($display_student_position) {
    $pos = 1;
    $previous_avg = null;
    $actual_position = 1;

    foreach ($all_student_averages as $sid => $avg) {
        // Handle ties: if same average as previous student, give same position
        if ($previous_avg !== null && abs($avg - $previous_avg) > 0.001) {
            $pos = $actual_position;
        }

        $position_map[$sid] = $pos;
        $previous_avg = $avg;
        $actual_position++;
    }
}

// Create sorted student IDs array (already in correct order - highest to lowest)
$sorted_student_ids = array_keys($all_student_averages);


// Process each student for assessment report (now in CORRECT sorted order - highest first)
$current_student = 0;
foreach ($sorted_student_ids as $student_id_from_loop) {
    $current_student++;

    $student = getStudentDetails($conn, $student_id_from_loop);
    if (!$student) continue;

    if (!isset($all_students_marks[$student_id_from_loop])) continue;

    $all_marks = $all_students_marks[$student_id_from_loop]['subjects'] ?? [];

    // Calculate position text (only if position display is enabled)
    $position_text = null;
    if ($display_student_position) {
        $position = $position_map[$student_id_from_loop] ?? $current_student;
        $position_text = $position . " out of " . $total_students;
    }

    // Generate report for this student
generateAssessmentReport($student, $all_marks, $selected_topics, $subject_names_cache, $compulsory_cache, $teacher_initials_cache, $conn, $position_text, $term, $year, $class, $all_students_marks[$student_id_from_loop]['stream'] ?? '', $aoi_columns, $display_percentage, $display_student_position, $school_details, $student_registered_subjects[$student_id_from_loop] ?? []);
    // Update progress indicator (legacy widget + new loader overlay)
    $progress_percent = round(($current_student / $total_students) * 100);
    echo "<script>
        document.getElementById('current-count').textContent = {$current_student};
        document.getElementById('progress-bar').style.width = '{$progress_percent}%';
        document.getElementById('loader-current-count').textContent = {$current_student};
        document.getElementById('loader-progress-percent').textContent = {$progress_percent};
        document.getElementById('loader-progress-fill').style.width = '{$progress_percent}%';
    </script>";

    flush();
    ob_flush();
}

// Function to generate assessment report
function generateAssessmentReport($student, $all_marks, $selected_topics, $subject_names_cache, $compulsory_cache, &$teacher_initials_cache, $conn, $position_text, $term, $year, $class, $stream, $aoi_columns, $display_percentage, $display_student_position, $school_details, $registered_subjects_for_student)
{
    $student_name = $student ? htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) : '';

    // Get the internal numerical student ID (from the database query)
    $internal_student_id = $student ? htmlspecialchars($student['student_id']) : '';

    // Get the display student_id (the alphanumeric one)
    $display_student_id_string = $student ? htmlspecialchars($student['student_id']) : '';

    $student_stream = !empty($stream) ? htmlspecialchars($stream) : '';

    echo "
    <div class='report-card'> 
        <div class='row header'>
    <div class='col-2'>
        <img src='/" . htmlspecialchars($school_details['logo_path'] ?? '../assets/img/logo.jpg') . "' alt='School Logo' class='school-logo' loading='lazy'>
    </div>
    <div class='col-8 school-info'>
        <div class='school-name'>" . htmlspecialchars($school_details['school_name'] ?? 'School Name Not Set') . "</div>
        <div>" . htmlspecialchars($school_details['pobox'] ?? '') . " " . htmlspecialchars($school_details['address'] ?? 'Address Not Set') . "</div>
        <div>TEL: " . htmlspecialchars($school_details['phone'] ?? 'Phone Not Set') . ", EMAIL: " . htmlspecialchars($school_details['email'] ?? 'Email Not Set') . "</div>
        <div class='mt-2'>
            <strong>MID TERM REPORT CARD FOR " . htmlspecialchars($term) . ", YEAR: " . htmlspecialchars($year) . "</strong>
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
                <strong>Class:</strong> " . htmlspecialchars($class) . " (" . $student_stream . ")
            </div>
            
        </div>

        <div class='results-table-container'>
           
            
            <table class='results-table' id='results-table-{$internal_student_id}'> <thead>
                    <tr>
                        <th rowspan='2' class='subject-cell'>SUBJECT</th>";

    // Generate dynamic AOI column headers
    for ($i = 1; $i <= $aoi_columns; $i++) {
        echo "<th class='aoi-column'>AOI {$i}</th>";
    }

    echo "
                        <th rowspan='2' class='aoi-column'>AVG</th>
                        <th rowspan='2' class='aoi-column calculated'>Out of 20</th>";

    // Conditionally add percentage column
    if ($display_percentage) {
        echo "<th rowspan='2' class='percentage-column calculated'>100 (%)</th>";
    }

    echo "
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
                    $aoi_marks[] = $mark;
                }
            }
        }

        // REORDER AOI marks to remove gaps - put valid marks first
        $reordered_aoi_marks = [];

        foreach ($aoi_marks as $mark) {
            if ($mark !== null && $mark !== '' && $mark !== '-') {
                $reordered_aoi_marks[] = $mark;
            }
        }

        $raw_has_marks = subjectHasMarks($reordered_aoi_marks, $eot_mark);
        $is_registered_optional = isRegisteredOptionalSubject($registered_subjects_for_student, $subject_name, $subject_name);
        // Correct lookup signature: registered_subjects_for_student is already this student's map
        $is_registered_optional = isset($registered_subjects_for_student[$subject_name]);
        $will_display = shouldDisplaySubject($is_compulsory, $raw_has_marks, $is_registered_optional);

        if (!$will_display) {
            continue;
        }

        // Apply display-only 0.9 default BEFORE padding with nulls, so it shows in AOI 1
        $reordered_aoi_marks = applyDefaultMarkIfMissing($reordered_aoi_marks, $will_display, $raw_has_marks);

        while (count($reordered_aoi_marks) < $aoi_columns) {
            $reordered_aoi_marks[] = null;
        }

        $aoi_marks = $reordered_aoi_marks;
        $has_marks = $raw_has_marks;

        $initials = getTeacherInitials($conn, $subject_name, $class, $student_stream, $teacher_initials_cache);

        echo "<tr data-row='{$row_index}' data-student='{$internal_student_id}'>"; // Use internal_student_id
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

        // Conditionally add percentage column (calculated by JS)
        if ($display_percentage) {
            echo "<td class='percentage-column calculated' data-percentage='true'>-</td>";
        }

        // Grade column (calculated by JS)
        echo "<td class='grade-column calculated' data-grade='true'>-</td>";

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
    $student_total_for_comments = 0;
    $subject_count_for_comments = 0;

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
        $is_registered_optional = isset($registered_subjects_for_student[$subject_name]);
        $will_display = shouldDisplaySubject($is_compulsory, $has_marks, $is_registered_optional);

        if (!$will_display) {
            continue;
        }

        $aoi_marks_check = applyDefaultMarkIfMissing($aoi_marks_check, $will_display, $has_marks);

        if (!empty($aoi_marks_check)) {
            $aoi_sum = array_sum($aoi_marks_check);
            $aoi_avg = $aoi_sum / $aoi_columns;
            $student_total_for_comments += $aoi_avg;
            $subject_count_for_comments++;
        }
    }

    $average_for_comments = $subject_count_for_comments > 0 ? $student_total_for_comments / $subject_count_for_comments : 0;
    $final_grade_for_comments = convertToGrade($average_for_comments);

    // Generate unique comments for this student
    $class_teacher_comment = getTeacherComment($student_name, $final_grade_for_comments, 'class_teacher');
    $head_teacher_comment = getTeacherComment($student_name, $final_grade_for_comments, 'head_teacher');

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

    echo "
    
    <table class='grades-table'>
        <tr>
            <th style='width: 20%;'>GRADE</th>
            <th style='width: 16%;'>E</th>
            <th style='width: 16%;'>D</th>
            <th style='width: 16%;'>C</th>
            <th style='width: 16%;'>B</th>
            <th style='width: 16%;'>A</th>
        </tr>
        <tr>
            <td style='font-weight: bold;'>RANGE</td>
            <td>0.9 - 1.4</td>
            <td>1.5 - 1.7</td>
            <td>1.8 - 2.3</td>
            <td>2.4 - 2.6</td>
            <td>2.7 - 3.0</td>
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
            const streamName = " . json_encode($student_stream) . ";
            
            // Get the base URL dynamically
            const protocol = window.location.protocol;
            const host = window.location.host;
            const pathArray = window.location.pathname.split('/');
            pathArray.pop(); // Remove current file
            const basePath = pathArray.join('/');
            
            // Construct dynamic verification URL
            const verificationUrl = protocol + '//' + host + basePath + '/verify_assessment_report.php?student_id=' + 
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
                    correctLevel: QRCode.CorrectLevel.M,
                    colorDark: '#000000',  // Black QR code
                    colorLight: '#ffffff'  // White background
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
            
            <table class='aoi-table' id='aoi-table-{$internal_student_id}' style='margin-top: 10px; width: 100%;'> <thead>
                <tr>
                    <th style='width: 10%; text-align: center;'></th>
                    <th style='width: 15%; text-align: center;'>SUBJECT</th>
                    <th style='width: 15%; text-align: center;'>AOI</th>
                    <th style='width: 25%; text-align: center;'>ACTIVITIES OF INTEGRATION</th>
                    <th style='width: 35%; text-align: center;'>COMPETENCY</th>
                </tr>
            </thead>
            <tbody>";

    $subject_counter = 1;
    foreach ($selected_topics as $subject => $topics) {
        $subject_name = $subject_names_cache[$subject] ?? $subject;
        $is_compulsory = $compulsory_cache[$subject] ?? false;

        // Only display subjects that have marks or are compulsory (same logic as front side)
        $aoi_marks_for_subject = [];
        $eot_mark = null;

        if (isset($all_marks[$subject])) {
            foreach ($topics as $topic_id) {
                $mark = $all_marks[$subject][$topic_id] ?? null;
                if ($topic_id === 'EOT') {
                    $eot_mark = $mark;
                } else {
                    $aoi_marks_for_subject[] = $mark;
                }
            }
        }

        $has_marks = subjectHasMarks($aoi_marks_for_subject, $eot_mark);

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
                    mysqli_stmt_close($aoi_stmt);
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

        $aoi_marks_teacher = [];
        $eot_mark_teacher = null;

        if (isset($all_marks[$subject])) {
            foreach ($topics as $topic_id) {
                $mark = $all_marks[$subject][$topic_id] ?? null;
                if ($topic_id === 'EOT') {
                    $eot_mark_teacher = $mark;
                } else {
                    if ($mark !== null && $mark !== '' && $mark !== '-') {
                        $aoi_marks_teacher[] = $mark;
                    }
                }
            }
        }

        $has_marks = subjectHasMarks($aoi_marks_teacher, $eot_mark_teacher);
        if (!$is_compulsory && !$has_marks) {
            continue;
        }

        // Get teacher details for this subject
        $initials = getTeacherInitials($conn, $subject_name, $class, $student_stream, $teacher_initials_cache);

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
                mysqli_stmt_bind_param($teacher_stmt, "sss", $subject_name, $class, $student_stream);
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

    // Add JavaScript for this specific student - MODIFIED FOR ASSESSMENT REPORT
    echo "
<script>
    (function() {
        const studentId = " . json_encode($internal_student_id) . ";
        const aoiColumns = " . json_encode((int)$aoi_columns) . ";
        const displayPercentage = " . ($display_percentage ? 'true' : 'false') . ";
        
        function calculateGrade(marks) {
            if (marks === null || marks === '' || isNaN(marks)) {
                return '-';
            }
            
            marks = parseFloat(marks);
            
            // Assessment report 5-grade scale
            if (marks >= 2.7) return 'A';
            if (marks >= 2.4) return 'B';
            if (marks >= 1.8) return 'C';
            if (marks >= 1.5) return 'D';
            if (marks >= 0.9) return 'E';
            return '-';
        }
        
        function getAchievement(grade) {
            const achievementMap = {
                'A': 'A',
                'B': 'B',
                'C': 'C',
                'D': 'D',
                'E': 'E',
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
                // Get AOI marks for this row
                const aoiCells = [];
                for (let i = 0; i < aoiColumns; i++) {
                    const cell = row.querySelector('td[data-aoi=\"' + i + '\"]');
                    if (cell) {
                        const value = cell.textContent.trim();
                        aoiCells.push(value === '-' ? null : parseFloat(value));
                    }
                }
                
                // FIXED: Calculate AOI average using ACTUAL count of valid marks
                const validAoi = aoiCells.filter(val => val !== null && !isNaN(val));
                const aoiSum = validAoi.length > 0 ? validAoi.reduce((sum, val) => sum + val, 0) : null;
                
                // KEY FIX: Divide by validAoi.length (actual marks) NOT aoiColumns (total columns)
              const aoiAvg = aoiSum !== null && validAoi.length > 0 ? aoiSum / aoiColumns : null;

                
                // Update AOI average display
                const avgCell = row.querySelector('td[data-avg]');
                if (avgCell) {
                    avgCell.textContent = aoiAvg !== null ? aoiAvg.toFixed(1) : '-';
                }
                
                // Calculate out of 20 score by converting AOI average
                let outOf20 = null;
                if (aoiAvg !== null) {
                    outOf20 = (aoiAvg / 3) * 20;
                }
                
                // Update out of 20 display
                const outOf20Cell = row.querySelector('td[data-out-of-20]');
                if (outOf20Cell) {
                    outOf20Cell.textContent = outOf20 !== null ? Math.round(outOf20) : '-';
                }
                
                // Calculate percentage if enabled
                if (displayPercentage) {
                    const percentageCell = row.querySelector('td[data-percentage]');
                    if (percentageCell && outOf20 !== null) {
                        const percentage = (outOf20 / 20) * 100;
                        percentageCell.textContent = Math.round(percentage);
                    } else if (percentageCell) {
                        percentageCell.textContent = '-';
                    }
                }
                
                // Calculate identifier based on AOI average (out of 3 score)
                const gradeCell = row.querySelector('td[data-grade]');
                if (gradeCell) {
                    gradeCell.textContent = calculateGrade(aoiAvg);
                }
                
                // Add to student totals (use aoiAvg for overall average calculation)
                if (aoiAvg !== null) {
                    totalSum += aoiAvg;
                    subjectCount++;
                }
            });
            
            // Calculate overall average (out of 3)
            const overallAvg = subjectCount > 0 ? totalSum / subjectCount : 0;
            const finalIdentifier = calculateGrade(overallAvg);
            const achievement = getAchievement(finalIdentifier);
            
            // Update summary
            const avgSpan = document.getElementById('overall-avg-' + studentId);
            const gradeSpan = document.getElementById('final-grade-' + studentId);
            const achievementSpan = document.getElementById('achievement-' + studentId);
            
            if (avgSpan) avgSpan.textContent = overallAvg.toFixed(1);
            if (gradeSpan) gradeSpan.textContent = finalIdentifier;
            if (achievementSpan) achievementSpan.textContent = achievement;
        }
        
        // Calculate immediately
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
        (function() {
            let hasRevealed = false;
            let safetyTimer = null;

            function revealReports() {
                if (hasRevealed) return;
                hasRevealed = true;

                if (safetyTimer) {
                    clearTimeout(safetyTimer);
                    safetyTimer = null;
                }

                document.body.classList.add('reports-ready');

                const overlay = document.getElementById('report-loader-overlay');
                if (overlay) {
                    overlay.classList.add('loader-hidden');
                    setTimeout(() => overlay.remove(), 600);
                }

                const statusWidget = document.querySelector('.processing-status');
                if (statusWidget) statusWidget.style.display = 'none';

                console.log('All " . count($student_ids) . " assessment reports generated in {$execution_time} seconds');

                // Final calculation sweep for all tables (in case any missed)
                setTimeout(function() {
                    const allTables = document.querySelectorAll('[id^=\"results-table-\"]');
                    allTables.forEach(table => {
                        const event = new Event('recalculate');
                        table.dispatchEvent(event);
                    });
                }, 500);
            }

            // Wait for every image on the page — school logo, student photos,
            // and the QR code images generated by QRCode.js — to finish loading
            const images = Array.from(document.images);
            const pending = images.filter(img => !img.complete);

            if (pending.length === 0) {
                requestAnimationFrame(revealReports);
            } else {
                let remaining = pending.length;
                function onImageSettled() {
                    remaining--;
                    if (remaining <= 0) {
                        requestAnimationFrame(revealReports);
                    }
                }

                pending.forEach(img => {
                    img.addEventListener('load', onImageSettled, { once: true });
                    img.addEventListener('error', onImageSettled, { once: true });
                });
            }

            // Safety net: never block the report view for more than 8 seconds,
            // even if one image fails or hangs. Cleared automatically once
            // revealReports() runs via the normal image-load path.
            safetyTimer = setTimeout(revealReports, 8000);
        })();
    </script>
</body>
</html>";

// Close database connection
mysqli_close($conn);
