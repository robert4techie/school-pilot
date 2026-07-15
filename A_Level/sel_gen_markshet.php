<?php
// Database connection
require_once '../auth.php';
require_once '../conn.php';

// Initialize variables
$class  = $_POST['class']  ?? 'Senior Five';
$term   = $_POST['term']   ?? 'Term 1';
$year   = $_POST['year']   ?? date('Y');
$streams      = $_POST['streams']      ?? [];
$exam_type    = $_POST['exam_type']    ?? '';
$subject_filter = $_POST['subject_filter'] ?? 'all';

// ── Fetch exam types ─────────────────────────────────────────────────────────
function getExamTypes($conn): array
{
    $result = mysqli_query($conn, "SELECT * FROM exam_sets ORDER BY id ASC");
    $out = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) $out[] = $row;
    }
    return $out;
}

// ── Fetch subjects for the subject-filter dropdown ───────────────────────────
// We pull from the subjects table; fall back to an empty list if it doesn't exist.
function getSubjectsList($conn): array
{
    $result = mysqli_query($conn, "SELECT subj_name FROM subjects ORDER BY subj_name ASC");
    $out = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) $out[] = $row['subj_name'];
    }
    return $out;
}

$exam_types   = getExamTypes($conn);
$subjects_list = getSubjectsList($conn);
?>
<!DOCTYPE html>
<html data-bs-theme="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>SchoolPilot — Generate A-Level Marksheet</title>
    <link rel="stylesheet" href="../assets/fonts/fontawesome-all.min.css">
    <link rel="stylesheet" href="../assets/fonts/font-awesome.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400..800&display=swap" rel="stylesheet">
    <style>
        /* ── Design tokens ───────────────────────────────────────────────── */
        :root {
            --green-900: #1a4731;
            --green-700: #1e8449;
            --green-500: #27ae60;
            --green-300: #2ecc71;
            --green-100: #e8f5ee;
            --green-50:  #f2faf6;
            --accent:    #b8972a;
            --red-500:   #e53935;
            --gray-800:  #1e293b;
            --gray-600:  #475569;
            --gray-400:  #94a3b8;
            --gray-200:  #e2e8f0;
            --gray-100:  #f1f5f9;
            --white:     #ffffff;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.04);
            --shadow-md: 0 4px 12px rgba(0,0,0,.10);
            --shadow-lg: 0 10px 30px rgba(0,0,0,.12);
            --radius:    10px;
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

        /* ── Page shell ─────────────────────────────────────────────────── */
        .page-wrapper {
            max-width: 100%;
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
        }
        .hero-icon {
            width: 52px; height: 52px;
            background: rgba(255,255,255,.15);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; flex-shrink: 0;
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
            margin-bottom: 12px;
            padding-bottom: 6px;
            border-bottom: 2px solid var(--green-100);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-label i { font-size: 13px; }

        /* ── Form rows ───────────────────────────────────────────────────── */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px 20px;
            margin-bottom: 28px;
        }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group.full { grid-column: 1 / -1; }

        label.field-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--gray-600);
            letter-spacing: .4px;
        }

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
        }

        /* ── Stream multi-select ─────────────────────────────────────────── */
        .stream-selector {
            border: 1.5px solid var(--gray-200);
            border-radius: 8px;
            background: var(--white);
            transition: var(--transition);
            overflow: hidden;
        }
        .stream-selector:focus-within {
            border-color: var(--green-500);
            box-shadow: 0 0 0 3px rgba(39,174,96,.18);
        }
        .stream-placeholder {
            height: 42px;
            padding: 0 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            font-size: 14px;
            color: var(--gray-600);
            user-select: none;
        }
        .stream-placeholder .arrow { transition: transform .2s; font-size: 10px; color: var(--gray-400); }
        .stream-placeholder.open .arrow { transform: rotate(180deg); }
        .stream-dropdown {
            display: none;
            border-top: 1px solid var(--gray-200);
            max-height: 200px;
            overflow-y: auto;
        }
        .stream-dropdown.open { display: block; }
        .stream-option {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 14px;
            cursor: pointer;
            font-size: 13px;
            transition: background .15s;
        }
        .stream-option:hover { background: var(--green-50); }
        .stream-option input[type="checkbox"] { accent-color: var(--green-700); width: 16px; height: 16px; }
        .stream-option.checked { background: var(--green-100); font-weight: 600; color: var(--green-900); }

        .stream-actions {
            display: flex; gap: 12px; margin-top: 6px; font-size: 12px;
        }
        .stream-actions a {
            color: var(--green-700); text-decoration: none; font-weight: 600;
        }
        .stream-actions a:hover { text-decoration: underline; }

        /* ── Exam type cards ─────────────────────────────────────────────── */
        .exam-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 28px;
        }
        .exam-card {
            border: 2px solid var(--gray-200);
            border-radius: 10px;
            padding: 14px 14px 12px;
            cursor: pointer;
            position: relative;
            transition: var(--transition);
            background: var(--white);
        }
        .exam-card:hover { border-color: var(--green-300); box-shadow: var(--shadow-sm); }
        .exam-card.selected {
            border-color: var(--green-700);
            background: var(--green-50);
            box-shadow: 0 0 0 3px rgba(39,174,96,.18);
        }
        .exam-card input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
        .exam-card-name {
            font-size: 14px; font-weight: 700;
            color: var(--gray-800); margin-bottom: 4px;
            display: flex; align-items: center; gap: 6px;
        }
        .exam-card-name i { color: var(--green-700); }
        .exam-card-desc { font-size: 11px; color: var(--gray-600); margin-bottom: 8px; line-height: 1.5; }
        .exam-card-badge {
            display: inline-block;
            background: var(--green-500); color: #fff;
            font-size: 10px; font-weight: 700;
            border-radius: 50px; padding: 2px 8px;
        }
        .exam-card-check {
            position: absolute; top: 10px; right: 10px;
            color: var(--green-700); font-size: 18px;
            opacity: 0; transition: opacity .2s;
        }
        .exam-card.selected .exam-card-check { opacity: 1; }

        /* ── Subject filter ──────────────────────────────────────────────── */
        .filter-toggle {
            display: flex; gap: 0; margin-bottom: 14px;
            border: 1.5px solid var(--gray-200); border-radius: 8px;
            overflow: hidden;
        }
        .filter-toggle label {
            flex: 1; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            padding: 10px 14px;
            font-size: 13px; font-weight: 600;
            color: var(--gray-600);
            transition: var(--transition);
            border: none; background: transparent;
        }
        .filter-toggle label:not(:last-child) { border-right: 1.5px solid var(--gray-200); }
        .filter-toggle input[type="radio"] { display: none; }
        .filter-toggle input[type="radio"]:checked + span,
        .filter-toggle label:has(input:checked) {
            background: var(--green-700);
            color: var(--white);
        }
        /* Simpler approach: style checked state via JS */
        .filter-toggle label.active {
            background: var(--green-700);
            color: var(--white);
        }

        #subject-select-wrap { display: none; }
        #subject-select-wrap.visible { display: block; }

        /* ── Divider ─────────────────────────────────────────────────────── */
        .divider { border: none; border-top: 1px solid var(--gray-200); margin: 28px 0; }

        /* ── Submit button ───────────────────────────────────────────────── */
        .form-actions { display: flex; justify-content: center; gap: 12px; }

        .btn-primary {
            background: linear-gradient(135deg, var(--green-700), var(--green-500));
            color: #fff;
            border: none;
            border-radius: 8px;
            height: 46px;
            padding: 0 32px;
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
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(30,132,73,.38);
        }
        .btn-primary:active { transform: translateY(0); }

        /* ── Submit button — loading state ───────────────────────────────── */
        .btn-primary:disabled,
        .btn-primary.loading {
            cursor: not-allowed;
            opacity: 0.82;
            transform: none !important;
            box-shadow: 0 4px 14px rgba(30,132,73,.18);
            pointer-events: none;
        }

        /* Spinner SVG injected by JS */
        @keyframes btn-spin {
            to { transform: rotate(360deg); }
        }
        .btn-spinner {
            display: inline-block;
            width: 17px; height: 17px;
            border: 2.5px solid rgba(255,255,255,.40);
            border-top-color: #fff;
            border-radius: 50%;
            animation: btn-spin 0.7s linear infinite;
            flex-shrink: 0;
        }

        /* Subtle progress bar that runs across the top of the page */
        #submit-progress-bar {
            position: fixed;
            top: 0; left: 0;
            height: 3px;
            width: 0;
            background: linear-gradient(90deg, var(--green-300), var(--green-500));
            z-index: 9999;
            border-radius: 0 3px 3px 0;
            transition: width 0.3s ease;
            box-shadow: 0 0 8px rgba(39,174,96,.55);
            display: none;
        }

        /* ── Responsive ──────────────────────────────────────────────────── */
        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
            .hero { padding: 20px; }
            .form-card { padding: 24px 18px 24px; }
        }
    </style>
