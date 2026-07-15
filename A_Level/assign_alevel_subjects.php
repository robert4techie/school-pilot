<?php
/**
 * assign_alevel_subjects.php
 * SchoolPilot — Assign students to A-Level subjects.
 *
 * - Styled to match sel_add_marks.php / add_marks.php design system
 * - Excludes General Paper (subj_abbr = 'GP') from the subject list
 * - Auto-saves assignments on checkbox change via AJAX
 * - Bulk assign / unassign all visible students
 * - Live search filter with pagination
 * - Uses student_alevel_subjects table
 */

require_once '../auth.php';
require_once '../conn.php';

// ── CSRF token ─────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$current_user = $_SESSION['username'] ?? 'System';

// ── Params ─────────────────────────────────────────────────────────────────────
$availableStreams  = ['Arts', 'Sciences'];
$availableClasses = ['Senior Five', 'Senior Six'];

$class   = trim($_GET['class']   ?? '');
$subject = trim($_GET['subject'] ?? '');
$streams = isset($_GET['streams']) && is_array($_GET['streams'])
           ? array_values(array_filter($_GET['streams'], fn($s) => in_array($s, $availableStreams, true)))
           : [];

$safe = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// ── Data functions ─────────────────────────────────────────────────────────────

/** All A-level subjects excluding General Paper */
function getALevelSubjects(mysqli $conn): array
{
    $sql  = "SELECT subj_name FROM subjects
             WHERE (level LIKE '%A%') AND subj_abbr != 'GP'
             ORDER BY subj_name";
    $res  = mysqli_query($conn, $sql);
    $out  = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $out[] = $row['subj_name'];
        }
    }
    return $out;
}

/** Students in the chosen class + streams */
function getStudentsForClass(mysqli $conn, string $class, array $streams): array
{
    if (empty($streams)) return [];

    $placeholders = implode(',', array_fill(0, count($streams), '?'));
    $types        = 's' . str_repeat('s', count($streams));
    $params       = array_merge([$class], $streams);

    $sql  = "SELECT student_id, first_name, last_name, stream, subject_combination
             FROM   students
             WHERE  current_class = ?
               AND  stream IN ({$placeholders})
               AND  status = 'Active'
             ORDER  BY stream, last_name, first_name";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $out    = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $out[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $out;
}

/** student_ids already assigned to this class+subject */
function getAssignedStudents(mysqli $conn, string $class, string $subject): array
{
    $sql  = "SELECT student_id FROM student_alevel_subjects
             WHERE class = ? AND subject = ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, 'ss', $class, $subject);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $out    = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $out[] = $row['student_id'];
    }
    mysqli_stmt_close($stmt);
    return $out;
}

// ── Fetch data ─────────────────────────────────────────────────────────────────
$allSubjects      = getALevelSubjects($conn);
$students         = [];
$assignedStudents = [];

$formSubmitted = !empty($class) && !empty($subject) && !empty($streams);
if ($formSubmitted) {
    $students         = getStudentsForClass($conn, $class, $streams);
    $assignedStudents = getAssignedStudents($conn, $class, $subject);
}

$totalStudents    = count($students);
$assignedCount    = count(array_filter($students, fn($s) => in_array($s['student_id'], $assignedStudents)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign A-Level Subjects &mdash; SchoolPilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
<style>
/* ══════════════════════════════════════════════════════════════════════════════
   Design tokens — identical to sel_add_marks / add_marks
   ══════════════════════════════════════════════════════════════════════════════ */
:root {
    --g900:#1b5e20; --g800:#2e7d32; --g700:#388e3c; --g600:#43a047;
    --g400:#66bb6a; --g100:#e8f5e9; --g50:#f1f8f1;
    --gray-50:#f8faf8; --gray-100:#f5f7fa; --gray-200:#e8ede9;
    --gray-300:#d1dbd2; --gray-400:#c8d4c9; --gray-600:#6b7c6d; --gray-900:#1e2922;
    --text:#263329;
    --danger:#d32f2f; --danger-bg:#fff8f8;
    --radius:8px; --radius-lg:12px;
    --shadow-sm:0 1px 3px rgba(0,0,0,.07);
    --shadow:0 2px 10px rgba(0,0,0,.09);
    --shadow-lg:0 8px 32px rgba(0,0,0,.14);
    --transition:.2s ease;
    --field-h:42px;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",system-ui,-apple-system,sans-serif;background:#eef2ef;color:var(--text);min-height:100vh}

/* ── Layout ── */
.page{max-width:100%;margin:0 auto;padding:24px 20px 72px}

/* ── Page header ── */
.page-header{
    background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);
    border-radius:var(--radius-lg);padding:26px 32px;
    margin-top:52px;margin-bottom:20px;
    box-shadow:var(--shadow-lg);
    display:flex;align-items:flex-start;justify-content:space-between;gap:16px;
}
.page-header-text h1{color:#fff;font-size:1.45rem;font-weight:700;line-height:1.25;display:flex;align-items:center;gap:10px}
.page-header-text h1 i{opacity:.82}
.page-header-text p{color:rgba(255,255,255,.72);font-size:.84rem;margin-top:6px}

/* ── Card ── */
.card{
    background:#fff;border-radius:var(--radius-lg);
    box-shadow:var(--shadow);border:1px solid rgba(0,0,0,.05);overflow:visible;
    margin-bottom:20px;
}
.card-header{
    display:flex;align-items:center;gap:12px;
    padding:20px 32px 16px;border-bottom:1px solid var(--gray-200);
}
.card-header-icon{
    width:36px;height:36px;background:var(--g100);border-radius:9px;
    display:flex;align-items:center;justify-content:center;color:var(--g700);font-size:.92rem;flex-shrink:0;
}
.card-header-title{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--gray-600)}
.card-header-sub{font-size:.8rem;color:var(--gray-600);margin-top:1px}
.card-body{padding:28px 32px 32px}

/* ── Form grid ── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;column-gap:20px}
.form-group{display:flex;flex-direction:column;margin-bottom:20px}
.form-group.full{grid-column:1 / -1}

.form-label{
    display:block;font-size:.82rem;font-weight:600;
    color:var(--gray-600);margin-bottom:6px;line-height:1.3;
    user-select:none;letter-spacing:.02em;
}
.form-label .req{color:var(--danger);margin-left:2px;font-weight:700}
.form-field{position:relative;min-width:0}

/* ── Native selects ── */
.form-control{
    width:100%;height:var(--field-h);padding:0 36px 0 13px;
    border:1.5px solid var(--gray-300);border-radius:var(--radius);
    font-size:.9rem;font-family:inherit;background:#fff;color:var(--text);
    transition:border-color var(--transition),box-shadow var(--transition);
    appearance:none;-webkit-appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236b7c6d' stroke-width='1.6' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 13px center;cursor:pointer;
}
.form-control:hover:not(:focus):not(.is-invalid){border-color:var(--gray-600)}
.form-control:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.16)}
.form-control.is-invalid{border-color:var(--danger);background-color:var(--danger-bg)}

