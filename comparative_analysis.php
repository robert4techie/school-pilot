<?php
require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Comparative Results Analysis");

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comparative Analysis | School Pilot</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <style>
        :root {
            --primary-green: #2e7d32;
            --light-green: #a5d6a7;
            --dark-green: #1b5e20;
            --background: #f1f8e9;
            --card-bg: #ffffff;
            --text: #212121;
            --border: #ddd;
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

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        h1 {
            font-size: 2.5em;
            color: var(--dark-green);
            margin-bottom: 10px;
        }

        .subtitle {
            font-size: 1.1em;
            color: #666;
        }

        /* Filter Section */
        .filters {
            font-family: "Sen", sans-serif !important;
            background: var(--card-bg);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .filter-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--dark-green);
        }

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
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn:hover {
            background: var(--dark-green);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #666;
            margin-left: 10px;
        }

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .kpi-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .kpi-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary-green);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8em;
        }

        .kpi-info .value {
            font-size: 2em;
            font-weight: 700;
            color: var(--dark-green);
        }

        .kpi-info .label {
            font-size: 0.9em;
            color: #666;
        }

        /* Section Cards */
        .section {
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
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th,
        td {
            padding: 12px;
            text-align: left;
            border: 1px solid var(--border);
        }

        th {
            background: var(--primary-green);
            color: white;
            font-weight: 600;
            text-align: center;
        }

        td {
            text-align: center;
        }

        tbody tr:nth-child(even) {
            background: #f9f9f9;
        }

        tbody tr:hover {
            background: var(--light-green);
            transition: 0.2s;
        }

        /* Charts */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .chart-container {
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

        /* Loading State */
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

        /* Print Styles */
        @media print {
            body {
                background: white;
                padding: 0;
            }

            .filters,
            .btn {
                display: none;
            }

            .section {
                page-break-inside: avoid;
                box-shadow: none;
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1> Comparative Analysis Dashboard</h1>
            <p class="subtitle">Compare student performance across groups, streams, and terms</p>
        </div>

        <!-- Filters -->
        <div class="filters">
            <div class="filter-grid">
                <div class="filter-group">
                    <label>Class</label>
                    <select id="class">
                        <option value="Senior One">Senior One</option>
                        <option value="Senior Two">Senior Two</option>
                        <option value="Senior Three">Senior Three</option>
                        <option value="Senior Four">Senior Four</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Subject</label>
                    <select id="subject">
                        <option value="Mathematics">Mathematics</option>
                        <option value="English">English</option>
                        <option value="Physics">Physics</option>
                        <option value="Chemistry">Chemistry</option>
                        <option value="Biology">Biology</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Stream</label>
                    <select id="stream">
                        <option value="All">All Streams</option>
                        <option value="East">East</option>
                        <option value="West">West</option>
                        <option value="South">South</option>
                        <option value="North">North</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Term</label>
                    <select id="term">
                        <option value="1">Term 1</option>
                        <option value="2">Term 2</option>
                        <option value="3">Term 3</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Year</label>
                    <select id="year">
                        <option value="2024">2024</option>
                        <option value="2025">2025</option>
                        <option value="2026">2026</option>
                    </select>
                </div>
            </div>
            <div>
                <button class="btn" onclick="loadData()">
                    <i class="fas fa-search"></i> Analyze
                </button>
                <button class="btn btn-secondary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>

        <div id="loading" class="loading" style="display:none;">
            <i class="fas fa-spinner fa-spin"></i> Loading data...
        </div>

        <div id="error" class="error" style="display:none;"></div>

        <div id="content" style="display:none;">
            <!-- KPI Cards -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon"><i class="fas fa-users"></i></div>
                    <div class="kpi-info">
                        <div class="value" id="total-students">0</div>
                        <div class="label">Total Students</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="kpi-info">
                        <div class="value" id="pass-rate">0%</div>
                        <div class="label">Pass Rate (C+)</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon"><i class="fas fa-trophy"></i></div>
                    <div class="kpi-info">
                        <div class="value" id="class-average">0</div>
                        <div class="label">Class Average</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon"><i class="fas fa-star"></i></div>
                    <div class="kpi-info">
                        <div class="value" id="highest-score">0</div>
                        <div class="label">Highest Score</div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="charts-grid">
                <div class="chart-container">
                    <div class="chart-title">Grade Distribution</div>
                    <canvas id="gradeChart"></canvas>
                </div>
                <div class="chart-container">
                    <div class="chart-title">Achievement Levels</div>
                    <canvas id="achievementChart"></canvas>
                </div>
                <div class="chart-container">
                    <div class="chart-title">Gender Performance</div>
                    <canvas id="genderChart"></canvas>
                </div>
                <div class="chart-container">
                    <div class="chart-title">Competency Trend</div>
                    <canvas id="trendChart"></canvas>
                </div>
            </div>

            <!-- Table 1: Achievement Levels -->
            <div class="section">
                <h2 class="section-title">Table 1: Achievement Levels Distribution</h2>
                <table id="achievementTable">
                    <thead>
                        <tr>
                            <th>Achievement Level</th>
                            <th>Score Range</th>
                            <th>Number of Students</th>
                            <th>Percentage (%)</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <!-- Table 2: Gender-Grade Breakdown -->
            <div class="section">
                <h2 class="section-title">Table 2: Gender-Grade Distribution</h2>
                <table id="genderGradeTable">
                    <thead>
                        <tr>
                            <th rowspan="2">Grade</th>
                            <th colspan="3">Male</th>
                            <th colspan="3">Female</th>
                            <th colspan="2">Total</th>
                        </tr>
                        <tr>
                            <th>n°</th>
                            <th>%</th>
                            <th>Avg</th>
                            <th>n°</th>
                            <th>%</th>
                            <th>Avg</th>
                            <th>n°</th>
                            <th>%</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <!-- Table 3: Competency Achievement -->
            <div class="section">
                <h2 class="section-title">Table 3: Competency Achievement Summary</h2>
                <table id="competencyTable">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Grades Included</th>
                            <th>Number of Students</th>
                            <th>Percentage (%)</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <!-- Table 4: Performance Trend -->
            <div class="section">
                <h2 class="section-title">Table 4: Performance Trend Across Terms</h2>
                <table id="trendTable">
                    <thead>
                        <tr>
                            <th>Term</th>
                            <th>Competency Achievement (%)</th>
                            <th>Trend</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <!-- Top Performers -->
            <div class="section">
                <h2 class="section-title">🌟 Top 10 Performers</h2>
                <table id="topPerformers">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Student Name</th>
                            <th>Stream</th>
                            <th>Score</th>
                            <th>Grade</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        let charts = {};

        // Add this function at the beginning of your script section
        async function loadSubjects() {
            try {
                const response = await fetch('api/fetch_subjects.php');
                const subjects = await response.json();

                const subjectSelect = document.getElementById('subject');
                subjectSelect.innerHTML = '';

                subjects.forEach(subject => {
                    const option = document.createElement('option');
                    option.value = subject;
                    option.textContent = subject;
                    subjectSelect.appendChild(option);
                });
            } catch (error) {
                console.error('Failed to load subjects:', error);
            }
        }

        // Update your window.onload to include loading subjects
        window.onload = () => {
            populateYears();
            loadSubjects(); // Add this line
            loadData();
        };

        async function loadData() {
            const params = {
                class: document.getElementById('class').value,
                subject: document.getElementById('subject').value,
                stream: document.getElementById('stream').value,
                term: document.getElementById('term').value,
                year: document.getElementById('year').value,
                aoi_columns: 3
            };

            document.getElementById('loading').style.display = 'block';
            document.getElementById('error').style.display = 'none';
            document.getElementById('content').style.display = 'none';

            try {
                const query = new URLSearchParams(params).toString();
                const response = await fetch(`api/fetch_comparative_data.php?${query}`);
                const data = await response.json();

                if (data.error) {
                    showError(data.error);
                    return;
                }

                renderData(data);
            } catch (error) {
                showError('Failed to load data: ' + error.message);
            } finally {
                document.getElementById('loading').style.display = 'none';
            }
        }

        function showError(message) {
            document.getElementById('error').textContent = message;
            document.getElementById('error').style.display = 'block';
        }

        function renderData(data) {
            // Update KPIs
            document.getElementById('total-students').textContent = data.totalStudents;
            document.getElementById('pass-rate').textContent = data.passRate;
            document.getElementById('class-average').textContent = data.classAverage;
            document.getElementById('highest-score').textContent = data.highestScore;

            // Render charts
            renderGradeChart(data.gradeDistribution);
            renderAchievementChart(data.achievementLevels);
            renderGenderChart(data.genderAverages);
            renderTrendChart(data.trendData);

            // Render tables
            renderAchievementTable(data.achievementLevels);
            renderGenderGradeTable(data);
            renderCompetencyTable(data.competencyData, data.totalStudents);
            renderTrendTable(data.trendData);
            renderTopPerformers(data.studentList);

            document.getElementById('content').style.display = 'block';
        }

        function renderGradeChart(gradeDistribution) {
            const ctx = document.getElementById('gradeChart').getContext('2d');
            if (charts.grade) charts.grade.destroy();

            charts.grade = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['A', 'B', 'C', 'D', 'E'],
                    datasets: [{
                        label: 'Number of Students',
                        data: [
                            gradeDistribution.A,
                            gradeDistribution.B,
                            gradeDistribution.C,
                            gradeDistribution.D,
                            gradeDistribution.E
                        ],
                        backgroundColor: [
                            '#4caf50',
                            '#8bc34a',
                            '#ffeb3b',
                            '#ff9800',
                            '#f44336'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }

        function renderAchievementChart(achievementLevels) {
            const ctx = document.getElementById('achievementChart').getContext('2d');
            if (charts.achievement) charts.achievement.destroy();

            const labels = Object.keys(achievementLevels);
            const values = labels.map(key => achievementLevels[key].count);

            charts.achievement = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: [
                            '#2e7d32',
                            '#66bb6a',
                            '#ffeb3b',
                            '#ff9800',
                            '#f44336'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }

        function renderGenderChart(genderAverages) {
            const ctx = document.getElementById('genderChart').getContext('2d');
            if (charts.gender) charts.gender.destroy();

            charts.gender = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Male', 'Female'],
                    datasets: [{
                        label: 'Average Score',
                        data: [
                            genderAverages.Male || 0,
                            genderAverages.Female || 0
                        ],
                        backgroundColor: ['#2196f3', '#e91e63']
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100
                        }
                    }
                }
            });
        }

        function renderTrendChart(trendData) {
            const ctx = document.getElementById('trendChart').getContext('2d');
            if (charts.trend) charts.trend.destroy();

            charts.trend = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: trendData.labels,
                    datasets: [{
                        label: 'Competency Achievement %',
                        data: trendData.data,
                        borderColor: '#2e7d32',
                        backgroundColor: 'rgba(46, 125, 50, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100
                        }
                    }
                }
            });
        }

        function renderAchievementTable(achievementLevels) {
            const ranges = {
                'Exceptional': '85-100',
                'Outstanding': '70-84',
                'Satisfactory': '50-69',
                'Basic': '40-49',
                'Elementary': '<40'
            };

            const tbody = document.querySelector('#achievementTable tbody');
            tbody.innerHTML = '';

            for (let [level, stats] of Object.entries(achievementLevels)) {
                const row = `
                    <tr>
                        <td><strong>${level}</strong></td>
                        <td>${ranges[level]}</td>
                        <td>${stats.count}</td>
                        <td>${stats.percentage}%</td>
                    </tr>
                `;
                tbody.innerHTML += row;
            }
        }

        function renderGenderGradeTable(data) {
            const tbody = document.querySelector('#genderGradeTable tbody');
            tbody.innerHTML = '';

            const grades = ['A', 'B', 'C', 'D', 'E'];
            const total = data.subjectTakers || data.totalStudents;

            grades.forEach(grade => {
                const breakdown = data.detailedGradeBreakdown[grade];
                const maleCount = breakdown.male;
                const femaleCount = breakdown.female;
                const malePercent = total > 0 ? ((maleCount / total) * 100).toFixed(1) : 0;
                const femalePercent = total > 0 ? ((femaleCount / total) * 100).toFixed(1) : 0;

                const row = `
                    <tr>
                        <td><strong>${grade}</strong></td>
                        <td>${maleCount}</td>
                        <td>${malePercent}%</td>
                        <td>-</td>
                        <td>${femaleCount}</td>
                        <td>${femalePercent}%</td>
                        <td>-</td>
                        <td>${breakdown.total}</td>
                        <td>${breakdown.percentage}%</td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
        }

        function renderCompetencyTable(competencyData, totalStudents) {
            const tbody = document.querySelector('#competencyTable tbody');
            const total = competencyData.achieved + competencyData.underachieved;

            tbody.innerHTML = `
        <tr>
            <td><strong>Achieved Competency</strong></td>
            <td>A, B, C</td>
            <td>${competencyData.achieved}</td>
            <td>${total > 0 ? ((competencyData.achieved / total) * 100).toFixed(1) : 0}%</td>
        </tr>
        <tr style="background: #e8f5e9;">
            <td style="padding-left: 30px;">Grade A</td>
            <td>85-100</td>
            <td>${competencyData.gradeBreakdown.A}</td>
            <td>${total > 0 ? ((competencyData.gradeBreakdown.A / total) * 100).toFixed(1) : 0}%</td>
        </tr>
        <tr style="background: #e8f5e9;">
            <td style="padding-left: 30px;">Grade B</td>
            <td>70-84</td>
            <td>${competencyData.gradeBreakdown.B}</td>
            <td>${total > 0 ? ((competencyData.gradeBreakdown.B / total) * 100).toFixed(1) : 0}%</td>
        </tr>
        <tr style="background: #e8f5e9;">
            <td style="padding-left: 30px;">Grade C</td>
            <td>50-69</td>
            <td>${competencyData.gradeBreakdown.C}</td>
            <td>${total > 0 ? ((competencyData.gradeBreakdown.C / total) * 100).toFixed(1) : 0}%</td>
        </tr>
        <tr>
            <td><strong>Underachieved</strong></td>
            <td>D, E</td>
            <td>${competencyData.underachieved}</td>
            <td>${total > 0 ? ((competencyData.underachieved / total) * 100).toFixed(1) : 0}%</td>
        </tr>
        <tr style="background: #ffebee;">
            <td style="padding-left: 30px;">Grade D</td>
            <td>40-49</td>
            <td>${competencyData.gradeBreakdown.D}</td>
            <td>${total > 0 ? ((competencyData.gradeBreakdown.D / total) * 100).toFixed(1) : 0}%</td>
        </tr>
        <tr style="background: #ffebee;">
            <td style="padding-left: 30px;">Grade E</td>
            <td>&lt;40</td>
            <td>${competencyData.gradeBreakdown.E}</td>
            <td>${total > 0 ? ((competencyData.gradeBreakdown.E / total) * 100).toFixed(1) : 0}%</td>
        </tr>
    `;
        }

        function renderTrendTable(trendData) {
            const tbody = document.querySelector('#trendTable tbody');
            tbody.innerHTML = '';

            trendData.labels.forEach((label, index) => {
                const current = trendData.data[index];
                const previous = index > 0 ? trendData.data[index - 1] : null;
                let trend = '-';

                if (previous !== null) {
                    if (current > previous) {
                        trend = `<span style="color: green;">↑ +${(current - previous).toFixed(1)}%</span>`;
                    } else if (current < previous) {
                        trend = `<span style="color: red;">↓ ${(current - previous).toFixed(1)}%</span>`;
                    } else {
                        trend = '<span style="color: gray;">→ No change</span>';
                    }
                }

                tbody.innerHTML += `
                    <tr>
                        <td><strong>${label}</strong></td>
                        <td>${current}%</td>
                        <td>${trend}</td>
                    </tr>
                `;
            });
        }

        function renderTopPerformers(studentList) {
            const tbody = document.querySelector('#topPerformers tbody');
            tbody.innerHTML = '';

            studentList.slice(0, 10).forEach((student, index) => {
                tbody.innerHTML += `
                    <tr>
                        <td><strong>${index + 1}</strong></td>
                        <td>${student.name}</td>
                        <td>${student.stream}</td>
                        <td>${student.score}</td>
                        <td><strong>${student.grade}</strong></td>
                    </tr>
                `;
            });
        }

        // Load data on page load
        window.onload = async () => {
            populateYears();
            await loadSubjects(); // Load subjects first
            loadData();
        };

        // Populate years dynamically
        function populateYears() {
            const yearSelect = document.getElementById('year');
            const currentYear = new Date().getFullYear();
            const startYear = 2025; // Adjust this to your school's start year

            yearSelect.innerHTML = ''; // Clear existing options

            // Generate years from current year down to start year
            for (let year = currentYear; year >= startYear; year--) {
                const option = document.createElement('option');
                option.value = year;
                option.textContent = year;
                yearSelect.appendChild(option);
            }
        }

        // Call on page load (before loadData)
        document.addEventListener('DOMContentLoaded', function() {
            populateYears();
        });
    </script>
</body>

</html>