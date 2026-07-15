<?php
require_once '../auth.php';
require_once '../conn.php';
require_once '../tracking.php';
$tracker->trackAction("School Fees Receipt");

// Get payment ID from URL
$payment_id = isset($_GET['payment_id']) ? intval($_GET['payment_id']) : 0;

if ($payment_id <= 0) {
    die('Invalid payment ID');
}

// Fetch school profile data
$school_query = "SELECT * FROM school_profile LIMIT 1";
$school_result = mysqli_query($conn, $school_query);
$school_info = mysqli_fetch_assoc($school_result);

if (!$school_info) {
    die('School profile not found. Please configure school profile first.');
}

// Fetch payment data
$query = "SELECT fp.*, CONCAT(s.first_name, ' ', s.last_name) as student_name, 
          s.current_class, s.stream, s.student_id as admission_number
          FROM fees_payments fp
          JOIN students s ON fp.student_id = s.student_id
          WHERE fp.id = $payment_id";

$result = mysqli_query($conn, $query);
$payment = mysqli_fetch_assoc($result);

if (!$payment) {
    die('Payment not found');
}

// Fetch all payments for this student in this term/year to show payment history
$history_query = "SELECT * FROM fees_payments 
                 WHERE student_id = '{$payment['student_id']}' 
                 AND term = '{$payment['term']}' 
                 AND year = {$payment['year']}
                 ORDER BY payment_date ASC";
$history_result = mysqli_query($conn, $history_query);
$payment_history = [];
$running_balance = $payment['amount_to_pay'];

while ($row = mysqli_fetch_assoc($history_result)) {
    $running_balance -= $row['amount_paid'];
    $payment_history[] = [
        'date' => date('d/m/Y', strtotime($row['payment_date'])),
        'amount' => $row['amount_paid'],
        'balance' => max(0, $running_balance),
        'depositor' => $row['depositor_name'],
        'contact' => $row['depositor_contact']
    ];
}

