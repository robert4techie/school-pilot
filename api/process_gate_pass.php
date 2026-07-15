<?php
require_once '../auth.php';
require_once '../conn.php';

header('Content-Type: application/json');

// ── Only accept POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Decode JSON body ────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request body.']);
    exit;
}

// ── CSRF validation ─────────────────────────────────────────────────────────
if (
    empty($input['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'] ?? '', $input['csrf_token'])
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing CSRF token.']);
    exit;
}

// ── Input validation ────────────────────────────────────────────────────────
/**
 * Validates all required gate-pass fields.
 *
 * @param array $data Decoded JSON input.
 * @return string[]   Array of human-readable error messages (empty = valid).
 */
function validateInput(array $data): array
{
    $errors = [];

    $required = [
        'student_name'   => 'Student name',
        'student_id'     => 'Student ID',
        'class'          => 'Class',
        'stream'         => 'Stream',
        'departure_time' => 'Departure time',
        'expected_return'=> 'Expected return time',
        'destination'    => 'Destination',
        'priority'       => 'Priority level',
        'reason'         => 'Reason for leaving',
        'issued_by'      => 'Issuer name',
    ];

    foreach ($required as $field => $label) {
        if (empty(trim($data[$field] ?? ''))) {
            $errors[] = "{$label} is required.";
        }
    }

    // Validate departure time
    $departure = null;
    if (!empty($data['departure_time'])) {
        $departure = strtotime($data['departure_time']);
        if ($departure === false) {
            $errors[] = 'Invalid departure time format.';
        } elseif ($departure < (time() - 600)) {
            // Allow up to 10 minutes in the past to account for network latency / form fill time
            $errors[] = 'Departure time cannot be in the past.';
        }
    }

    // Validate expected return time
    if (!empty($data['expected_return'])) {
        $return = strtotime($data['expected_return']);
        if ($return === false) {
            $errors[] = 'Invalid return time format.';
        } elseif ($departure !== null && $departure !== false && $return <= $departure) {
            $errors[] = 'Return time must be after departure time.';
        }
    }

    // Validate priority
    $validPriorities = ['normal', 'urgent', 'emergency'];
    if (!empty($data['priority']) && !in_array($data['priority'], $validPriorities, true)) {
        $errors[] = 'Invalid priority level.';
    }

    // Validate optional phone numbers
    $phonePattern = '/^\+?[\d\s\-()\[\]]{10,20}$/';
    if (!empty($data['parent_contact'])) {
        $stripped = preg_replace('/[\s\-()\[\]]/', '', $data['parent_contact']);
        if (!preg_match('/^\+?\d{10,15}$/', $stripped)) {
            $errors[] = 'Invalid parent contact number format.';
        }
    }
    if (!empty($data['student_contact'])) {
        $stripped = preg_replace('/[\s\-()\[\]]/', '', $data['student_contact']);
        if (!preg_match('/^\+?\d{10,15}$/', $stripped)) {
            $errors[] = 'Invalid student contact number format.';
        }
    }

    return $errors;
}

// ── Run validation ──────────────────────────────────────────────────────────
$validationErrors = validateInput($input);
if (!empty($validationErrors)) {
    echo json_encode(['success' => false, 'errors' => $validationErrors]);
    exit;
}

// ── Sanitise input (trim only — prepared statements handle SQL escaping) ───
$studentName        = trim($input['student_name']);
$studentId          = trim($input['student_id']);
$class              = trim($input['class']);
$stream             = trim($input['stream']);
$departureTime      = trim($input['departure_time']);
$expectedReturn     = trim($input['expected_return']);
$destination        = trim($input['destination']);
$priority           = trim($input['priority']);
$reason             = trim($input['reason']);
$parentContact      = !empty($input['parent_contact'])      ? trim($input['parent_contact'])      : null;
$studentContact     = !empty($input['student_contact'])     ? trim($input['student_contact'])     : null;
$accompanyingPerson = !empty($input['accompanying_person']) ? trim($input['accompanying_person']) : null;

// Use the session user's name if available; fall back to the form-supplied value.
// This ensures the field always reflects the actual logged-in user.
$issuedBy = trim(
    $_SESSION['user_name'] ?? $_SESSION['username'] ?? $_SESSION['full_name'] ?? $input['issued_by']
);
if ($issuedBy === '') {
    $issuedBy = trim($input['issued_by']);
}

