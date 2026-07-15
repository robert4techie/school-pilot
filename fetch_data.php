    <?php
    // Backend API endpoint for comparative analysis data.

    require_once 'conn.php';

    header('Content-Type: application/json');

    // Helper function to get the dynamic marks table name
    function getMarksTableName($year, $term) {
        $romans = [1 => 'i', 2 => 'ii', 3 => 'iii'];
        $term_roman = $romans[$term] ?? 'i';
        return "{$year}_{$term_roman}_olevel";
    }

    // Check for required parameters
    if (!isset($_GET['class']) || !isset($_GET['subject']) || !isset($_GET['year']) || !isset($_GET['term'])) {
        echo json_encode(['error' => 'Missing required parameters for comparative analysis.']);
        exit;
    }

    $class = $_GET['class'];
    $subject = $_GET['subject'];
    $year = $_GET['year'];
    $term = $_GET['term'];

    $data = [
        'classAverages' => [],
        'genderAverages' => []
    ];
    
    $tableName = getMarksTableName($year, $term);
    
    // Check if the dynamic marks table exists
    $checkTable = mysqli_query($conn, "SHOW TABLES LIKE '{$tableName}'");
    if (mysqli_num_rows($checkTable) == 0) {
        echo json_encode(['error' => "The marks table for Term {$term}, {$year} does not exist."]);
        exit;
    }

    // Class Performance by Stream (assuming streams exist in the marks table)
    $sqlClass = "
        SELECT 
            t1.stream, 
            AVG(t1.marks) AS avg_score
        FROM `{$tableName}` t1
        WHERE t1.class = ? AND t1.subject = ?
        GROUP BY t1.stream
    ";
    $stmtClass = mysqli_prepare($conn, $sqlClass);
    if ($stmtClass) {
        mysqli_stmt_bind_param($stmtClass, "ss", $class, $subject);
        mysqli_stmt_execute($stmtClass);
        $result = mysqli_stmt_get_result($stmtClass);
        while ($row = mysqli_fetch_assoc($result)) {
            $data['classAverages'][$row['stream']] = (float)$row['avg_score'];
        }
    }

    // Gender-based Performance
    $sqlGender = "
        SELECT 
            t2.gender, 
            AVG(t1.marks) AS avg_score
        FROM `{$tableName}` t1
        JOIN students t2 ON t1.student_id = t2.student_id
        WHERE t1.class = ? AND t1.subject = ?
        GROUP BY t2.gender
    ";
    $stmtGender = mysqli_prepare($conn, $sqlGender);
    if ($stmtGender) {
        mysqli_stmt_bind_param($stmtGender, "ss", $class, $subject);
        mysqli_stmt_execute($stmtGender);
        $result = mysqli_stmt_get_result($stmtGender);
        while ($row = mysqli_fetch_assoc($result)) {
            $data['genderAverages'][ucfirst($row['gender'])] = (float)$row['avg_score'];
        }
    }

    echo json_encode($data);

    mysqli_close($conn);
    ?>
    
