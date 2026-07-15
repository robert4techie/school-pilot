<?php
ob_start();
// Database connection
require_once '../conn.php';

// Function to get all exam sets
function getExamSets($conn) {
    $sql = "SELECT id, exam_set, description FROM exam_sets ORDER BY id";
    $result = mysqli_query($conn, $sql);
    
    $examSets = array();
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $examSets[] = $row;
        }
    }
    
    return $examSets;
}

// Function to get exam sets for a specific class
function getExamSetsForClass($conn, $class) {
    $sql = "SELECT e.id, e.exam_set, e.description 
            FROM exam_sets e 
            WHERE e.classes LIKE '%" . mysqli_real_escape_string($conn, $class) . "%' 
            OR e.classes = '' 
            ORDER BY e.id";
    $result = mysqli_query($conn, $sql);
    
    $examSets = array();
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $examSets[] = $row;
        }
    }
    
    return $examSets;
}

// Function to get available classes
function getClasses($conn) {
    $sql = "SELECT DISTINCT class FROM students WHERE class LIKE 'Senior%' ORDER BY class";
    $result = mysqli_query($conn, $sql);
    
    $classes = array();
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $classes[] = $row['class'];
        }
    }
    
    return $classes;
}

// Function to get all terms
function getTerms() {
    return array('Term 1', 'Term 2', 'Term 3');
}

// Function to get current and past 5 years
function getYears() {
    $currentYear = date('Y');
    $years = array();
    
    for ($i = 0; $i <= 5; $i++) {
        $years[] = $currentYear - $i;
    }
    
    return $years;
}

// Function to get existing settings
function getExistingSettings($conn) {
    $sql = "SELECT * FROM alevel_report_settings ORDER BY year DESC, term ASC, class ASC";
    $result = mysqli_query($conn, $sql);
    
    $settings = array();
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $settings[] = $row;
        }
    }
    
    return $settings;
}

// Function to get specific setting by class, term, and year
function getSettingByClassTermYear($conn, $class, $term, $year) {
    $sql = "SELECT * FROM alevel_report_settings 
            WHERE class = '" . mysqli_real_escape_string($conn, $class) . "' 
            AND term = '" . mysqli_real_escape_string($conn, $term) . "' 
            AND year = '" . mysqli_real_escape_string($conn, $year) . "'";
    $result = mysqli_query($conn, $sql);
    
    if ($result && mysqli_num_rows($result) > 0) {
        return mysqli_fetch_assoc($result);
    }
    
    return null;
}

