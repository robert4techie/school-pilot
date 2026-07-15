<?php
require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Student Diagnostic Results Analysis");

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Diagnostic Analysis | School Pilot</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --primary-green: #2e7d32;
            --light-green: #a5d6a7;
            --dark-green: #1b5e20;
            --background: #f1f8e9;
            --card-bg: #ffffff;
            --text: #333;
            --success: #4CAF50;
            --warning: #FFC107;
            --danger: #F44336;
            --info: #2196F3;
            --border: #e0e0e0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Sen", sans-serif !important;
            background-color: var(--background);
            color: var(--text);
            padding: 20px;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
        }

        h1 {
            text-align: center;
            color: var(--dark-green);
            font-size: 2.5em;
            margin-bottom: 30px;
        }

        /* Filter Section */
        .filters {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .filter-group label {
            display: block;
            font-weight: 600;
            color: var(--dark-green);
            margin-bottom: 5px;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px;
            border: 2px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
        }

        .btn {
            font-family: "Sen", sans-serif !important;
            background: var(--primary-green);
            color: white;
            padding: 10px 25px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: 0.3s;
        }

        .btn:hover {
            background: var(--dark-green);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #666;
            margin-left: 10px;
        }

        /* Section 1: Student Overview Card */
        .student-overview {
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }

        .student-header {
            display: flex;
            align-items: center;
            gap: 25px;
            margin-bottom: 25px;
        }

        .student-avatar {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5em;
            font-weight: bold;
            border: 4px solid rgba(255, 255, 255, 0.3);
        }

        .student-details h2 {
            font-size: 2em;
            margin-bottom: 5px;
        }

        .student-details p {
            opacity: 0.9;
            font-size: 1.1em;
        }

        .overview-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }

        .stat-box {
            background: rgba(255, 255, 255, 0.15);
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }

        .stat-box .value {
            font-size: 2em;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-box .label {
            font-size: 0.9em;
            opacity: 0.9;
        }

        /* Section Cards */
        .section-card {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 25px;
        }

        .section-title {
            font-size: 1.5em;
            color: var(--dark-green);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid var(--light-green);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Subject Performance Table */
        .subject-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .subject-table th,
        .subject-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .subject-table th {
            background: var(--primary-green);
            color: white;
            font-weight: 600;
            text-align: center;
        }

        .subject-table td {
            text-align: center;
        }

        .subject-table tbody tr:hover {
            background: #f5f5f5;
        }

        .grade-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 700;
            color: white;
        }

        .trend-indicator {
            font-weight: 700;
        }

        .trend-up {
            color: var(--success);
        }

        .trend-down {
            color: var(--danger);
        }

        .trend-stable {
            color: #666;
        }

        /* Priority Badges */
        .priority-critical {
            background: var(--danger);
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: 600;
        }

        .priority-high {
            background: var(--warning);
            color: #333;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: 600;
        }

        .priority-medium {
            background: var(--info);
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: 600;
        }

        .priority-low {
            background: var(--success);
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: 600;
        }

        .priority-maintain {
            background: #9C27B0;
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: 600;
        }

        /* Strengths & Weaknesses Section */
        .sw-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-top: 20px;
        }

        .sw-box {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid var(--primary-green);
        }

        .sw-box.weakness {
            border-left-color: var(--danger);
        }

        .sw-box h4 {
            color: var(--dark-green);
            margin-bottom: 15px;
            font-size: 1.2em;
        }

        .sw-item {
            padding: 10px;
            background: white;
            margin-bottom: 10px;
            border-radius: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .sw-item .subject-name {
            font-weight: 600;
        }

        .improvement-badge {
            background: var(--success);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: 600;
        }

        .decline-badge {
            background: var(--danger);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: 600;
        }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 25px;
            margin: 25px 0;
        }

        .chart-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .chart-title {
            font-size: 1.2em;
            color: var(--dark-green);
            margin-bottom: 15px;
            font-weight: 600;
        }

        .chart-container {
            position: relative;
            height: 350px;
        }

        /* Recommendations Section */
        .recommendations-list {
            margin-top: 20px;
        }

        .recommendation-item {
            background: #f9f9f9;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 8px;
            border-left: 4px solid var(--primary-green);
        }

        .recommendation-item.critical {
            border-left-color: var(--danger);
            background: #ffebee;
        }

        .recommendation-item.high {
            border-left-color: var(--warning);
            background: #fff8e1;
        }

        .recommendation-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .recommendation-header h4 {
            color: var(--dark-green);
            font-size: 1.1em;
        }

        .recommendation-items ul {
            list-style-position: inside;
            color: #555;
            line-height: 1.8;
        }

        /* Print Styles */
        .print-section {
            display: none;
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }

            .filters,
            .btn {
                display: none;
            }

            .section-card {
                page-break-inside: avoid;
                box-shadow: none;
                border: 1px solid #ddd;
            }

            .print-section {
                display: block;
            }
        }

        /* Responsive */
        @media (max-width: 768px) {

            .charts-grid,
            .sw-grid {
                grid-template-columns: 1fr;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .subject-table {
                font-size: 0.85em;
            }
        }

        .loading {
            text-align: center;
            padding: 40px;
            font-size: 1.2em;
            color: var(--primary-green);
        }

        .error {
            background: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 6px;
            margin: 20px 0;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>Student Diagnostic Analysis</h1>

        <!-- Filters -->
        <div class="filters">
            <form id="diagnostic-form">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Search Student</label>
                        <input type="text" id="student-search" placeholder="Type name or ID...">
                    </div>
                    <div class="filter-group">
                        <label>Select Student</label>
                        <select name="student_id" id="student-select"></select>
                    </div>
                    <div class="filter-group">
                        <label>Academic Year</label>
                        <select name="year" id="year-select">
                            <option value="2025">2025</option>
                            <option value="2024">2024</option>
                            <option value="2026">2026</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>View Term</label>
                        <select name="term" id="term-select">
                            <option value="1">Term 1</option>
                            <option value="2">Term 2</option>
                            <option value="3">Term 3</option>
                        </select>
                    </div>
                </div>
                <div>
                    <button type="button" class="btn" onclick="fetchData()">
                        <i class="fas fa-search"></i> Analyze
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="window.print()">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                </div>
            </form>
        </div>

        <div id="loading" class="loading" style="display:none;">
            <i class="fas fa-spinner fa-spin"></i> Loading diagnostic data...
        </div>

        <div id="error" class="error" style="display:none;"></div>

        <div id="content" style="display:none;">
            <!-- Section 1: Student Overview Card -->
            <div class="student-overview">
                <div class="student-header">
                    <div class="student-avatar" id="avatar"></div>
                    <div class="student-details">
                        <h2 id="student-name"></h2>
                        <p id="student-class"></p>
                    </div>
                </div>
                <div class="overview-stats">
                    <div class="stat-box">
                        <div class="value" id="overall-avg">0</div>
                        <div class="label">Overall Average</div>
                    </div>
                    <div class="stat-box">
                        <div class="value" id="overall-grade">-</div>
                        <div class="label">Overall Grade</div>
                    </div>
                    <div class="stat-box">
                        <div class="value" id="position">-</div>
                        <div class="label">Class Position</div>
                    </div>
                    <div class="stat-box">
                        <div class="value" id="percentile">0%</div>
                        <div class="label">Percentile</div>
                    </div>
                    <div class="stat-box">
                        <div class="value" id="achievement">-</div>
                        <div class="label">Achievement Level</div>
                    </div>
                </div>
            </div>

            <!-- Section 2: Performance Summary -->
            <div class="section-card">
                <h3 class="section-title">
                    <i class="fas fa-chart-bar"></i> Performance Summary
                </h3>
                <div id="performance-summary"></div>
            </div>

            <!-- Section 3: Subject-by-Subject Analysis -->
            <div class="section-card">
                <h3 class="section-title">
                    <i class="fas fa-list-check"></i> Subject-by-Subject Analysis
                </h3>
                <div style="overflow-x: auto;">
                    <table class="subject-table" id="subject-table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>AOI Avg<br></th>
                                <th>Out of 20</th>
                                <th>EOT / 80</th>
                                <th>Final Score<br>(100%)</th>
                                <th>Grade</th>
                                <th>Class Avg</th>
                                <th>Difference</th>
                                <th>Trend</th>
                                <th>Priority</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            <!-- Section 4: Strengths & Weaknesses -->
            <div class="section-card">
                <h3 class="section-title">
                    <i class="fas fa-balance-scale"></i> Strengths & Weaknesses Analysis
                </h3>
                <div class="sw-grid">
                    <div class="sw-box">
                        <h4>Top 3 Strongest Subjects</h4>
                        <div id="top-subjects"></div>
                    </div>
                    <div class="sw-box weakness">
                        <h4>Top 3 Areas for Improvement</h4>
                        <div id="weak-subjects"></div>
                    </div>
                </div>
                <div class="sw-grid" style="margin-top: 20px;">
                    <div class="sw-box">
                        <h4>Most Improved Subject</h4>
                        <div id="most-improved"></div>
                    </div>
                    <div class="sw-box weakness">
                        <h4>Most Declined Subject</h4>
                        <div id="most-declined"></div>
                    </div>
                </div>
            </div>

            <!-- Section 5: Enhanced Charts -->
            <div class="charts-grid">
                <div class="chart-card">
                    <div class="chart-title">Subject Performance Overview</div>
                    <div class="chart-container">
                        <canvas id="subject-chart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-title">AOI vs EOT Performance</div>
                    <div class="chart-container">
                        <canvas id="aoi-eot-chart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-title">Performance Trend (Across Terms)</div>
                    <div class="chart-container">
                        <canvas id="trend-chart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-title">Grade Distribution</div>
                    <div class="chart-container">
                        <canvas id="grade-chart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Section 6: Detailed Recommendations -->
            <div class="section-card">
                <h3 class="section-title">
                    <i class="fas fa-lightbulb"></i> Detailed Recommendations & Action Plan
                </h3>
                <div class="recommendations-list" id="recommendations"></div>
            </div>

            <!-- Section 7: Print Report Footer -->
            <div class="print-section" style="margin-top: 40px; padding-top: 20px; border-top: 2px solid #ddd;">
                <p style="text-align: center; color: #666;">
                    Report Generated: <span id="report-date"></span><br>
                    <strong>Academic Year: <span id="report-year"></span> | Term: <span id="report-term"></span></strong>
                </p>
            </div>
        </div>
    </div>

    <script>
        let charts = {};
        let allStudents = [];

        // Fetch student list
        async function loadStudents() {
            try {
                const response = await fetch('api/fetch_students_list.php');
                allStudents = await response.json();
                populateStudentSelect(allStudents);
            } catch (error) {
                console.error('Failed to load students:', error);
            }
        }

        function populateStudentSelect(students) {
            const select = document.getElementById('student-select');
            select.innerHTML = '';

            if (students.length === 0) {
                select.innerHTML = '<option>No students found</option>';
                return;
            }

            students.forEach(student => {
                const option = document.createElement('option');
                option.value = student.student_id;
                option.textContent = `${student.first_name} ${student.last_name} (${student.current_class})`;
                select.appendChild(option);
            });
        }

        // Student search
        document.addEventListener('DOMContentLoaded', () => {
            loadStudents();

            document.getElementById('student-search').addEventListener('input', (e) => {
                const searchTerm = e.target.value.toLowerCase();
                const filtered = allStudents.filter(s => {
                    const fullName = `${s.first_name} ${s.last_name}`.toLowerCase();
                    return fullName.includes(searchTerm) || s.student_id.toLowerCase().includes(searchTerm);
                });
                populateStudentSelect(filtered);
            });

            // Auto-load on select change
            document.querySelectorAll('#diagnostic-form select').forEach(select => {
                select.addEventListener('change', fetchData);
            });
        });

        async function fetchData() {
            const form = document.getElementById('diagnostic-form');
            const params = new URLSearchParams(new FormData(form)).toString();

            document.getElementById('loading').style.display = 'block';
            document.getElementById('error').style.display = 'none';
            document.getElementById('content').style.display = 'none';

            try {
                const response = await fetch(`api/fetch_diagnostic_data.php?${params}`);
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                renderDashboard(data);
                document.getElementById('content').style.display = 'block';
            } catch (error) {
                document.getElementById('error').textContent = 'Error: ' + error.message;
                document.getElementById('error').style.display = 'block';
            } finally {
                document.getElementById('loading').style.display = 'none';
            }
        }

        function renderDashboard(data) {
            // Section 1: Student Overview
            const info = data.studentInfo;
            document.getElementById('avatar').textContent =
                (info.first_name.charAt(0) + info.last_name.charAt(0)).toUpperCase();
            document.getElementById('student-name').textContent =
                `${info.first_name} ${info.last_name}`;
            document.getElementById('student-class').textContent =
                `${info.current_class} | Stream: ${info.stream} | Section: ${info.section}`;

            const stats = data.overallStats;
            document.getElementById('overall-avg').textContent = stats.average?.toFixed(1) || '-';
            document.getElementById('overall-grade').textContent = stats.grade || '-';
            document.getElementById('achievement').textContent = stats.achievement || '-';

            const ranking = data.classRanking;
            document.getElementById('position').textContent = ranking.position ?
                `${ranking.position}/${ranking.totalStudents}` : '-';
            document.getElementById('percentile').textContent =
                ranking.percentile ? `${ranking.percentile}%` : '0%';

            // Section 2: Performance Summary
            renderPerformanceSummary(data);

            // Section 3: Subject Table
            renderSubjectTable(data.subjectPerformance);

            // Section 4: Strengths & Weaknesses
            renderStrengthsWeaknesses(data.strengthsWeaknesses);

            // Section 5: Charts
            renderCharts(data);

            // Section 6: Recommendations
            renderRecommendations(data.subjectPerformance);

            // Section 7: Print Info
            document.getElementById('report-date').textContent = new Date().toLocaleDateString();
            document.getElementById('report-year').textContent = document.getElementById('year-select').value;
            document.getElementById('report-term').textContent = 'Term ' + document.getElementById('term-select').value;
        }

        function renderPerformanceSummary(data) {
            const stats = data.overallStats;
            const html = `
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <div style="text-align: center; padding: 15px; background: #f9f9f9; border-radius: 8px;">
                        <div style="font-size: 1.5em; font-weight: 700; color: var(--dark-green);">
                            ${stats.subjectCount || 0}
                        </div>
                        <div style="color: #666;">Subjects Taken</div>
                    </div>
                    <div style="text-align: center; padding: 15px; background: #f9f9f9; border-radius: 8px;">
                        <div style="font-size: 1.5em; font-weight: 700; color: var(--dark-green);">
                            ${stats.total || 0}
                        </div>
                        <div style="color: #666;">Total Marks</div>
                    </div>
                    <div style="text-align: center; padding: 15px; background: #f9f9f9; border-radius: 8px;">
                        <div style="font-size: 1.5em; font-weight: 700; color: var(--dark-green);">
                            ${stats.grade || '-'}
                        </div>
                        <div style="color: #666;">Overall Grade</div>
                    </div>
                    <div style="text-align: center; padding: 15px; background: #f9f9f9; border-radius: 8px;">
                        <div style="font-size: 1.5em; font-weight: 700; color: var(--dark-green);">
                            ${stats.achievement || '-'}
                        </div>
                        <div style="color: #666;">Achievement Level</div>
                    </div>
                </div>
            `;
            document.getElementById('performance-summary').innerHTML = html;
        }

        function renderSubjectTable(subjects) {
            const tbody = document.querySelector('#subject-table tbody');
            tbody.innerHTML = '';

            subjects.forEach(subj => {
                const trendIcon = subj.trend > 0 ? '↑' : subj.trend < 0 ? '↓' : '→';
                const trendClass = subj.trend > 0 ? 'trend-up' : subj.trend < 0 ? 'trend-down' : 'trend-stable';
                const trendText = subj.trend !== null ? `${trendIcon} ${Math.abs(subj.trend).toFixed(1)}` : '-';

                const diffText = subj.difference !== null ?
                    (subj.difference >= 0 ? `+${subj.difference}` : subj.difference) :
                    '-';

                const row = `
            <tr>
                <td style="text-align: left; font-weight: 600;">${subj.subject}</td>
                <td>${subj.aoi_average !== null ? subj.aoi_average.toFixed(2) : '-'}</td>
                <td><strong>${subj.out_of_20 !== null ? Math.round(subj.out_of_20) : '-'}</strong></td>
                <td><strong>${subj.eot_of_80 !== null ? Math.round(subj.eot_of_80) : '-'}</strong></td>
                <td><strong>${Math.round(subj.score)}</strong></td>
                <td><span class="grade-badge" style="background: ${subj.color};">${subj.grade}</span></td>
                <td>${subj.classAverage !== null ? subj.classAverage : '-'}</td>
                <td class="${subj.difference >= 0 ? 'trend-up' : 'trend-down'}">${diffText}</td>
                <td class="${trendClass}">${trendText}</td>
                <td><span class="priority-${subj.recommendation.priority}">${subj.recommendation.priority.toUpperCase()}</span></td>
            </tr>
        `;
                tbody.innerHTML += row;
            });
        }

        function renderStrengthsWeaknesses(sw) {
            // Top 3 Subjects
            let topHtml = '';
            sw.topSubjects.forEach((subj, i) => {
                topHtml += `
                    <div class="sw-item">
                        <div>
                            <div class="subject-name">${i + 1}. ${subj.subject}</div>
                            <div style="font-size: 0.9em; color: #666;">
                                Score: ${subj.score} | Grade: ${subj.grade}
                            </div>
                        </div>
                        <span class="grade-badge" style="background: ${subj.color};">${subj.grade}</span>
                    </div>
                `;
            });
            document.getElementById('top-subjects').innerHTML = topHtml || '<p style="color: #666;">No data available</p>';

            // Weak Subjects
            let weakHtml = '';
            sw.weakSubjects.forEach((subj, i) => {
                weakHtml += `
                    <div class="sw-item">
                        <div>
                            <div class="subject-name">${i + 1}. ${subj.subject}</div>
                            <div style="font-size: 0.9em; color: #666;">
                                Score: ${subj.score} | Grade: ${subj.grade}
                            </div>
                        </div>
                        <span class="grade-badge" style="background: ${subj.color};">${subj.grade}</span>
                    </div>
                `;
            });
            document.getElementById('weak-subjects').innerHTML = weakHtml || '<p style="color: #666;">No data available</p>';

            // Most Improved
            if (sw.mostImproved) {
                const improved = sw.mostImproved;
                document.getElementById('most-improved').innerHTML = `
                    <div class="sw-item">
                        <div>
                            <div class="subject-name">${improved.subject}</div>
                            <div style="font-size: 0.9em; color: #666;">
                                Current Score: ${improved.currentScore} (${improved.grade})
                            </div>
                        </div>
                        <span class="improvement-badge">+${improved.improvement.toFixed(1)}</span>
                    </div>
                `;
            } else {
                document.getElementById('most-improved').innerHTML = '<p style="color: #666;">Need at least 2 terms to compare</p>';
            }

            // Most Declined
            if (sw.mostDeclined) {
                const declined = sw.mostDeclined;
                document.getElementById('most-declined').innerHTML = `
                    <div class="sw-item">
                        <div>
                            <div class="subject-name">${declined.subject}</div>
                            <div style="font-size: 0.9em; color: #666;">
                                Current Score: ${declined.currentScore} (${declined.grade})
                            </div>
                        </div>
                        <span class="decline-badge">-${declined.decline.toFixed(1)}</span>
                    </div>
                `;
            } else {
                document.getElementById('most-declined').innerHTML = '<p style="color: #666;">Need at least 2 terms to compare</p>';
            }
        }

        function renderCharts(data) {
            // Subject Performance Chart
            if (charts.subject) charts.subject.destroy();
            const subjectCtx = document.getElementById('subject-chart').getContext('2d');
            charts.subject = new Chart(subjectCtx, {
                type: 'bar',
                data: {
                    labels: data.subjectPerformance.map(s => s.subject),
                    datasets: [{
                            label: 'Student Score',
                            data: data.subjectPerformance.map(s => s.score),
                            backgroundColor: data.subjectPerformance.map(s => s.color)
                        },
                        {
                            label: 'Class Average',
                            data: data.subjectPerformance.map(s => s.classAverage || 0),
                            backgroundColor: 'rgba(150, 150, 150, 0.5)',
                            borderColor: '#666',
                            borderWidth: 1
                        }
                    ]
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

            // AOI vs EOT Chart
            if (charts.aoiEot) charts.aoiEot.destroy();
            const aoiEotCtx = document.getElementById('aoi-eot-chart').getContext('2d');
            const aoiEotData = data.subjectPerformance.filter(s => s.aoiAverage !== null && s.eotScore !== null);

            charts.aoiEot = new Chart(aoiEotCtx, {
                type: 'radar',
                data: {
                    labels: aoiEotData.map(s => s.subject),
                    datasets: [{
                            label: 'AOI Average',
                            data: aoiEotData.map(s => (s.aoiAverage / 3) * 100),
                            borderColor: '#2196F3',
                            backgroundColor: 'rgba(33, 150, 243, 0.2)'
                        },
                        {
                            label: 'EOT Score',
                            data: aoiEotData.map(s => s.eotScore),
                            borderColor: '#FF9800',
                            backgroundColor: 'rgba(255, 152, 0, 0.2)'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        r: {
                            beginAtZero: true,
                            max: 100
                        }
                    }
                }
            });

            // Performance Trend Chart
            if (charts.trend) charts.trend.destroy();
            const trendCtx = document.getElementById('trend-chart').getContext('2d');
            charts.trend = new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: data.performanceTrend.map(t => t.term),
                    datasets: [{
                        label: 'Overall Average',
                        data: data.performanceTrend.map(t => t.average),
                        borderColor: '#2e7d32',
                        backgroundColor: 'rgba(46, 125, 50, 0.1)',
                        tension: 0.4,
                        fill: true
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

            // Grade Distribution Chart
            if (charts.grade) charts.grade.destroy();
            const gradeCtx = document.getElementById('grade-chart').getContext('2d');
            const gradeCounts = {
                A: 0,
                B: 0,
                C: 0,
                D: 0,
                E: 0
            };
            data.subjectPerformance.forEach(s => {
                if (gradeCounts.hasOwnProperty(s.grade)) {
                    gradeCounts[s.grade]++;
                }
            });

            charts.grade = new Chart(gradeCtx, {
                type: 'pie',
                data: {
                    labels: ['A', 'B', 'C', 'D', 'E'],
                    datasets: [{
                        data: [gradeCounts.A, gradeCounts.B, gradeCounts.C, gradeCounts.D, gradeCounts.E],
                        backgroundColor: ['#4CAF50', '#8BC34A', '#FFC107', '#FF9800', '#F44336']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
        }

        function renderRecommendations(subjects) {
            let html = '';

            // Group by priority
            const priorities = ['critical', 'high', 'medium', 'low', 'maintain'];

            priorities.forEach(priority => {
                const filtered = subjects.filter(s => s.recommendation.priority === priority);
                if (filtered.length > 0) {
                    filtered.forEach(subj => {
                        html += `
                            <div class="recommendation-item ${priority}">
                                <div class="recommendation-header">
                                    <h4>${subj.subject}</h4>
                                    <span class="priority-${priority}">${priority.toUpperCase()}</span>
                                </div>
                                <div class="recommendation-items">
                                    <ul>
                                        ${subj.recommendation.items.map(item => `<li>${item}</li>`).join('')}
                                    </ul>
                                </div>
                            </div>
                        `;
                    });
                }
            });

            document.getElementById('recommendations').innerHTML = html;
        }
    </script>
</body>

</html>