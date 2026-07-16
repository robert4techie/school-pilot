<?php
require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';

// Check if user has permission to access this page
$allowedRoles = ['super user', 'school leader', 'developer'];
if (!in_array($_SESSION['role'], $allowedRoles)) {
    header("Location: dashboard.php");
    exit();
}

$tracker->trackAction("Staff Email Management");

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Create email_logs table if it doesn't exist
$createTableQuery = "
CREATE TABLE IF NOT EXISTS email_logs (
    log_id INT(11) AUTO_INCREMENT PRIMARY KEY,
    sender_id INT(11) NOT NULL,
    recipient_email VARCHAR(100) NOT NULL,
    recipient_name VARCHAR(100) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('sent', 'failed') NOT NULL,
    error_message TEXT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sender (sender_id),
    INDEX idx_status (status),
    INDEX idx_sent_at (sent_at)
)";
$conn->query($createTableQuery);

// Handle AJAX requests for fetching staff
if (isset($_GET['action']) && $_GET['action'] === 'fetch_staff') {
    header('Content-Type: application/json');

    $gender = isset($_GET['gender']) ? $_GET['gender'] : '';
    $department = isset($_GET['department']) ? $_GET['department'] : '';
    $designation = isset($_GET['designation']) ? $_GET['designation'] : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $employmentType = isset($_GET['employment_type']) ? $_GET['employment_type'] : '';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    $query = "SELECT staff_id, first_name, last_name, email, designation, department, gender, Status, employment_type, profile_photo FROM staff WHERE 1=1";
    $params = [];
    $types = "";

    if (!empty($gender)) {
        $query .= " AND gender = ?";
        $params[] = $gender;
        $types .= "s";
    }

    if (!empty($department)) {
        $query .= " AND department = ?";
        $params[] = $department;
        $types .= "s";
    }

    if (!empty($designation)) {
        $query .= " AND designation = ?";
        $params[] = $designation;
        $types .= "s";
    }

    if (!empty($status)) {
        $query .= " AND Status = ?";
        $params[] = $status;
        $types .= "s";
    }

    if (!empty($employmentType)) {
        $query .= " AND employment_type = ?";
        $params[] = $employmentType;
        $types .= "s";
    }

    if (!empty($search)) {
        $query .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR staff_id LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $types .= "ssss";
    }

    $query .= " ORDER BY first_name, last_name";

    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $staff = [];
    while ($row = $result->fetch_assoc()) {
        $staff[] = $row;
    }

    echo json_encode(['success' => true, 'data' => $staff]);
    exit();
}

