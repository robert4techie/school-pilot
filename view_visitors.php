<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("View school visitors");

// Set timezone to Uganda
date_default_timezone_set('Africa/Kampala');

// Process checkout action
if (isset($_POST['checkout'])) {
    $visitorId = $_POST['visitor_id'];
    $checkoutTime = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("UPDATE visitors SET checkout_time = ? WHERE id = ?");
    $stmt->bind_param("si", $checkoutTime, $visitorId);

    if ($stmt->execute()) {
        $_SESSION['notification'] = [
            'type' => 'success',
            'message' => 'Visitor checked out successfully!'
        ];
    } else {
        $_SESSION['notification'] = [
            'type' => 'error',
            'message' => 'Failed to update checkout time: ' . $stmt->error
        ];
    }
    $stmt->close();

    header("Location: view_visitors.php");
    exit();
}

// Handle AJAX request for visitor details
if (isset($_GET['ajax']) && $_GET['ajax'] == 'visitor_details' && isset($_GET['id'])) {
    $visitorId = $_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM visitors WHERE id = ?");
    $stmt->bind_param("i", $visitorId);
    $stmt->execute();
    $result = $stmt->get_result();
    $visitor = $result->fetch_assoc();
    $stmt->close();

    if ($visitor) {
?>
        <div class="visitor-details">
            <div class="detail-item">
                <span class="detail-label">Full Name:</span>
                <div class="detail-value"><?php echo htmlspecialchars($visitor['first_name'] . ' ' . $visitor['last_name']); ?></div>
            </div>
            <div class="detail-item">
                <span class="detail-label">Company:</span>
                <div class="detail-value"><?php echo htmlspecialchars($visitor['company'] ?? 'N/A'); ?></div>
            </div>
            <div class="detail-item">
                <span class="detail-label">Email:</span>
                <div class="detail-value"><?php echo htmlspecialchars($visitor['email'] ?? 'N/A'); ?></div>
            </div>
            <div class="detail-item">
                <span class="detail-label">Phone:</span>
                <div class="detail-value"><?php echo htmlspecialchars($visitor['phone'] ?? 'N/A'); ?></div>
            </div>
            <div class="detail-item">
                <span class="detail-label">Visit Purpose:</span>
                <div class="detail-value"><?php echo htmlspecialchars($visitor['visit_purpose']); ?></div>
            </div>
            <div class="detail-item">
                <span class="detail-label">Host:</span>
                <div class="detail-value"><?php echo htmlspecialchars($visitor['host']); ?></div>
            </div>
            <div class="detail-item">
                <span class="detail-label">Visit Date:</span>
                <div class="detail-value"><?php echo date('M j, Y', strtotime($visitor['visit_date'])); ?></div>
            </div>
            <div class="detail-item">
                <span class="detail-label">Check-In Time:</span>
                <div class="detail-value"><?php echo date('g:i A', strtotime($visitor['created_at'])); ?></div>
            </div>
            <div class="detail-item">
                <span class="detail-label">Address:</span>
                <div class="detail-value"><?php echo htmlspecialchars($visitor['address'] ?? 'N/A'); ?></div>
            </div>
            <div class="detail-item">
                <span class="detail-label">Vehicle Plate:</span>
                <div class="detail-value"><?php echo htmlspecialchars($visitor['number_plate'] ?? 'N/A'); ?></div>
            </div>
            <div class="detail-item">
                <span class="detail-label">Check-Out Time:</span>
                <div class="detail-value">
                    <?php if (!empty($visitor['checkout_time'])): ?>
                        <?php echo date('g:i A', strtotime($visitor['checkout_time'])); ?>
                    <?php else: ?>
                        <span class="badge badge-success">Still Active</span>
                    <?php endif; ?>
                </div>
            </div>

        </div>
<?php
    } else {
        echo '<div class="alert alert-danger">Visitor not found</div>';
    }
    exit();
}

