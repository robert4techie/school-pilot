<?php
/**
 * manage_inventory.php
 *
 * FIX: Corrected bind_param type strings for both ADD and EDIT operations.
 *   - Old (wrong): "ssisssssds"  →  cost was 's', location was 'd'
 *   - New (correct): "ssissssdss" →  cost is 'd', location is 's'
 *   Parameter order: name(s), category(s), quantity(i), unit(s), threshold(i),
 *                    expiry(s), supplier(s), cost(d), location(s), description(s)
 *
 * FIX: Added server-side required-field validation.
 *
 * FIX: DB errors no longer exposed to the client.
 */

header('Content-Type: application/json');

require '../auth.php';
require_once '../conn.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data) || !isset($data['action'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
    exit;
}

// ── Shared validation helper ──────────────────────────────────────────────────
function validate_item(array $data): ?string {
    if (empty(trim($data['name'] ?? ''))) {
        return 'Item name is required.';
    }
    if (!is_numeric($data['quantity'] ?? '') || (int)$data['quantity'] < 0) {
        return 'Quantity must be a non-negative number.';
    }
    if (!is_numeric($data['threshold'] ?? '') || (int)$data['threshold'] < 0) {
        return 'Threshold must be a non-negative number.';
    }
    if (!empty($data['cost']) && !is_numeric($data['cost'])) {
        return 'Cost must be a valid number.';
    }
    return null;
}

$action = $data['action'];

switch ($action) {

    case 'add':
        $err = validate_item($data);
        if ($err) {
            echo json_encode(['status' => 'error', 'message' => $err]);
            exit;
        }

        // Correct type string: s s i s i s s d s s  (10 params)
        //                      name cat qty unit thr exp sup cost loc desc
        $sql = "INSERT INTO inventory_items
                    (item_name, category, quantity, unit, threshold, expiry_date,
                     supplier, cost, location, description, last_updated)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            error_log('manage_inventory add prepare failed: ' . $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'Failed to add item.']);
            exit;
        }

        $name        = trim($data['name']);
        $category    = trim($data['category']   ?? '');
        $quantity    = (int)$data['quantity'];
        $unit        = trim($data['unit']        ?? '');
        $threshold   = (int)$data['threshold'];
        $expiry      = !empty($data['expiry'])   ? $data['expiry']   : null;
        $supplier    = trim($data['supplier']    ?? '');
        $cost        = !empty($data['cost'])     ? (float)$data['cost'] : 0.0;
        $location    = trim($data['location']    ?? '');
        $description = trim($data['description'] ?? '');

        // FIXED type string: "ssissssdss"
        $stmt->bind_param(
            "ssissssdss",
            $name, $category, $quantity, $unit, $threshold,
            $expiry, $supplier, $cost, $location, $description
        );

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Item added successfully.']);
        } else {
            error_log('manage_inventory add execute failed: ' . $stmt->error);
            echo json_encode(['status' => 'error', 'message' => 'Failed to add item. Please try again.']);
        }
        $stmt->close();
        break;

    case 'edit':
        if (!isset($data['id']) || !is_numeric($data['id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Item ID not provided for edit.']);
            exit;
        }

        $err = validate_item($data);
        if ($err) {
            echo json_encode(['status' => 'error', 'message' => $err]);
            exit;
        }

        // Correct type string: s s i s i s s d s s i  (10 params + ID)
        $sql = "UPDATE inventory_items
                SET item_name=?, category=?, quantity=?, unit=?, threshold=?,
                    expiry_date=?, supplier=?, cost=?, location=?, description=?,
                    last_updated=NOW()
                WHERE id=?";
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            error_log('manage_inventory edit prepare failed: ' . $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'Failed to update item.']);
            exit;
        }

        $name        = trim($data['name']);
        $category    = trim($data['category']   ?? '');
        $quantity    = (int)$data['quantity'];
        $unit        = trim($data['unit']        ?? '');
        $threshold   = (int)$data['threshold'];
        $expiry      = !empty($data['expiry'])   ? $data['expiry']   : null;
        $supplier    = trim($data['supplier']    ?? '');
        $cost        = !empty($data['cost'])     ? (float)$data['cost'] : 0.0;
        $location    = trim($data['location']    ?? '');
        $description = trim($data['description'] ?? '');
        $id          = (int)$data['id'];

        // FIXED type string: "ssissssdss" + "i" for WHERE id
        $stmt->bind_param(
            "ssissssdssi",
            $name, $category, $quantity, $unit, $threshold,
            $expiry, $supplier, $cost, $location, $description,
            $id
        );

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Item updated successfully.']);
        } else {
            error_log('manage_inventory edit execute failed: ' . $stmt->error);
            echo json_encode(['status' => 'error', 'message' => 'Failed to update item. Please try again.']);
        }
        $stmt->close();
        break;

    case 'delete':
        if (!isset($data['id']) || !is_numeric($data['id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Item ID not provided for delete.']);
            exit;
        }

        $id  = (int)$data['id'];
        $sql = "DELETE FROM inventory_items WHERE id = ?";
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            error_log('manage_inventory delete prepare failed: ' . $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete item.']);
            exit;
        }

        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Item deleted successfully.']);
        } else {
            error_log('manage_inventory delete execute failed: ' . $stmt->error);
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete item. Please try again.']);
        }
        $stmt->close();
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
        break;
}

$conn->close();
?>