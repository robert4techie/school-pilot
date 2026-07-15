<?php
require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("View fees payments");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-green: #2e7d32;
            --secondary-green: #388e3c;
            --light-green: #81c784;
            --lighter-green: #e8f5e9;
            --dark-green: #1b5e20;
            --accent-green: #4caf50;
        }
        
        body {
            background-color: #f5f5f5;
        }
        
        .main-content {
            padding: 2rem;
        }
        
        .card {
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
        }
        
        .card-header {
            background-color: white;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            font-weight: 600;
            padding: 1.25rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .btn-primary {
            background-color: var(--primary-green);
            border-color: var(--primary-green);
        }
        
        .btn-primary:hover {
            background-color: var(--secondary-green);
            border-color: var(--secondary-green);
        }
        
        .btn-outline-primary {
            color: var(--primary-green);
            border-color: var(--primary-green);
        }
        
        .btn-outline-primary:hover {
            background-color: var(--primary-green);
            color: white;
        }
        
        .badge-completed {
            background-color: var(--light-green);
            color: var(--dark-green);
        }
        
        .badge-pending {
            background-color: #fff3e0;
            color: #e65100;
        }
        
        .badge-partial {
            background-color: #e3f2fd;
            color: #1565c0;
        }
        
        .search-box {
            position: relative;
        }
        
        .search-box i {
            position: absolute;
            top: 12px;
            left: 12px;
            color: #6c757d;
        }
        
        .search-box input {
            padding-left: 35px;
        }
        
        .table th {
            background-color: var(--lighter-green);
            color: var(--dark-green);
            font-weight: 600;
            border-top: none;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        .payment-timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .payment-timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background-color: var(--light-green);
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 20px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -30px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background-color: var(--primary-green);
            border: 2px solid white;
        }
        
        .timeline-date {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .timeline-amount {
            font-weight: 600;
            color: var(--dark-green);
        }
        
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 5px;
        }
        
        .status-completed {
            background-color: var(--light-green);
        }
        
        .status-pending {
            background-color: #ffb74d;
        }
        
        .status-partial {
            background-color: #64b5f6;
        }
        
        .action-dropdown .dropdown-menu {
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .action-dropdown .dropdown-item {
            padding: 0.5rem 1.5rem;
        }
        
        .action-dropdown .dropdown-item i {
            width: 20px;
            text-align: center;
            margin-right: 8px;
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .table-responsive {
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 ms-sm-auto main-content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="h4 mb-0">Payment Management</h2>
                    <button class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> New Payment
                    </button>
                </div>

                <!-- Advanced Search Card -->
                <div class="card">
                    <div class="card-header">
                        <span>Advanced Search</span>
                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#searchCollapse">
                            <i class="bi bi-chevron-down"></i> Toggle
                        </button>
                    </div>
                    <div class="card-body collapse show" id="searchCollapse">
                        <form>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="studentSearch" class="form-label">Student</label>
                                    <select class="form-select" id="studentSearch">
                                        <option selected>All Students</option>
                                        <option>John Smith (Grade 10)</option>
                                        <option>Sarah Johnson (Grade 11)</option>
                                        <option>Michael Brown (Grade 9)</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="classSearch" class="form-label">Class</label>
                                    <select class="form-select" id="classSearch">
                                        <option selected>All Classes</option>
                                        <option>Grade 10</option>
                                        <option>Grade 11</option>
                                        <option>Grade 9</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="dateFrom" class="form-label">From Date</label>
                                    <input type="date" class="form-control" id="dateFrom">
                                </div>
                                <div class="col-md-3">
                                    <label for="dateTo" class="form-label">To Date</label>
                                    <input type="date" class="form-control" id="dateTo">
                                </div>
                                <div class="col-md-3">
                                    <label for="statusSearch" class="form-label">Payment Status</label>
                                    <select class="form-select" id="statusSearch">
                                        <option selected>All Statuses</option>
                                        <option>Completed</option>
                                        <option>Pending</option>
                                        <option>Partial</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="amountRange" class="form-label">Amount Range</label>
                                    <select class="form-select" id="amountRange">
                                        <option selected>Any Amount</option>
                                        <option>Below $100</option>
                                        <option>$100 - $500</option>
                                        <option>Above $500</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <div class="d-flex justify-content-end">
                                        <button type="reset" class="btn btn-outline-secondary me-2">
                                            <i class="bi bi-arrow-counterclockwise"></i> Reset
                                        </button>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-search"></i> Search
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Payment Records Card -->
                <div class="card">
                    <div class="card-header">
                        <span>Payment Records</span>
                        <div class="d-flex align-items-center">
                            <div class="search-box me-3">
                                <i class="bi bi-search"></i>
                                <input type="text" class="form-control form-control-sm" placeholder="Search payments...">
                            </div>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="exportDropdown" data-bs-toggle="dropdown">
                                    <i class="bi bi-download"></i> Export
                                </button>
                                <ul class="dropdown-menu" aria-labelledby="exportDropdown">
                                    <li><a class="dropdown-item" href="#"><i class="bi bi-file-earmark-excel"></i> Excel</a></li>
                                    <li><a class="dropdown-item" href="#"><i class="bi bi-file-earmark-pdf"></i> PDF</a></li>
                                    <li><a class="dropdown-item" href="#"><i class="bi bi-file-earmark-text"></i> CSV</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Payment ID <i class="bi bi-arrow-down-up"></i></th>
                                        <th>Student <i class="bi bi-arrow-down-up"></i></th>
                                        <th>Class <i class="bi bi-arrow-down-up"></i></th>
                                        <th>Date <i class="bi bi-arrow-down-up"></i></th>
                                        <th>Amount <i class="bi bi-arrow-down-up"></i></th>
                                        <th>Status <i class="bi bi-arrow-down-up"></i></th>
                                        <th>Method <i class="bi bi-arrow-down-up"></i></th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>#PAY-2023-001</td>
                                        <td>John Smith</td>
                                        <td>Grade 10</td>
                                        <td>15 Oct 2023</td>
                                        <td>$450.00</td>
                                        <td><span class="status-indicator status-completed"></span><span class="badge badge-completed">Completed</span></td>
                                        <td>Credit Card</td>
                                        <td>
                                            <div class="dropdown action-dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                    <i class="bi bi-three-dots-vertical"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li><a class="dropdown-item" href="#"><i class="bi bi-eye"></i> View Details</a></li>
                                                    <li><a class="dropdown-item" href="#"><i class="bi bi-receipt"></i> Reprint Receipt</a></li>
                                                    <li><a class="dropdown-item" href="#"><i class="bi bi-pencil"></i> Edit</a></li>
                                                    <li><a class="dropdown-item" href="#"><i class="bi bi-trash"></i> Delete</a></li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>#PAY-2023-002</td>
                                        <td>Sarah Johnson</td>
                                        <td>Grade 11</td>
                                        <td>14 Oct 2023</td>
                                        <td>$300.00</td>
                                        <td><span class="status-indicator status-pending"></span><span class="badge badge-pending">Pending</span></td>
                                        <td>Bank Transfer</td>
                                        <td>
                                            <div class="dropdown action-dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                    <i class="bi bi-three-dots-vertical"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li><a class="dropdown-item" href="#"><i class="bi bi-eye"></i> View Details</a></li>
                                                    <li><a class="dropdown-item" href="#"><i class="bi bi-receipt"></i> Reprint Receipt</a></li>
                                                    <li><a class="dropdown-item" href="#"><i class="bi bi-pencil"></i> Edit</a></li>
                                                    <li><a class="dropdown-item" href="#"><i class="bi bi-trash"></i> Delete</a></li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>#PAY-2023-003</td>
                                        <td>Michael Brown</td>
                                        <td>Grade 9</td>
                                        <td>12 Oct 2023</td>
                                        <td>$200.00</td>
                                        <td><span class="status-indicator status-partial"></span><span class="badge badge-partial">Partial</span></td>
                                        <td>Cash</td>
                                        <td>
                                            <div class="dropdown action-dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                    <i class="bi bi-three-dots-vertical"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li><a class="dropdown-item" href="#"><i class="bi bi-eye"></i> View Details</a></li>
                                                    <li><a class="dropdown-item" href="#"><i class="bi bi-receipt"></i> Reprint Receipt</a></li>
                                                    <li><a class="dropdown-item" href="#"><i class="bi bi-pencil"></i> Edit</a></li>
                                                    <li><a class="dropdown-item" href="#"><i class="bi bi-trash"></i> Delete</a></li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>#PAY-2023-004</td>
                                        <td>Emily Davis</td>
                                        <td>Grade 10</td>
                                        <td>10 Oct 2023</td>
                                        <td>$500.00</td>
                                        <td><span class="status-indicator status-completed"></span><span class="badge badge-completed">Completed</span></td>
                                        <td>Credit Card</td>
                                        <td>
                                            <div class="dropdown action-dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                    <i class="bi bi-three-dots-vertical"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li><a class="dropdown-item" href="#"><i class="bi bi-eye"></i> View Details</a></li>
                                                    <li><a class="dropdown-item" href="#"><i class="bi bi-receipt"></i> Reprint Receipt</a></li>
                                                    <li><a class="dropdown-item" href="#"><i class="bi bi-pencil"></i> Edit</a></li>
                                                    <li><a class="dropdown-item" href="#"><i class="bi bi-trash"></i> Delete</a></li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center">
                                <li class="page-item disabled">
                                    <a class="page-link" href="#" tabindex="-1">Previous</a>
                                </li>
                                <li class="page-item active"><a class="page-link" href="#">1</a></li>
                                <li class="page-item"><a class="page-link" href="#">2</a></li>
                                <li class="page-item"><a class="page-link" href="#">3</a></li>
                                <li class="page-item">
                                    <a class="page-link" href="#">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                </div>

                <!-- Payment Details and History -->
                <div class="row">
                    <!-- Payment Details Card -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <span>Payment Details</span>
                            </div>
                            <div class="card-body">
                                <div class="text-center py-4" id="noSelection">
                                    <i class="bi bi-credit-card-2-front" style="font-size: 3rem; color: #6c757d;"></i>
                                    <p class="mt-3">Select a payment to view details</p>
                                </div>
                                <div id="paymentDetails" style="display: none;">
                                    <div class="d-flex justify-content-between mb-3">
                                        <h5 class="mb-0">Payment #PAY-2023-001</h5>
                                        <span class="badge badge-completed">Completed</span>
                                    </div>
                                    <hr>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <p class="mb-1"><strong>Student:</strong></p>
                                            <p>John Smith (Grade 10)</p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mb-1"><strong>Date:</strong></p>
                                            <p>15 Oct 2023, 10:30 AM</p>
                                        </div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <p class="mb-1"><strong>Payment Method:</strong></p>
                                            <p>Credit Card (Visa ending in 4242)</p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mb-1"><strong>Transaction ID:</strong></p>
                                            <p>ch_1JXyZt2eZvKYlo2C0ZJXyZt2</p>
                                        </div>
                                    </div>
                                    <div class="table-responsive mb-4">
                                        <table class="table table-bordered">
                                            <thead>
                                                <tr>
                                                    <th>Fee Type</th>
                                                    <th>Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td>Tuition Fee</td>
                                                    <td>$350.00</td>
                                                </tr>
                                                <tr>
                                                    <td>Library Fee</td>
                                                    <td>$50.00</td>
                                                </tr>
                                                <tr>
                                                    <td>Activity Fee</td>
                                                    <td>$50.00</td>
                                                </tr>
                                                <tr class="table-active">
                                                    <td><strong>Total</strong></td>
                                                    <td><strong>$450.00</strong></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <button class="btn btn-outline-primary">
                                            <i class="bi bi-receipt"></i> Reprint Receipt
                                        </button>
                                        <button class="btn btn-outline-secondary">
                                            <i class="bi bi-printer"></i> Print
                                        </button>
                                        <button class="btn btn-outline-danger">
                                            <i class="bi bi-envelope"></i> Email Receipt
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment History Card -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <span>Payment History</span>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="showAllHistory">
                                    <label class="form-check-label" for="showAllHistory">Show All Students</label>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="text-center py-4" id="noHistory">
                                    <i class="bi bi-clock-history" style="font-size: 3rem; color: #6c757d;"></i>
                                    <p class="mt-3">Select a student to view payment history</p>
                                </div>
                                <div id="paymentHistory" style="display: none;">
                                    <h5 class="mb-4">John Smith (Grade 10)</h5>
                                    <div class="payment-timeline">
                                        <div class="timeline-item">
                                            <div class="d-flex justify-content-between">
                                                <div>
                                                    <h6 class="mb-1">Tuition Fee Payment</h6>
                                                    <p class="timeline-date mb-1">15 Oct 2023</p>
                                                </div>
                                                <span class="timeline-amount">$450.00</span>
                                            </div>
                                            <p class="text-muted mb-0">Completed via Credit Card</p>
                                        </div>
                                        <div class="timeline-item">
                                            <div class="d-flex justify-content-between">
                                                <div>
                                                    <h6 class="mb-1">Activity Fee Payment</h6>
                                                    <p class="timeline-date mb-1">5 Sep 2023</p>
                                                </div>
                                                <span class="timeline-amount">$75.00</span>
                                            </div>
                                            <p class="text-muted mb-0">Completed via Bank Transfer</p>
                                        </div>
                                        <div class="timeline-item">
                                            <div class="d-flex justify-content-between">
                                                <div>
                                                    <h6 class="mb-1">Tuition Fee Payment</h6>
                                                    <p class="timeline-date mb-1">12 Aug 2023</p>
                                                </div>
                                                <span class="timeline-amount">$400.00</span>
                                            </div>
                                            <p class="text-muted mb-0">Completed via Credit Card</p>
                                        </div>
                                        <div class="timeline-item">
                                            <div class="d-flex justify-content-between">
                                                <div>
                                                    <h6 class="mb-1">Library Fee Payment</h6>
                                                    <p class="timeline-date mb-1">25 Jul 2023</p>
                                                </div>
                                                <span class="timeline-amount">$30.00</span>
                                            </div>
                                            <p class="text-muted mb-0">Completed via Cash</p>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <button class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-download"></i> Export Full History
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // This would be replaced with actual JavaScript to handle interactions
        document.addEventListener('DOMContentLoaded', function() {
            // For demo purposes - in a real app, this would be dynamic
            setTimeout(function() {
                document.getElementById('noSelection').style.display = 'none';
                document.getElementById('paymentDetails').style.display = 'block';
                document.getElementById('noHistory').style.display = 'none';
                document.getElementById('paymentHistory').style.display = 'block';
            }, 1000);
        });
    </script>
</body>
</html>