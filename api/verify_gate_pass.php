<?php
/**
 * verify_gate_pass.php
 * Public gate pass verification page — no login required.
 * Accessed via QR code scan. Shows verification status first,
 * then allows the security guard to reveal full pass details.
 */

// ── 0. Bootstrap ───────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Secret key — must match print_gate_pass.php
if (!defined('GATE_PASS_SECRET_KEY')) {
    define('GATE_PASS_SECRET_KEY', getenv('GATE_PASS_SECRET') ?: 'GATEPASS_VERIFICATION_SECRET_2026');
}

// ── 1. Database connection ─────────────────────────────────────────────────
if (!isset($conn)) {
    require_once '../conn.php';
}
if (!isset($conn) || !$conn) {
    http_response_code(503);
    die('Service temporarily unavailable. Please try again later.');
}

// ── 2. Dynamic base URL ────────────────────────────────────────────────────
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$base_url = $protocol . '://' . $_SERVER['HTTP_HOST'];

function to_abs(string $path, string $base): string {
    if (empty($path)) return '';
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) return $path;
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}
function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── 3. Validate required parameters ───────────────────────────────────────
$pass_id = trim($_GET['pass_id'] ?? '');
$token   = trim($_GET['token']   ?? '');

if (empty($pass_id) || empty($token)) {
    render_error('Invalid Request',
        'This verification link is missing required parameters. '
        . 'Please scan the QR code on the gate pass again.',
        'warning');
    exit;
}

// Strict input: reference numbers must be alphanumeric + hyphens/underscores only
if (!preg_match('/^[A-Za-z0-9\-_]+$/', $pass_id) || strlen($pass_id) > 64) {
    render_error('Invalid Request', 'The gate pass reference number format is invalid.', 'warning');
    exit;
}
// Token must be a 64-char hex string (SHA-256 HMAC output)
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    render_error('Invalid Token', 'The verification token format is invalid.', 'danger');
    exit;
}

// ── 4. Fetch gate pass ─────────────────────────────────────────────────────
$stmt = mysqli_prepare($conn,
    "SELECT * FROM gate_passes WHERE reference_number = ? LIMIT 1");
if (!$stmt) {
    render_error('System Error', 'Unable to process your request. Please try again.', 'warning');
    exit;
}
mysqli_stmt_bind_param($stmt, 's', $pass_id);
mysqli_stmt_execute($stmt);
$gate_pass = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$gate_pass) {
    render_not_found($pass_id);
    exit;
}

// ── 5. Verify HMAC token (timing-safe comparison) ─────────────────────────
$expected_token = hash_hmac('sha256',
    $gate_pass['reference_number'] . '|' . $gate_pass['issued_at'],
    GATE_PASS_SECRET_KEY
);
if (!hash_equals($expected_token, $token)) {
    render_tampered($pass_id);
    exit;
}

// ── 6. Fetch school profile ────────────────────────────────────────────────
$school = ['school_name' => 'School', 'school_motto' => '', 'logo_path' => '',
           'address' => '', 'phone' => '', 'email' => '', 'website' => ''];
$rs = mysqli_query($conn, "SELECT * FROM school_profile ORDER BY id LIMIT 1");
if ($rs) {
    $row = mysqli_fetch_assoc($rs);
    if ($row) $school = array_merge($school, $row);
}
$logo_url = to_abs($school['logo_path'], $base_url);

// ── 7. Fetch student profile photo ─────────────────────────────────────────
$student_photo_url = '';
if (!empty($gate_pass['student_id'])) {
    $stmt_s = mysqli_prepare($conn,
        "SELECT profile_photo FROM students WHERE student_id = ? LIMIT 1");
    if ($stmt_s) {
        mysqli_stmt_bind_param($stmt_s, 's', $gate_pass['student_id']);
        mysqli_stmt_execute($stmt_s);
        $s_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_s));
        mysqli_stmt_close($stmt_s);
        if ($s_row && !empty($s_row['profile_photo'])) {
            $student_photo_url = to_abs($s_row['profile_photo'], $base_url);
        }
    }
}

mysqli_close($conn);

// ── 8. Determine pass status presentation ─────────────────────────────────
$status = strtolower(trim($gate_pass['status']));

