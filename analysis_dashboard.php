<?php
require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Results Analysis Dashboard");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Results Analysis Dashboard | School Pilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        :root {
            --primary-green: #2e7d32;
            --light-green: #a5d6a7;
            --dark-green: #1b5e20;
            --background-color: #f1f8e9;
            --card-background: #ffffff;
            --text-color: #212121;
            --icon-color: #ffffff;
            --icon-bg: #2e7d32;
        }
        body {
            margin: 0;
            padding: 40px;
            background-color: var(--background-color);
            color: var(--text-color);
        }
        .container {
            max-width: 100%;
            margin: 0 auto;
            margin-top: 50px;
        }
        h1 {
            font-size: 2.8em;
            font-weight: 700;
            color: var(--dark-green);
            text-align: center;
            margin-bottom: 10px;
        }
        p.subtitle {
            font-size: 1.1em;
            color: #666;
            margin-bottom: 40px;
            text-align: center;
        }
        /* KPI Cards Section */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        .kpi-card {
            background-color: var(--card-background);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .kpi-icon {
            font-size: 2em;
            color: var(--icon-color);
            background-color: var(--icon-bg);
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .kpi-info .value {
            font-size: 2em;
            font-weight: 700;
            color: var(--dark-green);
        }
        .kpi-info .label {
            font-size: 0.95em;
            color: #555;
        }
        /* Menu Cards Section */
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
        }
        .menu-card {
            display: block;
            background-color: var(--card-background);
            padding: 30px;
            border-radius: 12px;
            text-decoration: none;
            color: var(--text-color);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border-left: 5px solid var(--light-green);
        }
        .menu-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.1);
            border-left-color: var(--primary-green);
        }
        .menu-card-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        .menu-card-header .icon {
            font-size: 1.5em;
            color: var(--primary-green);
        }
        .menu-card-header .title {
            font-size: 1.3em;
            font-weight: 600;
            color: var(--dark-green);
        }
        .menu-card .description {
            font-size: 0.95em;
            color: #666;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <?php require_once 'nav.php'?>
    <div class="container">
        <h1>Results Analysis Dashboard</h1>
        <p class="subtitle">Get an overview of school performance or select a tool for detailed analysis.</p>
        
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon"><i class="fas fa-users"></i></div>
                <div class="kpi-info">
                    <div class="value" id="kpi-total-students">-</div>
                    <div class="label">Total Students (S1-S4)</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon"><i class="fas fa-chart-line"></i></div>
                <div class="kpi-info">
                    <div class="value" id="kpi-pass-rate">-</div>
                    <div class="label">Overall Pass Rate</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon"><i class="fas fa-award"></i></div>
                <div class="kpi-info">
                    <div class="value" id="kpi-top-subject">-</div>
                    <div class="label">Top Subject</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon"><i class="fas fa-flag"></i></div>
                <div class="kpi-info">
                    <div class="value" id="kpi-bottom-subject">-</div>
                    <div class="label">Improvement Focus</div>
                </div>
            </div>
        </div>

        <div class="menu-grid">
            <a href="comparative_analysis.php" class="menu-card">
                <div class="menu-card-header">
                    <i class="fas fa-layer-group icon"></i>
                    <span class="title">Comparative Analysis</span>
                </div>
                <p class="description">Compare performance between different groups, classes, and streams to identify trends and patterns.</p>
            </a>
            <a href="diagnostic_analysis.php" class="menu-card">
                <div class="menu-card-header">
                    <i class="fas fa-user-doctor icon"></i>
                    <span class="title">Diagnostic Analysis</span>
                </div>
                <p class="description">Drill down into a single student's performance across all subjects and terms to identify strengths and weaknesses.</p>
            </a>
            <a href="predictive_analysis.php" class="menu-card">
                <div class="menu-card-header">
                    <i class="fas fa-wand-magic-sparkles icon"></i>
                    <span class="title">Predictive Analysis</span>
                </div>
                <p class="description">Forecast a student's future performance based on their historical data and get AI-powered recommendations.</p>
            </a>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            try {
                const response = await fetch('api/fetch_dashboard_stats.php');
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                document.getElementById('kpi-total-students').textContent = data.totalStudents;
                document.getElementById('kpi-pass-rate').textContent = data.overallPassRate;
                document.getElementById('kpi-top-subject').textContent = data.topSubject;
                document.getElementById('kpi-bottom-subject').textContent = data.bottomSubject;

            } catch (error) {
                console.error('Error fetching dashboard stats:', error);
                // Display friendly error on the cards
                document.getElementById('kpi-pass-rate').textContent = 'N/A';
                document.getElementById('kpi-top-subject').textContent = 'N/A';
                document.getElementById('kpi-bottom-subject').textContent = 'N/A';
            }
        });
    </script>
</body>
</html>
