<?php
// ============================================
// DYNAMIC PATH DETECTION SYSTEM (FIXED)
// ============================================

// Get the current script's directory relative to document root
$current_script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME']);
$doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);

// Remove the document root from the script path to get the relative folder
$relative_path = str_replace($doc_root, '', dirname($current_script));

// Clean up slashes
$clean_path = trim($relative_path, '/');

// Count directory depth correctly
// If clean_path is empty, we are at root (0). Otherwise, count folders.
if ($clean_path === "") {
    $depth = 0;
} else {
    $depth = count(explode('/', $clean_path));
}

// Create base path (e.g., '../' for 1 level deep, '../../' for 2 levels)
$base_path = ($depth > 0) ? str_repeat('../', $depth) : '';

// Helper function for creating correct paths
function nav_path($path) {
    global $base_path;
    // Ensure we don't have double slashes when prepending
    return $base_path . ltrim($path, '/');
}

// Include required files with dynamic paths
require_once nav_path('auth.php');
require_once nav_path('conn.php');
require_once nav_path('role_permissions.php');

// Ensure connection is active.
// mysqli_ping() throws an exception (not just returns false) in PHP 8.1+ when
// the connection object exists but has already been closed. We catch that,
// null out $conn so conn.php creates a brand-new connection, then re-require it.
$_nav_conn_alive = false;
if (isset($conn) && $conn instanceof mysqli) {
    try {
        $_nav_conn_alive = @mysqli_ping($conn);
    } catch (Throwable $_nav_e) {
        // Connection object is closed — force a fresh one below
        $conn = null;
    }
}
if (!$_nav_conn_alive) {
    $conn = null; // ensure conn.php always opens a fresh connection
    require nav_path('conn.php');
}
unset($_nav_conn_alive, $_nav_e);

// Fetch all feature settings for the school
$feature_flags = [];
$query = "SELECT feature_key, is_enabled FROM feature_settings";
$result = mysqli_query($conn, $query);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $feature_flags[$row['feature_key']] = (bool) $row['is_enabled'];
    }
}

