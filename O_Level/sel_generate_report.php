<?php
require_once '../auth.php';
require_once '../conn.php';
// Initialize variables
$class = isset($_GET['class']) ? $_GET['class'] : 'Senior Five';
$term = isset($_GET['term']) ? $_GET['term'] : 'Term 1';
$current_year = date("Y");
$year = isset($_GET['year']) ? $_GET['year'] : $current_year;
$streams = isset($_GET['streams']) ? $_GET['streams'] : [];
$subjects = isset($_GET['subjects']) ? $_GET['subjects'] : [];
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : '';

// Get subjects from database where level is like 'O'
function getSubjects($conn)
{
    // Replace with your actual query to get subjects with level like 'O%' ORDER BY compulsory DESC
    $sql = "SELECT * FROM subjects WHERE level LIKE 'O%' ORDER BY compulsory DESC";
    $result = mysqli_query($conn, $sql);

    $subjects = array();
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $subjects[] = $row;
        }
    }
    return $subjects;
}

$all_subjects = getSubjects($conn);
?>
<!DOCTYPE html>
<html data-bs-theme="light" lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Generate Report - SchoolPilot</title>
    <style>

        :root {
            --g900: #1b5e20;
            --g800: #2e7d32;
            --g700: #388e3c;
            --g600: #43a047;
            --g400: #66bb6a;
            --g100: #e8f5e9;
            --g50: #f1f8f1;

            --primary-color: var(--g700);
            --primary-light: var(--g600);
            --primary-dark: var(--g900);
            --accent-color: var(--g600);

            --sp-red: #c62828;
            --sp-orange: #e65100;

            --text-primary: #1a1a1a;
            --text-secondary: #333;
            --text-muted: #7f8c8d;
            --bg-light: #f1f8f1;
            --border-color: #d0dbd1;
            --white: #ffffff;

            --sp-radius: 8px;
            --sp-radius-lg: 12px;
            --shadow: 0 2px 8px rgba(0, 0, 0, .10);
            --shadow-hover: 0 8px 28px rgba(0, 0, 0, .14);
            --sp-transition: .22s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: var(--bg-light);
            font-size: 14px;
            line-height: 1.6;
            color: var(--text-primary);
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            margin-top: 45px;
            padding: 0 20px 60px;
        }

        .main-panel {
            background: var(--white);
            border-radius: var(--sp-radius-lg);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .panel-header {
            background: linear-gradient(135deg, var(--g900) 0%, var(--g700) 100%);
            color: var(--white);
            padding: 26px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 18px;
            box-shadow: var(--shadow-hover);
        }

        .panel-title {
            font-size: 1.35rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
        }

        .panel-title i {
            margin-right: 10px;
        }

        .panel-body {
            padding: 30px;
        }

        .form-container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .form-group {
            margin-bottom: 25px;
            display: flex;
            flex-direction: column;
        }

        .form-row {
            display: flex;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 22px;
        }

        .form-label {
            color: var(--text-secondary);
            font-weight: 600;
            font-size: .85rem;
            margin-bottom: 8px;
            min-width: 200px;
            flex-shrink: 0;
        }

        .form-input-wrapper {
            flex: 1;
            max-width: 400px;
        }

        .form-control {
            width: 100%;
            height: 44px;
            padding: 0 14px;
            border: 1.5px solid var(--border-color);
            border-radius: var(--sp-radius);
            background-color: var(--white);
            font-size: .9rem;
            font-family: inherit;
            color: var(--text-primary);
            transition: border-color var(--sp-transition), box-shadow var(--sp-transition);
            outline: none;
        }

        .form-control:focus {
            border-color: var(--g600);
            box-shadow: 0 0 0 3px rgba(67, 160, 71, .12);
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%237f8c8d' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 16px;
            padding-right: 42px;
        }

        /* Custom Selector Styling */
        .selector {
            position: relative;
            border: 1.5px solid var(--border-color);
            border-radius: var(--sp-radius);
            background-color: var(--white);
            overflow: hidden;
            transition: border-color var(--sp-transition), box-shadow var(--sp-transition);
        }

        .selector:focus-within {
            border-color: var(--g600);
            box-shadow: 0 0 0 3px rgba(67, 160, 71, .12);
        }

        .placeholder {
            height: 44px;
            padding: 0 38px 0 14px;
            color: var(--text-secondary);
            background-color: var(--white);
            border: none;
            cursor: pointer;
            position: relative;
            display: flex;
            align-items: center;
            transition: background var(--sp-transition);
            font-size: .9rem;
        }

        .placeholder:hover {
            background-color: var(--g50);
        }

        .placeholder:after {
            content: '';
            position: absolute;
            right: 14px;
            width: 16px;
            height: 16px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%238a9a8b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: center;
            transition: transform .2s;
        }

        .placeholder.active:after {
            transform: rotate(180deg);
        }

        .options {
            max-height: 230px;
            overflow-y: auto;
            border-top: 1.5px solid #f0f4f1;
            background-color: var(--white);
        }

        .option {
            padding: 10px 14px;
            border-bottom: 1px solid #f0f4f1;
            cursor: pointer;
            transition: background var(--sp-transition);
            display: flex;
            align-items: center;
            font-size: .875rem;
            color: var(--text-primary);
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

        .option input[type="checkbox"] {
            margin-right: 10px;
            width: 16px;
            height: 16px;
            accent-color: var(--g700);
            cursor: pointer;
        }

        /* Select Actions */
        .select-actions {
            margin-top: 8px;
            display: flex;
            gap: 12px;
            font-size: .77rem;
        }

        .select-actions a {
            text-decoration: none;
            color: var(--g700);
            font-weight: 600;
            transition: color var(--sp-transition);
        }

        .select-actions a:hover {
            color: var(--g900);
        }

        /* Report Type Cards */
        .report-types {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .report-card {
            border: 1.5px solid var(--border-color);
            border-radius: var(--sp-radius-lg);
            padding: 18px 20px;
            cursor: pointer;
            transition: all var(--sp-transition);
            background-color: var(--white);
            position: relative;
            display: block;
        }

        .report-card:hover {
            border-color: var(--g400);
            box-shadow: var(--shadow);
            transform: translateY(-2px);
        }

        .report-card.selected {
            border-color: var(--g600);
            box-shadow: 0 0 0 3px rgba(67, 160, 71, .14);
            background-color: var(--g50);
        }

        .report-card input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .report-card-title {
            color: var(--text-primary);
            font-weight: 700;
            font-size: .95rem;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
        }

        .report-card-title i {
            margin-right: 9px;
            color: var(--g700);
            font-size: 1.05rem;
        }

        .report-card-description {
            color: var(--text-muted);
            font-size: .8rem;
            line-height: 1.5;
        }

        .report-card-check {
            position: absolute;
            top: 16px;
            right: 16px;
            color: var(--g700);
            opacity: 0;
            transition: opacity var(--sp-transition);
            font-size: 1.1rem;
        }

        .report-card.selected .report-card-check {
            opacity: 1;
        }

        /* Dynamic Options */
        .dynamic-option {
            margin-top: 16px;
            padding: 16px 18px;
            background-color: var(--g50);
            border: 1.5px solid #cfe8d1;
            border-radius: var(--sp-radius);
            display: none;
            transition: all var(--sp-transition);
        }

        .dynamic-option.show {
            display: block;
            animation: slideIn .3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .dynamic-checkbox {
            display: flex;
            align-items: center;
            font-size: .875rem;
            color: var(--text-primary);
            margin-bottom: 0;
        }

        .dynamic-checkbox input[type="checkbox"] {
            margin-right: 12px;
            width: 17px;
            height: 17px;
            accent-color: var(--g700);
            cursor: pointer;
        }

        .dynamic-checkbox label {
            margin: 0;
            cursor: pointer;
            font-weight: 600;
        }

        .dynamic-form-row {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .dynamic-label {
            color: var(--text-secondary);
            font-weight: 600;
            font-size: .875rem;
            min-width: 180px;
            flex-shrink: 0;
        }

        .dynamic-input {
            flex: 1;
            max-width: 200px;
        }

        /* Buttons */
        .button-group {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 26px;
            padding-top: 22px;
            border-top: 1px solid #e8ede9;
        }

        .btn {
            padding: 11px 22px;
            border: none;
            border-radius: var(--sp-radius);
            font-size: .88rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: all var(--sp-transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            justify-content: center;
        }

        .btn-primary {
            background-color: var(--g700);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--g800);
            box-shadow: var(--shadow);
        }

        .btn-success {
            background-color: var(--g600);
            color: var(--white);
        }

        .btn-success:hover {
            background-color: var(--g700);
            box-shadow: var(--shadow);
        }

        .btn-secondary {
            background-color: #f1f2f6;
            color: var(--text-secondary);
        }

        .btn-secondary:hover {
            background-color: #e4e7ea;
        }

        /* Selected Count Badge */
        .selected-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background-color: var(--g700);
            color: var(--white);
            font-size: 11px;
            font-weight: 700;
            border-radius: 50px;
            padding: 3px 8px;
            margin-left: 8px;
        }

        /* Search Box */
        .search-box {
            position: sticky;
            top: 0;
            padding: 10px 14px;
            background-color: var(--white);
            border-bottom: 1.5px solid #f0f4f1;
            z-index: 10;
        }

        .search-input {
            width: 100%;
            padding: 8px 12px;
            border: 1.5px solid var(--border-color);
            border-radius: 6px;
            font-size: .85rem;
            font-family: inherit;
            background-color: var(--white);
            transition: border-color var(--sp-transition);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--g600);
            box-shadow: 0 0 0 3px rgba(67, 160, 71, .12);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 0 10px 40px;
            }

            .panel-body {
                padding: 20px;
            }

            .form-row {
                flex-direction: column;
                gap: 8px;
            }

            .form-label {
                min-width: auto;
            }

            .form-input-wrapper {
                max-width: none;
                width: 100%;
            }

            .report-types {
                grid-template-columns: 1fr;
            }

            .button-group {
                flex-direction: column-reverse;
                align-items: stretch;
            }

            .btn {
                width: 100%;
            }

            .dynamic-form-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .dynamic-label {
                min-width: auto;
            }

            .dynamic-input {
                max-width: none;
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .panel-header {
                padding: 20px;
            }

            .panel-title {
                font-size: 1.15rem;
            }

            .form-control {
                height: 42px;
            }

            .placeholder {
                height: 42px;
            }
        }
    
    </style>

</head>

<body>
    <?php require_once '../nav.php'; ?>

    <div class="container">
        <div class="main-panel">
            <div class="panel-header">
                <h1 class="panel-title">Generate Report</h1>
            </div>
            <div class="panel-body">
                <div class="form-container">
                    <form id="generate-report-form" method="post">
                        <div class="form-row">
                            <label class="form-label">Select Class:</label>
                            <div class="form-input-wrapper">
                                <select name="class" id="class" class="form-control form-select" required>
                                    <option value="">-- Select Class --</option>
                                    <option value="Senior One" <?php echo ($class == 'Senior One') ? 'selected' : ''; ?>>Senior One</option>
                                    <option value="Senior Two" <?php echo ($class == 'Senior Two') ? 'selected' : ''; ?>>Senior Two</option>
                                    <option value="Senior Three" <?php echo ($class == 'Senior Three') ? 'selected' : ''; ?>>Senior Three</option>
                                    <option value="Senior Four" <?php echo ($class == 'Senior Four') ? 'selected' : ''; ?>>Senior Four</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label">Term:</label>
                            <div class="form-input-wrapper">
                                <select name="term" id="term" class="form-control form-select" required>
                                    <option value="">-- Select Term --</option>
                                    <option value="Term 1" <?php echo ($term == 'Term 1') ? 'selected' : ''; ?>>Term 1</option>
                                    <option value="Term 2" <?php echo ($term == 'Term 2') ? 'selected' : ''; ?>>Term 2</option>
                                    <option value="Term 3" <?php echo ($term == 'Term 3') ? 'selected' : ''; ?>>Term 3</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label">Academic Year:</label>
                            <div class="form-input-wrapper">
                                <input type="text" class="form-control" name="year" id="year" value="<?php echo $current_year; ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <label class="form-label">Select Stream(s):</label>
                            <div class="form-input-wrapper">
                                <div class="selector stream-selector">
                                    <div class="placeholder stream-placeholder" id="stream-placeholder">Select streams</div>
                                    <div class="options stream-options" id="stream-options" style="display: none;">
                                        <div class="option stream-option" data-value="East">
                                            <input type="checkbox" name="stream[]" value="East" id="stream-East"> East
                                        </div>
                                        <div class="option stream-option" data-value="West">
                                            <input type="checkbox" name="stream[]" value="West" id="stream-West"> West
                                        </div>
                                        <div class="option stream-option" data-value="South">
                                            <input type="checkbox" name="stream[]" value="South" id="stream-South"> South
                                        </div>
                                        <div class="option stream-option" data-value="North">
                                            <input type="checkbox" name="stream[]" value="North" id="stream-North"> North
                                        </div>
                                    </div>
                                </div>
                                <div class="select-actions">
                                    <a href="#" id="select-all-streams">Select All</a>
                                    <a href="#" id="deselect-all-streams">Deselect All</a>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Report Type:</label>
                            <div class="report-types">
                                <!--<label class="report-card <?php echo ($report_type == 'detailed') ? 'selected' : ''; ?>" for="report-detailed">
                                    <input type="radio" name="report_type" id="report-detailed" value="detailed" <?php echo ($report_type == 'detailed') ? 'checked' : ''; ?> required>
                                    <div class="report-card-title">
                                        <i class="fas fa-list-ul"></i> Detailed Report
                                    </div>
                                    <div class="report-card-description">
                                        Shows all topics and competencies achieved by individual learner in each subject assessed.
                                    </div>
                                    <span class="report-card-check"><i class="fas fa-check-circle"></i></span>
                                </label>-->

                                <label class="report-card <?php echo ($report_type == 'summarized') ? 'selected' : ''; ?>" for="report-summarized">
                                    <input type="radio" name="report_type" id="report-summarized" value="summarized" <?php echo ($report_type == 'summarized') ? 'checked' : ''; ?>>
                                    <div class="report-card-title">
                                        <i class="fas fa-calculator"></i> Summarized Report
                                    </div>
                                    <div class="report-card-description">
                                        Shows learners' AOI and EOT scores computed to /100 scale and graded.
                                    </div>
                                    <span class="report-card-check"><i class="fas fa-check-circle"></i></span>
                                </label>

                                <label class="report-card <?php echo ($report_type == 'assessment') ? 'selected' : ''; ?>" for="report-assessment">
                                    <input type="radio" name="report_type" id="report-assessment" value="assessment" <?php echo ($report_type == 'assessment') ? 'checked' : ''; ?>>
                                    <div class="report-card-title">
                                        <i class="fas fa-clipboard-check"></i> Assessment Report
                                    </div>
                                    <div class="report-card-description">
                                        Shows learners AOI scores only convert to /20 scale
                                    </div>
                                    <span class="report-card-check"><i class="fas fa-check-circle"></i></span>
                                </label>
                            </div>

                            <!-- Dynamic Options -->
                            <div class="dynamic-option" id="eot-scores-option">
                                <div class="dynamic-checkbox">
                                    <input type="checkbox" name="display_eot_scores" id="display-eot-scores" value="1">
                                    <label for="display-eot-scores">Display End of Term Scores</label>
                                </div>
                            </div>

                            <div class="dynamic-option" id="aoi-columns-option">
                                <div class="dynamic-form-row">
                                    <label class="dynamic-label">Number of AOI Columns:</label>
                                    <div class="dynamic-input">
                                        <select name="aoi_columns" id="aoi-columns" class="form-control form-select">
                                            <option value="1">1 Column</option>
                                            <option value="2" selected>2 Columns</option>
                                            <option value="3">3 Columns</option>
                                            <option value="4">4 Columns</option>
                                            <option value="5">5 Columns</option>
                                            <option value="6">6 Columns</option>
                                            <option value="7">7 Columns</option>
                                            <option value="8">8 Columns</option>
                                            <option value="9">9 Columns</option>
                                            <option value="10">10 Columns</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="dynamic-option" id="percentage-option">
                                <div class="dynamic-checkbox">
                                    <input type="checkbox" name="display_percentage" id="display-percentage" value="1">
                                    <label for="display-percentage">Display Percentage Column</label>
                                </div>
                            </div>

                            <div class="dynamic-option" id="student-position-option">
                                <div class="dynamic-checkbox">
                                    <input type="checkbox" name="display_student_position" id="display-student-position" value="1">
                                    <label for="display-student-position">Display Student Position</label>
                                </div>
                            </div>
                        </div>

                        <div class="button-group">
                            <button type="button" class="btn btn-secondary">Cancel</button>
                            <button type="reset" class="btn btn-primary">Reset</button>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-file-alt"></i> Generate Report
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Files -->
    <script src="../assets/js/jquery.min.js"></script>
    <script src="../assets/bootstrap/js/bootstrap.min.js"></script>
    <script src="../assets/js/bs-init.js"></script>
    <script src="../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/custom.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Select All/Deselect All functionality on page load
            // This makes sure these functions work even before opening the dropdowns
            initializeSelectAllFunctionality();

            // Toggle stream options display
            $('#stream-placeholder').click(function() {
                $('#stream-options').slideToggle(200);
                $(this).toggleClass('active');

                // Hide subjects dropdown if open
                $('#subjects-options').slideUp(200);
                $('#subject-placeholder').removeClass('active');
            });

            // Toggle subjects options display
            $('#subject-placeholder').click(function() {
                $('#subjects-options').slideToggle(200);
                $(this).toggleClass('active');

                // Hide streams dropdown if open
                $('#stream-options').slideUp(200);
                $('#stream-placeholder').removeClass('active');
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

            // Handle subject option selection
            $('.subject-option').click(function() {
                const checkbox = $(this).find('input[type="checkbox"]');
                checkbox.prop('checked', !checkbox.prop('checked'));

                // Update styling
                if (checkbox.prop('checked')) {
                    $(this).addClass('selected');
                } else {
                    $(this).removeClass('selected');
                }

                // Update placeholder text
                updateSubjectPlaceholder();
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

            // Handle just the checkbox click for subjects (to prevent toggle issues)
            $('.subject-option input[type="checkbox"]').click(function(e) {
                e.stopPropagation();

                // Update styling
                if ($(this).prop('checked')) {
                    $(this).closest('.subject-option').addClass('selected');
                } else {
                    $(this).closest('.subject-option').removeClass('selected');
                }

                // Update placeholder text
                updateSubjectPlaceholder();
            });

            // Function to update the stream placeholder text
            function updateStreamPlaceholder() {
                const selectedOptions = $('.stream-option input[type="checkbox"]:checked');

                if (selectedOptions.length === 0) {
                    $('#stream-placeholder').text('Select streams');
                } else if (selectedOptions.length === 1) {
                    $('#stream-placeholder').text('Stream ' + selectedOptions.closest('.stream-option').data('value'));
                } else {
                    $('#stream-placeholder').html(selectedOptions.length + ' streams <span class="selected-count">' + selectedOptions.length + '</span>');
                }
            }

            // Function to update the subject placeholder text
            function updateSubjectPlaceholder() {
                const selectedOptions = $('.subject-option input[type="checkbox"]:checked');

                if (selectedOptions.length === 0) {
                    $('#subject-placeholder').text('Select subjects');
                } else if (selectedOptions.length === 1) {
                    $('#subject-placeholder').text(selectedOptions.closest('.subject-option').data('value'));
                } else {
                    $('#subject-placeholder').html(selectedOptions.length + ' subjects <span class="selected-count">' + selectedOptions.length + '</span>');
                }
            }

            // Initialize Select All/Deselect All functionality
            function initializeSelectAllFunctionality() {
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

                // Select All functionality for subjects
                $('#select-all-subjects').click(function(e) {
                    e.preventDefault();

                    // Make sure options are visible before selecting
                    if ($('#subjects-options').is(':hidden')) {
                        $('#subjects-options').slideDown(200);
                        $('#subject-placeholder').addClass('active');
                    }

                    // Select all subject checkboxes that are visible (to respect search filter)
                    $('.subject-option:visible input[type="checkbox"]').prop('checked', true);
                    $('.subject-option:visible').addClass('selected');

                    // Update the placeholder text after selecting all
                    updateSubjectPlaceholder();
                });

                // Deselect All functionality for subjects
                $('#deselect-all-subjects').click(function(e) {
                    e.preventDefault();

                    // Make sure options are visible before deselecting
                    if ($('#subjects-options').is(':hidden')) {
                        $('#subjects-options').slideDown(200);
                        $('#subject-placeholder').addClass('active');
                    }

                    // Deselect all subject checkboxes
                    $('.subject-option input[type="checkbox"]').prop('checked', false);
                    $('.subject-option').removeClass('selected');

                    // Update the placeholder text after deselecting all
                    updateSubjectPlaceholder();
                });
            }

            // Subject search functionality
            $('#subject-search').on('input', function() {
                const searchTerm = $(this).val().toLowerCase();

                $('.subject-option').each(function() {
                    const subjectName = $(this).data('value').toLowerCase();
                    if (subjectName.includes(searchTerm)) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });

            // Report type selection and dynamic options handling
            $('.report-card').click(function() {
                // Unselect all cards first
                $('.report-card').removeClass('selected');

                // Select this card
                $(this).addClass('selected');

                // Ensure the radio button is checked
                $(this).find('input[type="radio"]').prop('checked', true);

                // Handle dynamic options visibility
                handleDynamicOptions();
            });

            // Handle radio button clicks to update card selection
            $('.report-card input[type="radio"]').click(function(e) {
                e.stopPropagation();

                // Unselect all cards first
                $('.report-card').removeClass('selected');

                // Select this card
                $(this).closest('.report-card').addClass('selected');

                // Handle dynamic options visibility
                handleDynamicOptions();
            });

            // Function to handle dynamic options visibility
            function handleDynamicOptions() {
                const selectedReportType = $('input[name="report_type"]:checked').val();

                // Hide all dynamic options first
                $('.dynamic-option').removeClass('show');

                if (selectedReportType === 'detailed') {
                    // Show EOT scores option for detailed report
                    $('#eot-scores-option').addClass('show');
                    // Show student position option for detailed report
                    $('#student-position-option').addClass('show');
                } else if (selectedReportType === 'summarized') {
                    // Show AOI columns option for summarized report
                    $('#aoi-columns-option').addClass('show');
                    // Show student position option for summarized report
                    $('#student-position-option').addClass('show');
                } else if (selectedReportType === 'assessment') {
                    // Show both AOI columns and percentage options for assessment report
                    $('#aoi-columns-option').addClass('show');
                    $('#percentage-option').addClass('show');
                    // Show student position option for assessment report
                    $('#student-position-option').addClass('show');
                }

                // Reset values when hiding options
                if (selectedReportType !== 'assessment') {
                    $('#display-percentage').prop('checked', false);
                }
                if (selectedReportType !== 'detailed') {
                    $('#display-eot-scores').prop('checked', false);
                }
                if (selectedReportType === 'detailed') {
                    $('#aoi-columns').val('2'); // Reset to default
                }
            }

            // Initialize dynamic options visibility on page load
            handleDynamicOptions();

            // Close the dropdowns when clicking outside
            $(document).click(function(e) {
                if (!$(e.target).closest('.stream-selector').length) {
                    $('#stream-options').slideUp(200);
                    $('#stream-placeholder').removeClass('active');
                }

                if (!$(e.target).closest('.subject-selector').length) {
                    $('#subjects-options').slideUp(200);
                    $('#subject-placeholder').removeClass('active');
                }
            });

            // Form submission with dynamic redirect based on report type
            $('#generate-report-form').submit(function(e) {
                e.preventDefault(); // Prevent default form submission

                // Validate streams selection
                if ($('.stream-option input[type="checkbox"]:checked').length === 0) {
                    alert('Please select at least one stream');
                    return false;
                }

                // Validate report type selection
                if (!$('input[name="report_type"]:checked').length) {
                    alert('Please select a report type');
                    return false;
                }

                // Validate other required fields
                if ($('#class').val() === '') {
                    alert('Please select a class');
                    return false;
                }

                if ($('#term').val() === '') {
                    alert('Please select a term');
                    return false;
                }

                // Get the selected report type
                const reportType = $('input[name="report_type"]:checked').val();

                // Determine the target URL based on report type
                let targetUrl = '';
                switch (reportType) {
                    case 'detailed':
                        targetUrl = 'report.php';
                        break;
                    case 'summarized':
                        targetUrl = 'summarized_report.php';
                        break;
                    case 'assessment':
                        targetUrl = 'assessment_report.php';
                        break;
                    default:
                        alert('Invalid report type selected');
                        return false;
                }

                // Create a new form element for submission
                const submitForm = $('<form>', {
                    'method': 'GET',
                    'action': targetUrl,
                    'target': '_blank'
                });

                // Clone all form inputs and append to the new form
                $(this).find('input, select').each(function() {
                    const input = $(this);

                    if (input.attr('type') === 'checkbox') {
                        if (input.is(':checked')) {
                            submitForm.append($('<input>', {
                                'type': 'hidden',
                                'name': input.attr('name'),
                                'value': input.val()
                            }));
                        }
                    } else if (input.attr('type') === 'radio') {
                        if (input.is(':checked')) {
                            submitForm.append($('<input>', {
                                'type': 'hidden',
                                'name': input.attr('name'),
                                'value': input.val()
                            }));
                        }
                    } else if (input.is('select') || input.attr('type') === 'text') {
                        if (input.val()) {
                            submitForm.append($('<input>', {
                                'type': 'hidden',
                                'name': input.attr('name'),
                                'value': input.val()
                            }));
                        }
                    }
                });

                // Append the form to body and submit
                $('body').append(submitForm);
                submitForm.submit();

                return false;
            });

            // Reset form functionality
            $('button[type="reset"]').click(function() {
                // Reset all form fields
                $('#generate-report-form')[0].reset();

                // Reset custom elements
                $('.stream-option input[type="checkbox"]').prop('checked', false);
                $('.stream-option').removeClass('selected');
                $('.subject-option input[type="checkbox"]').prop('checked', false);
                $('.subject-option').removeClass('selected');
                $('.report-card').removeClass('selected');

                // Reset placeholders
                $('#stream-placeholder').text('Select streams');
                $('#subject-placeholder').text('Select subjects');

                // Hide dynamic options
                $('.dynamic-option').removeClass('show');
                $('#display-percentage').prop('checked', false);
                $('#display-eot-scores').prop('checked', false);
                $('#display-student-position').prop('checked', false);
                $('#aoi-columns').val('2'); // Reset to default
            });
        });
    </script>
</body>

</html>