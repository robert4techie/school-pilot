<?php
/**
 * get_alevel_exam_sets.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Returns JSON array of exam sets available for a given class / term / year.
 * Called by the filter panel in alevel_analysis.php via fetch().
 *
 * GET Parameters:
 *   class   — e.g. "Senior Five"
 *   term    — 1 | 2 | 3
 *   year    — e.g. 2026
 *
 * Response: JSON array of { id, exam_set, label } objects, or { error: "..." }
 */
require_once '../auth.php';
require_once '../conn.php';
header('Content-Type: application/json; charset=utf-8');

mysqli_set_charset($conn, 'utf8mb4');
mysqli_query($conn, "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// ── Input validation ──────────────────────────────────────────────────────────
if (!isset($_GET['class'], $_GET['term'], $_GET['year'])) {
    echo json_encode(['error' => 'Missing required parameters.']);
    exit;
}

$class = mysqli_real_escape_string($conn, trim($_GET['class']));
$term  = max(1, min(3, (int) $_GET['term']));
$year  = (int) $_GET['year'];

// ── Build marks table name ────────────────────────────────────────────────────
$termStr   = [1 => 'i', 2 => 'ii', 3 => 'iii'][$term];
$tableName = "{$year}_{$termStr}_alevel";

// ── Check table exists ────────────────────────────────────────────────────────
$tableCheck = mysqli_query(
    $conn,
    "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $tableName) . "'"
);
if (!$tableCheck || mysqli_num_rows($tableCheck) === 0) {
    echo json_encode(['error' => "No marks table found for Term {$term}, {$year}."]);
    exit;
}

// ── Get distinct exam_type values used in this class/term ─────────────────────
// We JOIN against students so we only return exam sets that actually have
// marks for this class — not every exam set in the school system.
$res = mysqli_query($conn, "
    SELECT DISTINCT m.exam_type
    FROM   `{$tableName}` m
    JOIN   students s
           ON s.student_id COLLATE utf8mb4_unicode_ci
            = m.student_id COLLATE utf8mb4_unicode_ci
    WHERE  s.current_class = '{$class}'
    ORDER  BY m.exam_type
");

if (!$res || mysqli_num_rows($res) === 0) {
    echo json_encode(['error' => "No exam sets found for class '{$class}' in Term {$term}, {$year}."]);
    exit;
}

$examTypeIds = [];
while ($r = mysqli_fetch_assoc($res)) {
    $examTypeIds[] = (int) $r['exam_type'];
}

// ── Fetch exam set metadata ───────────────────────────────────────────────────
$ids_in = implode(',', $examTypeIds);
$res2 = mysqli_query($conn, "
    SELECT id,
           exam_set,
           description,
           exam_mark,
           classes
    FROM   exam_sets
    WHERE  id IN ({$ids_in})
    ORDER  BY id
");

$sets = [];
if ($res2) {
    while ($r = mysqli_fetch_assoc($res2)) {
        $sets[] = [
            'id'        => (int) $r['id'],
            'exam_set'  => $r['exam_set'],
            'label'     => $r['exam_set'] . ' — ' . $r['description'],
            'exam_mark' => (int) $r['exam_mark'],
            'classes'   => $r['classes'],
        ];
    }
}

// ── Fallback: if exam_sets table is missing or empty, return raw IDs ─────────
if (empty($sets)) {
    foreach ($examTypeIds as $eid) {
        $sets[] = [
            'id'        => $eid,
            'exam_set'  => (string) $eid,
            'label'     => "Exam Set {$eid}",
            'exam_mark' => 100,
            'classes'   => '',
        ];
    }
}

echo json_encode($sets, JSON_UNESCAPED_UNICODE);
mysqli_close($conn);
