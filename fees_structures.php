<?php
require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/security.php';
require_once 'fee_functions.php';
require_once 'tracking.php';
$tracker->trackAction("School fees structure");

// Generate CSRF token
$csrf_token = generateCSRFToken();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error_message'] = 'Invalid security token. Please try again.';
        header('Location: fees_structures.php');
        exit();
    }

    // Sanitize all POST data
    // Don't sanitize everything at once - sanitize each field appropriately
    if (isset($_POST['add_fee'])) {
        $result = add_fee_structure(
            $conn,
            sanitize_input($_POST['class']),
            sanitize_input($_POST['student_type']),
            sanitize_input($_POST['term']),
            (int)$_POST['year'],  // Cast to integer, don't sanitize as text
            (float)$_POST['amount']  // Cast to float, don't sanitize as text
        );
    } elseif (isset($_POST['edit_fee'])) {
        $result = update_fee_structure(
            $conn,
            (int)$_POST['fee_id'],
            sanitize_input($_POST['class']),
            sanitize_input($_POST['student_type']),
            sanitize_input($_POST['term']),
            (int)$_POST['year'],
            (float)$_POST['amount']
        );
    } elseif (isset($_POST['delete_fee'])) {
        $result = delete_fee_structure($conn, (int)$_POST['id']);
    }

    if (isset($result)) {
        if ($result['success']) {
            $_SESSION['success_message'] = $result['message'];
        } else {
            $_SESSION['error_message'] = $result['message'];
        }
    }

    header('Location: fees_structures.php');
    exit();
}