$status_map = [
    'active'    => ['class' => 'valid',    'icon' => 'fa-shield-check',       'color' => '#1e8449',
                    'title' => 'Gate Pass Verified',
                    'msg'   => 'This gate pass is authentic and currently active. The student is authorised to travel.'],
    'returned'  => ['class' => 'info',     'icon' => 'fa-check-circle',       'color' => '#1565c0',
                    'title' => 'Student Has Returned',
                    'msg'   => 'This gate pass is valid and the student has already returned to school.'],
    'overdue'   => ['class' => 'warning',  'icon' => 'fa-exclamation-triangle','color' => '#e65100',
                    'title' => 'Gate Pass Overdue',
                    'msg'   => 'This gate pass is authentic but the student has exceeded their expected return time. Please notify school administration.'],
    'cancelled' => ['class' => 'invalid',  'icon' => 'fa-ban',                'color' => '#b71c1c',
                    'title' => 'Gate Pass Cancelled',
                    'msg'   => 'This gate pass has been officially cancelled and is no longer valid. Do not permit exit on this pass.'],
];
$s = $status_map[$status] ?? ['class' => 'valid', 'icon' => 'fa-check-circle', 'color' => '#1e8449',
                               'title' => 'Gate Pass Verified',
                               'msg'   => 'This gate pass has been successfully verified against school records.'];

$priority = strtolower(trim($gate_pass['priority'] ?? 'normal'));
$priority_map = [
    'normal'    => ['label' => 'Normal',    'bg' => '#e3f2fd', 'color' => '#1565c0'],
    'urgent'    => ['label' => 'Urgent',    'bg' => '#fff3e0', 'color' => '#e65100'],
    'emergency' => ['label' => 'Emergency', 'bg' => '#ffebee', 'color' => '#b71c1c'],
];
$p = $priority_map[$priority] ?? $priority_map['normal'];

$verification_id   = 'VRF-' . strtoupper(bin2hex(random_bytes(4)));
$verification_time = date('l, F j, Y \a\t g:i:s A');

// ── 9. Build print URL for "View Full Gate Pass" button ────────────────────
$script_dir  = dirname($_SERVER['SCRIPT_NAME']);
$print_token = hash_hmac('sha256',
    $gate_pass['reference_number'] . '|' . $gate_pass['issued_at'],
    GATE_PASS_SECRET_KEY
);
// AFTER (fixed — both files are already in /api/):
$print_url = $protocol . '://' . $_SERVER['HTTP_HOST']
           . rtrim($script_dir, '/') . '/print_gate_pass.php'
           . '?id=' . urlencode($gate_pass['id'])
           . '&verified=1'
           . '&verification_token=' . urlencode($print_token);

