<?php
require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/payment_functions.php';
require_once 'tracking.php';
require_once 'includes/security.php';
$tracker->trackAction("Add Fees Payments");


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

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'get_students':
            getStudents($conn);
            break;
        case 'get_student_details':
            getStudentDetails($conn);
            break;
        case 'get_fee_amount':
            getFeeAmount($conn);
            break;
        case 'get_bursary_info':
            getBursaryInfo($conn);
            break;
        case 'get_existing_balance':
            getExistingBalance($conn);
            break;
        case 'save_payment':
            $paymentData = [
                'receipt_number' => $_POST['receipt_number'],
                'student_id' => $_POST['student_id'],
                'student_type' => $_POST['student_type'], // Add this line
                'term' => $_POST['term'],
                'year' => $_POST['year'],
                'fees_amount' => $_POST['fees_amount'],
                'bursary_discount' => $_POST['bursary_discount'],
                'amount_paid' => $_POST['amount_paid'],
                'payment_method' => $_POST['payment_method'],
                'depositor_name' => $_POST['depositor_name'],
                'depositor_contact' => $_POST['depositor_contact'],
                'payment_date' => date('Y-m-d H:i:s'),
                'payment_reference' => $_POST['payment_reference'] ?? null,
                'notes' => $_POST['notes'] ?? null
            ];

            $result = savePaymentWithTransaction($conn, $paymentData);
            jsonResponse($result['success'], $result['message'], $result);
            break;
        case 'get_payments':
            getPayments($conn);
            break;
        case 'delete_payment':
            deletePayment($conn);
            break;
    }
    exit;
}

// Function to get all active students
function getStudents($conn)
{
    $query = "SELECT student_id, CONCAT(first_name, ' ', last_name) as full_name, current_class, stream 
              FROM students WHERE status = 'active' ORDER BY first_name, last_name";
    $result = mysqli_query($conn, $query);

    $students = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $students[] = $row;
    }

    header('Content-Type: application/json');
    echo json_encode($students);
}

/// Function to get student details
function getStudentDetails($conn)
{
    $student_id = mysqli_real_escape_string($conn, $_POST['student_id']);

    $query = "SELECT student_id, CONCAT(first_name, ' ', last_name) as full_name, 
              current_class, stream, section as student_type 
              FROM students WHERE student_id = '$student_id'";
    $result = mysqli_query($conn, $query);

    if ($row = mysqli_fetch_assoc($result)) {
        header('Content-Type: application/json');
        echo json_encode($row);
    } else {
        echo json_encode(['error' => 'Student not found']);
    }
}

// Replace these functions in your add_fees_payments.php file

// Function to get fee amount based on class, student_type, term, and year
function getFeeAmount($conn)
{
    $class = mysqli_real_escape_string($conn, $_POST['class']);
    $student_type = mysqli_real_escape_string($conn, $_POST['student_type']);
    $term = mysqli_real_escape_string($conn, $_POST['term']);
    $year = intval($_POST['year']);

    $query = "SELECT amount FROM fee_structures 
              WHERE class_name = '$class' 
              AND student_type = '$student_type' 
              AND term = '$term' 
              AND year = $year";
    $result = mysqli_query($conn, $query);

    if ($row = mysqli_fetch_assoc($result)) {
        header('Content-Type: application/json');
        echo json_encode(['amount' => $row['amount']]);
    } else {
        echo json_encode([
            'amount' => 0,
            'error' => 'No fee structure found for this combination. Please set up the fee structure first.'
        ]);
    }
}

function getExistingBalance($conn)
{
    $student_id = mysqli_real_escape_string($conn, $_POST['student_id']);
    $term = mysqli_real_escape_string($conn, $_POST['term']);
    $year = intval($_POST['year']);
    $student_type = mysqli_real_escape_string($conn, $_POST['student_type']); // THIS WAS MISSING IN USAGE

    // THIS IS THE KEY FIX - Added student_type to WHERE clause
    $query = "SELECT 
                COALESCE(SUM(amount_paid), 0) as total_paid,
                MAX(amount_to_pay) as amount_to_pay,
                (MAX(amount_to_pay) - COALESCE(SUM(amount_paid), 0)) as current_balance,
                COUNT(*) as payment_count
              FROM fees_payments 
              WHERE student_id = '$student_id' 
              AND term = '$term' 
              AND year = $year
              AND student_type = '$student_type'";  // <-- THIS LINE WAS MISSING THE student_type CONDITION

    $result = mysqli_query($conn, $query);

    if ($row = mysqli_fetch_assoc($result)) {
        header('Content-Type: application/json');
        echo json_encode([
            'total_paid' => (int)$row['total_paid'],
            'amount_to_pay' => (int)($row['amount_to_pay'] ?: 0),
            'current_balance' => (int)($row['current_balance'] ?: 0),
            'payment_count' => (int)$row['payment_count'],
            'has_existing_payments' => $row['payment_count'] > 0
        ]);
    } else {
        echo json_encode([
            'total_paid' => 0,
            'amount_to_pay' => 0,
            'current_balance' => 0,
            'payment_count' => 0,
            'has_existing_payments' => false
        ]);
    }
}

// Function to get bursary information
function getBursaryInfo($conn)
{
    $student_id = mysqli_real_escape_string($conn, $_POST['student_id']);
    $term = mysqli_real_escape_string($conn, $_POST['term']);
    $year = intval($_POST['year']);

    $query = "SELECT bursary_discount FROM fees_bursaries 
              WHERE student_id = '$student_id' AND term = '$term' AND academic_year = $year";
    $result = mysqli_query($conn, $query);

    if ($row = mysqli_fetch_assoc($result)) {
        header('Content-Type: application/json');
        echo json_encode(['bursary_discount' => $row['bursary_discount']]);
    } else {
        echo json_encode(['bursary_discount' => 0]);
    }
}





function getPayments($conn)
{
    $search = isset($_POST['search']) ? mysqli_real_escape_string($conn, $_POST['search']) : '';
    $class_filter = isset($_POST['class_filter']) ? mysqli_real_escape_string($conn, $_POST['class_filter']) : '';
    $stream_filter = isset($_POST['stream_filter']) ? mysqli_real_escape_string($conn, $_POST['stream_filter']) : '';
    $term_filter = isset($_POST['term_filter']) ? mysqli_real_escape_string($conn, $_POST['term_filter']) : '';
    $year_filter = isset($_POST['year_filter']) ? intval($_POST['year_filter']) : 0;
    $status_filter = isset($_POST['status_filter']) ? mysqli_real_escape_string($conn, $_POST['status_filter']) : '';

    $query = "SELECT fp.*, CONCAT(s.first_name, ' ', s.last_name) as student_name, 
              s.current_class, s.stream, s.section as student_type_display
              FROM fees_payments fp
              JOIN students s ON fp.student_id = s.student_id
              WHERE 1=1";

    if (!empty($search)) {
        $query .= " AND (CONCAT(s.first_name, ' ', s.last_name) LIKE '%$search%' 
                    OR fp.receipt_number LIKE '%$search%'
                    OR fp.depositor_name LIKE '%$search%')";
    }

    if (!empty($class_filter)) {
        $query .= " AND s.current_class = '$class_filter'";
    }

    if (!empty($stream_filter)) {
        $query .= " AND s.stream = '$stream_filter'";
    }

    if (!empty($term_filter)) {
        $query .= " AND fp.term = '$term_filter'";
    }

    if ($year_filter > 0) {
        $query .= " AND fp.year = $year_filter";
    }

    if (!empty($status_filter)) {
        $query .= " AND fp.status = '$status_filter'";
    }

    $query .= " ORDER BY fp.payment_date DESC";

    $result = mysqli_query($conn, $query);
    $payments = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $payments[] = $row;
    }

    header('Content-Type: application/json');
    echo json_encode($payments);
}

