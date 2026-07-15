<?php
/**
 * print_gate_pass.php
 * Renders a printable A4 gate pass.
 * Supports authenticated staff access and unauthenticated QR-verified access.
 */

// ── 0. Secret key — must be defined before any use ────────────────────────
if (!defined('GATE_PASS_SECRET_KEY')) {
    define('GATE_PASS_SECRET_KEY', getenv('GATE_PASS_SECRET') ?: 'GATEPASS_VERIFICATION_SECRET_2026');
}

// ── 1. Access control ──────────────────────────────────────────────────────
$is_verified_access = false;

if (isset($_GET['verified'], $_GET['verification_token'], $_GET['id'])) {
    require_once '../conn.php';
    $chk_id = (int) $_GET['id'];

    if ($chk_id > 0) {
        $chk_stmt = mysqli_prepare($conn,
            "SELECT reference_number, issued_at FROM gate_passes WHERE id = ? LIMIT 1");
        mysqli_stmt_bind_param($chk_stmt, 'i', $chk_id);
        mysqli_stmt_execute($chk_stmt);
        $chk_row = mysqli_fetch_assoc(mysqli_stmt_get_result($chk_stmt));
        mysqli_stmt_close($chk_stmt);

        if ($chk_row) {
            $expected_chk = hash_hmac('sha256',
                $chk_row['reference_number'] . '|' . $chk_row['issued_at'],
                GATE_PASS_SECRET_KEY
            );
            if (hash_equals($expected_chk, $_GET['verification_token'])) {
                $is_verified_access = true;
            }
        }
    }
}

if (!$is_verified_access) {
    require_once '../auth.php';
    require_once '../tracking.php';
    $tracker->trackAction('Print student gatepass');
}

if (!isset($conn)) {
    require_once '../conn.php';
}

// ── 2. Dynamic base URL (works across all school deployments) ──────────────
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$base_url = $protocol . '://' . $_SERVER['HTTP_HOST'];

/**
 * Converts a stored path to a fully-qualified URL.
 * Handles both relative paths (uploads/…) and already-absolute URLs (https://…).
 */
function to_absolute_url(string $path, string $base): string {
    if (empty($path)) return '';
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

// ── 3. Validate gate pass ID ───────────────────────────────────────────────
if (empty($_GET['id'])) {
    http_response_code(400);
    die('Error: No Gate Pass ID specified.');
}
$id = (int) $_GET['id'];
if ($id <= 0) {
    http_response_code(400);
    die('Error: Invalid Gate Pass ID.');
}

// ── 4. Fetch gate pass ─────────────────────────────────────────────────────
$stmt_pass = mysqli_prepare($conn, "SELECT * FROM gate_passes WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt_pass, 'i', $id);
mysqli_stmt_execute($stmt_pass);
$pass = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_pass));
mysqli_stmt_close($stmt_pass);

if (!$pass) {
    http_response_code(404);
    die('Error: Gate Pass not found.');
}

// ── 5. Fetch student profile photo ─────────────────────────────────────────
$student_photo_url = '';
if (!empty($pass['student_id'])) {
    $stmt_stu = mysqli_prepare($conn,
        "SELECT profile_photo FROM students WHERE student_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt_stu, 's', $pass['student_id']);
    mysqli_stmt_execute($stmt_stu);
    $stu_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_stu));
    mysqli_stmt_close($stmt_stu);

    if ($stu_row && !empty($stu_row['profile_photo'])) {
        $student_photo_url = to_absolute_url($stu_row['profile_photo'], $base_url);
    }
}

// ── 6. Fetch school profile ────────────────────────────────────────────────
$school = [
    'school_name'  => '',
    'school_motto' => '',
    'logo_path'    => '',
    'address'      => '',
    'phone'        => '',
    'email'        => '',
    'website'      => '',
];
$res_school = mysqli_query($conn, "SELECT * FROM school_profile ORDER BY id LIMIT 1");
if ($res_school) {
    $row_school = mysqli_fetch_assoc($res_school);
    if ($row_school) {
        $school = array_merge($school, $row_school);
    }
}
$logo_url = to_absolute_url($school['logo_path'], $base_url);

