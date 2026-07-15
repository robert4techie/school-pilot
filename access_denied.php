<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - School Pilot</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
        <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400..900&family=Quicksand:wght@300..700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #2e7d32;
            --dark-green: #1b5e20;
            --light-green: #81c784;
            --accent-green: #4caf50;
            --background: #f5f9f5;
            --white: #ffffff;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-400: #ced4da;
            --gray-500: #adb5bd;
            --gray-600: #6c757d;
            --gray-700: #495057;
            --gray-800: #343a40;
            --text-dark: #212529;
            --shadow: 0 2px 10px rgba(46, 125, 50, 0.1);
            --shadow-lg: 0 10px 30px rgba(46, 125, 50, 0.15);
            --error-red: #dc3545;
            --warning-orange: #fd7e14;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
             font-family: "Quicksand", sans-serif;
        }

        body {
            background: linear-gradient(135deg, var(--background) 0%, #e8f5e8 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
            padding: 2rem 1rem;
        }

        /* Animated background elements */
        .bg-decoration {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            z-index: -1;
            overflow: hidden;
        }

        .bg-decoration::before,
        .bg-decoration::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            background: linear-gradient(45deg, var(--light-green), transparent);
            animation: float 6s ease-in-out infinite;
        }

        .bg-decoration::before {
            width: 300px;
            height: 300px;
            top: -150px;
            right: -150px;
            animation-delay: 0s;
        }

        .bg-decoration::after {
            width: 200px;
            height: 200px;
            bottom: -100px;
            left: -100px;
            animation-delay: 3s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }

        .container {
            max-width: 1200px;
            width: 95%;
            position: relative;
            z-index: 1;
        }

        .access-denied-card {
            background: var(--white);
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-200);
            transform: translateY(0);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 3rem;
            align-items: center;
            min-height: 300px;
        }

        .access-denied-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-green), var(--accent-green), var(--light-green));
        }

        .access-denied-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(46, 125, 50, 0.2);
        }

        .left-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 1rem;
            border-right: 2px solid var(--gray-200);
            position: relative;
        }

        .left-section::after {
            content: '';
            position: absolute;
            right: -1px;
            top: 20%;
            bottom: 20%;
            width: 2px;
            background: linear-gradient(to bottom, transparent, var(--primary-green), transparent);
        }

        .right-section {
            padding: 1rem 2rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .icon-container {
            width: 120px;
            height: 120px;
            margin: 0 auto 1.5rem;
            background: linear-gradient(135deg, var(--error-red), #ff6b6b);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            animation: pulse 2s infinite;
        }

        .icon-container::before {
            content: '';
            position: absolute;
            width: 140px;
            height: 140px;
            background: rgba(220, 53, 69, 0.2);
            border-radius: 50%;
            animation: ripple 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        @keyframes ripple {
            0% { transform: scale(0.8); opacity: 1; }
            100% { transform: scale(1.2); opacity: 0; }
        }

        .icon-container i {
            font-size: 3rem;
            color: var(--white);
            z-index: 1;
        }

        .status-badge {
            background: linear-gradient(135deg, var(--error-red), #ff6b6b);
            color: var(--white);
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 1rem;
            display: inline-block;
        }

        .error-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 1rem;
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-align: left;
        }

        .error-subtitle {
            font-size: 1.25rem;
            color: var(--gray-600);
            margin-bottom: 1.5rem;
            font-weight: 500;
            text-align: left;
        }

        .error-message {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 12px;
            padding: 1.5rem;
            margin: 2rem 0;
            color: #856404;
            font-size: 1rem;
            line-height: 1.6;
            position: relative;
        }

        .error-message::before {
            content: '\f071';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            top: 1rem;
            left: 1rem;
            color: var(--warning-orange);
            font-size: 1.25rem;
        }

        .error-message-text {
            margin-left: 2rem;
            text-align: left;
        }

        .button-group {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 2rem;
        }

        .btn {
            padding: 0.875rem 2rem;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            min-width: 140px;
            justify-content: center;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-green), var(--accent-green));
            color: var(--white);
            box-shadow: var(--shadow);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--dark-green), var(--primary-green));
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(46, 125, 50, 0.3);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--primary-green);
            border: 2px solid var(--primary-green);
        }

        .btn-secondary:hover {
            background: var(--primary-green);
            color: var(--white);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(46, 125, 50, 0.2);
        }

        .help-section {
            margin-top: 2rem;
            padding: 1.5rem;
            background: var(--gray-100);
            border-radius: 12px;
            border-left: 4px solid var(--primary-green);
        }

        .help-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-green);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .help-text {
            color: var(--gray-700);
            font-size: 0.9rem;
            line-height: 1.5;
        }

        /* Mobile responsiveness */
        @media (max-width: 1024px) {
            .access-denied-card {
                grid-template-columns: 250px 1fr;
                gap: 2rem;
                padding: 2rem;
            }

            .error-title {
                font-size: 2rem;
            }
        }

        @media (max-width: 768px) {
            .container {
                width: 95%;
            }

            .access-denied-card {
                grid-template-columns: 1fr;
                gap: 2rem;
                padding: 2rem 1.5rem;
                border-radius: 16px;
                text-align: center;
            }

            .left-section {
                border-right: none;
                border-bottom: 2px solid var(--gray-200);
                padding-bottom: 2rem;
            }

            .left-section::after {
                display: none;
            }

            .left-section::before {
                content: '';
                position: absolute;
                bottom: -1px;
                left: 20%;
                right: 20%;
                height: 2px;
                background: linear-gradient(to right, transparent, var(--primary-green), transparent);
            }

            .right-section {
                padding: 1rem 0;
                text-align: center;
            }

            .error-title,
            .error-subtitle {
                text-align: center;
            }

            .error-title {
                font-size: 2rem;
            }

            .error-subtitle {
                font-size: 1.1rem;
            }

            .icon-container {
                width: 100px;
                height: 100px;
            }

            .icon-container i {
                font-size: 2.5rem;
            }

            .button-group {
                justify-content: center;
            }

            .btn {
                flex: 1;
                max-width: 200px;
            }
        }

        @media (max-width: 480px) {
            .button-group {
                flex-direction: column;
                align-items: center;
            }

            .btn {
                width: 100%;
                max-width: 280px;
            }
        }

        /* Animation for page load */
        .access-denied-card {
            animation: slideUp 0.6s cubic-bezier(0.4, 0, 0.2, 1);
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

        /* Additional decorative elements */
        .decorative-line {
            width: 60px;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-green), var(--accent-green));
            margin: 1rem auto;
            border-radius: 2px;
        }
    </style>
