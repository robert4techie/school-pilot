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
        // Log the error for debugging. Check your PHP error logs.
        error_log("summarized_marksheet.php: mysqli_prepare failed in getStudents: " . mysqli_error($conn));
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
    // The error on line 73 strongly suggests $stmt is not an object here.
    // The if (!$stmt) check above should catch this.
    if (!call_user_func_array(array($stmt, 'bind_param'), $refs)) {
        error_log("summarized_marksheet.php: bind_param failed in getStudents: " . $stmt->error);
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
                // Make sure we are using the correct ID column based on your table schema
                // Your schema has 'student_id' as PRI and 'students_id' as UNI.
                // It's likely 'student_id' is what you want for unique identification.
                $row['student_id'] = $row['student_id']; // This line is redundant if $row already has 'student_id'
                $students[] = $row;
            }
        }
        mysqli_free_result($result);
    } else {
        error_log("summarized_marksheet.php: mysqli_stmt_get_result failed in getStudents: " . $stmt->error);
    }

    mysqli_stmt_close($stmt);
    return $students;
}

// Function to get all marks in one query (for JS processing)
function getAllMarks($conn, $results_table, $students, $subjects)
{
    if (empty($students) || empty($subjects)) {
        return array();
    }

    $student_ids = array_column($students, 'student_id');
    $student_placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
    $subject_placeholders = str_repeat('?,', count($subjects) - 1) . '?';

    // Single query to get ALL marks (excluding EOT)
    $sql = "SELECT student_id, subject, topic_id, marks 
            FROM `$results_table` 
            WHERE student_id IN ($student_placeholders) 
            AND subject IN ($subject_placeholders)
            AND topic_id != 'EOT'";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
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

    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $marks[] = array(
                'student_id' => $row['student_id'],
                'subject' => $row['subject'],
                'topic_id' => $row['topic_id'],
                'marks' => (float)$row['marks']
            );
        }
    }

    return $marks;
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

// Get all marks for JS processing
$all_marks = getAllMarks($conn, $results_table, $students, $subjects);

// Get school information
$school_info = getSchoolInfo($conn);

