<?php

// Session security settings
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);

session_start();
require_once 'conn.php';

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// PHPMailer Setup with Composer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

/**
 * Rate limiting for password reset requests
 * Max 5 requests per 15 minutes per IP
 */
function checkPasswordResetRateLimit($conn, $ip_address) {
    $sql = "SELECT COUNT(*) as attempts FROM password_reset_attempts 
            WHERE ip_address = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $ip_address);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['attempts'] < 5; // Max 5 attempts per 15 minutes
}

/**
 * Record password reset attempt
 */
function recordPasswordResetAttempt($conn, $email, $ip_address, $success) {
    $sql = "INSERT INTO password_reset_attempts (email, ip_address, success, attempt_time) 
            VALUES (?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $email, $ip_address, $success);
    $stmt->execute();
    $stmt->close();
}

function redirectWithNotification($url, $message, $type = 'success')
{
    if (ob_get_level() > 0) {
        ob_clean();
    }
    $_SESSION['notification'] = [
        'message' => $message,
        'type' => $type
    ];
    header("Location: $url");
    exit();
}

// Handle email submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ✓ CSRF VALIDATION
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        redirectWithNotification('forgot_password.php', 'Invalid security token. Please try again.', 'error');
    }

    $email = trim($_POST['email']);
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // ✓ RATE LIMIT CHECK
    if (!checkPasswordResetRateLimit($conn, $ip_address)) {
        recordPasswordResetAttempt($conn, $email, $ip_address, 0);
        redirectWithNotification('forgot_password.php', 'Too many password reset requests. Please try again in 15 minutes.', 'error');
    }

    // Validate email format
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        recordPasswordResetAttempt($conn, $email, $ip_address, 0);
        redirectWithNotification('forgot_password.php', 'Please enter a valid email address.', 'error');
    }

    // Check if user exists
    $stmt = $conn->prepare("SELECT user_id, user_name FROM users WHERE email = ? AND role != 'student' AND role != 'parent'");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user) {
        // Generate 6-digit reset code
        $reset_code = sprintf("%06d", mt_rand(0, 999999));
        $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        // Store reset code
        $stmt_reset = $conn->prepare("INSERT INTO password_resets (email, reset_code, expires_at) VALUES (?, ?, ?) 
                                      ON DUPLICATE KEY UPDATE reset_code = VALUES(reset_code), expires_at = VALUES(expires_at), used = 0");
        $stmt_reset->bind_param("sss", $email, $reset_code, $expires_at);

        if ($stmt_reset->execute()) {
            $mail = new PHPMailer(true);

            try {
                // Server settings
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'robertsontumwesige1@gmail.com';
                $mail->Password   = 'oenz unar mzyr uozw';             
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;
                $mail->SMTPDebug  = SMTP::DEBUG_OFF;

                // Recipients
                $mail->setFrom('security@schoolpilot.com', 'School Pilot Security');
                $mail->addAddress($email, $user['user_name']);

                // Email Body
                $mail->isHTML(true);
                $mail->Subject = 'Your Password Reset Code for School Pilot';
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
                            .code-box { background-color: #e8f5e8; border: 2px dashed #27ae60; color: #145a32; font-size: 32px; font-weight: bold; text-align: center; padding: 15px; margin: 20px 0; letter-spacing: 5px; border-radius: 5px; }
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
                                <p>Hello " . htmlspecialchars($user['user_name']) . ",</p>
                                <p>We received a request to reset the password for your account. Please use the verification code below to proceed.</p>
                                <div class='code-box'>
                                    $reset_code
                                </div>
                                <p><strong>This code is valid for 15 minutes.</strong></p>
                                <p>If you did not request a password reset, please ignore this email or contact support if you have concerns.</p>
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
                $mail->AltBody = "Hello " . htmlspecialchars($user['user_name']) . ",\n\nYour password reset code for School Pilot is: $reset_code\n\nThis code is valid for 15 minutes. If you did not request a password reset, please ignore this email.\n\nThank you,\nThe School Pilot Team";

                $mail->send();
                
                recordPasswordResetAttempt($conn, $email, $ip_address, 1);
                $_SESSION['reset_email'] = $email;
                redirectWithNotification('verify_reset_code.php', 'A reset code has been sent to your email.', 'success');
            } catch (Exception $e) {
                recordPasswordResetAttempt($conn, $email, $ip_address, 0);
                redirectWithNotification('forgot_password.php', 'Could not send email. Please try again later or contact support.', 'error');
            }
        } else {
            die("Database error on inserting reset code: " . $stmt_reset->error);
        }
        $stmt_reset->close();
    } else {
        // ✓ PREVENT USER ENUMERATION: Always show generic success message
        recordPasswordResetAttempt($conn, $email, $ip_address, 0);
        redirectWithNotification('forgot_password.php', "If a matching account was found, an email has been sent to reset your password.", 'info');
    }
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Pilot - Forgot Password</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@300..700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1e8449;
            --primary-light: #27ae60;
            --primary-dark: #145a32;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --gray: #e0e0e0;
            --text-dark: #333333;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Quicksand", sans-serif;
        }

        body {
            font-family: "Quicksand", sans-serif;
            background: linear-gradient(135deg, var(--light-gray) 0%, #e8f5e8 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--shadow);
            overflow: hidden;
            width: 100%;
            max-width: 450px;
            animation: slideUp 0.6s ease-out;
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

        .header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: var(--white);
            padding: 40px 30px;
            text-align: center;
        }

        .header-icon {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .header h1 {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .header p {
            opacity: 0.9;
            font-size: 1rem;
        }

        .form-container {
            padding: 40px 30px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-dark);
            font-weight: 500;
        }

        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--gray);
            border-radius: 10px;
            font-size: 1rem;
            transition: var(--transition);
            background: var(--light-gray);
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            background: var(--white);
            box-shadow: 0 0 0 4px rgba(30, 132, 73, 0.1);
        }

        .btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: var(--white);
            border: none;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(30, 132, 73, 0.2);
        }

        .back-link {
            text-align: center;
            margin-top: 25px;
        }

        .back-link a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .back-link a:hover {
            color: var(--primary-dark);
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 20px;
            border-radius: 10px;
            color: white;
            z-index: 1000;
            animation: slideInRight 0.5s ease, slideOutRight 0.5s 4.5s ease forwards;
            box-shadow: var(--shadow);
        }

        .notification.success,
        .notification.info {
            background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
        }

        .notification.error {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(100px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideOutRight {
            to {
                opacity: 0;
                transform: translateX(100px);
            }
        }

        .btn.loading {
            pointer-events: none;
        }

        .btn.loading .btn-text {
            opacity: 0;
        }

        .btn .loader {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.5);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .btn.loading .loader {
            opacity: 1;
        }

        @keyframes spin {
            to {
                transform: translate(-50%, -50%) rotate(360deg);
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <div class="header-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>
                </svg>
            </div>
            <h1>Forgot Password?</h1>
            <p>Enter your email to receive a reset code.</p>
        </div>

        <div class="form-container">
            <form method="POST" action="forgot_password.php" id="forgotPasswordForm">
                <!-- ✓ CSRF TOKEN -->
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="form-group">
                    <label for="email">Staff Email Address</label>
                    <input type="email" id="email" name="email" required placeholder="Enter a valid email you registered">
                </div>
                <button type="submit" name="send_code" class="btn" id="submitBtn">
                    <span class="btn-text">Send Reset Code</span>
                    <div class="loader"></div>
                </button>
            </form>
            <div class="back-link">
                <a href="index.php">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 12H5"></path>
                        <path d="M12 19l-7-7 7-7"></path>
                    </svg>
                    Back to Login
                </a>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php
            if (isset($_SESSION['notification'])) {
                echo 'showNotification("' . addslashes($_SESSION['notification']['message']) . '", "' . $_SESSION['notification']['type'] . '");';
                unset($_SESSION['notification']);
            }
            ?>
        });

        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            document.body.appendChild(notification);
            setTimeout(() => {
                notification.remove();
            }, 5000);
        }

        document.getElementById('forgotPasswordForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('submitBtn');
            const emailInput = document.getElementById('email');
            if (emailInput.value.trim() === '' || !emailInput.checkValidity()) {
                return;
            }
            btn.classList.add('loading');
            btn.disabled = true;
        });
    </script>
</body>

</html>