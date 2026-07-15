<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';

$tracker->trackAction('Dashboard');

// ── Subscription warning ───────────────────────────────────────────────────────
$subscription_warning = null;
if (isset($_SESSION['subscription_warning']) && $_SESSION['subscription_warning'] !== null) {
    $days = (int) $_SESSION['subscription_warning'];
    if ($days <= 15) {
        $subscription_warning = ['days' => $days, 'critical' => $days <= 3];
    }
}

// ── Session notification (consumed here so it's never double-shown) ───────────
$session_notification = null;
if (isset($_SESSION['notification'])) {
    $session_notification = $_SESSION['notification'];
    unset($_SESSION['notification']);
}

// ── Dashboard statistics — 4 queries  ───────────────────────────
function getDashboardStats(mysqli $conn): array
{
    // 1. All student counts in one pass
    $r = $conn->query("
        SELECT
            COUNT(*)                                                                AS total,
            SUM(LOWER(gender) = 'male')                                             AS male,
            SUM(LOWER(gender) = 'female')                                           AS female,
            SUM(section = 'Day'      AND LOWER(gender) = 'male')                    AS male_day,
            SUM(section = 'Day'      AND LOWER(gender) = 'female')                  AS female_day,
            SUM(section = 'Boarding' AND LOWER(gender) = 'male')                    AS male_boarding,
            SUM(section = 'Boarding' AND LOWER(gender) = 'female')                  AS female_boarding,
            SUM(date_of_enrolment >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH))         AS new_month
        FROM students
    ");
    if (!$r) throw new RuntimeException('Students query: ' . $conn->error);
    $s = $r->fetch_assoc();

    $total_s   = (int) $s['total'];
    $new_s     = (int) $s['new_month'];
    $prev_s    = $total_s - $new_s;

    // 2. All staff counts in one pass
    $r = $conn->query("
        SELECT
            COUNT(*)                                                                                    AS total,
            SUM(LOWER(designation) = 'teacher')                                                         AS teaching,
            SUM(LOWER(designation) != 'teacher')                                                        AS non_teaching,
            SUM(LOWER(gender) = 'male'   AND LOWER(designation) = 'teacher')                           AS male_teachers,
            SUM(LOWER(gender) = 'female' AND LOWER(designation) = 'teacher')                           AS female_teachers,
            SUM(LOWER(gender) = 'male'   AND LOWER(designation) != 'teacher')                          AS male_non_teachers,
            SUM(LOWER(gender) = 'female' AND LOWER(designation) != 'teacher')                          AS female_non_teachers,
            SUM(joining_date >= DATE_SUB(CURDATE(), INTERVAL 1 WEEK))                                   AS new_week,
            SUM(LOWER(designation) = 'teacher'  AND joining_date >= DATE_SUB(CURDATE(), INTERVAL 1 WEEK)) AS new_t_week,
            SUM(LOWER(designation) != 'teacher' AND joining_date >= DATE_SUB(CURDATE(), INTERVAL 1 WEEK)) AS new_nt_week
        FROM staff
    ");
    if (!$r) throw new RuntimeException('Staff query: ' . $conn->error);
    $st = $r->fetch_assoc();

    $total_st  = (int) $st['total'];
    $new_st    = (int) $st['new_week'];
    $prev_st   = $total_st - $new_st;

    // 3. System users
    $r = $conn->query("
        SELECT
            COUNT(*) AS total,
            SUM(DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)) AS new_yesterday
        FROM users
    ");
    if (!$r) throw new RuntimeException('Users query: ' . $conn->error);
    $u = $r->fetch_assoc();

    return [
        // Students
        'total_students'        => $total_s,
        'male_students'         => (int) $s['male'],
        'female_students'       => (int) $s['female'],
        'total_day'             => (int) $s['male_day'] + (int) $s['female_day'],
        'male_day'              => (int) $s['male_day'],
        'female_day'            => (int) $s['female_day'],
        'total_boarding'        => (int) $s['male_boarding'] + (int) $s['female_boarding'],
        'male_boarding'         => (int) $s['male_boarding'],
        'female_boarding'       => (int) $s['female_boarding'],
        'students_growth'       => $prev_s > 0 ? round(($new_s / $prev_s) * 100, 1) : 0,
        // Staff
        'total_staff'           => $total_st,
        'teaching_staff'        => (int) $st['teaching'],
        'non_teaching_staff'    => (int) $st['non_teaching'],
        'male_teachers'         => (int) $st['male_teachers'],
        'female_teachers'       => (int) $st['female_teachers'],
        'male_non_teachers'     => (int) $st['male_non_teachers'],
        'female_non_teachers'   => (int) $st['female_non_teachers'],
        'staff_growth'          => $prev_st > 0 ? round(($new_st / $prev_st) * 100, 1) : 0,
        'new_teaching_staff'    => (int) $st['new_t_week'],
        'new_non_teaching_staff'=> (int) $st['new_nt_week'],
        // System
        'system_users'          => (int) $u['total'],
        'new_users'             => (int) $u['new_yesterday'],
    ];
}

function getClassStreamData(mysqli $conn): array
{
    $r = $conn->query("
        SELECT
            current_class,
            stream,
            SUM(LOWER(gender) = 'male')   AS male_count,
            SUM(LOWER(gender) = 'female') AS female_count,
            COUNT(student_id)             AS student_count
        FROM students
        GROUP BY current_class, stream
        ORDER BY current_class, stream
    ");
    if (!$r) throw new RuntimeException('Class stream query: ' . $conn->error);
    $data = [];
    while ($row = $r->fetch_assoc()) $data[] = $row;
    return $data;
}

// Fallback on DB error
$empty_stats = array_fill_keys([
    'total_students','male_students','female_students',
    'total_day','male_day','female_day',
    'total_boarding','male_boarding','female_boarding','students_growth',
    'total_staff','teaching_staff','non_teaching_staff',
    'male_teachers','female_teachers','male_non_teachers','female_non_teachers',
    'staff_growth','new_teaching_staff','new_non_teaching_staff',
    'system_users','new_users',
], 0);

try {
    $stats           = getDashboardStats($conn);
    $classStreamData = getClassStreamData($conn);
} catch (RuntimeException $e) {
    error_log('Dashboard error: ' . $e->getMessage());
    $stats           = $empty_stats;
    $classStreamData = [];
}

// ── Safe values for HTML & JS output ─────────────────────────────────────────
$school_name = htmlspecialchars($_SESSION['school_name'] ?? 'School Pilot', ENT_QUOTES);
$user_name   = htmlspecialchars($_SESSION['user_name']   ?? $_SESSION['user_id'] ?? 'User', ENT_QUOTES);
$today       = date('l, d F Y');

// All PHP→JS data goes through json_encode — never addslashes
$js_stream_data = json_encode($classStreamData,  JSON_HEX_TAG | JSON_HEX_AMP);
$js_stats       = json_encode([
    'total_day'      => $stats['total_day'],
    'total_boarding' => $stats['total_boarding'],
    'male_day'       => $stats['male_day'],
    'female_day'     => $stats['female_day'],
    'male_boarding'  => $stats['male_boarding'],
    'female_boarding'=> $stats['female_boarding'],
], JSON_HEX_TAG | JSON_HEX_AMP);
$js_notification = $session_notification
    ? json_encode($session_notification, JSON_HEX_TAG | JSON_HEX_AMP)
    : 'null';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Dashboard &mdash; <?= $school_name ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
<style>
/* ── Variables — identical tokens to staff/student pages ─── */
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --blue:#1565c0;--blue-lt:#e3f2fd;
  --pink:#c2185b;--pink-lt:#fce4ec;
  --amber:#e65100;--amber-lt:#fff3e0;
  --purple:#6a1b9a;--purple-lt:#f3e5f5;
  --red:#c62828;--red-lt:#ffebee;
  --gray:#546e7a;
  --radius:8px;--radius-lg:12px;--radius-xl:16px;
  --shadow:0 2px 8px rgba(0,0,0,.08);
  --shadow-md:0 4px 16px rgba(0,0,0,.10);
  --shadow-lg:0 8px 28px rgba(0,0,0,.13);
  --transition:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}

/* ── Layout ─────────────────────────────────────────────── */
.page{max-width:100%;margin:0 auto;padding:24px 20px 56px}

/* ── Page Header — gradient banner ─────────────────────── */
.page-header{
  background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);
  border-radius:var(--radius-xl);padding:28px 32px;
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:20px;margin-bottom:32px;margin-top:40px;
  box-shadow:var(--shadow-lg);position:relative;overflow:hidden
}
.page-header::before{
  content:'';position:absolute;inset:0;
  background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
  pointer-events:none
}
.header-left{position:relative}
.header-left h1{color:#fff;font-size:1.65rem;font-weight:700;letter-spacing:.2px;display:flex;align-items:center;gap:12px}
.header-left h1 i{opacity:.85;font-size:1.4rem}
.header-left .subtitle{color:rgba(255,255,255,.72);font-size:.9rem;margin-top:5px}
.header-left .date-badge{
  display:inline-flex;align-items:center;gap:6px;
  background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);
  border-radius:20px;padding:4px 12px;font-size:.75rem;
  color:rgba(255,255,255,.85);margin-top:10px
}
.header-pills{display:flex;gap:12px;flex-wrap:wrap;position:relative}
.hpill{
  background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);
  border-radius:var(--radius-lg);padding:14px 20px;text-align:center;
  min-width:110px;cursor:default;transition:background var(--transition)
}
.hpill:hover{background:rgba(255,255,255,.22)}
.hpill .n{font-size:1.6rem;font-weight:800;color:#fff;display:block;line-height:1}
.hpill .l{font-size:.7rem;color:rgba(255,255,255,.72);text-transform:uppercase;letter-spacing:.6px;margin-top:4px;display:block}

/* ── Greeting bar ────────────────────────────────────────── */
.greeting-bar{
  background:#fff;border-radius:var(--radius-lg);
  padding:16px 24px;margin-bottom:28px;
  box-shadow:var(--shadow);border:1px solid #e4ede5;
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px
}
.greeting-text{font-size:.95rem;color:#4a5568}
.greeting-text strong{color:var(--g700);font-weight:700}
.greeting-date{font-size:.82rem;color:#8a9a8b;display:flex;align-items:center;gap:6px}

/* ── Section headings ────────────────────────────────────── */
.section-head{
  display:flex;align-items:center;gap:10px;
  margin-bottom:16px;margin-top:8px;
  font-size:.78rem;font-weight:700;color:var(--g700);
  text-transform:uppercase;letter-spacing:.7px
}
.section-head::after{content:'';flex:1;height:1.5px;background:var(--g100)}
.section-head i{font-size:.85rem}

/* ── Stat grid ───────────────────────────────────────────── */
.stat-grid{display:grid;gap:16px;margin-bottom:12px}
.stat-grid-3{grid-template-columns:repeat(3,1fr)}
.stat-grid-4{grid-template-columns:repeat(4,1fr)}
.stat-grid-2{grid-template-columns:repeat(2,1fr)}

/* ── Stat Card ───────────────────────────────────────────── */
.stat-card{
  background:#fff;border-radius:var(--radius-lg);
  padding:22px 24px;box-shadow:var(--shadow);
  border:1px solid #e8ede9;position:relative;overflow:hidden;
  transition:transform var(--transition),box-shadow var(--transition);
  animation:fadeUp .45s ease both
}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg)}
.stat-card.hero{padding:26px 28px}
.card-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:14px}
.card-icon{
  width:44px;height:44px;border-radius:var(--radius);
  display:flex;align-items:center;justify-content:center;
  font-size:1.1rem;flex-shrink:0
}
.card-label{font-size:.75rem;font-weight:700;color:#6b7c6d;text-transform:uppercase;letter-spacing:.5px}
.card-value{
  font-size:2.1rem;font-weight:800;color:var(--g800);
  letter-spacing:-1px;line-height:1;margin-bottom:8px
}
.stat-card.hero .card-value{font-size:2.5rem}
.card-meta{font-size:.78rem;color:#8a9a8b;display:flex;align-items:center;gap:6px}
.card-meta .tag{
  display:inline-flex;align-items:center;gap:4px;
  padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:700
}
.tag-up{background:#e8f5e9;color:#2e7d32}
.tag-neutral{background:#f1f5f9;color:#64748b}

/* icon theme colours */
.ic-green{background:var(--g100);color:var(--g700)}
.ic-blue{background:var(--blue-lt);color:var(--blue)}
.ic-pink{background:var(--pink-lt);color:var(--pink)}
.ic-amber{background:var(--amber-lt);color:var(--amber)}
.ic-purple{background:var(--purple-lt);color:var(--purple)}
.ic-teal{background:#e0f2f1;color:#00695c}

/* ── Divider between sections ────────────────────────────── */
.section-spacer{height:20px}

/* ── Chart area ──────────────────────────────────────────── */
.chart-row{display:grid;gap:20px;margin-top:28px}
.chart-row-1{grid-template-columns:1fr}
.chart-row-2{grid-template-columns:1fr 1fr}
.chart-card{
  background:#fff;border-radius:var(--radius-lg);
  border:1px solid #e8ede9;box-shadow:var(--shadow);overflow:hidden
}
.chart-head{
  padding:20px 24px 0;border-bottom:1px solid #f0f4f1
}
.chart-head-inner{display:flex;align-items:center;justify-content:space-between;padding-bottom:16px}
.chart-title{font-size:1rem;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px}
.chart-title i{color:var(--g600);font-size:.9rem}
.chart-subtitle{font-size:.78rem;color:#8a9a8b;margin-top:2px}
.chart-body{padding:24px;position:relative}
.chart-body canvas{display:block}
.chart-empty{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  height:300px;color:#9aaa9b;gap:10px
}
.chart-empty i{font-size:2.5rem;opacity:.4}
.chart-empty p{font-size:.88rem}

/* ── Bar chart card specifics ────────────────────────────── */
.chart-card-bar{border-top:3px solid transparent;border-image:linear-gradient(90deg,var(--g900),var(--g600),#1565c0) 1}
.bar-chart-header{
  padding:22px 26px 18px;
  display:flex;align-items:flex-start;justify-content:space-between;gap:16px;
  border-bottom:1px solid #f0f4f1;flex-wrap:wrap
}
.bar-chart-header-left{}
.bar-chart-title{font-size:1.05rem;font-weight:700;color:#111827;display:flex;align-items:center;gap:9px;margin-bottom:3px}
.bar-chart-title i{color:var(--g600)}
.bar-chart-sub{font-size:.78rem;color:#8a9a8b}
.bar-chart-header-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.bar-total-badge{
  background:linear-gradient(135deg,var(--g900),var(--g700));
  color:#fff;border-radius:20px;padding:6px 14px;
  font-size:.78rem;font-weight:700;letter-spacing:.3px;
  display:flex;align-items:center;gap:6px
}
.bar-total-badge i{opacity:.8;font-size:.7rem}
.bar-export-btn{
  background:#fff;border:1.5px solid #d1d5db;border-radius:var(--radius);
  padding:6px 13px;font-size:.78rem;font-weight:600;color:#4b5563;
  cursor:pointer;display:flex;align-items:center;gap:6px;
  transition:all var(--transition);font-family:inherit
}
.bar-export-btn:hover{border-color:var(--g600);color:var(--g700);background:var(--g50)}
.bar-legend-row{
  display:flex;align-items:center;gap:20px;
  padding:12px 26px;border-bottom:1px solid #f0f4f1;
  background:#fafcfa;flex-wrap:wrap
}
.bar-legend-item{display:flex;align-items:center;gap:8px}
.bar-legend-dot{
  width:12px;height:12px;border-radius:3px;flex-shrink:0
}
.bar-legend-label{font-size:.8rem;font-weight:600;color:#374151}
.bar-legend-count{
  font-size:.75rem;font-weight:700;color:#fff;
  padding:2px 8px;border-radius:20px;margin-left:2px
}
.bar-chart-scroll{overflow-x:auto;padding:24px 20px 16px}
.bar-chart-scroll canvas{display:block}

/* ── Subscription warning ────────────────────────────────── */
.sub-warning{
  position:fixed;top:78px;right:20px;width:440px;max-width:calc(100vw - 40px);
  z-index:1001;border-radius:var(--radius-lg);overflow:hidden;
  box-shadow:0 12px 32px rgba(0,0,0,.18);animation:slideWarn .4s ease
}
.sub-warning.critical{background:linear-gradient(135deg,#c62828,#ef5350)}
.sub-warning.warning{background:linear-gradient(135deg,#e65100,#ff7043)}
.sub-inner{padding:18px 20px}
.sub-row1{display:flex;align-items:center;gap:10px;color:#fff;margin-bottom:8px}
.sub-row1 i{font-size:1.25rem;flex-shrink:0}
.sub-row1 strong{font-size:.95rem;font-weight:700}
.sub-msg{color:rgba(255,255,255,.88);font-size:.83rem;line-height:1.5;margin-bottom:14px}
.sub-actions{display:flex;gap:10px;flex-wrap:wrap}
.sub-btn-contact{
  display:inline-flex;align-items:center;gap:6px;
  background:#fff;color:var(--g800);
  padding:7px 14px;border-radius:var(--radius);
  font-size:.8rem;font-weight:700;border:none;cursor:pointer;transition:all var(--transition)
}
.sub-btn-contact:hover{background:var(--g100)}
.sub-btn-dismiss{
  background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.4);
  color:#fff;padding:7px 14px;border-radius:var(--radius);
  font-size:.8rem;font-weight:600;cursor:pointer;transition:all var(--transition)
}
.sub-btn-dismiss:hover{background:rgba(255,255,255,.25)}
.sub-close{
  position:absolute;top:12px;right:14px;
  background:rgba(255,255,255,.15);border:none;color:#fff;
  width:26px;height:26px;border-radius:50%;cursor:pointer;
  display:flex;align-items:center;justify-content:center;font-size:.95rem;
  transition:background var(--transition)
}
.sub-close:hover{background:rgba(255,255,255,.3)}

/* ── Notifications ───────────────────────────────────────── */
#notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.notif{background:#fff;border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow-lg);display:flex;align-items:flex-start;gap:12px;border-left:4px solid var(--g600);animation:notifIn .3s ease}
.notif.error{border-left-color:var(--red)}.notif.warning{border-left-color:var(--amber)}.notif.info{border-left-color:var(--blue)}
@keyframes notifIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.notif-icon{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.notif.success .notif-icon{color:var(--g700)}.notif.error .notif-icon{color:var(--red)}.notif.warning .notif-icon{color:var(--amber)}.notif.info .notif-icon{color:var(--blue)}
.notif-body{flex:1}.notif-title{font-weight:700;font-size:.85rem;margin-bottom:2px}.notif-msg{font-size:.8rem;color:#666}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:1rem;padding:0;line-height:1}

/* ── Footer ──────────────────────────────────────────────── */
.dash-footer{background:var(--g800);color:#fff;padding:24px 0;margin-top:48px}
.footer-inner{max-width:1600px;margin:0 auto;padding:0 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px}
.footer-copy{font-size:.8rem;color:rgba(255,255,255,.65)}
.footer-links{display:flex;flex-wrap:wrap;gap:4px 16px}
.footer-links a{font-size:.78rem;color:rgba(255,255,255,.7);transition:color var(--transition)}
.footer-links a:hover{color:#fff}

/* ── Animations ──────────────────────────────────────────── */
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes slideWarn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
/* Stagger stat cards — max 350ms total */
.stat-card:nth-child(1){animation-delay:.05s}
.stat-card:nth-child(2){animation-delay:.10s}
.stat-card:nth-child(3){animation-delay:.15s}
.stat-card:nth-child(4){animation-delay:.20s}
.stat-card:nth-child(5){animation-delay:.25s}
.stat-card:nth-child(6){animation-delay:.30s}
.stat-card:nth-child(7){animation-delay:.35s}

/* ── Responsive ──────────────────────────────────────────── */
@media(max-width:1100px){
  .stat-grid-4{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:860px){
  .stat-grid-3{grid-template-columns:repeat(2,1fr)}
  .chart-row-2{grid-template-columns:1fr}
  .page-header{flex-direction:column}
}
@media(max-width:560px){
  .stat-grid-3,.stat-grid-4,.stat-grid-2{grid-template-columns:1fr}
  .header-pills{width:100%}
  .hpill{flex:1}
}
</style>
</head>
<body>

<?php require_once 'nav.php'; ?>

<div id="notif-stack"></div>

<!-- ── Subscription warning ──────────────────────────────── -->
<?php if ($subscription_warning): ?>
<div class="sub-warning <?= $subscription_warning['critical'] ? 'critical' : 'warning' ?>" id="subWarning">
  <button class="sub-close" onclick="dismissWarning()" aria-label="Dismiss"><i class="fas fa-times"></i></button>
  <div class="sub-inner">
    <div class="sub-row1">
      <i class="fas fa-exclamation-triangle"></i>
      <strong><?= $subscription_warning['critical'] ? 'URGENT: Subscription Expiring!' : 'Subscription Warning' ?></strong>
    </div>
    <p class="sub-msg">
      <?php
        $d = $subscription_warning['days'];
        if ($d === 0)      echo 'Your subscription expires <strong>today</strong>. Renew immediately to avoid interruption.';
        elseif ($d === 1)  echo 'Your subscription expires in <strong>1 day</strong>. Please renew immediately.';
        else               echo 'Your subscription expires in <strong>' . $d . ' days</strong>. ' . ($subscription_warning['critical'] ? 'Renew urgently to avoid interruption.' : 'Contact your administrator to renew.');
      ?>
    </p>
    <div class="sub-actions">
      <a href="mailto:admin@schoolpilot.com?subject=Subscription+Renewal+Request" class="sub-btn-contact">
        <i class="fas fa-envelope"></i> Contact Support
      </a>
      <button class="sub-btn-dismiss" onclick="dismissWarning()"><i class="fas fa-times"></i> Dismiss</button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="page">

  <!-- ── Page Header ──────────────────────────────────────── -->
  <div class="page-header">
    <div class="header-left">
      <h1><i class="fas fa-chart-pie"></i> School Dashboard</h1>
      <p class="subtitle"><?= $school_name ?> &mdash; Overview &amp; Analytics</p>
      <div class="date-badge"><i class="fas fa-calendar-alt"></i> <?= $today ?></div>
    </div>
    
  </div>

  <!-- ── Greeting bar ─────────────────────────────────────── -->
  <div class="greeting-bar">
    <p class="greeting-text" id="greeting">Loading…</p>
    <div class="greeting-date"><i class="fas fa-clock"></i> <span id="clock"></span></div>
  </div>

  <!-- ════════════════════════════════════════════════════════
       SECTION: STUDENTS
  ════════════════════════════════════════════════════════ -->
  <div class="section-head"><i class="fas fa-user-graduate"></i> Students</div>

  <!-- Hero row — 3 cards -->
  <div class="stat-grid stat-grid-3">

    <div class="stat-card hero">
      <div class="card-top">
        <div class="card-label">Total Students</div>
        <div class="card-icon ic-green"><i class="fas fa-users"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['total_students']) ?></div>
      <div class="card-meta">
        <span class="tag tag-up"><i class="fas fa-arrow-up"></i> <?= $stats['students_growth'] ?>%</span>
        enrolled last month
      </div>
    </div>

    <div class="stat-card hero">
      <div class="card-top">
        <div class="card-label">Male Students</div>
        <div class="card-icon ic-blue"><i class="fas fa-mars"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['male_students']) ?></div>
      <div class="card-meta">
        <?php $mp = $stats['total_students'] > 0 ? round($stats['male_students']/$stats['total_students']*100,1) : 0 ?>
        <span class="tag tag-neutral"><?= $mp ?>%</span> of all students
      </div>
    </div>

    <div class="stat-card hero">
      <div class="card-top">
        <div class="card-label">Female Students</div>
        <div class="card-icon ic-pink"><i class="fas fa-venus"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['female_students']) ?></div>
      <div class="card-meta">
        <?php $fp = $stats['total_students'] > 0 ? round($stats['female_students']/$stats['total_students']*100,1) : 0 ?>
        <span class="tag tag-neutral"><?= $fp ?>%</span> of all students
      </div>
    </div>

  </div>

  <!-- Section breakdown — 4 cards -->
  <div class="stat-grid stat-grid-4" style="margin-top:12px">

    <div class="stat-card">
      <div class="card-top">
        <div class="card-label">Day Students</div>
        <div class="card-icon ic-teal"><i class="fas fa-sun"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['total_day']) ?></div>
      <div class="card-meta"><i class="fas fa-male" style="color:#1565c0"></i> <?= number_format($stats['male_day']) ?> &nbsp;·&nbsp; <i class="fas fa-female" style="color:#c2185b"></i> <?= number_format($stats['female_day']) ?></div>
    </div>

    <div class="stat-card">
      <div class="card-top">
        <div class="card-label">Boarding Students</div>
        <div class="card-icon ic-purple"><i class="fas fa-moon"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['total_boarding']) ?></div>
      <div class="card-meta"><i class="fas fa-male" style="color:#1565c0"></i> <?= number_format($stats['male_boarding']) ?> &nbsp;·&nbsp; <i class="fas fa-female" style="color:#c2185b"></i> <?= number_format($stats['female_boarding']) ?></div>
    </div>

    <div class="stat-card">
      <div class="card-top">
        <div class="card-label">Day Male</div>
        <div class="card-icon ic-blue"><i class="fas fa-mars"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['male_day']) ?></div>
      <div class="card-meta"><span class="tag tag-neutral">Day section</span></div>
    </div>

    <div class="stat-card">
      <div class="card-top">
        <div class="card-label">Day Female</div>
        <div class="card-icon ic-pink"><i class="fas fa-venus"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['female_day']) ?></div>
      <div class="card-meta"><span class="tag tag-neutral">Day section</span></div>
    </div>

  </div>

  <div class="section-spacer"></div>

  <!-- ════════════════════════════════════════════════════════
       SECTION: STAFF
  ════════════════════════════════════════════════════════ -->
  <div class="section-head"><i class="fas fa-users-cog"></i> Staff</div>

  <!-- Top row — 3 cards -->
  <div class="stat-grid stat-grid-3">

    <div class="stat-card hero">
      <div class="card-top">
        <div class="card-label">Total Staff</div>
        <div class="card-icon ic-green"><i class="fas fa-id-badge"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['total_staff']) ?></div>
      <div class="card-meta">
        <span class="tag tag-up"><i class="fas fa-arrow-up"></i> <?= $stats['staff_growth'] ?>%</span>
        from last week
      </div>
    </div>

    <div class="stat-card hero">
      <div class="card-top">
        <div class="card-label">Teaching Staff</div>
        <div class="card-icon ic-amber"><i class="fas fa-chalkboard-teacher"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['teaching_staff']) ?></div>
      <div class="card-meta">
        <span class="tag tag-up">+<?= $stats['new_teaching_staff'] ?></span> joined this week
      </div>
    </div>

    <div class="stat-card hero">
      <div class="card-top">
        <div class="card-label">Non-Teaching Staff</div>
        <div class="card-icon ic-teal"><i class="fas fa-user-tie"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['non_teaching_staff']) ?></div>
      <div class="card-meta">
        <span class="tag tag-up">+<?= $stats['new_non_teaching_staff'] ?></span> joined this week
      </div>
    </div>

  </div>

  <!-- Gender breakdown — 4 cards -->
  <div class="stat-grid stat-grid-4" style="margin-top:12px">

    <div class="stat-card">
      <div class="card-top">
        <div class="card-label">Male Teachers</div>
        <div class="card-icon ic-blue"><i class="fas fa-mars"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['male_teachers']) ?></div>
      <div class="card-meta"><span class="tag tag-neutral">Teaching dept.</span></div>
    </div>

    <div class="stat-card">
      <div class="card-top">
        <div class="card-label">Female Teachers</div>
        <div class="card-icon ic-pink"><i class="fas fa-venus"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['female_teachers']) ?></div>
      <div class="card-meta"><span class="tag tag-neutral">Teaching dept.</span></div>
    </div>

    <div class="stat-card">
      <div class="card-top">
        <div class="card-label">Male Non-Teaching</div>
        <div class="card-icon ic-blue"><i class="fas fa-user-cog"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['male_non_teachers']) ?></div>
      <div class="card-meta"><span class="tag tag-neutral">Admin &amp; support</span></div>
    </div>

    <div class="stat-card">
      <div class="card-top">
        <div class="card-label">Female Non-Teaching</div>
        <div class="card-icon ic-pink"><i class="fas fa-user-cog"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['female_non_teachers']) ?></div>
      <div class="card-meta"><span class="tag tag-neutral">Admin &amp; support</span></div>
    </div>

  </div>

  <div class="section-spacer"></div>

  <!-- ════════════════════════════════════════════════════════
       SECTION: SYSTEM
  ════════════════════════════════════════════════════════ -->
  <div class="section-head"><i class="fas fa-shield-halved"></i> System</div>

  <div class="stat-grid stat-grid-3">
    <div class="stat-card hero">
      <div class="card-top">
        <div class="card-label">System Users</div>
        <div class="card-icon ic-purple"><i class="fas fa-user-shield"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['system_users']) ?></div>
      <div class="card-meta">
        <span class="tag tag-neutral"><?= $stats['new_users'] ?> added yesterday</span>
      </div>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════════
       CHARTS
  ════════════════════════════════════════════════════════ -->

  <!-- Full-width: Class & Stream bar chart -->
  <div class="chart-row chart-row-1">
    <div class="chart-card chart-card-bar">

      <div class="bar-chart-header">
        <div class="bar-chart-header-left">
          <div class="bar-chart-title"><i class="fas fa-chart-bar"></i> Students by Class &amp; Stream</div>
          <div class="bar-chart-sub">Boys and girls side-by-side per class &amp; stream</div>
        </div>
        <div class="bar-chart-header-right">
          <div class="bar-total-badge">
            <i class="fas fa-users"></i>
            Total: <?= number_format($stats['total_students']) ?>
          </div>
          <button class="bar-export-btn" onclick="exportBarChart()">
            <i class="fas fa-download"></i> Export
          </button>
        </div>
      </div>

      <?php
        $total_boys  = array_sum(array_column($classStreamData, 'male_count'));
        $total_girls = array_sum(array_column($classStreamData, 'female_count'));
      ?>
      <div class="bar-legend-row">
        <div class="bar-legend-item">
          <div class="bar-legend-dot" style="background:linear-gradient(135deg,#1565c0,#42a5f5)"></div>
          <span class="bar-legend-label">Boys</span>
          <span class="bar-legend-count" style="background:#1565c0"><?= number_format($total_boys) ?></span>
        </div>
        <div class="bar-legend-item">
          <div class="bar-legend-dot" style="background:linear-gradient(135deg,#c2185b,#f06292)"></div>
          <span class="bar-legend-label">Girls</span>
          <span class="bar-legend-count" style="background:#c2185b"><?= number_format($total_girls) ?></span>
        </div>
        <div class="bar-legend-item" style="margin-left:auto">
          <span style="font-size:.75rem;color:#9ca3af"><i class="fas fa-info-circle"></i> Hover a bar for details</span>
        </div>
      </div>

      <?php if (empty($classStreamData)): ?>
        <div class="chart-empty"><i class="fas fa-chart-bar"></i><p>No class data available yet.</p></div>
      <?php else: ?>
        <div class="bar-chart-scroll">
          <?php
            // Compute a sensible canvas width: at least 60px per class group
            $minWidth = max(700, count($classStreamData) * 72);
          ?>
          <canvas id="classStreamChart"
                  height="400"
                  style="min-width:<?= $minWidth ?>px;width:100%"></canvas>
        </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- Two-column: Section pie + Gender doughnut -->
  <div class="chart-row chart-row-2">

    <div class="chart-card">
      <div class="chart-head">
        <div class="chart-head-inner">
          <div>
            <div class="chart-title"><i class="fas fa-chart-pie"></i> Section Distribution</div>
            <div class="chart-subtitle">Day vs. Boarding students</div>
          </div>
        </div>
      </div>
      <div class="chart-body">
        <?php if ($stats['total_students'] === 0): ?>
          <div class="chart-empty"><i class="fas fa-chart-pie"></i><p>No student data available.</p></div>
        <?php else: ?>
          <canvas id="sectionPieChart" height="300"></canvas>
        <?php endif; ?>
      </div>
    </div>

    <div class="chart-card">
      <div class="chart-head">
        <div class="chart-head-inner">
          <div>
            <div class="chart-title"><i class="fas fa-venus-mars"></i> Gender by Section</div>
            <div class="chart-subtitle">Male / Female split across Day &amp; Boarding</div>
          </div>
        </div>
      </div>
      <div class="chart-body">
        <?php if ($stats['total_students'] === 0): ?>
          <div class="chart-empty"><i class="fas fa-venus-mars"></i><p>No student data available.</p></div>
        <?php else: ?>
          <canvas id="genderSectionChart" height="300"></canvas>
        <?php endif; ?>
      </div>
    </div>

  </div>

</div><!-- /page -->

<!-- ── Footer ────────────────────────────────────────────── -->
<footer class="dash-footer">
  <div class="footer-inner">
    <p class="footer-copy">&copy; <?= date('Y') ?> <?= $school_name ?>. All rights reserved. &mdash; Powered by School Pilot</p>
    <div class="footer-links">
      <a href="https://schoolpilot.org/legal/privacy_policy.php">Privacy Policy</a>
      <a href="https://schoolpilot.org/legal/terms_of_use.php">Terms of Use</a>
      <a href="https://schoolpilot.org/legal/data_protection.php">Data Protection</a>
      <a href="https://schoolpilot.org/legal/service_level_agreement.php">SLA</a>
      <a href="https://schoolpilot.org/legal/refund_policy.php">Refund Policy</a>
    </div>
  </div>
</footer>

<script>
// ── All PHP data safely handed off via json_encode ────────
const CLASS_STREAM_DATA = <?= $js_stream_data ?>;
const SECTION_STATS     = <?= $js_stats ?>;
const SESSION_NOTIF     = <?= $js_notification ?>;
const USERNAME          = <?= json_encode($user_name) ?>;

// ── Greeting + live clock ─────────────────────────────────
(function () {
    const phrases = {
        morning:   ['Here\'s what\'s on today.','Let\'s kick off a great day.','Your morning overview is ready.','Rise and shine &mdash; here\'s the latest.'],
        afternoon: ['Afternoon check-in.','Your midday overview.','Here\'s how the day is going.','Power through with today\'s update.'],
        evening:   ['Here\'s today\'s wrap-up.','Let\'s review today\'s highlights.','Winding down &mdash; here\'s the summary.','Evening edition ready.'],
        night:     ['One last look before you sign off.','Here\'s your nightly summary.','The day\'s final update.','Goodnight &mdash; here\'s the recap.']
    };

    function update() {
        const now  = new Date();
        const h    = now.getHours();
        const key  = h >= 5 && h < 12 ? 'morning' : h < 17 ? 'afternoon' : h < 22 ? 'evening' : 'night';
        const greet= h >= 5 && h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : h < 22 ? 'Good evening' : 'Good night';
        const arr  = phrases[key];
        const phrase = arr[Math.floor(Math.random() * arr.length)];

        document.getElementById('greeting').innerHTML =
            `<strong>${greet}, ${USERNAME}!</strong> ${phrase}`;

        const pad = n => String(n).padStart(2, '0');
        document.getElementById('clock').textContent =
            `${pad(h)}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
    }

    update();
    setInterval(() => {
        // Only update clock after first render
        const now = new Date();
        const h   = now.getHours();
        const pad = n => String(n).padStart(2, '0');
        document.getElementById('clock').textContent =
            `${pad(h)}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
    }, 1000);
})();

// ── Session notification (single DOMContentLoaded) ────────
document.addEventListener('DOMContentLoaded', function () {

    // Show session notification if present
    if (SESSION_NOTIF && SESSION_NOTIF.message) {
        notify(SESSION_NOTIF.type === 'error' ? 'Error' : 'Notice',
               SESSION_NOTIF.message,
               SESSION_NOTIF.type || 'info');
    }

    // ── Shared chart defaults ──────────────────────────────
    Chart.defaults.font.family = "'Sen', system-ui, sans-serif";

    const tooltip = {
        backgroundColor: 'rgba(17,24,39,.95)',
        titleColor: '#fff',
        bodyColor: 'rgba(255,255,255,.85)',
        borderColor: '#388e3c',
        borderWidth: 1,
        padding: 14,
        cornerRadius: 10,
        titleFont: { size: 13, weight: '700' },
        bodyFont:  { size: 12 },
    };

    // ── Custom plugin: total labels above each bar group ──────
    const totalLabelsPlugin = {
        id: 'totalLabels',
        afterDatasetsDraw(chart) {
            const { ctx, data, scales: { x, y } } = chart;
            const totals = data.labels.map((_, i) =>
                data.datasets.reduce((sum, ds) => sum + (Number(ds.data[i]) || 0), 0)
            );
            ctx.save();
            totals.forEach((total, i) => {
                if (!total) return;
                const xPos = x.getPixelForValue(i);
                const yPos = y.getPixelForValue(total);
                // Pill background
                const label = total.toLocaleString();
                ctx.font = '600 10.5px "Sen", system-ui, sans-serif';
                const tw = ctx.measureText(label).width;
                const pw = tw + 12, ph = 18, pr = 4;
                const px = xPos - pw / 2, py = yPos - ph - 7;
                ctx.beginPath();
                ctx.roundRect(px, py, pw, ph, pr);
                ctx.fillStyle = 'rgba(31,41,55,.82)';
                ctx.fill();
                // Label text
                ctx.fillStyle = '#ffffff';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(label, xPos, py + ph / 2);
            });
            ctx.restore();
        }
    };

    // ── Chart 1: Class & Stream grouped bar ────────────────
    if (CLASS_STREAM_DATA.length && document.getElementById('classStreamChart')) {
        const canvas   = document.getElementById('classStreamChart');
        const ctx      = canvas.getContext('2d');
        const labels   = CLASS_STREAM_DATA.map(r =>
            r.stream ? `${r.current_class}\n${r.stream}` : r.current_class
        );
        const maleData   = CLASS_STREAM_DATA.map(r => parseInt(r.male_count)   || 0);
        const femaleData = CLASS_STREAM_DATA.map(r => parseInt(r.female_count) || 0);

        // Canvas gradients — built after chart layout so chartArea is defined
        function makeGrad(chart, top1, top2) {
            const { ctx: c, chartArea } = chart;
            if (!chartArea) return top1;
            const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
            g.addColorStop(0, top1);
            g.addColorStop(1, top2);
            return g;
        }

        const barChart = new Chart(ctx, {
            type: 'bar',
            plugins: [totalLabelsPlugin],
            data: {
                labels,
                datasets: [
                    {
                        label: 'Boys',
                        data: maleData,
                        backgroundColor: ctx => makeGrad(ctx.chart, '#1976d2', '#90caf9'),
                        borderColor:      'rgba(21,101,192,.25)',
                        borderWidth:      1,
                        borderRadius:     8,
                        borderSkipped:    false,
                        maxBarThickness:  28,
                        barPercentage:    0.7,
                        categoryPercentage: 0.75,
                    },
                    {
                        label: 'Girls',
                        data: femaleData,
                        backgroundColor: ctx => makeGrad(ctx.chart, '#d81b60', '#f48fb1'),
                        borderColor:      'rgba(194,24,91,.25)',
                        borderWidth:      1,
                        borderRadius:     8,
                        borderSkipped:    false,
                        maxBarThickness:  28,
                        barPercentage:    0.7,
                        categoryPercentage: 0.75,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 900, easing: 'easeOutQuart' },
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: false }, // we use custom HTML legend
                    tooltip: {
                        ...tooltip,
                        callbacks: {
                            title:  items  => items[0].label.replace('\n', ' – '),
                            label:  ctx    => `  ${ctx.dataset.label}: ${ctx.raw.toLocaleString()} students`,
                            footer: items  => {
                                const tot = items.reduce((s, i) => s + i.raw, 0);
                                return `  Total: ${tot.toLocaleString()} students`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid:   { display: false },
                        border: { display: false },
                        ticks:  {
                            color: '#6b7280',
                            font:  { size: 11, weight: '600' },
                            maxRotation: 35,
                            minRotation: 0,
                            callback(val) {
                                // Chart.js passes the index; get label and split for two-line
                                const lbl = this.getLabelForValue(val);
                                return lbl.includes('\n') ? lbl.split('\n') : lbl;
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        border:  { display: false, dash: [4, 4] },
                        grid:    { color: 'rgba(226,232,240,.65)', lineWidth: 1 },
                        ticks:   {
                            precision: 0,
                            color:     '#9ca3af',
                            font:      { size: 11 },
                            padding:   8,
                            callback:  v => v.toLocaleString()
                        },
                        title: {
                            display: true,
                            text:    'Number of Students',
                            color:   '#6b7280',
                            font:    { size: 11, weight: '600' },
                            padding: { bottom: 6 }
                        }
                    }
                },
                layout: { padding: { top: 36, right: 12, bottom: 4, left: 4 } }
            }
        });

        // Re-draw gradients after resize so they always fill the full axis height
        barChart.options.onResize = () => barChart.update('none');
    }

    // ── Export bar chart as PNG ────────────────────────────
    window.exportBarChart = function () {
        const canvas = document.getElementById('classStreamChart');
        if (!canvas) return;
        const link    = document.createElement('a');
        link.download = 'students-by-class-stream.png';
        link.href     = canvas.toDataURL('image/png');
        link.click();
        notify('Exported', 'Chart saved as PNG.', 'success', 3000);
    };

    // ── Chart 2: Section pie ───────────────────────────────
    if (document.getElementById('sectionPieChart')) {
        new Chart(document.getElementById('sectionPieChart'), {
            type: 'pie',
            data: {
                labels: ['Day Students', 'Boarding Students'],
                datasets: [{
                    data: [SECTION_STATS.total_day, SECTION_STATS.total_boarding],
                    backgroundColor: ['#388e3c','#1565c0'],
                    borderColor: '#fff',
                    borderWidth: 3,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position:'bottom', labels:{ font:{size:12,weight:'600'}, color:'#374151', padding:18, usePointStyle:true } },
                    tooltip: { ...tooltip, callbacks:{ label: ctx => ` ${ctx.label}: ${ctx.parsed.toLocaleString()} students` } }
                }
            }
        });
    }

    // ── Chart 3: Gender by section doughnut ───────────────
    if (document.getElementById('genderSectionChart')) {
        new Chart(document.getElementById('genderSectionChart'), {
            type: 'doughnut',
            data: {
                labels: ['Day – Boys', 'Day – Girls', 'Boarding – Boys', 'Boarding – Girls'],
                datasets: [{
                    data: [SECTION_STATS.male_day, SECTION_STATS.female_day, SECTION_STATS.male_boarding, SECTION_STATS.female_boarding],
                    backgroundColor: ['#1565c0','#c2185b','#0d47a1','#880e4f'],
                    borderColor: '#fff',
                    borderWidth: 3,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '55%',
                plugins: {
                    legend: { position:'bottom', labels:{ font:{size:12,weight:'600'}, color:'#374151', padding:14, usePointStyle:true } },
                    tooltip: { ...tooltip, callbacks:{ label: ctx => ` ${ctx.label}: ${ctx.parsed.toLocaleString()}` } }
                }
            }
        });
    }
});

// ── Subscription warning dismiss ──────────────────────────
function dismissWarning() {
    const el = document.getElementById('subWarning');
    if (!el) return;
    el.style.transition = 'opacity .3s ease, transform .3s ease';
    el.style.opacity = '0';
    el.style.transform = 'translateX(30px)';
    setTimeout(() => el.remove(), 320);
}

// ── Notifications ─────────────────────────────────────────
function notify(title, msg, type = 'success', dur = 5000) {
    const icons = { success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
    const esc   = v => String(v || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const n = document.createElement('div');
    n.className = `notif ${type}`;
    n.innerHTML = `
      <i class="fas ${icons[type]||icons.info} notif-icon"></i>
      <div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div>
      <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('notif-stack').prepend(n);
    setTimeout(() => {
        n.style.opacity = '0'; n.style.transform = 'translateX(30px)'; n.style.transition = '.3s';
        setTimeout(() => n.remove(), 320);
    }, dur);
}
</script>
</body>
</html>