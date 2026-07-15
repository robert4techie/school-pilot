<?php
// File: maintenance_check.php
// Include this at the top of every public page (index.php, etc.)

require_once 'conn.php';

function isMaintenanceMode() {
    global $conn;
    
    if (!$conn) {
        return false; // If DB is down, don't show maintenance mode
    }
    
    $result = mysqli_query($conn, "SELECT is_enabled FROM feature_settings WHERE feature_key = 'maintenance_mode' LIMIT 1");
    
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return (bool)$row['is_enabled'];
    }
    
    return false;
}

function isExemptFromMaintenance() {
    // Check if user is logged in as developer or admin
    return (isset($_SESSION['user_type']) && 
            ($_SESSION['user_type'] === 'developer' || $_SESSION['user_type'] === 'admin'));
}

// Check maintenance mode for public pages
if (isMaintenanceMode() && !isExemptFromMaintenance()) {
    // Get current page to avoid redirect loops
    $currentPage = basename($_SERVER['PHP_SELF']);
    
    // Don't redirect if already on maintenance page
    if ($currentPage !== 'maintenance.php') {
        header("Location: maintenance.php");
        exit();
    }
}
?>

<?php
// File: maintenance.php
// The maintenance mode page that users see

// Start session for potential admin bypass
session_start();

// Check if maintenance mode is actually enabled
require_once 'conn.php';

function getMaintenanceSettings() {
    global $conn;
    
    $settings = [
        'enabled' => false,
        'title' => 'System Maintenance',
        'message' => 'We are currently performing scheduled maintenance. Please check back soon.',
        'estimated_time' => '',
        'contact_email' => '',
        'last_updated' => date('Y-m-d H:i:s')
    ];
    
    if ($conn) {
        // Get maintenance mode status
        $result = mysqli_query($conn, "SELECT is_enabled FROM feature_settings WHERE feature_key = 'maintenance_mode'");
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            $settings['enabled'] = (bool)$row['is_enabled'];
        }
        
        // Get maintenance settings (you might want to create a maintenance_settings table)
        // For now, we'll use default values, but you can extend this
    }
    
    return $settings;
}

$maintenanceSettings = getMaintenanceSettings();

// If maintenance mode is disabled, redirect to index
if (!$maintenanceSettings['enabled']) {
    header("Location: index.php");
    exit();
}

// Check if user is exempt (developer/admin)
if (isset($_SESSION['user_type']) && 
    ($_SESSION['user_type'] === 'developer' || $_SESSION['user_type'] === 'admin')) {
    // Show bypass option for developers/admins
    $showBypass = true;
} else {
    $showBypass = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($maintenanceSettings['title']) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        :root {
            --primary-green: #10b981;
            --primary-green-dark: #059669;
            --green-50: #ecfdf5;
            --green-100: #d1fae5;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --white: #ffffff;
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--green-50) 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .maintenance-container {
            max-width: 600px;
            width: 100%;
            text-align: center;
        }

        .maintenance-card {
            background: var(--white);
            border-radius: 1.5rem;
            padding: 3rem 2rem;
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--gray-200);
            position: relative;
            overflow: hidden;
        }

        .maintenance-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-green), var(--primary-green-dark));
        }

        .maintenance-icon {
            width: 80px;
            height: 80px;
            background: var(--green-100);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 2rem;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
                opacity: 1;
            }
            50% {
                transform: scale(1.05);
                opacity: 0.8;
            }
        }

        .maintenance-icon svg {
            width: 40px;
            height: 40px;
            color: var(--primary-green);
        }

        .maintenance-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 1rem;
            letter-spacing: -0.025em;
        }

        .maintenance-message {
            font-size: 1.125rem;
            color: var(--gray-600);
            line-height: 1.7;
            margin-bottom: 2rem;
        }

        .maintenance-details {
            background: var(--gray-50);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--gray-100);
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .detail-item:last-child {
            margin-bottom: 0;
        }

        .detail-label {
            font-weight: 600;
            color: var(--gray-700);
            font-size: 0.875rem;
        }

        .detail-value {
            color: var(--gray-600);
            font-size: 0.875rem;
            font-weight: 500;
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--green-100);
            color: var(--primary-green-dark);
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 2rem;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            background: var(--primary-green);
            border-radius: 50%;
            animation: blink 1.5s ease-in-out infinite;
        }

        @keyframes blink {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.3;
            }
        }

        .refresh-button {
            background: var(--primary-green);
            color: var(--white);
            border: none;
            padding: 0.875rem 2rem;
            border-radius: 0.75rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .refresh-button:hover {
            background: var(--primary-green-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }

        .refresh-button:active {
            transform: translateY(0);
        }

        .bypass-section {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--gray-200);
        }

        .bypass-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary-green);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 600;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            border: 1px solid var(--green-200);
            transition: all 0.2s ease-in-out;
        }

        .bypass-link:hover {
            background: var(--green-50);
            border-color: var(--primary-green);
        }

        .social-links {
            margin-top: 2rem;
            display: flex;
            justify-content: center;
            gap: 1rem;
        }

        .social-link {
            width: 40px;
            height: 40px;
            background: var(--gray-100);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-500);
            text-decoration: none;
            transition: all 0.2s ease-in-out;
        }

        .social-link:hover {
            background: var(--primary-green);
            color: var(--white);
            transform: translateY(-2px);
        }

        @media (max-width: 480px) {
            .maintenance-card {
                padding: 2rem 1.5rem;
            }

            .maintenance-title {
                font-size: 2rem;
            }

            .maintenance-message {
                font-size: 1rem;
            }

            .detail-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }
        }

        /* Auto-refresh indicator */
        .refresh-indicator {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: var(--white);
            border-radius: 50%;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-200);
        }

        .refresh-timer {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--primary-green);
        }
    </style>
