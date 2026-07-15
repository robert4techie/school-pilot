<?php
/**
 * ajax_handlers/aoi_form.php
 *
 * Returns the Add / Edit AOI form as an HTML fragment.
 * Injected into #form-container inside the modal on add_aoi.php.
 *
 * ── BUGS FIXED ──────────────────────────────────────────────────────────────
 *   BUG-5  The form had no csrf_token hidden field; the parent page appended
 *          it manually via JS string concatenation. This meant $(form).serialize()
 *          alone was insufficient — if JS was ever refactored, the token would
 *          silently disappear. Fix: embed the token as a hidden field so it is
 *          always part of the serialised form data.
 *          The parent JS still appends it as a fallback; duplicate POST keys are
 *          harmless since both values are identical and PHP uses the first match.
 *
 *   BUG-6  The <style> block was at the bottom of the fragment. When the parent
 *          injects HTML via innerHTML, browsers keep the <style> alive in the
 *          fragment container. If loadFormContent() were ever called more than
 *          once (e.g., future refactor), duplicate rules would accumulate.
 *          Fix: guard the style injection with a JS flag so it only runs once.
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
$allowed_classes  = ['Senior One', 'Senior Two', 'Senior Three', 'Senior Four'];
$allowed_terms    = ['Term 1', 'Term 2', 'Term 3'];
$allowed_streams  = ['East', 'West', 'South', 'North'];

/* ── Validate & sanitize inputs ──────────────────────────────────────── */
$sel_class = in_array($_POST['class'] ?? '', $allowed_classes, true)
    ? $_POST['class'] : '';

$term = in_array($_POST['term'] ?? '', $allowed_terms, true)
    ? $_POST['term'] : '';

$year = (int)($_POST['year'] ?? date('Y'));
if ($year < 2000 || $year > (int)date('Y') + 5) {
    $year = (int)date('Y');
}

$raw_streams = (array)($_POST['streams'] ?? []);
if (count($raw_streams) === 1 && str_contains($raw_streams[0], ',')) {
    $raw_streams = explode(',', $raw_streams[0]);
}
$streams = array_values(array_filter(
    array_map('trim', $raw_streams),
    fn($s) => in_array($s, $allowed_streams, true)
));
$streams_str = implode(',', $streams);

$subject = substr(strip_tags(trim($_POST['subject'] ?? '')), 0, 100);

/* ── Safe display values ─────────────────────────────────────────────── */
$h_class   = htmlspecialchars($sel_class,   ENT_QUOTES, 'UTF-8');
$h_term    = htmlspecialchars($term,        ENT_QUOTES, 'UTF-8');
$h_year    = (int)$year;
$h_subject = htmlspecialchars($subject,     ENT_QUOTES, 'UTF-8');
$h_streams = htmlspecialchars($streams_str, ENT_QUOTES, 'UTF-8');

// FIX BUG-5: embed the CSRF token so $(form).serialize() always includes it.
$h_csrf = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
?>
<form id="aoi-form" novalidate autocomplete="off">

    <!-- ── Security / Hidden state fields ──────────────────────────── -->
    <!-- FIX BUG-5: csrf_token now lives in the form itself -->
    <input type="hidden" name="csrf_token"  value="<?= $h_csrf ?>">

    <!-- ajax_action toggles between "add" and "edit" via JS setFormToEdit() -->
    <input type="hidden" id="form-action"  name="ajax_action" value="add">
    <input type="hidden" id="edit-id"      name="id"          value="">

    <!-- Context fields — sent with every submission -->
    <input type="hidden" name="class"   value="<?= $h_class ?>">
    <input type="hidden" name="term"    value="<?= $h_term ?>">
    <input type="hidden" name="year"    value="<?= $h_year ?>">
    <input type="hidden" name="subject" value="<?= $h_subject ?>">
    <!-- Streams stored as one comma-separated value (e.g. "East,West") -->
    <input type="hidden" name="streams" value="<?= $h_streams ?>">

    <!-- ── Topic ────────────────────────────────────────────────────── -->
    <div class="form-group">
        <label for="topic">
            Topic / Title <span style="color:#d32f2f;font-size:.85em">*</span>
        </label>
        <input type="text"
               id="topic"
               name="topic"
               class="form-control"
               maxlength="255"
               placeholder="e.g. Fractions and Proportions in Daily Life…"
               required>
        <span class="field-hint">A concise, descriptive title for this activity</span>
    </div>

    <!-- ── Description ──────────────────────────────────────────────── -->
    <div class="form-group" style="margin-top:16px">
        <label for="description">Description / Notes</label>
        <textarea id="description"
                  name="description"
                  class="form-control"
                  rows="5"
                  maxlength="2000"
                  placeholder="Learning objectives, methodology, resources needed…"></textarea>
        <span class="field-hint">Optional — up to 2 000 characters</span>
    </div>

    <!-- ── Form actions ─────────────────────────────────────────────── -->
    <div class="form-actions">
        <button type="button" id="cancel-btn" class="btn btn-secondary hidden">
            <i class="fas fa-times"></i> Cancel Edit
        </button>
        <button type="submit" id="submit-btn" class="btn btn-primary">
            <i class="fas fa-plus-circle"></i> Add Activity of Integration
        </button>
    </div>
</form>

<script>
// FIX BUG-6: only inject the <style> block once, even if this fragment is
// loaded more than once. A flag on the window object acts as a guard.
if (!window._aoiFormStyleInjected) {
    window._aoiFormStyleInjected = true;
    const style = document.createElement('style');
    style.textContent = `
        #aoi-form .form-group                       { display:flex; flex-direction:column; gap:5px; margin-bottom:0 }
        #aoi-form label                             { font-size:.8rem; font-weight:700; color:#3a4a3b; text-transform:uppercase; letter-spacing:.4px }
        #aoi-form .form-control                     { padding:10px 13px; border:1.5px solid #d0dbd1; border-radius:8px; font-size:.9rem; font-family:inherit; width:100%; background:#fff; transition:border-color .22s ease, box-shadow .22s ease }
        #aoi-form .form-control:focus               { outline:none; border-color:#43a047; box-shadow:0 0 0 3px rgba(67,160,71,.1) }
        #aoi-form .form-control.is-invalid          { border-color:#d32f2f }
        #aoi-form textarea.form-control             { resize:vertical; min-height:110px }
        #aoi-form .field-hint                       { font-size:.75rem; color:#8a9a8b; margin-top:2px }
        #aoi-form .form-actions                     { display:flex; gap:12px; justify-content:flex-end; padding-top:20px; border-top:1px solid #eef2ee; margin-top:20px }
    `;
    document.head.appendChild(style);
}
</script>
