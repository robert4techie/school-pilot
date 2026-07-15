<?php
require_once "conn.php";
require_once "auth.php";


// Process form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Collect form data and sanitize inputs
    $itemName = $conn->real_escape_string($_POST['itemName']);
    $category = $conn->real_escape_string($_POST['category']);
    $quantity = (int)$_POST['quantity'];
    $unit = $conn->real_escape_string($_POST['unit']);
    $threshold = (int)$_POST['threshold'];
    $expiryDate = !empty($_POST['expiryDate']) ? $conn->real_escape_string($_POST['expiryDate']) : NULL;
    $location = $conn->real_escape_string($_POST['location'] ?? '');
    $description = $conn->real_escape_string($_POST['description'] ?? '');
    $supplier = $conn->real_escape_string($_POST['supplier'] ?? '');
    $cost = !empty($_POST['cost']) ? (float)$_POST['cost'] : NULL;
    
    // Prepare SQL statement
    $sql = "INSERT INTO inventory_items (item_name, category, quantity, unit, threshold, expiry_date, location, description, supplier, cost, last_updated) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        // Bind parameters
        $stmt->bind_param("ssissssssd", $itemName, $category, $quantity, $unit, $threshold, $expiryDate, $location, $description, $supplier, $cost);
        
        // Execute the statement
        if ($stmt->execute()) {
            // Success
            echo json_encode(['status' => 'success', 'message' => 'Item added successfully!']);
        } else {
            // Error
            echo json_encode(['status' => 'error', 'message' => 'Error: ' . $stmt->error]);
        }
        
        // Close statement
        $stmt->close();
    } else {
        // Error in preparing statement
        echo json_encode(['status' => 'error', 'message' => 'Error: ' . $conn->error]);
    }
    
    // Close connection
    $conn->close();
    exit;
}
?>