<?php
require_once 'conn.php';
require_once 'auth.php';

// Check if user is logged in and has permission
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['developer', 'admin'])) {
    header('Location: dashboard.php');
    exit();
}

// Get subscription ID from URL
$subscription_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$subscription_id) {
    header('Location: manage_subscriptions.php');
    exit();
}

// Get subscription details
$sql = "SELECT * FROM school_subscriptions WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $subscription_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: manage_subscriptions.php');
    exit();
}

$subscription = $result->fetch_assoc();
$stmt->close();

// Generate receipt number
$receipt_number = 'SP-' . str_pad($subscription['id'], 6, '0', STR_PAD_LEFT);
$verification_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . "/verify_subscription_receipt.php?id=" . $subscription['id'] . "&receipt=" . $receipt_number;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt #<?php echo $receipt_number; ?> - SchoolPilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400..900&family=Quicksand:wght@300..700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Quicksand", sans-serif;
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
            height: 297mm;
            padding: 15mm;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            box-sizing: border-box;
            position: relative;
            overflow: hidden;
        }

        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            opacity: 0.03;
            z-index: 1;
            font-size: 120px;
            font-weight: 700;
            color: #10b981;
            letter-spacing: 10px;
        }

        .receipt-content {
            position: relative;
            z-index: 2;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .receipt-header {
            text-align: center;
            border-bottom: 3px double #10b981;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .company-logo {
            font-size: 26px;
            font-weight: 700;
            color: #10b981;
            margin-bottom: 5px;
            letter-spacing: 1px;
        }

        .company-tagline {
            font-size: 12px;
            color: #666;
            margin: 3px 0;
            font-style: italic;
        }

        .company-details {
            font-size: 11px;
            color: #666;
            line-height: 1.5;
            margin-top: 5px;
        }

        .receipt-title {
            text-align: center;
            margin-bottom: 15px;
        }

        .receipt-title h2 {
            display: inline-block;
            background-color: #10b981;
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
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 15px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }

        .meta-item {
            text-align: center;
        }

        .meta-item strong {
            display: block;
            color: #10b981;
            font-size: 11px;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        .meta-item span {
            color: #333;
            font-size: 13px;
            font-weight: 600;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 25px;
            font-size: 14px;
            margin-bottom: 15px;
            flex: 1;
        }

        .detail-item {
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 6px;
        }

        .detail-item strong {
            color: #10b981;
            display: block;
            margin-bottom: 3px;
            font-size: 12px;
            text-transform: uppercase;
        }

        .detail-item span {
            color: #333;
            font-size: 13px;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .payment-summary {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(52, 211, 153, 0.1));
            border: 2px solid rgba(16, 185, 129, 0.3);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            text-align: center;
        }

        .payment-summary strong {
            display: block;
            color: #666;
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .total-amount {
            font-size: 32px;
            font-weight: 700;
            color: #10b981;
            margin-bottom: 8px;
        }

        .payment-status {
            background: #22c55e;
            color: white;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }

        .receipt-footer {
            margin-top: 15px;
            padding-top: 12px;
            border-top: 1px solid #ccc;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .signature-box {
            text-align: center;
            width: 45%;
        }

        .signature-line {
            border-bottom: 1px solid #333;
            height: 30px;
            margin-bottom: 4px;
        }

        .signature-box strong {
            font-size: 12px;
            color: #333;
        }

        .qr-section {
            text-align: center;
        }

        #qrcode {
            width: 140px;
            height: 140px;
            margin: 0 auto 8px;
            padding: 8px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            border-radius: 4px;
        }

        #qrcode canvas,
        #qrcode img {
            width: 100% !important;
            height: 100% !important;
            display: block;
        }

        .qr-section p {
            font-size: 10px;
            color: #666;
            margin: 0;
        }

        .verification-code {
            font-family: 'Courier New', monospace;
            font-size: 10px;
            color: #999;
            margin-top: 5px;
        }

        .thank-you-section {
            margin-top: 15px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 5px;
            text-align: center;
        }

        .thank-you-section h3 {
            color: #10b981;
            font-size: 18px;
            margin-bottom: 8px;
        }

        .thank-you-section p {
            font-size: 12px;
            color: #666;
            line-height: 1.5;
            margin-bottom: 10px;
        }

        .support-info {
            font-size: 11px;
            color: #999;
            padding: 10px;
            background: rgba(0, 0, 0, 0.02);
            border-radius: 5px;
        }

        .print-button-container {
            position: fixed;
            top: 20px;
            right: 20px;
            display: flex;
            gap: 10px;
            z-index: 1000;
        }

        .btn {
            background: #10b981;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn:hover {
            background: #059669;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #6b7280;
        }

        .btn-secondary:hover {
            background: #4b5563;
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
                height: 297mm;
                padding: 15mm;
                page-break-after: avoid;
                page-break-inside: avoid;
            }

            .print-button-container {
                display: none;
            }

            .watermark {
                opacity: 0.05;
            }

            .receipt-content {
                page-break-inside: avoid;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 10px;
            }

            .a4-sheet {
                width: 100%;
                height: auto;
            }

            .print-button-container {
                position: relative;
                top: auto;
                right: auto;
                justify-content: center;
                margin-bottom: 20px;
            }
        }
    </style>
</head>

