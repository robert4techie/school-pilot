<?php
/**
 * SchoolPilot — Bulk Student Photo Import Tool
 * ─────────────────────────────────────────────
 * Drop this file into your SchoolPilot root (same level as auth.php / conn.php).
 * Place student photos inside the folder defined by PHOTO_STAGING_DIR below.
 * Photos may be named by Student ID  →  0U-STD-2026-0194.jpg   (best / most reliable)
 *                      or by Name    →  BESIGYE_AARON.jpg  /  AARON_BESIGYE.jpg  (fuzzy)
 *
 * Workflow
 * ────────
 * 1. Tool scans the staging folder and matches each photo to a student record.
 * 2. Results are shown in a colour-coded preview table (dry-run — nothing is written yet).
 * 3. You review, then click "Confirm & Import" to apply only the high-confidence matches.
 * 4. After import the matched photos are moved to the live photos directory.
 */

// ── Configuration ─────────────────────────────────────────────────────────────

/** Folder (relative to this file) where you drop the bulk photos before import. */
const PHOTO_STAGING_DIR = 'uploads/student_photos_staging/';

/** Live student photos directory (must match what the rest of the system uses). */
const PHOTO_LIVE_DIR    = 'uploads/profile_photos/';

/** Base URL of the site — used so profile_photo is stored as a full URL, matching existing records. */
const SITE_BASE_URL     = 'https://ou-schoolpilot.org';
const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

/** Confidence threshold (0–100) below which a match is flagged for manual review. */
const CONFIRM_THRESHOLD = 75;

// ── Bootstrap ─────────────────────────────────────────────────────────────────

require_once 'auth.php';
require_once 'conn.php';

// Only developers / school leaders may run this tool.
$allowed_roles = ['developer', 'super user', 'school leader'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles, true)) {
    http_response_code(403);
    die('Access denied.');
}

