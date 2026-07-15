<?php
require_once 'conn.php';

// PHPMailer Setup
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

class UserTracker
{
    private $conn;
    private $session_id;
    private $tracking_id;

    public function __construct($conn)
    {
        $this->conn = $conn;
        $this->session_id = session_id();
    }

    /**
     * Get location from IP address using ip-api.com (free, no key required)
     */
    private function getLocationFromIP($ip_address)
    {
        try {
            // Check for local/private IPs (including IPv6 localhost)
            if ($this->isPrivateIP($ip_address)) {
                error_log("Private/Local IP detected: " . $ip_address);
                return [
                    'location' => "Local Network (Development)",
                    'latitude' => null,
                    'longitude' => null,
                    'isp' => 'Local Network'  // Add ISP for local
                ];
            }

            // Try to get location from IP
            $url = "http://ip-api.com/json/{$ip_address}?fields=status,message,country,regionName,city,lat,lon,isp";

            error_log("Fetching location from: " . $url);

            $options = [
                'http' => [
                    'method' => 'GET',
                    'timeout' => 5,
                    'header' => 'User-Agent: SchoolPilot/1.0'
                ]
            ];

            $context = stream_context_create($options);
            $response = @file_get_contents($url, false, $context);

            if ($response === false) {
                error_log("Failed to fetch location data for IP: " . $ip_address);
                return [
                    'location' => "Location unavailable",
                    'latitude' => null,
                    'longitude' => null,
                    'isp' => 'Unknown ISP'  // Add default ISP
                ];
            }

            $data = json_decode($response, true);
            error_log("IP API Response: " . print_r($data, true));

            if (isset($data['status']) && $data['status'] === 'success') {
                $location_parts = [];

                if (!empty($data['city'])) {
                    $location_parts[] = $data['city'];
                }

                if (!empty($data['regionName'])) {
                    $location_parts[] = $data['regionName'];
                }

                if (!empty($data['country'])) {
                    $location_parts[] = $data['country'];
                }

                $location = !empty($location_parts) ? implode(', ', $location_parts) : "Location unavailable";

                error_log("Location found: " . $location);

                // Return location with coordinates AND ISP
                return [
                    'location' => $location,
                    'latitude' => $data['lat'] ?? null,
                    'longitude' => $data['lon'] ?? null,
                    'isp' => $data['isp'] ?? 'Unknown ISP'  // Add ISP data
                ];
            } else {
                $error_msg = $data['message'] ?? 'Unknown error';
                error_log("IP API Error: " . $error_msg);
                return [
                    'location' => "Location unavailable",
                    'latitude' => null,
                    'longitude' => null,
                    'isp' => 'Unknown ISP'  // Add default ISP
                ];
            }
        } catch (Exception $e) {
            error_log("IP Geolocation Exception: " . $e->getMessage());
            return [
                'location' => "Location unavailable",
                'latitude' => null,
                'longitude' => null,
                'isp' => 'Unknown ISP'  // Add default ISP
            ];
        }
    }

    /**
     * Check if IP is private/local
     */
    private function isPrivateIP($ip)
    {
        // Check for IPv6 localhost
        if ($ip === '::1' || $ip === '::ffff:127.0.0.1') {
            return true;
        }

        // Check for IPv4 localhost
        if ($ip === '127.0.0.1' || strpos($ip, '127.') === 0) {
            return true;
        }

        // Convert IPv6 to IPv4 if needed
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // If it's IPv6, check if it's a private range
            if (strpos($ip, 'fe80:') === 0 || strpos($ip, 'fc00:') === 0 || strpos($ip, 'fd00:') === 0) {
                return true;
            }
            return false;
        }

