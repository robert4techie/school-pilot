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

function to_absolute_url(string $path, string $base): string {
    if (empty($path)) return '';
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) return $path;
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

// ── 3. Validate gate pass ID ───────────────────────────────────────────────
if (empty($_GET['id'])) { http_response_code(400); die('Error: No Gate Pass ID specified.'); }
$id = (int) $_GET['id'];
if ($id <= 0)            { http_response_code(400); die('Error: Invalid Gate Pass ID.'); }

// ── 4. Fetch gate pass ─────────────────────────────────────────────────────
$stmt_pass = mysqli_prepare($conn, "SELECT * FROM gate_passes WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt_pass, 'i', $id);
mysqli_stmt_execute($stmt_pass);
$pass = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_pass));
mysqli_stmt_close($stmt_pass);

if (!$pass) { http_response_code(404); die('Error: Gate Pass not found.'); }

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
$school = ['school_name'=>'','school_motto'=>'','logo_path'=>'',
           'address'=>'','phone'=>'','email'=>'','website'=>''];
$res_school = mysqli_query($conn, "SELECT * FROM school_profile ORDER BY id LIMIT 1");
if ($res_school) {
    $row_school = mysqli_fetch_assoc($res_school);
    if ($row_school) $school = array_merge($school, $row_school);
}
$logo_url = to_absolute_url($school['logo_path'], $base_url);

// ── 7. Build secure verification URL ──────────────────────────────────────
// AFTER (fixed):
// Both files live in /api/ — verification URL must include /api/
$script_dir  = dirname($_SERVER['SCRIPT_NAME']); // '/api'
$verify_base = $base_url . rtrim($script_dir, '/'); // 'https://ou-schoolpilot.org/api'

$verification_token = hash_hmac('sha256',
    $pass['reference_number'] . '|' . $pass['issued_at'],
    GATE_PASS_SECRET_KEY
);
$verification_url    = $verify_base . '/verify_gate_pass.php'
    . '?pass_id=' . rawurlencode($pass['reference_number'])
    . '&token='   . rawurlencode($verification_token);
$verification_url_js = json_encode($verification_url);

// Logo URL safe for CSS background-image (json_encode gives a double-quoted string)
$logo_css_url = $logo_url ? 'url(' . json_encode($logo_url) . ')' : 'none';