// ── 10. Render ─────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gate Pass Verification &mdash; <?= e($gate_pass['reference_number']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --green:        #1e8449;
            --green-dark:   #145a32;
            --green-light:  #e8f5e9;
            --text:         #1a1a1a;
            --text-med:     #555;
            --text-light:   #888;
            --border:       #e0e0e0;
            --bg:           #f0f4f0;
            --white:        #ffffff;
            --radius:       12px;
            --shadow:       0 4px 24px rgba(0,0,0,.1);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Sen', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            padding: 20px 16px 40px;
            color: var(--text);
        }

        /* ── Card ────────────────────────────────────── */
        .card {
            max-width: 680px;
            margin: 0 auto;
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            animation: rise .5s ease both;
        }
        @keyframes rise {
            from { opacity:0; transform:translateY(24px); }
            to   { opacity:1; transform:translateY(0); }
        }

        /* ── School Header ───────────────────────────── */
        .school-header {
            background: linear-gradient(135deg, var(--green) 0%, var(--green-dark) 100%);
            color: var(--white);
            padding: 28px 24px 22px;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .school-logo-wrap {
            width: 68px;
            height: 68px;
            background: rgba(255,255,255,.15);
            border-radius: 50%;
            padding: 8px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .school-logo-wrap img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
        }
        .school-logo-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            opacity: .8;
        }
        .school-text h1 {
            font-size: 17px;
            font-weight: 800;
            line-height: 1.25;
            white-space: pre-line;
        }
        .school-text p {
            font-size: 11.5px;
            opacity: .85;
            margin-top: 3px;
            font-style: italic;
        }
        .school-contact-bar {
            font-size: 10.5px;
            opacity: .8;
            margin-top: 4px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .school-contact-bar span i { margin-right: 3px; }

        /* ── Status Banner ───────────────────────────── */
        .status-banner {
            padding: 28px 24px;
            display: flex;
            align-items: flex-start;
            gap: 18px;
            border-bottom: 1px solid var(--border);
        }
        .status-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: var(--white);
            font-size: 26px;
        }
        .status-text h2 { font-size: 22px; font-weight: 800; margin-bottom: 6px; }
        .status-text p  { font-size: 13.5px; color: var(--text-med); line-height: 1.55; }

        .status-valid   .status-icon { background: #1e8449; }
        .status-valid   .status-text h2 { color: #1b5e20; }
        .status-info    .status-icon { background: #1565c0; }
        .status-info    .status-text h2 { color: #0d47a1; }
        .status-warning .status-icon { background: #e65100; }
        .status-warning .status-text h2 { color: #bf360c; }
        .status-invalid .status-icon { background: #b71c1c; }
        .status-invalid .status-text h2 { color: #b71c1c; }

        /* ── Student Summary (always visible) ────────── */
        .student-summary {
            padding: 20px 24px;
            display: flex;
            align-items: center;
            gap: 18px;
            border-bottom: 1px solid var(--border);
            background: #fafafa;
        }
        .student-photo-wrap {
            flex-shrink: 0;
            position: relative;
        }
        .student-photo-wrap img,
        .student-photo-placeholder {
            width: 80px;
            height: 95px;
            border-radius: 6px;
            object-fit: cover;
            border: 3px solid var(--green);
            box-shadow: 0 2px 8px rgba(0,0,0,.12);
        }
        .student-photo-placeholder {
            background: #e8f5e9;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--green);
            font-size: 10px;
            gap: 4px;
        }
        .student-photo-placeholder i { font-size: 28px; }
        .photo-verified-badge {
            position: absolute;
            bottom: -5px;
            right: -5px;
            width: 22px;
            height: 22px;
            background: #1e8449;
            border-radius: 50%;
            border: 2px solid var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 10px;
        }
        .student-meta h3 { font-size: 20px; font-weight: 800; }
        .student-meta .id-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--green-light);
            color: var(--green-dark);
            border: 1px solid #a5d6a7;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 700;
            padding: 3px 10px;
            margin: 5px 0 8px;
        }
        .student-meta .quick-info {
            font-size: 12.5px;
            color: var(--text-med);
            line-height: 1.7;
        }
        .student-meta .quick-info span { margin-right: 14px; }
        .student-meta .quick-info i { color: var(--green); margin-right: 4px; }

        /* ── Priority badge ──────────────────────────── */
        .priority-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }

        /* ── Reveal Button ───────────────────────────── */
        .reveal-section {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
        }
        .btn-reveal {
            width: 100%;
            background: linear-gradient(135deg, var(--green) 0%, var(--green-dark) 100%);
            color: var(--white);
            border: none;
            border-radius: 8px;
            padding: 14px 20px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-family: 'Sen', sans-serif;
            transition: all .25s;
            box-shadow: 0 3px 12px rgba(30,132,73,.3);
        }
        .btn-reveal:hover { background: linear-gradient(135deg, var(--green-dark) 0%, #0d3d10 100%); }
        .btn-reveal .arrow { transition: transform .3s; }
        .btn-reveal.open .arrow { transform: rotate(180deg); }

        /* ── Full Details Panel ──────────────────────── */
        .full-details {
            display: none;
            padding: 0 24px 24px;
            animation: fadeSlide .35s ease both;
        }
        .full-details.open { display: block; }
        @keyframes fadeSlide {
            from { opacity:0; transform:translateY(-10px); }
            to   { opacity:1; transform:translateY(0); }
        }

        .section-title {
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .8px;
            color: var(--green-dark);
            padding: 18px 0 10px;
            border-bottom: 2px solid var(--green-light);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 14px;
        }
        @media (max-width: 480px) { .info-grid { grid-template-columns: 1fr; } }

        .info-item {
            background: #f8faf8;
            border: 1px solid var(--border);
            border-left: 3px solid var(--green);
            border-radius: 6px;
            padding: 12px;
        }
        .info-item.full { grid-column: 1 / -1; }
        .info-label {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--green-dark);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .info-value { font-size: 13.5px; font-weight: 600; color: var(--text); }
        .info-value.reason-text {
            background: var(--white);
            padding: 8px;
            border-radius: 4px;
            border-left: 3px solid var(--green);
            font-size: 13px;
            line-height: 1.6;
        }

        /* Status / priority pills */
        .pill {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 700;
        }
        .pill-valid    { background:#e8f5e9; color:#1b5e20; }
        .pill-info     { background:#e3f2fd; color:#0d47a1; }
        .pill-warning  { background:#fff8e1; color:#e65100; }
        .pill-invalid  { background:#ffebee; color:#b71c1c; }
        .pill-normal   { background:#e3f2fd; color:#1565c0; }
        .pill-urgent   { background:#fff3e0; color:#e65100; }
        .pill-emergency{ background:#ffebee; color:#b71c1c; }

        /* Print gate pass link */
        .btn-print-pass {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            margin-top: 16px;
            padding: 12px;
            background: #f8faf8;
            border: 2px dashed #a5d6a7;
            border-radius: 8px;
            color: var(--green-dark);
            font-size: 13.5px;
            font-weight: 700;
            text-decoration: none;
            transition: all .25s;
        }
        .btn-print-pass:hover {
            background: var(--green-light);
            border-color: var(--green);
        }

        /* ── Verification Footer ─────────────────────── */
        .verify-footer {
            padding: 16px 24px;
            background: #f8faf8;
            border-top: 1px solid var(--border);
            font-size: 11px;
            color: var(--text-light);
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            justify-content: space-between;
            align-items: center;
        }
        .verify-footer strong { color: var(--green-dark); }
        .verified-stamp {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--green-light);
            color: var(--green-dark);
            border: 1px solid #a5d6a7;
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 11px;
            font-weight: 700;
        }
    </style>
</head>
<body>

<div class="card">

    <!-- ── School Header ──────────────────────────────── -->
    <div class="school-header">
        <div class="school-logo-wrap">
            <?php if ($logo_url): ?>
                <img src="<?= e($logo_url) ?>"
                     alt="<?= e($school['school_name']) ?> Logo"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <div class="school-logo-placeholder" style="display:none">
                    <i class="fas fa-school"></i>
                </div>
            <?php else: ?>
                <div class="school-logo-placeholder"><i class="fas fa-school"></i></div>
            <?php endif; ?>
        </div>
        <div class="school-text">
            <h1><?= e($school['school_name']) ?></h1>
            <?php if ($school['school_motto']): ?>
                <p>&ldquo;<?= e($school['school_motto']) ?>&rdquo;</p>
            <?php endif; ?>
            <div class="school-contact-bar">
                <?php if ($school['address']): ?><span><i class="fas fa-map-marker-alt"></i><?= e($school['address']) ?></span><?php endif; ?>
                <?php if ($school['phone']):   ?><span><i class="fas fa-phone"></i><?= e($school['phone']) ?></span><?php endif; ?>
                <?php if ($school['email']):   ?><span><i class="fas fa-envelope"></i><?= e($school['email']) ?></span><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Verification Status Banner ────────────────── -->
    <div class="status-banner status-<?= e($s['class']) ?>">
        <div class="status-icon">
            <i class="fas <?= e($s['icon']) ?>"></i>
        </div>
        <div class="status-text">
            <h2><?= e($s['title']) ?></h2>
            <p><?= e($s['msg']) ?></p>
        </div>
    </div>

    <!-- ── Student Summary (always visible) ──────────── -->
    <div class="student-summary">
        <div class="student-photo-wrap">
            <?php if ($student_photo_url): ?>
                <img src="<?= e($student_photo_url) ?>"
                     alt="<?= e($gate_pass['student_name']) ?>"
                     onerror="this.outerHTML='<div class=\'student-photo-placeholder\'><i class=\'fas fa-user\'></i><span>No Photo</span></div>'">
            <?php else: ?>
                <div class="student-photo-placeholder">
                    <i class="fas fa-user"></i>
                    <span>No Photo</span>
                </div>
            <?php endif; ?>
            <?php if ($s['class'] === 'valid' || $s['class'] === 'info' || $s['class'] === 'warning'): ?>
                <div class="photo-verified-badge" title="Verified">
                    <i class="fas fa-check"></i>
                </div>
            <?php endif; ?>
        </div>

        <div class="student-meta">
            <h3><?= e($gate_pass['student_name']) ?></h3>
            <div class="id-chip">
                <i class="fas fa-id-badge"></i><?= e($gate_pass['student_id']) ?>
            </div>
            <div class="quick-info">
                <span><i class="fas fa-chalkboard"></i><?= e($gate_pass['class']) ?> <?= e($gate_pass['stream']) ?></span>
                <br>
                <span><i class="fas fa-sign-out-alt"></i><?= date('d M Y, h:i A', strtotime($gate_pass['departure_time'])) ?></span>
                <br>
                <span><i class="fas fa-sign-in-alt"></i>Return: <?= date('d M Y, h:i A', strtotime($gate_pass['expected_return'])) ?></span>
                <br>
                <span>
                    <span class="priority-badge" style="background:<?= e($p['bg']) ?>;color:<?= e($p['color']) ?>">
                        <i class="fas fa-flag"></i><?= e($p['label']) ?>
                    </span>
                </span>
            </div>
        </div>
    </div>

    <!-- ── Reveal Button ──────────────────────────────── -->
    <div class="reveal-section">
        <button class="btn-reveal" id="revealBtn" onclick="toggleDetails()" aria-expanded="false">
            <i class="fas fa-eye"></i>
            <span id="revealLabel">View Full Gate Pass Details</span>
            <i class="fas fa-chevron-down arrow" id="revealArrow"></i>
        </button>
    </div>

    <!-- ── Full Details Panel ─────────────────────────── -->
    <div class="full-details" id="fullDetails" aria-hidden="true">

        <!-- Travel Details -->
        <div class="section-title">
            <i class="fas fa-route"></i> Travel Details
        </div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label"><i class="fas fa-hashtag"></i>Pass Reference</div>
                <div class="info-value"><?= e($gate_pass['reference_number']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-info-circle"></i>Status</div>
                <div class="info-value">
                    <?php
                    $sp = match($s['class']) {
                        'info'    => 'pill-info',
                        'warning' => 'pill-warning',
                        'invalid' => 'pill-invalid',
                        default   => 'pill-valid',
                    };
                    ?>
                    <span class="pill <?= $sp ?>"><?= e(ucfirst($gate_pass['status'])) ?></span>
                </div>
            </div>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-map-pin"></i>Destination</div>
                <div class="info-value"><?= e($gate_pass['destination']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-user-friends"></i>Accompanying Person</div>
                <div class="info-value"><?= e($gate_pass['accompanying_person'] ?: 'None') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-sign-out-alt"></i>Departure Time</div>
                <div class="info-value"><?= e(date('d M Y, h:i A', strtotime($gate_pass['departure_time']))) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-sign-in-alt"></i>Expected Return</div>
                <div class="info-value"><?= e(date('d M Y, h:i A', strtotime($gate_pass['expected_return']))) ?></div>
            </div>
            <div class="info-item full">
                <div class="info-label"><i class="fas fa-comment-alt"></i>Reason for Leaving</div>
                <div class="info-value reason-text"><?= nl2br(e($gate_pass['reason'])) ?></div>
            </div>
        </div>

        <!-- Contact Details -->
        <div class="section-title">
            <i class="fas fa-address-book"></i> Contact Details
        </div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label"><i class="fas fa-mobile-alt"></i>Parent Contact</div>
                <div class="info-value"><?= e($gate_pass['parent_contact'] ?: 'N/A') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-phone"></i>Student Contact</div>
                <div class="info-value"><?= e($gate_pass['student_contact'] ?: 'N/A') ?></div>
            </div>
        </div>

        <!-- Issuance Details -->
        <div class="section-title">
            <i class="fas fa-stamp"></i> Issuance Details
        </div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label"><i class="fas fa-user-tie"></i>Issued By</div>
                <div class="info-value"><?= e($gate_pass['issued_by']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label"><i class="fas fa-calendar-alt"></i>Issued At</div>
                <div class="info-value"><?= e(date('d M Y, h:i A', strtotime($gate_pass['issued_at']))) ?></div>
            </div>
        </div>

        <!-- Print Gate Pass link -->
        <a href="<?= e($print_url) ?>" target="_blank" class="btn-print-pass">
            <i class="fas fa-print"></i> Open Printable Gate Pass
        </a>

    </div>
    <!-- /Full Details Panel -->

    <!-- ── Footer ─────────────────────────────────────── -->
    <div class="verify-footer">
        <div>
            <i class="fas fa-shield-alt" style="color:var(--green)"></i>
            Verified on <strong><?= $verification_time ?></strong>
        </div>
        <div class="verified-stamp">
            <i class="fas fa-check-circle"></i>
            Cryptographically Signed
        </div>
        <div style="width:100%;margin-top:4px;">
            Verification ID: <strong><?= $verification_id ?></strong>
        </div>
    </div>

</div><!-- /card -->

<script>
function toggleDetails() {
    const panel  = document.getElementById('fullDetails');
    const btn    = document.getElementById('revealBtn');
    const label  = document.getElementById('revealLabel');
    const arrow  = document.getElementById('revealArrow');
    const isOpen = panel.classList.contains('open');

    if (isOpen) {
        panel.classList.remove('open');
        btn.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
        panel.setAttribute('aria-hidden', 'true');
        label.textContent = 'View Full Gate Pass Details';
    } else {
        panel.classList.add('open');
        btn.classList.add('open');
        btn.setAttribute('aria-expanded', 'true');
        panel.setAttribute('aria-hidden', 'false');
        label.textContent = 'Hide Gate Pass Details';
        // Smooth scroll so the panel is visible on mobile
        setTimeout(() => panel.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
    }
}
</script>
</body>
</html>
<?php

// ══════════════════════════════════════════════════════════════════════════════
// ERROR / EDGE-CASE PAGE FUNCTIONS
// ══════════════════════════════════════════════════════════════════════════════

function render_error(string $title, string $message, string $type = 'warning'): void {
    $colors = [
        'warning' => ['bg' => '#e65100', 'icon' => 'fa-exclamation-triangle'],
        'danger'  => ['bg' => '#b71c1c', 'icon' => 'fa-shield-alt'],
        'info'    => ['bg' => '#1565c0', 'icon' => 'fa-info-circle'],
    ];
    $c = $colors[$type] ?? $colors['warning'];
    render_standalone_page(e($title), e($message), $c['bg'], $c['icon']);
}

function render_not_found(string $pass_id): void {
    render_standalone_page(
        'Gate Pass Not Found',
        'No gate pass with the reference <strong>' . e($pass_id) . '</strong> was found in school records. '
        . 'The pass may have been deleted, or the QR code may be damaged.',
        '#455a64',
        'fa-search'
    );
}

function render_tampered(string $pass_id): void {
    render_standalone_page(
        'Verification Failed &mdash; Invalid Token',
        'The verification signature for pass <strong>' . e($pass_id) . '</strong> does not match school records. '
        . 'This may indicate the QR code or URL has been tampered with. '
        . '<br><br><strong>Please do not permit exit on this pass and report it to school administration immediately.</strong>',
        '#b71c1c',
        'fa-ban'
    );
}

function render_standalone_page(string $title, string $message, string $color, string $icon): void {
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?> &mdash; Gate Pass Verification</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
        body {
            font-family:'Sen',sans-serif;
            background:#f0f4f0;
            min-height:100vh;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:24px;
        }
        .box {
            max-width:520px;
            width:100%;
            background:#fff;
            border-radius:14px;
            overflow:hidden;
            box-shadow:0 6px 30px rgba(0,0,0,.12);
        }
        .box-header {
            background:<?= $color ?>;
            color:#fff;
            padding:36px 32px;
            text-align:center;
        }
        .box-header i { font-size:48px; margin-bottom:16px; display:block; }
        .box-header h1 { font-size:22px; font-weight:800; }
        .box-body { padding:32px; font-size:14px; color:#444; line-height:1.7; }
        .box-body p { margin-bottom:20px; }
        .notice {
            background:#fff8e1;
            border:1px solid #ffe082;
            border-left:4px solid #ffa000;
            border-radius:6px;
            padding:14px 16px;
            font-size:13px;
            color:#5d4037;
        }
        .notice i { margin-right:6px; color:#ffa000; }
    </style>
</head>
<body>
<div class="box">
    <div class="box-header">
        <i class="fas <?= $icon ?>"></i>
        <h1><?= $title ?></h1>
    </div>
    <div class="box-body">
        <p><?= $message ?></p>
        <div class="notice">
            <i class="fas fa-exclamation-circle"></i>
            If you believe this is an error, contact the school administration with the gate pass reference number.
        </div>
    </div>
</div>
</body>
</html><?php
}
