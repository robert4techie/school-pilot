<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Visitor dashboard");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Dashboard - School Pilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <style>
        :root {
            --primary-green: #2E7D32;
            --light-green: #4CAF50;
            --pale-green: #E8F5E9;
            --accent-green: #1B5E20;
            --text-dark: #212121;
            --text-light: #FFFFFF;
            --gray-light: #EEEEEE;
            --gray-medium: #9E9E9E;
            --blue: #2196F3;
            --orange: #FF9800;
            --red: #f44336;
            --box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: #f8fafc;
            color: var(--text-dark);
            line-height: 1.6;
        }

        .content-container {
            max-width: 100%;
            margin: 20px auto;
            padding: 0 20px;
            margin-top: 80px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .page-header h3 {
            font-size: 28px;
            font-weight: 600;
            color: var(--primary-green);
        }

        .header-meta {
            text-align: right;
        }

        .header-meta #live-clock {
            font-size: 18px;
            font-weight: 500;
            color: var(--text-dark);
        }

        .header-meta #live-date {
            font-size: 14px;
            color: var(--gray-medium);
        }

        .dashboard-filters {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 8px 16px;
            border-radius: 6px;
            border: 1px solid #ddd;
            background-color: white;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: var(--transition);
        }

        .filter-btn:hover {
            background-color: var(--pale-green);
            border-color: var(--light-green);
        }

        .filter-btn.active {
            background-color: var(--primary-green);
            color: white;
            border-color: var(--primary-green);
        }
        
        #customDateFilter {
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 14px;
            background-color: white;
            cursor: pointer;
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background-color: white;
            border-radius: 10px;
            box-shadow: var(--box-shadow);
            padding: 25px;
            display: flex;
            align-items: center;
            gap: 20px;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        .stat-card .icon {
            font-size: 2.5em;
            padding: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-card .icon.users { background-color: rgba(46, 125, 50, 0.1); color: var(--primary-green); }
        .stat-card .icon.check-in { background-color: rgba(33, 150, 243, 0.1); color: var(--blue); }
        .stat-card .icon.clock { background-color: rgba(255, 152, 0, 0.1); color: var(--orange); }
        .stat-card .icon.building { background-color: rgba(156, 39, 176, 0.1); color: #9c27b0; }

        .stat-card .info h4 {
            font-size: 16px;
            color: var(--gray-medium);
            margin-bottom: 5px;
            font-weight: 500;
        }

        .stat-card .info p {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-dark);
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }

        .chart-card, .list-card {
            background-color: white;
            border-radius: 10px;
            box-shadow: var(--box-shadow);
            padding: 25px;
        }

        .chart-card h4, .list-card h4 {
            font-size: 18px;
            margin-bottom: 20px;
            color: var(--primary-green);
            font-weight: 600;
        }

        .chart-container {
            position: relative;
            height: 350px;
        }
        
        .list-card ul {
            list-style: none;
            max-height: 350px;
            overflow-y: auto;
            padding-right: 10px;
        }

        .list-card li {
            padding: 15px 0;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .list-card li:last-child { border-bottom: none; }

        .list-card .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--pale-green);
            color: var(--primary-green);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            flex-shrink: 0;
        }

        .list-card .visitor-info strong {
            display: block;
            font-size: 15px;
            color: var(--text-dark);
        }
        .list-card .visitor-info small {
            color: var(--gray-medium);
            font-size: 13px;
        }
        
        .dashboard-grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
        }

        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            border-radius: 10px;
            transition: opacity 0.3s ease;
        }
        .loading-overlay i {
            font-size: 2em;
            color: var(--primary-green);
        }
        .hidden {
            opacity: 0;
            pointer-events: none;
        }

        @media (max-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            .kpi-grid, .dashboard-grid-2 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <?php require_once 'nav.php'; ?>

    <div class="content-container">
        <div class="page-header">
            <h3>Visitor Dashboard</h3>
            <div class="header-meta">
                <div id="live-clock"></div>
                <div id="live-date"></div>
            </div>
        </div>

        <div class="dashboard-filters">
            <button class="filter-btn active" data-period="today">Today</button>
            <button class="filter-btn" data-period="week">This Week</button>
            <button class="filter-btn" data-period="month">This Month</button>
            <input type="text" id="customDateFilter" placeholder="Select Custom Range">
        </div>

        <!-- KPI Row -->
        <div class="kpi-grid">
            <div class="stat-card">
                <div class="icon users"><i class="fas fa-street-view"></i></div>
                <div class="info">
                    <h4>Visitors on Premises</h4>
                    <p id="kpi-on-premises">0</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon check-in"><i class="fas fa-user-check"></i></div>
                <div class="info">
                    <h4>Total Visits</h4>
                    <p id="kpi-total-visits">0</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon clock"><i class="fas fa-clock"></i></div>
                <div class="info">
                    <h4>Avg. Visit Duration</h4>
                    <p id="kpi-avg-duration">0m</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon building"><i class="fas fa-building"></i></div>
                <div class="info">
                    <h4>Unique Companies</h4>
                    <p id="kpi-unique-companies">0</p>
                </div>
            </div>
        </div>

        <!-- Main Charts Row -->
        <div class="dashboard-grid">
            <div class="chart-card">
                <h4>Visitor Traffic</h4>
                <div class="chart-container">
                    <div class="loading-overlay"><i class="fas fa-spinner fa-spin"></i></div>
                    <canvas id="trafficChart"></canvas>
                </div>
            </div>
            <div class="list-card">
                <h4>Currently on Premises</h4>
                <div class="chart-container" style="padding-right: 10px;">
                     <div class="loading-overlay"><i class="fas fa-spinner fa-spin"></i></div>
                     <ul id="activeVisitorList"></ul>
                </div>
            </div>
        </div>

        <!-- Breakdown Charts Row -->
        <div class="dashboard-grid-2">
            <div class="chart-card">
                <h4>Visits by Purpose</h4>
                <div class="chart-container">
                    <div class="loading-overlay"><i class="fas fa-spinner fa-spin"></i></div>
                    <canvas id="purposeChart"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <h4>Top 5 Hosts</h4>
                <div class="chart-container">
                    <div class="loading-overlay"><i class="fas fa-spinner fa-spin"></i></div>
                    <canvas id="topHostsChart"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <h4>Top 5 Visiting Companies</h4>
                <div class="chart-container">
                    <div class="loading-overlay"><i class="fas fa-spinner fa-spin"></i></div>
                    <canvas id="topCompaniesChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- Live Clock ---
            const clockEl = document.getElementById('live-clock');
            const dateEl = document.getElementById('live-date');
            function updateTime() {
                const now = new Date();
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                dateEl.textContent = now.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            }
            updateTime();
            setInterval(updateTime, 1000);

            // --- Chart instances ---
            let trafficChart, purposeChart, topHostsChart, topCompaniesChart;
            const chartColors = ['#2E7D32', '#4CAF50', '#81C784', '#FFB300', '#64B5F6', '#9575CD', '#F06292'];

            // --- Flatpickr for custom date range ---
            const datePicker = flatpickr("#customDateFilter", {
                mode: "range",
                dateFormat: "Y-m-d",
                onChange: function(selectedDates) {
                    if (selectedDates.length === 2) {
                        document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
                        const startDate = selectedDates[0].toISOString().split('T')[0];
                        const endDate = selectedDates[1].toISOString().split('T')[0];
                        updateDashboard('custom', startDate, endDate);
                    }
                }
            });

            // --- Filter button logic ---
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    datePicker.clear();
                    updateDashboard(this.dataset.period);
                });
            });

            // --- Main data fetching and rendering function ---
            async function updateDashboard(period = 'today', startDate = null, endDate = null) {
                showAllLoaders();
                let url = `api/visitors_dashboard_stats.php?period=${period}`;
                if (period === 'custom' && startDate && endDate) {
                    url += `&start_date=${startDate}&end_date=${endDate}`;
                }

                try {
                    const response = await fetch(url);
                    if (!response.ok) throw new Error(`Network response was not ok: ${response.statusText}`);
                    const data = await response.json();

                    // Update KPIs
                    document.getElementById('kpi-on-premises').textContent = data.kpi.on_premises;
                    document.getElementById('kpi-total-visits').textContent = data.kpi.total_visits;
                    document.getElementById('kpi-avg-duration').textContent = `${data.kpi.avg_duration}m`;
                    document.getElementById('kpi-unique-companies').textContent = data.kpi.unique_companies;

                    // Update Active Visitor List
                    updateActiveVisitorList(data.lists.active_visitors);

                    // Update Charts
                    renderTrafficChart(data.charts.traffic, period);
                    renderPurposeChart(data.charts.purpose);
                    renderTopHostsChart(data.charts.top_hosts);
                    renderTopCompaniesChart(data.charts.top_companies);

                } catch (error) {
                    console.error('Failed to fetch dashboard data:', error);
                    // You could show an error message on the UI here
                } finally {
                   hideAllLoaders();
                }
            }
            
            function showAllLoaders() {
                document.querySelectorAll('.loading-overlay').forEach(el => el.classList.remove('hidden'));
            }

            function hideAllLoaders() {
                 document.querySelectorAll('.loading-overlay').forEach(el => el.classList.add('hidden'));
            }

            function updateActiveVisitorList(visitors) {
                const listEl = document.getElementById('activeVisitorList');
                listEl.innerHTML = '';
                if (visitors.length === 0) {
                    listEl.innerHTML = '<li style="justify-content: center; color: var(--gray-medium);">No visitors currently on premises.</li>';
                    return;
                }
                visitors.forEach(v => {
                    const li = document.createElement('li');
                    const initial = (v.first_name ? v.first_name[0] : '') + (v.last_name ? v.last_name[0] : '');
                    li.innerHTML = `
                        <div class="avatar">${initial}</div>
                        <div class="visitor-info">
                            <strong>${v.first_name} ${v.last_name}</strong>
                            <small>To see: ${v.host} | Checked in: ${v.checkin_time}</small>
                        </div>
                    `;
                    listEl.appendChild(li);
                });
            }

            // --- Chart Rendering Functions ---
            const chartDefaultOptions = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    y: { ticks: { color: '#9E9E9E' }, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { ticks: { color: '#9E9E9E' }, grid: { display: false } }
                }
            };
            
            function renderTrafficChart(data, period) {
                const ctx = document.getElementById('trafficChart').getContext('2d');
                let labels;
                let chartData;

                if (period === 'today') {
                    labels = Array.from({ length: 24 }, (_, i) => `${i}:00`);
                    chartData = new Array(24).fill(0);
                    data.forEach(item => { chartData[item.label] = item.count; });
                } else {
                    labels = data.map(item => item.label);
                    chartData = data.map(item => item.count);
                }

                if (trafficChart) trafficChart.destroy();
                trafficChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Visitors',
                            data: chartData,
                            borderColor: '#2E7D32',
                            backgroundColor: 'rgba(46, 125, 50, 0.1)',
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '#2E7D32'
                        }]
                    },
                    options: chartDefaultOptions
                });
            }

            function renderPurposeChart(data) {
                const ctx = document.getElementById('purposeChart').getContext('2d');
                if (purposeChart) purposeChart.destroy();
                purposeChart = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: data.map(item => item.visit_purpose),
                        datasets: [{
                            data: data.map(item => item.count),
                            backgroundColor: chartColors,
                            borderColor: '#fff',
                            borderWidth: 2
                        }]
                    },
                    options: { ...chartDefaultOptions, scales: { x: { display: false }, y: { display: false } } }
                });
            }

            function renderTopHostsChart(data) {
                const ctx = document.getElementById('topHostsChart').getContext('2d');
                if (topHostsChart) topHostsChart.destroy();
                topHostsChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: data.map(item => item.host),
                        datasets: [{
                            label: 'Number of Visits',
                            data: data.map(item => item.count),
                            backgroundColor: chartColors,
                        }]
                    },
                    options: { ...chartDefaultOptions, indexAxis: 'y', plugins: { legend: { display: false } } }
                });
            }

            function renderTopCompaniesChart(data) {
                const ctx = document.getElementById('topCompaniesChart').getContext('2d');
                if (topCompaniesChart) topCompaniesChart.destroy();
                topCompaniesChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: data.map(item => item.company),
                        datasets: [{
                            label: 'Number of Visits',
                            data: data.map(item => item.count),
                            backgroundColor: chartColors.slice().reverse(),
                        }]
                    },
                    options: { ...chartDefaultOptions, indexAxis: 'y', plugins: { legend: { display: false } } }
                });
            }

            // --- Initial Load ---
            updateDashboard();
            // Optional: Auto-refresh data every 60 seconds
            setInterval(() => {
                const activeFilter = document.querySelector('.filter-btn.active');
                if (activeFilter) {
                    updateDashboard(activeFilter.dataset.period);
                }
                // Note: Does not auto-refresh custom date range
            }, 60000);
        });
    </script>
</body>
</html>
