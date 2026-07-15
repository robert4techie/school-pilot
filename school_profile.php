<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';

$tracker->trackAction('School Profile');

// ── Role guard ────────────────────────────────────────────────────────────────
$allowed = ['developer', 'super user'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed, true)) {
    http_response_code(403);
    echo '<p style="font-family:sans-serif;padding:40px;color:#c62828">Access denied.</p>';
    exit;
}

// ── CSRF token ────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── Fetch school profile ──────────────────────────────────────────────────────
$result = $conn->query("
    SELECT id, school_name, school_motto, address, phone, email,
           website, pobox, next_term_date, next_term_ends, logo_path
    FROM school_profile
    ORDER BY id DESC
    LIMIT 1
");

$hasProfile = false;
$school     = [];

if ($result && $result->num_rows > 0) {
    $hasProfile = true;
    $raw        = $result->fetch_assoc();

    $h = fn(string $key) => htmlspecialchars($raw[$key] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $safeDate = function (string $val): string {
        if (!$val || strlen(trim($val)) < 8) return '';
        $ts = strtotime($val);
        return ($ts && $ts > 86400) ? date('Y-m-d', $ts) : '';
    };
    $fmtDate = function (string $val): string {
        if (!$val || strlen(trim($val)) < 8) return '—';
        $ts = strtotime($val);
        return ($ts && $ts > 86400) ? date('d M Y', $ts) : $val;
    };

    $school = [
        'id'              => (int)($raw['id'] ?? 0),
        'school_name'     => $h('school_name'),
        'school_motto'    => $h('school_motto'),
        'address'         => $h('address'),
        'phone'           => $h('phone'),
        'email'           => $h('email'),
        'website'         => $h('website'),
        'pobox'           => $h('pobox'),
        'term_start_disp' => $fmtDate($raw['next_term_date'] ?? ''),
        'term_end_disp'   => $fmtDate($raw['next_term_ends'] ?? ''),
        'term_start_val'  => $safeDate($raw['next_term_date'] ?? ''),
        'term_end_val'    => $safeDate($raw['next_term_ends'] ?? ''),
        // Raw (un-encoded) path for use in src="" attributes
        'logo_src'        => htmlspecialchars($raw['logo_path'] ?? '', ENT_QUOTES),
        'logo_path'       => $h('logo_path'),   // for hidden form field
    ];
}

// ── Term countdown ────────────────────────────────────────────────────────────
$countdown = null;
if ($hasProfile && $school['term_start_val']) {
    $today     = new DateTimeImmutable('today');
    $termStart = new DateTimeImmutable($school['term_start_val']);
    $diffDays  = (int)$today->diff($termStart)->format('%r%a');
    $absD      = abs($diffDays);
    $countdown = [
        'days'    => $diffDays,
        'weeks'   => (int)floor($absD / 7),
        'rem'     => $absD % 7,
        'future'  => $diffDays > 0,
        'today'   => $diffDays === 0,
        'ongoing' => $diffDays < 0,
    ];

    // Progress bar % when term is ongoing
    $termPct = 0;
    if ($countdown['ongoing'] && $school['term_end_val']) {
        $termEnd = new DateTimeImmutable($school['term_end_val']);
        $total   = (int)$termStart->diff($termEnd)->format('%a');
        $elapsed = (int)$termStart->diff($today)->format('%a');
        $termPct = $total > 0 ? min(100, round($elapsed / $total * 100)) : 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>School Profile &mdash; School Pilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f5fbf5;
  --red:#c62828;--amber:#e65100;--blue:#1565c0;--gray:#546e7a;
  --radius:8px;--radius-lg:12px;--radius-xl:16px;
  --shadow:0 1px 4px rgba(0,0,0,.07),0 2px 8px rgba(0,0,0,.05);
  --shadow-md:0 4px 16px rgba(0,0,0,.10);
  --shadow-lg:0 8px 32px rgba(0,0,0,.13);
  --tr:.2s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#eef2ef;min-height:100vh;color:#1a1a1a;-webkit-font-smoothing:antialiased}
a{text-decoration:none;color:inherit}
.page{max-width:100%;margin:0 auto;padding:24px 20px 64px}

/* ── Page header ─────────────────────────────────────────── */
.page-header{
  background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);
  border-radius:var(--radius-xl);padding:26px 32px;margin-bottom:24px;margin-top:40px;
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;
  box-shadow:var(--shadow-lg);position:relative;overflow:hidden
}
.page-header::before{content:'';position:absolute;inset:0;pointer-events:none;
  background:url("data:image/svg+xml,%3Csvg width='52' height='52' viewBox='0 0 52 52' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Ccircle cx='26' cy='26' r='4'/%3E%3C/g%3E%3C/svg%3E")}
.ph-left{position:relative}
.ph-left h1{color:#fff;font-size:1.5rem;font-weight:700;display:flex;align-items:center;gap:10px}
.ph-left p{color:rgba(255,255,255,.68);font-size:.84rem;margin-top:4px}

/* ── Buttons ─────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border:none;border-radius:var(--radius);font-size:.84rem;font-weight:700;font-family:inherit;cursor:pointer;transition:all var(--tr);white-space:nowrap}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(0,0,0,.2)}
.btn:active{transform:none}
.btn-white{background:#fff;color:var(--g800)}.btn-white:hover{background:var(--g100)}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g900)}
.btn-outline{background:transparent;border:1.5px solid #d0dbd1;color:var(--gray);font-weight:600}.btn-outline:hover{border-color:var(--gray);background:#f4f4f4;transform:none}

/* ══ Profile card ════════════════════════════════════════ */
.profile-card{background:#fff;border-radius:var(--radius-xl);box-shadow:var(--shadow-md);border:1px solid #dde8de;overflow:hidden;margin-bottom:20px}

/* — Identity banner — */
.identity-strip{
  background:linear-gradient(155deg,var(--g900) 0%,var(--g800) 50%,var(--g700) 100%);
  padding:36px 40px;display:flex;align-items:center;gap:32px;flex-wrap:wrap
}
.logo-frame{
  width:120px;height:120px;flex-shrink:0;border-radius:var(--radius-lg);
  background:#fff;border:3px solid rgba(255,255,255,.3);
  box-shadow:0 6px 24px rgba(0,0,0,.3);
  display:flex;align-items:center;justify-content:center;overflow:hidden
}
.logo-frame img{width:100%;height:100%;object-fit:contain;padding:6px;background:#fff}
.logo-fallback{font-size:3rem;color:rgba(255,255,255,.3)}
.id-text h2{font-size:1.6rem;font-weight:800;color:#fff;letter-spacing:-.3px;line-height:1.2;margin-bottom:6px}
.id-text .motto{color:rgba(255,255,255,.68);font-style:italic;font-size:.9rem;margin-bottom:10px}
.id-text .addr-chip{display:inline-flex;align-items:center;gap:6px;color:rgba(255,255,255,.6);font-size:.78rem}
.id-text .addr-chip i{font-size:.7rem}

/* — Countdown — */
.cd-strip{
  background:var(--g50);border-bottom:1px solid #d4e8d4;
  padding:18px 40px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px
}
.cd-left{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.cd-label{font-size:.82rem;font-weight:700;color:var(--g800);display:flex;align-items:center;gap:7px}
.cd-label i{color:var(--g600);font-size:.95rem}
.cd-blocks{display:flex;gap:8px}
.cdb{background:#fff;border:1px solid #cce0cc;border-radius:var(--radius);padding:10px 18px;text-align:center;box-shadow:var(--shadow)}
.cdb .n{font-size:1.5rem;font-weight:800;color:var(--g800);line-height:1;display:block}
.cdb .l{font-size:.62rem;text-transform:uppercase;letter-spacing:.5px;color:#8a9a8b;margin-top:2px;display:block}
.cd-dates{font-size:.78rem;color:#6b7c6d;display:flex;align-items:center;gap:7px}
.cd-dates i{color:var(--g600)}
.cd-dates strong{color:#374151}

/* progress bar */
.prog-wrap{padding:0 40px 22px;background:var(--g50)}
.prog-labels{display:flex;justify-content:space-between;font-size:.72rem;color:#6b7c6d;font-weight:600;margin-bottom:6px}
.prog-bar{height:6px;background:#d4e8d4;border-radius:10px;overflow:hidden}
.prog-fill{height:100%;background:linear-gradient(90deg,var(--g700),var(--g400));border-radius:10px;transition:width .9s ease}

/* — Info columns — */
.info-section{display:grid;grid-template-columns:repeat(4,1fr);border-top:1px solid #e8eee8}
.info-col{padding:24px 26px;border-right:1px solid #e8eee8}
.info-col:last-child{border-right:none}
.ic-title{font-size:.67rem;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:var(--g700);margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid var(--g100);display:flex;align-items:center;gap:6px}
.ifield{display:flex;align-items:flex-start;gap:10px;margin-bottom:14px}
.ifield:last-child{margin-bottom:0}
.if-icon{width:32px;height:32px;flex-shrink:0;border-radius:6px;background:var(--g100);color:var(--g700);display:flex;align-items:center;justify-content:center;font-size:.72rem;margin-top:1px}
.if-lbl{font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#9aaa9b}
.if-val{font-size:.875rem;color:#1a1a1a;font-weight:500;margin-top:2px;word-break:break-word;line-height:1.45}
.if-val a{color:var(--blue)}.if-val a:hover{color:var(--g700);text-decoration:underline}
.if-val.empty{color:#bbb;font-style:italic;font-weight:400}

/* ── Empty state ─────────────────────────────────────────── */
.empty-state{background:#fff;border-radius:var(--radius-xl);border:2px dashed #c0d4c1;padding:72px 40px;text-align:center;color:#8a9a8b}
.empty-state i{font-size:4rem;opacity:.2;color:var(--g700);margin-bottom:18px;display:block}
.empty-state h3{font-size:1.15rem;font-weight:700;color:#3a4a3b;margin-bottom:8px}
.empty-state p{font-size:.875rem;line-height:1.65;max-width:420px;margin:0 auto 24px}

/* ── Modal ───────────────────────────────────────────────── */
.modal{display:none;position:fixed;inset:0;z-index:1100;background:rgba(0,0,0,.48);backdrop-filter:blur(4px);align-items:flex-start;justify-content:center;padding:20px 16px 40px;overflow-y:auto}
.modal.active{display:flex}
.modal-box{background:#fff;border-radius:var(--radius-xl);width:100%;max-width:660px;margin:auto;box-shadow:var(--shadow-lg);animation:mSlide .24s ease}
@keyframes mSlide{from{opacity:0;transform:translateY(-18px)}to{opacity:1;transform:translateY(0)}}
.modal-head{background:linear-gradient(135deg,var(--g800),var(--g600));padding:20px 26px;border-radius:var(--radius-xl) var(--radius-xl) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head h2{color:#fff;font-size:1rem;font-weight:700;display:flex;align-items:center;gap:9px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1rem;transition:background var(--tr)}
.modal-close:hover{background:rgba(255,255,255,.28)}
.modal-body{padding:28px 28px 10px}
.modal-footer{padding:16px 28px 24px;display:flex;justify-content:flex-end;gap:10px}

/* ── Form ────────────────────────────────────────────────── */
.fsec{font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:var(--g700);margin:22px 0 14px;padding-bottom:7px;border-bottom:2px solid var(--g100);display:flex;align-items:center;gap:7px}
.fsec:first-child{margin-top:0}
.fgrid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.fgrid .full{grid-column:1/-1}
.fg{display:flex;flex-direction:column;gap:5px}
.fg label{font-size:.75rem;font-weight:700;color:#3a4a3b}
.fg label .req{color:var(--red)}
.fg label .opt{font-weight:400;color:#9aaa9b;font-size:.71rem}
.fc{padding:9px 12px;border:1.5px solid #cfd9d0;border-radius:var(--radius);font-size:.875rem;width:100%;font-family:inherit;color:#1a1a1a;background:#fff;transition:border-color var(--tr),box-shadow var(--tr)}
.fc:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.1)}
.fc.inv{border-color:var(--red)!important;box-shadow:0 0 0 3px rgba(198,40,40,.08)!important}
.ferr{font-size:.72rem;color:var(--red);display:none;align-items:center;gap:4px;margin-top:2px}
.ferr.on{display:flex}

/* ── Logo upload zone ────────────────────────────────────── */
.logo-zone{border:2px dashed #c0d4c1;border-radius:var(--radius-lg);padding:18px 16px 14px;text-align:center;cursor:pointer;transition:all var(--tr);background:#fafcfa;position:relative;overflow:hidden}
.logo-zone:hover{border-color:var(--g600);background:var(--g100)}
.logo-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.lz-prev{width:88px;height:88px;border-radius:var(--radius);object-fit:contain;border:2px solid #d0e8d0;margin:0 auto 8px;display:block;background:#fff;padding:4px}
.lz-icon{font-size:2rem;color:#c0d4c1;margin-bottom:8px;display:block}
.lz-hint{font-size:.76rem;color:#8a9a8b;line-height:1.5}
.lz-hint strong{color:var(--g700)}
.lz-badge{display:inline-flex;align-items:center;gap:5px;margin-top:8px;background:var(--g100);color:var(--g700);font-size:.7rem;font-weight:700;padding:3px 10px;border-radius:20px}

/* ── Notifications ───────────────────────────────────────── */
#nstack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.notif{background:#fff;border-radius:var(--radius);padding:13px 15px;box-shadow:var(--shadow-lg);display:flex;align-items:flex-start;gap:11px;border-left:4px solid var(--g600);animation:nIn .28s ease}
.notif.error{border-left-color:var(--red)}.notif.warning{border-left-color:var(--amber)}.notif.info{border-left-color:var(--blue)}
@keyframes nIn{from{opacity:0;transform:translateX(28px)}to{opacity:1;transform:translateX(0)}}
.ni{font-size:1rem;margin-top:1px;flex-shrink:0}
.notif.success .ni{color:var(--g700)}.notif.error .ni{color:var(--red)}.notif.warning .ni{color:var(--amber)}.notif.info .ni{color:var(--blue)}
.nb{flex:1}.nt{font-weight:700;font-size:.83rem;margin-bottom:1px}.nm{font-size:.78rem;color:#666}
.nc{background:none;border:none;cursor:pointer;color:#bbb;font-size:.95rem;padding:0;line-height:1}

/* ── Responsive ──────────────────────────────────────────── */
@media(max-width:960px){
  .info-section{grid-template-columns:1fr 1fr}
  .info-col:nth-child(2){border-right:none}
  .info-col:nth-child(3),.info-col:nth-child(4){border-top:1px solid #e8eee8}
  .info-col:nth-child(4){border-right:none}
}
@media(max-width:640px){
  .identity-strip{padding:28px 22px;gap:20px;flex-direction:column;align-items:flex-start}
  .logo-frame{width:88px;height:88px}
  .id-text h2{font-size:1.3rem}
  .cd-strip{padding:14px 22px}.prog-wrap{padding:0 22px 18px}
  .info-section{grid-template-columns:1fr}
  .info-col{border-right:none;border-top:1px solid #e8eee8}
  .info-col:first-child{border-top:none}
  .page-header{flex-direction:column}
  .fgrid{grid-template-columns:1fr}.fgrid .full{grid-column:1}
  .modal-body{padding:20px 18px 8px}.modal-footer{padding:14px 18px 20px}
}
</style>
</head>
<body>
<?php require_once 'nav.php'; ?>
<div id="nstack"></div>

<div class="page">

  <!-- ── Page banner ──────────────────────────────────────── -->
  <div class="page-header">
    <div class="ph-left">
      <h1><i class="fas fa-school"></i> School Profile</h1>
      <p>Manage your school's identity, contact details and term dates</p>
    </div>
    <?php if (!$hasProfile): ?>
      <button class="btn btn-white" id="openAddBtn"><i class="fas fa-plus"></i> Set Up Profile</button>
    <?php else: ?>
      <button class="btn btn-white" id="openEditBtn"><i class="fas fa-pen"></i> Edit Profile</button>
    <?php endif; ?>
  </div>

  <?php if ($hasProfile): ?>
  <!-- ══════════════════════════════════════════════════════
       PROFILE CARD
  ══════════════════════════════════════════════════════ -->
  <div class="profile-card">

    <!-- Identity — logo · name · motto · address (nothing else) -->
    <div class="identity-strip">
      <div class="logo-frame">
        <?php if ($school['logo_src']): ?>
          <img src="<?= $school['logo_src'] ?>"
               alt="<?= $school['school_name'] ?> logo"
               onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
          <i class="fas fa-school logo-fallback" style="display:none"></i>
        <?php else: ?>
          <i class="fas fa-school logo-fallback"></i>
        <?php endif; ?>
      </div>
      <div class="id-text">
        <h2><?= $school['school_name'] ?></h2>
        <?php if ($school['school_motto']): ?>
          <p class="motto">&ldquo;<?= $school['school_motto'] ?>&rdquo;</p>
        <?php endif; ?>
        <?php if ($school['address']): ?>
          <span class="addr-chip"><i class="fas fa-map-marker-alt"></i> <?= $school['address'] ?></span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Term countdown (only when profile has dates) -->
    <?php if ($countdown): ?>
    <div class="cd-strip">
      <div class="cd-left">
        <div class="cd-label">
          <?php if ($countdown['today']): ?>
            <i class="fas fa-bell"></i> Term starts today!
          <?php elseif ($countdown['future']): ?>
            <i class="fas fa-hourglass-half"></i> Next term in:
          <?php else: ?>
            <i class="fas fa-book-open"></i> Term in progress —
          <?php endif; ?>
        </div>
        <?php if (!$countdown['today']): ?>
        <div class="cd-blocks">
          <?php if ($countdown['weeks'] > 0): ?>
          <div class="cdb">
            <span class="n"><?= $countdown['weeks'] ?></span>
            <span class="l">Wk<?= $countdown['weeks'] !== 1 ? 's' : '' ?></span>
          </div>
          <?php endif; ?>
          <div class="cdb">
            <span class="n"><?= $countdown['rem'] ?></span>
            <span class="l">Day<?= $countdown['rem'] !== 1 ? 's' : '' ?></span>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <div class="cd-dates">
        <i class="fas fa-calendar-alt"></i>
        <span><strong><?= $school['term_start_disp'] ?></strong> &rarr; <strong><?= $school['term_end_disp'] ?></strong></span>
      </div>
    </div>

    <?php if ($countdown['ongoing'] && $termPct > 0): ?>
    <div class="prog-wrap">
      <div class="prog-labels">
        <span>Term started: <?= $school['term_start_disp'] ?></span>
        <span><?= $termPct ?>% complete</span>
      </div>
      <div class="prog-bar">
        <div class="prog-fill" style="width:<?= $termPct ?>%"></div>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Info columns — each data point appears EXACTLY ONCE -->
    <div class="info-section">

      <!-- Contact -->
      <div class="info-col">
        <div class="ic-title"><i class="fas fa-phone-alt"></i> Contact</div>
        <div class="ifield">
          <div class="if-icon"><i class="fas fa-phone"></i></div>
          <div>
            <div class="if-lbl">Phone</div>
            <div class="if-val <?= !$school['phone'] ? 'empty' : '' ?>"><?= $school['phone'] ?: 'Not provided' ?></div>
          </div>
        </div>
        <div class="ifield">
          <div class="if-icon"><i class="fas fa-envelope"></i></div>
          <div>
            <div class="if-lbl">Email</div>
            <div class="if-val <?= !$school['email'] ? 'empty' : '' ?>">
              <?= $school['email'] ? '<a href="mailto:' . $school['email'] . '">' . $school['email'] . '</a>' : 'Not provided' ?>
            </div>
          </div>
        </div>
        <div class="ifield">
          <div class="if-icon"><i class="fas fa-globe"></i></div>
          <div>
            <div class="if-lbl">Website</div>
            <div class="if-val <?= !$school['website'] ? 'empty' : '' ?>">
              <?= $school['website'] ? '<a href="' . $school['website'] . '" target="_blank" rel="noopener">' . $school['website'] . '</a>' : 'Not provided' ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Location -->
      <div class="info-col">
        <div class="ic-title"><i class="fas fa-map-marker-alt"></i> Location</div>
        <div class="ifield">
          <div class="if-icon"><i class="fas fa-map"></i></div>
          <div>
            <div class="if-lbl">Physical Address</div>
            <div class="if-val <?= !$school['address'] ? 'empty' : '' ?>"><?= $school['address'] ?: 'Not provided' ?></div>
          </div>
        </div>
        <div class="ifield">
          <div class="if-icon"><i class="fas fa-inbox"></i></div>
          <div>
            <div class="if-lbl">PO Box</div>
            <div class="if-val <?= !$school['pobox'] ? 'empty' : '' ?>">
              <?= $school['pobox'] ? 'P.O. Box ' . $school['pobox'] : 'Not provided' ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Term Dates -->
      <div class="info-col">
        <div class="ic-title"><i class="fas fa-calendar-alt"></i> Term Dates</div>
        <div class="ifield">
          <div class="if-icon"><i class="fas fa-play-circle"></i></div>
          <div>
            <div class="if-lbl">Next Term Starts</div>
            <div class="if-val <?= $school['term_start_disp'] === '—' ? 'empty' : '' ?>"><?= $school['term_start_disp'] ?></div>
          </div>
        </div>
        <div class="ifield">
          <div class="if-icon"><i class="fas fa-stop-circle"></i></div>
          <div>
            <div class="if-lbl">Next Term Ends</div>
            <div class="if-val <?= $school['term_end_disp'] === '—' ? 'empty' : '' ?>"><?= $school['term_end_disp'] ?></div>
          </div>
        </div>
      </div>

      <!-- System record -->
      <div class="info-col">
        <div class="ic-title"><i class="fas fa-cog"></i> Record</div>
        <div class="ifield">
          <div class="if-icon"><i class="fas fa-hashtag"></i></div>
          <div>
            <div class="if-lbl">Profile ID</div>
            <div class="if-val">#<?= $school['id'] ?></div>
          </div>
        </div>
        <div class="ifield">
          <div class="if-icon"><i class="fas fa-image"></i></div>
          <div>
            <div class="if-lbl">Logo</div>
            <div class="if-val <?= !$school['logo_src'] ? 'empty' : '' ?>">
              <?= $school['logo_src'] ? '&#10003; Logo uploaded' : 'No logo uploaded' ?>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /info-section -->
  </div><!-- /profile-card -->

  <?php else: ?>

  <div class="empty-state">
    <i class="fas fa-school"></i>
    <h3>No school profile configured yet</h3>
    <p>Add your school's name, contact details, logo and term dates.<br>This information appears across the system and on all generated documents.</p>
    <button class="btn btn-primary" id="openAddBtnAlt"><i class="fas fa-plus"></i> Set Up School Profile</button>
  </div>

  <?php endif; ?>
</div><!-- /page -->


<!-- ════════════════════════════════════════════════════════
     ADD MODAL
════════════════════════════════════════════════════════ -->
<div id="addModal" class="modal" onclick="if(event.target===this)closeModal('addModal')">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-plus-circle"></i> Set Up School Profile</h2>
      <button class="modal-close" onclick="closeModal('addModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form id="addForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <div class="fsec"><i class="fas fa-school"></i> School Identity</div>
        <div class="fgrid">
          <div class="fg">
            <label>School Name <span class="req">*</span></label>
            <input type="text" name="school_name" class="fc" id="add_name" placeholder="e.g. Cardinal Nsubuga SS" required>
            <span class="ferr" id="add_name_err"><i class="fas fa-exclamation-circle"></i> School name is required</span>
          </div>
          <div class="fg">
            <label>School Motto <span class="req">*</span></label>
            <input type="text" name="school_motto" class="fc" id="add_motto" placeholder="e.g. Education is the key…" required>
            <span class="ferr" id="add_motto_err"><i class="fas fa-exclamation-circle"></i> School motto is required</span>
          </div>
          <div class="fg full">
            <label>School Logo <span class="opt">(JPEG / PNG / WebP, max 2 MB)</span></label>
            <div class="logo-zone">
              <input type="file" name="logo" id="add_logo" accept="image/jpeg,image/png,image/webp"
                     onchange="previewLogo(this,'add_prev','add_icon')">
              <i class="fas fa-cloud-upload-alt lz-icon" id="add_icon"></i>
              <img id="add_prev" class="lz-prev" style="display:none" alt="Logo preview">
              <div class="lz-hint"><strong>Click to upload logo</strong> or drag &amp; drop</div>
            </div>
            <span class="ferr" id="add_logo_err"><i class="fas fa-exclamation-circle"></i> Only JPEG, PNG or WebP under 2 MB</span>
          </div>
        </div>

        <div class="fsec"><i class="fas fa-address-card"></i> Contact Details</div>
        <div class="fgrid">
          <div class="fg">
            <label>Phone <span class="req">*</span></label>
            <input type="tel" name="phone" class="fc" id="add_phone" placeholder="+256 752 526 084" required>
            <span class="ferr" id="add_phone_err"><i class="fas fa-exclamation-circle"></i> Valid phone required (min 10 digits)</span>
          </div>
          <div class="fg">
            <label>Email <span class="req">*</span></label>
            <input type="email" name="email" class="fc" id="add_email" placeholder="school@example.com" required>
            <span class="ferr" id="add_email_err"><i class="fas fa-exclamation-circle"></i> Valid email is required</span>
          </div>
          <div class="fg">
            <label>Website <span class="opt">(optional)</span></label>
            <input type="url" name="website" class="fc" id="add_website" placeholder="https://school.ac.ug">
            <span class="ferr" id="add_website_err"><i class="fas fa-exclamation-circle"></i> Enter a valid URL</span>
          </div>
          <div class="fg">
            <label>PO Box <span class="opt">(optional)</span></label>
            <input type="text" name="pobox" class="fc" id="add_pobox" placeholder="e.g. 51">
          </div>
          <div class="fg full">
            <label>Physical Address <span class="req">*</span></label>
            <input type="text" name="address" class="fc" id="add_address" placeholder="e.g. Kayabwe - Mpigi" required>
            <span class="ferr" id="add_address_err"><i class="fas fa-exclamation-circle"></i> Address is required</span>
          </div>
        </div>

        <div class="fsec"><i class="fas fa-calendar-alt"></i> Term Dates</div>
        <div class="fgrid">
          <div class="fg">
            <label>Next Term Starts <span class="req">*</span></label>
            <input type="date" name="next_term_date" class="fc" id="add_tstart" required>
            <span class="ferr" id="add_tstart_err"><i class="fas fa-exclamation-circle"></i> Start date is required</span>
          </div>
          <div class="fg">
            <label>Next Term Ends <span class="req">*</span></label>
            <input type="date" name="next_term_ends" class="fc" id="add_tend" required>
            <span class="ferr" id="add_tend_err"><i class="fas fa-exclamation-circle"></i> Must be after the start date</span>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('addModal')">Cancel</button>
      <button class="btn btn-primary" id="addSaveBtn" onclick="submitForm('add')">
        <i class="fas fa-save"></i> Save School Profile
      </button>
    </div>
  </div>
</div>


<!-- ════════════════════════════════════════════════════════
     EDIT MODAL
════════════════════════════════════════════════════════ -->
<div id="editModal" class="modal" onclick="if(event.target===this)closeModal('editModal')">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-pen"></i> Edit School Profile</h2>
      <button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form id="editForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token"        value="<?= $csrf ?>">
        <input type="hidden" name="school_id"         value="<?= $school['id'] ?? 0 ?>">
        <!-- Tells update_school.php which logo to keep when no new file is uploaded -->
        <input type="hidden" name="current_logo_path" value="<?= $school['logo_path'] ?? '' ?>">

        <div class="fsec"><i class="fas fa-school"></i> School Identity</div>
        <div class="fgrid">
          <div class="fg">
            <label>School Name <span class="req">*</span></label>
            <input type="text" name="school_name" class="fc" id="edit_name"
                   value="<?= $school['school_name'] ?? '' ?>" required>
            <span class="ferr" id="edit_name_err"><i class="fas fa-exclamation-circle"></i> School name is required</span>
          </div>
          <div class="fg">
            <label>School Motto <span class="req">*</span></label>
            <input type="text" name="school_motto" class="fc" id="edit_motto"
                   value="<?= $school['school_motto'] ?? '' ?>" required>
            <span class="ferr" id="edit_motto_err"><i class="fas fa-exclamation-circle"></i> School motto is required</span>
          </div>
          <div class="fg full">
            <label>School Logo <span class="opt">— leave blank to keep the current logo</span></label>
            <div class="logo-zone">
              <input type="file" name="logo" id="edit_logo" accept="image/jpeg,image/png,image/webp"
                     onchange="previewLogo(this,'edit_prev','edit_icon')">
              <?php if ($school['logo_src'] ?? ''): ?>
                <img id="edit_prev" class="lz-prev"
                     src="<?= $school['logo_src'] ?>"
                     alt="Current school logo"
                     onerror="this.style.display='none';document.getElementById('edit_icon').style.display='block'">
                <i class="fas fa-cloud-upload-alt lz-icon" id="edit_icon" style="display:none"></i>
                <div class="lz-hint">Current logo shown above &mdash; <strong>click to replace</strong></div>
                <span class="lz-badge"><i class="fas fa-check-circle"></i> Logo uploaded</span>
              <?php else: ?>
                <i class="fas fa-cloud-upload-alt lz-icon" id="edit_icon"></i>
                <img id="edit_prev" class="lz-prev" style="display:none" alt="Logo preview">
                <div class="lz-hint"><strong>Click to upload logo</strong> &mdash; JPEG, PNG or WebP, max 2 MB</div>
              <?php endif; ?>
            </div>
            <span class="ferr" id="edit_logo_err"><i class="fas fa-exclamation-circle"></i> Only JPEG, PNG or WebP under 2 MB</span>
          </div>
        </div>

        <div class="fsec"><i class="fas fa-address-card"></i> Contact Details</div>
        <div class="fgrid">
          <div class="fg">
            <label>Phone <span class="req">*</span></label>
            <input type="tel" name="phone" class="fc" id="edit_phone"
                   value="<?= $school['phone'] ?? '' ?>" required>
            <span class="ferr" id="edit_phone_err"><i class="fas fa-exclamation-circle"></i> Valid phone required (min 10 digits)</span>
          </div>
          <div class="fg">
            <label>Email <span class="req">*</span></label>
            <input type="email" name="email" class="fc" id="edit_email"
                   value="<?= $school['email'] ?? '' ?>" required>
            <span class="ferr" id="edit_email_err"><i class="fas fa-exclamation-circle"></i> Valid email is required</span>
          </div>
          <div class="fg">
            <label>Website <span class="opt">(optional)</span></label>
            <input type="url" name="website" class="fc" id="edit_website"
                   value="<?= $school['website'] ?? '' ?>" placeholder="https://…">
            <span class="ferr" id="edit_website_err"><i class="fas fa-exclamation-circle"></i> Enter a valid URL</span>
          </div>
          <div class="fg">
            <label>PO Box <span class="opt">(optional)</span></label>
            <input type="text" name="pobox" class="fc" id="edit_pobox"
                   value="<?= $school['pobox'] ?? '' ?>">
          </div>
          <div class="fg full">
            <label>Physical Address <span class="req">*</span></label>
            <input type="text" name="address" class="fc" id="edit_address"
                   value="<?= $school['address'] ?? '' ?>" required>
            <span class="ferr" id="edit_address_err"><i class="fas fa-exclamation-circle"></i> Address is required</span>
          </div>
        </div>

        <div class="fsec"><i class="fas fa-calendar-alt"></i> Term Dates</div>
        <div class="fgrid">
          <div class="fg">
            <label>Next Term Starts <span class="req">*</span></label>
            <input type="date" name="next_term_date" class="fc" id="edit_tstart"
                   value="<?= $school['term_start_val'] ?? '' ?>" required>
            <span class="ferr" id="edit_tstart_err"><i class="fas fa-exclamation-circle"></i> Start date is required</span>
          </div>
          <div class="fg">
            <label>Next Term Ends <span class="req">*</span></label>
            <input type="date" name="next_term_ends" class="fc" id="edit_tend"
                   value="<?= $school['term_end_val'] ?? '' ?>" required>
            <span class="ferr" id="edit_tend_err"><i class="fas fa-exclamation-circle"></i> Must be after the start date</span>
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
      <button class="btn btn-primary" id="editSaveBtn" onclick="submitForm('edit')">
        <i class="fas fa-save"></i> Save Changes
      </button>
    </div>
  </div>
</div>


<script>
// ── Modals ────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('active');    }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
        document.querySelectorAll('.modal.active').forEach(m => m.classList.remove('active'));
});
['openAddBtn','openAddBtnAlt'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', () => openModal('addModal'));
});
const eb = document.getElementById('openEditBtn');
if (eb) eb.addEventListener('click', () => openModal('editModal'));

// ── Logo preview ──────────────────────────────────────────
function previewLogo(input, prevId, iconId) {
    if (!input.files || !input.files[0]) return;
    const prev = document.getElementById(prevId);
    const icon = document.getElementById(iconId);
    const r    = new FileReader();
    r.onload = e => {
        prev.src = e.target.result;
        prev.style.display = 'block';
        if (icon) icon.style.display = 'none';
    };
    r.readAsDataURL(input.files[0]);
}

// ── Unified validation ────────────────────────────────────
function validateForm(p) {
    let ok = true;
    const form = document.getElementById(p + 'Form');
    form.querySelectorAll('.inv').forEach(el => el.classList.remove('inv'));
    form.querySelectorAll('.ferr.on').forEach(el => el.classList.remove('on'));

    const v = id => (document.getElementById(id)?.value ?? '').trim();
    function mark(fid, eid, valid) {
        const f = document.getElementById(fid), e = document.getElementById(eid);
        if (!f || !e) return;
        f.classList.toggle('inv', !valid);
        e.classList.toggle('on', !valid);
        if (!valid) ok = false;
    }

    mark(p+'_name',    p+'_name_err',    v(p+'_name') !== '');
    mark(p+'_motto',   p+'_motto_err',   v(p+'_motto') !== '');
    mark(p+'_address', p+'_address_err', v(p+'_address') !== '');

    // Phone — min 10 digits
    mark(p+'_phone', p+'_phone_err',
        /^[0-9\s()\-+]+$/.test(v(p+'_phone')) &&
        v(p+'_phone').replace(/\D/g,'').length >= 10);

    // Email
    mark(p+'_email', p+'_email_err',
        /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v(p+'_email')));

    // Website — optional, validate if present
    const ws = v(p+'_website');
    if (ws) {
        let u = ws.startsWith('http') ? ws : 'https://' + ws;
        try { new URL(u); } catch(_) { mark(p+'_website', p+'_website_err', false); }
    }

    // Term dates
    const ts = v(p+'_tstart'), te = v(p+'_tend');
    mark(p+'_tstart', p+'_tstart_err', ts !== '');
    mark(p+'_tend',   p+'_tend_err',   te !== '' && (!ts || te >= ts));

    // Logo — only if a file was selected
    const li = document.getElementById(p+'_logo');
    if (li && li.files[0]) {
        const f = li.files[0];
        mark(p+'_logo', p+'_logo_err',
            ['image/jpeg','image/png','image/webp'].includes(f.type) &&
            f.size <= 2 * 1024 * 1024);
    }

    return ok;
}

// ── Submit ────────────────────────────────────────────────
function submitForm(p) {
    if (!validateForm(p)) {
        notify('Fix errors', 'Please correct the highlighted fields.', 'error');
        return;
    }
    const btn  = document.getElementById(p + 'SaveBtn');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    fetch(p === 'add' ? 'api/add_school.php' : 'api/update_school.php', {
        method: 'POST',
        body: new FormData(document.getElementById(p + 'Form'))
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            closeModal(p + 'Modal');
            notify('Saved', d.message || 'Profile saved successfully.', 'success');
            setTimeout(() => location.reload(), 1600);
        } else {
            notify('Error', d.message || d.error || 'Save failed.', 'error');
        }
    })
    .catch(err => notify('Network error', err.message, 'error'))
    .finally(() => { btn.disabled = false; btn.innerHTML = orig; });
}

// ── Notifications ─────────────────────────────────────────
function notify(title, msg, type = 'success', dur = 5000) {
    const icons = {success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
    const esc   = v => String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const n = document.createElement('div');
    n.className = 'notif ' + type;
    n.innerHTML = `<i class="fas ${icons[type]||icons.info} ni"></i>
      <div class="nb"><div class="nt">${esc(title)}</div><div class="nm">${esc(msg)}</div></div>
      <button class="nc" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('nstack').prepend(n);
    setTimeout(() => {
        n.style.transition = '.3s';
        n.style.opacity = '0';
        n.style.transform = 'translateX(28px)';
        setTimeout(() => n.remove(), 320);
    }, dur);
}
</script>
</body>
</html>