// Calculate totals
$total_paid = array_sum(array_column($payment_history, 'amount'));
$receipt_id_for_qr = htmlspecialchars($payment['receipt_number']);
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
$verification_url = $baseUrl . "/verify-receipt.php?receipt=" . urlencode($payment['receipt_number']) . "&id=" . $payment['id'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Fees Receipt - <?php echo $payment['receipt_number']; ?></title>
     <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400..800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs/qrcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        * {
            font-family: "Sen", sans-serif !important;

        }

        body {
            background-color: #e9ebee;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
            margin: 0;
            padding: 40px 20px;
        }

        .a4-sheet {
            background-color: #ffffff;
            width: 210mm;
            min-height: 297mm;
            padding: 15mm;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            box-sizing: border-box;
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            opacity: 0.05;
            z-index: 1;
        }

        .receipt-content {
            position: relative;
            z-index: 2;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .receipt-header {
            text-align: center;
            border-bottom: 3px double #145a32;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .school-logo {
            max-width: 100px;
            max-height: 100px;
            margin-bottom: 8px;
        }

        .school-name {
            font-family: 'Playfair Display', serif;
            font-size: 24px;
            font-weight: 700;
            color: #145a32;
            margin: 0;
            text-transform: uppercase;
        }

        .school-details {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
            line-height: 1.6;
        }

        .receipt-title {
            text-align: center;
            margin-bottom: 15px;
        }

        .receipt-title h2 {
            display: inline-block;
            background-color: #1e8449;
            color: #ffffff;
            padding: 6px 25px;
            border-radius: 5px;
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .receipt-meta {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: #666;
            margin-bottom: 15px;
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
        }

        .receipt-meta strong {
            color: #145a32;
        }

        /* Updated details-grid to be more compact - 2 lines max */
        .details-grid {
            display: flex;
            flex-direction: column;
            gap: 8px;
            font-size: 13px;
            margin-bottom: 15px;
            padding: 12px;
            border-radius: 5px;
        }

        .details-row {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: nowrap;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .detail-item.full-width {
            flex: 1;
        }

        .detail-item strong {
            color: #145a32;
            font-size: 11px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .detail-item span {
            color: #333;
            font-size: 12px;
        }

        .fee-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 15px;
        }

        .fee-box {
            background: linear-gradient(135deg, #f8f9fa, #e8f5e8);
            border: 2px solid #1e8449;
            border-radius: 8px;
            padding: 10px;
            text-align: center;
        }

        .fee-label {
            font-size: 10px;
            color: #145a32;
            font-weight: bold;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .fee-amount {
            font-size: 16px;
            font-weight: bold;
            color: #1a3d1f;
        }

        .payment-history-section {
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 15px;
            font-weight: bold;
            color: #145a32;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 2px solid #1e8449;
        }

        .payment-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            overflow: hidden;
        }

        .payment-table th {
            background: linear-gradient(135deg, #145a32, #1e8449);
            color: white;
            padding: 8px;
            text-align: left;
            font-weight: 600;
            font-size: 11px;
        }

        .payment-table td {
            padding: 8px;
            border-bottom: 1px solid #e0e0e0;
        }

        .payment-table tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .payment-table tbody tr:hover {
            background-color: #e8f5e8;
        }

        .signatures-section {
            display: flex;
            justify-content: space-between;
            margin-top: 25px;
            padding-top: 15px;
            border-top: 1px solid #ccc;
        }

        .signature-box {
            text-align: center;
            width: 45%;
        }

        .signature-line {
            border-bottom: 1px solid #333;
            height: 25px;
            margin-bottom: 5px;
        }

        .signature-box strong {
            font-size: 11px;
            color: #333;
        }

        /* QR section moved to footer - positioned on the right */
        .qr-section {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
            padding: 10px;
            border-radius: 5px;
            margin-top: auto;
        }

        #qrcode {
            width: 100px;
            height: 100px;
            box-shadow: 0 0 5px rgba(0, 0, 0, 0.2);
            background: white;
        }

        #qrcode canvas,
        #qrcode img {
            width: 100% !important;
            height: 100% !important;
        }

        .qr-text {
            text-align: center;
        }

        .qr-text p {
            font-size: 11px;
            color: #666;
            margin: 0;
            line-height: 1.4;
        }

        .qr-text p:first-child {
            font-weight: 700;
            color: #145a32;
            font-size: 12px;
            margin-bottom: 3px;
        }

        .receipt-footer {
            background-color: #f8f9fa;
            padding: 10px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 2px solid #145a32;
            border-radius: 5px;
            margin-top: 5px;
            /* Change this back to a standard margin */
        }

        .print-info {
            font-size: 9px;
            color: #999;
            text-align: center;
            margin-top: 8px;
            font-style: italic;
        }

        .print-button-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }

        .print-button {
            background-color: #1e8449;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background-color 0.3s;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .print-button:hover {
            background-color: #145a32;
        }

        .print-button span {
            font-size: 20px;
        }

        .print-button:hover {
            background-color: #145a32;
        }

        @media print {
            @page {
                size: A4;
                margin: 0;
            }

            body {
                background-color: #ffffff;
                padding: 0;
                margin: 0;
            }

            .a4-sheet {
                box-shadow: none;
                margin: 0;
                width: 210mm;
                min-height: 297mm;
                padding: 15mm;
            }

            .print-button-container {
                display: none;
            }

            .watermark {
                opacity: 0.08;
            }
        }
    </style>
</head>

<body>

    <div class="print-button-container">
        <button class="print-button" onclick="printReceipt()">
            Print Receipt
        </button>
    </div>

    <div class="a4-sheet" id="receipt-container">
        <div class="watermark">
            <?php if (!empty($school_info['logo_path']) && file_exists($school_info['logo_path'])): ?>
                <img src="<?php echo htmlspecialchars($school_info['logo_path']); ?>" alt="School Watermark" width="300">
            <?php endif; ?>
        </div>

        <div class="receipt-content">

            <div class="receipt-header">
                <?php if (!empty($school_info['logo_path']) && file_exists($school_info['logo_path'])): ?>
                    <img src="<?php echo htmlspecialchars($school_info['logo_path']); ?>" alt="School Logo" class="school-logo">
                <?php endif; ?>
                <div>
                    <h1 class="school-name"><?php echo htmlspecialchars($school_info['school_name']); ?></h1>
                    <div class="school-details">
                        <?php echo htmlspecialchars($school_info['address']); ?>
                        <?php if (!empty($school_info['pobox'])): ?>
                            | P.O. Box <?php echo htmlspecialchars($school_info['pobox']); ?>
                        <?php endif; ?>
                        <br>
                        <?php
                        $contact_parts = [];
                        if (!empty($school_info['phone'])) {
                            $contact_parts[] = "☎ " . htmlspecialchars($school_info['phone']);
                        }
                        if (!empty($school_info['email'])) {
                            $contact_parts[] = "✉ " . htmlspecialchars($school_info['email']);
                        }
                        echo implode(' | ', $contact_parts);
                        ?>
                        <?php if (!empty($school_info['website'])): ?>
                            <br><?php echo htmlspecialchars($school_info['website']); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="receipt-title">
                <h2>Official Fee Receipt</h2>
            </div>

            <div class="receipt-meta">
                <div><strong>Receipt No:</strong> <?php echo htmlspecialchars($payment['receipt_number']); ?></div>
                <div><strong>Date:</strong> <?php echo date('d M Y', strtotime($payment['payment_date'])); ?></div>
            </div>

            <div class="details-grid">
                <div class="details-row">
                    <div class="detail-item full-width">
                        <strong>Student Name:</strong>
                        <span><?php echo htmlspecialchars($payment['student_name']); ?></span>
                    </div>
                    <div class="detail-item">
                        <strong>Student ID:</strong>
                        <span><?php echo htmlspecialchars($payment['admission_number']); ?></span>
                    </div>
                    <div class="detail-item">
                        <strong>Class:</strong>
                        <span><?php echo htmlspecialchars($payment['current_class']); ?> (<?php echo htmlspecialchars($payment['stream']); ?>)</span>
                    </div>
                   
                </div>
                <div class="details-row">
                     <div class="detail-item">
                        <strong>Term:</strong>
                        <span><?php echo htmlspecialchars($payment['term']); ?></span>
                    </div>

                    <div class="detail-item">
                        <strong>Academic Year:</strong>
                        <span><?php echo htmlspecialchars($payment['year']); ?></span>
                    </div>
                </div>
            </div>

            <div class="fee-summary">
                <div class="fee-box">
                    <div class="fee-label">Total Fees</div>
                    <div class="fee-amount">UGX <?php echo number_format($payment['fees_amount']); ?></div>
                </div>
                <div class="fee-box">
                    <div class="fee-label">Bursary Discount</div>
                    <div class="fee-amount">UGX <?php echo number_format($payment['bursary_discount']); ?></div>
                </div>
                <div class="fee-box">
                    <div class="fee-label">Amount to Pay</div>
                    <div class="fee-amount">UGX <?php echo number_format($payment['amount_to_pay']); ?></div>
                </div>
            </div>

            <div class="payment-history-section">
                <div class="section-title">Payment History</div>
                <table class="payment-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Fees Paid</th>
                            <th>Balance</th>
                            <th>Depositor</th>
                            <th>Contact</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payment_history as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['date']); ?></td>
                                <td>UGX <?php echo number_format($item['amount']); ?></td>
                                <td>UGX <?php echo number_format($item['balance']); ?></td>
                                <td><?php echo htmlspecialchars($item['depositor']); ?></td>
                                <td><?php echo htmlspecialchars($item['contact']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="signatures-section">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <strong>Student/Parent Signature</strong>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <strong>Bursar's Signature</strong>
                </div>
            </div>

            <div class="qr-section">
                <div id="qrcode"></div>
                <div class="qr-text">
                    <p>SCAN TO VERIFY</p>
                </div>
            </div>

            <div class="receipt-footer">
                Thank you for your payment. Please keep this receipt for your records.<br>
                For any queries, contact the school accounts office during business hours.
            </div>

            <div class="print-info">
                <span id="print-timestamp">Printed on: <?php echo date('l, F j, Y \a\t g:i A'); ?></span>
            </div>
        </div>
    </div>

    <script>
        function updatePrintTimestamp() {
            const now = new Date();
            const options = {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            };
            const timestamp = now.toLocaleDateString('en-US', options);
            document.getElementById('print-timestamp').textContent = `Printed on: ${timestamp}`;
        }

        document.addEventListener('DOMContentLoaded', () => {
            const qrContainer = document.getElementById('qrcode');
            const verificationUrl = "<?php echo $verification_url; ?>";

            qrContainer.innerHTML = '';

            new QRCode(qrContainer, {
                text: verificationUrl,
                width: 100,
                height: 100,
                correctLevel: QRCode.CorrectLevel.H,
                colorDark: "#145a32",
                colorLight: "#ffffff"
            });

            updatePrintTimestamp();
        });

        function printReceipt() {
            updatePrintTimestamp();
            window.print();
        }
    </script>
</body>

</html>