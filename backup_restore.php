<?php


declare(strict_types=1);

require_once 'conn.php';
require_once 'auth.php';

// ── [BUG-10] Role gate ────────────────────────────────────────────────────────
// Adjust the constant / session key to match your auth.php implementation.
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['developer', 'super user'], true)) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
    } else {
        http_response_code(403);
        echo '<h1>403 Forbidden</h1>';
    }
    exit;
}

// ── [BUG-06] CSRF helpers ─────────────────────────────────────────────────────
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_Csrf_Token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }
}

// ── Paths ─────────────────────────────────────────────────────────────────────
// [BUG-09] Move schedule file ABOVE webroot. Adjust BASE_DIR to your setup.
define('BASE_DIR',    dirname(__DIR__) . '/propms_data/');   // e.g. /var/propms_data/
define('BACKUP_DIR',  BASE_DIR . 'backups/');
define('LOGS_DIR',    BASE_DIR . 'logs/');
define('SCHED_FILE',  BASE_DIR . 'backup_schedule.json');

// ─────────────────────────────────────────────────────────────────────────────
class AutoBackupScheduler
{
    private \mysqli $conn;

    public function __construct(\mysqli $connection)
    {
        if (!$connection || !$connection->ping()) {
            throw new \RuntimeException('Database connection is not valid.');
        }
        $this->conn = $connection;
        $this->initializeDirectories();
    }

