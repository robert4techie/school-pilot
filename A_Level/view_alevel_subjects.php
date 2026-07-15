<?php
/**
 * view_alevel_subjects.php
 * SchoolPilot — Browse & filter A-Level students by their subject assignments.
 *
 * Filters : class (required), stream(s) (required), subject(s) [optional],
 *           only-assigned toggle [optional].
 * Output  : stat cards + student table with colour-tagged subjects, CSV export.
 *
 * SQL note: all cross-table string comparisons carry
 *           COLLATE utf8mb4_unicode_ci to prevent "Illegal mix of collations"
 *           errors when students / student_alevel_subjects differ in collation.
 */

require_once '../auth.php';
require_once '../conn.php';

// ── Helpers ─────────────────────────────────────────────────────────────────────
$safe = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// ── Constants ──────────────────────────────────────────────────────────────────
$availableClasses = ['Senior Five', 'Senior Six'];
$availableStreams  = ['Arts', 'Sciences'];

// ── Params (GET) ───────────────────────────────────────────────────────────────
$class       = trim($_GET['class'] ?? '');
$streams     = isset($_GET['streams'])  && is_array($_GET['streams'])
               ? array_values(array_filter($_GET['streams'],
                     fn($s) => in_array($s, $availableStreams, true)))
               : [];
$rawSubjects = isset($_GET['subjects']) && is_array($_GET['subjects'])
               ? array_values($_GET['subjects'])
               : [];
$onlyAssigned = !empty($_GET['only_assigned']);

// ── Data functions ─────────────────────────────────────────────────────────────

/**
 * All A-Level subjects, excluding GP.
 * GP has no rows in student_alevel_subjects (all students sit it automatically),
 * so it cannot be meaningfully filtered here.
 */
function getALevelSubjects(mysqli $conn): array
{
    $res = mysqli_query($conn,
        "SELECT subj_name FROM subjects
         WHERE (level LIKE '%A%') AND subj_abbr != 'GP'
         ORDER BY subj_name"
    );
    $out = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $out[] = $row['subj_name'];
        }
    }
    return $out;
}

/** Sanitise user-supplied subject list against DB values. */
function validateSubjects(mysqli $conn, array $requested): array
{
    if (empty($requested)) return [];
    $allowed = getALevelSubjects($conn);
    return array_values(array_filter($requested, fn($s) => in_array($s, $allowed, true)));
}

/**
 * Deterministic colour slot 0-7 for a subject name (consistent across loads).
 */
function subjectColorSlot(string $name): int
{
    return ((crc32($name) % 8) + 8) % 8;
}

/**
 * Fetch students with their assigned subjects.
 *
 * If $filterSubjs is empty  → LEFT JOIN: all students (with/without subjects).
 * If $filterSubjs is set    → INNER JOIN to restrict to matched students, then
 *                             LEFT JOIN to collect ALL their subjects for display.
 *
 * Each returned row has all student columns plus:
 *   subjects       – chr(31)-delimited string (null if none)
 *   subject_count  – int
 *   subjects_array – PHP array (added post-fetch)
 */
