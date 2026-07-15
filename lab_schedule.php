<?php
/**
 * lab_schedule.php — Lab Booking & Schedule Management
 * SchoolPilot  ·  Production build
 *
 * Security:  CSRF tokens on all mutations · prepared statements only ·
 *            whitelist validation · no DB errors exposed to client ·
 *            week param sanitised & snapped to Monday.
 *
 * DB tables assumed:
 *   lab_bookings(id, booking_date, start_time, end_time, purpose,
 *                responsible_person, contact_email, title, notes,
 *                created_by, created_at, updated_at)
 *   lab_booking_equipment(id, booking_id, equipment_id)
 *   lab_equipment(id, name, status)   ← from add_lab_equipment.php
 */

require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';

/* ─── CSRF ──────────────────────────────────────────────── */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* ─── Constants ─────────────────────────────────────────── */
const ALLOWED_PURPOSES = ['research', 'class', 'maintenance', 'training', 'meeting'];

/* ─── Helpers ───────────────────────────────────────────── */
function validateDate(string $d): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $day] = explode('-', $d);
    return checkdate((int)$m, (int)$day, (int)$y);
}
function validateTime(string $t): bool
{
    return (bool) preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t);
}
function jsonErr(string $msg): never
{
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function jsonOk(array $extra = []): never
{
    echo json_encode(array_merge(['success' => true], $extra));
    exit;
}
function csrfGuard(): void
{
    global $csrf;
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($csrf, $_POST['csrf_token'])
    ) {
        jsonErr('Security token mismatch. Please refresh the page.');
    }
}

/* ─── AJAX dispatcher ───────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    switch ($_POST['action']) {
        case 'save_booking':    csrfGuard(); handleSave();           break;
        case 'delete_booking':  csrfGuard(); handleDelete();         break;
        case 'check_conflicts':              handleConflicts();       break;
        case 'get_bookings':                 handleGetBookings();     break;
        default: jsonErr('Unknown action.');
    }
}

/* ─── AJAX: save (insert or update) ────────────────────── */
function handleSave(): void
{
    global $conn;

    $id        = max(0, (int)($_POST['id'] ?? 0));
    $date      = $_POST['booking_date'] ?? '';
    $start     = substr($_POST['start_time'] ?? '', 0, 5);
    $end       = substr($_POST['end_time'] ?? '',   0, 5);
    $purpose   = $_POST['purpose'] ?? '';
    $person    = trim($_POST['responsible_person'] ?? '');
    $email     = trim($_POST['contact_email'] ?? '');
    $title     = trim($_POST['title'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');
    $eqRaw     = (array)($_POST['equipment_ids'] ?? []);
    $eqIds     = array_values(array_filter(array_map('intval', $eqRaw), fn($v) => $v > 0));
    $uid       = (int)($_SESSION['user_id'] ?? 0);

    // Validation
    if (!validateDate($date))                         jsonErr('Invalid date.');
    if (!validateTime($start))                        jsonErr('Invalid start time.');
    if (!validateTime($end))                          jsonErr('Invalid end time.');
    if ($start >= $end)                               jsonErr('End time must be after start time.');
    if (!in_array($purpose, ALLOWED_PURPOSES, true))  jsonErr('Invalid booking purpose.');
    if ($person === '')                               jsonErr('Responsible person is required.');
    if (mb_strlen($person) > 150)                     jsonErr('Name is too long.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))   jsonErr('Invalid email address.');
    if ($title === '')                                jsonErr('Title / description is required.');
    if (mb_strlen($title) > 255)                      jsonErr('Title too long (max 255 characters).');

    mysqli_begin_transaction($conn);
    try {
        if ($id > 0) {
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE lab_bookings
                 SET booking_date=?, start_time=?, end_time=?, purpose=?,
                     responsible_person=?, contact_email=?, title=?, notes=?,
                     updated_at=NOW()
                 WHERE id=?"
            );
            mysqli_stmt_bind_param(
                $stmt, 'ssssssssi',
                $date, $start, $end, $purpose,
                $person, $email, $title, $notes, $id
            );
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            // Remove old equipment links
            $del = mysqli_prepare($conn, "DELETE FROM lab_booking_equipment WHERE booking_id=?");
            mysqli_stmt_bind_param($del, 'i', $id);
            mysqli_stmt_execute($del);
            mysqli_stmt_close($del);
        } else {
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO lab_bookings
                 (booking_date, start_time, end_time, purpose, responsible_person,
                  contact_email, title, notes, created_by, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())"
            );
            mysqli_stmt_bind_param(
                $stmt, 'ssssssssi',
                $date, $start, $end, $purpose,
                $person, $email, $title, $notes, $uid
            );
            mysqli_stmt_execute($stmt);
            $id = (int)mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);
        }

        // Re-link equipment
        if (!empty($eqIds)) {
            $ins = mysqli_prepare($conn,
                "INSERT INTO lab_booking_equipment (booking_id, equipment_id) VALUES (?,?)"
            );
            foreach ($eqIds as $eqId) {
                mysqli_stmt_bind_param($ins, 'ii', $id, $eqId);
                mysqli_stmt_execute($ins);
            }
            mysqli_stmt_close($ins);
        }

        mysqli_commit($conn);
        jsonOk(['message' => 'Booking saved successfully.', 'booking_id' => $id]);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('[LabSchedule] save: ' . $e->getMessage());
        jsonErr('Failed to save booking. Please try again.');
    }
}

/* ─── AJAX: delete ──────────────────────────────────────── */
function handleDelete(): void
{
    global $conn;
    $id = max(0, (int)($_POST['id'] ?? 0));
    if ($id === 0) jsonErr('Invalid booking ID.');

    mysqli_begin_transaction($conn);
    try {
        $d1 = mysqli_prepare($conn, "DELETE FROM lab_booking_equipment WHERE booking_id=?");
        mysqli_stmt_bind_param($d1, 'i', $id);
        mysqli_stmt_execute($d1);
        mysqli_stmt_close($d1);

        $d2 = mysqli_prepare($conn, "DELETE FROM lab_bookings WHERE id=?");
        mysqli_stmt_bind_param($d2, 'i', $id);
        mysqli_stmt_execute($d2);
        $affected = mysqli_stmt_affected_rows($d2);
        mysqli_stmt_close($d2);

        mysqli_commit($conn);
        if ($affected > 0) {
            jsonOk(['message' => 'Booking deleted successfully.']);
        } else {
            jsonErr('Booking not found.');
        }
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('[LabSchedule] delete: ' . $e->getMessage());
        jsonErr('Failed to delete booking. Please try again.');
    }
}

