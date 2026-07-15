<?php
require_once '../auth.php';
?>
<!DOCTYPE html>
<html data-bs-theme="light" lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Exam Sets - SchoolPilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

    <style>
        :root {
            --primary-color: #1e8449;
            --primary-light: #27ae60;
            --primary-dark: #145a32;
            --accent-color: #2ecc71;
            --white: #ffffff;
            --light-gray: #f5f5f5;
            --gray: #e0e0e0;
            --dark-gray: #757575;
            --text-dark: #333333;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: var(--light-gray);
            color: var(--text-dark);
            line-height: 1.6;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px;
        }

        .page-header {
            text-align: center;
            margin-bottom: 40px;
            background: var(--white);
            padding: 20px;
            border-radius: 10px;
            box-shadow: var(--shadow);
        }

        .page-header h2 {
            color: var(--primary-color);
            font-size: 1.8rem;
            font-weight: 600;
        }

        .main-content {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
            align-items: start;
        }

        .panel {
            background: var(--white);
            border-radius: 10px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .panel-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            color: var(--white);
            padding: 20px;
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .panel-content {
            padding: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 14px;
        }

        .required {
            color: #e74c3c;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--gray);
            border-radius: 8px;
            font-size: 14px;
            transition: var(--transition);
            background: var(--white);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(46, 204, 113, 0.1);
        }

        .form-text {
            font-size: 12px;
            color: var(--dark-gray);
            margin-top: 5px;
        }

        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary-color);
        }

        .checkbox-item label {
            font-weight: 500;
            cursor: pointer;
            margin-bottom: 0;
        }

        .button-group {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            color: var(--white);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(30, 132, 73, 0.3);
        }

        .btn-secondary {
            background: var(--dark-gray);
            color: var(--white);
        }

        .btn-secondary:hover {
            background: #5a5a5a;
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 8px 15px;
            font-size: 12px;
        }

        .btn-danger {
            background: #e74c3c;
            color: var(--white);
        }

        .btn-danger:hover {
            background: #c0392b;
            transform: translateY(-2px);
        }

        .table-container {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .table thead {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            color: var(--white);
        }

        .table th,
        .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--gray);
            vertical-align: middle;
        }

        .table th {
            font-weight: 600;
            font-size: 13px;
        }

        .table tbody tr {
            transition: var(--transition);
        }

        .table tbody tr:hover {
            background-color: rgba(46, 204, 113, 0.05);
        }

        .table tbody tr:nth-child(even) {
            background-color: rgba(245, 245, 245, 0.5);
        }

        .table tbody tr:nth-child(even):hover {
            background-color: rgba(46, 204, 113, 0.08);
        }

        .text-center {
            text-align: center;
        }

        /* Modal for Add/Edit Form */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            display: none;
            z-index: 1050;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }

        .modal-content-form {
            background-color: var(--white);
            border-radius: 15px;
            width: 100%;
            max-width: 600px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            animation: modalSlideIn 0.4s ease-out;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            padding: 20px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            color: var(--white);
            font-size: 1.4rem;
            font-weight: 600;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-close-btn {
            background: none;
            border: none;
            color: var(--white);
            font-size: 28px;
            cursor: pointer;
            opacity: 0.8;
            transition: var(--transition);
        }

        .modal-close-btn:hover {
            opacity: 1;
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 25px;
            overflow-y: auto;
        }

        /* Custom styles for the confirmation modal */
        .confirmation-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: none;
            z-index: 1060;
            /* Higher than form modal */
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: var(--white);
            border-radius: 15px;
            width: 100%;
            max-width: 450px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                transform: scale(0.8) translateY(-30px);
                opacity: 0;
            }

            to {
                transform: scale(1) translateY(0);
                opacity: 1;
            }
        }

        .warning-icon {
            color: #F8BB86;
            font-size: 60px;
            margin-bottom: 25px;
        }

        .modal-content h2 {
            color: var(--text-dark);
            font-size: 28px;
            margin-bottom: 15px;
            font-weight: 600;
        }

        .modal-content p {
            color: var(--dark-gray);
            font-size: 16px;
            margin-bottom: 35px;
        }

        .modal-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
        }

        .btn-confirm {
            background: linear-gradient(135deg, #E74C3C, #c0392b);
            color: var(--white);
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(231, 76, 60, 0.4);
        }

        .btn-cancel {
            background: var(--dark-gray);
            color: var(--white);
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-cancel:hover {
            background: #5a5a5a;
            transform: translateY(-2px);
        }

        /* Alert styles */
        .alert-container {
            position: fixed;
            top: 20px;
            right: 20px;
            max-width: 400px;
            z-index: 1070;
        }

        .custom-alert {
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slide-in 0.4s ease-out;
            backdrop-filter: blur(10px);
        }

        .alert-success {
            background: linear-gradient(135deg, var(--accent-color), var(--primary-light));
            color: var(--white);
        }

        .alert-danger {
            background: linear-gradient(135deg, #E74C3C, #c0392b);
            color: var(--white);
        }

        .alert-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .alert-message {
            flex-grow: 1;
            font-weight: 500;
        }

        .alert-close {
            background: none;
            border: none;
            color: var(--white);
            font-size: 24px;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            opacity: 0.8;
            transition: var(--transition);
        }

        .alert-close:hover {
            opacity: 1;
            transform: scale(1.1);
        }

        @keyframes slide-in {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* Loading spinner */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.9);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1080;
            backdrop-filter: blur(5px);
        }

        .spinner {
            width: 60px;
            height: 60px;
            border: 4px solid var(--gray);
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {

            .button-group,
            .modal-buttons {
                flex-direction: column;
            }

            .btn,
            .btn-confirm,
            .btn-cancel {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <?php require_once '../nav.php'; ?>
    <div class="container">
        <div class="main-content">
            <div class="panel">
                <div class="panel-header">
                    <div><i class="fas fa-list"></i> Existing Exam Sets</div>
                    <button id="addExamSetBtn" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus-circle"></i> Add New Exam Set
                    </button>
                </div>
                <div class="panel-content">
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Exam Code</th>
                                    <th>Description</th>
                                    <th>Max Marks</th>
                                    <th>Classes</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody id="examTableBody">
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="examSetModal">
        <div class="modal-content-form">
            <div class="modal-header">
                <span id="modalTitle"><i class="fas fa-plus-circle"></i> Add Exam Set</span>
                <button class="modal-close-btn" id="closeModalBtn">&times;</button>
            </div>
            <div class="modal-body">
                <form id="examSetForm">
                    <input type="hidden" id="exam_id" name="exam_id" value="">
                    <input type="hidden" id="action" name="action" value="save">

                    <div class="form-group">
                        <label for="examCode" class="form-label">Exam Short Code <span class="required">*</span></label>
                        <input type="text" class="form-control" id="examCode" name="examCode" placeholder="e.g. MID, EOT, MOCK" required>
                    </div>

                    <div class="form-group">
                        <label for="examDescription" class="form-label">Exam Description <span class="required">*</span></label>
                        <textarea class="form-control" id="examDescription" name="examDescription" rows="3" placeholder="e.g. Mid Term Examination..." required></textarea>
                    </div>

                    <div class="form-group">
                        <label for="examMark" class="form-label">Exam Mark <span class="required">*</span></label>
                        <input type="number" class="form-control" id="examMark" name="examMark" placeholder="e.g. 100" min="1" required>
                        <small class="form-text">Enter the maximum possible marks for this exam.</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Classes <span class="required">*</span></label>
                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <input type="checkbox" value="1" id="seniorFive" name="classes[]">
                                <label for="seniorFive">Senior Five</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" value="2" id="seniorSix" name="classes[]">
                                <label for="seniorSix">Senior Six</label>
                            </div>
                        </div>
                    </div>

                    <div class="button-group">
                        <button type="submit" id="submitBtn" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Exam Set
                        </button>
                        <button type="button" id="resetBtn" class="btn btn-secondary">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="confirmation-modal" id="deleteConfirmationModal">
        <div class="modal-content">
            <div class="warning-icon">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <h2>Are you sure?</h2>
            <p>You won't be able to revert this!</p>
            <div class="modal-buttons">
                <button class="btn-confirm" id="confirmDeleteBtn">
                    <i class="fas fa-check"></i> Yes, delete it!
                </button>
                <button class="btn-cancel" id="cancelDeleteBtn">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </div>
    </div>

    <div class="alert-container" id="alertContainer"></div>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>

    <script>
      document.addEventListener('DOMContentLoaded', function() {
    // Initial Load
    loadExamSets();

    const examSetForm = document.querySelector('#examSetForm');
    const alertContainer = document.querySelector('#alertContainer');
    const examTableBody = document.querySelector('#examTableBody');

    // FORM SUBMISSION
    examSetForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        const submitBtn = document.querySelector('#submitBtn');
        const originalBtnHtml = submitBtn.innerHTML;

        // Show loading state
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        fetch('process_exam_set.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showAlert(data.message, 'success');
                    resetForm();
                    loadExamSets();
                    document.querySelector('#examSetModal').style.display = 'none';
                } else {
                    showAlert(data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('A network error occurred.', 'danger');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnHtml;
            });
    });

    function loadExamSets() {
        fetch('get_exam_sets.php')
            .then(response => response.json())
            .then(data => {
                console.log('Loaded data:', data); // Debug: check what's coming from the server
                
                if (data.length === 0) {
                    examTableBody.innerHTML = '<tr><td colspan="6" class="text-center">No exam sets found.</td></tr>';
                    return;
                }

                let html = '';
                data.forEach((row, index) => {
                    html += `
                <tr>
                    <td>${index + 1}</td>
                    <td>${row.exam_set || 'N/A'}</td>
                    <td>${row.description || 'N/A'}</td>
                    <td>${row.exam_mark || 'N/A'}</td>
                    <td>${row.classes || 'N/A'}</td>
                    <td class="text-center">
                        <button class="btn btn-primary btn-sm edit-btn" 
                                data-id="${row.id}" 
                                data-exam-set="${row.exam_set || ''}" 
                                data-description="${row.description || ''}" 
                                data-exam-mark="${row.exam_mark || ''}"
                                data-classes="${row.classes || ''}">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-danger btn-sm delete-btn" data-id="${row.id}">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>`;
                });
                examTableBody.innerHTML = html;
            })
            .catch(error => {
                console.error('Fetch error:', error);
                examTableBody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading data. Check console.</td></tr>';
            });
    }

    // EVENT DELEGATION
    document.addEventListener('click', function(e) {
        // Edit Button
        if (e.target.closest('.edit-btn')) {
            const btn = e.target.closest('.edit-btn');
            
            // Populate form fields (matching your form's input names: examCode, examDescription, examMark)
            document.querySelector('#exam_id').value = btn.dataset.id;
            document.querySelector('#examCode').value = btn.dataset.examSet;
            document.querySelector('#examDescription').value = btn.dataset.description;
            document.querySelector('#examMark').value = btn.dataset.examMark;

            // Handle classes checkboxes
            // The classes field contains text like "Senior Five, Senior Six"
            // We need to convert back to checkbox values
            const classesStr = btn.dataset.classes || '';
            document.querySelectorAll('input[name="classes[]"]').forEach(cb => {
                cb.checked = false; // Reset first
                if (cb.value === '1' && classesStr.includes('Senior Five')) {
                    cb.checked = true;
                } else if (cb.value === '2' && classesStr.includes('Senior Six')) {
                    cb.checked = true;
                }
            });

            // Update modal
            document.querySelector('#action').value = 'update';
            document.querySelector('#modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Exam Set';
            document.querySelector('#submitBtn').innerHTML = '<i class="fas fa-save"></i> Update Exam Set';
            document.querySelector('#examSetModal').style.display = 'flex';
        }

        // Delete Button
        if (e.target.closest('.delete-btn')) {
            if (confirm('Are you sure you want to delete this exam set?')) {
                const id = e.target.closest('.delete-btn').dataset.id;
                const deleteData = new FormData();
                deleteData.append('exam_id', id); // Changed from 'id' to 'exam_id'
                deleteData.append('action', 'delete');

                fetch('process_exam_set.php', {
                        method: 'POST',
                        body: deleteData
                    })
                    .then(response => response.json())
                    .then(data => {
                        showAlert(data.message, data.status === 'success' ? 'success' : 'danger');
                        if (data.status === 'success') {
                            loadExamSets();
                        }
                    })
                    .catch(error => {
                        console.error('Delete error:', error);
                        showAlert('Network error while deleting', 'danger');
                    });
            }
        }
    });

    // HELPER FUNCTIONS
    function resetForm() {
        examSetForm.reset();
        document.querySelector('#exam_id').value = '';
        document.querySelector('#action').value = 'save';
        document.querySelector('#submitBtn').innerHTML = '<i class="fas fa-save"></i> Save Exam Set';
        document.querySelector('#modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add Exam Set';
        
        // Uncheck all class checkboxes
        document.querySelectorAll('input[name="classes[]"]').forEach(cb => {
            cb.checked = false;
        });
    }

    function showAlert(message, type) {
        const div = document.createElement('div');
        div.className = `custom-alert alert-${type}`;
        div.innerHTML = `
            <div class="alert-icon"><i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'}"></i></div>
            <div class="alert-message">${message}</div>
            <button class="alert-close">&times;</button>
        `;
        alertContainer.appendChild(div);
        setTimeout(() => div.remove(), 5000);
        div.querySelector('.alert-close').onclick = () => div.remove();
    }

    // Open Modal
    document.querySelector('#addExamSetBtn').addEventListener('click', () => {
        resetForm();
        document.querySelector('#examSetModal').style.display = 'flex';
    });

    // Close Modal
    document.querySelector('#closeModalBtn').addEventListener('click', () => {
        document.querySelector('#examSetModal').style.display = 'none';
    });

    // Reset Button
    document.querySelector('#resetBtn').addEventListener('click', () => {
        resetForm();
    });
});
    </script>
</body>

</html>