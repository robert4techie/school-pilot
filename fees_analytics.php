<?php
require_once 'conn.php';
require_once 'auth.php';
require_once 'tracking.php';
$tracker->trackAction("Fees analytics");

// Role-based access control (same as fees collection report)
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

        $allowed_roles = ['bursar', 'super user', 'head teacher', 'director', 'developer'];

        if (!in_array($user_role, $allowed_roles)) {
            $_SESSION['previous_page'] = $_SERVER['REQUEST_URI'];
            $_SESSION['access_denied_message'] = "Access Denied: You don't have permission to access the fees analytics.";
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
        case 'get_analytics_data':
            getAnalyticsData($conn);
            break;
        case 'get_trend_data':
            getTrendData($conn);
            break;
    }
    exit;
}

function getAnalyticsData($conn)
{
    $term = mysqli_real_escape_string($conn, $_POST['term']);
    $year = intval($_POST['year']);
    $class_filter = $_POST['class_filter'] ?? '';
    $stream_filter = $_POST['stream_filter'] ?? '';

    if (empty($term) || empty($year)) {
        echo json_encode(['error' => 'Term and year are required']);
        return;
    }

    // Build where clause for filters
    $where_clause = "WHERE s.status = 'active'";
    if (!empty($class_filter)) {
        $where_clause .= " AND s.current_class = '" . mysqli_real_escape_string($conn, $class_filter) . "'";
    }
    if (!empty($stream_filter)) {
        $where_clause .= " AND s.stream = '" . mysqli_real_escape_string($conn, $stream_filter) . "'";
    }

    // Get all class, stream, and section (Day/Boarding) combinations with students
    $query = "
        SELECT DISTINCT 
            s.current_class as class_name,
            s.stream,
            s.section as student_type,
            COUNT(s.student_id) as student_count
        FROM students s 
        $where_clause
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
    $analytics_data = [];
    $class_summaries = [];
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

        $stream_data = [
            'class_name' => $class_name,
            'stream' => $stream,
            'student_type' => $student_type,
            'student_count' => $student_count,
            'expected_amount' => $expected_amount,
            'amount_collected' => $amount_collected,
            'fees_deficit' => $fees_deficit,
            'collection_percentage' => round($collection_percentage, 2)
        ];

        $analytics_data[] = $stream_data;

        // Aggregate by class for class summaries
        if (!isset($class_summaries[$class_name])) {
            $class_summaries[$class_name] = [
                'class_name' => $class_name,
                'student_count' => 0,
                'expected_amount' => 0,
                'amount_collected' => 0,
                'fees_deficit' => 0
            ];
        }

        $class_summaries[$class_name]['student_count'] += $student_count;
        $class_summaries[$class_name]['expected_amount'] += $expected_amount;
        $class_summaries[$class_name]['amount_collected'] += $amount_collected;
        $class_summaries[$class_name]['fees_deficit'] += $fees_deficit;

        // Add to totals
        $totals['expected_amount'] += $expected_amount;
        $totals['amount_collected'] += $amount_collected;
        $totals['fees_deficit'] += $fees_deficit;
        $totals['student_count'] += $student_count;
    }

    // Calculate collection percentages for class summaries
    foreach ($class_summaries as &$class) {
        $class['collection_percentage'] = $class['expected_amount'] > 0 ?
            round(($class['amount_collected'] / $class['expected_amount']) * 100, 2) : 0;
    }

    // Calculate total percentages
    $totals['collection_percentage'] = $totals['expected_amount'] > 0 ?
        round(($totals['amount_collected'] / $totals['expected_amount']) * 100, 2) : 0;

    header('Content-Type: application/json');
    echo json_encode([
        'stream_data' => $analytics_data,
        'class_data' => array_values($class_summaries),
        'totals' => $totals,
        'term' => $term,
        'year' => $year
    ]);
}



