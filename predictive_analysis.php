<?php
require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Predictive Results Analysis");

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Predictive Analysis | School Pilot</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --primary-green: #2e7d32;
            --dark-green: #1b5e20;
            --background: #f1f8e9;
            --card-bg: #ffffff;
            --text: #333;
            --critical: #d32f2f;
            --high: #f57c00;
            --medium: #FFC107;
            --low: #388e3c;
            --info: #1976D2;
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

        /* Filters */
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

        /* Prediction Cards */
        .prediction-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .prediction-card {
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            color: white;
            padding: 25px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }

        .prediction-card.risk-Critical {
            background: linear-gradient(135deg, #d32f2f, #b71c1c);
        }

        .prediction-card.risk-High {
            background: linear-gradient(135deg, #f57c00, #e65100);
        }

        .prediction-card.risk-Medium {
            background: linear-gradient(135deg, #FFC107, #FFA000);
        }

        .prediction-card.risk-Low {
            background: linear-gradient(135deg, #388e3c, #2e7d32);
        }

        .prediction-card .value {
            font-size: 2.5em;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .prediction-card .label {
            font-size: 1em;
            opacity: 0.95;
        }

        .prediction-card .sublabel {
            font-size: 0.85em;
            opacity: 0.8;
            margin-top: 5px;
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
            border-bottom: 3px solid #a5d6a7;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
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

        /* Insights & Recommendations */
        .insight-item,
        .recommendation-item {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid var(--primary-green);
        }

        .insight-item.positive {
            border-left-color: var(--low);
            background: #e8f5e9;
        }

        .insight-item.negative {
            border-left-color: var(--critical);
            background: #ffebee;
        }

        .insight-item.warning {
            border-left-color: var(--medium);
            background: #fff8e1;
        }

        .insight-item.info {
            border-left-color: var(--info);
            background: #e3f2fd;
        }

        .insight-item h4,
        .recommendation-item h4 {
            margin: 0 0 8px 0;
            color: var(--dark-green);
            font-size: 1.1em;
        }

        .insight-item p,
        .recommendation-item p {
            margin: 0;
            color: #555;
            line-height: 1.6;
        }

        /* Recommendation Priority */
        .recommendation-item.priority-Critical {
            border-left-color: var(--critical);
            background: #ffebee;
        }

        .recommendation-item.priority-High {
            border-left-color: var(--high);
            background: #fff3e0;
        }

        .recommendation-item.priority-Medium {
            border-left-color: var(--medium);
            background: #fff8e1;
        }

        .recommendation-item.priority-Low {
            border-left-color: var(--low);
            background: #e8f5e9;
        }

        .recommendation-actions {
            margin-top: 10px;
        }

        .recommendation-actions ul {
            margin: 8px 0 0 20px;
            color: #555;
            line-height: 1.8;
        }

        /* Risk Assessment */
        .risk-meter {
            display: flex;
            align-items: center;
            gap: 15px;
            margin: 20px 0;
        }

        .risk-bar {
            flex: 1;
            height: 30px;
            background: #e0e0e0;
            border-radius: 15px;
            overflow: hidden;
            position: relative;
        }

        .risk-fill {
            height: 100%;
            transition: width 0.5s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .risk-fill.Critical {
            background: var(--critical);
        }

        .risk-fill.High {
            background: var(--high);
        }

        .risk-fill.Medium {
            background: var(--medium);
            color: #333;
        }

        .risk-fill.Low {
            background: var(--low);
        }

        /* Risk Factors */
        .risk-factors {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .risk-factor-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid var(--border);
        }

        .risk-factor-card.Critical {
            border-left-color: var(--critical);
        }

        .risk-factor-card.High {
            border-left-color: var(--high);
        }

        .risk-factor-card.Medium {
            border-left-color: var(--medium);
        }

        .risk-factor-card.Low {
            border-left-color: var(--low);
        }

        .risk-factor-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .risk-factor-title {
            font-weight: 600;
            color: var(--dark-green);
        }

        .risk-factor-impact {
            background: var(--border);
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.85em;
            font-weight: 600;
        }

        /* Intervention Plan */
        .intervention-timeline {
            margin-top: 20px;
        }

        .intervention-phase {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 5px solid var(--primary-green);
        }

        .phase-header {
            font-size: 1.2em;
            font-weight: 600;
            color: var(--dark-green);
            margin-bottom: 10px;
        }

        .phase-focus {
            color: var(--primary-green);
            font-weight: 600;
            margin-bottom: 10px;
        }

        .phase-activities ul {
            margin: 10px 0 0 20px;
            color: #555;
            line-height: 1.8;
        }

        /* Success Metrics */
        .success-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .metric-box {
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border: 2px solid var(--border);
        }

        .metric-value {
            font-size: 2em;
            font-weight: 700;
            color: var(--primary-green);
        }

        .metric-label {
            font-size: 0.9em;
            color: #666;
            margin-top: 5px;
        }

        /* Loading & Error */
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

        /* Print */
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
            }
        }

        /* Responsive */
        @media (max-width: 768px) {

            .charts-grid,
            .prediction-dashboard,
            .risk-factors,
            .success-metrics {
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
        <h1> Predictive Analysis & Forecasting</h1>

        <!-- Filters -->
        <div class="filters">
            <form id="predictive-form">
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
                        <label>Select Subject</label>
                        <select name="subject" id="subject-select"></select>
                    </div>
                </div>
                <div>
                    <button type="button" class="btn" onclick="fetchData()">
                        <i class="fas fa-crystal-ball"></i> Generate Predictions
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="window.print()">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                </div>
            </form>
        </div>

        <div id="loading" class="loading" style="display:none;">
            <i class="fas fa-spinner fa-spin"></i> Analyzing historical data and generating predictions...
        </div>

        <div id="error" class="error" style="display:none;"></div>

        <div id="content" style="display:none;">
            <!-- Prediction Dashboard -->
            <div class="prediction-dashboard" id="prediction-dashboard"></div>

            <!-- Success Metrics -->
            <div class="section-card">
                <h3 class="section-title">
                    <i class="fas fa-target"></i> Success Probability Metrics
                </h3>
                <div class="success-metrics" id="success-metrics"></div>
            </div>

            <!-- Charts -->
            <div class="charts-grid">
                <div class="chart-card">
                    <div class="chart-title">Performance Forecast & Scenarios</div>
                    <div class="chart-container">
                        <canvas id="forecast-chart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-title">AOI vs EOT Trend Analysis</div>
                    <div class="chart-container">
                        <canvas id="aoi-eot-chart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Risk Assessment -->
            <div class="section-card">
                <h3 class="section-title">
                    <i class="fas fa-exclamation-triangle"></i> Risk Assessment & Early Warning
                </h3>
                <div id="risk-assessment"></div>
            </div>

            <!-- Insights -->
            <div class="section-card">
                <h3 class="section-title">
                    <i class="fas fa-lightbulb"></i> Key Insights & Patterns
                </h3>
                <div id="insights-container"></div>
            </div>

            <!-- Recommendations -->
            <div class="section-card">
                <h3 class="section-title">
                    <i class="fas fa-list-check"></i> AI-Powered Recommendations
                </h3>
                <div id="recommendations-container"></div>
            </div>

            <!-- Intervention Plan -->
            <div class="section-card" id="intervention-section" style="display:none;">
                <h3 class="section-title">
                    <i class="fas fa-calendar-check"></i> Structured Intervention Plan
                </h3>
                <div class="intervention-timeline" id="intervention-plan"></div>
            </div>
        </div>
    </div>

    <script>
        let charts = {};
        let allStudents = [];
        let allSubjects = [];

        // Load students and subjects
        async function loadData() {
            try {
                const [studentsResp, subjectsResp] = await Promise.all([
                    fetch('api/fetch_students_list.php'),
                    fetch('api/fetch_subjects_list.php')
                ]);

                allStudents = await studentsResp.json();
                allSubjects = await subjectsResp.json();

                populateStudentSelect(allStudents);
                populateSubjectSelect(allSubjects);
            } catch (error) {
                console.error('Failed to load initial data:', error);
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

        function populateSubjectSelect(subjects) {
            const select = document.getElementById('subject-select');
            select.innerHTML = '';

            subjects.forEach(subject => {
                const option = document.createElement('option');
                option.value = subject;
                option.textContent = subject;
                select.appendChild(option);
            });
        }

        // Student search
        document.addEventListener('DOMContentLoaded', () => {
            loadData();

            document.getElementById('student-search').addEventListener('input', (e) => {
                const searchTerm = e.target.value.toLowerCase();
                const filtered = allStudents.filter(s => {
                    const fullName = `${s.first_name} ${s.last_name}`.toLowerCase();
                    return fullName.includes(searchTerm) || s.student_id.toLowerCase().includes(searchTerm);
                });
                populateStudentSelect(filtered);
            });
        });

        async function fetchData() {
            const form = document.getElementById('predictive-form');
            const params = new URLSearchParams(new FormData(form)).toString();

            document.getElementById('loading').style.display = 'block';
            document.getElementById('error').style.display = 'none';
            document.getElementById('content').style.display = 'none';

            try {
                const response = await fetch(`api/fetch_predictive_data.php?${params}`);
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
            renderPredictionCards(data);
            renderSuccessMetrics(data.successMetrics);
            renderCharts(data);
            renderRiskAssessment(data.riskAssessment);
            renderInsights(data.insights);
            renderRecommendations(data.recommendations);
            renderInterventionPlan(data.interventionPlan);
        }

        function renderPredictionCards(data) {
            const preds = data.predictions;
            const risk = data.riskAssessment;

            const html = `
                <div class="prediction-card">
                    <div class="value">${preds.ensemble}%</div>
                    <div class="label">Predicted Score</div>
                    <div class="sublabel">Next Term</div>
                </div>
                <div class="prediction-card">
                    <div class="value">${preds.expectedGrade}</div>
                    <div class="label">Expected Grade</div>
                    <div class="sublabel">${data.predictions.scenarios.realistic >= 50 ? 'Pass' : 'Fail'}</div>
                </div>
                <div class="prediction-card">
                    <div class="value">${preds.trendDirection}</div>
                    <div class="label">Performance Trend</div>
                    <div class="sublabel">Slope: ${preds.trendSlope}/term</div>
                </div>
                <div class="prediction-card risk-${risk.level}">
                    <div class="value">${risk.level}</div>
                    <div class="label">Risk Level</div>
                    <div class="sublabel">Score: ${risk.score}/100</div>
                </div>
                <div class="prediction-card">
                    <div class="value">${preds.confidence}%</div>
                    <div class="label">Model Confidence</div>
                    <div class="sublabel">R² = ${(preds.confidence / 100).toFixed(2)}</div>
                </div>
            `;

            document.getElementById('prediction-dashboard').innerHTML = html;
        }

        function renderSuccessMetrics(metrics) {
            const html = `
                <div class="metric-box">
                    <div class="metric-value">${metrics.passingProbability}%</div>
                    <div class="metric-label">Passing Probability</div>
                </div>
                <div class="metric-box">
                    <div class="metric-value">${metrics.gradeAProbability}%</div>
                    <div class="metric-label">Grade A Probability</div>
                </div>
                <div class="metric-box">
                    <div class="metric-value">${metrics.improvementNeeded}</div>
                    <div class="metric-label">Points Needed to Pass</div>
                </div>
                <div class="metric-box">
                    <div class="metric-value">${metrics.pointsToNextGrade || 0}</div>
                    <div class="metric-label">Points to Next Grade${metrics.nextGrade ? ' (' + metrics.nextGrade + ')' : ''}</div>
                </div>
                <div class="metric-box">
                    <div class="metric-value">${metrics.termsToImprovement || 'N/A'}</div>
                    <div class="metric-label">Terms to Next Grade</div>
                </div>
            `;

            document.getElementById('success-metrics').innerHTML = html;
        }

        function renderCharts(data) {
            // Forecast Chart
            if (charts.forecast) charts.forecast.destroy();
            const forecastCtx = document.getElementById('forecast-chart').getContext('2d');

            charts.forecast = new Chart(forecastCtx, {
                type: 'line',
                data: {
                    labels: data.chartData.labels,
                    datasets: [{
                            label: 'Historical Performance',
                            data: data.chartData.historical,
                            borderColor: '#2e7d32',
                            backgroundColor: 'rgba(46, 125, 50, 0.1)',
                            tension: 0.4,
                            pointRadius: 5,
                            pointBackgroundColor: '#2e7d32',
                            fill: true
                        },
                        {
                            label: 'Predicted (Realistic)',
                            data: data.chartData.predicted,
                            borderColor: '#1976D2',
                            borderDash: [5, 5],
                            tension: 0.4,
                            pointRadius: 5,
                            pointBackgroundColor: '#1976D2'
                        },
                        {
                            label: 'Optimistic Scenario',
                            data: data.chartData.optimistic,
                            borderColor: '#4CAF50',
                            borderDash: [2, 2],
                            tension: 0.4,
                            pointRadius: 3,
                            pointBackgroundColor: '#4CAF50'
                        },
                        {
                            label: 'Pessimistic Scenario',
                            data: data.chartData.pessimistic,
                            borderColor: '#F44336',
                            borderDash: [2, 2],
                            tension: 0.4,
                            pointRadius: 3,
                            pointBackgroundColor: '#F44336'
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
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom'
                        }
                    }
                }
            });

            // AOI vs EOT Chart
            if (charts.aoiEot) charts.aoiEot.destroy();
            const aoiEotCtx = document.getElementById('aoi-eot-chart').getContext('2d');

            // Filter out null values
            const aoiData = data.chartData.aoiTrend.filter(v => v !== null);
            const eotData = data.chartData.eotTrend.filter(v => v !== null);
            const labels = data.chartData.labels.slice(0, Math.max(aoiData.length, eotData.length));

            charts.aoiEot = new Chart(aoiEotCtx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                            label: 'AOI Average (Out of 3)',
                            data: aoiData,
                            borderColor: '#2196F3',
                            backgroundColor: 'rgba(33, 150, 243, 0.1)',
                            tension: 0.4,
                            yAxisID: 'y1'
                        },
                        {
                            label: 'EOT Score (Out of 100)',
                            data: eotData,
                            borderColor: '#FF9800',
                            backgroundColor: 'rgba(255, 152, 0, 0.1)',
                            tension: 0.4,
                            yAxisID: 'y2'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y1: {
                            type: 'linear',
                            position: 'left',
                            beginAtZero: true,
                            max: 3,
                            title: {
                                display: true,
                                text: 'AOI (0-3)'
                            }
                        },
                        y2: {
                            type: 'linear',
                            position: 'right',
                            beginAtZero: true,
                            max: 100,
                            title: {
                                display: true,
                                text: 'EOT (0-100)'
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    }
                }
            });
        }

        function renderRiskAssessment(risk) {
            let html = `
                <div class="risk-meter">
                    <strong style="width: 80px;">Risk Score:</strong>
                    <div class="risk-bar">
                        <div class="risk-fill ${risk.level}" style="width: ${risk.score}%;">
                            ${risk.score}/100
                        </div>
                    </div>
                    <strong style="width: 100px; text-align: right;">${risk.level} Risk</strong>
                </div>
                <p style="color: #666; margin: 10px 0;">${risk.description}</p>
            `;

            if (risk.factors && risk.factors.length > 0) {
                html += '<h4 style="margin-top: 20px; color: var(--dark-green);">Risk Factors Identified:</h4>';
                html += '<div class="risk-factors">';

                risk.factors.forEach(factor => {
                    html += `
                        <div class="risk-factor-card ${factor.severity}">
                            <div class="risk-factor-header">
                                <div class="risk-factor-title">${factor.factor}</div>
                                <div class="risk-factor-impact">+${factor.impact}</div>
                            </div>
                            <p style="color: #666; font-size: 0.9em; margin: 5px 0 0 0;">
                                ${factor.description}
                            </p>
                        </div>
                    `;
                });

                html += '</div>';
            }

            document.getElementById('risk-assessment').innerHTML = html;
        }

        function renderInsights(insights) {
            if (!insights || insights.length === 0) {
                document.getElementById('insights-container').innerHTML =
                    '<p style="color: #666;">No specific insights generated.</p>';
                return;
            }

            const html = insights.map(insight => `
                <div class="insight-item ${insight.type}">
                    <h4>${insight.title}</h4>
                    <p>${insight.description}</p>
                </div>
            `).join('');

            document.getElementById('insights-container').innerHTML = html;
        }

        function renderRecommendations(recommendations) {
            if (!recommendations || recommendations.length === 0) {
                document.getElementById('recommendations-container').innerHTML =
                    '<p style="color: #666;">No specific recommendations generated.</p>';
                return;
            }

            const html = recommendations.map(rec => `
                <div class="recommendation-item priority-${rec.priority}">
                    <h4>${rec.title}</h4>
                    <p><strong>Category:</strong> ${rec.category}</p>
                    <div class="recommendation-actions">
                        <strong>Action Items:</strong>
                        <ul>
                            ${rec.actions.map(action => `<li>${action}</li>`).join('')}
                        </ul>
                    </div>
                </div>
            `).join('');

            document.getElementById('recommendations-container').innerHTML = html;
        }

        function renderInterventionPlan(plan) {
            if (!plan || plan.length === 0) {
                document.getElementById('intervention-section').style.display = 'none';
                return;
            }

            document.getElementById('intervention-section').style.display = 'block';

            const html = plan.map(phase => `
                <div class="intervention-phase">
                    <div class="phase-header">${phase.phase}</div>
                    <div class="phase-focus">Focus: ${phase.focus}</div>
                    <div class="phase-activities">
                        <strong>Activities:</strong>
                        <ul>
                            ${phase.activities.map(activity => `<li>${activity}</li>`).join('')}
                        </ul>
                    </div>
                </div>
            `).join('');

            document.getElementById('intervention-plan').innerHTML = html;
        }
    </script>
</body>

</html>