<?php

// Create error log
$error_messages = [];

// Check for required files
$required_files = ['auth.php', 'conn.php', 'tracking.php'];
foreach ($required_files as $file) {
    if (!file_exists($file)) {
        $error_messages[] = "Missing required file: $file";
    }
}

if (!empty($error_messages)) {
    die("Configuration Error:<br>" . implode("<br>", $error_messages) . "<br><br>Please ensure all required files exist.");
}

require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    die("Database Connection Error: " . ($conn->connect_error ?? "Connection object not found"));
}

// CSRF Protection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Role-based access control
$allowed_roles = ['developer', 'super user', 'school leader'];

if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), array_map('strtolower', $allowed_roles))) {
    if (isset($tracker)) {
        $tracker->trackAction("Unauthorized access attempt to Staff Attendance Analytics by " . ($_SESSION['username'] ?? 'Unknown'));
    }

    $_SESSION['error_message'] = "Access Denied: You don't have permission to access this page.";
    http_response_code(403);
    header("Location: dashboard.php");
    exit();
}

try {
    if (isset($tracker)) {
        $tracker->trackAction("Viewed Staff Attendance Analytics");
    }

    // Sanitize inputs with fallbacks
    $date_from = filter_input(INPUT_GET, 'date_from', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $date_to = filter_input(INPUT_GET, 'date_to', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    // Set defaults if empty
    if (empty($date_from)) {
        $date_from = date('Y-m-01');
    }
    if (empty($date_to)) {
        $date_to = date('Y-m-t');
    }

    $selected_department = filter_input(INPUT_GET, 'department', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '';
    $selected_position = filter_input(INPUT_GET, 'position', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '';

    // Validate dates - More lenient
    $date_from_obj = DateTime::createFromFormat('Y-m-d', $date_from);
    $date_to_obj = DateTime::createFromFormat('Y-m-d', $date_to);

    if (!$date_from_obj || $date_from_obj->format('Y-m-d') !== $date_from) {
        error_log("Invalid date_from: $date_from");
        $date_from = date('Y-m-01');
        $date_from_obj = DateTime::createFromFormat('Y-m-d', $date_from);
    }

    if (!$date_to_obj || $date_to_obj->format('Y-m-d') !== $date_to) {
        error_log("Invalid date_to: $date_to");
        $date_to = date('Y-m-t');
        $date_to_obj = DateTime::createFromFormat('Y-m-d', $date_to);
    }

    if ($date_from_obj > $date_to_obj) {
        // Swap dates instead of throwing error
        $temp = $date_from;
        $date_from = $date_to;
        $date_to = $temp;
    }

    // Fetch departments with error handling - FIXED
    $departments = null;
    try {
        $departments_query = "SELECT DISTINCT department FROM staff WHERE Status = 'active' AND department IS NOT NULL AND department != '' ORDER BY department";
        $departments = $conn->query($departments_query);

        if (!$departments) {
            error_log("Department query failed: " . $conn->error);
            throw new Exception("Failed to fetch departments: " . $conn->error);
        }
    } catch (Exception $e) {
        error_log("Department fetch error: " . $e->getMessage());
        // Create empty result set
        $departments = new class {
            public function fetch_assoc()
            {
                return null;
            }
        };
    }

    // Fetch positions with error handling - FIXED: Changed to 'designation'
    $positions = null;
    try {
        $positions_query = "SELECT DISTINCT designation FROM staff WHERE Status = 'active' AND designation IS NOT NULL AND designation != '' ORDER BY designation";
        $positions = $conn->query($positions_query);

        if (!$positions) {
            error_log("Position query failed: " . $conn->error);
            throw new Exception("Failed to fetch positions: " . $conn->error);
        }
    } catch (Exception $e) {
        error_log("Position fetch error: " . $e->getMessage());
        // Create empty result set
        $positions = new class {
            public function fetch_assoc()
            {
                return null;
            }
        };
    }
} catch (Exception $e) {
    error_log("Staff Analytics Critical Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
}

// If we got here, basic setup worked - continue with HTML
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Attendance Analytics</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        :root {
            /* Primary Green Color Palette */
            --primary-color: #2e7d32;
            --primary-dark: #145a32;
            --primary-light: #66bb6a;
            --primary-lighter: #a5d6a7;
            --primary-gradient: linear-gradient(135deg, #2e7d32 0%, #4caf50 100%);

            /* Status Colors */
            --present-color: #4caf50;
            --absent-color: #f44336;
            --late-color: #ff9800;
            --on-leave-color: #9c27b0;
            --at-risk-color: #d32f2f;
            --warning-color: #ffa726;
            --success-color: #66bb6a;

            /* Neutral Colors */
            --light-bg: #f1f8f4;
            --white: #ffffff;
            --border-color: #e0e0e0;
            --text-dark: #1b5e20;
            --text-medium: #388e3c;
            --text-light: #666;

            /* Shadows & Effects */
            --shadow-sm: 0 2px 4px rgba(46, 125, 50, 0.08);
            --shadow: 0 4px 6px rgba(46, 125, 50, 0.12);
            --shadow-md: 0 6px 12px rgba(46, 125, 50, 0.15);
            --shadow-lg: 0 10px 30px rgba(46, 125, 50, 0.18);
            --shadow-xl: 0 20px 40px rgba(46, 125, 50, 0.2);

            /* Border Radius */
            --radius-sm: 6px;
            --radius: 10px;
            --radius-lg: 15px;
            --radius-xl: 20px;

            /* Transitions */
            --transition-fast: 0.15s ease;
            --transition: 0.3s ease;
            --transition-slow: 0.5s ease;
        }

        /* === GLOBAL RESET === */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* === BODY === */
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
            background: var(--light-bg);
            color: #333;
            padding: 10px;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* === LOADER OVERLAY === */
        .loader-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(241, 248, 244, 0.98);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            transition: opacity var(--transition);
            backdrop-filter: blur(4px);
        }

        .loader-overlay.hidden {
            opacity: 0;
            pointer-events: none;
        }

        .loader {
            width: 70px;
            height: 70px;
            border: 6px solid var(--primary-lighter);
            border-top: 6px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s cubic-bezier(0.68, -0.55, 0.265, 1.55) infinite;
            box-shadow: 0 4px 12px rgba(46, 125, 50, 0.2);
        }

        .loader-text {
            margin-top: 24px;
            font-size: 17px;
            color: var(--primary-dark);
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* === CONTAINER === */
        .container {
            max-width: 100%;
            margin: 20px auto;
            margin-top: 70px;
            animation: fadeInUp 0.6s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* === HEADER === */
        .header {
            background: var(--primary-gradient);
            color: white;
            padding: 32px 28px;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            box-shadow: var(--shadow-lg);
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
            background: linear-gradient(135deg, transparent 0%, rgba(255, 255, 255, 0.1) 100%);
            pointer-events: none;
        }

        .header h1 {
            font-size: 2rem;
            margin-bottom: 8px;
            font-weight: 700;
            position: relative;
            z-index: 1;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .header p {
            opacity: 0.95;
            font-size: 1rem;
            position: relative;
            z-index: 1;
            font-weight: 400;
        }

        /* === FILTER SECTION === */
        .filter-section {
            background: white;
            padding: 28px;
            border-radius: 0 0 var(--radius-lg) var(--radius-lg);
            box-shadow: var(--shadow-lg);
            margin-bottom: 28px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 22px;
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 13px 16px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 0.95rem;
            transition: all var(--transition);
            background: white;
            color: #333;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(46, 125, 50, 0.1);
            background: #fafffe;
        }

        .form-group input:hover,
        .form-group select:hover {
            border-color: var(--primary-light);
        }

        /* === FILTER ACTIONS === */
        .filter-actions {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .btnn {
            font-family: "Sen", sans-serif !important;
            padding: 13px 28px;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: var(--shadow-sm);
            position: relative;
            overflow: hidden;
        }

        .btnn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .btnn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btnn-primary {
            background: var(--primary-gradient);
            color: white;
        }

        .btnn-primary:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .btnn-primary:active {
            transform: translateY(-1px);
        }

        .btnn-secondary {
            background: #607d8b;
            color: white;
        }

        .btnn-secondary:hover {
            background: #546e7a;
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .btnn-export {
            background: linear-gradient(135deg, #1976d2 0%, #2196f3 100%);
            color: white;
        }

        .btnn-export:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        /* === HIDDEN === */
        .hidden {
            display: none !important;
        }

        /* === TABS === */
        .tabs {
            font-family: "Sen", sans-serif !important;
            display: flex;
            gap: 6px;
            margin-bottom: 28px;
            border-bottom: 3px solid var(--border-color);
            overflow-x: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--primary-light) var(--light-bg);
        }

        .tabs::-webkit-scrollbar {
            height: 6px;
        }

        .tabs::-webkit-scrollbar-track {
            background: var(--light-bg);
            border-radius: 10px;
        }

        .tabs::-webkit-scrollbar-thumb {
            background: var(--primary-light);
            border-radius: 10px;
        }

        .tab {
            padding: 14px 26px;
            background: white;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: var(--text-light);
            border-bottom: 4px solid transparent;
            transition: all var(--transition);
            white-space: nowrap;
            position: relative;
            font-size: 0.95rem;
        }

        .tab::after {
            content: '';
            position: absolute;
            bottom: -3px;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
            transform: scaleX(0);
            transition: transform var(--transition);
        }

        .tab.active {
            color: var(--primary-dark);
            background: linear-gradient(to bottom, rgba(46, 125, 50, 0.05) 0%, white 100%);
        }

        .tab.active::after {
            transform: scaleX(1);
        }

        .tab:hover {
            background: rgba(46, 125, 50, 0.05);
            color: var(--primary-color);
        }

        .tab-content {
            display: none;
            animation: fadeIn 0.4s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        /* === KPI CARDS === */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 22px;
            margin-bottom: 32px;
        }

        .kpi-card {
            background: white;
            padding: 24px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border-left: 5px solid var(--primary-color);
            transition: all var(--transition);
            position: relative;
            overflow: hidden;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: radial-gradient(circle, rgba(46, 125, 50, 0.08) 0%, transparent 70%);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }

        .kpi-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-lg);
            border-left-width: 6px;
        }

        .kpi-label {
            font-size: 0.8rem;
            color: var(--text-light);
            text-transform: uppercase;
            margin-bottom: 10px;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .kpi-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--primary-dark);
            line-height: 1;
            margin-bottom: 8px;
        }

        .kpi-change {
            font-size: 0.875rem;
            margin-top: 8px;
            font-weight: 600;
        }

        .kpi-change.positive {
            color: var(--success-color);
        }

        .kpi-change.negative {
            color: var(--at-risk-color);
        }

        /* === CHART GRID === */
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 28px;
            margin-bottom: 32px;
        }

        .chart-card {
            background: white;
            padding: 28px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            transition: all var(--transition);
        }

        .chart-card:hover {
            box-shadow: var(--shadow-md);
        }

        .chart-card.full-width {
            grid-column: 1 / -1;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 22px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--light-bg);
        }

        .chart-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-dark);
            position: relative;
            padding-left: 16px;
        }

        .chart-title::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 5px;
            height: 24px;
            background: var(--primary-gradient);
            border-radius: 3px;
        }

        .chart-container {
            position: relative;
            height: 350px;
        }

        .chart-container.large {
            height: 450px;
        }

        /* === QUICK SEARCH SECTION === */
        .quick-search-section {
            background: white;
            padding: 28px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 28px;
            border: 1px solid rgba(46, 125, 50, 0.1);
        }

        .section-title {
            color: var(--primary-dark);
            margin-bottom: 18px;
            font-size: 1.3rem;
            font-weight: 700;
            position: relative;
            padding-left: 16px;
        }

        .section-title::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 5px;
            height: 24px;
            background: var(--primary-gradient);
            border-radius: 3px;
        }

        .search-container {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .search-input {
            flex: 1;
            padding: 14px 18px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 0.95rem;
            transition: all var(--transition);
            font-family: inherit;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(46, 125, 50, 0.1);
            background: #fafffe;
        }

        .search-input:hover {
            border-color: var(--primary-light);
        }

        /* === SEARCH RESULTS === */
        .search-results {
            margin-top: 18px;
            max-height: 320px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--primary-light) var(--light-bg);
        }

        .search-results::-webkit-scrollbar {
            width: 8px;
        }

        .search-results::-webkit-scrollbar-track {
            background: var(--light-bg);
            border-radius: 10px;
        }

        .search-results::-webkit-scrollbar-thumb {
            background: var(--primary-light);
            border-radius: 10px;
        }

        .search-result-item {
            padding: 16px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            margin-bottom: 10px;
            cursor: pointer;
            transition: all var(--transition);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
        }

        .search-result-item:hover {
            background: var(--light-bg);
            border-color: var(--primary-color);
            transform: translateX(6px);
            box-shadow: var(--shadow-sm);
        }

        .staff-info {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .staff-name {
            font-weight: 700;
            color: var(--primary-dark);
            font-size: 1.05rem;
        }

        .staff-details {
            font-size: 0.875rem;
            color: var(--text-light);
            font-weight: 500;
        }

        /* === AT-RISK SECTION === */
        .at-risk-section {
            background: white;
            padding: 28px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 32px;
        }

        .alert-banner {
            background: linear-gradient(135deg, #fff8e1 0%, #fff3e0 100%);
            border-left: 5px solid var(--warning-color);
            padding: 18px 20px;
            margin-bottom: 22px;
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
        }

        .alert-banner.critical {
            background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
            border-left-color: var(--at-risk-color);
        }

        .alert-banner strong {
            color: var(--primary-dark);
            font-weight: 700;
        }

        /* === TABLE === */
        .table-container {
            overflow-x: auto;
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .data-table th,
        .data-table td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table th {
            background: linear-gradient(to bottom, var(--light-bg) 0%, #f8fbf9 100%);
            font-weight: 700;
            color: var(--primary-dark);
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.8px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .data-table tbody tr {
            transition: all var(--transition-fast);
        }

        .data-table tbody tr:hover {
            background: var(--light-bg);
            transform: scale(1.01);
        }

        /* === BADGES === */
        .badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-danger {
            background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
            color: var(--at-risk-color);
            border: 1px solid rgba(211, 47, 47, 0.3);
        }

        .badge-warning {
            background: linear-gradient(135deg, #fff8e1 0%, #fff3e0 100%);
            color: #f57c00;
            border: 1px solid rgba(255, 167, 38, 0.3);
        }

        .badge-success {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            color: #2e7d32;
            border: 1px solid rgba(46, 125, 50, 0.3);
        }

        /* === LINKS === */
        .staff-link {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 700;
            cursor: pointer;
            transition: all var(--transition-fast);
            position: relative;
        }

        .staff-link::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--primary-gradient);
            transition: width var(--transition);
        }

        .staff-link:hover {
            color: var(--primary-dark);
        }

        .staff-link:hover::after {
            width: 100%;
        }

        /* === MESSAGE === */
        .message {
            position: fixed;
            top: 90px;
            right: 24px;
            padding: 18px 28px;
            border-radius: var(--radius-lg);
            color: white;
            font-weight: 600;
            box-shadow: var(--shadow-xl);
            transform: translateX(400px);
            transition: transform var(--transition);
            z-index: 1000;
            min-width: 280px;
            backdrop-filter: blur(10px);
        }

        .message.show {
            transform: translateX(0);
        }

        .message.success {
            background: linear-gradient(135deg, var(--success-color) 0%, #4caf50 100%);
        }

        .message.error {
            background: linear-gradient(135deg, var(--at-risk-color) 0%, #f44336 100%);
        }

        /* === STAFF SECTION === */
        .staff-section {
            background: white;
            padding: 28px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 18px;
            padding-bottom: 18px;
            border-bottom: 2px solid var(--light-bg);
        }

        .search-input-small {
            padding: 12px 16px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 0.9rem;
            min-width: 280px;
            transition: all var(--transition);
        }

        .search-input-small:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(46, 125, 50, 0.1);
        }

        /* === VIEW PROFILE BUTTON === */
        .view-profile-btn {
            font-family: "Sen", sans-serif !important;
            padding: 8px 16px;
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 700;
            transition: all var(--transition);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: var(--shadow-sm);
        }

        .view-profile-btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .view-profile-btn:active {
            transform: translateY(-1px);
        }

        /* === ATTENDANCE BADGES === */
        .attendance-badge {
            padding: 6px 12px;
            border-radius: 16px;
            font-size: 0.85rem;
            font-weight: 700;
            display: inline-block;
        }

        .rate-excellent {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            color: #1b5e20;
            border: 1px solid rgba(27, 94, 32, 0.2);
        }

        .rate-good {
            background: linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%);
            color: #f57c00;
            border: 1px solid rgba(245, 124, 0, 0.2);
        }

        .rate-poor {
            background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
            color: #c62828;
            border: 1px solid rgba(198, 40, 40, 0.2);
        }

        /* === RESPONSIVE DESIGN === */
        @media (max-width: 1024px) {
            .chart-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 8px;
            }

            .container {
                margin-top: 60px;
            }

            .header h1 {
                font-size: 1.6rem;
            }

            .header p {
                font-size: 0.9rem;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                flex-direction: column;
            }

            .btnn {
                width: 100%;
            }

            .chart-grid {
                grid-template-columns: 1fr;
            }

            .kpi-grid {
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            }

            .kpi-value {
                font-size: 2rem;
            }

            .search-input-small {
                min-width: 100%;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 480px) {
            .header {
                padding: 24px 20px;
            }

            .filter-section,
            .quick-search-section,
            .staff-section {
                padding: 20px;
            }

            .chart-card {
                padding: 20px;
            }

            .kpi-card {
                padding: 18px;
            }

            .tabs {
                gap: 4px;
            }

            .tab {
                padding: 12px 18px;
                font-size: 0.85rem;
            }

            .message {
                right: 12px;
                left: 12px;
                min-width: auto;
            }
        }

        /* === PRINT STYLES === */
        @media print {

            .loader-overlay,
            .filter-section,
            .tabs,
            .message,
            .btnn,
            .view-profile-btn {
                display: none !important;
            }

            body {
                background: white;
            }

            .container {
                margin-top: 0;
            }

            .chart-card,
            .kpi-card {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>

<body>
    <?php if (file_exists('nav.php')) require_once "nav.php"; ?>

    <div class="loader-overlay" id="loader">
        <div class="loader"></div>
        <div class="loader-text">Loading Analytics...</div>
    </div>

    <div class="container">
        <div class="header">
            <h1>Staff Attendance Analytics</h1>
            <p>Real-time insights and comprehensive staff attendance analysis</p>
        </div>

        <div class="filter-section">
            <form id="filterForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div class="filter-grid">
                    <div class="form-group">
                        <label for="date_from">From Date</label>
                        <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="date_to">To Date</label>
                        <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="department">Department</label>
                        <select id="department" name="department">
                            <option value="">All Departments</option>
                            <?php while ($dept = $departments->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($dept['department']) ?>"
                                    <?= $selected_department === $dept['department'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dept['department']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="position">Position</label>
                        <select id="position" name="position">
                            <option value="">All Positions</option>
                            <?php while ($pos = $positions->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($pos['designation']) ?>"
                                    <?= $selected_position === $pos['designation'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($pos['designation']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btnn btnn-primary" id="generatebtnn">
                        Generate Analytics
                    </button>
                    <button type="button" class="btnn btnn-secondary" id="resetbtnn">
                        Reset Filters
                    </button>
                    <button type="button" class="btnn btnn-export" id="exportPdfbtnn">
                        Export PDF
                    </button>
                    <button type="button" class="btnn btnn-export" id="exportExcelbtnn">
                        Export Excel
                    </button>
                </div>
            </form>
        </div>

        <!-- Quick Staff Search -->
        <div class="quick-search-section">
            <h3 class="section-title">View Individual Staff Profile</h3>
            <div class="search-container">
                <input
                    type="text"
                    id="staffSearch"
                    placeholder="Search by Staff ID or Name..."
                    class="search-input">
                <button onclick="openSelectedStaff()" class="btnn btnn-primary">
                    View Profile →
                </button>
            </div>
            <div id="searchResults" class="search-results"></div>
        </div>

        <div class="tabs">
            <button class="tab active" data-tab="overview">Overview</button>
            <button class="tab" data-tab="trends">Trends</button>
            <button class="tab" data-tab="comparisons">Comparisons</button>
            <button class="tab" data-tab="at-risk">At-Risk Staff</button>
            <button class="tab" data-tab="patterns">Patterns</button>
            <button class="tab" data-tab="all-staff">All Staff</button>
        </div>

        <!-- Overview Tab -->
        <div class="tab-content active" id="overview-tab">
            <div class="kpi-grid" id="kpiGrid">
                <!-- KPIs will be loaded here -->
            </div>

            <div class="chart-grid">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Attendance Distribution</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="pieChart"></canvas>
                    </div>
                </div>

                <div class="chart-card">
                    <div class="chart-header">
                        <h3 class="chart-title">Attendance Rate Gauge</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="gaugeChart"></canvas>
                    </div>
                </div>

                <div class="chart-card full-width">
                    <div class="chart-header">
                        <h3 class="chart-title">Daily Attendance Trends</h3>
                    </div>
                    <div class="chart-container large">
                        <canvas id="lineChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Trends Tab -->
        <div class="tab-content" id="trends-tab">
            <div class="chart-grid">
                <div class="chart-card full-width">
                    <div class="chart-header">
                        <h3 class="chart-title">Weekly Attendance Trends</h3>
                    </div>
                    <div class="chart-container large">
                        <canvas id="weeklyTrendChart"></canvas>
                    </div>
                </div>

                <div class="chart-card full-width">
                    <div class="chart-header">
                        <h3 class="chart-title">Monthly Comparison</h3>
                    </div>
                    <div class="chart-container large">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Comparisons Tab -->
        <div class="tab-content" id="comparisons-tab">
            <div class="chart-grid">
                <div class="chart-card full-width">
                    <div class="chart-header">
                        <h3 class="chart-title">Department Performance</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="departmentChart"></canvas>
                    </div>
                </div>

                <div class="chart-card full-width">
                    <div class="chart-header">
                        <h3 class="chart-title">Position Comparison</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="positionChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- At-Risk Tab -->
        <div class="tab-content" id="at-risk-tab">
            <div class="at-risk-section" id="atRiskSection">
                <!-- At-risk staff will be loaded here -->
            </div>
        </div>

        <!-- Patterns Tab -->
        <div class="tab-content" id="patterns-tab">
            <div class="chart-grid">
                <div class="chart-card full-width">
                    <div class="chart-header">
                        <h3 class="chart-title">Attendance by Day of Week</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="dayOfWeekChart"></canvas>
                    </div>
                </div>

                <div class="chart-card full-width">
                    <div class="chart-header">
                        <h3 class="chart-title">Heatmap Calendar</h3>
                    </div>
                    <div id="heatmapContainer" style="padding: 20px;">
                        <!-- Heatmap will be generated here -->
                    </div>
                </div>
            </div>
        </div>

        <!-- All Staff Tab -->
        <div class="tab-content" id="all-staff-tab">
            <div class="staff-section">
                <div class="section-header">
                    <h3 class="chart-title">Staff Directory</h3>
                    <input
                        type="text"
                        id="tableSearch"
                        placeholder="Filter staff..."
                        class="search-input-small">
                </div>
                <div class="table-container">
                    <table class="data-table" id="allStaffTable">
                        <thead>
                            <tr>
                                <th>Staff ID</th>
                                <th>Name</th>
                                <th>Department</th>
                                <th>Position</th>
                                <th>Attendance Rate</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="allStaffBody">
                            <tr>
                                <td colspan="6" style="text-align: center;">Loading staff...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="message" class="message"></div>

    <script>
        let allStaffData = [];
        let selectedStaffId = null;
        const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
        let charts = {};

        document.addEventListener('DOMContentLoaded', function() {
            setupEventListeners();
            loadAnalytics();
            loadAllStaff();
        });

        function setupEventListeners() {
            document.querySelectorAll('.tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabName = this.dataset.tab;
                    switchTab(tabName);
                });
            });

            document.getElementById('filterForm').addEventListener('submit', function(e) {
                e.preventDefault();
                loadAnalytics();
            });

            document.getElementById('resetbtnn').addEventListener('click', function() {
                document.getElementById('filterForm').reset();
                loadAnalytics();
            });

            document.getElementById('exportPdfbtnn').addEventListener('click', exportToPDF);
            document.getElementById('exportExcelbtnn').addEventListener('click', exportToExcel);
        }

        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');

            document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
            document.getElementById(`${tabName}-tab`).classList.add('active');
        }

        async function loadAnalytics() {
            showLoader();

            try {
                const formData = new FormData(document.getElementById('filterForm'));
                const params = new URLSearchParams(formData);

                const response = await fetch(`api/get_staff_analytics_data.php?${params}`);

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.message || 'Failed to load analytics');
                }

                renderKPIs(data.kpis);
                renderCharts(data);
                renderAtRiskStaff(data.at_risk_staff);

                hideLoader();
                showMessage('Analytics loaded successfully!', 'success');

            } catch (error) {
                console.error('Error loading analytics:', error);
                hideLoader();
                showMessage('Failed to load analytics. Please try again.', 'error');
            }
        }

        function renderKPIs(kpis) {
            const container = document.getElementById('kpiGrid');
            container.innerHTML = `
                <div class="kpi-card">
                    <div class="kpi-label">Total Staff</div>
                    <div class="kpi-value">${kpis.total_staff}</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Attendance Rate</div>
                    <div class="kpi-value">${kpis.attendance_rate}%</div>
                    <div class="kpi-change ${kpis.rate_change >= 0 ? 'positive' : 'negative'}">
                        ${kpis.rate_change >= 0 ? '↑' : '↓'} ${Math.abs(kpis.rate_change)}% from last period
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Present</div>
                    <div class="kpi-value" style="color: var(--present-color);">${kpis.present}</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Absent</div>
                    <div class="kpi-value" style="color: var(--absent-color);">${kpis.absent}</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Late</div>
                    <div class="kpi-value" style="color: var(--late-color);">${kpis.late}</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">At-Risk Staff</div>
                    <div class="kpi-value" style="color: var(--at-risk-color);">${kpis.at_risk}</div>
                </div>
            `;
        }

        function renderCharts(data) {
            Object.values(charts).forEach(chart => chart.destroy());
            charts = {};

            // Pie Chart
            charts.pie = new Chart(document.getElementById('pieChart'), {
                type: 'doughnut',
                data: {
                    labels: ['Present', 'Absent', 'Late', 'On Leave'],
                    datasets: [{
                        data: [
                            data.status_distribution.present,
                            data.status_distribution.absent,
                            data.status_distribution.late,
                            data.status_distribution.on_leave
                        ],
                        backgroundColor: [
                            'rgba(76, 175, 80, 0.8)',
                            'rgba(244, 67, 54, 0.8)',
                            'rgba(255, 152, 0, 0.8)',
                            'rgba(156, 39, 176, 0.8)'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // Gauge Chart
            charts.gauge = new Chart(document.getElementById('gaugeChart'), {
                type: 'doughnut',
                data: {
                    datasets: [{
                        data: [data.kpis.attendance_rate, 100 - data.kpis.attendance_rate],
                        backgroundColor: [
                            data.kpis.attendance_rate >= 90 ? 'rgba(76, 175, 80, 0.8)' :
                            data.kpis.attendance_rate >= 75 ? 'rgba(255, 152, 0, 0.8)' :
                            'rgba(244, 67, 54, 0.8)',
                            'rgba(224, 224, 224, 0.3)'
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    circumference: 180,
                    rotation: 270,
                    cutout: '75%',
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            enabled: false
                        }
                    }
                },
                plugins: [{
                    afterDraw: (chart) => {
                        const ctx = chart.ctx;
                        const centerX = (chart.chartArea.left + chart.chartArea.right) / 2;
                        const centerY = chart.chartArea.bottom;

                        ctx.save();
                        ctx.font = 'bold 48px Arial';
                        ctx.fillStyle = '#1565c0';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'bottom';
                        ctx.fillText(`${data.kpis.attendance_rate}%`, centerX, centerY - 20);

                        ctx.font = '16px Arial';
                        ctx.fillStyle = '#666';
                        ctx.fillText('Attendance Rate', centerX, centerY);
                        ctx.restore();
                    }
                }]
            });

            // Line Chart
            charts.line = new Chart(document.getElementById('lineChart'), {
                type: 'line',
                data: {
                    labels: data.daily_trends.map(d => d.date),
                    datasets: [{
                            label: 'Present',
                            data: data.daily_trends.map(d => d.present),
                            borderColor: 'rgba(76, 175, 80, 1)',
                            backgroundColor: 'rgba(76, 175, 80, 0.1)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Absent',
                            data: data.daily_trends.map(d => d.absent),
                            borderColor: 'rgba(244, 67, 54, 1)',
                            backgroundColor: 'rgba(244, 67, 54, 0.1)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Late',
                            data: data.daily_trends.map(d => d.late),
                            borderColor: 'rgba(255, 152, 0, 1)',
                            backgroundColor: 'rgba(255, 152, 0, 0.1)',
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            position: 'top'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });

            // Weekly Trend Chart
            charts.weekly = new Chart(document.getElementById('weeklyTrendChart'), {
                type: 'bar',
                data: {
                    labels: data.weekly_trends.map(w => `Week ${w.week}`),
                    datasets: [{
                        label: 'Attendance Rate %',
                        data: data.weekly_trends.map(w => w.rate),
                        backgroundColor: data.weekly_trends.map(w =>
                            w.rate >= 90 ? 'rgba(76, 175, 80, 0.8)' :
                            w.rate >= 75 ? 'rgba(255, 152, 0, 0.8)' :
                            'rgba(244, 67, 54, 0.8)'
                        )
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100
                        }
                    }
                }
            });

            // Monthly Chart
            charts.monthly = new Chart(document.getElementById('monthlyChart'), {
                type: 'line',
                data: {
                    labels: data.monthly_comparison.map(m => m.month),
                    datasets: [{
                            label: 'Present',
                            data: data.monthly_comparison.map(m => m.present),
                            borderColor: 'rgba(76, 175, 80, 1)',
                            backgroundColor: 'rgba(76, 175, 80, 0.2)',
                            fill: true
                        },
                        {
                            label: 'Absent',
                            data: data.monthly_comparison.map(m => m.absent),
                            borderColor: 'rgba(244, 67, 54, 1)',
                            backgroundColor: 'rgba(244, 67, 54, 0.2)',
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });

            // Department Chart
            charts.department = new Chart(document.getElementById('departmentChart'), {
                type: 'bar',
                data: {
                    labels: data.department_performance.map(d => d.department),
                    datasets: [{
                        label: 'Attendance Rate %',
                        data: data.department_performance.map(d => d.rate),
                        backgroundColor: 'rgba(21, 101, 192, 0.8)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    scales: {
                        x: {
                            beginAtZero: true,
                            max: 100
                        }
                    }
                }
            });

            // Position Chart
            charts.position = new Chart(document.getElementById('positionChart'), {
                type: 'bar',
                data: {
                    labels: data.position_performance.map(p => p.position),
                    datasets: [{
                        label: 'Attendance Rate %',
                        data: data.position_performance.map(p => p.rate),
                        backgroundColor: 'rgba(25, 118, 210, 0.8)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    scales: {
                        x: {
                            beginAtZero: true,
                            max: 100
                        }
                    }
                }
            });

            // Day of Week Pattern
            charts.dayOfWeek = new Chart(document.getElementById('dayOfWeekChart'), {
                type: 'bar',
                data: {
                    labels: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
                    datasets: [{
                        label: 'Average Attendance',
                        data: data.day_of_week_pattern,
                        backgroundColor: [
                            'rgba(244, 67, 54, 0.8)',
                            'rgba(233, 30, 99, 0.8)',
                            'rgba(156, 39, 176, 0.8)',
                            'rgba(103, 58, 183, 0.8)',
                            'rgba(63, 81, 181, 0.8)',
                            'rgba(33, 150, 243, 0.8)',
                            'rgba(76, 175, 80, 0.8)'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });

            generateHeatmap(data.heatmap_data);
        }

        function renderAtRiskStaff(staff) {
            const container = document.getElementById('atRiskSection');

            if (!staff || staff.length === 0) {
                container.innerHTML = `
                    <div class="alert-banner">
                        <strong>✅ Great News!</strong> No staff members are currently at risk.
                    </div>
                `;
                return;
            }

            const criticalStaff = staff.filter(s => s.consecutive_absences >= 5);
            const warningStaff = staff.filter(s => s.consecutive_absences >= 3 && s.consecutive_absences < 5);

            let html = '';

            if (criticalStaff.length > 0) {
                html += `
                    <div class="alert-banner critical">
                        <strong>🚨 Critical Alert:</strong> ${criticalStaff.length} staff member(s) with 5+ consecutive absences
                    </div>
                `;
            }

            if (warningStaff.length > 0) {
                html += `
                    <div class="alert-banner">
                        <strong>⚠️ Warning:</strong> ${warningStaff.length} staff member(s) with 3-4 consecutive absences
                    </div>
                `;
            }

            html += `
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Staff ID</th>
                                <th>Name</th>
                                <th>Department</th>
                                <th>Position</th>
                                <th>Consecutive Absences</th>
                                <th>Total Absences</th>
                                <th>Attendance Rate</th>
                                <th>Last Reason</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            staff.forEach(member => {
                const badgeClass = member.consecutive_absences >= 5 ? 'badge-danger' : 'badge-warning';
                const statusText = member.consecutive_absences >= 5 ? 'Critical' : 'Warning';

                html += `
                    <tr>
                        <td>${escapeHtml(member.staff_id)}</td>
                        <td>
                            <a href="staff_profile.php?id=${escapeHtml(member.staff_id)}" class="staff-link" target="_blank">
                                ${escapeHtml(member.name)}
                            </a>
                        </td>
                        <td>${escapeHtml(member.department)}</td>
                        <td>${escapeHtml(member.position)}</td>
                        <td><strong>${member.consecutive_absences}</strong></td>
                        <td>${member.total_absences}</td>
                        <td>${member.attendance_rate}%</td>
                        <td>${escapeHtml(member.last_reason || 'N/A')}</td>
                        <td><span class="badge ${badgeClass}">${statusText}</span></td>
                        <td>
                            <a href="staff_profile.php?id=${escapeHtml(member.staff_id)}" class="staff-link" target="_blank">
                                View Details →
                            </a>
                        </td>
                    </tr>
                `;
            });

            html += `
                        </tbody>
                    </table>
                </div>
            `;

            container.innerHTML = html;
        }

        function generateHeatmap(data) {
            const container = document.getElementById('heatmapContainer');

            if (!data || data.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <p style="font-size: 1.1rem; margin-bottom: 10px;">📅 No attendance data available for heatmap</p>
                        <p style="font-size: 0.9rem;">Select a date range with attendance records to view the calendar.</p>
                    </div>
                `;
                return;
            }

            const dateMap = {};
            data.forEach(record => {
                const date = record.date;
                if (!dateMap[date]) {
                    dateMap[date] = {
                        present: 0,
                        absent: 0,
                        late: 0,
                        on_leave: 0,
                        total: 0
                    };
                }
                dateMap[date][record.status]++;
                dateMap[date].total++;
            });

            Object.keys(dateMap).forEach(date => {
                const day = dateMap[date];
                day.rate = day.total > 0 ? Math.round((day.present / day.total) * 100) : 0;
            });

            const dates = Object.keys(dateMap).sort();
            if (dates.length === 0) {
                container.innerHTML = '<p style="color: #666;">No data to display</p>';
                return;
            }

            const firstDate = new Date(dates[0]);
            const lastDate = new Date(dates[dates.length - 1]);

            const months = [];
            let currentDate = new Date(firstDate.getFullYear(), firstDate.getMonth(), 1);
            const endDate = new Date(lastDate.getFullYear(), lastDate.getMonth(), 1);

            while (currentDate <= endDate) {
                months.push(new Date(currentDate));
                currentDate.setMonth(currentDate.getMonth() + 1);
            }

            let html = `
                <div style="margin-bottom: 20px;">
                    <h4 style="color: var(--primary-dark); margin-bottom: 15px;">Attendance Calendar Heatmap</h4>
                    <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap; margin-bottom: 20px;">
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <span style="font-size: 0.9rem; color: #666;">Legend:</span>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <div style="width: 20px; height: 20px; background: #c8e6c9; border-radius: 3px;"></div>
                                <span style="font-size: 0.85rem;">90-100%</span>
                            </div>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <div style="width: 20px; height: 20px; background: #fff9c4; border-radius: 3px;"></div>
                                <span style="font-size: 0.85rem;">75-89%</span>
                            </div>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <div style="width: 20px; height: 20px; background: #ffccbc; border-radius: 3px;"></div>
                                <span style="font-size: 0.85rem;">50-74%</span>
                            </div>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <div style="width: 20px; height: 20px; background: #ffcdd2; border-radius: 3px;"></div>
                                <span style="font-size: 0.85rem;">&lt;50%</span>
                            </div>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <div style="width: 20px; height: 20px; background: #f5f5f5; border-radius: 3px; border: 1px solid #e0e0e0;"></div>
                                <span style="font-size: 0.85rem;">No data</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 30px;">
            `;

            months.forEach(monthDate => {
                const year = monthDate.getFullYear();
                const month = monthDate.getMonth();
                const monthName = monthDate.toLocaleString('default', {
                    month: 'long',
                    year: 'numeric'
                });

                html += `
                    <div style="background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                        <h5 style="text-align: center; color: var(--primary-dark); margin-bottom: 12px; font-size: 0.95rem;">${monthName}</h5>
                        <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px;">
                `;

                const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                dayNames.forEach(day => {
                    html += `
                        <div style="text-align: center; font-size: 0.7rem; font-weight: 600; color: #666; padding: 4px;">
                            ${day}
                        </div>
                    `;
                });

                const firstDay = new Date(year, month, 1).getDay();
                const daysInMonth = new Date(year, month + 1, 0).getDate();

                for (let i = 0; i < firstDay; i++) {
                    html += '<div style="padding: 8px;"></div>';
                }

                for (let day = 1; day <= daysInMonth; day++) {
                    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                    const dayData = dateMap[dateStr];

                    let bgColor = '#f5f5f5';
                    let borderColor = '#e0e0e0';
                    let tooltip = 'No attendance data';

                    if (dayData) {
                        const rate = dayData.rate;
                        if (rate >= 90) bgColor = '#c8e6c9';
                        else if (rate >= 75) bgColor = '#fff9c4';
                        else if (rate >= 50) bgColor = '#ffccbc';
                        else bgColor = '#ffcdd2';

                        borderColor = 'transparent';
                        tooltip = `${dateStr}\nRate: ${rate}%\nPresent: ${dayData.present}\nAbsent: ${dayData.absent}\nLate: ${dayData.late}\nOn Leave: ${dayData.on_leave}\nTotal: ${dayData.total}`;
                    }

                    html += `
                        <div 
                            title="${tooltip.replace(/\n/g, '&#10;')}"
                            style="
                                background: ${bgColor};
                                border: 1px solid ${borderColor};
                                border-radius: 4px;
                                padding: 8px;
                                text-align: center;
                                font-size: 0.8rem;
                                font-weight: 500;
                                cursor: ${dayData ? 'pointer' : 'default'};
                                transition: transform 0.2s, box-shadow 0.2s;
                            "
                            onmouseover="this.style.transform='scale(1.1)'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.15)'; this.style.zIndex='10';"
                            onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='none'; this.style.zIndex='1';"
                        >
                            ${day}
                        </div>
                    `;
                }

                html += `
                        </div>
                    </div>
                `;
            });

            html += '</div>';

            const totalDays = Object.keys(dateMap).length;
            const avgRate = totalDays > 0 ?
                Math.round(Object.values(dateMap).reduce((sum, day) => sum + day.rate, 0) / totalDays) : 0;

            const excellentDays = Object.values(dateMap).filter(d => d.rate >= 90).length;
            const goodDays = Object.values(dateMap).filter(d => d.rate >= 75 && d.rate < 90).length;
            const concernDays = Object.values(dateMap).filter(d => d.rate < 75).length;

            html += `
                <div style="margin-top: 30px; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <h5 style="color: var(--primary-dark); margin-bottom: 15px;">Period Summary</h5>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                        <div>
                            <div style="font-size: 0.85rem; color: #666; margin-bottom: 5px;">Average Rate</div>
                            <div style="font-size: 1.8rem; font-weight: 700; color: var(--primary-color);">${avgRate}%</div>
                        </div>
                        <div>
                            <div style="font-size: 0.85rem; color: #666; margin-bottom: 5px;">Excellent Days (≥90%)</div>
                            <div style="font-size: 1.8rem; font-weight: 700; color: #4caf50;">${excellentDays}</div>
                        </div>
                        <div>
                            <div style="font-size: 0.85rem; color: #666; margin-bottom: 5px;">Good Days (75-89%)</div>
                            <div style="font-size: 1.8rem; font-weight: 700; color: #ff9800;">${goodDays}</div>
                        </div>
                        <div>
                            <div style="font-size: 0.85rem; color: #666; margin-bottom: 5px;">Concern Days (&lt;75%)</div>
                            <div style="font-size: 1.8rem; font-weight: 700; color: #f44336;">${concernDays}</div>
                        </div>
                    </div>
                </div>
            `;

            container.innerHTML = html;
        }

        async function loadAllStaff() {
            try {
                const response = await fetch('api/get_all_staff.php');
                const data = await response.json();

                if (data.success) {
                    allStaffData = data.staff;
                    renderAllStaffTable(allStaffData);
                    setupStaffSearch();
                }
            } catch (error) {
                console.error('Error loading staff:', error);
            }
        }

        function renderAllStaffTable(staff) {
            const tbody = document.getElementById('allStaffBody');

            if (!staff || staff.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No staff found</td></tr>';
                return;
            }

            let html = '';
            staff.forEach(member => {
                const rateClass = member.attendance_rate >= 90 ? 'rate-excellent' :
                    member.attendance_rate >= 75 ? 'rate-good' : 'rate-poor';

                html += `
                    <tr>
                        <td>${escapeHtml(member.staff_id)}</td>
                        <td><strong>${escapeHtml(member.name)}</strong></td>
                        <td>${escapeHtml(member.department)}</td>
                        <td>${escapeHtml(member.position)}</td>
                        <td>
                            <span class="attendance-badge ${rateClass}">
                                ${member.attendance_rate}%
                            </span>
                        </td>
                        <td>
                            <button 
                                onclick="viewProfile('${escapeHtml(member.staff_id)}')" 
                                class="view-profile-btn"
                            >
                                View Profile →
                            </button>
                        </td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
        }

        function setupStaffSearch() {
            const searchInput = document.getElementById('staffSearch');
            const resultsDiv = document.getElementById('searchResults');

            searchInput.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();

                if (query.length < 2) {
                    resultsDiv.innerHTML = '';
                    selectedStaffId = null;
                    return;
                }

                const matches = allStaffData.filter(s =>
                    s.staff_id.toLowerCase().includes(query) ||
                    s.name.toLowerCase().includes(query) ||
                    s.department.toLowerCase().includes(query)
                ).slice(0, 10);

                if (matches.length > 0) {
                    let html = '';
                    matches.forEach(member => {
                        html += `
                            <div class="search-result-item" onclick="selectStaff('${member.staff_id}')">
                                <div class="staff-info">
                                    <span class="staff-name">${escapeHtml(member.name)}</span>
                                    <span class="staff-details">
                                        ${escapeHtml(member.staff_id)} • 
                                        ${escapeHtml(member.department)} • 
                                        ${escapeHtml(member.position)} • 
                                        Attendance: ${member.attendance_rate}%
                                    </span>
                                </div>
                                <span style="color: var(--primary-color);">→</span>
                            </div>
                        `;
                    });
                    resultsDiv.innerHTML = html;
                } else {
                    resultsDiv.innerHTML = '<p style="color: #666; padding: 10px;">No staff found</p>';
                }
            });

            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && selectedStaffId) {
                    viewProfile(selectedStaffId);
                }
            });
        }

        function selectStaff(staffId) {
            selectedStaffId = staffId;
            viewProfile(staffId);
        }

        function openSelectedStaff() {
            if (selectedStaffId) {
                viewProfile(selectedStaffId);
            } else {
                const searchValue = document.getElementById('staffSearch').value.trim();
                if (searchValue) {
                    viewProfile(searchValue);
                } else {
                    showMessage('Please search and select a staff member first', 'error');
                }
            }
        }

        function viewProfile(staffId) {
            window.open(`staff_profile.php?id=${encodeURIComponent(staffId)}`, '_blank');
        }

        document.getElementById('tableSearch')?.addEventListener('input', function() {
            const query = this.value.toLowerCase();
            const filtered = allStaffData.filter(s =>
                s.staff_id.toLowerCase().includes(query) ||
                s.name.toLowerCase().includes(query) ||
                s.department.toLowerCase().includes(query) ||
                s.position.toLowerCase().includes(query)
            );
            renderAllStaffTable(filtered);
        });

        function exportToPDF() {
            showMessage('Generating PDF...', 'success');
            // PDF export implementation
        }

        function exportToExcel() {
            showMessage('Generating Excel...', 'success');
            // Excel export implementation
        }

        function showLoader() {
            document.getElementById('loader').classList.remove('hidden');
        }

        function hideLoader() {
            document.getElementById('loader').classList.add('hidden');
        }

        function showMessage(text, type) {
            const msg = document.getElementById('message');
            msg.textContent = text;
            msg.className = `message ${type} show`;
            setTimeout(() => msg.classList.remove('show'), 3000);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>

</html>