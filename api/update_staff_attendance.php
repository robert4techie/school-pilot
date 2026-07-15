<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json');

// Start session FIRST
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../auth.php';
require_once '../conn.php';

$response = ['success' => false, 'message' => ''];

try {
    // Authentication check
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        throw new Exception('Unauthorized access - Please login again');
    }
    
    // Fallback for username if not set
    $user_id = $_SESSION['user_id'];
    $username = $_SESSION['username'] ?? $_SESSION['email'] ?? 'User_' . $user_id;
    
    // Log for debugging
    error_log("Staff Attendance Update - User ID: $user_id, Username: $username");
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Method not allowed');
    }
    
    // CSRF Protection
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        http_response_code(403);
        throw new Exception('Invalid security token. Please refresh the page.');
    }
    
    // Rate limiting
    $rate_key = 'staff_attendance_rate_' . $user_id;
    if (!isset($_SESSION[$rate_key])) {
        $_SESSION[$rate_key] = ['count' => 0, 'time' => time()];
    }
    
    if ((time() - $_SESSION[$rate_key]['time']) > 60) {
        $_SESSION[$rate_key] = ['count' => 1, 'time' => time()];
    } else {
        $_SESSION[$rate_key]['count']++;
        if ($_SESSION[$rate_key]['count'] > 150) {
            http_response_code(429);
            throw new Exception('Too many requests. Please wait a moment.');
        }
    }
    
    // Sanitize inputs
    $staff_id = trim($_POST['staff_id'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $date = trim($_POST['date'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $recorded_by_name = trim($_POST['recorded_by'] ?? $username);
    
    // Validate inputs
    if (empty($staff_id) || empty($status) || empty($date)) {
        http_response_code(400);
        throw new Exception('All required fields must be filled');
    }
    
    $allowed_statuses = ['present', 'absent', 'late', 'on_leave'];
    if (!in_array($status, $allowed_statuses)) {
        http_response_code(400);
        throw new Exception('Invalid status value');
    }
    
    // Validate date format
    $date_obj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$date_obj || $date_obj->format('Y-m-d') !== $date) {
        http_response_code(400);
        throw new Exception('Invalid date format');
    }
    
    // Check date constraints
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    $date_obj->setTime(0, 0, 0);
    
    if ($date_obj > $today) {
        http_response_code(400);
        throw new Exception('Cannot mark attendance for future dates');
    }
    
    $diff = $today->diff($date_obj)->days;
    
    // Allow same-day edits freely, limit backdating to 7 days
    if ($date_obj < $today && $diff > 7) {
        http_response_code(400);
        throw new Exception('Cannot mark attendance older than 7 days');
    }
    
    // Verify staff exists and is active
    $verify_stmt = $conn->prepare("SELECT staff_id FROM staff WHERE staff_id = ? AND Status = 'active'");
    $verify_stmt->bind_param("s", $staff_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows === 0) {
        $verify_stmt->close();
        http_response_code(404);
        throw new Exception('Staff member not found or inactive');
    }
    $verify_stmt->close();
    
    // Check for late arrival threshold
    $late_threshold_time = '08:15:00';
    $current_time = date('H:i:s');
    $is_late_arrival = false;
    
    if ($status === 'present' && $date === date('Y-m-d')) {
        if ($current_time > $late_threshold_time) {
            $is_late_arrival = true;
        }
    }
    
    // START TRANSACTION
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_WRITE);
    
    try {
        // Lock the row for optimistic locking
        $lock_stmt = $conn->prepare(
            "SELECT attendance_id, status AS old_status, remarks AS old_remarks, version 
             FROM staff_attendance 
             WHERE staff_id = ? AND date = ? 
             FOR UPDATE"
        );
        $lock_stmt->bind_param("ss", $staff_id, $date);
        $lock_stmt->execute();
        $lock_result = $lock_stmt->get_result();
        $existing = $lock_result->fetch_assoc();
        $lock_stmt->close();
        
        $attendance_id = null;
        $old_status = null;
        $old_remarks = null;
        
        if ($existing) {
            // UPDATE existing record
            $old_status = $existing['old_status'];
            $old_remarks = $existing['old_remarks'];
            $current_version = $existing['version'];
            $new_version = $current_version + 1;
            
            $update_stmt = $conn->prepare(
                "UPDATE staff_attendance 
                 SET status = ?, remarks = ?, version = ?, updated_at = CURRENT_TIMESTAMP, recorded_by = ?
                 WHERE staff_id = ? AND date = ? AND version = ?"
            );
            $update_stmt->bind_param("ssisssi", $status, $remarks, $new_version, $user_id, $staff_id, $date, $current_version);
            $update_stmt->execute();
            
            if ($update_stmt->affected_rows === 0) {
                $update_stmt->close();
                throw new Exception('Record was modified by another user. Please refresh and try again.');
            }
            
            $attendance_id = $existing['attendance_id'];
            $update_stmt->close();
            
        } else {
            // INSERT new record
            $insert_stmt = $conn->prepare(
                "INSERT INTO staff_attendance (staff_id, date, status, remarks, version, recorded_by, created_at, updated_at) 
                 VALUES (?, ?, ?, ?, 0, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $insert_stmt->bind_param("ssssi", $staff_id, $date, $status, $remarks, $user_id);
            $insert_stmt->execute();
            
            if ($insert_stmt->affected_rows === 0) {
                $insert_stmt->close();
                throw new Exception('Failed to create attendance record');
            }
            
            $attendance_id = $conn->insert_id;
            $insert_stmt->close();
        }
        
        // Enhanced audit trail with username
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN', 0, 255);
        $change_reason = '';
        
        if ($old_status && $old_status !== $status) {
            $change_reason = "Status changed from '$old_status' to '$status'";
        }
        if ($old_remarks !== $remarks) {
            $change_reason .= ($change_reason ? ' | ' : '') . "Remarks updated";
        }
        
        // Create audit table if it doesn't exist
        $audit_table_check = "CREATE TABLE IF NOT EXISTS staff_attendance_audit (
            audit_id INT AUTO_INCREMENT PRIMARY KEY,
            attendance_id INT,
            staff_id VARCHAR(50),
            date DATE,
            old_status VARCHAR(20),
            new_status VARCHAR(20),
            changed_by INT,
            changed_by_name VARCHAR(100),
            change_reason TEXT,
            ip_address VARCHAR(45),
            user_agent VARCHAR(255),
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_staff_date (staff_id, date),
            INDEX idx_changed_by (changed_by),
            INDEX idx_changed_at (changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $conn->query($audit_table_check);
        
        $audit_stmt = $conn->prepare(
            "INSERT INTO staff_attendance_audit 
             (attendance_id, staff_id, date, old_status, new_status, changed_by, changed_by_name, change_reason, ip_address, user_agent, changed_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
        );
        $audit_stmt->bind_param("issssissss", 
            $attendance_id, $staff_id, $date, $old_status, $status, 
            $user_id, $recorded_by_name, $change_reason, $ip, $user_agent
        );
        $audit_stmt->execute();
        $audit_stmt->close();
        
        // COMMIT transaction
        $conn->commit();
        
        // Success response
        $response['success'] = true;
        $response['message'] = 'Staff attendance recorded successfully';
        $response['data'] = [
            'staff_id' => $staff_id,
            'date' => $date,
            'status' => $status,
            'old_status' => $old_status,
            'remarks' => $remarks,
            'is_late_arrival' => $is_late_arrival,
            'late_threshold' => $late_threshold_time,
            'recorded_by' => $recorded_by_name
        ];
        
        if ($is_late_arrival) {
            $response['warning'] = "Note: Marked after late arrival time ($late_threshold_time)";
        }
        
        // Log success
        error_log(sprintf(
            "[SUCCESS] User %s (%s) marked staff attendance: staff=%s, date=%s, status=%s, remarks=%s",
            $username, $user_id, $staff_id, $date, $status, $remarks ? 'YES' : 'NO'
        ));
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log(sprintf(
        "[ERROR] Staff attendance update failed: %s | User: %s | IP: %s",
        $e->getMessage(),
        $_SESSION['username'] ?? 'unknown',
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ));
    
    $response['message'] = $e->getMessage();
    
    if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
        $response['debug'] = [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ];
    }
}

if (isset($conn)) {
    $conn->close();
}

echo json_encode($response);
exit;
?>