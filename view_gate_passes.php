<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction('View Gate Passes');

// ── CSRF token ──────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── Fetch gate passes (prepared statement – no injection risk) ──
$gate_passes = [];
$stats = ['total' => 0, 'issued' => 0, 'returned' => 0, 'overdue' => 0, 'cancelled' => 0];

$sql = "SELECT id, reference_number, student_id, student_name, class, stream,
               departure_time, expected_return, destination, reason, priority,
               parent_contact, student_contact, accompanying_person,
               status, issued_by, issued_at
        FROM gate_passes
        ORDER BY issued_at DESC";

$result = mysqli_query($conn, $sql);
if ($result) {
    $now = time();
    while ($row = mysqli_fetch_assoc($result)) {
        $gate_passes[] = $row;
        $stats['total']++;

        $s = $row['status'];

        // Dynamically classify overdue: an "issued" pass whose expected_return
        // has already passed — regardless of whether the DB column was updated.
        if ($s === 'issued'
            && !empty($row['expected_return'])
            && strtotime($row['expected_return']) < $now) {
            $stats['overdue']++;
        } elseif (isset($stats[$s])) {
            $stats[$s]++;
        }
    }
    mysqli_free_result($result);
} else {
    // Log the error server-side; NEVER expose mysqli_error() to the browser
    error_log('Gate passes query failed: ' . mysqli_error($conn));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Gate Pass Registry &mdash; School Pilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
<style>
/* ── Variables (shared design-system) ───────────────────── */
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--orange:#e65100;--blue:#1565c0;--gray:#546e7a;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);
  --shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --transition:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}
a{color:inherit;text-decoration:none}

/* ── Layout ─────────────────────────────────────────────── */
.page{max-width:100%;margin:0 auto;padding:24px 20px 48px}