// Convert PHP arrays to JSON for JavaScript use
$students_json = json_encode($students);
$subjects_json = json_encode($subjects);
$all_marks_json = json_encode($all_marks);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Summarized Marksheet — <?= htmlspecialchars($class) ?> · <?= htmlspecialchars($term) ?> · <?= htmlspecialchars($year) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/fonts/fontawesome-all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <style>
        :root {
            --g9:#1a4731;--g7:#1e8449;--g5:#27ae60;
            --g3:#2ecc71;--g1:#e8f5ee;--g0:#f2faf6;
            --red:#e53935;
            --gr8:#1e293b;--gr6:#475569;--gr4:#94a3b8;
            --gr2:#e2e8f0;--gr1:#f1f5f9;--wh:#fff;
            --shadow:0 4px 18px rgba(0,0,0,.10);
            --r:8px;--trans:all .2s ease;
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Sen',sans-serif;background:var(--gr1);color:var(--gr8);font-size:13px;padding-bottom:40px;}
        .toolbar{background:var(--g9);color:#fff;padding:12px 20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;position:sticky;top:0;z-index:100;}
        .toolbar-title{font-weight:700;font-size:14px;flex:1;}
        .btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border:none;border-radius:6px;font-family:'Sen',sans-serif;font-size:13px;font-weight:700;cursor:pointer;transition:var(--trans);text-decoration:none;}
        .btn-excel{background:#217346;color:#fff;}.btn-excel:hover{background:#1a5c37;}
        .btn-pdf{background:var(--red);color:#fff;}.btn-pdf:hover{background:#c62828;}
        .btn-back{background:rgba(255,255,255,.15);color:#fff;}.btn-back:hover{background:rgba(255,255,255,.25);}
        .page{max-width:100%;margin:0 auto;padding:18px 14px;}
        .stats-strip{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
        .stat-card{background:var(--wh);border-radius:var(--r);padding:10px 18px;flex:1;min-width:110px;box-shadow:0 1px 4px rgba(0,0,0,.06);display:flex;flex-direction:column;align-items:center;}
        .stat-val{font-size:22px;font-weight:800;color:var(--g7);}
        .stat-lbl{font-size:11px;color:var(--gr6);margin-top:2px;font-weight:600;}
        .ms-wrap{background:var(--wh);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden;}
        .ms-header{background:linear-gradient(135deg,var(--g9),var(--g7));color:#fff;padding:18px 22px 14px;display:flex;align-items:center;gap:20px;}
        .ms-header img{height:70px;width:auto;border-radius:6px;background:#fff;padding:4px;}
        .ms-school-name{font-size:20px;font-weight:800;letter-spacing:.5px;}
        .ms-school-sub{font-size:12px;opacity:.85;margin-top:2px;}
        .ms-header-right{margin-left:auto;text-align:right;font-size:12px;opacity:.85;}
        .ms-title-bar{background:var(--red);color:#fff;text-align:center;padding:9px 16px;font-size:14px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;}
        .grade-key{background:var(--g0);padding:7px 16px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;font-size:11px;border-bottom:1px solid var(--gr2);}
        .grade-key-label{font-weight:700;color:var(--g9);margin-right:4px;}
        .gk-badge{display:inline-flex;align-items:center;gap:4px;background:var(--wh);border:1px solid var(--gr2);border-radius:4px;padding:3px 8px;font-weight:700;}
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
        .mark-cell{background:var(--g1)!important;font-weight:700;color:var(--g9);}
        .average-cell{background:#fff8e1!important;font-weight:800;color:#e65100;}
        .position-cell{background:#e8eaf6!important;font-weight:800;color:#283593;}
        .no-data{text-align:center;padding:48px;color:var(--gr4);font-size:15px;}
        .ms-footer{padding:10px 16px;font-size:11px;color:var(--gr6);border-top:1px solid var(--gr2);display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;}

        /* Loading spinner (replaces old spinner) */
        #loading-overlay{position:fixed;inset:0;background:var(--gr1);z-index:999;display:flex;justify-content:center;align-items:center;}
        .spinner{width:48px;height:48px;border:5px solid var(--gr2);border-top:5px solid var(--g5);border-radius:50%;animation:spin 1s linear infinite;}
        @keyframes spin{to{transform:rotate(360deg)}}

        @media print {
            body{background:#fff;font-size:9px;padding:0;}
            .toolbar,.stats-strip,.no-print,#loading-overlay{display:none!important;}
            .page{padding:0;margin:0;}
            .ms-wrap{box-shadow:none;border-radius:0;}
            .ms-header,.ms-title-bar{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
            .ms-table thead tr:first-child th,.ms-table thead tr:nth-child(2) th{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
            .ms-table tbody tr:nth-child(even){-webkit-print-color-adjust:exact;print-color-adjust:exact;}
            .mark-cell,.average-cell,.position-cell{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
            @page{size:landscape;margin:8mm;}
            .ms-table{font-size:8px!important;}
            .ms-table th,.ms-table td{padding:4px 3px!important;}
        }
    </style>
</head>
<body>

<!-- Loading overlay (JS hides this after table is populated) -->
<div id="loading-overlay"><div class="spinner"></div></div>

<!-- ══ TOOLBAR ══ -->
<div class="toolbar no-print">
    <span class="toolbar-title"><i class="fas fa-table"></i> Summarized Marksheet (0–3 Scale)</span>
    <a href="sel_gen_marksheet.php" class="btn btn-back"><i class="fas fa-arrow-left"></i> Back</a>
    <button class="btn btn-excel" id="btn-excel"><i class="fas fa-file-excel"></i> Download Excel</button>
    <button class="btn btn-pdf" onclick="window.print()"><i class="fas fa-file-pdf"></i> Print / PDF</button>
</div>

<div class="page">

<!-- Stats strip — values filled by JS after table is built -->
<div class="stats-strip no-print" id="stats-strip" style="display:none">
    <div class="stat-card">
        <span class="stat-val" id="stat-students"><?= count($students) ?></span>
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

<!-- ══ MARKSHEET CONTAINER ══ -->
<div class="ms-wrap" id="marksheet-container" style="display:none">

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
        <?= htmlspecialchars(strtoupper($class)) ?> &mdash; <?= htmlspecialchars($term) ?> SUMMARIZED MARKSHEET (<?= htmlspecialchars($year) ?>)
    </div>

    <!-- Grading Key -->
    <div class="grade-key">
        <span class="grade-key-label">0–3 SCALE:</span>
        <span class="gk-badge">3 &nbsp;≥ 2.5 Outstanding</span>
        <span class="gk-badge">2 &nbsp;≥ 1.5 Moderate</span>
        <span class="gk-badge">1 &nbsp;≥ 0.9 Basic</span>
        <span class="gk-badge">0 &nbsp;≤ 0.8 Below Basic</span>
    </div>

    <!-- Table -->
    <div class="tbl-scroll">
    <?php if (empty($students) || empty($all_marks)): ?>
        <div class="no-data"><i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:12px"></i>No student records found for the selected criteria.</div>
    <?php else: ?>
        <table class="ms-table" id="ms-table">
            <thead>
                <tr>
                    <th rowspan="2" class="col-no" style="min-width:32px">#</th>
                    <th rowspan="2" class="col-name" style="text-align:left;min-width:180px">FULL NAME</th>
                    <th rowspan="2" style="min-width:56px">STREAM</th>
                    <?php foreach ($subjects as $subject): ?>
                        <th class="subj-hdr"><?= htmlspecialchars(getSubjectAbbreviation($subject)) ?></th>
                    <?php endforeach; ?>
                    <th rowspan="2" class="subj-hdr" style="min-width:52px">AVG</th>
                    <th rowspan="2" class="subj-hdr" style="min-width:44px">POS</th>
                </tr>
                <tr>
                    <?php foreach ($subjects as $subject): ?>
                        <th style="background:var(--g9);color:#fff;font-size:10px">/3</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <!-- Populated by JavaScript -->
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
// Pass PHP data to JavaScript — UNCHANGED logic
const students = <?php echo $students_json; ?>;
const subjects = <?php echo $subjects_json; ?>;
const allMarks = <?php echo $all_marks_json; ?>;

function processMarks() {
    const subjectAverages = {};
    const marksByStudentSubject = {};
    students.forEach(student => {
        const studentId = student.student_id;
        subjectAverages[studentId] = {};
        marksByStudentSubject[studentId] = {};
        subjects.forEach(subject => {
            marksByStudentSubject[studentId][subject] = [];
            subjectAverages[studentId][subject] = 0;
        });
    });
    allMarks.forEach(mark => {
        const studentId = mark.student_id;
        const subject = mark.subject;
        if (marksByStudentSubject[studentId] && marksByStudentSubject[studentId][subject]) {
            marksByStudentSubject[studentId][subject].push(mark.marks);
        }
    });
    students.forEach(student => {
        const studentId = student.student_id;
        subjects.forEach(subject => {
            const marks = marksByStudentSubject[studentId][subject];
            if (marks.length > 0) {
                const sum = marks.reduce((total, mark) => total + mark, 0);
                subjectAverages[studentId][subject] = sum / marks.length;
            }
        });
    });
    const overallAverages = {};
    students.forEach(student => {
        const studentId = student.student_id;
        let total = 0, count = 0;
        subjects.forEach(subject => {
            if (subjectAverages[studentId][subject] > 0) { total += subjectAverages[studentId][subject]; count++; }
        });
        overallAverages[studentId] = count > 0 ? total / count : 0;
    });
    const studentRankings = students.map(student => ({ studentId: student.student_id, average: overallAverages[student.student_id] || 0 }));
    studentRankings.sort((a, b) => b.average - a.average);
    const positions = {};
    let currentPosition = 1, previousAverage = null, skippedPositions = 0;
    studentRankings.forEach((ranking) => {
        const { studentId, average } = ranking;
        if (previousAverage !== null && average < previousAverage) { currentPosition += skippedPositions + 1; skippedPositions = 0; }
        else if (previousAverage !== null && average === previousAverage) { skippedPositions++; }
        positions[studentId] = currentPosition;
        previousAverage = average;
    });
    return { subjectAverages, overallAverages, positions };
}

function populateTable(results) {
    const { subjectAverages, positions, overallAverages } = results;
    const tableBody = document.querySelector('#ms-table tbody');
    if (!tableBody) return;
    tableBody.innerHTML = '';
    students.forEach((student, index) => {
        const row = document.createElement('tr');
        const studentId = student.student_id;
        // Index
        const idxCell = document.createElement('td');
        idxCell.className = 'col-no'; idxCell.textContent = index + 1; row.appendChild(idxCell);
        // Name
        const nameCell = document.createElement('td');
        nameCell.className = 'student-name-cell'; nameCell.style.textAlign = 'left';
        nameCell.textContent = (student.first_name + ' ' + student.last_name).toUpperCase(); row.appendChild(nameCell);
        // Stream
        const streamCell = document.createElement('td');
        streamCell.textContent = student.stream; row.appendChild(streamCell);
        // Subject marks
        subjects.forEach(subject => {
            const mark3 = subjectAverages[studentId][subject] || 0;
            const cell = document.createElement('td');
            cell.className = 'mark-cell';
            cell.textContent = mark3 > 0 ? mark3.toFixed(1) : '—';
            row.appendChild(cell);
        });
        // Overall average
        const avgCell = document.createElement('td');
        avgCell.className = 'average-cell';
        const overallAvg = overallAverages[studentId] || 0;
        avgCell.textContent = overallAvg > 0 ? overallAvg.toFixed(1) : '—'; row.appendChild(avgCell);
        // Position
        const posCell = document.createElement('td');
        posCell.className = 'position-cell';
        posCell.textContent = positions[studentId] || '—'; row.appendChild(posCell);
        tableBody.appendChild(row);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    setTimeout(function () {
        const results = processMarks();
        populateTable(results);
        document.getElementById('loading-overlay').style.display = 'none';
        document.getElementById('marksheet-container').style.display = 'block';
        document.getElementById('stats-strip').style.display = 'flex';
    }, 10);
});

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
    XLSX.writeFile(wb, '<?= addslashes($class) ?>_<?= addslashes($term) ?>_<?= addslashes($year) ?>_Summarized.xlsx');
});
</script>
</body>
</html>
