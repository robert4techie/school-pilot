<?php
// Start output buffering to prevent headers from being sent prematurely
ob_start();
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Stationery costs");

// Function to fetch report data based on filters
function fetchReportData($conn, $start_date, $end_date, $category_filter)
{
    $filter_sql = "t.type IN ('distribution', 'withdrawal') ";
    $params = [];
    $types = "";

    $filter_sql .= " AND t.created_at >= ? AND t.created_at <= ?";
    $params[] = $start_date . " 00:00:00";
    $params[] = $end_date . " 23:59:59";
    $types .= "ss";

    if (!empty($category_filter)) {
        $filter_sql .= " AND i.category = ?";
        $params[] = $category_filter;
        $types .= "s";
    }

    // Calculate total current stock value
    $sql_stock_value = "SELECT SUM(current_stock * unit_price) AS total_value FROM stationery_items";
    $result_stock_value = $conn->query($sql_stock_value);
    $total_stock_value = $result_stock_value->fetch_assoc()['total_value'] ?? 0;

    // Calculate total cost of withdrawals
    $sql_withdrawal_cost = "SELECT SUM(t.quantity * i.unit_price) AS total_cost FROM stationery_transactions t JOIN stationery_items i ON t.item_id = i.item_id WHERE " . $filter_sql;
    $stmt_withdrawal = $conn->prepare($sql_withdrawal_cost);
    $stmt_withdrawal->bind_param($types, ...$params);
    $stmt_withdrawal->execute();
    $total_withdrawal_cost = $stmt_withdrawal->get_result()->fetch_assoc()['total_cost'] ?? 0;
    $stmt_withdrawal->close();

    // Fetch data for cost over time chart
    $sql_cost_over_time = "
        SELECT DATE_FORMAT(t.created_at, '%Y-%m-%d') AS date_label, SUM(t.quantity * i.unit_price) AS total_cost
        FROM stationery_transactions t 
        JOIN stationery_items i ON t.item_id = i.item_id
        WHERE " . $filter_sql . "
        GROUP BY date_label
        ORDER BY date_label
    ";
    $stmt_cost_over_time = $conn->prepare($sql_cost_over_time);
    $stmt_cost_over_time->bind_param($types, ...$params);
    $stmt_cost_over_time->execute();
    $result_cost_over_time = $stmt_cost_over_time->get_result();

    $cost_labels = [];
    $cost_data = [];
    while ($row = $result_cost_over_time->fetch_assoc()) {
        $cost_labels[] = $row['date_label'];
        $cost_data[] = $row['total_cost'];
    }
    $stmt_cost_over_time->close();

    // Fetch data for cost by category chart
    $sql_cost_by_category = "
        SELECT i.category, SUM(t.quantity * i.unit_price) AS total_cost
        FROM stationery_transactions t 
        JOIN stationery_items i ON t.item_id = i.item_id
        WHERE " . $filter_sql . "
        GROUP BY i.category
        ORDER BY total_cost DESC
    ";
    $stmt_cost_by_category = $conn->prepare($sql_cost_by_category);
    $stmt_cost_by_category->bind_param($types, ...$params);
    $stmt_cost_by_category->execute();
    $result_cost_by_category = $stmt_cost_by_category->get_result();
    $cost_by_category = [];
    while ($row = $result_cost_by_category->fetch_assoc()) {
        $cost_by_category[] = $row;
    }
    $stmt_cost_by_category->close();

    // Fetch detailed transaction log
    $sql_transactions = "
        SELECT t.*, i.item_name, i.category, i.unit_price
        FROM stationery_transactions t 
        JOIN stationery_items i ON t.item_id = i.item_id
        WHERE " . $filter_sql . "
        ORDER BY t.created_at DESC
    ";
    $stmt_transactions = $conn->prepare($sql_transactions);
    $stmt_transactions->bind_param($types, ...$params);
    $stmt_transactions->execute();
    $result_transactions = $stmt_transactions->get_result();

    $transactions = [];
    while ($row = $result_transactions->fetch_assoc()) {
        $transactions[] = $row;
    }
    $stmt_transactions->close();

    return [
        'total_stock_value' => number_format($total_stock_value, 2),
        'total_withdrawal_cost' => number_format($total_withdrawal_cost, 2),
        'cost_labels' => $cost_labels,
        'cost_data' => $cost_data,
        'cost_by_category' => $cost_by_category,
        'transactions' => $transactions
    ];
}

