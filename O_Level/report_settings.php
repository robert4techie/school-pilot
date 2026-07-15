<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once 'connection.php';

// Initialize variables
$class = isset($_GET['class']) ? $_GET['class'] : '';
$term = isset($_GET['term']) ? $_GET['term'] : '';
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $class = $_POST['class'] ?? '';
    $term = $_POST['term'] ?? '';
    $year = $_POST['year'] ?? '';
    $action = $_POST['action'] ?? '';
    
    // Save topics if requested
    if ($action == 'save_topics' && !empty($class) && !empty($term) && !empty($year)) {
        // Process selected topics
        $selected_topics = [];
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'topic_') === 0 && $value == 1) {
                // Extract the subject and topic ID from the checkbox name
                // Format: topic_[subject]_[topic_id]
                $parts = explode('_', $key, 3);
                if (count($parts) == 3) {
                    $subject = $parts[1];
                    $topic_id = $parts[2];
                    
                    if (!isset($selected_topics[$subject])) {
                        $selected_topics[$subject] = [];
                    }
                    
                    $selected_topics[$subject][] = $topic_id;
                }
            }
        }
        
        // Save topics to database
        $result = saveReportTopicsToDb($conn, $class, $term, $year, $selected_topics);
        
        if ($result) {
            $success_message = "Report topics saved successfully!";
        } else {
            $error_message = "Failed to save report topics. Please try again.";
        }
    }
    
}

/**
 * Saves selected topics to the report_topics table
 */
function saveReportTopicsToDb($conn, $class, $term, $year, $selected_topics) {
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // First delete any existing settings for this class, term, and year
        $delete_sql = "DELETE FROM report_topics WHERE class = ? AND term = ? AND year = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_sql);
        mysqli_stmt_bind_param($delete_stmt, "sss", $class, $term, $year);
        mysqli_stmt_execute($delete_stmt);
        mysqli_stmt_close($delete_stmt);
        
        // Now insert the new settings
        $insert_sql = "INSERT INTO report_topics (subject, topic_id, class, term, year) VALUES (?, ?, ?, ?, ?)";
        $insert_stmt = mysqli_prepare($conn, $insert_sql);
        
        foreach ($selected_topics as $subject => $topics) {
            foreach ($topics as $topic_id) {
                mysqli_stmt_bind_param($insert_stmt, "sssss", $subject, $topic_id, $class, $term, $year);
                mysqli_stmt_execute($insert_stmt);
            }
        }
        
        mysqli_stmt_close($insert_stmt);
        
        // Commit the transaction
        mysqli_commit($conn);
        return true;
    } catch (Exception $e) {
        // Rollback the transaction if there was an error
        mysqli_rollback($conn);
        return false;
    }
}

/**
 * Retrieves saved report topics from the database
 */
function getReportTopicsFromDb($conn, $class, $term, $year) {
    $topics = [];
    
    $sql = "SELECT subject, topic_id FROM report_topics WHERE class = ? AND term = ? AND year = ? ORDER BY subject, topic_id";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "sss", $class, $term, $year);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $subject = $row['subject'];
            $topic_id = $row['topic_id'];
            
            if (!isset($topics[$subject])) {
                $topics[$subject] = [];
            }
            
            $topics[$subject][] = $topic_id;
        }
    }
    
    return $topics;
}

/**
 * Checks if report topics exist for the given parameters
 */
function topicsExist($conn, $class, $term, $year) {
    $sql = "SELECT COUNT(*) as count FROM report_topics WHERE class = ? AND term = ? AND year = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "sss", $class, $term, $year);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result && $row = mysqli_fetch_assoc($result)) {
        return $row['count'] > 0;
    }
    
    return false;
}

// Function to get available classes
function getClasses($conn) {
    $classes = [];
    // Filter classes for O level only (Senior One to Senior Four)
    $sql = "SELECT DISTINCT class FROM students WHERE class LIKE 'Senior One%' OR class LIKE 'Senior Two%' OR class LIKE 'Senior Three%' OR class LIKE 'Senior Four%' ORDER BY class";
    $result = mysqli_query($conn, $sql);
    
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $classes[] = $row['class'];
        }
    }
    
    return $classes;
}

// Function to get subjects
function getSubjects($conn) {
    $subjects = [];
    // Get O-level subjects only (subjects with level = 'O')
    $sql = "SELECT * FROM subjects WHERE level LIKE 'O%' ORDER BY subj_name";
    $result = mysqli_query($conn, $sql);
    
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $subjects[] = [
                'id' => $row['subj_id'],
                'name' => $row['subj_name'],
                'abbr' => $row['subj_abbr'],
                'code' => $row['code']
            ];
        }
    }
    
    return $subjects;
}

