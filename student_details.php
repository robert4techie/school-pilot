<?php
require_once 'conn.php';
require_once "auth.php";
require_once 'tracking.php';
$tracker->trackAction("Student details");

$studentId = $_GET['id'] ?? null;

if (!$studentId || !is_numeric($studentId)) {
    header("Location: students.php");
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT s.*, p.full_name AS parent_name, p.occupation AS parent_occupation, 
               p.phone AS parent_phone, p.email AS parent_email
        FROM students s
        LEFT JOIN parents p ON s.id = p.student_id
        WHERE s.id = ?
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    
    if (!$student) {
        header("Location: students.php");
        exit;
    }
    
    // Calculate age
    $age = '';
    $dobFormatted = 'N/A';
    if (!empty($student['date_of_birth'])) {
        $dob = new DateTime($student['date_of_birth']);
        $now = new DateTime();
        $age = $now->diff($dob)->y;
        $dobFormatted = $dob->format('F j, Y'); // Format: May 25, 2003
    }
    
    // Format enrollment date
    $enrollDateFormatted = !empty($student['date_of_enrolment']) ? 
        (new DateTime($student['date_of_enrolment']))->format('F j, Y') : 'N/A';
    
    // Generate initials
    $initials = substr($student['first_name'] ?? '', 0, 1) . substr($student['last_name'] ?? '', 0, 1);
    
} catch (Exception $e) {
    die("Error fetching student details: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?> | School Pilot</title>
   <style>
        :root {
            --primary-color: #1e8449;
            --primary-light: #27ae60;
            --primary-dark: #145a32;
            --white: #ffffff;
            --light-gray: #f5f5f5;
            --medium-gray: #e0e0e0;
            --dark-gray: #757575;
            --text-dark: #333333;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Quicksand', sans-serif;
        }

        body {
            background-color: var(--light-gray);
            color: var(--text-dark);
            line-height: 1.6;
        }

        .container {
            max-width: 1300px;
            margin: 0 auto;
            margin-top: 60px;
            padding: 20px;
        }

        .profile-header {
            background-color: var(--primary-color);
            color: var(--white);
            padding: 30px;
            border-radius: 10px 10px 0 0;
            display: flex;
            align-items: center;
            gap: 30px;
        }

        .profile-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--white);
            box-shadow: var(--shadow);
            background-color: var(--light-gray);
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--primary-dark);
        }

        .profile-title h1 {
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .profile-title p {
            opacity: 0.9;
        }

        .profile-content {
            background-color: var(--white);
            border-radius: 0 0 10px 10px;
            padding: 30px;
            box-shadow: var(--shadow);
        }

        .section-title {
            color: var(--primary-color);
            margin: 25px 0 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--light-gray);
            font-size: 1.3rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .detail-group {
            margin-bottom: 20px;
        }

        .detail-label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--dark-gray);
            font-size: 0.9rem;
        }

        .detail-value {
            background-color: var(--light-gray);
            padding: 12px 15px;
            border-radius: 6px;
            border-left: 4px solid var(--primary-color);
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--medium-gray);
        }

        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-outline {
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
        }

        .btn-outline:hover {
            background-color: var(--light-gray);
        }

        @media (max-width: 768px) {
            .profile-header {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php require_once 'nav.php' ?>

    <div class="container">
        <div class="profile-header">
            
            <div class="profile-title">
                <h1><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></h1>
                <p><?= htmlspecialchars($student['current_class']) ?> • <?= htmlspecialchars($student['stream']) ?>    
                 | Student ID: <?= htmlspecialchars($student['student_id']) ?></p>
         
            </div>
        </div>

        <div class="profile-content">
            <h2 class="section-title">Personal Information</h2>
            <div class="detail-grid">
                <div class="detail-group">
                    <span class="detail-label">First Name</span>
                    <div class="detail-value"><?= htmlspecialchars($student['first_name']) ?></div>
                </div>
                
                <div class="detail-group">
                    <span class="detail-label">Last Name</span>
                    <div class="detail-value"><?= htmlspecialchars($student['last_name']) ?></div>
                </div>
                
                <div class="detail-group">
                    <span class="detail-label">Other Names</span>
                    <div class="detail-value"><?= !empty($student['other_names']) ? htmlspecialchars($student['other_names']) : '-' ?></div>
                </div>
                
                <div class="detail-group">
                    <span class="detail-label">Gender</span>
                    <div class="detail-value"><?= htmlspecialchars($student['gender']) ?></div>
                </div>
                
                <div class="detail-group">
                    <span class="detail-label">Date of Birth</span>
                    <div class="detail-value"><?= $dobFormatted ?> <?= $age ? "($age years)" : '' ?></div>
                </div>
                
                <div class="detail-group">
                    <span class="detail-label">Nationality</span>
                    <div class="detail-value"><?= htmlspecialchars($student['nationality'] ?? 'N/A') ?></div>
                </div>
            </div>

            <h2 class="section-title">Contact Information</h2>
            <div class="detail-grid">
                <div class="detail-group">
                    <span class="detail-label">Phone Number</span>
                    <div class="detail-value"><?= htmlspecialchars($student['phone'] ?? 'N/A') ?></div>
                </div>
                
                <div class="detail-group">
                    <span class="detail-label">Personal Email</span>
                    <div class="detail-value"><?= htmlspecialchars($student['email'] ?? 'N/A') ?></div>
                </div>
                
                <div class="detail-group">
                    <span class="detail-label">Residential Address</span>
                    <div class="detail-value"><?= htmlspecialchars($student['residential_address'] ?? 'N/A') ?></div>
                </div>
            </div>

            <h2 class="section-title">Academic Information</h2>
            <div class="detail-grid">
                <div class="detail-group">
                    <span class="detail-label">Current Class</span>
                    <div class="detail-value"><?= htmlspecialchars($student['current_class']) ?></div>
                </div>
                
                <div class="detail-group">
                    <span class="detail-label">Stream</span>
                    <div class="detail-value"><?= htmlspecialchars($student['stream']) ?></div>
                </div>
                
                <div class="detail-group">
                    <span class="detail-label">Enrollment Date</span>
                    <div class="detail-value"><?= $enrollDateFormatted ?></div>
                </div>
                
                <div class="detail-group">
                    <span class="detail-label">Subject Combination</span>
                    <div class="detail-value"><?= htmlspecialchars($student['subject_combination'] ?? 'N/A') ?></div>
                </div>
            </div>

            <h2 class="section-title">Parent/Guardian Information</h2>
            <div class="detail-grid">
                <div class="detail-group">
                    <span class="detail-label">Full Name</span>
                    <div class="detail-value"><?= htmlspecialchars($student['parent_name'] ?? 'N/A') ?></div>
                </div>
                
                <div class="detail-group">
                    <span class="detail-label">Occupation</span>
                    <div class="detail-value"><?= htmlspecialchars($student['parent_occupation'] ?? 'N/A') ?></div>
                </div>
                
                <div class="detail-group">
                    <span class="detail-label">Phone Number</span>
                    <div class="detail-value"><?= htmlspecialchars($student['parent_phone'] ?? 'N/A') ?></div>
                </div>
                
                <div class="detail-group">
                    <span class="detail-label">Email Address</span>
                    <div class="detail-value"><?= htmlspecialchars($student['parent_email'] ?? 'N/A') ?></div>
                </div>
            </div>

            <div class="action-buttons">
                <a href="edit_student.php?id=<?= $studentId ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit Profile
                </a>
                <a href="view_students.php" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i> Back to Students
                </a>
            </div>
        </div>
    </div>
</body>
</html>