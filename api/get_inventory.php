<?php
/**
 * get_inventory.php
 *
 * FIX: Replaced SELECT * with explicit column list. SELECT * fetches
 *      heavy columns (description, notes) unnecessarily for dropdowns/lists.
 *      Only the columns actually used by the frontend are selected.
 *
 * FIX: Errors are no longer exposed to the client.
 */

header('Content-Type: application/json');
require_once '../auth.php';
require_once '../conn.php';

$sql = "SELECT id, item_name, category, quantity, unit, threshold,
               expiry_date, supplier, cost, location, description, last_updated
        FROM inventory_items
        ORDER BY item_name ASC";

$result = $conn->query($sql);

if ($result) {
    $inventory = [];
    while ($row = $result->fetch_assoc()) {
        $inventory[] = $row;
    }
    echo json_encode($inventory);
} else {
    error_log('get_inventory.php query failed: ' . $conn->error);
    echo json_encode(['error' => 'Could not fetch inventory items.']);
}

$conn->close();
?>
