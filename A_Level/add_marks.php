<?php
/**
 * add_marks.php
 * SchoolPilot — Step 3: Enter marks for each student.
 *
 * Fixes applied vs original:
 *  - CSRF token generated and passed to all AJAX calls
 *  - SQL injection fixed in getStudents() & getExistingMarks() (prepared stmts)
 *  - All dynamic output wrapped in htmlspecialchars()
 *  - Search bar widened and debounced (300 ms)
 *  - mark inputs changed to type="number" min=0 max=100
 *  - Progress counter (X of Y marks entered)
 *  - Improved UI matching view_students.php header style
 *  - Cleaned up save notification (proper CSS animation)
 *  - Streams validated against whitelist
 *  - getStudents() now joins student_alevel_subjects so only students assigned
 *    to the selected subject are shown; General Paper bypasses this filter
 *    (all active A-level students sit GP — no assignment rows exist for it)
 */

require_once '../auth.php';
require_once '../conn.php';
require_once '../O_Level/teacher_auth_check.php';

// ── CSRF token ─────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── Extract & basic-sanitise params ───────────────────────────────────────────
$class     = trim($_GET['class']     ?? '');
$term      = trim($_GET['term']      ?? '');
$year      = trim($_GET['year']      ?? date('Y'));
$streams   = isset($_GET['streams']) && is_array($_GET['streams']) ? $_GET['streams'] : [];
$subject   = trim($_GET['subject']   ?? '');
$exam_type = trim($_GET['exam_type'] ?? '');
$papers    = isset($_GET['papers'])  && is_array($_GET['papers'])  ? $_GET['papers']  : [];

// Whitelist streams
$validStreams = ['Arts', 'Sciences'];
$streams = array_filter($streams, fn($s) => in_array($s, $validStreams, true));

// ── Data functions ─────────────────────────────────────────────────────────────

/**
 * Look up the display name for an exam type ID.
 */
function getExamTypeName(mysqli $conn, string $examId): string
{
    if (!is_numeric($examId)) return 'Unknown';
    $id   = (int) $examId;
    $sql  = 'SELECT exam_set FROM exam_sets WHERE id = ? LIMIT 1';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return 'Unknown';
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row    = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    return $row['exam_set'] ?? 'Unknown';
}

/**
 * Fetch students for the given class and streams.
 * Uses parameterised IN clause built safely from whitelisted values.
 */
/**
 * Fetch students for the given class, streams and subject.
 *
 * - For General Paper (subj_abbr = 'GP') all active students in the class/streams
 *   are returned, because GP is sat by every A-level student and there are no
 *   assignment rows in student_alevel_subjects for it.
 * - For every other subject only students that have been assigned to that subject
 *   via student_alevel_subjects are returned.
 */
