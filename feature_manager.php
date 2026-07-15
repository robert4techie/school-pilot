<?php
// Put all required files at the top
require_once 'conn.php';
require_once 'auth.php';
require_once 'tracking.php';
$tracker->trackAction("Feature Manager");

// --- START: Developer Role Check ---
// After confirming the user is logged in (from auth.php),
// now check if they have the specific 'developer' role.
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'developer') {
    // If the user_type is not set or is not 'developer', redirect them.
    header("Location: access_denied.php");
    exit(); // Stop script execution immediately after redirection
}
// --- END: Developer Role Check ---

// Handle AJAX requests FIRST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    // Set proper headers
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');

    // Error reporting for debugging
    error_reporting(E_ALL);
    ini_set('display_errors', 0); // Don't display errors in response

    try {
        // Check database connection
        if (!$conn) {
            throw new Exception('Database connection failed');
        }

        // Handle single feature toggle
        if (isset($_POST['feature_key']) && isset($_POST['enabled'])) {
            $feature_key = mysqli_real_escape_string($conn, $_POST['feature_key']);
            $enabled = (int)$_POST['enabled']; // Convert to integer (0 or 1)

            $stmt = $conn->prepare("UPDATE feature_settings SET is_enabled = ? WHERE feature_key = ?");
            if (!$stmt) {
                throw new Exception('Failed to prepare statement: ' . mysqli_error($conn));
            }

            $stmt->bind_param("is", $enabled, $feature_key);
            $execute_result = $stmt->execute();

            if (!$execute_result) {
                throw new Exception('Failed to execute statement: ' . $stmt->error);
            }
            
            $affected_rows = $stmt->affected_rows;
            $stmt->close();

            if ($affected_rows === 0) {
                throw new Exception('Feature not found or no changes made');
            }

            // Get updated count of enabled features
            $count_result = mysqli_query($conn, "SELECT COUNT(*) as count FROM feature_settings WHERE is_enabled = 1");
            $count_data = mysqli_fetch_assoc($count_result);
            $enabled_count = $count_data['count'];

            // Success response
            $response = [
                'success' => true,
                'message' => $enabled ? 'Feature enabled successfully' : 'Feature disabled successfully',
                'enabled_count' => $enabled_count,
                'feature_key' => $feature_key,
                'new_state' => $enabled
            ];

            echo json_encode($response);
        } else {
            throw new Exception('Invalid request parameters');
        }

    } catch (Exception $e) {
        // Error response
        $response = [
            'success' => false,
            'message' => 'Error updating feature: ' . $e->getMessage(),
            'error_code' => $e->getCode()
        ];

        echo json_encode($response);

        // Log error for debugging
        error_log('Feature Manager Error: ' . $e->getMessage());
    }

    // IMPORTANT: Stop the script here so it doesn't output the HTML below
    exit;
}

// ---- The rest of your file is for the HTML page load ----

// Include navigation ONLY for the HTML page view
require_once 'nav.php';

