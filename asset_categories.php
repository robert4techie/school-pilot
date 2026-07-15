<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Assets categories");

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        // Set content type for JSON responses
        header('Content-Type: application/json');

        if ($action === 'add' || $action === 'edit') {
            $category_name = trim($_POST['categoryName']);
            $category_code = strtoupper(substr($category_name, 0, 4));
            $category_icon = $_POST['categoryIcon'];
            $description = trim($_POST['categoryDescription']);
            $status = $_POST['categoryStatus'];
            $depreciation_rate = floatval($_POST['depreciation']);

            if (empty($category_name)) {
                echo json_encode(['success' => false, 'message' => 'Category Name is required.']);
                exit();
            }

            if ($action === 'add') {
                $sql = "INSERT INTO asset_categories (category_name, category_code, category_icon, description, status, depreciation_rate) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);

                if ($stmt) {
                    $stmt->bind_param("sssssd", $category_name, $category_code, $category_icon, $description, $status, $depreciation_rate);
                    if ($stmt->execute()) {
                        // Return the new category data
                        $new_id = $conn->insert_id;
                        $new_category_sql = "SELECT id, category_name, category_code, category_icon, description, status, depreciation_rate, created_at FROM asset_categories WHERE id = ?";
                        $new_stmt = $conn->prepare($new_category_sql);
                        $new_stmt->bind_param("i", $new_id);
                        $new_stmt->execute();
                        $new_result = $new_stmt->get_result();
                        $new_category = $new_result->fetch_assoc();
                        echo json_encode(['success' => true, 'message' => 'Category added successfully!', 'category' => $new_category]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Error: ' . $stmt->error]);
                    }
                    $stmt->close();
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error preparing statement: ' . $conn->error]);
                }

            } elseif ($action === 'edit') {
                $id = intval($_POST['categoryId']);
                $sql = "UPDATE asset_categories SET category_name = ?, category_icon = ?, description = ?, status = ?, depreciation_rate = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);

                if ($stmt) {
                    $stmt->bind_param("ssssdi", $category_name, $category_icon, $description, $status, $depreciation_rate, $id);
                    if ($stmt->execute()) {
                        // Return the updated category data
                        $updated_category_sql = "SELECT id, category_name, category_code, category_icon, description, status, depreciation_rate, created_at FROM asset_categories WHERE id = ?";
                        $updated_stmt = $conn->prepare($updated_category_sql);
                        $updated_stmt->bind_param("i", $id);
                        $updated_stmt->execute();
                        $updated_result = $updated_stmt->get_result();
                        $updated_category = $updated_result->fetch_assoc();
                        echo json_encode(['success' => true, 'message' => 'Category updated successfully!', 'category' => $updated_category]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Error: ' . $stmt->error]);
                    }
                    $stmt->close();
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error preparing statement: ' . $conn->error]);
                }
            }

        } elseif ($action === 'delete') {
            $id = intval($_POST['id']);

            // Get the category name for the check
            $name_sql = "SELECT category_name FROM asset_categories WHERE id = ?";
            $name_stmt = $conn->prepare($name_sql);
            $name_stmt->bind_param("i", $id);
            $name_stmt->execute();
            $name_result = $name_stmt->get_result();
            $category_row = $name_result->fetch_assoc();
            $category_name_to_delete = $category_row['category_name'];
            $name_stmt->close();

            // Check if category is being used by any assets using the category name
            $check_sql = "SELECT COUNT(*) as count FROM assets WHERE category = ?";
            $check_stmt = $conn->prepare($check_sql);

            if ($check_stmt) {
                $check_stmt->bind_param("s", $category_name_to_delete);
                $check_stmt->execute();
                $result = $check_stmt->get_result();
                $row = $result->fetch_assoc();

                if ($row['count'] > 0) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete category. It is being used by ' . $row['count'] . ' asset(s).']);
                    $check_stmt->close();
                    exit();
                }
                $check_stmt->close();
            }

            $sql = "DELETE FROM asset_categories WHERE id = ?";
            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        echo json_encode(['success' => true, 'message' => 'Category deleted successfully!']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Category not found.']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error: ' . $stmt->error]);
                }
                $stmt->close();
            } else {
                echo json_encode(['success' => false, 'message' => 'Error preparing statement: ' . $conn->error]);
            }

        } elseif ($action === 'toggle_status') {
            $id = intval($_POST['id']);
            $new_status = $_POST['status'];

            $sql = "UPDATE asset_categories SET status = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $stmt->bind_param("si", $new_status, $id);
                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        // Return the new category data to update the UI
                        $updated_category_sql = "SELECT id, category_name, category_code, category_icon, description, status, depreciation_rate, created_at FROM asset_categories WHERE id = ?";
                        $updated_stmt = $conn->prepare($updated_category_sql);
                        $updated_stmt->bind_param("i", $id);
                        $updated_stmt->execute();
                        $updated_result = $updated_stmt->get_result();
                        $updated_category = $updated_result->fetch_assoc();
                        echo json_encode(['success' => true, 'message' => 'Status updated successfully!', 'category' => $updated_category]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Category not found.']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error: ' . $stmt->error]);
                }
                $stmt->close();
            } else {
                echo json_encode(['success' => false, 'message' => 'Error preparing statement: ' . $conn->error]);
            }
        }
        exit();
    }
}

