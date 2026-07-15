<?php

/**
 * process_staff_registration.php
 * Handles staff registration POST requests.
 *
 * Security & reliability improvements over the original:
 *
 *  1. CSRF VALIDATION (critical) — Every POST is validated against the session
 *     token set in add_staff.php. The original had no CSRF protection at all.
 *
 *  2. RACE CONDITION (critical) — Staff ID is now generated atomically INSIDE
 *     the DB transaction using SELECT … FOR UPDATE immediately before INSERT.
 *     The lock is held until COMMIT. The original generateStaffId() ran outside
 *     any transaction; two concurrent submissions could produce the same ID.
 *
 *  3. SQL INJECTION IN ID GENERATION (critical) — The original used raw string
 *     interpolation in the SQL WHERE clause: WHERE staff_id LIKE '{$prefix}%'.
 *     This is now replaced with a prepared statement and bound parameter.
 *
 *  4. INCORRECT SANITISATION (bug) — The original sanitize_input() called both
 *     htmlspecialchars() AND real_escape_string() on every value before inserting
 *     via prepared statements. htmlspecialchars is for HTML output, not DB storage;
 *     it would corrupt data like "O'Brien" → "O&#039;Brien" in the database.
 *     Prepared statements already prevent SQL injection; no escaping is needed.
 *
 *  5. UNTRUSTWORTHY MIME DETECTION (bug) — The original trusted $_FILES[…]['type'],
 *     which is set by the browser/client and can be spoofed. File type is now
 *     verified with finfo_file() which reads the actual file content.
 *
 *  6. NO TRANSACTION / ROLLBACK (bug) — The original had no transaction; if the
 *     INSERT failed after a photo was uploaded, the orphaned file was never cleaned
 *     up. A transaction now wraps the ID generation and INSERT together.
 *
 *  7. JSON RESPONSE (architecture) — The original used header('Location:…') for
 *     both success and failure, making it incompatible with fetch()-based submission.
 *     This version always returns JSON, matching the student registration pattern.
 *
 *  8. HTTP STATUS CODES (bug) — Validation failures now return HTTP 422 and errors
 *     return 500, so fetch()'s r.ok check works correctly.
 *
 *  9. OUTPUT BUFFERING (robustness) — ob_start() prevents stray whitespace from
 *     corrupting the JSON output.
 *
 * 10. DUPLICATE EMAIL / NIN DETECTION (UX) — MySQL errno 1062 is caught and
 *     returns a descriptive, actionable message instead of a generic error.
 */

declare(strict_types=1);

ob_start();

require_once '../auth.php'; // starts session, validates user
require_once '../conn.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Convert PHP warnings/notices to catchable exceptions.
set_error_handler(function (int $severity, string $msg, string $file, int $line): bool {
    throw new ErrorException($msg, 0, $severity, $file, $line);
});

// Last-resort handler.
set_exception_handler(function (Throwable $e): void {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status'  => 'error',
        'message' => 'A system error occurred. Please try again later.',
    ]);
    error_log('[process_staff] Unhandled: ' . $e->getMessage());
    exit;
});

if (!$conn || $conn->connect_error) {
    throw new RuntimeException('Database connection not established');
}

header('Content-Type: application/json');

// ── Upload paths ──────────────────────────────────────────────────────────────
define(
    'STAFF_UPLOAD_DIR',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'staff_photos' . DIRECTORY_SEPARATOR
);
define('MAX_STAFF_FILE_SIZE',    2 * 1024 * 1024);   // 2 MB
define('ALLOWED_STAFF_MIME_TYPES', [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
]);

// ── Default response ──────────────────────────────────────────────────────────
$response = [
    'status'  => 'error',
    'message' => 'An error occurred while processing your request.',
    'errors'  => [],
];

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Returns a trimmed, non-empty string or null.
 * No htmlspecialchars — that belongs in output, never in DB storage.
 */
function cleanString(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $trimmed = trim($value);
    return $trimmed !== '' ? $trimmed : null;
}

/**
 * Validates a date string and returns it in Y-m-d format, or throws.
 *
 * @throws InvalidArgumentException
 */
function validateDate(string $value, string $fieldName): string
{
    $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        throw new \InvalidArgumentException("{$fieldName} has an invalid date format.");
    }
    return $d->format('Y-m-d');
}

/**
 * Handles the profile photo upload.
 *
 * Returns ['fs_path' => …, 'web_path' => …] on success.
 * Returns null if no file was submitted.
 *
 * @throws RuntimeException on upload failure
 */
