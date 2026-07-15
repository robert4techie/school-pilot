<?php
session_start();
require_once 'conn.php';

// Redirect if the user hasn't verified a code yet
if (!isset($_SESSION['reset_code_verified']) || $_SESSION['reset_code_verified'] !== true || !isset($_SESSION['verified_email'])) {
    // Set a notification for the user
    $_SESSION['notification'] = [
        'message' => 'Please verify your email and code first.',
        'type' => 'error'
    ];
    header("Location: forgot_password.php");
    exit();
}

$email = $_SESSION['verified_email'];

function redirectWithNotification($url, $message, $type = 'success')
{
    $_SESSION['notification'] = [
        'message' => $message,
        'type' => $type
    ];
    header("Location: $url");
    exit();
}

// Handle password reset submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // --- Server-side Validation ---
    if (empty($password) || empty($confirm_password)) {
        redirectWithNotification('reset_my_password.php', 'Please fill out both password fields.', 'error');
    }
    if ($password !== $confirm_password) {
        redirectWithNotification('reset_my_password.php', 'Passwords do not match.', 'error');
    }
    if (strlen($password) < 8) {
        redirectWithNotification('reset_my_password.php', 'Password must be at least 8 characters long.', 'error');
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        redirectWithNotification('reset_my_password.php', 'Password must include uppercase, lowercase, and numbers.', 'error');
    }
    // --- End Validation ---

    // Hash the new password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Update the user's password in the database
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
    $stmt->bind_param("ss", $hashed_password, $email);

    if ($stmt->execute()) {
        // Password updated, now delete the reset token to prevent reuse
        $stmt_delete = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
        $stmt_delete->bind_param("s", $email);
        $stmt_delete->execute();
        $stmt_delete->close();

        // Clean up session variables
        unset($_SESSION['reset_code_verified']);
        unset($_SESSION['verified_email']);
        unset($_SESSION['reset_email']);

        redirectWithNotification('index.php', 'Your password has been reset successfully. Please log in.', 'success');
    } else {
        redirectWithNotification('reset_my_password.php', 'Failed to update password. Please try again.', 'error');
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
    <title>School Pilot - Reset Password</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@300..700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1e8449;
            --primary-light: #27ae60;
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

        .header h1 {
            font-size: 1.8rem;
            font-weight: 600;
        }

        .form-container {
            padding: 40px 30px;
        }

        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-dark);
            font-weight: 500;
        }

        .form-group input {
            width: 100%;
            padding: 14px 40px 14px 16px;
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

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 45px;
            background: none;
            border: none;
            cursor: pointer;
            color: #999;
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
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(30, 132, 73, 0.2);
        }

        .btn:disabled {
            background: #aaa;
            cursor: not-allowed;
        }

        .password-strength {
            margin-top: 10px;
            font-size: 0.85rem;
        }

        .strength-bar {
            height: 5px;
            background: #eee;
            border-radius: 5px;
            margin-top: 5px;
            transition: all 0.3s;
        }

        .strength-bar-fill {
            height: 100%;
            border-radius: 5px;
            width: 0;
            transition: all 0.3s;
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

        .notification.success {
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
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>Set New Password</h1>
        </div>
        <div class="form-container">
            <form method="POST" action="reset_my_password.php" id="resetPasswordForm">
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required>
                    <button type="button" class="password-toggle" onclick="togglePassword('new_password', this)">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                    <div class="password-strength">
                        <div class="strength-bar">
                            <div id="strength-bar-fill" class="strength-bar-fill"></div>
                        </div>
                        <span id="strength-text"></span>
                    </div>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                    <span id="match-text" style="font-size: 0.85rem; color: red;"></span>
                </div>
                <button type="submit" name="reset_password" class="btn" id="submitBtn" disabled>Reset Password</button>
            </form>
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

            const newPass = document.getElementById('new_password');
            const confirmPass = document.getElementById('confirm_password');
            const matchText = document.getElementById('match-text');
            const strengthFill = document.getElementById('strength-bar-fill');
            const strengthText = document.getElementById('strength-text');
            const submitBtn = document.getElementById('submitBtn');

            function checkPasswords() {
                const pass = newPass.value;
                const confirm = confirmPass.value;
                let strength = 0;
                if (pass.length >= 8) strength++;
                if (pass.match(/[a-z]/)) strength++;
                if (pass.match(/[A-Z]/)) strength++;
                if (pass.match(/[0-9]/)) strength++;

                const strengthColors = ['#e74c3c', '#f39c12', '#f1c40f', '#2ecc71'];
                const strengthLabels = ['Weak', 'Medium', 'Good', 'Strong'];

                strengthFill.style.width = (strength * 25) + '%';
                strengthFill.style.backgroundColor = strength > 0 ? strengthColors[strength - 1] : '#eee';
                strengthText.textContent = strength > 0 ? `Strength: ${strengthLabels[strength - 1]}` : '';

                if (confirm && pass !== confirm) {
                    matchText.textContent = 'Passwords do not match.';
                    submitBtn.disabled = true;
                } else {
                    matchText.textContent = '';
                    submitBtn.disabled = strength < 4 || !confirm;
                }
            }

            newPass.addEventListener('input', checkPasswords);
            confirmPass.addEventListener('input', checkPasswords);
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

        function togglePassword(inputId, button) {
            const passwordInput = document.getElementById(inputId);
            const icon = button.querySelector('svg');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.innerHTML = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>`;
            } else {
                passwordInput.type = 'password';
                icon.innerHTML = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>`;
            }
        }
    </script>
</body>

</html>