        // Check IPv4 private ranges
        $private_ranges = [
            ['10.0.0.0', '10.255.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['127.0.0.0', '127.255.255.255']
        ];

        $ip_long = ip2long($ip);

        if ($ip_long === false) {
            return false;
        }

        foreach ($private_ranges as $range) {
            if ($ip_long >= ip2long($range[0]) && $ip_long <= ip2long($range[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Track login and check if device is new
     */
    public function trackLogin($username, $user_type = 'staff', $email = null)
    {
        try {
            // Sanitize and validate input
            $username = $this->sanitizeInput($username);
            if (empty($username)) {
                throw new Exception("Username cannot be empty");
            }

            // Get user details
            $ip_address = $this->getClientIP();
            $browser = $this->sanitizeInput($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
            $device_type = $this->getDeviceType();
            $device_fingerprint = $this->generateDeviceFingerprint($ip_address, $browser);

            // Get location from IP address
            $location_data = $this->getLocationFromIP($ip_address);
            $location = $location_data['location'];
            $latitude = $location_data['latitude'];
            $longitude = $location_data['longitude'];
            $isp = $location_data['isp'];  // Extract ISP

            // Check if this is a new device
            $is_new_device = $this->isNewDevice($username, $device_fingerprint);

            // Insert tracking record with location
            $stmt = $this->conn->prepare("INSERT INTO user_tracking 
            (username, login_time, ip_address, browser, device_type, session_id, device_fingerprint, location, latitude, longitude) 
            VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)");

            if (!$stmt) {
                throw new Exception("Database prepare failed: " . $this->conn->error);
            }

            $stmt->bind_param("sssssssdd", $username, $ip_address, $browser, $device_type, $this->session_id, $device_fingerprint, $location, $latitude, $longitude);

            if (!$stmt->execute()) {
                throw new Exception("Database execute failed: " . $stmt->error);
            }

            $this->tracking_id = $stmt->insert_id;
            $stmt->close();

            // Store tracking ID in session for logout tracking
            $_SESSION['tracking_id'] = $this->tracking_id;

            // Send email notification with actual location AND ISP
            if ($email) {
                $this->sendLoginNotification($username, $email, $ip_address, $device_type, $browser, $location, $is_new_device, $isp);  // Pass ISP
            }

            return $this->tracking_id;
        } catch (Exception $e) {
            error_log("Tracking Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate device fingerprint for tracking
     */
    private function generateDeviceFingerprint($ip, $user_agent)
    {
        $components = [
            $ip,
            $user_agent,
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
        ];
        return hash('sha256', implode('|', $components));
    }

    /**
     * Check if this device has been used before
     */
    private function isNewDevice($username, $device_fingerprint)
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM user_tracking 
                                      WHERE username = ? AND device_fingerprint = ? 
                                      AND login_time > DATE_SUB(NOW(), INTERVAL 90 DAY)");
        $stmt->bind_param("ss", $username, $device_fingerprint);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row['count'] == 0;
    }

    /**
     * Send login notification email
     */
    private function sendLoginNotification($username, $email, $ip_address, $device_type, $browser, $location, $is_new_device, $isp = 'Unknown ISP')  // Add ISP parameter
    {
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'robertsontumwesige1@gmail.com';
            $mail->Password   = 'oenz unar mzyr uozw';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->SMTPDebug  = 0;

            // Recipients
            $mail->setFrom('security@schoolpilot.com', 'School Pilot Security');
            $mail->addAddress($email, $username);

            // Get detailed browser and OS info
            $browser_details = $this->getBrowserDetails($browser);
            $login_time = date('l, F j, Y \a\t g:i A');

            // Format location display with better handling
            if ($location === "Location unavailable") {
                $location_display = "<em style='color: #999;'>Location unavailable</em>";
            } elseif (strpos($location, "Local Network") !== false) {
                $location_display = "<em style='color: #ff9800;'>📍 " . htmlspecialchars($location) . "</em>
                <div class='location-note' style='color: #ff9800;'>⚠️ This login was from localhost/development environment</div>";
            } else {
                $location_display = "<strong> " . htmlspecialchars($location) . "</strong>";
            }

            // Format ISP display
            $isp_display = htmlspecialchars($isp);
            if ($isp === 'Unknown ISP' || $isp === 'Local Network') {
                $isp_display = "<em style='color: #999;'>" . htmlspecialchars($isp) . "</em>";
            }

            // Security alert badge for new devices
            $alert_badge = $is_new_device ?
                '<div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 20px 0; border-radius: 5px;">
                <strong style="color: #856404;">⚠️ New Device Alert</strong>
                <p style="margin: 5px 0 0 0; color: #856404; font-size: 14px;">This is the first time we\'ve seen a login from this device. If this wasn\'t you, please secure your account immediately.</p>
            </div>' : '';

            // Email Body
            $mail->isHTML(true);
            $mail->Subject = $is_new_device ? '⚠️ New Device Login Alert - School Pilot' : ' Login Notification - School Pilot';
            $mail->Body    = "
            <!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <style>
                    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 0; background-color: #f4f4f4; }
                    .container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
                    .header { background: linear-gradient(135deg, #1e8449 0%, #27ae60 100%); color: #ffffff; padding: 30px 20px; text-align: center; }
                    .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
                    .header p { margin: 10px 0 0 0; opacity: 0.9; font-size: 14px; }
                    .content { padding: 30px 25px; line-height: 1.6; color: #333333; }
                    .greeting { font-size: 18px; font-weight: 600; color: #1e8449; margin-bottom: 15px; }
                    .info-section { background-color: #f8f9fa; border-radius: 8px; padding: 20px; margin: 20px 0; }
                    .info-section h3 { color: #1e8449; margin: 0 0 15px 0; font-size: 16px; border-bottom: 2px solid #e0e0e0; padding-bottom: 8px; }
                    .info-row { display: flex; padding: 10px 0; border-bottom: 1px solid #e9ecef; }
                    .info-row:last-child { border-bottom: none; }
                    .info-label { font-weight: 600; color: #555; min-width: 140px; }
                    .info-value { color: #333; flex: 1; }
                    .security-tip { background-color: #e8f5e8; border-left: 4px solid #27ae60; padding: 15px; margin: 20px 0; border-radius: 5px; }
                    .security-tip strong { color: #145a32; display: block; margin-bottom: 8px; }
                    .security-tip p { margin: 0; color: #145a32; font-size: 14px; line-height: 1.5; }
                    .footer { background-color: #f4f4f4; color: #777777; padding: 20px; text-align: center; font-size: 12px; border-top: 1px solid #e0e0e0; }
                    .footer p { margin: 5px 0; }
                    .divider { height: 1px; background-color: #e0e0e0; margin: 25px 0; }
                    .location-note { font-size: 12px; color: #999; font-style: italic; margin-top: 5px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1> Login Activity Detected</h1>
                        <p>School Pilot Security Notification</p>
                    </div>
                    <div class='content'>
                        <div class='greeting'>Hello, " . htmlspecialchars($username) . " 👋</div>
                        <p>We detected a new login to your School Pilot account. Here are the details:</p>
                        
                        " . $alert_badge . "
                        
                        <div class='info-section'>
                            <h3>Login Details</h3>
                            <div class='info-row'>
                                <div class='info-label'>Date & Time:</div>
                                <div class='info-value'>" . $login_time . "</div>
                            </div>
                            <div class='info-row'>
                                <div class='info-label'>IP Address:</div>
                                <div class='info-value'>" . htmlspecialchars($ip_address) . "</div>
                            </div>
                            <div class='info-row'>
                                <div class='info-label'>Location:</div>
                                <div class='info-value'>
                                    " . $location_display . "
                                    <div class='location-note'>Approximate location based on IP address</div>
                                </div>
                            </div>
                            <div class='info-row'>
                                <div class='info-label'>Internet Provider:</div>
                                <div class='info-value'> " . $isp_display . "</div>
                            </div>
                        </div>

                        <div class='info-section'>
                            <h3> Device Information</h3>
                            <div class='info-row'>
                                <div class='info-label'>Device Type:</div>
                                <div class='info-value'>" . htmlspecialchars($device_type) . "</div>
                            </div>
                            <div class='info-row'>
                                <div class='info-label'>Browser:</div>
                                <div class='info-value'>" . htmlspecialchars($browser_details['name']) . " " . htmlspecialchars($browser_details['version']) . "</div>
                            </div>
                            <div class='info-row'>
                                <div class='info-label'>Operating System:</div>
                                <div class='info-value'>" . htmlspecialchars($browser_details['os']) . "</div>
                            </div>
                        </div>

                        <div class='divider'></div>

                        <div class='security-tip'>
                            <strong> Security Reminder</strong>
                            <p>If this login wasn't you, please take immediate action:</p>
                            <ul style='margin: 10px 0; padding-left: 20px;'>
                                <li>Change your password immediately</li>
                                <li>Review your recent account activity</li>
                                <li>Contact School Pilot support at +256 747 170 325</li>
                            </ul>
                        </div>

                        <p style='margin-top: 25px; color: #666; font-size: 14px;'>
                            <strong>Was this you?</strong> If you recognize this activity, you can safely ignore this email. 
                            We send these notifications to help protect your account. If the above sign-in attempt wasn't you, 
                            please reset your password and enable 2-factor authentication (2FA) as soon as possible to safeguard 
                            your account.
                        </p>
                        
                    </div>
                    <div class='footer'>
                        <p><strong>School Pilot Security Team</strong></p>
                        <p>&copy; " . date('Y') . " School Pilot. All rights reserved.</p>
                        <p style='margin-top: 10px;'>This is an automated security notification. Please do not reply to this email.</p>
                        <p style='margin-top: 5px;'>For support, call: <strong>+256 747 170 325</strong></p>
                    </div>
                </div>
            </body>
            </html>
        ";

            $mail->send();
            error_log("Login notification sent successfully to: " . $email);
            return true;
        } catch (Exception $e) {
            error_log("Login notification email failed: " . $mail->ErrorInfo);
            return false;
        }
    }
    /**
     * Get detailed browser information
     */
    private function getBrowserDetails($user_agent)
    {
        $browser_name = 'Unknown Browser';
        $browser_version = '';
        $os = 'Unknown OS';

        // Detect Browser
        if (preg_match('/MSIE/i', $user_agent) || preg_match('/Trident/i', $user_agent)) {
            $browser_name = 'Internet Explorer';
        } elseif (preg_match('/Edge/i', $user_agent)) {
            $browser_name = 'Microsoft Edge';
            preg_match('/Edge\/([0-9.]+)/', $user_agent, $matches);
            $browser_version = $matches[1] ?? '';
        } elseif (preg_match('/Edg/i', $user_agent)) {
            $browser_name = 'Microsoft Edge (Chromium)';
            preg_match('/Edg\/([0-9.]+)/', $user_agent, $matches);
            $browser_version = $matches[1] ?? '';
        } elseif (preg_match('/Firefox/i', $user_agent)) {
            $browser_name = 'Mozilla Firefox';
            preg_match('/Firefox\/([0-9.]+)/', $user_agent, $matches);
            $browser_version = $matches[1] ?? '';
        } elseif (preg_match('/Chrome/i', $user_agent)) {
            $browser_name = 'Google Chrome';
            preg_match('/Chrome\/([0-9.]+)/', $user_agent, $matches);
            $browser_version = $matches[1] ?? '';
        } elseif (preg_match('/Safari/i', $user_agent)) {
            $browser_name = 'Safari';
            preg_match('/Version\/([0-9.]+)/', $user_agent, $matches);
            $browser_version = $matches[1] ?? '';
        } elseif (preg_match('/Opera|OPR/i', $user_agent)) {
            $browser_name = 'Opera';
            preg_match('/OPR\/([0-9.]+)/', $user_agent, $matches);
            $browser_version = $matches[1] ?? '';
        }

        // Detect OS
        if (preg_match('/Windows NT 10.0/i', $user_agent)) {
            $os = 'Windows 10/11';
        } elseif (preg_match('/Windows NT 6.3/i', $user_agent)) {
            $os = 'Windows 8.1';
        } elseif (preg_match('/Windows NT 6.2/i', $user_agent)) {
            $os = 'Windows 8';
        } elseif (preg_match('/Windows NT 6.1/i', $user_agent)) {
            $os = 'Windows 7';
        } elseif (preg_match('/Mac OS X/i', $user_agent)) {
            $os = 'macOS';
            preg_match('/Mac OS X ([0-9_]+)/', $user_agent, $matches);
            if (isset($matches[1])) {
                $os .= ' ' . str_replace('_', '.', $matches[1]);
            }
        } elseif (preg_match('/Android/i', $user_agent)) {
            $os = 'Android';
            preg_match('/Android ([0-9.]+)/', $user_agent, $matches);
            if (isset($matches[1])) {
                $os .= ' ' . $matches[1];
            }
        } elseif (preg_match('/iPhone|iPad|iPod/i', $user_agent)) {
            $os = 'iOS';
            preg_match('/OS ([0-9_]+)/', $user_agent, $matches);
            if (isset($matches[1])) {
                $os .= ' ' . str_replace('_', '.', $matches[1]);
            }
        } elseif (preg_match('/Linux/i', $user_agent)) {
            $os = 'Linux';
        }

        return [
            'name' => $browser_name,
            'version' => $browser_version,
            'os' => $os
        ];
    }

    public function trackLogout()
    {
        try {
            if (isset($_SESSION['tracking_id'])) {
                $tracking_id = (int)$_SESSION['tracking_id'];

                $stmt = $this->conn->prepare("UPDATE user_tracking SET logout_time = NOW() WHERE id = ?");

                if (!$stmt) {
                    throw new Exception("Database prepare failed: " . $this->conn->error);
                }

                $stmt->bind_param("i", $tracking_id);

                if (!$stmt->execute()) {
                    throw new Exception("Database execute failed: " . $stmt->error);
                }

                $stmt->close();
                unset($_SESSION['tracking_id']);

                return true;
            }
            return false;
        } catch (Exception $e) {
            error_log("Logout Tracking Error: " . $e->getMessage());
            return false;
        }
    }

    public function trackAction($action)
    {
        try {
            if (isset($_SESSION['tracking_id'])) {
                $tracking_id = (int)$_SESSION['tracking_id'];
                $action = $this->sanitizeInput($action);

                $formatted_action = "[" . date('Y-m-d H:i:s') . "] " . $action . "\n";

                $stmt = $this->conn->prepare("UPDATE user_tracking SET actions = CONCAT(IFNULL(actions,''), ?) WHERE id = ?");

                if (!$stmt) {
                    throw new Exception("Database prepare failed: " . $this->conn->error);
                }

                $stmt->bind_param("si", $formatted_action, $tracking_id);

                if (!$stmt->execute()) {
                    throw new Exception("Database execute failed: " . $stmt->error);
                }

                $stmt->close();
                return true;
            }
            return false;
        } catch (Exception $e) {
            error_log("Action Tracking Error: " . $e->getMessage());
            return false;
        }
    }

    public function getLocationJS()
    {
        // Keep browser geolocation as backup/additional data
        return "<script>
            if (navigator.geolocation) {
                const geolocationOptions = {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                };
                
                const successCallback = (position) => {
                    const formData = new FormData();
                    formData.append('latitude', position.coords.latitude);
                    formData.append('longitude', position.coords.longitude);
                    formData.append('tracking_id', '" . $this->tracking_id . "');
                    
                    fetch('update_location.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            console.error('Location save failed:', data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Location save error:', error);
                    });
                };
                
                const errorCallback = (error) => {
                    console.warn('Geolocation error (' + error.code + '): ' + error.message);
                };
                
                navigator.geolocation.getCurrentPosition(
                    successCallback,
                    errorCallback,
                    geolocationOptions
                );
            }
        </script>";
    }

    private function getDeviceType()
    {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $device_types = [
            'iPhone' => [
                'iPhone15,3' => 'iPhone 14 Pro Max',
                'iPhone15,2' => 'iPhone 14 Pro',
                'iPhone14,7' => 'iPhone 14',
                'iPhone14,2' => 'iPhone 13 Pro',
                'iPhone14,3' => 'iPhone 13 Pro Max',
                'iPhone13,2' => 'iPhone 12',
                'iPhone13,3' => 'iPhone 12 Pro',
                'iPhone13,4' => 'iPhone 12 Pro Max'
            ],
            'Android' => 'Android Device',
            'iPad' => 'iPad',
            'Windows' => 'Windows PC',
            'Macintosh' => 'Mac',
            'Linux' => 'Linux PC'
        ];

        foreach ($device_types as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $code => $model) {
                    if (strpos($user_agent, $code) !== false) {
                        return $model;
                    }
                }
                if (strpos($user_agent, $key) !== false) {
                    return $key;
                }
            } elseif (strpos($user_agent, $key) !== false) {
                return $value;
            }
        }

        return (strpos($user_agent, 'Mobile') !== false) ? 'Mobile Device' : 'Desktop';
    }

    private function getClientIP()
    {
        $ip_keys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ip_keys as $key) {
            if (isset($_SERVER[$key])) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function sanitizeInput($input)
    {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
}

// Initialize tracker
$tracker = new UserTracker($conn);