// Handle AJAX request
if (isset($_GET['action']) && $_GET['action'] == 'filter') {
    ob_clean();
    header('Content-Type: application/json');

    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $category_filter = $_GET['category_filter'] ?? '';

    $reportData = fetchReportData($conn, $start_date, $end_date, $category_filter);
    echo json_encode($reportData);
    exit;
}

// Initial page load
$start_date = date('Y-m-01');
$end_date = date('Y-m-d');
$category_filter = '';
$reportData = fetchReportData($conn, $start_date, $end_date, $category_filter);

// Fetch all categories for dropdowns
$sql_categories = "SELECT DISTINCT category FROM stationery_items ORDER BY category";
$result_categories = $conn->query($sql_categories);
$categories = [];
if ($result_categories->num_rows > 0) {
    while ($row = $result_categories->fetch_assoc()) {
        $categories[] = $row;
    }
}
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cost Analysis | School Pilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --info-color: #3498db;
            --cost-color: #f1c40f;
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

        .content-section {
            padding: 40px;
        }

        .main-metrics-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            align-items: flex-end;
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
        .form-group select {
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
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 132, 73, 0.1);
        }

        .btn1-container {
            grid-column: 1 / -1;
            display: flex;
            justify-content: flex-end;
            gap: 15px;
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

        .btn1-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: white;
        }

        .btn1-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(30, 132, 73, 0.3);
        }

        .btn1-secondary {
            background: #6c757d;
            color: white;
        }

        .btn1-secondary:hover {
            transform: translateY(-2px);
        }

        h3 {
            margin-top: 40px;
            margin-bottom: 20px;
            font-size: 1.5rem;
            color: var(--text-color);
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 10px;
        }

        .card {
            background: var(--card-background);
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            padding: 25px;
            text-align: center;
        }

        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--text-color);
        }

        .card-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--cost-color);
        }

        .card-icon {
            font-size: 2.5rem;
            color: var(--text-color);
            margin-bottom: 15px;
        }

        .card.total-stock-value .card-value {
            color: var(--success-color);
        }

        .card.total-stock-value .card-icon {
            color: var(--success-color);
        }

        .card.total-withdrawal-cost .card-value {
            color: var(--error-color);
        }

        .card.total-withdrawal-cost .card-icon {
            color: var(--error-color);
        }

        .chart-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 30px;
        }

        .chart-container {
            background: #f0f4f7;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .chart-container h4 {
            text-align: center;
            margin-bottom: 15px;
            font-weight: 600;
            color: var(--text-color);
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background-color: var(--card-background);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            margin-top: 20px;
        }

        thead tr {
            background: var(--primary-light);
            color: white;
        }

        th,
        td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.3s ease;
        }

        th:first-child,
        td:first-child {
            padding-left: 20px;
        }

        th:last-child,
        td:last-child {
            padding-right: 20px;
        }

        tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        tbody tr:hover {
            background-color: #f0f0f0;
        }

        .text-center {
            text-align: center;
        }

        .export-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-bottom: 20px;
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

        .loader {
            border: 4px solid var(--border-color);
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            width: 20px;
            height: 20px;
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
    </style>
</head>

<body>
    <?php require_once 'nav.php';?>
    <div class="container">
        <div class="header">
            <h2><i class="fas fa-dollar-sign"></i> Cost Analysis</h2>
            <p>Financial overview of your stationery inventory.</p>
        </div>

        <div class="content-section">
            <div id="costMetrics">
                <div class="main-metrics-grid">
                    <div class="card total-stock-value">
                        <i class="fas fa-boxes card-icon"></i>
                        <div class="card-title">Total Current Stock Value</div>
                        <div class="card-value" id="totalStockValue">UGX <?php echo $reportData['total_stock_value']; ?></div>
                    </div>
                    <div class="card total-withdrawal-cost">
                        <i class="fas fa-hand-holding-box card-icon"></i>
                        <div class="card-title">Total Withdrawal Cost</div>
                        <div class="card-value" id="totalWithdrawalCost">UGX <?php echo $reportData['total_withdrawal_cost']; ?></div>
                    </div>
                </div>
            </div>

            <form id="filterForm" class="filter-form">
                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                <div class="form-group">
                    <label for="category_filter">Filter by Category</label>
                    <select id="category_filter" name="category_filter">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo ($category_filter == $cat['category']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['category']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="btn1-container">
                    <button type="submit" class="btn1 btn1-primary" id="generateReportbtn1">
                        <i class="fas fa-search"></i> Generate Report
                    </button>
                    <a href="stationery_costs.php" class="btn1 btn1-secondary"><i class="fas fa-sync-alt"></i> Reset Filters</a>
                </div>
            </form>

            <div id="reportContent">
                <?php if (!empty($reportData['cost_data'])): ?>
                    <h3>Cost Breakdown</h3>
                    <div class="export-buttons">
                        <button class="export-btn1 excel" id="exportExcelbtn1"><i class="fas fa-file-excel"></i> Export to Excel</button>
                        <button class="export-btn1 pdf" id="exportPdfbtn1"><i class="fas fa-file-pdf"></i> Export to PDF</button>
                    </div>
                    <div class="chart-grid">
                        <div class="chart-container">
                            <h4>Withdrawal Cost Over Time</h4>
                            <canvas id="costOverTimeChart"></canvas>
                        </div>
                        <div class="chart-container">
                            <h4>Cost Distribution by Category</h4>
                            <canvas id="costByCategoryChart" style="max-height: 400px;"></canvas>
                        </div>
                    </div>
                <?php endif; ?>

                <h3>Detailed Transaction Log</h3>
                <table id="transactionsTable">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total Cost</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody id="transactionsTableBody">
                        <?php if (!empty($reportData['transactions'])): ?>
                            <?php foreach ($reportData['transactions'] as $transaction): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($transaction['item_name']); ?></td>
                                    <td><?php echo htmlspecialchars($transaction['category']); ?></td>
                                    <td><?php echo htmlspecialchars($transaction['quantity']); ?></td>
                                    <td>UGX <?php echo number_format($transaction['unit_price'], 2); ?></td>
                                    <td>UGX <?php echo number_format($transaction['quantity'] * $transaction['unit_price'], 2); ?></td>
                                    <td><?php echo (new DateTime($transaction['created_at']))->format('Y-m-d H:i'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center">No transactions found for the selected filters.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const filterForm = document.getElementById('filterForm');
        const totalStockValueEl = document.getElementById('totalStockValue');
        const totalWithdrawalCostEl = document.getElementById('totalWithdrawalCost');
        const reportContent = document.getElementById('reportContent');

        let costOverTimeChart, costByCategoryChart;

        function renderCharts(data) {
            const costLabels = data.cost_labels;
            const costData = data.cost_data;
            const costByCategory = data.cost_by_category;

            if (costOverTimeChart) costOverTimeChart.destroy();
            if (costByCategoryChart) costByCategoryChart.destroy();

            if (costLabels.length > 0) {
                costOverTimeChart = new Chart(document.getElementById('costOverTimeChart'), {
                    type: 'line',
                    data: {
                        labels: costLabels,
                        datasets: [{
                            label: 'Withdrawal Cost',
                            data: costData,
                            borderColor: 'rgba(30, 132, 73, 1)',
                            backgroundColor: 'rgba(30, 132, 73, 0.2)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return 'UGX ' + value.toLocaleString();
                                    }
                                }
                            }
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        let label = context.dataset.label || '';
                                        if (label) {
                                            label += ': ';
                                        }
                                        if (context.parsed.y !== null) {
                                            label += 'UGX ' + context.parsed.y.toLocaleString();
                                        }
                                        return label;
                                    }
                                }
                            }
                        }
                    }
                });
            }

            if (costByCategory.length > 0) {
                const backgroundColors = [
                    '#1abc9c', '#3498db', '#f1c40f', '#e74c3c', '#9b59b6',
                    '#2ecc71', '#34495e', '#f39c12', '#2980b9', '#c0392b'
                ];
                costByCategoryChart = new Chart(document.getElementById('costByCategoryChart'), {
                    type: 'doughnut',
                    data: {
                        labels: costByCategory.map(cat => cat.category),
                        datasets: [{
                            label: 'Total Cost',
                            data: costByCategory.map(cat => cat.total_cost),
                            backgroundColor: backgroundColors.slice(0, costByCategory.length),
                            hoverOffset: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'right'
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        let label = context.label || '';
                                        if (label) {
                                            label += ': ';
                                        }
                                        if (context.parsed !== null) {
                                            label += 'UGX ' + context.parsed.toLocaleString();
                                        }
                                        return label;
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }

        function renderTable(transactions) {
            const transactionsTableBody = document.getElementById('transactionsTableBody');
            transactionsTableBody.innerHTML = '';

            if (transactions.length === 0) {
                transactionsTableBody.innerHTML = `<tr><td colspan="6" class="text-center">No transactions found for the selected filters.</td></tr>`;
                return;
            }

            transactions.forEach(transaction => {
                const row = document.createElement('tr');
                const totalCost = (transaction.quantity * transaction.unit_price).toFixed(2);
                const date = new Date(transaction.created_at);
                const formattedDate = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;

                row.innerHTML = `
                    <td>${transaction.item_name}</td>
                    <td>${transaction.category}</td>
                    <td>${transaction.quantity}</td>
                    <td>UGX ${parseFloat(transaction.unit_price).toLocaleString('en-UG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                    <td>UGX ${parseFloat(totalCost).toLocaleString('en-UG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                    <td>${formattedDate}</td>
                `;
                transactionsTableBody.appendChild(row);
            });
        }

        async function fetchReport() {
            const formData = new FormData(filterForm);
            const params = new URLSearchParams(formData);

            try {
                const response = await fetch(`stationery_costs.php?action=filter&${params.toString()}`);
                const data = await response.json();

                totalStockValueEl.textContent = `UGX ${data.total_stock_value}`;
                totalWithdrawalCostEl.textContent = `UGX ${data.total_withdrawal_cost}`;

                if (data.cost_data.length > 0) {
                    reportContent.innerHTML = `
                        <h3>Cost Breakdown</h3>
                        <div class="export-buttons">
                            <button class="export-btn1 excel" id="exportExcelbtn1"><i class="fas fa-file-excel"></i> Export to Excel</button>
                            <button class="export-btn1 pdf" id="exportPdfbtn1"><i class="fas fa-file-pdf"></i> Export to PDF</button>
                        </div>
                        <div class="chart-grid">
                            <div class="chart-container">
                                <h4>Withdrawal Cost Over Time</h4>
                                <canvas id="costOverTimeChart"></canvas>
                            </div>
                            <div class="chart-container">
                                <h4>Cost Distribution by Category</h4>
                                <canvas id="costByCategoryChart" style="max-height: 400px;"></canvas>
                            </div>
                        </div>
                        <h3>Detailed Transaction Log</h3>
                        <table id="transactionsTable">
                            <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th>Category</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Total Cost</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody id="transactionsTableBody"></tbody>
                        </table>
                    `;
                    renderCharts(data);
                    renderTable(data.transactions);
                } else {
                    reportContent.innerHTML = `
                        <h3>Cost Breakdown</h3>
                        <p class="text-center">No data available for the selected filters.</p>
                        <h3>Detailed Transaction Log</h3>
                        <table id="transactionsTable">
                            <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th>Category</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Total Cost</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody id="transactionsTableBody">
                                <tr>
                                    <td colspan="6" class="text-center">No transactions found for the selected filters.</td>
                                </tr>
                            </tbody>
                        </table>
                    `;
                }

                document.getElementById('exportExcelbtn1')?.addEventListener('click', exportToExcel);
                document.getElementById('exportPdfbtn1')?.addEventListener('click', exportToPdf);

            } catch (error) {
                console.error('Failed to fetch report data:', error);
            }
        }

        filterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            fetchReport();
        });

        function exportToExcel() {
            const table = document.getElementById('transactionsTable');
            const ws = XLSX.utils.table_to_sheet(table);
            XLSX.utils.book_append_sheet(XLSX.utils.book_new(), ws, "Cost Analysis Report");
            XLSX.writeFile(XLSX.utils.book_new(), "stationery_cost_report.xlsx");
        }

        function exportToPdf() {
            const {
                jsPDF
            } = window.jspdf;
            const doc = new jsPDF();
            doc.text("Stationery Cost Analysis Report", 14, 20);
            doc.autoTable({
                html: '#transactionsTable',
                startY: 30,
                styles: {
                    fontSize: 10,
                    cellPadding: 2,
                    overflow: 'linebreak'
                },
                headStyles: {
                    fillColor: [30, 132, 73],
                    textColor: 255
                },
                margin: {
                    top: 25
                },
                didDrawPage: function(data) {
                    doc.text("Page " + doc.internal.getNumberOfPages(), data.settings.margin.left, doc.internal.pageSize.height - 10);
                }
            });
            doc.save("stationery_cost_report.pdf");
        }

        document.addEventListener('DOMContentLoaded', () => {
            const initialData = <?php echo json_encode($reportData); ?>;
            renderCharts(initialData);

            document.getElementById('exportExcelbtn1')?.addEventListener('click', exportToExcel);
            document.getElementById('exportPdfbtn1')?.addEventListener('click', exportToPdf);
        });
    </script>
</body>

</html>