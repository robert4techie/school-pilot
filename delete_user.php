<?php
require_once 'conn.php';
require_once 'auth.php';

// Initialize notification variables
$notification = null;
$notificationType = null;

// Handle form submission
if (isset($_POST['delete_user'])) {
    $username = $_POST['username'];

    // Check if the username exists
    $sql_check = "SELECT * FROM users WHERE user_name = ?";
    $stmt_check = $conn->prepare($sql_check);
    if (!$stmt_check) {
        $notification = "Error preparing check statement: " . $conn->error;
        $notificationType = "error";
    } else {
        $stmt_check->bind_param("s", $username);
        $stmt_check->execute();
        $result = $stmt_check->get_result();

        if ($result->num_rows > 0) {
            // Delete related records from user_logs first
            $sql_delete_logs = "DELETE FROM user_logs WHERE user_id = (SELECT user_id FROM users WHERE user_name = ?)";
            $stmt_delete_logs = $conn->prepare($sql_delete_logs);
            if (!$stmt_delete_logs) {
                $notification = "Error preparing delete logs statement: " . $conn->error;
                $notificationType = "error";
            } else {
                $stmt_delete_logs->bind_param("s", $username);
                $stmt_delete_logs->execute();
                $stmt_delete_logs->close();

                // Now delete the user from users table
                $sql_delete = "DELETE FROM users WHERE user_name = ?";
                $stmt_delete = $conn->prepare($sql_delete);
                if (!$stmt_delete) {
                    $notification = "Error preparing delete statement: " . $conn->error;
                    $notificationType = "error";
                } else {
                    $stmt_delete->bind_param("s", $username);

                    if ($stmt_delete->execute()) {
                        $notification = "User deleted successfully!";
                        $notificationType = "success";
                    } else {
                        $notification = "Error deleting user: " . $stmt_delete->error;
                        $notificationType = "error";
                    }
                    $stmt_delete->close();
                }
            }
        } else {
            $notification = "User not found.";
            $notificationType = "error";
        }
        $stmt_check->close();
    }
}

// Fetch all users from the database
$users = [];
$sql_users = "SELECT user_name FROM users ORDER BY user_name";
$result_users = $conn->query($sql_users);
if ($result_users && $result_users->num_rows > 0) {
    while ($row = $result_users->fetch_assoc()) {
        $users[] = $row['user_name'];
    }
}

// Set headers to prevent browser from caching the page
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete User | SchoolPilot</title>
    <!--favicon-->
    <link rel="shortcut icon" href="images/schoolcontrol_icon.png" type="image/x-icon">
    <link rel="stylesheet" href="settings.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #2E7D32;
            --primary-light: #4CAF50;
            --primary-dark: #1B5E20;
            --accent-color: #8BC34A;
            --text-on-primary: #FFFFFF;
            --surface-color: #FFFFFF;
            --error-color: #D32F2F;
            --success-color: #388E3C;
            --warning-color: #FFA000;
            --background-color: #E8F5E9;
        }

        * {
            padding: 0;
            margin: 0;
            box-sizing: border-box;
           
        }

        body {
            background-color: var(--background-color);
            color: #333;
        }

        .container {
            max-width: 800px;
            margin: 80px auto;
            padding: 30px;
            background-color: var(--surface-color);
            border-radius: 12px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .container:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .container h2 {
            margin-bottom: 25px;
            text-align: center;
            color: var(--primary-dark);
            font-size: 24px;
            font-weight: 600;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--accent-color);
        }

        .form-group {
            margin-bottom: 25px;
        }

        .container label {
            display: block;
            margin-bottom: 10px;
            font-weight: 500;
            color: var(--primary-dark);
        }

        .select-wrapper {
            position: relative;
            margin-bottom: 25px;
        }

        .select-wrapper::after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-dark);
            pointer-events: none;
        }

        .container select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background-color: #f9f9f9;
            font-size: 16px;
            color: #333;
            cursor: pointer;
            appearance: none;
            transition: all 0.3s ease;
        }

        .container select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(46, 125, 50, 0.2);
            background-color: white;
        }

        .container button {
            width: 100%;
            padding: 14px 10px;
            background-color: var(--primary-color);
            color: var(--text-on-primary);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            transition: background-color 0.3s ease, transform 0.2s ease;
        }

        .container button:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .container button:active {
            transform: translateY(0);
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            transform: translateX(120%);
            transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            max-width: 350px;
        }

        .notification.show {
            transform: translateX(0);
        }

        .notification.success {
            background-color: var(--success-color);
        }

        .notification.error {
            background-color: var(--error-color);
        }

        .notification.warning {
            background-color: var(--warning-color);
        }

        .notification i {
            font-size: 20px;
        }

        .no-users {
            text-align: center;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 8px;
            color: #666;
            font-style: italic;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .container {
            animation: fadeIn 0.5s ease-out;
        }
    </style>
</head>

<body>
    <?php include 'nav.php' ?>

    <div class="container">
        <h2><i class="fas fa-user-minus"></i> Delete User</h2>
        <form id="deleteUserForm" action="" method="POST">
            <div class="form-group">
                <label for="username"><i class="fas fa-user"></i> Select User to Delete:</label>
                <div class="select-wrapper">
                    <?php if (count($users) > 0): ?>
                        <select id="username" name="username" required>
                            <option value="" disabled selected>-- Select a user --</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo htmlspecialchars($user); ?>"><?php echo htmlspecialchars($user); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <div class="no-users">No users available in the system</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (count($users) > 0): ?>
                <button type="submit" name="delete_user"><i class="fas fa-trash-alt"></i> Delete User</button>
            <?php endif; ?>
        </form>
    </div>

    <!-- Notification container -->
    <div id="notification" class="notification">
        <i id="notification-icon" class="fas"></i>
        <span id="notification-message"></span>
    </div>

    <!-- Sound effects -->
    <audio id="success-sound" src="sounds/success.mp3" preload="auto"></audio>
    <audio id="error-sound" src="sounds/error.wav" preload="auto"></audio>

    <script>
        // Function to show notifications
        function showNotification(message, type) {
            const notification = document.getElementById('notification');
            const notificationMessage = document.getElementById('notification-message');
            const notificationIcon = document.getElementById('notification-icon');
            
            notificationMessage.textContent = message;
            
            // Remove all classes and add the appropriate ones
            notification.className = 'notification';
            notification.classList.add(type);
            
            // Set the appropriate icon
            if (type === 'success') {
                notificationIcon.className = 'fas fa-check-circle';
                document.getElementById('success-sound').play();
            } else if (type === 'error') {
                notificationIcon.className = 'fas fa-times-circle';
                document.getElementById('error-sound').play();
            } else if (type === 'warning') {
                notificationIcon.className = 'fas fa-exclamation-triangle';
                document.getElementById('error-sound').play();
            }
            
            // Show the notification
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            
            // Hide the notification after 5 seconds
            setTimeout(() => {
                notification.classList.remove('show');
            }, 5000);
        }

        // Show notification if set from PHP
        <?php if ($notification): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showNotification('<?php echo addslashes($notification); ?>', '<?php echo $notificationType; ?>');
            });
        <?php endif; ?>

        // Confirm deletion before submitting
        document.getElementById('deleteUserForm').addEventListener('submit', function(event) {
            const usernameSelect = document.getElementById('username');
            const username = usernameSelect.options[usernameSelect.selectedIndex].text;
            
            const confirmDelete = confirm(`Are you sure you want to delete the user '${username}'? This action cannot be undone.`);
            
            if (!confirmDelete) {
                event.preventDefault(); // Prevent form submission if the user cancels
            }
        });
    </script>
</body>

</html>