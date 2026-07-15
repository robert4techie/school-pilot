<?php
/**
 * sel_add_marks.php
 * SchoolPilot — Step 1 of 3: Choose class, term, year, streams and subject.
 *
 * v5 (production):
 *  - Labels rendered ABOVE their fields (stacked layout)
 *  - bfcache fix: pageshow event resets spinner so "Back" never shows blur
 *  - Stream(s) dropdown uses smooth max-height slide (zero layout shift)
 *  - Spinner replaces arrow icon on submit; button disabled to prevent double-submit
 *  - Full client-side validation with clear inline error messages & focus-management
 *  - Proper ARIA roles on the custom combobox
 *  - All output escaped through $safe()
 */

require_once '../auth.php';
require_once '../conn.php';

// ── Defaults & sanitisation ───────────────────────────────────────────────────
$currentYear     = (int) date('Y');
$availableStreams = ['Arts', 'Sciences'];

$class   = trim($_GET['class']   ?? '');
$term    = trim($_GET['term']    ?? '');
$year    = trim($_GET['year']    ?? (string) $currentYear);
$streams = isset($_GET['streams']) && is_array($_GET['streams'])
           ? array_values(array_filter($_GET['streams'], fn($s) => in_array($s, $availableStreams, true)))
           : [];
$subject = trim($_GET['subject'] ?? '');

// ── Data query ────────────────────────────────────────────────────────────────
function getSubjects(mysqli $conn): array
{
    // FIND_IN_SET handles comma-separated level values like 'O,A' and pure 'A'
    // This ensures General Paper (level='A') and cross-level subjects (level='O,A')
    // are all included correctly.
    $result = mysqli_query($conn,
        "SELECT subj_name FROM subjects
         WHERE FIND_IN_SET('A', level) > 0
         ORDER BY subj_name"
    );
    $out = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $out[] = $row['subj_name'];
        }
    }
    return $out;
}

$allSubjects = getSubjects($conn);
$safe        = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage A-Level Marks &mdash; SchoolPilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ══════════════════════════════════════════════════════════════════════════════
   Design tokens
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
   Form groups — stacked: label sits above the field
   ══════════════════════════════════════════════════════════════════════════════ */
.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    column-gap:20px;
}
.form-group{
    display:flex;flex-direction:column;
    margin-bottom:20px;
}
.form-group.full{grid-column:1 / -1}

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
.form-field{position:relative;min-width:0}

/* ── Native controls ── */
.form-control{
    width:100%;height:var(--field-h);padding:0 36px 0 13px;
    border:1.5px solid var(--gray-300);border-radius:var(--radius);
    font-size:.9rem;font-family:inherit;background:#fff;color:var(--text);
    transition:border-color var(--transition),box-shadow var(--transition),background var(--transition);
    appearance:none;-webkit-appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236b7c6d' stroke-width='1.6' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 13px center;cursor:pointer;
}
input.form-control{background-image:none;padding-right:13px;cursor:text}
.form-control:hover:not(:focus):not(.is-invalid){border-color:var(--gray-600)}
.form-control:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.16)}
.form-control.is-invalid{border-color:var(--danger);background-color:var(--danger-bg)}
.form-control.is-invalid:focus{box-shadow:0 0 0 3px rgba(211,47,47,.14)}

.field-hint{font-size:.78rem;color:var(--gray-600);margin-top:5px;line-height:1.4}
.field-hint.error{color:var(--danger);font-weight:500}
.error-msg{
    font-size:.78rem;color:var(--danger);font-weight:500;margin-top:6px;
    display:none;align-items:center;gap:5px;
    animation:fadeIn .18s ease;
}
.error-msg i{font-size:.7rem;flex-shrink:0}
@keyframes fadeIn{from{opacity:0;transform:translateY(-3px)}to{opacity:1;transform:none}}

/* ══════════════════════════════════════════════════════════════════════════════
   Custom Stream multi-select dropdown
   ══════════════════════════════════════════════════════════════════════════════ */
.stream-selector{position:relative}

/* Trigger bar */
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
.stream-trigger:focus-visible{outline:2px solid var(--g600);outline-offset:2px}

/* Rotating chevron */
.stream-arrow{
    position:absolute;right:13px;top:50%;transform:translateY(-50%);
    color:var(--gray-600);font-size:.72rem;pointer-events:none;
    transition:transform .28s cubic-bezier(.4,0,.2,1);
}
.stream-selector.open .stream-arrow{transform:translateY(-50%) rotate(180deg)}

/* Tags / placeholder inside trigger */
.stream-trigger-content{display:flex;align-items:center;gap:5px;flex:1;min-width:0;overflow:hidden}
.stream-placeholder{font-size:.9rem;color:#aab8ac;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.stream-tag{
    display:inline-flex;align-items:center;gap:4px;
    background:var(--g100);color:var(--g800);border:1px solid #b3dab9;
    border-radius:5px;padding:2px 9px;font-size:.78rem;font-weight:600;
    white-space:nowrap;flex-shrink:0;
}

