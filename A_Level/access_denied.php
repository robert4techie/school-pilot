<?php
// Include necessary files to maintain session and navigation
require_once '../auth.php';
require_once '../conn.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-g">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --danger-color: #f44336;
            --danger-light: #ffebee;
            --danger-dark: #c62828;
            --primary-color: #1e8449;
            --text-color: #333;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        body {
            background-color: #f9fafb;
            color: var(--text-color);
            line-height: 1.6;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            margin-top: 60px; /* Adjust margin as needed */
            padding: 20px;
        }
        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border-left: 5px solid var(--danger-dark);
            text-align: center;
            padding: 40px;
        }
        .card-icon {
            font-size: 50px;
            color: var(--danger-color);
            margin-bottom: 20px;
        }
        .card h1 {
            color: var(--danger-dark);
            margin-bottom: 15px;
            font-size: 24px;
        }
        .card p {
            font-size: 16px;
            color: #555;
            margin-bottom: 30px;
        }
        .btnn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 500;
            cursor: pointer;
            padding: 12px 24px;
            font-size: 16px;
            border-radius: var(--border-radius);
            border: none;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        .btnn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        .btnn-primary:hover {
            background-color: #145a32;
        }
    </style>
</head>
<body>
    <?php require_once '../nav.php'; // Includes your standard navigation bar ?>

    <div class="container">
        <div class="card">
            <div class="card-icon">
                <i class="fas fa-lock"></i>
            </div>
            <h1>Access Denied</h1>
            <p>You do not have permission to view this page because you are not assigned to teach the selected class or subject.</p>
            <p><strong>What to do next:</strong> Please go back and select a class, stream and subject you are assigned to teach. If you believe this is an error, contact the school administrator.</p>
            <a href="sel_add_marks.php" class="btnn btnn-primary">
                <i class="fas fa-arrow-left"></i> Go Back to Selection
            </a>
        </div>
    </div>
</body>
</html>