$user_role = $_SESSION['role'] ?? 'guest';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SchoolPilot</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
        :root {

            --accent-color: #4895ef;
            --text-color: black;
            --light-text: #666;
            --bg-color: #ffffff;
            --white: #ffffff;
            --sidebar-width: 280px;
            --box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
            --danger-color: #e63946;
            --success-color: #38b000;
            --warning-color: #ffb703;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;

        }

        body {
            font-family: "Sen", sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: var(--transition);
        }

        /* Overlay */
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
            z-index: 999;
        }

        .overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* headers */
        .headers {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 60px;
            background-color: var(--white);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            box-shadow: var(--box-shadow);
            z-index: 100;
            transition: var(--transition);
        }

        .headers-left {
            display: flex;
            align-items: center;
        }

        .hamburger-menu {
            font-size: 20px;
            cursor: pointer;
            color: var(--primary-color);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: var(--transition);
        }

        .hamburger-menu:hover {
            background-color: rgba(67, 97, 238, 0.1);
        }

        .logo-text {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary-color);
            margin-left: 15px;
        }

        .headers-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-profile-mini {
            display: flex;
            align-items: center;
            cursor: pointer;
            padding: 5px;
            border-radius: 5px;
            transition: var(--transition);
        }

        .user-profile-mini:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }



        .dropdown-arrow {
            font-size: 12px;
            transition: transform 0.3s ease;
        }

        .user-profile-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            width: 220px;
            background: #fff;
            border-radius: 4px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            padding: 8px 0;
            z-index: 1000;
            display: none;
            animation: fadeIn 0.2s ease-in-out;
        }

        .user-profile-dropdown a {
            display: flex;
            align-items: center;
            padding: 8px 16px;
            color: #333;
            text-decoration: none;
            transition: all 0.2s;
        }

        .user-profile-dropdown a i {
            margin-right: 10px;
            width: 18px;
            text-align: center;
        }

        .user-profile-dropdown a:hover {
            background-color: #f5f5f5;
            color: #000;
        }

        .dropdown-divider {
            height: 1px;
            background-color: #e9ecef;
            margin: 8px 0;
        }

        .logout-link {
            color: #dc3545 !important;
        }

        .logout-link:hover {
            background-color: #f8d7da !important;
        }

        /* When dropdown is active */
        .user-profile-mini.active .dropdown-arrow {
            transform: rotate(180deg);
        }

        .user-profile-mini.active .user-profile-dropdown {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .user-avatar-mini {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-color);
        }

        .user-info-mini {
            margin-left: 10px;
            display: none;
        }

        @media (min-width: 768px) {
            .user-info-mini {
                display: block;
            }
        }

        .user-name-mini {
            font-size: 14px;
            font-weight: 500;
        }

        .user-role {
            font-size: 12px;
            color: var(--light-text);
        }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            background-color: var(--white);
            box-shadow: var(--box-shadow);
            position: fixed;
            top: 0;
            left: -100%;
            transition: var(--transition);
            z-index: 1000;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        .sidebar.active {
            left: 0;
        }

        /* Logo Section */
        .sidebar-headers {
            padding: 15px 20px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            height: 60px;
        }

        .logo-container {
            display: flex;
            align-items: center;
        }

        .logo {
            width: 35px;
            height: 35px;
            object-fit: cover;
        }

        .sidebar-logo-text {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary-color);
            margin-left: 10px;
        }

        /* User Profile */
        .user-profile {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .user-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-color);
            margin-bottom: 10px;
        }

        .user-name {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .user-email {
            font-size: 13px;
            color: var(--light-text);
        }

        /* Navigation Menu */
        .nav-menu {
            flex: 1;
            overflow-y: auto;
            padding: 15px 0;
        }

        .nav-menu ul {
            list-style: none;
        }

        .nav-menu li a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: var(--text-color);
            text-decoration: none;
            transition: var(--transition);
            border-radius: 0 30px 30px 0;
            margin-right: 15px;
        }

        .nav-menu li a:hover,
        .nav-menu li a.active {
            background-color: rgba(67, 97, 238, 0.1);
            color: var(--primary-color);
        }

        .nav-menu li a i {
            width: 20px;
            text-align: center;
            margin-right: 10px;
            font-size: 16px;
        }

        .menu-title {
            font-size: 14px;
            margin-top: 15px;
            padding: 0 20px;
            color: var(--light-text);
            text-transform: uppercase;
            font-weight: 500;
            font-size: 11px;
            letter-spacing: 0.5px;
        }

        /* Submenu Styling */
        .has-submenu .submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .has-submenu.active .submenu {
            max-height: 500px;
        }

        .submenu li a {
            padding: 10px 20px 10px 50px;
            font-size: 14px;
            color: var(--light-text);
        }

        .submenu li a i {
            font-size: 14px;
        }

        /* Dropdown Icon */
        .dropdown-icon {
            margin-left: auto;
            transition: transform 0.3s ease;
        }

        .has-submenu.active .dropdown-icon {
            transform: rotate(180deg);
        }

        /* Logout Section */
        .sidebar-footer {
            padding: 15px 20px;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
        }

        .logout-btns {
            font-family: "Sen", sans-serif !important;
            display: flex;
            align-items: center;
            padding: 10px 15px;
            background-color: rgba(230, 57, 70, 0.1);
            color: var(--danger-color);
            border-radius: 5px;
            text-decoration: none;
            transition: var(--transition);
        }

        .logout-btns:hover {
            background-color: var(--danger-color);
            color: var(--white);
        }

        .logout-btns i {
            margin-right: 10px;
        }

        /* Main Content */
        .main-content {
            margin-left: 0;
            padding: 80px 20px 20px;
            transition: var(--transition);
        }

        .sidebar.active~.main-content {
            margin-left: var(--sidebar-width);
        }

        /* Dashboard Widgets */
        .dashboard-widgets {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .widget {
            background-color: var(--white);
            border-radius: 10px;
            padding: 20px;
            box-shadow: var(--box-shadow);
            display: flex;
            align-items: center;
        }

        .widget-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 20px;
            color: var(--white);
        }

        .widget-icon.blue {
            background-color: var(--primary-color);
        }

        .widget-icon.green {
            background-color: var(--success-color);
        }

        .widget-icon.orange {
            background-color: var(--warning-color);
        }

        .widget-icon.red {
            background-color: var(--danger-color);
        }

        .widget-info h3 {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .widget-info p {
            font-size: 13px;
            color: var(--light-text);
        }

        /* Quick Actions */
        .quick-actions {
            background-color: var(--white);
            border-radius: 10px;
            padding: 20px;
            box-shadow: var(--box-shadow);
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--text-color);
            display: flex;
            align-items: center;
        }

        .section-title i {
            margin-right: 10px;
            color: var(--primary-color);
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
        }

        .action-card {
            background-color: var(--bg-color);
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
        }

        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow);
        }

        .action-icon {
            font-size: 24px;
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .action-title {
            font-size: 14px;
            font-weight: 500;
        }

        /* Recent Activities & Stock Alerts */
        .dashboard-bottom {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        @media (min-width: 992px) {
            .dashboard-bottom {
                grid-template-columns: 2fr 1fr;
            }
        }

        .recent-activities,
        .stock-alerts {
            background-color: var(--white);
            border-radius: 10px;
            padding: 20px;
            box-shadow: var(--box-shadow);
        }

        .activity-list,
        .alert-list {
            margin-top: 15px;
        }

        .activity-item,
        .alert-item {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .activity-item:last-child,
        .alert-item:last-child {
            border-bottom: none;
        }

        .activity-icon,
        .alert-icon {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 14px;
            color: var(--white);
        }

        .activity-details,
        .alert-details {
            flex: 1;
        }

        .activity-title,
        .alert-title {
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 5px;
        }

        .activity-time,
        .alert-info {
            font-size: 12px;
            color: var(--light-text);
        }

        .alert-action {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btns {
            font-family: "Sen", sans-serif !important;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            outline: none;
        }

        .btns-primary {
            background-color: var(--primary-color);
            color: var(--white);
        }

        .btns-primary:hover {
            background-color: var(--secondary-color);
        }

        .btns-danger {
            background-color: var(--danger-color);
            color: var(--white);
        }

        .btns-danger:hover {
            background-color: #d90429;
        }

        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .sidebar.active~.main-content {
                margin-left: 0;
            }

            .sidebar.active {
                width: 100%;
            }

            .headers {
                left: 0;
            }
        }



        .user-role-badge {
            display: inline-block;
            background-color: rgba(0, 123, 255, 0.2);
            color: var(--accent-color);
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
            margin-top: 5px;
        }

        /* Loading Animation */
        .loading {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: var(--bg-color);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid rgba(0, 0, 0, 0.1);
            border-left-color: var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Logout Modal Styles */
        .logout-modal {
            display: none;
            position: fixed;
            z-index: 1001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }

        .logout-modal-content {
            background-color: #fff;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            animation: modalFadeIn 0.3s ease-out;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.95) translateY(-20px);
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .logout-modal-body {
            padding: 30px;
            display: flex;
            align-items: flex-start;
            gap: 20px;
        }

        .logout-modal-body .warning-icon {
            width: 48px;
            height: 48px;
            background-color: #fff3cd;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .logout-modal-body .warning-icon i {
            font-size: 24px;
            color: #ff9800;
            margin: 0;
        }

        .logout-modal-text {
            flex: 1;
            padding-top: 4px;
        }

        .logout-modal-text h3 {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin: 0 0 8px 0;
        }

        .logout-modal-text p {
            margin: 0;
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }

        .logout-modal-text .logout-message {
            font-size: 13px;
            color: #999;
            margin-top: 6px;
        }

        .logout-modal-footer {
            padding: 16px 30px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background-color: #f8f9fa;
            border-top: 1px solid #e9ecef;
        }

        .logout-modal-footer button {
            padding: 10px 28px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            outline: none;
        }

        .cancel-logout {
            font-family: inherit;
            background-color: #fff;
            border: 1px solid #ddd;
            color: #555;
        }

        .cancel-logout:hover {
            background-color: #f8f9fa;
            border-color: #999;
        }

        .confirm-logout {
            font-family: inherit;
            background-color: #dc3545;
            color: white;
            border: 1px solid #dc3545;
        }

        .confirm-logout:hover {
            background-color: #c82333;
            border-color: #bd2130;
            box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3);
        }

        .confirm-logout:active {
            transform: translateY(1px);
        }
    </style>
</head>

<body>
<?php require_once 'includes/Connection_Status.php';?>
    <!-- Overlay -->
    <div class="overlay" id="overlay"></div>

    <!-- Headers -->
    <headers class="headers">
        <div class="headers-left">
            <div class="hamburger-menu" id="hamburger-menu">
                <i class="fas fa-bars"></i>
            </div>
            <div class="logo-text">SchoolPilot</div>
        </div>
        <div class="headers-right">
            <div class="user-profile-mini" id="user-profile-dropdown-trigger">
                <img src="<?php echo nav_path('images/avartor.png'); ?>" alt="User Avatar" class="user-avatar-mini">
                <div class="user-info-mini">
                    <div class="user-name-mini"><?php echo htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['user_id']); ?></div>
                    <div class="user-role">
                        <?php
                        if ($_SESSION['user_type'] === 'student') {
                            echo 'Student';
                        } elseif ($_SESSION['user_type'] === 'parent') {
                            echo 'Parent';
                        } else {
                            echo htmlspecialchars($_SESSION['role'] ?? 'User');
                        }
                        ?>
                    </div>
                </div>
                <i class="fas fa-chevron-down dropdown-arrow"></i>

                <!-- Dropdown menu -->
                <div class="user-profile-dropdown" id="user-profile-dropdown">
                    <a href="<?php echo nav_path('reset_password.php'); ?>"><i class="fas fa-key"></i> Reset Password</a>
                    <div class="dropdown-divider"></div>
                    <a href="<?php echo nav_path('logout.php'); ?>" class="logout-link">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </headers>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-headers">
            <div class="logo-container">
                <span class="sidebar-logo-text">SchoolPilot</span>
            </div>
        </div>
        
        <!-- Navigation Menu -->
        <nav class="nav-menu">
            <ul>
                <div class="menu-title">Main</div>
                <?php if ($feature_flags['dashboard'] ?? true): ?>
                    <li>
                        <a href="<?php echo nav_path('dashboard.php'); ?>" class="active">
                            <i class="fas fa-home"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('students', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#Students">
                            <i class="fas fa-user-graduate"></i>
                            <span>Students</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="<?php echo nav_path('add_student.php'); ?>"><i class="fas fa-user-plus"></i> Add Student</a></li>
                            <li><a href="<?php echo nav_path('view_students.php'); ?>"><i class="fas fa-users"></i> View Students</a></li>
                            <li><a href="<?php echo nav_path('students_list.php'); ?>"><i class="fas fa-list-ol"></i> Students List</a></li>
                            <li><a href="<?php echo nav_path('parents_list.php'); ?>"><i class="fas fa-user-friends"></i> Parents List</a></li>
                            <li><a href="<?php echo nav_path('students_promotion.php'); ?>"><i class="fas fa-edit"></i>Promote Students</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('staff', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#staff">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <span>Staff</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="<?php echo nav_path('add_staff.php'); ?>"><i class="fas fa-user-plus"></i>Add Staff</a></li>
                            <li><a href="<?php echo nav_path('view_staff.php'); ?>"><i class="fas fa-users"></i>View Staff</a></li>
                            <li><a href="<?php echo nav_path('staff_list.php'); ?>"><i class="fas fa-users"></i>Staff List</a></li>
                            <li><a href="<?php echo nav_path('teaching.php'); ?>"><i class="fas fa-users"></i>Staff Roles</a></li>
                            <li><a href="<?php echo nav_path('class_teachers.php'); ?>"><i class="fas fa-chalkboard-teacher"></i> Class Teachers</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('attendance', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#Attendance">
                            <i class="fas fa-calendar-check"></i>
                            <span>Attendance</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="<?php echo nav_path('select_attendance_class.php'); ?>"><i class="fas fa-user-plus"></i>Add student Attendance</a></li>
                            <li><a href="<?php echo nav_path('view_student_attendance.php'); ?>"><i class="fas fa-search"></i>view student Attendance</a></li>
                            <?php if (in_array($user_role, ['developer', 'super user', 'school leader'])): ?>
                                <li><a href="<?php echo nav_path('record_staff_attendance.php'); ?>"><i class="fas fa-user-plus"></i>Add staff Attendance</a></li>
                                <li><a href="<?php echo nav_path('view_staff_attendance_report.php'); ?>"><i class="fas fa-search"></i>View staff Attendance</a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('discipline', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#Discipline">
                            <i class="fas fa-gavel"></i>
                            <span>Discipline</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="<?php echo nav_path('add_student_discpline.php'); ?>"><i class="fas fa-plus-circle"></i>Add Student Discipline</a></li>
                            <li><a href="<?php echo nav_path('view_student_discpline.php'); ?>"><i class="fas fa-list"></i>View Student Discipline</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('assets', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#assets">
                            <i class="fas fa-building"></i>
                            <span>Assets</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li class="submenu-header">Asset Registry</li>
                            <li><a href="<?php echo nav_path('add_asset.php'); ?>"><i class="fas fa-plus-circle"></i>Add New Asset</a></li>
                            <li><a href="<?php echo nav_path('view_assets.php'); ?>"><i class="fas fa-list"></i>View All Assets</a></li>
                            <li><a href="<?php echo nav_path('asset_categories.php'); ?>"><i class="fas fa-tags"></i>Asset Categories</a></li>
                            <li><a href="<?php echo nav_path('asset_disposal.php'); ?>"><i class="fas fa-trash-alt"></i>Asset Disposal</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('stationery', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#stationery">
                            <i class="fas fa-pencil-ruler"></i>
                            <span>Stationery Supplies</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li class="submenu-header">Inventory Management</li>
                            <li><a href="<?php echo nav_path('add_stationery_item.php'); ?>"><i class="fas fa-plus-circle"></i>Add Stationery Item</a></li>
                            <li><a href="<?php echo nav_path('stationery_inventory.php'); ?>"><i class="fas fa-warehouse"></i>Stationery Inventory</a></li>
                            <li><a href="<?php echo nav_path('stationery_categories.php'); ?>"><i class="fas fa-folder-open"></i>Item Categories</a></li>
                            <li><a href="<?php echo nav_path('low_stock_alerts.php'); ?>"><i class="fas fa-exclamation-triangle"></i>Low Stock Alerts</a></li>
                            <li><a href="<?php echo nav_path('stock_out.php'); ?>"><i class="fas fa-minus-circle"></i>Withdrawals</a></li>
                            <li><a href="<?php echo nav_path('stationery_adjustments.php'); ?>"><i class="fas fa-exchange-alt"></i>Adjustments</a></li>
                            <li><a href="<?php echo nav_path('stationery_usage_reports.php'); ?>"><i class="fas fa-chart-pie"></i>Usage Reports</a></li>
                            <li><a href="<?php echo nav_path('stationery_costs.php'); ?>"><i class="fas fa-dollar-sign"></i>Cost Analysis</a></li>
                            <li><a href="<?php echo nav_path('supplier_management.php'); ?>"><i class="fas fa-truck"></i>Supplier Management</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('laboratory', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#laboratory">
                            <i class="fas fa-flask"></i>
                            <span>Science Laboratory</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="<?php echo nav_path('add_lab_equipment.php'); ?>"><i class="fas fa-plus-circle"></i>Add Equipment</a></li>
                            <li><a href="<?php echo nav_path('lab_chemicals.php'); ?>"><i class="fas fa-prescription-bottle"></i>Chemicals</a></li>
                            <li><a href="<?php echo nav_path('chemicals_withdraws.php'); ?>"><i class="fas fa-prescription-bottle"></i>Chemical Withdraws</a></li>
                            <li><a href="<?php echo nav_path('lab_schedule.php'); ?>"><i class="fas fa-calendar-week"></i>Lab Schedule</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('visitors', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#visitors">
                            <i class="fas fa-address-book"></i>
                            <span>Visitors Book</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="<?php echo nav_path('visitor_dashboard.php'); ?>"><i class="fas fa-tachometer-alt"></i>Visitors Dashboard</a></li>
                            <li><a href="<?php echo nav_path('add_visitor.php'); ?>"><i class="fas fa-user-plus"></i>Add Visitor</a></li>
                            <li><a href="<?php echo nav_path('view_visitors.php'); ?>"><i class="fas fa-users"></i>View Visitors</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('gatepass', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#gatepass">
                            <i class="fas fa-address-book"></i>
                            <span>Students Gate pass</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="<?php echo nav_path('issue_student_pass.php'); ?>"><i class="fas fa-user-plus"></i>Make Gate pass</a></li>
                            <li><a href="<?php echo nav_path('view_gate_passes.php'); ?>"><i class="fas fa-users"></i>View Gate pass</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('school_requirements', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#school-requirements">
                            <i class="fas fa-clipboard-list"></i>
                            <span>Requirements</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="<?php echo nav_path('requirements_setup.php'); ?>"><i class="fas fa-cogs"></i>Setup Requirements</a></li>
                            <li><a href="<?php echo nav_path('record_requirements.php'); ?>"><i class="fas fa-pen-alt"></i>Record Requirements</a></li>
                            <li><a href="<?php echo nav_path('requirements_reports.php'); ?>"><i class="fas fa-chart-bar"></i>Requirements Reports</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('kitchen', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#kitchen">
                            <i class="fas fa-utensils"></i>
                            <span>Kitchen</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="<?php echo nav_path('food_inventory.php'); ?>"><i class="fas fa-warehouse"></i>Food Inventory</a></li>
                            <li><a href="<?php echo nav_path('food_withdrawals.php'); ?>"><i class="fas fa-box-open"></i> Food Withdrawals</a></li>
                            <li><a href="<?php echo nav_path('meal_planner.php'); ?>"><i class="fas fa-clipboard-list"></i>Meal Planner</a></li>
                            <li><a href="<?php echo nav_path('kitchen_staff.php'); ?>"><i class="fas fa-users"></i>Kitchen Staff</a></li>
                            <li><a href="<?php echo nav_path('kitchen_reports.php'); ?>"><i class="fas fa-chart-pie"></i>Reports</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('o_level', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#olevel-curriculum">
                            <i class="fas fa-school"></i>
                            <span>O Level Curriculum</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="<?php echo nav_path('manage_subjects.php'); ?>"><i class="fas fa-plus"></i>Manage Subjects</a></li>
                            <li><a href="<?php echo nav_path('O_Level/sel_add_aoi.php'); ?>"><i class="fas fa-plus"></i>Add AOIs</a></li>
                            <li><a href="<?php echo nav_path('O_Level/assign_optional_subjects.php'); ?>"><i class="fas fa-plus"></i>Add Electives</a></li>
                            <li><a href="<?php echo nav_path('O_Level/view_student_subjects.php'); ?>"><i class="fas fa-file-alt"></i>Student Electives</a></li>
                            <li><a href="<?php echo nav_path('O_Level/sel_targets.php'); ?>"><i class="fas fa-file-alt"></i>Enter targets</a></li>
                            <li><a href="<?php echo nav_path('O_Level/sel_add_marks.php'); ?>"><i class="fas fa-edit"></i>Add Marks</a></li>
                            <li><a href="<?php echo nav_path('O_Level/sel_gen_marksheet.php'); ?>"><i class="fas fa-file-alt"></i>MarkSheet</a></li>
                            <li><a href="<?php echo nav_path('O_Level/sel_generate_report.php'); ?>"><i class="fas fa-file-pdf"></i>All Reports</a></li>
                            <li><a href="<?php echo nav_path('O_Level/sel_gen_ind_report.php'); ?>"><i class="fas fa-user-graduate"></i>individual Reports</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('a_level', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#alevel-curriculum">
                            <i class="fas fa-university"></i>
                            <span>A Level Curriculum</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="<?php echo nav_path('A_Level/manage_exam_sets.php'); ?>"><i class="fas fa-edit"></i>Manage Exam Sets</a></li>
                            <li><a href="<?php echo nav_path('A_Level/assign_papers.php'); ?>"><i class="fas fa-edit"></i>Assign Papers</a></li>
                            <li><a href="<?php echo nav_path('A_Level/assign_alevel_subjects.php'); ?>"><i class="fas fa-pen-to-square"></i>Assign subjects</a></li>
                            <li><a href="<?php echo nav_path('A_Level/view_alevel_subjects.php'); ?>"><i class="fas fa-person-chalkboard"></i>Students Subjects</a></li>
                            <li><a href="<?php echo nav_path('A_Level/sel_alevel_targets.php'); ?>"><i class="fas fa-file-alt"></i>Enter targets</a></li>
                            <li><a href="<?php echo nav_path('A_Level/sel_add_marks.php'); ?>"><i class="fas fa-edit"></i>Add Marks</a></li>
                            <li><a href="<?php echo nav_path('A_Level/sel_gen_markshet.php'); ?>"><i class="fas fa-file-alt"></i>MarkSheet</a></li>
                            <li><a href="<?php echo nav_path('A_Level/sel_gen_reports.php'); ?>"><i class="fas fa-file-pdf"></i>All Reports</a></li>
                            <li><a href="<?php echo nav_path('A_Level/alevel_analysis_dashboard.php'); ?>"><i class="fas fa-file-pdf"></i>Results Analysis</a></li>
                            <li><a href="<?php echo nav_path('A_Level/view_marks_audit.php'); ?>"><i class="fas fa-scroll"></i>Marks Audit Logs</a></li>

                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('timetable', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#timetable">
                            <i class="fas fa-calendar-alt"></i>
                            <span>TimeTable</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="<?php echo nav_path('school_timetable.php'); ?>"><i class="fas fa-calendar-alt"></i>Class Timetable</a></li>
                            <li><a href="<?php echo nav_path('exams_timetable.php'); ?>"><i class="fas fa-calendar-alt"></i>Exams Timetable</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('health', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#health">
                            <i class="fas fa-heartbeat"></i>
                            <span>Health</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="<?php echo nav_path('nurse_dashboard.php'); ?>"><i class="fas fa-tachometer-alt"></i>Nurse Dashboard</a></li>
                            <li><a href="<?php echo nav_path('inventory.php'); ?>"><i class="fas fa-medkit"></i>Inventory</a></li>
                            <li><a href="<?php echo nav_path('Add_visit.php'); ?>"><i class="fas fa-plus"></i>Add Visit</a></li>
                            <li><a href="<?php echo nav_path('visit_reports.php'); ?>"><i class="fas fa-file-medical"></i>Visit Reports</a></li>
                            <li><a href="<?php echo nav_path('withdraw_medicine.php'); ?>"><i class="fas fa-minus-circle"></i>Dispense</a></li>
                            <li><a href="<?php echo nav_path('dispensed_reports.php'); ?>"><i class="fas fa-file-medical"></i>Dispensed Reports</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('fees', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#fees">
                            <i class="fas fa-money-bill-wave"></i>
                            <span>Fees Payments</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="<?php echo nav_path('fees_structures.php'); ?>"><i class="fas fa-list-alt"></i>Fees Structures</a></li>
                            <li><a href="<?php echo nav_path('add_fees_payments.php'); ?>"><i class="fas fa-credit-card"></i>Add Fees Payments</a></li>
                            <li><a href="<?php echo nav_path('fees_bursaries.php'); ?>"><i class="fas fa-hand-holding-heart"></i>Fees Bursaries</a></li>
                            <li><a href="<?php echo nav_path('fees_collection_report.php'); ?>"><i class="fas fa-file-invoice-dollar"></i>Fees Collection Report</a></li>
                            <li><a href="<?php echo nav_path('daily_transactions.php'); ?>"><i class="fas fa-calendar-day"></i>Daily Transactions Report</a></li>
                            <li><a href="<?php echo nav_path('fees_analytics.php'); ?>"><i class="fas fa-chart-line"></i>Fees Analytics</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('finance_docs', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#finance-documents">
                            <i class="fas fa-file-invoice-dollar"></i>
                            <span>Finance Documents</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li class="submenu-header">Income Documents</li>
                            <li><a href="<?php echo nav_path('receipt_books.php'); ?>"><i class="fas fa-receipt"></i> Receipt Books (Duplicate)</a></li>
                            <li><a href="<?php echo nav_path('admission_receipts.php'); ?>"><i class="fas fa-user-graduate"></i> Admission Fee Receipts</a></li>
                            <li class="submenu-header">Payment Documents</li>
                            <li><a href="<?php echo nav_path('payment_vouchers.php'); ?>"><i class="fas fa-file-invoice"></i> Payment Vouchers</a></li>
                            <li><a href="<?php echo nav_path('petty_cash.php'); ?>"><i class="fas fa-coins"></i> Petty Cash Vouchers</a></li>
                            <li><a href="<?php echo nav_path('cheque_management.php'); ?>"><i class="fas fa-money-check"></i> Cheque Counterfoils</a></li>
                            <li class="submenu-header">Procurement Documents</li>
                            <li><a href="<?php echo nav_path('purchase_requisitions.php'); ?>"><i class="fas fa-clipboard-list"></i> Purchase Requisitions</a></li>
                            <li><a href="<?php echo nav_path('local_pos.php'); ?>"><i class="fas fa-file-signature"></i> Local Purchase Orders</a></li>
                            <li><a href="<?php echo nav_path('invoice.php'); ?>"><i class="fas fa-file-invoice-dollar"></i> Supplier Invoices/Bills</a></li>
                            <li><a href="<?php echo nav_path('grn.php'); ?>"><i class="fas fa-clipboard-check"></i> Goods Received Notes</a></li>
                            <li class="submenu-header">Payroll Documents</li>
                            <li><a href="<?php echo nav_path('salary_sheets.php'); ?>"><i class="fas fa-file-alt"></i> Salary Payment Sheets</a></li>
                            <li><a href="<?php echo nav_path('payroll_records.php'); ?>"><i class="fas fa-users"></i> Payroll Records</a></li>
                            <li><a href="<?php echo nav_path('staff_loans.php'); ?>"><i class="fas fa-hand-holding-usd"></i> Staff Loan/Advance Forms</a></li>
                            <li><a href="<?php echo nav_path('reimbursements.php'); ?>"><i class="fas fa-file-export"></i> Staff Reimbursements</a></li>
                            <li class="submenu-header">Budget Documents</li>
                            <li><a href="<?php echo nav_path('budget_documents.php'); ?>"><i class="fas fa-chart-pie"></i> Budget Plans</a></li>
                            <li><a href="<?php echo nav_path('budget_tracking.php'); ?>"><i class="fas fa-search-dollar"></i> Budget Tracking</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('library', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#library">
                            <i class="fas fa-book"></i>
                            <span>Library</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="<?php echo nav_path('librarian_dashboard.php'); ?>"><i class="fas fa-tachometer-alt"></i>Library Dashboard</a></li>
                            <li><a href="<?php echo nav_path('library_books.php'); ?>"><i class="fas fa-book-open"></i>Library Books</a></li>
                            <li><a href="<?php echo nav_path('lend_books.php'); ?>"><i class="fas fa-exchange-alt"></i>Lend Books</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('communication', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#communication">
                            <i class="fas fa-user-friends"></i>
                            <span>Communication</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="<?php echo nav_path('staff_email.php'); ?>"><i class="fas fa-envelope"></i>Email Staff</a></li>
                            <li><a href="<?php echo nav_path('parent_email.php'); ?>"><i class="fas fa-envelope"></i>Email Parents</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (user_has_access('parent_portal', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#parent-portal">
                            <i class="fas fa-user-friends"></i>
                            <span>Parent Portal</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="#parent-access"><i class="fas fa-user-shield"></i>Parent Access</a></li>
                            <li><a href="#student-reports"><i class="fas fa-file-alt"></i>Student Reports</a></li>
                            <li><a href="#fee-statements"><i class="fas fa-receipt"></i>Fee Statements</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <div class="menu-title">Analytics</div>
                <?php if (user_has_access('reports', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#reports">
                            <i class="fas fa-chart-line"></i>
                            <span>Reports</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="<?php echo nav_path('analysis_dashboard.php'); ?>"><i class="fas fa-chart-bar"></i>Results Analysis</a></li>
                            <li><a href="<?php echo nav_path('attendance_analytics.php'); ?>"><i class="fas fa-chart-bar"></i>student Attendance Analytics</a></li>
                            <?php if (in_array($user_role, ['developer', 'super user', 'school leader'])): ?>
                                <li><a href="<?php echo nav_path('staff_attendance_analytics.php'); ?>"><i class="fas fa-chart-bar"></i>staff Attendance Analytics</a></li>
                            <?php endif; ?>
                            <li><a href="<?php echo nav_path('students_details.php'); ?>"><i class="fas fa-user-graduate"></i>Student Details</a></li>
                            <li><a href="<?php echo nav_path('staff_details.php'); ?>"><i class="fas fa-chalkboard-teacher"></i>staff Details</a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <div class="menu-title">System</div>
                <?php if (user_has_access('settings', $user_role, $feature_flags, $role_permissions)): ?>
                    <li class="has-submenu">
                        <a href="#settings">
                            <i class="fas fa-cog"></i>
                            <span>Settings</span>
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </a>
                        <ul class="submenu">
                            <li><a href="<?php echo nav_path('school_profile.php'); ?>"><i class="fas fa-school"></i> School Profile</a></li>
                            <li><a href="<?php echo nav_path('user_roles.php'); ?>"><i class="fas fa-users-cog"></i> User Roles</a></li>
                            <li><a href="<?php echo nav_path('add_user.php'); ?>"><i class="fas fa-user-plus"></i> Add User</a></li>
                            <li><a href="<?php echo nav_path('account_management.php'); ?>"><i class="fas fa-users-cog"></i>Account Management</a></li>
                            <li><a href="<?php echo nav_path('user_logs.php'); ?>"><i class="fas fa-clipboard-list"></i> User Logs</a></li>
                            <li><a href="<?php echo nav_path('backup_restore.php'); ?>"><i class="fas fa-database"></i> Backup & Restore</a></li>
                            <?php if ($user_role === 'developer'): ?>
                                <li><a href="<?php echo nav_path('feature_manager.php'); ?>"><i class="fas fa-users-cog"></i>Feature Manager</a></li>
                                <li><a href="<?php echo nav_path('manage_subscriptions.php'); ?>"><i class="fas fa-users-cog"></i>Subscription Manager</a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>

    </main>

    <!-- Custom Logout Modal -->
    <div class="logout-modal" id="logout-modal">
        <div class="logout-modal-content">
            <div class="logout-modal-body">
                <div class="warning-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="logout-modal-text">
                    <h3>Confirm Logout</h3>
                    <p>Are you sure you want to logout?</p>
                    <p class="logout-message">Your session will be terminated and you'll need to login again to access the system.</p>
                </div>
            </div>
            <div class="logout-modal-footer">
                <button class="cancel-logout">Cancel</button>
                <button class="confirm-logout">Yes, Logout</button>
            </div>
        </div>
    </div>

    <script>
        // Wait for the page to load
        window.addEventListener('load', function() {
            // Hide loading animation
            setTimeout(function() {
                const loadingEl = document.getElementById('loading');
                if (loadingEl) loadingEl.style.display = 'none';
            }, 500);
        });

        // Toggle Sidebar
        const hamburgerMenu = document.getElementById('hamburger-menu');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');
        const mainContent = document.querySelector('.main-content');

        hamburgerMenu.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        });

        // Close sidebar when clicking on overlay or anywhere outside the sidebar
        document.addEventListener('click', function(e) {
            if (sidebar.classList.contains('active') &&
                !sidebar.contains(e.target) &&
                e.target !== hamburgerMenu &&
                !hamburgerMenu.contains(e.target)) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            }
        });

        // Submenu Toggle
        const submenus = document.querySelectorAll('.has-submenu');
        submenus.forEach(function(submenu) {
            submenu.addEventListener('click', function(e) {
                if (e.target.closest('.submenu')) return;
                e.preventDefault();
                this.classList.toggle('active');
            });
        });

        // Dropdown
        document.addEventListener('DOMContentLoaded', function() {
            const profileDropdownTrigger = document.getElementById('user-profile-dropdown-trigger');
            const profileDropdown = document.getElementById('user-profile-dropdown');
            const overlay = document.getElementById('overlay');

            profileDropdownTrigger.addEventListener('click', function(e) {
                e.stopPropagation();
                this.classList.toggle('active');
                profileDropdown.style.display = this.classList.contains('active') ? 'block' : 'none';
            });

            document.addEventListener('click', function(e) {
                if (!profileDropdown.contains(e.target) && e.target !== profileDropdownTrigger) {
                    profileDropdownTrigger.classList.remove('active');
                    profileDropdown.style.display = 'none';
                }
            });
        });

        // Logout Modal Functionality
        document.addEventListener('DOMContentLoaded', function() {
            const logoutModal = document.getElementById('logout-modal');
            const logoutLinks = document.querySelectorAll('.logout-link');
            const cancelLogout = document.querySelector('.cancel-logout');
            const confirmLogout = document.querySelector('.confirm-logout');
            const overlay = document.getElementById('overlay');

            let clickedLogoutUrl = '<?php echo nav_path("logout.php"); ?>';

            function showLogoutModal(e) {
                e.preventDefault();
                clickedLogoutUrl = e.currentTarget.href || '<?php echo nav_path("logout.php"); ?>';
                logoutModal.style.display = 'flex';
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            }

            function hideLogoutModal() {
                logoutModal.style.display = 'none';
                overlay.classList.remove('active');
                document.body.style.overflow = 'auto';
            }

            logoutLinks.forEach(link => {
                link.addEventListener('click', showLogoutModal);
            });

            if (cancelLogout) {
                cancelLogout.addEventListener('click', hideLogoutModal);
            }

            if (confirmLogout) {
                confirmLogout.addEventListener('click', function() {
                    window.location.href = clickedLogoutUrl;
                });
            }

            overlay.addEventListener('click', function() {
                if (logoutModal.style.display === 'flex') {
                    hideLogoutModal();
                }
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && logoutModal.style.display === 'flex') {
                    hideLogoutModal();
                }
            });
        });
    </script>
</body>

</html>