<?php
require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';

$allowedRoles = ['super user', 'school leader', 'developer', 'bursar'];
if (!in_array($_SESSION['role'], $allowedRoles)) { header("Location: dashboard.php"); exit(); }
$tracker->trackAction("Record Student Requirements");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache"); header("Expires: 0");

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // Search-as-you-type — never loads all 2000 students at once
    if ($action === 'search_students') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { echo json_encode(['success' => true, 'data' => []]); exit(); }
        // bind_param handles all escaping — do NOT call real_escape_string here (double-escaping)
        $like = '%' . $q . '%';
        $stmt = $conn->prepare(
            "SELECT student_id, first_name, last_name, current_class, stream, section
             FROM students
             WHERE status = 'active'
               AND (student_id LIKE ? OR first_name LIKE ? OR last_name LIKE ?
                    OR CONCAT(first_name,' ',last_name) LIKE ?)
             ORDER BY first_name, last_name
             LIMIT 20"
        );
        $stmt->bind_param("ssss", $like, $like, $like, $like);
        $stmt->execute();
        echo json_encode(['success' => true, 'data' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
        exit();
    }

    if ($action === 'get_student_info') {
        $id   = trim($_GET['student_id'] ?? '');
        if (!$id) { echo json_encode(['success' => false, 'message' => 'Missing student ID']); exit(); }
        $stmt = $conn->prepare(
            "SELECT student_id, first_name, last_name, current_class, stream, section
             FROM students WHERE student_id = ?"
        );
        $stmt->bind_param("s", $id); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        echo json_encode($row
            ? ['success' => true,  'data'    => $row]
            : ['success' => false, 'message' => 'Student not found']);
        exit();
    }

    // Section-aware requirement fetch — the core fix
    if ($action === 'get_requirements') {
        $studentId = trim($_GET['student_id'] ?? '');
        $term      = trim($_GET['term']       ?? '');
        $year      = trim($_GET['year']       ?? '');

        if (!$studentId || !$term || !$year) {
            echo json_encode(['success' => false, 'message' => 'Missing parameters']); exit();
        }
        if (!preg_match('/^\d{4}$/', $year)) {
            echo json_encode(['success' => false, 'message' => 'Invalid year format']); exit();
        }

        $info = $conn->prepare(
            "SELECT current_class, stream, section FROM students WHERE student_id = ?"
        );
        $info->bind_param("s", $studentId); $info->execute();
        $stu = $info->get_result()->fetch_assoc();

        if (!$stu) { echo json_encode(['success' => false, 'message' => 'Student not found']); exit(); }

        // A requirement applies to this student when:
        // 1. section_type matches 'All' OR matches student's section
        // 2. class is NULL (all classes) OR matches student's class
        // 3. stream is NULL (all streams) OR matches student's stream
        $stmt = $conn->prepare(
            "SELECT r.*,
                COALESCE(sr.items_brought, 0)   AS items_brought,
                COALESCE(sr.cash_paid,     0)   AS cash_paid,
                COALESCE(sr.total_value,   0)   AS total_value,
                COALESCE(sr.balance,       0)   AS balance,
                COALESCE(sr.status, 'pending')  AS student_status,
                sr.record_id,
                sr.waived_reason
             FROM school_requirements r
             LEFT JOIN student_requirements sr
                 ON r.requirement_id = sr.requirement_id
                 AND sr.student_id = ?
             WHERE r.status = 'active'
               AND r.term = ?
               AND r.academic_year = ?
               AND (r.class IS NULL OR (r.class = ? AND (r.stream IS NULL OR r.stream = ?)))
               AND (r.section_type = 'All' OR r.section_type = ?)
             ORDER BY
                FIELD(sr.status,'pending','partial','completed','waived'), r.requirement_name"
        );
        $stmt->bind_param(
            "ssssss",
            $studentId, $term, $year,
            $stu['current_class'], $stu['stream'], $stu['section']
        );
        $stmt->execute();
        echo json_encode([
            'success'         => true,
            'data'            => $stmt->get_result()->fetch_all(MYSQLI_ASSOC),
            'student_section' => $stu['section'],
        ]);
        exit();
    }

    if ($action === 'get_payment_history') {
        $recordId = intval($_GET['record_id'] ?? 0);
        if ($recordId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid record ID']); exit(); }
        $stmt = $conn->prepare(
            "SELECT rp.*, u.user_name
             FROM requirement_payments rp
             LEFT JOIN users u ON rp.recorded_by = u.user_id
             WHERE rp.record_id = ?
             ORDER BY rp.payment_date DESC, rp.recorded_at DESC"
        );
        $stmt->bind_param("i", $recordId); $stmt->execute();
        echo json_encode(['success' => true, 'data' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit();
}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Refresh and try again.']);
        exit();
    }

    $action = $_POST['action'] ?? '';

    // ── Record payment ──────────────────────────────────────────────────────
    if ($action === 'record_payment') {
        $studentId     = trim($_POST['student_id']      ?? '');
        $requirementId = intval($_POST['requirement_id'] ?? 0);
        $paymentType   = $_POST['payment_type']           ?? '';
        $quantity      = intval($_POST['quantity']        ?? 0);
        $amount        = floatval($_POST['amount']        ?? 0);
        $paymentDateRaw = $_POST['payment_date'] ?? date('Y-m-d');
        // Validate it is a real calendar date — reject malformed input
        $parsedDate  = DateTime::createFromFormat('Y-m-d', $paymentDateRaw);
        $paymentDate = ($parsedDate && $parsedDate->format('Y-m-d') === $paymentDateRaw)
            ? $paymentDateRaw
            : date('Y-m-d'); // fall back to today if invalid
        $notes         = trim($_POST['notes']             ?? '');

        if (!$studentId || $requirementId <= 0 || !in_array($paymentType, ['item', 'cash'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid input data']); exit();
        }
        if ($paymentType === 'item' && $quantity <= 0) {
            echo json_encode(['success' => false, 'message' => 'Quantity must be greater than 0']); exit();
        }
        if ($paymentType === 'cash' && $amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);  exit();
        }

        $conn->begin_transaction();
        try {
            // Lock the requirement row so concurrent submissions can't race
            $reqStmt = $conn->prepare(
                "SELECT quantity_per_student, cash_equivalent
                 FROM school_requirements
                 WHERE requirement_id = ? AND status = 'active'
                 FOR UPDATE"
            );
            $reqStmt->bind_param("i", $requirementId); $reqStmt->execute();
            $reqData = $reqStmt->get_result()->fetch_assoc();
            if (!$reqData) throw new Exception('Requirement not found or inactive');

            $qtyRequired  = (int)$reqData['quantity_per_student'];
            $cashEquiv    = (float)$reqData['cash_equivalent'];
            $totalRequired = $qtyRequired * $cashEquiv;

            // Get existing record (with lock)
            $chkStmt = $conn->prepare(
                "SELECT record_id, items_brought, cash_paid
                 FROM student_requirements
                 WHERE student_id = ? AND requirement_id = ?
                 FOR UPDATE"
            );
            $chkStmt->bind_param("si", $studentId, $requirementId); $chkStmt->execute();
            $existing = $chkStmt->get_result()->fetch_assoc();

            if ($existing) {
                $newItems = (int)$existing['items_brought']   + ($paymentType === 'item' ? $quantity : 0);
                $newCash  = (float)$existing['cash_paid']     + ($paymentType === 'cash' ? $amount   : 0.0);
            } else {
                $newItems = $paymentType === 'item' ? $quantity : 0;
                $newCash  = $paymentType === 'cash' ? $amount   : 0.0;
            }

            $itemsValue = $newItems * $cashEquiv;
            $totalValue = $itemsValue + $newCash;
            $balance    = max(0.0, $totalRequired - $totalValue);

            // Derive status
            if ($totalValue <= 0) {
                $newStatus = 'pending';
            } elseif ($balance <= 0) {
                $newStatus = 'completed';
            } else {
                $newStatus = 'partial';
            }

            $recordId = 0;
            if ($existing) {
                $recordId = (int)$existing['record_id'];
                $upd = $conn->prepare(
                    "UPDATE student_requirements
                     SET items_brought=?, cash_paid=?, total_value=?, balance=?, status=?, updated_at=NOW()
                     WHERE record_id=?"
                );
                $upd->bind_param("idddsi", $newItems, $newCash, $totalValue, $balance, $newStatus, $recordId);
                $upd->execute();
            } else {
                $ins = $conn->prepare(
                    "INSERT INTO student_requirements
                     (student_id, requirement_id, items_brought, cash_paid, total_value, balance, status, recorded_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $ins->bind_param("siidddsi", $studentId, $requirementId, $newItems, $newCash, $totalValue, $balance, $newStatus, $_SESSION['user_id']);
                $ins->execute();
                $recordId = $conn->insert_id;
            }

            // Payment log entry
            $logQty = $paymentType === 'item' ? $quantity : 0;
            $logAmt = $paymentType === 'cash' ? $amount   : 0.0;
            $log = $conn->prepare(
                "INSERT INTO requirement_payments
                 (record_id, student_id, requirement_id, payment_type, quantity, amount, payment_date, notes, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            // record_id(i) student_id(s) requirement_id(i) payment_type(s) quantity(i) amount(d) payment_date(s) notes(s) recorded_by(i)
            $log->bind_param("isisidssi", $recordId, $studentId, $requirementId, $paymentType, $logQty, $logAmt, $paymentDate, $notes, $_SESSION['user_id']);
            $log->execute();

            $conn->commit();
            echo json_encode([
                'success'    => true,
                'message'    => 'Payment recorded successfully',
                'new_status' => $newStatus,
                'balance'    => $balance,
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit();
    }

    // ── Waive requirement ───────────────────────────────────────────────────
    if ($action === 'waive_requirement') {
        $studentId     = trim($_POST['student_id']      ?? '');
        $requirementId = intval($_POST['requirement_id'] ?? 0);
        $reason        = trim($_POST['reason']           ?? '');

        if (!$studentId || $requirementId <= 0 || !$reason) {
            echo json_encode(['success' => false, 'message' => 'All fields are required']); exit();
        }

        $conn->begin_transaction();
        try {
            $chk = $conn->prepare(
                "SELECT record_id FROM student_requirements WHERE student_id = ? AND requirement_id = ?"
            );
            $chk->bind_param("si", $studentId, $requirementId); $chk->execute();
            $rec = $chk->get_result()->fetch_assoc();

            if ($rec) {
                $upd = $conn->prepare(
                    "UPDATE student_requirements
                     SET status='waived', balance=0, waived_reason=?, updated_at=NOW()
                     WHERE record_id=?"
                );
                $upd->bind_param("si", $reason, $rec['record_id']); $upd->execute();
            } else {
                $ins = $conn->prepare(
                    "INSERT INTO student_requirements
                     (student_id, requirement_id, items_brought, cash_paid, total_value, balance, status, waived_reason, recorded_by)
                     VALUES (?, ?, 0, 0, 0, 0, 'waived', ?, ?)"
                );
                $ins->bind_param("sisi", $studentId, $requirementId, $reason, $_SESSION['user_id']);
                $ins->execute();
            }
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Requirement waived successfully']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit();
}

$currentYear = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Record Requirements &mdash; School Pilot</title>
<link rel="shortcut icon" href="images/schoolcontrol_icon.png" type="image/x-icon">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--orange:#e65100;--blue:#1565c0;--amber:#f57c00;--purple:#6a1b9a;
  --gray:#546e7a;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);--shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --tr:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}
a{color:inherit;text-decoration:none}
.page{max-width:100%;padding:24px 20px 60px;margin-top:48px}

/* ── Page Header ─────────────────────────────────── */
.page-header{
  background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);
  border-radius:var(--radius-lg);padding:28px 32px;
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:20px;margin-bottom:24px;box-shadow:var(--shadow-lg)
}
.page-header h1{color:#fff;font-size:1.55rem;font-weight:700}
.page-header p{color:rgba(255,255,255,.78);font-size:.9rem;margin-top:3px}

/* ── Search Card ─────────────────────────────────── */
.search-card{
  background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);
  padding:24px;margin-bottom:20px
}
.search-card h2{font-size:1rem;font-weight:700;color:#333;margin-bottom:18px;display:flex;align-items:center;gap:9px}
.search-card h2 i{color:var(--g700)}
.search-row{display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap}
.search-wrap{position:relative;flex:1 1 260px}
.search-wrap input{
  width:100%;padding:11px 14px 11px 40px;
  border:1.5px solid #d0dbd1;border-radius:var(--radius);
  font-size:.9rem;font-family:inherit;
  transition:border-color var(--tr),box-shadow var(--tr)
}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.search-wrap .srch-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#aaa;font-size:.9rem;pointer-events:none}
.ac-drop{
  position:absolute;top:calc(100% + 4px);left:0;right:0;
  background:#fff;border:1.5px solid #d0dbd1;border-radius:var(--radius);
  box-shadow:var(--shadow-lg);z-index:500;
  max-height:280px;overflow-y:auto;display:none
}
.ac-drop.open{display:block}
.ac-item{
  padding:10px 14px;cursor:pointer;display:flex;align-items:center;gap:10px;
  border-bottom:1px solid #f5f5f5;transition:background var(--tr)
}
.ac-item:last-child{border-bottom:none}
.ac-item:hover,.ac-item.focused{background:var(--g50)}
.ac-avatar{
  width:34px;height:34px;border-radius:50%;
  background:var(--g100);display:flex;align-items:center;justify-content:center;
  font-size:.82rem;font-weight:700;color:var(--g800);flex-shrink:0
}
.ac-name{font-weight:600;font-size:.875rem;color:#222}
.ac-meta{font-size:.75rem;color:#888;margin-top:1px}
.ac-empty{padding:18px 14px;color:#aaa;font-size:.85rem;text-align:center}

.fgroup{display:flex;flex-direction:column;gap:0}
.fgroup label{font-size:.8rem;font-weight:600;color:#555;margin-bottom:6px}
.filter-sel{
  padding:10px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);
  font-size:.875rem;background:#fff;font-family:inherit;
  transition:border-color var(--tr);cursor:pointer;min-width:120px
}
.filter-sel:focus{outline:none;border-color:var(--g600)}

/* ── Student Banner ──────────────────────────────── */
.student-banner{
  background:linear-gradient(135deg,var(--g900),var(--g700));
  border-radius:var(--radius-lg);padding:20px 26px;
  display:flex;align-items:center;gap:18px;flex-wrap:wrap;
  box-shadow:var(--shadow);margin-bottom:20px
}
.stu-avatar{
  width:58px;height:58px;border-radius:50%;
  background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;
  font-size:1.5rem;font-weight:700;color:#fff;flex-shrink:0;
  border:2px solid rgba(255,255,255,.3)
}
.stu-name{font-size:1.15rem;font-weight:700;color:#fff}
.stu-sub{font-size:.83rem;color:rgba(255,255,255,.78);margin-top:3px}
.stu-tags{display:flex;gap:8px;flex-wrap:wrap;margin-top:9px}
.stu-tag{
  background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);
  border-radius:20px;padding:3px 12px;font-size:.74rem;color:#fff;font-weight:600;
  display:inline-flex;align-items:center;gap:5px
}
.stu-tag.day     {background:rgba(255,193,7,.25);border-color:rgba(255,193,7,.4)}
.stu-tag.boarding{background:rgba(179,136,255,.25);border-color:rgba(179,136,255,.4)}

/* ── Summary Pills ───────────────────────────────── */
.summary-bar{
  display:flex;gap:12px;flex-wrap:wrap;
  padding:18px 22px;border-bottom:1px solid #f0f4f1
}
.sum-pill{
  border-radius:var(--radius);padding:13px 18px;
  flex:1 1 100px;text-align:center;min-width:90px
}
.sum-pill .pn{font-size:1.5rem;font-weight:700;display:block;color:#333;line-height:1}
.sum-pill .pl{font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;color:#666;margin-top:4px}
.sp-all      {background:var(--g50);       border:1px solid #c8e6c9}
.sp-completed{background:#e8f5e9;          border:1px solid #a5d6a7}
.sp-partial  {background:#fff8e1;          border:1px solid #ffe082}
.sp-pending  {background:#ffebee;          border:1px solid #ffcdd2}
.sp-waived   {background:#f3e5f5;          border:1px solid #ce93d8}

/* ── Card ────────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px}
.card-head{
  background:linear-gradient(90deg,var(--g700),var(--g600));
  padding:15px 22px;display:flex;align-items:center;justify-content:space-between
}
.card-head h2{color:#fff;font-size:1rem;font-weight:700;display:flex;align-items:center;gap:9px}
.refresh-btn{
  background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.28);
  color:#fff;border-radius:var(--radius);padding:6px 14px;
  font-size:.8rem;font-weight:600;font-family:inherit;cursor:pointer;
  display:flex;align-items:center;gap:6px;transition:background var(--tr)
}
.refresh-btn:hover{background:rgba(255,255,255,.25)}

/* ── Table ───────────────────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{
  padding:12px 14px;text-align:left;
  font-size:.77rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap
}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--tr)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:12px 14px;font-size:.855rem;vertical-align:middle}
.req-name{font-weight:600;color:var(--g800)}
.req-sub{font-size:.73rem;color:#999;margin-top:2px}

/* ── Progress bar ────────────────────────────────── */
.prog-wrap{min-width:80px}
.prog-bar{height:7px;background:#e8ede9;border-radius:4px;overflow:hidden;margin-top:4px}
.prog-fill{height:100%;border-radius:4px;transition:width .55s ease}

/* ── Badges ──────────────────────────────────────── */
.badge{
  display:inline-block;padding:3px 10px;border-radius:20px;
  font-size:.69rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px
}
.b-completed{background:#e8f5e9;color:#2e7d32}
.b-partial  {background:#fff8e1;color:#e65100}
.b-pending  {background:#ffebee;color:#c62828}
.b-waived   {background:#f3e5f5;color:#6a1b9a}

/* ── Section badge ───────────────────────────────── */
.sbadge{
  display:inline-flex;align-items:center;gap:4px;
  padding:2px 8px;border-radius:20px;font-size:.67rem;font-weight:700;letter-spacing:.3px
}
.sb-all     {background:#e3f2fd;color:#1565c0}
.sb-day     {background:#fff8e1;color:#e65100}
.sb-boarding{background:#f3e5f5;color:#6a1b9a}

/* ── Buttons ─────────────────────────────────────── */
.btn{
  display:inline-flex;align-items:center;gap:7px;
  padding:9px 16px;border:none;border-radius:var(--radius);
  font-size:.85rem;font-weight:600;font-family:inherit;cursor:pointer;
  transition:all var(--tr);white-space:nowrap
}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn:disabled{opacity:.55;cursor:not-allowed;transform:none;box-shadow:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-amber  {background:var(--amber);color:#fff}.btn-amber:hover{background:#ef6c00}

/* ── Icon action buttons ─────────────────────────── */
.ac-cell{display:flex;gap:5px}
.ibtn{
  width:30px;height:30px;border:none;border-radius:6px;cursor:pointer;
  display:inline-flex;align-items:center;justify-content:center;
  font-size:.78rem;transition:all var(--tr);flex-shrink:0
}
.ibtn:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.16)}
.ib-pay  {background:#e8f5e9;color:var(--g700)}.ib-pay:hover  {background:var(--g700);color:#fff}
.ib-waive{background:#fff8e1;color:var(--amber)}.ib-waive:hover{background:var(--amber);color:#fff}
.ib-hist {background:#e3f2fd;color:var(--blue)}.ib-hist:hover {background:var(--blue);color:#fff}

/* ── Empty / Prompt ──────────────────────────────── */
.empty-state{text-align:center;padding:52px 20px;color:#8a9a8b}
.empty-state i{font-size:2.5rem;margin-bottom:12px;display:block;opacity:.4}
.start-prompt{text-align:center;padding:44px 20px;color:#8a9a8b}
.start-prompt i{font-size:3.5rem;margin-bottom:14px;display:block;opacity:.22;color:var(--g700)}
.start-prompt h3{font-size:1rem;font-weight:600;margin-bottom:6px;color:#555}
.start-prompt p{font-size:.85rem}

/* ── Modals ──────────────────────────────────────── */
.modal{
  display:none;position:fixed;inset:0;z-index:1000;
  background:rgba(0,0,0,.45);backdrop-filter:blur(3px)
}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto}
.modal-box{
  background:#fff;border-radius:var(--radius-lg);
  width:100%;max-width:520px;box-shadow:var(--shadow-lg);
  animation:mslide .25s ease;margin:auto
}
@keyframes mslide{from{transform:translateY(-22px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{
  padding:18px 22px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;
  display:flex;align-items:center;justify-content:space-between
}
.mh-green {background:linear-gradient(135deg,var(--g900),var(--g700))}
.mh-amber {background:linear-gradient(135deg,#e65100,var(--amber))}
.mh-blue  {background:linear-gradient(135deg,#0d47a1,var(--blue))}
.modal-head h2{color:#fff;font-size:1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.modal-close{
  background:rgba(255,255,255,.15);border:none;color:#fff;
  width:30px;height:30px;border-radius:50%;font-size:1rem;cursor:pointer;
  display:flex;align-items:center;justify-content:center;transition:background var(--tr)
}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:22px}
.modal-footer{padding:4px 22px 20px;display:flex;gap:10px;justify-content:flex-end}

/* ── Form elements ───────────────────────────────── */
.frow{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.fg{display:flex;flex-direction:column;margin-bottom:15px}
.fg:last-child{margin-bottom:0}
.fg label{font-size:.8rem;font-weight:600;color:#444;margin-bottom:6px}
.req-mark{color:var(--red)}
.fc{
  padding:10px 13px;border:1.5px solid #d0dbd1;border-radius:var(--radius);
  font-size:.875rem;font-family:inherit;transition:border-color var(--tr),box-shadow var(--tr)
}
.fc:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.fc[readonly]{background:#f9f9f9;cursor:default}
textarea.fc{resize:vertical;min-height:80px}

/* Payment type toggle */
.pt-row{display:flex;gap:10px}
.pt-btn{
  flex:1;padding:11px 8px;border:2px solid #e0e9e1;border-radius:var(--radius);
  background:#fff;cursor:pointer;font-family:inherit;font-size:.85rem;font-weight:600;color:#555;
  transition:all var(--tr);display:flex;align-items:center;justify-content:center;gap:7px
}
.pt-btn.active{border-color:var(--g700);background:var(--g100);color:var(--g800)}

/* History table */
.hist-table{width:100%;border-collapse:collapse;font-size:.84rem}
.hist-table th{
  background:#f5f5f5;padding:10px 12px;text-align:left;
  font-weight:600;font-size:.77rem;color:#555;border-bottom:2px solid #e0e0e0
}
.hist-table td{padding:10px 12px;border-bottom:1px solid #f5f5f5;vertical-align:middle}

/* ── Notification Stack ──────────────────────────── */
#notif-stack{
  position:fixed;top:68px;right:18px;z-index:9999;
  display:flex;flex-direction:column;gap:9px;pointer-events:none
}
.notif{
  pointer-events:all;display:flex;align-items:flex-start;gap:12px;
  padding:14px 16px;border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);
  max-width:360px;animation:ntslide .25s ease;transition:opacity .3s,transform .3s
}
@keyframes ntslide{from{transform:translateX(30px);opacity:0}to{transform:translateX(0);opacity:1}}
.notif.success{background:#1b5e20;color:#fff}
.notif.error  {background:#b71c1c;color:#fff}
.notif.warning{background:#e65100;color:#fff}
.notif.info   {background:#1565c0;color:#fff}
.notif-icon{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.notif-body{flex:1}
.notif-title{font-weight:700;font-size:.84rem}
.notif-msg{font-size:.78rem;opacity:.9;margin-top:2px}
.notif-close{
  background:rgba(255,255,255,.2);border:none;color:#fff;
  width:22px;height:22px;border-radius:50%;font-size:.72rem;cursor:pointer;
  display:flex;align-items:center;justify-content:center;flex-shrink:0
}

/* ── Page Loader ─────────────────────────────────── */
#page-loader{
  position:fixed;inset:0;background:rgba(255,255,255,.88);z-index:2000;
  display:none;align-items:center;justify-content:center;flex-direction:column;gap:12px
}
#page-loader.show{display:flex}
.spin{
  width:42px;height:42px;
  border:4px solid #e8f5e9;border-top-color:var(--g700);
  border-radius:50%;animation:spinning .8s linear infinite
}
@keyframes spinning{to{transform:rotate(360deg)}}
#page-loader p{font-size:.88rem;color:var(--g800);font-weight:600}

@media(max-width:640px){
  .frow{grid-template-columns:1fr}
  .search-row{flex-direction:column;align-items:stretch}
  .summary-bar{gap:8px}
}
</style>
</head>
<body>

<div id="page-loader"><div class="spin"></div><p>Please wait…</p></div>
<div id="notif-stack"></div>

<?php require_once 'nav.php'; ?>

<div class="page">

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <h1><i class="fas fa-clipboard-check" style="margin-right:10px;opacity:.85"></i>Record Requirements</h1>
      <p>Search a student · Requirements auto-filter by class &amp; section (Day / Boarding)</p>
    </div>
    <a href="requirements_reports.php" class="btn" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;font-size:.83rem">
      <i class="fas fa-chart-bar"></i> View Reports
    </a>
  </div>

  <!-- Student Search -->
  <div class="search-card">
    <h2><i class="fas fa-magnifying-glass"></i> Find Student</h2>
    <div class="search-row">
      <div class="search-wrap" id="sw">
        <i class="fas fa-search srch-icon"></i>
        <input type="text" id="stuSearch" placeholder="Type student name or ID number…" autocomplete="off">
        <div class="ac-drop" id="acDrop"></div>
      </div>
      <div class="fgroup" style="min-width:130px">
        <label>Term <span class="req-mark">*</span></label>
        <select class="filter-sel" id="termSel">
          <option value="">Select Term</option>
          <option>Term 1</option><option>Term 2</option><option>Term 3</option>
        </select>
      </div>
      <div class="fgroup" style="min-width:100px">
        <label>Year <span class="req-mark">*</span></label>
        <input type="text" class="filter-sel" id="yearInp" value="<?= $currentYear ?>" placeholder="<?= $currentYear ?>" maxlength="4">
      </div>
      <button class="btn btn-outline" onclick="clearStudent()" style="align-self:flex-end">
        <i class="fas fa-rotate-left"></i> Clear
      </button>
    </div>
  </div>

  <!-- Student-specific section (hidden until a student is selected) -->
  <div id="studentSection" style="display:none">

    <!-- Student Banner -->
    <div class="student-banner">
      <div class="stu-avatar" id="stuInitials"></div>
      <div>
        <div class="stu-name" id="stuFullName"></div>
        <div class="stu-sub"  id="stuSub"></div>
        <div class="stu-tags" id="stuTags"></div>
      </div>
    </div>

    <!-- Requirements Card -->
    <div class="card">
      <div class="card-head">
        <h2><i class="fas fa-list-check"></i> Requirements</h2>
        <button class="refresh-btn" onclick="loadRequirements()">
          <i class="fas fa-arrows-rotate"></i> Refresh
        </button>
      </div>

      <!-- Summary pills -->
      <div class="summary-bar" id="summaryBar" style="display:none">
        <div class="sum-pill sp-all">
          <span class="pn" id="sumTotal">0</span>
          <div class="pl">Total</div>
        </div>
        <div class="sum-pill sp-completed">
          <span class="pn" id="sumCompleted">0</span>
          <div class="pl">Completed</div>
        </div>
        <div class="sum-pill sp-partial">
          <span class="pn" id="sumPartial">0</span>
          <div class="pl">Partial</div>
        </div>
        <div class="sum-pill sp-pending">
          <span class="pn" id="sumPending">0</span>
          <div class="pl">Pending</div>
        </div>
        <div class="sum-pill sp-waived">
          <span class="pn" id="sumWaived">0</span>
          <div class="pl">Waived</div>
        </div>
      </div>

      <!-- Table -->
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Requirement</th>
              <th>Applies To</th>
              <th>Required</th>
              <th>Brought / Paid</th>
              <th>Balance</th>
              <th>Progress</th>
              <th>Status</th>
              <th style="width:110px">Actions</th>
            </tr>
          </thead>
          <tbody id="reqBody">
            <tr>
              <td colspan="8">
                <div class="start-prompt">
                  <i class="fas fa-user-graduate"></i>
                  <h3>Search for a student above</h3>
                  <p>Select a term &amp; year, then type to find a student</p>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div><!-- end card -->

  </div><!-- end studentSection -->

</div><!-- end .page -->

<!-- ── Payment Modal ─────────────────────────────────── -->
<div class="modal" id="payModal">
  <div class="modal-box">
    <div class="modal-head mh-green">
      <h2><i class="fas fa-hand-holding-dollar"></i> Record Payment</h2>
      <button class="modal-close" onclick="closeModal('payModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form id="payForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="record_payment">
        <input type="hidden" name="student_id"     id="pyStuId">
        <input type="hidden" name="requirement_id" id="pyReqId">

        <div class="fg">
          <label>Requirement</label>
          <input type="text" id="pyReqName" class="fc" readonly>
        </div>

        <div class="fg">
          <label>Payment Type <span class="req-mark">*</span></label>
          <div class="pt-row">
            <button type="button" class="pt-btn active" id="ptItem" onclick="setPayType('item')">
              <i class="fas fa-box"></i> Item Delivery
            </button>
            <button type="button" class="pt-btn" id="ptCash" onclick="setPayType('cash')">
              <i class="fas fa-money-bill-wave"></i> Cash Payment
            </button>
          </div>
          <input type="hidden" name="payment_type" id="pyType" value="item">
        </div>

        <div id="qtyGroup" class="fg">
          <label>Quantity Delivered <span class="req-mark">*</span></label>
          <input type="number" name="quantity" id="pyQty" class="fc" value="1" min="1">
        </div>
        <div id="amtGroup" class="fg" style="display:none">
          <label>Amount Paid (UGX) <span class="req-mark">*</span></label>
          <input type="number" name="amount" id="pyAmt" class="fc" value="0" min="1" step="500">
        </div>

        <div class="frow">
          <div class="fg" style="margin-bottom:0">
            <label>Payment Date <span class="req-mark">*</span></label>
            <input type="date" name="payment_date" id="pyDate" class="fc">
          </div>
          <div class="fg" style="margin-bottom:0">
            <label>Notes <small style="font-weight:400;color:#aaa">(optional)</small></label>
            <input type="text" name="notes" id="pyNotes" class="fc" placeholder="Any remarks…">
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('payModal')">Cancel</button>
      <button class="btn btn-primary" id="savePayBtn" onclick="savePayment()">
        <i class="fas fa-save"></i> Save Payment
      </button>
    </div>
  </div>
</div>

<!-- ── Waive Modal ───────────────────────────────────── -->
<div class="modal" id="waiveModal">
  <div class="modal-box" style="max-width:440px">
    <div class="modal-head mh-amber">
      <h2><i class="fas fa-hand-peace"></i> Waive Requirement</h2>
      <button class="modal-close" onclick="closeModal('waiveModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form id="waiveForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="waive_requirement">
        <input type="hidden" name="student_id"     id="wvStuId">
        <input type="hidden" name="requirement_id" id="wvReqId">
        <div class="fg">
          <label>Requirement</label>
          <input type="text" id="wvReqName" class="fc" readonly>
        </div>
        <div class="fg" style="margin-bottom:0">
          <label>Reason for Waiving <span class="req-mark">*</span></label>
          <textarea name="reason" id="wvReason" class="fc" rows="3"
            placeholder="e.g. Financial hardship, scholarship, fully sponsored…"></textarea>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('waiveModal')">Cancel</button>
      <button class="btn btn-amber" onclick="saveWaive()">
        <i class="fas fa-check"></i> Confirm Waive
      </button>
    </div>
  </div>
</div>

<!-- ── History Modal ─────────────────────────────────── -->
<div class="modal" id="histModal">
  <div class="modal-box" style="max-width:720px">
    <div class="modal-head mh-blue">
      <h2><i class="fas fa-clock-rotate-left"></i> Payment History &mdash; <span id="histReqName" style="font-weight:400"></span></h2>
      <button class="modal-close" onclick="closeModal('histModal')"><i class="fas fa-times"></i></button>
    </div>
    <div id="histContent" style="overflow-x:auto;max-height:70vh;overflow-y:auto"></div>
  </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
let currentStudent    = null;
let currentReqs       = [];
let searchTimer       = null;
let acFocusIdx        = -1;

// ── Search-as-you-type ──────────────────────────────────────────────────────
const stuSearch = document.getElementById('stuSearch');
const acDrop    = document.getElementById('acDrop');

stuSearch.addEventListener('input', function () {
    clearTimeout(searchTimer);
    const q = this.value.trim();
    if (q.length < 2) { closeDrop(); return; }
    searchTimer = setTimeout(() => doSearch(q), 280);
});

// Keyboard nav in dropdown
stuSearch.addEventListener('keydown', function (e) {
    const items = acDrop.querySelectorAll('.ac-item');
    if (!items.length) return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        acFocusIdx = Math.min(acFocusIdx + 1, items.length - 1);
        updateFocus(items);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        acFocusIdx = Math.max(acFocusIdx - 1, 0);
        updateFocus(items);
    } else if (e.key === 'Enter' && acFocusIdx >= 0) {
        e.preventDefault();
        items[acFocusIdx].click();
    } else if (e.key === 'Escape') {
        closeDrop();
    }
});

function updateFocus(items) {
    items.forEach((el, i) => el.classList.toggle('focused', i === acFocusIdx));
}

async function doSearch(q) {
    try {
        const r = await fetch(`record_requirements.php?action=search_students&q=${encodeURIComponent(q)}`);
        const d = await r.json();
        if (!d.success) return;
        renderDrop(d.data);
    } catch (e) { /* silent */ }
}

function renderDrop(students) {
    acFocusIdx = -1;
    if (!students.length) {
        acDrop.innerHTML = '<div class="ac-empty"><i class="fas fa-search" style="margin-right:6px;opacity:.5"></i>No students found</div>';
        acDrop.classList.add('open');
        return;
    }
    acDrop.innerHTML = '';
    students.forEach(s => {
        const initials = ((s.first_name || '')[0] || '') + ((s.last_name || '')[0] || '');
        const secMark  = s.section === 'Day' ? '☀️' : '🌙';
        const item = document.createElement('div');
        item.className = 'ac-item';
        item.innerHTML = `
          <div class="ac-avatar">${esc(initials).toUpperCase()}</div>
          <div>
            <div class="ac-name">${esc(s.first_name + ' ' + s.last_name)}</div>
            <div class="ac-meta">${esc(s.student_id)} &middot; ${esc(s.current_class || '')} ${esc(s.stream || '')} &middot; ${secMark} ${esc(s.section || '')}</div>
          </div>`;
        // Use addEventListener — no inline onclick, no HTML-attribute quoting issues
        item.addEventListener('click', () => pickStudent(s.student_id));
        acDrop.appendChild(item);
    });
    acDrop.classList.add('open');
}

function closeDrop() { acDrop.classList.remove('open'); acDrop.innerHTML = ''; acFocusIdx = -1; }
document.addEventListener('click', e => { if (!document.getElementById('sw').contains(e.target)) closeDrop(); });

async function pickStudent(id) {
    closeDrop();
    showLoader();
    try {
        const r = await fetch(`record_requirements.php?action=get_student_info&student_id=${encodeURIComponent(id)}`);
        const d = await r.json();
        if (!d.success) throw new Error(d.message);
        currentStudent = d.data;
        stuSearch.value = `${d.data.first_name} ${d.data.last_name}  (${d.data.student_id})`;
        renderBanner(d.data);
        document.getElementById('studentSection').style.display = 'block';
        await loadRequirements();
    } catch (e) { notify('Error', e.message, 'error'); }
    finally { hideLoader(); }
}

function renderBanner(s) {
    const ini = ((s.first_name || '')[0] || '') + ((s.last_name || '')[0] || '');
    document.getElementById('stuInitials').textContent = ini.toUpperCase();
    document.getElementById('stuFullName').textContent = `${s.first_name} ${s.last_name}`;
    document.getElementById('stuSub').textContent      = `Student ID: ${s.student_id}`;
    const secClass = (s.section || '').toLowerCase();
    const secIcon  = s.section === 'Day' ? 'fa-sun' : s.section === 'Boarding' ? 'fa-moon' : 'fa-user';
    document.getElementById('stuTags').innerHTML =
        `<span class="stu-tag ${secClass}"><i class="fas ${secIcon}"></i> ${esc(s.section || '')} Student</span>` +
        (s.current_class ? `<span class="stu-tag">${esc(s.current_class)}</span>` : '') +
        (s.stream        ? `<span class="stu-tag">${esc(s.stream)}</span>` : '');
}

// ── Load requirements ────────────────────────────────────────────────────────
async function loadRequirements() {
    if (!currentStudent) return;
    const term = document.getElementById('termSel').value;
    const year = document.getElementById('yearInp').value.trim();
    if (!term || year.length < 4) {
        notify('Missing Period', 'Please select a Term and enter a 4-digit Year.', 'warning'); return;
    }
    showLoader();
    try {
        const url = `record_requirements.php?action=get_requirements`
            + `&student_id=${encodeURIComponent(currentStudent.student_id)}`
            + `&term=${encodeURIComponent(term)}&year=${encodeURIComponent(year)}`;
        const r = await fetch(url);
        const d = await r.json();
        if (!d.success) throw new Error(d.message);
        currentReqs = d.data;
        updateSummary(d.data);
        renderReqTable(d.data);
    } catch (e) { notify('Error', e.message, 'error'); }
    finally { hideLoader(); }
}

function updateSummary(data) {
    const bar = document.getElementById('summaryBar');
    if (!data.length) { bar.style.display = 'none'; return; }
    bar.style.display = 'flex';
    let c = { completed: 0, partial: 0, pending: 0, waived: 0 };
    data.forEach(r => { if (c[r.student_status] !== undefined) c[r.student_status]++; });
    document.getElementById('sumTotal').textContent     = data.length;
    document.getElementById('sumCompleted').textContent = c.completed;
    document.getElementById('sumPartial').textContent   = c.partial;
    document.getElementById('sumPending').textContent   = c.pending;
    document.getElementById('sumWaived').textContent    = c.waived;
}

function renderReqTable(data) {
    const tbody = document.getElementById('reqBody');
    if (!data.length) {
        tbody.innerHTML = `<tr><td colspan="8">
          <div class="empty-state">
            <i class="fas fa-clipboard-list"></i>
            <p><strong>No requirements found</strong> for this student in the selected period.</p>
            <p style="font-size:.8rem;margin-top:6px;color:#aaa">
              Check that active requirements exist for this term/year and match the student's class and section.
            </p>
          </div></td></tr>`;
        return;
    }

    const rows = data.map(req => {
        const qtyReq   = parseInt(req.quantity_per_student) || 1;
        const cashEq   = parseFloat(req.cash_equivalent)    || 0;
        const totalReq = qtyReq * cashEq;
        const brought  = parseInt(req.items_brought)         || 0;
        const paid     = parseFloat(req.cash_paid)           || 0;
        const value    = parseFloat(req.total_value)         || 0;
        const balance  = parseFloat(req.balance)             || 0;
        const status   = req.student_status                  || 'pending';
        const pct      = totalReq > 0 ? Math.min((value / totalReq) * 100, 100) : 0;
        const progColor = pct >= 100 ? 'var(--g700)' : pct >= 50 ? 'var(--amber)' : 'var(--red)';
        const fmtUGX   = v => 'UGX ' + Number(v).toLocaleString('en-UG');

        const secType  = req.section_type || 'All';
        const secBadge = secType === 'Day'
            ? `<span class="sbadge sb-day"><i class="fas fa-sun"></i> Day</span>`
            : secType === 'Boarding'
            ? `<span class="sbadge sb-boarding"><i class="fas fa-moon"></i> Boarding</span>`
            : `<span class="sbadge sb-all"><i class="fas fa-users"></i> All</span>`;

        const classScope = req['class']
            ? `<div style="font-size:.78rem;font-weight:600;margin-bottom:3px">${esc(req['class'])}${req.stream ? ' &middot; '+esc(req.stream) : ''}</div>`
            : `<div style="font-size:.78rem;color:#aaa;margin-bottom:3px">All Classes</div>`;

        const broughtRow = brought > 0 ? `<div>${brought} item${brought !== 1 ? 's' : ''}</div>` : '';
        const paidRow    = paid    > 0 ? `<div style="font-size:.78rem;color:#888">${fmtUGX(paid)}</div>` : '';
        const bothEmpty  = !broughtRow && !paidRow;

        const canAct = status !== 'completed' && status !== 'waived';

        // Build the row as a string (no user values in onclick — buttons wired via addEventListener below)
        return {
            req, canAct, status, qtyReq, cashEq, totalReq,
            bothEmpty, broughtRow, paidRow, value, balance, pct, progColor,
            classScope, secBadge, fmtUGX
        };
    });

    // Two-pass: build HTML then wire events — avoids any onclick quoting issues
    tbody.innerHTML = '';
    rows.forEach(({ req, canAct, status, qtyReq, cashEq, totalReq, bothEmpty, broughtRow, paidRow, value, balance, pct, progColor, classScope, secBadge, fmtUGX }) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>
            <div class="req-name">${esc(req.requirement_name)}</div>
            <div class="req-sub">${qtyReq} × ${fmtUGX(cashEq)} = ${fmtUGX(totalReq)}</div>
          </td>
          <td>${classScope}${secBadge}</td>
          <td>
            <strong>${qtyReq}</strong> item${qtyReq !== 1 ? 's' : ''}
            <div style="font-size:.75rem;color:#888">${fmtUGX(totalReq)}</div>
          </td>
          <td>${bothEmpty ? '<span style="color:#ccc">—</span>' : broughtRow + paidRow}</td>
          <td style="font-weight:700;color:${balance > 0 ? 'var(--red)' : (value > 0 || status === 'waived') ? 'var(--g700)' : 'inherit'}">
            ${balance > 0
                ? fmtUGX(balance)
                : (value > 0 || status === 'waived')
                    ? '<span style="color:var(--g600);font-weight:600">Settled</span>'
                    : '<span style="color:#ccc">—</span>'}
          </td>
          <td>
            <div class="prog-wrap">
              <div style="font-size:.72rem;color:#888;text-align:right">${pct.toFixed(0)}%</div>
              <div class="prog-bar">
                <div class="prog-fill" style="width:${pct}%;background:${progColor}"></div>
              </div>
            </div>
          </td>
          <td>
            <span class="badge b-${status}">${status}</span>
            ${req.waived_reason ? `<div style="font-size:.69rem;color:#888;margin-top:3px;max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${esc(req.waived_reason)}">Reason on file</div>` : ''}
          </td>
          <td>
            <div class="ac-cell">
              ${canAct ? `<button class="ibtn ib-pay"   title="Record Payment"><i class="fas fa-plus"></i></button>
                          <button class="ibtn ib-waive" title="Waive Requirement"><i class="fas fa-hand-peace"></i></button>` : ''}
              ${req.record_id ? `<button class="ibtn ib-hist" title="Payment History"><i class="fas fa-clock-rotate-left"></i></button>` : ''}
            </div>
          </td>`;

        // Safe event wiring — closure captures req object directly, no string quoting needed
        if (canAct) {
            tr.querySelector('.ib-pay').addEventListener('click',   () => openPayModal(req.requirement_id, req.requirement_name));
            tr.querySelector('.ib-waive').addEventListener('click', () => openWaiveModal(req.requirement_id, req.requirement_name));
        }
        if (req.record_id) {
            tr.querySelector('.ib-hist').addEventListener('click', () => viewHistory(req.record_id, req.requirement_name));
        }
        tbody.appendChild(tr);
    });
}

// ── Payment modal ────────────────────────────────────────────────────────────
function openPayModal(reqId, reqName) {
    document.getElementById('pyStuId').value  = currentStudent.student_id;
    document.getElementById('pyReqId').value  = reqId;
    document.getElementById('pyReqName').value = reqName;
    document.getElementById('pyDate').value   = new Date().toISOString().split('T')[0];
    document.getElementById('pyNotes').value  = '';
    document.getElementById('pyQty').value    = '1';
    document.getElementById('pyAmt').value    = '0';
    setPayType('item');
    openModal('payModal');
}

function setPayType(type) {
    document.getElementById('pyType').value = type;
    document.getElementById('ptItem').classList.toggle('active', type === 'item');
    document.getElementById('ptCash').classList.toggle('active', type === 'cash');
    document.getElementById('qtyGroup').style.display = type === 'item' ? '' : 'none';
    document.getElementById('amtGroup').style.display = type === 'cash' ? '' : 'none';
}

async function savePayment() {
    const btn = document.getElementById('savePayBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    try {
        const r = await fetch('record_requirements.php', { method:'POST', body: new FormData(document.getElementById('payForm')) });
        const d = await r.json();
        if (!d.success) throw new Error(d.message);
        notify('Recorded', d.message, 'success');
        closeModal('payModal');
        await loadRequirements();
    } catch (e) { notify('Error', e.message, 'error'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Payment'; }
}

// ── Waive modal ──────────────────────────────────────────────────────────────
function openWaiveModal(reqId, reqName) {
    document.getElementById('wvStuId').value   = currentStudent.student_id;
    document.getElementById('wvReqId').value   = reqId;
    document.getElementById('wvReqName').value = reqName;
    document.getElementById('wvReason').value  = '';
    openModal('waiveModal');
}

async function saveWaive() {
    const reason = document.getElementById('wvReason').value.trim();
    if (!reason) { notify('Validation', 'Please enter a reason for waiving.', 'warning'); return; }
    showLoader();
    try {
        const r = await fetch('record_requirements.php', { method:'POST', body: new FormData(document.getElementById('waiveForm')) });
        const d = await r.json();
        if (!d.success) throw new Error(d.message);
        notify('Waived', d.message, 'success');
        closeModal('waiveModal');
        await loadRequirements();
    } catch (e) { notify('Error', e.message, 'error'); }
    finally { hideLoader(); }
}

// ── History modal ────────────────────────────────────────────────────────────
async function viewHistory(recordId, reqName) {
    document.getElementById('histReqName').textContent = reqName;
    document.getElementById('histContent').innerHTML =
        '<div style="padding:36px;text-align:center"><div class="spin" style="margin:0 auto"></div></div>';
    openModal('histModal');
    try {
        const r = await fetch(`record_requirements.php?action=get_payment_history&record_id=${recordId}`);
        const d = await r.json();
        if (!d.success) throw new Error(d.message);
        if (!d.data.length) {
            document.getElementById('histContent').innerHTML =
                '<div class="empty-state" style="padding:40px"><i class="fas fa-clock-rotate-left"></i><p>No payment history recorded yet</p></div>';
            return;
        }
        document.getElementById('histContent').innerHTML = `
          <table class="hist-table">
            <thead>
              <tr>
                <th>Date</th><th>Type</th><th>Qty / Amount</th>
                <th>Notes</th><th>Recorded By</th><th>Time</th>
              </tr>
            </thead>
            <tbody>
              ${d.data.map(p => `<tr>
                <td>${esc(p.payment_date)}</td>
                <td><span class="badge ${p.payment_type === 'item' ? 'b-completed' : 'b-partial'}">${p.payment_type}</span></td>
                <td>${p.payment_type === 'item'
                    ? `${p.quantity} item${p.quantity != 1 ? 's' : ''}`
                    : `UGX ${Number(p.amount).toLocaleString('en-UG')}`}</td>
                <td>${esc(p.notes || '—')}</td>
                <td style="white-space:nowrap">${esc(p.user_name || '—')}</td>
                <td style="font-size:.77rem;color:#888;white-space:nowrap">${new Date(p.recorded_at).toLocaleString()}</td>
              </tr>`).join('')}
            </tbody>
          </table>`;
    } catch (e) {
        document.getElementById('histContent').innerHTML =
            `<div class="empty-state"><i class="fas fa-circle-exclamation"></i><p>${esc(e.message)}</p></div>`;
    }
}

function clearStudent() {
    currentStudent = null; currentReqs = [];
    stuSearch.value = '';
    document.getElementById('studentSection').style.display = 'none';
    document.getElementById('summaryBar').style.display     = 'none';
    document.getElementById('reqBody').innerHTML = `<tr><td colspan="8">
      <div class="start-prompt">
        <i class="fas fa-user-graduate"></i>
        <h3>Search for a student above</h3>
        <p>Select a term &amp; year, then type to find a student</p>
      </div></td></tr>`;
}

// ── Modal helpers ────────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }
document.querySelectorAll('.modal').forEach(m =>
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); }));
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal.active').forEach(m => m.classList.remove('active'));
});

// Period change reloads if student already selected
document.getElementById('termSel').addEventListener('change', () => { if (currentStudent) loadRequirements(); });
document.getElementById('yearInp').addEventListener('change', () => {
    if (currentStudent && document.getElementById('yearInp').value.length === 4) loadRequirements();
});

// ── Utilities ────────────────────────────────────────────────────────────────
function showLoader() { document.getElementById('page-loader').classList.add('show'); }
function hideLoader() { document.getElementById('page-loader').classList.remove('show'); }
function esc(v) {
    return String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function notify(title, msg, type = 'success', dur = 4500) {
    const icons = { success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
    const n = document.createElement('div');
    n.className = `notif ${type}`;
    n.innerHTML = `
      <i class="fas ${icons[type] || icons.info} notif-icon"></i>
      <div class="notif-body">
        <div class="notif-title">${esc(title)}</div>
        <div class="notif-msg">${esc(msg)}</div>
      </div>
      <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('notif-stack').prepend(n);
    setTimeout(() => {
        n.style.opacity   = '0';
        n.style.transform = 'translateX(30px)';
        setTimeout(() => n.remove(), 300);
    }, dur);
}
</script>
</body>
</html>
<?php $conn->close(); ?>
