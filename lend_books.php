<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction('Lend Library Books');

// ── Access control ─────────────────────────────────────────────────────────────
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
        $_SESSION['previous_page']         = $_SERVER['REQUEST_URI'];
        $_SESSION['access_denied_message'] = "You don't have permission to access the library management system.";
        header('Location: access_denied.php');
        exit;
    }
}
checkLibraryAccess($conn);

date_default_timezone_set('Africa/Kampala');

// ── CSRF ───────────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Security token expired. Please try again.'];
        header('Location: lend_books.php');
        exit;
    }
}

// ── JSON output helper ─────────────────────────────────────────────────────────
function jsonOut($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    ob_clean();
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX — GET handlers
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    $ajax = $_GET['ajax'];

    // ── All borrowings (for JS table render) ──────────────────────────────────
    if ($ajax === 'get_borrowings') {
        global $conn;
        $res  = $conn->query("SELECT * FROM book_borrowings ORDER BY created_at DESC");
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $now  = time();
        foreach ($rows as &$r) {
            $r['is_overdue']     = ($r['status'] === 'borrowed' && strtotime($r['due_date']) < $now);
            $r['display_status'] = $r['is_overdue'] ? 'overdue' : $r['status'];
        }
        unset($r);
        jsonOut($rows);
    }

    // ── Search students ───────────────────────────────────────────────────────
    if ($ajax === 'search_students') {
        global $conn;
        $q    = trim($_GET['q'] ?? '');
        $like = '%' . $q . '%';
        $stmt = $conn->prepare("
            SELECT student_id, first_name, last_name, current_class, stream, section
            FROM   students
            WHERE  status = 'active'
              AND  (CONCAT(first_name,' ',last_name) LIKE ? OR first_name LIKE ? OR last_name LIKE ?)
            ORDER  BY first_name, last_name
            LIMIT  20
        ");
        $stmt->bind_param('sss', $like, $like, $like);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        jsonOut($rows);
    }

    // ── Search staff ──────────────────────────────────────────────────────────
    if ($ajax === 'search_staff') {
        global $conn;
        $q    = trim($_GET['q'] ?? '');
        $like = '%' . $q . '%';
        $stmt = $conn->prepare("
            SELECT id, first_name, last_name, designation, department, staff_id
            FROM   staff
            WHERE  Status = 'Active'
              AND  (CONCAT(first_name,' ',last_name) LIKE ? OR first_name LIKE ? OR last_name LIKE ?)
            ORDER  BY first_name, last_name
            LIMIT  20
        ");
        $stmt->bind_param('sss', $like, $like, $like);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        jsonOut($rows);
    }

    // ── Search books from catalog ─────────────────────────────────────────────
    if ($ajax === 'search_books') {
        global $conn;
        $q    = trim($_GET['q'] ?? '');
        $like = '%' . $q . '%';
        $stmt = $conn->prepare("
            SELECT book_id, title, subject, publisher, copies
            FROM   books
            WHERE  title LIKE ? OR subject LIKE ?
            ORDER  BY title LIMIT 15
        ");
        $stmt->bind_param('ss', $like, $like);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        jsonOut($rows);
    }

    // ── Borrowing details (view modal) ────────────────────────────────────────
    if ($ajax === 'borrowing_details' && isset($_GET['id'])) {
        global $conn;
        $id   = (int) $_GET['id'];
        $stmt = $conn->prepare("SELECT * FROM book_borrowings WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row  = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) { jsonOut(['error' => 'Record not found'], 404); }
        $row['is_overdue']    = ($row['status'] === 'borrowed' && strtotime($row['due_date']) < time());
        $row['days_borrowed'] = (int) floor((time() - strtotime($row['created_at'])) / 86400);
        jsonOut($row);
    }

    // ── Edit data ─────────────────────────────────────────────────────────────
    if ($ajax === 'edit_data' && isset($_GET['id'])) {
        global $conn;
        $id   = (int) $_GET['id'];
        $stmt = $conn->prepare("SELECT * FROM book_borrowings WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row  = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        jsonOut($row ?: ['error' => 'Not found']);
    }

    jsonOut(['error' => 'Unknown action'], 400);
}

// ─────────────────────────────────────────────────────────────────────────────
// POST — write handlers
// ─────────────────────────────────────────────────────────────────────────────

// ── Return book ────────────────────────────────────────────────────────────────
if (isset($_POST['return_book'])) {
    verifyCsrf();
    $borrowId = (int) ($_POST['borrow_id'] ?? 0);
    if ($borrowId <= 0) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Invalid borrow record.'];
        header('Location: lend_books.php'); exit;
    }
    $returnDate = date('Y-m-d');
    $returnTime = date('H:i:s');
    $stmt = $conn->prepare("
        UPDATE book_borrowings
        SET    return_date = ?, return_time = ?, status = 'returned'
        WHERE  id = ? AND status = 'borrowed'
    ");
    $stmt->bind_param('ssi', $returnDate, $returnTime, $borrowId);
    $stmt->execute();
    $_SESSION['notification'] = $stmt->affected_rows > 0
        ? ['type' => 'success', 'message' => 'Book marked as returned successfully.']
        : ['type' => 'error',   'message' => 'Could not update — the book may already be returned.'];
    $stmt->close();
    header('Location: lend_books.php'); exit;
}

// ── Add borrowing ──────────────────────────────────────────────────────────────
if (isset($_POST['add_borrowing'])) {
    verifyCsrf();
    $borrowDate  = $_POST['borrow_date']   ?? '';
    $borrowTime  = $_POST['borrow_time']   ?? date('H:i:s');
    $studentName = trim($_POST['student_name']  ?? '');
    $studentClass= trim($_POST['student_class'] ?? '');
    $bookTitle   = trim($_POST['book_title']    ?? '');
    $subject     = trim($_POST['subject']       ?? '');
    $publisher   = trim($_POST['publisher']     ?? '');
    $dueDate     = $_POST['due_date']      ?? '';
    $notes       = trim($_POST['notes']         ?? '');
    $bookId      = (int) ($_POST['book_id'] ?? 0) ?: null;

    $borrowDateObj = DateTime::createFromFormat('Y-m-d', $borrowDate);
    $dueDateObj    = DateTime::createFromFormat('Y-m-d', $dueDate);

    if (!$borrowDateObj || $borrowDateObj->format('Y-m-d') !== $borrowDate) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Invalid borrow date provided.'];
        header('Location: lend_books.php'); exit;
    }
    if (!$dueDateObj || $dueDateObj->format('Y-m-d') !== $dueDate) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Invalid due date provided.'];
        header('Location: lend_books.php'); exit;
    }
    if ($dueDateObj < $borrowDateObj) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Due date cannot be before borrow date.'];
        header('Location: lend_books.php'); exit;
    }
    if ($studentName === '' || $bookTitle === '') {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Borrower name and book title are required.'];
        header('Location: lend_books.php'); exit;
    }

    $createdAt = $borrowDate . ' ' . $borrowTime;
    $stmt = $conn->prepare("
        INSERT INTO book_borrowings
            (borrow_date, borrow_time, student_name, student_class,
             book_title, subject, publisher, due_date, notes, created_at, status, book_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'borrowed', ?)
    ");
    $stmt->bind_param('ssssssssssi',
        $borrowDate, $borrowTime, $studentName, $studentClass,
        $bookTitle, $subject, $publisher, $dueDate, $notes, $createdAt, $bookId
    );
    $_SESSION['notification'] = $stmt->execute()
        ? ['type' => 'success', 'message' => 'Book borrowing recorded successfully!']
        : ['type' => 'error',   'message' => 'Failed to record borrowing. Please try again.'];
    $stmt->close();
    header('Location: lend_books.php'); exit;
}

// ── Edit borrowing ─────────────────────────────────────────────────────────────
if (isset($_POST['edit_borrowing'])) {
    verifyCsrf();
    $borrowId    = (int) ($_POST['borrow_id']   ?? 0);
    $borrowDate  = $_POST['borrow_date']   ?? '';
    $borrowTime  = $_POST['borrow_time']   ?? '';
    $studentName = trim($_POST['student_name']  ?? '');
    $studentClass= trim($_POST['student_class'] ?? '');
    $bookTitle   = trim($_POST['book_title']    ?? '');
    $subject     = trim($_POST['subject']       ?? '');
    $publisher   = trim($_POST['publisher']     ?? '');
    $dueDate     = $_POST['due_date']      ?? '';
    $notes       = trim($_POST['notes']         ?? '');

    if ($borrowId <= 0) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Invalid record ID.'];
        header('Location: lend_books.php'); exit;
    }
    $borrowDateObj = DateTime::createFromFormat('Y-m-d', $borrowDate);
    $dueDateObj    = DateTime::createFromFormat('Y-m-d', $dueDate);
    if (!$borrowDateObj || !$dueDateObj) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Invalid dates provided.'];
        header('Location: lend_books.php'); exit;
    }

    $stmt = $conn->prepare("
        UPDATE book_borrowings
        SET    borrow_date=?, borrow_time=?, student_name=?, student_class=?,
               book_title=?, subject=?, publisher=?, due_date=?, notes=?
        WHERE  id=?
    ");
    $stmt->bind_param('sssssssssi',
        $borrowDate, $borrowTime, $studentName, $studentClass,
        $bookTitle, $subject, $publisher, $dueDate, $notes, $borrowId
    );
    $_SESSION['notification'] = $stmt->execute()
        ? ['type' => 'success', 'message' => 'Borrowing record updated successfully!']
        : ['type' => 'error',   'message' => 'Failed to update record. Please try again.'];
    $stmt->close();
    header('Location: lend_books.php'); exit;
}

// ── Delete borrowing ───────────────────────────────────────────────────────────
if (isset($_POST['delete_borrowing'])) {
    verifyCsrf();
    $borrowId = (int) ($_POST['borrow_id'] ?? 0);
    if ($borrowId <= 0) {
        $_SESSION['notification'] = ['type' => 'error', 'message' => 'Invalid record ID.'];
        header('Location: lend_books.php'); exit;
    }
    $stmt = $conn->prepare("DELETE FROM book_borrowings WHERE id = ?");
    $stmt->bind_param('i', $borrowId);
    $_SESSION['notification'] = $stmt->execute()
        ? ['type' => 'success', 'message' => 'Borrowing record deleted.']
        : ['type' => 'error',   'message' => 'Failed to delete the record.'];
    $stmt->close();
    header('Location: lend_books.php'); exit;
}

// ── Header stats (separate query — table is now AJAX-rendered) ─────────────────
try {
    $statsRow = $conn->query("
        SELECT
            COALESCE(SUM(status = 'borrowed'), 0)                           AS on_loan,
            COALESCE(SUM(status = 'returned'), 0)                           AS returned,
            COALESCE(SUM(status = 'borrowed' AND due_date < CURDATE()), 0)  AS overdue,
            COUNT(*)                                                          AS total
        FROM book_borrowings
    ")->fetch_assoc();
} catch (Throwable $e) {
    $statsRow = ['on_loan' => 0, 'returned' => 0, 'overdue' => 0, 'total' => 0];
}

// ── Session notification & JS vars ────────────────────────────────────────────
$session_notification = null;
if (isset($_SESSION['notification'])) {
    $session_notification = $_SESSION['notification'];
    unset($_SESSION['notification']);
}
$js_notification = $session_notification
    ? json_encode($session_notification, JSON_HEX_TAG | JSON_HEX_AMP)
    : 'null';
$js_csrf = json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP);

$school_name = htmlspecialchars($_SESSION['school_name'] ?? 'School Pilot', ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Lend Books &mdash; <?= $school_name ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
<style>
/* ── Design tokens ───────────────────────────────────────── */
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#c62828;--red-lt:#ffebee;
  --amber:#e65100;--amber-lt:#fff3e0;
  --blue:#1565c0;--blue-lt:#e3f2fd;
  --gray:#546e7a;
  --radius:8px;--radius-lg:12px;--radius-xl:16px;
  --shadow:0 2px 8px rgba(0,0,0,.08);
  --shadow-lg:0 8px 28px rgba(0,0,0,.13);
  --transition:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}

/* ── Page ────────────────────────────────────────────────── */
.page{max-width:100%;margin:0 auto;padding:24px 20px 56px}

/* ── Page header ─────────────────────────────────────────── */
.page-header{
  background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);
  border-radius:var(--radius-xl);padding:28px 32px;
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:20px;margin-bottom:24px;margin-top:40px;
  box-shadow:var(--shadow-lg);position:relative;overflow:hidden
}
.page-header::before{
  content:'';position:absolute;inset:0;
  background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/svg%3E");
  pointer-events:none
}
.page-header h1{color:#fff;font-size:1.55rem;font-weight:700;display:flex;align-items:center;gap:12px;position:relative}
.page-header p{color:rgba(255,255,255,.72);font-size:.9rem;margin-top:4px;position:relative}
.stats-row{display:flex;gap:12px;flex-wrap:wrap;position:relative}
.stat-pill{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:40px;padding:8px 18px;text-align:center;min-width:86px;cursor:default;transition:background var(--transition)}
.stat-pill:hover{background:rgba(255,255,255,.22)}
.stat-pill .n{font-size:1.35rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.7rem;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.5px}
.stat-pill.danger .n{color:#ffcdd2}

/* ── Card ────────────────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden;margin-bottom:24px}

/* ── Toolbar ─────────────────────────────────────────────── */
.toolbar{padding:16px 22px;border-bottom:1px solid #e8ede9;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.toolbar-left{display:flex;flex-wrap:wrap;gap:10px;align-items:center;flex:1}
.toolbar-right{display:flex;gap:8px;align-items:center;flex-shrink:0}
.search-wrap{position:relative;min-width:220px}
.search-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.82rem}
.search-wrap input{width:100%;padding:9px 12px 9px 32px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;transition:border-color var(--transition)}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.filter-select{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;background:#fff;cursor:pointer;min-width:130px}
.filter-select:focus{outline:none;border-color:var(--g600)}
.result-count{font-size:.8rem;color:#6b7c6d;white-space:nowrap}

/* ── Buttons ─────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;transition:all var(--transition);white-space:nowrap;cursor:pointer}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-danger{background:var(--red);color:#fff}.btn-danger:hover{background:#b71c1c}
.btn-amber{background:var(--amber);color:#fff}.btn-amber:hover{background:#bf360c}
.btn-sm{padding:6px 11px;font-size:.78rem}

/* ── Icon buttons ────────────────────────────────────────── */
.action-cell{display:flex;gap:5px;align-items:center}
.btn-icon{width:30px;height:30px;border:none;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;transition:all var(--transition);flex-shrink:0;font-family:inherit}
.btn-icon:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.18)}
.bi-view{background:#e3f2fd;color:var(--blue)}.bi-view:hover{background:var(--blue);color:#fff}
.bi-edit{background:#fff3e0;color:var(--amber)}.bi-edit:hover{background:var(--amber);color:#fff}
.bi-return{background:#e8f5e9;color:var(--g700)}.bi-return:hover{background:var(--g700);color:#fff}
.bi-delete{background:#ffebee;color:var(--red)}.bi-delete:hover{background:var(--red);color:#fff}

/* ── Table ───────────────────────────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:12px 14px;text-align:left;font-size:.78rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--transition)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:12px 14px;font-size:.85rem;vertical-align:middle}

/* ── Skeleton loader ─────────────────────────────────────── */
@keyframes sk-shimmer{
  0%{background-position:200% 0}
  100%{background-position:-200% 0}
}
.sk-cell{
  display:inline-block;border-radius:5px;
  background:linear-gradient(90deg,#e4ebe5 25%,#f1f5f1 50%,#e4ebe5 75%);
  background-size:200% 100%;
  animation:sk-shimmer 1.6s ease-in-out infinite;
}
.sk-badge{height:22px;border-radius:12px}
.sk-text{height:13px}
.sk-text-lg{height:15px}
.sk-actions{display:flex;gap:5px;align-items:center}
.sk-btn{width:28px;height:28px;border-radius:6px}
tbody tr.sk-row td{padding:13px 14px}

/* ── Badges ──────────────────────────────────────────────── */
.badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.badge-green{background:var(--g100);color:var(--g800)}
.badge-amber{background:var(--amber-lt);color:var(--amber)}
.badge-red{background:var(--red-lt);color:var(--red)}
.badge-blue{background:var(--blue-lt);color:var(--blue)}

/* ── Empty state ─────────────────────────────────────────── */
.empty-state{text-align:center;padding:60px 20px;color:#8a9a8b}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;opacity:.4}
.empty-state p{font-size:.95rem}

/* ── Pagination ──────────────────────────────────────────── */
.pagination{padding:14px 22px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e8ede9;flex-wrap:wrap;gap:10px}
.page-info{font-size:.82rem;color:#6b7c6d}
.page-btns{display:flex;gap:4px}
.page-btn{width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:6px;background:#fff;cursor:pointer;font-size:.82rem;font-weight:600;color:#444;display:flex;align-items:center;justify-content:center;transition:all var(--transition);font-family:inherit}
.page-btn:hover:not(:disabled){border-color:var(--g600);background:var(--g100);color:var(--g800)}
.page-btn.active{background:var(--g700);border-color:var(--g700);color:#fff}
.page-btn:disabled{opacity:.38;cursor:default}

/* ── Modal ───────────────────────────────────────────────── */
.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);animation:fadeOverlay .2s ease}
@keyframes fadeOverlay{from{opacity:0}to{opacity:1}}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:780px;box-shadow:var(--shadow-lg);animation:slideDown .25s ease;margin:auto}
@keyframes slideDown{from{transform:translateY(-24px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%);padding:18px 24px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head h2{color:#fff;font-size:1.05rem;font-weight:700;display:flex;align-items:center;gap:9px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:30px;height:30px;border-radius:50%;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background var(--transition)}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:26px}
.modal-footer{padding:16px 26px;border-top:1px solid #e8ede9;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}

/* ── Form elements ───────────────────────────────────────── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 20px}
.form-grid .full{grid-column:1/-1}
.form-group label{display:block;font-size:.8rem;font-weight:600;color:#374151;margin-bottom:5px}
.form-group input,.form-group select,.form-group textarea{
  width:100%;padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);
  font-size:.875rem;font-family:inherit;transition:border-color var(--transition)
}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{
  outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.1)
}
.form-group textarea{resize:vertical;min-height:80px}
.form-group input[readonly]{background:#f5f8f5;color:#6b7c6d;cursor:default}
.form-section-title{font-size:.75rem;font-weight:700;color:var(--g700);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;margin-top:4px;border-bottom:1px solid var(--g100);padding-bottom:6px}

/* ── Borrower type toggle ────────────────────────────────── */
.borrower-type-group{display:flex;gap:10px;margin-bottom:2px}
.type-card{flex:1;display:flex;align-items:center;gap:10px;padding:10px 14px;border:2px solid #d0dbd1;border-radius:var(--radius);cursor:pointer;transition:all var(--transition);background:#fff}
.type-card:hover{border-color:var(--g400);background:var(--g50)}
.type-card.active{border-color:var(--g700);background:var(--g100)}
.type-card input[type=radio]{display:none}
.type-card-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0}
.type-card.active .type-card-icon{background:var(--g700);color:#fff}
.type-card:not(.active) .type-card-icon{background:#e8ede9;color:#6b7c6d}
.type-card-text strong{display:block;font-size:.85rem;font-weight:700;color:#1f2937}
.type-card-text span{font-size:.73rem;color:#8a9a8b}
.type-card.active .type-card-text strong{color:var(--g800)}

/* ── Person search autocomplete ──────────────────────────── */
.person-search-wrap{position:relative}
.person-search-wrap .ps-icon{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.82rem;pointer-events:none;z-index:1}
.person-search-wrap input{padding-left:32px}
.person-acl{
  position:absolute;top:100%;left:0;right:0;background:#fff;
  border:1.5px solid #d0dbd1;border-top:none;
  border-radius:0 0 var(--radius) var(--radius);
  max-height:240px;overflow-y:auto;z-index:500;box-shadow:var(--shadow-lg);display:none
}
.person-acl.open{display:block}
.person-acl-item{padding:10px 14px;cursor:pointer;border-bottom:1px solid #f0f4f1}
.person-acl-item:hover{background:var(--g50)}
.person-acl-item:last-child{border-bottom:none}
.person-acl-name{font-weight:600;font-size:.85rem;color:#1f2937}
.person-acl-meta{font-size:.75rem;color:#8a9a8b;margin-top:2px}
.person-acl-state{padding:12px 14px;font-size:.83rem;color:#8a9a8b;text-align:center;display:flex;align-items:center;justify-content:center;gap:8px}
.person-selected-card{
  display:none;margin-top:8px;padding:9px 12px;background:var(--g100);
  border:1.5px solid var(--g400);border-radius:var(--radius);
  display:none;align-items:center;gap:10px
}
.person-selected-card.visible{display:flex}
.person-selected-card .psc-name{font-weight:600;font-size:.85rem;color:var(--g800);flex:1}
.person-selected-card .psc-meta{font-size:.76rem;color:#6b7c6d}
.person-selected-card button{background:none;border:none;cursor:pointer;color:#9aaa9b;padding:2px 4px;border-radius:4px;font-size:.85rem;transition:color var(--transition)}
.person-selected-card button:hover{color:var(--red)}

/* ── Book autocomplete ───────────────────────────────────── */
.autocomplete-wrap{position:relative}
.autocomplete-list{
  position:absolute;top:100%;left:0;right:0;background:#fff;
  border:1.5px solid #d0dbd1;border-top:none;border-radius:0 0 var(--radius) var(--radius);
  max-height:220px;overflow-y:auto;z-index:200;box-shadow:var(--shadow-lg);display:none
}
.autocomplete-list.open{display:block}
.acl-item{padding:10px 14px;cursor:pointer;border-bottom:1px solid #f0f4f1;font-size:.85rem}
.acl-item:hover{background:var(--g50)}
.acl-item .acl-title{font-weight:600;color:#1f2937}
.acl-item .acl-meta{font-size:.75rem;color:#8a9a8b;margin-top:2px}
.acl-hint{font-size:.73rem;color:#8a9a8b;margin-top:5px}

/* ── View details grid ───────────────────────────────────── */
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 20px}
.detail-item{border-bottom:1px solid #f0f4f1;padding-bottom:10px}
.detail-item.full{grid-column:1/-1}
.detail-label{font-size:.73rem;font-weight:600;color:#8a9a8b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px}
.detail-value{font-size:.88rem;color:#1f2937;font-weight:500}

/* ── Confirm dialog ──────────────────────────────────────── */
.dialog{display:none;position:fixed;inset:0;z-index:1100;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);animation:fadeOverlay .2s ease}
.dialog.active{display:flex;align-items:center;justify-content:center;padding:20px}
.dialog-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:420px;box-shadow:var(--shadow-lg);animation:slideDown .25s ease}
.dialog-head{padding:18px 22px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;gap:10px}
.dialog-head.danger{background:var(--red)}
.dialog-head.warning{background:var(--amber)}
.dialog-head i,.dialog-head h3{color:#fff}
.dialog-head h3{font-size:.95rem;font-weight:700}
.dialog-body{padding:22px;font-size:.88rem;color:#374151;line-height:1.55}
.dialog-actions{padding:14px 22px;border-top:1px solid #f0f4f1;display:flex;gap:10px;justify-content:flex-end}

/* ── Notifications ───────────────────────────────────────── */
#notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.notif{background:#fff;border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow-lg);display:flex;align-items:flex-start;gap:12px;border-left:4px solid var(--g600);animation:notifIn .3s ease}
.notif.error{border-left-color:var(--red)}.notif.warning{border-left-color:var(--amber)}.notif.info{border-left-color:var(--blue)}
@keyframes notifIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.notif-icon{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.notif.success .notif-icon{color:var(--g700)}.notif.error .notif-icon{color:var(--red)}.notif.warning .notif-icon{color:var(--amber)}.notif.info .notif-icon{color:var(--blue)}
.notif-body{flex:1}.notif-title{font-weight:700;font-size:.85rem;margin-bottom:2px}.notif-msg{font-size:.8rem;color:#666}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:1rem;padding:0;line-height:1}

/* ── Responsive ─────────────────────────────────────────── */
@media(max-width:700px){
  .form-grid{grid-template-columns:1fr}
  .detail-grid{grid-template-columns:1fr}
  .page-header{flex-direction:column}
  .stats-row{width:100%}
  .borrower-type-group{flex-direction:column}
}
</style>
</head>
<body>
<?php require_once 'nav.php' ?>

<div id="notif-stack"></div>

<div class="page">

  <!-- ── Page header ──────────────────────────────────────── -->
  <div class="page-header">
    <div>
      <h1><i class="fas fa-hand-holding-heart"></i> Book Lending</h1>
      <p><?= $school_name ?> — Library Lending Management</p>
    </div>
    <div class="stats-row">
      <div class="stat-pill">
        <span class="n"><?= (int)$statsRow['on_loan'] ?></span>
        <span class="l">On Loan</span>
      </div>
      <div class="stat-pill">
        <span class="n"><?= (int)$statsRow['returned'] ?></span>
        <span class="l">Returned</span>
      </div>
      <div class="stat-pill <?= (int)$statsRow['overdue'] > 0 ? 'danger' : '' ?>">
        <span class="n"><?= (int)$statsRow['overdue'] ?></span>
        <span class="l">Overdue</span>
      </div>
      <div class="stat-pill">
        <span class="n"><?= (int)$statsRow['total'] ?></span>
        <span class="l">Total</span>
      </div>
    </div>
  </div>

  <!-- ── Borrowings table card ─────────────────────────────── -->
  <div class="card">
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search borrower, book, subject…" oninput="applyFilter()">
        </div>
        <select class="filter-select" id="statusFilter" onchange="applyFilter()">
          <option value="">All Status</option>
          <option value="borrowed">On Loan</option>
          <option value="overdue">Overdue</option>
          <option value="returned">Returned</option>
        </select>
        <span class="result-count" id="resultCount"></span>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-outline btn-sm" onclick="exportToPDF()"><i class="fas fa-file-pdf"></i> PDF</button>
        <button class="btn btn-outline btn-sm" onclick="exportToExcel()"><i class="fas fa-file-excel"></i> Excel</button>
        <button class="btn btn-primary" onclick="openAddModal()">
          <i class="fas fa-plus"></i> Record Borrowing
        </button>
      </div>
    </div>

    <div class="table-wrap">
      <table id="borrowTable">
        <thead>
          <tr>
            <th>Status</th>
            <th>Student / Teacher</th>
            <th>Class / Dept</th>
            <th>Book Title</th>
            <th>Subject</th>
            <th>Borrowed</th>
            <th>Due Date</th>
            <th>Returned</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="tableBody">
          <!-- Skeleton rows shown on initial load -->
          <?php for ($i = 0; $i < 7; $i++): ?>
          <tr class="sk-row">
            <td><span class="sk-cell sk-badge" style="width:<?= [62,74,68,62,74,68,62][$i] ?>px"></span></td>
            <td><span class="sk-cell sk-text-lg" style="width:<?= [120,140,110,130,145,118,135][$i] ?>px"></span></td>
            <td><span class="sk-cell sk-text" style="width:<?= [55,70,60,65,50,72,58][$i] ?>px"></span></td>
            <td><span class="sk-cell sk-text-lg" style="width:<?= [160,140,175,150,165,142,158][$i] ?>px"></span></td>
            <td><span class="sk-cell sk-text" style="width:<?= [80,90,70,85,75,88,78][$i] ?>px"></span></td>
            <td><span class="sk-cell sk-text" style="width:72px"></span></td>
            <td><span class="sk-cell sk-text" style="width:72px"></span></td>
            <td><span class="sk-cell sk-text" style="width:72px"></span></td>
            <td><div class="sk-actions"><span class="sk-cell sk-btn"></span><span class="sk-cell sk-btn"></span><span class="sk-cell sk-btn"></span></div></td>
          </tr>
          <?php endfor; ?>
        </tbody>
      </table>
    </div>

    <div class="pagination">
      <span class="page-info" id="paginationInfo"></span>
      <div class="page-btns" id="paginationBtns"></div>
    </div>
  </div>

</div><!-- /page -->

<!-- ══════════════════════════════════════════════════════════════
     ADD BORROWING MODAL
══════════════════════════════════════════════════════════════ -->
<div class="modal" id="addModal" onclick="if(event.target===this)closeModal('addModal')">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-plus-circle"></i> Record New Borrowing</h2>
      <button class="modal-close" onclick="closeModal('addModal')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="lend_books.php" onsubmit="return validateAddForm()">
      <input type="hidden" name="add_borrowing" value="1">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="book_id" id="addBookId">
      <!-- Hidden fields populated by JS person search -->
      <input type="hidden" name="student_name"  id="addHiddenName">
      <input type="hidden" name="student_class" id="addHiddenClass">

      <div class="modal-body">

        <!-- ── Section: Who is borrowing ─────────────────────── -->
        <div class="form-section-title"><i class="fas fa-user"></i> Who is Borrowing?</div>

        <!-- Borrower type cards -->
        <div class="borrower-type-group" id="addTypeGroup">
          <label class="type-card" id="addCardStudent" onclick="selectBorrowerType('add','student')">
            <input type="radio" name="_borrower_type_ui" value="student">
            <div class="type-card-icon"><i class="fas fa-user-graduate"></i></div>
            <div class="type-card-text">
              <strong>Student</strong>
              <span>Search from enrolled students</span>
            </div>
          </label>
          <label class="type-card" id="addCardStaff" onclick="selectBorrowerType('add','staff')">
            <input type="radio" name="_borrower_type_ui" value="staff">
            <div class="type-card-icon"><i class="fas fa-chalkboard-teacher"></i></div>
            <div class="type-card-text">
              <strong>Teacher / Staff</strong>
              <span>Search from staff directory</span>
            </div>
          </label>
        </div>

        <!-- Person search (hidden until type chosen) -->
        <div id="addPersonGroup" style="display:none;margin-top:14px">
          <div class="form-group">
            <label id="addPersonLabel">Student Name *</label>
            <div class="person-search-wrap">
              <i class="fas fa-search ps-icon"></i>
              <input type="text" id="addPersonSearch"
                     placeholder="Type a name to search…"
                     autocomplete="off"
                     oninput="debouncedPersonSearch('add', this.value)">
              <div class="person-acl" id="addPersonAcl"></div>
            </div>
            <!-- Selected person card -->
            <div class="person-selected-card" id="addPersonCard">
              <div>
                <div class="psc-name" id="addPersonCardName"></div>
                <div class="psc-meta" id="addPersonCardMeta"></div>
              </div>
              <button type="button" title="Clear selection" onclick="clearPersonSelection('add')"><i class="fas fa-times"></i></button>
            </div>
          </div>
        </div>

        <!-- Auto-filled class/dept (shown after person selected) -->
        <div id="addClassGroup" style="display:none;margin-top:0">
          <div class="form-group">
            <label id="addClassLabel">Class / Department</label>
            <input type="text" id="addClassDisplay" readonly placeholder="Auto-filled on selection">
          </div>
        </div>

        <!-- ── Section: Dates ─────────────────────────────────── -->
        <div class="form-section-title" style="margin-top:20px"><i class="fas fa-calendar"></i> Borrowing Dates</div>
        <div class="form-grid">
          <div class="form-group">
            <label>Borrow Date *</label>
            <input type="date" name="borrow_date" id="addBorrowDate" required>
          </div>
          <div class="form-group">
            <label>Borrow Time</label>
            <input type="time" name="borrow_time" id="addBorrowTime">
          </div>
          <div class="form-group">
            <label>Due Date *</label>
            <input type="date" name="due_date" id="addDueDate" required>
          </div>
        </div>

        <!-- ── Section: Book ──────────────────────────────────── -->
        <div class="form-section-title" style="margin-top:4px"><i class="fas fa-book"></i> Book Details</div>

        <!-- Book search from catalog -->
        <div class="form-group" style="margin-bottom:14px">
          <label>Search Catalog (Optional)</label>
          <div class="autocomplete-wrap">
            <input type="text" id="bookSearch" placeholder="Type title or subject to auto-fill from catalog…" autocomplete="off" oninput="searchBooks(this.value)">
            <div class="autocomplete-list" id="bookAutocomplete"></div>
          </div>
          <div class="acl-hint">Selecting a catalog book auto-fills the fields below.</div>
        </div>

        <div class="form-grid">
          <div class="form-group full">
            <label>Book Title *</label>
            <input type="text" name="book_title" id="addBookTitle" placeholder="Book title" required maxlength="200">
          </div>
          <div class="form-group">
            <label>Subject</label>
            <input type="text" name="subject" id="addSubject" placeholder="Subject" maxlength="100">
          </div>
          <div class="form-group">
            <label>Publisher</label>
            <input type="text" name="publisher" id="addPublisher" placeholder="Publisher" maxlength="150">
          </div>
          <div class="form-group full">
            <label>Notes</label>
            <textarea name="notes" id="addNotes" placeholder="Any additional notes about this borrowing…" rows="2"></textarea>
          </div>
        </div>

      </div><!-- /modal-body -->

      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Borrowing</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     EDIT BORROWING MODAL
══════════════════════════════════════════════════════════════ -->
<div class="modal" id="editModal" onclick="if(event.target===this)closeModal('editModal')">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-edit"></i> Edit Borrowing Record</h2>
      <button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="lend_books.php">
      <input type="hidden" name="edit_borrowing" value="1">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="borrow_id" id="editBorrowId">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group">
            <label>Borrow Date</label>
            <input type="date" name="borrow_date" id="editBorrowDate" required>
          </div>
          <div class="form-group">
            <label>Borrow Time</label>
            <input type="time" name="borrow_time" id="editBorrowTime">
          </div>
          <div class="form-group">
            <label>Student / Teacher Name</label>
            <input type="text" name="student_name" id="editStudentName" required maxlength="150">
          </div>
          <div class="form-group">
            <label>Class / Department</label>
            <input type="text" name="student_class" id="editStudentClass" maxlength="80">
          </div>
          <div class="form-group">
            <label>Book Title</label>
            <input type="text" name="book_title" id="editBookTitle" required maxlength="200">
          </div>
          <div class="form-group">
            <label>Subject</label>
            <input type="text" name="subject" id="editSubject" maxlength="100">
          </div>
          <div class="form-group">
            <label>Publisher</label>
            <input type="text" name="publisher" id="editPublisher" maxlength="150">
          </div>
          <div class="form-group">
            <label>Due Date</label>
            <input type="date" name="due_date" id="editDueDate" required>
          </div>
          <div class="form-group full">
            <label>Notes</label>
            <textarea name="notes" id="editNotes" rows="2"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Record</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     VIEW DETAILS MODAL
══════════════════════════════════════════════════════════════ -->
<div class="modal" id="viewModal" onclick="if(event.target===this)closeModal('viewModal')">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-eye"></i> Borrowing Details</h2>
      <button class="modal-close" onclick="closeModal('viewModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="viewModalBody">
      <div style="text-align:center;padding:30px;color:#9aaa9b"><i class="fas fa-spinner fa-spin" style="font-size:2rem"></i></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('viewModal')">Close</button>
    </div>
  </div>
</div>

<!-- ── Hidden action forms ────────────────────────────────── -->
<form method="POST" action="lend_books.php" id="returnForm" style="display:none">
  <input type="hidden" name="return_book"  value="1">
  <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="borrow_id"    id="returnBorrowId">
</form>
<form method="POST" action="lend_books.php" id="deleteForm" style="display:none">
  <input type="hidden" name="delete_borrowing" value="1">
  <input type="hidden" name="csrf_token"       value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="borrow_id"        id="deleteBorrowId">
</form>

<!-- ── Confirm dialog ─────────────────────────────────────── -->
<div class="dialog" id="confirmDlg">
  <div class="dialog-box">
    <div class="dialog-head" id="dlgHead"><i id="dlgIcon" class="fas fa-question"></i><h3 id="dlgTitle">Confirm</h3></div>
    <div class="dialog-body"  id="dlgMsg"></div>
    <div class="dialog-actions">
      <button class="btn btn-outline" onclick="closeDlg()">Cancel</button>
      <button class="btn" id="dlgConfirmBtn" onclick="runDlgCb()">Confirm</button>
    </div>
  </div>
</div>

<script>
/* ──────────────────────────────────────────────────────────
   Constants & state
────────────────────────────────────────────────────────── */
const CSRF  = <?= $js_csrf ?>;
const NOTIF = <?= $js_notification ?>;

let allBorrowings = [], filteredBorrowings = [], currentPage = 1;
const PER_PAGE = 20;

/* ──────────────────────────────────────────────────────────
   Utilities
────────────────────────────────────────────────────────── */
function esc(v){
  return String(v||'')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtDate(d){
  if(!d||d==='—') return '—';
  try{ return new Date(d).toLocaleDateString('en-UG',{day:'2-digit',month:'short',year:'numeric'}); }
  catch(_){ return d; }
}
function notify(title, msg, type='success', dur=5000){
  const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
  const n = document.createElement('div');
  n.className = `notif ${type}`;
  n.innerHTML = `<i class="fas ${icons[type]||icons.info} notif-icon"></i>
    <div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div>
    <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
  document.getElementById('notif-stack').prepend(n);
  setTimeout(()=>{ n.style.cssText='opacity:0;transform:translateX(30px);transition:.3s';
    setTimeout(()=>n.remove(),320); }, dur);
}

// Session notification
if(NOTIF) notify(NOTIF.type==='success'?'Success':'Error', NOTIF.message, NOTIF.type);

/* ──────────────────────────────────────────────────────────
   Skeleton loader
────────────────────────────────────────────────────────── */
function showSkeleton(){
  const widths = [
    [62,130,58,162,82],[74,118,70,145,90],[68,140,60,170,75],
    [62,125,65,150,88],[74,145,55,162,80],[68,120,72,142,85],[62,135,62,155,78]
  ];
  document.getElementById('tableBody').innerHTML = widths.map(w=>`
    <tr class="sk-row">
      <td><span class="sk-cell sk-badge" style="width:${w[0]}px"></span></td>
      <td><span class="sk-cell sk-text-lg" style="width:${w[1]}px"></span></td>
      <td><span class="sk-cell sk-text"    style="width:${w[2]}px"></span></td>
      <td><span class="sk-cell sk-text-lg" style="width:${w[3]}px"></span></td>
      <td><span class="sk-cell sk-text"    style="width:${w[4]}px"></span></td>
      <td><span class="sk-cell sk-text"    style="width:72px"></span></td>
      <td><span class="sk-cell sk-text"    style="width:72px"></span></td>
      <td><span class="sk-cell sk-text"    style="width:72px"></span></td>
      <td><div class="sk-actions">
        <span class="sk-cell sk-btn"></span>
        <span class="sk-cell sk-btn"></span>
        <span class="sk-cell sk-btn"></span>
      </div></td>
    </tr>`).join('');
}

/* ──────────────────────────────────────────────────────────
   Load & render borrowings
────────────────────────────────────────────────────────── */
async function loadBorrowings(){
  showSkeleton();
  try {
    const r = await fetch('lend_books.php?ajax=get_borrowings');
    if(!r.ok) throw new Error('Server error ' + r.status);
    allBorrowings = await r.json();
    applyFilter();
  } catch(e) {
    document.getElementById('tableBody').innerHTML = `<tr><td colspan="9">
      <div class="empty-state"><i class="fas fa-exclamation-circle"></i>
      <p>Failed to load records. Please refresh the page.</p></div></td></tr>`;
    notify('Error','Could not load borrowing data.','error');
  }
}

function applyFilter(){
  const q  = (document.getElementById('searchInput').value||'').toLowerCase().trim();
  const sf = document.getElementById('statusFilter').value;
  filteredBorrowings = allBorrowings.filter(b => {
    const matchSt = !sf || b.display_status === sf;
    const text    = `${b.student_name||''} ${b.student_class||''} ${b.book_title||''} ${b.subject||''} ${b.publisher||''}`.toLowerCase();
    return matchSt && (!q || text.includes(q));
  });
  currentPage = 1;
  renderPage();
}

function renderPage(){
  const total  = filteredBorrowings.length;
  const pages  = Math.max(1, Math.ceil(total / PER_PAGE));
  currentPage  = Math.min(currentPage, pages);
  const start  = (currentPage - 1) * PER_PAGE;
  const slice  = filteredBorrowings.slice(start, start + PER_PAGE);

  document.getElementById('resultCount').textContent =
    total === allBorrowings.length ? `${total} records` : `${total} of ${allBorrowings.length} records`;
  document.getElementById('paginationInfo').textContent =
    `Showing ${Math.min(start+1,total)}–${Math.min(start+PER_PAGE,total)} of ${total}`;

  if(!slice.length){
    document.getElementById('tableBody').innerHTML = `<tr><td colspan="9">
      <div class="empty-state"><i class="fas fa-book-open"></i>
      <p>No borrowing records found.</p></div></td></tr>`;
  } else {
    document.getElementById('tableBody').innerHTML = slice.map(b => {
      const overdue = b.is_overdue;
      const id      = parseInt(b.id);
      const title   = esc(b.book_title || '—');
      const badge   = overdue
        ? `<span class="badge badge-red"><i class="fas fa-exclamation-triangle"></i> Overdue</span>`
        : b.status==='borrowed'
          ? `<span class="badge badge-amber">On Loan</span>`
          : `<span class="badge badge-green"><i class="fas fa-check"></i> Returned</span>`;
      const returnBtn = b.status==='borrowed'
        ? `<button class="btn-icon bi-return" title="Mark as returned"
             data-id="${id}" data-title="${title}"
             onclick="confirmReturn(this.dataset.id,this.dataset.title)">
             <i class="fas fa-undo"></i></button>` : '';
      return `<tr>
        <td>${badge}</td>
        <td style="font-weight:600">${esc(b.student_name||'—')}</td>
        <td>${esc(b.student_class||'—')}</td>
        <td>${esc(b.book_title||'—')}</td>
        <td>${esc(b.subject||'—')}</td>
        <td>${fmtDate(b.borrow_date)}</td>
        <td style="${overdue?'color:var(--red);font-weight:600':''}">${fmtDate(b.due_date)}</td>
        <td>${b.return_date?fmtDate(b.return_date):'—'}</td>
        <td>
          <div class="action-cell">
            <button class="btn-icon bi-view" title="View details" onclick="viewBorrowing(${id})"><i class="fas fa-eye"></i></button>
            <button class="btn-icon bi-edit" title="Edit record"  onclick="editBorrowing(${id})"><i class="fas fa-edit"></i></button>
            ${returnBtn}
            <button class="btn-icon bi-delete" title="Delete record"
              data-id="${id}" data-title="${title}"
              onclick="confirmDelete(this.dataset.id,this.dataset.title)">
              <i class="fas fa-trash"></i></button>
          </div>
        </td>
      </tr>`;
    }).join('');
  }

  // Pagination buttons
  const btns = document.getElementById('paginationBtns');
  btns.innerHTML = '';
  const range = [];
  if(pages<=7){ for(let i=1;i<=pages;i++) range.push(i); }
  else {
    range.push(1);
    if(currentPage>3) range.push('…');
    for(let i=Math.max(2,currentPage-1);i<=Math.min(pages-1,currentPage+1);i++) range.push(i);
    if(currentPage<pages-2) range.push('…');
    range.push(pages);
  }
  range.forEach(p=>{
    if(p==='…'){
      const s=document.createElement('span');s.style.padding='0 4px';s.textContent='…';btns.appendChild(s);return;
    }
    const b=document.createElement('button');
    b.className='page-btn'+(p===currentPage?' active':'');
    b.textContent=p; b.disabled=(p===currentPage);
    b.onclick=()=>{ currentPage=p; renderPage(); };
    btns.appendChild(b);
  });
}

/* ──────────────────────────────────────────────────────────
   Add modal — open & reset
────────────────────────────────────────────────────────── */
function openAddModal(){
  // Reset borrower type
  ['addCardStudent','addCardStaff'].forEach(id=>document.getElementById(id).classList.remove('active'));
  document.getElementById('addPersonGroup').style.display = 'none';
  document.getElementById('addClassGroup').style.display  = 'none';
  document.getElementById('addHiddenName').value  = '';
  document.getElementById('addHiddenClass').value = '';
  document.getElementById('addPersonSearch').value = '';
  document.getElementById('addClassDisplay').value = '';
  document.getElementById('addPersonCard').classList.remove('visible');
  closePersonAcl('add');

  // Reset dates
  const today = new Date().toISOString().split('T')[0];
  document.getElementById('addBorrowDate').value = today;
  document.getElementById('addBorrowTime').value = new Date().toTimeString().slice(0,5);
  const due = new Date(); due.setDate(due.getDate()+14);
  document.getElementById('addDueDate').value = due.toISOString().split('T')[0];

  // Reset book fields
  document.getElementById('bookSearch').value    = '';
  document.getElementById('addBookId').value     = '';
  document.getElementById('addBookTitle').value  = '';
  document.getElementById('addSubject').value    = '';
  document.getElementById('addPublisher').value  = '';
  document.getElementById('addNotes').value      = '';
  document.getElementById('bookAutocomplete').classList.remove('open');

  openModal('addModal');
}

/* ──────────────────────────────────────────────────────────
   Borrower type selection
────────────────────────────────────────────────────────── */
function selectBorrowerType(prefix, type){
  // Update card styles
  document.getElementById(prefix + 'CardStudent').classList.toggle('active', type==='student');
  document.getElementById(prefix + 'CardStaff').classList.toggle('active', type==='staff');

  // Reset person selection
  clearPersonSelection(prefix);

  // Show/hide person search
  const personGroup = document.getElementById(prefix + 'PersonGroup');
  const classGroup  = document.getElementById(prefix + 'ClassGroup');
  personGroup.style.display = '';
  classGroup.style.display  = 'none'; // shown only after person chosen

  // Update label
  document.getElementById(prefix + 'PersonLabel').textContent =
    type==='student' ? 'Student Name *' : 'Teacher / Staff Name *';
  document.getElementById(prefix + 'ClassLabel').textContent =
    type==='student' ? 'Class' : 'Department';

  // Store type on element for search function
  personGroup.dataset.borrowerType = type;

  // Focus search after animation frame
  requestAnimationFrame(()=>{ document.getElementById(prefix+'PersonSearch').focus(); });
}

/* ──────────────────────────────────────────────────────────
   Person search autocomplete
────────────────────────────────────────────────────────── */
const _personTimers = {};

function debouncedPersonSearch(prefix, q){
  clearTimeout(_personTimers[prefix]);
  const acl = document.getElementById(prefix + 'PersonAcl');
  if(q.length < 2){ closePersonAcl(prefix); return; }
  acl.innerHTML = '<div class="person-acl-state"><i class="fas fa-spinner fa-spin"></i> Searching…</div>';
  acl.classList.add('open');
  _personTimers[prefix] = setTimeout(()=>_execPersonSearch(prefix, q), 300);
}

async function _execPersonSearch(prefix, q){
  const personGroup = document.getElementById(prefix + 'PersonGroup');
  const type = personGroup ? personGroup.dataset.borrowerType : '';
  if(!type) return;
  const endpoint = type==='student' ? 'search_students' : 'search_staff';
  const acl = document.getElementById(prefix + 'PersonAcl');
  try {
    const r = await fetch(`lend_books.php?ajax=${endpoint}&q=${encodeURIComponent(q)}`);
    const people = await r.json();
    acl.innerHTML = '';
    if(!people.length){
      acl.innerHTML = '<div class="person-acl-state"><i class="fas fa-user-slash"></i> No results found.</div>';
      acl.classList.add('open');
      return;
    }
    people.forEach(p=>{
      const item = document.createElement('div');
      item.className = 'person-acl-item';
      const isStudent = type === 'student';
      const name = `${p.first_name} ${p.last_name}`.trim();
      const classOrDept = isStudent
        ? [p.current_class, p.stream].filter(Boolean).join(' ')
        : (p.department || '');
      const idLabel = isStudent
        ? `ID: ${p.student_id}`
        : (p.staff_id ? `ID: ${p.staff_id}` : '');
      const meta = isStudent
        ? [classOrDept, p.section, idLabel].filter(Boolean).join(' · ')
        : [p.designation, p.department, idLabel].filter(Boolean).join(' · ');
      item.innerHTML = `<div class="person-acl-name">${esc(name)}</div>
                        <div class="person-acl-meta">${esc(meta)}</div>`;
      item.addEventListener('click', ()=>{
        _applyPersonSelection(prefix, name, classOrDept, meta, type);
      });
      acl.appendChild(item);
    });
    acl.classList.add('open');
  } catch(e){
    acl.innerHTML = '<div class="person-acl-state" style="color:var(--red)"><i class="fas fa-exclamation-circle"></i> Search failed.</div>';
  }
}

function _applyPersonSelection(prefix, name, classOrDept, meta, type){
  // Populate hidden fields
  document.getElementById(prefix + 'HiddenName').value  = name;
  document.getElementById(prefix + 'HiddenClass').value = classOrDept;

  // Show selected card
  document.getElementById(prefix + 'PersonSearch').value = '';
  const card = document.getElementById(prefix + 'PersonCard');
  document.getElementById(prefix + 'PersonCardName').textContent = name;
  document.getElementById(prefix + 'PersonCardMeta').textContent = meta;
  card.classList.add('visible');
  closePersonAcl(prefix);

  // Show & fill class/dept field
  document.getElementById(prefix + 'ClassDisplay').value = classOrDept;
  document.getElementById(prefix + 'ClassLabel').textContent = type==='student' ? 'Class' : 'Department';
  document.getElementById(prefix + 'ClassGroup').style.display = classOrDept ? '' : 'none';
}

function clearPersonSelection(prefix){
  document.getElementById(prefix + 'HiddenName').value  = '';
  document.getElementById(prefix + 'HiddenClass').value = '';
  document.getElementById(prefix + 'ClassDisplay').value = '';
  document.getElementById(prefix + 'PersonSearch').value = '';
  document.getElementById(prefix + 'PersonCard').classList.remove('visible');
  document.getElementById(prefix + 'ClassGroup').style.display = 'none';
  closePersonAcl(prefix);
}

function closePersonAcl(prefix){
  const acl = document.getElementById(prefix + 'PersonAcl');
  if(acl){ acl.innerHTML=''; acl.classList.remove('open'); }
}

/* ──────────────────────────────────────────────────────────
   Add form validation
────────────────────────────────────────────────────────── */
function validateAddForm(){
  const personGroup = document.getElementById('addPersonGroup');
  const type  = personGroup ? personGroup.dataset.borrowerType : '';
  const name  = document.getElementById('addHiddenName').value.trim();
  const borrow = document.getElementById('addBorrowDate').value;
  const due   = document.getElementById('addDueDate').value;

  if(!type){
    notify('Validation','Please choose Student or Teacher / Staff.','error');
    return false;
  }
  if(!name){
    const label = type==='student' ? 'a student' : 'a staff member';
    notify('Validation',`Please search and select ${label} before saving.`,'error');
    return false;
  }
  if(borrow && due && due < borrow){
    notify('Validation','Due date must be on or after the borrow date.','error');
    return false;
  }
  return true;
}

/* ──────────────────────────────────────────────────────────
   Book catalog autocomplete
────────────────────────────────────────────────────────── */
let _acTimer = null;
function searchBooks(q){
  clearTimeout(_acTimer);
  const list = document.getElementById('bookAutocomplete');
  if(q.length < 2){ list.classList.remove('open'); return; }
  _acTimer = setTimeout(()=>{
    fetch(`lend_books.php?ajax=search_books&q=${encodeURIComponent(q)}`)
      .then(r=>r.json())
      .then(books=>{
        list.innerHTML = '';
        if(!books.length){
          list.innerHTML='<div class="acl-item" style="color:#9aaa9b">No books found in catalog.</div>';
          list.classList.add('open'); return;
        }
        books.forEach(b=>{
          const item = document.createElement('div');
          item.className = 'acl-item';
          item.innerHTML = `<div class="acl-title">${esc(b.title)}</div>
            <div class="acl-meta">${esc(b.subject)} · ${esc(b.publisher||'Unknown')} · ${b.copies} cop${b.copies==1?'y':'ies'}</div>`;
          item.onclick = ()=>{
            document.getElementById('addBookTitle').value = b.title;
            document.getElementById('addSubject').value   = b.subject || '';
            document.getElementById('addPublisher').value = b.publisher || '';
            document.getElementById('addBookId').value    = b.book_id;
            document.getElementById('bookSearch').value   = b.title;
            list.classList.remove('open');
          };
          list.appendChild(item);
        });
        list.classList.add('open');
      }).catch(()=>list.classList.remove('open'));
  }, 300);
}

/* ──────────────────────────────────────────────────────────
   View & edit modals
────────────────────────────────────────────────────────── */
function viewBorrowing(id){
  document.getElementById('viewModalBody').innerHTML =
    '<div style="text-align:center;padding:30px;color:#9aaa9b"><i class="fas fa-spinner fa-spin" style="font-size:2rem"></i></div>';
  openModal('viewModal');
  fetch(`lend_books.php?ajax=borrowing_details&id=${id}`)
    .then(r=>r.json())
    .then(b=>{
      if(b.error){ document.getElementById('viewModalBody').innerHTML=`<p style="color:var(--red);padding:20px">${esc(b.error)}</p>`; return; }
      const overdue = b.is_overdue;
      document.getElementById('viewModalBody').innerHTML = `
        <div class="detail-grid">
          <div class="detail-item"><div class="detail-label">Student / Teacher</div><div class="detail-value">${esc(b.student_name||'—')}</div></div>
          <div class="detail-item"><div class="detail-label">Class / Department</div><div class="detail-value">${esc(b.student_class||'—')}</div></div>
          <div class="detail-item"><div class="detail-label">Book Title</div><div class="detail-value">${esc(b.book_title||'—')}</div></div>
          <div class="detail-item"><div class="detail-label">Subject</div><div class="detail-value">${esc(b.subject||'—')}</div></div>
          <div class="detail-item"><div class="detail-label">Publisher</div><div class="detail-value">${esc(b.publisher||'—')}</div></div>
          <div class="detail-item"><div class="detail-label">Borrow Date</div><div class="detail-value">${fmtDate(b.borrow_date)}</div></div>
          <div class="detail-item"><div class="detail-label">Due Date</div>
            <div class="detail-value" style="${overdue?'color:var(--red);font-weight:700':''}">
              ${fmtDate(b.due_date)}${overdue?' <span class="badge badge-red" style="margin-left:6px">OVERDUE</span>':''}
            </div>
          </div>
          <div class="detail-item"><div class="detail-label">Days on Loan</div><div class="detail-value">${b.days_borrowed} day${b.days_borrowed!==1?'s':''}</div></div>
          <div class="detail-item"><div class="detail-label">Status</div>
            <div class="detail-value">
              ${overdue?'<span class="badge badge-red">Overdue</span>':b.status==='borrowed'?'<span class="badge badge-amber">On Loan</span>':'<span class="badge badge-green">Returned</span>'}
            </div>
          </div>
          <div class="detail-item"><div class="detail-label">Return Date</div>
            <div class="detail-value">${b.return_date?fmtDate(b.return_date):'<span style="color:#9aaa9b">Not yet returned</span>'}</div>
          </div>
          ${b.notes?`<div class="detail-item full"><div class="detail-label">Notes</div><div class="detail-value">${esc(b.notes)}</div></div>`:''}
        </div>`;
    })
    .catch(()=>{ document.getElementById('viewModalBody').innerHTML='<p style="color:var(--red);padding:20px">Failed to load details.</p>'; });
}

function editBorrowing(id){
  fetch(`lend_books.php?ajax=edit_data&id=${id}`)
    .then(r=>r.json())
    .then(b=>{
      if(b.error){ notify('Error',b.error,'error'); return; }
      document.getElementById('editBorrowId').value    = b.id;
      document.getElementById('editBorrowDate').value  = b.borrow_date  || '';
      document.getElementById('editBorrowTime').value  = b.borrow_time  ? b.borrow_time.slice(0,5) : '';
      document.getElementById('editStudentName').value = b.student_name  || '';
      document.getElementById('editStudentClass').value= b.student_class || '';
      document.getElementById('editBookTitle').value   = b.book_title    || '';
      document.getElementById('editSubject').value     = b.subject       || '';
      document.getElementById('editPublisher').value   = b.publisher     || '';
      document.getElementById('editDueDate').value     = b.due_date      || '';
      document.getElementById('editNotes').value       = b.notes         || '';
      openModal('editModal');
    })
    .catch(()=>notify('Error','Could not load record data.','error'));
}

/* ──────────────────────────────────────────────────────────
   Confirm dialog
────────────────────────────────────────────────────────── */
let _dlgCb = null;

function showDlg(type, icon, title, msg, btnClass, btnLabel, cb){
  document.getElementById('dlgHead').className   = `dialog-head ${type}`;
  document.getElementById('dlgIcon').className   = icon;
  document.getElementById('dlgTitle').textContent = title;
  document.getElementById('dlgMsg').innerHTML    = msg;
  const btn = document.getElementById('dlgConfirmBtn');
  btn.textContent = btnLabel;
  btn.className   = `btn ${btnClass}`;
  _dlgCb = cb;
  document.getElementById('confirmDlg').classList.add('active');
}
function closeDlg(){ document.getElementById('confirmDlg').classList.remove('active'); _dlgCb = null; }
function runDlgCb(){ if(_dlgCb) _dlgCb(); closeDlg(); }

function confirmReturn(id, title){
  showDlg('warning','fas fa-undo','Mark as Returned',
    `Mark <strong>${esc(title)}</strong> as returned today?`,
    'btn-amber','Mark Returned',
    ()=>{ document.getElementById('returnBorrowId').value = id; document.getElementById('returnForm').submit(); }
  );
}
function confirmDelete(id, title){
  showDlg('danger','fas fa-trash','Delete Record',
    `Permanently delete the borrowing record for <strong>${esc(title)}</strong>?<br><small style="color:#ccc">This cannot be undone.</small>`,
    'btn-danger','Delete',
    ()=>{ document.getElementById('deleteBorrowId').value = id; document.getElementById('deleteForm').submit(); }
  );
}

/* ──────────────────────────────────────────────────────────
   Modal helpers
────────────────────────────────────────────────────────── */
function openModal(id){ document.getElementById(id).classList.add('active'); }
function closeModal(id){ document.getElementById(id).classList.remove('active'); }
document.addEventListener('keydown', e=>{
  if(e.key==='Escape'){
    ['addModal','editModal','viewModal'].forEach(closeModal);
    closeDlg();
  }
});

/* ──────────────────────────────────────────────────────────
   Click-outside: close dropdowns
────────────────────────────────────────────────────────── */
document.addEventListener('click', e=>{
  if(!e.target.closest('.person-search-wrap'))
    document.querySelectorAll('.person-acl').forEach(el=>{ el.innerHTML=''; el.classList.remove('open'); });
  if(!e.target.closest('.autocomplete-wrap'))
    document.getElementById('bookAutocomplete').classList.remove('open');
});

/* ──────────────────────────────────────────────────────────
   Export PDF
────────────────────────────────────────────────────────── */
function exportToPDF(){
  if(!filteredBorrowings.length){ notify('Empty','No records to export.','warning'); return; }
  const {jsPDF} = window.jspdf;
  const doc = new jsPDF('landscape');
  doc.setFontSize(16); doc.setTextColor(46,125,50);
  doc.text('Library Book Borrowings Report', 14, 18);
  doc.setFontSize(9); doc.setTextColor(120);
  doc.text('Generated: ' + new Date().toLocaleDateString('en-UG'), 14, 26);
  doc.autoTable({
    head:[['Status','Student/Teacher','Class','Book Title','Subject','Borrowed','Due Date','Returned']],
    body: filteredBorrowings.map(b=>[
      b.display_status==='overdue'?'Overdue':b.status==='borrowed'?'On Loan':'Returned',
      b.student_name||'', b.student_class||'', b.book_title||'', b.subject||'',
      b.borrow_date?new Date(b.borrow_date).toLocaleDateString('en-UG'):'',
      b.due_date?new Date(b.due_date).toLocaleDateString('en-UG'):'',
      b.return_date?new Date(b.return_date).toLocaleDateString('en-UG'):''
    ]),
    startY:32, theme:'grid',
    headStyles:{fillColor:[56,142,60],fontSize:8},
    bodyStyles:{fontSize:7.5}
  });
  doc.save('library-borrowings-'+new Date().toISOString().split('T')[0]+'.pdf');
  notify('Exported','PDF downloaded.','success');
}

/* ──────────────────────────────────────────────────────────
   Export Excel
────────────────────────────────────────────────────────── */
function exportToExcel(){
  if(!filteredBorrowings.length){ notify('Empty','No records to export.','warning'); return; }
  const data=[['Status','Student/Teacher','Class','Book Title','Subject','Borrowed','Due Date','Returned']];
  filteredBorrowings.forEach(b=>data.push([
    b.display_status==='overdue'?'Overdue':b.status==='borrowed'?'On Loan':'Returned',
    b.student_name||'', b.student_class||'', b.book_title||'', b.subject||'',
    b.borrow_date||'', b.due_date||'', b.return_date||''
  ]));
  const wb = XLSX.utils.book_new();
  const ws = XLSX.utils.aoa_to_sheet(data);
  ws['!cols'] = [{wch:12},{wch:24},{wch:14},{wch:32},{wch:18},{wch:13},{wch:13},{wch:13}];
  XLSX.utils.book_append_sheet(wb, ws, 'Borrowings');
  XLSX.writeFile(wb, 'library-borrowings-'+new Date().toISOString().split('T')[0]+'.xlsx');
  notify('Exported','Excel file downloaded.','success');
}

/* ──────────────────────────────────────────────────────────
   Init
────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', loadBorrowings);
</script>
</body>
</html>