// Get all fee structures
$fee_structures = get_fee_structures($conn);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Fees Structures - SchoolPliot</title>
    <style>
        /* CSS Variables */
        :root {
            --primary-green: #2e7d32;
            --dark-green: #1b5e20;
            --light-green: #81c784;
            --accent-green: #4caf50;
            --background: #f5f9f5;
            --white: #ffffff;
            --text-dark: #333333;
            --text-light: #666666;
            --border-color: #e0e0e0;
            --error-color: #f44336;
            --success-color: #4caf50;
            --warning-color: #ff9800;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            --border-radius: 8px;
            --transition: all 0.3s ease;
        }

        /* Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: var(--background);
            color: var(--text-dark);
            line-height: 1.6;
            font-family: 'Poppins', sans-serif;
        }

        .container {
            max-width: 100%;
            margin: 50px auto;
            padding: 20px;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
            background: linear-gradient(135deg, var(--primary-green), var(--accent-green));
            color: var(--white);
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }

        .header h2 {
            font-size: 1.8rem;
            font-weight: 600;
        }

        /* Notifications */
        .notification {
            display: flex;
            align-items: center;
            padding: 16px 20px;
            margin-bottom: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            animation: slideDown 0.5s ease-out;
        }

        .notification.success {
            background-color: #e8f5e8;
            border-left: 4px solid var(--success-color);
            color: var(--dark-green);
        }

        .notification.error {
            background-color: #ffebee;
            border-left: 4px solid var(--error-color);
            color: #c62828;
        }

        .notification-text {
            flex: 1;
            font-weight: 500;
        }

        .notification-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: inherit;
            opacity: 0.7;
        }

        /* List Section */
        .list-section {
            background: var(--white);
            padding: 30px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }

        .list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .list-section h2 {
            color: var(--primary-green);
            font-size: 1.5rem;
            font-weight: 600;
        }

        .table-container {
            overflow-x: auto;
        }

        .fees-table {
            width: 100%;
            border-collapse: collapse;
        }

        .fees-table th {
            background: linear-gradient(135deg, var(--primary-green), var(--accent-green));
            color: var(--white);
            padding: 12px 15px;
            text-align: left;
        }

        .fees-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .fees-table tbody tr:hover {
            background-color: #f8fdf8;
        }

        .class-cell {
            font-weight: 600;
            color: var(--primary-green);
        }

        .student-type-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .student-type-badge.day {
            background-color: #e3f2fd;
            color: #1976d2;
        }

        .student-type-badge.boarding {
            background-color: #fff3e0;
            color: #f57c00;
        }

        .amount-cell {
            font-weight: 600;
            color: var(--dark-green);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        /* Buttons */
        .btnn {
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 20px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
        }

        .btnn-primary {
            background: linear-gradient(135deg, var(--primary-green), var(--accent-green));
            color: var(--white);
        }

        .btnn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(46, 125, 50, 0.3);
        }

        .btnn-secondary {
            background-color: #e0e0e0;
            color: var(--text-dark);
        }

        .btnn-secondary:hover {
            background-color: #d0d0d0;
        }

        .btnn-edit {
            background-color: var(--warning-color);
            color: var(--white);
            padding: 6px 12px;
            font-size: 0.9rem;
        }

        .btnn-edit:hover {
            background-color: #f57c00;
        }

        .btnn-delete {
            background-color: var(--error-color);
            color: var(--white);
            padding: 6px 12px;
            font-size: 0.9rem;
        }

        .btnn-delete:hover {
            background-color: #d32f2f;
        }

        /* Modal Styles */
        .modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }

        .modal.show {
            opacity: 1;
            pointer-events: auto;
        }

        .modal-content {
            background-color: var(--white);
            padding: 30px;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.95);
            transition: transform 0.3s ease;
        }

        .modal.show .modal-content {
            transform: scale(1);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            color: var(--primary-green);
        }

        .modal-header h2 {
            font-size: 1.5rem;
        }

        .close-button {
            font-size: 2rem;
            font-weight: bold;
            color: var(--text-light);
            cursor: pointer;
            line-height: 1;
        }

        .close-button:hover {
            color: var(--text-dark);
        }

        /* Custom Confirmation Modal */
        .confirm-modal-content {
            text-align: center;
        }

        .confirm-modal-content p {
            margin-bottom: 25px;
            font-size: 1.1rem;
        }

        .confirm-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
        }

        /* Form Styles inside Modal */
        .fee-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 6px;
        }

        .form-group .required {
            color: var(--error-color);
        }

        .form-group input,
        .form-group select {
            padding: 12px 16px;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            font-size: 1rem;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-green);
        }

        .form-group input.error,
        .form-group select.error {
            border-color: var(--error-color);
        }

        .error-message {
            color: var(--error-color);
            font-size: 0.85rem;
            margin-top: 5px;
            min-height: 1.2em;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
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
    <div class="container">
        <header class="header">
            <h2>School Fees Management</h2>
            <p>Manage fee structures for Day and Boarding Students (S1 - S6)</p>
        </header>

        <?php if (isset($_SESSION['success_message'])) : ?>
            <div class="notification success">
                <span class="notification-text"><?php echo $_SESSION['success_message']; ?></span>
                <button class="notification-close" onclick="this.parentElement.style.display='none';">&times;</button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])) : ?>
            <div class="notification error">
                <span class="notification-text"><?php echo $_SESSION['error_message']; ?></span>
                <button class="notification-close" onclick="this.parentElement.style.display='none';">&times;</button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <div class="list-section">
            <div class="list-header">
                <h2>Current Fee Structures</h2>
                <button id="addFeeBtn" class="btnn btnn-primary">
                    <span>+</span> Add New Fee Structure
                </button>
            </div>
            <div class="table-container">
                <table class="fees-table">
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Student Type</th>
                            <th>Term</th>
                            <th>Year</th>
                            <th>Amount (UGX)</th>
                            <th>Date Added</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fee_structures as $fee) : ?>
                            <tr>
                                <td class="class-cell"><?php echo htmlspecialchars($fee['class_name']); ?></td>
                                <td>
                                    <span class="student-type-badge <?php echo strtolower($fee['student_type']); ?>">
                                        <?php echo htmlspecialchars($fee['student_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($fee['term']); ?></td>
                                <td><?php echo htmlspecialchars($fee['year']); ?></td>
                                <td class="amount-cell">UGX <?php echo number_format($fee['amount']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($fee['created_at'])); ?></td>
                                <td class="action-buttons">
                                    <button class="btnn btnn-edit" onclick='openEditModal(<?php echo json_encode($fee); ?>)'>Edit</button>
                                    <button class="btnn btnn-delete" onclick="openDeleteModal(<?php echo $fee['id']; ?>)">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="feeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Add New Fee Structure</h2>
                <span class="close-button" id="closeModal">&times;</span>
            </div>
            <form id="feeForm" method="POST" class="fee-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="fee_id" id="fee_id">

                <div class="form-group">
                    <label for="class">Class <span class="required">*</span></label>
                    <select name="class" id="class" required>
                        <option value="">Select Class</option>
                        <option value="Senior One">Senior One</option>
                        <option value="Senior Two">Senior Two</option>
                        <option value="Senior Three">Senior Three</option>
                        <option value="Senior Four">Senior Four</option>
                        <option value="Senior Five">Senior Five</option>
                        <option value="Senior Six">Senior Six</option>
                    </select>
                    <span class="error-message" id="class-error"></span>
                </div>

                <div class="form-group">
                    <label for="student_type">Student Type <span class="required">*</span></label>
                    <select name="student_type" id="student_type" required>
                        <option value="">Select Student Type</option>
                        <option value="Day">Day</option>
                        <option value="Boarding">Boarding</option>
                    </select>
                    <span class="error-message" id="student_type-error"></span>
                </div>

                <div class="form-group">
                    <label for="term">Term <span class="required">*</span></label>
                    <select name="term" id="term" required>
                        <option value="">Select Term</option>
                        <option value="Term One">Term One</option>
                        <option value="Term Two">Term Two</option>
                        <option value="Term Three">Term Three</option>
                    </select>
                    <span class="error-message" id="term-error"></span>
                </div>

                <div class="form-group">
                    <label for="year">Year <span class="required">*</span></label>
                    <input type="number" name="year" id="year" min="2020" max="<?php echo date('Y') + 10; ?>" placeholder="e.g., <?php echo date('Y'); ?>" required>
                    <span class="error-message" id="year-error"></span>
                </div>

                <div class="form-group">
                    <label for="amount">Amount (UGX) <span class="required">*</span></label>
                    <input type="number" name="amount" id="amount" min="0" placeholder="e.g., 500000" required>
                    <span class="error-message" id="amount-error"></span>
                </div>

                <button type="submit" id="formSubmitBtn" name="add_fee" class="btnn btnn-primary">Add Fee Structure</button>
            </form>
        </div>
    </div>

    <div id="confirmModal" class="modal">
        <div class="modal-content confirm-modal-content">
            <div class="modal-header">
                <h2>Confirm Deletion</h2>
                <span class="close-button" id="closeConfirmModal">&times;</span>
            </div>
            <p>Are you sure you want to delete this fee structure?</p>
            <div class="confirm-buttons">
                <button id="cancelDeleteBtn" class="btnn btnn-secondary">Cancel</button>
                <button id="confirmDeleteBtn" class="btnn btnn-delete">Delete</button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Modal elements
            const feeModal = document.getElementById('feeModal');
            const addFeeBtn = document.getElementById('addFeeBtn');
            const closeModalBtn = document.getElementById('closeModal');
            const modalTitle = document.getElementById('modalTitle');
            const feeForm = document.getElementById('feeForm');
            const feeIdInput = document.getElementById('fee_id');
            const formSubmitBtn = document.getElementById('formSubmitBtn');

            // Confirmation Modal elements
            const confirmModal = document.getElementById('confirmModal');
            const closeConfirmModalBtn = document.getElementById('closeConfirmModal');
            const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
            const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
            let deleteId = null;

            // --- Modal Controls ---
            const openModal = (modal) => modal.classList.add('show');
            const closeModal = (modal) => modal.classList.remove('show');

            addFeeBtn.addEventListener('click', () => {
                modalTitle.innerText = "Add New Fee Structure";
                feeForm.reset();
                resetFormValidation();
                feeIdInput.value = '';
                formSubmitBtn.name = "add_fee";
                formSubmitBtn.innerText = "Add Fee Structure";
                openModal(feeModal);
            });

            closeModalBtn.addEventListener('click', () => closeModal(feeModal));

            // --- Custom Confirmation Modal Logic ---
            closeConfirmModalBtn.addEventListener('click', () => closeModal(confirmModal));
            cancelDeleteBtn.addEventListener('click', () => closeModal(confirmModal));

            confirmDeleteBtn.addEventListener('click', confirmDeletePayment);

            function confirmDeletePayment() {
                if (!deleteId) return;

                const confirmBtn = document.getElementById('confirmDeleteBtn');
                confirmBtn.disabled = true;

                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'fees_structures.php';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="id" value="${deleteId}">
                    <input type="hidden" name="delete_fee" value="1">
                `;
                document.body.appendChild(form);
                form.submit();
            }

            window.addEventListener('click', (event) => {
                if (event.target === feeModal) closeModal(feeModal);
                if (event.target === confirmModal) closeModal(confirmModal);
            });

            // Make functions globally accessible
            window.openEditModal = function(fee) {
                modalTitle.innerText = "Edit Fee Structure";
                feeForm.reset();
                resetFormValidation();
                feeIdInput.value = fee.id;
                document.getElementById('class').value = fee.class_name;
                document.getElementById('student_type').value = fee.student_type;
                document.getElementById('term').value = fee.term;
                document.getElementById('year').value = fee.year;
                document.getElementById('amount').value = fee.amount;
                formSubmitBtn.name = "edit_fee";
                formSubmitBtn.innerText = "Update Fee Structure";
                openModal(feeModal);
            }

            window.openDeleteModal = function(id) {
                deleteId = id;
                openModal(confirmModal);
            }

            // --- Form Validation Logic ---
            const validationRules = {
                class: {
                    required: true
                },
                student_type: {
                    required: true
                },
                term: {
                    required: true
                },
                year: {
                    required: true,
                    min: 2020,
                    max: new Date().getFullYear() + 10
                },
                amount: {
                    required: true,
                    min: 0
                }
            };

            const getFieldLabel = (fieldName) => {
                const labels = {
                    'class': 'Class',
                    'student_type': 'Student Type',
                    'term': 'Term',
                    'year': 'Year',
                    'amount': 'Amount'
                };
                return labels[fieldName] || fieldName;
            };

            const showFieldError = (field, message) => {
                const errorElement = document.getElementById(`${field.name}-error`);
                if (errorElement) errorElement.textContent = message;
                field.classList.add('error');
            };

            const clearFieldError = (field) => {
                const errorElement = document.getElementById(`${field.name}-error`);
                if (errorElement) errorElement.textContent = '';
                field.classList.remove('error');
            };

            const validateField = (field) => {
                const {
                    name,
                    value
                } = field;
                const rules = validationRules[name];
                if (!rules) return true;

                clearFieldError(field);

                if (rules.required && !value.trim()) {
                    showFieldError(field, `${getFieldLabel(name)} is required.`);
                    return false;
                }

                const numValue = parseFloat(value);
                if (rules.min !== undefined && numValue < rules.min) {
                    showFieldError(field, `${getFieldLabel(name)} must be at least ${rules.min}.`);
                    return false;
                }

                if (rules.max !== undefined && numValue > rules.max) {
                    showFieldError(field, `${getFieldLabel(name)} must not exceed ${rules.max}.`);
                    return false;
                }
                return true;
            };

            const validateForm = () => {
                let isValid = true;
                const fieldsToValidate = feeForm.querySelectorAll('input[required], select[required]');
                fieldsToValidate.forEach(field => {
                    if (!validateField(field)) {
                        isValid = false;
                    }
                });
                return isValid;
            };

            const resetFormValidation = () => {
                const fields = feeForm.querySelectorAll('input, select');
                fields.forEach(field => clearFieldError(field));
            }

            // Attach validation listeners
            const fieldsToValidate = feeForm.querySelectorAll('input[required], select[required]');
            fieldsToValidate.forEach(field => {
                field.addEventListener('blur', () => validateField(field));
                field.addEventListener('input', () => clearFieldError(field));
            });

            feeForm.addEventListener('submit', function(e) {
                if (!validateForm()) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>

</html>