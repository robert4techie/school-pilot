<?php
require_once '../auth.php';
require_once '../conn.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

// ── Role guard ────────────────────────────────────────────────────────────────
$allowed = ['developer', 'super user','school leader'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorised access.']);
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function validateCsrf(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/** Return null for blank strings, otherwise return value as-is. */
function n(?string $v): ?string {
    return ($v !== null && trim($v) !== '') ? $v : null;
}

/**
 * Handle profile-photo upload. Deletes old file on success.
 * Returns the full URL of the saved photo, or $existing if no new file.
 */
function handlePhoto(int $staffId, string $existing = ''): string {
    if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
        return $existing;
    }

    $file    = $_FILES['profile_photo'];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);

    if (!isset($allowed[$mime]))           throw new Exception('Invalid file type. JPEG, PNG or WebP only.');
    if ($file['size'] > 2 * 1024 * 1024)  throw new Exception('Photo must be 2 MB or smaller.');

    $dir = __DIR__ . '/uploads/staff_photos/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new Exception('Cannot create upload directory.');

    $filename = 'staff_' . $staffId . '_' . uniqid() . '.' . $allowed[$mime];
    $target   = $dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) throw new Exception('Failed to save uploaded file.');

    // Delete the old photo file if it belongs to our uploads folder
    if ($existing !== '') {
        if (preg_match('#uploads/staff_photos/[^/?#]+$#', $existing, $m)) {
            $old = __DIR__ . '/' . $m[0];
            if (is_file($old)) @unlink($old);
        }
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base  = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    return $https . '://' . $_SERVER['HTTP_HOST'] . $base . '/uploads/staff_photos/' . $filename;
}

// ── Parse action & CSRF ───────────────────────────────────────────────────────
try {
    $staff = [];

    if (isset($_POST['action']) && $_POST['action'] === 'update') {
        $action    = 'update';
        $csrfToken = $_POST['csrf_token'] ?? '';
        $staff     = json_decode($_POST['staff'] ?? '{}', true) ?: [];
    } else {
        $raw   = file_get_contents('php://input');
        $input = json_decode($raw, true);
        if (!is_array($input)) throw new Exception('Invalid request body.');
        $action    = $input['action']     ?? '';
        $csrfToken = $input['csrf_token'] ?? '';
    }

    if (!validateCsrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    if (!$action) throw new Exception('Action not specified.');

    // ── Switch ────────────────────────────────────────────────────────────────
    switch ($action) {

        // ── UPDATE ────────────────────────────────────────────────────────────
        case 'update':
            $req  = ['id', 'first_name', 'last_name', 'email', 'phone_number', 'designation', 'department', 'nationality', 'nin'];
            $miss = array_filter($req, fn($f) => empty(trim((string)($staff[$f] ?? ''))));
            if ($miss) throw new Exception('Missing required fields: ' . implode(', ', array_values($miss)));

            $staffId = (int) $staff['id'];

            // Fetch current photo path from DB — never trust client-supplied path
            $pr = $conn->prepare("SELECT profile_photo FROM staff WHERE id = ? LIMIT 1");
            if (!$pr) throw new Exception('Prepare failed: ' . $conn->error);
            $pr->bind_param('i', $staffId);
            $pr->execute();
            $pr->bind_result($currentPhoto);
            $pr->fetch();
            $pr->close();

            $newPhoto = handlePhoto($staffId, $currentPhoto ?? '');
            $photoToSave = $newPhoto ?: $currentPhoto;

            // Nullable optional fields
            $dateOfBirth   = n($staff['date_of_birth']   ?? null);
            $maritalStatus = n($staff['marital_status']  ?? null);
            $address       = n($staff['address']         ?? null);
            $joiningDate   = n($staff['joining_date']    ?? null);
            $employmentType= n($staff['employment_type'] ?? null);
            $qualifications= n($staff['qualifications']  ?? null);
            $experience    = n($staff['experience']      ?? null);
            $tin           = n($staff['tin']             ?? null);
            $nssf          = n($staff['nssf']            ?? null);

            $stmt = $conn->prepare("
                UPDATE staff
                SET first_name = ?, last_name = ?, date_of_birth = ?, gender = ?,
                    phone_number = ?, marital_status = ?, nationality = ?, email = ?,
                    address = ?, designation = ?, department = ?, joining_date = ?,
                    employment_type = ?, qualifications = ?, experience = ?,
                    national_id = ?, tin_number = ?, nssf_number = ?, profile_photo = ?
                WHERE id = ?
            ");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);

            $stmt->bind_param('sssssssssssssssssssi',
                $staff['first_name'],
                $staff['last_name'],
                $dateOfBirth,
                $staff['gender'],
                $staff['phone_number'],
                $maritalStatus,
                $staff['nationality'],
                $staff['email'],
                $address,
                $staff['designation'],
                $staff['department'],
                $joiningDate,
                $employmentType,
                $qualifications,
                $experience,
                $staff['nin'],
                $tin,
                $nssf,
                $photoToSave,
                $staffId
            );
            if (!$stmt->execute()) throw new Exception('Update staff: ' . $stmt->error);
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'Staff updated successfully.']);
            break;

        // ── DELETE ────────────────────────────────────────────────────────────
        case 'delete':
            $staffId = (int) ($input['id'] ?? 0);
            if ($staffId <= 0) throw new Exception('A valid staff ID is required.');

            // Fetch photo path before row deletion
            $pr = $conn->prepare("SELECT profile_photo FROM staff WHERE id = ? LIMIT 1");
            if (!$pr) throw new Exception('Prepare failed: ' . $conn->error);
            $pr->bind_param('i', $staffId);
            $pr->execute();
            $pr->bind_result($photoPath);
            $pr->fetch();
            $pr->close();

            $stmt = $conn->prepare("DELETE FROM staff WHERE id = ?");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param('i', $staffId);
            if (!$stmt->execute()) throw new Exception('Delete staff: ' . $stmt->error);
            if ($stmt->affected_rows === 0) throw new Exception('Staff member not found.');
            $stmt->close();

            // Clean up photo file after successful deletion
            if (!empty($photoPath) && preg_match('#uploads/staff_photos/[^/?#]+$#', $photoPath, $m)) {
                $f = __DIR__ . '/' . $m[0];
                if (is_file($f)) @unlink($f);
            }

            echo json_encode(['success' => true, 'message' => 'Staff deleted successfully.']);
            break;

        // ── TOGGLE STATUS ─────────────────────────────────────────────────────
        case 'toggle_status':
            $staffId = (int) ($input['id']     ?? 0);
            $status  = trim($input['status']   ?? '');
            $valid   = ['active', 'inactive'];

            if ($staffId <= 0 || !in_array($status, $valid, true)) {
                throw new Exception('A valid staff ID and status (active or inactive) are required.');
            }

            $stmt = $conn->prepare("UPDATE staff SET status = ? WHERE id = ?");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param('si', $status, $staffId);
            if (!$stmt->execute()) throw new Exception('Toggle status: ' . $stmt->error);
            if ($stmt->affected_rows === 0) throw new Exception('Staff member not found or status already set.');
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'Status changed to ' . ucfirst($status) . '.']);
            break;

        default:
            throw new Exception('Unknown action.');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
}