/* ── Page Header ────────────────────────────────────────── */
.page-header{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--radius-lg);padding:28px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;margin-bottom:24px;margin-top:40px;box-shadow:var(--shadow-lg)}
.page-header h1{color:#fff;font-size:1.55rem;font-weight:700;letter-spacing:.3px}
.page-header p{color:rgba(255,255,255,.78);font-size:.9rem;margin-top:3px}
.stats-row{display:flex;gap:12px;flex-wrap:wrap}
.stat-pill{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:40px;padding:8px 18px;text-align:center;min-width:80px;cursor:default;transition:background var(--transition)}
.stat-pill:hover{background:rgba(255,255,255,.22)}
.stat-pill .n{font-size:1.35rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.72rem;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.5px}

/* ── Card ───────────────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden}

/* ── Toolbar ────────────────────────────────────────────── */
.toolbar{padding:18px 24px;border-bottom:1px solid #e8ede9;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.toolbar-left{display:flex;flex-wrap:wrap;gap:10px;align-items:center;flex:1 1 auto}
.toolbar-right{display:flex;gap:10px;align-items:center;flex-shrink:0}
.search-wrap{position:relative;min-width:220px}
.search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.85rem}
.search-wrap input{width:100%;padding:9px 12px 9px 34px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;transition:border-color var(--transition),box-shadow var(--transition)}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.filter-select{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;background:#fff;cursor:pointer;min-width:130px;transition:border-color var(--transition)}
.filter-select:focus{outline:none;border-color:var(--g600)}
.result-count{font-size:.8rem;color:#6b7c6d;white-space:nowrap}

/* ── Buttons ────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all var(--transition);white-space:nowrap}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-pdf{background:#c62828;color:#fff}.btn-pdf:hover{background:var(--red)}
.btn-excel{background:var(--g800);color:#fff}.btn-excel:hover{background:var(--g900)}
.btn-danger-solid{background:var(--red);color:#fff}.btn-danger-solid:hover{background:#b71c1c}
.btn-success-solid{background:var(--g700);color:#fff}.btn-success-solid:hover{background:var(--g800)}
.btn-secondary{background:#546e7a;color:#fff}.btn-secondary:hover{background:#37474f}

/* ── Icon Buttons (table actions) ───────────────────────── */
.action-cell{display:flex;gap:5px;align-items:center;flex-wrap:nowrap}
.btn-icon{width:30px;height:30px;border:none;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;transition:all var(--transition);flex-shrink:0}
.btn-icon:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.18)}
.bi-view{background:#e3f2fd;color:#1565c0}.bi-view:hover{background:#1565c0;color:#fff}
.bi-edit{background:#fff3e0;color:#e65100}.bi-edit:hover{background:#e65100;color:#fff}
.bi-delete{background:#ffebee;color:var(--red)}.bi-delete:hover{background:var(--red);color:#fff}
.bi-print{background:#f3e5f5;color:#6a1b9a}.bi-print:hover{background:#6a1b9a;color:#fff}
.bi-return{background:#e8f5e9;color:var(--g700)}.bi-return:hover{background:var(--g700);color:#fff}

/* ── Table ──────────────────────────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:13px 14px;text-align:left;font-size:.8rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--transition)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:12px 14px;font-size:.875rem;vertical-align:middle}
.ref-num{font-family:monospace;font-size:.82rem;background:var(--g100);color:var(--g800);padding:3px 8px;border-radius:4px;font-weight:700}
.student-name{font-weight:600;color:var(--g800)}

/* ── Priority Indicators ────────────────────────────────── */
.priority-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px;vertical-align:middle}
.prio-normal .priority-dot{background:#43a047}
.prio-urgent .priority-dot{background:#e65100}
.prio-emergency .priority-dot{background:#d32f2f;animation:pulse 1.2s infinite}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(211,47,47,.4)}50%{box-shadow:0 0 0 5px rgba(211,47,47,0)}}

/* ── Status Badges ──────────────────────────────────────── */
.badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.badge-issued{background:#e3f2fd;color:#1565c0}
.badge-returned{background:#e8f5e9;color:#2e7d32}
.badge-overdue{background:#fff3e0;color:#e65100}
.badge-cancelled{background:#ffebee;color:#c62828}

/* ── Skeleton Rows ──────────────────────────────────────── */
.skeleton-cell{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;height:14px;display:inline-block;width:80%}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ── Empty State ────────────────────────────────────────── */
.empty-state{text-align:center;padding:60px 20px;color:#8a9a8b}
.empty-state i{font-size:3rem;margin-bottom:14px;display:block;opacity:.45}
.empty-state p{font-size:.95rem}

/* ── Pagination ─────────────────────────────────────────── */
.pagination{padding:16px 24px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e8ede9;flex-wrap:wrap;gap:10px}
.page-info{font-size:.82rem;color:#6b7c6d}
.page-btns{display:flex;gap:4px}
.page-btn{width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:6px;background:#fff;cursor:pointer;font-size:.82rem;font-weight:600;color:#444;display:flex;align-items:center;justify-content:center;transition:all var(--transition)}
.page-btn:hover:not(:disabled){border-color:var(--g600);background:var(--g100);color:var(--g800)}
.page-btn.active{background:var(--g700);border-color:var(--g700);color:#fff}
.page-btn:disabled{opacity:.38;cursor:default}

/* ── Modal ──────────────────────────────────────────────── */
.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);animation:fadeOverlay .2s ease}
@keyframes fadeOverlay{from{opacity:0}to{opacity:1}}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:820px;box-shadow:var(--shadow-lg);animation:slideDown .25s ease;margin:auto}
.modal-box.modal-sm{max-width:460px}
@keyframes slideDown{from{transform:translateY(-24px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{padding:20px 24px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head.green{background:linear-gradient(135deg,var(--g800) 0%,var(--g600) 100%)}
.modal-head.red{background:linear-gradient(135deg,#c62828,#e53935)}
.modal-head.teal{background:linear-gradient(135deg,#00695c,#00897b)}
.modal-head h2{color:#fff;font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background var(--transition)}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:28px;max-height:72vh;overflow-y:auto}
.modal-foot{padding:16px 24px;border-top:1px solid #e8ede9;display:flex;justify-content:flex-end;gap:10px;background:#fafafa;border-radius:0 0 var(--radius-lg) var(--radius-lg)}

/* ── Detail Grid (view modal) ───────────────────────────── */
.detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:18px}
.detail-item{background:#f8fbf8;border:1px solid #e8ede9;border-radius:var(--radius);padding:12px 14px}
.detail-item.full{grid-column:1/-1}
.detail-item strong{display:block;font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;color:#6b7c6d;margin-bottom:4px}
.detail-item span{font-size:.9rem;color:#222;font-weight:500}

/* ── Form Grid (edit modal) ─────────────────────────────── */
.form-section{margin-bottom:18px}
.form-section-title{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--g700);margin-bottom:10px;padding-bottom:6px;border-bottom:2px solid var(--g100)}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
.form-group label{font-size:.8rem;font-weight:600;color:#444}
.form-group input,.form-group select,.form-group textarea{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;transition:border-color var(--transition),box-shadow var(--transition)}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}

/* ── Confirm Dialog ─────────────────────────────────────── */
.dialog{display:none;position:fixed;inset:0;z-index:1100;background:rgba(0,0,0,.5);backdrop-filter:blur(2px)}
.dialog.active{display:flex;align-items:center;justify-content:center;padding:20px}
.dialog-box{background:#fff;border-radius:var(--radius-lg);max-width:420px;width:100%;box-shadow:var(--shadow-lg);animation:slideDown .2s ease;overflow:hidden}
.dialog-head{padding:18px 22px;display:flex;align-items:center;gap:12px}
.dialog-head.danger{background:#ffebee}.dialog-head.danger .dialog-icon{color:var(--red)}
.dialog-head.success{background:#e8f5e9}.dialog-head.success .dialog-icon{color:var(--g700)}
.dialog-icon{font-size:1.4rem}
.dialog-title{font-weight:700;font-size:1rem;color:#222}
.dialog-body{padding:16px 22px 10px;font-size:.9rem;color:#444;line-height:1.55}
.dialog-actions{padding:14px 22px 18px;display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}

/* ── Notification Stack ─────────────────────────────────── */
#notif-stack{position:fixed;top:20px;right:20px;z-index:2000;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.notif{display:flex;align-items:flex-start;gap:12px;background:#fff;border-radius:var(--radius);padding:14px 16px;box-shadow:0 4px 20px rgba(0,0,0,.14);min-width:280px;max-width:360px;border-left:4px solid;pointer-events:all;transition:opacity .3s,transform .3s;animation:slideInNotif .3s ease}
@keyframes slideInNotif{from{transform:translateX(40px);opacity:0}to{transform:translateX(0);opacity:1}}
.notif.success{border-color:var(--g600)}.notif.error{border-color:var(--red)}.notif.warning{border-color:#e65100}.notif.info{border-color:var(--blue)}
.notif-icon{font-size:1.1rem;margin-top:1px}.notif.success .notif-icon{color:var(--g600)}.notif.error .notif-icon{color:var(--red)}.notif.warning .notif-icon{color:#e65100}.notif.info .notif-icon{color:var(--blue)}
.notif-body{flex:1}.notif-title{font-weight:700;font-size:.85rem;color:#222}.notif-msg{font-size:.8rem;color:#555;margin-top:2px}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:.85rem;padding:0;line-height:1;align-self:flex-start}.notif-close:hover{color:#555}

@media(max-width:640px){
  .form-grid{grid-template-columns:1fr}
  .form-group.full{grid-column:1}
  .detail-grid{grid-template-columns:1fr}
  .detail-item.full{grid-column:1}
  .toolbar{flex-direction:column;align-items:stretch}
  .toolbar-right{flex-wrap:wrap}
}
</style>
</head>
<body>
<?php require_once 'nav.php'; ?>

<div id="notif-stack"></div>

<div class="page">

  <!-- ── Page Header ────────────────────────────────────── -->
  <div class="page-header">
    <div>
      <h1><i class="fas fa-id-badge" style="margin-right:10px;opacity:.85"></i>Gate Pass Registry</h1>
      <p>Track, manage and review all student gate passes</p>
    </div>
    <div class="stats-row">
      <div class="stat-pill">
        <span class="n" id="statTotal"><?= $stats['total'] ?></span>
        <span class="l">Total</span>
      </div>
      <div class="stat-pill">
        <span class="n" id="statIssued"><?= $stats['issued'] ?></span>
        <span class="l">Issued</span>
      </div>
      <div class="stat-pill">
        <span class="n" id="statOverdue"><?= $stats['overdue'] ?></span>
        <span class="l">Overdue</span>
      </div>
      <div class="stat-pill">
        <span class="n" id="statReturned"><?= $stats['returned'] ?></span>
        <span class="l">Returned</span>
      </div>
    </div>
  </div>

  <!-- ── Main Card ──────────────────────────────────────── -->
  <div class="card">

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-wrap">
          <i class="fas fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search passes…" autocomplete="off">
        </div>
        <select class="filter-select" id="filterStatus">
          <option value="">All Statuses</option>
          <option value="issued">Issued</option>
          <option value="returned">Returned</option>
          <option value="overdue">Overdue</option>
          <option value="cancelled">Cancelled</option>
        </select>
        <select class="filter-select" id="filterPriority">
          <option value="">All Priorities</option>
          <option value="normal">Normal</option>
          <option value="urgent">Urgent</option>
          <option value="emergency">Emergency</option>
        </select>
        <span class="result-count" id="resultCount"></span>
      </div>
      <div class="toolbar-right">
        <button class="btn btn-pdf" onclick="exportToPDF()"><i class="fas fa-file-pdf"></i> PDF</button>
        <button class="btn btn-excel" onclick="exportToExcel()"><i class="fas fa-file-excel"></i> Excel</button>
        <a class="btn btn-primary" href="issue_student_pass.php"><i class="fas fa-plus"></i> New Pass</a>
      </div>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Ref No.</th>
            <th>Student</th>
            <th>Class</th>
            <th>Priority</th>
            <th>Departure</th>
            <th>Exp. Return</th>
            <th>Status</th>
            <th>Issued By</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="passTableBody">
          <?php if (!empty($gate_passes)): ?>
            <?php foreach ($gate_passes as $pass): ?>
              <?php
                $statusClass = 'badge-' . htmlspecialchars($pass['status'], ENT_QUOTES, 'UTF-8');
                $prioClass   = 'prio-' . htmlspecialchars($pass['priority'], ENT_QUOTES, 'UTF-8');
                // Safely encode the whole pass object for JS — all HTML is escaped inside JSON
                $passJson = htmlspecialchars(json_encode($pass, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                $passId   = (int)$pass['id'];
              ?>
              <tr id="pass-row-<?= $passId ?>"
                  data-status="<?= htmlspecialchars($pass['status'], ENT_QUOTES, 'UTF-8') ?>"
                  data-priority="<?= htmlspecialchars($pass['priority'], ENT_QUOTES, 'UTF-8') ?>"
                  data-search="<?= htmlspecialchars(strtolower($pass['reference_number'].' '.$pass['student_name'].' '.$pass['class'].' '.$pass['stream'].' '.$pass['destination'].' '.$pass['issued_by']), ENT_QUOTES, 'UTF-8') ?>">
                <td><span class="ref-num"><?= htmlspecialchars($pass['reference_number'], ENT_QUOTES, 'UTF-8') ?></span></td>
                <td>
                  <span class="student-name"><?= htmlspecialchars($pass['student_name'], ENT_QUOTES, 'UTF-8') ?></span>
                  <div style="font-size:.75rem;color:#6b7c6d;margin-top:2px"><?= htmlspecialchars($pass['student_id'], ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td><?= htmlspecialchars($pass['class'] . ' ' . $pass['stream'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="<?= $prioClass ?>">
                  <span class="priority-dot"></span>
                  <?= htmlspecialchars(ucfirst($pass['priority']), ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($pass['departure_time'])), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= $pass['expected_return'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($pass['expected_return'])), ENT_QUOTES, 'UTF-8') : '<span style="color:#aaa">—</span>' ?></td>
                <td><span class="badge <?= $statusClass ?>"><?= htmlspecialchars(ucfirst($pass['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><?= htmlspecialchars($pass['issued_by'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <div class="action-cell">
                    <button class="btn-icon bi-view" title="View Details"
                            onclick="viewDetails(<?= $passJson ?>)">
                      <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn-icon bi-edit" title="Edit Pass"
                            onclick="openEditModal(<?= $passJson ?>)">
                      <i class="fas fa-pencil-alt"></i>
                    </button>
                    <button class="btn-icon bi-print" title="Print Pass"
                            onclick="printPass(<?= $passId ?>)">
                      <i class="fas fa-print"></i>
                    </button>
                    <?php if (in_array($pass['status'], ['issued', 'overdue'], true)): ?>
                      <button class="btn-icon bi-return" title="Mark as Returned"
                              onclick="confirmReturn(<?= $passId ?>, '<?= htmlspecialchars($pass['reference_number'], ENT_QUOTES, 'UTF-8') ?>')">
                        <i class="fas fa-check-circle"></i>
                      </button>
                    <?php endif; ?>
                    <button class="btn-icon bi-delete" title="Delete Pass"
                            onclick="confirmDelete(<?= $passId ?>, '<?= htmlspecialchars($pass['reference_number'], ENT_QUOTES, 'UTF-8') ?>')">
                      <i class="fas fa-trash-alt"></i>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <div id="emptyState" class="empty-state" style="display:none">
        <i class="fas fa-ticket-alt"></i>
        <p>No gate passes match your search.</p>
      </div>
    </div>

    <!-- Pagination -->
    <div class="pagination">
      <span class="page-info" id="pageInfo"></span>
      <div class="page-btns" id="pageBtns"></div>
    </div>
  </div><!-- /.card -->

</div><!-- /.page -->

<!-- ══════════════════════════════════════════════════════════
     VIEW DETAILS MODAL
═══════════════════════════════════════════════════════════ -->
<div class="modal" id="viewModal" onclick="modalOutsideClick(event,'viewModal')">
  <div class="modal-box">
    <div class="modal-head green">
      <h2><i class="fas fa-id-badge"></i> <span id="viewModalTitle">Gate Pass Details</span></h2>
      <button class="modal-close" onclick="closeModal('viewModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body" id="viewModalBody"></div>
    <div class="modal-foot">
      <button class="btn btn-outline" onclick="closeModal('viewModal')">Close</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     EDIT MODAL
═══════════════════════════════════════════════════════════ -->
<div class="modal" id="editModal" onclick="modalOutsideClick(event,'editModal')">
  <div class="modal-box">
    <div class="modal-head green">
      <h2><i class="fas fa-pencil-alt"></i> Edit Gate Pass</h2>
      <button class="modal-close" type="button" onclick="closeModal('editModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="editPassId">
      <input type="hidden" id="editCsrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

      <div class="form-section">
        <div class="form-section-title">Schedule</div>
        <div class="form-grid">
          <div class="form-group">
            <label for="editDeparture">Departure Time</label>
            <input type="datetime-local" id="editDeparture" required>
          </div>
          <div class="form-group">
            <label for="editReturn">Expected Return</label>
            <input type="datetime-local" id="editReturn">
          </div>
          <div class="form-group full">
            <label for="editDestination">Destination</label>
            <input type="text" id="editDestination" maxlength="255" required>
          </div>
          <div class="form-group full">
            <label for="editReason">Reason for Leaving</label>
            <textarea id="editReason" rows="3" maxlength="1000" required></textarea>
          </div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">Classification</div>
        <div class="form-grid">
          <div class="form-group">
            <label for="editPriority">Priority</label>
            <select id="editPriority" required>
              <option value="normal">Normal</option>
              <option value="urgent">Urgent</option>
              <option value="emergency">Emergency</option>
            </select>
          </div>
          <div class="form-group">
            <label for="editStatus">Status</label>
            <select id="editStatus" required>
              <option value="issued">Issued</option>
              <option value="returned">Returned</option>
              <option value="overdue">Overdue</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-title">Contacts &amp; Escort</div>
        <div class="form-grid">
          <div class="form-group">
            <label for="editParentContact">Parent Contact</label>
            <input type="tel" id="editParentContact" maxlength="20">
          </div>
          <div class="form-group">
            <label for="editStudentContact">Student Contact</label>
            <input type="tel" id="editStudentContact" maxlength="20">
          </div>
          <div class="form-group full">
            <label for="editAccompanying">Accompanying Person</label>
            <input type="text" id="editAccompanying" maxlength="150">
          </div>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="submitEdit()"><i class="fas fa-save"></i> Save Changes</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     CONFIRM DIALOG (shared for delete & return)
═══════════════════════════════════════════════════════════ -->
<div class="dialog" id="confirmDlg">
  <div class="dialog-box">
    <div class="dialog-head" id="dlgHead">
      <i class="dialog-icon" id="dlgIcon"></i>
      <span class="dialog-title" id="dlgTitle"></span>
    </div>
    <div class="dialog-body" id="dlgMsg"></div>
    <div class="dialog-actions">
      <button class="btn btn-outline" onclick="closeDlg()">Cancel</button>
      <button class="btn" id="dlgConfirmBtn" onclick="runDlgCb()">Confirm</button>
    </div>
  </div>
</div>

<script>
// ── Constants ──────────────────────────────────────────────
const CSRF = <?= json_encode($csrf) ?>;

// ── Raw data from PHP (already JSON-encoded safely) ────────
const ALL_PASSES = <?= json_encode($gate_passes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

// ── Pagination state ───────────────────────────────────────
const PAGE_SIZE = 25;
let currentPage = 1;
let filtered = [];
let dlgCb = null;

// ── Boot ───────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    filtered = ALL_PASSES.slice();
    applyFilters();

    document.getElementById('searchInput').addEventListener('input', applyFilters);
    document.getElementById('filterStatus').addEventListener('change', applyFilters);
    document.getElementById('filterPriority').addEventListener('change', applyFilters);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAllModals(); });
});

// ── Filter & Render ────────────────────────────────────────
function applyFilters() {
    const q      = document.getElementById('searchInput').value.toLowerCase().trim();
    const status = document.getElementById('filterStatus').value;
    const prio   = document.getElementById('filterPriority').value;

    filtered = ALL_PASSES.filter(p => {
        const matchQ = !q || [p.reference_number, p.student_name, p.student_id, p.class,
                               p.stream, p.destination, p.issued_by, p.reason]
                             .some(v => String(v||'').toLowerCase().includes(q));
        const matchS = !status || p.status === status;
        const matchP = !prio   || p.priority === prio;
        return matchQ && matchS && matchP;
    });

    currentPage = 1;
    renderTable();
}

function renderTable() {
    const tbody = document.getElementById('passTableBody');
    const rows  = Array.from(tbody.querySelectorAll('tr[id^="pass-row-"]'));

    // Hide all rows first
    rows.forEach(r => r.style.display = 'none');

    const start = (currentPage - 1) * PAGE_SIZE;
    const page  = filtered.slice(start, start + PAGE_SIZE);

    // Show only rows that belong to the current page of filtered results
    page.forEach(p => {
        const row = document.getElementById('pass-row-' + p.id);
        if (row) row.style.display = '';
    });

    document.getElementById('resultCount').textContent =
        filtered.length + ' record' + (filtered.length !== 1 ? 's' : '');

    const empty = document.getElementById('emptyState');
    empty.style.display = filtered.length === 0 ? '' : 'none';

    renderPagination();
}

// ── Live stat pills ────────────────────────────────────────
// Mirrors the PHP logic: an issued pass past its expected_return is overdue.
function recalcStats() {
    const now = Date.now();
    let total = ALL_PASSES.length, issued = 0, returned = 0, overdue = 0;

    ALL_PASSES.forEach(p => {
        if (p.status === 'returned') {
            returned++;
        } else if (p.status === 'issued') {
            if (p.expected_return && new Date(p.expected_return).getTime() < now) {
                overdue++;
            } else {
                issued++;
            }
        }
        // cancelled passes are counted in total only (same as PHP)
    });

    document.getElementById('statTotal').textContent    = total;
    document.getElementById('statIssued').textContent   = issued;
    document.getElementById('statOverdue').textContent  = overdue;
    document.getElementById('statReturned').textContent = returned;
}

// ── Pagination ─────────────────────────────────────────────
function renderPagination() {
    const total = Math.ceil(filtered.length / PAGE_SIZE) || 1;
    const start = Math.min((currentPage - 1) * PAGE_SIZE + 1, filtered.length);
    const end   = Math.min(currentPage * PAGE_SIZE, filtered.length);

    document.getElementById('pageInfo').textContent =
        filtered.length ? `Showing ${start}–${end} of ${filtered.length}` : 'No records';

    const container = document.getElementById('pageBtns');
    container.innerHTML = '';

    const prev = btn('<i class="fas fa-chevron-left"></i>', currentPage <= 1, () => goPage(currentPage - 1));
    container.appendChild(prev);

    const range = pageRange(currentPage, total);
    range.forEach(item => {
        if (item === '…') {
            const span = document.createElement('span');
            span.textContent = '…';
            span.style.cssText = 'padding:0 6px;display:flex;align-items:center;color:#888;font-size:.82rem';
            container.appendChild(span);
        } else {
            container.appendChild(btn(item, false, () => goPage(item), item === currentPage));
        }
    });

    const next = btn('<i class="fas fa-chevron-right"></i>', currentPage >= total, () => goPage(currentPage + 1));
    container.appendChild(next);
}

function btn(html, disabled, cb, active = false) {
    const b = document.createElement('button');
    b.className = 'page-btn' + (active ? ' active' : '');
    b.innerHTML = html;
    b.disabled  = disabled;
    b.addEventListener('click', cb);
    return b;
}

function goPage(n) { currentPage = n; renderTable(); }

function pageRange(cur, total) {
    if (total <= 7) return Array.from({length: total}, (_, i) => i + 1);
    if (cur <= 4)   return [1,2,3,4,5,'…',total];
    if (cur >= total - 3) return [1,'…',total-4,total-3,total-2,total-1,total];
    return [1,'…',cur-1,cur,cur+1,'…',total];
}

// ── View Details ───────────────────────────────────────────
function viewDetails(p) {
    document.getElementById('viewModalTitle').textContent = 'Pass ' + esc(p.reference_number);
    document.getElementById('viewModalBody').innerHTML = `
      <div class="detail-grid">
        <div class="detail-item"><strong>Reference</strong><span>${esc(p.reference_number)}</span></div>
        <div class="detail-item"><strong>Student Name</strong><span>${esc(p.student_name)}</span></div>
        <div class="detail-item"><strong>Student ID</strong><span>${esc(p.student_id)}</span></div>
        <div class="detail-item"><strong>Class &amp; Stream</strong><span>${esc(p.class)} ${esc(p.stream)}</span></div>
        <div class="detail-item"><strong>Status</strong><span><span class="badge badge-${esc(p.status)}">${esc(ucf(p.status))}</span></span></div>
        <div class="detail-item"><strong>Priority</strong><span>${esc(ucf(p.priority))}</span></div>
        <div class="detail-item"><strong>Departure</strong><span>${fmtDate(p.departure_time)}</span></div>
        <div class="detail-item"><strong>Expected Return</strong><span>${p.expected_return ? fmtDate(p.expected_return) : '—'}</span></div>
        <div class="detail-item full"><strong>Destination</strong><span>${esc(p.destination)}</span></div>
        <div class="detail-item full"><strong>Reason for Leaving</strong><span>${esc(p.reason)}</span></div>
        <div class="detail-item"><strong>Parent Contact</strong><span>${esc(p.parent_contact||'—')}</span></div>
        <div class="detail-item"><strong>Student Contact</strong><span>${esc(p.student_contact||'—')}</span></div>
        <div class="detail-item full"><strong>Accompanying Person</strong><span>${esc(p.accompanying_person||'—')}</span></div>
        <div class="detail-item full"><strong>Issued By</strong><span>${esc(p.issued_by)} &mdash; ${fmtDate(p.issued_at)}</span></div>
      </div>`;
    openModal('viewModal');
}

// ── Edit Modal ─────────────────────────────────────────────
function openEditModal(p) {
    document.getElementById('editPassId').value          = p.id;
    document.getElementById('editDeparture').value       = fmtForInput(p.departure_time);
    document.getElementById('editReturn').value          = fmtForInput(p.expected_return);
    document.getElementById('editDestination').value     = p.destination   || '';
    document.getElementById('editReason').value          = p.reason        || '';
    document.getElementById('editPriority').value        = p.priority      || 'normal';
    document.getElementById('editStatus').value          = p.status        || 'issued';
    document.getElementById('editParentContact').value   = p.parent_contact   || '';
    document.getElementById('editStudentContact').value  = p.student_contact  || '';
    document.getElementById('editAccompanying').value    = p.accompanying_person || '';
    openModal('editModal');
}

function submitEdit() {
    const id = document.getElementById('editPassId').value;
    if (!id) return;

    const departure = document.getElementById('editDeparture').value;
    const dest      = document.getElementById('editDestination').value.trim();
    const reason    = document.getElementById('editReason').value.trim();

    if (!departure || !dest || !reason) {
        notify('Validation', 'Departure, destination and reason are required.', 'warning');
        return;
    }

    const payload = {
        action:               'update',
        id,
        csrf_token:           CSRF,
        departure_time:       departure,
        expected_return:      document.getElementById('editReturn').value,
        destination:          dest,
        reason:               reason,
        priority:             document.getElementById('editPriority').value,
        status:               document.getElementById('editStatus').value,
        parent_contact:       document.getElementById('editParentContact').value,
        student_contact:      document.getElementById('editStudentContact').value,
        accompanying_person:  document.getElementById('editAccompanying').value,
    };

    fetch('api/update_gate_pass.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload),
    })
    .then(r => r.json())
    .then(d => {
        if (!d.success) throw new Error(d.error || 'Update failed');
        notify('Updated', 'Gate pass updated successfully.', 'success');
        closeModal('editModal');
        // Update in-memory data and re-render without full reload
        const idx = ALL_PASSES.findIndex(p => String(p.id) === String(id));
        if (idx > -1) {
            ALL_PASSES[idx] = Object.assign(ALL_PASSES[idx], {
                departure_time:      payload.departure_time,
                expected_return:     payload.expected_return,
                destination:         payload.destination,
                reason:              payload.reason,
                priority:            payload.priority,
                status:              payload.status,
                parent_contact:      payload.parent_contact,
                student_contact:     payload.student_contact,
                accompanying_person: payload.accompanying_person,
            });
        }
        // Reload to sync server state cleanly
        setTimeout(() => location.reload(), 1200);
    })
    .catch(err => notify('Error', err.message, 'error'));
}

// ── Print ──────────────────────────────────────────────────
function printPass(passId) {
    window.open('api/print_gate_pass.php?id=' + encodeURIComponent(passId), '_blank');
}

// ── Confirm Return ─────────────────────────────────────────
function confirmReturn(passId, refNum) {
    showDlg('success', 'fas fa-check-circle', 'Confirm Return',
        `Mark pass <strong>${esc(refNum)}</strong> as returned?`,
        'btn-success-solid', 'Mark Returned',
        () => doReturn(passId));
}

function doReturn(passId) {
    fetch('api/mark_as_returned.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ id: passId, csrf_token: CSRF }),
    })
    .then(r => r.json())
    .then(d => {
        if (!d.success) throw new Error(d.error || 'Failed');
        notify('Returned', 'Pass marked as returned.', 'success');
        const row = document.getElementById('pass-row-' + passId);
        if (row) {
            const badge = row.querySelector('.badge');
            if (badge) { badge.className = 'badge badge-returned'; badge.textContent = 'Returned'; }
            row.querySelector('.bi-return')?.remove();
            // Update data
            const p = ALL_PASSES.find(x => x.id == passId);
            if (p) p.status = 'returned';
        }
        recalcStats();
    })
    .catch(err => notify('Error', err.message, 'error'));
}

// ── Confirm Delete ─────────────────────────────────────────
function confirmDelete(passId, refNum) {
    showDlg('danger', 'fas fa-exclamation-triangle', 'Delete Gate Pass',
        `Permanently delete pass <strong>${esc(refNum)}</strong>? This cannot be undone.`,
        'btn-danger-solid', 'Delete',
        () => doDelete(passId));
}

function doDelete(passId) {
    fetch('api/delete_gate_pass.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ id: passId, csrf_token: CSRF }),
    })
    .then(r => r.json())
    .then(d => {
        if (!d.success) throw new Error(d.error || 'Delete failed');
        notify('Deleted', 'Gate pass removed.', 'success');
        const row = document.getElementById('pass-row-' + passId);
        if (row) row.remove();
        // Remove from in-memory array and re-filter
        const idx = ALL_PASSES.findIndex(p => p.id == passId);
        if (idx > -1) ALL_PASSES.splice(idx, 1);
        recalcStats();
        applyFilters();
    })
    .catch(err => notify('Error', err.message, 'error'));
}

// ── Confirm Dialog ─────────────────────────────────────────
function showDlg(type, icon, title, msg, btnClass, btnLabel, cb) {
    document.getElementById('dlgHead').className = `dialog-head ${type}`;
    document.getElementById('dlgIcon').className = icon;
    document.getElementById('dlgTitle').textContent = title;
    document.getElementById('dlgMsg').innerHTML = msg;
    const btn = document.getElementById('dlgConfirmBtn');
    btn.className = 'btn ' + btnClass;
    btn.textContent = btnLabel;
    dlgCb = cb;
    document.getElementById('confirmDlg').classList.add('active');
}
function closeDlg()  { document.getElementById('confirmDlg').classList.remove('active'); dlgCb = null; }
function runDlgCb()  { if (dlgCb) dlgCb(); closeDlg(); }

// ── Modal helpers ──────────────────────────────────────────
function openModal(id)    { document.getElementById(id).classList.add('active'); }
function closeModal(id)   { document.getElementById(id).classList.remove('active'); }
function closeAllModals() { document.querySelectorAll('.modal.active,.dialog.active').forEach(m => m.classList.remove('active')); }
function modalOutsideClick(e, id) { if (e.target.id === id) closeModal(id); }

// ── Notifications ──────────────────────────────────────────
function notify(title, msg, type = 'success', dur = 4000) {
    const icons = {success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
    const n = document.createElement('div');
    n.className = `notif ${type}`;
    n.innerHTML = `
      <i class="fas ${icons[type]||icons.info} notif-icon"></i>
      <div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div>
      <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('notif-stack').prepend(n);
    setTimeout(() => { n.style.opacity='0'; n.style.transform='translateX(30px)'; n.style.transition='.3s'; setTimeout(()=>n.remove(),300); }, dur);
}

// ── Export PDF ─────────────────────────────────────────────
function exportToPDF() {
    if (!filtered.length) { notify('Empty', 'No records to export.', 'warning'); return; }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');
    doc.setFontSize(16); doc.setTextColor(46,125,50);
    doc.text('Gate Pass Registry Report', 14, 18);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text('Generated: ' + new Date().toLocaleDateString('en-UG'), 14, 25);
    doc.autoTable({
        head: [['Ref No.','Student','Class','Priority','Departure','Status','Issued By']],
        body: filtered.map(p => [
            p.reference_number, p.student_name, p.class+' '+p.stream,
            ucf(p.priority), fmtDate(p.departure_time), ucf(p.status), p.issued_by
        ]),
        startY: 30, theme: 'grid',
        headStyles: {fillColor:[67,160,71], fontSize:8},
        bodyStyles: {fontSize:7.5},
    });
    doc.save('gate-passes-' + datestamp() + '.pdf');
    notify('Exported', 'PDF report downloaded.', 'success');
}

// ── Export Excel ───────────────────────────────────────────
function exportToExcel() {
    if (!filtered.length) { notify('Empty', 'No records to export.', 'warning'); return; }
    const data = [['Ref No.','Student ID','Student Name','Class','Stream','Priority',
                   'Departure','Expected Return','Destination','Reason','Status',
                   'Parent Contact','Student Contact','Accompanying Person','Issued By','Issued At']];
    filtered.forEach(p => data.push([
        p.reference_number, p.student_id, p.student_name, p.class, p.stream,
        ucf(p.priority), p.departure_time, p.expected_return||'', p.destination, p.reason,
        ucf(p.status), p.parent_contact||'', p.student_contact||'', p.accompanying_person||'',
        p.issued_by, p.issued_at
    ]));
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = [{wch:16},{wch:13},{wch:20},{wch:8},{wch:10},{wch:10},{wch:18},{wch:18},
                   {wch:24},{wch:32},{wch:12},{wch:16},{wch:16},{wch:22},{wch:18},{wch:18}];
    XLSX.utils.book_append_sheet(wb, ws, 'Gate Passes');
    XLSX.writeFile(wb, 'gate-passes-' + datestamp() + '.xlsx');
    notify('Exported', 'Excel file downloaded.', 'success');
}

// ── Utilities ──────────────────────────────────────────────
function esc(v) {
    return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function ucf(v) { return v ? v.charAt(0).toUpperCase() + v.slice(1) : ''; }
function fmtDate(d) {
    if (!d) return '—';
    try { return new Date(d).toLocaleString('en-UG', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}); }
    catch(_) { return d; }
}
function fmtForInput(d) {
    if (!d) return '';
    try {
        const dt = new Date(d);
        return new Date(dt.getTime() - dt.getTimezoneOffset()*60000).toISOString().slice(0,16);
    } catch(_) { return ''; }
}
function datestamp() { return new Date().toISOString().split('T')[0]; }
</script>
</body>
</html>