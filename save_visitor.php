<?php
header('Content-Type: application/json');
require_once 'conn.php';
require_once 'tracking.php';
require_once 'auth.php';



// Function to sanitize input data
function sanitizeInput($data)
{
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => ''];

    try {
        // Validate and sanitize inputs
        $firstName = sanitizeInput($_POST['first_name'] ?? '');
        $lastName = sanitizeInput($_POST['last_name'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $phone = sanitizeInput($_POST['phone'] ?? '');
        $company = sanitizeInput($_POST['company'] ?? '');
        $visitPurpose = sanitizeInput($_POST['visit_purpose'] ?? '');
        $host = sanitizeInput($_POST['host'] ?? '');
        $visitDate = sanitizeInput($_POST['visit_date'] ?? '');
        $numberPlate = sanitizeInput($_POST['number_plate'] ?? '');
        $address = sanitizeInput($_POST['address'] ?? '');

        // Validate required fields
        $errors = [];

        if (empty($firstName)) $errors[] = 'First name is required';
        if (empty($lastName)) $errors[] = 'Last name is required';
        if (empty($email)) $errors[] = 'Email is required';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format';
        if (empty($phone)) $errors[] = 'Phone number is required';
        if (empty($visitPurpose)) $errors[] = 'Purpose of visit is required';
        if (empty($host)) $errors[] = 'Host/person to visit is required';
        if (empty($visitDate)) $errors[] = 'Visit date and time is required';
        if (empty($address)) $errors[] = 'Address is required';

        if (!empty($errors)) {
            throw new Exception(implode('<br>', $errors));
        }

        // Prepare SQL statement
        $sql = "INSERT INTO visitors (
            first_name, last_name, email, phone, company, 
            visit_purpose, host, visit_date, number_plate, address, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception("Database error: " . $conn->error);
        }

        $stmt->bind_param(
            "ssssssssss",
            $firstName,
            $lastName,
            $email,
            $phone,
            $company,
            $visitPurpose,
            $host,
            $visitDate,
            $numberPlate,
            $address
        );

        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Visitor information saved successfully!';
        } else {
            throw new Exception("Failed to save visitor: " . $stmt->error);
        }

        $stmt->close();
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }

    echo json_encode($response);
    exit();
}

// Close database connection
$conn->close();
?>