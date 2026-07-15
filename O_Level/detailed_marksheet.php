<?php

require_once '../auth.php';
require_once '../conn.php';

// Check if marksheet data exists in session
if (!isset($_SESSION['marksheet_data'])) {
    // Redirect back to the form if no data is available
    header("Location: marksheet.php");
    exit;
}

// Get form data from session
$data = $_SESSION['marksheet_data'];
$class = $data['class'];
$term = $data['term'];
$year = $data['year'];
$streams = $data['streams'];
$subjects = $data['subjects'];
$marksheet_type = $data['marksheet_type'];

// Function to get term in roman numerals
function termToRoman($term)
{
    $term_number = filter_var($term, FILTER_SANITIZE_NUMBER_INT);
    $romans = ['i', 'ii', 'iii']; // Corrected to lowercase
    return $romans[$term_number - 1] ?? 'i';
}

// Function to determine the level (Olevel or Alevel)
function getLevel($class)
{
    if (
        stripos($class, 'senior one') !== false ||
        stripos($class, 'senior two') !== false ||
        stripos($class, 'senior three') !== false ||
        stripos($class, 'senior four') !== false
    ) {
        return 'olevel'; // Corrected to lowercase
    } else {
        return 'alevel'; // Also correct this for future consistency
    }
}

// Generate table name based on year, term and level
$term_roman = termToRoman($term);
$level = getLevel($class);
$results_table = "{$year}_{$term_roman}_{$level}";

// Function to get students for the selected class and streams
function getStudents($conn, $class, $streams)
{
    if (empty($streams) || !is_array($streams)) {
        return array();
    }

    $placeholders = str_repeat('?,', count($streams) - 1) . '?';
    // MODIFIED SQL QUERY:
    // 1. Changed 'stream' to 'stream'
    // 2. Explicitly selected 'student_id' and 'students_id' to ensure they are available
    $sql = "SELECT student_id, first_name, last_name, stream, student_id FROM students WHERE current_class = ? AND stream IN ($placeholders) ORDER BY stream, last_name, first_name";

    $stmt = mysqli_prepare($conn, $sql);

    // IMPORTANT: Add error checking for mysqli_prepare
    if (!$stmt) {
        error_log("Failed to prepare statement in getStudents (detailed_marksheet): " . mysqli_error($conn));
        return array(); // Return empty array to prevent further TypeErrors
    }
    // END ERROR CHECKING

    $types = 's' . str_repeat('s', count($streams));
    $bind_params = array($types, $class);

    foreach ($streams as $stream) {
        $bind_params[] = $stream;
    }

    $refs = array();
    foreach ($bind_params as $key => $value) {
        $refs[$key] = &$bind_params[$key];
    }
    // The first element of $bind_params is the types string, which doesn't need to be a reference
    // The subsequent elements are the actual values, which must be references.
    $params = array($types);
    foreach ($bind_params as $key => $value) {
        if ($key > 0) { // Skip the types string
            $params[] = &$bind_params[$key];
        }
    }
    call_user_func_array(array($stmt, 'bind_param'), $params);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $students = array();
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            // REMOVED: $row['student_id'] = $row['id'];
            // 'student_id' is already fetched directly by the SELECT statement
            $students[] = $row;
        }
    }
    mysqli_stmt_close($stmt); // Good practice: close the statement
    return $students;
}

// Function to get AOI topics for the selected subjects
function getAOITopicsBySubject($conn, $subjects)
{
    if (empty($subjects) || !is_array($subjects)) {
        return array();
    }

    $placeholders = str_repeat('?,', count($subjects) - 1) . '?';
    $sql = "SELECT * FROM aoi WHERE subject IN ($placeholders) ORDER BY subject";

    $stmt = mysqli_prepare($conn, $sql);

    $types = str_repeat('s', count($subjects));
    $bind_params = array($types);

    foreach ($subjects as $subject) {
        $bind_params[] = $subject;
    }

    $refs = array();
    foreach ($bind_params as $key => $value) {
        $refs[$key] = &$bind_params[$key];
    }

    call_user_func_array(array($stmt, 'bind_param'), $refs);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $topics = array();
    $has_eot_topic = array(); // Track which subjects have EOT topic

    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            if (!isset($topics[$row['subject']])) {
                $topics[$row['subject']] = array();
                $has_eot_topic[$row['subject']] = false;
            }

            // Check if this is an EOT topic
            if (strtolower($row['topic']) === 'eot') {
                $has_eot_topic[$row['subject']] = true;
            } else {
                // Only add non-EOT topics to the regular topics array
                $topics[$row['subject']][] = $row;
            }
        }
    }

    return array(
        'topics' => $topics,
        'has_eot_topic' => $has_eot_topic
    );
}

