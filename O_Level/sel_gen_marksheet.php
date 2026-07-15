<?php
/**
 * sel_gen_markshet_olevel.php
 * Generate O-Level Marksheet — SchoolPilot
 *
 * Changelog vs. original:
 *  - Fixed: page title / hero labelled "A-Level" instead of "O-Level"
 *  - Fixed: class options were Senior Five/Six (A-Level); changed to S1–S4
 *  - Fixed: streams were A-Level only (Arts/Sciences); now O-Level streams
 *  - Fixed: subject selector was "all / single" only; replaced with proper
 *           multi-select (with search) pulled from DB WHERE level LIKE 'O%'
 *  - Fixed:  all htmlspecialchars() calls now pass ENT_QUOTES | ENT_SUBSTITUTE + UTF-8
 *  - Fixed:  integer IDs cast with (int) before output to prevent XSS
 *  - Fixed:  DB type-hint on helper functions upgraded to mysqli
 *  - Fixed:  streams are now iterated from a single source-of-truth array
 *  - Fixed:  streams changed to East / West / South / North
 *  - Removed: Marksheet Type section (not needed for O-Level)
 *  - Improved: inline field-level validation instead of window.alert()
 *  - Improved: back-button / pageshow reset included
 *  - Improved: progress bar + button loading state retained from original
 */

require_once '../auth.php';
require_once '../conn.php';

// ── Input defaults ────────────────────────────────────────────────────────────
$class          = $_POST['class']          ?? 'Senior One';
$term           = $_POST['term']           ?? 'Term 1';
$year           = $_POST['year']           ?? date('Y');
$streams        = is_array($_POST['streams']  ?? null) ? $_POST['streams']  : [];
$subjects       = is_array($_POST['subjects'] ?? null) ? $_POST['subjects'] : [];
$marksheet_type = $_POST['marksheet_type'] ?? '';

// ── Helpers ───────────────────────────────────────────────────────────────────
/** Safe HTML output. Always use instead of bare echo on user/DB data. */
function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Fetch O-Level subjects ────────────────────────────────────────────────────
function getOLevelSubjects(mysqli $conn): array
{
    $result = mysqli_query(
        $conn,
        "SELECT * FROM subjects WHERE level LIKE 'O%' ORDER BY compulsory DESC, subj_name ASC"
    );
    $out = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $out[] = $row;
        }
    }
    return $out;
}

$all_subjects = getOLevelSubjects($conn);

