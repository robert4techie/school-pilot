<?php
/**
 * GET /get_subjects.php
 *
 * Returns a paginated, searchable, filterable list of subjects as JSON.
 * Requires an authenticated session (enforced by auth.php).
 *
 * Query Parameters:
 *   page    int  Page number (default 1)
 *   limit   int  Items per page (default 10, max 100)
 *   search  str  Full-text search across name, abbreviation, codes, level
 *   level   str  Level filter: "O" | "A" | "O,A" | "" (all)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once 'auth.php';   // Starts session and enforces authentication
require_once 'conn.php';

$response = [
    'success'    => false,
    'message'    => 'Failed to retrieve subjects.',
    'subjects'   => [],
    'totalItems' => 0,
];

try {
    /* ── Input validation ─────────────────────────────────────────────── */
    $page   = max(1, (int)($_GET['page']  ?? 1));
    $limit  = min(100, max(1, (int)($_GET['limit'] ?? 10)));
    $search = trim($_GET['search'] ?? '');
    $offset = ($page - 1) * $limit;

    // Whitelist-validate the level filter to prevent injection via bind_param type confusion
    $allowedLevelFilters = ['', 'O', 'A', 'O,A'];
    $levelFilter = trim($_GET['level'] ?? '');
    if (!in_array($levelFilter, $allowedLevelFilters, true)) {
        $levelFilter = '';
    }

    /* ── Build WHERE clause ───────────────────────────────────────────── */
    $conditions = [];
    $types      = '';
    $params     = [];

    if ($search !== '') {
        $like = "%{$search}%";
        $conditions[] = '(subj_abbr LIKE ? OR subj_name LIKE ? OR code LIKE ? OR codea LIKE ?)';
        $types  .= 'ssss';
        $params  = array_merge($params, [$like, $like, $like, $like]);
    }

    if ($levelFilter !== '') {
        $conditions[] = 'level = ?';
        $types  .= 's';
        $params[] = $levelFilter;
    }

    $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

    /* ── Count total matching rows ────────────────────────────────────── */
    $countSql = "SELECT COUNT(*) AS total FROM subjects{$where}";
    $stmt = $conn->prepare($countSql);
    if ($stmt === false) {
        throw new RuntimeException('Query preparation failed.');
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $totalItems = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    /* ── Fetch page of results ────────────────────────────────────────── */
    $dataSql = "SELECT subj_id, subj_abbr, subj_name, level, code, codea, compulsory
                FROM subjects{$where}
                ORDER BY subj_id DESC
                LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($dataSql);
    if ($stmt === false) {
        throw new RuntimeException('Query preparation failed.');
    }

    $dataTypes  = $types . 'ii';
    $dataParams = array_merge($params, [$limit, $offset]);
    $stmt->bind_param($dataTypes, ...$dataParams);
    $stmt->execute();
    $result = $stmt->get_result();

    $subjects = [];
    while ($row = $result->fetch_assoc()) {
        $subjects[] = [
            'subj_id'    => (int)$row['subj_id'],
            'subj_abbr'  => $row['subj_abbr'],
            'subj_name'  => $row['subj_name'],
            'level'      => $row['level'],
            'code'       => $row['code']  ?? '',
            'codea'      => $row['codea'] ?? '',
            'compulsory' => (int)$row['compulsory'],
        ];
    }
    $stmt->close();

    $response = [
        'success'    => true,
        'message'    => 'OK',
        'subjects'   => $subjects,
        'totalItems' => $totalItems,
    ];

} catch (Throwable $e) {
    error_log('[get_subjects] ' . $e->getMessage());
    $response['message'] = 'An unexpected error occurred. Please try again.';
}

$conn->close();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
