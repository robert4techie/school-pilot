<?php
// File: maintenance.php
// The maintenance mode page that users see

// Start session for potential admin bypass
session_start();

// Check if maintenance mode is actually enabled
require_once 'conn.php';

function getMaintenanceSettings()
{
    global $conn;

    $settings = [
        'enabled' => false,
        'title' => 'System Maintenance',
        'message' => 'We are currently performing scheduled maintenance. Please check back soon.',
        'estimated_completion' => '',
        'contact_email' => '',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    if ($conn) {
        // Get maintenance mode status
        $result = mysqli_query($conn, "SELECT is_enabled FROM feature_settings WHERE feature_key = 'maintenance_mode'");
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            $settings['enabled'] = (bool)$row['is_enabled'];
        }

        // Get maintenance settings from maintenance_settings table
        $maintenanceResult = mysqli_query($conn, "SELECT * FROM maintenance_settings ORDER BY id DESC LIMIT 1");
        if ($maintenanceResult && mysqli_num_rows($maintenanceResult) > 0) {
            $maintenanceData = mysqli_fetch_assoc($maintenanceResult);
            $settings = array_merge($settings, $maintenanceData);
        }
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
if (
    isset($_SESSION['user_type']) &&
    ($_SESSION['user_type'] === 'developer' || $_SESSION['user_type'] === 'admin')
) {
    // Show bypass option for developers/admins
    $showBypass = true;
} else {
    $showBypass = false;
}

// Calculate countdown data
$estimatedCompletion = !empty($maintenanceSettings['estimated_completion']) ?
    strtotime($maintenanceSettings['estimated_completion']) :
    strtotime('+4 hours'); // Default to 4 hours from now if no estimate

$createdAt = strtotime($maintenanceSettings['created_at']);
$currentTime = time();
$totalDuration = $estimatedCompletion - $createdAt;
$remainingTime = $estimatedCompletion - $currentTime;
$progressPercentage = $totalDuration > 0 ? max(0, min(100, (($totalDuration - $remainingTime) / $totalDuration) * 100)) : 0;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400..900&family=Quicksand:wght@300..700&display=swap" rel="stylesheet">
    <title><?= htmlspecialchars($maintenanceSettings['title']) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        :root {
            --primary-color: #1e8449;
            --primary-light: #27ae60;
            --primary-dark: #145a32;
            --accent-color: #2ecc71;
            --gray-50: #f8f9fa;
            --gray-100: #f1f3f4;
            --gray-200: #e8eaed;
            --gray-300: #dadce0;
            --gray-400: #9aa0a6;
            --gray-500: #5f6368;
            --gray-600: #3c4043;
            --gray-700: #202124;
            --gray-800: #1a1a1a;
            --gray-900: #111111;
            --white: #ffffff;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Quicksand", sans-serif;

        }

        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #f8f9fa 0%, #e8f5e8 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            color: var(--gray-700);
        }

        .maintenance-container {
            max-width: 1000px;
            width: 100%;
        }

        .maintenance-card {
            background: var(--white);
            border-radius: 12px;
            padding: 2rem;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-200);
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 2rem;
            align-items: start;
        }

        .left-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            min-width: 200px;
        }

        .maintenance-icon {
            width: 80px;
            height: 80px;
            background: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .maintenance-icon svg {
            width: 40px;
            height: 40px;
            color: var(--white);
            animation: spin 3s linear infinite;
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--accent-color);
            color: var(--white);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            background: var(--white);
            border-radius: 50%;
        }

        .maintenance-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 0.5rem;
        }

        .maintenance-message {
            font-size: 1rem;
            color: var(--gray-600);
            line-height: 1.5;
            margin-bottom: 1.5rem;
        }

        .right-section {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .countdown-section {
            background: var(--gray-50);
            border-radius: 8px;
            padding: 1.5rem;
            border: 1px solid var(--gray-200);
        }

        .countdown-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 1rem;
            text-align: center;
        }

        .countdown-timer {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .time-unit {
            background: var(--white);
            border-radius: 8px;
            padding: 1rem 0.5rem;
            text-align: center;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        .time-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            display: block;
            line-height: 1;
        }

        .time-label {
            font-size: 0.75rem;
            color: var(--gray-500);
            font-weight: 500;
            margin-top: 0.25rem;
            text-transform: uppercase;
        }

        .progress-section {
            margin-bottom: 1rem;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .progress-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-700);
        }

        .progress-percentage {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .progress-bar-container {
            width: 100%;
            height: 8px;
            background: var(--gray-200);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: var(--primary-color);
            border-radius: 4px;
            transition: width 1s ease-in-out;
        }

        .maintenance-details {
            background: var(--gray-50);
            border-radius: 8px;
            padding: 1.5rem;
            border: 1px solid var(--gray-200);
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .detail-item {
            background: var(--white);
            border-radius: 6px;
            padding: 1rem;
            border: 1px solid var(--gray-200);
        }

        .detail-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray-500);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
        }

        .detail-value {
            font-size: 0.875rem;
            color: var(--gray-800);
            font-weight: 500;
        }

        .action-section {
            display: flex;
            gap: 1rem;
            align-items: center;
            justify-content: space-between;
        }

        .refresh-button {
            background: var(--primary-color);
            color: var(--white);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s ease;
            box-shadow: var(--shadow-sm);
        }

        .refresh-button:hover {
            background: var(--primary-dark);
        }

        .refresh-button:disabled {
            background: var(--gray-400);
            cursor: not-allowed;
        }

        .bypass-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            border: 1px solid var(--primary-color);
            transition: all 0.2s ease;
            background: var(--white);
        }

        .bypass-link:hover {
            background: var(--primary-color);
            color: var(--white);
        }

        .auto-refresh-indicator {
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            background: var(--white);
            border-radius: 8px;
            padding: 0.75rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-200);
            display: none;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
        }

        .refresh-timer {
            font-weight: 600;
            color: var(--primary-color);
            min-width: 1.5rem;
            text-align: center;
        }

        .timer-icon {
            width: 16px;
            height: 16px;
            color: var(--primary-color);
        }

        @media (max-width: 768px) {
            .maintenance-card {
                grid-template-columns: 1fr;
                gap: 1.5rem;
                padding: 1.5rem;
            }

            .left-section {
                min-width: auto;
            }

            .maintenance-icon {
                width: 60px;
                height: 60px;
            }

            .maintenance-icon svg {
                width: 30px;
                height: 30px;
            }

            .maintenance-title {
                font-size: 1.5rem;
            }

            .countdown-timer {
                grid-template-columns: repeat(2, 1fr);
            }

            .details-grid {
                grid-template-columns: 1fr;
            }

            .action-section {
                flex-direction: column;
                align-items: stretch;
            }
        }

        @media (max-width: 480px) {
            .maintenance-card {
                padding: 1rem;
            }

            .maintenance-title {
                font-size: 1.25rem;
            }

            .maintenance-message {
                font-size: 0.875rem;
            }

            .countdown-timer {
                grid-template-columns: repeat(4, 1fr);
                gap: 0.5rem;
            }

            .time-unit {
                padding: 0.75rem 0.25rem;
            }

            .time-value {
                font-size: 1.25rem;
            }
        }
    </style>
