<?php
ob_start();
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Stationery inventory");

// Handle item update via modal form submission (POST request)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_item'])) {
    ob_clean();
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => '', 'errors' => []];

    $item_id = intval($_POST['item_id']);
    $item_name = trim($_POST['item_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $unit = trim($_POST['unit'] ?? '');
    $current_stock = $_POST['current_stock'] ?? '';
    $min_stock = $_POST['min_stock'] ?? '';
    $unit_price = $_POST['unit_price'] ?? '';
    $supplier = trim($_POST['supplier'] ?? '');
    $description = trim($_POST['description'] ?? '');

    // Validation
    if (empty($item_name)) $response['errors'][] = "Item name is required";
    if (empty($category)) $response['errors'][] = "Category is required";
    if (!is_numeric($current_stock) || $current_stock < 0) $response['errors'][] = "Current stock must be a non-negative number";
    if (!is_numeric($min_stock) || $min_stock < 0) $response['errors'][] = "Minimum stock must be a non-negative number";
    if (!empty($unit_price) && (!is_numeric($unit_price) || $unit_price < 0)) $response['errors'][] = "Unit price must be a positive number";

    if (empty($response['errors'])) {
        $sql_update = "UPDATE stationery_items SET item_name=?, category=?, brand=?, unit=?, current_stock=?, min_stock=?, unit_price=?, supplier=?, description=? WHERE item_id=?";
        $stmt = $conn->prepare($sql_update);
        if ($stmt) {
            $stmt->bind_param("ssssiidssi", $item_name, $category, $brand, $unit, $current_stock, $min_stock, $unit_price, $supplier, $description, $item_id);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = "Item updated successfully!";
            } else {
                $response['errors'][] = "Database error: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $response['errors'][] = "Database preparation error: " . $conn->error;
        }
    }
    echo json_encode($response);
    exit;
}

// Handle item deletion (GET request)
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $item_id = intval($_GET['id']);
    $sql_delete = "DELETE FROM stationery_items WHERE item_id = ?";
    $stmt = $conn->prepare($sql_delete);
    $stmt->bind_param("i", $item_id);
    if ($stmt->execute()) {
        header("Location: stationery_inventory.php?success_message=" . urlencode("Item deleted successfully!"));
    } else {
        header("Location: stationery_inventory.php?error_message=" . urlencode("Error deleting item: " . $stmt->error));
    }
    $stmt->close();
    exit;
}

// Handle AJAX search
if (isset($_GET['search_query'])) {
    ob_clean();
    header('Content-Type: application/json');
    $search_query = "%" . trim($_GET['search_query']) . "%";
    $sql_search = "SELECT * FROM stationery_items WHERE item_name LIKE ? OR category LIKE ? OR brand LIKE ? OR supplier LIKE ? ORDER BY item_name ASC";
    $stmt = $conn->prepare($sql_search);
    $stmt->bind_param("ssss", $search_query, $search_query, $search_query, $search_query);
    $stmt->execute();
    $result_items = $stmt->get_result();

    $items = [];
    if ($result_items->num_rows > 0) {
        while ($row = $result_items->fetch_assoc()) {
            $items[] = $row;
        }
    }
    echo json_encode($items);
    exit;
}

// Fetch all items and suppliers for page display
$sql_items = "SELECT * FROM stationery_items ORDER BY item_name ASC";
$result_items = $conn->query($sql_items);
$items = [];
if ($result_items->num_rows > 0) {
    while ($row = $result_items->fetch_assoc()) {
        $items[] = $row;
    }
}

$suppliers = [];
$sql_suppliers = "SELECT supplier_name FROM suppliers ORDER BY supplier_name ASC";
$result_suppliers = $conn->query($sql_suppliers);
if ($result_suppliers->num_rows > 0) {
    while ($row = $result_suppliers->fetch_assoc()) {
        $suppliers[] = $row['supplier_name'];
    }
}