// ── Generate reference number ───────────────────────────────────────────────
$referenceNumber = 'GP-' . date('YmdHis') . '-' . random_int(1000, 9999);

try {
    // ── Start transaction ─────────────────────────────────────────────────
    mysqli_autocommit($conn, false);

    // ── Ensure gate_passes table exists ──────────────────────────────────
    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'gate_passes'");
    if (mysqli_num_rows($tableCheck) === 0) {
        $createTable = "CREATE TABLE gate_passes (
            id                 INT           AUTO_INCREMENT PRIMARY KEY,
            reference_number   VARCHAR(50)   UNIQUE NOT NULL,
            student_id         VARCHAR(20)   NOT NULL,
            student_name       VARCHAR(100)  NOT NULL,
            class              VARCHAR(50)   NOT NULL,
            stream             VARCHAR(50)   NOT NULL,
            departure_time     DATETIME      NOT NULL,
            expected_return    DATETIME      NOT NULL,
            actual_return      DATETIME      NULL,
            destination        VARCHAR(255)  NOT NULL,
            reason             TEXT          NOT NULL,
            priority           ENUM('normal','urgent','emergency') DEFAULT 'normal',
            parent_contact     VARCHAR(25)   NULL,
            student_contact    VARCHAR(25)   NULL,
            accompanying_person VARCHAR(100) NULL,
            status             ENUM('issued','returned','overdue','cancelled') DEFAULT 'issued',
            issued_by          VARCHAR(100)  NOT NULL,
            issued_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
            returned_at        TIMESTAMP     NULL,
            notes              TEXT          NULL,
            created_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
            updated_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_student_id      (student_id),
            INDEX idx_reference       (reference_number),
            INDEX idx_status          (status),
            INDEX idx_issued_at       (issued_at)
        )";

        if (!mysqli_query($conn, $createTable)) {
            throw new RuntimeException('Failed to create gate_passes table: ' . mysqli_error($conn));
        }
    }

    // ── Verify the student exists and is active ───────────────────────────
    $stmtCheck = mysqli_prepare($conn, "SELECT student_id FROM students WHERE student_id = ? AND status = 'active'");
    if (!$stmtCheck) {
        throw new RuntimeException('Database prepare error: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmtCheck, 's', $studentId);
    mysqli_stmt_execute($stmtCheck);
    $checkResult = mysqli_stmt_get_result($stmtCheck);
    if (mysqli_num_rows($checkResult) === 0) {
        throw new RuntimeException('Student not found or is no longer active.');
    }
    mysqli_stmt_close($stmtCheck);

    // ── Insert gate pass ──────────────────────────────────────────────────
    $insertSql = "INSERT INTO gate_passes
        (reference_number, student_id, student_name, class, stream,
         departure_time, expected_return, destination, reason, priority,
         parent_contact, student_contact, accompanying_person, issued_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = mysqli_prepare($conn, $insertSql);
    if (!$stmt) {
        throw new RuntimeException('Database prepare error: ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param(
        $stmt, 'ssssssssssssss',
        $referenceNumber,
        $studentId,
        $studentName,
        $class,
        $stream,
        $departureTime,
        $expectedReturn,
        $destination,
        $reason,
        $priority,
        $parentContact,
        $studentContact,
        $accompanyingPerson,
        $issuedBy
    );

    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('Failed to issue gate pass: ' . mysqli_stmt_error($stmt));
    }

    $passId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    // ── Commit ────────────────────────────────────────────────────────────
    mysqli_commit($conn);
    mysqli_autocommit($conn, true);

    echo json_encode([
        'success' => true,
        'message' => 'Gate pass issued successfully!',
        'data'    => [
            'reference_number' => $referenceNumber,
            'pass_id'          => $passId,
            'student_name'     => $studentName,
            'departure_time'   => date('M j, Y g:i A', strtotime($departureTime)),
            'expected_return'  => date('M j, Y g:i A', strtotime($expectedReturn)),
            'destination'      => $destination,
            'priority'         => ucfirst($priority),
        ],
    ]);

} catch (RuntimeException $e) {
    mysqli_rollback($conn);
    mysqli_autocommit($conn, true);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}

mysqli_close($conn);
