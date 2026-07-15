<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');


require_once 'conn.php';

try {
    // Get parameters
    $action = isset($_GET['action']) ? $_GET['action'] : 'daily_summary';
    $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
    $class = isset($_GET['class']) ? $_GET['class'] : '';
    $stream = isset($_GET['stream']) ? $_GET['stream'] : '';
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

    switch ($action) {
        case 'daily_summary':
            $result = getDailySummary($conn, $date, $class, $stream);
            break;
            
        case 'student_history':
            $student_id = isset($_GET['student_id']) ? $_GET['student_id'] : '';
            $result = getStudentHistory($conn, $student_id, $start_date, $end_date);
            break;
            
        case 'class_statistics':
            $result = getClassStatistics($conn, $start_date, $end_date);
            break;
            
        case 'monthly_report':
            $month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
            $result = getMonthlyReport($conn, $month, $class, $stream);
            break;
            
        default:
            throw new Exception('Invalid action specified');
    }
    
    echo json_encode([
        'success' => true,
        'data' => $result,
        'action' => $action,
        'parameters' => $_GET
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function getDailySummary($conn, $date, $class = '', $stream = '') {
    $whereClause = "WHERE a.date = ?";
    $params = [$date];
    $types = "s";
    
    if (!empty($class)) {
        $whereClause .= " AND s.current_class = ?";
        $params[] = $class;
        $types .= "s";
    }
    
    if (!empty($stream)) {
        $whereClause .= " AND s.stream = ?";
        $params[] = $stream;
        $types .= "s";
    }
    
    $query = "
        SELECT 
            s.student_id,
            s.first_name,
            s.last_name,
            s.current_class,
            s.stream,
            a.status,
            a.created_at as marked_at,
            a.remarks
        FROM students s
        LEFT JOIN attendance a ON s.student_id = a.student_id
        $whereClause
        ORDER BY s.current_class, s.stream, s.first_name
    ";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $students = [];
    $summary = [
        'total' => 0,
        'present' => 0,
        'absent' => 0,
        'late' => 0,
        'not_marked' => 0
    ];
    
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
        $summary['total']++;
        
        switch ($row['status']) {
            case 'present':
                $summary['present']++;
                break;
            case 'absent':
                $summary['absent']++;
                break;
            case 'late':
                $summary['late']++;
                break;
            default:
                $summary['not_marked']++;
        }
    }
    
    $stmt->close();
    
    return [
        'date' => $date,
        'summary' => $summary,
        'students' => $students
    ];
}

function getStudentHistory($conn, $student_id, $start_date = '', $end_date = '') {
    if (empty($student_id)) {
        throw new Exception('Student ID is required');
    }
    
    $whereClause = "WHERE s.student_id = ?";
    $params = [$student_id];
    $types = "s";
    
    if (!empty($start_date)) {
        $whereClause .= " AND a.date >= ?";
        $params[] = $start_date;
        $types .= "s";
    }
    
    if (!empty($end_date)) {
        $whereClause .= " AND a.date <= ?";
        $params[] = $end_date;
        $types .= "s";
    }
    
    // Get student info
    $studentQuery = "SELECT * FROM students WHERE student_id = ?";
    $studentStmt = $conn->prepare($studentQuery);
    $studentStmt->bind_param("s", $student_id);
    $studentStmt->execute();
    $studentInfo = $studentStmt->get_result()->fetch_assoc();
    $studentStmt->close();
    
    if (!$studentInfo) {
        throw new Exception('Student not found');
    }
    
    // Get attendance history
    $query = "
        SELECT 
            a.date,
            a.status,
            a.created_at as marked_at,
            a.updated_at as last_updated,
            a.remarks
        FROM students s
        LEFT JOIN attendance a ON s.student_id = a.student_id
        $whereClause
        ORDER BY a.date DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $attendance = [];
    $stats = [
        'total_days' => 0,
        'present' => 0,
        'absent' => 0,
        'late' => 0,
        'attendance_rate' => 0
    ];
    
    while ($row = $result->fetch_assoc()) {
        if ($row['status']) {
            $attendance[] = $row;
            $stats['total_days']++;
            $stats[$row['status']]++;
        }
    }
    
    if ($stats['total_days'] > 0) {
        $stats['attendance_rate'] = round(
            (($stats['present'] + $stats['late']) / $stats['total_days']) * 100, 
            2
        );
    }
    
    $stmt->close();
    
    return [
        'student' => $studentInfo,
        'statistics' => $stats,
        'attendance_history' => $attendance
    ];
}

function getClassStatistics($conn, $start_date = '', $end_date = '') {
    $whereClause = "";
    $params = [];
    $types = "";
    
    if (!empty($start_date) && !empty($end_date)) {
        $whereClause = "WHERE a.date BETWEEN ? AND ?";
        $params = [$start_date, $end_date];
        $types = "ss";
    } elseif (!empty($start_date)) {
        $whereClause = "WHERE a.date >= ?";
        $params = [$start_date];
        $types = "s";
    } elseif (!empty($end_date)) {
        $whereClause = "WHERE a.date <= ?";
        $params = [$end_date];
        $types = "s";
    }
    
    $query = "
        SELECT 
            s.current_class,
            s.stream,
            COUNT(DISTINCT s.student_id) as total_students,
            COUNT(a.id) as total_records,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
            ROUND(
                (SUM(CASE WHEN a.status IN ('present', 'late') THEN 1 ELSE 0 END) / 
                 NULLIF(COUNT(a.id), 0)) * 100, 2
            ) as attendance_rate
        FROM students s
        LEFT JOIN attendance a ON s.student_id = a.student_id
        $whereClause
        GROUP BY s.current_class, s.stream
        ORDER BY s.current_class, s.stream
    ";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $statistics = [];
    while ($row = $result->fetch_assoc()) {
        $statistics[] = $row;
    }
    
    $stmt->close();
    
    return $statistics;
}

function getMonthlyReport($conn, $month, $class = '', $stream = '') {
    $start_date = $month . '-01';
    $end_date = date('Y-m-t', strtotime($start_date));
    
    $whereClause = "WHERE a.date BETWEEN ? AND ?";
    $params = [$start_date, $end_date];
    $types = "ss";
    
    if (!empty($class)) {
        $whereClause .= " AND s.current_class = ?";
        $params[] = $class;
        $types .= "s";
    }
    
    if (!empty($stream)) {
        $whereClause .= " AND s.stream = ?";
        $params[] = $stream;
        $types .= "s";
    }
    
    $query = "
        SELECT 
            a.date,
            COUNT(DISTINCT s.student_id) as total_students,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late,
            ROUND(
                (SUM(CASE WHEN a.status IN ('present', 'late') THEN 1 ELSE 0 END) / 
                 NULLIF(COUNT(a.id), 0)) * 100, 2
            ) as attendance_rate
        FROM students s
        LEFT JOIN attendance a ON s.student_id = a.student_id
        $whereClause
        GROUP BY a.date
        ORDER BY a.date
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $daily_data = [];
    $monthly_summary = [
        'month' => $month,
        'total_school_days' => 0,
        'average_attendance_rate' => 0,
        'total_present' => 0,
        'total_absent' => 0,
        'total_late' => 0
    ];
    
    while ($row = $result->fetch_assoc()) {
        $daily_data[] = $row;
        $monthly_summary['total_school_days']++;
        $monthly_summary['total_present'] += $row['present'];
        $monthly_summary['total_absent'] += $row['absent'];
        $monthly_summary['total_late'] += $row['late'];
    }
    
    if ($monthly_summary['total_school_days'] > 0) {
        $total_attendance = $monthly_summary['total_present'] + $monthly_summary['total_late'];
        $total_possible = $monthly_summary['total_present'] + $monthly_summary['total_absent'] + $monthly_summary['total_late'];
        
        if ($total_possible > 0) {
            $monthly_summary['average_attendance_rate'] = round(
                ($total_attendance / $total_possible) * 100, 2
            );
        }
    }
    
    $stmt->close();
    
    return [
        'summary' => $monthly_summary,
        'daily_data' => $daily_data
    ];
}

$conn->close();
?>