/* ─── AJAX: conflict check ──────────────────────────────── */
function handleConflicts(): void
{
    global $conn;
    $date      = $_POST['booking_date'] ?? '';
    $start     = substr($_POST['start_time'] ?? '', 0, 5);
    $end       = substr($_POST['end_time'] ?? '',   0, 5);
    $excludeId = max(0, (int)($_POST['exclude_id'] ?? 0));

    if (!validateDate($date) || !validateTime($start) || !validateTime($end)) {
        jsonOk(['conflicts' => []]);
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, title, start_time, end_time, responsible_person, purpose
         FROM lab_bookings
         WHERE booking_date=? AND id!=? AND start_time<? AND end_time>?
         ORDER BY start_time"
    );
    mysqli_stmt_bind_param($stmt, 'siss', $date, $excludeId, $end, $start);
    mysqli_stmt_execute($stmt);
    $res  = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    mysqli_stmt_close($stmt);
    jsonOk(['conflicts' => $rows]);
}

/* ─── AJAX: fetch bookings for week ─────────────────────── */
function handleGetBookings(): void
{
    global $conn;
    $week = $_POST['week'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) || !strtotime($week)) {
        $week = date('Y-m-d', strtotime('monday this week'));
    }
    $weekEnd = date('Y-m-d', strtotime($week . ' +6 days'));
    jsonOk(['bookings' => dbFetchWeek($conn, $week, $weekEnd)]);
}

/* ─── DB helpers ────────────────────────────────────────── */
function dbFetchWeek(mysqli $db, string $wStart, string $wEnd): array
{
    $stmt = mysqli_prepare(
        $db,
        "SELECT b.id, b.booking_date, b.start_time, b.end_time, b.purpose,
                b.responsible_person, b.contact_email, b.title, b.notes,
                b.created_at,
                GROUP_CONCAT(e.name   ORDER BY e.name SEPARATOR ', ') AS equipment_names,
                GROUP_CONCAT(e.id     ORDER BY e.name SEPARATOR ',')  AS equipment_ids_csv
         FROM lab_bookings b
         LEFT JOIN lab_booking_equipment be ON be.booking_id = b.id
         LEFT JOIN lab_equipment e           ON e.id = be.equipment_id
         WHERE b.booking_date BETWEEN ? AND ?
         GROUP BY b.id
         ORDER BY b.booking_date, b.start_time"
    );
    mysqli_stmt_bind_param($stmt, 'ss', $wStart, $wEnd);
    mysqli_stmt_execute($stmt);
    $res  = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $r['equipment_id_list'] = $r['equipment_ids_csv']
            ? array_map('intval', explode(',', $r['equipment_ids_csv']))
            : [];
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function dbFetchEquipment(mysqli $db): array
{
    $res = mysqli_query($db,
        "SELECT id, name FROM lab_equipment WHERE status='active' ORDER BY name LIMIT 200"
    );
    if (!$res) return [];
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    return $rows;
}

function dbFetchPersons(mysqli $db): array
{
    $res = mysqli_query($db,
        "SELECT DISTINCT responsible_person FROM lab_bookings
         WHERE responsible_person != '' ORDER BY responsible_person LIMIT 100"
    );
    if (!$res) return [];
    $out = [];
    while ($r = mysqli_fetch_assoc($res)) $out[] = $r['responsible_person'];
    return $out;
}

/* ─── Page data ─────────────────────────────────────────── */
// Validate & snap week to Monday
$currentWeek = date('Y-m-d', strtotime('monday this week'));
if (isset($_GET['week'])) {
    $wp = $_GET['week'];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $wp) && strtotime($wp) !== false) {
        $currentWeek = date('Y-m-d', strtotime('monday this week', strtotime($wp)));
    }
}
$weekEnd     = date('Y-m-d', strtotime($currentWeek . ' +6 days'));
$weekBookings   = dbFetchWeek($conn, $currentWeek, $weekEnd);
$equipmentList  = dbFetchEquipment($conn);
$persons        = dbFetchPersons($conn);

// Stats for header pills
$totalBookings  = count($weekBookings);
$uniquePersons  = count(array_unique(array_column($weekBookings, 'responsible_person')));
$totalHours     = 0;
foreach ($weekBookings as $b) {
    [$sh, $sm] = array_map('intval', explode(':', $b['start_time']));
    [$eh, $em] = array_map('intval', explode(':', $b['end_time']));
    $totalHours += ($eh * 60 + $em - $sh * 60 - $sm) / 60;
}

$tracker->trackAction('Lab Schedule');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Lab Schedule &mdash; SchoolPilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
<style>
/* ── Variables (matches view_students.php design system) ── */
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--red-bg:#ffebee;
  --orange:#e65100;--orange-bg:#fff3e0;
  --blue:#1565c0;--blue-bg:#e3f2fd;
  --purple:#6a1b9a;--purple-bg:#f3e5f5;
  --amber:#f57f17;--amber-bg:#fffde7;
  --gray:#546e7a;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);
  --shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --tr:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}
a{color:inherit;text-decoration:none}
button{font-family:inherit;cursor:pointer}
input,select,textarea{font-family:inherit}

/* ── Layout ──────────────────────────────────────────────── */
.page{max-width:100%;margin:0 auto;padding:24px 20px 60px}

