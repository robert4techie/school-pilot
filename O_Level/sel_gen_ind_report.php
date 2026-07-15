<?php
require_once '../auth.php';
?>
<!DOCTYPE html>
<html data-bs-theme="light" lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title> Generate individual Reports -SchoolPilot</title>
    <link rel="stylesheet" href="../assets/fonts/fontawesome-all.min.css">
    <link rel="stylesheet" href="../assets/fonts/font-awesome.min.css">
    <link rel="stylesheet" href="../assets/fonts/fontawesome5-overrides.min.css">

      <style>
        :root {
            --primary-color: #1e8449;
            --primary-light: #27ae60;
            --primary-dark: #145a32;
            --accent-color: #2ecc71;
            --text-primary: #2c3e50;
            --text-secondary: #6c757d;
            --bg-light: #f8f9fa;
            --border-color: #dee2e6;
            --white: #ffffff;
            --shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        /* Reset and base styles */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: var(--bg-light);
            font-size: 14px;
            line-height: 1.5;
            color: var(--text-primary);
        }

        /* Container and layout */
        .container {
            width: 100%;
            padding: 0 15px;
            margin: 0 auto;
            margin-top: 45px;
        }

        .main_container {
            display: flex;
            min-height: 100vh;
        }

        .right_col {
            flex: 1;
            padding: 20px;
            background-color: var(--bg-light);
        }

        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -15px;
        }

        .col-md-12 {
            width: 100%;
            padding: 0 15px;
        }

        /* Panel styling */
        .x_panel {
            background: var(--white);
            border-radius: 8px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            overflow: hidden;
        }

        .x_title {
            padding: 20px 25px;
            border-bottom: 1px solid var(--border-color);
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
        }

        .x_title h2 {
            color: var(--white);
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }

        .x_content {
            padding: 25px;
        }

        .clearfix::after {
            content: "";
            display: table;
            clear: both;
        }

        /* Form styles */
        .form-horizontal {
            max-width: 900px;
            margin: 0 auto;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group.row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
        }

        .control-label {
            display: block;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
            font-size: 14px;
        }

        .col-md-3 {
            width: 25%;
            padding-right: 15px;
        }

        .col-md-6 {
            width: 50%;
            padding: 0 15px;
        }

        .col-md-9 {
            width: 75%;
            padding: 0 15px;
        }

        .offset-md-3 {
            margin-left: 25%;
        }

        /* Form controls */
        .form-control,
        .form-select {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
            background-color: var(--white);
            transition: all 0.3s ease;
            outline: none;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 132, 73, 0.1);
        }

        .form-select {
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 16px;
            padding-right: 45px;
        }

        /* Custom selector styles (your existing stream/student selectors) */
        .selector {
            position: relative;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            background-color: var(--white);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .selector:hover {
            border-color: var(--primary-light);
        }

        .placeholder {
            height: 44px;
            padding: 0 15px;
            color: var(--text-primary);
            background-color: var(--white);
            border: none;
            cursor: pointer;
            position: relative;
            display: flex;
            align-items: center;
            transition: all 0.2s ease;
            font-size: 14px;
        }

        .placeholder:hover {
            background-color: #f8fafd;
        }

        .placeholder:after {
            content: '';
            position: absolute;
            right: 15px;
            width: 16px;
            height: 16px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236a7a8c' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: center;
            transition: transform 0.2s ease;
        }

        .placeholder.active:after {
            transform: rotate(180deg);
        }

        .options {
            max-height: 250px;
            overflow-y: auto;
            border-top: 1px solid var(--border-color);
            background: var(--white);
        }

        .option {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background-color 0.2s;
            display: flex;
            align-items: center;
            font-size: 14px;
        }

        .option:last-child {
            border-bottom: none;
        }

        .option:hover {
            background-color: rgba(30, 132, 73, 0.05);
        }

        .option.selected {
            background-color: var(--primary-color);
            color: var(--white);
        }

        .option input[type="checkbox"] {
            margin-right: 12px;
            transform: scale(1.2);
        }

        /* Select actions */
        .select-actions {
            margin-top: 10px;
            display: flex;
            font-size: 13px;
        }

        .select-actions a {
            text-decoration: none;
            color: var(--primary-color);
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .select-actions a:hover {
            color: var(--primary-dark);
        }

        .select-actions span {
            color: #d0d6e1;
            margin: 0 10px;
        }

        /* Search box */
        .search-box {
            position: sticky;
            top: 0;
            padding: 12px;
            background-color: var(--white);
            border-bottom: 1px solid var(--border-color);
            z-index: 10;
        }

        .search-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 13px;
            background-color: #f8fafd;
            outline: none;
        }

        .search-input:focus {
            border-color: var(--primary-color);
            background-color: var(--white);
        }

        /* Report cards */
        .report-types {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 15px;
        }

        .report-card {
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            background-color: var(--white);
            position: relative;
        }

        .report-card:hover {
            border-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 132, 73, 0.15);
        }

        .report-card.selected {
            border-color: var(--primary-color);
            background: linear-gradient(135deg, rgba(30, 132, 73, 0.05), rgba(46, 204, 113, 0.05));
            box-shadow: 0 4px 12px rgba(30, 132, 73, 0.2);
        }

        .report-card input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .report-card-title {
            color: var(--text-primary);
            font-weight: 700;
            font-size: 16px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }

        .report-card-title i {
            margin-right: 10px;
            color: var(--primary-color);
            font-size: 18px;
        }

        .report-card-description {
            color: var(--text-secondary);
            font-size: 13px;
            line-height: 1.6;
        }

        .report-card-check {
            position: absolute;
            top: 20px;
            right: 20px;
            color: var(--primary-color);
            opacity: 0;
            transition: opacity 0.3s ease;
            font-size: 20px;
        }

        .report-card.selected .report-card-check {
            opacity: 1;
        }

        /* Dynamic options */
        .dynamic-option {
            margin-top: 20px;
            padding: 20px;
            background: linear-gradient(135deg, rgba(30, 132, 73, 0.03), rgba(46, 204, 113, 0.03));
            border: 1px solid rgba(30, 132, 73, 0.2);
            border-radius: 6px;
            display: none;
            transition: all 0.3s ease;
        }

        .dynamic-option.show {
            display: block;
            animation: slideIn 0.3s ease;
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
            font-size: 14px;
            color: var(--text-primary);
            margin-bottom: 0;
        }

        .dynamic-checkbox input[type="checkbox"] {
            margin-right: 12px;
            transform: scale(1.2);
            accent-color: var(--primary-color);
        }

        .dynamic-checkbox label {
            margin: 0;
            cursor: pointer;
            font-weight: 500;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 10px;
            margin-bottom: 10px;
            outline: none;
        }

        .btn i {
            margin-right: 8px;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            color: var(--white);
            box-shadow: 0 2px 4px rgba(30, 132, 73, 0.3);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 132, 73, 0.4);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-color), var(--primary-light));
            color: var(--white);
            box-shadow: 0 2px 4px rgba(46, 204, 113, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-light), var(--accent-color));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(46, 204, 113, 0.4);
        }

        .btn-secondary {
            background-color: #6c757d;
            color: var(--white);
            box-shadow: 0 2px 4px rgba(108, 117, 125, 0.3);
        }

        .btn-secondary:hover {
            background-color: #545b62;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.4);
        }

        /* Form divider */
        .ln_solid {
            border-top: 2px solid var(--border-color);
            margin: 30px 0;
            position: relative;
        }

        .ln_solid::before {
            content: '';
            position: absolute;
            top: -1px;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 2px;
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
        }

        /* Loading spinner */
        .loading-spinner {
            display: none;
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: translateY(-50%) rotate(0deg); }
            100% { transform: translateY(-50%) rotate(360deg); }
        }

        /* Selected count badge */
        .selected-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            color: var(--white);
            font-size: 11px;
            font-weight: 700;
            border-radius: 50px;
            padding: 4px 10px;
            margin-left: 10px;
        }

        /* Student info */
        .student-info {
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }

        .student-name {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 14px;
        }

        .student-details {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 4px;
        }

        /* No students message */
        .no-students {
            padding: 30px 20px;
            text-align: center;
            color: var(--text-secondary);
            font-style: italic;
        }

        /* Disabled state */
        .student-selector.disabled {
            opacity: 0.6;
            pointer-events: none;
        }

        .student-selector.disabled .placeholder {
            background-color: #f5f5f5;
            color: #999;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .col-md-3,
            .col-md-6,
            .col-md-9 {
                width: 100%;
                padding: 0;
                margin-bottom: 15px;
            }

            .offset-md-3 {
                margin-left: 0;
            }

            .form-group.row {
                flex-direction: column;
                align-items: stretch;
            }

            .control-label {
                margin-bottom: 8px;
            }

            .report-types {
                grid-template-columns: 1fr;
            }

            .right_col {
                padding: 15px;
            }
        }

        @media (max-width: 480px) {
            .x_content {
                padding: 20px 15px;
            }

            .x_title {
                padding: 15px 20px;
            }

            .btn {
                width: 100%;
                margin-right: 0;
                margin-bottom: 10px;
            }
        }
    </style>
