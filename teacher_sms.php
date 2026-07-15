<?php
require_once 'auth.php'; 
require_once 'conn.php'; 
require_once 'nav.php'; 

// SMS API Configuration - Replace with your SMS provider credentials
define('SMS_API_URL', 'https://your-sms-provider.com/api/send');
define('SMS_API_KEY', 'your_api_key');
define('SMS_SENDER_ID', 'YourCompany');

class SMSStaffManager {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    /**
     * Get all staff phone numbers and names
     */
    public function getAllStaffContacts() {
        $sql = "SELECT id, first_name, last_name, phone_number, designation, department 
                FROM staff 
                WHERE phone_number IS NOT NULL 
                AND phone_number != '' 
                AND Status = 'active'
                ORDER BY first_name, last_name";
        
        $result = $this->conn->query($sql);
        $contacts = [];
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $contacts[] = [
                    'id' => $row['id'],
                    'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                    'phone' => $this->formatPhoneNumber($row['phone_number']),
                    'designation' => $row['designation'],
                    'department' => $row['department']
                ];
            }
        }
        
        return $contacts;
    }
    
    /**
     * Format phone number for SMS API
     */
    private function formatPhoneNumber($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Add country code if not present (assuming Uganda +256)
        if (strlen($phone) == 9 && substr($phone, 0, 1) == '7') {
            $phone = '256' . $phone;
        } elseif (strlen($phone) == 10 && substr($phone, 0, 2) == '07') {
            $phone = '256' . substr($phone, 1);
        }
        
        return $phone;
    }
    
    /**
     * Send SMS to single recipient
     */
    private function sendSingleSMS($phone, $message) {
        $data = [
            'api_key' => SMS_API_KEY,
            'sender_id' => SMS_SENDER_ID,
            'phone' => $phone,
            'message' => $message
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, SMS_API_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'success' => $httpCode == 200,
            'response' => $response,
            'http_code' => $httpCode
        ];
    }
    
    /**
     * Send SMS to all staff
     */
    public function sendBulkSMS($message, $selectedStaff = null) {
        $contacts = $this->getAllStaffContacts();
        $results = [
            'total' => 0,
            'sent' => 0,
            'failed' => 0,
            'details' => []
        ];
        
        foreach ($contacts as $contact) {
            // If specific staff selected, only send to those
            if ($selectedStaff && !in_array($contact['id'], $selectedStaff)) {
                continue;
            }
            
            $results['total']++;
            
            $smsResult = $this->sendSingleSMS($contact['phone'], $message);
            
            if ($smsResult['success']) {
                $results['sent']++;
                $status = 'sent';
            } else {
                $results['failed']++;
                $status = 'failed';
            }
            
            $results['details'][] = [
                'name' => $contact['name'],
                'phone' => $contact['phone'],
                'status' => $status,
                'response' => $smsResult['response']
            ];
            
            // Log SMS attempt
            $this->logSMSAttempt($contact['id'], $message, $status);
            
            // Small delay to prevent API rate limiting
            usleep(500000); // 0.5 second delay
        }
        
        return $results;
    }
    
    /**
     * Log SMS attempts to database
     */
    private function logSMSAttempt($staffId, $message, $status) {
        $sql = "INSERT INTO sms_log (staff_id, message, status, sent_at) VALUES (?, ?, ?, NOW())";
        $stmt = $this->conn->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("iss", $staffId, $message, $status);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    /**
     * Get SMS templates
     */
    public function getSMSTemplates() {
        return [
            'meeting' => "Dear {name}, You have a meeting scheduled. Please confirm your attendance. Thank you.",
            'announcement' => "Important Announcement: {message}. For more information, contact HR department.",
            'reminder' => "Reminder: {message}. Thank you for your attention.",
            'emergency' => "URGENT: {message}. Please respond immediately.",
            'custom' => ""
        ];
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
     $smsManager = new SMSStaffManager($conn);
    
    switch ($_POST['action']) {
        case 'get_staff':
             $contacts = $smsManager->getAllStaffContacts();
            echo json_encode(['success' => true, 'data' => $contacts]);
            
            // Demo data for testing
            echo json_encode([
                'success' => true,
                'data' => [
                    ['id' => 1, 'name' => 'John Doe', 'phone' => '256701234567', 'designation' => 'Manager', 'department' => 'IT'],
                    ['id' => 2, 'name' => 'Jane Smith', 'phone' => '256702345678', 'designation' => 'Developer', 'department' => 'IT'],
                    ['id' => 3, 'name' => 'Bob Johnson', 'phone' => '256703456789', 'designation' => 'Analyst', 'department' => 'Finance']
                ]
            ]);
            break;
            
        case 'send_sms':
            $message = trim($_POST['message'] ?? '');
            $selectedStaff = $_POST['selected_staff'] ?? null;
            
            if (empty($message)) {
                echo json_encode(['success' => false, 'message' => 'Message cannot be empty']);
                exit;
            }
            
            if (strlen($message) > 160) {
                echo json_encode(['success' => false, 'message' => 'Message too long. Maximum 160 characters allowed.']);
                exit;
            }
            
            $results = $smsManager->sendBulkSMS($message, $selectedStaff);
             echo json_encode(['success' => true, 'data' => $results]);
            
            // Demo response
            echo json_encode([
                'success' => true,
                'data' => [
                    'total' => 3,
                    'sent' => 2,
                    'failed' => 1,
                    'details' => [
                        ['name' => 'John Doe', 'phone' => '256701234567', 'status' => 'sent'],
                        ['name' => 'Jane Smith', 'phone' => '256702345678', 'status' => 'sent'],
                        ['name' => 'Bob Johnson', 'phone' => '256703456789', 'status' => 'failed']
                    ]
                ]
            ]);
            break;
    }
    exit;
}
 $smsManager = new SMSStaffManager($conn);
 $templates = $smsManager->getSMSTemplates();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff SMS Management</title>
    
</head>
<body>
    <div class="container">
        <header class="header">
            <h1>📱 Staff SMS Management</h1>
            <p>Send SMS messages to your staff members efficiently and professionally</p>
        </header>

        <div id="alert-container"></div>

        <div class="main-content">
            <div class="sms-form-section">
                <h2 class="section-title">Compose Message</h2>
                
                <form id="sms-form">
                    <div class="form-group">
                        <label class="form-label" for="message-template">Quick Templates</label>
                        <select class="form-select" id="message-template">
                            <option value="">Select a template...</option>
                            <option value="meeting">Meeting Notification</option>
                            <option value="announcement">General Announcement</option>
                            <option value="reminder">Reminder</option>
                            <option value="emergency">Emergency Message</option>
                            <option value="custom">Custom Message</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="sms-message">Message Content *</label>
                        <textarea 
                            class="form-textarea" 
                            id="sms-message" 
                            placeholder="Type your message here..."
                            maxlength="160"
                            required
                        ></textarea>
                        <div class="char-counter" id="char-counter">0/160 characters</div>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary" id="send-btn">
                            <span>📤</span>
                            Send SMS to Selected Staff
                        </button>
                    </div>
                </form>
            </div>

            <div class="staff-selection">
                <div class="staff-header">
                    <h3 class="section-title">📋 Select Recipients</h3>
                    <span class="staff-counter" id="staff-counter">0 selected</span>
                </div>

                <div class="select-all-container">
                    <div class="checkbox-group">
                        <input type="checkbox" id="select-all" />
                        <label for="select-all">Select All Staff Members</label>
                    </div>
                </div>

                <div class="staff-list" id="staff-list">
                    <div class="loading">
                        <div class="spinner"></div>
                        Loading staff members...
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Results Modal -->
    <div class="modal" id="results-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">SMS Sending Results</h3>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <div id="results-content"></div>
        </div>
    </div>

    <script>
        class SMSManager {
            constructor() {
                this.staff = [];
                this.selectedStaff = new Set();
                this.templates = {
                    meeting: "Dear {name}, You have a meeting scheduled. Please confirm your attendance. Thank you.",
                    announcement: "Important Announcement: Please check your email for detailed information. Contact HR for questions.",
                    reminder: "Reminder: Please submit your monthly reports by end of day. Thank you.",
                    emergency: "URGENT: Please report to your supervisor immediately. This is time-sensitive.",
                    custom: ""
                };
                
                this.init();
            }

            init() {
                this.loadStaff();
                this.bindEvents();
                this.updateCharCounter();
            }

            bindEvents() {
                // Template selection
                document.getElementById('message-template').addEventListener('change', (e) => {
                    const template = this.templates[e.target.value] || '';
                    document.getElementById('sms-message').value = template;
                    this.updateCharCounter();
                });

                // Character counter
                document.getElementById('sms-message').addEventListener('input', () => {
                    this.updateCharCounter();
                });

                // Select all checkbox
                document.getElementById('select-all').addEventListener('change', (e) => {
                    this.toggleSelectAll(e.target.checked);
                });

                // Form submission
                document.getElementById('sms-form').addEventListener('submit', (e) => {
                    e.preventDefault();
                    this.sendSMS();
                });
            }

            async loadStaff() {
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=get_staff'
                    });

                    const result = await response.json();
                    
                    if (result.success) {
                        this.staff = result.data;
                        this.renderStaffList();
                    } else {
                        this.showAlert('Error loading staff members', 'error');
                    }
                } catch (error) {
                    console.error('Error loading staff:', error);
                    this.showAlert('Failed to load staff members', 'error');
                }
            }

            renderStaffList() {
                const container = document.getElementById('staff-list');
                
                if (this.staff.length === 0) {
                    container.innerHTML = '<div class="loading">No staff members found</div>';
                    return;
                }

                const html = this.staff.map(member => `
                    <div class="staff-item">
                        <div class="staff-checkbox">
                            <input 
                                type="checkbox" 
                                id="staff-${member.id}" 
                                value="${member.id}"
                                onchange="smsManager.toggleStaffSelection(${member.id}, this.checked)"
                            />
                        </div>
                        <div class="staff-info">
                            <div class="staff-name">${this.escapeHtml(member.name)}</div>
                            <div class="staff-details">
                                ${this.escapeHtml(member.designation)} • ${this.escapeHtml(member.department)} • ${member.phone}
                            </div>
                        </div>
                    </div>
                `).join('');

                container.innerHTML = html;
                this.updateStaffCounter();
            }

            toggleStaffSelection(staffId, isSelected) {
                if (isSelected) {
                    this.selectedStaff.add(staffId);
                } else {
                    this.selectedStaff.delete(staffId);
                }
                
                this.updateStaffCounter();
                this.updateSelectAllCheckbox();
            }

            toggleSelectAll(selectAll) {
                this.selectedStaff.clear();
                
                const checkboxes = document.querySelectorAll('#staff-list input[type="checkbox"]');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = selectAll;
                    if (selectAll) {
                        this.selectedStaff.add(parseInt(checkbox.value));
                    }
                });
                
                this.updateStaffCounter();
            }

            updateSelectAllCheckbox() {
                const selectAllCheckbox = document.getElementById('select-all');
                const totalStaff = this.staff.length;
                const selectedCount = this.selectedStaff.size;
                
                selectAllCheckbox.checked = selectedCount === totalStaff && totalStaff > 0;
                selectAllCheckbox.indeterminate = selectedCount > 0 && selectedCount < totalStaff;
            }

            updateStaffCounter() {
                const counter = document.getElementById('staff-counter');
                const count = this.selectedStaff.size;
                counter.textContent = `${count} selected`;
            }

            updateCharCounter() {
                const textarea = document.getElementById('sms-message');
                const counter = document.getElementById('char-counter');
                const length = textarea.value.length;
                
                counter.textContent = `${length}/160 characters`;
                
                // Update counter color based on length
                counter.className = 'char-counter';
                if (length > 140) {
                    counter.classList.add('danger');
                } else if (length > 120) {
                    counter.classList.add('warning');
                }
            }

            async sendSMS() {
                const message = document.getElementById('sms-message').value.trim();
                const sendBtn = document.getElementById('send-btn');
                
                // Validation
                if (!message) {
                    this.showAlert('Please enter a message', 'error');
                    return;
                }
                
                if (this.selectedStaff.size === 0) {
                    this.showAlert('Please select at least one staff member', 'error');
                    return;
                }

                if (message.length > 160) {
                    this.showAlert('Message is too long. Maximum 160 characters allowed.', 'error');
                    return;
                }

               // Disable send button
                sendBtn.disabled = true;
                sendBtn.innerHTML = '<span>⏳</span> Sending...';

                try {
                    const selectedStaffArray = Array.from(this.selectedStaff);
                    
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'send_sms',
                            message: message,
                            selected_staff: JSON.stringify(selectedStaffArray)
                        })
                    });

                    const result = await response.json();
                    
                    if (result.success) {
                        this.showAlert(`SMS sent successfully! ${result.data.sent}/${result.data.total} messages delivered.`, 'success');
                        this.showResults(result.data);
                        
                        // Clear form
                        document.getElementById('sms-message').value = '';
                        document.getElementById('message-template').value = '';
                        this.updateCharCounter();
                    } else {
                        this.showAlert(result.message || 'Failed to send SMS', 'error');
                    }
                } catch (error) {
                    console.error('Error sending SMS:', error);
                    this.showAlert('Network error occurred while sending SMS', 'error');
                } finally {
                    // Re-enable send button
                    sendBtn.disabled = false;
                    sendBtn.innerHTML = '<span>📤</span> Send SMS to Selected Staff';
                }
            }

            showResults(data) {
                const modal = document.getElementById('results-modal');
                const content = document.getElementById('results-content');
                
                let html = `
                    <div class="alert alert-success" style="margin-bottom: 20px;">
                        <strong>Summary:</strong> ${data.sent} of ${data.total} messages sent successfully
                    </div>
                    
                    <h4 style="margin-bottom: 15px; color: var(--primary-green);">Detailed Results:</h4>
                `;
                
                data.details.forEach(detail => {
                    html += `
                        <div class="result-item">
                            <div>
                                <div style="font-weight: 500;">${this.escapeHtml(detail.name)}</div>
                                <div style="font-size: 0.85rem; color: var(--text-light);">${detail.phone}</div>
                            </div>
                            <span class="result-status ${detail.status}">${detail.status.toUpperCase()}</span>
                        </div>
                    `;
                });
                
                content.innerHTML = html;
                modal.classList.add('show');
            }

            showAlert(message, type = 'info') {
                const container = document.getElementById('alert-container');
                const alertId = 'alert-' + Date.now();
                
                const alertHtml = `
                    <div class="alert alert-${type}" id="${alertId}">
                        ${this.escapeHtml(message)}
                    </div>
                `;
                
                container.insertAdjacentHTML('beforeend', alertHtml);
                
                // Auto remove after 5 seconds
                setTimeout(() => {
                    const alertElement = document.getElementById(alertId);
                    if (alertElement) {
                        alertElement.remove();
                    }
                }, 5000);
            }

            escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
        }

        // Modal functions
        function closeModal() {
            document.getElementById('results-modal').classList.remove('show');
        }

        // Close modal when clicking outside
        document.getElementById('results-modal').addEventListener('click', (e) => {
            if (e.target.id === 'results-modal') {
                closeModal();
            }
        });

        // Initialize SMS Manager when page loads
        let smsManager;
        document.addEventListener('DOMContentLoaded', () => {
            smsManager = new SMSManager();
        });

        // Close modal with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    </script>
</body>
</html>