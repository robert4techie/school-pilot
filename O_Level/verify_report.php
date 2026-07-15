<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../conn.php';

// Check database connection
if (!isset($conn) || !$conn) {
    die('connection');
}

// Sanitization function
function sanitize_input($data)
{
    if ($data === null) return '';
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Get and validate parameters
$student_id = isset($_GET['student_id']) ? sanitize_input($_GET['student_id']) : '';
$term = isset($_GET['term']) ? sanitize_input($_GET['term']) : '';
$year = isset($_GET['year']) ? sanitize_input($_GET['year']) : '';
$class = isset($_GET['class']) ? sanitize_input($_GET['class']) : '';
$stream = isset($_GET['stream']) ? sanitize_input($_GET['stream']) : '';

// Basic validation
$errors = [];
if (empty($student_id)) $errors[] = "Student ID is required";
if (empty($term)) $errors[] = "Term is required";
if (empty($year)) $errors[] = "Year is required";

if (!empty($errors)) {
    display_error_page("Invalid Request", $errors);
    exit;
}

// Get school profile
$school_info = null;
$school_query = "SELECT * FROM school_profile LIMIT 1";
$school_result = mysqli_query($conn, $school_query);
if ($school_result) {
    $school_info = mysqli_fetch_assoc($school_result);
}

// Get student information
$student_sql = "SELECT student_id, first_name, last_name, current_class, stream, 
                date_of_birth, gender, profile_photo 
                FROM students 
                WHERE student_id = ?";

$stmt = mysqli_prepare($conn, $student_sql);
if (!$stmt) {
    display_error_page("Database Error", ["Unable to prepare query"]);
    exit;
}

mysqli_stmt_bind_param($stmt, "s", $student_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$student = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$student) {
    display_not_found_page($student_id, $term, $year);
    exit;
}

// Check for marks
$term_number = filter_var($term, FILTER_SANITIZE_NUMBER_INT);
$romans = ['i', 'ii', 'iii'];
$term_roman = isset($romans[$term_number - 1]) ? $romans[$term_number - 1] : 'i';
$table_name = "{$year}_{$term_roman}_olevel";

$has_marks = false;
$marks_count = 0;

// Check if table exists
$table_check = "SHOW TABLES LIKE '$table_name'";
$table_result = mysqli_query($conn, $table_check);
$table_exists = $table_result && mysqli_num_rows($table_result) > 0;

if ($table_exists) {
    $marks_sql = "SELECT COUNT(*) as count FROM `$table_name` 
                  WHERE student_id = ? AND class = ?";
    $marks_stmt = mysqli_prepare($conn, $marks_sql);

    if ($marks_stmt) {
        mysqli_stmt_bind_param($marks_stmt, "ss", $student_id, $class);
        mysqli_stmt_execute($marks_stmt);
        $marks_result = mysqli_stmt_get_result($marks_stmt);
        $marks_row = mysqli_fetch_assoc($marks_result);
        $marks_count = $marks_row['count'];
        $has_marks = $marks_count > 0;
        mysqli_stmt_close($marks_stmt);
    }
}

// Display the verification page
display_verification_page($student, $term, $year, $class, $stream, $has_marks, $marks_count, $school_info);

mysqli_close($conn);

// ==================== DISPLAY FUNCTIONS ====================

function display_verification_page($student, $term, $year, $class, $stream, $has_marks, $marks_count, $school_info)
{
    $student_name = htmlspecialchars($student['first_name'] . ' ' . $student['last_name']);
    $student_id = htmlspecialchars($student['student_id']);
    $current_class = htmlspecialchars($student['current_class']);
    $current_stream = htmlspecialchars($student['stream']);
    $gender = htmlspecialchars($student['gender'] ?? 'Not specified');
    $dob = isset($student['date_of_birth']) && $student['date_of_birth'] ? date('M d, Y', strtotime($student['date_of_birth'])) : 'Not specified';
    $photo = !empty($student['profile_photo']) ? htmlspecialchars($student['profile_photo']) : 'images/avatar.png';

    $school_name = isset($school_info['school_name']) ? htmlspecialchars($school_info['school_name']) : 'School Name';
    $school_logo = isset($school_info['logo_path']) ? htmlspecialchars($school_info['logo_path']) : 'assets/img/logo.jpg';

    $verification_date = date('l, F j, Y \a\t g:i A');
    $verification_id = 'VRF-' . strtoupper(substr(md5($student_id . time()), 0, 10));
    
    // Generate verification token
    $verification_token = md5($student_id . $term . $year . 'OU_SECRET_KEY');
?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Report Verification - <?php echo $student_name; ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            :root {
                --primary-color: #2e7d32;
                --primary-light: #4caf50;
                --primary-dark: #1b5e20;
                --secondary-color: #689f38;
                --success-color: #4caf50;
                --danger-color: #f44336;
                --warning-color: #ff9800;
                --text-dark: #1a1a1a;
                --text-medium: #555555;
                --text-light: #777777;
                --border-color: #e0e0e0;
                --bg-light: #f8f9fa;
                --white: #ffffff;
                --shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.08);
                --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.12);
                --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.15);
            }

            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: "Sen", sans-serif;
                min-height: 100vh;
                background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                padding: 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: var(--text-dark);
                line-height: 1.6;
            }

            .verification-container {
                max-width: 950px;
                width: 100%;
                background: var(--white);
                border-radius: 16px;
                box-shadow: var(--shadow-lg);
                overflow: hidden;
                animation: slideUp 0.6s ease;
            }

            @keyframes slideUp {
                from {
                    opacity: 0;
                    transform: translateY(30px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            /* Header Section */
            .header {
                background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
                color: var(--white);
                padding: 40px 30px;
                text-align: center;
                position: relative;
                overflow: hidden;
            }

            .header::before {
                content: '';
                position: absolute;
                top: -50%;
                right: -50%;
                width: 200%;
                height: 200%;
                background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
                animation: pulse 3s ease-in-out infinite;
            }

            @keyframes pulse {
                0%, 100% { transform: scale(1); opacity: 0.5; }
                50% { transform: scale(1.1); opacity: 0.8; }
            }

            .header-content {
                position: relative;
                z-index: 1;
            }

            .school-logo-container {
                width: 90px;
                height: 90px;
                background: var(--white);
                padding: 12px;
                border-radius: 50%;
                margin: 0 auto 20px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .school-logo {
                width: 100%;
                height: 100%;
                object-fit: contain;
            }

            .school-name {
                font-size: 26px;
                font-weight: 700;
                margin-bottom: 15px;
                text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            }

            .verification-badge {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                background: rgba(255, 255, 255, 0.2);
                backdrop-filter: blur(10px);
                padding: 12px 24px;
                border-radius: 50px;
                margin-top: 10px;
                font-size: 14px;
                font-weight: 600;
                border: 2px solid rgba(255, 255, 255, 0.3);
            }

            .verification-badge i {
                font-size: 20px;
            }

            /* Content Section */
            .content {
                padding: 40px 30px;
            }

            /* Status Banner */
            .status-banner {
                display: flex;
                align-items: flex-start;
                gap: 20px;
                padding: 24px;
                border-radius: 12px;
                margin-bottom: 30px;
                border: 2px solid;
                animation: fadeIn 0.8s ease 0.3s both;
            }

            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(10px); }
                to { opacity: 1; transform: translateY(0); }
            }

            .status-banner.success {
                background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 50%);
                border-color: var(--success-color);
            }

            .status-banner.warning {
                background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 50%);
                border-color: var(--warning-color);
            }

            .status-icon-container {
                width: 50px;
                height: 50px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }

            .status-banner.success .status-icon-container {
                background: var(--success-color);
                color: var(--white);
            }

            .status-banner.warning .status-icon-container {
                background: var(--warning-color);
                color: var(--white);
            }

            .status-icon-container i {
                font-size: 24px;
            }

            .status-content h3 {
                font-size: 18px;
                font-weight: 700;
                margin-bottom: 5px;
                color: var(--text-dark);
            }

            .status-content p {
                font-size: 14px;
                color: var(--text-medium);
                line-height: 1.5;
            }

            /* Student Card */
            .student-card {
                background: var(--bg-light);
                border-radius: 12px;
                padding: 30px;
                margin-bottom: 30px;
                border: 1px solid var(--border-color);
                animation: fadeIn 0.8s ease 0.4s both;
            }

            .student-header {
                display: flex;
                align-items: center;
                gap: 25px;
                margin-bottom: 25px;
                padding-bottom: 25px;
                border-bottom: 2px solid var(--border-color);
            }

            .student-photo-container {
                position: relative;
            }

            .student-photo {
                width: 110px;
                height: 110px;
                border-radius: 50%;
                object-fit: cover;
                border: 4px solid var(--primary-color);
                box-shadow: var(--shadow-md);
            }

            .verification-icon {
                position: absolute;
                bottom: 5px;
                right: 5px;
                width: 28px;
                height: 28px;
                background: var(--success-color);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                border: 3px solid var(--white);
            }

            .verification-icon i {
                font-size: 12px;
                color: var(--white);
            }

            .student-basic-info h2 {
                font-size: 26px;
                color: var(--text-dark);
                margin-bottom: 8px;
                font-weight: 700;
            }

            .student-id-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                background: var(--white);
                padding: 6px 12px;
                border-radius: 6px;
                font-size: 13px;
                font-weight: 600;
                color: var(--primary-color);
                border: 1px solid var(--primary-light);
            }

            .student-id-badge i {
                font-size: 12px;
            }

            .info-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
            }

            .info-item {
                background: var(--white);
                padding: 16px;
                border-radius: 10px;
                border-left: 4px solid var(--primary-color);
                box-shadow: var(--shadow-sm);
                transition: transform 0.3s ease, box-shadow 0.3s ease;
            }

            .info-item:hover {
                transform: translateY(-2px);
                box-shadow: var(--shadow-md);
            }

            .info-label {
                font-size: 11px;
                color: var(--primary-dark);
                font-weight: 700;
                text-transform: uppercase;
                margin-bottom: 6px;
                letter-spacing: 0.5px;
                display: flex;
                align-items: center;
                gap: 6px;
            }

            .info-label i {
                font-size: 10px;
            }

            .info-value {
                font-size: 16px;
                color: var(--text-dark);
                font-weight: 600;
            }

            /* Report Details */
            .report-details {
                background: linear-gradient(135deg, #e8f5e9 0%, #f1f8e9 100%);
                border-radius: 12px;
                padding: 30px;
                margin-bottom: 30px;
                border: 1px solid var(--primary-light);
                animation: fadeIn 0.8s ease 0.5s both;
            }

            .report-details h3 {
                color: var(--primary-dark);
                font-size: 20px;
                margin-bottom: 20px;
                font-weight: 700;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .report-details h3 i {
                color: var(--primary-color);
            }

            .detail-row {
                display: flex;
                padding: 14px 0;
                border-bottom: 1px solid rgba(0, 0, 0, 0.08);
                transition: background 0.3s ease;
            }

            .detail-row:hover {
                background: rgba(255, 255, 255, 0.5);
                padding-left: 10px;
                margin-left: -10px;
                border-radius: 6px;
            }

            .detail-row:last-child {
                border-bottom: none;
            }

            .detail-label {
                font-weight: 700;
                color: var(--primary-dark);
                min-width: 180px;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .detail-label i {
                font-size: 14px;
                color: var(--primary-color);
            }

            .detail-value {
                color: var(--text-dark);
                font-weight: 600;
            }

            /* Action Buttons */
            .action-buttons {
                display: flex;
                gap: 15px;
                justify-content: center;
                flex-wrap: wrap;
                animation: fadeIn 0.8s ease 0.6s both;
            }

            .btn {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                padding: 14px 28px;
                border: none;
                border-radius: 8px;
                font-size: 15px;
                font-weight: 600;
                cursor: pointer;
                text-decoration: none;
                transition: all 0.3s ease;
                font-family: "Sen", sans-serif;
                box-shadow: var(--shadow-sm);
            }

            .btn-primary {
                background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
                color: var(--white);
            }

            .btn-primary:hover {
                transform: translateY(-2px);
                box-shadow: var(--shadow-md);
                background: linear-gradient(135deg, var(--primary-dark) 0%, #0d3d10 100%);
            }

            .btn-secondary {
                background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
                color: var(--white);
            }

            .btn-secondary:hover {
                transform: translateY(-2px);
                box-shadow: var(--shadow-md);
                background: linear-gradient(135deg, #5a6268 0%, #4a5055 100%);
            }

            .btn i {
                font-size: 16px;
            }

            /* Footer */
            .footer {
                background: var(--bg-light);
                padding: 25px;
                text-align: center;
                border-top: 2px solid var(--border-color);
            }

            .footer-content {
                display: flex;
                flex-direction: column;
                gap: 10px;
                color: var(--text-medium);
                font-size: 13px;
            }

            .footer-content strong {
                color: var(--primary-color);
                font-weight: 700;
            }

            .footer-icon {
                display: inline-flex;
                align-items: center;
                gap: 6px;
            }

            .footer-icon i {
                color: var(--primary-color);
            }

            /* Responsive Design */
            @media (max-width: 768px) {
                body {
                    padding: 10px;
                }

                .header {
                    padding: 30px 20px;
                }

                .school-name {
                    font-size: 22px;
                }

                .content {
                    padding: 30px 20px;
                }

                .student-header {
                    flex-direction: column;
                    text-align: center;
                }

                .student-basic-info h2 {
                    font-size: 22px;
                }

                .info-grid {
                    grid-template-columns: 1fr;
                }

                .detail-label {
                    min-width: 140px;
                    font-size: 13px;
                }

                .detail-value {
                    font-size: 14px;
                }

                .action-buttons {
                    flex-direction: column;
                    width: 100%;
                }

                .btn {
                    width: 100%;
                    justify-content: center;
                }
            }

            /* Print Styles */
            @media print {
                body {
                    background: var(--white);
                    padding: 0;
                    margin: 0;
                }

                .verification-container {
                    box-shadow: none;
                    border-radius: 0;
                    max-width: 100%;
                }

                .header::before {
                    display: none;
                }

                .action-buttons {
                    display: none !important;
                }

                .status-banner,
                .student-card,
                .report-details {
                    page-break-inside: avoid;
                }

                .verification-badge {
                    background: rgba(46, 125, 50, 0.2);
                }

                .student-photo {
                    border: 3px solid var(--primary-color);
                }

                .info-item,
                .detail-row {
                    box-shadow: none;
                }

                /* Add watermark for printed version */
                .footer::after {
                    content: 'OFFICIAL VERIFICATION DOCUMENT';
                    position: fixed;
                    bottom: 20px;
                    left: 50%;
                    transform: translateX(-50%);
                    font-size: 10px;
                    color: #999;
                    font-weight: 600;
                }
            }

            /* Print Loading Overlay */
            .print-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(255, 255, 255, 0.95);
                backdrop-filter: blur(5px);
                display: none;
                justify-content: center;
                align-items: center;
                z-index: 9999;
            }

            .print-overlay.active {
                display: flex;
            }

            .print-message {
                text-align: center;
                color: var(--primary-color);
            }

            .print-spinner {
                width: 50px;
                height: 50px;
                border: 4px solid #e0e0e0;
                border-top: 4px solid var(--primary-color);
                border-radius: 50%;
                animation: spin 1s linear infinite;
                margin: 0 auto 20px;
            }

            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }

            .print-message h3 {
                font-size: 18px;
                font-weight: 700;
                margin-bottom: 8px;
            }

            .print-message p {
                font-size: 14px;
                color: var(--text-medium);
            }
        </style>
    </head>
    <body>
        <!-- Print Loading Overlay -->
        <div class="print-overlay" id="printOverlay">
            <div class="print-message">
                <div class="print-spinner"></div>
                <h3>Preparing Document...</h3>
                <p>Please wait while we prepare your verification document for printing</p>
            </div>
        </div>

        <div class="verification-container">
            <div class="header">
                <div class="header-content">
                    <div class="school-logo-container">
                        <img src="<?php echo $school_logo; ?>" alt="School Logo" class="school-logo">
                    </div>
                    <div class="school-name"><?php echo $school_name; ?></div>
                    <div class="verification-badge">
                        <i class="fas fa-shield-check"></i>
                        <span>Report Verification System</span>
                    </div>
                </div>
            </div>

            <div class="content">
                <?php if ($has_marks): ?>
                    <div class="status-banner success">
                        <div class="status-icon-container">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="status-content">
                            <h3>Authentic Report Card Verified</h3>
                            <p>This report card has been successfully verified against official school records and is confirmed to be authentic.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="status-banner warning">
                        <div class="status-icon-container">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="status-content">
                            <h3>Student Record Found - No Marks Available</h3>
                            <p>The student record exists in our system, but no marks have been recorded for this academic term.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="student-card">
                    <div class="student-header">
                        <div class="student-photo-container">
                            <img src="<?php echo $photo; ?>" alt="Student Photo" class="student-photo" onerror="this.src='images/avatar.png'">
                            <div class="verification-icon">
                                <i class="fas fa-check"></i>
                            </div>
                        </div>
                        <div class="student-basic-info">
                            <h2><?php echo $student_name; ?></h2>
                            <div class="student-id-badge">
                                <i class="fas fa-id-card"></i>
                                <span><?php echo $student_id; ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">
                                <i class="fas fa-graduation-cap"></i>
                                Current Class
                            </div>
                            <div class="info-value"><?php echo $current_class; ?> (<?php echo $current_stream; ?>)</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">
                                <i class="fas fa-venus-mars"></i>
                                Gender
                            </div>
                            <div class="info-value"><?php echo $gender; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">
                                <i class="fas fa-calendar-alt"></i>
                                Date of Birth
                            </div>
                            <div class="info-value"><?php echo $dob; ?></div>
                        </div>
                    </div>
                </div>

                <div class="report-details">
                    <h3><i class="fas fa-file-alt"></i> Report Card Details</h3>
                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-calendar-check"></i>
                            Academic Term:
                        </div>
                        <div class="detail-value"><?php echo htmlspecialchars($term); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-calendar"></i>
                            Academic Year:
                        </div>
                        <div class="detail-value"><?php echo htmlspecialchars($year); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">
                            <i class="fas fa-school"></i>
                            Class/Stream:
                        </div>
                        <div class="detail-value"><?php echo htmlspecialchars($class); ?> (<?php echo htmlspecialchars($stream); ?>)</div>
                    </div>
                    <?php if ($has_marks): ?>
                        <div class="detail-row">
                            <div class="detail-label">
                                <i class="fas fa-book-open"></i>
                                Subjects Recorded:
                            </div>
                            <div class="detail-value"><?php echo $marks_count; ?> entries found</div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($has_marks): ?>
                    <div class="action-buttons">
                        <a href="summarized_report.php?student_id=<?php echo urlencode($student_id); ?>&class=<?php echo urlencode($class); ?>&stream=<?php echo urlencode($stream); ?>&term=<?php echo urlencode($term); ?>&year=<?php echo urlencode($year); ?>&verified=1&verification_token=<?php echo $verification_token; ?>"
                            class="btn btn-primary" target="_blank">
                            <i class="fas fa-file-pdf"></i>
                            View Full Report
                        </a>
                        <!--<button onclick="window.print()" class="btn btn-secondary" id="printBtn">
                            <i class="fas fa-print"></i>
                            Print Verification
                        </button>-->
                    </div>
                <?php endif; ?>
            </div>

            <div class="footer">
                <div class="footer-content">
                    <div class="footer-icon">
                        <i class="fas fa-clock"></i>
                        <span>Verified on: <?php echo $verification_date; ?></span>
                    </div>
                    <div class="footer-icon">
                        <i class="fas fa-fingerprint"></i>
                        <span>Verification ID: <strong><?php echo $verification_id; ?></strong></span>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Enhanced Print Functionality
            document.getElementById('printBtn').addEventListener('click', function(e) {
                e.preventDefault();
                
                // Show loading overlay
                const printOverlay = document.getElementById('printOverlay');
                printOverlay.classList.add('active');
                
                // Small delay to show the overlay and let the page stabilize
                setTimeout(function() {
                    // Trigger print dialog
                    window.print();
                    
                    // Hide overlay after print dialog opens
                    setTimeout(function() {
                        printOverlay.classList.remove('active');
                    }, 500);
                }, 800);
            });

            // Listen for print events
            window.addEventListener('beforeprint', function() {
                console.log('Preparing to print...');
                // You can add any pre-print adjustments here
            });

            window.addEventListener('afterprint', function() {
                console.log('Print completed or cancelled');
                // Clean up after printing
                document.getElementById('printOverlay').classList.remove('active');
            });

            // Keyboard shortcut for printing (Ctrl+P or Cmd+P)
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                    e.preventDefault();
                    document.getElementById('printBtn').click();
                }
            });
        </script>
    </body>
    </html>
