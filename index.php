<?php

// Disable error display to users
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');
@error_reporting(E_ALL);

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Define error context constant for system_error.php
define('ERROR_CONTEXT', true);

// Custom error handler
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    // Log the error
    error_log("Error [$errno]: $errstr in $errfile on line $errline");
    
    // For fatal errors, show error page
    if ($errno === E_ERROR || $errno === E_PARSE || $errno === E_CORE_ERROR || $errno === E_COMPILE_ERROR) {
        if (!headers_sent()) {
            http_response_code(500);
        }
        require_once 'system_error.php';
        exit();
    }
    
    return true; // Don't execute PHP's internal error handler
});

// Custom exception handler
set_exception_handler(function ($exception) {
    // Log the exception
    error_log("Uncaught Exception: " . $exception->getMessage() .
        " in " . $exception->getFile() .
        " on line " . $exception->getLine());
    
    if (!headers_sent()) {
        http_response_code(500);
    }
    
    require_once 'system_error.php';
    exit();
});

// Shutdown function to catch fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Log the fatal error
        error_log("Fatal Error: {$error['message']} in {$error['file']} on line {$error['line']}");
        
        if (!headers_sent()) {
            http_response_code(500);
        }
        
        require_once 'system_error.php';
        exit();
    }
});

// ========================================
// SESSION CONFIGURATION
// ========================================
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);

session_start();
require_once __DIR__ . '/includes/version.php';

// Wrap database connection in try-catch
try {
    require_once 'conn.php';
} catch (Exception $e) {
    error_log("Database connection failed in index.php: " . $e->getMessage());
    require_once 'system_error.php';
    exit();
}

require_once 'LoginSecurity.php';
require_once 'tracking.php';

// Initialize objects with error handling
try {
    $tracker = new UserTracker($conn);
    $loginSecurity = new LoginSecurity($conn);
} catch (Exception $e) {
    error_log("Failed to initialize security objects in index.php: " . $e->getMessage());
    require_once 'system_error.php';
    exit();
}


// Initialize objects
$tracker = new UserTracker($conn);
$loginSecurity = new LoginSecurity($conn);

// ========================================
// CSRF TOKEN GENERATION
// ========================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ========================================
// SESSION TIMEOUT MANAGEMENT
// ========================================
$timeout_duration = 1800; // 30 minutes in seconds

if (isset($_SESSION['LAST_ACTIVITY'])) {
    $elapsed_time = time() - $_SESSION['LAST_ACTIVITY'];

    if ($elapsed_time > $timeout_duration) {
        // Session has expired
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $_SESSION['notification'] = [
            'message' => 'Your session has expired due to inactivity. Please login again.',
            'type' => 'info'
        ];
        header("Location: index.php");
        exit();
    }
}

$_SESSION['LAST_ACTIVITY'] = time();

// ========================================
// SECURITY HEADERS
// ========================================
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

// ========================================
// HELPER FUNCTIONS
// ========================================

/**
 * Sanitize input to prevent XSS
 */
function sanitizeInput($input)
{
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect with notification
 */
function redirectWithNotification($url, $message, $type = 'success')
{
    $_SESSION['notification'] = [
        'message' => $message,
        'type' => $type
    ];
    header("Location: $url");
    exit();
}

/**
 * Rate limiting: prevent brute force from same IP
 */
function checkRateLimit($ip)
{
    global $conn;

$sql_rapid = "SELECT COUNT(*) as attempts FROM login_attempts 
              WHERE ip_address = ? 
              AND success = 0
              AND attempt_time > DATE_SUB(NOW(), INTERVAL 1 MINUTE)";
    $stmt = $conn->prepare($sql_rapid);
    if (!$stmt) {
        error_log("Rate limit query failed: " . $conn->error);
        return true; // Fail open to avoid blocking legitimate users
    }
    $stmt->bind_param("s", $ip);
    $stmt->execute();
    $result = $stmt->get_result();
    $rapid = $result->fetch_assoc();
    $stmt->close();

    if ($rapid['attempts'] >= 500) {
        error_log("SECURITY: Rapid-fire attack detected from IP: $ip");
        return false; // Block immediately
    }

 $sql_sustained = "SELECT COUNT(*) as attempts FROM login_attempts 
                  WHERE ip_address = ? 
                  AND success = 0
                  AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)";
    $stmt = $conn->prepare($sql_sustained);
    if (!$stmt) {
        error_log("Rate limit query failed: " . $conn->error);
        return true;
    }
    $stmt->bind_param("s", $ip);
    $stmt->execute();
    $result = $stmt->get_result();
    $sustained = $result->fetch_assoc();
    $stmt->close();

    if ($sustained['attempts'] >= 500) {
        error_log("SECURITY: Sustained attack detected from IP: $ip");
        return false;
    }

    return true; // Allow login attempt
}

