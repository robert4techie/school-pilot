<?php
ob_start();
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Stationery usage reports");

// Function to fetch report data based on filters
function fetchReportData($conn, $start_date, $end_date, $item_filter, $category_filter, $report_by) {
    $filter_sql = "t.type IN ('distribution', 'withdrawal') ";
    $params = [];
    $types = "";

    $filter_sql .= " AND t.created_at >= ? AND t.created_at <= ?";
    $params[] = $start_date . " 00:00:00";
    $params[] = $end_date . " 23:59:59";
    $types .= "ss";

    if (!empty($item_filter)) {
        $filter_sql .= " AND t.item_id = ?";
        $params[] = $item_filter;
        $types .= "i";
    }
    if (!empty($category_filter)) {
        $filter_sql .= " AND i.category = ?";
        $params[] = $category_filter;
        $types .= "s";
    }

    // Fetch data for the main chart
    $date_format_sql = ($report_by == 'month') ? "%Y-%m" : "%Y-%m-%d";
    $sql_chart_data = "
        SELECT DATE_FORMAT(t.created_at, ?) AS date_label, SUM(t.quantity) AS total_quantity
        FROM stationery_transactions t 
        JOIN stationery_items i ON t.item_id = i.item_id
        WHERE " . $filter_sql . "
        GROUP BY date_label
        ORDER BY date_label
    ";
    $params_chart = array_merge([$date_format_sql], $params);
    $types_chart = "s" . $types;

    $stmt_chart = $conn->prepare($sql_chart_data);
    $stmt_chart->bind_param($types_chart, ...$params_chart);
    $stmt_chart->execute();
    $result_chart = $stmt_chart->get_result();

    $chart_labels = [];
    $chart_data = [];
    while ($row = $result_chart->fetch_assoc()) {
        $chart_labels[] = $row['date_label'];
        $chart_data[] = $row['total_quantity'];
    }
    $stmt_chart->close();

    // Fetch data for top 5 most used items
    $sql_top_items = "
        SELECT i.item_name, SUM(t.quantity) AS total_quantity
        FROM stationery_transactions t 
        JOIN stationery_items i ON t.item_id = i.item_id
        WHERE " . $filter_sql . "
        GROUP BY i.item_name
        ORDER BY total_quantity DESC
        LIMIT 5
    ";
    $stmt_top_items = $conn->prepare($sql_top_items);
    $stmt_top_items->bind_param($types, ...$params);
    $stmt_top_items->execute();
    $result_top_items = $stmt_top_items->get_result();
    $top_items = [];
    while ($row = $result_top_items->fetch_assoc()) {
        $top_items[] = $row;
    }
    $stmt_top_items->close();

    // Fetch data for category distribution pie chart
    $sql_category_data = "
        SELECT i.category, SUM(t.quantity) AS total_quantity
        FROM stationery_transactions t 
        JOIN stationery_items i ON t.item_id = i.item_id
        WHERE " . $filter_sql . "
        GROUP BY i.category
        ORDER BY total_quantity DESC
    ";
    $stmt_category = $conn->prepare($sql_category_data);
    $stmt_category->bind_param($types, ...$params);
    $stmt_category->execute();
    $result_category = $stmt_category->get_result();
    $category_data = [];
    while ($row = $result_category->fetch_assoc()) {
        $category_data[] = $row;
    }
    $stmt_category->close();

    // Fetch detailed transaction log
    $sql_transactions = "
        SELECT t.*, i.item_name, i.category
        FROM stationery_transactions t 
        JOIN stationery_items i ON t.item_id = i.item_id
        WHERE " . $filter_sql . "
        ORDER BY t.created_at DESC LIMIT 20
    ";
    $stmt_transactions = $conn->prepare($sql_transactions);
    $stmt_transactions->bind_param($types, ...$params);
    $stmt_transactions->execute();
    $result_transactions = $stmt_transactions->get_result();
    $transactions = [];
    while($row = $result_transactions->fetch_assoc()) {
        $transactions[] = $row;
    }
    $stmt_transactions->close();

    return [
        'chart_labels' => $chart_labels,
        'chart_data' => $chart_data,
        'top_items' => $top_items,
        'category_data' => $category_data,
        'transactions' => $transactions
    ];
}

