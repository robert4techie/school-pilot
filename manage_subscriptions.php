<?php
require_once 'conn.php';
require_once 'auth.php';

// Check if user is developer or has permission
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['developer'])) {
    header('Location: dashboard.php');
    exit();
}

$message = '';
$message_type = '';
$new_subscription_id = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_subscription'])) {
        $school_name = trim($_POST['school_name']);
        $school_domain = trim($_POST['school_domain']);
        $amount_paid = (float)$_POST['amount_paid'];
        $currency = $_POST['currency'];
        $term = $_POST['term']; // Can now be 'year' or numeric term
        $year = (int)$_POST['year'];
        $days = (int)$_POST['days'];
        $payment_method = $_POST['payment_method'];
        $payment_reference = $_POST['payment_reference'];
        
        // Calculate dates
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d', strtotime("+$days days"));
        
        // Insert subscription
        $sql = "INSERT INTO school_subscriptions (school_name, school_domain, amount_paid, payment_currency, subscription_term, subscription_year, subscription_days, subscription_start_date, subscription_end_date, payment_method, payment_reference, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        $created_by = $_SESSION['user_name'];
        $stmt->bind_param("ssdssiisssss", $school_name, $school_domain, $amount_paid, $currency, $term, $year, $days, $start_date, $end_date, $payment_method, $payment_reference, $created_by);
        
        if ($stmt->execute()) {
            $message = "Subscription added successfully!";
            $message_type = "success";
            $new_subscription_id = $stmt->insert_id;
        } else {
            $message = "Error adding subscription: " . $conn->error;
            $message_type = "error";
        }
        $stmt->close();
    }
    
    if (isset($_POST['update_status'])) {
        $subscription_id = (int)$_POST['subscription_id'];
        $new_status = $_POST['status'];
        
        $sql = "UPDATE school_subscriptions SET status = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $new_status, $subscription_id);
        
        if ($stmt->execute()) {
            $message = "Status updated successfully!";
            $message_type = "success";
        } else {
            $message = "Error updating status: " . $conn->error;
            $message_type = "error";
        }
        $stmt->close();
    }
    
    if (isset($_POST['extend_subscription'])) {
        $subscription_id = (int)$_POST['subscription_id'];
        $additional_days = (int)$_POST['additional_days'];
        
        $sql = "UPDATE school_subscriptions SET subscription_end_date = DATE_ADD(subscription_end_date, INTERVAL ? DAY), subscription_days = subscription_days + ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $additional_days, $additional_days, $subscription_id);
        
        if ($stmt->execute()) {
            $message = "Subscription extended successfully!";
            $message_type = "success";
        } else {
            $message = "Error extending subscription: " . $conn->error;
            $message_type = "error";
        }
        $stmt->close();
    }
}

// Get all subscriptions with computed status
$subscriptions_query = "SELECT *, 
                       DATEDIFF(subscription_end_date, CURDATE()) as days_remaining,
                       CASE 
                           WHEN subscription_end_date < CURDATE() THEN 'expired'
                           WHEN DATEDIFF(subscription_end_date, CURDATE()) <= 15 THEN 'warning'
                           ELSE 'active'
                       END as computed_status
                       FROM school_subscriptions 
                       ORDER BY subscription_end_date DESC";
