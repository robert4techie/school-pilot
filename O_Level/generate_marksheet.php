<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get form data
$class = isset($_POST['class']) ? $_POST['class'] : '';
$term = isset($_POST['term']) ? $_POST['term'] : '';
$year = isset($_POST['year']) ? $_POST['year'] : '';
$streams = isset($_POST['streams']) ? $_POST['streams'] : [];
$subjects = isset($_POST['subjects']) ? $_POST['subjects'] : [];
$marksheet_type = isset($_POST['marksheet_type']) ? $_POST['marksheet_type'] : '';

// Validate required fields
if (empty($class) || empty($term) || empty($year) || empty($streams) || empty($subjects) || empty($marksheet_type)) {
    // Redirect back with error message
    $_SESSION['error'] = "All fields are required.";
    header("Location: marksheet.php");
    exit;
}

// Store form data in session for use in the redirected pages
$_SESSION['marksheet_data'] = [
    'class' => $class,
    'term' => $term,
    'year' => $year,
    'streams' => $streams,
    'subjects' => $subjects,
    'marksheet_type' => $marksheet_type
];

// Redirect based on the selected marksheet type
switch ($marksheet_type) {
    case 'detailed':
        header("Location: detailed_marksheet.php");
        break;
    case 'summarized':
        header("Location: summarized_marksheet.php");
        break;
    case 'assessment':
        header("Location: assessment_marksheet.php");
        break;
    case 'overall':
        header("Location: overall_marksheet.php");
        break;
    default:
        // Invalid marksheet type, redirect back with error
        $_SESSION['error'] = "Invalid marksheet type selected.";
        header("Location: marksheet.php");
        break;
}
exit;