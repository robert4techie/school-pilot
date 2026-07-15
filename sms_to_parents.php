
<?php require_once 'auth.php'?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS To Parents - schoolPilot</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-green: #28a745;
            --light-green: #d4edda;
            --dark-green: #218838;
            --lighter-green: #e8f5e9;
        }
        
        body {
            background-color: #f8f9fa;
        }
        .container{
            margin-top: 80px;
        }
        .send-btn {
            background-color: var(--primary-green);
            border-color: var(--primary-green);
            font-weight: 500;
            letter-spacing: 0.5px;
            padding: 0.1rem 2rem;
        }
        
        .send-btn:hover, .send-btn:focus {
            background-color: var(--dark-green);
            border-color: var(--dark-green);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .send-btn:active {
            transform: translateY(0);
        }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            overflow: hidden;
            transition: transform 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-5px);
        }
        
        .card-header {
            background-color: var(--primary-green);
            color: white;
            font-weight: 600;
            padding: 1.25rem 1.5rem;
            border-bottom: none;
        }
        
        .card-body {
            padding: 2rem;
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            padding: 0.75rem 1rem;
            border: 1px solid #ced4da;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 0.25rem rgba(40, 167, 69, 0.25);
        }
        
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            max-width: 400px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            overflow: hidden;
        }
        
        .success-notification {
            background-color: var(--light-green);
            border-left: 5px solid var(--primary-green);
        }
        
        .error-notification {
            background-color: #f8d7da;
            border-left: 5px solid #dc3545;
        }
        
        .progress-bar {
            background-color: var(--primary-green);
            border-radius: 4px;
            transition: width 0.6s ease;
        }
        
        .progress {
            height: 10px;
            border-radius: 5px;
            background-color: #e9ecef;
        }
        
        .select2-container--default .select2-selection--multiple {
            border-radius: 8px !important;
            padding: 0.375rem;
            min-height: calc(1.5em + 0.75rem + 2px);
        }
        
        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background-color: var(--primary-green);
            border-color: var(--dark-green);
            border-radius: 4px;
            padding: 0 8px;
            margin-top: 4px;
        }
        
        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color: white;
            margin-right: 4px;
        }
        
        .form-label {
            font-weight: 500;
            color: #495057;
            margin-bottom: 0.5rem;
        }
        
        .form-text {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        #charCount {
            font-weight: 600;
            color: var(--primary-green);
        }
        
        .info-box {
            background-color: var(--lighter-green);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary-green);
        }
        
        .info-box i {
            color: var(--primary-green);
            margin-right: 0.5rem;
        }
        
        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-green);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #dee2e6;
        }
        
        @media (max-width: 768px) {
            .card-body {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
<?php require_once 'nav.php'?>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-send-fill me-2"></i>
                        <h5 class="mb-0">Send Bulk SMS To Parents</h5>
                    </div>
                    <div class="card-body">
                        <div class="info-box mb-4">
                            <i class="bi bi-info-circle-fill"></i>
                            <span>Send messages to parents efficiently. Select recipients, compose your message, and send.</span>
                        </div>
                        
                        <form id="smsForm">
                            <div class="mb-4">
                                <h6 class="section-title">Recipient Selection</h6>
                                <div class="mb-3">
                                    <label for="recipientType" class="form-label">Recipient Group</label>
                                    <select class="form-select" id="recipientType">
                                        <option value="all">All Parents</option>
                                        <option value="class">By Class</option>
                                        <option value="stream">By Class and Stream</option>
                                    </select>
                                </div>
                                
                                <div class="row g-3">
                                    <div class="col-md-6" id="classSelection" style="display: none;">
                                        <label for="classes" class="form-label">Select Class(es)</label>
                                        <select class="form-select" id="classes" multiple>
                                            <!-- Classes will be populated via AJAX -->
                                        </select>
                                        <div class="form-text">Select one or more classes</div>
                                    </div>
                                    
                                    <div class="col-md-6" id="streamSelection" style="display: none;">
                                        <label for="streams" class="form-label">Select Stream(s)</label>
                                        <select class="form-select" id="streams" multiple>
                                            <!-- Streams will be populated via AJAX -->
                                        </select>
                                        <div class="form-text">Select one or more streams</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <h6 class="section-title">Message Content</h6>
                                <div class="mb-3">
                                    <label for="message" class="form-label">Message Text</label>
                                    <textarea class="form-control" id="message" rows="5" required 
                                              placeholder="Type your message here..."></textarea>
                                    <div class="form-text mt-2">
                                        <span id="charCount">0</span>/160 characters (1 SMS) - 
                                        <span id="smsCount">0 SMS</span>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="senderId" class="form-label">Sender ID</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                                        <input type="text" class="form-control" id="senderId" value="SCHOOL PILOT" required>
                                    </div>
                                    <div class="form-text">Maximum 11 characters</div>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 mt-4">
                                <button type="submit" class="btn send-btn btn-lg py-2" id="sendBtn">
                                    <span id="sendBtnText">
                                        <i class="bi bi-send-fill me-2"></i>Send SMS
                                    </span>
                                    <span id="sendBtnLoader" class="spinner-border spinner-border-sm" role="status" aria-hidden="true" style="display: none;"></span>
                                </button>
                            </div>
                            
                            <div class="mt-4" id="progressContainer" style="display: none;">
                                <h6 class="section-title">Sending Progress</h6>
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-muted">Progress:</span>
                                    <span id="progressText" class="fw-bold">0%</span>
                                </div>
                                <div class="progress mb-3" style="height: 12px;">
                                    <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="text-muted" id="recipientCountText">
                                        <i class="bi bi-people-fill me-1"></i>
                                        <span id="recipientCount">0</span> recipients
                                    </div>
                                    <div class="text-muted" id="estimatedCost">
                                        <i class="bi bi-currency-dollar me-1"></i>
                                        Estimated cost: <span id="costValue">0</span>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-clock-history me-2"></i>
                        <h5 class="mb-0">Recent Messages</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Recipients</th>
                                        <th>Message</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>2023-06-15 14:30</td>
                                        <td>All Parents</td>
                                        <td>Parent-teacher meeting scheduled for next Friday...</td>
                                        <td><span class="badge bg-success">Delivered</span></td>
                                    </tr>
                                    <tr>
                                        <td>2023-06-10 09:15</td>
                                        <td>Class 7</td>
                                        <td>Reminder: School trip permission slips due tomorrow</td>
                                        <td><span class="badge bg-success">Delivered</span></td>
                                    </tr>
                                    <tr>
                                        <td>2023-06-05 16:45</td>
                                        <td>Class 9 Stream B</td>
                                        <td>Science project submissions extended to Monday</td>
                                        <td><span class="badge bg-success">Delivered</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification Container -->
    <div id="notificationContainer" class="notification"></div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Initialize Select2
            $('#classes').select2({
                placeholder: "Select classes",
                allowClear: true
            });
            
            $('#streams').select2({
                placeholder: "Select streams",
                allowClear: true
            });
            
            // Load classes and streams
            loadClasses();
            loadStreams();
            
            // Show/hide class and stream selection based on recipient type
            $('#recipientType').change(function() {
                const type = $(this).val();
                
                $('#classSelection').hide();
                $('#streamSelection').hide();
                
                if (type === 'class') {
                    $('#classSelection').show();
                } else if (type === 'stream') {
                    $('#classSelection').show();
                    $('#streamSelection').show();
                }
            });
            
            // Character count for message
            $('#message').on('input', function() {
                const count = $(this).val().length;
                $('#charCount').text(count);
                
                if (count > 160) {
                    $('#charCount').css('color', 'red');
                } else {
                    $('#charCount').css('color', 'inherit');
                }
            });
            
            // Form submission
            $('#smsForm').submit(function(e) {
                e.preventDefault();
                
                const recipientType = $('#recipientType').val();
                const classes = $('#classes').val() || [];
                const streams = $('#streams').val() || [];
                const message = $('#message').val();
                const senderId = $('#senderId').val();
                
                if (!message) {
                    showNotification('error', 'Please enter a message');
                    return;
                }
                
                if (recipientType === 'class' && classes.length === 0) {
                    showNotification('error', 'Please select at least one class');
                    return;
                }
                
                if (recipientType === 'stream' && (classes.length === 0 || streams.length === 0)) {
                    showNotification('error', 'Please select at least one class and stream');
                    return;
                }
                
                // Prepare data
                const data = {
                    recipientType: recipientType,
                    classes: classes,
                    streams: streams,
                    message: message,
                    senderId: senderId
                };
                
                // Show loading state
                $('#sendBtn').prop('disabled', true);
                $('#sendBtnText').text('Sending...');
                $('#sendBtnLoader').show();
                $('#progressContainer').show();
                
                // Start sending process
                sendBulkSMS(data);
            });
            
            // Function to load classes
            function loadClasses() {
                $.ajax({
                    url: 'get_classes.php',
                    type: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('#classes').empty();
                            $.each(response.classes, function(index, className) {
                                $('#classes').append(new Option(className, className));
                            });
                        } else {
                            showNotification('error', 'Failed to load classes: ' + response.message);
                        }
                    },
                    error: function() {
                        showNotification('error', 'Error loading classes');
                    }
                });
            }
            
            // Function to load streams
            function loadStreams() {
                $.ajax({
                    url: 'get_streams.php',
                    type: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('#streams').empty();
                            $.each(response.streams, function(index, streamName) {
                                $('#streams').append(new Option(streamName, streamName));
                            });
                        } else {
                            showNotification('error', 'Failed to load streams: ' + response.message);
                        }
                    },
                    error: function() {
                        showNotification('error', 'Error loading streams');
                    }
                });
            }
            
            // Function to send bulk SMS
            function sendBulkSMS(data) {
                $.ajax({
                    url: 'send_bulk_sms.php',
                    type: 'POST',
                    data: data,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            showNotification('success', 'SMS sent successfully to ' + response.total_recipients + ' parents');
                            
                            // Update progress bar to 100%
                            updateProgress(100, response.total_recipients);
                        } else {
                            showNotification('error', 'Failed to send SMS: ' + response.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        showNotification('error', 'Error: ' + error);
                    },
                    complete: function() {
                        // Reset button state
                        $('#sendBtn').prop('disabled', false);
                        $('#sendBtnText').text('Send SMS');
                        $('#sendBtnLoader').hide();
                    }
                });
            }
            
            // Function to update progress
            function updateProgress(percent, totalRecipients) {
                $('#progressBar').css('width', percent + '%');
                $('#progressText').text(percent + '%');
                $('#recipientCount').text('Sent to ' + Math.round(totalRecipients * percent / 100) + ' of ' + totalRecipients + ' recipients');
            }
            
            // Function to show notification
            function showNotification(type, message) {
                const notification = $('<div>').addClass('alert alert-dismissible fade show mb-2')
                    .addClass(type === 'success' ? 'success-notification' : 'error-notification')
                    .html(message + '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>');
                
                $('#notificationContainer').append(notification);
                
                // Auto-remove after 5 seconds
                setTimeout(function() {
                    notification.alert('close');
                }, 5000);
            }
        });
    </script>
</body>
</html>