</head>

<body>
    <div class="maintenance-container">
        <div class="maintenance-card">
            <div class="left-section">
                <div class="maintenance-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                </div>

                <div class="status-badge">
                    <span class="status-dot"></span>
                    Under Maintenance
                </div>

                <h1 class="maintenance-title"><?= htmlspecialchars($maintenanceSettings['title']) ?></h1>
                <p class="maintenance-message"><?= htmlspecialchars($maintenanceSettings['message']) ?></p>

                <div class="action-section">
                    <button class="refresh-button" onclick="checkMaintenanceStatus()">
                        <span id="refreshText">Check Status</span>
                    </button>

                    <?php if ($showBypass): ?>
                        <a href="index.php?bypass=1" class="bypass-link">
                            <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2zm3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z" />
                            </svg>
                            Developer Access
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="right-section">
                <?php if ($remainingTime > 0): ?>
                    <div class="countdown-section">
                        <h3 class="countdown-title">Estimated Time Remaining</h3>
                        <div class="countdown-timer" id="countdownTimer">
                            <div class="time-unit">
                                <span class="time-value" id="days">0</span>
                                <span class="time-label">Days</span>
                            </div>
                            <div class="time-unit">
                                <span class="time-value" id="hours">0</span>
                                <span class="time-label">Hours</span>
                            </div>
                            <div class="time-unit">
                                <span class="time-value" id="minutes">0</span>
                                <span class="time-label">Minutes</span>
                            </div>
                            <div class="time-unit">
                                <span class="time-value" id="seconds">0</span>
                                <span class="time-label">Seconds</span>
                            </div>
                        </div>

                        <div class="progress-section">
                            <div class="progress-header">
                                <span class="progress-label">Maintenance Progress</span>
                                <span class="progress-percentage" id="progressPercentage"><?= number_format($progressPercentage, 1) ?>%</span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar" id="progressBar" style="width: <?= $progressPercentage ?>%"></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="maintenance-details">
                    <div class="details-grid">
                        <div class="detail-item">
                            <div class="detail-label">Status</div>
                            <div class="detail-value">Maintenance Active</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Started</div>
                            <div class="detail-value"><?= date('M j, Y g:i A', strtotime($maintenanceSettings['created_at'])) ?></div>
                        </div>
                        <?php if (!empty($maintenanceSettings['estimated_completion'])): ?>
                            <div class="detail-item">
                                <div class="detail-label">Estimated Completion</div>
                                <div class="detail-value"><?= date('M j, Y g:i A', strtotime($maintenanceSettings['estimated_completion'])) ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($maintenanceSettings['contact_email'])): ?>
                            <div class="detail-item">
                                <div class="detail-label">Contact</div>
                                <div class="detail-value">
                                    <a href="mailto:<?= htmlspecialchars($maintenanceSettings['contact_email']) ?>"
                                        style="color: var(--primary-color); text-decoration: none;">
                                        <?= htmlspecialchars($maintenanceSettings['contact_email']) ?>
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="detail-item">
                            <div class="detail-label">Last Checked</div>
                            <div class="detail-value" id="lastChecked"><?= date('g:i:s A') ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="auto-refresh-indicator" id="refreshIndicator">
        <svg class="timer-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
        </svg>
        <span class="refresh-timer" id="refreshTimer">30</span>
    </div>

    <script>
        // JavaScript variables from PHP
        const estimatedCompletionTimestamp = <?= $estimatedCompletion ?>;
        const initialProgressPercentage = <?= $progressPercentage ?>;
        const totalDuration = <?= $totalDuration ?>;
        const createdAtTimestamp = <?= $createdAt ?>;

        let autoRefreshInterval;
        let countdownInterval;
        let refreshCounter = 30;
        let mainCountdownInterval;

        function updateCountdown() {
            const now = Math.floor(Date.now() / 1000);
            const remainingTime = estimatedCompletionTimestamp - now;

            if (remainingTime <= 0) {
                document.getElementById('days').textContent = '0';
                document.getElementById('hours').textContent = '0';
                document.getElementById('minutes').textContent = '0';
                document.getElementById('seconds').textContent = '0';

                // Update progress to 100%
                const progressBar = document.getElementById('progressBar');
                const progressPercentage = document.getElementById('progressPercentage');
                if (progressBar && progressPercentage) {
                    progressBar.style.width = '100%';
                    progressPercentage.textContent = '100.0%';
                }

                // Check if maintenance is complete
                checkMaintenanceStatus();
                return;
            }

            const days = Math.floor(remainingTime / (24 * 3600));
            const hours = Math.floor((remainingTime % (24 * 3600)) / 3600);
            const minutes = Math.floor((remainingTime % 3600) / 60);
            const seconds = remainingTime % 60;

            document.getElementById('days').textContent = days;
            document.getElementById('hours').textContent = hours;
            document.getElementById('minutes').textContent = minutes;
            document.getElementById('seconds').textContent = seconds;

            // Update progress bar continuously
            updateProgressBar();
        }

        function updateProgressBar() {
            if (totalDuration > 0) {
                const now = Math.floor(Date.now() / 1000);
                const elapsedTime = now - createdAtTimestamp;
                const progressPercent = Math.max(0, Math.min(100, (elapsedTime / totalDuration) * 100));

                const progressBar = document.getElementById('progressBar');
                const progressPercentage = document.getElementById('progressPercentage');

                if (progressBar && progressPercentage) {
                    progressBar.style.width = progressPercent.toFixed(1) + '%';
                    progressPercentage.textContent = progressPercent.toFixed(1) + '%';
                }
            }
        }

        function updateLastChecked() {
            const now = new Date();
            const timeString = now.toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
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

        function startMainCountdown() {
            // Only start countdown if we have a valid estimated completion time
            if (estimatedCompletionTimestamp > 0) {
                updateCountdown(); // Initial update
                mainCountdownInterval = setInterval(updateCountdown, 1000);
            }
        }

        // Handle visibility change to pause/resume auto-refresh
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopAutoRefresh();
                if (mainCountdownInterval) {
                    clearInterval(mainCountdownInterval);
                }
            } else {
                startAutoRefresh();
                startMainCountdown();
                checkMaintenanceStatus();
            }
        });

        // Initialize everything when page loads
        document.addEventListener('DOMContentLoaded', () => {
            startAutoRefresh();
            startMainCountdown();
            updateLastChecked();

            // Ensure progress bar updates immediately
            updateProgressBar();

            // Update progress bar every second along with countdown
            setInterval(updateProgressBar, 1000);
        });

        // Keyboard accessibility
        document.addEventListener('keydown', (e) => {
            if (e.key === 'r' || e.key === 'R') {
                if (e.ctrlKey || e.metaKey) return; // Don't interfere with browser refresh
                e.preventDefault();
                checkMaintenanceStatus();
            }
        });
    </script>
</body>

</html>