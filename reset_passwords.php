<?php
session_start();
require_once 'conn.php';

/* Check if this is a valid password reset request
if (!isset($_SESSION['temp_user_id']) || !isset($_SESSION['user_type']) || !isset($_SESSION['first_login'])) {
    $_SESSION['notification'] = [
        'message' => 'Invalid password reset request',
        'type' => 'error',
        'sound' => 'error'
    ];
    header("Location: index.php");
    exit();
}*/

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_passwords'])) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $user_type = $_SESSION['user_type'];
    $user_id = $_SESSION['temp_user_id'];

    // Validate passwords
    if ($new_password !== $confirm_password) {
        $_SESSION['notification'] = [
            'message' => 'Passwords do not match',
            'type' => 'error',
            'sound' => 'error'
        ];
        header("Location: reset_passwords.php");
        exit();
    }

    if (strlen($new_password) < 6) {
        $_SESSION['notification'] = [
            'message' => 'Password must be at least 6 characters',
            'type' => 'error',
            'sound' => 'error'
        ];
        header("Location: reset_passwords.php");
        exit();
    }

    // Update password in database
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    if ($user_type === 'student') {
        $sql = "UPDATE students SET password = ?, first_login = 0 WHERE student_id = ?";
        $redirect = 'student_dashboard.php';
    } else { // parent
        $sql = "UPDATE parents SET password = ?, first_login = 0 WHERE phone = ?";
        $redirect = 'parent_dashboard.php';
    }

    // Use prepared statement for security
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $hashed_password, $user_id);
    
    if ($stmt->execute()) {
        // Update session variables
        $_SESSION['user_id'] = $user_id;
        unset($_SESSION['temp_user_id']);
        unset($_SESSION['first_login']);

        $_SESSION['notification'] = [
            'message' => 'Password updated successfully!',
            'type' => 'success',
            'sound' => 'success'
        ];
        header("Location: $redirect");
        exit();
    } else {
        $_SESSION['notification'] = [
            'message' => 'Error updating password: ' . $conn->error,
            'type' => 'error',
            'sound' => 'error'
        ];
        header("Location: reset_passwords.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400..900&family=Quicksand:wght@300..700&display=swap" rel="stylesheet">
    <title>Reset Password - School Pilot</title>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1e8449;
            --primary-dark: #146c3a;
            --primary-light: #81c784;
            --secondary-color: #f5f5f5;
            --success-color: #4CAF50;
            --error-color: #f44336;
            --info-color: #2196F3;
            --text-dark: #333;
            --text-medium: #555;
            --text-light: #777;
            --white: #ffffff;
            --box-shadow: 0 8px 20px rgba(0,0,0,0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Quicksand", sans-serif;
        }

        body {
            background-color: var(--secondary-color);
            background-image: linear-gradient(135deg, #f5f5f5 0%, #e8f5e9 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 8px;
            color: var(--white);
            z-index: 1000;
            box-shadow: var(--box-shadow);
            animation: slideIn 0.5s forwards;
            display: flex;
            align-items: center;
            gap: 10px;
            max-width: 350px;
        }

        .notification i {
            font-size: 24px;
        }

        .notification.success { 
            background-color: var(--success-color);
            border-left: 5px solid #388E3C;
        }

        .notification.error { 
            background-color: var(--error-color);
            border-left: 5px solid #D32F2F;
        }

        .notification.info { 
            background-color: var(--info-color);
            border-left: 5px solid #1976D2;
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
                transform: translateX(0);
                opacity: 1;
            }
            to { 
                transform: translateX(100%);
                opacity: 0;
            }
        }
        
        .reset-container {
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
            padding: 40px;
            background: var(--white);
            box-shadow: var(--box-shadow);
            border-radius: 15px;
            position: relative;
            overflow: hidden;
        }

        .reset-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: var(--primary-color);
            border-radius: 6px 6px 0 0;
        }

        .school-logo {
            text-align: center;
            margin-bottom: 20px;
        }

        .school-logo i {
            font-size: 48px;
            color: var(--primary-color);
        }

        .form-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .form-header h2 {
            color: var(--text-dark);
            font-size: 28px;
            margin-bottom: 10px;
        }

        .form-header p {
            color: var(--text-medium);
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-medium);
        }

        .form-control {
            width: 100%;
            padding: 14px 15px 14px 45px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: var(--transition);
            background-color: #f9f9f9;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(30, 132, 73, 0.1);
            background-color: var(--white);
        }

        .form-group i.field-icon {
            position: absolute;
            left: 15px;
            top: 47px;
            color: var(--text-light);
            transition: var(--transition);
        }

        .form-control:focus + i.field-icon {
            color: var(--primary-color);
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 47px;
            color: var(--text-light);
            cursor: pointer;
            transition: var(--transition);
        }

        .password-toggle:hover {
            color: var(--primary-color);
        }

        .password-strength {
            height: 5px;
            margin-top: 8px;
            border-radius: 3px;
            background-color: #e0e0e0;
            overflow: hidden;
            position: relative;
        }

        .password-strength-meter {
            height: 100%;
            width: 0;
            transition: var(--transition);
            border-radius: 3px;
        }

        .strength-weak {
            background-color: #ff5252;
            width: 25%;
        }

        .strength-medium {
            background-color: #ffca28;
            width: 50%;
        }

        .strength-good {
            background-color: #66bb6a;
            width: 75%;
        }

        .strength-strong {
            background-color: #43a047;
            width: 100%;
        }

        .password-rules {
            margin-top: 8px;
            font-size: 14px;
            color: var(--text-light);
        }

        .password-rules ul {
            padding-left: 20px;
            margin-top: 5px;
        }

        .password-rules li {
            margin-bottom: 3px;
        }

        .password-rules li.valid {
            color: var(--success-color);
        }

        .submit-btn {
            background-color: var(--primary-color);
            color: var(--white);
            padding: 14px 20px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            transition: var(--transition);
            font-weight: 600;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }

        .submit-btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 132, 73, 0.2);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        @media (max-width: 576px) {
            .reset-container {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="school-logo">
            <i class="fas fa-graduation-cap"></i>
        </div>
        
        <div class="form-header">
            <h2>Reset Your Password</h2>
            <p>For security reasons, please create a new password for your School Pilot account.</p>
        </div>
        
        <form id="resetForm" method="POST" action="reset_passwords.php">
            <div class="form-group">
                <label for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" class="form-control" required minlength="6" placeholder="Enter new password">
                <i class="fas fa-lock field-icon"></i>
                <i class="fas fa-eye password-toggle" id="togglePassword"></i>
                
                <div class="password-strength">
                    <div class="password-strength-meter" id="passwordStrengthMeter"></div>
                </div>
                
                <div class="password-rules">
                    <span>Password should:</span>
                    <ul id="passwordRulesList">
                        <li id="lengthRule">Be at least 6 characters long</li>
                        <li id="caseRule">Include both uppercase and lowercase letters</li>
                        <li id="numberRule">Include at least one number</li>
                        <li id="specialRule">Include at least one special character</li>
                    </ul>
                </div>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="6" placeholder="Confirm new password">
                <i class="fas fa-lock field-icon"></i>
                <i class="fas fa-eye password-toggle" id="toggleConfirmPassword"></i>
            </div>
            
            <button type="submit" name="reset_passwords" class="submit-btn">
                <i class="fas fa-key"></i> Update Password
            </button>
        </form>
    </div>

    <script>
        // Preload audio files
        const audioFiles = {
            success: new Audio('sounds/success.mp3'),
            error: new Audio('sounds/error.wav')
        };
        
        // Show notification function
        function showNotification(message, type, sound) {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            
            // Add icon based on type
            let icon;
            if (type === 'success') {
                icon = '<i class="fas fa-check-circle"></i>';
            } else if (type === 'error') {
                icon = '<i class="fas fa-exclamation-circle"></i>';
            } else {
                icon = '<i class="fas fa-info-circle"></i>';
            }
            
            notification.innerHTML = `${icon} ${message}`;
            document.body.appendChild(notification);
            
            // Play sound if specified
            if (sound && audioFiles[sound]) {
                audioFiles[sound].currentTime = 0; // Rewind to start
                audioFiles[sound].play().catch(e => {
                    console.error('Audio playback failed:', e);
                });
            }
            
            // Remove after animation
            setTimeout(() => {
                notification.style.animation = 'fadeOut 0.5s forwards';
                setTimeout(() => {
                    notification.remove();
                }, 500);
            }, 3000);
        }
        
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('new_password');
            const icon = this;
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
            const confirmPasswordInput = document.getElementById('confirm_password');
            const icon = this;
            
            if (confirmPasswordInput.type === 'password') {
                confirmPasswordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                confirmPasswordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        // Password strength checker
        const passwordInput = document.getElementById('new_password');
        const strengthMeter = document.getElementById('passwordStrengthMeter');
        const lengthRule = document.getElementById('lengthRule');
        const caseRule = document.getElementById('caseRule');
        const numberRule = document.getElementById('numberRule');
        const specialRule = document.getElementById('specialRule');
        
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            const lengthValid = password.length >= 6;
            const caseValid = /[a-z]/.test(password) && /[A-Z]/.test(password);
            const numberValid = /[0-9]/.test(password);
            const specialValid = /[^A-Za-z0-9]/.test(password);
            
            // Update rule indicators
            lengthRule.className = lengthValid ? 'valid' : '';
            lengthRule.innerHTML = lengthValid ? 
                '<i class="fas fa-check"></i> Be at least 6 characters long' : 
                'Be at least 6 characters long';
                
            caseRule.className = caseValid ? 'valid' : '';
            caseRule.innerHTML = caseValid ? 
                '<i class="fas fa-check"></i> Include both uppercase and lowercase letters' : 
                'Include both uppercase and lowercase letters';
                
            numberRule.className = numberValid ? 'valid' : '';
            numberRule.innerHTML = numberValid ? 
                '<i class="fas fa-check"></i> Include at least one number' : 
                'Include at least one number';
                
            specialRule.className = specialValid ? 'valid' : '';
            specialRule.innerHTML = specialValid ? 
                '<i class="fas fa-check"></i> Include at least one special character' : 
                'Include at least one special character';
            
            // Calculate strength
            let strength = 0;
            if (lengthValid) strength += 1;
            if (caseValid) strength += 1;
            if (numberValid) strength += 1;
            if (specialValid) strength += 1;
            
            // Update strength meter
            strengthMeter.className = 'password-strength-meter';
            if (password.length === 0) {
                strengthMeter.style.width = '0';
            } else if (strength === 1) {
                strengthMeter.classList.add('strength-weak');
            } else if (strength === 2) {
                strengthMeter.classList.add('strength-medium');
            } else if (strength === 3) {
                strengthMeter.classList.add('strength-good');
            } else if (strength === 4) {
                strengthMeter.classList.add('strength-strong');
            }
        });
        
        // Form validation
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            const newPass = document.getElementById('new_password').value;
            const confirmPass = document.getElementById('confirm_password').value;
            
            if (newPass !== confirmPass) {
                e.preventDefault();
                showNotification('Passwords do not match!', 'error', 'error');
                return false;
            }
            
            if (newPass.length < 6) {
                e.preventDefault();
                showNotification('Password must be at least 6 characters!', 'error', 'error');
                return false;
            }
            
            return true;
        });
        
        // Monitor password match
        const confirmPasswordInput = document.getElementById('confirm_password');
        confirmPasswordInput.addEventListener('input', function() {
            const newPass = passwordInput.value;
            const confirmPass = this.value;
            
            if (confirmPass.length > 0) {
                if (newPass === confirmPass) {
                    this.style.borderColor = '#4CAF50';
                } else {
                    this.style.borderColor = '#f44336';
                }
            } else {
                this.style.borderColor = '#e0e0e0';
            }
        });
        
        // Check for URL parameters that might indicate errors
        window.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('error')) {
                showNotification(urlParams.get('error'), 'error', 'error');
            }
            
            // Display PHP notifications
            <?php
            if (isset($_SESSION['notification'])) {
                $notification = $_SESSION['notification'];
                unset($_SESSION['notification']);
                
                // Escape the message for JavaScript
                $js_message = addslashes($notification['message']);
                $js_type = addslashes($notification['type']);
                $js_sound = isset($notification['sound']) ? addslashes($notification['sound']) : '';
                
                echo "showNotification('$js_message', '$js_type', '$js_sound');";
            }
            ?>
        });
    </script>
</body>
</html>