// Function to get marks for all students
function getStudentMarks($conn, $table_name, $students, $subjects)
{
    if (empty($students) || empty($subjects)) {
        return array();
    }

    $student_ids = array_column($students, 'student_id');

    $student_placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
    $subject_placeholders = str_repeat('?,', count($subjects) - 1) . '?';

    $sql = "SELECT student_id, subject, topic_id, marks FROM `$table_name` 
            WHERE student_id IN ($student_placeholders) 
            AND subject IN ($subject_placeholders)";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        // Handle query preparation error
        return array();
    }

    $types = str_repeat('i', count($student_ids)) . str_repeat('s', count($subjects));
    $bind_params = array($types);

    foreach ($student_ids as $id) {
        $bind_params[] = $id;
    }

    foreach ($subjects as $subject) {
        $bind_params[] = $subject;
    }

    $refs = array();
    foreach ($bind_params as $key => $value) {
        $refs[$key] = &$bind_params[$key];
    }

    call_user_func_array(array($stmt, 'bind_param'), $refs);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $marks = array();
    $eot_marks = array(); // New array to hold EOT marks

    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            // Check if this is an EOT mark
            if ($row['topic_id'] === 'EOT') {
                // Store EOT marks separately
                if (!isset($eot_marks[$row['student_id']])) {
                    $eot_marks[$row['student_id']] = array();
                }
                $eot_marks[$row['student_id']][$row['subject']] = $row['marks'];
            } else {
                // Store regular marks
                if (!isset($marks[$row['student_id']])) {
                    $marks[$row['student_id']] = array();
                }
                if (!isset($marks[$row['student_id']][$row['subject']])) {
                    $marks[$row['student_id']][$row['subject']] = array();
                }
                $marks[$row['student_id']][$row['subject']][$row['topic_id']] = $row['marks'];
            }
        }
    }

    return array(
        'regular_marks' => $marks,
        'eot_marks' => $eot_marks
    );
}

// Function to calculate average marks - CORRECTED VERSION
function calculateAverageMarks($student_marks)
{
    $averages = array();

    foreach ($student_marks as $student_id => $subjects) {
        $averages[$student_id] = array();

        foreach ($subjects as $subject => $topics) {
            // Filter out empty or null marks
            $valid_marks = array_filter($topics, function ($mark) {
                return $mark !== null && $mark !== '' && is_numeric($mark);
            });

            // Only calculate average if there are valid marks
            if (count($valid_marks) > 0) {
                $averages[$student_id][$subject] = array_sum($valid_marks) / count($valid_marks);
            } else {
                $averages[$student_id][$subject] = 0;
            }
        }
    }

    return $averages;
}

// Function to calculate positions based on total/average marks
function calculatePositions($students, $average_marks, $subjects)
{
    if (empty($students) || empty($average_marks) || empty($subjects)) {
        return array(
            'positions' => array(),
            'totals' => array(),
            'averages' => array()
        );
    }

    $student_totals = array();
    $student_averages = array();
    $student_subject_counts = array();

    // Calculate total and average across selected subjects for each student
    foreach ($students as $student) {
        $student_id = $student['student_id'];
        if (!isset($average_marks[$student_id])) {
            continue;
        }

        $total = 0;
        $subject_count = 0;

        // Only include selected subjects in the calculation
        foreach ($subjects as $subject) {
            if (isset($average_marks[$student_id][$subject]) && $average_marks[$student_id][$subject] > 0) {
                $total += $average_marks[$student_id][$subject];
                $subject_count++;
            }
        }

        $student_totals[$student_id] = $total;
        $student_subject_counts[$student_id] = $subject_count;
        $student_averages[$student_id] = ($subject_count > 0) ? ($total / $subject_count) : 0;
    }

    // Create array for sorting with student_id as key
    $sorting_data = array();
    foreach ($student_averages as $student_id => $average) {
        $sorting_data[] = array(
            'student_id' => $student_id,
            'average' => $average
        );
    }

    // Sort by average in descending order
    usort($sorting_data, function ($a, $b) {
        if ($a['average'] == $b['average']) {
            return 0;
        }
        return ($a['average'] > $b['average']) ? -1 : 1;
    });

    // Assign positions based on sorted averages
    $positions = array();
    $current_position = 1;
    $previous_average = null;
    $skipped_positions = 0;

    foreach ($sorting_data as $idx => $data) {
        $student_id = $data['student_id'];
        $average = $data['average'];

        if ($previous_average !== null && $average < $previous_average) {
            $current_position += $skipped_positions + 1;
            $skipped_positions = 0;
        } elseif ($previous_average !== null && $average == $previous_average) {
            $skipped_positions++;
        }

        $positions[$student_id] = $current_position;
        $previous_average = $average;
    }

    return array(
        'positions' => $positions,
        'totals' => $student_totals,
        'averages' => $student_averages,
        'subject_counts' => $student_subject_counts
    );
}

