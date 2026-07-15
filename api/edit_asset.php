<?php
require_once '../auth.php';
require_once '../conn.php';
$tracker->trackAction("Edit Assets");


// Helper function to set a toast message in a session variable
function setSessionToast($message, $type)
{
    $_SESSION['toast_message'] = $message;
    $_SESSION['toast_type'] = $type;
}

// Fetch all categories for the dropdown
$categories = [];
$sql_categories = "SELECT category_name FROM asset_categories WHERE status = 'active' ORDER BY category_name ASC";
$result_categories = $conn->query($sql_categories);
if ($result_categories) {
    while ($row = $result_categories->fetch_assoc()) {
        $categories[] = $row['category_name'];
    }
}

// Check if an asset ID is provided in the URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setSessionToast('Invalid asset ID provided.', 'error');
    header('Location: ../view_assets.php');
    exit();
}

$asset_id = (int)$_GET['id'];
$asset = null;

// Fetch the asset's current data from the database
$sql_fetch_asset = "SELECT * FROM assets WHERE id = ?";
$stmt_fetch = $conn->prepare($sql_fetch_asset);
if ($stmt_fetch) {
    $stmt_fetch->bind_param("i", $asset_id);
    $stmt_fetch->execute();
    $result_fetch = $stmt_fetch->get_result();
    if ($result_fetch->num_rows === 1) {
        $asset = $result_fetch->fetch_assoc();
    } else {
        setSessionToast('Asset not found.', 'error');
        header('Location: ../view_assets.php');
        exit();
    }
    $stmt_fetch->close();
} else {
    setSessionToast('Database error: ' . $conn->error, 'error');
    header('Location: ../view_assets.php');
    exit();
}


