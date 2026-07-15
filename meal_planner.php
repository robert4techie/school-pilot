<?php
/**
 * Meal Planner — Weekly Calendar View
 * Production-grade: JSON API, CSRF, prepared statements, copy-week, nutrition summary.
 */
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction('Meal Planner');

/* ── Security Headers ───────────────────────────────────────────────── */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

/* ── Ensure mysqli throws exceptions on errors ──────────────────────── */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* ── JSON API ───────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], (string)($body['csrf_token'] ?? ''))) {
        http_response_code(403); echo json_encode(['success'=>false,'error'=>'Invalid CSRF token.']); exit;
    }

    switch ($action) {
        case 'get_week':      echo json_encode(apiGetWeek($conn, $body));    break;
        case 'add':           echo json_encode(apiSave($conn, $body));       break;
        case 'delete':        echo json_encode(apiDelete($conn, $body));     break;
        case 'copy_week':     echo json_encode(apiCopyWeek($conn, $body));   break;
        case 'shopping_list': echo json_encode(apiShoppingList($conn,$body));break;
        default: http_response_code(400); echo json_encode(['success'=>false,'error'=>'Unknown action.']);
    }
    exit;
}

/* ════════════════════════════════════════════════════════════════════
   API
   ════════════════════════════════════════════════════════════════════ */

