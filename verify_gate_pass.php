<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// IMPORTANT: Load database connection BEFORE checking QR code validity
if (!isset($conn)) {
    require_once 'conn.php';
}

// Check database connection
if (!isset($conn) || !$conn) {
    die('Database connection failed. Please contact support.');
}

// Secret key for gate pass verification
define('GATE_PASS_SECRET_KEY', 'GATEPASS_VERIFICATION_SECRET_2026');

// CHECK IF THIS IS A VALID QR CODE VERIFICATION ACCESS
$is_valid_qr_access = false;
if (isset($_GET['pass_id']) && isset($_GET['token']) && !empty($_GET['pass_id']) && !empty($_GET['token'])) {
    // Quick token validation
    $pass_id = mysqli_real_escape_string($conn, $_GET['pass_id']);
    $token = mysqli_real_escape_string($conn, $_GET['token']);
    
    $quick_check = "SELECT reference_number, issued_at FROM gate_passes WHERE reference_number = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $quick_check);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $pass_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($row = mysqli_fetch_assoc($result)) {
            $expected_token = md5($row['reference_number'] . $row['issued_at'] . GATE_PASS_SECRET_KEY);
            if ($token === $expected_token) {
                $is_valid_qr_access = true;
            }
        }
        mysqli_stmt_close($stmt);
    }
}

// Only require authentication if NOT a valid QR code access
if (!$is_valid_qr_access) {
    require_once 'auth.php';
}


