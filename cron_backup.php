<?php
// cron_backup.php

// Set the current directory to the script's location
// This ensures that relative paths for 'backups/' and 'logs/' work correctly.
chdir(dirname(__FILE__));

require_once 'conn.php';
require_once 'backup_restore.php'; // We need the AutoBackupScheduler class definition

try {
    // Check if the database connection is valid
    if ($conn && $conn->ping()) {
        $backupScheduler = new AutoBackupScheduler($conn);
        $backupScheduler->checkAndRunAutoBackup();
        echo "Backup check completed successfully at " . date('Y-m-d H:i:s');
    } else {
        // Log an error if the connection fails
        error_log("Cron Backup: Database connection failed.");
    }
} catch (Exception $e) {
    // Log any exceptions that occur during the process
    error_log("Cron Backup Error: " . $e->getMessage());
}
?>