<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'Unauthorized access.']);
    exit();
}

require_once 'config.php'; // Your database connection

header('Content-Type: application/json'); // Respond with JSON

$eventId = $_GET['id'] ?? null;
$response = ['success' => false, 'data' => null, 'error' => ''];

if ($eventId) {
    // THIS IS THE CRITICAL LINE: Make sure 'event_status' is in the SELECT list
    $sql = "SELECT 
    e.event_id, 
    e.event_name, 
    e.event_status, 
    e.start_date, 
    e.end_date, 
    e.description,
    e.gold_count,      /* <- NEW */
    e.silver_count,    /* <- NEW */
    e.bronze_count,    /* <- NEW */
    s.sport_id,
    s.sport_name,
    s.category 
FROM 
    events e 
JOIN 
    sports s ON e.sport_id = s.sport_id 
WHERE 
    e.event_id = ?";


    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param("i", $eventId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
    $data = $result->fetch_assoc(); // fetch the row

    // Ensure medal_count has a default value
    if (!isset($data['medal_count']) || $data['medal_count'] === null) {
        $data['medal_count'] = 0;
    }

    $response['success'] = true;
    $response['data'] = $data;
    if (!isset($data['medal_count']) || $data['medal_count'] === null) {
    $data['medal_count'] = 0;
}

$response['success'] = true;
$response['data'] = $data;
} else {
    $response['error'] = 'Event not found.';
}

        $stmt->close();
    } else {
        $response['error'] = 'Database query preparation failed: ' . $conn->error;
    }
} else {
    $response['error'] = 'No event ID provided.';
}

$conn->close();
echo json_encode($response);
?>
