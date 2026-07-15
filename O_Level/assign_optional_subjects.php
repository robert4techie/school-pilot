<?php
require_once '../auth.php';
require_once '../conn.php';
require_once 'teacher_auth_check.php';

// Get current teacher info
$current_teacher = $_SESSION['username'] ?? 'System';

// Get parameters
$class = isset($_GET['class']) ? $_GET['class'] : 'Senior One';
$subject = isset($_GET['subject']) ? $_GET['subject'] : '';
$streams = isset($_GET['streams']) ? $_GET['streams'] : [];

// Get optional subjects only
function getOptionalSubjects($conn) {
    $sql = "SELECT * FROM subjects WHERE compulsory = 0 AND level LIKE 'O%' ORDER BY subj_name";
    $result = mysqli_query($conn, $sql);
    
    $subjects = array();
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $subjects[] = $row;
        }
    }
    return $subjects;
}

// Get students for selected class and streams
function getStudentsForClass($conn, $class, $streams) {
    if (empty($streams) || !is_array($streams)) {
        return array();
    }
    
    $placeholders = str_repeat('?,', count($streams) - 1) . '?';
    $sql = "SELECT student_id, first_name, last_name, stream 
            FROM students 
            WHERE LOWER(current_class) = LOWER(?) 
            AND LOWER(stream) IN ($placeholders) 
            AND status = 'active'
            ORDER BY stream, first_name, last_name";
    
    $stmt = mysqli_prepare($conn, $sql);
    
    if (!$stmt) {
        error_log("Failed to prepare statement: " . mysqli_error($conn));
        return array();
    }
    
    $types = 's' . str_repeat('s', count($streams));
    $bind_values = array(strtolower($class));
    foreach ($streams as $stream) {
        $bind_values[] = strtolower($stream);
    }
    
    $refs = [];
    foreach ($bind_values as $key => $value) {
        $refs[$key] = &$bind_values[$key];
    }
    
    call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $students = array();
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $students[] = $row;
        }
    }
    
    mysqli_stmt_close($stmt);
    return $students;
}

// Get currently assigned students for a subject
function getAssignedStudents($conn, $class, $subject) {
    $sql = "SELECT student_id FROM student_subjects 
            WHERE LOWER(class) = LOWER(?) AND subject = ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $class, $subject);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $assigned = array();
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $assigned[] = $row['student_id'];
        }
    }
    
    mysqli_stmt_close($stmt);
    return $assigned;
}

$optional_subjects = getOptionalSubjects($conn);
$students = array();
$assigned_students = array();

if (!empty($class) && !empty($subject) && !empty($streams)) {
    $students = getStudentsForClass($conn, $class, $streams);
    $assigned_students = getAssignedStudents($conn, $class, $subject);
}

