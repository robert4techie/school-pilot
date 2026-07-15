<?php
require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("View attendance report");

// Sanitize GET parameters
$selected_class = filter_input(INPUT_GET, 'class', FILTER_SANITIZE_STRING);
$selected_stream = filter_input(INPUT_GET, 'stream', FILTER_SANITIZE_STRING);

// Get the date from the URL for the INITIAL view, or default to today.
$selected_date_str = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_STRING);
if ($selected_date_str && DateTime::createFromFormat('Y-m-d', $selected_date_str) !== false) {
    $selected_date = $selected_date_str;
} else {
    $selected_date = date('Y-m-d');
}


if (!$selected_class || !$selected_stream) {
    // Redirect if class or stream is not selected
    header('Location: select_attendance_class.php');
    exit();
}

// Track action
if (isset($tracker)) {
    // Updated tracking message as it's a general view now
    $tracker->trackAction("Viewed Attendance Report for Class: $selected_class, Stream: $selected_stream");
}

$students = [];

// --- MODIFIED SQL query to fetch all students and all their attendance records ---
// The date filter is removed to allow client-side filtering.
$sql = "SELECT s.student_id, s.first_name, s.last_name,
               a.status AS attendance_status,
               a.created_at AS attendance_time
        FROM students s
        LEFT JOIN attendance a ON s.student_id = a.student_id
        WHERE s.current_class = ? AND s.stream = ? AND s.status = 'active'
        ORDER BY s.last_name, s.first_name";


$stmt = $conn->prepare($sql);

if ($stmt) {
    // --- UPDATED: bind_param now only has two parameters ---
    $stmt->bind_param("ss", $selected_class, $selected_stream);
    $stmt->execute();
    $result = $stmt->get_result();

    // To avoid duplicate students in the list, we process them.
    $student_data = [];
    while ($row = $result->fetch_assoc()) {
        $student_id = $row['student_id'];
        if (!isset($student_data[$student_id])) {
            $student_data[$student_id] = [
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'records' => []
            ];
        }
        if ($row['attendance_status']) {
            $student_data[$student_id]['records'][] = [
                'attendance_status' => $row['attendance_status'],
                'attendance_time' => $row['attendance_time']
            ];
        }
    }

    // Now, create the final student list, combining records.
    foreach($student_data as $student_id => $data) {
        if (empty($data['records'])) {
            // Student has never been marked
             $students[] = [
                'student_id' => $student_id,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'attendance_status' => 'unmarked',
                'attendance_time' => null
            ];
        } else {
            // Add an entry for each attendance record this student has
            foreach($data['records'] as $record) {
                $students[] = [
                    'student_id' => $student_id,
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'attendance_status' => $record['attendance_status'],
                    'attendance_time' => $record['attendance_time']
                ];
            }
        }
    }

    $stmt->close();
} else {
    error_log("Error preparing statement: " . $conn->error);
    die("A database error occurred. Please try again later.");
}

// --- Calculate initial statistics for the selected date ---
$present_count = 0;
$absent_count = 0;
$late_count = 0;
$not_marked = 0;
$total_students_in_class = count(array_unique(array_column($students, 'student_id')));

// view_attendance_report.php (Around Line 111)

$initial_view_students = [];
foreach(array_unique(array_column($students, 'student_id')) as $id) {
    $student_records = array_filter($students, fn($s) => $s['student_id'] === $id && ($s['attendance_time'] === null || str_starts_with($s['attendance_time'], $selected_date)));
    
    // Line 113 - FIX: Add array_values() to reset keys, preventing "Undefined array key 0" warning.
    $latest_record = end($student_records) 
        ?: array_values(array_filter($students, fn($s) => $s['student_id'] === $id))[0];
        
    // Line 114 - This line is now safe, as $latest_record is guaranteed to be a valid array.
    if ($latest_record['attendance_time'] === null || !str_starts_with($latest_record['attendance_time'], $selected_date)) {
        $latest_record['attendance_status'] = 'unmarked';
    }
    $initial_view_students[$id] = $latest_record;
}