// AJAX handlers
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // Handle AJAX request to get exam sets for a class
    if ($_GET['ajax'] == 'getExamSets') {
        $class = isset($_GET['class']) ? $_GET['class'] : '';
        $term = isset($_GET['term']) ? $_GET['term'] : '';
        $year = isset($_GET['year']) ? $_GET['year'] : '';
        
        $classExamSets = getExamSetsForClass($conn, $class);
        
        // Check if there's an existing setting for this class, term, and year
        $existingSetting = null;
        if (!empty($class) && !empty($term) && !empty($year)) {
            $existingSetting = getSettingByClassTermYear($conn, $class, $term, $year);
        }
        
        $response = array(
            'success' => true,
            'examSets' => $classExamSets,
            'existingSetting' => $existingSetting
        );
        
        echo json_encode($response);
        exit;
    }
    
    // Handle AJAX request to toggle exam set selection
    if ($_GET['ajax'] == 'toggleExamSet') {
        $class = isset($_GET['class']) ? $_GET['class'] : '';
        $term = isset($_GET['term']) ? $_GET['term'] : '';
        $year = isset($_GET['year']) ? $_GET['year'] : '';
        $examSetId = isset($_GET['examSetId']) ? intval($_GET['examSetId']) : 0;
        $isChecked = isset($_GET['isChecked']) ? ($_GET['isChecked'] === 'true') : false;
        
        // Validation
        if (empty($class) || empty($term) || empty($year) || $examSetId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Missing required parameters.']);
            exit;
        }
        
        // Get the exam set info
        $sql = "SELECT exam_set FROM exam_sets WHERE id = " . $examSetId;
        $result = mysqli_query($conn, $sql);
        
        if (!$result || mysqli_num_rows($result) == 0) {
            echo json_encode(['success' => false, 'message' => 'Exam set not found.']);
            exit;
        }
        
        $examSetRow = mysqli_fetch_assoc($result);
        $examSetName = $examSetRow['exam_set'];
        
        // Check if setting already exists
        $setting = getSettingByClassTermYear($conn, $class, $term, $year);
        
        if ($setting) {
            // Setting exists, update it
            $currentExamSets = explode(', ', $setting['exam_sets']);
            
            if ($isChecked) {
                // Add exam set if not already present
                if (!in_array($examSetName, $currentExamSets)) {
                    $currentExamSets[] = $examSetName;
                }
            } else {
                // Remove exam set
                $currentExamSets = array_filter($currentExamSets, function($item) use ($examSetName) {
                    return $item !== $examSetName;
                });
            }
            
            // Update the setting
            $examSetsString = implode(', ', $currentExamSets);
            $updateSql = "UPDATE alevel_report_settings SET 
                        exam_sets = '" . mysqli_real_escape_string($conn, $examSetsString) . "'
                        WHERE id = " . $setting['id'];
            
            if (mysqli_query($conn, $updateSql)) {
                $selectedCount = count($currentExamSets);
                echo json_encode([
                    'success' => true, 
                    'message' => $isChecked ? 'Exam set added.' : 'Exam set removed.',
                    'examSets' => $currentExamSets,
                    'count' => $selectedCount
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error updating setting: ' . mysqli_error($conn)]);
            }
        } else {
            // Setting doesn't exist, create a new one
            $examSetsString = $examSetName;
            $insertSql = "INSERT INTO alevel_report_settings (class, term, year, exam_sets) VALUES (
                        '" . mysqli_real_escape_string($conn, $class) . "',
                        '" . mysqli_real_escape_string($conn, $term) . "',
                        '" . mysqli_real_escape_string($conn, $year) . "',
                        '" . mysqli_real_escape_string($conn, $examSetsString) . "')";
            
            if (mysqli_query($conn, $insertSql)) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'New setting created with exam set.',
                    'examSets' => [$examSetName],
                    'count' => 1
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error creating setting: ' . mysqli_error($conn)]);
            }
        }
        
        exit;
    }
}

// Get available classes
$classes = getClasses($conn);

// Get terms
$terms = getTerms();

// Get years
$years = getYears();

// Get existing settings
$existingSettings = getExistingSettings($conn);

// Initialize variables
$selectedClass = '';
$selectedTerm = '';
$selectedYear = date('Y');
$errorMessage = '';
$successMessage = '';

