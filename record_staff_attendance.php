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

// Define allowed roles for this page
$allowed_roles = ['developer', 'super user', 'school leader'];

// Check if user has required role
if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), array_map('strtolower', $allowed_roles))) {
    
    // Log unauthorized access attempt
    if (isset($tracker)) {
        $tracker->trackAction("Unauthorized access attempt to Staff Attendance by " . ($_SESSION['username'] ?? 'Unknown'));
    }
    
    // Set error message
    $_SESSION['error_message'] = "Access Denied: You don't have permission to access this page.";
    
    // Send 403 Forbidden status
    http_response_code(403);
    
    // Redirect to dashboard (or any other page)
    header("Location: dashboard.php");
    exit();
}

// ============================================
// END OF ROLE-BASED ACCESS CONTROL
// ============================================
$tracker->trackAction("Record staff attendance");

// Force current date
$selected_date = date('Y-m-d');

// Track action
if (isset($tracker)) {
    $tracker->trackAction("Viewed Staff Attendance for Date: $selected_date");
}

$staff_members = [];

// SQL query with version support for optimistic locking
$sql = "SELECT s.staff_id, s.first_name, s.last_name, 
               COALESCE(sa.status, '') AS attendance_status,
               sa.created_at AS attendance_time,
               sa.remarks,
               sa.version
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

// Calculate statistics
$total_staff = count($staff_members);
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

