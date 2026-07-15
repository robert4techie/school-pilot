<?php
require_once 'conn.php';

$receipt_number = isset($_GET['receipt']) ? $_GET['receipt'] : '';
$payment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (empty($receipt_number) || $payment_id <= 0) {
    die('Invalid verification parameters');
}

// Fetch payment data for verification
$query = "SELECT fp.*, CONCAT(s.first_name, ' ', s.last_name) as student_name, 
          s.current_class, s.stream, s.student_id as admission_number
          FROM fees_payments fp
          JOIN students s ON fp.student_id = s.student_id
          WHERE fp.id = $payment_id AND fp.receipt_number = '$receipt_number'";

$result = mysqli_query($conn, $query);
$payment = mysqli_fetch_assoc($result);

if (!$payment) {
    die('Receipt not found or invalid');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode/1.5.3/qrcode.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400..900&family=Quicksand:wght@300..700&display=swap" rel="stylesheet">
    <title>Receipt Verification - <?php echo $payment['receipt_number']; ?></title>
    <style>
        body {
           font-family: "Quicksand", sans-serif;
            max-width: 600px;
            margin: 20px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .verification-card {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .verified-badge {
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border-radius: 20px;
            text-align: center;
            margin-bottom: 20px;
            font-weight: bold;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .detail-label {
            font-weight: bold;
            color: #333;
        }
        .detail-value {
            color: #666;
        }
        .amount {
            font-size: 1.2em;
            color: #28a745;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="verification-card">
        <div class="verified-badge">
            ✓ VERIFIED RECEIPT
        </div>
        
        <h2>Receipt Details</h2>
        
        <div class="detail-row">
            <span class="detail-label">Receipt Number:</span>
            <span class="detail-value"><?php echo $payment['receipt_number']; ?></span>
        </div>
        
        <div class="detail-row">
            <span class="detail-label">Student Name:</span>
            <span class="detail-value"><?php echo $payment['student_name']; ?></span>
        </div>
        
        <div class="detail-row">
            <span class="detail-label">Class:</span>
            <span class="detail-value"><?php echo $payment['current_class']; ?></span>
        </div>
        
        <div class="detail-row">
            <span class="detail-label">Student ID:</span>
            <span class="detail-value"><?php echo $payment['admission_number']; ?></span>
        </div>
        
        <div class="detail-row">
            <span class="detail-label">Term:</span>
            <span class="detail-value"><?php echo $payment['term']; ?></span>
        </div>
        
        <div class="detail-row">
            <span class="detail-label">Academic Year:</span>
            <span class="detail-value"><?php echo $payment['year']; ?></span>
        </div>
        
        <div class="detail-row">
            <span class="detail-label">Payment Date:</span>
            <span class="detail-value"><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></span>
        </div>
        
        <div class="detail-row">
            <span class="detail-label">Amount Paid:</span>
            <span class="detail-value amount">UGX <?php echo number_format($payment['amount_paid']); ?></span>
        </div>
        
        <div class="detail-row">
            <span class="detail-label">Depositor:</span>
            <span class="detail-value"><?php echo $payment['depositor_name']; ?></span>
        </div>
        
        <div style="margin-top: 20px; text-align: center; color: #666; font-size: 0.9em;">
            This receipt has been verified as authentic.<br>
            Verified on: <?php echo date('d/m/Y H:i:s'); ?>
        </div>
    </div>
</body>
</html>