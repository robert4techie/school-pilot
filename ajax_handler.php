<?php
// ajax_handler.php - Handle all AJAX requests
require_once 'conn.php'; // Include your connection file
require_once 'functions.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'get_bookings':
        handleGetBookings();
        break;
    case 'create_booking':
        handleCreateBooking();
        break;
    case 'update_booking':
        handleUpdateBooking();
        break;
    case 'delete_booking':
        handleDeleteBooking();
        break;
    case 'get_booking':
        handleGetBooking();
        break;
    case 'check_conflicts':
        handleCheckConflicts();
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

function handleGetBookings() {
    global $conn;
    
    $startDate = $_GET['start_date'] ?? null;
    $endDate = $_GET['end_date'] ?? null;
    $type = $_GET['type'] ?? null;
    $person = $_GET['person'] ?? null;
    
    $bookings = getBookings($conn, $startDate, $endDate, $type, $person);
    
    // Format bookings for frontend
    $formattedBookings = [];
    foreach ($bookings as $booking) {
        $dayOfWeek = date('w', strtotime($booking['booking_date']));
        $days = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $hour = date('G', strtotime($booking['start_time']));
        
        $formattedBookings[] = [
            'id' => $booking['id'],
            'title' => $booking['title'],
            'date' => $booking['booking_date'],
            'startTime' => substr($booking['start_time'], 0, 5), // HH:MM format
            'endTime' => substr($booking['end_time'], 0, 5),
            'type' => $booking['purpose'],
            'person' => $booking['responsible_person'],
            'email' => $booking['contact_email'],
            'notes' => $booking['notes'],
            'equipment' => $booking['equipment_needed'] ? explode(',', $booking['equipment_needed']) : [],
            'cellId' => $days[$dayOfWeek] . '-' . $hour
        ];
    }
    
    echo json_encode(['success' => true, 'bookings' => $formattedBookings]);
}

function handleCreateBooking() {
    global $conn;
    
    $data = [
        'title' => cleanInput($_POST['title']),
        'booking_date' => cleanInput($_POST['booking_date']),
        'start_time' => cleanInput($_POST['start_time']),
        'end_time' => cleanInput($_POST['end_time']),
        'purpose' => cleanInput($_POST['purpose']),
        'responsible_person' => cleanInput($_POST['responsible_person']),
        'contact_email' => cleanInput($_POST['contact_email']),
        'notes' => cleanInput($_POST['notes'] ?? ''),
        'equipment_needed' => isset($_POST['equipment']) ? implode(',', $_POST['equipment']) : ''
    ];
    
    // Validate data
    $errors = validateBookingData($data);
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
        return;
    }
    
    $result = createBooking($conn, $data);
    echo json_encode($result);
}

function handleUpdateBooking() {
    global $conn;
    
    $id = (int)$_POST['id'];
    $data = [
        'title' => cleanInput($_POST['title']),
        'booking_date' => cleanInput($_POST['booking_date']),
        'start_time' => cleanInput($_POST['start_time']),
        'end_time' => cleanInput($_POST['end_time']),
        'purpose' => cleanInput($_POST['purpose']),
        'responsible_person' => cleanInput($_POST['responsible_person']),
        'contact_email' => cleanInput($_POST['contact_email']),
        'notes' => cleanInput($_POST['notes'] ?? ''),
        'equipment_needed' => isset($_POST['equipment']) ? implode(',', $_POST['equipment']) : ''
    ];
    
    // Validate data
    $errors = validateBookingData($data);
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
        return;
    }
    
    $result = updateBooking($conn, $id, $data);
    echo json_encode($result);
}

function handleDeleteBooking() {
    global $conn;
    
    $id = (int)$_POST['id'];
    $result = deleteBooking($conn, $id);
    echo json_encode($result);
}

function handleGetBooking() {
    global $conn;
    
    $id = (int)$_GET['id'];
    $booking = getBookingById($conn, $id);
    
    if ($booking) {
        // Format for frontend
        $booking['equipment'] = $booking['equipment_needed'] ? explode(',', $booking['equipment_needed']) : [];
        $booking['startTime'] = substr($booking['start_time'], 0, 5);
        $booking['endTime'] = substr($booking['end_time'], 0, 5);
        echo json_encode(['success' => true, 'booking' => $booking]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
    }
}

function handleCheckConflicts() {
    global $conn;
    
    $data = [
        'booking_date' => cleanInput($_POST['booking_date']),
        'start_time' => cleanInput($_POST['start_time']),
        'end_time' => cleanInput($_POST['end_time'])
    ];
    
    $excludeId = isset($_POST['exclude_id']) ? (int)$_POST['exclude_id'] : null;
    $conflicts = checkConflicts($conn, $data, $excludeId);
    
    echo json_encode([
        'success' => true, 
        'hasConflicts' => !empty($conflicts),
        'conflicts' => $conflicts
    ]);
}
?>