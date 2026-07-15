<?php
require_once "auth.php";
require_once "conn.php";

header('Content-Type: application/json');

$response = ['success' => false, 'data' => null];

try {
    $query = "SELECT * FROM school_profile ORDER BY id DESC LIMIT 1";
    $result = mysqli_query($conn, $query);

    if ($result && mysqli_num_rows($result) > 0) {
        $response['success'] = true;
        $response['data'] = mysqli_fetch_assoc($result);
    } else {
        // Return default values if no records exist
        $response['success'] = true;
        $response['data'] = [
            'school_name' => 'Greenwood High School',
            'school_motto' => 'Excellence in Education',
            'address' => '123 Education Avenue, Greenwood City',
            'phone' => '(123) 456-7890',
            'email' => 'info@greenwood.edu',
            'website' => 'www.greenwood.edu',
            'pobox' => 'P.O. Box 1234, Greenwood',
            'next_term_date' => date('Y-m-d', strtotime('+1 month')),
            'logo_path' => 'https://via.placeholder.com/200x200?text=School+Logo'
        ];
    }
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
$conn->close();
?>