function handleStaffPhotoUpload(array $file): ?array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $codes = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload directory.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'File upload stopped by server extension.',
        ];
        throw new RuntimeException($codes[$file['error']] ?? 'Unknown upload error.');
    }

    if ($file['size'] > MAX_STAFF_FILE_SIZE) {
        throw new RuntimeException('Profile photo is too large. Maximum size is 2 MB.');
    }

    // Detect MIME from file content — never trust $_FILES[…]['type'].
    $finfo    = new \finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!array_key_exists($mimeType, ALLOWED_STAFF_MIME_TYPES)) {
        throw new RuntimeException('Invalid file type. Only JPEG, PNG, and WebP are allowed.');
    }

    if (!is_dir(STAFF_UPLOAD_DIR)) {
        if (!mkdir(STAFF_UPLOAD_DIR, 0755, true)) {
            throw new RuntimeException('Could not create upload directory.');
        }
    }

    $extension = ALLOWED_STAFF_MIME_TYPES[$mimeType];
    $fileName  = 'staff_' . bin2hex(random_bytes(12)) . '.' . $extension;
    $fsPath    = STAFF_UPLOAD_DIR . $fileName;
    $webPath   = 'uploads/staff_photos/' . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $fsPath)) {
        throw new RuntimeException('Failed to save the uploaded photo.');
    }

    return ['fs_path' => $fsPath, 'web_path' => $webPath];
}

/**
 * Generates the next staff ID atomically within an open transaction.
 * Caller MUST hold an active transaction; the FOR UPDATE lock is released on COMMIT/ROLLBACK.
 *
 * Pattern: OU-YYYY-STA-NNNN
 *
 * @throws RuntimeException
 */
function generateStaffIdAtomic(mysqli $conn): string
{
    $currentYear = date('Y');
    $basePattern = "OU-{$currentYear}-STA";
    $likePattern = "{$basePattern}-%";

    $stmt = $conn->prepare(
        "SELECT staff_id
           FROM staff
          WHERE staff_id LIKE ?
          ORDER BY CAST(SUBSTRING_INDEX(staff_id, '-', -1) AS UNSIGNED) DESC
          LIMIT 1
          FOR UPDATE"
    );

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('s', $likePattern);

    if (!$stmt->execute()) {
        throw new RuntimeException('Execute failed: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $number = 1;

    if ($result && $result->num_rows > 0) {
        $row    = $result->fetch_assoc();
        $number = (int) substr($row['staff_id'], -4) + 1;
    }

    $stmt->close();

    if ($number > 9999) {
        throw new RuntimeException(
            "Staff ID sequence exhausted for year {$currentYear}. Contact the administrator."
        );
    }

    return "{$basePattern}-" . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
}

// ── Request method guard ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

// ── CSRF validation ───────────────────────────────────────────────────────────
$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken   = $_SESSION['csrf_token'] ?? '';

if (
    empty($submittedToken) ||
    empty($sessionToken)   ||
    !hash_equals($sessionToken, $submittedToken)
) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid or expired security token. Please refresh the page and try again.']);
    exit;
}

// Rotate the CSRF token after successful validation.
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// ── Input collection & validation ─────────────────────────────────────────────
$errors = [];

$firstName      = cleanString($_POST['firstName']      ?? null);
$lastName       = cleanString($_POST['lastName']       ?? null);
$dateOfBirth    = cleanString($_POST['dateOfBirth']    ?? null);
$gender         = cleanString($_POST['gender']         ?? null);
$phoneNumber    = cleanString($_POST['phoneNumber']    ?? null);
$nationality    = cleanString($_POST['nationality']    ?? null);
$email          = cleanString($_POST['email']          ?? null);
$address        = cleanString($_POST['address']        ?? null);
$maritalStatus  = cleanString($_POST['maritalStatus']  ?? null);
$designation    = cleanString($_POST['designation']    ?? null);
$department     = cleanString($_POST['department']     ?? null);
$joiningDate    = cleanString($_POST['joiningDate']    ?? null);
$employmentType = cleanString($_POST['employmentType'] ?? null);
$qualifications = cleanString($_POST['qualifications'] ?? null);
$nin            = cleanString($_POST['nin']            ?? null);
$tin            = cleanString($_POST['tin']            ?? null);
$nssf           = cleanString($_POST['nssf']           ?? null);
$experience     = isset($_POST['experience']) && $_POST['experience'] !== ''
    ? (int) $_POST['experience']
    : null;

// Required field validation
$required = [
    'firstName'      => $firstName,
    'lastName'       => $lastName,
    'gender'         => $gender,
    'phoneNumber'    => $phoneNumber,
    'nationality'    => $nationality,
    'email'          => $email,
    'address'        => $address,
    'designation'    => $designation,
    'department'     => $department,
    'joiningDate'    => $joiningDate,
    'employmentType' => $employmentType,
    'qualifications' => $qualifications,
    'nin'            => $nin,
];

foreach ($required as $field => $value) {
    if ($value === null) {
        $errors[$field] = ucfirst(preg_replace('/([A-Z])/', ' $1', $field)) . ' is required.';
    }
}

// Individual field rules
if ($firstName !== null && (mb_strlen($firstName) < 2 || mb_strlen($firstName) > 80)) {
    $errors['firstName'] = 'First name must be 2–80 characters.';
}

if ($lastName !== null && (mb_strlen($lastName) < 2 || mb_strlen($lastName) > 80)) {
    $errors['lastName'] = 'Last name must be 2–80 characters.';
}

if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Please enter a valid email address.';
}

