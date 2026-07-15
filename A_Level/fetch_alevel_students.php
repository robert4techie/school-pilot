<?php
/**
 * fetch_alevel_students.php
 * Returns JSON array of A-Level students matching a search query.
 * Called by the live-search input in student_analysis.php.
 *
 * GET: q — search string (name or student_id)
 */
require_once '../auth.php';
require_once '../conn.php';
header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, 'utf8mb4');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode([]); exit; }

$like = '%' . mysqli_real_escape_string($conn, $q) . '%';

$res = mysqli_query($conn, "
    SELECT student_id, first_name, last_name, current_class, stream, subject_combination
    FROM   students
    WHERE  (current_class LIKE 'Senior Five%' OR current_class LIKE 'Senior Six%')
      AND  (
               first_name    LIKE '{$like}'
            OR last_name     LIKE '{$like}'
            OR student_id    LIKE '{$like}'
            OR CONCAT(first_name,' ',last_name) LIKE '{$like}'
            OR CONCAT(last_name,' ',first_name) LIKE '{$like}'
          )
    ORDER  BY last_name, first_name
    LIMIT  25
");

$out = [];
if ($res) while ($r = mysqli_fetch_assoc($res)) $out[] = $r;
echo json_encode($out, JSON_UNESCAPED_UNICODE);
mysqli_close($conn);
