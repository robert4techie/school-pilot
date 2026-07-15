<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (headers_sent($filename, $linenum)) {
    error_log("Headers already sent in $filename on line $linenum");
    echo json_encode(['success' => false, 'message' => 'Server configuration error']);
    exit;
}

ob_start();

require_once "../auth.php";
require_once "../conn.php";

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

// Resolve the project root absolutely (e.g. C:/xampp/htdocs/schoolpilot/)
// Works no matter which subfolder this script lives in.
$projectRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . DIRECTORY_SEPARATOR
             . trim(explode('/', ltrim($_SERVER['SCRIPT_NAME'], '/'))[0], '/') . DIRECTORY_SEPARATOR;

try {
    if (empty($_POST['school_id'])) {
        throw new Exception('School ID is required');
    }

    $required = ['school_name', 'school_motto', 'address', 'phone', 'email', 'website', 'next_term_date', 'next_term_ends'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("$field is required");
        }
    }

    // Get current logo path from DB
    $currentLogo = null;
    $stmt = $conn->prepare("SELECT logo_path FROM school_profile WHERE id = ?");
    if (!$stmt) {
        throw new Exception('Database error: ' . $conn->error);
    }
    $stmt->bind_param("i", $_POST['school_id']);
    $stmt->execute();
    $stmt->bind_result($currentLogo);
    $stmt->fetch();
    $stmt->close();

    // Default: keep the existing logo
    $logoPath   = $currentLogo;
    $targetPath = null;

    // Only run upload logic if a new file was actually chosen
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
        $finfo        = finfo_open(FILEINFO_MIME_TYPE);
        $fileType     = finfo_file($finfo, $_FILES['logo']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($fileType, $allowedTypes)) {
            throw new Exception('Only JPG, PNG, GIF and WebP images are allowed');
        }

        if (move_uploaded_file($_FILES['logo']['tmp_name'], $targetPath)) {
            // Delete the old logo file from disk if it exists
            if ($currentLogo) {
                $oldFile = $projectRoot . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $currentLogo), DIRECTORY_SEPARATOR);
                if (file_exists($oldFile) && strpos($oldFile, 'default') === false) {
                    unlink($oldFile);
                }
            }
            // Store clean relative path in DB
            $logoPath = 'uploads/school_logos/' . $fileName;
        } else {
            throw new Exception('Failed to upload logo.');
        }
    }

    $school_id    = $_POST['school_id'];
    $school_name  = $_POST['school_name'];
    $school_motto = $_POST['school_motto'];
    $address      = $_POST['address'];
    $phone        = $_POST['phone'];
    $email        = $_POST['email'];
    $website      = $_POST['website'];
    $pobox        = !empty($_POST['pobox']) ? $_POST['pobox'] : null;
    $next_term_date = $_POST['next_term_date'];
    $next_term_ends = $_POST['next_term_ends'];

    $stmt = $conn->prepare("UPDATE school_profile SET 
        school_name = ?, 
        school_motto = ?, 
        address = ?, 
        phone = ?, 
        email = ?, 
        website = ?, 
        pobox = ?, 
        next_term_date = ?, 
        next_term_ends = ?, 
        logo_path = ?
    WHERE id = ?");

    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param(
        "ssssssssssi",
        $school_name,
        $school_motto,
        $address,
        $phone,
        $email,
        $website,
        $pobox,
        $next_term_date,
        $next_term_ends,
        $logoPath,
        $school_id
    );

    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'School profile updated successfully';
    } else {
        throw new Exception('Failed to update school profile: ' . $stmt->error);
    }

    $stmt->close();
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    if (isset($targetPath) && $targetPath && file_exists($targetPath)) {
        unlink($targetPath);
    }
}

ob_end_clean();
echo json_encode($response);
$conn->close();