// Function to delete payment
function deletePayment($conn)
{
    $payment_id = intval($_POST['payment_id']);

    $query = "DELETE FROM fees_payments WHERE id = $payment_id";

    if (mysqli_query($conn, $query)) {
        echo json_encode(['success' => true, 'message' => 'Payment deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error deleting payment']);
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Fees Payment - schoolPilot</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Export Libraries -->
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
            background-color: var(--background);
            color: var(--text-dark);
            line-height: 1.6;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            margin-top: 40px;
            padding: 2rem;
        }

        .header {
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
        }

        .header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .controls-section {
            background: var(--white);
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
        }

        .controls-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .add-payment-btnn {
            font-family: "Sen", sans-serif !important;
            background: linear-gradient(135deg, var(--accent-green), var(--primary-green));
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            box-shadow: var(--shadow);
        }

        .add-payment-btnn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .export-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .export-btnn {
            font-family: "Sen", sans-serif !important;
            background: var(--white);
            border: 2px solid var(--primary-green);
            color: var(--primary-green);
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .export-btnn:hover {
            background: var(--primary-green);
            color: white;
        }

        .filters-row {
            font-family: "Sen", sans-serif !important;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
            gap: 1rem;
            align-items: end;
        }

        .search-group {
            position: relative;
        }

        .search-input {
            font-family: "Sen", sans-serif !important;
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--white);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-500);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-700);
        }

        .filter-select {
            padding: 0.75rem;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            font-size: 1rem;
            background: var(--white);
            transition: all 0.3s ease;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .table-section {
            background: var(--white);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
        }

        .table-header {
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            color: white;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-title {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .table-stats {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .table-container {
            overflow-x: auto;
            max-height: 600px;
            overflow-y: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: var(--gray-100);
            padding: 0.8rem;
            text-align: left;
            font-weight: 600;
            color: var(--gray-700);
            border-bottom: 2px solid var(--gray-200);
            position: sticky;
            top: 0;
            z-index: 1;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
            transition: background-color 0.2s ease;
        }

        tr:hover {
            background-color: var(--gray-100);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-paid {
            background-color: rgba(76, 175, 80, 0.1);
            color: var(--accent-green);
        }

        .status-partial {
            background-color: rgba(255, 193, 7, 0.1);
            color: #f57c00;
        }

        .status-pending {
            background-color: rgba(244, 67, 54, 0.1);
            color: #d32f2f;
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
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background-color: var(--white);
            margin: 2% auto;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 900px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            color: white;
            padding: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
        }

        .close {
            color: white;
            font-size: 2rem;
            font-weight: bold;
            cursor: pointer;
            transition: opacity 0.3s ease;
        }

        .close:hover {
            opacity: 0.7;
        }

        .modal-body {
            padding: 2rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            font-weight: 600;
            color: var(--gray-700);
            font-size: 0.9rem;
        }

        .form-input,
        .form-select {
            padding: 0.875rem;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--white);
        }

        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .form-input:disabled {
            background-color: var(--gray-100);
            color: var(--gray-600);
        }

        .amount-display {
            background: linear-gradient(135deg, var(--light-green), var(--accent-green));
            color: white;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .modal-footer {
            padding: 1.5rem 2rem;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        .btnn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btnn-primary {
            background: linear-gradient(135deg, var(--accent-green), var(--primary-green));
            color: white;
            box-shadow: var(--shadow);
        }

        .btnn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btnn-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .btnn-secondary:hover {
            background: var(--gray-300);
        }

        .no-data {
            text-align: center;
            padding: 3rem;
            color: var(--gray-500);
        }

        .no-data i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .header h2 {
                font-size: 2rem;
            }

            .filters-row {
                grid-template-columns: 1fr;
            }

            .controls-header {
                flex-direction: column;
                align-items: stretch;
            }

            .export-buttons {
                justify-content: center;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }

            th,
            td {
                padding: 0.5rem;
                font-size: 0.875rem;
            }
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
        }

        .action-btn {
            padding: 0.5rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .btn-view {
            background-color: #17a2b8;
            color: white;
        }

        .btn-edit {
            background-color: #ffc107;
            color: #212529;
        }

        .btn-delete {
            background-color: #dc3545;
            color: white;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .detail-label {
            font-weight: 600;
            color: var(--gray-700);
            font-size: 0.875rem;
        }

        .detail-value {
            font-weight: 500;
            color: var(--text-dark);
            padding: 0.5rem;
            background-color: var(--gray-100);
            border-radius: 4px;
        }

        @media (max-width: 768px) {
            .details-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }
        }

        /* Add these styles to your existing <style> section */
        .student-search-wrapper {
            position: relative;
        }

        .student-search-input {
            width: 100%;
            padding: 0.875rem 2.5rem 0.875rem 0.875rem;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--white);
        }

        .student-search-input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .student-search-input::placeholder {
            color: var(--gray-500);
        }

        .student-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 250px;
            overflow-y: auto;
            background: var(--white);
            border: 2px solid var(--primary-green);
            border-top: none;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            display: none;
        }

        .student-dropdown.active {
            display: block;
        }

        .student-dropdown-item {
            padding: 0.875rem;
            cursor: pointer;
            transition: background-color 0.2s ease;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .student-dropdown-item:last-child {
            border-bottom: none;
        }

        .student-dropdown-item:hover {
            background-color: var(--gray-100);
        }

        .student-dropdown-item.selected {
            background-color: rgba(46, 125, 50, 0.1);
            font-weight: 600;
        }

        .student-name {
            color: var(--text-dark);
            font-weight: 500;
        }

        .student-info {
            color: var(--gray-600);
            font-size: 0.875rem;
        }

        .no-results {
            padding: 1rem;
            text-align: center;
            color: var(--gray-500);
            font-style: italic;
        }

        .student-dropdown::-webkit-scrollbar {
            width: 8px;
        }

        .student-dropdown::-webkit-scrollbar-track {
            background: var(--gray-100);
            border-radius: 0 0 8px 0;
        }

        .student-dropdown::-webkit-scrollbar-thumb {
            background: var(--primary-green);
            border-radius: 4px;
        }

        .student-dropdown::-webkit-scrollbar-thumb:hover {
            background: var(--dark-green);
        }

        /* Loading States */
        .btn-loading {
            position: relative;
            pointer-events: none;
            opacity: 0.7;
        }

        .btn-loading::after {
            content: "";
            position: absolute;
            width: 16px;
            height: 16px;
            top: 50%;
            left: 50%;
            margin-left: -8px;
            margin-top: -8px;
            border: 2px solid #ffffff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spinner 0.6s linear infinite;
        }

        @keyframes spinner {
            to {
                transform: rotate(360deg);
            }
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            backdrop-filter: blur(4px);
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid var(--gray-300);
            border-top: 4px solid var(--primary-green);
            border-radius: 50%;
            animation: spinner 0.8s linear infinite;
        }

        .loading-text {
            margin-top: 1rem;
            font-size: 1.1rem;
            color: var(--gray-700);
            font-weight: 500;
        }

        .table-loading {
            text-align: center;
            padding: 3rem;
            color: var(--gray-500);
        }

        .table-loading i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--primary-green);
            animation: spinner 1s linear infinite;
        }

        /* Custom Confirmation Modal */
        .confirm-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            animation: fadeIn 0.3s ease;
        }

        .confirm-modal-content {
            background-color: var(--white);
            margin: 15% auto;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 450px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            animation: slideDown 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .confirm-modal-header {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            padding: 1rem;
            border-radius: 12px 12px 0 0;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .confirm-modal-header i {
            font-size: 2rem;
        }

        .confirm-modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
        }

        .confirm-modal-body {
            padding: 2rem;
            text-align: center;
        }

        .confirm-modal-body p {
            font-size: 1.1rem;
            color: var(--gray-700);
            margin: 0;
            line-height: 1.6;
        }

        .confirm-modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        .btn-confirm {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4);
        }

        .btn-cancel {
            background: var(--gray-200);
            color: var(--gray-700);
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-cancel:hover {
            background: var(--gray-300);
        }

        /* Toast Notifications */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .toast {
            background: white;
            min-width: 300px;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 1rem;
            animation: slideInRight 0.3s ease;
            border-left: 4px solid;
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(100%);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideOutRight {
            from {
                opacity: 1;
                transform: translateX(0);
            }

            to {
                opacity: 0;
                transform: translateX(100%);
            }
        }

        .toast.hide {
            animation: slideOutRight 0.3s ease forwards;
        }

        .toast-success {
            border-left-color: #4caf50;
        }

        .toast-error {
            border-left-color: #dc3545;
        }

        .toast-warning {
            border-left-color: #ffc107;
        }

        .toast-info {
            border-left-color: #17a2b8;
        }

        .toast-icon {
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .toast-success .toast-icon {
            color: #4caf50;
        }

        .toast-error .toast-icon {
            color: #dc3545;
        }

        .toast-warning .toast-icon {
            color: #ffc107;
        }

        .toast-info .toast-icon {
            color: #17a2b8;
        }

        .toast-content {
            flex: 1;
        }

        .toast-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--text-dark);
        }

        .toast-message {
            font-size: 0.9rem;
            color: var(--gray-600);
            margin: 0;
        }

        .toast-close {
            background: none;
            border: none;
            color: var(--gray-500);
            font-size: 1.25rem;
            cursor: pointer;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .toast-close:hover {
            background: var(--gray-200);
            color: var(--text-dark);
        }

        @media (max-width: 768px) {
            .toast-container {
                top: 10px;
                right: 10px;
                left: 10px;
            }

            .toast {
                min-width: auto;
                width: 100%;
            }

            .confirm-modal-content {
                margin: 30% auto;
            }
        }
    </style>
</head>

<body>
    <!-- Loading Overlay -->
    <div id="pageLoadingOverlay" class="loading-overlay" style="display: none;">
        <div class="loading-spinner"></div>
        <div class="loading-text">Loading data...</div>
    </div>
    <?php require_once 'nav.php'; ?>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h2><i class="fas fa-graduation-cap"></i> School Fees Management</h2>
            <p>Manage and track student fee payments efficiently</p>
        </div>

        <!-- Controls Section -->
        <div class="controls-section">
            <div class="controls-header">
                <button class="add-payment-btnn" onclick="openModal()">
                    <i class="fas fa-plus"></i>
                    Add New Payment
                </button>
                <div class="export-buttons">
                    <button class="export-btnn" onclick="exportToPDF()">
                        <i class="fas fa-file-pdf"></i>
                        Export PDF
                    </button>
                    <button class="export-btnn" onclick="exportToExcel()">
                        <i class="fas fa-file-excel"></i>
                        Export Excel
                    </button>
                </div>
            </div>

            <div class="filters-row">
                <div class="search-group">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="search-input" placeholder="Search by student name" id="searchInput">
                </div>
                <div class="filter-group">
                    <label class="filter-label">Class</label>
                    <select class="filter-select" id="classFilter">
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
                    <label class="filter-label">Stream</label>
                    <select class="filter-select" id="streamFilter">
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
                    <label class="filter-label">Term</label>
                    <select class="filter-select" id="termFilter">
                        <option value="">All Terms</option>
                        <option value="Term One">Term One</option>
                        <option value="Term Two">Term Two</option>
                        <option value="Term Three">Term Three</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Year</label>
                    <select class="filter-select" id="yearFilter">
                        <option value="">All Years</option>
                        <option value="2024">2024</option>
                        <option value="2025">2025</option>
                        <option value="2026">2026</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select class="filter-select" id="statusFilter">
                        <option value="">All Status</option>
                        <option value="paid">Paid</option>
                        <option value="partial">Partial</option>
                        <option value="pending">Pending</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Table Section -->
        <div class="table-section">
            <div class="table-header">
                <h2 class="table-title">Payment Records</h2>
                <div class="table-stats">
                    Total Records: <span id="totalRecords">0</span> |
                    Total Amount: UGX <span id="totalAmount">0</span>
                </div>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Class</th>
                            <th>Stream</th>
                            <th>Fees Amount</th>
                            <th>Bursary Discount</th>
                            <th>Amount to Pay</th>
                            <th>Amount Paid</th>
                            <th>Balance</th>
                            <th>Term</th>
                            <th>Year</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="paymentsTableBody">
                        <!-- Data will be inserted here -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Payment Modal -->
        <div id="paymentModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title">Add New Payment</h2>
                    <span class="close" onclick="closeModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <form id="paymentForm">
                        <div class="form-grid">
                            <!-- Replace the existing Student Name form-group with this -->
                            <div class="form-group">
                                <label class="form-label">Student Name *</label>
                                <div class="student-search-wrapper">
                                    <input
                                        type="text"
                                        class="form-input student-search-input"
                                        id="studentSearchInput"
                                        placeholder="Type to search students..."
                                        autocomplete="off">
                                    <select class="form-select" id="studentName" required onchange="loadStudentDetails()" style="display: none;">
                                        <option value="">Select Student</option>
                                    </select>
                                    <div class="student-dropdown" id="studentDropdown"></div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Student ID</label>
                                <input type="text" class="form-input" id="studentId" readonly>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Receipt Number *</label>
                                <input type="text" class="form-input" id="receiptNumber" required readonly>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Class</label>
                                <input type="text" class="form-input" id="studentClass" readonly>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Stream</label>
                                <input type="text" class="form-input" id="studentStream" readonly>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Student Type</label>
                                <input type="text" class="form-input" id="studentType" readonly>
                                <input type="hidden" id="studentTypeHidden" name="student_type">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Term *</label>
                                <select class="form-select" id="paymentTerm" required onchange="updateFeeAmount()">
                                    <option value="">Select Term</option>
                                    <option value="Term One">Term One</option>
                                    <option value="Term Two">Term Two</option>
                                    <option value="Term Three">Term Three</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Year *</label>
                                <select class="form-select" id="paymentYear" required onchange="updateFeeAmount()">
                                    <option value="">Select Year</option>
                                    <option value="2024">2024</option>
                                    <option value="2025">2025</option>
                                    <option value="2026">2026</option>
                                    <option value="2027">2027</option>
                                    <option value="2028">2028</option>
                                    <option value="2029">2029</option>
                                    <option value="2030">2030</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Fees Amount</label>
                                <div class="amount-display" id="feesAmount">UGX 0</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Bursary Discount</label>
                                <div class="amount-display" id="bursaryDiscount">UGX 0</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Amount to be Paid</label>
                                <div class="amount-display" id="amountToBePaid">UGX 0</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Amount Paid *</label>
                                <input type="number" class="form-input" id="amountPaid" required min="0" onchange="calculateBalance()">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Payment Method *</label>
                                <select class="form-select" id="paymentMethod" required>
                                    <option value="">Select Payment Method</option>
                                    <option value="Cash">Cash</option>
                                    <option value="Bank Transfer">Bank Transfer</option>
                                    <option value="Mobile Money">Mobile Money</option>
                                    <option value="Cheque">Cheque</option>
                                    <option value="Online Payment">Online Payment</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Balance</label>
                                <input type="number" class="form-input" id="balance" readonly>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Depositor Name *</label>
                                <input type="text" class="form-input" id="depositorName" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Depositor Contact *</label>
                                <input type="tel" class="form-input" id="depositorContact" required>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btnn btnn-secondary" onclick="closeModal()">
                        <i class="fas fa-times"></i>
                        Cancel
                    </button>
                    <button type="button" class="btnn btnn-primary" onclick="savePayment(event)"> <i class="fas fa-save"></i>
                        Save Payment
                    </button>
                </div>
            </div>
        </div>

        <!-- View Details Modal -->
        <div id="viewDetailsModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title">Payment Details</h2>
                    <span class="close" onclick="closeViewModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <div id="paymentDetails"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btnn btnn-secondary" onclick="closeViewModal()">
                        <i class="fas fa-times"></i>
                        Close
                    </button>
                </div>
            </div>
        </div>

        <!-- Custom Confirmation Modal -->
        <div id="confirmModal" class="confirm-modal">
            <div class="confirm-modal-content">
                <div class="confirm-modal-header">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3 class="confirm-modal-title">Confirm Deletion</h3>
                </div>
                <div class="confirm-modal-body">
                    <p id="confirmMessage">Are you sure you want to delete this payment? This action cannot be undone.</p>
                </div>
                <div class="confirm-modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeConfirmModal()">
                        <i class="fas fa-times"></i>
                        Cancel
                    </button>
                    <button type="button" class="btn-confirm" id="confirmDeleteBtn">
                        <i class="fas fa-trash"></i>
                        Delete
                    </button>
                </div>
            </div>
        </div>

        <!-- Toast Container -->
        <div class="toast-container" id="toastContainer"></div>
    </div>

    <script>
        let currentFeesAmount = 0;
        let currentBursaryDiscount = 0;
        let currentAmountToPay = 0;
        let allStudents = [];

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            loadStudents();
            generateReceiptNumber();
            loadPayments();
            setupEventListeners();
        });

        function setupEventListeners() {
            document.getElementById('searchInput').addEventListener('input', loadPayments);
            document.getElementById('classFilter').addEventListener('change', loadPayments);
            document.getElementById('streamFilter').addEventListener('change', loadPayments);
            document.getElementById('termFilter').addEventListener('change', loadPayments);
            document.getElementById('yearFilter').addEventListener('change', loadPayments);
            document.getElementById('statusFilter').addEventListener('change', loadPayments);
        }
        // Function to populate year dropdowns dynamically
        function populateYearDropdowns() {
            const currentYear = new Date().getFullYear();
            const startYear = currentYear - 2; // 2 years back
            const endYear = currentYear + 5; // 3 years forward

            // Get both year dropdowns
            const paymentYearSelect = document.getElementById('paymentYear');
            const yearFilterSelect = document.getElementById('yearFilter');

            // Clear existing options (except the first placeholder option)
            paymentYearSelect.innerHTML = '<option value="">Select Year</option>';
            yearFilterSelect.innerHTML = '<option value="">All Years</option>';

            // Generate year options
            for (let year = startYear; year <= endYear; year++) {
                // For payment modal dropdown
                const paymentOption = document.createElement('option');
                paymentOption.value = year;
                paymentOption.textContent = year;

                // Set current year as selected in payment modal
                if (year === currentYear) {
                    paymentOption.selected = true;
                }

                paymentYearSelect.appendChild(paymentOption);

                // For filter dropdown
                const filterOption = document.createElement('option');
                filterOption.value = year;
                filterOption.textContent = year;
                yearFilterSelect.appendChild(filterOption);
            }
        }

        // Call the function when the page loads
        document.addEventListener('DOMContentLoaded', function() {
            populateYearDropdowns();

            // Your existing initialization code
            loadStudents();
            generateReceiptNumber();
            loadPayments();
            setupEventListeners();
        });
        // Load students from database
        function loadStudents() {
            // Show page loading overlay
            document.getElementById('pageLoadingOverlay').style.display = 'flex';

            const formData = new FormData();
            formData.append('action', 'get_students');

            fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    allStudents = data;
                    const select = document.getElementById('studentName');
                    select.innerHTML = '<option value="">Select Student</option>';

                    data.forEach(student => {
                        const option = document.createElement('option');
                        option.value = student.student_id;
                        option.textContent = student.full_name;
                        option.dataset.class = student.current_class;
                        option.dataset.stream = student.stream;
                        select.appendChild(option);
                    });

                    // Initialize search functionality
                    initializeStudentSearch();
                })
                .catch(error => {
                    console.error('Error loading students:', error);
                    showToast('Error!', 'Error loading students. Please refresh the page.', 'error');
                })
                .finally(() => {
                    // Hide page loading overlay
                    document.getElementById('pageLoadingOverlay').style.display = 'none';
                });
        }

        // Load student details when selected
        function loadStudentDetails() {
            const studentId = document.getElementById('studentName').value;
            if (!studentId) {
                clearStudentDetails();
                return;
            }

            const formData = new FormData();
            formData.append('action', 'get_student_details');
            formData.append('student_id', studentId);

            fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        showToast('Error!', data.error, 'error');
                        return;
                    }

                    document.getElementById('studentId').value = data.student_id;
                    document.getElementById('studentClass').value = data.current_class;
                    document.getElementById('studentStream').value = data.stream;
                    document.getElementById('studentType').value = data.student_type || 'N/A';
                    document.getElementById('studentTypeHidden').value = data.student_type || '';

                    updateFeeAmount();
                })
                .catch(error => {
                    console.error('Error loading student details:', error);
                    showToast('Error!', 'Failed to load student details', 'error');
                });
        }
        // Update fee amount based on class, student_type, term, and year
        function updateFeeAmount() {
            const studentClass = document.getElementById('studentClass').value;
            const studentType = document.getElementById('studentTypeHidden').value;
            const term = document.getElementById('paymentTerm').value;
            const year = document.getElementById('paymentYear').value;
            const studentId = document.getElementById('studentName').value;

            if (!studentClass || !studentType || !term || !year) {
                clearAmounts();
                return;
            }

            // Get fee amount
            const feeFormData = new FormData();
            feeFormData.append('action', 'get_fee_amount');
            feeFormData.append('class', studentClass);
            feeFormData.append('student_type', studentType);
            feeFormData.append('term', term);
            feeFormData.append('year', year);

            fetch('', {
                    method: 'POST',
                    body: feeFormData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        showToast('Error!', data.error, 'error');
                        currentFeesAmount = 0;
                        document.getElementById('feesAmount').textContent = 'UGX 0';
                        clearAmounts();
                        return;
                    }

                    currentFeesAmount = data.amount;
                    document.getElementById('feesAmount').textContent = `UGX ${currentFeesAmount.toLocaleString()}`;

                    // Get bursary information
                    if (studentId) {
                        getBursaryInfo(studentId, term, year);
                    }
                })
                .catch(error => {
                    console.error('Error loading fee amount:', error);
                    showToast('Error!', 'Failed to fetch fee details', 'error');
                });
        }

        // Get bursary information and existing balance
        function getBursaryInfo(studentId, term, year) {
            const studentType = document.getElementById('studentTypeHidden').value;

            const bursaryFormData = new FormData();
            bursaryFormData.append('action', 'get_bursary_info');
            bursaryFormData.append('student_id', studentId);
            bursaryFormData.append('term', term);
            bursaryFormData.append('year', year);

            fetch('', {
                    method: 'POST',
                    body: bursaryFormData
                })
                .then(response => response.json())
                .then(data => {
                    currentBursaryDiscount = data.bursary_discount;
                    currentAmountToPay = currentFeesAmount - currentBursaryDiscount;

                    document.getElementById('bursaryDiscount').textContent = `UGX ${currentBursaryDiscount.toLocaleString()}`;
                    document.getElementById('amountToBePaid').textContent = `UGX ${currentAmountToPay.toLocaleString()}`;

                    // Get existing balance information
                    getExistingBalance(studentId, term, year, studentType);
                })
                .catch(error => console.error('Error loading bursary info:', error));
        }

        // Get existing balance for the student's term/year
        function getExistingBalance(studentId, term, year, studentType) {
            const balanceFormData = new FormData();
            balanceFormData.append('action', 'get_existing_balance');
            balanceFormData.append('student_id', studentId);
            balanceFormData.append('term', term);
            balanceFormData.append('year', year);
            balanceFormData.append('student_type', studentType);

            fetch('', {
                    method: 'POST',
                    body: balanceFormData
                })
                .then(response => response.json())
                .then(data => {
                    console.log('Existing balance data:', data);

                    if (data.total_paid > 0) {
                        const remainingBalance = currentAmountToPay - data.total_paid;

                        const feesAmountElement = document.getElementById('feesAmount');
                        feesAmountElement.innerHTML = `
                    UGX ${currentFeesAmount.toLocaleString()}<br>
                    <small style="color: #666;">Already Paid: UGX ${data.total_paid.toLocaleString()}</small>
                `;

                        if (remainingBalance > 0) {
                            document.getElementById('amountToBePaid').innerHTML = `
                        UGX ${currentAmountToPay.toLocaleString()}<br>
                        <small style="color: #666;">Remaining: UGX ${remainingBalance.toLocaleString()}</small>
                    `;
                        } else {
                            document.getElementById('amountToBePaid').innerHTML = `
                        UGX ${currentAmountToPay.toLocaleString()}<br>
                        <small style="color: green;">Fully Paid</small>
                    `;
                        }
                    }

                    const amountPaidField = document.getElementById('amountPaid');
                    if (amountPaidField.value) {
                        calculateBalance();
                    }
                })
                .catch(error => console.error('Error loading existing balance:', error));
        }


        // Helper function to update amount display
        function updateAmountDisplay(totalPaidSoFar, newTotalPaid) {
            const remainingAfterCurrentPayment = currentAmountToPay - newTotalPaid;
            const amountToBePayedElement = document.getElementById('amountToBePaid');

            if (totalPaidSoFar > 0) {
                if (remainingAfterCurrentPayment > 0) {
                    amountToBePayedElement.innerHTML = `
                UGX ${currentAmountToPay.toLocaleString()}<br>
                <small style="color: #666;">
                    Already Paid: UGX ${totalPaidSoFar.toLocaleString()}<br>
                    Remaining: UGX ${(currentAmountToPay - totalPaidSoFar).toLocaleString()}
                </small>
            `;
                } else if (remainingAfterCurrentPayment === 0) {
                    amountToBePayedElement.innerHTML = `
                UGX ${currentAmountToPay.toLocaleString()}<br>
                <small style="color: green;">Will be Fully Paid</small>
            `;
                } else {
                    amountToBePayedElement.innerHTML = `
                UGX ${currentAmountToPay.toLocaleString()}<br>
                <small style="color: orange;">Overpayment: UGX ${Math.abs(remainingAfterCurrentPayment).toLocaleString()}</small>
            `;
                }
            } else {
                amountToBePayedElement.textContent = `UGX ${currentAmountToPay.toLocaleString()}`;
            }
        }


        // Calculate balance
        function calculateBalance() {
            const amountPaid = parseInt(document.getElementById('amountPaid').value) || 0;
            const studentId = document.getElementById('studentName').value;
            const term = document.getElementById('paymentTerm').value;
            const year = document.getElementById('paymentYear').value;
            const studentType = document.getElementById('studentTypeHidden').value;

            if (!studentId || !term || !year || !studentType || currentAmountToPay === 0) {
                if (amountPaid > 0 && currentAmountToPay > 0) {
                    const balance = Math.max(0, currentAmountToPay - amountPaid);
                    document.getElementById('balance').value = balance;
                } else {
                    document.getElementById('balance').value = '';
                }
                return;
            }

            const balanceFormData = new FormData();
            balanceFormData.append('action', 'get_existing_balance');
            balanceFormData.append('student_id', studentId);
            balanceFormData.append('term', term);
            balanceFormData.append('year', year);
            balanceFormData.append('student_type', studentType);

            fetch('', {
                    method: 'POST',
                    body: balanceFormData
                })
                .then(response => response.json())
                .then(data => {
                    const totalPaidSoFar = data.total_paid || 0;
                    const newTotalPaid = totalPaidSoFar + amountPaid;
                    const balance = currentAmountToPay - newTotalPaid;

                    document.getElementById('balance').value = balance;
                    updateAmountDisplay(totalPaidSoFar, newTotalPaid);
                })
                .catch(error => {
                    console.error('Error calculating balance:', error);
                    const balance = Math.max(0, currentAmountToPay - amountPaid);
                    document.getElementById('balance').value = balance;
                });
        }
        // Clear student details
        function clearStudentDetails() {
            document.getElementById('studentId').value = '';
            document.getElementById('studentClass').value = '';
            document.getElementById('studentStream').value = '';
            document.getElementById('studentType').value = '';
            document.getElementById('studentTypeHidden').value = '';
            clearAmounts();
        }

        // Clear amounts
        function clearAmounts() {
            document.getElementById('feesAmount').textContent = 'UGX 0';
            document.getElementById('bursaryDiscount').textContent = 'UGX 0';
            document.getElementById('amountToBePaid').textContent = 'UGX 0';
            document.getElementById('balance').value = '';
            currentFeesAmount = 0;
            currentBursaryDiscount = 0;
            currentAmountToPay = 0;
        }

        // Generate receipt number
        function generateReceiptNumber() {
            const timestamp = Date.now();
            const receiptNumber = 'RCP' + timestamp.toString().slice(-6);
            document.getElementById('receiptNumber').value = receiptNumber;
        }

        // Save payment
        function savePayment(event) {
            const form = document.getElementById('paymentForm');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            // Validate that student type exists
            const studentType = document.getElementById('studentTypeHidden').value;
            if (!studentType) {
                showToast('Error!', 'Student type is missing. Please select a student again.', 'error');
                return;
            }

            // Validate that fees amount exists
            if (currentFeesAmount === 0) {
                showToast('Error!', 'No fee structure found for this student type, class, term, and year. Please set up the fee structure first.', 'error');
                return;
            }

            const saveBtn = event.target;
            const originalText = saveBtn.innerHTML;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            saveBtn.classList.add('btn-loading');
            saveBtn.disabled = true;

            const formData = new FormData();
            formData.append('action', 'save_payment');
            formData.append('receipt_number', document.getElementById('receiptNumber').value);
            formData.append('student_id', document.getElementById('studentName').value);
            formData.append('student_type', studentType);
            formData.append('term', document.getElementById('paymentTerm').value);
            formData.append('year', document.getElementById('paymentYear').value);
            formData.append('fees_amount', currentFeesAmount);
            formData.append('bursary_discount', currentBursaryDiscount);
            formData.append('amount_paid', document.getElementById('amountPaid').value);
            formData.append('payment_method', document.getElementById('paymentMethod').value);
            formData.append('depositor_name', document.getElementById('depositorName').value);
            formData.append('depositor_contact', document.getElementById('depositorContact').value);

            fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Payment Saved!', data.message, 'success');
                        closeModal();
                        resetForm();
                        loadPayments();
                    } else {
                        showToast('Error!', data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error saving payment:', error);
                    showToast('Error!', 'Error saving payment. Please try again.', 'error');
                })
                .finally(() => {
                    saveBtn.innerHTML = originalText;
                    saveBtn.classList.remove('btn-loading');
                    saveBtn.disabled = false;
                });
        }
        // Load payments with filters
        function loadPayments() {
            // Show loading state in table
            const tbody = document.getElementById('paymentsTableBody');
            tbody.innerHTML = `
        <tr>
            <td colspan="12" class="table-loading">
                <i class="fas fa-spinner fa-spin"></i>
                <div>Loading payment records...</div>
            </td>
        </tr>
    `;

            const formData = new FormData();
            formData.append('action', 'get_payments');
            formData.append('search', document.getElementById('searchInput').value);
            formData.append('class_filter', document.getElementById('classFilter').value);
            formData.append('stream_filter', document.getElementById('streamFilter').value);
            formData.append('term_filter', document.getElementById('termFilter').value);
            formData.append('year_filter', document.getElementById('yearFilter').value);
            formData.append('status_filter', document.getElementById('statusFilter').value);

            fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    displayPayments(data);
                    updateStats(data);
                })
                .catch(error => {
                    console.error('Error loading payments:', error);
                    tbody.innerHTML =
                        '<tr><td colspan="12" class="no-data"><i class="fas fa-exclamation-circle"></i><br>Error loading payments</td></tr>';
                });
        }
        // Initialize student search functionality
        function initializeStudentSearch() {
            const searchInput = document.getElementById('studentSearchInput');
            const dropdown = document.getElementById('studentDropdown');
            const studentSelect = document.getElementById('studentName');

            // Show dropdown when input is focused
            searchInput.addEventListener('focus', function() {
                if (allStudents.length > 0) {
                    displayStudentDropdown('');
                }
            });

            // Live search as user types
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase().trim();
                displayStudentDropdown(searchTerm);
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function(event) {
                if (!event.target.closest('.student-search-wrapper')) {
                    dropdown.classList.remove('active');
                }
            });
        }

        // Display filtered students in dropdown
        function displayStudentDropdown(searchTerm) {
            const dropdown = document.getElementById('studentDropdown');

            // Filter students based on search term
            const filteredStudents = allStudents.filter(student => {
                const fullName = student.full_name.toLowerCase();
                const studentId = student.student_id.toLowerCase();
                const currentClass = student.current_class.toLowerCase();
                const stream = student.stream.toLowerCase();

                return fullName.includes(searchTerm) ||
                    studentId.includes(searchTerm) ||
                    currentClass.includes(searchTerm) ||
                    stream.includes(searchTerm);
            });

            // Clear existing dropdown content
            dropdown.innerHTML = '';

            if (filteredStudents.length === 0) {
                dropdown.innerHTML = '<div class="no-results">No students found</div>';
                dropdown.classList.add('active');
                return;
            }

            // Create dropdown items
            filteredStudents.forEach(student => {
                const item = document.createElement('div');
                item.className = 'student-dropdown-item';
                item.innerHTML = `
            <span class="student-name">${student.full_name}</span>
            <span class="student-info">${student.current_class} - ${student.stream}</span>
        `;

                // Add click event to select student
                item.addEventListener('click', function() {
                    selectStudent(student);
                });

                dropdown.appendChild(item);
            });

            dropdown.classList.add('active');
        }

        // Select a student from dropdown
        function selectStudent(student) {
            const searchInput = document.getElementById('studentSearchInput');
            const dropdown = document.getElementById('studentDropdown');
            const studentSelect = document.getElementById('studentName');

            // Update search input with selected student name
            searchInput.value = student.full_name;

            // Update hidden select element
            studentSelect.value = student.student_id;

            // Close dropdown
            dropdown.classList.remove('active');

            // Trigger the existing loadStudentDetails function
            loadStudentDetails();
        }

        // Display payments in table
        function displayPayments(payments) {
            const tbody = document.getElementById('paymentsTableBody');

            if (payments.length === 0) {
                tbody.innerHTML = '<tr><td colspan="12" class="no-data">No payment records found</td></tr>';
                return;
            }

            tbody.innerHTML = payments.map(payment => `
                <tr>
                    <td>${payment.student_name}</td>
                    <td>${payment.current_class}</td>
                    <td>${payment.stream}</td>
                    <td>UGX ${parseInt(payment.fees_amount).toLocaleString()}</td>
                    <td>UGX ${parseInt(payment.bursary_discount).toLocaleString()}</td>
                    <td>UGX ${parseInt(payment.amount_to_pay).toLocaleString()}</td>
                    <td>UGX ${parseInt(payment.amount_paid).toLocaleString()}</td>
                    <td>UGX ${parseInt(payment.balance).toLocaleString()}</td>
                    <td>${payment.term}</td>
                    <td>${payment.year}</td>
                    <td><span class="status-badge status-${payment.status}">${payment.status.toUpperCase()}</span></td>
                    <td>
                        <div class="action-buttons">
                            <button class="action-btn btn-view" onclick="viewPaymentDetails(${payment.id})" title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="action-btn btn-receipt" onclick="generateReceipt(${payment.id})" title="Generate Receipt">
                                <i class="fas fa-receipt"></i>
                            </button>
                            <!--<button class="action-btn btn-edit" onclick="editPayment(${payment.id})" title="Edit Payment">
                                <i class="fas fa-edit"></i>
                            </button>-->
                            <button class="action-btn btn-delete" onclick="deletePayment(${payment.id})" title="Delete Payment">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        // Update statistics
        function updateStats(payments) {
            const totalRecords = payments.length;
            const totalAmount = payments.reduce((sum, payment) => sum + parseInt(payment.amount_paid), 0);

            document.getElementById('totalRecords').textContent = totalRecords;
            document.getElementById('totalAmount').textContent = totalAmount.toLocaleString();
        }

        // View payment details
        function viewPaymentDetails(paymentId) {
            // Find payment in current data or fetch from server
            const formData = new FormData();
            formData.append('action', 'get_payments');

            fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    const payment = data.find(p => p.id == paymentId);
                    if (payment) {
                        showPaymentDetails(payment);
                    }
                })
                .catch(error => console.error('Error fetching payment details:', error));
        }

        // Show payment details in modal
        function showPaymentDetails(payment) {
            const detailsHtml = `
                <div class="payment-details">
                    <div class="detail-row">
                        <strong>Receipt Number:</strong> ${payment.receipt_number}
                    </div>
                    <div class="detail-row">
                        <strong>Student Name:</strong> ${payment.student_name}
                    </div>
                    <div class="detail-row">
                        <strong>Student ID:</strong> ${payment.student_id}
                    </div>
                    <div class="detail-row">
                        <strong>Class:</strong> ${payment.current_class}
                    </div>
                    <div class="detail-row">
                        <strong>Stream:</strong> ${payment.stream}
                    </div>
                    <div class="detail-row">
                        <strong>Term:</strong> ${payment.term}
                    </div>
                    <div class="detail-row">
                        <strong>Year:</strong> ${payment.year}
                    </div>
                    <div class="detail-row">
                        <strong>Fees Amount:</strong> UGX ${parseInt(payment.fees_amount).toLocaleString()}
                    </div>
                    <div class="detail-row">
                        <strong>Bursary Discount:</strong> UGX ${parseInt(payment.bursary_discount).toLocaleString()}
                    </div>
                    <div class="detail-row">
                        <strong>Amount to Pay:</strong> UGX ${parseInt(payment.amount_to_pay).toLocaleString()}
                    </div>
                    <div class="detail-row">
                        <strong>Amount Paid:</strong> UGX ${parseInt(payment.amount_paid).toLocaleString()}
                    </div>
                    <div class="detail-row">
                        <strong>Balance:</strong> UGX ${parseInt(payment.balance).toLocaleString()}
                    </div>
                    <div class="detail-row">
                        <strong>Payment Method:</strong> ${payment.payment_method}
                    </div>
                    <div class="detail-row">
                        <strong>Depositor Name:</strong> ${payment.depositor_name}
                    </div>
                    <div class="detail-row">
                        <strong>Depositor Contact:</strong> ${payment.depositor_contact}
                    </div>
                    <div class="detail-row">
                        <strong>Status:</strong> <span class="status-badge status-${payment.status}">${payment.status.toUpperCase()}</span>
                    </div>
                    <div class="detail-row">
                        <strong>Payment Date:</strong> ${new Date(payment.payment_date).toLocaleString()}
                    </div>
                </div>
                <style>
                    .payment-details { margin: 20px 0; }
                    .detail-row { 
                        display: flex; 
                        justify-content: space-between; 
                        padding: 10px 0; 
                        border-bottom: 1px solid #eee; 
                    }
                    .detail-row:last-child { border-bottom: none; }
                </style>
            `;

            document.getElementById('paymentDetails').innerHTML = detailsHtml;
            document.getElementById('viewDetailsModal').style.display = 'block';
        }

        // Generate receipt
        function generateReceipt(paymentId) {
            // Redirect to receipt page with payment ID
            window.open(`api/fees_receipt.php?payment_id=${paymentId}`, '_blank');
        }

        // Edit payment
        function editPayment(paymentId) {
            alert('Payment editing feature will be implemented here');
        }

        // Delete payment - Updated to use custom modal
        function deletePayment(paymentId) {
            showConfirmModal(paymentId);
        }


        // Export functions
        function exportToPDF() {
            const formData = new FormData();
            formData.append('action', 'get_payments');
            formData.append('search', document.getElementById('searchInput').value);
            formData.append('class_filter', document.getElementById('classFilter').value);
            formData.append('stream_filter', document.getElementById('streamFilter').value);
            formData.append('term_filter', document.getElementById('termFilter').value);
            formData.append('year_filter', document.getElementById('yearFilter').value);
            formData.append('status_filter', document.getElementById('statusFilter').value);

            fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.length === 0) {
                        alert('No data to export');
                        return;
                    }

                    const {
                        jsPDF
                    } = window.jspdf;
                    const doc = new jsPDF('l', 'mm', 'a4'); // landscape orientation

                    // Add title
                    doc.setFontSize(18);
                    doc.text('School Fees Payment Report', 14, 22);

                    // Add export date
                    doc.setFontSize(10);
                    doc.text(`Generated on: ${new Date().toLocaleString()}`, 14, 30);

                    // Prepare table data
                    const tableColumns = [
                        'Student Name', 'Class', 'Stream', 'Fees Amount', 'Bursary Discount',
                        'Amount to Pay', 'Amount Paid', 'Balance', 'Term', 'Year', 'Status'
                    ];

                    const tableRows = data.map(payment => [
                        payment.student_name,
                        payment.current_class,
                        payment.stream,
                        `UGX ${parseInt(payment.fees_amount).toLocaleString()}`,
                        `UGX ${parseInt(payment.bursary_discount).toLocaleString()}`,
                        `UGX ${parseInt(payment.amount_to_pay).toLocaleString()}`,
                        `UGX ${parseInt(payment.amount_paid).toLocaleString()}`,
                        `UGX ${parseInt(payment.balance).toLocaleString()}`,
                        payment.term,
                        payment.year,
                        payment.status.toUpperCase()
                    ]);

                    // Add table
                    doc.autoTable({
                        head: [tableColumns],
                        body: tableRows,
                        startY: 35,
                        styles: {
                            fontSize: 8,
                            cellPadding: 2
                        },
                        headStyles: {
                            fillColor: [41, 128, 185],
                            textColor: 255,
                            fontStyle: 'bold'
                        },
                        alternateRowStyles: {
                            fillColor: [245, 245, 245]
                        },
                        columnStyles: {
                            3: {
                                halign: 'right'
                            }, // Fees Amount
                            4: {
                                halign: 'right'
                            }, // Bursary Discount
                            5: {
                                halign: 'right'
                            }, // Amount to Pay
                            6: {
                                halign: 'right'
                            }, // Amount Paid
                            7: {
                                halign: 'right'
                            } // Balance
                        }
                    });

                    // Add summary
                    const totalAmount = data.reduce((sum, payment) => sum + parseInt(payment.amount_paid), 0);
                    const finalY = doc.lastAutoTable.finalY + 10;
                    doc.setFontSize(12);
                    doc.text(`Total Records: ${data.length}`, 14, finalY);
                    doc.text(`Total Amount Collected: UGX ${totalAmount.toLocaleString()}`, 14, finalY + 7);

                    // Save PDF
                    doc.save('fees_payment_report.pdf');
                })
                .catch(error => {
                    console.error('Error exporting to PDF:', error);
                    showToast('Error exporting to PDF. Please try again.');
                });
        }

        function exportToExcel() {
            const formData = new FormData();
            formData.append('action', 'get_payments');
            formData.append('search', document.getElementById('searchInput').value);
            formData.append('class_filter', document.getElementById('classFilter').value);
            formData.append('stream_filter', document.getElementById('streamFilter').value);
            formData.append('term_filter', document.getElementById('termFilter').value);
            formData.append('year_filter', document.getElementById('yearFilter').value);
            formData.append('status_filter', document.getElementById('statusFilter').value);

            fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.length === 0) {
                        alert('No data to export');
                        return;
                    }

                    // Prepare Excel data
                    const excelData = data.map(payment => ({
                        'Student Name': payment.student_name,
                        'Class': payment.current_class,
                        'Stream': payment.stream,
                        'Fees Amount': parseInt(payment.fees_amount),
                        'Bursary Discount': parseInt(payment.bursary_discount),
                        'Amount to Pay': parseInt(payment.amount_to_pay),
                        'Amount Paid': parseInt(payment.amount_paid),
                        'Balance': parseInt(payment.balance),
                        'Term': payment.term,
                        'Year': payment.year,
                        'Status': payment.status.toUpperCase(),
                        'Payment Method': payment.payment_method,
                        'Depositor Name': payment.depositor_name,
                        'Payment Date': new Date(payment.payment_date).toLocaleDateString()
                    }));

                    // Create workbook and worksheet
                    const wb = XLSX.utils.book_new();
                    const ws = XLSX.utils.json_to_sheet(excelData);

                    // Set column widths
                    const colWidths = [{
                            wch: 20
                        }, // Student Name
                        {
                            wch: 12
                        }, // Class
                        {
                            wch: 10
                        }, // Stream
                        {
                            wch: 15
                        }, // Fees Amount
                        {
                            wch: 15
                        }, // Bursary Discount
                        {
                            wch: 15
                        }, // Amount to Pay
                        {
                            wch: 15
                        }, // Amount Paid
                        {
                            wch: 12
                        }, // Balance
                        {
                            wch: 12
                        }, // Term
                        {
                            wch: 8
                        }, // Year
                        {
                            wch: 10
                        }, // Status
                        {
                            wch: 15
                        }, // Payment Method
                        {
                            wch: 20
                        }, // Depositor Name
                        {
                            wch: 15
                        } // Payment Date
                    ];
                    ws['!cols'] = colWidths;

                    // Add worksheet to workbook
                    XLSX.utils.book_append_sheet(wb, ws, 'Fees Payments');

                    // Generate filename with timestamp
                    const timestamp = new Date().toISOString().slice(0, 10);
                    const filename = `fees_payment_report_${timestamp}.xlsx`;

                    // Save file
                    XLSX.writeFile(wb, filename);
                })
                .catch(error => {
                    console.error('Error exporting to Excel:', error);
                    showToast('Error exporting to Excel. Please try again.');
                });
        }
        // Modal functions
        function openModal() {
            document.getElementById('paymentModal').style.display = 'block';
            generateReceiptNumber();
        }

        function closeModal() {
            document.getElementById('paymentModal').style.display = 'none';
        }

        function closeViewModal() {
            document.getElementById('viewDetailsModal').style.display = 'none';
        }

        // Reset form
        function resetForm() {
            document.getElementById('paymentForm').reset();
            document.getElementById('studentSearchInput').value = '';
            document.getElementById('studentDropdown').classList.remove('active');
            document.getElementById('studentTypeHidden').value = ''; // Add this line
            clearStudentDetails();
            generateReceiptNumber();
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const paymentModal = document.getElementById('paymentModal');
            const viewModal = document.getElementById('viewDetailsModal');

            if (event.target === paymentModal) {
                closeModal();
            }
            if (event.target === viewModal) {
                closeViewModal();
            }
        }

        // Toast Notification System
        function showToast(title, message, type = 'success', duration = 4000) {
            const toastContainer = document.getElementById('toastContainer');

            // Create toast element
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;

            // Set icon based on type
            let icon = '';
            switch (type) {
                case 'success':
                    icon = 'fa-check-circle';
                    break;
                case 'error':
                    icon = 'fa-exclamation-circle';
                    break;
                case 'warning':
                    icon = 'fa-exclamation-triangle';
                    break;
                case 'info':
                    icon = 'fa-info-circle';
                    break;
                default:
                    icon = 'fa-check-circle';
            }

            toast.innerHTML = `
        <div class="toast-icon">
            <i class="fas ${icon}"></i>
        </div>
        <div class="toast-content">
            <div class="toast-title">${title}</div>
            <p class="toast-message">${message}</p>
        </div>
        <button class="toast-close" onclick="closeToast(this)">
            <i class="fas fa-times"></i>
        </button>
    `;

            toastContainer.appendChild(toast);

            // Auto remove after duration
            setTimeout(() => {
                closeToast(toast.querySelector('.toast-close'));
            }, duration);
        }

        function closeToast(btn) {
            const toast = btn.closest('.toast');
            toast.classList.add('hide');
            setTimeout(() => {
                toast.remove();
            }, 300);
        }

        // Custom Confirmation Modal
        let deletePaymentId = null;

        function showConfirmModal(paymentId, message = null) {
            deletePaymentId = paymentId;
            const modal = document.getElementById('confirmModal');
            const messageElement = document.getElementById('confirmMessage');

            if (message) {
                messageElement.textContent = message;
            } else {
                messageElement.textContent = 'Are you sure you want to delete this payment? This action cannot be undone.';
            }

            modal.style.display = 'block';

            // Set up the confirm button click handler
            const confirmBtn = document.getElementById('confirmDeleteBtn');
            confirmBtn.onclick = function() {
                confirmDeletePayment();
            };
        }

        function closeConfirmModal() {
            document.getElementById('confirmModal').style.display = 'none';
            deletePaymentId = null;
        }

        function confirmDeletePayment() {
            if (!deletePaymentId) return;

            const confirmBtn = document.getElementById('confirmDeleteBtn');
            const originalText = confirmBtn.innerHTML;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
            confirmBtn.disabled = true;

            const formData = new FormData();
            formData.append('action', 'delete_payment');
            formData.append('payment_id', deletePaymentId);

            fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    closeConfirmModal();
                    if (data.success) {
                        showToast('Success!', data.message, 'success');
                        loadPayments();
                    } else {
                        showToast('Error!', data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error deleting payment:', error);
                    closeConfirmModal();
                    showToast('Error!', 'Error deleting payment. Please try again.', 'error');
                })
                .finally(() => {
                    confirmBtn.innerHTML = originalText;
                    confirmBtn.disabled = false;
                });
        }

        // Close confirm modal when clicking outside
        window.addEventListener('click', function(event) {
            const confirmModal = document.getElementById('confirmModal');
            if (event.target === confirmModal) {
                closeConfirmModal();
            }
        });
    </script>
</body>

</html>