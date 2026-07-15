<?php
/**
 * A-Level Report Viewer Bridge - FIXED VERSION
 * This file acts as a bridge between the verification page and the report generator
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../auth.php';
require_once '../conn.php';

// Debug: Log all GET parameters
error_log("GET Parameters: " . print_r($_GET, true));

// Get parameters from URL - with more flexible handling
$student_id = isset($_GET['student_id']) ? trim($_GET['student_id']) : '';
$term = isset($_GET['term']) ? trim($_GET['term']) : '';
$year = isset($_GET['year']) ? trim($_GET['year']) : '';
$class = isset($_GET['class']) ? trim($_GET['class']) : '';
$stream = isset($_GET['stream']) ? trim($_GET['stream']) : '';

// Debug output function
function showDebugError($message, $details = []) {
    echo "<!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Debug Information</title>
        <link href='https://fonts.googleapis.com/css2?family=Sen:wght@400;600;700&display=swap' rel='stylesheet'>
        <style>
            body {
                font-family: 'Sen', sans-serif;
                background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
                padding: 20px;
                margin: 0;
            }
            .debug-box {
                background: white;
                max-width: 800px;
                margin: 20px auto;
                padding: 30px;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            }
            h2 {
                color: #1565c0;
                margin-bottom: 20px;
            }
            .detail-row {
                padding: 10px;
                margin: 5px 0;
                background: #f5f5f5;
                border-radius: 6px;
                display: flex;
                gap: 15px;
            }
            .detail-label {
                font-weight: bold;
                color: #1565c0;
                min-width: 150px;
            }
            .detail-value {
                color: #333;
                font-family: monospace;
            }
            .error-msg {
                background: #ffebee;
                border-left: 4px solid #f44336;
                padding: 15px;
                margin: 20px 0;
                border-radius: 6px;
            }
            .back-btn {
                display: inline-block;
                margin-top: 20px;
                padding: 12px 30px;
                background: #1565c0;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 600;
            }
        </style>
    </head>
    <body>
        <div class='debug-box'>
            <h2>🔍 Debug Information</h2>
            <div class='error-msg'>
                <strong>Error:</strong> " . htmlspecialchars($message) . "
            </div>";
    
    if (!empty($details)) {
        echo "<h3 style='margin-top: 25px; color: #555;'>Received Parameters:</h3>";
        foreach ($details as $key => $value) {
            $displayValue = empty($value) ? '<span style="color: #f44336;">[EMPTY]</span>' : htmlspecialchars($value);
            echo "<div class='detail-row'>
                    <div class='detail-label'>{$key}:</div>
                    <div class='detail-value'>{$displayValue}</div>
                  </div>";
        }
        
        echo "<h3 style='margin-top: 25px; color: #555;'>Full URL:</h3>";
        echo "<div class='detail-row' style='display: block;'>
                <code style='word-break: break-all; color: #666;'>" . htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'Unknown') . "</code>
              </div>";
    }
    
    echo "
            <a href='javascript:history.back()' class='back-btn'>← Go Back</a>
            <a href='verify_alevel_report.php?student_id=" . urlencode($details['student_id'] ?? '') . 
                "&term=" . urlencode($details['term'] ?? '') . 
                "&year=" . urlencode($details['year'] ?? '') . 
                "&class=" . urlencode($details['class'] ?? '') . 
                "&stream=" . urlencode($details['stream'] ?? '') . "' class='back-btn' style='background: #4caf50; margin-left: 10px;'>Try Verification Page</a>
        </div>
    </body>
    </html>";
    exit;
}

// Validation with detailed feedback
$params = [
    'student_id' => $student_id,
    'term' => $term,
    'year' => $year,
    'class' => $class,
    'stream' => $stream
];

if (empty($student_id) || empty($term) || empty($year) || empty($class)) {
    showDebugError("Missing Required Parameters", $params);
}

// Verify student exists
$student_check = "SELECT student_id, first_name, last_name, current_class, stream 
                  FROM students 
                  WHERE student_id = ?";
$stmt = mysqli_prepare($conn, $student_check);

if (!$stmt) {
    showDebugError("Database Error", array_merge($params, ['db_error' => mysqli_error($conn)]));
}

mysqli_stmt_bind_param($stmt, "s", $student_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$student = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$student) {
    showDebugError("Student Not Found in Database", array_merge($params, [
        'note' => 'The student ID does not exist in the students table'
    ]));
}

// Build table name
$term_number = filter_var($term, FILTER_SANITIZE_NUMBER_INT);
$romans = ['i', 'ii', 'iii'];
$term_roman = isset($romans[$term_number - 1]) ? $romans[$term_number - 1] : 'i';
$table_name = "{$year}_{$term_roman}_alevel";

// Check if table exists
$table_check = "SHOW TABLES LIKE '$table_name'";
$table_result = mysqli_query($conn, $table_check);
$table_exists = $table_result && mysqli_num_rows($table_result) > 0;

if (!$table_exists) {
    showDebugError("Marks Table Does Not Exist", array_merge($params, [
        'table_name' => $table_name,
        'note' => 'The marks table for this term has not been created yet'
    ]));
}

// Get exam sets for this student
$exam_sets_query = "SELECT DISTINCT exam_type FROM `$table_name` 
                    WHERE student_id = ? 
                    ORDER BY exam_type";
$stmt = mysqli_prepare($conn, $exam_sets_query);

if (!$stmt) {
    showDebugError("Query Preparation Failed", array_merge($params, [
        'table_name' => $table_name,
        'db_error' => mysqli_error($conn)
    ]));
}

mysqli_stmt_bind_param($stmt, "s", $student_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$exam_sets = [];
while ($row = mysqli_fetch_assoc($result)) {
    $exam_sets[] = $row['exam_type'];
}
mysqli_stmt_close($stmt);

if (empty($exam_sets)) {
    showDebugError("No Exam Results Found", array_merge($params, [
        'table_name' => $table_name,
        'student_name' => $student['first_name'] . ' ' . $student['last_name'],
        'note' => 'Student exists but has no marks recorded in this table'
    ]));
}

// If we got here, everything is valid! Show loading screen and redirect
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loading A-Level Report...</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Sen", sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .loading-container {
            background: white;
            border-radius: 16px;
            padding: 50px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            text-align: center;
            max-width: 500px;
            width: 100%;
            animation: fadeIn 0.6s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .spinner {
            width: 60px;
            height: 60px;
            border: 4px solid #e0e0e0;
            border-top: 4px solid #1565c0;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 30px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        h2 {
            color: #1565c0;
            font-size: 24px;
            margin-bottom: 15px;
            font-weight: 700;
        }

        p {
            color: #555;
            font-size: 16px;
            line-height: 1.6;
        }

        .success-icon {
            font-size: 48px;
            margin-bottom: 20px;
        }

        .details {
            background: #e8f5e9;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 14px;
            color: #2e7d32;
            text-align: left;
            border-left: 4px solid #4caf50;
        }

        .details strong {
            color: #1b5e20;
        }

        .details-row {
            padding: 5px 0;
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e0e0e0;
            border-radius: 3px;
            margin-top: 25px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #1565c0 0%, #1976d2 100%);
            width: 0%;
            animation: progress 1.5s ease-in-out forwards;
        }

        @keyframes progress {
            0% { width: 0%; }
            100% { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="loading-container">
        <div class="success-icon">✅</div>
        <h2>Loading A-Level Report...</h2>
        <p>All validations passed. Generating report card...</p>
        
        <div class="details">
            <div class="details-row">
                <strong>✓ Student:</strong> <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
            </div>
            <div class="details-row">
                <strong>✓ Student ID:</strong> <?php echo htmlspecialchars($student_id); ?>
            </div>
            <div class="details-row">
                <strong>✓ Term:</strong> <?php echo htmlspecialchars($term); ?> <?php echo htmlspecialchars($year); ?>
            </div>
            <div class="details-row">
                <strong>✓ Class:</strong> <?php echo htmlspecialchars($class); ?> (<?php echo htmlspecialchars($stream); ?>)
            </div>
            <div class="details-row">
                <strong>✓ Exam Sets:</strong> <?php echo count($exam_sets); ?> found
            </div>
        </div>
        
        <div class="progress-bar">
            <div class="progress-fill"></div>
        </div>
    </div>

    <!-- Auto-submit form to pass data to reports.php -->
    <form id="reportForm" action="reports.php" method="POST" style="display: none;">
        <input type="hidden" name="class" value="<?php echo htmlspecialchars($class); ?>">
        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
        <input type="hidden" name="year" value="<?php echo htmlspecialchars($year); ?>">
        <input type="hidden" name="level" value="A Level">
        <input type="hidden" name="streams[]" value="<?php echo htmlspecialchars($stream); ?>">
        <?php foreach ($exam_sets as $exam_id): ?>
            <input type="hidden" name="exam_sets[]" value="<?php echo htmlspecialchars($exam_id); ?>">
        <?php endforeach; ?>
        <input type="hidden" name="grading_type" value="percentage">
    </form>

    <script>
        // Auto-submit the form after animation completes
        setTimeout(function() {
            document.getElementById('reportForm').submit();
        }, 1800);
    </script>
</body>
</html>
<?php
mysqli_close($conn);
?>