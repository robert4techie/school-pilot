<?php
session_start();
require_once 'conn.php';

// Redirect if the user hasn't requested a code yet
if (!isset($_SESSION['reset_email'])) {
    header("Location: forgot_password.php");
    exit();
}

$email = $_SESSION['reset_email'];

function redirectWithNotification($url, $message, $type = 'success')
{
    $_SESSION['notification'] = [
        'message' => $message,
        'type' => $type
    ];
    header("Location: $url");
    exit();
}

// Handle code verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_code'])) {
    // Concatenate all code inputs
    $code_parts = array_map('trim', $_POST['code']);
    $code = implode('', $code_parts);

    if (strlen($code) != 6 || !ctype_digit($code)) {
        redirectWithNotification('verify_reset_code.php', 'Please enter the complete 6-digit code.', 'error');
    } else {
        // Check if the code is valid and not expired
        $stmt = $conn->prepare("SELECT reset_code FROM password_resets WHERE email = ? AND reset_code = ? AND expires_at > NOW()");
        $stmt->bind_param("ss", $email, $code);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            // Code is correct, mark as verified and proceed to reset page
            $_SESSION['reset_code_verified'] = true;
            $_SESSION['verified_email'] = $email; // Carry email to the next step
            redirectWithNotification('reset_my_password.php', 'Code verified. Please set your new password.', 'success');
        } else {
            redirectWithNotification('verify_reset_code.php', 'Invalid or expired code. Please try again.', 'error');
        }
        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Pilot - Verify Code</title>
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
            max-width: 480px;
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

        .email-info {
            background: var(--light-gray);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 30px;
            text-align: center;
            border-left: 4px solid var(--primary-color);
        }

        .email-info p {
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .email-info strong {
            color: var(--primary-color);
            font-weight: 600;
        }

        .code-inputs {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-bottom: 30px;
        }

        .code-input {
            width: 50px;
            height: 60px;
            border: 2px solid var(--gray);
            border-radius: 10px;
            text-align: center;
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-dark);
            background: var(--light-gray);
            transition: var(--transition);
        }

        .code-input:focus {
            outline: none;
            border-color: var(--primary-color);
            background: var(--white);
            box-shadow: 0 0 0 4px rgba(30, 132, 73, 0.1);
            transform: scale(1.05);
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
            <div class="header-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                </svg>
            </div>
            <h1>Verify Your Account</h1>
            <p>Enter the 6-digit code sent to your email.</p>
        </div>

        <div class="form-container">
            <div class="email-info">
                <p>A verification code was sent to:</p>
                <p><strong><?php echo htmlspecialchars($email); ?></strong></p>
            </div>

            <form method="POST" action="verify_reset_code.php" id="verifyCodeForm">
                <div class="code-inputs" id="code-container">
                    <input type="text" class="code-input" name="code[]" maxlength="1" pattern="[0-9]" required>
                    <input type="text" class="code-input" name="code[]" maxlength="1" pattern="[0-9]" required>
                    <input type="text" class="code-input" name="code[]" maxlength="1" pattern="[0-9]" required>
                    <input type="text" class="code-input" name="code[]" maxlength="1" pattern="[0-9]" required>
                    <input type="text" class="code-input" name="code[]" maxlength="1" pattern="[0-9]" required>
                    <input type="text" class="code-input" name="code[]" maxlength="1" pattern="[0-9]" required>
                </div>
                <button type="submit" name="verify_code" class="btn">Verify & Proceed</button>
            </form>
            <div class="back-link">
                <a href="forgot_password.php">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 12H5"></path>
                        <path d="M12 19l-7-7 7-7"></path>
                    </svg>
                    Use a different email
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

            const codeContainer = document.getElementById('code-container');
            const inputs = [...codeContainer.children];
            inputs[0].focus();

            codeContainer.addEventListener('input', (e) => {
                const input = e.target;
                const value = input.value;
                if (value && input.nextElementSibling) {
                    input.nextElementSibling.focus();
                }
            });

            codeContainer.addEventListener('keydown', (e) => {
                const input = e.target;
                if (e.key === 'Backspace' && !input.value && input.previousElementSibling) {
                    input.previousElementSibling.focus();
                }
            });

            codeContainer.addEventListener('paste', (e) => {
                e.preventDefault();
                const pasteData = (e.clipboardData || window.clipboardData).getData('text').trim();
                if (/^\d{6}$/.test(pasteData)) {
                    inputs.forEach((input, index) => {
                        input.value = pasteData[index];
                    });
                    inputs[5].focus();
                }
            });
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
    </script>
</body>

</html>