// Handle form submission for updating the asset
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and sanitize form data
    $asset_name = $conn->real_escape_string($_POST['asset_name']);
    $asset_code = $conn->real_escape_string($_POST['asset_code']);
    $category = $conn->real_escape_string($_POST['category']);
    $quantity = (int)$_POST['quantity'];
    $unit = $conn->real_escape_string($_POST['unit']);
    $status = $conn->real_escape_string($_POST['status']);
    $serial_number = $conn->real_escape_string($_POST['serial_number'] ?? '');
    $model = $conn->real_escape_string($_POST['model'] ?? '');
    $min_stock_level = (int)($_POST['min_stock_level'] ?? 0);
    $description = $conn->real_escape_string($_POST['description'] ?? '');
    $unit_price = (float)$_POST['unit_price'];
    $total_cost = (float)$_POST['total_cost'];
    $purchase_date = $conn->real_escape_string($_POST['purchase_date']);
    $warranty_period = (int)($_POST['warranty_period'] ?? 0);
    $warranty_expiry = $conn->real_escape_string($_POST['warranty_expiry'] ?? '');
    $depreciation_rate = (float)($_POST['depreciation_rate'] ?? 0.00);
    $supplier_name = $conn->real_escape_string($_POST['supplier_name'] ?? '');
    $supplier_contact = $conn->real_escape_string($_POST['supplier_contact'] ?? '');
    $supplier_email = $conn->real_escape_string($_POST['supplier_email'] ?? '');
    $supplier_address = $conn->real_escape_string($_POST['supplier_address'] ?? '');
    $invoice_number = $conn->real_escape_string($_POST['invoice_number'] ?? '');
    $payment_method = $conn->real_escape_string($_POST['payment_method'] ?? '');
    $storage_location = $conn->real_escape_string($_POST['storage_location']);
    $assigned_to = $conn->real_escape_string($_POST['assigned_to'] ?? '');
    $department = $conn->real_escape_string($_POST['department'] ?? '');
    $asset_condition = $conn->real_escape_string($_POST['asset_condition'] ?? '');
    $maintenance_schedule = $conn->real_escape_string($_POST['maintenance_schedule'] ?? '');
    $next_maintenance = $conn->real_escape_string($_POST['next_maintenance'] ?? '');
    $insurance_value = (float)($_POST['insurance_value'] ?? 0.00);
    $disposal_date = $conn->real_escape_string($_POST['disposal_date'] ?? '');
    $barcode = $conn->real_escape_string($_POST['barcode'] ?? '');

    // Get the existing image path
    $asset_image = $asset['asset_image'];

    // Handle new image upload, if any
    if (isset($_FILES['asset_image']) && $_FILES['asset_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_name = uniqid() . '_' . basename($_FILES['asset_image']['name']);
        $file_path = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES['asset_image']['tmp_name'], $file_path)) {
            // Delete the old image if it exists
            if ($asset['asset_image'] && file_exists($asset['asset_image'])) {
                unlink($asset['asset_image']);
            }
            $asset_image = $file_path;
        } else {
            setSessionToast('Error uploading new image.', 'error');
            header('Location: edit_asset.php?id=' . $asset_id);
            exit();
        }
    }

    // Prepare SQL statement for UPDATE
    $sql = "UPDATE assets SET
                asset_name = ?, asset_code = ?, category = ?, quantity = ?, unit = ?, status = ?,
                serial_number = ?, model = ?, min_stock_level = ?, description = ?, unit_price = ?,
                total_cost = ?, purchase_date = ?, warranty_period = ?, warranty_expiry = ?,
                depreciation_rate = ?, supplier_name = ?, supplier_contact = ?, supplier_email = ?,
                supplier_address = ?, invoice_number = ?, payment_method = ?, storage_location = ?,
                assigned_to = ?, department = ?, asset_condition = ?, asset_image = ?,
                maintenance_schedule = ?, next_maintenance = ?, insurance_value = ?,
                disposal_date = ?, barcode = ?
            WHERE id = ?";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        setSessionToast('Error preparing statement: ' . $conn->error, 'error');
        header('Location: edit_asset.php?id=' . $asset_id);
        exit();
    }

    $stmt->bind_param(
        "sssisssisddsisdssssssssssssssdssi",
        $asset_name, $asset_code, $category, $quantity, $unit, $status,
        $serial_number, $model, $min_stock_level, $description, $unit_price,
        $total_cost, $purchase_date, $warranty_period, $warranty_expiry,
        $depreciation_rate, $supplier_name, $supplier_contact, $supplier_email,
        $supplier_address, $invoice_number, $payment_method, $storage_location,
        $assigned_to, $department, $asset_condition, $asset_image,
        $maintenance_schedule, $next_maintenance, $insurance_value,
        $disposal_date, $barcode, $asset_id
    );

    if ($stmt->execute()) {
        setSessionToast('Asset updated successfully!', 'success');
        header('Location: ../view_assets.php');
    } else {
        setSessionToast('Error updating asset: ' . $stmt->error, 'error');
        header('Location: edit_asset.php?id=' . $asset_id);
    }

    $stmt->close();
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Asset - School Pilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* All your existing CSS from add_asset.php can go here */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #e8f5e8 0%, #f0f9f0 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(34, 139, 34, 0.1);
            overflow: hidden;
        }

        .header {
            margin-top: 42px;
            background: linear-gradient(135deg, #228b22 0%, #32cd32 100%);
            color: white;
            padding: 15px;
            text-align: center;
            position: relative;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="2" fill="rgba(255,255,255,0.1)"/><circle cx="80" cy="40" r="1.5" fill="rgba(255,255,255,0.1)"/><circle cx="40" cy="80" r="1" fill="rgba(255,255,255,0.1)"/></svg>');
            pointer-events: none;
        }

        .header h2 {
            font-size: 1.5em;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .header p {
            font-size: 1.1em;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .form-container {
            padding: 40px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
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
            color: #2e7d32;
            margin-bottom: 8px;
            font-size: 0.95em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group label i {
            color: #4caf50;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 12px 16px;
            border: 2px solid #e8f5e8;
            border-radius: 8px;
            font-size: 1em;
            transition: all 0.3s ease;
            background: #fafafa;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #4caf50;
            background: white;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-group select {
            cursor: pointer;
        }

        .file-upload-container {
            position: relative;
            display: inline-block;
            width: 100%;
        }

        .file-upload-input {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .file-upload-label {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            border: 2px dashed #4caf50;
            border-radius: 8px;
            background: #f1f8e9;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #2e7d32;
            font-weight: 500;
        }

        .file-upload-label:hover {
            background: #e8f5e8;
            border-color: #2e7d32;
        }

        .file-upload-label i {
            margin-right: 10px;
            font-size: 1.2em;
        }

        .button-group {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
        }

        .btnn {
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-size: 1em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            min-width: 150px;
            justify-content: center;
        }

        .btnn-primary {
            background: linear-gradient(135deg, #4caf50 0%, #45a049 100%);
            color: white;
        }

        .btnn-primary:hover {
            background: linear-gradient(135deg, #45a049 0%, #3d8b40 100%);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.3);
        }

        .btnn-secondary {
            background: #f5f5f5;
            color: #666;
            border: 2px solid #e0e0e0;
        }

        .btnn-secondary:hover {
            background: #e8e8e8;
            border-color: #d0d0d0;
        }

        .required {
            color: #f44336;
        }

        .form-section {
            background: #f8fcf8;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            border-left: 4px solid #4caf50;
        }

        .form-section h3 {
            color: #2e7d32;
            margin-bottom: 20px;
            font-size: 1.2em;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 8px;
            background: #e8f5e8;
            border-radius: 20px;
            font-size: 0.85em;
            color: #2e7d32;
        }

        .status-indicator.active {
            background: #c8e6c9;
        }

        .status-indicator.inactive {
            background: #ffcdd2;
            color: #c62828;
        }

        /* Toast Notification Styles */
        .toast {
            visibility: hidden;
            min-width: 250px;
            margin-left: -125px;
            background-color: #333;
            color: #fff;
            text-align: center;
            border-radius: 8px;
            padding: 16px;
            position: fixed;
            z-index: 1001;
            right: 20px;
            top: 20px;
            font-size: 17px;
            opacity: 0;
            transition: opacity 0.3s, transform 0.3s;
            transform: translateX(100%);
        }

        .toast.show {
            visibility: visible;
            opacity: 1;
            transform: translateX(0);
        }

        .toast.toast-success {
            background-color: #4CAF50;
        }

        .toast.toast-error {
            background-color: #f44336;
        }

        .current-image-container {
            margin-bottom: 20px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .current-image-container img {
            max-width: 200px;
            height: auto;
            border-radius: 8px;
            border: 2px solid #e8f5e8;
        }

        .current-image-container p {
            font-size: 0.9em;
            color: #666;
            margin-top: 10px;
        }


        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .form-container {
                padding: 20px;
            }

            .button-group {
                flex-direction: column;
                align-items: center;
            }

            .btnn {
                width: 100%;
                max-width: 300px;
            }
        }
    </style>
</head>

<body>
    <?php require_once '../nav.php'; ?>

    <div class="container">
        <div class="header">
            <h2><i class="fas fa-edit"></i> Edit Asset: <?php echo htmlspecialchars($asset['asset_name']); ?></h2>
            <p>Modify asset details and save changes</p>
        </div>

        <div class="form-container">
            <form action="edit_asset.php?id=<?php echo $asset_id; ?>" method="POST" enctype="multipart/form-data">
                <div class="form-section">
                    <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="asset_name">
                                <i class="fas fa-tag"></i>
                                Asset Name <span class="required">*</span>
                            </label>
                            <input type="text" id="asset_name" name="asset_name" required placeholder="Enter asset name" value="<?php echo htmlspecialchars($asset['asset_name']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="asset_code">
                                <i class="fas fa-barcode"></i>
                                Asset Code
                            </label>
                            <input type="text" id="asset_code" name="asset_code" value="<?php echo htmlspecialchars($asset['asset_code']); ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label for="category">
                                <i class="fas fa-folder"></i>
                                Category <span class="required">*</span>
                            </label>
                            <select id="category" name="category" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $category_name) : ?>
                                    <option value="<?php echo htmlspecialchars($category_name); ?>" <?php echo ($asset['category'] === $category_name) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="quantity">
                                <i class="fas fa-calculator"></i>
                                Quantity <span class="required">*</span>
                            </label>
                            <input type="number" id="quantity" name="quantity" required min="1" step="1" placeholder="Enter quantity" value="<?php echo htmlspecialchars($asset['quantity']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="unit">
                                <i class="fas fa-ruler"></i>
                                Unit <span class="required">*</span>
                            </label>
                            <select id="unit" name="unit" required>
                                <option value="">Select Unit</option>
                                <option value="pieces" <?php echo ($asset['unit'] === 'pieces') ? 'selected' : ''; ?>>Pieces</option>
                                <option value="sets" <?php echo ($asset['unit'] === 'sets') ? 'selected' : ''; ?>>Sets</option>
                                <option value="boxes" <?php echo ($asset['unit'] === 'boxes') ? 'selected' : ''; ?>>Boxes</option>
                                <option value="units" <?php echo ($asset['unit'] === 'units') ? 'selected' : ''; ?>>Units</option>
                                <option value="pairs" <?php echo ($asset['unit'] === 'pairs') ? 'selected' : ''; ?>>Pairs</option>
                                <option value="meters" <?php echo ($asset['unit'] === 'meters') ? 'selected' : ''; ?>>Meters</option>
                                <option value="liters" <?php echo ($asset['unit'] === 'liters') ? 'selected' : ''; ?>>Liters</option>
                                <option value="kg" <?php echo ($asset['unit'] === 'kg') ? 'selected' : ''; ?>>Kilograms</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="status">
                                <i class="fas fa-circle"></i>
                                Status
                            </label>
                            <select id="status" name="status">
                                <option value="active" <?php echo ($asset['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($asset['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                <option value="maintenance" <?php echo ($asset['status'] === 'maintenance') ? 'selected' : ''; ?>>Under Maintenance</option>
                                <option value="disposed" <?php echo ($asset['status'] === 'disposed') ? 'selected' : ''; ?>>Disposed</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="serial_number">
                                <i class="fas fa-hashtag"></i>
                                Serial Number
                            </label>
                            <input type="text" id="serial_number" name="serial_number" placeholder="Manufacturer serial number" value="<?php echo htmlspecialchars($asset['serial_number']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="model">
                                <i class="fas fa-cogs"></i>
                                Model/Brand
                            </label>
                            <input type="text" id="model" name="model" placeholder="Brand and model" value="<?php echo htmlspecialchars($asset['model']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="min_stock_level">
                                <i class="fas fa-exclamation-triangle"></i>
                                Minimum Stock Level
                            </label>
                            <input type="number" id="min_stock_level" name="min_stock_level" min="0" step="1" placeholder="Minimum quantity threshold" value="<?php echo htmlspecialchars($asset['min_stock_level']); ?>">
                        </div>

                        <div class="form-group full-width">
                            <label for="description">
                                <i class="fas fa-align-left"></i>
                                Description/Notes
                            </label>
                            <textarea id="description" name="description" rows="3" placeholder="Additional notes about the asset..."><?php echo htmlspecialchars($asset['description']); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-dollar-sign"></i> Financial Information</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="unit_price">
                                <i class="fas fa-money-bill"></i>
                                Unit Price (UGX) <span class="required">*</span>
                            </label>
                            <input type="number" id="unit_price" name="unit_price" step="1" required min="0" placeholder="Enter unit price in UGX" value="<?php echo htmlspecialchars($asset['unit_price']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="total_cost">
                                <i class="fas fa-calculator"></i>
                                Total Cost (UGX) <span class="required">*</span>
                            </label>
                            <input type="number" id="total_cost" name="total_cost" step="1" required min="0" placeholder="Total purchase cost" value="<?php echo htmlspecialchars($asset['total_cost']); ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label for="purchase_date">
                                <i class="fas fa-calendar-alt"></i>
                                Purchase Date <span class="required">*</span>
                            </label>
                            <input type="date" id="purchase_date" name="purchase_date" required value="<?php echo htmlspecialchars($asset['purchase_date']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="warranty_period">
                                <i class="fas fa-shield-alt"></i>
                                Warranty Period (months)
                            </label>
                            <input type="number" id="warranty_period" name="warranty_period" min="0" placeholder="Warranty in months" value="<?php echo htmlspecialchars($asset['warranty_period']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="warranty_expiry">
                                <i class="fas fa-calendar-times"></i>
                                Warranty Expiry Date
                            </label>
                            <input type="date" id="warranty_expiry" name="warranty_expiry" readonly value="<?php echo htmlspecialchars($asset['warranty_expiry']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="depreciation_rate">
                                <i class="fas fa-chart-line"></i>
                                Depreciation Rate (% per year)
                            </label>
                            <input type="number" id="depreciation_rate" name="depreciation_rate" min="0" max="100" step="0.1" placeholder="Annual depreciation rate" value="<?php echo htmlspecialchars($asset['depreciation_rate']); ?>">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-truck"></i> Supplier Information</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="supplier_name">
                                <i class="fas fa-building"></i>
                                Supplier Name
                            </label>
                            <input type="text" id="supplier_name" name="supplier_name" placeholder="Company/Supplier name" value="<?php echo htmlspecialchars($asset['supplier_name']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="supplier_contact">
                                <i class="fas fa-phone"></i>
                                Supplier Contact
                            </label>
                            <input type="tel" id="supplier_contact" name="supplier_contact" placeholder="Phone number" value="<?php echo htmlspecialchars($asset['supplier_contact']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="supplier_email">
                                <i class="fas fa-envelope"></i>
                                Supplier Email
                            </label>
                            <input type="email" id="supplier_email" name="supplier_email" placeholder="Email address" value="<?php echo htmlspecialchars($asset['supplier_email']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="supplier_address">
                                <i class="fas fa-map-marker-alt"></i>
                                Supplier Address
                            </label>
                            <input type="text" id="supplier_address" name="supplier_address" placeholder="Physical address" value="<?php echo htmlspecialchars($asset['supplier_address']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="invoice_number">
                                <i class="fas fa-file-invoice"></i>
                                Invoice Number
                            </label>
                            <input type="text" id="invoice_number" name="invoice_number" placeholder="Purchase invoice number" value="<?php echo htmlspecialchars($asset['invoice_number']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="payment_method">
                                <i class="fas fa-credit-card"></i>
                                Payment Method
                            </label>
                            <select id="payment_method" name="payment_method">
                                <option value="">Select Payment Method</option>
                                <option value="cash" <?php echo ($asset['payment_method'] === 'cash') ? 'selected' : ''; ?>>Cash</option>
                                <option value="bank_transfer" <?php echo ($asset['payment_method'] === 'bank_transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="cheque" <?php echo ($asset['payment_method'] === 'cheque') ? 'selected' : ''; ?>>Cheque</option>
                                <option value="mobile_money" <?php echo ($asset['payment_method'] === 'mobile_money') ? 'selected' : ''; ?>>Mobile Money</option>
                                <option value="credit_card" <?php echo ($asset['payment_method'] === 'credit_card') ? 'selected' : ''; ?>>Credit Card</option>
                                <option value="other" <?php echo ($asset['payment_method'] === 'other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-map-marker-alt"></i> Location & Assignment</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="storage_location">
                                <i class="fas fa-warehouse"></i>
                                Storage Location <span class="required">*</span>
                            </label>
                            <select id="storage_location" name="storage_location" required>
                                <option value="">Select Location</option>
                                <option value="admin_office" <?php echo ($asset['storage_location'] === 'admin_office') ? 'selected' : ''; ?>>Administrative Office</option>
                                <option value="classroom_1" <?php echo ($asset['storage_location'] === 'classroom_1') ? 'selected' : ''; ?>>Classroom 1</option>
                                <option value="classroom_2" <?php echo ($asset['storage_location'] === 'classroom_2') ? 'selected' : ''; ?>>Classroom 2</option>
                                <option value="library" <?php echo ($asset['storage_location'] === 'library') ? 'selected' : ''; ?>>Library</option>
                                <option value="laboratory" <?php echo ($asset['storage_location'] === 'laboratory') ? 'selected' : ''; ?>>Laboratory</option>
                                <option value="staff_room" <?php echo ($asset['storage_location'] === 'staff_room') ? 'selected' : ''; ?>>Staff Room</option>
                                <option value="playground" <?php echo ($asset['storage_location'] === 'playground') ? 'selected' : ''; ?>>Playground</option>
                                <option value="storage_room" <?php echo ($asset['storage_location'] === 'storage_room') ? 'selected' : ''; ?>>Storage Room</option>
                                <option value="warehouse" <?php echo ($asset['storage_location'] === 'warehouse') ? 'selected' : ''; ?>>Warehouse</option>
                                <option value="maintenance_room" <?php echo ($asset['storage_location'] === 'maintenance_room') ? 'selected' : ''; ?>>Maintenance Room</option>
                                <option value="other" <?php echo ($asset['storage_location'] === 'other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="assigned_to">
                                <i class="fas fa-user"></i>
                                Assigned To
                            </label>
                            <input type="text" id="assigned_to" name="assigned_to" placeholder="Staff name or department" value="<?php echo htmlspecialchars($asset['assigned_to']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="department">
                                <i class="fas fa-users"></i>
                                Department
                            </label>
                            <select id="department" name="department">
                                <option value="">Select Department</option>
                                <option value="administration" <?php echo ($asset['department'] === 'administration') ? 'selected' : ''; ?>>Administration</option>
                                <option value="academics" <?php echo ($asset['department'] === 'academics') ? 'selected' : ''; ?>>Academics</option>
                                <option value="sports" <?php echo ($asset['department'] === 'sports') ? 'selected' : ''; ?>>Sports</option>
                                <option value="maintenance" <?php echo ($asset['department'] === 'maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                                <option value="kitchen" <?php echo ($asset['department'] === 'kitchen') ? 'selected' : ''; ?>>Kitchen</option>
                                <option value="security" <?php echo ($asset['department'] === 'security') ? 'selected' : ''; ?>>Security</option>
                                <option value="library" <?php echo ($asset['department'] === 'library') ? 'selected' : ''; ?>>Library</option>
                                <option value="laboratory" <?php echo ($asset['department'] === 'laboratory') ? 'selected' : ''; ?>>Laboratory</option>
                                <option value="other" <?php echo ($asset['department'] === 'other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="asset_condition">
                                <i class="fas fa-star"></i>
                                Asset Condition
                            </label>
                            <select id="asset_condition" name="asset_condition">
                                <option value="">Select Condition</option>
                                <option value="excellent" <?php echo ($asset['asset_condition'] === 'excellent') ? 'selected' : ''; ?>>Excellent</option>
                                <option value="good" <?php echo ($asset['asset_condition'] === 'good') ? 'selected' : ''; ?>>Good</option>
                                <option value="fair" <?php echo ($asset['asset_condition'] === 'fair') ? 'selected' : ''; ?>>Fair</option>
                                <option value="poor" <?php echo ($asset['asset_condition'] === 'poor') ? 'selected' : ''; ?>>Poor</option>
                                <option value="damaged" <?php echo ($asset['asset_condition'] === 'damaged') ? 'selected' : ''; ?>>Damaged</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3><i class="fas fa-camera"></i> Asset Image & Additional Information</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Current Asset Image</label>
                            <div class="current-image-container">
                                <?php if ($asset['asset_image'] && file_exists($asset['asset_image'])) : ?>
                                    <img src="<?php echo htmlspecialchars($asset['asset_image']); ?>" alt="Current Asset Image">
                                    <p>Upload a new image to replace the current one.</p>
                                <?php else : ?>
                                    <p>No image uploaded. Please upload a new one.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="asset_image">
                                <i class="fas fa-image"></i>
                                Upload New Asset Image
                            </label>
                            <div class="file-upload-container">
                                <input type="file" id="asset_image" name="asset_image" accept="image/*" class="file-upload-input">
                                <label for="asset_image" class="file-upload-label">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    Click to upload new image or drag and drop
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="maintenance_schedule">
                                <i class="fas fa-calendar-check"></i>
                                Maintenance Schedule
                            </label>
                            <select id="maintenance_schedule" name="maintenance_schedule">
                                <option value="">Select Schedule</option>
                                <option value="weekly" <?php echo ($asset['maintenance_schedule'] === 'weekly') ? 'selected' : ''; ?>>Weekly</option>
                                <option value="monthly" <?php echo ($asset['maintenance_schedule'] === 'monthly') ? 'selected' : ''; ?>>Monthly</option>
                                <option value="quarterly" <?php echo ($asset['maintenance_schedule'] === 'quarterly') ? 'selected' : ''; ?>>Quarterly</option>
                                <option value="semi_annually" <?php echo ($asset['maintenance_schedule'] === 'semi_annually') ? 'selected' : ''; ?>>Semi-Annually</option>
                                <option value="annually" <?php echo ($asset['maintenance_schedule'] === 'annually') ? 'selected' : ''; ?>>Annually</option>
                                <option value="as_needed" <?php echo ($asset['maintenance_schedule'] === 'as_needed') ? 'selected' : ''; ?>>As Needed</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="next_maintenance">
                                <i class="fas fa-wrench"></i>
                                Next Maintenance Date
                            </label>
                            <input type="date" id="next_maintenance" name="next_maintenance" value="<?php echo htmlspecialchars($asset['next_maintenance']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="insurance_value">
                                <i class="fas fa-shield-alt"></i>
                                Insurance Value (UGX)
                            </label>
                            <input type="number" id="insurance_value" name="insurance_value" min="0" step="1" placeholder="Insurance coverage value" value="<?php echo htmlspecialchars($asset['insurance_value']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="disposal_date">
                                <i class="fas fa-calendar-times"></i>
                                Expected Disposal Date
                            </label>
                            <input type="date" id="disposal_date" name="disposal_date" value="<?php echo htmlspecialchars($asset['disposal_date']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="barcode">
                                <i class="fas fa-barcode"></i>
                                Barcode/QR Code
                            </label>
                            <input type="text" id="barcode" name="barcode" placeholder="Barcode or QR code" value="<?php echo htmlspecialchars($asset['barcode']); ?>">
                        </div>
                    </div>
                </div>

                <div class="button-group">
                    <button type="submit" class="btnn btnn-primary">
                        <i class="fas fa-save"></i>
                        Save Changes
                    </button>
                    <a href="../view_assets.php" class="btnn btnn-secondary">
                        <i class="fas fa-times"></i>
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script>
        // Toast notification function
        function showToast(message, type) {
            const toast = document.getElementById('toast');
            toast.className = `toast show toast-${type}`;
            toast.textContent = message;
            setTimeout(function() {
                toast.className = toast.className.replace('show', '');
            }, 3000);
        }

        // Check for session toast message and display it
        window.addEventListener('load', function() {
            <?php if (isset($_SESSION['toast_message'])) : ?>
                showToast('<?php echo $_SESSION['toast_message']; ?>', '<?php echo $_SESSION['toast_type']; ?>');
                <?php
                unset($_SESSION['toast_message']);
                unset($_SESSION['toast_type']);
                ?>
            <?php endif; ?>
        });

        // Auto-calculate total cost when quantity or unit price changes
        function calculateTotal() {
            const quantity = parseFloat(document.getElementById('quantity').value) || 0;
            const unitPrice = parseFloat(document.getElementById('unit_price').value) || 0;
            const totalCost = quantity * unitPrice;
            document.getElementById('total_cost').value = totalCost;
        }

        // Auto-calculate warranty expiry date
        function calculateWarrantyExpiry() {
            const purchaseDate = document.getElementById('purchase_date').value;
            const warrantyPeriod = parseInt(document.getElementById('warranty_period').value) || 0;

            if (purchaseDate && warrantyPeriod > 0) {
                const purchaseDateTime = new Date(purchaseDate);
                const expiryDate = new Date(purchaseDateTime.setMonth(purchaseDateTime.getMonth() + warrantyPeriod));
                document.getElementById('warranty_expiry').value = expiryDate.toISOString().split('T')[0];
            } else {
                document.getElementById('warranty_expiry').value = '';
            }
        }

        // Event listeners
        document.getElementById('quantity').addEventListener('input', calculateTotal);
        document.getElementById('unit_price').addEventListener('input', calculateTotal);
        document.getElementById('purchase_date').addEventListener('change', calculateWarrantyExpiry);
        document.getElementById('warranty_period').addEventListener('input', calculateWarrantyExpiry);

        // File upload preview
        document.getElementById('asset_image').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const label = document.querySelector('.file-upload-label');

            if (file) {
                label.innerHTML = `<i class="fas fa-check-circle"></i> ${file.name}`;
                label.style.color = '#2e7d32';
                label.style.borderColor = '#2e7d32';
            }
        });

        // Initial calculation on page load
        document.addEventListener('DOMContentLoaded', function() {
            calculateTotal();
            calculateWarrantyExpiry();
        });
    </script>
</body>

</html>