<body>
    <div class="print-button-container">
        <button onclick="window.print()" class="btn">
            <i class="fas fa-print"></i> Print
        </button>
        <button onclick="downloadPDF()" class="btn">
            <i class="fas fa-download"></i> Download
        </button>
        <a href="manage_subscriptions.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <div class="a4-sheet">
        <div class="watermark">SCHOOLPILOT</div>

        <div class="receipt-content">
            <div class="receipt-header">
                <div class="company-logo">SchoolPilot Technologies</div>
                <div class="company-tagline">Smart School Management System</div>
                <div class="company-details">
                    Wakiso, Buloba<br>
                    Kampala, Uganda | +256 747 170 325<br>
                    schoolpilotug@gmail.com | www.schoolpilot.org
                </div>
            </div>

            <div class="receipt-title">
                <h2>Payment Receipt</h2>
            </div>

            <div class="receipt-meta">
                <div class="meta-item">
                    <strong>Receipt No.</strong>
                    <span><?php echo $receipt_number; ?></span>
                </div>
                <div class="meta-item">
                    <strong>Date</strong>
                    <span><?php echo date('d M Y', strtotime($subscription['created_at'])); ?></span>
                </div>
                <div class="meta-item">
                    <strong>Cashier</strong>
                    <span><?php echo htmlspecialchars($subscription['created_by']); ?></span>
                </div>
            </div>

            <div class="details-grid">
                <div class="detail-item full-width">
                    <strong>School Name:</strong>
                    <span><?php echo htmlspecialchars($subscription['school_name']); ?></span>
                </div>
                <div class="detail-item full-width">
                    <strong>School Domain:</strong>
                    <span><?php echo htmlspecialchars($subscription['school_domain']); ?></span>
                </div>
                <div class="detail-item">
                    <strong>Subscription Period:</strong>
                    <span>
                        <?php
                        $termDisplay = $subscription['subscription_term'] === 'year' ? 'Full Year' : 'Term ' . $subscription['subscription_term'];
                        echo $termDisplay . ' - ' . $subscription['subscription_year'];
                        ?>
                    </span>
                </div>
                <div class="detail-item">
                    <strong>Duration:</strong>
                    <span><?php echo $subscription['subscription_days']; ?> Days</span>
                </div>
                <div class="detail-item">
                    <strong>Start Date:</strong>
                    <span><?php echo date('d M Y', strtotime($subscription['subscription_start_date'])); ?></span>
                </div>
                <div class="detail-item">
                    <strong>End Date:</strong>
                    <span><?php echo date('d M Y', strtotime($subscription['subscription_end_date'])); ?></span>
                </div>
                <div class="detail-item">
                    <strong>Payment Method:</strong>
                    <span><?php echo htmlspecialchars($subscription['payment_method']); ?></span>
                </div>
                <?php if ($subscription['payment_reference']): ?>
                    <div class="detail-item">
                        <strong>Reference:</strong>
                        <span><?php echo htmlspecialchars($subscription['payment_reference']); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="payment-summary">
                <strong>Total Amount Paid</strong>
                <div class="total-amount">
                    <?php echo number_format($subscription['amount_paid'], 2); ?> <?php echo $subscription['payment_currency']; ?>
                </div>
                <div class="payment-status">PAID IN FULL</div>
            </div>

            <div class="receipt-footer">
                <!--<div class="signature-box">
                    <div class="signature-line"></div>
                    <strong>Authorized Signature</strong>
                </div>-->
                <div class="qr-section">
                    <div id="qrcode"></div>
                    <p>Scan to verify</p>
                    <div class="verification-code">ID: <?php echo substr(md5($receipt_number . $subscription['id']), 0, 8); ?></div>
                </div>
            </div>

            <div class="thank-you-section">
                <h3>Thank You for Your Payment!</h3>
                <p>Your subscription is now active. You can access all SchoolPilot features during your subscription period.</p>
                <div class="support-info">
                    For support or inquiries, contact us at<br>
                    <strong>schoolpilotug@gmail.com</strong> or <strong>+256 747 170 325 | +256 772 548 084 </strong>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.rawgit.com/davidshimjs/qrcodejs/gh-pages/qrcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

    <script>
        // Generate QR Code
        document.addEventListener('DOMContentLoaded', function() {
            new QRCode(document.getElementById("qrcode"), {
                text: "<?php echo $verification_url; ?>",
                width: 100,
                height: 100,
                colorDark: "#059669",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.H
            });
        });

        // Download PDF function
        function downloadPDF() {
            const {
                jsPDF
            } = window.jspdf;
            const receiptElement = document.querySelector('.a4-sheet');
            const printActions = document.querySelector('.print-button-container');

            printActions.style.display = 'none';

            html2canvas(receiptElement, {
                scale: 2,
                useCORS: true,
                backgroundColor: '#ffffff'
            }).then(canvas => {
                const imgData = canvas.toDataURL('image/png');
                const pdf = new jsPDF({
                    unit: 'mm',
                    format: 'a4',
                    orientation: 'portrait'
                });

                const imgProps = pdf.getImageProperties(imgData);
                const pdfWidth = pdf.internal.pageSize.getWidth();
                const pdfHeight = (imgProps.height * pdfWidth) / imgProps.width;

                pdf.addImage(imgData, 'PNG', 0, 0, pdfWidth, pdfHeight);
                pdf.save(`receipt-<?php echo $receipt_number; ?>.pdf`);

                printActions.style.display = 'flex';
            });
        }
    </script>
</body>

</html>