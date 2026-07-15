<?php
// Start output buffering to prevent headers from being sent prematurely
ob_start();
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("stationery categories");

// Create a new 'categories' table if it doesn't exist
$sql_create_table = "
CREATE TABLE IF NOT EXISTS categories (
    category_id INT(11) AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE
);";
$conn->query($sql_create_table);

// Handle AJAX form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    ob_clean(); // Clear any previous output
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => '', 'errors' => []];

    if (isset($_POST['add_category'])) {
        $category_name = trim($_POST['category_name'] ?? '');

        if (empty($category_name)) {
            $response['errors'][] = "Category name is required.";
        } else {
            $sql_check = "SELECT * FROM categories WHERE category_name = ?";
            $stmt = $conn->prepare($sql_check);
            $stmt->bind_param("s", $category_name);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $response['errors'][] = "Category already exists.";
            } else {
                $sql_insert = "INSERT INTO categories (category_name) VALUES (?)";
                $stmt_insert = $conn->prepare($sql_insert);
                $stmt_insert->bind_param("s", $category_name);
                if ($stmt_insert->execute()) {
                    $response['success'] = true;
                    $response['message'] = "Category added successfully!";
                } else {
                    $response['errors'][] = "Database error: " . $stmt_insert->error;
                }
            }
            $stmt->close();
        }
    } elseif (isset($_POST['edit_category'])) {
        $category_id = intval($_POST['category_id']);
        $category_name = trim($_POST['category_name'] ?? '');

        if (empty($category_name)) {
            $response['errors'][] = "Category name is required.";
        } else {
            $sql_update = "UPDATE categories SET category_name = ? WHERE category_id = ?";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("si", $category_name, $category_id);
            if ($stmt_update->execute()) {
                $response['success'] = true;
                $response['message'] = "Category updated successfully!";
            } else {
                $response['errors'][] = "Database error: " . $stmt_update->error;
            }
            $stmt_update->close();
        }
    }
    echo json_encode($response);
    exit;
}

// Handle AJAX deletion
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    ob_clean();
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    $category_id = intval($_GET['id']);
    
    $sql_delete = "DELETE FROM categories WHERE category_id = ?";
    $stmt_delete = $conn->prepare($sql_delete);
    $stmt_delete->bind_param("i", $category_id);
    if ($stmt_delete->execute()) {
        $response['success'] = true;
        $response['message'] = "Category deleted successfully!";
    } else {
        $response['message'] = "Error deleting category: " . $stmt_delete->error;
    }
    $stmt_delete->close();
    
    echo json_encode($response);
    exit;
}

// Handle AJAX search
if (isset($_GET['search_query'])) {
    ob_clean();
    header('Content-Type: application/json');
    $search_query = "%" . trim($_GET['search_query']) . "%";
    
    $sql_fetch = "SELECT * FROM categories WHERE category_name LIKE ? ORDER BY category_name ASC";
    $stmt_fetch = $conn->prepare($sql_fetch);
    $stmt_fetch->bind_param("s", $search_query);
    $stmt_fetch->execute();
    $result_fetch = $stmt_fetch->get_result();

    $categories = [];
    if ($result_fetch->num_rows > 0) {
        while($row = $result_fetch->fetch_assoc()) {
            $categories[] = $row;
        }
    }
    echo json_encode($categories);
    exit;
}

