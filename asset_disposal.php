<?php
// Use output buffering to prevent unexpected output from interfering with JSON responses.
ob_start();

require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction("Asset Disposal");

// Helper function to set a toast message in a session variable
function setSessionToast($message, $type)
{
    $_SESSION['toast_message'] = $message;
    $_SESSION['toast_type'] = $type;
}

// Function to fetch active assets
function getActiveAssets($conn) {
    $assets_to_dispose = [];
    $sql_active = "SELECT * FROM assets WHERE status IN ('active', 'inactive', 'maintenance') ORDER BY asset_name ASC";
    $result_active = $conn->query($sql_active);
    if ($result_active) {
        while ($row = $result_active->fetch_assoc()) {
            $assets_to_dispose[] = $row;
        }
    }
    return $assets_to_dispose;
}

// Function to fetch disposed assets
function getDisposedAssets($conn) {
    $disposed_assets = [];
    $sql_disposed = "SELECT * FROM assets WHERE status = 'disposed' ORDER BY disposal_date DESC";
    $result_disposed = $conn->query($sql_disposed);
    if ($result_disposed) {
        while ($row = $result_disposed->fetch_assoc()) {
            $disposed_assets[] = $row;
        }
    }
    return $disposed_assets;
}


// Handle all AJAX requests at the top of the file
if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['action']) && in_array($_GET['action'], ['get_active_assets', 'get_disposed_assets']))) {
    ob_clean(); // Clear any existing output before sending JSON
    header('Content-Type: application/json');

    $response = ['success' => false, 'message' => ''];
    $action = $_SERVER['REQUEST_METHOD'] === 'POST' ? $conn->real_escape_string($_POST['action']) : $_GET['action'];

    if ($action === 'dispose') {
        $asset_id = (int)($_POST['asset_id'] ?? 0);
        $disposal_reason = $conn->real_escape_string($_POST['disposal_reason'] ?? '');
        $disposal_method = $conn->real_escape_string($_POST['disposal_method'] ?? '');
        $disposal_date = $conn->real_escape_string($_POST['disposal_date'] ?? '');
        $final_value = (float)($_POST['final_value'] ?? 0.00);

        if (empty($asset_id) || empty($disposal_reason) || empty($disposal_method)) {
            $response['message'] = 'Missing required fields.';
        } else {
            $sql = "UPDATE assets SET 
                    status = 'disposed', 
                    disposal_date = ?, 
                    disposal_reason = ?, 
                    disposal_method = ?, 
                    final_value = ?,
                    assigned_to = NULL, 
                    department = NULL
                    WHERE id = ?";

            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("sssdi", $disposal_date, $disposal_reason, $disposal_method, $final_value, $asset_id);
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = 'Asset disposed of successfully!';
                } else {
                    $response['message'] = 'Error: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $response['message'] = 'Error preparing statement: ' . $conn->error;
            }
        }
    } else if ($action === 'revert') {
        $asset_id = (int)($_POST['asset_id'] ?? 0);
        $sql = "UPDATE assets SET status = 'active', disposal_date = NULL, disposal_reason = NULL, disposal_method = NULL, final_value = NULL WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $asset_id);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Disposal reverted. Asset is now active.';
            } else {
                $response['message'] = 'Error: ' . $stmt->error;
            }
            $stmt->close();
        } else {
            $response['message'] = 'Error preparing statement: ' . $conn->error;
        }
    } else if ($action === 'get_active_assets') {
        $response = getActiveAssets($conn);
    } else if ($action === 'get_disposed_assets') {
        $response = getDisposedAssets($conn);
    }

    echo json_encode($response);
    exit();
}