$subscriptions_result = $conn->query($subscriptions_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subscriptions - SchoolPilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #10b981;
            --primary-light: #34d399;
            --primary-dark: #059669;
            --success-color: #22c55e;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --background-primary: #f8fafc;
            --background-secondary: #ffffff;
            --border-color: #e2e8f0;
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --border-radius: 12px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--background-primary);
            color: var(--text-primary);
            line-height: 1.6;
        }

        .container {
            max-width: 100%;
            margin: 80px auto 40px;
            padding: 24px;
        }

        .page-header {
            background: var(--background-secondary);
            padding: 32px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            margin-bottom: 32px;
            border-left: 4px solid var(--primary-color);
        }

        .page-header h1 {
            font-size: 32px;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .page-header p {
            color: var(--text-secondary);
            font-size: 16px;
        }

        .form-card {
            background: var(--background-secondary);
            padding: 32px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            margin-bottom: 32px;
        }

        .form-card h2 {
            color: var(--text-primary);
            margin-bottom: 24px;
            font-size: 24px;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 12px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-group input,
        .form-group select {
            padding: 12px 16px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: var(--background-primary);
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }

        .btn2 {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn2-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn2-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn2-success {
            background: var(--success-color);
            color: white;
        }

        .btn2-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn2-danger {
            background: var(--error-color);
            color: white;
        }

        .btn2-sm {
            padding: 8px 12px;
            font-size: 14px;
        }

        .table-container {
            background: var(--background-secondary);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }

        .table-header {
            padding: 24px 32px;
            border-bottom: 2px solid var(--border-color);
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            color: white;
        }

        .table-header h2 {
            margin: 0;
            font-size: 24px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        th {
            background: var(--background-primary);
            font-weight: 600;
            color: var(--text-primary);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        tr:hover {
            background: var(--background-primary);
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-active {
            background: rgba(34, 197, 94, 0.1);
            color: var(--success-color);
        }

        .status-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }

        .status-expired {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error-color);
        }

        .status-suspended {
            background: rgba(107, 114, 128, 0.1);
            color: var(--text-secondary);
        }

        .notification {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            border-left: 4px solid;
            font-weight: 500;
        }

        .notification.success {
            background: rgba(34, 197, 94, 0.1);
            border-color: var(--success-color);
            color: var(--success-color);
        }

        .notification.error {
            background: rgba(239, 68, 68, 0.1);
            border-color: var(--error-color);
            color: var(--error-color);
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .modal-content {
            background: var(--background-secondary);
            margin: 5% auto;
            padding: 32px;
            border-radius: var(--border-radius);
            max-width: 500px;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--border-color);
        }

        .close {
            font-size: 24px;
            cursor: pointer;
            color: var(--text-secondary);
        }

        .close:hover {
            color: var(--error-color);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: var(--background-secondary);
            padding: 24px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            text-align: center;
            border-top: 4px solid var(--primary-color);
        }

        .stat-card h3 {
            font-size: 32px;
            color: var(--primary-color);
            margin-bottom: 8px;
        }

        .stat-card p {
            color: var(--text-secondary);
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .container {
                padding: 16px;
                margin-top: 60px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .actions {
                flex-direction: column;
            }

            th, td {
                padding: 12px 8px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <?php require_once 'nav.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-credit-card"></i> Subscription Management</h1>
            <p>Manage school subscriptions, payments, and renewal dates</p>
        </div>

        <?php if ($message): ?>
            <div class="notification <?php echo $message_type; ?>">
                <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <?php
        $stats_query = "SELECT 
            COUNT(*) as total_subscriptions,
            SUM(CASE WHEN subscription_end_date >= CURDATE() THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN subscription_end_date < CURDATE() THEN 1 ELSE 0 END) as expired_count,
            SUM(CASE WHEN DATEDIFF(subscription_end_date, CURDATE()) BETWEEN 0 AND 15 THEN 1 ELSE 0 END) as warning_count,
            SUM(amount_paid) as total_revenue
            FROM school_subscriptions";
        $stats_result = $conn->query($stats_query);
        $stats = $stats_result->fetch_assoc();
        ?>

        <div class="stats-grid">
            <div class="stat-card">
                <h3><?php echo number_format($stats['total_subscriptions']); ?></h3>
                <p>Total Schools</p>
            </div>
            <div class="stat-card">
                <h3><?php echo number_format($stats['active_count']); ?></h3>
                <p>Active Subscriptions</p>
            </div>
            <div class="stat-card">
                <h3><?php echo number_format($stats['expired_count']); ?></h3>
                <p>Expired Subscriptions</p>
            </div>
            <div class="stat-card">
                <h3><?php echo number_format($stats['warning_count']); ?></h3>
                <p>Expiring Soon</p>
            </div>
            <div class="stat-card">
                <h3><?php echo number_format($stats['total_revenue']); ?> UGX</h3>
                <p>Total Revenue</p>
            </div>
        </div>

        <div class="form-card">
            <h2><i class="fas fa-plus-circle"></i> Add New Subscription</h2>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="school_name">School Name *</label>
                        <input type="text" id="school_name" name="school_name" required>
                    </div>
                    <div class="form-group">
                        <label for="school_domain">School Domain *</label>
                        <input type="text" id="school_domain" name="school_domain" placeholder="school.schoolpilot.com" required>
                    </div>
                    <div class="form-group">
                        <label for="amount_paid">Amount Paid *</label>
                        <input type="number" id="amount_paid" name="amount_paid" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label for="currency">Currency</label>
                        <select id="currency" name="currency">
                            <option value="UGX">UGX - Ugandan Shilling</option>
                            <option value="USD">USD - US Dollar</option>
                            <option value="EUR">EUR - Euro</option>
                            <option value="GBP">GBP - British Pound</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="term">Subscription Term *</label>
                        <select id="term" name="term" required onchange="updateDaysField()">
                            <option value="">Select Term</option>
                            <option value="1">Term 1</option>
                            <option value="2">Term 2</option>
                            <option value="3">Term 3</option>
                            <option value="year">Full Year</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="year">Year *</label>
                        <select id="year" name="year" required>
                            <option value="">Select Year</option>
                            <?php for ($y = 2024; $y <= 2030; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="days">Subscription Days *</label>
                        <input type="number" id="days" name="days" min="1" max="365" required>
                    </div>
                    <div class="form-group">
                        <label for="payment_method">Payment Method</label>
                        <select id="payment_method" name="payment_method">
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Mobile Money">Mobile Money</option>
                            <option value="Credit Card">Credit Card</option>
                            <option value="Cash">Cash</option>
                            <option value="Cheque">Cheque</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="payment_reference">Payment Reference</label>
                        <input type="text" id="payment_reference" name="payment_reference" placeholder="Transaction ID or Reference">
                    </div>
                </div>
                <button type="submit" name="add_subscription" class="btn2 btn2-primary">
                    <i class="fas fa-plus"></i> Add Subscription
                </button>
            </form>
        </div>

        <div class="table-container">
            <div class="table-header">
                <h2><i class="fas fa-list"></i> All Subscriptions</h2>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>School Details</th>
                            <th>Payment Info</th>
                            <th>Subscription Period</th>
                            <th>Status</th>
                            <th>Days Remaining</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $subscriptions_result->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($row['school_name']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($row['school_domain']); ?></small>
                            </td>
                            <td>
                                <strong><?php echo number_format($row['amount_paid'], 2); ?> <?php echo $row['payment_currency']; ?></strong><br>
                                <small class="text-muted"><?php echo $row['payment_method']; ?>
                                <?php if ($row['payment_reference']): ?>
                                    | <?php echo $row['payment_reference']; ?>
                                <?php endif; ?>
                                </small>
                            </td>
                            <td>
                                <?php 
                                $termDisplay = $row['subscription_term'] === 'year' ? 'Full Year' : 'Term ' . $row['subscription_term'];
                                echo $termDisplay . ' - ' . $row['subscription_year'];
                                ?><br>
                                <small class="text-muted">
                                    <?php echo date('M j, Y', strtotime($row['subscription_start_date'])); ?> - 
                                    <?php echo date('M j, Y', strtotime($row['subscription_end_date'])); ?>
                                    (<?php echo $row['subscription_days']; ?> days)
                                </small>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $row['computed_status']; ?>">
                                    <?php echo ucfirst($row['computed_status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($row['days_remaining'] < 0): ?>
                                    <span class="text-danger">Expired <?php echo abs($row['days_remaining']); ?> days ago</span>
                                <?php elseif ($row['days_remaining'] == 0): ?>
                                    <span class="text-warning">Expires today</span>
                                <?php else: ?>
                                    <span class="<?php echo $row['days_remaining'] <= 15 ? 'text-warning' : 'text-success'; ?>">
                                        <?php echo $row['days_remaining']; ?> days left
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="subscription_id" value="<?php echo $row['id']; ?>">
                                        <select name="status" class="btn2 btn2-sm" style="padding: 4px 8px;">
                                            <option value="active" <?php echo $row['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="suspended" <?php echo $row['status'] == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                            <option value="expired" <?php echo $row['status'] == 'expired' ? 'selected' : ''; ?>>Expired</option>
                                        </select>
                                        <button type="submit" name="update_status" class="btn2 btn2-primary btn2-sm">Update</button>
                                    </form>
                                    
                                    <button onclick="openExtendModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['school_name']); ?>')" 
                                            class="btn2 btn2-success btn2-sm">
                                        <i class="fas fa-plus-circle"></i> Extend
                                    </button>
                                    
                                    <a href="receipt.php?id=<?php echo $row['id']; ?>" target="_blank" class="btn2 btn2-sm btn2-warning">
                                        <i class="fas fa-receipt"></i> Receipt
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="extendModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Extend Subscription</h3>
                <span class="close" onclick="closeExtendModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" id="extend_subscription_id" name="subscription_id">
                <div class="form-group">
                    <label>School: <span id="extend_school_name"></span></label>
                </div>
                <div class="form-group">
                    <label for="additional_days">Additional Days *</label>
                    <input type="number" id="additional_days" name="additional_days" min="1" max="365" required>
                </div>
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="closeExtendModal()" class="btn2 btn2-secondary">Cancel</button>
                    <button type="submit" name="extend_subscription" class="btn2 btn2-success">
                        <i class="fas fa-plus-circle"></i> Extend Subscription
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    /**
     * Updates the days field based on selected term.
     */
    function updateDaysField() {
        const termSelect = document.getElementById('term');
        const daysInput = document.getElementById('days');
        
        if (termSelect.value === 'year') {
            daysInput.value = 365;
        } else if (termSelect.value === '1' || termSelect.value === '2' || termSelect.value === '3') {
            daysInput.value = 90; // Default term days
        }
    }

    /**
     * Opens the "Extend Subscription" modal.
     * @param {number} id The ID of the subscription to be extended.
     * @param {string} schoolName The name of the school.
     */
    function openExtendModal(id, schoolName) {
        document.getElementById('extend_subscription_id').value = id;
        document.getElementById('extend_school_name').textContent = schoolName;
        document.getElementById('extendModal').style.display = 'block';
    }

    /**
     * Closes the "Extend Subscription" modal.
     */
    function closeExtendModal() {
        document.getElementById('extendModal').style.display = 'none';
    }

    // Attach event listeners to close the modal when clicking outside of it.
    window.onload = function() {
        const extendModal = document.getElementById('extendModal');

        window.onclick = function(event) {
            if (event.target == extendModal) {
                extendModal.style.display = 'none';
            }
        };
    };
</script>
</body>
</html>