foreach ($initial_view_students as $student) {
    switch ($student['attendance_status']) {
        case 'present': $present_count++; break;
        case 'absent': $absent_count++; break;
        case 'late': $late_count++; break;
        default: $not_marked++; break;
    }
}


// Helper function to generate CSS class for status badges
function getStatusBadgeClass($status)
{
    switch ($status) {
        case 'present': return 'status-badge-present';
        case 'absent': return 'status-badge-absent';
        case 'late': return 'status-badge-late';
        default: return 'status-badge-unmarked';
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Report: <?= htmlspecialchars($selected_class) ?> - <?= htmlspecialchars($selected_stream) ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        :root {
            --primary-color: #2e7d32;
            --primary-gradient: linear-gradient(135deg, #2e7d32 0%, #4caf50 100%);
            --present-color: #4caf50;
            --absent-color: #f44336;
            --late-color: #ff9800;
            --unmarked-color: #757575;
            --light-bg: #f8f9fa;
            --white: #ffffff;
            --border-color: #e0e0e0;
            --shadow-color: rgba(0, 0, 0, 0.1);
            --pdf-color: #c0392b;
            --excel-color: #1e8449;
        }

        body {
            background: var(--light-bg);
            padding: 10px;
        }

        /* START: Loader CSS */
        .loader-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .loader {
            width: 40px;
            height: 30px;
            /* Using green primary color */
            --c: no-repeat linear-gradient(var(--primary-color) 0 0);
            background:
                var(--c) 0 100%/8px 30px,
                var(--c) 50% 100%/8px 20px,
                var(--c) 100% 100%/8px 10px;
            position: relative;
            clip-path: inset(-100% 0);
        }

        .loader:before {
            content: "";
            position: absolute;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--primary-color);
            left: -16px;
            top: 0;
            animation:
                l5-1 2s linear infinite,
                l5-2 0.5s cubic-bezier(0, 200, .8, 200) infinite;
        }

        @keyframes l5-1 {
            0% {
                left: -16px;
                transform: translateY(-8px);
            }

            100% {
                left: calc(100% + 8px);
                transform: translateY(22px);
            }
        }

        @keyframes l5-2 {
            100% {
                top: -0.1px;
            }
        }

        /* END: Loader CSS */

        .container {
            max-width: 100%;
            margin: 20px auto;
            margin-top: 45px;
            background: var(--white);
            border-radius: 15px;
            box-shadow: 0 10px 30px var(--shadow-color);
            overflow: hidden;
        }

        .header {
            background: var(--primary-gradient);
            color: var(--white);
            padding: 20px;
            text-align: center;
        }

        .header h2 {
            margin: 0;
            font-size: 1.25rem;
        }

        .header p {
            margin: 5px 0 0;
            opacity: 0.9;
        }

        .report-actions {
            background: var(--light-bg);
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: flex-end;
            gap: 15px;
        }

        .report-actions .form-group {
            display: flex;
            flex-direction: column;
        }

        .report-actions label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
        }

        .report-actions input[type="date"],
        .report-actions button {
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #ccc;
            font-size: 1rem;
        }

        .report-actions button {
            cursor: pointer;
            border: none;
            color: white;
            font-weight: 600;
            transition: background-color 0.2s, transform 0.2s;
        }

        .report-actions button:active {
            transform: scale(0.98);
        }

        .view-btn {
            background-color: var(--primary-color);
        }

        .pdf-btn {
            background-color: var(--pdf-color);
        }

        .excel-btn {
            background-color: var(--excel-color);
        }

        .view-btn:hover {
            background-color: #256428;
        }

        .pdf-btn:hover {
            background-color: #a93226;
        }

        .excel-btn:hover {
            background-color: #196f3d;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            padding: 15px;
        }

        .stat-item {
            background: #fdfdfd;
            padding: 15px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            text-align: center;
        }

        .stat-number {
            font-size: 1.75rem;
            font-weight: bold;
        }

        .stat-present .stat-number {
            color: var(--present-color);
        }

        .stat-absent .stat-number {
            color: var(--absent-color);
        }

        .stat-late .stat-number {
            color: var(--late-color);
        }

        .stat-unmarked .stat-number {
            color: var(--unmarked-color);
        }

        .stat-label {
            font-size: 0.8rem;
            color: #666;
            text-transform: uppercase;
        }

        .table-container {
            padding: 15px;
        }

        .attendance-table {
            width: 100%;
            border-collapse: collapse;
        }

        .attendance-table thead {
            display: none;
        }

        .attendance-table tr {
            display: block;
            margin-bottom: 15px;
            background: var(--white);
            border-radius: 8px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px var(--shadow-color);
            overflow: hidden;
        }

        .attendance-table td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            text-align: right;
            border-bottom: 1px solid #f0f0f0;
        }

        .attendance-table td:last-child {
            border-bottom: none;
        }

        .attendance-table td::before {
            content: attr(data-label);
            font-weight: bold;
            text-align: left;
            margin-right: 10px;
            color: #555;
        }

        .student-id {
            font-weight: 600;
            color: var(--primary-color);
        }

        .attendance-time, .attendance-date {
            font-size: 0.8rem;
            color: #666;
            font-style: italic;
        }

        .status-badge {
            padding: 5px 12px;
            border-radius: 15px;
            font-weight: 600;
            font-size: 0.8rem;
            color: white;
            text-transform: capitalize;
        }

        .status-badge-present {
            background-color: var(--present-color);
        }

        .status-badge-absent {
            background-color: var(--absent-color);
        }

        .status-badge-late {
            background-color: var(--late-color);
        }

        .status-badge-unmarked {
            background-color: var(--unmarked-color);
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: #2ecc71;
            color: white;
            padding: 15px 20px;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s, visibility 0.3s, transform 0.3s;
            transform: translateY(-20px);
        }

        .notification.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        /* Search Bar Styles */
        .search-container {
            padding: 15px 20px;
            background: var(--light-bg);
            border-bottom: 1px solid var(--border-color);
        }

        .search-box {
            width: 100%;
            max-width: 400px;
            padding: 12px 15px;
            border: 1px solid #ccc;
            border-radius: 4px;
            /* Reduced border radius as requested */
            font-size: 1rem;
            font-family: inherit;
            background: white;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .search-box:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(46, 125, 50, 0.1);
        }

        .search-box::placeholder {
            color: #999;
        }

        .no-results {
            text-align: center;
            padding: 40px 20px;
            color: #666;
            font-style: italic;
        }

        .no-results-row {
            display: none;
        }

        @media screen and (min-width: 768px) {
            .search-container {
                padding: 20px;
            }

            .search-box {
                max-width: 500px;
            }
        }

        @media screen and (min-width: 768px) {
            body {
                padding: 20px;
            }

            .header h2 {
                font-size: 1.8rem;
            }

            .report-actions {
                justify-content: flex-start;
                padding: 20px;
            }

            .stats-container {
                grid-template-columns: repeat(4, 1fr);
                padding: 20px;
                gap: 20px;
            }

            .table-container {
                padding: 0 20px 20px 20px;
            }

            .attendance-table thead {
                display: table-header-group;
            }

            .attendance-table th {
                background: var(--primary-gradient);
                color: white;
                padding: 18px 15px;
                text-align: left;
                text-transform: uppercase;
                font-size: 14px;
            }

            .attendance-table tr {
                display: table-row;
                border: none;
                box-shadow: none;
                border-bottom: 1px solid var(--border-color);
            }

            .attendance-table tr:last-child {
                border-bottom: none;
            }

            .attendance-table td {
                display: table-cell;
                text-align: left;
                padding: 15px;
            }

            .attendance-table td::before {
                display: none;
            }
        }
    </style>
