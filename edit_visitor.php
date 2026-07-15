<?php
require_once 'conn.php';
require_once 'auth.php';

// Set timezone
date_default_timezone_set('Africa/Kampala');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $visitorId = $_POST['id'];
    $firstName = $_POST['first_name'];
    $lastName = $_POST['last_name'];
    $company = $_POST['company'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $address = $_POST['address'];
    $numberPlate = $_POST['number_plate'];
    $visitPurpose = $_POST['visit_purpose'];
    $host = $_POST['host'];
    $visitDate = $_POST['visit_date'];

    $stmt = $conn->prepare("UPDATE visitors SET 
        first_name = ?, 
        last_name = ?, 
        company = ?, 
        email = ?, 
        phone = ?, 
        address = ?, 
        number_plate = ?, 
        visit_purpose = ?, 
        host = ?, 
        visit_date = ? 
        WHERE id = ?");
    
    $stmt->bind_param("ssssssssssi", 
        $firstName, 
        $lastName, 
        $company, 
        $email, 
        $phone, 
        $address, 
        $numberPlate, 
        $visitPurpose, 
        $host, 
        $visitDate, 
        $visitorId);

    if ($stmt->execute()) {
        $_SESSION['notification'] = [
            'type' => 'success',
            'message' => 'Visitor updated successfully!'
        ];
    } else {
        $_SESSION['notification'] = [
            'type' => 'error',
            'message' => 'Failed to update visitor: ' . $stmt->error
        ];
    }
    
    $stmt->close();
    header("Location: view_visitors.php");
    exit();
}

// Fetch visitor data for editing
$visitor = [];
if (isset($_GET['id'])) {
    $visitorId = $_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM visitors WHERE id = ?");
    $stmt->bind_param("i", $visitorId);
    $stmt->execute();
    $result = $stmt->get_result();
    $visitor = $result->fetch_assoc();
    $stmt->close();
}

if (!$visitor) {
    $_SESSION['notification'] = [
        'type' => 'error',
        'message' => 'Visitor not found'
    ];
    header("Location: view_visitors.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Visitor</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .edit-form-container {
            max-width: 800px;
            margin: 20px auto;
            margin-top: 40px;
            padding: 30px;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2E7D32;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #4CAF50;
            box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.2);
            outline: none;
        }
        
        .form-row {
            display: flex;
            gap: 20px;
        }
        
        .form-row .form-group {
            flex: 1;
        }
        
        .btn-submit {
            background: linear-gradient(to right, #4CAF50, #2E7D32);
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .btn-submit:hover {
            background: linear-gradient(to right, #2E7D32, #1B5E20);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(46, 125, 50, 0.3);
        }
    </style>
</head>
<body>
    <?php require_once 'nav.php'; ?>
    
    <div class="content-container main-container">
        <div class="page-header">
            <h3><i class="fas fa-user-edit"></i> Edit Visitor</h3>
            <a href="view_visitors.php" class="btn-success" style="text-decoration: none;">
                <i class="fas fa-arrow-left"></i> Back to Visitors
            </a>
        </div>
        
        <div class="edit-form-container">
            <form method="POST">
                <input type="hidden" name="id" value="<?php echo $visitor['id']; ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($visitor['first_name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($visitor['last_name']); ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="company">Company</label>
                        <input type="text" id="company" name="company" value="<?php echo htmlspecialchars($visitor['company'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($visitor['email'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($visitor['phone'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="number_plate">Vehicle Plate</label>
                        <input type="text" id="number_plate" name="number_plate" value="<?php echo htmlspecialchars($visitor['number_plate'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="address">Address</label>
                    <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($visitor['address'] ?? ''); ?>">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="visit_purpose">Visit Purpose</label>
                        <input type="text" id="visit_purpose" name="visit_purpose" value="<?php echo htmlspecialchars($visitor['visit_purpose']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="host">Host</label>
                        <input type="text" id="host" name="host" value="<?php echo htmlspecialchars($visitor['host']); ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="visit_date">Visit Date</label>
                    <input type="date" id="visit_date" name="visit_date" value="<?php echo htmlspecialchars($visitor['visit_date']); ?>" required>
                </div>
                
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Update Visitor
                </button>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        $(document).ready(function() {
            // Initialize date picker
            flatpickr("#visit_date", {
                dateFormat: "Y-m-d",
                allowInput: true
            });
        });
    </script>
</body>
</html>