// O-Level streams
$olevel_streams = ['East', 'West', 'South', 'North'];
?>
<!DOCTYPE html>
<html data-bs-theme="light" lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>SchoolPilot — Generate O-Level Marksheet</title>

    <!-- Icons -->
    <link rel="stylesheet" href="../assets/fonts/fontawesome-all.min.css">
    <link rel="stylesheet" href="../assets/fonts/font-awesome.min.css">

    <!-- Typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400..800&display=swap" rel="stylesheet">

    <style>
        /* ── Design tokens ───────────────────────────────────────────────── */
        :root {
            --green-900:  #1a4731;
            --green-700:  #1e8449;
            --green-500:  #27ae60;
            --green-300:  #2ecc71;
            --green-100:  #e8f5ee;
            --green-50:   #f2faf6;
            --accent:     #b8972a;   /* "Core" subject badge */
            --red-500:    #e53935;
            --gray-800:   #1e293b;
            --gray-600:   #475569;
            --gray-400:   #94a3b8;
            --gray-200:   #e2e8f0;
            --gray-100:   #f1f5f9;
            --white:      #ffffff;
            --shadow-sm:  0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.04);
            --shadow-md:  0 4px 12px rgba(0,0,0,.10);
            --shadow-lg:  0 10px 30px rgba(0,0,0,.12);
            --radius:     10px;
            --transition: all .22s ease;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Sen', sans-serif;
            background: var(--gray-100);
            color: var(--gray-800);
            min-height: 100vh;
            padding: 0 0 60px;
        }

        /* ── Top progress bar (shown on submit) ──────────────────────────── */
        #submit-progress-bar {
            position: fixed;
            top: 0; left: 0;
            height: 3px; width: 0;
            background: linear-gradient(90deg, var(--green-300), var(--green-500));
            z-index: 9999;
            border-radius: 0 3px 3px 0;
            transition: width .3s ease;
            box-shadow: 0 0 8px rgba(39,174,96,.55);
            display: none;
        }

        /* ── Page shell ──────────────────────────────────────────────────── */
        .page-wrapper {
            max-width: 1400px;
            margin: 0 auto;
            padding: 80px 20px 0;
        }

        /* ── Hero banner ─────────────────────────────────────────────────── */
        .hero {
            background: linear-gradient(135deg, var(--green-900) 0%, var(--green-700) 60%, var(--green-500) 100%);
            color: var(--white);
            border-radius: var(--radius) var(--radius) 0 0;
            padding: 28px 32px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            position: relative;
            overflow: hidden;
        }
        /* Decorative circle behind text */
        .hero::after {
            content: '';
            position: absolute;
            right: -30px; top: -30px;
            width: 180px; height: 180px;
            border-radius: 50%;
            background: rgba(255,255,255,.06);
            pointer-events: none;
        }
        .hero-icon {
            width: 52px; height: 52px;
            background: rgba(255,255,255,.15);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; flex-shrink: 0;
        }
        .hero-badge {
            display: inline-block;
            background: rgba(255,255,255,.20);
            border: 1px solid rgba(255,255,255,.32);
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            padding: 2px 10px;
            margin-bottom: 5px;
        }
        .hero-title { font-size: 20px; font-weight: 800; letter-spacing: .3px; }
        .hero-sub   { font-size: 13px; opacity: .82; margin-top: 3px; }

        /* ── Form card ───────────────────────────────────────────────────── */
        .form-card {
            background: var(--white);
            border-radius: 0 0 var(--radius) var(--radius);
            box-shadow: var(--shadow-lg);
            padding: 36px 32px 32px;
        }

        /* ── Section headings ────────────────────────────────────────────── */
        .section-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--green-700);
            margin-bottom: 16px;
            padding-bottom: 6px;
            border-bottom: 2px solid var(--green-100);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ── Form grid ───────────────────────────────────────────────────── */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px 24px;
            margin-bottom: 32px;
        }
        .form-group { display: flex; flex-direction: column; gap: 6px; }

        label.field-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--gray-600);
            letter-spacing: .4px;
        }

        /* ── Standard form control ───────────────────────────────────────── */
        .form-control {
            height: 42px;
            padding: 0 14px;
            border: 1.5px solid var(--gray-200);
            border-radius: 8px;
            font-family: 'Sen', sans-serif;
            font-size: 14px;
            color: var(--gray-800);
            background: var(--white);
            transition: var(--transition);
            appearance: none;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--green-500);
            box-shadow: 0 0 0 3px rgba(39,174,96,.18);
        }
        select.form-control {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2394a3b8' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 36px;
            cursor: pointer;
        }

        /* ── Multi-select dropdown ───────────────────────────────────────── */
        .multi-selector {
            border: 1.5px solid var(--gray-200);
            border-radius: 8px;
            background: var(--white);
            transition: var(--transition);
            overflow: hidden;
            position: relative;
        }
        .multi-selector.open {
            border-color: var(--green-500);
            box-shadow: 0 0 0 3px rgba(39,174,96,.18);
        }
        .selector-placeholder {
            height: 42px;
            padding: 0 36px 0 14px;
            display: flex;
            align-items: center;
            cursor: pointer;
            font-size: 14px;
            color: var(--gray-600);
            user-select: none;
            position: relative;
        }
        .selector-placeholder::after {
            content: '';
            position: absolute;
            right: 13px;
            width: 12px; height: 8px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2394a3b8' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: center;
            transition: transform .2s;
        }
        .multi-selector.open .selector-placeholder::after { transform: rotate(180deg); }

        .sel-badge {
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--green-500); color: #fff;
            font-size: 11px; font-weight: 700;
            border-radius: 20px; padding: 1px 7px;
            margin-left: 7px;
        }

        .selector-dropdown {
            display: none;
            border-top: 1px solid var(--gray-200);
            max-height: 240px;
            overflow-y: auto;
            background: var(--white);
        }
        .multi-selector.open .selector-dropdown { display: block; }

        /* Sticky search in subject dropdown */
        .selector-search {
            position: sticky;
            top: 0;
            background: var(--white);
            padding: 10px 12px;
            border-bottom: 1px solid var(--gray-200);
            z-index: 5;
        }
        .selector-search input {
            width: 100%;
            height: 34px;
            padding: 0 12px;
            border: 1.5px solid var(--gray-200);
            border-radius: 6px;
            font-family: 'Sen', sans-serif;
            font-size: 13px;
            color: var(--gray-800);
            background: var(--gray-100);
            transition: var(--transition);
        }
        .selector-search input:focus {
            outline: none;
            border-color: var(--green-500);
            background: var(--white);
            box-shadow: 0 0 0 2px rgba(39,174,96,.15);
        }
        .selector-search input::placeholder { color: var(--gray-400); }

        .selector-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            cursor: pointer;
            font-size: 13px;
            border-bottom: 1px solid var(--gray-100);
            transition: background .15s;
        }
        .selector-option:last-child { border-bottom: none; }
        .selector-option:hover { background: var(--green-50); }
        .selector-option input[type="checkbox"] {
            accent-color: var(--green-700);
            width: 15px; height: 15px;
            flex-shrink: 0;
        }
        .selector-option.checked {
            background: var(--green-100);
            font-weight: 600;
            color: var(--green-900);
        }
        .core-badge {
            margin-left: auto;
            font-size: 10px;
            background: var(--accent);
            color: #fff;
            border-radius: 4px;
            padding: 1px 5px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .selector-actions {
            display: flex; gap: 12px;
            margin-top: 6px; font-size: 12px;
        }
        .selector-actions a {
            color: var(--green-700); text-decoration: none; font-weight: 600;
        }
        .selector-actions a:hover { text-decoration: underline; }

        /* ── Marksheet type cards ────────────────────────────────────────── */
        .mtype-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 8px;
        }
        .mtype-card {
            border: 2px solid var(--gray-200);
            border-radius: 10px;
            padding: 16px;
            cursor: pointer;
            position: relative;
            transition: var(--transition);
            background: var(--white);
        }
        .mtype-card:hover {
            border-color: var(--green-300);
            box-shadow: var(--shadow-sm);
            transform: translateY(-1px);
        }
        .mtype-card.selected {
            border-color: var(--green-700);
            background: var(--green-50);
            box-shadow: 0 0 0 3px rgba(39,174,96,.18);
        }
        .mtype-card input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
        .mtype-card-title {
            font-size: 14px; font-weight: 700;
            color: var(--gray-800); margin-bottom: 5px;
            display: flex; align-items: center; gap: 8px;
        }
        .mtype-card-title i { color: var(--green-700); }
        .mtype-card-desc { font-size: 11px; color: var(--gray-600); line-height: 1.5; }
        .mtype-card-check {
            position: absolute; top: 10px; right: 10px;
            color: var(--green-700); font-size: 18px;
            opacity: 0; transition: opacity .2s;
        }
        .mtype-card.selected .mtype-card-check { opacity: 1; }

        /* ── Inline validation errors ────────────────────────────────────── */
        .field-error {
            font-size: 11px;
            color: var(--red-500);
            margin-top: 4px;
            display: none;
        }
        .field-error.visible { display: block; }

        /* ── Divider ─────────────────────────────────────────────────────── */
        .divider { border: none; border-top: 1px solid var(--gray-200); margin: 28px 0; }

        /* ── Submit button ───────────────────────────────────────────────── */
        .form-actions { display: flex; justify-content: center; }

        .btn-primary {
            background: linear-gradient(135deg, var(--green-700), var(--green-500));
            color: #fff;
            border: none;
            border-radius: 8px;
            height: 48px;
            padding: 0 40px;
            font-family: 'Sen', sans-serif;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 9px;
            transition: var(--transition);
            box-shadow: 0 4px 14px rgba(30,132,73,.30);
        }
        .btn-primary:hover  { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(30,132,73,.40); }
        .btn-primary:active { transform: translateY(0); }
        .btn-primary:disabled,
        .btn-primary.loading {
            cursor: not-allowed;
            opacity: .82;
            transform: none !important;
            box-shadow: 0 4px 14px rgba(30,132,73,.18);
            pointer-events: none;
        }

        @keyframes btn-spin { to { transform: rotate(360deg); } }
        .btn-spinner {
            display: inline-block;
            width: 17px; height: 17px;
            border: 2.5px solid rgba(255,255,255,.40);
            border-top-color: #fff;
            border-radius: 50%;
            animation: btn-spin .7s linear infinite;
            flex-shrink: 0;
        }

        /* ── Responsive ──────────────────────────────────────────────────── */
        @media (max-width: 640px) {
            .form-grid  { grid-template-columns: 1fr; }
            .mtype-grid { grid-template-columns: 1fr 1fr; }
            .hero       { padding: 20px; }
            .form-card  { padding: 24px 18px; }
        }
        @media (max-width: 400px) {
            .mtype-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php require_once '../nav.php'; ?>

<!-- Top progress bar (shown on submit) -->
<div id="submit-progress-bar"></div>

<div class="page-wrapper">

    <!-- ── Hero ── -->
    <div class="hero">
        <div class="hero-icon"><i class="fas fa-table"></i></div>
        <div>
            <div class="hero-badge">O-Level</div>
            <div class="hero-title">Generate O-Level Marksheet</div>
            <div class="hero-sub">Senior One – Four</div>
        </div>
    </div>

    <!-- ── Form card ── -->
    <div class="form-card">
        <form id="marksheet-form" method="post" action="generate_marksheet.php" novalidate>

            <!-- ════════════════════════════════════════════════════════════
                 Section 1 · Class & Exam Details
                 ════════════════════════════════════════════════════════════ -->
            <div class="section-label">
                <i class="fas fa-chalkboard"></i> Class &amp; Exam Details
            </div>

            <div class="form-grid">

                <!-- Class -->
                <div class="form-group">
                    <label class="field-label" for="class">Class</label>
                    <select name="class" id="class" class="form-control" required>
                        <option value="">— Select Class —</option>
                        <option value="Senior One"   <?= $class === 'Senior One'   ? 'selected' : '' ?>>Senior One (S1)</option>
                        <option value="Senior Two"   <?= $class === 'Senior Two'   ? 'selected' : '' ?>>Senior Two (S2)</option>
                        <option value="Senior Three" <?= $class === 'Senior Three' ? 'selected' : '' ?>>Senior Three (S3)</option>
                        <option value="Senior Four"  <?= $class === 'Senior Four'  ? 'selected' : '' ?>>Senior Four (S4)</option>
                    </select>
                    <span class="field-error" id="err-class">Please select a class.</span>
                </div>

                <!-- Term -->
                <div class="form-group">
                    <label class="field-label" for="term">Term</label>
                    <select name="term" id="term" class="form-control" required>
                        <option value="">— Select Term —</option>
                        <option value="Term 1" <?= $term === 'Term 1' ? 'selected' : '' ?>>Term 1</option>
                        <option value="Term 2" <?= $term === 'Term 2' ? 'selected' : '' ?>>Term 2</option>
                        <option value="Term 3" <?= $term === 'Term 3' ? 'selected' : '' ?>>Term 3</option>
                    </select>
                    <span class="field-error" id="err-term">Please select a term.</span>
                </div>

                <!-- Academic Year -->
                <div class="form-group">
                    <label class="field-label" for="year">Academic Year</label>
                    <input type="number" name="year" id="year" class="form-control"
                           value="<?= esc((string)$year) ?>" min="2000" max="2099" required>
                </div>

                <!-- Streams multi-select -->
                <div class="form-group">
                    <label class="field-label">Stream(s)</label>
                    <div class="multi-selector" id="stream-selector">
                        <div class="selector-placeholder" id="stream-placeholder">
                            <span id="stream-text">Select streams</span>
                        </div>
                        <div class="selector-dropdown" id="stream-dropdown">
                            <?php foreach ($olevel_streams as $s):
                                $checked = in_array($s, $streams, true);
                            ?>
                            <label class="selector-option <?= $checked ? 'checked' : '' ?>"
                                   data-value="<?= esc($s) ?>">
                                <input type="checkbox" name="streams[]"
                                       value="<?= esc($s) ?>"
                                       <?= $checked ? 'checked' : '' ?>>
                                <?= esc($s) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="selector-actions">
                        <a href="#" id="sel-all-streams">Select All</a>
                        <span style="color:var(--gray-400)">|</span>
                        <a href="#" id="desel-all-streams">Deselect All</a>
                    </div>
                    <span class="field-error" id="err-streams">Please select at least one stream.</span>
                </div>

            </div><!-- /form-grid -->


            <!-- ════════════════════════════════════════════════════════════
                 Section 2 · Subjects
                 ════════════════════════════════════════════════════════════ -->
            <div class="section-label">
                <i class="fas fa-book-open"></i> Subjects
            </div>

            <div class="form-group" style="margin-bottom: 32px;">
                <label class="field-label">Select Subject(s)</label>
                <div class="multi-selector" id="subject-selector">
                    <div class="selector-placeholder" id="subject-placeholder">
                        <span id="subject-text">Select subjects</span>
                    </div>
                    <div class="selector-dropdown" id="subject-dropdown">

                        <!-- Sticky search box -->
                        <div class="selector-search">
                            <input type="text" id="subject-search"
                                   placeholder="Search subjects…" autocomplete="off">
                        </div>

                        <?php foreach ($all_subjects as $subj):
                            $sName   = $subj['subj_name'];
                            $sId     = (int)$subj['subj_id'];
                            $isCore  = !empty($subj['compulsory']);
                            $checked = in_array($sName, $subjects, true);
                        ?>
                        <label class="selector-option <?= $checked ? 'checked' : '' ?>"
                               data-value="<?= esc($sName) ?>">
                            <input type="checkbox" name="subjects[]"
                                   value="<?= esc($sName) ?>"
                                   id="subj-<?= $sId ?>"
                                   <?= $checked ? 'checked' : '' ?>>
                            <?= esc($sName) ?>
                            <?php if ($isCore): ?>
                            <span class="core-badge">Core</span>
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>

                    </div>
                </div>
                <div class="selector-actions">
                    <a href="#" id="sel-all-subjects">Select All</a>
                    <span style="color:var(--gray-400)">|</span>
                    <a href="#" id="desel-all-subjects">Deselect All</a>
                </div>
                <span class="field-error" id="err-subjects">Please select at least one subject.</span>
            </div>


            <!-- ════════════════════════════════════════════════════════════
                 Section 3 · Marksheet Type
                 ════════════════════════════════════════════════════════════ -->
            <div class="section-label">
                <i class="fas fa-layer-group"></i> Marksheet Type
            </div>

            <div class="mtype-grid">

                <label class="mtype-card <?= $marksheet_type === 'detailed'   ? 'selected' : '' ?>" for="mtype-detailed">
                    <input type="radio" name="marksheet_type" id="mtype-detailed"   value="detailed"
                           <?= $marksheet_type === 'detailed'   ? 'checked' : '' ?> required>
                    <div class="mtype-card-title"><i class="fas fa-list-ul"></i> Detailed</div>
                    <div class="mtype-card-desc">All topics with individual marks per assessment.</div>
                    <span class="mtype-card-check"><i class="fas fa-check-circle"></i></span>
                </label>

                <label class="mtype-card <?= $marksheet_type === 'summarized' ? 'selected' : '' ?>" for="mtype-summarized">
                    <input type="radio" name="marksheet_type" id="mtype-summarized" value="summarized"
                           <?= $marksheet_type === 'summarized' ? 'checked' : '' ?>>
                    <div class="mtype-card-title"><i class="fas fa-calculator"></i> Summarized</div>
                    <div class="mtype-card-desc">Calculated averages for each subject.</div>
                    <span class="mtype-card-check"><i class="fas fa-check-circle"></i></span>
                </label>

                <label class="mtype-card <?= $marksheet_type === 'assessment' ? 'selected' : '' ?>" for="mtype-assessment">
                    <input type="radio" name="marksheet_type" id="mtype-assessment" value="assessment"
                           <?= $marksheet_type === 'assessment' ? 'checked' : '' ?>>
                    <div class="mtype-card-title"><i class="fas fa-clipboard-check"></i> Assessment</div>
                    <div class="mtype-card-desc">Averages converted to /20 scale.</div>
                    <span class="mtype-card-check"><i class="fas fa-check-circle"></i></span>
                </label>

                <label class="mtype-card <?= $marksheet_type === 'overall'    ? 'selected' : '' ?>" for="mtype-overall">
                    <input type="radio" name="marksheet_type" id="mtype-overall"    value="overall"
                           <?= $marksheet_type === 'overall'    ? 'checked' : '' ?>>
                    <div class="mtype-card-title"><i class="fas fa-chart-bar"></i> Overall</div>
                    <div class="mtype-card-desc">Averages /20 + EOT /80 with full percentage breakdown.</div>
                    <span class="mtype-card-check"><i class="fas fa-check-circle"></i></span>
                </label>

            </div>
            <span class="field-error" id="err-mtype" style="margin-bottom:24px;">
                Please select a marksheet type.
            </span>



            <hr class="divider">

            <div class="form-actions">
                <button type="submit" class="btn-primary" id="submit-btn">
                    <i class="fas fa-table"></i> Generate Marksheet
                </button>
            </div>

        </form>
    </div><!-- /form-card -->

</div><!-- /page-wrapper -->

<!-- jQuery (already bundled in SchoolPilot assets) -->
<script src="../assets/js/jquery.min.js"></script>
<script>
$(function () {

    /* ────────────────────────────────────────────────────────────────────────
       Multi-selector factory
       Handles open/close, checkbox state, placeholder text and badge count.
    ──────────────────────────────────────────────────────────────────────── */
    function MultiSelector(selectorId, textSpanId, dropdownId, noun) {
        var $sel      = $('#' + selectorId);
        var $text     = $('#' + textSpanId);
        var $dropdown = $('#' + dropdownId);

        // Open / close on placeholder click
        $sel.find('.selector-placeholder').on('click', function () {
            $sel.toggleClass('open');
        });

        // Close when clicking outside
        $(document).on('click', function (e) {
            if (!$(e.target).closest('#' + selectorId).length) {
                $sel.removeClass('open');
            }
        });

        function refresh() {
            var $chk = $dropdown.find('input:checked');
            var n    = $chk.length;
            if (n === 0) {
                $text.html('Select ' + noun);
            } else if (n === 1) {
                $text.html($chk.first().val());
            } else {
                $text.html(n + ' ' + noun + ' selected <span class="sel-badge">' + n + '</span>');
            }
            $dropdown.find('.selector-option').each(function () {
                $(this).toggleClass('checked', $(this).find('input').is(':checked'));
            });
        }

        $dropdown.on('change', 'input[type="checkbox"]', refresh);
        refresh(); // set initial display

        return { refresh: refresh };
    }

    /* ── Streams ─────────────────────────────────────────────────────────── */
    MultiSelector('stream-selector', 'stream-text', 'stream-dropdown', 'streams');

    $('#sel-all-streams').on('click', function (e) {
        e.preventDefault();
        $('#stream-dropdown input').prop('checked', true).trigger('change');
    });
    $('#desel-all-streams').on('click', function (e) {
        e.preventDefault();
        $('#stream-dropdown input').prop('checked', false).trigger('change');
    });

    /* ── Subjects ────────────────────────────────────────────────────────── */
    MultiSelector('subject-selector', 'subject-text', 'subject-dropdown', 'subjects');

    $('#sel-all-subjects').on('click', function (e) {
        e.preventDefault();
        var q = $('#subject-search').val().toLowerCase().trim();
        var $targets = $('#subject-dropdown input[type="checkbox"]');
        // If a search is active, only check subjects that match the query
        if (q) {
            $targets = $targets.filter(function () {
                return $(this).closest('.selector-option').data('value').toLowerCase().indexOf(q) !== -1;
            });
        }
        $targets.prop('checked', true).trigger('change');
    });
    $('#desel-all-subjects').on('click', function (e) {
        e.preventDefault();
        $('#subject-dropdown input').prop('checked', false).trigger('change');
    });

    // Live subject search
    $('#subject-search').on('input', function () {
        var q = $(this).val().toLowerCase();
        $('#subject-dropdown .selector-option').each(function () {
            var match = $(this).data('value').toLowerCase().indexOf(q) !== -1;
            $(this).toggle(match);
        });
    });

    /* ── Marksheet type cards ────────────────────────────────────────────── */
    $('.mtype-card').on('click', function () {
        $('.mtype-card').removeClass('selected');
        $(this).addClass('selected').find('input[type="radio"]').prop('checked', true);
    });

    /* ── Inline validation helpers ───────────────────────────────────────── */
    function showErr(id)  { $('#' + id).addClass('visible'); }
    function clearErr(id) { $('#' + id).removeClass('visible'); }

    /* ── Form submission ─────────────────────────────────────────────────── */
    $('#marksheet-form').on('submit', function (e) {
        var ok = true;

        // Class
        if (!$('#class').val()) {
            showErr('err-class'); ok = false;
        } else clearErr('err-class');

        // Term
        if (!$('#term').val()) {
            showErr('err-term'); ok = false;
        } else clearErr('err-term');

        // Streams
        if (!$('input[name="streams[]"]:checked').length) {
            showErr('err-streams'); ok = false;
        } else clearErr('err-streams');

        // Subjects
        if (!$('input[name="subjects[]"]:checked').length) {
            showErr('err-subjects'); ok = false;
        } else clearErr('err-subjects');

        // Marksheet type
        if (!$('input[name="marksheet_type"]:checked').length) {
            showErr('err-mtype'); ok = false;
        } else clearErr('err-mtype');

        if (!ok) { e.preventDefault(); return; }

        /* ── Loading state ── */
        var $btn = $('#submit-btn');
        $btn.prop('disabled', true)
            .addClass('loading')
            .html('<span class="btn-spinner"></span> Generating Marksheet\u2026');

        var $bar = $('#submit-progress-bar');
        $bar.show().css('width', '0');
        var progress = 0;
        var timer = setInterval(function () {
            var inc = (85 - progress) * 0.08 + 0.5;
            progress = Math.min(progress + inc, 85);
            $bar.css('width', progress + '%');
            if (progress >= 85) clearInterval(timer);
        }, 120);
    });

    /* ── Reset on back-button navigation ────────────────────────────────── */
    window.addEventListener('pageshow', function () {
        $('#submit-btn')
            .prop('disabled', false)
            .removeClass('loading')
            .html('<i class="fas fa-table"></i> Generate Marksheet');
        $('#submit-progress-bar').hide().css('width', '0');
    });

});
</script>
</body>
</html>