// Replace the email sending section (starting around line 131) with this improved version:

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_emails'])) {
    header('Content-Type: application/json');

    try {
        $recipients = isset($_POST['recipients']) ? json_decode($_POST['recipients'], true) : [];
        $subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';
        $message = isset($_POST['message']) ? trim($_POST['message']) : '';

        if (empty($recipients)) {
            echo json_encode(['success' => false, 'message' => 'No recipients selected']);
            exit();
        }

        if (empty($subject)) {
            echo json_encode(['success' => false, 'message' => 'Subject is required']);
            exit();
        }

        if (empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Message is required']);
            exit();
        }

        $conn->begin_transaction();

        $successCount = 0;
        $failCount = 0;
        $errors = [];

        foreach ($recipients as $recipient) {
            $mail = new PHPMailer(true);

            try {
                // SMTP Configuration with improved settings
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = '';
                $mail->Password   = '';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                // Enable verbose debug output (change to 0 in production)
                $mail->SMTPDebug  = 0; // Change this to 2 temporarily to see errors
                $mail->Debugoutput = function ($str, $level) use (&$errors) {
                    $errors[] = "Debug level $level: $str";
                };

                // Additional settings for better reliability
                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                );

                // Set timeout
                $mail->Timeout = 30;

                $mail->setFrom('robertsontumwesige1@gmail.com', 'School Pilot');
                $mail->addAddress($recipient['email'], $recipient['name']);

                // Clear any previous reply-to
                $mail->clearReplyTos();
                $mail->addReplyTo('robertsontumwesige1@gmail.com', 'School Pilot');

                $mail->isHTML(true);
                $mail->CharSet = 'UTF-8';
                $mail->Subject = $subject;
                $mail->Body = "
                    <!DOCTYPE html>
                    <html lang='en'>
                    <head>
                        <meta charset='UTF-8'>
                        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                        <style>
                            body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f4f4; }
                            .container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #dddddd; }
                            .header { background-color: #1e8449; color: #ffffff; padding: 20px; text-align: center; }
                            .header h1 { margin: 0; font-size: 24px; }
                            .content { padding: 30px; line-height: 1.6; color: #333333; }
                            .content p { margin: 0 0 15px; }
                            .message-box { background-color: #f9f9f9; border-left: 4px solid #1e8449; padding: 15px; margin: 20px 0; border-radius: 5px; }
                            .footer { background-color: #f4f4f4; color: #777777; padding: 20px; text-align: center; font-size: 12px; }
                            .footer p { margin: 0 0 5px; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h1>School Pilot</h1>
                            </div>
                            <div class='content'>
                                <p>Hello " . htmlspecialchars($recipient['name']) . ",</p>
                                <div class='message-box'>
                                    " . nl2br(htmlspecialchars($message)) . "
                                </div>
                                <p>Best regards,<br>The School Pilot Team</p>
                            </div>
                            <div class='footer'>
                                <p>&copy; " . date('Y') . " School Pilot. All rights reserved.</p>
                                <p>This email was sent Using school Pilot system .This is an automated message, please do not reply.</p>
                                <p>Visit us https://schoolpilot.org</p>
                                <p>For support, call: <strong>+256 747 170 325 | +256 772 548 084 </strong></p>

                            </div>
                        </div>
                    </body>
                    </html>
                ";

                // Add plain text alternative
                $mail->AltBody = "Hello " . $recipient['name'] . ",\n\n" . strip_tags($message) . "\n\nBest regards,\nThe School Pilot Team";

                // Send email
                $sendResult = $mail->send();

                if ($sendResult) {
                    // Log success
                    $logStmt = $conn->prepare("INSERT INTO email_logs (sender_id, recipient_email, recipient_name, subject, message, status) VALUES (?, ?, ?, ?, ?, 'sent')");
                    $logStmt->bind_param("issss", $_SESSION['user_id'], $recipient['email'], $recipient['name'], $subject, $message);
                    $logStmt->execute();
                    $logStmt->close();

                    $successCount++;
                } else {
                    throw new Exception("Mail send returned false");
                }
            } catch (Exception $e) {
                // Capture detailed error
                $errorMsg = $mail->ErrorInfo;
                $detailedError = "PHPMailer Error: " . $errorMsg;

                // Add exception message if different
                if ($e->getMessage() != $errorMsg) {
                    $detailedError .= " | Exception: " . $e->getMessage();
                }

                // Log failure with detailed error
                $logStmt = $conn->prepare("INSERT INTO email_logs (sender_id, recipient_email, recipient_name, subject, message, status, error_message) VALUES (?, ?, ?, ?, ?, 'failed', ?)");
                $logStmt->bind_param("isssss", $_SESSION['user_id'], $recipient['email'], $recipient['name'], $subject, $message, $detailedError);
                $logStmt->execute();
                $logStmt->close();

                $failCount++;
                $errors[] = "Failed to send to " . $recipient['name'] . " (" . $recipient['email'] . "): " . $detailedError;
            }

            // Clear recipients for next iteration
            $mail->clearAddresses();
            $mail->clearAttachments();
        }

        $conn->commit();

        $responseMessage = "Successfully sent {$successCount} email(s).";
        if ($failCount > 0) {
            $responseMessage .= " {$failCount} failed.";
        }

        echo json_encode([
            'success' => true,
            'message' => $responseMessage,
            'successCount' => $successCount,
            'failCount' => $failCount,
            'errors' => $errors
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }

    exit();
}

// Get filter options
$departments = $conn->query("SELECT DISTINCT department FROM staff ORDER BY department")->fetch_all(MYSQLI_ASSOC);
$designations = $conn->query("SELECT DISTINCT designation FROM staff ORDER BY designation")->fetch_all(MYSQLI_ASSOC);
$statuses = $conn->query("SELECT DISTINCT Status FROM staff ORDER BY Status")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="shortcut icon" href="images/schoolcontrol_icon.png" type="image/x-icon">
    <title>Staff Email Management</title>
    <style>
        :root {
            --primary-color: #2e7d32;
            --primary-light: #4caf50;
            --primary-dark: #1b5e20;
            --secondary-color: #689f38;
            --success-color: #4caf50;
            --danger-color: #f44336;
            --warning-color: #ff9800;
            --info-color: #2196F3;
            --text-color: #333;
            --light-gray: #f5f5f5;
            --medium-gray: #e0e0e0;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            background-color: #f9fafb;
            color: var(--text-color);
            line-height: 1.6;
        }

        .page-container {
            max-width: 100%;
            margin: 0 auto;
            margin-top: 50px;
            padding: 20px;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
            transition: var(--transition);
        }

        .breadcrumb a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .breadcrumb span {
            margin: 0 8px;
            color: #999;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-title i {
            color: var(--primary-color);
        }

        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            margin-bottom: 30px;
            transition: var(--transition);
        }

        .card:hover {
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        .card-header {
            background: var(--primary-color);
            color: white;
            padding: 20px;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-header i {
            margin-right: 10px;
        }

        .card-body {
            padding: 30px;
        }

        /* Filter Section */
        .filter-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 5px;
            color: #555;
        }

        .filter-group select,
        .filter-group input {
            padding: 10px;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
            transition: var(--transition);
        }

        .filter-group select:focus,
        .filter-group input:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        .search-box {
            position: relative;
            grid-column: span 2;
        }

        .search-box input {
            width: 100%;
            padding-left: 40px;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        /* Staff Table */
        .staff-table-container {
            overflow-x: auto;
            margin-top: 20px;
        }

        .staff-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .staff-table thead {
            background: var(--light-gray);
        }

        .staff-table th,
        .staff-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--medium-gray);
        }

        .staff-table th {
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }

        .staff-table tbody tr {
            transition: var(--transition);
        }

        .staff-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .staff-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-light);
        }

        .staff-name {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .staff-info {
            display: flex;
            flex-direction: column;
        }

        .staff-info-name {
            font-weight: 500;
            color: var(--text-color);
        }

        .staff-info-id {
            font-size: 12px;
            color: #999;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-active {
            background-color: #e8f5e9;
            color: #2e7d32;
        }

        .badge-inactive {
            background-color: #ffebee;
            color: #c62828;
        }

        .checkbox-cell {
            text-align: center;
        }

        .checkbox-cell input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* Email Composer */
        .email-composer {
            background: white;
            padding: 20px;
            border-radius: var(--border-radius);
            border: 1px solid var(--medium-gray);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 8px;
            color: #555;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--medium-gray);
            border-radius: var(--border-radius);
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
            transition: var(--transition);
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 200px;
        }

        /* Buttons */
        .btnn {
            font-family: "Quicksand", sans-serif !important;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
        }

        .btnn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btnn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .btnn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btnn-secondary:hover {
            background-color: #5a6268;
            transform: translateY(-2px);
        }

        .btnn-outline {
            background-color: transparent;
            border: 1px solid var(--medium-gray);
            color: #555;
        }

        .btnn-outline:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            transform: translateY(-2px);
        }

        .btnn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btnn-danger:hover {
            background-color: #d32f2f;
            transform: translateY(-2px);
        }

        .btnn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btnn.loading {
            position: relative;
            color: transparent;
            pointer-events: none;
        }

        .btnn.loading::after {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: translate(-50%, -50%) rotate(0deg);
            }

            100% {
                transform: translate(-50%, -50%) rotate(360deg);
            }
        }

        /* Selection Info */
        .selection-info {
            background: #e3f2fd;
            padding: 15px 20px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid var(--info-color);
        }

        .selection-count {
            font-weight: 600;
            color: var(--info-color);
        }

        /* Empty State */
        .empty-state {
            padding: 60px 20px;
            color: #999;
        }

        .empty-state i {
            text-align: center !important;
            font-size: 64px;
            margin-bottom: 20px;
            color: var(--medium-gray);
        }

        .empty-state p {
            text-align: center;
            font-size: 18px;
            margin-bottom: 10px;
        }

        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(5px);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .loading-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .loader {
            width: 50px;
            aspect-ratio: 1;
            display: grid;
            margin-bottom: 20px;
        }

        .loader::before,
        .loader::after {
            content: "";
            grid-area: 1/1;
            --c: no-repeat radial-gradient(farthest-side, #2e7d32 92%, #0000);
            background: var(--c) 50% 0, var(--c) 50% 100%, var(--c) 100% 50%, var(--c) 0 50%;
            background-size: 12px 12px;
            animation: l12 1s infinite;
        }

        .loader::before {
            margin: 4px;
            filter: hue-rotate(15deg);
            background-size: 8px 8px;
            animation-timing-function: linear;
        }

        @keyframes l12 {
            100% {
                transform: rotate(.5turn);
            }
        }

        .loading-text {
            color: var(--primary-color);
            font-size: 16px;
            font-weight: 500;
            text-align: center;
        }

        /* Notification */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: var(--border-radius);
            color: white;
            max-width: 400px;
            z-index: 10000;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: flex-start;
            animation: slideIn 0.3s ease;
            backdrop-filter: blur(5px);
        }

        .notification i {
            margin-right: 10px;
            font-size: 20px;
            flex-shrink: 0;
        }

        .notification.success {
            background-color: rgba(76, 175, 80, 0.95);
            border-left: 5px solid var(--primary-dark);
        }

        .notification.error {
            background-color: rgba(244, 67, 54, 0.95);
            border-left: 5px solid #c62828;
        }

        .notification.warning {
            background-color: rgba(255, 152, 0, 0.95);
            border-left: 5px solid #e65100;
        }

        .notification.info {
            background-color: rgba(33, 150, 243, 0.95);
            border-left: 5px solid #1565c0;
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .notification-message {
            font-size: 14px;
            line-height: 1.4;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes fadeOut {
            from {
                opacity: 1;
            }

            to {
                opacity: 0;
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .search-box {
                grid-column: span 1;
            }

            .staff-table {
                font-size: 12px;
            }

            .staff-table th,
            .staff-table td {
                padding: 8px;
            }

            .selection-info {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .notification {
                max-width: calc(100% - 40px);
            }
        }

        .recipients-preview {
            background: #f8f9fa;
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            max-height: 200px;
            overflow-y: auto;
        }

        .recipients-preview h4 {
            font-size: 14px;
            margin-bottom: 10px;
            color: #555;
        }

        .recipient-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: white;
            padding: 5px 10px;
            border-radius: 15px;
            margin: 5px;
            font-size: 13px;
            border: 1px solid var(--medium-gray);
        }

        .recipient-chip i {
            color: var(--primary-color);
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10001;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .modal-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 90%;
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }

        .modal-overlay.show .modal-container {
            transform: scale(1);
        }

        .modal-header {
            background: var(--primary-color);
            color: white;
            padding: 20px 25px;
            border-radius: 12px 12px 0 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-header i {
            font-size: 24px;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }

        .modal-body {
            padding: 30px 25px;
            text-align: center;
        }

        .modal-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        .modal-icon i {
            font-size: 40px;
            color: var(--primary-color);
        }

        .modal-message {
            font-size: 16px;
            color: var(--text-color);
            margin-bottom: 10px;
            line-height: 1.6;
        }

        .modal-message strong {
            color: var(--primary-color);
            font-weight: 600;
        }

        .modal-submessage {
            font-size: 14px;
            color: #666;
            margin: 0;
        }

        .modal-footer {
            padding: 20px 25px;
            background: #f8f9fa;
            border-radius: 0 0 12px 12px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .modal-footer .btnn {
            min-width: 120px;
        }

        @media (max-width: 768px) {
            .modal-container {
                max-width: 95%;
            }

            .modal-footer {
                flex-direction: column-reverse;
            }

            .modal-footer .btnn {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loader"></div>
        <div class="loading-text">Loading staff data...</div>
    </div>

    <?php require_once 'nav.php' ?>

    <div class="page-container">
        <div class="breadcrumb">
            <a href="dashboard.php">Dashboard</a>
            <span>/</span>
            <span>Communication</span>
            <span>/</span>
            <span>Staff Email Management</span>
        </div>


        <!-- Replace the entire Filter Section card in your PHP file with this -->

        <!-- Filter Section -->
        <div class="card">
            <div class="card-header">
                <div><i class="fas fa-filter"></i> Filter Staff</div>
            </div>
            <div class="card-body">
                <div class="filter-section">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label for="filterGender">Gender</label>
                            <select id="filterGender">
                                <option value="">All Genders</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="filterDepartment">Department</label>
                            <select id="filterDepartment">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept['department']); ?>">
                                        <?php echo htmlspecialchars($dept['department']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="filterDesignation">Designation</label>
                            <select id="filterDesignation">
                                <option value="">All Designations</option>
                                <?php foreach ($designations as $desig): ?>
                                    <option value="<?php echo htmlspecialchars($desig['designation']); ?>">
                                        <?php echo htmlspecialchars($desig['designation']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="filterStatus">Status</label>
                            <select id="filterStatus">
                                <option value="">All Statuses</option>
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo htmlspecialchars($status['Status']); ?>">
                                        <?php echo htmlspecialchars($status['Status']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="filterEmploymentType">Employment Type</label>
                            <select id="filterEmploymentType">
                                <option value="">All Types</option>
                                <option value="full_time">Full Time</option>
                                <option value="part_time">Part Time</option>
                                <option value="contract">Contract</option>
                                <option value="temporary">Temporary</option>
                            </select>
                        </div>

                        <div class="filter-group search-box">
                            <label for="searchInput">Search</label>
                            <input type="text" id="searchInput" placeholder="Search by name, email, or staff ID...">
                        </div>
                    </div>

                    <div class="filter-actions">
                        <button type="button" class="btnn btnn-outline" id="clearFilters">
                            <i class="fas fa-times"></i> Clear All Filters
                        </button>
                    </div>
                </div>

                <div id="selectionInfo" class="selection-info" style="display: none;">
                    <div>
                        <span class="selection-count" id="selectedCount">0</span> staff member(s) selected
                    </div>
                    <div>
                        <button type="button" class="btnn btnn-secondary" id="deselectAll">
                            <i class="fas fa-times-circle"></i> Deselect All
                        </button>
                    </div>
                </div>

                <div class="staff-table-container">
                    <table class="staff-table">
                        <thead>
                            <tr>
                                <th class="checkbox-cell">
                                    <input type="checkbox" id="selectAll" title="Select All">
                                </th>
                                <th>Staff Name</th>
                                <th>Email</th>
                                <th>Designation</th>
                                <th>Department</th>
                                <th>Gender</th>
                                <th>Employment</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="staffTableBody">
                            <tr>
                                <td colspan="8" class="empty-state">
                                    <i class="fas fa-spinner fa-spin"></i>
                                    <p>Loading staff members...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Email Composer Section -->
        <div class="card">
            <div class="card-header">
                <div><i class="fas fa-paper-plane"></i> Compose Email</div>
            </div>
            <div class="card-body">
                <div id="recipientsPreview" class="recipients-preview" style="display: none;">
                    <h4><i class="fas fa-users"></i> Selected Recipients:</h4>
                    <div id="recipientsList"></div>
                </div>

                <form id="emailForm">
                    <div class="form-group">
                        <label for="emailSubject">Subject <span style="color: red;">*</span></label>
                        <input type="text" id="emailSubject" class="form-control" placeholder="Enter email subject..." required>
                    </div>

                    <div class="form-group">
                        <label for="emailMessage">Message <span style="color: red;">*</span></label>
                        <textarea id="emailMessage" class="form-control" placeholder="Type your message here..." required></textarea>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 15px;">
                        <button type="button" class="btnn btnn-outline" id="clearForm">
                            <i class="fas fa-eraser"></i> Clear
                        </button>
                        <button type="submit" class="btnn btnn-primary" id="sendEmailbtnn">
                            <i class="fas fa-paper-plane"></i> Send Email
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- Custom Confirmation Modal -->
    <div class="modal-overlay" id="confirmModal">
        <div class="modal-container">
            <div class="modal-header">
                <i class="fas fa-paper-plane"></i>
                <h3>Confirm Email Send</h3>
            </div>
            <div class="modal-body">
                <div class="modal-icon">
                    <i class="fas fa-envelope-open-text"></i>
                </div>
                <p class="modal-message">You are about to send email to <strong id="recipientCount">0</strong> staff member(s).</p>
                <p class="modal-submessage">This action cannot be undone. Do you want to continue?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btnn btnn-outline" id="cancelSend">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btnn btnn-primary" id="confirmSend">
                    <i class="fas fa-check"></i> Yes, Send Email
                </button>
            </div>
        </div>
    </div>

    <audio id="successSound" preload="auto">
        <source src="sounds/success.mp3" type="audio/mpeg">
    </audio>
    <audio id="errorSound" preload="auto">
        <source src="sounds/error.wav" type="audio/mpeg">
    </audio>

    <script>
        // Replace the existing JavaScript section with this updated version

        let staffData = [];
        let filteredStaffData = [];
        let selectedStaff = new Map();

        // Show/Hide Loading Overlay
        function showLoader(message = 'Processing...') {
            const overlay = document.getElementById('loadingOverlay');
            const loadingText = overlay.querySelector('.loading-text');
            loadingText.textContent = message;
            overlay.classList.add('show');
        }

        function hideLoader() {
            document.getElementById('loadingOverlay').classList.remove('show');
        }

        // Notification System
        function showNotification(title, message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;

            let icon = 'fa-info-circle';
            if (type === 'success') icon = 'fa-check-circle';
            if (type === 'error') icon = 'fa-exclamation-circle';
            if (type === 'warning') icon = 'fa-exclamation-triangle';

            notification.innerHTML = `
        <i class="fas ${icon}"></i>
        <div class="notification-content">
            <div class="notification-title">${title}</div>
            <div class="notification-message">${message}</div>
        </div>
    `;

            document.body.appendChild(notification);

            if (type === 'success') playSuccessSound();
            if (type === 'error') playErrorSound();

            setTimeout(() => {
                notification.style.animation = 'fadeOut 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }, 5000);
        }

        function playSuccessSound() {
            const sound = document.getElementById('successSound');
            if (sound) {
                sound.currentTime = 0;
                sound.play().catch(e => console.log('Audio play failed:', e));
            }
        }

        function playErrorSound() {
            const sound = document.getElementById('errorSound');
            if (sound) {
                sound.currentTime = 0;
                sound.play().catch(e => console.log('Audio play failed:', e));
            }
        }

        // Fetch ALL Staff Data on page load
        async function fetchAllStaff() {
            const params = new URLSearchParams({
                action: 'fetch_staff'
            });

            try {
                showLoader('Loading all staff data...');
                const response = await fetch(`staff_email.php?${params}`);
                const result = await response.json();

                if (result.success) {
                    staffData = result.data;
                    filteredStaffData = [...staffData]; // Initialize filtered data with all staff
                    renderStaffTable();
                    showNotification('Success', `Loaded ${staffData.length} staff member(s)`, 'success');
                } else {
                    showNotification('Error', 'Failed to load staff data', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showNotification('Error', 'An error occurred while loading staff data', 'error');
            } finally {
                hideLoader();
            }
        }

        // Filter staff data based on current filter values
        function applyFilters() {
            const gender = document.getElementById('filterGender').value.toLowerCase();
            const department = document.getElementById('filterDepartment').value;
            const designation = document.getElementById('filterDesignation').value;
            const status = document.getElementById('filterStatus').value;
            const employmentType = document.getElementById('filterEmploymentType').value;
            const search = document.getElementById('searchInput').value.toLowerCase().trim();

            filteredStaffData = staffData.filter(staff => {
                // Gender filter
                if (gender && staff.gender.toLowerCase() !== gender) {
                    return false;
                }

                // Department filter
                if (department && staff.department !== department) {
                    return false;
                }

                // Designation filter
                if (designation && staff.designation !== designation) {
                    return false;
                }

                // Status filter
                if (status && staff.Status !== status) {
                    return false;
                }

                // Employment Type filter
                if (employmentType && staff.employment_type !== employmentType) {
                    return false;
                }

                // Search filter (searches in name, email, and staff_id)
                if (search) {
                    const fullName = `${staff.first_name} ${staff.last_name}`.toLowerCase();
                    const email = staff.email.toLowerCase();
                    const staffId = staff.staff_id.toLowerCase();

                    if (!fullName.includes(search) &&
                        !email.includes(search) &&
                        !staffId.includes(search)) {
                        return false;
                    }
                }

                return true;
            });

            renderStaffTable();
        }

        function renderStaffTable() {
            const tbody = document.getElementById('staffTableBody');

            if (filteredStaffData.length === 0) {
                tbody.innerHTML = `
            <tr>
                <td colspan="8" class="empty-state" style="text-align: center;">
                    <i class="fas fa-users-slash"></i>
                    <p>No staff members found matching your filters</p>
                </td>
            </tr>
        `;
                return;
            }

            tbody.innerHTML = filteredStaffData.map(staff => {
                const isSelected = selectedStaff.has(staff.staff_id);

                return `
            <tr>
                <td class="checkbox-cell">
                    <input type="checkbox" 
                           class="staff-checkbox" 
                           data-staff-id="${staff.staff_id}"
                           data-staff-name="${staff.first_name} ${staff.last_name}"
                           data-staff-email="${staff.email}"
                           ${isSelected ? 'checked' : ''}>
                </td>
                <td>
                    <div class="staff-info">
                        <span class="staff-info-name">${staff.first_name} ${staff.last_name}</span>
                        <span class="staff-info-id">${staff.staff_id}</span>
                    </div>
                </td>
                <td>${staff.email}</td>
                <td>${staff.designation}</td>
                <td>${staff.department}</td>
                <td>${staff.gender.charAt(0).toUpperCase() + staff.gender.slice(1)}</td>
                <td>${staff.employment_type.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}</td>
                <td>
                    <span class="badge ${staff.Status.toLowerCase() === 'active' ? 'badge-active' : 'badge-inactive'}">
                        ${staff.Status}
                    </span>
                </td>
            </tr>
        `;
            }).join('');

            attachCheckboxListeners();
            updateSelectionInfo();
        }

        // Attach Checkbox Listeners
        function attachCheckboxListeners() {
            const checkboxes = document.querySelectorAll('.staff-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const staffId = this.dataset.staffId;
                    const staffName = this.dataset.staffName;
                    const staffEmail = this.dataset.staffEmail;

                    if (this.checked) {
                        selectedStaff.set(staffId, {
                            name: staffName,
                            email: staffEmail
                        });
                    } else {
                        selectedStaff.delete(staffId);
                    }

                    updateSelectionInfo();
                    updateSelectAllCheckbox();
                });
            });
        }

        // Select All Functionality
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.staff-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
                const staffId = checkbox.dataset.staffId;
                const staffName = checkbox.dataset.staffName;
                const staffEmail = checkbox.dataset.staffEmail;

                if (this.checked) {
                    selectedStaff.set(staffId, {
                        name: staffName,
                        email: staffEmail
                    });
                } else {
                    selectedStaff.delete(staffId);
                }
            });

            updateSelectionInfo();
        });

        // Update Select All Checkbox
        function updateSelectAllCheckbox() {
            const selectAllCheckbox = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.staff-checkbox');
            const checkedCount = document.querySelectorAll('.staff-checkbox:checked').length;

            if (checkboxes.length === 0) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = false;
            } else if (checkedCount === checkboxes.length) {
                selectAllCheckbox.checked = true;
                selectAllCheckbox.indeterminate = false;
            } else if (checkedCount > 0) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = true;
            } else {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = false;
            }
        }

        // Update Selection Info
        function updateSelectionInfo() {
            const selectionInfo = document.getElementById('selectionInfo');
            const selectedCount = document.getElementById('selectedCount');
            const recipientsPreview = document.getElementById('recipientsPreview');
            const recipientsList = document.getElementById('recipientsList');

            selectedCount.textContent = selectedStaff.size;

            if (selectedStaff.size > 0) {
                selectionInfo.style.display = 'flex';
                recipientsPreview.style.display = 'block';

                recipientsList.innerHTML = Array.from(selectedStaff.values())
                    .map(staff => `
                <span class="recipient-chip">
                    <i class="fas fa-user"></i>
                    ${staff.name}
                </span>
            `).join('');
            } else {
                selectionInfo.style.display = 'none';
                recipientsPreview.style.display = 'none';
            }
        }

        // Deselect All
        document.getElementById('deselectAll').addEventListener('click', function() {
            selectedStaff.clear();
            const checkboxes = document.querySelectorAll('.staff-checkbox');
            checkboxes.forEach(checkbox => checkbox.checked = false);
            document.getElementById('selectAll').checked = false;
            document.getElementById('selectAll').indeterminate = false;
            updateSelectionInfo();
        });

        // Add event listeners for dynamic filtering
        document.getElementById('filterGender').addEventListener('change', applyFilters);
        document.getElementById('filterDepartment').addEventListener('change', applyFilters);
        document.getElementById('filterDesignation').addEventListener('change', applyFilters);
        document.getElementById('filterStatus').addEventListener('change', applyFilters);
        document.getElementById('filterEmploymentType').addEventListener('change', applyFilters);

        // Live search as user types
        let searchTimeout;
        document.getElementById('searchInput').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                applyFilters();
            }, 300); // Debounce search by 300ms for better performance
        });

        // Clear Filters
        document.getElementById('clearFilters').addEventListener('click', function() {
            document.getElementById('filterGender').value = '';
            document.getElementById('filterDepartment').value = '';
            document.getElementById('filterDesignation').value = '';
            document.getElementById('filterStatus').value = '';
            document.getElementById('filterEmploymentType').value = '';
            document.getElementById('searchInput').value = '';

            // Reset to show all staff
            filteredStaffData = [...staffData];
            renderStaffTable();
        });

        // Clear Form
        document.getElementById('clearForm').addEventListener('click', function() {
            document.getElementById('emailSubject').value = '';
            document.getElementById('emailMessage').value = '';
        });

        // Custom Confirm Modal Functions
        function showConfirmModal(recipientCount) {
            return new Promise((resolve) => {
                const modal = document.getElementById('confirmModal');
                const recipientCountEl = document.getElementById('recipientCount');
                const confirmBtn = document.getElementById('confirmSend');
                const cancelBtn = document.getElementById('cancelSend');

                recipientCountEl.textContent = recipientCount;
                modal.classList.add('show');

                // Handle confirm
                const handleConfirm = () => {
                    modal.classList.remove('show');
                    cleanup();
                    resolve(true);
                };

                // Handle cancel
                const handleCancel = () => {
                    modal.classList.remove('show');
                    cleanup();
                    resolve(false);
                };

                // Handle escape key
                const handleEscape = (e) => {
                    if (e.key === 'Escape') {
                        handleCancel();
                    }
                };

                // Cleanup function
                const cleanup = () => {
                    confirmBtn.removeEventListener('click', handleConfirm);
                    cancelBtn.removeEventListener('click', handleCancel);
                    modal.removeEventListener('click', handleOverlayClick);
                    document.removeEventListener('keydown', handleEscape);
                };

                // Handle clicking outside modal
                const handleOverlayClick = (e) => {
                    if (e.target === modal) {
                        handleCancel();
                    }
                };

                // Attach event listeners
                confirmBtn.addEventListener('click', handleConfirm);
                cancelBtn.addEventListener('click', handleCancel);
                modal.addEventListener('click', handleOverlayClick);
                document.addEventListener('keydown', handleEscape);
            });
        }

        // Updated Send Email Handler
        document.getElementById('emailForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            if (selectedStaff.size === 0) {
                showNotification('No Recipients', 'Please select at least one staff member to send email', 'warning');
                return;
            }

            const subject = document.getElementById('emailSubject').value.trim();
            const message = document.getElementById('emailMessage').value.trim();

            if (!subject) {
                showNotification('Validation Error', 'Subject is required', 'error');
                return;
            }

            if (!message) {
                showNotification('Validation Error', 'Message is required', 'error');
                return;
            }

            const recipients = Array.from(selectedStaff.values());

            // Show custom confirmation modal instead of browser alert
            const confirmed = await showConfirmModal(recipients.length);
            if (!confirmed) return;

            const sendbtnn = document.getElementById('sendEmailbtnn');
            sendbtnn.classList.add('loading');
            sendbtnn.disabled = true;

            try {
                showLoader(`Sending emails to ${recipients.length} recipient(s)...`);

                const formData = new FormData();
                formData.append('send_emails', '1');
                formData.append('recipients', JSON.stringify(recipients));
                formData.append('subject', subject);
                formData.append('message', message);

                const response = await fetch('staff_email.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showNotification('Success', result.message, 'success');

                    // Clear form and selections
                    document.getElementById('emailSubject').value = '';
                    document.getElementById('emailMessage').value = '';
                    selectedStaff.clear();
                    const checkboxes = document.querySelectorAll('.staff-checkbox');
                    checkboxes.forEach(checkbox => checkbox.checked = false);
                    document.getElementById('selectAll').checked = false;
                    updateSelectionInfo();

                    // Show errors if any
                    if (result.errors && result.errors.length > 0) {
                        setTimeout(() => {
                            const errorList = result.errors.join('\n');
                            showNotification('Some Emails Failed', errorList, 'warning');
                        }, 2000);
                    }
                } else {
                    showNotification('Error', result.message, 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showNotification('Error', 'An error occurred while sending emails', 'error');
            } finally {
                hideLoader();
                sendbtnn.classList.remove('loading');
                sendbtnn.disabled = false;
            }
        });
        // Load all staff when page loads
        window.addEventListener('load', () => {
            fetchAllStaff();
        });
    </script>
</body>

</html>

<?php
$conn->close();
?>