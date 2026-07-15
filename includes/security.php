<?php
// CSRF Token Management
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Input Sanitization
function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

// Decimal/Money Handling
function formatDecimal($value) {
    return number_format((float)$value, 2, '.', '');
}

function parseDecimal($value) {
    $cleaned = preg_replace('/[^0-9.]/', '', $value);
    return formatDecimal($cleaned);
}

// Error Logging (never expose to users)
function logError($message, $context = []) {
    $logFile = __DIR__ . '/../logs/errors.log';
    $timestamp = date('Y-m-d H:i:s');
    $contextJson = json_encode($context);
    $logMessage = "[$timestamp] $message | Context: $contextJson\n";
    error_log($logMessage, 3, $logFile);
}

// Safe JSON Response
function jsonResponse($success, $message, $data = null) {
    header('Content-Type: application/json');
    $response = [
        'success' => $success,
        'message' => $message
    ];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit;
}
?>