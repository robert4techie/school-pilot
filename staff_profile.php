<?php


ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Role-based access control
$allowed_roles = ['developer', 'super user', 'school leader'];

if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), array_map('strtolower', $allowed_roles))) {
    if (isset($tracker)) {
        $tracker->trackAction("Unauthorized access attempt to Staff Profile by " . ($_SESSION['username'] ?? 'Unknown'));
    }

    $_SESSION['error_message'] = "Access Denied: You don't have permission to access this page.";
    http_response_code(403);
    header("Location: dashboard.php");
    exit();
}

try {
    $staff_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    if (!$staff_id) {
        header('Location: staff_attendance_analytics.php');
        exit();
    }

    $tracker->trackAction("Viewed Staff Profile: $staff_id");

    // Fetch staff details
    $staff_sql = "SELECT * FROM staff WHERE staff_id = ? AND Status = 'active'";
    $stmt = $conn->prepare($staff_sql);
    $stmt->bind_param("s", $staff_id);
    $stmt->execute();
    $staff = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$staff) {
        throw new Exception("Staff member not found");
    }
} catch (Exception $e) {
    error_log("Staff Profile Error: " . $e->getMessage());
    die("An error occurred. Please go back and try again.");
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Profile - <?= htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            /* Primary Green Color Palette */
            --primary-color: #2e7d32;
            --primary-dark: #145a32;
            --primary-light: #66bb6a;
            --primary-lighter: #a5d6a7;
            --primary-gradient: linear-gradient(135deg, #2e7d32 0%, #4caf50 100%);

            /* Status Colors */
            --present-color: #4caf50;
            --absent-color: #f44336;
            --late-color: #ff9800;
            --on-leave-color: #9c27b0;

            /* Neutral Colors */
            --light-bg: #f1f8f4;
            --white: #ffffff;
            --text-dark: #1b5e20;
            --text-medium: #388e3c;
            --text-light: #666;

            /* Shadows */
            --shadow-sm: 0 2px 4px rgba(46, 125, 50, 0.08);
            --shadow: 0 4px 6px rgba(46, 125, 50, 0.12);
            --shadow-md: 0 6px 12px rgba(46, 125, 50, 0.15);
            --shadow-lg: 0 10px 30px rgba(46, 125, 50, 0.18);

            /* Border Radius */
            --radius-sm: 6px;
            --radius: 10px;
            --radius-lg: 15px;
            --radius-xl: 20px;

            /* Transitions */
            --transition: 0.3s ease;
        }

        /* === GLOBAL RESET === */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* === BODY === */
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
            background: var(--light-bg);
            padding: 10px;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* === CONTAINER === */
        .container {
            max-width: 100%;
            margin: 20px auto;
            margin-top: 70px;
            animation: fadeInUp 0.6s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* === PROFILE HEADER === */
        .profile-header {
            background: var(--primary-gradient);
            color: white;
            padding: 35px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            gap: 35px;
            margin-bottom: 32px;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, transparent 0%, rgba(255, 255, 255, 0.1) 100%);
            pointer-events: none;
        }

        .profile-header::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        /* === PROFILE AVATAR === */
        .profile-avatar {
            width: 130px;
            height: 130px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 52px;
            font-weight: 800;
            color: var(--primary-color);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
            border: 5px solid rgba(255, 255, 255, 0.3);
            position: relative;
            z-index: 1;
            transition: all var(--transition);
        }

        .profile-avatar:hover {
            transform: scale(1.05);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.25);
        }

        /* === PROFILE INFO === */
        .profile-info {
            flex: 1;
            position: relative;
            z-index: 1;
        }

        .profile-info h1 {
            font-size: 2.2rem;
            margin-bottom: 12px;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .profile-meta {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            opacity: 0.95;
            font-size: 0.95rem;
        }

        .profile-meta span {
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.15);
            padding: 6px 14px;
            border-radius: 20px;
            backdrop-filter: blur(10px);
            transition: all var(--transition);
        }

        .profile-meta span:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
        }

        /* === BACK BUTTON === */
        .back-btn {
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid white;
            color: white;
            padding: 12px 24px;
            border-radius: var(--radius);
            text-decoration: none;
            font-weight: 600;
            transition: all var(--transition);
            backdrop-filter: blur(10px);
            position: relative;
            z-index: 1;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.9rem;
        }

        .back-btn:hover {
            background: white;
            color: var(--primary-color);
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        .back-btn:active {
            transform: translateY(-1px);
        }

        /* === STATS GRID === */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 22px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: white;
            padding: 24px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            text-align: center;
            transition: all var(--transition);
            border-top: 4px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 80px;
            height: 80px;
            background: radial-gradient(circle, rgba(46, 125, 50, 0.05) 0%, transparent 70%);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }

        .stat-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-lg);
            border-top-color: var(--primary-color);
        }

        .stat-value {
            font-size: 2.8rem;
            font-weight: 800;
            margin-bottom: 8px;
            line-height: 1;
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.85rem;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 1px;
        }

        /* === CHART SECTION === */
        .chart-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 28px;
            margin-bottom: 32px;
        }

        .chart-card {
            background: white;
            padding: 28px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            transition: all var(--transition);
            border: 1px solid rgba(46, 125, 50, 0.1);
        }

        .chart-card:hover {
            box-shadow: var(--shadow-md);
            border-color: rgba(46, 125, 50, 0.2);
        }

        .chart-card.full {
            grid-column: 1 / -1;
        }

        .chart-title {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 22px;
            color: var(--primary-dark);
            position: relative;
            padding-left: 16px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--light-bg);
        }

        .chart-title::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 5px;
            height: 28px;
            background: var(--primary-gradient);
            border-radius: 3px;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        /* === HISTORY TABLE === */
        .history-table {
            background: white;
            padding: 28px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid rgba(46, 125, 50, 0.1);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th,
        td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: linear-gradient(to bottom, var(--light-bg) 0%, #f8fbf9 100%);
            font-weight: 700;
            color: var(--primary-dark);
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.8px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        tr {
            transition: all 0.15s ease;
        }

        tr:hover {
            background: var(--light-bg);
            transform: scale(1.005);
        }

        /* === STATUS BADGES === */
        .status-badge {
            padding: 6px 14px;
            border-radius: 16px;
            font-size: 0.85rem;
            font-weight: 700;
            display: inline-block;
            text-transform: capitalize;
            letter-spacing: 0.3px;
        }

        .status-present {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            color: var(--present-color);
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .status-absent {
            background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
            color: var(--absent-color);
            border: 1px solid rgba(244, 67, 54, 0.3);
        }

        .status-late {
            background: linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%);
            color: var(--late-color);
            border: 1px solid rgba(255, 152, 0, 0.3);
        }

        .status-on_leave {
            background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%);
            color: var(--on-leave-color);
            border: 1px solid rgba(156, 39, 176, 0.3);
        }

        /* === ALERT BOX === */
        .alert-box {
            background: linear-gradient(135deg, #fff8e1 0%, #fff3e0 100%);
            border-left: 5px solid var(--late-color);
            padding: 18px 22px;
            border-radius: var(--radius);
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
            animation: slideInDown 0.4s ease;
        }

        .alert-box.critical {
            background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
            border-left-color: var(--absent-color);
        }

        .alert-box strong {
            color: var(--primary-dark);
            font-weight: 700;
            font-size: 1.05rem;
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* === LOADER === */
        .loader {
            text-align: center;
            padding: 50px;
            color: var(--text-light);
            font-size: 1.1rem;
        }

        .loader::after {
            content: '';
            display: block;
            margin: 20px auto 0;
            width: 40px;
            height: 40px;
            border: 4px solid var(--primary-lighter);
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

        /* === RESPONSIVE DESIGN === */
        @media (max-width: 1024px) {
            .chart-section {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 8px;
            }

            .container {
                margin-top: 60px;
            }

            .profile-header {
                flex-direction: column;
                text-align: center;
                padding: 28px 20px;
                gap: 24px;
            }

            .profile-info h1 {
                font-size: 1.8rem;
            }

            .profile-meta {
                justify-content: center;
                gap: 12px;
            }

            .profile-meta span {
                font-size: 0.85rem;
                padding: 5px 12px;
            }

            .chart-section {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                gap: 16px;
            }

            .stat-value {
                font-size: 2.2rem;
            }

            .stat-label {
                font-size: 0.75rem;
            }

            .chart-card,
            .history-table {
                padding: 20px;
            }

            .profile-avatar {
                width: 110px;
                height: 110px;
                font-size: 44px;
            }

            .back-btn {
                width: 100%;
                text-align: center;
            }

            table {
                font-size: 0.9rem;
            }

            th,
            td {
                padding: 10px 12px;
            }
        }

        @media (max-width: 480px) {
            .profile-header {
                padding: 20px 16px;
            }

            .profile-info h1 {
                font-size: 1.5rem;
            }

            .profile-meta {
                flex-direction: column;
                align-items: center;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .stat-value {
                font-size: 2rem;
            }

            .chart-title {
                font-size: 1.1rem;
            }

            .alert-box {
                padding: 14px 16px;
            }

            table {
                font-size: 0.85rem;
            }

            th,
            td {
                padding: 8px 10px;
            }
        }

        /* === PRINT STYLES === */
        @media print {
            body {
                background: white;
            }

            .container {
                margin-top: 0;
            }

            .back-btn {
                display: none;
            }

            .profile-header {
                background: var(--primary-color) !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .chart-card,
            .stat-card,
            .history-table {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #ddd;
            }

            .alert-box {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        /* === SMOOTH SCROLLING === */
        html {
            scroll-behavior: smooth;
        }

        /* === SELECTION === */
        ::selection {
            background: var(--primary-light);
            color: white;
        }

        ::-moz-selection {
            background: var(--primary-light);
            color: white;
        }

        /* === SCROLLBAR === */
        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        ::-webkit-scrollbar-track {
            background: var(--light-bg);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-light);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-color);
        }

        /* === FOCUS STYLES === */
        *:focus {
            outline: 3px solid rgba(46, 125, 50, 0.3);
            outline-offset: 2px;
        }
    </style>
</head>

<body>
    <?php if (file_exists('nav.php')) require_once "nav.php"; ?>

    <div class="container">
        <div class="profile-header">
            <div class="profile-avatar">
                <?= strtoupper(substr($staff['first_name'], 0, 1) . substr($staff['last_name'], 0, 1)) ?>
            </div>
            <div class="profile-info" style="flex: 1;">
                <h1><?= htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']) ?></h1>
                <div class="profile-meta">
                    <span><?= htmlspecialchars($staff['department'] ?? 'N/A') ?></span>
                    <span><?= htmlspecialchars($staff['position'] ?? 'N/A') ?></span>
                    <span><?= htmlspecialchars($staff['staff_id']) ?></span>
                    <span><?= htmlspecialchars($staff['email'] ?? 'N/A') ?></span>
                </div>
            </div>
            <a href="staff_attendance_analytics.php" class="back-btn">← Back to Analytics</a>
        </div>

        <div id="alertContainer"></div>

        <div class="stats-grid" id="statsGrid">
            <div class="loader">Loading statistics...</div>
        </div>

        <div class="chart-section">
            <div class="chart-card">
                <h3 class="chart-title">Attendance Breakdown</h3>
                <div class="chart-container">
                    <canvas id="breakdownChart"></canvas>
                </div>
            </div>

            <div class="chart-card">
                <h3 class="chart-title">Monthly Trend</h3>
                <div class="chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>

            <div class="chart-card full">
                <h3 class="chart-title">Daily Attendance Pattern (Last 60 Days)</h3>
                <div class="chart-container" style="height: 200px;">
                    <canvas id="patternChart"></canvas>
                </div>
            </div>
        </div>

        <div class="history-table">
            <h3 class="chart-title">Attendance History</h3>
            <div id="historyTable">
                <div class="loader">Loading history...</div>
            </div>
        </div>
    </div>

    <script>
        const STAFF_ID = '<?= htmlspecialchars($staff_id) ?>';
        let charts = {};

        document.addEventListener('DOMContentLoaded', function() {
            loadStaffData();
        });

        async function loadStaffData() {
            try {
                const response = await fetch(`api/get_staff_profile_analytics.php?staff_id=${STAFF_ID}`);

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();

                if (!result.success) {
                    throw new Error(result.message || 'Failed to load data');
                }

                if (!result.data) {
                    throw new Error('No data returned from server');
                }

                renderStats(result.data.stats);
                renderAlerts(result.data.alerts || []);
                renderCharts(result.data.charts || {});
                renderHistory(result.data.history || []);

            } catch (error) {
                console.error('Error loading staff data:', error);

                document.getElementById('statsGrid').innerHTML = `
                    <div class="stat-card" style="grid-column: 1 / -1; text-align: center; color: #d32f2f;">
                        <p style="font-size: 1.2rem; margin-bottom: 10px;">❌ Failed to load staff data</p>
                        <p style="font-size: 0.9rem; color: #666;">${error.message}</p>
                        <button onclick="location.reload()" style="margin-top: 15px; padding: 10px 20px; background: #1565c0; color: white; border: none; border-radius: 8px; cursor: pointer;">
                            Retry
                        </button>
                    </div>
                `;

                document.getElementById('alertContainer').innerHTML = '';
                document.getElementById('historyTable').innerHTML = `
                    <p style="color: #d32f2f;">Unable to load attendance history. Please try again.</p>
                `;
            }
        }

        function renderStats(stats) {
            if (!stats) {
                console.error('Stats is undefined');
                return;
            }

            const html = `
                <div class="stat-card">
                    <div class="stat-value" style="color: var(--primary-color);">${stats.attendance_rate || 0}%</div>
                    <div class="stat-label">Attendance Rate</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" style="color: var(--present-color);">${stats.present_days || 0}</div>
                    <div class="stat-label">Days Present</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" style="color: var(--absent-color);">${stats.absent_days || 0}</div>
                    <div class="stat-label">Days Absent</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" style="color: var(--late-color);">${stats.late_days || 0}</div>
                    <div class="stat-label">Days Late</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" style="color: var(--on-leave-color);">${stats.on_leave_days || 0}</div>
                    <div class="stat-label">Days On Leave</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">${stats.total_days || 0}</div>
                    <div class="stat-label">Total Days</div>
                </div>
            `;
            document.getElementById('statsGrid').innerHTML = html;
        }

        function renderAlerts(alerts) {
            const container = document.getElementById('alertContainer');
            if (!alerts || alerts.length === 0) {
                container.innerHTML = '';
                return;
            }

            let html = '';
            alerts.forEach(alert => {
                const alertClass = alert.severity === 'critical' ? 'critical' : '';
                html += `
                    <div class="alert-box ${alertClass}">
                        <strong>${alert.icon || '⚠️'} ${alert.title || 'Alert'}</strong><br>
                        ${alert.message || 'No details available'}
                    </div>
                `;
            });
            container.innerHTML = html;
        }

        function renderCharts(chartData) {
            if (!chartData || !chartData.breakdown) {
                console.error('Chart data is missing or incomplete');
                return;
            }

            // Breakdown Pie Chart
            if (document.getElementById('breakdownChart')) {
                charts.breakdown = new Chart(document.getElementById('breakdownChart'), {
                    type: 'doughnut',
                    data: {
                        labels: ['Present', 'Absent', 'Late', 'On Leave'],
                        datasets: [{
                            data: [
                                chartData.breakdown.present || 0,
                                chartData.breakdown.absent || 0,
                                chartData.breakdown.late || 0,
                                chartData.breakdown.on_leave || 0
                            ],
                            backgroundColor: [
                                'rgba(76, 175, 80, 0.8)',
                                'rgba(244, 67, 54, 0.8)',
                                'rgba(255, 152, 0, 0.8)',
                                'rgba(156, 39, 176, 0.8)'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });
            }

            // Monthly Trend
            if (document.getElementById('trendChart') && chartData.monthly) {
                charts.trend = new Chart(document.getElementById('trendChart'), {
                    type: 'line',
                    data: {
                        labels: chartData.monthly.map(m => m.month),
                        datasets: [{
                            label: 'Attendance Rate %',
                            data: chartData.monthly.map(m => m.rate),
                            borderColor: 'rgba(21, 101, 192, 1)',
                            backgroundColor: 'rgba(21, 101, 192, 0.1)',
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100
                            }
                        }
                    }
                });
            }

            // Daily Pattern
            if (document.getElementById('patternChart') && chartData.daily_pattern) {
                charts.pattern = new Chart(document.getElementById('patternChart'), {
                    type: 'bar',
                    data: {
                        labels: chartData.daily_pattern.map(d => d.date),
                        datasets: [{
                            data: chartData.daily_pattern.map(d => {
                                if (d.status === 'present') return 1;
                                if (d.status === 'late') return 0.7;
                                if (d.status === 'on_leave') return 0.4;
                                return 0;
                            }),
                            backgroundColor: chartData.daily_pattern.map(d => {
                                if (d.status === 'present') return 'rgba(76, 175, 80, 0.8)';
                                if (d.status === 'late') return 'rgba(255, 152, 0, 0.8)';
                                if (d.status === 'on_leave') return 'rgba(156, 39, 176, 0.8)';
                                return 'rgba(244, 67, 54, 0.8)';
                            })
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const status = chartData.daily_pattern[context.dataIndex]?.status || 'absent';
                                        return status.charAt(0).toUpperCase() + status.slice(1).replace('_', ' ');
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                display: false
                            },
                            y: {
                                display: false
                            }
                        }
                    }
                });
            }
        }

        function renderHistory(history) {
            if (!history || history.length === 0) {
                document.getElementById('historyTable').innerHTML = '<p>No attendance records found.</p>';
                return;
            }

            let html = `
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Time Marked</th>
                            <th>Remarks</th>
                            <th>Recorded By</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            history.forEach(record => {
                html += `
                    <tr>
                        <td>${record.date || 'N/A'}</td>
                        <td><span class="status-badge status-${record.status || 'absent'}">${(record.status || 'absent').replace('_', ' ')}</span></td>
                        <td>${record.time || 'N/A'}</td>
                        <td>${record.remarks || '-'}</td>
                        <td>${record.recorded_by || 'System'}</td>
                    </tr>
                `;
            });

            html += '</tbody></table>';
            document.getElementById('historyTable').innerHTML = html;
        }
    </script>
</body>

</html>