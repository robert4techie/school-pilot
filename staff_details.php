<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Staff details");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Management - School Pilot</title>
    <!-- Add your CSS files here -->
    <link rel="stylesheet" href="your-styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            color: #2d5a3d;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, #2d5a3d 0%, #4a7c59 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            margin-top: 50px;
            box-shadow: 0 10px 30px rgba(45, 90, 61, 0.3);
        }

        .header h2 {
            font-size: 1.5rem;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
            font-size: 1.1rem;
        }

        .controls {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            border: 2px solid #e8f5e8;
        }

        .controls-row {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 12px 45px 12px 15px;
            border: 2px solid #d4e6d4;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .search-box input:focus {
            outline: none;
            border-color: #4a7c59;
            box-shadow: 0 0 0 3px rgba(74, 124, 89, 0.1);
        }

        .search-box i {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #4a7c59;
        }

        .filter-select {
            padding: 12px 15px;
            border: 2px solid #d4e6d4;
            border-radius: 8px;
            font-size: 16px;
            background: white;
            color: #2d5a3d;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-select:focus {
            outline: none;
            border-color: #4a7c59;
            box-shadow: 0 0 0 3px rgba(74, 124, 89, 0.1);
        }

        .btnn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }

        .btnn-primary {
            background: linear-gradient(135deg, #4a7c59 0%, #2d5a3d 100%);
            color: white;
        }

        .btnn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(74, 124, 89, 0.3);
        }

        .btnn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }

        .btnn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }

        .btnn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }

        .btnn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
        }

        .btnn-info {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
        }

        .btnn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
        }

        .bulk-actions {
            display: flex;
            gap: 15px;
            align-items: center;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e8f5e8;
        }

        .student-count {
            background: #e8f5e8;
            padding: 10px 15px;
            border-radius: 6px;
            font-weight: 600;
            color: #2d5a3d;
        }

        .students-grid {
            display: grid;
            gap: 20px;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
        }

        .student-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            border: 2px solid #e8f5e8;
            transition: all 0.3s ease;
            position: relative;
        }

        .student-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            border-color: #4a7c59;
        }

        .student-card.selected {
            border-color: #28a745;
            background: #f8fff8;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .student-info {
            flex: 1;
        }

        .student-name {
            font-size: 1.3rem;
            font-weight: bold;
            color: #2d5a3d;
            margin-bottom: 5px;
        }

        .student-id {
            font-size: 0.9rem;
            color: #666;
            background: #e8f5e8;
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
        }

        .select-checkbox {
            width: 20px;
            height: 20px;
            accent-color: #4a7c59;
            cursor: pointer;
        }

        .student-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 3px solid #4a7c59;
        }

        .detail-item i {
            color: #4a7c59;
            width: 16px;
        }

        .detail-label {
            font-weight: 600;
            color: #2d5a3d;
            margin-right: 5px;
        }

        .detail-value {
            color: #666;
        }

        .card-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e8f5e8;
        }

        .loading {
            text-align: center;
            padding: 50px;
            color: #4a7c59;
        }

        .loading i {
            font-size: 2rem;
            margin-bottom: 10px;
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

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            overflow: auto;
        }

        .modal-content {
            background-color: white;
            margin: 2% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e8f5e8;
            flex-shrink: 0;
        }

        .modal-body {
            padding: 0;
            overflow-y: auto;
            flex-grow: 1;
            margin-right: -15px;
            padding-right: 15px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding-top: 20px;
            margin-top: 20px;
            border-top: 2px solid #e8f5e8;
            flex-shrink: 0;
        }


        .modal-body::-webkit-scrollbar {
            width: 8px;
        }

        .modal-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .modal-body::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }

        .modal-body::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }


        .student-detail-view {
            display: flex;
            flex-direction: column;
            gap: 20px;
            padding-bottom: 10px;
        }


        .detail-header {
            display: flex;
            align-items: center;
            gap: 20px;
            padding-bottom: 20px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e8f5e8;
        }

        .student-photo-placeholder {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background-color: #e8f5e8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            color: #2d5a3d;
        }



        .progress-bar {
            width: 100%;
            height: 8px;
            background-color: #e8f5e8;
            border-radius: 4px;
            overflow: hidden;
            margin: 20px 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #28a745, #20c997);
            width: 0%;
            transition: width 0.3s ease;
        }

        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #28a745;
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            z-index: 1001;
            transform: translateX(100%);
            transition: transform 0.3s ease;
        }

        .toast.show {
            transform: translateX(0);
        }

        @media (max-width: 768px) {
            .controls-row {
                flex-direction: column;
                align-items: stretch;
            }

            .students-grid {
                grid-template-columns: 1fr;
            }

            .bulk-actions {
                flex-direction: column;
                align-items: stretch;
            }
        }

        /* Enhanced Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }

            100% {
                transform: scale(1);
            }
        }

        /* Apply animations to cards */
        .student-card {
            animation: fadeInUp 0.6s ease-out;
            animation-fill-mode: both;
        }

        .student-card:nth-child(even) {
            animation-delay: 0.1s;
        }

        .student-card:nth-child(odd) {
            animation-delay: 0.2s;
        }

        /* Enhanced Button Styles */
        .btnn {
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
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

        .btnn:active {
            transform: scale(0.98);
        }

        /* Enhanced Card Hover Effects */
        .student-card {
            position: relative;
            overflow: hidden;
        }

        .student-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(74, 124, 89, 0.05) 0%, rgba(40, 167, 69, 0.05) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }

        .student-card:hover::before {
            opacity: 1;
        }

        /* Advanced Loading States */
        .loading-skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }

        @keyframes loading {
            0% {
                background-position: 200% 0;
            }

            100% {
                background-position: -200% 0;
            }
        }

        /* Enhanced Form Elements */
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2d5a3d;
            transition: color 0.3s ease;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #d4e6d4;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f8fff8;
        }

        .form-input:focus {
            outline: none;
            border-color: #4a7c59;
            box-shadow: 0 0 0 3px rgba(74, 124, 89, 0.1);
            background: white;
        }

        .form-input:focus+.form-label {
            color: #4a7c59;
        }

        /* Stats Cards */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            border: 2px solid #e8f5e8;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #4a7c59, #28a745);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #2d5a3d;
            margin-bottom: 10px;
        }

        .stat-label {
            color: #666;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-icon {
            font-size: 3rem;
            color: #4a7c59;
            margin-bottom: 15px;
            opacity: 0.7;
        }

        /* Enhanced Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .pagination button {
            padding: 10px 15px;
            border: 2px solid #d4e6d4;
            background: white;
            color: #2d5a3d;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
        }

        .pagination button:hover {
            background: #4a7c59;
            color: white;
            border-color: #4a7c59;
        }

        .pagination button.active {
            background: #4a7c59;
            color: white;
            border-color: #4a7c59;
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Advanced Search Filters */
        .advanced-filters {
            background: #f8fff8;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #e8f5e8;
        }

        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .date-range {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .date-range input[type="date"] {
            padding: 10px;
            border: 2px solid #d4e6d4;
            border-radius: 6px;
            font-size: 14px;
        }

        /* Enhanced Mobile Responsiveness */
        @media (max-width: 1024px) {
            .students-grid {
                grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            }

            .stats-container {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .header h1 {
                font-size: 2rem;
            }

            .controls {
                padding: 20px 15px;
            }

            .students-grid {
                grid-template-columns: 1fr;
            }

            .student-details {
                grid-template-columns: 1fr;
            }

            .card-actions {
                flex-direction: column;
            }

            .btnn {
                width: 100%;
                justify-content: center;
            }

            .modal-content {
                margin: 10% auto;
                width: 95%;
                padding: 20px;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 10px;
            }

            .header {
                padding: 20px 15px;
            }

            .header h1 {
                font-size: 1.5rem;
            }

            .student-card {
                padding: 20px 15px;
            }
        }

        /* Print Styles */
        @media print {
            body {
                background: white;
            }

            .header,
            .controls,
            .card-actions,
            .bulk-actions {
                display: none;
            }

            .student-card {
                box-shadow: none;
                border: 1px solid #ccc;
                break-inside: avoid;
                margin-bottom: 20px;
            }

            .students-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Accessibility Enhancements */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .focus-visible {
            outline: 2px solid #4a7c59;
            outline-offset: 2px;
        }

        /* High Contrast Mode */
        @media (prefers-contrast: high) {
            .student-card {
                border: 2px solid #000;
            }

            .btnn {
                border: 2px solid #000;
            }
        }

        /* Reduced Motion */
        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>

<body>
    <?php require_once 'nav.php'; ?>
    <div class="container">
        <div class="header">
            <h2><i class="fas fa-users"></i> Staff Management</h2>
            <p>Manage and download staff records with ease</p>
        </div>

        <div class="controls">
            <div class="controls-row">
                <div class="search-box">
                    <input type="text" id="searchInput" placeholder="Search by name, ID, or email...">
                    <i class="fas fa-search"></i>
                </div>
                <select class="filter-select" id="departmentFilter">
                    <option value="">All Departments</option>
                </select>
                <select class="filter-select" id="designationFilter">
                    <option value="">All Designations</option>
                </select>
                <select class="filter-select" id="employmentTypeFilter">
                    <option value="">All Employment Types</option>
                </select>
                <button class="btnn btnn-primary" onclick="loadStaff()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>

            <div class="bulk-actions">
                <label>
                    <input type="checkbox" id="selectAll" class="select-checkbox"> Select All
                </label>
                <button class="btnn btnn-success" onclick="downloadSelectedPDFs()">
                    <i class="fas fa-download"></i> Download Selected (<span id="selectedCount">0</span>)
                </button>
                <button class="btnn btnn-info" onclick="exportToExcel()">
                    <i class="fas fa-file-excel"></i> Export to Excel
                </button>
                <div class="student-count">
                    Total Staff: <span id="totalCount">0</span>
                </div>
            </div>
        </div>

        <div id="staffContainer">
            <div class="loading">
                <i class="fas fa-spinner"></i>
                <p>Loading staff...</p>
            </div>
        </div>
    </div>

    <!-- Modal for bulk download progress -->
    <div id="downloadModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-download"></i> Downloading PDFs</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div id="downloadProgress">
                <p>Preparing downloads...</p>
                <div class="progress-bar">
                    <div class="progress-fill" id="progressFill"></div>
                </div>
                <p id="progressText">0%</p>
            </div>
        </div>
    </div>

    <!-- Modal for viewing staff details -->
    <div id="viewStaffModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3><i class="fas fa-user-tie"></i> Staff Details</h3>
                <span class="close" onclick="closeViewModal()">&times;</span>
            </div>
            <div class="modal-body" id="staffDetailsContent">
                <!-- Staff details will be loaded here -->
            </div>
            <div class="modal-footer">
                <button class="btnn btnn-primary" onclick="downloadStaffPDF(currentViewingStaffId)">
                    <i class="fas fa-download"></i> Download PDF
                </button>
                <button class="btnn" onclick="closeViewModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        let staff = [];
        let selectedStaff = [];

        // Load staff on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadStaff();
            setupEventListeners();
        });

        function setupEventListeners() {
            document.getElementById('searchInput').addEventListener('input', filterStaff);
            document.getElementById('departmentFilter').addEventListener('change', filterStaff);
            document.getElementById('designationFilter').addEventListener('change', filterStaff);
            document.getElementById('employmentTypeFilter').addEventListener('change', filterStaff);
            document.getElementById('selectAll').addEventListener('change', toggleSelectAll);
        }

        async function loadStaff() {
            try {
                const response = await fetch('get_staff_details.php');
                const data = await response.json();

                if (data.success) {
                    staff = data.staff;
                    populateFilters();
                    displayStaff(staff);
                    updateCounts();
                } else {
                    showToast('Error loading staff: ' + data.message, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Error loading staff', 'error');
            }
        }

        function populateFilters() {
            const departmentFilter = document.getElementById('departmentFilter');
            const designationFilter = document.getElementById('designationFilter');
            const employmentTypeFilter = document.getElementById('employmentTypeFilter');

            const departments = [...new Set(staff.map(s => s.department))].sort();
            const designations = [...new Set(staff.map(s => s.designation))].sort();
            const employmentTypes = [...new Set(staff.map(s => s.employment_type))].sort();

            departmentFilter.innerHTML = '<option value="">All Departments</option>';
            designationFilter.innerHTML = '<option value="">All Designations</option>';
            employmentTypeFilter.innerHTML = '<option value="">All Employment Types</option>';

            departments.forEach(dept => {
                departmentFilter.innerHTML += `<option value="${dept}">${capitalizeFirst(dept)}</option>`;
            });

            designations.forEach(designation => {
                designationFilter.innerHTML += `<option value="${designation}">${capitalizeFirst(designation)}</option>`;
            });

            employmentTypes.forEach(type => {
                employmentTypeFilter.innerHTML += `<option value="${type}">${capitalizeFirst(type.replace('_', ' '))}</option>`;
            });
        }

        function displayStaff(staffToShow) {
            const container = document.getElementById('staffContainer');

            if (staffToShow.length === 0) {
                container.innerHTML = `
                    <div class="loading">
                        <i class="fas fa-search"></i>
                        <p>No staff found matching your criteria</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = `
                <div class="students-grid">
                    ${staffToShow.map(staffMember => createStaffCard(staffMember)).join('')}
                </div>
            `;
        }

        function createStaffCard(staffMember) {
            return `
                <div class="student-card" data-staff-id="${staffMember.id}">
                    <div class="card-header">
                        <div class="student-info">
                            <div class="student-name">${staffMember.first_name} ${staffMember.last_name}</div>
                            <div class="student-id">ID: ${staffMember.staff_id}</div>
                        </div>
                        <input type="checkbox" class="select-checkbox staff-checkbox" 
                               data-staff-id="${staffMember.id}" onchange="toggleStaffSelection(this)">
                    </div>
                    
                    <div class="student-details">
                        <div class="detail-item">
                            <i class="fas fa-briefcase"></i>
                            <span class="detail-label">Designation:</span>
                            <span class="detail-value">${capitalizeFirst(staffMember.designation)}</span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-building"></i>
                            <span class="detail-label">Department:</span>
                            <span class="detail-value">${capitalizeFirst(staffMember.department)}</span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-venus-mars"></i>
                            <span class="detail-label">Gender:</span>
                            <span class="detail-value">${capitalizeFirst(staffMember.gender)}</span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-phone"></i>
                            <span class="detail-label">Phone:</span>
                            <span class="detail-value">${staffMember.phone_number}</span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-envelope"></i>
                            <span class="detail-label">Email:</span>
                            <span class="detail-value">${staffMember.email}</span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-calendar-plus"></i>
                            <span class="detail-label">Joined:</span>
                            <span class="detail-value">${formatDate(staffMember.joining_date)}</span>
                        </div>
                    </div>
                    
                    <div class="card-actions">
                        <button class="btnn btnn-info" onclick="viewStaff(${staffMember.id})">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <button class="btnn btnn-danger" onclick="downloadStaffPDF(${staffMember.id})">
                            <i class="fas fa-download"></i> Download
                        </button>
                    </div>
                </div>
            `;
        }

        function filterStaff() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const departmentFilter = document.getElementById('departmentFilter').value;
            const designationFilter = document.getElementById('designationFilter').value;
            const employmentTypeFilter = document.getElementById('employmentTypeFilter').value;

            const filtered = staff.filter(staffMember => {
                const matchesSearch =
                    staffMember.first_name.toLowerCase().includes(searchTerm) ||
                    staffMember.last_name.toLowerCase().includes(searchTerm) ||
                    staffMember.staff_id.toLowerCase().includes(searchTerm) ||
                    staffMember.email.toLowerCase().includes(searchTerm);

                const matchesDepartment = !departmentFilter || staffMember.department === departmentFilter;
                const matchesDesignation = !designationFilter || staffMember.designation === designationFilter;
                const matchesEmploymentType = !employmentTypeFilter || staffMember.employment_type === employmentTypeFilter;

                return matchesSearch && matchesDepartment && matchesDesignation && matchesEmploymentType;
            });

            displayStaff(filtered);
            updateCounts();
        }

        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.staff-checkbox');

            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
                toggleStaffSelection(checkbox);
            });
        }

        function toggleStaffSelection(checkbox) {
            const staffId = parseInt(checkbox.dataset.staffId);
            const card = checkbox.closest('.student-card');

            if (checkbox.checked) {
                if (!selectedStaff.includes(staffId)) {
                    selectedStaff.push(staffId);
                }
                card.classList.add('selected');
            } else {
                selectedStaff = selectedStaff.filter(id => id !== staffId);
                card.classList.remove('selected');
            }

            updateCounts();
        }

        function updateCounts() {
            document.getElementById('totalCount').textContent = staff.length;
            document.getElementById('selectedCount').textContent = selectedStaff.length;
        }

        async function downloadStaffPDF(staffId) {
            const staffMember = staff.find(s => s.id === staffId);
            if (!staffMember) return;

            try {
                const {
                    jsPDF
                } = window.jspdf;
                const doc = new jsPDF();

                generateStaffPDFContent(doc, staffMember);
                doc.save(`${staffMember.first_name}_${staffMember.last_name}_${staffMember.staff_id}.pdf`);

                showToast('PDF downloaded successfully!', 'success');
            } catch (error) {
                console.error('Error generating PDF:', error);
                showToast('Error generating PDF', 'error');
            }
        }

        async function downloadSelectedPDFs() {
            if (selectedStaff.length === 0) {
                showToast('Please select at least one staff member', 'error');
                return;
            }

            const modal = document.getElementById('downloadModal');
            modal.style.display = 'block';

            const progressFill = document.getElementById('progressFill');
            const progressText = document.getElementById('progressText');

            try {
                const {
                    jsPDF
                } = window.jspdf;

                for (let i = 0; i < selectedStaff.length; i++) {
                    const staffId = selectedStaff[i];
                    const staffMember = staff.find(s => s.id === staffId);

                    if (staffMember) {
                        const doc = new jsPDF();
                        generateStaffPDFContent(doc, staffMember);
                        doc.save(`${staffMember.first_name}_${staffMember.last_name}_${staffMember.staff_id}.pdf`);

                        const progress = ((i + 1) / selectedStaff.length) * 100;
                        progressFill.style.width = progress + '%';
                        progressText.textContent = Math.round(progress) + '%';

                        // Small delay to prevent browser from freezing
                        await new Promise(resolve => setTimeout(resolve, 100));
                    }
                }

                showToast(`${selectedStaff.length} PDFs downloaded successfully!`, 'success');
                closeModal();
            } catch (error) {
                console.error('Error generating PDFs:', error);
                showToast('Error generating PDFs', 'error');
                closeModal();
            }
        }

        function generateStaffPDFContent(doc, staffMember) {
            // Header
            doc.setFontSize(20);
            doc.setTextColor(45, 90, 61);
            doc.text('STAFF INFORMATION RECORD', 105, 20, {
                align: 'center'
            });

            // Staff Photo Placeholder
            doc.setFillColor(232, 245, 232);
            doc.rect(20, 35, 40, 50, 'F');
            doc.setFontSize(10);
            doc.setTextColor(100, 100, 100);
            doc.text('Photo', 40, 62, {
                align: 'center'
            });

            // Staff Name and ID
            doc.setFontSize(16);
            doc.setTextColor(45, 90, 61);
            doc.text(`${staffMember.first_name} ${staffMember.last_name}`, 70, 45);

            doc.setFontSize(12);
            doc.setTextColor(100, 100, 100);
            doc.text(`Staff ID: ${staffMember.staff_id}`, 70, 55);
            doc.text(`${capitalizeFirst(staffMember.designation)} - ${capitalizeFirst(staffMember.department)}`, 70, 65);

            // Personal Information
            let yPos = 100;
            doc.setFontSize(14);
            doc.setTextColor(45, 90, 61);
            doc.text('Personal Information', 20, yPos);

            yPos += 10;
            doc.setFontSize(10);
            doc.setTextColor(0, 0, 0);

            const personalInfo = [
                ['Date of Birth:', formatDate(staffMember.date_of_birth)],
                ['Gender:', capitalizeFirst(staffMember.gender)],
                ['Nationality:', staffMember.nationality],
                ['Phone Number:', staffMember.phone_number],
                ['Email:', staffMember.email],
                ['Address:', staffMember.address],
                ['Marital Status:', capitalizeFirst(staffMember.marital_status || 'Not specified')],
                ['National ID:', staffMember.national_id]
            ];

            personalInfo.forEach(([label, value]) => {
                doc.setFont(undefined, 'bold');
                doc.text(label, 20, yPos);
                doc.setFont(undefined, 'normal');
                doc.text(value, 70, yPos);
                yPos += 8;
            });

            // Employment Information
            yPos += 10;
            doc.setFontSize(14);
            doc.setTextColor(45, 90, 61);
            doc.text('Employment Information', 20, yPos);

            yPos += 10;
            doc.setFontSize(10);
            doc.setTextColor(0, 0, 0);

            const employmentInfo = [
                ['Joining Date:', formatDate(staffMember.joining_date)],
                ['Employment Type:', capitalizeFirst(staffMember.employment_type.replace('_', ' '))],
                ['Qualifications:', staffMember.qualifications],
                ['Experience:', `${staffMember.experience || 0} years`],
                ['TIN Number:', staffMember.tin_number || 'Not provided'],
                ['NSSF Number:', staffMember.nssf_number || 'Not provided']
            ];

            employmentInfo.forEach(([label, value]) => {
                doc.setFont(undefined, 'bold');
                doc.text(label, 20, yPos);
                doc.setFont(undefined, 'normal');
                doc.text(value, 70, yPos);
                yPos += 8;
            });

            // Footer
            doc.setFontSize(8);
            doc.setTextColor(100, 100, 100);
            doc.text(`Generated on: ${new Date().toLocaleString()}`, 20, 280);
            doc.text('School Pilot - Staff Management System', 105, 280, {
                align: 'center'
            });
        }

        let currentViewingStaffId = null;

        function viewStaff(staffId) {
            currentViewingStaffId = staffId;
            const staffMember = staff.find(s => s.id === staffId);
            if (!staffMember) return;

            const modal = document.getElementById('viewStaffModal');
            const content = document.getElementById('staffDetailsContent');

            // Create the detailed view content
            content.innerHTML = `
                <div class="student-detail-view">
                    <div class="detail-header">
                        <div class="student-photo-placeholder">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div class="student-main-info">
                            <h2>${staffMember.first_name} ${staffMember.last_name}</h2>
                            <p class="student-id">ID: ${staffMember.staff_id}</p>
                            <p class="student-class">${capitalizeFirst(staffMember.designation)} - ${capitalizeFirst(staffMember.department)}</p>
                        </div>
                    </div>
                    
                    <div class="detail-sections">
                        <div class="detail-section">
                            <h3><i class="fas fa-info-circle"></i> Personal Information</h3>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <span class="detail-label">Date of Birth:</span>
                                    <span class="detail-value">${formatDate(staffMember.date_of_birth)}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Gender:</span>
                                    <span class="detail-value">${capitalizeFirst(staffMember.gender)}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Nationality:</span>
                                    <span class="detail-value">${staffMember.nationality}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Marital Status:</span>
                                    <span class="detail-value">${capitalizeFirst(staffMember.marital_status || 'Not specified')}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Phone:</span>
                                    <span class="detail-value">${staffMember.phone_number}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Email:</span>
                                    <span class="detail-value">${staffMember.email}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">National ID:</span>
                                    <span class="detail-value">${staffMember.national_id}</span>
                                </div>
                                <div class="detail-item full-width">
                                    <span class="detail-label">Address:</span>
                                    <span class="detail-value">${staffMember.address}</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="detail-section">
                            <h3><i class="fas fa-briefcase"></i> Employment Information</h3>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <span class="detail-label">Joining Date:</span>
                                    <span class="detail-value">${formatDate(staffMember.joining_date)}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Employment Type:</span>
                                    <span class="detail-value">${capitalizeFirst(staffMember.employment_type.replace('_', ' '))}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Experience:</span>
                                    <span class="detail-value">${staffMember.experience || 0} years</span>
                                </div>
                                <div class="detail-item full-width">
                                    <span class="detail-label">Qualifications:</span>
                                    <span class="detail-value">${staffMember.qualifications}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">TIN Number:</span>
                                    <span class="detail-value">${staffMember.tin_number || 'Not provided'}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">NSSF Number:</span>
                                    <span class="detail-value">${staffMember.nssf_number || 'Not provided'}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            modal.style.display = 'block';
        }

        function closeViewModal() {
            document.getElementById('viewStaffModal').style.display = 'none';
        }

        function exportToExcel() {
            // Create CSV content
            const headers = ['Staff ID', 'First Name', 'Last Name', 'Date of Birth', 'Gender', 'Phone', 'Email', 'Designation', 'Department', 'Joining Date', 'Employment Type', 'Qualifications', 'Experience'];
            const csvContent = [
                headers.join(','),
                ...staff.map(staffMember => [
                    staffMember.staff_id,
                    staffMember.first_name,
                    staffMember.last_name,
                    staffMember.date_of_birth,
                    staffMember.gender,
                    staffMember.phone_number,
                    staffMember.email,
                    staffMember.designation,
                    staffMember.department,
                    staffMember.joining_date,
                    staffMember.employment_type,
                    staffMember.qualifications,
                    staffMember.experience || 0
                ].join(','))
            ].join('\n');

            const blob = new Blob([csvContent], {
                type: 'text/csv'
            });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'staff_export.csv';
            a.click();
            window.URL.revokeObjectURL(url);

            showToast('Staff exported to CSV successfully!', 'success');
        }

        function closeModal() {
            document.getElementById('downloadModal').style.display = 'none';
        }

        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i>
                ${message}
            `;

            document.body.appendChild(toast);

            setTimeout(() => toast.classList.add('show'), 100);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => document.body.removeChild(toast), 300);
            }, 3000);
        }

        function formatDate(dateString) {
            return new Date(dateString).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        }

        function capitalizeFirst(string) {
            if (!string) return '';
            return string.charAt(0).toUpperCase() + string.slice(1);
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const downloadModal = document.getElementById('downloadModal');
            const viewModal = document.getElementById('viewStaffModal');

            if (event.target === downloadModal) {
                closeModal();
            }
            if (event.target === viewModal) {
                closeViewModal();
            }
        }
    </script>
</body>

</html>