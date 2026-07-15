<?php
/**
 * ajax_handlers/aoi_table.php
 *
 * Returns the AOI table as an HTML fragment.
 * Injected into #table-container on add_aoi.php.
 *
 * ── BUGS FIXED ──────────────────────────────────────────────────────────────
 *   BUG-7  The <style> block was re-injected on every table reload (add, delete,
 *          etc.), accumulating duplicate rules in the DOM on each refresh.
 *          Fix: same window-flag guard used in aoi_form.php — the style block
 *          is only appended to <head> on the first table load.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

/* ── AJAX-only guard ─────────────────────────────────────────────────── */
if (
    empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    http_response_code(403);
    exit('Forbidden');
}

/* ── POST-only guard ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

/* ── Auth + CSRF ─────────────────────────────────────────────────────── */
require_once '../../auth.php';
require_once '../../conn.php';

$token = trim($_POST['csrf_token'] ?? '');
if (
    empty($token) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $token)
) {
    http_response_code(403);
    exit('Invalid security token.');
}

/* ── Allowed value whitelists ────────────────────────────────────────── */
$allowed_classes = ['Senior One', 'Senior Two', 'Senior Three', 'Senior Four'];
$allowed_terms   = ['Term 1', 'Term 2', 'Term 3'];

/* ── Validate inputs ─────────────────────────────────────────────────── */
$sel_class = in_array($_POST['class'] ?? '', $allowed_classes, true)
    ? $_POST['class'] : '';

$term = in_array($_POST['term'] ?? '', $allowed_terms, true)
    ? $_POST['term'] : '';

$year = (int)($_POST['year'] ?? date('Y'));
if ($year < 2000 || $year > (int)date('Y') + 5) {
    $year = (int)date('Y');
}

$subject = substr(strip_tags(trim($_POST['subject'] ?? '')), 0, 100);

/* ── Fetch AOI records ───────────────────────────────────────────────── */
$aois     = [];
$db_error = false;

if ($sel_class && $term && $subject) {
    $stmt = $conn->prepare(
        'SELECT id, topic, description, streams, created_at
         FROM aoi
         WHERE class = ? AND term = ? AND year = ? AND subject = ?
         ORDER BY id ASC'
    );

    if ($stmt === false) {
        error_log('[aoi_table] prepare failed: ' . $conn->error);
        $db_error = true;
    } else {
        $stmt->bind_param('ssis', $sel_class, $term, $year, $subject);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $aois[] = $row;
        }
        $stmt->close();
    }
}

$conn->close();

/* ── Helper: truncate long text for table display ────────────────────── */
function truncate(string $text, int $max = 120): string {
    $text = strip_tags($text);
    return mb_strlen($text) > $max
        ? mb_substr($text, 0, $max) . '…'
        : $text;
}
?>
<div class="table-wrap">
<?php if ($db_error): ?>
    <div class="empty-state">
        <i class="fas fa-circle-exclamation"></i>
        <p>Failed to load activities. Please refresh the page.</p>
    </div>
<?php elseif (empty($aois)): ?>
    <div class="empty-state">
        <i class="fas fa-list-check"></i>
        <p>No activities of integration registered yet for this selection.<br>
           Click <strong>Add New Activity</strong> to get started.</p>
    </div>
<?php else: ?>
    <table id="aoi-table">
        <thead>
            <tr>
                <th style="width:4%">#</th>
                <th style="width:28%">Topic</th>
                <th style="width:45%">Description</th>
                <th style="width:11%">Streams</th>
                <th style="width:12%">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($aois as $i => $aoi): ?>
            <tr id="aoi-row-<?= (int)$aoi['id'] ?>">
                <td><?= $i + 1 ?></td>
                <td>
                    <div class="aoi-topic">
                        <?= htmlspecialchars($aoi['topic'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </td>
                <td>
                    <?php $desc = trim($aoi['description'] ?? ''); ?>
                    <?php if ($desc): ?>
                        <div class="aoi-desc"
                             title="<?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(truncate($desc, 130), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php else: ?>
                        <span class="aoi-no-desc">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    $streamList = array_filter(array_map('trim', explode(',', $aoi['streams'] ?? '')));
                    foreach ($streamList as $s):
                    ?>
                        <span class="stream-badge"><?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endforeach; ?>
                </td>
                <td>
                    <div class="action-cell">
                        <button type="button"
                                class="btn-icon bi-edit edit-aoi"
                                data-id="<?= (int)$aoi['id'] ?>"
                                title="Edit this activity"
                                aria-label="Edit <?= htmlspecialchars($aoi['topic'], ENT_QUOTES, 'UTF-8') ?>">
                            <i class="fas fa-pencil"></i>
                        </button>
                        <button type="button"
                                class="btn-icon bi-delete delete-aoi"
                                data-id="<?= (int)$aoi['id'] ?>"
                                data-topic="<?= htmlspecialchars($aoi['topic'], ENT_QUOTES, 'UTF-8') ?>"
                                title="Delete this activity"
                                aria-label="Delete <?= htmlspecialchars($aoi['topic'], ENT_QUOTES, 'UTF-8') ?>">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="aoi-table-footer">
        <span><?= count($aois) ?> activit<?= count($aois) === 1 ? 'y' : 'ies' ?> registered</span>
    </div>
<?php endif; ?>
</div>

<script>
// FIX BUG-7: inject table-specific styles only once, even across multiple
// table reloads triggered by add / delete operations.
if (!window._aoiTableStyleInjected) {
    window._aoiTableStyleInjected = true;
    const style = document.createElement('style');
    style.textContent = `
        .aoi-topic        { font-weight:600; color:#2e7d32; font-size:.875rem }
        .aoi-desc         { font-size:.85rem; color:#444; line-height:1.45; cursor:default }
        .aoi-no-desc      { color:#bbb; font-style:italic; font-size:.8rem }
        .stream-badge     { display:inline-block; background:#e3f2fd; color:#1565c0; border-radius:12px;
                            padding:2px 8px; font-size:.7rem; font-weight:700; text-transform:uppercase;
                            letter-spacing:.3px; margin:1px 2px 1px 0 }
        .aoi-table-footer { padding:12px 16px; font-size:.8rem; color:#8a9a8b;
                            border-top:1px solid #f0f4f1; text-align:right }
    `;
    document.head.appendChild(style);
}
</script>