function getStatusClass($status)
{
    switch ($status) {
        case 'present':
            return 'status-present';
        case 'absent':
            return 'status-absent';
        case 'late':
            return 'status-late';
        case 'on_leave':
            return 'status-on-leave';
        default:
            return '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Attendance</title>
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
            color: #333;
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

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            padding: 15px;
            background: var(--light-bg);
        }

        .stat-item {
            background: var(--white);
            padding: 15px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            text-align: center;
            box-shadow: 0 2px 5px var(--shadow-color);
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
            margin-top: 5px;
        }

        .toolbar {
            padding: 20px;
            background: var(--light-bg);
            border-bottom: 2px solid var(--border-color);
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }

        .search-input {
            flex: 1;
            min-width: 200px;
            padding: 12px;
            border: 2px solid var(--primary-color);
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

        .btnn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .hidden {
            display: none !important;
        }

        .table-container {
            padding: 15px;
            overflow-x: auto;
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
        }

        .attendance-table tr.row-edited {
            background: #fff3e0;
            border-left: 4px solid var(--late-color);
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

        .checkbox-cell {
            width: 50px;
        }

        .staff-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .staff-id {
            font-weight: 600;
            color: var(--primary-color);
        }

        .time-badge {
            display: inline-block;
            padding: 4px 8px;
            background: #e3f2fd;
            border-radius: 4px;
            font-size: 12px;
            color: #1976d2;
        }

        .status-dropdown {
            padding: 8px 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            min-width: 140px;
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

        .status-on-leave {
            background: #e8eaf6;
            border-color: #9fa8da;
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

        .form-group textarea,
        .form-group select {
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

        @media screen and (min-width: 768px) {
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
                position: sticky;
                top: 0;
                z-index: 10;
            }

            .attendance-table tr {
                display: table-row;
                border: none;
                box-shadow: none;
                border-bottom: 1px solid var(--border-color);
            }

            .attendance-table tr:hover {
                background: #f5f5f5;
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

        @media (max-width: 768px) {
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .btnn {
                width: 100%;
            }

            .checkbox-cell::before {
                content: 'Select';
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
            <h2>Staff Attendance Registration</h2>
            <p id="currentDate"></p>
        </header>

        <section class="stats-container">
            <div class="stat-item stat-present">
                <div class="stat-number" id="presentCount"><?= $present_count ?></div>
                <div class="stat-label">Present</div>
            </div>
            <div class="stat-item stat-absent">
                <div class="stat-number" id="absentCount"><?= $absent_count ?></div>
                <div class="stat-label">Absent</div>
            </div>
            <div class="stat-item stat-late">
                <div class="stat-number" id="lateCount"><?= $late_count ?></div>
                <div class="stat-label">Late</div>
            </div>
            <div class="stat-item stat-on-leave">
                <div class="stat-number" id="onLeaveCount"><?= $on_leave_count ?></div>
                <div class="stat-label">On Leave</div>
            </div>
            <div class="stat-item stat-unmarked">
                <div class="stat-number" id="unmarkedCount"><?= $not_marked ?></div>
                <div class="stat-label">Not Marked</div>
            </div>
        </section>

        <section class="toolbar">
            <input type="text" class="search-input" id="staffSearch"
                placeholder="Search by staff name or ID..." autocomplete="off">

            <button class="btnn btnn-secondary hidden" id="clearSearchBtn"
                style="background: #757575;" onclick="clearSearch()">
                ✕ Clear Search
            </button>

            <button class="btnn btnn-primary" id="markAllPresentBtn">
                ✓ Mark All Present
            </button>

            <button class="btnn btnn-secondary" id="markSelectedBtn" disabled>
                ✓ Mark Selected
            </button>

            <button class="btnn btnn-secondary" id="exportExcelBtn">
                Export Excel
            </button>
        </section>

        <main class="table-container">
            <table class="attendance-table" id="attendanceTable">
                <thead>
                    <tr>
                        <th class="checkbox-cell">
                            <input type="checkbox" id="selectAllCheckbox" class="staff-checkbox">
                        </th>
                        <th>Staff ID</th>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Time Marked</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($staff_members) > 0) : ?>
                        <?php foreach ($staff_members as $staff) : ?>
                            <tr data-staff-name="<?= htmlspecialchars(strtolower($staff['first_name'] . ' ' . $staff['last_name'])) ?>"
                                data-staff-id="<?= htmlspecialchars(strtolower($staff['staff_id'])) ?>">
                                <td class="checkbox-cell" data-label="Select">
                                    <input type="checkbox" class="staff-checkbox"
                                        data-staff-id="<?= htmlspecialchars($staff['staff_id']) ?>">
                                </td>
                                <td data-label="Staff ID" class="staff-id"><?= htmlspecialchars($staff['staff_id']) ?></td>
                                <td data-label="Name"><?= htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']) ?></td>
                                <td data-label="Status">
                                    <select class="status-dropdown <?= getStatusClass($staff['attendance_status']) ?>"
                                        data-staff-id="<?= htmlspecialchars($staff['staff_id']) ?>"
                                        onchange="handleStatusChange(this)">
                                        <option value="">Select...</option>
                                        <option value="present" <?= $staff['attendance_status'] == 'present' ? 'selected' : '' ?>>Present</option>
                                        <option value="absent" <?= $staff['attendance_status'] == 'absent' ? 'selected' : '' ?>>Absent</option>
                                        <option value="late" <?= $staff['attendance_status'] == 'late' ? 'selected' : '' ?>>Late</option>
                                        <option value="on_leave" <?= $staff['attendance_status'] == 'on_leave' ? 'selected' : '' ?>>On Leave</option>
                                    </select>
                                </td>
                                <td data-label="Time Marked">
                                    <?php if (!empty($staff['attendance_time'])): ?>
                                        <span class="time-badge">
                                            <?= date('g:i A', strtotime($staff['attendance_time'])) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Remarks">
                                    <span class="remarks-text">
                                        <?= !empty($staff['remarks']) ? htmlspecialchars($staff['remarks']) : '-' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="noResultsRow" class="hidden">
                            <td colspan="6" style="text-align:center;padding:40px;">
                                <div style="font-size: 18px; font-weight: 600; margin-bottom: 5px;">No Results Found</div>
                                <div style="font-size: 14px; color:#999;">Try a different search term</div>
                            </td>
                        </tr>
                    <?php else : ?>
                        <tr>
                            <td colspan="6" style="text-align:center;padding:40px;">No active staff members found.</td>
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
                <p id="modalStaffInfo" style="color: #666;"></p>
            </div>
            <div class="form-group">
                <label for="remarksInput">Reason <span style="color: red;">*</span></label>
                <textarea id="remarksInput" rows="4" required
                    placeholder="E.g., Medical leave, Family emergency, Scheduled leave..."></textarea>
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
                <h3>Mark Selected Staff</h3>
                <p id="selectedCountText" style="color: #666;"></p>
            </div>
            <div class="form-group">
                <label for="bulkStatus">Select Status:</label>
                <select id="bulkStatus" class="status-dropdown" style="width: 100%;">
                    <option value="">-- Choose Status --</option>
                    <option value="present">Present</option>
                    <option value="absent">Absent</option>
                    <option value="late">Late</option>
                    <option value="on_leave">On Leave</option>
                </select>
            </div>
            <div class="form-group" id="bulkRemarksGroup">
                <label for="bulkRemarks">Remarks <span id="bulkRequiredIndicator" style="color: red; display: none;">*</span>:</label>
                <textarea id="bulkRemarks" rows="3"
                    placeholder="Optional: Add common remarks for all selected staff..."></textarea>
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
        const currentDate = '<?= $selected_date ?>';
        const LOGGED_USER = '<?= $_SESSION['username'] ?? 'Unknown' ?>';

        let pendingUpdate = null;

        // ========== UTILITY FUNCTIONS ==========
        function showMessage(element, message, isSuccess) {
            element.textContent = (isSuccess ? '✓ ' : '✗ ') + message;
            element.classList.add('show');
            setTimeout(() => element.classList.remove('show'), 4000);
        }

        function updateStats() {
            const rows = document.querySelectorAll('#attendanceTable tbody tr:not(.hidden):not(#noResultsRow)');
            let present = 0, absent = 0, late = 0, onLeave = 0, unmarked = 0;

            rows.forEach(row => {
                const dropdown = row.querySelector('.status-dropdown');
                if (!dropdown) return;

                switch (dropdown.value) {
                    case 'present': present++; break;
                    case 'absent': absent++; break;
                    case 'late': late++; break;
                    case 'on_leave': onLeave++; break;
                    default: unmarked++; break;
                }
            });

            document.getElementById('presentCount').textContent = present;
            document.getElementById('absentCount').textContent = absent;
            document.getElementById('lateCount').textContent = late;
            document.getElementById('onLeaveCount').textContent = onLeave;
            document.getElementById('unmarkedCount').textContent = unmarked;
        }

        // ========== ATTENDANCE UPDATE ==========
        function handleStatusChange(selectElement) {
            const status = selectElement.value;
            if (!status) return;

            if (['absent', 'late', 'on_leave'].includes(status)) {
                pendingUpdate = selectElement;
                openRemarksModal(selectElement);
            } else {
                updateAttendance(selectElement, '');
            }
        }

        async function updateAttendance(selectElement, remarks = '') {
            const staffId = selectElement.dataset.staffId;
            const status = selectElement.value;
            if (!status) return;

            selectElement.disabled = true;
            const row = selectElement.closest('tr');
            row.style.opacity = '0.6';

            const formData = new FormData();
            formData.append('staff_id', staffId);
            formData.append('status', status);
            formData.append('date', currentDate);
            formData.append('remarks', remarks);
            formData.append('recorded_by', LOGGED_USER);
            formData.append('csrf_token', CSRF_TOKEN);

            try {
                const response = await fetch('api/update_staff_attendance.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`Server error: ${response.status}`);
                }

                const data = await response.json();

                if (data.success) {
                    selectElement.className = `status-dropdown status-${status}`;

                    const timeCell = row.querySelector('td[data-label="Time Marked"]');
                    if (timeCell) {
                        const now = new Date();
                        timeCell.innerHTML = `<span class="time-badge">${now.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'})}</span>`;
                    }

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
            const staffName = row.querySelector('td[data-label="Name"]').textContent;
            const status = selectElement.value;

            document.getElementById('modalStaffInfo').textContent =
                `Staff: ${staffName} | Status: ${status.replace('_', ' ').charAt(0).toUpperCase() + status.slice(1).replace('_', ' ')}`;
            document.getElementById('remarksInput').value = '';
            document.getElementById('remarksModal').classList.add('show');
        }

        function closeRemarksModal() {
            if (pendingUpdate) {
                pendingUpdate.value = '';
                pendingUpdate = null;
            }
            document.getElementById('remarksModal').classList.remove('show');
        }

        function saveWithRemarks() {
            const remarks = document.getElementById('remarksInput').value.trim();

            if (!remarks) {
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
            const checkboxes = document.querySelectorAll('.staff-checkbox:not(#selectAllCheckbox)');
            const visibleCheckboxes = Array.from(checkboxes).filter(cb => {
                const row = cb.closest('tr');
                return !row.classList.contains('hidden');
            });

            visibleCheckboxes.forEach(cb => cb.checked = this.checked);
            updateBulkButton();
        });

        document.querySelectorAll('.staff-checkbox:not(#selectAllCheckbox)').forEach(cb => {
            cb.addEventListener('change', updateBulkButton);
        });

        function updateBulkButton() {
            const checked = document.querySelectorAll('.staff-checkbox:not(#selectAllCheckbox):checked').length;
            document.getElementById('markSelectedBtn').disabled = checked === 0;
        }

        document.getElementById('markAllPresentBtn').addEventListener('click', async function() {
            if (!confirm('Mark ALL visible staff as Present?')) return;

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
                `${successCount} staff marked as present!`, true);
        });

       document.getElementById('markSelectedBtn').addEventListener('click', function() {
            const checked = document.querySelectorAll('.staff-checkbox:not(#selectAllCheckbox):checked');
            document.getElementById('selectedCountText').textContent =
                `${checked.length} staff member(s) selected`;
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

            if (['absent', 'late', 'on_leave'].includes(status) && !remarks) {
                showMessage(document.getElementById('errorMessage'),
                    'Please provide a reason for ' + status + ' status', false);
                document.getElementById('bulkRemarks').focus();
                return;
            }

            const checked = document.querySelectorAll('.staff-checkbox:not(#selectAllCheckbox):checked');
            closeBulkModal();

            let successCount = 0;
            for (const checkbox of checked) {
                const staffId = checkbox.dataset.staffId;
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
                `${successCount} staff updated successfully!`, true);
        }

        // ========== EXPORT TO EXCEL ==========
        document.getElementById('exportExcelBtn').addEventListener('click', function() {
            const table = document.getElementById('attendanceTable');
            const rows = [];

            rows.push(['Staff ID', 'Staff Name', 'Status', 'Time', 'Remarks']);

            table.querySelectorAll('tbody tr:not(.hidden):not(#noResultsRow)').forEach(tr => {
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

            const colWidths = rows[0].map((_, colIndex) => {
                return Math.max(...rows.map(row => (row[colIndex] ? row[colIndex].toString().length : 10)));
            });
            ws['!cols'] = colWidths.map(w => ({wch: w + 2}));

            XLSX.utils.book_append_sheet(wb, ws, 'Staff Attendance');

            const filename = `Staff_Attendance_${currentDate}.xlsx`;
            XLSX.writeFile(wb, filename);

            showMessage(document.getElementById('successMessage'),
                'Excel file exported successfully!', true);
        });

        // ========== SEARCH FUNCTIONALITY ==========
        let searchTimeout;
        document.getElementById('staffSearch').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const searchTerm = this.value.toLowerCase().trim();
                const rows = document.querySelectorAll('#attendanceTable tbody tr:not(#noResultsRow)');
                let visibleCount = 0;

                rows.forEach(row => {
                    if (row.querySelector('td[colspan]')) return;

                    const staffName = row.dataset.staffName || '';
                    const staffId = row.dataset.staffId || '';

                    if (staffName.includes(searchTerm) || staffId.includes(searchTerm)) {
                        row.classList.remove('hidden');
                        visibleCount++;
                    } else {
                        row.classList.add('hidden');
                    }
                });

                const noResultsRow = document.getElementById('noResultsRow');
                if (visibleCount === 0 && searchTerm !== '') {
                    noResultsRow.classList.remove('hidden');
                } else {
                    noResultsRow.classList.add('hidden');
                }

                updateStats();

                if (searchTerm) {
                    document.getElementById('selectAllCheckbox').checked = false;
                }
            }, 300);
        });

        function clearSearch() {
            const searchInput = document.getElementById('staffSearch');
            searchInput.value = '';
            searchInput.dispatchEvent(new Event('input'));
            document.getElementById('clearSearchBtn').classList.add('hidden');
        }

        document.getElementById('staffSearch').addEventListener('input', function() {
            const clearBtn = document.getElementById('clearSearchBtn');
            if (this.value.trim() !== '') {
                clearBtn.classList.remove('hidden');
            } else {
                clearBtn.classList.add('hidden');
            }
        });

        // ========== MODAL INTERACTIONS ==========
        document.getElementById('remarksModal').addEventListener('click', function(e) {
            if (e.target === this) closeRemarksModal();
        });

        document.getElementById('bulkActionModal').addEventListener('click', function(e) {
            if (e.target === this) closeBulkModal();
        });

        document.getElementById('remarksInput').addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.ctrlKey) saveWithRemarks();
        });

        document.getElementById('bulkRemarks').addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.ctrlKey) executeBulkMark();
        });

        document.getElementById('bulkStatus').addEventListener('change', function() {
            const status = this.value;
            const indicator = document.getElementById('bulkRequiredIndicator');
            const remarksLabel = document.querySelector('label[for="bulkRemarks"]');

            if (['absent', 'late', 'on_leave'].includes(status)) {
                indicator.style.display = 'inline';
            } else {
                indicator.style.display = 'none';
            }
        });

        // ========== KEYBOARD SHORTCUTS ==========
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                document.getElementById('exportExcelBtn').click();
            }

            if (e.key === 'Escape') {
                closeRemarksModal();
                closeBulkModal();
            }
        });

        // ========== INITIALIZATION ==========
        document.addEventListener('DOMContentLoaded', function() {
            const dateObj = new Date(currentDate + 'T00:00:00');
            document.getElementById('currentDate').textContent = dateObj.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });

            updateStats();
            document.getElementById('staffSearch').focus();

            console.log('%c💡 Tip: Use Ctrl+S to export attendance to Excel', 'color: #4caf50; font-size: 14px; font-weight: bold;');
        });
    </script>
</body>

</html>