// Get all exam sets (default)
$examSets = getExamSets($conn);
?>
<!DOCTYPE html>
<html data-bs-theme="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>A-Level Report Settings</title>
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/fonts/fontawesome-all.min.css">
    <link rel="stylesheet" href="../assets/fonts/font-awesome.min.css">
    <link rel="stylesheet" href="../assets/fonts/fontawesome5-overrides.min.css">
    <link rel="stylesheet" href="../assets/css/custom.min.css">
    <style>
        body {
            background-color: #f5f7fa;
            font-size: 13px;
        }
        
        .settings-container {
            background-color: #fff;
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        
        .page-header h4 {
            font-size: 18px;
            font-weight: 600;
            color: #3a4a5d;
            margin: 0;
            display: flex;
            align-items: center;
        }
        
        .page-header h4 i {
            margin-right: 8px;
            color: #4a9eff;
        }
        
        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #3a4a5d;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5e9f0;
        }
        
        .form-section {
            background-color: #f8fafd;
            border: 1px solid #e5e9f0;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-group {
            flex: 1;
            min-width: 200px;
        }
        
        .form-group label {
            font-weight: 500;
            color: #3a4a5d;
            margin-bottom: 5px;
            display: block;
        }
        
        .form-control {
            height: 38px;
            border-radius: 4px;
            border: 1px solid #dce1e9;
            padding: 8px 12px;
            font-size: 13px;
            width: 100%;
        }
        
        .form-control:focus {
            border-color: #4a9eff;
            box-shadow: 0 0 0 3px rgba(74, 158, 255, 0.15);
            outline: none;
        }
        
        .exam-sets-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
            position: relative;
        }
        
        .exam-set-item {
            background-color: #fff;
            border: 1px solid #e5e9f0;
            border-radius: 4px;
            padding: 12px;
            transition: all 0.2s ease;
        }
        
        .exam-set-item:hover {
            border-color: #4a9eff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .exam-set-item.selected {
            background-color: #e0f0ff;
            border-color: #4a9eff;
        }
        
        .exam-set-checkbox {
            margin-right: 10px;
        }
        
        .exam-set-name {
            font-weight: 600;
            color: #3a4a5d;
        }
        
        .exam-set-description {
            color: #6a7a8c;
            font-size: 12px;
            margin-top: 5px;
        }
        
        .selection-counter {
            display: inline-block;
            background-color: #e0f0ff;
            color: #4a9eff;
            font-size: 12px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 12px;
            margin-left: 10px;
        }
        
        .counter-warning {
            background-color: #fee7e7;
            color: #e74c3c;
        }
        
        .settings-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 20px;
        }
        
        .settings-table th {
            background-color: #f8fafd;
            color: #6a7a8c;
            font-weight: 600;
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e5e9f0;
        }
        
        .settings-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        
        .settings-table tbody tr:nth-child(even) {
            background-color: #f8fafd;
        }
        
        .settings-table tbody tr:hover {
            background-color: #f0f7ff;
        }
        
        .table-action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .btn-action {
            width: 32px;
            height: 32px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-edit {
            background-color: #4a9eff;
        }
        
        .btn-edit:hover {
            background-color: #2277e8;
        }
        
        .btn-delete {
            background-color: #e74c3c;
        }
        
        .btn-delete:hover {
            background-color: #c0392b;
        }
        
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(255, 255, 255, 0.7);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10;
            border-radius: 4px;
        }
        
        .loading-overlay i {
            color: #4a9eff;
            font-size: 24px;
        }
        
        .empty-state {
            text-align: center;
            padding: 30px;
            color: #6a7a8c;
        }
        
        .empty-state i {
            font-size: 48px;
            color: #dce1e9;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            margin-bottom: 15px;
        }
        
        .status-indicator {
            display: inline-block;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            margin-left: 10px;
        }
        
        .status-success {
            background-color: #4bbe65;
        }
        
        .status-error {
            background-color: #e74c3c;
        }
        
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        
        .toast {
            background-color: #fff;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            padding: 12px 15px;
            margin-bottom: 10px;
            width: 300px;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
            opacity: 0;
            transform: translateY(-20px);
        }
        
        .toast.show {
            opacity: 1;
            transform: translateY(0);
        }
        
        .toast.success {
            border-left: 4px solid #4bbe65;
        }
        
        .toast.error {
            border-left: 4px solid #e74c3c;
        }
        
        .toast i {
            margin-right: 10px;
            font-size: 16px;
        }
        
        .toast.success i {
            color: #4bbe65;
        }
        
        .toast.error i {
            color: #e74c3c;
        }
        
        .toast-message {
            flex: 1;
        }
        
        .toast-close {
            color: #6a7a8c;
            cursor: pointer;
        }
    </style>
