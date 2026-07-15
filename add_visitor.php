<?php
require_once 'conn.php';
require_once 'auth.php';
require_once 'tracking.php';
$tracker->trackAction("Add School Visitor");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Pilot - Visitor Management </title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-green: #2E7D32;
            --light-green: #4CAF50;
            --pale-green: #E8F5E9;
            --accent-green: #1B5E20;
            --text-dark: #212121;
            --text-light: #FFFFFF;
            --gray-light: #EEEEEE;
            --gray-medium: #9E9E9E;
            --box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: #f9fbf9;
            color: var(--text-dark);
            line-height: 1.6;
        }

        .content-container {
            max-width: 100%;
            margin: 20px auto;
            padding: 0 20px;
        }

        .page-header {
            background: linear-gradient(135deg, var(--primary-green), var(--accent-green));
            color: white;
            padding: 20px 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            margin-top: 70px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--box-shadow);
        }

        .page-header h3 {
            font-size: 22px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-actions {
            display: flex;
            gap: 12px;
        }

        .btn5 {
            padding: 10px 18px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 15px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .btn5-primary {
            background-color: white;
            color: var(--primary-green);
        }

        .btn5-primary:hover {
            background-color: #f0f0f0;
            transform: translateY(-2px);
        }

        .btn5-outline {
            background-color: transparent;
            color: white;
            border: 1px solid white;
        }

        .btn5-outline:hover {
            background-color: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }

        .card {
            background-color: white;
            border-radius: 10px;
            box-shadow: var(--box-shadow);
            margin-bottom: 30px;
            border: 1px solid rgba(76, 175, 80, 0.1);
            overflow: hidden;
        }

        .card-header {
            background-color: var(--pale-green);
            padding: 18px 25px;
            border-bottom: 2px solid rgba(76, 175, 80, 0.2);
            font-weight: 600;
            color: var(--primary-green);
            font-size: 18px;
            display: flex;
            align-items: center;
        }

        .card-header i {
            margin-right: 10px;
        }

        .card-body {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 500;
            color: var(--text-dark);
            font-size: 15px;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #E0E0E0;
            border-radius: 6px;
            font-size: 15px;
            transition: var(--transition);
            background-color: #FAFAFA;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--light-green);
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
            background-color: white;
        }

        .form-row {
            display: flex;
            gap: 25px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .form-col {
            flex: 1;
            min-width: 250px;
        }

        .btn5-success {
            background: linear-gradient(to right, var(--light-green), var(--accent-green));
            color: white;
            padding: 12px 24px;
            font-size: 16px;
            border-radius: 6px;
            box-shadow: 0 4px 6px rgba(46, 125, 50, 0.2);
        }

        .btn5-success:hover {
            background: linear-gradient(to right, var(--accent-green), var(--primary-green));
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(46, 125, 50, 0.3);
        }

        .required-field::after {
            content: "*";
            color: #e53935;
            margin-left: 4px;
        }

        .input-icon-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-medium);
        }

        .has-icon {
            padding-left: 40px;
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%232E7D32' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 15px;
            padding-right: 40px;
        }

        .section-divider {
            width: 100%;
            height: 1px;
            background-color: #E0E0E0;
            margin: 30px 0;
        }

        .form-footer {
            display: flex;
            justify-content: flex-end;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #E0E0E0;
        }

        /* Field status indicators */
        .field-valid::after {
            content: "✓";
            position: absolute;
            right: 15px;
            top: 40px;
            color: var(--light-green);
        }

        /* Form field focus animation */
        .form-control:focus {
            animation: pulse 1s;
        }

        .notification {
            position: relative;
            padding: 15px 20px;
            margin-bottom: 10px;
            border-radius: 5px;
            color: white;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            cursor: pointer;
            transform: translateX(100%);
            opacity: 0;
            transition: all 0.3s ease;
            overflow: hidden;
            max-width: 350px;
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

        .notification-info {
            background-color: #2196F3;
        }

        .notification-content {
            display: flex;
            align-items: center;
            width: 100%;
        }

        .notification-content i {
            margin-right: 10px;
            font-size: 1.2em;
        }

        .notification-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background-color: rgba(255, 255, 255, 0.5);
            width: 0;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(76, 175, 80, 0.4);
            }

            70% {
                box-shadow: 0 0 0 5px rgba(76, 175, 80, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(76, 175, 80, 0);
            }
        }

        /* Hover effects */
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 15px;
            }

            .page-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
                padding: 15px 20px;
            }

            .card-body {
                padding: 20px;
            }

            .form-col {
                min-width: 100%;
            }
        }
    </style>
</head>