// Get abbreviated subject names for column headers
function getSubjectAbbreviation($subject)
{
    // Custom abbreviations for common subjects
    $common_subjects = [
        'mathematics' => 'MTC',
        'physics' => 'PHY',
        'chemistry' => 'CHE',
        'biology' => 'BIO',
        'english' => 'ENG',
        'geography' => 'GEO',
        'history' => 'HIST',
        'computer' => 'COMP',
        'literature' => 'LIT',
        'agriculture' => 'AGR',
        'economics' => 'ECO',
        'kiswahili' => 'KIS',
        'french' => 'FRE',
        'german' => 'GER',
        'islamic religious education' => 'IRE',
        'christian religious education' => 'CRE',
        'art and design' => 'ART',
        'home science' => 'H/SCI',
        'music' => 'MUS'
    ];

    // First try to match with predefined abbreviations
    $subject_lower = strtolower($subject);
    foreach ($common_subjects as $key => $abbr) {
        if (strpos($subject_lower, $key) !== false) {
            return $abbr;
        }
    }

    // If no match found, create abbreviation from first 4 letters
    return strtoupper(substr($subject, 0, 4));
}

// Function to determine maximum topic count per subject
function getMaxTopicCount($all_topics)
{
    $max_topics = 0;

    foreach ($all_topics as $subject => $topics) {
        $topic_count = count($topics);
        if ($topic_count > $max_topics) {
            $max_topics = $topic_count;
        }
    }

    return $max_topics;
}

// Get school information for the header
function getSchoolInfo($conn)
{
    $sql = "SELECT * FROM school_profile LIMIT 1";
    $result = mysqli_query($conn, $sql);

    if ($result && mysqli_num_rows($result) > 0) {
        return mysqli_fetch_assoc($result);
    }

    return array(
        'school_name' => 'School Name', // This is also in your school_details table
        'local' => 'School Address', // Matches your school_details table
        'phone' => 'School Phone',   // Matches your school_details table
        'email' => 'School Email'         // Matches your school_details table
    );
}

// Check for EOT marks in the main results table
function checkForEOTMarks($conn, $table_name, $subjects)
{
    $subjects_with_eot = array();

    foreach ($subjects as $subject) {
        $sql = "SELECT DISTINCT subject FROM `$table_name` WHERE topic_id = 'EOT' AND subject = ?";
        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            continue;
        }

        mysqli_stmt_bind_param($stmt, "s", $subject);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($result && mysqli_num_rows($result) > 0) {
            $subjects_with_eot[$subject] = true;
        } else {
            $subjects_with_eot[$subject] = false;
        }
    }

    return $subjects_with_eot;
}

// Get all data
$students = getStudents($conn, $class, $streams);
$topics_result = getAOITopicsBySubject($conn, $subjects);
$all_topics = $topics_result['topics'];
$has_eot_topic = $topics_result['has_eot_topic'];

// Check for EOT marks in the results table
$subjects_with_eot = checkForEOTMarks($conn, $results_table, $subjects);

// Merge the AOI topics with the subjects that have EOT marks
foreach ($subjects_with_eot as $subject => $has_eot) {
    if ($has_eot) {
        $has_eot_topic[$subject] = true;
    }
}

// Get all marks from the results table (both regular and EOT)
$marks_result = getStudentMarks($conn, $results_table, $students, $subjects);
$student_marks = $marks_result['regular_marks'];
$eot_marks = $marks_result['eot_marks'];