// ── 7. Build secure verification URL ──────────────────────────────────────
$script_dir     = dirname($_SERVER['SCRIPT_NAME']);
$root_path      = str_replace('/api', '', $script_dir);
$verify_base    = $base_url . rtrim($root_path, '/');

$verification_token = hash_hmac('sha256',
    $pass['reference_number'] . '|' . $pass['issued_at'],
    GATE_PASS_SECRET_KEY
);
$verification_url = $verify_base . '/verify_gate_pass.php'
    . '?pass_id=' . rawurlencode($pass['reference_number'])
    . '&token='   . rawurlencode($verification_token);

// Safe to embed in JS via json_encode (handles all escaping)
$verification_url_js = json_encode($verification_url);

// ── 8. Helpers ─────────────────────────────────────────────────────────────
function esc(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function fmt_date(string $dt): string {
    return date('d M Y, h:i A', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gate Pass &mdash; <?= esc($pass['reference_number']) ?></title>

    <script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs/qrcode.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* ── Reset & Base ────────────────────────────── */
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Sen', sans-serif !important;
        }

        body {
            background: #dce1e8;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
            padding: 40px 20px;
        }

        /* ── A4 Sheet ────────────────────────────────── */
        .a4-sheet {
            background: #ffffff;
            width: 210mm;
            min-height: 297mm;
            padding: 12mm 14mm;
            box-shadow: 0 10px 40px rgba(0,0,0,.18);
            position: relative;
            overflow: hidden;
        }

        /* Watermark */
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            opacity: .045;
            pointer-events: none;
            z-index: 0;
        }
        .watermark img { width: 320px; }

        /* ── Content wrapper ─────────────────────────── */
        .pass-content {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        /* ── Header ──────────────────────────────────── */
        .pass-header {
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 14px;
            border-bottom: 3px double #145a32;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }

        .logo-box img {
            width: 75px;
            height: 75px;
            object-fit: contain;
            border-radius: 4px;
        }
        .logo-placeholder {
            width: 75px;
            height: 75px;
            background: #e8f5e9;
            border: 2px dashed #1e8449;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1e8449;
            font-size: 22px;
        }

        .school-info { text-align: center; }
        .school-name {
            font-size: 21px;
            font-weight: 800;
            color: #145a32;
            line-height: 1.2;
            white-space: pre-line;
        }
        .school-motto {
            font-size: 11.5px;
            color: #666;
            font-style: italic;
            margin: 3px 0 6px;
        }
        .school-contact {
            font-size: 10.5px;
            color: #555;
            line-height: 1.7;
        }
        .school-contact i { color: #1e8449; margin-right: 3px; }

        /* ── Student Photo (passport style) ──────────── */
        .photo-box {
            text-align: center;
        }
        .student-photo {
            width: 85px;
            height: 100px;
            object-fit: cover;
            border: 3px solid #1e8449;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
            display: block;
        }
        .photo-placeholder {
            width: 85px;
            height: 100px;
            background: #f0f4f0;
            border: 3px solid #1e8449;
            border-radius: 4px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #aaa;
            font-size: 10px;
            gap: 4px;
        }
        .photo-placeholder i { font-size: 26px; }
        .photo-label {
            font-size: 9px;
            color: #888;
            text-align: center;
            margin-top: 3px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        /* ── Pass Title ──────────────────────────────── */
        .pass-title {
            text-align: center;
            margin-bottom: 10px;
        }
        .pass-title h2 {
            display: inline-block;
            background: linear-gradient(135deg, #1e8449, #145a32);
            color: #fff;
            padding: 6px 30px;
            border-radius: 5px;
            font-size: 17px;
            font-weight: 800;
            letter-spacing: 3px;
            text-transform: uppercase;
        }
        .ref-line {
            font-size: 11px;
            color: #888;
            margin-top: 4px;
        }
        .ref-line strong { color: #145a32; }

        /* ── Details Grid ────────────────────────────── */
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 20px;
            font-size: 13px;
            flex: 1;
        }

        .detail-item {
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 5px;
        }
        .detail-item strong {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #145a32;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 2px;
        }
        .detail-item strong i { font-size: 9px; }
        .detail-item span { color: #333; font-size: 12.5px; }

        .full-width { grid-column: 1 / -1; }

        .reason-box {
            background: #f7faf7;
            padding: 8px 10px;
            border-left: 3px solid #1e8449;
            border-radius: 0 4px 4px 0;
        }

        /* Status pill */
        .status-pill {
            display: inline-block;
            padding: 2px 9px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: capitalize;
        }
        .status-active   { background:#e8f5e9; color:#1b5e20; }
        .status-returned { background:#e3f2fd; color:#0d47a1; }
        .status-overdue  { background:#fff8e1; color:#e65100; }
        .status-cancelled{ background:#ffebee; color:#b71c1c; }

        /* Priority pill */
        .priority-pill {
            display: inline-block;
            padding: 2px 9px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: capitalize;
        }
        .priority-normal    { background:#e3f2fd; color:#1565c0; }
        .priority-urgent    { background:#fff3e0; color:#e65100; }
        .priority-emergency { background:#ffebee; color:#b71c1c; }

        /* ── Footer ──────────────────────────────────── */
        .pass-footer {
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid #ccc;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .signature-area { display: flex; gap: 30px; }
        .sig-box { text-align: center; width: 130px; }
        .sig-line {
            border-bottom: 1px solid #444;
            height: 32px;
            margin-bottom: 4px;
        }
        .sig-box small {
            font-size: 10px;
            color: #555;
            display: block;
        }
        .sig-box strong { font-size: 11px; color: #333; }

        /* QR Section */
        .qr-section { text-align: center; }
        #qrcode {
            width: 90px;
            height: 90px;
            margin: 0 auto 4px;
            border: 3px solid #fff;
            box-shadow: 0 0 6px rgba(0,0,0,.12);
        }
        #qrcode canvas, #qrcode img {
            width: 100% !important;
            height: 100% !important;
        }
        .qr-label { font-size: 9px; color: #888; }

        /* Notice strip */
        .notice-strip {
            margin-top: 10px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 6px 10px;
            font-size: 9.5px;
            color: #666;
            text-align: center;
        }
        .notice-strip strong { color: #145a32; }

        /* ── Print Button ────────────────────────────── */
        .print-button-container {
            position: fixed;
            top: 20px;
            right: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 100;
        }
        .btn-print, .btn-back {
            padding: 12px 22px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 700;
            transition: all .25s;
            box-shadow: 0 4px 15px rgba(0,0,0,.2);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-print { background: #1e8449; color: #fff; }
        .btn-print:hover { background: #145a32; }
        .btn-back  { background: #fff; color: #333; border: 1px solid #ddd; }
        .btn-back:hover { background: #f5f5f5; }

        /* ── Print media ─────────────────────────────── */
        @media print {
            @page { size: A4; margin: 0; }
            body { background: #fff; padding: 0; }
            .a4-sheet {
                box-shadow: none;
                width: 210mm;
                min-height: 297mm;
                padding: 12mm 14mm;
            }
            .print-button-container { display: none !important; }
            .watermark { opacity: .06 !important; }
        }
    </style>
</head>
<body>

<div class="a4-sheet">

    <!-- Watermark -->
    <div class="watermark">
        <?php if ($logo_url): ?>
            <img src="<?= esc($logo_url) ?>" alt="">
        <?php endif; ?>
    </div>

    <div class="pass-content">

        <!-- ── Header ─────────────────────────────────────── -->
        <div class="pass-header">

            <!-- School Logo -->
            <div class="logo-box">
                <?php if ($logo_url): ?>
                    <img src="<?= esc($logo_url) ?>"
                         alt="<?= esc($school['school_name']) ?> Logo"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div class="logo-placeholder" style="display:none">
                        <i class="fas fa-school"></i>
                    </div>
                <?php else: ?>
                    <div class="logo-placeholder"><i class="fas fa-school"></i></div>
                <?php endif; ?>
            </div>

            <!-- School Info -->
            <div class="school-info">
                <div class="school-name"><?= esc($school['school_name']) ?></div>
                <?php if ($school['school_motto']): ?>
                    <div class="school-motto">&ldquo;<?= esc($school['school_motto']) ?>&rdquo;</div>
                <?php endif; ?>
                <div class="school-contact">
                    <?php if ($school['address']): ?>
                        <span><i class="fas fa-map-marker-alt"></i><?= esc($school['address']) ?></span>&nbsp;&nbsp;
                    <?php endif; ?>
                    <?php if ($school['phone']): ?>
                        <span><i class="fas fa-phone"></i><?= esc($school['phone']) ?></span>&nbsp;&nbsp;
                    <?php endif; ?>
                    <?php if ($school['email']): ?>
                        <span><i class="fas fa-envelope"></i><?= esc($school['email']) ?></span>
                    <?php endif; ?>
                    <?php if ($school['website']): ?>
                        <br><span><i class="fas fa-globe"></i><?= esc($school['website']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Student Photo -->
            <div class="photo-box">
                <?php if ($student_photo_url): ?>
                    <img src="<?= esc($student_photo_url) ?>"
                         alt="Student Photo"
                         class="student-photo"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div class="photo-placeholder" style="display:none">
                        <i class="fas fa-user"></i>
                        <span>No Photo</span>
                    </div>
                <?php else: ?>
                    <div class="photo-placeholder">
                        <i class="fas fa-user"></i>
                        <span>No Photo</span>
                    </div>
                <?php endif; ?>
                <div class="photo-label">Student Photo</div>
            </div>

        </div>
        <!-- /Header -->

        <!-- ── Pass Title ──────────────────────────────────── -->
        <div class="pass-title">
            <h2><i class="fas fa-id-card"></i>&ensp;Student Gate Pass</h2>
            <div class="ref-line">
                Pass ID: <strong><?= esc($pass['reference_number']) ?></strong>
                &nbsp;&bull;&nbsp;
                Issued: <strong><?= fmt_date($pass['issued_at']) ?></strong>
            </div>
        </div>

        <!-- ── Details Grid ────────────────────────────────── -->
        <div class="details-grid">

            <div class="detail-item">
                <strong><i class="fas fa-id-badge"></i>Student ID</strong>
                <span><?= esc($pass['student_id']) ?></span>
            </div>

            <div class="detail-item full-width">
                <strong><i class="fas fa-user"></i>Student Name</strong>
                <span><?= esc($pass['student_name']) ?></span>
            </div>

            <div class="detail-item">
                <strong><i class="fas fa-chalkboard"></i>Class</strong>
                <span><?= esc($pass['class']) ?></span>
            </div>

            <div class="detail-item">
                <strong><i class="fas fa-layer-group"></i>Stream</strong>
                <span><?= esc($pass['stream']) ?></span>
            </div>

            <div class="detail-item">
                <strong><i class="fas fa-sign-out-alt"></i>Departure Time</strong>
                <span><?= fmt_date($pass['departure_time']) ?></span>
            </div>

            <div class="detail-item">
                <strong><i class="fas fa-sign-in-alt"></i>Expected Return</strong>
                <span><?= fmt_date($pass['expected_return']) ?></span>
            </div>

            <div class="detail-item">
                <strong><i class="fas fa-map-pin"></i>Destination</strong>
                <span><?= esc($pass['destination']) ?></span>
            </div>

            <div class="detail-item">
                <strong><i class="fas fa-flag"></i>Priority</strong>
                <span>
                    <?php
                    $p_class = match(strtolower($pass['priority'])) {
                        'urgent'    => 'priority-urgent',
                        'emergency' => 'priority-emergency',
                        default     => 'priority-normal',
                    };
                    ?>
                    <span class="priority-pill <?= $p_class ?>">
                        <?= esc(ucfirst($pass['priority'])) ?>
                    </span>
                </span>
            </div>

            <div class="detail-item">
                <strong><i class="fas fa-mobile-alt"></i>Parent Contact</strong>
                <span><?= esc($pass['parent_contact'] ?: 'N/A') ?></span>
            </div>

            <div class="detail-item">
                <strong><i class="fas fa-phone"></i>Student Contact</strong>
                <span><?= esc($pass['student_contact'] ?: 'N/A') ?></span>
            </div>

            <div class="detail-item">
                <strong><i class="fas fa-user-friends"></i>Accompanying Person</strong>
                <span><?= esc($pass['accompanying_person'] ?: 'N/A') ?></span>
            </div>

            <div class="detail-item">
                <strong><i class="fas fa-info-circle"></i>Status</strong>
                <span>
                    <?php
                    $s_class = match(strtolower($pass['status'])) {
                        'returned'  => 'status-returned',
                        'overdue'   => 'status-overdue',
                        'cancelled' => 'status-cancelled',
                        default     => 'status-active',
                    };
                    ?>
                    <span class="status-pill <?= $s_class ?>">
                        <?= esc(ucfirst($pass['status'])) ?>
                    </span>
                </span>
            </div>

            <div class="detail-item full-width reason-box">
                <strong><i class="fas fa-comment-alt"></i>Reason for Leaving</strong>
                <span><?= nl2br(esc($pass['reason'])) ?></span>
            </div>

        </div>
        <!-- /Details Grid -->

        <!-- ── Footer ──────────────────────────────────────── -->
        <div class="pass-footer">
            <div class="signature-area">
                <div class="sig-box">
                    <div class="sig-line"></div>
                    <strong>Issued By</strong>
                    <small><?= esc($pass['issued_by']) ?></small>
                </div>
                <div class="sig-box">
                    <div class="sig-line"></div>
                    <strong>School Stamp &amp; Signature</strong>
                </div>
                <div class="sig-box">
                    <div class="sig-line"></div>
                    <strong>Security Signature</strong>
                </div>
            </div>

            <div class="qr-section">
                <div id="qrcode"></div>
                <div class="qr-label">
                    <i class="fas fa-qrcode"></i> Scan to verify
                </div>
            </div>
        </div>

        <!-- Notice strip -->
        <div class="notice-strip">
            <strong>IMPORTANT:</strong> This pass is only valid for the date and time stated above.
            Any alteration renders this pass invalid. Report suspicious passes to school administration immediately.
        </div>

    </div><!-- /pass-content -->
</div><!-- /a4-sheet -->

<!-- Print controls (hidden on print) -->
<div class="print-button-container">
    <button class="btn-print" onclick="window.print()">
        <i class="fas fa-print"></i> Print Gate Pass
    </button>
    <button class="btn-back" onclick="window.history.back()">
        <i class="fas fa-arrow-left"></i> Back
    </button>
</div>

<script>
// json_encode produces a safely escaped JS string — no XSS risk
new QRCode(document.getElementById('qrcode'), {
    text:         <?= $verification_url_js ?>,
    width:        110,
    height:       110,
    colorDark:    '#000000',
    colorLight:   '#ffffff',
    correctLevel: QRCode.CorrectLevel.H   // Highest error correction — stays readable if partially covered
});
</script>
</body>
</html>
