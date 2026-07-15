<?php
// This file is designed to be run as a cron job to create automated backups

// Include database connection
require_once('conn.php');

// Set script execution time limit to allow for larger databases
set_time_limit(300); // 5 minutes

// Function to log backup events
function logBackupEvent($message, $isError = false) {
    $logDir = 'logs/';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    $logFile = $logDir . 'backup_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $logType = $isError ? 'ERROR' : 'INFO';
    $logEntry = "[$timestamp] [$logType] $message\n";
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

try {
    // Create backup directory if not exists
    $backupDir = 'backups/';
    if (!file_exists($backupDir)) {
        mkdir($backupDir, 0777, true);
    }
    
    // Set retention policy - keep backups for 30 days
    $retentionDays = 30;
    $currentTime = time();
    
    // Clean up old backups
    $oldBackups = glob($backupDir . '*.zip');
    foreach ($oldBackups as $backup) {
        if (is_file($backup)) {
            $fileAge = $currentTime - filemtime($backup);
            if ($fileAge > ($retentionDays * 86400)) { // 86400 seconds = 1 day
                unlink($backup);
                logBackupEvent("Deleted old backup: " . basename($backup));
            }
        }
    }
    
    // Generate backup file name with date and time
    $backupFileName = 'database_backup_scheduled_' . date('Y-m-d_H-i-s') . '.sql';
    $backupFile = $backupDir . $backupFileName;
    
    // Open the backup file for writing
    $handle = fopen($backupFile, 'w');
    
    // Add header information
    $header = "-- School Management System Database Backup (Scheduled)\n";
    $header .= "-- Generation Time: " . date('F d, Y \a\t H:i:s') . "\n";
    $header .= "-- Server version: " . mysqli_get_server_info($conn) . "\n\n";
    fwrite($handle, $header);
    
    // Get all tables in the database
    $tables = array();
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_array()) {
        $tables[] = $row[0];
    }
    
    // Process each table
    foreach ($tables as $table) {
        // Get table creation syntax
        $tableCreate = $conn->query("SHOW CREATE TABLE $table");
        $createRow = $tableCreate->fetch_array();
        
        fwrite($handle, "\n\n-- Structure for table `$table`\n\n");
        fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n");
        fwrite($handle, $createRow[1] . ";\n\n");
        
        // Get table data
        $result = $conn->query("SELECT * FROM $table");
        $numRows = $result->num_rows;
        
        if ($numRows > 0) {
            fwrite($handle, "-- Dumping data for table `$table`\n");
            
            $columnCount = $result->field_count;
            
            // Start the INSERT statement
            while ($row = $result->fetch_array(MYSQLI_NUM)) {
                $insertQuery = "INSERT INTO `$table` VALUES (";
                
                // Add each column value to the INSERT statement
                for ($i = 0; $i < $columnCount; $i++) {
                    if ($i > 0) {
                        $insertQuery .= ", ";
                    }
                    
                    if ($row[$i] === null) {
                        $insertQuery .= "NULL";
                    } else {
                        $insertQuery .= "'" . $conn->real_escape_string($row[$i]) . "'";
                    }
                }
                
                $insertQuery .= ");\n";
                fwrite($handle, $insertQuery);
            }
        }
    }
    
    fclose($handle);
    
    // Create a zip file of the backup
    $zipFile = $backupDir . 'database_backup_scheduled_' . date('Y-m-d_H-i-s') . '.zip';
    $zip = new ZipArchive();
    
    if ($zip->open($zipFile, ZipArchive::CREATE) === TRUE) {
        $zip->addFile($backupFile, $backupFileName);
        $zip->close();
        
        // Delete the .sql file, leaving only the zip
        unlink($backupFile);
        
        logBackupEvent("Scheduled backup created successfully: " . basename($zipFile));
        
        // Send email notification if desired (uncomment and configure as needed)
        /*
        $to = "admin@school.com";
        $subject = "Database Backup Notification";
        $message = "A scheduled database backup was created successfully on " . date('F d, Y \a\t H:i:s');
        $headers = "From: system@school.com";
        mail($to, $subject, $message, $headers);
        */
        
        echo "Backup completed successfully. Backup file: " . basename($zipFile);
    } else {
        throw new Exception("Failed to create zip file.");
    }
} catch (Exception $e) {
    logBackupEvent("Backup failed: " . $e->getMessage(), true);
    echo "Backup failed: " . $e->getMessage();
}
?>