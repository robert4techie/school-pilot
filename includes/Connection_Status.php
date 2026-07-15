<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connection Status Monitor</title>
    <style>
        :root {
            --online-color: #4CAF50;
            --offline-color: #F44336;
            --reconnecting-color: #FF9800;
            --transition-speed: 0.3s;
        }
        
        .status-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 8px;
            color: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            transform: translateY(150%);
            opacity: 0;
            transition: all var(--transition-speed) cubic-bezier(0.68, -0.55, 0.265, 1.55);
            z-index: 1000;
            max-width: 320px;
            min-width: 280px;
        }
        
        .status-notification.active {
            transform: translateY(0);
            opacity: 1;
        }
        
        .status-notification.online {
            background-color: var(--online-color);
        }
        
        .status-notification.offline {
            background-color: var(--offline-color);
        }
        
        .status-notification.reconnecting {
            background-color: var(--reconnecting-color);
        }
        
        .status-icon {
            font-size: 24px;
            line-height: 1;
        }
        
        .status-content {
            flex: 1;
        }
        
        .status-title {
            font-weight: 600;
            margin-bottom: 4px;
            font-size: 16px;
        }
        
        .status-message {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .close-btn {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.2s;
            font-size: 20px;
            padding: 4px;
            margin-left: 8px;
            line-height: 1;
        }
        
        .close-btn:hover {
            opacity: 1;
        }
        
        .progress-bar {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 4px;
            background-color: rgba(255, 255, 255, 0.3);
            width: 100%;
            border-radius: 0 0 8px 8px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background-color: white;
            width: 100%;
            transition: width linear;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .pulse {
            animation: pulse 1.5s ease-in-out infinite;
        }
        
        .spin {
            animation: spin 1s linear infinite;
        }
    </style>
</head>
<body>
    <div class="status-notification" id="statusNotification">
        <span class="status-icon" id="statusIcon">🌐</span>
        <div class="status-content">
            <div class="status-title" id="statusTitle">Connection Status</div>
            <div class="status-message" id="statusMessage">Checking your network connection...</div>
        </div>
        <button class="close-btn" id="closeBtn" aria-label="Close notification">×</button>
        <div class="progress-bar">
            <div class="progress-fill" id="progressFill"></div>
        </div>
    </div>

    <script>
        class ConnectionStatus {
            constructor() {
                this.notification = document.getElementById('statusNotification');
                this.statusIcon = document.getElementById('statusIcon');
                this.statusTitle = document.getElementById('statusTitle');
                this.statusMessage = document.getElementById('statusMessage');
                this.closeBtn = document.getElementById('closeBtn');
                this.progressFill = document.getElementById('progressFill');
                
                this.notificationVisible = false;
                this.autoDismissTimeout = null;
                this.connectionCheckInterval = null;
                this.reconnectTimeout = null;
                this.reconnectAttempts = 0;
                this.maxReconnectAttempts = 10;
                this.reconnectDelay = 2000;
                
                this.currentState = 'unknown';
                this.lastSuccessfulCheck = Date.now();
                
                this.init();
            }
            
            init() {
                // Set initial state based on browser
                this.currentState = navigator.onLine ? 'online' : 'offline';
                
                // Only test connection if browser reports offline
                if (!navigator.onLine) {
                    this.testConnection();
                }
                
                window.addEventListener('online', () => this.handleBrowserOnlineEvent());
                window.addEventListener('offline', () => this.handleBrowserOfflineEvent());
                this.closeBtn.addEventListener('click', () => this.dismissNotification());
                
                this.startConnectionMonitoring();
                
                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden && this.currentState !== 'online') {
                        this.testConnection();
                    }
                });
            }
            
            startConnectionMonitoring() {
                if (this.connectionCheckInterval) {
                    clearInterval(this.connectionCheckInterval);
                }
                
                const checkInterval = this.currentState === 'online' ? 10000 : 5000;
                
                this.connectionCheckInterval = setInterval(() => {
                    this.testConnection();
                }, checkInterval);
            }
            
            handleBrowserOnlineEvent() {
                console.log('Browser online event detected');
                this.testConnection();
            }
            
            handleBrowserOfflineEvent() {
                console.log('Browser offline event detected');
                this.updateConnectionState('offline');
            }
            
            async testConnection() {
                try {
                    if (this.currentState === 'offline') {
                        this.updateConnectionState('reconnecting');
                    }
                    
                    const isConnected = await this.verifyConnection();
                    
                    if (isConnected) {
                        this.reconnectAttempts = 0;
                        this.reconnectDelay = 2000;
                        this.lastSuccessfulCheck = Date.now();
                        this.updateConnectionState('online');
                        this.clearReconnectTimeout();
                    } else {
                        this.updateConnectionState('offline');
                        this.scheduleReconnectAttempt();
                    }
                } catch (error) {
                    console.error('Connection test failed:', error);
                    this.updateConnectionState('offline');
                    this.scheduleReconnectAttempt();
                }
            }
            
            async verifyConnection() {
                // First, check browser's built-in navigator.onLine
                if (!navigator.onLine) {
                    return false;
                }
                
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 5000);
                
                try {
                    // Use mode: 'no-cors' which will succeed if the request goes through
                    // even if we can't read the response due to CORS
                    const response = await fetch('https://www.google.com/favicon.ico', {
                        method: 'GET',
                        mode: 'no-cors',
                        cache: 'no-store',
                        signal: controller.signal
                    });
                    
                    clearTimeout(timeoutId);
                    // If fetch didn't throw, we have connectivity
                    return true;
                } catch (error) {
                    clearTimeout(timeoutId);
                    
                    // If the error is because we aborted, that means timeout - likely offline
                    if (error.name === 'AbortError') {
                        return false;
                    }
                    
                    // Try a CORS-friendly endpoint as backup
                    try {
                        const altController = new AbortController();
                        const altTimeoutId = setTimeout(() => altController.abort(), 5000);
                        
                        const altResponse = await fetch('https://jsonplaceholder.typicode.com/posts/1', {
                            method: 'GET',
                            cache: 'no-store',
                            signal: altController.signal
                        });
                        
                        clearTimeout(altTimeoutId);
                        return altResponse.ok;
                    } catch (altError) {
                        // Both attempts failed, but if browser says we're online, trust it
                        return navigator.onLine;
                    }
                }
            }
            
            clearReconnectTimeout() {
                if (this.reconnectTimeout) {
                    clearTimeout(this.reconnectTimeout);
                    this.reconnectTimeout = null;
                }
            }
            
            scheduleReconnectAttempt() {
                this.clearReconnectTimeout();
                
                if (this.reconnectAttempts >= this.maxReconnectAttempts) {
                    console.log('Max reconnect attempts reached');
                    return;
                }
                
                this.reconnectAttempts++;
                
                const jitter = Math.random() * 1000;
                const delay = Math.min(
                    this.reconnectDelay + jitter,
                    30000
                );
                
                console.log(`Scheduling reconnect attempt ${this.reconnectAttempts} in ${Math.round(delay)}ms`);
                
                this.reconnectTimeout = setTimeout(() => {
                    if (this.currentState !== 'online') {
                        this.testConnection();
                    }
                }, delay);
                
                this.reconnectDelay = Math.min(this.reconnectDelay * 1.5, 30000);
            }
            
            updateConnectionState(newState) {
                const previousState = this.currentState;
                this.currentState = newState;
                
                console.log(`Connection state: ${previousState} → ${newState}`);
                
                if (newState !== previousState) {
                    switch (newState) {
                        case 'online':
                            if (previousState === 'offline' || previousState === 'reconnecting') {
                                this.showOnlineNotification();
                            }
                            break;
                        case 'offline':
                            this.showOfflineNotification();
                            break;
                        case 'reconnecting':
                            if (previousState === 'offline') {
                                this.showReconnectingNotification();
                            }
                            break;
                    }
                    
                    this.startConnectionMonitoring();
                }
            }
            
            showOnlineNotification() {
                this.statusIcon.textContent = '';
                this.statusIcon.className = 'status-icon pulse';
                this.statusTitle.textContent = 'Back Online!';
                this.statusMessage.textContent = 'Your connection has been restored.';
                
                this.notification.className = 'status-notification online active';
                this.notificationVisible = true;
                
                this.scheduleAutoDismiss(4000);
                this.animateProgressBar(4000);
            }
            
            showOfflineNotification() {
                this.statusIcon.textContent = '';
                this.statusIcon.className = 'status-icon';
                this.statusTitle.textContent = 'Connection Lost';
                this.statusMessage.textContent = "You're currently offline. We'll keep trying to reconnect...";
                
                this.notification.className = 'status-notification offline active';
                this.notificationVisible = true;
                
                this.cancelAutoDismiss();
                this.progressFill.style.width = '100%';
            }
            
            showReconnectingNotification() {
                this.statusIcon.textContent = '';
                this.statusIcon.className = 'status-icon spin';
                this.statusTitle.textContent = 'Reconnecting...';
                this.statusMessage.textContent = `Attempting to restore connection (${this.reconnectAttempts}/${this.maxReconnectAttempts})`;
                
                this.notification.className = 'status-notification reconnecting active';
                this.notificationVisible = true;
                
                this.cancelAutoDismiss();
                this.progressFill.style.width = '100%';
            }
            
            scheduleAutoDismiss(duration) {
                this.cancelAutoDismiss();
                this.autoDismissTimeout = setTimeout(() => {
                    this.dismissNotification();
                }, duration);
            }
            
            cancelAutoDismiss() {
                if (this.autoDismissTimeout) {
                    clearTimeout(this.autoDismissTimeout);
                    this.autoDismissTimeout = null;
                }
            }
            
            animateProgressBar(duration) {
                this.progressFill.style.transition = 'none';
                this.progressFill.style.width = '100%';
                
                void this.progressFill.offsetWidth;
                
                this.progressFill.style.transition = `width ${duration}ms linear`;
                this.progressFill.style.width = '0%';
            }
            
            dismissNotification() {
                this.notification.classList.remove('active');
                this.notificationVisible = false;
                this.cancelAutoDismiss();
                
                setTimeout(() => {
                    this.notification.className = 'status-notification';
                    this.progressFill.style.width = '100%';
                    this.statusIcon.className = 'status-icon';
                }, 300);
            }
        }
        
        let connectionMonitor;
        document.addEventListener('DOMContentLoaded', () => {
            connectionMonitor = new ConnectionStatus();
        });
    </script>
</body>
</html>