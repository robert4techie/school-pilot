<?php
require_once 'auth.php';
require_once 'conn.php';

function addEquipment($data) {
    global $conn;
    
    $stmt = $conn->prepare("INSERT INTO equipment (name, model_serial, manufacturer, purchase_date, warranty_info, location, maintenance_schedule, status) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param("ssssssss", 
        $data['name'],
        $data['model_serial'],
        $data['manufacturer'],
        $data['purchase_date'],
        $data['warranty_info'],
        $data['location'],
        $data['maintenance_schedule'],
        $data['status']
    );
    
    if ($stmt->execute()) {
        return $stmt->insert_id;
    } else {
        return false;
    }
}

function updateEquipment($id, $data) {
    global $conn;
    
    $stmt = $conn->prepare("UPDATE equipment SET 
                            name = ?,
                            model_serial = ?,
                            manufacturer = ?,
                            purchase_date = ?,
                            warranty_info = ?,
                            location = ?,
                            maintenance_schedule = ?,
                            status = ?
                            WHERE id = ?");
    
    $stmt->bind_param("ssssssssi", 
        $data['name'],
        $data['model_serial'],
        $data['manufacturer'],
        $data['purchase_date'],
        $data['warranty_info'],
        $data['location'],
        $data['maintenance_schedule'],
        $data['status'],
        $id
    );
    
    return $stmt->execute();
}

function deleteEquipment($id) {
    global $conn;
    
    $stmt = $conn->prepare("DELETE FROM equipment WHERE id = ?");
    $stmt->bind_param("i", $id);
    return $stmt->execute();
}

function getEquipmentById($id) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT * FROM equipment WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

function getAllEquipment($search = '') {
    global $conn;
    
    $query = "SELECT * FROM equipment";
    
    if (!empty($search)) {
        $search = "%$search%";
        $stmt = $conn->prepare("SELECT * FROM equipment WHERE 
                               name LIKE ? OR 
                               model_serial LIKE ? OR 
                               manufacturer LIKE ? OR 
                               location LIKE ?");
        $stmt->bind_param("ssss", $search, $search, $search, $search);
    } else {
        $stmt = $conn->prepare($query);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $equipment = [];
    while ($row = $result->fetch_assoc()) {
        $equipment[] = $row;
    }
    
    return $equipment;
}

function getEquipmentStats() {
    global $conn;
    
    $stats = [];
    
    // Total equipment
    $result = $conn->query("SELECT COUNT(*) as total FROM equipment");
    $stats['total'] = $result->fetch_assoc()['total'];
    
    // Available equipment (new or used)
    $result = $conn->query("SELECT COUNT(*) as available FROM equipment WHERE status IN ('new', 'used')");
    $stats['available'] = $result->fetch_assoc()['available'];
    
    // Equipment needing maintenance
    $result = $conn->query("SELECT COUNT(*) as needs_maintenance FROM equipment WHERE status = 'needs_repair'");
    $stats['needs_maintenance'] = $result->fetch_assoc()['needs_maintenance'];
    
    // New equipment this month
    $result = $conn->query("SELECT COUNT(*) as new_this_month FROM equipment 
                           WHERE MONTH(purchase_date) = MONTH(CURRENT_DATE()) 
                           AND YEAR(purchase_date) = YEAR(CURRENT_DATE())");
    $stats['new_this_month'] = $result->fetch_assoc()['new_this_month'];
    
    return $stats;
}

function calculateNextMaintenance($purchaseDate, $schedule) {
    $purchase = new DateTime($purchaseDate);
    $now = new DateTime();
    $monthsAhead = 12; // Default to annual
    
    if (stripos($schedule, '6 months') !== false || stripos($schedule, 'semi-annual') !== false) {
        $monthsAhead = 6;
    } elseif (stripos($schedule, 'quarterly') !== false || stripos($schedule, '3 months') !== false) {
        $monthsAhead = 3;
    } elseif (stripos($schedule, 'monthly') !== false) {
        $monthsAhead = 1;
    }
    
    $nextMaintenance = clone $purchase;
    $nextMaintenance->add(new DateInterval("P{$monthsAhead}M"));
    
    // If the next maintenance is in the past, calculate the next future date
    while ($nextMaintenance < $now) {
        $nextMaintenance->add(new DateInterval("P{$monthsAhead}M"));
    }
    
    return $nextMaintenance->format('M d, Y');
}

function getUniqueLocations() {
    global $conn;
    $locations = [];
    
    $sql = "SELECT DISTINCT location FROM lab_equipment ORDER BY location";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $locations[] = $row['location'];
        }
    }
    
    return $locations;
}
?>