// Create directories if they do not exist.
foreach ([PHOTO_STAGING_DIR, PHOTO_LIVE_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Normalise a string for comparison:
 * lower-case, strip punctuation, collapse spaces.
 */
function normalise(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);
    return preg_replace('/\s+/', ' ', $s);
}

/**
 * Extract the base name tokens from a filename (no extension, split on
 * underscores, hyphens, spaces, and dots).
 */
function filename_tokens(string $filename): array
{
    $base   = pathinfo($filename, PATHINFO_FILENAME);
    $tokens = preg_split('/[\s_\-\.]+/', $base);
    return array_filter(array_map('trim', $tokens));
}

/**
 * Build every permutation of an array up to a given length.
 * Used to try all orderings of name tokens.
 */
function permutations(array $items): array
{
    if (count($items) <= 1) return [$items];
    $result = [];
    foreach ($items as $i => $item) {
        $rest = $items;
        array_splice($rest, $i, 1);
        foreach (permutations($rest) as $p) {
            $result[] = array_merge([$item], $p);
        }
    }
    return $result;
}

/**
 * Score how well a photo filename matches a student record (0–100).
 *
 * Strategy (in priority order):
 *  1. Exact student_id match in filename  → 100
 *  2. All name tokens present in full name → scaled by token coverage
 *  3. Partial / fuzzy similarity           → lower score
 */
function match_score(array $tokens, string $student_id, string $first_name, string $last_name): int
{
    $norm_tokens = array_map('normalise', $tokens);

    // ── 1. Student-ID match ─────────────────────────────────────────────────
    // IDs in DB use hyphens (0U-STD-2026-0194) but filenames may use underscores.
    // Normalise both sides by stripping all separators before comparing.
    $sid_stripped = preg_replace('/[\-_\s]/', '', strtolower($student_id));
    foreach ($norm_tokens as $t) {
        $t_stripped = preg_replace('/[\-_\s]/', '', $t);
        if ($t_stripped === $sid_stripped) return 100;
    }
    // Also check if the whole joined base contains the stripped ID.
    $joined = preg_replace('/[\-_\s]/', '', implode('', $norm_tokens));
    if (strpos($joined, $sid_stripped) !== false) return 100;

    // ── 2. Name-token coverage ──────────────────────────────────────────────
    $full_name_norm   = normalise($first_name . ' ' . $last_name);
    $full_name_tokens = explode(' ', $full_name_norm);

    $matched = 0;
    foreach ($norm_tokens as $t) {
        if (in_array($t, $full_name_tokens, true)) {
            $matched++;
        }
    }

    $total_name_parts = count($full_name_tokens);
    if ($total_name_parts === 0) return 0;

    $coverage = $matched / $total_name_parts;

    // Bonus: all tokens from the filename are accounted for in the name.
    $unaccounted = count($norm_tokens) - $matched;
    $precision   = $unaccounted === 0 ? 1.0 : max(0, 1 - ($unaccounted / count($norm_tokens)));

    $score = (int) round(($coverage * 0.7 + $precision * 0.3) * 95);

    // ── 3. Fallback: trigram similarity on the full joined string ───────────
    if ($score < 40) {
        $filename_str = implode(' ', $norm_tokens);
        similar_text($filename_str, $full_name_norm, $pct);
        $score = max($score, (int) round($pct * 0.6)); // cap at 60 for fuzzy
    }

    return min($score, 99); // 100 is reserved for ID matches
}

// ── Load all students (without photos) from the database ──────────────────────

function load_students_without_photos(mysqli $conn): array
{
    $sql = "
        SELECT student_id, first_name, last_name, current_class, stream, section
        FROM   students
        WHERE  (profile_photo IS NULL OR profile_photo = '')
        ORDER  BY last_name ASC, first_name ASC
    ";
    $result = $conn->query($sql);
    if (!$result) {
        throw new RuntimeException('DB query failed: ' . $conn->error);
    }
    $rows = [];
    while ($r = $result->fetch_assoc()) {
        $rows[$r['student_id']] = $r;
    }
    return $rows;
}

// ── Scan the staging directory and match photos to students ──────────────────

function build_matches(array $students, string $staging_dir): array
{
    $matches    = [];   // matched:    photo → student (high confidence)
    $ambiguous  = [];   // ambiguous:  photo → multiple candidates
    $unmatched  = [];   // no student found for this photo

    $files = glob($staging_dir . '*');
    if ($files === false) return compact('matches', 'ambiguous', 'unmatched');

    foreach ($files as $filepath) {
        if (!is_file($filepath)) continue;

        $mime = mime_content_type($filepath);
        if (!in_array($mime, ALLOWED_MIME, true)) continue;   // skip non-images

        $filename = basename($filepath);
        $tokens   = filename_tokens($filename);

        if (empty($tokens)) {
            $unmatched[] = ['file' => $filename, 'reason' => 'Could not parse filename.'];
            continue;
        }

        // Score every student.
        $scores = [];
        foreach ($students as $sid => $student) {
            $s = match_score($tokens, $sid, $student['first_name'], $student['last_name']);
            if ($s > 0) {
                $scores[$sid] = $s;
            }
        }

        if (empty($scores)) {
            $unmatched[] = ['file' => $filename, 'reason' => 'No student name match found.'];
            continue;
        }

        arsort($scores);
        $top_sid   = array_key_first($scores);
        $top_score = $scores[$top_sid];

        // Check if there are multiple students with the same top score.
        $top_candidates = array_filter($scores, fn($s) => $s === $top_score);

        if (count($top_candidates) > 1) {
            // Multiple students share the top score → flag for manual review.
            $candidates = [];
            foreach ($top_candidates as $sid => $score) {
                $candidates[] = [
                    'student_id' => $sid,
                    'name'       => $students[$sid]['first_name'] . ' ' . $students[$sid]['last_name'],
                    'class'      => $students[$sid]['current_class'] . ' ' . $students[$sid]['stream'],
                    'score'      => $score,
                ];
            }
            $ambiguous[] = [
                'file'       => $filename,
                'candidates' => $candidates,
            ];
            continue;
        }

        $student = $students[$top_sid];

        if ($top_score >= CONFIRM_THRESHOLD) {
            $matches[] = [
                'file'       => $filename,
                'filepath'   => $filepath,
                'student_id' => $top_sid,
                'name'       => $student['first_name'] . ' ' . $student['last_name'],
                'class'      => $student['current_class'] . ' ' . $student['stream'],
                'score'      => $top_score,
                'status'     => 'ready',
            ];
        } else {
            // Low confidence — still show but mark as needs review.
            $matches[] = [
                'file'       => $filename,
                'filepath'   => $filepath,
                'student_id' => $top_sid,
                'name'       => $student['first_name'] . ' ' . $student['last_name'],
                'class'      => $student['current_class'] . ' ' . $student['stream'],
                'score'      => $top_score,
                'status'     => 'low_confidence',
            ];
        }
    }

    return compact('matches', 'ambiguous', 'unmatched');
}

// ── Handle the AJAX confirm action ────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_import') {

    header('Content-Type: application/json');

    // CSRF check.
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }

    $selected = $_POST['selected'] ?? [];   // array of filenames the user confirmed
    if (empty($selected) || !is_array($selected)) {
        echo json_encode(['success' => false, 'error' => 'No photos selected.']);
        exit;
    }

    try {
        $students = load_students_without_photos($conn);
        $result   = build_matches($students, PHOTO_STAGING_DIR);
        $all_matches = $result['matches'];

        // Index by filename for O(1) lookup.
        $by_file = [];
        foreach ($all_matches as $m) {
            $by_file[$m['file']] = $m;
        }

        $imported = 0;
        $errors   = [];

        $conn->begin_transaction();

        foreach ($selected as $filename) {
            $filename = basename($filename);   // strip any path traversal
            if (!isset($by_file[$filename])) {
                $errors[] = "{$filename}: Not found in match results.";
                continue;
            }

            $match    = $by_file[$filename];
            $src_path = PHOTO_STAGING_DIR . $filename;

            if (!is_file($src_path)) {
                $errors[] = "{$filename}: Source file missing.";
                continue;
            }

            // Validate mime type again (defence in depth).
            $mime = mime_content_type($src_path);
            if (!in_array($mime, ALLOWED_MIME, true)) {
                $errors[] = "{$filename}: Invalid file type ({$mime}).";
                continue;
            }

            // Build a canonical target filename matching the system convention:
            // {student_id_underscored}_{random_hex}.{ext}
            // e.g. 0U_STD_2026_0194_69e1eb52e3716.jpg
            $ext         = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $sid_clean   = str_replace('-', '_', $match['student_id']);
            $random_hex  = bin2hex(random_bytes(7));   // 14-char hex suffix
            $target_name = $sid_clean . '_' . $random_hex . '.' . $ext;
            $dest_path   = PHOTO_LIVE_DIR . $target_name;

            // Full URL stored in DB — matches how save_student.php saves photos.
            $db_value = SITE_BASE_URL . '/' . PHOTO_LIVE_DIR . $target_name;

            if (!rename($src_path, $dest_path)) {
                $errors[] = "{$filename}: Could not move to live directory.";
                continue;
            }

            // Update the students table with the full URL.
            $stmt = $conn->prepare(
                "UPDATE students SET profile_photo = ? WHERE student_id = ? AND (profile_photo IS NULL OR profile_photo = '')"
            );
            $stmt->bind_param('ss', $db_value, $match['student_id']);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                // Either already has a photo or student_id not found — roll back this file.
                rename($dest_path, $src_path);
                $errors[] = "{$filename}: Student not updated (may already have a photo).";
            } else {
                $imported++;
            }
            $stmt->close();
        }

        if (empty($errors)) {
            $conn->commit();
        } else {
            // Partial success: commit what succeeded.
            $conn->commit();
        }

        echo json_encode([
            'success'  => true,
            'imported' => $imported,
            'errors'   => $errors,
        ]);

    } catch (Throwable $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } finally {
        $conn->close();
    }
    exit;
}

