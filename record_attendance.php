<?php


require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';

// Generate CSRF token
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
    // Sanitize and validate inputs
    $selected_class = filter_input(INPUT_GET, 'class', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $selected_stream = filter_input(INPUT_GET, 'stream', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    if (!$selected_class || !$selected_stream) {
        header('Location: select_attendance_class.php');
        exit();
    }

    // Track action
    if (isset($tracker)) {
        $tracker->trackAction("Viewed Attendance for Class: $selected_class, Stream: $selected_stream");
    }

    $today_date = date('Y-m-d');

    // Fetch students with attendance
    $sql = "SELECT s.student_id, s.first_name, s.last_name, 
                   COALESCE(a.status, '') AS attendance_status,
                   a.created_at AS attendance_time,
                   a.remarks,
                   a.version
            FROM students s
            LEFT JOIN attendance a ON s.student_id = a.student_id AND a.date = ?
            WHERE s.current_class = ? AND s.stream = ? AND s.status = 'active'
            ORDER BY s.last_name, s.first_name";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Database error. Please try again later.");
    }

    $stmt->bind_param("sss", $today_date, $selected_class, $selected_stream);
    $stmt->execute();
    $result = $stmt->get_result();
    $students = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Calculate statistics
    $total_students = count($students);
    $present_count = 0;
    $absent_count = 0;
    $late_count = 0;
    $sick_count = 0;
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
            case 'sick':
                $sick_count++;
                break;
            default:
                $not_marked++;
                break;
        }
    }

    function getStatusClass($status)
    {
        switch ($status) {
            case 'present':
                return 'status-present';
            case 'absent':
                return 'status-absent';
            case 'late':
                return 'status-late';
            case 'sick':
                return 'status-sick';
            default:
                return '';
        }
    }
} catch (Exception $e) {
    error_log("Record Attendance Error: " . $e->getMessage());
    die("An error occurred. Please try again or contact support.");
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance: <?= htmlspecialchars($selected_class) ?> - <?= htmlspecialchars($selected_stream) ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        :root {
            --primary-color: #2e7d32;
            --present-color: #4caf50;
            --absent-color: #f44336;
            --late-color: #ff9800;
            --sick-color: #9c27b0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;

        }

        body {
            background: linear-gradient(135deg, #e8f5e8 0%, #f0fff0 100%);
            min-height: 100vh;
            padding: 10px;
        }

        .container {
            max-width: 100%;
            margin: 20px auto;
            margin-top: 55px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #2e7d32 0%, #4caf50 100%);
            color: white;
            padding: 20px;
            text-align: center;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 15px;
            padding: 20px;
            background: #f8f9fa;
        }

        .stat-item {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
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

        .stat-sick .stat-number {
            color: var(--sick-color);
        }

        .stat-unmarked .stat-number {
            color: #757575;
        }

        .toolbar {
            padding: 20px;
            background: #f8f9fa;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }

        .search-input {
            font-family: "Sen", sans-serif !important;
            flex: 1;
            min-width: 200px;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
        }

        .btnn {
            font-family: "Sen", sans-serif !important;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btnn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btnn-primary:hover {
            background: #1b5e20;
            transform: translateY(-2px);
        }

        .btnn-secondary {
            background: #2196f3;
            color: white;
        }

        .btnn-secondary:hover {
            background: #1976d2;
        }

        .btnn-danger {
            background: var(--absent-color);
            color: white;
        }

        .btnn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .table-container {
            padding: 20px;
            overflow-x: auto;
        }

        .attendance-table {
            width: 100%;
            border-collapse: collapse;
        }

        .attendance-table th {
            background: var(--primary-color);
            color: white;
            padding: 15px;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .attendance-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }

        .attendance-table tr:hover {
            background: #f5f5f5;
        }

        .attendance-table tr.row-edited {
            background: #fff3e0;
        }

        .status-dropdown {
            padding: 8px 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            min-width: 120px;
        }

        .status-present {
            background: #e8f5e9;
            border-color: #a5d6a7;
        }

        .status-absent {
            background: #ffebee;
            border-color: #ef9a9a;
        }

        .status-late {
            background: #fff3e0;
            border-color: #ffcc80;
        }

        .status-sick {
            background: #f3e5f5;
            border-color: #ce93d8;
        }

        .time-badge {
            display: inline-block;
            padding: 4px 8px;
            background: #e3f2fd;
            border-radius: 4px;
            font-size: 12px;
            color: #1976d2;
        }

        .checkbox-cell {
            text-align: center;
            width: 50px;
        }

        .student-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s;
        }

        .modal-header {
            margin-bottom: 20px;
        }

        .modal-header h3 {
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }

        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-family: inherit;
            resize: vertical;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .message-popup {
            position: fixed;
            top: 80px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transform: translateX(400px);
            transition: transform 0.3s;
            z-index: 1000;
            max-width: 300px;
        }

        .message-popup.show {
            transform: translateX(0);
        }

        .success-message {
            background: var(--present-color);
        }

        .error-message {
            background: var(--absent-color);
        }

        .hidden {
            display: none !important;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @media (max-width: 768px) {
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .btnn {
                width: 100%;
            }

            .attendance-table thead {
                display: none;
            }

            .attendance-table tr {
                display: block;
                margin-bottom: 15px;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 10px;
            }

            .attendance-table td {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border: none;
            }

            .attendance-table td::before {
                content: attr(data-label);
                font-weight: bold;
                color: #555;
            }

            .checkbox-cell::before {
                content: 'Select';
            }
        }
    </style>
</head>

<body>
    <?php if (file_exists('nav.php')) require_once "nav.php"; ?>

    <div class="container">
        <header class="header">
            <h2>Attendance Registration</h2>
            <p><?= htmlspecialchars($selected_class) ?> | <?= htmlspecialchars($selected_stream) ?></p>
            <div><?= date('l, F j, Y') ?></div>
        </header>

        <section class="stats-container">
            <div class="stat-item stat-present">
                <div class="stat-number" id="presentCount"><?= $present_count ?></div>
                <div>Present</div>
            </div>
            <div class="stat-item stat-absent">
                <div class="stat-number" id="absentCount"><?= $absent_count ?></div>
                <div>Absent</div>
            </div>
            <div class="stat-item stat-late">
                <div class="stat-number" id="lateCount"><?= $late_count ?></div>
                <div>Late</div>
            </div>
            <div class="stat-item stat-sick">
                <div class="stat-number" id="sickCount"><?= $sick_count ?></div>
                <div>Sick</div>
            </div>
            <div class="stat-item stat-unmarked">
                <div class="stat-number" id="unmarkedCount"><?= $not_marked ?></div>
                <div>Not Marked</div>
            </div>
        </section>

        <section class="toolbar">
            <input type="text" class="search-input" id="studentSearch"
                placeholder="Search by student name or ID..." autocomplete="off">

            <button class="btnn btnn-secondary hidden" id="clearSearchBtn"
                style="background: #757575;" onclick="clearSearch()">
                ✕ Clear Search
            </button>

            <button class="btnn btnn-primary" id="markAllPresentbtnn">
                ✓ Mark All Present
            </button>

            <button class="btnn btnn-secondary" id="markSelectedbtnn" disabled>
                ✓ Mark Selected
            </button>

            <button class="btnn btnn-secondary" id="exportExcelbtnn">
                Export Excel
            </button>
        </section>

        <main class="table-container">
            <table class="attendance-table" id="attendanceTable">
                <thead>
                    <tr>
                        <th class="checkbox-cell">
                            <input type="checkbox" id="selectAllCheckbox" class="student-checkbox">
                        </th>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Status</th>
                        <th>Time</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($students) > 0): ?>
                        <?php foreach ($students as $student): ?>
                            <tr data-student-name="<?= htmlspecialchars(strtolower($student['first_name'] . ' ' . $student['last_name'])) ?>"
                                data-student-id="<?= htmlspecialchars(strtolower($student['student_id'])) ?>">
                                <td class="checkbox-cell" data-label="Select">
                                    <input type="checkbox" class="student-checkbox"
                                        data-student-id="<?= htmlspecialchars($student['student_id']) ?>">
                                </td>
                                <td data-label="Student ID"><?= htmlspecialchars($student['student_id']) ?></td>
                                <td data-label="Name"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></td>
                                <td data-label="Status">
                                    <select class="status-dropdown <?= getStatusClass($student['attendance_status']) ?>"
                                        data-student-id="<?= htmlspecialchars($student['student_id']) ?>"
                                        onchange="handleStatusChange(this)">
                                        <option value="">Select...</option>
                                        <option value="present" <?= $student['attendance_status'] == 'present' ? 'selected' : '' ?>>Present</option>
                                        <option value="absent" <?= $student['attendance_status'] == 'absent' ? 'selected' : '' ?>>Absent</option>
                                        <option value="late" <?= $student['attendance_status'] == 'late' ? 'selected' : '' ?>>Late</option>
                                        <option value="sick" <?= $student['attendance_status'] == 'sick' ? 'selected' : '' ?>>Sick</option>
                                    </select>
                                </td>
                                <td data-label="Time">
                                    <?php if (!empty($student['attendance_time'])): ?>
                                        <span class="time-badge">
                                            <?= date('g:i A', strtotime($student['attendance_time'])) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Remarks">
                                    <span class="remarks-text">
                                        <?= !empty($student['remarks']) ? htmlspecialchars($student['remarks']) : '-' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align:center;padding:40px;">No students found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </main>
    </div>

    <!-- Remarks Modal -->
    <div id="remarksModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Remarks/Reason</h3>
                <p id="modalStudentInfo" style="color: #666;"></p>
            </div>
            <div class="form-group">
                <label for="remarksInput">Reason for absence/lateness/sickness: <span style="color: red;">*</span></label>
                <textarea id="remarksInput" rows="4" required
                    placeholder="E.g., Medical appointment, Family emergency, Illness..."></textarea>
            </div>
            <div class="modal-actions">
                <button class="btnn btnn-secondary" onclick="closeRemarksModal()">Cancel</button>
                <button class="btnn btnn-primary" onclick="saveWithRemarks()">Save</button>
            </div>
        </div>
    </div>

    <!-- Bulk Action Modal -->
    <div id="bulkActionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Mark Selected Students</h3>
                <p id="selectedCountText" style="color: #666;"></p>
            </div>
            <div class="form-group">
                <label for="bulkStatus">Select Status:</label>
                <select id="bulkStatus" class="status-dropdown" style="width: 100%;">
                    <option value="">-- Choose Status --</option>
                    <option value="present">Present</option>
                    <option value="absent">Absent</option>
                    <option value="late">Late</option>
                    <option value="sick">Sick</option>
                </select>
            </div>
            <div class="form-group" id="bulkRemarksGroup">
                <label for="bulkRemarks">Remarks <span id="bulkRequiredIndicator" style="color: red; display: none;">*</span>:</label>
                <textarea id="bulkRemarks" rows="3"
                    placeholder="Optional: Add common remarks for all selected students..."></textarea>
            </div>
            <div class="modal-actions">
                <button class="btnn btnn-secondary" onclick="closeBulkModal()">Cancel</button>
                <button class="btnn btnn-primary" onclick="executeBulkMark()">Mark All</button>
            </div>
        </div>
    </div>

    <div id="successMessage" class="message-popup success-message"></div>
    <div id="errorMessage" class="message-popup error-message"></div>

    <script>
        const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
        const currentDate = '<?= $today_date ?>';
        const LOGGED_USER = '<?= $_SESSION['username'] ?? 'Unknown' ?>';

        let pendingUpdate = null; // Store dropdown awaiting remarks

        // ========== UTILITY FUNCTIONS ==========
        function showMessage(element, message, isSuccess) {
            element.textContent = (isSuccess ? '✓ ' : '✗ ') + message;
            element.classList.add('show');
            setTimeout(() => element.classList.remove('show'), 4000);
        }

        function updateStats() {
            const rows = document.querySelectorAll('#attendanceTable tbody tr:not(.hidden)');
            let present = 0,
                absent = 0,
                late = 0,
                sick = 0,
                unmarked = 0;

            rows.forEach(row => {
                const dropdown = row.querySelector('.status-dropdown');
                if (!dropdown) return;

                switch (dropdown.value) {
                    case 'present':
                        present++;
                        break;
                    case 'absent':
                        absent++;
                        break;
                    case 'late':
                        late++;
                        break;
                    case 'sick':
                        sick++;
                        break;
                    default:
                        unmarked++;
                        break;
                }
            });

            document.getElementById('presentCount').textContent = present;
            document.getElementById('absentCount').textContent = absent;
            document.getElementById('lateCount').textContent = late;
            document.getElementById('sickCount').textContent = sick;
            document.getElementById('unmarkedCount').textContent = unmarked;
        }

        // ========== ATTENDANCE UPDATE ==========
        function handleStatusChange(selectElement) {
            const status = selectElement.value;
            if (!status) return;

            // If absent, late, or sick - ask for remarks
            if (['absent', 'late', 'sick'].includes(status)) {
                pendingUpdate = selectElement;
                openRemarksModal(selectElement);
            } else {
                // For present, save directly
                updateAttendance(selectElement, '');
            }
        }

        async function updateAttendance(selectElement, remarks = '') {
            const studentId = selectElement.dataset.studentId;
            const status = selectElement.value;
            if (!status) return;

            selectElement.disabled = true;
            const row = selectElement.closest('tr');
            row.style.opacity = '0.6';

            const formData = new FormData();
            formData.append('student_id', studentId);
            formData.append('status', status);
            formData.append('date', currentDate);
            formData.append('remarks', remarks);
            formData.append('recorded_by', LOGGED_USER);
            formData.append('csrf_token', CSRF_TOKEN);

            try {
                const response = await fetch('api/update_attendance.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`Server error: ${response.status}`);
                }

                const data = await response.json();

                if (data.success) {
                    selectElement.className = `status-dropdown status-${status}`;

                    // Update time display
                    const timeCell = row.querySelector('td[data-label="Time"]');
                    if (timeCell) {
                        const now = new Date();
                        timeCell.innerHTML = `<span class="time-badge">${now.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'})}</span>`;
                    }

                    // Update remarks display
                    const remarksCell = row.querySelector('.remarks-text');
                    if (remarksCell) {
                        remarksCell.textContent = remarks || '-';
                    }

                    row.classList.add('row-edited');
                    setTimeout(() => row.classList.remove('row-edited'), 2000);

                    updateStats();
                    showMessage(document.getElementById('successMessage'), 'Attendance saved successfully!', true);
                } else {
                    throw new Error(data.message || 'Failed to save attendance');
                }
            } catch (error) {
                console.error('Update failed:', error);
                showMessage(document.getElementById('errorMessage'), error.message, false);
                selectElement.value = '';
            } finally {
                selectElement.disabled = false;
                row.style.opacity = '1';
            }
        }

        // ========== REMARKS MODAL ==========
        function openRemarksModal(selectElement) {
            const row = selectElement.closest('tr');
            const studentName = row.querySelector('td[data-label="Name"]').textContent;
            const status = selectElement.value;

            document.getElementById('modalStudentInfo').textContent =
                `Student: ${studentName} | Status: ${status.charAt(0).toUpperCase() + status.slice(1)}`;
            document.getElementById('remarksInput').value = '';
            document.getElementById('remarksModal').classList.add('show');
        }

        function closeRemarksModal() {
            if (pendingUpdate) {
                pendingUpdate.value = ''; // Reset dropdown
                pendingUpdate = null;
            }
            document.getElementById('remarksModal').classList.remove('show');
        }

        function saveWithRemarks() {
            const remarks = document.getElementById('remarksInput').value.trim();

            // Make remarks compulsory for absent, late, and sick
            if (!remarks || remarks.length === 0) {
                showMessage(document.getElementById('errorMessage'),
                    'Please provide a reason for this status', false);
                document.getElementById('remarksInput').focus();
                return;
            }

            if (pendingUpdate) {
                updateAttendance(pendingUpdate, remarks);
                pendingUpdate = null;
            }
            document.getElementById('remarksModal').classList.remove('show');
        }

        // ========== BULK ACTIONS ==========
        document.getElementById('selectAllCheckbox').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.student-checkbox:not(#selectAllCheckbox)');
            const visibleCheckboxes = Array.from(checkboxes).filter(cb => {
                const row = cb.closest('tr');
                return !row.classList.contains('hidden');
            });

            visibleCheckboxes.forEach(cb => cb.checked = this.checked);
            updateBulkButton();
        });

        document.querySelectorAll('.student-checkbox:not(#selectAllCheckbox)').forEach(cb => {
            cb.addEventListener('change', updateBulkButton);
        });

        function updateBulkButton() {
            const checked = document.querySelectorAll('.student-checkbox:not(#selectAllCheckbox):checked').length;
            document.getElementById('markSelectedbtnn').disabled = checked === 0;
        }

        document.getElementById('markAllPresentbtnn').addEventListener('click', async function() {
            if (!confirm('Mark ALL visible students as Present?')) return;

            const dropdowns = Array.from(document.querySelectorAll('.status-dropdown')).filter(dd => {
                const row = dd.closest('tr');
                return !row.classList.contains('hidden');
            });

            let successCount = 0;
            for (const dropdown of dropdowns) {
                dropdown.value = 'present';
                await updateAttendance(dropdown, '');
                successCount++;
            }

            showMessage(document.getElementById('successMessage'),
                `${successCount} students marked as present!`, true);
        });

        document.getElementById('markSelectedbtnn').addEventListener('click', function() {
            const checked = document.querySelectorAll('.student-checkbox:not(#selectAllCheckbox):checked');
            document.getElementById('selectedCountText').textContent =
                `${checked.length} student(s) selected`;
            document.getElementById('bulkActionModal').classList.add('show');
        });

        function closeBulkModal() {
            document.getElementById('bulkActionModal').classList.remove('show');
        }

        async function executeBulkMark() {
            const status = document.getElementById('bulkStatus').value;
            if (!status) {
                alert('Please select a status');
                return;
            }

            const remarks = document.getElementById('bulkRemarks').value.trim();

            // Make remarks compulsory for absent, late, and sick
            if (['absent', 'late', 'sick'].includes(status) && !remarks) {
                showMessage(document.getElementById('errorMessage'),
                    'Please provide a reason for ' + status + ' status', false);
                document.getElementById('bulkRemarks').focus();
                return;
            }

            const checked = document.querySelectorAll('.student-checkbox:not(#selectAllCheckbox):checked');

            closeBulkModal();

            let successCount = 0;
            for (const checkbox of checked) {
                const studentId = checkbox.dataset.studentId;
                const row = checkbox.closest('tr');
                const dropdown = row.querySelector('.status-dropdown');

                dropdown.value = status;
                await updateAttendance(dropdown, remarks);
                checkbox.checked = false;
                successCount++;
            }

            document.getElementById('selectAllCheckbox').checked = false;
            updateBulkButton();

            showMessage(document.getElementById('successMessage'),
                `${successCount} students updated successfully!`, true);
        }
        // ========== EXPORT TO EXCEL ==========
        document.getElementById('exportExcelbtnn').addEventListener('click', function() {
            const table = document.getElementById('attendanceTable');
            const rows = [];

            // Headers
            rows.push(['Student ID', 'Student Name', 'Status', 'Time', 'Remarks']);

            // Data rows (only visible ones)
            table.querySelectorAll('tbody tr:not(.hidden)').forEach(tr => {
                const cells = tr.querySelectorAll('td');
                if (cells.length > 1) {
                    rows.push([
                        cells[1].textContent.trim(),
                        cells[2].textContent.trim(),
                        cells[3].querySelector('select').value.toUpperCase(),
                        cells[4].textContent.trim(),
                        cells[5].textContent.trim()
                    ]);
                }
            });

            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.aoa_to_sheet(rows);

            // Auto-size columns
            const colWidths = rows[0].map((_, colIndex) => {
                return Math.max(
                    ...rows.map(row => (row[colIndex] ? row[colIndex].toString().length : 10))
                );
            });
            ws['!cols'] = colWidths.map(w => ({
                wch: w + 2
            }));

            // Add worksheet to workbook
            XLSX.utils.book_append_sheet(wb, ws, 'Attendance');

            // Generate filename with date and class info
            const filename = `Attendance_<?= htmlspecialchars($selected_class) ?>_<?= htmlspecialchars($selected_stream) ?>_${currentDate}.xlsx`;

            // Download file
            XLSX.writeFile(wb, filename);

            showMessage(document.getElementById('successMessage'),
                'Excel file exported successfully!', true);
        });

        // ========== SEARCH FUNCTIONALITY ==========
        document.getElementById('studentSearch').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const rows = document.querySelectorAll('#attendanceTable tbody tr');
            let visibleCount = 0;

            rows.forEach(row => {
                // Skip the "no results" row if it exists
                if (row.id === 'noResultsRow' || row.querySelector('td[colspan]')) return;

                const studentName = row.dataset.studentName || '';
                const studentId = row.dataset.studentId || '';

                if (studentName.includes(searchTerm) || studentId.includes(searchTerm)) {
                    row.classList.remove('hidden');
                    visibleCount++;
                } else {
                    row.classList.add('hidden');
                }
            });

            // Show/hide "No results found" message
            handleNoResults(visibleCount, searchTerm);

            // Update stats for visible rows only
            updateStats();

            // Uncheck selectAll if search is active
            if (searchTerm) {
                document.getElementById('selectAllCheckbox').checked = false;
            }
        });

        // Function to handle "No results found" display
        function handleNoResults(visibleCount, searchTerm) {
            const tbody = document.querySelector('#attendanceTable tbody');
            let noResultsRow = document.getElementById('noResultsRow');

            if (visibleCount === 0 && searchTerm !== '') {
                // Create "No results found" row if it doesn't exist
                if (!noResultsRow) {
                    noResultsRow = document.createElement('tr');
                    noResultsRow.id = 'noResultsRow';
                    noResultsRow.innerHTML = `
                <td colspan="6" style="text-align:center; padding:40px; color:#666;">
                    <div style="font-size: 18px; font-weight: 600; margin-bottom: 5px;">No Results Found</div>
                    <div style="font-size: 14px; color:#999;">No students match "<strong>${searchTerm}</strong>"</div>
                    <div style="font-size: 14px; color:#999; margin-top: 10px;">Try a different search term</div>
                </td>
            `;
                    tbody.appendChild(noResultsRow);
                } else {
                    // Update the search term in existing row
                    noResultsRow.querySelector('strong').textContent = searchTerm;
                    noResultsRow.classList.remove('hidden');
                }
            } else {
                // Hide or remove "No results found" row
                if (noResultsRow) {
                    noResultsRow.classList.add('hidden');
                }
            }
        }

        function clearSearch() {
            const searchInput = document.getElementById('studentSearch');
            searchInput.value = '';
            searchInput.dispatchEvent(new Event('input'));
            document.getElementById('clearSearchBtn').classList.add('hidden');
        }

        // Show/hide clear button based on search input
        document.getElementById('studentSearch').addEventListener('input', function() {
            const clearBtn = document.getElementById('clearSearchBtn');
            if (this.value.trim() !== '') {
                clearBtn.classList.remove('hidden');
            } else {
                clearBtn.classList.add('hidden');
            }
        });

        // ========== KEYBOARD SHORTCUTS ==========
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + S to export
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                document.getElementById('exportExcelbtnn').click();
            }

            // Escape to close modals
            if (e.key === 'Escape') {
                closeRemarksModal();
                closeBulkModal();
            }
        });

        // ========== PREVENT ACCIDENTAL PAGE LEAVE ==========
        let hasUnsavedChanges = false;

        document.querySelectorAll('.status-dropdown').forEach(dropdown => {
            const originalValue = dropdown.value;
            dropdown.addEventListener('change', function() {
                if (this.value !== originalValue) {
                    hasUnsavedChanges = true;
                }
            });
        });

        window.addEventListener('beforeunload', function(e) {
            if (hasUnsavedChanges) {
                e.preventDefault();
                e.returnValue = 'You have unsaved attendance changes. Are you sure you want to leave?';
                return e.returnValue;
            }
        });

        // ========== AUTO-SAVE INDICATOR ==========
        let saveTimeout;
        document.querySelectorAll('.status-dropdown').forEach(dropdown => {
            dropdown.addEventListener('change', function() {
                clearTimeout(saveTimeout);
                const row = this.closest('tr');
                row.style.borderLeft = '4px solid #ff9800';

                saveTimeout = setTimeout(() => {
                    row.style.borderLeft = '';
                }, 3000);
            });
        });

        // ========== INITIALIZE ==========
        document.addEventListener('DOMContentLoaded', function() {
            updateStats();

            // Focus search on load
            document.getElementById('studentSearch').focus();

            // Show helpful tip
            console.log('%c💡 Tip: Use Ctrl+S to export attendance to Excel', 'color: #4caf50; font-size: 14px; font-weight: bold;');
            console.log('%c💡 Tip: Select multiple students and use "Mark Selected" for bulk operations', 'color: #2196f3; font-size: 14px;');
        });

        // ========== MODAL CLICK OUTSIDE TO CLOSE ==========
        document.getElementById('remarksModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeRemarksModal();
            }
        });

        document.getElementById('bulkActionModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeBulkModal();
            }
        });

        // ========== ENTER KEY SUPPORT FOR MODALS ==========
        document.getElementById('remarksInput').addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.ctrlKey) {
                saveWithRemarks();
            }
        });

        document.getElementById('bulkRemarks').addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.ctrlKey) {
                executeBulkMark();
            }
        });

        // ========== PERFORMANCE: Debounce search ==========
        let searchTimeout;
        document.getElementById('studentSearch').addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const searchTerm = this.value.toLowerCase().trim();
                const rows = document.querySelectorAll('#attendanceTable tbody tr');
                let visibleCount = 0;

                rows.forEach(row => {
                    // Skip the "no results" row if it exists
                    if (row.id === 'noResultsRow' || row.querySelector('td[colspan]')) return;

                    const studentName = row.dataset.studentName || '';
                    const studentId = row.dataset.studentId || '';

                    if (studentName.includes(searchTerm) || studentId.includes(searchTerm)) {
                        row.classList.remove('hidden');
                        visibleCount++;
                    } else {
                        row.classList.add('hidden');
                    }
                });

                // Show/hide "No results found" message
                handleNoResults(visibleCount, searchTerm);

                updateStats();

                if (searchTerm) {
                    document.getElementById('selectAllCheckbox').checked = false;
                }
            }, 300); // Wait 300ms after user stops typing
        });
        document.getElementById('bulkStatus').addEventListener('change', function() {
            const status = this.value;
            const indicator = document.getElementById('bulkRequiredIndicator');
            const remarksLabel = document.querySelector('label[for="bulkRemarks"]');

            if (['absent', 'late', 'sick'].includes(status)) {
                indicator.style.display = 'inline';
                remarksLabel.childNodes[0].textContent = 'Remarks ';
            } else {
                indicator.style.display = 'none';
                remarksLabel.childNodes[0].textContent = 'Remarks (optional) ';
            }
        });
    </script>
</body>

</html>