<?php
}

function display_not_found_page($student_id, $term, $year)
{
?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <title>Verification Failed</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: "Sen", sans-serif;
                min-height: 100vh;
                background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .error-container {
                background: white;
                border-radius: 16px;
                max-width: 600px;
                width: 100%;
                overflow: hidden;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
                animation: slideUp 0.6s ease;
            }

            @keyframes slideUp {
                from {
                    opacity: 0;
                    transform: translateY(30px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .error-header {
                background: linear-gradient(135deg, #c62828 0%, #b71c1c 100%);
                color: white;
                padding: 40px;
                text-align: center;
            }

            .error-icon {
                width: 80px;
                height: 80px;
                background: rgba(255, 255, 255, 0.2);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 20px;
                border: 3px solid rgba(255, 255, 255, 0.3);
            }

            .error-icon i {
                font-size: 40px;
            }

            .error-header h1 {
                font-size: 28px;
                font-weight: 700;
                margin-bottom: 8px;
            }

            .error-content {
                padding: 40px;
                text-align: center;
            }

            .error-content > p {
                font-size: 16px;
                color: #555;
                margin-bottom: 25px;
            }

            .error-box {
                background: #ffebee;
                border-left: 4px solid #c62828;
                padding: 25px;
                border-radius: 8px;
                text-align: left;
                margin: 20px 0;
            }

            .error-box h3 {
                color: #c62828;
                margin-bottom: 15px;
                font-weight: 700;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .error-box ul {
                list-style: none;
                padding: 0;
            }

            .error-box li {
                color: #721c24;
                padding: 10px 0;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .error-box li i {
                color: #c62828;
            }

            .help-text {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 8px;
                font-size: 14px;
                color: #666;
                margin-top: 20px;
                border: 1px solid #e0e0e0;
            }

            .help-text strong {
                color: #2e7d32;
                font-weight: 700;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-header">
                <div class="error-icon">
                    <i class="fas fa-times-circle"></i>
                </div>
                <h1>Verification Failed</h1>
                <p>Report card could not be verified</p>
            </div>
            <div class="error-content">
                <p>We were unable to verify the authenticity of this report card.</p>
                <div class="error-box">
                    <h3><i class="fas fa-exclamation-circle"></i> Possible Reasons:</h3>
                    <ul>
                        <li><i class="fas fa-circle"></i> Student ID does not exist in the system</li>
                        <li><i class="fas fa-circle"></i> Report card information may have been tampered with</li>
                        <li><i class="fas fa-circle"></i> QR code is invalid or corrupted</li>
                    </ul>
                </div>
                <div class="help-text">
                    <strong>Verification Details:</strong><br>
                    Student ID: <?php echo htmlspecialchars($student_id); ?><br>
                    Term: <?php echo htmlspecialchars($term); ?> <?php echo htmlspecialchars($year); ?>
                </div>
            </div>
        </div>
    </body>
    </html>
<?php
}

function display_error_page($title, $errors)
{
?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($title); ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: "Sen", sans-serif;
                min-height: 100vh;
                background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .error-container {
                background: white;
                border-radius: 16px;
                max-width: 600px;
                width: 100%;
                overflow: hidden;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            }

            .error-header {
                background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
                color: white;
                padding: 40px;
                text-align: center;
            }

            .error-icon {
                width: 80px;
                height: 80px;
                background: rgba(255, 255, 255, 0.2);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 20px;
            }

            .error-icon i {
                font-size: 40px;
            }

            .error-content {
                padding: 40px;
            }

            .error-list {
                background: #fff3e0;
                border-left: 4px solid #ff9800;
                padding: 25px;
                border-radius: 8px;
            }

            .error-list h3 {
                color: #e65100;
                margin-bottom: 15px;
                font-weight: 700;
            }

            .error-list ul {
                list-style: none;
                padding: 0;
            }

            .error-list li {
                color: #e65100;
                padding: 10px 0;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .error-list li i {
                color: #ff9800;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-header">
                <div class="error-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h1><?php echo htmlspecialchars($title); ?></h1>
            </div>
            <div class="error-content">
                <div class="error-list">
                    <h3>Validation Errors:</h3>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><i class="fas fa-circle"></i> <?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </body>
    </html>
<?php
}
?>