</head>
<body class="nav-md">
    <div class="container body">
        <div class="main_container">
       <?php include 'nav.php'; ?>
            
            <!-- Toast Container for Notifications -->
            <div class="toast-container" id="toastContainer"></div>
            
            <!-- Main Content Area -->
            <div class="right_col" role="main">
                <div class="row">
                    <div class="col-md-12">
                        <div class="settings-container">
                            <div class="page-header">
                                <h4>
                                    <i class="fas fa-cog"></i> 
                                    A-Level Report Settings
                                </h4>
                            </div>
                            
                            <!-- Settings Form -->
                            <div class="form-section">
                                <div class="section-title">
                                    Add New Report Setting
                                </div>
                                
                                <div id="reportSettingsForm">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="class">Class <span class="text-danger">*</span></label>
                                            <select class="form-control" id="class" name="class" required>
                                                <option value="">Select Class</option>
                                                <?php foreach ($classes as $class): ?>
                                                <option value="<?php echo htmlspecialchars($class); ?>" <?php echo $selectedClass == $class ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($class); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="term">Term <span class="text-danger">*</span></label>
                                            <select class="form-control" id="term" name="term" required>
                                                <option value="">Select Term</option>
                                                <?php foreach ($terms as $term): ?>
                                                <option value="<?php echo htmlspecialchars($term); ?>" <?php echo $selectedTerm == $term ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($term); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="year">Year <span class="text-danger">*</span></label>
                                            <select class="form-control" id="year" name="year" required>
                                                <option value="">Select Year</option>
                                                <?php foreach ($years as $year): ?>
                                                <option value="<?php echo $year; ?>" <?php echo $selectedYear == $year ? 'selected' : ''; ?>>
                                                    <?php echo $year; ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>
                                            Exam Sets 
                                            <span class="text-danger">*</span>
                                            <span class="selection-counter counter-warning">
                                                <span id="selectedCount">0</span> selected
                                            </span>
                                            <small class="text-muted d-block">(Select at least 3 exam sets to be displayed in reports)</small>
                                        </label>
                                        
                                        <div class="exam-sets-grid" id="examSetsGrid">
                                            <div class="loading-overlay" id="loadingOverlay" style="display: none;">
                                                <i class="fas fa-spinner fa-spin"></i>
                                            </div>
                                            
                                            <div class="alert alert-info" role="alert">
                                                Please select a class, term, and year to load available exam sets.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Existing Settings Table -->
                            <div class="section-title">Existing Report Settings</div>
                            
                            <div id="existingSettingsContainer">
                                <?php if (count($existingSettings) > 0): ?>
                                <div class="table-responsive">
                                    <table class="settings-table">
                                        <thead>
                                            <tr>
                                                <th>Class</th>
                                                <th>Term</th>
                                                <th>Year</th>
                                                <th>Exam Sets</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($existingSettings as $setting): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($setting['class']); ?></td>
                                                <td><?php echo htmlspecialchars($setting['term']); ?></td>
                                                <td><?php echo htmlspecialchars($setting['year']); ?></td>
                                                <td><?php echo htmlspecialchars($setting['exam_sets']); ?></td>
                                                <td>
                                                    <div class="table-action-buttons">
                                                        <a href="#" class="btn-action btn-edit edit-setting" 
                                                           data-id="<?php echo $setting['id']; ?>"
                                                           data-class="<?php echo htmlspecialchars($setting['class']); ?>"
                                                           data-term="<?php echo htmlspecialchars($setting['term']); ?>"
                                                           data-year="<?php echo htmlspecialchars($setting['year']); ?>"
                                                           title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="#" class="btn-action btn-delete delete-setting" 
                                                           data-id="<?php echo $setting['id']; ?>"
                                                           title="Delete">
                                                            <i class="fas fa-trash-alt"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-cog"></i>
                                    <p>No report settings found. Add your first setting above.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
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
            // Track the current class, term, and year selections
            let currentClass = '';
            let currentTerm = '';
            let currentYear = '';
            
            // Function to show a toast notification
            function showToast(message, type = 'success') {
                const toastId = 'toast-' + Date.now();
                const toast = `
                    <div class="toast ${type}" id="${toastId}">
                        <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                        <div class="toast-message">${message}</div>
                        <div class="toast-close" onclick="closeToast('${toastId}')">
                            <i class="fas fa-times"></i>
                        </div>
                    </div>
                `;
                
                $('#toastContainer').append(toast);
                setTimeout(() => {
                    $(`#${toastId}`).addClass('show');
                }, 100);
                
                // Auto-close after 3 seconds
                setTimeout(() => {
                    closeToast(toastId);
                }, 3000);
            }
            
            // Function to close a toast
            window.closeToast = function(toastId) {
                const toast = $(`#${toastId}`);
                toast.removeClass('show');
                setTimeout(() => {
                    toast.remove();
                }, 300);
            };
            
            // Function to refresh existing settings table
            function refreshExistingSettings() {
                $.ajax({
                    url: window.location.href,
                    type: 'GET',
                    success: function(response) {
                        const newContent = $(response).find('#existingSettingsContainer').html();
                        $('#existingSettingsContainer').html(newContent);
                        attachEventHandlers();
                    }
                });
            }
            
            // Function to update exam sets based on class selection
            function updateExamSets() {
                currentClass = $('#class').val();
                currentTerm = $('#term').val();
                currentYear = $('#year').val();
                
                if (!currentClass || !currentTerm || !currentYear) {
                    $('#examSetsGrid').html(`
                        <div class="alert alert-info" role="alert">
                            Please select a class, term, and year to load available exam sets.
                        </div>
                    `);
                    return;
                }
                
                $('#loadingOverlay').show();
                
                // Make AJAX request to get exam sets for this class
                $.ajax({
                    url: window.location.href,
                    type: 'GET',
                    data: {
                        ajax: 'getExamSets',
                        class: currentClass,
                        term: currentTerm,
                        year: currentYear
                    },
                    dataType: 'json',
                    success: function(response) {
                        // Clear existing exam sets
                        $('#examSetsGrid').empty();
                        
                        if (response.examSets && response.examSets.length > 0) {
                            // Create HTML for exam sets
                            let html = '';
                            const selectedExamSets = [];
                            
                            // If there's an existing setting, get the exam set names
                            let existingExamSetNames = [];
                            if (response.existingSetting) {
                                existingExamSetNames = response.existingSetting.exam_sets.split(', ');
                            }
                            
                            response.examSets.forEach(function(examSet) {
                                // Check if this exam set is in the existing setting
                                const isSelected = existingExamSetNames.includes(examSet.exam_set);
                                if (isSelected) {
                                    selectedExamSets.push(examSet.id);
                                }
                                
                                html += `
                                <div class="exam-set-item ${isSelected ? 'selected' : ''}">
                                    <div>
                                        <input type="checkbox" 
                                               class="exam-set-checkbox" 
                                               name="exam_sets[]" 
                                               value="${examSet.id}"
                                               id="exam_set_${examSet.id}"
                                               ${isSelected ? 'checked' : ''}>
                                        <label class="exam-set-name" for="exam_set_${examSet.id}">
                                            ${examSet.exam_set}
                                        </label>
                                    </div>
                                    ${examSet.description ? `
                                    <div class="exam-set-description">
                                        ${examSet.description}
                                    </div>
                                    ` : ''}
                                </div>
                                `;
                            });
                            
                            // Add HTML to the grid
$('#examSetsGrid').html(html);

// Update selected count
const selectedCount = selectedExamSets.length;
$('#selectedCount').text(selectedCount);

// Toggle warning class
if (selectedCount < 3) {
    $('.selection-counter').addClass('counter-warning');
} else {
    $('.selection-counter').removeClass('counter-warning');
}

// Attach event handlers for auto-save
attachCheckboxHandlers();

} else {
    // No exam sets found
    $('#examSetsGrid').html(`
        <div class="alert alert-warning" role="alert">
            No exam sets found for this class. Please add exam sets first.
        </div>
    `);
    
    // Update selected count
    $('#selectedCount').text(0);
    $('.selection-counter').addClass('counter-warning');
}

// Hide loading overlay
$('#loadingOverlay').hide();
},
error: function() {
    // Error handling
    $('#examSetsGrid').html(`
        <div class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-circle"></i> Error loading exam sets. Please try again.
        </div>
    `);
    
    // Hide loading overlay
    $('#loadingOverlay').hide();
}
});
}

