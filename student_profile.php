<?php

/**
 * Individual Student Attendance Profile
 * Detailed history, trends, and predictive analytics
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/profile_errors.log');
error_reporting(E_ALL);

require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $student_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    if (!$student_id) {
        header('Location: attendance_analytics.php');
        exit();
    }

    $tracker->trackAction("Viewed Student Profile: $student_id");

    // Fetch student details
    $student_sql = "SELECT * FROM students WHERE student_id = ? AND status = 'active'";
    $stmt = $conn->prepare($student_sql);
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$student) {
        throw new Exception("Student not found");
    }
} catch (Exception $e) {
    error_log("Student Profile Error: " . $e->getMessage());
    die("An error occurred. Please go back and try again.");
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Profile - <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --primary-color: #2e7d32;
            --primary-gradient: linear-gradient(135deg, #2e7d32 0%, #4caf50 100%);
            --present-color: #4caf50;
            --absent-color: #f44336;
            --late-color: #ff9800;
            --sick-color: #9c27b0;
            --light-bg: #f4f6f9;
            --white: #ffffff;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--light-bg);
            padding: 10px;
        }

        .container {
            max-width: 100%;
            margin: 20px auto;
            margin-top: 60px;
        }

        .profile-header {
            background: var(--primary-gradient);
            color: white;
            padding: 30px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            gap: 30px;
            margin-bottom: 30px;
            box-shadow: var(--shadow);
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            font-weight: bold;
            color: var(--primary-color);
        }

        .profile-info h1 {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .profile-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            opacity: 0.95;
        }

        .profile-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid white;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }

        .back-btn:hover {
            background: white;
            color: var(--primary-color);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
            text-transform: uppercase;
        }

        .chart-section {
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

        .chart-card.full {
            grid-column: 1 / -1;
        }

        .chart-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--primary-color);
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        .history-table {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: var(--shadow);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: var(--light-bg);
            font-weight: 600;
            color: var(--primary-color);
        }

        tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-present {
            background: #e8f5e9;
            color: var(--present-color);
        }

        .status-absent {
            background: #ffebee;
            color: var(--absent-color);
        }

        .status-late {
            background: #fff3e0;
            color: var(--late-color);
        }

        .status-sick {
            background: #f3e5f5;
            color: var(--sick-color);
        }

        .alert-box {
            background: #fff3e0;
            border-left: 4px solid var(--late-color);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-box.critical {
            background: #ffebee;
            border-left-color: var(--absent-color);
        }

        .loader {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        @media (max-width: 768px) {
            .profile-header {
                flex-direction: column;
                text-align: center;
            }

            .chart-section {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <?php if (file_exists('nav.php')) require_once "nav.php"; ?>

    <div class="container">
        <div class="profile-header">
            <div class="profile-avatar">
                <?= strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)) ?>
            </div>
            <div class="profile-info" style="flex: 1;">
                <h1><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></h1>
                <div class="profile-meta">
                    <span> <?= htmlspecialchars($student['current_class']) ?> - <?= htmlspecialchars($student['stream']) ?></span>
                    <span> <?= htmlspecialchars($student['student_id']) ?></span>
                    <span> <?= htmlspecialchars($student['gender']) ?></span>
                    <span> <?= htmlspecialchars($student['section']) ?> Student</span>
                </div>
            </div>
            <a href="attendance_analytics.php" class="back-btn">← Back to Analytics</a>
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
        const STUDENT_ID = '<?= htmlspecialchars($student_id) ?>';
        let charts = {};

        document.addEventListener('DOMContentLoaded', function() {
            loadStudentData();
        });

        // Replace the loadStudentData function in student_profile.php with this improved version

        async function loadStudentData() {
            try {
                const response = await fetch(`api/get_student_analytics.php?student_id=${STUDENT_ID}`);

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();

                console.log('API Response:', result); // Debug log

                if (!result.success) {
                    throw new Error(result.message || 'Failed to load data');
                }

                // Check if data exists
                if (!result.data) {
                    throw new Error('No data returned from server');
                }

                // Check if stats exist
                if (!result.data.stats) {
                    throw new Error('Stats data is missing');
                }

                // Render components with proper error handling
                renderStats(result.data.stats);
                renderAlerts(result.data.alerts || []);
                renderCharts(result.data.charts || {});
                renderHistory(result.data.history || []);

            } catch (error) {
                console.error('Error loading student data:', error);

                // Show user-friendly error messages
                document.getElementById('statsGrid').innerHTML = `
            <div class="stat-card" style="grid-column: 1 / -1; text-align: center; color: #d32f2f;">
                <p style="font-size: 1.2rem; margin-bottom: 10px;">❌ Failed to load student data</p>
                <p style="font-size: 0.9rem; color: #666;">${error.message}</p>
                <button onclick="location.reload()" style="margin-top: 15px; padding: 10px 20px; background: #2e7d32; color: white; border: none; border-radius: 8px; cursor: pointer;">
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
            // Add safety check
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
            <div class="stat-value" style="color: var(--sick-color);">${stats.sick_days || 0}</div>
            <div class="stat-label">Days Sick</div>
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
            // Safety checks
            if (!chartData || !chartData.breakdown) {
                console.error('Chart data is missing or incomplete');
                return;
            }

            // Breakdown Pie Chart
            if (document.getElementById('breakdownChart')) {
                charts.breakdown = new Chart(document.getElementById('breakdownChart'), {
                    type: 'doughnut',
                    data: {
                        labels: ['Present', 'Absent', 'Late', 'Sick'],
                        datasets: [{
                            data: [
                                chartData.breakdown.present || 0,
                                chartData.breakdown.absent || 0,
                                chartData.breakdown.late || 0,
                                chartData.breakdown.sick || 0
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
                            borderColor: 'rgba(46, 125, 50, 1)',
                            backgroundColor: 'rgba(46, 125, 50, 0.1)',
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
                                if (d.status === 'sick') return 0.4;
                                return 0;
                            }),
                            backgroundColor: chartData.daily_pattern.map(d => {
                                if (d.status === 'present') return 'rgba(76, 175, 80, 0.8)';
                                if (d.status === 'late') return 'rgba(255, 152, 0, 0.8)';
                                if (d.status === 'sick') return 'rgba(156, 39, 176, 0.8)';
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
                                        return status.charAt(0).toUpperCase() + status.slice(1);
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
                </tr>
            </thead>
            <tbody>
    `;

            history.forEach(record => {
                html += `
            <tr>
                <td>${record.date || 'N/A'}</td>
                <td><span class="status-badge status-${record.status || 'absent'}">${record.status || 'absent'}</span></td>
                <td>${record.time || 'N/A'}</td>
                <td>${record.remarks || '-'}</td>
            </tr>
        `;
            });

            html += '</tbody></table>';
            document.getElementById('historyTable').innerHTML = html;
        }
    </script>
</body>

</html>