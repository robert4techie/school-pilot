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
    // CORRECTED: Changed 'stream' to 'stream' based on your students table schema
    $sql = "SELECT * FROM students WHERE current_class = ? AND stream IN ($placeholders) ORDER BY stream, last_name, first_name";

    $stmt = mysqli_prepare($conn, $sql);

    // CRITICAL FIX: Check if statement preparation was successful
    if (!$stmt) {
        // Log the error for debugging. Check your PHP error logs (e.g., in XAMPP/php/logs/php_error.log).
        error_log("assessment_marksheet.php: mysqli_prepare failed in getStudents: " . mysqli_error($conn));
        return array(); // Return empty array to prevent further errors
    }

    $types = 's' . str_repeat('s', count($streams));
    $bind_params = array($types, $class);

    foreach ($streams as $stream) {
        $bind_params[] = $stream;
    }

    $refs = array();
    foreach ($bind_params as $key => $value) {
        $refs[$key] = &$bind_params[$key];
    }

    // Attempt to bind parameters
    if (!call_user_func_array(array($stmt, 'bind_param'), $refs)) {
        // Log binding error
        error_log("assessment_marksheet.php: bind_param failed in getStudents: " . $stmt->error);
        mysqli_stmt_close($stmt); // Close the statement even if bind fails
        return array();
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $students = array();
    // Check if get_result was successful before trying to fetch rows
    if ($result) {
        if (mysqli_num_rows($result) > 0) {
            while ($row = mysqli_fetch_assoc($result)) {
                // Ensure 'student_id' is correctly referenced.
                // Your schema has 'student_id' as PRI.
                $row['student_id'] = $row['student_id']; // This line is redundant if $row already has 'student_id' but harmless
                $students[] = $row;
            }
        }
        mysqli_free_result($result);
    } else {
        error_log("assessment_marksheet.php: mysqli_stmt_get_result failed in getStudents: " . $stmt->error);
    }

    mysqli_stmt_close($stmt); // Close the statement
    return $students;
}

// Function to get subject averages for each student - SIMPLIFIED (No EOT)
function getSubjectAverages($conn, $results_table, $students, $subjects)
{
    $subject_averages = array();

    if (empty($students) || empty($subjects)) {
        return $subject_averages;
    }

    $student_ids = array_column($students, 'student_id');
    $student_placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
    $subject_placeholders = str_repeat('?,', count($subjects) - 1) . '?';

    // Single query to get all regular averages (excluding EOT)
    $sql = "SELECT student_id, subject, AVG(marks) as avg_mark 
            FROM `$results_table` 
            WHERE student_id IN ($student_placeholders) 
            AND subject IN ($subject_placeholders)
            AND topic_id != 'EOT'
            GROUP BY student_id, subject";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return $subject_averages;
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

    // Initialize the array structure for all students and subjects
    foreach ($student_ids as $student_id) {
        $subject_averages[$student_id] = array();
        foreach ($subjects as $subject) {
            $subject_averages[$student_id][$subject] = 0;
        }
    }

    // Fill in the averages
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $subject_averages[$row['student_id']][$row['subject']] = $row['avg_mark'] ?: 0;
        }
    }

    return $subject_averages;
}

// Function to calculate overall averages and positions - OPTIMIZED
function calculateOverallAveragesAndPositions($subject_averages, $subjects)
{
    $overall_averages = array();
    $positions = array();

    // Calculate overall average for each student (single loop)
    $sorting_data = array();
    foreach ($subject_averages as $student_id => $subject_marks) {
        $total = 0;
        $subject_count = 0;

        foreach ($subjects as $subject) {
            if (isset($subject_marks[$subject]) && $subject_marks[$subject] > 0) {
                $total += $subject_marks[$subject];
                $subject_count++;
            }
        }

        $average = ($subject_count > 0) ? ($total / $subject_count) : 0;
        $overall_averages[$student_id] = $average;

        // Build sorting array in the same loop
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
        'overall_averages' => $overall_averages,
        'positions' => $positions
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
        'islamic religious education' => 'IRE',
        'christian religious education' => 'CRE',
        'art and design' => 'ART',
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
        'address' => 'School Address', // Matches your school_details table
        'phone' => 'School Phone',   // Matches your school_details table
        'email' => 'School Email'         // Matches your school_details table
    );
}

// Get all students
$students = getStudents($conn, $class, $streams);

// Get the subject averages for each student
$subject_averages = getSubjectAverages($conn, $results_table, $students, $subjects);

// Calculate overall averages and positions
$results = calculateOverallAveragesAndPositions($subject_averages, $subjects);
$overall_averages = $results['overall_averages'];
$positions = $results['positions'];

