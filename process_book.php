<?php
// Include database connection
require_once 'conn.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Initialize response array
$response = array(
    'status' => 'error',
    'message' => 'Unknown error occurred'
);

// Debug: Log all POST data
error_log("POST data: " . print_r($_POST, true));
error_log("FILES data: " . print_r($_FILES, true));

// Check if form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Get form data and sanitize inputs
    $title = isset($_POST["book-title"]) ? mysqli_real_escape_string($conn, $_POST["book-title"]) : '';
    $author = isset($_POST["book-author"]) ? mysqli_real_escape_string($conn, $_POST["book-author"]) : '';
    $isbn = isset($_POST["book-isbn"]) ? mysqli_real_escape_string($conn, $_POST["book-isbn"]) : '';
    $category = isset($_POST["book-category"]) ? mysqli_real_escape_string($conn, $_POST["book-category"]) : '';
    $publisher = isset($_POST["book-publisher"]) ? mysqli_real_escape_string($conn, $_POST["book-publisher"]) : '';
    $publication_date = isset($_POST["book-publication-date"]) && !empty($_POST["book-publication-date"]) ? 
                        mysqli_real_escape_string($conn, $_POST["book-publication-date"]) : NULL;
    $edition = isset($_POST["book-edition"]) ? mysqli_real_escape_string($conn, $_POST["book-edition"]) : '';
    $condition = isset($_POST["book-condition"]) ? mysqli_real_escape_string($conn, $_POST["book-condition"]) : '';
    $location = isset($_POST["book-location"]) ? mysqli_real_escape_string($conn, $_POST["book-location"]) : '';
    $pages = isset($_POST["book-pages"]) && !empty($_POST["book-pages"]) ? (int)$_POST["book-pages"] : NULL;
    $description = isset($_POST["book-description"]) ? mysqli_real_escape_string($conn, $_POST["book-description"]) : '';
    $status = isset($_POST["book-status"]) ? mysqli_real_escape_string($conn, $_POST["book-status"]) : '';
    
    // Debug: Log processed data
    error_log("Processed data:");
    error_log("Title: $title");
    error_log("Author: $author");
    error_log("ISBN: $isbn");
    error_log("Category: $category");
    error_log("Status: $status");
    
    // Validate required fields
    if (empty($title) || empty($author) || empty($isbn) || empty($category) || empty($status)) {
        $response = array(
            'status' => 'error',
            'message' => 'Please fill all required fields'
        );
        error_log("Validation failed: Missing required fields");
    } else {
        // Handle file upload if a cover image was provided
        $cover_path = '';
        if (isset($_FILES['book-cover']) && $_FILES['book-cover']['error'] == 0) {
            $allowed = array('jpg', 'jpeg', 'png', 'gif');
            $filename = $_FILES['book-cover']['name'];
            $file_ext = pathinfo($filename, PATHINFO_EXTENSION);
            
            // Check if file extension is allowed
            if (in_array(strtolower($file_ext), $allowed)) {
                $new_filename = uniqid('book_') . '.' . $file_ext;
                $upload_path = 'uploads/covers/' . $new_filename;
                
                // Create directory if it doesn't exist
                if (!file_exists('uploads/covers/')) {
                    mkdir('uploads/covers/', 0777, true);
                }
                
                if (move_uploaded_file($_FILES['book-cover']['tmp_name'], $upload_path)) {
                    $cover_path = $upload_path;
                    error_log("File uploaded to: $cover_path");
                } else {
                    error_log("File upload failed");
                }
            } else {
                error_log("Invalid file extension");
            }
        }
        
        // Use direct query approach (like in the test insert that worked)
        $pages_value = $pages === NULL ? "NULL" : $pages;
        $publication_date_value = $publication_date === NULL ? "NULL" : "'$publication_date'";
        
        $sql = "INSERT INTO books (
                    title, author, isbn, category, publisher, 
                    publication_date, edition, book_condition, 
                    location, pages, description, cover_image, status, created_at
                ) VALUES (
                    '$title', '$author', '$isbn', '$category', '$publisher',
                    $publication_date_value, '$edition', '$condition',
                    '$location', $pages_value, '$description', '$cover_path', '$status', NOW()
                )";
        
        error_log("SQL Query: $sql");
        
        if (mysqli_query($conn, $sql)) {
            $book_id = mysqli_insert_id($conn);
            $response = array(
                'status' => 'success',
                'message' => 'Book added successfully!',
                'book_id' => $book_id
            );
            error_log("Book added successfully with ID: $book_id");
            
            // Verify the insert actually worked by selecting the record
            $verify_sql = "SELECT * FROM books WHERE id = $book_id";
            $result = mysqli_query($conn, $verify_sql);
            if (mysqli_num_rows($result) > 0) {
                error_log("Verification successful: Record exists in database");
                $row = mysqli_fetch_assoc($result);
                error_log("Record data: " . print_r($row, true));
            } else {
                error_log("WARNING: Verification failed! Record not found in database despite successful insert!");
            }
        } else {
            $response = array(
                'status' => 'error',
                'message' => 'Database error: ' . mysqli_error($conn)
            );
            error_log("Database error: " . mysqli_error($conn));
        }
    }
    
    // Close database connection
    mysqli_close($conn);
    
    // Send JSON response
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
} else {
    error_log("No POST request detected");
}
?>