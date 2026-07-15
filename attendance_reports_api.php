<?php
require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Attendance reports");

// --- INITIALIZATION ---
$report_type = $_POST['report_type'] ?? 'students'; // Default to students
$date_from = $_POST['date_from'] ?? date('Y-m-d');
$date_to = $_POST['date_to'] ?? date('Y-m-d');
$selected_class = $_POST['class'] ?? '';
$selected_stream = $_POST['stream'] ?? '';
$selected_department = $_POST['department'] ?? '';

$results = [];
$stats = [
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'on_leave' => 0,
    'unmarked' => 0,
    'total' => 0
];
$report_title = "Attendance Report";

// --- DATA FETCHING FOR FILTERS ---
$classes = $conn->query("SELECT DISTINCT current_class FROM students WHERE current_class IS NOT NULL AND current_class != '' ORDER BY current_class ASC")->fetch_all(MYSQLI_ASSOC);
$departments = $conn->query("SELECT DISTINCT department FROM staff WHERE department IS NOT NULL AND department != '' ORDER BY department ASC")->fetch_all(MYSQLI_ASSOC);

// --- REPORT GENERATION LOGIC (if form is submitted) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($report_type === 'students') {
        $report_title = "Student Attendance Report ($selected_class - $selected_stream)";
        $sql = "SELECT s.student_id as id, s.first_name, s.last_name, a.date, COALESCE(a.status, 'unmarked') AS status, a.created_at as time_marked
                FROM students s
                LEFT JOIN attendance a ON s.student_id = a.student_id AND a.date BETWEEN ? AND ?
                WHERE s.current_class = ? AND s.stream = ? AND s.status = 'active'
                ORDER BY a.date, s.last_name, s.first_name";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssss", $date_from, $date_to, $selected_class, $selected_stream);
    } else { // Staff
        $report_title = "Staff Attendance Report" . ($selected_department ? " ($selected_department)" : '');
        $sql = "SELECT s.staff_id as id, s.first_name, s.last_name, sa.date, COALESCE(sa.status, 'unmarked') AS status, sa.created_at as time_marked
                FROM staff s
                LEFT JOIN staff_attendance sa ON s.staff_id = sa.staff_id AND sa.date BETWEEN ? AND ?
                WHERE s.Status = 'active'";
        if (!empty($selected_department)) {
            $sql .= " AND s.department = ?";
        }
        $sql .= " ORDER BY sa.date, s.last_name, s.first_name";
        $stmt = $conn->prepare($sql);
        if (!empty($selected_department)) {
            $stmt->bind_param("sss", $date_from, $date_to, $selected_department);
        } else {
            $stmt->bind_param("ss", $date_from, $date_to);
        }
    }

    if ($stmt) {
        $stmt->execute();
        $result_obj = $stmt->get_result();
        $results = $result_obj->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Calculate stats
        foreach ($results as $row) {
            $stats[$row['status']]++;
            $stats['total']++;
        }
    }
}