</head>

<body>

    <div id="loader-overlay">
        <div class="loader"></div>
    </div>
    <?php if (file_exists('nav.php')) {
        require_once "nav.php";
    } ?>

    <div class="container" id="main-content" style="visibility: hidden;">
        <header class="header">
            <h2>Attendance Report</h2>
            <p><?= htmlspecialchars($selected_class) ?> - <?= htmlspecialchars($selected_stream) ?></p>
            <p id="reportDateDisplay"></p>
        </header>

        <div class="report-actions">
            <div class="form-group">
                <label for="date">Select Date:</label>
                <input type="date" id="date" name="date" value="<?= htmlspecialchars($selected_date) ?>">
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <button type="button" class="pdf-btn" id="exportPdf">Export to PDF</button>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <button type="button" class="excel-btn" id="exportExcel">Export to Excel</button>
            </div>
        </div>

        <section class="stats-container">
            <div class="stat-item stat-present">
                <div class="stat-number" id="present-count"><?= $present_count ?></div>
                <div class="stat-label">Present</div>
            </div>
            <div class="stat-item stat-absent">
                <div class="stat-number" id="absent-count"><?= $absent_count ?></div>
                <div class="stat-label">Absent</div>
            </div>
            <div class="stat-item stat-late">
                <div class="stat-number" id="late-count"><?= $late_count ?></div>
                <div class="stat-label">Late</div>
            </div>
            <div class="stat-item stat-unmarked">
                <div class="stat-number" id="not-marked-count"><?= $not_marked ?></div>
                <div class="stat-label">Not Marked</div>
            </div>
        </section>
        <div class="search-container">
            <input type="text" id="searchInput" class="search-box" placeholder="Search by Student ID or Name...">
        </div>
        <main class="table-container">
            <table class="attendance-table" id="attendanceTable">
                <thead>
                    <tr>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Status</th>
                        <th>Date Marked</th>
                        <th>Time Marked</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($students) > 0): ?>
                        <?php foreach ($students as $student): ?>
                            <tr data-date="<?= $student['attendance_time'] ? date('Y-m-d', strtotime($student['attendance_time'])) : '' ?>">
                                <td data-label="Student ID" class="student-id"><?= htmlspecialchars($student['student_id']) ?></td>
                                <td data-label="Student Name"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></td>
                                <td data-label="Status"><span class="status-badge <?= getStatusBadgeClass($student['attendance_status']) ?>"><?= htmlspecialchars($student['attendance_status']) ?></span></td>
                                <td data-label="Date Marked" class="attendance-date"><?= $student['attendance_time'] ? date('M d, Y', strtotime($student['attendance_time'])) : 'N/A' ?></td>
                                <td data-label="Time Marked" class="attendance-time"><?= $student['attendance_time'] ? date('g:i A', strtotime($student['attendance_time'])) : 'N/A' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding: 40px;">No students found for this class/stream.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tbody id="noResultsBody" style="display: none;">
                    <tr class="no-results-row">
                        <td colspan="5" class="no-results">No students found matching your search criteria.</td>
                    </tr>
                </tbody>
            </table>
        </main>
    </div>

    <div class="notification" id="notification"></div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        // --- Hide loader and show content ---
        document.getElementById('loader-overlay').style.display = 'none';
        document.getElementById('main-content').style.visibility = 'visible';

        // --- Initialize variables ---
        const dateInput = document.getElementById('date');
        const searchInput = document.getElementById('searchInput');
        const table = document.getElementById('attendanceTable');
        // Select only the main tbody, not the 'noResultsBody'
        const tableBody = table.querySelector('tbody:not(#noResultsBody)');
        const noResultsBody = document.getElementById('noResultsBody');
        const allRows = Array.from(tableBody.querySelectorAll('tr'));
        const notification = document.getElementById('notification');
        const reportDateDisplay = document.getElementById('reportDateDisplay');

        // --- Notification System ---
        function showNotification(message) {
            notification.textContent = message;
            notification.classList.add('show');
            setTimeout(() => {
                notification.classList.remove('show');
            }, 3000);
        }

        // --- Client-Side Filtering Function ---
        function filterTable() {
            const selectedDate = dateInput.value;
            const searchTerm = searchInput.value.toLowerCase().trim();
            
            // Unmarked students are conceptually part of any day they weren't marked.
            const unmarkedStudents = new Set();
            allRows.forEach(row => {
                if (row.dataset.date === '') {
                    unmarkedStudents.add(row.querySelector('.student-id').textContent);
                }
            });

            let visibleRows = new Map();

            allRows.forEach(row => {
                const studentIdText = row.querySelector('.student-id').textContent;
                const studentNameText = row.querySelector('td[data-label="Student Name"]').textContent.toLowerCase();
                const rowDate = row.dataset.date;

                const searchMatch = searchTerm === '' || studentIdText.toLowerCase().includes(searchTerm) || studentNameText.includes(searchTerm);
                
                if (searchMatch) {
                    // A row is a candidate if its date matches the selected date
                    if (rowDate === selectedDate) {
                        visibleRows.set(studentIdText, row);
                    }
                    // Or if the student is unmarked and we haven't found a marked record for them on this day
                    else if (unmarkedStudents.has(studentIdText) && !visibleRows.has(studentIdText)) {
                         visibleRows.set(studentIdText, row);
                    }
                }
            });

            let visibleCount = 0;
            let present = 0, absent = 0, late = 0;

            // Hide all rows first
            allRows.forEach(row => row.style.display = 'none');

            // Then show only the filtered rows
            visibleRows.forEach(row => {
                row.style.display = ''; // Reverts to default (block or table-row)
                visibleCount++;
                const status = row.querySelector('.status-badge').textContent;
                 switch (status) {
                    case 'present': present++; break;
                    case 'absent': absent++; break;
                    case 'late': late++; break;
                }
            });

            // Update stats
            document.getElementById('present-count').textContent = present;
            document.getElementById('absent-count').textContent = absent;
            document.getElementById('late-count').textContent = late;
            document.getElementById('not-marked-count').textContent = visibleCount - (present + absent + late);

            // Show/hide no results message
            noResultsBody.style.display = (visibleCount === 0) ? '' : 'none';

            // Update header date
            const dateObj = new Date(selectedDate + 'T00:00:00');
            reportDateDisplay.textContent = dateObj.toLocaleDateString('en-US', {
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
            });
        }

        // --- Event Listeners ---
        // Filter when date or search term changes
        dateInput.addEventListener('change', filterTable);
        searchInput.addEventListener('input', filterTable);

        // --- PDF Export ---
        document.getElementById('exportPdf').addEventListener('click', () => {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            const dateStr = dateInput.value;
            const title = "Attendance Report";
            const className = "Class: <?= htmlspecialchars($selected_class) ?> - <?= htmlspecialchars($selected_stream) ?>";
            const reportDate = "Date: " + reportDateDisplay.textContent;
            const fileName = `Attendance-Report_<?= htmlspecialchars($selected_class) ?>-<?= htmlspecialchars($selected_stream) ?>_${dateStr}.pdf`;

            doc.setFontSize(18);
            doc.text(title, 14, 22);
            doc.setFontSize(11);
            doc.text(className, 14, 32);
            doc.text(reportDate, 14, 38);

            // Use autoTable on a CLONED table to include only visible rows
            const tableClone = table.cloneNode(true);
            Array.from(tableClone.querySelectorAll("tr")).forEach(row => {
                if (row.style.display === "none") {
                    row.remove();
                }
            });

            doc.autoTable({
                html: tableClone,
                startY: 45,
                theme: 'grid',
                headStyles: { fillColor: [46, 125, 50] },
            });

            doc.save(fileName);
            showNotification('PDF exported successfully!');
        });

        // --- Excel Export ---
        document.getElementById('exportExcel').addEventListener('click', () => {
            const dateStr = dateInput.value;
            const fileName = `Attendance-Report_<?= htmlspecialchars($selected_class) ?>-<?= htmlspecialchars($selected_stream) ?>_${dateStr}.xlsx`;
            
            // Create a new workbook from a cloned table containing only visible rows
            const tableClone = table.cloneNode(true);
            Array.from(tableClone.querySelectorAll("tr")).forEach(row => {
                if (row.style.display === "none") {
                    row.remove();
                }
            });
            // Also remove the "no results" body if it's there
            tableClone.querySelector('#noResultsBody')?.remove();

            const wb = XLSX.utils.table_to_book(tableClone, { sheet: "Attendance Report" });
            XLSX.writeFile(wb, fileName);
            showNotification('Excel exported successfully!');
        });
        
        // --- Initial Filter on Page Load ---
        filterTable();
    });
    </script>
</body>

</html>