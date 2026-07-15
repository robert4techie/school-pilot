<?php
session_start();
require_once 'conn.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tracking_id = isset($_POST['tracking_id']) ? (int)$_POST['tracking_id'] : 0;
        
        if ($tracking_id <= 0) {
            throw new Exception('Invalid tracking ID');
        }

        // Get coordinates from POST or use null
        $latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : null;
        $longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : null;
        $location = null;

        if ($latitude && $longitude) {
            // Reverse geocoding using OpenStreetMap Nominatim (free service)
            $location = getLocationFromCoordinates($latitude, $longitude);
        }

        // Update tracking record
        $stmt = $conn->prepare("UPDATE user_tracking SET latitude = ?, longitude = ?, location = ? WHERE id = ?");
        $stmt->bind_param("ddsi", $latitude, $longitude, $location, $tracking_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Database update failed');
        }
        
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => 'Location updated successfully',
            'location' => $location
        ]);
    } else {
        throw new Exception('Invalid request method');
    }
} catch (Exception $e) {
    error_log("Location Update Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Get location name from coordinates using reverse geocoding
 */
function getLocationFromCoordinates($lat, $lon) {
    try {
        $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lon}&zoom=10&addressdetails=1";
        
        $options = [
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: SchoolPilot/1.0'
            ]
        ];
        
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            return "Location unavailable";
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['address'])) {
            $parts = [];
            
            if (isset($data['address']['city'])) {
                $parts[] = $data['address']['city'];
            } elseif (isset($data['address']['town'])) {
                $parts[] = $data['address']['town'];
            } elseif (isset($data['address']['village'])) {
                $parts[] = $data['address']['village'];
            }
            
            if (isset($data['address']['state'])) {
                $parts[] = $data['address']['state'];
            }
            
            if (isset($data['address']['country'])) {
                $parts[] = $data['address']['country'];
            }
            
            return !empty($parts) ? implode(', ', $parts) : "Location unavailable";
        }
        
        return "Location unavailable";
    } catch (Exception $e) {
        error_log("Geocoding Error: " . $e->getMessage());
        return "Location unavailable";
    }
}

$conn->close();