function getStatusBadgeClass($status)
{
    $classes = ['present' => 'present', 'absent' => 'absent', 'late' => 'late', 'on_leave' => 'on-leave', 'unmarked' => 'unmarked'];
    return 'status-badge-' . ($classes[$status] ?? 'unmarked');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprehensive Attendance Reports</title>
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
            --on-leave-color: #3f51b5;
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

        .container {
            max-width: 1600px;
            margin: 20px auto;
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
            font-size: 1.5rem;
            margin: 0;
        }

        .filters-container {
            background: var(--white);
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .tab-controls {
            display: flex;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 20px;
        }

        .tab-controls label {
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 600;
            color: #888;
            position: relative;
        }

        .tab-controls input[type="radio"] {
            display: none;
        }

        .tab-controls input[type="radio"]:checked+label {
            color: var(--primary-color);
            border-bottom: 3px solid var(--primary-color);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
        }

        .form-group input,
        .form-group select {
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #ccc;
            font-size: 1rem;
        }

        .form-group button {
            padding: 12px;
            border-radius: 6px;
            border: none;
            font-weight: 600;
            color: white;
            background: var(--primary-gradient);
            cursor: pointer;
        }

        #student-filters,
        #staff-filters {
            display: none;
        }

        .results-container {
            padding: 20px;
        }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .results-header h3 {
            margin: 0;
        }

        .export-buttons button {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            color: white;
            font-weight: 600;
            cursor: pointer;
            margin-left: 10px;
        }

        .pdf-btn {
            background-color: var(--pdf-color);
        }

        .excel-btn {
            background-color: var(--excel-color);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            padding: 15px 0;
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

        .stat-label {
            font-size: 0.8rem;
            color: #666;
            text-transform: uppercase;
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

        .stat-on-leave .stat-number {
            color: var(--on-leave-color);
        }

        .stat-unmarked .stat-number {
            color: var(--unmarked-color);
        }

        .table-container {
            overflow-x: auto;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
        }

        .report-table th,
        .report-table td {
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            text-align: left;
        }

        .report-table th {
            background: var(--primary-gradient);
            color: white;
            text-transform: uppercase;
            font-size: 14px;
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

        .status-badge-on-leave {
            background-color: var(--on-leave-color);
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
            transition: opacity 0.3s, transform 0.3s;
            transform: translateY(-20px);
        }

        .notification.show {
            opacity: 1;
            transform: translateY(0);
        }
    </style>
</head>

<body>
    <?php if (file_exists('nav.php')) {
        require_once "nav.php";
    } ?>
    <div class="container">
        <header class="header">
            <h2>Comprehensive Attendance Reports</h2>
        </header>

        <div class="filters-container">
            <form method="POST" action="attendance_reports.php">
                <div class="tab-controls">
                    <input type="radio" name="report_type" id="type_students" value="students" <?= $report_type === 'students' ? 'checked' : '' ?>>
                    <label for="type_students">Students</label>
                    <input type="radio" name="report_type" id="type_staff" value="staff" <?= $report_type === 'staff' ? 'checked' : '' ?>>
                    <label for="type_staff">Staff</label>
                </div>

                <div class="filter-grid">
                    <div class="form-group"><label for="date_from">From</label><input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" required></div>
                    <div class="form-group"><label for="date_to">To</label><input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" required></div>
                </div>

                <div id="student-filters" class="filter-grid" style="margin-top: 20px;">
                    <div class="form-group">
                        <label for="class">Class</label>
                        <select name="class">
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class) echo "<option value='{$class['current_class']}' " . ($selected_class == $class['current_class'] ? 'selected' : '') . ">{$class['current_class']}</option>"; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="stream">Stream</label>
                        <input type="text" name="stream" placeholder="Enter Stream" value="<?= htmlspecialchars($selected_stream) ?>">
                    </div>
                </div>

                <div id="staff-filters" class="filter-grid" style="margin-top: 20px;">
                    <div class="form-group">
                        <label for="department">Department</label>
                        <select name="department">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept) echo "<option value='{$dept['department']}' " . ($selected_department == $dept['department'] ? 'selected' : '') . ">{$dept['department']}</option>"; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 20px;"><button type="submit">Generate Report</button></div>
            </form>
        </div>

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <div class="results-container">
                <div class="results-header">
                    <h3><?= htmlspecialchars($report_title) ?></h3>
                    <div class="export-buttons">
                        <button type="button" id="exportPdf" class="pdf-btn">Export PDF</button>
                        <button type="button" id="exportExcel" class="excel-btn">Export Excel</button>
                    </div>
                </div>

                <section class="stats-container">
                    <div class="stat-item stat-present">
                        <div class="stat-number"><?= $stats['present'] ?></div>
                        <div class="stat-label">Present</div>
                    </div>
                    <div class="stat-item stat-absent">
                        <div class="stat-number"><?= $stats['absent'] ?></div>
                        <div class="stat-label">Absent</div>
                    </div>
                    <div class="stat-item stat-late">
                        <div class="stat-number"><?= $stats['late'] ?></div>
                        <div class="stat-label">Late</div>
                    </div>
                    <?php if ($report_type === 'staff'): ?>
                        <div class="stat-item stat-on-leave">
                            <div class="stat-number"><?= $stats['on_leave'] ?></div>
                            <div class="stat-label">On Leave</div>
                        </div>
                    <?php endif; ?>
                    <div class="stat-item stat-unmarked">
                        <div class="stat-number"><?= $stats['unmarked'] ?></div>
                        <div class="stat-label">Not Marked</div>
                    </div>
                </section>

                <main class="table-container">
                    <table class="report-table" id="reportTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Status</th>
                                <th>Time Marked</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($results) > 0): ?>
                                <?php foreach ($results as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['date']) ?></td>
                                        <td><?= htmlspecialchars($row['id']) ?></td>
                                        <td><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></td>
                                        <td><span class="status-badge <?= getStatusBadgeClass($row['status']) ?>"><?= htmlspecialchars(str_replace('_', ' ', $row['status'])) ?></span></td>
                                        <td><?= $row['time_marked'] ? date('g:i A', strtotime($row['time_marked'])) : 'N/A' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align:center;padding:40px;">No records found for the selected criteria.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </main>
            </div>
        <?php endif; ?>
    </div>
    <div class="notification" id="notification"></div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const studentFilters = document.getElementById('student-filters');
            const staffFilters = document.getElementById('staff-filters');
            const studentRadio = document.getElementById('type_students');
            const staffRadio = document.getElementById('type_staff');

            function toggleFilters() {
                studentFilters.style.display = studentRadio.checked ? 'grid' : 'none';
                staffFilters.style.display = staffRadio.checked ? 'grid' : 'none';
            }

            studentRadio.addEventListener('change', toggleFilters);
            staffRadio.addEventListener('change', toggleFilters);
            toggleFilters(); // Set initial state on page load

            // --- Export Logic ---
            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                const showNotification = (message) => {
                    const el = document.getElementById('notification');
                    el.textContent = message;
                    el.classList.add('show');
                    setTimeout(() => el.classList.remove('show'), 3000);
                };

                document.getElementById('exportPdf').addEventListener('click', () => {
                    const {
                        jsPDF
                    } = window.jspdf;
                    const doc = new jsPDF();
                    const reportTitle = "<?= htmlspecialchars($report_title) ?>";
                    const dateRange = "From: <?= htmlspecialchars($date_from) ?> To: <?= htmlspecialchars($date_to) ?>";

                    doc.setFontSize(18);
                    doc.text(reportTitle, 14, 22);
                    doc.setFontSize(11);
                    doc.text(dateRange, 14, 30);

                    doc.autoTable({
                        html: '#reportTable',
                        startY: 35,
                        headStyles: {
                            fillColor: [46, 125, 50]
                        }
                    });
                    doc.save(`${reportTitle.replace(/ /g, '_')}.pdf`);
                    showNotification('✅ PDF export successful!');
                });

                document.getElementById('exportExcel').addEventListener('click', () => {
                    const table = document.getElementById('reportTable');
                    const wb = XLSX.utils.table_to_book(table, {
                        sheet: "Attendance Report"
                    });
                    const reportTitle = "<?= htmlspecialchars($report_title) ?>";
                    XLSX.writeFile(wb, `${reportTitle.replace(/ /g, '_')}.xlsx`);
                    showNotification('✅ Excel export successful!');
                });
            <?php endif; ?>
        });
    </script>
</body>

</html>