// Sanitization function
function sanitize_input($data)
{
    if ($data === null) return '';
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Get and validate parameters
$pass_id = isset($_GET['pass_id']) ? sanitize_input($_GET['pass_id']) : '';
$verification_token = isset($_GET['token']) ? sanitize_input($_GET['token']) : '';

// Basic validation
$errors = [];
if (empty($pass_id)) $errors[] = "Gate Pass ID is required";
if (empty($verification_token)) $errors[] = "Verification token is required";

if (!empty($errors)) {
    display_error_page("Invalid Request", $errors);
    exit;
}

// Get school profile
$school_info = null;
$school_query = "SELECT * FROM school_profile LIMIT 1";
$school_result = mysqli_query($conn, $school_query);
if ($school_result) {
    $school_info = mysqli_fetch_assoc($school_result);
}

// Get gate pass information
$pass_sql = "SELECT * FROM gate_passes WHERE reference_number = ?";
$stmt = mysqli_prepare($conn, $pass_sql);

if (!$stmt) {
    display_error_page("Database Error", ["Unable to prepare query"]);
    exit;
}

mysqli_stmt_bind_param($stmt, "s", $pass_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$gate_pass = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$gate_pass) {
    display_not_found_page($pass_id);
    exit;
}

// Verify the token
$expected_token = md5($gate_pass['reference_number'] . $gate_pass['issued_at'] . GATE_PASS_SECRET_KEY);

if ($verification_token !== $expected_token) {
    display_invalid_token_page($pass_id);
    exit;
}

// Get student information
$student = null;
if (!empty($gate_pass['student_id'])) {
    $student_sql = "SELECT * FROM students WHERE student_id = ?";
    $student_stmt = mysqli_prepare($conn, $student_sql);

    if ($student_stmt) {
        mysqli_stmt_bind_param($student_stmt, "s", $gate_pass['student_id']);
        mysqli_stmt_execute($student_stmt);
        $student_result = mysqli_stmt_get_result($student_stmt);
        $student = mysqli_fetch_assoc($student_result);
        mysqli_stmt_close($student_stmt);
    }
}

// Display the verification page
display_verification_page($gate_pass, $student, $school_info);

mysqli_close($conn);

// ==================== DISPLAY FUNCTIONS ====================

function display_verification_page($gate_pass, $student, $school_info)
{
    $pass_id = htmlspecialchars($gate_pass['reference_number']);
    $student_name = htmlspecialchars($gate_pass['student_name']);
    $student_id = htmlspecialchars($gate_pass['student_id']);
    $class = htmlspecialchars($gate_pass['class']);
    $stream = htmlspecialchars($gate_pass['stream']);
    $status = htmlspecialchars($gate_pass['status']);
    $destination = htmlspecialchars($gate_pass['destination']);
    $reason = htmlspecialchars($gate_pass['reason']);
    $departure_time = date('M d, Y g:i A', strtotime($gate_pass['departure_time']));
    $expected_return = date('M d, Y g:i A', strtotime($gate_pass['expected_return']));
    $issued_by = htmlspecialchars($gate_pass['issued_by']);
    $issued_at = date('M d, Y g:i A', strtotime($gate_pass['issued_at']));
    $priority = htmlspecialchars($gate_pass['priority']);

    $parent_contact = $gate_pass['parent_contact'] ? htmlspecialchars($gate_pass['parent_contact']) : 'N/A';
    $student_contact = $gate_pass['student_contact'] ? htmlspecialchars($gate_pass['student_contact']) : 'N/A';
    $accompanying_person = $gate_pass['accompanying_person'] ? htmlspecialchars($gate_pass['accompanying_person']) : 'N/A';

    // Get student photo
    $photo = 'images/avatar.png';
    if ($student && !empty($student['profile_photo'])) {
        $photo = htmlspecialchars($student['profile_photo']);
    }

    $school_name = isset($school_info['school_name']) ? htmlspecialchars($school_info['school_name']) : 'School Name';
    $school_logo = isset($school_info['logo_path']) ? htmlspecialchars($school_info['logo_path']) : 'assets/img/logo.jpg';

    $verification_date = date('l, F j, Y \a\t g:i A');
    $verification_id = 'VRF-GP-' . strtoupper(substr(md5($pass_id . time()), 0, 10));
    $verification_token = md5($gate_pass['reference_number'] . $gate_pass['issued_at'] . GATE_PASS_SECRET_KEY);

    // Determine status message and styling
    $status_class = 'success';
    $status_icon = 'check-circle';
    $status_title = 'Valid Gate Pass Verified';
    $status_message = 'This gate pass has been successfully verified against official school records and is confirmed to be authentic.';

    if ($status === 'cancelled') {
        $status_class = 'danger';
        $status_icon = 'times-circle';
        $status_title = 'Cancelled Gate Pass';
        $status_message = 'This gate pass has been cancelled and is no longer valid for use.';
    } elseif ($status === 'overdue') {
        $status_class = 'warning';
        $status_icon = 'exclamation-triangle';
        $status_title = 'Overdue Gate Pass';
        $status_message = 'This gate pass is overdue. The student should have returned by the expected return time.';
    } elseif ($status === 'returned') {
        $status_class = 'info';
        $status_icon = 'check-circle';
        $status_title = 'Returned Gate Pass';
        $status_message = 'This gate pass has been marked as returned. The student has safely returned to school.';
    }

    // Priority badge styling
    $priority_class = 'normal';
    $priority_icon = 'info-circle';
    if ($priority === 'urgent') {
        $priority_class = 'urgent';
        $priority_icon = 'exclamation-circle';
    } elseif ($priority === 'emergency') {
        $priority_class = 'emergency';
        $priority_icon = 'exclamation-triangle';
    }
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Gate Pass Verification - <?php echo $pass_id; ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            :root {
                --primary-color: #1e8449;
                --primary-light: #27ae60;
                --primary-dark: #145a32;
                --secondary-color: #52be80;
                --success-color: #4caf50;
                --danger-color: #f44336;
                --warning-color: #ff9800;
                --info-color: #2196f3;
                --text-dark: #1a1a1a;
                --text-medium: #555555;
                --text-light: #777777;
                --border-color: #e0e0e0;
                --bg-light: #f8f9fa;
                --white: #ffffff;
                --shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.08);
                --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.12);
                --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.15);
            }

            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: "Sen", sans-serif;
                min-height: 100vh;
                background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                padding: 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: var(--text-dark);
                line-height: 1.6;
            }

            .verification-container {
                max-width: 950px;
                width: 100%;
                background: var(--white);
                border-radius: 16px;
                box-shadow: var(--shadow-lg);
                overflow: hidden;
                animation: slideUp 0.6s ease;
            }

            @keyframes slideUp {
                from {
                    opacity: 0;
                    transform: translateY(30px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            /* Header Section */
            .header {
                background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
                color: var(--white);
                padding: 40px 30px;
                text-align: center;
                position: relative;
                overflow: hidden;
            }

            .header::before {
                content: '';
                position: absolute;
                top: -50%;
                right: -50%;
                width: 200%;
                height: 200%;
                background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
                animation: pulse 3s ease-in-out infinite;
            }

            @keyframes pulse {

                0%,
                100% {
                    transform: scale(1);
                    opacity: 0.5;
                }

                50% {
                    transform: scale(1.1);
                    opacity: 0.8;
                }
            }

            .header-content {
                position: relative;
                z-index: 1;
            }

            .school-logo-container {
                width: 90px;
                height: 90px;
                background: var(--white);
                padding: 12px;
                border-radius: 50%;
                margin: 0 auto 20px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .school-logo {
                width: 100%;
                height: 100%;
                object-fit: contain;
            }

            .school-name {
                font-size: 26px;
                font-weight: 700;
                margin-bottom: 15px;
                text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            }

            .verification-badge {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                background: rgba(255, 255, 255, 0.2);
                backdrop-filter: blur(10px);
                padding: 12px 24px;
                border-radius: 50px;
                margin-top: 10px;
                font-size: 14px;
                font-weight: 600;
                border: 2px solid rgba(255, 255, 255, 0.3);
            }

            .verification-badge i {
                font-size: 20px;
            }

            /* Content Section */
            .content {
                padding: 40px 30px;
            }

            /* Status Banner */
            .status-banner {
                display: flex;
                align-items: flex-start;
                gap: 20px;
                padding: 24px;
                border-radius: 12px;
                margin-bottom: 30px;
                border: 2px solid;
                animation: fadeIn 0.8s ease 0.3s both;
            }

            @keyframes fadeIn {
                from {
                    opacity: 0;
                    transform: translateY(10px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .status-banner.success {
                background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 50%);
                border-color: var(--success-color);
            }

            .status-banner.warning {
                background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 50%);
                border-color: var(--warning-color);
            }

            .status-banner.danger {
                background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 50%);
                border-color: var(--danger-color);
            }

            .status-banner.info {
                background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 50%);
                border-color: var(--info-color);
            }

            .status-icon-container {
                width: 50px;
                height: 50px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
                color: var(--white);
            }

            .status-banner.success .status-icon-container {
                background: var(--success-color);
            }

            .status-banner.warning .status-icon-container {
                background: var(--warning-color);
            }

            .status-banner.danger .status-icon-container {
                background: var(--danger-color);
            }

            .status-banner.info .status-icon-container {
                background: var(--info-color);
            }

            .status-icon-container i {
                font-size: 24px;
            }

            .status-content h3 {
                font-size: 18px;
                font-weight: 700;
                margin-bottom: 5px;
                color: var(--text-dark);
            }

            .status-content p {
                font-size: 14px;
                color: var(--text-medium);
                line-height: 1.5;
            }

            /* Student Card */
            .student-card {
                background: var(--bg-light);
                border-radius: 12px;
                padding: 30px;
                margin-bottom: 30px;
                border: 1px solid var(--border-color);
                animation: fadeIn 0.8s ease 0.4s both;
            }

            .student-header {
                display: flex;
                align-items: center;
                gap: 25px;
                margin-bottom: 25px;
                padding-bottom: 25px;
                border-bottom: 2px solid var(--border-color);
            }

            .student-photo-container {
                position: relative;
            }

            .student-photo {
                width: 110px;
                height: 110px;
                border-radius: 50%;
                object-fit: cover;
                border: 4px solid var(--primary-color);
                box-shadow: var(--shadow-md);
            }

            .verification-icon {
                position: absolute;
                bottom: 5px;
                right: 5px;
                width: 28px;
                height: 28px;
                background: var(--success-color);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                border: 3px solid var(--white);
            }

            .verification-icon i {
                font-size: 12px;
                color: var(--white);
            }

            .student-basic-info h2 {
                font-size: 26px;
                color: var(--text-dark);
                margin-bottom: 8px;
                font-weight: 700;
            }

            .student-id-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                background: var(--white);
                padding: 6px 12px;
                border-radius: 6px;
                font-size: 13px;
                font-weight: 600;
                color: var(--primary-color);
                border: 1px solid var(--primary-light);
            }

            .student-id-badge i {
                font-size: 12px;
            }

            .info-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
            }

            .info-item {
                background: var(--white);
                padding: 16px;
                border-radius: 10px;
                border-left: 4px solid var(--primary-color);
                box-shadow: var(--shadow-sm);
                transition: transform 0.3s ease, box-shadow 0.3s ease;
            }

            .info-item:hover {
                transform: translateY(-2px);
                box-shadow: var(--shadow-md);
            }

            .info-label {
                font-size: 11px;
                color: var(--primary-dark);
                font-weight: 700;
                text-transform: uppercase;
                margin-bottom: 6px;
                letter-spacing: 0.5px;
                display: flex;
                align-items: center;
                gap: 6px;
            }

            .info-label i {
                font-size: 10px;
            }

            .info-value {
                font-size: 16px;
                color: var(--text-dark);
                font-weight: 600;
            }

            /* Priority Badge */
            .priority-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 4px 10px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 700;
                text-transform: uppercase;
            }

            .priority-badge.normal {
                background: #e3f2fd;
                color: #1976d2;
            }

            .priority-badge.urgent {
                background: #fff3e0;
                color: #f57c00;
            }

            .priority-badge.emergency {
                background: #ffebee;
                color: #c62828;
            }

            /* Pass Details */
            .pass-details {
                background: linear-gradient(135deg, #e8f5e9 0%, #f1f8e9 100%);
                border-radius: 12px;
                padding: 30px;
                margin-bottom: 30px;
                border: 1px solid var(--primary-light);
                animation: fadeIn 0.8s ease 0.5s both;
            }

            .pass-details h3 {
                color: var(--primary-dark);
                font-size: 20px;
                margin-bottom: 20px;
                font-weight: 700;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .pass-details h3 i {
                color: var(--primary-color);
            }

            .detail-row {
                display: flex;
                padding: 14px 0;
                border-bottom: 1px solid rgba(0, 0, 0, 0.08);
                transition: background 0.3s ease;
            }

            .detail-row:hover {
                background: rgba(255, 255, 255, 0.5);
                padding-left: 10px;
                margin-left: -10px;
                border-radius: 6px;
            }

            .detail-row:last-child {
                border-bottom: none;
            }

            .detail-label {
                font-weight: 700;
                color: var(--primary-dark);
                min-width: 180px;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .detail-label i {
                font-size: 14px;
                color: var(--primary-color);
            }

            .detail-value {
                color: var(--text-dark);
                font-weight: 600;
            }

            .detail-row.full-width {
                flex-direction: column;
                gap: 8px;
            }

            .detail-row.full-width .detail-value {
                padding: 12px;
                background: rgba(255, 255, 255, 0.7);
                border-radius: 6px;
                border-left: 3px solid var(--primary-color);
            }

            /* Action Buttons */
            .action-buttons {
                display: flex;
                gap: 15px;
                justify-content: center;
                flex-wrap: wrap;
                animation: fadeIn 0.8s ease 0.6s both;
            }

            .btn {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                padding: 14px 28px;
                border: none;
                border-radius: 8px;
                font-size: 15px;
                font-weight: 600;
                cursor: pointer;
                text-decoration: none;
                transition: all 0.3s ease;
                font-family: "Sen", sans-serif;
                box-shadow: var(--shadow-sm);
            }

            .btn-primary {
                background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
                color: var(--white);
            }

            .btn-primary:hover {
                transform: translateY(-2px);
                box-shadow: var(--shadow-md);
                background: linear-gradient(135deg, var(--primary-dark) 0%, #0d3d10 100%);
            }

            .btn-secondary {
                background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
                color: var(--white);
            }

            .btn-secondary:hover {
                transform: translateY(-2px);
                box-shadow: var(--shadow-md);
                background: linear-gradient(135deg, #5a6268 0%, #4a5055 100%);
            }

            .btn:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }

            .btn:disabled:hover {
                transform: none;
                box-shadow: var(--shadow-sm);
            }

            .btn i {
                font-size: 16px;
            }

            /* Footer */
            .footer {
                background: var(--bg-light);
                padding: 25px;
                text-align: center;
                border-top: 2px solid var(--border-color);
            }

            .footer-content {
                display: flex;
                flex-direction: column;
                gap: 10px;
                color: var(--text-medium);
                font-size: 13px;
            }

            .footer-content strong {
                color: var(--primary-color);
                font-weight: 700;
            }

            .footer-icon {
                display: inline-flex;
                align-items: center;
                gap: 6px;
            }

            .footer-icon i {
                color: var(--primary-color);
            }

            /* Responsive Design */
            @media (max-width: 768px) {
                body {
                    padding: 10px;
                }

                .header {
                    padding: 30px 20px;
                }

                .school-name {
                    font-size: 22px;
                }

                .content {
                    padding: 30px 20px;
                }

                .student-header {
                    flex-direction: column;
                    text-align: center;
                }

                .student-basic-info h2 {
                    font-size: 22px;
                }

                .info-grid {
                    grid-template-columns: 1fr;
                }

                .detail-label {
                    min-width: 140px;
                    font-size: 13px;
                }

                .detail-value {
                    font-size: 14px;
                }

                .action-buttons {
                    flex-direction: column;
                    width: 100%;
                }

                .btn {
                    width: 100%;
                    justify-content: center;
                }
            }

            /* Print Styles */
            @media print {
                body {
                    background: var(--white);
                    padding: 0;
                    margin: 0;
                }

                .verification-container {
                    box-shadow: none;
                    border-radius: 0;
                    max-width: 100%;
                }

                .header::before {
                    display: none;
                }

                .action-buttons {
                    display: none !important;
                }

                .status-banner,
                .student-card,
                .pass-details {
                    page-break-inside: avoid;
                }

                .verification-badge {
                    background: rgba(30, 132, 73, 0.2);
                }

                .student-photo {
                    border: 3px solid var(--primary-color);
                }

                .info-item,
                .detail-row {
                    box-shadow: none;
                }

                .footer::after {
                    content: 'OFFICIAL GATE PASS VERIFICATION DOCUMENT';
                    position: fixed;
                    bottom: 20px;
                    left: 50%;
                    transform: translateX(-50%);
                    font-size: 10px;
                    color: #999;
                    font-weight: 600;
                }
            }
        </style>
    </head>

    <body>
        <div class="verification-container">
            <div class="header">
                <div class="header-content">
                    <div class="school-logo-container">
                        <img src="<?php echo $school_logo; ?>" alt="School Logo" class="school-logo">
                    </div>
                    <div class="school-name"><?php echo $school_name; ?></div>
                    <div class="verification-badge">
                        <i class="fas fa-shield-check"></i>
                        <span>Gate Pass Verification System</span>
                    </div>
                </div>
            </div>

            <div class="content">
                <div class="status-banner <?php echo $status_class; ?>">
                    <div class="status-icon-container">
                        <i class="fas fa-<?php echo $status_icon; ?>"></i>
                    </div>
                    <div class="status-content">
                        <h3><?php echo $status_title; ?></h3>
                        <p><?php echo $status_message; ?></p>
                    </div>
                </div>

                <div class="student-card">
                    <div class="student-header">
                        <div class="student-photo-container">
                            <img src="<?php echo $photo; ?>" alt="Student Photo" class="student-photo" onerror="this.src='images/avatar.png'">
                            <div class="verification-icon">
                                <i class="fas fa-check"></i>
                            </div>
                        </div>
                        <div class="student-basic-info">
                            <h2><?php echo $student_name; ?></h2>
                            <div class="student-id-badge">
                                <i class="fas fa-id-card"></i>
                                <span><?php echo $student_id; ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">
                                <i class="fas fa-graduation-cap"></i>
                                Class & Stream
                            </div>
                            <div class="info-value"><?php echo $class; ?> (<?php echo $stream; ?>)</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">
                                <i class="fas fa-ticket-alt"></i>
                                Pass Reference
                            </div>
                            <div class="info-value"><?php echo $pass_id; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">
                                <i class="fas fa-flag"></i>
                                Priority Level
                            </div>
                            <div class="info-value">
                                <span class="priority-badge <?php echo $priority_class; ?>">
                                    <i class="fas fa-<?php echo $priority_icon; ?>"></i>
                                    <?php echo strtoupper($priority); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pass-details">
                    <h3><i class="fas fa-info-circle"></i> Gate Pass Details</h3>

                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-map-marker-alt"></i>
                            Destination:
                        </div>
                        <div class="detail-value"><?php echo $destination; ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-clock"></i>
                            Departure Time:
                        </div>
                        <div class="detail-value"><?php echo $departure_time; ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-calendar-check"></i>
                            Expected Return:
                        </div>
                        <div class="detail-value"><?php echo $expected_return; ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-info-circle"></i>
                            Status:
                        </div>
                        <div class="detail-value">
                            <span class="priority-badge <?php echo $status_class; ?>">
                                <?php echo strtoupper($status); ?>
                            </span>
                        </div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-phone"></i>
                            Parent Contact:
                        </div>
                        <div class="detail-value"><?php echo $parent_contact; ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-mobile-alt"></i>
                            Student Contact:
                        </div>
                        <div class="detail-value"><?php echo $student_contact; ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-user-friends"></i>
                            Accompanying Person:
                        </div>
                        <div class="detail-value"><?php echo $accompanying_person; ?></div>
                    </div>

                    <div class="detail-row full-width">
                        <div class="detail-label">
                            <i class="fas fa-comment-alt"></i>
                            Reason for Leaving:
                        </div>
                        <div class="detail-value"><?php echo nl2br($reason); ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-user-tie"></i>
                            Issued By:
                        </div>
                        <div class="detail-value"><?php echo $issued_by; ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-calendar-alt"></i>
                            Issue Date & Time:
                        </div>
                        <div class="detail-value"><?php echo $issued_at; ?></div>
                    </div>
                </div>

                <?php if ($status !== 'cancelled'): ?>
                    <div class="action-buttons">
                        <a href="api/print_gate_pass.php?id=<?php echo $gate_pass['id']; ?>&verified=1&verification_token=<?php echo $verification_token; ?>"
                            class="btn btn-primary" target="_blank">
                            <i class="fas fa-file-download"></i>
                            View/Download Gate Pass
                        </a>
                    </div>
                <?php else: ?>
                    <div class="action-buttons">
                        <button class="btn btn-primary" disabled>
                            <i class="fas fa-ban"></i>
                            Cannot Download Cancelled Pass
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <div class="footer">
                <div class="footer-content">
                    <div class="footer-icon">
                        <i class="fas fa-clock"></i>
                        <span>Verified on: <?php echo $verification_date; ?></span>
                    </div>
                    <div class="footer-icon">
                        <i class="fas fa-fingerprint"></i>
                        <span>Verification ID: <strong><?php echo $verification_id; ?></strong></span>
                    </div>
                    <div class="footer-icon">
                        <i class="fas fa-shield-alt"></i>
                        <span>This document is digitally verified and authentic</span>
                    </div>
                </div>
            </div>
        </div>
    </body>

    </html>
<?php
}

function display_not_found_page($pass_id)
{
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <title>Verification Failed</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: "Sen", sans-serif;
                min-height: 100vh;
                background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .error-container {
                background: white;
                border-radius: 16px;
                max-width: 600px;
                width: 100%;
                overflow: hidden;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
                animation: slideUp 0.6s ease;
            }

            @keyframes slideUp {
                from {
                    opacity: 0;
                    transform: translateY(30px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .error-header {
                background: linear-gradient(135deg, #c62828 0%, #b71c1c 100%);
                color: white;
                padding: 40px;
                text-align: center;
            }

            .error-icon {
                width: 80px;
                height: 80px;
                background: rgba(255, 255, 255, 0.2);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 20px;
                border: 3px solid rgba(255, 255, 255, 0.3);
            }

            .error-icon i {
                font-size: 40px;
            }

            .error-header h1 {
                font-size: 28px;
                font-weight: 700;
                margin-bottom: 8px;
            }

            .error-content {
                padding: 40px;
                text-align: center;
            }

            .error-content>p {
                font-size: 16px;
                color: #555;
                margin-bottom: 25px;
            }

            .error-box {
                background: #ffebee;
                border-left: 4px solid #c62828;
                padding: 25px;
                border-radius: 8px;
                text-align: left;
                margin: 20px 0;
            }

            .error-box h3 {
                color: #c62828;
                margin-bottom: 15px;
                font-weight: 700;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .error-box ul {
                list-style: none;
                padding: 0;
            }

            .error-box li {
                color: #721c24;
                padding: 10px 0;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .error-box li i {
                color: #c62828;
            }

            .help-text {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 8px;
                font-size: 14px;
                color: #666;
                margin-top: 20px;
                border: 1px solid #e0e0e0;
            }

            .help-text strong {
                color: #1e8449;
                font-weight: 700;
            }
        </style>
    </head>

    <body>
        <div class="error-container">
            <div class="error-header">
                <div class="error-icon">
                    <i class="fas fa-times-circle"></i>
                </div>
                <h1>Verification Failed</h1>
                <p>Gate pass not found in system</p>
            </div>
            <div class="error-content">
                <p>We were unable to verify this gate pass.</p>
                <div class="error-box">
                    <h3><i class="fas fa-exclamation-circle"></i> Possible Reasons:</h3>
                    <ul>
                        <li><i class="fas fa-circle"></i> Gate pass reference number does not exist</li>
                        <li><i class="fas fa-circle"></i> Gate pass may have been deleted</li>
                        <li><i class="fas fa-circle"></i> QR code is invalid or corrupted</li>
                        <li><i class="fas fa-circle"></i> Gate pass information may have been tampered with</li>
                    </ul>
                </div>
                <div class="help-text">
                    <strong>Verification Details:</strong><br>
                    Pass Reference: <?php echo htmlspecialchars($pass_id); ?>
                </div>
            </div>
        </div>
    </body>

    </html>
<?php
}

function display_invalid_token_page($pass_id)
{
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <title>Invalid Verification Token</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: "Sen", sans-serif;
                min-height: 100vh;
                background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .error-container {
                background: white;
                border-radius: 16px;
                max-width: 600px;
                width: 100%;
                overflow: hidden;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
                animation: slideUp 0.6s ease;
            }

            @keyframes slideUp {
                from {
                    opacity: 0;
                    transform: translateY(30px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .error-header {
                background: linear-gradient(135deg, #d32f2f 0%, #c62828 100%);
                color: white;
                padding: 40px;
                text-align: center;
            }

            .error-icon {
                width: 80px;
                height: 80px;
                background: rgba(255, 255, 255, 0.2);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 20px;
                border: 3px solid rgba(255, 255, 255, 0.3);
            }

            .error-icon i {
                font-size: 40px;
            }

            .error-header h1 {
                font-size: 28px;
                font-weight: 700;
                margin-bottom: 8px;
            }

            .error-content {
                padding: 40px;
                text-align: center;
            }

            .error-content>p {
                font-size: 16px;
                color: #555;
                margin-bottom: 25px;
            }

            .error-box {
                background: #ffebee;
                border-left: 4px solid #d32f2f;
                padding: 25px;
                border-radius: 8px;
                text-align: left;
                margin: 20px 0;
            }

            .error-box h3 {
                color: #d32f2f;
                margin-bottom: 15px;
                font-weight: 700;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .error-box ul {
                list-style: none;
                padding: 0;
            }

            .error-box li {
                color: #721c24;
                padding: 10px 0;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .error-box li i {
                color: #d32f2f;
            }

            .warning-text {
                background: #fff3e0;
                padding: 20px;
                border-radius: 8px;
                font-size: 14px;
                color: #e65100;
                margin-top: 20px;
                border: 1px solid #ffb74d;
                font-weight: 600;
            }

            .warning-text i {
                margin-right: 8px;
            }
        </style>
    </head>

    <body>
        <div class="error-container">
            <div class="error-header">
                <div class="error-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <h1>Security Verification Failed</h1>
                <p>Invalid authentication token detected</p>
            </div>
            <div class="error-content">
                <p>The verification token provided does not match our records.</p>
                <div class="error-box">
                    <h3><i class="fas fa-exclamation-triangle"></i> Security Alert:</h3>
                    <ul>
                        <li><i class="fas fa-circle"></i> The QR code or URL may have been tampered with</li>
                        <li><i class="fas fa-circle"></i> The verification link may be expired or corrupted</li>
                        <li><i class="fas fa-circle"></i> This could be a fraudulent gate pass</li>
                    </ul>
                </div>
                <div class="warning-text">
                    <i class="fas fa-exclamation-circle"></i>
                    <strong>WARNING:</strong> Please report this to school administration immediately if you suspect fraudulent activity.
                </div>
            </div>
        </div>
    </body>

    </html>
<?php
}

function display_error_page($title, $errors)
{
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($title); ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: "Sen", sans-serif;
                min-height: 100vh;
                background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .error-container {
                background: white;
                border-radius: 16px;
                max-width: 600px;
                width: 100%;
                overflow: hidden;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            }

            .error-header {
                background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
                color: white;
                padding: 40px;
                text-align: center;
            }

            .error-icon {
                width: 80px;
                height: 80px;
                background: rgba(255, 255, 255, 0.2);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 20px;
            }

            .error-icon i {
                font-size: 40px;
            }

            .error-content {
                padding: 40px;
            }

            .error-list {
                background: #fff3e0;
                border-left: 4px solid #ff9800;
                padding: 25px;
                border-radius: 8px;
            }

            .error-list h3 {
                color: #e65100;
                margin-bottom: 15px;
                font-weight: 700;
            }

            .error-list ul {
                list-style: none;
                padding: 0;
            }

            .error-list li {
                color: #e65100;
                padding: 10px 0;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .error-list li i {
                color: #ff9800;
            }
        </style>
    </head>

    <body>
        <div class="error-container">
            <div class="error-header">
                <div class="error-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h1><?php echo htmlspecialchars($title); ?></h1>
            </div>
            <div class="error-content">
                <div class="error-list">
                    <h3>Validation Errors:</h3>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><i class="fas fa-circle"></i> <?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </body>

    </html>
<?php
}
?>