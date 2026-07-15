<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'fee_functions.php';

// Check if request is POST and has required data
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id']) || !isset($_POST['action'])) {
    $_SESSION['error_message'] = 'Invalid request';
    header('Location: fees_structures.php');
    exit();
}

// Validate action
if ($_POST['action'] !== 'delete') {
    $_SESSION['error_message'] = 'Invalid action';
    header('Location: fees_structures.php');
    exit();
}

// Sanitize and validate ID
$id = filter_var($_POST['id'], FILTER_VALIDATE_INT);

if (!$id || $id <= 0) {
    $_SESSION['error_message'] = 'Invalid fee structure ID';
    header('Location: fees_structures.php');
    exit();
}

// Attempt to delete the fee structure
$result = delete_fee_structure($conn, $id);

if ($result['success']) {
    $_SESSION['success_message'] = $result['message'];
} else {
    $_SESSION['error_message'] = $result['message'];
}

// Redirect back to main page
header('Location: fees_structures.php');
exit();
?>