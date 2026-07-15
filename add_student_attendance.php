<?php
require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Student Attendance");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Attendance - School Pilot</title>
    <style>
        /* Your existing CSS here */
        * { margin: 0; padding: 0; box-sizing: border-box; } body { background: linear-gradient(135deg, #e8f5e8 0%, #f0fff0 100%); min-height: 100vh; padding: 20px; } .container { max-width: 1400px; margin: 0 auto; margin-top: 50px; background: white; border-radius: 15px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1); overflow: hidden; } .header { background: linear-gradient(135deg, #2e7d32 0%, #4caf50 100%); color: white; padding: 20px 25px; text-align: center; position: relative; overflow: hidden; } .header::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="2" fill="rgba(255,255,255,0.1)"/><circle cx="80" cy="40" r="1.5" fill="rgba(255,255,255,0.1)"/><circle cx="40" cy="80" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="90" cy="80" r="2.5" fill="rgba(255,255,255,0.1)"/></svg>'); pointer-events: none; } .header h2 { font-size: 1.5rem; margin-bottom: 10px; text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3); position: relative; z-index: 1; } .header p { font-size: 1.1rem; opacity: 0.9; position: relative; z-index: 1; } .date-display { background: rgba(255, 255, 255, 0.15); padding: 10px 20px; border-radius: 25px; margin-top: 15px; display: inline-block; font-weight: 600; position: relative; z-index: 1; } .controls { padding: 20px 30px; background: #f8f9fa; border-bottom: 2px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; } .selection-container { display: flex; align-items: center; gap: 15px; flex-grow: 1; } .filter-select, .action-btn { padding: 12px 15px; border: 2px solid #ddd; border-radius: 8px; font-size: 16px; background: white; cursor: pointer; transition: all 0.3s ease; } .filter-select:focus { outline: none; border-color: #4caf50; } .action-btn { background-color: #4caf50; color: white; border-color: #4caf50; font-weight: bold; } .action-btn:hover { background-color: #45a049; } .action-btn:disabled { background-color: #ccc; border-color: #ccc; cursor: not-allowed; } .stats { display: flex; gap: 20px; align-items: center; } .stat-item { text-align: center; padding: 10px 15px; background: white; border-radius: 8px; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1); } .stat-number { font-size: 1.5rem; font-weight: bold; color: #2e7d32; } .stat-label { font-size: 0.9rem; color: #666; margin-top: 5px; } .table-container { margin: 20px 30px; overflow-x: auto; border-radius: 10px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); } .attendance-table { width: 100%; border-collapse: collapse; background: white; min-width: 800px; } .attendance-table th { background: linear-gradient(135deg, #388e3c 0%, #4caf50 100%); color: white; padding: 18px 15px; text-align: left; font-weight: 600; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px; position: sticky; top: 0; z-index: 10; } .attendance-table td { padding: 15px; border-bottom: 1px solid #e0e0e0; transition: all 0.3s ease; } .attendance-table tr:hover { background: #f8f9fa; transform: translateX(5px); } .student-id { font-weight: 600; color: #2e7d32; } .status-dropdown { padding: 8px 15px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px; cursor: pointer; transition: all 0.3s ease; min-width: 100px; } .status-dropdown:focus { outline: none; border-color: #4caf50; box-shadow: 0 0 5px rgba(76, 175, 80, 0.3); } .status-present { background: #e8f5e9; color: #2e7d32; border-color: #4caf50; } .status-absent { background: #ffebee; color: #c62828; border-color: #f44336; } .status-late { background: #fff3e0; color: #ef6c00; border-color: #ff9800; } .loading::after { content: ''; position: absolute; right: 10px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; border: 2px solid #ddd; border-top: 2px solid #4caf50; border-radius: 50%; animation: spin 1s linear infinite; } @keyframes spin { 0% { transform: translateY(-50%) rotate(0deg); } 100% { transform: translateY(-50%) rotate(360deg); } } .success-message, .error-message { position: fixed; top: 20px; right: 20px; color: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2); transform: translateX(400px); transition: transform 0.3s ease; z-index: 1000; } .success-message { background: #4caf50; } .error-message { background: #f44336; } .success-message.show, .error-message.show { transform: translateX(0); } .no-records { text-align: center; padding: 50px; color: #666; font-size: 1.1rem; }
    </style>
</head>

<body>
    <?php require_once "nav.php" ?>
    <div class="container">
        <div class="header">
            <h2>📚 Student Attendance</h2>
            <p>School Pilot - Digital Attendance Management</p>
            <div class="date-display" id="currentDate"></div>
        </div>

        <div class="controls">
            <div class="selection-container">
                <select class="filter-select" id="classFilter">
                    <option value="">-- Select Class --</option>
                </select>
                <select class="filter-select" id="streamFilter" disabled>
                    <option value="">-- Select Stream --</option>
                </select>
                <button id="fetchStudentsBtn" class="action-btn" disabled>Fetch Students</button>
            </div>

            <div class="stats">
                <div class="stat-item">
                    <div class="stat-number" id="totalStudents">0</div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" id="presentCount">0</div>
                    <div class="stat-label">Present</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" id="absentCount">0</div>
                    <div class="stat-label">Absent</div>
                </div>
            </div>
        </div>

        <div class="table-container">
            <table class="attendance-table">
                <thead>
                    <tr>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Date</th>
                        <th>Attendance Status</th>
                    </tr>
                </thead>
                <tbody id="attendanceTableBody">
                    <tr>
                        <td colspan="4" class="no-records">Please select a class and stream to load students.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="successMessage" class="success-message">✅ Attendance updated successfully!</div>
    <div id="errorMessage" class="error-message">❌ Error updating attendance. Please try again.</div>

    <script>
        // Global variables
        let studentsData = [];
        let currentDate = '';

        // DOM Elements
        const classFilter = document.getElementById('classFilter');
        const streamFilter = document.getElementById('streamFilter');
        const fetchBtn = document.getElementById('fetchStudentsBtn');
        const tableBody = document.getElementById('attendanceTableBody');

        // Initialize the system
        document.addEventListener('DOMContentLoaded', function() {
            initializeDate();
            loadClasses();
            setupEventListeners();
        });

        // Set up current date
        function initializeDate() {
            const today = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            currentDate = today.toISOString().split('T')[0]; // YYYY-MM-DD format
            document.getElementById('currentDate').textContent = today.toLocaleDateString('en-US', options);
        }

        // 1. Load Classes into the first dropdown
        function loadClasses() {
            fetch('api/get_classes.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        classFilter.innerHTML = '<option value="">-- Select Class --</option>';
                        data.classes.forEach(cls => {
                            classFilter.innerHTML += `<option value="${cls.current_class}">${cls.current_class}</option>`;
                        });
                    } else {
                        showError('Failed to load classes.');
                    }
                })
                .catch(error => showError('Network error while loading classes.'));
        }

        // 2. Load Streams when a Class is selected
        function loadStreams(className) {
            streamFilter.disabled = true;
            streamFilter.innerHTML = '<option value="">Loading streams...</option>';
            fetch(`api/get_streams.php?class=${encodeURIComponent(className)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        streamFilter.innerHTML = '<option value="">-- Select Stream --</option>';
                        data.streams.forEach(s => {
                            streamFilter.innerHTML += `<option value="${s.stream}">${s.stream}</option>`;
                        });
                        streamFilter.disabled = false;
                    } else {
                        showError('Failed to load streams.');
                        streamFilter.innerHTML = '<option value="">-- Select Stream --</option>';
                    }
                })
                .catch(error => showError('Network error while loading streams.'));
        }

        // 3. Fetch students for the selected class and stream
        function fetchStudents() {
            const selectedClass = classFilter.value;
            const selectedStream = streamFilter.value;

            if (!selectedClass || !selectedStream) {
                showError("Please select both a class and a stream.");
                return;
            }
            
            tableBody.innerHTML = '<tr><td colspan="4" class="no-records">Loading students...</td></tr>';
            fetch(`api/get_students_by_class.php?class=${encodeURIComponent(selectedClass)}&stream=${encodeURIComponent(selectedStream)}&date=${currentDate}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        studentsData = data.students;
                        displayStudents();
                        updateStats();
                    } else {
                        showError('Failed to load students: ' + data.message);
                        tableBody.innerHTML = `<tr><td colspan="4" class="no-records">${data.message}</td></tr>`;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showError('Failed to load students.');
                     tableBody.innerHTML = `<tr><td colspan="4" class="no-records">An error occurred.</td></tr>`;
                });
        }
        
        // Display students in table
        function displayStudents() {
            if (studentsData.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="4" class="no-records">No students found in this class/stream.</td></tr>';
                return;
            }

            tableBody.innerHTML = studentsData.map(student => `
                <tr>
                    <td class="student-id">${student.student_id}</td>
                    <td class="student-name">${student.first_name} ${student.last_name}</td>
                    <td>${formatDate(currentDate)}</td>
                    <td>
                        <select class="status-dropdown ${getStatusClass(student.attendance_status)}" 
                                data-student-id="${student.student_id}" 
                                onchange="updateAttendance(this)">
                            <option value="">Select Status</option>
                            <option value="present" ${student.attendance_status === 'present' ? 'selected' : ''}>Present</option>
                            <option value="absent" ${student.attendance_status === 'absent' ? 'selected' : ''}>Absent</option>
                            <option value="late" ${student.attendance_status === 'late' ? 'selected' : ''}>Late</option>
                        </select>
                    </td>
                </tr>
            `).join('');
        }

        // Setup event listeners
        function setupEventListeners() {
            classFilter.addEventListener('change', () => {
                const selectedClass = classFilter.value;
                streamFilter.value = '';
                fetchBtn.disabled = true;
                if (selectedClass) {
                    loadStreams(selectedClass);
                } else {
                    streamFilter.disabled = true;
                    streamFilter.innerHTML = '<option value="">-- Select Stream --</option>';
                }
            });

            streamFilter.addEventListener('change', () => {
                fetchBtn.disabled = !classFilter.value || !streamFilter.value;
            });
            
            fetchBtn.addEventListener('click', fetchStudents);
        }

        // --- Your existing helper functions (updateAttendance, updateStats, getStatusClass, formatDate, showSuccess, showError) can remain mostly the same ---
        // I've included them below with minor adjustments.

        function getStatusClass(status) {
            switch (status) {
                case 'present': return 'status-present';
                case 'absent': return 'status-absent';
                case 'late': return 'status-late';
                default: return '';
            }
        }
        
        function formatDate(dateStr) {
            const date = new Date(dateStr + 'T00:00:00'); // Ensure correct parsing
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function updateAttendance(selectElement) {
            const studentId = selectElement.dataset.studentId;
            const status = selectElement.value;
            if (!status) return;

            selectElement.classList.add('loading');
            selectElement.disabled = true;

            const formData = new FormData();
            formData.append('student_id', studentId);
            formData.append('status', status);
            formData.append('date', currentDate);

            fetch('api/update_attendance.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    selectElement.classList.remove('loading');
                    selectElement.disabled = false;
                    if (data.success) {
                        const studentIndex = studentsData.findIndex(s => s.student_id == studentId);
                        if (studentIndex !== -1) {
                            studentsData[studentIndex].attendance_status = status;
                        }
                        selectElement.className = `status-dropdown ${getStatusClass(status)}`;
                        updateStats();
                        showSuccess('Attendance updated!');
                    } else {
                        showError('Error: ' + data.message);
                        fetchStudents(); // Re-fetch to reset to original state
                    }
                })
                .catch(error => {
                    selectElement.classList.remove('loading');
                    selectElement.disabled = false;
                    showError('Network error. Please try again.');
                    fetchStudents(); // Re-fetch
                });
        }

        function updateStats() {
            const total = studentsData.length;
            const present = studentsData.filter(s => s.attendance_status === 'present' || s.attendance_status === 'late').length;
            const absent = studentsData.filter(s => s.attendance_status === 'absent').length;

            document.getElementById('totalStudents').textContent = total;
            document.getElementById('presentCount').textContent = present;
            document.getElementById('absentCount').textContent = absent;
        }

        function showSuccess(message) {
            const successEl = document.getElementById('successMessage');
            successEl.textContent = '✅ ' + message;
            successEl.classList.add('show');
            setTimeout(() => { successEl.classList.remove('show'); }, 3000);
        }

        function showError(message) {
            const errorEl = document.getElementById('errorMessage');
            errorEl.textContent = '❌ ' + message;
            errorEl.classList.add('show');
            setTimeout(() => { errorEl.classList.remove('show'); }, 4000);
        }
    </script>
</body>
</html>