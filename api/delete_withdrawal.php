<?php
/**
 * delete_withdrawal.php
 *
 * FIX: Now restores the inventory quantity when a withdrawal record is deleted.
 * Both the deletion and the inventory restoration happen inside one transaction,
 * so they either both succeed or both roll back — no silent stock drift.
 *
 * FIX: Detailed error messages are no longer sent to the client; they are
 * logged server-side only.
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
require_once '../auth.php';
require_once '../conn.php';

$response = ['success' => false, 'message' => 'An unexpected error occurred.'];

try {
    if (!isset($conn) || !$conn) {
        throw new Exception('Database connection not established.');
    }

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON payload.');
    }

    $withdrawal_id = filter_var($data['withdrawal_id'] ?? null, FILTER_VALIDATE_INT);

    if ($withdrawal_id === false || $withdrawal_id === null) {
        throw new Exception('A valid withdrawal ID is required.');
    }

    // --- Begin transaction ---
    $conn->begin_transaction();

    // Step 1: Fetch the withdrawal record so we know what to restore
    $stmt_fetch = $conn->prepare(
        "SELECT item_id, quantity_withdrawn FROM withdrawals WHERE withdrawal_id = ? FOR UPDATE"
    );
    if (!$stmt_fetch) {
        throw new Exception('Prepare failed.');
    }
    $stmt_fetch->bind_param("i", $withdrawal_id);
    $stmt_fetch->execute();
    $result = $stmt_fetch->get_result();

    if ($result->num_rows === 0) {
        $conn->rollback();
        $stmt_fetch->close();
        $response['message'] = 'No record found with the given ID.';
        ob_end_clean();
        echo json_encode($response);
        exit;
    }

    $withdrawal = $result->fetch_assoc();
    $stmt_fetch->close();

    $item_id          = (int)$withdrawal['item_id'];
    $qty_to_restore   = (int)$withdrawal['quantity_withdrawn'];

    // Step 2: Restore inventory quantity
    $stmt_restore = $conn->prepare(
        "UPDATE inventory_items SET quantity = quantity + ?, last_updated = NOW() WHERE id = ?"
    );
    if (!$stmt_restore) {
        throw new Exception('Prepare failed.');
    }
    $stmt_restore->bind_param("ii", $qty_to_restore, $item_id);
    if (!$stmt_restore->execute()) {
        throw new Exception('Failed to restore inventory.');
    }
    $stmt_restore->close();

    // Step 3: Delete the withdrawal record
    $stmt_delete = $conn->prepare("DELETE FROM withdrawals WHERE withdrawal_id = ?");
    if (!$stmt_delete) {
        throw new Exception('Prepare failed.');
    }
    $stmt_delete->bind_param("i", $withdrawal_id);
    if (!$stmt_delete->execute()) {
        throw new Exception('Failed to delete withdrawal record.');
    }
    $stmt_delete->close();

    // --- Commit ---
    $conn->commit();

    $response['success'] = true;
    $response['message'] = "Record deleted and {$qty_to_restore} unit(s) restored to inventory.";

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        $conn->rollback();
    }
    // Log the real error server-side; return a generic message to the client
    error_log('Error in delete_withdrawal.php: ' . $e->getMessage());
    $response['message'] = 'Could not delete record. Please try again or contact support.';
}

ob_end_clean();
echo json_encode($response);
exit;
?>