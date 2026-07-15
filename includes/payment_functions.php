<?php
require_once 'security.php';

function savePaymentWithTransaction($conn, $paymentData)
{
    // Start transaction
    mysqli_begin_transaction($conn, MYSQLI_TRANS_START_READ_WRITE);

    try {
        // Validate all inputs
        $errors = validatePaymentData($conn, $paymentData);
        if (!empty($errors)) {
            throw new Exception(implode(', ', $errors));
        }

        // Format decimal values
        $fees_amount = parseDecimal($paymentData['fees_amount']);
        $bursary_discount = parseDecimal($paymentData['bursary_discount']);
        $amount_paid = parseDecimal($paymentData['amount_paid']);
        $amount_to_pay = parseDecimal($fees_amount - $bursary_discount);

        // Lock existing payment records for this student/term/year/student_type
        $lock_stmt = $conn->prepare(
            "SELECT id, amount_paid FROM fees_payments 
             WHERE student_id = ? AND term = ? AND year = ? AND student_type = ?
             FOR UPDATE"
        );
        $lock_stmt->bind_param(
            "ssis",
            $paymentData['student_id'],
            $paymentData['term'],
            $paymentData['year'],
            $paymentData['student_type']
        );
        $lock_stmt->execute();
        $existing_payments = $lock_stmt->get_result();

        // Calculate total already paid
        $total_already_paid = 0;
        while ($row = $existing_payments->fetch_assoc()) {
            $total_already_paid += (float)$row['amount_paid'];
        }

        // Calculate new totals
        $new_total_paid = $total_already_paid + $amount_paid;
        $balance = $amount_to_pay - $new_total_paid;

        // Validate payment doesn't exceed amount to pay
        if ($new_total_paid > $amount_to_pay) {
            throw new Exception('Payment amount exceeds total fees. Overpayment not allowed.');
        }

        // Determine status
        if ($new_total_paid >= $amount_to_pay) {
            $status = 'paid';
        } elseif ($new_total_paid > 0) {
            $status = 'partial';
        } else {
            $status = 'pending';
        }

        // Insert new payment record with student_type
        $insert_stmt = $conn->prepare(
            "INSERT INTO fees_payments (
        receipt_number, student_id, student_type, term, year,
        fees_amount, bursary_discount, amount_to_pay, amount_paid, balance,
        payment_method, depositor_name, depositor_contact, status,
        payment_date, payment_reference, notes
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        // Assign nullable values to variables first (required for bind_param)
        $payment_reference = $paymentData['payment_reference'] ?? null;
        $notes = $paymentData['notes'] ?? null;

        // FIXED: Correct type string with 17 parameters (added 's' for student_type)
        // s s s s i d d d d d s s s s s s s
        // 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17
        $insert_stmt->bind_param(
            "sssidddddssssssss",  // 17 's' and 'd' - matches 17 parameters
            $paymentData['receipt_number'],       // 1 - s (string)
            $paymentData['student_id'],           // 2 - s (string)
            $paymentData['student_type'],         // 3 - s (string) - ADDED
            $paymentData['term'],                 // 4 - s (string)
            $paymentData['year'],                 // 5 - i (integer)
            $fees_amount,                         // 6 - d (double)
            $bursary_discount,                    // 7 - d (double)
            $amount_to_pay,                       // 8 - d (double)
            $amount_paid,                         // 9 - d (double)
            $balance,                             // 10 - d (double)
            $paymentData['payment_method'],       // 11 - s (string)
            $paymentData['depositor_name'],       // 12 - s (string)
            $paymentData['depositor_contact'],    // 13 - s (string)
            $status,                              // 14 - s (string)
            $paymentData['payment_date'],         // 15 - s (string)
            $payment_reference,                   // 16 - s (string)
            $notes                                // 17 - s (string)
        );
        
        if (!$insert_stmt->execute()) {
            throw new Exception('Failed to insert payment record: ' . $insert_stmt->error);
        }

        $payment_id = $conn->insert_id;

        // Update status of all payments for this student/term/year/student_type
        $update_stmt = $conn->prepare(
            "UPDATE fees_payments 
             SET status = ? 
             WHERE student_id = ? AND term = ? AND year = ? AND student_type = ?"
        );
        $update_stmt->bind_param(
            "sssis",
            $status,
            $paymentData['student_id'],
            $paymentData['term'],
            $paymentData['year'],
            $paymentData['student_type']
        );
        $update_stmt->execute();

        // Commit transaction
        mysqli_commit($conn);

        return [
            'success' => true,
            'message' => 'Payment saved successfully',
            'payment_id' => $payment_id,
            'new_balance' => $balance,
            'total_paid' => $new_total_paid,
            'status' => $status
        ];
    } catch (Exception $e) {
        // Rollback on error
        mysqli_rollback($conn);

        logError("Payment transaction failed", [
            'error' => $e->getMessage(),
            'student_id' => $paymentData['student_id'] ?? 'unknown',
            'receipt' => $paymentData['receipt_number'] ?? 'unknown'
        ]);

        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

function validatePaymentData($conn, $data)
{
    $errors = [];

    // Validate receipt number uniqueness
    if (empty($data['receipt_number'])) {
        $errors[] = 'Receipt number is required';
    } else {
        $stmt = $conn->prepare("SELECT id FROM fees_payments WHERE receipt_number = ?");
        $stmt->bind_param("s", $data['receipt_number']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors[] = 'Receipt number already exists';
        }
    }

    // Validate student exists and get their student type
    if (empty($data['student_id'])) {
        $errors[] = 'Student ID is required';
    } else {
        $stmt = $conn->prepare("SELECT student_id, section FROM students WHERE student_id = ? AND status = 'active'");
        $stmt->bind_param("s", $data['student_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $errors[] = 'Invalid or inactive student';
        }
    }

    // Validate student_type
    if (empty($data['student_type'])) {
        $errors[] = 'Student type is required';
    } else {
        $valid_student_types = ['Day', 'Boarding'];
        if (!in_array($data['student_type'], $valid_student_types)) {
            $errors[] = 'Invalid student type. Must be either Day or Boarding';
        }
    }

    // Validate term
    $valid_terms = ['Term One', 'Term Two', 'Term Three'];
    if (!in_array($data['term'] ?? '', $valid_terms)) {
        $errors[] = 'Invalid term';
    }

    // Validate year
    $year = (int)($data['year'] ?? 0);
    if ($year < 2020 || $year > 2050) {
        $errors[] = 'Invalid year';
    }

    // Validate amounts
    $fees_amount = (float)($data['fees_amount'] ?? 0);
    if ($fees_amount <= 0) {
        $errors[] = 'Fees amount must be greater than 0';
    }

    $bursary_discount = (float)($data['bursary_discount'] ?? 0);
    if ($bursary_discount < 0) {
        $errors[] = 'Bursary discount cannot be negative';
    }

    if ($bursary_discount > $fees_amount) {
        $errors[] = 'Bursary discount cannot exceed fees amount';
    }

    $amount_paid = (float)($data['amount_paid'] ?? 0);
    if ($amount_paid <= 0) {
        $errors[] = 'Amount paid must be greater than 0';
    }

    // Validate payment method
    $valid_methods = ['Cash', 'Bank Transfer', 'Mobile Money', 'Cheque', 'Online Payment'];
    if (!in_array($data['payment_method'] ?? '', $valid_methods)) {
        $errors[] = 'Invalid payment method';
    }

    // Validate depositor info
    if (empty($data['depositor_name'])) {
        $errors[] = 'Depositor name is required';
    }

    if (empty($data['depositor_contact'])) {
        $errors[] = 'Depositor contact is required';
    }

    return $errors;
}