/**
 * Notification System with Sound
 * This script provides toast notifications with sound effects for form submission
 */

// Sound files - replace URLs with actual paths to your sound files
const NOTIFICATION_SOUNDS = {
    success: 'sounds/success.mp3',
    error: 'sounds/error.wav',
    warning: 'assets/sounds/warning.mp3'
};

// Create notification container if it doesn't exist
document.addEventListener('DOMContentLoaded', function() {
    if (!document.getElementById('notification-container')) {
        const container = document.createElement('div');
        container.id = 'notification-container';
        container.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        `;
        document.body.appendChild(container);
    }
    
    // Add styles for notifications if not already in document
    if (!document.getElementById('notification-styles')) {
        const styles = document.createElement('style');
        styles.id = 'notification-styles';
        styles.textContent = `
            .notification {
                padding: 15px 20px;
                border-radius: 5px;
                color: white;
                margin-bottom: 10px;
                min-width: 300px;
                max-width: 500px;
                box-shadow: 0 4px 8px rgba(0,0,0,0.2);
                display: flex;
                justify-content: space-between;
                align-items: center;
                animation: notification-slide-in 0.4s ease-out;
                transition: all 0.3s ease;
            }
            
            .notification.hide {
                opacity: 0;
                transform: translateX(100%);
            }
            
            .notification-success {
                background-color: #4CAF50;
            }
            
            .notification-error {
                background-color: #f44336;
            }
            
            .notification-warning {
                background-color: #ff9800;
            }
            
            .notification-content {
                flex-grow: 1;
                padding-right: 10px;
            }
            
            .notification-title {
                font-weight: bold;
                margin-bottom: 5px;
            }
            
            .notification-close {
                background: transparent;
                border: none;
                color: white;
                cursor: pointer;
                font-size: 20px;
                padding: 0 5px;
                line-height: 20px;
            }
            
            @keyframes notification-slide-in {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
        `;
        document.head.appendChild(styles);
    }
});

/**
 * Show a notification with sound
 * @param {string} message - Message to display
 * @param {string} type - Type of notification (success, error, warning)
 * @param {string} title - Title of notification
 * @param {number} duration - Duration in milliseconds
 */
function showNotification(message, type = 'success', title = null, duration = 5000) {
    const container = document.getElementById('notification-container');
    if (!container) return;
    
    // Set default title based on type if not provided
    if (!title) {
        switch (type) {
            case 'success':
                title = 'Success';
                break;
            case 'error':
                title = 'Error';
                break;
            case 'warning':
                title = 'Warning';
                break;
            default:
                title = 'Notification';
        }
    }
    
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    
    // Create content
    const content = document.createElement('div');
    content.className = 'notification-content';
    
    const titleElement = document.createElement('div');
    titleElement.className = 'notification-title';
    titleElement.textContent = title;
    content.appendChild(titleElement);
    
    const messageElement = document.createElement('div');
    messageElement.textContent = message;
    content.appendChild(messageElement);
    
    notification.appendChild(content);
    
    // Create close button
    const closeButton = document.createElement('button');
    closeButton.className = 'notification-close';
    closeButton.innerHTML = '&times;';
    closeButton.addEventListener('click', function() {
        notification.classList.add('hide');
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    });
    notification.appendChild(closeButton);
    
    // Add to container
    container.appendChild(notification);
    
    // Play sound
    playNotificationSound(type);
    
    // Auto remove after duration
    setTimeout(() => {
        notification.classList.add('hide');
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, duration);
    
    return notification;
}

/**
 * Play notification sound
 * @param {string} type - Type of notification (success, error, warning)
 */
function playNotificationSound(type) {
    if (!NOTIFICATION_SOUNDS[type]) return;
    
    const audio = new Audio(NOTIFICATION_SOUNDS[type]);
    audio.volume = 0.5; // Adjust volume as needed
    
    // Try to play the sound
    const playPromise = audio.play();
    
    // Handle autoplay policy
    if (playPromise !== undefined) {
        playPromise.catch(error => {
            console.warn('Sound could not be played automatically:', error);
            // You might want to show a sound button here if needed
        });
    }
}

/**
 * Show error messages from validation
 * @param {Array} errors - Array of error messages
 */
function showValidationErrors(errors) {
    if (!errors || !errors.length) return;
    
    let errorMessage = '<ul style="margin: 0; padding-left: 20px;">';
    errors.forEach(error => {
        errorMessage += `<li>${error}</li>`;
    });
    errorMessage += '</ul>';
    
    showNotification(errorMessage, 'error', 'Validation Errors', 8000);
}