/**
 * Get topics for a subject from the aoi table and present them for selection
 * Modified to use topic_id from aoi table for saving to report_topics
 */
function getTopicsFromDb($conn, $subject, $class, $term, $year) {
    $topics = [];
    
    // First, try exact match on all criteria
    $sql = "SELECT id, topic, description FROM aoi 
            WHERE subject = ? AND class = ? AND term = ? AND year = ? 
            ORDER BY topic";
    
    $stmt = mysqli_prepare($conn, $sql);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ssss", $subject, $class, $term, $year);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($result && mysqli_num_rows($result) > 0) {
            while ($row = mysqli_fetch_assoc($result)) {
                $topic_id = $row['id']; // Use id as the topic_id for saving
                $topics[$topic_id] = [
                    'name' => $row['topic'],
                    'description' => $row['description'] ?: 'No description available'
                ];
            }
        }
        
        mysqli_stmt_close($stmt);
    }
    
    // If no topics found, try without year constraint
    if (empty($topics)) {
        $sql = "SELECT id, topic, description FROM aoi 
                WHERE subject = ? AND class LIKE ? AND term = ? 
                ORDER BY topic";
        
        $stmt = mysqli_prepare($conn, $sql);
        $class_pattern = "%" . explode(" ", $class)[0] . "%"; // Extract "Senior" part
        
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "sss", $subject, $class_pattern, $term);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if ($result && mysqli_num_rows($result) > 0) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $topic_id = $row['id']; // Use id as the topic_id for saving
                    $topics[$topic_id] = [
                        'name' => $row['topic'],
                        'description' => $row['description'] ?: 'No description available'
                    ];
                }
            }
            
            mysqli_stmt_close($stmt);
        }
    }
    
    // If still no topics, try just by subject
    if (empty($topics)) {
        $sql = "SELECT id, topic, description FROM aoi 
                WHERE subject = ? 
                ORDER BY topic 
                LIMIT 10";
                
        $stmt = mysqli_prepare($conn, $sql);
        
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $subject);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if ($result && mysqli_num_rows($result) > 0) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $topic_id = $row['id']; // Use id as the topic_id for saving
                    $topics[$topic_id] = [
                        'name' => $row['topic'],
                        'description' => $row['description'] ?: 'No description available' 
                    ];
                }
            }
            
            mysqli_stmt_close($stmt);
        }
    }
    
    // Always include EOT with proper description
    $topics['EOT'] = [
        'name' => 'End of Term Examination',
        'description' => 'Final examination covering all topics for the term'
    ];
    
    return $topics;
}

// Get available classes and subjects
$available_classes = getClasses($conn);
$available_subjects = getSubjects($conn);

// Check if topics exist for the selected class, term, and year
$topics_exist = false;
$selected_topics = [];
if (!empty($class) && !empty($term) && !empty($year)) {
    $topics_exist = topicsExist($conn, $class, $term, $year);
    if ($topics_exist) {
        $selected_topics = getReportTopicsFromDb($conn, $class, $term, $year);
    }
}

// Determine which subjects to display
$subjects_to_display = [];

// Always start with all available subjects
foreach ($available_subjects as $subject) {
    $subjects_to_display[] = $subject['name'];
}

// If we have previously selected topics, ensure those subjects are included too
if (!empty($selected_topics)) {
    foreach (array_keys($selected_topics) as $subject) {
        if (!in_array($subject, $subjects_to_display)) {
            $subjects_to_display[] = $subject;
        }
    }
}