    // ── Directory setup ───────────────────────────────────────────────────────
    private function initializeDirectories(): void
    {
        foreach ([BACKUP_DIR, LOGS_DIR] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
                throw new \RuntimeException("Failed to create directory: {$dir}");
            }
        }
    }

    // ── Auto backup check ─────────────────────────────────────────────────────
    public function checkAndRunAutoBackup(): array|false
    {
        try {
            $schedule    = $this->getScheduleSettings();
            $lastBackup  = (int)($schedule['last_backup_time'] ?? 0);
            $elapsed     = time() - $lastBackup;

            if ($schedule['auto_backup_enabled'] && $elapsed >= 86400) {
                return $this->createAutoBackup();
            }
            return false;
        } catch (\Throwable $e) {
            $this->log('AUTO', '', 'FAILED', $e->getMessage());
            return ['success' => false, 'message' => 'Auto-backup failed.'];
        }
    }

    public function createAutoBackup(): array
    {
        $fileName = 'auto_backup_' . date('Y-m-d_H-i-s') . '.sql';
        try {
            $result = $this->createBackup($fileName, 'Automatic daily backup');
            $status = $result['success'] ? 'SUCCESS' : 'FAILED';
            $this->log('AUTO', $fileName, $status, $result['message'] ?? '');
            if ($result['success']) {
                $this->updateLastBackupTime();
                $this->cleanOldBackups();
            }
            return $result;
        } catch (\Throwable $e) {
            $this->log('AUTO', $fileName, 'FAILED', $e->getMessage());
            return ['success' => false, 'message' => 'Auto-backup failed.'];
        }
    }

    public function createManualBackup(string $description = ''): array
    {
        $fileName = 'manual_backup_' . date('Y-m-d_H-i-s') . '.sql';
        try {
            // [BUG-05] Sanitise description before writing into SQL comment
            $safeDesc = $this->sanitizeComment($description ?: 'Manual backup');
            $result   = $this->createBackup($fileName, $safeDesc);
            $status   = $result['success'] ? 'SUCCESS' : 'FAILED';
            $this->log('MANUAL', $fileName, $status, $result['message'] ?? '');
            return $result;
        } catch (\Throwable $e) {
            $this->log('MANUAL', $fileName, 'FAILED', $e->getMessage());
            return ['success' => false, 'message' => 'Manual backup failed.'];
        }
    }

    // ── Core backup writer ────────────────────────────────────────────────────
    private function createBackup(string $fileName, string $description): array
    {
        if (!is_writable(BACKUP_DIR)) {
            throw new \RuntimeException('Backup directory is not writable.');
        }
        if (!preg_match('/^[a-zA-Z0-9_\-.]+$/', $fileName)) {
            throw new \InvalidArgumentException('Invalid filename.');
        }

        $backupFile = BACKUP_DIR . $fileName;
        $handle     = fopen($backupFile, 'w');
        if (!$handle) {
            throw new \RuntimeException("Cannot open backup file for writing.");
        }

        try {
            $result = $this->conn->query('SHOW TABLES');
            if (!$result) {
                throw new \RuntimeException('Failed to fetch table list: ' . $this->conn->error);
            }

            $tables = [];
            while ($row = $result->fetch_array()) {
                $tables[] = $row[0];
            }

            // Header
            $header  = "-- SCHOOLPILOT Database Backup\n";
            $header .= "-- Description: {$description}\n";
            $header .= "-- Generated: " . date('Y-m-d H:i:s T') . "\n";
            $header .= "-- Server: " . mysqli_get_server_info($this->conn) . "\n\n";
            $header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
            $header .= "SET AUTOCOMMIT = 0;\nSTART TRANSACTION;\n";
            $header .= "SET time_zone = \"+00:00\";\n\n";
            fwrite($handle, $header);

            foreach ($tables as $table) {
                $safeTable  = $this->conn->real_escape_string($table);
                $createRes  = $this->conn->query("SHOW CREATE TABLE `{$safeTable}`");
                if (!$createRes) {
                    throw new \RuntimeException("Could not get structure for `{$table}`.");
                }
                $createRow  = $createRes->fetch_array();
                fwrite($handle, "\n-- --------------------------------------------------------\n");
                fwrite($handle, "-- Structure: `{$table}`\n");
                fwrite($handle, "-- --------------------------------------------------------\n\n");
                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($handle, $createRow[1] . ";\n\n");

                $dataRes = $this->conn->query("SELECT * FROM `{$safeTable}`");
                if (!$dataRes) {
                    throw new \RuntimeException("Could not read data from `{$table}`.");
                }

                if ($dataRes->num_rows > 0) {
                    fwrite($handle, "-- Data: `{$table}`\n\n");
                    $fields     = $dataRes->fetch_fields();
                    $fieldNames = array_map(fn($f) => '`' . $f->name . '`', $fields);

                    while ($row = $dataRes->fetch_array(MYSQLI_NUM)) {
                        $values = [];
                        foreach ($row as $val) {
                            $values[] = $val === null
                                ? 'NULL'
                                : "'" . $this->conn->real_escape_string((string)$val) . "'";
                        }
                        fwrite($handle,
                            'INSERT INTO `' . $table . '` (' . implode(', ', $fieldNames) . ') VALUES ('
                            . implode(', ', $values) . ");\n"
                        );
                    }
                    fwrite($handle, "\n");
                }
            }

            fwrite($handle, "COMMIT;\n");
            fclose($handle);
            $handle = null;

            // Try to compress
            if (class_exists('ZipArchive')) {
                $zipFile = BACKUP_DIR . str_replace('.sql', '.zip', $fileName);
                $zip     = new \ZipArchive();
                if ($zip->open($zipFile, \ZipArchive::CREATE) === true) {
                    $zip->addFile($backupFile, $fileName);
                    $zip->close();
                    @unlink($backupFile);
                    $finalName = str_replace('.sql', '.zip', $fileName);
                    return [
                        'success'  => true,
                        'message'  => 'Backup created successfully.',
                        'filename' => $finalName,
                        'size'     => $this->formatBytes((int)filesize($zipFile)),
                    ];
                }
                // [BUG-02] ZIP failed — fall through and keep the SQL file
            }

            return [
                'success'  => true,
                'message'  => 'Backup created successfully (SQL format).',
                'filename' => $fileName,
                'size'     => $this->formatBytes((int)filesize($backupFile)),
            ];

        } catch (\Throwable $e) {
            if ($handle) fclose($handle);
            if (is_file($backupFile)) @unlink($backupFile);
            throw $e;
        }
    }

    // ── Schedule settings ─────────────────────────────────────────────────────
    public function getScheduleSettings(): array
    {
        $defaults = ['auto_backup_enabled' => true, 'retention_days' => 30, 'last_backup_time' => 0];
        if (is_file(SCHED_FILE)) {
            $content  = file_get_contents(SCHED_FILE);
            $settings = json_decode((string)$content, true);
            if (is_array($settings)) {
                return array_merge($defaults, $settings);
            }
        }
        return $defaults;
    }

    public function updateScheduleSettings(array $settings): void
    {
        $result = file_put_contents(SCHED_FILE, json_encode($settings, JSON_PRETTY_PRINT), LOCK_EX);
        if ($result === false) {
            throw new \RuntimeException('Failed to write schedule settings.');
        }
    }

    private function updateLastBackupTime(): void
    {
        $s = $this->getScheduleSettings();
        $s['last_backup_time'] = time();
        $this->updateScheduleSettings($s);
    }

    // ── Retention cleanup ─────────────────────────────────────────────────────
    private function cleanOldBackups(): void
    {
        $s           = $this->getScheduleSettings();
        $retention   = max(1, (int)($s['retention_days'] ?? 30));
        $cutoff      = time() - ($retention * 86400);
        $files       = glob(BACKUP_DIR . 'auto_backup_*') ?: [];
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                unlink($file) && $this->log('CLEANUP', basename($file), 'DELETED', 'Expired backup removed.');
            }
        }
    }

    // ── Stats ─────────────────────────────────────────────────────────────────
    public function getBackupStats(): array
    {
        $all     = array_values(array_filter(glob(BACKUP_DIR . '*') ?: [], 'is_file'));
        $auto    = array_filter($all, fn($f) => str_contains(basename($f), 'auto_backup_'));
        $manual  = array_filter($all, fn($f) => str_contains(basename($f), 'manual_backup_'));
        $size    = array_sum(array_map('filesize', $all));

        $lastTime = 0;
        foreach ($all as $f) {
            $mtime = filemtime($f);
            if ($mtime > $lastTime) $lastTime = $mtime;
        }

        return [
            'total_backups'  => count($all),
            'auto_backups'   => count($auto),
            'manual_backups' => count($manual),
            'total_size'     => $this->formatBytes((int)$size),
            'last_backup'    => $lastTime > 0 ? date('d M Y, H:i', $lastTime) : 'Never',
        ];
    }

    // ── Backup list ───────────────────────────────────────────────────────────
    public function getBackupList(): array
    {
        $files = array_values(array_filter(glob(BACKUP_DIR . '*') ?: [], 'is_file'));
        $list  = [];
        foreach ($files as $f) {
            $list[] = [
                'name'      => basename($f),
                'size'      => $this->formatBytes((int)filesize($f)),
                'date'      => date('d M Y, H:i', (int)filemtime($f)),
                'timestamp' => (int)filemtime($f),
                'type'      => str_contains(basename($f), 'auto_backup_') ? 'Automatic' : 'Manual',
            ];
        }
        usort($list, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
        return $list;
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    public function deleteBackup(string $fileName): array
    {
        if (!preg_match('/^[a-zA-Z0-9_\-.]+$/', $fileName)) {
            return ['success' => false, 'message' => 'Invalid filename.'];
        }
        $path = BACKUP_DIR . $fileName;
        // Ensure no path traversal even after basename()
        if (realpath(dirname($path)) !== realpath(BACKUP_DIR)) {
            return ['success' => false, 'message' => 'Access denied.'];
        }
        if (!is_file($path)) {
            return ['success' => false, 'message' => 'Backup not found.'];
        }
        if (unlink($path)) {
            $this->log('DELETE', $fileName, 'SUCCESS', 'Deleted by user.');
            return ['success' => true, 'message' => 'Backup deleted successfully.'];
        }
        return ['success' => false, 'message' => 'Could not delete backup file.'];
    }

    // ── Download stream ───────────────────────────────────────────────────────
    // [BUG-07] Replaces the direct file href with a server-streamed response.
    public function streamDownload(string $fileName): void
    {
        if (!preg_match('/^[a-zA-Z0-9_\-.]+$/', $fileName)) {
            http_response_code(400);
            exit('Invalid filename.');
        }
        $path = BACKUP_DIR . $fileName;
        if (realpath(dirname($path)) !== realpath(BACKUP_DIR) || !is_file($path)) {
            http_response_code(404);
            exit('File not found.');
        }
        $mime = str_ends_with($fileName, '.zip') ? 'application/zip' : 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        exit;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function sanitizeComment(string $text): string
    {
        // Strip SQL comment terminators and control characters
        return preg_replace('/[\r\n\-\-]+|\/\*|\*\//', ' ', strip_tags($text));
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max(0, $bytes);
        $i     = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    private function log(string $type, string $file, string $status, string $msg = ''): void
    {
        $entry = date('Y-m-d H:i:s') . " | {$type} | {$status} | {$file} | {$msg}\n";
        @file_put_contents(LOGS_DIR . 'backup_log.txt', $entry, FILE_APPEND | LOCK_EX);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX / Download handler  (runs before any HTML output)
// ═════════════════════════════════════════════════════════════════════════════
$isAjax     = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
              && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$isDownload = isset($_GET['action']) && $_GET['action'] === 'download_backup';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    header('Content-Type: application/json');
    try {
        verifyCsrf(); // [BUG-06]
        $scheduler = new AutoBackupScheduler($conn);
        $action    = $_POST['action'] ?? '';
        $result    = [];

        switch ($action) {
            case 'manual_backup':
                $desc   = substr(trim($_POST['description'] ?? ''), 0, 200);
                $result = $scheduler->createManualBackup($desc);
                break;

            case 'update_settings':
                $enabled   = ($_POST['auto_backup_enabled'] ?? 'false') === 'true';
                $retention = max(1, min(365, (int)($_POST['retention_days'] ?? 30))); // [BUG-14]
                $current   = $scheduler->getScheduleSettings();
                $current['auto_backup_enabled'] = $enabled;
                $current['retention_days']      = $retention;
                $scheduler->updateScheduleSettings($current);
                $result = ['success' => true, 'message' => 'Settings updated.'];
                break;

            case 'delete_backup':
                $fileName = basename($_POST['fileName'] ?? '');
                $result   = !empty($fileName)
                    ? $scheduler->deleteBackup($fileName)
                    : ['success' => false, 'message' => 'No filename provided.'];
                break;

            case 'get_stats':
                $result = ['success' => true, 'stats' => $scheduler->getBackupStats()];
                break;

            case 'get_backup_list':
                $result = ['success' => true, 'backups' => $scheduler->getBackupList()];
                break;

            default:
                http_response_code(400);
                $result = ['success' => false, 'message' => 'Unknown action.'];
        }

        echo json_encode($result);

    } catch (\Throwable $e) {
        error_log('[PROPMS Backup] ' . $e->getMessage());
        http_response_code(500);
        // [BUG-08] Never expose internal error detail to client
        echo json_encode(['success' => false, 'message' => 'A server error occurred. Check logs.']);
    }
    exit;
}

// [BUG-07] Secure file download via server-side stream
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $isDownload) {
    verifyCsrf(); // token sent as GET param
    $scheduler = new AutoBackupScheduler($conn);
    $fileName  = basename($_GET['file'] ?? '');
    $scheduler->streamDownload($fileName);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// Page rendering
// ═════════════════════════════════════════════════════════════════════════════

// [BUG-01] Single try-catch wrapping both init calls
$backupStats      = ['total_backups' => 0, 'auto_backups' => 0, 'manual_backups' => 0, 'total_size' => '0 B', 'last_backup' => 'Never'];
$backupList       = [];
$scheduleSettings = ['auto_backup_enabled' => true, 'retention_days' => 30];
$initError        = null;

try {
    $scheduler        = new AutoBackupScheduler($conn);
    $scheduler->checkAndRunAutoBackup();
    $backupStats      = $scheduler->getBackupStats();
    $backupList       = $scheduler->getBackupList();
    $scheduleSettings = $scheduler->getScheduleSettings();
} catch (\Throwable $e) {
    error_log('[PROPMS Backup] Init error: ' . $e->getMessage());
    $initError = 'The backup system could not be initialised. Check server logs.';
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- [BUG-03] Fixed title capitalisation -->
    <title>Data Backup — PROPMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        /* ── Reset & Tokens ─────────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --green-900: #0d3d1e;
            --green-800: #145a2c;
            --green-700: #1a7a3c;   /* PROPMS brand */
            --green-600: #21983b;  /* [Bug-03 fixed this is not a bug just consistency] */
            --green-100: #e8f5ee;
            --green-50:  #f2fbf5;

            --red-600:   #d63031;
            --red-100:   #ffeaea;
            --amber-500: #f59e0b;
            --sky-500:   #0ea5e9;

            --surface:   #ffffff;
            --surface-2: #f6f9f7;
            --border:    #d8e8df;
            --text-1:    #0f2518;
            --text-2:    #4a6558;
            --text-3:    #8aab9a;

            --shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
            --shadow-md: 0 4px 16px rgba(13,61,30,.08), 0 1px 4px rgba(13,61,30,.05);
            --shadow-lg: 0 12px 40px rgba(13,61,30,.12);

            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;

            --font: 'Sen', system-ui, sans-serif;
            --transition: 200ms cubic-bezier(.4,0,.2,1);
        }

        html { font-size: 16px; }
        body {
            font-family: var(--font);
            background: var(--surface-2);
            color: var(--text-1);
            line-height: 1.6;
            min-height: 100vh;
        }

        /* ── Layout ─────────────────────────────────────────────────── */
        .page-wrap {
            max-width: 100%;
            margin: 0 auto;
            padding: 6rem 1.25rem 3rem;
        }

        /* ── Page header ─────────────────────────────────────────────── */
        .page-header {
            margin-bottom: 2.5rem;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .page-header__left { display: flex; align-items: center; gap: 1rem; }
        .page-header__icon {
            width: 52px; height: 52px;
            background: var(--green-700);
            border-radius: var(--radius-md);
            display: grid; place-items: center;
            color: #fff; font-size: 1.4rem;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(26,122,60,.35);
        }
        .page-header__title { font-size: 1.75rem; font-weight: 800; color: var(--text-1); line-height: 1.2; }
        .page-header__sub   { font-size: .9rem; color: var(--text-2); margin-top: .2rem; }

        /* ── Alert banner ────────────────────────────────────────────── */
        .alert {
            padding: .9rem 1.2rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.5rem;
            display: flex; align-items: center; gap: .75rem;
            font-size: .9rem; font-weight: 600;
        }
        .alert-error { background: var(--red-100); color: var(--red-600); border: 1px solid #f5c6cb; }

        /* ── Stats row ───────────────────────────────────────────────── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.75rem;
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.25rem 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: box-shadow var(--transition), transform var(--transition);
        }
        .stat-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
        .stat-card__icon {
            width: 38px; height: 38px;
            border-radius: var(--radius-sm);
            display: grid; place-items: center;
            font-size: 1rem; margin-bottom: .75rem;
        }
        .stat-card__icon--green  { background: var(--green-100); color: var(--green-700); }
        .stat-card__icon--amber  { background: #fef3c7; color: var(--amber-500); }
        .stat-card__icon--sky    { background: #e0f2fe; color: var(--sky-500); }
        .stat-card__icon--red    { background: var(--red-100); color: var(--red-600); }
        .stat-card__value { font-size: 1.85rem; font-weight: 800; color: var(--text-1); line-height: 1; }
        .stat-card__label { font-size: .8rem; font-weight: 600; color: var(--text-2); margin-top: .3rem; text-transform: uppercase; letter-spacing: .04em; }

        /* ── Main grid ───────────────────────────────────────────────── */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
            margin-bottom: 1.25rem;
        }
        @media (max-width: 860px) { .main-grid { grid-template-columns: 1fr; } }

        /* ── Card ────────────────────────────────────────────────────── */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        .card-header {
            display: flex; align-items: center; justify-content: space-between;
            padding-bottom: 1.1rem;
            margin-bottom: 1.25rem;
            border-bottom: 1px solid var(--border);
        }
        .card-header h3 {
            font-size: 1rem; font-weight: 700;
            display: flex; align-items: center; gap: .55rem;
            color: var(--text-1);
        }
        .card-header h3 i { color: var(--green-700); }

        /* ── Form ────────────────────────────────────────────────────── */
        .form-group { margin-bottom: 1.1rem; }
        .form-label {
            display: flex; align-items: center; justify-content: space-between;
            font-size: .875rem; font-weight: 600; color: var(--text-2);
            margin-bottom: .45rem;
        }
        .form-input {
            width: 100%; padding: .65rem .9rem;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: var(--font); font-size: .9rem; color: var(--text-1);
            background: var(--surface-2);
            transition: border-color var(--transition), box-shadow var(--transition);
        }
        .form-input:focus {
            outline: none;
            border-color: var(--green-700);
            box-shadow: 0 0 0 3px rgba(26,122,60,.15);
            background: var(--surface);
        }
        .form-hint { font-size: .75rem; color: var(--text-3); margin-top: .3rem; }

        /* ── Toggle ──────────────────────────────────────────────────── */
        .toggle-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: .85rem 1rem;
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            margin-bottom: 1.1rem;
        }
        .toggle-row__label {
            display: flex; align-items: center; gap: .6rem;
            font-size: .9rem; font-weight: 600;
        }
        .status-dot {
            width: 9px; height: 9px;
            border-radius: 50%;
            transition: background var(--transition);
        }
        .status-dot--on  { background: #22c55e; box-shadow: 0 0 0 3px #dcfce7; }
        .status-dot--off { background: var(--red-600); box-shadow: 0 0 0 3px var(--red-100); }

        .switch { position: relative; display: inline-block; width: 44px; height: 24px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .switch-track {
            position: absolute; inset: 0;
            background: #cbd5e1; border-radius: 24px;
            cursor: pointer; transition: background var(--transition);
        }
        .switch-track::before {
            content: ''; position: absolute;
            width: 18px; height: 18px; left: 3px; bottom: 3px;
            background: #fff; border-radius: 50%;
            transition: transform var(--transition);
            box-shadow: 0 1px 3px rgba(0,0,0,.2);
        }
        .switch input:checked + .switch-track { background: var(--green-700); }
        .switch input:checked + .switch-track::before { transform: translateX(20px); }
        .switch input:focus-visible + .switch-track { outline: 2px solid var(--green-700); outline-offset: 2px; }

        /* ── Buttons ─────────────────────────────────────────────────── */
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: .45rem;
            padding: .65rem 1.25rem;
            border: none; border-radius: var(--radius-sm);
            font-family: var(--font); font-size: .9rem; font-weight: 700;
            cursor: pointer; text-decoration: none; white-space: nowrap;
            transition: background var(--transition), transform var(--transition), box-shadow var(--transition);
        }
        .btn:active { transform: scale(.97); }
        .btn:disabled { opacity: .55; cursor: not-allowed; pointer-events: none; }

        .btn--primary {
            background: var(--green-700); color: #fff;
            box-shadow: 0 2px 8px rgba(26,122,60,.3);
        }
        .btn--primary:hover { background: var(--green-800); box-shadow: 0 4px 14px rgba(26,122,60,.4); }

        .btn--ghost {
            background: transparent; color: var(--text-2);
            border: 1.5px solid var(--border);
        }
        .btn--ghost:hover { background: var(--surface-2); border-color: var(--green-700); color: var(--green-700); }

        .btn--danger { background: var(--red-600); color: #fff; }
        .btn--danger:hover { background: #b91c1c; }

        .btn--sm { padding: .45rem .9rem; font-size: .8rem; }
        .btn--full { width: 100%; }

        .btn--icon {
            background: var(--surface-2); color: var(--text-2);
            border: 1px solid var(--border); border-radius: var(--radius-sm);
            width: 34px; height: 34px; padding: 0;
        }
        .btn--icon:hover { background: var(--green-100); color: var(--green-700); }

        /* ── Last backup strip ───────────────────────────────────────── */
        .last-backup-strip {
            display: flex; align-items: center; gap: .6rem;
            background: var(--green-50); border: 1px solid var(--green-100);
            border-radius: var(--radius-md);
            padding: .75rem 1rem; margin-top: 1.25rem;
            font-size: .85rem;
        }
        .last-backup-strip i { color: var(--green-700); }
        .last-backup-strip strong { font-weight: 700; color: var(--text-1); }

        /* ── Backup list ─────────────────────────────────────────────── */
        .history-card { margin-bottom: 0; }
        .backup-list {
            max-height: 520px; overflow-y: auto;
            padding-right: 4px;
            scrollbar-width: thin; scrollbar-color: var(--border) transparent;
        }
        .backup-list::-webkit-scrollbar { width: 5px; }
        .backup-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

        .backup-item {
            display: flex; align-items: center; gap: 1rem;
            padding: 1rem 1.1rem;
            border: 1px solid var(--border); border-radius: var(--radius-md);
            margin-bottom: .75rem; background: var(--surface);
            transition: border-color var(--transition), box-shadow var(--transition), opacity var(--transition), transform var(--transition);
        }
        .backup-item:hover { border-color: var(--green-700); box-shadow: var(--shadow-sm); }
        .backup-item:last-child { margin-bottom: 0; }

        .backup-item__icon {
            width: 40px; height: 40px; flex-shrink: 0;
            border-radius: var(--radius-sm);
            background: var(--green-100); color: var(--green-700);
            display: grid; place-items: center; font-size: 1.1rem;
        }
        .backup-item__icon--auto { background: #e0f2fe; color: var(--sky-500); }

        .backup-item__info { flex: 1; min-width: 0; }
        .backup-item__name { font-size: .88rem; font-weight: 700; color: var(--text-1); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .backup-item__meta {
            display: flex; flex-wrap: wrap; gap: .6rem;
            font-size: .76rem; color: var(--text-2); margin-top: .2rem;
        }
        .backup-item__meta span { display: flex; align-items: center; gap: .3rem; }
        .backup-item__badge {
            font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
            padding: .15rem .5rem; border-radius: 20px;
        }
        .badge--auto   { background: #e0f2fe; color: var(--sky-500); }
        .badge--manual { background: var(--green-100); color: var(--green-700); }

        .backup-item__actions { display: flex; gap: .4rem; flex-shrink: 0; }

        .empty-state {
            text-align: center; padding: 3.5rem 1rem;
            color: var(--text-3);
        }
        .empty-state i { font-size: 2.5rem; margin-bottom: .75rem; display: block; }
        .empty-state p { font-size: .9rem; }

        /* ── Skeleton loader ─────────────────────────────────────────── */
        .skeleton {
            background: linear-gradient(90deg, var(--surface-2) 25%, #e9f0ec 50%, var(--surface-2) 75%);
            background-size: 400% 100%;
            animation: shimmer 1.4s ease infinite;
            border-radius: var(--radius-sm);
        }
        @keyframes shimmer { 0% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }

        .skeleton-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem; margin-bottom: 1.75rem;
        }
        .skeleton-stat-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 1.25rem 1.5rem;
        }
        .skeleton-stat-card .sk-icon  { width: 38px; height: 38px; margin-bottom: .75rem; }
        .skeleton-stat-card .sk-value { width: 60%; height: 32px; margin-bottom: .4rem; }
        .skeleton-stat-card .sk-label { width: 80%; height: 12px; }

        .skeleton-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;
        }
        @media (max-width: 860px) { .skeleton-grid { grid-template-columns: 1fr; } }
        .skeleton-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 1.5rem;
        }
        .skeleton-card .sk-header { width: 50%; height: 18px; margin-bottom: 1.25rem; }
        .skeleton-card .sk-line   { height: 40px; border-radius: var(--radius-sm); margin-bottom: .75rem; }
        .skeleton-card .sk-line--sm { height: 24px; width: 70%; }

        .skeleton-history {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 1.5rem;
        }
        .skeleton-history .sk-header { width: 30%; height: 18px; margin-bottom: 1.25rem; }
        .skeleton-row {
            display: flex; align-items: center; gap: 1rem;
            padding: 1rem 0; border-bottom: 1px solid var(--border);
        }
        .skeleton-row .sk-avatar { width: 40px; height: 40px; border-radius: var(--radius-sm); flex-shrink: 0; }
        .skeleton-row .sk-body   { flex: 1; }
        .skeleton-row .sk-body .sk-name { height: 14px; width: 55%; margin-bottom: .4rem; }
        .skeleton-row .sk-body .sk-meta { height: 11px; width: 75%; }
        .skeleton-row .sk-btns  { display: flex; gap: .4rem; }
        .skeleton-row .sk-btn   { width: 70px; height: 30px; border-radius: var(--radius-sm); }

        /* ── Loading overlay ─────────────────────────────────────────── */
        .loading-overlay {
            position: fixed; inset: 0;
            background: rgba(255,255,255,.75);
            backdrop-filter: blur(2px);
            display: grid; place-items: center;
            z-index: 900;
        }
        .loading-box {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); padding: 2rem 2.5rem;
            box-shadow: var(--shadow-lg); text-align: center;
        }
        .spinner {
            width: 44px; height: 44px; margin: 0 auto .9rem;
            border: 4px solid var(--border); border-top-color: var(--green-700);
            border-radius: 50%; animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .loading-box p { font-size: .9rem; font-weight: 600; color: var(--text-2); }

        /* ── Modal ───────────────────────────────────────────────────── */
        .modal-backdrop {
            position: fixed; inset: 0;
            background: rgba(0,0,0,.45); backdrop-filter: blur(2px);
            display: grid; place-items: center; z-index: 950;
        }
        .modal {
            background: var(--surface); border-radius: var(--radius-lg);
            padding: 2rem; width: 90%; max-width: 420px;
            box-shadow: var(--shadow-lg); animation: popIn .2s ease;
        }
        @keyframes popIn { from { transform: scale(.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .modal__icon { font-size: 2.2rem; color: var(--red-600); margin-bottom: .5rem; }
        .modal__title { font-size: 1.2rem; font-weight: 800; margin-bottom: .5rem; }
        .modal__msg { font-size: .9rem; color: var(--text-2); }
        .modal__actions { display: flex; justify-content: flex-end; gap: .75rem; margin-top: 1.5rem; }

        /* ── Toast notifications ─────────────────────────────────────── */
        #toastContainer {
            position: fixed; top: 1.2rem; right: 1.2rem;
            z-index: 1000; width: 340px;
            display: flex; flex-direction: column; gap: .6rem;
        }
        .toast {
            background: var(--surface); border-radius: var(--radius-md);
            padding: .85rem 1rem; box-shadow: var(--shadow-lg);
            display: flex; align-items: flex-start; gap: .7rem;
            border-left: 4px solid;
            animation: slideIn .25s ease;
        }
        @keyframes slideIn { from { transform: translateX(110%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .toast--success { border-color: #22c55e; }
        .toast--error   { border-color: var(--red-600); }
        .toast--warning { border-color: var(--amber-500); }
        .toast--info    { border-color: var(--sky-500); }
        .toast__icon { font-size: 1.1rem; flex-shrink: 0; padding-top: .1rem; }
        .toast--success .toast__icon { color: #22c55e; }
        .toast--error   .toast__icon { color: var(--red-600); }
        .toast--warning .toast__icon { color: var(--amber-500); }
        .toast--info    .toast__icon { color: var(--sky-500); }
        .toast__body { flex: 1; }
        .toast__msg  { font-size: .88rem; font-weight: 600; color: var(--text-1); }
        .toast__close { background: none; border: none; color: var(--text-3); cursor: pointer; font-size: .9rem; padding: 0; }
        .toast__close:hover { color: var(--text-1); }

        /* ── Responsive tweaks ───────────────────────────────────────── */
        @media (max-width: 600px) {
            .backup-item { flex-wrap: wrap; }
            .backup-item__actions { width: 100%; justify-content: flex-end; }
            #toastContainer { width: calc(100vw - 2rem); right: 1rem; }
        }
    </style>
</head>
<body>
<?php require_once 'nav.php'; ?>

<div class="page-wrap">

    <!-- Page header -->
    <div class="page-header">
        <div class="page-header__left">
            <div class="page-header__icon"><i class="fas fa-shield-halved"></i></div>
            <div>
                <div class="page-header__title">Backup &amp; Restore</div>
                <div class="page-header__sub">Automated daily snapshots with manual override and retention policies</div>
            </div>
        </div>
    </div>

    <?php if ($initError): ?>
    <div class="alert alert-error">
        <i class="fas fa-circle-exclamation"></i>
        <?php echo htmlspecialchars($initError, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>

    <!-- ── SKELETON (shown while JS loads real data) ──────────── -->
    <div id="skeletonView">
        <div class="skeleton-stats">
            <?php for ($i = 0; $i < 5; $i++): ?>
            <div class="skeleton-stat-card">
                <div class="skeleton sk-icon"></div>
                <div class="skeleton sk-value"></div>
                <div class="skeleton sk-label"></div>
            </div>
            <?php endfor; ?>
        </div>
        <div class="skeleton-grid">
            <div class="skeleton-card">
                <div class="skeleton sk-header"></div>
                <div class="skeleton sk-line"></div>
                <div class="skeleton sk-line"></div>
                <div class="skeleton sk-line sk-line--sm"></div>
            </div>
            <div class="skeleton-card">
                <div class="skeleton sk-header"></div>
                <div class="skeleton sk-line"></div>
                <div class="skeleton sk-line sk-line--sm"></div>
            </div>
        </div>
        <div class="skeleton-history">
            <div class="skeleton sk-header"></div>
            <?php for ($i = 0; $i < 4; $i++): ?>
            <div class="skeleton-row">
                <div class="skeleton sk-avatar"></div>
                <div class="sk-body">
                    <div class="skeleton sk-name"></div>
                    <div class="skeleton sk-meta"></div>
                </div>
                <div class="sk-btns">
                    <div class="skeleton sk-btn"></div>
                    <div class="skeleton sk-btn"></div>
                </div>
            </div>
            <?php endfor; ?>
        </div>
    </div>

    <!-- ── REAL CONTENT (hidden until JS removes skeleton) ──────── -->
    <div id="mainContent" style="display:none;">

        <!-- Stats row -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-card__icon stat-card__icon--green"><i class="fas fa-database"></i></div>
                <div class="stat-card__value" id="statTotal"><?php echo (int)$backupStats['total_backups']; ?></div>
                <div class="stat-card__label">Total Backups</div>
            </div>
            <div class="stat-card">
                <div class="stat-card__icon stat-card__icon--sky"><i class="fas fa-robot"></i></div>
                <div class="stat-card__value" id="statAuto"><?php echo (int)$backupStats['auto_backups']; ?></div>
                <div class="stat-card__label">Auto Backups</div>
            </div>
            <div class="stat-card">
                <div class="stat-card__icon stat-card__icon--amber"><i class="fas fa-hand-pointer"></i></div>
                <div class="stat-card__value" id="statManual"><?php echo (int)$backupStats['manual_backups']; ?></div>
                <div class="stat-card__label">Manual Backups</div>
            </div>
            <div class="stat-card">
                <div class="stat-card__icon stat-card__icon--red"><i class="fas fa-weight-hanging"></i></div>
                <div class="stat-card__value" id="statSize"><?php echo htmlspecialchars($backupStats['total_size'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="stat-card__label">Total Size</div>
            </div>
            <div class="stat-card" style="grid-column: span 1;">
                <div class="stat-card__icon stat-card__icon--green"><i class="fas fa-clock-rotate-left"></i></div>
                <div class="stat-card__value" style="font-size:1.1rem;" id="statLast"><?php echo htmlspecialchars($backupStats['last_backup'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="stat-card__label">Last Backup</div>
            </div>
        </div>

        <!-- Settings + Manual backup -->
        <div class="main-grid">

            <!-- Auto Backup Settings -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-sliders"></i> Auto Backup Settings</h3>
                </div>
                <form id="settingsForm" novalidate>
                    <div class="toggle-row">
                        <div class="toggle-row__label">
                            <span class="status-dot <?php echo $scheduleSettings['auto_backup_enabled'] ? 'status-dot--on' : 'status-dot--off'; ?>" id="statusDot"></span>
                            Automatic Daily Backup
                        </div>
                        <label class="switch" aria-label="Toggle automatic daily backup">
                            <input type="checkbox" id="autoBackupToggle" <?php echo $scheduleSettings['auto_backup_enabled'] ? 'checked' : ''; ?>>
                            <span class="switch-track"></span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="retentionDays">
                            Retention Period
                        </label>
                        <input type="number" class="form-input" id="retentionDays"
                               value="<?php echo (int)$scheduleSettings['retention_days']; ?>"
                               min="1" max="365" required>
                        <p class="form-hint">Automatic backups older than this many days are deleted.</p>
                    </div>
                    <button type="submit" class="btn btn--primary btn--full">
                        <i class="fas fa-floppy-disk"></i> Save Settings
                    </button>
                </form>
            </div>

            <!-- Manual Backup -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-circle-plus"></i> Create Manual Backup</h3>
                </div>
                <form id="manualBackupForm" novalidate>
                    <div class="form-group">
                        <label class="form-label" for="backupDescription">Description <span style="font-weight:400;color:var(--text-3)">(optional)</span></label>
                        <input type="text" class="form-input" id="backupDescription"
                               placeholder="e.g. Before semester migration"
                               maxlength="200" autocomplete="off">
                        <p class="form-hint">Max 200 characters. Stored in the backup header.</p>
                    </div>
                    <button type="submit" class="btn btn--primary btn--full" id="manualBackupBtn">
                        <i class="fas fa-download"></i> Create Backup Now
                    </button>
                </form>
            </div>
        </div>

        <!-- Backup History -->
        <div class="card history-card">
            <div class="card-header">
                <h3><i class="fas fa-rectangle-list"></i> Backup History</h3>
                <button class="btn btn--ghost btn--sm" id="refreshBtn" onclick="refreshBackupList()">
                    <i class="fas fa-arrows-rotate"></i> Refresh
                </button>
            </div>
            <div class="backup-list" id="backupList">
                <?php if (empty($backupList)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>No backups available yet. Create your first backup above.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($backupList as $backup):
                        $isAuto   = $backup['type'] === 'Automatic';
                        $safeName = htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8');
                        $safeDate = htmlspecialchars($backup['date'], ENT_QUOTES, 'UTF-8');
                        $safeSize = htmlspecialchars($backup['size'], ENT_QUOTES, 'UTF-8');
                        $safeType = htmlspecialchars($backup['type'], ENT_QUOTES, 'UTF-8');
                        // [BUG-07] Download uses secure action endpoint, not raw path
                        $dlUrl = 'backup_restore.php?action=download_backup&file=' . rawurlencode($backup['name']) . '&csrf_token=' . urlencode($csrfToken);
                    ?>
                    <div class="backup-item" data-filename="<?php echo $safeName; ?>">
                        <div class="backup-item__icon <?php echo $isAuto ? 'backup-item__icon--auto' : ''; ?>">
                            <i class="fas <?php echo $isAuto ? 'fa-robot' : 'fa-hand-pointer'; ?>"></i>
                        </div>
                        <div class="backup-item__info">
                            <div class="backup-item__name"><?php echo $safeName; ?></div>
                            <div class="backup-item__meta">
                                <span><i class="fas fa-calendar-days"></i><?php echo $safeDate; ?></span>
                                <span><i class="fas fa-weight-scale"></i><?php echo $safeSize; ?></span>
                                <span class="backup-item__badge <?php echo $isAuto ? 'badge--auto' : 'badge--manual'; ?>"><?php echo $safeType; ?></span>
                            </div>
                        </div>
                        <div class="backup-item__actions">
                            <a href="<?php echo htmlspecialchars($dlUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn--ghost btn--sm" title="Download">
                                <i class="fas fa-download"></i>
                            </a>
                            <button class="btn btn--danger btn--sm"
                                    onclick="confirmDeleteBackup('<?php echo $safeName; ?>')"
                                    title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- #mainContent -->
</div><!-- .page-wrap -->

<!-- Loading overlay -->
<div class="loading-overlay" id="loadingOverlay" style="display:none;" role="status" aria-live="polite">
    <div class="loading-box">
        <div class="spinner"></div>
        <p id="loadingMsg">Processing…</p>
    </div>
</div>

<!-- Delete confirmation modal -->
<div class="modal-backdrop" id="deleteModal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
    <div class="modal">
        <div class="modal__icon"><i class="fas fa-triangle-exclamation"></i></div>
        <div class="modal__title" id="deleteModalTitle">Confirm Deletion</div>
        <p class="modal__msg" id="deleteModalMsg">Are you sure you want to permanently delete this backup? This action cannot be undone.</p>
        <div class="modal__actions">
            <button class="btn btn--ghost" onclick="closeDeleteModal()">Cancel</button>
            <button class="btn btn--danger" id="confirmDeleteBtn"><i class="fas fa-trash"></i> Delete</button>
        </div>
    </div>
</div>

<!-- Toast container -->
<div id="toastContainer" aria-live="assertive" aria-atomic="true"></div>

<!-- CSRF meta for JS -->
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

<script>
'use strict';

// ── CSRF token ──────────────────────────────────────────────────────────────
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

// ── Skeleton → real content transition ─────────────────────────────────────
window.addEventListener('DOMContentLoaded', () => {
    // A brief delay so the skeleton is visible (proves it works); in prod
    // you'd tie this to actual async data fetching.
    setTimeout(() => {
        document.getElementById('skeletonView').style.display  = 'none';
        document.getElementById('mainContent').style.display   = 'block';
        initListeners();
    }, 900);
});

// ── Event wiring ────────────────────────────────────────────────────────────
function initListeners() {
    document.getElementById('settingsForm').addEventListener('submit', handleSettingsSubmit);
    document.getElementById('manualBackupForm').addEventListener('submit', handleManualBackup);
    document.getElementById('autoBackupToggle').addEventListener('change', syncStatusDot);
    document.getElementById('confirmDeleteBtn').addEventListener('click', executeDelete);
    document.getElementById('deleteModal').addEventListener('click', e => {
        if (e.target === e.currentTarget) closeDeleteModal();
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeDeleteModal();
    });
    syncStatusDot();
}

// ── Core API helper ─────────────────────────────────────────────────────────
// [BUG-12] CSRF token automatically appended to every mutating request.
async function api(data) {
    data.append('csrf_token', CSRF);
    const res = await fetch('backup_restore.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        body: data
    });
    let json;
    try { json = await res.json(); } catch (_) { throw new Error('Invalid server response. Check logs.'); }
    if (!res.ok) throw new Error(json.message || `Server error ${res.status}`);
    return json;
}

// ── Settings form ───────────────────────────────────────────────────────────
async function handleSettingsSubmit(e) {
    e.preventDefault();
    const days = parseInt(document.getElementById('retentionDays').value, 10);
    if (isNaN(days) || days < 1 || days > 365) {
        toast('Retention must be between 1 and 365 days.', 'warning');
        return;
    }
    setLoading(true, 'Saving settings…');
    try {
        const fd = new FormData();
        fd.append('action', 'update_settings');
        fd.append('auto_backup_enabled', document.getElementById('autoBackupToggle').checked);
        fd.append('retention_days', days);
        const res = await api(fd);
        if (res.success) { toast('Settings saved.', 'success'); syncStatusDot(); }
        else throw new Error(res.message);
    } catch (err) {
        toast(err.message, 'error');
    } finally {
        setLoading(false);
    }
}

// ── Manual backup ────────────────────────────────────────────────────────────
async function handleManualBackup(e) {
    e.preventDefault();
    const btn  = document.getElementById('manualBackupBtn');
    btn.disabled = true;
    setLoading(true, 'Creating backup…');
    try {
        const fd = new FormData();
        fd.append('action', 'manual_backup');
        // [BUG-13] Trim input; toasts use textContent so no XSS risk there
        fd.append('description', document.getElementById('backupDescription').value.trim().substring(0, 200));
        const res = await api(fd);
        if (res.success) {
            toast(`Backup created: ${esc(res.filename)}`, 'success');
            document.getElementById('backupDescription').value = '';
            await refreshBackupList();
            await updateStats();
        } else {
            throw new Error(res.message);
        }
    } catch (err) {
        toast(err.message, 'error');
    } finally {
        setLoading(false);
        btn.disabled = false;
    }
}

// ── Delete flow ──────────────────────────────────────────────────────────────
let _pendingDelete = null;
function confirmDeleteBackup(fileName) {
    _pendingDelete = fileName;
    document.getElementById('deleteModalMsg').textContent =
        `Permanently delete "${fileName}"? This cannot be undone.`;
    document.getElementById('deleteModal').style.display = 'grid';
}
function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
    _pendingDelete = null;
}
async function executeDelete() {
    if (!_pendingDelete) return;
    const name = _pendingDelete;
    closeDeleteModal();
    setLoading(true, 'Deleting backup…');
    try {
        const fd = new FormData();
        fd.append('action', 'delete_backup');
        fd.append('fileName', name);
        const res = await api(fd);
        if (res.success) {
            toast('Backup deleted.', 'success');
            removeItemFromList(name);
            await updateStats();
        } else {
            throw new Error(res.message);
        }
    } catch (err) {
        toast(err.message, 'error');
    } finally {
        setLoading(false);
    }
}

// ── Refresh list ─────────────────────────────────────────────────────────────
async function refreshBackupList() {
    const btn = document.getElementById('refreshBtn');
    if (btn) btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('action', 'get_backup_list');
        const res = await api(fd);
        if (res.success) renderBackupList(res.backups);
        else throw new Error(res.message);
    } catch (err) {
        toast(err.message, 'error');
    } finally {
        if (btn) btn.disabled = false;
    }
}

function renderBackupList(backups) {
    const list = document.getElementById('backupList');
    list.innerHTML = '';
    if (!backups || !backups.length) {
        list.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i><p>No backups available yet.</p></div>';
        return;
    }
    for (const b of backups) {
        const isAuto  = b.type === 'Automatic';
        // [BUG-07] Download link uses secure endpoint
        const dlUrl   = `backup_restore.php?action=download_backup&file=${encodeURIComponent(b.name)}&csrf_token=${encodeURIComponent(CSRF)}`;
        const item    = document.createElement('div');
        item.className = 'backup-item';
        item.setAttribute('data-filename', esc(b.name));
        item.innerHTML = `
            <div class="backup-item__icon ${isAuto ? 'backup-item__icon--auto' : ''}">
                <i class="fas ${isAuto ? 'fa-robot' : 'fa-hand-pointer'}"></i>
            </div>
            <div class="backup-item__info">
                <div class="backup-item__name">${esc(b.name)}</div>
                <div class="backup-item__meta">
                    <span><i class="fas fa-calendar-days"></i>${esc(b.date)}</span>
                    <span><i class="fas fa-weight-scale"></i>${esc(b.size)}</span>
                    <span class="backup-item__badge ${isAuto ? 'badge--auto' : 'badge--manual'}">${esc(b.type)}</span>
                </div>
            </div>
            <div class="backup-item__actions">
                <a href="${esc(dlUrl)}" class="btn btn--ghost btn--sm" title="Download">
                    <i class="fas fa-download"></i>
                </a>
                <button class="btn btn--danger btn--sm" title="Delete"
                        onclick="confirmDeleteBackup('${esc(b.name).replace(/'/g, "\\'")}')">
                    <i class="fas fa-trash"></i>
                </button>
            </div>`;
        list.appendChild(item);
    }
}

// ── Stats update ─────────────────────────────────────────────────────────────
async function updateStats() {
    try {
        const fd = new FormData();
        fd.append('action', 'get_stats');
        const res = await api(fd);
        if (res.success) {
            const s = res.stats;
            document.getElementById('statTotal').textContent  = s.total_backups;
            document.getElementById('statAuto').textContent   = s.auto_backups;
            document.getElementById('statManual').textContent = s.manual_backups;
            document.getElementById('statSize').textContent   = s.total_size;
            document.getElementById('statLast').textContent   = s.last_backup;
        }
    } catch (_) { /* stats are non-critical */ }
}

// ── Remove item from list with animation ─────────────────────────────────────
function removeItemFromList(name) {
    const el = document.querySelector(`[data-filename="${CSS.escape(name)}"]`);
    if (!el) return;
    el.style.transition = 'opacity .25s, transform .25s';
    el.style.opacity    = '0';
    el.style.transform  = 'translateX(-16px)';
    setTimeout(() => {
        el.remove();
        const list = document.getElementById('backupList');
        if (!list.children.length) {
            list.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i><p>No backups available yet.</p></div>';
        }
    }, 280);
}

// ── Status dot sync ──────────────────────────────────────────────────────────
function syncStatusDot() {
    const on  = document.getElementById('autoBackupToggle').checked;
    const dot = document.getElementById('statusDot');
    dot.className = 'status-dot ' + (on ? 'status-dot--on' : 'status-dot--off');
}

// ── Loading overlay ──────────────────────────────────────────────────────────
function setLoading(show, msg = 'Processing…') {
    const ov  = document.getElementById('loadingOverlay');
    const txt = document.getElementById('loadingMsg');
    txt.textContent = msg;
    ov.style.display = show ? 'grid' : 'none';
}

// ── Toast ────────────────────────────────────────────────────────────────────
const TOAST_ICONS = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
function toast(msg, type = 'info') {
    const ct = document.getElementById('toastContainer');
    const el = document.createElement('div');
    el.className = `toast toast--${type}`;
    // [BUG-13] Use textContent, never innerHTML for user-derived values
    const iconEl  = document.createElement('i');
    iconEl.className = `fas ${TOAST_ICONS[type] || TOAST_ICONS.info} toast__icon`;
    const bodyEl  = document.createElement('div');
    bodyEl.className = 'toast__body';
    const msgEl   = document.createElement('div');
    msgEl.className = 'toast__msg';
    msgEl.textContent = msg;  // ← textContent, not innerHTML
    bodyEl.appendChild(msgEl);
    const closeEl = document.createElement('button');
    closeEl.className = 'toast__close';
    closeEl.innerHTML = '<i class="fas fa-xmark"></i>';
    closeEl.onclick   = () => el.remove();
    el.append(iconEl, bodyEl, closeEl);
    ct.prepend(el);
    setTimeout(() => el.remove(), 5500);
}

// ── Escape HTML (for innerHTML uses) ────────────────────────────────────────
function esc(t) {
    if (typeof t !== 'string') return '';
    return t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>
</body>
</html>
