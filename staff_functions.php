<?php
require_once 'conn.php';

// Function to sanitize input data
function sanitizeStaffInput($data) {
    global $conn;
    return htmlspecialchars(strip_tags(trim($conn->real_escape_string($data))));
}

// Notification functions
function showStaffNotification($type, $message) {
    $_SESSION['staff_notification'] = [
        'type' => $type,
        'message' => $message
    ];
}

function displayStaffNotification() {
    if (isset($_SESSION['staff_notification'])) {
        $notification = $_SESSION['staff_notification'];
        $alertClass = $notification['type'] === 'success' ? 'alert-success' : 'alert-danger';
        echo "<div class='alert $alertClass' style='position: fixed; top: 20px; right: 20px; z-index: 1100;'>
                {$notification['message']}
                <button type='button' class='close' onclick=\"this.parentElement.style.display='none';\">&times;</button>
              </div>";
        unset($_SESSION['staff_notification']);
    }
}

// Staff table operations
function getStaffMembers() {
    global $conn;
    $staffMembers = [];
    $result = $conn->query("SELECT * FROM kitchen_staff ORDER BY position, last_name");
    if ($result) {
        $staffMembers = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
    return $staffMembers;
}

function getStaffStats() {
    global $conn;
    $stats = [
        'total_staff' => 0,
        'active_staff' => 0,
        'on_leave_staff' => 0,
        'vacancies' => 3
    ];

    $result = $conn->query("SELECT 
        COUNT(*) as total_staff,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_staff,
        SUM(CASE WHEN status = 'On Leave' THEN 1 ELSE 0 END) as on_leave_staff
        FROM kitchen_staff");

    if ($result) {
        $stats = array_merge($stats, $result->fetch_assoc());
        $result->free();
    }
    return $stats;
}

function getStaffById($id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM kitchen_staff WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function addStaffMember($data) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO kitchen_staff (first_name, last_name, email, position, phone, status, hire_date, role_description, skills) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssssss", 
        $data['first_name'], 
        $data['last_name'], 
        $data['email'], 
        $data['position'], 
        $data['phone'], 
        $data['status'], 
        $data['hire_date'], 
        $data['role'], 
        $data['skills']
    );
    return $stmt->execute();
}

function updateStaffMember($data) {
    global $conn;
    $stmt = $conn->prepare("UPDATE kitchen_staff SET first_name=?, last_name=?, email=?, position=?, phone=?, status=?, hire_date=?, role_description=?, skills=? WHERE id=?");
    $stmt->bind_param("sssssssssi", 
        $data['first_name'], 
        $data['last_name'], 
        $data['email'], 
        $data['position'], 
        $data['phone'], 
        $data['status'], 
        $data['hire_date'], 
        $data['role'], 
        $data['skills'],
        $data['staff_id']
    );
    return $stmt->execute();
}

function deleteStaffMember($id) {
    global $conn;
    $stmt = $conn->prepare("DELETE FROM kitchen_staff WHERE id=?");
    $stmt->bind_param("i", $id);
    return $stmt->execute();
}
?>