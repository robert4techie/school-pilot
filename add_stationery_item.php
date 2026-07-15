<?php
// Start output buffering to prevent headers from being sent prematurely
ob_start();

require_once 'conn.php';
require_once 'auth.php';
require_once 'tracking.php';
$tracker->trackAction("Add Stationery items");

// Create the stationery_items table if it doesn't exist
$sql_create_table = "
CREATE TABLE IF NOT EXISTS stationery_items (
    item_id INT(11) AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL,
    brand VARCHAR(100),
    unit VARCHAR(50),
    current_stock INT(11) NOT NULL,
    min_stock INT(11) NOT NULL,
    unit_price DECIMAL(10, 2),
    supplier VARCHAR(255),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);";
$conn->query($sql_create_table);

// Create the suppliers table with more details if it doesn't exist
$sql_create_suppliers_table = "
CREATE TABLE IF NOT EXISTS suppliers (
    supplier_id INT(11) AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(255) NOT NULL UNIQUE,
    phone_number VARCHAR(20),
    email VARCHAR(255)
);";
$conn->query($sql_create_suppliers_table);

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Clear any previous output before sending JSON header
    ob_clean();
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => '', 'errors' => []];

    // Validation
    $item_name = trim($_POST['item_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $unit = trim($_POST['unit'] ?? '');
    $current_stock = $_POST['current_stock'] ?? '';
    $min_stock = $_POST['min_stock'] ?? '';
    $unit_price = $_POST['unit_price'] ?? '';
    $supplier = trim($_POST['supplier'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $description = trim($_POST['description'] ?? '');

    // Required field validation
    if (empty($item_name)) {
        $response['errors'][] = "Item name is required";
    } elseif (strlen($item_name) < 2) {
        $response['errors'][] = "Item name must be at least 2 characters long";
    } elseif (strlen($item_name) > 255) {
        $response['errors'][] = "Item name must not exceed 255 characters";
    }

    if (empty($category)) {
        $response['errors'][] = "Category is required";
    } elseif (strlen($category) > 100) {
        $response['errors'][] = "Category must not exceed 100 characters";
    }

    if (!empty($brand) && strlen($brand) > 100) {
        $response['errors'][] = "Brand must not exceed 100 characters";
    }

    if (!empty($unit) && strlen($unit) > 50) {
        $response['errors'][] = "Unit must not exceed 50 characters";
    }

    if (empty($current_stock)) {
        $response['errors'][] = "Current stock is required";
    } elseif (!is_numeric($current_stock) || $current_stock < 0) {
        $response['errors'][] = "Current stock must be a non-negative number";
    }

    if (empty($min_stock)) {
        $response['errors'][] = "Minimum stock is required";
    } elseif (!is_numeric($min_stock) || $min_stock < 0) {
        $response['errors'][] = "Minimum stock must be a non-negative number";
    }

    if (!empty($unit_price) && (!is_numeric($unit_price) || $unit_price < 0)) {
        $response['errors'][] = "Unit price must be a positive number";
    }

    // Check for existing supplier and add if new
    if (!empty($supplier) && empty($response['errors'])) {
        $sql_supplier_check = "SELECT supplier_id FROM suppliers WHERE supplier_name = ?";
        $stmt_supplier = $conn->prepare($sql_supplier_check);
        $stmt_supplier->bind_param("s", $supplier);
        $stmt_supplier->execute();
        $result_supplier = $stmt_supplier->get_result();

        if ($result_supplier->num_rows == 0) {
            $sql_insert_supplier = "INSERT INTO suppliers (supplier_name, phone_number, email) VALUES (?, ?, ?)";
            $stmt_insert_supplier = $conn->prepare($sql_insert_supplier);
            $stmt_insert_supplier->bind_param("sss", $supplier, $phone_number, $email);
            $stmt_insert_supplier->execute();
            $stmt_insert_supplier->close();
        }
        $stmt_supplier->close();
    }

    // If no errors, insert into database
    if (empty($response['errors'])) {
        $sql = "INSERT INTO stationery_items (item_name, category, brand, unit, current_stock, min_stock, unit_price, supplier, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("ssssiidss", $item_name, $category, $brand, $unit, $current_stock, $min_stock, $unit_price, $supplier, $description);
            
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = "New stationery item added successfully!";
            } else {
                $response['errors'][] = "Database error: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $response['errors'][] = "Database preparation error: " . $conn->error;
        }
    }
    
    echo json_encode($response);
    exit; // Stop further execution after sending JSON response
}

// If it's a GET request, continue to render the HTML
// Fetch all suppliers and categories for the dropdowns
$suppliers = [];
$sql_fetch_suppliers = "SELECT supplier_name FROM suppliers ORDER BY supplier_name ASC";
$result_suppliers = $conn->query($sql_fetch_suppliers);
if ($result_suppliers->num_rows > 0) {
    while($row = $result_suppliers->fetch_assoc()) {
        $suppliers[] = $row['supplier_name'];
    }
}

$categories = [];
$sql_fetch_categories = "SELECT category_name FROM categories ORDER BY category_name ASC";
$result_categories = $conn->query($sql_fetch_categories);
if ($result_categories->num_rows > 0) {
    while($row = $result_categories->fetch_assoc()) {
        $categories[] = $row['category_name'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Stationery Item | School Pilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1e8449;
            --primary-light: #27ae60;
            --primary-dark: #145a32;
            --accent-color: #2ecc71;
            --error-color: #e74c3c;
            --success-color: #27ae60;
            --background-color: #f8f9fa;
            --card-background: #ffffff;
            --text-color: #2c3e50;
            --border-color: #e0e0e0;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            margin-top: 50px;
            background: var(--card-background);
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: white;
            padding: 25px;
            text-align: center;
        }

        .header h2 {
            font-size: 1.8rem;
            font-weight: 400;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
            font-size: 1rem;
        }

        .form-container {
            padding: 40px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-color);
            display: block;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 12px 16px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background-color: #fafafa;
            width: 100%;
        }

        .form-group select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' class='feather feather-chevron-down'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1.2rem;
            padding-right: 3rem;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            background-color: white;
            box-shadow: 0 0 0 3px rgba(30, 132, 73, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .btn1-container {
            display: flex;
            justify-content: center;
            margin-top: 30px;
        }

        .btn1 {
            padding: 14px 30px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn1-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: white;
            padding: 18px 40px;
            font-size: 1.1rem;
            border-radius: 50px;
            box-shadow: 0 8px 15px rgba(30, 132, 73, 0.2);
        }

        .btn1-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(30, 132, 73, 0.3);
        }

        /* Toast Notifications */
        .toast {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 16px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
            max-width: 400px;
            box-shadow: var(--shadow);
        }

        .toast.success {
            background: linear-gradient(135deg, var(--success-color) 0%, var(--accent-color) 100%);
        }

        .toast.error {
            background: linear-gradient(135deg, var(--error-color) 0%, #c0392b 100%);
        }

        .toast i {
            font-size: 1.2rem;
        }

        .toast-close {
            margin-left: auto;
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 1.2rem;
            opacity: 0.7;
        }

        .toast-close:hover {
            opacity: 1;
        }

        @keyframes slideIn {
            from {
                transform: translate(-50%, 100%);
                opacity: 0;
            }
            to {
                transform: translate(-50%, 0);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            from {
                transform: translate(-50%, 0);
                opacity: 1;
            }
            to {
                transform: translate(-50%, 100%);
                opacity: 0;
            }
        }
        
        /* New loading spinner and success checkmark styles */
        .loader {
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top: 4px solid #fff;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
        }
        
        .checkmark-icon {
            display: none;
            color: white;
            font-size: 1.5rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .form-container {
                padding: 20px;
            }
        }

        /* Input validation styles */
        .form-group input.error,
        .form-group select.error,
        .form-group textarea.error {
            border-color: var(--error-color);
            background-color: #fdf2f2;
        }

        .required {
            color: var(--error-color);
        }
    </style>
</head>
<body>
    <?php require_once 'nav.php';?>
    <div class="container">
        <div class="header">
            <h2>Add Stationery Item</h2>
            <p>Add new items to your stationery inventory</p>
        </div>

        <div class="form-container">
            <form id="addItemForm" method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="item_name">
                            Item Name <span class="required">*</span>
                        </label>
                        <input type="text" id="item_name" name="item_name" required maxlength="255">
                    </div>

                    <div class="form-group">
                        <label for="category">
                            Category <span class="required">*</span>
                        </label>
                        <select id="category" name="category" required>
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $category_name): ?>
                                <option value="<?php echo htmlspecialchars($category_name); ?>">
                                    <?php echo htmlspecialchars($category_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="brand">Brand</label>
                        <input type="text" id="brand" name="brand" maxlength="100">
                    </div>

                    <div class="form-group">
                        <label for="unit">Unit</label>
                        <select id="unit" name="unit" maxlength="50">
                            <option value="">-- Select Unit --</option>
                            <option value="pieces">Pieces</option>
                            <option value="boxes">Boxes</option>
                            <option value="packs">Packs</option>
                            <option value="reams">Reams</option>
                            <option value="sets">Sets</option>
                            <option value="sheets">Sheets</option>
                            <option value="pairs">Pairs</option>
                            <option value="units">Units</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="current_stock">
                            Current Stock <span class="required">*</span>
                        </label>
                        <input type="number" id="current_stock" name="current_stock" required min="0">
                    </div>

                    <div class="form-group">
                        <label for="min_stock">
                            Minimum Stock <span class="required">*</span>
                        </label>
                        <input type="number" id="min_stock" name="min_stock" required min="0">
                    </div>

                    <div class="form-group">
                        <label for="unit_price">Unit Price</label>
                        <input type="number" step="0.01" id="unit_price" name="unit_price" min="0">
                    </div>

                    <div class="form-group">
                        <label for="supplier">Supplier</label>
                        <input list="suppliers" id="supplier" name="supplier" maxlength="255" placeholder="Select or type a new supplier">
                        <datalist id="suppliers">
                            <?php foreach ($suppliers as $supplier_name): ?>
                                <option value="<?php echo htmlspecialchars($supplier_name); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone_number">Supplier Phone Number</label>
                        <input type="tel" id="phone_number" name="phone_number" maxlength="20">
                    </div>

                    <div class="form-group">
                        <label for="email">Supplier Email</label>
                        <input type="email" id="email" name="email" maxlength="255">
                    </div>
                </div>

                <div class="form-group full-width">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" maxlength="1000" placeholder="Enter item description..."></textarea>
                </div>

                <div class="btn1-container">
                    <button type="submit" class="btn1 btn1-primary" id="submitbtn1">
                        <i class="fas fa-plus" id="submitIcon"></i>
                        <span id="buttonText">Add Item</span>
                        <div class="loader" style="display: none;"></div>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Toast notification system
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            const icon = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
            
            toast.innerHTML = `
                <i class="${icon}"></i>
                <span>${message}</span>
                <button class="toast-close" onclick="closeToast(this)">&times;</button>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                if (toast.parentNode) {
                    closeToast(toast.querySelector('.toast-close'));
                }
            }, 5000);
        }

        function closeToast(button) {
            const toast = button.parentNode;
            toast.style.animation = 'slideOut 0.3s ease forwards';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }

        // Asynchronous form submission
        const form = document.getElementById('addItemForm');
        const submitbtn1 = document.getElementById('submitbtn1');
        const submitIcon = document.getElementById('submitIcon');
        const buttonText = document.getElementById('buttonText');
        const loader = document.querySelector('.loader');

        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            // Client-side validation
            const requiredFields = ['item_name', 'category', 'current_stock', 'min_stock'];
            let hasError = false;

            requiredFields.forEach(fieldName => {
                const field = document.getElementById(fieldName);
                const value = field.value.trim();
                if (!value) {
                    field.classList.add('error');
                    hasError = true;
                } else {
                    field.classList.remove('error');
                }
            });

            if (hasError) {
                showToast('Please fill in all required fields', 'error');
                return;
            }

            // Disable button and show loader
            submitbtn1.disabled = true;
            submitbtn1.style.opacity = '0.8';
            submitIcon.style.display = 'none';
            buttonText.textContent = 'Saving...';
            loader.style.display = 'block';

            const formData = new FormData(form);

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }

                const result = await response.json();

                if (result.success) {
                    showToast(result.message, 'success');
                    form.reset(); // Clear the form
                } else {
                    result.errors.forEach(error => showToast(error, 'error'));
                }
            } catch (error) {
                showToast('An unexpected error occurred. Please try again.', 'error');
                console.error('Submission Error:', error);
            } finally {
                // Re-enable button and hide loader
                submitbtn1.disabled = false;
                submitbtn1.style.opacity = '1';
                submitIcon.style.display = 'inline-block';
                buttonText.textContent = 'Add Item';
                loader.style.display = 'none';
            }
        });

        // Remove error class on input
        document.querySelectorAll('input, select, textarea').forEach(field => {
            field.addEventListener('input', function() {
                this.classList.remove('error');
            });
        });

        // Character counter for description
        const description = document.getElementById('description');
        const maxLength = 1000;
        
        description.addEventListener('input', function() {
            const remaining = maxLength - this.value.length;
            let counter = document.getElementById('desc-counter');
            
            if (!counter) {
                counter = document.createElement('small');
                counter.id = 'desc-counter';
                counter.style.color = '#666';
                counter.style.fontSize = '0.85rem';
                this.parentNode.appendChild(counter);
            }
            
            counter.textContent = `${remaining} characters remaining`;
            
            if (remaining < 0) {
                this.style.borderColor = 'var(--error-color)';
            } else {
                this.style.borderColor = '';
            }
        });
    </script>
</body>
</html>