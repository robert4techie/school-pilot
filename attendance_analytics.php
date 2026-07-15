<?php

require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';

// CSRF Protection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
    $tracker->trackAction("Viewed students Attendance Analytics");

    // Sanitize inputs
    $date_from = filter_input(INPUT_GET, 'date_from', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: date('Y-m-01');
    $date_to = filter_input(INPUT_GET, 'date_to', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: date('Y-m-t');
    $selected_class = filter_input(INPUT_GET, 'class', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '';
    $selected_stream = filter_input(INPUT_GET, 'stream', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '';
    $selected_gender = filter_input(INPUT_GET, 'gender', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '';
    $selected_section = filter_input(INPUT_GET, 'section', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '';

    // Validate dates
    $date_from_obj = DateTime::createFromFormat('Y-m-d', $date_from);
    $date_to_obj = DateTime::createFromFormat('Y-m-d', $date_to);

    if (!$date_from_obj || !$date_to_obj) {
        throw new Exception("Invalid date format");
    }

    if ($date_from_obj > $date_to_obj) {
        throw new Exception("Start date cannot be after end date");
    }

    // Fetch filter options
    $classes_query = "SELECT DISTINCT current_class FROM students WHERE status = 'active' AND current_class IS NOT NULL ORDER BY current_class";
    $classes = $conn->query($classes_query);
    if (!$classes) {
        throw new Exception("Failed to fetch classes");
    }

    $streams_query = "SELECT DISTINCT stream FROM students WHERE status = 'active' AND stream IS NOT NULL ORDER BY stream";
    $streams = $conn->query($streams_query);
} catch (Exception $e) {
    error_log("Analytics Error: " . $e->getMessage());
    die("An error occurred loading the analytics. Please contact support.");
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Attendance Analytics</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        :root {
            --primary-color: #2e7d32;
            --primary-dark: #1b5e20;
            --primary-light: #a5d6a7;
            --primary-gradient: linear-gradient(135deg, #2e7d32 0%, #4caf50 100%);
            --present-color: #4caf50;
            --absent-color: #f44336;
            --late-color: #ff9800;
            --sick-color: #9c27b0;
            --at-risk-color: #d32f2f;
            --warning-color: #ffa726;
            --success-color: #66bb6a;
            --info-color: #42a5f5;
            --light-bg: #f4f6f9;
            --white: #ffffff;
            --border-color: #e0e0e0;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            --shadow-lg: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--light-bg);
            color: #333;
            padding: 10px;
        }

        /* Loader Overlay */
        .loader-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            transition: opacity 0.3s;
        }

        .loader-overlay.hidden {
            opacity: 0;
            pointer-events: none;
        }

        .loader {
            width: 60px;
            height: 60px;
            border: 5px solid var(--primary-light);
            border-top: 5px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        .loader-text {
            margin-top: 20px;
            font-size: 16px;
            color: var(--primary-color);
            font-weight: 600;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Container */
        .container {
            max-width: 100%;
            margin: 20px auto;
            margin-top: 60px;
        }

        /* Header */
        .header {
            background: var(--primary-gradient);
            color: white;
            padding: 25px;
            border-radius: 15px 15px 0 0;
            box-shadow: var(--shadow-lg);
        }

        .header h1 {
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .header p {
            opacity: 0.9;
            font-size: 0.95rem;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            padding: 25px;
            border-radius: 0 0 15px 15px;
            box-shadow: var(--shadow-lg);
            margin-bottom: 25px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .filter-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btnn {
            font-family: "Sen", sans-serif !important;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.95rem;
        }

        .btnn-primary {
            background: var(--primary-gradient);
            color: white;
        }

        .btnn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .btnn-secondary {
            background: #607d8b;
            color: white;
        }

        .btnn-export {
            background: #1976d2;
            color: white;
        }

        /* Tabs */
        .tabs {
            font-family: "Sen", sans-serif !important;
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            border-bottom: 2px solid var(--border-color);
            overflow-x: auto;
        }

        .tab {
            padding: 12px 24px;
            background: white;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: #666;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }

        .tab:hover {
            background: #f8f9fa;
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .kpi-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary-color);
            transition: transform 0.3s;
        }

        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .kpi-label {
            font-size: 0.85rem;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .kpi-value {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--primary-dark);
        }

        .kpi-change {
            font-size: 0.85rem;
            margin-top: 5px;
        }

        .kpi-change.positive {
            color: var(--success-color);
        }

        .kpi-change.negative {
            color: var(--at-risk-color);
        }

        /* Chart Cards */
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: var(--shadow);
        }

        .chart-card.full-width {
            grid-column: 1 / -1;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
        }

        .chart-container {
            position: relative;
            height: 350px;
        }

        .chart-container.large {
            height: 450px;
        }

        /* At-Risk Table */
        .at-risk-section {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
        }

        .alert-banner {
            background: #fff3e0;
            border-left: 4px solid var(--warning-color);
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
        }

        .alert-banner.critical {
            background: #ffebee;
            border-left-color: var(--at-risk-color);
        }

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table th {
            background: var(--light-bg);
            font-weight: 600;
            color: var(--primary-dark);
            text-transform: uppercase;
            font-size: 0.85rem;
        }

        .data-table tbody tr:hover {
            background: #f8f9fa;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge-danger {
            background: #ffebee;
            color: var(--at-risk-color);
        }

        .badge-warning {
            background: #fff3e0;
            color: var(--warning-color);
        }

        .badge-success {
            background: #e8f5e9;
            color: var(--success-color);
        }

        .student-link {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }

        .student-link:hover {
            text-decoration: underline;
        }

        /* Message */
        .message {
            position: fixed;
            top: 80px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            box-shadow: var(--shadow-lg);
            transform: translateX(400px);
            transition: transform 0.3s;
            z-index: 1000;
        }

        .message.show {
            transform: translateX(0);
        }

        .message.success {
            background: var(--success-color);
        }

        .message.error {
            background: var(--at-risk-color);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .chart-grid {
                grid-template-columns: 1fr;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }
        }

        .quick-search-section {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 25px;
        }

        .section-title {
            color: var(--primary-color);
            margin-bottom: 15px;
            font-size: 1.2rem;
        }

        .search-container {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .search-input {
            flex: 1;
            padding: 12px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .search-input-small {
            padding: 10px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.9rem;
            min-width: 250px;
        }

        .search-results {
            margin-top: 15px;
            max-height: 300px;
            overflow-y: auto;
        }

        .search-result-item {
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .search-result-item:hover {
            background: var(--light-bg);
            border-color: var(--primary-color);
        }

        .student-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .student-name {
            font-weight: 600;
            color: var(--primary-dark);
        }

        .student-details {
            font-size: 0.85rem;
            color: #666;
        }

        .students-section {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: var(--shadow);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .view-profile-btn {
            font-family: "Sen", sans-serif !important;
            padding: 6px 12px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s;
        }

        .view-profile-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .attendance-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .rate-excellent {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .rate-good {
            background: #fff3e0;
            color: #f57c00;
        }

        .rate-poor {
            background: #ffebee;
            color: #c62828;
        }
    </style>
</head>

<body>
    <?php if (file_exists('nav.php')) require_once "nav.php"; ?>

    <div class="loader-overlay" id="loader">
        <div class="loader"></div>
        <div class="loader-text">Loading Analytics...</div>
    </div>

    <div class="container">
        <div class="header">
            <h1>Advanced Attendance Analytics</h1>
            <p>Real-time insights and predictive attendance analysis</p>
        </div>

        <div class="filter-section">
            <form id="filterForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div class="filter-grid">
                    <div class="form-group">
                        <label for="date_from">From Date</label>
                        <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="date_to">To Date</label>
                        <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="class">Class</label>
                        <select id="class" name="class">
                            <option value="">All Classes</option>
                            <?php while ($class = $classes->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($class['current_class']) ?>"
                                    <?= $selected_class === $class['current_class'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['current_class']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="stream">Stream</label>
                        <select id="stream" name="stream">
                            <option value="">All Streams</option>
                            <?php while ($stream = $streams->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($stream['stream']) ?>"
                                    <?= $selected_stream === $stream['stream'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($stream['stream']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="gender">Gender</label>
                        <select id="gender" name="gender">
                            <option value="">All Genders</option>
                            <option value="Male" <?= $selected_gender === 'Male' ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= $selected_gender === 'Female' ? 'selected' : '' ?>>Female</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="section">Student Type</label>
                        <select id="section" name="section">
                            <option value="">All Students</option>
                            <option value="Day" <?= $selected_section === 'Day' ? 'selected' : '' ?>>Day Students</option>
                            <option value="Boarding" <?= $selected_section === 'Boarding' ? 'selected' : '' ?>>Boarding Students</option>
                        </select>
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btnn btnn-primary" id="generatebtnn">
                        Generate Analytics
                    </button>
                    <button type="button" class="btnn btnn-secondary" id="resetbtnn">
                        Reset Filters
                    </button>
                    <button type="button" class="btnn btnn-export" id="exportPdfbtnn">
                        Export PDF
                    </button>
                    <button type="button" class="btnn btnn-export" id="exportExcelbtnn">
                        Export Excel
                    </button>
                </div>
            </form>
        </div>

        <!-- 1. QUICK STUDENT SEARCH (Add after filter section, before tabs) -->
        <div class="quick-search-section">
            <h3 class="section-title"> View Individual Student Profile</h3>
            <div class="search-container">
                <input
                    type="text"
                    id="studentSearch"
                    placeholder="Search by Student ID or Name..."
                    class="search-input">
                <button onclick="openSelectedStudent()" class="btnn btnn-primary">
                    View Profile →
                </button>
            </div>
            <div id="searchResults" class="search-results"></div>
        </div>

        <div class="tabs">
            <button class="tab active" data-tab="overview">Overview</button>
            <button class="tab" data-tab="trends">Trends</button>
            <button class="tab" data-tab="comparisons">Comparisons</button>
            <button class="tab" data-tab="at-risk">At-Risk Students</button>
            <button class="tab" data-tab="patterns">Patterns</button>
            <button class="tab" data-tab="all-students">All Students</button> <!-- NEW TAB -->
        </div>


        <!-- Overview Tab -->
        <div class="tab-content active" id="overview-tab">
            <div class="kpi-grid" id="kpiGrid">
                <!-- KPIs will be loaded here -->
            </div>

            <div class="chart-grid">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Overall Attendance Distribution</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="pieChart"></canvas>
                    </div>
                </div>

                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Attendance Rate Gauge</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="gaugeChart"></canvas>
                    </div>
                </div>

                <div class="chart-card full-width">
                    <div class="chart-header">
                        <h3 class="chart-title">Daily Attendance Trends</h3>
                    </div>
                    <div class="chart-container large">
                        <canvas id="lineChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Trends Tab -->
        <div class="tab-content" id="trends-tab">
            <div class="chart-grid">
                <div class="chart-card full-width">
                    <div class="chart-header">
                        <h3 class="chart-title">Weekly Attendance Trends</h3>
                    </div>
                    <div class="chart-container large">
                        <canvas id="weeklyTrendChart"></canvas>
                    </div>
                </div>

                <div class="chart-card full-width">
                    <div class="chart-header">
                        <h3 class="chart-title">Monthly Comparison</h3>
                    </div>
                    <div class="chart-container large">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Comparisons Tab -->
        <div class="tab-content" id="comparisons-tab">
            <div class="chart-grid">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Gender Comparison</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="genderChart"></canvas>
                    </div>
                </div>

                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Day vs Boarding Students</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="sectionChart"></canvas>
                    </div>
                </div>

                <div class="chart-card full-width">
                    <div class="chart-header">
                        <h3 class="chart-title">Class Performance</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="classChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- At-Risk Tab -->
        <div class="tab-content" id="at-risk-tab">
            <div class="at-risk-section" id="atRiskSection">
                <!-- At-risk students will be loaded here -->
            </div>
        </div>

        <!-- Patterns Tab -->
        <div class="tab-content" id="patterns-tab">
            <div class="chart-grid">
                <div class="chart-card full-width">
                    <div class="chart-header">
                        <h3 class="chart-title">Attendance by Day of Week</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="dayOfWeekChart"></canvas>
                    </div>
                </div>

                <div class="chart-card full-width">
                    <div class="chart-header">
                        <h3 class="chart-title">Heatmap Calendar</h3>
                    </div>
                    <div id="heatmapContainer" style="padding: 20px;">
                        <!-- Heatmap will be generated here -->
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-content" id="all-students-tab">
            <div class="students-section">
                <div class="section-header">
                    <h3 class="chart-title">All Students Directory</h3>
                    <input
                        type="text"
                        id="tableSearch"
                        placeholder="Filter students..."
                        class="search-input-small">
                </div>
                <div class="table-container">
                    <table class="data-table" id="allStudentsTable">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Class</th>
                                <th>Stream</th>
                                <th>Gender</th>
                                <th>Section</th>
                                <th>Attendance Rate</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="allStudentsBody">
                            <tr>
                                <td colspan="8" style="text-align: center;">Loading students...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <div id="message" class="message"></div>

    <script>
        // Global variable to store all students
        let allStudentsData = [];
        let selectedStudentId = null;
        const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
        let charts = {};

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            setupEventListeners();
            loadAnalytics();
            loadAllStudents();
        });

        function setupEventListeners() {
            // Tab switching
            document.querySelectorAll('.tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabName = this.dataset.tab;
                    switchTab(tabName);
                });
            });

            // Form submission
            document.getElementById('filterForm').addEventListener('submit', function(e) {
                e.preventDefault();
                loadAnalytics();
            });

            // Reset button
            document.getElementById('resetbtnn').addEventListener('click', function() {
                document.getElementById('filterForm').reset();
                loadAnalytics();
            });

            // Export buttons
            document.getElementById('exportPdfbtnn').addEventListener('click', exportToPDF);
            document.getElementById('exportExcelbtnn').addEventListener('click', exportToExcel);
        }

        function switchTab(tabName) {
            // Update tab buttons
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');

            // Update tab content
            document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
            document.getElementById(`${tabName}-tab`).classList.add('active');
        }

        async function loadAnalytics() {
            showLoader();

            try {
                const formData = new FormData(document.getElementById('filterForm'));
                const params = new URLSearchParams(formData);

                const response = await fetch(`api/get_analytics_data.php?${params}`);

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();

                console.log('API Response:', data); // Debug log

                if (!data.success) {
                    throw new Error(data.message || 'Failed to load analytics');
                }

                // Check if we have the required data
                if (!data.kpis) {
                    throw new Error('Missing KPIs data from server');
                }

                renderKPIs(data.kpis);
                renderCharts(data);
                renderAtRiskStudents(data.at_risk_students);

                hideLoader();
                showMessage('Analytics loaded successfully!', 'success');

            } catch (error) {
                console.error('Error loading analytics:', error);
                hideLoader();
                showMessage('Failed to load analytics. Please try again.', 'error');

                // Show error details in console for debugging
                console.error('Full error details:', error.message);
            }
        }

        function renderKPIs(kpis) {
            const container = document.getElementById('kpiGrid');
            container.innerHTML = `
                <div class="kpi-card">
                    <div class="kpi-label">Total Students</div>
                    <div class="kpi-value">${kpis.total_students}</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Attendance Rate</div>
                    <div class="kpi-value">${kpis.attendance_rate}%</div>
                    <div class="kpi-change ${kpis.rate_change >= 0 ? 'positive' : 'negative'}">
                        ${kpis.rate_change >= 0 ? '↑' : '↓'} ${Math.abs(kpis.rate_change)}% from last period
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Present</div>
                    <div class="kpi-value" style="color: var(--present-color);">${kpis.present}</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Absent</div>
                    <div class="kpi-value" style="color: var(--absent-color);">${kpis.absent}</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Late</div>
                    <div class="kpi-value" style="color: var(--late-color);">${kpis.late}</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">At-Risk Students</div>
                    <div class="kpi-value" style="color: var(--at-risk-color);">${kpis.at_risk}</div>
                </div>
            `;
        }

        function renderCharts(data) {
            // Destroy existing charts
            Object.values(charts).forEach(chart => chart.destroy());
            charts = {};

            // Pie Chart
            charts.pie = new Chart(document.getElementById('pieChart'), {
                type: 'doughnut',
                data: {
                    labels: ['Present', 'Absent', 'Late', 'Sick'],
                    datasets: [{
                        data: [
                            data.status_distribution.present,
                            data.status_distribution.absent,
                            data.status_distribution.late,
                            data.status_distribution.sick
                        ],
                        backgroundColor: [
                            'rgba(76, 175, 80, 0.8)',
                            'rgba(244, 67, 54, 0.8)',
                            'rgba(255, 152, 0, 0.8)',
                            'rgba(156, 39, 176, 0.8)'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // Gauge Chart (Attendance Rate)
            charts.gauge = new Chart(document.getElementById('gaugeChart'), {
                type: 'doughnut',
                data: {
                    datasets: [{
                        data: [data.kpis.attendance_rate, 100 - data.kpis.attendance_rate],
                        backgroundColor: [
                            data.kpis.attendance_rate >= 90 ? 'rgba(76, 175, 80, 0.8)' :
                            data.kpis.attendance_rate >= 75 ? 'rgba(255, 152, 0, 0.8)' :
                            'rgba(244, 67, 54, 0.8)',
                            'rgba(224, 224, 224, 0.3)'
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    circumference: 180,
                    rotation: 270,
                    cutout: '75%',
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            enabled: false
                        }
                    }
                },
                plugins: [{
                    afterDraw: (chart) => {
                        const ctx = chart.ctx;
                        const centerX = (chart.chartArea.left + chart.chartArea.right) / 2;
                        const centerY = chart.chartArea.bottom;

                        ctx.save();
                        ctx.font = 'bold 48px Arial';
                        ctx.fillStyle = '#2e7d32';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'bottom';
                        ctx.fillText(`${data.kpis.attendance_rate}%`, centerX, centerY - 20);

                        ctx.font = '16px Arial';
                        ctx.fillStyle = '#666';
                        ctx.fillText('Attendance Rate', centerX, centerY);
                        ctx.restore();
                    }
                }]
            });

            // Line Chart
            charts.line = new Chart(document.getElementById('lineChart'), {
                type: 'line',
                data: {
                    labels: data.daily_trends.map(d => d.date),
                    datasets: [{
                            label: 'Present',
                            data: data.daily_trends.map(d => d.present),
                            borderColor: 'rgba(76, 175, 80, 1)',
                            backgroundColor: 'rgba(76, 175, 80, 0.1)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Absent',
                            data: data.daily_trends.map(d => d.absent),
                            borderColor: 'rgba(244, 67, 54, 1)',
                            backgroundColor: 'rgba(244, 67, 54, 0.1)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Late',
                            data: data.daily_trends.map(d => d.late),
                            borderColor: 'rgba(255, 152, 0, 1)',
                            backgroundColor: 'rgba(255, 152, 0, 0.1)',
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            position: 'top'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });

            // Weekly Trend Chart
            charts.weekly = new Chart(document.getElementById('weeklyTrendChart'), {
                type: 'bar',
                data: {
                    labels: data.weekly_trends.map(w => `Week ${w.week}`),
                    datasets: [{
                        label: 'Attendance Rate %',
                        data: data.weekly_trends.map(w => w.rate),
                        backgroundColor: data.weekly_trends.map(w =>
                            w.rate >= 90 ? 'rgba(76, 175, 80, 0.8)' :
                            w.rate >= 75 ? 'rgba(255, 152, 0, 0.8)' :
                            'rgba(244, 67, 54, 0.8)'
                        )
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100
                        }
                    }
                }
            });

            // Monthly Chart
            charts.monthly = new Chart(document.getElementById('monthlyChart'), {
                type: 'line',
                data: {
                    labels: data.monthly_comparison.map(m => m.month),
                    datasets: [{
                            label: 'Present',
                            data: data.monthly_comparison.map(m => m.present),
                            borderColor: 'rgba(76, 175, 80, 1)',
                            backgroundColor: 'rgba(76, 175, 80, 0.2)',
                            fill: true
                        },
                        {
                            label: 'Absent',
                            data: data.monthly_comparison.map(m => m.absent),
                            borderColor: 'rgba(244, 67, 54, 1)',
                            backgroundColor: 'rgba(244, 67, 54, 0.2)',
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });

            // Gender Comparison
            charts.gender = new Chart(document.getElementById('genderChart'), {
                type: 'bar',
                data: {
                    labels: ['Male', 'Female'],
                    datasets: [{
                            label: 'Present',
                            data: [data.gender_comparison.male.present, data.gender_comparison.female.present],
                            backgroundColor: 'rgba(76, 175, 80, 0.8)'
                        },
                        {
                            label: 'Absent',
                            data: [data.gender_comparison.male.absent, data.gender_comparison.female.absent],
                            backgroundColor: 'rgba(244, 67, 54, 0.8)'
                        },
                        {
                            label: 'Late',
                            data: [data.gender_comparison.male.late, data.gender_comparison.female.late],
                            backgroundColor: 'rgba(255, 152, 0, 0.8)'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            stacked: true
                        },
                        y: {
                            stacked: true
                        }
                    }
                }
            });

            // Section (Day/Boarding) Comparison
            charts.section = new Chart(document.getElementById('sectionChart'), {
                type: 'pie',
                data: {
                    labels: ['Day - Present', 'Day - Absent', 'Boarding - Present', 'Boarding - Absent'],
                    datasets: [{
                        data: [
                            data.section_comparison.day.present,
                            data.section_comparison.day.absent,
                            data.section_comparison.boarding.present,
                            data.section_comparison.boarding.absent
                        ],
                        backgroundColor: [
                            'rgba(76, 175, 80, 0.8)',
                            'rgba(244, 67, 54, 0.8)',
                            'rgba(129, 199, 132, 0.8)',
                            'rgba(229, 115, 115, 0.8)'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });

            // Class Performance
            charts.class = new Chart(document.getElementById('classChart'), {
                type: 'bar',
                data: {
                    labels: data.class_performance.map(c => c.class_name),
                    datasets: [{
                        label: 'Attendance Rate %',
                        data: data.class_performance.map(c => c.rate),
                        backgroundColor: 'rgba(46, 125, 50, 0.8)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    scales: {
                        x: {
                            beginAtZero: true,
                            max: 100
                        }
                    }
                }
            });

            // Day of Week Pattern
            charts.dayOfWeek = new Chart(document.getElementById('dayOfWeekChart'), {
                type: 'bar',
                data: {
                    labels: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
                    datasets: [{
                        label: 'Average Attendance',
                        data: data.day_of_week_pattern,
                        backgroundColor: [
                            'rgba(244, 67, 54, 0.8)',
                            'rgba(233, 30, 99, 0.8)',
                            'rgba(156, 39, 176, 0.8)',
                            'rgba(103, 58, 183, 0.8)',
                            'rgba(63, 81, 181, 0.8)',
                            'rgba(33, 150, 243, 0.8)',
                            'rgba(76, 175, 80, 0.8)'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });

            // Generate Heatmap
            generateHeatmap(data.heatmap_data);
        }

        function renderAtRiskStudents(students) {
            const container = document.getElementById('atRiskSection');

            if (!students || students.length === 0) {
                container.innerHTML = `
                    <div class="alert-banner">
                        <strong>✅ Great News!</strong> No students are currently at risk.
                    </div>
                `;
                return;
            }

            const criticalStudents = students.filter(s => s.consecutive_absences >= 5);
            const warningStudents = students.filter(s => s.consecutive_absences >= 3 && s.consecutive_absences < 5);

            let html = '';

            if (criticalStudents.length > 0) {
                html += `
                    <div class="alert-banner critical">
                        <strong>🚨 Critical Alert:</strong> ${criticalStudents.length} student(s) with 5+ consecutive absences
                    </div>
                `;
            }

            if (warningStudents.length > 0) {
                html += `
                    <div class="alert-banner">
                        <strong>⚠️ Warning:</strong> ${warningStudents.length} student(s) with 3-4 consecutive absences
                    </div>
                `;
            }

            html += `
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Class</th>
                                <th>Consecutive Absences</th>
                                <th>Total Absences</th>
                                <th>Attendance Rate</th>
                                <th>Last Reason</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            students.forEach(student => {
                const badgeClass = student.consecutive_absences >= 5 ? 'badge-danger' : 'badge-warning';
                const statusText = student.consecutive_absences >= 5 ? 'Critical' : 'Warning';

                html += `
                    <tr>
                        <td>${escapeHtml(student.student_id)}</td>
                        <td>
                            <a href="student_profile.php?id=${escapeHtml(student.student_id)}" class="student-link" target="_blank">
                                ${escapeHtml(student.name)}
                            </a>
                        </td>
                        <td>${escapeHtml(student.class)}</td>
                        <td><strong>${student.consecutive_absences}</strong></td>
                        <td>${student.total_absences}</td>
                        <td>${student.attendance_rate}%</td>
                        <td>${escapeHtml(student.last_reason || 'N/A')}</td>
                        <td><span class="badge ${badgeClass}">${statusText}</span></td>
                        <td>
                            <a href="student_profile.php?id=${escapeHtml(student.student_id)}" class="student-link" target="_blank">
                                View Details →
                            </a>
                        </td>
                    </tr>
                `;
            });

            html += `
                        </tbody>
                    </table>
                </div>
            `;

            container.innerHTML = html;
        }

        // Add this to your attendance_analytics.php JavaScript section
        // Replace the existing generateHeatmap function with this comprehensive implementation

        function generateHeatmap(data) {
            const container = document.getElementById('heatmapContainer');

            if (!data || data.length === 0) {
                container.innerHTML = `
            <div style="text-align: center; padding: 40px; color: #666;">
                <p style="font-size: 1.1rem; margin-bottom: 10px;">📅 No attendance data available for heatmap</p>
                <p style="font-size: 0.9rem;">Select a date range with attendance records to view the calendar.</p>
            </div>
        `;
                return;
            }

            // Group data by date
            const dateMap = {};
            data.forEach(record => {
                const date = record.date;
                if (!dateMap[date]) {
                    dateMap[date] = {
                        present: 0,
                        absent: 0,
                        late: 0,
                        sick: 0,
                        total: 0
                    };
                }
                dateMap[date][record.status]++;
                dateMap[date].total++;
            });

            // Calculate attendance rate for each date
            Object.keys(dateMap).forEach(date => {
                const day = dateMap[date];
                day.rate = day.total > 0 ? Math.round((day.present / day.total) * 100) : 0;
            });

            // Get date range
            const dates = Object.keys(dateMap).sort();
            if (dates.length === 0) {
                container.innerHTML = '<p style="color: #666;">No data to display</p>';
                return;
            }

            const firstDate = new Date(dates[0]);
            const lastDate = new Date(dates[dates.length - 1]);

            // Generate months between first and last date
            const months = [];
            let currentDate = new Date(firstDate.getFullYear(), firstDate.getMonth(), 1);
            const endDate = new Date(lastDate.getFullYear(), lastDate.getMonth(), 1);

            while (currentDate <= endDate) {
                months.push(new Date(currentDate));
                currentDate.setMonth(currentDate.getMonth() + 1);
            }

            // Build HTML
            let html = `
        <div style="margin-bottom: 20px;">
            <h4 style="color: var(--primary-dark); margin-bottom: 15px;">Attendance Calendar Heatmap</h4>
            <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap; margin-bottom: 20px;">
                <div style="display: flex; gap: 10px; align-items: center;">
                    <span style="font-size: 0.9rem; color: #666;">Legend:</span>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <div style="width: 20px; height: 20px; background: #c8e6c9; border-radius: 3px;"></div>
                        <span style="font-size: 0.85rem;">90-100%</span>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <div style="width: 20px; height: 20px; background: #fff9c4; border-radius: 3px;"></div>
                        <span style="font-size: 0.85rem;">75-89%</span>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <div style="width: 20px; height: 20px; background: #ffccbc; border-radius: 3px;"></div>
                        <span style="font-size: 0.85rem;">50-74%</span>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <div style="width: 20px; height: 20px; background: #ffcdd2; border-radius: 3px;"></div>
                        <span style="font-size: 0.85rem;">&lt;50%</span>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <div style="width: 20px; height: 20px; background: #f5f5f5; border-radius: 3px; border: 1px solid #e0e0e0;"></div>
                        <span style="font-size: 0.85rem;">No data</span>
                    </div>
                </div>
            </div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 30px;">
    `;

            // Generate calendar for each month
            months.forEach(monthDate => {
                const year = monthDate.getFullYear();
                const month = monthDate.getMonth();
                const monthName = monthDate.toLocaleString('default', {
                    month: 'long',
                    year: 'numeric'
                });

                html += `
            <div style="background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                <h5 style="text-align: center; color: var(--primary-dark); margin-bottom: 12px; font-size: 0.95rem;">${monthName}</h5>
                <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px;">
        `;

                // Add day headers
                const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                dayNames.forEach(day => {
                    html += `
                <div style="text-align: center; font-size: 0.7rem; font-weight: 600; color: #666; padding: 4px;">
                    ${day}
                </div>
            `;
                });

                // Get first day of month and number of days
                const firstDay = new Date(year, month, 1).getDay();
                const daysInMonth = new Date(year, month + 1, 0).getDate();

                // Add empty cells for days before month starts
                for (let i = 0; i < firstDay; i++) {
                    html += '<div style="padding: 8px;"></div>';
                }

                // Add days of month
                for (let day = 1; day <= daysInMonth; day++) {
                    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                    const dayData = dateMap[dateStr];

                    let bgColor = '#f5f5f5';
                    let borderColor = '#e0e0e0';
                    let tooltip = 'No attendance data';

                    if (dayData) {
                        const rate = dayData.rate;
                        if (rate >= 90) {
                            bgColor = '#c8e6c9'; // Light green
                        } else if (rate >= 75) {
                            bgColor = '#fff9c4'; // Light yellow
                        } else if (rate >= 50) {
                            bgColor = '#ffccbc'; // Light orange
                        } else {
                            bgColor = '#ffcdd2'; // Light red
                        }
                        borderColor = 'transparent';
                        tooltip = `${dateStr}
Rate: ${rate}%
Present: ${dayData.present}
Absent: ${dayData.absent}
Late: ${dayData.late}
Sick: ${dayData.sick}
Total: ${dayData.total}`;
                    }

                    html += `
                <div 
                    title="${tooltip.replace(/\n/g, '&#10;')}"
                    style="
                        background: ${bgColor};
                        border: 1px solid ${borderColor};
                        border-radius: 4px;
                        padding: 8px;
                        text-align: center;
                        font-size: 0.8rem;
                        font-weight: 500;
                        cursor: ${dayData ? 'pointer' : 'default'};
                        transition: transform 0.2s, box-shadow 0.2s;
                    "
                    onmouseover="this.style.transform='scale(1.1)'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.15)'; this.style.zIndex='10';"
                    onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='none'; this.style.zIndex='1';"
                    onclick="showDayDetails('${dateStr}', ${dayData ? JSON.stringify(dayData).replace(/"/g, '&quot;') : 'null'})"
                >
                    ${day}
                </div>
            `;
                }

                html += `
                </div>
            </div>
        `;
            });

            html += '</div>';

            // Add summary statistics
            const totalDays = Object.keys(dateMap).length;
            const avgRate = totalDays > 0 ?
                Math.round(Object.values(dateMap).reduce((sum, day) => sum + day.rate, 0) / totalDays) : 0;

            const excellentDays = Object.values(dateMap).filter(d => d.rate >= 90).length;
            const goodDays = Object.values(dateMap).filter(d => d.rate >= 75 && d.rate < 90).length;
            const concernDays = Object.values(dateMap).filter(d => d.rate < 75).length;

            html += `
        <div style="margin-top: 30px; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
            <h5 style="color: var(--primary-dark); margin-bottom: 15px;">Period Summary</h5>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                <div>
                    <div style="font-size: 0.85rem; color: #666; margin-bottom: 5px;">Average Rate</div>
                    <div style="font-size: 1.8rem; font-weight: 700; color: var(--primary-color);">${avgRate}%</div>
                </div>
                <div>
                    <div style="font-size: 0.85rem; color: #666; margin-bottom: 5px;">Excellent Days (≥90%)</div>
                    <div style="font-size: 1.8rem; font-weight: 700; color: #4caf50;">${excellentDays}</div>
                </div>
                <div>
                    <div style="font-size: 0.85rem; color: #666; margin-bottom: 5px;">Good Days (75-89%)</div>
                    <div style="font-size: 1.8rem; font-weight: 700; color: #ff9800;">${goodDays}</div>
                </div>
                <div>
                    <div style="font-size: 0.85rem; color: #666; margin-bottom: 5px;">Concern Days (&lt;75%)</div>
                    <div style="font-size: 1.8rem; font-weight: 700; color: #f44336;">${concernDays}</div>
                </div>
            </div>
        </div>
    `;

            container.innerHTML = html;
        }

        // Function to show detailed day information
        function showDayDetails(dateStr, dayData) {
            if (!dayData) return;

            const modal = document.createElement('div');
            modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 10000;
    `;

            modal.innerHTML = `
        <div style="
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 400px;
            width: 90%;
        ">
            <h3 style="color: var(--primary-dark); margin-bottom: 20px; text-align: center;">
                📅 ${dateStr}
            </h3>
            <div style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span style="font-weight: 600;">Attendance Rate:</span>
                    <span style="font-size: 1.3rem; font-weight: 700; color: var(--primary-color);">${dayData.rate}%</span>
                </div>
                <div style="border-top: 2px solid #e0e0e0; padding-top: 15px; margin-top: 15px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span>✅ Present:</span>
                        <strong style="color: #4caf50;">${dayData.present}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span>❌ Absent:</span>
                        <strong style="color: #f44336;">${dayData.absent}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span>🕐 Late:</span>
                        <strong style="color: #ff9800;">${dayData.late}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span>🤒 Sick:</span>
                        <strong style="color: #9c27b0;">${dayData.sick}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-top: 1px solid #e0e0e0; padding-top: 8px; margin-top: 8px;">
                        <span style="font-weight: 600;">Total Students:</span>
                        <strong>${dayData.total}</strong>
                    </div>
                </div>
            </div>
            <button 
                onclick="this.closest('div[style*=fixed]').remove()"
                style="
                    width: 100%;
                    padding: 12px;
                    background: var(--primary-gradient);
                    color: white;
                    border: none;
                    border-radius: 8px;
                    font-weight: 600;
                    cursor: pointer;
                    font-size: 0.95rem;
                "
                onmouseover="this.style.opacity='0.9'"
                onmouseout="this.style.opacity='1'"
            >
                Close
            </button>
        </div>
    `;

            modal.onclick = function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            };

            document.body.appendChild(modal);
        }

        function exportToPDF() {
            showMessage('Generating PDF...', 'success');
            // PDF export implementation
        }

        function exportToExcel() {
            showMessage('Generating Excel...', 'success');
            // Excel export implementation
        }

        function showLoader() {
            document.getElementById('loader').classList.remove('hidden');
        }

        function hideLoader() {
            document.getElementById('loader').classList.add('hidden');
        }

        function showMessage(text, type) {
            const msg = document.getElementById('message');
            msg.textContent = text;
            msg.className = `message ${type} show`;
            setTimeout(() => msg.classList.remove('show'), 3000);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Load all students when page loads
        async function loadAllStudents() {
            try {
                const response = await fetch('api/get_all_students.php');
                const data = await response.json();

                if (data.success) {
                    allStudentsData = data.students;
                    renderAllStudentsTable(allStudentsData);
                    setupStudentSearch();
                }
            } catch (error) {
                console.error('Error loading students:', error);
            }
        }

        // Render all students table
        function renderAllStudentsTable(students) {
            const tbody = document.getElementById('allStudentsBody');

            if (!students || students.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align: center;">No students found</td></tr>';
                return;
            }

            let html = '';
            students.forEach(student => {
                const rateClass = student.attendance_rate >= 90 ? 'rate-excellent' :
                    student.attendance_rate >= 75 ? 'rate-good' : 'rate-poor';

                html += `
            <tr>
                <td>${escapeHtml(student.student_id)}</td>
                <td><strong>${escapeHtml(student.name)}</strong></td>
                <td>${escapeHtml(student.current_class)}</td>
                <td>${escapeHtml(student.stream)}</td>
                <td>${escapeHtml(student.gender)}</td>
                <td>${escapeHtml(student.section)}</td>
                <td>
                    <span class="attendance-badge ${rateClass}">
                        ${student.attendance_rate}%
                    </span>
                </td>
                <td>
                    <button 
                        onclick="viewProfile('${escapeHtml(student.student_id)}')" 
                        class="view-profile-btn"
                    >
                        View Profile →
                    </button>
                </td>
            </tr>
        `;
            });

            tbody.innerHTML = html;
        }

        // Setup student search functionality
        function setupStudentSearch() {
            const searchInput = document.getElementById('studentSearch');
            const resultsDiv = document.getElementById('searchResults');

            searchInput.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();

                if (query.length < 2) {
                    resultsDiv.innerHTML = '';
                    selectedStudentId = null;
                    return;
                }

                const matches = allStudentsData.filter(s =>
                    s.student_id.toLowerCase().includes(query) ||
                    s.name.toLowerCase().includes(query) ||
                    s.current_class.toLowerCase().includes(query)
                ).slice(0, 10);

                if (matches.length > 0) {
                    let html = '';
                    matches.forEach(student => {
                        html += `
                    <div class="search-result-item" onclick="selectStudent('${student.student_id}')">
                        <div class="student-info">
                            <span class="student-name">${escapeHtml(student.name)}</span>
                            <span class="student-details">
                                ${escapeHtml(student.student_id)} • 
                                ${escapeHtml(student.current_class)} ${escapeHtml(student.stream)} • 
                                Attendance: ${student.attendance_rate}%
                            </span>
                        </div>
                        <span style="color: var(--primary-color);">→</span>
                    </div>
                `;
                    });
                    resultsDiv.innerHTML = html;
                } else {
                    resultsDiv.innerHTML = '<p style="color: #666; padding: 10px;">No students found</p>';
                }
            });

            // Allow Enter key
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && selectedStudentId) {
                    viewProfile(selectedStudentId);
                }
            });
        }

        // Select student from search
        function selectStudent(studentId) {
            selectedStudentId = studentId;
            viewProfile(studentId);
        }

        // Open selected student
        function openSelectedStudent() {
            if (selectedStudentId) {
                viewProfile(selectedStudentId);
            } else {
                const searchValue = document.getElementById('studentSearch').value.trim();
                if (searchValue) {
                    // Try direct student ID
                    viewProfile(searchValue);
                } else {
                    showMessage('Please search and select a student first', 'error');
                }
            }
        }

        // View student profile
        function viewProfile(studentId) {
            window.open(`student_profile.php?id=${encodeURIComponent(studentId)}`, '_blank');
        }

        // Table filter
        document.getElementById('tableSearch')?.addEventListener('input', function() {
            const query = this.value.toLowerCase();
            const filtered = allStudentsData.filter(s =>
                s.student_id.toLowerCase().includes(query) ||
                s.name.toLowerCase().includes(query) ||
                s.current_class.toLowerCase().includes(query) ||
                s.stream.toLowerCase().includes(query)
            );
            renderAllStudentsTable(filtered);
        });
    </script>
</body>

</html>