<?php
/**
 * process_registration.php
 * Handles student registration POST requests.
 *
 * Bugs fixed in this revision:
 *
 *  1. RACE CONDITION (critical) — Student ID is now generated atomically INSIDE
 *     the DB transaction using SELECT … FOR UPDATE immediately before INSERT.
 *     The lock is held until COMMIT, so no two concurrent registrations can
 *     produce the same ID. The client-submitted studentId is ignored server-side
 *     (it was only a preview displayed on the form).
 *
 *  2. FILE CLEANUP PATH (critical) — handleFileUpload() now returns both the
 *     filesystem path (fs_path) and the public URL (web_url). Rollback cleanup
 *     uses fs_path directly instead of the old UPLOAD_DIR constant, which pointed
 *     to a completely different directory and made cleanup silently fail every time.
 *
 *  3. EXCEPTION TYPE MISMATCH (critical) — All throws inside handleFileUpload()
 *     changed from plain Exception to RuntimeException, so the caller's
 *     catch (RuntimeException) block actually catches them. Previously a failed
 *     upload bypassed per-field error reporting and produced a generic 500.
 *
 *  4. HOST-HEADER INJECTION (bug) — Public URL for stored photos is now built
 *     from SERVER_NAME (set by web-server config) instead of HTTP_HOST (client-
 *     supplied). An attacker could previously forge "Host: evil.com" and store
 *     a malicious URL in the database.
 *
 *  5. USER-AGENT LENGTH (bug) — User-Agent is truncated to 255 chars before
 *     insertion into the audit log, preventing oversized strings from causing
 *     an insert failure that would roll back the entire registration.
 *
 *  6. DUPLICATE-KEY ERROR (bug) — MySQL errno 1062 (duplicate entry) is now
 *     caught and returns a user-friendly, actionable message instead of a
 *     generic server error. This can occur in the rare case where a concurrent
 *     registration commits between FOR UPDATE and the INSERT of this request.
 *
 *  7. HTTP STATUS CODES (bug) — Validation failures now return HTTP 422
 *     (Unprocessable Entity) instead of 200, so the JS fetch() correctly
 *     identifies them as errors when r.ok is checked.
 */

declare(strict_types=1);

// Start output buffering to catch any accidental whitespace before headers.
ob_start();

require_once '../auth.php'; // starts session, validates user, provides CSRF token
require_once '../conn.php';

// auth.php always calls session_start(); guard prevents a double-start on edge cases.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Convert PHP warnings/notices to catchable exceptions.
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Last-resort handler — catches anything not caught below.
set_exception_handler(function (Throwable $e): void {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status'  => 'error',
        'message' => 'A system error occurred. Please try again later.',
    ]);
    exit;
});

if (!$conn || $conn->connect_error) {
    throw new RuntimeException('Database connection not established');
}

header('Content-Type: application/json');

// ── App base URL (immune to host-header injection) ────────────────────────────
//
// SERVER_NAME is configured by the web server (Apache ServerName / Nginx server_name).
// HTTP_HOST is sent by the client and must never be trusted for stored values.
//
// RECOMMENDED: define APP_BASE_URL in a config.php that is required before this
// file so the value is always explicit:
//   define('APP_BASE_URL', 'https://yourschool.com/schoolpilot');
//
if (!defined('APP_BASE_URL')) {
    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $docRoot  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    $projRoot = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    $basePath = str_replace($docRoot, '', $projRoot);
    define('APP_BASE_URL', $scheme . '://' . $_SERVER['SERVER_NAME'] . $basePath);
}

// ── Upload paths ──────────────────────────────────────────────────────────────
//
// PHOTO_UPLOAD_DIR: filesystem path one level above api/ (the project root).
// Previously UPLOAD_DIR was defined as __DIR__.'/uploads/profiles/' — a path
// INSIDE api/ that never existed — so cleanup after transaction failure always
// silently failed. This is now corrected.
//
define(
    'PHOTO_UPLOAD_DIR',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profile_photos' . DIRECTORY_SEPARATOR
);
define('MAX_FILE_SIZE',    2 * 1024 * 1024);  // 2 MB
define('ALLOWED_MIME_TYPES', [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
]);

