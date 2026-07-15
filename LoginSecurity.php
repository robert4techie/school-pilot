<?php
class LoginSecurity
{
    private $conn;
    private $max_attempts_short = 3; // After 3 attempts, lock for 10 minutes
    private $max_attempts_long = 5;  // After 5 attempts, lock for 1 hour
    private $short_lockout_minutes = 10;
    private $long_lockout_minutes = 60;

    public function __construct($database_connection)
    {
        $this->conn = $database_connection;
    }

    /**
     * Check if account is currently locked
     */
    public function isAccountLocked($user_identifier, $user_type)
    {
        $table = $this->getUserTable($user_type);
        $identifier_field = $this->getIdentifierField($user_type);

        $sql = "SELECT failed_login_attempts, locked_until, locked_by_admin 
                FROM $table 
                WHERE $identifier_field = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $user_identifier);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            return ['locked' => false, 'reason' => ''];
        }

        $user = $result->fetch_assoc();
        $stmt->close();

        // Check if locked by admin
        if ($user['locked_by_admin'] == 1) {
            return [
                'locked' => true,
                'reason' => 'Account locked by administrator. Please contact support.',
                'locked_until' => null
            ];
        }

        // Check if locked due to failed attempts
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $locked_until = date('Y-m-d H:i:s', strtotime($user['locked_until']));
            return [
                'locked' => true,
                'reason' => "Account temporarily locked until $locked_until due to multiple failed login attempts.",
                'locked_until' => $locked_until
            ];
        }

        // If lock time has expired, reset failed attempts
        if ($user['locked_until'] && strtotime($user['locked_until']) <= time()) {
            $this->resetFailedAttempts($user_identifier, $user_type);
        }

        return ['locked' => false, 'reason' => ''];
    }

    /**
     * Record login attempt in database
     */
    public function recordLoginAttempt($user_identifier, $user_type, $success, $failure_reason = null)
    {
        $ip_address = $this->getClientIP();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $sql = "INSERT INTO login_attempts (user_identifier, user_type, ip_address, user_agent, success, failure_reason) 
                VALUES (?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ssssis", $user_identifier, $user_type, $ip_address, $user_agent, $success, $failure_reason);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Handle failed login attempt
     */
    public function handleFailedLogin($user_identifier, $user_type, $failure_reason)
    {
        // Record the failed attempt
        $this->recordLoginAttempt($user_identifier, $user_type, 0, $failure_reason);

        // Only increment failed attempts if user exists (don't lock non-existent accounts)
        if ($failure_reason !== 'invalid_username' && $failure_reason !== 'invalid_student_id' && $failure_reason !== 'invalid_phone') {
            $this->incrementFailedAttempts($user_identifier, $user_type);
        }
    }

    /**
     * Handle successful login
     */
    public function handleSuccessfulLogin($user_identifier, $user_type)
    {
        // Record successful attempt
        $this->recordLoginAttempt($user_identifier, $user_type, 1);

        // Reset failed attempts counter
        $this->resetFailedAttempts($user_identifier, $user_type);
    }

    /**
     * Increment failed login attempts and apply lockout if necessary
     */
    private function incrementFailedAttempts($user_identifier, $user_type)
    {
        $table = $this->getUserTable($user_type);
        $identifier_field = $this->getIdentifierField($user_type);

        // Get current failed attempts
        $sql = "SELECT failed_login_attempts FROM $table WHERE $identifier_field = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $user_identifier);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return;
        }

        $user = $result->fetch_assoc();
        $current_attempts = $user['failed_login_attempts'];
        $new_attempts = $current_attempts + 1;
        $stmt->close();

        // Determine lockout duration
        $locked_until = null;
        $lock_reason = null;

        if ($new_attempts >= $this->max_attempts_long) {
            // Lock for 1 hour after 5 attempts
            $locked_until = date('Y-m-d H:i:s', strtotime("+{$this->long_lockout_minutes} minutes"));
            $lock_reason = 'failed_attempts';
        } elseif ($new_attempts >= $this->max_attempts_short) {
            // Lock for 10 minutes after 3 attempts
            $locked_until = date('Y-m-d H:i:s', strtotime("+{$this->short_lockout_minutes} minutes"));
            $lock_reason = 'failed_attempts';
        }

        // Update user table
        $update_sql = "UPDATE $table SET failed_login_attempts = ?, locked_until = ? WHERE $identifier_field = ?";
        $stmt = $this->conn->prepare($update_sql);
        $stmt->bind_param("iss", $new_attempts, $locked_until, $user_identifier);
        $stmt->execute();
        $stmt->close();

        // Record lock in account_locks table if account is being locked
        if ($locked_until) {
            $this->recordAccountLock($user_identifier, $user_type, $locked_until, $lock_reason);
        }
    }

    /**
     * Reset failed login attempts
     */
    public function resetFailedAttempts($user_identifier, $user_type)
    {
        $table = $this->getUserTable($user_type);
        $identifier_field = $this->getIdentifierField($user_type);

        $sql = "UPDATE $table SET failed_login_attempts = 0, locked_until = NULL WHERE $identifier_field = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $user_identifier);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Admin function to unlock account
     */
    public function adminUnlockAccount($user_identifier, $user_type, $admin_username)
    {
        $table = $this->getUserTable($user_type);
        $identifier_field = $this->getIdentifierField($user_type);

        // Reset user account
        $sql = "UPDATE $table SET failed_login_attempts = 0, locked_until = NULL, locked_by_admin = 0 WHERE $identifier_field = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $user_identifier);
        $stmt->execute();
        $stmt->close();

        // Update account_locks table
        $update_lock_sql = "UPDATE account_locks 
                           SET unlocked_at = NOW(), unlocked_by_admin = ?, is_active = 0 
                           WHERE user_identifier = ? AND user_type = ? AND is_active = 1";
        $stmt = $this->conn->prepare($update_lock_sql);
        $stmt->bind_param("sss", $admin_username, $user_identifier, $user_type);
        $stmt->execute();
        $stmt->close();

        return true;
    }

    /**
     * Admin function to lock account
     */
    public function adminLockAccount($user_identifier, $user_type, $admin_username, $duration_hours = 24)
    {
        $table = $this->getUserTable($user_type);
        $identifier_field = $this->getIdentifierField($user_type);

        $locked_until = date('Y-m-d H:i:s', strtotime("+{$duration_hours} hours"));

        // Lock user account
        $sql = "UPDATE $table SET locked_until = ?, locked_by_admin = 1 WHERE $identifier_field = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ss", $locked_until, $user_identifier);
        $stmt->execute();
        $stmt->close();

        // Record lock in account_locks table
        $this->recordAccountLock($user_identifier, $user_type, $locked_until, 'admin_lock', $admin_username);

        return true;
    }

    /**
     * Record account lock in account_locks table
     */
    private function recordAccountLock($user_identifier, $user_type, $locked_until, $lock_reason, $admin_username = null)
    {
        $sql = "INSERT INTO account_locks (user_identifier, user_type, locked_until, lock_reason, locked_by_admin) 
                VALUES (?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sssss", $user_identifier, $user_type, $locked_until, $lock_reason, $admin_username);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get user table name based on user type
     */
    private function getUserTable($user_type)
    {
        switch ($user_type) {
            case 'staff':
                return 'users';
            case 'student':
                return 'students';
            case 'parent':
                return 'parents';
            default:
                throw new Exception("Invalid user type");
        }
    }

    /**
     * Get identifier field name based on user type
     */
    private function getIdentifierField($user_type)
    {
        switch ($user_type) {
            case 'staff':
                return 'user_name';
            case 'student':
                return 'student_id';
            case 'parent':
                return 'phone';
            default:
                throw new Exception("Invalid user type");
        }
    }

    /**
     * Get client IP address
     */
    private function getClientIP()
    {
        $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (array_map('trim', explode(',', $_SERVER[$key])) as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Get login statistics for admin dashboard
     */
    public function getLoginStats($days = 30)
    {
        $sql = "SELECT 
                    user_type,
                    success,
                    COUNT(*) as count,
                    DATE(attempt_time) as date
                FROM login_attempts 
                WHERE attempt_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY user_type, success, DATE(attempt_time)
                ORDER BY date DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $days);
        $stmt->execute();
        $result = $stmt->get_result();

        $stats = [];
        while ($row = $result->fetch_assoc()) {
            $stats[] = $row;
        }

        $stmt->close();
        return $stats;
    }

    /**
     * Get currently locked accounts
     */
    public function getLockedAccounts()
    {
        $tables = [
            'staff' => ['table' => 'users', 'field' => 'user_name'],
            'student' => ['table' => 'students', 'field' => 'student_id'],
            'parent' => ['table' => 'parents', 'field' => 'phone']
        ];

        $locked_accounts = [];

        foreach ($tables as $user_type => $config) {
            $sql = "SELECT {$config['field']} as identifier, locked_until, locked_by_admin, failed_login_attempts
                    FROM {$config['table']} 
                    WHERE (locked_until > NOW() OR locked_by_admin = 1)";

            $result = $this->conn->query($sql);
            while ($row = $result->fetch_assoc()) {
                $row['user_type'] = $user_type;
                $locked_accounts[] = $row;
            }
        }

        return $locked_accounts;
    }
}