</head>
<body>
    <div class="maintenance-container">
        <div class="maintenance-card">
            <div class="maintenance-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                          d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                          d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
            </div>

            <div class="status-indicator">
                <span class="status-dot"></span>
                System Under Maintenance
            </div>

            <h1 class="maintenance-title"><?= htmlspecialchars($maintenanceSettings['title']) ?></h1>
            <p class="maintenance-message"><?= htmlspecialchars($maintenanceSettings['message']) ?></p>

            <div class="maintenance-details">
                <div class="detail-item">
                    <span class="detail-label">Status</span>
                    <span class="detail-value">Maintenance Active</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Started</span>
                    <span class="detail-value"><?= date('M j, Y g:i A', strtotime($maintenanceSettings['last_updated'])) ?></span>
                </div>
                <?php if (!empty($maintenanceSettings['estimated_time'])): ?>
                <div class="detail-item">
                    <span class="detail-label">Estimated Completion</span>
                    <span class="detail-value"><?= htmlspecialchars($maintenanceSettings['estimated_time']) ?></span>
                </div>
                <?php endif; ?>
                <div class="detail-item">
                    <span class="detail-label">Last Checked</span>
                    <span class="detail-value" id="lastChecked"><?= date('g:i:s A') ?></span>
                </div>
            </div>

            <button class="refresh-button" onclick="checkMaintenanceStatus()">
                <span id="refreshText">Check Status</span>
            </button>

            <?php if ($showBypass): ?>
            <div class="bypass-section">
                <a href="index.php?bypass=1" class="bypass-link">
                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2zm3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
                    </svg>
                    Developer Access
                </a>
            </div>
            <?php endif; ?>

            <div class="social-links">
                <a href="#" class="social-link" title="Twitter">
                    <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"/>
                    </svg>
                </a>
                <a href="#" class="social-link" title="Email">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </a>
                <a href="#" class="social-link" title="Phone">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                    </svg>
                </a>
            </div>
        </div>
    </div>

    <div class="refresh-indicator" id="refreshIndicator" style="display: none;">
        <span class="refresh-timer" id="refreshTimer">30</span>
    </div>

    <script>
        let autoRefreshInterval;
        let countdownInterval;
        let refreshCounter = 30;

        function updateLastChecked() {
            const now = new Date();
            const timeString = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit', second:'2-digit'});
            document.getElementById('lastChecked').textContent = timeString;
        }

        function checkMaintenanceStatus() {
            const refreshText = document.getElementById('refreshText');
            const refreshButton = document.querySelector('.refresh-button');
            
            refreshText.textContent = 'Checking...';
            refreshButton.disabled = true;
            
            // Simple check by trying to load a status endpoint or just refresh
            fetch(window.location.href + '?check=1', {
                method: 'GET',
                cache: 'no-cache'
            })
            .then(response => {
                if (response.redirected && !response.url.includes('maintenance.php')) {
                    // Maintenance mode is off, redirect to main site
                    window.location.href = 'index.php';
                } else {
                    updateLastChecked();
                    refreshText.textContent = 'Check Status';
                    refreshButton.disabled = false;
                }
            })
            .catch(() => {
                updateLastChecked();
                refreshText.textContent = 'Check Status';
                refreshButton.disabled = false;
            });
        }

        function startAutoRefresh() {
            const indicator = document.getElementById('refreshIndicator');
            const timerElement = document.getElementById('refreshTimer');
            
            indicator.style.display = 'flex';
            refreshCounter = 30;
            timerElement.textContent = refreshCounter;

            countdownInterval = setInterval(() => {
                refreshCounter--;
                timerElement.textContent = refreshCounter;
                
                if (refreshCounter <= 0) {
                    checkMaintenanceStatus();
                    refreshCounter = 30;
                }
            }, 1000);

            autoRefreshInterval = setInterval(() => {
                checkMaintenanceStatus();
            }, 30000); // Check every 30 seconds
        }

        function stopAutoRefresh() {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
            }
            if (countdownInterval) {
                clearInterval(countdownInterval);
            }
            document.getElementById('refreshIndicator').style.display = 'none';
        }

        // Handle visibility change to pause/resume auto-refresh
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopAutoRefresh();
            } else {
                startAutoRefresh();
                checkMaintenanceStatus();
            }
        });

        // Start auto-refresh when page loads
        startAutoRefresh();

        // Update last checked time immediately
        updateLastChecked();
    </script>
</body>
</html>