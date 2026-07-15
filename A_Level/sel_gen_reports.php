<?php
// Database connection
require_once '../auth.php';
require_once '../conn.php';
// Initialize variables
$class = isset($_POST['class']) ? $_POST['class'] : 'Senior Five';
$term = isset($_POST['term']) ? $_POST['term'] : 'Term 1';
$current_year = date("Y");
$year = isset($_POST['year']) ? $_POST['year'] : $current_year;
$streams = isset($_POST['streams']) ? (is_array($_POST['streams']) ? $_POST['streams'] : explode(',', $_POST['streams'])) : [];

// Initialize an array to store exam sets
$examSets = [];

// Check if class is selected to load exam sets
if (!empty($class)) {
    // Query to get exam sets based on the selected class - USING MYSQLI
    $sql = "SELECT * FROM exam_sets WHERE classes LIKE ? ORDER BY id ASC";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        // For mysqli, we use bind_param and need to specify the data type ('s' for string)
        $param = "%$class%";
        $stmt->bind_param('s', $param);
        $stmt->execute();

        // Get the result
        $result = $stmt->get_result();

        // Fetch all results into array
        $examSets = [];
        while ($row = $result->fetch_assoc()) {
            $examSets[] = $row;
        }

        $stmt->close();
    } else {
        // Handle prepare error
        $error = $conn->error;
        // You might want to log this error or display it for debugging
    }
}

// Get any previously selected exam_sets (if form was submitted before)
$selectedExamSets = isset($_POST['exam_sets']) ? (is_array($_POST['exam_sets']) ? $_POST['exam_sets'] : explode(',', $_POST['exam_sets'])) : [];

// Check if there's an error message stored in the session

