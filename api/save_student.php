<?php
require_once '../auth.php';
require_once '../conn.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

// ── Role guard ────────────────────────────────────────────────────────────────
$allowed = ['developer', 'super user', 'class teacher', 'subject teacher', 'school leader'];
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
function handlePhoto(string $studentId, string $existing = ''): string {
    if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
        return $existing;
    }

    $file    = $_FILES['profile_photo'];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);

    if (!isset($allowed[$mime]))           throw new Exception('Invalid file type. JPEG, PNG or WebP only.');
    if ($file['size'] > 2 * 1024 * 1024)  throw new Exception('Photo must be 2 MB or smaller.');

    // 1. PHYSICAL PATH: Go up one level from 'api/' to project root
    $rootDir = dirname(__DIR__); 
    $uploadDir = $rootDir . '/uploads/profile_photos/';
    
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new Exception('Cannot create upload directory.');
    }

    $filename = preg_replace('/[^a-z0-9_]/i', '_', $studentId) . '_' . uniqid() . '.' . $allowed[$mime];
    $target   = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new Exception('Failed to save uploaded file.');
    }

    // 2. CLEANUP: Delete old photo using the correct physical path
    if ($existing !== '') {
        // Extract the filename from the old URL to find it on disk
        $oldFilename = basename(parse_url($existing, PHP_URL_PATH));
        $oldFilePath = $uploadDir . $oldFilename;
        if (is_file($oldFilePath)) {
            @unlink($oldFilePath);
        }
    }

    // 3. URL CONSTRUCTION: Build the full public URL pointing to the root
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    
    // dirname($_SERVER['SCRIPT_NAME']) is '/schoolpilot/api'. 
    // We use dirname() again to get the root: '/schoolpilot'
    $baseUrl  = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    
    return $protocol . '://' . $host . $baseUrl . '/uploads/profile_photos/' . $filename;
}