</head>
<body>
<?php require_once '../nav.php'; ?>

<!-- Top progress bar (shown on submit) -->
<div id="submit-progress-bar"></div>

<div class="page-wrapper">
    <!-- Hero banner -->
    <div class="hero">
        <div class="hero-icon"><i class="fas fa-table"></i></div>
        <div>
            <div class="hero-title">Generate A-Level Marksheet</div>
            <div class="hero-sub">NCDC New Curriculum — A through E Grading System</div>
        </div>
    </div>

    <!-- Form card -->
    <div class="form-card">
        <form id="marksheet-form" method="post" action="view_marksheet.php">

            <!-- ── Section 1: Class & Exam details ── -->
            <div class="section-label"><i class="fas fa-chalkboard"></i> Class &amp; Exam Details</div>
            <div class="form-grid">
                <div class="form-group">
                    <label class="field-label" for="class">Class</label>
                    <select name="class" id="class" class="form-control" required>
                        <option value="">— Select Class —</option>
                        <option value="Senior Five" <?= $class==='Senior Five' ? 'selected':'' ?>>Senior Five</option>
                        <option value="Senior Six"  <?= $class==='Senior Six'  ? 'selected':'' ?>>Senior Six</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="field-label" for="term">Term</label>
                    <select name="term" id="term" class="form-control" required>
                        <option value="">— Select Term —</option>
                        <option value="Term 1" <?= $term==='Term 1' ? 'selected':'' ?>>Term 1</option>
                        <option value="Term 2" <?= $term==='Term 2' ? 'selected':'' ?>>Term 2</option>
                        <option value="Term 3" <?= $term==='Term 3' ? 'selected':'' ?>>Term 3</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="field-label" for="year">Academic Year</label>
                    <input type="number" name="year" id="year" class="form-control"
                           value="<?= htmlspecialchars($year) ?>" min="2000" max="2099" required>
                </div>

                <div class="form-group">
                    <label class="field-label">Stream(s)</label>
                    <div class="stream-selector" id="stream-selector">
                        <div class="stream-placeholder" id="stream-placeholder">
                            <span id="placeholder-text">Select streams</span>
                            <span class="arrow">▼</span>
                        </div>
                        <div class="stream-dropdown" id="stream-dropdown">
                            <label class="stream-option" data-value="Arts">
                                <input type="checkbox" name="streams[]" value="Arts"> Arts
                            </label>
                            <label class="stream-option" data-value="Sciences">
                                <input type="checkbox" name="streams[]" value="Sciences"> Sciences
                            </label>
                        </div>
                    </div>
                    <div class="stream-actions">
                        <a href="#" id="sel-all">Select All</a>
                        <span style="color:var(--gray-400)">|</span>
                        <a href="#" id="desel-all">Deselect All</a>
                    </div>
                </div>
            </div>

            <!-- ── Section 2: Exam Type ── -->
            <div class="section-label"><i class="fas fa-clipboard-check"></i> Exam Type</div>
            <div class="exam-grid">
                <?php foreach ($exam_types as $exam): ?>
                <label class="exam-card <?= $exam_type == $exam['id'] ? 'selected':'' ?>"
                       for="exam-<?= $exam['id'] ?>">
                    <input type="radio" name="exam_type" id="exam-<?= $exam['id'] ?>"
                           value="<?= $exam['id'] ?>"
                           <?= $exam_type == $exam['id'] ? 'checked':'' ?> required>
                    <div class="exam-card-name">
                        <i class="fas fa-file-alt"></i>
                        <?= htmlspecialchars($exam['exam_set']) ?>
                    </div>
                    <div class="exam-card-desc"><?= htmlspecialchars($exam['description'] ?? '') ?></div>
                    <span class="exam-card-badge">Max: <?= htmlspecialchars($exam['exam_mark']) ?></span>
                    <span class="exam-card-check"><i class="fas fa-check-circle"></i></span>
                </label>
                <?php endforeach; ?>
            </div>

            <!-- ── Section 3: Subject filter ── -->
            <div class="section-label"><i class="fas fa-filter"></i> Subject Filter</div>

            <div class="filter-toggle" id="filter-toggle">
                <label id="lbl-all" class="<?= $subject_filter==='all' ? 'active':'' ?>">
                    <input type="radio" name="subject_filter" value="all"
                           <?= $subject_filter==='all' ? 'checked':'' ?>>
                    <span><i class="fas fa-th-list"></i> All Subjects</span>
                </label>
                <label id="lbl-single" class="<?= $subject_filter!=='all' ? 'active':'' ?>">
                    <input type="radio" name="subject_filter" value="single"
                           <?= $subject_filter!=='all' ? 'checked':'' ?>>
                    <span><i class="fas fa-book"></i> Specific Subject</span>
                </label>
            </div>

            <div id="subject-select-wrap" class="<?= $subject_filter!=='all' ? 'visible':'' ?>">
                <div class="form-group" style="max-width:380px">
                    <label class="field-label" for="subject_name">Select Subject</label>
                    <select name="subject_name" id="subject_name" class="form-control">
                        <option value="">— Choose a subject —</option>
                        <?php foreach ($subjects_list as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>"
                            <?= (isset($_POST['subject_name']) && $_POST['subject_name']===$s) ? 'selected':'' ?>>
                            <?= htmlspecialchars($s) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- ── Hidden helpers ── -->
            <input type="hidden" name="exam_name" id="exam_name_hidden" value="">

            <hr class="divider">

            <div class="form-actions">
                <button type="submit" class="btn-primary">
                    <i class="fas fa-table"></i> Generate Marksheet
                </button>
            </div>

        </form>
    </div><!-- /form-card -->
</div><!-- /page-wrapper -->

<script src="../assets/js/jquery.min.js"></script>
<script>
$(function () {

    /* ── Stream dropdown ─────────────────────────────────────────────── */
    const $placeholder = $('#stream-placeholder');
    const $dropdown    = $('#stream-dropdown');

    $placeholder.on('click', function () {
        $dropdown.toggleClass('open');
        $placeholder.toggleClass('open');
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('#stream-selector').length) {
            $dropdown.removeClass('open');
            $placeholder.removeClass('open');
        }
    });

    function updatePlaceholder() {
        const checked = $dropdown.find('input:checked');
        const $txt = $('#placeholder-text');
        if (checked.length === 0)       $txt.text('Select streams');
        else if (checked.length === 1)  $txt.text(checked.first().val());
        else                            $txt.text(checked.length + ' streams selected');

        $dropdown.find('label').each(function () {
            $(this).toggleClass('checked', $(this).find('input').is(':checked'));
        });
    }

    $dropdown.on('change', 'input[type="checkbox"]', updatePlaceholder);

    $('#sel-all').on('click', function (e) {
        e.preventDefault();
        $dropdown.find('input').prop('checked', true).trigger('change');
    });
    $('#desel-all').on('click', function (e) {
        e.preventDefault();
        $dropdown.find('input').prop('checked', false).trigger('change');
    });

    updatePlaceholder();

    /* ── Exam type cards ─────────────────────────────────────────────── */
    $('.exam-card').on('click', function () {
        $('.exam-card').removeClass('selected');
        $(this).addClass('selected');
        $(this).find('input[type="radio"]').prop('checked', true);
        $('#exam_name_hidden').val($(this).find('.exam-card-name').text().trim());
    });

    /* ── Subject filter toggle ───────────────────────────────────────── */
    $('#filter-toggle label').on('click', function () {
        $('#filter-toggle label').removeClass('active');
        $(this).addClass('active');
        const val = $(this).find('input').val();
        if (val === 'single') {
            $('#subject-select-wrap').addClass('visible');
        } else {
            $('#subject-select-wrap').removeClass('visible');
            $('#subject_name').val('');
        }
    });

    /* ── Form validation + loading state ────────────────────────────── */
    $('#marksheet-form').on('submit', function (e) {
        const streams = $('input[name="streams[]"]:checked');
        if (!streams.length) {
            alert('Please select at least one stream.');
            e.preventDefault(); return;
        }
        if (!$('input[name="exam_type"]:checked').length) {
            alert('Please select an exam type.');
            e.preventDefault(); return;
        }
        if ($('#class').val() === '') {
            alert('Please select a class.');
            e.preventDefault(); return;
        }
        const filter = $('input[name="subject_filter"]:checked').val();
        if (filter === 'single' && !$('#subject_name').val()) {
            alert('Please choose a subject to filter by.');
            e.preventDefault(); return;
        }

        /* Convert checkboxes to a comma-separated hidden field */
        $(this).find('input[name="streams_csv"]').remove();
        const vals = streams.map(function(){ return this.value; }).get().join(',');
        $(this).append('<input type="hidden" name="streams_csv" value="' + vals + '">');
        $('input[name="streams[]"]').prop('disabled', true);

        /* ── Loading state: prevent double-submit ── */
        const $btn = $(this).find('button[type="submit"]');

        // Disable immediately to block any further clicks
        $btn.prop('disabled', true).addClass('loading');

        // Swap icon + label to spinner state
        $btn.html(
            '<span class="btn-spinner"></span> Generating Marksheet…'
        );

        // Animate the top progress bar
        var $bar = $('#submit-progress-bar');
        $bar.show().css('width', '0');

        var progress = 0;
        var barTimer = setInterval(function () {
            // Ease toward 85% — never reaches 100% until the new page loads
            var increment = (85 - progress) * 0.08 + 0.5;
            progress = Math.min(progress + increment, 85);
            $bar.css('width', progress + '%');
            if (progress >= 85) clearInterval(barTimer);
        }, 120);
    });
    
        /* ── Reset UI on Page Show (Fixes Back-Button Loader) ── */
    window.addEventListener('pageshow', function (event) {
        const $btn = $('#marksheet-form button[type="submit"]');
        const $bar = $('#submit-progress-bar');
    
        // Restore the button to its original state
        $btn.prop('disabled', false)
            .removeClass('loading')
            .html('<i class="fas fa-file-invoice mr-2"></i> Generate Marksheet');
    
        // Hide and reset the progress bar
        $bar.hide().css('width', '0');
    });
});
</script>
</body>
</html>