$error_message = isset($_SESSION['error']) ? $_SESSION['error'] : '';
unset($_SESSION['error']); // Clear the error message after reading it
?>
<!DOCTYPE html>
<html data-bs-theme="light" lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Generate A Level Reports</title>
    <link rel="stylesheet" href="../assets/fonts/font-awesome.min.css">
    <link rel="stylesheet" href="../assets/fonts/fontawesome5-overrides.min.css">
   <style>
        /* ══════════════════════════════════════════════════
           sel_gen_reports.php – Page styles (restyled to match sel_targets.php)
           ══════════════════════════════════════════════════ */
        :root {
            --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
            --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
            --primary-color: #388e3c;
            --primary-light: #43a047;
            --primary-dark: #1b5e20;
            --accent-color: #66bb6a;
            --white: #ffffff;
            --light-gray: #f5f7f5;
            --gray: #d0dbd1;
            --dark-gray: #757575;
            --text-dark: #222222;
            --danger-bg: #f8d7da;
            --danger-text: #721c24;
            --danger-border: #f5c6cb;
            --sp-red:#c62828;
            --sp-radius: 8px;
            --sp-radius-lg: 12px;
            --shadow: 0 2px 8px rgba(0,0,0,.10);
            --shadow-lg: 0 8px 28px rgba(0,0,0,.14);
            --transition: all .22s ease;
        }

        /* Basic Body and Layout Styling */
        body {
            background-color: #f1f4f1;
            color: var(--text-dark);
            margin: 0;
            padding: 20px;
            font-size: 14px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
        }

        .main_container {
            width: 100%;
            max-width: 1000px;
            margin-top: 60px;
            padding: 0 20px 60px;
            box-sizing: border-box;
        }

        /* Page header (new, purely visual) */
        .gr-page-header {
            background: linear-gradient(135deg, var(--g900) 0%, var(--g700) 100%);
            border-radius: var(--sp-radius-lg);
            padding: 26px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 18px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-lg);
        }
        .gr-ph-left h1 {
            color: #fff;
            font-size: 1.35rem;
            font-weight: 700;
            margin: 0 0 3px;
        }
        .gr-ph-left p {
            color: rgba(255,255,255,.8);
            font-size: .875rem;
            margin: 0;
        }
        .gr-ph-icon {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: rgba(255,255,255,.15);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.15rem;
            flex-shrink: 0;
        }

        .gr-info-box {
            background: var(--g50);
            border: 1px solid #a5d6a7;
            border-radius: var(--sp-radius);
            padding: 13px 16px;
            display: flex;
            gap: 11px;
            align-items: flex-start;
            font-size: .83rem;
            color: var(--g900);
            margin-bottom: 22px;
        }
        .gr-info-box i {
            color: var(--g700);
            margin-top: 1px;
            flex-shrink: 0;
        }

        /* Card Styling */
        .card {
            background-color: var(--white);
            border-radius: var(--sp-radius-lg);
            box-shadow: var(--shadow);
            overflow: visible;
            margin-top: 0;
        }

        .card-header {
            padding: 18px 25px 16px;
            border-bottom: 1px solid #f0f4f1;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header h2 {
            margin: 0;
            font-size: 1rem;
            color: #1a1a1a;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-header h2:before {
            content: '\f0c9';
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            color: var(--g700);
            font-size: .9em;
        }

        .card-body {
            padding: 26px 25px;
        }

        /* Form Layout — vertical, label-above-field like sel_targets.php */
        .form-group {
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 6px;
            margin-bottom: 20px;
        }

        .form-label {
            flex: none;
            font-weight: 600;
            font-size: .85rem;
            color: #333;
            padding-right: 0;
            box-sizing: border-box;
        }

        .form-field {
            flex: none;
            width: 100%;
            min-width: 0;
        }

        /* General Input and Select Styling */
        .form-control,
        .form-select {
            width: 100%;
            height: 44px;
            padding: 0 15px;
            border: 1.5px solid var(--gray);
            border-radius: var(--sp-radius);
            background-color: var(--white);
            color: var(--text-dark);
            font-size: .9rem;
            font-family: inherit;
            transition: border-color .22s ease, box-shadow .22s ease;
            box-sizing: border-box;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }

        .form-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23388e3c' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            padding-right: 40px;
            cursor: pointer;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--g600);
            box-shadow: 0 0 0 3px rgba(67,160,71,.12);
        }

        /* Custom Multi-select Dropdown (visual refresh of same markup/IDs) */
        .selector {
            position: relative;
        }

        .placeholder {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
            height: 44px;
            padding: 0 38px 0 15px;
            border: 1.5px solid var(--gray);
            border-radius: var(--sp-radius);
            background-color: var(--white);
            color: #222;
            cursor: pointer;
            transition: border-color .22s ease, box-shadow .22s ease;
            box-sizing: border-box;
            font-size: .9rem;
        }

        .placeholder:after {
            content: '';
            position: absolute;
            right: 15px;
            width: 16px;
            height: 16px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23388e3c' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: center;
            transition: transform 0.2s ease;
        }

        .placeholder.active:after {
            transform: rotate(180deg);
        }

        .placeholder.active,
        .placeholder:hover {
            border-color: var(--g600);
        }

        .placeholder.active {
            box-shadow: 0 0 0 3px rgba(67,160,71,.12);
        }

        .options {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            background-color: var(--white);
            border: 1.5px solid var(--gray);
            border-radius: var(--sp-radius);
            box-shadow: var(--shadow-lg);
            max-height: 250px;
            overflow-y: auto;
            z-index: 500;
        }

        .option {
            display: flex;
            align-items: center;
            padding: 10px 14px;
            cursor: pointer;
            transition: background-color .22s ease;
            border-bottom: 1px solid #f0f4f1;
            font-size: .875rem;
        }

        .option:last-child {
            border-bottom: none;
        }

        .option:hover {
            background-color: var(--g50);
        }

        .option.selected {
            background-color: var(--g50);
            color: var(--g900);
            font-weight: 600;
        }

        .option.selected:hover {
            background-color: var(--g100);
        }

        .option input[type="checkbox"] {
            width: 16px;
            height: 16px;
            margin-right: 12px;
            accent-color: var(--g700);
            cursor: pointer;
            flex-shrink: 0;
        }

        .selected-count {
            background-color: var(--g700);
            color: var(--white);
            font-size: 11px;
            font-weight: 600;
            border-radius: 10px;
            padding: 3px 8px;
            margin-left: 8px;
        }

        /* Select All / Deselect All Links */
        .select-actions {
            margin-top: 2px;
            font-size: .78rem;
        }

        .select-actions a {
            color: var(--g700);
            text-decoration: none;
            font-weight: 600;
        }

        .select-actions a:hover {
            color: var(--g900);
            text-decoration: underline;
        }

        .select-actions span {
            margin: 0 8px;
            color: var(--gray);
        }

        /* Separator and Button */
        .form-separator {
            border-top: 1px solid #e8ede9;
            margin: 24px 0 0;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            padding-top: 20px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            height: 44px;
            padding: 0 24px;
            border: none;
            border-radius: var(--sp-radius);
            font-size: .92rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-submit {
            background-color: var(--g700);
            color: var(--white);
        }

        .btn-submit:hover {
            background-color: var(--g800);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .btn i {
            margin-right: 0;
        }

        /* Alert Styling */
        .alert {
            padding: 13px 16px;
            margin-bottom: 22px;
            border-radius: var(--sp-radius);
            display: flex;
            align-items: center;
            font-size: .87rem;
        }

        .alert-danger {
            background-color: var(--danger-bg);
            color: var(--danger-text);
            border: 1px solid var(--danger-border);
        }

        .alert i {
            margin-right: 10px;
        }

        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .main_container {
                padding: 0 10px 40px;
            }

            .gr-page-header {
                padding: 20px;
            }

            .form-actions {
                justify-content: center;
            }
        }

        .btn-submit:disabled,
        .btn-submit.loading {
            cursor: not-allowed;
            opacity: 0.82;
            pointer-events: none;
            transform: none !important;
        }

        /* Spinner inside the button */
        @keyframes btn-spin {
            to {
                transform: rotate(360deg);
            }
        }

        .btn-spinner {
            display: inline-block;
            width: 15px;
            height: 15px;
            border: 2.5px solid rgba(255, 255, 255, .35);
            border-top-color: #fff;
            border-radius: 50%;
            animation: btn-spin .7s linear infinite;
            vertical-align: middle;
            margin-right: 7px;
            flex-shrink: 0;
        }

        /* Slim top-of-page progress bar */
        #submit-progress-bar {
            position: fixed;
            top: 0;
            left: 0;
            height: 3px;
            width: 0;
            background: linear-gradient(90deg, var(--g600), var(--g900));
            z-index: 9999;
            border-radius: 0 3px 3px 0;
            transition: width .3s ease;
            box-shadow: 0 0 8px rgba(39, 174, 96, .55);
            display: none;
        }
    </style>