// Sort the subjects alphabetically for consistent display
sort($subjects_to_display);
?>
<!DOCTYPE html>
<html data-bs-theme="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Report Topic Selector</title>
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/fonts/fontawesome-all.min.css">
    <link rel="stylesheet" href="../assets/fonts/font-awesome.min.css">
    <link rel="stylesheet" href="../assets/fonts/fontawesome5-overrides.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2-bootstrap-theme/0.1.0-beta.10/select2-bootstrap.min.css" />
    <link rel="stylesheet" href="../assets/css/custom.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --primary-dark: #2980b9;
            --secondary-color: #2ecc71;
            --secondary-dark: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-bg: #f8f9fa;
            --dark-bg: #343a40;
            --border-color: #e9ecef;
            --text-color: #495057;
            --text-muted: #6c757d;
            --shadow-sm: 0 .125rem .25rem rgba(0, 0, 0, .075);
            --shadow: 0 .5rem 1rem rgba(0, 0, 0, .15);
            --transition: all 0.3s ease;
        }
        
        .page-title {
            margin-bottom: 2rem;
        }
        
        .header-title {
            background: linear-gradient(to right, #f5f7fa, #e4eff9);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary-color);
            font-weight: 600;
            color: var(--dark-bg);
            border-radius: 0.25rem;
            box-shadow: var(--shadow-sm);
        }
        
        .settings-actions {
            background: var(--light-bg);
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }
        
        .settings-actions .btn {
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 0.25rem;
            transition: var(--transition);
        }
        
        .subject-card {
            border: none;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition);
        }
        
        .subject-card:hover {
            box-shadow: var(--shadow);
            transform: translateY(-2px);
        }
        
        .subject-header {
            background: linear-gradient(to right, #f9f9f9, #edf5fd);
            padding: 1rem 1.25rem;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
            cursor: pointer;
            position: relative;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .subject-header:hover {
            background: linear-gradient(to right, #edf5fd, #d6e9fa);
            color: var(--primary-dark);
        }
        
        .subject-header i.expand-icon {
            transition: transform 0.3s;
        }
        
        .subject-badge {
            font-size: 0.75rem;
            font-weight: 500;
            padding: 0.25rem 0.5rem;
            border-radius: 50px;
            margin-left: 0.5rem;
            background-color: var(--primary-color);
            color: white;
            display: inline-flex;
            align-items: center;
        }
        
        .topic-list-container {
            display: none; /* Hide initially */
        }
        
        .topic-list {
            background-color: white;
            padding: 0;
            max-height: 400px;
            overflow-y: auto;
            border-radius: 0 0 0.5rem 0.5rem;
            scrollbar-width: thin;
            scrollbar-color: var(--border-color) transparent;
        }
        
        .topic-list::-webkit-scrollbar {
            width: 5px;
        }
        
        .topic-list::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .topic-list::-webkit-scrollbar-thumb {
            background-color: var(--border-color);
            border-radius: 10px;
        }
        
        .topic-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-color);
            transition: var(--transition);
        }
        
        .topic-item:hover {
            background-color: rgba(52, 152, 219, 0.05);
        }
        
        .topic-item:last-child {
            border-bottom: none;
        }
        
        .topic-name {
            font-weight: 500;
            color: var(--text-color);
            display: block;
            margin-bottom: 0.25rem;
        }
        
        .topic-description {
            color: var(--text-muted);
            font-size: 0.85rem;
            margin-top: 0.25rem;
            display: block;
            padding-left: 22px;
        }
        
        .form-section {
            margin-bottom: 2rem;
            padding: 1.5rem;
            background-color: white;
            border-radius: 0.5rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }
        
        .form-section label {
            font-weight: 500;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }
        
        .form-section .form-control {
            border-radius: 0.25rem;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-color);
            transition: var(--transition);
        }
        
        .form-section .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }
        
        .card-actions {
            padding: 0.75rem 1rem;
            background-color: rgba(52, 152, 219, 0.05);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-actions button {
            margin-right: 0.5rem;
            padding: 0.25rem 0.5rem;
            font-size: 0.85rem;
        }
        
        .topics-count {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        /* Custom checkbox styling */
        .custom-checkbox {
            position: relative;
            padding-left: 28px;
            cursor: pointer;
            display: block;
            margin-bottom: 0;
        }
        
        .custom-checkbox input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }
        
        .checkmark {
            position: absolute;
            top: 2px;
            left: 0;
            height: 18px;
            width: 18px;
            background-color: white;
            border-radius: 4px;
            border: 2px solid var(--border-color);
            transition: var(--transition);
        }
        
        .custom-checkbox:hover input ~ .checkmark {
            border-color: var(--primary-color);
        }
        
        .custom-checkbox input:checked ~ .checkmark {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .checkmark:after {
            content: "";
            position: absolute;
            display: none;
        }
        
        .custom-checkbox input:checked ~ .checkmark:after {
            display: block;
        }
        
        .custom-checkbox .checkmark:after {
            left: 5px;
            top: 1px;
            width: 6px;
            height: 10px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }
        
        /* Essential topics (like EOT) */
        .essential-topic-item {
            background-color: rgba(46, 204, 113, 0.05);
        }
        
        .essential-topic .checkmark {
            border-color: var(--secondary-color);
        }
        
        .custom-checkbox input.essential-topic:checked ~ .checkmark {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }
        
        .topic-tag {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 500;
            padding: 0.15rem 0.5rem;
            border-radius: 50px;
            margin-left: 0.5rem;
            background-color: var(--secondary-color);
            color: white;
        }
        
        /* Button styles */
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
        }
        
        .btn-success {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }
        
        .btn-success:hover {
            background-color: var(--secondary-dark);
            border-color: var(--secondary-dark);
        }
        
        /* Select2 styles */
        .select2-container--bootstrap .select2-selection {
            height: calc(1.5em + 0.75rem + 2px);
            padding: 0.375rem 0.75rem;
            font-size: 1rem;
            font-weight: 400;
            line-height: 1.5;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
        }
        
        .select2-container--bootstrap .select2-selection--single .select2-selection__rendered {
            color: #495057;
            padding: 0;
        }
        
        .select2-container--bootstrap .select2-results__option--highlighted[aria-selected] {
            background-color: var(--primary-color);
        }
        
        /* Loading spinner */
        .spinner-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(255, 255, 255, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            visibility: hidden;
            opacity: 0;
            transition: var(--transition);
        }
        
        .spinner-overlay.show {
            visibility: visible;
            opacity: 1;
        }
        
        /* Animation for added elements */
        .animate-fade-in {
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Topic list slide animation */
        .topic-list-container.show {
            display: block;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .form-section {
                padding: 1rem;
            }
            
            .subject-card {
                margin-bottom: 1rem;
            }
            
            .settings-actions .btn {
                width: 100%;
                margin-right: 0;
            }
        }
        
        .topic-list::-webkit-scrollbar {
            width: 5px;
        }
        
        .topic-list::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .topic-list::-webkit-scrollbar-thumb {
            background-color: var(--border-color);
            border-radius: 10px;
        }
        
        .topic-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-color);
            transition: var(--transition);
        }
        
        .topic-item:hover {
            background-color: rgba(52, 152, 219, 0.05);
        }
        
        .topic-item:last-child {
            border-bottom: none;
        }
        
        .topic-name {
            font-weight: 500;
            color: var(--text-color);
            display: block;
            margin-bottom: 0.25rem;
        }
        
        .topic-description {
            color: var(--text-muted);
            font-size: 0.85rem;
            margin-top: 0.25rem;
            display: block;
            padding-left: 22px;
        }
        
        .form-section {
            margin-bottom: 2rem;
            padding: 1.5rem;
            background-color: white;
            border-radius: 0.5rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }
        
        .form-section label {
            font-weight: 500;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }
        
        .form-section .form-control {
            border-radius: 0.25rem;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-color);
            transition: var(--transition);
        }
        
        .form-section .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }
        
        .card-actions {
            padding: 0.75rem 1rem;
            background-color: rgba(52, 152, 219, 0.05);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-actions button {
            margin-right: 0.5rem;
            padding: 0.25rem 0.5rem;
            font-size: 0.85rem;
        }
        
        .topics-count {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        /* Custom checkbox styling */
        .custom-checkbox {
            position: relative;
            padding-left: 28px;
            cursor: pointer;
            display: block;
            margin-bottom: 0;
        }
        
        .custom-checkbox input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }
        
        .checkmark {
            position: absolute;
            top: 2px;
            left: 0;
            height: 18px;
            width: 18px;
            background-color: white;
            border-radius: 4px;
            border: 2px solid var(--border-color);
            transition: var(--transition);
        }
        
        .custom-checkbox:hover input ~ .checkmark {
            border-color: var(--primary-color);
        }
        
        .custom-checkbox input:checked ~ .checkmark {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .checkmark:after {
            content: "";
            position: absolute;
            display: none;
        }
        
        .custom-checkbox input:checked ~ .checkmark:after {
            display: block;
        }
        
        .custom-checkbox .checkmark:after {
            left: 5px;
            top: 1px;
            width: 6px;
            height: 10px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }
        
        /* Essential topics (like EOT) */
        .essential-topic-item {
            background-color: rgba(46, 204, 113, 0.05);
        }
        
        .essential-topic .checkmark {
            border-color: var(--secondary-color);
        }
        
        .custom-checkbox input.essential-topic:checked ~ .checkmark {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }
        
        .topic-tag {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 500;
            padding: 0.15rem 0.5rem;
            border-radius: 50px;
            margin-left: 0.5rem;
            background-color: var(--secondary-color);
            color: white;
        }
        
        /* Button styles */
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
        }
        
        .btn-success {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }
        
        .btn-success:hover {
            background-color: var(--secondary-dark);
            border-color: var(--secondary-dark);
        }
        
        /* Select2 styles */
        .select2-container--bootstrap .select2-selection {
            height: calc(1.5em + 0.75rem + 2px);
            padding: 0.375rem 0.75rem;
            font-size: 1rem;
            font-weight: 400;
            line-height: 1.5;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
        }
        
        .select2-container--bootstrap .select2-selection--single .select2-selection__rendered {
            color: #495057;
            padding: 0;
        }
        
        .select2-container--bootstrap .select2-results__option--highlighted[aria-selected] {
            background-color: var(--primary-color);
        }
        
        /* Loading spinner */
        .spinner-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(255, 255, 255, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            visibility: hidden;
            opacity: 0;
            transition: var(--transition);
        }
        
        .spinner-overlay.show {
            visibility: visible;
            opacity: 1;
        }
        
        /* Animation for added elements */
        .animate-fade-in {
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .form-section {
                padding: 1rem;
            }
            
            .subject-card {
                margin-bottom: 1rem;
            }
            
            .settings-actions .btn {
                width: 100%;
                margin-right: 0;
            }
        }
    </style>
</head>
<body class="nav-md">
    <div class="container body">
        <div class="main_container">
            <!-- Loading spinner -->
            <div class="spinner-overlay" id="spinnerOverlay">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Loading...</span>
                </div>
            </div>
            
            <!-- Main Content Area -->
            <div class="right_col" role="main">
                <div class="page-title">
                    <div class="title_left">
                        <h3><i class="fa fa-list-check me-2"></i> Report Topic Selector</h3>
                        <p class="text-muted">Select topics to be displayed on student report cards</p>
                    </div>
                </div>
                
                <div class="clearfix"></div>
                
                <div class="row">
                    <div class="col-md-12 col-sm-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2><i class="fa fa-cog me-2"></i> Configure Report Card Topics</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <!-- Display success/error messages -->
                                <?php if (isset($success_message)): ?>
                                <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                                    <i class="fa fa-check-circle me-2"></i> <?php echo $success_message; ?>
                                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (isset($error_message)): ?>
                                <div class="alert alert-danger alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                                    <i class="fa fa-exclamation-triangle me-2"></i> <?php echo $error_message; ?>
                                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Class, Term, Year Selection Form -->
                                <div class="form-section animate-fade-in">
                                    <form method="get" action="" id="filterForm">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label for="class"><i class="fa fa-users me-1"></i> Class:</label>
                                                    <select class="form-control select2" id="class" name="class" required>
                                                        <option value="">Select Class</option>
                                                        <?php foreach ($available_classes as $available_class): ?>
                                                        <option value="<?php echo $available_class; ?>" <?php echo $class == $available_class ? 'selected' : ''; ?>>
                                                            <?php echo $available_class; ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="term"><i class="fa fa-calendar-alt me-1"></i> Term:</label>
                                                    <select class="form-control select2" id="term" name="term" required>
                                                        <option value="">Select Term</option>
                                                        <option value="Term 1" <?php echo $term == 'Term 1' ? 'selected' : ''; ?>>Term 1</option>
                                                        <option value="Term 2" <?php echo $term == 'Term 2' ? 'selected' : ''; ?>>Term 2</option>
                                                        <option value="Term 3" <?php echo $term == 'Term 3' ? 'selected' : ''; ?>>Term 3</option>
                                                    </select>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="year"><i class="fa fa-calendar me-1"></i> Year:</label>
                                                    <select class="form-control select2" id="year" name="year" required>
                                                        <option value="">Select Year</option>
                                                        <?php
                                                        $current_year = date("Y");
                                                        for ($i = $current_year; $i >= $current_year - 5; $i--) {
                                                            echo '<option value="' . $i . '" ' . ($year == $i ? 'selected' : '') . '>' . $i . '</option>';
                                                        }
                                                        ?>
                                                    </select>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-2">
                                                <div class="form-group">
                                                    <label>&nbsp;</label>
                                                    <button type="submit" class="btn btn-primary btn-block" id="viewTopicsBtn">
                                                        <i class="fa fa-search me-1"></i> View Topics
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                                
                                <?php if (!empty($class) && !empty($term) && !empty($year)): ?>
                                <form method="post" action="" id="topicsForm">
                                    <div class="header-title animate-fade-in">
                                        <i class="fa fa-list-check me-2"></i> SELECT TOPICS TO DISPLAY ON REPORT CARDS
                                        <div class="float-end">
                                            <span class="badge bg-info">
                                                <i class="fa fa-info-circle me-1"></i> <?php echo $class; ?> | <?php echo $term; ?> | <?php echo $year; ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="settings-actions animate-fade-in">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <button type="button" class="btn btn-outline-primary" id="selectAllTopics">
                                                    <i class="fa fa-check-square"></i> Select All Topics
                                                </button>
                                                <button type="button" class="btn btn-outline-secondary" id="deselectAllTopics">
                                                    <i class="fa fa-square"></i> Deselect All Topics
                                                </button>
                                                <button type="button" class="btn btn-outline-success" id="selectEotOnly">
                                                    <i class="fa fa-filter"></i> Select Only EOT
                                                </button>
                                                <button type="button" class="btn btn-outline-info" id="expandAllSubjects">
                                                    <i class="fa fa-plus-square"></i> Expand All
                                                </button>
                                                <button type="button" class="btn btn-outline-info" id="collapseAllSubjects">
                                                    <i class="fa fa-minus-square"></i> Collapse All
                                                </button>
                                            </div>
                                            <div class="col-md-4 text-end">
                                                <div class="input-group">
                                                    <input type="text" class="form-control" id="topicSearch" placeholder="Search topics...">
                                                    <div class="input-group-append">
                                                        <span class="input-group-text"><i class="fa fa-search"></i></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row" id="subjectsContainer">
                                    <?php 
                                    // Display subjects and their topics
                                    $count = 0;
                                    foreach ($subjects_to_display as $subject) {
                                        $count++;
                                        
                                        // Get topics for this subject from database with full topic info
                                        $topics = getTopicsFromDb($conn, $subject, $class, $term, $year);
                                        
                                        // Always ensure EOT is included
                                        if (!isset($topics['EOT'])) {
                                            $topics['EOT'] = [
                                                'name' => 'End of Term Examination',
                                                'description' => 'Final examination covering all topics for the term'
                                            ];
                                        }
                                        
                                        // If no topics found for this subject, add a default empty message
                                        if (count($topics) <= 1) { // Only EOT is present
                                            // Show a message in the UI
                                            echo '<div class="col-md-12 text-center p-4">';
                                            echo '<div class="alert alert-info">';
                                            echo '<i class="fa fa-info-circle me-2"></i>';
                                            echo 'No topics found for ' . $subject . ' in ' . $class . ', ' . $term . ', ' . $year . '. ';
                                            echo 'Select topics from other available subjects.';
                                            echo '</div>';
                                            echo '</div>';
                                        }
                                        
                                        // Count selected topics for this subject
                                        $selected_count = 0;
                                        if (!empty($selected_topics) && isset($selected_topics[$subject])) {
                                            $selected_count = count($selected_topics[$subject]);
                                        }
                                        
                                        // Start a new column for each subject card
                                        echo '<div class="col-md-6 subject-container animate-fade-in">';
                                        echo '<div class="subject-card" data-subject="' . $subject . '">';
                                        
                                        // Subject header with count badge
                                        echo '<div class="subject-header collapsed" data-toggle="collapse">';
                                        echo '<div>';
                                        echo '<i class="fa fa-book me-2"></i> ' . $subject;
                                        
                                        // Add badge showing topic count
                                        echo '<span class="subject-badge">';
                                        echo '<i class="fa fa-list-ul me-1"></i> ';
                                        echo count($topics) . ' topics';
                                        echo '</span>';
                                        
                                        // Add badge showing selected count if any
                                        if ($selected_count > 0) {
                                            echo '<span class="subject-badge ms-1" style="background-color: var(--secondary-color);">';
                                            echo '<i class="fa fa-check me-1"></i> ';
                                            echo $selected_count . ' selected';
                                            echo '</span>';
                                        }
                                        
                                        echo '</div>';
                                        echo '<i class="fa fa-chevron-right expand-icon"></i>';
                                        echo '</div>';
                                        
                                        // Topic list (collapsed by default)
                                        echo '<div class="topic-list-container">';
                                        echo '<div class="topic-list">';
                                        
                                        foreach ($topics as $topic_id => $topic_info) {
                                            $is_selected = false;
                                            $is_essential = ($topic_id == 'EOT');
                                            $topic_class = $is_essential ? 'essential-topic' : '';
                                            $item_class = $is_essential ? 'essential-topic-item' : '';
                                            
                                            // Check if this topic was previously selected
                                            if (!empty($selected_topics) && isset($selected_topics[$subject]) && in_array($topic_id, $selected_topics[$subject])) {
                                                $is_selected = true;
                                            }
                                            
                                            echo '<div class="topic-item ' . $item_class . '" data-topic-id="' . $topic_id . '">';
                                            echo '<label class="custom-checkbox">';
                                            echo '<input type="checkbox" class="topic-checkbox ' . $topic_class . '" ';
                                            echo 'name="topic_' . $subject . '_' . $topic_id . '" ';
                                            echo 'data-subject="' . $subject . '" ';
                                            echo 'data-topic-id="' . $topic_id . '" ';
                                            echo 'value="1" ' . ($is_selected || empty($selected_topics) ? 'checked' : '') . '> ';
                                            echo '<span class="checkmark"></span>';
                                            
                                            // Topic name and ID
                                            echo '<span class="topic-name">';
                                            echo $topic_info['name'];
                                            
                                            // Show topic ID if different from name
                                            if ($topic_id != $topic_info['name']) {
                                                echo ' <small class="text-muted">(' . $topic_id . ')</small>';
                                            }
                                            
                                            // Add EOT tag for essential topics
                                            if ($is_essential) {
                                                echo '<span class="topic-tag">EOT</span>';
                                            }
                                            
                                            echo '</span>';
                                            
                                            // Topic description
                                            if (!empty($topic_info['description'])) {
                                                echo '<small class="topic-description">' . $topic_info['description'] . '</small>';
                                            }
                                            
                                            echo '</label>';
                                            echo '</div>';
                                        }
                                        
                                        echo '</div>'; // End topic-list
                                        
                                        // Card actions
                                        echo '<div class="card-actions">';
                                        echo '<div>';
                                        echo '<button type="button" class="btn btn-xs btn-outline-primary select-subject" data-subject="' . $subject . '">';
                                        echo '<i class="fa fa-check-square me-1"></i> Select All';
                                        echo '</button>';
                                        echo '<button type="button" class="btn btn-xs btn-outline-secondary deselect-subject" data-subject="' . $subject . '">';
                                        echo '<i class="fa fa-square me-1"></i> Deselect All';
                                        echo '</button>';
                                        echo '</div>';
                                        
                                        // Show count of selected topics
                                        echo '<div class="topics-count">';
                                        echo '<span class="selected-count">0</span>/<span class="total-count">' . count($topics) . '</span> selected';
                                        echo '</div>';
                                        
                                        echo '</div>'; // End card-actions
                                        
                                        echo '</div>'; // End collapse div
                                        echo '</div>'; // End subject-card
                                        echo '</div>'; // End col-md-6
                                    }
                                    
                                    // If no subjects were found
                                    if (empty($subjects_to_display)) {
                                        echo '<div class="col-md-12">';
                                        echo '<div class="alert alert-info animate-fade-in">';
                                        echo '<i class="fa fa-info-circle me-2"></i> No subjects found for the selected class, term, and year.';
                                        echo '</div>';
                                        echo '</div>';
                                    }
                                    ?>
                                    </div><!-- End row -->
                                    
                                    <input type="hidden" name="class" value="<?php echo htmlspecialchars($class); ?>">
                                    <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
                                    <input type="hidden" name="year" value="<?php echo htmlspecialchars($year); ?>">
                                    <input type="hidden" name="action" value="save_topics">
                                    
                                    <div class="form-group text-center mt-4 animate-fade-in">
                                        <button type="submit" class="btn btn-success btn-lg" id="saveTopicsBtn">
                                            <i class="fa fa-save me-2"></i> Save Topic Settings
                                        </button>
                                    </div>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript Files -->
    <script src="../assets/js/jquery.min.js"></script>
    <script src="../assets/bootstrap/js/bootstrap.min.js"></script>
    <script src="../assets/js/bs-init.js"></script>
    <script src="../assets/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <script src="../assets/js/custom.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Select2
            $('.select2').select2({
                theme: 'bootstrap',
                width: '100%'
            });
            
            // Show loading spinner when submitting forms
            $('#filterForm, #topicsForm').on('submit', function() {
                $('#spinnerOverlay').addClass('show');
            });
            
            // Update selected count for each subject on page load
            updateAllSelectedCounts();
            
            // Accordion behavior for subject headers
            $('.subject-header').on('click', function() {
                // Toggle the collapsed class for styling
                $(this).toggleClass('collapsed');
                
                // Find the collapse container
                const collapseContainer = $(this).siblings('.topic-list-container');
                
                // Toggle the collapse state
                if (collapseContainer.hasClass('show')) {
                    collapseContainer.removeClass('show').slideUp(300);
                } else {
                    collapseContainer.addClass('show').slideDown(300);
                }
                
                // Toggle the chevron icon
                $(this).find('.expand-icon').toggleClass('fa-chevron-down fa-chevron-right');
            });
            
            // Initialize all collapse containers as hidden
            $('.topic-list-container').hide();
            
            // Update selected count when checkbox state changes
            $('.topic-checkbox').on('change', function() {
                const subject = $(this).data('subject');
                updateSelectedCount(subject);
            });
            
            // Topic search functionality
            $('#topicSearch').on('keyup', function() {
                const searchTerm = $(this).val().toLowerCase();
                
                if (searchTerm.length > 1) {
                    // Hide all subject containers first
                    $('.subject-container').hide();
                    
                    // Show only relevant topics and their subject containers
                    $('.topic-item').each(function() {
                        const topicText = $(this).text().toLowerCase();
                        const subjectContainer = $(this).closest('.subject-container');
                        
                        if (topicText.includes(searchTerm)) {
                            $(this).show();
                            subjectContainer.show();
                            
                            // Expand the subject card if it's collapsed
                            const collapseElement = $(this).closest('.topic-list-container');
                            if (!collapseElement.hasClass('show')) {
                                collapseElement.addClass('show').show();
                                subjectContainer.find('.subject-header').removeClass('collapsed');
                                subjectContainer.find('.expand-icon').removeClass('fa-chevron-right').addClass('fa-chevron-down');
                            }
                        } else {
                            $(this).hide();
                        }
                    });
                } else {
                    // If search is cleared, show all subjects and reset
                    $('.subject-container').show();
                    $('.topic-item').show();
                }
            });
            
            // Select all topics button (global)
            $('#selectAllTopics').click(function() {
                $('.topic-checkbox').prop('checked', true);
                updateAllSelectedCounts();
            });
            
            // Deselect all topics button (global)
            $('#deselectAllTopics').click(function() {
                $('.topic-checkbox').prop('checked', false);
                updateAllSelectedCounts();
            });
            
            // Select only EOT (global)
            $('#selectEotOnly').click(function() {
                $('.topic-checkbox').prop('checked', false);
                $('.essential-topic').prop('checked', true);
                updateAllSelectedCounts();
            });
            
            // Expand all subjects
            $('#expandAllSubjects').click(function() {
                $('.topic-list-container').addClass('show').slideDown(300);
                $('.subject-header').removeClass('collapsed');
                $('.subject-header .expand-icon').removeClass('fa-chevron-right').addClass('fa-chevron-down');
            });
            
            // Collapse all subjects
            $('#collapseAllSubjects').click(function() {
                $('.topic-list-container').removeClass('show').slideUp(300);
                $('.subject-header').addClass('collapsed');
                $('.subject-header .expand-icon').removeClass('fa-chevron-down').addClass('fa-chevron-right');
            });
            
            // Select all topics for a specific subject
            $('.select-subject').click(function() {
                const subject = $(this).data('subject');
                $('input[data-subject="' + subject + '"]').prop('checked', true);
                updateSelectedCount(subject);
            });
            
            // Deselect all topics for a specific subject
            $('.deselect-subject').click(function() {
                const subject = $(this).data('subject');
                $('input[data-subject="' + subject + '"]').prop('checked', false);
                updateSelectedCount(subject);
            });
            
            // Function to update the selected count for a subject
            function updateSelectedCount(subject) {
                const subjectCard = $('.subject-card[data-subject="' + subject + '"]');
                const totalTopics = subjectCard.find('.topic-checkbox').length;
                const selectedTopics = subjectCard.find('.topic-checkbox:checked').length;
                
                // Update the counter at the bottom of the card
                subjectCard.find('.selected-count').text(selectedTopics);
                
                // Update the selected badge in the header
                const header = subjectCard.find('.subject-header');
                const existingBadge = header.find('.subject-badge').filter(function() {
                    return $(this).text().includes('selected');
                });
                
                if (selectedTopics > 0) {
                    if (existingBadge.length) {
                        existingBadge.html('<i class="fa fa-check me-1"></i> ' + selectedTopics + ' selected');
                    } else {
                        header.find('div').append('<span class="subject-badge ms-1" style="background-color: var(--secondary-color);"><i class="fa fa-check me-1"></i> ' + selectedTopics + ' selected</span>');
                    }
                } else {
                    existingBadge.remove();
                }
            }
            
            // Function to update all selected counts
            function updateAllSelectedCounts() {
                $('.subject-card').each(function() {
                    const subject = $(this).data('subject');
                    if (subject) {
                        updateSelectedCount(subject);
                    }
                });
            }
        });
    </script>
</body>
</html>