<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Fees Bursaries");

// Role-based access control for fees management
function checkFeesAccess($conn)
{
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit();
    }

    // Get user role from database
    $user_id = $_SESSION['user_id'];
    $sql = "SELECT role FROM users WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $user_role = strtolower(trim($user['role']));

        // Allow access only for bursar and super user
        $allowed_roles = ['bursar', 'developer', 'super user', 'school leader'];

        if (!in_array($user_role, $allowed_roles)) {
            // Store the current page URL for the back button
            $_SESSION['previous_page'] = $_SERVER['REQUEST_URI'];
            $_SESSION['access_denied_message'] = "Access Denied: You don't have permission to access the fees management system.";
            header("Location: access_denied.php");
            exit();
        }
    } else {
        // User not found in database
        session_destroy();
        header("Location: index.php");
        exit();
    }

    $stmt->close();
}

// Call the function to check access
checkFeesAccess($conn);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Bursary Management</title>
    <link rel="stylesheet" href="styles.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        /* Root Variables */
        :root {
            --primary-green: #2e7d32;
            --dark-green: #1b5e20;
            --light-green: #81c784;
            --accent-green: #4caf50;
            --background: #f5f9f5;
            --white: #ffffff;
            --text-primary: #1b5e20;
            --text-secondary: #2e7d32;
            --text-muted: #666666;
            --border-color: #e0e0e0;
            --success-color: #4caf50;
            --error-color: #f44336;
            --warning-color: #ff9800;
            --danger-color: #dc3545;
            --shadow-light: 0 2px 4px rgba(0, 0, 0, 0.1);
            --shadow-medium: 0 4px 8px rgba(0, 0, 0, 0.12);
            --shadow-heavy: 0 8px 16px rgba(0, 0, 0, 0.15);
            --border-radius: 8px;
            --border-radius-large: 12px;
            --transition: all 0.3s ease;
        }

        /* Reset and Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: var(--background);
            color: var(--text-primary);
            line-height: 1.6;
            font-size: 16px;
        }

        /* Container */
        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%);
            color: var(--white);
            padding: 10px 0;
            margin-bottom: -15px;
            margin-top: 45px;
            border-radius: var(--border-radius-large);
            box-shadow: var(--shadow-medium);
        }

        .header-content {
            text-align: center;
            max-width: 800px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .header-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .header-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            font-weight: 300;
        }

        /* Main Content */
        .main-content {
            display: grid;
            gap: 40px;
            grid-template-columns: 1fr;
        }

        /* Controls Section */
        .controls-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }

        /* Search Container */
        .search-container {
            flex: 1;
            max-width: 600px;
        }

        .search-box {
            position: relative;
            background: var(--white);
            border-radius: var(--border-radius-large);
            box-shadow: var(--shadow-medium);
            overflow: hidden;
            transition: var(--transition);
        }

        .search-box:focus-within {
            box-shadow: var(--shadow-heavy);
            transform: translateY(-2px);
        }

        .search-icon {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1.1rem;
            z-index: 2;
        }

        .search-input {
            width: 100%;
            padding: 18px 60px 18px 55px;
            border: none;
            outline: none;
            font-size: 1rem;
            background: transparent;
            color: var(--text-primary);
        }

        .search-input::placeholder {
            color: var(--text-muted);
            font-style: italic;
        }

        .search-clear {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .search-clear:hover {
            background-color: rgba(0, 0, 0, 0.05);
            color: var(--text-primary);
        }

        /* Add Bursary Button */
        .add-bursary-btnn {
            font-family: "Sen", sans-serif !important;
            white-space: nowrap;
            padding: 18px 30px;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .add-bursary-btnn i {
            font-size: 1.1rem;
        }

        /* Card Component */
        .card {
            background: var(--white);
            border-radius: var(--border-radius-large);
            box-shadow: var(--shadow-medium);
            overflow: hidden;
            transition: var(--transition);
        }

        .card:hover {
            box-shadow: var(--shadow-heavy);
        }

        .card-header {
            background: linear-gradient(135deg, var(--light-green) 0%, var(--accent-green) 100%);
            color: var(--white);
            padding: 25px 30px;
            text-align: center;
        }

        .card-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .card-description {
            font-size: 1rem;
            opacity: 0.9;
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
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .modal-content {
            background: var(--white);
            border-radius: var(--border-radius-large);
            box-shadow: var(--shadow-heavy);
            width: 90%;
            max-width: 900px;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideIn 0.3s ease;
        }

        .modal-small {
            max-width: 500px;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%);
            color: var(--white);
            padding: 10px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--white);
            font-size: 1rem;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
        }

        .modal-close:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .modal-body {
            padding: 30px;
        }

        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }

        /* Modal Form */
        .modal-form {
            padding: 30px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 8px;
            font-size: 0.95rem;
        }

        .form-control {
            padding: 12px 16px;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: var(--transition);
            background-color: var(--white);
            font-family: inherit;
            resize: vertical;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-green);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        .form-control:read-only {
            background-color: #f8f9fa;
            color: var(--text-muted);
        }

        .form-control[required]:invalid {
            border-color: var(--error-color);
        }

        textarea.form-control {
            min-height: 100px;
        }

        .form-error {
            color: var(--error-color);
            font-size: 0.85rem;
            margin-top: 5px;
            display: none;
        }

        .form-error.show {
            display: block;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }

        /* Button Styles */
        .btnn {
            font-family: "Sen", sans-serif !important;
            padding: 12px 30px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            min-width: 130px;
            gap: 8px;
        }

        .btnn-primary {
            background: linear-gradient(135deg, var(--accent-green) 0%, var(--primary-green) 100%);
            color: var(--white);
            box-shadow: var(--shadow-light);
        }

        .btnn-primary:hover {
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%);
            box-shadow: var(--shadow-medium);
            transform: translateY(-2px);
        }

        .btnn-secondary {
            background: #f5f5f5;
            color: var(--text-primary);
            border: 2px solid var(--border-color);
        }

        .btnn-secondary:hover {
            background: #e0e0e0;
            border-color: var(--text-muted);
        }

        .btnn-danger {
            background: var(--danger-color);
            color: var(--white);
            box-shadow: var(--shadow-light);
        }

        .btnn-danger:hover {
            background: #c82333;
            box-shadow: var(--shadow-medium);
            transform: translateY(-2px);
        }

        .btnn:active {
            transform: translateY(0);
        }

        /* Action Buttons */
        .action-btnn {
            padding: 8px 12px;
            min-width: auto;
            margin: 0 2px;
            font-size: 0.9rem;
        }

        .btnn-edit {
            font-family: "Sen", sans-serif !important;
            background: var(--warning-color);
            color: var(--white);
        }

        .btnn-edit:hover {
            background: #e68900;
        }

        .btnn-delete {
            background: var(--danger-color);
            color: var(--white);
        }

        .btnn-delete:hover {
            background: #c82333;
        }

        /* Table Section */
        .table-section {
            margin-top: 20px;
        }

        .table-container {
            padding: 30px;
        }

        .table-filters {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            min-width: 200px;
        }

        .filter-label {
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .filter-select {
            padding: 8px 12px;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--accent-green);
        }

        /* Table Wrapper */
        .table-wrapper {
            overflow-x: auto;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
        }

        /* Data Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--white);
            font-size: 0.95rem;
        }

        .data-table thead {
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%);
            color: var(--white);
        }

        .data-table th {
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
        }

        .data-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            transition: var(--transition);
        }

        .data-table tbody tr:hover {
            background-color: rgba(76, 175, 80, 0.05);
        }

        .data-table tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .data-table tbody tr:nth-child(even):hover {
            background-color: rgba(76, 175, 80, 0.08);
        }

        /* Actions Column */
        .actions-cell {
            white-space: nowrap;
            text-align: center;
        }

        /* Delete Confirmation Modal */
        .delete-confirmation {
            text-align: center;
            padding: 20px;
        }

        .warning-icon {
            font-size: 3rem;
            color: var(--warning-color);
            margin-bottom: 20px;
        }

        .delete-confirmation p {
            font-size: 1.1rem;
            margin-bottom: 10px;
            color: var(--text-primary);
        }

        .warning-text {
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        /* Notification System */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            max-width: 400px;
            transform: translateX(450px);
            transition: var(--transition);
        }

        .notification.show {
            transform: translateX(0);
        }

        .notification-content {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-heavy);
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            border-left: 4px solid var(--success-color);
        }

        .notification.success .notification-content {
            border-left-color: var(--success-color);
        }

        .notification.error .notification-content {
            border-left-color: var(--error-color);
        }

        .notification.warning .notification-content {
            border-left-color: var(--warning-color);
        }

        .notification-icon {
            font-size: 1.2rem;
            font-weight: bold;
        }

        .notification.success .notification-icon::before {
            content: '✓';
            color: var(--success-color);
        }

        .notification.error .notification-icon::before {
            content: '✗';
            color: var(--error-color);
        }

        .notification.warning .notification-icon::before {
            content: '⚠';
            color: var(--warning-color);
        }

        .notification-message {
            flex: 1;
            font-weight: 500;
            color: var(--text-primary);
        }

        .notification-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-muted);
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: var(--transition);
        }

        .notification-close:hover {
            background-color: #f0f0f0;
            color: var(--text-primary);
        }

        /* Loading State */
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 2px solid var(--accent-green);
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        /* Empty State - Continuing from where it was cut off */
        .empty-state h3 {
            font-size: 1.3rem;
            margin-bottom: 10px;
            color: var(--text-secondary);
        }

        .empty-state p {
            font-size: 1rem;
            margin-bottom: 20px;
        }

        .empty-state-icon {
            font-size: 4rem;
            color: var(--border-color);
            margin-bottom: 20px;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .container {
                padding: 15px;
            }

            .form-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
            }
        }

        @media (max-width: 768px) {
            .header-title {
                font-size: 2rem;
            }

            .header-subtitle {
                font-size: 1rem;
            }

            .controls-section {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }

            .search-container {
                max-width: none;
            }

            .table-filters {
                flex-direction: column;
                gap: 15px;
            }

            .filter-group {
                min-width: auto;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .form-actions {
                flex-direction: column;
            }

            .btnn {
                width: 100%;
                justify-content: center;
            }

            .modal-content {
                width: 95%;
                margin: 10px;
            }

            .modal-form {
                padding: 20px;
            }

            .card-header {
                padding: 20px;
            }

            .table-container {
                padding: 20px;
            }

            .data-table {
                font-size: 0.85rem;
            }

            .data-table th,
            .data-table td {
                padding: 8px 6px;
            }

            .action-btnn {
                padding: 6px 8px;
                font-size: 0.8rem;
                margin: 1px;
            }
        }

        @media (max-width: 480px) {
            .header {
                padding: 30px 0;
                margin-bottom: 30px;
            }

            .header-title {
                font-size: 1.7rem;
            }

            .header-subtitle {
                font-size: 0.9rem;
            }

            .search-input {
                padding: 15px 50px 15px 45px;
                font-size: 0.9rem;
            }

            .search-icon {
                left: 15px;
                font-size: 1rem;
            }

            .add-bursary-btnn {
                padding: 15px 20px;
                font-size: 0.9rem;
            }

            .notification {
                right: 10px;
                left: 10px;
                max-width: none;
            }

            .notification.show {
                transform: translateX(0);
            }

            .data-table th {
                font-size: 0.8rem;
                padding: 10px 4px;
            }

            .data-table td {
                padding: 8px 4px;
                font-size: 0.8rem;
            }
        }

        /* Print Styles */
        @media print {

            .header,
            .controls-section,
            .table-filters,
            .actions-cell,
            .modal,
            .notification {
                display: none !important;
            }

            .container {
                max-width: none;
                padding: 0;
            }

            .card {
                box-shadow: none;
                border: 1px solid #ccc;
            }

            .data-table {
                font-size: 12px;
            }

            .data-table th,
            .data-table td {
                padding: 6px 4px;
                border: 1px solid #ccc;
            }
        }

        /* High Contrast Mode */
        @media (prefers-contrast: high) {
            :root {
                --border-color: #000000;
                --text-muted: #333333;
                --shadow-light: 0 2px 4px rgba(0, 0, 0, 0.3);
                --shadow-medium: 0 4px 8px rgba(0, 0, 0, 0.4);
                --shadow-heavy: 0 8px 16px rgba(0, 0, 0, 0.5);
            }

            .form-control:focus {
                border-color: #000000;
                box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.3);
            }
        }

        /* Reduced Motion */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }

            .modal-content {
                animation: none;
            }

            .notification {
                transition: none;
            }
        }

        /* Focus Styles for Accessibility */
        .btnn:focus,
        .form-control:focus,
        .filter-select:focus,
        .search-input:focus {
            outline: 2px solid var(--accent-green);
            outline-offset: 2px;
        }

        .modal-close:focus {
            outline: 2px solid var(--white);
            outline-offset: 2px;
        }

        /* Text Selection */
        ::selection {
            background-color: var(--light-green);
            color: var(--white);
        }

        /* Scrollbar Styling */
        .table-wrapper::-webkit-scrollbar {
            height: 8px;
        }

        .table-wrapper::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .table-wrapper::-webkit-scrollbar-thumb {
            background: var(--accent-green);
            border-radius: 4px;
        }

        .table-wrapper::-webkit-scrollbar-thumb:hover {
            background: var(--primary-green);
        }

        .modal-content::-webkit-scrollbar {
            width: 8px;
        }

        .modal-content::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .modal-content::-webkit-scrollbar-thumb {
            background: var(--accent-green);
            border-radius: 4px;
        }

        .modal-content::-webkit-scrollbar-thumb:hover {
            background: var(--primary-green);
        }

        /* Animation for success/error states */
        .form-control.success {
            border-color: var(--success-color);
            animation: successPulse 0.6s ease;
        }

        .form-control.error {
            border-color: var(--error-color);
            animation: errorShake 0.6s ease;
        }

        @keyframes successPulse {
            0% {
                box-shadow: 0 0 0 0 rgba(76, 175, 80, 0.4);
            }

            70% {
                box-shadow: 0 0 0 10px rgba(76, 175, 80, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(76, 175, 80, 0);
            }
        }

        @keyframes errorShake {

            0%,
            20%,
            40%,
            60%,
            80% {
                transform: translateX(0);
            }

            10%,
            30%,
            50%,
            70%,
            90% {
                transform: translateX(-5px);
            }
        }

        /* Utility Classes */
        .hidden {
            display: none !important;
        }

        .visible {
            display: block !important;
        }

        .text-center {
            text-align: center !important;
        }

        .text-left {
            text-align: left !important;
        }

        .text-right {
            text-align: right !important;
        }

        .mt-10 {
            margin-top: 10px !important;
        }

        .mt-20 {
            margin-top: 20px !important;
        }

        .mb-10 {
            margin-bottom: 10px !important;
        }

        .mb-20 {
            margin-bottom: 20px !important;
        }

        .p-10 {
            padding: 10px !important;
        }

        .p-20 {
            padding: 20px !important;
        }

        /* Status Indicators */
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }

        .status-active {
            background-color: var(--success-color);
        }

        .status-pending {
            background-color: var(--warning-color);
        }

        .status-inactive {
            background-color: var(--text-muted);
        }

        /* Currency Formatting */
        .currency {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: var(--primary-green);
        }

        /* Highlight matching search results */
        .highlight {
            background-color: yellow;
            font-weight: bold;
        }

        /* Loading spinner for buttons */
        .btnn.loading {
            position: relative;
            color: transparent;
        }

        .btnn.loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 16px;
            height: 16px;
            margin: -8px 0 0 -8px;
            border: 2px solid currentColor;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 1s linear infinite;
        }

        /* Export buttons section */
        .export-section {
            display: flex;
            gap: 10px;
            margin-left: auto;
        }

        .export-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btnn-success {
            background-color: #28a745;
            color: white;
        }

        .btnn-success:hover {
            background-color: #218838;
            transform: translateY(-2px);
        }

        .btnn-info {
            background-color: #17a2b8;
            color: white;
        }

        .btnn-info:hover {
            background-color: #138496;
            transform: translateY(-2px);
        }

        /* Update controls section to accommodate export buttons */
        .controls-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            gap: 20px;
        }

        /* View details modal */
        .modal-medium {
            max-width: 500px;
        }

        .details-grid {
            display: grid;
            gap: 16px;
        }

        .detail-item {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 12px;
            align-items: start;
        }

        .detail-label {
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }

        .detail-value {
            color: #666;
            font-size: 14px;
            word-wrap: break-word;
        }

        /* View button styling */
        .btn-view {
            background-color: #6c757d;
            color: white;
        }

        .btn-view:hover {
            background-color: #5a6268;
        }

        /* No results message */
        .no-results {
            text-align: center;
            padding: 60px 20px;
            background: #f8f9fa;
            border-radius: 8px;
            margin: 20px 0;
        }

        .no-results-content i {
            font-size: 48px;
            color: #dee2e6;
            margin-bottom: 16px;
        }

        .no-results-content h3 {
            color: #6c757d;
            margin-bottom: 8px;
            font-size: 20px;
        }

        .no-results-content p {
            color: #868e96;
            font-size: 14px;
            max-width: 400px;
            margin: 0 auto;
        }

        /* Search clear button */
        .search-clear {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            transition: background-color 0.2s;
        }

        .search-clear:hover {
            background-color: #f8f9fa;
        }

        /* Update search box to accommodate clear button */
        .search-box {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-input {
            padding-right: 40px;
            /* Make room for clear button */
        }

        /* Action buttons tooltip */
        .btn-action {
            position: relative;
        }

        .btn-action:hover::after {
            content: attr(title);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 1000;
            margin-bottom: 4px;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .controls-section {
                flex-direction: column;
                align-items: stretch;
            }

            .export-section {
                margin-left: 0;
                justify-content: center;
            }

            .detail-item {
                grid-template-columns: 1fr;
                gap: 4px;
            }

            .detail-label {
                font-weight: 600;
                margin-bottom: 0;
            }
        }

        /* Action buttons styling */
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            margin: 0 2px;
            font-size: 14px;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn-action:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-action:active {
            transform: translateY(0);
        }

        /* View button - Blue */
        .btn-view {
            background-color: #3498db;
            color: white;
        }

        .btn-view:hover {
            background-color: #2980b9;
        }

        /* Edit button - Orange */
        .btn-edit {
            background-color: #f39c12;
            color: white;
        }

        .btn-edit:hover {
            background-color: #e67e22;
        }

        /* Delete button - Red */
        .btn-delete {
            background-color: #e74c3c;
            color: white;
        }

        .btn-delete:hover {
            background-color: #c0392b;
        }

        /* Actions column styling */
        .data-table td:last-child {
            text-align: center;
            white-space: nowrap;
            padding: 8px;
        }

        /* Icon styling inside buttons */
        .btn-action i {
            font-size: 13px;
            pointer-events: none;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .btn-action {
                width: 28px;
                height: 28px;
                font-size: 12px;
                margin: 0 1px;
            }

            .btn-action i {
                font-size: 11px;
            }
        }

        .empty-state,
        .no-results {
            display: none;
            text-align: center;
            padding: 60px 20px;
            background: #f8f9fa;
            border-radius: 8px;
            margin: 20px 0;
        }

        .empty-state-content,
        .no-results-content {
            max-width: 400px;
            margin: 0 auto;
        }

        .empty-state i,
        .no-results i {
            font-size: 48px;
            color: #6c757d;
            margin-bottom: 20px;
            display: block;
        }

        .empty-state h3,
        .no-results h3 {
            color: #495057;
            margin-bottom: 10px;
            font-size: 1.5rem;
        }

        .empty-state p,
        .no-results p {
            color: #6c757d;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .empty-state-actions {
            margin-top: 20px;
        }
    </style>
</head>

<body>
    <?php require_once 'nav.php';
    ?>
    <div class="container">
        <header class="header">
            <div class="header-content">
                <h2 class="header-title">School Bursary Records</h2>
                <p class="header-subtitle">Manage and View students' bursaries efficiently</p>
            </div>
        </header>

        <main class="main-content">
            <!-- Search and Add Section -->
            <div class="controls-section">
                <div class="search-container">
                    <div class="search-box">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="searchInput" class="search-input" placeholder="Search students, classes, or bursary records...">
                        <button class="search-clear" id="searchClear" style="display: none;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>

                <div class="export-section">
                    <button class="btnn btnn-success export-btn" id="exportPDF">
                        <i class="fas fa-file-pdf"></i>
                        <span>Export PDF</span>
                    </button>
                    <button class="btnn btnn-info export-btn" id="exportExcel">
                        <i class="fas fa-file-excel"></i>
                        <span>Export Excel</span>
                    </button>
                </div>

                <button class="btnn btnn-primary add-bursary-btnn" id="addBursarybtnn">
                    <i class="fas fa-plus"></i>
                    <span>Add New Bursary</span>
                </button>
            </div>

            <!-- Table Section -->
            <div class="table-section">
                <div class="card">
                    <div class="table-container">
                        <div class="table-filters">
                            <div class="filter-group">
                                <label for="filter_class" class="filter-label">Filter by Class:</label>
                                <select id="filter_class" class="filter-select">
                                    <option value="">All Classes</option>
                                    <option value="Senior One">Senior One</option>
                                    <option value="Senior Two">Senior Two</option>
                                    <option value="Senior Three">Senior Three</option>
                                    <option value="Senior Four">Senior Four</option>
                                    <option value="Senior Five">Senior Five</option>
                                    <option value="Senior Six">Senior Six</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="filter_stream" class="filter-label">Filter by Stream:</label>
                                <select id="filter_stream" class="filter-select">
                                    <option value="">All Streams</option>
                                    <option value="East">East</option>
                                    <option value="West">West</option>
                                    <option value="South">South</option>
                                    <option value="North">North</option>
                                    <option value="Arts">Arts</option>
                                    <option value="Sciences">Sciences</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="filter_year" class="filter-label">Filter by Year:</label>
                                <select id="filter_year" class="filter-select">
                                    <option value="">All Years</option>
                                    <option value="2024">2024</option>
                                    <option value="2025">2025</option>
                                    <option value="2026">2026</option>
                                </select>
                            </div>
                        </div>

                        <div class="table-wrapper">
                            <table class="data-table" id="bursaryTable">
                                <thead>
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Student Name</th>
                                        <th>Class</th>
                                        <th>Stream</th>
                                        <th>Fees Amount</th>
                                        <th>Bursary Discount</th>
                                        <th>Amount to Pay</th>
                                        <th>Year</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="bursaryTableBody">
                                    <!-- Data will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="noResultsMessage" class="no-results" style="display: none;">
        <div class="no-results-content">
            <i class="fas fa-search"></i>
            <h3>No Results Found</h3>
            <p>No bursary records match your search criteria. Try adjusting your filters or search terms.</p>
        </div>
    </div>

    <div id="emptyStateMessage" class="empty-state" style="display: none;">
        <div class="empty-state-content">
            <i class="fas fa-inbox"></i>
            <h3>No Bursary Records Yet</h3>
            <p>You haven't added any bursary records yet. Click the button below to add your first bursary record.</p>
            <div class="empty-state-actions">
                <button class="btnn btnn-primary" id="addFirstBursary">
                    <span>Add First Bursary</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Modal for Add/Edit Bursary -->
    <div id="bursaryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Add New Bursary</h2>
                <button class="modal-close" id="modalClose">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <form id="bursaryForm" class="modal-form" method="POST" action="api/process_bursary.php">
                <input type="hidden" id="bursary_id" name="bursary_id">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="student_name" class="form-label">Student Name *</label>
                        <select id="student_name" name="student_id" class="form-control" required>
                            <option value="">Select a student...</option>
                        </select>
                        <span class="form-error" id="student_name_error"></span>
                    </div>

                    <div class="form-group">
                        <label for="student_id_display" class="form-label">Student ID</label>
                        <input type="text" id="student_id_display" class="form-control" readonly>
                    </div>

                    <div class="form-group">
                        <label for="class_display" class="form-label">Class</label>
                        <input type="text" id="class_display" class="form-control" readonly>
                    </div>

                    <div class="form-group">
                        <label for="stream_display" class="form-label">Stream</label>
                        <input type="text" id="stream_display" class="form-control" readonly>
                    </div>

                    <div class="form-group">
                        <label for="student_type_display" class="form-label">Student Type</label>
                        <input type="text" id="student_type_display" class="form-control" readonly>
                    </div>

                    <div class="form-group">
                        <label for="fees_amount_display" class="form-label">Fees Amount (UGX)</label>
                        <input type="text" id="fees_amount_display" class="form-control" readonly>
                        <input type="hidden" id="fees_amount" name="fees_amount">
                    </div>

                    <div class="form-group">
                        <label for="bursary_discount" class="form-label">Bursary Discount (UGX) *</label>
                        <input type="number" id="bursary_discount" name="bursary_discount" class="form-control"
                            min="0" step="1000" required>
                        <span class="form-error" id="bursary_discount_error"></span>
                    </div>

                    <div class="form-group">
                        <label for="amount_to_pay_display" class="form-label">Amount to Pay (UGX)</label>
                        <input type="text" id="amount_to_pay_display" class="form-control" readonly>
                        <input type="hidden" id="amount_to_pay" name="amount_to_pay">
                    </div>

                    <div class="form-group">
                        <label for="bursary_reason" class="form-label">Reason for Bursary *</label>
                        <textarea id="bursary_reason" name="bursary_reason" class="form-control" rows="3"
                            placeholder="Enter the reason for granting this bursary..." required></textarea>
                        <span class="form-error" id="bursary_reason_error"></span>
                    </div>

                    <div class="form-group">
                        <label for="academic_year" class="form-label">Academic Year *</label>
                        <input type="number" id="academic_year" name="academic_year" class="form-control"
                            min="2020" max="2030" value="2025" required>
                        <span class="form-error" id="academic_year_error"></span>
                    </div>

                    <div class="form-group">
                        <label for="term" class="form-label">Term *</label>
                        <select id="term" name="term" class="form-control" required>
                            <option value="">Select term...</option>
                            <option value="Term One">Term One</option>
                            <option value="Term Two">Term Two</option>
                            <option value="Term Three">Term Three</option>
                        </select>
                        <span class="form-error" id="term_error"></span>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btnn btnn-primary">
                        <i class="fas fa-save"></i>
                        <span class="btnn-text">Save Bursary</span>
                    </button>
                    <button type="button" class="btnn btnn-secondary" id="cancelbtnn">
                        <i class="fas fa-times"></i>
                        <span class="btnn-text">Cancel</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content modal-small">
            <div class="modal-header">
                <h3 class="modal-title">Confirm Delete</h3>
                <button class="modal-close" id="deleteModalClose">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="delete-confirmation">
                    <i class="fas fa-exclamation-triangle warning-icon"></i>
                    <p>Are you sure you want to delete this bursary record?</p>
                    <p class="warning-text">This action cannot be undone.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btnn btnn-danger" id="confirmDelete">
                    <i class="fas fa-trash"></i>
                    <span>Delete</span>
                </button>
                <button class="btnn btnn-secondary" id="cancelDelete">
                    <i class="fas fa-times"></i>
                    <span>Cancel</span>
                </button>
            </div>
        </div>
    </div>

    <!-- 2. ADD this new modal after the existing deleteModal (around line 200) -->
    <div id="viewDetailsModal" class="modal">
        <div class="modal-content modal-medium">
            <div class="modal-header">
                <h3 class="modal-title">Bursary Details</h3>
                <button class="modal-close" id="viewDetailsModalClose">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="details-grid">
                    <div class="detail-item">
                        <label class="detail-label">Student Name:</label>
                        <span class="detail-value" id="detail-student-name"></span>
                    </div>
                    <div class="detail-item">
                        <label class="detail-label">Reason:</label>
                        <span class="detail-value" id="detail-reason"></span>
                    </div>
                    <div class="detail-item">
                        <label class="detail-label">Term:</label>
                        <span class="detail-value" id="detail-term"></span>
                    </div>
                    <div class="detail-item">
                        <label class="detail-label">Date Added:</label>
                        <span class="detail-value" id="detail-date-added"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btnn btnn-secondary" id="closeDetailsModal">
                    <i class="fas fa-times"></i>
                    <span>Close</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Notification System -->
    <div id="notification" class="notification">
        <div class="notification-content">
            <span class="notification-icon"></span>
            <span class="notification-message"></span>
            <button class="notification-close">&times;</button>
        </div>
    </div>

    <script>
        // Global variables and functions
        let allBursaries = [];
        let deleteId = null;

        // Global functions that can be called from onclick events
        function viewBursaryDetails(bursaryId) {
            const bursary = allBursaries.find(b => b.id == bursaryId);
            if (bursary) {
                document.getElementById('detail-student-name').textContent = bursary.student_name;
                document.getElementById('detail-reason').textContent = bursary.bursary_reason;
                document.getElementById('detail-term').textContent = bursary.term;
                document.getElementById('detail-date-added').textContent = formatDate(bursary.created_at);
                document.getElementById('viewDetailsModal').classList.add('show');
            }
        }

        // Updated editBursary function - replace the existing one in your fees_bursaries.php
        function editBursary(bursaryId) {
            const bursary = allBursaries.find(b => b.id == bursaryId);
            if (bursary) {
                // Populate the form with existing data
                document.getElementById('bursary_id').value = bursary.id;

                // Set the student dropdown value
                const studentSelect = document.getElementById('student_name');
                studentSelect.value = bursary.student_id;

                // Trigger the change event to populate other fields
                const changeEvent = new Event('change');
                studentSelect.dispatchEvent(changeEvent);

                // Wait a bit for the change event to complete, then set the remaining fields
                setTimeout(() => {
                    document.getElementById('bursary_discount').value = bursary.bursary_discount;
                    document.getElementById('bursary_reason').value = bursary.bursary_reason;
                    document.getElementById('academic_year').value = bursary.academic_year;
                    document.getElementById('term').value = bursary.term;

                    // Trigger change events for term and year to load correct fees
                    document.getElementById('term').dispatchEvent(new Event('change'));
                    document.getElementById('academic_year').dispatchEvent(new Event('change'));

                    // After fees are loaded, set the discount again and calculate
                    setTimeout(() => {
                        document.getElementById('bursary_discount').value = bursary.bursary_discount;
                        document.getElementById('bursary_discount').dispatchEvent(new Event('input'));
                    }, 500);
                }, 100);

                // Change modal title
                document.getElementById('modalTitle').textContent = 'Edit Bursary Record';

                // Show modal
                document.getElementById('bursaryModal').classList.add('show');
            }
        }

        function deleteBursary(bursaryId) {
            deleteId = bursaryId;
            document.getElementById('deleteModal').classList.add('show');
        }

        // Utility functions
        function formatCurrency(amount) {
            return new Intl.NumberFormat('en-UG', {
                style: 'currency',
                currency: 'UGX',
                minimumFractionDigits: 0
            }).format(amount || 0);
        }

        function formatDate(dateString) {
            return new Date(dateString).toLocaleDateString('en-GB');
        }

        // Notification system
        function showNotification(type, message) {
            const notification = document.getElementById('notification');
            const icon = notification.querySelector('.notification-icon');
            const messageEl = notification.querySelector('.notification-message');

            // Set icon based on type
            const icons = {
                success: 'fas fa-check-circle',
                error: 'fas fa-exclamation-circle',
                warning: 'fas fa-exclamation-triangle',
                info: 'fas fa-info-circle'
            };

            icon.className = `notification-icon ${icons[type] || icons.info}`;
            messageEl.textContent = message;
            notification.className = `notification show ${type}`;

            // Auto-hide after 5 seconds
            setTimeout(() => {
                notification.classList.remove('show');
            }, 5000);
        }

        // DOMContentLoaded event listener
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize the page
            loadStudents();
            loadBursaries();
            initializeFilters();
            initializeExportButtons();
            initializeViewDetailsModal();
            initializeDeleteModal();

            // Form elements
            const bursaryForm = document.getElementById('bursaryForm');
            const studentSelect = document.getElementById('student_name');
            const bursaryDiscountInput = document.getElementById('bursary_discount');
            const termSelect = document.getElementById('term');
            const yearSelect = document.getElementById('academic_year');

            // Load students for dropdown
            function loadStudents() {
                fetch('api/process_bursary.php?action=get_students')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            studentSelect.innerHTML = '<option value="">Select a student...</option>';
                            data.data.forEach(student => {
                                const option = document.createElement('option');
                                option.value = student.student_id;
                                option.textContent = `${student.full_name} (${student.section || 'N/A'})`;
                                option.dataset.class = student.current_class;
                                option.dataset.stream = student.stream;
                                option.dataset.section = student.section; // Add section data
                                studentSelect.appendChild(option);
                            });
                        } else {
                            showNotification('error', data.message);
                        }
                    })
                    .catch(error => {
                        showNotification('error', 'Failed to load students');
                        console.error('Error:', error);
                    });
            }

            // Handle student selection change
            studentSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                if (selectedOption.value) {
                    // Update display fields
                    document.getElementById('student_id_display').value = selectedOption.value;
                    document.getElementById('class_display').value = selectedOption.dataset.class || '';
                    document.getElementById('stream_display').value = selectedOption.dataset.stream || '';

                    // If you added the student type display field, update it here
                    const studentTypeDisplay = document.getElementById('student_type_display');
                    if (studentTypeDisplay) {
                        studentTypeDisplay.value = selectedOption.dataset.section || '';
                    }

                    // Fetch fee details
                    updateFeeAmount();
                } else {
                    // Clear fields
                    document.getElementById('student_id_display').value = '';
                    document.getElementById('class_display').value = '';
                    document.getElementById('stream_display').value = '';
                    document.getElementById('fees_amount_display').value = '';
                    document.getElementById('fees_amount').value = '';

                    const studentTypeDisplay = document.getElementById('student_type_display');
                    if (studentTypeDisplay) {
                        studentTypeDisplay.value = '';
                    }

                    calculateAmountToPay();
                }
            });

            // Handle term and year changes
            termSelect.addEventListener('change', updateFeeAmount);
            yearSelect.addEventListener('change', updateFeeAmount);

            // Handle bursary discount changes
            bursaryDiscountInput.addEventListener('input', calculateAmountToPay);

            // Update fee amount based on student, term, and year
            function updateFeeAmount() {
                const studentId = studentSelect.value;
                const term = termSelect.value;
                const year = yearSelect.value;

                if (studentId && term && year) {
                    const url = `api/process_bursary.php?action=get_student_details&student_id=${studentId}&term=${encodeURIComponent(term)}&year=${year}`;

                    fetch(url)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                const feesAmount = data.data.fees_amount || 0;
                                const studentType = data.data.student_type || 'N/A';

                                document.getElementById('fees_amount_display').value = formatCurrency(feesAmount);
                                document.getElementById('fees_amount').value = feesAmount;

                                // Display student type (Day/Boarding)
                                if (studentType !== 'N/A') {
                                    document.getElementById('fees_amount_display').placeholder = `${studentType} Student`;
                                }

                                calculateAmountToPay();
                            } else {
                                document.getElementById('fees_amount_display').value = '';
                                document.getElementById('fees_amount').value = '';
                                showNotification('warning', 'Fee structure not found for selected student type, class, term, and year');
                                calculateAmountToPay();
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            showNotification('error', 'Failed to fetch fee details');
                        });
                } else {
                    document.getElementById('fees_amount_display').value = '';
                    document.getElementById('fees_amount').value = '';
                    calculateAmountToPay();
                }
            }
            // Calculate amount to pay
            function calculateAmountToPay() {
                const feesAmount = parseInt(document.getElementById('fees_amount').value) || 0;
                const bursaryDiscount = parseInt(bursaryDiscountInput.value) || 0;
                const amountToPay = Math.max(0, feesAmount - bursaryDiscount);

                document.getElementById('amount_to_pay_display').value = formatCurrency(amountToPay);
                document.getElementById('amount_to_pay').value = amountToPay;

                // Validate bursary discount
                if (bursaryDiscount > feesAmount && feesAmount > 0) {
                    document.getElementById('bursary_discount_error').textContent = 'Bursary discount cannot exceed fees amount';
                } else {
                    document.getElementById('bursary_discount_error').textContent = '';
                }
            }

            // Handle form submission
            bursaryForm.addEventListener('submit', function(e) {
                e.preventDefault();

                // Clear previous errors
                document.querySelectorAll('.form-error').forEach(error => error.textContent = '');

                const formData = new FormData(this);
                formData.append('action', 'save');

                fetch('api/process_bursary.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification('success', data.message);
                            document.getElementById('bursaryModal').classList.remove('show');
                            bursaryForm.reset();
                            // Clear display fields
                            document.getElementById('student_id_display').value = '';
                            document.getElementById('class_display').value = '';
                            document.getElementById('stream_display').value = '';
                            document.getElementById('fees_amount_display').value = '';
                            document.getElementById('amount_to_pay_display').value = '';
                            // Add this line if you included the student type display field
                            const studentTypeDisplay = document.getElementById('student_type_display');
                            if (studentTypeDisplay) {
                                studentTypeDisplay.value = '';
                            }
                            // Reset modal title
                            document.getElementById('modalTitle').textContent = 'Add New Bursary';
                            loadBursaries();
                        } else {
                            if (data.data && typeof data.data === 'object') {
                                // Handle field-specific errors
                                Object.keys(data.data).forEach(field => {
                                    const errorElement = document.getElementById(field + '_error');
                                    if (errorElement) {
                                        errorElement.textContent = data.data[field];
                                    }
                                });
                            }
                            showNotification('error', data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('error', 'An error occurred while saving the bursary record');
                    });
            });

            // Load bursary records
            function loadBursaries() {
                fetch('api/process_bursary.php?action=get_bursaries')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            allBursaries = data.data;
                            populateBursaryTable(allBursaries);
                        } else {
                            showNotification('error', data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('error', 'Failed to load bursary records');
                    });
            }

            // Populate bursary table
            function populateBursaryTable(bursaries) {
                const tbody = document.getElementById('bursaryTableBody');
                const noResultsMessage = document.getElementById('noResultsMessage');
                const emptyStateMessage = document.getElementById('emptyStateMessage');

                tbody.innerHTML = '';

                // Hide both messages initially
                noResultsMessage.style.display = 'none';
                emptyStateMessage.style.display = 'none';

                if (bursaries.length === 0) {
                    // Check if this is due to filtering/searching or genuinely empty data
                    const hasActiveFilters = checkForActiveFilters();

                    if (hasActiveFilters) {
                        // Show no results message when filters are active
                        noResultsMessage.style.display = 'block';
                    } else if (allBursaries.length === 0) {
                        // Show empty state when no data exists at all
                        emptyStateMessage.style.display = 'block';
                    } else {
                        // Show no results message for other cases
                        noResultsMessage.style.display = 'block';
                    }
                    return;
                }

                // Populate table with data
                bursaries.forEach(bursary => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
            <td>${bursary.student_id}</td>
            <td>${bursary.student_name}</td>
            <td>${bursary.current_class}</td>
            <td>${bursary.stream}</td>
            <td>${formatCurrency(bursary.fees_amount)}</td>
            <td>${formatCurrency(bursary.bursary_discount)}</td>
            <td>${formatCurrency(bursary.amount_to_pay)}</td>
            <td>${bursary.academic_year}</td>
            <td>
                <button class="btn-action btn-view" onclick="viewBursaryDetails(${bursary.id})" title="View Details">
                    <i class="fas fa-eye"></i>
                </button>
                <button class="btn-action btn-edit" onclick="editBursary(${bursary.id})" title="Edit">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn-action btn-delete" onclick="deleteBursary(${bursary.id})" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;
                    tbody.appendChild(row);
                });
            }

            // Add this new function to check for active filters
            function checkForActiveFilters() {
                const searchTerm = document.getElementById('searchInput').value.trim();
                const classFilter = document.getElementById('filter_class').value;
                const streamFilter = document.getElementById('filter_stream').value;
                const yearFilter = document.getElementById('filter_year').value;

                return searchTerm !== '' || classFilter !== '' || streamFilter !== '' || yearFilter !== '';
            }

            // Add event listener for the "Add First Bursary" button in the DOMContentLoaded section
            document.getElementById('addFirstBursary').addEventListener('click', function() {
                // Hide empty state
                document.getElementById('emptyStateMessage').style.display = 'none';

                // Reset form and modal title for new entry
                const bursaryForm = document.getElementById('bursaryForm');
                bursaryForm.reset();
                document.getElementById('bursary_id').value = '';
                document.getElementById('modalTitle').textContent = 'Add New Bursary';

                // Clear display fields
                document.getElementById('student_id_display').value = '';
                document.getElementById('class_display').value = '';
                document.getElementById('stream_display').value = '';
                document.getElementById('fees_amount_display').value = '';
                document.getElementById('amount_to_pay_display').value = '';
                // Add this line if you included the student type display field
                const studentTypeDisplay = document.getElementById('student_type_display');
                if (studentTypeDisplay) {
                    studentTypeDisplay.value = '';
                }

                // Show modal
                document.getElementById('bursaryModal').classList.add('show');
            });
            // Search and filter functionality
            function initializeFilters() {
                const searchInput = document.getElementById('searchInput');
                const searchClear = document.getElementById('searchClear');
                const filterClass = document.getElementById('filter_class');
                const filterStream = document.getElementById('filter_stream');
                const filterYear = document.getElementById('filter_year');

                // Search functionality
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    searchClear.style.display = searchTerm ? 'block' : 'none';
                    filterAndDisplayBursaries();
                });

                searchClear.addEventListener('click', function() {
                    searchInput.value = '';
                    this.style.display = 'none';
                    filterAndDisplayBursaries();
                });

                // Filter functionality
                filterClass.addEventListener('change', filterAndDisplayBursaries);
                filterStream.addEventListener('change', filterAndDisplayBursaries);
                filterYear.addEventListener('change', filterAndDisplayBursaries);
            }

            function filterAndDisplayBursaries() {
                const searchTerm = document.getElementById('searchInput').value.toLowerCase();
                const classFilter = document.getElementById('filter_class').value;
                const streamFilter = document.getElementById('filter_stream').value;
                const yearFilter = document.getElementById('filter_year').value;

                let filteredBursaries = allBursaries.filter(bursary => {
                    const matchesSearch = searchTerm === '' ||
                        bursary.student_name.toLowerCase().includes(searchTerm) ||
                        bursary.student_id.toLowerCase().includes(searchTerm) ||
                        bursary.current_class.toLowerCase().includes(searchTerm) ||
                        bursary.stream.toLowerCase().includes(searchTerm) ||
                        bursary.bursary_reason.toLowerCase().includes(searchTerm);

                    const matchesClass = classFilter === '' || bursary.current_class === classFilter;
                    const matchesStream = streamFilter === '' || bursary.stream === streamFilter;
                    const matchesYear = yearFilter === '' || bursary.academic_year.toString() === yearFilter;

                    return matchesSearch && matchesClass && matchesStream && matchesYear;
                });

                populateBursaryTable(filteredBursaries);
            }

            // Export to PDF function
            function exportToPDF() {
                const {
                    jsPDF
                } = window.jspdf;
                const doc = new jsPDF();

                // Get current filtered data
                const tableBody = document.getElementById('bursaryTableBody');
                const rows = Array.from(tableBody.querySelectorAll('tr'));

                if (rows.length === 0) {
                    showNotification('warning', 'No data to export');
                    return;
                }

                const tableData = rows.map(row => {
                    const cells = Array.from(row.querySelectorAll('td'));
                    return [
                        cells[0].textContent, // Student ID
                        cells[1].textContent, // Student Name
                        cells[2].textContent, // Class
                        cells[3].textContent, // Stream
                        cells[4].textContent, // Fees Amount
                        cells[5].textContent, // Bursary Discount
                        cells[6].textContent, // Amount to Pay
                        cells[7].textContent // Year
                    ];
                });

                doc.autoTable({
                    head: [
                        ['Student ID', 'Student Name', 'Class', 'Stream', 'Fees Amount', 'Bursary Discount', 'Amount to Pay', 'Year']
                    ],
                    body: tableData,
                    startY: 20,
                    theme: 'striped',
                    headStyles: {
                        fillColor: [41, 128, 185]
                    },
                    styles: {
                        fontSize: 8
                    },
                    columnStyles: {
                        4: {
                            halign: 'right'
                        },
                        5: {
                            halign: 'right'
                        },
                        6: {
                            halign: 'right'
                        }
                    }
                });

                doc.save('bursary_records.pdf');
                showNotification('success', 'PDF exported successfully');
            }

            // Export to Excel function
            function exportToExcel() {
                const tableBody = document.getElementById('bursaryTableBody');
                const rows = Array.from(tableBody.querySelectorAll('tr'));

                if (rows.length === 0) {
                    showNotification('warning', 'No data to export');
                    return;
                }

                const data = rows.map(row => {
                    const cells = Array.from(row.querySelectorAll('td'));
                    return {
                        'Student ID': cells[0].textContent,
                        'Student Name': cells[1].textContent,
                        'Class': cells[2].textContent,
                        'Stream': cells[3].textContent,
                        'Fees Amount': cells[4].textContent.replace(/[^\d]/g, ''),
                        'Bursary Discount': cells[5].textContent.replace(/[^\d]/g, ''),
                        'Amount to Pay': cells[6].textContent.replace(/[^\d]/g, ''),
                        'Year': cells[7].textContent
                    };
                });

                const ws = XLSX.utils.json_to_sheet(data);
                const wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, 'Bursary Records');
                XLSX.writeFile(wb, 'bursary_records.xlsx');
                showNotification('success', 'Excel file exported successfully');
            }

            // Initialize export buttons
            function initializeExportButtons() {
                document.getElementById('exportPDF').addEventListener('click', exportToPDF);
                document.getElementById('exportExcel').addEventListener('click', exportToExcel);
            }

            // Initialize view details modal
            function initializeViewDetailsModal() {
                const viewDetailsModal = document.getElementById('viewDetailsModal');
                const viewDetailsModalClose = document.getElementById('viewDetailsModalClose');
                const closeDetailsModal = document.getElementById('closeDetailsModal');

                viewDetailsModalClose.addEventListener('click', function() {
                    viewDetailsModal.classList.remove('show');
                });

                closeDetailsModal.addEventListener('click', function() {
                    viewDetailsModal.classList.remove('show');
                });

                viewDetailsModal.addEventListener('click', function(e) {
                    if (e.target === viewDetailsModal) {
                        viewDetailsModal.classList.remove('show');
                    }
                });
            }

            // Initialize delete modal
            function initializeDeleteModal() {
                const deleteModal = document.getElementById('deleteModal');
                const deleteModalClose = document.getElementById('deleteModalClose');
                const cancelDelete = document.getElementById('cancelDelete');
                const confirmDelete = document.getElementById('confirmDelete');

                deleteModalClose.addEventListener('click', function() {
                    deleteModal.classList.remove('show');
                    deleteId = null;
                });

                cancelDelete.addEventListener('click', function() {
                    deleteModal.classList.remove('show');
                    deleteId = null;
                });

                confirmDelete.addEventListener('click', function() {
                    if (deleteId) {
                        // Perform delete operation
                        fetch('api/process_bursary.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: `action=delete&id=${deleteId}`
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    showNotification('success', 'Bursary record deleted successfully');
                                    loadBursaries();
                                } else {
                                    showNotification('error', data.message);
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                showNotification('error', 'Failed to delete bursary record');
                            });

                        deleteModal.classList.remove('show');
                        deleteId = null;
                    }
                });

                deleteModal.addEventListener('click', function(e) {
                    if (e.target === deleteModal) {
                        deleteModal.classList.remove('show');
                        deleteId = null;
                    }
                });
            }

            // Close notification
            document.querySelector('.notification-close').addEventListener('click', function() {
                document.getElementById('notification').classList.remove('show');
            });

            // Get DOM elements for modal handling
            const addBursaryBtn = document.getElementById('addBursarybtnn');
            const bursaryModal = document.getElementById('bursaryModal');
            const modalClose = document.getElementById('modalClose');
            const cancelBtn = document.getElementById('cancelbtnn');

            // Open modal when "Add New Bursary" button is clicked
            addBursaryBtn.addEventListener('click', function() {
                // Reset form and modal title for new entry
                bursaryForm.reset();
                document.getElementById('bursary_id').value = '';
                document.getElementById('modalTitle').textContent = 'Add New Bursary';
                // Clear display fields
                document.getElementById('student_id_display').value = '';
                document.getElementById('class_display').value = '';
                document.getElementById('stream_display').value = '';
                document.getElementById('student_type_display').value = ''; // ADD THIS LINE
                document.getElementById('fees_amount_display').value = '';
                document.getElementById('amount_to_pay_display').value = '';
                bursaryModal.classList.add('show');
            });
            // Close modal when close button is clicked
            modalClose.addEventListener('click', function() {
                bursaryModal.classList.remove('show');
            });

            // Close modal when cancel button is clicked
            cancelBtn.addEventListener('click', function() {
                bursaryModal.classList.remove('show');
            });

            // Close modal when clicking outside of it
            bursaryModal.addEventListener('click', function(e) {
                if (e.target === bursaryModal) {
                    bursaryModal.classList.remove('show');
                }
            });
        });
    </script>
</body>

</html>