<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction('Librarian Dashboard');

// ── Access control ────────────────────────────────────────────────────────────
function checkLibraryAccess(mysqli $conn): void
{
    if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
    $uid  = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row  = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { session_destroy(); header('Location: index.php'); exit; }
    $allowed = ['librarian', 'developer', 'super user', 'school leader'];
    if (!in_array(strtolower(trim($row['role'])), $allowed, true)) {
        $_SESSION['previous_page']        = $_SERVER['REQUEST_URI'];
        $_SESSION['access_denied_message'] = "You don't have permission to access the library management system.";
        header('Location: access_denied.php');
        exit;
    }
}
checkLibraryAccess($conn);

date_default_timezone_set('Africa/Kampala');

// ── Stats — all consolidated, zero duplicate queries ─────────────────────────
function getLibraryStats(mysqli $conn): array
{
    // 1. Books summary (one pass)
    $q = $conn->query("
        SELECT
            COALESCE(SUM(copies),0)          AS total_books,
            COUNT(*)                          AS total_titles,
            COUNT(DISTINCT subject)           AS total_subjects
        FROM books
    ");
    if (!$q) throw new RuntimeException('Books stats: ' . $conn->error);
    $b = $q->fetch_assoc();

    // 2. Borrowing summary (one pass)
    $q = $conn->query("
        SELECT
            COUNT(*)                                        AS all_borrow,
            SUM(return_date IS NULL)                        AS on_loan,
            SUM(return_date IS NULL AND due_date < CURDATE()) AS overdue,
            SUM(DATE(borrow_date) = CURDATE())              AS today
        FROM book_borrowings
    ");
    if (!$q) throw new RuntimeException('Borrowing stats: ' . $conn->error);
    $br = $q->fetch_assoc();

    // 3. New books last 30 days
    $q = $conn->query("SELECT COUNT(*) AS c FROM books WHERE date_added >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    if (!$q) throw new RuntimeException('New books: ' . $conn->error);
    $nb = (int) $q->fetch_assoc()['c'];

    // 4. Unique members
    $q = $conn->query("
        SELECT COUNT(DISTINCT student_name) AS c
        FROM book_borrowings
        WHERE student_name IS NOT NULL AND student_name != ''
    ");
    if (!$q) throw new RuntimeException('Members: ' . $conn->error);
    $mb = (int) $q->fetch_assoc()['c'];

    $on_loan = (int) $br['on_loan'];
    $total   = (int) $b['total_books'];

    return [
        'total_books'      => $total,
        'total_titles'     => (int) $b['total_titles'],
        'total_subjects'   => (int) $b['total_subjects'],
        'total_borrowings' => (int) $br['all_borrow'],
        'borrowed_books'   => $on_loan,
        'overdue_books'    => (int) $br['overdue'],
        'borrows_today'    => (int) $br['today'],
        'available_books'  => max(0, $total - $on_loan),
        'new_books_30d'    => $nb,
        'total_members'    => $mb,
    ];
}

function getChartRows(mysqli $conn, string $query): array
{
    $result = $conn->query($query);
    if (!$result) {
        error_log('Library chart query failed: ' . $conn->error . ' | SQL: ' . $query);
        return [];
    }
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    return $rows;
}

$empty = array_fill_keys(['total_books','total_titles','total_subjects','total_borrowings',
    'borrowed_books','overdue_books','borrows_today','available_books','new_books_30d','total_members'], 0);

try {
    $stats = getLibraryStats($conn);

    // Subject distribution (pie)
    $subjectData = getChartRows($conn, "
        SELECT subject, SUM(copies) AS total
        FROM books WHERE subject IS NOT NULL AND subject != ''
        GROUP BY subject ORDER BY total DESC LIMIT 10
    ");

    // Monthly trends — build a full 12-element array so Chart always has all months
    $trendsRaw = getChartRows($conn, "
        SELECT MONTH(borrow_date) AS m, COUNT(*) AS c
        FROM book_borrowings WHERE YEAR(borrow_date) = YEAR(CURDATE())
        GROUP BY MONTH(borrow_date) ORDER BY m
    ");
    $monthly = array_fill(0, 12, 0);
    foreach ($trendsRaw as $t) $monthly[(int)$t['m'] - 1] = (int)$t['c'];

    // Popular books (bar)
    $popularBooks = getChartRows($conn, "
        SELECT book_title, COUNT(id) AS borrow_count
        FROM book_borrowings WHERE book_title IS NOT NULL AND book_title != ''
        GROUP BY book_title ORDER BY borrow_count DESC LIMIT 7
    ");

    // Top borrowers
    $topMembers = getChartRows($conn, "
        SELECT student_name, COUNT(id) AS borrow_count
        FROM book_borrowings WHERE student_name IS NOT NULL AND student_name != ''
        GROUP BY student_name ORDER BY borrow_count DESC LIMIT 6
    ");

    // Books never borrowed (need restock awareness)
    $unpopularBooks = getChartRows($conn, "
        SELECT title FROM books
        WHERE book_id NOT IN (
            SELECT DISTINCT book_id FROM book_borrowings WHERE book_id IS NOT NULL
        )
        LIMIT 6
    ");

    // Books that need reordering (1 copy, borrowed > 5×)
    $reorderBooks = getChartRows($conn, "
        SELECT b.title, b.copies, COUNT(bb.id) AS borrow_count
        FROM books b
        JOIN book_borrowings bb ON b.book_id = bb.book_id
        GROUP BY b.book_id, b.title, b.copies
        HAVING b.copies = 1 AND COUNT(bb.id) > 5
        ORDER BY borrow_count DESC LIMIT 6
    ");

    // Inactive members (no borrow in last 3 months)
    $inactiveMembers = getChartRows($conn, "
        SELECT student_name, MAX(borrow_date) AS last_borrow
        FROM book_borrowings WHERE student_name IS NOT NULL AND student_name != ''
        GROUP BY student_name
        HAVING MAX(borrow_date) < DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
        ORDER BY last_borrow ASC LIMIT 6
    ");

} catch (RuntimeException $e) {
    error_log('Librarian dashboard error: ' . $e->getMessage());
    $stats = $empty;
    $subjectData = $monthly = $popularBooks = $topMembers = $unpopularBooks = $reorderBooks = $inactiveMembers = [];
}

// ── Safe PHP→HTML/JS ──────────────────────────────────────────────────────────
$school_name = htmlspecialchars($_SESSION['school_name'] ?? 'School Pilot', ENT_QUOTES);
$user_name   = htmlspecialchars($_SESSION['user_name']   ?? $_SESSION['user_id'] ?? 'Librarian', ENT_QUOTES);
$today_label = date('l, d F Y');

// All data to JS via json_encode — never addslashes
$js_subject_labels = json_encode(array_column($subjectData, 'subject'),      JSON_HEX_TAG | JSON_HEX_AMP);
$js_subject_totals = json_encode(array_column($subjectData, 'total'),        JSON_HEX_TAG | JSON_HEX_AMP);
$js_monthly        = json_encode($monthly,                                   JSON_HEX_TAG | JSON_HEX_AMP);
$js_pop_labels     = json_encode(array_column($popularBooks, 'book_title'),  JSON_HEX_TAG | JSON_HEX_AMP);
$js_pop_counts     = json_encode(array_column($popularBooks, 'borrow_count'),JSON_HEX_TAG | JSON_HEX_AMP);

$session_notification = null;
if (isset($_SESSION['notification'])) {
    $session_notification = $_SESSION['notification'];
    unset($_SESSION['notification']);
}
$js_notification = $session_notification
    ? json_encode($session_notification, JSON_HEX_TAG | JSON_HEX_AMP)
    : 'null';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Library Dashboard &mdash; <?= $school_name ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>
<style>
/* ── Design tokens (identical to dashboard.php) ─────────── */
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#c62828;--red-lt:#ffebee;
  --amber:#e65100;--amber-lt:#fff3e0;
  --blue:#1565c0;--blue-lt:#e3f2fd;
  --purple:#6a1b9a;--purple-lt:#f3e5f5;
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

/* ── Page header ─────────────────────────────────────────── */
.page-header{
  background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);
  border-radius:var(--radius-xl);padding:28px 32px;
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:20px;margin-bottom:28px;margin-top:40px;
  box-shadow:var(--shadow-lg);position:relative;overflow:hidden
}
.page-header::before{
  content:'';position:absolute;inset:0;
  background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/svg%3E");
  pointer-events:none
}
.header-left{position:relative}
.header-left h1{color:#fff;font-size:1.6rem;font-weight:700;letter-spacing:.2px;display:flex;align-items:center;gap:12px}
.header-left h1 i{opacity:.85}
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
  min-width:100px;cursor:default;transition:background var(--transition)
}
.hpill:hover{background:rgba(255,255,255,.22)}
.hpill .n{font-size:1.55rem;font-weight:800;color:#fff;display:block;line-height:1}
.hpill .l{font-size:.68rem;color:rgba(255,255,255,.72);text-transform:uppercase;letter-spacing:.6px;margin-top:4px;display:block}

/* ── Greeting bar ─────────────────────────────────────────── */
.greeting-bar{
  background:#fff;border-radius:var(--radius-lg);
  padding:16px 24px;margin-bottom:24px;
  box-shadow:var(--shadow);border:1px solid #e4ede5;
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px
}
.greeting-text{font-size:.95rem;color:#4a5568}
.greeting-text strong{color:var(--g700)}
.greeting-date{font-size:.82rem;color:#8a9a8b;display:flex;align-items:center;gap:6px}

/* ── Quick-nav links ──────────────────────────────────────── */
.quick-nav{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:24px}
.qlink{
  display:inline-flex;align-items:center;gap:8px;
  background:#fff;border:1.5px solid #d0dbd1;border-radius:var(--radius);
  padding:9px 16px;font-size:.83rem;font-weight:600;color:var(--gray);
  transition:all var(--transition);cursor:pointer
}
.qlink:hover{border-color:var(--g600);color:var(--g700);background:var(--g50);transform:translateY(-1px)}
.qlink i{font-size:.85rem}
.qlink.primary{background:var(--g700);border-color:var(--g700);color:#fff}
.qlink.primary:hover{background:var(--g800);border-color:var(--g800)}

/* ── Section heading ──────────────────────────────────────── */
.section-head{
  display:flex;align-items:center;gap:10px;
  margin-bottom:16px;margin-top:4px;
  font-size:.78rem;font-weight:700;color:var(--g700);
  text-transform:uppercase;letter-spacing:.7px
}
.section-head::after{content:'';flex:1;height:1.5px;background:var(--g100)}

/* ── Stat grid ───────────────────────────────────────────── */
.stat-grid{display:grid;gap:16px;margin-bottom:8px}
.sg4{grid-template-columns:repeat(4,1fr)}
.sg3{grid-template-columns:repeat(3,1fr)}

/* ── Stat card ───────────────────────────────────────────── */
.stat-card{
  background:#fff;border-radius:var(--radius-lg);
  padding:22px 24px;box-shadow:var(--shadow);
  border:1px solid #e8ede9;position:relative;overflow:hidden;
  transition:transform var(--transition),box-shadow var(--transition);
  animation:fadeUp .45s ease both
}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg)}
.card-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:14px}
.card-icon{width:44px;height:44px;border-radius:var(--radius);display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.card-label{font-size:.73rem;font-weight:700;color:#6b7c6d;text-transform:uppercase;letter-spacing:.5px}
.card-value{font-size:2.05rem;font-weight:800;color:var(--g800);letter-spacing:-1px;line-height:1;margin-bottom:8px}
.card-meta{font-size:.78rem;color:#8a9a8b;display:flex;align-items:center;gap:6px}
.tag{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:700}
.tag-up{background:#e8f5e9;color:#2e7d32}
.tag-danger{background:var(--red-lt);color:var(--red)}
.tag-amber{background:var(--amber-lt);color:var(--amber)}
.tag-neutral{background:#f1f5f9;color:#64748b}
/* icon palette */
.ic-green{background:var(--g100);color:var(--g700)}
.ic-blue{background:var(--blue-lt);color:var(--blue)}
.ic-amber{background:var(--amber-lt);color:var(--amber)}
.ic-red{background:var(--red-lt);color:var(--red)}
.ic-purple{background:var(--purple-lt);color:var(--purple)}
.ic-teal{background:#e0f2f1;color:#00695c}

/* ── Stagger animation ────────────────────────────────────── */
.stat-card:nth-child(1){animation-delay:.05s}.stat-card:nth-child(2){animation-delay:.10s}
.stat-card:nth-child(3){animation-delay:.15s}.stat-card:nth-child(4){animation-delay:.20s}
.stat-card:nth-child(5){animation-delay:.25s}.stat-card:nth-child(6){animation-delay:.30s}
.stat-card:nth-child(7){animation-delay:.35s}

/* ── Chart cards ─────────────────────────────────────────── */
.chart-row{display:grid;gap:20px;margin-top:28px}
.cr2{grid-template-columns:1fr 1fr}
.cr1{grid-template-columns:1fr}
.chart-card{background:#fff;border-radius:var(--radius-lg);border:1px solid #e8ede9;box-shadow:var(--shadow);overflow:hidden}
.chart-head{padding:20px 24px 0}
.chart-head-inner{display:flex;align-items:center;justify-content:space-between;padding-bottom:16px;border-bottom:1px solid #f0f4f1}
.chart-title{font-size:1rem;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px}
.chart-title i{color:var(--g600)}
.chart-subtitle{font-size:.78rem;color:#8a9a8b;margin-top:2px}
.chart-body{padding:24px}
.chart-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;height:220px;color:#9aaa9b;gap:10px}
.chart-empty i{font-size:2rem;opacity:.4}
.chart-empty p{font-size:.85rem}

/* ── Insight lists ────────────────────────────────────────── */
.insight-row{display:grid;gap:20px;margin-top:20px}
.ir4{grid-template-columns:repeat(4,1fr)}
.insight-card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);border:1px solid #e8ede9;overflow:hidden}
.insight-head{padding:16px 20px;border-bottom:1px solid #f0f4f1;display:flex;align-items:center;gap:9px}
.insight-head h3{font-size:.9rem;font-weight:700;color:#1f2937}
.insight-head i{font-size:.9rem;color:var(--g600)}
.insight-list{padding:8px 0}
.insight-item{display:flex;align-items:center;justify-content:space-between;padding:10px 20px;border-bottom:1px solid #f8f9f8;gap:10px}
.insight-item:last-child{border-bottom:none}
.insight-item:hover{background:#fafcfa}
.ii-name{font-size:.82rem;font-weight:600;color:#1f2937;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ii-sub{font-size:.73rem;color:#8a9a8b;margin-top:2px}
.ii-badge{font-size:.72rem;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap;flex-shrink:0}
.ib-green{background:var(--g100);color:var(--g700)}
.ib-amber{background:var(--amber-lt);color:var(--amber)}
.ib-red{background:var(--red-lt);color:var(--red)}
.ib-gray{background:#f1f5f9;color:#64748b}
.insight-empty{padding:28px 20px;text-align:center;color:#9aaa9b;font-size:.83rem}

/* ── Notification stack ───────────────────────────────────── */
#notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.notif{background:#fff;border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow-lg);display:flex;align-items:flex-start;gap:12px;border-left:4px solid var(--g600);animation:notifIn .3s ease}
.notif.error{border-left-color:var(--red)}.notif.warning{border-left-color:var(--amber)}.notif.info{border-left-color:var(--blue)}
@keyframes notifIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.notif-icon{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.notif.success .notif-icon{color:var(--g700)}.notif.error .notif-icon{color:var(--red)}.notif.warning .notif-icon{color:var(--amber)}.notif.info .notif-icon{color:var(--blue)}
.notif-body{flex:1}.notif-title{font-weight:700;font-size:.85rem;margin-bottom:2px}.notif-msg{font-size:.8rem;color:#666}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:1rem;padding:0;line-height:1}

/* ── Footer ──────────────────────────────────────────────── */
.dash-footer{background:var(--g800);color:#fff;padding:22px 0;margin-top:48px}
.footer-inner{max-width:1600px;margin:0 auto;padding:0 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px}
.footer-copy{font-size:.8rem;color:rgba(255,255,255,.6)}
.footer-links{display:flex;gap:16px;flex-wrap:wrap}
.footer-links a{font-size:.78rem;color:rgba(255,255,255,.65);transition:color var(--transition)}
.footer-links a:hover{color:#fff}

/* ── Animations ─────────────────────────────────────────── */
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}

/* ── Responsive ─────────────────────────────────────────── */
@media(max-width:1200px){.sg4{grid-template-columns:repeat(2,1fr)}.ir4{grid-template-columns:repeat(2,1fr)}}
@media(max-width:900px){.cr2{grid-template-columns:1fr}.sg3{grid-template-columns:repeat(2,1fr)}.page-header{flex-direction:column}}
@media(max-width:600px){.sg4,.sg3,.cr2,.ir4{grid-template-columns:1fr}.header-pills{width:100%}.hpill{flex:1}}
</style>
</head>
<body>
<?php require_once 'nav.php' ?>

<div id="notif-stack"></div>

<!-- ── Page ─────────────────────────────────────────────────── -->
<div class="page">

  <!-- Header -->
  <div class="page-header">
    <div class="header-left">
      <h1><i class="fas fa-book-open"></i> Library Dashboard</h1>
      <p class="subtitle"><?= $school_name ?> — Library Management</p>
      <span class="date-badge"><i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($today_label) ?></span>
    </div>
    <div class="header-pills">
      <div class="hpill"><span class="n"><?= number_format($stats['total_books']) ?></span><span class="l">Total Books</span></div>
      <div class="hpill"><span class="n"><?= number_format($stats['borrowed_books']) ?></span><span class="l">On Loan</span></div>
      <div class="hpill"><span class="n"><?= number_format($stats['overdue_books']) ?></span><span class="l">Overdue</span></div>
      <div class="hpill"><span class="n"><?= number_format($stats['total_members']) ?></span><span class="l">Members</span></div>
    </div>
  </div>

  <!-- Greeting -->
  <div class="greeting-bar">
    <div class="greeting-text" id="greeting">Welcome back, <strong><?= $user_name ?></strong>!</div>
    <div class="greeting-date"><i class="fas fa-clock"></i> <?= $today_label ?></div>
  </div>

  <!-- Quick nav -->
  <div class="quick-nav">
    <a href="lend_books.php"    class="qlink primary"><i class="fas fa-hand-holding-heart"></i> Lend Books</a>
    <a href="library_books.php" class="qlink"><i class="fas fa-book"></i> Manage Catalog</a>
    <a href="#charts"           class="qlink"><i class="fas fa-chart-bar"></i> Analytics</a>
  </div>

  <!-- ── Section: Core stats ─────────────────────────────── -->
  <div class="section-head"><i class="fas fa-chart-pie"></i> Library Overview</div>
  <div class="stat-grid sg4">
    <!-- Total books -->
    <div class="stat-card">
      <div class="card-top">
        <div><div class="card-label">Total Book Copies</div></div>
        <div class="card-icon ic-green"><i class="fas fa-books"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['total_books']) ?></div>
      <div class="card-meta">
        <span class="tag tag-up"><i class="fas fa-plus"></i> <?= $stats['new_books_30d'] ?> new</span>
        in the last 30 days
      </div>
    </div>
    <!-- Available -->
    <div class="stat-card">
      <div class="card-top">
        <div><div class="card-label">Available Copies</div></div>
        <div class="card-icon ic-teal"><i class="fas fa-check-circle"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['available_books']) ?></div>
      <div class="card-meta">
        <span class="tag tag-neutral"><?= $stats['total_titles'] ?> unique titles</span>
      </div>
    </div>
    <!-- On Loan -->
    <div class="stat-card">
      <div class="card-top">
        <div><div class="card-label">Currently On Loan</div></div>
        <div class="card-icon ic-amber"><i class="fas fa-exchange-alt"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['borrowed_books']) ?></div>
      <div class="card-meta">
        <span class="tag tag-neutral"><i class="fas fa-calendar-day"></i> <?= $stats['borrows_today'] ?> issued today</span>
      </div>
    </div>
    <!-- Overdue -->
    <div class="stat-card">
      <div class="card-top">
        <div><div class="card-label">Overdue Returns</div></div>
        <div class="card-icon ic-red"><i class="fas fa-exclamation-triangle"></i></div>
      </div>
      <div class="card-value" style="color:<?= $stats['overdue_books'] > 0 ? 'var(--red)' : 'var(--g800)' ?>">
        <?= number_format($stats['overdue_books']) ?>
      </div>
      <div class="card-meta">
        <?php if ($stats['overdue_books'] > 0): ?>
          <span class="tag tag-danger"><i class="fas fa-bell"></i> Needs attention</span>
        <?php else: ?>
          <span class="tag tag-up"><i class="fas fa-check"></i> All on time</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Secondary stats -->
  <div class="stat-grid sg3" style="margin-top:16px">
    <div class="stat-card">
      <div class="card-top">
        <div><div class="card-label">Total Borrowings</div></div>
        <div class="card-icon ic-blue"><i class="fas fa-history"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['total_borrowings']) ?></div>
      <div class="card-meta">All-time lending records</div>
    </div>
    <div class="stat-card">
      <div class="card-top">
        <div><div class="card-label">Unique Members</div></div>
        <div class="card-icon ic-purple"><i class="fas fa-users"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['total_members']) ?></div>
      <div class="card-meta">Students who have borrowed</div>
    </div>
    <div class="stat-card">
      <div class="card-top">
        <div><div class="card-label">Subjects Covered</div></div>
        <div class="card-icon ic-teal"><i class="fas fa-graduation-cap"></i></div>
      </div>
      <div class="card-value"><?= number_format($stats['total_subjects']) ?></div>
      <div class="card-meta"><?= $stats['total_titles'] ?> unique book titles</div>
    </div>
  </div>

  <!-- ── Charts ──────────────────────────────────────────── -->
  <div id="charts" class="chart-row cr2" style="margin-top:32px">
    <!-- Monthly trend -->
    <div class="chart-card">
      <div class="chart-head">
        <div class="chart-head-inner">
          <div>
            <div class="chart-title"><i class="fas fa-chart-line"></i> Monthly Borrowing Trends</div>
            <div class="chart-subtitle">Books borrowed per month — <?= date('Y') ?></div>
          </div>
        </div>
      </div>
      <div class="chart-body">
        <?php if (array_sum($monthly) === 0): ?>
          <div class="chart-empty"><i class="fas fa-chart-line"></i><p>No borrowing data for this year yet.</p></div>
        <?php else: ?>
          <canvas id="trendsChart" height="240"></canvas>
        <?php endif; ?>
      </div>
    </div>

    <!-- Subject distribution -->
    <div class="chart-card">
      <div class="chart-head">
        <div class="chart-head-inner">
          <div>
            <div class="chart-title"><i class="fas fa-chart-pie"></i> Books by Subject</div>
            <div class="chart-subtitle">Distribution of copies across subjects</div>
          </div>
        </div>
      </div>
      <div class="chart-body">
        <?php if (empty($subjectData)): ?>
          <div class="chart-empty"><i class="fas fa-book"></i><p>No subject data in catalog yet.</p></div>
        <?php else: ?>
          <canvas id="subjectChart" height="240"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Popular books bar chart -->
  <?php if (!empty($popularBooks)): ?>
  <div class="chart-row cr1" style="margin-top:20px">
    <div class="chart-card">
      <div class="chart-head">
        <div class="chart-head-inner">
          <div>
            <div class="chart-title"><i class="fas fa-fire"></i> Most Borrowed Books</div>
            <div class="chart-subtitle">Top titles by number of borrow events</div>
          </div>
        </div>
      </div>
      <div class="chart-body"><canvas id="popularChart" height="200"></canvas></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Insight lists ───────────────────────────────────── -->
  <div class="section-head" style="margin-top:36px"><i class="fas fa-lightbulb"></i> Library Insights</div>
  <div class="insight-row ir4">

    <!-- Top borrowers -->
    <div class="insight-card">
      <div class="insight-head">
        <i class="fas fa-trophy"></i>
        <h3>Top Borrowers</h3>
      </div>
      <div class="insight-list">
        <?php if (empty($topMembers)): ?>
          <div class="insight-empty">No borrowing data yet.</div>
        <?php else: foreach ($topMembers as $i => $m): ?>
          <div class="insight-item">
            <div>
              <div class="ii-name"><?= htmlspecialchars($m['student_name']) ?></div>
              <div class="ii-sub">#<?= $i + 1 ?> borrower</div>
            </div>
            <span class="ii-badge ib-green"><?= (int)$m['borrow_count'] ?> books</span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Never borrowed -->
    <div class="insight-card">
      <div class="insight-head">
        <i class="fas fa-book-dead"></i>
        <h3>Never Borrowed</h3>
      </div>
      <div class="insight-list">
        <?php if (empty($unpopularBooks)): ?>
          <div class="insight-empty">All books have been borrowed at least once!</div>
        <?php else: foreach ($unpopularBooks as $b): ?>
          <div class="insight-item">
            <div class="ii-name"><?= htmlspecialchars($b['title']) ?></div>
            <span class="ii-badge ib-gray">0 borrows</span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Needs reorder -->
    <div class="insight-card">
      <div class="insight-head">
        <i class="fas fa-boxes"></i>
        <h3>Needs Reorder</h3>
      </div>
      <div class="insight-list">
        <?php if (empty($reorderBooks)): ?>
          <div class="insight-empty">No books flagged for reorder.</div>
        <?php else: foreach ($reorderBooks as $b): ?>
          <div class="insight-item">
            <div>
              <div class="ii-name"><?= htmlspecialchars($b['title']) ?></div>
              <div class="ii-sub"><?= (int)$b['copies'] ?> copy · <?= (int)$b['borrow_count'] ?>× borrowed</div>
            </div>
            <span class="ii-badge ib-amber">Reorder</span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Inactive members -->
    <div class="insight-card">
      <div class="insight-head">
        <i class="fas fa-user-clock"></i>
        <h3>Inactive Members</h3>
      </div>
      <div class="insight-list">
        <?php if (empty($inactiveMembers)): ?>
          <div class="insight-empty">All members active within 3 months.</div>
        <?php else: foreach ($inactiveMembers as $m): ?>
          <div class="insight-item">
            <div>
              <div class="ii-name"><?= htmlspecialchars($m['student_name']) ?></div>
              <div class="ii-sub">Last borrow: <?= $m['last_borrow'] ? date('M j, Y', strtotime($m['last_borrow'])) : 'N/A' ?></div>
            </div>
            <span class="ii-badge ib-red">Inactive</span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

  </div><!-- /insight-row -->

</div><!-- /page -->

<!-- ── Footer ────────────────────────────────────────────── -->
<div class="dash-footer">
  <div class="footer-inner">
    <span class="footer-copy">&copy; <?= date('Y') ?> <?= $school_name ?> — Library Management</span>
    <div class="footer-links">
      <a href="librarian_dashboard.php">Dashboard</a>
      <a href="library_books.php">Catalog</a>
      <a href="lend_books.php">Lending</a>
    </div>
  </div>
</div>

<script>
const MONTHLY   = <?= $js_monthly ?>;
const SUB_LAB   = <?= $js_subject_labels ?>;
const SUB_TOT   = <?= $js_subject_totals ?>;
const POP_LAB   = <?= $js_pop_labels ?>;
const POP_CNT   = <?= $js_pop_counts ?>;
const NOTIF     = <?= $js_notification ?>;
const USER_NAME = <?= json_encode($user_name, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

// ── Greeting ─────────────────────────────────────────────
(function(){
  const h = new Date().getHours();
  const greet = h<12?'Good morning':h<17?'Good afternoon':h<22?'Good evening':'Good night';
  const phrases = {
    morning:  ['Ready to organise knowledge today?','Your library awaits your guidance.','Let\'s kickstart another productive day.'],
    afternoon:['Hope your day is going smoothly.','Midday check-in on your library.','Keep up the great work!'],
    evening:  ['Winding down another successful day.','Time to review today\'s library activities.','Your dedication shows.'],
    night:    ['Thank you for your commitment.','Rest well — you\'ve done great today.','A successful day in the books.']
  };
  const pool = h<12?phrases.morning:h<17?phrases.afternoon:h<22?phrases.evening:phrases.night;
  const phrase = pool[Math.floor(Math.random()*pool.length)];
  document.getElementById('greeting').innerHTML =
    `<strong>${greet}, ${esc(USER_NAME)}!</strong> ${esc(phrase)}`;
})();

// ── Notify system ─────────────────────────────────────────
function notify(title, msg, type='success', dur=5000){
  const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
  const n=document.createElement('div');
  n.className=`notif ${type}`;
  n.innerHTML=`<i class="fas ${icons[type]||icons.info} notif-icon"></i>
    <div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div>
    <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
  document.getElementById('notif-stack').prepend(n);
  setTimeout(()=>{n.style.opacity='0';n.style.transform='translateX(30px)';n.style.transition='.3s';setTimeout(()=>n.remove(),320);},dur);
}
function esc(v){return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

// ── Session notification ───────────────────────────────────
if(NOTIF){notify(NOTIF.type==='success'?'Success':'Error', NOTIF.message, NOTIF.type);}

// ── Chart.js tooltip base (Chart.js 4.x) ─────────────────
const tooltip = {
  backgroundColor:'rgba(17,24,39,.92)',
  titleColor:'#fff', bodyColor:'#e5e7eb',
  borderColor:'rgba(255,255,255,.08)', borderWidth:1,
  cornerRadius:8, padding:10, displayColors:true
};

document.addEventListener('DOMContentLoaded', () => {
  // ── Monthly trends ──────────────────────────────────────
  const tEl = document.getElementById('trendsChart');
  if(tEl){
    new Chart(tEl, {
      type:'line',
      data:{
        labels:['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
        datasets:[{
          label:'Books Borrowed', data:MONTHLY,
          backgroundColor:'rgba(56,142,60,.12)', borderColor:'#388e3c',
          borderWidth:3, tension:.4, fill:true,
          pointBackgroundColor:'#2e7d32', pointBorderColor:'#fff',
          pointBorderWidth:3, pointRadius:5, pointHoverRadius:7
        }]
      },
      options:{
        responsive:true, maintainAspectRatio:false,
        animation:{duration:900, easing:'easeOutQuart'},
        plugins:{legend:{display:false}, tooltip:{...tooltip,callbacks:{label:c=>`  Borrowed: ${c.parsed.y.toLocaleString()}`}}},
        scales:{
          x:{grid:{display:false},ticks:{color:'#6b7280',font:{size:11}}},
          y:{beginAtZero:true,border:{dash:[4,4]},grid:{color:'rgba(226,232,240,.65)'},ticks:{precision:0,color:'#9ca3af',font:{size:11},callback:v=>v.toLocaleString()}}
        }
      }
    });
  }

  // ── Subject pie ─────────────────────────────────────────
  const sEl = document.getElementById('subjectChart');
  if(sEl && SUB_LAB.length){
    const palette=['#388e3c','#1565c0','#e65100','#6a1b9a','#00695c','#c62828','#0277bd','#558b2f','#4527a0','#00838f'];
    new Chart(sEl, {
      type:'doughnut',
      data:{
        labels:SUB_LAB,
        datasets:[{data:SUB_TOT, backgroundColor:palette.slice(0,SUB_LAB.length), borderColor:'#fff', borderWidth:3, hoverOffset:6}]
      },
      options:{
        responsive:true, maintainAspectRatio:false, cutout:'52%',
        plugins:{
          legend:{position:'bottom',labels:{font:{size:11,weight:'600'},color:'#374151',padding:14,usePointStyle:true}},
          tooltip:{...tooltip,callbacks:{label:c=>` ${c.label}: ${c.parsed.toLocaleString()} copies`}}
        }
      }
    });
  }

  // ── Popular books bar ───────────────────────────────────
  const pEl = document.getElementById('popularChart');
  if(pEl && POP_LAB.length){
    new Chart(pEl, {
      type:'bar',
      data:{
        labels:POP_LAB,
        datasets:[{
          label:'Times Borrowed', data:POP_CNT,
          backgroundColor:'rgba(56,142,60,.8)', borderColor:'#2e7d32', borderWidth:1,
          borderRadius:6, maxBarThickness:42
        }]
      },
      options:{
        responsive:true, maintainAspectRatio:false,
        animation:{duration:900, easing:'easeOutQuart'},
        plugins:{legend:{display:false}, tooltip:{...tooltip,callbacks:{label:c=>` Borrowed: ${c.parsed.y}×`}}},
        scales:{
          x:{grid:{display:false},ticks:{color:'#6b7280',font:{size:11},maxRotation:30}},
          y:{beginAtZero:true,border:{dash:[4,4]},grid:{color:'rgba(226,232,240,.65)'},ticks:{precision:0,color:'#9ca3af',font:{size:11}}}
        }
      }
    });
  }
});
</script>
</body>
</html>