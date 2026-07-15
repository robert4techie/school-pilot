<?php
// ═══════════════════════════════════════════════════════════════════════════════
//  user_roles.php  –  User Roles & Permissions Management
//  Production-grade: CSRF, server-side auth, input validation, audit logging,
//  self-delete guard, last-super-user guard, developer account protection.
// ═══════════════════════════════════════════════════════════════════════════════
require_once 'auth.php';       // starts session, sets $_SESSION
require_once 'conn.php';       // provides $conn (mysqli OOP object)
require_once 'tracking.php';

$tracker->trackAction('User Roles and Permissions');

// ── Access Gate ───────────────────────────────────────────────────────────────
$PRIVILEGED_ROLES = ['super user', 'developer'];
$current_role     = $_SESSION['role']    ?? '';
$current_uid      = (int)($_SESSION['user_id'] ?? 0);

if (!in_array($current_role, $PRIVILEGED_ROLES, true)) {
    header('Location: dashboard.php');
    exit;
}

// ── CSRF Token ────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── Roles that can be assigned (never escalate to developer) ──────────────────
const ASSIGNABLE_ROLES = [
    'super user', 'school leader', 'class teacher', 'subject teacher',
    'nurse', 'bursar', 'librarian', 'receptionist', 'gateman', 'lab attendant'
];

// ── Helper: write audit record (silent failure so it never breaks main flow) ──
function log_audit(mysqli $conn, int $actor_id, string $action, string $detail): void {
    $stmt = $conn->prepare(
        "INSERT INTO audit_log (actor_id, action, detail, created_at)
         VALUES (?, ?, ?, NOW())"
    );
    if ($stmt) {
        $stmt->bind_param('iss', $actor_id, $action, $detail);
        $stmt->execute();
        $stmt->close();
    }
}

// ── Helper: emit JSON error and halt ─────────────────────────────────────────
function json_error(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  AJAX POST HANDLER
// ═══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // 1. CSRF validation
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $token)) {
        json_error('Invalid security token. Please refresh the page.', 403);
    }

    // 2. Re-verify privileges (defence-in-depth — never trust only the page gate)
    if (!in_array($current_role, $PRIVILEGED_ROLES, true)) {
        json_error('Insufficient permissions.', 403);
    }

    $action  = $_POST['action'] ?? '';
    $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

    if ($user_id <= 0) {
        json_error('Invalid user ID.');
    }

    // ── Fetch target user info (used by both delete and update) ───────────────
    $info = $conn->prepare("SELECT user_name, email, role FROM users WHERE user_id = ?");
    $info->bind_param('i', $user_id);
    $info->execute();
    $info->bind_result($target_name, $target_email, $target_role);
    if (!$info->fetch()) {
        $info->close();
        json_error('User not found.', 404);
    }
    $info->close();

    // ══════════════════════════════════════════════════════════════════════════
    //  DELETE
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'delete') {

        // Cannot delete yourself
        if ($user_id === $current_uid) {
            json_error('You cannot delete your own account.');
        }

        // Cannot delete a developer account from this interface
        if ($target_role === 'developer') {
            json_error('Developer accounts cannot be deleted from this interface.');
        }

        // Cannot delete the last super user
        if ($target_role === 'super user') {
            $cnt = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'super user'");
            [$su_count] = $cnt->fetch_row();
            if ((int) $su_count <= 1) {
                json_error('Cannot delete the only remaining super user account.');
            }
        }

        $conn->begin_transaction();
        try {
            // Remove tracking records first (FK integrity)
            $s1 = $conn->prepare("DELETE FROM user_tracking WHERE id = ?");
            $s1->bind_param('i', $user_id);
            $s1->execute();
            $s1->close();

            // Delete the user
            $s2 = $conn->prepare("DELETE FROM users WHERE user_id = ?");
            $s2->bind_param('i', $user_id);
            $s2->execute();
            $s2->close();

            $conn->commit();

            log_audit($conn, $current_uid, 'DELETE_USER',
                "Deleted user_id={$user_id} username=\"{$target_name}\" role=\"{$target_role}\"");

            echo json_encode(['success' => true, 'message' => "User \"{$target_name}\" deleted successfully."]);

        } catch (Exception $e) {
            $conn->rollback();
            error_log('[user_roles] DELETE error uid=' . $user_id . ': ' . $e->getMessage());
            json_error('A server error occurred. Please try again.', 500);
        }
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  UPDATE
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'update') {
        $new_email = trim($_POST['email'] ?? '');
        $new_role  = trim($_POST['role']  ?? '');

        // Validate email format
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            json_error('Please enter a valid email address.');
        }
        // Clamp email length
        if (strlen($new_email) > 255) {
            json_error('Email address is too long.');
        }

        // Validate role against whitelist
        if (!in_array($new_role, ASSIGNABLE_ROLES, true)) {
            json_error('Invalid role selected.');
        }

        // Cannot edit a developer account
        if ($target_role === 'developer') {
            json_error('Developer account details cannot be changed from this interface.');
        }

        // Cannot demote the last super user
        if ($target_role === 'super user' && $new_role !== 'super user') {
            $cnt = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'super user'");
            [$su_count] = $cnt->fetch_row();
            if ((int) $su_count <= 1) {
                json_error('Cannot change the role of the only remaining super user.');
            }
        }

        // Email uniqueness check
        $dup = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $dup->bind_param('si', $new_email, $user_id);
        $dup->execute();
        $dup->store_result();
        if ($dup->num_rows > 0) {
            $dup->close();
            json_error('That email address is already in use by another account.');
        }
        $dup->close();

        try {
            $upd = $conn->prepare("UPDATE users SET email = ?, role = ? WHERE user_id = ?");
            $upd->bind_param('ssi', $new_email, $new_role, $user_id);
            $upd->execute();
            $upd->close();

            log_audit($conn, $current_uid, 'UPDATE_USER',
                "Updated user_id={$user_id} username=\"{$target_name}\" " .
                "email=[{$target_email}→{$new_email}] role=[{$target_role}→{$new_role}]");

            echo json_encode(['success' => true, 'message' => "User \"{$target_name}\" updated successfully."]);

        } catch (Exception $e) {
            error_log('[user_roles] UPDATE error uid=' . $user_id . ': ' . $e->getMessage());
            json_error('A server error occurred. Please try again.', 500);
        }
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ADMIN RESET PASSWORD
    //  The logged-in super user / developer confirms with THEIR OWN password,
    //  then the target user's password is replaced — no knowledge of the
    //  target's current password required.
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'admin_reset_password') {
        $admin_password = $_POST['admin_password']      ?? '';
        $new_password   = trim($_POST['new_password']   ?? '');
        $confirm_pass   = trim($_POST['confirm_password'] ?? '');

        // ── 1. Verify admin's own password ────────────────────────────────────
        $chk = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
        $chk->bind_param('i', $current_uid);
        $chk->execute();
        $chk->bind_result($admin_hash);
        $chk->fetch();
        $chk->close();

        if (empty($admin_hash) || !password_verify($admin_password, $admin_hash)) {
            json_error('Your password is incorrect. Reset cancelled.');
        }

        // ── 2. Validate new password strength ─────────────────────────────────
        if (strlen($new_password) < 8) {
            json_error('New password must be at least 8 characters long.');
        }
        if (!preg_match('/[A-Z]/', $new_password)) {
            json_error('New password must contain at least one uppercase letter.');
        }
        if (!preg_match('/[a-z]/', $new_password)) {
            json_error('New password must contain at least one lowercase letter.');
        }
        if (!preg_match('/[0-9]/', $new_password)) {
            json_error('New password must contain at least one number.');
        }
        if (!preg_match('/[\W_]/', $new_password)) {
            json_error('New password must contain at least one special character.');
        }
        if ($new_password !== $confirm_pass) {
            json_error('New passwords do not match.');
        }

        // ── 3. Cannot reset a developer account (unless you are also a developer) ──
        if ($target_role === 'developer' && $current_role !== 'developer') {
            json_error('Only a developer can reset another developer\'s password.');
        }

        // ── 4. Apply the new password ─────────────────────────────────────────
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        try {
            $upd = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $upd->bind_param('si', $hashed, $user_id);
            $upd->execute();
            $upd->close();

            log_audit($conn, $current_uid, 'RESET_PASSWORD',
                "Admin reset password for user_id={$user_id} username=\"{$target_name}\" role=\"{$target_role}\"");

            echo json_encode(['success' => true,
                'message' => "Password for \"{$target_name}\" has been reset successfully."]);

        } catch (Exception $e) {
            error_log('[user_roles] RESET_PASSWORD error uid=' . $user_id . ': ' . $e->getMessage());
            json_error('A server error occurred. Please try again.', 500);
        }
        exit;
    }

    json_error('Unknown action.');
}