/* Options panel */
.stream-panel{
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
.stream-selector.open .stream-panel{
    max-height:400px;opacity:1;pointer-events:auto;
}

/* Individual option rows */
.stream-option{
    display:flex;align-items:center;gap:12px;padding:12px 16px;
    cursor:pointer;font-size:.9rem;color:var(--text);
    border-bottom:1px solid var(--gray-200);
    transition:background var(--transition);
    position:relative;
}
.stream-option:last-child{border-bottom:none}
.stream-option:hover{background:var(--g50)}
.stream-option.selected{
    background:linear-gradient(90deg,var(--g800),var(--g700));
    color:#fff;font-weight:600;
}
.stream-option.selected:hover{filter:brightness(1.06)}

/* Visual checkbox */
.opt-check{
    width:18px;height:18px;border-radius:4px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    border:2px solid var(--gray-300);background:#fff;
    transition:border-color var(--transition),background var(--transition);
}
.stream-option.selected .opt-check{
    background:rgba(255,255,255,.25);border-color:rgba(255,255,255,.6);
}
.opt-check-icon{font-size:.6rem;color:#fff;opacity:0;transition:opacity .15s ease}
.stream-option.selected .opt-check-icon{opacity:1}

/* Real hidden checkbox for form submission */
.stream-option input[type="checkbox"]{
    position:absolute;opacity:0;width:0;height:0;pointer-events:none;
}
.opt-label{flex:1;pointer-events:none}
.opt-icon{font-size:.82rem;opacity:.55}
.stream-option.selected .opt-icon{opacity:.8}

/* Select all / clear links */
.stream-actions{display:flex;align-items:center;gap:4px;margin-top:6px;font-size:.8rem}
.stream-actions a{
    color:var(--g700);text-decoration:none;font-weight:600;
    padding:2px 7px;border-radius:4px;
    transition:background var(--transition),color var(--transition);
}
.stream-actions a:hover{background:var(--g100);color:var(--g900)}
.stream-actions .sep{color:var(--gray-400);margin:0 2px}

/* ── Divider & button row ── */
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
.btn-primary:active:not(:disabled){transform:translateY(0);box-shadow:0 2px 8px rgba(46,125,50,.22)}
.btn-primary:disabled{opacity:.72;cursor:not-allowed;transform:none!important;box-shadow:none!important}

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
    .form-grid{grid-template-columns:1fr}
    .form-group.full{grid-column:auto}
    .btn-row{padding-left:0}
}
</style>
</head>
<body>

<?php require_once '../nav.php'; ?>

<div class="page">

    <!-- ══ Page Header ════════════════════════════════════════════════════════ -->
    <div class="page-header">
        <div class="page-header-text">
            <h1><i class="fas fa-pen-to-square"></i>Manage A-Level Marks</h1>
            <p>Select the class, term, year, streams and subject to begin entering marks</p>
        </div>
        <span class="step-badge">Step 1 of 3</span>
    </div>

    <!-- ══ Step Progress Bar ══════════════════════════════════════════════════ -->
    <div class="steps-bar">
        <div class="step-item active">
            <span class="step-num">1</span> Setup
        </div>
        <div class="step-connector"></div>
        <div class="step-item">
            <span class="step-num">2</span> Exam Type
        </div>
        <div class="step-connector"></div>
        <div class="step-item">
            <span class="step-num">3</span> Enter Marks
        </div>
    </div>

    <!-- ══ Form Card ══════════════════════════════════════════════════════════ -->
    <div class="card">

        <div class="card-header">
            <div class="card-header-icon"><i class="fas fa-sliders-h"></i></div>
            <div>
                <div class="card-header-title">Session Details</div>
                <div class="card-header-sub">All fields are required to continue</div>
            </div>
        </div>

        <div class="card-body">
            <form id="marksSetupForm" method="GET" action="choose_exam_type.php" novalidate autocomplete="off">

                <div class="form-grid">

                    <!-- Class -->
                    <div class="form-group">
                        <label class="form-label" for="class">
                            Class <span class="req">*</span>
                        </label>
                        <div class="form-field">
                            <select name="class" id="class" class="form-control" required>
                                <option value="">— Select Class —</option>
                                <option value="Senior Five" <?= $class === 'Senior Five' ? 'selected' : '' ?>>Senior Five</option>
                                <option value="Senior Six"  <?= $class === 'Senior Six'  ? 'selected' : '' ?>>Senior Six</option>
                            </select>
                            <p class="error-msg" id="classError">
                                <i class="fas fa-exclamation-circle"></i> Please select a class.
                            </p>
                        </div>
                    </div>

                    <!-- Term -->
                    <div class="form-group">
                        <label class="form-label" for="term">
                            Term <span class="req">*</span>
                        </label>
                        <div class="form-field">
                            <select name="term" id="term" class="form-control" required>
                                <option value="">— Select Term —</option>
                                <option value="Term 1" <?= $term === 'Term 1' ? 'selected' : '' ?>>Term 1</option>
                                <option value="Term 2" <?= $term === 'Term 2' ? 'selected' : '' ?>>Term 2</option>
                                <option value="Term 3" <?= $term === 'Term 3' ? 'selected' : '' ?>>Term 3</option>
                            </select>
                            <p class="error-msg" id="termError">
                                <i class="fas fa-exclamation-circle"></i> Please select a term.
                            </p>
                        </div>
                    </div>

                    <!-- Academic Year -->
                    <div class="form-group">
                        <label class="form-label" for="year">
                            Academic Year <span class="req">*</span>
                        </label>
                        <div class="form-field">
                            <input type="number" name="year" id="year" class="form-control"
                                   value="<?= $safe($year) ?>"
                                   min="2000" max="2099" step="1"
                                   placeholder="e.g. <?= $currentYear ?>" required>
                            <p class="field-hint" id="yearHint">Enter a valid 4-digit year (2000–2099).</p>
                            <p class="error-msg" id="yearError">
                                <i class="fas fa-exclamation-circle"></i> Please enter a valid year (2000–2099).
                            </p>
                        </div>
                    </div>

                    <!-- Subject -->
                    <div class="form-group">
                        <label class="form-label" for="subject">
                            Subject <span class="req">*</span>
                        </label>
                        <div class="form-field">
                            <select name="subject" id="subject" class="form-control" required>
                                <option value="">— Select Subject —</option>
                                <?php foreach ($allSubjects as $subj): ?>
                                <option value="<?= $safe($subj) ?>" <?= $subject === $subj ? 'selected' : '' ?>>
                                    <?= $safe($subj) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="error-msg" id="subjectError">
                                <i class="fas fa-exclamation-circle"></i> Please select a subject.
                            </p>
                        </div>
                    </div>

                    <!-- Streams — full width -->
                    <div class="form-group full">
                        <label class="form-label" id="streamLabel">
                            Stream(s) <span class="req">*</span>
                        </label>
                        <div class="form-field">

                            <div class="stream-selector" id="streamSelector"
                                 role="combobox" aria-haspopup="listbox"
                                 aria-expanded="false" aria-labelledby="streamLabel" aria-required="true">

                                <!-- Trigger -->
                                <div class="stream-trigger" id="streamTrigger"
                                     tabindex="0" role="button" aria-label="Select streams">
                                    <div class="stream-trigger-content" id="streamTriggerContent">
                                        <span class="stream-placeholder">Select streams…</span>
                                    </div>
                                    <i class="fas fa-chevron-down stream-arrow" aria-hidden="true"></i>
                                </div>

                                <!-- Options panel -->
                                <div class="stream-panel" id="streamPanel" role="listbox" aria-multiselectable="true">
                                    <?php
                                    $streamIcons = ['Arts' => 'fa-palette', 'Sciences' => 'fa-flask'];
                                    foreach ($availableStreams as $s):
                                        $sel  = in_array($s, $streams, true);
                                        $icon = $streamIcons[$s] ?? 'fa-circle';
                                    ?>
                                    <div class="stream-option<?= $sel ? ' selected' : '' ?>"
                                         data-value="<?= $safe($s) ?>"
                                         role="option"
                                         aria-selected="<?= $sel ? 'true' : 'false' ?>">
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
                    <button type="submit" class="btn btn-primary" id="continueBtn">
                        <span class="btn-spinner" aria-hidden="true"></span>
                        <i class="fas fa-arrow-right btn-icon" aria-hidden="true"></i>
                        <span class="btn-label">Continue to Exam Type</span>
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
       bfcache fix — when the user hits Back from choose_exam_type.php the
       browser may restore this page from cache with the button already in
       loading state. Reset everything on pageshow (fires for both normal
       loads and bfcache restores; e.persisted is true only for bfcache).
       ════════════════════════════════════════════════════════════════════════ */
    function resetSubmitButton() {
        const btn = document.getElementById('continueBtn');
        if (!btn) return;
        btn.classList.remove('loading');
        btn.disabled = false;
        const label = btn.querySelector('.btn-label');
        if (label) label.textContent = 'Continue to Exam Type';
    }

    window.addEventListener('pageshow', function (e) {
        // Always reset — covers both bfcache restore (e.persisted=true)
        // and normal forward navigation with stale DOM (e.persisted=false).
        resetSubmitButton();
    });


    /* ════════════════════════════════════════════════════════════════════════
       Stream Dropdown Controller
       ════════════════════════════════════════════════════════════════════════ */
    const selector  = document.getElementById('streamSelector');
    const trigger   = document.getElementById('streamTrigger');
    const panel     = document.getElementById('streamPanel');
    const content   = document.getElementById('streamTriggerContent');
    const streamErr = document.getElementById('streamError');

    /** Rebuild pill tags / placeholder in the trigger. */
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
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function clearStreamInvalid() {
        selector.classList.remove('invalid');
        streamErr.style.display = 'none';
    }

    trigger.addEventListener('click', e => {
        e.stopPropagation();
        setOpen(!selector.classList.contains('open'));
    });

    trigger.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            setOpen(!selector.classList.contains('open'));
        }
        if (e.key === 'Escape') setOpen(false);
        if (e.key === 'ArrowDown' && !selector.classList.contains('open')) {
            e.preventDefault();
            setOpen(true);
        }
    });

    panel.addEventListener('click', e => {
        const opt = e.target.closest('.stream-option');
        if (!opt) return;
        const cb = opt.querySelector('input[type="checkbox"]');
        cb.checked = !cb.checked;
        opt.classList.toggle('selected', cb.checked);
        opt.setAttribute('aria-selected', cb.checked ? 'true' : 'false');
        refreshTrigger();
        if (cb.checked) clearStreamInvalid();
    });

    document.addEventListener('click', e => {
        if (!selector.contains(e.target)) setOpen(false);
    });

    document.getElementById('selectAllStreams').addEventListener('click', e => {
        e.preventDefault();
        panel.querySelectorAll('.stream-option').forEach(opt => {
            opt.querySelector('input').checked = true;
            opt.classList.add('selected');
            opt.setAttribute('aria-selected', 'true');
        });
        refreshTrigger();
        clearStreamInvalid();
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

    refreshTrigger(); // initialise on page load


    /* ════════════════════════════════════════════════════════════════════════
       Year — live validation
       ════════════════════════════════════════════════════════════════════════ */
    const yearInput = document.getElementById('year');
    const yearHint  = document.getElementById('yearHint');
    const yearErr   = document.getElementById('yearError');

    function validateYear(showEmpty) {
        const v     = yearInput.value.trim();
        const n     = parseInt(v, 10);
        const bad   = v !== '' && (!/^\d{4}$/.test(v) || n < 2000 || n > 2099);
        const empty = v === '' && showEmpty;
        const invalid = bad || empty;
        yearInput.classList.toggle('is-invalid', invalid);
        yearHint.classList.toggle('error', bad);
        yearErr.style.display = invalid ? 'flex' : 'none';
        return !bad && v !== '';
    }

    yearInput.addEventListener('input', () => validateYear(false));
    yearInput.addEventListener('blur',  () => validateYear(false));


    /* ════════════════════════════════════════════════════════════════════════
       Clear field errors on change
       ════════════════════════════════════════════════════════════════════════ */
    ['class', 'term', 'subject'].forEach(id => {
        const el  = document.getElementById(id);
        const err = document.getElementById(id + 'Error');
        el.addEventListener('change', () => {
            el.classList.remove('is-invalid');
            if (err) err.style.display = 'none';
        });
    });


    /* ════════════════════════════════════════════════════════════════════════
       Form submit — validate all, then show spinner
       ════════════════════════════════════════════════════════════════════════ */
    const form        = document.getElementById('marksSetupForm');
    const continueBtn = document.getElementById('continueBtn');

    form.addEventListener('submit', function (e) {
        let ok = true;
        let firstInvalid = null;

        function fail(fieldId, errId) {
            const el  = document.getElementById(fieldId);
            const err = document.getElementById(errId);
            el.classList.add('is-invalid');
            if (err) err.style.display = 'flex';
            if (!firstInvalid) firstInvalid = el;
            ok = false;
        }

        if (!document.getElementById('class').value)   fail('class',   'classError');
        if (!document.getElementById('term').value)    fail('term',    'termError');
        if (!validateYear(true)) {
            if (!firstInvalid) firstInvalid = yearInput;
            ok = false;
        }

        const checkedStreams = panel.querySelectorAll('input[type="checkbox"]:checked');
        if (checkedStreams.length === 0) {
            selector.classList.add('invalid');
            streamErr.style.display = 'flex';
            if (!firstInvalid) firstInvalid = trigger;
            ok = false;
        }

        if (!document.getElementById('subject').value) fail('subject', 'subjectError');

        if (!ok) {
            e.preventDefault();
            if (firstInvalid) {
                firstInvalid.focus();
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        }

        // ✓ Valid — activate spinner
        continueBtn.classList.add('loading');
        continueBtn.disabled = true;
        continueBtn.querySelector('.btn-label').textContent = 'Loading…';
    });

}());
</script>
</body>
</html>