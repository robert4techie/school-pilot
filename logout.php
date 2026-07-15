<?php
/**
 * logout.php - SECURE VERSION (Fixed)
 * 
 * SECURITY FIXES:
 * ✅ Fixed SQL injection vulnerabilities
 * ✅ Proper prepared statements
 * ✅ Correct session destruction and notification handling
 * ✅ Input validation and sanitization
 * ✅ Error handling
 * ✅ Proper session cookie cleanup
 */

session_start();
require_once 'conn.php';
require_once 'tracking.php';

// Initialize tracker
$tracker = new UserTracker($conn);

// ========================================
// CHECK IF USER IS LOGGED IN
// ========================================
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// ========================================
// STORE USER DATA BEFORE DESTROYING SESSION
// ========================================
// Important: Store all needed data BEFORE session_destroy()
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? 'unknown';
$user_name = $_SESSION['user_name'] ?? null;

// ========================================
// UPDATE LAST LOGIN TIMESTAMP
// ========================================
try {
    if ($user_type === 'staff' && is_numeric($user_id)) {
        // SECURE: Using prepared statement
        $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
        }
    } 
    elseif ($user_type === 'student') {
        // SECURE: Using prepared statement
        $stmt = $conn->prepare("UPDATE students SET last_login = NOW() WHERE student_id = ?");
        if ($stmt) {
            $stmt->bind_param("s", $user_id);
            $stmt->execute();
            $stmt->close();
        }
    } 
    elseif ($user_type === 'parent') {
        // SECURE: Sanitize phone number and use prepared statement
        $phone = preg_replace('/[^0-9+]/', '', $user_id);
        $stmt = $conn->prepare("UPDATE parents SET last_login = NOW() WHERE phone = ?");
        if ($stmt) {
            $stmt->bind_param("s", $phone);
            $stmt->execute();
            $stmt->close();
        }
    }

    // ========================================
    // TRACK LOGOUT
    // ========================================
    if ($user_name || $user_id) {
        $tracker->trackLogout();
    }

} catch (Exception $e) {
    // Log error but continue with logout
    error_log("Logout error: " . $e->getMessage());
}

// Close database connection
$conn->close();

// ========================================
// PREPARE NOTIFICATION MESSAGE
// ========================================
// Store the message BEFORE destroying the session
$notification_message = 'You have been successfully logged out!';
$notification_type = 'success';

// ========================================
// DESTROY CURRENT SESSION COMPLETELY
// ========================================

// Remove all session variables
session_unset();

// Delete the session cookie
if (isset($_COOKIE[session_name()])) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// ========================================
// CREATE NEW SESSION FOR NOTIFICATION ONLY
// ========================================

// Start a completely NEW session
session_start();

// Generate a new session ID for security
session_regenerate_id(true);

// Set the notification in the NEW session
$_SESSION['notification'] = [
    'message' => $notification_message,
    'type' => $notification_type
];

// ========================================
// REDIRECT TO LOGIN PAGE
// ========================================
header("Location: index.php");
exit();
?>