// ═══════════════════════════════════════════════════════════════════════════════
//  LOAD AUDIT LOGS (server-side, for initial page render)
// ═══════════════════════════════════════════════════════════════════════════════
$audit_logs = [];
$audit_res  = $conn->query(
    "SELECT al.id, al.action, al.detail, al.created_at,
            COALESCE(u.user_name, 'System') AS actor_name
     FROM   audit_log al
     LEFT JOIN users u ON u.user_id = al.actor_id
     ORDER  BY al.created_at DESC
     LIMIT  100"
);
if ($audit_res) {
    while ($row = $audit_res->fetch_assoc()) {
        $audit_logs[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Roles &amp; Permissions — School Pilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
<style>
/* ── Variables ──────────────────────────────────────────────────────────────── */
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--orange:#e65100;--blue:#1565c0;--purple:#6a1b9a;--gray:#546e7a;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);--shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --transition:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}
a{color:inherit;text-decoration:none}

/* ── Layout ─────────────────────────────────────────────────────────────────── */
.page{max-width:100%;padding:24px 20px 48px;margin-top:60px}

/* ── Page Header ────────────────────────────────────────────────────────────── */
.page-header{
  background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);
  border-radius:var(--radius-lg);padding:28px 32px;
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:20px;margin-bottom:24px;box-shadow:var(--shadow-lg)
}
.page-header-left h1{color:#fff;font-size:1.55rem;font-weight:700;letter-spacing:.3px;display:flex;align-items:center;gap:10px}
.page-header-left p{color:rgba(255,255,255,.78);font-size:.9rem;margin-top:4px}
.stats-row{display:flex;gap:12px;flex-wrap:wrap}
.stat-pill{
  background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);
  border-radius:40px;padding:8px 18px;text-align:center;min-width:90px;
  cursor:default;transition:background var(--transition)
}
.stat-pill:hover{background:rgba(255,255,255,.22)}
.stat-pill .n{font-size:1.35rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.72rem;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.5px}

/* ── Tabs ───────────────────────────────────────────────────────────────────── */
.tabs{display:flex;gap:4px;margin-bottom:16px}
.tab-btn{
  padding:9px 20px;border:none;border-radius:var(--radius) var(--radius) 0 0;
  background:#fff;color:#666;font-size:.875rem;font-weight:600;cursor:pointer;
  border-bottom:3px solid transparent;transition:all var(--transition);font-family:inherit
}
.tab-btn.active{color:var(--g800);border-bottom-color:var(--g700);background:#fff}
.tab-btn:hover:not(.active){background:#edf7ee;color:var(--g700)}
.tab-panel{display:none}
.tab-panel.active{display:block}

/* ── Card ───────────────────────────────────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden}

/* ── Toolbar ────────────────────────────────────────────────────────────────── */
.toolbar{
  padding:16px 20px;border-bottom:1px solid #e8ede9;
  display:flex;flex-wrap:wrap;gap:10px;align-items:center
}
.toolbar-left{display:flex;flex-wrap:wrap;gap:10px;align-items:center;flex:1 1 auto}
.toolbar-right{display:flex;gap:8px;align-items:center;flex-shrink:0}
.search-wrap{position:relative;min-width:220px}
.search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.85rem}
.search-wrap input{
  width:100%;padding:9px 12px 9px 34px;border:1.5px solid #d0dbd1;
  border-radius:var(--radius);font-size:.875rem;
  transition:border-color var(--transition),box-shadow var(--transition);font-family:inherit
}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.filter-select{
  padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);
  font-size:.875rem;background:#fff;cursor:pointer;min-width:130px;font-family:inherit;
  transition:border-color var(--transition)
}
.filter-select:focus{outline:none;border-color:var(--g600)}
.result-count{font-size:.8rem;color:#6b7c6d;white-space:nowrap}

/* ── Buttons ────────────────────────────────────────────────────────────────── */
.btn{
  display:inline-flex;align-items:center;gap:7px;padding:9px 16px;
  border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;
  font-family:inherit;transition:all var(--transition);white-space:nowrap;cursor:pointer
}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}
.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-pdf{background:#c62828;color:#fff}.btn-pdf:hover{background:var(--red)}
.btn-excel{background:var(--g800);color:#fff}.btn-excel:hover{background:var(--g900)}
.btn-danger{background:var(--red);color:#fff}.btn-danger:hover{background:#b71c1c}
.btn-cancel-modal{background:#f5f5f5;color:#555;border:1.5px solid #ddd}
.btn-cancel-modal:hover{background:#e8e8e8;transform:none}

/* ── Icon Action Buttons ────────────────────────────────────────────────────── */
.action-cell{display:flex;gap:5px;align-items:center}
.btn-icon{
  width:30px;height:30px;border:none;border-radius:6px;cursor:pointer;
  display:inline-flex;align-items:center;justify-content:center;
  font-size:.78rem;transition:all var(--transition);flex-shrink:0
}
.btn-icon:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.18)}
.bi-edit{background:#fff3e0;color:#e65100}.bi-edit:hover{background:#e65100;color:#fff}
.bi-delete{background:#ffebee;color:var(--red)}.bi-delete:hover{background:var(--red);color:#fff}
.bi-reset{background:#e8f5e9;color:#1b5e20}.bi-reset:hover{background:#1b5e20;color:#fff}
.audit-RESET_PASSWORD{background:#e8f5e9;color:#1b5e20}

/* ── Table ──────────────────────────────────────────────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:13px 14px;text-align:left;font-size:.8rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--transition)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:12px 14px;font-size:.875rem;vertical-align:middle}
.user-name-cell{font-weight:600;color:var(--g800)}

/* ── Role Badges ────────────────────────────────────────────────────────────── */
.badge{
  display:inline-block;padding:4px 10px;border-radius:20px;
  font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px
}
/* Fixed: use hyphenated class names that match JS `role.replace(/\s+/g,'-')` */
.badge-super-user     {background:#e8f5e9;color:#1b5e20}
.badge-school-leader  {background:#e3f2fd;color:#0d47a1}
.badge-class-teacher  {background:#e3f2fd;color:#1565c0}
.badge-subject-teacher{background:#e8eaf6;color:#283593}
.badge-nurse          {background:#fce4ec;color:#880e4f}
.badge-bursar         {background:#fff8e1;color:#e65100}
.badge-librarian      {background:#f3e5f5;color:#6a1b9a}
.badge-receptionist   {background:#e0f2f1;color:#004d40}
.badge-gateman        {background:#efebe9;color:#3e2723}
.badge-lab-attendant  {background:#e0f7fa;color:#006064}
.badge-developer      {background:#263238;color:#fff}
.badge-default        {background:#eceff1;color:#546e7a}

/* ── Skeleton ───────────────────────────────────────────────────────────────── */
.skeleton-cell{
  background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);
  background-size:200% 100%;animation:shimmer 1.4s infinite;
  border-radius:4px;height:14px;display:inline-block;width:80%
}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ── Empty State ────────────────────────────────────────────────────────────── */
.empty-state{text-align:center;padding:60px 20px;color:#8a9a8b}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;opacity:.45}
.empty-state p{font-size:.95rem}

/* ── Pagination ─────────────────────────────────────────────────────────────── */
.pagination{
  padding:16px 20px;display:flex;align-items:center;justify-content:space-between;
  border-top:1px solid #e8ede9;flex-wrap:wrap;gap:10px
}
.page-info{font-size:.82rem;color:#6b7c6d}
.page-btns{display:flex;gap:4px}
.page-btn{
  width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:6px;
  background:#fff;cursor:pointer;font-size:.82rem;font-weight:600;color:#444;
  display:flex;align-items:center;justify-content:center;transition:all var(--transition)
}
.page-btn:hover:not(:disabled){border-color:var(--g600);background:var(--g100);color:var(--g800)}
.page-btn.active{background:var(--g700);border-color:var(--g700);color:#fff}
.page-btn:disabled{opacity:.38;cursor:default}

/* ── Audit Log ──────────────────────────────────────────────────────────────── */
.audit-toolbar{padding:16px 20px;border-bottom:1px solid #e8ede9;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.audit-search-wrap{position:relative;min-width:240px;flex:1}
.audit-search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.85rem}
.audit-search-wrap input{width:100%;padding:9px 12px 9px 34px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;transition:border-color var(--transition)}
.audit-search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.audit-action-badge{
  display:inline-block;padding:3px 8px;border-radius:12px;font-size:.7rem;font-weight:700;
  text-transform:uppercase;letter-spacing:.4px;white-space:nowrap
}
.audit-DELETE_USER{background:#ffebee;color:#b71c1c}
.audit-UPDATE_USER{background:#fff3e0;color:#e65100}
.audit-detail{font-size:.78rem;color:#666;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.audit-empty{text-align:center;padding:40px 20px;color:#8a9a8b}
.audit-empty i{font-size:2.5rem;display:block;margin-bottom:10px;opacity:.4}

/* ── Modal ──────────────────────────────────────────────────────────────────── */
.modal{
  display:none;position:fixed;inset:0;z-index:1000;
  background:rgba(0,0,0,.45);backdrop-filter:blur(3px);
  animation:fadeOverlay .2s ease
}
@keyframes fadeOverlay{from{opacity:0}to{opacity:1}}
.modal.active{display:flex;align-items:center;justify-content:center;padding:20px 16px;overflow-y:auto}
.modal-box{
  background:#fff;border-radius:var(--radius-lg);width:100%;max-width:480px;
  box-shadow:var(--shadow-lg);animation:slideDown .25s ease;position:relative
}
@keyframes slideDown{from{transform:translateY(-24px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{
  background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%);
  padding:18px 22px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;
  display:flex;align-items:center;justify-content:space-between
}
.modal-head.danger{background:linear-gradient(135deg,#b71c1c 0%,#ef5350 100%)}
.modal-head h2{color:#fff;font-size:1.05rem;font-weight:700;display:flex;align-items:center;gap:10px}
.modal-close{
  background:rgba(255,255,255,.15);border:none;color:#fff;
  width:30px;height:30px;border-radius:50%;font-size:1rem;cursor:pointer;
  display:flex;align-items:center;justify-content:center;transition:background var(--transition)
}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:24px 24px 20px}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;padding:0 24px 22px}

/* Modal loader overlay */
.modal-loader{
  position:absolute;inset:0;border-radius:var(--radius-lg);
  background:rgba(255,255,255,.72);display:none;
  align-items:center;justify-content:center;z-index:5
}
.modal-loader.show{display:flex}
.spin{font-size:2.2rem;color:var(--g700);animation:rotating 1s linear infinite}
@keyframes rotating{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}

/* Form elements */
.form-group{margin-bottom:16px}
.form-group label{display:block;font-size:.8rem;font-weight:600;color:#3a4a3b;margin-bottom:5px}
.form-group label .req{color:var(--red)}
.form-control{
  padding:9px 13px;border:1.5px solid #d0dbd1;border-radius:var(--radius);
  font-size:.875rem;width:100%;font-family:inherit;
  transition:border-color var(--transition),box-shadow var(--transition)
}
.form-control:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.1)}
.form-control[readonly]{background:#f5f8f5;color:#555;cursor:default}

/* Delete modal warning box */
.delete-warning{
  background:#fff8e1;border:1px solid #ffe082;border-radius:var(--radius);
  padding:14px 16px;margin-bottom:16px;display:flex;gap:12px;align-items:flex-start
}
.delete-warning i{color:#f57f17;font-size:1.2rem;flex-shrink:0;margin-top:1px}
.delete-warning p{font-size:.875rem;color:#5d4037;line-height:1.5}
.delete-warning strong{color:#bf360c}

/* ── Toast Notifications ────────────────────────────────────────────────────── */
.toast-stack{position:fixed;top:16px;right:16px;z-index:2000;display:flex;flex-direction:column;gap:8px;max-width:360px}
.toast{
  background:#fff;border-radius:var(--radius);box-shadow:0 4px 18px rgba(0,0,0,.15);
  display:flex;align-items:flex-start;gap:12px;padding:14px 16px;
  animation:toastIn .3s ease;border-left:4px solid var(--g700);min-width:260px
}
.toast.error{border-left-color:var(--red)}
.toast.warning{border-left-color:#f57f17}
.toast-icon{font-size:1.1rem;flex-shrink:0;margin-top:1px}
.toast.error .toast-icon{color:var(--red)}
.toast:not(.error):not(.warning) .toast-icon{color:var(--g700)}
.toast.warning .toast-icon{color:#f57f17}
.toast-text{flex:1;font-size:.85rem;color:#333;line-height:1.45}
.toast-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:1rem;padding:0;line-height:1;flex-shrink:0}
.toast-close:hover{color:#555}
@keyframes toastIn{from{transform:translateX(100%);opacity:0}to{transform:none;opacity:1}}

/* ── Responsive ─────────────────────────────────────────────────────────────── */
@media(max-width:768px){
  .page{padding:14px 12px 40px}
  .page-header{padding:20px 18px}
  .toolbar{flex-direction:column;align-items:stretch}
  .toolbar-right{justify-content:flex-end}
  thead th:nth-child(5){display:none} /* hide Last Login on mobile */
  tbody td:nth-child(5){display:none}
}
</style>
</head>
<body>

<?php require_once 'nav.php'; ?>

<div class="page">

  <!-- ── Page Header ──────────────────────────────────────────────────────── -->
  <div class="page-header">
    <div class="page-header-left">
      <h1><i class="fas fa-user-shield"></i> User Roles &amp; Permissions</h1>
      <p>Manage user accounts, assign roles, and review all administrative actions</p>
    </div>
    <div class="stats-row" id="statsRow">
      <div class="stat-pill"><span class="n" id="statTotal">—</span><span class="l">Total</span></div>
      <div class="stat-pill"><span class="n" id="statSuperUser">—</span><span class="l">Super Users</span></div>
      <div class="stat-pill"><span class="n" id="statTeachers">—</span><span class="l">Teachers</span></div>
      <div class="stat-pill"><span class="n" id="statOther">—</span><span class="l">Other Staff</span></div>
    </div>
  </div>

  <!-- ── Tabs ─────────────────────────────────────────────────────────────── -->
  <div class="tabs">
    <button class="tab-btn active" onclick="switchTab('users',this)">
      <i class="fas fa-users"></i> Users
    </button>
    <button class="tab-btn" onclick="switchTab('audit',this)">
      <i class="fas fa-clipboard-list"></i> Audit Log
      <?php if (count($audit_logs)): ?>
        <span style="background:var(--g700);color:#fff;border-radius:10px;padding:2px 7px;font-size:.7rem;margin-left:4px">
          <?= count($audit_logs) ?>
        </span>
      <?php endif; ?>
    </button>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════
       TAB: USERS
  ════════════════════════════════════════════════════════════════════════ -->
  <div id="tab-users" class="tab-panel active">
    <div class="card">
      <div class="toolbar">
        <div class="toolbar-left">
          <div class="search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search name, email, or role…" autocomplete="off">
          </div>
          <select class="filter-select" id="roleFilter">
            <option value="">All Roles</option>
            <?php foreach (ASSIGNABLE_ROLES as $r): ?>
              <option value="<?= htmlspecialchars($r) ?>"><?= ucwords($r) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="result-count" id="resultCount"></span>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-outline" id="btnExcelExport">
            <i class="fas fa-file-excel"></i> Excel
          </button>
          <button class="btn btn-pdf" id="btnPdfExport">
            <i class="fas fa-file-pdf"></i> PDF
          </button>
        </div>
      </div>

      <div class="table-wrap">
        <table id="usersTable">
          <thead>
            <tr>
              <th>ID</th>
              <th>Username</th>
              <th>Email</th>
              <th>Role</th>
              <th>Last Login</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="tableBody">
            <!-- skeleton rows while loading -->
            <?php for ($i = 0; $i < 6; $i++): ?>
            <tr>
              <?php for ($c = 0; $c < 6; $c++): ?>
                <td><span class="skeleton-cell" style="width:<?= [40,70,90,60,70,50][$c] ?>%"></span></td>
              <?php endfor; ?>
            </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>

      <div class="pagination" id="paginationWrap">
        <span class="page-info" id="pageInfo"></span>
        <div class="page-btns" id="pageBtns"></div>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════
       TAB: AUDIT LOG
  ════════════════════════════════════════════════════════════════════════ -->
  <div id="tab-audit" class="tab-panel">
    <div class="card">
      <div class="audit-toolbar">
        <div class="audit-search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="auditSearch" placeholder="Filter by user, action, or detail…" autocomplete="off">
        </div>
        <span class="result-count" id="auditCount"></span>
      </div>

      <div class="table-wrap">
        <table id="auditTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Date &amp; Time</th>
              <th>Actor</th>
              <th>Action</th>
              <th>Detail</th>
            </tr>
          </thead>
          <tbody id="auditBody">
            <?php if (empty($audit_logs)): ?>
            <tr>
              <td colspan="5">
                <div class="audit-empty">
                  <i class="fas fa-clipboard-list"></i>
                  <p>No audit records yet. Actions on this page will be logged here.</p>
                </div>
              </td>
            </tr>
            <?php else: ?>
              <?php foreach ($audit_logs as $idx => $log): ?>
              <tr class="audit-row"
                  data-search="<?= htmlspecialchars(strtolower($log['actor_name'].' '.$log['action'].' '.$log['detail'])) ?>">
                <td><?= $idx + 1 ?></td>
                <td style="white-space:nowrap;font-size:.8rem;color:#555">
                  <?= htmlspecialchars(date('d M Y, H:i', strtotime($log['created_at']))) ?>
                </td>
                <td style="font-weight:600;color:var(--g800)"><?= htmlspecialchars($log['actor_name']) ?></td>
                <td>
                  <span class="audit-action-badge audit-<?= htmlspecialchars($log['action']) ?>">
                    <?= htmlspecialchars(str_replace('_', ' ', $log['action'])) ?>
                  </span>
                </td>
                <td>
                  <span class="audit-detail" title="<?= htmlspecialchars($log['detail']) ?>">
                    <?= htmlspecialchars($log['detail']) ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- .page -->

<!-- ═══════════════════════════════════════════════════════════════════════════
     EDIT MODAL
════════════════════════════════════════════════════════════════════════════ -->
<div id="editModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
  <div class="modal-box">
    <div class="modal-loader" id="editLoader"><i class="fas fa-spinner spin"></i></div>
    <div class="modal-head">
      <h2 id="editModalTitle"><i class="fas fa-user-edit"></i> Edit User</h2>
      <button class="modal-close" onclick="closeEditModal()" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="editUserId">
      <div class="form-group">
        <label>Username</label>
        <input class="form-control" id="editUsername" type="text" readonly>
      </div>
      <div class="form-group">
        <label for="editEmail">Email Address <span class="req">*</span></label>
        <input class="form-control" id="editEmail" type="email" required autocomplete="off">
      </div>
      <div class="form-group">
        <label for="editRole">User Role <span class="req">*</span></label>
        <select class="form-control" id="editRole" required>
          <?php foreach (ASSIGNABLE_ROLES as $r): ?>
            <option value="<?= htmlspecialchars($r) ?>"><?= ucwords($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-cancel-modal" onclick="closeEditModal()">
        <i class="fas fa-times"></i> Cancel
      </button>
      <button class="btn btn-primary" onclick="submitEdit()">
        <i class="fas fa-save"></i> Save Changes
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     DELETE MODAL
════════════════════════════════════════════════════════════════════════════ -->
<div id="deleteModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
  <div class="modal-box">
    <div class="modal-loader" id="deleteLoader"><i class="fas fa-spinner spin"></i></div>
    <div class="modal-head danger">
      <h2 id="deleteModalTitle"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h2>
      <button class="modal-close" onclick="closeDeleteModal()" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="delete-warning">
        <i class="fas fa-exclamation-circle"></i>
        <p>You are about to permanently delete <strong id="deleteUserName"></strong>.
           All associated records and login history will also be removed. This action
           <strong>cannot be undone</strong>.</p>
      </div>
      <p style="font-size:.875rem;color:#555">Are you sure you want to proceed?</p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-cancel-modal" onclick="closeDeleteModal()">
        <i class="fas fa-times"></i> Cancel
      </button>
      <button class="btn btn-danger" onclick="confirmDelete()">
        <i class="fas fa-trash-alt"></i> Delete User
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     RESET PASSWORD MODAL
════════════════════════════════════════════════════════════════════════════ -->
<div id="resetModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="resetModalTitle">
  <div class="modal-box">
    <div class="modal-loader" id="resetLoader"><i class="fas fa-spinner spin"></i></div>
    <div class="modal-head">
      <h2 id="resetModalTitle"><i class="fas fa-key"></i> Reset User Password</h2>
      <button class="modal-close" onclick="closeResetModal()" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="resetUserId">

      <!-- Target user info banner -->
      <div style="background:var(--g100);border:1px solid #a5d6a7;border-radius:var(--radius);
                  padding:11px 14px;margin-bottom:18px;display:flex;align-items:center;gap:10px">
        <i class="fas fa-user-circle" style="color:var(--g700);font-size:1.2rem;flex-shrink:0"></i>
        <div>
          <div style="font-size:.78rem;color:#555">Resetting password for</div>
          <div style="font-weight:700;color:var(--g800);font-size:.95rem" id="resetTargetName"></div>
        </div>
      </div>

      <!-- Admin confirmation -->
      <div class="form-group">
        <label for="adminPassword">
          <i class="fas fa-shield-alt" style="color:var(--g700)"></i>
          Your Password (Admin Confirmation) <span class="req">*</span>
        </label>
        <div style="position:relative">
          <input class="form-control" id="adminPassword" type="password"
                 placeholder="Enter your own password to confirm" autocomplete="current-password">
          <button type="button" onclick="toggleResetField('adminPassword')"
                  style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                         background:none;border:none;cursor:pointer;color:#8a9a8b;font-size:1rem">
            <i class="fas fa-eye" id="adminPasswordEye"></i>
          </button>
        </div>
        <div style="font-size:.77rem;color:#888;margin-top:5px">
          <i class="fas fa-info-circle"></i> This verifies your identity before changing another user's password.
        </div>
      </div>

      <hr style="border:none;border-top:1px solid #e8ede9;margin:18px 0">

      <!-- New password for target user -->
      <div class="form-group">
        <label for="resetNewPassword">
          New Password for <span id="resetTargetNameInline" style="color:var(--g800)"></span>
          <span class="req">*</span>
        </label>
        <div style="position:relative">
          <input class="form-control" id="resetNewPassword" type="password"
                 placeholder="Enter new password" autocomplete="new-password"
                 oninput="checkResetStrength()">
          <button type="button" onclick="toggleResetField('resetNewPassword')"
                  style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                         background:none;border:none;cursor:pointer;color:#8a9a8b;font-size:1rem">
            <i class="fas fa-eye" id="resetNewPasswordEye"></i>
          </button>
        </div>
        <!-- Strength bar -->
        <div style="height:5px;background:#e8ede9;border-radius:4px;margin-top:8px;overflow:hidden">
          <div id="resetStrengthBar" style="height:100%;width:0;border-radius:4px;transition:all .3s"></div>
        </div>
        <!-- Inline hints -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 10px;margin-top:9px;font-size:.76rem">
          <span id="rh-len"   class="rh"><i class="fas fa-times rh-icon"></i> 8+ characters</span>
          <span id="rh-upper" class="rh"><i class="fas fa-times rh-icon"></i> Uppercase letter</span>
          <span id="rh-lower" class="rh"><i class="fas fa-times rh-icon"></i> Lowercase letter</span>
          <span id="rh-num"   class="rh"><i class="fas fa-times rh-icon"></i> Number</span>
          <span id="rh-spec"  class="rh"><i class="fas fa-times rh-icon"></i> Special character</span>
        </div>
      </div>

      <div class="form-group" style="margin-bottom:0">
        <label for="resetConfirmPassword">Confirm New Password <span class="req">*</span></label>
        <div style="position:relative">
          <input class="form-control" id="resetConfirmPassword" type="password"
                 placeholder="Confirm new password" autocomplete="new-password"
                 oninput="checkResetMatch()">
          <button type="button" onclick="toggleResetField('resetConfirmPassword')"
                  style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                         background:none;border:none;cursor:pointer;color:#8a9a8b;font-size:1rem">
            <i class="fas fa-eye" id="resetConfirmPasswordEye"></i>
          </button>
        </div>
        <div id="resetMatchMsg" style="font-size:.77rem;margin-top:5px;display:none"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-cancel-modal" onclick="closeResetModal()">
        <i class="fas fa-times"></i> Cancel
      </button>
      <button class="btn btn-primary" onclick="submitReset()">
        <i class="fas fa-key"></i> Reset Password
      </button>
    </div>
  </div>
</div>

<style>
/* Password hint items inside reset modal */
.rh { color:#aaa; display:flex; align-items:center; gap:5px; transition:color .2s; }
.rh.valid { color:var(--g700); }
.rh.valid .rh-icon { }
</style>

<!-- Toast container -->
<div class="toast-stack" id="toastStack"></div>

<audio id="snd-success" src="sounds/success.mp3" preload="auto"></audio>
<audio id="snd-error"   src="sounds/error.wav"   preload="auto"></audio>

<!-- ═══════════════════════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════════════════════════ -->
<script>
/* ── State ──────────────────────────────────────────────────────────────────── */
const CSRF = <?= json_encode($csrf) ?>;
const ITEMS_PER_PAGE = 10;
let allUsers      = [];
let filteredUsers = [];
let currentPage   = 1;
let pendingDeleteId = null;

/* ── Tab switching ──────────────────────────────────────────────────────────── */
function switchTab(name, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  btn.classList.add('active');
}

/* ── Boot ───────────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  fetchUsers();
  document.getElementById('searchInput').addEventListener('input', applyFilters);
  document.getElementById('roleFilter').addEventListener('change', applyFilters);
  document.getElementById('btnExcelExport').addEventListener('click', exportExcel);
  document.getElementById('btnPdfExport').addEventListener('click', exportPDF);

  // Audit search
  document.getElementById('auditSearch').addEventListener('input', filterAudit);
  updateAuditCount();

  // Close modals on backdrop click
  ['editModal','deleteModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', e => {
      if (e.target === document.getElementById(id)) {
        id === 'editModal' ? closeEditModal() : closeDeleteModal();
      }
    });
  });

  // Keyboard escape
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeEditModal(); closeDeleteModal(); closeResetModal(); }
  });
});

/* ── Fetch users from API ──────────────────────────────────────────────────── */
function fetchUsers() {
  fetch('api/get_users.php')
    .then(r => { if (!r.ok) throw new Error('Network error'); return r.json(); })
    .then(data => {
      if (data.error) throw new Error(data.message || 'Unknown error');
      allUsers      = data;
      filteredUsers = [...allUsers];
      currentPage   = 1;
      renderTable();
      renderPagination();
      updateStats();
    })
    .catch(err => {
      showToast('Failed to load users: ' + err.message, 'error');
      document.getElementById('tableBody').innerHTML =
        `<tr><td colspan="6"><div class="empty-state">
           <i class="fas fa-exclamation-circle"></i>
           <p>Could not load users. Please refresh.</p>
         </div></td></tr>`;
    });
}

/* ── Render table ───────────────────────────────────────────────────────────── */
function renderTable() {
  const tbody = document.getElementById('tableBody');
  const start = (currentPage - 1) * ITEMS_PER_PAGE;
  const slice = filteredUsers.slice(start, start + ITEMS_PER_PAGE);

  document.getElementById('resultCount').textContent =
    filteredUsers.length + ' of ' + allUsers.length + ' users';

  if (slice.length === 0) {
    tbody.innerHTML = `<tr><td colspan="6">
      <div class="empty-state">
        <i class="fas fa-search"></i>
        <p>No users match your search.</p>
      </div></td></tr>`;
    return;
  }

  tbody.innerHTML = slice.map(u => {
    const badgeClass = 'badge-' + (u.role || '').toLowerCase().replace(/\s+/g, '-');
    // Escape for HTML context (safe text nodes)
    const safeName  = esc(u.name);
    const safeEmail = esc(u.email);
    const safeRole  = esc(u.role);
    // Escape for JS attribute context (data-* attrs used instead of inline onclick strings)
    return `<tr>
      <td style="color:#888;font-size:.82rem">${esc(String(u.id))}</td>
      <td class="user-name-cell">${safeName}</td>
      <td>${safeEmail}</td>
      <td><span class="badge ${badgeClass}">${safeRole}</span></td>
      <td style="font-size:.82rem;color:#666">${fmtDate(u.last_login)}</td>
      <td>
        <div class="action-cell">
          <button class="btn-icon bi-edit" title="Edit user"
            data-id="${u.id}" data-name="${encodeURIComponent(u.name)}"
            data-email="${encodeURIComponent(u.email)}" data-role="${encodeURIComponent(u.role)}"
            onclick="openEditModal(this)">
            <i class="fas fa-pen"></i>
          </button>
          <button class="btn-icon bi-reset" title="Reset password"
            data-id="${u.id}" data-name="${encodeURIComponent(u.name)}"
            onclick="openResetModal(this)">
            <i class="fas fa-key"></i>
          </button>
          <button class="btn-icon bi-delete" title="Delete user"
            data-id="${u.id}" data-name="${encodeURIComponent(u.name)}"
            onclick="openDeleteModal(this)">
            <i class="fas fa-trash-alt"></i>
          </button>
        </div>
      </td>
    </tr>`;
  }).join('');
}

/* ── Filters ────────────────────────────────────────────────────────────────── */
function applyFilters() {
  const term = document.getElementById('searchInput').value.toLowerCase().trim();
  const role = document.getElementById('roleFilter').value.toLowerCase();

  filteredUsers = allUsers.filter(u => {
    const matchTerm = !term ||
      u.name.toLowerCase().includes(term) ||
      u.email.toLowerCase().includes(term) ||
      u.role.toLowerCase().includes(term);
    const matchRole = !role || u.role.toLowerCase() === role;
    return matchTerm && matchRole;
  });

  currentPage = 1;
  renderTable();
  renderPagination();
}

/* ── Pagination ─────────────────────────────────────────────────────────────── */
function renderPagination() {
  const pageCount = Math.ceil(filteredUsers.length / ITEMS_PER_PAGE) || 1;
  const start = (currentPage - 1) * ITEMS_PER_PAGE + 1;
  const end   = Math.min(currentPage * ITEMS_PER_PAGE, filteredUsers.length);

  document.getElementById('pageInfo').textContent =
    filteredUsers.length ? `Showing ${start}–${end} of ${filteredUsers.length}` : '';

  const container = document.getElementById('pageBtns');
  let html = `<button class="page-btn" ${currentPage===1?'disabled':''} onclick="goPage(${currentPage-1})">
                <i class="fas fa-chevron-left"></i></button>`;

  const max = 5, s = Math.max(1, currentPage - 2), e = Math.min(pageCount, s + max - 1);
  for (let i = s; i <= e; i++) {
    html += `<button class="page-btn ${i===currentPage?'active':''}" onclick="goPage(${i})">${i}</button>`;
  }
  html += `<button class="page-btn" ${currentPage===pageCount?'disabled':''} onclick="goPage(${currentPage+1})">
             <i class="fas fa-chevron-right"></i></button>`;
  container.innerHTML = html;
}

function goPage(n) {
  currentPage = n;
  renderTable();
  renderPagination();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ── Stats pills ────────────────────────────────────────────────────────────── */
function updateStats() {
  const teachers = ['class teacher','subject teacher'];
  const otherStaff = allUsers.filter(u =>
    !['super user','developer'].includes(u.role) && !teachers.includes(u.role));

  document.getElementById('statTotal').textContent    = allUsers.length;
  document.getElementById('statSuperUser').textContent =
    allUsers.filter(u => u.role === 'super user').length;
  document.getElementById('statTeachers').textContent  =
    allUsers.filter(u => teachers.includes(u.role)).length;
  document.getElementById('statOther').textContent     = otherStaff.length;
}

/* ── Edit Modal ─────────────────────────────────────────────────────────────── */
function openEditModal(btn) {
  document.getElementById('editUserId').value  = btn.dataset.id;
  document.getElementById('editUsername').value = decodeURIComponent(btn.dataset.name);
  document.getElementById('editEmail').value    = decodeURIComponent(btn.dataset.email);
  document.getElementById('editRole').value     = decodeURIComponent(btn.dataset.role);
  document.getElementById('editModal').classList.add('active');
  document.getElementById('editEmail').focus();
}
function closeEditModal() {
  document.getElementById('editModal').classList.remove('active');
  document.getElementById('editLoader').classList.remove('show');
}
function submitEdit() {
  const id    = document.getElementById('editUserId').value;
  const email = document.getElementById('editEmail').value.trim();
  const role  = document.getElementById('editRole').value;

  if (!email) { showToast('Email address is required.', 'error'); return; }

  document.getElementById('editLoader').classList.add('show');

  const fd = new FormData();
  fd.append('action',     'update');
  fd.append('csrf_token', CSRF);
  fd.append('user_id',    id);
  fd.append('email',      email);
  fd.append('role',       role);

  fetch('user_roles.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      document.getElementById('editLoader').classList.remove('show');
      if (data.success) {
        showToast(data.message, 'success');
        closeEditModal();
        fetchUsers();
        refreshAuditLog();
      } else {
        showToast(data.message, 'error');
      }
    })
    .catch(err => {
      document.getElementById('editLoader').classList.remove('show');
      showToast('Network error: ' + err.message, 'error');
    });
}

/* ── Delete Modal ───────────────────────────────────────────────────────────── */
function openDeleteModal(btn) {
  pendingDeleteId = btn.dataset.id;
  document.getElementById('deleteUserName').textContent = decodeURIComponent(btn.dataset.name);
  document.getElementById('deleteModal').classList.add('active');
}
function closeDeleteModal() {
  document.getElementById('deleteModal').classList.remove('active');
  document.getElementById('deleteLoader').classList.remove('show');
  pendingDeleteId = null;
}
function confirmDelete() {
  if (!pendingDeleteId) return;

  document.getElementById('deleteLoader').classList.add('show');

  const fd = new FormData();
  fd.append('action',     'delete');
  fd.append('csrf_token', CSRF);
  fd.append('user_id',    pendingDeleteId);

  fetch('user_roles.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      document.getElementById('deleteLoader').classList.remove('show');
      if (data.success) {
        showToast(data.message, 'success');
        closeDeleteModal();
        fetchUsers();
        refreshAuditLog();
      } else {
        showToast(data.message, 'error');
      }
    })
    .catch(err => {
      document.getElementById('deleteLoader').classList.remove('show');
      showToast('Network error: ' + err.message, 'error');
    });
}

/* ── Audit log helpers ──────────────────────────────────────────────────────── */
function filterAudit() {
  const term = document.getElementById('auditSearch').value.toLowerCase();
  let vis = 0;
  document.querySelectorAll('.audit-row').forEach(r => {
    const match = !term || r.dataset.search.includes(term);
    r.style.display = match ? '' : 'none';
    if (match) vis++;
  });
  document.getElementById('auditCount').textContent =
    term ? vis + ' results' : '';
}

function updateAuditCount() {
  const rows = document.querySelectorAll('.audit-row').length;
  if (rows) document.getElementById('auditCount').textContent = rows + ' records';
}

function refreshAuditLog() {
  // Reload page silently in background to refresh audit rows
  // (lightweight: just fetch the page and extract the audit tbody)
  fetch(location.pathname)
    .then(r => r.text())
    .then(html => {
      const parser = new DOMParser();
      const doc    = parser.parseFromString(html, 'text/html');
      const fresh  = doc.getElementById('auditBody');
      if (fresh) {
        document.getElementById('auditBody').innerHTML = fresh.innerHTML;
        updateAuditCount();
        // Update tab badge count
        const rows = document.querySelectorAll('.audit-row').length;
        const tabBtn = document.querySelector('.tab-btn:nth-child(2)');
        if (tabBtn && rows) {
          const badge = tabBtn.querySelector('span');
          if (badge) badge.textContent = rows;
        }
      }
    })
    .catch(() => {}); // silent — audit is non-critical
}

/* ── Export ─────────────────────────────────────────────────────────────────── */
function getExportRows() {
  return filteredUsers.map(u => ({
    'User ID':    u.id,
    'Username':   u.name,
    'Email':      u.email,
    'Role':       u.role,
    'Last Login': u.last_login ? new Date(u.last_login).toLocaleString() : 'Never'
  }));
}

function exportExcel() {
  showToast('Preparing Excel export…', 'success');
  setTimeout(() => {
    try {
      const ws = XLSX.utils.json_to_sheet(getExportRows());
      const wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, 'Users');
      XLSX.writeFile(wb, 'school_pilot_users.xlsx');
      showToast('Excel exported successfully.', 'success');
    } catch (e) {
      showToast('Export failed: ' + e.message, 'error');
    }
  }, 300);
}

function exportPDF() {
  showToast('Preparing PDF export…', 'success');
  setTimeout(() => {
    try {
      const { jsPDF } = window.jspdf;
      const doc = new jsPDF();
      doc.setFontSize(16); doc.setTextColor(27, 94, 32);
      doc.text('User Roles & Permissions', 14, 18);
      doc.setFontSize(9); doc.setTextColor(120);
      doc.text('Generated: ' + new Date().toLocaleString(), 14, 25);
      doc.autoTable({
        head: [['ID','Username','Email','Role','Last Login']],
        body: getExportRows().map(r => [r['User ID'],r.Username,r.Email,r.Role,r['Last Login']]),
        startY: 30,
        headStyles: { fillColor:[46,125,50], textColor:255, fontStyle:'bold' },
        alternateRowStyles: { fillColor:[240,248,240] }
      });
      doc.save('school_pilot_users.pdf');
      showToast('PDF exported successfully.', 'success');
    } catch (e) {
      showToast('Export failed: ' + e.message, 'error');
    }
  }, 300);
}

/* ── Utilities ──────────────────────────────────────────────────────────────── */
function esc(str) {
  return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

function fmtDate(ds) {
  if (!ds) return '<span style="color:#bbb">Never</span>';
  const d = new Date(ds);
  if (isNaN(d)) return '<span style="color:#bbb">—</span>';
  return d.toLocaleString('en-GB', { day:'2-digit', month:'short', year:'numeric',
    hour:'2-digit', minute:'2-digit' });
}

function showToast(msg, type = 'success') {
  const id   = 'toast-' + Date.now();
  const icon = type === 'error' ? 'fa-circle-xmark' :
               type === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-check';
  const el   = document.createElement('div');
  el.className = `toast ${type}`;
  el.id = id;
  el.innerHTML = `
    <i class="fas ${icon} toast-icon"></i>
    <span class="toast-text">${esc(msg)}</span>
    <button class="toast-close" onclick="removeToast('${id}')">&times;</button>`;
  document.getElementById('toastStack').appendChild(el);
  // Play sound
  const snd = document.getElementById(type === 'error' ? 'snd-error' : 'snd-success');
  if (snd) snd.play().catch(() => {});
  setTimeout(() => removeToast(id), 5000);
}

function removeToast(id) {
  const el = document.getElementById(id);
  if (el) { el.style.opacity = '0'; el.style.transform = 'translateX(110%)';
    el.style.transition = '.3s'; setTimeout(() => el.remove(), 300); }
}

/* ── Admin Reset Password Modal ─────────────────────────────────────────────── */
function openResetModal(btn) {
  const name = decodeURIComponent(btn.dataset.name);
  document.getElementById('resetUserId').value = btn.dataset.id;
  document.getElementById('resetTargetName').textContent = name;
  document.getElementById('resetTargetNameInline').textContent = name;
  ['adminPassword','resetNewPassword','resetConfirmPassword'].forEach(function(id) {
    document.getElementById(id).value = '';
    document.getElementById(id).classList.remove('error','success');
  });
  document.getElementById('resetStrengthBar').style.width = '0';
  document.getElementById('resetMatchMsg').style.display = 'none';
  ['rh-len','rh-upper','rh-lower','rh-num','rh-spec'].forEach(function(id) {
    var el = document.getElementById(id);
    el.classList.remove('valid');
    el.querySelector('.rh-icon').className = 'fas fa-times rh-icon';
  });
  document.getElementById('resetModal').classList.add('active');
  setTimeout(function() { document.getElementById('adminPassword').focus(); }, 100);
}

function closeResetModal() {
  document.getElementById('resetModal').classList.remove('active');
  document.getElementById('resetLoader').classList.remove('show');
}

function toggleResetField(fieldId) {
  var f   = document.getElementById(fieldId);
  var eye = document.getElementById(fieldId + 'Eye');
  if (f.type === 'password') { f.type = 'text'; eye.classList.replace('fa-eye','fa-eye-slash'); }
  else                       { f.type = 'password'; eye.classList.replace('fa-eye-slash','fa-eye'); }
}

function checkResetStrength() {
  var pw  = document.getElementById('resetNewPassword').value;
  var bar = document.getElementById('resetStrengthBar');
  var checks = {
    'rh-len':   pw.length >= 8,
    'rh-upper': /[A-Z]/.test(pw),
    'rh-lower': /[a-z]/.test(pw),
    'rh-num':   /[0-9]/.test(pw),
    'rh-spec':  /[\W_]/.test(pw)
  };
  var passed = 0;
  Object.keys(checks).forEach(function(id) {
    var ok  = checks[id];
    var el  = document.getElementById(id);
    var ico = el.querySelector('.rh-icon');
    if (ok) { el.classList.add('valid'); ico.className = 'fas fa-check rh-icon'; passed++; }
    else    { el.classList.remove('valid'); ico.className = 'fas fa-times rh-icon'; }
  });
  var colors = ['','#ef4444','#f59e0b','#f59e0b','#22c55e','#1b5e20'];
  var widths  = ['0%','20%','40%','65%','85%','100%'];
  bar.style.background = colors[passed] || '';
  bar.style.width      = widths[passed] || '0%';
  checkResetMatch();
}

function checkResetMatch() {
  var np  = document.getElementById('resetNewPassword').value;
  var cp  = document.getElementById('resetConfirmPassword').value;
  var msg = document.getElementById('resetMatchMsg');
  if (!cp) { msg.style.display = 'none'; return; }
  msg.style.display = 'block';
  if (np === cp) {
    msg.innerHTML = '<i class="fas fa-check-circle" style="color:var(--g700)"></i> <span style="color:var(--g700)">Passwords match</span>';
  } else {
    msg.innerHTML = '<i class="fas fa-times-circle" style="color:#ef4444"></i> <span style="color:#ef4444">Passwords do not match</span>';
  }
}

function submitReset() {
  var id      = document.getElementById('resetUserId').value;
  var adminPw = document.getElementById('adminPassword').value;
  var newPw   = document.getElementById('resetNewPassword').value;
  var confPw  = document.getElementById('resetConfirmPassword').value;
  if (!adminPw) { showToast('Please enter your own password to confirm.', 'error'); return; }
  if (!newPw)   { showToast('Please enter a new password for the user.', 'error'); return; }
  if (newPw !== confPw) { showToast('New passwords do not match.', 'error'); return; }
  document.getElementById('resetLoader').classList.add('show');
  var fd = new FormData();
  fd.append('action',           'admin_reset_password');
  fd.append('csrf_token',       CSRF);
  fd.append('user_id',          id);
  fd.append('admin_password',   adminPw);
  fd.append('new_password',     newPw);
  fd.append('confirm_password', confPw);
  fetch('user_roles.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      document.getElementById('resetLoader').classList.remove('show');
      if (data.success) {
        showToast(data.message, 'success');
        closeResetModal();
        refreshAuditLog();
      } else {
        showToast(data.message, 'error');
      }
    })
    .catch(function(err) {
      document.getElementById('resetLoader').classList.remove('show');
      showToast('Network error: ' + err.message, 'error');
    });
}

document.addEventListener('DOMContentLoaded', function() {
  var rm = document.getElementById('resetModal');
  if (rm) rm.addEventListener('click', function(e) { if (e.target === rm) closeResetModal(); });
});

</script>

</body>
</html>
