<?php
class SessionManager
{
    private $conn;
    private $inactivity_timeout_minutes = 30;

    public function __construct($database_connection)
    {
        $this->conn = $database_connection;
    }

    /**
     * Check if user already has an active session
     */
    public function hasActiveSession($user_identifier, $user_type)
    {
        // Clean up expired sessions first
        $this->cleanupExpiredSessions();

        $sql = "SELECT session_id, ip_address, user_agent, login_time, last_activity 
                FROM active_sessions 
                WHERE user_identifier = ? AND user_type = ? AND is_active = 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ss", $user_identifier, $user_type);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $session = $result->fetch_assoc();
            $stmt->close();
            return [
                'has_session' => true,
                'session_info' => $session
            ];
        }

        $stmt->close();
        return ['has_session' => false];
    }

    /**
     * Create new active session
     */
    public function createSession($user_identifier, $user_type)
    {
        $session_id = session_id();
        $ip_address = $this->getClientIP();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $sql = "INSERT INTO active_sessions (session_id, user_identifier, user_type, ip_address, user_agent, login_time, last_activity) 
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sssss", $session_id, $user_identifier, $user_type, $ip_address, $user_agent);
        
        if ($stmt->execute()) {
            $stmt->close();
            return true;
        }

        $stmt->close();
        return false;
    }

    /**
     * Update session activity
     */
    public function updateSessionActivity($user_identifier, $user_type)
    {
        $session_id = session_id();
        
        $sql = "UPDATE active_sessions 
                SET last_activity = NOW() 
                WHERE session_id = ? AND user_identifier = ? AND user_type = ? AND is_active = 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sss", $session_id, $user_identifier, $user_type);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * End current session (logout)
     */
    public function endSession($user_identifier, $user_type, $session_id = null)
    {
        if ($session_id === null) {
            $session_id = session_id();
        }

        $sql = "UPDATE active_sessions 
                SET is_active = 0 
                WHERE session_id = ? AND user_identifier = ? AND user_type = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sss", $session_id, $user_identifier, $user_type);
        $stmt->execute();
        $stmt->close();

        return true;
    }

    /**
     * Admin function to force logout user from all sessions
     */
    public function adminForceLogout($user_identifier, $user_type, $admin_username)
    {
        $sql = "UPDATE active_sessions 
                SET is_active = 0, forced_logout_by = ?, forced_logout_at = NOW() 
                WHERE user_identifier = ? AND user_type = ? AND is_active = 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sss", $admin_username, $user_identifier, $user_type);
        $stmt->execute();
        $affected_rows = $stmt->affected_rows;
        $stmt->close();

        return $affected_rows > 0;
    }

    /**
     * Clean up expired sessions (inactive for more than specified minutes)
     */
    public function cleanupExpiredSessions()
    {
        $sql = "UPDATE active_sessions 
                SET is_active = 0 
                WHERE last_activity < DATE_SUB(NOW(), INTERVAL ? MINUTE) AND is_active = 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->inactivity_timeout_minutes);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Check if current session is valid
     */
    public function isSessionValid($user_identifier, $user_type)
    {
        $session_id = session_id();
        
        // Clean up expired sessions first
        $this->cleanupExpiredSessions();

        $sql = "SELECT session_id FROM active_sessions 
                WHERE session_id = ? AND user_identifier = ? AND user_type = ? AND is_active = 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sss", $session_id, $user_identifier, $user_type);
        $stmt->execute();
        $result = $stmt->get_result();

        $is_valid = $result->num_rows > 0;
        $stmt->close();

        if ($is_valid) {
            // Update last activity
            $this->updateSessionActivity($user_identifier, $user_type);
        }

        return $is_valid;
    }

    /**
     * Get active sessions for admin dashboard
     */
    public function getActiveSessions($user_type = null)
    {
        $this->cleanupExpiredSessions();

        if ($user_type) {
            $sql = "SELECT s.*, 
                           CASE 
                               WHEN s.user_type = 'staff' THEN u.user_name
                               WHEN s.user_type = 'student' THEN CONCAT(st.first_name, ' ', st.last_name)
                               WHEN s.user_type = 'parent' THEN p.name
                           END as display_name
                    FROM active_sessions s
                    LEFT JOIN users u ON s.user_identifier = u.user_name AND s.user_type = 'staff'
                    LEFT JOIN students st ON s.user_identifier = st.student_id AND s.user_type = 'student'
                    LEFT JOIN parents p ON s.user_identifier = p.phone AND s.user_type = 'parent'
                    WHERE s.user_type = ? AND s.is_active = 1
                    ORDER BY s.last_activity DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("s", $user_type);
        } else {
            $sql = "SELECT s.*, 
                           CASE 
                               WHEN s.user_type = 'staff' THEN u.user_name
                               WHEN s.user_type = 'student' THEN CONCAT(st.first_name, ' ', st.last_name)
                               WHEN s.user_type = 'parent' THEN p.name
                           END as display_name
                    FROM active_sessions s
                    LEFT JOIN users u ON s.user_identifier = u.user_name AND s.user_type = 'staff'
                    LEFT JOIN students st ON s.user_identifier = st.student_id AND s.user_type = 'student'
                    LEFT JOIN parents p ON s.user_identifier = p.phone AND s.user_type = 'parent'
                    WHERE s.is_active = 1
                    ORDER BY s.last_activity DESC";
            
            $stmt = $this->conn->prepare($sql);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $sessions = [];
        while ($row = $result->fetch_assoc()) {
            $sessions[] = $row;
        }

        $stmt->close();
        return $sessions;
    }

    /**
     * Get session statistics for admin dashboard
     */
    public function getSessionStats($days = 7)
    {
        $sql = "SELECT 
                    user_type,
                    DATE(login_time) as date,
                    COUNT(*) as total_sessions,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_sessions
                FROM active_sessions 
                WHERE login_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY user_type, DATE(login_time)
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
     * Generate session conflict message
     */
    public function getSessionConflictMessage($session_info)
    {
        $login_time = date('M j, Y g:i A', strtotime($session_info['login_time']));
        $last_activity = date('M j, Y g:i A', strtotime($session_info['last_activity']));
        $ip_address = $session_info['ip_address'];
        
        // Get browser info from user agent
        $browser_info = $this->getBrowserInfo($session_info['user_agent']);

        return "Your account is already logged in on another device/browser.<br><br>" .
               "<strong>Active Session Details:</strong><br>" .
               "• Login Time: {$login_time}<br>" .
               "• Last Activity: {$last_activity}<br>" .
               "• IP Address: {$ip_address}<br>" .
               "• Browser: {$browser_info}<br><br>" .
               "Please log out from the other session first, then try again.<br>" .
               "If you don't have access to the other device, please contact the administrator.";
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
     * Extract browser information from user agent
     */
    private function getBrowserInfo($user_agent)
    {
        $browsers = [
            'Chrome' => '/Chrome\/([0-9.]+)/',
            'Firefox' => '/Firefox\/([0-9.]+)/',
            'Safari' => '/Safari\/([0-9.]+)/',
            'Edge' => '/Edge\/([0-9.]+)/',
            'Opera' => '/Opera\/([0-9.]+)/',
            'Internet Explorer' => '/MSIE ([0-9.]+)/'
        ];

        foreach ($browsers as $browser => $pattern) {
            if (preg_match($pattern, $user_agent, $matches)) {
                return $browser . ' ' . $matches[1];
            }
        }

        return 'Unknown Browser';
    }

    /**
     * Set custom inactivity timeout (for testing or specific requirements)
     */
    public function setInactivityTimeout($minutes)
    {
        $this->inactivity_timeout_minutes = $minutes;
    }

    /**
     * Get current inactivity timeout
     */
    public function getInactivityTimeout()
    {
        return $this->inactivity_timeout_minutes;
    }
}
?>