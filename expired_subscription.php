<?php
session_start();

// Destroy the session to log the user out, as they cannot proceed with an expired subscription.
session_destroy();

$message = isset($_SESSION['notification']['message']) ? $_SESSION['notification']['message'] : 'Your school\'s subscription has expired or is suspended. Please contact the system administrator for assistance.';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Expired</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@300..700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #ef4444; /* A red color for errors/warnings */
            --primary-light: #f87171;
            --primary-dark: #b91c1c;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --background-primary: #f8fafc;
            --background-secondary: #ffffff;
            --border-color: #e2e8f0;
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --border-radius: 12px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Quicksand', sans-serif;
        }

        body {
            background-color: var(--background-primary);
            color: var(--text-primary);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 24px;
        }

        .container {
            max-width: 600px;
            background: var(--background-secondary);
            padding: 48px 32px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            text-align: center;
            border-top: 4px solid var(--primary-color);
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .icon {
            font-size: 80px;
            color: var(--primary-color);
            margin-bottom: 24px;
            animation: bounceIn 0.8s ease-out;
        }
        
        @keyframes bounceIn {
            0% { transform: scale(0.1); opacity: 0; }
            60% { transform: scale(1.2); opacity: 1; }
            100% { transform: scale(1); }
        }

        .container h1 {
            font-size: 2.5rem;
            color: var(--text-primary);
            margin-bottom: 16px;
        }

        .container p {
            font-size: 1.1rem;
            color: var(--text-secondary);
            margin-bottom: 24px;
            line-height: 1.5;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .contact-info {
            margin-top: 32px;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }
    </style>
</head>
<body>

<div class="container">
    <div class="icon">
        <i class="fas fa-exclamation-circle"></i>
    </div>
    <h1>Subscription Expired</h1>
    <p>
        <?php echo htmlspecialchars($message); ?>
    </p>
    <a href="index.php" class="btn btn-primary">Go Back to Login</a>
    <div class="contact-info">
        <p>If you believe this is an error, please contact your school administrator.</p>
    </div>
</div>

</body>
</html>
