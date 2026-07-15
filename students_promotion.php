<?php
require_once "auth.php"
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Promotion System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f5f5f5;
            padding: 20px;
        }

        .container {
            max-width: 100%;
            margin: 70px auto 0;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        h2 {
            color: #2c5f2d;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 25px;
            font-size: 14px;
        }

        /* Year Input Section */
        .year-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            border: 2px solid #dee2e6;
        }

        .year-input-group {
            display: flex;
            gap: 15px;
            align-items: center;
            max-width: 500px;
        }

        .year-input-group input {
            flex: 1;
            padding: 12px 15px;
            border: 2px solid #ced4da;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
        }

        .year-input-group input:focus {
            outline: none;
            border-color: #2c5f2d;
        }

        .btn-load {
            background: #2c5f2d;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-load:hover {
            background: #1e4620;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        /* Info Box */
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 25px;
        }

        .info-box h4 {
            color: #1976d2;
            margin-bottom: 12px;
            font-size: 16px;
        }

        .info-box ul {
            margin-left: 25px;
            color: #555;
            line-height: 1.8;
        }

        .info-box li {
            margin: 8px 0;
        }

        /* Section Styles */
        .section {
            background: #e8f5e9;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #2c5f2d;
            display: none;
            /* Hidden by default */
        }

        .section.visible {
            display: block;
        }

        .section-title {
            font-weight: bold;
            color: #2c5f2d;
            margin-bottom: 15px;
            font-size: 16px;
        }

        /* Promotion Order */
        .promotion-order {
            background: #fff3cd;
            border-left-color: #ffc107;
            border: 2px solid #ffc107;
        }

        .order-step {
            display: flex;
            align-items: center;
            padding: 15px;
            margin: 10px 0;
            background: white;
            border-radius: 6px;
            border-left: 4px solid #ccc;
            transition: all 0.3s;
        }

        .order-step:hover {
            transform: translateX(5px);
        }

        .order-step.completed {
            border-left-color: #4caf50;
            background: #e8f5e9;
        }

        .order-step.next {
            border-left-color: #ff9800;
            background: #fff3e0;
            font-weight: bold;
        }

        .order-step.locked {
            opacity: 0.5;
        }

        .step-number {
            background: #2c5f2d;
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 15px;
            font-size: 16px;
        }

        .step-number.completed {
            background: #4caf50;
        }

        .step-number.next {
            background: #ff9800;
            animation: pulse 2s infinite;
        }

        .step-number.locked {
            background: #ccc;
        }

        @keyframes pulse {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.1);
            }
        }

        .step-info {
            flex: 1;
            font-size: 15px;
        }

        .step-arrow {
            margin: 0 10px;
            color: #666;
            font-weight: bold;
        }

        .step-count {
            color: #666;
            font-size: 13px;
            margin-left: auto;
            font-weight: 600;
        }

        .btn-reverse {
            background: #ff9800;
            color: white;
            padding: 6px 15px;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            margin-left: 10px;
            transition: all 0.3s;
        }

        .btn-reverse:hover {
            background: #f57c00;
            transform: translateY(-1px);
        }

        /* Form Styles */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-group select {
            padding: 12px;
            border: 2px solid #ced4da;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
        }

        .form-group select:focus {
            outline: none;
            border-color: #2c5f2d;
        }

        /* Search Box */
        .search-box {
            display: flex;
            align-items: center;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border: 2px solid #e9ecef;
        }

        .search-box input {
            flex: 1;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            margin-left: 10px;
            font-size: 14px;
        }

        /* Buttons */
        .button-group {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        button {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 14px;
        }

        .btn-primary {
            background: #2c5f2d;
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        button:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* Student List */
        .student-list {
            max-height: 450px;
            overflow-y: auto;
            border: 2px solid #dee2e6;
            border-radius: 6px;
            background: white;
        }

        .student-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #eee;
            transition: all 0.2s;
        }

        .student-item:last-child {
            border-bottom: none;
        }

        .student-item:hover {
            background: #f8f9fa;
        }

        .student-item input[type="checkbox"] {
            margin-right: 15px;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .student-info {
            flex: 1;
        }

        .student-name {
            font-weight: 600;
            color: #333;
            font-size: 15px;
        }

        .student-id {
            color: #666;
            font-size: 13px;
            margin-top: 4px;
        }

        .student-section {
            color: #888;
            font-size: 13px;
            margin-left: auto;
            background: #e9ecef;
            padding: 4px 12px;
            border-radius: 12px;
        }

        .selected-count {
            display: inline-block;
            background: #2c5f2d;
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
        }

        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            animation: slideIn 0.3s;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }

        .alert::before {
            content: "ℹ️";
            margin-right: 12px;
            font-size: 20px;
        }

        /* Admin Controls */
        .admin-section {
            background: #ffebee;
            border-left-color: #f44336;
            margin-top: 40px;
            border: 2px solid #ef5350;
        }

        .admin-section .section-title {
            color: #c62828;
        }

        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: #999;
            font-size: 15px;
        }

        .empty-state::before {
            content: "📋";
            display: block;
            font-size: 48px;
            margin-bottom: 15px;
        }

        .promote-btn-large {
            width: 100%;
            padding: 18px;
            font-size: 18px;
            background: linear-gradient(135deg, #2c5f2d 0%, #1e4620 100%);
            margin-top: 10px;
        }
    </style>
</head>

<body>
<?php require_once 'nav.php'?>
    <div class="container">
        <h2>
             Student Promotion System
        </h2>
        <p class="subtitle">Promotes students in the correct order to prevent class mixing</p>

        <!-- STEP 1: Year Input -->
        <div class="year-section">
            <div class="year-input-group">
                <input type="text" id="academicYear" placeholder="Enter Academic Year (e.g., 2025)" value="2025">
                <button class="btn-load" onclick="loadPromotionStatus()">
                     Load Year
                </button>
            </div>
        </div>

        <div id="alertContainer"></div>

        <!-- Info Box -->
        <div class="info-box">
            <h4> How This Works:</h4>
            <ul>
                <li><strong>Step 1:</strong> Enter the academic year (e.g., 2025) and click "Load Year"</li>
                <li><strong>Step 2:</strong> You MUST promote in order: S5→S6, then S4→S5, then S3→S4, then S2→S3, then S1→S2</li>
                <li><strong>Step 3:</strong> Select the class/stream, choose students, and promote</li>
                <li><strong>Step 4:</strong> Once a class is promoted, it cannot be promoted again that year</li>
                <li><strong>Note:</strong> Senior 4 students do UCE (no promotion needed unless continuing to A-Level)</li>
                <li><strong>Note:</strong> New S1 students come from P7/UNEB separately</li>
            </ul>
        </div>

        <!-- Promotion Order Progress -->
        <div class="section promotion-order" id="orderSection">
            <div class="section-title"> Promotion Progress - Follow This Order!</div>
            <div id="orderSteps"></div>
        </div>

        <!-- Select Current Class & Stream -->
        <div class="section" id="selectSection">
            <div class="section-title"> Select Current Class & Stream to Promote</div>
            <div class="form-row">
                <div class="form-group">
                    <label>Current Class <span style="color: red;">*</span></label>
                    <select id="currentClass" onchange="updateTargetClass(); loadStudents();">
                        <option value="">-- Select Class --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Current Stream <span style="color: red;">*</span></label>
                    <select id="currentStream" onchange="loadStudents()">
                        <option value="">-- Select Stream --</option>
                        <option value="East">East</option>
                        <option value="West">West</option>
                        <option value="North">North</option>
                        <option value="South">South</option>
                        <option value="Arts">Arts</option>
                        <option value="Sciences">Sciences</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Select Students -->
        <div class="section" id="studentsSection">
            <div class="section-title">Select Students to Promote</div>

            <div class="search-box">
                <span style="font-size: 20px;"></span>
                <input type="text" id="searchStudents" placeholder="Search students by name or ID..." onkeyup="filterStudents()">
            </div>

            <div class="button-group">
                <button class="btn-primary" onclick="selectAll()">✓ Select All</button>
                <button class="btn-secondary" onclick="deselectAll()">✗ Deselect All</button>
                <span class="selected-count" id="selectedCount">0 selected</span>
            </div>

            <div class="student-list" id="studentList">
                <div class="empty-state">
                    Select a class and stream above to view students
                </div>
            </div>
        </div>

        <!-- Promotion Target -->
        <div class="section" id="targetSection">
            <div class="section-title"> Promotion Target (Auto-Selected)</div>
            <div class="form-row">
                <div class="form-group">
                    <label>Promote To Class</label>
                    <select id="targetClass" disabled>
                        <option value="">Will be set automatically</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Promote To Stream</label>
                    <select id="targetStream">
                        <option value="">-- Select Target Stream --</option>
                        <option value="East">East</option>
                        <option value="West">West</option>
                        <option value="North">North</option>
                        <option value="South">South</option>
                        <option value="Arts">Arts</option>
                        <option value="Sciences">Sciences</option>
                    </select>
                </div>
            </div>

            <button class="btn-primary promote-btn-large" onclick="promoteStudents()">
                 Promote Selected Students
            </button>
        </div>

        <!-- Admin Reset -->
        <div class="section admin-section">
            <div class="section-title">⚠️ Admin Controls</div>
            <p style="margin-bottom: 15px; color: #666;">
                Reset all promotions for the current year (use only if mistakes were made)
            </p>
            <button class="btn-danger" onclick="resetYear()">🔄 Reset All Promotions for This Year</button>
        </div>
    </div>

    <script>
        let allStudents = [];
        let promotionStatus = null;
        let currentAcademicYear = '';

        const PROMOTION_MAP = {
            'Senior Five': 'Senior Six',
            'Senior Four': 'Senior Five',
            'Senior Three': 'Senior Four',
            'Senior Two': 'Senior Three',
            'Senior One': 'Senior Two'
        };

        function showAlert(message, type) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.textContent = message;
            document.getElementById('alertContainer').innerHTML = '';
            document.getElementById('alertContainer').appendChild(alertDiv);
            setTimeout(() => alertDiv.remove(), 6000);
        }

        async function loadPromotionStatus() {
            currentAcademicYear = document.getElementById('academicYear').value.trim();

            if (!currentAcademicYear) {
                showAlert('Please enter an academic year', 'error');
                return;
            }

            console.log('Loading promotion status for year:', currentAcademicYear);

            try {
                const response = await fetch(`api/promote_students.php?action=check_status&academic_year=${currentAcademicYear}`);
                const data = await response.json();

                console.log('Promotion status:', data);

                if (data.success) {
                    promotionStatus = data;
                    displayPromotionOrder();
                    updateClassDropdown();

                    document.getElementById('orderSection').classList.add('visible');
                    document.getElementById('selectSection').classList.add('visible');

                    if (data.all_complete) {
                        showAlert('All promotions completed for this year! ✅', 'success');
                    } else if (data.next_required) {
                        showAlert(`Next: You must promote ${data.next_required} first`, 'warning');
                    } else {
                        showAlert('Ready to start promotions!', 'success');
                    }
                } else {
                    showAlert(data.message || 'Error loading promotion status', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showAlert('Error loading promotion status: ' + error.message, 'error');
            }
        }

        function displayPromotionOrder() {
            const container = document.getElementById('orderSteps');
            const steps = [{
                    from: 'Senior Five',
                    to: 'Senior Six',
                    num: 1
                },
                {
                    from: 'Senior Four',
                    to: 'Senior Five',
                    num: 2
                },
                {
                    from: 'Senior Three',
                    to: 'Senior Four',
                    num: 3
                },
                {
                    from: 'Senior Two',
                    to: 'Senior Three',
                    num: 4
                },
                {
                    from: 'Senior One',
                    to: 'Senior Two',
                    num: 5
                }
            ];

            container.innerHTML = steps.map(step => {
                const isCompleted = promotionStatus.promoted_classes.includes(step.from);
                const isNext = promotionStatus.next_required === step.from;
                const isLocked = !isCompleted && !isNext && !promotionStatus.allowed_classes.includes(step.from);

                const statusClass = isCompleted ? 'completed' : isNext ? 'next' : 'locked';
                const statusIcon = isCompleted ? '✅' : isNext ? '👉' : '🔒';

                const promo = promotionStatus.completed_promotions.find(p => p.from_class === step.from);
                const count = promo ? `${promo.student_count} students promoted` : '';

                // Add reverse button for completed promotions
                const reverseBtn = isCompleted ?
                    `<button class="btn-reverse" onclick="reversePromotion('${step.from}')" title="Undo this promotion">
                         Undo
                    </button>` : '';

                return `
                    <div class="order-step ${statusClass}">
                        <div class="step-number ${statusClass}">${step.num}</div>
                        <div class="step-info">
                            ${step.from} <span class="step-arrow">→</span> ${step.to}
                        </div>
                        <div class="step-count">${statusIcon} ${count}</div>
                        ${reverseBtn}
                    </div>
                `;
            }).join('');
        }

        function updateClassDropdown() {
            const select = document.getElementById('currentClass');
            const allowedClasses = promotionStatus.allowed_classes || [];

            select.innerHTML = '<option value="">-- Select Class --</option>';

            Object.keys(PROMOTION_MAP).forEach(className => {
                const option = document.createElement('option');
                option.value = className;
                option.textContent = className;

                if (!allowedClasses.includes(className)) {
                    option.disabled = true;
                    option.textContent += ' (Locked - promote higher classes first)';
                }

                if (promotionStatus.promoted_classes.includes(className)) {
                    option.disabled = true;
                    option.textContent += ' (Already promoted ✅)';
                }

                select.appendChild(option);
            });
        }

        function updateTargetClass() {
            const currentClass = document.getElementById('currentClass').value;
            const targetSelect = document.getElementById('targetClass');

            if (currentClass && PROMOTION_MAP[currentClass]) {
                targetSelect.innerHTML = `<option value="${PROMOTION_MAP[currentClass]}">${PROMOTION_MAP[currentClass]}</option>`;
            }
        }

        async function loadStudents() {
            const currentClass = document.getElementById('currentClass').value;
            const currentStream = document.getElementById('currentStream').value;

            console.log('Loading students:', currentClass, currentStream);

            if (!currentClass || !currentStream) {
                console.log('Missing class or stream');
                return;
            }

            const listDiv = document.getElementById('studentList');
            listDiv.innerHTML = '<div class="empty-state">Loading students...</div>';

            try {
                const url = `api/promote_students.php?action=get_students&current_class=${encodeURIComponent(currentClass)}&stream=${encodeURIComponent(currentStream)}&academic_year=${encodeURIComponent(currentAcademicYear)}`;
                console.log('Fetching:', url);

                const response = await fetch(url);
                const data = await response.json();

                console.log('Students response:', data);

                if (data.success) {
                    allStudents = data.students;
                    console.log('Loaded students:', allStudents.length);
                    displayStudents(allStudents);
                    document.getElementById('studentsSection').classList.add('visible');
                    document.getElementById('targetSection').classList.add('visible');
                    updateTargetClass();
                } else {
                    console.error('Error:', data.message);
                    showAlert(data.message, 'error');
                    listDiv.innerHTML = `<div class="empty-state">${data.message}</div>`;
                }
            } catch (error) {
                console.error('Fetch error:', error);
                showAlert('Error loading students: ' + error.message, 'error');
                listDiv.innerHTML = '<div class="empty-state">Error loading students</div>';
            }
        }

        function displayStudents(students) {
            const listDiv = document.getElementById('studentList');

            if (students.length === 0) {
                listDiv.innerHTML = '<div class="empty-state">No students found in this class/stream</div>';
                return;
            }

            listDiv.innerHTML = students.map(student => `
                <div class="student-item">
                    <input type="checkbox" class="student-checkbox" data-id="${student.student_id}" onchange="updateCount()">
                    <div class="student-info">
                        <div class="student-name">${student.last_name} ${student.first_name}</div>
                        <div class="student-id">ID: ${student.student_id}</div>
                    </div>
                    <div class="student-section">${student.section}</div>
                </div>
            `).join('');

            updateCount();
        }

        function filterStudents() {
            const search = document.getElementById('searchStudents').value.toLowerCase();
            const filtered = allStudents.filter(student =>
                student.first_name.toLowerCase().includes(search) ||
                student.last_name.toLowerCase().includes(search) ||
                student.student_id.toLowerCase().includes(search)
            );
            displayStudents(filtered);
        }

        function selectAll() {
            document.querySelectorAll('.student-checkbox').forEach(cb => cb.checked = true);
            updateCount();
        }

        function deselectAll() {
            document.querySelectorAll('.student-checkbox').forEach(cb => cb.checked = false);
            updateCount();
        }

        function updateCount() {
            const count = document.querySelectorAll('.student-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = `${count} selected`;
        }

        async function promoteStudents() {
            const fromClass = document.getElementById('currentClass').value;
            const fromStream = document.getElementById('currentStream').value;
            const targetClass = document.getElementById('targetClass').value;
            const targetStream = document.getElementById('targetStream').value;

            if (!targetStream) {
                showAlert('Please select target stream', 'error');
                return;
            }

            const selectedIds = Array.from(document.querySelectorAll('.student-checkbox:checked'))
                .map(cb => cb.dataset.id);

            if (selectedIds.length === 0) {
                showAlert('Please select at least one student', 'error');
                return;
            }

            if (!confirm(`Promote ${selectedIds.length} student(s) from ${fromClass}-${fromStream} to ${targetClass}-${targetStream}?`)) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'promote');
            formData.append('student_ids', JSON.stringify(selectedIds));
            formData.append('from_class', fromClass);
            formData.append('from_stream', fromStream);
            formData.append('target_class', targetClass);
            formData.append('target_stream', targetStream);
            formData.append('academic_year', currentAcademicYear);

            try {
                const response = await fetch('api/promote_students.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                console.log('Promotion result:', data);

                if (data.success) {
                    showAlert(data.message, 'success');
                    await loadPromotionStatus();
                    document.getElementById('studentsSection').classList.remove('visible');
                    document.getElementById('targetSection').classList.remove('visible');
                    document.getElementById('currentClass').value = '';
                    document.getElementById('currentStream').value = '';
                } else {
                    showAlert(data.message, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showAlert('Error promoting students: ' + error.message, 'error');
            }
        }

        async function resetYear() {
            if (!confirm('⚠️ WARNING: This will UNDO ALL promotions for this year and move students back to their original classes. Are you ABSOLUTELY sure?')) {
                return;
            }

            const confirmation = prompt('Type YES to confirm reset:');
            if (confirmation !== 'YES') {
                showAlert('Reset cancelled', 'warning');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'reset_year');
            formData.append('academic_year', currentAcademicYear);

            try {
                const response = await fetch('api/promote_students.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.success) {
                    showAlert(data.message, 'success');
                    loadPromotionStatus();
                } else {
                    showAlert(data.message, 'error');
                }
            } catch (error) {
                showAlert('Error resetting promotions: ' + error.message, 'error');
            }
        }

        async function reversePromotion(fromClass) {
            if (!confirm(`⚠️ Reverse promotion for ${fromClass}?\n\nThis will move all students back from their promoted class to ${fromClass}.`)) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'reverse_class');
            formData.append('from_class', fromClass);
            formData.append('academic_year', currentAcademicYear);

            try {
                const response = await fetch('api/promote_students.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                console.log('Reverse result:', data);

                if (data.success) {
                    showAlert(data.message, 'success');
                    await loadPromotionStatus();
                } else {
                    showAlert(data.message, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showAlert('Error reversing promotion: ' + error.message, 'error');
            }
        }
    </script>
</body>

</html>