<?php
require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("View staff attendance report");

// Get the date from the URL, or default to today.
// The date filter now correctly uses the 'date' GET parameter.
$selected_date = date('Y-m-d');
if (!empty($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
    $selected_date = $_GET['date'];
}

// Track action
if (isset($tracker)) {
    $tracker->trackAction("Viewed Staff Attendance Report for Date: $selected_date");
}

$staff_members = [];

// --- SQL query to fetch attendance for the selected date ---
// The query correctly fetches data based on the selected date.
$sql = "SELECT s.staff_id, s.first_name, s.last_name,
               COALESCE(sa.status, 'unmarked') AS attendance_status,
               sa.created_at AS attendance_time
        FROM staff s
        LEFT JOIN staff_attendance sa ON s.staff_id = sa.staff_id AND sa.date = ?
        WHERE s.Status = 'active'
        ORDER BY s.last_name, s.first_name";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("s", $selected_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $staff_members = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    error_log("Error preparing statement: " . $conn->error);
    die("A database error occurred. Please try again later.");
}

// --- Calculate attendance statistics ---
$present_count = 0;
$absent_count = 0;
$late_count = 0;
$on_leave_count = 0;
$not_marked = 0;

foreach ($staff_members as $staff) {
    switch ($staff['attendance_status']) {
        case 'present':
            $present_count++;
            break;
        case 'absent':
            $absent_count++;
            break;
        case 'late':
            $late_count++;
            break;
        case 'on_leave':
            $on_leave_count++;
            break;
        default:
            $not_marked++;
            break;
    }
}