// Function to attach checkbox handlers with auto-save functionality
function attachCheckboxHandlers() {
    $('.exam-set-checkbox').on('change', function() {
        const parentItem = $(this).closest('.exam-set-item');
        const examSetId = $(this).val();
        const isChecked = $(this).is(':checked');
        
        // Update UI immediately for responsiveness
        if (isChecked) {
            parentItem.addClass('selected');
        } else {
            parentItem.removeClass('selected');
        }
        
        // Add loading indicator to the parent item
        const statusIndicator = $('<span class="status-indicator"></span>');
        parentItem.append(statusIndicator);
        
        // Make AJAX request to toggle the exam set
        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: {
                ajax: 'toggleExamSet',
                class: currentClass,
                term: currentTerm,
                year: currentYear,
                examSetId: examSetId,
                isChecked: isChecked
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Update the UI
                    statusIndicator.addClass('status-success');
                    
                    // Update the selected count
                    $('#selectedCount').text(response.count);
                    
                    // Toggle warning class
                    if (response.count < 3) {
                        $('.selection-counter').addClass('counter-warning');
                    } else {
                        $('.selection-counter').removeClass('counter-warning');
                    }
                    
                    // Show success toast
                    showToast(response.message);
                    
                    // Refresh the existing settings table
                    refreshExistingSettings();
                    
                    // Remove status indicator after a short delay
                    setTimeout(function() {
                        statusIndicator.remove();
                    }, 1000);
                } else {
                    // Error handling
                    statusIndicator.addClass('status-error');
                    
                    // Revert checkbox state
                    if (isChecked) {
                        $(this).prop('checked', false);
                        parentItem.removeClass('selected');
                    } else {
                        $(this).prop('checked', true);
                        parentItem.addClass('selected');
                    }
                    
                    // Show error toast
                    showToast(response.message, 'error');
                    
                    // Remove status indicator after a short delay
                    setTimeout(function() {
                        statusIndicator.remove();
                    }, 1000);
                }
            }.bind(this),
            error: function() {
                // Error handling
                statusIndicator.addClass('status-error');
                
                // Revert checkbox state
                if (isChecked) {
                    $(this).prop('checked', false);
                    parentItem.removeClass('selected');
                } else {
                    $(this).prop('checked', true);
                    parentItem.addClass('selected');
                }
                
                // Show error toast
                showToast('Error saving setting. Please try again.', 'error');
                
                // Remove status indicator after a short delay
                setTimeout(function() {
                    statusIndicator.remove();
                }, 1000);
            }.bind(this)
        });
    });
}

