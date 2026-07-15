<?php
require_once 'includes/security.php';

// Add Fee Structure with Prepared Statements
function add_fee_structure($conn, $class_name, $student_type, $term, $year, $amount) {
    try {
        // Validate inputs
        $errors = validate_fee_structure_input($class_name, $student_type, $term, $year, $amount);
        if (!empty($errors)) {
            return ['success' => false, 'message' => implode(', ', $errors)];
        }
        
        // Format amount as decimal
        $amount = parseDecimal($amount);
        
        // Check for duplicates
        $check_stmt = $conn->prepare(
            "SELECT id FROM fee_structures WHERE class_name = ? AND student_type = ? AND term = ? AND year = ?"
        );
        $check_stmt->bind_param("sssi", $class_name, $student_type, $term, $year);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows > 0) {
            return ['success' => false, 'message' => 'Fee structure already exists for this class, student type, term, and year'];
        }
        
        // Insert new fee structure
        $stmt = $conn->prepare(
            "INSERT INTO fee_structures (class_name, student_type, term, year, amount, created_by) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        
        $created_by = $_SESSION['user_id'] ?? null;
        $stmt->bind_param("sssidi", $class_name, $student_type, $term, $year, $amount, $created_by);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Fee structure added successfully'];
        } else {
            throw new Exception($stmt->error);
        }
        
    } catch (Exception $e) {
        logError("Error adding fee structure", [
            'error' => $e->getMessage(),
            'class' => $class_name,
            'student_type' => $student_type,
            'term' => $term,
            'year' => $year
        ]);
        return ['success' => false, 'message' => 'Failed to add fee structure. Please try again.'];
    }
}

// Update Fee Structure
function update_fee_structure($conn, $id, $class_name, $student_type, $term, $year, $amount) {
    try {
        $errors = validate_fee_structure_input($class_name, $student_type, $term, $year, $amount);
        if (!empty($errors)) {
            return ['success' => false, 'message' => implode(', ', $errors)];
        }
        
        $amount = parseDecimal($amount);
        $id = (int)$id;
        
        // Check for duplicate (excluding current record)
        $check_stmt = $conn->prepare(
            "SELECT id FROM fee_structures 
             WHERE class_name = ? AND student_type = ? AND term = ? AND year = ? AND id != ?"
        );
        $check_stmt->bind_param("sssii", $class_name, $student_type, $term, $year, $id);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows > 0) {
            return ['success' => false, 'message' => 'Another fee structure already exists for this class, student type, term, and year'];
        }
        
        $stmt = $conn->prepare(
            "UPDATE fee_structures SET class_name = ?, student_type = ?, term = ?, year = ?, amount = ? WHERE id = ?"
        );
        $stmt->bind_param("sssidi", $class_name, $student_type, $term, $year, $amount, $id);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Fee structure updated successfully'];
        } else {
            throw new Exception($stmt->error);
        }
        
    } catch (Exception $e) {
        logError("Error updating fee structure", ['error' => $e->getMessage(), 'id' => $id]);
        return ['success' => false, 'message' => 'Failed to update fee structure. Please try again.'];
    }
}

// Delete Fee Structure
function delete_fee_structure($conn, $id) {
    try {
        $id = (int)$id;
        
        // Check if used in payments or bursaries
        $check_stmt = $conn->prepare(
            "SELECT COUNT(*) as count FROM fees_payments fp
             JOIN fee_structures fs ON fp.term = fs.term AND fp.year = fs.year
             WHERE fs.id = ?"
        );
        $check_stmt->bind_param("i", $id);
        $check_stmt->execute();
        $result = $check_stmt->get_result()->fetch_assoc();
        
        if ($result['count'] > 0) {
            return ['success' => false, 'message' => 'Cannot delete fee structure that has associated payments'];
        }
        
        $stmt = $conn->prepare("DELETE FROM fee_structures WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Fee structure deleted successfully'];
        } else {
            throw new Exception($stmt->error);
        }
        
    } catch (Exception $e) {
        logError("Error deleting fee structure", ['error' => $e->getMessage(), 'id' => $id]);
        return ['success' => false, 'message' => 'Failed to delete fee structure. Please try again.'];
    }
}

// Get All Fee Structures
function get_fee_structures($conn) {
    try {
        $query = "SELECT * FROM fee_structures ORDER BY year DESC, 
                  CASE term 
                    WHEN 'Term One' THEN 1
                    WHEN 'Term Two' THEN 2
                    WHEN 'Term Three' THEN 3
                  END,
                  CASE class_name
                    WHEN 'Senior One' THEN 1
                    WHEN 'Senior Two' THEN 2
                    WHEN 'Senior Three' THEN 3
                    WHEN 'Senior Four' THEN 4
                    WHEN 'Senior Five' THEN 5
                    WHEN 'Senior Six' THEN 6
                  END,
                  CASE student_type
                    WHEN 'Day' THEN 1
                    WHEN 'Boarding' THEN 2
                  END";
        
        $result = $conn->query($query);
        $fees = [];
        
        while ($row = $result->fetch_assoc()) {
            $fees[] = $row;
        }
        
        return $fees;
        
    } catch (Exception $e) {
        logError("Error fetching fee structures", ['error' => $e->getMessage()]);
        return [];
    }
}

// Get Fee Structure by Details (useful for student fee calculations)
function get_fee_by_details($conn, $class_name, $student_type, $term, $year) {
    try {
        $stmt = $conn->prepare(
            "SELECT * FROM fee_structures 
             WHERE class_name = ? AND student_type = ? AND term = ? AND year = ?"
        );
        $stmt->bind_param("sssi", $class_name, $student_type, $term, $year);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        
        return null;
        
    } catch (Exception $e) {
        logError("Error fetching fee by details", [
            'error' => $e->getMessage(),
            'class' => $class_name,
            'student_type' => $student_type,
            'term' => $term,
            'year' => $year
        ]);
        return null;
    }
}

// Validation Function
function validate_fee_structure_input($class_name, $student_type, $term, $year, $amount) {
    $errors = [];
    
    $valid_classes = ['Senior One', 'Senior Two', 'Senior Three', 'Senior Four', 'Senior Five', 'Senior Six'];
    if (!in_array($class_name, $valid_classes)) {
        $errors[] = 'Invalid class selected';
    }
    
    $valid_student_types = ['Day', 'Boarding'];
    if (!in_array($student_type, $valid_student_types)) {
        $errors[] = 'Invalid student type selected';
    }
    
    $valid_terms = ['Term One', 'Term Two', 'Term Three'];
    if (!in_array($term, $valid_terms)) {
        $errors[] = 'Invalid term selected';
    }
    
    $year = (int)$year;
    if ($year < 2020 || $year > 2050) {
        $errors[] = 'Year must be between 2020 and 2050';
    }
    
    $amount = (float)$amount;
    if ($amount <= 0) {
        $errors[] = 'Amount must be greater than 0';
    }
    
    if ($amount > 99999999.99) {
        $errors[] = 'Amount is too large';
    }
    
    return $errors;
}
?>