function getStudents(mysqli $conn, string $class, array $streams, string $subject): array
{
    // Determine whether this is General Paper
    $gpCheck = mysqli_prepare($conn,
        "SELECT subj_id FROM subjects WHERE subj_name = ? AND subj_abbr = 'GP' LIMIT 1"
    );
    $isGP = false;
    if ($gpCheck) {
        mysqli_stmt_bind_param($gpCheck, 's', $subject);
        mysqli_stmt_execute($gpCheck);
        mysqli_stmt_store_result($gpCheck);
        $isGP = mysqli_stmt_num_rows($gpCheck) > 0;
        mysqli_stmt_close($gpCheck);
    }

    if ($isGP) {
        // ── General Paper: return ALL active students in the class/streams ──────
        if (!empty($streams)) {
            $placeholders = implode(',', array_fill(0, count($streams), '?'));
            $types        = 's' . str_repeat('s', count($streams));
            $params       = array_merge([$class], array_values($streams));
            $sql = "SELECT student_id, first_name, last_name, stream, subject_combination
                    FROM   students
                    WHERE  current_class = ?
                      AND  status = 'Active'
                      AND  stream IN ({$placeholders})
                    ORDER  BY first_name, last_name";
            $stmt = mysqli_prepare($conn, $sql);
            if (!$stmt) return [];
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        } else {
            $sql = "SELECT student_id, first_name, last_name, stream, subject_combination
                    FROM   students
                    WHERE  current_class = ?
                      AND  status = 'Active'
                    ORDER  BY first_name, last_name";
            $stmt = mysqli_prepare($conn, $sql);
            if (!$stmt) return [];
            mysqli_stmt_bind_param($stmt, 's', $class);
        }
    } else {
        // ── All other subjects: only assigned students ────────────────────────
        if (!empty($streams)) {
            $placeholders = implode(',', array_fill(0, count($streams), '?'));
            $types        = 'ss' . str_repeat('s', count($streams));
            $params       = array_merge([$class, $subject], array_values($streams));
            $sql = "SELECT s.student_id, s.first_name, s.last_name, s.stream, s.subject_combination
                    FROM   students s
                    INNER JOIN student_alevel_subjects sa
                           ON  sa.student_id = s.student_id
                           AND sa.class   COLLATE utf8mb4_unicode_ci = s.current_class COLLATE utf8mb4_unicode_ci
                           AND sa.subject COLLATE utf8mb4_unicode_ci = ?
                    WHERE  s.current_class = ?
                      AND  s.status        = 'Active'
                      AND  s.stream        IN ({$placeholders})
                   ORDER  BY s.first_name, s.last_name";
            // Reorder params: subject, class, streams[]
            $params = array_merge([$subject, $class], array_values($streams));
            $stmt = mysqli_prepare($conn, $sql);
            if (!$stmt) return [];
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        } else {
            $sql = "SELECT s.student_id, s.first_name, s.last_name, s.stream, s.subject_combination
                    FROM   students s
                    INNER JOIN student_alevel_subjects sa
                           ON  sa.student_id = s.student_id
                           AND sa.class   COLLATE utf8mb4_unicode_ci = s.current_class COLLATE utf8mb4_unicode_ci
                           AND sa.subject COLLATE utf8mb4_unicode_ci = ?
                    WHERE  s.current_class = ?
                      AND  s.status        = 'Active'
                   ORDER  BY s.first_name, s.last_name";
            $stmt = mysqli_prepare($conn, $sql);
            if (!$stmt) return [];
            mysqli_stmt_bind_param($stmt, 'ss', $subject, $class);
        }
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $out    = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $out[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $out;
}

/**
 * Build and validate a marks table name.
 * Pattern: YYYY_(i|ii|iii)_alevel
 */
function buildTableName(string $year, string $term): string|false
{
    if (!preg_match('/^\d{4}$/', $year)) return false;
    if (str_contains($term, '1')) return "{$year}_i_alevel";
    if (str_contains($term, '2')) return "{$year}_ii_alevel";
    if (str_contains($term, '3')) return "{$year}_iii_alevel";
    return false;
}

/**
 * Returns existing marks keyed as [student_id][paper] => mark.
 * Uses prepared statements throughout.
 */
function getExistingMarks(mysqli $conn, string $class, string $term, string $year,
                          string $subject, string $examType): array
{
    $tableName = buildTableName($year, $term);
    if ($tableName === false) return [];

    // Check table existence safely
    $chk  = 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1';
    $stmt = mysqli_prepare($conn, $chk);
    mysqli_stmt_bind_param($stmt, 's', $tableName);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $exists = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    if (!$exists) return [];

    $sql  = "SELECT student_id, paper, mark FROM `{$tableName}`
             WHERE class = ? AND term = ? AND year = ? AND subject = ? AND exam_type = ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    $examTypeInt = (int)$examType;
    mysqli_stmt_bind_param($stmt, 'ssssi', $class, $term, $year, $subject, $examTypeInt);    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $out    = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $out[$row['student_id']][$row['paper']] = $row['mark'];
    }
    mysqli_stmt_close($stmt);
    return $out;
}

    // ── Build back URL to choose_exam_type.php ───────────────────────────────
    $backParams = http_build_query([
        'class'      => $class,
        'term'       => $term,
        'year'       => $year,
        'subject'    => $subject,
        'exam_type'  => $exam_type,
    ]);
    $streamQuery = implode('&', array_map(fn($s) => 'streams[]=' . urlencode($s), $streams));
    $backUrl     = 'choose_exam_type.php?' . $backParams . ($streamQuery ? '&' . $streamQuery : '');
$examTypeName  = getExamTypeName($conn, $exam_type);
$students      = getStudents($conn, $class, $streams, $subject);
$existingMarks = getExistingMarks($conn, $class, $term, $year, $subject, $exam_type);

// Format paper labels
$paperLabels = array_map(fn($p) => 'Paper ' . $p, $papers);

// Count pre-existing marks for the progress counter
$preFilledCount = 0;
foreach ($students as $st) {
    foreach ($papers as $p) {
        if (isset($existingMarks[$st['student_id']][$p])) {
            $preFilledCount++;
        }
    }
}
$totalMarkSlots = count($students) * count($papers);

// Page params for JavaScript (safe JSON, XSS-safe)
$pageParams = json_encode([
    'class'      => $class,
    'term'       => $term,
    'year'       => $year,
    'subject'    => $subject,
    'exam_type'  => $exam_type,
    'csrf_token' => $csrf,      // CSRF token included for all AJAX calls
], JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP);

$safe = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enter Marks &mdash; <?= $safe($subject) ?> &mdash; SchoolPilot</title>
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── Variables ──────────────────────────────────────────────────────────────── */
:root {
    --g900: #1b5e20; --g800: #2e7d32; --g700: #388e3c; --g600: #43a047;
    --g400: #66bb6a; --g100: #e8f5e9; --g50: #f1f8f1;
    --gray-100: #f5f7fa; --gray-200: #e8ede9; --gray-400: #c8d4c9;
    --gray-600: #6b7c6d; --text: #263329;
    --danger: #d32f2f;
    --radius: 8px; --radius-lg: 12px;
    --shadow: 0 2px 8px rgba(0,0,0,.09);
    --shadow-lg: 0 8px 28px rgba(0,0,0,.13);
    --transition: .22s ease;
}

*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: "Segoe UI", system-ui, sans-serif;
    background: #f0f4f1;
    color: var(--text);
    min-height: 100vh;
    padding: 0 0 60px;
}

