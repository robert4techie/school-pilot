<?php
require_once 'conn.php';
require_once 'auth.php';
require_once 'tracking.php';
$tracker->trackAction("Stationery low stock alerts");

// Fetch all items with current stock less than minimum stock
$sql = "SELECT * FROM stationery_items WHERE current_stock < min_stock ORDER BY current_stock ASC";
$result = $conn->query($sql);

$low_stock_items = [];
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $low_stock_items[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Low Stock Alerts | School Pilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
            background: linear-gradient(135deg, var(--warning-color) 0%, #f1c40f 100%);
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
            background: var(--warning-color);
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
            background-color: #fef8f0;
        }

        .actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .action-link {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: white;
            padding: 8px 15px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .action-link:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(30, 132, 73, 0.3);
        }
        
        .text-center {
            text-align: center;
        }
    </style>
</head>
<body>
    <?php require_once 'nav.php';?>
    <div class="container">
        <div class="header">
            <h2><i class="fas fa-exclamation-triangle"></i> Low Stock Alerts</h2>
            <p>The following items have stock levels below their minimum threshold.</p>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Category</th>
                        <th>Current Stock</th>
                        <th>Min. Stock</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($low_stock_items)): ?>
                        <?php foreach ($low_stock_items as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                <td><?php echo htmlspecialchars($item['category']); ?></td>
                                <td><?php echo htmlspecialchars($item['current_stock']); ?></td>
                                <td><?php echo htmlspecialchars($item['min_stock']); ?></td>
                                <td class="text-center">
                                    <a href="stationery_adjustments.php" class="action-link">
                                        <i class="fas fa-boxes"></i> Adjust Stock
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center">No low stock items. Everything is in order!</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>