$average_marks = calculateAverageMarks($student_marks);

// Calculate positions
$position_data = calculatePositions($students, $average_marks, $subjects);
$positions = $position_data['positions'];
$student_totals = $position_data['totals'];
$student_averages = $position_data['averages'];

$school_info = getSchoolInfo($conn);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detailed Marksheet — <?= htmlspecialchars($class) ?> · <?= htmlspecialchars($term) ?> · <?= htmlspecialchars($year) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/fonts/fontawesome-all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <style>
        :root {
            --g9:#1a4731;--g7:#1e8449;--g5:#27ae60;
            --g3:#2ecc71;--g1:#e8f5ee;--g0:#f2faf6;
            --red:#e53935;--amber:#f59e0b;
            --gr8:#1e293b;--gr6:#475569;--gr4:#94a3b8;
            --gr2:#e2e8f0;--gr1:#f1f5f9;--wh:#fff;
            --shadow:0 4px 18px rgba(0,0,0,.10);
            --r:8px;--trans:all .2s ease;
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Sen',sans-serif;background:var(--gr1);color:var(--gr8);font-size:13px;padding-bottom:40px;}

        /* ── Toolbar ── */
        .toolbar{background:var(--g9);color:#fff;padding:12px 20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;position:sticky;top:0;z-index:100;}
        .toolbar-title{font-weight:700;font-size:14px;flex:1;}
        .btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border:none;border-radius:6px;font-family:'Sen',sans-serif;font-size:13px;font-weight:700;cursor:pointer;transition:var(--trans);text-decoration:none;}
        .btn-excel{background:#217346;color:#fff;}.btn-excel:hover{background:#1a5c37;}
        .btn-pdf{background:var(--red);color:#fff;}.btn-pdf:hover{background:#c62828;}
        .btn-back{background:rgba(255,255,255,.15);color:#fff;}.btn-back:hover{background:rgba(255,255,255,.25);}

        /* ── Page ── */
        .page{max-width:100%;margin:0 auto;padding:18px 14px;}

        /* ── Stats strip ── */
        .stats-strip{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
        .stat-card{background:var(--wh);border-radius:var(--r);padding:10px 18px;flex:1;min-width:110px;box-shadow:0 1px 4px rgba(0,0,0,.06);display:flex;flex-direction:column;align-items:center;}
        .stat-val{font-size:22px;font-weight:800;color:var(--g7);}
        .stat-lbl{font-size:11px;color:var(--gr6);margin-top:2px;font-weight:600;}

        /* ── Marksheet container ── */
        .ms-wrap{background:var(--wh);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden;}

        /* ── School header ── */
        .ms-header{background:linear-gradient(135deg,var(--g9),var(--g7));color:#fff;padding:18px 22px 14px;display:flex;align-items:center;gap:20px;}
        .ms-header img{height:70px;width:auto;border-radius:6px;background:#fff;padding:4px;}
        .ms-school-name{font-size:20px;font-weight:800;letter-spacing:.5px;}
        .ms-school-sub{font-size:12px;opacity:.85;margin-top:2px;}
        .ms-header-right{margin-left:auto;text-align:right;font-size:12px;opacity:.85;}

        /* ── Title bar ── */
        .ms-title-bar{background:var(--red);color:#fff;text-align:center;padding:9px 16px;font-size:14px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;}

        /* ── Key strip ── */
        .grade-key{background:var(--g0);padding:7px 16px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;font-size:11px;border-bottom:1px solid var(--gr2);}
        .grade-key-label{font-weight:700;color:var(--g9);margin-right:4px;}
        .gk-badge{display:inline-flex;align-items:center;gap:4px;background:var(--wh);border:1px solid var(--gr2);border-radius:4px;padding:3px 8px;font-weight:700;}

        /* ── Table ── */
        .tbl-scroll{overflow-x:auto;}
        table.ms-table{width:100%;border-collapse:collapse;font-size:11.5px;min-width:600px;}
        .ms-table th,.ms-table td{border:1px solid var(--gr2);padding:7px 6px;text-align:center;vertical-align:middle;white-space:nowrap;}
        .ms-table thead tr:first-child th{background:var(--g7);color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;position:sticky;top:0;z-index:5;}
        .ms-table thead tr:nth-child(2) th{background:var(--g9);color:#fff;font-size:10px;font-weight:600;}
        .ms-table tbody tr:nth-child(even){background:var(--g0);}
        .ms-table tbody tr:hover{background:var(--g1);}
        .col-name{text-align:left!important;padding-left:10px!important;white-space:normal!important;font-weight:600;}
        .col-no{font-weight:700;color:var(--gr6);}
        .subj-hdr{background:var(--g7)!important;}
        .student-name-cell{text-align:left!important;padding-left:10px!important;font-weight:600;position:sticky;left:40px;background-color:inherit;z-index:2;}
        .stream-cell{font-weight:500;position:sticky;left:220px;background-color:inherit;z-index:2;}
        .mark-cell{background:var(--g1)!important;font-weight:700;color:var(--g9);}
        .mark-cell.empty{background:var(--gr1)!important;color:var(--gr4);}
        .avg-cell{background:#fff8e1!important;font-weight:800;color:#e65100;}
        .eot-cell{background:#fff3e0!important;font-weight:700;color:#bf360c;}
        .average-cell{background:#fff8e1!important;font-weight:800;color:#e65100;}
        .position-cell{background:#e8eaf6!important;font-weight:800;color:#283593;}
        .no-data{text-align:center;padding:48px;color:var(--gr4);font-size:15px;}

        /* ── Footer ── */
        .ms-footer{padding:10px 16px;font-size:11px;color:var(--gr6);border-top:1px solid var(--gr2);display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;}

        /* ── Print ── */
        @media print {
            body{background:#fff;font-size:9px;padding:0;}
            .toolbar,.stats-strip,.no-print{display:none!important;}
            .page{padding:0;margin:0;}
            .ms-wrap{box-shadow:none;border-radius:0;}
            .ms-header,.ms-title-bar{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
            .ms-table thead tr:first-child th,.ms-table thead tr:nth-child(2) th{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
            .ms-table tbody tr:nth-child(even){-webkit-print-color-adjust:exact;print-color-adjust:exact;}
            .mark-cell,.avg-cell,.eot-cell,.average-cell,.position-cell{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
            @page{size:landscape;margin:8mm;}
            .ms-table{font-size:8px!important;}
            .ms-table th,.ms-table td{padding:4px 3px!important;}
            .ms-header{padding:10px 14px;}
            .ms-school-name{font-size:15px;}
        }

        /* ── Skeleton ── */
        @keyframes skeleton-shimmer{0%{background-position:-600px 0}100%{background-position:600px 0}}
        .skeleton{background:linear-gradient(90deg,#e2e8f0 25%,#f1f5f9 50%,#e2e8f0 75%);background-size:600px 100%;animation:skeleton-shimmer 1.4s ease-in-out infinite;border-radius:5px;}
        #skeleton-overlay{position:fixed;inset:0;background:var(--gr1);z-index:999;overflow-y:auto;padding-bottom:40px;}
        #skeleton-overlay.fade-out{animation:sk-fade .35s ease forwards;}
        @keyframes sk-fade{to{opacity:0;pointer-events:none}}
        .sk-toolbar{background:var(--g9);padding:12px 20px;display:flex;align-items:center;gap:12px;}
        .sk-toolbar-title{height:16px;width:220px;border-radius:4px;background:rgba(255,255,255,.22);animation:none;}
        .sk-btn{height:34px;border-radius:6px;background:rgba(255,255,255,.18);animation:none;}
        .sk-stats{display:flex;gap:10px;flex-wrap:wrap;padding:18px 14px 0;}
        .sk-stat-card{flex:1;min-width:110px;background:var(--wh);border-radius:var(--r);padding:10px 18px;box-shadow:0 1px 4px rgba(0,0,0,.06);display:flex;flex-direction:column;align-items:center;gap:6px;}
        .sk-stat-val{height:28px;width:52px;}.sk-stat-lbl{height:11px;width:64px;}
        .sk-ms-wrap{background:var(--wh);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden;margin:14px 14px 0;}
        .sk-ms-header{background:linear-gradient(135deg,var(--g9),var(--g7));padding:18px 22px 14px;display:flex;align-items:center;gap:20px;}
        .sk-logo{width:70px;height:70px;border-radius:6px;background:rgba(255,255,255,.25);animation:none;}
        .sk-ms-header-body{display:flex;flex-direction:column;gap:8px;flex:1;}
        .sk-school-name{height:20px;width:240px;background:rgba(255,255,255,.30);animation:none;border-radius:5px;}
        .sk-school-sub{height:13px;width:180px;background:rgba(255,255,255,.18);animation:none;border-radius:4px;}
        .sk-title-bar{background:var(--red);padding:9px 16px;display:flex;justify-content:center;}
        .sk-title-text{height:14px;width:340px;max-width:80%;background:rgba(255,255,255,.30);animation:none;border-radius:4px;}
        .sk-table-wrap{padding:16px;display:flex;flex-direction:column;}
        .sk-thead{display:flex;background:var(--g7);border-radius:6px 6px 0 0;padding:10px 12px;gap:8px;}
        .sk-th{height:13px;border-radius:4px;background:rgba(255,255,255,.28);animation:none;}
        .sk-tbody{display:flex;flex-direction:column;}
        .sk-row{display:flex;gap:8px;padding:9px 12px;border-bottom:1px solid var(--gr2);align-items:center;}
        .sk-row:nth-child(even){background:var(--g0);}
        .sk-cell{height:13px;border-radius:4px;flex-shrink:0;}
        .sk-footer{padding:10px 16px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;border-top:1px solid var(--gr2);}
        .sk-footer-text{height:11px;border-radius:4px;}
    </style>
</head>
<body>

<!-- ══ SKELETON OVERLAY ══ -->
<div id="skeleton-overlay" aria-hidden="true" aria-label="Loading marksheet…">
    <div class="sk-toolbar">
        <div class="sk-toolbar-title"></div>
        <div class="sk-btn skeleton" style="width:82px"></div>
        <div class="sk-btn skeleton" style="width:140px"></div>
        <div class="sk-btn skeleton" style="width:112px"></div>
    </div>
    <div class="sk-stats no-print">
        <?php for ($i = 0; $i < 4; $i++): ?><div class="sk-stat-card"><div class="sk-stat-val skeleton"></div><div class="sk-stat-lbl skeleton"></div></div><?php endfor; ?>
    </div>
    <div class="sk-ms-wrap">
        <div class="sk-ms-header"><div class="sk-logo"></div><div class="sk-ms-header-body"><div class="sk-school-name skeleton"></div><div class="sk-school-sub skeleton"></div></div></div>
        <div class="sk-title-bar"><div class="sk-title-text skeleton"></div></div>
        <div class="sk-table-wrap">
            <div class="sk-thead">
                <div class="sk-th skeleton" style="width:28px"></div>
                <div class="sk-th skeleton" style="width:160px"></div>
                <div class="sk-th skeleton" style="width:60px"></div>
                <?php for ($i = 0; $i < 8; $i++): ?><div class="sk-th skeleton" style="flex:1;min-width:40px"></div><?php endfor; ?>
            </div>
            <div class="sk-tbody">
                <?php for ($r = 0; $r < 12; $r++): ?>
                <div class="sk-row">
                    <div class="sk-cell skeleton" style="width:24px"></div>
                    <div class="sk-cell skeleton" style="width:<?= 140 + ($r % 3) * 20 ?>px"></div>
                    <div class="sk-cell skeleton" style="width:54px"></div>
                    <?php for ($c = 0; $c < 8; $c++): ?><div class="sk-cell skeleton" style="flex:1;min-width:36px"></div><?php endfor; ?>
                </div>
                <?php endfor; ?>
            </div>
        </div>
        <div class="sk-footer"><div class="sk-footer-text skeleton" style="width:220px"></div><div class="sk-footer-text skeleton" style="width:280px"></div></div>
    </div>
</div>

<!-- ══ TOOLBAR ══ -->
<div class="toolbar no-print">
    <span class="toolbar-title"><i class="fas fa-table"></i> Detailed Marksheet</span>
    <a href="sel_gen_marksheet.php" class="btn btn-back"><i class="fas fa-arrow-left"></i> Back</a>
    <button class="btn btn-excel" id="btn-excel"><i class="fas fa-file-excel"></i> Download Excel</button>
    <button class="btn btn-pdf" onclick="window.print()"><i class="fas fa-file-pdf"></i> Print / PDF</button>
</div>

<div class="page">

<?php if (!empty($students)): ?>
<!-- ══ STATS STRIP ══ -->
<div class="stats-strip no-print">
    <div class="stat-card">
        <span class="stat-val"><?= count($students) ?></span>
        <span class="stat-lbl">Students</span>
    </div>
    <div class="stat-card">
        <span class="stat-val"><?= count($subjects) ?></span>
        <span class="stat-lbl">Subjects</span>
    </div>
    <div class="stat-card">
        <span class="stat-val" style="font-size:14px"><?= htmlspecialchars(implode(', ', $streams)) ?></span>
        <span class="stat-lbl">Streams</span>
    </div>
    <div class="stat-card">
        <span class="stat-val" style="font-size:14px"><?= htmlspecialchars($term) ?></span>
        <span class="stat-lbl"><?= htmlspecialchars($year) ?></span>
    </div>
</div>
<?php endif; ?>

<!-- ══ MARKSHEET CONTAINER ══ -->
<div class="ms-wrap" id="marksheet-container">

    <!-- School Header -->
    <div class="ms-header">
        <?php if (!empty($school_info['logo_path'])): ?>
        <img src="<?= htmlspecialchars('../' . ltrim($school_info['logo_path'] ?? '', '/')) ?>" alt="Logo" onerror="this.style.display='none'">
        <?php endif; ?>
        <div>
            <div class="ms-school-name"><?= htmlspecialchars($school_info['school_name'] ?? 'School Name') ?></div>
            <div class="ms-school-sub">
                <?= htmlspecialchars($school_info['address'] ?? '') ?>
                <?php if (!empty($school_info['phone'])): ?> &nbsp;|&nbsp; <?= htmlspecialchars($school_info['phone']) ?><?php endif; ?>
                <?php if (!empty($school_info['email'])): ?> &nbsp;|&nbsp; <?= htmlspecialchars($school_info['email']) ?><?php endif; ?>
            </div>
        </div>
        <div class="ms-header-right">
            <div>Streams: <?= htmlspecialchars(implode(', ', $streams)) ?></div>
            <div><?= htmlspecialchars($term) ?> &middot; <?= htmlspecialchars($year) ?></div>
        </div>
    </div>

    <!-- Title Bar -->
    <div class="ms-title-bar">
        <?= htmlspecialchars(strtoupper($class)) ?> &mdash; <?= htmlspecialchars($term) ?> DETAILED MARKSHEET (<?= htmlspecialchars($year) ?>)
    </div>

    <!-- Key Strip -->
    <div class="grade-key">
        <span class="grade-key-label">KEY:</span>
        <span class="gk-badge">AV = Average of AOI Topics</span>
        <span class="gk-badge">EOT = End of Term Exam</span>
        <span class="gk-badge">AVG = Overall Average</span>
        <span class="gk-badge">POS = Position</span>
    </div>

    <!-- Table -->
    <div class="tbl-scroll">
    <?php if (empty($students) || empty($all_topics)): ?>
        <div class="no-data"><i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:12px"></i>No student records found for the selected criteria.</div>
    <?php else: ?>
        <table class="ms-table" id="ms-table">
            <thead>
                <tr>
                    <th rowspan="2" class="col-no" style="min-width:32px">#</th>
                    <th rowspan="2" class="col-name" style="text-align:left;min-width:180px">FULL NAME</th>
                    <th rowspan="2" style="min-width:56px">STREAM</th>
                    <?php foreach ($subjects as $subject): ?>
                        <?php
                        $topic_count = isset($all_topics[$subject]) && !empty($all_topics[$subject]) ? count($all_topics[$subject]) : 1;
                        $show_eot   = isset($subjects_with_eot[$subject]) && $subjects_with_eot[$subject];
                        $show_avg   = $topic_count > 1;
                        $colspan    = $topic_count + ($show_avg ? 1 : 0) + ($show_eot ? 1 : 0);
                        ?>
                        <th colspan="<?= $colspan ?>" class="subj-hdr"><?= htmlspecialchars(getSubjectAbbreviation($subject)) ?></th>
                    <?php endforeach; ?>
                    <th rowspan="2" class="subj-hdr" style="min-width:48px">AVG</th>
                    <th rowspan="2" class="subj-hdr" style="min-width:44px">POS</th>
                </tr>
                <tr>
                    <?php foreach ($subjects as $subject): ?>
                        <?php
                        $topics_for_subj = isset($all_topics[$subject]) && !empty($all_topics[$subject]) ? $all_topics[$subject] : [];
                        $t_count = count($topics_for_subj);
                        ?>
                        <?php if (!empty($topics_for_subj)): ?>
                            <?php foreach ($topics_for_subj as $idx => $topic): ?>
                                <th><?= $idx + 1 ?></th>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <th>1</th>
                        <?php endif; ?>
                        <?php if ($t_count > 1): ?><th>AV</th><?php endif; ?>
                        <?php if (isset($subjects_with_eot[$subject]) && $subjects_with_eot[$subject]): ?><th>EOT</th><?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php $i = 0; foreach ($students as $student): $i++; $sid = $student['student_id']; ?>
                <tr>
                    <td class="col-no"><?= $i ?></td>
                    <td class="col-name student-name-cell"><?= htmlspecialchars(strtoupper($student['first_name'] . ' ' . $student['last_name'])) ?></td>
                    <td class="stream-cell"><?= htmlspecialchars($student['stream']) ?></td>

                    <?php foreach ($subjects as $subject): ?>
                        <?php if (isset($all_topics[$subject]) && !empty($all_topics[$subject])): ?>
                            <?php foreach ($all_topics[$subject] as $topic): ?>
                                <td class="mark-cell <?= !isset($student_marks[$sid][$subject][$topic['id']]) ? 'empty' : '' ?>">
                                    <?= isset($student_marks[$sid][$subject][$topic['id']]) ? number_format($student_marks[$sid][$subject][$topic['id']], 1) : '' ?>
                                </td>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <td class="mark-cell empty"></td>
                        <?php endif; ?>
                        <?php $tc = isset($all_topics[$subject]) && !empty($all_topics[$subject]) ? count($all_topics[$subject]) : 0; ?>
                        <?php if ($tc > 1): ?>
                            <td class="avg-cell"><?= isset($average_marks[$sid][$subject]) ? number_format($average_marks[$sid][$subject], 1) : '' ?></td>
                        <?php endif; ?>
                        <?php if (isset($subjects_with_eot[$subject]) && $subjects_with_eot[$subject]): ?>
                            <td class="eot-cell"><?= isset($eot_marks[$sid][$subject]) ? number_format($eot_marks[$sid][$subject], 1) : '' ?></td>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <td class="average-cell"><?= isset($student_averages[$sid]) ? number_format($student_averages[$sid], 1) : '' ?></td>
                    <td class="position-cell"><?= isset($positions[$sid]) ? $positions[$sid] : '' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div><!-- /tbl-scroll -->

    <!-- Footer -->
    <div class="ms-footer">
        <span><?= htmlspecialchars($school_info['school_name'] ?? '') ?> &mdash; Generated: <?= date('d M Y, H:i') ?></span>
        <span><?= htmlspecialchars($class) ?> &middot; <?= htmlspecialchars($term) ?> &middot; <?= htmlspecialchars($year) ?></span>
    </div>
</div><!-- /ms-wrap -->

</div><!-- /page -->

<script src="../assets/js/jquery.min.js"></script>
<script>
// Skeleton dismissal
(function () {
    const overlay = document.getElementById('skeleton-overlay');
    if (!overlay) return;
    const dismiss = () => {
        if (overlay.classList.contains('fade-out')) return;
        overlay.classList.add('fade-out');
        setTimeout(() => { overlay.style.display = 'none'; overlay.remove(); }, 400);
    };
    window.addEventListener('load', dismiss);
    setTimeout(dismiss, 3000);
})();

// Excel export
document.getElementById('btn-excel').addEventListener('click', function () {
    const table = document.getElementById('ms-table');
    if (!table) { alert('No table data to export.'); return; }
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.table_to_sheet(table, { raw: false, display: true });
    const range = XLSX.utils.decode_range(ws['!ref']);
    ws['!cols'] = [];
    for (let C = range.s.c; C <= range.e.c; C++) {
        let maxLen = 8;
        for (let R = range.s.r; R <= range.e.r; R++) {
            const cell = ws[XLSX.utils.encode_cell({ r: R, c: C })];
            if (cell && cell.v) maxLen = Math.max(maxLen, String(cell.v).length + 2);
        }
        ws['!cols'].push({ wch: Math.min(maxLen, 30) });
    }
    XLSX.utils.book_append_sheet(wb, ws, 'Marksheet');
    XLSX.writeFile(wb, '<?= addslashes($class) ?>_<?= addslashes($term) ?>_<?= addslashes($year) ?>_Detailed.xlsx');
});
</script>
</body>
</html>
