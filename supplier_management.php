<?php
require_once 'auth.php';
require_once 'conn.php';

// Create suppliers table if it doesn't exist
$sql_create_table = "
CREATE TABLE IF NOT EXISTS suppliers (
    supplier_id INT(11) AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(255) NOT NULL UNIQUE,
    phone_number VARCHAR(20),
    email VARCHAR(255)
);";
$conn->query($sql_create_table);

$errors = [];
$success_message = '';

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['add_supplier'])) {
        $supplier_name = trim($_POST['supplier_name'] ?? '');
        $phone_number = trim($_POST['phone_number'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (empty($supplier_name)) {
            $errors[] = "Supplier name is required.";
        } else {
            $sql_check = "SELECT * FROM suppliers WHERE supplier_name = ?";
            $stmt = $conn->prepare($sql_check);
            $stmt->bind_param("s", $supplier_name);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $errors[] = "Supplier already exists.";
            } else {
                $sql_insert = "INSERT INTO suppliers (supplier_name, phone_number, email) VALUES (?, ?, ?)";
                $stmt_insert = $conn->prepare($sql_insert);
                $stmt_insert->bind_param("sss", $supplier_name, $phone_number, $email);
                if ($stmt_insert->execute()) {
                    $success_message = "Supplier added successfully!";
                } else {
                    $errors[] = "Database error: " . $stmt_insert->error;
                }
            }
            $stmt->close();
        }
    } elseif (isset($_POST['edit_supplier'])) {
        $supplier_id = intval($_POST['supplier_id']);
        $supplier_name = trim($_POST['supplier_name'] ?? '');
        $phone_number = trim($_POST['phone_number'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (empty($supplier_name)) {
            $errors[] = "Supplier name is required.";
        } else {
            $sql_update = "UPDATE suppliers SET supplier_name = ?, phone_number = ?, email = ? WHERE supplier_id = ?";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("sssi", $supplier_name, $phone_number, $email, $supplier_id);
            if ($stmt_update->execute()) {
                $success_message = "Supplier updated successfully!";
            } else {
                $errors[] = "Database error: " . $stmt_update->error;
            }
            $stmt_update->close();
        }
    }
}

// Handle deletion
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $supplier_id = intval($_GET['id']);
    $sql_delete = "DELETE FROM suppliers WHERE supplier_id = ?";
    $stmt_delete = $conn->prepare($sql_delete);
    $stmt_delete->bind_param("i", $supplier_id);
    if ($stmt_delete->execute()) {
        $success_message = "Supplier deleted successfully!";
    } else {
        $errors[] = "Error deleting supplier: " . $stmt_delete->error;
    }
    $stmt_delete->close();
}

// Fetch all suppliers for the table
$sql_fetch = "SELECT * FROM suppliers ORDER BY supplier_name ASC";
$result_fetch = $conn->query($sql_fetch);
$suppliers = [];
if ($result_fetch->num_rows > 0) {
    while($row = $result_fetch->fetch_assoc()) {
        $suppliers[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Suppliers | School Pilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1e8449;
            --primary-light: #27ae60;
            --primary-dark: #145a32;
            --accent-color: #2ecc71;
            --error-color: #e74c3c;
            --success-color: #27ae60;
            --info-color: #3498db;
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
            background: linear-gradient(135deg, var(--background-color) 0%, #e9ecef 100%);
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
            margin-top: 50px;
            background: var(--card-background);
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .header {
            background:  #27ae60;
            color: white;
            padding: 25px;
            text-align: center;
        }

        .header h2 {
            font-size: 2rem;
            font-weight: 400;
            margin-bottom: 5px;
        }
        
        .header p {
            font-size: 1rem;
            opacity: 0.9;
        }

        .content-section {
            padding: 40px;
        }

        .btnn-add-container {
            text-align: center;
            margin-bottom: 30px;
        }

        .btnn {
            padding: 14px 30px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btnn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: white;
        }
        
        .btnn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(30, 132, 73, 0.3);
        }

        .btnn-info {
            background: linear-gradient(135deg, var(--info-color) 0%, #2980b9 100%);
            color: white;
        }
        
        .btnn-info:hover {
            background: linear-gradient(135deg, #2980b9 0%, var(--info-color) 100%);
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background-color: var(--card-background);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        thead tr {
            background: var(--primary-light);
            color: white;
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.3s ease;
        }

        th:first-child, td:first-child {
            padding-left: 20px;
        }

        th:last-child, td:last-child {
            padding-right: 20px;
        }
        
        td:last-child {
            width: 1%;
            white-space: nowrap;
        }

        tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        tbody tr:hover {
            background-color: #f0f0f0;
        }

        .actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .action-btnn {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--primary-color);
            font-size: 1.1rem;
            transition: color 0.3s ease, transform 0.2s ease;
        }

        .action-btnn:hover {
            color: var(--primary-dark);
            transform: scale(1.1);
        }

        .action-btnn.delete-btnn {
            color: var(--error-color);
        }

        .action-btnn.delete-btnn:hover {
            color: #c0392b;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.4);
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.3s forwards;
            overflow-y: auto;
        }

        .modal-content {
            background-color: var(--card-background);
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            width: 90%;
            max-width: 600px;
            position: relative;
            animation: slideIn 0.3s forwards;
            margin: 20px auto;
        }
        
        .close-btnn {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 10px;
            right: 20px;
            cursor: pointer;
        }
        
        .close-btnn:hover,
        .close-btnn:focus {
            color: var(--error-color);
            text-decoration: none;
        }

        .modal-header {
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 15px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            color: var(--primary-dark);
        }
        
        .modal-header h3 {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-color);
        }
        
        .form-group input {
            padding: 12px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .btnn-container {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btnn-secondary {
            background: #6c757d;
            color: white;
        }

        .btnn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        /* Custom Confirmation Modal */
        .confirm-modal-content {
            text-align: center;
        }
        
        .confirm-modal-content i {
            font-size: 3rem;
            color: var(--error-color);
            margin-bottom: 20px;
        }

        .confirm-modal-content h3 {
            margin-bottom: 10px;
        }

        .confirm-modal-content p {
            margin-bottom: 25px;
        }

        .confirm-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
        }
        
        .btnn-confirm-delete {
            background-color: var(--error-color);
            color: white;
        }
        
        .btnn-confirm-delete:hover {
            background-color: #c0392b;
        }

        .btnn-cancel {
            background-color: #95a5a6;
            color: white;
        }

        .btnn-cancel:hover {
            background-color: #7f8c8d;
        }
        
        /* Toast Notifications */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 1001;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
            max-width: 400px;
            box-shadow: var(--shadow);
        }
        
        .toast.success { background: linear-gradient(135deg, var(--success-color) 0%, var(--accent-color) 100%); }
        .toast.error { background: linear-gradient(135deg, var(--error-color) 0%, #c0392b 100%); }
        .toast i { font-size: 1.2rem; }
        .toast-close { margin-left: auto; background: none; border: none; color: white; cursor: pointer; font-size: 1.2rem; opacity: 0.7; }
        .toast-close:hover { opacity: 1; }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }

        @media (max-width: 768px) {
            .container { padding: 10px; margin: 10px; }
            .content-section { padding: 15px; }
            th, td { padding: 10px; font-size: 0.9rem; }
            .form-grid { grid-template-columns: 1fr; }
        }
        
        .text-center { text-align: center; }
    </style>
</head>
<body>
    <?php require_once 'nav.php';?>
    <div class="container">
        <div class="header">
            <h2><i class="fas fa-truck"></i> Supplier Management</h2>
            <p>Add, edit, and view details of your stationery suppliers.</p>
        </div>

        <div class="content-section">
            <div class="btnn-add-container">
                <button id="showAddModalbtnn" class="btnn btnn-info">
                    <i class="fas fa-plus-circle"></i> Add New Supplier
                </button>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Supplier Name</th>
                        <th>Phone Number</th>
                        <th>Email</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($suppliers)): ?>
                        <?php foreach ($suppliers as $supplier): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($supplier['supplier_name']); ?></td>
                                <td><?php echo htmlspecialchars($supplier['phone_number']); ?></td>
                                <td><?php echo htmlspecialchars($supplier['email']); ?></td>
                                <td class="actions">
                                    <button class="action-btnn edit-btnn" 
                                            data-id="<?php echo $supplier['supplier_id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($supplier['supplier_name']); ?>"
                                            data-phone="<?php echo htmlspecialchars($supplier['phone_number']); ?>"
                                            data-email="<?php echo htmlspecialchars($supplier['email']); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="action-btnn delete-btnn" data-id="<?php echo $supplier['supplier_id']; ?>">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center">No suppliers found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div id="addModal" class="modal">
        <div class="modal-content">
            <span class="close-btnn">&times;</span>
            <div class="modal-header">
                <i class="fas fa-plus"></i>
                <h3>Add New Supplier</h3>
            </div>
            <div class="modal-body">
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">
                    <input type="hidden" name="add_supplier" value="1">
                    <div class="form-group">
                        <label for="add_supplier_name">Supplier Name</label>
                        <input type="text" id="add_supplier_name" name="supplier_name" required>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="add_phone_number">Phone Number</label>
                            <input type="tel" id="add_phone_number" name="phone_number">
                        </div>
                        <div class="form-group">
                            <label for="add_email">Email</label>
                            <input type="email" id="add_email" name="email">
                        </div>
                    </div>
                    <div class="btnn-container">
                        <button type="submit" class="btnn btnn-primary">
                            <i class="fas fa-plus"></i> Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close-btnn">&times;</span>
            <div class="modal-header">
                <i class="fas fa-edit"></i>
                <h3>Edit Supplier</h3>
            </div>
            <div class="modal-body">
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">
                    <input type="hidden" name="edit_supplier" value="1">
                    <input type="hidden" id="edit_supplier_id" name="supplier_id">
                    <div class="form-group">
                        <label for="edit_supplier_name">Supplier Name</label>
                        <input type="text" id="edit_supplier_name" name="supplier_name" required>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_phone_number">Phone Number</label>
                            <input type="tel" id="edit_phone_number" name="phone_number">
                        </div>
                        <div class="form-group">
                            <label for="edit_email">Email</label>
                            <input type="email" id="edit_email" name="email">
                        </div>
                    </div>
                    <div class="btnn-container">
                        <button type="submit" class="btnn btnn-primary">
                            <i class="fas fa-save"></i> Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="deleteConfirmModal" class="modal">
        <div class="modal-content confirm-modal-content">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>Confirm Deletion</h3>
            <p>Are you sure you want to delete this supplier? This action cannot be undone.</p>
            <div class="confirm-buttons">
                <button id="btnnCancelDelete" class="btnn btnn-cancel">Cancel</button>
                <button id="btnnConfirmDelete" class="btnn btnn-confirm-delete">Delete</button>
            </div>
        </div>
    </div>

    <script>
        const addModal = document.getElementById('addModal');
        const editModal = document.getElementById('editModal');
        const deleteConfirmModal = document.getElementById('deleteConfirmModal');
        let supplierIdToDelete = null;

        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            const icon = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
            toast.innerHTML = `<i class="${icon}"></i><span>${message}</span><button class="toast-close" onclick="closeToast(this)">&times;</button>`;
            document.body.appendChild(toast);
            setTimeout(() => { if (toast.parentNode) { closeToast(toast.querySelector('.toast-close')); } }, 5000);
        }

        function closeToast(button) {
            const toast = button.parentNode;
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => { if (toast.parentNode) { toast.parentNode.removeChild(toast); } }, 300);
        }

        document.getElementById('showAddModalbtnn').onclick = function() {
            addModal.style.display = "flex";
        }
        
        document.body.addEventListener('click', function(e) {
            if (e.target.closest('.edit-btnn')) {
                const button = e.target.closest('.edit-btnn');
                document.getElementById('edit_supplier_id').value = button.dataset.id;
                document.getElementById('edit_supplier_name').value = button.dataset.name;
                document.getElementById('edit_phone_number').value = button.dataset.phone;
                document.getElementById('edit_email').value = button.dataset.email;
                editModal.style.display = "flex";
            } else if (e.target.closest('.delete-btnn')) {
                supplierIdToDelete = e.target.closest('.delete-btnn').dataset.id;
                deleteConfirmModal.style.display = "flex";
            } else if (e.target.matches('.close-btnn')) {
                e.target.closest('.modal').style.display = 'none';
            } else if (e.target.matches('.modal')) {
                event.target.style.display = "none";
            }
        });

        document.getElementById('btnnCancelDelete').onclick = function() {
            deleteConfirmModal.style.display = "none";
            supplierIdToDelete = null;
        }

        document.getElementById('btnnConfirmDelete').onclick = function() {
            if (supplierIdToDelete) {
                window.location.href = `?action=delete&id=${supplierIdToDelete}`;
            }
        }
        
        <?php if (!empty($success_message)): ?>
            showToast('<?php echo addslashes($success_message); ?>', 'success');
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $error): ?>
                showToast('<?php echo addslashes($error); ?>', 'error');
            <?php endforeach; ?>
        <?php endif; ?>
    </script>
</body>
</html>