// ── Build the preview (dry-run) ───────────────────────────────────────────────

$error_msg = null;
$result    = ['matches' => [], 'ambiguous' => [], 'unmatched' => []];

try {
    $students = load_students_without_photos($conn);
    $result   = build_matches($students, PHOTO_STAGING_DIR);
} catch (Throwable $e) {
    $error_msg = $e->getMessage();
} finally {
    $conn->close();
}

// CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$ready_count     = count(array_filter($result['matches'], fn($m) => $m['status'] === 'ready'));
$low_conf_count  = count(array_filter($result['matches'], fn($m) => $m['status'] === 'low_confidence'));
$ambiguous_count = count($result['ambiguous']);
$unmatched_count = count($result['unmatched']);
$staging_empty   = empty($result['matches']) && empty($result['ambiguous']) && empty($result['unmatched']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Photo Import — SchoolPilot</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary:     #1a5276;
            --primary-lt:  #1f618d;
            --success:     #1e8449;
            --success-bg:  #eafaf1;
            --warn:        #b7770d;
            --warn-bg:     #fef9e7;
            --danger:      #c0392b;
            --danger-bg:   #fdedec;
            --neutral:     #5d6d7e;
            --neutral-bg:  #f4f6f7;
            --border:      #d5d8dc;
            --radius:      6px;
            --font:        'Segoe UI', system-ui, sans-serif;
        }

        body {
            font-family: var(--font);
            background: #eef2f5;
            color: #1c2833;
            min-height: 100vh;
        }

        /* ── Layout ─────────────────────────────────────────── */
        .page-wrap { max-width: 1100px; margin: 0 auto; padding: 32px 16px 64px; }

        .page-header {
            background: var(--primary);
            color: #fff;
            padding: 20px 28px;
            border-radius: var(--radius);
            margin-bottom: 28px;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .page-header h1 { font-size: 1.4rem; font-weight: 600; }
        .page-header p  { font-size: .85rem; opacity: .8; margin-top: 4px; }
        .page-header .icon { font-size: 2rem; line-height: 1; }

        /* ── Cards ──────────────────────────────────────────── */
        .card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 20px;
        }
        .card-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ── Summary bar ────────────────────────────────────── */
        .summary {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .stat-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 50px;
            font-size: .9rem;
            font-weight: 500;
            border: 1.5px solid transparent;
        }
        .stat-pill.success { background: var(--success-bg); color: var(--success); border-color: #a9dfbf; }
        .stat-pill.warn    { background: var(--warn-bg);    color: var(--warn);    border-color: #f9e79f; }
        .stat-pill.danger  { background: var(--danger-bg);  color: var(--danger);  border-color: #f1948a; }
        .stat-pill.neutral { background: var(--neutral-bg); color: var(--neutral); border-color: #d5d8dc; }
        .stat-pill .num    { font-size: 1.3rem; font-weight: 700; }

        /* ── Table ──────────────────────────────────────────── */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        thead th {
            background: var(--neutral-bg);
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid var(--border);
            white-space: nowrap;
        }
        tbody td { padding: 10px 12px; border-bottom: 1px solid #eaecee; vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #f8f9fa; }

        .badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 50px;
            font-size: .75rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .badge-success  { background: var(--success-bg); color: var(--success); }
        .badge-warn     { background: var(--warn-bg);    color: var(--warn); }
        .badge-danger   { background: var(--danger-bg);  color: var(--danger); }
        .badge-neutral  { background: var(--neutral-bg); color: var(--neutral); }

        /* Score bar */
        .score-bar { display: flex; align-items: center; gap: 8px; }
        .score-track {
            flex: 1; height: 8px; background: #e8eaed; border-radius: 99px; overflow: hidden;
            min-width: 60px;
        }
        .score-fill { height: 100%; border-radius: 99px; transition: width .3s; }
        .score-num  { font-weight: 600; font-size: .8rem; min-width: 32px; text-align: right; }

        /* Checkbox column */
        input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; accent-color: var(--primary); }

        /* ── Buttons ────────────────────────────────────────── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 22px;
            border-radius: var(--radius);
            font-size: .9rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: opacity .15s, transform .1s;
        }
        .btn:active { transform: scale(.98); }
        .btn:disabled { opacity: .45; cursor: not-allowed; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover:not(:disabled) { background: var(--primary-lt); }
        .btn-success { background: var(--success); color: #fff; }
        .btn-success:hover:not(:disabled) { background: #1a7a3c; }
        .btn-ghost  { background: transparent; color: var(--primary); border: 1.5px solid var(--border); }
        .btn-ghost:hover { background: var(--neutral-bg); }

        .action-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding-top: 16px;
            border-top: 1px solid var(--border);
            margin-top: 16px;
        }
        .action-bar .select-all-wrap { margin-right: auto; display: flex; align-items: center; gap: 8px; font-size: .875rem; }

        /* ── Alerts ─────────────────────────────────────────── */
        .alert {
            padding: 14px 18px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            font-size: .875rem;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .alert-info    { background: #eaf3fb; color: #1a5276; border-left: 4px solid #2980b9; }
        .alert-success { background: var(--success-bg); color: var(--success); border-left: 4px solid var(--success); }
        .alert-warn    { background: var(--warn-bg);    color: var(--warn);    border-left: 4px solid #f0b429; }
        .alert-danger  { background: var(--danger-bg);  color: var(--danger);  border-left: 4px solid var(--danger); }

        /* ── How-to instructions ────────────────────────────── */
        .how-to ol  { padding-left: 20px; }
        .how-to li  { padding: 4px 0; font-size: .875rem; line-height: 1.6; }
        .how-to code {
            background: #eef2f5;
            padding: 1px 6px;
            border-radius: 4px;
            font-family: 'Cascadia Code', 'Consolas', monospace;
            font-size: .82rem;
            color: var(--primary);
        }

        /* ── Result overlay ─────────────────────────────────── */
        #result-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 100;
            align-items: center;
            justify-content: center;
        }
        #result-overlay.visible { display: flex; }
        .result-box {
            background: #fff;
            border-radius: var(--radius);
            padding: 32px 40px;
            max-width: 480px;
            width: 90%;
            text-align: center;
        }
        .result-box .icon-big { font-size: 3.5rem; line-height: 1; margin-bottom: 12px; }
        .result-box h2  { font-size: 1.3rem; font-weight: 700; margin-bottom: 8px; }
        .result-box p   { font-size: .9rem; color: var(--neutral); margin-bottom: 20px; }
        .result-box ul  { text-align: left; font-size: .85rem; margin-bottom: 16px; padding-left: 18px; }
        .result-box li  { margin-bottom: 4px; color: var(--danger); }

        .spinner {
            display: inline-block; width: 20px; height: 20px;
            border: 3px solid rgba(255,255,255,.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div class="page-wrap">

    <!-- Header -->
    <div class="page-header">
        <div class="icon">🖼️</div>
        <div>
            <h1>Bulk Student Photo Import</h1>
            <p>Review matches below, select the ones to import, then click Confirm &amp; Import.</p>
        </div>
    </div>

    <?php if ($error_msg): ?>
    <div class="alert alert-danger">⚠️ Database error: <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <!-- How-to card -->
    <div class="card how-to">
        <div class="card-title">📂 How to use this tool</div>
        <ol>
            <li>Upload your student photos into the staging folder on the server: <code><?= htmlspecialchars(realpath(PHOTO_STAGING_DIR) ?: PHOTO_STAGING_DIR) ?></code></li>
            <li>Name each photo after the <strong>Student ID</strong> (most reliable) — e.g. <code>0U-STD-2026-0194.jpg</code> or <code>0U_STD_2026_0194.jpg</code> (hyphens or underscores both work)<br>
                — OR after the student's full name with spaces — e.g. <code>BESIGYE AARON BENTEZ.jpg</code> or <code>BESIGYE AARON.jpg</code></li>
            <li>Refresh this page to see the auto-matched preview below.</li>
            <li>Tick the photos you want to apply, then click <strong>Confirm &amp; Import</strong>.</li>
            <li>Imported photos are automatically renamed to <code>{student_id}.jpg</code> and moved to the live folder.</li>
        </ol>
    </div>

    <?php if ($staging_empty && !$error_msg): ?>
    <div class="alert alert-info">
        ℹ️ The staging folder is empty or contains no image files.
        Upload photos there and refresh this page.
    </div>
    <?php else: ?>

    <!-- Summary pills -->
    <div class="summary">
        <div class="stat-pill success">
            <span class="num"><?= $ready_count ?></span> Ready to import
        </div>
        <div class="stat-pill warn">
            <span class="num"><?= $low_conf_count ?></span> Low confidence
        </div>
        <div class="stat-pill warn">
            <span class="num"><?= $ambiguous_count ?></span> Ambiguous (manual review)
        </div>
        <div class="stat-pill danger">
            <span class="num"><?= $unmatched_count ?></span> Unmatched
        </div>
    </div>

    <!-- Main match table -->
    <?php if (!empty($result['matches'])): ?>
    <div class="card">
        <div class="card-title">✅ Matched Photos</div>

        <div class="table-wrap">
            <table id="match-table">
                <thead>
                    <tr>
                        <th style="width:40px"></th>
                        <th>Photo File</th>
                        <th>Matched Student</th>
                        <th>Student ID</th>
                        <th>Class</th>
                        <th>Confidence</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($result['matches'] as $m):
                    $score     = $m['score'];
                    $is_ready  = $m['status'] === 'ready';
                    $fill_clr  = $score >= 90 ? '#1e8449' : ($score >= CONFIRM_THRESHOLD ? '#f0b429' : '#e74c3c');
                ?>
                <tr data-status="<?= $m['status'] ?>">
                    <td>
                        <input
                            type="checkbox"
                            name="selected[]"
                            value="<?= htmlspecialchars($m['file']) ?>"
                            <?= $is_ready ? 'checked' : '' ?>
                            class="row-check"
                        >
                    </td>
                    <td style="font-family:monospace;font-size:.82rem;word-break:break-all;">
                        <?= htmlspecialchars($m['file']) ?>
                    </td>
                    <td style="font-weight:600;"><?= htmlspecialchars($m['name']) ?></td>
                    <td style="font-family:monospace;font-size:.82rem;"><?= htmlspecialchars($m['student_id']) ?></td>
                    <td><?= htmlspecialchars($m['class']) ?></td>
                    <td>
                        <div class="score-bar">
                            <div class="score-track">
                                <div class="score-fill" style="width:<?= $score ?>%;background:<?= $fill_clr ?>;"></div>
                            </div>
                            <span class="score-num" style="color:<?= $fill_clr ?>;"><?= $score ?>%</span>
                        </div>
                    </td>
                    <td>
                        <?php if ($is_ready): ?>
                            <span class="badge badge-success">Ready</span>
                        <?php else: ?>
                            <span class="badge badge-warn">Low confidence</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="action-bar">
            <label class="select-all-wrap">
                <input type="checkbox" id="select-all" checked>
                <span>Select / deselect all ready matches</span>
            </label>
            <button class="btn btn-success" id="confirm-btn" onclick="confirmImport()">
                ✅ Confirm &amp; Import Selected
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Ambiguous matches -->
    <?php if (!empty($result['ambiguous'])): ?>
    <div class="card">
        <div class="card-title">⚠️ Ambiguous Matches — Manual Review Required</div>
        <div class="alert alert-warn" style="margin-bottom:16px;">
            These photos matched multiple students with the same score. Rename the file using the exact Student ID and re-upload.
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Photo File</th>
                        <th>Possible Students</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($result['ambiguous'] as $a): ?>
                <tr>
                    <td style="font-family:monospace;font-size:.82rem;"><?= htmlspecialchars($a['file']) ?></td>
                    <td>
                        <?php foreach ($a['candidates'] as $c): ?>
                            <div>
                                <strong><?= htmlspecialchars($c['name']) ?></strong>
                                <span class="badge badge-neutral" style="margin-left:6px;"><?= htmlspecialchars($c['student_id']) ?></span>
                                <span style="font-size:.8rem;color:var(--neutral);margin-left:6px;"><?= htmlspecialchars($c['class']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Unmatched photos -->
    <?php if (!empty($result['unmatched'])): ?>
    <div class="card">
        <div class="card-title">❌ Unmatched Photos</div>
        <div class="alert alert-danger" style="margin-bottom:16px;">
            No student could be found for these files. Check the filename and try again.
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Photo File</th><th>Reason</th></tr>
                </thead>
                <tbody>
                <?php foreach ($result['unmatched'] as $u): ?>
                <tr>
                    <td style="font-family:monospace;font-size:.82rem;"><?= htmlspecialchars($u['file']) ?></td>
                    <td><?= htmlspecialchars($u['reason']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // end if not empty ?>

</div><!-- /page-wrap -->

<!-- Result overlay -->
<div id="result-overlay">
    <div class="result-box">
        <div class="icon-big" id="result-icon"></div>
        <h2 id="result-title"></h2>
        <p id="result-body"></p>
        <ul id="result-errors" style="display:none;"></ul>
        <button class="btn btn-primary" onclick="window.location.reload()">🔄 Refresh &amp; Continue</button>
    </div>
</div>

<script>
// ── Select-all toggle ───────────────────────────────────────────────────────
document.getElementById('select-all')?.addEventListener('change', function () {
    document.querySelectorAll('.row-check').forEach(cb => {
        cb.checked = this.checked;
    });
});

// Keep select-all in sync when individual checkboxes change.
document.querySelectorAll('.row-check').forEach(cb => {
    cb.addEventListener('change', syncSelectAll);
});

function syncSelectAll() {
    const all   = document.querySelectorAll('.row-check');
    const checked = document.querySelectorAll('.row-check:checked');
    const sa    = document.getElementById('select-all');
    if (!sa) return;
    sa.indeterminate = checked.length > 0 && checked.length < all.length;
    sa.checked = checked.length === all.length;
}

// ── Confirm import ──────────────────────────────────────────────────────────
async function confirmImport() {
    const selected = [...document.querySelectorAll('.row-check:checked')]
        .map(cb => cb.value);

    if (selected.length === 0) {
        alert('No photos selected. Tick at least one row.');
        return;
    }

    if (!confirm(`Import ${selected.length} photo(s)? This will update the database and move files to the live folder.`)) {
        return;
    }

    const btn = document.getElementById('confirm-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Importing…';

    const body = new URLSearchParams();
    body.append('action', 'confirm_import');
    body.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
    selected.forEach(f => body.append('selected[]', f));

    try {
        const res  = await fetch(window.location.pathname, { method: 'POST', body });
        const data = await res.json();

        const overlay = document.getElementById('result-overlay');
        overlay.classList.add('visible');

        if (data.success) {
            document.getElementById('result-icon').textContent  = '✅';
            document.getElementById('result-title').textContent = `${data.imported} photo(s) imported successfully!`;
            document.getElementById('result-body').textContent  =
                data.errors.length > 0
                    ? `${data.errors.length} file(s) had issues (see below).`
                    : 'All selected photos were imported and the database has been updated.';
        } else {
            document.getElementById('result-icon').textContent  = '❌';
            document.getElementById('result-title').textContent = 'Import failed';
            document.getElementById('result-body').textContent  = data.error || 'An unknown error occurred.';
        }

        if (data.errors && data.errors.length > 0) {
            const ul = document.getElementById('result-errors');
            ul.style.display = 'block';
            ul.innerHTML = data.errors.map(e => `<li>${escHtml(e)}</li>`).join('');
        }

    } catch (err) {
        alert('Network error: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = '✅ Confirm &amp; Import Selected';
    }
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
</body>
</html>
