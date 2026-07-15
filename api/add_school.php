<?php
// Ensure no output has been sent yet
if (headers_sent()) {
    die("");
}

require_once "../auth.php";
require_once "../conn.php";
require_once 'tracking.php';
$tracker->trackAction("Add School Profile");

// Clear any previous output
ob_clean();

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

// Resolve the project root absolutely (e.g. C:/xampp/htdocs/schoolpilot/)
// Works no matter which subfolder this script lives in.
$projectRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . DIRECTORY_SEPARATOR
             . trim(explode('/', ltrim($_SERVER['SCRIPT_NAME'], '/'))[0], '/') . DIRECTORY_SEPARATOR;

try {
    // Validate required fields
    $required = ['school_name', 'school_motto', 'address', 'phone', 'email', 'website', 'next_term_date', 'next_term_ends'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("$field is required");
        }
    }

    // Handle file upload
    $logoPath = null;
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        // Always save into the project root uploads folder
        $uploadDir = $projectRoot . 'uploads' . DIRECTORY_SEPARATOR . 'school_logos' . DIRECTORY_SEPARATOR;
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileExt    = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        $fileName   = uniqid('school_logo_') . '.' . $fileExt;
        $targetPath = $uploadDir . $fileName;

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $fileType     = mime_content_type($_FILES['logo']['tmp_name']);

        if (!in_array($fileType, $allowedTypes)) {
            throw new Exception('Only JPG, PNG, GIF and WebP images are allowed');
        }

        if (move_uploaded_file($_FILES['logo']['tmp_name'], $targetPath)) {
            // Store clean relative path in DB (no leading slash, forward slashes)
            $logoPath = 'uploads/school_logos/' . $fileName;
        } else {
            throw new Exception('Failed to upload logo');
        }
    }

    $pobox        = !empty($_POST['pobox']) ? $_POST['pobox'] : null;
    $nextTermDate = $_POST['next_term_date'];
    $nextTermEnds = $_POST['next_term_ends'];

    $stmt = $conn->prepare("INSERT INTO school_profile 
    (school_name, school_motto, address, phone, email, website, pobox, next_term_date, next_term_ends, logo_path) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->bind_param(
        "ssssssssss",
        $_POST['school_name'],
        $_POST['school_motto'],
        $_POST['address'],
        $_POST['phone'],
        $_POST['email'],
        $_POST['website'],
        $pobox,
        $nextTermDate,
        $nextTermEnds,
        $logoPath
    );

    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'School profile created successfully';
    } else {
        throw new Exception('Failed to create school profile: ' . $stmt->error);
    }

    $stmt->close();
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    if (isset($targetPath) && file_exists($targetPath)) {
        unlink($targetPath);
    }
}

echo json_encode($response);
$conn->close();