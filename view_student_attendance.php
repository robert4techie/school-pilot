<?php
require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("View student attendance");

// Sanitize GET parameters
$selected_class = filter_input(INPUT_GET, 'class', FILTER_SANITIZE_STRING);
$selected_stream = filter_input(INPUT_GET, 'stream', FILTER_SANITIZE_STRING);

// Get the date from the URL, or default to today. Validate the date format.
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
    $tracker->trackAction("Viewed Attendance Report for Class: $selected_class, Stream: $selected_stream, Date: $selected_date");
}

$students = [];

// --- SQL query to fetch attendance for the selected date ---
$sql = "SELECT s.student_id, s.first_name, s.last_name, 
               COALESCE(a.status, 'unmarked') AS attendance_status,
               a.created_at AS attendance_time
        FROM students s
        LEFT JOIN attendance a ON s.student_id = a.student_id AND a.date = ?
        WHERE s.current_class = ? AND s.stream = ? AND s.status = 'active'
        ORDER BY s.last_name, s.first_name";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("sss", $selected_date, $selected_class, $selected_stream);
    $stmt->execute();
    $result = $stmt->get_result();
    $students = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    error_log("Error preparing statement: " . $conn->error);
    die("A database error occurred. Please try again later.");
}

// --- Calculate attendance statistics ---
$total_students = count($students);
$present_count = 0;
$absent_count = 0;
$late_count = 0;
$not_marked = 0;

foreach ($students as $student) {
    switch ($student['attendance_status']) {
        case 'present':
            $present_count++;
            break;
        case 'absent':
            $absent_count++;
            break;
        case 'late':
            $late_count++;
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
    <title>Attendance Report: <?= htmlspecialchars($selected_class) ?> - <?= htmlspecialchars($selected_stream) ?></title>
    <style>
        /* --- Base styles are similar to the recording page for consistency --- */
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
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--light-bg);
            padding: 10px;
        }

        .container {
            max-width: 100%;
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
            font-size: 1.25rem;
        }

        /* --- NEW: Form for Date Selection & Actions --- */
        .report-actions {
            background: var(--light-bg);
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
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

        .view-btn {
            background-color: var(--primary-color);
        }

        .print-btn {
            background-color: #337ab7;
        }

        .view-btn:hover {
            background-color: #256428;
        }

        .print-btn:hover {
            background-color: #286090;
        }

        .report-actions button:active {
            transform: scale(0.98);
        }

        /* --- Stats are the same --- */
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

        /* --- Responsive Table (Card view on mobile) --- */
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

        .attendance-time {
            font-size: 0.8rem;
            color: #666;
            font-style: italic;
        }

        /* --- NEW: Status Badge for Read-Only View --- */
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

        /* --- Desktop and Tablet Styles --- */
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

        /* --- Print-Specific Styles --- */
        @media print {
            body {
                padding: 0;
                background: var(--white);
            }

            .no-print,
            .report-actions,
            nav {
                display: none !important;
            }

            .container {
                box-shadow: none;
                border: none;
                margin: 0;
                max-width: 100%;
            }

            .header {
                background: none;
                color: black;
                padding: 20px 0;
                text-align: left;
            }

            .header h2::after {
                content: " - <?= htmlspecialchars($selected_class) ?> | <?= htmlspecialchars($selected_stream) ?>";
            }

            .header p {
                display: block;
            }

            .attendance-table {
                font-size: 12pt;
            }

            .attendance-table th {
                background: #eee !important;
                color: black !important;
                -webkit-print-color-adjust: exact;
            }

            .status-badge {
                color: black !important;
                border: 1px solid #ccc;
                background: transparent !important;
                -webkit-print-color-adjust: exact;
            }
        }
    </style>
</head>

<body>
    <?php if (file_exists('nav.php')) {
        require_once "nav.php";
    } ?>
    <div class="container">
        <header class="header">
            <h2>Attendance Report</h2>
            <p id="reportDateDisplay"></p>
        </header>

        <form method="GET" action="view_attendance_report.php" class="report-actions">
            <input type="hidden" name="class" value="<?= htmlspecialchars($selected_class) ?>">
            <input type="hidden" name="stream" value="<?= htmlspecialchars($selected_stream) ?>">
            <div class="form-group">
                <label for="date">Select Date:</label>
                <input type="date" id="date" name="date" value="<?= htmlspecialchars($selected_date) ?>">
            </div>
            <div class="form-group">
                <label>&nbsp;</label> <!-- Spacer for alignment -->
                <button type="submit" class="view-btn">View Report</button>
            </div>
            <div class="form-group">
                <label>&nbsp;</label> <!-- Spacer for alignment -->
                <button type="button" class="print-btn" onclick="window.print()">Print Report</button>
            </div>
        </form>

        <section class="stats-container">
            <!-- Stats are populated by PHP -->
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
            <div class="stat-item stat-unmarked">
                <div class="stat-number"><?= $not_marked ?></div>
                <div class="stat-label">Not Marked</div>
            </div>
        </section>

        <main class="table-container">
            <table class="attendance-table">
                <thead>
                    <tr>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Status</th>
                        <th>Time Marked</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($students) > 0): ?>
                        <?php foreach ($students as $student): ?>
                            <tr>
                                <td data-label="Student ID" class="student-id"><?= htmlspecialchars($student['student_id']) ?></td>
                                <td data-label="Student Name"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></td>
                                <td data-label="Status">
                                    <span class="status-badge <?= getStatusBadgeClass($student['attendance_status']) ?>">
                                        <?= htmlspecialchars($student['attendance_status']) ?>
                                    </span>
                                </td>
                                <td data-label="Time Marked" class="attendance-time">
                                    <?= $student['attendance_time'] ? date('g:i A', strtotime($student['attendance_time'])) : 'N/A' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align:center; padding: 40px;">No students found for this class/stream.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </main>
    </div>

    <script>
        // Display the selected date in a nice format in the header
        document.addEventListener('DOMContentLoaded', () => {
            const dateStr = '<?= htmlspecialchars($selected_date) ?>';
            const dateObj = new Date(dateStr + 'T00:00:00');
            document.getElementById('reportDateDisplay').textContent = dateObj.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        });
    </script>
</body>

</html>