// ── 8. Helpers ─────────────────────────────────────────────────────────────
function esc(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function fmt_date(string $dt): string {
    return date('d M Y,  h:i A', strtotime($dt));
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
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
                        font-family: 'Sen', sans-serif !important;
            background: #d0d5dd;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
            padding: 40px 20px;
        }

        /* ══ A4 Sheet ═════════════════════════════════════════ */
        .a4-sheet {
            background: #ffffff;
            width: 210mm;
            height: 297mm;           /* Fixed A4 — content must fill this exactly */
            padding: 10mm 13mm;
            box-shadow: 0 12px 50px rgba(0,0,0,.22);
            position: relative;
            overflow: hidden;
        }

        /* ══ Tiled Watermark ─ many small logos, like official documents ══ */
        .watermark-tile {
            position: absolute;
            /* Extend beyond sheet so diagonal tiles fill every corner */
            top:    -30%;
            left:   -30%;
            width:  160%;
            height: 160%;
            transform: rotate(-28deg);
            opacity: 0.045;
            pointer-events: none;
            z-index: 0;
            background-image: <?= $logo_css_url ?>;
            background-repeat: repeat;
            background-size: 88px 88px;  /* Tile density */
            background-position: 0 0;
        }

        /* ══ Content wrapper ══════════════════════════════════ */
        .pass-content {
            position: relative;
            z-index: 2;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        /* ══ Header ═══════════════════════════════════════════ */
        .pass-header {
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 16px;
            border-bottom: 3px double #145a32;
            padding-bottom: 12px;
            margin-bottom: 11px;
        }

        /* School Logo */
        .logo-box {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .logo-box img {
            width: 108px;
            height: 108px;
            object-fit: contain;
            border-radius: 6px;
            filter: drop-shadow(0 2px 8px rgba(0,0,0,.18));
        }
        .logo-placeholder {
            width: 108px;
            height: 108px;
            background: #e8f5e9;
            border: 2px dashed #1e8449;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1e8449;
            font-size: 30px;
        }

        /* School Info */
        .school-info { text-align: center; }
        .school-name {
            font-size: 22px;
            font-weight: 800;
            color: #145a32;
            line-height: 1.25;
            white-space: pre-line;
            letter-spacing: 0.3px;
        }
        .school-motto {
            font-size: 12px;
            color: #555;
            font-style: italic;
            margin: 5px 0 9px;
        }
        .school-contact {
            font-size: 11px;
            color: #444;
            line-height: 1.95;
        }
        .school-contact span { margin: 0 5px; white-space: nowrap; }
        .school-contact i { color: #1e8449; margin-right: 4px; }

        /* Student Photo — passport format */
        .photo-box { text-align: center; }
        .student-photo {
            width: 102px;
            height: 124px;
            object-fit: cover;
            border: 3px solid #1e8449;
            border-radius: 5px;
            box-shadow: 0 3px 12px rgba(0,0,0,.2);
            display: block;
        }
        .photo-placeholder {
            width: 102px;
            height: 124px;
            background: #f0f7f0;
            border: 3px solid #1e8449;
            border-radius: 5px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #aaa;
            gap: 5px;
        }
        .photo-placeholder i { font-size: 30px; }
        .photo-placeholder span { font-size: 9.5px; }
        .photo-label {
            font-size: 9px;
            color: #777;
            text-align: center;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: .7px;
            font-weight: 700;
        }

        /* ══ Pass Title ═══════════════════════════════════════ */
        .pass-title { text-align: center; margin-bottom: 11px; }
        .pass-title h2 {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, #1e8449, #145a32);
            color: #fff;
            padding: 8px 36px;
            border-radius: 6px;
            font-size: 18px;
            font-weight: 800;
            letter-spacing: 3.5px;
            text-transform: uppercase;
            box-shadow: 0 3px 10px rgba(30,132,73,.25);
        }
        .ref-line {
            font-size: 11.5px;
            color: #777;
            margin-top: 6px;
        }
        .ref-line strong { color: #145a32; }

        /* ══ Details Grid ─ bordered cells, fills remaining space ══════ */
        .details-grid {
            flex: 1;                       /* Grows to fill space between title and footer */
            display: grid;
            grid-template-columns: 1fr 1fr;
            border: 1.5px solid #c8e6c9;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 12px;
        }

        .detail-item {
            padding: 11px 14px;
            border-bottom: 1px solid #e8f5e9;
            border-right: 1px solid #e8f5e9;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        /* Remove right border from right-column cells */
        .detail-item:nth-child(even):not(.full-width) { border-right: none; }

        .detail-item strong {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #145a32;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 3px;
        }
        .detail-item strong i { font-size: 9px; opacity: .8; }
        .detail-item span {
            color: #222;
            font-size: 13.5px;
            font-weight: 600;
        }

        .full-width { grid-column: 1 / -1; border-right: none !important; }

        /* Alternating zebra rows for readability */
        .detail-item:nth-child(4n+1),
        .detail-item:nth-child(4n+2) {
            background: #fafcfa;
        }

        /* Reason box */
        .reason-box { background: #f2faf2 !important; }
        .reason-box span {
            font-size: 13px !important;
            line-height: 1.65;
        }

        /* Status & Priority pills */
        .status-pill, .priority-pill {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
        }
        .status-active    { background:#e8f5e9; color:#1b5e20; }
        .status-returned  { background:#e3f2fd; color:#0d47a1; }
        .status-overdue   { background:#fff8e1; color:#e65100; }
        .status-cancelled { background:#ffebee; color:#b71c1c; }
        .priority-normal    { background:#e3f2fd; color:#1565c0; }
        .priority-urgent    { background:#fff3e0; color:#e65100; }
        .priority-emergency { background:#ffebee; color:#b71c1c; }

        /* ══ Footer: Signatures + QR ══════════════════════════ */
        .pass-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            padding-top: 12px;
            border-top: 1.5px solid #c8e6c9;
            gap: 14px;
        }

        .signature-area { display: flex; gap: 18px; flex: 1; }
        .sig-box { text-align: center; flex: 1; }
        .sig-line {
            border-bottom: 1.5px solid #444;
            height: 40px;
            margin-bottom: 6px;
        }
        .sig-box strong { font-size: 11px; color: #333; display: block; line-height: 1.35; }
        .sig-box small  { font-size: 10.5px; color: #666; display: block; margin-top: 2px; }

        /* QR Code — large for reliable scanning */
        .qr-section { text-align: center; flex-shrink: 0; }
        #qrcode {
            width: 130px;
            height: 130px;
            margin: 0 auto 6px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        #qrcode canvas, #qrcode img {
            width: 100% !important;
            height: 100% !important;
            display: block;
        }
        .qr-label {
            font-size: 10px;
            color: #555;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        /* ══ Notice Strip ═════════════════════════════════════ */
        .notice-strip {
            margin-top: 10px;
            background: linear-gradient(135deg, #f0faf0, #e8f5e9);
            border: 1px solid #a5d6a7;
            border-left: 4px solid #1e8449;
            border-radius: 4px;
            padding: 8px 13px;
            font-size: 10px;
            color: #2e7d32;
            text-align: center;
            line-height: 1.65;
        }
        .notice-strip strong { color: #1b5e20; }

        /* ══ Print Controls ══════════════════════════════════ */
        .print-controls {
            position: fixed;
            top: 20px;
            right: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 999;
        }
        .btn-print, .btn-back {
            padding: 13px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 700;
            transition: all .2s;
            box-shadow: 0 4px 16px rgba(0,0,0,.2);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-print { background: #1e8449; color: #fff; }
        .btn-print:hover { background: #145a32; transform: translateY(-1px); }
        .btn-back  { background: #fff; color: #333; border: 1px solid #ddd; }
        .btn-back:hover  { background: #f5f5f5; }

        /* ══ Print Media ══════════════════════════════════════ */
        @media print {
            @page { size: A4; margin: 0; }
            body {
                background: #fff !important;
                padding: 0 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .a4-sheet { box-shadow: none; }
            .print-controls { display: none !important; }
            .watermark-tile { opacity: .05 !important; }
        }
    </style>
</head>
<body>

<div class="a4-sheet">

    <!-- Tiled watermark: many small logos like official documents -->
    <?php if ($logo_url): ?>
        <div class="watermark-tile" aria-hidden="true"></div>
    <?php endif; ?>

    <div class="pass-content">

        <!-- ── Header ──────────────────────────────────────── -->
        <div class="pass-header">

            <!-- Left: School Logo (large & clear) -->
            <div class="logo-box">
                <?php if ($logo_url): ?>
                    <img src="<?= esc($logo_url) ?>"
                         alt="<?= esc($school['school_name']) ?> Logo"
                         onerror="this.style.display='none';document.getElementById('logo-ph').style.display='flex'">
                    <div id="logo-ph" class="logo-placeholder" style="display:none">
                        <i class="fas fa-school"></i>
                    </div>
                <?php else: ?>
                    <div class="logo-placeholder"><i class="fas fa-school"></i></div>
                <?php endif; ?>
            </div>

            <!-- Centre: School name, motto & contact details -->
            <div class="school-info">
                <div class="school-name"><?= esc($school['school_name']) ?></div>
                <?php if ($school['school_motto']): ?>
                    <div class="school-motto">&ldquo;<?= esc($school['school_motto']) ?>&rdquo;</div>
                <?php endif; ?>
                <div class="school-contact">
                    <?php if ($school['address']): ?>
                        <span><i class="fas fa-map-marker-alt"></i><?= esc($school['address']) ?></span>
                    <?php endif; ?>
                    <?php if ($school['phone']): ?>
                        <span><i class="fas fa-phone"></i><?= esc($school['phone']) ?></span>
                    <?php endif; ?>
                    <?php if ($school['email']): ?>
                        <span><i class="fas fa-envelope"></i><?= esc($school['email']) ?></span>
                    <?php endif; ?>
                    <?php if ($school['website']): ?>
                        <span><i class="fas fa-globe"></i><?= esc($school['website']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right: Student passport-style photo -->
            <div class="photo-box">
                <?php if ($student_photo_url): ?>
                    <img src="<?= esc($student_photo_url) ?>"
                         alt="Student Photo"
                         class="student-photo"
                         onerror="this.style.display='none';document.getElementById('photo-ph').style.display='flex'">
                    <div id="photo-ph" class="photo-placeholder" style="display:none">
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

        </div><!-- /pass-header -->

        <!-- ── Title ───────────────────────────────────────── -->
        <div class="pass-title">
            <h2><i class="fas fa-id-card"></i>Student Gate Pass</h2>
            <div class="ref-line">
                Pass ID:&nbsp;<strong><?= esc($pass['reference_number']) ?></strong>
                &nbsp;&bull;&nbsp;
                Issued:&nbsp;<strong><?= fmt_date($pass['issued_at']) ?></strong>
            </div>
        </div>

        <!-- ── Details Grid ─ flex:1 so it fills remaining height ─ -->
        <div class="details-grid">

            <div class="detail-item">
                <strong><i class="fas fa-id-badge"></i>Student ID</strong>
                <span><?= esc($pass['student_id']) ?></span>
            </div>

            <div class="detail-item">
                <strong><i class="fas fa-info-circle"></i>Status</strong>
                <span>
                    <?php
                    $s_class = match(strtolower($pass['status'] ?? '')) {
                        'returned'  => 'status-returned',
                        'overdue'   => 'status-overdue',
                        'cancelled' => 'status-cancelled',
                        default     => 'status-active',
                    };
                    ?>
                    <span class="status-pill <?= $s_class ?>"><?= esc(ucfirst($pass['status'] ?? 'Active')) ?></span>
                </span>
            </div>

            <div class="detail-item full-width">
                <strong><i class="fas fa-user"></i>Student Name</strong>
                <span style="font-size:14.5px"><?= esc($pass['student_name']) ?></span>
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
                    $p_class = match(strtolower($pass['priority'] ?? '')) {
                        'urgent'    => 'priority-urgent',
                        'emergency' => 'priority-emergency',
                        default     => 'priority-normal',
                    };
                    ?>
                    <span class="priority-pill <?= $p_class ?>"><?= esc(ucfirst($pass['priority'] ?? 'Normal')) ?></span>
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

            <div class="detail-item full-width">
                <strong><i class="fas fa-user-friends"></i>Accompanying Person</strong>
                <span><?= esc($pass['accompanying_person'] ?: 'None') ?></span>
            </div>

            <div class="detail-item full-width reason-box">
                <strong><i class="fas fa-comment-alt"></i>Reason for Leaving</strong>
                <span><?= nl2br(esc($pass['reason'])) ?></span>
            </div>

        </div><!-- /details-grid -->

        <!-- ── Footer: Signatures + QR Code ────────────────── -->
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
                    <strong>Security / Gate Signature</strong>
                </div>
            </div>

            <!-- QR Code — 158 px displayed, 180 px generated for sharpness -->
            <div class="qr-section">
                <div id="qrcode"></div>
                <div class="qr-label">
                    <i class="fas fa-qrcode"></i>&ensp;Scan to verify
                </div>
            </div>

        </div><!-- /pass-footer -->

        <!-- ── Notice Strip ────────────────────────────────── -->
        <div class="notice-strip">
            <strong>NOTICE:</strong> This pass is valid only for the date &amp; time stated above.
            Any alteration renders it invalid. Unauthorised possession is an offence.
            Report suspicious passes to school administration immediately.
        </div>

    </div><!-- /pass-content -->
</div><!-- /a4-sheet -->

<!-- Print Controls (hidden when printing) -->
<div class="print-controls">
    <button class="btn-print" onclick="window.print()">
        <i class="fas fa-print"></i> Print Gate Pass
    </button>
    <button class="btn-back" onclick="window.history.back()">
        <i class="fas fa-arrow-left"></i> Back
    </button>
</div>

<script>
// Generated at 180×180 px (sharp canvas); CSS container is 158×158 px
new QRCode(document.getElementById('qrcode'), {
    text:         <?= $verification_url_js ?>,
    width:        180,
    height:       180,
    colorDark:    '#000000',
    colorLight:   '#ffffff',
    correctLevel: QRCode.CorrectLevel.H   // Highest error-correction: 30% damage tolerance
});
</script>
</body>
</html>
