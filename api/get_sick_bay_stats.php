<?php
require_once "../auth.php";
require_once "../conn.php"; 


header('Content-Type: application/json');

try {
    // Get current month date range
    $currentMonth = date('Y-m');
    $startOfMonth = $currentMonth . '-01';
    $endOfMonth = date('Y-m-t');

    // Total visits this month
    $totalVisitsQuery = "SELECT COUNT(*) as total_visits FROM sick_bay_visits WHERE DATE(visit_datetime) >= ? AND DATE(visit_datetime) <= ?";
    $stmt = $conn->prepare($totalVisitsQuery);
    $stmt->bind_param("ss", $startOfMonth, $endOfMonth);
    $stmt->execute();
    $totalVisits = $stmt->get_result()->fetch_assoc()['total_visits'];

    // Fever cases (temperature >= 37.5)
    $feverCasesQuery = "SELECT COUNT(*) as fever_cases FROM sick_bay_visits WHERE DATE(visit_datetime) >= ? AND DATE(visit_datetime) <= ? AND temperature >= 37.5";
    $stmt = $conn->prepare($feverCasesQuery);
    $stmt->bind_param("ss", $startOfMonth, $endOfMonth);
    $stmt->execute();
    $feverCases = $stmt->get_result()->fetch_assoc()['fever_cases'];

    // Hospital referrals
    $hospitalReferralsQuery = "SELECT COUNT(*) as hospital_referrals FROM sick_bay_visits WHERE DATE(visit_datetime) >= ? AND DATE(visit_datetime) <= ? AND action_taken = 'ReferredToHospital'";
    $stmt = $conn->prepare($hospitalReferralsQuery);
    $stmt->bind_param("ss", $startOfMonth, $endOfMonth);
    $stmt->execute();
    $hospitalReferrals = $stmt->get_result()->fetch_assoc()['hospital_referrals'];

    // Students sent home
    $sentHomeQuery = "SELECT COUNT(*) as sent_home FROM sick_bay_visits WHERE DATE(visit_datetime) >= ? AND DATE(visit_datetime) <= ? AND action_taken = 'SentHome'";
    $stmt = $conn->prepare($sentHomeQuery);
    $stmt->bind_param("ss", $startOfMonth, $endOfMonth);
    $stmt->execute();
    $sentHome = $stmt->get_result()->fetch_assoc()['sent_home'];

    echo json_encode([
        'success' => true,
        'stats' => [
            'total_visits' => (int)$totalVisits,
            'fever_cases' => (int)$feverCases,
            'hospital_referrals' => (int)$hospitalReferrals,
            'sent_home' => (int)$sentHome
        ]
    ]);

} catch (Exception $e) {
    error_log("Error in get_sick_bay_stats.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while fetching statistics.'
    ]);
}
?>