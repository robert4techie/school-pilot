<?php
// Include database connection
require_once 'conn.php';

// Initialize response array
$response = [
    'status' => 'error',
    'message' => '',
    'errors' => []
];

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Validate notice title
    if (empty($_POST['noticeTitle'])) {
        $response['errors'][] = 'Notice title is required';
    } elseif (strlen($_POST['noticeTitle']) > 255) {
        $response['errors'][] = 'Notice title must be less than 255 characters';
    }
    
    // Validate notice content
    if (empty($_POST['noticeContent'])) {
        $response['errors'][] = 'Notice content is required';
    }
    
    // Validate priority
    $validPriorities = ['low', 'medium', 'high'];
    if (!in_array($_POST['noticePriority'], $validPriorities)) {
        $response['errors'][] = 'Invalid priority level';
    }
    
    // Validate publish date
    if (empty($_POST['noticeDate'])) {
        $response['errors'][] = 'Publish date is required';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['noticeDate'])) {
        $response['errors'][] = 'Invalid publish date format';
    }
    
    // Validate expiry date if provided
    if (!empty($_POST['expiryDate'])) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['expiryDate'])) {
            $response['errors'][] = 'Invalid expiry date format';
        } elseif ($_POST['expiryDate'] < $_POST['noticeDate']) {
            $response['errors'][] = 'Expiry date cannot be before publish date';
        }
    }
    
    // Validate at least one audience is selected
    $audienceSelected = false;
    
    if (isset($_POST['audienceAll']) && $_POST['audienceAll'] === 'true') {
        $audienceSelected = true;
    } else {
        $audienceGroups = ['audienceStudents', 'audienceTeachers', 'audienceParents', 'audienceStaff', 'audienceManagement'];
        foreach ($audienceGroups as $group) {
            if (isset($_POST[$group]) && $_POST[$group] === 'true') {
                $audienceSelected = true;
                break;
            }
        }
        
        // Check for selected classes
        if (isset($_POST['audienceClasses']) && is_array($_POST['audienceClasses']) && count($_POST['audienceClasses']) > 0) {
            $audienceSelected = true;
        }
    }
    
    if (!$audienceSelected) {
        $response['errors'][] = 'Please select at least one recipient group';
    }
    
    // If there are no validation errors, proceed to save the notice
    if (empty($response['errors'])) {
        
        // Sanitize inputs
        $noticeTitle = mysqli_real_escape_string($conn, $_POST['noticeTitle']);
        $noticeContent = mysqli_real_escape_string($conn, $_POST['noticeContent']);
        $noticePriority = mysqli_real_escape_string($conn, $_POST['noticePriority']);
        $noticeDate = mysqli_real_escape_string($conn, $_POST['noticeDate']);
        $noticeCreator = mysqli_real_escape_string($conn, $_POST['noticeCreator']);
        $expiryDate = !empty($_POST['expiryDate']) ? "'" . mysqli_real_escape_string($conn, $_POST['expiryDate']) . "'" : "NULL";
        
        // Start transaction
        mysqli_autocommit($conn, FALSE);
        $success = true;
        
        // Insert notice
        $sql = "INSERT INTO notices (title, content, noticeCreator, priority, publish_date, expiry_date) 
                VALUES ('$noticeTitle', '$noticeContent', '$noticeCreator', '$noticePriority', '$noticeDate', $expiryDate)";
        
        if (mysqli_query($conn, $sql)) {
            $noticeId = mysqli_insert_id($conn);
            
            // Insert recipients
            if (isset($_POST['audienceAll']) && $_POST['audienceAll'] === 'true') {
                $sql = "INSERT INTO notice_recipients (notice_id, recipient_type) VALUES ($noticeId, 'all')";
                if (!mysqli_query($conn, $sql)) {
                    $success = false;
                    $response['errors'][] = 'Error saving recipient information: ' . mysqli_error($conn);
                }
            } else {
                // Insert specific recipient groups
                $audienceGroups = [
                    'audienceStudents' => 'students',
                    'audienceTeachers' => 'teachers',
                    'audienceParents' => 'parents',
                    'audienceStaff' => 'staff',
                    'audienceManagement' => 'management'
                ];
                
                foreach ($audienceGroups as $formField => $recipientType) {
                    if (isset($_POST[$formField]) && $_POST[$formField] === 'true') {
                        $sql = "INSERT INTO notice_recipients (notice_id, recipient_type) VALUES ($noticeId, '$recipientType')";
                        if (!mysqli_query($conn, $sql)) {
                            $success = false;
                            $response['errors'][] = 'Error saving recipient information: ' . mysqli_error($conn);
                            break;
                        }
                    }
                }
                
                // Insert specific classes
                if (isset($_POST['audienceClasses']) && is_array($_POST['audienceClasses'])) {
                    foreach ($_POST['audienceClasses'] as $class) {
                        $className = mysqli_real_escape_string($conn, $class);
                        $sql = "INSERT INTO notice_recipients (notice_id, recipient_type, class_name) VALUES ($noticeId, 'class', '$className')";
                        if (!mysqli_query($conn, $sql)) {
                            $success = false;
                            $response['errors'][] = 'Error saving class information: ' . mysqli_error($conn);
                            break;
                        }
                    }
                }
            }
            
            // Process file uploads if any
            if ($success && isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
                $uploadDir = 'uploads/notices/';
                
                // Create directory if it doesn't exist
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
                    if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                        $fileName = basename($_FILES['attachments']['name'][$i]);
                        $fileSize = $_FILES['attachments']['size'][$i];
                        $fileType = $_FILES['attachments']['type'][$i];
                        
                        // Generate unique filename
                        $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
                        $uniqueName = uniqid() . '_' . time() . '.' . $fileExt;
                        $targetFilePath = $uploadDir . $uniqueName;
                        
                        // Check file size (5MB limit)
                        if ($fileSize > 5 * 1024 * 1024) {
                            $success = false;
                            $response['errors'][] = 'File ' . $fileName . ' exceeds 5MB size limit';
                            continue;
                        }
                        
                        // Move uploaded file
                        if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $targetFilePath)) {
                            // Insert file info into database
                            $fileNameSafe = mysqli_real_escape_string($conn, $fileName);
                            $filePathSafe = mysqli_real_escape_string($conn, $targetFilePath);
                            $fileTypeSafe = mysqli_real_escape_string($conn, $fileType);
                            
                            $sql = "INSERT INTO notice_attachments (notice_id, file_name, file_path, file_size, file_type) 
                                    VALUES ($noticeId, '$fileNameSafe', '$filePathSafe', $fileSize, '$fileTypeSafe')";
                            
                            if (!mysqli_query($conn, $sql)) {
                                $success = false;
                                $response['errors'][] = 'Error saving attachment information: ' . mysqli_error($conn);
                                break;
                            }
                        } else {
                            $success = false;
                            $response['errors'][] = 'Failed to upload file: ' . $fileName;
                        }
                    } elseif ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                        $success = false;
                        $response['errors'][] = 'File upload error: ' . $_FILES['attachments']['error'][$i];
                    }
                }
            }
            
            // Commit or rollback based on success
            if ($success) {
                mysqli_commit($conn);
                $response['status'] = 'success';
                $response['message'] = 'Notice published successfully!';
            } else {
                mysqli_rollback($conn);
            }
        } else {
            $response['errors'][] = 'Error saving notice: ' . mysqli_error($conn);
            mysqli_rollback($conn);
        }
        
        // Reset autocommit
        mysqli_autocommit($conn, TRUE);
    }
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>