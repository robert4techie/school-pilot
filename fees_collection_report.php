<?php
require_once 'conn.php';
require_once 'auth.php';
require_once 'tracking.php';
$tracker->trackAction("Fees collection report ");

function checkReportAccess($conn)
{
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit();
    }

    $user_id = $_SESSION['user_id'];
    $sql = "SELECT role FROM users WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $user_role = strtolower(trim($user['role']));

        $allowed_roles = ['bursar', 'super user', 'school leader', 'developer'];

        if (!in_array($user_role, $allowed_roles)) {
            $_SESSION['previous_page'] = $_SERVER['REQUEST_URI'];
            $_SESSION['access_denied_message'] = "Access Denied: You don't have permission to access the fees reports.";
            header("Location: access_denied.php");
            exit();
        }
    } else {
        session_destroy();
        header("Location: index.php");
        exit();
    }

    $stmt->close();
}

checkReportAccess($conn);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'get_collection_report':
            getCollectionReport($conn);
            break;
    }
    exit;
}

function getCollectionReport($conn)
{
    $term = mysqli_real_escape_string($conn, $_POST['term']);
    $year = intval($_POST['year']);

    if (empty($term) || empty($year)) {
        echo json_encode(['error' => 'Term and year are required']);
        return;
    }

    // Get all class, stream, and section (Day/Boarding) combinations with students
    $query = "
        SELECT DISTINCT 
            s.current_class as class_name,
            s.stream,
            s.section as student_type,
            COUNT(s.student_id) as student_count
        FROM students s 
        WHERE s.status = 'active'
        GROUP BY s.current_class, s.stream, s.section
        ORDER BY 
            CASE s.current_class
                WHEN 'Senior One' THEN 1
                WHEN 'Senior Two' THEN 2
                WHEN 'Senior Three' THEN 3
                WHEN 'Senior Four' THEN 4
                WHEN 'Senior Five' THEN 5
                WHEN 'Senior Six' THEN 6
                ELSE 7
            END,
            s.stream,
            CASE s.section
                WHEN 'Day' THEN 1
                WHEN 'Boarding' THEN 2
                ELSE 3
            END
    ";

    $result = mysqli_query($conn, $query);
    $report_data = [];
    $totals = [
        'expected_amount' => 0,
        'amount_collected' => 0,
        'fees_deficit' => 0,
        'student_count' => 0
    ];

    while ($row = mysqli_fetch_assoc($result)) {
        $class_name = $row['class_name'];
        $stream = $row['stream'];
        $student_type = $row['student_type'];
        $student_count = (int)$row['student_count'];

        // Get fee amount for this class and student type
        $fee_query = "
            SELECT amount 
            FROM fee_structures 
            WHERE class_name = '$class_name' 
            AND student_type = '$student_type'
            AND term = '$term' 
            AND year = $year
        ";
        $fee_result = mysqli_query($conn, $fee_query);
        $fee_amount = 0;

        if ($fee_row = mysqli_fetch_assoc($fee_result)) {
            $fee_amount = (int)$fee_row['amount'];
        }

        // Calculate expected amount (students × fee amount)
        $gross_expected = $student_count * $fee_amount;

        // Get total bursary discounts for this class/stream/section/term/year
        $bursary_query = "
            SELECT COALESCE(SUM(fb.bursary_discount), 0) as total_bursary
            FROM fees_bursaries fb
            JOIN students s ON fb.student_id = s.student_id
            WHERE s.current_class = '$class_name' 
            AND s.stream = '$stream'
            AND s.section = '$student_type'
            AND fb.term = '$term' 
            AND fb.academic_year = $year
            AND s.status = 'active'
        ";
        $bursary_result = mysqli_query($conn, $bursary_query);
        $total_bursary = 0;

        if ($bursary_row = mysqli_fetch_assoc($bursary_result)) {
            $total_bursary = (int)$bursary_row['total_bursary'];
        }

        // Net expected amount after bursaries
        $expected_amount = $gross_expected - $total_bursary;

        // Get actual amount collected for this class/stream/section/term/year
        $collection_query = "
            SELECT COALESCE(SUM(fp.amount_paid), 0) as amount_collected
            FROM fees_payments fp
            JOIN students s ON fp.student_id = s.student_id
            WHERE s.current_class = '$class_name' 
            AND s.stream = '$stream'
            AND s.section = '$student_type'
            AND fp.term = '$term' 
            AND fp.year = $year
            AND s.status = 'active'
        ";
        $collection_result = mysqli_query($conn, $collection_query);
        $amount_collected = 0;

        if ($collection_row = mysqli_fetch_assoc($collection_result)) {
            $amount_collected = (int)$collection_row['amount_collected'];
        }

        // Calculate metrics
        $fees_deficit = $expected_amount - $amount_collected;
        $collection_percentage = $expected_amount > 0 ? ($amount_collected / $expected_amount) * 100 : 0;
        $deficit_percentage = $expected_amount > 0 ? ($fees_deficit / $expected_amount) * 100 : 0;

        $report_data[] = [
            'class_name' => $class_name,
            'stream' => $stream,
            'student_type' => $student_type,
            'student_count' => $student_count,
            'fee_amount' => $fee_amount,
            'gross_expected' => $gross_expected,
            'total_bursary' => $total_bursary,
            'expected_amount' => $expected_amount,
            'amount_collected' => $amount_collected,
            'fees_deficit' => $fees_deficit,
            'collection_percentage' => round($collection_percentage, 2),
            'deficit_percentage' => round($deficit_percentage, 2)
        ];

        // Add to totals
        $totals['expected_amount'] += $expected_amount;
        $totals['amount_collected'] += $amount_collected;
        $totals['fees_deficit'] += $fees_deficit;
        $totals['student_count'] += $student_count;
    }

    // Calculate total percentages
    $totals['collection_percentage'] = $totals['expected_amount'] > 0 ?
        round(($totals['amount_collected'] / $totals['expected_amount']) * 100, 2) : 0;
    $totals['deficit_percentage'] = $totals['expected_amount'] > 0 ?
        round(($totals['fees_deficit'] / $totals['expected_amount']) * 100, 2) : 0;

    header('Content-Type: application/json');
    echo json_encode([
        'report_data' => $report_data,
        'totals' => $totals,
        'term' => $term,
        'year' => $year
    ]);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fees Collection Report</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        :root {
            --primary-green: #2e7d32;
            --dark-green: #1b5e20;
            --light-green: #81c784;
            --accent-green: #4caf50;
            --background: #f5f9f5;
            --white: #ffffff;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-400: #ced4da;
            --gray-500: #adb5bd;
            --gray-600: #6c757d;
            --gray-700: #495057;
            --gray-800: #343a40;
            --text-dark: #212529;
            --shadow: 0 2px 10px rgba(46, 125, 50, 0.1);
            --shadow-lg: 0 10px 30px rgba(46, 125, 50, 0.15);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            margin-top: 55px;
            padding: 10px;
        }

        .header {
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            text-align: center;
        }

        .header h2 {
            font-size: 1.5rem;
            margin-bottom: 10px;
        }

        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .controls-section {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .filters-row {
            display: flex;
            gap: 20px;
            align-items: end;
            flex-wrap: wrap;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }

        .filter-select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .filter-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btnn {
            font-family: inherit;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btnn-primary {
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            color: white;
        }

        .btnn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .btnn-success {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
        }

        .btnn-info {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
        }

        .export-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .report-section {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .report-header {
            background: #f8f9fa;
            padding: 20px 25px;
            border-bottom: 1px solid #dee2e6;
        }

        .report-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #495057;
            margin: 0;
        }

        .report-info {
            font-size: 0.9rem;
            color: #6c757d;
            margin-top: 5px;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 15px 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        tr:hover {
            background-color: #f8f9fa;
        }

        .number-cell {
            text-align: right;
            font-family: 'Courier New', monospace;
        }

        .percentage-cell {
            text-align: center;
        }

        .status-paid {
            color: #28a745;
            font-weight: 600;
        }

        .status-partial {
            color: #ffc107;
            font-weight: 600;
        }

        .status-deficit {
            color: #dc3545;
            font-weight: 600;
        }

        .progress-bar {
            width: 100px;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin: 0 auto;
        }

        .progress-fill {
            height: 100%;
            transition: width 0.3s ease;
        }

        .progress-success {
            background: linear-gradient(90deg, #28a745, #20c997);
        }

        .progress-warning {
            background: linear-gradient(90deg, #ffc107, #fd7e14);
        }

        .progress-danger {
            background: linear-gradient(90deg, #dc3545, #e83e8c);
        }

        .totals-row {
            background: #e7f3ff;
            font-weight: 600;
            border-top: 2px solid #007bff;
        }

        .totals-row td {
            border-bottom: none;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #6c757d;
            font-style: italic;
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .summary-card h3 {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-card .value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .summary-card .subtitle {
            font-size: 0.8rem;
            color: #9ca3af;
        }

        .card-expected .value {
            color: #3b82f6;
        }

        .card-collected .value {
            color: #10b981;
        }

        .card-deficit .value {
            color: #ef4444;
        }

        .card-percentage .value {
            color: #8b5cf6;
        }

        .student-type-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-day {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .badge-boarding {
            background-color: #fed7aa;
            color: #c2410c;
        }

        @media (max-width: 768px) {
            .filters-row {
                flex-direction: column;
            }

            .filter-group {
                min-width: 100%;
            }

            .export-buttons {
                flex-direction: column;
            }

            .header h2 {
                font-size: 2rem;
            }
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
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
    <?php require_once 'nav.php'; ?>

    <div class="container">
        <!-- Header -->
        <div class="header">
            <h2><i class="fas fa-chart-bar"></i> Fees Collection Report</h2>
            <p>Comprehensive analysis of school fees collection by class and stream</p>
        </div>

        <!-- Controls Section -->
        <div class="controls-section">
            <div class="filters-row">
                <div class="filter-group">
                    <label class="filter-label">Academic Year *</label>
                    <select class="filter-select" id="yearFilter" required>
                        <option value="">Select Year</option>
                        <option value="2024">2024</option>
                        <option value="2025" selected>2025</option>
                        <option value="2026">2026</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Term *</label>
                    <select class="filter-select" id="termFilter" required>
                        <option value="">Select Term</option>
                        <option value="Term One">Term One</option>
                        <option value="Term Two">Term Two</option>
                        <option value="Term Three">Term Three</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button class="btnn btnn-primary" onclick="generateReport()">
                        <i class="fas fa-chart-line"></i>
                        Generate Report
                    </button>
                </div>
            </div>

            <div class="export-buttons" id="exportButtons" style="display: none;">
                <button class="btnn btnn-success" onclick="exportToPDF()">
                    <i class="fas fa-file-pdf"></i>
                    Export PDF
                </button>
                <button class="btnn btnn-info" onclick="exportToExcel()">
                    <i class="fas fa-file-excel"></i>
                    Export Excel
                </button>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards" id="summaryCards" style="display: none;">
            <div class="summary-card card-expected">
                <h3>Total Expected</h3>
                <div class="value" id="totalExpected">UGX 0</div>
                <div class="subtitle">Net amount after bursaries</div>
            </div>
            <div class="summary-card card-collected">
                <h3>Total Collected</h3>
                <div class="value" id="totalCollected">UGX 0</div>
                <div class="subtitle">Actual payments received</div>
            </div>
            <div class="summary-card card-deficit">
                <h3>Total Deficit</h3>
                <div class="value" id="totalDeficit">UGX 0</div>
                <div class="subtitle">Outstanding amount</div>
            </div>
            <div class="summary-card card-percentage">
                <h3>Collection Rate</h3>
                <div class="value" id="collectionRate">0%</div>
                <div class="subtitle">Overall performance</div>
            </div>
        </div>

        <!-- Report Section -->
        <div class="report-section">
            <div class="report-header">
                <h2 class="report-title">Collection Report by Class & Stream</h2>
                <div class="report-info" id="reportInfo">Select term and year to generate report</div>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Stream</th>
                            <th>Type</th>
                            <th>Students</th>
                            <th>Expected Amount</th>
                            <th>Amount Collected</th>
                            <th>Collection %</th>
                            <th>Progress</th>
                            <th>Fees Deficit</th>
                            <th>Deficit %</th>
                        </tr>
                    </thead>
                    <tbody id="reportTableBody">
                        <tr>
                            <td colspan="10" class="no-data">
                                Please select term and year, then click "Generate Report" to view collection data
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        let currentReportData = null;

        // Generate report
        function generateReport() {
            const term = document.getElementById('termFilter').value;
            const year = document.getElementById('yearFilter').value;

            if (!term || !year) {
                alert('Please select both term and year');
                return;
            }

            // Show loading
            document.getElementById('reportTableBody').innerHTML = `
                <tr>
                  <td colspan="10" class="loading">
                        <div class="spinner"></div>
                        Generating report for ${term} ${year}...
                    </td>
                </tr>
            `;

            const formData = new FormData();
            formData.append('action', 'get_collection_report');
            formData.append('term', term);
            formData.append('year', year);

            fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }

                    currentReportData = data;
                    displayReport(data);
                    updateSummaryCards(data.totals);

                    // Show export buttons
                    document.getElementById('exportButtons').style.display = 'flex';
                })
                .catch(error => {
                    console.error('Error generating report:', error);
                    document.getElementById('reportTableBody').innerHTML = `
                    <tr>
                        <td colspan="10" class="no-data">
                            Error generating report. Please try again.
                        </td>
                    </tr>
                `;
                });
        }

        // Display report data
        function displayReport(data) {
            const tbody = document.getElementById('reportTableBody');
            const reportInfo = document.getElementById('reportInfo');

            reportInfo.textContent = `Report for ${data.term} ${data.year} - Generated on ${new Date().toLocaleString()}`;

            if (!data.report_data || data.report_data.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="10" class="no-data">
                            No data found for ${data.term} ${data.year}
                        </td>
                    </tr>
                `;
                return;
            }

            // Generate table rows
            const rows = data.report_data.map(row => {
                const progressClass = row.collection_percentage >= 80 ? 'progress-success' :
                    row.collection_percentage >= 50 ? 'progress-warning' : 'progress-danger';

                const badgeClass = row.student_type === 'Day' ? 'badge-day' : 'badge-boarding';

                return `
                    <tr>
                        <td>${row.class_name}</td>
                        <td>${row.stream}</td>
                        <td>
                            <span class="student-type-badge ${badgeClass}">
                                ${row.student_type}
                            </span>
                        </td>
                        <td class="number-cell">${row.student_count}</td>
                        <td class="number-cell">UGX ${row.expected_amount.toLocaleString()}</td>
                        <td class="number-cell">UGX ${row.amount_collected.toLocaleString()}</td>
                        <td class="percentage-cell">${row.collection_percentage}%</td>
                        <td class="percentage-cell">
                            <div class="progress-bar">
                                <div class="progress-fill ${progressClass}" style="width: ${Math.min(row.collection_percentage, 100)}%"></div>
                            </div>
                        </td>
                        <td class="number-cell ${row.fees_deficit > 0 ? 'status-deficit' : 'status-paid'}">
                            UGX ${row.fees_deficit.toLocaleString()}
                        </td>
                        <td class="percentage-cell">${row.deficit_percentage}%</td>
                    </tr>
                `;
            }).join('');

            // Add totals row
            const totalProgressClass = data.totals.collection_percentage >= 80 ? 'progress-success' :
                data.totals.collection_percentage >= 50 ? 'progress-warning' : 'progress-danger';

            const totalsRow = `
                <tr class="totals-row">
                    <td colspan="3"><strong>TOTALS</strong></td>
                    <td class="number-cell"><strong>${data.totals.student_count}</strong></td>
                    <td class="number-cell"><strong>UGX ${data.totals.expected_amount.toLocaleString()}</strong></td>
                    <td class="number-cell"><strong>UGX ${data.totals.amount_collected.toLocaleString()}</strong></td>
                    <td class="percentage-cell"><strong>${data.totals.collection_percentage}%</strong></td>
                    <td class="percentage-cell">
                        <div class="progress-bar">
                            <div class="progress-fill ${totalProgressClass}" style="width: ${Math.min(data.totals.collection_percentage, 100)}%"></div>
                        </div>
                    </td>
                    <td class="number-cell ${data.totals.fees_deficit > 0 ? 'status-deficit' : 'status-paid'}">
                        <strong>UGX ${data.totals.fees_deficit.toLocaleString()}</strong>
                    </td>
                    <td class="percentage-cell"><strong>${data.totals.deficit_percentage}%</strong></td>
                </tr>
            `;

            tbody.innerHTML = rows + totalsRow;
        }

        // Update summary cards
        function updateSummaryCards(totals) {
            document.getElementById('totalExpected').textContent = `UGX ${totals.expected_amount.toLocaleString()}`;
            document.getElementById('totalCollected').textContent = `UGX ${totals.amount_collected.toLocaleString()}`;
            document.getElementById('totalDeficit').textContent = `UGX ${totals.fees_deficit.toLocaleString()}`;
            document.getElementById('collectionRate').textContent = `${totals.collection_percentage}%`;

            document.getElementById('summaryCards').style.display = 'grid';
        }

        // Export to PDF
        function exportToPDF() {
            if (!currentReportData) {
                alert('Please generate a report first');
                return;
            }

            const {
                jsPDF
            } = window.jspdf;
            const doc = new jsPDF('l', 'mm', 'a4'); // Landscape orientation

            // Title
            doc.setFontSize(20);
            doc.text('Fees Collection Report', 20, 20);

            doc.setFontSize(12);
            doc.text(`${currentReportData.term} ${currentReportData.year}`, 20, 30);
            doc.text(`Generated on: ${new Date().toLocaleString()}`, 20, 37);

            // Summary
            const totals = currentReportData.totals;
            doc.text(`Total Expected: UGX ${totals.expected_amount.toLocaleString()}`, 20, 50);
            doc.text(`Total Collected: UGX ${totals.amount_collected.toLocaleString()}`, 100, 50);
            doc.text(`Collection Rate: ${totals.collection_percentage}%`, 180, 50);

            // Prepare table data
            const tableData = currentReportData.report_data.map(row => [
                row.class_name,
                row.stream,
                row.student_type,
                row.student_count.toString(),
                `UGX ${row.expected_amount.toLocaleString()}`,
                `UGX ${row.amount_collected.toLocaleString()}`,
                `${row.collection_percentage}%`,
                `UGX ${row.fees_deficit.toLocaleString()}`,
                `${row.deficit_percentage}%`
            ]);

            // Add totals row
            tableData.push([
                'TOTALS',
                '',
                '',
                totals.student_count.toString(),
                `UGX ${totals.expected_amount.toLocaleString()}`,
                `UGX ${totals.amount_collected.toLocaleString()}`,
                `${totals.collection_percentage}%`,
                `UGX ${totals.fees_deficit.toLocaleString()}`,
                `${totals.deficit_percentage}%`
            ]);

            // Create table
            doc.autoTable({
                head: [
                    ['Class', 'Stream', 'Type', 'Students', 'Expected Amount', 'Amount Collected', 'Collection %', 'Fees Deficit', 'Deficit %']
                ],
                body: tableData,
                startY: 60,
                styles: {
                    fontSize: 8,
                    cellPadding: 2
                },
                headStyles: {
                    fillColor: [102, 126, 234],
                    textColor: 255,
                    fontStyle: 'bold'
                },
                alternateRowStyles: {
                    fillColor: [245, 247, 250]
                },
                bodyStyles: {
                    textColor: 51
                },
                columnStyles: {
                    2: {
                        halign: 'center'
                    }, // Type
                    3: {
                        halign: 'center'
                    }, // Students
                    4: {
                        halign: 'right'
                    }, // Expected Amount
                    5: {
                        halign: 'right'
                    }, // Amount Collected
                    6: {
                        halign: 'center'
                    }, // Collection %
                    7: {
                        halign: 'right'
                    }, // Fees Deficit
                    8: {
                        halign: 'center'
                    } // Deficit %
                },
                didParseCell: function(data) {
                    // Style totals row
                    if (data.row.index === tableData.length - 1) {
                        data.cell.styles.fillColor = [231, 243, 255];
                        data.cell.styles.fontStyle = 'bold';
                    }
                }
            });

            // Save the PDF
            doc.save(`fees-collection-report-${currentReportData.term}-${currentReportData.year}.pdf`);
        }

        // Export to Excel
        function exportToExcel() {
            if (!currentReportData) {
                alert('Please generate a report first');
                return;
            }

            // Prepare workbook data
            const ws_data = [
                ['Fees Collection Report'],
                [`${currentReportData.term} ${currentReportData.year}`],
                [`Generated on: ${new Date().toLocaleString()}`],
                [], // Empty row
                ['Summary:'],
                ['Total Expected Amount:', `UGX ${currentReportData.totals.expected_amount.toLocaleString()}`],
                ['Total Amount Collected:', `UGX ${currentReportData.totals.amount_collected.toLocaleString()}`],
                ['Total Deficit:', `UGX ${currentReportData.totals.fees_deficit.toLocaleString()}`],
                ['Collection Rate:', `${currentReportData.totals.collection_percentage}%`],
                [], // Empty row
                ['Detailed Report:'],
                ['Class', 'Stream', 'Type', 'Students', 'Fee Amount', 'Gross Expected', 'Bursary Discount', 'Net Expected', 'Amount Collected', 'Collection %', 'Fees Deficit', 'Deficit %']
            ];

            // Add report data
            currentReportData.report_data.forEach(row => {
                ws_data.push([
                    row.class_name,
                    row.stream,
                    row.student_type,
                    row.student_count,
                    row.fee_amount,
                    row.gross_expected,
                    row.total_bursary,
                    row.expected_amount,
                    row.amount_collected,
                    row.collection_percentage + '%',
                    row.fees_deficit,
                    row.deficit_percentage + '%'
                ]);
            });

            // Add totals row
            ws_data.push([
                'TOTALS',
                '',
                '',
                currentReportData.totals.student_count,
                '',
                '',
                '',
                currentReportData.totals.expected_amount,
                currentReportData.totals.amount_collected,
                currentReportData.totals.collection_percentage + '%',
                currentReportData.totals.fees_deficit,
                currentReportData.totals.deficit_percentage + '%'
            ]);

            // Create workbook and worksheet
            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.aoa_to_sheet(ws_data);

            // Set column widths
            const colWidths = [{
                    wch: 15
                }, // Class
                {
                    wch: 10
                }, // Stream
                {
                    wch: 10
                }, // Type
                {
                    wch: 10
                }, // Students
                {
                    wch: 12
                }, // Fee Amount
                {
                    wch: 15
                }, // Gross Expected
                {
                    wch: 15
                }, // Bursary Discount
                {
                    wch: 15
                }, // Net Expected
                {
                    wch: 15
                }, // Amount Collected
                {
                    wch: 12
                }, // Collection %
                {
                    wch: 15
                }, // Fees Deficit
                {
                    wch: 12
                } // Deficit %
            ];
            ws['!cols'] = colWidths;

            // Style the header rows
            const range = XLSX.utils.decode_range(ws['!ref']);
            for (let R = 0; R <= 11; R++) {
                for (let C = 0; C <= range.e.c; C++) {
                    const cell_address = XLSX.utils.encode_cell({
                        r: R,
                        c: C
                    });
                    if (!ws[cell_address]) continue;

                    if (R === 0 || R === 10 || R === 11) { // Title, "Detailed Report:", and header row
                        ws[cell_address].s = {
                            font: {
                                bold: true
                            },
                            fill: {
                                fgColor: {
                                    rgb: "E7F3FF"
                                }
                            }
                        };
                    }
                }
            }

            // Add worksheet to workbook
            XLSX.utils.book_append_sheet(wb, ws, "Fees Collection Report");

            // Save the file
            XLSX.writeFile(wb, `fees-collection-report-${currentReportData.term}-${currentReportData.year}.xlsx`);
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Set current year as default if not already selected
            const yearFilter = document.getElementById('yearFilter');
            if (!yearFilter.value) {
                const currentYear = new Date().getFullYear();
                if (yearFilter.querySelector(`option[value="${currentYear}"]`)) {
                    yearFilter.value = currentYear;
                }
            }
        });
    </script>
</body>

</html>