// Define available streams
$available_streams = ['East', 'West', 'South', 'North'];
$available_classes = ['Senior One', 'Senior Two', 'Senior Three', 'Senior Four'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Optional Subjects - SchoolPilot</title>
    <link rel="stylesheet" href="../assets/fonts/fontawesome-all.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <style>
        :root {
            --primary-color: #1e8449;
            --primary-light: #27ae60;
            --primary-dark: #145a32;
            --accent-color: #2ecc71;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: #f5f7fa;
            font-size: 13px;
            line-height: 1.6;
            color: #2c3e50;
        }

        .container {
            max-width: 100%;
            margin: 80px auto 20px;
            padding: 0 20px;
        }

        .page-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            color: white;
            padding: 25px 30px;
            border-radius: 8px 8px 0 0;
            box-shadow: 0 2px 10px rgba(30, 132, 73, 0.2);
        }

        .page-header h2 {
            font-size: 22px;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
        }

        .page-header i {
            margin-right: 12px;
        }

        .selection-panel {
            background: white;
            padding: 30px;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .form-group {
            flex: 1;
            min-width: 250px;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-select, .form-control {
            width: 100%;
            height: 42px;
            padding: 8px 15px;
            border: 2px solid #dce1e9;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-select:focus, .form-control:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(30, 132, 73, 0.1);
        }

        .stream-selector {
            position: relative;
            border: 2px solid #dce1e9;
            border-radius: 6px;
            background: white;
            transition: all 0.3s ease;
        }

        .stream-selector:hover {
            border-color: var(--primary-light);
        }

        .stream-placeholder {
            height: 42px;
            padding: 8px 45px 8px 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            font-size: 14px;
            position: relative;
        }

        .stream-placeholder:after {
            content: '';
            position: absolute;
            right: 15px;
            width: 16px;
            height: 16px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%231e8449' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            transition: transform 0.3s ease;
        }

        .stream-placeholder.active:after {
            transform: rotate(180deg);
        }

        .stream-options {
            max-height: 250px;
            overflow-y: auto;
            border-top: 2px solid #f0f4f9;
        }

        .stream-option {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f4f9;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
        }

        .stream-option:hover {
            background: rgba(30, 132, 73, 0.05);
        }

        .stream-option.selected {
            background: var(--primary-color);
            color: white;
        }

        .stream-option input[type="checkbox"] {
            margin-right: 10px;
            width: 16px;
            height: 16px;
            accent-color: var(--primary-color);
        }

        .select-actions {
            margin-top: 10px;
            display: flex;
            gap: 8px;
            font-size: 13px;
        }

        .select-actions a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s ease;
            padding: 4px 8px;
            border-radius: 4px;
        }

        .select-actions a:hover {
            background: rgba(30, 132, 73, 0.1);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn i {
            margin-right: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 132, 73, 0.3);
        }

        .students-panel {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .panel-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .panel-title {
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
        }

        .student-count {
            background: rgba(255, 255, 255, 0.2);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
        }

        .panel-body {
            padding: 0;
        }

        .bulk-actions {
            padding: 20px 30px;
            background: #f8fffe;
            border-bottom: 2px solid #e8f5e8;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .bulk-btn {
            padding: 8px 16px;
            font-size: 13px;
        }

        .btn-success {
            background: var(--accent-color);
            color: white;
        }

        .btn-warning {
            background: #f39c12;
            color: white;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }

        .search-box input {
            width: 100%;
            height: 38px;
            padding: 8px 15px 8px 40px;
            border: 2px solid #dce1e9;
            border-radius: 20px;
            font-size: 13px;
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-color);
        }

        .students-table {
            width: 100%;
            border-collapse: collapse;
        }

        .students-table th {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            color: white;
            padding: 14px 20px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .students-table td {
            padding: 14px 20px;
            border-bottom: 1px solid #f0f4f9;
        }

        .students-table tr:hover td {
            background: #f8fffe;
        }

        .student-checkbox {
            width: 18px;
            height: 18px;
            accent-color: var(--primary-color);
            cursor: pointer;
        }

        .student-name {
            font-weight: 600;
            color: #2c3e50;
        }

        .stream-badge {
            display: inline-block;
            padding: 4px 12px;
            background: var(--accent-color);
            color: white;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-assigned {
            color: var(--accent-color);
            font-weight: 600;
        }

        .status-unassigned {
            color: #95a5a6;
        }

        .empty-state {
            text-align: center;
            padding: 60px 30px;
            color: #6a7a8c;
        }

        .empty-state i {
            font-size: 48px;
            color: var(--primary-light);
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .saving-indicator {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            font-size: 12px;
            margin-left: 10px;
        }

        .saving-indicator i {
            margin-right: 6px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
            }
            
            .bulk-actions {
                flex-direction: column;
            }
            
            .search-box {
                width: 100%;
            }
        }
    </style>
</head>
<body>
        <?php  require_once '../nav.php'; ?>

    <div class="container">
        <div class="page-header">
            <h2><i class="fas fa-user-graduate"></i> Assign Students to Optional Subjects</h2>
        </div>

        <div class="selection-panel">
            <form id="selection-form" method="GET">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Select Class:</label>
                        <select name="class" id="class" class="form-select" required>
                            <option value="">-- Select Class --</option>
                            <?php foreach ($available_classes as $cls): ?>
                                <option value="<?php echo $cls; ?>" <?php echo ($class == $cls) ? 'selected' : ''; ?>>
                                    <?php echo $cls; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Select Subject (Optional Only):</label>
                        <select name="subject" id="subject" class="form-select" required>
                            <option value="">-- Select Subject --</option>
                            <?php foreach ($optional_subjects as $subj): ?>
                                <option value="<?php echo $subj['subj_name']; ?>" <?php echo ($subject == $subj['subj_name']) ? 'selected' : ''; ?>>
                                    <?php echo $subj['subj_name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Select Stream(s):</label>
                        <div class="stream-selector">
                            <div class="stream-placeholder" id="stream-placeholder">Select streams</div>
                            <div class="stream-options" id="stream-options" style="display: none;">
                                <?php foreach ($available_streams as $stream): ?>
                                    <div class="stream-option" data-value="<?php echo $stream; ?>">
                                        <input type="checkbox" name="streams[]" value="<?php echo $stream; ?>" 
                                               <?php echo (in_array($stream, $streams)) ? 'checked' : ''; ?>>
                                        <?php echo $stream; ?>
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

                <div style="text-align: right; margin-top: 20px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Load Students
                    </button>
                </div>
            </form>
        </div>

        <?php if (!empty($students)): ?>
            <div class="students-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fas fa-users"></i> Students - <?php echo $class; ?> (<?php echo $subject; ?>)
                        <span class="saving-indicator" id="saving-indicator" style="display: none;">
                            <i class="fas fa-spinner"></i> Saving...
                        </span>
                    </div>
                    <div class="student-count">
                        <span id="assigned-count"><?php echo count($assigned_students); ?></span> / <?php echo count($students); ?> assigned
                    </div>
                </div>

                <div class="bulk-actions">
                    <button type="button" class="btn btn-success bulk-btn" id="assign-all-btn">
                        <i class="fas fa-check-double"></i> Assign All
                    </button>
                    <button type="button" class="btn btn-warning bulk-btn" id="unassign-all-btn">
                        <i class="fas fa-times"></i> Unassign All
                    </button>
                    <button type="button" class="btn btn-secondary bulk-btn" id="assign-by-stream-btn">
                        <i class="fas fa-layer-group"></i> Assign by Stream
                    </button>
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="student-search" placeholder="Search students...">
                    </div>
                </div>

                <div class="panel-body">
                    <table class="students-table">
                        <thead>
                            <tr>
                                <th width="50px">
                                    <input type="checkbox" id="select-all-checkbox" class="student-checkbox">
                                </th>
                                <th width="80px">No.</th>
                                <th>Student Name</th>
                                <th width="100px">Stream</th>
                                <th width="120px">Status</th>
                            </tr>
                        </thead>
                        <tbody id="students-tbody">
                            <?php 
                            $counter = 1;
                            foreach ($students as $student): 
                                $is_assigned = in_array($student['student_id'], $assigned_students);
                            ?>
                                <tr class="student-row" data-stream="<?php echo strtolower($student['stream']); ?>">
                                    <td>
                                        <input type="checkbox" 
                                               class="student-checkbox assignment-checkbox" 
                                               data-student-id="<?php echo $student['student_id']; ?>"
                                               data-student-name="<?php echo $student['first_name'] . ' ' . $student['last_name']; ?>"
                                               data-stream="<?php echo $student['stream']; ?>"
                                               <?php echo $is_assigned ? 'checked' : ''; ?>>
                                    </td>
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <span class="student-name">
                                            <?php echo $student['first_name'] . ' ' . $student['last_name']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="stream-badge"><?php echo $student['stream']; ?></span>
                                    </td>
                                    <td class="status-cell">
                                        <span class="<?php echo $is_assigned ? 'status-assigned' : 'status-unassigned'; ?>">
                                            <?php echo $is_assigned ? 'Assigned' : 'Not Assigned'; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif (!empty($class) && !empty($subject) && !empty($streams)): ?>
            <div class="students-panel">
                <div class="empty-state">
                    <i class="fas fa-user-slash"></i>
                    <h3>No students found</h3>
                    <p>There are no students in the selected class and streams.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="../assets/js/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script>
        $(document).ready(function() {
            const classValue = '<?php echo $class; ?>';
            const subjectValue = '<?php echo $subject; ?>';
            const assignedBy = '<?php echo $current_teacher; ?>';
            
            // Stream selector functionality
            updateStreamPlaceholder();
            
            $('#stream-placeholder').click(function() {
                $('#stream-options').slideToggle(200);
                $(this).toggleClass('active');
            });
            
            $('.stream-option').click(function(e) {
                if (!$(e.target).is('input[type="checkbox"]')) {
                    const checkbox = $(this).find('input[type="checkbox"]');
                    checkbox.prop('checked', !checkbox.prop('checked'));
                    $(this).toggleClass('selected', checkbox.prop('checked'));
                    updateStreamPlaceholder();
                }
            });
            
            $('.stream-option input[type="checkbox"]').click(function(e) {
                e.stopPropagation();
                $(this).closest('.stream-option').toggleClass('selected', $(this).prop('checked'));
                updateStreamPlaceholder();
            });
            
            $('.stream-option input[type="checkbox"]:checked').each(function() {
                $(this).closest('.stream-option').addClass('selected');
            });
            
            function updateStreamPlaceholder() {
                const selected = $('.stream-option input[type="checkbox"]:checked');
                if (selected.length === 0) {
                    $('#stream-placeholder').text('Select streams');
                } else if (selected.length === 1) {
                    $('#stream-placeholder').text(selected.val());
                } else {
                    $('#stream-placeholder').text(selected.length + ' streams selected');
                }
            }
            
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
            
            $(document).click(function(e) {
                if (!$(e.target).closest('.stream-selector').length) {
                    $('#stream-options').slideUp(200);
                    $('#stream-placeholder').removeClass('active');
                }
            });

            // Auto-save functionality
            function updateAssignedCount() {
                const assignedCount = $('.assignment-checkbox:checked').length;
                $('#assigned-count').text(assignedCount);
            }

            function updateStatusCell(checkbox) {
                const row = checkbox.closest('tr');
                const statusCell = row.find('.status-cell span');
                
                if (checkbox.is(':checked')) {
                    statusCell.removeClass('status-unassigned').addClass('status-assigned').text('Assigned');
                } else {
                    statusCell.removeClass('status-assigned').addClass('status-unassigned').text('Not Assigned');
                }
                updateAssignedCount();
            }

            function saveAssignment(studentId, studentName, stream, isAssigned) {
                $('#saving-indicator').show();
                
                $.ajax({
                    url: 'save_subject_assignments.php',
                    type: 'POST',
                    data: JSON.stringify({
                        class: classValue,
                        subject: subjectValue,
                        assigned_by: assignedBy,
                        assignments: [{
                            student_id: studentId,
                            student_name: studentName,
                            stream: stream,
                            assigned: isAssigned
                        }]
                    }),
                    contentType: 'application/json',
                    success: function(response) {
                        $('#saving-indicator').hide();
                        try {
                            const data = JSON.parse(response);
                            if (data.status === 'success') {
                                Toastify({
                                    text: isAssigned ? "Student assigned successfully!" : "Student unassigned successfully!",
                                    duration: 2000,
                                    gravity: "top",
                                    position: "right",
                                    style: {
                                        background: "linear-gradient(to right, #00b09b, #96c93d)",
                                    }
                                }).showToast();
                            } else {
                                Toastify({
                                    text: "Error: " + data.message,
                                    duration: 3000,
                                    gravity: "top",
                                    position: "right",
                                    style: {
                                        background: "linear-gradient(to right, #ff5f6d, #ffc371)",
                                    }
                                }).showToast();
                            }
                        } catch (e) {
                            console.error('Parse error:', e, response);
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#saving-indicator').hide();
                        Toastify({
                            text: "Network error occurred",
                            duration: 3000,
                            gravity: "top",
                            position: "right",
                            style: {
                                background: "linear-gradient(to right, #ff5f6d, #ffc371)",
                            }
                        }).showToast();
                    }
                });
            }

            $('.assignment-checkbox').change(function() {
                const checkbox = $(this);
                const studentId = checkbox.data('student-id');
                const studentName = checkbox.data('student-name');
                const stream = checkbox.data('stream');
                const isAssigned = checkbox.is(':checked');
                
                updateStatusCell(checkbox);
                saveAssignment(studentId, studentName, stream, isAssigned);
            });

            $('#select-all-checkbox').change(function() {
                const isChecked = $(this).is(':checked');
                const visibleCheckboxes = $('.assignment-checkbox:visible');
                
                visibleCheckboxes.each(function() {
                    const checkbox = $(this);
                    const currentState = checkbox.is(':checked');
                    
                    if (currentState !== isChecked) {
                        checkbox.prop('checked', isChecked);
                        updateStatusCell(checkbox);
                        
                        const studentId = checkbox.data('student-id');
                        const studentName = checkbox.data('student-name');
                        const stream = checkbox.data('stream');
                        saveAssignment(studentId, studentName, stream, isChecked);
                    }
                });
            });

            $('#assign-all-btn').click(function() {
                $('.assignment-checkbox:visible').each(function() {
                    const checkbox = $(this);
                    if (!checkbox.is(':checked')) {
                        checkbox.prop('checked', true);
                        updateStatusCell(checkbox);
                        
                        const studentId = checkbox.data('student-id');
                        const studentName = checkbox.data('student-name');
                        const stream = checkbox.data('stream');
                        saveAssignment(studentId, studentName, stream, true);
                    }
                });
            });

            $('#unassign-all-btn').click(function() {
                $('.assignment-checkbox:visible').each(function() {
                    const checkbox = $(this);
                    if (checkbox.is(':checked')) {
                        checkbox.prop('checked', false);
                        updateStatusCell(checkbox);
                        
                        const studentId = checkbox.data('student-id');
                        const studentName = checkbox.data('student-name');
                        const stream = checkbox.data('stream');
                        saveAssignment(studentId, studentName, stream, false);
                    }
                });
            });

            $('#assign-by-stream-btn').click(function() {
                const streams = prompt('Enter stream names separated by commas (e.g., East, West):');
                if (streams) {
                    const streamArray = streams.split(',').map(s => s.trim().toLowerCase());
                    $('.assignment-checkbox').each(function() {
                        const checkbox = $(this);
                        const studentStream = checkbox.data('stream').toLowerCase();
                        
                        if (streamArray.includes(studentStream) && !checkbox.is(':checked')) {
                            checkbox.prop('checked', true);
                            updateStatusCell(checkbox);
                            
                            const studentId = checkbox.data('student-id');
                            const studentName = checkbox.data('student-name');
                            const stream = checkbox.data('stream');
                            saveAssignment(studentId, studentName, stream, true);
                        }
                    });
                }
            });

            // Search functionality with proper visibility handling
            $('#student-search').on('input', function() {
                const searchTerm = $(this).val().toLowerCase();
                let visibleCount = 0;
                
                $('.student-row').each(function() {
                    const studentName = $(this).find('.student-name').text().toLowerCase();
                    const stream = $(this).find('.stream-badge').text().toLowerCase();
                    
                    if (studentName.includes(searchTerm) || stream.includes(searchTerm)) {
                        $(this).show();
                        visibleCount++;
                    } else {
                        $(this).hide();
                    }
                });
                
                // Update select all checkbox state based on visible rows
                updateSelectAllState();
            });

            function updateSelectAllState() {
                const visibleCheckboxes = $('.assignment-checkbox:visible');
                const checkedVisible = visibleCheckboxes.filter(':checked');
                
                if (visibleCheckboxes.length === 0) {
                    $('#select-all-checkbox').prop('checked', false).prop('indeterminate', false);
                } else if (checkedVisible.length === 0) {
                    $('#select-all-checkbox').prop('checked', false).prop('indeterminate', false);
                } else if (checkedVisible.length === visibleCheckboxes.length) {
                    $('#select-all-checkbox').prop('checked', true).prop('indeterminate', false);
                } else {
                    $('#select-all-checkbox').prop('checked', false).prop('indeterminate', true);
                }
            }

            // Update select all state on any checkbox change
            $('.assignment-checkbox').on('change', function() {
                updateSelectAllState();
            });

            // Initialize select all state
            updateSelectAllState();
        });
    </script>
</body>
</html>