<?php
require_once 'conn.php';

$message = "";
$status_class = "";
$receipt_details = null;

// Check if id and receipt are present in the URL
if (isset($_GET['id']) && isset($_GET['receipt'])) {
    $subscription_id = (int)$_GET['id'];
    $receipt_number = $_GET['receipt'];

    if ($subscription_id > 0) {
        // Prepare and execute the SQL query to get the subscription details
        $sql = "SELECT * FROM school_subscriptions WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $subscription_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $subscription = $result->fetch_assoc();
        $stmt->close();

        if ($subscription) {
            // Recalculate the receipt number to verify
            $generated_receipt_number = 'SP-' . str_pad($subscription['id'], 6, '0', STR_PAD_LEFT);

            if ($generated_receipt_number === $receipt_number) {
                // Receipt is valid
                $message = "This receipt is VALID.";
                $status_class = "success";
                $receipt_details = $subscription;
            } else {
                // Receipt number mismatch
                $message = "Receipt verification failed. The receipt number is incorrect.";
                $status_class = "error";
            }
        } else {
            // Subscription not found
            $message = "Receipt verification failed. The provided ID does not match any record.";
            $status_class = "error";
        }
    } else {
        // Invalid ID provided
        $message = "Invalid subscription ID.";
        $status_class = "error";
    }
} else {
    // Missing parameters
    $message = "Invalid URL. Please provide both 'id' and 'receipt' parameters.";
    $status_class = "error";
}

// Function to format the amount
function formatAmount($amount, $currency)
{
    return number_format($amount, 2) . ' ' . $currency;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt Verification</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400..900&family=Quicksand:wght@300..700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: "Quicksand", sans-serif;
        }

        .container-card {
            max-width: 500px;
            margin: 40px auto;
        }

        .status-badge {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .status-badge.success {
            color: #10B981;
        }

        .status-badge.error {
            color: #EF4444;
        }
    </style>
</head>

<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="container-card bg-white p-8 rounded-2xl shadow-lg border border-gray-200">
        <div class="flex flex-col items-center text-center">
            <div class="mb-4">
                <?php if ($status_class === "success"): ?>
                    <i class="fas fa-check-circle text-6xl text-green-500"></i>
                <?php else: ?>
                    <i class="fas fa-times-circle text-6xl text-red-500"></i>
                <?php endif; ?>
            </div>
            <h1 class="text-3xl font-bold mb-2 text-gray-900">Receipt Verification</h1>
            <p class="text-xl font-medium status-badge <?php echo $status_class; ?> mb-6">
                <?php echo htmlspecialchars($message); ?>
            </p>
        </div>

        <?php if ($receipt_details): ?>
            <div class="border-t border-gray-200 pt-6">
                <h2 class="text-2xl font-semibold mb-4 text-gray-800">Receipt Details</h2>
                <div class="space-y-4">
                    <div class="flex justify-between items-center bg-gray-50 p-4 rounded-lg">
                        <span class="text-gray-600 font-medium">Receipt Number:</span>
                        <span class="text-gray-900 font-bold"><?php echo htmlspecialchars($_GET['receipt']); ?></span>
                    </div>
                    <div class="flex justify-between items-center p-4 rounded-lg">
                        <span class="text-gray-600 font-medium">School Name:</span>
                        <span class="text-gray-900 font-bold"><?php echo htmlspecialchars($receipt_details['school_name']); ?></span>
                    </div>
                    <div class="flex justify-between items-center bg-gray-50 p-4 rounded-lg">
                        <span class="text-gray-600 font-medium">Subscription Term:</span>
                        <span class="text-gray-900 font-bold">
                            <?php
                            $termDisplay = $receipt_details['subscription_term'] === 'year' ? 'Full Year' : 'Term ' . $receipt_details['subscription_term'];
                            echo htmlspecialchars($termDisplay . ' - ' . $receipt_details['subscription_year']);
                            ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center p-4 rounded-lg">
                        <span class="text-gray-600 font-medium">Payment Date:</span>
                        <span class="text-gray-900 font-bold"><?php echo date('F j, Y', strtotime($receipt_details['created_at'])); ?></span>
                    </div>
                    <div class="flex justify-between items-center bg-gray-50 p-4 rounded-lg">
                        <span class="text-gray-600 font-medium">Amount Paid:</span>
                        <span class="text-gray-900 font-bold"><?php echo formatAmount($receipt_details['amount_paid'], $receipt_details['payment_currency']); ?></span>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>