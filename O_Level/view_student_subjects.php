<?php
require_once '../auth.php';
require_once '../conn.php';
require_once 'teacher_auth_check.php';

// Check if this is an AJAX request
$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] == '1';

if ($is_ajax) {
    header('Content-Type: application/json');

    // Get filter values
    $filters = [
        'class' => $_GET['class'] ?? '',
        'stream' => $_GET['stream'] ?? '',
        'gender' => $_GET['gender'] ?? '',
        'subject' => $_GET['subject'] ?? '',
        'search' => $_GET['search'] ?? ''
    ];

    $students = getStudentsWithSubjects($conn, $filters);
    echo json_encode(['status' => 'success', 'students' => $students, 'count' => count($students)]);
    exit;
}

// Get optional subjects only
function getOptionalSubjects($conn)
{
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

// Get students with their optional subjects (Senior One to Four only)
function getStudentsWithSubjects($conn, $filters = [])
{
    $sql = "SELECT 
                s.student_id,
                s.first_name,
                s.last_name,
                s.current_class,
                s.stream,
                s.gender,
                GROUP_CONCAT(DISTINCT ss.subject ORDER BY ss.subject SEPARATOR ', ') as optional_subjects,
                COUNT(DISTINCT ss.subject) as subject_count
            FROM students s
            LEFT JOIN student_subjects ss ON s.student_id = ss.student_id
            WHERE s.status = 'active'
            AND s.current_class IN ('Senior One', 'Senior Two', 'Senior Three', 'Senior Four')";

    $params = [];
    $types = '';

    // Apply filters
    if (!empty($filters['class'])) {
        $sql .= " AND LOWER(s.current_class) = LOWER(?)";
        $params[] = $filters['class'];
        $types .= 's';
    }

    if (!empty($filters['stream'])) {
        $sql .= " AND LOWER(s.stream) = LOWER(?)";
        $params[] = $filters['stream'];
        $types .= 's';
    }

    if (!empty($filters['gender'])) {
        $sql .= " AND LOWER(s.gender) = LOWER(?)";
        $params[] = $filters['gender'];
        $types .= 's';
    }

    if (!empty($filters['subject'])) {
        $sql .= " AND ss.subject = ?";
        $params[] = $filters['subject'];
        $types .= 's';
    }

    if (!empty($filters['search'])) {
        $sql .= " AND (LOWER(s.first_name) LIKE LOWER(?) OR LOWER(s.last_name) LIKE LOWER(?) OR LOWER(s.student_id) LIKE LOWER(?))";
        $search_term = '%' . $filters['search'] . '%';
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= 'sss';
    }

    $sql .= " GROUP BY s.student_id, s.first_name, s.last_name, s.current_class, s.stream, s.gender
              ORDER BY s.current_class, s.stream, s.first_name, s.last_name";

    if (!empty($params)) {
        $stmt = mysqli_prepare($conn, $sql);

        $refs = [];
        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }

        call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $refs));
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    } else {
        $result = mysqli_query($conn, $sql);
    }

    $students = array();
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $students[] = $row;
        }
    }

    if (!empty($params)) {
        mysqli_stmt_close($stmt);
    }

    return $students;
}

include '../nav.php';

$optional_subjects = getOptionalSubjects($conn);
$students = getStudentsWithSubjects($conn);