// Function to attach event handlers for edit and delete buttons
function attachEventHandlers() {
    // Edit button handler
    $('.edit-setting').on('click', function(e) {
        e.preventDefault();
        
        // Get data attributes
        const settingClass = $(this).data('class');
        const settingTerm = $(this).data('term');
        const settingYear = $(this).data('year');
        
        // Update form selections
        $('#class').val(settingClass);
        $('#term').val(settingTerm);
        $('#year').val(settingYear);
        
        // Update exam sets
        updateExamSets();
        
        // Scroll to form
        $('html, body').animate({
            scrollTop: $('#reportSettingsForm').offset().top - 100
        }, 500);
    });
    
    // Delete button handler
    $('.delete-setting').on('click', function(e) {
        e.preventDefault();
        
        if (confirm('Are you sure you want to delete this setting?')) {
            const settingId = $(this).data('id');
            
            // Show loading overlay
            $('body').append('<div class="loading-overlay" id="globalLoadingOverlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(255,255,255,0.7); z-index: 9999; display: flex; justify-content: center; align-items: center;"><i class="fas fa-spinner fa-spin" style="font-size: 48px; color: #4a9eff;"></i></div>');
            
            // Make AJAX request to delete setting
            $.ajax({
                url: 'delete_report_setting.php',
                type: 'POST',
                data: {
                    id: settingId
                },
                dataType: 'json',
                success: function(response) {
                    // Remove loading overlay
                    $('#globalLoadingOverlay').remove();
                    
                    if (response.success) {
                        // Show success toast
                        showToast('Setting deleted successfully.');
                        
                        // Refresh the existing settings table
                        refreshExistingSettings();
                    } else {
                        // Show error toast
                        showToast(response.message || 'Error deleting setting.', 'error');
                    }
                },
                error: function() {
                    // Remove loading overlay
                    $('#globalLoadingOverlay').remove();
                    
                    // Show error toast
                    showToast('Error deleting setting. Please try again.', 'error');
                }
            });
        }
    });
}

// Attach event handlers to form fields
$('#class, #term, #year').on('change', function() {
    updateExamSets();
});

// Initial setup of event handlers
attachEventHandlers();

// Function to create the delete_report_setting.php file
function createDeleteScript() {
    const deleteScript = `<?php
// Database connection
require_once '../includes/conn.php';

// Check if ID is provided
if (isset($_POST['id'])) {
    $id = intval($_POST['id']);
    
    // Delete the setting
    $sql = "DELETE FROM alevel_report_settings WHERE id = " . $id;
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error deleting setting: ' . mysqli_error($conn)]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Missing setting ID.']);
}
?>`;

    // In a real scenario, this would create a file on the server
    console.log('Delete script created with content:', deleteScript);
}

// Call the function to show it in the console for reference
createDeleteScript();
});
</script>
</body>
</html>