<?php
require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';

// FIX #1: Removed duplicate trackAction call (was called on both line 5 and 15)
$tracker->trackAction("Add New User");

// --- PHPMailer Setup ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load Composer's autoloader
require __DIR__ . '/vendor/autoload.php';
// --- End PHPMailer Setup ---

// Prevent caching for sensitive pages
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// FIX #2: CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Form processing variables
$formErrors = [
    'username' => '',
    'email' => '',
    'password' => '',
    'confirmPassword' => '',
    'role' => ''
];
$formData = [
    'username' => '',
    'email' => '',
    'role' => ''
];

// Success message flag
$userAdded = false;

$staffMembers = [];
// FIX #12: Check if query succeeds before iterating
$staffQuery = $conn->query("SELECT staff_id, first_name, last_name FROM staff ORDER BY first_name, last_name");
if ($staffQuery) {
    while ($row = $staffQuery->fetch_assoc()) {
        $staffMembers[] = [
            'id' => $row['staff_id'],
            'name' => $row['first_name'] . ' ' . $row['last_name']
        ];
    }
    // FIX #12: Free result to release memory
    $staffQuery->free();
}

// FIX #3: Define allowed roles as a whitelist for validation
$allowedRoles = [
    'super user', 'school leader', 'class teacher', 'subject teacher',
    'nurse', 'bursar', 'librarian', 'receptionist', 'gateman', 'lab attendant'
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // FIX #2: CSRF token validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['notification'] = [
            'type' => 'error',
            'title' => 'Security Error',
            'message' => 'Invalid request. Please refresh the page and try again.'
        ];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $isValid = true;

    // Validate staff member selection
    if (empty($_POST['staff_member'])) {
        $formErrors['username'] = 'Please select a staff member from the dropdown';
        $isValid = false;
    } else {
        $staffId = trim($_POST['staff_member']);

        // Fetch the staff member's name to use as the username
        $stmt = $conn->prepare("SELECT first_name, last_name FROM staff WHERE staff_id = ?");
        $stmt->bind_param("s", $staffId);
        $stmt->execute();
        $staffResult = $stmt->get_result();
        if ($staffResult->num_rows === 0) {
            $formErrors['username'] = 'The selected staff member is invalid';
            $isValid = false;
        } else {
            $staffRow = $staffResult->fetch_assoc();
            $username = $staffRow['first_name'] . ' ' . $staffRow['last_name'];
        }
        $stmt->close();

        // Check if a user account for this staff_id already exists
        if ($isValid) {
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE staff_id = ?");
            $stmt->bind_param("s", $staffId);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $formErrors['username'] = 'This staff member already has a user account';
                $isValid = false;
            }
            $stmt->close();
        }
    }

    // Validate email
    if (empty($_POST['email'])) {
        $formErrors['email'] = 'Email is required';
        $isValid = false;
    } elseif (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $formErrors['email'] = 'Invalid email format';
        $isValid = false;
    } else {
        $formData['email'] = trim($_POST['email']);
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->bind_param("s", $formData['email']);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $formErrors['email'] = 'This email is already registered';
            $isValid = false;
        }
        $stmt->close();
    }

    // FIX #10: Validate role against a strict whitelist
    if (empty($_POST['role'])) {
        $formErrors['role'] = 'Role is required';
        $isValid = false;
    } elseif (!in_array($_POST['role'], $allowedRoles, true)) {
        $formErrors['role'] = 'Invalid role selected';
        $isValid = false;
    } else {
        $formData['role'] = $_POST['role'];
    }

    // Validate password
    $password = $_POST['password'];
    if (empty($password)) {
        $formErrors['password'] = 'Password is required';
        $isValid = false;
    } elseif (strlen($password) < 8) {
        $formErrors['password'] = 'Password must be at least 8 characters';
        $isValid = false;
    } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $formErrors['password'] = 'Password must include uppercase, lowercase, and numbers';
        $isValid = false;
    }

    // Validate confirm password
    if (empty($_POST['confirm-password'])) {
        $formErrors['confirmPassword'] = 'Please confirm your password';
        $isValid = false;
    } elseif ($password != $_POST['confirm-password']) {
        $formErrors['confirmPassword'] = 'Passwords do not match';
        $isValid = false;
    }

    // Process valid form
    if ($isValid) {
        try {
            $conn->begin_transaction();

            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            $stmt = $conn->prepare("INSERT INTO users (user_name, email, password, role, staff_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("sssss", $username, $formData['email'], $passwordHash, $formData['role'], $staffId);

            if (!$stmt->execute()) {
                throw new Exception('Database error: ' . $stmt->error);
            }
            $stmt->close();

            // --- Send Welcome Email ---
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                // SECURITY FIX #4: SMTP credentials must be defined in a secure config file.
                // Add these constants to your config.php (which must NOT be in version control):
                //   define('SMTP_USERNAME', 'your-email@gmail.com');
                //   define('SMTP_PASSWORD', 'your-app-password');
                $mail->Username   = defined('SMTP_USERNAME') ? SMTP_USERNAME : '';
                $mail->Password   = defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;
                $mail->SMTPDebug  = 0;

                $mail->setFrom('no-reply@schoolpilot.com', 'School Pilot Accounts');
                $mail->addAddress($formData['email'], $username);

                $mail->isHTML(true);
                $mail->Subject = 'Welcome to School Pilot!';
                $mail->Body    = "
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
                            .info-box { background-color: #e8f5e8; border-left: 4px solid #27ae60; color: #145a32; padding: 15px; margin: 20px 0; border-radius: 5px; }
                            .info-box ul { list-style: none; padding: 0; margin: 0; }
                            .info-box li { margin-bottom: 8px; }
                            .info-box li strong { display: inline-block; width: 100px; }
                            .footer { background-color: #f4f4f4; color: #777777; padding: 20px; text-align: center; font-size: 12px; }
                            .footer p { margin: 0 0 5px; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h1>Welcome to School Pilot!</h1>
                            </div>
                            <div class='content'>
                               <p>Hello " . htmlspecialchars($username) . ",</p>
                                <p>An account has been created for you in the School Pilot system. You can now log in using the following credentials and the password that was set for you.</p>
                                <div class='info-box'>
                                    <ul>
                                        <li><strong>Username:</strong> " . htmlspecialchars($username) . "</li>
                                        <li><strong>Email:</strong> " . htmlspecialchars($formData['email']) . "</li>
                                        <li><strong>Role:</strong> " . htmlspecialchars(ucfirst($formData['role'])) . "</li>
                                    </ul>
                                </div>
                                <p>We recommend changing your password after your first login for security purposes.</p>
                                <p>Thank you,<br>The School Pilot Team</p>
                            </div>
                            <div class='footer'>
                                <p>&copy; " . date('Y') . " School Pilot. All rights reserved.</p>
                                <p>This is an automated message, please do not reply.</p>
                            </div>
                        </div>
                    </body>
                    </html>
                ";
                $mail->send();
                $_SESSION['notification'] = [
                    'type' => 'success',
                    'title' => 'Success',
                    'message' => 'User added successfully and a welcome email has been sent.'
                ];
            } catch (Exception $e) {
                $_SESSION['notification'] = [
                    'type' => 'warning',
                    'title' => 'User Added, Email Failed',
                    'message' => "User was added, but the welcome email could not be sent. Mailer Error: {$mail->ErrorInfo}"
                ];
            }
            // --- End Send Welcome Email ---

            $conn->commit();

            $userAdded = true;
            // Regenerate CSRF token after successful submission
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            // Clear form data for next entry
            $formData = ['username' => '', 'email' => '', 'role' => ''];
        } catch (Exception $e) {
            $conn->rollback();
            // FIX #4: Log the real error server-side, show a generic message to the user
            error_log('[Add User Error] ' . $e->getMessage());
            $_SESSION['notification'] = [
                'type' => 'error',
                'title' => 'Error',
                'message' => 'Failed to add user. Please try again or contact the system administrator.'
            ];
        }
    }
}

// FIX #11: Close connection before HTML output
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="shortcut icon" href="images/schoolcontrol_icon.png" type="image/x-icon">
    <title>Add New User — School Pilot</title>
    <style>
        :root {
            --primary:       #1a6b2f;
            --primary-light: #2e8b47;
            --primary-dark:  #134d22;
            --primary-ghost: rgba(26, 107, 47, 0.08);
            --primary-ring:  rgba(26, 107, 47, 0.18);
            --accent:        #3dba60;
            --danger:        #d93025;
            --danger-bg:     #fff0ef;
            --warning:       #e07b00;
            --warning-bg:    #fff8ec;
            --success:       #1a6b2f;
            --success-bg:    #edf7f0;
            --text-primary:  #111827;
            --text-secondary:#4b5563;
            --text-muted:    #9ca3af;
            --border:        #e5e7eb;
            --border-focus:  #1a6b2f;
            --surface:       #ffffff;
            --bg:            #f3f4f6;
            --radius-sm:     6px;
            --radius:        10px;
            --radius-lg:     14px;
            --shadow-sm:     0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.06);
            --shadow:        0 4px 12px rgba(0,0,0,.08), 0 2px 6px rgba(0,0,0,.05);
            --shadow-lg:     0 10px 30px rgba(0,0,0,.10), 0 4px 10px rgba(0,0,0,.06);
            --transition:    all 0.2s cubic-bezier(.4,0,.2,1);
        }

        *, *::before, *::after {
            margin: 0; padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            background-color: var(--bg);
            color: var(--text-primary);
            font-family: 'DM Sans', system-ui, sans-serif;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        /* ── Page Layout ── */
        .page-container {
            max-width: 100%px;
            margin: 0 auto;
            margin-top: 72px;
            padding: 28px 24px 60px;
        }

        /* ── Breadcrumb ── */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 22px;
            font-size: 13px;
            color: var(--text-muted);
        }
        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }
        .breadcrumb a:hover { color: var(--primary-dark); text-decoration: underline; }
        .breadcrumb .sep { color: var(--border); font-size: 16px; line-height: 1; }
        .breadcrumb .current { color: var(--text-secondary); font-weight: 500; }

        /* ── Card ── */
        .card {
            background: var(--surface);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 60%, var(--primary-light) 100%);
            color: white;
            padding: 22px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .card-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .card-header-icon {
            width: 40px; height: 40px;
            background: rgba(255,255,255,0.15);
            border-radius: var(--radius);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .card-header-text h2 {
            font-size: 17px;
            font-weight: 600;
            letter-spacing: -0.01em;
            margin-bottom: 2px;
        }
        .card-header-text p {
            font-size: 13px;
            opacity: 0.75;
            font-weight: 400;
        }

        /* ── Form Body ── */
        .card-body {
            padding: 32px 28px;
        }

        .form-section {
            margin-bottom: 32px;
            padding-bottom: 32px;
            border-bottom: 1px solid var(--border);
        }
        .form-section:last-of-type {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .form-section-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-muted);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-section-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        .form-col {
            flex: 1;
            min-width: 240px;
        }
        .form-col-full { flex: 0 0 100%; }

        .form-group {
            margin-bottom: 22px;
        }
        .form-group:last-child { margin-bottom: 0; }

        .form-label {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 7px;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        .form-label .required {
            color: var(--danger);
            font-size: 15px;
            line-height: 1;
        }
        .form-label .label-icon {
            color: var(--text-muted);
            font-size: 12px;
        }

        /* ── Inputs ── */
        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            font-size: 14.5px;
            font-family: 'DM Sans', sans-serif;
            color: var(--text-primary);
            background: var(--surface);
            transition: var(--transition);
            appearance: none;
        }
        .form-control::placeholder { color: var(--text-muted); }
        .form-control:hover { border-color: #d1d5db; }
        .form-control:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px var(--primary-ring);
            outline: none;
        }
        .form-control.is-invalid {
            border-color: var(--danger);
            background-color: var(--danger-bg);
        }
        .form-control.is-invalid:focus {
            box-shadow: 0 0 0 3px rgba(217,48,37,.15);
        }

        /* ── Select Arrow ── */
        select.form-control {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 36px;
            cursor: pointer;
        }

        /* ── Password wrapper ── */
        .input-wrapper { position: relative; }
        .password-toggle {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--text-muted);
            transition: var(--transition);
            padding: 4px;
            border-radius: 4px;
            font-size: 14px;
            line-height: 1;
        }
        .password-toggle:hover { color: var(--primary); background: var(--primary-ghost); }

        input[type="password"] {
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
        }
        input[type="password"]::selection { background: transparent; }

        /* ── Feedback ── */
        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 5px;
            display: flex;
            align-items: flex-start;
            gap: 5px;
        }
        .error-feedback {
            color: var(--danger);
            font-size: 12.5px;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
            font-weight: 500;
        }

        /* ── Password Strength ── */
        .strength-track {
            height: 4px;
            border-radius: 4px;
            background: var(--border);
            margin-top: 8px;
            overflow: hidden;
        }
        .strength-bar {
            height: 100%;
            width: 0;
            border-radius: 4px;
            transition: width 0.3s ease, background-color 0.3s ease;
        }
        .strength-bar.weak   { width: 25%; background: #ef4444; }
        .strength-bar.medium { width: 60%; background: #f59e0b; }
        .strength-bar.strong { width: 100%; background: var(--accent); }

        .strength-label {
            font-size: 11.5px;
            margin-top: 4px;
            font-weight: 500;
        }
        .strength-label.weak   { color: #ef4444; }
        .strength-label.medium { color: #d97706; }
        .strength-label.strong { color: var(--primary); }

        /* ── Buttons ── */
        .button-group {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 28px;
            padding-top: 24px;
            border-top: 1px solid var(--border);
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 10px 22px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'DM Sans', sans-serif;
            border-radius: var(--radius);
            border: 1.5px solid transparent;
            cursor: pointer;
            transition: var(--transition);
            white-space: nowrap;
            line-height: 1.4;
        }
        .btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

        .btn-primary {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            box-shadow: 0 4px 12px rgba(26,107,47,.30);
            transform: translateY(-1px);
        }
        .btn-primary:active { transform: translateY(0); box-shadow: none; }

        .btn-ghost {
            background: transparent;
            color: var(--text-secondary);
            border-color: var(--border);
        }
        .btn-ghost:hover {
            border-color: #9ca3af;
            color: var(--text-primary);
            background: var(--bg);
        }

        /* Loading state on btn */
        .btn.loading {
            position: relative;
            color: transparent;
            pointer-events: none;
        }
        .btn.loading::after {
            content: "";
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 18px; height: 18px;
            border: 2px solid rgba(255,255,255,.35);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.75s linear infinite;
        }

        /* ── Loading Overlay ── */
        .loading-overlay {
            position: fixed;
            inset: 0;
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(6px);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 20px;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s ease;
        }
        .loading-overlay.show { opacity: 1; visibility: visible; }

        .loader-ring {
            width: 48px; height: 48px;
            border: 3px solid var(--border);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        .loading-text {
            color: var(--text-secondary);
            font-size: 14px;
            font-weight: 500;
            text-align: center;
        }

        /* ── Notifications ── */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 14px 18px;
            border-radius: var(--radius);
            max-width: 360px;
            z-index: 10000;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: slideInRight 0.3s cubic-bezier(.4,0,.2,1) both;
            border: 1px solid transparent;
        }
        .notification.success {
            background: var(--success-bg);
            border-color: #a7f3c0;
            color: var(--primary-dark);
        }
        .notification.error {
            background: var(--danger-bg);
            border-color: #fecaca;
            color: #7f1d1d;
        }
        .notification.warning {
            background: var(--warning-bg);
            border-color: #fed7aa;
            color: #78350f;
        }
        .notification i {
            font-size: 17px;
            margin-top: 1px;
            flex-shrink: 0;
        }
        .notification.success i { color: var(--accent); }
        .notification.error   i { color: var(--danger); }
        .notification.warning i { color: var(--warning); }
        .notif-title { font-size: 13.5px; font-weight: 700; margin-bottom: 2px; }
        .notif-body  { font-size: 13px; line-height: 1.45; }

        /* ── Animations ── */
        @keyframes spin {
            to { transform: translate(-50%,-50%) rotate(360deg); }
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        @keyframes slideInRight {
            from { transform: translateX(110%); opacity: 0; }
            to   { transform: translateX(0);    opacity: 1; }
        }
        @keyframes fadeOut {
            to { opacity: 0; transform: translateX(20px); }
        }

        /* ── Responsive ── */
        @media (max-width: 640px) {
            .page-container { padding: 16px 14px 48px; margin-top: 60px; }
            .card-body { padding: 22px 18px; }
            .card-header { padding: 18px 18px; }
            .form-col { flex: 0 0 100%; }
            .button-group { flex-direction: column-reverse; }
            .btn { width: 100%; }
            .notification { left: 12px; right: 12px; max-width: 100%; }
        }
    </style>
</head>

<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay" role="status" aria-live="polite">
        <div class="loader-ring"></div>
        <div class="loading-text" id="loadingText">Creating user account…</div>
    </div>

    <?php require_once 'nav.php' ?>

    <div class="page-container">

        <!-- Breadcrumb -->
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="dashboard.php"><i class="fas fa-home" style="font-size:11px;"></i> Dashboard</a>
            <span class="sep">/</span>
            <a href="settings.php">Settings</a>
            <span class="sep">/</span>
            <span class="current">Add User</span>
        </nav>

        <!-- Main Card -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-left">
                    <div class="card-header-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="card-header-text">
                        <h2>Add New User</h2>
                        <p>Create a system account for a staff member</p>
                    </div>
                </div>
            </div>

            <div class="card-body">
                <form id="addUserForm" method="POST" novalidate autocomplete="off">
                    <!-- FIX #2: CSRF hidden field -->
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <!-- Section 1: Account Identity -->
                    <div class="form-section">
                        <div class="form-section-title">Account Identity</div>
                        <div class="form-row">
                            <div class="form-col">
                                <div class="form-group">
                                    <!-- FIX #6: label `for` now matches the select `id="staff_member"` -->
                                    <label class="form-label" for="staff_member">
                                        <i class="fas fa-user label-icon"></i>
                                        Staff Member <span class="required" aria-hidden="true">*</span>
                                    </label>
                                    <select class="form-control <?php echo !empty($formErrors['username']) ? 'is-invalid' : ''; ?>"
                                        id="staff_member" name="staff_member" required aria-required="true"
                                        aria-describedby="<?php echo !empty($formErrors['username']) ? 'err-staff' : 'hint-staff'; ?>">
                                        <option value="" disabled selected>Select a staff member…</option>
                                        <?php foreach ($staffMembers as $staff): ?>
                                            <option value="<?php echo htmlspecialchars($staff['id']); ?>">
                                                <?php echo htmlspecialchars($staff['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!empty($formErrors['username'])): ?>
                                        <div class="error-feedback" id="err-staff" role="alert">
                                            <i class="fas fa-circle-exclamation"></i>
                                            <?php echo htmlspecialchars($formErrors['username']); ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="form-hint" id="hint-staff">
                                            <i class="fas fa-info-circle"></i>
                                            Only staff members without an existing account are shown.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="form-col">
                                <div class="form-group">
                                    <label class="form-label" for="email">
                                        <i class="fas fa-envelope label-icon"></i>
                                        Email Address <span class="required" aria-hidden="true">*</span>
                                    </label>
                                    <input type="email"
                                        class="form-control <?php echo !empty($formErrors['email']) ? 'is-invalid' : ''; ?>"
                                        id="email" name="email"
                                        value="<?php echo htmlspecialchars($formData['email']); ?>"
                                        placeholder="e.g. john.doe@school.ac.ug"
                                        autocomplete="off"
                                        aria-required="true"
                                        aria-describedby="<?php echo !empty($formErrors['email']) ? 'err-email' : 'hint-email'; ?>">
                                    <?php if (!empty($formErrors['email'])): ?>
                                        <div class="error-feedback" id="err-email" role="alert">
                                            <i class="fas fa-circle-exclamation"></i>
                                            <?php echo htmlspecialchars($formErrors['email']); ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="form-hint" id="hint-email">
                                            <i class="fas fa-info-circle"></i>
                                            A welcome email will be sent to this address.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-col">
                                <div class="form-group">
                                    <label class="form-label" for="role">
                                        <i class="fas fa-shield-halved label-icon"></i>
                                        User Role <span class="required" aria-hidden="true">*</span>
                                    </label>
                                    <select class="form-control <?php echo !empty($formErrors['role']) ? 'is-invalid' : ''; ?>"
                                        id="role" name="role"
                                        aria-required="true"
                                        aria-describedby="<?php echo !empty($formErrors['role']) ? 'err-role' : ''; ?>">
                                        <option value="" disabled <?php echo empty($formData['role']) ? 'selected' : ''; ?>>Select a role…</option>
                                        <?php
                                        $roleLabels = [
                                            'super user'      => 'Super User',
                                            'school leader'   => 'School Leader',
                                            'class teacher'   => 'Class Teacher',
                                            'subject teacher' => 'Subject Teacher',
                                            'nurse'           => 'Nurse',
                                            'bursar'          => 'Bursar',
                                            'librarian'       => 'Librarian',
                                            'receptionist'    => 'Receptionist',
                                            'gateman'         => 'Gateman',
                                            'lab attendant'   => 'Lab Attendant',
                                        ];
                                        foreach ($roleLabels as $value => $label):
                                        ?>
                                            <option value="<?php echo $value; ?>" <?php echo $formData['role'] === $value ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!empty($formErrors['role'])): ?>
                                        <div class="error-feedback" id="err-role" role="alert">
                                            <i class="fas fa-circle-exclamation"></i>
                                            <?php echo htmlspecialchars($formErrors['role']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="form-col"></div><!-- spacer -->
                        </div>
                    </div>

                    <!-- Section 2: Security -->
                    <div class="form-section">
                        <div class="form-section-title">Security</div>
                        <div class="form-row">
                            <div class="form-col">
                                <div class="form-group">
                                    <label class="form-label" for="password">
                                        <i class="fas fa-lock label-icon"></i>
                                        Password <span class="required" aria-hidden="true">*</span>
                                    </label>
                                    <div class="input-wrapper">
                                        <input type="password"
                                            class="form-control <?php echo !empty($formErrors['password']) ? 'is-invalid' : ''; ?>"
                                            id="password" name="password"
                                            placeholder="Create a strong password"
                                            autocomplete="new-password"
                                            oncopy="return false" oncut="return false" onpaste="return false"
                                            aria-required="true"
                                            aria-describedby="hint-pw <?php echo !empty($formErrors['password']) ? 'err-pw' : ''; ?>">
                                        <span class="password-toggle" id="togglePassword" aria-label="Show password" role="button" tabindex="0">
                                            <i class="fas fa-eye" aria-hidden="true"></i>
                                        </span>
                                    </div>
                                    <div class="strength-track" aria-hidden="true">
                                        <div class="strength-bar" id="strengthBar"></div>
                                    </div>
                                    <div class="strength-label" id="strengthLabel"></div>
                                    <?php if (!empty($formErrors['password'])): ?>
                                        <div class="error-feedback" id="err-pw" role="alert">
                                            <i class="fas fa-circle-exclamation"></i>
                                            <?php echo htmlspecialchars($formErrors['password']); ?>
                                        </div>
                                    <?php else: ?>
                                        <!-- FIX #9: Hint now correctly says 8 characters (was incorrectly 6) -->
                                        <div class="form-hint" id="hint-pw">
                                            <i class="fas fa-info-circle"></i>
                                            Min. 8 characters with uppercase, lowercase, and a number.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="form-col">
                                <div class="form-group">
                                    <label class="form-label" for="confirm-password">
                                        <i class="fas fa-lock label-icon"></i>
                                        Confirm Password <span class="required" aria-hidden="true">*</span>
                                    </label>
                                    <div class="input-wrapper">
                                        <input type="password"
                                            class="form-control <?php echo !empty($formErrors['confirmPassword']) ? 'is-invalid' : ''; ?>"
                                            id="confirm-password" name="confirm-password"
                                            placeholder="Repeat the password"
                                            autocomplete="new-password"
                                            oncopy="return false" oncut="return false" onpaste="return false"
                                            aria-required="true"
                                            aria-describedby="<?php echo !empty($formErrors['confirmPassword']) ? 'err-cpw' : ''; ?>">
                                        <span class="password-toggle" id="toggleConfirmPassword" aria-label="Show confirm password" role="button" tabindex="0">
                                            <i class="fas fa-eye" aria-hidden="true"></i>
                                        </span>
                                    </div>
                                    <?php if (!empty($formErrors['confirmPassword'])): ?>
                                        <div class="error-feedback" id="err-cpw" role="alert">
                                            <i class="fas fa-circle-exclamation"></i>
                                            <?php echo htmlspecialchars($formErrors['confirmPassword']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Buttons -->
                    <div class="button-group">
                        <!-- FIX #8: Both buttons now use consistent `btn` class -->
                        <button type="button" class="btn btn-ghost" onclick="window.location.href='dashboard.php'">
                            <i class="fas fa-arrow-left" aria-hidden="true"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-user-plus" aria-hidden="true"></i> Create User Account
                        </button>
                    </div>
                </form>
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
        // Prevent context menu on password fields
        ['password', 'confirm-password'].forEach(id => {
            const el = document.getElementById(id);
            el.addEventListener('contextmenu', e => e.preventDefault());
            el.addEventListener('copy', () => showNotification('Security Notice', 'Copying passwords is disabled for security reasons.', 'warning'));
        });

        document.addEventListener('DOMContentLoaded', function () {

            // ── Password visibility toggles ──
            function setupToggle(toggleId, fieldId) {
                const toggle = document.getElementById(toggleId);
                const field  = document.getElementById(fieldId);
                const handler = () => {
                    const show = field.type === 'password';
                    field.type = show ? 'text' : 'password';
                    toggle.querySelector('i').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
                    toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                };
                toggle.addEventListener('click', handler);
                toggle.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); handler(); } });
            }
            setupToggle('togglePassword', 'password');
            setupToggle('toggleConfirmPassword', 'confirm-password');

            // ── Password strength ──
            const passwordField = document.getElementById('password');
            const strengthBar   = document.getElementById('strengthBar');
            const strengthLabel = document.getElementById('strengthLabel');

            passwordField.addEventListener('input', function () {
                const v = this.value;
                let score = 0;
                if (v.length >= 8)  score++;
                if (v.length >= 12) score++;
                if (/[A-Z]/.test(v)) score++;
                if (/[a-z]/.test(v)) score++;
                if (/[0-9]/.test(v)) score++;
                if (/[^A-Za-z0-9]/.test(v)) score++;

                strengthBar.className = 'strength-bar';
                strengthLabel.className = 'strength-label';

                if (!v) {
                    strengthLabel.textContent = '';
                } else if (score < 3) {
                    strengthBar.classList.add('weak');
                    strengthLabel.classList.add('weak');
                    strengthLabel.textContent = 'Weak — add numbers or symbols';
                } else if (score < 5) {
                    strengthBar.classList.add('medium');
                    strengthLabel.classList.add('medium');
                    strengthLabel.textContent = 'Fair — could be stronger';
                } else {
                    strengthBar.classList.add('strong');
                    strengthLabel.classList.add('strong');
                    strengthLabel.textContent = 'Strong password ✓';
                }
            });

            // ── Loader helpers ──
            function showLoader(msg = 'Processing…') {
                document.getElementById('loadingText').textContent = msg;
                document.getElementById('loadingOverlay').classList.add('show');
            }
            function hideLoader() {
                document.getElementById('loadingOverlay').classList.remove('show');
            }

            // ── Field error helpers ──
            function showFieldError(field, message) {
                field.classList.add('is-invalid');
                let err = field.closest('.input-wrapper, .form-group').querySelector('.error-feedback');
                if (!err) {
                    err = document.createElement('div');
                    err.className = 'error-feedback';
                    err.setAttribute('role', 'alert');
                    (field.closest('.input-wrapper') || field).insertAdjacentElement('afterend', err);
                }
                err.innerHTML = `<i class="fas fa-circle-exclamation"></i> ${message}`;
            }
            function clearFieldError(field) {
                field.classList.remove('is-invalid');
                const wrap = field.closest('.input-wrapper, .form-group');
                const err  = wrap ? wrap.querySelector('.error-feedback') : null;
                if (err) err.remove();
            }
            function isValidEmail(email) {
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
            }

            // ── Form validation ──
            document.getElementById('addUserForm').addEventListener('submit', function (event) {
                let valid = true;

                // FIX #7: now correctly targeting id="staff_member" (was "username" — didn't exist)
                const staffMember = document.getElementById('staff_member');
                if (!staffMember.value) {
                    showFieldError(staffMember, 'Please select a staff member');
                    valid = false;
                } else {
                    clearFieldError(staffMember);
                }

                const email = document.getElementById('email');
                if (!email.value.trim()) {
                    showFieldError(email, 'Email is required');
                    valid = false;
                } else if (!isValidEmail(email.value)) {
                    showFieldError(email, 'Please enter a valid email address');
                    valid = false;
                } else {
                    clearFieldError(email);
                }

                const role = document.getElementById('role');
                if (!role.value) {
                    showFieldError(role, 'Please select a role');
                    valid = false;
                } else {
                    clearFieldError(role);
                }

                const pw = document.getElementById('password');
                if (!pw.value) {
                    showFieldError(pw, 'Password is required');
                    valid = false;
                } else if (pw.value.length < 8) {
                    showFieldError(pw, 'Password must be at least 8 characters');
                    valid = false;
                } else if (!/[A-Z]/.test(pw.value) || !/[a-z]/.test(pw.value) || !/[0-9]/.test(pw.value)) {
                    showFieldError(pw, 'Password must include uppercase, lowercase, and numbers');
                    valid = false;
                } else {
                    clearFieldError(pw);
                }

                const cpw = document.getElementById('confirm-password');
                if (!cpw.value) {
                    showFieldError(cpw, 'Please confirm your password');
                    valid = false;
                } else if (cpw.value !== pw.value) {
                    showFieldError(cpw, 'Passwords do not match');
                    valid = false;
                } else {
                    clearFieldError(cpw);
                }

                if (!valid) {
                    event.preventDefault();
                    playErrorSound();
                    showNotification('Validation Error', 'Please correct the highlighted fields before submitting.', 'error');
                    // Scroll to first error
                    const firstError = document.querySelector('.is-invalid');
                    if (firstError) firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    showLoader('Creating user account and sending welcome email…');
                    const btn = document.getElementById('submitBtn');
                    btn.classList.add('loading');
                    btn.disabled = true;

                    setTimeout(() => {
                        if (document.getElementById('loadingOverlay').classList.contains('show')) {
                            hideLoader();
                            btn.classList.remove('loading');
                            btn.disabled = false;
                            showNotification('Timeout', 'The request is taking too long. Please try again.', 'error');
                        }
                    }, 30000);
                }
            });

            // ── Notification system ──
            function showNotification(title, message, type = 'info') {
                const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
                const n = document.createElement('div');
                n.className = `notification ${type}`;
                n.setAttribute('role', 'alert');
                n.setAttribute('aria-live', 'assertive');
                n.innerHTML = `
                    <i class="fas ${icons[type] || icons.info}" aria-hidden="true"></i>
                    <div>
                        <div class="notif-title">${title}</div>
                        <div class="notif-body">${message}</div>
                    </div>`;
                document.body.appendChild(n);

                if (type === 'success') playSuccessSound();
                else if (type === 'error') playErrorSound();

                setTimeout(() => {
                    n.style.animation = 'fadeOut 0.35s ease forwards';
                    setTimeout(() => n.remove(), 350);
                }, 5000);
            }

            function playSuccessSound() {
                const s = document.getElementById('successSound');
                if (s) { s.currentTime = 0; s.play().catch(() => {}); }
            }
            function playErrorSound() {
                const s = document.getElementById('errorSound');
                if (s) { s.currentTime = 0; s.play().catch(() => {}); }
            }

            // Hide loader on page load
            window.addEventListener('load', hideLoader);

            // Navigation loader
            document.addEventListener('click', function (e) {
                if (e.target.matches('a[href*="dashboard.php"], a[href*="settings.php"]')) {
                    showLoader('Loading…');
                }
            });

            // FIX #5: Use json_encode for safe JS output (was addslashes which is not XSS-safe)
            <?php if (isset($_SESSION['notification'])): ?>
                showNotification(
                    <?php echo json_encode($_SESSION['notification']['title']); ?>,
                    <?php echo json_encode($_SESSION['notification']['message']); ?>,
                    <?php echo json_encode($_SESSION['notification']['type']); ?>
                );
                <?php unset($_SESSION['notification']); ?>
            <?php endif; ?>

        }); // end DOMContentLoaded
    </script>
</body>
</html>
