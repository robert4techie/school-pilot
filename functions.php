<?php
// functions.php - All booking-related functions

// Get all bookings for a specific date range
function getBookings($conn, $startDate = null, $endDate = null, $type = null, $person = null) {
    $sql = "SELECT * FROM lab_bookings WHERE 1=1";
    $params = [];
    $types = "";
    
    if ($startDate) {
        $sql .= " AND booking_date >= ?";
        $params[] = $startDate;
        $types .= "s";
    }
    
    if ($endDate) {
        $sql .= " AND booking_date <= ?";
        $params[] = $endDate;
        $types .= "s";
    }
    
    if ($type) {
        $sql .= " AND purpose = ?";
        $params[] = $type;
        $types .= "s";
    }
    
    if ($person) {
        $sql .= " AND responsible_person = ?";
        $params[] = $person;
        $types .= "s";
    }
    
    $sql .= " ORDER BY booking_date, start_time";
    
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }
    
    return $bookings;
}

// Get bookings for a specific week
function getWeekBookings($conn, $weekStart) {
    $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
    return getBookings($conn, $weekStart, $weekEnd);
}

// Create new booking
function createBooking($conn, $data) {
    // Check for conflicts first
    $conflicts = checkConflicts($conn, $data);
    if (!empty($conflicts)) {
        return ['success' => false, 'message' => 'Booking conflicts detected', 'conflicts' => $conflicts];
    }
    
    $sql = "INSERT INTO lab_bookings (title, booking_date, start_time, end_time, purpose, responsible_person, contact_email, notes, equipment_needed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssssss", 
        $data['title'],
        $data['booking_date'],
        $data['start_time'],
        $data['end_time'],
        $data['purpose'],
        $data['responsible_person'],
        $data['contact_email'],
        $data['notes'],
        $data['equipment_needed']
    );
    
    if ($stmt->execute()) {
        return ['success' => true, 'id' => $conn->insert_id];
    } else {
        return ['success' => false, 'message' => 'Failed to create booking'];
    }
}

// Update existing booking
function updateBooking($conn, $id, $data) {
    // Check for conflicts (excluding current booking)
    $conflicts = checkConflicts($conn, $data, $id);
    if (!empty($conflicts)) {
        return ['success' => false, 'message' => 'Booking conflicts detected', 'conflicts' => $conflicts];
    }
    
    $sql = "UPDATE lab_bookings SET title=?, booking_date=?, start_time=?, end_time=?, purpose=?, responsible_person=?, contact_email=?, notes=?, equipment_needed=? WHERE id=?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssssssi", 
        $data['title'],
        $data['booking_date'],
        $data['start_time'],
        $data['end_time'],
        $data['purpose'],
        $data['responsible_person'],
        $data['contact_email'],
        $data['notes'],
        $data['equipment_needed'],
        $id
    );
    
    if ($stmt->execute()) {
        return ['success' => true];
    } else {
        return ['success' => false, 'message' => 'Failed to update booking'];
    }
}

// Delete booking
function deleteBooking($conn, $id) {
    $sql = "DELETE FROM lab_bookings WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        return ['success' => true];
    } else {
        return ['success' => false, 'message' => 'Failed to delete booking'];
    }
}

// Get single booking by ID
function getBookingById($conn, $id) {
    $sql = "SELECT * FROM lab_bookings WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

// Check for booking conflicts
function checkConflicts($conn, $data, $excludeId = null) {
    $sql = "SELECT * FROM lab_bookings WHERE booking_date = ? AND (
        (start_time < ? AND end_time > ?) OR
        (start_time < ? AND end_time > ?) OR
        (start_time >= ? AND end_time <= ?)
    )";
    
    if ($excludeId) {
        $sql .= " AND id != ?";
    }
    
    $stmt = $conn->prepare($sql);
    
    if ($excludeId) {
        $stmt->bind_param("ssssssi", 
            $data['booking_date'],
            $data['end_time'], $data['start_time'],
            $data['end_time'], $data['start_time'],
            $data['start_time'], $data['end_time'],
            $excludeId
        );
    } else {
        $stmt->bind_param("sssssss", 
            $data['booking_date'],
            $data['end_time'], $data['start_time'],
            $data['end_time'], $data['start_time'],
            $data['start_time'], $data['end_time']
        );
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $conflicts = [];
    while ($row = $result->fetch_assoc()) {
        $conflicts[] = $row;
    }
    
    return $conflicts;
}

// Get available time slots for a specific date
function getAvailableSlots($conn, $date, $duration = 60) {
    $bookings = getBookings($conn, $date, $date);
    
    $availableSlots = [];
    $startHour = 8; // 8 AM
    $endHour = 18; // 6 PM
    
    for ($hour = $startHour; $hour < $endHour; $hour++) {
        $slotStart = sprintf("%02d:00", $hour);
        $slotEnd = sprintf("%02d:00", $hour + 1);
        
        $isAvailable = true;
        foreach ($bookings as $booking) {
            if ($slotStart < $booking['end_time'] && $slotEnd > $booking['start_time']) {
                $isAvailable = false;
                break;
            }
        }
        
        if ($isAvailable) {
            $availableSlots[] = ['start' => $slotStart, 'end' => $slotEnd];
        }
    }
    
    return $availableSlots;
}

// Get unique responsible persons
function getResponsiblePersons($conn) {
    $sql = "SELECT DISTINCT responsible_person FROM lab_bookings ORDER BY responsible_person";
    $result = $conn->query($sql);
    
    $persons = [];
    while ($row = $result->fetch_assoc()) {
        $persons[] = $row['responsible_person'];
    }
    
    return $persons;
}

// Generate calendar cell ID
function generateCellId($date, $hour) {
    $dayOfWeek = date('w', strtotime($date));
    $days = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
    return $days[$dayOfWeek] . '-' . $hour;
}

// Format time for display
function formatTime($time) {
    return date('g:i A', strtotime($time));
}

// Format date for display
function formatDate($date) {
    return date('l, F j, Y', strtotime($date));
}

// Validate booking data
function validateBookingData($data) {
    $errors = [];
    
    if (empty($data['title'])) {
        $errors[] = 'Title is required';
    }
    
    if (empty($data['booking_date'])) {
        $errors[] = 'Date is required';
    }
    
    if (empty($data['start_time'])) {
        $errors[] = 'Start time is required';
    }
    
    if (empty($data['end_time'])) {
        $errors[] = 'End time is required';
    }
    
    if (!empty($data['start_time']) && !empty($data['end_time'])) {
        if ($data['start_time'] >= $data['end_time']) {
            $errors[] = 'End time must be after start time';
        }
    }
    
    if (empty($data['purpose'])) {
        $errors[] = 'Purpose is required';
    }
    
    if (empty($data['responsible_person'])) {
        $errors[] = 'Responsible person is required';
    }
    
    if (!empty($data['contact_email']) && !filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email address is required';
    }
    
    return $errors;
}

// Clean input data
function cleanInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}
?>