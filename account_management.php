<?php
ob_start();
session_start();
require_once 'conn.php';
require_once 'LoginSecurity.php';

require_once 'tracking.php';
$tracker->trackAction("Account Management");


// Check if user is logged in and is a super user
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'developer') {
    header("Location: index.php");
    exit();
}

$loginSecurity = new LoginSecurity($conn);
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $user_identifier = trim($_POST['user_identifier']);
    $user_type = $_POST['user_type'];
    $admin_username = $_SESSION['user_name'];

    try {
        if ($action === 'lock') {
            $duration_hours = isset($_POST['duration_hours']) ? (int)$_POST['duration_hours'] : 24;
            $result = $loginSecurity->adminLockAccount($user_identifier, $user_type, $admin_username, $duration_hours);
            if ($result) {
                $message = "Account successfully locked for {$duration_hours} hours.";
                $messageType = 'success';
            }
        } elseif ($action === 'unlock') {
            $result = $loginSecurity->adminUnlockAccount($user_identifier, $user_type, $admin_username);
            if ($result) {
                $message = "Account successfully unlocked.";
                $messageType = 'success';
            }
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Get currently locked accounts
$lockedAccounts = $loginSecurity->getLockedAccounts();

// Get login statistics
$loginStats = $loginSecurity->getLoginStats(7); // Last 7 days
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Management - School Pilot System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #2d7d32;
            --secondary-green: #388e3c;
            --light-green: #4caf50;
            --accent-green: #66bb6a;
            --dark-green: #1b5e20;
            --success-green: #4caf50;
            --warning-orange: #ff9800;
            --error-red: #f44336;
            --light-bg: #e8f5e8;
            --white: #ffffff;
            --text-dark: #2e7d32;
            --text-light: #757575;
            --shadow: 0 4px 20px rgba(76, 175, 80, 0.15);
            --shadow-hover: 0 8px 30px rgba(76, 175, 80, 0.25);
            --border-radius: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, var(--light-bg) 0%, #f1f8e9 100%);
            min-height: 100vh;
            color: var(--text-dark);
            position: relative;
        }

        /* Background Pattern */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                radial-gradient(circle at 25% 25%, rgba(76, 175, 80, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 75% 75%, rgba(139, 195, 74, 0.1) 0%, transparent 50%);
            z-index: -1;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px;
            position: relative;
            z-index: 1;
        }

        /* Navigation Header */
        .nav-header {
            background: linear-gradient(135deg, var(--primary-green), var(--secondary-green));
            border-radius: var(--border-radius);
            padding: 10px 20px;
            margin-bottom: 30px;
            margin-top: 50px;
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            overflow: hidden;
        }

        .nav-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 100%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            transform: rotate(45deg);
            animation: shine 3s infinite;
        }

        @keyframes shine {
            0% { transform: translateX(-100%) rotate(45deg); }
            100% { transform: translateX(100%) rotate(45deg); }
        }

        .nav-title {
            display: flex;
            align-items: center;
            color: var(--white);
            z-index: 2;
        }

        .nav-title i {
            font-size: 2.5rem;
            margin-right: 15px;
            background: linear-gradient(45deg, #fff, #e8f5e8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .nav-title h1 {
            font-size: 1.2rem;
            font-weight: 700;
            margin: 0;
        }

        .nav-title p {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-top: 5px;
        }

        .back-btnn {
            background: rgba(255,255,255,0.2);
            color: var(--white);
            padding: 6px 18px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
            z-index: 2;
        }

        .back-btnn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        /* Advanced Notification System */
        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            max-width: 400px;
        }

        .notification {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: var(--shadow-hover);
            border-left: 5px solid;
            transform: translateX(400px);
            opacity: 0;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .notification.show {
            transform: translateX(0);
            opacity: 1;
        }

        .notification.success {
            border-left-color: var(--success-green);
            background: linear-gradient(135deg, #ffffff 0%, #f1f8e9 100%);
        }

        .notification.error {
            border-left-color: var(--error-red);
            background: linear-gradient(135deg, #ffffff 0%, #ffebee 100%);
        }

        .notification-content {
            display: flex;
            align-items: center;
        }

        .notification-icon {
            font-size: 1.5rem;
            margin-right: 15px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .notification.success .notification-icon {
            background: var(--success-green);
            color: var(--white);
        }

        .notification.error .notification-icon {
            background: var(--error-red);
            color: var(--white);
        }

        .notification-text {
            flex: 1;
            font-weight: 500;
        }

        .notification-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: var(--text-light);
            padding: 5px;
            border-radius: 50%;
            transition: var(--transition);
        }

        .notification-close:hover {
            background: rgba(0,0,0,0.1);
        }

        .notification-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background: var(--success-green);
            width: 100%;
            transform-origin: left;
            animation: progress 5s linear forwards;
        }

        .notification.error .notification-progress {
            background: var(--error-red);
        }

        @keyframes progress {
            0% { transform: scaleX(1); }
            100% { transform: scaleX(0); }
        }

        /* Main Content Sections */
        .main-content {
            display: grid;
            gap: 30px;
        }

        .section {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: var(--transition);
            border: 1px solid rgba(76, 175, 80, 0.1);
        }

        .section:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        .section-header {
            background: linear-gradient(135deg, var(--light-green), var(--accent-green));
            color: var(--white);
            padding: 15px 20px;
            position: relative;
            overflow: hidden;
        }

        .section-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1));
            transform: skewX(-15deg);
        }

        .section-header h2 {
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            margin: 0;
        }

        .section-header i {
            margin-right: 12px;
            font-size: 1.3rem;
        }

        .section-content {
            padding: 30px;
        }

        /* Form Styling */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        label {
            font-weight: 500;
            color: var(--text-dark);
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        input, select {
            padding: 14px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: var(--transition);
            background: var(--white);
            font-family: inherit;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--light-green);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        /* Advanced Button Styling */
        .btnn {
            padding: 8px 22px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            font-family: inherit;
        }

        .btnn i {
            margin-right: 8px;
        }

        .btnn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255,255,255,0.3);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: var(--transition);
        }

        .btnn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btnn-lock {
            background: linear-gradient(135deg, var(--error-red), #d32f2f);
            color: var(--white);
        }

        .btnn-lock:hover {
            background: linear-gradient(135deg, #d32f2f, #c62828);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(244, 67, 54, 0.3);
        }

        .btnn-unlock {
            background: linear-gradient(135deg, var(--success-green), var(--secondary-green));
            color: var(--white);
        }

        .btnn-unlock:hover {
            background: linear-gradient(135deg, var(--secondary-green), var(--primary-green));
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.3);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--white), var(--light-bg));
            padding: 14px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            text-align: center;
            transition: var(--transition);
            border: 1px solid rgba(76, 175, 80, 0.1);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--light-green), var(--accent-green));
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .stat-number {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-green);
            margin-bottom: 8px;
        }

        .stat-label {
            color: var(--text-light);
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            font-size: 2rem;
            color: var(--accent-green);
            margin-bottom: 15px;
        }

        /* Table Styling */
        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            background: var(--white);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }

        th {
            background: linear-gradient(135deg, var(--light-bg), #f1f8e9);
            font-weight: 600;
            color: var(--text-dark);
            position: sticky;
            top: 0;
        }

        tr:hover {
            background: linear-gradient(135deg, var(--light-bg), rgba(76, 175, 80, 0.05));
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.6rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-admin {
            background: linear-gradient(135deg, #ffebee, #ffcdd2);
            color: #c62828;
        }

        .status-attempts {
            background: linear-gradient(135deg, #fff3e0, #ffe0b2);
            color: #ef6c00;
        }

        .status-success {
            color: var(--success-green);
            font-weight: 600;
        }

        .status-failed {
            color: var(--error-red);
            font-weight: 600;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 2rem;
            color: var(--accent-green);
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 1rem;
            margin-bottom: 10px;
            color: var(--text-dark);
        }

        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(76, 175, 80, 0.3);
            border-radius: 50%;
            border-top-color: var(--light-green);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            .nav-header {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .notification-container {
                left: 10px;
                right: 10px;
                max-width: none;
            }

            .nav-title h1 {
                font-size: 1.5rem;
            }

            .nav-title i {
                font-size: 2rem;
            }
        }

        /* Animations */
        .fade-in {
            animation: fadeIn 0.6s ease-out;
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

        .slide-in {
            animation: slideIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
    </style>
</head>
<body>
    <?php require_once 'nav.php'; ?>
    <!-- Advanced Notification Container -->
    <div class="notification-container" id="notificationContainer"></div>
    
    <div class="container fade-in">
        <!-- Enhanced Navigation Header -->
        <div class="nav-header">
            <div class="nav-title">
                <i class="fas fa-graduation-cap"></i>
                <div>
                    <h1>School Pilot - Account Management</h1>
                    <p>Super User Control Panel</p>
                </div>
            </div>
            <a href="dashboard.php" class="back-btnn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <div class="main-content">
            <!-- Lock Account Section -->
            <div class="section slide-in">
                <div class="section-header">
                    <h2><i class="fas fa-lock"></i> Lock Account</h2>
                </div>
                <div class="section-content">
                    <form method="POST" id="lockForm">
                        <input type="hidden" name="action" value="lock">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="lock_user_type">
                                    <i class="fas fa-users"></i> User Type
                                </label>
                                <select name="user_type" id="lock_user_type" required>
                                    <option value="">Select User Type</option>
                                    <option value="staff">👨‍🏫 Staff</option>
                                    <option value="student">👨‍🎓 Student</option>
                                    <option value="parent">👨‍👩‍👧‍👦 Parent</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="lock_user_identifier">
                                    <i class="fas fa-id-card"></i> User Identifier
                                </label>
                                <input type="text" name="user_identifier" id="lock_user_identifier" 
                                       placeholder="Username/Student ID/Phone" required>
                            </div>
                            <div class="form-group">
                                <label for="duration_hours">
                                    <i class="fas fa-clock"></i> Lock Duration
                                </label>
                                <select name="duration_hours" id="duration_hours">
                                    <option value="1">1 Hour</option>
                                    <option value="6">6 Hours</option>
                                    <option value="24" selected>24 Hours</option>
                                    <option value="72">3 Days</option>
                                    <option value="168">1 Week</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <button type="submit" class="btnn btnn-lock">
                                    <i class="fas fa-lock"></i> Lock Account
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Unlock Account Section -->
            <div class="section slide-in">
                <div class="section-header">
                    <h2><i class="fas fa-unlock"></i> Unlock Account</h2>
                </div>
                <div class="section-content">
                    <form method="POST" id="unlockForm">
                        <input type="hidden" name="action" value="unlock">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="unlock_user_type">
                                    <i class="fas fa-users"></i> User Type
                                </label>
                                <select name="user_type" id="unlock_user_type" required>
                                    <option value="">Select User Type</option>
                                    <option value="staff">👨‍🏫 Staff</option>
                                    <option value="student">👨‍🎓 Student</option>
                                    <option value="parent">👨‍👩‍👧‍👦 Parent</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="unlock_user_identifier">
                                    <i class="fas fa-id-card"></i> User Identifier
                                </label>
                                <input type="text" name="user_identifier" id="unlock_user_identifier" 
                                       placeholder="Username/Student ID/Phone" required>
                            </div>
                            <div class="form-group">
                                <button type="submit" class="btnn btnn-unlock">
                                    <i class="fas fa-unlock"></i> Unlock Account
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Login Statistics -->
            <div class="section slide-in">
                <div class="section-header">
                    <h2><i class="fas fa-chart-bar"></i> System Statistics (Last 7 Days)</h2>
                </div>
                <div class="section-content">
                    <?php
                    $totalAttempts = 0;
                    $successfulLogins = 0;
                    $failedLogins = 0;
                    $lockedAccountsCount = count($lockedAccounts);

                    foreach ($loginStats as $stat) {
                        $totalAttempts += $stat['count'];
                        if ($stat['success'] == 1) {
                            $successfulLogins += $stat['count'];
                        } else {
                            $failedLogins += $stat['count'];
                        }
                    }
                    ?>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-sign-in-alt"></i>
                            </div>
                            <div class="stat-number"><?= $totalAttempts ?></div>
                            <div class="stat-label">Total Attempts</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="stat-number"><?= $successfulLogins ?></div>
                            <div class="stat-label">Successful Logins</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-times-circle"></i>
                            </div>
                            <div class="stat-number"><?= $failedLogins ?></div>
                            <div class="stat-label">Failed Attempts</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-user-lock"></i>
                            </div>
                            <div class="stat-number"><?= $lockedAccountsCount ?></div>
                            <div class="stat-label">Locked Accounts</div>
                        </div>
                    </div>

                    <?php if (!empty($loginStats)): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-calendar"></i> Date</th>
                                        <th><i class="fas fa-users"></i> User Type</th>
                                        <th><i class="fas fa-info-circle"></i> Status</th>
                                        <th><i class="fas fa-hashtag"></i> Count</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($loginStats as $stat): ?>
                                        <tr>
                                            <td><?= date('M j, Y', strtotime($stat['date'])) ?></td>
                                            <td><?= ucfirst(htmlspecialchars($stat['user_type'])) ?></td>
                                            <td>
                                                <?php if ($stat['success'] == 1): ?>
                                                    <span class="status-success">
                                                        <i class="fas fa-check-circle"></i> Success
                                                    </span>
                                                <?php else: ?>
                                                    <span class="status-failed">
                                                        <i class="fas fa-times-circle"></i> Failed
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= $stat['count'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Currently Locked Accounts -->
            <div class="section slide-in">
                <div class="section-header">
                    <h2><i class="fas fa-user-slash"></i> Currently Locked Accounts</h2>
                </div>
                <div class="section-content">
                    <?php if (empty($lockedAccounts)): ?>
                        <div class="empty-state">
                            <i class="fas fa-shield-alt"></i>
                            <h3>No Locked Accounts</h3>
                            <p>All accounts are currently active and accessible.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-user"></i> User Type</th>
                                        <th><i class="fas fa-id-card"></i> Identifier</th>
                                        <th><i class="fas fa-exclamation-triangle"></i> Failed Attempts</th>
                                        <th><i class="fas fa-clock"></i> Locked Until</th>
                                        <th><i class="fas fa-tag"></i> Lock Type</th>
                                        <th><i class="fas fa-cogs"></i> Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lockedAccounts as $account): ?>
                                        <tr>
                                            <td><?= ucfirst(htmlspecialchars($account['user_type'])) ?></td>
                                            <td><?= htmlspecialchars($account['identifier']) ?></td>
                                            <td><?= $account['failed_login_attempts'] ?></td>
                                            <td>
                                                <?php if ($account['locked_by_admin'] == 1): ?>
                                                    <span class="status-badge status-admin">Admin Locked</span>
                                                <?php else: ?>
                                                    <?= $account['locked_until'] ? date('M j, Y g:i A', strtotime($account['locked_until'])) : 'Permanent' ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($account['locked_by_admin'] == 1): ?>
                                                    <span class="status-badge status-admin">
                                                        <i class="fas fa-user-shield"></i> Admin Lock
                                                    </span>
                                                <?php else: ?>
                                                    <span class="status-badge status-attempts">
                                                        <i class="fas fa-exclamation-circle"></i> Failed Attempts
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <form method="POST" style="display: inline;" class="unlock-form">
                                                    <input type="hidden" name="action" value="unlock">
                                                    <input type="hidden" name="user_type" value="<?= $account['user_type'] ?>">
                                                    <input type="hidden" name="user_identifier" value="<?= $account['identifier'] ?>">
                                                    <button type="submit" class="btnn btnn-unlock" 
                                                            style="padding: 8px 16px; font-size: 0.9rem;"
                                                            onclick="return confirm('Are you sure you want to unlock this account?')">
                                                        <i class="fas fa-unlock"></i> Unlock
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Advanced Notification System
        class NotificationSystem {
            constructor() {
                this.container = document.getElementById('notificationContainer');
                this.notifications = [];
            }

            show(message, type = 'success', duration = 5000) {
                const notification = this.createNotification(message, type, duration);
                this.container.appendChild(notification);
                this.notifications.push(notification);

                // Trigger animation
                requestAnimationFrame(() => {
                    notification.classList.add('show');
                });

                // Auto remove
                setTimeout(() => {
                    this.remove(notification);
                }, duration);

                return notification;
            }

            createNotification(message, type, duration) {
                const notification = document.createElement('div');
                notification.className = `notification ${type}`;
                
                const icon = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
                
                notification.innerHTML = `
                    <div class="notification-content">
                        <div class="notification-icon">
                            <i class="${icon}"></i>
                        </div>
                        <div class="notification-text">${message}</div>
                        <button class="notification-close" onclick="notificationSystem.remove(this.parentElement.parentElement)">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="notification-progress"></div>
                `;

                return notification;
            }

            remove(notification) {
                if (notification && notification.parentElement) {
                    notification.style.transform = 'translateX(400px)';
                    notification.style.opacity = '0';
                    
                    setTimeout(() => {
                        if (notification.parentElement) {
                            notification.parentElement.removeChild(notification);
                        }
                        const index = this.notifications.indexOf(notification);
                        if (index > -1) {
                            this.notifications.splice(index, 1);
                        }
                    }, 300);
                }
            }

            clear() {
                this.notifications.forEach(notification => {
                    this.remove(notification);
                });
            }
        }

        // Initialize notification system
        const notificationSystem = new NotificationSystem();

        // Show PHP messages as notifications
        <?php if ($message): ?>
            document.addEventListener('DOMContentLoaded', function() {
                notificationSystem.show(
                    '<?= addslashes($message) ?>',
                    '<?= $messageType ?>',
                    <?= $messageType === 'success' ? 5000 : 7000 ?>
                );
            });
        <?php endif; ?>

        // Enhanced Form Handling
        document.addEventListener('DOMContentLoaded', function() {
            // Add loading states to forms
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitbtnn = this.querySelector('button[type="submit"]');
                    if (submitbtnn) {
                        const originalText = submitbtnn.innerHTML;
                        submitbtnn.innerHTML = '<div class="loading"></div> Processing...';
                        submitbtnn.disabled = true;
                        
                        // Re-enable after 3 seconds (fallback)
                        setTimeout(() => {
                            submitbtnn.innerHTML = originalText;
                            submitbtnn.disabled = false;
                        }, 3000);
                    }
                });
            });

            // Form validation with better UX
            const validateForm = (form) => {
                const userIdentifier = form.querySelector('input[name="user_identifier"]');
                const userType = form.querySelector('select[name="user_type"]');
                
                let isValid = true;
                let errorMessage = '';

                if (userType && !userType.value) {
                    errorMessage = 'Please select a user type.';
                    isValid = false;
                    userType.focus();
                } else if (userIdentifier && !userIdentifier.value.trim()) {
                    errorMessage = 'Please enter a user identifier.';
                    isValid = false;
                    userIdentifier.focus();
                }

                if (!isValid) {
                    notificationSystem.show(errorMessage, 'error', 4000);
                }

                return isValid;
            };

            // Add validation to forms
            document.getElementById('lockForm').addEventListener('submit', function(e) {
                if (!validateForm(this)) {
                    e.preventDefault();
                }
            });

            document.getElementById('unlockForm').addEventListener('submit', function(e) {
                if (!validateForm(this)) {
                    e.preventDefault();
                }
            });

            // Add confirmation dialogs with better styling
            document.querySelectorAll('.unlock-form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const userType = this.querySelector('input[name="user_type"]').value;
                    const userIdentifier = this.querySelector('input[name="user_identifier"]').value;
                    
                    if (confirm(`Are you sure you want to unlock the ${userType} account: ${userIdentifier}?`)) {
                        this.submit();
                    }
                });
            });
        });

        // Enhanced interactions
        document.addEventListener('DOMContentLoaded', function() {
            // Add hover effects to table rows
            const tableRows = document.querySelectorAll('tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.01)';
                });
                
                row.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                });
            });

            // Add click effects to buttons
            const buttons = document.querySelectorAll('.btnn');
            buttons.forEach(button => {
                button.addEventListener('click', function(e) {
                    const ripple = document.createElement('span');
                    const rect = this.getBoundingClientRect();
                    const size = Math.max(rect.height, rect.width);
                    const x = e.clientX - rect.left - size / 2;
                    const y = e.clientY - rect.top - size / 2;
                    
                    ripple.style.cssText = `
                        position: absolute;
                        width: ${size}px;
                        height: ${size}px;
                        left: ${x}px;
                        top: ${y}px;
                        background: rgba(255,255,255,0.5);
                        border-radius: 50%;
                        transform: scale(0);
                        animation: ripple 0.6s ease-out;
                        pointer-events: none;
                    `;
                    
                    this.appendChild(ripple);
                    
                    setTimeout(() => {
                        ripple.remove();
                    }, 600);
                });
            });

            // Add CSS for ripple animation
            const style = document.createElement('style');
            style.textContent = `
                @keyframes ripple {
                    to {
                        transform: scale(2);
                        opacity: 0;
                    }
                }
            `;
            document.head.appendChild(style);
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + L = Focus lock form
            if (e.ctrlKey && e.key === 'l') {
                e.preventDefault();
                document.getElementById('lock_user_identifier').focus();
                notificationSystem.show('Lock form focused', 'success', 2000);
            }
            
            // Ctrl + U = Focus unlock form
            if (e.ctrlKey && e.key === 'u') {
                e.preventDefault();
                document.getElementById('unlock_user_identifier').focus();
                notificationSystem.show('Unlock form focused', 'success', 2000);
            }
            
            // Escape = Clear notifications
            if (e.key === 'Escape') {
                notificationSystem.clear();
            }
        });

        // Auto-refresh statistics every 30 seconds
        setInterval(() => {
            // You can implement auto-refresh here if needed
            console.log('Auto-refresh triggered');
        }, 30000);

        // Progressive enhancement for better performance
        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('fade-in');
                    }
                });
            });

            document.querySelectorAll('.section').forEach(section => {
                observer.observe(section);
            });
        }
    </script>
</body>
</html>