/**
 * UPDATED: Check school subscription - DATES ONLY, NO DOMAIN CHECK
 * This allows each school's separate domain to work independently
 */
function getSchoolSubscriptionStatus($conn)
{
    $current_date = date('Y-m-d');

    // REMOVED: Domain check - only checking dates and active status
    $sql = "SELECT subscription_start_date, subscription_end_date, status 
            FROM school_subscriptions 
            WHERE status = 'active' 
            ORDER BY subscription_end_date DESC LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return ['is_active' => false, 'days_remaining' => null];
    }

    $row = $result->fetch_assoc();
    $subscription_start_date = $row['subscription_start_date'];
    $subscription_end_date = $row['subscription_end_date'];
    $subscription_status = $row['status'];

    $start_date_obj = new DateTime($subscription_start_date);
    $end_date_obj = new DateTime($subscription_end_date);
    $current_date_obj = new DateTime($current_date);

    $start_int = (int)$start_date_obj->format('Ymd');
    $end_int = (int)$end_date_obj->format('Ymd');
    $current_int = (int)$current_date_obj->format('Ymd');

    // Check if the current date is within the subscription period
    $is_active = ($current_int >= $start_int && $current_int <= $end_int);

    // Also check for 'active' status
    if (!$is_active || $subscription_status !== 'active') {
        return ['is_active' => false, 'days_remaining' => 0];
    }

    $interval = $current_date_obj->diff($end_date_obj);
    $days_remaining = $interval->days;

    return ['is_active' => true, 'days_remaining' => $days_remaining];
}