$success_message = $_GET['success_message'] ?? '';
$error_message = $_GET['error_message'] ?? '';

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stationery Inventory | School Pilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <style>
        :root {
            --primary-color: #1e8449;
            --primary-light: #27ae60;
            --primary-dark: #145a32;
            --accent-color: #2ecc71;
            --error-color: #e74c3c;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --background-color: #f8f9fa;
            --card-background: #ffffff;
            --text-color: #2c3e50;
            --border-color: #e0e0e0;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, var(--background-color) 0%, #e9ecef 100%);
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            margin-top: 50px;
            background: var(--card-background);
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: white;
            padding: 25px;
            text-align: center;
        }

        .header h2 {
            font-size: 2rem;
            font-weight: 400;
            margin-bottom: 5px;
        }
        
        .header p {
            font-size: 1rem;
            opacity: 0.9;
        }

        .table-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 40px 0;
            flex-wrap: wrap;
            gap: 15px;
        }

        .search-container {
            flex-grow: 1;
            max-width: 400px;
        }

        .search-container input {
            width: 100%;
            padding: 12px 20px;
            border: 2px solid var(--border-color);
            border-radius: 50px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background-color: #fafafa;
        }

        .search-container input:focus {
            outline: none;
            border-color: var(--primary-color);
            background-color: white;
            box-shadow: 0 0 0 3px rgba(30, 132, 73, 0.1);
        }

        .export-buttons {
            display: flex;
            gap: 10px;
        }

        .export-btn1 {
            padding: 10px 20px;
            border: none;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            color: white;
        }

        .export-btn1.excel {
            background-color: #21a366;
        }

        .export-btn1.excel:hover {
            background-color: #1a7e4b;
        }

        .export-btn1.pdf {
            background-color: #e74c3c;
        }

        .export-btn1.pdf:hover {
            background-color: #c0392b;
        }

        .table-container {
            padding: 40px;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background-color: var(--card-background);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        thead tr {
            background: var(--primary-light);
            color: white;
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.3s ease;
        }

        th:first-child, td:first-child {
            padding-left: 20px;
        }

        th:last-child, td:last-child {
            padding-right: 20px;
        }

        tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        tbody tr:hover {
            background-color: #f0f0f0;
        }

        .low-stock {
            background-color: #fdf3f2 !important;
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            color: white;
        }

        .status-in-stock {
            background-color: var(--success-color);
        }

        .status-low-stock {
            background-color: var(--error-color);
        }

        .actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .action-btn1 {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--primary-color);
            font-size: 1.1rem;
            transition: color 0.3s ease, transform 0.2s ease;
        }

        .action-btn1:hover {
            color: var(--primary-dark);
            transform: scale(1.1);
        }

        .action-btn1.delete-btn1 {
            color: var(--error-color);
        }

        .action-btn1.delete-btn1:hover {
            color: #c0392b;
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
            background-color: rgba(0,0,0,0.4);
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.3s forwards;
            overflow-y: auto;
        }

        .modal-content {
            background-color: var(--card-background);
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            width: 90%;
            max-width: 600px;
            position: relative;
            animation: slideIn 0.3s forwards;
            margin: 20px auto;
        }
        
        .modal.edit-modal .modal-content {
            max-width: 900px; 
        }

        .close-btn1 {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 10px;
            right: 20px;
            cursor: pointer;
        }

        .close-btn1:hover,
        .close-btn1:focus {
            color: var(--error-color);
            text-decoration: none;
        }

        .modal-header {
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 15px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            color: var(--primary-dark);
        }
        
        .modal-header h3 {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .modal-body p {
            margin-bottom: 10px;
            font-size: 1rem;
        }
        
        .modal-body strong {
            color: var(--primary-color);
        }
        
        .modal-body .description-box {
            background-color: #f0f4f7;
            border: 1px solid #d0d8df;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        /* Edit Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        /* New styles for 3-column layout */
        @media (min-width: 1024px) {
            .form-grid {
                grid-template-columns: 1fr 1fr 1fr;
            }
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group label i {
            color: var(--primary-color);
            width: 16px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 12px 16px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background-color: #fafafa;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            background-color: white;
            box-shadow: 0 0 0 3px rgba(30, 132, 73, 0.1);
        }
        
        .form-group select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' class='feather feather-chevron-down'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1.2rem;
            padding-right: 3rem;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .btn1-container {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 30px;
        }

        .btn1 {
            padding: 14px 30px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn1-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: white;
        }

        .btn1-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(30, 132, 73, 0.3);
        }

        .btn1-secondary {
            background: #6c757d;
            color: white;
        }

        .btn1-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        /* Custom Confirmation Modal */
        .confirm-modal-content {
            text-align: center;
        }
        
        .confirm-modal-content i {
            font-size: 3rem;
            color: var(--error-color);
            margin-bottom: 20px;
        }

        .confirm-modal-content h3 {
            margin-bottom: 10px;
        }

        .confirm-modal-content p {
            margin-bottom: 25px;
        }

        .confirm-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
        }

        .confirm-buttons button {
            padding: 10px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.3s ease;
        }

        .btn1-confirm-delete {
            background-color: var(--error-color);
            color: white;
        }
        
        .btn1-confirm-delete:hover {
            background-color: #c0392b;
        }

        .btn1-cancel {
            background-color: #95a5a6;
            color: white;
        }

        .btn1-cancel:hover {
            background-color: #7f8c8d;
        }

        /* Toast Notifications */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 1001;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
            max-width: 400px;
            box-shadow: var(--shadow);
        }

        .toast.success {
            background: linear-gradient(135deg, var(--success-color) 0%, var(--accent-color) 100%);
        }

        .toast.error {
            background: linear-gradient(135deg, var(--error-color) 0%, #c0392b 100%);
        }

        .toast i {
            font-size: 1.2rem;
        }

        .toast-close {
            margin-left: auto;
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 1.2rem;
            opacity: 0.7;
        }

        .toast-close:hover {
            opacity: 1;
        }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container { padding: 10px; margin: 10px; }
            .table-container { padding: 15px; }
            th, td { padding: 10px; font-size: 0.9rem; }
            .form-grid { grid-template-columns: 1fr; }
        }

        /* Utility classes */
        .text-center { text-align: center; }
    </style>
