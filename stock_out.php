<?php
// Start output buffering to prevent headers from being sent prematurely
ob_start();
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Stationery Stockout");

// Check if stationery_transactions table exists, if not, create it
$sql_create_table = "
CREATE TABLE IF NOT EXISTS stationery_transactions (
    transaction_id INT(11) AUTO_INCREMENT PRIMARY KEY,
    item_id INT(11) NOT NULL,
    quantity INT(11) NOT NULL,
    type VARCHAR(50) NOT NULL,
    reason TEXT,
    recipient_name VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES stationery_items(item_id)
);";
$conn->query($sql_create_table);

// Handle AJAX form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    ob_clean(); // Clear any previous output
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => '', 'errors' => []];

    if (isset($_POST['record_stock_out'])) {
        $item_id = $_POST['item_id'] ?? '';
        $quantity = $_POST['quantity'] ?? '';
        $transaction_type = $_POST['transaction_type'] ?? '';
        $reason = $_POST['reason'] ?? '';
        $recipient_name = trim($_POST['recipient_name'] ?? '');

        // Validation
        if (empty($item_id)) $response['errors'][] = "Please select an item.";
        if (empty($quantity) || !is_numeric($quantity) || $quantity <= 0) $response['errors'][] = "Please enter a valid quantity.";
        if (empty($transaction_type)) $response['errors'][] = "Please select a reason for the stock-out.";
        if ($transaction_type === 'distribution' && empty($recipient_name)) $response['errors'][] = "Recipient name is required for distributions.";

        if (empty($response['errors'])) {
            $conn->begin_transaction();
            try {
                $sql_select = "SELECT current_stock, item_name FROM stationery_items WHERE item_id = ?";
                $stmt_select = $conn->prepare($sql_select);
                $stmt_select->bind_param("i", $item_id);
                $stmt_select->execute();
                $result = $stmt_select->get_result();
                $row = $result->fetch_assoc();
                $current_stock = $row['current_stock'];
                $item_name = $row['item_name'];
                $stmt_select->close();

                if ($current_stock >= $quantity) {
                    $new_stock = $current_stock - $quantity;
                    $sql_update = "UPDATE stationery_items SET current_stock = ? WHERE item_id = ?";
                    $stmt_update = $conn->prepare($sql_update);
                    $stmt_update->bind_param("ii", $new_stock, $item_id);
                    $stmt_update->execute();
                    $stmt_update->close();

                    $sql_log = "INSERT INTO stationery_transactions (item_id, quantity, type, reason, recipient_name) VALUES (?, ?, ?, ?, ?)";
                    $stmt_log = $conn->prepare($sql_log);
                    $stmt_log->bind_param("iisss", $item_id, $quantity, $transaction_type, $reason, $recipient_name);
                    $stmt_log->execute();
                    $stmt_log->close();

                    $conn->commit();
                    $response['success'] = true;
                    $response['message'] = "Stock-out recorded successfully!";
                } else {
                    throw new Exception("Insufficient stock. Current stock for " . htmlspecialchars($item_name) . " is only " . $current_stock . ".");
                }
            } catch (Exception $e) {
                $conn->rollback();
                $response['errors'][] = $e->getMessage();
            }
        }
    } elseif (isset($_POST['update_transaction'])) {
        $transaction_id = intval($_POST['transaction_id']);
        $item_id = $_POST['item_id'] ?? '';
        $quantity = $_POST['quantity'] ?? '';
        $transaction_type = $_POST['transaction_type'] ?? '';
        $reason = $_POST['reason'] ?? '';
        $recipient_name = trim($_POST['recipient_name'] ?? '');

        // Validation
        if (empty($item_id)) $response['errors'][] = "Please select an item.";
        if (empty($quantity) || !is_numeric($quantity) || $quantity <= 0) $response['errors'][] = "Please enter a valid quantity.";
        if (empty($transaction_type)) $response['errors'][] = "Please select a reason for the stock-out.";
        if ($transaction_type === 'distribution' && empty($recipient_name)) $response['errors'][] = "Recipient name is required for distributions.";
        
        if (empty($response['errors'])) {
            $conn->begin_transaction();
            try {
                $sql_original = "SELECT item_id, quantity FROM stationery_transactions WHERE transaction_id = ?";
                $stmt_original = $conn->prepare($sql_original);
                $stmt_original->bind_param("i", $transaction_id);
                $stmt_original->execute();
                $result_original = $stmt_original->get_result();
                $original_transaction = $result_original->fetch_assoc();
                $stmt_original->close();
                
                $original_item_id = $original_transaction['item_id'];
                $original_quantity = $original_transaction['quantity'];
                
                // Revert original stock change
                $sql_revert = "UPDATE stationery_items SET current_stock = current_stock + ? WHERE item_id = ?";
                $stmt_revert = $conn->prepare($sql_revert);
                $stmt_revert->bind_param("ii", $original_quantity, $original_item_id);
                $stmt_revert->execute();
                $stmt_revert->close();

                // Apply new stock change
                $sql_apply = "UPDATE stationery_items SET current_stock = current_stock - ? WHERE item_id = ?";
                $stmt_apply = $conn->prepare($sql_apply);
                $stmt_apply->bind_param("ii", $quantity, $item_id);
                $stmt_apply->execute();
                $stmt_apply->close();

                // Update the transaction record
                $sql_update = "UPDATE stationery_transactions SET item_id=?, quantity=?, type=?, reason=?, recipient_name=? WHERE transaction_id=?";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->bind_param("iisssi", $item_id, $quantity, $transaction_type, $reason, $recipient_name, $transaction_id);
                
                if ($stmt_update->execute()) {
                    $conn->commit();
                    $response['success'] = true;
                    $response['message'] = "Transaction updated successfully!";
                } else {
                    throw new Exception("Database error: " . $stmt_update->error);
                }
                $stmt_update->close();
            } catch (Exception $e) {
                $conn->rollback();
                $response['errors'][] = "Failed to update transaction. " . $e->getMessage();
            }
        }
    }
    echo json_encode($response);
    exit;
}