// Handle AJAX request for filtered data
if (isset($_GET['action']) && $_GET['action'] == 'filter') {
    ob_clean();
    header('Content-Type: application/json');

    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $item_filter = $_GET['item_filter'] ?? '';
    $category_filter = $_GET['category_filter'] ?? '';
    $report_by = $_GET['report_by'] ?? 'month';

    $reportData = fetchReportData($conn, $start_date, $end_date, $item_filter, $category_filter, $report_by);
    echo json_encode($reportData);
    exit;
}

// Initial page load
$start_date = date('Y-m-01');
$end_date = date('Y-m-d');
$item_filter = '';
$category_filter = '';
$report_by = 'month';
$reportData = fetchReportData($conn, $start_date, $end_date, $item_filter, $category_filter, $report_by);

// Fetch all items and categories for dropdowns
$sql_items_dropdown = "SELECT item_id, item_name FROM stationery_items ORDER BY item_name";
$result_items_dropdown = $conn->query($sql_items_dropdown);
$items = [];
while($row = $result_items_dropdown->fetch_assoc()) {
    $items[] = $row;
}

$sql_categories_dropdown = "SELECT DISTINCT category FROM stationery_items ORDER BY category";
$result_categories_dropdown = $conn->query($sql_categories_dropdown);
$categories = [];
while($row = $result_categories_dropdown->fetch_assoc()) {
    $categories[] = $row;
}
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usage Reports | School Pilot</title>
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
            --report-color: #1e8449; /* Green color for reports */
            --background-color: #f8f9fa;
            --card-background: #ffffff;
            --text-color: #2c3e50;
            --border-color: #e0e0e0;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: linear-gradient(135deg, var(--background-color) 0%, #e9ecef 100%); color: var(--text-color); line-height: 1.6; min-height: 100vh; padding: 20px; }
        .container { max-width: 100%; margin: 0 auto; margin-top: 50px; background: var(--card-background); border-radius: 12px; box-shadow: var(--shadow); overflow: hidden; }
        .header { background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%); color: white; padding: 25px; text-align: center; }
        .header h2 { font-size: 2rem; font-weight: 400; margin-bottom: 5px; }
        .header p { font-size: 1rem; opacity: 0.9; }
        .content-section { padding: 40px; }
        .filter-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; align-items: flex-end; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-weight: 600; margin-bottom: 8px; color: var(--text-color); }
        .form-group input, .form-group select { padding: 12px; border: 2px solid var(--border-color); border-radius: 8px; font-size: 1rem; transition: all 0.3s ease; background-color: #fafafa; }
        .form-group select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' class='feather feather-chevron-down'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 1rem center; background-size: 1.2rem; padding-right: 3rem; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(30, 132, 73, 0.1); }
        .btn-container { grid-column: 1 / -1; display: flex; justify-content: flex-end; gap: 15px; }
        .btn { padding: 14px 30px; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn-primary { background: linear-gradient(135deg, var(--report-color) 0%, #17713d 100%); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 12px rgba(30, 132, 73, 0.3); }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { transform: translateY(-2px); }
        h3 { margin-top: 40px; margin-bottom: 20px; font-size: 1.5rem; color: var(--text-color); border-bottom: 2px solid var(--border-color); padding-bottom: 10px; }
        .export-buttons { display: flex; gap: 10px; margin-bottom: 20px; }
        .export-btn { padding: 10px 20px; border: none; border-radius: 50px; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; gap: 8px; color: white; }
        .export-btn.excel { background-color: #21a366; }
        .export-btn.excel:hover { background-color: #1a7e4b; }
        .export-btn.pdf { background-color: #e74c3c; }
        .export-btn.pdf:hover { background-color: #c0392b; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; background-color: var(--card-background); border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin-top: 20px; }
        thead tr { background: var(--primary-light); color: white; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid var(--border-color); transition: background-color 0.3s ease; }
        th:first-child, td:first-child { padding-left: 20px; }
        th:last-child, td:last-child { padding-right: 20px; }
        tbody tr:nth-child(even) { background-color: #f9f9f9; }
        tbody tr:hover { background-color: #f0f0f0; }
        .text-center { text-align: center; }
        .chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 30px; }
        .chart-container { background: #f0f4f7; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .chart-container h4 { text-align: center; margin-bottom: 15px; font-weight: 600; color: var(--text-color); }
        .transaction-badge { padding: 5px 10px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; color: white; }
        .badge-distribution { background-color: #1abc9c; }
        .badge-withdrawal { background-color: var(--error-color); }
        .loader { border: 4px solid rgba(255, 255, 255, 0.3); border-top: 4px solid #fff; border-radius: 50%; width: 20px; height: 20px; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <?php require_once 'nav.php';?>
    <div class="container">
        <div class="header">
            <h2><i class="fas fa-chart-pie"></i> Usage Reports</h2>
            <p>Generate detailed reports on item usage and trends.</p>
        </div>

        <div class="content-section">
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
                    <label for="report_by">Report Grouping</label>
                    <select id="report_by" name="report_by">
                        <option value="month" <?php echo ($report_by == 'month') ? 'selected' : ''; ?>>By Month</option>
                        <option value="day" <?php echo ($report_by == 'day') ? 'selected' : ''; ?>>By Day</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="item_filter">Filter by Item</label>
                    <select id="item_filter" name="item_filter">
                        <option value="">All Items</option>
                        <?php foreach ($items as $item): ?>
                            <option value="<?php echo $item['item_id']; ?>" <?php echo ($item_filter == $item['item_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($item['item_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
                <div class="btn-container">
                    <button type="submit" class="btn btn-primary" id="generateReportBtn">
                        <i class="fas fa-search"></i> Generate Report
                    </button>
                    <a href="stationery_usage_reports.php" class="btn btn-secondary"><i class="fas fa-sync-alt"></i> Reset Filters</a>
                </div>
            </form>
            
            <div id="reportContent">
                <?php if (!empty($reportData['chart_data'])): ?>
                    <h3>Usage Summary</h3>
                    <div class="export-buttons">
                        <button class="export-btn excel" id="exportExcelBtn"><i class="fas fa-file-excel"></i> Export to Excel</button>
                        <button class="export-btn pdf" id="exportPdfBtn"><i class="fas fa-file-pdf"></i> Export to PDF</button>
                    </div>
                    <div class="chart-grid">
                        <div class="chart-container">
                            <h4>Quantity Withdrawn Over Time</h4>
                            <canvas id="usageOverTimeChart"></canvas>
                        </div>
                        <div class="chart-container">
                            <h4>Top 5 Most Used Items</h4>
                            <canvas id="topItemsChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-grid" style="grid-template-columns: 1fr;">
                        <div class="chart-container">
                            <h4>Usage Distribution by Category</h4>
                            <canvas id="categoryDistributionChart" style="max-height: 400px;"></canvas>
                        </div>
                    </div>
                <?php endif; ?>

                <h3>Detailed Transaction Log</h3>
                <table id="transactionsTable">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Type</th>
                            <th>Quantity</th>
                            <th>Recipient</th>
                            <th>Reason</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody id="transactionsTableBody">
                        <?php if (!empty($reportData['transactions'])): ?>
                            <?php foreach ($reportData['transactions'] as $transaction): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($transaction['item_name']); ?></td>
                                    <td><?php echo htmlspecialchars($transaction['category']); ?></td>
                                    <td>
                                        <span class="transaction-badge badge-<?php echo strtolower(htmlspecialchars($transaction['type'])); ?>">
                                            <?php echo ucfirst(htmlspecialchars($transaction['type'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($transaction['quantity']); ?></td>
                                    <td><?php echo htmlspecialchars($transaction['recipient_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($transaction['reason']); ?></td>
                                    <td><?php echo (new DateTime($transaction['created_at']))->format('Y-m-d H:i'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center">No transactions found for the selected filters.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        const filterForm = document.getElementById('filterForm');
        const reportContent = document.getElementById('reportContent');

        let usageOverTimeChart, topItemsChart, categoryDistributionChart;

        function renderCharts(data) {
            const chartLabels = data.chart_labels;
            const chartData = data.chart_data;
            const topItems = data.top_items;
            const categoryData = data.category_data;

            // Destroy old charts to prevent redraw issues
            if (usageOverTimeChart) usageOverTimeChart.destroy();
            if (topItemsChart) topItemsChart.destroy();
            if (categoryDistributionChart) categoryDistributionChart.destroy();

            if (chartLabels.length > 0) {
                // Line Chart for Usage Over Time
                usageOverTimeChart = new Chart(document.getElementById('usageOverTimeChart'), {
                    type: 'line',
                    data: {
                        labels: chartLabels,
                        datasets: [{
                            label: 'Quantity Withdrawn',
                            data: chartData,
                            borderColor: 'rgba(30, 132, 73, 1)',
                            backgroundColor: 'rgba(30, 132, 73, 0.2)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: { y: { beginAtZero: true } }
                    }
                });
            }

            if (topItems.length > 0) {
                // Horizontal Bar Chart for Top 5 Items
                topItemsChart = new Chart(document.getElementById('topItemsChart'), {
                    type: 'bar',
                    data: {
                        labels: topItems.map(item => item.item_name),
                        datasets: [{
                            label: 'Total Quantity',
                            data: topItems.map(item => item.total_quantity),
                            backgroundColor: 'rgba(39, 174, 96, 0.7)',
                            borderColor: 'rgba(39, 174, 96, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        scales: { x: { beginAtZero: true } }
                    }
                });
            }
            
            if (categoryData.length > 0) {
                const backgroundColors = [
                    '#1abc9c', '#3498db', '#f1c40f', '#e74c3c', '#9b59b6',
                    '#2ecc71', '#34495e', '#f39c12', '#2980b9', '#c0392b'
                ];
                // Pie Chart for Category Distribution
                categoryDistributionChart = new Chart(document.getElementById('categoryDistributionChart'), {
                    type: 'doughnut',
                    data: {
                        labels: categoryData.map(cat => cat.category),
                        datasets: [{
                            label: 'Quantity Withdrawn',
                            data: categoryData.map(cat => cat.total_quantity),
                            backgroundColor: backgroundColors.slice(0, categoryData.length),
                            hoverOffset: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'right' },
                            title: { display: false }
                        }
                    }
                });
            }
        }
        
        function renderTable(transactions) {
            const transactionsTableBody = document.getElementById('transactionsTableBody');
            transactionsTableBody.innerHTML = '';

            if (transactions.length === 0) {
                transactionsTableBody.innerHTML = `<tr><td colspan="7" class="text-center">No transactions found for the selected filters.</td></tr>`;
                return;
            }

            transactions.forEach(transaction => {
                const row = document.createElement('tr');
                const date = new Date(transaction.created_at);
                const formattedDate = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
                
                const badgeClass = (transaction.type === 'distribution') ? 'badge-distribution' : 'badge-withdrawal';
                const typeText = transaction.type.charAt(0).toUpperCase() + transaction.type.slice(1);

                row.innerHTML = `
                    <td>${transaction.item_name}</td>
                    <td>${transaction.category}</td>
                    <td><span class="transaction-badge ${badgeClass}">${typeText}</span></td>
                    <td>${transaction.quantity}</td>
                    <td>${transaction.recipient_name || 'N/A'}</td>
                    <td>${transaction.reason}</td>
                    <td>${formattedDate}</td>
                `;
                transactionsTableBody.appendChild(row);
            });
        }
        
        async function fetchReport() {
            const formData = new FormData(filterForm);
            const params = new URLSearchParams(formData);

            try {
                const response = await fetch(`stationery_usage_reports.php?action=filter&${params.toString()}`);
                const data = await response.json();
                
                if (data.chart_labels.length > 0) {
                    document.getElementById('reportContent').innerHTML = `
                        <h3>Usage Summary</h3>
                        <div class="export-buttons">
                            <button class="export-btn excel" id="exportExcelBtn"><i class="fas fa-file-excel"></i> Export to Excel</button>
                            <button class="export-btn pdf" id="exportPdfBtn"><i class="fas fa-file-pdf"></i> Export to PDF</button>
                        </div>
                        <div class="chart-grid">
                            <div class="chart-container">
                                <h4>Quantity Withdrawn Over Time</h4>
                                <canvas id="usageOverTimeChart"></canvas>
                            </div>
                            <div class="chart-container">
                                <h4>Top 5 Most Used Items</h4>
                                <canvas id="topItemsChart"></canvas>
                            </div>
                        </div>
                        <div class="chart-grid" style="grid-template-columns: 1fr;">
                            <div class="chart-container">
                                <h4>Usage Distribution by Category</h4>
                                <canvas id="categoryDistributionChart" style="max-height: 400px;"></canvas>
                            </div>
                        </div>
                        <h3>Detailed Transaction Log</h3>
                        <table id="transactionsTable">
                            <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th>Category</th>
                                    <th>Type</th>
                                    <th>Quantity</th>
                                    <th>Recipient</th>
                                    <th>Reason</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody id="transactionsTableBody"></tbody>
                        </table>
                    `;
                    renderCharts(data);
                    renderTable(data.transactions);
                } else {
                    document.getElementById('reportContent').innerHTML = `
                        <h3>Usage Summary</h3>
                        <p class="text-center">No data available for the selected filters.</p>
                        <h3>Detailed Transaction Log</h3>
                        <table id="transactionsTable">
                            <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th>Category</th>
                                    <th>Type</th>
                                    <th>Quantity</th>
                                    <th>Recipient</th>
                                    <th>Reason</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody id="transactionsTableBody">
                                <tr>
                                    <td colspan="7" class="text-center">No transactions found for the selected filters.</td>
                                </tr>
                            </tbody>
                        </table>
                    `;
                }
                
                // Re-attach export event listeners
                document.getElementById('exportExcelBtn')?.addEventListener('click', exportToExcel);
                document.getElementById('exportPdfBtn')?.addEventListener('click', exportToPdf);

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
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Usage Report");
            XLSX.writeFile(wb, "stationery_usage_report.xlsx");
        }

        function exportToPdf() {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            doc.text("Stationery Usage Report", 14, 20);
            doc.autoTable({
                html: '#transactionsTable',
                startY: 30,
                styles: { fontSize: 10, cellPadding: 2, overflow: 'linebreak' },
                headStyles: { fillColor: [30, 132, 73], textColor: 255 },
                margin: { top: 25 },
                didDrawPage: function (data) {
                    doc.text("Page " + doc.internal.getNumberOfPages(), data.settings.margin.left, doc.internal.pageSize.height - 10);
                }
            });
            doc.save("stationery_usage_report.pdf");
        }
        
        // Initial chart rendering
        document.addEventListener('DOMContentLoaded', () => {
            const initialData = {
                chart_labels: <?php echo json_encode($reportData['chart_labels']); ?>,
                chart_data: <?php echo json_encode($reportData['chart_data']); ?>,
                top_items: <?php echo json_encode($reportData['top_items']); ?>,
                category_data: <?php echo json_encode($reportData['category_data']); ?>,
                transactions: <?php echo json_encode($reportData['transactions']); ?>
            };
            renderCharts(initialData);
            
            document.getElementById('exportExcelBtn')?.addEventListener('click', exportToExcel);
            document.getElementById('exportPdfBtn')?.addEventListener('click', exportToPdf);
        });
    </script>
</body>
</html>