// Fetch all categories for display
$sql = "SELECT id, category_name, category_code, category_icon, description, status, depreciation_rate, created_at FROM asset_categories ORDER BY created_at DESC";
$result = $conn->query($sql);

$categories = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
}

// Get total asset count (if assets table exists)
$total_assets = 0;
$assets_sql = "SELECT COUNT(*) as count FROM assets";
$assets_result = $conn->query($assets_sql);
if ($assets_result) {
    $assets_row = $assets_result->fetch_assoc();
    $total_assets = $assets_row['count'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Categories - School Pilot</title>
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
            padding: 20px;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(34, 139, 34, 0.1);
            overflow: hidden;
        }

        .header {
            margin-top: 42px;
            background: linear-gradient(135deg, #228b22 0%, #32cd32 100%);
            color: white;
            padding: 20px;
            position: relative;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .header-left h2 {
            font-size: 1.8em;
            margin-bottom: 8px;
        }

        .header-left p {
            font-size: 1.1em;
            opacity: 0.9;
        }

        .header-stats {
            display: flex;
            gap: 30px;
        }

        .stat-item {
            text-align: center;
            background: rgba(255, 255, 255, 0.1);
            padding: 15px 20px;
            border-radius: 10px;
            backdrop-filter: blur(10px);
        }

        .stat-number {
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9em;
            opacity: 0.8;
        }

        .main-content {
            padding: 40px;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .search-filters {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-box {
            position: relative;
            min-width: 300px;
        }

        .search-box input {
            width: 100%;
            padding: 12px 16px 12px 45px;
            border: 2px solid #e8f5e8;
            border-radius: 25px;
            font-size: 1em;
            transition: all 0.3s ease;
            background: #fafafa;
        }

        .search-box input:focus {
            outline: none;
            border-color: #4caf50;
            background: white;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        .search-box i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
        }

        .filter-select {
            padding: 12px 16px;
            border: 2px solid #e8f5e8;
            border-radius: 8px;
            font-size: 1em;
            background: #fafafa;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-select:focus {
            outline: none;
            border-color: #4caf50;
            background: white;
        }

        .btnn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 1em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            color: white;
        }

        .btnn-primary {
            background: linear-gradient(135deg, #4caf50 0%, #45a049 100%);
        }

        .btnn-primary:hover {
            background: linear-gradient(135deg, #45a049 0%, #3d8b40 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(76, 175, 80, 0.3);
        }

        .btnn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
        }

        .btnn-secondary:hover {
            background: linear-gradient(135deg, #5a6268 0%, #495057 100%);
            transform: translateY(-2px);
        }

        .btnn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }

        .btnn-danger:hover {
            background: linear-gradient(135deg, #c82333 0%, #a71e2a 100%);
            transform: translateY(-2px);
        }

        .btnn-warning {
            background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
            color: #212529;
        }

        .btnn-warning:hover {
            background: linear-gradient(135deg, #e0a800 0%, #c69500 100%);
            transform: translateY(-2px);
        }

        .btnn-sm {
            padding: 8px 16px;
            font-size: 0.9em;
        }

        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #f0f0f0;
        }

        .table-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            border-bottom: 1px solid #dee2e6;
        }

        .table-header h3 {
            color: #2e7d32;
            font-size: 1.3em;
            margin-bottom: 5px;
        }

        .table-header p {
            color: #666;
            font-size: 0.9em;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th,
        td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }

        th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            font-weight: 600;
            color: #2e7d32;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        tr:hover {
            background: #f8fcf8;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 500;
            text-transform: capitalize;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .category-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2em;
            margin-right: 12px;
            background: #e8f5e8;
            color: #2e7d32;
        }

        .category-info {
            display: flex;
            align-items: center;
        }

        .category-details h4 {
            margin: 0 0 4px 0;
            color: #2e7d32;
            font-size: 1em;
        }

        .category-details p {
            margin: 0;
            color: #666;
            font-size: 0.85em;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btnn-icon {
            padding: 8px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btnn-icon:hover {
            transform: translateY(-2px);
        }

        .btnn-edit {
            background: #e3f2fd;
            color: #1976d2;
        }

        .btnn-edit:hover {
            background: #bbdefb;
        }

        .btnn-delete {
            background: #ffebee;
            color: #d32f2f;
        }

        .btnn-delete:hover {
            background: #ffcdd2;
        }
         .btnn-status-toggle {
            background: #fff3e0;
            color: #ef6c00;
        }

        .btnn-status-toggle:hover {
            background: #ffcc80;
        }

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
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }

        .modal.active .modal-content {
            transform: scale(1);
        }

        .modal-header {
            background: linear-gradient(135deg, #228b22 0%, #32cd32 100%);
            color: white;
            padding: 20px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.4em;
        }

        .close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5em;
            cursor: pointer;
            padding: 5px;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .modal-body {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e8f5e8;
            border-radius: 8px;
            font-size: 1em;
            transition: all 0.3s ease;
            background: #fafafa;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #4caf50;
            background: white;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 30px;
            gap: 10px;
        }

        .pagination button {
            padding: 8px 12px;
            border: 1px solid #e8f5e8;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .pagination button:hover {
            background: #f8fcf8;
            border-color: #4caf50;
        }

        .pagination button.active {
            background: #4caf50;
            color: white;
            border-color: #4caf50;
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }

        .empty-state i {
            font-size: 4em;
            margin-bottom: 20px;
            color: #ccc;
        }

        .empty-state h3 {
            margin-bottom: 10px;
            color: #333;
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .loading i {
            font-size: 2em;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            z-index: 1100;
            transform: translateX(100%);
            transition: transform 0.3s ease;
        }

        .toast.show {
            transform: translateX(0);
        }

        .toast-success {
            background: #28a745;
        }

        .toast-error {
            background: #dc3545;
        }

        .toast-warning {
            background: #ffc107;
            color: #212529;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 20px;
            }

            .top-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .search-filters {
                flex-direction: column;
            }

            .search-box {
                min-width: auto;
            }

            .header-content {
                flex-direction: column;
                gap: 20px;
            }

            .header-stats {
                justify-content: center;
            }

            .table-wrapper {
                font-size: 0.9em;
            }

            .modal-content {
                width: 95%;
                margin: 10px;
            }

            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <?php
    require_once 'nav.php';
    ?>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <div class="header-left">
                    <h2><i class="fas fa-folder-open"></i> Asset Categories</h2>
                    <p>Organize and manage your asset categories efficiently</p>
                </div>
                <div class="header-stats">
                    <div class="stat-item">
                        <div class="stat-number" id="totalCategories"><?php echo count($categories); ?></div>
                        <div class="stat-label">Total Categories</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number" id="activeCategories"><?php echo count(array_filter($categories, function($c) { return $c['status'] === 'active'; })); ?></div>
                        <div class="stat-label">Active</div>
                    </div>
                     <div class="stat-item">
                        <div class="stat-number" id="totalAssets"><?php echo $total_assets; ?></div>
                        <div class="stat-label">Total Assets</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="main-content">
            <div class="top-bar">
                <div class="search-filters">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search categories...">
                    </div>
                    <select class="filter-select" id="statusFilter">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <button class="btnn btnn-secondary" onclick="exportData()">
                        <i class="fas fa-download"></i>
                        Export
                    </button>
                </div>
                <button class="btnn btnn-primary" onclick="openModal()">
                    <i class="fas fa-plus"></i>
                    Add Category
                </button>
            </div>

            <div class="table-container">
                <div class="table-header">
                    <h3>Category Management</h3>
                    <p>Manage your asset categories with advanced filtering and sorting options</p>
                </div>
                <div class="table-wrapper">
                    <table id="categoriesTable">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Code</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php if (empty($categories)) : ?>
                                <tr>
                                    <td colspan="5" class="empty-state">
                                        <i class="fas fa-folder-open"></i>
                                        <h3>No categories found</h3>
                                        <p>Try adding a new category to get started.</p>
                                    </td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($categories as $category) : ?>
                                    <tr>
                                        <td>
                                            <div class="category-info">
                                                <div class="category-icon">
                                                    <i class="<?php echo htmlspecialchars($category['category_icon']); ?>"></i>
                                                </div>
                                                <div class="category-details">
                                                    <h4><?php echo htmlspecialchars($category['category_name']); ?></h4>
                                                    <p><?php echo htmlspecialchars($category['description']); ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($category['category_code']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $category['status'] === 'active' ? 'status-active' : 'status-inactive'; ?>">
                                                <?php echo htmlspecialchars($category['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('Y-m-d', strtotime($category['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btnn-icon btnn-edit" onclick='editCategory(<?php echo json_encode($category); ?>)' title="Edit">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                                <button class="btnn-icon btnn-status-toggle" onclick="toggleStatus(<?php echo $category['id']; ?>, '<?php echo $category['status']; ?>')" title="Toggle Status">
                                                    <i class="fas fa-exchange-alt"></i>
                                                </button>
                                                <button class="btnn-icon btnn-delete" onclick="deleteCategory(<?php echo $category['id']; ?>)" title="Delete">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination" id="pagination">
                    </div>
            </div>
        </div>
    </div>

    <div id="categoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Add New Category</h3>
                <button class="close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="categoryForm">
                    <input type="hidden" name="action" id="action">
                    <input type="hidden" name="categoryId" id="categoryId">

                    <div class="form-group">
                        <label for="categoryName">Category Name <span style="color: #f44336;">*</span></label>
                        <input type="text" id="categoryName" name="categoryName" required placeholder="Enter category name">
                    </div>

                    <div class="form-group">
                        <label for="categoryCode">Category Code (Auto Generated)</label>
                        <input type="text" id="categoryCode" name="categoryCode" readonly placeholder="e.g., FURN, ELEC, SPORT">
                    </div>

                    <div class="form-group">
                        <label for="categoryIcon">Category Icon</label>
                        <select id="categoryIcon" name="categoryIcon">
                             <option value="fas fa-chair">Chair (Furniture)</option>
                            <option value="fas fa-laptop">Laptop (Electronics)</option>
                            <option value="fas fa-football-ball">Football (Sports)</option>
                            <option value="fas fa-book">Book (Literature)</option>
                            <option value="fas fa-flask">Flask (Laboratory)</option>
                            <option value="fas fa-car">Car (Vehicles)</option>
                            <option value="fas fa-broom">Broom (Cleaning)</option>
                            <option value="fas fa-utensils">Utensils (Kitchen)</option>
                            <option value="fas fa-stethoscope">Stethoscope (Medical)</option>
                            <option value="fas fa-box">Box (Other)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="categoryDescription">Description</label>
                        <textarea id="categoryDescription" name="categoryDescription" rows="3" placeholder="Enter category description..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="categoryStatus">Status</label>
                        <select id="categoryStatus" name="categoryStatus">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="depreciation">Default Depreciation Rate (% per year)</label>
                        <input type="number" id="depreciation" name="depreciation" min="0" max="100" step="0.1" placeholder="10.0">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btnn btnn-secondary" onclick="closeModal()">Cancel</button>
                <button type="button" class="btnn btnn-primary" onclick="submitForm()">
                    <i class="fas fa-save"></i>
                    <span id="submitButtonText">Save Category</span>
                </button>
            </div>
        </div>
    </div>

    <div id="confirmationModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h3 id="confirmationTitle">Confirm Action</h3>
                <button class="close" onclick="closeConfirmationModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p id="confirmationMessage">Are you sure you want to perform this action?</p>
            </div>
            <div class="modal-footer">
                <button class="btnn btnn-secondary" onclick="closeConfirmationModal()">Cancel</button>
                <button class="btnn btnn-danger" id="confirmActionButton">Confirm</button>
            </div>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script>
        // Use a global variable to store categories and update it dynamically
        let categories = <?php echo json_encode($categories); ?>;

        let currentPage = 1;
        const itemsPerPage = 10;

        document.addEventListener('DOMContentLoaded', function() {
            renderTable();
            setupEventListeners();
        });

        function setupEventListeners() {
            document.getElementById('searchInput').addEventListener('input', () => {
                currentPage = 1;
                renderTable();
            });
            document.getElementById('statusFilter').addEventListener('change', () => {
                currentPage = 1;
                renderTable();
            });

            document.getElementById('categoryName').addEventListener('input', function() {
                const categoryName = this.value.trim().toUpperCase();
                document.getElementById('categoryCode').value = categoryName.substring(0, 4);
            });
        }

        function renderTable() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value;

            let filteredData = categories.filter(category => {
                const matchesSearch = category.category_name.toLowerCase().includes(searchTerm) ||
                    category.category_code.toLowerCase().includes(searchTerm) ||
                    category.description.toLowerCase().includes(searchTerm);
                const matchesStatus = !statusFilter || category.status === statusFilter;
                return matchesSearch && matchesStatus;
            });

            const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            const pageData = filteredData.slice(startIndex, endIndex);

            const tableBody = document.getElementById('tableBody');
            tableBody.innerHTML = '';

            if (pageData.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="5" class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <h3>No categories found</h3>
                            <p>Try adjusting your search or filters</p>
                        </td>
                    </tr>
                `;
            } else {
                pageData.forEach(category => {
                    const row = `
                        <tr data-id="${category.id}">
                            <td>
                                <div class="category-info">
                                    <div class="category-icon">
                                        <i class="${category.category_icon}"></i>
                                    </div>
                                    <div class="category-details">
                                        <h4>${category.category_name}</h4>
                                        <p>${category.description || 'No description'}</p>
                                    </div>
                                </div>
                            </td>
                            <td>${category.category_code}</td>
                            <td>
                                <span class="status-badge ${category.status === 'active' ? 'status-active' : 'status-inactive'}">
                                    ${category.status}
                                </span>
                            </td>
                            <td>${new Date(category.created_at).toLocaleDateString()}</td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btnn-icon btnn-edit" onclick='editCategory(${JSON.stringify(category)})' title="Edit">
                                        <i class="fas fa-pencil-alt"></i>
                                    </button>
                                    <button class="btnn-icon btnn-status-toggle" onclick="toggleStatus(${category.id}, '${category.status}')" title="Toggle Status">
                                        <i class="fas fa-exchange-alt"></i>
                                    </button>
                                    <button class="btnn-icon btnn-delete" onclick="deleteCategory(${category.id})" title="Delete">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `;
                    tableBody.innerHTML += row;
                });
            }

            renderPagination(filteredData.length);
            updateStats();
        }

        function renderPagination(totalItems) {
            const paginationContainer = document.getElementById('pagination');
            const totalPages = Math.ceil(totalItems / itemsPerPage);

            if (totalPages <= 1) {
                paginationContainer.innerHTML = '';
                return;
            }

            let paginationHTML = `
                <button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>
                    <i class="fas fa-chevron-left"></i> Prev
                </button>
            `;

            for (let i = 1; i <= totalPages; i++) {
                paginationHTML += `
                    <button class="${i === currentPage ? 'active' : ''}" onclick="changePage(${i})">${i}</button>
                `;
            }

            paginationHTML += `
                <button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>
                    Next <i class="fas fa-chevron-right"></i>
                </button>
            `;

            paginationContainer.innerHTML = paginationHTML;
        }

        function changePage(page) {
            if (page < 1) page = 1;
            const totalPages = Math.ceil(categories.length / itemsPerPage);
            if (page > totalPages) page = totalPages;

            currentPage = page;
            renderTable();
        }

        function updateStats() {
            const totalCategories = categories.length;
            const activeCategories = categories.filter(c => c.status === 'active').length;
            document.getElementById('totalCategories').innerText = totalCategories;
            document.getElementById('activeCategories').innerText = activeCategories;
        }

        function openModal(category = null) {
            const modal = document.getElementById('categoryModal');
            const form = document.getElementById('categoryForm');
            form.reset();

            document.getElementById('action').value = category ? 'edit' : 'add';
            document.getElementById('categoryId').value = category ? category.id : '';
            document.getElementById('modalTitle').innerText = category ? 'Edit Category' : 'Add New Category';
            document.getElementById('submitButtonText').innerText = category ? 'Update Category' : 'Save Category';

            document.getElementById('categoryName').value = category ? category.category_name : '';
            document.getElementById('categoryCode').value = category ? category.category_code : '';
            document.getElementById('categoryIcon').value = category ? category.category_icon : 'fas fa-chair';
            document.getElementById('categoryDescription').value = category ? category.description : '';
            document.getElementById('categoryStatus').value = category ? category.status : 'active';
            document.getElementById('depreciation').value = category ? category.depreciation_rate : '';

            modal.classList.add('active');
        }

        function closeModal() {
            document.getElementById('categoryModal').classList.remove('active');
        }

        function submitForm() {
            const form = document.getElementById('categoryForm');
            const formData = new FormData(form);
            const action = formData.get('action');

            // Show loading state
            const submitBtn = document.querySelector('#categoryModal .btnn-primary');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;

            fetch('asset_categories.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'toast-success');
                    closeModal();

                    if (action === 'add') {
                        categories.unshift(data.category);
                    } else if (action === 'edit') {
                        const index = categories.findIndex(c => c.id == data.category.id);
                        if (index !== -1) {
                            categories[index] = data.category;
                        }
                    }
                    renderTable();
                } else {
                    showToast(data.message, 'toast-error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Network error occurred. Please try again.', 'toast-error');
            })
            .finally(() => {
                // Reset button state
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }

        function openConfirmationModal(title, message, confirmAction) {
            document.getElementById('confirmationTitle').innerText = title;
            document.getElementById('confirmationMessage').innerText = message;
            const confirmBtn = document.getElementById('confirmActionButton');

            const newConfirmBtn = confirmBtn.cloneNode(true);
            confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

            newConfirmBtn.addEventListener('click', function() {
                confirmAction();
                closeConfirmationModal();
            });

            document.getElementById('confirmationModal').classList.add('active');
        }

        function closeConfirmationModal() {
            document.getElementById('confirmationModal').classList.remove('active');
        }

        function editCategory(category) {
            openModal(category);
        }

        function deleteCategory(id) {
            openConfirmationModal(
                'Delete Category',
                'Are you sure you want to delete this category? This action cannot be undone.',
                function() {
                    const formData = new FormData();
                    formData.append('action', 'delete');
                    formData.append('id', id);

                    fetch('asset_categories.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showToast(data.message, 'toast-success');
                            categories = categories.filter(c => c.id !== id);
                            renderTable();
                        } else {
                            showToast(data.message, 'toast-error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showToast('Network error occurred. Please try again.', 'toast-error');
                    });
                }
            );
        }

        function toggleStatus(id, currentStatus) {
            const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
            openConfirmationModal(
                'Change Status',
                `Are you sure you want to change the status to "${newStatus}"?`,
                function() {
                    const formData = new FormData();
                    formData.append('action', 'toggle_status');
                    formData.append('id', id);
                    formData.append('status', newStatus);

                    fetch('asset_categories.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showToast(data.message, 'toast-success');
                            const index = categories.findIndex(c => c.id == id);
                            if (index !== -1) {
                                categories[index] = data.category;
                            }
                            renderTable();
                        } else {
                            showToast(data.message, 'toast-error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showToast('Network error occurred. Please try again.', 'toast-error');
                    });
                }
            );
        }

        function showToast(message, className) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `toast ${className} show`;
            setTimeout(() => {
                toast.className = toast.className.replace('show', '');
            }, 3000);
        }

        function exportData() {
            const table = document.getElementById('categoriesTable');
            let csvContent = "data:text/csv;charset=utf-8,";

            // Get table headers
            const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.innerText).join(',');
            csvContent += headers + '\n';

            // Get visible table rows (filtered data)
            table.querySelectorAll('tbody tr').forEach(row => {
                if (!row.querySelector('.empty-state')) {
                    const rowData = Array.from(row.querySelectorAll('td')).slice(0, -1).map(td => {
                        // Get clean text content, avoiding action buttons
                        let text = td.innerText.trim();
                        // Remove line breaks and extra spaces
                        text = text.replace(/\n/g, ' ').replace(/\s+/g, ' ');
                        // Escape commas and quotes for CSV
                        text = `"${text.replace(/"/g, '""')}"`;
                        return text;
                    }).join(',');
                    csvContent += rowData + '\n';
                }
            });

            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", `asset_categories_${new Date().toISOString().split('T')[0]}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            showToast('Categories exported successfully!', 'toast-success');
        }

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const categoryModal = document.getElementById('categoryModal');
            const confirmationModal = document.getElementById('confirmationModal');

            if (event.target === categoryModal) {
                closeModal();
            }
            if (event.target === confirmationModal) {
                closeConfirmationModal();
            }
        });
    </script>
</body>
</html>