function getStudentsWithSubjects(
    mysqli $conn,
    string $class,
    array  $streams,
    array  $filterSubjs  = [],
    bool   $onlyAssigned = false
): array {
    if (empty($streams)) return [];

    $streamPH = implode(',', array_fill(0, count($streams), '?'));

    // MariaDB's GROUP_CONCAT SEPARATOR only accepts a string literal, not CHAR(31).
    // We embed the unit-separator byte directly via PHP so it arrives as a literal.
    $sep = chr(31);

    if (empty($filterSubjs)) {
        $having = $onlyAssigned ? 'HAVING COUNT(sa.subject) > 0' : '';
        $sql    = "SELECT
                       s.student_id,
                       s.first_name,
                       s.last_name,
                       s.stream,
                       s.subject_combination,
                       GROUP_CONCAT(sa.subject ORDER BY sa.subject SEPARATOR '{$sep}') AS subjects,
                       COUNT(sa.subject) AS subject_count
                   FROM   students s
                   LEFT JOIN student_alevel_subjects sa
                          ON  sa.student_id = s.student_id
                          AND sa.class COLLATE utf8mb4_unicode_ci
                                = s.current_class COLLATE utf8mb4_unicode_ci
                   WHERE  s.current_class = ?
                     AND  s.status        = 'Active'
                     AND  s.stream        IN ({$streamPH})
                   GROUP BY s.student_id, s.first_name, s.last_name,
                            s.stream, s.subject_combination
                   {$having}
                   ORDER BY s.stream, s.last_name, s.first_name";
        $types  = 's' . str_repeat('s', count($streams));
        $params = array_merge([$class], $streams);

    } else {
        // INNER JOIN restricts to students with at least one filter subject;
        // LEFT JOIN then gathers ALL their subjects for display.
        $filterPH = implode(',', array_fill(0, count($filterSubjs), '?'));
        $sql      = "SELECT
                         s.student_id,
                         s.first_name,
                         s.last_name,
                         s.stream,
                         s.subject_combination,
                         GROUP_CONCAT(DISTINCT sa_all.subject
                                      ORDER BY sa_all.subject
                                      SEPARATOR '{$sep}') AS subjects,
                         COUNT(DISTINCT sa_all.subject)   AS subject_count
                     FROM   students s
                     INNER JOIN student_alevel_subjects sa_filter
                             ON  sa_filter.student_id = s.student_id
                             AND sa_filter.class COLLATE utf8mb4_unicode_ci
                                   = s.current_class COLLATE utf8mb4_unicode_ci
                             AND sa_filter.subject COLLATE utf8mb4_unicode_ci
                                   IN ({$filterPH})
                     LEFT JOIN  student_alevel_subjects sa_all
                             ON  sa_all.student_id = s.student_id
                             AND sa_all.class COLLATE utf8mb4_unicode_ci
                                   = s.current_class COLLATE utf8mb4_unicode_ci
                     WHERE  s.current_class = ?
                       AND  s.status        = 'Active'
                       AND  s.stream        IN ({$streamPH})
                     GROUP BY s.student_id, s.first_name, s.last_name,
                              s.stream, s.subject_combination
                     ORDER BY s.stream, s.last_name, s.first_name";
        $types  = str_repeat('s', count($filterSubjs)) . 's' . str_repeat('s', count($streams));
        $params = array_merge($filterSubjs, [$class], $streams);
    }

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $out = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['subjects_array'] = ($row['subjects'] !== null)
            ? explode(chr(31), $row['subjects'])
            : [];
        $out[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $out;
}

// ── Reference data ─────────────────────────────────────────────────────────────
$allSubjects = getALevelSubjects($conn);
$subjects    = validateSubjects($conn, $rawSubjects);  // DB-validated

// ── Form-submitted state ───────────────────────────────────────────────────────
$formSubmitted = !empty($class)
                 && in_array($class, $availableClasses, true)
                 && !empty($streams);

// ── CSV export — must run before any HTML output ───────────────────────────────
if ($formSubmitted && ($_GET['export'] ?? '') === 'csv') {
    $rows  = getStudentsWithSubjects($conn, $class, $streams, $subjects, $onlyAssigned);
    $fname = 'alevel_subjects_' . preg_replace('/[^A-Za-z0-9]+/', '_', $class) . '_' . date('Ymd') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');

    $fh = fopen('php://output', 'w');
    fprintf($fh, "\xEF\xBB\xBF");  // UTF-8 BOM — Excel compatibility
    fputcsv($fh, ['#', 'Student ID', 'Last Name', 'First Name', 'Stream', 'Combination', 'Subjects', 'Subject Count']);
    foreach ($rows as $i => $st) {
        fputcsv($fh, [
            $i + 1,
            $st['student_id'],
            $st['last_name'],
            $st['first_name'],
            $st['stream'],
            $st['subject_combination'] ?? '',
            implode('; ', $st['subjects_array']),
            (int) $st['subject_count'],
        ]);
    }
    fclose($fh);
    exit;
}

// ── Fetch students + compute stats ────────────────────────────────────────────
$students = [];
$stats    = ['total' => 0, 'with_subjects' => 0, 'unique_subjects' => 0, 'avg' => 0, 'total_assignments' => 0];

if ($formSubmitted) {
    $students = getStudentsWithSubjects($conn, $class, $streams, $subjects, $onlyAssigned);
    $subjSeen = [];
    foreach ($students as $st) {
        $stats['total']++;
        if ((int) $st['subject_count'] > 0) {
            $stats['with_subjects']++;
            $stats['total_assignments'] += (int) $st['subject_count'];
        }
        foreach ($st['subjects_array'] as $subj) {
            $subjSeen[$subj] = true;
        }
    }
    $stats['unique_subjects'] = count($subjSeen);
    $stats['avg'] = $stats['with_subjects'] > 0
        ? round($stats['total_assignments'] / $stats['with_subjects'], 1)
        : 0;
}

// ── Export URL (same GET params + export=csv) ──────────────────────────────────
$baseParams = array_filter($_GET, fn($k) => $k !== 'export', ARRAY_FILTER_USE_KEY);
$exportUrl  = 'view_alevel_subjects.php?' . http_build_query(array_merge($baseParams, ['export' => 'csv']));

// ── Precompute subject → colour slot (used in template) ───────────────────────
$subjectColors = [];
foreach ($allSubjects as $s) {
    $subjectColors[$s] = subjectColorSlot($s);
}

// Quick-check: is a subject in the user's filter set?
$filterSet = array_flip($subjects);  // O(1) lookup
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View A-Level Subjects &mdash; SchoolPilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
<style>
/* ═══════════════════════════════════════════════════════════════════════════════
   Design tokens — identical to the rest of the A-Level module
   ═══════════════════════════════════════════════════════════════════════════════ */
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

/* Subject tag colour palette (8 slots) */
.st[data-c="0"]{background:#e8f5e9;border-color:#a5d6a7;color:#2e7d32}
.st[data-c="1"]{background:#e0f2f1;border-color:#80cbc4;color:#00695c}
.st[data-c="2"]{background:#e3f2fd;border-color:#90caf9;color:#1565c0}
.st[data-c="3"]{background:#ede7f6;border-color:#ce93d8;color:#6a1b9a}
.st[data-c="4"]{background:#fff3e0;border-color:#ffcc80;color:#e65100}
.st[data-c="5"]{background:#fce4ec;border-color:#f48fb1;color:#880e4f}
.st[data-c="6"]{background:#e8eaf6;border-color:#9fa8da;color:#283593}
.st[data-c="7"]{background:#e0f7fa;border-color:#80deea;color:#006064}
/* Active filter subjects are highlighted */
.st.st-active{background:var(--g700)!important;border-color:var(--g700)!important;color:#fff!important;font-weight:700}

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
    display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;
}
.page-header-text h1{color:#fff;font-size:1.45rem;font-weight:700;line-height:1.25;display:flex;align-items:center;gap:10px}
.page-header-text h1 i{opacity:.82}
.page-header-text p{color:rgba(255,255,255,.72);font-size:.84rem;margin-top:6px}
.header-link{
    display:inline-flex;align-items:center;gap:7px;
    color:rgba(255,255,255,.85);font-size:.82rem;font-weight:600;
    text-decoration:none;
    padding:8px 16px;border-radius:var(--radius);
    border:1.5px solid rgba(255,255,255,.35);
    transition:background var(--transition),border-color var(--transition);
    white-space:nowrap;
}
.header-link:hover{background:rgba(255,255,255,.18);border-color:rgba(255,255,255,.6);color:#fff}

/* ── Card ── */
.card{
    background:#fff;border-radius:var(--radius-lg);
    box-shadow:var(--shadow);border:1px solid rgba(0,0,0,.05);
    margin-bottom:20px;overflow:visible;
}
.card-header{
    display:flex;align-items:center;gap:12px;
    padding:18px 28px 16px;border-bottom:1px solid var(--gray-200);
}
.card-header-icon{
    width:36px;height:36px;background:var(--g100);border-radius:9px;
    display:flex;align-items:center;justify-content:center;color:var(--g700);font-size:.92rem;flex-shrink:0;
}
.card-header-title{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--gray-600)}
.card-header-sub{font-size:.82rem;color:var(--gray-600);margin-top:1px}
.card-body{padding:26px 28px 28px}

/* ── Form ── */
.form-grid{display:grid;grid-template-columns:1fr 1fr 1fr;column-gap:20px}
.form-group{display:flex;flex-direction:column;margin-bottom:20px}
.form-group.span2{grid-column:span 2}
.form-group.full{grid-column:1/-1}

.form-label{
    display:block;font-size:.82rem;font-weight:600;
    color:var(--gray-600);margin-bottom:6px;letter-spacing:.02em;user-select:none;
}
.form-label .req{color:var(--danger);margin-left:2px;font-weight:700}
.form-field{position:relative;min-width:0}

.form-control{
    width:100%;height:var(--field-h);padding:0 36px 0 13px;
    border:1.5px solid var(--gray-300);border-radius:var(--radius);
    font-size:.9rem;font-family:inherit;background:#fff;color:var(--text);
    transition:border-color var(--transition),box-shadow var(--transition);
    appearance:none;-webkit-appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236b7c6d' stroke-width='1.6' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 13px center;cursor:pointer;
}
.form-control:hover:not(:focus){border-color:var(--gray-600)}
.form-control:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.16)}
.form-control.is-invalid{border-color:var(--danger);background-color:var(--danger-bg)}
.error-msg{font-size:.78rem;color:var(--danger);font-weight:500;margin-top:6px;display:none;align-items:center;gap:5px}
.error-msg i{font-size:.7rem;flex-shrink:0}

/* ─── Stream multi-select (same widget as assign page) ────────────────────── */
.stream-selector{position:relative}
.stream-trigger{
    width:100%;height:var(--field-h);padding:0 38px 0 13px;
    border:1.5px solid var(--gray-300);border-radius:var(--radius);
    background:#fff;display:flex;align-items:center;gap:6px;
    cursor:pointer;user-select:none;
    transition:border-color var(--transition),box-shadow var(--transition),border-radius var(--transition);
}
.stream-trigger:hover{border-color:var(--gray-600)}
.stream-selector.open .stream-trigger{border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.16);border-bottom-left-radius:0;border-bottom-right-radius:0}
.stream-selector.invalid .stream-trigger{border-color:var(--danger);background-color:var(--danger-bg)}
.stream-arrow{position:absolute;right:13px;top:50%;transform:translateY(-50%);color:var(--gray-600);font-size:.72rem;pointer-events:none;transition:transform .28s cubic-bezier(.4,0,.2,1)}
.stream-selector.open .stream-arrow{transform:translateY(-50%) rotate(180deg)}
.stream-trigger-content{display:flex;align-items:center;gap:5px;flex:1;min-width:0;overflow:hidden}
.stream-placeholder{font-size:.9rem;color:#aab8ac;white-space:nowrap}
.stream-tag{display:inline-flex;align-items:center;gap:4px;background:var(--g100);color:var(--g800);border:1px solid #b3dab9;border-radius:5px;padding:2px 9px;font-size:.78rem;font-weight:600;white-space:nowrap;flex-shrink:0}
.stream-panel{
    position:absolute;top:calc(var(--field-h) - 1.5px);left:0;right:0;z-index:300;
    background:#fff;border:1.5px solid var(--g600);border-top:1px solid var(--gray-200);
    border-radius:0 0 var(--radius) var(--radius);box-shadow:0 14px 36px rgba(0,0,0,.14);
    overflow:hidden;max-height:0;opacity:0;pointer-events:none;
    transition:max-height .3s cubic-bezier(.4,0,.2,1),opacity .22s ease;
}
.stream-selector.open .stream-panel{max-height:400px;opacity:1;pointer-events:auto}
.stream-option{display:flex;align-items:center;gap:12px;padding:12px 16px;cursor:pointer;font-size:.9rem;color:var(--text);border-bottom:1px solid var(--gray-200);transition:background var(--transition)}
.stream-option:last-child{border-bottom:none}
.stream-option:hover{background:var(--g50)}
.stream-option.selected{background:linear-gradient(90deg,var(--g800),var(--g700));color:#fff;font-weight:600}
.opt-check{width:18px;height:18px;border-radius:4px;flex-shrink:0;display:flex;align-items:center;justify-content:center;border:2px solid var(--gray-300);background:#fff;transition:border-color var(--transition),background var(--transition)}
.stream-option.selected .opt-check{background:rgba(255,255,255,.25);border-color:rgba(255,255,255,.6)}
.opt-check-icon{font-size:.6rem;color:#fff;opacity:0;transition:opacity .15s ease}
.stream-option.selected .opt-check-icon{opacity:1}
.stream-option input[type="checkbox"]{position:absolute;opacity:0;width:0;height:0;pointer-events:none}
.opt-label{flex:1;pointer-events:none}
.opt-icon{font-size:.82rem;opacity:.55}
.stream-option.selected .opt-icon{opacity:.8}
.stream-actions{display:flex;align-items:center;gap:4px;margin-top:6px;font-size:.8rem}
.stream-actions a{color:var(--g700);text-decoration:none;font-weight:600;padding:2px 7px;border-radius:4px;transition:background var(--transition)}
.stream-actions a:hover{background:var(--g100)}
.stream-actions .sep{color:var(--gray-400)}

/* ─── Subject multi-select (new — with in-panel search) ──────────────────── */
.subj-selector{position:relative}
.subj-trigger{
    width:100%;min-height:var(--field-h);padding:6px 38px 6px 13px;
    border:1.5px solid var(--gray-300);border-radius:var(--radius);
    background:#fff;display:flex;align-items:center;gap:6px;flex-wrap:wrap;
    cursor:pointer;user-select:none;
    transition:border-color var(--transition),box-shadow var(--transition),border-radius var(--transition);
}
.subj-trigger:hover{border-color:var(--gray-600)}
.subj-selector.open .subj-trigger{border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.16);border-bottom-left-radius:0;border-bottom-right-radius:0}
.subj-arrow{position:absolute;right:13px;top:13px;color:var(--gray-600);font-size:.72rem;pointer-events:none;transition:transform .28s cubic-bezier(.4,0,.2,1)}
.subj-selector.open .subj-arrow{transform:rotate(180deg)}
.subj-placeholder{font-size:.9rem;color:#aab8ac;white-space:nowrap;pointer-events:none}
.subj-tag-pill{
    display:inline-flex;align-items:center;gap:5px;
    background:var(--g700);color:#fff;border-radius:5px;
    padding:2px 9px;font-size:.76rem;font-weight:600;white-space:nowrap;
    cursor:pointer;flex-shrink:0;
}
.subj-tag-pill .rm{opacity:.7;font-size:.65rem}
.subj-tag-pill:hover .rm{opacity:1}
.subj-count-badge{
    display:inline-flex;align-items:center;
    background:var(--g100);color:var(--g800);border:1px solid #b3dab9;
    border-radius:5px;padding:2px 10px;font-size:.78rem;font-weight:700;
}
/* Dropdown panel */
.subj-panel{
    position:absolute;top:calc(100% - 1.5px);left:0;right:0;z-index:300;
    background:#fff;border:1.5px solid var(--g600);border-top:1px solid var(--gray-200);
    border-radius:0 0 var(--radius) var(--radius);box-shadow:0 14px 36px rgba(0,0,0,.14);
    display:none;flex-direction:column;
}
.subj-selector.open .subj-panel{display:flex}
.subj-search-wrap{padding:10px 12px;border-bottom:1px solid var(--gray-200);position:relative;flex-shrink:0}
.subj-search-wrap i{position:absolute;left:21px;top:50%;transform:translateY(-50%);color:var(--gray-600);font-size:.8rem;pointer-events:none}
.subj-search-input{width:100%;height:34px;padding:0 10px 0 32px;border:1.5px solid var(--gray-300);border-radius:var(--radius);font-size:.85rem;font-family:inherit;transition:border-color var(--transition)}
.subj-search-input:focus{outline:none;border-color:var(--g600)}
.subj-opts-list{overflow-y:auto;max-height:260px;flex:1}
.subj-opt{display:flex;align-items:center;gap:11px;padding:10px 14px;cursor:pointer;font-size:.87rem;border-bottom:1px solid var(--gray-200);transition:background var(--transition)}
.subj-opt:last-child{border-bottom:none}
.subj-opt:hover{background:var(--g50)}
.subj-opt.selected{background:linear-gradient(90deg,var(--g800),var(--g700));color:#fff}
.subj-opt input[type="checkbox"]{position:absolute;opacity:0;width:0;height:0;pointer-events:none}
.subj-opt .s-check{width:16px;height:16px;border-radius:3px;border:2px solid var(--gray-300);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.subj-opt.selected .s-check{border-color:rgba(255,255,255,.6);background:rgba(255,255,255,.2)}
.s-check-ico{font-size:.55rem;color:#fff;opacity:0}
.subj-opt.selected .s-check-ico{opacity:1}
.subj-opt .s-label{flex:1;pointer-events:none}
.subj-opt.hidden{display:none}
.subj-no-match{padding:20px;text-align:center;color:var(--gray-600);font-size:.85rem;display:none}
.subj-panel-footer{
    padding:8px 14px;border-top:1px solid var(--gray-200);
    display:flex;align-items:center;justify-content:space-between;flex-shrink:0;
    background:var(--gray-50);
}
.subj-panel-footer a{color:var(--g700);text-decoration:none;font-size:.78rem;font-weight:600;padding:3px 8px;border-radius:4px;transition:background var(--transition)}
.subj-panel-footer a:hover{background:var(--g100)}
.subj-panel-footer .sep{color:var(--gray-300);margin:0 2px}
.subj-panel-footer .close-panel{
    display:inline-flex;align-items:center;gap:5px;
    padding:4px 12px;border-radius:var(--radius);
    background:var(--g700);color:#fff;font-size:.78rem;font-weight:600;cursor:pointer;border:none;
    transition:background var(--transition);
}
.subj-panel-footer .close-panel:hover{background:var(--g800)}

/* ── Checkbox row ── */
.check-row{display:flex;align-items:center;gap:10px;margin-bottom:20px}
.check-row input[type="checkbox"]{width:16px;height:16px;accent-color:var(--g700);cursor:pointer;flex-shrink:0}
.check-row label{font-size:.87rem;color:var(--text);cursor:pointer}

/* ── Buttons ── */
.divider{border:none;border-top:1px solid var(--gray-200);margin:24px 0 20px}
.btn-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:8px;padding:0 24px;height:var(--field-h);border-radius:var(--radius);font-size:.875rem;font-weight:600;font-family:inherit;cursor:pointer;border:1.5px solid transparent;white-space:nowrap;text-decoration:none;transition:background var(--transition),box-shadow var(--transition),transform .15s ease}
.btn-primary{background:var(--g700);color:#fff;border-color:var(--g700)}
.btn-primary:hover:not(:disabled){background:var(--g800);border-color:var(--g800);box-shadow:0 4px 16px rgba(46,125,50,.35);transform:translateY(-1px)}
.btn-primary:disabled{opacity:.72;cursor:not-allowed}
.btn-outline-green{background:#fff;color:var(--g700);border-color:var(--g400)}
.btn-outline-green:hover{background:var(--g100);border-color:var(--g700)}
.btn-export{background:#fff;color:var(--gray-600);border-color:var(--gray-300)}
.btn-export:hover{background:var(--gray-100);border-color:var(--gray-600);color:var(--text)}
.btn-spinner{display:none;width:15px;height:15px;border:2.5px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .65s linear infinite;flex-shrink:0}
.btn.loading .btn-spinner{display:block}
.btn.loading .btn-icon{display:none}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Selection summary bar ── */
.selection-bar{
    display:flex;align-items:center;flex-wrap:wrap;gap:12px;
    background:#fff;border-radius:var(--radius-lg);
    border:1px solid rgba(0,0,0,.05);box-shadow:var(--shadow-sm);
    padding:14px 22px;margin-bottom:16px;
}
.sel-chips{display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex:1}
.sel-chip{display:inline-flex;align-items:center;gap:6px;background:var(--g100);color:var(--g800);border:1px solid #b3dab9;border-radius:6px;padding:5px 12px;font-size:.8rem;font-weight:600}
.sel-chip i{opacity:.7;font-size:.72rem}
.sel-chip.chip-class{background:var(--g800);color:#fff;border-color:var(--g800)}
.sel-chip.chip-subj{background:var(--g600);color:#fff;border-color:var(--g600);font-size:.74rem}
.sel-divider{width:1px;height:28px;background:var(--gray-200);flex-shrink:0}
.change-btn{display:inline-flex;align-items:center;gap:6px;padding:0 16px;height:34px;border-radius:var(--radius);font-size:.8rem;font-weight:600;font-family:inherit;cursor:pointer;background:#fff;color:var(--g700);border:1.5px solid var(--g400);transition:all var(--transition);white-space:nowrap;text-decoration:none}
.change-btn:hover{background:var(--g100);border-color:var(--g700);color:var(--g800)}

/* ── Stats bar ── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}
.stat-card{background:#fff;border-radius:var(--radius-lg);border:1px solid rgba(0,0,0,.05);box-shadow:var(--shadow-sm);padding:18px 20px;display:flex;align-items:center;gap:14px}
.stat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.si-green{background:var(--g100);color:var(--g700)}
.si-teal{background:#e0f2f1;color:#00695c}
.si-blue{background:#e3f2fd;color:#1565c0}
.si-orange{background:#fff3e0;color:#e65100}
.stat-value{font-size:1.6rem;font-weight:800;color:var(--text);line-height:1}
.stat-label{font-size:.74rem;color:var(--gray-600);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-top:3px}

/* ── Students card ── */
.students-card .card-header{background:linear-gradient(135deg,var(--g900),var(--g700));border-radius:var(--radius-lg) var(--radius-lg) 0 0;padding:18px 28px;border-bottom:none}
.students-card .card-header-icon{background:rgba(255,255,255,.18);color:#fff}
.students-card .card-header-title{color:rgba(255,255,255,.8);letter-spacing:.6px}
.students-card .card-header-sub{color:#fff;font-size:.95rem;font-weight:700}

/* ── Toolbar ── */
.toolbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:14px 28px;background:var(--g50);border-bottom:1px solid var(--gray-200)}
.search-wrap{flex:1;min-width:180px;max-width:380px;position:relative}
.search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--gray-600);font-size:.82rem;pointer-events:none}
.search-input{width:100%;height:36px;padding:0 12px 0 34px;border:1.5px solid var(--gray-300);border-radius:20px;font-size:.85rem;font-family:inherit;transition:border-color var(--transition)}
.search-input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.16)}
.filter-info{font-size:.8rem;color:var(--gray-600);margin-left:auto;white-space:nowrap}

/* ── Table ── */
.table-wrap{overflow-x:auto}
table.students-tbl{width:100%;border-collapse:collapse}
table.students-tbl thead th{background:var(--g800);color:#fff;padding:12px 16px;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;user-select:none}
table.students-tbl thead th.sortable{cursor:pointer;transition:background var(--transition)}
table.students-tbl thead th.sortable:hover{background:var(--g700)}
table.students-tbl thead th .sort-icon{margin-left:6px;font-size:.68rem;opacity:.5}
table.students-tbl thead th.sort-asc  .sort-icon::before{content:"\f0de"}
table.students-tbl thead th.sort-desc .sort-icon::before{content:"\f0dd"}
table.students-tbl thead th.sort-asc  .sort-icon,
table.students-tbl thead th.sort-desc .sort-icon{opacity:1}
table.students-tbl tbody td{padding:12px 16px;border-bottom:1px solid var(--gray-200);font-size:.875rem;vertical-align:middle}
table.students-tbl tbody tr:hover td{background:var(--g50)}
table.students-tbl tbody tr:last-child td{border-bottom:none}

.col-no{width:50px;color:var(--gray-600);font-size:.8rem}
.col-id{width:120px;font-size:.8rem;color:var(--gray-600);font-weight:600}
.col-stream{width:100px}
.col-combo{width:140px}
.col-count{width:80px;text-align:center}

/* Badges */
.stream-badge{display:inline-flex;align-items:center;padding:3px 11px;background:var(--g100);color:var(--g800);border:1px solid #b3dab9;border-radius:12px;font-size:.75rem;font-weight:700}
.combo-badge{display:inline-flex;align-items:center;padding:3px 10px;background:#ede7f6;color:#6a1b9a;border:1px solid #ce93d8;border-radius:12px;font-size:.75rem;font-weight:600}
.combo-badge.empty{background:var(--gray-100);color:var(--gray-600);border-color:var(--gray-300)}
.count-badge{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:var(--g100);color:var(--g800);font-size:.8rem;font-weight:700;border:1.5px solid #b3dab9}
.count-badge.zero{background:var(--gray-100);color:var(--gray-600);border-color:var(--gray-300)}

/* Subject tags */
.subj-tags{display:flex;flex-wrap:wrap;gap:5px}
.st{display:inline-flex;align-items:center;padding:3px 9px;border-radius:6px;font-size:.74rem;font-weight:600;border:1px solid;white-space:nowrap}
.no-subjects{font-size:.8rem;color:var(--gray-600);font-style:italic}

/* ── Empty state ── */
.empty-state{text-align:center;padding:60px 32px;color:var(--gray-600)}
.empty-state i{font-size:2.8rem;color:var(--g400);opacity:.45;display:block;margin-bottom:14px}
.empty-state h3{font-size:1rem;font-weight:600;color:var(--text);margin-bottom:6px}
.empty-state p{font-size:.87rem}

/* ── Pagination ── */
.pagination-row{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;padding:14px 28px;border-top:1px solid var(--gray-200)}
.page-info{font-size:.8rem;color:var(--gray-600)}
.page-btns{display:flex;gap:5px}
.page-btn{min-width:33px;height:33px;padding:0 8px;border:1.5px solid var(--gray-300);border-radius:var(--radius);background:#fff;color:var(--text);font-size:.8rem;font-weight:600;cursor:pointer;transition:all var(--transition);font-family:inherit}
.page-btn:hover:not(:disabled){border-color:var(--g600);color:var(--g700)}
.page-btn.active{background:var(--g700);color:#fff;border-color:var(--g700)}
.page-btn:disabled{opacity:.45;cursor:not-allowed}

/* ── Toast ── */
#toast{position:fixed;bottom:28px;right:28px;z-index:9999;background:var(--g800);color:#fff;padding:11px 20px;border-radius:var(--radius);font-size:.85rem;font-weight:600;box-shadow:0 6px 24px rgba(0,0,0,.2);transform:translateY(16px);opacity:0;pointer-events:none;transition:opacity .25s ease,transform .25s ease}
#toast.show{opacity:1;transform:none}
#toast.toast-error{background:var(--danger)}

/* ── Responsive ── */
@media(max-width:900px){.form-grid{grid-template-columns:1fr 1fr}.form-group.span2{grid-column:auto}.stats-row{grid-template-columns:1fr 1fr}}
@media(max-width:640px){
    .page{padding:16px 12px 60px}
    .page-header{flex-direction:column;padding:20px 22px;margin-top:56px}
    .form-grid{grid-template-columns:1fr}
    .stats-row{grid-template-columns:1fr 1fr}
    .card-header,.card-body,.toolbar,.pagination-row{padding-left:16px;padding-right:16px}
    .col-combo,.col-id{display:none}
    .selection-bar{padding:12px 14px}
}
@media print{
    .page-header,.selection-bar,.toolbar,.pagination-row,#toast,.card-header .btn,.header-link{display:none!important}
    body{background:#fff}
    .card{box-shadow:none;border:1px solid #ddd}
    .students-card .card-header{display:flex!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>
</head>
<body>

<?php require_once '../nav.php'; ?>

<div class="page">

    <!-- ══ Page Header ════════════════════════════════════════════════════════ -->
    <div class="page-header">
        <div class="page-header-text">
            <h1><i class="fas fa-table-list"></i>A-Level Subject View</h1>
            <p>Filter students by class, stream and subject to see who does what</p>
        </div>
        <a href="assign_alevel_subjects.php" class="header-link">
            <i class="fas fa-user-tag"></i> Assign Subjects
        </a>
    </div>

    <?php if (!$formSubmitted): ?>
    <!-- ══ Filter Card ════════════════════════════════════════════════════════ -->
    <div class="card">
        <div class="card-header">
            <div class="card-header-icon"><i class="fas fa-filter"></i></div>
            <div>
                <div class="card-header-title">Filter</div>
                <div class="card-header-sub">Choose class, stream(s) and optionally subjects</div>
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

                    <!-- Streams -->
                    <div class="form-group span2">
                        <label class="form-label" id="streamLabel">Stream(s) <span class="req">*</span></label>
                        <div class="form-field">
                            <div class="stream-selector" id="streamSelector" role="combobox" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="streamLabel">
                                <div class="stream-trigger" id="streamTrigger" tabindex="0" role="button">
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
                                         data-value="<?= $safe($s) ?>" role="option" aria-selected="<?= $sel ? 'true' : 'false' ?>">
                                        <span class="opt-check"><i class="fas fa-check opt-check-icon"></i></span>
                                        <input type="checkbox" name="streams[]" value="<?= $safe($s) ?>" id="stream-<?= $safe($s) ?>" <?= $sel ? 'checked' : '' ?> tabindex="-1" aria-hidden="true">
                                        <i class="fas <?= $icon ?> opt-icon" aria-hidden="true"></i>
                                        <label class="opt-label" for="stream-<?= $safe($s) ?>"><?= $safe($s) ?></label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="stream-actions">
                                <a href="#" id="selAllStreams">Select All</a>
                                <span class="sep">|</span>
                                <a href="#" id="deselAllStreams">Clear</a>
                            </div>
                            <p class="error-msg" id="streamError"><i class="fas fa-exclamation-circle"></i> Please select at least one stream.</p>
                        </div>
                    </div>

                    <!-- Subjects (optional multi-select with search) -->
                    <div class="form-group full">
                        <label class="form-label" id="subjLabel">
                            Filter by Subject(s)
                            <span style="font-weight:400;color:var(--gray-600);font-size:.78rem;margin-left:6px">(optional — leave blank to show all)</span>
                        </label>
                        <div class="form-field">
                            <div class="subj-selector" id="subjSelector">
                                <div class="subj-trigger" id="subjTrigger" tabindex="0" role="button" aria-haspopup="listbox" aria-expanded="false">
                                    <div id="subjTriggerContent">
                                        <span class="subj-placeholder">All subjects — no filter applied</span>
                                    </div>
                                    <i class="fas fa-chevron-down subj-arrow" aria-hidden="true"></i>
                                </div>

                                <div class="subj-panel" id="subjPanel" role="listbox" aria-multiselectable="true">
                                    <div class="subj-search-wrap">
                                        <i class="fas fa-search"></i>
                                        <input type="text" class="subj-search-input" id="subjSearch" placeholder="Search subjects…" autocomplete="off">
                                    </div>
                                    <div class="subj-opts-list" id="subjOptsList">
                                        <?php foreach ($allSubjects as $subj):
                                            $sel = in_array($subj, $subjects, true);
                                        ?>
                                        <div class="subj-opt<?= $sel ? ' selected' : '' ?>" data-value="<?= $safe($subj) ?>" role="option" aria-selected="<?= $sel ? 'true' : 'false' ?>">
                                            <span class="s-check"><i class="fas fa-check s-check-ico"></i></span>
                                            <input type="checkbox" name="subjects[]" value="<?= $safe($subj) ?>" <?= $sel ? 'checked' : '' ?> tabindex="-1" aria-hidden="true">
                                            <span class="s-label"><?= $safe($subj) ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="subj-no-match" id="subjNoMatch">No subjects match your search.</p>
                                    <div class="subj-panel-footer">
                                        <div>
                                            <a href="#" id="selAllSubjs">Select All</a>
                                            <span class="sep">|</span>
                                            <a href="#" id="deselAllSubjs">Clear</a>
                                        </div>
                                        <button type="button" class="close-panel" id="closeSubjPanel">
                                            <i class="fas fa-check"></i> Done
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div><!-- /form-grid -->

                <!-- Only-assigned toggle -->
                <div class="check-row">
                    <input type="checkbox" name="only_assigned" id="only_assigned" value="1" <?= $onlyAssigned ? 'checked' : '' ?>>
                    <label for="only_assigned">Only show students who have at least one subject assigned</label>
                </div>

                <hr class="divider">

                <div class="btn-row">
                    <button type="submit" class="btn btn-primary" id="loadBtn">
                        <span class="btn-spinner" aria-hidden="true"></span>
                        <i class="fas fa-search btn-icon" aria-hidden="true"></i>
                        <span class="btn-label">View Students</span>
                    </button>
                </div>

            </form>
        </div>
    </div>
    <?php endif; /* !formSubmitted */ ?>


    <?php if ($formSubmitted): ?>

    <!-- ══ Selection Summary Bar ══════════════════════════════════════════════ -->
    <div class="selection-bar">
        <div class="sel-chips">
            <span class="sel-chip chip-class"><i class="fas fa-school"></i>&nbsp;<?= $safe($class) ?></span>
            <?php foreach ($streams as $s): ?>
            <span class="sel-chip"><i class="fas fa-layer-group"></i>&nbsp;<?= $safe($s) ?></span>
            <?php endforeach; ?>
            <?php if (!empty($subjects)): ?>
                <span style="color:var(--gray-400);font-size:.8rem;margin:0 4px">&#9654;</span>
                <?php foreach ($subjects as $subj): ?>
                <span class="sel-chip chip-subj"><i class="fas fa-book"></i>&nbsp;<?= $safe($subj) ?></span>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($onlyAssigned): ?>
            <span class="sel-chip" style="background:#fff3e0;color:#e65100;border-color:#ffcc80">
                <i class="fas fa-filter"></i>&nbsp;Assigned only
            </span>
            <?php endif; ?>
        </div>
        <div class="sel-divider"></div>
        <a href="view_alevel_subjects.php" class="change-btn"><i class="fas fa-pen"></i> Change Selection</a>
    </div>

    <!-- ══ Stats Bar ══════════════════════════════════════════════════════════ -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon si-green"><i class="fas fa-users"></i></div>
            <div>
                <div class="stat-value"><?= $stats['total'] ?></div>
                <div class="stat-label">Students Found</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon si-teal"><i class="fas fa-user-check"></i></div>
            <div>
                <div class="stat-value"><?= $stats['with_subjects'] ?></div>
                <div class="stat-label">With Subjects</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon si-blue"><i class="fas fa-book-open"></i></div>
            <div>
                <div class="stat-value"><?= $stats['unique_subjects'] ?></div>
                <div class="stat-label">Subjects Covered</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon si-orange"><i class="fas fa-chart-bar"></i></div>
            <div>
                <div class="stat-value"><?= $stats['avg'] ?></div>
                <div class="stat-label">Avg Subjects / Student</div>
            </div>
        </div>
    </div>

    <!-- ══ Students Card ══════════════════════════════════════════════════════ -->
    <div class="card students-card">

        <div class="card-header">
            <div class="card-header-icon"><i class="fas fa-users"></i></div>
            <div style="flex:1">
                <div class="card-header-title">Students</div>
                <div class="card-header-sub">
                    <?= $safe($class) ?>
                    &mdash; <?= $safe(implode(', ', $streams)) ?>
                    <?php if (!empty($subjects)): ?>
                    &mdash; <span style="opacity:.8"><?= count($subjects) === 1 ? $safe($subjects[0]) : count($subjects) . ' subjects' ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($students)): ?>
            <a href="<?= $safe($exportUrl) ?>" class="btn btn-export" style="height:36px;font-size:.8rem">
                <i class="fas fa-file-csv"></i> Export CSV
            </a>
            <button type="button" class="btn btn-export" style="height:36px;font-size:.8rem" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
            <?php endif; ?>
        </div>

        <?php if (count($students) > 0): ?>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" class="search-input" id="studentSearch" placeholder="Search by name, ID or subject…">
            </div>
            <span class="filter-info" id="filterInfo"></span>
        </div>

        <!-- Table -->
        <div class="table-wrap">
            <table class="students-tbl" id="studentsTable">
                <thead>
                    <tr>
                        <th class="col-no">#</th>
                        <th class="sortable" data-sort="name">
                            Student Name <i class="fas fa-sort sort-icon"></i>
                        </th>
                        <th class="col-id">Student ID</th>
                        <th class="col-stream sortable" data-sort="stream">
                            Stream <i class="fas fa-sort sort-icon"></i>
                        </th>
                        <th class="col-combo">Combination</th>
                        <th>Subjects <?php if (!empty($subjects)): ?><span style="opacity:.65;font-weight:400;font-size:.72rem">(▸ highlighted)</span><?php endif; ?></th>
                        <th class="col-count sortable" data-sort="count">
                            # <i class="fas fa-sort sort-icon"></i>
                        </th>
                    </tr>
                </thead>
                <tbody id="tBody">
                    <?php foreach ($students as $idx => $st):
                        $combo = trim($st['subject_combination'] ?? '');
                    ?>
                    <tr class="student-row"
                        data-name="<?= $safe(strtolower($st['last_name'] . ' ' . $st['first_name'])) ?>"
                        data-id="<?= $safe(strtolower($st['student_id'])) ?>"
                        data-stream="<?= $safe(strtolower($st['stream'])) ?>"
                        data-subjects="<?= $safe(strtolower(implode(' ', $st['subjects_array']))) ?>"
                        data-count="<?= (int)$st['subject_count'] ?>">
                        <td class="col-no"><?= $idx + 1 ?></td>
                        <td style="font-weight:600;color:var(--text)">
                            <?= $safe($st['last_name'] . ', ' . $st['first_name']) ?>
                        </td>
                        <td class="col-id"><?= $safe($st['student_id']) ?></td>
                        <td class="col-stream">
                            <span class="stream-badge"><?= $safe($st['stream']) ?></span>
                        </td>
                        <td class="col-combo">
                            <?php if ($combo): ?>
                                <span class="combo-badge"><?= $safe($combo) ?></span>
                            <?php else: ?>
                                <span class="combo-badge empty">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($st['subjects_array'])): ?>
                            <div class="subj-tags">
                                <?php foreach ($st['subjects_array'] as $subj):
                                    $slot      = $subjectColors[$subj] ?? subjectColorSlot($subj);
                                    $isFiltered = isset($filterSet[$subj]);
                                ?>
                                <span class="st<?= $isFiltered ? ' st-active' : '' ?>"
                                      data-c="<?= $slot ?>">
                                    <?= $safe($subj) ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                                <span class="no-subjects">No subjects assigned</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-count">
                            <span class="count-badge<?= $st['subject_count'] == 0 ? ' zero' : '' ?>">
                                <?= (int)$st['subject_count'] ?>
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
        <!-- Empty state -->
        <div class="empty-state">
            <i class="fas fa-user-slash"></i>
            <h3>No students found</h3>
            <p>
                No active students in <strong><?= $safe($class) ?></strong>
                (<?= $safe(implode(', ', $streams)) ?>)
                <?php if (!empty($subjects)): ?>
                match the selected subject filter.
                <?php elseif ($onlyAssigned): ?>
                have subjects assigned yet.
                <?php else: ?>
                were found.
                <?php endif; ?>
            </p>
        </div>
        <?php endif; /* count($students) > 0 */ ?>

    </div><!-- /students-card -->
    <?php endif; /* formSubmitted */ ?>

</div><!-- /page -->

<!-- Toast -->
<div id="toast"><span id="toastMsg"></span></div>

<script src="../assets/js/jquery.min.js"></script>
<script>
(function ($) {
    'use strict';

    /* ═══════════════════════════════════════════════════════════════════════
       Stream dropdown controller (identical to assign_alevel_subjects.php)
       ═══════════════════════════════════════════════════════════════════════ */
    const streamSel   = document.getElementById('streamSelector');
    const streamTrig  = document.getElementById('streamTrigger');
    const streamPanel = document.getElementById('streamPanel');
    const streamCont  = document.getElementById('streamTriggerContent');
    const streamErr   = document.getElementById('streamError');

    if (streamSel) {
        function refreshStreamTrigger() {
            const checked = streamPanel.querySelectorAll('input[type="checkbox"]:checked');
            streamCont.innerHTML = '';
            if (checked.length === 0) {
                const ph = document.createElement('span');
                ph.className   = 'stream-placeholder';
                ph.textContent = 'Select streams…';
                streamCont.appendChild(ph);
            } else {
                checked.forEach(cb => {
                    const tag       = document.createElement('span');
                    tag.className   = 'stream-tag';
                    tag.textContent = cb.value;
                    streamCont.appendChild(tag);
                });
            }
        }
        function setStreamOpen(open) {
            streamSel.classList.toggle('open', open);
            streamSel.setAttribute('aria-expanded', open);
        }
        streamTrig.addEventListener('click', e => { e.stopPropagation(); setStreamOpen(!streamSel.classList.contains('open')); });
        streamTrig.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setStreamOpen(!streamSel.classList.contains('open')); }
            if (e.key === 'Escape') setStreamOpen(false);
        });
        streamPanel.addEventListener('click', e => {
            const opt = e.target.closest('.stream-option');
            if (!opt) return;
            const cb = opt.querySelector('input');
            cb.checked = !cb.checked;
            opt.classList.toggle('selected', cb.checked);
            opt.setAttribute('aria-selected', cb.checked);
            refreshStreamTrigger();
            if (cb.checked) { streamSel.classList.remove('invalid'); streamErr.style.display = 'none'; }
        });
        document.addEventListener('click', e => { if (!streamSel.contains(e.target)) setStreamOpen(false); });
        document.getElementById('selAllStreams').addEventListener('click', e => {
            e.preventDefault();
            streamPanel.querySelectorAll('.stream-option').forEach(o => {
                o.querySelector('input').checked = true;
                o.classList.add('selected');
                o.setAttribute('aria-selected', 'true');
            });
            refreshStreamTrigger();
            streamSel.classList.remove('invalid');
            streamErr.style.display = 'none';
        });
        document.getElementById('deselAllStreams').addEventListener('click', e => {
            e.preventDefault();
            streamPanel.querySelectorAll('.stream-option').forEach(o => {
                o.querySelector('input').checked = false;
                o.classList.remove('selected');
                o.setAttribute('aria-selected', 'false');
            });
            refreshStreamTrigger();
        });
        refreshStreamTrigger();
    }

    /* ═══════════════════════════════════════════════════════════════════════
       Subject multi-select with in-panel search
       ═══════════════════════════════════════════════════════════════════════ */
    const subjSel    = document.getElementById('subjSelector');
    const subjTrig   = document.getElementById('subjTrigger');
    const subjPanel  = document.getElementById('subjPanel');
    const subjCont   = document.getElementById('subjTriggerContent');
    const subjSearch = document.getElementById('subjSearch');
    const subjList   = document.getElementById('subjOptsList');
    const noMatch    = document.getElementById('subjNoMatch');

    if (subjSel) {
        function getCheckedSubjects() {
            return Array.from(subjList.querySelectorAll('input:checked'));
        }

        function refreshSubjTrigger() {
            const checked = getCheckedSubjects();
            subjCont.innerHTML = '';
            if (checked.length === 0) {
                const ph       = document.createElement('span');
                ph.className   = 'subj-placeholder';
                ph.textContent = 'All subjects — no filter applied';
                subjCont.appendChild(ph);
            } else if (checked.length <= 2) {
                checked.forEach(cb => {
                    const pill        = document.createElement('span');
                    pill.className    = 'subj-tag-pill';
                    pill.textContent  = cb.value;
                    pill.dataset.value = cb.value;
                    subjCont.appendChild(pill);
                });
            } else {
                const badge       = document.createElement('span');
                badge.className   = 'subj-count-badge';
                badge.textContent = checked.length + ' subjects selected';
                subjCont.appendChild(badge);
            }
        }

        function setSubjOpen(open) {
            subjSel.classList.toggle('open', open);
            subjTrig.setAttribute('aria-expanded', open);
            if (open) { setTimeout(() => subjSearch.focus(), 60); }
        }

        subjTrig.addEventListener('click', e => {
            e.stopPropagation();
            setSubjOpen(!subjSel.classList.contains('open'));
        });
        subjTrig.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setSubjOpen(true); }
            if (e.key === 'Escape') setSubjOpen(false);
        });

        // Click on an option
        subjList.addEventListener('click', e => {
            const opt = e.target.closest('.subj-opt');
            if (!opt) return;
            const cb = opt.querySelector('input');
            cb.checked = !cb.checked;
            opt.classList.toggle('selected', cb.checked);
            opt.setAttribute('aria-selected', cb.checked);
            refreshSubjTrigger();
        });

        // In-panel search
        subjSearch.addEventListener('input', function () {
            const q   = this.value.trim().toLowerCase();
            let visible = 0;
            subjList.querySelectorAll('.subj-opt').forEach(opt => {
                const match = opt.dataset.value.toLowerCase().includes(q);
                opt.classList.toggle('hidden', !match);
                if (match) visible++;
            });
            noMatch.style.display = visible === 0 ? 'block' : 'none';
        });

        // Close on outside click
        document.addEventListener('click', e => {
            if (!subjSel.contains(e.target)) setSubjOpen(false);
        });
        subjSearch.addEventListener('keydown', e => {
            if (e.key === 'Escape') setSubjOpen(false);
        });

        // Select all / clear (only visible options)
        document.getElementById('selAllSubjs').addEventListener('click', e => {
            e.preventDefault();
            subjList.querySelectorAll('.subj-opt:not(.hidden)').forEach(opt => {
                opt.querySelector('input').checked = true;
                opt.classList.add('selected');
                opt.setAttribute('aria-selected', 'true');
            });
            refreshSubjTrigger();
        });
        document.getElementById('deselAllSubjs').addEventListener('click', e => {
            e.preventDefault();
            subjList.querySelectorAll('.subj-opt').forEach(opt => {
                opt.querySelector('input').checked = false;
                opt.classList.remove('selected');
                opt.setAttribute('aria-selected', 'false');
            });
            refreshSubjTrigger();
        });
        document.getElementById('closeSubjPanel').addEventListener('click', () => setSubjOpen(false));

        // Stop panel clicks propagating (prevents outside-click closing)
        subjPanel.addEventListener('click', e => e.stopPropagation());

        refreshSubjTrigger();
    }

    /* ═══════════════════════════════════════════════════════════════════════
       Form validation + spinner
       ═══════════════════════════════════════════════════════════════════════ */
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

            if (!document.getElementById('class')?.value) fail('class', 'classError');

            const checkedStreams = streamPanel?.querySelectorAll('input:checked') ?? [];
            if (checkedStreams.length === 0) {
                streamSel?.classList.add('invalid');
                if (streamErr) streamErr.style.display = 'flex';
                if (!first) first = streamTrig;
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

        document.getElementById('class')?.addEventListener('change', () => {
            document.getElementById('class').classList.remove('is-invalid');
            document.getElementById('classError').style.display = 'none';
        });

        window.addEventListener('pageshow', () => {
            if (loadBtn) {
                loadBtn.classList.remove('loading');
                loadBtn.disabled = false;
                const lbl = loadBtn.querySelector('.btn-label');
                if (lbl) lbl.textContent = 'View Students';
            }
        });
    }

    /* ═══════════════════════════════════════════════════════════════════════
       Table — search, sort, pagination
       (only runs when the students table is present)
       ═══════════════════════════════════════════════════════════════════════ */
    if (!$('#studentsTable').length) return;

    const PER_PAGE = 25;
    let allRows  = Array.from($('#tBody .student-row'));
    let filtered = allRows.slice();
    let page     = 1;
    let sortCol  = '';
    let sortDir  = 'asc';

    // ── Render table ────────────────────────────────────────────────────────
    function renderTable() {
        $(allRows).hide();
        $('#tBody .no-records-row').remove();

        if (filtered.length === 0) {
            const cols = $('#studentsTable thead th').length;
            $('#tBody').append(
                `<tr class="no-records-row"><td colspan="${cols}"><div class="empty-state">` +
                `<i class="fas fa-search"></i><h3>No results</h3>` +
                `<p>No students match your search term.</p></div></td></tr>`
            );
            $('#filterInfo').text('');
            return;
        }

        const start = (page - 1) * PER_PAGE;
        const end   = Math.min(start + PER_PAGE, filtered.length);
        $(filtered.slice(start, end)).show();

        // Re-number
        $(filtered.slice(start, end)).each(function (i) {
            $(this).find('td:first').text(start + i + 1);
        });

        const total = allRows.length;
        $('#filterInfo').text(filtered.length < total
            ? `Showing ${filtered.length} of ${total} students`
            : `${total} student${total !== 1 ? 's' : ''}`);
    }

    // ── Render pagination ────────────────────────────────────────────────────
    function renderPagination() {
        const total = filtered.length;
        const pages = Math.max(1, Math.ceil(total / PER_PAGE));
        const start = total === 0 ? 0 : (page - 1) * PER_PAGE + 1;
        const end   = Math.min(page * PER_PAGE, total);

        $('#pageInfo').text(total > 0 ? `Showing ${start}–${end} of ${total}` : '');
        if (pages <= 1) { $('#pageBtns').html(''); return; }

        let html = `<button class="page-btn" onclick="goPage(${page - 1})" ${page === 1 ? 'disabled' : ''}><i class="fas fa-chevron-left"></i></button>`;
        for (let p = 1; p <= pages; p++) {
            if (pages > 7 && Math.abs(p - page) > 2 && p !== 1 && p !== pages) {
                if (p === 2 || p === pages - 1) html += `<button class="page-btn" disabled style="border:none;cursor:default;color:#bbb">…</button>`;
                continue;
            }
            html += `<button class="page-btn ${p === page ? 'active' : ''}" onclick="goPage(${p})">${p}</button>`;
        }
        html += `<button class="page-btn" onclick="goPage(${page + 1})" ${page === pages ? 'disabled' : ''}><i class="fas fa-chevron-right"></i></button>`;
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

    // ── Search (debounced 220ms) ─────────────────────────────────────────────
    let searchTimer;
    $('#studentSearch').on('input', function () {
        clearTimeout(searchTimer);
        const q = $(this).val().toLowerCase().trim();
        searchTimer = setTimeout(() => {
            filtered = allRows.filter(r => {
                if (!q) return true;
                return $(r).data('name').includes(q)
                    || $(r).data('id').includes(q)
                    || $(r).data('stream').includes(q)
                    || $(r).data('subjects').includes(q);
            });
            page = 1;
            renderTable();
            renderPagination();
        }, 220);
    });

    // ── Column sort ──────────────────────────────────────────────────────────
    $('#studentsTable thead th.sortable').on('click', function () {
        const col = $(this).data('sort');
        if (sortCol === col) {
            sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            sortCol = col;
            sortDir = 'asc';
        }

        // Update header icons
        $('#studentsTable thead th.sortable').removeClass('sort-asc sort-desc');
        $(this).addClass(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');

        allRows.sort((a, b) => {
            let av = $(a).data(col) ?? '';
            let bv = $(b).data(col) ?? '';
            if (col === 'count') {
                av = parseInt(av) || 0;
                bv = parseInt(bv) || 0;
                return sortDir === 'asc' ? av - bv : bv - av;
            }
            const cmp = String(av).localeCompare(String(bv), undefined, { sensitivity: 'base' });
            return sortDir === 'asc' ? cmp : -cmp;
        });

        // Re-order DOM rows (keep in tbody, re-sort visual order)
        const tbody = document.getElementById('tBody');
        allRows.forEach(r => tbody.appendChild(r));

        // Re-apply current search filter preserving new order
        const q = $('#studentSearch').val().toLowerCase().trim();
        filtered = allRows.filter(r => {
            if (!q) return true;
            return $(r).data('name').includes(q)
                || $(r).data('id').includes(q)
                || $(r).data('stream').includes(q)
                || $(r).data('subjects').includes(q);
        });

        page = 1;
        renderTable();
        renderPagination();
    });

    // ── Initial render ───────────────────────────────────────────────────────
    renderTable();
    renderPagination();

}(jQuery));
</script>

</body>
</html>