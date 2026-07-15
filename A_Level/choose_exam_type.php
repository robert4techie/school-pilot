<?php
/**
 * choose_exam_type.php
 * SchoolPilot — Step 2 of 3: Choose exam type and papers.
 *
 * v3 (production):
 *  - Labels rendered ABOVE their fields (stacked layout, matches sel_add_marks)
 *  - Select2 REMOVED — replaced with a custom multi-select panel (same pattern
 *    as the stream selector) for consistency and zero jQuery dependency
 *  - Back button uses a real URL with params instead of javascript:history.back()
 *    so the browser reloads sel_add_marks.php cleanly (no bfcache blur)
 *  - pageshow event resets spinner in case of bfcache restore
 *  - Spinner replaces arrow on form submit; button disabled to prevent double-post
 *  - Smooth focus rings, hover states, dropdown animations throughout
 *  - All output escaped through $safe()
 */

require_once '../auth.php';
require_once '../conn.php';
require_once '../O_Level/teacher_auth_check.php';

// ── Extract & sanitise params ─────────────────────────────────────────────────
$class     = trim($_GET['class']     ?? '');
$term      = trim($_GET['term']      ?? '');
$year      = trim($_GET['year']      ?? date('Y'));
$streams   = isset($_GET['streams']) && is_array($_GET['streams']) ? $_GET['streams'] : [];
$subject   = trim($_GET['subject']   ?? '');
$exam_type = trim($_GET['exam_type'] ?? '');
$papers    = isset($_GET['papers'])  && is_array($_GET['papers'])  ? $_GET['papers']  : [];

// ── Data functions ─────────────────────────────────────────────────────────────

function getExamTypes(mysqli $conn, string $class): array
{
    $sql  = 'SELECT id, exam_set, description FROM exam_sets WHERE classes LIKE ? ORDER BY id';
    $like = '%' . $class . '%';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, 's', $like);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $out    = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $out[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $out;
}

function getSubjectPapers(mysqli $conn, string $subject, string $class): array
{
    $sql  = 'SELECT papers FROM subject_papers WHERE subject_name = ? AND class = ? LIMIT 1';
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, 'ss', $subject, $class);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row    = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$row || empty($row['papers'])) return [];

    $out = [];
    foreach (array_map('trim', explode(',', $row['papers'])) as $p) {
        if ($p !== '') {
            $out[] = ['id' => $p, 'name' => 'Paper ' . $p];
        }
    }
    return $out;
}

$allExamTypes  = getExamTypes($conn, $class);
$subjectPapers = getSubjectPapers($conn, $subject, $class);

$safe = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// ── Build back URL (real link so browser loads the page fresh) ────────────────
$backParams = http_build_query([
    'class'   => $class,
    'term'    => $term,
    'year'    => $year,
    'subject' => $subject,
]);
$streamQuery = implode('&', array_map(
    fn($s) => 'streams[]=' . urlencode($s),
    $streams
));
$backUrl = 'sel_add_marks.php?' . $backParams . ($streamQuery ? '&' . $streamQuery : '');