// Get trend data for multiple terms
function getTrendData($conn)
{
    $year = intval($_POST['year']);
    $class_name = $_POST['class_name'] ?? '';

    if (empty($year)) {
        echo json_encode(['error' => 'Year is required']);
        return;
    }

    $terms = ['Term One', 'Term Two', 'Term Three'];
    $trend_data = [];

    foreach ($terms as $term) {
        $where_clause = "WHERE s.status = 'active'";
        if (!empty($class_name)) {
            $where_clause .= " AND s.current_class = '" . mysqli_real_escape_string($conn, $class_name) . "'";
        }

        // Get collection data for this term
        $query = "
            SELECT 
                COALESCE(SUM(CASE 
                    WHEN fs.amount IS NOT NULL THEN 
                        (COUNT(s.student_id) * fs.amount) - COALESCE(SUM(fb.bursary_discount), 0)
                    ELSE 0 
                END), 0) as expected_amount,
                COALESCE(SUM(fp.amount_paid), 0) as amount_collected
            FROM students s
            LEFT JOIN fee_structures fs ON s.current_class = fs.class_name AND fs.term = '$term' AND fs.year = $year
            LEFT JOIN fees_bursaries fb ON s.student_id = fb.student_id AND fb.term = '$term' AND fb.academic_year = $year
            LEFT JOIN fees_payments fp ON s.student_id = fp.student_id AND fp.term = '$term' AND fp.year = $year
            $where_clause
        ";

        $result = mysqli_query($conn, $query);
        if ($row = mysqli_fetch_assoc($result)) {
            $expected = (int)$row['expected_amount'];
            $collected = (int)$row['amount_collected'];
            $collection_rate = $expected > 0 ? round(($collected / $expected) * 100, 2) : 0;

            $trend_data[] = [
                'term' => $term,
                'expected_amount' => $expected,
                'amount_collected' => $collected,
                'collection_percentage' => $collection_rate
            ];
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['trend_data' => $trend_data]);
}

// Get available classes and streams for filters
function getFilterOptions($conn)
{
    // Get classes
    $classes_query = "SELECT DISTINCT current_class FROM students WHERE status = 'active' ORDER BY 
        CASE current_class
            WHEN 'Senior One' THEN 1
            WHEN 'Senior Two' THEN 2
            WHEN 'Senior Three' THEN 3
            WHEN 'Senior Four' THEN 4
            WHEN 'Senior Five' THEN 5
            WHEN 'Senior Six' THEN 6
            ELSE 7
        END";
    $classes_result = mysqli_query($conn, $classes_query);
    $classes = [];
    while ($row = mysqli_fetch_assoc($classes_result)) {
        $classes[] = $row['current_class'];
    }

    // Get streams
    $streams_query = "SELECT DISTINCT stream FROM students WHERE status = 'active' ORDER BY stream";
    $streams_result = mysqli_query($conn, $streams_query);
    $streams = [];
    while ($row = mysqli_fetch_assoc($streams_result)) {
        $streams[] = $row['stream'];
    }

    return ['classes' => $classes, 'streams' => $streams];
}

$filter_options = getFilterOptions($conn);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fees Analytics Dashboard</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
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
            --chart-primary: #4f46e5;
            --chart-secondary: #06b6d4;
            --chart-success: #10b981;
            --chart-warning: #f59e0b;
            --chart-danger: #ef4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: #333;
            line-height: 1.6;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            margin-top: 45px;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            color: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
            box-shadow: var(--shadow-lg);
        }

        .header h2 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .header p {
            font-size: 1rem;
            opacity: 0.9;
        }

        .controls-section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .filter-select {
            padding: 12px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--chart-primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .control-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 20px;
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

        .btnn-secondary {
            background: linear-gradient(135deg, var(--chart-secondary), #0891b2);
            color: white;
        }

        .btnn-success {
            background: linear-gradient(135deg, var(--chart-success), #059669);
            color: white;
        }

        .btnn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .analytics-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow);
            position: relative;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 15px;
        }

        .chart-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chart-toggle {
            display: flex;
            gap: 10px;
        }

        .toggle-btnn {
            padding: 6px 12px;
            border: 2px solid #e2e8f0;
            background: white;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .toggle-btnn.active {
            background: var(--chart-primary);
            color: white;
            border-color: var(--chart-primary);
        }

        .student-type-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 11px;
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

        .canvas-wrapper {
            position: relative;
            height: 400px;
        }

        .pie-chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .pie-chart-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .pie-chart-container h3 {
            margin-bottom: 15px;
            color: #1e293b;
            font-size: 1.1rem;
        }

        .pie-canvas-wrapper {
            position: relative;
            height: 250px;
        }

        .trend-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
        }

        .trend-controls {
            display: flex;
            gap: 15px;
            align-items: center;
            margin-bottom: 20px;
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: linear-gradient(135deg, white, #f8fafc);
            padding: 25px;
            border-radius: 15px;
            box-shadow: var(--shadow);
            text-align: center;
            border-left: 5px solid var(--chart-primary);
        }

        .summary-card h3 {
            font-size: 0.9rem;
            color: #64748b;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-card .value {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .summary-card .subtitle {
            font-size: 0.8rem;
            color: #94a3b8;
        }

        .card-expected {
            border-left-color: var(--chart-primary);
        }

        .card-expected .value {
            color: var(--chart-primary);
        }

        .card-collected {
            border-left-color: var(--chart-success);
        }

        .card-collected .value {
            color: var(--chart-success);
        }

        .card-deficit {
            border-left-color: var(--chart-danger);
        }

        .card-deficit .value {
            color: var(--chart-danger);
        }

        .card-rate {
            border-left-color: var(--chart-secondary);
        }

        .card-rate .value {
            color: var(--chart-secondary);
        }

        .loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 200px;
            color: #64748b;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--chart-primary);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin-bottom: 15px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #64748b;
            font-style: italic;
        }

        @media (max-width: 1200px) {
            .analytics-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            .header h2 {
                font-size: 2rem;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .control-buttons {
                flex-direction: column;
            }

            .chart-header {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
        }
    </style>
</head>

<body>
    <?php require_once 'nav.php'; ?>

    <div class="container">
        <!-- Header -->
        <div class="header">
            <h2><i class="fas fa-chart-line"></i> Fees Analytics Dashboard</h2>
            <p>Advanced data visualization and insights for school fees collection</p>
        </div>

        <!-- Controls Section -->
        <div class="controls-section">
            <div class="filters-grid">
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
                    <label class="filter-label">Class (Optional)</label>
                    <select class="filter-select" id="classFilter">
                        <option value="">All Classes</option>
                        <?php foreach ($filter_options['classes'] as $class): ?>
                            <option value="<?php echo htmlspecialchars($class); ?>"><?php echo htmlspecialchars($class); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Stream (Optional)</label>
                    <select class="filter-select" id="streamFilter">
                        <option value="">All Streams</option>
                        <?php foreach ($filter_options['streams'] as $stream): ?>
                            <option value="<?php echo htmlspecialchars($stream); ?>"><?php echo htmlspecialchars($stream); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="control-buttons">
                <button class="btnn btnn-primary" onclick="generateAnalytics()">
                    <i class="fas fa-chart-bar"></i>
                    Generate Analytics
                </button>
                <!--<button class="btnn btnn-secondary" onclick="loadTrendAnalysis()">
                    <i class="fas fa-trending-up"></i>
                    Trend Analysis
                </button>-->
                <button class="btnn btnn-success" onclick="exportDashboard()" id="exportbtnn" style="display: none;">
                    <i class="fas fa-download"></i>
                    Export Dashboard
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
            <div class="summary-card card-rate">
                <h3>Collection Rate</h3>
                <div class="value" id="collectionRate">0%</div>
                <div class="subtitle">Overall performance</div>
            </div>
        </div>

        <!-- Main Analytics Grid -->
        <div class="analytics-grid" id="analyticsGrid" style="display: none;">
            <!-- Bar/Line Chart -->
            <div class="chart-container">
                <div class="chart-header">
                    <h2 class="chart-title">
                        <i class="fas fa-chart-bar"></i>
                        Collection Analysis by Class
                    </h2>
                    <div class="chart-toggle">
                        <button class="toggle-btnn active" onclick="toggleChart('bar')" data-type="bar">Bar</button>
                        <button class="toggle-btnn" onclick="toggleChart('line')" data-type="line">Line</button>
                        <button class="toggle-btnn" onclick="toggleChart('mixed')" data-type="mixed">Mixed</button>
                    </div>
                </div>
                <div class="canvas-wrapper">
                    <canvas id="mainChart"></canvas>
                </div>
            </div>

            <!-- Stream Comparison Chart -->
            <div class="chart-container">
                <div class="chart-header">
                    <h2 class="chart-title">
                        <i class="fas fa-stream"></i>
                        Stream Performance Analysis
                    </h2>
                    <div class="chart-toggle">
                        <button class="toggle-btnn active" onclick="toggleStreamChart('bar')" data-type="bar">Bar</button>
                        <button class="toggle-btnn" onclick="toggleStreamChart('radar')" data-type="radar">Radar</button>
                    </div>
                </div>
                <div class="canvas-wrapper">
                    <canvas id="streamChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Pie Charts Section -->
        <div class="pie-chart-grid" id="pieChartGrid" style="display: none;">
            <!-- Overall Collection Pie Chart -->
            <div class="pie-chart-container">
                <h3><i class="fas fa-chart-pie"></i> Overall Collection Distribution</h3>
                <div class="pie-canvas-wrapper">
                    <canvas id="overallPieChart"></canvas>
                </div>
            </div>

            <!-- Individual Class Pie Charts will be dynamically generated -->
        </div>

        <!-- Trend Analysis Section -->
        <div class="trend-section" id="trendSection" style="display: none;">
            <div class="chart-header">
                <h2 class="chart-title">
                    <i class="fas fa-trending-up"></i>
                    Trend Analysis
                </h2>
                <div class="trend-controls">
                    <select class="filter-select" id="trendClassFilter">
                        <option value="">All Classes</option>
                        <?php foreach ($filter_options['classes'] as $class): ?>
                            <option value="<?php echo htmlspecialchars($class); ?>"><?php echo htmlspecialchars($class); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btnn btnn-primary" onclick="loadTrendData()">
                        <i class="fas fa-sync-alt"></i>
                        Refresh Trend
                    </button>
                </div>
            </div>
            <div class="canvas-wrapper">
                <canvas id="trendChart"></canvas>
            </div>
        </div>

        <!-- Data Tables Section -->
        <div class="chart-container" id="dataTablesSection" style="display: none;">
            <div class="chart-header">
                <h2 class="chart-title">
                    <i class="fas fa-table"></i>
                    Detailed Analytics Data
                </h2>
                <div class="chart-toggle">
                    <button class="toggle-btnn active" onclick="toggleTable('stream')" data-type="stream">By Stream</button>
                    <button class="toggle-btnn" onclick="toggleTable('class')" data-type="class">By Class</button>
                </div>
            </div>

            <!-- Stream Data Table -->
            <div id="streamTable" class="table-container">
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
                        <thead>
                            <tr style="background: linear-gradient(135deg, #f8fafc, #e2e8f0); color: #374151;">
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #d1d5db;">Class</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #d1d5db;">Stream</th>
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #d1d5db;">Type</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #d1d5db;">Students</th>
                                <th style="padding: 12px; text-align: right; border-bottom: 2px solid #d1d5db;">Expected (UGX)</th>
                                <th style="padding: 12px; text-align: right; border-bottom: 2px solid #d1d5db;">Collected (UGX)</th>
                                <th style="padding: 12px; text-align: right; border-bottom: 2px solid #d1d5db;">Deficit (UGX)</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #d1d5db;">Rate (%)</th>
                            </tr>
                        </thead>
                        <tbody id="streamTableBody">
                            <!-- Data will be populated by JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Class Summary Table -->
            <div id="classTable" class="table-container" style="display: none;">
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
                        <thead>
                            <tr style="background: linear-gradient(135deg, #f8fafc, #e2e8f0); color: #374151;">
                                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #d1d5db;">Class</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #d1d5db;">Total Students</th>
                                <th style="padding: 12px; text-align: right; border-bottom: 2px solid #d1d5db;">Expected (UGX)</th>
                                <th style="padding: 12px; text-align: right; border-bottom: 2px solid #d1d5db;">Collected (UGX)</th>
                                <th style="padding: 12px; text-align: right; border-bottom: 2px solid #d1d5db;">Deficit (UGX)</th>
                                <th style="padding: 12px; text-align: center; border-bottom: 2px solid #d1d5db;">Collection Rate (%)</th>
                            </tr>
                        </thead>
                        <tbody id="classTableBody">
                            <!-- Data will be populated by JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart.js Configuration and JavaScript -->
    <script>
        // Global variables
        let mainChart = null;
        let streamChart = null;
        let trendChart = null;
        let overallPieChart = null;
        let classPieCharts = {};
        let currentChartType = 'bar';
        let currentStreamChartType = 'bar';
        let analyticsData = null;

        // Utility functions
        function formatCurrency(amount) {
            return new Intl.NumberFormat('en-UG', {
                style: 'currency',
                currency: 'UGX',
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            }).format(amount);
        }

        function formatNumber(number) {
            return new Intl.NumberFormat('en-UG').format(number);
        }

        function showLoading(containerId) {
            const container = document.getElementById(containerId);
            if (container) {
                container.innerHTML = `
                    <div class="loading">
                        <div class="spinner"></div>
                        <p>Loading analytics data...</p>
                    </div>
                `;
            }
        }

        function showError(containerId, message) {
            const container = document.getElementById(containerId);
            if (container) {
                container.innerHTML = `
                    <div class="no-data">
                        <i class="fas fa-exclamation-triangle" style="font-size: 2rem; color: #ef4444; margin-bottom: 10px;"></i>
                        <p>${message}</p>
                    </div>
                `;
            }
        }

        // Main analytics generation function
        async function generateAnalytics() {
            const year = document.getElementById('yearFilter').value;
            const term = document.getElementById('termFilter').value;
            const classFilter = document.getElementById('classFilter').value;
            const streamFilter = document.getElementById('streamFilter').value;

            if (!year || !term) {
                alert('Please select both year and term to generate analytics.');
                return;
            }

            // Show loading state
            document.getElementById('summaryCards').style.display = 'none';
            document.getElementById('analyticsGrid').style.display = 'none';
            document.getElementById('pieChartGrid').style.display = 'none';
            document.getElementById('dataTablesSection').style.display = 'none';
            document.getElementById('exportbtnn').style.display = 'none';

            try {
                const formData = new FormData();
                formData.append('action', 'get_analytics_data');
                formData.append('year', year);
                formData.append('term', term);
                formData.append('class_filter', classFilter);
                formData.append('stream_filter', streamFilter);

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                analyticsData = data;

                // Update summary cards
                updateSummaryCards(data.totals);

                // Generate all charts and tables
                generateMainChart(data.class_data);
                generateStreamChart(data.stream_data);
                generatePieCharts(data);
                generateDataTables(data);

                // Show all sections
                document.getElementById('summaryCards').style.display = 'grid';
                document.getElementById('analyticsGrid').style.display = 'grid';
                document.getElementById('pieChartGrid').style.display = 'grid';
                document.getElementById('dataTablesSection').style.display = 'block';
                document.getElementById('exportbtnn').style.display = 'inline-flex';

            } catch (error) {
                console.error('Error generating analytics:', error);
                alert('Error loading analytics data: ' + error.message);
            }
        }

        // Update summary cards
        function updateSummaryCards(totals) {
            document.getElementById('totalExpected').textContent = formatCurrency(totals.expected_amount);
            document.getElementById('totalCollected').textContent = formatCurrency(totals.amount_collected);
            document.getElementById('totalDeficit').textContent = formatCurrency(totals.fees_deficit);
            document.getElementById('collectionRate').textContent = totals.collection_percentage + '%';
        }

        // Generate main chart (by class)
        function generateMainChart(classData) {
            const ctx = document.getElementById('mainChart').getContext('2d');

            if (mainChart) {
                mainChart.destroy();
            }

            const labels = classData.map(item => item.class_name);
            const expectedData = classData.map(item => item.expected_amount);
            const collectedData = classData.map(item => item.amount_collected);
            const deficitData = classData.map(item => item.fees_deficit);

            const config = {
                type: currentChartType,
                data: {
                    labels: labels,
                    datasets: [{
                            label: 'Expected Amount',
                            data: expectedData,
                            backgroundColor: 'rgba(79, 70, 229, 0.8)',
                            borderColor: 'rgba(79, 70, 229, 1)',
                            borderWidth: 2
                        },
                        {
                            label: 'Collected Amount',
                            data: collectedData,
                            backgroundColor: 'rgba(16, 185, 129, 0.8)',
                            borderColor: 'rgba(16, 185, 129, 1)',
                            borderWidth: 2
                        },
                        {
                            label: 'Deficit Amount',
                            data: deficitData,
                            backgroundColor: 'rgba(239, 68, 68, 0.8)',
                            borderColor: 'rgba(239, 68, 68, 1)',
                            borderWidth: 2
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 20
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + formatCurrency(context.parsed.y);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return formatCurrency(value);
                                }
                            }
                        }
                    }
                }
            };

            mainChart = new Chart(ctx, config);
        }

        // Generate stream chart
        function generateStreamChart(streamData) {
            const ctx = document.getElementById('streamChart').getContext('2d');

            if (streamChart) {
                streamChart.destroy();
            }

            if (currentStreamChartType === 'radar') {
                generateRadarChart(ctx, streamData);
            } else {
                generateStreamBarChart(ctx, streamData);
            }
        }

        function generateStreamBarChart(ctx, streamData) {
            const labels = streamData.map(item => `${item.class_name} ${item.stream} (${item.student_type})`);
            const collectionPercentages = streamData.map(item => item.collection_percentage);

            const config = {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Collection Rate (%)',
                        data: collectionPercentages,
                        backgroundColor: collectionPercentages.map(percentage => {
                            if (percentage >= 80) return 'rgba(16, 185, 129, 0.8)';
                            if (percentage >= 60) return 'rgba(245, 158, 11, 0.8)';
                            return 'rgba(239, 68, 68, 0.8)';
                        }),
                        borderColor: collectionPercentages.map(percentage => {
                            if (percentage >= 80) return 'rgba(16, 185, 129, 1)';
                            if (percentage >= 60) return 'rgba(245, 158, 11, 1)';
                            return 'rgba(239, 68, 68, 1)';
                        }),
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Collection Rate: ' + context.parsed.y.toFixed(2) + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45
                            }
                        }
                    }
                }
            };

            streamChart = new Chart(ctx, config);
        }

        function generateRadarChart(ctx, streamData) {
            // Group data by class for radar chart
            const classGroups = {};
            streamData.forEach(item => {
                if (!classGroups[item.class_name]) {
                    classGroups[item.class_name] = [];
                }
                classGroups[item.class_name].push(item);
            });

            const labels = [];
            const datasets = [];
            const colors = [
                'rgba(79, 70, 229, 0.6)',
                'rgba(16, 185, 129, 0.6)',
                'rgba(239, 68, 68, 0.6)',
                'rgba(245, 158, 11, 0.6)',
                'rgba(6, 182, 212, 0.6)',
                'rgba(139, 92, 246, 0.6)'
            ];

            let colorIndex = 0;

            Object.keys(classGroups).forEach(className => {
                const classStreams = classGroups[className];
                const data = [];

                classStreams.forEach(stream => {
                    if (!labels.includes(stream.stream)) {
                        labels.push(stream.stream);
                    }
                });

                // Ensure data is in correct order for labels
                labels.forEach(label => {
                    const streamData = classStreams.find(s => s.stream === label);
                    data.push(streamData ? streamData.collection_percentage : 0);
                });

                datasets.push({
                    label: className,
                    data: data,
                    backgroundColor: colors[colorIndex % colors.length],
                    borderColor: colors[colorIndex % colors.length].replace('0.6', '1'),
                    borderWidth: 2,
                    pointBackgroundColor: colors[colorIndex % colors.length].replace('0.6', '1')
                });

                colorIndex++;
            });

            const config = {
                type: 'radar',
                data: {
                    labels: labels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        r: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'top'
                        }
                    }
                }
            };

            streamChart = new Chart(ctx, config);
        }

        // Generate pie charts
        function generatePieCharts(data) {
            // Overall pie chart
            generateOverallPieChart(data.totals);

            // Individual class pie charts
            generateClassPieCharts(data.class_data);
        }

        function generateOverallPieChart(totals) {
            const ctx = document.getElementById('overallPieChart').getContext('2d');

            if (overallPieChart) {
                overallPieChart.destroy();
            }

            const config = {
                type: 'pie',
                data: {
                    labels: ['Collected', 'Outstanding'],
                    datasets: [{
                        data: [totals.amount_collected, totals.fees_deficit],
                        backgroundColor: [
                            'rgba(16, 185, 129, 0.8)',
                            'rgba(239, 68, 68, 0.8)'
                        ],
                        borderColor: [
                            'rgba(16, 185, 129, 1)',
                            'rgba(239, 68, 68, 1)'
                        ],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label;
                                    const value = formatCurrency(context.parsed);
                                    const percentage = ((context.parsed / (totals.amount_collected + totals.fees_deficit)) * 100).toFixed(1);
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            };

            overallPieChart = new Chart(ctx, config);
        }

        function generateClassPieCharts(classData) {
            const pieChartGrid = document.getElementById('pieChartGrid');

            // Clear existing class pie charts
            Object.values(classPieCharts).forEach(chart => chart.destroy());
            classPieCharts = {};

            // Remove existing class pie chart containers
            const existingClassCharts = pieChartGrid.querySelectorAll('.class-pie-chart');
            existingClassCharts.forEach(chart => chart.remove());

            // Generate new pie charts for each class
            classData.forEach((classItem, index) => {
                if (classItem.expected_amount > 0) {
                    const chartContainer = document.createElement('div');
                    chartContainer.className = 'pie-chart-container class-pie-chart';
                    chartContainer.innerHTML = `
                        <h3><i class="fas fa-graduation-cap"></i> ${classItem.class_name}</h3>
                        <div class="pie-canvas-wrapper">
                            <canvas id="classPieChart_${index}"></canvas>
                        </div>
                    `;
                    pieChartGrid.appendChild(chartContainer);

                    const ctx = document.getElementById(`classPieChart_${index}`).getContext('2d');

                    const config = {
                        type: 'doughnut',
                        data: {
                            labels: ['Collected', 'Outstanding'],
                            datasets: [{
                                data: [classItem.amount_collected, classItem.fees_deficit],
                                backgroundColor: [
                                    'rgba(16, 185, 129, 0.8)',
                                    'rgba(239, 68, 68, 0.8)'
                                ],
                                borderColor: [
                                    'rgba(16, 185, 129, 1)',
                                    'rgba(239, 68, 68, 1)'
                                ],
                                borderWidth: 2
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        font: {
                                            size: 10
                                        }
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label;
                                            const value = formatCurrency(context.parsed);
                                            const percentage = ((context.parsed / classItem.expected_amount) * 100).toFixed(1);
                                            return `${label}: ${value} (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    };

                    classPieCharts[`class_${index}`] = new Chart(ctx, config);
                }
            });
        }

        // Generate data tables
        function generateDataTables(data) {
            generateStreamTable(data.stream_data);
            generateClassTable(data.class_data);
        }

        function generateStreamTable(streamData) {
            const tbody = document.getElementById('streamTableBody');
            tbody.innerHTML = '';

            streamData.forEach(item => {
                const badgeClass = item.student_type === 'Day' ? 'badge-day' : 'badge-boarding';
                const row = document.createElement('tr');
                row.style.borderBottom = '1px solid #e5e7eb';
                row.innerHTML = `
            <td style="padding: 12px; font-weight: 600;">${item.class_name}</td>
            <td style="padding: 12px;">${item.stream}</td>
            <td style="padding: 12px;">
                <span class="student-type-badge ${badgeClass}">
                    ${item.student_type}
                </span>
            </td>
            <td style="padding: 12px; text-align: center;">${formatNumber(item.student_count)}</td>
            <td style="padding: 12px; text-align: right;">${formatCurrency(item.expected_amount)}</td>
            <td style="padding: 12px; text-align: right; color: #059669;">${formatCurrency(item.amount_collected)}</td>
            <td style="padding: 12px; text-align: right; color: #dc2626;">${formatCurrency(item.fees_deficit)}</td>
            <td style="padding: 12px; text-align: center;">
                <span style="padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; 
                             background-color: ${getPercentageColor(item.collection_percentage)}; 
                             color: white;">
                    ${item.collection_percentage}%
                </span>
            </td>
        `;
                tbody.appendChild(row);
            });
        }

        function generateClassTable(classData) {
            const tbody = document.getElementById('classTableBody');
            tbody.innerHTML = '';

            classData.forEach(item => {
                const row = document.createElement('tr');
                row.style.borderBottom = '1px solid #e5e7eb';
                row.innerHTML = `
                    <td style="padding: 12px; font-weight: 600;">${item.class_name}</td>
                    <td style="padding: 12px; text-align: center;">${formatNumber(item.student_count)}</td>
                    <td style="padding: 12px; text-align: right;">${formatCurrency(item.expected_amount)}</td>
                    <td style="padding: 12px; text-align: right; color: #059669;">${formatCurrency(item.amount_collected)}</td>
                    <td style="padding: 12px; text-align: right; color: #dc2626;">${formatCurrency(item.fees_deficit)}</td>
                    <td style="padding: 12px; text-align: center;">
                        <span style="padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; 
                                     background-color: ${getPercentageColor(item.collection_percentage)}; 
                                     color: white;">
                            ${item.collection_percentage}%
                        </span>
                    </td>
                `;
                tbody.appendChild(row);
            });
        }

        function getPercentageColor(percentage) {
            if (percentage >= 80) return '#059669';
            if (percentage >= 60) return '#d97706';
            if (percentage >= 40) return '#dc2626';
            return '#7c2d12';
        }

        // Chart toggle functions
        function toggleChart(type) {
            currentChartType = type;

            // Update button states
            document.querySelectorAll('.chart-toggle .toggle-btnn').forEach(btnn => {
                btnn.classList.remove('active');
                if (btnn.dataset.type === type) {
                    btnn.classList.add('active');
                }
            });

            if (analyticsData) {
                generateMainChart(analyticsData.class_data);
            }
        }

        function toggleStreamChart(type) {
            currentStreamChartType = type;

            // Update button states
            document.querySelectorAll('.chart-toggle .toggle-btnn').forEach(btnn => {
                btnn.classList.remove('active');
                if (btnn.dataset.type === type) {
                    btnn.classList.add('active');
                }
            });

            if (analyticsData) {
                generateStreamChart(analyticsData.stream_data);
            }
        }

        function toggleTable(type) {
            // Update button states
            document.querySelectorAll('#dataTablesSection .toggle-btnn').forEach(btnn => {
                btnn.classList.remove('active');
                if (btnn.dataset.type === type) {
                    btnn.classList.add('active');
                }
            });

            // Show/hide tables
            if (type === 'stream') {
                document.getElementById('streamTable').style.display = 'block';
                document.getElementById('classTable').style.display = 'none';
            } else {
                document.getElementById('streamTable').style.display = 'none';
                document.getElementById('classTable').style.display = 'block';
            }
        }

        // Trend analysis functions
        async function loadTrendAnalysis() {
            const year = document.getElementById('yearFilter').value;

            if (!year) {
                alert('Please select a year for trend analysis.');
                return;
            }

            document.getElementById('trendSection').style.display = 'block';
            showLoading('trendSection');

            try {
                await loadTrendData();
            } catch (error) {
                console.error('Error loading trend analysis:', error);
                showError('trendSection', 'Error loading trend data: ' + error.message);
            }
        }

        async function loadTrendData() {
            const year = document.getElementById('yearFilter').value;
            const className = document.getElementById('trendClassFilter').value;

            if (!year) {
                alert('Please select a year for trend analysis.');
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'get_trend_data');
                formData.append('year', year);
                formData.append('class_name', className);

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                generateTrendChart(data.trend_data);

            } catch (error) {
                console.error('Error loading trend data:', error);
                showError('trendSection', 'Error loading trend data: ' + error.message);
            }
        }

        function generateTrendChart(trendData) {
            const ctx = document.getElementById('trendChart').getContext('2d');

            if (trendChart) {
                trendChart.destroy();
            }

            const labels = trendData.map(item => item.term);
            const expectedData = trendData.map(item => item.expected_amount);
            const collectedData = trendData.map(item => item.amount_collected);
            const collectionRates = trendData.map(item => item.collection_percentage);

            const config = {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                            label: 'Expected Amount',
                            data: expectedData,
                            backgroundColor: 'rgba(79, 70, 229, 0.1)',
                            borderColor: 'rgba(79, 70, 229, 1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Collected Amount',
                            data: collectedData,
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            borderColor: 'rgba(16, 185, 129, 1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Collection Rate (%)',
                            data: collectionRates,
                            backgroundColor: 'rgba(245, 158, 11, 0.1)',
                            borderColor: 'rgba(245, 158, 11, 1)',
                            borderWidth: 3,
                            fill: false,
                            tension: 0.4,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    if (context.dataset.label === 'Collection Rate (%)') {
                                        return context.dataset.label + ': ' + context.parsed.y.toFixed(2) + '%';
                                    }
                                    return context.dataset.label + ': ' + formatCurrency(context.parsed.y);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            display: true,
                            title: {
                                display: true,
                                text: 'Terms'
                            }
                        },
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Amount (UGX)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return formatCurrency(value);
                                }
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Collection Rate (%)'
                            },
                            grid: {
                                drawOnChartArea: false,
                            },
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            },
                            min: 0,
                            max: 100
                        }
                    }
                }
            };

            trendChart = new Chart(ctx, config);
        }

        // Export functionality
        async function exportDashboard() {
            if (!analyticsData) {
                alert('No data to export. Please generate analytics first.');
                return;
            }

            try {
                const {
                    jsPDF
                } = window.jspdf;
                const pdf = new jsPDF('p', 'mm', 'a4');

                // Set up document
                const pageWidth = pdf.internal.pageSize.getWidth();
                const pageHeight = pdf.internal.pageSize.getHeight();
                const margin = 15;
                let yPosition = margin;

                // Header
                pdf.setFontSize(20);
                pdf.setFont(undefined, 'bold');
                pdf.text('Fees Analytics Dashboard', pageWidth / 2, yPosition, {
                    align: 'center'
                });
                yPosition += 10;

                // Subtitle with filters
                pdf.setFontSize(12);
                pdf.setFont(undefined, 'normal');
                const subtitle = `Academic Year: ${analyticsData.year} | Term: ${analyticsData.term}`;
                pdf.text(subtitle, pageWidth / 2, yPosition, {
                    align: 'center'
                });
                yPosition += 15;

                // Summary Section
                pdf.setFontSize(16);
                pdf.setFont(undefined, 'bold');
                pdf.text('Summary Overview', margin, yPosition);
                yPosition += 8;

                pdf.setFontSize(10);
                pdf.setFont(undefined, 'normal');

                const summaryData = [
                    ['Metric', 'Value'],
                    ['Total Expected Amount', formatCurrency(analyticsData.totals.expected_amount)],
                    ['Total Collected Amount', formatCurrency(analyticsData.totals.amount_collected)],
                    ['Total Outstanding', formatCurrency(analyticsData.totals.fees_deficit)],
                    ['Overall Collection Rate', `${analyticsData.totals.collection_percentage}%`],
                    ['Total Students', formatNumber(analyticsData.totals.student_count)]
                ];

                // Create summary table
                let tableY = yPosition;
                const colWidth = (pageWidth - 2 * margin) / 2;

                summaryData.forEach((row, index) => {
                    if (index === 0) {
                        pdf.setFont(undefined, 'bold');
                        pdf.setFillColor(240, 240, 240);
                        pdf.rect(margin, tableY, colWidth * 2, 6, 'F');
                    } else {
                        pdf.setFont(undefined, 'normal');
                    }

                    pdf.text(row[0], margin + 2, tableY + 4);
                    pdf.text(row[1], margin + colWidth + 2, tableY + 4);
                    pdf.rect(margin, tableY, colWidth, 6);
                    pdf.rect(margin + colWidth, tableY, colWidth, 6);
                    tableY += 6;
                });

                yPosition = tableY + 10;

                // Class Performance Section
                if (yPosition > pageHeight - 50) {
                    pdf.addPage();
                    yPosition = margin;
                }

                pdf.setFontSize(16);
                pdf.setFont(undefined, 'bold');
                pdf.text('Class Performance Analysis', margin, yPosition);
                yPosition += 8;

                // Class data table
                pdf.setFontSize(8);
                const classHeaders = ['Class', 'Students', 'Expected (UGX)', 'Collected (UGX)', 'Outstanding (UGX)', 'Rate (%)'];
                const colWidths = [25, 20, 35, 35, 35, 20];

                // Headers
                pdf.setFont(undefined, 'bold');
                pdf.setFillColor(240, 240, 240);
                let xPos = margin;
                classHeaders.forEach((header, index) => {
                    pdf.rect(xPos, yPosition, colWidths[index], 6, 'F');
                    pdf.text(header, xPos + 1, yPosition + 4);
                    xPos += colWidths[index];
                });
                yPosition += 6;

                // Class data rows
                pdf.setFont(undefined, 'normal');
                analyticsData.class_data.forEach((classItem) => {
                    if (yPosition > pageHeight - 20) {
                        pdf.addPage();
                        yPosition = margin;
                    }

                    const rowData = [
                        classItem.class_name,
                        formatNumber(classItem.student_count),
                        formatCurrency(classItem.expected_amount),
                        formatCurrency(classItem.amount_collected),
                        formatCurrency(classItem.fees_deficit),
                        `${classItem.collection_percentage}%`
                    ];

                    xPos = margin;
                    rowData.forEach((data, index) => {
                        pdf.rect(xPos, yPosition, colWidths[index], 6);
                        pdf.text(data.toString(), xPos + 1, yPosition + 4);
                        xPos += colWidths[index];
                    });
                    yPosition += 6;
                });

                // Stream Performance Section
                if (yPosition > pageHeight - 50) {
                    pdf.addPage();
                    yPosition = margin;
                } else {
                    yPosition += 10;
                }

                pdf.setFontSize(16);
                pdf.setFont(undefined, 'bold');
                pdf.text('Stream Performance Details', margin, yPosition);
                yPosition += 8;

                // Stream data table
                pdf.setFontSize(7);
                const streamHeaders = ['Class', 'Stream', 'Type', 'Students', 'Expected', 'Collected', 'Outstanding', 'Rate'];
                const streamColWidths = [20, 16, 14, 16, 26, 26, 26, 16];

                // Headers
                pdf.setFont(undefined, 'bold');
                pdf.setFillColor(240, 240, 240);
                xPos = margin;
                streamHeaders.forEach((header, index) => {
                    pdf.rect(xPos, yPosition, streamColWidths[index], 6, 'F');
                    pdf.text(header, xPos + 1, yPosition + 4);
                    xPos += streamColWidths[index];
                });
                yPosition += 6;

                // Stream data rows
                pdf.setFont(undefined, 'normal');
                analyticsData.stream_data.forEach((streamItem) => {
                    if (yPosition > pageHeight - 15) {
                        pdf.addPage();
                        yPosition = margin;
                    }

                    const rowData = [
                        streamItem.class_name,
                        streamItem.stream,
                        streamItem.student_type,
                        formatNumber(streamItem.student_count),
                        formatCurrency(streamItem.expected_amount),
                        formatCurrency(streamItem.amount_collected),
                        formatCurrency(streamItem.fees_deficit),
                        `${streamItem.collection_percentage}%`
                    ];

                    xPos = margin;
                    rowData.forEach((data, index) => {
                        pdf.rect(xPos, yPosition, streamColWidths[index], 6);
                        // Truncate long text to fit
                        const maxWidth = streamColWidths[index] - 2;
                        const text = pdf.splitTextToSize(data.toString(), maxWidth);
                        pdf.text(text[0] || '', xPos + 1, yPosition + 4);
                        xPos += streamColWidths[index];
                    });
                    yPosition += 6;
                });

                // Footer
                const totalPages = pdf.internal.getNumberOfPages();
                for (let i = 1; i <= totalPages; i++) {
                    pdf.setPage(i);
                    pdf.setFontSize(8);
                    pdf.setFont(undefined, 'normal');
                    pdf.text(`Generated on: ${new Date().toLocaleString()}`, margin, pageHeight - 10);
                    pdf.text(`Page ${i} of ${totalPages}`, pageWidth - margin - 20, pageHeight - 10);
                }

                // Save the PDF
                const fileName = `fees_analytics_${analyticsData.year}_${analyticsData.term.replace(' ', '_')}_${new Date().toISOString().split('T')[0]}.pdf`;
                pdf.save(fileName);

            } catch (error) {
                console.error('Error exporting dashboard:', error);
                alert('Error exporting dashboard. Please try again.');
            }
        }

        // Utility function for responsive chart resizing
        function handleChartResize() {
            if (mainChart) mainChart.resize();
            if (streamChart) streamChart.resize();
            if (trendChart) trendChart.resize();
            if (overallPieChart) overallPieChart.resize();
            Object.values(classPieCharts).forEach(chart => chart.resize());
        }

        // Event listeners
        window.addEventListener('resize', debounce(handleChartResize, 250));

        // Debounce utility function
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Auto-refresh functionality
        let autoRefreshInterval;

        function startAutoRefresh(intervalMinutes = 5) {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
            }

            autoRefreshInterval = setInterval(() => {
                if (analyticsData && document.getElementById('yearFilter').value && document.getElementById('termFilter').value) {
                    console.log('Auto-refreshing analytics data...');
                    generateAnalytics();
                }
            }, intervalMinutes * 60 * 1000);
        }

        function stopAutoRefresh() {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
            }
        }

        // Enhanced error handling
        window.addEventListener('error', function(e) {
            console.error('Global error:', e.error);
            if (e.error.message.includes('Chart')) {
                alert('Chart rendering error. Please refresh the page and try again.');
            }
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Set current year as default if available
            const currentYear = new Date().getFullYear();
            const yearSelect = document.getElementById('yearFilter');
            if (yearSelect.querySelector(`option[value="${currentYear}"]`)) {
                yearSelect.value = currentYear;
            }

            // Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey || e.metaKey) {
                    switch (e.key) {
                        case 'g':
                            e.preventDefault();
                            generateAnalytics();
                            break;
                        case 'e':
                            e.preventDefault();
                            if (analyticsData) exportDashboard();
                            break;
                        case 't':
                            e.preventDefault();
                            loadTrendAnalysis();
                            break;
                    }
                }
            });

            // Add tooltips for keyboard shortcuts
            document.getElementById('yearFilter').parentElement.insertAdjacentHTML('afterend',
                '<div style="font-size: 11px; color: #6b7280; margin-top: 5px;">Shortcuts: Ctrl+G (Generate), Ctrl+E (Export), Ctrl+T (Trends)</div>'
            );

            console.log('Fees Analytics Dashboard initialized successfully');
        });

        // Performance monitoring
        const performanceMonitor = {
            startTime: null,

            start() {
                this.startTime = performance.now();
            },

            end(operation) {
                if (this.startTime) {
                    const duration = performance.now() - this.startTime;
                    console.log(`${operation} completed in ${duration.toFixed(2)}ms`);
                    this.startTime = null;
                }
            }
        };

        // Enhanced analytics generation with performance monitoring
        const originalGenerateAnalytics = generateAnalytics;
        generateAnalytics = async function() {
            performanceMonitor.start();
            try {
                await originalGenerateAnalytics();
                performanceMonitor.end('Analytics generation');
            } catch (error) {
                performanceMonitor.end('Analytics generation (with error)');
                throw error;
            }
        };

        // Memory cleanup on page unload
        window.addEventListener('beforeunload', function() {
            stopAutoRefresh();

            // Cleanup charts
            if (mainChart) mainChart.destroy();
            if (streamChart) streamChart.destroy();
            if (trendChart) trendChart.destroy();
            if (overallPieChart) overallPieChart.destroy();
            Object.values(classPieCharts).forEach(chart => chart.destroy());

            console.log('Dashboard cleanup completed');
        });

        // Data validation helpers
        function validateAnalyticsData(data) {
            const required = ['stream_data', 'class_data', 'totals', 'term', 'year'];
            for (const field of required) {
                if (!data.hasOwnProperty(field)) {
                    throw new Error(`Missing required field: ${field}`);
                }
            }

            if (!Array.isArray(data.stream_data) || !Array.isArray(data.class_data)) {
                throw new Error('Invalid data format: stream_data and class_data must be arrays');
            }

            return true;
        }

        // Enhanced data processing with validation
        const originalGetAnalyticsData = generateAnalytics;
        generateAnalytics = async function() {
            const year = document.getElementById('yearFilter').value;
            const term = document.getElementById('termFilter').value;
            const classFilter = document.getElementById('classFilter').value;
            const streamFilter = document.getElementById('streamFilter').value;

            if (!year || !term) {
                alert('Please select both year and term to generate analytics.');
                return;
            }

            // Show loading state
            document.getElementById('summaryCards').style.display = 'none';
            document.getElementById('analyticsGrid').style.display = 'none';
            document.getElementById('pieChartGrid').style.display = 'none';
            document.getElementById('dataTablesSection').style.display = 'none';
            document.getElementById('exportbtnn').style.display = 'none';

            performanceMonitor.start();

            try {
                const formData = new FormData();
                formData.append('action', 'get_analytics_data');
                formData.append('year', year);
                formData.append('term', term);
                formData.append('class_filter', classFilter);
                formData.append('stream_filter', streamFilter);

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                // Validate data structure
                validateAnalyticsData(data);

                analyticsData = data;

                // Update summary cards
                updateSummaryCards(data.totals);

                // Generate all charts and tables
                generateMainChart(data.class_data);
                generateStreamChart(data.stream_data);
                generatePieCharts(data);
                generateDataTables(data);

                // Show all sections
                document.getElementById('summaryCards').style.display = 'grid';
                document.getElementById('analyticsGrid').style.display = 'grid';
                document.getElementById('pieChartGrid').style.display = 'grid';
                document.getElementById('dataTablesSection').style.display = 'block';
                document.getElementById('exportbtnn').style.display = 'inline-flex';

                performanceMonitor.end('Analytics generation');

            } catch (error) {
                performanceMonitor.end('Analytics generation (with error)');
                console.error('Error generating analytics:', error);

                // Show user-friendly error message
                const errorMessage = error.message.includes('fetch') ?
                    'Network error. Please check your connection and try again.' :
                    `Error loading analytics data: ${error.message}`;

                alert(errorMessage);
            }
        };
    </script>