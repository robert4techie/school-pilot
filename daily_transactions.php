<?php
require_once 'conn.php';
require_once 'auth.php';
require_once 'tracking.php';
$tracker->trackAction("Daily transactions");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <title>School Pilot - Daily Transaction Report</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #e8f5e8 0%, #f0f8f0 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
            margin-top: 50px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #2d5a2d 0%, #4a8f4a 100%);
            color: white;
            padding: 20px;
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
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="1" fill="rgba(255,255,255,0.1)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .header h2 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .header .subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .date-section {
            background: #f8fdf8;
            padding: 25px 30px;
            border-bottom: 2px solid #e0f0e0;
        }

        .date-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .date-input-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .date-input-group label {
            font-weight: 600;
            color: #2d5a2d;
            font-size: 1.1rem;
        }

        .date-input {
            padding: 12px 15px;
            border: 2px solid #d0e7d0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .date-input:focus {
            outline: none;
            border-color: #4a8f4a;
            box-shadow: 0 0 0 3px rgba(74, 143, 74, 0.1);
        }

        .add-transaction-btnn {
            background: linear-gradient(135deg, #4a8f4a 0%, #5ba55b 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(74, 143, 74, 0.3);
        }

        .add-transaction-btnn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(74, 143, 74, 0.4);
        }

        .content {
            padding: 30px;
        }

        .transaction-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            background: white;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .transaction-table th {
            background: linear-gradient(135deg, #2d5a2d 0%, #4a8f4a 100%);
            color: white;
            padding: 10px 10px;
            text-align: left;
            font-weight: 600;
            font-size: 1rem;
        }

        .transaction-table td {
            padding: 18px 15px;
            border-bottom: 1px solid #e8f5e8;
            transition: background-color 0.3s ease;
        }

        .transaction-table tbody tr:hover {
            background-color: #f8fdf8;
        }

        .transaction-table tbody tr:last-child td {
            border-bottom: none;
        }

        .amount-income {
            color: #4a8f4a;
            font-weight: 600;
        }

        .amount-expenditure {
            color: #d32f2f;
            font-weight: 600;
        }

        .summary-section {
            background: linear-gradient(135deg, #f8fdf8 0%, #e8f5e8 100%);
            padding: 25px;
            border-radius: 10px;
            margin-top: 20px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease;
        }

        .summary-card:hover {
            transform: translateY(-5px);
        }

        .summary-card h3 {
            color: #2d5a2d;
            margin-bottom: 10px;
            font-size: 1.1rem;
        }

        .summary-card .amount {
            font-size: 1.8rem;
            font-weight: bold;
        }

        .summary-card.income .amount {
            color: #4a8f4a;
        }

        .summary-card.expenditure .amount {
            color: #d32f2f;
        }

        .summary-card.balance .amount {
            color: #2d5a2d;
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
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: white;
            margin: 3% auto;
            padding: 0;
            border-radius: 15px;
            width: 90%;
            max-width: 550px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            background: linear-gradient(135deg, #2d5a2d 0%, #4a8f4a 100%);
            color: white;
            padding: 10px 15px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            font-size: 1rem;
            margin: 0;
        }

        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: opacity 0.3s ease;
        }

        .close:hover {
            opacity: 0.7;
        }

        .modal-body {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2d5a2d;
            font-size: 1rem;
        }

        .form-control {
            width: 100%;
            padding: 15px;
            border: 2px solid #d0e7d0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: #4a8f4a;
            box-shadow: 0 0 0 3px rgba(74, 143, 74, 0.1);
        }

        .form-control select {
            cursor: pointer;
        }

        .modal-footer {
            padding: 10px 20px 20px;
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }

        .btnn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btnn-primary {
            background: linear-gradient(135deg, #4a8f4a 0%, #5ba55b 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(74, 143, 74, 0.3);
        }

        .btnn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(74, 143, 74, 0.4);
        }

        .btnn-secondary {
            background: #6c757d;
            color: white;
        }

        .btnn-secondary:hover {
            background: #5a6268;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btnn-sm {
            padding: 8px 15px;
            font-size: 0.875rem;
        }

        .btnn-edit {
            background: #ffc107;
            color: #212529;
        }

        .btnn-edit:hover {
            background: #e0a800;
        }

        .btnn-delete {
            background: #dc3545;
            color: white;
        }

        .btnn-delete:hover {
            background: #c82333;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }

        .empty-state svg {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            margin-bottom: 10px;
            font-size: 1.5rem;
        }

        @media (max-width: 768px) {
            .date-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .date-input-group {
                flex-direction: column;
                align-items: stretch;
            }

            .transaction-table {
                font-size: 0.9rem;
            }

            .transaction-table th,
            .transaction-table td {
                padding: 12px 8px;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .modal-content {
                margin: 10% auto;
                width: 95%;
            }
        }

        .button-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .export-dropdown {
            position: relative;
            display: inline-block;
        }

        .export-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .export-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .dropdown-arrow {
            transition: transform 0.3s ease;
            font-size: 12px;
        }

        .export-dropdown.active .dropdown-arrow {
            transform: rotate(180deg);
        }

        .export-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            min-width: 160px;
            margin-top: 4px;
        }

        .export-menu.show {
            display: block;
            animation: fadeIn 0.2s ease;
        }

        .export-option {
            width: 100%;
            padding: 12px 16px;
            border: none;
            background: none;
            text-align: left;
            cursor: pointer;
            font-size: 14px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s ease;
        }

        .export-option:hover {
            background-color: #f5f5f5;
        }

        .export-option:first-child {
            border-radius: 6px 6px 0 0;
        }

        .export-option:last-child {
            border-radius: 0 0 6px 6px;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-5px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Success message styling */
        .export-success {
            position: fixed;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, #4CAF50, #45a049);
            color: white;
            padding: 12px 20px;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
            z-index: 10001;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    </style>
</head>

<body>
    <?php
    require_once 'nav.php';
    ?>
    <div class="container">
        <div class="header">
            <h2>Daily Transaction Report</h2>
            <p class="subtitle"></p>
        </div>

        <div class="date-section">
            <div class="date-controls">
                <div class="date-input-group">
                    <label for="transactionDate">Date:</label>
                    <input type="date" id="transactionDate" class="date-input">
                </div>
                <div class="button-group">
                    <div class="export-dropdown">
                        <button class="export-btn" onclick="toggleExportMenu()">
                             Export
                            <span class="dropdown-arrow">▼</span>
                        </button>
                        <div id="exportMenu" class="export-menu">
                            <button onclick="exportToExcel()" class="export-option">
                                 Export to Excel
                            </button>
                            <button onclick="exportToPDF()" class="export-option">
                                 Export to PDF
                            </button>
                        </div>
                    </div>
                    <button class="add-transaction-btnn" onclick="openModal()">
                        + Add Transaction
                    </button>
                </div>
            </div>
        </div>


        <div class="content">
            <div class="table-container">
                <table class="transaction-table">
                    <thead>
                        <tr>
                            <th>S/N</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Payment Method</th>
                            <th>Income</th>
                            <th>Expenditure</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="transactionTableBody">
                        <!-- Transactions will be dynamically added here -->
                    </tbody>
                </table>

                <div id="emptyState" class="empty-state">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h24c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h20v2zm0-4H7V7h20v2z" />
                    </svg>
                    <h3>No Transactions Yet</h3>
                    <p>Click "Add Transaction" to record your first transaction for today.</p>
                </div>
            </div>

            <div class="summary-section">
                <div class="summary-grid">
                    <div class="summary-card income">
                        <h3>Total Income</h3>
                        <div class="amount" id="totalIncome">UGX0.00</div>
                    </div>
                    <div class="summary-card expenditure">
                        <h3>Total Expenditure</h3>
                        <div class="amount" id="totalExpenditure">UGX0.00</div>
                    </div>
                    <div class="summary-card balance">
                        <h3>Balance</h3>
                        <div class="amount" id="balance">UGX0.00</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div id="transactionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Add New Transaction</h2>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="transactionForm">
                    <div class="form-group">
                        <label for="description">Description *</label>
                        <input type="text" id="description" class="form-control" required placeholder="Enter transaction description">
                    </div>

                    <div class="form-group">
                        <label for="transactionDate">Transaction Date *</label>
                        <input type="date" id="transactionDateModal" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="paymentMethod">Payment Method *</label>
                        <select id="paymentMethod" class="form-control" required>
                            <option value="">Select payment method</option>
                            <option value="Cash">Cash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Mobile Money">Mobile Money</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Card">Card</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="transactionType">Transaction Type *</label>
                        <select id="transactionType" class="form-control" required>
                            <option value="">Select transaction type</option>
                            <option value="income">Income</option>
                            <option value="expenditure">Expenditure</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="amount">Amount (UGX) *</label>
                        <input type="number" id="amount" class="form-control" required min="0" step="0.01" placeholder="0.00">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btnn btnn-secondary" onclick="closeModal()">Cancel</button>
                <button type="button" class="btnn btnn-primary" onclick="saveTransaction()">Save Transaction</button>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let transactions = [];
        let editingIndex = -1;
        let currentDate = new Date().toISOString().split('T')[0];

        // Initialize the page
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('transactionDate').value = currentDate;
            document.getElementById('transactionDateModal').value = currentDate;
            loadTransactions();
        });

        // API Helper functions
        async function apiRequest(url, options = {}) {
            try {
                const response = await fetch(url, {
                    headers: {
                        'Content-Type': 'application/json',
                        ...options.headers
                    },
                    ...options
                });

                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data.message || 'Request failed');
                }

                return data;
            } catch (error) {
                console.error('API Request Error:', error);
                throw error;
            }
        }

        // Modal functions
        function openModal(index = -1) {
            const modal = document.getElementById('transactionModal');
            const modalTitle = document.getElementById('modalTitle');
            const form = document.getElementById('transactionForm');

            if (index >= 0) {
                // Edit mode
                editingIndex = index;
                const transaction = transactions[index];
                modalTitle.textContent = 'Edit Transaction';

                document.getElementById('description').value = transaction.description;
                document.getElementById('paymentMethod').value = transaction.paymentMethod;
                document.getElementById('transactionType').value = transaction.type;
                document.getElementById('amount').value = transaction.amount;
                document.getElementById('transactionDateModal').value = transaction.date;
            } else {
                // Add mode
                editingIndex = -1;
                modalTitle.textContent = 'Add New Transaction';
                form.reset();
                document.getElementById('transactionDateModal').value = currentDate;
            }

            modal.style.display = 'block';
            document.getElementById('description').focus();
        }

        function closeModal() {
            document.getElementById('transactionModal').style.display = 'none';
            document.getElementById('transactionForm').reset();
            editingIndex = -1;
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('transactionModal');
            if (event.target === modal) {
                closeModal();
            }
        }

        // Save transaction
        async function saveTransaction() {
            const description = document.getElementById('description').value.trim();
            const paymentMethod = document.getElementById('paymentMethod').value;
            const transactionType = document.getElementById('transactionType').value;
            const amount = parseFloat(document.getElementById('amount').value);
            const transactionDate = document.getElementById('transactionDateModal').value;

            // Client-side validation
            if (!description || !paymentMethod || !transactionType || !amount || amount <= 0 || !transactionDate) {
                showMessage('Please fill in all required fields with valid values.', 'error');
                return;
            }

            const transactionData = {
                description: description,
                payment_method: paymentMethod,
                transaction_type: transactionType,
                amount: amount,
                transaction_date: transactionDate
            };

            try {
                showLoading(true);

                if (editingIndex >= 0) {
                    // Update existing transaction
                    transactionData.action = 'update';
                    transactionData.id = transactions[editingIndex].id;

                    const response = await apiRequest('api/transaction_handler.php', {
                        method: 'POST',
                        body: JSON.stringify(transactionData)
                    });

                    if (response.success) {
                        showMessage('Transaction updated successfully!', 'success');
                    } else {
                        throw new Error(response.message);
                    }
                } else {
                    // Add new transaction
                    transactionData.action = 'add';

                    const response = await apiRequest('api/transaction_handler.php', {
                        method: 'POST',
                        body: JSON.stringify(transactionData)
                    });

                    if (response.success) {
                        showMessage('Transaction added successfully!', 'success');
                    } else {
                        throw new Error(response.message);
                    }
                }

                // Reload transactions and close modal
                await loadTransactions();
                closeModal();

            } catch (error) {
                showMessage('Error saving transaction: ' + error.message, 'error');
            } finally {
                showLoading(false);
            }
        }

        // Delete transaction
        async function deleteTransaction(index) {
            if (!confirm('Are you sure you want to delete this transaction?')) {
                return;
            }

            const transaction = transactions[index];

            try {
                showLoading(true);

                const response = await apiRequest('api/transaction_handler.php', {
                    method: 'POST',
                    body: JSON.stringify({
                        action: 'delete',
                        id: transaction.id
                    })
                });

                if (response.success) {
                    showMessage('Transaction deleted successfully!', 'success');
                    await loadTransactions();
                } else {
                    throw new Error(response.message);
                }

            } catch (error) {
                showMessage('Error deleting transaction: ' + error.message, 'error');
            } finally {
                showLoading(false);
            }
        }

        // Load transactions from database
        async function loadTransactions() {
            try {
                showLoading(true);

                const response = await apiRequest(`api/transaction_handler.php?date=${currentDate}`);

                if (response.success) {
                    transactions = response.transactions || [];
                    renderTransactions();
                    await updateSummary();
                } else {
                    throw new Error(response.message);
                }

            } catch (error) {
                console.error('Error loading transactions:', error);
                showMessage('Error loading transactions: ' + error.message, 'error');
                transactions = [];
                renderTransactions();
                updateSummaryDisplay(0, 0, 0);
            } finally {
                showLoading(false);
            }
        }

        // Render transactions table
        function renderTransactions() {
            const tbody = document.getElementById('transactionTableBody');
            const emptyState = document.getElementById('emptyState');

            if (transactions.length === 0) {
                tbody.innerHTML = '';
                emptyState.style.display = 'block';
                return;
            }

            emptyState.style.display = 'none';

            tbody.innerHTML = transactions.map((transaction, index) => `
        <tr>
            <td>${index + 1}</td>
            <td>${formatDate(transaction.date)}</td>
            <td>${escapeHtml(transaction.description)}</td>
            <td>${escapeHtml(transaction.paymentMethod)}</td>
            <td class="amount-income">
                ${transaction.type === 'income' ? `UGX${formatNumber(transaction.amount)}` : '-'}
            </td>
            <td class="amount-expenditure">
                ${transaction.type === 'expenditure' ? `UGX${formatNumber(transaction.amount)}` : '-'}
            </td>
            <td>
                <div class="action-buttons">
                    <button class="btnn btnn-edit btnn-sm" onclick="openModal(${index})">Edit</button>
                    <button class="btnn btnn-delete btnn-sm" onclick="deleteTransaction(${index})">Delete</button>
                </div>
            </td>
        </tr>
    `).join('');
        }

        // Update summary from database
        async function updateSummary() {
            try {
                const response = await apiRequest(`api/transaction_handler.php?date=${currentDate}&summary=1`);

                if (response.success) {
                    const summary = response.summary;
                    updateSummaryDisplay(
                        summary.income.amount,
                        summary.expenditure.amount,
                        summary.balance
                    );
                } else {
                    throw new Error(response.message);
                }

            } catch (error) {
                console.error('Error updating summary:', error);
                // Fallback to client-side calculation
                const income = transactions
                    .filter(t => t.type === 'income')
                    .reduce((sum, t) => sum + t.amount, 0);

                const expenditure = transactions
                    .filter(t => t.type === 'expenditure')
                    .reduce((sum, t) => sum + t.amount, 0);

                const balance = income - expenditure;

                updateSummaryDisplay(income, expenditure, balance);
            }
        }

        // Update summary display
        function updateSummaryDisplay(income, expenditure, balance) {
            document.getElementById('totalIncome').textContent = `UGX${formatNumber(income)}`;
            document.getElementById('totalExpenditure').textContent = `UGX${formatNumber(expenditure)}`;
            document.getElementById('balance').textContent = `UGX${formatNumber(balance)}`;

            // Add visual indicators for balance
            const balanceElement = document.getElementById('balance');
            balanceElement.className = 'amount';
            if (balance > 0) {
                balanceElement.classList.add('positive');
            } else if (balance < 0) {
                balanceElement.classList.add('negative');
            }
        }

        // Utility functions
        function formatNumber(num) {
            return parseFloat(num).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        }

        function formatDate(dateString) {
            const date = new Date(dateString + 'T00:00:00');
            const options = {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            };
            return date.toLocaleDateString('en-US', options);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function showMessage(message, type = 'info') {
            // Create or update message element
            let messageEl = document.getElementById('message');
            if (!messageEl) {
                messageEl = document.createElement('div');
                messageEl.id = 'message';
                messageEl.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 5px;
            color: white;
            font-weight: bold;
            z-index: 10000;
            max-width: 300px;
            word-wrap: break-word;
        `;
                document.body.appendChild(messageEl);
            }

            // Set message content and style
            messageEl.textContent = message;
            messageEl.className = `message-${type}`;

            // Set background color based on type
            switch (type) {
                case 'success':
                    messageEl.style.backgroundColor = '#4CAF50';
                    break;
                case 'error':
                    messageEl.style.backgroundColor = '#f44336';
                    break;
                case 'warning':
                    messageEl.style.backgroundColor = '#ff9800';
                    break;
                default:
                    messageEl.style.backgroundColor = '#2196F3';
            }

            messageEl.style.display = 'block';

            // Auto-hide after 5 seconds
            setTimeout(() => {
                if (messageEl) {
                    messageEl.style.display = 'none';
                }
            }, 5000);
        }

        function showLoading(show) {
            let loadingEl = document.getElementById('loading');

            if (show) {
                if (!loadingEl) {
                    loadingEl = document.createElement('div');
                    loadingEl.id = 'loading';
                    loadingEl.innerHTML = `
                <div style="
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0,0,0,0.5);
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    z-index: 9999;
                ">
                    <div style="
                        background: white;
                        padding: 20px;
                        border-radius: 5px;
                        text-align: center;
                    ">
                        <div style="
                            border: 4px solid #f3f3f3;
                            border-top: 4px solid #3498db;
                            border-radius: 50%;
                            width: 40px;
                            height: 40px;
                            animation: spin 2s linear infinite;
                            margin: 0 auto 10px;
                        "></div>
                        <div>Processing...</div>
                    </div>
                </div>
            `;
                    document.body.appendChild(loadingEl);

                    // Add CSS animation
                    if (!document.getElementById('loading-styles')) {
                        const style = document.createElement('style');
                        style.id = 'loading-styles';
                        style.textContent = `
                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                `;
                        document.head.appendChild(style);
                    }
                }
                loadingEl.style.display = 'block';
            } else {
                if (loadingEl) {
                    loadingEl.style.display = 'none';
                }
            }
        }

        // Event listeners
        document.getElementById('transactionDate').addEventListener('change', function(e) {
            currentDate = e.target.value;
            loadTransactions();
        });

        document.getElementById('transactionForm').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveTransaction();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Export dropdown functionality
        function toggleExportMenu() {
            const dropdown = document.querySelector('.export-dropdown');
            const menu = document.getElementById('exportMenu');

            dropdown.classList.toggle('active');
            menu.classList.toggle('show');
        }

        // Close export menu when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.querySelector('.export-dropdown');
            const menu = document.getElementById('exportMenu');

            if (!dropdown.contains(event.target)) {
                dropdown.classList.remove('active');
                menu.classList.remove('show');
            }
        });

        // Export to Excel function
        async function exportToExcel() {
            try {
                if (transactions.length === 0) {
                    showMessage('No transactions to export', 'warning');
                    return;
                }

                showLoading(true);

                // Prepare data for Excel
                const exportData = transactions.map((transaction, index) => ({
                    'S/N': index + 1,
                    'Date': formatDate(transaction.date),
                    'Description': transaction.description,
                    'Payment Method': transaction.paymentMethod,
                    'Income': transaction.type === 'income' ? transaction.amount : '',
                    'Expenditure': transaction.type === 'expenditure' ? transaction.amount : '',
                    'Type': transaction.type
                }));

                // Calculate summary
                const totalIncome = transactions
                    .filter(t => t.type === 'income')
                    .reduce((sum, t) => sum + parseFloat(t.amount), 0);

                const totalExpenditure = transactions
                    .filter(t => t.type === 'expenditure')
                    .reduce((sum, t) => sum + parseFloat(t.amount), 0);

                const balance = totalIncome - totalExpenditure;

                // Add summary rows
                exportData.push({}, // Empty row
                    {
                        'S/N': '',
                        'Date': '',
                        'Description': 'SUMMARY',
                        'Payment Method': '',
                        'Income': '',
                        'Expenditure': '',
                        'Type': ''
                    }, {
                        'S/N': '',
                        'Date': '',
                        'Description': 'Total Income',
                        'Payment Method': '',
                        'Income': totalIncome,
                        'Expenditure': '',
                        'Type': ''
                    }, {
                        'S/N': '',
                        'Date': '',
                        'Description': 'Total Expenditure',
                        'Payment Method': '',
                        'Income': '',
                        'Expenditure': totalExpenditure,
                        'Type': ''
                    }, {
                        'S/N': '',
                        'Date': '',
                        'Description': 'Balance',
                        'Payment Method': '',
                        'Income': balance >= 0 ? balance : '',
                        'Expenditure': balance < 0 ? Math.abs(balance) : '',
                        'Type': ''
                    }
                );

                // Create workbook and worksheet
                const wb = XLSX.utils.book_new();
                const ws = XLSX.utils.json_to_sheet(exportData);

                // Set column widths
                const colWidths = [{
                        wch: 5
                    }, // S/N
                    {
                        wch: 12
                    }, // Date
                    {
                        wch: 25
                    }, // Description
                    {
                        wch: 15
                    }, // Payment Method
                    {
                        wch: 12
                    }, // Income
                    {
                        wch: 12
                    }, // Expenditure
                    {
                        wch: 12
                    } // Type
                ];
                ws['!cols'] = colWidths;

                // Add worksheet to workbook
                XLSX.utils.book_append_sheet(wb, ws, 'Daily Transactions');

                // Generate filename with current date
                const filename = `Daily_Transactions_${currentDate}.xlsx`;

                // Save file
                XLSX.writeFile(wb, filename);

                showExportSuccess(`Excel file "${filename}" downloaded successfully!`);
                toggleExportMenu(); // Close the menu

            } catch (error) {
                console.error('Excel export error:', error);
                showMessage('Error exporting to Excel: ' + error.message, 'error');
            } finally {
                showLoading(false);
            }
        }

        // Export to PDF function
        async function exportToPDF() {
            try {
                if (transactions.length === 0) {
                    showMessage('No transactions to export', 'warning');
                    return;
                }

                showLoading(true);

                const {
                    jsPDF
                } = window.jspdf;
                const doc = new jsPDF();

                // Add title and date
                doc.setFontSize(18);
                doc.setFont(undefined, 'bold');
                doc.text('Daily Transaction Report', 14, 22);

                doc.setFontSize(12);
                doc.setFont(undefined, 'normal');
                doc.text(`Date: ${formatDate(currentDate)}`, 14, 32);
                doc.text(`Generated: ${new Date().toLocaleString()}`, 14, 40);

                // Prepare table data
                const tableData = transactions.map((transaction, index) => [
                    index + 1,
                    formatDate(transaction.date),
                    transaction.description,
                    transaction.paymentMethod,
                    transaction.type === 'income' ? `UGX ${formatNumber(transaction.amount)}` : '-',
                    transaction.type === 'expenditure' ? `UGX ${formatNumber(transaction.amount)}` : '-'
                ]);

                // Create table
                doc.autoTable({
                    head: [
                        ['S/N', 'Date', 'Description', 'Payment Method', 'Income', 'Expenditure']
                    ],
                    body: tableData,
                    startY: 50,
                    styles: {
                        fontSize: 9,
                        cellPadding: 3,
                    },
                    headStyles: {
                        fillColor: [41, 128, 185],
                        textColor: 255,
                        fontStyle: 'bold'
                    },
                    alternateRowStyles: {
                        fillColor: [245, 245, 245]
                    },
                    columnStyles: {
                        0: {
                            halign: 'center',
                            cellWidth: 15
                        },
                        1: {
                            cellWidth: 25
                        },
                        2: {
                            cellWidth: 40
                        },
                        3: {
                            cellWidth: 30
                        },
                        4: {
                            halign: 'right',
                            cellWidth: 35
                        },
                        5: {
                            halign: 'right',
                            cellWidth: 35
                        }
                    }
                });

                // Calculate summary
                const totalIncome = transactions
                    .filter(t => t.type === 'income')
                    .reduce((sum, t) => sum + parseFloat(t.amount), 0);

                const totalExpenditure = transactions
                    .filter(t => t.type === 'expenditure')
                    .reduce((sum, t) => sum + parseFloat(t.amount), 0);

                const balance = totalIncome - totalExpenditure;

                // Add summary section
                const finalY = doc.lastAutoTable.finalY + 10;

                doc.setFontSize(14);
                doc.setFont(undefined, 'bold');
                doc.text('Summary', 14, finalY);

                // Summary table
                const summaryData = [
                    ['Total Income', `UGX ${formatNumber(totalIncome)}`],
                    ['Total Expenditure', `UGX ${formatNumber(totalExpenditure)}`],
                    ['Balance', `UGX ${formatNumber(balance)}`]
                ];

                doc.autoTable({
                    body: summaryData,
                    startY: finalY + 5,
                    styles: {
                        fontSize: 11,
                        cellPadding: 4,
                    },
                    columnStyles: {
                        0: {
                            fontStyle: 'bold',
                            cellWidth: 50
                        },
                        1: {
                            halign: 'right',
                            cellWidth: 50,
                            fontStyle: 'bold'
                        }
                    },
                    theme: 'plain'
                });

                // Add footer
                const pageCount = doc.internal.getNumberOfPages();
                for (let i = 1; i <= pageCount; i++) {
                    doc.setPage(i);
                    doc.setFontSize(8);
                    doc.setFont(undefined, 'normal');
                    doc.text(`Page ${i} of ${pageCount}`, doc.internal.pageSize.getWidth() - 30, doc.internal.pageSize.getHeight() - 10);
                }

                // Generate filename and save
                const filename = `Daily_Transactions_${currentDate}.pdf`;
                doc.save(filename);

                showExportSuccess(`PDF file "${filename}" downloaded successfully!`);
                toggleExportMenu(); // Close the menu

            } catch (error) {
                console.error('PDF export error:', error);
                showMessage('Error exporting to PDF: ' + error.message, 'error');
            } finally {
                showLoading(false);
            }
        }

        // Show export success message
        function showExportSuccess(message) {
            // Remove any existing success message
            const existingMsg = document.querySelector('.export-success');
            if (existingMsg) {
                existingMsg.remove();
            }

            // Create new success message
            const successMsg = document.createElement('div');
            successMsg.className = 'export-success';
            successMsg.textContent = message;
            document.body.appendChild(successMsg);

            // Remove after 4 seconds
            setTimeout(() => {
                if (successMsg.parentNode) {
                    successMsg.remove();
                }
            }, 4000);
        }
    </script>
</body>

</html>