// Fetch all categories for the initial page load
$sql_fetch = "SELECT * FROM categories ORDER BY category_name ASC";
$result_fetch = $conn->query($sql_fetch);
$categories = [];
if ($result_fetch->num_rows > 0) {
    while($row = $result_fetch->fetch_assoc()) {
        $categories[] = $row;
    }
}
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Categories | School Pilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <style>
        :root {
            --primary-color: #1e8449;
            --primary-light: #27ae60;
            --primary-dark: #145a32;
            --accent-color: #2ecc71;
            --error-color: #e74c3c;
            --success-color: #27ae60;
            --warning-color: #f39c12;
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
            padding: 15px;
            text-align: center;
        }

        .header h2 {
            font-size: 1.5rem;
            font-weight: 400;
            margin-bottom: 5px;
        }
        
        .header p {
            font-size: 1rem;
            opacity: 0.9;
        }
        
        .controls-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 20px 40px; /* Adjusted padding */
        }

        .search-container {
            flex-grow: 1;
            max-width: 300px;
        }
        
        @media (min-width: 768px) {
            .controls-container {
                flex-wrap: nowrap;
            }
        }
        
        .add-button-container {
            flex-shrink: 0;
        }

        .export-buttons {
            display: flex;
            gap: 10px;
            flex-shrink: 0;
        }
        
        .search-container input {
            width: 100%;
            padding: 12px 20px;
            border: 2px solid var(--border-color);
            border-radius: 50px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background-color: #fafafa;
        }

        .search-container input:focus {
            outline: none;
            border-color: var(--primary-color);
            background-color: white;
            box-shadow: 0 0 0 3px rgba(30, 132, 73, 0.1);
        }
        
        .export-btn1 {
            padding: 10px 20px;
            border: none;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            color: white;
        }

        .export-btn1.excel {
            background-color: #21a366;
        }

        .export-btn1.excel:hover {
            background-color: #1a7e4b;
        }

        .export-btn1.pdf {
            background-color: #e74c3c;
        }

        .export-btn1.pdf:hover {
            background-color: #c0392b;
        }

        .content-section {
            padding: 40px;
        }
        
        .content-header {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 30px;
        }
        
        .btn1 {
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
        
        .btn1-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: white;
        }
        
        .btn1-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(30, 132, 73, 0.3);
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

        .action-btn1 {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--primary-color);
            font-size: 1.1rem;
            transition: color 0.3s ease, transform 0.2s ease;
        }

        .action-btn1:hover {
            color: var(--primary-dark);
            transform: scale(1.1);
        }

        .action-btn1.delete-btn1 {
            color: var(--error-color);
        }

        .action-btn1.delete-btn1:hover {
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
            max-width: 500px;
            position: relative;
            animation: slideIn 0.3s forwards;
            margin: 20px auto;
        }
        
        .close-btn1 {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 10px;
            right: 20px;
            cursor: pointer;
        }
        
        .close-btn1:hover,
        .close-btn1:focus {
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
        
        .form-group {
            display: flex;
            flex-direction: column;
            margin-bottom: 20px;
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
        
        .btn1-confirm-delete {
            background-color: var(--error-color);
            color: white;
        }
        
        .btn1-confirm-delete:hover {
            background-color: #c0392b;
        }

        .btn1-cancel {
            background-color: #95a5a6;
            color: white;
        }

        .btn1-cancel:hover {
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
            .controls-container {
                flex-direction: column;
                align-items: stretch;
            }
            .add-button-container {
                width: 100%;
            }
            .search-container {
                max-width: none;
            }
        }
        
        .text-center { text-align: center; }
        
        .loader {
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top: 4px solid #fff;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php require_once 'nav.php';?>
    <div class="container">
        <div class="header">
            <h2><i class="fas fa-layer-group"></i> Manage Stationery Categories</h2>
            
        </div>
        
        <div class="controls-container">
            <div class="search-container">
                <input type="text" id="searchInput" placeholder="Search categories..." />
            </div>
            <div class="add-button-container">
                <button id="showAddModalbtn1" class="btn1 btn1-primary">
                    <i class="fas fa-plus-circle"></i> Add New Category
                </button>
            </div>
            <div class="export-buttons">
                <button class="export-btn1 excel" id="exportExcelbtn1"><i class="fas fa-file-excel"></i> Excel</button>
                <button class="export-btn1 pdf" id="exportPdfbtn1"><i class="fas fa-file-pdf"></i> PDF</button>
            </div>
        </div>

        <div class="content-section">
            <table id="categoriesTable">
                <thead>
                    <tr>
                        <th>Category Name</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="categoriesTableBody">
                    <?php if (!empty($categories)): ?>
                        <?php foreach ($categories as $category): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($category['category_name']); ?></td>
                                <td class="actions">
                                    <button class="action-btn1 edit-btn1" data-id="<?php echo $category['category_id']; ?>" data-name="<?php echo htmlspecialchars($category['category_name']); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="action-btn1 delete-btn1" data-id="<?php echo $category['category_id']; ?>">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="2" class="text-center">No categories found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div id="addModal" class="modal">
        <div class="modal-content">
            <span class="close-btn1">&times;</span>
            <div class="modal-header">
                <i class="fas fa-plus"></i>
                <h3>Add New Category</h3>
            </div>
            <div class="modal-body">
                <form id="addForm" method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">
                    <input type="hidden" name="add_category" value="1">
                    <div class="form-group">
                        <label for="add_category_name">Category Name</label>
                        <input type="text" id="add_category_name" name="category_name" required>
                    </div>
                    <div class="btn1-container">
                        <button type="submit" class="btn1 btn1-primary" id="addSavebtn1">
                            <i class="fas fa-plus" id="addIcon"></i>
                            <span id="addbtn1Text">Save</span>
                            <div class="loader" style="display: none;"></div>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close-btn1">&times;</span>
            <div class="modal-header">
                <i class="fas fa-edit"></i>
                <h3>Edit Category</h3>
            </div>
            <div class="modal-body">
                <form id="editForm" method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">
                    <input type="hidden" name="edit_category" value="1">
                    <input type="hidden" id="edit_category_id" name="category_id">
                    <div class="form-group">
                        <label for="edit_category_name">Category Name</label>
                        <input type="text" id="edit_category_name" name="category_name" required>
                    </div>
                    <div class="btn1-container">
                        <button type="submit" class="btn1 btn1-primary" id="editSavebtn1">
                            <i class="fas fa-save" id="editIcon"></i>
                            <span id="editbtn1Text">Update</span>
                            <div class="loader" style="display: none;"></div>
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
            <p>Are you sure you want to delete this category? This action cannot be undone.</p>
            <div class="confirm-buttons">
                <button id="btn1CancelDelete" class="btn1 btn1-cancel">Cancel</button>
                <button id="btn1ConfirmDelete" class="btn1 btn1-confirm-delete">Delete</button>
            </div>
        </div>
    </div>

    <script>
        const addModal = document.getElementById('addModal');
        const editModal = document.getElementById('editModal');
        const deleteConfirmModal = document.getElementById('deleteConfirmModal');
        const addForm = document.getElementById('addForm');
        const editForm = document.getElementById('editForm');
        const categoriesTableBody = document.getElementById('categoriesTableBody');
        const searchInput = document.getElementById('searchInput');
        const exportExcelbtn1 = document.getElementById('exportExcelbtn1');
        const exportPdfbtn1 = document.getElementById('exportPdfbtn1');

        let categoryIdToDelete = null;

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
            toast.style.animation = 'slideOut 0.3s ease forwards';
            setTimeout(() => { if (toast.parentNode) { toast.parentNode.removeChild(toast); } }, 300);
        }
        
        function renderTable(data) {
            categoriesTableBody.innerHTML = '';
            if (data.length > 0) {
                data.forEach(category => {
                    const row = `
                        <tr>
                            <td>${category.category_name}</td>
                            <td class="actions">
                                <button class="action-btn1 edit-btn1" data-id="${category.category_id}" data-name="${category.category_name}">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="action-btn1 delete-btn1" data-id="${category.category_id}">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </td>
                        </tr>
                    `;
                    categoriesTableBody.innerHTML += row;
                });
            } else {
                categoriesTableBody.innerHTML = `<tr><td colspan="2" class="text-center">No categories found.</td></tr>`;
            }
        }

        document.getElementById('showAddModalbtn1').onclick = function() {
            addModal.style.display = "flex";
        }
        
        document.body.addEventListener('click', function(e) {
            if (e.target.closest('.edit-btn1')) {
                const button = e.target.closest('.edit-btn1');
                document.getElementById('edit_category_id').value = button.dataset.id;
                document.getElementById('edit_category_name').value = button.dataset.name;
                editModal.style.display = "flex";
            } else if (e.target.closest('.delete-btn1')) {
                categoryIdToDelete = e.target.closest('.delete-btn1').dataset.id;
                deleteConfirmModal.style.display = "flex";
            } else if (e.target.matches('.close-btn1')) {
                e.target.closest('.modal').style.display = 'none';
            } else if (e.target.matches('.modal')) {
                e.target.style.display = "none";
            }
        });

        document.getElementById('btn1CancelDelete').onclick = function() {
            deleteConfirmModal.style.display = "none";
            categoryIdToDelete = null;
        }

        document.getElementById('btn1ConfirmDelete').onclick = async function() {
            if (categoryIdToDelete) {
                const response = await fetch(`?action=delete&id=${categoryIdToDelete}`);
                const result = await response.json();
                
                if (result.success) {
                    showToast(result.message, 'success');
                    deleteConfirmModal.style.display = 'none';
                    fetchCategories();
                } else {
                    showToast(result.message, 'error');
                }
            }
        }
        
        // AJAX form submissions
        addForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn1 = document.getElementById('addSavebtn1');
            const icon = document.getElementById('addIcon');
            const text = document.getElementById('addbtn1Text');
            const loader = btn1.querySelector('.loader');

            btn1.disabled = true;
            icon.style.display = 'none';
            text.textContent = 'Saving...';
            if (loader) loader.style.display = 'block';

            const formData = new FormData(this);
            const response = await fetch(this.action, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            btn1.disabled = false;
            icon.style.display = 'inline-block';
            text.textContent = 'Save';
            if (loader) loader.style.display = 'none';
            
            if (result.success) {
                showToast(result.message, 'success');
                addModal.style.display = 'none';
                addForm.reset();
                fetchCategories();
            } else {
                result.errors.forEach(error => showToast(error, 'error'));
            }
        });

        editForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btn1 = document.getElementById('editSavebtn1');
            const icon = document.getElementById('editIcon');
            const text = document.getElementById('editbtn1Text');
            const loader = btn1.querySelector('.loader');

            btn1.disabled = true;
            icon.style.display = 'none';
            text.textContent = 'Updating...';
            if (loader) loader.style.display = 'block';

            const formData = new FormData(this);
            const response = await fetch(this.action, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            btn1.disabled = false;
            icon.style.display = 'inline-block';
            text.textContent = 'Update';
            if (loader) loader.style.display = 'none';
            
            if (result.success) {
                showToast(result.message, 'success');
                editModal.style.display = 'none';
                fetchCategories();
            } else {
                result.errors.forEach(error => showToast(error, 'error'));
            }
        });

        // Search and data fetching
        let debounceTimer;
        searchInput.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                const query = searchInput.value;
                fetchCategories(query);
            }, 300);
        });

        async function fetchCategories(query = '') {
            try {
                const response = await fetch(`?search_query=${encodeURIComponent(query)}`);
                const categories = await response.json();
                renderTable(categories);
            } catch (error) {
                console.error('Failed to fetch categories:', error);
                showToast('Failed to load categories. Please try again.', 'error');
            }
        }

        // Export to Excel
        exportExcelbtn1.addEventListener('click', () => {
            const table = document.getElementById('categoriesTable');
            const ws = XLSX.utils.table_to_sheet(table);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Stationery Categories");
            XLSX.writeFile(wb, "stationery_categories.xlsx");
        });

        // Export to PDF
        exportPdfbtn1.addEventListener('click', () => {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            doc.text("Stationery Categories Report", 14, 20);
            doc.autoTable({
                html: '#categoriesTable',
                startY: 30,
                styles: { fontSize: 10, cellPadding: 2, overflow: 'linebreak' },
                headStyles: { fillColor: [30, 132, 73], textColor: 255 },
                margin: { top: 25 },
                didDrawPage: function (data) {
                    doc.text("Page " + doc.internal.getNumberOfPages(), data.settings.margin.left, doc.internal.pageSize.height - 10);
                }
            });
            doc.save("stationery_categories.pdf");
        });
        
        <?php
        if (isset($success_message)) {
            echo "showToast('" . addslashes($success_message) . "', 'success');";
        }
        if (isset($errors) && !empty($errors)) {
            foreach ($errors as $error) {
                echo "showToast('" . addslashes($error) . "', 'error');";
            }
        }
        ?>
    </script>
</body>
</html>