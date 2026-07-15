<?php
// Always start session at the beginning
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Set a notification message in session
 */
function set_notification($type, $message) {
    if (!isset($_SESSION['notifications'])) {
        $_SESSION['notifications'] = [];
    }
    
    $_SESSION['notifications'][] = [
        'type' => $type,
        'message' => $message,
        'timestamp' => time()
    ];
}

/**
 * Display all notifications with auto-dismiss
 */
function display_notifications() {
    if (empty($_SESSION['notifications'])) {
        return '';
    }

    $output = '<div class="notification-container">';
    foreach ($_SESSION['notifications'] as $notification) {
        $type = htmlspecialchars($notification['type']);
        $message = htmlspecialchars($notification['message']);
        
        $icon = match($type) {
            'success' => '✓',
            'error' => '✗',
            'warning' => '⚠',
            default => 'ℹ'
        };
        
        $output .= <<<HTML
        <div class="notification notification-{$type}" data-autodismiss="5000">
            <div class="notification-icon">{$icon}</div>
            <div class="notification-content">{$message}</div>
            <button class="notification-close" onclick="this.parentElement.remove()">×</button>
        </div>
HTML;
    }
    $output .= '</div>';

    // Clear only after displaying
    $_SESSION['notifications'] = [];
    
    return $output;
}

/**
 * Check if notifications exist
 */
function has_notifications() {
    return !empty($_SESSION['notifications']);
}
?>