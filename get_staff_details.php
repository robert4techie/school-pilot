<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once 'conn.php';

try {
    // Check if connection exists and is valid
    if (!$conn) {
        throw new Exception("Database connection failed");
    }

    // Prepare the SQL query to fetch all staff
    $sql = "SELECT 
                id,
                first_name,
                last_name,
                date_of_birth,
                gender,
                phone_number,
                nationality,
                email,
                address,
                marital_status,
                profile_photo,
                designation,
                department,
                joining_date,
                employment_type,
                qualifications,
                experience,
                tin_number,
                nssf_number,
                national_id,
                staff_id,
                created_at
            FROM staff 
            ORDER BY first_name ASC, last_name ASC";

    $result = mysqli_query($conn, $sql);
    
    if (!$result) {
        throw new Exception("Query failed: " . mysqli_error($conn));
    }

    $staff = array();
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Clean and format the data
        $staff_member = array(
            'id' => $row['id'],
            'first_name' => htmlspecialchars($row['first_name']),
            'last_name' => htmlspecialchars($row['last_name']),
            'date_of_birth' => $row['date_of_birth'],
            'gender' => htmlspecialchars($row['gender']),
            'phone_number' => htmlspecialchars($row['phone_number']),
            'nationality' => htmlspecialchars($row['nationality']),
            'email' => htmlspecialchars($row['email']),
            'address' => htmlspecialchars($row['address']),
            'marital_status' => htmlspecialchars($row['marital_status'] ?? ''),
            'profile_photo' => htmlspecialchars($row['profile_photo'] ?? ''),
            'designation' => htmlspecialchars($row['designation']),
            'department' => htmlspecialchars($row['department']),
            'joining_date' => $row['joining_date'],
            'employment_type' => htmlspecialchars($row['employment_type']),
            'qualifications' => htmlspecialchars($row['qualifications']),
            'experience' => $row['experience'],
            'tin_number' => htmlspecialchars($row['tin_number'] ?? ''),
            'nssf_number' => htmlspecialchars($row['nssf_number'] ?? ''),
            'national_id' => htmlspecialchars($row['national_id']),
            'staff_id' => htmlspecialchars($row['staff_id']),
            'created_at' => $row['created_at']
        );
        
        $staff[] = $staff_member;
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'staff' => $staff,
        'total_count' => count($staff),
        'message' => 'Staff loaded successfully'
    ]);

} catch (Exception $e) {
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'staff' => [],
        'total_count' => 0,
        'message' => $e->getMessage()
    ]);
} finally {
    // Close the database connection if it exists
    if (isset($conn) && $conn) {
        mysqli_close($conn);
    }
}
?>