/* ── Page header ─────────────────────────────────────────── */
.page-header{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--radius-lg);padding:28px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:22px;margin-top:40px;box-shadow:var(--shadow-lg)}
.hdr-left h1{color:#fff;font-size:1.55rem;font-weight:700;display:flex;align-items:center;gap:12px}
.hdr-left p{color:rgba(255,255,255,.78);font-size:.9rem;margin-top:4px}
.hdr-right{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.stat-pill{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:40px;padding:8px 18px;text-align:center;min-width:84px}
.stat-pill .n{font-size:1.35rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.72rem;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.5px}

/* ── Card ────────────────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px}

/* ── Toolbar ─────────────────────────────────────────────── */
.toolbar{padding:16px 22px;border-bottom:1px solid #e8ede9;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}
.toolbar-left{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.toolbar-right{display:flex;gap:8px;align-items:center;flex-shrink:0}

/* ── Buttons ─────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;transition:all var(--tr);white-space:nowrap;cursor:pointer}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-secondary{background:#f5f5f5;color:#444;border:1.5px solid #ddd}.btn-secondary:hover{background:#eee}
.btn-danger{background:var(--red);color:#fff}.btn-danger:hover{background:#b71c1c}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-pdf{background:#c62828;color:#fff}.btn-pdf:hover{background:var(--red)}
.btn-sm{padding:6px 12px;font-size:.8rem}

/* ── View toggle ─────────────────────────────────────────── */
.view-toggle{display:flex;background:#f0f4f1;border-radius:var(--radius);padding:3px;gap:2px}
.vt-btn{padding:6px 14px;border:none;border-radius:6px;font-size:.82rem;font-weight:600;background:transparent;color:#666;transition:all var(--tr)}
.vt-btn.active{background:#fff;color:var(--g800);box-shadow:var(--shadow)}

/* ── Week navigator ──────────────────────────────────────── */
.week-nav{display:flex;align-items:center;gap:8px}
.week-nav button{width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:var(--radius);background:#fff;color:#444;display:flex;align-items:center;justify-content:center;font-size:.85rem;transition:all var(--tr)}
.week-nav button:hover{border-color:var(--g600);background:var(--g100);color:var(--g800)}
.week-label{font-size:.88rem;font-weight:600;color:#333;white-space:nowrap;min-width:170px;text-align:center}

/* ── Legend ──────────────────────────────────────────────── */
.legend{display:flex;flex-wrap:wrap;gap:10px;padding:12px 22px;border-bottom:1px solid #e8ede9;background:#fafbfa}
.legend-item{display:flex;align-items:center;gap:6px;font-size:.8rem;color:#555}
.legend-dot{width:12px;height:12px;border-radius:3px;flex-shrink:0}
.ld-research{background:#1565c0}
.ld-class{background:#2e7d32}
.ld-maintenance{background:#e65100}
.ld-training{background:#6a1b9a}
.ld-meeting{background:#f57f17}

/* ── Calendar view ───────────────────────────────────────── */
.cal-container{padding:0;overflow-x:auto}
.cal-grid{display:grid;grid-template-columns:64px repeat(5,1fr);min-width:600px}
.cal-header-cell{padding:10px 8px;text-align:center;border-bottom:2px solid #e8ede9;background:var(--g50)}
.cal-header-cell .day-name{font-size:.78rem;font-weight:700;color:var(--g800);text-transform:uppercase;letter-spacing:.5px}
.cal-header-cell .day-date{font-size:1.1rem;font-weight:700;color:#222;margin-top:2px}
.cal-header-cell.today{background:var(--g100)}
.cal-header-cell.today .day-date{color:var(--g700)}
.cal-time-label{font-size:.72rem;color:#999;padding:0 6px;text-align:right;border-right:1px solid #e8ede9;height:60px;display:flex;align-items:flex-start;padding-top:4px}
.cal-day-col{border-right:1px solid #f0f4f1;min-height:720px;position:relative;background:repeating-linear-gradient(180deg,transparent 0,transparent 59px,#f0f4f1 59px,#f0f4f1 60px)}
.cal-day-col.today{background:repeating-linear-gradient(180deg,#f5fbf5 0,#f5fbf5 59px,#d8edd8 59px,#d8edd8 60px)}
.cal-time-col{border-right:1px solid #e8ede9}

/* ── Booking block ───────────────────────────────────────── */
.booking-block{position:absolute;left:3px;right:3px;border-radius:6px;padding:4px 7px;cursor:pointer;transition:all var(--tr);overflow:hidden;border-left:3px solid transparent}
.booking-block:hover{filter:brightness(.93);box-shadow:0 2px 8px rgba(0,0,0,.18);z-index:10}
.bb-title{font-size:.72rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bb-time{font-size:.67rem;opacity:.8;margin-top:1px}
.bb-person{font-size:.67rem;opacity:.75;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* Purpose colours */
.bb-research{background:#bbdefb;border-color:#1565c0;color:#0d47a1}
.bb-class{background:#c8e6c9;border-color:#2e7d32;color:#1b5e20}
.bb-maintenance{background:#ffe0b2;border-color:#e65100;color:#bf360c}
.bb-training{background:#e1bee7;border-color:#6a1b9a;color:#4a148c}
.bb-meeting{background:#fff9c4;border-color:#f57f17;color:#e65100}

/* ── Empty day state ─────────────────────────────────────── */
.cal-empty{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:.75rem;color:#ccc;text-align:center;pointer-events:none}

/* ── List / table view ───────────────────────────────────── */
.list-view{display:none}
.list-filters{display:flex;flex-wrap:wrap;gap:10px;padding:14px 22px;border-bottom:1px solid #e8ede9;background:#fafbfa;align-items:flex-end}
.filter-group{display:flex;flex-direction:column;gap:4px}
.filter-group label{font-size:.75rem;color:#6b7c6d;font-weight:600}
.filter-select,.search-input{padding:8px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;background:#fff;min-width:130px;transition:border-color var(--tr)}
.filter-select:focus,.search-input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:12px 14px;text-align:left;font-size:.8rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--tr)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:12px 14px;font-size:.875rem;vertical-align:middle}
.result-count{font-size:.8rem;color:#6b7c6d;padding:10px 22px;border-top:1px solid #e8ede9}

/* ── Action buttons ──────────────────────────────────────── */
.action-cell{display:flex;gap:5px;align-items:center}
.btn-icon{width:30px;height:30px;border:none;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;transition:all var(--tr)}
.btn-icon:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.18)}
.bi-edit{background:#fff3e0;color:#e65100}.bi-edit:hover{background:#e65100;color:#fff}
.bi-delete{background:#ffebee;color:var(--red)}.bi-delete:hover{background:var(--red);color:#fff}

/* ── Badge ───────────────────────────────────────────────── */
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.badge-research{background:var(--blue-bg);color:var(--blue)}
.badge-class{background:var(--g100);color:var(--g800)}
.badge-maintenance{background:var(--orange-bg);color:var(--orange)}
.badge-training{background:var(--purple-bg);color:var(--purple)}
.badge-meeting{background:var(--amber-bg);color:var(--amber)}

/* ── Skeleton ────────────────────────────────────────────── */
.skeleton{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;height:14px;display:inline-block;width:80%}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ── Empty state ─────────────────────────────────────────── */
.empty-state{text-align:center;padding:60px 20px;color:#8a9a8b}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;opacity:.4}
.empty-state p{font-size:.95rem}

/* ── Modal ───────────────────────────────────────────────── */
.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);animation:fadeOverlay .2s ease}
@keyframes fadeOverlay{from{opacity:0}to{opacity:1}}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:780px;box-shadow:var(--shadow-lg);animation:slideDown .25s ease;margin:auto}
@keyframes slideDown{from{transform:translateY(-24px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%);padding:20px 26px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head h2{color:#fff;font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background var(--tr)}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:26px 28px 22px}
.modal-footer{padding:16px 28px 22px;display:flex;justify-content:flex-end;gap:10px;border-top:1px solid #e8ede9}

/* ── Form ────────────────────────────────────────────────── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
.form-label{font-size:.82rem;font-weight:600;color:#444}
.form-label .req{color:var(--red)}
.form-control{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;background:#fff;transition:border-color var(--tr),box-shadow var(--tr)}
.form-control:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.form-control.error{border-color:var(--red)}
.form-hint{font-size:.75rem;color:#888}
.form-section{font-size:.78rem;font-weight:700;color:var(--g800);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;margin-top:4px;padding-bottom:6px;border-bottom:1px solid #e8ede9}

/* ── Conflict warning ────────────────────────────────────── */
.conflict-box{background:#fff8e1;border:1px solid #ffe082;border-radius:var(--radius);padding:12px 16px;margin-bottom:18px;display:none}
.conflict-box.visible{display:block}
.conflict-box-title{font-size:.82rem;font-weight:700;color:#e65100;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.conflict-item{font-size:.8rem;color:#444;padding:4px 0}

/* ── Equipment grid ──────────────────────────────────────── */
.eq-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px}
.eq-option{display:flex;align-items:center;gap:8px;padding:8px 10px;border:1.5px solid #d0dbd1;border-radius:var(--radius);cursor:pointer;transition:all var(--tr);font-size:.82rem}
.eq-option:hover{border-color:var(--g600);background:var(--g50)}
.eq-option input[type=checkbox]{accent-color:var(--g700);width:15px;height:15px;flex-shrink:0}
.eq-option.checked{border-color:var(--g600);background:var(--g100);color:var(--g800)}

/* ── Confirm dialog ──────────────────────────────────────── */
.dialog{display:none;position:fixed;inset:0;z-index:1100;background:rgba(0,0,0,.45);backdrop-filter:blur(3px)}
.dialog.active{display:flex;align-items:center;justify-content:center;padding:20px}
.dialog-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:400px;box-shadow:var(--shadow-lg);overflow:hidden;animation:slideDown .2s ease}
.dialog-head{padding:18px 22px;display:flex;align-items:center;gap:10px}
.dialog-head.danger{background:var(--red-bg)}
.dialog-head.info{background:var(--blue-bg)}
.dialog-head i{font-size:1.3rem}
.dialog-head.danger i{color:var(--red)}
.dialog-head.info i{color:var(--blue)}
.dialog-head span{font-weight:700;font-size:1rem}
.dialog-body{padding:16px 22px;font-size:.9rem;color:#444;line-height:1.5}
.dialog-actions{padding:14px 22px 18px;display:flex;justify-content:flex-end;gap:8px}

/* ── Notification stack ──────────────────────────────────── */
#notif-stack{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.notif{display:flex;align-items:flex-start;gap:12px;padding:13px 16px;border-radius:var(--radius);box-shadow:0 4px 16px rgba(0,0,0,.18);min-width:280px;max-width:380px;pointer-events:auto;transition:all .3s ease;animation:slideNotif .3s ease}
@keyframes slideNotif{from{transform:translateX(30px);opacity:0}to{transform:translateX(0);opacity:1}}
.notif.success{background:#1b5e20;color:#fff}
.notif.error{background:#b71c1c;color:#fff}
.notif.warning{background:#e65100;color:#fff}
.notif.info{background:#1565c0;color:#fff}
.notif-icon{font-size:1rem;flex-shrink:0;margin-top:1px}
.notif-body{flex:1;min-width:0}
.notif-title{font-weight:700;font-size:.85rem}
.notif-msg{font-size:.8rem;opacity:.88;margin-top:2px}
.notif-close{background:none;border:none;color:inherit;opacity:.7;cursor:pointer;font-size:.9rem;flex-shrink:0;padding:0}
.notif-close:hover{opacity:1}

@media(max-width:700px){
  .form-grid{grid-template-columns:1fr}
  .page-header{padding:20px}
  .hdr-left h1{font-size:1.25rem}
  .cal-grid{grid-template-columns:50px repeat(5,minmax(100px,1fr))}
}
</style>
</head>
<body>
<?php require_once 'nav.php'; ?>

<div class="page">

  <!-- ── Page Header ─────────────────────────────────────── -->
  <div class="page-header">
    <div class="hdr-left">
      <h1><i class="fas fa-calendar-alt"></i> Lab Schedule</h1>
      <p>Time &amp; resource booking system</p>
    </div>
    <div class="hdr-right">
      <div class="stat-pill">
        <span class="n"><?= $totalBookings ?></span>
        <span class="l">This week</span>
      </div>
      <div class="stat-pill">
        <span class="n"><?= number_format($totalHours, 1) ?></span>
        <span class="l">Hours booked</span>
      </div>
      <div class="stat-pill">
        <span class="n"><?= $uniquePersons ?></span>
        <span class="l">People</span>
      </div>
    </div>
  </div>

  <!-- ── Main card ────────────────────────────────────────── -->
  <div class="card">

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <!-- View toggle -->
        <div class="view-toggle">
          <button class="vt-btn active" id="btn-cal" onclick="switchView('cal')">
            <i class="fas fa-calendar-week"></i> Calendar
          </button>
          <button class="vt-btn" id="btn-list" onclick="switchView('list')">
            <i class="fas fa-list"></i> List
          </button>
        </div>
        <!-- Week nav -->
        <div class="week-nav">
          <button onclick="changeWeek(-1)" title="Previous week"><i class="fas fa-chevron-left"></i></button>
          <span class="week-label" id="weekLabel"></span>
          <button onclick="changeWeek(1)"  title="Next week"><i class="fas fa-chevron-right"></i></button>
          <button class="btn btn-sm btn-outline" onclick="goToday()">Today</button>
        </div>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-outline btn-sm btn-pdf" onclick="exportPDF()" title="Export schedule as PDF">
          <i class="fas fa-file-pdf"></i> Export PDF
        </button>
        <button class="btn btn-primary btn-sm" onclick="openBookingModal()">
          <i class="fas fa-plus"></i> New Booking
        </button>
      </div>
    </div>

    <!-- Legend -->
    <div class="legend">
      <div class="legend-item"><div class="legend-dot ld-research"></div> Research</div>
      <div class="legend-item"><div class="legend-dot ld-class"></div> Classes</div>
      <div class="legend-item"><div class="legend-dot ld-maintenance"></div> Maintenance</div>
      <div class="legend-item"><div class="legend-dot ld-training"></div> Training</div>
      <div class="legend-item"><div class="legend-dot ld-meeting"></div> Meeting</div>
    </div>

    <!-- ── Calendar view ─────────────────────────────────── -->
    <div id="calView">
      <div class="cal-container">
        <div class="cal-grid" id="calGrid">
          <!-- Populated by JS -->
        </div>
      </div>
    </div>

    <!-- ── List view ─────────────────────────────────────── -->
    <div class="list-view" id="listView">
      <div class="list-filters">
        <div class="filter-group">
          <label>From</label>
          <input type="date" class="form-control search-input" id="listFrom" style="min-width:150px">
        </div>
        <div class="filter-group">
          <label>To</label>
          <input type="date" class="form-control search-input" id="listTo" style="min-width:150px">
        </div>
        <div class="filter-group">
          <label>Purpose</label>
          <select class="filter-select" id="listPurpose" onchange="renderList()">
            <option value="">All purposes</option>
            <option value="research">Research</option>
            <option value="class">Classes</option>
            <option value="maintenance">Maintenance</option>
            <option value="training">Training</option>
            <option value="meeting">Meeting</option>
          </select>
        </div>
        <div class="filter-group">
          <label>Person</label>
          <select class="filter-select" id="listPerson" onchange="renderList()">
            <option value="">All people</option>
            <?php foreach ($persons as $p): ?>
              <option value="<?= htmlspecialchars($p, ENT_QUOTES) ?>"><?= htmlspecialchars($p) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group" style="justify-content:flex-end">
          <label>&nbsp;</label>
          <button class="btn btn-primary btn-sm" onclick="renderList()">
            <i class="fas fa-filter"></i> Apply
          </button>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Time</th>
              <th>Title</th>
              <th>Purpose</th>
              <th>Responsible</th>
              <th>Equipment</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="listBody">
            <tr><td colspan="7"><div class="empty-state"><i class="fas fa-calendar-times"></i><p>No bookings found for this period.</p></div></td></tr>
          </tbody>
        </table>
      </div>
      <div class="result-count" id="listCount"></div>
    </div>

  </div><!-- /card -->

</div><!-- /page -->

<!-- ── Booking Modal ──────────────────────────────────────── -->
<div class="modal" id="bookingModal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <div class="modal-head">
      <h2><i class="fas fa-calendar-plus"></i> <span id="modalTitle">New Lab Booking</span></h2>
      <button class="modal-close" onclick="closeModal()" title="Close">&times;</button>
    </div>
    <div class="modal-body">

      <!-- Conflict warning -->
      <div class="conflict-box" id="conflictBox">
        <div class="conflict-box-title">
          <i class="fas fa-exclamation-triangle"></i> Scheduling Conflicts Detected
        </div>
        <div id="conflictList"></div>
      </div>

      <!-- Form -->
      <div class="form-section">Booking Details</div>
      <div class="form-grid" style="margin-bottom:18px">
        <input type="hidden" id="fId">
        <div class="form-group">
          <label class="form-label">Date <span class="req">*</span></label>
          <input type="date" class="form-control" id="fDate" oninput="checkConflicts()">
        </div>
        <div class="form-group">
          <label class="form-label">Purpose <span class="req">*</span></label>
          <select class="form-control" id="fPurpose">
            <option value="">Select purpose</option>
            <option value="research">Research Project</option>
            <option value="class">Class Session</option>
            <option value="maintenance">Equipment Maintenance</option>
            <option value="training">Training Session</option>
            <option value="meeting">Lab Meeting</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Start Time <span class="req">*</span></label>
          <input type="time" class="form-control" id="fStart" step="900" oninput="checkConflicts()">
        </div>
        <div class="form-group">
          <label class="form-label">End Time <span class="req">*</span></label>
          <input type="time" class="form-control" id="fEnd" step="900" oninput="checkConflicts()">
        </div>
        <div class="form-group">
          <label class="form-label">Responsible Person <span class="req">*</span></label>
          <input type="text" class="form-control" id="fPerson" placeholder="Full name" maxlength="150">
        </div>
        <div class="form-group">
          <label class="form-label">Contact Email <span class="req">*</span></label>
          <input type="email" class="form-control" id="fEmail" placeholder="name@school.ac.ug">
        </div>
        <div class="form-group full">
          <label class="form-label">Title / Description <span class="req">*</span></label>
          <input type="text" class="form-control" id="fTitle" placeholder="Brief description of the booking" maxlength="255">
        </div>
        <div class="form-group full">
          <label class="form-label">Additional Notes</label>
          <textarea class="form-control" id="fNotes" rows="3" placeholder="Special requirements, safety notes, group size…"></textarea>
        </div>
      </div>

      <!-- Equipment -->
      <div class="form-section">Equipment Needed</div>
      <div class="eq-grid" id="equipmentGrid">
        <?php if (empty($equipmentList)): ?>
          <p style="font-size:.82rem;color:#888;grid-column:1/-1">
            No active equipment found. Add equipment via the Lab Equipment module.
          </p>
        <?php else: ?>
          <?php foreach ($equipmentList as $eq): ?>
            <label class="eq-option" id="eq-lbl-<?= (int)$eq['id'] ?>">
              <input type="checkbox" value="<?= (int)$eq['id'] ?>" class="eq-check"
                onchange="this.closest('.eq-option').classList.toggle('checked',this.checked)">
              <?= htmlspecialchars($eq['name']) ?>
            </label>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
      <button class="btn btn-danger" id="deleteBtn" style="display:none" onclick="confirmDelete()">
        <i class="fas fa-trash"></i> Delete
      </button>
      <button class="btn btn-primary" onclick="saveBooking()">
        <i class="fas fa-save"></i> Save Booking
      </button>
    </div>
  </div>
</div>

<!-- ── Confirm dialog ─────────────────────────────────────── -->
<div class="dialog" id="confirmDlg" onclick="if(event.target===this)closeDlg()">
  <div class="dialog-box">
    <div class="dialog-head danger">
      <i class="fas fa-trash-alt"></i>
      <span>Delete Booking</span>
    </div>
    <div class="dialog-body" id="dlgBody">Are you sure you want to delete this booking? This cannot be undone.</div>
    <div class="dialog-actions">
      <button class="btn btn-secondary" onclick="closeDlg()">Cancel</button>
      <button class="btn btn-danger" onclick="doDelete()">Yes, delete</button>
    </div>
  </div>
</div>

<!-- ── Notification stack ──────────────────────────────────── -->
<div id="notif-stack"></div>

<script>
'use strict';
/* ── Data from PHP ──────────────────────────────────────── */
const CSRF        = <?= json_encode($csrf, JSON_HEX_QUOT | JSON_HEX_TAG) ?>;
const WEEK_START  = <?= json_encode($currentWeek, JSON_HEX_QUOT | JSON_HEX_TAG) ?>;
const EQUIPMENT   = <?= json_encode($equipmentList, JSON_HEX_QUOT | JSON_HEX_TAG) ?>;
let   bookings    = <?= json_encode($weekBookings, JSON_HEX_QUOT | JSON_HEX_TAG) ?>;

/* ── State ──────────────────────────────────────────────── */
let currentWeekStr = WEEK_START;   // 'YYYY-MM-DD' (always a Monday)
let currentView    = 'cal';
let pendingDeleteId = 0;

/* ── Init ───────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    updateWeekLabel();
    renderCalendar();
    initListDates();
    renderList();
});

/* ─────────────────────────────────────────────────────────
   WEEK NAVIGATION
──────────────────────────────────────────────────────── */
function changeWeek(dir) {
    const d = new Date(currentWeekStr + 'T00:00:00');
    d.setDate(d.getDate() + dir * 7);
    currentWeekStr = fmtDateISO(d);
    updateWeekLabel();
    loadWeek();
}
function goToday() {
    const now = new Date();
    const mon = new Date(now);
    const dow = now.getDay(); // 0=Sun
    mon.setDate(now.getDate() - (dow === 0 ? 6 : dow - 1));
    currentWeekStr = fmtDateISO(mon);
    updateWeekLabel();
    loadWeek();
}
function updateWeekLabel() {
    const mon = new Date(currentWeekStr + 'T00:00:00');
    const fri = new Date(mon); fri.setDate(mon.getDate() + 4);
    const opts = { day: 'numeric', month: 'short' };
    const label = mon.toLocaleDateString('en-UG', opts) + ' – ' + fri.toLocaleDateString('en-UG', { ...opts, year: 'numeric' });
    document.getElementById('weekLabel').textContent = label;
}
async function loadWeek() {
    const fd = new FormData();
    fd.append('action', 'get_bookings');
    fd.append('week', currentWeekStr);
    try {
        const res = await fetch('lab_schedule.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            bookings = data.bookings;
            renderCalendar();
            renderList();
        } else {
            notify('Error', data.error || 'Failed to load bookings.', 'error');
        }
    } catch (e) {
        notify('Error', 'Network error. Please try again.', 'error');
    }
}

/* ─────────────────────────────────────────────────────────
   VIEW SWITCHING
──────────────────────────────────────────────────────── */
function switchView(v) {
    currentView = v;
    document.getElementById('calView').style.display  = v === 'cal'  ? '' : 'none';
    document.getElementById('listView').style.display = v === 'list' ? 'block' : 'none';
    document.getElementById('btn-cal').classList.toggle('active',  v === 'cal');
    document.getElementById('btn-list').classList.toggle('active', v === 'list');
}

/* ─────────────────────────────────────────────────────────
   CALENDAR RENDER
──────────────────────────────────────────────────────── */
const DAYS      = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
const START_H   = 7;   // 07:00
const END_H     = 19;  // 19:00
const SLOT_H    = 60;  // px per hour

function renderCalendar() {
    const grid = document.getElementById('calGrid');
    grid.innerHTML = '';

    // Row 1: time-col placeholder + day headers
    const timeCorner = el('div');
    timeCorner.className = 'cal-header-cell cal-time-col';
    grid.appendChild(timeCorner);

    const todayISO = fmtDateISO(new Date());

    DAYS.forEach((day, i) => {
        const d = new Date(currentWeekStr + 'T00:00:00');
        d.setDate(d.getDate() + i);
        const iso = fmtDateISO(d);
        const isToday = iso === todayISO;
        const cell = el('div');
        cell.className = 'cal-header-cell' + (isToday ? ' today' : '');
        cell.innerHTML = `<div class="day-name">${day.substring(0,3)}</div>
                          <div class="day-date">${d.getDate()}</div>`;
        grid.appendChild(cell);
    });

    // Remaining rows: time labels + day columns
    const totalSlots = (END_H - START_H); // one row per hour
    const totalH = totalSlots * SLOT_H;

    // Create day columns (positioned containers)
    const dayCols = [];
    DAYS.forEach((_, i) => {
        const d = new Date(currentWeekStr + 'T00:00:00');
        d.setDate(d.getDate() + i);
        const iso = fmtDateISO(d);
        const isToday = iso === fmtDateISO(new Date());

        const dayDiv = el('div');
        dayDiv.className = 'cal-day-col' + (isToday ? ' today' : '');
        dayDiv.style.gridRow    = '2 / ' + (totalSlots + 3);
        dayDiv.style.gridColumn = (i + 2) + '';
        dayDiv.style.height     = totalH + 'px';
        dayDiv.dataset.date     = iso;
        dayCols.push({ col: dayDiv, iso });
    });

    // Time label cells (one per hour) - column 1, rows 2..end
    for (let h = START_H; h < END_H; h++) {
        const timeCell = el('div');
        timeCell.className = 'cal-time-label';
        timeCell.textContent = h.toString().padStart(2,'0') + ':00';
        grid.appendChild(timeCell);

        // Add a placeholder for each day column (CSS grid needs cells)
        // Actually we'll use absolute positioning within the day cols
        // so just add empty cells for each day
        DAYS.forEach(() => {
            const ph = el('div'); // placeholder cell
            ph.style.height = SLOT_H + 'px';
            ph.style.borderBottom = 'none';
            // not appended — day cols are positioned absolutely within CSS grid rows
        });
    }

    // Since CSS grid doesn't easily support absolute positioning across cells,
    // use a single row for all time cells + a single row for day columns approach.
    // Rebuild approach: single-column time strip + flex day strip.
    buildCalFlexLayout(grid, dayCols, todayISO);
}

function buildCalFlexLayout(grid, dayCols, todayISO) {
    // Clear and rebuild with a proper flex layout inside a wrapper
    grid.style.display = 'block';
    grid.innerHTML = '';

    const wrapper = el('div');
    wrapper.style.cssText = 'display:flex;overflow-x:auto';

    // Time strip
    const timeStrip = el('div');
    timeStrip.style.cssText = 'flex-shrink:0;width:64px;border-right:1px solid #e8ede9;padding-top:50px';
    for (let h = START_H; h < END_H; h++) {
        const lbl = el('div');
        lbl.style.cssText = `height:${SLOT_H}px;font-size:.72rem;color:#999;display:flex;align-items:flex-start;padding:4px 6px 0 0;justify-content:flex-end;border-bottom:1px solid #f0f4f1`;
        lbl.textContent = h.toString().padStart(2,'0') + ':00';
        timeStrip.appendChild(lbl);
    }

    // Days strip
    const daysStrip = el('div');
    daysStrip.style.cssText = 'flex:1;display:grid;grid-template-columns:repeat(5,1fr);min-width:480px';

    const totalH = (END_H - START_H) * SLOT_H;
    const todayFull = fmtDateISO(new Date());

    DAYS.forEach((day, i) => {
        const d = new Date(currentWeekStr + 'T00:00:00');
        d.setDate(d.getDate() + i);
        const iso = fmtDateISO(d);
        const isToday = iso === todayFull;

        const colWrap = el('div');
        colWrap.style.cssText = 'display:flex;flex-direction:column;border-right:1px solid #e8ede9';

        // Day header
        const hdr = el('div');
        hdr.className = 'cal-header-cell' + (isToday ? ' today' : '');
        hdr.style.borderBottom = '2px solid #e8ede9';
        hdr.innerHTML = `<div class="day-name">${day.substring(0,3)}</div><div class="day-date">${d.getDate()}</div>`;

        // Day column body (relative, for absolute booking blocks)
        const dayBody = el('div');
        dayBody.style.cssText = `position:relative;height:${totalH}px;background:repeating-linear-gradient(180deg,transparent 0,transparent ${SLOT_H-1}px,#f0f4f1 ${SLOT_H-1}px,#f0f4f1 ${SLOT_H}px)`;
        if (isToday) {
            dayBody.style.background = `repeating-linear-gradient(180deg,#f5fbf5 0,#f5fbf5 ${SLOT_H-1}px,#d8edd8 ${SLOT_H-1}px,#d8edd8 ${SLOT_H}px)`;
        }

        // Filter bookings for this day
        const dayBookings = bookings.filter(b => b.booking_date === iso);

        if (dayBookings.length === 0) {
            const emp = el('div');
            emp.className = 'cal-empty';
            emp.innerHTML = '<i class="fas fa-calendar-day" style="opacity:.2;font-size:1.5rem"></i><br>No bookings';
            dayBody.appendChild(emp);
        } else {
            dayBookings.forEach(b => {
                const block = makeBookingBlock(b, totalH);
                if (block) dayBody.appendChild(block);
            });
        }

        colWrap.appendChild(hdr);
        colWrap.appendChild(dayBody);
        daysStrip.appendChild(colWrap);
    });

    wrapper.appendChild(timeStrip);
    wrapper.appendChild(daysStrip);
    grid.appendChild(wrapper);
}

function makeBookingBlock(b, totalH) {
    const [sh, sm] = parseTime(b.start_time);
    const [eh, em] = parseTime(b.end_time);
    if (sh === null) return null;

    const startMin = (sh - START_H) * 60 + sm;
    const durMin   = (eh * 60 + em) - (sh * 60 + sm);
    if (startMin < 0 || durMin <= 0) return null;

    const topPx  = (startMin / 60) * SLOT_H;
    const hPx    = Math.max((durMin / 60) * SLOT_H - 2, 18);

    const div = el('div');
    div.className = `booking-block bb-${b.purpose}`;
    div.style.top    = topPx + 'px';
    div.style.height = hPx + 'px';

    const startFmt = fmtTime(b.start_time);
    const endFmt   = fmtTime(b.end_time);

    div.innerHTML = `<div class="bb-title">${esc(b.title)}</div>
                     <div class="bb-time">${startFmt}–${endFmt}</div>
                     ${hPx > 36 ? `<div class="bb-person">${esc(b.responsible_person)}</div>` : ''}`;
    div.title = `${b.title}\n${startFmt}–${endFmt}\n${b.responsible_person}`;
    div.onclick = () => openBookingModal(b);
    return div;
}

/* ─────────────────────────────────────────────────────────
   LIST VIEW
──────────────────────────────────────────────────────── */
function initListDates() {
    // Set list date range to current week
    const mon = new Date(currentWeekStr + 'T00:00:00');
    const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
    document.getElementById('listFrom').value = fmtDateISO(mon);
    document.getElementById('listTo').value   = fmtDateISO(sun);
}

function renderList() {
    const from    = document.getElementById('listFrom').value;
    const to      = document.getElementById('listTo').value;
    const purpose = document.getElementById('listPurpose').value;
    const person  = document.getElementById('listPerson').value;

    let filtered = bookings.filter(b => {
        if (from && b.booking_date < from) return false;
        if (to   && b.booking_date > to)   return false;
        if (purpose && b.purpose !== purpose) return false;
        if (person  && b.responsible_person !== person) return false;
        return true;
    });

    const tbody = document.getElementById('listBody');
    const count = document.getElementById('listCount');

    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state"><i class="fas fa-calendar-times"></i><p>No bookings match the current filters.</p></div></td></tr>`;
        count.textContent = '';
        return;
    }

    tbody.innerHTML = filtered.map(b => `
      <tr>
        <td>${fmtDate(b.booking_date)}</td>
        <td style="white-space:nowrap">${fmtTime(b.start_time)} – ${fmtTime(b.end_time)}</td>
        <td style="font-weight:600">${esc(b.title)}</td>
        <td><span class="badge badge-${b.purpose}">${ucf(b.purpose)}</span></td>
        <td>${esc(b.responsible_person)}</td>
        <td style="font-size:.8rem;color:#666">${esc(b.equipment_names || '—')}</td>
        <td>
          <div class="action-cell">
            <button class="btn-icon bi-edit" title="Edit" onclick='openBookingModal(${JSON.stringify(b)})'><i class="fas fa-edit"></i></button>
            <button class="btn-icon bi-delete" title="Delete" onclick="promptDelete(${b.id},'${esc(b.title).replace(/'/g,'')}')"><i class="fas fa-trash"></i></button>
          </div>
        </td>
      </tr>
    `).join('');

    count.textContent = `Showing ${filtered.length} booking${filtered.length !== 1 ? 's' : ''}`;
}

/* ─────────────────────────────────────────────────────────
   BOOKING MODAL
──────────────────────────────────────────────────────── */
function openBookingModal(booking = null) {
    clearForm();
    if (booking) {
        document.getElementById('modalTitle').textContent = 'Edit Booking';
        document.getElementById('fId').value      = booking.id;
        document.getElementById('fDate').value    = booking.booking_date;
        document.getElementById('fStart').value   = booking.start_time.substring(0,5);
        document.getElementById('fEnd').value     = booking.end_time.substring(0,5);
        document.getElementById('fPurpose').value = booking.purpose;
        document.getElementById('fPerson').value  = booking.responsible_person;
        document.getElementById('fEmail').value   = booking.contact_email;
        document.getElementById('fTitle').value   = booking.title;
        document.getElementById('fNotes').value   = booking.notes || '';
        document.getElementById('deleteBtn').style.display = '';
        // Tick equipment checkboxes
        const ids = booking.equipment_id_list || [];
        document.querySelectorAll('.eq-check').forEach(cb => {
            const checked = ids.includes(parseInt(cb.value));
            cb.checked = checked;
            cb.closest('.eq-option').classList.toggle('checked', checked);
        });
    } else {
        document.getElementById('modalTitle').textContent = 'New Lab Booking';
        document.getElementById('fDate').value = fmtDateISO(new Date());
        document.getElementById('deleteBtn').style.display = 'none';
    }
    document.getElementById('bookingModal').classList.add('active');
}

function closeModal() {
    document.getElementById('bookingModal').classList.remove('active');
    document.getElementById('conflictBox').classList.remove('visible');
}

function clearForm() {
    ['fId','fDate','fStart','fEnd','fPurpose','fPerson','fEmail','fTitle','fNotes'].forEach(id => {
        document.getElementById(id).value = '';
        document.getElementById(id).classList.remove('error');
    });
    document.querySelectorAll('.eq-check').forEach(cb => {
        cb.checked = false;
        cb.closest('.eq-option').classList.remove('checked');
    });
    document.getElementById('conflictBox').classList.remove('visible');
}

/* ── Conflict check ──────────────────────────────────────── */
let conflictTimer = null;
function checkConflicts() {
    clearTimeout(conflictTimer);
    conflictTimer = setTimeout(doConflictCheck, 400);
}
async function doConflictCheck() {
    const date  = document.getElementById('fDate').value;
    const start = document.getElementById('fStart').value;
    const end   = document.getElementById('fEnd').value;
    const id    = document.getElementById('fId').value || '0';
    if (!date || !start || !end || start >= end) return;

    const fd = new FormData();
    fd.append('action', 'check_conflicts');
    fd.append('booking_date', date);
    fd.append('start_time', start);
    fd.append('end_time', end);
    fd.append('exclude_id', id);

    try {
        const res  = await fetch('lab_schedule.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) return;

        const box  = document.getElementById('conflictBox');
        const list = document.getElementById('conflictList');
        if (data.conflicts.length > 0) {
            list.innerHTML = data.conflicts.map(c =>
                `<div class="conflict-item"><i class="fas fa-clock" style="margin-right:5px;color:#e65100"></i>
                 <strong>${esc(c.title)}</strong> — ${fmtTime(c.start_time)} to ${fmtTime(c.end_time)}
                 (${esc(c.responsible_person)})</div>`
            ).join('');
            box.classList.add('visible');
        } else {
            box.classList.remove('visible');
        }
    } catch(e) { /* silently ignore conflict check network errors */ }
}

/* ── Save booking ────────────────────────────────────────── */
async function saveBooking() {
    const id      = document.getElementById('fId').value;
    const date    = document.getElementById('fDate').value;
    const start   = document.getElementById('fStart').value;
    const end     = document.getElementById('fEnd').value;
    const purpose = document.getElementById('fPurpose').value;
    const person  = document.getElementById('fPerson').value.trim();
    const email   = document.getElementById('fEmail').value.trim();
    const title   = document.getElementById('fTitle').value.trim();
    const notes   = document.getElementById('fNotes').value.trim();

    // Client-side validation
    let valid = true;
    const req = { fDate:date, fStart:start, fEnd:end, fPurpose:purpose, fPerson:person, fEmail:email, fTitle:title };
    Object.entries(req).forEach(([fieldId, val]) => {
        const el2 = document.getElementById(fieldId);
        if (!val) { el2.classList.add('error'); valid = false; }
        else        el2.classList.remove('error');
    });
    if (!valid) { notify('Validation', 'Please fill in all required fields.', 'warning'); return; }
    if (start >= end) { notify('Time Error', 'End time must be after start time.', 'warning'); return; }

    const eqIds = [...document.querySelectorAll('.eq-check:checked')].map(cb => cb.value);

    const fd = new FormData();
    fd.append('action', 'save_booking');
    fd.append('csrf_token', CSRF);
    fd.append('id',       id);
    fd.append('booking_date', date);
    fd.append('start_time',   start);
    fd.append('end_time',     end);
    fd.append('purpose',  purpose);
    fd.append('responsible_person', person);
    fd.append('contact_email', email);
    fd.append('title',    title);
    fd.append('notes',    notes);
    eqIds.forEach(eid => fd.append('equipment_ids[]', eid));

    const saveBtn = document.querySelector('.modal-footer .btn-primary');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    try {
        const res  = await fetch('lab_schedule.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            notify('Saved', data.message, 'success');
            closeModal();
            await loadWeek();
        } else {
            notify('Error', data.error, 'error');
        }
    } catch (e) {
        notify('Error', 'Network error. Please try again.', 'error');
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Booking';
    }
}

/* ─────────────────────────────────────────────────────────
   DELETE
──────────────────────────────────────────────────────── */
function confirmDelete() {
    pendingDeleteId = parseInt(document.getElementById('fId').value);
    const title = document.getElementById('fTitle').value;
    document.getElementById('dlgBody').innerHTML =
        `Are you sure you want to delete <strong>${esc(title)}</strong>? This cannot be undone.`;
    document.getElementById('confirmDlg').classList.add('active');
}
function promptDelete(id, title) {
    pendingDeleteId = id;
    document.getElementById('dlgBody').innerHTML =
        `Are you sure you want to delete <strong>${esc(title)}</strong>? This cannot be undone.`;
    document.getElementById('confirmDlg').classList.add('active');
}
function closeDlg() {
    document.getElementById('confirmDlg').classList.remove('active');
    pendingDeleteId = 0;
}
async function doDelete() {
    if (!pendingDeleteId) return;

    // 1. Capture the ID into a local variable before it gets reset
    const idToDelete = pendingDeleteId;

    // 2. Now it's safe to close the dialog and reset the global variable
    closeDlg();

    const fd = new FormData();
    fd.append('action', 'delete_booking');
    fd.append('csrf_token', CSRF);
    fd.append('id', idToDelete); // 3. Use the local variable here

    try {
        const res  = await fetch('lab_schedule.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            notify('Deleted', data.message, 'success');
            closeModal();
            await loadWeek();
        } else {
            notify('Error', data.error, 'error');
        }
    } catch (e) {
        notify('Error', 'Network error. Please try again.', 'error');
    }
}
/* ─────────────────────────────────────────────────────────
   EXPORT PDF
──────────────────────────────────────────────────────── */
function exportPDF() {
    if (typeof window.jspdf === 'undefined') {
        notify('PDF', 'PDF library still loading. Please wait a moment.', 'warning');
        return;
    }
    if (!bookings.length) {
        notify('PDF', 'No bookings to export for this week.', 'warning');
        return;
    }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');

    const mon = new Date(currentWeekStr + 'T00:00:00');
    const fri = new Date(mon); fri.setDate(mon.getDate() + 4);
    const hdr = `Lab Schedule: ${fmtDate(currentWeekStr)} – ${fmtDate(fmtDateISO(fri))}`;

    doc.setFontSize(16); doc.setTextColor(27, 94, 32);
    doc.text('SchoolPilot — Lab Schedule', 14, 16);
    doc.setFontSize(10); doc.setTextColor(80);
    doc.text(hdr, 14, 23);
    doc.text('Generated: ' + new Date().toLocaleDateString('en-UG'), 14, 29);

    doc.autoTable({
        head: [['Date','Start','End','Purpose','Title','Person','Equipment']],
        body: bookings.map(b => [
            fmtDate(b.booking_date),
            fmtTime(b.start_time),
            fmtTime(b.end_time),
            ucf(b.purpose),
            b.title,
            b.responsible_person,
            b.equipment_names || '—'
        ]),
        startY: 35,
        theme: 'grid',
        headStyles: { fillColor: [56, 142, 60], fontSize: 8, fontStyle: 'bold' },
        bodyStyles: { fontSize: 8 },
        alternateRowStyles: { fillColor: [232, 245, 233] },
        margin: { left: 12, right: 12 }
    });

    doc.save(`lab-schedule-${currentWeekStr}.pdf`);
    notify('Exported', 'PDF schedule downloaded.', 'success');
}

/* ─────────────────────────────────────────────────────────
   NOTIFICATIONS
──────────────────────────────────────────────────────── */
function notify(title, msg, type = 'success', dur = 4500) {
    const icons = { success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
    const n = document.createElement('div');
    n.className = `notif ${type}`;
    n.innerHTML = `<i class="fas ${icons[type] || icons.info} notif-icon"></i>
      <div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div>
      <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('notif-stack').prepend(n);
    setTimeout(() => {
        n.style.opacity = '0';
        n.style.transform = 'translateX(30px)';
        n.style.transition = '.3s';
        setTimeout(() => n.remove(), 320);
    }, dur);
}

/* ─────────────────────────────────────────────────────────
   UTILITIES
──────────────────────────────────────────────────────── */
function el(tag) { return document.createElement(tag); }

function esc(v) {
    return String(v || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function ucf(v) { return v ? v.charAt(0).toUpperCase() + v.slice(1) : ''; }

function fmtDateISO(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2,'0');
    const day = String(d.getDate()).padStart(2,'0');
    return `${y}-${m}-${day}`;
}
function fmtDate(iso) {
    if (!iso) return '—';
    try { return new Date(iso + 'T00:00:00').toLocaleDateString('en-UG', { day:'2-digit', month:'short', year:'numeric' }); }
    catch(_) { return iso; }
}
function fmtTime(t) {
    if (!t) return '';
    const [h, m] = t.split(':');
    const hour = parseInt(h);
    const ampm = hour >= 12 ? 'PM' : 'AM';
    const h12  = hour % 12 || 12;
    return `${h12}:${m} ${ampm}`;
}
function parseTime(t) {
    if (!t) return [null, null];
    const parts = t.split(':');
    return [parseInt(parts[0]), parseInt(parts[1])];
}
</script>
</body>
</html>