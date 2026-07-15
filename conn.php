<?php
$servername = "localhost";
$username = "";
$password = "";
$dbname = "";

// Create connection with error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

//timezone
date_default_timezone_set('Africa/Kampala');

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    $conn->set_charset("utf8mb4");
    $conn->query("SET collation_connection = utf8mb4_unicode_ci");
} catch (mysqli_sql_exception $e) {
    header('Content-Type: application/json');
    die(json_encode([
        'status' => 'error',
        'message' => 'Database connection failed',
        'error' => $e->getMessage()
    ]));
}
?>