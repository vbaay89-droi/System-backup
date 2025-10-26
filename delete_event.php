<?php
session_start();

// Ensure this script is only accessible to logged-in admins
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401); // Unauthorized
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit();
}

require_once 'config.php'; // Your database connection

header('Content-Type: application/json'); // Respond with JSON

$response = ['success' => false, 'error' => ''];

// Ensure it's a POST request and event_id is provided
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $eventId = (int)$_POST['id']; // Cast to integer for safety

    if ($eventId <= 0) {
        $response['error'] = 'Invalid event ID.';
    } else {
        $sql = "DELETE FROM events WHERE event_id = ?";
        $stmt = $conn->prepare($sql);

        if ($stmt) {
            $stmt->bind_param("i", $eventId);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $response['success'] = true;
                } else {
                    $response['error'] = 'Event not found or already deleted.';
                }
            } else {
                $response['error'] = 'Database deletion failed: ' . $stmt->error;
            }
            $stmt->close();
        } else {
            $response['error'] = 'Database query preparation failed: ' . $conn->error;
        }
    }
} else {
    $response['error'] = 'Invalid request method or missing event ID.';
}

$conn->close();
echo json_encode($response);
?>
