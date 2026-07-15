<?php
// Database connection
require_once '../conn.php';

// Function to get individual student report data
function getStudentReport($student_id, $class, $stream, $term, $year) {
    global $conn;
    
    // 1. Convert term to Roman numeral format for table name
    $term_numeral = '';
    if ($term == 'Term 1') {
        $term_numeral = 'i';
    } elseif ($term == 'Term 2') {
        $term_numeral = 'ii';
    } elseif ($term == 'Term 3') {
        $term_numeral = 'iii';
    }
    
    // 2. Form the dynamic table name (e.g., 2025_i_alevel)
    $table_name = $year . '_' . $term_numeral . '_alevel';
    
    // 3. Get student information
    $student_sql = "SELECT * FROM students WHERE student_id = ?";
    $stmt = $conn->prepare($student_sql);
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $student_result = $stmt->get_result();
    $student_data = $student_result->fetch_assoc();
    
    // 4. Get exam sets configured for this class/term from alevel_report_settings
    $settings_sql = "SELECT * FROM alevel_report_settings WHERE class = ? AND term = ? AND year = ?";
    $stmt = $conn->prepare($settings_sql);
    $stmt->bind_param("sss", $class, $term, $year);
    $stmt->execute();
    $settings_result = $stmt->get_result();
    $settings_data = $settings_result->fetch_assoc();
    
    // 5. Get exam sets details
    $exam_sets = [];
    if ($settings_data) {
        $configured_exams = explode(',', $settings_data['exam_sets']);
        foreach ($configured_exams as $exam_set) {
            $exam_sql = "SELECT * FROM exam_sets WHERE exam_set = ?";
            $stmt = $conn->prepare($exam_sql);
            $stmt->bind_param("s", $exam_set);
            $stmt->execute();
            $exam_result = $stmt->get_result();
            if ($exam_data = $exam_result->fetch_assoc()) {
                $exam_sets[$exam_set] = $exam_data;
            }
        }
    }
    
    // 6. Get student's marks for each subject
    $subjects_sql = "SELECT DISTINCT subject FROM {$table_name} WHERE student_id = ? AND class = ? AND stream = ?";
    $stmt = $conn->prepare($subjects_sql);
    $stmt->bind_param("sss", $student_id, $class, $stream);
    $stmt->execute();
    $subjects_result = $stmt->get_result();
    
    $subjects_data = [];
    while ($subject = $subjects_result->fetch_assoc()) {
        $subject_code = $subject['subject'];
        
        // Get subject name from subjects table
        $subject_name_sql = "SELECT subj_name FROM subjects WHERE code = ?";
        $stmt = $conn->prepare($subject_name_sql);
        $stmt->bind_param("s", $subject_code);
        $stmt->execute();
        $subject_name_result = $stmt->get_result();
        $subject_name_data = $subject_name_result->fetch_assoc();
        
        $subjects_data[$subject_code] = [
            'name' => $subject_name_data ? $subject_name_data['subj_name'] : $subject_code,
            'marks' => []
        ];
        
        // Get marks for each exam set
        foreach ($configured_exams as $exam_set) {
            $marks_sql = "SELECT * FROM {$table_name} WHERE student_id = ? AND class = ? AND stream = ? AND subject = ? AND exam_type = ?";
            $stmt = $conn->prepare($marks_sql);
            $stmt->bind_param("sssss", $student_id, $class, $stream, $subject_code, $exam_set);
            $stmt->execute();
            $marks_result = $stmt->get_result();
            $marks_data = $marks_result->fetch_assoc();
            
            $subjects_data[$subject_code]['marks'][$exam_set] = $marks_data ? $marks_data['mark'] : '-';
        }
    }
    
    // 7. Return all collected data
    return [
        'student' => $student_data,
        'settings' => $settings_data,
        'exam_sets' => $exam_sets,
        'subjects' => $subjects_data
    ];
}

// Example usage
if (isset($_GET['student_id'])) {
    $student_id = $_GET['student_id'];
    $class = isset($_GET['class']) ? $_GET['class'] : 'Senior Five';
    $stream = isset($_GET['stream']) ? $_GET['stream'] : 'Arts';
    $term = isset($_GET['term']) ? $_GET['term'] : 'Term 1';
    $year = isset($_GET['year']) ? $_GET['year'] : date("Y");
    
    $report_data = getStudentReport($student_id, $class, $stream, $term, $year);
    
    // Display report
    include 'student_report_template.php';
} else {
    // Redirect to student selection page
    header("Location: select_student.php");
    exit;
}
?>