// Fetch current settings to display on the page
$features = [];
$result = mysqli_query($conn, "SELECT feature_key, feature_name, is_enabled FROM feature_settings ORDER BY feature_name");
while ($row = mysqli_fetch_assoc($result)) {
    $features[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feature Manager - Developer</title>
    <style>
        :root {
            --primary-green: #10b981;
            --primary-green-dark: #059669;
            --primary-green-light: #34d399;
            --green-50: #ecfdf5;
            --green-100: #d1fae5;
            --green-200: #a7f3d0;
            --green-500: #10b981;
            --green-600: #059669;
            --green-700: #047857;
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
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --transition: all 0.2s ease-in-out;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            text-align: center;
            margin: 3rem 0 4rem;
        }

        .header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.20rem;
            letter-spacing: -0.025em;
        }

        .header p {
            font-size: 1.125rem;
            color: var(--gray-600);
            font-weight: 400;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: 0.75rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-green);
            display: block;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--gray-600);
            font-size: 0.875rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .feature-card {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: 0.75rem;
            padding: 1.5rem;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
        }

        .feature-card:hover {
            border-color: var(--green-300);
            box-shadow: var(--shadow-md);
        }

        .feature-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .feature-info {
            flex: 1;
            min-width: 0;
        }

        .feature-name {
            font-size: 1rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
            line-height: 1.5;
        }

        .feature-description {
            font-size: 0.875rem;
            color: var(--gray-500);
            line-height: 1.4;
        }

        /* Toggle Switch Styles */
        .toggle-wrapper {
            position: relative;
            flex-shrink: 0;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 48px;
            height: 24px;
            cursor: pointer;
        }

        .toggle-input {
            opacity: 0;
            width: 0;
            height: 0;
            position: absolute;
        }

        .toggle-slider {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--gray-300);
            border-radius: 24px;
            transition: var(--transition);
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .toggle-slider:before {
            content: '';
            position: absolute;
            height: 20px;
            width: 20px;
            left: 2px;
            bottom: 2px;
            background: var(--white);
            border-radius: 50%;
            transition: var(--transition);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
        }

        .toggle-input:checked + .toggle-slider {
            background: var(--primary-green);
            box-shadow: inset 0 1px 2px rgba(16, 185, 129, 0.2);
        }

        .toggle-input:checked + .toggle-slider:before {
            transform: translateX(24px);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
        }

        .toggle-switch:hover .toggle-slider {
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.1), 0 0 0 3px rgba(16, 185, 129, 0.1);
        }

        .toggle-input:focus + .toggle-slider {
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.1), 0 0 0 3px rgba(16, 185, 129, 0.2);
        }

        /* Loading State */
        .toggle-switch.loading .toggle-slider:before {
            animation: pulse 1s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.5;
            }
        }

        /* Notification System */
        .notification-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 1000;
            max-width: 400px;
        }

        .notification {
            background: var(--white);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 0.75rem;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-200);
            transform: translateX(100%);
            opacity: 0;
            animation: slideIn 0.3s ease forwards;
            position: relative;
            overflow: hidden;
        }

        .notification.success {
            border-left: 4px solid var(--primary-green);
        }

        .notification.error {
            border-left: 4px solid #ef4444;
        }

        .notification-content {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .notification-icon {
            flex-shrink: 0;
            width: 20px;
            height: 20px;
            margin-top: 0.125rem;
        }

        .notification-text {
            flex: 1;
            min-width: 0;
        }

        .notification-title {
            font-weight: 600;
            color: var(--gray-900);
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .notification-message {
            font-size: 0.875rem;
            color: var(--gray-600);
            line-height: 1.4;
        }

        .notification-close {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            background: none;
            border: none;
            cursor: pointer;
            color: var(--gray-400);
            font-size: 1.25rem;
            line-height: 1;
            padding: 0;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: var(--transition);
        }

        .notification-close:hover {
            color: var(--gray-600);
            background: var(--gray-100);
        }

        @keyframes slideIn {
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        .notification.removing {
            animation: slideOut 0.3s ease forwards;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .header {
                margin: 2rem 0 3rem;
            }

            .header h1 {
                font-size: 2rem;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }

            .stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .notification-container {
                left: 1rem;
                right: 1rem;
                max-width: none;
            }
        }

        @media (max-width: 480px) {
            .feature-content {
                flex-direction: column;
                align-items: stretch;
                gap: 1rem;
            }

            .toggle-wrapper {
                align-self: flex-end;
            }
        }

        /* Status indicator for enabled features */
        .feature-card.enabled {
            border-color: var(--green-200);
            background: linear-gradient(135deg, var(--white) 0%, var(--green-50) 100%);
        }

        .feature-card.enabled .feature-name {
            color: var(--green-700);
        }

        /* Loading overlay for individual cards */
        .feature-card.updating {
            position: relative;
            pointer-events: none;
        }

        .feature-card.updating::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 0.75rem;
        }
    </style>
</head>

<body>
    <div class="notification-container" id="notificationContainer"></div>

    <div class="container">
        <div class="header">
            <h1>Feature Manager</h1>
            <p>Manage system features with instant toggle controls</p>
        </div>

        <div class="stats">
            <div class="stat-card">
                <span class="stat-number" id="totalFeatures"><?= count($features) ?></span>
                <span class="stat-label">Total Features</span>
            </div>
            <div class="stat-card">
                <span class="stat-number" id="enabledFeatures"><?= array_sum(array_column($features, 'is_enabled')) ?></span>
                <span class="stat-label">Enabled</span>
            </div>
            <div class="stat-card">
                <span class="stat-number" id="disabledFeatures"><?= count($features) - array_sum(array_column($features, 'is_enabled')) ?></span>
                <span class="stat-label">Disabled</span>
            </div>
        </div>

        <div class="features-grid">
            <?php foreach ($features as $feature): ?>
                <div class="feature-card <?= $feature['is_enabled'] ? 'enabled' : '' ?>" data-feature="<?= htmlspecialchars($feature['feature_key']) ?>">
                    <div class="feature-content">
                        <div class="feature-info">
                            <div class="feature-name"><?= htmlspecialchars($feature['feature_name']) ?></div>
                            <div class="feature-description">Toggle to enable or disable this feature</div>
                        </div>
                        <div class="toggle-wrapper">
                            <label class="toggle-switch">
                                <input type="checkbox" 
                                       class="toggle-input" 
                                       data-feature="<?= htmlspecialchars($feature['feature_key']) ?>"
                                       <?= $feature['is_enabled'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        class NotificationSystem {
            constructor() {
                this.container = document.getElementById('notificationContainer');
            }

            show(message, type = 'info', title = null, duration = 4000) {
                const notification = document.createElement('div');
                notification.className = `notification ${type}`;

                const icons = {
                    success: `<svg class="notification-icon" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>`,
                    error: `<svg class="notification-icon" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path></svg>`
                };

                const titles = {
                    success: 'Success',
                    error: 'Error'
                };

                notification.innerHTML = `
                    <div class="notification-content">
                        <div style="color: ${type === 'success' ? 'var(--primary-green)' : '#ef4444'}">${icons[type]}</div>
                        <div class="notification-text">
                            <div class="notification-title">${title || titles[type]}</div>
                            <div class="notification-message">${message}</div>
                        </div>
                    </div>
                    <button class="notification-close" onclick="this.parentElement.remove()">×</button>
                `;

                this.container.appendChild(notification);

                setTimeout(() => {
                    this.remove(notification);
                }, duration);

                return notification;
            }

            remove(notification) {
                if (notification.parentElement) {
                    notification.classList.add('removing');
                    setTimeout(() => {
                        if (notification.parentElement) {
                            notification.parentElement.removeChild(notification);
                        }
                    }, 300);
                }
            }

            success(message, title = null) {
                return this.show(message, 'success', title);
            }

            error(message, title = null) {
                return this.show(message, 'error', title);
            }
        }

        const notify = new NotificationSystem();

        function updateStats() {
            const toggles = document.querySelectorAll('.toggle-input');
            const totalCount = toggles.length;
            const enabledCount = Array.from(toggles).filter(toggle => toggle.checked).length;
            const disabledCount = totalCount - enabledCount;

            document.getElementById('enabledFeatures').textContent = enabledCount;
            document.getElementById('disabledFeatures').textContent = disabledCount;
        }

        function updateFeatureCard(featureKey, enabled) {
            const card = document.querySelector(`[data-feature="${featureKey}"]`);
            if (card) {
                if (enabled) {
                    card.classList.add('enabled');
                } else {
                    card.classList.remove('enabled');
                }
            }
        }

        // Handle toggle switch changes
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('toggle-input')) {
                const featureKey = e.target.getAttribute('data-feature');
                const enabled = e.target.checked ? 1 : 0;
                const card = document.querySelector(`[data-feature="${featureKey}"]`);
                const toggleSwitch = e.target.closest('.toggle-switch');

                // Add loading state
                card.classList.add('updating');
                toggleSwitch.classList.add('loading');

                const formData = new FormData();
                formData.append('ajax', '1');
                formData.append('feature_key', featureKey);
                formData.append('enabled', enabled);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }

                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        throw new Error('Response is not JSON');
                    }

                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        updateFeatureCard(featureKey, enabled);
                        updateStats();
                        
                        const actionText = enabled ? 'enabled' : 'disabled';
                        notify.success(`Feature ${actionText} successfully`);
                    } else {
                        // Revert the toggle state on error
                        e.target.checked = !e.target.checked;
                        notify.error(data.message || 'Failed to update feature');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    // Revert the toggle state on error
                    e.target.checked = !e.target.checked;
                    
                    let errorMessage = 'Failed to update feature. Please try again.';
                    if (error.message.includes('HTTP error')) {
                        errorMessage = 'Server error occurred. Please check your connection.';
                    } else if (error.message.includes('Failed to fetch')) {
                        errorMessage = 'Network error. Please check your connection.';
                    }
                    
                    notify.error(errorMessage);
                })
                .finally(() => {
                    // Remove loading states
                    card.classList.remove('updating');
                    toggleSwitch.classList.remove('loading');
                });
            }
        });

        // Initialize stats on page load
        updateStats();
    </script>
</body>

</html>