// Fetch initial data for the page load
$assets_to_dispose = getActiveAssets($conn);
$disposed_assets = getDisposedAssets($conn);

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Disposal - School Pilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
       
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #e8f5e8 0%, #f0f9f0 100%);
            min-height: 100vh;
            color: #333;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            margin-top: 45px;
            background: linear-gradient(135deg, #228b22 0%, #32cd32 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(34, 139, 34, 0.2);
            position: relative;
            overflow: hidden;
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

        .header-content {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .header-title h1 {
            font-size: 2em;
            font-weight: 700;
        }

        .header-title p {
            font-size: 1.1em;
            opacity: 0.9;
            margin-top: 5px;
        }

        .section-header {
            background: white;
            padding: 20px 25px;
            border-bottom: 2px solid #e8f5e8;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 15px 15px 0 0;
        }

        .section-title {
            font-size: 1.3em;
            font-weight: 700;
            color: #2e7d32;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        .table-wrapper {
            overflow-x: auto;
            max-height: 600px;
        }

        .assets-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95em;
        }

        .assets-table th {
            background: linear-gradient(135deg, #2e7d32 0%, #388e3c 100%);
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
            border-bottom: 2px solid #1b5e20;
        }

        .assets-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #e8f5e8;
            vertical-align: middle;
        }

        .assets-table tbody tr {
            transition: all 0.3s ease;
        }

        .assets-table tbody tr:hover {
            background: #f8fcf8;
            transform: scale(1.01);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }

        .empty-state i {
            font-size: 4em;
            color: #ddd;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            margin-bottom: 10px;
            color: #999;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .action-btnn {
            width: 35px;
            height: 35px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9em;
            transition: all 0.3s ease;
            color: white;
        }
        
        .action-btnn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .btnn-dispose {
            background: linear-gradient(135deg, #f44336, #d32f2f);
        }

        .btnn-revert {
            background: linear-gradient(135deg, #2196f3, #1976d2);
        }

        .btnn-view {
            background: linear-gradient(135deg, #ff9800, #f57c00);
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
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: white;
            margin: 2% auto;
            padding: 0;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }

        .modal-header {
            background: linear-gradient(135deg, #228b22 0%, #32cd32 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.5em;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5em;
            cursor: pointer;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 20px;
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

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
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
        
        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }

        .btnn {
            padding: 12px 24px;
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
            color: white;
            white-space: nowrap;
        }

        .btnn-primary {
            background: linear-gradient(135deg, #4caf50 0%, #45a049 100%);
        }

        .btnn-primary:hover {
            background: linear-gradient(135deg, #45a049 0%, #3d8b40 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(76, 175, 80, 0.3);
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

        .btnn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }

        .btnn-danger:hover {
            background: linear-gradient(135deg, #c82333 0%, #a71e2a 100%);
        }
        
        .toast {
            visibility: hidden;
            min-width: 250px;
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
        
        .toast.toast-info {
            background-color: #2196F3;
        }
    </style>
</head>
<body>
    <?php require_once 'nav.php'; ?>

    <div class="container">
        <div class="header">
            <div class="header-content">
                <div class="header-title">
                    <div>
                        <h1><i class="fas fa-trash-alt"></i> Asset Disposal</h1>
                        <p>Manage and track the disposal of school assets</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-container">
            <div class="section-header">
                <div class="section-title">
                    <i class="fas fa-clipboard-list"></i> Assets to be Disposed
                </div>
            </div>
            <div class="table-wrapper">
                <table class="assets-table">
                    <thead>
                        <tr>
                            <th>Asset Name</th>
                            <th>Asset Code</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Location</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="activeAssetsTableBody">
                        <?php if (empty($assets_to_dispose)) : ?>
                            <tr>
                                <td colspan="6" class="empty-state">
                                    <i class="fas fa-box-open"></i>
                                    <h3>No active assets found</h3>
                                    <p>Add assets from the main inventory page to see them here.</p>
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($assets_to_dispose as $asset) : ?>
                                <tr data-asset-id="<?php echo htmlspecialchars($asset['id']); ?>">
                                    <td><?php echo htmlspecialchars($asset['asset_name']); ?></td>
                                    <td><?php echo htmlspecialchars($asset['asset_code']); ?></td>
                                    <td><?php echo htmlspecialchars($asset['category']); ?></td>
                                    <td><span class="status-badge status-<?php echo htmlspecialchars($asset['status']); ?>"><?php echo htmlspecialchars($asset['status']); ?></span></td>
                                    <td><?php echo htmlspecialchars($asset['storage_location']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btnn btnn-dispose" onclick="openDisposeModal(<?php echo htmlspecialchars($asset['id']); ?>, '<?php echo htmlspecialchars($asset['asset_name']); ?>')" title="Dispose Asset">
                                                <i class="fas fa-dumpster"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="table-container">
            <div class="section-header">
                <div class="section-title">
                    <i class="fas fa-history"></i> Disposal History
                </div>
            </div>
            <div class="table-wrapper">
                <table class="assets-table">
                    <thead>
                        <tr>
                            <th>Asset Name</th>
                            <th>Asset Code</th>
                            <th>Disposal Date</th>
                            <th>Reason</th>
                            <th>Method</th>
                            <th>Final Value</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="disposedAssetsTableBody">
                        <?php if (empty($disposed_assets)) : ?>
                            <tr>
                                <td colspan="7" class="empty-state">
                                    <i class="fas fa-undo"></i>
                                    <h3>No assets have been disposed of yet.</h3>
                                    <p>Disposed assets will appear here once the process is complete.</p>
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($disposed_assets as $asset) : ?>
                                <tr data-asset-id="<?php echo htmlspecialchars($asset['id']); ?>">
                                    <td><?php echo htmlspecialchars($asset['asset_name']); ?></td>
                                    <td><?php echo htmlspecialchars($asset['asset_code']); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($asset['disposal_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($asset['disposal_reason']); ?></td>
                                    <td><?php echo htmlspecialchars($asset['disposal_method']); ?></td>
                                    <td>UGX <?php echo number_format($asset['final_value']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btnn btnn-revert" onclick="revertDisposal(<?php echo htmlspecialchars($asset['id']); ?>)" title="Revert Disposal">
                                                <i class="fas fa-undo"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="disposeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-trash-alt"></i> Confirm Asset Disposal
                </h3>
                <button class="close" onclick="closeModal('disposeModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to dispose of asset: <strong><span id="assetName"></span></strong>?</p>
                <form id="disposeForm">
                    <input type="hidden" id="assetId" name="asset_id">
                    <input type="hidden" name="action" value="dispose">

                    <div class="form-group">
                        <label for="disposalReason"><i class="fas fa-question-circle"></i> Reason for Disposal</label>
                        <select id="disposalReason" name="disposal_reason" required>
                            <option value="">Select Reason</option>
                            <option value="broken">Broken / Damaged</option>
                            <option value="obsolete">Obsolete / Outdated</option>
                            <option value="surplus">Surplus to Requirements</option>
                            <option value="lost">Lost</option>
                            <option value="stolen">Stolen</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="disposalMethod"><i class="fas fa-recycle"></i> Disposal Method</label>
                        <select id="disposalMethod" name="disposal_method" required>
                            <option value="">Select Method</option>
                            <option value="scrapped">Scrapped / Recycled</option>
                            <option value="sold">Sold</option>
                            <option value="donated">Donated</option>
                            <option value="written_off">Written-off</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="disposalDate"><i class="fas fa-calendar-alt"></i> Disposal Date</label>
                        <input type="date" id="disposalDate" name="disposal_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="form-group" id="finalValueGroup" style="display:none;">
                        <label for="finalValue"><i class="fas fa-dollar-sign"></i> Final Value / Proceeds (UGX)</label>
                        <input type="number" id="finalValue" name="final_value" step="1" min="0" placeholder="Enter proceeds if sold">
                    </div>

                </form>
            </div>
            <div class="modal-footer">
                <button class="btnn btnn-secondary" onclick="closeModal('disposeModal')">Cancel</button>
                <button class="btnn btnn-danger" id="confirmDisposeBtn">
                    <i class="fas fa-check-circle"></i> Confirm Disposal
                </button>
            </div>
        </div>
    </div>

    <div id="toast" class="toast"></div>
    
    <script>
        // JS Data from PHP
        let activeAssetsData = <?php echo json_encode($assets_to_dispose); ?>;
        let disposedAssetsData = <?php echo json_encode($disposed_assets); ?>;

        const activeAssetsTableBody = document.getElementById('activeAssetsTableBody');
        const disposedAssetsTableBody = document.getElementById('disposedAssetsTableBody');
        const disposeModal = document.getElementById('disposeModal');
        const disposeForm = document.getElementById('disposeForm');
        const confirmDisposeBtn = document.getElementById('confirmDisposeBtn');

        // Toast notification function
        function showToast(message, type) {
            const toast = document.getElementById('toast');
            toast.className = `toast show toast-${type}`;
            toast.textContent = message;
            setTimeout(function() {
                toast.className = toast.className.replace('show', '');
            }, 3000);
        }

        // Check for session toast message on page load
        window.addEventListener('load', function() {
            <?php if (isset($_SESSION['toast_message'])) : ?>
                showToast('<?php echo $_SESSION['toast_message']; ?>', '<?php echo $_SESSION['toast_type']; ?>');
                <?php
                unset($_SESSION['toast_message']);
                unset($_SESSION['toast_type']);
                ?>
            <?php endif; ?>
        });

        // Open disposal modal
        function openDisposeModal(id, name) {
            document.getElementById('assetId').value = id;
            document.getElementById('assetName').textContent = name;
            disposeModal.style.display = 'flex';
        }

        // Close disposal modal
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Submit disposal form via AJAX
        disposeForm.addEventListener('submit', function(e) {
            e.preventDefault();
            submitDisposal();
        });

        confirmDisposeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            submitDisposal();
        });

        function submitDisposal() {
            if (!disposeForm.checkValidity()) {
                disposeForm.reportValidity();
                return;
            }

            const formData = new FormData(disposeForm);
            
            // Show loading state
            confirmDisposeBtn.disabled = true;
            confirmDisposeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Disposing...';

            fetch('asset_disposal.php', {
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
                    showToast(data.message, 'success');
                    closeModal('disposeModal');
                    // Update tables without full page reload
                    fetchTablesData();
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred. Please try again.', 'error');
            })
            .finally(() => {
                confirmDisposeBtn.disabled = false;
                confirmDisposeBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Disposal';
            });
        }
        
        // Revert disposal via AJAX
        function revertDisposal(assetId) {
            if (!confirm('Are you sure you want to revert this disposal? The asset will be marked as "Active" again.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'revert');
            formData.append('asset_id', assetId);

            fetch('asset_disposal.php', {
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
                    showToast(data.message, 'info');
                    fetchTablesData();
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred. Please try again.', 'error');
            });
        }
        
        // Function to render the tables from fetched data
        function renderTables(active, disposed) {
            // Render Active Assets table
            activeAssetsTableBody.innerHTML = '';
            if (active.length > 0) {
                active.forEach(asset => {
                    const row = document.createElement('tr');
                    row.dataset.assetId = asset.id;
                    row.innerHTML = `
                        <td>${asset.asset_name}</td>
                        <td>${asset.asset_code}</td>
                        <td>${asset.category}</td>
                        <td><span class="status-badge status-${asset.status}">${asset.status}</span></td>
                        <td>${asset.storage_location}</td>
                        <td>
                            <div class="action-buttons">
                                <button class="action-btnn btnn-dispose" onclick="openDisposeModal(${asset.id}, '${asset.asset_name}')" title="Dispose Asset">
                                    <i class="fas fa-dumpster"></i>
                                </button>
                            </div>
                        </td>
                    `;
                    activeAssetsTableBody.appendChild(row);
                });
            } else {
                activeAssetsTableBody.innerHTML = `
                    <tr>
                        <td colspan="6" class="empty-state">
                            <i class="fas fa-box-open"></i>
                            <h3>No active assets found</h3>
                            <p>Add assets from the main inventory page to see them here.</p>
                        </td>
                    </tr>
                `;
            }

            // Render Disposed Assets table
            disposedAssetsTableBody.innerHTML = '';
            if (disposed.length > 0) {
                disposed.forEach(asset => {
                    const row = document.createElement('tr');
                    const disposalDate = asset.disposal_date ? new Date(asset.disposal_date).toISOString().slice(0, 10) : 'N/A';
                    const finalValue = asset.final_value ? `UGX ${parseFloat(asset.final_value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }` : 'N/A';
                    row.innerHTML = `
                        <td>${asset.asset_name}</td>
                        <td>${asset.asset_code}</td>
                        <td>${disposalDate}</td>
                        <td>${asset.disposal_reason}</td>
                        <td>${asset.disposal_method}</td>
                        <td>${finalValue}</td>
                        <td>
                            <div class="action-buttons">
                                <button class="action-btnn btnn-revert" onclick="revertDisposal(${asset.id})" title="Revert Disposal">
                                    <i class="fas fa-undo"></i>
                                </button>
                            </div>
                        </td>
                    `;
                    disposedAssetsTableBody.appendChild(row);
                });
            } else {
                disposedAssetsTableBody.innerHTML = `
                    <tr>
                        <td colspan="7" class="empty-state">
                            <i class="fas fa-undo"></i>
                            <h3>No assets have been disposed of yet.</h3>
                            <p>Disposed assets will appear here once the process is complete.</p>
                        </td>
                    </tr>
                `;
            }
        }
        
        // Function to fetch updated data from the server
        function fetchTablesData() {
            Promise.all([
                fetch('asset_disposal.php?action=get_active_assets').then(res => res.json()),
                fetch('asset_disposal.php?action=get_disposed_assets').then(res => res.json())
            ])
            .then(([active, disposed]) => {
                activeAssetsData = active;
                disposedAssetsData = disposed;
                renderTables(activeAssetsData, disposedAssetsData);
            })
            .catch(error => {
                console.error('Failed to fetch data:', error);
                showToast('Failed to refresh data. Please reload the page.', 'error');
            });
        }
        
        // Initial rendering on page load and event listener setup
        document.addEventListener('DOMContentLoaded', () => {
             renderTables(activeAssetsData, disposedAssetsData);

             // Show/hide final value field based on disposal method
             document.getElementById('disposalMethod').addEventListener('change', function() {
                const finalValueGroup = document.getElementById('finalValueGroup');
                const finalValueInput = document.getElementById('finalValue');
                if (this.value === 'sold') {
                    finalValueGroup.style.display = 'block';
                    finalValueInput.setAttribute('required', 'required');
                } else {
                    finalValueGroup.style.display = 'none';
                    finalValueInput.removeAttribute('required');
                }
            });
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('disposeModal');
            if (event.target == modal) {
                closeModal('disposeModal');
            }
        }
    </script>
</body>
</html>
