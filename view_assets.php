<?php
require_once 'auth.php';
require_once 'conn.php';
$tracker->trackAction("View Assets");


// Handle asset deletion via POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    // Ensure we're only outputting JSON
    header('Content-Type: application/json');

    // Clear any output that might have been sent before
    if (ob_get_level()) {
        ob_clean();
    }

    try {
        $delete_id = $conn->real_escape_string($_POST['delete_id']);

        // Validate that the ID is a number
        if (!is_numeric($delete_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid asset ID']);
            exit();
        }

        // First, get the image path to delete the file from the server
        $sql_get_image = "SELECT asset_image FROM assets WHERE id = '$delete_id'";
        $result_get_image = $conn->query($sql_get_image);

        if ($result_get_image && $result_get_image->num_rows > 0) {
            $row = $result_get_image->fetch_assoc();
            $image_path = $row['asset_image'];

            // Delete the record from the database
            $sql_delete = "DELETE FROM assets WHERE id = '$delete_id'";
            if ($conn->query($sql_delete) === TRUE) {
                // Delete the physical image file if it exists
                if ($image_path && file_exists($image_path)) {
                    unlink($image_path);
                }

                // Store success message in session for display after reload
                $_SESSION['toast_message'] = 'Asset deleted successfully!';
                $_SESSION['toast_type'] = 'success';

                echo json_encode(['status' => 'success', 'message' => 'Asset deleted successfully!']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Error deleting asset: ' . $conn->error]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Asset not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'An error occurred: ' . $e->getMessage()]);
    }

    // Crucial: Exit immediately after sending the JSON response
    exit();
}

// Rest of your PHP code remains the same...
// [Include all the existing PHP code for fetching assets, categories, etc.]

// Fetch all assets from the database
$assets = [];
$sql = "SELECT * FROM assets ORDER BY asset_name ASC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $assets[] = $row;
    }
}

// Fetch categories and statuses for filters
$categories = [];
$statuses = [];

$sql_categories = "SELECT DISTINCT category FROM assets ORDER BY category ASC";
$result_categories = $conn->query($sql_categories);
if ($result_categories) {
    while ($row = $result_categories->fetch_assoc()) {
        $categories[] = $row['category'];
    }
}

$sql_statuses = "SELECT DISTINCT status FROM assets ORDER BY status ASC";
$result_statuses = $conn->query($sql_statuses);
if ($result_statuses) {
    while ($row = $result_statuses->fetch_assoc()) {
        $statuses[] = $row['status'];
    }
}

// Fetch statistics
$totalAssets = $conn->query("SELECT COUNT(*) AS total FROM assets")->fetch_assoc()['total'];
$activeAssets = $conn->query("SELECT COUNT(*) AS total FROM assets WHERE status = 'active'")->fetch_assoc()['total'];
$maintenanceAssets = $conn->query("SELECT COUNT(*) AS total FROM assets WHERE status = 'maintenance'")->fetch_assoc()['total'];
$totalValue = $conn->query("SELECT SUM(total_cost) AS total FROM assets")->fetch_assoc()['total'] ?? 0;

// JSON encode data for JavaScript
$assetsJson = json_encode($assets);
$categoriesJson = json_encode($categories);
$statusesJson = json_encode($statuses);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Management - School Pilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #e8f5e8 0%, #f0f9f0 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            margin-top: 45px;
            background: linear-gradient(135deg, #228b22 0%, #32cd32 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(34, 139, 34, 0.2);
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="2" fill="rgba(255,255,255,0.1)"/><circle cx="80" cy="40" r="1.5" fill="rgba(255,255,255,0.1)"/><circle cx="40" cy="80" r="1" fill="rgba(255,255,255,0.1)"/></svg>');
            pointer-events: none;
        }

        .header-content {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .header-title h1 {
            font-size: 2em;
            font-weight: 700;
        }

        .header-title p {
            font-size: 1.1em;
            opacity: 0.9;
            margin-top: 5px;
        }

        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .btnn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 1em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            white-space: nowrap;
        }

        .btnn-primary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .btnn-primary:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .controls-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .controls-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto;
            gap: 20px;
            align-items: end;
        }

        .control-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .control-group label {
            font-weight: 600;
            color: #2e7d32;
            font-size: 0.9em;
        }

        .control-group input,
        .control-group select {
            padding: 12px 15px;
            border: 2px solid #e8f5e8;
            border-radius: 8px;
            font-size: 1em;
            transition: all 0.3s ease;
            background: #fafafa;
        }

        .control-group input:focus,
        .control-group select:focus {
            outline: none;
            border-color: #4caf50;
            background: white;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
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
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5em;
            color: white;
        }

        .stat-icon.total {
            background: linear-gradient(135deg, #2196f3, #1976d2);
        }

        .stat-icon.active {
            background: linear-gradient(135deg, #4caf50, #45a049);
        }

        .stat-icon.maintenance {
            background: linear-gradient(135deg, #ff9800, #f57c00);
        }

        .stat-icon.value {
            background: linear-gradient(135deg, #9c27b0, #7b1fa2);
        }

        .stat-content h3 {
            font-size: 1.8em;
            color: #2e7d32;
            margin-bottom: 5px;
        }

        .stat-content p {
            color: #666;
            font-size: 0.9em;
        }

        .table-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .table-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px 25px;
            border-bottom: 2px solid #e8f5e8;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-title {
            font-size: 1.3em;
            font-weight: 700;
            color: #2e7d32;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-actions {
            display: flex;
            gap: 10px;
        }

        .btnn-sm {
            padding: 8px 16px;
            font-size: 0.9em;
        }

        .table-wrapper {
            overflow-x: auto;
            max-height: 600px;
        }

        .assets-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95em;
        }

        .assets-table th {
            background: linear-gradient(135deg, #2e7d32 0%, #388e3c 100%);
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
            border-bottom: 2px solid #1b5e20;
        }

        .assets-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #e8f5e8;
            vertical-align: middle;
        }

        .assets-table tbody tr {
            transition: all 0.3s ease;
        }

        .assets-table tbody tr:hover {
            background: #f8fcf8;
            transform: scale(1.01);
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-active {
            background: #c8e6c9;
            color: #2e7d32;
        }

        .status-inactive {
            background: #ffcdd2;
            color: #c62828;
        }

        .status-maintenance {
            background: #ffe0b2;
            color: #ef6c00;
        }

        .status-disposed {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .category-tag {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            color: #1976d2;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.8em;
            font-weight: 500;
        }

        .condition-indicator {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9em;
        }

        .condition-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .condition-excellent .condition-dot {
            background: #4caf50;
        }

        .condition-good .condition-dot {
            background: #8bc34a;
        }

        .condition-fair .condition-dot {
            background: #ffc107;
        }

        .condition-poor .condition-dot {
            background: #ff9800;
        }

        .condition-damaged .condition-dot {
            background: #f44336;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .action-btnn {
            width: 35px;
            height: 35px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9em;
            transition: all 0.3s ease;
            color: white;
        }

        .action-btnn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .btnn-view {
            background: linear-gradient(135deg, #2196f3, #1976d2);
        }

        .btnn-edit {
            background: linear-gradient(135deg, #ff9800, #f57c00);
        }

        .btnn-delete {
            background: linear-gradient(135deg, #f44336, #d32f2f);
        }

        .asset-image {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            object-fit: cover;
            border: 2px solid #e8f5e8;
        }

        .no-image {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 1.2em;
            border: 2px solid #e8f5e8;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding: 20px;
            background: #f8f9fa;
            border-top: 1px solid #e8f5e8;
        }

        .pagination button {
            padding: 8px 12px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .pagination button:hover {
            background: #4caf50;
            color: white;
            border-color: #4caf50;
        }

        .pagination .active {
            background: #4caf50;
            color: white;
            border-color: #4caf50;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: white;
            margin: 2% auto;
            padding: 0;
            border-radius: 15px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }

        /* Image Modal Specific Styles */
        #imageModal .modal-content {
            background: none;
            box-shadow: none;
            max-width: 90%;
            max-height: 90vh;
            display: flex;
        }

        .modal-image {
            max-width: 100%;
            max-height: 100%;
            display: block;
            margin: auto;
            object-fit: contain;
        }

        .modal-image-close {
            position: absolute;
            top: 10px;
            right: 25px;
            color: #f1f1f1;
            font-size: 40px;
            font-weight: bold;
            transition: 0.3s;
            cursor: pointer;
            z-index: 1001;
        }

        .modal-image-close:hover,
        .modal-image-close:focus {
            color: #bbb;
            text-decoration: none;
            cursor: pointer;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            background: linear-gradient(135deg, #228b22 0%, #32cd32 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.5em;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5em;
            cursor: pointer;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 30px;
        }

        .asset-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        .detail-section {
            background: #f8fcf8;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #4caf50;
        }

        .detail-section h3 {
            color: #2e7d32;
            margin-bottom: 15px;
            font-size: 1.1em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detail-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .detail-label {
            font-weight: 600;
            color: #2e7d32;
            font-size: 0.9em;
        }

        .detail-value {
            color: #333;
            font-size: 1em;
        }

        .asset-image-modal {
            width: 100%;
            max-width: 200px;
            height: 150px;
            object-fit: cover;
            border-radius: 10px;
            border: 2px solid #e8f5e8;
            margin-bottom: 20px;
        }

        .no-image-modal {
            width: 100%;
            max-width: 200px;
            height: 150px;
            background: #f5f5f5;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 2em;
            border: 2px solid #e8f5e8;
            margin-bottom: 20px;
        }

        .currency {
            font-weight: 600;
            color: #2e7d32;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }

        .empty-state i {
            font-size: 4em;
            color: #ddd;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            margin-bottom: 10px;
            color: #999;
        }

        .empty-state p {
            margin-bottom: 20px;
        }

        /* Toast Notification Styles */
        .toast {
            visibility: hidden;
            min-width: 250px;
            background-color: #333;
            color: #fff;
            text-align: center;
            border-radius: 8px;
            padding: 16px;
            position: fixed;
            z-index: 1001;
            right: 20px;
            top: 20px;
            font-size: 17px;
            opacity: 0;
            transition: opacity 0.3s, transform 0.3s;
            transform: translateX(100%);
        }

        .toast.show {
            visibility: visible;
            opacity: 1;
            transform: translateX(0);
        }

        .toast.toast-success {
            background-color: #4CAF50;
        }

        .toast.toast-error {
            background-color: #f44336;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .controls-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }

            .controls-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .asset-details {
                grid-template-columns: 1fr;
            }

            .detail-row {
                grid-template-columns: 1fr;
            }

            .assets-table {
                font-size: 0.8em;
            }

            .assets-table th,
            .assets-table td {
                padding: 10px 8px;
            }

            .action-buttons {
                flex-direction: column;
                gap: 5px;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 10px;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }

            .modal-body {
                padding: 20px;
            }
        }
    </style>
</head>

<body>
    <?php require_once 'nav.php'; ?>

    <div class="container">
        <div class="header">
            <div class="header-content">
                <div class="header-title">
                    <div>
                        <h1><i class="fas fa-boxes"></i> Asset Management</h1>
                        <p>Monitor, track, and manage your school assets efficiently</p>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="add_asset.php" class="btnn btnn-primary">
                        <i class="fas fa-plus"></i> Add New Asset
                    </a>
                    <button class="btnn btnn-primary" onclick="exportAssets()">
                        <i class="fas fa-download"></i> Export
                    </button>
                </div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon total">
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="stat-content">
                    <h3 id="totalAssets"><?php echo $totalAssets; ?></h3>
                    <p>Total Assets</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon active">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <h3 id="activeAssets"><?php echo $activeAssets; ?></h3>
                    <p>Active Assets</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon maintenance">
                    <i class="fas fa-wrench"></i>
                </div>
                <div class="stat-content">
                    <h3 id="maintenanceAssets"><?php echo $maintenanceAssets; ?></h3>
                    <p>Under Maintenance</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon value">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stat-content">
                    <h3 id="totalValue">UGX <?php echo number_format($totalValue); ?></h3>
                    <p>Total Value</p>
                </div>
            </div>
        </div>

        <div class="controls-section">
            <div class="controls-grid">
                <div class="control-group">
                    <label for="searchInput">Search Assets</label>
                    <input type="text" id="searchInput" placeholder="Search by name, code, or category...">
                </div>
                <div class="control-group">
                    <label for="categoryFilter">Filter by Category</label>
                    <select id="categoryFilter">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat) : ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="control-group">
                    <label for="statusFilter">Filter by Status</label>
                    <select id="statusFilter">
                        <option value="">All Status</option>
                        <?php foreach ($statuses as $stat) : ?>
                            <option value="<?php echo htmlspecialchars($stat); ?>"><?php echo htmlspecialchars($stat); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="control-group">
                    <button class="btnn btnn-primary" onclick="clearFilters()">
                        <i class="fas fa-refresh"></i> Clear Filters
                    </button>
                </div>
            </div>
        </div>

        <div class="table-container">
            <div class="table-header">
                <div class="table-title">
                    <i class="fas fa-table"></i> Assets Inventory
                </div>
                <div class="table-actions">
                    <button class="btnn btnn-primary btnn-sm" onclick="toggleBulkActions()">
                        <i class="fas fa-tasks"></i> Bulk Actions
                    </button>
                </div>
            </div>

            <div class="table-wrapper">
                <table class="assets-table">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Asset Code</th>
                            <th>Asset Name</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Condition</th>
                            <th>Location</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total Value</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="assetsTableBody">
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                <button onclick="changePage(-1)">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <span id="pageInfo">Page 1 of 10</span>
                <button onclick="changePage(1)">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>

    <div id="assetModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-info-circle"></i> Asset Details
                </div>
                <button class="close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="modalContent">
                </div>
            </div>
        </div>
    </div>

    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-trash-alt"></i> Confirm Deletion
                </div>
                <button class="close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this asset? This action cannot be undone.</p>
                <p><strong>Asset Name:</strong> <span id="assetNameToDelete"></span></p>
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button class="btnn btnn-secondary" onclick="closeDeleteModal()">Cancel</button>
                    <button class="btnn btnn-delete" id="confirmDeleteBtn">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="imageModal" class="modal">
        <span class="modal-image-close" onclick="closeImageModal()">&times;</span>
        <img class="modal-image" id="enlargedImage" />
    </div>

    <div id="toast" class="toast"></div>

    <script>
        // Use PHP to inject the dynamic data into JavaScript
        const assetsData = <?php echo $assetsJson; ?>;
        const categories = <?php echo $categoriesJson; ?>;
        const statuses = <?php echo $statusesJson; ?>;

        let currentPage = 1;
        const itemsPerPage = 10;
        let filteredAssets = [...assetsData];
        let assetToDeleteId = null;

        // Initialize the page
        document.addEventListener('DOMContentLoaded', function() {
            renderAssetsTable();
            setupEventListeners();
            checkAndShowToast();
        });

        function checkAndShowToast() {
            <?php
            // This is a one-time check for a message passed from a redirect
            if (isset($_SESSION['toast_message'])) {
                echo "showToast('" . addslashes($_SESSION['toast_message']) . "', '" . addslashes($_SESSION['toast_type']) . "');";
                unset($_SESSION['toast_message']);
                unset($_SESSION['toast_type']);
            }
            ?>
        }

        function showToast(message, type) {
            const toast = document.getElementById('toast');
            if (!toast) {
                console.warn('Toast element not found');
                return;
            }
            toast.className = `toast show toast-${type}`;
            toast.textContent = message;
            setTimeout(function() {
                toast.className = toast.className.replace('show', '');
            }, 3000);
        }

        function setupEventListeners() {
            const searchInput = document.getElementById('searchInput');
            const categoryFilter = document.getElementById('categoryFilter');
            const statusFilter = document.getElementById('statusFilter');
            const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');

            if (searchInput) {
                searchInput.addEventListener('input', filterAssets);
            }
            if (categoryFilter) {
                categoryFilter.addEventListener('change', filterAssets);
            }
            if (statusFilter) {
                statusFilter.addEventListener('change', filterAssets);
            }
            if (confirmDeleteBtn) {
                confirmDeleteBtn.addEventListener('click', confirmDelete);
            }
        }

        function filterAssets() {
            const searchInput = document.getElementById('searchInput');
            const categoryFilter = document.getElementById('categoryFilter');
            const statusFilter = document.getElementById('statusFilter');

            if (!searchInput || !categoryFilter || !statusFilter) {
                console.error('Filter elements not found');
                return;
            }

            const searchTerm = searchInput.value.toLowerCase();
            const categoryFilterValue = categoryFilter.value;
            const statusFilterValue = statusFilter.value;

            filteredAssets = assetsData.filter(asset => {
                const matchesSearch = asset.asset_name.toLowerCase().includes(searchTerm) ||
                    asset.asset_code.toLowerCase().includes(searchTerm) ||
                    (asset.category && asset.category.toLowerCase().includes(searchTerm));

                const matchesCategory = !categoryFilterValue || (asset.category && asset.category === categoryFilterValue);
                const matchesStatus = !statusFilterValue || (asset.status && asset.status === statusFilterValue);

                return matchesSearch && matchesCategory && matchesStatus;
            });

            currentPage = 1;
            renderAssetsTable();
            updatePagination();
        }

        function renderAssetsTable() {
            const tableBody = document.getElementById('assetsTableBody');
            if (!tableBody) {
                console.error('Table body element not found');
                return;
            }

            const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            const paginatedAssets = filteredAssets.slice(startIndex, endIndex);

            if (paginatedAssets.length === 0) {
                tableBody.innerHTML = `
            <tr>
                <td colspan="11" class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <h3>No assets found</h3>
                    <p>Try adjusting your search criteria or add new assets.</p>
                </td>
            </tr>
        `;
                return;
            }

            tableBody.innerHTML = paginatedAssets.map(asset => `
        <tr>
            <td>
                ${asset.asset_image ? 
                    `<img src="${escapeHtml(asset.asset_image)}" alt="${escapeHtml(asset.asset_name)}" class="asset-image" onclick="enlargeImage('${escapeHtml(asset.asset_image)}')" style="cursor: pointer;">` :
                    `<div class="no-image"><i class="fas fa-image"></i></div>`
                }
            </td>
            <td><strong>${escapeHtml(asset.asset_code)}</strong></td>
            <td>${escapeHtml(asset.asset_name)}</td>
            <td><span class="category-tag">${formatCategory(asset.category)}</span></td>
            <td><span class="status-badge status-${asset.status}">${formatStatus(asset.status)}</span></td>
            <td>
                <div class="condition-indicator condition-${asset.asset_condition}">
                    <span class="condition-dot"></span>
                    ${formatCondition(asset.asset_condition)}
                </div>
            </td>
            <td>${escapeHtml(asset.storage_location || '')}</td>
            <td>${escapeHtml(asset.quantity || '0')}</td>
            <td><span class="currency">UGX ${formatCurrency(asset.unit_price || 0)}</span></td>
            <td><span class="currency">UGX ${formatCurrency(asset.total_cost || 0)}</span></td>
            <td>
                <div class="action-buttons">
                    <button class="action-btnn btnn-view" onclick="viewAsset(${asset.id})" title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="action-btnn btnn-edit" onclick="editAsset(${asset.id})" title="Edit Asset">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="action-btnn btnn-delete" onclick="deleteAsset(${asset.id}, '${escapeHtml(asset.asset_name)}')" title="Delete Asset">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');
        }

        function updatePagination() {
            const totalPages = Math.ceil(filteredAssets.length / itemsPerPage);
            const pageInfo = document.getElementById('pageInfo');
            if (pageInfo) {
                pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
            }
        }

        function changePage(direction) {
            const totalPages = Math.ceil(filteredAssets.length / itemsPerPage);
            const newPage = currentPage + direction;

            if (newPage >= 1 && newPage <= totalPages) {
                currentPage = newPage;
                renderAssetsTable();
                updatePagination();
            }
        }

        function clearFilters() {
            const searchInput = document.getElementById('searchInput');
            const categoryFilter = document.getElementById('categoryFilter');
            const statusFilter = document.getElementById('statusFilter');

            if (searchInput) searchInput.value = '';
            if (categoryFilter) categoryFilter.value = '';
            if (statusFilter) statusFilter.value = '';

            filteredAssets = [...assetsData];
            currentPage = 1;
            renderAssetsTable();
            updatePagination();
        }

        function viewAsset(assetId) {
            const asset = assetsData.find(a => parseInt(a.id) === assetId);
            if (!asset) {
                showToast('Asset not found', 'error');
                return;
            }

            const modalContent = document.getElementById('modalContent');
            const modal = document.getElementById('assetModal');

            if (!modalContent || !modal) {
                console.error('Modal elements not found');
                showToast('Unable to display asset details', 'error');
                return;
            }

            modalContent.innerHTML = `
        <div class="asset-details">
            <div class="detail-section">
                <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                ${asset.asset_image ? 
                    `<img src="${escapeHtml(asset.asset_image)}" alt="${escapeHtml(asset.asset_name)}" class="asset-image-modal" onclick="enlargeImage('${escapeHtml(asset.asset_image)}')" style="cursor: pointer;">` :
                    `<div class="no-image-modal"><i class="fas fa-image"></i></div>`
                }
                <div class="detail-row">
                    <div class="detail-item">
                        <span class="detail-label">Asset Code</span>
                        <span class="detail-value">${escapeHtml(asset.asset_code || '')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Asset Name</span>
                        <span class="detail-value">${escapeHtml(asset.asset_name || '')}</span>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="detail-item">
                        <span class="detail-label">Category</span>
                        <span class="detail-value">${formatCategory(asset.category)}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Status</span>
                        <span class="detail-value">
                            <span class="status-badge status-${asset.status}">${formatStatus(asset.status)}</span>
                        </span>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="detail-item">
                        <span class="detail-label">Condition</span>
                        <span class="detail-value">
                            <div class="condition-indicator condition-${asset.asset_condition}">
                                <span class="condition-dot"></span>
                                ${formatCondition(asset.asset_condition)}
                            </div>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Location</span>
                        <span class="detail-value">${escapeHtml(asset.storage_location || '')}</span>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="detail-item">
                        <span class="detail-label">Description</span>
                        <span class="detail-value">${escapeHtml(asset.description || '')}</span>
                    </div>
                </div>
            </div>

            <div class="detail-section">
                <h3><i class="fas fa-cogs"></i> Technical Details</h3>
                <div class="detail-row">
                    <div class="detail-item">
                        <span class="detail-label">Model</span>
                        <span class="detail-value">${escapeHtml(asset.model || '')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Serial Number</span>
                        <span class="detail-value">${escapeHtml(asset.serial_number || '')}</span>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="detail-item">
                        <span class="detail-label">Supplier</span>
                        <span class="detail-value">${escapeHtml(asset.supplier_name || '')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Department</span>
                        <span class="detail-value">${escapeHtml(asset.department || '')}</span>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="detail-item">
                        <span class="detail-label">Assigned To</span>
                        <span class="detail-value">${escapeHtml(asset.assigned_to || '')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Purchase Date</span>
                        <span class="detail-value">${formatDate(asset.purchase_date)}</span>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="detail-item">
                        <span class="detail-label">Warranty Expiry</span>
                        <span class="detail-value">${formatDate(asset.warranty_expiry)}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Maintenance Schedule</span>
                        <span class="detail-value">${formatMaintenanceSchedule(asset.maintenance_schedule)}</span>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="detail-item">
                        <span class="detail-label">Next Maintenance</span>
                        <span class="detail-value">${formatDate(asset.next_maintenance)}</span>
                    </div>
                </div>
            </div>

            <div class="detail-section">
                <h3><i class="fas fa-dollar-sign"></i> Financial Information</h3>
                <div class="detail-row">
                    <div class="detail-item">
                        <span class="detail-label">Quantity</span>
                        <span class="detail-value">${escapeHtml(asset.quantity || '0')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Unit Price</span>
                        <span class="detail-value currency">UGX ${formatCurrency(asset.unit_price || 0)}</span>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="detail-item">
                        <span class="detail-label">Total Value</span>
                        <span class="detail-value currency">UGX ${formatCurrency(asset.total_cost || 0)}</span>
                    </div>
                </div>
            </div>
        </div>
    `;
            modal.style.display = 'flex';
        }

        function editAsset(assetId) {
            if (!assetId || !Number.isInteger(assetId)) {
                showToast('Invalid asset ID', 'error');
                return;
            }
            window.location.href = `api/edit_asset.php?id=${assetId}`;
        }

        function deleteAsset(assetId, assetName) {
            if (!assetId || !Number.isInteger(assetId)) {
                showToast('Invalid asset ID', 'error');
                return;
            }

            assetToDeleteId = assetId;
            const assetNameElement = document.getElementById('assetNameToDelete');
            const deleteModal = document.getElementById('deleteModal');

            if (assetNameElement) {
                assetNameElement.textContent = assetName || 'Unknown Asset';
            }

            if (deleteModal) {
                deleteModal.style.display = 'flex';
            } else {
                console.error('Delete modal not found');
                // Fallback: direct confirmation
                if (confirm(`Are you sure you want to delete "${assetName || 'this asset'}"? This action cannot be undone.`)) {
                    confirmDelete();
                }
            }
        }

        function confirmDelete() {
            if (!assetToDeleteId) {
                showToast('No asset selected for deletion', 'error');
                return;
            }

            // Disable the delete button to prevent multiple clicks
            const confirmBtn = document.getElementById('confirmDeleteBtn');
            if (confirmBtn) {
                confirmBtn.disabled = true;
                confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
            }

            const formData = new FormData();
            formData.append('delete_id', assetToDeleteId.toString());

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    // Check if response is ok
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }

                    // Check content type
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        return response.text().then(text => {
                            console.error('Non-JSON response:', text);
                            throw new Error('Server returned non-JSON response');
                        });
                    }

                    return response.json();
                })
                .then(data => {
                    if (data.status === 'success') {
                        showToast(data.message || 'Asset deleted successfully', 'success');
                        // Reload the page to show updated data
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    } else {
                        showToast(data.message || 'Delete failed', 'error');
                    }
                })
                .catch(error => {
                    console.error('Delete error:', error);
                    showToast('An error occurred while deleting the asset. Please try again.', 'error');
                })
                .finally(() => {
                    // Re-enable the button
                    if (confirmBtn) {
                        confirmBtn.disabled = false;
                        confirmBtn.innerHTML = '<i class="fas fa-trash"></i> Delete';
                    }
                    closeDeleteModal();
                });
        }

        function closeModal() {
            const assetModal = document.getElementById('assetModal');
            if (assetModal) {
                assetModal.style.display = 'none';
            }
        }

        function closeDeleteModal() {
            const deleteModal = document.getElementById('deleteModal');
            if (deleteModal) {
                deleteModal.style.display = 'none';
            }
            assetToDeleteId = null; // Reset the ID
        }

        function enlargeImage(imageSrc) {
            if (!imageSrc) {
                showToast('No image to display', 'error');
                return;
            }

            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('enlargedImage');
            if (modal && modalImg) {
                modal.style.display = 'flex';
                modalImg.src = imageSrc;
            } else {
                console.error('Image modal elements not found');
            }
        }

        function closeImageModal() {
            const imageModal = document.getElementById('imageModal');
            if (imageModal) {
                imageModal.style.display = 'none';
            }
        }

        function toggleBulkActions() {
            showToast('Bulk actions feature coming soon!', 'info');
        }

        function exportAssets() {
            try {
                // Create CSV content
                const headers = [
                    'Asset Code', 'Asset Name', 'Category', 'Status', 'Condition',
                    'Location', 'Quantity', 'Unit Price', 'Total Value', 'Model',
                    'Serial Number', 'Supplier', 'Purchase Date', 'Warranty Expiry',
                    'Assigned To', 'Department', 'Description'
                ];

                const rows = filteredAssets.map(asset => [
                    asset.asset_code || '',
                    asset.asset_name || '',
                    formatCategory(asset.category),
                    formatStatus(asset.status),
                    formatCondition(asset.asset_condition),
                    asset.storage_location || '',
                    asset.quantity || '0',
                    asset.unit_price || '0',
                    asset.total_cost || '0',
                    asset.model || '',
                    asset.serial_number || '',
                    asset.supplier_name || '',
                    asset.purchase_date || '',
                    asset.warranty_expiry || '',
                    asset.assigned_to || '',
                    asset.department || '',
                    asset.description || ''
                ]);

                const csvContent = [headers, ...rows].map(row =>
                    row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(',')
                ).join('\n');

                // Create and trigger download
                const blob = new Blob([csvContent], {
                    type: 'text/csv;charset=utf-8;'
                });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `assets_export_${new Date().toISOString().split('T')[0]}.csv`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);

                showToast('Assets exported successfully!', 'success');
            } catch (error) {
                console.error('Export error:', error);
                showToast('Error exporting assets. Please try again.', 'error');
            }
        }

        // Utility functions
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        }

        function formatCategory(category) {
            const categories = {
                'furniture': 'Furniture',
                'electronics': 'Electronics',
                'sports': 'Sports Equipment',
                'books': 'Books & Literature',
                'laboratory': 'Laboratory Equipment',
                'vehicles': 'Vehicles',
                'cleaning': 'Cleaning Equipment',
                'kitchen': 'Kitchen Equipment',
                'medical': 'Medical Equipment',
                'other': 'Other'
            };
            return categories[category] || (category || 'Uncategorized');
        }

        function formatStatus(status) {
            const statuses = {
                'active': 'Active',
                'inactive': 'Inactive',
                'maintenance': 'Under Maintenance',
                'disposed': 'Disposed'
            };
            return statuses[status] || (status || 'Unknown');
        }

        function formatCondition(condition) {
            const conditions = {
                'excellent': 'Excellent',
                'good': 'Good',
                'fair': 'Fair',
                'poor': 'Poor',
                'damaged': 'Damaged'
            };
            return conditions[condition] || (condition || 'Unknown');
        }

        function formatCurrency(amount) {
            try {
                const num = parseFloat(amount) || 0;
                return new Intl.NumberFormat('en-UG').format(num);
            } catch (error) {
                console.error('Currency formatting error:', error);
                return '0';
            }
        }

        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            try {
                return new Date(dateString).toLocaleDateString('en-UG', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
            } catch (error) {
                console.error('Date formatting error:', error);
                return dateString;
            }
        }

        function formatMaintenanceSchedule(schedule) {
            const schedules = {
                'weekly': 'Weekly',
                'monthly': 'Monthly',
                'quarterly': 'Quarterly',
                'semi_annually': 'Semi-Annually',
                'annually': 'Annually',
                'as_needed': 'As Needed'
            };
            return schedules[schedule] || (schedule || 'Not Set');
        }

        // Close modal when clicking outside - enhanced with null checks
        window.onclick = function(event) {
            const assetModal = document.getElementById('assetModal');
            const deleteModal = document.getElementById('deleteModal');
            const imageModal = document.getElementById('imageModal');

            if (assetModal && event.target === assetModal) {
                closeModal();
            }
            if (deleteModal && event.target === deleteModal) {
                closeDeleteModal();
            }
            if (imageModal && event.target === imageModal) {
                closeImageModal();
            }
        }

        // Handle escape key to close modals
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const assetModal = document.getElementById('assetModal');
                const deleteModal = document.getElementById('deleteModal');
                const imageModal = document.getElementById('imageModal');

                if (assetModal && assetModal.style.display === 'flex') {
                    closeModal();
                }
                if (deleteModal && deleteModal.style.display === 'flex') {
                    closeDeleteModal();
                }
                if (imageModal && imageModal.style.display === 'flex') {
                    closeImageModal();
                }
            }
        });

        // Prevent form submission on enter key in search input
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' && event.target.id === 'searchInput') {
                event.preventDefault();
            }
        });
    </script>
</body>

</html>