// Get school information
$school_info = getSchoolInfo($conn);

// Function to get grade based on mark (direct 0-3 scale)
function getGrade($mark)
{
    if ($mark >= 2.5) {
        return '3';
    } elseif ($mark >= 1.5) {
        return '2';
    } elseif ($mark >= 0.9) {
        return '1';
    } else {
        return '0';
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Marksheet — <?= htmlspecialchars($class) ?> · <?= htmlspecialchars($term) ?> · <?= htmlspecialchars($year) ?></title>
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

        /* ── Stats ── */
        .stats-strip{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
        .stat-card{background:var(--wh);border-radius:var(--r);padding:10px 18px;flex:1;min-width:110px;box-shadow:0 1px 4px rgba(0,0,0,.06);display:flex;flex-direction:column;align-items:center;}
        .stat-val{font-size:22px;font-weight:800;color:var(--g7);}
        .stat-lbl{font-size:11px;color:var(--gr6);margin-top:2px;font-weight:600;}

        /* ── Marksheet card ── */
        .ms-wrap{background:var(--wh);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden;}
        .ms-header{background:linear-gradient(135deg,var(--g9),var(--g7));color:#fff;padding:18px 22px 14px;display:flex;align-items:center;gap:20px;}
        .ms-header img{height:70px;width:auto;border-radius:6px;background:#fff;padding:4px;}
        .ms-school-name{font-size:20px;font-weight:800;letter-spacing:.5px;}
        .ms-school-sub{font-size:12px;opacity:.85;margin-top:2px;}
        .ms-header-right{margin-left:auto;text-align:right;font-size:12px;opacity:.85;}
        .ms-title-bar{background:var(--red);color:#fff;text-align:center;padding:9px 16px;font-size:14px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;}

        /* ── Grade key ── */
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
        .student-name-cell{text-align:left!important;padding-left:10px!important;font-weight:600;}

        /* ── Cell types ── */
        .mark-cell-3{background:var(--g1)!important;font-weight:700;color:var(--g9);}       /* /3  */
        .mark-cell-20{background:#fff8e1!important;font-weight:800;color:#e65100;}           /* /20 */
        .position-cell{background:#e8eaf6!important;font-weight:800;color:#283593;}

        /* Grade colours */
        .g-3{color:var(--g7);font-weight:800}
        .g-2{color:#1565c0;font-weight:800}
        .g-1{color:#e65100;font-weight:800}
        .g-0{color:var(--red);font-weight:800}

        .no-data{text-align:center;padding:48px;color:var(--gr4);font-size:15px;}
        .ms-footer{padding:10px 16px;font-size:11px;color:var(--gr6);border-top:1px solid var(--gr2);display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;}

        /* ── Print ── */
        @media print{
            body{background:#fff;font-size:9px;padding:0;}
            .toolbar,.stats-strip,.no-print{display:none!important;}
            .page{padding:0;margin:0;}
            .ms-wrap{box-shadow:none;border-radius:0;}
            .ms-header,.ms-title-bar{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
            .ms-table thead tr:first-child th,.ms-table thead tr:nth-child(2) th{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
            .ms-table tbody tr:nth-child(even){-webkit-print-color-adjust:exact;print-color-adjust:exact;}
            .mark-cell-3,.mark-cell-20,.position-cell{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
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
<div id="skeleton-overlay" aria-hidden="true">
    <div class="sk-toolbar">
        <div class="sk-toolbar-title"></div>
        <div class="sk-btn skeleton" style="width:82px"></div>
        <div class="sk-btn skeleton" style="width:140px"></div>
        <div class="sk-btn skeleton" style="width:112px"></div>
    </div>
    <div class="sk-stats">
        <?php for ($i=0;$i<4;$i++): ?><div class="sk-stat-card"><div class="sk-stat-val skeleton"></div><div class="sk-stat-lbl skeleton"></div></div><?php endfor; ?>
    </div>
    <div class="sk-ms-wrap">
        <div class="sk-ms-header"><div class="sk-logo"></div><div class="sk-ms-header-body"><div class="sk-school-name skeleton"></div><div class="sk-school-sub skeleton"></div></div></div>
        <div class="sk-title-bar"><div class="sk-title-text skeleton"></div></div>
        <div class="sk-table-wrap">
            <div class="sk-thead">
                <div class="sk-th skeleton" style="width:28px"></div>
                <div class="sk-th skeleton" style="width:160px"></div>
                <div class="sk-th skeleton" style="width:56px"></div>
                <?php for($i=0;$i<10;$i++): ?><div class="sk-th skeleton" style="flex:1;min-width:38px"></div><?php endfor; ?>
            </div>
            <div class="sk-tbody">
                <?php for($r=0;$r<12;$r++): ?>
                <div class="sk-row">
                    <div class="sk-cell skeleton" style="width:24px"></div>
                    <div class="sk-cell skeleton" style="width:<?= 140+($r%3)*20 ?>px"></div>
                    <div class="sk-cell skeleton" style="width:50px"></div>
                    <?php for($c=0;$c<10;$c++): ?><div class="sk-cell skeleton" style="flex:1;min-width:34px"></div><?php endfor; ?>
                </div>
                <?php endfor; ?>
            </div>
        </div>
        <div class="sk-footer"><div class="sk-footer-text skeleton" style="width:220px"></div><div class="sk-footer-text skeleton" style="width:280px"></div></div>
    </div>
</div>

<!-- ══ TOOLBAR ══ -->
<div class="toolbar no-print">
    <span class="toolbar-title"><i class="fas fa-clipboard-check"></i> Assessment Marksheet (/3 &amp; /20)</span>
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
        <?= htmlspecialchars(strtoupper($class)) ?> &mdash; <?= htmlspecialchars($term) ?> ASSESSMENT MARKSHEET (<?= htmlspecialchars($year) ?>)
    </div>

    <!-- Grade Key -->
    <div class="grade-key">
        <span class="grade-key-label">0–3 SCALE:</span>
        <span class="gk-badge"><span class="g-3">3</span> ≥ 2.5 Outstanding</span>
        <span class="gk-badge"><span class="g-2">2</span> ≥ 1.5 Moderate</span>
        <span class="gk-badge"><span class="g-1">1</span> ≥ 0.9 Basic</span>
        <span class="gk-badge"><span class="g-0">0</span> ≤ 0.8 Below Basic</span>
        <span class="gk-badge" style="margin-left:8px;background:var(--g1)">AOI /3 → AOI /20 via × (20/3)</span>
    </div>

    <!-- Table -->
    <div class="tbl-scroll">
    <?php if (empty($students) || empty($subject_averages)): ?>
        <div class="no-data"><i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:12px"></i>No student records found for the selected criteria.</div>
    <?php else: ?>
        <table class="ms-table" id="ms-table">
            <thead>
                <tr>
                    <th rowspan="2" class="col-no" style="min-width:32px">#</th>
                    <th rowspan="2" class="col-name" style="text-align:left;min-width:180px">FULL NAME</th>
                    <th rowspan="2" style="min-width:56px">STREAM</th>
                    <?php foreach ($subjects as $subject): ?>
                        <th class="subj-hdr" colspan="2"><?= htmlspecialchars(getSubjectAbbreviation($subject)) ?></th>
                    <?php endforeach; ?>
                    <th rowspan="2" class="subj-hdr" style="min-width:44px">POS</th>
                </tr>
                <tr>
                    <?php foreach ($subjects as $subject): ?>
                        <th style="background:var(--g9);color:#fff;font-size:10px">/3</th>
                        <th style="background:var(--g9);color:#fff;font-size:10px">/20</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $i = 0;
                foreach ($students as $student):
                    $i++;
                    $student_id = $student['student_id'];
                ?>
                <tr>
                    <td class="col-no"><?= $i ?></td>
                    <td class="student-name-cell"><?= htmlspecialchars(strtoupper($student['first_name'] . ' ' . $student['last_name'])) ?></td>
                    <td><?= htmlspecialchars($student['stream']) ?></td>

                    <?php foreach ($subjects as $subject):
                        $mark3 = isset($subject_averages[$student_id][$subject]) ? $subject_averages[$student_id][$subject] : 0;
                        $mark20 = $mark3 > 0 ? ($mark3 / 3) * 20 : 0;
                        $grade = getGrade($mark3);
                    ?>
                        <!-- /3 cell -->
                        <td class="mark-cell-3">
                            <?php if ($mark3 > 0): ?>
                                <span class="g-<?= $grade ?>"><?= number_format($mark3, 1) ?></span>
                            <?php else: ?>
                                <span style="color:var(--gr4)">—</span>
                            <?php endif; ?>
                        </td>
                        <!-- /20 cell -->
                        <td class="mark-cell-20">
                            <?php if ($mark3 > 0): ?>
                                <?= number_format($mark20, 1) ?>
                            <?php else: ?>
                                <span style="color:var(--gr4)">—</span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>

                    <!-- Position -->
                    <td class="position-cell">
                        <?= isset($positions[$student_id]) ? $positions[$student_id] : '—' ?>
                    </td>
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
    XLSX.writeFile(wb, '<?= addslashes($class) ?>_<?= addslashes($term) ?>_<?= addslashes($year) ?>_Assessment.xlsx');
});
</script>
</body>
</html>