/* ── Page ───────────────────────────────────────────────────────────────────── */
.page { max-width: 100%; margin: 0 auto; padding: 24px 20px 60px; }

/* ── Page Header ────────────────────────────────────────────────────────────── */
.page-header {
    background: linear-gradient(135deg, var(--g900) 0%, var(--g700) 100%);
    border-radius: var(--radius-lg);
    padding: 24px 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 20px;
    margin-top: 52px;
    box-shadow: var(--shadow-lg);
}
.page-header-left h1 { color: #fff; font-size: 1.4rem; font-weight: 700; }
.page-header-left p  { color: rgba(255,255,255,.75); font-size: .85rem; margin-top: 3px; }

/* ── Progress pill ──────────────────────────────────────────────────────────── */
.progress-pill {
    background: rgba(255,255,255,.15);
    border: 1px solid rgba(255,255,255,.25);
    border-radius: 40px;
    padding: 8px 18px;
    text-align: center;
    min-width: 140px;
}
.progress-pill .pn { font-size: 1.3rem; font-weight: 700; color: #fff; display: block; }
.progress-pill .pl { font-size: .72rem; color: rgba(255,255,255,.72); letter-spacing: .4px; text-transform: uppercase; }

/* ── Summary Bar ─────────────────────────────────────────────────────────────── */
.summary-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 0;
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    overflow: hidden;
    margin-bottom: 20px;
    box-shadow: var(--shadow);
}
.summary-item { display: flex; flex-direction: column; padding: 11px 20px; border-right: 1px solid var(--gray-200); }
.summary-item:last-child { border-right: none; }
.summary-label { font-size: .7rem; font-weight: 700; color: var(--gray-600); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 2px; }
.summary-value { font-size: .88rem; font-weight: 600; color: var(--g800); }

/* ── Card ───────────────────────────────────────────────────────────────────── */
.card { background: #fff; border-radius: var(--radius-lg); box-shadow: var(--shadow); overflow: hidden; }

/* ── Toolbar ────────────────────────────────────────────────────────────────── */
.toolbar {
    padding: 16px 24px;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    justify-content: space-between;
}
.toolbar-left { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; flex: 1; }

/* ── Search bar — wider ──────────────────────────────────────────────────────── */
.search-wrap {
    position: relative;
    flex: 1 1 420px;      /* grows up to available space, min ~420px */
    max-width: 580px;
}
.search-wrap i {
    position: absolute;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--gray-600);
    font-size: .85rem;
    pointer-events: none;
}
.search-wrap input {
    width: 100%;
    padding: 9px 13px 9px 36px;
    border: 1.5px solid var(--gray-400);
    border-radius: 20px;
    font-size: .875rem;
    transition: border-color var(--transition), box-shadow var(--transition);
    background: var(--gray-100);
}
.search-wrap input:focus {
    outline: none;
    border-color: var(--g600);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(67,160,71,.12);
}
.search-wrap .clear-search {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    border: none;
    background: none;
    color: var(--gray-600);
    cursor: pointer;
    padding: 2px 6px;
    font-size: .8rem;
    display: none;
    border-radius: 50%;
    transition: background var(--transition);
}
.search-wrap .clear-search:hover { background: var(--gray-200); }

/* ── Records per page ──────────────────────────────────────────────────────── */
.records-wrap { display: flex; align-items: center; gap: 8px; font-size: .85rem; color: var(--gray-600); white-space: nowrap; }
.records-wrap select {
    height: 36px;
    padding: 0 10px;
    border: 1.5px solid var(--gray-400);
    border-radius: var(--radius);
    font-size: .85rem;
    background: #fff;
    cursor: pointer;
}
.result-count { font-size: .8rem; color: var(--gray-600); white-space: nowrap; }

/* ── Table ──────────────────────────────────────────────────────────────────── */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead tr { background: linear-gradient(90deg, var(--g700) 0%, var(--g600) 100%); }
thead th {
    padding: 12px 14px;
    text-align: left;
    font-size: .8rem;
    font-weight: 600;
    color: #fff;
    letter-spacing: .4px;
    white-space: nowrap;
}
tbody tr { border-bottom: 1px solid #f0f4f1; transition: background var(--transition); }
tbody tr:hover { background: #f5fbf5; }
tbody td { padding: 12px 14px; font-size: .875rem; vertical-align: middle; }

/* ── Stream / Combination badges ─────────────────────────────────────────────── */
.badge-stream { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: .72rem; font-weight: 700; background: #e3f2fd; color: #1565c0; }
.badge-combo  { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: .72rem; font-weight: 700; background: #ede7f6; color: #5e35b1; }

/* ── Mark Input ─────────────────────────────────────────────────────────────── */
.mark-input {
    width: 68px;
    height: 34px;
    text-align: center;
    border: 1.5px solid var(--gray-400);
    border-radius: var(--radius);
    font-size: .9rem;
    font-weight: 600;
    transition: border-color var(--transition), box-shadow var(--transition);
    background: #fff;
    -moz-appearance: textfield;    /* remove spinners in Firefox */
}
.mark-input::-webkit-outer-spin-button,
.mark-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.mark-input:focus {
    outline: none;
    border-color: var(--g600);
    box-shadow: 0 0 0 3px rgba(67,160,71,.15);
}
.mark-input.saving   { border-color: #fb8c00; background: #fff8f0; }
.mark-input.saved    { border-color: var(--g600); background: #f5fbf5; }
.mark-input.error    { border-color: var(--danger); background: #fff8f8; }

/* ── Row status dot ─────────────────────────────────────────────────────────── */
.status-dot {
    display: inline-block;
    width: 8px; height: 8px;
    border-radius: 50%;
    margin-left: 5px;
    vertical-align: middle;
    opacity: 0;
    transition: opacity .3s;
}
.status-dot.saving  { background: #fb8c00; opacity: 1; }
.status-dot.saved   { background: var(--g600); opacity: 1; }
.status-dot.err     { background: var(--danger); opacity: 1; }

/* ── Empty state ─────────────────────────────────────────────────────────────── */
.empty-state { text-align: center; padding: 60px 20px; color: var(--gray-600); }
.empty-state i { font-size: 2.5rem; display: block; margin-bottom: 12px; opacity: .4; }

/* ── Pagination ──────────────────────────────────────────────────────────────── */
.pagination-bar {
    padding: 14px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-top: 1px solid var(--gray-200);
    flex-wrap: wrap;
    gap: 10px;
}
.page-info { font-size: .82rem; color: var(--gray-600); }
.page-btns { display: flex; gap: 4px; }
.page-btn {
    width: 32px; height: 32px;
    border: 1.5px solid var(--gray-400);
    border-radius: 6px;
    background: #fff;
    cursor: pointer;
    font-size: .82rem;
    font-weight: 600;
    color: #444;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all var(--transition);
}
.page-btn:hover:not(:disabled) { border-color: var(--g600); background: var(--g100); color: var(--g800); }
.page-btn.active                { background: var(--g700); border-color: var(--g700); color: #fff; }
.page-btn:disabled              { opacity: .35; cursor: default; }

/* ── Footer Action Bar ───────────────────────────────────────────────────────── */
.action-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 24px;
    border-top: 1px solid var(--gray-200);
    flex-wrap: wrap;
    gap: 12px;
}
.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 20px;
    border-radius: var(--radius);
    font-size: .875rem;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    border: 1.5px solid transparent;
    text-decoration: none;
    transition: all var(--transition);
}
.btn-outline { background: #fff; color: var(--gray-600); border-color: var(--gray-400); }
.btn-outline:hover { background: var(--gray-100); border-color: var(--gray-600); }
.saved-badge { font-size: .82rem; color: var(--gray-600); }
.saved-badge strong { color: var(--g700); }

/* ── Toast notification ──────────────────────────────────────────────────────── */
#toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: var(--g800);
    color: #fff;
    padding: 12px 18px;
    border-radius: var(--radius);
    font-size: .875rem;
    font-weight: 600;
    box-shadow: var(--shadow-lg);
    display: flex;
    align-items: center;
    gap: 9px;
    z-index: 9999;
    transform: translateY(80px);
    opacity: 0;
    transition: transform .3s ease, opacity .3s ease;
    pointer-events: none;
}
#toast.show { transform: translateY(0); opacity: 1; }
#toast.toast-error { background: var(--danger); }

/* ── Paper header tabs ──────────────────────────────────────────────────────── */
.paper-tabs { display: flex; gap: 8px; padding: 14px 24px 0; flex-wrap: wrap; }
.paper-tab {
    background: var(--g100);
    color: var(--g800);
    padding: 5px 14px;
    border-radius: 20px;
    font-size: .78rem;
    font-weight: 700;
    letter-spacing: .3px;
}

/* ── Responsive ─────────────────────────────────────────────────────────────── */
@media (max-width: 768px) {
    .page { padding: 12px 12px 60px; }
    .page-header { flex-direction: column; }
    .summary-item { border-right: none; border-bottom: 1px solid var(--gray-200); }
    .toolbar { flex-direction: column; align-items: stretch; }
    .search-wrap { max-width: 100%; }
}
</style>
</head>
<body>

<?php require_once '../nav.php'; ?>

<div class="page">

    <!-- ── Page Header ───────────────────────────────────────────────────── -->
    <div class="page-header">
        <div class="page-header-left">
            <h1><i class="fas fa-pen-to-square" style="margin-right:10px;opacity:.85"></i>Enter <?= $safe($subject) ?> Marks</h1>
            <p><?= $safe($class) ?> &bull; <?= $safe($term) ?> <?= $safe($year) ?> &bull; <?= $safe($examTypeName) ?></p>
        </div>
        <div class="progress-pill" id="progressPill">
            <span class="pn" id="progressNum"><?= $preFilledCount ?> / <?= $totalMarkSlots ?></span>
            <span class="pl">Marks Entered</span>
        </div>
    </div>

    <!-- ── Summary Bar ───────────────────────────────────────────────────── -->
    <div class="summary-bar">
        <div class="summary-item">
            <span class="summary-label">Class</span>
            <span class="summary-value"><?= $safe($class) ?></span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Subject</span>
            <span class="summary-value"><?= $safe($subject) ?></span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Exam Type</span>
            <span class="summary-value"><?= $safe($examTypeName) ?></span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Streams</span>
            <span class="summary-value"><?= $safe(implode(', ', $streams) ?: 'All') ?></span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Students</span>
            <span class="summary-value"><?= count($students) ?></span>
        </div>
    </div>

    <!-- ── Main Card ─────────────────────────────────────────────────────── -->
    <div class="card">

        <?php if (!empty($paperLabels)): ?>
        <div class="paper-tabs">
            <?php foreach ($paperLabels as $lbl): ?>
            <span class="paper-tab"><i class="fas fa-file-alt" style="margin-right:5px;opacity:.7"></i><?= $safe($lbl) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="toolbar-left">
                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search by name or student ID…" autocomplete="off">
                    <button class="clear-search" id="clearSearch" title="Clear search">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <span class="result-count" id="resultCount"></span>
            </div>
            <div class="records-wrap">
                <label for="recordsPerPage">Show</label>
                <select id="recordsPerPage">
                    <option value="10" selected>10</option>
                    <option value="20">20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="9999">All</option>
                </select>
                <span>entries</span>
            </div>
        </div>

        <!-- Table -->
        <div class="table-wrap">
            <table id="marksTable">
                <thead>
                    <tr>
                        <th style="width:42px">#</th>
                        <th>Student Name</th>
                        <th>Student ID</th>
                        <th>Stream</th>
                        <th>Combination</th>
                        <?php foreach ($paperLabels as $i => $lbl): ?>
                        <th>
                            <?= $safe($lbl) ?>
                            <span style="display:block;font-size:.7rem;font-weight:400;opacity:.75">Max: 100</span>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody id="tBody">
                    <?php if (count($students) > 0): ?>
                        <?php foreach ($students as $idx => $student): ?>
                        <tr data-name="<?= $safe(strtolower($student['first_name'] . ' ' . $student['last_name'])) ?>"
                            data-id="<?= $safe(strtolower($student['student_id'])) ?>">
                            <td style="color:#9aaa9b;font-size:.78rem"><?= $idx + 1 ?></td>
                            <td style="font-weight:600;color:var(--g800)"><?= $safe($student['first_name'] . ' ' . $student['last_name']) ?></td>
                            <td style="font-size:.82rem;color:var(--gray-600);font-weight:600"><?= $safe($student['student_id']) ?></td>
                            <td><span class="badge-stream"><?= $safe($student['stream']) ?></span></td>
                            <td><span class="badge-combo"><?= $safe($student['subject_combination'] ?? '—') ?></span></td>
                            <?php foreach ($papers as $paper): ?>
                            <?php $existingVal = $existingMarks[$student['student_id']][$paper] ?? ''; ?>
                            <td>
                                <input type="number"
                                       class="mark-input"
                                       data-student-id="<?= $safe($student['student_id']) ?>"
                                       data-paper="<?= $safe($paper) ?>"
                                       data-stream="<?= $safe($student['stream']) ?>"
                                       data-original="<?= $existingVal !== '' ? (int)$existingVal : '' ?>"
                                       value="<?= $existingVal !== '' ? (int)$existingVal : '' ?>"
                                       min="0"
                                       max="100"
                                       step="1"
                                       placeholder="—"
                                       title="Enter a mark between 0 and 100">
                                <span class="status-dot" id="dot_<?= $safe($student['student_id']) ?>_<?= $safe($paper) ?>"></span>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr id="emptyRow">
                            <td colspan="<?= 5 + count($paperLabels) ?>">
                                <div class="empty-state">
                                    <i class="fas fa-users-slash"></i>
                                    <p>No students found for the selected class and streams.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if (count($students) > 0): ?>
        <div class="pagination-bar">
            <span class="page-info" id="pageInfo"></span>
            <div class="page-btns" id="pageBtns"></div>
        </div>
        <?php endif; ?>

        <!-- Footer Action Bar -->
        <div class="action-bar">
            <a href="<?= $safe($backUrl) ?>" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <span class="saved-badge">
                <i class="fas fa-check-circle" style="color:var(--g600)"></i>
                <strong id="savedCount"><?= $preFilledCount ?></strong> of
                <strong><?= $totalMarkSlots ?></strong> marks entered
            </span>
        </div>

    </div><!-- /card -->

</div><!-- /page -->

<!-- Toast -->
<div id="toast"><i class="fas fa-check-circle"></i> <span id="toastMsg">Mark saved</span></div>

<script src="../assets/js/jquery.min.js"></script>
<script>
(function ($) {
    'use strict';

    /* ── Page parameters (from PHP) ──────────────────────────────────────── */
    const P = <?= $pageParams ?>;

    /* ── State ────────────────────────────────────────────────────────────── */
    let allRows    = [],      // array of TR elements (all, not filtered)
        filtered   = [],      // currently filtered rows
        page       = 1,
        perPage    = 10,
        savedCount = (function () {
            const n = parseInt($('#savedCount').text(), 10);
            return isNaN(n) ? 0 : n;
        }());

    /* ── Boot ─────────────────────────────────────────────────────────────── */
    $(function () {
        allRows  = $('#tBody tr').not('#emptyRow').toArray();
        filtered = allRows.slice();

        // Only run table/pagination logic when there are students.
        // Without this guard, renderTable() would append a duplicate
        // "no students" row on top of the PHP-rendered #emptyRow.
        if (allRows.length > 0) {
            renderTable();
            renderPagination();
            updateResultCount();
        }

        // Store initial values as strings for dirty-check comparisons
        $('.mark-input').each(function () {
            $(this).data('original', $(this).val());
        });
    });

    /* ── Search (debounced 300ms) ─────────────────────────────────────────── */
    let searchTimer;
    $('#searchInput').on('input', function () {
        clearTimeout(searchTimer);
        const q = this.value.trim();
        $('#clearSearch').css('display', q ? 'block' : 'none');
        searchTimer = setTimeout(() => { page = 1; applyFilter(); }, 300);
    });

    $('#clearSearch').on('click', function () {
        $('#searchInput').val('');
        $(this).hide();
        page = 1;
        applyFilter();
        $('#searchInput').focus();
    });

    function applyFilter() {
        const q = $('#searchInput').val().trim().toLowerCase();
        filtered = q
            ? allRows.filter(r => $(r).data('name').includes(q) || $(r).data('id').includes(q))
            : allRows.slice();
        renderTable();
        renderPagination();
        updateResultCount();
    }

    /* ── Records per page ─────────────────────────────────────────────────── */
    $('#recordsPerPage').on('change', function () {
        perPage = parseInt(this.value, 10) || 9999;
        page = 1;
        renderTable();
        renderPagination();
    });

    /* ── Table render ─────────────────────────────────────────────────────── */
    function renderTable() {
        $(allRows).hide();
        $('.no-records-row').remove();

        if (filtered.length === 0) {
            const cols = $('#marksTable thead th').length;
            $('#tBody').append(
                `<tr class="no-records-row"><td colspan="${cols}"><div class="empty-state">` +
                `<i class="fas fa-search"></i><p>No students match your search.</p></div></td></tr>`
            );
            return;
        }

        const start = (page - 1) * perPage;
        const end   = Math.min(start + perPage, filtered.length);
        $(filtered.slice(start, end)).show();

        // Re-number visible rows
        $(filtered.slice(start, end)).each(function (i) {
            $(this).find('td:first').text(start + i + 1);
        });
    }

    /* ── Pagination render ────────────────────────────────────────────────── */
    function renderPagination() {
        const total  = filtered.length;
        const pages  = Math.max(1, Math.ceil(total / perPage));
        const start  = total === 0 ? 0 : (page - 1) * perPage + 1;
        const end    = Math.min(page * perPage, total);

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
        const pages = Math.max(1, Math.ceil(filtered.length / perPage));
        if (p < 1 || p > pages) return;
        page = p;
        renderTable();
        renderPagination();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    function updateResultCount() {
        const total = allRows.length;
        const shown = filtered.length;
        $('#resultCount').text(shown < total ? `${shown} of ${total} students` : `${total} students`);
    }

    /* ── Mark input — live validation ────────────────────────────────────── */
    $('#tBody').on('input', '.mark-input', function () {
        const v = this.value;
        if (v === '') return;
        let n = parseInt(v, 10);
        if (isNaN(n))  { this.value = ''; return; }
        if (n < 0)     { this.value = '0';   return; }
        if (n > 100)   { this.value = '100'; return; }
    });

    /* ── Mark input — save on blur ────────────────────────────────────────── */
    $('#tBody').on('blur', '.mark-input', function () {
        const $input     = $(this);
        const studentId  = $input.data('student-id');
        const paper      = $input.data('paper');
        const stream     = $input.data('stream');
        const mark       = $input.val().trim();
        const original   = String($input.data('original') ?? '');
        const $dot       = $(`#dot_${CSS.escape(studentId + '_' + paper)}`);

        if (mark === original) return;   // nothing changed

        if (mark === '' && original !== '') {
            // Empty = delete the mark
            deleteMark($input, $dot, studentId, paper, original);
            return;
        }
        if (mark === '') return;

        // Validate range
        const n = parseInt(mark, 10);
        if (isNaN(n) || n < 0 || n > 100) {
            $input.addClass('error');
            showDot($dot, 'err');
            showToast('Invalid mark (0–100 only)', true);
            return;
        }

        saveMark($input, $dot, studentId, paper, mark, stream, original);
    });

    /* ── AJAX: save ───────────────────────────────────────────────────────── */
    function saveMark($input, $dot, studentId, paper, mark, stream, original) {
        $input.removeClass('saved error').addClass('saving');
        showDot($dot, 'saving');

        $.ajax({
            url  : 'save_mark_ajax.php',
            type : 'POST',
            data : {
                csrf_token : P.csrf_token,
                student_id : studentId,
                paper      : paper,
                mark       : mark,
                stream     : stream,
                class      : P.class,
                term       : P.term,
                year       : P.year,
                subject    : P.subject,
                exam_type  : P.exam_type,
            },
            dataType: 'json',
        }).done(function (res) {
            if (res.success) {
                $input.removeClass('saving error').addClass('saved');
                $input.data('original', mark);
                showDot($dot, 'saved');
                if (original === '') { savedCount++; updateSavedCount(); }
                showToast('Mark saved');
            } else {
                $input.removeClass('saving').addClass('error');
                showDot($dot, 'err');
                showToast(res.message || 'Save failed', true);
            }
        }).fail(function () {
            $input.removeClass('saving').addClass('error');
            showDot($dot, 'err');
            showToast('Network error — please try again', true);
        }).always(function () {
            setTimeout(() => { $input.removeClass('saved error'); showDot($dot, ''); }, 2500);
        });
    }

    /* ── AJAX: delete ─────────────────────────────────────────────────────── */
    function deleteMark($input, $dot, studentId, paper, original) {
        $input.addClass('saving');
        showDot($dot, 'saving');

        $.ajax({
            url  : 'delete_mark_ajax.php',
            type : 'POST',
            data : {
                csrf_token : P.csrf_token,
                student_id : studentId,
                paper      : paper,
                class      : P.class,
                term       : P.term,
                year       : P.year,
                subject    : P.subject,
                exam_type  : P.exam_type,
            },
            dataType: 'json',
        }).done(function (res) {
            if (res.success) {
                $input.removeClass('saving error saved');
                $input.data('original', '');
                showDot($dot, '');
                savedCount = Math.max(0, savedCount - 1);
                updateSavedCount();
                showToast('Mark removed');
            } else {
                $input.val(original).removeClass('saving').addClass('error');
                showDot($dot, 'err');
                showToast(res.message || 'Delete failed', true);
                setTimeout(() => { $input.removeClass('error'); showDot($dot, ''); }, 2500);
            }
        }).fail(function () {
            $input.val(original).removeClass('saving').addClass('error');
            showDot($dot, 'err');
            showToast('Network error — mark was not deleted', true);
            setTimeout(() => { $input.removeClass('error'); showDot($dot, ''); }, 2500);
        });
    }

    /* ── Helpers ──────────────────────────────────────────────────────────── */
    function showDot($dot, state) {
        $dot.removeClass('saving saved err');
        if (state) $dot.addClass(state);
    }

    let toastTimer;
    function showToast(msg, isError = false) {
        clearTimeout(toastTimer);
        $('#toastMsg').text(msg);
        $('#toast').removeClass('toast-error').toggleClass('toast-error', isError).addClass('show');
        toastTimer = setTimeout(() => $('#toast').removeClass('show'), isError ? 4000 : 2500);
    }

    function updateSavedCount() {
        $('#savedCount').text(savedCount);
        $('#progressNum').text(`${savedCount} / <?= $totalMarkSlots ?>`);
    }

}(jQuery));
</script>

</body>
</html>