// Define available options
$available_classes = ['Senior One', 'Senior Two', 'Senior Three', 'Senior Four'];
$available_streams = ['East', 'West', 'South', 'North', 'Arts'];
$available_genders = ['Male', 'Female'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Optional Subjects - SchoolPilot</title>
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
            background: white;
            padding: 25px 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .page-title {
            font-size: 22px;
            font-weight: 600;
            color: var(--primary-color);
            display: flex;
            align-items: center;
        }

        .page-title i {
            margin-right: 12px;
            font-size: 24px;
        }

        .filters-panel {
            background: #e8f5e9;
            padding: 25px 30px;
            border-radius: 8px;
            margin-bottom: 25px;
        }

        .filters-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
            gap: 15px;
            margin-bottom: 0;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 8px;
            font-size: 13px;
            display: flex;
            align-items: center;
        }

        .filter-label i {
            margin-right: 8px;
            color: var(--primary-color);
        }

        .search-input {
            width: 100%;
            height: 42px;
            padding: 8px 15px;
            border: 2px solid #c8e6c9;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(30, 132, 73, 0.1);
        }

        .filter-select {
            width: 100%;
            height: 42px;
            padding: 8px 15px;
            border: 2px solid #c8e6c9;
            border-radius: 6px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-select:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(30, 132, 73, 0.1);
        }

        .results-header {
            background: white;
            padding: 20px 30px;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .results-count {
            font-size: 15px;
            color: var(--primary-dark);
            font-weight: 600;
            display: flex;
            align-items: center;
        }

        .results-count i {
            margin-right: 10px;
            color: var(--primary-color);
        }

        .loading-indicator {
            display: inline-flex;
            align-items: center;
            margin-left: 15px;
            color: var(--primary-color);
        }

        .loading-indicator i {
            margin-right: 6px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .export-buttons {
            display: flex;
            gap: 10px;
        }

        .btnn {
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btnn i {
            margin-right: 8px;
        }

        .btnn-excel {
            background: #1e7e34;
            color: white;
        }

        .btnn-excel:hover {
            background: #155724;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 126, 52, 0.3);
        }

        .btnn-pdf {
            background: #dc3545;
            color: white;
        }

        .btnn-pdf:hover {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }

        .table-container {
            background: white;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            min-height: 200px;
        }

        .students-table {
            width: 100%;
            border-collapse: collapse;
        }

        .students-table thead {
            background: var(--primary-color);
            color: white;
        }

        .students-table th {
            padding: 15px 20px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .students-table td {
            padding: 15px 20px;
            border-bottom: 1px solid #f0f4f9;
            font-size: 13px;
        }

        .students-table tbody tr {
            transition: all 0.2s ease;
        }

        .students-table tbody tr:hover {
            background: #f8fffe;
        }

        .student-name {
            font-weight: 600;
            color: #2c3e50;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-class {
            background: #e3f2fd;
            color: #1976d2;
        }

        .badge-stream {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .badge-gender-male {
            background: #e1f5fe;
            color: #0277bd;
        }

        .badge-gender-female {
            background: #fce4ec;
            color: #c2185b;
        }

        .subjects-list {
            color: var(--primary-dark);
            font-weight: 500;
        }

        .no-subjects {
            color: #95a5a6;
            font-style: italic;
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

        .empty-state h3 {
            font-size: 18px;
            margin-bottom: 10px;
        }

        @media (max-width: 1200px) {
            .filters-row {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 768px) {
            .filters-row {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                gap: 15px;
            }

            .results-header {
                flex-direction: column;
                gap: 15px;
            }

            .export-buttons {
                width: 100%;
            }

            .btnn {
                flex: 1;
            }
        }
    </style>
</head>

<body>
    <?php require_once '../nav.php'; ?>

    <div class="container">
        <div class="page-header">
            <div class="page-title">
                <i class="fas fa-book-reader"></i>
                Student Optional Subjects
            </div>
        </div>

        <div class="filters-panel">
            <div class="filters-row">
                <div class="filter-group">
                    <label class="filter-label">
                        <i class="fas fa-search"></i> Search
                    </label>
                    <input type="text"
                        id="search-input"
                        class="search-input"
                        placeholder="Student name or ID">
                </div>

                <div class="filter-group">
                    <label class="filter-label">
                        <i class="fas fa-venus-mars"></i> Gender
                    </label>
                    <select id="gender-filter" class="filter-select">
                        <option value="">All Genders</option>
                        <?php foreach ($available_genders as $gender): ?>
                            <option value="<?php echo $gender; ?>"><?php echo $gender; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">
                        <i class="fas fa-graduation-cap"></i> Class
                    </label>
                    <select id="class-filter" class="filter-select">
                        <option value="">All Classes</option>
                        <?php foreach ($available_classes as $class): ?>
                            <option value="<?php echo $class; ?>"><?php echo $class; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">
                        <i class="fas fa-layer-group"></i> Stream
                    </label>
                    <select id="stream-filter" class="filter-select">
                        <option value="">All Streams</option>
                        <?php foreach ($available_streams as $stream): ?>
                            <option value="<?php echo $stream; ?>"><?php echo $stream; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">
                        <i class="fas fa-book"></i> Subject
                    </label>
                    <select id="subject-filter" class="filter-select">
                        <option value="">All Subjects</option>
                        <?php foreach ($optional_subjects as $subject): ?>
                            <option value="<?php echo $subject['subj_name']; ?>"><?php echo $subject['subj_name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="results-header">
            <div class="results-count">
                <i class="fas fa-users"></i>
                <span id="results-text">Showing <?php echo count($students); ?> of <?php echo count($students); ?> student(s)</span>
                <span class="loading-indicator" id="loading-indicator" style="display: none;">
                    <i class="fas fa-spinner"></i> Loading...
                </span>
            </div>
            <div class="export-buttons">
                <button class="btnn btnn-excel" id="export-excel">
                    <i class="fas fa-file-excel"></i> Export to Excel
                </button>
                <button class="btnn btnn-pdf" id="export-pdf">
                    <i class="fas fa-file-pdf"></i> Export to PDF
                </button>
            </div>
        </div>

        <div class="table-container">
            <table class="students-table" id="students-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Student ID</th>
                        <th>Gender</th>
                        <th>Class</th>
                        <th>Stream</th>
                        <th>Optional Subjects</th>
                        <th>Count</th>
                    </tr>
                </thead>
                <tbody id="students-tbody">
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td>
                                <span class="student-name">
                                    <?php echo $student['first_name'] . ' ' . $student['last_name']; ?>
                                </span>
                            </td>
                            <td><?php echo $student['student_id']; ?></td>
                            <td>
                                <span class="badge badge-gender-<?php echo strtolower($student['gender']); ?>">
                                    <?php echo ucfirst($student['gender']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-class">
                                    <?php echo $student['current_class']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-stream">
                                    <?php echo $student['stream']; ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($student['optional_subjects'])): ?>
                                    <span class="subjects-list"><?php echo $student['optional_subjects']; ?></span>
                                <?php else: ?>
                                    <span class="no-subjects">No subjects assigned</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $student['subject_count']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="../assets/js/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <script>
        $(document).ready(function() {
            let searchTimeout;

            // Function to load students via AJAX
            function loadStudents() {
                const filters = {
                    search: $('#search-input').val(),
                    gender: $('#gender-filter').val(),
                    class: $('#class-filter').val(),
                    stream: $('#stream-filter').val(),
                    subject: $('#subject-filter').val(),
                    ajax: '1'
                };

                $('#loading-indicator').show();

                $.ajax({
                    url: window.location.pathname,
                    type: 'GET',
                    data: filters,
                    dataType: 'json',
                    success: function(response) {
                        $('#loading-indicator').hide();

                        if (response.status === 'success') {
                            updateTable(response.students);
                            updateResultsCount(response.count, response.count);
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#loading-indicator').hide();
                        console.error('Error loading students:', error);

                        Toastify({
                            text: "Error loading students. Please try again.",
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

            // Function to update the table
            function updateTable(students) {
                const tbody = $('#students-tbody');
                tbody.empty();

                if (students.length === 0) {
                    tbody.append(`
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <i class="fas fa-search"></i>
                                    <h3>No students found</h3>
                                    <p>Try adjusting your filters or search criteria.</p>
                                </div>
                            </td>
                        </tr>
                    `);
                } else {
                    students.forEach(function(student) {
                        const genderClass = student.gender.toLowerCase();
                        const subjectsDisplay = student.optional_subjects ?
                            `<span class="subjects-list">${student.optional_subjects}</span>` :
                            `<span class="no-subjects">No subjects assigned</span>`;

                        tbody.append(`
                            <tr>
                                <td>
                                    <span class="student-name">${student.first_name} ${student.last_name}</span>
                                </td>
                                <td>${student.student_id}</td>
                                <td>
                                    <span class="badge badge-gender-${genderClass}">${student.gender}</span>
                                </td>
                                <td>
                                    <span class="badge badge-class">${student.current_class}</span>
                                </td>
                                <td>
                                    <span class="badge badge-stream">${student.stream}</span>
                                </td>
                                <td>${subjectsDisplay}</td>
                                <td>${student.subject_count}</td>
                            </tr>
                        `);
                    });
                }
            }

            // Function to update results count
            function updateResultsCount(showing, total) {
                $('#results-text').text(`Showing ${showing} of ${total} student(s)`);
            }

            // Search input with debounce
            $('#search-input').on('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    loadStudents();
                }, 500);
            });

            // Filter dropdowns - instant change
            $('.filter-select').on('change', function() {
                loadStudents();
            });

            // Export to Excel
            $('#export-excel').click(function() {
                const table = document.getElementById('students-table');
                const wb = XLSX.utils.table_to_book(table, {
                    sheet: "Students"
                });
                XLSX.writeFile(wb, 'student_optional_subjects.xlsx');

                Toastify({
                    text: "Excel file exported successfully!",
                    duration: 3000,
                    gravity: "top",
                    position: "right",
                    style: {
                        background: "linear-gradient(to right, #1e7e34, #28a745)",
                    }
                }).showToast();
            });

            // Export to PDF
            $('#export-pdf').click(function() {
                const {
                    jsPDF
                } = window.jspdf;
                const doc = new jsPDF('l', 'mm', 'a4');

                doc.setFontSize(16);
                doc.text('Student Optional Subjects Report', 14, 15);

                doc.setFontSize(10);
                doc.text('Generated: ' + new Date().toLocaleString(), 14, 22);

                const tableData = [];
                const headers = ['Student Name', 'Student ID', 'Gender', 'Class', 'Stream', 'Optional Subjects', 'Count'];

                $('#students-table tbody tr').each(function() {
                    const row = [];
                    $(this).find('td').each(function(index) {
                        if (index === 2 || index === 3 || index === 4) {
                            row.push($(this).find('.badge').text().trim());
                        } else if (index === 5) {
                            const subjectText = $(this).find('.subjects-list').text().trim();
                            row.push(subjectText || $(this).find('.no-subjects').text().trim());
                        } else {
                            row.push($(this).text().trim());
                        }
                    });

                    if (row.length === 7) {
                        tableData.push(row);
                    }
                });

                doc.autoTable({
                    head: [headers],
                    body: tableData,
                    startY: 28,
                    styles: {
                        fontSize: 8
                    },
                    headStyles: {
                        fillColor: [30, 132, 73]
                    }
                });

                doc.save('student_optional_subjects.pdf');

                Toastify({
                    text: "PDF file exported successfully!",
                    duration: 3000,
                    gravity: "top",
                    position: "right",
                    style: {
                        background: "linear-gradient(to right, #dc3545, #c82333)",
                    }
                }).showToast();
            });
        });
    </script>
</body>

</html>