// ========================================
// HANDLE LOGIN - WITH ALL SECURITY FIXES
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ✓ CSRF VALIDATION
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        redirectWithNotification('index.php', 'Invalid security token. Please try again.', 'error');
    }

    // ========================================
    // HONEYPOT CHECK (Anti-Bot Protection)
    // ========================================
    if (!empty($_POST['website'])) {
        // This is likely a bot - the "website" field should be empty
        error_log("SECURITY: Bot detected - honeypot triggered from IP: " . ($client_ip ?? 'unknown'));
        sleep(2); // Slow down the bot
        redirectWithNotification('index.php', 'Invalid request. Please try again.', 'error');
    }

    // Sanitize user type input
    $userType = sanitizeInput($_POST['userType']);
    $error = '';
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $school_domain = $_SERVER['HTTP_HOST'];
    $log_ip = $client_ip ?? 'unknown';
    $log_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    // Check rate limit
    if (!checkRateLimit($client_ip)) {
        redirectWithNotification('index.php', 'Too many login attempts from this IP address. Please try again in 5 minutes.', 'error');
    }

    // Check school subscription BEFORE checking user credentials
    $subscription_status = getSchoolSubscriptionStatus($conn);

    if (!$subscription_status['is_active']) {
        // Allow users with the 'developer' role to bypass an expired/inactive subscription
        $is_developer = false;
        if ($userType === 'staff' && !empty($_POST['user_name'])) {
            $dev_username = sanitizeInput($_POST['user_name']);
            $dev_sql = "SELECT role FROM users WHERE user_name = ?";
            $dev_stmt = $conn->prepare($dev_sql);
            if ($dev_stmt) {
                $dev_stmt->bind_param("s", $dev_username);
                $dev_stmt->execute();
                $dev_result = $dev_stmt->get_result();
                if ($dev_result->num_rows === 1) {
                    $dev_user = $dev_result->fetch_assoc();
                    if ($dev_user['role'] === 'developer') {
                        $is_developer = true;
                    }
                }
                $dev_stmt->close();
            }
        }

        if (!$is_developer) {
            redirectWithNotification('expired_subscription.php', 'Your school subscription has expired or is suspended. Please contact administration.', 'error');
        }
    }

    // ========================================
    // STAFF LOGIN
    // ========================================
    if ($userType === 'staff') {
        // Sanitize inputs
        $user_name = sanitizeInput($_POST['user_name']);
        $password = $_POST['password']; // Don't sanitize passwords

        // Check if account is locked
        $lockStatus = $loginSecurity->isAccountLocked($user_name, 'staff');
        if ($lockStatus['locked']) {
            $loginSecurity->recordLoginAttempt($user_name, 'staff', 0, 'account_locked');
            $error = $lockStatus['reason'];
        } else {
            $sql = "SELECT user_id, user_name, password, role, email, staff_id FROM users WHERE user_name = ?";
            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                error_log("Database error in staff login: " . $conn->error);
                redirectWithNotification('index.php', 'System error. Please try again later.', 'error');
            }

            $stmt->bind_param("s", $user_name);

            if (!$stmt->execute()) {
                error_log("Query execution failed in staff login: " . $stmt->error);
                redirectWithNotification('index.php', 'System error. Please try again later.', 'error');
            }

            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();

                if (password_verify($password, $user['password'])) {
                    // Success - add security logging
                    error_log("SECURITY: Successful staff login - User: $user_name, IP: $log_ip");
                    // ✓ REGENERATE SESSION ID (prevent session fixation)
                    session_regenerate_id(true);

                    $loginSecurity->handleSuccessfulLogin($user_name, 'staff');

                    $update_sql = "UPDATE users SET last_login = NOW() WHERE user_id = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param("i", $user['user_id']);
                    $update_stmt->execute();
                    $update_stmt->close();

                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['user_name'] = $user['user_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['user_type'] = $user['role'];
                    $_SESSION['school_domain'] = $school_domain;
                    $_SESSION['staff_id'] = $user['staff_id'];

                    if ($subscription_status['days_remaining'] <= 15) {
                        $_SESSION['subscription_warning'] = $subscription_status['days_remaining'];
                    }

                    // ✓ NEW: Track login with email notification
                    $tracker->trackLogin($_SESSION['user_name'], 'staff', $user['email']);
                    echo $tracker->getLocationJS();

                    switch ($user['role']) {
                        case 'nurse':
                            redirectWithNotification('nurse_dashboard.php', 'Login successful!');
                            break;
                        case 'bursar':
                            redirectWithNotification('bursar_dashboard.php', 'Login successful!');
                            break;
                        case 'librarian':
                            redirectWithNotification('librarian_dashboard.php', 'Login successful!');
                            break;
                        default:
                            redirectWithNotification('dashboard.php', 'Login successful!');
                    }
                } else {
                    $loginSecurity->handleFailedLogin($user_name, 'staff', 'invalid_credentials');
                    error_log("SECURITY: Failed staff login - User: $user_name, IP: $log_ip, Reason: invalid_password");
                    $error = 'Invalid username or password';
                }
            } else {
                $loginSecurity->handleFailedLogin($user_name, 'staff', 'invalid_credentials');
                error_log("SECURITY: Failed staff login - User: $user_name, IP: $log_ip, Reason: user_not_found");
                $error = 'Invalid username or password';
            }
            $stmt->close();
        }
    }

    // ========================================
    // STUDENT LOGIN
    // ========================================
    elseif ($userType === 'student') {
        $student_id = sanitizeInput($_POST['student_id']);
        $password = $_POST['password'];

        $lockStatus = $loginSecurity->isAccountLocked($student_id, 'student');
        if ($lockStatus['locked']) {
            $loginSecurity->recordLoginAttempt($student_id, 'student', 0, 'account_locked');
            $error = $lockStatus['reason'];
        } else {
            $sql = "SELECT * FROM students WHERE student_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $student = $result->fetch_assoc();

                if ($student['first_login'] == 1 && $password === '123456') {
                    $loginSecurity->recordLoginAttempt($student_id, 'student', 1, 'first_login');

                    $_SESSION['temp_user_id'] = $student['student_id'];
                    $_SESSION['user_type'] = 'student';
                    $_SESSION['first_login'] = true;
                    $_SESSION['school_domain'] = $school_domain;
                    redirectWithNotification('reset_passwords.php', 'Please reset your password', 'info');
                } elseif (password_verify($password, $student['password'])) {
                    // ✓ REGENERATE SESSION ID
                    session_regenerate_id(true);

                    $loginSecurity->handleSuccessfulLogin($student_id, 'student');

                    $update_sql = "UPDATE students SET last_login = NOW(), first_login = 0 WHERE student_id = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param("s", $student_id);
                    $update_stmt->execute();
                    $update_stmt->close();

                    $_SESSION['user_id'] = $student['student_id'];
                    $_SESSION['user_type'] = 'student';
                    $_SESSION['class'] = $student['class'];
                    $_SESSION['stream'] = $student['stream'];
                    $_SESSION['school_domain'] = $school_domain;

                    if ($subscription_status['days_remaining'] <= 15) {
                        $_SESSION['subscription_warning'] = $subscription_status['days_remaining'];
                    }

                    // Students don't have email in students table, so no email notification
                    $tracker->trackLogin($_SESSION['user_id'], 'student', null);
                    echo $tracker->getLocationJS();

                    redirectWithNotification('student_dashboard.php', 'Login successful!');
                } else {
                    $loginSecurity->handleFailedLogin($student_id, 'student', 'invalid_credentials');
                    // ✓ GENERIC ERROR MESSAGE
                    $error = 'Invalid student ID or password';
                }
            } else {
                $loginSecurity->handleFailedLogin($student_id, 'student', 'invalid_credentials');
                // ✓ GENERIC ERROR MESSAGE
                $error = 'Invalid student ID or password';
            }
            $stmt->close();
        }
    }

    // ========================================
    // PARENT LOGIN
    // ========================================
    elseif ($userType === 'parent') {
        // ✓ SANITIZE PHONE NUMBER
        $phone = preg_replace('/[^0-9+]/', '', $_POST['phone']);
        $password = $_POST['password'];

        $lockStatus = $loginSecurity->isAccountLocked($phone, 'parent');
        if ($lockStatus['locked']) {
            $loginSecurity->recordLoginAttempt($phone, 'parent', 0, 'account_locked');
            $error = $lockStatus['reason'];
        } else {
            $sql = "SELECT * FROM parents WHERE phone = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $phone);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $parent = $result->fetch_assoc();

                if ($parent['first_login'] == 1 && $password === '123456') {
                    $loginSecurity->recordLoginAttempt($phone, 'parent', 1, 'first_login');

                    $_SESSION['temp_user_id'] = $parent['phone'];
                    $_SESSION['user_type'] = 'parent';
                    $_SESSION['first_login'] = true;
                    $_SESSION['school_domain'] = $school_domain;
                    redirectWithNotification('reset_passwords.php', 'Please reset your password', 'info');
                } elseif (password_verify($password, $parent['password'])) {
                    // ✓ REGENERATE SESSION ID
                    session_regenerate_id(true);

                    $loginSecurity->handleSuccessfulLogin($phone, 'parent');

                    $update_sql = "UPDATE parents SET last_login = NOW(), first_login = 0 WHERE phone = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param("s", $phone);
                    $update_stmt->execute();
                    $update_stmt->close();

                    $_SESSION['user_id'] = $parent['phone'];
                    $_SESSION['user_type'] = 'parent';
                    $_SESSION['student_id'] = $parent['student_id'];
                    $_SESSION['school_domain'] = $school_domain;

                    if ($subscription_status['days_remaining'] <= 15) {
                        $_SESSION['subscription_warning'] = $subscription_status['days_remaining'];
                    }

                    // ✓ NEW: Track login with email notification for parents
                    $parent_email = $parent['email'] ?? null;
                    $tracker->trackLogin($_SESSION['user_id'], 'parent', $parent_email);
                    echo $tracker->getLocationJS();

                    redirectWithNotification('parent_dashboard.php', 'Login successful!');
                } else {
                    $loginSecurity->handleFailedLogin($phone, 'parent', 'invalid_credentials');
                    // ✓ GENERIC ERROR MESSAGE
                    $error = 'Invalid phone number or password';
                }
            } else {
                $loginSecurity->handleFailedLogin($phone, 'parent', 'invalid_credentials');
                // ✓ GENERIC ERROR MESSAGE
                $error = 'Invalid phone number or password';
            }
            $stmt->close();
        }
    }

    if (!empty($error)) {
        $_SESSION['notification'] = [
            'message' => $error,
            'type' => 'error'
        ];
        header("Location: index.php");
        exit();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400..800&display=swap" rel="stylesheet">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>School Pilot - Complete School Management System | Teachers, Student & Parent Portal</title>
    <meta name="description" content="Access School Pilot's secure portal for comprehensive school management. Students, parents, and staff can track grades, attendance, assignments, and communicate seamlessly. Try free demo today!">
    <meta name="keywords" content="school management system, student information system, parent portal, gradebook, attendance tracking, school ERP, education software, student portal, academic management">
    <meta name="author" content="School Pilot Technologies">
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">

    <link rel="canonical" href="https://schoolpilot.org/login">
    <meta name="language" content="en-US">
    <meta name="geo.region" content="UG-C">
    <meta name="geo.placename" content="Kampala">

    <meta property="og:site_name" content="School Pilot">
    <meta property="og:title" content="School Pilot - Transform Your School Management Experience">
    <meta property="og:description" content="Streamline academic operations with our comprehensive school management platform. Real-time grade tracking, seamless communication, and powerful analytics for better educational outcomes.">
    <meta property="og:url" content="https://schoolpilot.org/login">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="en_US">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@SchoolPilotHQ">
    <meta name="twitter:title" content="School Pilot - Modern School Management Made Simple">

    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <meta name="theme-color" content="#2563eb">

    <style>
        :root {
            --primary-color: #1e8449;
            --primary-light: #27ae60;
            --primary-dark: #145a32;
            --accent-color: #2ecc71;
            --white: #ffffff;
            --light-gray: #f5f5f5;
            --gray: #e0e0e0;
            --dark-gray: #757575;
            --text-dark: #333333;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Sen", sans-serif !important;

        }

        body {
            background-color: var(--light-gray);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background-image: linear-gradient(135deg, rgba(30, 132, 73, 0.1) 0%, rgba(46, 204, 113, 0.1) 100%);
        }

        .container {
            display: flex;
            width: 90%;
            max-width: 1000px;
            height: 550px;
            box-shadow: var(--shadow);
            border-radius: 12px;
            overflow: hidden;
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .welcome-side {
            flex: 1;
            background-color: var(--primary-color);
            color: var(--white);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }

        .welcome-side::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('/api/placeholder/800/600');
            background-size: cover;
            background-position: center;
            opacity: 0.15;
        }

        .logo {
            margin-bottom: 2rem;
            position: relative;
            z-index: 1;
            animation: floatUp 1s ease-out forwards;
            opacity: 0;
            transform: translateY(20px);
        }

        @keyframes floatUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo-circle {
            width: 80px;
            height: 80px;
            background-color: var(--white);
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            transition: var(--transition);
        }

        .logo-circle:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .logo-text {
            font-size: 1.8rem;
            font-weight: 700;
            margin-top: 1rem;
            text-align: center;
        }

        .welcome-text {
            text-align: center;
            position: relative;
            z-index: 1;
            animation: floatUp 1s ease-out 0.3s forwards;
            opacity: 0;
            transform: translateY(20px);
        }

        .welcome-text h1 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .welcome-text p {
            font-size: 1.1rem;
            opacity: 0.9;
            line-height: 1.6;
        }

        .login-side {
            flex: 1;
            background-color: var(--white);
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-header {
            margin-bottom: 2rem;
            animation: slideIn 0.6s ease-out forwards;
            opacity: 0;
            transform: translateX(-20px);
        }

        @keyframes slideIn {
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .login-header h2 {
            color: var(--text-dark);
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .login-header p {
            color: var(--dark-gray);
        }

        .user-type-selector {
            display: flex;
            margin-bottom: 2rem;
            background-color: var(--light-gray);
            border-radius: 8px;
            animation: slideIn 0.6s ease-out 0.2s forwards;
            opacity: 0;
            transform: translateX(-20px);
        }

        .user-type-option {
            flex: 1;
            padding: 0.8rem;
            text-align: center;
            cursor: pointer;
            color: var(--dark-gray);
            border-radius: 8px;
            transition: var(--transition);
        }

        .user-type-option:hover:not(.active) {
            background-color: var(--gray);
        }

        .user-type-option.active {
            background-color: var(--primary-color);
            color: var(--white);
        }

        .form-group {
            margin-bottom: 1.5rem;
            animation: slideIn 0.6s ease-out 0.4s forwards;
            opacity: 0;
            transform: translateX(-20px);
        }

        .form-group:nth-child(2) {
            animation-delay: 0.5s;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-weight: 500;
        }

        input {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 1px solid var(--gray);
            border-radius: 6px;
            font-size: 1rem;
            transition: var(--transition);
        }

        input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(46, 204, 113, 0.2);
        }

        .password-container {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--dark-gray);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }

        .password-toggle:hover {
            color: var(--primary-color);
        }

        .form-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            animation: slideIn 0.6s ease-out 0.6s forwards;
            opacity: 0;
            transform: translateX(-20px);
        }

        .forgot-password {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .forgot-password:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .login-button {
            background-color: var(--primary-color);
            color: var(--white);
            border: none;
            padding: 1rem;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: var(--transition);
            animation: slideIn 0.6s ease-out 0.7s forwards;
            opacity: 0;
            transform: translateX(-20px);
            position: relative;
            overflow: hidden;
        }

        .login-button:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(20, 90, 50, 0.2);
        }

        .login-button .button-text {
            display: inline-block;
            transition: all 0.3s;
        }

        .login-button .loading-dots {
            position: absolute;
            left: 0;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            text-align: center;
            opacity: 0;
            transition: all 0.3s;
            display: flex;
            justify-content: center;
            gap: 4px;
        }

        .login-button.loading .button-text {
            opacity: 0;
            transform: translateY(20px);
        }

        .login-button.loading .loading-dots {
            opacity: 1;
        }

        .loading-dots span {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: white;
            animation: bounce 1.4s infinite ease-in-out both;
        }

        .loading-dots span:nth-child(1) {
            animation-delay: -0.32s;
        }

        .loading-dots span:nth-child(2) {
            animation-delay: -0.16s;
        }

        @keyframes bounce {

            0%,
            80%,
            100% {
                transform: scale(0);
            }

            40% {
                transform: scale(1);
            }
        }

        .help-text {
            text-align: center;
            margin-top: 2rem;
            color: var(--dark-gray);
            animation: slideIn 0.6s ease-out 0.8s forwards;
            opacity: 0;
            transform: translateX(-20px);
        }

        .help-text a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .help-text a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .app-version {
            margin-top: 20px;
            display: inline-block;
            font-size: 12px;
            color: #145a32 ;
            background: #f1f3f5;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        .login-form {
            display: none;
        }

        .login-form.active {
            display: block;
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px;
            border-radius: 5px;
            color: white;
            z-index: 1000;
            animation: slideIn 0.5s, fadeOut 0.5s 4.5s;
        }

        .success {
            background-color: #4CAF50;
        }

        .error {
            background-color: #f44336;
        }

        .info {
            background-color: #2196F3;
        }

       @media (max-width: 768px) {
    .container {
        flex-direction: column;
        height: auto;
        margin: 2rem 0;
    }

    .welcome-side {
        display: none;
    }

    .login-side {
        padding: 2rem 1.5rem;
    }
}
    </style>
</head>

<body>
    <div class="container">
        <div class="welcome-side">
            <div class="logo">
                <div class="logo-circle">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 3L20 7V17L12 21L4 17V7L12 3Z" stroke="#1e8449" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M12 12L20 7" stroke="#1e8449" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M12 12V21" stroke="#1e8449" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M12 12L4 7" stroke="#1e8449" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
                <div class="logo-text">School Pilot</div>
            </div>
            <div class="welcome-text">
                <h1>Welcome Back!</h1>
                <p>Access your School Pilot portal to manage, monitor, and engage with your educational journey.</p>
            </div>
        </div>

        <div class="login-side">
            <div class="login-header">
                <h2>Sign In</h2>
                <p>Please sign in to continue to School Pilot</p>
            </div>

            <div class="user-type-selector">
            </div>

            <form class="login-form active" id="staff-form" action="index.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="userType" value="staff">

                <!-- Honeypot field (Anti-Bot Protection) - Hidden from real users -->
                <div style="position: absolute; left: -9999px; opacity: 0; pointer-events: none;" aria-hidden="true">
                    <label for="website">Website (leave blank)</label>
                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                </div>

                <div class="form-group">
                    <label for="staff-username">Username</label>
                    <input type="text" id="staff-username" name="user_name" placeholder="Enter your username" required autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="staff-password">Password</label>
                    <div class="password-container">
                        <input type="password"
                            id="staff-password"
                            name="password"
                            placeholder="Enter your password"
                            required
                            autocomplete="current-password"
                            minlength="8"
                            maxlength="128"
                            title="Password must be at least 8 characters">
                        <button type="button" class="password-toggle" onclick="togglePassword('staff-password', this)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="eye-icon">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="form-footer">
                    <a href="forgot_password.php" class="forgot-password">Forgot Password?</a>
                </div>
                <button type="submit" class="login-button">
                    <span class="button-text">Let me in</span>
                    <span class="loading-dots">
                        <span></span>
                        <span></span>
                        <span></span>
                    </span>
                </button>
            </form>

            <div class="help-text">
                <span class="app-version">
                     v<?= APP_VERSION ?>
                </span>
            </div>
        </div>
    </div>

    <script>
        function togglePassword(inputId, button) {
            const passwordInput = document.getElementById(inputId);
            const eyeIcon = button.querySelector('.eye-icon');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.innerHTML = `
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                    <line x1="1" y1="1" x2="23" y2="23"></line>
                `;
            } else {
                passwordInput.type = 'password';
                eyeIcon.innerHTML = `
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                `;
            }
        }

        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            document.body.appendChild(notification);

            const audio = new Audio(type === 'success' ? 'sounds/success.mp3' : 'sounds/error.wav');
            audio.play().catch(e => {
                console.log('Audio play failed:', e);
            });

            setTimeout(() => {
                notification.remove();
            }, 5000);
        }

        document.addEventListener('DOMContentLoaded', function() {
            <?php
            if (isset($_SESSION['notification'])) {
                echo 'showNotification("' . addslashes($_SESSION['notification']['message']) . '", "' . $_SESSION['notification']['type'] . '");';
                unset($_SESSION['notification']);
            }
            ?>
        });

        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (this.checkValidity()) {
                    const button = this.querySelector('.login-button');
                    button.classList.add('loading');
                    button.disabled = true;
                }
            });
        });



        // Custom cursor message element
        const cursorMessage = document.createElement('div');
        cursorMessage.id = 'cursor-message';
        cursorMessage.style.cssText = `
    position: fixed;
    background: #145a32;
    color: white;
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    pointer-events: none;
    z-index: 10000;
    display: none;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    animation: shake 0.5s;
`;
        cursorMessage.textContent = 'Right-click disabled ';
        document.body.appendChild(cursorMessage);

        // Add shake animation
        const style = document.createElement('style');
        style.textContent = `
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-10px); }
        75% { transform: translateX(10px); }
    }
    .no-select {
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
        user-select: none;
    }
`;
        document.head.appendChild(style);

        // Disable right-click with cursor message
        document.addEventListener('contextmenu', function(e) {
            e.preventDefault();

            // Show message at cursor position
            cursorMessage.style.left = e.pageX + 15 + 'px';
            cursorMessage.style.top = e.pageY + 15 + 'px';
            cursorMessage.style.display = 'block';

            // Hide after 3 seconds
            setTimeout(() => {
                cursorMessage.style.display = 'none';
            }, 3000);

            return false;
        });

        // Disable text selection on entire page except input fields
        document.body.classList.add('no-select');
        document.querySelectorAll('input:not([type="password"]), textarea').forEach(el => {
            el.classList.remove('no-select');
            el.style.userSelect = 'text';
        });

        // Disable copying in password fields only
        document.querySelectorAll('input[type="password"]').forEach(passwordField => {
            passwordField.addEventListener('copy', function(e) {
                e.preventDefault();
                return false;
            });

            passwordField.addEventListener('cut', function(e) {
                e.preventDefault();
                return false;
            });

            passwordField.addEventListener('paste', function(e) {
                e.preventDefault();
                return false;
            });
        });

        // Comprehensive keyboard shortcuts blocking
        document.addEventListener('keydown', function(e) {
            // F12 - Developer Tools
            if (e.keyCode === 123) {
                e.preventDefault();
                return false;
            }

            // Ctrl+Shift+I - Developer Tools
            if (e.ctrlKey && e.shiftKey && e.keyCode === 73) {
                e.preventDefault();
                return false;
            }

            // Ctrl+Shift+J - Console
            if (e.ctrlKey && e.shiftKey && e.keyCode === 74) {
                e.preventDefault();
                return false;
            }

            // Ctrl+Shift+C - Inspect Element
            if (e.ctrlKey && e.shiftKey && e.keyCode === 67) {
                e.preventDefault();
                return false;
            }

            // Ctrl+U - View Source
            if (e.ctrlKey && e.keyCode === 85) {
                e.preventDefault();
                return false;
            }

            // Ctrl+S - Save Page
            if (e.ctrlKey && e.keyCode === 83) {
                e.preventDefault();
                return false;
            }

            // Ctrl+P - Print
            if (e.ctrlKey && e.keyCode === 80) {
                e.preventDefault();
                return false;
            }

            // Ctrl+Shift+K - Firefox Console
            if (e.ctrlKey && e.shiftKey && e.keyCode === 75) {
                e.preventDefault();
                return false;
            }
        });

        // Disable drag and drop
        document.addEventListener('dragstart', function(e) {
            e.preventDefault();
            return false;
        });

        // Disable text selection via mouse
        document.addEventListener('selectstart', function(e) {
            if (e.target.tagName !== 'INPUT' || e.target.type === 'password') {
                e.preventDefault();
                return false;
            }
        });

        // Prevent image dragging
        document.querySelectorAll('img, svg').forEach(img => {
            img.addEventListener('dragstart', function(e) {
                e.preventDefault();
                return false;
            });
        });

        // Disable Ctrl+A (Select All) on password fields
        document.querySelectorAll('input[type="password"]').forEach(passwordField => {
            passwordField.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.keyCode === 65) {
                    e.preventDefault();
                    return false;
                }
            });
        });

        // Add warning message in console
        console.log('%c⚠️ SECURITY WARNING', 'color: red; font-size: 40px; font-weight: bold;');
        console.log('%cUsing this console may compromise your account security.\nIf someone told you to copy/paste something here, it is likely a scam.', 'color: red; font-size: 16px;');
    </script>
</body>

</html>