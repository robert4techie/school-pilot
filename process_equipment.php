<?php
require_once 'equipment_functions.php';
require_once 'notifications.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'name' => $_POST['equipmentName'] ?? '',
        'model_serial' => $_POST['modelSerial'] ?? '',
        'manufacturer' => $_POST['manufacturer'] ?? '',
        'purchase_date' => $_POST['purchaseDate'] ?? '',
        'warranty_info' => $_POST['warrantyInfo'] ?? '',
        'location' => $_POST['location'] ?? '',
        'maintenance_schedule' => $_POST['maintenanceSchedule'] ?? '',
        'status' => $_POST['status'] ?? ''
    ];
    
    // Validate required fields
    $required = ['name', 'model_serial', 'manufacturer', 'purchase_date', 'location', 'status'];
    $valid = true;
    
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $valid = false;
            break;
        }
    }
    
    if (!$valid) {
        setNotification('error', 'Please fill in all required fields.');
        header("Location: add_lab_equipment.php");
        exit();
    }
    
    if (isset($_POST['equipment_id']) && !empty($_POST['equipment_id'])) {
        // Update existing equipment
        $id = (int)$_POST['equipment_id'];
        if (updateEquipment($id, $data)) {
            setNotification('success', 'Equipment updated successfully!');
        } else {
            setNotification('error', 'Failed to update equipment. Please try again.');
        }
    } else {
        // Add new equipment
        if (addEquipment($data)) {
            setNotification('success', 'Equipment added successfully!');
        } else {
            setNotification('error', 'Failed to add equipment. Please try again.');
        }
    }
    
    header("Location: add_lab_equipment.php");
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    if (deleteEquipment($id)) {
        setNotification('success', 'Equipment deleted successfully!');
    } else {
        setNotification('error', 'Failed to delete equipment. Please try again.');
    }
    
    header("Location: add_lab_equipment.php");
    exit();
}
?>