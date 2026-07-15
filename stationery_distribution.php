<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Stationery distribution");

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

$errors = [];
$success_message = '';

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $item_id = $_POST['item_id'] ?? '';
    $quantity = $_POST['quantity'] ?? '';
    $recipient_name = trim($_POST['recipient_name'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    // Validation
    if (empty($item_id)) {
        $errors[] = "Please select an item.";
    }
    if (empty($quantity) || !is_numeric($quantity) || $quantity <= 0) {
        $errors[] = "Please enter a valid quantity.";
    }
    if (empty($recipient_name)) {
        $errors[] = "Recipient name is required.";
    }
    if (empty($errors)) {
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

                $sql_log = "INSERT INTO stationery_transactions (item_id, quantity, type, reason, recipient_name) VALUES (?, ?, 'distribution', ?, ?)";
                $stmt_log = $conn->prepare($sql_log);
                $stmt_log->bind_param("iiss", $item_id, $quantity, $reason, $recipient_name);
                $stmt_log->execute();
                $stmt_log->close();

                $conn->commit();
                $success_message = "Item distributed successfully!";
            } else {
                throw new Exception("Insufficient stock. Current stock for **" . htmlspecialchars($item_name) . "** is only " . $current_stock . ".");
            }
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = $e->getMessage();
        }
    }
}

// Fetch all stationery items for the form
$sql_items = "SELECT item_id, item_name, current_stock FROM stationery_items ORDER BY item_name";
$result_items = $conn->query($sql_items);
$items = [];
if ($result_items->num_rows > 0) {
    while($row = $result_items->fetch_assoc()) {
        $items[] = $row;
    }
}

// Fetch recent distributions for the log table
$sql_distributions = "SELECT t.*, i.item_name FROM stationery_transactions t JOIN stationery_items i ON t.item_id = i.item_id WHERE t.type = 'distribution' ORDER BY t.created_at DESC LIMIT 10";
$result_distributions = $conn->query($sql_distributions);
$distributions = [];
if ($result_distributions->num_rows > 0) {
    while($row = $result_distributions->fetch_assoc()) {
        $distributions[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Distribution | School Pilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1e8449;
            --primary-light: #27ae60;
            --primary-dark: #145a32;
            --accent-color: #2ecc71;
            --error-color: #e74c3c;
            --success-color: #27ae60;
            --info-color: #3498db;
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
            max-width: 1200px;
            margin: 0 auto;
            margin-top: 50px;
            background: var(--card-background);
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #1abc9c 0%, #16a085 100%);
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
        
        .content-section {
            padding: 40px;
        }
        
        .btn-add-container {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .btn {
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

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(30, 132, 73, 0.3);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--error-color) 0%, #c0392b 100%);
            color: white;
        }
        
        .btn-danger:hover {
            background: linear-gradient(135deg, #c0392b 0%, var(--error-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(231, 76, 60, 0.3);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
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
            border-color: var(--primary-color);
            background-color: white;
            box-shadow: 0 0 0 3px rgba(30, 132, 73, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .btn-container {
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

        .close-btn {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 10px;
            right: 20px;
            cursor: pointer;
        }

        .close-btn:hover,
        .close-btn:focus {
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
        }
    </style>
</head>
<body>
    <?php require_once 'nav.php';?>
    <div class="container">
        <div class="header">
            <h2><i class="fas fa-hand-holding-box"></i> Item Distribution</h2>
            <p>Record the distribution of stationery items to staff or students.</p>
        </div>

        <div class="content-section">
            <div class="btn-add-container">
                <button id="showDistributionModalBtn" class="btn btn-danger">
                    <i class="fas fa-share-square"></i> Distribute an Item
                </button>
            </div>
            
            <h3>Recent Distributions</h3>
            <table>
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Recipient</th>
                        <th>Quantity</th>
                        <th>Reason</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($distributions)): ?>
                        <?php foreach ($distributions as $distribution): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($distribution['item_name']); ?></td>
                                <td><?php echo htmlspecialchars($distribution['recipient_name']); ?></td>
                                <td><?php echo htmlspecialchars($distribution['quantity']); ?></td>
                                <td><?php echo htmlspecialchars($distribution['reason']); ?></td>
                                <td><?php echo (new DateTime($distribution['created_at']))->format('Y-m-d H:i'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center">No recent distributions found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div id="distributionModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal()">&times;</span>
            <div class="modal-header">
                <i class="fas fa-hand-holding-box"></i>
                <h3>Distribute Stationery Item</h3>
            </div>
            <div class="modal-body">
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">
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
                            <label for="quantity">Quantity to Distribute</label>
                            <input type="number" id="quantity" name="quantity" min="1" required>
                        </div>
                        <div class="form-group">
                            <label for="recipient_name">Recipient Name</label>
                            <input type="text" id="recipient_name" name="recipient_name" required>
                        </div>
                        <div class="form-group">
                            <label for="reason">Reason / Purpose</label>
                            <textarea id="reason" name="reason" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="btn-container">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-minus"></i> Distribute
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const distributionModal = document.getElementById('distributionModal');
        const itemSelect = document.getElementById('item_id');
        const quantityInput = document.getElementById('quantity');
        
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
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => { if (toast.parentNode) { toast.parentNode.removeChild(toast); } }, 300);
        }

        function closeModal() {
            distributionModal.style.display = 'none';
        }

        document.getElementById('showDistributionModalBtn').onclick = function() {
            distributionModal.style.display = 'flex';
        }

        itemSelect.addEventListener('change', function() {
            const selectedOption = itemSelect.options[itemSelect.selectedIndex];
            const stock = selectedOption.dataset.stock;
            quantityInput.max = stock; // Set max value for quantity input
        });

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            if (event.target == distributionModal) {
                closeModal();
            }
        }
        
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