.error-msg{
    font-size:.78rem;color:var(--danger);font-weight:500;margin-top:6px;
    display:none;align-items:center;gap:5px;
}
.error-msg i{font-size:.7rem;flex-shrink:0}

/* ══ Custom Stream multi-select ════════════════════════════════════════════════ */
.stream-selector{position:relative}
.stream-trigger{
    width:100%;height:var(--field-h);padding:0 38px 0 13px;
    border:1.5px solid var(--gray-300);border-radius:var(--radius);
    background:#fff;display:flex;align-items:center;gap:6px;
    cursor:pointer;user-select:none;position:relative;
    transition:border-color var(--transition),box-shadow var(--transition),border-radius var(--transition);
}
.stream-trigger:hover{border-color:var(--gray-600)}
.stream-selector.open .stream-trigger{
    border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.16);
    border-bottom-left-radius:0;border-bottom-right-radius:0;
}
.stream-selector.invalid .stream-trigger{border-color:var(--danger);background-color:var(--danger-bg)}
.stream-arrow{
    position:absolute;right:13px;top:50%;transform:translateY(-50%);
    color:var(--gray-600);font-size:.72rem;pointer-events:none;
    transition:transform .28s cubic-bezier(.4,0,.2,1);
}
.stream-selector.open .stream-arrow{transform:translateY(-50%) rotate(180deg)}
.stream-trigger-content{display:flex;align-items:center;gap:5px;flex:1;min-width:0;overflow:hidden}
.stream-placeholder{font-size:.9rem;color:#aab8ac;white-space:nowrap}
.stream-tag{
    display:inline-flex;align-items:center;gap:4px;
    background:var(--g100);color:var(--g800);border:1px solid #b3dab9;
    border-radius:5px;padding:2px 9px;font-size:.78rem;font-weight:600;
    white-space:nowrap;flex-shrink:0;
}
.stream-panel{
    position:absolute;top:calc(var(--field-h) - 1.5px);left:0;right:0;z-index:300;
    background:#fff;border:1.5px solid var(--g600);border-top:1px solid var(--gray-200);
    border-radius:0 0 var(--radius) var(--radius);
    box-shadow:0 14px 36px rgba(0,0,0,.14);
    overflow:hidden;max-height:0;opacity:0;pointer-events:none;
    transition:max-height .3s cubic-bezier(.4,0,.2,1),opacity .22s ease;
}
.stream-selector.open .stream-panel{max-height:400px;opacity:1;pointer-events:auto}
.stream-option{
    display:flex;align-items:center;gap:12px;padding:12px 16px;
    cursor:pointer;font-size:.9rem;color:var(--text);
    border-bottom:1px solid var(--gray-200);
    transition:background var(--transition);position:relative;
}
.stream-option:last-child{border-bottom:none}
.stream-option:hover{background:var(--g50)}
.stream-option.selected{background:linear-gradient(90deg,var(--g800),var(--g700));color:#fff;font-weight:600}
.opt-check{
    width:18px;height:18px;border-radius:4px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    border:2px solid var(--gray-300);background:#fff;
    transition:border-color var(--transition),background var(--transition);
}
.stream-option.selected .opt-check{background:rgba(255,255,255,.25);border-color:rgba(255,255,255,.6)}
.opt-check-icon{font-size:.6rem;color:#fff;opacity:0;transition:opacity .15s ease}
.stream-option.selected .opt-check-icon{opacity:1}
.stream-option input[type="checkbox"]{position:absolute;opacity:0;width:0;height:0;pointer-events:none}
.opt-label{flex:1;pointer-events:none}
.opt-icon{font-size:.82rem;opacity:.55}
.stream-option.selected .opt-icon{opacity:.8}
.stream-actions{display:flex;align-items:center;gap:4px;margin-top:6px;font-size:.8rem}
.stream-actions a{
    color:var(--g700);text-decoration:none;font-weight:600;
    padding:2px 7px;border-radius:4px;
    transition:background var(--transition),color var(--transition);
}
.stream-actions a:hover{background:var(--g100);color:var(--g900)}
.stream-actions .sep{color:var(--gray-400);margin:0 2px}

/* ── Buttons ── */
.divider{border:none;border-top:1px solid var(--gray-200);margin:28px 0 24px}
.btn-row{display:flex;gap:10px;align-items:center}
.btn{
    display:inline-flex;align-items:center;gap:8px;
    padding:0 26px;height:var(--field-h);border-radius:var(--radius);
    font-size:.875rem;font-weight:600;font-family:inherit;cursor:pointer;
    text-decoration:none;border:1.5px solid transparent;white-space:nowrap;
    transition:background var(--transition),border-color var(--transition),
               box-shadow var(--transition),transform .15s ease;
}
.btn-primary{background:var(--g700);color:#fff;border-color:var(--g700)}
.btn-primary:hover:not(:disabled){
    background:var(--g800);border-color:var(--g800);
    box-shadow:0 4px 18px rgba(46,125,50,.35);transform:translateY(-1px);
}
.btn-primary:disabled{opacity:.72;cursor:not-allowed}
.btn-spinner{
    display:none;width:15px;height:15px;
    border:2.5px solid rgba(255,255,255,.35);border-top-color:#fff;
    border-radius:50%;animation:spin .65s linear infinite;flex-shrink:0;
}
.btn.loading .btn-spinner{display:block}
.btn.loading .btn-icon{display:none}
@keyframes spin{to{transform:rotate(360deg)}}

/* ══ Students panel ════════════════════════════════════════════════════════════ */
.students-card .card-header{
    background:linear-gradient(135deg,var(--g900),var(--g700));
    border-radius:var(--radius-lg) var(--radius-lg) 0 0;
    padding:20px 32px;border-bottom:none;
}
.students-card .card-header-icon{background:rgba(255,255,255,.18);color:#fff}
.students-card .card-header-title{color:rgba(255,255,255,.82);letter-spacing:.6px}
.students-card .card-header-sub{color:#fff;font-size:.95rem;font-weight:700}

.counter-badge{
    background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.3);
    font-size:.78rem;font-weight:700;padding:5px 14px;border-radius:20px;white-space:nowrap;
}
.saving-pill{
    display:none;align-items:center;gap:6px;
    background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.3);
    font-size:.75rem;font-weight:600;padding:4px 12px;border-radius:20px;
}
.saving-pill.visible{display:inline-flex}
.saving-pill i{animation:spin .65s linear infinite}

/* ── Toolbar ── */
.toolbar{
    display:flex;align-items:center;gap:12px;flex-wrap:wrap;
    padding:16px 32px;background:var(--g50);border-bottom:1px solid var(--gray-200);
}
.bulk-btn{
    display:inline-flex;align-items:center;gap:7px;
    padding:0 16px;height:36px;border-radius:var(--radius);
    font-size:.8rem;font-weight:600;font-family:inherit;cursor:pointer;
    border:1.5px solid transparent;transition:background var(--transition),transform .12s ease;
    white-space:nowrap;
}
.bulk-btn:hover{transform:translateY(-1px)}
.btn-assign{background:var(--g600);color:#fff;border-color:var(--g600)}
.btn-assign:hover{background:var(--g700);border-color:var(--g700)}
.btn-unassign{background:#f57c00;color:#fff;border-color:#f57c00}
.btn-unassign:hover{background:#e65100;border-color:#e65100}
.search-wrap{
    flex:1;min-width:200px;max-width:360px;
    position:relative;margin-left:auto;
}
.search-wrap i{
    position:absolute;left:12px;top:50%;transform:translateY(-50%);
    color:var(--gray-600);font-size:.82rem;pointer-events:none;
}
.search-input{
    width:100%;height:36px;padding:0 12px 0 34px;
    border:1.5px solid var(--gray-300);border-radius:20px;
    font-size:.85rem;font-family:inherit;color:var(--text);
    transition:border-color var(--transition),box-shadow var(--transition);
}
.search-input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.16)}

/* ── Table ── */
.table-wrap{overflow-x:auto}
table.marks-tbl{width:100%;border-collapse:collapse}
table.marks-tbl thead th{
    background:var(--g800);color:#fff;
    padding:13px 18px;font-size:.78rem;font-weight:700;
    text-transform:uppercase;letter-spacing:.5px;
    position:sticky;top:0;z-index:10;white-space:nowrap;
}
table.marks-tbl thead th:first-child{border-radius:0}
table.marks-tbl tbody td{
    padding:13px 18px;border-bottom:1px solid var(--gray-200);
    font-size:.88rem;vertical-align:middle;
}
table.marks-tbl tbody tr:hover td{background:var(--g50)}
table.marks-tbl tbody tr:last-child td{border-bottom:none}

/* checkbox column */
.col-check{width:48px;text-align:center}
.col-no{width:58px;color:var(--gray-600);font-size:.82rem}
.col-combo{width:160px}
.col-stream{width:110px}
.col-status{width:130px;text-align:center}

/* visual checkbox */
.assign-cb{
    width:18px;height:18px;accent-color:var(--g700);cursor:pointer;
}
.select-all-cb{width:16px;height:16px;accent-color:var(--g700);cursor:pointer}

/* stream badge */
.stream-badge{
    display:inline-flex;align-items:center;padding:3px 12px;
    background:var(--g100);color:var(--g800);border:1px solid #b3dab9;
    border-radius:12px;font-size:.76rem;font-weight:700;
}

/* combo badge */
.combo-badge{
    display:inline-flex;align-items:center;padding:3px 10px;
    background:var(--g100);color:var(--g800);border:1px solid #b3dab9;
    border-radius:12px;font-size:.76rem;font-weight:600;
}
.combo-badge.empty{background:var(--gray-100);color:var(--gray-600);border-color:var(--gray-300)}

/* status pill */
.status-pill{
    display:inline-flex;align-items:center;gap:5px;
    padding:4px 12px;border-radius:20px;font-size:.76rem;font-weight:700;
}
.pill-assigned{background:var(--g100);color:var(--g800)}
.pill-unassigned{background:var(--gray-100);color:var(--gray-600)}

/* empty state */
.empty-state{text-align:center;padding:56px 32px;color:var(--gray-600)}
.empty-state i{font-size:2.8rem;color:var(--g400);opacity:.5;display:block;margin-bottom:14px}
.empty-state p{font-size:.9rem}

/* ── Pagination ── */
.pagination-row{
    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;
    padding:14px 32px;border-top:1px solid var(--gray-200);
}
.page-info{font-size:.8rem;color:var(--gray-600)}
.page-btns{display:flex;gap:5px}
.page-btn{
    min-width:33px;height:33px;padding:0 8px;
    border:1.5px solid var(--gray-300);border-radius:var(--radius);
    background:#fff;color:var(--text);font-size:.8rem;font-weight:600;
    cursor:pointer;transition:all var(--transition);font-family:inherit;
}
.page-btn:hover:not(:disabled){border-color:var(--g600);color:var(--g700)}
.page-btn.active{background:var(--g700);color:#fff;border-color:var(--g700)}
.page-btn:disabled{opacity:.45;cursor:not-allowed}

/* ── Toast ── */
#toast{
    position:fixed;bottom:28px;right:28px;z-index:9999;
    background:var(--g800);color:#fff;
    padding:11px 20px;border-radius:var(--radius);
    font-size:.85rem;font-weight:600;
    box-shadow:0 6px 24px rgba(0,0,0,.2);
    transform:translateY(16px);opacity:0;pointer-events:none;
    transition:opacity .25s ease,transform .25s ease;
}
#toast.show{opacity:1;transform:none}
#toast.toast-error{background:var(--danger)}

/* ── Selection summary bar (shown instead of filter form after submit) ── */
.selection-bar{
    display:flex;align-items:center;flex-wrap:wrap;gap:12px;
    background:#fff;border-radius:var(--radius-lg);
    border:1px solid rgba(0,0,0,.05);
    box-shadow:var(--shadow-sm);
    padding:14px 24px;margin-bottom:20px;
}
.sel-chips{display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex:1}
.sel-chip{
    display:inline-flex;align-items:center;gap:6px;
    background:var(--g100);color:var(--g800);
    border:1px solid #b3dab9;border-radius:6px;
    padding:5px 12px;font-size:.8rem;font-weight:600;
}
.sel-chip i{opacity:.7;font-size:.72rem}
.sel-chip.chip-subject{background:var(--g800);color:#fff;border-color:var(--g800)}
.sel-chip.chip-subject i{opacity:.8}
.sel-divider{width:1px;height:28px;background:var(--gray-200);flex-shrink:0}
.change-btn{
    display:inline-flex;align-items:center;gap:6px;
    padding:0 16px;height:34px;border-radius:var(--radius);
    font-size:.8rem;font-weight:600;font-family:inherit;cursor:pointer;
    background:#fff;color:var(--g700);
    border:1.5px solid var(--g400);
    transition:all var(--transition);white-space:nowrap;text-decoration:none;
}
.change-btn:hover{background:var(--g100);border-color:var(--g700);color:var(--g800)}

/* ── Responsive ── */
@media(max-width:640px){
    .page{padding:16px 12px 60px}
    .page-header{flex-direction:column;padding:20px 22px;margin-top:56px}
    .card-header,.card-body,.toolbar,.pagination-row{padding-left:18px;padding-right:18px}
    .form-grid{grid-template-columns:1fr}
    .form-group.full{grid-column:auto}
    .search-wrap{max-width:100%;margin-left:0}
    .col-combo,.col-status{display:none}
    .selection-bar{padding:12px 16px}
}
</style>
</head>
<body>

<?php require_once '../nav.php'; ?>

<div class="page">

    <!-- ══ Page Header ════════════════════════════════════════════════════════ -->
    <div class="page-header">
        <div class="page-header-text">
            <h1><i class="fas fa-user-tag"></i>Assign A-Level Subjects</h1>
            <p>Select a class, subject and streams, then tick the students who do that subject</p>
        </div>
    </div>

    <?php if (!$formSubmitted): ?>
    <!-- ══ Filter Card (shown only before students are loaded) ═══════════════ -->
    <div class="card">
        <div class="card-header">
            <div class="card-header-icon"><i class="fas fa-sliders-h"></i></div>
            <div>
                <div class="card-header-title">Filter Options</div>
                <div class="card-header-sub">Choose class, subject and stream(s) to load students</div>
            </div>
        </div>
        <div class="card-body">
            <form id="filterForm" method="GET" novalidate autocomplete="off">

                <div class="form-grid">

                    <!-- Class -->
                    <div class="form-group">
                        <label class="form-label" for="class">Class <span class="req">*</span></label>
                        <div class="form-field">
                            <select name="class" id="class" class="form-control" required>
                                <option value="">— Select Class —</option>
                                <?php foreach ($availableClasses as $cls): ?>
                                <option value="<?= $safe($cls) ?>" <?= $class === $cls ? 'selected' : '' ?>>
                                    <?= $safe($cls) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="error-msg" id="classError"><i class="fas fa-exclamation-circle"></i> Please select a class.</p>
                        </div>
                    </div>

                    <!-- Subject -->
                    <div class="form-group">
                        <label class="form-label" for="subject">Subject <span class="req">*</span></label>
                        <div class="form-field">
                            <select name="subject" id="subject" class="form-control" required>
                                <option value="">— Select Subject —</option>
                                <?php foreach ($allSubjects as $subj): ?>
                                <option value="<?= $safe($subj) ?>" <?= $subject === $subj ? 'selected' : '' ?>>
                                    <?= $safe($subj) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="error-msg" id="subjectError"><i class="fas fa-exclamation-circle"></i> Please select a subject.</p>
                        </div>
                    </div>

                    <!-- Streams — full width -->
                    <div class="form-group full">
                        <label class="form-label" id="streamLabel">Stream(s) <span class="req">*</span></label>
                        <div class="form-field">
                            <div class="stream-selector" id="streamSelector"
                                 role="combobox" aria-haspopup="listbox"
                                 aria-expanded="false" aria-labelledby="streamLabel" aria-required="true">

                                <div class="stream-trigger" id="streamTrigger"
                                     tabindex="0" role="button" aria-label="Select streams">
                                    <div class="stream-trigger-content" id="streamTriggerContent">
                                        <span class="stream-placeholder">Select streams…</span>
                                    </div>
                                    <i class="fas fa-chevron-down stream-arrow" aria-hidden="true"></i>
                                </div>

                                <div class="stream-panel" id="streamPanel" role="listbox" aria-multiselectable="true">
                                    <?php
                                    $streamIcons = ['Arts' => 'fa-palette', 'Sciences' => 'fa-flask'];
                                    foreach ($availableStreams as $s):
                                        $sel  = in_array($s, $streams, true);
                                        $icon = $streamIcons[$s] ?? 'fa-circle';
                                    ?>
                                    <div class="stream-option<?= $sel ? ' selected' : '' ?>"
                                         data-value="<?= $safe($s) ?>"
                                         role="option" aria-selected="<?= $sel ? 'true' : 'false' ?>">
                                        <span class="opt-check" aria-hidden="true">
                                            <i class="fas fa-check opt-check-icon"></i>
                                        </span>
                                        <input type="checkbox" name="streams[]"
                                               value="<?= $safe($s) ?>"
                                               id="stream-<?= $safe($s) ?>"
                                               <?= $sel ? 'checked' : '' ?>
                                               tabindex="-1" aria-hidden="true">
                                        <i class="fas <?= $icon ?> opt-icon" aria-hidden="true"></i>
                                        <label class="opt-label" for="stream-<?= $safe($s) ?>"><?= $safe($s) ?></label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="stream-actions">
                                <a href="#" id="selectAllStreams">Select All</a>
                                <span class="sep">|</span>
                                <a href="#" id="deselectAllStreams">Clear</a>
                            </div>

                            <p class="error-msg" id="streamError">
                                <i class="fas fa-exclamation-circle"></i> Please select at least one stream.
                            </p>
                        </div>
                    </div>

                </div><!-- /form-grid -->

                <hr class="divider">

                <div class="btn-row">
                    <button type="submit" class="btn btn-primary" id="loadBtn">
                        <span class="btn-spinner" aria-hidden="true"></span>
                        <i class="fas fa-users btn-icon" aria-hidden="true"></i>
                        <span class="btn-label">Load Students</span>
                    </button>
                </div>

            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($formSubmitted): ?>

    <!-- ══ Selection Summary Bar ════════════════════════════════════════════════════════════ -->
    <div class="selection-bar">
        <div class="sel-chips">
            <span class="sel-chip"><i class="fas fa-school"></i>&nbsp;<?= $safe($class) ?></span>
            <span class="sel-chip chip-subject"><i class="fas fa-book-open"></i>&nbsp;<?= $safe($subject) ?></span>
            <?php foreach ($streams as $s): ?>
            <span class="sel-chip"><i class="fas fa-layer-group"></i>&nbsp;<?= $safe($s) ?></span>
            <?php endforeach; ?>
        </div>
        <div class="sel-divider"></div>
        <a href="assign_alevel_subjects.php" class="change-btn">
            <i class="fas fa-pen"></i> Change Selection
        </a>
    </div>

    <!-- ══ Students Panel ════════════════════════════════════════════════════════════════════════════════ -->
    <div class="card students-card">

        <!-- Header -->
        <div class="card-header" style="border-radius:var(--radius-lg) var(--radius-lg) 0 0">
            <div class="card-header-icon"><i class="fas fa-users"></i></div>
            <div style="flex:1">
                <div class="card-header-title">Students</div>
                <div class="card-header-sub">
                    <?= $safe($class) ?> &mdash; <?= $safe($subject) ?>
                    &nbsp;<span style="opacity:.7;font-weight:400">(<?= implode(', ', array_map($safe, $streams)) ?>)</span>
                </div>
            </div>
            <span class="saving-pill" id="savingPill"><i class="fas fa-circle-notch"></i> Saving…</span>
            <span class="counter-badge">
                <span id="assignedCount"><?= $assignedCount ?></span> / <?= $totalStudents ?> assigned
            </span>
        </div>

        <?php if ($totalStudents > 0): ?>

        <!-- Toolbar -->
        <div class="toolbar">
            <button type="button" class="bulk-btn btn-assign" id="assignAllBtn">
                <i class="fas fa-check-double"></i> Assign All
            </button>
            <button type="button" class="bulk-btn btn-unassign" id="unassignAllBtn">
                <i class="fas fa-times"></i> Unassign All
            </button>
            <div class="search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" class="search-input" id="studentSearch" placeholder="Search students…">
            </div>
        </div>

        <!-- Table -->
        <div class="table-wrap">
            <table class="marks-tbl" id="studentsTable">
                <thead>
                    <tr>
                        <th class="col-check">
                            <input type="checkbox" class="select-all-cb" id="selectAllCb" title="Select / deselect all visible">
                        </th>
                        <th class="col-no">#</th>
                        <th>Student Name</th>
                        <th class="col-combo">Combination</th>
                        <th class="col-stream">Stream</th>
                        <th class="col-status">Status</th>
                    </tr>
                </thead>
                <tbody id="tBody">
                    <?php
                    $i = 1;
                    foreach ($students as $st):
                        $assigned = in_array($st['student_id'], $assignedStudents);
                        $combo    = trim($st['subject_combination'] ?? '');
                    ?>
                    <tr class="student-row"
                        data-student-id="<?= $safe($st['student_id']) ?>"
                        data-name="<?= $safe(strtolower($st['first_name'] . ' ' . $st['last_name'])) ?>"
                        data-stream="<?= $safe(strtolower($st['stream'])) ?>">

                        <td class="col-check">
                            <input type="checkbox"
                                   class="assign-cb"
                                   data-student-id="<?= $safe($st['student_id']) ?>"
                                   data-student-name="<?= $safe($st['first_name'] . ' ' . $st['last_name']) ?>"
                                   data-stream="<?= $safe($st['stream']) ?>"
                                   <?= $assigned ? 'checked' : '' ?>>
                        </td>
                        <td class="col-no"><?= $i++ ?></td>
                        <td>
                            <span style="font-weight:600;color:var(--text)">
                                <?= $safe($st['last_name'] . ', ' . $st['first_name']) ?>
                            </span>
                        </td>
                        <td class="col-combo">
                            <?php if ($combo): ?>
                                <span class="combo-badge"><?= $safe($combo) ?></span>
                            <?php else: ?>
                                <span class="combo-badge empty">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-stream">
                            <span class="stream-badge"><?= $safe($st['stream']) ?></span>
                        </td>
                        <td class="col-status">
                            <span class="status-pill <?= $assigned ? 'pill-assigned' : 'pill-unassigned' ?>" id="status_<?= $safe($st['student_id']) ?>">
                                <?= $assigned ? '<i class="fas fa-check"></i> Assigned' : '<i class="fas fa-minus"></i> Not Assigned' ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="pagination-row">
            <span class="page-info" id="pageInfo"></span>
            <div class="page-btns" id="pageBtns"></div>
        </div>

        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-user-slash"></i>
            <p>No active students found in <strong><?= $safe($class) ?></strong>
               for the selected stream(s).</p>
        </div>
        <?php endif; ?>

    </div><!-- /students-card -->
    <?php endif; ?>

</div><!-- /page -->

<!-- Toast -->
<div id="toast"><span id="toastMsg"></span></div>

<script src="../assets/js/jquery.min.js"></script>
<script>
(function ($) {
    'use strict';

    /* ════════════════════════════════════════════════════════════════════════
       Stream Dropdown Controller
       ════════════════════════════════════════════════════════════════════════ */
    const selector  = document.getElementById('streamSelector');
    const trigger   = document.getElementById('streamTrigger');
    const panel     = document.getElementById('streamPanel');
    const content   = document.getElementById('streamTriggerContent');
    const streamErr = document.getElementById('streamError');

    if (selector) {
        function refreshTrigger() {
            const checked = panel.querySelectorAll('input[type="checkbox"]:checked');
            content.innerHTML = '';
            if (checked.length === 0) {
                const ph = document.createElement('span');
                ph.className   = 'stream-placeholder';
                ph.textContent = 'Select streams…';
                content.appendChild(ph);
            } else {
                checked.forEach(cb => {
                    const tag = document.createElement('span');
                    tag.className   = 'stream-tag';
                    tag.textContent = cb.value;
                    content.appendChild(tag);
                });
            }
        }

        function setOpen(open) {
            selector.classList.toggle('open', open);
            selector.setAttribute('aria-expanded', open ? 'true' : 'false');
            trigger.setAttribute('aria-expanded',  open ? 'true' : 'false');
        }

        trigger.addEventListener('click', e => { e.stopPropagation(); setOpen(!selector.classList.contains('open')); });
        trigger.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setOpen(!selector.classList.contains('open')); }
            if (e.key === 'Escape') setOpen(false);
        });

        panel.addEventListener('click', e => {
            const opt = e.target.closest('.stream-option');
            if (!opt) return;
            const cb = opt.querySelector('input[type="checkbox"]');
            cb.checked = !cb.checked;
            opt.classList.toggle('selected', cb.checked);
            opt.setAttribute('aria-selected', cb.checked ? 'true' : 'false');
            refreshTrigger();
            if (cb.checked) { selector.classList.remove('invalid'); streamErr.style.display = 'none'; }
        });

        document.addEventListener('click', e => { if (!selector.contains(e.target)) setOpen(false); });

        document.getElementById('selectAllStreams').addEventListener('click', e => {
            e.preventDefault();
            panel.querySelectorAll('.stream-option').forEach(opt => {
                opt.querySelector('input').checked = true;
                opt.classList.add('selected');
                opt.setAttribute('aria-selected', 'true');
            });
            refreshTrigger();
            selector.classList.remove('invalid');
            streamErr.style.display = 'none';
        });

        document.getElementById('deselectAllStreams').addEventListener('click', e => {
            e.preventDefault();
            panel.querySelectorAll('.stream-option').forEach(opt => {
                opt.querySelector('input').checked = false;
                opt.classList.remove('selected');
                opt.setAttribute('aria-selected', 'false');
            });
            refreshTrigger();
        });

        refreshTrigger();
    }

    /* ════════════════════════════════════════════════════════════════════════
       Form validation + spinner
       ════════════════════════════════════════════════════════════════════════ */
    const form    = document.getElementById('filterForm');
    const loadBtn = document.getElementById('loadBtn');

    if (form) {
        form.addEventListener('submit', function (e) {
            let ok = true, first = null;

            function fail(fieldId, errId) {
                const el  = document.getElementById(fieldId);
                const err = document.getElementById(errId);
                if (el)  el.classList.add('is-invalid');
                if (err) err.style.display = 'flex';
                if (!first) first = el;
                ok = false;
            }

            ['class', 'subject'].forEach(id => {
                if (!document.getElementById(id)?.value) fail(id, id + 'Error');
            });

            const checkedStreams = panel?.querySelectorAll('input[type="checkbox"]:checked') ?? [];
            if (checkedStreams.length === 0) {
                selector?.classList.add('invalid');
                if (streamErr) streamErr.style.display = 'flex';
                if (!first) first = trigger;
                ok = false;
            }

            if (!ok) {
                e.preventDefault();
                first?.focus();
                first?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            loadBtn.classList.add('loading');
            loadBtn.disabled = true;
            loadBtn.querySelector('.btn-label').textContent = 'Loading…';
        });

        // Clear errors on change
        ['class', 'subject'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', () => {
                document.getElementById(id)?.classList.remove('is-invalid');
                const err = document.getElementById(id + 'Error');
                if (err) err.style.display = 'none';
            });
        });

        // bfcache reset
        window.addEventListener('pageshow', () => {
            if (loadBtn) {
                loadBtn.classList.remove('loading');
                loadBtn.disabled = false;
                const lbl = loadBtn.querySelector('.btn-label');
                if (lbl) lbl.textContent = 'Load Students';
            }
        });
    }

    /* ════════════════════════════════════════════════════════════════════════
       Assignment logic (only active when students table is present)
       ════════════════════════════════════════════════════════════════════════ */
    if (!$('#studentsTable').length) return;

    const CLASS   = <?= json_encode($class,   JSON_HEX_TAG | JSON_HEX_QUOT) ?>;
    const SUBJECT = <?= json_encode($subject, JSON_HEX_TAG | JSON_HEX_QUOT) ?>;
    const CSRF    = <?= json_encode($csrf,    JSON_HEX_TAG | JSON_HEX_QUOT) ?>;

    /* ── Pagination state ──────────────────────────────────────────────── */
    const PER_PAGE = 25;
    let page     = 1;
    let allRows  = [];
    let filtered = [];

    function initRows() {
        allRows  = Array.from($('#tBody .student-row'));
        filtered = allRows.slice();
    }
    initRows();

    function renderTable() {
        $(allRows).hide();
        $('#tBody .no-records-row').remove();

        if (filtered.length === 0) {
            const cols = $('#studentsTable thead th').length;
            $('#tBody').append(
                `<tr class="no-records-row"><td colspan="${cols}"><div class="empty-state">` +
                `<i class="fas fa-search"></i><p>No students match your search.</p></div></td></tr>`
            );
            return;
        }

        const start = (page - 1) * PER_PAGE;
        const end   = Math.min(start + PER_PAGE, filtered.length);
        $(filtered.slice(start, end)).show();

        // re-number
        $(filtered.slice(start, end)).each(function (i) {
            $(this).find('td:nth-child(2)').text(start + i + 1);
        });
    }

    function renderPagination() {
        const total = filtered.length;
        const pages = Math.max(1, Math.ceil(total / PER_PAGE));
        const start = total === 0 ? 0 : (page - 1) * PER_PAGE + 1;
        const end   = Math.min(page * PER_PAGE, total);

        $('#pageInfo').text(total > 0 ? `Showing ${start}–${end} of ${total}` : '');

        if (pages <= 1) { $('#pageBtns').html(''); return; }

        let html = `<button class="page-btn" onclick="goPage(${page-1})" ${page===1?'disabled':''}><i class="fas fa-chevron-left"></i></button>`;
        for (let p = 1; p <= pages; p++) {
            if (pages > 7 && Math.abs(p - page) > 2 && p !== 1 && p !== pages) {
                if (p === 2 || p === pages - 1) html += `<button class="page-btn" disabled style="border:none;cursor:default;color:#bbb">…</button>`;
                continue;
            }
            html += `<button class="page-btn ${p === page ? 'active' : ''}" onclick="goPage(${p})">${p}</button>`;
        }
        html += `<button class="page-btn" onclick="goPage(${page+1})" ${page===pages?'disabled':''}><i class="fas fa-chevron-right"></i></button>`;
        $('#pageBtns').html(html);
    }

    window.goPage = function (p) {
        const pages = Math.max(1, Math.ceil(filtered.length / PER_PAGE));
        if (p < 1 || p > pages) return;
        page = p;
        renderTable();
        renderPagination();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    renderTable();
    renderPagination();

    /* ── Search ────────────────────────────────────────────────────────── */
    let searchTimer;
    $('#studentSearch').on('input', function () {
        clearTimeout(searchTimer);
        const q = $(this).val().toLowerCase().trim();
        searchTimer = setTimeout(() => {
            filtered = allRows.filter(r => {
                const name   = $(r).data('name')   ?? '';
                const stream = $(r).data('stream') ?? '';
                return name.includes(q) || stream.includes(q);
            });
            page = 1;
            renderTable();
            renderPagination();
            updateSelectAll();
        }, 220);
    });

    /* ── Counter ───────────────────────────────────────────────────────── */
    function updateCounter() {
        $('#assignedCount').text($('.assign-cb:checked').length);
    }

    /* ── Select-all header checkbox ─────────────────────────────────────── */
    function updateSelectAll() {
        const visible  = $('.assign-cb:visible');
        const checked  = visible.filter(':checked');
        const allCb    = document.getElementById('selectAllCb');
        if (!allCb) return;
        if (visible.length === 0) {
            allCb.checked       = false;
            allCb.indeterminate = false;
        } else if (checked.length === 0) {
            allCb.checked       = false;
            allCb.indeterminate = false;
        } else if (checked.length === visible.length) {
            allCb.checked       = true;
            allCb.indeterminate = false;
        } else {
            allCb.checked       = false;
            allCb.indeterminate = true;
        }
    }

    $('#selectAllCb').on('change', function () {
        const isChecked = $(this).is(':checked');
        $('.assign-cb:visible').each(function () {
            if ($(this).prop('checked') !== isChecked) {
                $(this).prop('checked', isChecked);
                saveOne($(this), isChecked);
            }
        });
        updateCounter();
    });

    /* ── Status pill helper ──────────────────────────────────────────────── */
    function setStatusPill(studentId, assigned) {
        const $pill = $('#status_' + CSS.escape(studentId));
        $pill.removeClass('pill-assigned pill-unassigned');
        if (assigned) {
            $pill.addClass('pill-assigned').html('<i class="fas fa-check"></i> Assigned');
        } else {
            $pill.addClass('pill-unassigned').html('<i class="fas fa-minus"></i> Not Assigned');
        }
    }

    /* ── Saving pill ─────────────────────────────────────────────────────── */
    let saveQueue = 0;
    function bumpSaving(delta) {
        saveQueue = Math.max(0, saveQueue + delta);
        if (saveQueue > 0) $('#savingPill').addClass('visible');
        else               $('#savingPill').removeClass('visible');
    }

    /* ── Core AJAX save ──────────────────────────────────────────────────── */
    function saveOne($cb, isAssigned) {
        const studentId   = $cb.data('student-id');
        const studentName = $cb.data('student-name');
        const stream      = $cb.data('stream');

        bumpSaving(+1);

        $.ajax({
            url      : 'save_alevel_subject_assignments.php',
            type     : 'POST',
            dataType : 'json',
            data     : {
                csrf_token   : CSRF,
                class        : CLASS,
                subject      : SUBJECT,
                student_id   : studentId,
                student_name : studentName,
                stream       : stream,
                assigned     : isAssigned ? 1 : 0,
            },
        }).done(function (res) {
            if (res.success) {
                setStatusPill(studentId, isAssigned);
                showToast(isAssigned ? 'Student assigned' : 'Student unassigned');
            } else {
                // Revert checkbox
                $cb.prop('checked', !isAssigned);
                setStatusPill(studentId, !isAssigned);
                showToast(res.message || 'Save failed', true);
            }
        }).fail(function () {
            $cb.prop('checked', !isAssigned);
            setStatusPill(studentId, !isAssigned);
            showToast('Network error — please try again', true);
        }).always(function () {
            bumpSaving(-1);
            updateCounter();
            updateSelectAll();
        });
    }

    /* ── Individual checkbox change ──────────────────────────────────────── */
    $('#tBody').on('change', '.assign-cb', function () {
        saveOne($(this), $(this).is(':checked'));
        updateCounter();
        updateSelectAll();
    });

    /* ── Bulk assign all ─────────────────────────────────────────────────── */
    $('#assignAllBtn').on('click', function () {
        $('.assign-cb:visible').each(function () {
            if (!$(this).is(':checked')) {
                $(this).prop('checked', true);
                saveOne($(this), true);
            }
        });
        updateCounter();
        updateSelectAll();
    });

    /* ── Bulk unassign all ───────────────────────────────────────────────── */
    $('#unassignAllBtn').on('click', function () {
        $('.assign-cb:visible').each(function () {
            if ($(this).is(':checked')) {
                $(this).prop('checked', false);
                saveOne($(this), false);
            }
        });
        updateCounter();
        updateSelectAll();
    });

    /* ── Toast ───────────────────────────────────────────────────────────── */
    let toastTimer;
    function showToast(msg, isError = false) {
        clearTimeout(toastTimer);
        $('#toastMsg').text(msg);
        $('#toast').toggleClass('toast-error', isError).addClass('show');
        toastTimer = setTimeout(() => $('#toast').removeClass('show'), isError ? 4000 : 2500);
    }

    updateSelectAll();

}(jQuery));
</script>

</body>
</html>