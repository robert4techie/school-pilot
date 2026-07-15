<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'fee_functions.php';

// Check if ID is provided
if (!isset($_GET['id'])) {
    $_SESSION['error_message'] = 'Fee structure ID not provided';
    header('Location: fees_structures.php');
    exit();
}

$id = filter_var($_GET['id'], FILTER_VALIDATE_INT);

if (!$id || $id <= 0) {
    $_SESSION['error_message'] = 'Invalid fee structure ID';
    header('Location: fees_structures.php');
    exit();
}

// Get fee structure data
$fee_structure = get_fee_structure_by_id($conn, $id);

if (!$fee_structure) {
    $_SESSION['error_message'] = 'Fee structure not found';
    header('Location: fees_structures.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_fee'])) {
    $class = sanitize_input($_POST['class']);
    $term = sanitize_input($_POST['term']);
    $year = sanitize_input($_POST['year']);
    $amount = sanitize_input($_POST['amount']);
    
    $result = update_fee_structure($conn, $id, $class, $term, $year, $amount);
    
    if ($result['success']) {
        $_SESSION['success_message'] = $result['message'];
        header('Location: fees_structures.php');
        exit();
    } else {
        $_SESSION['error_message'] = $result['message'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Fee Structure - School Fees Management</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <header class="header">
            <h1>Edit Fee Structure</h1>
            <p>Modify the selected fee structure</p>
        </header>

        <!-- Notifications -->
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="notification error" id="notification">
                <span class="notification-icon">✗</span>
                <span class="notification-text"><?php echo $_SESSION['error_message']; ?></span>
                <button class="notification-close" onclick="closeNotification()">&times;</button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <div class="main-content" style="grid-template-columns: 1fr; max-width: 600px; margin: 0 auto;">
            <!-- Edit Fee Structure Form -->
            <div class="form-section">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                    <h2>Edit Fee Structure</h2>
                    <a href="fees_structures.php" class="btn" style="background-color: var(--text-light); color: white; text-decoration: none; padding: 8px 16px; font-size: 0.9rem;">
                        ← Back to List
                    </a>
                </div>
                
                <form id="feeForm" method="POST" class="fee-form">
                    <div class="form-group">
                        <label for="class">Class <span class="required">*</span></label>
                        <select name="class" id="class" required>
                            <option value="">Select Class</option>
                            <option value="Senior 1" <?php echo ($fee_structure['class_name'] === 'Senior 1') ? 'selected' : ''; ?>>Senior 1</option>
                            <option value="Senior 2" <?php echo ($fee_structure['class_name'] === 'Senior 2') ? 'selected' : ''; ?>>Senior 2</option>
                            <option value="Senior 3" <?php echo ($fee_structure['class_name'] === 'Senior 3') ? 'selected' : ''; ?>>Senior 3</option>
                            <option value="Senior 4" <?php echo ($fee_structure['class_name'] === 'Senior 4') ? 'selected' : ''; ?>>Senior 4</option>
                            <option value="Senior 5" <?php echo ($fee_structure['class_name'] === 'Senior 5') ? 'selected' : ''; ?>>Senior 5</option>
                            <option value="Senior 6" <?php echo ($fee_structure['class_name'] === 'Senior 6') ? 'selected' : ''; ?>>Senior 6</option>
                        </select>
                        <span class="error-message" id="class-error"></span>
                    </div>

                    <div class="form-group">
                        <label for="term">Term <span class="required">*</span></label>
                        <select name="term" id="term" required>
                            <option value="">Select Term</option>
                            <option value="1" <?php echo ($fee_structure['term'] == '1') ? 'selected' : ''; ?>>Term 1</option>
                            <option value="2" <?php echo ($fee_structure['term'] == '2') ? 'selected' : ''; ?>>Term 2</option>
                            <option value="3" <?php echo ($fee_structure['term'] == '3') ? 'selected' : ''; ?>>Term 3</option>
                        </select>
                        <span class="error-message" id="term-error"></span>
                    </div>

                    <div class="form-group">
                        <label for="year">Year <span class="required">*</span></label>
                        <input type="number" name="year" id="year" min="2020" max="2030" 
                               value="<?php echo htmlspecialchars($fee_structure['year']); ?>" 
                               placeholder="e.g., 2024" required>
                        <span class="error-message" id="year-error"></span>
                    </div>

                    <div class="form-group">
                        <label for="amount">Amount (UGX) <span class="required">*</span></label>
                        <input type="number" name="amount" id="amount" min="0" step="0.01" 
                               value="<?php echo htmlspecialchars($fee_structure['amount']); ?>" 
                               placeholder="e.g., 500000" required>
                        <span class="error-message" id="amount-error"></span>
                    </div>

                    <div style="display: flex; gap: 15px; margin-top: 30px;">
                        <button type="submit" name="update_fee" class="btn btn-primary" style="flex: 1;">
                            <span class="btn-icon">✓</span>
                            Update Fee Structure
                        </button>
                        
                        <button type="button" onclick="resetForm()" class="btn" 
                                style="background-color: var(--text-light); color: white;">
                            Reset
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
</body>
</html>