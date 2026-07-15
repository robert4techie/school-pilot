<?php
// ========================================
// SYSTEM ERROR PAGE - STANDALONE
// This file displays a user-friendly error page
// when system errors occur
// ========================================

// Prevent direct access without error context
if (!defined('ERROR_CONTEXT')) {
    header("Location: index.php");
    exit();
}

// Get return URL based on context
$returnUrl = 'index.php';
if (isset($_SESSION['user_type'])) {
    switch ($_SESSION['user_type']) {
        case 'staff':
        case 'super user':
            $returnUrl = 'dashboard.php';
            break;
        case 'student':
            $returnUrl = 'student_dashboard.php';
            break;
        case 'parent':
            $returnUrl = 'parent_dashboard.php';
            break;
    }
}

// Optional: Get custom error message if provided
$errorMessage = isset($GLOBALS['custom_error_message']) 
    ? htmlspecialchars($GLOBALS['custom_error_message'], ENT_QUOTES, 'UTF-8')
    : "We're experiencing technical difficulties. Our team has been notified and is working to resolve the issue.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400..800&display=swap" rel="stylesheet">
    <title>System Error - School Pilot</title>
    <style>
        :root {
            --primary-color: #1e8449;
            --primary-light: #27ae60;
            --error-color: #e74c3c;
            --error-light: #ec7063;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --text-dark: #333333;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box;
        }
        body {
            font-family: "Sen", sans-serif;
            background: linear-gradient(135deg, var(--light-gray) 0%, #fee 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .error-container {
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--shadow);
            overflow: hidden;
            width: 100%;
            max-width: 1000px;
            display: flex;
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
        .error-left {
            flex: 1;
            background: linear-gradient(135deg, var(--error-color) 0%, var(--error-light) 100%);
            color: var(--white);
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        .error-left::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .error-icon {
            width: 120px; 
            height: 120px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 30px;
            border: 4px solid rgba(255, 255, 255, 0.3);
            animation: pulse 2s infinite;
            position: relative;
            z-index: 1;
        }
        @keyframes pulse { 
            0%, 100% { 
                transform: scale(1); 
            } 
            50% { 
                transform: scale(1.05); 
            } 
        }
        .error-left h1 { 
            font-size: 2.5rem; 
            font-weight: 700; 
            margin-bottom: 15px;
            position: relative;
            z-index: 1;
        }
        .error-left p {
            font-size: 1.1rem;
            opacity: 0.95;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        .error-right { 
            flex: 1;
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .error-right h2 {
            color: var(--text-dark);
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .error-right p { 
            color: var(--text-dark); 
            font-size: 1.05rem; 
            line-height: 1.7; 
            margin-bottom: 25px; 
        }
        .error-code {
            background: var(--light-gray);
            padding: 15px 20px;
            border-radius: 10px;
            font-family: 'Courier New', monospace;
            color: var(--error-color);
            font-weight: 600;
            margin-bottom: 30px;
            border: 2px dashed var(--error-light);
            text-align: center;
        }
        .btn-container { 
            display: flex; 
            gap: 15px; 
            margin-bottom: 30px;
        }
        .btn {
            flex: 1;
            padding: 14px 28px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-family: "Sen", sans-serif;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: var(--white);
        }
        .btn-primary:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 8px 25px rgba(30, 132, 73, 0.2); 
        }
        .btn-secondary { 
            background: var(--light-gray); 
            color: var(--text-dark); 
        }
        .btn-secondary:hover { 
            background: #e0e0e0; 
        }
        .support-info {
            padding-top: 25px;
            border-top: 2px solid var(--light-gray);
            color: #777;
            font-size: 0.95rem;
            text-align: center;
        }
        .support-info a { 
            color: var(--primary-color); 
            text-decoration: none; 
            font-weight: 600; 
        }
        .support-info a:hover { 
            text-decoration: underline; 
        }
        @media (max-width: 768px) {
            .error-container {
                flex-direction: column;
                max-width: 500px;
            }
            .error-left {
                padding: 40px 30px;
            }
            .error-left h1 {
                font-size: 2rem;
            }
            .error-icon {
                width: 90px;
                height: 90px;
                margin-bottom: 20px;
            }
            .error-right {
                padding: 40px 30px;
            }
            .error-right h2 {
                font-size: 1.5rem;
            }
            .btn-container {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-left">
            <div class="error-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
            </div>
            <h1>System Error</h1>
            <p>Something unexpected happened</p>
        </div>
        
        <div class="error-right">
            <h2>Oops! We hit a snag</h2>
            <p><?php echo $errorMessage; ?></p>
            <div class="error-code">Error Code: 500 - Internal Server Error</div>
            
            <div class="btn-container">
                <a href="<?php echo htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    </svg>
                    Return Home
                </a>
                <button onclick="location.reload()" class="btn btn-secondary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"></path>
                    </svg>
                    Try Again
                </button>
            </div>
            
            <div class="support-info">
                <p>Need immediate assistance?<br>
                <a href="tel:+256747170325">+256 747 170 325</a></p>
            </div>
        </div>
    </div>
</body>
</html>