// Handle AJAX deletion
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    ob_clean();
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    $transaction_id = intval($_GET['id']);
    
    $conn->begin_transaction();
    try {
        $sql_select = "SELECT item_id, quantity FROM stationery_transactions WHERE transaction_id = ?";
        $stmt_select = $conn->prepare($sql_select);
        $stmt_select->bind_param("i", $transaction_id);
        $stmt_select->execute();
        $result = $stmt_select->get_result();
        $row = $result->fetch_assoc();
        
        if ($row) {
            $item_id = $row['item_id'];
            $quantity = $row['quantity'];
            $stmt_select->close();

            $sql_return = "UPDATE stationery_items SET current_stock = current_stock + ? WHERE item_id = ?";
            $stmt_return = $conn->prepare($sql_return);
            $stmt_return->bind_param("ii", $quantity, $item_id);
            $stmt_return->execute();
            $stmt_return->close();

            $sql_delete = "DELETE FROM stationery_transactions WHERE transaction_id = ?";
            $stmt_delete = $conn->prepare($sql_delete);
            $stmt_delete->bind_param("i", $transaction_id);
            $stmt_delete->execute();
            $stmt_delete->close();

            $conn->commit();
            $response['success'] = true;
            $response['message'] = "Transaction deleted successfully! Stock has been returned.";
        } else {
            $response['message'] = "Transaction not found.";
            $conn->rollback();
        }
    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = "Failed to delete transaction. " . $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

// Handle AJAX search
if (isset($_GET['search_query'])) {
    ob_clean();
    header('Content-Type: application/json');
    $search_query = "%" . trim($_GET['search_query']) . "%";
    
    $sql_search = "SELECT t.*, i.item_name FROM stationery_transactions t JOIN stationery_items i ON t.item_id = i.item_id WHERE t.type IN ('distribution', 'withdrawal', 'damage', 'loss') AND (i.item_name LIKE ? OR t.recipient_name LIKE ? OR t.reason LIKE ?) ORDER BY t.created_at DESC";
    $stmt_search = $conn->prepare($sql_search);
    $stmt_search->bind_param("sss", $search_query, $search_query, $search_query);
    $stmt_search->execute();
    $result_transactions = $stmt_search->get_result();

    $transactions = [];
    if ($result_transactions->num_rows > 0) {
        while($row = $result_transactions->fetch_assoc()) {
            $transactions[] = $row;
        }
    }
    echo json_encode($transactions);
    exit;
}

// Fetch all stationery items for the forms
$sql_items = "SELECT item_id, item_name, current_stock FROM stationery_items ORDER BY item_name";
$result_items = $conn->query($sql_items);
$items = [];
if ($result_items->num_rows > 0) {
    while($row = $result_items->fetch_assoc()) {
        $items[] = $row;
    }
}

// Fetch recent stock-out transactions for the log table
$sql_transactions = "SELECT t.*, i.item_name FROM stationery_transactions t JOIN stationery_items i ON t.item_id = i.item_id WHERE t.type IN ('distribution', 'withdrawal', 'damage', 'loss') ORDER BY t.created_at DESC LIMIT 10";
$result_transactions = $conn->query($sql_transactions);
$transactions = [];
if ($result_transactions->num_rows > 0) {
    while($row = $result_transactions->fetch_assoc()) {
        $transactions[] = $row;
    }
}
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock-Out Management | School Pilot</title>
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
            background-color: #21a366;
            color: white;
            padding: 15px;
            text-align: center;
        }

        .header h2 {
            font-size: 1.5rem;
            font-weight: 400;
            margin-bottom: 5px;
        }
        
        .header p {
            font-size: 1rem;
            opacity: 0.9;
        }

        .controls-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 20px 40px;
        }
        
        @media (min-width: 768px) {
            .controls-container {
                flex-wrap: nowrap;
            }
        }
        
        .search-container {
            flex-grow: 1;
            max-width: 300px;
        }
        
        .add-button-container {
            flex-shrink: 0;
        }

        .export-buttons {
            display: flex;
            gap: 10px;
            flex-shrink: 0;
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
            border-color: var(--error-color);
            background-color: white;
            box-shadow: 0 0 0 3px rgba(231, 76, 60, 0.1);
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
        
        .content-section {
            padding: 40px;
        }
        
        .btn1-add-container {
            text-align: right;
            margin-bottom: 20px;
        }

        .btn1 {
            padding: 14px 30px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn1-danger {
            background: linear-gradient(135deg, var(--error-color) 0%, #c0392b 100%);
            color: white;
        }
        
        .btn1-danger:hover {
            background: linear-gradient(135deg, #c0392b 0%, var(--error-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(231, 76, 60, 0.3);
        }
        
        .btn1-secondary {
            background: #6c757d;
            color: white;
        }

        .btn1-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        /* New two-column grid for modals */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        @media (min-width: 600px) {
            .form-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        .form-grid > .form-group:nth-child(5) {
            grid-column: 1 / -1;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-color);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 12px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background-color: #fafafa;
        }
        
        .form-group select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' class='feather feather-chevron-down'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1.2rem;
            padding-right: 3rem;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--error-color);
            background-color: white;
            box-shadow: 0 0 0 3px rgba(231, 76, 60, 0.1);
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

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background-color: var(--card-background);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-top: 30px;
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
        
        th:first-child, td:first-child { padding-left: 20px; }
        th:last-child, td:last-child { padding-right: 20px; }

        tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        tbody tr:hover {
            background-color: #f0f0f0;
        }

        .text-center {
            text-align: center;
        }

        .transaction-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            color: white;
        }
        
        .badge-distribution { background-color: #1abc9c; }
        .badge-withdrawal { background-color: var(--error-color); }
        .badge-damage { background-color: #f1c40f; }
        .badge-loss { background-color: #34495e; }
        
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
            max-width: 700px;
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
        
        .actions-cell {
            white-space: nowrap;
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
        
        /* Custom Confirmation Modal */
        .confirm-modal-content {
            text-align: center;
        }
        
        .confirm-modal-content i {
            font-size: 3rem;
            color: var(--error-color);
            margin-bottom: 20px;
        }

        .confirm-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
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

        .toast.success { background: linear-gradient(135deg, var(--success-color) 0%, var(--accent-color) 100%); }
        .toast.error { background: linear-gradient(135deg, var(--error-color) 0%, #c0392b 100%); }
        .toast i { font-size: 1.2rem; }
        .toast-close { margin-left: auto; background: none; border: none; color: white; cursor: pointer; font-size: 1.2rem; opacity: 0.7; }
        .toast-close:hover { opacity: 1; }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }
        
        @media (max-width: 768px) {
            .container { padding: 10px; margin: 10px; }
            .content-section { padding: 15px; }
            .form-container { padding: 20px; }
            th, td { padding: 10px; font-size: 0.9rem; }
            .controls-container {
                flex-direction: column;
                align-items: stretch;
            }
            .search-container {
                max-width: none;
            }
            .add-button-container {
                width: 100%;
            }
        }
        
        .text-center { text-align: center; }
        
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
</head>
<body>
    <?php require_once 'nav.php';?>
    <div class="container">
        <div class="header">
            <h2><i class="fas fa-sign-out-alt"></i> Stock-Out Management</h2>
            <p>Record all items removed from the inventory.</p>
        </div>

        <div class="controls-container">
            <div class="search-container">
                <input type="text" id="searchInput" placeholder="Search transactions..." />
            </div>
            <div class="add-button-container">
                <button id="showStockOutModalbtn1" class="btn1 btn1-danger">
                    <i class="fas fa-minus-circle"></i> Record Stock-Out
                </button>
            </div>
            <div class="export-buttons">
                <button class="export-btn1 excel" id="exportExcelbtn1"><i class="fas fa-file-excel"></i> Excel</button>
                <button class="export-btn1 pdf" id="exportPdfbtn1"><i class="fas fa-file-pdf"></i> PDF</button>
            </div>
        </div>

        <div class="content-section">
            <h3>Recent Stock-Outs</h3>
            <table id="transactionsTable">
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Type</th>
                        <th>Quantity</th>
                        <th>Recipient</th>
                        <th>Reason</th>
                        <th>Date</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="transactionsTableBody">
                    <?php if (!empty($transactions)): ?>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($transaction['item_name']); ?></td>
                                <td>
                                    <span class="transaction-badge badge-<?php echo strtolower(htmlspecialchars($transaction['type'])); ?>">
                                        <?php echo ucfirst(htmlspecialchars($transaction['type'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($transaction['quantity']); ?></td>
                                <td><?php echo htmlspecialchars($transaction['recipient_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($transaction['reason']); ?></td>
                                <td><?php echo (new DateTime($transaction['created_at']))->format('Y-m-d H:i'); ?></td>
                                <td class="actions actions-cell">
                                    <button class="action-btn1 edit-btn1" 
                                            data-id="<?php echo $transaction['transaction_id']; ?>" 
                                            data-item-id="<?php echo $transaction['item_id']; ?>"
                                            data-quantity="<?php echo $transaction['quantity']; ?>"
                                            data-type="<?php echo htmlspecialchars($transaction['type']); ?>"
                                            data-reason="<?php echo htmlspecialchars($transaction['reason']); ?>"
                                            data-recipient="<?php echo htmlspecialchars($transaction['recipient_name']); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="action-btn1 delete-btn1" data-id="<?php echo $transaction['transaction_id']; ?>">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center">No recent stock-out transactions found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div id="stockOutModal" class="modal">
        <div class="modal-content">
            <span class="close-btn1" onclick="closeModal('stockOutModal')">&times;</span>
            <div class="modal-header">
                <i class="fas fa-sign-out-alt"></i>
                <h3>Record Stock-Out</h3>
            </div>
            <div class="modal-body">
                <form id="stockOutForm" method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">
                    <input type="hidden" name="record_stock_out" value="1">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="item_id">Item Name</label>
                            <select id="item_id" name="item_id" required>
                                <option value="">-- Select an Item --</option>
                                <?php foreach ($items as $item): ?>
                                    <option value="<?php echo $item['item_id']; ?>" data-stock="<?php echo $item['current_stock']; ?>">
                                        <?php echo htmlspecialchars($item['item_name']); ?> (Current Stock: <?php echo $item['current_stock']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="transaction_type">Reason for Stock-Out</label>
                            <select id="transaction_type" name="transaction_type" required>
                                <option value="">-- Select Reason --</option>
                                <option value="distribution">Distribution</option>
                                <option value="withdrawal">General Withdrawal</option>
                                <option value="damage">Damage</option>
                                <option value="loss">Loss</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="quantity">Quantity</label>
                            <input type="number" id="quantity" name="quantity" min="1" required>
                        </div>
                        <div class="form-group" id="recipient_field" style="display: none;">
                            <label for="recipient_name">Recipient Name</label>
                            <input type="text" id="recipient_name" name="recipient_name">
                        </div>
                    </div>
                    <div class="form-group full-width">
                        <label for="reason">Additional Details</label>
                        <textarea id="reason" name="reason" rows="3" required></textarea>
                    </div>
                    <div class="btn1-container">
                        <button type="submit" class="btn1 btn1-danger" id="recordStockOutbtn1">
                            <i class="fas fa-minus" id="recordIcon"></i>
                            <span id="recordbtn1Text">Record Stock-Out</span>
                            <div class="loader" style="display: none;"></div>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div id="editModal" class="modal edit-modal">
        <div class="modal-content">
            <span class="close-btn1" onclick="closeModal('editModal')">&times;</span>
            <div class="modal-header">
                <i class="fas fa-edit"></i>
                <h3>Edit Stock-Out Transaction</h3>
            </div>
            <div class="modal-body">
                <form id="editForm" method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">
                    <input type="hidden" name="update_transaction" value="1">
                    <input type="hidden" id="edit_transaction_id" name="transaction_id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_item_id">Item Name</label>
                            <select id="edit_item_id" name="item_id" required>
                                <option value="">-- Select an Item --</option>
                                <?php foreach ($items as $item): ?>
                                    <option value="<?php echo $item['item_id']; ?>">
                                        <?php echo htmlspecialchars($item['item_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_transaction_type">Reason for Stock-Out</label>
                            <select id="edit_transaction_type" name="transaction_type" required>
                                <option value="distribution">Distribution</option>
                                <option value="withdrawal">General Withdrawal</option>
                                <option value="damage">Damage</option>
                                <option value="loss">Loss</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_quantity">Quantity</label>
                            <input type="number" id="edit_quantity" name="quantity" min="1" required>
                        </div>
                        <div class="form-group" id="edit_recipient_field" style="display: none;">
                            <label for="edit_recipient_name">Recipient Name</label>
                            <input type="text" id="edit_recipient_name" name="recipient_name">
                        </div>
                    </div>
                    <div class="form-group full-width">
                        <label for="edit_reason">Additional Details</label>
                        <textarea id="edit_reason" name="reason" rows="3" required></textarea>
                    </div>
                    <div class="btn1-container">
                        <button type="submit" class="btn1 btn1-primary" id="editSavebtn1">
                            <i class="fas fa-save" id="editIcon"></i>
                            <span id="editbtn1Text">Save Changes</span>
                            <div class="loader" style="display: none;"></div>
                        </button>
                        <button type="button" class="btn1 btn1-secondary" onclick="closeModal('editModal')">
                            <i class="fas fa-times"></i> Cancel
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
            <p>Are you sure you want to delete this transaction? The stock will be returned to the inventory.</p>
            <div class="confirm-buttons">
                <button id="btn1CancelDelete" class="btn1 btn1-cancel">Cancel</button>
                <button id="btn1ConfirmDelete" class="btn1 btn1-confirm-delete">Delete</button>
            </div>
        </div>
    </div>

    <script>
        const stockOutModal = document.getElementById('stockOutModal');
        const editModal = document.getElementById('editModal');
        const deleteConfirmModal = document.getElementById('deleteConfirmModal');
        const stockOutForm = document.getElementById('stockOutForm');
        const editForm = document.getElementById('editForm');
        const transactionsTableBody = document.getElementById('transactionsTableBody');
        const searchInput = document.getElementById('searchInput');
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

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function renderTable(transactions) {
            transactionsTableBody.innerHTML = '';
            if (transactions.length === 0) {
                transactionsTableBody.innerHTML = '<tr><td colspan="7" class="text-center">No transactions found.</td></tr>';
                return;
            }

            const badgeClasses = {
                'distribution': 'badge-distribution',
                'withdrawal': 'badge-withdrawal',
                'damage': 'badge-damage',
                'loss': 'badge-loss'
            };

            transactions.forEach(transaction => {
                const row = document.createElement('tr');
                const date = new Date(transaction.created_at);
                const formattedDate = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
                
                row.innerHTML = `
                    <td>${transaction.item_name}</td>
                    <td><span class="transaction-badge badge-${transaction.type.toLowerCase()}">${transaction.type.charAt(0).toUpperCase() + transaction.type.slice(1)}</span></td>
                    <td>${transaction.quantity}</td>
                    <td>${transaction.recipient_name || 'N/A'}</td>
                    <td>${transaction.reason}</td>
                    <td>${formattedDate}</td>
                    <td class="actions actions-cell">
                        <button class="action-btn1 edit-btn1" 
                                data-id="${transaction.transaction_id}" 
                                data-item-id="${transaction.item_id}"
                                data-quantity="${transaction.quantity}"
                                data-type="${transaction.type}"
                                data-reason="${transaction.reason}"
                                data-recipient="${transaction.recipient_name || ''}">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="action-btn1 delete-btn1" data-id="${transaction.transaction_id}">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </td>
                `;
                transactionsTableBody.appendChild(row);
            });
        }
        
        document.getElementById('showStockOutModalbtn1').onclick = function() {
            stockOutModal.style.display = 'flex';
        }
        
        document.getElementById('transaction_type').addEventListener('change', function() {
            document.getElementById('recipient_field').style.display = (this.value === 'distribution') ? 'flex' : 'none';
            document.getElementById('recipient_name').setAttribute('required', (this.value === 'distribution') ? 'required' : '');
        });

        document.getElementById('edit_transaction_type').addEventListener('change', function() {
            document.getElementById('edit_recipient_field').style.display = (this.value === 'distribution') ? 'flex' : 'none';
            document.getElementById('edit_recipient_name').setAttribute('required', (this.value === 'distribution') ? 'required' : '');
        });

        document.body.addEventListener('click', function(e) {
            if (e.target.closest('.edit-btn1')) {
                const button = e.target.closest('.edit-btn1');
                document.getElementById('edit_transaction_id').value = button.dataset.id;
                document.getElementById('edit_item_id').value = button.dataset.itemId;
                document.getElementById('edit_quantity').value = button.dataset.quantity;
                document.getElementById('edit_transaction_type').value = button.dataset.type;
                document.getElementById('edit_reason').value = button.dataset.reason;
                
                if (button.dataset.type === 'distribution') {
                    document.getElementById('edit_recipient_field').style.display = 'flex';
                    document.getElementById('edit_recipient_name').value = button.dataset.recipient;
                    document.getElementById('edit_recipient_name').setAttribute('required', 'required');
                } else {
                    document.getElementById('edit_recipient_field').style.display = 'none';
                    document.getElementById('edit_recipient_name').value = '';
                    document.getElementById('edit_recipient_name').removeAttribute('required');
                }
                editModal.style.display = "flex";
            } else if (e.target.closest('.delete-btn1')) {
                itemIdToDelete = e.target.closest('.delete-btn1').dataset.id;
                deleteConfirmModal.style.display = "flex";
            }
        });
        
        window.onclick = function(event) {
            if (event.target == stockOutModal || event.target == editModal || event.target == deleteConfirmModal) {
                event.target.style.display = 'none';
            }
        }
        
        document.getElementById('btn1CancelDelete').onclick = function() {
            closeModal('deleteConfirmModal');
            itemIdToDelete = null;
        }

        document.getElementById('btn1ConfirmDelete').onclick = async function() {
            if (itemIdToDelete) {
                const response = await fetch(`?action=delete&id=${itemIdToDelete}`);
                const result = await response.json();
                
                if (result.success) {
                    showToast(result.message, 'success');
                    closeModal('deleteConfirmModal');
                    fetchTransactions();
                } else {
                    showToast(result.message, 'error');
                }
            }
        }

        // --- AJAX Form Submissions ---
        const recordStockOutbtn1 = document.getElementById('recordStockOutbtn1');
        const editSavebtn1 = document.getElementById('editSavebtn1');

        stockOutForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            recordStockOutbtn1.disabled = true;
            recordStockOutbtn1.querySelector('#recordIcon').style.display = 'none';
            recordStockOutbtn1.querySelector('#recordbtn1Text').textContent = 'Recording...';
            recordStockOutbtn1.querySelector('.loader').style.display = 'block';

            const formData = new FormData(this);
            const response = await fetch(this.action, { method: 'POST', body: formData });
            const result = await response.json();

            recordStockOutbtn1.disabled = false;
            recordStockOutbtn1.querySelector('#recordIcon').style.display = 'inline-block';
            recordStockOutbtn1.querySelector('#recordbtn1Text').textContent = 'Record Stock-Out';
            recordStockOutbtn1.querySelector('.loader').style.display = 'none';

            if (result.success) {
                showToast(result.message, 'success');
                closeModal('stockOutModal');
                stockOutForm.reset();
                fetchTransactions();
            } else {
                result.errors.forEach(error => showToast(error, 'error'));
            }
        });

        editForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            editSavebtn1.disabled = true;
            editSavebtn1.querySelector('#editIcon').style.display = 'none';
            editSavebtn1.querySelector('#editbtn1Text').textContent = 'Updating...';
            editSavebtn1.querySelector('.loader').style.display = 'block';

            const formData = new FormData(this);
            const response = await fetch(this.action, { method: 'POST', body: formData });
            const result = await response.json();

            editSavebtn1.disabled = false;
            editSavebtn1.querySelector('#editIcon').style.display = 'inline-block';
            editSavebtn1.querySelector('#editbtn1Text').textContent = 'Save Changes';
            editSavebtn1.querySelector('.loader').style.display = 'none';
            
            if (result.success) {
                showToast(result.message, 'success');
                closeModal('editModal');
                fetchTransactions();
            } else {
                result.errors.forEach(error => showToast(error, 'error'));
            }
        });
        
        // --- Search and Data Fetching ---
        let debounceTimer;
        searchInput.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                const query = searchInput.value;
                fetchTransactions(query);
            }, 300);
        });

        async function fetchTransactions(query = '') {
            try {
                const response = await fetch(`?search_query=${encodeURIComponent(query)}`);
                const transactions = await response.json();
                renderTable(transactions);
            } catch (error) {
                console.error('Failed to fetch transactions:', error);
                showToast('Failed to load transactions. Please try again.', 'error');
            }
        }

        // --- Export Functions ---
        exportExcelbtn1.addEventListener('click', () => {
            const table = document.getElementById('transactionsTable');
            const ws = XLSX.utils.table_to_sheet(table);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Stock-Out Transactions");
            XLSX.writeFile(wb, "stock_out_transactions.xlsx");
        });

        exportPdfbtn1.addEventListener('click', () => {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            doc.text("Stock-Out Transactions Report", 14, 20);
            doc.autoTable({
                html: '#transactionsTable',
                startY: 30,
                styles: { fontSize: 10, cellPadding: 2, overflow: 'linebreak' },
                headStyles: { fillColor: [231, 76, 60], textColor: 255 },
                margin: { top: 25 },
                didDrawPage: function (data) {
                    doc.text("Page " + doc.internal.getNumberOfPages(), data.settings.margin.left, doc.internal.pageSize.height - 10);
                }
            });
            doc.save("stock_out_transactions.pdf");
        });
        
        // --- Initial Load ---
        <?php if (!empty($success_message)): ?>
            showToast('<?php echo addslashes($success_message); ?>', 'success');
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                showToast('<?php echo addslashes($error); ?>', 'error');
            <?php endforeach; ?>
        <?php endif; ?>
    </script>
</body>
</html>