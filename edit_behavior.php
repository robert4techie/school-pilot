<?php
require_once "conn.php";
require_once "auth.php";

// Initialize variables
$behavior_id = $_GET['id'] ?? 0; // Renamed from $id to $behavior_id for clarity
$error = '';
$success = '';
$behavior = [];

// Get students for dropdown
$students_query = "SELECT student_id, CONCAT(first_name, ' ', last_name) AS name FROM students ORDER BY first_name, last_name";
$students_result = mysqli_query($conn, $students_query);

// Check if ID is valid
if (!$behavior_id) {
    header("Location: student_behaviors.php");
    exit;
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate and sanitize inputs
    $student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
    $class = mysqli_real_escape_string($conn, $_POST['class'] ?? '');
    $stream = mysqli_real_escape_string($conn, $_POST['stream'] ?? '');
    $type = mysqli_real_escape_string($conn, $_POST['type'] ?? '');
    $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
    $date_occurred = mysqli_real_escape_string($conn, $_POST['date_occurred'] ?? '');
    $time_occurred = mysqli_real_escape_string($conn, $_POST['time_occurred'] ?? '');
    $reporter = mysqli_real_escape_string($conn, $_POST['reporter'] ?? '');
    $reporter_name = mysqli_real_escape_string($conn, $_POST['reporter_name'] ?? '');
    $action_taken = mysqli_real_escape_string($conn, $_POST['action_taken'] ?? '');
    $follow_up = mysqli_real_escape_string($conn, $_POST['follow_up'] ?? '');

    // Combine date and time
    $datetime_occurred = $date_occurred . ' ' . $time_occurred;

    // Validate required fields
    if (
        empty($student_id) || empty($class) || empty($stream) || empty($type) || empty($description) ||
        empty($date_occurred) || empty($time_occurred) || empty($reporter)
    ) {
        $error = "Please fill in all required fields.";
    } else {
        // Update the record
        $update_sql = "UPDATE student_behaviors SET 
            student_id = ?, 
            class = ?, 
            stream = ?, 
            type = ?, 
            description = ?, 
            date_occurred = ?, 
            reporter = ?, 
            reporter_name = ?, 
            action_taken = ?, 
            follow_up = ? 
            WHERE id = ?";

        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param(
            $stmt,
            "isssssssssi",
            $student_id,
            $class,
            $stream,
            $type,
            $description,
            $datetime_occurred,
            $reporter,
            $reporter_name,
            $action_taken,
            $follow_up,
            $behavior_id
        );

        if (mysqli_stmt_execute($stmt)) {
            $success = "Behavior record updated successfully!";
            // Refresh behavior data
            $behavior_query = "SELECT * FROM student_behaviors WHERE id = ?";
            $behavior_stmt = mysqli_prepare($conn, $behavior_query);
            mysqli_stmt_bind_param($behavior_stmt, "i", $behavior_id);
            mysqli_stmt_execute($behavior_stmt);
            $behavior_result = mysqli_stmt_get_result($behavior_stmt);
            $behavior = mysqli_fetch_assoc($behavior_result);
        } else {
            $error = "Error updating record: " . mysqli_error($conn);
        }
    }
} else {
    // Fetch existing behavior record
    $behavior_query = "SELECT * FROM student_behaviors WHERE id = ?";
    $behavior_stmt = mysqli_prepare($conn, $behavior_query);
    mysqli_stmt_bind_param($behavior_stmt, "i", $behavior_id);
    mysqli_stmt_execute($behavior_stmt);
    $behavior_result = mysqli_stmt_get_result($behavior_stmt);

    if (mysqli_num_rows($behavior_result) == 0) {
        header("Location: student_behaviors.php");
        exit;
    }

    $behavior = mysqli_fetch_assoc($behavior_result);
}

// Get all classes and streams for dropdowns
$classes_query = "SELECT DISTINCT class FROM student_behaviors ORDER BY class";
$classes_result = mysqli_query($conn, $classes_query);

$streams_query = "SELECT DISTINCT stream FROM student_behaviors ORDER BY stream";
$streams_result = mysqli_query($conn, $streams_query);