<body>
     <!-- Notification Container - Place this right after <body> -->
    <div id="notification-container" style="position: fixed; top: 70px; right: 20px; z-index: 9999;"></div>
    <?php require_once 'nav.php'; ?>
    <!-- Add Visitor Page -->
    <div id="add-visitor" class="content-container main-container" style="display: block;">
        <div class="page-header">
            <h3><i class="fas fa-user-plus"></i> Add Visitor</h3>
        </div>

        <div class="card">
            <div class="card-header">
                <i class="fas fa-info-circle"></i> Visitor Information
            </div>

            <div class="card-body">
                <form id="visitor-form">
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label class="required-field">First Name</label>
                                <div class="input-icon-wrapper">
                                    <i class="fas fa-user input-icon"></i>
                                    <input type="text" id="first-name" name="first_name" class="form-control has-icon" required placeholder="Enter first name">
                                </div>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label class="required-field">Last Name</label>
                                <div class="input-icon-wrapper">
                                    <i class="fas fa-user input-icon"></i>
                                    <input type="text" id="last-name" name="last_name" class="form-control has-icon" required placeholder="Enter last name">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label class="required-field">Email Address</label>
                                <div class="input-icon-wrapper">
                                    <i class="fas fa-envelope input-icon"></i>
                                    <input type="email" id="email" name="email" class="form-control has-icon" required placeholder="Enter email address">
                                </div>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label class="required-field">Phone Number</label>
                                <div class="input-icon-wrapper">
                                    <i class="fas fa-phone input-icon"></i>
                                    <input type="tel" id="phone" name="phone" class="form-control has-icon" required placeholder="Enter phone number">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="section-divider"></div>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label>Company/Organization</label>
                                <div class="input-icon-wrapper">
                                    <i class="fas fa-building input-icon"></i>
                                    <input type="text" id="company" name="company" class="form-control has-icon" placeholder="Enter company/organization name">
                                </div>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label class="required-field">Purpose of Visit</label>
                                <select id="visit-purpose" name="visit_purpose" class="form-control" required>
                                    <option value="">-- Select Purpose --</option>
                                    <option value="meeting">Meeting</option>
                                    <option value="interview">Interview</option>
                                    <option value="delivery">Delivery</option>
                                    <option value="event">Event</option>
                                    <option value="tour">School Tour</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label class="required-field">Host/Person to Visit</label>
                                <div class="input-icon-wrapper">
                                    <i class="fas fa-user-tie input-icon"></i>
                                    <input type="text" id="host" name="host" class="form-control has-icon" placeholder="Enter Name & (title)" required>
                                </div>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label class="required-field">Visit Date and Time</label>
                                <div class="input-icon-wrapper">
                                    <i class="fas fa-calendar-alt input-icon"></i>
                                    <input type="datetime-local" id="visit-date" name="visit_date" class="form-control has-icon" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label>Number Plate</label>
                                <div class="input-icon-wrapper">
                                    <i class="fas fa-car input-icon"></i>
                                    <input type="text" id="number-plate" name="number_plate" class="form-control has-icon" placeholder="Enter vehicle number plate">
                                </div>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label class="required-field">Address</label>
                                <div class="input-icon-wrapper">
                                    <i class="fas fa-map-marker-alt input-icon"></i>
                                    <input type="text" id="Address" name="address" class="form-control has-icon" required placeholder="Enter address">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-footer">
                        <button type="reset" class="btn5 btn5-outline" style="margin-right: 15px; color: var(--gray-medium); border: 1px solid #E0E0E0;"><i class="fas fa-undo"></i> Reset Form</button>
                        <button type="submit" class="btn5 btn5-success"><i class="fas fa-save"></i> Save Visitor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
        // Notification System
        function showNotification(message, type = 'success', duration = 5000) {
            const container = document.getElementById('notification-container');
            if (!container) return;

            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
        <div class="notification-content">
            <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
            <span>${message}</span>
        </div>
        <div class="notification-progress"></div>
    `;

            container.appendChild(notification);

            // Slide in animation
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
                notification.style.opacity = '1';
            }, 10);

            // Progress bar animation
            const progress = notification.querySelector('.notification-progress');
            if (progress) {
                progress.style.width = '100%';
                progress.style.transition = `width ${duration}ms linear`;
            }

            // Auto-dismiss after duration
            setTimeout(() => {
                notification.style.transform = 'translateX(100%)';
                notification.style.opacity = '0';
                setTimeout(() => notification.remove(), 300);
            }, duration);

            // Manual dismiss on click
            notification.addEventListener('click', () => {
                notification.style.transform = 'translateX(100%)';
                notification.style.opacity = '0';
                setTimeout(() => notification.remove(), 300);
            });
        }
        document.getElementById('visitor-form').addEventListener('submit', function(e) {
            e.preventDefault();

            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            submitBtn.disabled = true;

            const formData = new FormData(this);

            fetch('save_visitor.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        showNotification(data.message, 'success');
                        this.reset();
                    } else {
                        showNotification(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification(error.message || 'An error occurred. Please try again.', 'error');
                })
                .finally(() => {
                    // Restore button state
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
        });
    </script>
</body>

</html>