</head>

<body class="nav-md">
        <?php  require_once '../nav.php'; ?>

    <div class="container body">
        <div class="main_container">

            <!-- Main Content Area -->
            <div class="right_col" role="main">
                <div class="row">
                    <div class="col-md-12 col-sm-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2>Generate Individual Student Reports</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <form id="generate-report-form" class="form-horizontal form-label-left" method="post">
                                    <div class="form-group row mb-3">
                                        <label class="control-label col-md-3 col-sm-3">Select Class:</label>
                                        <div class="col-md-6 col-sm-6">
                                            <select name="class" id="class" class="form-select" required>
                                                <option value="">-- Select Class --</option>
                                                <option value="Senior One">Senior One</option>
                                                <option value="Senior Two">Senior Two</option>
                                                <option value="Senior Three">Senior Three</option>
                                                <option value="Senior Four">Senior Four</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group row mb-3">
                                        <label class="control-label col-md-3 col-sm-3">Term</label>
                                        <div class="col-md-6 col-sm-6">
                                            <select name="term" id="term" class="form-select" required>
                                                <option value="">-- Select Term --</option>
                                                <option value="Term 1">Term 1</option>
                                                <option value="Term 2">Term 2</option>
                                                <option value="Term 3">Term 3</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group row mb-3">
                                        <label class="control-label col-md-3 col-sm-3">Academic Year</label>
                                        <div class="col-md-6 col-sm-6">
                                            <input type="text" class="form-control" name="year" id="year" value="<?php echo date('Y'); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group row mb-3">
                                        <label class="control-label col-md-3 col-sm-3">Select Stream(s):</label>
                                        <div class="col-md-6 col-sm-6">
                                            <div class="selector stream-selector">
                                                <div class="placeholder stream-placeholder" id="stream-placeholder">Select streams</div>
                                                <div class="loading-spinner" id="stream-loading"></div>
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
                                                <span>|</span>
                                                <a href="#" id="deselect-all-streams">Deselect All</a>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Students Selection -->
                                    <div class="form-group row mb-3">
                                        <label class="control-label col-md-3 col-sm-3">Select Students:</label>
                                        <div class="col-md-6 col-sm-6">
                                            <div class="selector student-selector disabled" id="student-selector">
                                                <div class="placeholder student-placeholder" id="student-placeholder">Select class and stream first</div>
                                                <div class="loading-spinner" id="student-loading"></div>
                                                <div class="options students-options" id="students-options" style="display: none;">
                                                    <div class="search-box">
                                                        <input type="text" class="search-input" id="student-search" placeholder="Search students...">
                                                    </div>
                                                    <div id="student-list">
                                                        <!-- Students will be loaded here via AJAX -->
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="select-actions">
                                                <a href="#" id="select-all-students">Select All</a>
                                                <span>|</span>
                                                <a href="#" id="deselect-all-students">Deselect All</a>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group row mb-3">
                                        <label class="control-label col-md-3 col-sm-3">Report Type:</label>
                                        <div class="col-md-9 col-sm-9">
                                            <div class="report-types">
                                                <!--<label class="report-card" for="report-detailed">
                                                    <input type="radio" name="report_type" id="report-detailed" value="detailed" required>
                                                    <div class="report-card-title">
                                                        <i class="fas fa-list-ul"></i> Detailed Report
                                                    </div>
                                                    <div class="report-card-description">
                                                        Shows all topics and competencies achieved by individual learner in each subject assessed.
                                                    </div>
                                                    <span class="report-card-check"><i class="fas fa-check-circle"></i></span>
                                                </label-->

                                                <label class="report-card" for="report-summarized">
                                                    <input type="radio" name="report_type" id="report-summarized" value="summarized">
                                                    <div class="report-card-title">
                                                        <i class="fas fa-calculator"></i> Summarized Report
                                                    </div>
                                                    <div class="report-card-description">
                                                        Shows learners' AOI and EOT scores computed to /100 scale and graded.
                                                    </div>
                                                    <span class="report-card-check"><i class="fas fa-check-circle"></i></span>
                                                </label>

                                                <label class="report-card" for="report-assessment">
                                                    <input type="radio" name="report_type" id="report-assessment" value="assessment">
                                                    <div class="report-card-title">
                                                        <i class="fas fa-clipboard-check"></i> Assessment Report
                                                    </div>
                                                    <div class="report-card-description">
                                                        Shows learners AOI scores only convert to /20 scale
                                                    </div>
                                                    <span class="report-card-check"><i class="fas fa-check-circle"></i></span>
                                                </label>
                                            </div>

                                            <!-- Dynamic options -->
                                            <div class="dynamic-option" id="eot-scores-option">
                                                <div class="dynamic-checkbox">
                                                    <input type="checkbox" name="display_eot_scores" id="display-eot-scores" value="1">
                                                    <label for="display-eot-scores">Display End of Term Scores</label>
                                                </div>
                                            </div>

                                            <div class="dynamic-option" id="aoi-columns-option">
                                                <div class="form-group row mb-0">
                                                    <label class="control-label col-md-4 col-sm-4">Number of AOI Columns:</label>
                                                    <div class="col-md-8 col-sm-8">
                                                        <select name="aoi_columns" id="aoi-columns" class="form-select">
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

                                            <!--<div class="dynamic-option" id="student-position-option">
                                                <div class="dynamic-checkbox">
                                                    <input type="checkbox" name="display_student_position" id="display-student-position" value="1">
                                                    <label for="display-student-position">Display Student Position</label>
                                                </div>
                                            </div>-->
                                        </div>
                                    </div>

                                    <div class="ln_solid"></div>

                                    <div class="form-group row">
                                        <div class="col-md-6 col-sm-6 offset-md-3">
                                            <button type="button" class="btn btn-secondary">Cancel</button>
                                            <button type="reset" class="btn btn-primary">Reset</button>
                                            <button type="submit" class="btn btn-success">
                                                <i class="fas fa-file-alt"></i> Generate Reports
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Files -->
    <script src="../assets/js/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize functionality
            initializeSelectAllFunctionality();

            // Watch for class and stream changes to load students
            $('#class, input[name="stream[]"]').on('change', function() {
                loadStudents();
            });

            // Toggle stream options display
            $('#stream-placeholder').click(function() {
                $('#stream-options').slideToggle(200);
                $(this).toggleClass('active');
                $('#students-options').slideUp(200);
                $('#student-placeholder').removeClass('active');
            });

            // Toggle students options display
            $('#student-placeholder').click(function() {
                if (!$('#student-selector').hasClass('disabled')) {
                    $('#students-options').slideToggle(200);
                    $(this).toggleClass('active');
                    $('#stream-options').slideUp(200);
                    $('#stream-placeholder').removeClass('active');
                }
            });

            // Handle stream option selection
            $('.stream-option').click(function() {
                const checkbox = $(this).find('input[type="checkbox"]');
                checkbox.prop('checked', !checkbox.prop('checked'));

                if (checkbox.prop('checked')) {
                    $(this).addClass('selected');
                } else {
                    $(this).removeClass('selected');
                }

                updateStreamPlaceholder();
                loadStudents(); // Load students when stream changes
            });

            // Handle stream checkbox clicks
            $('.stream-option input[type="checkbox"]').click(function(e) {
                e.stopPropagation();

                if ($(this).prop('checked')) {
                    $(this).closest('.stream-option').addClass('selected');
                } else {
                    $(this).closest('.stream-option').removeClass('selected');
                }

                updateStreamPlaceholder();
                loadStudents(); // Load students when stream changes
            });

            // Student search functionality
            $('#student-search').on('input', function() {
                const searchTerm = $(this).val().toLowerCase();

                $('.student-option').each(function() {
                    const studentName = $(this).find('.student-name').text().toLowerCase();
                    const studentId = $(this).find('.student-details').text().toLowerCase();

                    if (studentName.includes(searchTerm) || studentId.includes(searchTerm)) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });

            // Function to load students via AJAX
            function loadStudents() {
                const selectedClass = $('#class').val();
                const selectedStreams = [];

                $('.stream-option input[type="checkbox"]:checked').each(function() {
                    selectedStreams.push($(this).val());
                });

                // Clear students and disable selector if no class or streams selected
                if (!selectedClass || selectedStreams.length === 0) {
                    $('#student-list').empty();
                    $('#student-selector').addClass('disabled');
                    $('#student-placeholder').text('Select class and stream first');
                    updateStudentPlaceholder();
                    return;
                }

                // Show loading spinner
                $('#student-loading').show();
                $('#student-selector').removeClass('disabled');
                $('#student-placeholder').text('Loading students...');

                // AJAX call to fetch students
                $.ajax({
                    url: 'ajax_handlers/get_students.php', // You'll need to create this file
                    type: 'POST',
                    data: {
                        class: selectedClass,
                        streams: selectedStreams
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#student-loading').hide();

                        if (response.success && response.students.length > 0) {
                            let studentHtml = '';

                            response.students.forEach(function(student) {
                                studentHtml += `
                                    <div class="option student-option" data-value="${student.full_name}" data-id="${student.id}">
                                        <input type="checkbox" name="students[]" value="${student.id}" id="student-${student.id}">
                                        <div class="student-info">
                                            <div class="student-name">${student.full_name}</div>
                                            <div class="student-details">ID: ${student.student_id} | Stream: ${student.stream}</div>
                                        </div>
                                    </div>
                                `;
                            });

                            $('#student-list').html(studentHtml);
                            $('#student-placeholder').text('Select students');

                            // Bind click events to new student options
                            bindStudentEvents();

                        } else {
                            $('#student-list').html('<div class="no-students">No students found for selected class and streams</div>');
                            $('#student-placeholder').text('No students available');
                        }

                        updateStudentPlaceholder();
                    },
                    error: function(xhr, status, error) {
                        $('#student-loading').hide();
                        $('#student-list').html('<div class="no-students">Error loading students. Please try again.</div>');
                        $('#student-placeholder').text('Error loading students');
                        console.error('AJAX Error:', error);
                    }
                });
            }

            // Function to bind events to student options
            function bindStudentEvents() {
                // Handle student option selection
                $('.student-option').off('click').on('click', function() {
                    const checkbox = $(this).find('input[type="checkbox"]');
                    checkbox.prop('checked', !checkbox.prop('checked'));

                    if (checkbox.prop('checked')) {
                        $(this).addClass('selected');
                    } else {
                        $(this).removeClass('selected');
                    }

                    updateStudentPlaceholder();
                });

                // Handle student checkbox clicks
                $('.student-option input[type="checkbox"]').off('click').on('click', function(e) {
                    e.stopPropagation();

                    if ($(this).prop('checked')) {
                        $(this).closest('.student-option').addClass('selected');
                    } else {
                        $(this).closest('.student-option').removeClass('selected');
                    }

                    updateStudentPlaceholder();
                });
            }

            // Function to update stream placeholder text
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

            // Function to update student placeholder text
            function updateStudentPlaceholder() {
                const selectedOptions = $('.student-option input[type="checkbox"]:checked');

                if (selectedOptions.length === 0) {
                    if ($('#student-list').children().length === 0 || $('#student-list').find('.no-students').length > 0) {
                        $('#student-placeholder').text('No students available');
                    } else {
                        $('#student-placeholder').text('Select students');
                    }
                } else if (selectedOptions.length === 1) {
                    $('#student-placeholder').text(selectedOptions.closest('.student-option').data('value'));
                } else {
                    $('#student-placeholder').html(selectedOptions.length + ' students <span class="selected-count">' + selectedOptions.length + '</span>');
                }
            }

            // Initialize Select All/Deselect All functionality
            function initializeSelectAllFunctionality() {
                // Stream select all functionality
                $('#select-all-streams').click(function(e) {
                    e.preventDefault();

                    if ($('#stream-options').is(':hidden')) {
                        $('#stream-options').slideDown(200);
                        $('#stream-placeholder').addClass('active');
                    }

                    $('.stream-option input[type="checkbox"]').prop('checked', true);
                    $('.stream-option').addClass('selected');
                    updateStreamPlaceholder();
                    loadStudents(); // Reload students when streams change
                });

                $('#deselect-all-streams').click(function(e) {
                    e.preventDefault();

                    if ($('#stream-options').is(':hidden')) {
                        $('#stream-options').slideDown(200);
                        $('#stream-placeholder').addClass('active');
                    }

                    $('.stream-option input[type="checkbox"]').prop('checked', false);
                    $('.stream-option').removeClass('selected');
                    updateStreamPlaceholder();
                    loadStudents(); // Reload students when streams change
                });

                // Student select all functionality
                $('#select-all-students').click(function(e) {
                    e.preventDefault();

                    if ($('#students-options').is(':hidden') && !$('#student-selector').hasClass('disabled')) {
                        $('#students-options').slideDown(200);
                        $('#student-placeholder').addClass('active');
                    }

                    $('.student-option:visible input[type="checkbox"]').prop('checked', true);
                    $('.student-option:visible').addClass('selected');
                    updateStudentPlaceholder();
                });

                $('#deselect-all-students').click(function(e) {
                    e.preventDefault();

                    if ($('#students-options').is(':hidden') && !$('#student-selector').hasClass('disabled')) {
                        $('#students-options').slideDown(200);
                        $('#student-placeholder').addClass('active');
                    }

                    $('.student-option input[type="checkbox"]').prop('checked', false);
                    $('.student-option').removeClass('selected');
                    updateStudentPlaceholder();
                });
            }

            // Report type selection and dynamic options handling
            $('.report-card').click(function() {
                $('.report-card').removeClass('selected');
                $(this).addClass('selected');
                $(this).find('input[type="radio"]').prop('checked', true);
                handleDynamicOptions();
            });

            $('.report-card input[type="radio"]').click(function(e) {
                e.stopPropagation();
                $('.report-card').removeClass('selected');
                $(this).closest('.report-card').addClass('selected');
                handleDynamicOptions();
            });

            // Function to handle dynamic options visibility
            function handleDynamicOptions() {
                const selectedReportType = $('input[name="report_type"]:checked').val();

                $('.dynamic-option').removeClass('show');

                if (selectedReportType === 'detailed') {
                    $('#eot-scores-option').addClass('show');
                    $('#student-position-option').addClass('show');
                } else if (selectedReportType === 'summarized') {
                    $('#aoi-columns-option').addClass('show');
                    $('#student-position-option').addClass('show');
                } else if (selectedReportType === 'assessment') {
                    $('#aoi-columns-option').addClass('show');
                    $('#percentage-option').addClass('show');
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
                    $('#aoi-columns').val('2');
                }
            }

            // Initialize dynamic options on page load
            handleDynamicOptions();

            // Close dropdowns when clicking outside
            $(document).click(function(e) {
                if (!$(e.target).closest('.stream-selector').length) {
                    $('#stream-options').slideUp(200);
                    $('#stream-placeholder').removeClass('active');
                }

                if (!$(e.target).closest('.student-selector').length) {
                    $('#students-options').slideUp(200);
                    $('#student-placeholder').removeClass('active');
                }
            });

            // CORRECTED FORM SUBMISSION - MULTIPLE STUDENTS IN ONE REPORT
            $('#generate-report-form').submit(function(e) {
                e.preventDefault();

                // Validate required fields
                if ($('#class').val() === '') {
                    alert('Please select a class');
                    return false;
                }

                if ($('#term').val() === '') {
                    alert('Please select a term');
                    return false;
                }

                if ($('.stream-option input[type="checkbox"]:checked').length === 0) {
                    alert('Please select at least one stream');
                    return false;
                }

                if ($('.student-option input[type="checkbox"]:checked').length === 0) {
                    alert('Please select at least one student');
                    return false;
                }

                if (!$('input[name="report_type"]:checked').length) {
                    alert('Please select a report type');
                    return false;
                }

                // Get selected students and report type
                const selectedStudents = [];
                $('.student-option input[type="checkbox"]:checked').each(function() {
                    selectedStudents.push($(this).val());
                });

                const reportType = $('input[name="report_type"]:checked').val();

                // Determine target URL
                let targetUrl = '';
                switch (reportType) {
                    case 'detailed':
                        targetUrl = 'individual_detailed_report.php';
                        break;
                    case 'summarized':
                        targetUrl = 'individual_summarized_report.php';
                        break;
                    case 'assessment':
                        targetUrl = 'individual_assessment_report.php';
                        break;
                    default:
                        alert('Invalid report type selected');
                        return false;
                }

                // Create ONE form for ALL selected students (not individual forms)
                const submitForm = $('<form>', {
                    'method': 'GET',
                    'action': targetUrl,
                    'target': '_blank'
                });

                // Add basic form data
                submitForm.append($('<input>', {
                    'type': 'hidden',
                    'name': 'class',
                    'value': $('#class').val()
                }));

                submitForm.append($('<input>', {
                    'type': 'hidden',
                    'name': 'term',
                    'value': $('#term').val()
                }));

                submitForm.append($('<input>', {
                    'type': 'hidden',
                    'name': 'year',
                    'value': $('#year').val()
                }));

                submitForm.append($('<input>', {
                    'type': 'hidden',
                    'name': 'report_type',
                    'value': reportType
                }));

                // Add ALL selected students as an array
                selectedStudents.forEach(function(studentId) {
                    submitForm.append($('<input>', {
                        'type': 'hidden',
                        'name': 'students[]', // This creates the array
                        'value': studentId
                    }));
                });

                // Add streams
                $('.stream-option input[type="checkbox"]:checked').each(function() {
                    submitForm.append($('<input>', {
                        'type': 'hidden',
                        'name': 'streams[]',
                        'value': $(this).val()
                    }));
                });

                // Add dynamic options
                if ($('#display-eot-scores').is(':checked')) {
                    submitForm.append($('<input>', {
                        'type': 'hidden',
                        'name': 'display_eot_scores',
                        'value': '1'
                    }));
                }

                if ($('#display-percentage').is(':checked')) {
                    submitForm.append($('<input>', {
                        'type': 'hidden',
                        'name': 'display_percentage',
                        'value': '1'
                    }));
                }

                if ($('#display-student-position').is(':checked')) {
                    submitForm.append($('<input>', {
                        'type': 'hidden',
                        'name': 'display_student_position',
                        'value': '1'
                    }));
                }

                if ($('#aoi-columns').val()) {
                    submitForm.append($('<input>', {
                        'type': 'hidden',
                        'name': 'aoi_columns',
                        'value': $('#aoi-columns').val()
                    }));
                }

                // Submit the SINGLE form with ALL students
                $('body').append(submitForm);
                submitForm.submit();
                submitForm.remove();

                return false;
            });

            // Reset form functionality
            $('button[type="reset"]').click(function() {
                $('#generate-report-form')[0].reset();

                $('.stream-option input[type="checkbox"]').prop('checked', false);
                $('.stream-option').removeClass('selected');
                $('.student-option input[type="checkbox"]').prop('checked', false);
                $('.student-option').removeClass('selected');
                $('.report-card').removeClass('selected');

                $('#stream-placeholder').text('Select streams');
                $('#student-placeholder').text('Select class and stream first');
                $('#student-list').empty();
                $('#student-selector').addClass('disabled');

                $('.dynamic-option').removeClass('show');
                $('#display-percentage').prop('checked', false);
                $('#display-eot-scores').prop('checked', false);
                $('#display-student-position').prop('checked', false);
                $('#aoi-columns').val('2');
            });
        });
    </script>
</body>

</html>