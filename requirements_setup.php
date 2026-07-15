<?php
require_once "auth.php";
require_once 'conn.php';
require_once 'tracking.php';

$allowedRoles = ['super user', 'school leader', 'developer', 'bursar'];
if (!in_array($_SESSION['role'], $allowedRoles)) { header("Location: dashboard.php"); exit(); }
$tracker->trackAction("Requirements Setup Management");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache"); header("Expires: 0");

// ── Schema bootstrap (runs once) ──────────────────────────────────────────────
$conn->multi_query("
CREATE TABLE IF NOT EXISTS school_requirements (
    requirement_id INT AUTO_INCREMENT PRIMARY KEY,
    requirement_name VARCHAR(200) NOT NULL,
    description TEXT,
    quantity_per_student INT NOT NULL DEFAULT 1,
    cash_equivalent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    class VARCHAR(50) DEFAULT NULL,
    stream VARCHAR(50) DEFAULT NULL,
    section_type ENUM('All','Day','Boarding') NOT NULL DEFAULT 'All',
    term VARCHAR(50) NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_class_stream (class, stream),
    INDEX idx_term_year (term, academic_year),
    INDEX idx_status (status),
    INDEX idx_section_type (section_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS student_requirements (
    record_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(30) NOT NULL,
    requirement_id INT NOT NULL,
    items_brought INT DEFAULT 0,
    cash_paid DECIMAL(10,2) DEFAULT 0.00,
    total_value DECIMAL(10,2) DEFAULT 0.00,
    balance DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('pending','partial','completed','waived') DEFAULT 'pending',
    waived_reason TEXT,
    recorded_by INT NOT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (requirement_id) REFERENCES school_requirements(requirement_id) ON DELETE CASCADE,
    INDEX idx_student (student_id),
    INDEX idx_requirement (requirement_id),
    INDEX idx_status (status),
    UNIQUE KEY unique_student_req (student_id, requirement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS requirement_payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    record_id INT NOT NULL,
    student_id VARCHAR(30) NOT NULL,
    requirement_id INT NOT NULL,
    payment_type ENUM('item','cash') NOT NULL,
    quantity INT DEFAULT 0,
    amount DECIMAL(10,2) DEFAULT 0.00,
    payment_date DATE NOT NULL,
    notes TEXT,
    recorded_by INT NOT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (record_id) REFERENCES student_requirements(record_id) ON DELETE CASCADE,
    FOREIGN KEY (requirement_id) REFERENCES school_requirements(requirement_id) ON DELETE CASCADE,
    INDEX idx_student (student_id),
    INDEX idx_requirement (requirement_id),
    INDEX idx_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
while ($conn->next_result()) {;}

// Migration: add section_type column to existing installs
$mc = $conn->query("SELECT COUNT(*) as c FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='school_requirements' AND COLUMN_NAME='section_type'");
if ($mc && (int)$mc->fetch_assoc()['c'] === 0) {
    $conn->query("ALTER TABLE school_requirements
        ADD COLUMN section_type ENUM('All','Day','Boarding') NOT NULL DEFAULT 'All' AFTER stream,
        ADD INDEX idx_section_type (section_type)");
}

// CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

// ── AJAX ─────────────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    switch ($_GET['action']) {

        case 'fetch_requirements':
            $term    = $_GET['term']    ?? '';
            $year    = $_GET['year']    ?? '';
            $class   = $_GET['class']   ?? '';
            $status  = $_GET['status']  ?? '';
            $section = $_GET['section'] ?? '';
            // Whitelist status to prevent arbitrary WHERE injection through the filter
            if ($status && !in_array($status, ['active', 'inactive'], true)) $status = '';
            // Whitelist section
            if ($section && !in_array($section, ['All', 'Day', 'Boarding'], true)) $section = '';

            $q = "SELECT r.*, u.user_name,
                    (SELECT COUNT(*) FROM student_requirements sr WHERE sr.requirement_id = r.requirement_id) AS record_count
                  FROM school_requirements r
                  LEFT JOIN users u ON r.created_by = u.user_id
                  WHERE 1=1";
            $p = []; $t = '';
            if ($term)    { $q .= " AND r.term = ?";          $p[] = $term;    $t .= 's'; }
            if ($year)    { $q .= " AND r.academic_year = ?"; $p[] = $year;    $t .= 's'; }
            if ($class)   { $q .= " AND (r.class = ? OR r.class IS NULL)"; $p[] = $class; $t .= 's'; }
            if ($status)  { $q .= " AND r.status = ?";        $p[] = $status;  $t .= 's'; }
            if ($section) { $q .= " AND r.section_type = ?";  $p[] = $section; $t .= 's'; }
            $q .= " ORDER BY r.status ASC, r.created_at DESC LIMIT 500";
            $stmt = $conn->prepare($q);
            if ($p) $stmt->bind_param($t, ...$p);
            $stmt->execute();
            echo json_encode(['success' => true, 'data' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
            break;

        case 'fetch_single':
            $id = intval($_GET['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); break; }
            $stmt = $conn->prepare("SELECT * FROM school_requirements WHERE requirement_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            echo json_encode($row ? ['success' => true, 'data' => $row] : ['success' => false, 'message' => 'Not found']);
            break;

        case 'get_chart_data':
            $term = $_GET['term'] ?? '';
            $year = $_GET['year'] ?? '';
            if (!$term || !$year) { echo json_encode(['success' => false, 'message' => 'Select term and year']); break; }
            if (!preg_match('/^\d{4}$/', $year)) { echo json_encode(['success' => false, 'message' => 'Invalid year format']); break; }
            if (!in_array($term, ['Term 1', 'Term 2', 'Term 3'], true)) { echo json_encode(['success' => false, 'message' => 'Invalid term']); break; }

            // Chart 1: Completion rate by class
            $cs = $conn->prepare("
                SELECT s.current_class,
                    COUNT(DISTINCT s.student_id) AS total,
                    COUNT(DISTINCT CASE WHEN sr.status IN ('completed','waived') THEN s.student_id END) AS done
                FROM students s
                LEFT JOIN student_requirements sr
                    ON s.student_id = sr.student_id
                    AND sr.requirement_id IN (
                        SELECT requirement_id FROM school_requirements
                        WHERE term = ? AND academic_year = ? AND status = 'active'
                    )
                WHERE s.status = 'active' AND s.current_class IS NOT NULL AND s.current_class != ''
                GROUP BY s.current_class
                ORDER BY s.current_class");
            $cs->bind_param("ss", $term, $year); $cs->execute();
            $completion = $cs->get_result()->fetch_all(MYSQLI_ASSOC);

            // Chart 2: Cash collected vs outstanding
            $cs2 = $conn->prepare("
                SELECT
                    COALESCE(SUM(sr.cash_paid), 0) AS collected,
                    COALESCE(SUM(sr.items_brought * COALESCE(r2.cash_equivalent, 0)), 0) AS items_value,
                    COALESCE(SUM(sr.balance), 0) AS outstanding
                FROM student_requirements sr
                INNER JOIN school_requirements r2 ON sr.requirement_id = r2.requirement_id
                WHERE r2.term = ? AND r2.academic_year = ? AND sr.status != 'waived'");
            $cs2->bind_param("ss", $term, $year); $cs2->execute();
            $cash = $cs2->get_result()->fetch_assoc();

            // Chart 3: Top 5 most defaulted requirements
            $cs3 = $conn->prepare("
                SELECT r3.requirement_name,
                    SUM(CASE WHEN sr.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN sr.status = 'partial' THEN 1 ELSE 0 END) AS partial_count
                FROM student_requirements sr
                INNER JOIN school_requirements r3 ON sr.requirement_id = r3.requirement_id
                WHERE r3.term = ? AND r3.academic_year = ?
                GROUP BY r3.requirement_id, r3.requirement_name
                HAVING pending_count > 0
                ORDER BY pending_count DESC
                LIMIT 5");
            $cs3->bind_param("ss", $term, $year); $cs3->execute();
            $defaulted = $cs3->get_result()->fetch_all(MYSQLI_ASSOC);

            echo json_encode(['success' => true, 'completion' => $completion, 'cash' => $cash, 'defaulted' => $defaulted]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
    exit();
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Refresh the page and try again.']);
        exit();
    }

    $action = $_POST['action'] ?? 'save';

    // ── Toggle active/inactive ──
    if ($action === 'toggle_status') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit(); }
        $stmt = $conn->prepare("UPDATE school_requirements SET status = IF(status='active','inactive','active'), updated_at=NOW() WHERE requirement_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $chkStatus = $conn->prepare("SELECT status FROM school_requirements WHERE requirement_id = ?");
        $chkStatus->bind_param("i", $id); $chkStatus->execute();
        $row = $chkStatus->get_result()->fetch_assoc();
        $newStatus = $row['status'] ?? 'inactive';
        echo json_encode(['success' => true, 'new_status' => $newStatus, 'message' => 'Requirement ' . ($newStatus === 'active' ? 'activated' : 'deactivated') . ' successfully']);
        exit();
    }

    // ── Delete (POST only, blocks if records exist) ──
    if ($action === 'delete_requirement') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit(); }
        $conn->begin_transaction();
        try {
            $chk = $conn->prepare("SELECT COUNT(*) AS c FROM student_requirements WHERE requirement_id = ?");
            $chk->bind_param("i", $id);
            $chk->execute();
            if ((int)$chk->get_result()->fetch_assoc()['c'] > 0) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Cannot delete: this requirement has student records. Use the deactivate button instead to hide it.']);
                exit();
            }
            $del = $conn->prepare("DELETE FROM school_requirements WHERE requirement_id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Requirement deleted successfully']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Database error while deleting']);
        }
        exit();
    }

    // ── Save (insert / update) ──
    $reqId = intval($_POST['requirement_id'] ?? 0);
    $name  = trim($_POST['requirement_name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $qty   = max(1, intval($_POST['quantity'] ?? 1));
    $cash  = max(0.0, floatval($_POST['cash_equivalent'] ?? 0));
    $term  = trim($_POST['term'] ?? '');
    $year  = trim($_POST['academic_year'] ?? '');
    $scope = $_POST['scope'] ?? 'all';

    // Whitelist scope values
    $validScopes = ['all', 'day', 'boarding', 'class_all', 'class_day', 'class_boarding'];
    if (!in_array($scope, $validScopes, true)) $scope = 'all';

    // Resolve class, stream, section_type from scope
    $class = null; $stream = null; $sectionType = 'All';
    switch ($scope) {
        case 'day':            $sectionType = 'Day';      break;
        case 'boarding':       $sectionType = 'Boarding'; break;
        case 'class_all':      $sectionType = 'All';      $class = trim($_POST['class'] ?? '') ?: null; $stream = trim($_POST['stream'] ?? '') ?: null; break;
        case 'class_day':      $sectionType = 'Day';      $class = trim($_POST['class'] ?? '') ?: null; $stream = trim($_POST['stream'] ?? '') ?: null; break;
        case 'class_boarding': $sectionType = 'Boarding'; $class = trim($_POST['class'] ?? '') ?: null; $stream = trim($_POST['stream'] ?? '') ?: null; break;
        // 'all' is the default
    }

    if (!$name || !$term || !$year) {
        echo json_encode(['success' => false, 'message' => 'Requirement Name, Term, and Academic Year are required']);
        exit();
    }
    $validTerms = ['Term 1', 'Term 2', 'Term 3'];
    if (!in_array($term, $validTerms, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid term value']); exit();
    }
    if (!preg_match('/^\d{4}$/', $year)) {
        echo json_encode(['success' => false, 'message' => 'Academic year must be a 4-digit number']); exit();
    }
    if (in_array($scope, ['class_all', 'class_day', 'class_boarding']) && !$class) {
        echo json_encode(['success' => false, 'message' => 'Please select a Class for class-specific requirements']);
        exit();
    }

    $conn->begin_transaction();
    try {
        if ($reqId > 0) {
            $stmt = $conn->prepare("UPDATE school_requirements SET
                requirement_name=?, description=?, quantity_per_student=?, cash_equivalent=?,
                class=?, stream=?, section_type=?, term=?, academic_year=?, updated_at=NOW()
                WHERE requirement_id=?");
            $stmt->bind_param("ssidsssssi", $name, $desc, $qty, $cash, $class, $stream, $sectionType, $term, $year, $reqId);
        } else {
            $stmt = $conn->prepare("INSERT INTO school_requirements
                (requirement_name, description, quantity_per_student, cash_equivalent,
                 class, stream, section_type, term, academic_year, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssidsssssi", $name, $desc, $qty, $cash, $class, $stream, $sectionType, $term, $year, $_SESSION['user_id']);
        }
        $stmt->execute();
        $conn->commit();
        echo json_encode(['success' => true, 'message' => $reqId > 0 ? 'Requirement updated successfully' : 'Requirement added successfully']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// ── Page data ─────────────────────────────────────────────────────────────────
$classes     = $conn->query("SELECT DISTINCT current_class FROM students WHERE current_class IS NOT NULL AND current_class != '' ORDER BY current_class")->fetch_all(MYSQLI_ASSOC);
$streams     = $conn->query("SELECT DISTINCT stream FROM students WHERE stream IS NOT NULL AND stream != '' ORDER BY stream")->fetch_all(MYSQLI_ASSOC);
$currentYear = date('Y');
$stats       = $conn->query("SELECT SUM(status='active') as ac, SUM(status='inactive') as ic, COUNT(*) as tc FROM school_requirements")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Requirements Setup &mdash; School Pilot</title>
<link rel="shortcut icon" href="images/schoolcontrol_icon.png" type="image/x-icon">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
<style>
/* ── Design Tokens ─────────────────────────────────────── */
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--orange:#e65100;--blue:#1565c0;--gray:#546e7a;--amber:#f57c00;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);--shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --tr:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}
a{color:inherit;text-decoration:none}
.page{max-width:100%;padding:24px 20px 60px;margin-top:48px}

/* ── Page Header ─────────────────────────────────────────*/
.page-header{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--radius-lg);padding:28px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;margin-bottom:24px;box-shadow:var(--shadow-lg)}
.page-header h1{color:#fff;font-size:1.55rem;font-weight:700;letter-spacing:.3px}
.page-header p{color:rgba(255,255,255,.78);font-size:.9rem;margin-top:3px}
.stats-row{display:flex;gap:12px;flex-wrap:wrap}
.stat-pill{background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);border-radius:40px;padding:8px 18px;text-align:center;min-width:80px}
.stat-pill .n{font-size:1.35rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.72rem;color:rgba(255,255,255,.75);text-transform:uppercase;letter-spacing:.5px}

/* ── Chart area ──────────────────────────────────────────*/
.chart-filter-bar{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);padding:16px 24px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:18px}
.chart-filter-bar label{font-size:.82rem;font-weight:600;color:#555}
.charts-grid{display:grid;grid-template-columns:1fr 300px 300px;gap:18px;margin-bottom:24px}
@media(max-width:1100px){.charts-grid{grid-template-columns:1fr}}
.chart-card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);padding:20px}
.chart-card h3{font-size:.9rem;font-weight:700;color:#333;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.chart-card h3 i{color:var(--g700)}
.chart-placeholder{display:flex;align-items:center;justify-content:center;height:200px;color:#bbb;flex-direction:column;gap:10px;font-size:.82rem}
.chart-placeholder i{font-size:2rem;opacity:.5}

/* ── Card ────────────────────────────────────────────────*/
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden;margin-bottom:24px}
.card-head{background:linear-gradient(90deg,var(--g700),var(--g600));padding:17px 24px;display:flex;align-items:center;justify-content:space-between}
.card-head h2{color:#fff;font-size:1rem;font-weight:700;display:flex;align-items:center;gap:9px}
.card-body{padding:26px}

/* ── Scope Tiles ─────────────────────────────────────────*/
.scope-section-label{font-size:.82rem;font-weight:600;color:#444;margin-bottom:10px}
.scope-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px}
@media(max-width:700px){.scope-grid{grid-template-columns:repeat(2,1fr)}}
.scope-tile{cursor:pointer;border:2px solid #e0e9e1;border-radius:var(--radius);padding:14px 10px;text-align:center;transition:all var(--tr);position:relative;display:flex;flex-direction:column;align-items:center;gap:4px;user-select:none}
.scope-tile input[type=radio]{position:absolute;opacity:0;width:0;height:0;pointer-events:none}
.scope-tile:hover{border-color:var(--g600);background:var(--g50)}
.scope-tile.selected{border-color:var(--g700);background:var(--g100)}
.scope-tile .si{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;margin-bottom:3px;background:#f0f4f1;color:var(--g800);transition:all var(--tr)}
.scope-tile.selected .si{background:var(--g700);color:#fff}
.scope-tile .sl{font-size:.78rem;font-weight:700;color:#333;line-height:1.3}
.scope-tile .ss{font-size:.7rem;color:#999;line-height:1.2}
.scope-tile.selected .sl{color:var(--g800)}

/* ── Class/Stream row ────────────────────────────────────*/
.class-stream-row{display:none;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px}
.class-stream-row.show{display:grid}

/* ── Scope badge (table) ─────────────────────────────────*/
.sbadge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:20px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
.sbadge-all{background:#e3f2fd;color:#1565c0}
.sbadge-day{background:#fff8e1;color:#e65100}
.sbadge-boarding{background:#f3e5f5;color:#6a1b9a}

/* ── Toolbar ─────────────────────────────────────────────*/
.toolbar{padding:16px 22px;border-bottom:1px solid #e8ede9;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.tl{display:flex;flex-wrap:wrap;gap:10px;align-items:center;flex:1}
.tr-bar{display:flex;gap:10px;align-items:center;flex-shrink:0}
.filter-select{padding:8px 11px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.845rem;background:#fff;cursor:pointer;font-family:inherit;transition:border-color var(--tr)}
.filter-select:focus{outline:none;border-color:var(--g600)}
.result-count{font-size:.78rem;color:#6b7c6d;white-space:nowrap}

/* ── Buttons ─────────────────────────────────────────────*/
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;cursor:pointer;transition:all var(--tr);white-space:nowrap}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-danger{background:var(--red);color:#fff}.btn-danger:hover{background:#b71c1c}
.btn-icon{width:30px;height:30px;border:none;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;transition:all var(--tr);flex-shrink:0;font-family:inherit}
.btn-icon:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.18)}
.bi-edit{background:#fff3e0;color:var(--orange)}.bi-edit:hover{background:var(--orange);color:#fff}
.bi-activate{background:var(--g100);color:var(--g700)}.bi-activate:hover{background:var(--g700);color:#fff}
.bi-deactivate{background:#ffebee;color:var(--red)}.bi-deactivate:hover{background:var(--red);color:#fff}
.bi-del{background:#ffebee;color:var(--red)}.bi-del:hover{background:var(--red);color:#fff}
.bi-del[disabled]{opacity:.3;cursor:not-allowed;pointer-events:none;transform:none;box-shadow:none}

/* ── Form ────────────────────────────────────────────────*/
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:18px;margin-bottom:18px}
.form-group{display:flex;flex-direction:column}
.form-group label{font-size:.82rem;font-weight:600;margin-bottom:7px;color:#444}
.req-label{color:var(--red)}
.form-control{padding:10px 13px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;transition:border-color var(--tr),box-shadow var(--tr)}
.form-control:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
textarea.form-control{resize:vertical;min-height:86px}

/* ── Table ───────────────────────────────────────────────*/
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:12px 14px;text-align:left;font-size:.78rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--tr)}
tbody tr:hover{background:#f5fbf5}
tbody tr.row-inactive{opacity:.58;background:#fafafa}
tbody td{padding:12px 14px;font-size:.855rem;vertical-align:middle}
.req-name{font-weight:600;color:var(--g800)}
.req-desc{font-size:.73rem;color:#999;margin-top:2px}
.action-cell{display:flex;gap:5px}

/* ── Badges ──────────────────────────────────────────────*/
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.badge-active{background:#e8f5e9;color:#2e7d32}
.badge-inactive{background:#ffebee;color:#c62828}

/* ── Skeleton / Empty ────────────────────────────────────*/
.empty-state{text-align:center;padding:56px 20px;color:#8a9a8b}
.empty-state i{font-size:2.5rem;margin-bottom:12px;display:block;opacity:.4}
.skel{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;height:13px;display:inline-block;width:80%}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ── Notification stack ──────────────────────────────────*/
#notif-stack{position:fixed;top:68px;right:18px;z-index:9999;display:flex;flex-direction:column;gap:9px;pointer-events:none}
.notif{pointer-events:all;display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);max-width:360px;animation:slideN .25s ease;transition:opacity .3s,transform .3s}
@keyframes slideN{from{transform:translateX(30px);opacity:0}to{transform:translateX(0);opacity:1}}
.notif.success{background:#1b5e20;color:#fff}.notif.error{background:#b71c1c;color:#fff}
.notif.warning{background:#e65100;color:#fff}.notif.info{background:#1565c0;color:#fff}
.notif-icon{font-size:1.1rem;margin-top:1px;flex-shrink:0}
.notif-body{flex:1}.notif-title{font-weight:700;font-size:.84rem}.notif-msg{font-size:.78rem;opacity:.9;margin-top:2px}
.notif-close{background:rgba(255,255,255,.2);border:none;color:#fff;width:22px;height:22px;border-radius:50%;font-size:.72rem;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0}

/* ── Dialog ──────────────────────────────────────────────*/
.dialog{display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(3px)}
.dialog.active{display:flex;align-items:center;justify-content:center;padding:20px}
.dlg-box{background:#fff;border-radius:var(--radius-lg);width:100%;max-width:420px;box-shadow:var(--shadow-lg);animation:slideD .25s ease}
@keyframes slideD{from{transform:translateY(-18px);opacity:0}to{transform:translateY(0);opacity:1}}
.dlg-head{padding:22px 22px 0;display:flex;align-items:flex-start;gap:14px}
.dlg-ico{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0}
.dlg-ico.danger{background:#ffebee;color:var(--red)}.dlg-ico.warning{background:#fff3e0;color:var(--amber)}.dlg-ico.info{background:var(--g100);color:var(--g700)}
.dlg-title{font-size:1.05rem;font-weight:700;color:#222;margin-top:4px}
.dlg-body{padding:14px 22px 22px}
.dlg-msg{font-size:.875rem;color:#444;line-height:1.65;margin-bottom:18px}
.dlg-actions{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}

/* ── Page loader ─────────────────────────────────────────*/
#page-loader{position:fixed;inset:0;background:rgba(255,255,255,.88);z-index:2000;display:none;align-items:center;justify-content:center;flex-direction:column;gap:12px}
#page-loader.show{display:flex}
.spin{width:42px;height:42px;border:4px solid #e8f5e9;border-top-color:var(--g700);border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
#page-loader p{font-size:.88rem;color:var(--g800);font-weight:600}
</style>
</head>
<body>

<div id="page-loader"><div class="spin"></div><p>Please wait…</p></div>
<div id="notif-stack"></div>

<?php require_once 'nav.php'; ?>

<div class="page">

  <!-- ── Page Header ── -->
  <div class="page-header">
    <div>
      <h1><i class="fas fa-clipboard-list" style="margin-right:10px;opacity:.85"></i>Requirements Setup</h1>
      <p>Manage school requirements · Section-aware targeting · Safe deactivation</p>
    </div>
    <div class="stats-row">
      <div class="stat-pill"><span class="n"><?= intval($stats['tc']) ?></span><span class="l">Total</span></div>
      <div class="stat-pill"><span class="n" style="color:#a5d6a7"><?= intval($stats['ac']) ?></span><span class="l">Active</span></div>
      <div class="stat-pill"><span class="n" style="color:#ef9a9a"><?= intval($stats['ic']) ?></span><span class="l">Inactive</span></div>
    </div>
  </div>

  <!-- ── Chart Filter Bar ── -->
  <div class="chart-filter-bar">
    <i class="fas fa-chart-bar" style="color:var(--g700);font-size:1.1rem"></i>
    <label>View Stats For:</label>
    <select class="filter-select" id="chartTerm" style="min-width:120px">
      <option value="">Select Term</option>
      <option>Term 1</option><option>Term 2</option><option>Term 3</option>
    </select>
    <input type="text" class="filter-select" id="chartYear" placeholder="Year e.g. <?= $currentYear ?>" value="<?= $currentYear ?>" style="width:120px">
    <button class="btn btn-primary" id="loadChartsBtn"><i class="fas fa-chart-line"></i> Load Charts</button>
    <span id="chartStatus" style="font-size:.8rem;color:#888"></span>
  </div>

  <!-- ── Charts ── -->
  <div class="charts-grid" id="chartsGrid">
    <div class="chart-card">
      <h3><i class="fas fa-school"></i> Completion Rate by Class</h3>
      <div style="position:relative;height:230px">
        <div class="chart-placeholder" id="ph1"><i class="fas fa-chart-bar"></i>Select term &amp; year to load</div>
        <canvas id="classChart" style="display:none"></canvas>
      </div>
    </div>
    <div class="chart-card">
      <h3><i class="fas fa-coins"></i> Cash: Collected vs Outstanding</h3>
      <div style="position:relative;height:180px;display:flex;align-items:center;justify-content:center">
        <div class="chart-placeholder" id="ph2"><i class="fas fa-chart-pie"></i>Select term &amp; year to load</div>
        <canvas id="cashChart" style="display:none"></canvas>
      </div>
      <div id="cashLegend" style="display:none;font-size:.76rem;margin-top:10px"></div>
    </div>
    <div class="chart-card">
      <h3><i class="fas fa-triangle-exclamation"></i> Top 5 Most Defaulted</h3>
      <div style="position:relative;height:210px">
        <div class="chart-placeholder" id="ph3"><i class="fas fa-chart-bar"></i>Select term &amp; year to load</div>
        <canvas id="defaultChart" style="display:none"></canvas>
      </div>
    </div>
  </div>

  <!-- ── Add / Edit Form ── -->
  <div class="card" id="formCard">
    <div class="card-head">
      <h2><i class="fas fa-plus-circle"></i><span id="formTitle">Add New Requirement</span></h2>
    </div>
    <div class="card-body">
      <form id="reqForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="requirement_id" id="reqId" value="">

        <div class="form-grid">
          <div class="form-group" style="grid-column:1/-1">
            <label>Requirement Name <span class="req-label">*</span></label>
            <input type="text" name="requirement_name" id="reqName" class="form-control" placeholder="e.g. Exercise Books, Lab Coat, School Fees…" required>
          </div>
          <div class="form-group">
            <label>Quantity per Student <span class="req-label">*</span></label>
            <input type="number" name="quantity" id="reqQty" class="form-control" value="1" min="1" required>
          </div>
          <div class="form-group">
            <label>Cash Equivalent (UGX) <span class="req-label">*</span></label>
            <input type="number" name="cash_equivalent" id="reqCash" class="form-control" value="0" min="0" step="500" required>
          </div>
          <div class="form-group">
            <label>Term <span class="req-label">*</span></label>
            <select name="term" id="reqTerm" class="form-control" required>
              <option value="">Select Term</option>
              <option>Term 1</option><option>Term 2</option><option>Term 3</option>
            </select>
          </div>
          <div class="form-group">
            <label>Academic Year <span class="req-label">*</span></label>
            <input type="text" name="academic_year" id="reqYear" class="form-control" value="<?= $currentYear ?>" placeholder="e.g. <?= $currentYear ?>" required>
          </div>
        </div>

        <!-- Scope Selector -->
        <div class="scope-section-label"><span class="req-label">*</span> Requirement Applies To</div>
        <div class="scope-grid" id="scopeGrid">
          <label class="scope-tile selected" data-scope="all">
            <input type="radio" name="scope" value="all" checked>
            <div class="si"><i class="fas fa-users"></i></div>
            <div class="sl">All Students</div>
            <div class="ss">Day + Boarding · All Classes</div>
          </label>
          <label class="scope-tile" data-scope="day">
            <input type="radio" name="scope" value="day">
            <div class="si"><i class="fas fa-sun"></i></div>
            <div class="sl">Day Students Only</div>
            <div class="ss">All Classes</div>
          </label>
          <label class="scope-tile" data-scope="boarding">
            <input type="radio" name="scope" value="boarding">
            <div class="si"><i class="fas fa-moon"></i></div>
            <div class="sl">Boarding Students Only</div>
            <div class="ss">All Classes</div>
          </label>
          <label class="scope-tile" data-scope="class_all">
            <input type="radio" name="scope" value="class_all">
            <div class="si"><i class="fas fa-chalkboard"></i></div>
            <div class="sl">Specific Class</div>
            <div class="ss">Day + Boarding</div>
          </label>
          <label class="scope-tile" data-scope="class_day">
            <input type="radio" name="scope" value="class_day">
            <div class="si"><i class="fas fa-chalkboard-user"></i></div>
            <div class="sl">Class — Day Only</div>
            <div class="ss">Specific Class</div>
          </label>
          <label class="scope-tile" data-scope="class_boarding">
            <input type="radio" name="scope" value="class_boarding">
            <div class="si"><i class="fas fa-building"></i></div>
            <div class="sl">Class — Boarding Only</div>
            <div class="ss">Specific Class</div>
          </label>
        </div>

        <div class="class-stream-row" id="classStreamRow">
          <div class="form-group">
            <label>Class <span class="req-label">*</span></label>
            <select name="class" id="reqClass" class="form-control">
              <option value="">Select Class</option>
              <?php foreach ($classes as $c): ?>
              <option value="<?= htmlspecialchars($c['current_class']) ?>"><?= htmlspecialchars($c['current_class']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Stream <small style="font-weight:400;color:#aaa">(optional — leave blank for all streams)</small></label>
            <select name="stream" id="reqStream" class="form-control">
              <option value="">All Streams in Class</option>
              <?php foreach ($streams as $s): ?>
              <option value="<?= htmlspecialchars($s['stream']) ?>"><?= htmlspecialchars($s['stream']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:22px">
          <label>Description <small style="font-weight:400;color:#aaa">(optional)</small></label>
          <textarea name="description" id="reqDesc" class="form-control" placeholder="Any additional notes or instructions for this requirement…"></textarea>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap">
          <button type="button" class="btn btn-outline" id="cancelBtn"><i class="fas fa-times"></i> Cancel</button>
          <button type="submit" class="btn btn-primary" id="saveBtn"><i class="fas fa-save"></i> Save Requirement</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Requirements List ── -->
  <div class="card">
    <div class="card-head">
      <h2><i class="fas fa-list-check"></i> Requirements List</h2>
    </div>
    <div class="toolbar">
      <div class="tl">
        <select class="filter-select" id="fTerm">
          <option value="">All Terms</option>
          <option>Term 1</option><option>Term 2</option><option>Term 3</option>
        </select>
        <input type="text" class="filter-select" id="fYear" placeholder="Year…" style="width:88px" maxlength="4">
        <select class="filter-select" id="fClass">
          <option value="">All Classes</option>
          <?php foreach ($classes as $c): ?><option value="<?= htmlspecialchars($c['current_class']) ?>"><?= htmlspecialchars($c['current_class']) ?></option><?php endforeach; ?>
        </select>
        <select class="filter-select" id="fSection">
          <option value="">All Sections</option>
          <option value="All">All (Day+Boarding)</option>
          <option value="Day">Day Only</option>
          <option value="Boarding">Boarding Only</option>
        </select>
        <select class="filter-select" id="fStatus">
          <option value="">All Status</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
        <span class="result-count" id="resultCount"></span>
      </div>
      <div class="tr-bar">
        <button class="btn btn-outline" id="clearFiltersBtn"><i class="fas fa-rotate-left"></i> Reset</button>
      </div>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Requirement</th>
            <th>Qty</th>
            <th>Cash (UGX)</th>
            <th>Applies To</th>
            <th>Term / Year</th>
            <th>Status</th>
            <th>Records</th>
            <th>Created By</th>
            <th style="width:110px">Actions</th>
          </tr>
        </thead>
        <tbody id="reqTableBody">
          <tr><td colspan="9"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Loading requirements…</p></div></td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- end .page -->

<!-- ── Confirm Dialog ── -->
<div class="dialog" id="confirmDlg">
  <div class="dlg-box">
    <div class="dlg-head">
      <div class="dlg-ico" id="dlgIco"></div>
      <div>
        <div class="dlg-title" id="dlgTitle"></div>
      </div>
    </div>
    <div class="dlg-body">
      <p class="dlg-msg" id="dlgMsg"></p>
      <div class="dlg-actions">
        <button class="btn btn-outline" onclick="closeDlg()">Cancel</button>
        <button class="btn" id="dlgConfirmBtn" onclick="runDlg()"></button>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
let allReqs = [], dlgCb = null;
let chartClass = null, chartCash = null, chartDefault = null;

// ── Scope tiles ───────────────────────────────────────────────────────────────
document.querySelectorAll('.scope-tile').forEach(tile => {
    tile.addEventListener('click', () => {
        const scope = tile.dataset.scope;
        document.querySelectorAll('.scope-tile').forEach(t => t.classList.remove('selected'));
        tile.classList.add('selected');
        tile.querySelector('input[type=radio]').checked = true;
        const needsClass = scope.startsWith('class_');
        document.getElementById('classStreamRow').classList.toggle('show', needsClass);
        if (!needsClass) { document.getElementById('reqClass').value = ''; document.getElementById('reqStream').value = ''; }
    });
});

// ── Charts ────────────────────────────────────────────────────────────────────
document.getElementById('loadChartsBtn').addEventListener('click', loadCharts);

async function loadCharts() {
    const term = document.getElementById('chartTerm').value;
    const year = document.getElementById('chartYear').value.trim();
    if (!term || !year) { notify('Missing Filters', 'Please select both term and year to load charts.', 'warning'); return; }
    if (!/^\d{4}$/.test(year)) { notify('Invalid Year', 'Please enter a valid 4-digit year.', 'warning'); return; }
    const btn = document.getElementById('loadChartsBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading…';
    document.getElementById('chartStatus').textContent = '';
    try {
        const r = await fetch(`requirements_setup.php?action=get_chart_data&term=${encodeURIComponent(term)}&year=${encodeURIComponent(year)}`);
        const d = await r.json();
        if (!d.success) throw new Error(d.message);
        renderClassChart(d.completion);
        renderCashChart(d.cash);
        renderDefaultChart(d.defaulted);
        document.getElementById('chartStatus').textContent = `Showing data for ${term} ${year}`;
    } catch(e) { notify('Chart Error', e.message, 'error'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-chart-line"></i> Load Charts'; }
}

function renderClassChart(data) {
    const el = document.getElementById('classChart');
    document.getElementById('ph1').style.display = 'none'; el.style.display = 'block';
    const pcts = data.map(d => d.total > 0 ? +((d.done / d.total) * 100).toFixed(1) : 0);
    if (chartClass) chartClass.destroy();
    chartClass = new Chart(el, {
        type: 'bar',
        data: { labels: data.map(d => d.current_class), datasets: [{ label: '% Completed', data: pcts, backgroundColor: pcts.map(v => v >= 80 ? '#388e3c' : v >= 50 ? '#f57c00' : '#d32f2f'), borderRadius: 5, borderSkipped: false }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `${c.raw}% completed` } } }, scales: { y: { max: 100, ticks: { callback: v => v + '%' }, grid: { color: '#f0f0f0' } }, x: { grid: { display: false } } } }
    });
}

function renderCashChart(cash) {
    const el = document.getElementById('cashChart');
    document.getElementById('ph2').style.display = 'none'; el.style.display = 'block';
    // collected = cash payments + value of items brought (both returned by PHP)
    const col = (parseFloat(cash.collected) || 0) + (parseFloat(cash.items_value) || 0);
    const out = parseFloat(cash.outstanding) || 0;
    if (chartCash) chartCash.destroy();
    const donutData   = (col === 0 && out === 0) ? [1]              : [col, out];
    const donutColors = (col === 0 && out === 0) ? ['#e8ede9']      : ['#388e3c', '#d32f2f'];
    const donutLabels = (col === 0 && out === 0) ? ['No data']      : ['Collected', 'Outstanding'];
    chartCash = new Chart(el, {
        type: 'doughnut',
        data: { labels: donutLabels, datasets: [{ data: donutData, backgroundColor: donutColors, borderWidth: 3, borderColor: '#fff', hoverOffset: 8 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `UGX ${Number(c.raw).toLocaleString('en-UG')}` } } } }
    });
    const fmt = v => 'UGX ' + Number(v).toLocaleString('en-UG');
    const leg = document.getElementById('cashLegend');
    leg.style.display = 'block';
    leg.innerHTML = `<div style="display:flex;flex-direction:column;gap:6px">
        <div style="display:flex;align-items:center;gap:8px"><span style="width:11px;height:11px;border-radius:2px;background:#388e3c;display:inline-block;flex-shrink:0"></span><span>Collected: <strong>${fmt(col)}</strong></span></div>
        <div style="display:flex;align-items:center;gap:8px"><span style="width:11px;height:11px;border-radius:2px;background:#d32f2f;display:inline-block;flex-shrink:0"></span><span>Outstanding: <strong>${fmt(out)}</strong></span></div>
    </div>`;
}

function renderDefaultChart(data) {
    const el = document.getElementById('defaultChart');
    document.getElementById('ph3').style.display = 'none'; el.style.display = 'block';
    if (!data.length) {
        el.style.display = 'none';
        document.getElementById('ph3').style.display = 'flex';
        document.getElementById('ph3').innerHTML = '<i class="fas fa-circle-check" style="color:#388e3c;font-size:2rem"></i><span style="color:#388e3c;font-weight:600">No pending defaults — excellent!</span>';
        return;
    }
    if (chartDefault) chartDefault.destroy();
    chartDefault = new Chart(el, {
        type: 'bar',
        data: {
            labels: data.map(d => d.requirement_name.length > 20 ? d.requirement_name.slice(0, 20) + '…' : d.requirement_name),
            datasets: [
                { label: 'Pending', data: data.map(d => +d.pending_count), backgroundColor: '#d32f2f', borderRadius: 4 },
                { label: 'Partial', data: data.map(d => +d.partial_count), backgroundColor: '#f57c00', borderRadius: 4 }
            ]
        },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } }, scales: { x: { stacked: true, grid: { color: '#f0f0f0' } }, y: { stacked: true, grid: { display: false } } } }
    });
}

// ── Load requirements table ───────────────────────────────────────────────────
async function loadRequirements() {
    const p = new URLSearchParams({
        action: 'fetch_requirements',
        term:    document.getElementById('fTerm').value,
        year:    document.getElementById('fYear').value,
        class:   document.getElementById('fClass').value,
        section: document.getElementById('fSection').value,
        status:  document.getElementById('fStatus').value
    });
    showLoader();
    try {
        const r = await fetch(`requirements_setup.php?${p}`);
        const d = await r.json();
        if (!d.success) throw new Error(d.message);
        allReqs = d.data;
        renderTable(d.data);
    } catch(e) { notify('Error', e.message, 'error'); }
    finally { hideLoader(); }
}

function scopeHTML(req) {
    const cls  = req['class'] ? `${esc(req['class'])}${req.stream ? ' · ' + esc(req.stream) : ''}` : 'All Classes';
    const sec  = req.section_type || 'All';
    const badge = sec === 'Day'      ? `<span class="sbadge sbadge-day"><i class="fas fa-sun"></i> Day Only</span>`
                : sec === 'Boarding' ? `<span class="sbadge sbadge-boarding"><i class="fas fa-moon"></i> Boarding Only</span>`
                :                     `<span class="sbadge sbadge-all"><i class="fas fa-users"></i> All</span>`;
    return `<div style="font-size:.8rem;font-weight:600;margin-bottom:4px">${cls}</div>${badge}`;
}

function renderTable(data) {
    const tbody = document.getElementById('reqTableBody');
    document.getElementById('resultCount').textContent = `${data.length} requirement${data.length !== 1 ? 's' : ''}`;
    if (!data.length) {
        tbody.innerHTML = `<tr><td colspan="9"><div class="empty-state"><i class="fas fa-clipboard-list"></i><p>No requirements found matching these filters</p></div></td></tr>`;
        return;
    }
    // Build rows via DOM — never string-interpolate values into onclick attributes
    // (esc() does not escape single quotes, so inline onclick is XSS-vulnerable for names like O'Clock)
    tbody.innerHTML = '';
    data.forEach(req => {
        const inactive   = req.status === 'inactive';
        const hasRecords = parseInt(req.record_count) > 0;
        const cash       = parseFloat(req.cash_equivalent).toLocaleString('en-UG', { minimumFractionDigits: 0 });

        const tr = document.createElement('tr');
        if (inactive) tr.className = 'row-inactive';
        tr.innerHTML = `
          <td>
            <div class="req-name">${esc(req.requirement_name)}</div>
            ${req.description ? `<div class="req-desc">${esc(req.description.length > 70 ? req.description.slice(0,70)+'…' : req.description)}</div>` : ''}
          </td>
          <td>${esc(String(req.quantity_per_student))}</td>
          <td>${esc(cash)}</td>
          <td>${scopeHTML(req)}</td>
          <td><div style="font-size:.8rem">${esc(req.term)}</div><div style="font-size:.73rem;color:#888">${esc(req.academic_year)}</div></td>
          <td><span class="badge badge-${req.status === 'active' ? 'active' : 'inactive'}">${esc(req.status)}</span></td>
          <td>
            <span style="font-size:.8rem;font-weight:600;color:${hasRecords ? 'var(--g700)' : '#ccc'}">
              ${req.record_count} record${req.record_count != 1 ? 's' : ''}
            </span>
          </td>
          <td style="font-size:.8rem;color:#555">${esc(req.user_name || '—')}</td>
          <td>
            <div class="action-cell">
              <button class="btn-icon bi-edit"       data-id="${req.requirement_id}" title="Edit Requirement"><i class="fas fa-edit"></i></button>
              <button class="btn-icon ${inactive ? 'bi-activate' : 'bi-deactivate'}" data-id="${req.requirement_id}" data-name="${esc(req.requirement_name)}" title="${inactive ? 'Activate' : 'Deactivate'}">
                <i class="fas fa-${inactive ? 'eye' : 'eye-slash'}"></i>
              </button>
              <button class="btn-icon bi-del" data-id="${req.requirement_id}" data-name="${esc(req.requirement_name)}" data-has="${hasRecords ? '1' : '0'}"
                ${hasRecords ? 'disabled' : ''}
                title="${hasRecords ? 'Has student records — deactivate instead' : 'Delete permanently'}">
                <i class="fas fa-trash"></i>
              </button>
            </div>
          </td>`;

        // Wire up actions via addEventListener — no quoting concerns at all
        tr.querySelector('.bi-edit').addEventListener('click', () => editReq(req.requirement_id));
        const toggleBtn = tr.querySelector('.bi-activate, .bi-deactivate');
        if (toggleBtn) toggleBtn.addEventListener('click', () => toggleStatus(req.requirement_id, req.requirement_name));
        const delBtn = tr.querySelector('.bi-del:not([disabled])');
        if (delBtn) delBtn.addEventListener('click', () => deleteReq(req.requirement_id, req.requirement_name, hasRecords));

        tbody.appendChild(tr);
    });
}

// ── Edit ──────────────────────────────────────────────────────────────────────
async function editReq(id) {
    showLoader();
    try {
        const r = await fetch(`requirements_setup.php?action=fetch_single&id=${id}`);
        const d = await r.json();
        if (!d.success) throw new Error(d.message);
        const req = d.data;
        document.getElementById('formTitle').textContent = 'Edit Requirement';
        document.getElementById('reqId').value    = req.requirement_id;
        document.getElementById('reqName').value  = req.requirement_name;
        document.getElementById('reqQty').value   = req.quantity_per_student;
        document.getElementById('reqCash').value  = req.cash_equivalent;
        document.getElementById('reqTerm').value  = req.term;
        document.getElementById('reqYear').value  = req.academic_year;
        document.getElementById('reqDesc').value  = req.description || '';
        // Determine scope
        let scope = 'all';
        if (req['class']) {
            scope = req.section_type === 'Day' ? 'class_day' : req.section_type === 'Boarding' ? 'class_boarding' : 'class_all';
        } else {
            scope = req.section_type === 'Day' ? 'day' : req.section_type === 'Boarding' ? 'boarding' : 'all';
        }
        document.querySelectorAll('.scope-tile').forEach(t => t.classList.remove('selected'));
        const tile = document.querySelector(`.scope-tile[data-scope="${scope}"]`);
        if (tile) { tile.classList.add('selected'); tile.querySelector('input[type=radio]').checked = true; }
        const needsClass = scope.startsWith('class_');
        document.getElementById('classStreamRow').classList.toggle('show', needsClass);
        if (needsClass) {
            document.getElementById('reqClass').value  = req['class'] || '';
            document.getElementById('reqStream').value = req.stream || '';
        }
        document.getElementById('formCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch(e) { notify('Error', e.message, 'error'); }
    finally { hideLoader(); }
}

// ── Toggle status ─────────────────────────────────────────────────────────────
function toggleStatus(id, name) {
    const req = allReqs.find(r => r.requirement_id == id);
    const isActive = req && req.status === 'active';
    showDlg(
        isActive ? 'warning' : 'info',
        isActive ? 'fa-eye-slash' : 'fa-eye',
        isActive ? 'Deactivate Requirement' : 'Activate Requirement',
        isActive
            ? `<strong>${esc(name)}</strong> will be hidden from new records. All existing student data is preserved and can be restored by activating it again.`
            : `<strong>${esc(name)}</strong> will become visible and assignable to students again.`,
        isActive ? 'btn-danger' : 'btn-primary',
        isActive ? 'Yes, Deactivate' : 'Yes, Activate',
        () => doToggle(id)
    );
}

async function doToggle(id) {
    showLoader();
    try {
        const fd = new FormData();
        fd.append('action', 'toggle_status'); fd.append('id', id); fd.append('csrf_token', CSRF);
        const r = await fetch('requirements_setup.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.success) throw new Error(d.message);
        notify('Updated', d.message, 'success');
        loadRequirements();
    } catch(e) { notify('Error', e.message, 'error'); }
    finally { hideLoader(); }
}

// ── Delete ────────────────────────────────────────────────────────────────────
function deleteReq(id, name, hasRecords) {
    if (hasRecords) { notify('Cannot Delete', 'This requirement has student records. Use the deactivate button (eye icon) to hide it without losing data.', 'warning'); return; }
    showDlg('danger', 'fa-trash', 'Delete Requirement',
        `<strong>${esc(name)}</strong> will be <strong>permanently deleted</strong>. This action cannot be undone.`,
        'btn-danger', 'Delete Permanently', () => doDelete(id)
    );
}

async function doDelete(id) {
    showLoader();
    try {
        const fd = new FormData();
        fd.append('action', 'delete_requirement'); fd.append('id', id); fd.append('csrf_token', CSRF);
        const r = await fetch('requirements_setup.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.success) throw new Error(d.message);
        notify('Deleted', d.message, 'success');
        loadRequirements();
    } catch(e) { notify('Error', e.message, 'error'); }
    finally { hideLoader(); }
}

// ── Form submit ───────────────────────────────────────────────────────────────
document.getElementById('reqForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const scope = this.querySelector('input[name=scope]:checked')?.value || 'all';
    if (scope.startsWith('class_') && !document.getElementById('reqClass').value) {
        notify('Validation Error', 'Please select a Class for class-specific requirements.', 'warning'); return;
    }
    const btn = document.getElementById('saveBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    try {
        const r = await fetch('requirements_setup.php', { method: 'POST', body: new FormData(this) });
        const d = await r.json();
        if (!d.success) throw new Error(d.message);
        notify('Saved', d.message, 'success');
        resetForm();
        loadRequirements();
    } catch(e) { notify('Error', e.message, 'error'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Requirement'; }
});

function resetForm() {
    document.getElementById('reqForm').reset();
    document.getElementById('reqId').value = '';
    document.getElementById('formTitle').textContent = 'Add New Requirement';
    document.getElementById('reqYear').value = '<?= $currentYear ?>';
    document.querySelectorAll('.scope-tile').forEach(t => t.classList.remove('selected'));
    document.querySelector('.scope-tile[data-scope="all"]').classList.add('selected');
    document.querySelector('.scope-tile[data-scope="all"] input[type=radio]').checked = true;
    document.getElementById('classStreamRow').classList.remove('show');
}

document.getElementById('cancelBtn').addEventListener('click', resetForm);

// Filter events
['fTerm', 'fClass', 'fSection', 'fStatus'].forEach(id =>
    document.getElementById(id).addEventListener('change', loadRequirements));
let yearTimer;
document.getElementById('fYear').addEventListener('input', function() {
    clearTimeout(yearTimer);
    if (this.value.length === 4 || this.value === '') yearTimer = setTimeout(loadRequirements, 400);
});
document.getElementById('clearFiltersBtn').addEventListener('click', () => {
    ['fTerm', 'fYear', 'fClass', 'fSection', 'fStatus'].forEach(id => document.getElementById(id).value = '');
    loadRequirements();
});

// ── Dialog ────────────────────────────────────────────────────────────────────
function showDlg(type, icon, title, msg, confirmCls, confirmLabel, cb) {
    const ico = document.getElementById('dlgIco');
    ico.className = `dlg-ico ${type}`;
    ico.innerHTML = `<i class="fas ${icon}"></i>`;
    document.getElementById('dlgTitle').textContent = title;
    document.getElementById('dlgMsg').innerHTML = msg;
    const btn = document.getElementById('dlgConfirmBtn');
    btn.className = `btn ${confirmCls}`; btn.textContent = confirmLabel;
    dlgCb = cb;
    document.getElementById('confirmDlg').classList.add('active');
}
function closeDlg() { document.getElementById('confirmDlg').classList.remove('active'); dlgCb = null; }
function runDlg() { if (dlgCb) dlgCb(); closeDlg(); }
document.getElementById('confirmDlg').addEventListener('click', e => { if (e.target.id === 'confirmDlg') closeDlg(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDlg(); });

// ── Utilities ─────────────────────────────────────────────────────────────────
function showLoader() { document.getElementById('page-loader').classList.add('show'); }
function hideLoader() { document.getElementById('page-loader').classList.remove('show'); }
function esc(v) { return String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function notify(title, msg, type = 'success', dur = 4500) {
    const icons = { success:'fa-circle-check', error:'fa-circle-xmark', warning:'fa-triangle-exclamation', info:'fa-circle-info' };
    const n = document.createElement('div');
    n.className = `notif ${type}`;
    n.innerHTML = `<i class="fas ${icons[type]||icons.info} notif-icon"></i><div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div><button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('notif-stack').prepend(n);
    setTimeout(() => { n.style.opacity = '0'; n.style.transform = 'translateX(30px)'; setTimeout(() => n.remove(), 300); }, dur);
}

window.addEventListener('load', loadRequirements);
</script>
</body>
</html>
<?php $conn->close(); ?>
