S<?php
require_once 'auth.php';
require_once 'conn.php';

// Create gate_passes table if it doesn't exist
$create_gate_passes_table = "CREATE TABLE IF NOT EXISTS gate_passes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_number VARCHAR(50) UNIQUE NOT NULL,
    student_id VARCHAR(20) NOT NULL,
    student_name VARCHAR(100) NOT NULL,
    class VARCHAR(50) NOT NULL,
    stream VARCHAR(50) NOT NULL,
    departure_time DATETIME NOT NULL,
    expected_return DATETIME NOT NULL,
    actual_return DATETIME NULL,
    destination VARCHAR(255) NOT NULL,
    reason TEXT NOT NULL,
    priority ENUM('normal', 'urgent', 'emergency') DEFAULT 'normal',
    parent_contact VARCHAR(20) NULL,
    student_contact VARCHAR(20) NULL,
    accompanying_person VARCHAR(100) NULL,
    status ENUM('issued', 'returned', 'overdue', 'cancelled') DEFAULT 'issued',
    issued_by VARCHAR(50) NOT NULL,
    issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    returned_at TIMESTAMP NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign key constraint
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE ON UPDATE CASCADE,
    
    -- Indexes for better performance
    INDEX idx_student_id (student_id),
    INDEX idx_reference (reference_number),
    INDEX idx_status (status),
    INDEX idx_issued_at (issued_at),
    INDEX idx_departure_time (departure_time),
    INDEX idx_expected_return (expected_return),
    INDEX idx_priority (priority)
)";

try {
    if (mysqli_query($conn, $create_gate_passes_table)) {
        echo "Gate passes table created successfully or already exists.\n";
        
        // Create a log table for gate pass activities
        $create_log_table = "CREATE TABLE IF NOT EXISTS gate_pass_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gate_pass_id INT NOT NULL,
            action ENUM('issued', 'returned', 'overdue_marked', 'cancelled', 'modified') NOT NULL,
            performed_by VARCHAR(50) NOT NULL,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            notes TEXT NULL,
            
            FOREIGN KEY (gate_pass_id) REFERENCES gate_passes(id) ON DELETE CASCADE,
            INDEX idx_gate_pass_id (gate_pass_id),
            INDEX idx_action (action),
            INDEX idx_timestamp (timestamp)
        )";
        
        if (mysqli_query($conn, $create_log_table)) {
            echo "Gate pass logs table created successfully or already exists.\n";
        } else {
            echo "Error creating gate pass logs table: " . mysqli_error($conn) . "\n";
        }
        
        // Create a view for easy querying
        $create_view = "CREATE OR REPLACE VIEW gate_passes_view AS
            SELECT 
                gp.*,
                s.first_name,
                s.last_name,
                s.current_class as student_current_class,
                s.stream as student_stream,
                s.section,
                s.profile_photo,
                CASE 
                    WHEN gp.actual_return IS NOT NULL THEN 'returned'
                    WHEN NOW() > gp.expected_return AND gp.actual_return IS NULL THEN 'overdue'
                    ELSE gp.status
                END as current_status,
                TIMESTAMPDIFF(HOUR, gp.departure_time, COALESCE(gp.actual_return, NOW())) as duration_hours
            FROM gate_passes gp
            LEFT JOIN students s ON gp.student_id = s.student_id";
        
        if (mysqli_query($conn, $create_view)) {
            echo "Gate passes view created successfully.\n";
        } else {
            echo "Error creating gate passes view: " . mysqli_error($conn) . "\n";
        }
        
    } else {
        echo "Error creating gate passes table: " . mysqli_error($conn) . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

mysqli_close($conn);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Database Setup Complete</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #c3e6cb;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #bee5eb;
            margin-top: 20px;
        }
        .btn {
            display: inline-block;
            background: #007bff;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="success">
        <h2>✅ Database Setup Complete!</h2>
        <p>The gate passes system has been successfully set up with the following tables:</p>
        <ul>
            <li><strong>gate_passes</strong> - Main table for storing gate pass records</li>
            <li><strong>gate_pass_logs</strong> - Activity log table for tracking changes</li>
            <li><strong>gate_passes_view</strong> - View for easy data retrieval with student information</li>
        </ul>
    </div>
    
    <div class="info">
        <h3>📋 Next Steps:</h3>
        <ol>
            <li>Make sure your <strong>students</strong> table has the required structure</li>
            <li>Ensure proper user authentication is in place</li>
            <li>Test the gate pass issuance system</li>
            <li>Set up proper backup procedures for the database</li>
        </ol>
        
        <h3>🔧 System Features:</h3>
        <ul>
            <li>Automatic student data fetching</li>
            <li>Real-time form validation</li>
            <li>AJAX form submission with notifications</li>
            <li>Comprehensive logging system</li>
            <li>Mobile-responsive design</li>
        </ul>
    </div>
    
    <a href="issue_student_pass.php" class="btn">Go to Gate Pass System</a>
</body>
</html>