<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Exams Timetable");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- PDF Generation Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <!-- Custom Fonts for PDF (ensure these files exist in your project) -->
    <script src="Cinzel-Regular-normal.js"></script>
    <script src="Quicksand-Regular-normal.js"></script>
    <title>Exam Timetable - School Pilot</title>
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
            font-family: 'Quicksand', sans-serif;
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
            font-family: 'Cinzel', serif;
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .header p {
            color: var(--text-secondary);
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
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .form-group input,
        .form-group select {
            padding: 12px;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            font-size: 14px;
            transition: var(--transition);
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.2);
        }

        .btnn {
            padding: 12px 24px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: var(--transition);
            margin: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btnn-primary {
            background: var(--primary-green);
            color: var(--white);
        }

        .btnn-primary:hover {
            background: var(--dark-green);
            transform: translateY(-2px);
            box-shadow: var(--shadow-medium);
        }

        .btnn-success {
            background: var(--success-color);
            color: var(--white);
        }

        .btnn-success:hover {
            background: #388e3c;
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
            justify-content: flex-start;
        }

        .timetable-container {
            background: var(--white);
            border-radius: 15px;
            padding: 30px;
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
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
        }

        .timetable-title {
            font-family: 'Cinzel', serif;
            color: var(--text-primary);
        }

        .date-group {
            margin-bottom: 30px;
        }

        .date-header {
            font-size: 1.4rem;
            color: var(--dark-green);
            margin-bottom: 15px;
            padding-bottom: 5px;
            border-bottom: 1px solid var(--light-green);
        }

        .paper-entry {
            display: grid;
            grid-template-columns: 150px 1fr 1fr 1fr 120px;
            gap: 15px;
            align-items: center;
            padding: 15px;
            border-radius: var(--border-radius);
            background: #f9faf9;
            margin-bottom: 10px;
            border-left: 5px solid var(--accent-green);
        }

        .paper-time {
            font-weight: 600;
            color: var(--text-primary);
        }

        .paper-subject,
        .paper-invigilator,
        .paper-room {
            color: var(--text-muted);
        }

        .paper-subject strong {
            color: var(--text-secondary);
            font-weight: 600;
        }

        .paper-actions button {
            background: none;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            padding: 8px 12px;
            border-radius: 5px;
            cursor: pointer;
            margin-left: 5px;
            font-size: 12px;
            width: 100%;
            transition: var(--transition);
        }

        .paper-actions button.btn-edit {
            background: var(--primary-green);
            color: var(--white);
            border-color: var(--primary-green);
        }

        .paper-actions button.btn-edit:hover {
            background: var(--dark-green);
        }

        /* --- Modal Styles --- */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: linear-gradient(to top right, #fdfefe, #f5f9f5);
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
            animation: fadeIn 0.4s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
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
            font-family: 'Cinzel', serif;
        }

        .close-btnn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-muted);
        }

        .close-btnn:hover {
            color: var(--text-primary);
        }

        .modal-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        /* --- Notification Styles --- */
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

        .notification-message {
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.4;
        }

        .notification-icon {
            margin-right: 8px;
        }

        .fade-in {
            animation: fadeInPage 0.5s ease-in-out;
        }

        @keyframes fadeInPage {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>

<body>
    <?php require_once 'nav.php'; ?>

    <div class="notification-container" id="notificationContainer"></div>

    <div class="container">
        <div class="header fade-in">
            <h1>Examination Timetable</h1>
            <p>Schedule and manage examination papers for each class and stream.</p>
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
                    <label for="examTypeSelect">Exam Type</label>
                    <select id="examTypeSelect">
                        <option value="">Loading exam types...</option>
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
                <button class="btnn btnn-primary" onclick="loadExamTimetable()">
                     Load Timetable
                </button>
                <button class="btnn btnn-secondary" onclick="exportExamPDF()">
                    Export PDF
                </button>
            </div>
        </div>

        <div class="timetable-container fade-in">
            <div id="emptyState" class="empty-state">
                <h3>No Timetable Loaded</h3>
                <p>Please select all fields above, then click "Load Timetable".</p>
            </div>

            <div id="timetableView" style="display: none;">
                <div class="timetable-header">
                    <h2 class="timetable-title" id="currentTimetableTitle">Exam Timetable</h2>
                    <button class="btnn btnn-success" onclick="openExamModal()">
                         Add New Paper
                    </button>
                </div>
                <div id="timetableContent">
                    <!-- Exam papers will be dynamically inserted here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for adding/editing exam entries -->
    <div id="examModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Add Exam Paper</h3>
                <button class="close-btnn" onclick="closeModal()">&times;</button>
            </div>
            <div class="form-group">
                <label for="subjectSelect">Subject</label>
                <select id="subjectSelect">
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
            <div class="modal-grid">
                <div class="form-group">
                    <label for="examDate">Date</label>
                    <input type="date" id="examDate">
                </div>
                <div class="form-group">
                    <label for="roomNumber">Room Number</label>
                    <input type="text" id="roomNumber" placeholder="e.g., Main Hall">
                </div>
                <div class="form-group">
                    <label for="startTime">Start Time</label>
                    <input type="time" id="startTime">
                </div>
                <div class="form-group">
                    <label for="endTime">End Time</label>
                    <input type="time" id="endTime">
                </div>
            </div>
            <div class="form-group" style="margin-top: 20px;">
                <label for="invigilatorSelect">Invigilator</label>
                <select id="invigilatorSelect">
                    <option value="">Select Invigilator</option>
                </select>
            </div>
            <input type="hidden" id="examEntryId">
            <div class="btnn-group" style="margin-top: 20px; justify-content: flex-end;">
                <button class="btnn btnn-danger" onclick="removeExamEntry()" id="removeExamEntryBtn" style="display: none;">Remove</button>
                <button class="btnn btnn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btnn btnn-primary" onclick="saveExamEntry()">Save Exam</button>
            </div>
        </div>
    </div>

    <div id="confirmationModal" class="modal">
        <div class="modal-content" style="max-width: 450px; text-align: center;">
            <h3 class="modal-title" style="font-size: 1.5rem;">Confirm Action</h3>
            <p id="confirmationMessage" style="margin: 20px 0; font-size: 1rem; color: var(--text-muted);">
                Are you sure you want to proceed?
            </p>
            <div class="btnn-group" style="justify-content: center;">
                <button id="confirmCancelBtn" class="btnn btnn-secondary">Cancel</button>
                <button id="confirmOkBtn" class="btnn btnn-danger">Confirm</button>
            </div>
        </div>
    </div>

    <script>
        // --- GLOBAL VARIABLES ---
        // These arrays store data fetched from the server to avoid repeated API calls.
        let staffList = [];
        let subjectsList = [];
        // These store the state of the currently viewed timetable.
        let currentExamData = [];
        let currentSelection = {};

        // --- INITIALIZATION ---
        // This ensures that the script runs only after the entire page is loaded.
        document.addEventListener('DOMContentLoaded', () => {
            console.log('DOM loaded, initializing exam timetable system...');
            initializePage();
        });

        /**
         * Fetches all necessary initial data (staff, subjects, exam types)
         * and populates the dropdowns in the modal.
         */
        function initializePage() {
            Promise.all([loadStaff(), loadSubjects(), loadExamSets()]).then(() => {
                console.log('Initial data loaded successfully.');
                populateDropdowns();
            }).catch(error => {
                console.error("Initialization failed:", error);
                showNotification("Failed to load initial data. Please refresh.", 'error');
            });
        }

        // --- UI HELPER FUNCTIONS (NOTIFICATIONS & CONFIRMATIONS) ---

        /**
         * Displays a custom notification toast at the top-right of the screen.
         * @param {string} message The message to display.
         * @param {string} type 'success', 'error', or 'warning'.
         * @param {string} title An optional title for the notification.
         * @param {number} duration How long the notification stays visible (in ms).
         */
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
                info: 'Info'
            };
            notification.innerHTML = `<div class="notification-header"><div class="notification-title"><span class="notification-icon">${icons[type]}</span> ${titles[type]}</div><button class="notification-close" onclick="this.parentElement.parentElement.remove()">×</button></div><div class="notification-message">${message}</div>`;
            container.appendChild(notification);
            setTimeout(() => notification.classList.add('show'), 100);
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 300);
            }, duration);
        }

        /**
         * Shows a confirmation modal and returns a promise that resolves
         * if the user confirms, and rejects if they cancel.
         * @param {string} message The message to display in the modal.
         * @returns {Promise<void>}
         */
        function showConfirmation(message) {
            return new Promise((resolve, reject) => {
                const modal = document.getElementById('confirmationModal');
                const messageEl = document.getElementById('confirmationMessage');
                const okBtn = document.getElementById('confirmOkBtn');
                const cancelBtn = document.getElementById('confirmCancelBtn');

                messageEl.textContent = message;
                modal.style.display = 'flex';

                const onOk = () => {
                    modal.style.display = 'none';
                    cleanup();
                    resolve();
                };

                const onCancel = () => {
                    modal.style.display = 'none';
                    cleanup();
                    reject();
                };

                // Add event listeners
                okBtn.addEventListener('click', onOk);
                cancelBtn.addEventListener('click', onCancel);

                // Cleanup function to remove listeners to prevent memory leaks
                function cleanup() {
                    okBtn.removeEventListener('click', onOk);
                    cancelBtn.removeEventListener('click', onCancel);
                }
            });
        }

        // --- API COMMUNICATION ---

        /**
         * A centralized function for making API calls to the backend.
         * @param {string} action The action to be performed by the API.
         * @param {object} data The data to send with the request.
         * @returns {Promise<object>} The JSON response from the server.
         */
        async function apiCall(action, data = {}) {
            const formData = new FormData();
            formData.append('action', action);
            for (const key in data) {
                formData.append(key, data[key]);
            }
            try {
                const response = await fetch('api/exam_api.php', {
                    method: 'POST',
                    body: formData
                });
                if (!response.ok) throw new Error(`Network error: ${response.statusText}`);
                const result = await response.json();
                if (result.error && result.error.includes("SQL prepare failed")) {
                    console.error("A severe SQL error occurred:", result.error);
                    showNotification("A database error occurred. Please check table/column names.", 'error');
                }
                return result;
            } catch (error) {
                console.error(`API Error on action [${action}]:`, error);
                showNotification(`An API error occurred. Check the console for details.`, 'error');
                return {
                    success: false,
                    error: error.message
                };
            }
        }

        // --- DATA LOADING & POPULATION ---

        async function loadStaff() {
            const result = await apiCall('get_staff');
            if (result.success) staffList = result.data;
        }
        async function loadSubjects() {
            const result = await apiCall('get_subjects');
            if (result.success) subjectsList = result.data;
        }
        async function loadExamSets() {
            const result = await apiCall('get_exam_sets');
            const select = document.getElementById('examTypeSelect');
            if (result.success) {
                select.innerHTML = '<option value="">Choose exam type</option>';
                result.data.forEach(set => {
                    select.innerHTML += `<option value="${set.exam_set}">${set.exam_set} - ${set.description}</option>`;
                });
            } else {
                select.innerHTML = '<option value="">Error loading types</option>';
                showNotification('Failed to load exam types from the server.', 'error');
            }
        }

        function populateDropdowns() {
            const subjectSelect = document.getElementById('subjectSelect');
            subjectSelect.innerHTML = '<option value="">Select Subject</option>';
            subjectsList.forEach(s => subjectSelect.innerHTML += `<option value="${s.id}">${s.subject_name}</option>`);

            const invigilatorSelect = document.getElementById('invigilatorSelect');
            invigilatorSelect.innerHTML = '<option value="">Select Invigilator</option>';
            staffList.forEach(t => invigilatorSelect.innerHTML += `<option value="${t.id}">${t.full_name}</option>`);
        }

        // --- CORE TIMETABLE LOGIC ---

        function validateSelection() {
            currentSelection = {
                class_name: document.getElementById('classSelect').value,
                stream: document.getElementById('streamSelect').value,
                term: document.getElementById('termSelect').value,
                exam_type: document.getElementById('examTypeSelect').value,
                year: document.getElementById('yearSelect').value
            };
            if (!currentSelection.class_name || !currentSelection.stream || !currentSelection.term || !currentSelection.exam_type || !currentSelection.year) {
                showNotification('Please select all fields: Class, Stream, Term, Exam Type, and Year.', 'warning');
                return false;
            }
            return true;
        }

        async function loadExamTimetable() {
            if (!validateSelection()) return;

            document.getElementById('currentTimetableTitle').textContent = `Timetable for ${currentSelection.class_name} ${currentSelection.stream} (${currentSelection.exam_type})`;

            const result = await apiCall('load_exam_timetable', currentSelection);
            if (result.success) {
                currentExamData = result.data || [];
                displayExamTimetable();
                document.getElementById('timetableView').style.display = 'block';
                document.getElementById('emptyState').style.display = 'none';
                showNotification('Exam timetable loaded successfully.', 'success');
            } else {
                showNotification('Failed to load exam timetable: ' + (result.error || 'Unknown error'), 'error');
            }
        }

        function displayExamTimetable() {
            const contentDiv = document.getElementById('timetableContent');
            contentDiv.innerHTML = '';

            if (currentExamData.length === 0) {
                contentDiv.innerHTML = '<p style="text-align:center; padding: 40px; color: var(--text-muted);">No exam papers have been scheduled. Click "Add New Paper" to start.</p>';
                return;
            }

            const groupedByDate = currentExamData.reduce((acc, paper) => {
                (acc[paper.exam_date] = acc[paper.exam_date] || []).push(paper);
                return acc;
            }, {});

            const sortedDates = Object.keys(groupedByDate).sort((a, b) => new Date(a) - new Date(b));

            sortedDates.forEach(date => {
                const dateGroup = document.createElement('div');
                dateGroup.className = 'date-group';

                const formattedDate = new Date(date + 'T00:00:00').toLocaleDateString('en-US', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
                dateGroup.innerHTML = `<h3 class="date-header">${formattedDate}</h3>`;

                groupedByDate[date].sort((a, b) => a.start_time.localeCompare(b.start_time));

                groupedByDate[date].forEach(paper => {
                    const paperDiv = document.createElement('div');
                    paperDiv.className = 'paper-entry';

                    const startTime = formatTime(paper.start_time);
                    const endTime = formatTime(paper.end_time);

                    // FIXED: Better handling of paper number and room number
                    const paperTitle = paper.paper_number && paper.paper_number !== '0' ? `(Paper ${paper.paper_number})` : '';
                    const roomDisplay = paper.room_number && paper.room_number !== '0' && paper.room_number.trim() !== '' ? paper.room_number : 'N/A';

                    paperDiv.innerHTML = `
                <div class="paper-time">${startTime} - ${endTime}</div>
                <div class="paper-subject"><strong>${paper.subject_name} ${paperTitle}</strong></div>
                <div class="paper-invigilator">Invigilator: ${paper.teacher_name}</div>
                <div class="paper-room">Room: ${roomDisplay}</div>
                <div class="paper-actions">
                    <button class="btn-edit" onclick='openExamModal(${JSON.stringify(paper).replace(/'/g, "&apos;")})'>Edit</button>
                </div>
            `;
                    dateGroup.appendChild(paperDiv);
                });
                contentDiv.appendChild(dateGroup);
            });
        }


        function formatTime(timeString) {
            if (!timeString) return '';
            const [hour, minute] = timeString.split(':');
            const h = parseInt(hour, 10);
            const ampm = h >= 12 ? 'PM' : 'AM';
            const formattedHour = h % 12 === 0 ? 12 : h % 12;
            return `${formattedHour}:${minute} ${ampm}`;
        }

        // --- MODAL LOGIC ---

        function openExamModal(existingData = null) {
            if (!currentSelection.class_name) {
                showNotification('Please load a timetable before adding a paper.', 'warning');
                return;
            }

            const modal = document.getElementById('examModal');
            const title = document.getElementById('modalTitle');
            const removeBtn = document.getElementById('removeExamEntryBtn');

            // Reset form fields
            document.getElementById('examEntryId').value = '';
            document.getElementById('subjectSelect').value = '';
            document.getElementById('paperSelect').value = '';
            document.getElementById('examDate').value = '';
            document.getElementById('startTime').value = '';
            document.getElementById('endTime').value = '';
            document.getElementById('invigilatorSelect').value = '';
            document.getElementById('roomNumber').value = '';

            if (existingData) {
                // Populate form if editing an existing entry
                title.textContent = 'Edit Exam Paper';
                document.getElementById('examEntryId').value = existingData.id || '';
                document.getElementById('subjectSelect').value = existingData.subject_id || '';

                // FIXED: Properly set paper number even if it's a roman numeral
                if (existingData.paper_number) {
                    document.getElementById('paperSelect').value = existingData.paper_number.toString();
                }

                document.getElementById('examDate').value = existingData.exam_date || '';
                document.getElementById('startTime').value = existingData.start_time || '';
                document.getElementById('endTime').value = existingData.end_time || '';
                document.getElementById('invigilatorSelect').value = existingData.staff_id || '';

                // FIXED: Properly handle room_number
                document.getElementById('roomNumber').value = existingData.room_number && existingData.room_number !== '0' ? existingData.room_number : '';

                removeBtn.style.display = 'inline-block';
            } else {
                title.textContent = 'Add New Exam Paper';
                removeBtn.style.display = 'none';
            }
            modal.style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('examModal').style.display = 'none';
        }

        async function saveExamEntry() {
            // Gather all data from the form
            const entryData = {
                ...currentSelection,
                id: document.getElementById('examEntryId').value,
                subject_id: document.getElementById('subjectSelect').value,
                paper_number: document.getElementById('paperSelect').value, // Get paper number
                exam_date: document.getElementById('examDate').value,
                start_time: document.getElementById('startTime').value,
                end_time: document.getElementById('endTime').value,
                staff_id: document.getElementById('invigilatorSelect').value,
                room_number: document.getElementById('roomNumber').value
            };

            // Validate required fields
            if (!entryData.subject_id || !entryData.paper_number || !entryData.exam_date || !entryData.start_time || !entryData.end_time || !entryData.staff_id) {
                showNotification('Please fill all required fields: Subject, Paper Number, Date, Start/End Times, and Invigilator.', 'warning');
                return;
            }

            if (entryData.start_time >= entryData.end_time) {
                showNotification('End time must be after start time.', 'error');
                return;
            }

            const result = await apiCall('save_exam_entry', entryData);
            if (result.success) {
                closeModal();
                await loadExamTimetable(); // Reload to show changes
                showNotification('Exam paper saved successfully.', 'success');
            } else {
                showNotification('Failed to save paper: ' + (result.error || 'Unknown error'), 'error');
            }
        }

        async function removeExamEntry() {
            try {
                await showConfirmation('Are you sure you want to remove this exam paper? This action cannot be undone.');

                const entryId = document.getElementById('examEntryId').value;
                const result = await apiCall('delete_exam_entry', {
                    id: entryId
                });

                if (result.success) {
                    closeModal();
                    await loadExamTimetable();
                    showNotification('Exam paper has been removed.', 'success');
                } else {
                    showNotification('Failed to remove paper: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch {
                // User clicked "Cancel" in the confirmation modal
                console.log('User cancelled the deletion.');
            }
        }

        // --- UTILITY FUNCTIONS ---

        function exportExamPDF() {
            if (currentExamData.length === 0) {
                showNotification('Please load a timetable with data before exporting.', 'warning');
                return;
            }

            const {
                jsPDF
            } = window.jspdf;
            const doc = new jsPDF({
                orientation: 'portrait'
            });

            doc.setFont('Cinzel-Regular', 'normal');
            doc.setFontSize(18);
            doc.text('Examination Timetable', 105, 20, {
                align: 'center'
            });

            doc.setFont('Quicksand-Regular', 'normal');
            doc.setFontSize(12);
            const headerText = `${currentSelection.class_name} ${currentSelection.stream} - ${currentSelection.term}, ${currentSelection.year}`;
            doc.text(headerText, 105, 28, {
                align: 'center'
            });
            doc.setFontSize(11);
            doc.text(`Exam: ${currentSelection.exam_type}`, 105, 34, {
                align: 'center'
            });
            doc.setFontSize(9);
            doc.text(`Date Printed: ${new Date().toLocaleDateString()}`, 105, 39, {
                align: 'center'
            });

            const head = [
                ['Date', 'Time', 'Subject', 'Invigilator', 'Room']
            ];
            const body = [];

            const sortedData = [...currentExamData].sort((a, b) => new Date(a.exam_date + ' ' + a.start_time) - new Date(b.exam_date + ' ' + b.start_time));

            sortedData.forEach(paper => {
                const date = new Date(paper.exam_date + 'T00:00:00').toLocaleDateString('en-GB', {
                    weekday: 'short',
                    day: '2-digit',
                    month: 'short'
                });
                const time = `${formatTime(paper.start_time)} - ${formatTime(paper.end_time)}`;

                // **CHANGE**: Add the paper number to the subject name for the PDF.
                const subjectTitle = `${paper.subject_name} (Paper ${paper.paper_number || 'N/A'})`;

                body.push([date, time, subjectTitle, paper.teacher_name, paper.room_number || 'N/A']);
            });

            doc.autoTable({
                head: head,
                body: body,
                startY: 45,
                theme: 'grid',
                styles: {
                    font: 'Quicksand-Regular',
                    fontStyle: 'normal',
                    fontSize: 10
                },
                headStyles: {
                    font: 'Quicksand-Regular',
                    fillColor: [46, 125, 50],
                    textColor: [255, 255, 255],
                    fontStyle: 'bold'
                }
            });

            const filename = `Exam_Timetable_${currentSelection.class_name}_${currentSelection.stream}.pdf`;
            doc.save(filename);
            showNotification('Timetable exported as PDF.', 'success');
        }

        // --- GLOBAL EVENT LISTENERS & EXPORTS ---
        // Close modals if user clicks outside of them
        window.onclick = (event) => {
            if (event.target == document.getElementById('examModal')) {
                closeModal();
            }
            if (event.target == document.getElementById('confirmationModal')) {
                // We let the buttons handle the confirmation modal closing
            }
        };

        // Expose functions to the global scope so they can be called from HTML onclick attributes
        window.loadExamTimetable = loadExamTimetable;
        window.exportExamPDF = exportExamPDF;
        window.openExamModal = openExamModal;
        window.closeModal = closeModal;
        window.saveExamEntry = saveExamEntry;
        window.removeExamEntry = removeExamEntry;
    </script>

</body>

</html>