</head>

<body class="nav-md">
    <div id="submit-progress-bar"></div>

    <div class="main_container">
        <?php require_once '../nav.php'; ?>
        <div class="card">
            <div class="card-header">
                <h2>Generate A-Level Report Cards</h2>
            </div>
            <div class="card-body">
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>

                <form id="generate-marksheet-form" method="post" action="reports.php">

                    <div class="form-group">
                        <label class="form-label" for="class">Select Class:</label>
                        <div class="form-field">
                            <select name="class" id="class" class="form-select" required>
                                <option value="">-- Select Class --</option>
                                <option value="Senior Five" <?php echo ($class == 'Senior Five') ? 'selected' : ''; ?>>Senior Five</option>
                                <option value="Senior Six" <?php echo ($class == 'Senior Six') ? 'selected' : ''; ?>>Senior Six</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="term">Term:</label>
                        <div class="form-field">
                            <select name="term" id="term" class="form-select" required>
                                <option value="">-- Select Term --</option>
                                <option value="Term 1" <?php echo ($term == 'Term 1') ? 'selected' : ''; ?>>Term 1</option>
                                <option value="Term 2" <?php echo ($term == 'Term 2') ? 'selected' : ''; ?>>Term 2</option>
                                <option value="Term 3" <?php echo ($term == 'Term 3') ? 'selected' : ''; ?>>Term 3</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="year">Academic Year:</label>
                        <div class="form-field">
                            <input type="text" class="form-control" name="year" id="year" value="<?php echo $current_year; ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Select Stream(s):</label>
                        <div class="form-field">
                            <div class="selector stream-selector">
                                <div class="placeholder" id="stream-placeholder">Select streams</div>
                                <div class="options" id="stream-options">
                                    <div class="option stream-option" data-value="Arts">
                                        <input type="checkbox" name="streams[]" value="Arts" id="stream-Arts" <?php echo in_array('Arts', $streams) ? 'checked' : ''; ?>> Arts
                                    </div>
                                    <div class="option stream-option" data-value="Sciences">
                                        <input type="checkbox" name="streams[]" value="Sciences" id="stream-Sciences" <?php echo in_array('Sciences', $streams) ? 'checked' : ''; ?>> Sciences
                                    </div>
                                </div>
                            </div>
                            <div class="select-actions">
                                <a href="#" id="select-all-streams">Select All</a>
                                <span>|</span>
                                <a href="#" id="deselect-all-streams">Deselect All</a>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Select Exam Set(s):</label>
                        <div class="form-field">
                            <div class="selector exam-selector">
                                <div class="placeholder" id="exam-placeholder">Select exam sets</div>
                                <div class="options" id="exam-options">
                                    <?php if (empty($examSets)): ?>
                                        <div class="option">Please select a class first</div>
                                    <?php else: ?>
                                        <?php foreach ($examSets as $examSet): ?>
                                            <div class="option exam-option" data-value="<?php echo $examSet['id']; ?>">
                                                <input type="checkbox" name="exam_sets[]" value="<?php echo $examSet['id']; ?>" id="exam-<?php echo $examSet['id']; ?>" <?php echo in_array($examSet['id'], $selectedExamSets) ? 'checked' : ''; ?>>
                                                <?php echo htmlspecialchars($examSet['exam_set']); ?> (<?php echo htmlspecialchars($examSet['description']); ?>)
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="select-actions">
                                <a href="#" id="select-all-exams">Select All</a>
                                <span>|</span>
                                <a href="#" id="deselect-all-exams">Deselect All</a>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="level" id="level" value="A Level">

                    <div class="form-separator"></div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-submit">
                            <i class="fas fa-file-alt"></i> Generate Reports
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
    <!-- JavaScript Files -->
    <script src="../assets/js/jquery.min.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize selected options
            updateStreamPlaceholder();
            updateExamPlaceholder();

            // Toggle stream options display
            $('#stream-placeholder').click(function() {
                $('#stream-options').slideToggle(200);
                $(this).toggleClass('active');
            });

            // Toggle exam options display
            $('#exam-placeholder').click(function() {
                $('#exam-options').slideToggle(200);
                $(this).toggleClass('active');
            });

            // Handle stream option selection
            $('.stream-option').click(function() {
                const checkbox = $(this).find('input[type="checkbox"]');
                checkbox.prop('checked', !checkbox.prop('checked'));

                // Update styling
                if (checkbox.prop('checked')) {
                    $(this).addClass('selected');
                } else {
                    $(this).removeClass('selected');
                }

                // Update placeholder text
                updateStreamPlaceholder();
            });

            // Handle exam option selection
            $('.exam-option').click(function() {
                const checkbox = $(this).find('input[type="checkbox"]');
                checkbox.prop('checked', !checkbox.prop('checked'));

                // Update styling
                if (checkbox.prop('checked')) {
                    $(this).addClass('selected');
                } else {
                    $(this).removeClass('selected');
                }

                // Update placeholder text
                updateExamPlaceholder();
            });

            // Handle just the checkbox click for streams (to prevent toggle issues)
            $('.stream-option input[type="checkbox"]').click(function(e) {
                e.stopPropagation();

                // Update styling
                if ($(this).prop('checked')) {
                    $(this).closest('.stream-option').addClass('selected');
                } else {
                    $(this).closest('.stream-option').removeClass('selected');
                }

                // Update placeholder text
                updateStreamPlaceholder();
            });

            // Handle just the checkbox click for exams (to prevent toggle issues)
            $('.exam-option input[type="checkbox"]').click(function(e) {
                e.stopPropagation();

                // Update styling
                if ($(this).prop('checked')) {
                    $(this).closest('.exam-option').addClass('selected');
                } else {
                    $(this).closest('.exam-option').removeClass('selected');
                }

                // Update placeholder text
                updateExamPlaceholder();
            });

            // Function to update the stream placeholder text
            function updateStreamPlaceholder() {
                const selectedOptions = $('.stream-option input[type="checkbox"]:checked');

                if (selectedOptions.length === 0) {
                    $('#stream-placeholder').text('Select streams');
                } else if (selectedOptions.length === 1) {
                    $('#stream-placeholder').text(selectedOptions.closest('.stream-option').data('value') + ' Stream');
                } else {
                    $('#stream-placeholder').html(selectedOptions.length + ' streams <span class="selected-count">' + selectedOptions.length + '</span>');
                }
            }

            // Function to update the exam placeholder text
            function updateExamPlaceholder() {
                const selectedOptions = $('.exam-option input[type="checkbox"]:checked');

                if (selectedOptions.length === 0) {
                    $('#exam-placeholder').text('Select exam sets');
                } else if (selectedOptions.length === 1) {
                    $('#exam-placeholder').text(selectedOptions.closest('.exam-option').text().trim());
                } else {
                    $('#exam-placeholder').html(selectedOptions.length + ' exam sets <span class="selected-count">' + selectedOptions.length + '</span>');
                }
            }

            // Select All functionality for streams
            $('#select-all-streams').click(function(e) {
                e.preventDefault();

                // Make sure options are visible before selecting
                if ($('#stream-options').is(':hidden')) {
                    $('#stream-options').slideDown(200);
                    $('#stream-placeholder').addClass('active');
                }

                // Select all stream checkboxes
                $('.stream-option input[type="checkbox"]').prop('checked', true);
                $('.stream-option').addClass('selected');

                // Update the placeholder text after selecting all
                updateStreamPlaceholder();
            });

            // Select All functionality for exams
            $('#select-all-exams').click(function(e) {
                e.preventDefault();

                // Make sure options are visible before selecting
                if ($('#exam-options').is(':hidden')) {
                    $('#exam-options').slideDown(200);
                    $('#exam-placeholder').addClass('active');
                }

                // Select all exam checkboxes
                $('.exam-option input[type="checkbox"]').prop('checked', true);
                $('.exam-option').addClass('selected');

                // Update the placeholder text after selecting all
                updateExamPlaceholder();
            });

            // Deselect All functionality for streams
            $('#deselect-all-streams').click(function(e) {
                e.preventDefault();

                // Make sure options are visible before deselecting
                if ($('#stream-options').is(':hidden')) {
                    $('#stream-options').slideDown(200);
                    $('#stream-placeholder').addClass('active');
                }

                // Deselect all stream checkboxes
                $('.stream-option input[type="checkbox"]').prop('checked', false);
                $('.stream-option').removeClass('selected');

                // Update the placeholder text after deselecting all
                updateStreamPlaceholder();
            });

            // Deselect All functionality for exams
            $('#deselect-all-exams').click(function(e) {
                e.preventDefault();

                // Make sure options are visible before deselecting
                if ($('#exam-options').is(':hidden')) {
                    $('#exam-options').slideDown(200);
                    $('#exam-placeholder').addClass('active');
                }

                // Deselect all exam checkboxes
                $('.exam-option input[type="checkbox"]').prop('checked', false);
                $('.exam-option').removeClass('selected');

                // Update the placeholder text after deselecting all
                updateExamPlaceholder();
            });

            // Close the dropdowns when clicking outside
            $(document).click(function(e) {
                if (!$(e.target).closest('.stream-selector').length) {
                    $('#stream-options').slideUp(200);
                    $('#stream-placeholder').removeClass('active');
                }

                if (!$(e.target).closest('.exam-selector').length) {
                    $('#exam-options').slideUp(200);
                    $('#exam-placeholder').removeClass('active');
                }
            });

            // Set level based on selected class - always A Level for Senior Five and Senior Six
            $('#class').change(function() {
                const selectedClass = $(this).val();
                // For these classes, it's always A Level
                $('#level').val('A Level');

                // Now load exam sets based on class
                if (selectedClass !== '') {
                    // Show loading state
                    $('#exam-placeholder').text('Loading exam sets...');

                    // AJAX call to get exam sets
                    $.ajax({
                        url: 'get_exam_sets.php',
                        type: 'POST',
                        data: {
                            class: selectedClass
                        },
                        dataType: 'json',
                        success: function(data) {
                            // Clear existing options
                            $('#exam-options').empty();

                            // Add new options
                            if (data.length > 0) {
                                data.forEach(function(examSet) {
                                    const option = $('<div class="option exam-option" data-value="' + examSet.id + '">' +
                                        '<input type="checkbox" name="exam_sets[]" value="' + examSet.id + '" id="exam-' + examSet.id + '">' +
                                        examSet.exam_set + ' (' + examSet.description + ')' +
                                        '</div>');

                                    $('#exam-options').append(option);
                                });

                                // Update placeholder
                                $('#exam-placeholder').text('Select exam sets');

                                // Reattach event handlers for new options
                                attachExamOptionHandlers();
                            } else {
                                $('#exam-options').html('<div class="option">No exam sets found for this class</div>');
                                $('#exam-placeholder').text('No exam sets available');
                            }
                        },
                        error: function() {
                            $('#exam-options').html('<div class="option">Error loading exam sets</div>');
                            $('#exam-placeholder').text('Error loading exam sets');
                        }
                    });
                } else {
                    // Clear exam sets if no class selected
                    $('#exam-options').empty().html('<div class="option">Please select a class first</div>');
                    $('#exam-placeholder').text('Select exam sets');
                }
            });

            // Function to attach event handlers to dynamically added exam options
            function attachExamOptionHandlers() {
                // Option click handler
                $('.exam-option').off('click').on('click', function() {
                    const checkbox = $(this).find('input[type="checkbox"]');
                    checkbox.prop('checked', !checkbox.prop('checked'));

                    // Update styling
                    if (checkbox.prop('checked')) {
                        $(this).addClass('selected');
                    } else {
                        $(this).removeClass('selected');
                    }

                    // Update placeholder text
                    updateExamPlaceholder();
                });

                // Checkbox click handler
                $('.exam-option input[type="checkbox"]').off('click').on('click', function(e) {
                    e.stopPropagation();

                    // Update styling
                    if ($(this).prop('checked')) {
                        $(this).closest('.exam-option').addClass('selected');
                    } else {
                        $(this).closest('.exam-option').removeClass('selected');
                    }

                    // Update placeholder text
                    updateExamPlaceholder();
                });
            }

            // Trigger class change to set initial level and load exam sets if class is pre-selected
            $('#class').trigger('change');

            // Form validation with improved submission handling
            $('#generate-marksheet-form').submit(function(e) {

                // ── Validation (unchanged) ─────────────────────────────────────
                if ($('.stream-option input[type="checkbox"]:checked').length === 0) {
                    alert('Please select at least one stream');
                    e.preventDefault();
                    return false;
                }
                if ($('.exam-option input[type="checkbox"]:checked').length === 0) {
                    alert('Please select at least one exam set');
                    e.preventDefault();
                    return false;
                }
                if ($('#class').val() === '' || !(['Senior Five', 'Senior Six'].includes($('#class').val()))) {
                    alert('Please select a valid class (Senior Five or Senior Six)');
                    e.preventDefault();
                    return false;
                }
                if ($('#term').val() === '') {
                    alert('Please select a term');
                    e.preventDefault();
                    return false;
                }

                // ── Build hidden fields (unchanged logic) ──────────────────────
                $('#level').val('A Level');

                var selectedStreams = [];
                var selectedExamSets = [];
                $('.stream-option input[type="checkbox"]:checked').each(function() {
                    selectedStreams.push($(this).val());
                });
                $('.exam-option  input[type="checkbox"]:checked').each(function() {
                    selectedExamSets.push($(this).val());
                });

                $('input[name="streams[]"]').prop('disabled', true);
                $('input[name="exam_sets[]"]').prop('disabled', true);

                $(this).find('input[name="streams"]').remove();
                $(this).find('input[name="exam_sets"]').remove();
                $(this).append('<input type="hidden" name="streams"   value="' + selectedStreams.join(',') + '">');
                $(this).append('<input type="hidden" name="exam_sets" value="' + selectedExamSets.join(',') + '">');

                // ── Loading state: disable button + show spinner ───────────────
                var $btn = $(this).find('button[type="submit"]');
                $btn.prop('disabled', true)
                    .addClass('loading')
                    .html('<span class="btn-spinner"></span> Generating Reports&hellip;');

                // ── Animated progress bar across the top of the page ──────────
                var $bar = $('#submit-progress-bar');
                var progress = 0;
                $bar.show().css('width', '0');

                var barTimer = setInterval(function() {
                    var increment = (85 - progress) * 0.08 + 0.4;
                    progress = Math.min(progress + increment, 85);
                    $bar.css('width', progress + '%');
                    if (progress >= 85) clearInterval(barTimer);
                }, 120);

                return true; // allow form to submit
            });

            // Reset UI if user presses the browser Back button
            window.addEventListener('pageshow', function() {
                var $btn = $('#generate-marksheet-form button[type="submit"]');
                var $bar = $('#submit-progress-bar');
                $btn.prop('disabled', false)
                    .removeClass('loading')
                    .html('<i class="fas fa-file-alt"></i> Generate Reports');
                $bar.hide().css('width', '0');
            });
        });
    </script>
</body>

</html>