function apiGetWeek(mysqli $conn, array $p): array
{
    $weekStart = trim($p['week_start'] ?? '');
    if (!$weekStart || !strtotime($weekStart)) return ['success'=>false,'error'=>'Invalid week start date.'];

    $stmt = $conn->prepare("
        SELECT * FROM meals
         WHERE week_start_date = ?
         ORDER BY FIELD(day_of_week,'monday','tuesday','wednesday','thursday','friday','saturday','sunday'),
                  FIELD(meal_type,'breakfast','lunch','dinner','snack')
    ");
    $stmt->bind_param('s', $weekStart);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /* week summary stats */
    $totStmt = $conn->prepare("
        SELECT
            COUNT(*)        AS total_meals,
            SUM(calories)   AS total_calories,
            SUM(cost)       AS total_cost,
            AVG(protein)    AS avg_protein,
            AVG(carbs)      AS avg_carbs,
            AVG(fat)        AS avg_fat
        FROM meals WHERE week_start_date = ?
    ");
    $totStmt->bind_param('s', $weekStart);
    $totStmt->execute();
    $summary = $totStmt->get_result()->fetch_assoc();
    $totStmt->close();

    return ['success'=>true,'data'=>$rows,'summary'=>$summary];
}

function apiSave(mysqli $conn, array $p): array
{
    $day       = trim($p['day_of_week']    ?? '');
    $mealType  = trim($p['meal_type']      ?? '');
    $mealName  = trim($p['meal_name']      ?? '');
    $desc      = trim($p['description']    ?? '');
    $calories  = (int)($p['calories']      ?? 0);
    $cost      = (float)($p['cost']        ?? 0);
    $protein   = (float)($p['protein']     ?? 0);
    $carbs     = (float)($p['carbs']       ?? 0);
    $fat       = (float)($p['fat']         ?? 0);
    $fiber     = (float)($p['fiber']       ?? 0);
    $weekStart = trim($p['week_start']     ?? '');

    $validDays  = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
    $validTypes = ['breakfast','lunch','dinner','snack'];

    $errors = [];
    if (!in_array($day, $validDays, true))    $errors[] = 'Invalid day.';
    if (!in_array($mealType, $validTypes, true)) $errors[] = 'Invalid meal type.';
    if ($mealName === '')                     $errors[] = 'Meal name is required.';
    if (strlen($mealName) > 255)              $errors[] = 'Meal name too long.';
    if (!$weekStart || !strtotime($weekStart)) $errors[] = 'Invalid week start date.';
    if ($errors) return ['success'=>false,'error'=>implode(' ',$errors)];

    $stmt = $conn->prepare("
        INSERT INTO meals
            (day_of_week, meal_type, meal_name, description, calories, cost, protein, carbs, fat, fiber, week_start_date)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->bind_param('ssssdddddds',
        $day, $mealType, $mealName, $desc,
        $calories, $cost, $protein, $carbs, $fat, $fiber, $weekStart
    );
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    return $newId > 0
        ? ['success'=>true,'message'=>"'{$mealName}' added to {$day} {$mealType}."]
        : ['success'=>false,'error'=>'Failed to add meal.'];
}

function apiDelete(mysqli $conn, array $p): array
{
    $id = (int)($p['meal_id'] ?? 0);
    if (!$id) return ['success'=>false,'error'=>'Invalid ID.'];

    $stmt = $conn->prepare('DELETE FROM meals WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    return $affected > 0
        ? ['success'=>true,'message'=>'Meal removed.']
        : ['success'=>false,'error'=>'Meal not found.'];
}

function apiCopyWeek(mysqli $conn, array $p): array
{
    $src = trim($p['source_week'] ?? '');
    $dst = trim($p['target_week'] ?? '');
    if (!$src||!strtotime($src)||!$dst||!strtotime($dst)) return ['success'=>false,'error'=>'Invalid week dates.'];
    if ($src === $dst) return ['success'=>false,'error'=>'Source and target weeks must be different.'];

    $conn->begin_transaction();
    try {
        /* delete existing meals in target week first */
        $del = $conn->prepare('DELETE FROM meals WHERE week_start_date = ?');
        $del->bind_param('s', $dst); $del->execute(); $del->close();

        /* copy from source */
        $sel = $conn->prepare('SELECT * FROM meals WHERE week_start_date = ?');
        $sel->bind_param('s', $src); $sel->execute();
        $rows = $sel->get_result()->fetch_all(MYSQLI_ASSOC);
        $sel->close();

        if (empty($rows)) { $conn->rollback(); return ['success'=>false,'error'=>'Source week has no meals to copy.']; }

        $ins = $conn->prepare("
            INSERT INTO meals (day_of_week,meal_type,meal_name,description,calories,cost,protein,carbs,fat,fiber,week_start_date)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        foreach ($rows as $row) {
            $ins->bind_param('ssssdddddds',
                $row['day_of_week'],$row['meal_type'],$row['meal_name'],$row['description'],
                $row['calories'],$row['cost'],$row['protein'],$row['carbs'],$row['fat'],$row['fiber'],$dst
            );
            $ins->execute();
        }
        $ins->close();
        $conn->commit();
        return ['success'=>true,'message'=>count($rows).' meal(s) copied to the target week.'];
    } catch (\Throwable $e) {
        $conn->rollback();
        return ['success'=>false,'error'=>$e->getMessage()];
    }
}

function apiShoppingList(mysqli $conn, array $p): array
{
    $weekStart = trim($p['week_start'] ?? '');
    if (!$weekStart) return ['success'=>false,'error'=>'Invalid week.'];

    $stmt = $conn->prepare("
        SELECT m.meal_name, m.day_of_week, m.meal_type,
               fi.item_name, fi.quantity, fi.unit, fi.category
          FROM meals m
     LEFT JOIN food_inventory fi ON fi.item_name LIKE CONCAT('%', m.meal_name, '%')
         WHERE m.week_start_date = ?
         ORDER BY fi.category, fi.item_name
    ");
    $stmt->bind_param('s', $weekStart);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return ['success'=>true,'data'=>$rows];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Meal Planner &mdash; School Pilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js" defer></script>
<style>
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
.page{max-width:100%;margin:0 auto;padding:24px 20px 48px}

/* ── Page Header ─────────────────────────────────────────────────── */
.page-header{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--radius-lg);padding:28px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;margin-bottom:24px;margin-top:40px;box-shadow:var(--shadow-lg)}
.page-header h1{color:#fff;font-size:1.55rem;font-weight:700}
.page-header p{color:rgba(255,255,255,.78);font-size:.9rem;margin-top:3px}
.header-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}

/* ── Week Navigator ──────────────────────────────────────────────── */
.week-nav{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:20px}
.week-nav-center{display:flex;align-items:center;gap:12px}
.week-label{font-weight:700;font-size:1.05rem;color:var(--g800);min-width:260px;text-align:center}
.week-nav-actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all var(--transition);white-space:nowrap}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active,.btn:disabled{transform:none;opacity:.6}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{background:#f5f5f5;transform:none}
.btn-ghost{background:var(--g100);color:var(--g800);border:none}.btn-ghost:hover{background:var(--g400);color:#fff}
.btn-pdf{background:#c62828;color:#fff}.btn-pdf:hover{background:var(--red)}
.btn-excel{background:var(--g800);color:#fff}.btn-excel:hover{background:var(--g900)}
.btn-sm{padding:6px 10px;font-size:.78rem}
.btn-nav{width:36px;height:36px;padding:0;justify-content:center;background:var(--g100);color:var(--g800);border:1.5px solid #c8e6c9}.btn-nav:hover{background:var(--g700);color:#fff;border-color:var(--g700)}

/* ── Summary Stats Row ───────────────────────────────────────────── */
.summary-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:20px}
.summary-card{background:#fff;border-radius:var(--radius-lg);padding:16px 20px;box-shadow:var(--shadow);border-left:4px solid var(--g600)}
.summary-card .s-val{font-size:1.6rem;font-weight:700;color:var(--g800)}
.summary-card .s-label{font-size:.75rem;color:#8a9a8b;text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
.summary-card.orange{border-left-color:var(--orange)}
.summary-card.blue{border-left-color:var(--blue)}
.summary-card.purple{border-left-color:#7b1fa2}

/* ── Calendar Grid ───────────────────────────────────────────────── */
.calendar-wrap{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden}
.cal-header{display:grid;grid-template-columns:90px repeat(7,1fr);background:linear-gradient(90deg,var(--g700),var(--g600))}
.cal-header div{padding:12px 8px;text-align:center;font-size:.78rem;font-weight:700;color:#fff;letter-spacing:.4px}
.cal-header .type-col{background:rgba(0,0,0,.15)}
.cal-body{display:grid;grid-template-columns:90px repeat(7,1fr)}
.cal-row{display:contents}
.type-label{padding:10px 8px;background:var(--g50);border-right:2px solid #c8e6c9;border-bottom:1px solid #e8ede9;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:3px}
.type-label .tl-icon{font-size:1.1rem;color:var(--g600)}
.type-label .tl-name{font-size:.7rem;font-weight:700;color:var(--g700);text-transform:uppercase;letter-spacing:.4px}
.cal-cell{border-right:1px solid #e8ede9;border-bottom:1px solid #e8ede9;padding:6px;min-height:90px;position:relative;background:#fff;transition:background var(--transition)}
.cal-cell:hover{background:#f5fbf5}
.cal-cell.today-col{background:#f5fbf5}
.cal-cell:last-child{border-right:none}

/* ── Meal Card ───────────────────────────────────────────────────── */
.meal-card{background:var(--g100);border:1px solid #c8e6c9;border-radius:6px;padding:6px 8px;margin-bottom:4px;position:relative;font-size:.78rem}
.meal-card .mc-name{font-weight:700;color:var(--g800);line-height:1.3;padding-right:18px}
.meal-card .mc-meta{color:#6b7c6d;font-size:.7rem;margin-top:2px}
.meal-card .mc-del{position:absolute;top:4px;right:4px;background:none;border:none;color:#aaa;cursor:pointer;font-size:.72rem;width:18px;height:18px;display:flex;align-items:center;justify-content:center;border-radius:4px;transition:all var(--transition)}
.meal-card .mc-del:hover{background:var(--red);color:#fff}
.meal-card.breakfast{border-left:3px solid #ff8f00;background:#fff8e1}
.meal-card.lunch{border-left:3px solid var(--g600);background:var(--g100)}
.meal-card.dinner{border-left:3px solid var(--blue);background:#e3f2fd}
.meal-card.snack{border-left:3px solid var(--orange);background:#fff3e0}

/* ── Add Cell Btn ────────────────────────────────────────────────── */
.cell-add{display:block;width:100%;text-align:center;padding:4px;border:1.5px dashed #c8e6c9;border-radius:6px;color:#a5c7a7;font-size:.78rem;cursor:pointer;background:none;transition:all var(--transition);margin-top:2px}
.cell-add:hover{border-color:var(--g600);color:var(--g700);background:var(--g100)}

/* ── Loading Overlay ─────────────────────────────────────────────── */
.cal-loading{position:relative}
.cal-loading::after{content:'';position:absolute;inset:0;background:rgba(255,255,255,.65);display:flex;align-items:center;justify-content:center;z-index:10;border-radius:var(--radius-lg)}

/* ── Modal ───────────────────────────────────────────────────────── */
.modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px)}
.modal.active{display:flex;align-items:flex-start;justify-content:center;padding:20px 16px;overflow-y:auto}
.modal-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:600px;box-shadow:var(--shadow-lg);animation:slideDown .25s ease;margin:auto}
@keyframes slideDown{from{transform:translateY(-24px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-head{background:linear-gradient(135deg,var(--g800),var(--g600));padding:20px 24px;border-radius:var(--radius-lg) var(--radius-lg) 0 0;display:flex;align-items:center;justify-content:space-between}
.modal-head h2{color:#fff;font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:10px}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center}
.modal-close:hover{background:rgba(255,255,255,.3)}
.modal-body{padding:24px 28px}

/* ── Form ────────────────────────────────────────────────────────── */
.form-section-title{font-size:.8rem;font-weight:700;color:var(--g700);text-transform:uppercase;letter-spacing:.6px;margin-bottom:12px;padding-bottom:6px;border-bottom:2px solid var(--g100);display:flex;align-items:center;gap:8px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-grid .full{grid-column:1/-1}
.form-group{display:flex;flex-direction:column;gap:4px}
.form-group label{font-size:.8rem;font-weight:600;color:#3a4a3b}
.form-group label .req{color:var(--red)}
.form-control{padding:9px 13px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;width:100%;transition:border-color var(--transition);background:#fff;font-family:inherit}
.form-control:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.1)}
textarea.form-control{resize:vertical;min-height:60px}
.form-actions{display:flex;gap:12px;justify-content:flex-end;padding-top:18px;border-top:1px solid #eef2ee;margin-top:18px}

/* ── Nutrition mini bar ──────────────────────────────────────────── */
.nut-bars{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:14px}
.nut-bar{display:flex;flex-direction:column;gap:3px}
.nut-bar-label{font-size:.72rem;color:#6b7c6d;display:flex;justify-content:space-between}
.nut-bar-track{height:6px;background:#eee;border-radius:3px;overflow:hidden}
.nut-bar-fill{height:100%;border-radius:3px;transition:width .5s ease}

/* ── Copy Week Modal ─────────────────────────────────────────────── */
.copy-info{background:var(--g50);border:1px solid #c8e6c9;border-radius:var(--radius);padding:12px 16px;font-size:.85rem;color:var(--g800);margin-bottom:16px}

/* ── Shopping List Modal ─────────────────────────────────────────── */
.shop-item{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f0f4f1;font-size:.85rem}
.shop-item:last-child{border-bottom:none}
.shop-cb{width:16px;height:16px;accent-color:var(--g700);cursor:pointer;flex-shrink:0}
.shop-name{flex:1;color:#333}
.shop-qty{font-size:.78rem;color:#8a9a8b;white-space:nowrap}
.shop-cat-header{font-size:.72rem;font-weight:700;color:var(--g700);text-transform:uppercase;letter-spacing:.5px;padding:10px 0 4px;border-bottom:2px solid var(--g100)}

/* ── Dialog ──────────────────────────────────────────────────────── */
.dialog{display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);align-items:center;justify-content:center}
.dialog.active{display:flex}
.dialog-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:420px;box-shadow:var(--shadow-lg);animation:slideDown .22s ease;overflow:hidden}
.dialog-head{padding:18px 22px;display:flex;align-items:center;gap:12px;color:#fff}
.dialog-head.danger{background:linear-gradient(135deg,#c62828,#ef5350)}
.dialog-head i{font-size:1.2rem}.dialog-head h3{font-size:1rem;font-weight:700}
.dialog-body{padding:22px;text-align:center}
.dialog-body p{font-size:.9rem;color:#555;line-height:1.55;margin-bottom:20px}
.dialog-actions{display:flex;gap:10px;justify-content:center}

/* ── Notifications ───────────────────────────────────────────────── */
#notif-stack{position:fixed;top:20px;right:20px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:360px}
.notif{background:#fff;border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow-lg);display:flex;align-items:flex-start;gap:12px;border-left:4px solid var(--g600);animation:notifIn .3s ease}
.notif.error{border-left-color:var(--red)}.notif.warning{border-left-color:#e65100}.notif.info{border-left-color:var(--blue)}
@keyframes notifIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
.notif-icon{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.notif.success .notif-icon{color:var(--g700)}.notif.error .notif-icon{color:var(--red)}.notif.warning .notif-icon{color:#e65100}
.notif-body{flex:1}.notif-title{font-weight:700;font-size:.85rem;margin-bottom:2px}.notif-msg{font-size:.8rem;color:#666}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:1rem;padding:0;line-height:1;flex-shrink:0}

@media(max-width:900px){
  .cal-header,.cal-body{grid-template-columns:70px repeat(7,minmax(80px,1fr))}
  .cal-header div,.type-label{font-size:.65rem}
}
@media(max-width:600px){
  .form-grid{grid-template-columns:1fr}
  .page-header{flex-direction:column}
}
</style>
</head>
<body>
<?php require_once 'nav.php'; ?>
<div id="notif-stack"></div>

<div class="page">
  <!-- Page Header -->
  <div class="page-header">
    <div>
      <h1><i class="fas fa-calendar-alt" style="margin-right:10px;opacity:.85"></i>Meal Planner</h1>
      <p>Plan, track and export weekly school meal schedules</p>
    </div>
    <div class="header-actions">
      <button class="btn btn-pdf"   onclick="exportToPDF()"><i class="fas fa-file-pdf"></i> Export PDF</button>
      <button class="btn btn-excel" onclick="exportToExcel()"><i class="fas fa-file-excel"></i> Export Excel</button>
    </div>
  </div>

  <!-- Week Navigator -->
  <div class="week-nav">
    <div class="week-nav-center">
      <button class="btn btn-nav" onclick="changeWeek(-1)" title="Previous week"><i class="fas fa-chevron-left"></i></button>
      <span class="week-label" id="weekLabel">Loading…</span>
      <button class="btn btn-nav" onclick="changeWeek(1)"  title="Next week"><i class="fas fa-chevron-right"></i></button>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <label style="font-size:.82rem;font-weight:600;color:#6b7c6d">Jump to week:</label>
      <input type="date" id="weekPicker" class="form-control" style="width:160px;padding:7px 10px;font-size:.82rem" onchange="jumpToWeek(this.value)">
    </div>
    <div class="week-nav-actions">
      <button class="btn btn-ghost btn-sm" onclick="gotoToday()"><i class="fas fa-calendar-day"></i> Today</button>
      <button class="btn btn-ghost btn-sm" onclick="openCopyModal()"><i class="fas fa-copy"></i> Copy Week</button>
      <button class="btn btn-ghost btn-sm" onclick="openShoppingList()"><i class="fas fa-shopping-basket"></i> Shopping List</button>
    </div>
  </div>

  <!-- Summary Cards -->
  <div class="summary-row">
    <div class="summary-card">
      <div class="s-val" id="smMeals">—</div>
      <div class="s-label">Total Meals</div>
    </div>
    <div class="summary-card orange">
      <div class="s-val" id="smCalories">—</div>
      <div class="s-label">Total Calories</div>
    </div>
    <div class="summary-card blue">
      <div class="s-val" id="smCost">—</div>
      <div class="s-label">Estimated Cost</div>
    </div>
    <div class="summary-card">
      <div class="s-val" id="smProtein">—</div>
      <div class="s-label">Avg Protein (g)</div>
    </div>
    <div class="summary-card purple">
      <div class="s-val" id="smCarbs">—</div>
      <div class="s-label">Avg Carbs (g)</div>
    </div>
    <div class="summary-card orange">
      <div class="s-val" id="smFat">—</div>
      <div class="s-label">Avg Fat (g)</div>
    </div>
  </div>

  <!-- Calendar -->
  <div class="calendar-wrap" id="calendarWrap">
    <div class="cal-header">
      <div class="type-col">Meal</div>
      <div id="hMon">Mon</div>
      <div id="hTue">Tue</div>
      <div id="hWed">Wed</div>
      <div id="hThu">Thu</div>
      <div id="hFri">Fri</div>
      <div id="hSat">Sat</div>
      <div id="hSun">Sun</div>
    </div>
    <div class="cal-body" id="calBody">
      <!-- Rendered by JS -->
    </div>
  </div>
</div>

<!-- ADD MEAL MODAL -->
<div id="addModal" class="modal" onclick="modalOutsideClick(event,'addModal')">
  <div class="modal-box">
    <div class="modal-head">
      <h2 id="addModalTitle"><i class="fas fa-plus"></i> Add Meal</h2>
      <button class="modal-close" onclick="closeModal('addModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="f_day">
      <input type="hidden" id="f_meal_type_hidden">

      <div style="display:flex;gap:14px;margin-bottom:18px;padding-bottom:14px;border-bottom:2px solid var(--g100)">
        <div style="background:var(--g100);border-radius:var(--radius);padding:10px 16px;font-size:.82rem;color:var(--g700)">
          <strong id="addContext">—</strong>
        </div>
      </div>

      <div class="form-grid" style="margin-bottom:18px">
        <div class="form-group full">
          <label>Meal Name <span class="req">*</span></label>
          <input type="text" id="f_meal_name" class="form-control" maxlength="255" placeholder="e.g. Matoke with Groundnut Sauce" required>
        </div>
        <div class="form-group full">
          <label>Description</label>
          <textarea id="f_description" class="form-control" rows="2" placeholder="Ingredients, notes…" maxlength="1000"></textarea>
        </div>
      </div>

      <div class="form-section-title"><i class="fas fa-fire"></i> Nutrition &amp; Cost <span style="font-weight:400;color:#8a9a8b;font-size:.72rem;margin-left:6px">(optional)</span></div>
      <div class="form-grid">
        <div class="form-group">
          <label>Calories (kcal)</label>
          <input type="number" id="f_calories" class="form-control" min="0" step="1" value="0">
        </div>
        <div class="form-group">
          <label>Cost (UGX)</label>
          <input type="number" id="f_cost" class="form-control" min="0" step="100" value="0">
        </div>
        <div class="form-group">
          <label>Protein (g)</label>
          <input type="number" id="f_protein" class="form-control" min="0" step="0.1" value="0">
        </div>
        <div class="form-group">
          <label>Carbs (g)</label>
          <input type="number" id="f_carbs" class="form-control" min="0" step="0.1" value="0">
        </div>
        <div class="form-group">
          <label>Fat (g)</label>
          <input type="number" id="f_fat" class="form-control" min="0" step="0.1" value="0">
        </div>
        <div class="form-group">
          <label>Fiber (g)</label>
          <input type="number" id="f_fiber" class="form-control" min="0" step="0.1" value="0">
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn-outline" onclick="closeModal('addModal')"><i class="fas fa-times"></i> Cancel</button>
        <button class="btn btn-primary" id="addMealBtn" onclick="saveMeal()"><i class="fas fa-plus"></i> Add Meal</button>
      </div>
    </div>
  </div>
</div>

<!-- COPY WEEK MODAL -->
<div id="copyModal" class="modal" onclick="modalOutsideClick(event,'copyModal')">
  <div class="modal-box" style="max-width:460px">
    <div class="modal-head">
      <h2><i class="fas fa-copy"></i> Copy Week</h2>
      <button class="modal-close" onclick="closeModal('copyModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div class="copy-info"><i class="fas fa-info-circle" style="color:var(--g600)"></i> The current week's meals will be copied to the target week. Any existing meals in the target week will be replaced.</div>
      <div class="form-group" style="margin-bottom:16px">
        <label>Source Week (current)</label>
        <input type="text" id="copySource" class="form-control" readonly style="background:#f5f5f5;color:#555">
      </div>
      <div class="form-group">
        <label>Target Week Start (Monday) <span class="req">*</span></label>
        <input type="date" id="copyTarget" class="form-control">
      </div>
      <div class="form-actions">
        <button class="btn btn-outline" onclick="closeModal('copyModal')">Cancel</button>
        <button class="btn btn-primary" onclick="doCopyWeek()"><i class="fas fa-copy"></i> Copy Meals</button>
      </div>
    </div>
  </div>
</div>

<!-- SHOPPING LIST MODAL -->
<div id="shopModal" class="modal" onclick="modalOutsideClick(event,'shopModal')">
  <div class="modal-box" style="max-width:500px">
    <div class="modal-head">
      <h2><i class="fas fa-shopping-basket"></i> Shopping List</h2>
      <button class="modal-close" onclick="closeModal('shopModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div id="shopBody">
        <div style="text-align:center;padding:30px;color:#8a9a8b"><i class="fas fa-spinner fa-spin" style="font-size:2rem"></i></div>
      </div>
      <div class="form-actions" style="padding-top:14px;margin-top:14px;border-top:1px solid #eef2ee">
        <button class="btn btn-outline" onclick="closeModal('shopModal')">Close</button>
        <button class="btn btn-primary" onclick="printShopList()"><i class="fas fa-print"></i> Print</button>
      </div>
    </div>
  </div>
</div>

<!-- DELETE DIALOG -->
<div id="confirmDlg" class="dialog">
  <div class="dialog-box">
    <div class="dialog-head danger"><i class="fas fa-trash"></i><h3>Remove Meal</h3></div>
    <div class="dialog-body">
      <p id="dlgMsg"></p>
      <div class="dialog-actions">
        <button class="btn btn-outline" onclick="closeDlg()">Cancel</button>
        <button class="btn btn-danger"  onclick="runDlgCallback()"><i class="fas fa-trash"></i> Remove</button>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF     = <?= json_encode($csrf) ?>;
const DAYS     = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
const DAY_ABBR = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
const TYPES    = ['breakfast','lunch','dinner','snack'];
const TYPE_ICONS = { breakfast:'fas fa-sun', lunch:'fas fa-bowl-food', dinner:'fas fa-moon', snack:'fas fa-apple-whole' };

let currentWeekStart = getMondayOf(new Date());
let weekData = {};   // meals keyed by day+type
let dlgCb = null;

/* ────────── BOOT ──────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    buildCalendarShell();
    loadWeek();
});

/* ────────── WEEK NAVIGATION ───────────────────────────────────── */
function getMondayOf(date) {
    const d = new Date(date);
    const day = d.getDay(); // 0=Sun
    const diff = (day === 0 ? -6 : 1 - day);
    d.setDate(d.getDate() + diff);
    d.setHours(0,0,0,0);
    return d;
}

function changeWeek(n) {
    currentWeekStart = new Date(currentWeekStart);
    currentWeekStart.setDate(currentWeekStart.getDate() + n * 7);
    loadWeek();
}

function gotoToday() {
    currentWeekStart = getMondayOf(new Date());
    loadWeek();
}

function jumpToWeek(dateStr) {
    if (!dateStr) return;
    currentWeekStart = getMondayOf(new Date(dateStr));
    loadWeek();
}

function updateWeekLabel() {
    const end = new Date(currentWeekStart);
    end.setDate(end.getDate() + 6);
    const opts = { day:'2-digit', month:'short', year:'numeric' };
    document.getElementById('weekLabel').textContent =
        currentWeekStart.toLocaleDateString('en-UG', opts) + ' – ' + end.toLocaleDateString('en-UG', opts);
    document.getElementById('weekPicker').value = fmtDateISO(currentWeekStart);

    /* Update column headers with actual dates */
    DAYS.forEach((d, i) => {
        const dt = new Date(currentWeekStart);
        dt.setDate(dt.getDate() + i);
        const today = new Date(); today.setHours(0,0,0,0);
        const isToday = dt.toDateString() === today.toDateString();
        const el = document.getElementById('h' + DAY_ABBR[i]);
        el.innerHTML = `${DAY_ABBR[i]}<br><span style="font-size:.85rem;opacity:.9">${dt.getDate()}</span>`;
        el.style.background = isToday ? 'rgba(255,255,255,.2)' : '';
    });

    /* Highlight today column cells */
    document.querySelectorAll('.cal-cell').forEach(c => c.classList.remove('today-col'));
    const todayIdx = (() => {
        const today = new Date(); today.setHours(0,0,0,0);
        for (let i=0;i<7;i++) {
            const d = new Date(currentWeekStart); d.setDate(d.getDate()+i);
            if (d.toDateString() === today.toDateString()) return i;
        }
        return -1;
    })();
    if (todayIdx >= 0) {
        document.querySelectorAll(`.cal-cell[data-day-idx="${todayIdx}"]`).forEach(c => c.classList.add('today-col'));
    }
}

/* ────────── BUILD CALENDAR HTML SHELL ─────────────────────────── */
function buildCalendarShell() {
    const body = document.getElementById('calBody');
    let html = '';
    TYPES.forEach(type => {
        /* type label */
        html += `<div class="type-label"><i class="${TYPE_ICONS[type]} tl-icon"></i><span class="tl-name">${ucf(type)}</span></div>`;
        /* 7 day cells */
        DAYS.forEach((day, di) => {
            html += `<div class="cal-cell" data-day="${day}" data-type="${type}" data-day-idx="${di}" id="cell-${day}-${type}"></div>`;
        });
    });
    body.innerHTML = html;
}

/* ────────── LOAD WEEK DATA ─────────────────────────────────────── */
function loadWeek() {
    updateWeekLabel();
    const wrap = document.getElementById('calendarWrap');
    wrap.classList.add('cal-loading');

    api({ action:'get_week', week_start: fmtDateISO(currentWeekStart) })
    .then(d => {
        if (!d.success) throw new Error(d.error);
        /* index meals by day+type */
        weekData = {};
        (d.data||[]).forEach(m => {
            const k = `${m.day_of_week}-${m.meal_type}`;
            if (!weekData[k]) weekData[k] = [];
            weekData[k].push(m);
        });
        renderCells();
        renderSummary(d.summary);
    })
    .catch(err => notify('Error', err.message, 'error'))
    .finally(() => wrap.classList.remove('cal-loading'));
}

/* ────────── RENDER CELLS ───────────────────────────────────────── */
function renderCells() {
    TYPES.forEach(type => {
        DAYS.forEach(day => {
            const cell = document.getElementById(`cell-${day}-${type}`);
            if (!cell) return;
            const meals = weekData[`${day}-${type}`] || [];
            let html = '';
            meals.forEach(m => {
                html += `<div class="meal-card ${type}">
                  <button class="mc-del" title="Remove meal" onclick="promptDelete(${m.id},'${esc(m.meal_name)}')"><i class="fas fa-times"></i></button>
                  <div class="mc-name">${esc(m.meal_name)}</div>
                  <div class="mc-meta">
                    ${m.calories ? `<i class="fas fa-fire" style="color:#f57f17"></i> ${m.calories} kcal &nbsp;` : ''}
                    ${m.cost ? `<i class="fas fa-coins" style="color:var(--g600)"></i> ${fmtCurrency(m.cost)}` : ''}
                  </div>
                </div>`;
            });
            html += `<button class="cell-add" onclick="openAddMeal('${day}','${type}')"><i class="fas fa-plus" style="font-size:.65rem"></i> Add</button>`;
            cell.innerHTML = html;
        });
    });
}

/* ────────── SUMMARY ────────────────────────────────────────────── */
function renderSummary(s) {
    if (!s) return;
    document.getElementById('smMeals').textContent    = s.total_meals    || 0;
    document.getElementById('smCalories').textContent = Math.round(s.total_calories || 0).toLocaleString('en-UG');
    document.getElementById('smCost').textContent     = fmtCurrencyShort(s.total_cost || 0);
    document.getElementById('smProtein').textContent  = Math.round(s.avg_protein || 0);
    document.getElementById('smCarbs').textContent    = Math.round(s.avg_carbs   || 0);
    document.getElementById('smFat').textContent      = Math.round(s.avg_fat     || 0);
}

/* ────────── ADD MEAL ───────────────────────────────────────────── */
function openAddMeal(day, type) {
    document.getElementById('f_day').value              = day;
    document.getElementById('f_meal_type_hidden').value = type;
    document.getElementById('f_meal_name').value        = '';
    document.getElementById('f_description').value      = '';
    ['f_calories','f_cost','f_protein','f_carbs','f_fat','f_fiber'].forEach(id => document.getElementById(id).value = '0');
    document.getElementById('addContext').innerHTML = `<i class="${TYPE_ICONS[type]}"></i> ${ucf(day)} — ${ucf(type)}`;
    document.getElementById('addModalTitle').innerHTML  = `<i class="fas fa-plus"></i> Add ${ucf(type)} — ${ucf(day)}`;
    openModal('addModal');
}

function saveMeal() {
    const name = document.getElementById('f_meal_name').value.trim();
    if (!name) { notify('Validation', 'Meal name is required.', 'warning'); return; }

    const payload = {
        action:       'add',
        day_of_week:  document.getElementById('f_day').value,
        meal_type:    document.getElementById('f_meal_type_hidden').value,
        meal_name:    name,
        description:  document.getElementById('f_description').value.trim(),
        calories:     document.getElementById('f_calories').value,
        cost:         document.getElementById('f_cost').value,
        protein:      document.getElementById('f_protein').value,
        carbs:        document.getElementById('f_carbs').value,
        fat:          document.getElementById('f_fat').value,
        fiber:        document.getElementById('f_fiber').value,
        week_start:   fmtDateISO(currentWeekStart),
    };

    const btn = document.getElementById('addMealBtn');
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving…';

    api(payload).then(d => {
        if (!d.success) throw new Error(d.error);
        notify('Added', d.message, 'success');
        closeModal('addModal');
        loadWeek();
    }).catch(err => notify('Error', err.message, 'error'))
    .finally(() => { btn.disabled=false; btn.innerHTML='<i class="fas fa-plus"></i> Add Meal'; });
}

/* ────────── DELETE ─────────────────────────────────────────────── */
function promptDelete(id, name) {
    dlgCb = () => {
        api({ action:'delete', meal_id: id }).then(d => {
            if (!d.success) throw new Error(d.error);
            notify('Removed', d.message, 'success');
            loadWeek();
        }).catch(err => notify('Error', err.message, 'error'));
    };
    document.getElementById('dlgMsg').innerHTML = `Remove <strong>${esc(name)}</strong> from this slot?`;
    document.getElementById('confirmDlg').classList.add('active');
}

/* ────────── COPY WEEK ──────────────────────────────────────────── */
function openCopyModal() {
    document.getElementById('copySource').value = fmtDateISO(currentWeekStart) + ' (current week)';
    document.getElementById('copyTarget').value = '';
    openModal('copyModal');
}

function doCopyWeek() {
    const target = document.getElementById('copyTarget').value;
    if (!target) { notify('Validation', 'Please select a target week start date.', 'warning'); return; }

    api({ action:'copy_week', source_week: fmtDateISO(currentWeekStart), target_week: target })
    .then(d => {
        if (!d.success) throw new Error(d.error);
        notify('Copied', d.message, 'success');
        closeModal('copyModal');
    }).catch(err => notify('Error', err.message, 'error'));
}

/* ────────── SHOPPING LIST ──────────────────────────────────────── */
function openShoppingList() {
    openModal('shopModal');
    document.getElementById('shopBody').innerHTML = '<div style="text-align:center;padding:30px;color:#8a9a8b"><i class="fas fa-spinner fa-spin" style="font-size:2rem"></i></div>';

    /* build list from current week meals */
    const meals = Object.values(weekData).flat();
    if (!meals.length) {
        document.getElementById('shopBody').innerHTML = '<div style="text-align:center;padding:30px;color:#8a9a8b"><i class="fas fa-shopping-basket" style="font-size:2rem;display:block;margin-bottom:10px;opacity:.4"></i>No meals planned this week.</div>';
        return;
    }

    let html = '<p style="font-size:.82rem;color:#6b7c6d;margin-bottom:14px">Based on meals planned for this week:</p>';
    const mealsByType = {};
    meals.forEach(m => {
        if (!mealsByType[m.meal_type]) mealsByType[m.meal_type] = [];
        mealsByType[m.meal_type].push(m);
    });

    TYPES.forEach(type => {
        if (!mealsByType[type]?.length) return;
        html += `<div class="shop-cat-header"><i class="${TYPE_ICONS[type]}"></i> ${ucf(type)}</div>`;
        mealsByType[type].forEach(m => {
            html += `<div class="shop-item">
              <input type="checkbox" class="shop-cb" id="sc-${m.id}">
              <label for="sc-${m.id}" class="shop-name">${esc(m.meal_name)}</label>
              <span class="shop-qty">${ucf(m.day_of_week)}</span>
            </div>`;
        });
    });
    document.getElementById('shopBody').innerHTML = html;
}

function printShopList() {
    const content = document.getElementById('shopBody').innerHTML;
    const win = window.open('', '_blank');
    win.document.write(`<!DOCTYPE html><html><head><title>Shopping List</title>
    <style>body{font-family:sans-serif;padding:20px;max-width:600px;margin:0 auto}
    .shop-cat-header{font-weight:700;margin-top:16px;margin-bottom:4px;border-bottom:1px solid #ccc;padding-bottom:4px;text-transform:uppercase;font-size:12px;color:#2e7d32}
    .shop-item{display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid #f0f0f0;font-size:14px}
    h1{color:#2e7d32;font-size:18px;margin-bottom:16px}
    </style></head><body><h1>Shopping List — Week of ${fmtDateISO(currentWeekStart)}</h1>${content}</body></html>`);
    win.document.close();
    win.print();
}

/* ────────── EXPORTS ────────────────────────────────────────────── */
function exportToPDF() {
    const meals = Object.values(weekData).flat();
    if (!meals.length) { notify('Empty', 'No meals planned this week.', 'warning'); return; }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');
    doc.setFontSize(16); doc.setTextColor(46,125,50);
    doc.text('Meal Plan Report', 14, 18);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text(`Week: ${fmtDateISO(currentWeekStart)} · Generated: ${new Date().toLocaleString('en-UG')}`, 14, 25);
    doc.autoTable({
        head:[['Day','Type','Meal Name','Description','Calories','Cost (UGX)','Protein','Carbs','Fat']],
        body: meals.map(m=>[ucf(m.day_of_week),ucf(m.meal_type),m.meal_name,m.description||'',m.calories||0,'UGX '+fmtNum(m.cost||0),m.protein||0,m.carbs||0,m.fat||0]),
        startY:30, theme:'grid',
        headStyles:{fillColor:[67,160,71],fontSize:7.5},
        bodyStyles:{fontSize:7},
    });
    doc.save(`meal-plan-${fmtDateISO(currentWeekStart)}.pdf`);
    notify('Exported','PDF downloaded.','success');
}

function exportToExcel() {
    const meals = Object.values(weekData).flat();
    if (!meals.length) { notify('Empty', 'No meals planned this week.', 'warning'); return; }
    const wb=XLSX.utils.book_new();
    const ws=XLSX.utils.aoa_to_sheet([
        ['Day','Meal Type','Meal Name','Description','Calories','Cost (UGX)','Protein (g)','Carbs (g)','Fat (g)','Fiber (g)'],
        ...meals.map(m=>[ucf(m.day_of_week),ucf(m.meal_type),m.meal_name,m.description||'',m.calories||0,parseFloat(m.cost||0),m.protein||0,m.carbs||0,m.fat||0,m.fiber||0])
    ]);
    ws['!cols']=[{wch:12},{wch:12},{wch:28},{wch:30},{wch:10},{wch:14},{wch:10},{wch:10},{wch:10},{wch:10}];
    XLSX.utils.book_append_sheet(wb,ws,'Meal Plan');
    XLSX.writeFile(wb,`meal-plan-${fmtDateISO(currentWeekStart)}.xlsx`);
    notify('Exported','Excel downloaded.','success');
}

/* ────────── HELPERS ────────────────────────────────────────────── */
// fmtDateISO: Must use local date parts — NOT toISOString() which is UTC.
// In Uganda (UTC+3) midnight local = 21:00 previous day UTC, giving wrong date.
function fmtDateISO(d){
    const dt = new Date(d);
    const y  = dt.getFullYear();
    const m  = String(dt.getMonth()+1).padStart(2,'0');
    const day= String(dt.getDate()).padStart(2,'0');
    return `${y}-${m}-${day}`;
}
function api(p){ return fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({...p,csrf_token:CSRF})}).then(r=>r.json()); }
function esc(v){ return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function ucf(v){ return v?v.charAt(0).toUpperCase()+v.slice(1):''; }
function fmtNum(v){ return parseFloat(v||0).toLocaleString('en-UG',{maximumFractionDigits:0}); }
function fmtCurrency(v){ return 'UGX '+fmtNum(v); }
function fmtCurrencyShort(v){ const n=parseFloat(v||0); if(n>=1_000_000)return(n/1_000_000).toFixed(1)+'M'; if(n>=1_000)return(n/1_000).toFixed(0)+'K'; return n.toFixed(0); }
function closeDlg(){ document.getElementById('confirmDlg').classList.remove('active'); dlgCb=null; }
function runDlgCallback(){ if(dlgCb)dlgCb(); closeDlg(); }
function openModal(id){ document.getElementById(id).classList.add('active'); }
function closeModal(id){ document.getElementById(id).classList.remove('active'); }
function closeAllModals(){ document.querySelectorAll('.modal.active,.dialog.active').forEach(m=>m.classList.remove('active')); }
function modalOutsideClick(e,id){ if(e.target.id===id)closeModal(id); }
function notify(title,msg,type='success',dur=4500){
    const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
    const n=document.createElement('div');n.className=`notif ${type}`;
    n.innerHTML=`<i class="fas ${icons[type]||icons.info} notif-icon"></i><div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div><button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('notif-stack').prepend(n);
    setTimeout(()=>{n.style.opacity='0';n.style.transform='translateX(30px)';n.style.transition='.3s';setTimeout(()=>n.remove(),300);},dur);
}
document.addEventListener('keydown',e=>{ if(e.key==='Escape')closeAllModals(); });
</script>
</body>
</html>