// Helper function to generate CSS class for status badges
function getStatusBadgeClass($status)
{
    switch ($status) {
        case 'present':
            return 'status-badge-present';
        case 'absent':
            return 'status-badge-absent';
        case 'late':
            return 'status-badge-late';
        case 'on_leave':
            return 'status-badge-on-leave';
        default:
            return 'status-badge-unmarked';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Attendance Report</title>
    <!-- jsPDF and SheetJS libraries for exporting -->
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
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        .container {
            max-width: 100%;
            margin: 20px auto;
            margin-top: 55px;
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
            margin-bottom: 5px;
        }

        .header p {
            font-size: 1rem;
            opacity: 0.9;
        }

        .report-actions {
            background: var(--light-bg);
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-start;
            /* Aligned to the start */
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

        .stat-on-leave .stat-number {
            color: var(--on-leave-color);
        }

        .stat-unmarked .stat-number {
            color: var(--unmarked-color);
        }

        .stat-label {
            font-size: 0.8rem;
            color: #666;
            text-transform: uppercase;
        }

        .table-controls {
            padding: 10px 20px;
            background: var(--light-bg);
            border-bottom: 1px solid var(--border-color);
        }

        .search-bar {
            width: 100%;
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #ccc;
            font-size: 1rem;
        }

        .table-container {
            padding: 20px;
            overflow-x: auto;
        }

        .attendance-table {
            width: 100%;
            border-collapse: collapse;
        }

        .attendance-table th,
        .attendance-table td {
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            text-align: left;
        }

        .attendance-table th {
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

        .no-results {
            display: none;
            text-align: center;
            background-color: #f9f9f9;
            font-style: italic;
            color: #666;
        }
    </style>
</head>

<body>
    <?php if (file_exists('nav.php')) {
        require_once "nav.php";
    } ?>
    <div class="container">
        <header class="header">
            <h2>Staff Attendance Report</h2>
            <p id="reportDateDisplay"></p>
        </header>

        <div class="report-actions">
            <form id="date-form" method="GET" action="view_staff_attendance_report.php" class="form-group">
                <label for="date">Select Report Date:</label>
                <input type="date" id="date" name="date" value="<?= htmlspecialchars($selected_date) ?>" onchange="this.form.submit()">
            </form>
            <div class="form-group"><label>&nbsp;</label><button type="button" id="exportPdf" class="pdf-btn">Export to PDF</button></div>
            <div class="form-group"><label>&nbsp;</label><button type="button" id="exportExcel" class="excel-btn">Export to Excel</button></div>
        </div>

        <section class="stats-container">
            <div class="stat-item stat-present">
                <div class="stat-number"><?= $present_count ?></div>
                <div class="stat-label">Present</div>
            </div>
            <div class="stat-item stat-absent">
                <div class="stat-number"><?= $absent_count ?></div>
                <div class="stat-label">Absent</div>
            </div>
            <div class="stat-item stat-late">
                <div class="stat-number"><?= $late_count ?></div>
                <div class="stat-label">Late</div>
            </div>
            <div class="stat-item stat-on-leave">
                <div class="stat-number"><?= $on_leave_count ?></div>
                <div class="stat-label">On Leave</div>
            </div>
            <div class="stat-item stat-unmarked">
                <div class="stat-number"><?= $not_marked ?></div>
                <div class="stat-label">Not Marked</div>
            </div>
        </section>

        <div class="table-controls">
            <input type="text" id="search-input" class="search-bar" placeholder="Search by name or ID...">
        </div>

        <main class="table-container">
            <table class="attendance-table" id="attendanceTable">
                <thead>
                    <tr>
                        <th>Staff ID</th>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Time Marked</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($staff_members) > 0): ?>
                        <?php foreach ($staff_members as $staff): ?>
                            <tr>
                                <td><?= htmlspecialchars($staff['staff_id']) ?></td>
                                <td><?= htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']) ?></td>
                                <td><span class="status-badge <?= getStatusBadgeClass($staff['attendance_status']) ?>"><?= htmlspecialchars(str_replace('_', ' ', $staff['attendance_status'])) ?></span></td>
                                <td><?= $staff['attendance_time'] ? date('Y-m-d', strtotime($staff['attendance_time'])) : 'N/A' ?></td>
                                <td><?= $staff['attendance_time'] ? date('g:i A', strtotime($staff['attendance_time'])) : 'N/A' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="no-data-row">
                            <td colspan="5" style="text-align:center;padding:40px;">No attendance records found for this date.</td>
                        </tr>
                    <?php endif; ?>
                    <tr class='no-results'>
                        <td colspan='5'>No staff members match your search.</td>
                    </tr>
                </tbody>
            </table>
        </main>
    </div>
    <div class="notification" id="notification"></div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const dateStr = '<?= htmlspecialchars($selected_date) ?>';
            const dateObj = new Date(dateStr + 'T00:00:00');
            document.getElementById('reportDateDisplay').textContent = dateObj.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });

            const showNotification = (message) => {
                const el = document.getElementById('notification');
                el.textContent = message;
                el.classList.add('show');
                setTimeout(() => el.classList.remove('show'), 3000);
            };

            const searchInput = document.getElementById('search-input');
            const tableRows = document.querySelectorAll('#attendanceTable tbody tr:not(.no-results):not(.no-data-row)');
            const noResultsRow = document.querySelector('#attendanceTable tbody .no-results');

            searchInput.addEventListener('input', () => {
                const searchTerm = searchInput.value.toLowerCase();
                let visibleRows = 0;

                tableRows.forEach(row => {
                    const staffId = row.cells[0].textContent.toLowerCase();
                    const staffName = row.cells[1].textContent.toLowerCase();

                    if (staffId.includes(searchTerm) || staffName.includes(searchTerm)) {
                        row.style.display = '';
                        visibleRows++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                // Only show "no results" if there are data rows but none are visible
                if (noResultsRow) {
                    // This is the corrected line
                    noResultsRow.style.display = (tableRows.length > 0 && visibleRows === 0) ? 'table-row' : 'none';
                }
            });

            document.getElementById('exportPdf').addEventListener('click', () => {
                const {
                    jsPDF
                } = window.jspdf;
                const doc = new jsPDF();
                doc.autoTable({
                    html: '#attendanceTable',
                    startY: 35,
                    headStyles: {
                        fillColor: [46, 125, 50]
                    },
                    didDrawPage: function(data) {
                        doc.setFontSize(18);
                        doc.setTextColor(40);
                        doc.text('Staff Attendance Report', data.settings.margin.left, 22);
                        doc.setFontSize(11);
                        doc.text("Date: " + dateObj.toLocaleDateString('en-US'), data.settings.margin.left, 30);
                    },
                });
                doc.save(`Staff-Attendance-Report_${dateStr}.pdf`);
                showNotification('✅ PDF export successful!');
            });

            document.getElementById('exportExcel').addEventListener('click', () => {
                const table = document.getElementById('attendanceTable');
                const wb = XLSX.utils.table_to_book(table, {
                    sheet: "Staff Attendance"
                });
                XLSX.writeFile(wb, `Staff-Attendance-Report_${dateStr}.xlsx`);
                showNotification('✅ Excel export successful!');
            });
        });
    </script>
</body>

</html>