</head>
<body>
    <div class="bg-decoration"></div>
    
    <div class="container">
        <div class="access-denied-card">
            <div class="left-section">
                <div class="icon-container">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div class="status-badge">Restricted</div>
                <div class="decorative-line"></div>
            </div>
            
            <div class="right-section">
                <h1 class="error-title">Access Denied</h1>
                <p class="error-subtitle">You don't have permission to access this page</p>
                
                <div class="error-message">
                    <div class="error-message-text">
                        <strong>Restricted Access:</strong> This page is only accessible to authorized personnel. Please contact your system administrator if you believe this is an error.
                    </div>
                </div>
                
                <div class="button-group">
                    <button class="btn btn-primary" onclick="goBack()">
                        <i class="fas fa-arrow-left"></i>
                        Go Back
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-home"></i>
                        Dashboard
                    </a>
                </div>
                
                <div class="help-section">
                    <div class="help-title">
                        <i class="fas fa-question-circle"></i>
                        Need Help?
                    </div>
                    <p class="help-text">
                        If you need access to this feature, please contact your school administrator or IT support team. 
                        They can review your account permissions and grant appropriate access if needed.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        function goBack() {
            // Check if there's a previous page in history
            if (document.referrer && document.referrer !== window.location.href) {
                window.history.back();
            } else {
                // Fallback to dashboard if no referrer
                window.location.href = 'dashboard.php';
            }
        }

        // Add some interactive effects
        document.addEventListener('DOMContentLoaded', function() {
            // Add click effect to buttons
            const buttons = document.querySelectorAll('.btn');
            buttons.forEach(button => {
                button.addEventListener('click', function(e) {
                    const ripple = document.createElement('span');
                    const rect = this.getBoundingClientRect();
                    const size = Math.max(rect.width, rect.height);
                    const x = e.clientX - rect.left - size / 2;
                    const y = e.clientY - rect.top - size / 2;
                    
                    ripple.style.width = ripple.style.height = size + 'px';
                    ripple.style.left = x + 'px';
                    ripple.style.top = y + 'px';
                    ripple.classList.add('ripple-effect');
                    
                    this.appendChild(ripple);
                    
                    setTimeout(() => {
                        ripple.remove();
                    }, 600);
                });
            });
        });

        // Auto redirect after 30 seconds (optional)
        setTimeout(function() {
            if (confirm('Would you like to be redirected to the dashboard?')) {
                window.location.href = 'dashboard.php';
            }
        }, 30000);
    </script>

    <style>
        .ripple-effect {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.4);
            transform: scale(0);
            animation: ripple-animation 0.6s linear;
            pointer-events: none;
        }

        @keyframes ripple-animation {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }
    </style>
</body>
</html>