// Parse date and time from date_occurred
$date_part = date('Y-m-d', strtotime($behavior['date_occurred']));
$time_part = date('H:i', strtotime($behavior['date_occurred']));
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Behavior Record - School Pilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root {
            --primary: #3c64b1;
            --primary-dark: #2d4d8a;
            --secondary: #6c757d;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --light: #f8f9fa;
            --dark: #343a40;
            --body-bg: #f5f7fa;
            --card-bg: #ffffff;
            --text: #333333;
            --border: #e1e5eb;
            --shadow: rgba(0, 0, 0, 0.05);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
        
            background-color: var(--body-bg);
            color: var(--text);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            margin-top: 25px;
            padding: 20px;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }

        .page-header h1 {
            font-size: 24px;
            font-weight: 600;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
        }

        .page-header h1 i {
            margin-right: 10px;
            color: var(--primary);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            padding: 8px 16px;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.2s;
        }

        .btn i {
            margin-right: 8px;
        }

        .btn:hover {
            background-color: var(--primary-dark);
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background-color: var(--primary);
            color: white;
        }

        /* Cards */
        .card {
            background-color: var(--card-bg);
            border-radius: 6px;
            box-shadow: 0 2px 10px var(--shadow);
            padding: 20px;
            margin-bottom: 20px;
        }

        /* Forms */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-size: 14px;
            transition: border 0.2s;
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(60, 100, 177, 0.1);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        /* Radio groups */
        .radio-group {
            display: flex;
            gap: 20px;
        }

        .radio-group label {
            display: flex;
            align-items: center;
            cursor: pointer;
        }

        .radio-group input[type="radio"] {
            margin-right: 8px;
        }

        .radio-label {
            display: flex;
            align-items: center;
        }

        .radio-label i {
            margin-right: 5px;
        }

        /* Required field marker */
        .required {
            color: var(--danger);
        }

        /* Form buttons */
        .form-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }

        /* Alerts */
        .alert {
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            display: flex;
            align-items: center;
        }

        .alert i {
            margin-right: 10px;
            font-size: 16px;
        }

        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            border-left: 4px solid var(--success);
            color: #155724;
        }

        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            border-left: 4px solid var(--danger);
            color: #721c24;
        }

        /* Table styles */
        .table-container {
            overflow-x: auto;
            margin-bottom: 20px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            background-color: var(--card-bg);
            box-shadow: 0 2px 10px var(--shadow);
            border-radius: 6px;
            overflow: hidden;
        }

        .data-table th,
        .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .data-table th {
            background-color: rgba(60, 100, 177, 0.05);
            font-weight: 600;
            color: var(--primary-dark);
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .data-table tr:hover {
            background-color: rgba(60, 100, 177, 0.02);
        }

        /* Actions column */
        .actions {
            white-space: nowrap;
            display: flex;
            gap: 10px;
        }

        .actions a {
            color: var(--secondary);
            text-decoration: none;
            transition: color 0.2s;
            font-size: 16px;
        }

        .actions a.view-details {
            color: var(--info);
        }

        .actions a.edit-record {
            color: var(--primary);
        }

        .actions a.delete-record {
            color: var(--danger);
        }

        .actions a:hover {
            opacity: 0.8;
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge i {
            margin-right: 5px;
        }

        .badge-positive {
            background-color: rgba(40, 167, 69, 0.1);
            color: #155724;
        }

        .badge-negative {
            background-color: rgba(220, 53, 69, 0.1);
            color: #721c24;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            background-color: var(--card-bg);
            border-radius: 6px;
            box-shadow: 0 2px 10px var(--shadow);
        }

        .empty-state i {
            font-size: 48px;
            color: var(--secondary);
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .empty-state h3 {
            margin-bottom: 10px;
            color: var(--dark);
        }

        .empty-state p {
            color: var(--secondary);
        }

        /* Modals */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            overflow: auto;
        }

        .modal-content {
            background-color: var(--card-bg);
            margin: 10% auto;
            max-width: 600px;
            border-radius: 6px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            animation: modalopen 0.3s;
        }

        @keyframes modalopen {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary-dark);
        }

        .modal-body {
            padding: 20px;
        }

        .close-btn {
            color: var(--secondary);
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
        }

        .close-btn:hover {
            color: var(--dark);
        }

        /* Detail rows in modal */
        .detail-row {
            display: flex;
            margin-bottom: 12px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 12px;
        }

        .detail-row:last-child {
            margin-bottom: 0;
            border-bottom: none;
            padding-bottom: 0;
        }

        .detail-label {
            width: 120px;
            font-weight: 500;
            color: var(--secondary);
        }

        /* Loading indicator */
        .loading {
            text-align: center;
            padding: 20px;
            color: var(--secondary);
            font-style: italic;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 5px;
        }

        .pagination a,
        .pagination span {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s;
        }

        .pagination a {
            background-color: var(--card-bg);
            color: var(--primary);
            border: 1px solid var(--border);
        }

        .pagination a:hover {
            background-color: var(--primary);
            color: white;
        }

        .pagination span.active {
            background-color: var(--primary);
            color: white;
        }

        .pagination span.disabled {
            background-color: var(--light);
            color: var(--secondary);
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* Filter section */
        .filter-section {
            background-color: var(--card-bg);
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 6px;
            box-shadow: 0 2px 10px var(--shadow);
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        /* Responsive adjustments */
        @media screen and (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .data-table {
                font-size: 14px;
            }

            .data-table th,
            .data-table td {
                padding: 10px;
            }

            .actions {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
        }
    </style>
</head>

<body>
    <?php require_once "nav.php" ?>

    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-edit"></i> Edit Behavior Record</h1>
            <a href="view_student_discpline.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to List</a>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <form action="" method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="student_id">Student <span class="required">*</span></label>
                        <select id="student_id" name="student_id" class="form-control" required>
                            <option value="">Select Student</option>
                            <?php while ($student = mysqli_fetch_assoc($students_result)): ?>
                                <option value="<?php echo $student['id']; ?>" <?php echo ($behavior['student_id'] == $student['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($student['name'] . ' (' . $student['student_id'] . ')'); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="class">Class <span class="required">*</span></label>
                        <select id="class" name="class" class="form-control" required>
                            <option value="">Select Class</option>
                            <?php while ($class_row = mysqli_fetch_assoc($classes_result)): ?>
                                <option value="<?php echo htmlspecialchars($class_row['class']); ?>" <?php echo ($behavior['class'] == $class_row['class']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucwords($class_row['class'])); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="stream">Stream <span class="required">*</span></label>
                        <select id="stream" name="stream" class="form-control" required>
                            <option value="">Select Stream</option>
                            <?php while ($stream_row = mysqli_fetch_assoc($streams_result)): ?>
                                <option value="<?php echo htmlspecialchars($stream_row['stream']); ?>" <?php echo ($behavior['stream'] == $stream_row['stream']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst($stream_row['stream'])); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Behavior Type <span class="required">*</span></label>
                        <div class="radio-group">
                            <label>
                                <input type="radio" name="type" value="Positive" <?php echo ($behavior['type'] == 'Positive') ? 'checked' : ''; ?> required>
                                <span class="radio-label"><i class="fas fa-thumbs-up"></i> Positive</span>
                            </label>
                            <label>
                                <input type="radio" name="type" value="Negative" <?php echo ($behavior['type'] == 'Negative') ? 'checked' : ''; ?> required>
                                <span class="radio-label"><i class="fas fa-thumbs-down"></i> Negative</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="description">Description <span class="required">*</span></label>
                    <textarea id="description" name="description" class="form-control" rows="4" required><?php echo htmlspecialchars($behavior['description']); ?></textarea>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="date_occurred">Date <span class="required">*</span></label>
                        <input type="date" id="date_occurred" name="date_occurred" class="form-control" value="<?php echo $date_part; ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="time_occurred">Time <span class="required">*</span></label>
                        <input type="time" id="time_occurred" name="time_occurred" class="form-control" value="<?php echo $time_part; ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="reporter">Reported By <span class="required">*</span></label>
                        <select id="reporter" name="reporter" class="form-control" required>
                            <option value="">Select Reporter</option>
                            <option value="Teacher" <?php echo ($behavior['reporter'] == 'Teacher') ? 'selected' : ''; ?>>Teacher</option>
                            <option value="Principal" <?php echo ($behavior['reporter'] == 'Principal') ? 'selected' : ''; ?>>Principal</option>
                            <option value="Deputy Principal" <?php echo ($behavior['reporter'] == 'Deputy Principal') ? 'selected' : ''; ?>>Deputy Principal</option>
                            <option value="Counselor" <?php echo ($behavior['reporter'] == 'Counselor') ? 'selected' : ''; ?>>Counselor</option>
                            <option value="Other" <?php echo ($behavior['reporter'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <div class="form-group" id="reporter_name_group" style="<?php echo ($behavior['reporter'] != 'Other') ? 'display: none;' : ''; ?>">
                        <label for="reporter_name">Reporter Name</label>
                        <input type="text" id="reporter_name" name="reporter_name" class="form-control" value="<?php echo htmlspecialchars($behavior['reporter_name']); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="action_taken">Action Taken</label>
                    <textarea id="action_taken" name="action_taken" class="form-control" rows="3"><?php echo htmlspecialchars($behavior['action_taken']); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="follow_up">Follow-up Notes</label>
                    <textarea id="follow_up" name="follow_up" class="form-control" rows="3"><?php echo htmlspecialchars($behavior['follow_up']); ?></textarea>
                </div>

                <div class="form-buttons">
                    <button type="submit" class="btn"><i class="fas fa-save"></i> Update Record</button>
                    <a href="view_student_discpline.php" class="btn btn-outline"><i class="fas fa-times"></i> Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Show/hide reporter name field based on reporter selection
            const reporterSelect = document.getElementById('reporter');
            const reporterNameGroup = document.getElementById('reporter_name_group');

            reporterSelect.addEventListener('change', function() {
                if (this.value === 'Other') {
                    reporterNameGroup.style.display = 'block';
                } else {
                    reporterNameGroup.style.display = 'none';
                }
            });
        });
    </script>
</body>

</html>

<?php
// Close database connection
mysqli_close($conn);
?>