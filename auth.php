<?php
session_start();

// Prevent caching for sensitive pages
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// ========================================
// SESSION HIJACKING PROTECTION
// ========================================
// Validate User-Agent consistency to prevent session hijacking
if (!isset($_SESSION['HTTP_USER_AGENT'])) {
    // First time - store the user agent
    $_SESSION['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
} elseif ($_SESSION['HTTP_USER_AGENT'] !== ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown')) {
    // User agent mismatch - possible session hijacking
    error_log("Session hijacking attempt detected - User Agent mismatch for user: " . ($_SESSION['user_name'] ?? 'unknown'));
    
    // Destroy the session
    session_unset();
    session_destroy();
    
    // Start new session for error message
    session_start();
    session_regenerate_id(true);
    
    $_SESSION['notification'] = [
        'message' => 'Security alert: Your session has been terminated due to suspicious activity. Please login again.',
        'type' => 'error'
    ];
    
    header("Location: ../index.php");
    exit();
}

// ========================================
// SESSION TIMEOUT VALIDATION
// ========================================
$timeout_duration = 1800; // 30 minutes in seconds

if (isset($_SESSION['LAST_ACTIVITY'])) {
    $elapsed_time = time() - $_SESSION['LAST_ACTIVITY'];
    
    if ($elapsed_time > $timeout_duration) {
        // Session has expired due to inactivity
        $expired_user = $_SESSION['user_name'] ?? 'unknown';
        error_log("Session timeout for user: " . $expired_user);
        
        session_unset();
        session_destroy();
        
        // Start new session for timeout message
        session_start();
        session_regenerate_id(true);
        
        $_SESSION['notification'] = [
            'message' => 'Your session has expired due to inactivity. Please login again.',
            'type' => 'info'
        ];
        
        header("Location: ../index.php");
        exit();
    }
}

// Update last activity timestamp
$_SESSION['LAST_ACTIVITY'] = time();

// ========================================
// CORE AUTHENTICATION CHECK
// ========================================
// Redirect to login if essential session variables aren't set
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_name'])) {
    header("Location: ../index.php");
    exit();
}

// ========================================
// IP ADDRESS VALIDATION (Optional - Uncomment to enable)
// ========================================
// This adds an extra layer of security but may cause issues with dynamic IPs
/*
if (!isset($_SESSION['IP_ADDRESS'])) {
    $_SESSION['IP_ADDRESS'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
} elseif ($_SESSION['IP_ADDRESS'] !== ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
    // IP address changed - possible session hijacking
    error_log("Session hijacking attempt detected - IP mismatch for user: " . ($_SESSION['user_name'] ?? 'unknown'));
    
    session_unset();
    session_destroy();
    
    session_start();
    session_regenerate_id(true);
    
    $_SESSION['notification'] = [
        'message' => 'Security alert: Your session has been terminated due to IP address change. Please login again.',
        'type' => 'error'
    ];
    
    header("Location: ../index.php");
    exit();
}
*/

// ========================================
// RBAC (ROLE-BASED ACCESS CONTROL)
// ========================================
// Include the role permissions array
require_once 'role_permissions.php';

/**
 * Checks if a user has access to a specific feature.
 * @param string $feature_key The key for the feature (e.g., 'students').
 * @param string $user_role The role of the current user.
 * @param array $feature_flags The array of globally enabled/disabled features.
 * @param array $role_permissions The array mapping roles to permissions.
 * @return bool True if the user has access, false otherwise.
 */
function user_has_access($feature_key, $user_role, $feature_flags, $role_permissions) {
    // 1. Check if the feature is globally enabled in the database
    if (!($feature_flags[$feature_key] ?? false)) {
        return false;
    }

    // 2. Check if the user's role has permission for this feature
    if (isset($role_permissions[$user_role]) && in_array($feature_key, $role_permissions[$user_role])) {
        return true;
    }

    // Deny access if neither condition is met
    return false;
}

// ========================================
// FETCH ADDITIONAL USER INFO (For Staff)
// ========================================
// For staff users, fetch additional info if it's not already in the session
if ($_SESSION['user_type'] === 'staff' && !isset($_SESSION['email'])) {
    require_once 'conn.php';
    
    $sql = "SELECT email FROM users WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $_SESSION['email'] = $user['email'];
        }
        
        $stmt->close();
    }
    
    // Note: Connection stays open for other parts of the script to use
}

// ========================================
// SESSION REGENERATION (Periodic)
// ========================================
// Regenerate session ID periodically to prevent session fixation
if (!isset($_SESSION['LAST_REGENERATION'])) {
    $_SESSION['LAST_REGENERATION'] = time();
} elseif (time() - $_SESSION['LAST_REGENERATION'] > 600) { // Every 10 minutes
    session_regenerate_id(true);
    $_SESSION['LAST_REGENERATION'] = time();
}

// ========================================
// SECURITY LOGGING (Optional)
// ========================================
// Log successful authentication (useful for security audits)
if (!isset($_SESSION['AUTH_LOGGED'])) {
    error_log("User authenticated successfully: " . $_SESSION['user_name'] . " (" . $_SESSION['user_type'] . ") from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $_SESSION['AUTH_LOGGED'] = true;
}
?>