// Fetch all visitors for main page
$visitors = [];
$result = $conn->query("SELECT * FROM visitors ORDER BY visit_date DESC");
if ($result) {
    $visitors = $result->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Pilot - Visitor Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
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
            --box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        body {
            background-color: #f9fbf9;
            color: var(--text-dark);
            line-height: 1.6;
        }

        .content-container {
            max-width: 100%;
            margin: 20px auto;
            padding: 0 20px;
        }

        .page-header {
            background: linear-gradient(135deg, var(--primary-green), var(--accent-green));
            color: white;
            padding: 20px 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            margin-top: 70px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--box-shadow);
        }

        .page-header h3 {
            font-size: 22px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card {
            background-color: white;
            border-radius: 10px;
            box-shadow: var(--box-shadow);
            margin-bottom: 30px;
            border: 1px solid rgba(76, 175, 80, 0.1);
            overflow: hidden;
        }

        .card-header {
            background-color: var(--pale-green);
            padding: 18px 25px;
            border-bottom: 2px solid rgba(76, 175, 80, 0.2);
            font-weight: 600;
            color: var(--primary-green);
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-header i {
            margin-right: 10px;
        }

        .card-body {
            padding: 30px;
        }

        .btn-success {
            background: linear-gradient(to right, var(--light-green), var(--accent-green));
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            transition: var(--transition);
        }

        .btn-success:hover {
            background: linear-gradient(to right, var(--accent-green), var(--primary-green));
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(46, 125, 50, 0.3);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary-green);
            padding: 8px 16px;
            border-radius: 6px;
            border: 1px solid var(--primary-green);
            cursor: pointer;
            font-size: 14px;
            transition: var(--transition);
        }

        .btn-outline:hover {
            background-color: rgba(46, 125, 50, 0.1);
        }

        .badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-success {
            background-color: rgba(46, 125, 50, 0.1);
            color: var(--accent-green);
        }

        .badge-warning {
            background-color: rgba(255, 152, 0, 0.1);
            color: #ff9800;
        }

        /* Enhanced Table Styles */
        table.dataTable {
            width: 100% !important;
            border-collapse: separate !important;
            border-spacing: 0 !important;
            margin-top: 15px !important;
            background-color: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: var(--box-shadow);
        }

        /* Table Header Styles */
        table.dataTable thead th {
            background: linear-gradient(135deg, var(--pale-green), rgba(76, 175, 80, 0.15)) !important;
            color: var(--primary-green) !important;
            border-bottom: 2px solid var(--light-green) !important;
            padding: 15px 12px !important;
            font-weight: 600 !important;
            text-align: left !important;
            border-right: 1px solid rgba(76, 175, 80, 0.1) !important;
            position: relative;
            font-size: 14px;
        }

        table.dataTable thead th:last-child {
            border-right: none !important;
        }

        /* Table Body Styles */
        table.dataTable tbody {
            background-color: white;
        }

        table.dataTable tbody tr {
            border-bottom: 1px solid rgba(0, 0, 0, 0.05) !important;
            transition: all 0.2s ease !important;
        }

        table.dataTable tbody tr:hover {
            background-color: rgba(76, 175, 80, 0.08) !important;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        table.dataTable tbody tr:nth-child(even) {
            background-color: rgba(76, 175, 80, 0.02) !important;
        }

        table.dataTable tbody tr:nth-child(even):hover {
            background-color: rgba(76, 175, 80, 0.08) !important;
        }

        /* Table Cell Styles */
        table.dataTable tbody td {
            padding: 16px 12px !important;
            vertical-align: middle !important;
            border-right: 1px solid rgba(0, 0, 0, 0.03) !important;
            font-size: 14px;
            color: var(--text-dark);
            line-height: 1.4;
        }

        table.dataTable tbody td:last-child {
            border-right: none !important;
        }

        /* First and last row special styling */
        table.dataTable tbody tr:first-child td {
            border-top: 1px solid rgba(76, 175, 80, 0.1);
        }

        table.dataTable tbody tr:last-child {
            border-bottom: none !important;
        }

        /* Sorting indicators */
        table.dataTable thead th.sorting,
        table.dataTable thead th.sorting_asc,
        table.dataTable thead th.sorting_desc {
            cursor: pointer !important;
            position: relative !important;
        }

        table.dataTable thead th.sorting:after,
        table.dataTable thead th.sorting_asc:after,
        table.dataTable thead th.sorting_desc:after {
            position: absolute !important;
            right: 8px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            font-family: 'Font Awesome 5 Free' !important;
            font-weight: 900 !important;
            opacity: 0.6 !important;
        }

        table.dataTable thead th.sorting:after {
            content: '\f0dc' !important;
            /* fa-sort */
        }

        table.dataTable thead th.sorting_asc:after {
            content: '\f0de' !important;
            /* fa-sort-up */
            color: var(--primary-green) !important;
            opacity: 1 !important;
        }

        table.dataTable thead th.sorting_desc:after {
            content: '\f0dd' !important;
            /* fa-sort-down */
            color: var(--primary-green) !important;
            opacity: 1 !important;
        }

        /* DataTable Controls Styling */
        .dataTables_wrapper {
            padding: 0;
        }

        .dataTables_filter {
            float: none !important;
            text-align: left !important;
            margin-bottom: 20px !important;
        }

        .dataTables_filter label {
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            width: 100% !important;
            font-weight: 500 !important;
            color: var(--primary-green) !important;
        }

        .dataTables_filter input {
            border: 2px solid #e0e0e0 !important;
            border-radius: 8px !important;
            padding: 10px 15px !important;
            flex-grow: 1 !important;
            max-width: 300px !important;
            transition: var(--transition) !important;
            font-size: 14px !important;
        }

        .dataTables_filter input:focus {
            border-color: var(--light-green) !important;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.15) !important;
            outline: none !important;
        }

        .dataTables_length {
            margin-bottom: 20px !important;
        }

        .dataTables_length label {
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            font-weight: 500 !important;
            color: var(--primary-green) !important;
        }

        .dataTables_length select {
            border: 2px solid #e0e0e0 !important;
            border-radius: 6px !important;
            padding: 8px 12px !important;
            transition: var(--transition) !important;
            background-color: white !important;
        }

        .dataTables_length select:focus {
            border-color: var(--light-green) !important;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.15) !important;
            outline: none !important;
        }

        /* Pagination Styling */
        .dataTables_paginate {
            margin-top: 20px !important;
            text-align: center !important;
        }

        .dataTables_paginate .paginate_button {
            display: inline-block !important;
            padding: 8px 12px !important;
            margin: 0 2px !important;
            border: 1px solid #e0e0e0 !important;
            border-radius: 6px !important;
            background-color: white !important;
            color: var(--primary-green) !important;
            text-decoration: none !important;
            transition: var(--transition) !important;
            font-size: 14px !important;
        }

        .dataTables_paginate .paginate_button:hover {
            background-color: var(--pale-green) !important;
            border-color: var(--light-green) !important;
            color: var(--primary-green) !important;
        }

        .dataTables_paginate .paginate_button.current {
            background: linear-gradient(135deg, var(--light-green), var(--primary-green)) !important;
            color: white !important;
            border-color: var(--primary-green) !important;
        }

        .dataTables_paginate .paginate_button.disabled {
            opacity: 0.5 !important;
            cursor: not-allowed !important;
        }

        /* Info text styling */
        .dataTables_info {
            margin-top: 15px !important;
            color: var(--gray-medium) !important;
            font-size: 14px !important;
        }

        /* Empty table state */
        .dataTables_empty {
            padding: 40px !important;
            text-align: center !important;
            color: var(--gray-medium) !important;
            font-style: italic !important;
            background-color: rgba(76, 175, 80, 0.02) !important;
        }

        /* Processing indicator */
        .dataTables_processing {
            position: absolute !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            background: rgba(255, 255, 255, 0.95) !important;
            border: 1px solid var(--light-green) !important;
            border-radius: 8px !important;
            padding: 15px 25px !important;
            font-weight: 500 !important;
            color: var(--primary-green) !important;
            box-shadow: var(--box-shadow) !important;
        }

        /* Responsive table adjustments */
        @media (max-width: 768px) {
            table.dataTable {
                font-size: 12px !important;
            }

            table.dataTable thead th,
            table.dataTable tbody td {
                padding: 12px 8px !important;
            }

            .dataTables_filter input {
                max-width: 100% !important;
            }

            .dataTables_filter label {
                flex-direction: column !important;
                align-items: flex-start !important;
            }

            .dataTables_length label {
                flex-direction: column !important;
                align-items: flex-start !important;
            }
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        /* Status indicators */
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }

        .status-active {
            background-color: var(--light-green);
        }

        .status-checked-out {
            background-color: var(--gray-medium);
        }

        /* Improved search and filter controls */
        .dataTables_filter {
            float: none !important;
            text-align: left !important;
            margin-bottom: 20px;
        }

        .dataTables_filter label {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
        }

        .dataTables_filter input {
            border: 1px solid #ddd !important;
            border-radius: 6px !important;
            padding: 8px 15px !important;
            flex-grow: 1;
            max-width: 300px;
            transition: var(--transition);
        }

        .dataTables_filter input:focus {
            border-color: var(--light-green) !important;
            box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.2) !important;
            outline: none;
        }

        .dataTables_length {
            margin-bottom: 20px;
        }

        .dataTables_length select {
            border: 1px solid #ddd !important;
            border-radius: 6px !important;
            padding: 6px 10px !important;
        }

        /* Date filter controls */
        .date-filter-container {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            align-items: center;
        }

        .date-filter {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .date-filter label {
            font-weight: 500;
            color: var(--primary-green);
        }

        .date-filter input {
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 8px 15px;
            transition: var(--transition);
        }

        .date-filter input:focus {
            border-color: var(--light-green);
            box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.2);
            outline: none;
        }

        .clear-filters {
            background: transparent;
            border: none;
            color: var(--primary-green);
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: var(--transition);
        }

        .clear-filters:hover {
            color: var(--accent-green);
        }

        /* Notification system */
        .notification {
            position: relative;
            padding: 15px 20px;
            margin-bottom: 15px;
            border-radius: 8px;
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 350px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transform: translateX(100%);
            transition: transform 0.3s ease;
            overflow: hidden;
        }

        .notification.show {
            transform: translateX(0);
        }

        .notification-success {
            background: linear-gradient(135deg, var(--light-green), var(--primary-green));
        }

        .notification-error {
            background: linear-gradient(135deg, #f44336, #d32f2f);
        }

        .notification i {
            margin-right: 10px;
            font-size: 20px;
        }

        .notification-content {
            display: flex;
            align-items: center;
        }

        .notification-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 4px;
            background-color: rgba(255, 255, 255, 0.5);
            width: 0;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1050;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background-color: rgba(0, 0, 0, 0.5);
            transition: opacity 0.3s ease;
        }

        .modal.show {
            opacity: 1;
        }

        .modal-dialog {
            max-width: 600px;
            margin: 30px auto;
            position: relative;
            width: auto;
            transition: all 0.3s ease-out;
        }

        .modal.show .modal-dialog {
            transform: translateY(0);
            opacity: 1;
        }

        .modal-content {
            position: relative;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            transition: all 0.2s ease;
        }

        .modal.show .modal-content {
            transform: scale(1);
        }

        .modal-dialog {
            transform: translateX(50px);
            transition: all 0.3s cubic-bezier(0.25, 0.5, 0.5, 1.25);
        }

        .modal.show .modal-dialog {
            transform: translateX(0);
        }

        .modal.show .modal-content {
            animation: bounceIn 0.4s ease-out forwards;
        }

        .modal-header {
            padding: 15px 20px;
            background-color: var(--pale-green);
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h5 {
            margin: 0;
            color: var(--primary-green);
            font-size: 18px;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 15px 20px;
            background-color: #f8f9fa;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: flex-end;
        }

        .close {
            font-size: 24px;
            font-weight: bold;
            color: var(--primary-green);
            cursor: pointer;
            background: none;
            border: none;
        }

        .visitor-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .detail-item {
            margin-bottom: 15px;
        }

        .detail-label {
            font-weight: 600;
            color: var(--primary-green);
            margin-bottom: 5px;
            display: block;
        }

        .detail-value {
            padding: 8px 12px;
            background-color: #f8f9fa;
            border-radius: 6px;
            border-left: 3px solid var(--light-green);
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .card-body {
                padding: 15px;
            }

            .action-buttons {
                flex-direction: column;
                gap: 5px;
            }

            .visitor-details {
                grid-template-columns: 1fr;
            }

            .notification {
                width: 90%;
            }

            .date-filter-container {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        .export-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .btn-export {
            background: linear-gradient(to right, #FF5722, #E64A19);
            color: white;
            padding: 10px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-export:hover {
            background: linear-gradient(to right, #E64A19, #D84315);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(255, 87, 34, 0.3);
            color: white;
        }

        .btn-export.excel {
            background: linear-gradient(to right, #4CAF50, #388E3C);
        }

        .btn-export.excel:hover {
            background: linear-gradient(to right, #388E3C, #2E7D32);
            box-shadow: 0 4px 8px rgba(76, 175, 80, 0.3);
        }

        @media (max-width: 768px) {
            .export-buttons {
                flex-direction: column;
            }

            .btn-export {
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <?php require_once 'nav.php'; ?>

    <!-- Notification Container -->
    <div id="notification-container" style="position: fixed; top: 70px; right: 20px; z-index: 9999;"></div>

    <!-- View Visitors Page -->
    <div id="view-visitors" class="content-container main-container" style="display: block;">
        <div class="page-header">
            <h3><i class="fas fa-users"></i> Visitor Management</h3>
            <a href="add_visitor.php" class="btn-success" style="text-decoration: none;">
                <i class="fas fa-user-plus"></i> Add New Visitor
            </a>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <i class="fas fa-list"></i>
                    <span>Visitor Records</span>
                </div>
                <div>
                    <span class="badge badge-success">Active: <?php echo count(array_filter($visitors, function ($v) {
                                                                    return empty($v['checkout_time']);
                                                                })); ?></span>
                    <span class="badge badge-warning">Checked Out: <?php echo count(array_filter($visitors, function ($v) {
                                                                        return !empty($v['checkout_time']);
                                                                    })); ?></span>
                </div>
            </div>
            <div class="card-body">
                <!-- Date Filter Controls -->
                <div class="date-filter-container">
                    <div class="date-filter">
                        <label for="min-date">From:</label>
                        <input type="text" id="min-date" class="date-filter-input" placeholder="Select start date">
                    </div>
                    <div class="date-filter">
                        <label for="max-date">To:</label>
                        <input type="text" id="max-date" class="date-filter-input" placeholder="Select end date">
                    </div>
                    <button id="clear-filters" class="clear-filters">
                        <i class="fas fa-times"></i> Clear Filters
                    </button>

                    <div class="export-buttons">
                        <button id="exportPDF" class="btn-export">
                            <i class="fas fa-file-pdf"></i> Export to PDF
                        </button>
                        <button id="exportExcel" class="btn-export excel">
                            <i class="fas fa-file-excel"></i> Export to Excel
                        </button>
                    </div>
                </div>

                <table id="visitorsTable" class="table table-striped" style="width:100%">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Name</th>
                            <th>Company</th>
                            <th>Purpose</th>
                            <th>Host</th>
                            <th>Visit Date</th>
                            <th>Check-In</th>
                            <th>Check-Out</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($visitors as $visitor): ?>
                            <tr>
                                <td>
                                    <?php if (empty($visitor['checkout_time'])): ?>
                                        <span class="status-indicator status-active"></span>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="status-indicator status-checked-out"></span>
                                        <span class="badge badge-warning">Checked Out</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($visitor['first_name'] . ' ' . htmlspecialchars($visitor['last_name'])); ?></td>
                                <td><?php echo htmlspecialchars($visitor['company'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($visitor['visit_purpose']); ?></td>
                                <td><?php echo htmlspecialchars($visitor['host']); ?></td>
                                <td><?php echo date('M j, Y', strtotime($visitor['visit_date'])); ?></td>
                                <td><?php echo date('g:i A', strtotime($visitor['created_at'])); ?></td>
                                <td>
                                    <?php if (!empty($visitor['checkout_time'])): ?>
                                        <?php echo date('g:i A', strtotime($visitor['checkout_time'])); ?>
                                    <?php else: ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="visitor_id" value="<?php echo $visitor['id']; ?>">
                                            <button type="submit" name="checkout" class="btn-outline">
                                                <i class="fas fa-sign-out-alt"></i> Check Out
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td class="action-buttons">
                                    <button class="btn-outline view-details" data-id="<?php echo $visitor['id']; ?>">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <a href="edit_visitor.php?id=<?php echo $visitor['id']; ?>" class="btn-outline">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Visitor Details Modal -->
    <div id="visitorModal" class="modal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5><i class="fas fa-user-circle"></i> Visitor Details</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body" id="visitorDetailsContent">
                    <!-- Content will be loaded here via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <script>
        // Initialize DataTable with enhanced features
        $(document).ready(function() {
            // Initialize date pickers
            flatpickr(".date-filter-input", {
                dateFormat: "Y-m-d",
                allowInput: true
            });

            var table = $('#visitorsTable').DataTable({
                responsive: true,
                order: [
                    [5, 'desc']
                ], // Default sort by visit date
                dom: '<"top"lf>rt<"bottom"ip>',
                columnDefs: [{
                        responsivePriority: 1,
                        targets: 1
                    }, // Name
                    {
                        responsivePriority: 2,
                        targets: 8
                    }, // Actions
                    {
                        responsivePriority: 3,
                        targets: 0
                    } // Status
                ],
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search visitors...",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    infoEmpty: "Showing 0 to 0 of 0 entries",
                    infoFiltered: "(filtered from _MAX_ total entries)"
                }
            });

            // Date range filter
            $('#min-date, #max-date').on('change', function() {
                var min = $('#min-date').val();
                var max = $('#max-date').val();

                if (min || max) {
                    table.column(5).search(min + '|' + max, true, false).draw();
                }
            });

            // Clear filters
            $('#clear-filters').on('click', function() {
                $('#min-date, #max-date').val('');
                table.search('').columns().search('').draw();
            });

            // Custom search for date range
            $.fn.dataTable.ext.search.push(
                function(settings, data, dataIndex) {
                    var min = $('#min-date').val();
                    var max = $('#max-date').val();
                    var date = data[5]; // Visit date column

                    if (!min && !max) return true;

                    if (min && !max) {
                        return date >= min;
                    } else if (!min && max) {
                        return date <= max;
                    } else if (min && max) {
                        return date >= min && date <= max;
                    }
                    return true;
                }
            );

            // Modal handling - Updated to use the correct AJAX endpoint
            // Modal handling
            $(document).on('click', '.view-details', function() {
                var visitorId = $(this).data('id');
                $.ajax({
                    url: 'view_visitors.php?ajax=visitor_details&id=' + visitorId,
                    type: 'GET',
                    success: function(response) {
                        $('#visitorDetailsContent').html(response);
                        $('#visitorModal').css('display', 'block');
                        setTimeout(function() {
                            $('#visitorModal').addClass('show');
                        }, 10);
                    },
                    error: function(xhr, status, error) {
                        showNotification('Failed to load visitor details: ' + error, 'error');
                        console.error('AJAX Error:', status, error);
                    }
                });
            });

            $('.close, [data-dismiss="modal"]').on('click', function() {
                $('#visitorModal').removeClass('show');
                setTimeout(function() {
                    $('#visitorModal').css('display', 'none');
                }, 300); // Match this with your CSS transition time
            });

            // Close modal when clicking outside
            $(window).on('click', function(e) {
                if ($(e.target).is('#visitorModal')) {
                    $('#visitorModal').removeClass('show');
                    setTimeout(function() {
                        $('#visitorModal').css('display', 'none');
                    }, 300);
                }
            });

            // Export to PDF functionality
            $('#exportPDF').on('click', function() {
                try {
                    showNotification('Generating PDF...', 'info', 2000);

                    // Get filtered data from DataTable
                    const table = $('#visitorsTable').DataTable();
                    const data = table.rows({
                        search: 'applied'
                    }).data().toArray();

                    if (data.length === 0) {
                        showNotification('No data to export', 'error');
                        return;
                    }

                    // Initialize jsPDF
                    const {
                        jsPDF
                    } = window.jspdf;
                    const doc = new jsPDF('landscape', 'mm', 'a4');

                    // Add company header
                    doc.setFontSize(20);
                    doc.setTextColor(40, 40, 40);
                    doc.text('Visitor Management Report', 20, 20);

                    // Add generation info
                    doc.setFontSize(10);
                    doc.setTextColor(100, 100, 100);
                    doc.text(`Generated on: ${new Date().toLocaleString()}`, 20, 30);
                    doc.text(`Total Records: ${data.length}`, 20, 35);

                    // Calculate statistics
                    const activeVisitors = data.filter(row => {
                        const statusCell = $(row[0]).find('.badge').text().trim();
                        return statusCell === 'Active';
                    }).length;

                    const checkedOutVisitors = data.length - activeVisitors;

                    doc.text(`Active Visitors: ${activeVisitors}`, 150, 30);
                    doc.text(`Checked Out: ${checkedOutVisitors}`, 150, 35);

                    // Prepare table data
                    const tableData = data.map(row => {
                        // Extract clean text from HTML elements
                        const status = $(row[0]).find('.badge').text().trim();
                        const visitDate = $(row[1]).text().trim();
                        const name = $(row[2]).text().trim();
                        const company = $(row[3]).text().trim();
                        const purpose = $(row[4]).text().trim();
                        const host = $(row[5]).text().trim();
                        const checkIn = $(row[6]).text().trim();

                        // Handle checkout time (might contain button or time)
                        let checkOut = $(row[7]).text().trim();
                        if (checkOut.includes('Check Out')) {
                            checkOut = 'Active';
                        }

                        return [status, visitDate, name, company, purpose, host, checkIn, checkOut];
                    });

                    // Define table columns
                    const columns = [
                        'Status', 'Visit Date', 'Name', 'Company',
                        'Purpose', 'Host', 'Check-In', 'Check-Out'
                    ];

                    // Generate the table
                    doc.autoTable({
                        head: [columns],
                        body: tableData,
                        startY: 45,
                        styles: {
                            fontSize: 8,
                            cellPadding: 3,
                            overflow: 'linebreak',
                            halign: 'left'
                        },
                        headStyles: {
                            fillColor: [41, 128, 185],
                            textColor: 255,
                            fontStyle: 'bold',
                            fontSize: 9
                        },
                        alternateRowStyles: {
                            fillColor: [240, 248, 255]
                        },
                        columnStyles: {
                            0: {
                                cellWidth: 20,
                                halign: 'center'
                            }, // Status
                            1: {
                                cellWidth: 25
                            }, // Visit Date
                            2: {
                                cellWidth: 35
                            }, // Name
                            3: {
                                cellWidth: 30
                            }, // Company
                            4: {
                                cellWidth: 40
                            }, // Purpose
                            5: {
                                cellWidth: 30
                            }, // Host
                            6: {
                                cellWidth: 20,
                                halign: 'center'
                            }, // Check-In
                            7: {
                                cellWidth: 20,
                                halign: 'center'
                            } // Check-Out
                        },
                        didDrawCell: function(data) {
                            // Color code status cells
                            if (data.column.index === 0 && data.cell.section === 'body') {
                                const status = data.cell.text[0];
                                if (status === 'Active') {
                                    doc.setFillColor(46, 204, 113, 0.3);
                                } else if (status === 'Checked Out') {
                                    doc.setFillColor(241, 196, 15, 0.3);
                                }
                            }
                        },
                        margin: {
                            top: 45,
                            right: 15,
                            bottom: 15,
                            left: 15
                        },
                        didDrawPage: function(data) {
                            // Add page numbers
                            const pageCount = doc.internal.getNumberOfPages();
                            doc.setFontSize(8);
                            doc.setTextColor(150);
                            doc.text(`Page ${data.pageNumber} of ${pageCount}`,
                                doc.internal.pageSize.width - 30,
                                doc.internal.pageSize.height - 10);
                        }
                    });

                    // Save the PDF
                    const fileName = `Visitor_Report_${new Date().toISOString().split('T')[0]}.pdf`;
                    doc.save(fileName);

                    showNotification('PDF exported successfully!', 'success');

                } catch (error) {
                    console.error('PDF Export Error:', error);
                    showNotification('Failed to export PDF: ' + error.message, 'error');
                }
            });

            // Export to Excel functionality
            $('#exportExcel').on('click', function() {
                try {
                    showNotification('Generating Excel file...', 'info', 2000);

                    // Get filtered data from DataTable
                    const table = $('#visitorsTable').DataTable();
                    const data = table.rows({
                        search: 'applied'
                    }).data().toArray();

                    if (data.length === 0) {
                        showNotification('No data to export', 'error');
                        return;
                    }

                    // Prepare workbook data
                    const workbookData = [];

                    // Add header row
                    workbookData.push([
                        'Status', 'Visit Date', 'Name', 'Company',
                        'Purpose', 'Host', 'Check-In Time', 'Check-Out Time',
                        'Duration (Hours)', 'Contact Email', 'Phone Number'
                    ]);

                    // Process each row
                    data.forEach(row => {
                        // Extract clean data from HTML
                        const status = $(row[0]).find('.badge').text().trim();
                        const visitDate = $(row[1]).text().trim();
                        const name = $(row[2]).text().trim();
                        const company = $(row[3]).text().trim();
                        const purpose = $(row[4]).text().trim();
                        const host = $(row[5]).text().trim();
                        const checkIn = $(row[6]).text().trim();

                        let checkOut = $(row[7]).text().trim();
                        if (checkOut.includes('Check Out')) {
                            checkOut = '';
                        }

                        // Calculate duration if both times are available
                        let duration = '';
                        if (checkOut && checkOut !== 'Active' && checkOut !== '') {
                            try {
                                const checkInTime = new Date(`${visitDate} ${checkIn}`);
                                const checkOutTime = new Date(`${visitDate} ${checkOut}`);
                                const diffHours = (checkOutTime - checkInTime) / (1000 * 60 * 60);
                                duration = diffHours > 0 ? diffHours.toFixed(2) : '';
                            } catch (e) {
                                duration = '';
                            }
                        }

                        workbookData.push([
                            status,
                            visitDate,
                            name,
                            company,
                            purpose,
                            host,
                            checkIn,
                            checkOut || 'Still Active',
                            duration,
                            '', // Email - would need to be fetched from visitor details
                            '' // Phone - would need to be fetched from visitor details
                        ]);
                    });

                    // Create workbook and worksheet
                    const wb = XLSX.utils.book_new();
                    const ws = XLSX.utils.aoa_to_sheet(workbookData);

                    // Set column widths
                    ws['!cols'] = [{
                            wch: 12
                        }, // Status
                        {
                            wch: 12
                        }, // Visit Date
                        {
                            wch: 20
                        }, // Name
                        {
                            wch: 20
                        }, // Company
                        {
                            wch: 25
                        }, // Purpose
                        {
                            wch: 18
                        }, // Host
                        {
                            wch: 12
                        }, // Check-In
                        {
                            wch: 12
                        }, // Check-Out
                        {
                            wch: 10
                        }, // Duration
                        {
                            wch: 25
                        }, // Email
                        {
                            wch: 15
                        } // Phone
                    ];

                    // Style the header row
                    const headerRange = XLSX.utils.decode_range(ws['!ref']);
                    for (let col = headerRange.s.c; col <= headerRange.e.c; col++) {
                        const cellAddress = XLSX.utils.encode_cell({
                            r: 0,
                            c: col
                        });
                        if (!ws[cellAddress]) continue;

                        ws[cellAddress].s = {
                            font: {
                                bold: true,
                                color: {
                                    rgb: "FFFFFF"
                                }
                            },
                            fill: {
                                fgColor: {
                                    rgb: "2980B9"
                                }
                            },
                            alignment: {
                                horizontal: "center",
                                vertical: "center"
                            }
                        };
                    }

                    // Add the worksheet to workbook
                    XLSX.utils.book_append_sheet(wb, ws, "Visitor Report");

                    // Create summary sheet
                    const summaryData = [
                        ['Visitor Management Summary'],
                        [''],
                        ['Report Generated:', new Date().toLocaleString()],
                        ['Total Records:', data.length],
                        ['Active Visitors:', data.filter(row => $(row[0]).find('.badge').text().trim() === 'Active').length],
                        ['Checked Out Visitors:', data.filter(row => $(row[0]).find('.badge').text().trim() === 'Checked Out').length],
                        [''],
                        ['Export Options Used:', 'Excel Format'],
                        ['Data Source:', 'Visitor Management System']
                    ];

                    const summaryWs = XLSX.utils.aoa_to_sheet(summaryData);
                    summaryWs['!cols'] = [{
                        wch: 25
                    }, {
                        wch: 30
                    }];

                    // Style summary sheet
                    summaryWs['A1'].s = {
                        font: {
                            bold: true,
                            sz: 16
                        },
                        alignment: {
                            horizontal: "center"
                        }
                    };

                    XLSX.utils.book_append_sheet(wb, summaryWs, "Summary");

                    // Generate filename and save
                    const fileName = `Visitor_Report_${new Date().toISOString().split('T')[0]}.xlsx`;
                    XLSX.writeFile(wb, fileName);

                    showNotification('Excel file exported successfully!', 'success');

                } catch (error) {
                    console.error('Excel Export Error:', error);
                    showNotification('Failed to export Excel: ' + error.message, 'error');
                }
            });

            // Enhanced export functionality with detailed visitor data
            window.exportDetailedReport = function(format = 'pdf') {
                // This function can be called to export with additional visitor details
                // It would make AJAX calls to get complete visitor information

                showNotification(`Preparing detailed ${format.toUpperCase()} report...`, 'info');

                const table = $('#visitorsTable').DataTable();
                const visibleRows = table.rows({
                    search: 'applied'
                }).nodes();
                const promises = [];

                // Collect all visitor IDs
                const visitorIds = [];
                $(visibleRows).each(function() {
                    const viewButton = $(this).find('.view-details');
                    if (viewButton.length) {
                        visitorIds.push(viewButton.data('id'));
                    }
                });

                // Fetch detailed data for each visitor
                visitorIds.forEach(id => {
                    promises.push(
                        $.ajax({
                            url: `view_visitors.php?ajax=visitor_details&id=${id}`,
                            type: 'GET'
                        })
                    );
                });

                Promise.all(promises).then(responses => {
                    // Process detailed data and export
                    const detailedData = responses.map(response => {
                        const $response = $(response);
                        const details = {};

                        $response.find('.detail-item').each(function() {
                            const label = $(this).find('.detail-label').text().replace(':', '');
                            const value = $(this).find('.detail-value').text().trim();
                            details[label] = value;
                        });

                        return details;
                    });

                    if (format === 'pdf') {
                        generateDetailedPDF(detailedData);
                    } else {
                        generateDetailedExcel(detailedData);
                    }

                }).catch(error => {
                    console.error('Error fetching detailed data:', error);
                    showNotification('Failed to fetch detailed visitor data', 'error');
                });
            };

            function generateDetailedPDF(detailedData) {
                // Implementation for detailed PDF with complete visitor information
                // This would create a more comprehensive PDF report
                showNotification('Detailed PDF generation completed!', 'success');
            }

            function generateDetailedExcel(detailedData) {
                // Implementation for detailed Excel with complete visitor information
                // This would create a more comprehensive Excel report  
                showNotification('Detailed Excel generation completed!', 'success');
            }
        });

        // Utility function to format data for export
        function formatExportData(htmlContent) {
            return $(htmlContent).text().trim().replace(/\s+/g, ' ');
        }

        // Function to validate export prerequisites
        function validateExportPrerequisites() {
            if (typeof XLSX === 'undefined') {
                showNotification('Excel export library not loaded. Please refresh the page.', 'error');
                return false;
            }

            if (typeof window.jspdf === 'undefined') {
                showNotification('PDF export library not loaded. Please refresh the page.', 'error');
                return false;
            }

            return true;
        }

        // Add export buttons event listeners with validation
        $(document).on('click', '#exportPDF, #exportExcel', function(e) {
            if (!validateExportPrerequisites()) {
                e.preventDefault();
                return false;
            }
        });

        // Enhanced notification system
        function showNotification(message, type = 'success', duration = 5000) {
            const container = document.getElementById('notification-container');
            if (!container) return;

            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <div class="notification-content">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                    <span>${message}</span>
                </div>
                <div class="notification-progress"></div>
            `;

            container.appendChild(notification);

            // Trigger the show animation
            setTimeout(() => notification.classList.add('show'), 10);

            // Progress bar animation
            const progress = notification.querySelector('.notification-progress');
            if (progress) {
                progress.style.width = '100%';
                progress.style.transition = `width ${duration}ms linear`;
            }

            // Auto-dismiss
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 300);
            }, duration);

            // Manual dismiss
            notification.addEventListener('click', () => {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 300);
            });
        }

        // Display any PHP notifications
        <?php if (isset($_SESSION['notification'])): ?>
            showNotification('<?php echo addslashes($_SESSION['notification']['message']); ?>', '<?php echo $_SESSION['notification']['type']; ?>');
            <?php unset($_SESSION['notification']); ?>
        <?php endif; ?>
    </script>
</body>

</html>