if ($phoneNumber !== null && !preg_match('/^\+?[\d\s\-()]{7,20}$/', $phoneNumber)) {
    $errors['phoneNumber'] = 'Please enter a valid phone number.';
}

if ($dateOfBirth !== null) {
    try {
        $dob = validateDate($dateOfBirth, 'Date of Birth');
        if ($dob > date('Y-m-d')) {
            $errors['dateOfBirth'] = 'Date of birth cannot be in the future.';
        }
    } catch (\InvalidArgumentException $e) {
        $errors['dateOfBirth'] = $e->getMessage();
    }
}

if ($joiningDate !== null) {
    try {
        validateDate($joiningDate, 'Joining Date');
    } catch (\InvalidArgumentException $e) {
        $errors['joiningDate'] = $e->getMessage();
    }
}

if ($gender !== null && !in_array($gender, ['Male', 'Female'], true)) {
    $errors['gender'] = 'Please select a valid gender.';
}

if ($experience !== null && ($experience < 0 || $experience > 60)) {
    $errors['experience'] = 'Experience must be between 0 and 60 years.';
}

// Return all validation errors at once (HTTP 422)
if (!empty($errors)) {
    ob_end_clean();
    http_response_code(422);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Please correct the highlighted fields.',
        'errors'  => $errors,
    ]);
    exit;
}

// ── File upload (outside transaction — avoids holding lock during slow I/O) ───
$photoData = null;
try {
    if (isset($_FILES['profilePhoto'])) {
        $photoData = handleStaffPhotoUpload($_FILES['profilePhoto']);
    }
} catch (RuntimeException $e) {
    ob_end_clean();
    http_response_code(422);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
        'errors'  => ['profilePhoto' => $e->getMessage()],
    ]);
    exit;
}

$photoWebPath = $photoData['web_path'] ?? null;
$photoFsPath  = $photoData['fs_path']  ?? null;

// ── Database transaction ──────────────────────────────────────────────────────
$conn->begin_transaction();

try {
    // Generate staff ID atomically (FOR UPDATE holds row/gap lock until COMMIT).
    $staffId = generateStaffIdAtomic($conn);

    $stmt = $conn->prepare(
        "INSERT INTO staff (
        first_name, last_name, date_of_birth, gender, phone_number,
        nationality, email, address, marital_status, profile_photo,
        designation, department, joining_date, employment_type,
        qualifications, experience, tin_number, nssf_number,
        national_id, staff_id, Status
     ) VALUES (
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active'
     )"
    );
    if (!$stmt) {
        throw new RuntimeException('Prepare INSERT failed: ' . $conn->error);
    }

    $stmt->bind_param(
        'sssssssssssssssissss',
        $firstName,
        $lastName,
        $dateOfBirth,
        $gender,
        $phoneNumber,
        $nationality,
        $email,
        $address,
        $maritalStatus,
        $photoWebPath,
        $designation,
        $department,
        $joiningDate,
        $employmentType,
        $qualifications,
        $experience,
        $tin,
        $nssf,
        $nin,
        $staffId
    );

    if (!$stmt->execute()) {
        // Catch duplicate-key violations (e.g. email or NIN already exists).
        if ($conn->errno === 1062) {
            throw new \DomainException(
                'A staff member with this email or National ID already exists.'
            );
        }
        throw new RuntimeException('INSERT failed: ' . $stmt->error);
    }

    $stmt->close();
    $conn->commit();

    ob_end_clean();
    echo json_encode([
        'status'  => 'success',
        'message' => "Staff member registered successfully. Staff ID: {$staffId}",
        'staffId' => $staffId,
    ]);
} catch (\DomainException $e) {
    $conn->rollback();
    // Clean up orphaned upload if any
    if ($photoFsPath && file_exists($photoFsPath)) {
        @unlink($photoFsPath);
    }
    ob_end_clean();
    http_response_code(422);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    if ($photoFsPath && file_exists($photoFsPath)) {
        @unlink($photoFsPath);
    }
    error_log('[process_staff] Registration error: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Registration failed. Please try again or contact the administrator.',
    ]);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