// ── Default response ──────────────────────────────────────────────────────────
$response = [
    'status'    => 'error',
    'message'   => 'An error occurred while processing your request.',
    'errors'    => [],
    'resetForm' => false,
];

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Trim only. htmlspecialchars() belongs at output time, not storage time.
 * Prepared statements already prevent SQL injection.
 */
function sanitizeInput(string $data): string
{
    return trim($data);
}

/**
 * Validate a date string in YYYY-MM-DD format.
 */
function isValidDate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

/**
 * Generate the next student ID atomically using SELECT … FOR UPDATE.
 *
 * MUST be called inside an active transaction. The InnoDB row lock is held
 * from this SELECT until the caller calls $conn->commit() (after INSERT),
 * so no two concurrent transactions can read the same "last ID" and produce
 * a collision.
 *
 * Pattern: OU-STD-YYYY-NNNN
 *
 * @throws RuntimeException on DB error or sequence overflow (> 9999/year)
 */
function generateStudentId(mysqli $conn): string
{
    $currentYear  = date('Y');
    $schoolPrefix = 'OU';
    $basePattern  = "{$schoolPrefix}-STD-{$currentYear}";
    $likePattern  = "{$basePattern}-%";

    $stmt = $conn->prepare(
        "SELECT student_id
           FROM students
          WHERE student_id LIKE ?
          ORDER BY CAST(SUBSTRING_INDEX(student_id, '-', -1) AS UNSIGNED) DESC
          LIMIT 1
          FOR UPDATE"
    );

    if (!$stmt) {
        throw new RuntimeException('generateStudentId prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('s', $likePattern);

    if (!$stmt->execute()) {
        throw new RuntimeException('generateStudentId execute failed: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $number = 1;

    if ($result && $result->num_rows > 0) {
        $row    = $result->fetch_assoc();
        $number = (int) substr($row['student_id'], -4) + 1;
    }

    $stmt->close();

    if ($number > 9999) {
        throw new RuntimeException(
            "Student ID sequence exhausted for year {$currentYear}. Contact the administrator."
        );
    }

    return "{$basePattern}-" . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
}

/**
 * Handle a profile photo upload securely.
 *
 * Returns both the filesystem path (for cleanup on rollback) and the
 * public URL (for storage in the database) as an associative array.
 *
 * @param  array $file  The $_FILES['profilePhoto'] entry
 * @return array{fs_path: string, web_url: string}
 * @throws RuntimeException on any error — caught by caller as a field-level error
 */
function handleFileUpload(array $file): array
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        // Map PHP upload error codes to user-friendly messages
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload size limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form upload size limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by a server extension.',
        ];
        throw new RuntimeException(
            $uploadErrors[$file['error']] ?? 'Upload error (code ' . $file['error'] . ').'
        );
    }

    // Validate MIME type from the file's actual bytes, not the client-supplied type.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    if (!isset(ALLOWED_MIME_TYPES[$mime])) {
        throw new RuntimeException('Invalid file type. Only JPG, PNG, and WebP are allowed.');
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        throw new RuntimeException('File size exceeds the 2 MB limit.');
    }

    $uploadDir = PHOTO_UPLOAD_DIR;

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException('Failed to create the upload directory.');
    }

    // Use a cryptographically random filename to prevent enumeration.
    $extension  = ALLOWED_MIME_TYPES[$mime];
    $filename   = 'stud_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $targetPath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Failed to save the uploaded file to the server.');
    }

    return [
        'fs_path' => $targetPath,
        // Bug fix #4: APP_BASE_URL uses SERVER_NAME, not HTTP_HOST
        'web_url' => APP_BASE_URL . '/uploads/profile_photos/' . $filename,
    ];
}

