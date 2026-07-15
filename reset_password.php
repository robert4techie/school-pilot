<?php
require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Reset password");


// Add headers to prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

$errors = [];
$success = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize inputs
    $current_password = trim($_POST['current-password'] ?? '');
    $new_password = trim($_POST['new-password'] ?? '');
    $confirm_password = trim($_POST['confirm-password'] ?? '');

    // Get username from session
    $username = $_SESSION['user_name'];

    // Validate current password
    if (empty($current_password)) {
        $errors['current_password'] = "Current password is required.";
    } else {
        // Fetch current password hash from database
        $stmt = $conn->prepare("SELECT password FROM users WHERE user_name = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (!password_verify($current_password, $user['password'])) {
                $errors['current_password'] = "Current password is incorrect.";
            }
        } else {
            $errors['current_password'] = "User not found.";
        }
        $stmt->close();
    }

    // Validate new password
    if (empty($new_password)) {
        $errors['new_password'] = "New password is required.";
    } elseif (strlen($new_password) < 8) {
        $errors['new_password'] = "Password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Z]/', $new_password)) {
        $errors['new_password'] = "Password must contain at least one uppercase letter.";
    } elseif (!preg_match('/[a-z]/', $new_password)) {
        $errors['new_password'] = "Password must contain at least one lowercase letter.";
    } elseif (!preg_match('/[0-9]/', $new_password)) {
        $errors['new_password'] = "Password must contain at least one number.";
    } elseif (!preg_match('/[\W]/', $new_password)) {
        $errors['new_password'] = "Password must contain at least one special character.";
    } elseif ($new_password === $current_password) {
        $errors['new_password'] = "New password must be different from current password.";
    }

    // Validate confirm password
    if (empty($confirm_password)) {
        $errors['confirm_password'] = "Please confirm your new password.";
    } elseif ($new_password !== $confirm_password) {
        $errors['confirm_password'] = "Passwords do not match.";
    }

    // If no errors, update password
    if (empty($errors)) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_name = ?");
        $stmt->bind_param("ss", $hashed_password, $username);

        if ($stmt->execute()) {
            $success = true;
            // Log password change
            error_log("Password changed successfully for user: " . $username);
        } else {
            $errors['database'] = "There was an error resetting your password. Please try again.";
        }
        $stmt->close();
    }

    // THIS IS THE LINE THAT WAS REMOVED FROM HERE
    // $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Security Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1e8449;
            --primary-light: #27ae60;
            --primary-dark: #145a32;
            --accent-color: #2ecc71;
            --secondary-color: #10b981;
            --error-color: #ef4444;
            --warning-color: #f59e0b;
            --success-color: #22c55e;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --text-tertiary: #9ca3af;
            --bg-primary: #ffffff;
            --bg-secondary: #f9fafb;
            --bg-tertiary: #f3f4f6;
            --border-color: #e5e7eb;
            --border-focus: #1e8449;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --radius-sm: 6px;
            --radius-md: 8px;
            --radius-lg: 12px;
            --radius-xl: 16px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
            padding: 20px;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="0.5" fill="rgba(30,132,73,0.1)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>') repeat;
            pointer-events: none;
            z-index: -1;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 40px 20px;
        }

        .reset-card {
            background: var(--bg-primary);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            width: 100%;
            max-width: 900px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(30, 132, 73, 0.1);
            position: relative;
        }

        .reset-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--accent-color));
        }

        .card-header {
            background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-tertiary) 100%);
            padding: 30px 40px 20px;
            text-align: center;
            border-bottom: 1px solid var(--border-color);
        }

        .card-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 10px;
            letter-spacing: -0.025em;
        }

        .card-header p {
            color: var(--text-secondary);
            font-size: 1.1rem;
            font-weight: 400;
        }

        .card-body {
            padding: 50px;
        }

        .form-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-bottom: 40px;
        }

        .form-section {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-title i {
            color: var(--primary-color);
            font-size: 1.1rem;
        }

        .form-group {
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-primary);
            font-size: 0.95rem;
        }

        .input-wrapper {
            position: relative;
        }

        .form-control {
            width: 100%;
            padding: 14px 50px 14px 16px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 1rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background-color: var(--bg-primary);
            color: var(--text-primary);
            font-family: inherit;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px rgba(30, 132, 73, 0.1);
            transform: translateY(-1px);
        }

        .form-control:hover {
            border-color: var(--text-tertiary);
        }

        .form-control.error {
            border-color: var(--error-color);
            background-color: rgba(239, 68, 68, 0.05);
        }

        .form-control.success {
            border-color: var(--success-color);
            background-color: rgba(34, 197, 94, 0.05);
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-tertiary);
            cursor: pointer;
            font-size: 1.1rem;
            padding: 4px;
            border-radius: var(--radius-sm);
            transition: all 0.2s;
        }

        .password-toggle:hover {
            color: var(--primary-color);
            background-color: rgba(30, 132, 73, 0.1);
        }

        .password-strength {
            margin-top: 12px;
            background-color: var(--bg-tertiary);
            border-radius: var(--radius-sm);
            overflow: hidden;
            height: 6px;
            position: relative;
        }

        .strength-meter {
            height: 100%;
            width: 0;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: var(--radius-sm);
            position: relative;
        }

        .strength-meter::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% {
                transform: translateX(-100%);
            }

            100% {
                transform: translateX(100%);
            }
        }

        .strength-weak {
            background: linear-gradient(90deg, #ef4444, #f87171);
            width: 25%;
        }

        .strength-medium {
            background: linear-gradient(90deg, #f59e0b, #fbbf24);
            width: 60%;
        }

        .strength-strong {
            background: linear-gradient(90deg, var(--accent-color), var(--primary-light));
            width: 100%;
        }

        .password-hints {
            margin-top: 16px;
            padding: 16px;
            background-color: var(--bg-secondary);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
        }

        .password-hints h4 {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 12px;
        }

        .password-hints ul {
            list-style: none;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .password-hints li {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            padding: 4px 0;
            transition: all 0.2s;
        }

        .password-hints li i {
            font-size: 0.8rem;
            width: 12px;
        }

        .password-hints .valid {
            color: var(--success-color);
        }

        .password-hints .invalid {
            color: var(--text-tertiary);
        }

        .match-indicator {
            margin-top: 12px;
            padding: 10px 14px;
            border-radius: var(--radius-md);
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s;
            display: none;
        }

        .match-indicator.show {
            display: block;
        }

        .match-indicator.success {
            background-color: rgba(34, 197, 94, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(34, 197, 94, 0.2);
        }

        .match-indicator.error {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--error-color);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .submit-section {
            grid-column: 1 / -1;
            display: flex;
            flex-direction: column;
            gap: 20px;
            align-items: center;
            margin-top: 20px;
        }

        .btnn {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            color: white;
            border: none;
            border-radius: var(--radius-md);
            padding: 16px 40px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            min-width: 200px;
            box-shadow: var(--shadow-md);
        }

        .btnn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .btnn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
        }

        .btnn:hover::before {
            left: 100%;
        }

        .btnn:active {
            transform: translateY(0);
        }

        .btnn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .error-message {
            color: var(--error-color);
            font-size: 0.85rem;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
        }

        .error-message i {
            font-size: 0.8rem;
        }

        .notification {
            position: fixed;
            top: 30px;
            right: 30px;
            padding: 16px 24px;
            border-radius: var(--radius-md);
            color: white;
            font-weight: 500;
            box-shadow: var(--shadow-xl);
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 300px;
            backdrop-filter: blur(10px);
        }

        .notification.show {
            opacity: 1;
            transform: translateX(0);
        }

        .notification.success {
            background: linear-gradient(135deg, var(--success-color), var(--accent-color));
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .notification.error {
            background: linear-gradient(135deg, var(--error-color), #dc2626);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .notification i {
            font-size: 1.2rem;
        }

        .loading-spinner {
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            display: none;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .shake {
            animation: shake 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            25% {
                transform: translateX(-5px);
            }

            75% {
                transform: translateX(5px);
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .form-layout {
                grid-template-columns: 1fr;
                gap: 30px;
            }

            .card-header {
                padding: 30px 30px 20px;
            }

            .card-header h2 {
                font-size: 1.5rem;
            }

            .card-body {
                padding: 30px;
            }

            .password-hints ul {
                grid-template-columns: 1fr;
            }

            .notification {
                right: 20px;
                left: 20px;
                min-width: auto;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 20px 10px;
            }

            .card-header {
                padding: 20px;
            }

            .card-header h2 {
                font-size: 1.75rem;
            }

            .card-body {
                padding: 20px;
            }

            .form-control {
                padding: 12px 45px 12px 14px;
            }
        }

        /* Enhanced animations */
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

        .reset-card {
            animation: fadeInUp 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .form-group {
            animation: fadeInUp 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .form-group:nth-child(2) {
            animation-delay: 0.1s;
        }

        .form-group:nth-child(3) {
            animation-delay: 0.2s;
        }

        .form-group:nth-child(4) {
            animation-delay: 0.3s;
        }
    </style>
</head>

<body>
    <?php require_once 'nav.php' ?>

    <!-- Notification System -->
    <div id="notification" class="notification">
        <i id="notification-icon"></i>
        <span id="notification-message"></span>
    </div>

    <div class="container">
        <div class="reset-card">
            <div class="card-header">
                <h2><i class="fas fa-shield-alt"></i> Reset Password</h2>
                <p>Update your password to keep your account secure</p>
            </div>

            <div class="card-body">
                <form id="resetPasswordForm" action="reset_password.php" method="POST">
                    <div class="form-layout">
                        <!-- Current Password Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-key"></i>
                                Current Password
                            </div>

                            <div class="form-group">
                                <label for="current-password">Enter Current Password</label>
                                <div class="input-wrapper">
                                    <input type="password"
                                        id="current-password"
                                        name="current-password"
                                        class="form-control <?php echo isset($errors['current_password']) ? 'error' : ''; ?>"
                                        placeholder="Enter your current password"
                                        required>
                                    <button type="button" class="password-toggle" onclick="togglePassword('current-password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <?php if (isset($errors['current_password'])): ?>
                                    <div class="error-message">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <?php echo $errors['current_password']; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- New Password Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-lock"></i>
                                New Password
                            </div>

                            <div class="form-group">
                                <label for="new-password">Create New Password</label>
                                <div class="input-wrapper">
                                    <input type="password"
                                        id="new-password"
                                        name="new-password"
                                        class="form-control <?php echo isset($errors['new_password']) ? 'error' : ''; ?>"
                                        placeholder="Enter new password"
                                        required
                                        oninput="checkPasswordStrength()">
                                    <button type="button" class="password-toggle" onclick="togglePassword('new-password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="password-strength">
                                    <div id="strength-meter" class="strength-meter"></div>
                                </div>
                                <?php if (isset($errors['new_password'])): ?>
                                    <div class="error-message">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <?php echo $errors['new_password']; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="confirm-password">Confirm New Password</label>
                                <div class="input-wrapper">
                                    <input type="password"
                                        id="confirm-password"
                                        name="confirm-password"
                                        class="form-control <?php echo isset($errors['confirm_password']) ? 'error' : ''; ?>"
                                        placeholder="Confirm new password"
                                        required>
                                    <button type="button" class="password-toggle" onclick="togglePassword('confirm-password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div id="match-indicator" class="match-indicator">
                                    <i class="fas fa-check-circle"></i>
                                    <span id="match-message">Passwords match</span>
                                </div>
                                <?php if (isset($errors['confirm_password'])): ?>
                                    <div class="error-message">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <?php echo $errors['confirm_password']; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Password Requirements -->
                        <div class="form-section">
                            <div class="password-hints">
                                <h4><i class="fas fa-info-circle"></i> Password Requirements</h4>
                                <ul>
                                    <li id="length-hint" class="invalid">
                                        <i class="fas fa-times"></i>
                                        At least 8 characters
                                    </li>
                                    <li id="uppercase-hint" class="invalid">
                                        <i class="fas fa-times"></i>
                                        One uppercase letter
                                    </li>
                                    <li id="lowercase-hint" class="invalid">
                                        <i class="fas fa-times"></i>
                                        One lowercase letter
                                    </li>
                                    <li id="number-hint" class="invalid">
                                        <i class="fas fa-times"></i>
                                        One number
                                    </li>
                                    <li id="special-hint" class="invalid">
                                        <i class="fas fa-times"></i>
                                        One special character
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- Submit Section -->
                        <div class="submit-section">
                            <button type="submit" class="btnn" id="submit-btn">
                                <span id="btn-text">
                                    <i class="fas fa-shield-alt"></i>
                                    Update Password
                                </span>
                                <div class="loading-spinner" id="loading-spinner"></div>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Enhanced password toggle functionality
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const toggleBtn = field.nextElementSibling;
            const icon = toggleBtn.querySelector('i');

            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
                toggleBtn.setAttribute('aria-label', 'Hide password');
            } else {
                field.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
                toggleBtn.setAttribute('aria-label', 'Show password');
            }
        }

        // Enhanced password strength checker
        function checkPasswordStrength() {
            const password = document.getElementById('new-password').value;
            const meter = document.getElementById('strength-meter');
            const hints = {
                length: document.getElementById('length-hint'),
                uppercase: document.getElementById('uppercase-hint'),
                lowercase: document.getElementById('lowercase-hint'),
                number: document.getElementById('number-hint'),
                special: document.getElementById('special-hint')
            };

            // Reset classes
            meter.className = 'strength-meter';
            Object.values(hints).forEach(hint => {
                const icon = hint.querySelector('i');
                hint.classList.remove('valid');
                hint.classList.add('invalid');
                icon.classList.replace('fa-check', 'fa-times');
            });

            // Check password requirements
            const checks = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[\W_]/.test(password)
            };

            // Update hints
            Object.keys(checks).forEach(key => {
                if (checks[key]) {
                    const hint = hints[key];
                    const icon = hint.querySelector('i');
                    hint.classList.replace('invalid', 'valid');
                    icon.classList.replace('fa-times', 'fa-check');
                }
            });

            // Calculate strength
            const strength = Object.values(checks).filter(Boolean).length;

            // Update strength meter
            if (strength <= 2) {
                meter.classList.add('strength-weak');
            } else if (strength <= 4) {
                meter.classList.add('strength-medium');
            } else {
                meter.classList.add('strength-strong');
            }
            // Update new password field styling
            const newPasswordField = document.getElementById('new-password');
            if (password.length > 0) {
                if (strength >= 5) {
                    newPasswordField.classList.remove('error');
                    newPasswordField.classList.add('success');
                } else {
                    newPasswordField.classList.remove('success');
                    newPasswordField.classList.add('error');
                }
            } else {
                newPasswordField.classList.remove('success', 'error');
            }

            // Check password match when typing
            checkPasswordMatch();
        }

        // Enhanced password match checker
        function checkPasswordMatch() {
            const newPassword = document.getElementById('new-password').value;
            const confirmPassword = document.getElementById('confirm-password').value;
            const matchIndicator = document.getElementById('match-indicator');
            const matchMessage = document.getElementById('match-message');
            const matchIcon = matchIndicator.querySelector('i');
            const confirmField = document.getElementById('confirm-password');

            if (confirmPassword.length > 0) {
                matchIndicator.classList.add('show');

                if (newPassword === confirmPassword) {
                    matchIndicator.classList.remove('error');
                    matchIndicator.classList.add('success');
                    matchMessage.textContent = 'Passwords match';
                    matchIcon.classList.replace('fa-times-circle', 'fa-check-circle');
                    confirmField.classList.remove('error');
                    confirmField.classList.add('success');
                } else {
                    matchIndicator.classList.remove('success');
                    matchIndicator.classList.add('error');
                    matchMessage.textContent = 'Passwords do not match';
                    matchIcon.classList.replace('fa-check-circle', 'fa-times-circle');
                    confirmField.classList.remove('success');
                    confirmField.classList.add('error');
                }
            } else {
                matchIndicator.classList.remove('show');
                confirmField.classList.remove('success', 'error');
            }
        }

        // Enhanced notification system
        function showNotification(message, type = 'success', duration = 5000) {
            const notification = document.getElementById('notification');
            const icon = document.getElementById('notification-icon');
            const messageSpan = document.getElementById('notification-message');

            // Set icon based on type
            icon.className = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle';
            messageSpan.textContent = message;

            // Reset classes and add type
            notification.className = `notification ${type}`;

            // Show notification
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);

            // Hide notification after duration
            setTimeout(() => {
                notification.classList.remove('show');
            }, duration);
        }

        // Enhanced form validation
        function validateForm() {
            const currentPassword = document.getElementById('current-password').value;
            const newPassword = document.getElementById('new-password').value;
            const confirmPassword = document.getElementById('confirm-password').value;
            let isValid = true;

            // Clear previous errors
            document.querySelectorAll('.form-control').forEach(field => {
                field.classList.remove('error');
            });

            // Validate current password
            if (currentPassword.length === 0) {
                document.getElementById('current-password').classList.add('error');
                isValid = false;
            }

            // Validate new password
            const passwordChecks = {
                length: newPassword.length >= 8,
                uppercase: /[A-Z]/.test(newPassword),
                lowercase: /[a-z]/.test(newPassword),
                number: /[0-9]/.test(newPassword),
                special: /[\W_]/.test(newPassword)
            };

            const passwordValid = Object.values(passwordChecks).every(Boolean);
            if (!passwordValid || newPassword.length === 0) {
                document.getElementById('new-password').classList.add('error');
                isValid = false;
            }

            // Validate confirm password
            if (confirmPassword !== newPassword || confirmPassword.length === 0) {
                document.getElementById('confirm-password').classList.add('error');
                isValid = false;
            }

            // Check if new password is different from current
            if (newPassword === currentPassword && newPassword.length > 0) {
                document.getElementById('new-password').classList.add('error');
                showNotification('New password must be different from current password', 'error');
                isValid = false;
            }

            return isValid;
        }

        // Enhanced form submission with loading state
        document.getElementById('resetPasswordForm').addEventListener('submit', function(e) {
            e.preventDefault();

            // Validate form
            if (!validateForm()) {
                // Shake animation for invalid form
                this.classList.add('shake');
                setTimeout(() => {
                    this.classList.remove('shake');
                }, 500);
                return;
            }

            // Show loading state
            const submitBtn = document.getElementById('submit-btn');
            const btnText = document.getElementById('btn-text');
            const loadingSpinner = document.getElementById('loading-spinner');

            submitBtn.disabled = true;
            btnText.style.display = 'none';
            loadingSpinner.style.display = 'block';

            // Simulate processing delay for better UX
            setTimeout(() => {
                this.submit();
            }, 1000);
        });

        // Event listeners for real-time validation
        document.getElementById('new-password').addEventListener('input', checkPasswordStrength);
        document.getElementById('confirm-password').addEventListener('input', checkPasswordMatch);

        // Enhanced keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + Enter to submit form
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('resetPasswordForm').dispatchEvent(new Event('submit'));
            }

            // Escape to clear all fields
            if (e.key === 'Escape') {
                document.querySelectorAll('.form-control').forEach(field => {
                    field.value = '';
                    field.classList.remove('error', 'success');
                });
                document.getElementById('match-indicator').classList.remove('show');
                document.getElementById('strength-meter').className = 'strength-meter';

                // Reset all hints
                document.querySelectorAll('.password-hints li').forEach(hint => {
                    const icon = hint.querySelector('i');
                    hint.classList.remove('valid');
                    hint.classList.add('invalid');
                    icon.classList.replace('fa-check', 'fa-times');
                });
            }
        });

        // Auto-focus management
        document.addEventListener('DOMContentLoaded', function() {
            // Focus on first field
            document.getElementById('current-password').focus();

            // Show success notification if password was changed
            <?php if ($success): ?>
                showNotification('Password updated successfully! Please login with your new password.', 'success', 6000);
            <?php endif; ?>

            // Show error notification if there were database errors
            <?php if (isset($errors['database'])): ?>
                showNotification('<?php echo addslashes($errors['database']); ?>', 'error', 8000);
            <?php endif; ?>
        });

        // Enhanced accessibility features
        document.querySelectorAll('.password-toggle').forEach(btn => {
            btn.setAttribute('aria-label', 'Show password');
            btn.setAttribute('tabindex', '0');

            // Add keyboard support for password toggle
            btn.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
        });

        // Auto-save form data (except passwords) to sessionStorage for better UX
        // Note: This is commented out for security reasons with passwords
        /*
        function saveFormData() {
            const formData = {
                // Only save non-sensitive data
                timestamp: new Date().toISOString()
            };
            sessionStorage.setItem('resetPasswordFormData', JSON.stringify(formData));
        }
        */

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Enhanced security: Clear clipboard after password visibility
        let clipboardTimeout;
        document.querySelectorAll('.password-toggle').forEach(btn => {
            btn.addEventListener('click', function() {
                // Clear clipboard after 30 seconds when password is visible
                clearTimeout(clipboardTimeout);
                clipboardTimeout = setTimeout(() => {
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText('');
                    }
                }, 30000);
            });
        });

        // Prevent context menu on password fields for security
        document.querySelectorAll('input[type="password"]').forEach(field => {
            field.addEventListener('contextmenu', function(e) {
                e.preventDefault();
            });
        });

        // Enhanced form reset functionality
        function resetForm() {
            document.getElementById('resetPasswordForm').reset();
            document.querySelectorAll('.form-control').forEach(field => {
                field.classList.remove('error', 'success');
            });
            document.getElementById('match-indicator').classList.remove('show');
            document.getElementById('strength-meter').className = 'strength-meter';

            // Reset password hints
            document.querySelectorAll('.password-hints li').forEach(hint => {
                const icon = hint.querySelector('i');
                hint.classList.remove('valid');
                hint.classList.add('invalid');
                icon.classList.replace('fa-check', 'fa-times');
            });

            // Reset all password fields to password type
            document.querySelectorAll('input[type="text"]').forEach(field => {
                if (field.id.includes('password')) {
                    field.type = 'password';
                    const toggleBtn = field.nextElementSibling;
                    if (toggleBtn && toggleBtn.classList.contains('password-toggle')) {
                        const icon = toggleBtn.querySelector('i');
                        icon.classList.replace('fa-eye-slash', 'fa-eye');
                    }
                }
            });
        }
    </script>
</body>

</html>