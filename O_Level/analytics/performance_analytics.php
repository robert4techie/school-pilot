<?php
// Database connection
require_once '../../includes/conn.php';

// Get subjects from database where level is like 'O'
function getSubjects($conn) {
    $sql = "SELECT * FROM subjects WHERE level LIKE 'O%' ORDER BY subj_name";
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

// Define static streams
$available_streams = ['A', 'B', 'C', 'D'];

// Initialize variables
$current_year = date("Y");
?>
<!DOCTYPE html>
<html data-bs-theme="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Student Performance Analytics</title>
    <link rel="stylesheet" href="../../assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../assets/fonts/fontawesome-all.min.css">
    <link rel="stylesheet" href="../../assets/fonts/font-awesome.min.css">
    <link rel="stylesheet" href="../../assets/fonts/fontawesome5-overrides.min.css">
    <link rel="stylesheet" href="../../assets/css/custom.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <style>
        /* Original form styling from your system */
        body {
            background-color: #f5f7fa;
            font-size: 13px;
        }
        
        .x_panel {
            border-radius: 3px !important;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05) !important;
            border: none !important;
        }
        
        .x_title {
            border-bottom: 1px solid #f0f0f0 !important;
            padding-bottom: 15px;
        }
        
        .x_title h2 {
            font-weight: 500;
            color: #3a4a5d;
            font-size: 16px;
        }
        
        /* Form element styling */
        .form-control, .form-select {
            height: 38px;
            border-radius: 3px !important;
            border: 1px solid #dce1e9 !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02) !important;
            font-size: 13px;
            background-color: #fff;
            transition: all 0.2s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #4a9eff !important;
            box-shadow: 0 0 0 3px rgba(74, 158, 255, 0.15) !important;
        }
        
        /* Label styling */
        .control-label {
            color: #6a7a8c;
            font-weight: 500;
            font-size: 13px;
            margin-bottom: 8px;
        }
        
        /* Stream selector styling */
        .stream-selector, .subject-selector {
            position: relative;
            border: 1px solid #dce1e9;
            border-radius: 3px;
            background-color: #fff;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        
        .stream-placeholder, .subject-placeholder {
            height: 38px;
            padding: 0 15px;
            color: #6a7a8c;
            background-color: white;
            border: none;
            cursor: pointer;
            position: relative;
            display: flex;
            align-items: center;
            transition: all 0.2s ease;
            font-size: 13px;
        }
        
        .stream-placeholder:hover, .subject-placeholder:hover {
            background-color: #f8fafd;
        }
        
        .stream-placeholder:after, .subject-placeholder:after {
            content: '';
            position: absolute;
            right: 12px;
            width: 16px;
            height: 16px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236a7a8c' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: center;
            transition: transform 0.2s ease;
        }
        
        .stream-placeholder.active:after, .subject-placeholder.active:after {
            transform: rotate(180deg);
        }
        
        .stream-options, .subject-options {
            max-height: 250px;
            overflow-y: auto;
            border-top: 1px solid #f0f0f0;
            box-shadow: 0 5px 10px rgba(0,0,0,0.05) inset;
        }
        
        .stream-option, .subject-option {
            padding: 10px 15px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background-color 0.2s;
            display: flex;
            align-items: center;
            font-size: 13px;
        }
        
        .stream-option:last-child, .subject-option:last-child {
            border-bottom: none;
        }
        
        .stream-option:hover, .subject-option:hover {
            background-color: #f0f7ff;
        }
        
        .stream-option.selected, .subject-option.selected {
            background-color: #4a9eff;
            color: white;
        }
        
        .stream-option input[type="checkbox"], .subject-option input[type="checkbox"] {
            margin-right: 10px;
        }
        
        /* Select All/Deselect All links */
        .select-actions {
            margin-top: 8px;
            display: flex;
            font-size: 13px;
        }
        
        .select-actions a {
            text-decoration: none;
            color: #4a9eff;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .select-actions a:hover {
            color: #2277e8;
        }
        
        .select-actions span {
            color: #d0d6e1;
            margin: 0 8px;
        }
        
        /* Button styling */
        .btn {
            border-radius: 3px !important;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 500;
            padding: 0 20px;
            transition: all 0.2s ease;
            font-size: 13px;
        }
        
        .btn-success {
            background-color: #4bbe65 !important;
            border-color: #4bbe65 !important;
        }
        
        .btn-success:hover {
            background-color: #3ba954 !important;
            border-color: #3ba954 !important;
            box-shadow: 0 4px 10px rgba(75, 190, 101, 0.2) !important;
        }
        
        .btn-primary {
            background-color: #4a9eff !important;
            border-color: #4a9eff !important;
        }
        
        .btn-primary:hover {
            background-color: #2277e8 !important;
            border-color: #2277e8 !important;
            box-shadow: 0 4px 10px rgba(74, 158, 255, 0.2) !important;
        }
        
        .btn i {
            margin-right: 8px;
        }
        
        /* Form group spacing */
        .form-group.row {
            margin-bottom: 20px !important;
        }
        
        .ln_solid {
            border-top: 1px solid #f0f0f0;
            margin: 24px 0;
        }
        
        /* Analytics Results Styling */
        .analytics-results {
            background: #fff;
            border-radius: 2px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-top: 30px;
            padding: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 3px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            border: 1px solid rgba(74, 158, 255, 0.1);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
        }
        
        .stat-card h4 {
            color: #3a4a5d;
            margin-bottom: 15px;
            font-weight: 600;
            border-bottom: 2px solid #4a9eff;
            padding-bottom: 10px;
            font-size: 14px;
        }
        
        .performer-item {
            background: #fff;
            border-radius: 3px;
            padding: 12px;
            margin-bottom: 8px;
            border-left: 3px solid #4a9eff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
        }
        
        .performer-rank {
            background: linear-gradient(135deg, #4a9eff 0%, #2277e8 100%);
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 11px;
        }
        
        .performer-info {
            flex-grow: 1;
            margin-left: 12px;
        }
        
        .performer-name {
            font-weight: 600;
            color: #3a4a5d;
            margin-bottom: 2px;
            font-size: 12px;
        }
        
        .performer-details {
            font-size: 11px;
            color: #6a7a8c;
        }
        
        .performer-score {
            font-weight: bold;
            font-size: 14px;
            color: #4bbe65;
        }
        
        .gender-stats {
            display: flex;
            justify-content: space-around;
            margin-bottom: 15px;
        }
        
        .gender-stat {
            text-align: center;
            padding: 12px;
            border-radius: 3px;
            flex: 1;
            margin: 0 5px;
        }
        
        .gender-stat.male {
            background: linear-gradient(135deg, #4a9eff 0%, #2277e8 100%);
            color: white;
        }
        
        .gender-stat.female {
            background: linear-gradient(135deg, #e91e63 0%, #c2185b 100%);
            color: white;
        }
        
        .gender-stat h6 {
            margin: 0;
            font-size: 11px;
            opacity: 0.9;
        }
        
        .gender-stat .value {
            font-size: 18px;
            font-weight: bold;
            margin: 3px 0;
        }
        
        .chart-container {
            background: #fff;
            border-radius: 3px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            height: 300px;
        }
        
        .chart-container h5 {
            margin-bottom: 10px;
            font-size: 13px;
            color: #3a4a5d;
            font-weight: 600;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #4a9eff;
        }
        
        .loading i {
            font-size: 2rem;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #6a7a8c;
            font-style: italic;
        }
        
        .table {
            font-size: 12px;
        }
        
        .table th {
            background: linear-gradient(135deg, #4a9eff 0%, #2277e8 100%);
            color: white;
            border: none;
            font-weight: 600;
            text-align: center;
            font-size: 11px;
        }
        
        .table td {
            vertical-align: middle;
            text-align: center;
            font-size: 11px;
        }
        
        .badge {
            font-size: 10px;
        }
        
        .summary-stats {
            background: #f8f9fa;
            border-radius: 3px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #4a9eff;
        }
        
        .summary-stats .row > div {
            text-align: center;
            margin-bottom: 15px;
        }
        
        .summary-stats h6 {
            color: #6a7a8c;
            font-size: 11px;
            margin-bottom: 5px;
            text-transform: uppercase;
            font-weight: 600;
        }
        
        .summary-stats .stat-value {
            font-size: 20px;
            font-weight: bold;
            color: #3a4a5d;
        }
        
        .summary-stats .stat-value.success { color: #4bbe65; }
        .summary-stats .stat-value.primary { color: #4a9eff; }
        .summary-stats .stat-value.warning { color: #ffc107; }
        .summary-stats .stat-value.info { color: #17a2b8; }
    </style>
</head>
<body class="nav-md">
    <div class="container body">
        <div class="main_container">
            <!-- Include Sidebar -->
            <?php include '../../includes/sidebar.php'; ?>
            
            <!-- Include Header -->
            <?php include '../../includes/header.php'; ?>
            
            <!-- Main Content Area -->
            <div class="right_col" role="main">
                <div class="row">
                    <div class="col-md-12 col-sm-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2><i class="fas fa-chart-line"></i> Student Performance Analytics</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <!-- Analytics Form -->
                                <form id="analytics-form" class="form-horizontal form-label-left">
                                    <div class="form-group row mb-3">
                                        <label class="control-label col-md-3 col-sm-3">Select Class:</label>
                                        <div class="col-md-6 col-sm-6">
                                            <select name="class" id="class" class="form-select" required>
                                                <option value="">-- Select Class --</option>
                                                <option value="Senior One">Senior One</option>
                                                <option value="Senior Two">Senior Two</option>
                                                <option value="Senior Three">Senior Three</option>
                                                <option value="Senior Four">Senior Four</option>
                                                <option value="Senior Five">Senior Five</option>
                                                <option value="Senior Six">Senior Six</option>
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
                                            <input type="text" class="form-control" name="year" id="year" value="<?php echo $current_year; ?>" required>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group row mb-3">
                                        <label class="control-label col-md-3 col-sm-3">Select Stream(s):</label>
                                        <div class="col-md-6 col-sm-6">
                                            <div class="stream-selector">
                                                <div class="stream-placeholder" id="stream-placeholder">Select streams</div>
                                                <div class="stream-options" id="stream-options" style="display: none;">
                                                    <?php foreach ($available_streams as $stream): ?>
                                                    <div class="stream-option" data-value="<?php echo $stream; ?>">
                                                        <input type="checkbox" name="streams[]" value="<?php echo $stream; ?>" id="stream-<?php echo $stream; ?>">
                                                        Stream <?php echo $stream; ?>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <div class="select-actions">
                                                <a href="#" id="select-all-streams">Select All</a>
                                                <span>|</span>
                                                <a href="#" id="deselect-all-streams">Deselect All</a>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group row mb-3">
                                        <label class="control-label col-md-3 col-sm-3">Subject(s) - Optional:</label>
                                        <div class="col-md-6 col-sm-6">
                                            <div class="subject-selector">
                                                <div class="subject-placeholder" id="subject-placeholder">All subjects</div>
                                                <div class="subject-options" id="subject-options" style="display: none;">
                                                    <div class="subject-option" data-value="">
                                                        <input type="checkbox" name="subjects[]" value="" id="subject-all" checked>
                                                        All Subjects
                                                    </div>
                                                    <?php foreach ($all_subjects as $subject): ?>
                                                    <div class="subject-option" data-value="<?php echo $subject['subj_name']; ?>">
                                                        <input type="checkbox" name="subjects[]" value="<?php echo $subject['subj_name']; ?>" id="subject-<?php echo str_replace(' ', '_', $subject['subj_name']); ?>">
                                                        <?php echo $subject['subj_name']; ?>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <div class="select-actions">
                                                <a href="#" id="select-all-subjects">Select All</a>
                                                <span>|</span>
                                                <a href="#" id="deselect-all-subjects">Deselect All</a>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group row mb-3">
                                        <label class="control-label col-md-3 col-sm-3">Analysis Type:</label>
                                        <div class="col-md-6 col-sm-6">
                                            <select name="analysis_type" id="analysis_type" class="form-select">
                                                <option value="overall">Overall Performance (AOI + EOT)</option>
                                                <option value="aoi">AOI Performance Only</option>
                                                <option value="subject">Subject-specific Analysis</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="ln_solid"></div>
                                    
                                    <div class="form-group row">
                                        <div class="col-md-6 col-sm-6 offset-md-3">
                                            <button type="submit" class="btn btn-primary" id="analyze-btn">
                                                <i class="fas fa-chart-bar"></i> Analyze Performance
                                            </button>
                                            <button type="button" class="btn btn-secondary ms-2" id="export-btn" style="display: none;">
                                                <i class="fas fa-download"></i> Export Results
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Analytics Results Section -->
                <div id="analytics-results" style="display: none;">
                    <div class="row">
                        <div class="col-md-12 col-sm-12">
                            <div class="x_panel analytics-results">
                                <div class="x_title">
                                    <h2><i class="fas fa-analytics"></i> Performance Analysis Results</h2>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <!-- Loading State -->
                                    <div id="loading-state" class="loading">
                                        <i class="fas fa-spinner"></i>
                                        <p>Analyzing performance data...</p>
                                    </div>
                                    
                                    <!-- Summary Statistics -->
                                    <div id="summary-statistics" style="display: none;">
                                        <div class="summary-stats">
                                            <div class="row">
                                                <div class="col-md-3">
                                                    <h6>Total Students</h6>
                                                    <div class="stat-value primary" id="total-students">-</div>
                                                </div>
                                                <div class="col-md-3">
                                                    <h6>Class Average</h6>
                                                    <div class="stat-value success" id="class-average">-</div>
                                                </div>
                                                <div class="col-md-3">
                                                    <h6>Highest Score</h6>
                                                    <div class="stat-value warning" id="highest-score">-</div>
                                                </div>
                                                <div class="col-md-3">
                                                    <h6>Pass Rate</h6>
                                                    <div class="stat-value info" id="pass-rate">-</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Performance Analysis -->
                                    <div id="performance-analysis" style="display: none;">
                                        <div class="row">
                                            <!-- Best Performers -->
                                            <div class="col-md-6">
                                                <div class="stat-card">
                                                    <h4><i class="fas fa-trophy"></i> Top Performers</h4>
                                                    <div id="best-performers">
                                                        <!-- Will be populated dynamically -->
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Gender Analysis -->
                                            <div class="col-md-6">
                                                <div class="stat-card">
                                                    <h4><i class="fas fa-venus-mars"></i> Gender Analysis</h4>
                                                    <div class="gender-stats">
                                                        <div class="gender-stat male">
                                                            <h6>Male Students</h6>
                                                            <div class="value" id="male-count">-</div>
                                                            <small>Avg: <span id="male-average">-</span>%</small>
                                                        </div>
                                                        <div class="gender-stat female">
                                                            <h6>Female Students</h6>
                                                            <div class="value" id="female-count">-</div>
                                                            <small>Avg: <span id="female-average">-</span>%</small>
                                                        </div>
                                                    </div>
                                                    <div class="mt-3">
                                                        <div class="text-center">
                                                            <strong>Performance Difference: <span id="gender-difference" class="text-primary">-</span></strong>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Charts Section -->
                                    <div id="charts-section" style="display: none;">
                                        <div class="row">
                                            <div class="col-md-12">
                                                <div class="chart-container">
                                                    <h5><i class="fas fa-chart-line"></i> Performance Distribution</h5>
                                                    <canvas id="performance-chart"></canvas>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="chart-container">
                                                    <h5><i class="fas fa-chart-pie"></i> Grade Distribution</h5>
                                                    <canvas id="grade-chart"></canvas>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="chart-container">
                                                    <h5><i class="fas fa-chart-bar"></i> Gender Comparison</h5>
                                                    <canvas id="gender-chart"></canvas>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Detailed Results Table -->
                                    <div id="detailed-results" style="display: none;">
                                        <div class="stat-card">
                                            <h4><i class="fas fa-table"></i> Detailed Results</h4>
                                            <div class="table-responsive">
                                                <table class="table table-striped">
                                                    <thead>
                                                        <tr>
                                                            <th>Rank</th>
                                                            <th>Student Name</th>
                                                            <th>Student ID</th>
                                                            <th>Gender</th>
                                                            <th>Stream</th>
                                                            <th>Score (%)</th>
                                                            <th>Grade</th>
                                                            <th>Subjects</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="results-table-body">
                                                        <!-- Will be populated dynamically -->
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- No Data State -->
                                    <div id="no-data-state" class="no-data" style="display: none;">
                                        <i class="fas fa-chart-line fa-3x mb-3"></i>
                                        <h4>No Data Found</h4>
                                        <p>No performance data available for the selected criteria.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript Files -->
    <script src="../../assets/js/jquery.min.js"></script>
    <script src="../../assets/bootstrap/js/bootstrap.min.js"></script>
    <script src="../../assets/js/bs-init.js"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/custom.min.js"></script>
    
    <script>
        // Global variables
        let performanceChart = null;
        let gradeChart = null;
        let genderChart = null;
        
        $(document).ready(function() {
            // Initialize stream selector
            initializeStreamSelector();
            
            // Initialize subject selector
            initializeSubjectSelector();
            
            // Form submission handler
            $('#analytics-form').submit(function(e) {
                e.preventDefault();
                performAnalysis();
            });
            
            // Export button handler
            $('#export-btn').click(function() {
                exportResults();
            });
        });
        
        // Initialize stream selector functionality
        function initializeStreamSelector() {
            updateStreamPlaceholder();
            
            $('#stream-placeholder').click(function() {
                $('#stream-options').slideToggle(200);
                $(this).toggleClass('active');
            });
            
            $('.stream-option').click(function(e) {
                if (!$(e.target).is('input[type="checkbox"]')) {
                    const checkbox = $(this).find('input[type="checkbox"]');
                    checkbox.prop('checked', !checkbox.prop('checked'));
                    updateStreamSelection($(this), checkbox);
                }
            });
            
            $('.stream-option input[type="checkbox"]').click(function(e) {
                e.stopPropagation();
                updateStreamSelection($(this).closest('.stream-option'), $(this));
            });
            
            $('#select-all-streams').click(function(e) {
                e.preventDefault();
                $('.stream-option input[type="checkbox"]').prop('checked', true);
                $('.stream-option').addClass('selected');
                updateStreamPlaceholder();
            });
            
            $('#deselect-all-streams').click(function(e) {
                e.preventDefault();
                $('.stream-option input[type="checkbox"]').prop('checked', false);
                $('.stream-option').removeClass('selected');
                updateStreamPlaceholder();
            });
        }
        
        // Initialize subject selector functionality
        function initializeSubjectSelector() {
            updateSubjectPlaceholder();
            
            $('#subject-placeholder').click(function() {
                $('#subject-options').slideToggle(200);
                $(this).toggleClass('active');
            });
            
            $('.subject-option').click(function(e) {
                if (!$(e.target).is('input[type="checkbox"]')) {
                    const checkbox = $(this).find('input[type="checkbox"]');
                    
                    // Handle "All Subjects" special case
                    if (checkbox.val() === '') {
                        if (!checkbox.prop('checked')) {
                            $('.subject-option input[type="checkbox"]').prop('checked', false);
                            $('.subject-option').removeClass('selected');
                            checkbox.prop('checked', true);
                            $(this).addClass('selected');
                        }
                    } else {
                        // Uncheck "All Subjects" when selecting individual subjects
                        $('#subject-all').prop('checked', false);
                        $('#subject-all').closest('.subject-option').removeClass('selected');
                        
                        checkbox.prop('checked', !checkbox.prop('checked'));
                    }
                    
                    updateSubjectSelection($(this), checkbox);
                }
            });
            
            $('.subject-option input[type="checkbox"]').click(function(e) {
                e.stopPropagation();
                
                if ($(this).val() === '') {
                    // "All Subjects" clicked
                    if ($(this).prop('checked')) {
                        $('.subject-option input[type="checkbox"]').prop('checked', false);
                        $('.subject-option').removeClass('selected');
                        $(this).prop('checked', true);
                        $(this).closest('.subject-option').addClass('selected');
                    }
                } else {
                    // Individual subject clicked
                    $('#subject-all').prop('checked', false);
                    $('#subject-all').closest('.subject-option').removeClass('selected');
                }
                
                updateSubjectSelection($(this).closest('.subject-option'), $(this));
            });
            
            $('#select-all-subjects').click(function(e) {
                e.preventDefault();
                $('.subject-option input[type="checkbox"]').prop('checked', true);
                $('.subject-option').addClass('selected');
                updateSubjectPlaceholder();
            });
            
            $('#deselect-all-subjects').click(function(e) {
                e.preventDefault();
                $('.subject-option input[type="checkbox"]').prop('checked', false);
                $('.subject-option').removeClass('selected');
                // Check "All Subjects" by default
                $('#subject-all').prop('checked', true);
                $('#subject-all').closest('.subject-option').addClass('selected');
                updateSubjectPlaceholder();
            });
        }
        
        // Update stream selection styling
        function updateStreamSelection(option, checkbox) {
            if (checkbox.prop('checked')) {
                option.addClass('selected');
            } else {
                option.removeClass('selected');
            }
            updateStreamPlaceholder();
        }
        
        // Update subject selection styling
        function updateSubjectSelection(option, checkbox) {
            if (checkbox.prop('checked')) {
                option.addClass('selected');
            } else {
                option.removeClass('selected');
            }
            updateSubjectPlaceholder();
        }
        
        // Update stream placeholder text
        function updateStreamPlaceholder() {
            const selectedOptions = $('.stream-option input[type="checkbox"]:checked');
            
            if (selectedOptions.length === 0) {
                $('#stream-placeholder').text('Select streams');
            } else if (selectedOptions.length === 1) {
                $('#stream-placeholder').text('Stream ' + selectedOptions.val());
            } else {
                $('#stream-placeholder').text(selectedOptions.length + ' streams selected');
            }
        }
        
        // Update subject placeholder text
        function updateSubjectPlaceholder() {
            const selectedOptions = $('.subject-option input[type="checkbox"]:checked');
            const allSubjectsSelected = $('#subject-all').prop('checked');
            
            if (allSubjectsSelected || selectedOptions.length === 0) {
                $('#subject-placeholder').text('All subjects');
            } else if (selectedOptions.length === 1) {
                $('#subject-placeholder').text(selectedOptions.first().val());
            } else {
                $('#subject-placeholder').text(selectedOptions.length + ' subjects selected');
            }
        }
        
        // Close dropdowns when clicking outside
        $(document).click(function(e) {
            if (!$(e.target).closest('.stream-selector').length) {
                $('#stream-options').slideUp(200);
                $('#stream-placeholder').removeClass('active');
            }
            if (!$(e.target).closest('.subject-selector').length) {
                $('#subject-options').slideUp(200);
                $('#subject-placeholder').removeClass('active');
            }
        });
        
        // Main analysis function
        async function performAnalysis() {
            // Validate form
            if (!validateForm()) {
                return;
            }
            
            // Show results section and loading state
            $('#analytics-results').slideDown();
            showLoadingState();
            
            // Prepare form data
            const formData = new FormData(document.getElementById('analytics-form'));
            const params = new URLSearchParams();
            
            // Add form data to params
            for (let [key, value] of formData.entries()) {
                params.append(key, value);
            }
            
            try {
                // Make API call
                const response = await fetch('analytics_api.php?' + params.toString());
                const result = await response.json();
                
                if (result.success) {
                    displayResults(result.data);
                } else {
                    showNoDataState(result.error || 'An error occurred while analyzing data');
                }
                
            } catch (error) {
                console.error('Analysis error:', error);
                showNoDataState('Failed to connect to the server. Please try again.');
            }
        }
        
        // Validate form before submission
        function validateForm() {
            const class_ = $('#class').val();
            const term = $('#term').val();
            const year = $('#year').val();
            const selectedStreams = $('.stream-option input[type="checkbox"]:checked');
            
            if (!class_) {
                alert('Please select a class');
                $('#class').focus();
                return false;
            }
            
            if (!term) {
                alert('Please select a term');
                $('#term').focus();
                return false;
            }
            
            if (!year) {
                alert('Please enter an academic year');
                $('#year').focus();
                return false;
            }
            
            if (selectedStreams.length === 0) {
                alert('Please select at least one stream');
                return false;
            }
            
            return true;
        }
        
        // Show loading state
        function showLoadingState() {
            $('#loading-state').show();
            hideAllResultSections();
        }
        
        // Display analysis results
        function displayResults(data) {
            $('#loading-state').hide();
            
            // Display summary statistics
            displaySummaryStats(data.summary);
            
            // Display performance analysis
            displayPerformanceAnalysis(data);
            
            // Create charts
            createCharts(data);
            
            // Display detailed results
            displayDetailedResults(data.students);
            
            // Show all result sections
            showAllResultSections();
            
            // Show export button
            $('#export-btn').show();
        }
        
        // Display summary statistics
        function displaySummaryStats(summary) {
            $('#total-students').text(summary.total_students || 0);
            $('#class-average').text((summary.class_average || 0) + '%');
            $('#highest-score').text((summary.highest_score || 0) + '%');
            $('#pass-rate').text((summary.pass_rate || 0) + '%');
        }
        
        // Display performance analysis
        function displayPerformanceAnalysis(data) {
            // Top performers
            const topPerformers = data.students.slice(0, 5);
            const performersHtml = topPerformers.map((student, index) => `
                <div class="performer-item">
                    <div class="performer-rank">${index + 1}</div>
                    <div class="performer-info">
                        <div class="performer-name">${student.name}</div>
                        <div class="performer-details">${student.id} • ${student.gender} • Stream ${student.stream}</div>
                    </div>
                    <div class="performer-score">${student.score}%</div>
                </div>
            `).join('');
            
            $('#best-performers').html(performersHtml);
            
            // Gender analysis
            $('#male-count').text(data.gender.male_count);
            $('#female-count').text(data.gender.female_count);
            $('#male-average').text(data.gender.male_average);
            $('#female-average').text(data.gender.female_average);
            
            const betterGender = data.gender.female_average > data.gender.male_average ? 'Female' : 'Male';
            $('#gender-difference').text(`${betterGender} students perform ${data.gender.difference}% better`);
        }
        
        // Create charts
        function createCharts(data) {
            createPerformanceChart(data);
            createGradeChart(data);
            createGenderChart(data);
        }
        
        // Create performance distribution chart
        function createPerformanceChart(data) {
            const ctx = document.getElementById('performance-chart').getContext('2d');
            
            if (performanceChart) {
                performanceChart.destroy();
            }
            
            const bands = [
                { label: '90-100%', min: 90, max: 100 },
                { label: '80-89%', min: 80, max: 89 },
                { label: '70-79%', min: 70, max: 79 },
                { label: '60-69%', min: 60, max: 69 },
                { label: '50-59%', min: 50, max: 59 },
                { label: '0-49%', min: 0, max: 49 }
            ];
            
            const bandCounts = bands.map(band => 
                data.students.filter(s => s.score >= band.min && s.score <= band.max).length
            );
            
            performanceChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: bands.map(b => b.label),
                    datasets: [{
                        label: 'Number of Students',
                        data: bandCounts,
                        borderColor: '#4a9eff',
                        backgroundColor: 'rgba(74, 158, 255, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Students'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Score Range'
                            }
                        }
                    }
                }
            });
        }
        
        // Create grade distribution chart
        function createGradeChart(data) {
            const ctx = document.getElementById('grade-chart').getContext('2d');
            
            if (gradeChart) {
                gradeChart.destroy();
            }
            
            const grades = ['A', 'B', 'C', 'D', 'E', 'F'];
            const gradeCounts = grades.map(grade => data.grade_distribution[grade] || 0);
            
            gradeChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: grades,
                    datasets: [{
                        data: gradeCounts,
                        backgroundColor: [
                            '#4bbe65', '#17a2b8', '#ffc107', 
                            '#fd7e14', '#e83e8c', '#dc3545'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 10,
                                usePointStyle: true,
                                font: {
                                    size: 11
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Create gender comparison chart
        function createGenderChart(data) {
            const ctx = document.getElementById('gender-chart').getContext('2d');
            
            if (genderChart) {
                genderChart.destroy();
            }
            
            const grades = ['A', 'B', 'C', 'D', 'E', 'F'];
            const maleData = grades.map(grade => 
                data.students.filter(s => s.grade === grade && s.gender.toLowerCase() === 'male').length
            );
            const femaleData = grades.map(grade => 
                data.students.filter(s => s.grade === grade && s.gender.toLowerCase() === 'female').length
            );
            
            genderChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: grades,
                    datasets: [{
                        label: 'Male',
                        data: maleData,
                        backgroundColor: 'rgba(74, 158, 255, 0.8)',
                        borderColor: '#4a9eff',
                        borderWidth: 1
                    }, {
                        label: 'Female',
                        data: femaleData,
                        backgroundColor: 'rgba(233, 30, 99, 0.8)',
                        borderColor: '#e91e63',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Students'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Grade'
                            }
                        }
                    }
                }
            });
        }
        
        // Display detailed results table
        function displayDetailedResults(students) {
            const tbody = $('#results-table-body');
            
            const tableHtml = students.map((student, index) => `
                <tr>
                    <td><span class="badge ${getRankBadgeClass(index + 1)}">${index + 1}</span></td>
                    <td class="text-start">${student.name}</td>
                    <td>${student.id}</td>
                    <td>
                        <span class="badge ${student.gender.toLowerCase() === 'male' ? 'bg-primary' : 'bg-danger'}">
                            ${student.gender}
                        </span>
                    </td>
                    <td><span class="badge bg-secondary">Stream ${student.stream}</span></td>
                    <td><strong>${student.score}%</strong></td>
                    <td><span class="badge ${getGradeBadgeClass(student.grade)}">${student.grade}</span></td>
                    <td><span class="badge bg-info">${student.subjects_taken}</span></td>
                </tr>
            `).join('');
            
            tbody.html(tableHtml);
        }
        
        // Helper function to get rank badge class
        function getRankBadgeClass(rank) {
            if (rank <= 3) return 'bg-warning text-dark';
            if (rank <= 10) return 'bg-info';
            return 'bg-secondary';
        }
        
        // Helper function to get grade badge class
        function getGradeBadgeClass(grade) {
            const gradeClasses = {
                'A': 'bg-success',
                'B': 'bg-info',
                'C': 'bg-warning text-dark',
                'D': 'bg-warning text-dark',
                'E': 'bg-danger',
                'F': 'bg-danger'
            };
            return gradeClasses[grade] || 'bg-secondary';
        }
        
        // Hide all result sections
        function hideAllResultSections() {
            $('#summary-statistics').hide();
            $('#performance-analysis').hide();
            $('#charts-section').hide();
            $('#detailed-results').hide();
            $('#no-data-state').hide();
        }
        
        // Show all result sections
        function showAllResultSections() {
            $('#summary-statistics').slideDown();
            $('#performance-analysis').slideDown();
            $('#charts-section').slideDown();
            $('#detailed-results').slideDown();
        }
        
        // Show no data state
        function showNoDataState(message = 'No data found for the selected criteria.') {
            $('#loading-state').hide();
            hideAllResultSections();
            $('#no-data-state').find('p').text(message);
            $('#no-data-state').show();
        }
        
        // Export functionality - Updated for simpler PDF generation
        function exportResults() {
            // Show loading state for export
            const exportBtn = $('#export-btn');
            const originalText = exportBtn.html();
            exportBtn.html('<i class="fas fa-spinner fa-spin"></i> Generating PDF...');
            exportBtn.prop('disabled', true);
            
            // Create a form to submit the data
            const exportForm = document.createElement('form');
            exportForm.method = 'POST';
            exportForm.action = 'analytics_export_simple.php';
            exportForm.target = '_blank';
            
            // Get current form data
            const formData = new FormData(document.getElementById('analytics-form'));
            
            // Add all form data as hidden inputs
            for (let [key, value] of formData.entries()) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                exportForm.appendChild(input);
            }
            
            // Add export type
            const exportInput = document.createElement('input');
            exportInput.type = 'hidden';
            exportInput.name = 'export';
            exportInput.value = 'pdf';
            exportForm.appendChild(exportInput);
            
            // Add current analysis data as JSON
            const currentData = getCurrentAnalysisData();
            if (currentData) {
                const dataInput = document.createElement('input');
                dataInput.type = 'hidden';
                dataInput.name = 'analysis_data';
                dataInput.value = JSON.stringify(currentData);
                exportForm.appendChild(dataInput);
            }
            
            // Submit form to open in new window
            document.body.appendChild(exportForm);
            exportForm.submit();
            document.body.removeChild(exportForm);
            
            // Reset button after a delay
            setTimeout(() => {
                exportBtn.html(originalText);
                exportBtn.prop('disabled', false);
            }, 2000);
        }
        
        // Get current analysis data for export
        function getCurrentAnalysisData() {
            return {
                summary: {
                    total_students: $('#total-students').text(),
                    class_average: $('#class-average').text(),
                    highest_score: $('#highest-score').text(),
                    pass_rate: $('#pass-rate').text()
                },
                gender: {
                    male_count: $('#male-count').text(),
                    female_count: $('#female-count').text(),
                    male_average: $('#male-average').text(),
                    female_average: $('#female-average').text(),
                    difference: $('#gender-difference').text()
                },
                top_performers: getTopPerformersData(),
                detailed_results: getDetailedResultsData(),
                analysis_params: {
                    class: $('#class').val(),
                    term: $('#term').val(),
                    year: $('#year').val(),
                    streams: getSelectedStreams(),
                    subjects: getSelectedSubjects(),
                    analysis_type: $('#analysis_type').val()
                }
            };
        }
        
        // Get top performers data
        function getTopPerformersData() {
            const performers = [];
            $('#best-performers .performer-item').each(function() {
                const rank = $(this).find('.performer-rank').text();
                const name = $(this).find('.performer-name').text();
                const details = $(this).find('.performer-details').text();
                const score = $(this).find('.performer-score').text();
                performers.push({ rank, name, details, score });
            });
            return performers;
        }
        
        // Get detailed results data
        function getDetailedResultsData() {
            const results = [];
            $('#results-table-body tr').each(function() {
                const row = {};
                $(this).find('td').each(function(index) {
                    const columnNames = ['rank', 'name', 'id', 'gender', 'stream', 'score', 'grade', 'subjects'];
                    row[columnNames[index]] = $(this).text().trim();
                });
                results.push(row);
            });
            return results;
        }
        
        // Get selected streams
        function getSelectedStreams() {
            const streams = [];
            $('.stream-option input[type="checkbox"]:checked').each(function() {
                streams.push($(this).val());
            });
            return streams;
        }
        
        // Get selected subjects
        function getSelectedSubjects() {
            const subjects = [];
            $('.subject-option input[type="checkbox"]:checked').each(function() {
                const value = $(this).val();
                if (value !== '') {
                    subjects.push(value);
                }
            });
            return subjects.length > 0 ? subjects : ['All Subjects'];
        }
        
        // Real-time form validation
        function setupFormValidation() {
            const requiredFields = ['#class', '#term', '#year'];
            requiredFields.forEach(field => {
                $(field).on('change', function() {
                    validateFormRealTime();
                });
            });
            
            // Stream validation
            $('.stream-option input[type="checkbox"]').on('change', function() {
                validateFormRealTime();
            });
        }
        
        // Real-time form validation
        function validateFormRealTime() {
            const class_ = $('#class').val();
            const term = $('#term').val();
            const year = $('#year').val();
            const selectedStreams = $('.stream-option input[type="checkbox"]:checked');
            
            const isValid = class_ && term && year && selectedStreams.length > 0;
            
            $('#analyze-btn').prop('disabled', !isValid);
            
            if (isValid) {
                $('#analyze-btn').removeClass('btn-secondary').addClass('btn-primary');
            } else {
                $('#analyze-btn').removeClass('btn-primary').addClass('btn-secondary');
            }
        }
        
        // Initialize form validation
        setupFormValidation();
        
        // Additional utility functions
        function resetForm() {
            $('#analytics-form')[0].reset();
            $('.stream-option').removeClass('selected');
            $('.subject-option').removeClass('selected');
            $('#subject-all').prop('checked', true).closest('.subject-option').addClass('selected');
            updateStreamPlaceholder();
            updateSubjectPlaceholder();
            $('#analytics-results').hide();
            $('#export-btn').hide();
        }
        
        // Add reset button functionality
        $(document).on('click', '#reset-btn', function() {
            resetForm();
        });
        
        // Print functionality
        function printResults() {
            const printContent = $('#analytics-results').html();
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Performance Analytics Report</title>
                    <style>
                        body { font-family: Arial, sans-serif; font-size: 12px; }
                        .stat-card { border: 1px solid #ddd; margin: 10px 0; padding: 15px; }
                        .table { border-collapse: collapse; width: 100%; }
                        .table th, .table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        .badge { padding: 2px 6px; border-radius: 3px; color: white; }
                        .bg-success { background-color: #28a745; }
                        .bg-primary { background-color: #007bff; }
                        .bg-warning { background-color: #ffc107; color: black; }
                        .bg-danger { background-color: #dc3545; }
                        .bg-info { background-color: #17a2b8; }
                        .bg-secondary { background-color: #6c757d; }
                        @media print { body { margin: 0; } }
                    </style>
                </head>
                <body>
                    <h1>Student Performance Analytics Report</h1>
                    <p>Generated on: ${new Date().toLocaleString()}</p>
                    ${printContent}
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }
        
        // Add print button functionality
        $(document).on('click', '#print-btn', function() {
            printResults();
        });
        
        // Advanced filtering and comparison features
        function compareWithPreviousTerm() {
            // Implementation for comparing with previous term data
            alert('Term comparison feature will be implemented in the next version.');
        }
        
        function showSubjectBreakdown() {
            // Implementation for detailed subject-wise breakdown
            alert('Subject breakdown feature will be implemented in the next version.');
        }
        
        // Error handling for API calls
        function handleApiError(error) {
            console.error('API Error:', error);
            showNoDataState('An error occurred while fetching data. Please try again.');
        }
        
        // Initialize tooltips and popovers if needed
        function initializeTooltips() {
            // Initialize Bootstrap tooltips
            if (typeof bootstrap !== 'undefined') {
                var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                });
            }
        }
        
        // Call initialization functions
        initializeTooltips();
    </script>
</body>
</html>