// ── Only accept POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response['message'] = 'Invalid request method.';
    ob_end_clean();
    echo json_encode($response);
    exit;
}

try {
    // ── 1. CSRF VALIDATION ────────────────────────────────────────────────────
    $submittedToken = $_POST['csrf_token'] ?? '';
    $sessionToken   = $_SESSION['csrf_token'] ?? '';

    if (
        empty($submittedToken) ||
        empty($sessionToken)   ||
        !hash_equals($sessionToken, $submittedToken)
    ) {
        http_response_code(403);
        $response['message'] = 'Invalid or expired security token. Please refresh the page and try again.';
        ob_end_clean();
        echo json_encode($response);
        exit;
    }

    // ── 2. REQUIRED FIELD PRESENCE CHECK ──────────────────────────────────────
    // Note: 'studentId' is intentionally absent — it is generated server-side
    // inside the DB transaction (see step 6a). The value submitted by the form
    // is a UI preview only and is not used here.
    $requiredFields = [
        'firstName'    => 'First name',
        'lastName'     => 'Last name',
        'gender'       => 'Gender',
        'nationality'  => 'Nationality',
        'currentClass' => 'Current class',
        'stream'       => 'Stream',
        'section'      => 'Section',
    ];

    foreach ($requiredFields as $field => $label) {
        if (empty($_POST[$field])) {
            $response['errors'][$field] = "{$label} is required.";
        }
    }

    if (!empty($response['errors'])) {
        http_response_code(422); // Bug fix #7: was 200
        $response['message'] = 'Please fill in all required fields.';
        ob_end_clean();
        echo json_encode($response);
        exit;
    }

    // ── 3. FIELD EXTRACTION ───────────────────────────────────────────────────
    $firstName          = sanitizeInput($_POST['firstName']);
    $lastName           = sanitizeInput($_POST['lastName']);
    $dateOfBirth        = !empty($_POST['dateOfBirth'])        ? sanitizeInput($_POST['dateOfBirth'])        : null;
    $gender             = sanitizeInput($_POST['gender']);
    $nationality        = sanitizeInput($_POST['nationality']);
    $religion           = !empty($_POST['religion'])           ? sanitizeInput($_POST['religion'])           : null;
    $residentialAddress = !empty($_POST['residentialAddress']) ? sanitizeInput($_POST['residentialAddress']) : null;
    $parentName         = !empty($_POST['parentName'])         ? sanitizeInput($_POST['parentName'])         : '';
    $parentOccupation   = !empty($_POST['parentOccupation'])   ? sanitizeInput($_POST['parentOccupation'])   : null;
    $parentPhone        = !empty($_POST['parentPhone'])        ? sanitizeInput($_POST['parentPhone'])        : null;
    $parentEmail        = !empty($_POST['parentEmail'])        ? sanitizeInput($_POST['parentEmail'])        : null;
    $currentClass       = sanitizeInput($_POST['currentClass']);
    $stream             = sanitizeInput($_POST['stream']);
    $section            = sanitizeInput($_POST['section']);
    $schoolPayCode      = !empty($_POST['schoolPayCode'])      ? sanitizeInput($_POST['schoolPayCode'])      : null;
    $dateOfEnrolment    = !empty($_POST['dateOfEnrolment'])    ? sanitizeInput($_POST['dateOfEnrolment'])    : null;
    $previousSchool     = !empty($_POST['previousSchool'])     ? sanitizeInput($_POST['previousSchool'])     : null;
    $subjectCombination = !empty($_POST['subjectCombination']) ? sanitizeInput($_POST['subjectCombination']) : null;

    // ── 4. BUSINESS RULE VALIDATION ───────────────────────────────────────────
    if (mb_strlen($firstName) < 2 || mb_strlen($firstName) > 50) {
        $response['errors']['firstName'] = 'First name must be 2–50 characters.';
    }
    if (mb_strlen($lastName) < 2 || mb_strlen($lastName) > 50) {
        $response['errors']['lastName'] = 'Last name must be 2–50 characters.';
    }

    if ($dateOfBirth !== null && !isValidDate($dateOfBirth)) {
        $response['errors']['dateOfBirth'] = 'Invalid date of birth format.';
    }

    if (!in_array($gender, ['Male', 'Female'], true)) {
        $response['errors']['gender'] = 'Invalid gender selection.';
    }

    if (!in_array($section, ['Day', 'Boarding'], true)) {
        $response['errors']['section'] = 'Invalid section selection.';
    }

    if ($parentEmail !== null && !filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
        $response['errors']['parentEmail'] = 'Invalid email address.';
    }

    if ($parentPhone !== null) {
        $digitsOnly = preg_replace('/[\s()\-]/', '', $parentPhone);
        if (!preg_match('/^\+?[0-9]{10,15}$/', $digitsOnly)) {
            $response['errors']['parentPhone'] = 'Invalid phone number (10–15 digits).';
        }
    }

    if ($dateOfEnrolment !== null) {
        if (!isValidDate($dateOfEnrolment)) {
            $response['errors']['dateOfEnrolment'] = 'Invalid enrolment date format.';
        } elseif (new DateTime($dateOfEnrolment) > new DateTime()) {
            $response['errors']['dateOfEnrolment'] = 'Enrolment date cannot be in the future.';
        }
    }

    if ($subjectCombination !== null && mb_strlen($subjectCombination) > 120) {
        $response['errors']['subjectCombination'] = 'Subject combination is too long (max 120 characters).';
    }

    // ── 5. FILE UPLOAD ────────────────────────────────────────────────────────
    // Upload happens BEFORE the DB transaction so we never hold a DB lock
    // during slow I/O. The filesystem path is kept for cleanup on rollback.
    //
    // Bug fix #2: handleFileUpload() now returns ['fs_path', 'web_url'].
    //             Cleanup uses fs_path (exact disk location) instead of the
    //             old UPLOAD_DIR constant, which pointed to the wrong directory.
    //
    // Bug fix #3: handleFileUpload() throws RuntimeException (was Exception),
    //             so this catch block actually catches upload errors and maps
    //             them to a per-field validation message instead of a 500.
    $profilePhotoFsPath = ''; // filesystem path — used for rollback cleanup
    $profilePhotoUrl    = ''; // public URL    — stored in the database

    if (isset($_FILES['profilePhoto']) && $_FILES['profilePhoto']['error'] !== UPLOAD_ERR_NO_FILE) {
        try {
            $upload             = handleFileUpload($_FILES['profilePhoto']);
            $profilePhotoFsPath = $upload['fs_path'];
            $profilePhotoUrl    = $upload['web_url'];
        } catch (RuntimeException $e) {
            $response['errors']['profilePhoto'] = $e->getMessage();
        }
    }

    // Return early if any validation failed
    if (!empty($response['errors'])) {
        http_response_code(422); // Bug fix #7: was 200
        $response['message'] = 'Please correct the highlighted errors.';
        ob_end_clean();
        echo json_encode($response);
        exit;
    }

    // ── 6. DATABASE TRANSACTION ───────────────────────────────────────────────
    $conn->begin_transaction();

    try {
        // ── 6a. Generate student ID atomically ────────────────────────────────
        // Bug fix #1: ID is generated HERE, inside the transaction.
        // FOR UPDATE locks the last row until COMMIT (after INSERT below),
        // preventing any concurrent request from reading the same sequence number.
        $studentId = generateStudentId($conn);

        // ── 6b. Insert student ────────────────────────────────────────────────
        $stmt = $conn->prepare(
            "INSERT INTO students (
                student_id, first_name, last_name, date_of_birth, gender,
                nationality, religion, profile_photo, residential_address,
                current_class, stream, section, school_pay_code,
                date_of_enrolment, previous_school, subject_combination
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if ($stmt === false) {
            throw new RuntimeException('Prepare student insert failed: ' . $conn->error);
        }

        $stmt->bind_param(
            'ssssssssssssssss',
            $studentId, $firstName, $lastName, $dateOfBirth,
            $gender, $nationality, $religion, $profilePhotoUrl,
            $residentialAddress, $currentClass, $stream, $section,
            $schoolPayCode, $dateOfEnrolment, $previousSchool, $subjectCombination
        );

        if (!$stmt->execute()) {
            // Bug fix #6: catch duplicate-key error and surface a clear message.
            // errno 1062 = MySQL "Duplicate entry" — the extremely rare case where
            // two transactions both passed FOR UPDATE in the same millisecond.
            if ($conn->errno === 1062) {
                throw new RuntimeException(
                    'A student ID conflict occurred due to a simultaneous registration. ' .
                    'Please resubmit — a new ID will be assigned automatically.'
                );
            }
            throw new RuntimeException('Insert student failed: ' . $stmt->error);
        }
        $stmt->close();

        // ── 6c. Insert parent record ──────────────────────────────────────────
        if ($parentName !== '') {
            $stmt = $conn->prepare(
                "INSERT INTO parents (student_id, full_name, occupation, phone, email, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );

            if ($stmt === false) {
                throw new RuntimeException('Prepare parent insert failed: ' . $conn->error);
            }

            $stmt->bind_param('sssss', $studentId, $parentName, $parentOccupation, $parentPhone, $parentEmail);

            if (!$stmt->execute()) {
                throw new RuntimeException('Insert parent failed: ' . $stmt->error);
            }
            $stmt->close();
        }

        // ── 6d. Audit log ─────────────────────────────────────────────────────
        $auditStmt = $conn->prepare(
            "INSERT INTO registration_logs (student_id, action, status, message, ip_address, user_agent, created_at)
             VALUES (?, 'student_registration', 'success', ?, ?, ?, NOW())"
        );

        if ($auditStmt !== false) {
            $auditMsg  = "Student {$firstName} {$lastName} ({$studentId}) registered successfully.";
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            // Bug fix #5: truncate User-Agent to 255 chars.
            // An oversized UA string (up to 64 KB in theory) would cause the
            // INSERT to fail if the DB column is VARCHAR(255), rolling back the
            // entire registration silently.
            $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);
            $auditStmt->bind_param('ssss', $studentId, $auditMsg, $ipAddress, $userAgent);
            $auditStmt->execute();
            $auditStmt->close();
        }

        $conn->commit();

        // Rotate the CSRF token after a successful mutation to prevent replay attacks.
        $_SESSION['csrf_token']      = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();

       $response = [
    'status'     => 'success',
    'message'    => "Student {$firstName} {$lastName} registered successfully! (ID: {$studentId})",
    'studentId'  => $studentId,
    'resetForm'  => true,
    'csrf_token' => $_SESSION['csrf_token'],   // ← ADD THIS
];

    } catch (RuntimeException $e) {
        $conn->rollback();

        // Bug fix #2: clean up the uploaded file using the exact filesystem path.
        // Previously this used UPLOAD_DIR + basename(url), which pointed to the
        // wrong directory and silently left orphaned files on disk after every
        // failed transaction.
        if ($profilePhotoFsPath !== '' && file_exists($profilePhotoFsPath)) {
            unlink($profilePhotoFsPath);
        }

        throw $e; // Re-throw to the outer catch
    }

} catch (Throwable $e) {
    error_log('[process_registration] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    // The duplicate-ID concurrency message is safe and actionable to show the user.
    // All other internal errors are hidden behind a generic message.
    if (str_contains($e->getMessage(), 'ID conflict')) {
        $response['message'] = $e->getMessage();
    } else {
        $response['message'] = 'A server error occurred. Please try again later.';
    }
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

ob_end_clean();
echo json_encode($response);
exit;