// ── Parse action & CSRF ───────────────────────────────────────────────────────
try {
    $student = $parent = [];

    if (isset($_POST['action']) && in_array($_POST['action'], ['add', 'update'], true)) {
        $action    = $_POST['action'];
        $csrfToken = $_POST['csrf_token'] ?? '';
        $student   = json_decode($_POST['student'] ?? '{}', true) ?: [];
        $parent    = json_decode($_POST['parent']  ?? '{}', true) ?: [];
    } else {
        $raw = file_get_contents('php://input');
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

        // ── ADD ───────────────────────────────────────────────────────────────
        case 'add':
            $req = ['student_id','first_name','last_name','gender','current_class','stream','section','nationality','date_of_enrolment'];
            $miss = array_filter($req, fn($f) => empty(trim($student[$f] ?? '')));
            if ($miss) throw new Exception('Missing required fields: ' . implode(', ', array_values($miss)));

            $student['profile_photo'] = handlePhoto($student['student_id']);

            $conn->begin_transaction();

            $stmt = $conn->prepare("
                INSERT INTO students
                    (student_id, first_name, last_name, date_of_birth, gender,
                     nationality, religion, profile_photo, residential_address,
                     current_class, stream, section, school_pay_code,
                     date_of_enrolment, previous_school, subject_combination, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);

            $p_student_id          = $student['student_id'];
            $p_first_name          = $student['first_name'];
            $p_last_name           = $student['last_name'];
            $p_date_of_birth       = n($student['date_of_birth']       ?? null);
            $p_gender              = $student['gender'];
            $p_nationality         = $student['nationality'];
            $p_religion            = n($student['religion']            ?? null);
            $p_profile_photo       = n($student['profile_photo']       ?? null);
            $p_residential_address = n($student['residential_address'] ?? null);
            $p_current_class       = $student['current_class'];
            $p_stream              = $student['stream'];
            $p_section             = $student['section'];
            $p_school_pay_code     = n($student['school_pay_code']     ?? null);
            $p_date_of_enrolment   = $student['date_of_enrolment'];
            $p_previous_school     = n($student['previous_school']     ?? null);
            $p_subject_combination = n($student['subject_combination'] ?? null);
            $p_status              = $student['status'] ?? 'active';

            $stmt->bind_param('sssssssssssssssss',
                $p_student_id,
                $p_first_name,
                $p_last_name,
                $p_date_of_birth,
                $p_gender,
                $p_nationality,
                $p_religion,
                $p_profile_photo,
                $p_residential_address,
                $p_current_class,
                $p_stream,
                $p_section,
                $p_school_pay_code,
                $p_date_of_enrolment,
                $p_previous_school,
                $p_subject_combination,
                $p_status
            );
            if (!$stmt->execute()) throw new Exception('Insert student: ' . $stmt->error);
            $stmt->close();

            // Insert parent if provided
            if (!empty(trim($parent['full_name'] ?? ''))) {
                $stmt = $conn->prepare("INSERT INTO parents (student_id, full_name, occupation, phone, email) VALUES (?,?,?,?,?)");
                if (!$stmt) throw new Exception('Prepare parent: ' . $conn->error);
                $add_par_student_id = $student['student_id'];
                $add_par_full_name  = $parent['full_name'];
                $add_par_occupation = n($parent['occupation'] ?? null);
                $add_par_phone      = n($parent['phone']      ?? null);
                $add_par_email      = n($parent['email']      ?? null);
                $stmt->bind_param('sssss',
                    $add_par_student_id,
                    $add_par_full_name,
                    $add_par_occupation,
                    $add_par_phone,
                    $add_par_email
                );
                if (!$stmt->execute()) throw new Exception('Insert parent: ' . $stmt->error);
                $stmt->close();
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Student added successfully.']);
            break;

        // ── UPDATE ────────────────────────────────────────────────────────────
        case 'update':
            $req = ['student_id','first_name','last_name','gender','current_class','stream','section'];
            $miss = array_filter($req, fn($f) => empty(trim($student[$f] ?? '')));
            if ($miss) throw new Exception('Missing required fields: ' . implode(', ', array_values($miss)));

            // Fetch current photo path before overwriting
            $pr = $conn->prepare("SELECT profile_photo FROM students WHERE student_id = ? LIMIT 1");
            $pr->bind_param('s', $student['student_id']);
            $pr->execute();
            $pr->bind_result($currentPhoto);
            $pr->fetch();
            $pr->close();

            $newPhoto = handlePhoto($student['student_id'], $currentPhoto ?? '');
            // If no new upload keep existing (handlePhoto already returns $existing)
            $student['profile_photo'] = $newPhoto ?: $currentPhoto;

            $conn->begin_transaction();

            $stmt = $conn->prepare("
                UPDATE students SET
                    first_name=?, last_name=?, date_of_birth=?, gender=?,
                    nationality=?, religion=?, residential_address=?,
                    current_class=?, stream=?, section=?,
                    subject_combination=?, profile_photo=?
                WHERE student_id = ?
            ");
            if (!$stmt) throw new Exception('Prepare update: ' . $conn->error);

            $u_first_name          = $student['first_name'];
            $u_last_name           = $student['last_name'];
            $u_date_of_birth       = n($student['date_of_birth']       ?? null);
            $u_gender              = $student['gender'];
            $u_nationality         = n($student['nationality']         ?? null);
            $u_religion            = n($student['religion']            ?? null);
            $u_residential_address = n($student['residential_address'] ?? null);
            $u_current_class       = $student['current_class'];
            $u_stream              = $student['stream'];
            $u_section             = $student['section'];
            $u_subject_combination = n($student['subject_combination'] ?? null);
            $u_profile_photo       = n($student['profile_photo']       ?? null);
            $u_student_id          = $student['student_id'];

            $stmt->bind_param('sssssssssssss',
                $u_first_name,
                $u_last_name,
                $u_date_of_birth,
                $u_gender,
                $u_nationality,
                $u_religion,
                $u_residential_address,
                $u_current_class,
                $u_stream,
                $u_section,
                $u_subject_combination,
                $u_profile_photo,
                $u_student_id
            );
            if (!$stmt->execute()) throw new Exception('Update student: ' . $stmt->error);
            $stmt->close();

            // Parent upsert — existence-based (avoids affected_rows=0 false-negative)
            if (!empty(trim($parent['full_name'] ?? '')) || !empty(trim($parent['phone'] ?? ''))) {
                $chk = $conn->prepare("SELECT id FROM parents WHERE student_id = ? LIMIT 1");
                $chk->bind_param('s', $student['student_id']);
                $chk->execute();
                $chk->store_result();
                $parentExists = $chk->num_rows > 0;
                $chk->close();

                $upr_full_name   = n($parent['full_name']  ?? null);
                $upr_occupation  = n($parent['occupation'] ?? null);
                $upr_phone       = n($parent['phone']      ?? null);
                $upr_email       = n($parent['email']      ?? null);
                $upr_student_id  = $student['student_id'];

                if ($parentExists) {
                    $stmt = $conn->prepare("UPDATE parents SET full_name=?, occupation=?, phone=?, email=? WHERE student_id=?");
                    $stmt->bind_param('sssss',
                        $upr_full_name,
                        $upr_occupation,
                        $upr_phone,
                        $upr_email,
                        $upr_student_id
                    );
                } else {
                    $stmt = $conn->prepare("INSERT INTO parents (student_id, full_name, occupation, phone, email) VALUES (?,?,?,?,?)");
                    $stmt->bind_param('sssss',
                        $upr_student_id,
                        $upr_full_name,
                        $upr_occupation,
                        $upr_phone,
                        $upr_email
                    );
                }
                if (!$stmt->execute()) throw new Exception('Upsert parent: ' . $stmt->error);
                $stmt->close();
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Student updated successfully.']);
            break;

        // ── DELETE ────────────────────────────────────────────────────────────
        case 'delete':
            $studentId = trim($input['student_id'] ?? '');
            if ($studentId === '') throw new Exception('Student ID is required.');

            // Fetch photo path before row deletion
            $pr = $conn->prepare("SELECT profile_photo FROM students WHERE student_id = ? LIMIT 1");
            $pr->bind_param('s', $studentId);
            $pr->execute();
            $pr->bind_result($photoPath);
            $pr->fetch();
            $pr->close();

            $conn->begin_transaction();

            $stmt = $conn->prepare("DELETE FROM parents WHERE student_id = ?");
            $stmt->bind_param('s', $studentId);
            if (!$stmt->execute()) throw new Exception('Delete parents: ' . $stmt->error);
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM students WHERE student_id = ?");
            $stmt->bind_param('s', $studentId);
            if (!$stmt->execute()) throw new Exception('Delete student: ' . $stmt->error);
            if ($stmt->affected_rows === 0) throw new Exception('Student not found.');
            $stmt->close();

            $conn->commit();

            // Clean up photo file after successful commit
            if (!empty($photoPath) && preg_match('#uploads/profile_photos/[^/?#]+$#', $photoPath, $m)) {
                $f = __DIR__ . '/' . $m[0];
                if (is_file($f)) @unlink($f);
            }

            echo json_encode(['success' => true, 'message' => 'Student deleted successfully.']);
            break;

        // ── TOGGLE STATUS ─────────────────────────────────────────────────────
        case 'toggle_status':
            $studentId = trim($input['student_id'] ?? '');
            $status    = trim($input['status']     ?? '');
            $valid     = ['active', 'inactive', 'graduated', 'transferred'];

            if ($studentId === '' || !in_array($status, $valid, true)) {
                throw new Exception('A valid student ID and status are required.');
            }

            $stmt = $conn->prepare("UPDATE students SET status = ? WHERE student_id = ?");
            $stmt->bind_param('ss', $status, $studentId);
            if (!$stmt->execute()) throw new Exception('Toggle status: ' . $stmt->error);
            if ($stmt->affected_rows === 0) throw new Exception('Student not found or status already set.');
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'Status changed to ' . ucfirst($status) . '.']);
            break;

        default:
            throw new Exception('Unknown action.');
    }

} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $t) {}
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
}