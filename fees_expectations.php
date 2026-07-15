<?php
require_once 'conn.php';
require_once 'auth.php';
require_once 'tracking.php';
$tracker->trackAction("Fees Expectations");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Expectations Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-green: #2e7d32;
            --light-green: #81c784;
            --dark-green: #1b5e20;
            --accent-green: #a5d6a7;
            --background: #f5f5f5;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--background);
        }
        
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
            margin-bottom: 20px;
        }
        
        .card:hover {
            transform: translateY(-5px);
        }
        
        .card-header {
            background-color: var(--primary-green);
            color: white;
            border-radius: 10px 10px 0 0 !important;
            font-weight: 600;
        }
        
        .btn-primary {
            background-color: var(--primary-green);
            border-color: var(--primary-green);
        }
        
        .btn-primary:hover {
            background-color: var(--dark-green);
            border-color: var(--dark-green);
        }
        
        .badge-success {
            background-color: var(--light-green);
        }
        
        .progress {
            height: 10px;
            border-radius: 5px;
        }
        
        .progress-bar {
            background-color: var(--primary-green);
        }
        
        .table th {
            background-color: var(--accent-green);
        }
        
        .filter-section {
            background-color: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .kpi-card {
            text-align: center;
            padding: 15px;
            border-radius: 10px;
            background-color: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .kpi-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary-green);
        }
        
        .kpi-label {
            font-size: 14px;
            color: #666;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Fee Expectations Dashboard</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary">Export</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary">Print</button>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-circle"></i> New Projection
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filter-section mb-4">
                    <div class="row">
                        <div class="col-md-3">
                            <label for="reportPeriod" class="form-label">Reporting Period</label>
                            <select class="form-select" id="reportPeriod">
                                <option selected>Monthly</option>
                                <option>Weekly</option>
                                <option>Termly</option>
                                <option>Custom</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="academicYear" class="form-label">Academic Year</label>
                            <select class="form-select" id="academicYear">
                                <option selected>2023-2024</option>
                                <option>2022-2023</option>
                                <option>2021-2022</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="classFilter" class="form-label">Class</label>
                            <select class="form-select" id="classFilter">
                                <option selected>All Classes</option>
                                <option>Grade 1</option>
                                <option>Grade 2</option>
                                <option>Grade 3</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button class="btn btn-primary w-100">
                                <i class="bi bi-funnel"></i> Apply Filters
                            </button>
                        </div>
                    </div>
                </div>

                <!-- KPI Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="kpi-card">
                            <div class="kpi-value">$124,580</div>
                            <div class="kpi-label">Expected Revenue</div>
                            <small class="text-success"><i class="bi bi-arrow-up"></i> 12% vs last period</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="kpi-card">
                            <div class="kpi-value">$98,430</div>
                            <div class="kpi-label">Actual Collections</div>
                            <small class="text-success"><i class="bi bi-arrow-up"></i> 8% vs last period</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="kpi-card">
                            <div class="kpi-value">79%</div>
                            <div class="kpi-label">Collection Rate</div>
                            <small class="text-danger"><i class="bi bi-arrow-down"></i> 3% vs last period</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="kpi-card">
                            <div class="kpi-value">42</div>
                            <div class="kpi-label">Defaulters</div>
                            <small class="text-danger"><i class="bi bi-arrow-up"></i> 5% vs last period</small>
                        </div>
                    </div>
                </div>

                <!-- Main Charts -->
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span>Expected vs Actual Collections</span>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-light">Monthly</button>
                                    <button class="btn btn-light">Termly</button>
                                    <button class="btn btn-light active">Annual</button>
                                </div>
                            </div>
                            <div class="card-body">
                                <canvas id="expectationsChart" height="300"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header">
                                Collection Status
                            </div>
                            <div class="card-body">
                                <canvas id="collectionStatusChart" height="300"></canvas>
                                <div class="mt-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>Paid</span>
                                        <span>$98,430 (79%)</span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar" role="progressbar" style="width: 79%"></div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between mb-1 mt-3">
                                        <span>Pending</span>
                                        <span>$15,720 (13%)</span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar bg-warning" role="progressbar" style="width: 13%"></div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between mb-1 mt-3">
                                        <span>Overdue</span>
                                        <span>$10,430 (8%)</span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar bg-danger" role="progressbar" style="width: 8%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Class Breakdown -->
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                Class Breakdown
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Class</th>
                                                <th>Expected</th>
                                                <th>Actual</th>
                                                <th>% Collected</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>Grade 1</td>
                                                <td>$32,000</td>
                                                <td>$28,500</td>
                                                <td><span class="badge bg-success">89%</span></td>
                                            </tr>
                                            <tr>
                                                <td>Grade 2</td>
                                                <td>$30,500</td>
                                                <td>$22,300</td>
                                                <td><span class="badge bg-warning">73%</span></td>
                                            </tr>
                                            <tr>
                                                <td>Grade 3</td>
                                                <td>$28,750</td>
                                                <td>$20,100</td>
                                                <td><span class="badge bg-danger">70%</span></td>
                                            </tr>
                                            <tr>
                                                <td>Grade 4</td>
                                                <td>$33,330</td>
                                                <td>$27,530</td>
                                                <td><span class="badge bg-success">83%</span></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                Defaulter Analysis
                            </div>
                            <div class="card-body">
                                <canvas id="defaulterChart" height="250"></canvas>
                                <div class="mt-3">
                                    <div class="alert alert-warning">
                                        <i class="bi bi-exclamation-triangle-fill"></i> <strong>Pattern Detected:</strong> 
                                        65% of defaulters are from Grade 2 & 3 with payment plans
                                    </div>
                                    <button class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-envelope"></i> Send Reminders
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary ms-2">
                                        <i class="bi bi-file-earmark-text"></i> Generate Report
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Scenario Modeling -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                Scenario Modeling
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Enrollment Change</label>
                                            <input type="range" class="form-range" min="-20" max="20" step="5" id="enrollmentRange">
                                            <div class="d-flex justify-content-between">
                                                <span>-20%</span>
                                                <span id="enrollmentValue">0%</span>
                                                <span>+20%</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Discount Policy</label>
                                            <select class="form-select" id="discountPolicy">
                                                <option value="0">No Discount</option>
                                                <option value="5">5% Early Payment</option>
                                                <option value="10">10% Sibling Discount</option>
                                                <option value="15">15% Staff Discount</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Payment Plan</label>
                                            <select class="form-select" id="paymentPlan">
                                                <option value="100">Full Payment (100%)</option>
                                                <option value="50">Two Installments (50% each)</option>
                                                <option value="33">Three Installments (33% each)</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="card bg-light">
                                            <div class="card-body text-center">
                                                <h5 class="card-title">Current Projection</h5>
                                                <h2 class="text-primary">$124,580</h2>
                                                <p class="card-text">Based on current enrollment and policies</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="card bg-light">
                                            <div class="card-body text-center">
                                                <h5 class="card-title">Modeled Projection</h5>
                                                <h2 id="modeledProjection" class="text-success">$124,580</h2>
                                                <p class="card-text" id="modeledChange">No changes applied</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-center mt-3">
                                    <button class="btn btn-primary">
                                        <i class="bi bi-save"></i> Save Scenario
                                    </button>
                                    <button class="btn btn-outline-secondary ms-2">
                                        <i class="bi bi-arrow-counterclockwise"></i> Reset
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Expectations vs Actual Chart
        const expectationsCtx = document.getElementById('expectationsChart').getContext('2d');
        const expectationsChart = new Chart(expectationsCtx, {
            type: 'bar',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [
                    {
                        label: 'Expected',
                        data: [9500, 10200, 11000, 10500, 11500, 12000, 11800, 12500, 13000, 12800, 13500, 14000],
                        backgroundColor: '#2e7d32',
                        borderRadius: 5
                    },
                    {
                        label: 'Actual',
                        data: [8200, 8900, 9500, 9200, 9800, 10500, 10000, 11000, 11500, 11200, 12000, 12500],
                        backgroundColor: '#81c784',
                        borderRadius: 5
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Collection Status Chart
        const collectionStatusCtx = document.getElementById('collectionStatusChart').getContext('2d');
        const collectionStatusChart = new Chart(collectionStatusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Paid', 'Pending', 'Overdue'],
                datasets: [{
                    data: [79, 13, 8],
                    backgroundColor: [
                        '#2e7d32',
                        '#ffc107',
                        '#dc3545'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + context.raw + '%';
                            }
                        }
                    }
                },
                cutout: '70%'
            }
        });

        // Defaulter Chart
        const defaulterCtx = document.getElementById('defaulterChart').getContext('2d');
        const defaulterChart = new Chart(defaulterCtx, {
            type: 'line',
            data: {
                labels: ['Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6'],
                datasets: [{
                    label: 'Defaulters',
                    data: [5, 12, 15, 8, 10, 7],
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Defaulters'
                        }
                    }
                }
            }
        });

        // Scenario Modeling Interaction
        const enrollmentRange = document.getElementById('enrollmentRange');
        const enrollmentValue = document.getElementById('enrollmentValue');
        const discountPolicy = document.getElementById('discountPolicy');
        const paymentPlan = document.getElementById('paymentPlan');
        const modeledProjection = document.getElementById('modeledProjection');
        const modeledChange = document.getElementById('modeledChange');

        const baseProjection = 124580;

        function updateScenario() {
            const enrollmentChange = parseInt(enrollmentRange.value);
            const discount = parseInt(discountPolicy.value);
            const installments = parseInt(paymentPlan.value);
            
            // Simple calculation for demo purposes
            let newProjection = baseProjection * (1 + enrollmentChange/100);
            newProjection = newProjection * (1 - discount/100);
            
            // Installments might affect collection rate (simplified)
            if (installments < 100) {
                newProjection = newProjection * 0.95; // Assuming 5% reduction for installments
            }
            
            modeledProjection.textContent = '$' + Math.round(newProjection).toLocaleString();
            
            const change = ((newProjection - baseProjection) / baseProjection * 100).toFixed(1);
            if (change > 0) {
                modeledChange.innerHTML = `<span class="text-success">+${change}% increase</span> from current projection`;
            } else if (change < 0) {
                modeledChange.innerHTML = `<span class="text-danger">${change}% decrease</span> from current projection`;
            } else {
                modeledChange.textContent = 'No changes applied';
            }
        }

        enrollmentRange.addEventListener('input', function() {
            enrollmentValue.textContent = this.value + '%';
            updateScenario();
        });

        discountPolicy.addEventListener('change', updateScenario);
        paymentPlan.addEventListener('change', updateScenario);
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>