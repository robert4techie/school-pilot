<?php
require_once "conn.php";
require_once "auth.php";
require_once 'tracking.php';
$tracker->trackAction("View students discpline");

// Get filter options for dropdowns only once on page load
$classes_result = mysqli_query($conn, "SELECT DISTINCT class FROM student_behaviors WHERE class IS NOT NULL AND class != '' ORDER BY class");
$streams_result = mysqli_query($conn, "SELECT DISTINCT stream FROM student_behaviors WHERE stream IS NOT NULL AND stream != '' ORDER BY stream");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Behavior Records</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- PDF Export Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js"></script>

    <style>
        :root {
            --primary: #2E8B57;
            --primary-light: #3CB371;
            --primary-dark: #006400;
            --primary-gradient: linear-gradient(135deg, #2E8B57 0%, #20B2AA 100%);
            --danger: #F44336;
            --danger-light: #FF6B6B;
            --success: #4CAF50;
            --warning: #FF9800;
            --gray-light: #f8f9fa;
            --gray: #e9ecef;
            --gray-medium: #dee2e6;
            --gray-dark: #6c757d;
            --text-dark: #212529;
            --text-muted: #6c757d;
            --white: #ffffff;
            --shadow-sm: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            --shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            --shadow-lg: 0 1rem 3rem rgba(0, 0, 0, 0.175);
            --border-radius: 0.75rem;
            --border-radius-sm: 0.5rem;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: var(--text-dark);
            line-height: 1.6;
            min-height: 100vh;
        }

        .container {
            max-width: 100%;
            margin: 20px auto;
            margin-top: 80px;
            padding: 0 20px;
        }

        /* Enhanced Page Header */
        .page-header {
            background: var(--primary-gradient);
            color: white;
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.05)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.05)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.03)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            pointer-events: none;
        }

        .header-content {
            position: relative;
            z-index: 1;
        }

        .page-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            font-weight: 300;
        }

        /* Enhanced Filter Section */
        .filter-section {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--gray);
        }

        .filter-header h3 {
            color: var(--primary-dark);
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-group label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .input-with-icon {
            position: relative;
        }

        .input-with-icon i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-dark);
            z-index: 1;
        }

        .input-with-icon .form-control {
            padding-left: 2.5rem;
        }

        /* Enhanced Form Controls */
        .form-control {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid var(--gray);
            border-radius: var(--border-radius-sm);
            font-size: 0.95rem;
            transition: var(--transition);
            background: var(--white);
            font-family: inherit;
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(46, 139, 87, 0.1);
            transform: translateY(-1px);
        }

        .form-control:hover {
            border-color: var(--primary-light);
        }

        /* Enhanced Buttons */
        .btnn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.875rem 1.5rem;
            border: none;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            text-align: center;
            white-space: nowrap;
        }

        .btnn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .btnn:hover::before {
            left: 100%;
        }

        .btnn-primary {
            background: var(--primary-gradient);
            color: white;
            box-shadow: var(--shadow);
        }

        .btnn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btnn-secondary {
            background: var(--gray-medium);
            color: var(--text-dark);
        }

        .btnn-secondary:hover {
            background: var(--gray-dark);
            color: white;
        }

        .btnn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btnn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .btnn-danger {
            background: linear-gradient(135deg, var(--danger) 0%, var(--danger-light) 100%);
            color: white;
        }

        .btnn-danger:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        /* Enhanced Table Container */
        .table-container {
            background: var(--white);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray);
            min-height: 400px;
            position: relative;
        }

        .table-header {
            padding: 1.5rem 2rem;
            background: linear-gradient(135deg, var(--gray-light) 0%, #e8f5e8 100%);
            border-bottom: 2px solid var(--gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h3 {
            color: var(--primary-dark);
            font-size: 1.25rem;
            font-weight: 600;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }

        .data-table th,
        .data-table td {
            padding: 1rem 1.5rem;
            text-align: left;
            border-bottom: 1px solid var(--gray);
            vertical-align: middle;
        }

        .data-table th {
            background: linear-gradient(135deg, var(--gray-light) 0%, #f0f8f0 100%);
            font-weight: 700;
            color: var(--primary-dark);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.85rem;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .data-table tbody tr {
            transition: var(--transition);
        }

        .data-table tbody tr:hover {
            background: linear-gradient(135deg, #f0f8f0 0%, #e8f5e8 100%);
            transform: scale(1.01);
            box-shadow: var(--shadow-sm);
        }

        /* Enhanced Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.375rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-positive {
            background: linear-gradient(135deg, rgba(76, 175, 80, 0.15) 0%, rgba(76, 175, 80, 0.25) 100%);
            color: var(--primary-dark);
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .badge-negative {
            background: linear-gradient(135deg, rgba(244, 67, 54, 0.15) 0%, rgba(244, 67, 54, 0.25) 100%);
            color: var(--danger);
            border: 1px solid rgba(244, 67, 54, 0.3);
        }

        /* Enhanced Actions */
        .actions {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
        }

        .actions a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            text-decoration: none;
            transition: var(--transition);
            position: relative;
        }

        .action-view {
            background: linear-gradient(135deg, #17a2b8 0%, #20c997 100%);
            color: white;
        }

        .action-edit {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
            color: white;
        }

        .action-delete {
            background: linear-gradient(135deg, var(--danger) 0%, var(--danger-light) 100%);
            color: white;
        }

        .actions a:hover {
            transform: translateY(-2px) scale(1.1);
            box-shadow: var(--shadow);
        }

        /* Enhanced Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 2rem;
            gap: 0.5rem;
        }

        .pagination button {
            background: var(--white);
            border: 2px solid var(--gray);
            color: var(--text-dark);
            cursor: pointer;
            padding: 0.75rem 1rem;
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
            font-weight: 600;
            min-width: 3rem;
        }

        .pagination button:hover:not(:disabled) {
            background: var(--primary-light);
            color: white;
            border-color: var(--primary-light);
            transform: translateY(-1px);
        }

        .pagination button.active {
            background: var(--primary-gradient);
            color: white;
            border-color: var(--primary);
            box-shadow: var(--shadow);
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* Enhanced Modals */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(10px);
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .modal-content {
            background: var(--white);
            margin: 2% auto;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            animation: slideIn 0.3s ease-out;
            position: relative;
        }

        .modal-sm {
            max-width: 400px;
        }

        .modal-lg {
            max-width: 900px;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 1.5rem 2rem;
            border-bottom: 2px solid var(--gray);
            background: linear-gradient(135deg, var(--gray-light) 0%, #f0f8f0 100%);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
        }

        .modal-header h3 {
            margin: 0;
            color: var(--primary-dark);
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .confirm-header {
            background: linear-gradient(135deg, rgba(244, 67, 54, 0.1) 0%, rgba(255, 107, 107, 0.1) 100%);
        }

        .confirm-header h3 {
            color: var(--danger);
        }

        .close-btnn {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--gray-dark);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 50%;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
        }

        .close-btnn:hover {
            background: rgba(244, 67, 54, 0.1);
            color: var(--danger);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-footer {
            padding: 1.5rem 2rem;
            border-top: 2px solid var(--gray);
            background: var(--gray-light);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            border-radius: 0 0 var(--border-radius) var(--border-radius);
        }

        /* Form Enhancements */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-group label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
            font-family: inherit;
        }

        /* Detail Display */
        .detail-row {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
            padding: 0.75rem;
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
        }

        .detail-row:hover {
            background: var(--gray-light);
        }

        .detail-label {
            font-weight: 700;
            color: var(--primary-dark);
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
        }

        .detail-value {
            color: var(--text-dark);
            font-weight: 500;
        }

        /* Loading and Empty States */
        .loading-overlay,
        .empty-state {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(5px);
        }

        .loading-overlay .fa-spinner {
            font-size: 3rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--gray-dark);
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            color: var(--text-muted);
            font-weight: 400;
            font-size: 1.25rem;
        }

        /* Toast Notifications */
        .toast {
            position: fixed;
            top: 100px;
            right: 2rem;
            background: var(--white);
            border-radius: var(--border-radius-sm);
            box-shadow: var(--shadow-lg);
            padding: 1rem 1.5rem;
            display: none;
            align-items: center;
            gap: 1rem;
            z-index: 1100;
            border-left: 4px solid var(--success);
            min-width: 300px;
            animation: slideInRight 0.3s ease-out;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .toast.success {
            border-left-color: var(--success);
        }

        .toast.error {
            border-left-color: var(--danger);
        }

        .toast.warning {
            border-left-color: var(--warning);
        }

        .toast-content {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex: 1;
        }

        .toast-icon {
            font-size: 1.25rem;
        }

        .toast.success .toast-icon {
            color: var(--success);
        }

        .toast.error .toast-icon {
            color: var(--danger);
        }

        .toast.warning .toast-icon {
            color: var(--warning);
        }

        .toast-message {
            font-weight: 500;
            color: var(--text-dark);
        }

        .toast-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--gray-dark);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 50%;
            transition: var(--transition);
        }

        .toast-close:hover {
            background: var(--gray-light);
            color: var(--danger);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
                margin-top: 60px;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
                padding: 1.5rem;
            }

            .page-header h2 {
                font-size: 1.75rem;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .table-wrapper {
                overflow-x: auto;
            }

            .data-table {
                min-width: 800px;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .detail-row {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }

            .actions {
                flex-direction: column;
                gap: 0.5rem;
            }

            .toast {
                right: 1rem;
                left: 1rem;
                min-width: auto;
            }
        }

        @media (max-width: 480px) {
            .page-header h2 {
                font-size: 1.5rem;
            }

            .modal-header,
            .modal-body,
            .modal-footer {
                padding: 1rem;
            }

            .btnn {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }
        }

        /* Smooth scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--gray-light);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-light);
            border-radius: 4px;
            transition: var(--transition);
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary);
        }

        /* Focus styles for accessibility */
        .btnn:focus,
        .form-control:focus,
        button:focus {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }

        /* Print styles */
        @media print {

            .page-header,
            .filter-section,
            .pagination,
            .actions {
                display: none;
            }

            .table-container {
                box-shadow: none;
                border: 1px solid #000;
            }

            .data-table th,
            .data-table td {
                border: 1px solid #000;
                padding: 0.5rem;
            }
        }
    </style>

<body>
    <?php require_once "nav.php" ?>
    <div class="container">
        <div class="page-header">
            <div class="header-content">
                <h2><i class="fas fa-clipboard-list"></i> Student Behavior Records</h2>
                <p class="header-subtitle">Manage and track student behavioral incidents and achievements</p>
            </div>
            <a href="add_student_discpline.php" class="btnn btnn-primary">
                <i class="fas fa-plus"></i> Add New Record
            </a>
        </div>

        <!-- Enhanced Filter Section -->
        <div class="filter-section">
            <div class="filter-header">
                <h3><i class="fas fa-filter"></i> Filter Records</h3>
                <button id="resetFilters" class="btnn btnn-outline">
                    <i class="fas fa-undo"></i> Reset
                </button>
            </div>
            <form id="filterForm" class="filter-form">
                <div class="filter-group">
                    <label for="search">Search</label>
                    <div class="input-with-icon">
                        <i class="fas fa-search"></i>
                        <input type="text" id="search" name="search" class="form-control" placeholder="Search by name, ID, description...">
                    </div>
                </div>
                <div class="filter-group">
                    <label for="class">Class</label>
                    <select id="class" name="class" class="form-control">
                        <option value="">All Classes</option>
                        <?php while ($class_row = mysqli_fetch_assoc($classes_result)): ?>
                            <option value="<?php echo htmlspecialchars($class_row['class']); ?>"><?php echo htmlspecialchars(ucwords($class_row['class'])); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="stream">Stream</label>
                    <select id="stream" name="stream" class="form-control">
                        <option value="">All Streams</option>
                        <?php while ($stream_row = mysqli_fetch_assoc($streams_result)): ?>
                            <option value="<?php echo htmlspecialchars($stream_row['stream']); ?>"><?php echo htmlspecialchars(ucfirst($stream_row['stream'])); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="type">Type</label>
                    <select id="type" name="type" class="form-control">
                        <option value="">All Types</option>
                        <option value="Positive">Positive</option>
                        <option value="Negative">Negative</option>
                    </select>
                </div>
            </form>
        </div>

        <!-- Enhanced Table Container -->
        <div id="tableContainer" class="table-container">
            <div class="table-header">
                <h3>Behavior Records</h3>
                <div class="table-actions" style="display: flex; gap: 1rem;">
                    <button id="exportCsvBtn" class="btnn btnn-outline">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                    <button id="exportPdfBtn" class="btnn btnn-outline">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Class & Stream</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Date</th>
                            <th>Reporter</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="recordsBody">
                        <!-- Data will be loaded here by AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
        <div id="pagination" class="pagination"></div>
    </div>

    <!-- Detail Modal -->
    <div id="detailModal" class="modal">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> Behavior Record Details</h3>
                <button class="close-btnn" aria-label="Close modal">&times;</button>
            </div>
            <div id="modalBody" class="modal-body">
                <!-- Modal content will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Behavior Record</h3>
                <button class="close-btnn" aria-label="Close modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editForm">
                    <input type="hidden" id="edit_record_id" name="record_id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_student_id">Student ID</label>
                            <input type="text" id="edit_student_id" name="student_id" class="form-control" readonly>
                        </div>
                        <div class="form-group">
                            <label for="edit_student_name">Student Name</label>
                            <input type="text" id="edit_student_name" class="form-control" readonly>
                        </div>
                        <div class="form-group">
                            <label for="edit_class">Class</label>
                            <input type="text" id="edit_class" name="class" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_stream">Stream</label>
                            <input type="text" id="edit_stream" name="stream" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_type">Type</label>
                            <select id="edit_type" name="type" class="form-control" required>
                                <option value="Positive">Positive</option>
                                <option value="Negative">Negative</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_date_occurred">Date Occurred</label>
                            <input type="date" id="edit_date_occurred" name="date_occurred" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="edit_description">Description</label>
                        <textarea id="edit_description" name="description" class="form-control" rows="3" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="edit_action_taken">Action Taken</label>
                        <textarea id="edit_action_taken" name="action_taken" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="edit_follow_up">Follow-up</label>
                        <textarea id="edit_follow_up" name="follow_up" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btnn btnn-secondary" onclick="closeModal('editModal')">Cancel</button>
                        <button type="submit" class="btnn btnn-primary">
                            <i class="fas fa-save"></i> Update Record
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Custom Confirmation Modal -->
    <div id="confirmModal" class="modal">
        <div class="modal-content modal-sm">
            <div class="modal-header confirm-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Confirm Action</h3>
            </div>
            <div class="modal-body">
                <p id="confirmMessage">Are you sure you want to perform this action?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btnn btnn-secondary" onclick="closeModal('confirmModal')">Cancel</button>
                <button type="button" id="confirmbtnn" class="btnn btnn-danger">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </div>
        </div>
    </div>

    <!-- Success/Error Toast -->
    <div id="toast" class="toast">
        <div class="toast-content">
            <i class="toast-icon"></i>
            <span class="toast-message"></span>
        </div>
        <button class="toast-close">&times;</button>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const tableContainer = document.getElementById('tableContainer');
            const recordsBody = document.getElementById('recordsBody');
            const paginationContainer = document.getElementById('pagination');
            const filterForm = document.getElementById('filterForm');
            const detailModal = document.getElementById('detailModal');
            const editModal = document.getElementById('editModal');
            const confirmModal = document.getElementById('confirmModal');
            const modalBody = document.getElementById('modalBody');
            const editForm = document.getElementById('editForm');
            const resetFiltersbtnn = document.getElementById('resetFilters');
            const exportCsvBtn = document.getElementById('exportCsvBtn');
            const exportPdfBtn = document.getElementById('exportPdfBtn');

            let currentPage = 1;
            let searchTimeout;
            let pendingDeleteId = null;

            // --- INITIALIZATION ---
            init();

            function init() {
                setupEventListeners();
                fetchRecords();
            }

            function setupEventListeners() {
                // Filter form events
                filterForm.addEventListener('input', handleFilterChange);
                resetFiltersbtnn.addEventListener('click', resetFilters);

                // Modal events
                setupModalEvents();

                // Form submissions
                editForm.addEventListener('submit', handleEditSubmit);

                // Update the export functionality
                document.getElementById('exportCsvBtn').addEventListener('click', exportData);
                document.getElementById('exportPdfBtn').addEventListener('click', exportToPDF);

                // Table events
                recordsBody.addEventListener('click', handleTableActions);
            }

            function setupModalEvents() {
                // Close modal events
                document.querySelectorAll('.close-btnn').forEach(btnn => {
                    btnn.addEventListener('click', (e) => {
                        const modal = e.target.closest('.modal');
                        closeModal(modal.id);
                    });
                });

                // Click outside to close
                window.addEventListener('click', (e) => {
                    if (e.target.classList.contains('modal')) {
                        closeModal(e.target.id);
                    }
                });

                // Escape key to close
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                        closeAllModals();
                    }
                });

                // Confirm modal button
                document.getElementById('confirmbtnn').addEventListener('click', handleConfirmAction);
            }

            // --- DATA FETCHING AND RENDERING ---
            function fetchRecords() {
                showLoading();
                const formData = new FormData(filterForm);
                const params = new URLSearchParams(formData);
                params.append('page', currentPage);

                fetch(`api/fetch_behavior_records.php?${params.toString()}`)
                    .then(response => {
                        if (!response.ok) throw new Error('Network response was not ok');
                        return response.json();
                    })
                    .then(data => {
                        renderTable(data.records);
                        renderPagination(data.pagination);
                        hideLoading();
                    })
                    .catch(error => {
                        console.error('Error fetching records:', error);
                        showEmptyState('Error loading data. Please try again.');
                        hideLoading();
                        showToast('Failed to load records. Please try again.', 'error');
                    });
            }

            function renderTable(records) {
                recordsBody.innerHTML = '';
                if (records.length === 0) {
                    showEmptyState('No records found matching your criteria.');
                    return;
                }

                records.forEach(record => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                <td>
                    <div class="student-info">
                        <strong>${record.student_name || 'Unknown'}</strong><br>
                        <small class="text-muted">ID: ${record.student_id || 'N/A'}</small>
                    </div>
                </td>
                <td>
                    <div class="class-info">
                        <strong>${record.class || ''}</strong><br>
                        <small class="text-muted">${record.stream || ''}</small>
                    </div>
                </td>
                <td>
                    <span class="badge ${record.type === 'Positive' ? 'badge-positive' : 'badge-negative'}">
                        <i class="fas ${record.type === 'Positive' ? 'fa-thumbs-up' : 'fa-exclamation-triangle'}"></i>
                        ${record.type}
                    </span>
                </td>
                <td>
                    <div class="description-cell" title="${record.description}">
                        ${truncateText(record.description, 60)}
                    </div>
                </td>
                <td>${formatDate(record.date_occurred)}</td>
                <td>${record.reporter_name || record.reporter || 'N/A'}</td>
                <td class="actions">
                    <a href="#" class="action-view view-record" data-id="${record.id}" title="View Details">
                        <i class="fas fa-eye"></i>
                    </a>
                    <a href="#" class="action-edit edit-record" data-id="${record.id}" title="Edit Record">
                        <i class="fas fa-edit"></i>
                    </a>
                    <a href="#" class="action-delete delete-record" data-id="${record.id}" title="Delete Record">
                        <i class="fas fa-trash-alt"></i>
                    </a>
                </td>
            `;
                    recordsBody.appendChild(row);
                });
            }

            function renderPagination(pagination) {
                paginationContainer.innerHTML = '';
                if (pagination.totalPages <= 1) return;

                // Previous Button
                const prevButton = createPaginationButton(
                    '<i class="fas fa-chevron-left"></i>',
                    pagination.currentPage - 1,
                    pagination.currentPage === 1
                );
                paginationContainer.appendChild(prevButton);

                // Page numbers with ellipsis
                const visiblePages = getVisiblePages(pagination.currentPage, pagination.totalPages);

                visiblePages.forEach(page => {
                    if (page === '...') {
                        const ellipsis = document.createElement('span');
                        ellipsis.textContent = '...';
                        ellipsis.className = 'pagination-ellipsis';
                        paginationContainer.appendChild(ellipsis);
                    } else {
                        const pageButton = createPaginationButton(
                            page,
                            page,
                            false,
                            page === pagination.currentPage
                        );
                        paginationContainer.appendChild(pageButton);
                    }
                });

                // Next Button
                const nextButton = createPaginationButton(
                    '<i class="fas fa-chevron-right"></i>',
                    pagination.currentPage + 1,
                    pagination.currentPage === pagination.totalPages
                );
                paginationContainer.appendChild(nextButton);
            }

            function createPaginationButton(text, page, isDisabled = false, isActive = false) {
                const button = document.createElement('button');
                button.innerHTML = text;
                button.disabled = isDisabled;
                if (isActive) button.classList.add('active');
                button.addEventListener('click', () => {
                    currentPage = page;
                    fetchRecords();
                });
                return button;
            }

            function getVisiblePages(current, total) {
                const pages = [];
                const delta = 2;

                if (total <= 7) {
                    for (let i = 1; i <= total; i++) {
                        pages.push(i);
                    }
                } else {
                    if (current <= delta + 1) {
                        for (let i = 1; i <= delta + 3; i++) {
                            pages.push(i);
                        }
                        pages.push('...');
                        pages.push(total);
                    } else if (current >= total - delta) {
                        pages.push(1);
                        pages.push('...');
                        for (let i = total - delta - 2; i <= total; i++) {
                            pages.push(i);
                        }
                    } else {
                        pages.push(1);
                        pages.push('...');
                        for (let i = current - delta; i <= current + delta; i++) {
                            pages.push(i);
                        }
                        pages.push('...');
                        pages.push(total);
                    }
                }
                return pages;
            }

            // --- EVENT HANDLERS ---
            function handleFilterChange(e) {
                if (e.target.id === 'search') {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        currentPage = 1;
                        fetchRecords();
                    }, 300);
                } else {
                    currentPage = 1;
                    fetchRecords();
                }
            }

            function resetFilters() {
                filterForm.reset();
                currentPage = 1;
                fetchRecords();
                showToast('Filters reset successfully', 'success');
            }

            function handleTableActions(e) {
                const target = e.target.closest('a');
                if (!target) return;

                e.preventDefault();
                const id = target.dataset.id;

                if (target.classList.contains('view-record')) {
                    openDetailModal(id);
                } else if (target.classList.contains('edit-record')) {
                    openEditModal(id);
                } else if (target.classList.contains('delete-record')) {
                    showDeleteConfirmation(id);
                }
            }

            function handleEditSubmit(e) {
                e.preventDefault();

                const submitbtnn = e.target.querySelector('button[type="submit"]');
                const originalText = submitbtnn.innerHTML;

                submitbtnn.disabled = true;
                submitbtnn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';

                const formData = new FormData(editForm);

                fetch('api/update_behavior_record.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            closeModal('editModal');
                            fetchRecords();
                            showToast('Record updated successfully!', 'success');
                        } else {
                            showToast(`Error: ${data.message}`, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error updating record:', error);
                        showToast('Failed to update record. Please try again.', 'error');
                    })
                    .finally(() => {
                        submitbtnn.disabled = false;
                        submitbtnn.innerHTML = originalText;
                    });
            }

            function handleConfirmAction() {
                if (pendingDeleteId) {
                    deleteRecord(pendingDeleteId);
                    closeModal('confirmModal');
                }
            }

            // --- MODAL FUNCTIONS ---
            function openDetailModal(id) {
                modalBody.innerHTML = '<div class="loading-overlay" style="position: static; height: 200px;"><i class="fas fa-spinner fa-spin"></i><p>Loading details...</p></div>';
                openModal('detailModal');

                fetch(`api/get_behavior_details.php?id=${id}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            renderModalContent(data.data);
                        } else {
                            modalBody.innerHTML = `<div class="empty-state" style="position: static; height: 200px;"><i class="fas fa-exclamation-triangle"></i><h3>Error: ${data.message}</h3></div>`;
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching details:', error);
                        modalBody.innerHTML = '<div class="empty-state" style="position: static; height: 200px;"><i class="fas fa-exclamation-triangle"></i><h3>Could not load details. Please try again.</h3></div>';
                    });
            }

            function openEditModal(id) {
                openModal('editModal');

                // Show loading in form
                const formElements = editForm.querySelectorAll('input, select, textarea');
                formElements.forEach(el => el.disabled = true);

                fetch(`api/get_behavior_details.php?id=${id}`)
                    .then(response => response.json())
                    .then(data => {
                        console.log('Fetched data for edit:', data); // Debug line
                        if (data.status === 'success') {
                            populateEditForm(data.data);
                        } else {
                            showToast(`Error: ${data.message}`, 'error');
                            closeModal('editModal');
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching record for edit:', error);
                        showToast('Failed to load record for editing.', 'error');
                        closeModal('editModal');
                    })
                    .finally(() => {
                        formElements.forEach(el => el.disabled = false);
                    });
            }

            function showDeleteConfirmation(id) {
                pendingDeleteId = id;
                document.getElementById('confirmMessage').textContent =
                    'Are you sure you want to delete this behavior record? This action cannot be undone.';
                openModal('confirmModal');
            }

            function openModal(modalId) {
                const modal = document.getElementById(modalId);
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';

                // Focus management
                setTimeout(() => {
                    const firstFocusable = modal.querySelector('button, input, select, textarea, [tabindex]:not([tabindex="-1"])');
                    if (firstFocusable) firstFocusable.focus();
                }, 100);
            }

            function closeModal(modalId) {
                const modal = document.getElementById(modalId);
                modal.style.display = 'none';
                document.body.style.overflow = '';

                // Reset pending actions
                if (modalId === 'confirmModal') {
                    pendingDeleteId = null;
                }
            }

            function closeAllModals() {
                document.querySelectorAll('.modal').forEach(modal => {
                    modal.style.display = 'none';
                });
                document.body.style.overflow = '';
                pendingDeleteId = null;
            }

            // --- MODAL CONTENT RENDERING ---
            function renderModalContent(record) {
                modalBody.innerHTML = `
            <div class="detail-row">
                <div class="detail-label">Student:</div> 
                <div class="detail-value">
                    <strong>${record.student_name || 'N/A'}</strong> 
                    <small>(ID: ${record.student_id})</small>
                </div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Class:</div> 
                <div class="detail-value">${record.class} - ${record.stream}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Type:</div> 
                <div class="detail-value">
                    <span class="badge ${record.type === 'Positive' ? 'badge-positive' : 'badge-negative'}">
                        <i class="fas ${record.type === 'Positive' ? 'fa-thumbs-up' : 'fa-exclamation-triangle'}"></i>
                        ${record.type}
                    </span>
                </div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Date:</div> 
                <div class="detail-value">${formatDateTime(record.date_occurred)}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Reporter:</div> 
                <div class="detail-value">${record.reporter_name}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Description:</div> 
                <div class="detail-value">${record.description}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Action Taken:</div> 
                <div class="detail-value">${record.action_taken || 'None specified'}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Follow-up:</div> 
                <div class="detail-value">${record.follow_up || 'None'}</div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Recorded On:</div> 
                <div class="detail-value">${formatDateTime(record.created_at)}</div>
            </div>
        `;
            }

            function populateEditForm(record) {
                console.log('Populating form with record:', record);

                // Set the record ID
                const recordIdField = document.getElementById('edit_record_id');
                if (recordIdField) {
                    recordIdField.value = record.id || '';
                }

                // Set student ID - show the actual value from database (including "0")
                const studentIdField = document.getElementById('edit_student_id');
                if (studentIdField) {
                    studentIdField.value = record.student_id || '';
                    console.log('Set student ID to:', record.student_id);

                    // Make the field editable so users can fix invalid student IDs
                    studentIdField.removeAttribute('readonly');
                    studentIdField.style.backgroundColor = '#fff3cd'; // Light yellow to indicate it needs attention
                    studentIdField.placeholder = 'Enter valid student ID (e.g., XYZ-2025-STD-0001)';
                }

                // Set student name
                const studentNameField = document.getElementById('edit_student_name');
                if (studentNameField) {
                    let displayName = record.student_name;
                    if (record.student_id === '0') {
                        displayName = 'No Student Selected - Please enter valid Student ID';
                        studentNameField.style.color = '#856404'; // Warning color
                    }
                    studentNameField.value = displayName || '';
                    console.log('Set student name to:', displayName);
                }

                // Set other fields (same as before)
                const classField = document.getElementById('edit_class');
                if (classField) classField.value = record.class || '';

                const streamField = document.getElementById('edit_stream');
                if (streamField) streamField.value = record.stream || '';

                const typeField = document.getElementById('edit_type');
                if (typeField) typeField.value = record.type || '';

                const dateField = document.getElementById('edit_date_occurred');
                if (dateField) {
                    dateField.value = record.date_occurred ? record.date_occurred.split(' ')[0] : '';
                }

                const descriptionField = document.getElementById('edit_description');
                if (descriptionField) descriptionField.value = record.description || '';

                const actionField = document.getElementById('edit_action_taken');
                if (actionField) actionField.value = record.action_taken || '';

                const followUpField = document.getElementById('edit_follow_up');
                if (followUpField) followUpField.value = record.follow_up || '';
            }

            // --- RECORD OPERATIONS ---
            function deleteRecord(id) {
                const confirmbtnn = document.getElementById('confirmbtnn');
                const originalText = confirmbtnn.innerHTML;

                confirmbtnn.disabled = true;
                confirmbtnn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';

                fetch(`api/delete_behavior.php?id=${id}`, {
                        method: 'POST'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            fetchRecords();
                            showToast('Record deleted successfully!', 'success');
                        } else {
                            showToast(`Error: ${data.message}`, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error deleting record:', error);
                        showToast('Failed to delete record. Please try again.', 'error');
                    })
                    .finally(() => {
                        confirmbtnn.disabled = false;
                        confirmbtnn.innerHTML = originalText;
                        pendingDeleteId = null;
                    });
            }

            // --- UTILITY FUNCTIONS ---
            function showLoading() {
                const existingLoading = tableContainer.querySelector('.loading-overlay');
                if (existingLoading) return;

                const loading = document.createElement('div');
                loading.className = 'loading-overlay';
                loading.innerHTML = `
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading records...</p>
        `;
                tableContainer.appendChild(loading);
            }

            function hideLoading() {
                const loading = tableContainer.querySelector('.loading-overlay');
                if (loading) loading.remove();
            }

            function showEmptyState(message) {
                recordsBody.innerHTML = `
            <tr>
                <td colspan="7">
                    <div class="empty-state" style="position: static; height: 300px;">
                        <i class="fas fa-search"></i>
                        <h3>${message}</h3>
                        <p>Try adjusting your filters or search criteria.</p>
                    </div>
                </td>
            </tr>
        `;
            }

            function showToast(message, type = 'success') {
                const toast = document.getElementById('toast');
                const icon = toast.querySelector('.toast-icon');
                const messageEl = toast.querySelector('.toast-message');

                // Set content
                messageEl.textContent = message;

                // Set icon based on type
                const icons = {
                    success: 'fas fa-check-circle',
                    error: 'fas fa-exclamation-circle',
                    warning: 'fas fa-exclamation-triangle'
                };

                icon.className = `toast-icon ${icons[type] || icons.success}`;

                // Set type class
                toast.className = `toast ${type}`;

                // Show toast
                toast.style.display = 'flex';

                // Auto hide after 5 seconds
                setTimeout(() => {
                    hideToast();
                }, 5000);

                // Manual close
                toast.querySelector('.toast-close').onclick = hideToast;
            }

            function hideToast() {
                const toast = document.getElementById('toast');
                toast.style.display = 'none';
            }

            function truncateText(text, length) {
                if (!text || text.length <= length) return text || '';
                return text.substr(0, length) + '...';
            }

            function formatDate(dateString) {
                if (!dateString) return 'N/A';
                return new Date(dateString).toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
            }

            function formatDateTime(dateString) {
                if (!dateString) return 'N/A';
                return new Date(dateString).toLocaleString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }

            function exportData() {
                showToast('Preparing CSV export...', 'success');

                // Use the current filters to build the query parameters
                const formData = new FormData(filterForm);
                const params = new URLSearchParams(formData);

                // Add the export flag so the backend knows to send a CSV file
                // with all matching records.
                params.append('export', 'true');

                
                const link = document.createElement('a');
                link.href = `export_behavior_records.php?${params.toString()}`;
                link.download = `behavior_records_${new Date().toISOString().split('T')[0]}.csv`;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }

            function exportToPDF() {
                showToast('Preparing PDF export...', 'success');

                // Use the current filters to fetch ALL matching records, not just one page
                const formData = new FormData(filterForm);
                const params = new URLSearchParams(formData);
                params.append('export', 'all'); // A new parameter to tell the backend to send all data

                fetch(`api/fetch_behavior_records.php?${params.toString()}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.records && data.records.length > 0) {
                            generatePdf(data.records);
                        } else {
                            showToast('No data available to export.', 'warning');
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching data for PDF export:', error);
                        showToast('Failed to fetch data for PDF.', 'error');
                    });
            }

            function generatePdf(records) {
                const {
                    jsPDF
                } = window.jspdf;
                const doc = new jsPDF();

                // Define the columns for the table
                const tableColumns = [{
                        header: 'Student Name',
                        dataKey: 'student_name'
                    },
                    {
                        header: 'Student ID',
                        dataKey: 'student_id'
                    },
                    {
                        header: 'Class',
                        dataKey: 'class'
                    },
                    {
                        header: 'Stream',
                        dataKey: 'stream'
                    },
                    {
                        header: 'Type',
                        dataKey: 'type'
                    },
                    {
                        header: 'Date',
                        dataKey: 'date_occurred'
                    },
                    {
                        header: 'Description',
                        dataKey: 'description'
                    }
                ];

                // Map the record data to a format that autoTable can use
                const tableRows = records.map(record => ({
                    student_name: record.student_name || 'N/A',
                    student_id: record.student_id || 'N/A',
                    class: record.class || 'N/A',
                    stream: record.stream || 'N/A',
                    type: record.type || 'N/A',
                    date_occurred: formatDate(record.date_occurred),
                    description: record.description || ''
                }));

                // Add a title to the document
                doc.setFontSize(18);
                doc.text('Student Behavior Records', 14, 22);
                doc.setFontSize(11);
                doc.setTextColor(100);
                doc.text(`Report generated on: ${new Date().toLocaleDateString()}`, 14, 29);

                // Create the table
                doc.autoTable({
                    columns: tableColumns,
                    body: tableRows,
                    startY: 35,
                    headStyles: {
                        fillColor: [46, 139, 87]
                    }, // A nice green color for the header
                    styles: {
                        fontSize: 8
                    },
                    columnStyles: {
                        description: {
                            cellWidth: 'auto'
                        }
                    }
                });

                // Save the PDF
                const fileName = `behavior_records_${new Date().toISOString().split('T')[0]}.pdf`;
                doc.save(fileName);

                showToast('PDF generated successfully!', 'success');
            }
            // --- GLOBAL FUNCTIONS (for inline event handlers) ---
            window.closeModal = closeModal;
            window.openModal = openModal;
        });
    </script>
</body>

</html>