// ── JSON encode papers for JS ─────────────────────────────────────────────────
$papersJson          = json_encode($subjectPapers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$selectedPapersJson  = json_encode($papers,        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$examTypesJson       = json_encode(
    array_map(fn($e) => [
        'id'          => (string) $e['id'],
        'exam_set'    => $e['exam_set'],
        'description' => $e['description'] ?? '',
    ], $allExamTypes),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose Exam Type &amp; Papers &mdash; SchoolPilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ══════════════════════════════════════════════════════════════════════════════
   Design tokens — identical to sel_add_marks for visual consistency
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
.page{max-width:1400px;margin:0 auto;padding:24px 20px 72px}

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
.step-badge{
    background:rgba(255,255,255,.18);color:#fff;font-size:.72rem;font-weight:700;
    letter-spacing:.6px;text-transform:uppercase;padding:5px 14px;border-radius:20px;
    white-space:nowrap;flex-shrink:0;margin-top:2px;border:1px solid rgba(255,255,255,.28);
}

/* ── Step progress bar ── */
.steps-bar{
    display:flex;align-items:center;
    background:#fff;border:1px solid var(--gray-200);border-radius:var(--radius-lg);
    padding:14px 24px;margin-bottom:20px;box-shadow:var(--shadow-sm);
}
.step-item{display:flex;align-items:center;gap:9px;font-size:.82rem;font-weight:600;color:var(--gray-400);white-space:nowrap}
.step-item.active{color:var(--g700)}
.step-item.done{color:var(--gray-600)}
.step-num{
    width:27px;height:27px;border-radius:50%;border:2px solid currentColor;
    display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;flex-shrink:0;
    transition:background var(--transition),border-color var(--transition);
}
.step-item.active .step-num{background:var(--g700);color:#fff;border-color:var(--g700);box-shadow:0 0 0 3px rgba(56,142,60,.2)}
.step-item.done .step-num{background:var(--gray-400);color:#fff;border-color:var(--gray-400)}
.step-connector{flex:1;height:2px;background:var(--gray-200);margin:0 12px;border-radius:2px}
.step-connector.done{background:var(--g400)}

/* ── Summary bar ── */
.summary-bar{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px}
.summary-chip{
    display:inline-flex;align-items:center;gap:6px;
    background:#fff;border:1px solid var(--gray-200);border-radius:20px;
    padding:5px 12px 5px 10px;font-size:.8rem;
    box-shadow:0 1px 3px rgba(0,0,0,.06);
}
.summary-chip i{color:var(--g700);font-size:.75rem}
.summary-chip-label{color:var(--gray-600);font-weight:500}
.summary-chip-value{font-weight:700;color:var(--g900)}

/* ── Card ── */
.card{
    background:#fff;border-radius:var(--radius-lg);
    box-shadow:var(--shadow);border:1px solid rgba(0,0,0,.05);overflow:visible;
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

/* ══════════════════════════════════════════════════════════════════════════════
   Form groups — stacked: label above field
   ══════════════════════════════════════════════════════════════════════════════ */
.form-group{margin-bottom:24px}
.form-label{
    display:block;
    font-size:.82rem;font-weight:600;
    color:var(--gray-600);
    margin-bottom:6px;
    line-height:1.3;
    user-select:none;
    letter-spacing:.02em;
}
.form-label .req{color:var(--danger);margin-left:2px;font-weight:700}
.form-field{position:relative}

/* ── Native select ── */
.form-control{
    width:100%;height:var(--field-h);padding:0 36px 0 13px;
    border:1.5px solid var(--gray-300);border-radius:var(--radius);
    font-size:.9rem;font-family:inherit;background:#fff;color:var(--text);
    transition:border-color var(--transition),box-shadow var(--transition);
    appearance:none;-webkit-appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236b7c6d' stroke-width='1.6' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 13px center;
    cursor:pointer;
}
.form-control:hover:not(:focus):not(.is-invalid){border-color:var(--gray-600)}
.form-control:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.16)}
.form-control.is-invalid{border-color:var(--danger);background-color:var(--danger-bg)}
.form-control.is-invalid:focus{box-shadow:0 0 0 3px rgba(211,47,47,.14)}

.field-hint{font-size:.78rem;color:var(--gray-600);margin-top:5px;line-height:1.4;font-style:italic}
.error-msg{
    font-size:.78rem;color:var(--danger);font-weight:500;margin-top:6px;
    display:none;align-items:center;gap:5px;
    animation:fadeIn .18s ease;
}
.error-msg i{font-size:.7rem;flex-shrink:0}
@keyframes fadeIn{from{opacity:0;transform:translateY(-3px)}to{opacity:1;transform:none}}

/* ══════════════════════════════════════════════════════════════════════════════
   Custom Papers multi-select — same pattern as stream selector
   ══════════════════════════════════════════════════════════════════════════════ */
.paper-selector{position:relative}

.paper-trigger{
    width:100%;height:var(--field-h);padding:0 38px 0 13px;
    border:1.5px solid var(--gray-300);border-radius:var(--radius);
    background:#fff;display:flex;align-items:center;gap:6px;
    cursor:pointer;user-select:none;position:relative;
    transition:border-color var(--transition),box-shadow var(--transition),border-radius var(--transition);
}
.paper-trigger:hover{border-color:var(--gray-600)}
.paper-selector.open .paper-trigger{
    border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.16);
    border-bottom-left-radius:0;border-bottom-right-radius:0;
}
.paper-selector.invalid .paper-trigger{border-color:var(--danger);background-color:var(--danger-bg)}
.paper-trigger:focus-visible{outline:2px solid var(--g600);outline-offset:2px}

.paper-arrow{
    position:absolute;right:13px;top:50%;transform:translateY(-50%);
    color:var(--gray-600);font-size:.72rem;pointer-events:none;
    transition:transform .28s cubic-bezier(.4,0,.2,1);
}
.paper-selector.open .paper-arrow{transform:translateY(-50%) rotate(180deg)}

.paper-trigger-content{display:flex;align-items:center;gap:5px;flex:1;min-width:0;overflow:hidden;flex-wrap:nowrap}
.paper-placeholder{font-size:.9rem;color:#aab8ac;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.paper-tag{
    display:inline-flex;align-items:center;gap:4px;
    background:var(--g100);color:var(--g800);border:1px solid #b3dab9;
    border-radius:5px;padding:2px 9px;font-size:.78rem;font-weight:600;
    white-space:nowrap;flex-shrink:0;
}

.paper-panel{
    position:absolute;
    top:calc(var(--field-h) - 1.5px);
    left:0;right:0;z-index:300;
    background:#fff;
    border:1.5px solid var(--g600);
    border-top:1px solid var(--gray-200);
    border-radius:0 0 var(--radius) var(--radius);
    box-shadow:0 14px 36px rgba(0,0,0,.14);
    overflow:hidden;
    max-height:0;opacity:0;pointer-events:none;
    transition:max-height .3s cubic-bezier(.4,0,.2,1),opacity .22s ease;
}
.paper-selector.open .paper-panel{
    max-height:400px;opacity:1;pointer-events:auto;
}

.paper-empty{
    padding:16px;text-align:center;font-size:.86rem;color:var(--gray-600);font-style:italic;
}

.paper-option{
    display:flex;align-items:center;gap:12px;padding:12px 16px;
    cursor:pointer;font-size:.9rem;color:var(--text);
    border-bottom:1px solid var(--gray-200);
    transition:background var(--transition);
    position:relative;
}
.paper-option:last-child{border-bottom:none}
.paper-option:hover{background:var(--g50)}
.paper-option.selected{
    background:linear-gradient(90deg,var(--g800),var(--g700));
    color:#fff;font-weight:600;
}
.paper-option.selected:hover{filter:brightness(1.06)}

.opt-check{
    width:18px;height:18px;border-radius:4px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    border:2px solid var(--gray-300);background:#fff;
    transition:border-color var(--transition),background var(--transition);
}
.paper-option.selected .opt-check{background:rgba(255,255,255,.25);border-color:rgba(255,255,255,.6)}
.opt-check-icon{font-size:.6rem;color:#fff;opacity:0;transition:opacity .15s ease}
.paper-option.selected .opt-check-icon{opacity:1}

/* Hidden checkboxes */
.paper-option input[type="checkbox"]{
    position:absolute;opacity:0;width:0;height:0;pointer-events:none;
}
.opt-label{flex:1;pointer-events:none}

/* Quick-action links */
.paper-actions{display:flex;align-items:center;gap:4px;margin-top:6px;font-size:.8rem}
.paper-actions a{
    color:var(--g700);text-decoration:none;font-weight:600;
    padding:2px 7px;border-radius:4px;
    transition:background var(--transition),color var(--transition);
}
.paper-actions a:hover{background:var(--g100);color:var(--g900)}
.paper-actions .sep{color:var(--gray-400);margin:0 2px}

/* ── Divider & button row ── */
.divider{border:none;border-top:1px solid var(--gray-200);margin:28px 0 24px}
.btn-row{display:flex;gap:10px;align-items:center}

.btn{
    display:inline-flex;align-items:center;gap:8px;
    padding:0 24px;height:var(--field-h);border-radius:var(--radius);
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
.btn-primary:active:not(:disabled){transform:translateY(0);box-shadow:0 2px 8px rgba(46,125,50,.22)}
.btn-primary:disabled{opacity:.72;cursor:not-allowed;transform:none!important;box-shadow:none!important}

.btn-outline{
    background:#fff;color:var(--gray-600);
    border-color:var(--gray-300);
}
.btn-outline:hover{background:var(--gray-100);border-color:var(--gray-600);color:var(--text)}

/* Spinner */
.btn-spinner{
    display:none;width:15px;height:15px;
    border:2.5px solid rgba(255,255,255,.35);border-top-color:#fff;
    border-radius:50%;animation:spin .65s linear infinite;flex-shrink:0;
}
.btn.loading .btn-spinner{display:block}
.btn.loading .btn-icon{display:none}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Responsive ── */
@media(max-width:640px){
    .page{padding:16px 12px 60px}
    .page-header{flex-direction:column;gap:10px;padding:20px 22px;margin-top:56px}
    .step-badge,.steps-bar{display:none}
    .card-header{padding:18px 20px 14px}
    .card-body{padding:22px 20px 26px}
    .btn-row{flex-wrap:wrap}
    .summary-bar{gap:6px}
}
</style>
</head>
<body>

<?php require_once '../nav.php'; ?>

<div class="page">

    <!-- ══ Page Header ════════════════════════════════════════════════════════ -->
    <div class="page-header">
        <div class="page-header-text">
            <h1><i class="fas fa-layer-group"></i>Choose Exam Type &amp; Papers</h1>
            <p>Select the exam type and the paper(s) you want to enter marks for</p>
        </div>
        <span class="step-badge">Step 2 of 3</span>
    </div>

    <!-- ══ Step Progress Bar ══════════════════════════════════════════════════ -->
    <div class="steps-bar">
        <div class="step-item done">
            <span class="step-num"><i class="fas fa-check" style="font-size:.6rem"></i></span> Setup
        </div>
        <div class="step-connector done"></div>
        <div class="step-item active">
            <span class="step-num">2</span> Exam Type
        </div>
        <div class="step-connector"></div>
        <div class="step-item">
            <span class="step-num">3</span> Enter Marks
        </div>
    </div>

    <!-- ══ Summary Chips ══════════════════════════════════════════════════════ -->
    <div class="summary-bar">
        <span class="summary-chip">
            <i class="fas fa-school"></i>
            <span class="summary-chip-label">Class:</span>
            <span class="summary-chip-value"><?= $safe($class) ?></span>
        </span>
        <span class="summary-chip">
            <i class="fas fa-calendar-alt"></i>
            <span class="summary-chip-label">Term:</span>
            <span class="summary-chip-value"><?= $safe($term) ?></span>
        </span>
        <span class="summary-chip">
            <i class="fas fa-calendar-check"></i>
            <span class="summary-chip-label">Year:</span>
            <span class="summary-chip-value"><?= $safe($year) ?></span>
        </span>
        <span class="summary-chip">
            <i class="fas fa-book"></i>
            <span class="summary-chip-label">Subject:</span>
            <span class="summary-chip-value"><?= $safe($subject) ?></span>
        </span>
        <?php if (!empty($streams)): ?>
        <span class="summary-chip">
            <i class="fas fa-users"></i>
            <span class="summary-chip-label">Streams:</span>
            <span class="summary-chip-value"><?= $safe(implode(', ', $streams)) ?></span>
        </span>
        <?php endif; ?>
    </div>

    <!-- ══ Form Card ══════════════════════════════════════════════════════════ -->
    <div class="card">

        <div class="card-header">
            <div class="card-header-icon"><i class="fas fa-list-check"></i></div>
            <div>
                <div class="card-header-title">Exam Configuration</div>
                <div class="card-header-sub">Choose the exam type and the papers to enter marks for</div>
            </div>
        </div>

        <div class="card-body">
            <form id="examTypeForm" method="GET" action="add_marks.php" novalidate>

                <!-- Pass through previous step params -->
                <input type="hidden" name="class"   value="<?= $safe($class) ?>">
                <input type="hidden" name="term"    value="<?= $safe($term) ?>">
                <input type="hidden" name="year"    value="<?= $safe($year) ?>">
                <input type="hidden" name="subject" value="<?= $safe($subject) ?>">
                <?php foreach ($streams as $s): ?>
                    <input type="hidden" name="streams[]" value="<?= $safe($s) ?>">
                <?php endforeach; ?>

                <!-- Exam Type -->
                <div class="form-group">
                    <label class="form-label" for="exam_type">
                        Exam Type <span class="req">*</span>
                    </label>
                    <div class="form-field">
                        <select name="exam_type" id="exam_type" class="form-control" required>
                            <option value="">— Select Exam Type —</option>
                            <?php foreach ($allExamTypes as $exam): ?>
                            <option value="<?= $safe((string) $exam['id']) ?>"
                                    data-description="<?= $safe($exam['description'] ?? '') ?>"
                                    <?= (string) $exam_type === (string) $exam['id'] ? 'selected' : '' ?>>
                                <?= $safe($exam['exam_set']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-hint" id="examDescription"></p>
                        <p class="error-msg" id="examTypeError">
                            <i class="fas fa-exclamation-circle"></i> Please select an exam type.
                        </p>
                    </div>
                </div>

                <!-- Papers — custom multi-select -->
                <div class="form-group">
                    <label class="form-label" id="papersLabel">
                        Papers <span class="req">*</span>
                    </label>
                    <div class="form-field">

                        <div class="paper-selector" id="paperSelector"
                             role="combobox" aria-haspopup="listbox"
                             aria-expanded="false" aria-labelledby="papersLabel" aria-required="true">

                            <div class="paper-trigger" id="paperTrigger"
                                 tabindex="0" role="button" aria-label="Select papers">
                                <div class="paper-trigger-content" id="paperTriggerContent">
                                    <span class="paper-placeholder">Select paper(s)…</span>
                                </div>
                                <i class="fas fa-chevron-down paper-arrow" aria-hidden="true"></i>
                            </div>

                            <div class="paper-panel" id="paperPanel" role="listbox" aria-multiselectable="true">
                                <!-- Populated by JS from PHP data -->
                            </div>
                        </div>

                        <div class="paper-actions">
                            <a href="#" id="selectAllPapers">Select All</a>
                            <span class="sep">|</span>
                            <a href="#" id="deselectAllPapers">Deselect All</a>
                        </div>

                        <p class="error-msg" id="papersError">
                            <i class="fas fa-exclamation-circle"></i> Please select at least one paper.
                        </p>
                    </div>
                </div>

                <hr class="divider">

                <div class="btn-row">
                    <a href="<?= $safe($backUrl) ?>" class="btn btn-outline" id="backBtn">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back</span>
                    </a>
                    <button type="submit" class="btn btn-primary" id="proceedBtn">
                        <span class="btn-spinner" aria-hidden="true"></span>
                        <i class="fas fa-arrow-right btn-icon" aria-hidden="true"></i>
                        <span class="btn-label">Proceed to Add Marks</span>
                    </button>
                </div>

            </form>
        </div>
    </div>

</div><!-- /page -->

<script>
(function () {
    'use strict';

    /* ════════════════════════════════════════════════════════════════════════
       Data injected from PHP
       ════════════════════════════════════════════════════════════════════════ */
    const ALL_PAPERS      = <?= $papersJson ?>;
    const SELECTED_PAPERS = new Set(<?= $selectedPapersJson ?>);

    /* ════════════════════════════════════════════════════════════════════════
       bfcache fix — always reset spinner/button on pageshow
       ════════════════════════════════════════════════════════════════════════ */
    function resetProceedButton() {
        const btn = document.getElementById('proceedBtn');
        if (!btn) return;
        btn.classList.remove('loading');
        btn.disabled = false;
        const label = btn.querySelector('.btn-label');
        if (label) label.textContent = 'Proceed to Add Marks';
    }

    window.addEventListener('pageshow', function () {
        resetProceedButton();
    });


    /* ════════════════════════════════════════════════════════════════════════
       Exam type description
       ════════════════════════════════════════════════════════════════════════ */
    const examSelect = document.getElementById('exam_type');
    const examDesc   = document.getElementById('examDescription');
    const examErr    = document.getElementById('examTypeError');

    function syncExamDescription() {
        const opt  = examSelect.options[examSelect.selectedIndex];
        const desc = opt ? (opt.dataset.description || '') : '';
        examDesc.textContent = desc;
    }

    examSelect.addEventListener('change', function () {
        examErr.style.display = 'none';
        examSelect.classList.remove('is-invalid');
        syncExamDescription();
    });

    syncExamDescription();


    /* ════════════════════════════════════════════════════════════════════════
       Papers custom multi-select
       ════════════════════════════════════════════════════════════════════════ */
    const paperSelector = document.getElementById('paperSelector');
    const paperTrigger  = document.getElementById('paperTrigger');
    const paperPanel    = document.getElementById('paperPanel');
    const paperContent  = document.getElementById('paperTriggerContent');
    const papersErr     = document.getElementById('papersError');

    /** Build the options panel from ALL_PAPERS data */
    function buildPaperOptions() {
        paperPanel.innerHTML = '';

        if (ALL_PAPERS.length === 0) {
            const empty = document.createElement('div');
            empty.className   = 'paper-empty';
            empty.textContent = 'No papers available for this subject and class.';
            paperPanel.appendChild(empty);
            return;
        }

        ALL_PAPERS.forEach(function (paper) {
            const isSelected = SELECTED_PAPERS.has(paper.id);

            const opt = document.createElement('div');
            opt.className         = 'paper-option' + (isSelected ? ' selected' : '');
            opt.dataset.value     = paper.id;
            opt.setAttribute('role', 'option');
            opt.setAttribute('aria-selected', isSelected ? 'true' : 'false');

            opt.innerHTML = `
                <span class="opt-check" aria-hidden="true">
                    <i class="fas fa-check opt-check-icon"></i>
                </span>
                <input type="checkbox"
                       name="papers[]"
                       value="${escHtml(paper.id)}"
                       id="paper-${escHtml(paper.id)}"
                       ${isSelected ? 'checked' : ''}
                       tabindex="-1" aria-hidden="true">
                <i class="fas fa-file-alt opt-icon" aria-hidden="true"></i>
                <label class="opt-label" for="paper-${escHtml(paper.id)}">${escHtml(paper.name)}</label>
            `;

            paperPanel.appendChild(opt);
        });
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /** Refresh the trigger bar tags / placeholder */
    function refreshPaperTrigger() {
        const checked = paperPanel.querySelectorAll('input[type="checkbox"]:checked');
        paperContent.innerHTML = '';
        if (checked.length === 0) {
            const ph = document.createElement('span');
            ph.className   = 'paper-placeholder';
            ph.textContent = 'Select paper(s)…';
            paperContent.appendChild(ph);
        } else {
            checked.forEach(function (cb) {
                const tag = document.createElement('span');
                tag.className   = 'paper-tag';
                tag.textContent = cb.closest('.paper-option').querySelector('.opt-label').textContent;
                paperContent.appendChild(tag);
            });
        }
    }

    function setPaperOpen(open) {
        paperSelector.classList.toggle('open', open);
        paperSelector.setAttribute('aria-expanded', open ? 'true' : 'false');
        paperTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function clearPapersInvalid() {
        paperSelector.classList.remove('invalid');
        papersErr.style.display = 'none';
    }

    paperTrigger.addEventListener('click', function (e) {
        e.stopPropagation();
        setPaperOpen(!paperSelector.classList.contains('open'));
    });

    paperTrigger.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            setPaperOpen(!paperSelector.classList.contains('open'));
        }
        if (e.key === 'Escape') setPaperOpen(false);
        if (e.key === 'ArrowDown' && !paperSelector.classList.contains('open')) {
            e.preventDefault();
            setPaperOpen(true);
        }
    });

    paperPanel.addEventListener('click', function (e) {
        const opt = e.target.closest('.paper-option');
        if (!opt) return;
        const cb = opt.querySelector('input[type="checkbox"]');
        cb.checked = !cb.checked;
        opt.classList.toggle('selected', cb.checked);
        opt.setAttribute('aria-selected', cb.checked ? 'true' : 'false');
        refreshPaperTrigger();
        if (cb.checked) clearPapersInvalid();
    });

    document.addEventListener('click', function (e) {
        if (!paperSelector.contains(e.target)) setPaperOpen(false);
    });

    document.getElementById('selectAllPapers').addEventListener('click', function (e) {
        e.preventDefault();
        paperPanel.querySelectorAll('.paper-option').forEach(function (opt) {
            const cb = opt.querySelector('input');
            if (cb) {
                cb.checked = true;
                opt.classList.add('selected');
                opt.setAttribute('aria-selected', 'true');
            }
        });
        refreshPaperTrigger();
        clearPapersInvalid();
    });

    document.getElementById('deselectAllPapers').addEventListener('click', function (e) {
        e.preventDefault();
        paperPanel.querySelectorAll('.paper-option').forEach(function (opt) {
            const cb = opt.querySelector('input');
            if (cb) {
                cb.checked = false;
                opt.classList.remove('selected');
                opt.setAttribute('aria-selected', 'false');
            }
        });
        refreshPaperTrigger();
    });

    // Initialise
    buildPaperOptions();
    refreshPaperTrigger();


    /* ════════════════════════════════════════════════════════════════════════
       Form submit — validate, then spinner
       ════════════════════════════════════════════════════════════════════════ */
    const form       = document.getElementById('examTypeForm');
    const proceedBtn = document.getElementById('proceedBtn');

    form.addEventListener('submit', function (e) {
        let ok = true;
        let firstInvalid = null;

        // Exam type
        if (!examSelect.value) {
            examSelect.classList.add('is-invalid');
            examErr.style.display = 'flex';
            firstInvalid = examSelect;
            ok = false;
        }

        // Papers
        const checkedPapers = paperPanel.querySelectorAll('input[type="checkbox"]:checked');
        if (checkedPapers.length === 0) {
            paperSelector.classList.add('invalid');
            papersErr.style.display = 'flex';
            if (!firstInvalid) firstInvalid = paperTrigger;
            ok = false;
        }

        if (!ok) {
            e.preventDefault();
            if (firstInvalid) {
                firstInvalid.focus();
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        }

        // ✓ Valid — activate spinner
        proceedBtn.classList.add('loading');
        proceedBtn.disabled = true;
        proceedBtn.querySelector('.btn-label').textContent = 'Loading…';
    });

}());
</script>
</body>
</html>