</head>
<body>
    <?php require_once 'nav.php';?>
    <div class="container">
        <div class="header">
            <h2><i class="fas fa-boxes"></i> Stationery Inventory</h2>
            <p>A comprehensive view of all your school stationery items.</p>
        </div>

        <div class="table-controls">
            <div class="search-container">
                <input type="text" id="searchInput" placeholder="Search for items, categories, brands..." />
            </div>
            <div class="export-buttons">
                <button class="export-btn1 excel" id="exportExcelbtn1"><i class="fas fa-file-excel"></i> Export to Excel</button>
                <button class="export-btn1 pdf" id="exportPdfbtn1"><i class="fas fa-file-pdf"></i> Export to PDF</button>
            </div>
        </div>

        <div class="table-container">
            <table id="inventoryTable">
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Category</th>
                        <th>Brand</th>
                        <th>Current Stock</th>
                        <th>Min. Stock</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="inventoryTableBody">
                    <?php
                    if (!empty($items)) {
                        foreach ($items as $row) {
                            $is_low_stock = $row['current_stock'] < $row['min_stock'];
                            $status_class = $is_low_stock ? "low-stock" : "";
                            $status_text = $is_low_stock ? "Low Stock" : "In Stock";
                            $status_badge_class = $is_low_stock ? "status-low-stock" : "status-in-stock";
                            
                            echo "<tr class='{$status_class}'>";
                            echo "<td>" . htmlspecialchars($row['item_name']) . "</td>";
                            echo "<td>" . htmlspecialchars($row['category']) . "</td>";
                            echo "<td>" . htmlspecialchars($row['brand']) . "</td>";
                            echo "<td>" . htmlspecialchars($row['current_stock']) . "</td>";
                            echo "<td>" . htmlspecialchars($row['min_stock']) . "</td>";
                            echo "<td class='text-center'><span class='status-badge {$status_badge_class}'>" . htmlspecialchars($status_text) . "</span></td>";
                            echo "<td class='text-center actions'>";
                            echo "<button class='action-btn1 view-btn1' data-id='{$row['item_id']}'><i class='fas fa-eye'></i></button>";
                            echo "<button class='action-btn1 edit-btn1' data-id='{$row['item_id']}'><i class='fas fa-edit'></i></button>";
                            echo "<button class='action-btn1 delete-btn1' data-id='{$row['item_id']}'><i class='fas fa-trash-alt'></i></button>";
                            echo "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='7' class='text-center'>No stationery items found.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <span class="close-btn1">&times;</span>
            <div class="modal-header">
                <i class="fas fa-info-circle"></i>
                <h3>Item Details</h3>
            </div>
            <div class="modal-body">
                <p><strong>Item Name:</strong> <span id="view_item_name"></span></p>
                <p><strong>Category:</strong> <span id="view_category"></span></p>
                <p><strong>Brand:</strong> <span id="view_brand"></span></p>
                <p><strong>Unit:</strong> <span id="view_unit"></span></p>
                <p><strong>Current Stock:</strong> <span id="view_current_stock"></span></p>
                <p><strong>Minimum Stock:</strong> <span id="view_min_stock"></span></p>
                <p><strong>Unit Price:</strong> <span id="view_unit_price"></span></p>
                <p><strong>Supplier:</strong> <span id="view_supplier"></span></p>
                <p><strong>Description:</strong></p>
                <div class="description-box" id="view_description"></div>
            </div>
        </div>
    </div>
    
    <div id="editModal" class="modal edit-modal">
        <div class="modal-content">
            <span class="close-btn1">&times;</span>
            <div class="modal-header">
                <i class="fas fa-edit"></i>
                <h3>Edit Item</h3>
            </div>
            <div class="modal-body">
                <form id="editForm" method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">
                    <input type="hidden" name="item_id" id="edit_item_id">
                    <input type="hidden" name="update_item" value="1">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_item_name">Item Name <span class="required">*</span></label>
                            <input type="text" id="edit_item_name" name="item_name" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_category">Category <span class="required">*</span></label>
                            <input type="text" id="edit_category" name="category" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_brand">Brand</label>
                            <input type="text" id="edit_brand" name="brand">
                        </div>

                        <div class="form-group">
                            <label for="edit_unit">Unit</label>
                            <select id="edit_unit" name="unit">
                                <option value="">-- Select Unit --</option>
                                <option value="pieces">Pieces</option>
                                <option value="boxes">Boxes</option>
                                <option value="packs">Packs</option>
                                <option value="reams">Reams</option>
                                <option value="sets">Sets</option>
                                <option value="sheets">Sheets</option>
                                <option value="pairs">Pairs</option>
                                <option value="units">Units</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="edit_current_stock">Current Stock <span class="required">*</span></label>
                            <input type="number" id="edit_current_stock" name="current_stock" required min="0">
                        </div>

                        <div class="form-group">
                            <label for="edit_min_stock">Minimum Stock <span class="required">*</span></label>
                            <input type="number" id="edit_min_stock" name="min_stock" required min="0">
                        </div>

                        <div class="form-group">
                            <label for="edit_unit_price">Unit Price</label>
                            <input type="number" step="0.01" id="edit_unit_price" name="unit_price" min="0">
                        </div>

                        <div class="form-group">
                            <label for="edit_supplier">Supplier</label>
                            <input list="suppliers" id="edit_supplier" name="supplier">
                            <datalist id="suppliers">
                                <?php foreach ($suppliers as $supplier_name): ?>
                                    <option value="<?php echo htmlspecialchars($supplier_name); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="form-group full-width">
                            <label for="edit_description">Description</label>
                            <textarea id="edit_description" name="description"></textarea>
                        </div>
                    </div>

                    <div class="btn1-container">
                        <button type="submit" class="btn1 btn1-primary" id="editSavebtn1">
                            <i class="fas fa-save" id="saveIcon"></i>
                            <span id="saveButtonText">Save Changes</span>
                            <div class="loader" style="display: none;"></div>
                        </button>
                        <button type="button" class="btn1 btn1-secondary close-btn1">
                            <i class="fas fa-times"></i>
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div id="deleteConfirmModal" class="modal">
        <div class="modal-content confirm-modal-content">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>Confirm Deletion</h3>
            <p>Are you sure you want to delete this item? This action cannot be undone.</p>
            <div class="confirm-buttons">
                <button id="btn1CancelDelete" class="btn1 btn1-cancel">Cancel</button>
                <button id="btn1ConfirmDelete" class="btn1 btn1-confirm-delete">Delete</button>
            </div>
        </div>
    </div>
    
    <style>
        .loader {
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top: 4px solid #fff;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>

    <script>
        const items = <?php echo json_encode($items); ?>;
        const viewModal = document.getElementById('viewModal');
        const editModal = document.getElementById('editModal');
        const deleteConfirmModal = document.getElementById('deleteConfirmModal');
        const editForm = document.getElementById('editForm');
        const editSavebtn1 = document.getElementById('editSavebtn1');
        const saveIcon = document.getElementById('saveIcon');
        const saveButtonText = document.getElementById('saveButtonText');
        const searchInput = document.getElementById('searchInput');
        const inventoryTableBody = document.getElementById('inventoryTableBody');
        const exportExcelbtn1 = document.getElementById('exportExcelbtn1');
        const exportPdfbtn1 = document.getElementById('exportPdfbtn1');

        let itemIdToDelete = null;

        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            const icon = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
            toast.innerHTML = `<i class="${icon}"></i><span>${message}</span><button class="toast-close" onclick="closeToast(this)">&times;</button>`;
            document.body.appendChild(toast);
            setTimeout(() => { if (toast.parentNode) { closeToast(toast.querySelector('.toast-close')); } }, 5000);
        }

        function closeToast(button) {
            const toast = button.parentNode;
            toast.style.animation = 'slideOut 0.3s ease forwards';
            setTimeout(() => { if (toast.parentNode) { toast.parentNode.removeChild(toast); } }, 300);
        }

        function renderTable(data) {
            inventoryTableBody.innerHTML = '';
            if (data.length > 0) {
                data.forEach(item => {
                    const is_low_stock = item.current_stock < item.min_stock;
                    const status_class = is_low_stock ? "low-stock" : "";
                    const status_text = is_low_stock ? "Low Stock" : "In Stock";
                    const status_badge_class = is_low_stock ? "status-low-stock" : "status-in-stock";
                    
                    const row = `
                        <tr class="${status_class}">
                            <td>${item.item_name}</td>
                            <td>${item.category}</td>
                            <td>${item.brand}</td>
                            <td>${item.current_stock}</td>
                            <td>${item.min_stock}</td>
                            <td class="text-center"><span class="status-badge ${status_badge_class}">${status_text}</span></td>
                            <td class="text-center actions">
                                <button class="action-btn1 view-btn1" data-id="${item.item_id}"><i class="fas fa-eye"></i></button>
                                <button class="action-btn1 edit-btn1" data-id="${item.item_id}"><i class="fas fa-edit"></i></button>
                                <button class="action-btn1 delete-btn1" data-id="${item.item_id}"><i class="fas fa-trash-alt"></i></button>
                            </td>
                        </tr>
                    `;
                    inventoryTableBody.innerHTML += row;
                });
            } else {
                inventoryTableBody.innerHTML = `<tr><td colspan='7' class='text-center'>No stationery items found.</td></tr>`;
            }
        }

        document.body.addEventListener('click', function(e) {
            if (e.target.closest('.view-btn1')) {
                const button = e.target.closest('.view-btn1');
                const itemId = button.dataset.id;
                const item = items.find(i => i.item_id == itemId);
                if (item) {
                    document.getElementById('view_item_name').textContent = item.item_name;
                    document.getElementById('view_category').textContent = item.category;
                    document.getElementById('view_brand').textContent = item.brand || 'N/A';
                    document.getElementById('view_unit').textContent = item.unit || 'N/A';
                    document.getElementById('view_current_stock').textContent = item.current_stock;
                    document.getElementById('view_min_stock').textContent = item.min_stock;
                    document.getElementById('view_unit_price').textContent = item.unit_price ? `$${parseFloat(item.unit_price).toFixed(2)}` : 'N/A';
                    document.getElementById('view_supplier').textContent = item.supplier || 'N/A';
                    document.getElementById('view_description').textContent = item.description || 'No description provided.';
                    viewModal.style.display = "flex";
                }
            } else if (e.target.closest('.edit-btn1')) {
                const button = e.target.closest('.edit-btn1');
                const itemId = button.dataset.id;
                const item = items.find(i => i.item_id == itemId);
                if (item) {
                    document.getElementById('edit_item_id').value = item.item_id;
                    document.getElementById('edit_item_name').value = item.item_name;
                    document.getElementById('edit_category').value = item.category;
                    document.getElementById('edit_brand').value = item.brand;
                    document.getElementById('edit_unit').value = item.unit;
                    document.getElementById('edit_current_stock').value = item.current_stock;
                    document.getElementById('edit_min_stock').value = item.min_stock;
                    document.getElementById('edit_unit_price').value = item.unit_price;
                    document.getElementById('edit_supplier').value = item.supplier;
                    document.getElementById('edit_description').value = item.description;
                    editModal.style.display = "flex";
                }
            } else if (e.target.closest('.delete-btn1')) {
                itemIdToDelete = e.target.closest('.delete-btn1').dataset.id;
                deleteConfirmModal.style.display = "flex";
            } else if (e.target.matches('.close-btn1')) {
                e.target.closest('.modal').style.display = 'none';
            } else if (e.target.matches('.modal')) {
                event.target.style.display = "none";
            }
        });

        editForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const requiredFields = ['edit_item_name', 'edit_category', 'edit_current_stock', 'edit_min_stock'];
            let hasError = false;
            requiredFields.forEach(fieldName => {
                const field = document.getElementById(fieldName);
                if (!field.value.trim()) {
                    field.classList.add('error');
                    hasError = true;
                } else {
                    field.classList.remove('error');
                }
            });
            if (hasError) {
                showToast('Please fill in all required fields', 'error');
                return;
            }

            editSavebtn1.disabled = true;
            saveIcon.style.display = 'none';
            saveButtonText.textContent = 'Saving...';
            const loader = editSavebtn1.querySelector('.loader');
            if (loader) loader.style.display = 'block';

            const formData = new FormData(editForm);

            try {
                const response = await fetch(editForm.action, {
                    method: 'POST',
                    body: formData,
                });

                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }

                const result = await response.json();
                
                if (result.success) {
                    showToast(result.message, 'success');
                    editModal.style.display = 'none';
                    // Fetch and re-render the table data
                    fetchItems();
                } else {
                    result.errors.forEach(error => showToast(error, 'error'));
                }
            } catch (error) {
                console.error('Update failed:', error);
                showToast('An unexpected error occurred. Please try again.', 'error');
            } finally {
                editSavebtn1.disabled = false;
                saveIcon.style.display = 'inline-block';
                saveButtonText.textContent = 'Save Changes';
                const loader = editSavebtn1.querySelector('.loader');
                if (loader) loader.style.display = 'none';
            }
        });
        
        document.getElementById('btn1CancelDelete').onclick = function() {
            deleteConfirmModal.style.display = "none";
            itemIdToDelete = null;
        }

        document.getElementById('btn1ConfirmDelete').onclick = function() {
            if (itemIdToDelete) {
                window.location.href = `?action=delete&id=${itemIdToDelete}`;
            }
        }
        
        <?php if (!empty($success_message)): ?>
            showToast('<?php echo addslashes($success_message); ?>', 'success');
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            showToast('<?php echo addslashes($error_message); ?>', 'error');
        <?php endif; ?>

        // AJAX Search Functionality
        let debounceTimer;
        searchInput.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                const query = searchInput.value;
                fetch(`?search_query=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(data => {
                        renderTable(data);
                    })
                    .catch(error => {
                        console.error('Search failed:', error);
                        showToast('Search failed. Please try again.', 'error');
                    });
            }, 300); // 300ms delay to prevent excessive requests
        });

        // Function to fetch and render items (used after edit)
        async function fetchItems() {
            try {
                const response = await fetch(`?search_query=${encodeURIComponent(searchInput.value)}`);
                const data = await response.json();
                renderTable(data);
            } catch (error) {
                console.error('Failed to fetch items:', error);
            }
        }

        // Export to Excel
        exportExcelbtn1.addEventListener('click', () => {
            const table = document.getElementById('inventoryTable');
            const ws = XLSX.utils.table_to_sheet(table);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Stationery Inventory");
            XLSX.writeFile(wb, "stationery_inventory.xlsx");
        });

        // Export to PDF
        exportPdfbtn1.addEventListener('click', () => {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            doc.text("Stationery Inventory Report", 14, 20);
            doc.autoTable({
                html: '#inventoryTable',
                startY: 30,
                styles: { fontSize: 10, cellPadding: 2, overflow: 'linebreak' },
                headStyles: { fillColor: [30, 132, 73], textColor: 255 },
                margin: { top: 25 },
                didDrawPage: function (data) {
                    doc.text("Page " + doc.internal.getNumberOfPages(), data.settings.margin.left, doc.internal.pageSize.height - 10);
                }
            });
            doc.save("stationery_inventory.pdf");
        });
    </script>
</body>
</html>