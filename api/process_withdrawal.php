<?php
/**
 * process_withdrawal.php
 *
 * FIX: Added CSRF token validation.
 *
 * FIX: Unified to OOP MySQLi style throughout — the original mixed
 *      $conn->prepare() (OOP) and mysqli_prepare($conn, ...) (procedural)
 *      in the same file.
 *
 * FIX: DB error details no longer sent to the client.
 */

ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
require_once '../auth.php';
require_once '../conn.php';

$response = ['success' => false, 'message' => 'An unexpected error occurred.'];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $response['message'] = 'Invalid request method.';
        echo json_encode($response);
        exit;
    }

    $input = file_get_contents('php://input');
    $data  = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $response['message'] = 'Invalid JSON input.';
        echo json_encode($response);
        exit;
    }

    // ── CSRF Validation ────────────────────────────────────────────────────────
    $submitted_token = $data['csrf_token'] ?? '';
    if (empty($submitted_token) || !hash_equals($_SESSION['csrf_token'] ?? '', $submitted_token)) {
        $response['message'] = 'Invalid security token. Please refresh the page and try again.';
        echo json_encode($response);
        exit;
    }

    $student_id = $data['student_id'] ?? null;
    $item_id    = isset($data['item_id']) ? (int)$data['item_id'] : null;
    $quantity   = $data['quantity']    ?? null;
    $notes      = isset($data['notes']) ? trim((string)$data['notes']) : '';

    if (empty($student_id) || empty($item_id) || empty($quantity)) {
        $response['message'] = 'Missing required fields.';
        echo json_encode($response);
        exit;
    }

    if (!is_numeric($quantity) || $quantity <= 0) {
        $response['message'] = 'Quantity must be a positive number.';
        echo json_encode($response);
        exit;
    }

    $quantity = (int)$quantity;

    // ── Begin transaction (OOP style throughout) ───────────────────────────────
    $conn->begin_transaction();

    // Verify student exists
    $stmt_student = $conn->prepare("SELECT student_id FROM students WHERE student_id = ?");
    if (!$stmt_student) throw new Exception('Prepare failed.');
    $stmt_student->bind_param("s", $student_id);
    $stmt_student->execute();
    $result_student = $stmt_student->get_result();
    if ($result_student->num_rows === 0) {
        throw new Exception('Student not found.');
    }
    $stmt_student->close();

    // Lock and check stock
    $stmt_check = $conn->prepare("SELECT quantity, item_name, unit FROM inventory_items WHERE id = ? FOR UPDATE");
    if (!$stmt_check) throw new Exception('Prepare failed.');
    $stmt_check->bind_param("i", $item_id);
    $stmt_check->execute();
    $item_result = $stmt_check->get_result();
    if ($item_result->num_rows === 0) {
        throw new Exception('Item not found.');
    }
    $item          = $item_result->fetch_assoc();
    $current_stock = (int)$item['quantity'];
    $item_name     = $item['item_name'];
    $unit          = $item['unit'];
    $stmt_check->close();

    if ($current_stock < $quantity) {
        throw new Exception("Insufficient stock. Only {$current_stock} {$unit} available for {$item_name}.");
    }

    // Deduct inventory
    $stmt_update = $conn->prepare("UPDATE inventory_items SET quantity = quantity - ?, last_updated = NOW() WHERE id = ?");
    if (!$stmt_update) throw new Exception('Prepare failed.');
    $stmt_update->bind_param("ii", $quantity, $item_id);
    if (!$stmt_update->execute()) throw new Exception('Failed to update inventory.');
    $stmt_update->close();

    // Record the withdrawal
    $stmt_insert = $conn->prepare("INSERT INTO withdrawals (item_id, student_id, quantity_withdrawn, notes, withdrawal_date) VALUES (?, ?, ?, ?, NOW())");
    if (!$stmt_insert) throw new Exception('Prepare failed.');
    $stmt_insert->bind_param("isis", $item_id, $student_id, $quantity, $notes);
    if (!$stmt_insert->execute()) throw new Exception('Failed to record withdrawal.');
    $stmt_insert->close();

    $conn->commit();
    $response['success'] = true;
    $response['message'] = "Successfully dispensed {$quantity} {$unit} of {$item_name}.";

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        $conn->rollback();
    }
    error_log('process_withdrawal.php error: ' . $e->getMessage());
    // Preserve user-facing stock/validation messages; hide internal errors
    $userFacing = ['Student not found.', 'Item not found.'];
    if (str_starts_with($e->getMessage(), 'Insufficient stock')) {
        $response['message'] = $e->getMessage();
    } elseif (in_array($e->getMessage(), $userFacing)) {
        $response['message'] = $e->getMessage();
    } else {
        $response['message'] = 'An error occurred while processing the withdrawal. Please try again.';
    }
}

echo json_encode($response);
exit;
?>
