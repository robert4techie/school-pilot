<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("School timetable");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400..900&family=Quicksand:wght@300..700&display=swap" rel="stylesheet">
    <title>School Timetable - School Pilot</title>
    <style>
        :root {
            --primary-green: #2e7d32;
            --dark-green: #1b5e20;
            --light-green: #81c784;
            --accent-green: #4caf50;
            --background: #f5f9f5;
            --white: #ffffff;
            --text-primary: #1b5e20;
            --text-secondary: #2e7d32;
            --text-muted: #666666;
            --border-color: #e0e0e0;
            --success-color: #4caf50;
            --error-color: #f44336;
            --warning-color: #ff9800;
            --danger-color: #dc3545;
            --shadow-light: 0 2px 4px rgba(0, 0, 0, 0.1);
            --shadow-medium: 0 4px 8px rgba(0, 0, 0, 0.12);
            --shadow-heavy: 0 8px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-large: 12px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            padding: 20px;
            background: var(--background);
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            margin-top: 50px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            box-shadow: var(--shadow-heavy);
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            color: var(--text-primary);
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            color: var(--white);
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            box-shadow: var(--shadow-medium);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .time-management {
            background: var(--white);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
        }

        .collapse-btn {
            background: var(--primary-green);
            color: var(--white);
            border: none;
            padding: 5px 15px;
            border-radius: 5px;
            cursor: pointer;
            margin-left: 10px;
            transition: all 0.3s ease;
            transform: rotate(0deg);
        }

        .collapse-btn:hover {
            background: var(--dark-green);
        }

        .time-input-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .form-group input,
        .form-group select {
            padding: 10px;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            font-size: 14px;
            transition: var(--transition);
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-green);
        }

        .btnn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: var(--transition);
            margin: 5px;
        }

        .btnn-primary {
            background: var(--primary-green);
            color: var(--white);
        }

        .btnn-primary:hover {
            background: var(--dark-green);
            transform: translateY(-2px);
        }

        .btnn-secondary {
            background: var(--text-muted);
            color: var(--white);
        }

        .btnn-secondary:hover {
            background: #555555;
        }

        .btnn-danger {
            background: var(--danger-color);
            color: var(--white);
        }

        .btnn-danger:hover {
            background: #c82333;
        }

        .btnn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .controls {
            background: var(--white);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
        }

        .control-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .timetable-container {
            background: var(--white);
            border-radius: 15px;
            padding: 20px;
            box-shadow: var(--shadow-light);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .empty-state h3 {
            margin-bottom: 15px;
            font-size: 1.5rem;
            color: var(--text-secondary);
        }

        .timetable-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .class-badge {
            background: var(--primary-green);
            color: var(--white);
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
        }

        .timetable {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            box-shadow: var(--shadow-medium);
            border-radius: 10px;
            overflow: hidden;
        }

        .timetable th {
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            color: var(--white);
            padding: 15px 10px;
            text-align: center;
            font-weight: 600;
        }

        .timetable td {
            padding: 15px 10px;
            text-align: center;
            border: 1px solid var(--border-color);
            transition: var(--transition);
        }

        .time-slot {
            background: var(--background);
            font-weight: 600;
            color: var(--text-secondary);
        }

        .subject-cell {
            cursor: pointer;
            min-height: 60px;
            vertical-align: middle;
        }

        .subject-cell:hover {
            background: #e8f5e8;
            transform: scale(1.02);
        }

        .subject-info {
            text-align: center;
        }

        .subject-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 3px;
        }

        .teacher-name {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 2px;
        }

        .room-number {
            font-size: 11px;
            color: #888;
        }

        .add-subject {
            color: var(--primary-green);
            font-style: italic;
            padding: 20px;
        }

        .filled {
            background: linear-gradient(135deg, #e8f5e8, var(--light-green));
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background: linear-gradient(to top right, #fdfefe, #f5f9f5);
            margin: 5% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
            border: 1px solid rgba(255, 255, 255, 0.18);
            animation: fadeIn 0.4s ease-out;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-title {
            margin: 0;
            color: var(--text-primary);
        }

        .close-btnn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-muted);
            transition: var(--transition);
        }

        .close-btnn:hover {
            color: var(--text-primary);
        }

        .time-slots-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .time-slot-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: var(--border-radius);
            background: var(--background);
        }

        .time-slot-item.break {
            background: #fff3cd;
        }

        .time-slot-item.lunch {
            background: #e8f5e8;
        }

        .slot-type-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            margin-left: 10px;
        }

        .slot-type-badge.regular {
            background: #e8f5e8;
            color: var(--dark-green);
        }

        .slot-type-badge.break {
            background: #fff3cd;
            color: #856404;
        }

        .slot-type-badge.lunch {
            background: #e8f5e8;
            color: var(--dark-green);
        }

        .remove-time-btn {
            background: var(--danger-color);
            color: var(--white);
            border: none;
            padding: 5px 10px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            transition: var(--transition);
        }

        .remove-time-btn:hover {
            background: #c82333;
        }

        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Smooth slide animation for time management panel */
        #timeManagementContent {
            overflow: hidden;
            transition: max-height 0.3s ease-out, opacity 0.3s ease-out;
            max-height: 0;
            opacity: 0;
        }

        #timeManagementContent.show {
            max-height: 1000px;
            opacity: 1;
        }

        /* Notification system */
        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 2000;
            max-width: 350px;
        }

        .notification {
            background: var(--white);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            border-left: 4px solid var(--primary-green);
            transform: translateX(400px);
            opacity: 0;
            transition: all 0.3s ease-out;
        }

        .notification.show {
            transform: translateX(0);
            opacity: 1;
        }

        .notification.success {
            border-left-color: var(--success-color);
        }

        .notification.error {
            border-left-color: var(--error-color);
        }

        .notification.warning {
            border-left-color: var(--warning-color);
        }

        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .notification-title {
            font-weight: 600;
            font-size: 14px;
            color: var(--text-primary);
        }

        .notification-close {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: var(--text-muted);
            padding: 0;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .notification-close:hover {
            color: var(--text-primary);
        }

        .notification-message {
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.4;
        }

        .notification-icon {
            margin-right: 8px;
        }

        /* Slide animation for time slots */
        .time-slot-item {
            animation: slideInFromLeft 0.3s ease-out;
        }

        @keyframes slideInFromLeft {
            from {
                transform: translateX(-100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    </style>
</head>

<body>
    <?php require_once 'nav.php' ?>

    <!-- Notification Container -->
    <div class="notification-container" id="notificationContainer">
        <!-- Notifications will be dynamically added here -->
    </div>
    <div class="container">
        <div class="header fade-in">
            <h1> School Timetable Management</h1>
            <p>Create and manage timetables for Senior one to Senior six classes</p>
        </div>


        <div class="stats-grid fade-in">
            <div class="stat-card">
                <div class="stat-number" id="totalClasses">6</div>
                <div class="stat-label">Total Classes</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="totalSubjects">0</div>
                <div class="stat-label">Subjects Scheduled</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="totalTeachers">0</div>
                <div class="stat-label">Teachers Assigned</div>
            </div>
        </div>

        <div class="time-management fade-in">
            <h3>Time Slot Management
                <button class="collapse-btn" onclick="toggleTimeManagement()" id="timeToggleBtn">▼ Show</button>
            </h3>
            <div id="timeManagementContent">
                <div class="time-input-group">
                    <div class="form-group">
                        <label for="startTime">Start Time</label>
                        <input type="time" id="startTime" value="08:00">
                    </div>
                    <div class="form-group">
                        <label for="endTime">End Time</label>
                        <input type="time" id="endTime" value="08:40">
                    </div>
                    <div class="form-group">
                        <label for="slotType">Slot Type</label>
                        <select id="slotType">
                            <option value="regular"> Regular Class</option>
                            <option value="break">Break</option>
                            <option value="lunch">Lunch</option>
                        </select>
                    </div>
                    <button class="btnn btnn-primary" onclick="addTimeSlot()">Add Slot</button>
                </div>

                <div class="btnn-group" style="margin-bottom: 15px;">
                    <button class="btnn btnn-danger" onclick="clearAllTimeSlots()">Clear All Slots</button>
                </div>

                <div class="time-slots-list" id="timeSlotsList">
                    <!-- Time slots will be listed here -->
                </div>
            </div>
        </div>

        <div class="controls fade-in">
            <div class="control-group">
                <div class="form-group">
                    <label for="classSelect">Select Class</label>
                    <select id="classSelect">
                        <option value="">Choose a class</option>
                        <option value="Senior One">Senior One</option>
                        <option value="Senior Two">Senior Two</option>
                        <option value="Senior Three">Senior Three</option>
                        <option value="Senior Four">Senior Four</option>
                        <option value="Senior Five">Senior Five</option>
                        <option value="Senior Six">Senior Six</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="streamSelect">Stream</label>
                    <select id="streamSelect">
                        <option value="">Choose a stream</option>
                        <option value="East">East</option>
                        <option value="West">West</option>
                        <option value="South">South</option>
                        <option value="North">North</option>
                        <option value="Arts">Arts</option>
                        <option value="Sciences">Sciences</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="termSelect">Academic Term</label>
                    <select id="termSelect">
                        <option value="Term One">Term One</option>
                        <option value="Term Two">Term Two</option>
                        <option value="Term Three">Term Three</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="yearSelect">Academic Year</label>
                    <select id="yearSelect">
                        <option value="2025">2025</option>
                        <option value="2024">2024</option>
                        <option value="2026">2026</option>
                    </select>
                </div>
            </div>

            <div class="btnn-group">
                <button class="btnn btnn-primary" onclick="loadTimetable()">
                    Load Timetable
                </button>
                <button class="btnn btnn-primary" onclick="createNewTimetable()">
                    Create New Timetable
                </button>
                <button class="btnn btnn-secondary" onclick="exportTimetable()">
                    Export CSV
                </button>
                <button class="btnn btnn-secondary" onclick="exportPDF()">
                    Export PDF
                </button>
                <button class="btnn btnn-danger" onclick="clearTimetable()">
                    Clear Timetable
                </button>
            </div>
        </div>

        <div class="timetable-container fade-in">
            <div id="emptyState" class="empty-state">
                <h3>No Timetable Selected</h3>
                <p>Please select a class and load or create a timetable to get started.</p>
                <button class="btnn btnn-primary" onclick="document.getElementById('classSelect').focus()">
                    Select Class
                </button>
            </div>

            <div id="timetableView" style="display: none;">
                <div class="timetable-header">
                    <h2 class="timetable-title">Weekly Timetable</h2>
                    <div class="class-badge" id="currentClassBadge">Senior 1</div>
                </div>

                <table class="timetable">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Monday</th>
                            <th>Tuesday</th>
                            <th>Wednesday</th>
                            <th>Thursday</th>
                            <th>Friday</th>
                            <th>Saturday</th>
                        </tr>
                    </thead>
                    <tbody id="timetableBody">
                        <!-- Timetable content will be generated here -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal for adding/editing subjects -->
    <div id="subjectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Add Subject</h3>
                <button class="close-btnn" onclick="closeModal()">&times;</button>
            </div>
            <div class="form-group">
                <label for="subjectName">Subject Name</label>
                <select id="subjectName">
                    <option value="">Select Subject</option>
                </select>
            </div>
            <div class="form-group">
                <label for="paperSelect">Paper Number</label>
                <select id="paperSelect">
                    <option value="">Select Paper</option>
                    <option value="I">Paper I</option>
                    <option value="II">Paper II</option>
                    <option value="III">Paper III</option>
                    <option value="IV">Paper IV</option>
                    <option value="V">Paper V</option>
                    <option value="VI">Paper VI</option>
                </select>
            </div>
            <div class="form-group">
                <label for="teacherSelect">Teacher Name</label>
                <select id="teacherSelect">
                    <option value="">Select Teacher</option>
                </select>
            </div>
            <div class="form-group">
                <label for="roomNumber">Room Number</label>
                <input type="text" id="roomNumber" placeholder="Enter room number">
            </div>
            <div class="btnn-group" style="margin-top: 20px;">
                <button class="btnn btnn-primary" onclick="saveSubject()">Save Subject</button>
                <button class="btnn btnn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btnn btnn-danger" onclick="removeSubject()" id="removeSubject" style="display: none;">Remove</button>
            </div>
        </div>
    </div>


    <div id="confirmModal" class="modal">
        <div class="modal-content confirm-modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="confirmModalTitle">Are you sure?</h3>
                <button class="close-btnn" onclick="closeConfirmModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p id="confirmModalMessage" style="line-height: 1.6; font-size: 16px; color: var(--text-secondary);"></p>
            </div>
            <div class="btnn-group" style="margin-top: 20px; justify-content: flex-end;">
                <button class="btnn btnn-secondary" onclick="closeConfirmModal()">Cancel</button>
                <button class="btnn btnn-danger" id="confirmModalConfirmButton">Confirm</button>
            </div>
        </div>
    </div>

    <script>
        // --- GLOBAL VARIABLES ---
        let currentTimetable = {};
        let currentClass = '';
        let currentStream = '';
        let currentTerm = '';
        let currentYear = '';
        let currentCell = null; // To store which cell is being edited {day, slotIndex}
        let customTimeSlots = [];
        let staffList = [];
        let subjectsList = [];
        let confirmAction = null; // To hold the function to run on confirmation
        const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

        // --- INITIALIZATION ---
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, initializing timetable system...');
            initializeTimetable();
        });

        // --- NOTIFICATION SYSTEM ---
        function showNotification(message, type = 'success', title = '', duration = 4000) {
            const container = document.getElementById('notificationContainer');
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;

            const icons = {
                success: '✅',
                error: '',
                warning: '',
                info: ''
            };

            const titles = {
                success: title || 'Success',
                error: title || 'Error',
                warning: title || 'Warning',
                info: title || 'Info'
            };

            notification.innerHTML = `
    <div class="notification-header">
        <div class="notification-title">
            <span class="notification-icon">${icons[type]}</span>
            ${titles[type]}
        </div>
        <button class="notification-close" onclick="removeNotification(this.parentElement.parentElement)">×</button>
    </div>
    <div class="notification-message">${message}</div>
`;

            container.appendChild(notification);
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            setTimeout(() => {
                removeNotification(notification);
            }, duration);
        }

        function removeNotification(notification) {
            if (!notification) return;
            notification.classList.remove('show');
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.parentElement.removeChild(notification);
                }
            }, 300);
        }

        window.showNotification = showNotification;
        window.removeNotification = removeNotification;

        // --- CONFIRMATION MODAL LOGIC (NEW) ---
        function showConfirmModal(message, onConfirm, title = 'Are you sure?') {
            document.getElementById('confirmModalTitle').textContent = title;
            document.getElementById('confirmModalMessage').innerHTML = message;
            confirmAction = onConfirm;
            document.getElementById('confirmModal').style.display = 'block';

            // Attach event listener to the confirm button
            const confirmButton = document.getElementById('confirmModalConfirmButton');
            confirmButton.onclick = () => {
                if (typeof confirmAction === 'function') {
                    confirmAction();
                }
                closeConfirmModal();
            };
        }

        function closeConfirmModal() {
            document.getElementById('confirmModal').style.display = 'none';
            confirmAction = null;
        }

        function initializeTimetable() {
            Promise.all([
                loadStaff(),
                loadSubjects(),
                loadTimeSlots()
            ]).then(() => {
                console.log('Initial data loaded successfully.');
                updateStats();
            }).catch(error => {
                console.error("Initialization failed:", error);
                showNotification("Failed to load initial data. Please refresh the page.", 'error', 'Initialization Failed');
            });
        }

        // --- CORE API FUNCTION ---
        async function apiCall(action, data = {}) {
            const formData = new FormData();
            formData.append('action', action);
            for (const [key, value] of Object.entries(data)) {
                if (typeof value === 'object' && value !== null) {
                    formData.append(key, JSON.stringify(value));
                } else {
                    formData.append(key, value);
                }
            }
            try {
                const response = await fetch('api/timetable_api.php', {
                    method: 'POST',
                    body: formData
                });
                if (!response.ok) {
                    throw new Error(`Network response was not ok, status: ${response.status}`);
                }
                return await response.json();
            } catch (error) {
                console.error(`API Error on action [${action}]:`, error);
                return {
                    success: false,
                    error: error.message
                };
            }
        }

        // --- DATA LOADING FUNCTIONS ---
        async function loadStaff() {
            const result = await apiCall('get_staff');
            if (result.success) {
                staffList = result.data;
            } else {
                showNotification('Failed to load the staff list from the server.', 'error', 'Data Error');
            }
        }

        async function loadSubjects() {
            const result = await apiCall('get_subjects');
            if (result.success) {
                subjectsList = result.data;
            } else {
                showNotification('Failed to load the subjects list from the server.', 'error', 'Data Error');
            }
        }

        async function loadTimeSlots() {
            const result = await apiCall('get_time_slots');
            if (result.success) {
                customTimeSlots = result.data.map(slot => ({
                    time: slot.time,
                    type: slot.slot_type,
                    start_time: slot.start_time,
                    end_time: slot.end_time,
                    time_index: slot.time_index
                }));
                displayTimeSlots();
            } else {
                showNotification('Failed to load time slots.', 'error', 'Data Error');
            }
        }

        async function updateStats() {
            const result = await apiCall('get_stats');
            if (result.success) {
                document.getElementById('totalClasses').textContent = result.data.total_classes || 6;
                document.getElementById('totalSubjects').textContent = result.data.total_subjects || 0;
                document.getElementById('totalTeachers').textContent = result.data.total_teachers || 0;
            }
        }

        // --- TIME SLOT MANAGEMENT ---
        function toggleTimeManagement() {
            const content = document.getElementById('timeManagementContent');
            const btn = document.getElementById('timeToggleBtn');
            content.classList.toggle('show');
            if (content.classList.contains('show')) {
                btn.textContent = '▲ Hide';
                btn.style.transform = 'rotate(180deg)';
            } else {
                btn.textContent = '▼ Show';
                btn.style.transform = 'rotate(0deg)';
            }
        }

        async function addTimeSlot() {
            const startTime = document.getElementById('startTime').value;
            const endTime = document.getElementById('endTime').value;
            const slotType = document.getElementById('slotType').value;

            if (!startTime || !endTime || startTime >= endTime) {
                showNotification('End time must be after start time.', 'error', 'Invalid Time');
                return;
            }

            const newSlot = {
                type: slotType,
                start_time: startTime,
                end_time: endTime
            };
            customTimeSlots.push(newSlot);
            customTimeSlots.sort((a, b) => a.start_time.localeCompare(b.start_time));
            customTimeSlots.forEach((slot, index) => slot.time_index = index);

            if (await saveTimeSlots()) {
                await loadTimeSlots();
                if (currentClass) await generateTimetableGrid();
                const typeEmoji = slotType === 'break' ? '' : slotType === 'lunch' ? '' : '';
                showNotification(`${typeEmoji} ${slotType.charAt(0).toUpperCase() + slotType.slice(1)} time slot added.`, 'success', 'Time Slot Added');
                document.getElementById('startTime').value = '';
                document.getElementById('endTime').value = '';
                document.getElementById('slotType').value = 'regular';
            } else {
                showNotification('Failed to save time slot. Please try again.', 'error', 'Save Failed');
            }
        }

        function removeTimeSlot(index) {
            showConfirmModal('Do you really want to remove this time slot?', async () => {
                customTimeSlots.splice(index, 1);
                customTimeSlots.forEach((slot, idx) => slot.time_index = idx);
                if (await saveTimeSlots()) {
                    displayTimeSlots();
                    if (currentClass) await generateTimetableGrid();
                    showNotification('Time slot has been removed.', 'success');
                }
            });
        }

        function clearAllTimeSlots() {
            showConfirmModal('This will remove ALL time slots. This action cannot be undone.', async () => {
                customTimeSlots = [];
                if (await saveTimeSlots()) {
                    displayTimeSlots();
                    if (currentClass) {
                        currentTimetable = {};
                        await generateTimetableGrid();
                    }
                    showNotification('All time slots have been cleared.', 'success', 'Cleared');
                }
            });
        }

        function displayTimeSlots() {
            const container = document.getElementById('timeSlotsList');
            container.innerHTML = '';
            if (customTimeSlots.length === 0) {
                container.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">No time slots defined.</p>';
                return;
            }
            customTimeSlots.forEach((slot, index) => {
                const item = document.createElement('div');
                item.className = `time-slot-item ${slot.type}`;
                const typeEmoji = slot.type === 'break' ? '' : slot.type === 'lunch' ? '' : '';
                item.innerHTML = `
                <div>
                    <span>${slot.time}</span>
                    <span class="slot-type-badge ${slot.type}">${typeEmoji} ${slot.type.charAt(0).toUpperCase() + slot.type.slice(1)}</span>
                </div>
                <button class="remove-time-btn" onclick="removeTimeSlot(${index})">Remove</button>
            `;
                container.appendChild(item);
            });
        }

        async function saveTimeSlots() {
            const result = await apiCall('save_time_slots', {
                time_slots: customTimeSlots
            });
            if (!result.success) {
                showNotification('Failed to save time slots: ' + result.error, 'error', 'Save Error');
                return false;
            }
            return true;
        }

        // --- TIMETABLE CORE LOGIC ---
        async function loadTimetable() {
            if (!validateRequiredFields()) return;
            currentClass = document.getElementById('classSelect').value;
            currentStream = document.getElementById('streamSelect').value;
            currentTerm = document.getElementById('termSelect').value;
            currentYear = document.getElementById('yearSelect').value;
            document.getElementById('currentClassBadge').textContent = `${currentClass} ${currentStream}`;
            const result = await apiCall('load_timetable', {
                class_name: currentClass,
                stream: currentStream,
                term: currentTerm,
                year: currentYear
            });
            if (result.success) {
                currentTimetable = result.data || {};
                showTimetable();
                await generateTimetableGrid();
                await updateStats();
                showNotification(`Timetable for ${currentClass} ${currentStream} loaded successfully.`, 'success');
            } else {
                showNotification('Failed to load timetable: ' + result.error, 'error');
            }
        }

        function createNewTimetable() {
            if (!validateRequiredFields()) return;
            const className = document.getElementById('classSelect').value;
            const streamName = document.getElementById('streamSelect').value;
            showConfirmModal(`Create a new, empty timetable for <b>${className} ${streamName}</b>?`, async () => {
                currentClass = className;
                currentStream = streamName;
                currentTerm = document.getElementById('termSelect').value;
                currentYear = document.getElementById('yearSelect').value;
                document.getElementById('currentClassBadge').textContent = `${currentClass} ${currentStream}`;
                currentTimetable = {};
                showTimetable();
                await generateTimetableGrid();
                await updateStats();
                showNotification(`New empty timetable created for ${currentClass} ${currentStream}.`, 'info', 'Ready to Edit');
            });
        }

        function clearTimetable() {
            if (!currentClass) {
                showNotification("Please load a timetable first before clearing.", "warning");
                return;
            }
            showConfirmModal('Do you want to clear this entire timetable? This cannot be undone.', async () => {
                const result = await apiCall('clear_timetable', {
                    class_name: currentClass,
                    stream: currentStream,
                    term: currentTerm,
                    year: currentYear
                });
                if (result.success) {
                    currentTimetable = {};
                    await generateTimetableGrid();
                    await updateStats();
                    showNotification('Timetable cleared successfully!', 'success', 'Timetable Cleared');
                } else {
                    showNotification('Failed to clear timetable: ' + result.error, 'error');
                }
            });
        }

        // --- GRID AND DISPLAY ---
        function showTimetable() {
            document.getElementById('timetableView').style.display = 'block';
            document.getElementById('emptyState').style.display = 'none';
        }

        async function generateTimetableGrid() {
            const timetableBody = document.getElementById('timetableBody');
            timetableBody.innerHTML = '';
            if (customTimeSlots.length === 0) {
                const row = timetableBody.insertRow();
                row.insertCell().innerHTML = 'Please define time slots first using the "Time Slot Management" section.';
                row.cells[0].colSpan = days.length + 1;
                row.cells[0].style.textAlign = 'center';
                row.cells[0].style.padding = '40px';
                return;
            }
            customTimeSlots.forEach((slotObj, slotIndex) => {
                const row = timetableBody.insertRow();
                row.insertCell().innerHTML = slotObj.time.replace(' - ', '<br>');
                row.cells[0].className = 'time-slot';
                days.forEach(day => {
                    const cell = row.insertCell();
                    cell.className = 'subject-cell';
                    const cellKey = `${day}-${slotIndex}`;
                    const entry = currentTimetable[cellKey];
                    if (slotObj.type === 'break' || slotObj.type === 'lunch') {
                        cell.innerHTML = slotObj.type === 'break' ? '<br>Break' : '<br>Lunch';
                        cell.classList.add(slotObj.type);
                    } else if (entry) {
                        cell.classList.add('filled');
                        // **MODIFICATION HERE**
                        const paperText = entry.paper_number ? ` (Paper ${entry.paper_number})` : '';
                        cell.innerHTML = `
                <div class="subject-name">${entry.subject_name}${paperText}</div>
                <div class="teacher-name">${entry.teacher_name}</div>
                <div class="room-number">Room: ${entry.room_number || 'N/A'}</div>
            `;
                        cell.onclick = () => openSubjectModal(day, slotIndex, entry);
                    } else {
                        cell.innerHTML = '<div class="add-subject"> Add</div>';
                        cell.onclick = () => openSubjectModal(day, slotIndex);
                    }
                });
            });
        }

        // --- MODAL (POPUP) LOGIC ---
        function openSubjectModal(day, slotIndex, existingData = null) {
            currentCell = {
                day,
                slotIndex
            };
            populateSubjectDropdown();
            populateTeacherDropdown();
            const modal = document.getElementById('subjectModal');
            const title = modal.querySelector('.modal-title');
            const removeBtn = document.getElementById('removeSubject');
            // **MODIFICATION HERE**
            const paperSelect = document.getElementById('paperSelect');

            if (existingData) {
                title.textContent = 'Edit Subject';
                document.getElementById('subjectName').value = existingData.subject_id;
                paperSelect.value = existingData.paper_number || ''; // Set paper number
                document.getElementById('teacherSelect').value = existingData.staff_id;
                document.getElementById('roomNumber').value = existingData.room_number || '';
                removeBtn.style.display = 'inline-block';
            } else {
                title.textContent = 'Add Subject';
                document.getElementById('subjectName').value = '';
                paperSelect.value = ''; // Clear paper number
                document.getElementById('teacherSelect').value = '';
                document.getElementById('roomNumber').value = '';
                removeBtn.style.display = 'none';
            }
            modal.style.display = 'block';
        }

        function closeModal() {
            document.getElementById('subjectModal').style.display = 'none';
            currentCell = null;
        }

        function populateSubjectDropdown() {
            const select = document.getElementById('subjectName');
            select.innerHTML = '<option value="">Select Subject</option>';
            subjectsList.forEach(subject => {
                select.innerHTML += `<option value="${subject.id}">${subject.subject_name}</option>`;
            });
        }

        function populateTeacherDropdown() {
            const select = document.getElementById('teacherSelect');
            select.innerHTML = '<option value="">Select Teacher</option>';
            staffList.forEach(staff => {
                select.innerHTML += `<option value="${staff.id}">${staff.full_name}</option>`;
            });
        }

        // --- DATA MANIPULATION (SAVE/DELETE) ---
        async function saveSubject() {
            const subjectId = document.getElementById('subjectName').value;
            // **MODIFICATION HERE**
            const paperNumber = document.getElementById('paperSelect').value;
            const staffId = document.getElementById('teacherSelect').value;
            const roomNumber = document.getElementById('roomNumber').value;

            // Updated validation
            if (!subjectId || !staffId || !paperNumber) {
                showNotification('Please select a subject, paper number, and teacher.', 'warning', 'Missing Information');
                return;
            }
            const entryData = {
                class_name: currentClass,
                stream: currentStream,
                term: currentTerm,
                academic_year: currentYear,
                day_of_week: currentCell.day,
                time_index: currentCell.slotIndex,
                subject_id: subjectId,
                paper_number: paperNumber, // Add paper number to data
                staff_id: staffId,
                room_number: roomNumber
            };
            const result = await apiCall('save_entry', entryData);
            if (result.success) {
                const subjectName = subjectsList.find(s => s.id == subjectId)?.subject_name || 'N/A';
                const teacherName = staffList.find(t => t.id == staffId)?.full_name || 'N/A';
                const cellKey = `${currentCell.day}-${currentCell.slotIndex}`;
                currentTimetable[cellKey] = {
                    ...entryData,
                    subject_name: subjectName,
                    teacher_name: teacherName
                };
                await generateTimetableGrid();
                await updateStats();
                closeModal();
                showNotification(`<b>${subjectName} (Paper ${paperNumber})</b> was added to the timetable.`, 'success', 'Subject Added');
            } else if (result.conflicts) {
                const conflictMessage = result.conflicts.join('<br>');
                showNotification(`<b>Scheduling Conflict:</b><br>${conflictMessage}`, 'error', 'Conflict Detected', 6000);
            } else {
                showNotification('Failed to save entry: ' + result.error, 'error', 'Save Failed');
            }
        }

        function removeSubject() {
            showConfirmModal('Do you want to remove this timetable entry?', async () => {
                const result = await apiCall('delete_entry', {
                    class_name: currentClass,
                    stream: currentStream,
                    term: currentTerm,
                    year: currentYear,
                    day_of_week: currentCell.day,
                    time_index: currentCell.slotIndex
                });
                if (result.success) {
                    const cellKey = `${currentCell.day}-${currentCell.slotIndex}`;
                    delete currentTimetable[cellKey];
                    await generateTimetableGrid();
                    await updateStats();
                    closeModal();
                    showNotification('The timetable entry has been removed.', 'success');
                } else {
                    showNotification('Failed to remove subject: ' + result.error, 'error');
                }
            });
        }

        // --- UTILITY FUNCTIONS ---
        function validateRequiredFields() {
            const fields = {
                "Class": document.getElementById('classSelect').value,
                "Stream": document.getElementById('streamSelect').value,
                "Term": document.getElementById('termSelect').value,
                "Year": document.getElementById('yearSelect').value
            };
            const missing = Object.keys(fields).filter(key => !fields[key]);
            if (missing.length > 0) {
                showNotification(`Please select the following: ${missing.join(', ')}`, 'warning', 'Missing Fields');
                return false;
            }
            return true;
        }

        function exportTimetable() {
            if (!currentClass) {
                showNotification('Please load a timetable first before exporting.', 'warning');
                return;
            }
            let csv = 'Time,' + days.map(d => d.charAt(0).toUpperCase() + d.slice(1)).join(',') + '\n';
            customTimeSlots.forEach((slot, slotIndex) => {
                const row = [slot.time];
                days.forEach(day => {
                    const key = `${day}-${slotIndex}`;
                    const entry = currentTimetable[key];
                    if (entry) {
                        row.push(`"${entry.subject_name} (${entry.teacher_name}) - Room: ${entry.room_number || 'N/A'}"`);
                    } else if (slot.type !== 'regular') {
                        row.push(slot.type.charAt(0).toUpperCase() + slot.type.slice(1));
                    } else {
                        row.push('');
                    }
                });
                csv += row.join(',') + '\n';
            });
            const blob = new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `${currentClass}_${currentStream}_Timetable.csv`;
            link.click();
            URL.revokeObjectURL(link.href);
            showNotification('Timetable has been exported as a CSV file.', 'success');
        }

        // UPDATED FUNCTION: EXPORT AS PDF WITH CINZEL AND QUICKSAND FONTS
      // UPDATED FUNCTION: EXPORT AS PDF WITH OPTIMIZED SPACING
        function exportPDF() {
            if (!currentClass) {
                showNotification('Please load a timetable first before exporting.', 'warning');
                return;
            }

            const {
                jsPDF
            } = window.jspdf;
            const doc = new jsPDF({
                orientation: 'landscape'
            });

            const head = [
                ['Time', ...days.map(d => d.charAt(0).toUpperCase() + d.slice(1))]
            ];
            const body = [];

            customTimeSlots.forEach((slot, slotIndex) => {
                const row = [slot.time];
                days.forEach(day => {
                    const key = `${day}-${slotIndex}`;
                    const entry = currentTimetable[key];
                    if (entry) {
                        const paperText = entry.paper_number ? ` (P${entry.paper_number})` : '';
                        row.push(`${entry.subject_name}${paperText}\n${entry.teacher_name}\nRm: ${entry.room_number || 'N/A'}`);
                    } else if (slot.type !== 'regular') {
                        row.push(slot.type.charAt(0).toUpperCase() + slot.type.slice(1));
                    } else {
                        row.push('');
                    }
                });
                body.push(row);
            });

            // --- RENDER PDF WITH OPTIMIZED SPACING ---

            // 1. Title with reduced size
            const title = `Timetable for ${currentClass} ${currentStream} - (${currentTerm} ${currentYear})`;
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(14);
            doc.text(title, 14, 15);

            // 2. Compact table with minimal padding
            doc.autoTable({
                head: head,
                body: body,
                startY: 22,
                theme: 'grid',
                styles: {
                    font: 'helvetica',
                    fontStyle: 'normal',
                    cellPadding: 1.5, // Reduced from 2
                    fontSize: 8, // Reduced from 12
                    valign: 'middle',
                    halign: 'center',
                    lineWidth: 0.1,
                    lineColor: [200, 200, 200]
                },
                headStyles: {
                    font: 'helvetica',
                    fillColor: [46, 125, 50],
                    textColor: [255, 255, 255],
                    fontStyle: 'bold',
                    fontSize: 9, // Slightly larger for headers
                    cellPadding: 2
                },
                columnStyles: {
                    0: { cellWidth: 28 } // Time column slightly narrower
                },
                margin: { top: 22, left: 10, right: 10, bottom: 10 }
            });

            doc.save(`${currentClass}_${currentStream}_Timetable.pdf`);
            showNotification('Timetable has been exported as a PDF file.', 'success');
        }
        // --- EVENT LISTENERS & GLOBAL EXPOSURE ---
        window.onclick = function(event) {
            const subjectModal = document.getElementById('subjectModal');
            const confirmModal = document.getElementById('confirmModal');
            if (event.target == subjectModal) {
                closeModal();
            }
            if (event.target == confirmModal) {
                closeConfirmModal();
            }
        };

        window.toggleTimeManagement = toggleTimeManagement;
        window.addTimeSlot = addTimeSlot;
        window.removeTimeSlot = removeTimeSlot;
        window.clearAllTimeSlots = clearAllTimeSlots;
        window.loadTimetable = loadTimetable;
        window.createNewTimetable = createNewTimetable;
        window.exportTimetable = exportTimetable;
        window.exportPDF = exportPDF;
        window.clearTimetable = clearTimetable;
        window.openSubjectModal = openSubjectModal;
        window.closeModal = closeModal;
        window.saveSubject = saveSubject;
        window.removeSubject = removeSubject;
        window.closeConfirmModal = closeConfirmModal; // Expose to global scope for onclick
    </script>

</body>

</html>