<?php
/**
 * update_withdrawal.php
 *
 * FIX: Now adjusts the inventory quantity when the dispensed quantity is changed.
 * The delta (old_qty - new_qty) is added back to inventory so stock stays accurate.
 * Everything runs in one transaction — nothing is committed until both updates succeed.
 *
 * FIX: Replaced deprecated FILTER_SANITIZE_STRING (removed in PHP 8.1).
 *
 * FIX: Detailed DB errors are logged server-side only; client gets a generic message.
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

    $withdrawal_id       = filter_var($data['withdrawal_id'] ?? null, FILTER_VALIDATE_INT);
    $new_quantity        = filter_var($data['quantity_withdrawn'] ?? null, FILTER_VALIDATE_FLOAT);
    // FILTER_SANITIZE_STRING is deprecated — use htmlspecialchars on output instead;
    // for DB storage, prepared statements protect us.
    $notes               = isset($data['notes']) ? trim((string)$data['notes']) : null;

    if ($withdrawal_id === false || $withdrawal_id === null) {
        throw new Exception('A valid withdrawal ID is required.');
    }
    if ($new_quantity === false || $new_quantity === null || $new_quantity <= 0) {
        throw new Exception('Quantity must be a positive number.');
    }

    $new_quantity_int = (int)$new_quantity; // quantities are whole units

    // --- Begin transaction ---
    $conn->begin_transaction();

    // Step 1: Fetch current withdrawal so we know the old quantity
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

    $item_id     = (int)$withdrawal['item_id'];
    $old_qty     = (int)$withdrawal['quantity_withdrawn'];
    $qty_delta   = $old_qty - $new_quantity_int; // positive = restore stock, negative = deduct more

    // Step 2: Check there is enough stock if we are dispensing MORE than before
    if ($qty_delta < 0) {
        $extra_needed = abs($qty_delta);
        $stmt_stock = $conn->prepare(
            "SELECT quantity, item_name, unit FROM inventory_items WHERE id = ? FOR UPDATE"
        );
        if (!$stmt_stock) {
            throw new Exception('Prepare failed.');
        }
        $stmt_stock->bind_param("i", $item_id);
        $stmt_stock->execute();
        $stock_result = $stmt_stock->get_result();
        $item = $stock_result->fetch_assoc();
        $stmt_stock->close();

        if ((int)$item['quantity'] < $extra_needed) {
            $conn->rollback();
            $response['message'] = "Insufficient stock. Only {$item['quantity']} {$item['unit']} available for {$item['item_name']}.";
            ob_end_clean();
            echo json_encode($response);
            exit;
        }
    }

    // Step 3: Adjust inventory (qty_delta can be positive or negative)
    if ($qty_delta !== 0) {
        $stmt_inv = $conn->prepare(
            "UPDATE inventory_items SET quantity = quantity + ?, last_updated = NOW() WHERE id = ?"
        );
        if (!$stmt_inv) {
            throw new Exception('Prepare failed.');
        }
        $stmt_inv->bind_param("ii", $qty_delta, $item_id);
        if (!$stmt_inv->execute()) {
            throw new Exception('Failed to adjust inventory.');
        }
        $stmt_inv->close();
    }

    // Step 4: Update the withdrawal record
    $stmt_update = $conn->prepare(
        "UPDATE withdrawals SET quantity_withdrawn = ?, notes = ? WHERE withdrawal_id = ?"
    );
    if (!$stmt_update) {
        throw new Exception('Prepare failed.');
    }
    $stmt_update->bind_param("isi", $new_quantity_int, $notes, $withdrawal_id);
    if (!$stmt_update->execute()) {
        throw new Exception('Failed to update withdrawal record.');
    }
    $stmt_update->close();

    // --- Commit ---
    $conn->commit();

    $response['success'] = true;
    $response['message'] = 'Record updated successfully.';
    if ($qty_delta > 0) {
        $response['message'] .= " {$qty_delta} unit(s) returned to inventory.";
    } elseif ($qty_delta < 0) {
        $response['message'] .= " " . abs($qty_delta) . " additional unit(s) deducted from inventory.";
    }

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        $conn->rollback();
    }
    error_log('Error in update_withdrawal.php: ' . $e->getMessage());
    $response['message'] = 'Could not update record. Please try again or contact support.';
}

ob_end_clean();
echo json_encode($response);
exit;
?>