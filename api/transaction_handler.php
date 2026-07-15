<?php
require_once '../auth.php';
require_once '../conn.php';

// Set content type to JSON
header('Content-Type: application/json');

class TransactionHandler {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    /**
     * Add a new transaction
     */
    public function addTransaction($data) {
        try {
            // Validate required fields
            $required_fields = ['description', 'payment_method', 'transaction_type', 'amount', 'transaction_date'];
            foreach ($required_fields as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Field '{$field}' is required");
                }
            }
            
            // Validate amount
            if (!is_numeric($data['amount']) || $data['amount'] <= 0) {
                throw new Exception("Amount must be a positive number");
            }
            
            // Validate transaction type
            if (!in_array($data['transaction_type'], ['income', 'expenditure'])) {
                throw new Exception("Invalid transaction type");
            }
            
            // Validate payment method
            $valid_methods = ['Cash', 'Bank Transfer', 'Mobile Money', 'Cheque', 'Card'];
            if (!in_array($data['payment_method'], $valid_methods)) {
                throw new Exception("Invalid payment method");
            }
            
            // Validate date format
            if (!$this->validateDate($data['transaction_date'])) {
                throw new Exception("Invalid date format");
            }
            
            $sql = "INSERT INTO transactions (description, payment_method, transaction_type, amount, transaction_date) 
                    VALUES (?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("sssds", 
                $data['description'],
                $data['payment_method'],
                $data['transaction_type'],
                $data['amount'],
                $data['transaction_date']
            );
            
            if ($stmt->execute()) {
                $transaction_id = $this->conn->insert_id;
                $stmt->close();
                
                return [
                    'success' => true,
                    'message' => 'Transaction added successfully',
                    'transaction_id' => $transaction_id
                ];
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Update an existing transaction
     */
    public function updateTransaction($id, $data) {
        try {
            // Validate required fields
            $required_fields = ['description', 'payment_method', 'transaction_type', 'amount', 'transaction_date'];
            foreach ($required_fields as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Field '{$field}' is required");
                }
            }
            
            // Validate amount
            if (!is_numeric($data['amount']) || $data['amount'] <= 0) {
                throw new Exception("Amount must be a positive number");
            }
            
            // Validate transaction type
            if (!in_array($data['transaction_type'], ['income', 'expenditure'])) {
                throw new Exception("Invalid transaction type");
            }
            
            // Validate payment method
            $valid_methods = ['Cash', 'Bank Transfer', 'Mobile Money', 'Cheque', 'Card'];
            if (!in_array($data['payment_method'], $valid_methods)) {
                throw new Exception("Invalid payment method");
            }
            
            // Validate date format
            if (!$this->validateDate($data['transaction_date'])) {
                throw new Exception("Invalid date format");
            }
            
            $sql = "UPDATE transactions SET 
                    description = ?, 
                    payment_method = ?, 
                    transaction_type = ?, 
                    amount = ?, 
                    transaction_date = ?
                    WHERE id = ?";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("sssdsi", 
                $data['description'],
                $data['payment_method'],
                $data['transaction_type'],
                $data['amount'],
                $data['transaction_date'],
                $id
            );
            
            if ($stmt->execute()) {
                $affected_rows = $stmt->affected_rows;
                $stmt->close();
                
                if ($affected_rows > 0) {
                    return [
                        'success' => true,
                        'message' => 'Transaction updated successfully'
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Transaction not found or no changes made'
                    ];
                }
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Delete a transaction
     */
    public function deleteTransaction($id) {
        try {
            if (!is_numeric($id) || $id <= 0) {
                throw new Exception("Invalid transaction ID");
            }
            
            $sql = "DELETE FROM transactions WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $affected_rows = $stmt->affected_rows;
                $stmt->close();
                
                if ($affected_rows > 0) {
                    return [
                        'success' => true,
                        'message' => 'Transaction deleted successfully'
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Transaction not found'
                    ];
                }
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get transactions by date
     */
    public function getTransactionsByDate($date) {
        try {
            if (!$this->validateDate($date)) {
                throw new Exception("Invalid date format");
            }
            
            $sql = "SELECT id, description, payment_method, transaction_type, amount, transaction_date, 
                           created_at, updated_at 
                    FROM transactions 
                    WHERE transaction_date = ? 
                    ORDER BY created_at DESC";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("s", $date);
            
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                $transactions = [];
                
                while ($row = $result->fetch_assoc()) {
                    $transactions[] = [
                        'id' => (int)$row['id'],
                        'description' => $row['description'],
                        'paymentMethod' => $row['payment_method'],
                        'type' => $row['transaction_type'],
                        'amount' => (float)$row['amount'],
                        'date' => $row['transaction_date'],
                        'created_at' => $row['created_at'],
                        'updated_at' => $row['updated_at']
                    ];
                }
                
                $stmt->close();
                
                return [
                    'success' => true,
                    'transactions' => $transactions
                ];
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'transactions' => []
            ];
        }
    }
    
    /**
     * Get summary statistics for a specific date
     */
    public function getSummaryByDate($date) {
        try {
            if (!$this->validateDate($date)) {
                throw new Exception("Invalid date format");
            }
            
            $sql = "SELECT 
                        transaction_type,
                        SUM(amount) as total_amount,
                        COUNT(*) as transaction_count
                    FROM transactions 
                    WHERE transaction_date = ? 
                    GROUP BY transaction_type";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("s", $date);
            
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                
                $summary = [
                    'income' => ['amount' => 0, 'count' => 0],
                    'expenditure' => ['amount' => 0, 'count' => 0],
                    'balance' => 0
                ];
                
                while ($row = $result->fetch_assoc()) {
                    $summary[$row['transaction_type']] = [
                        'amount' => (float)$row['total_amount'],
                        'count' => (int)$row['transaction_count']
                    ];
                }
                
                $summary['balance'] = $summary['income']['amount'] - $summary['expenditure']['amount'];
                
                $stmt->close();
                
                return [
                    'success' => true,
                    'summary' => $summary
                ];
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate date format (YYYY-MM-DD)
     */
    private function validateDate($date, $format = 'Y-m-d') {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
}

// Handle the request
try {
    // Check if database connection exists
    if (!isset($conn) || !$conn) {
        throw new Exception("Database connection not available");
    }
    
    $handler = new TransactionHandler($conn);
    $method = $_SERVER['REQUEST_METHOD'];
    $response = ['success' => false, 'message' => 'Invalid request'];
    
    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                $input = $_POST;
            }
            
            if (isset($input['action'])) {
                switch ($input['action']) {
                    case 'add':
                        $response = $handler->addTransaction($input);
                        break;
                        
                    case 'update':
                        if (!isset($input['id'])) {
                            $response = ['success' => false, 'message' => 'Transaction ID is required'];
                        } else {
                            $response = $handler->updateTransaction($input['id'], $input);
                        }
                        break;
                        
                    case 'delete':
                        if (!isset($input['id'])) {
                            $response = ['success' => false, 'message' => 'Transaction ID is required'];
                        } else {
                            $response = $handler->deleteTransaction($input['id']);
                        }
                        break;
                        
                    default:
                        $response = ['success' => false, 'message' => 'Invalid action'];
                        break;
                }
            } else {
                $response = ['success' => false, 'message' => 'Action is required'];
            }
            break;
            
        case 'GET':
            if (isset($_GET['date'])) {
                $date = $_GET['date'];
                
                if (isset($_GET['summary']) && $_GET['summary'] == '1') {
                    $response = $handler->getSummaryByDate($date);
                } else {
                    $response = $handler->getTransactionsByDate($date);
                }
            } else {
                $response = ['success' => false, 'message' => 'Date parameter is required'];
            }
            break;
            
        default:
            $response = ['success' => false, 'message' => 'Method not allowed'];
            break;
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>