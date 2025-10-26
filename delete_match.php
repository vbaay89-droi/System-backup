<?php
session_start();
header('Content-Type: application/json');

// --- Temporarily enable error reporting for debugging ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
// --- End temporary error reporting ---

// Authentication check (optional but recommended for admin actions)
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit();
}

require_once 'config.php'; // Make sure this path is correct

$match_id = $_POST['id'] ?? null;

$response = ['success' => false, 'error' => ''];

if (!$match_id) {
    $response['error'] = 'No match ID provided.';
    echo json_encode($response);
    exit();
}

$sql = "DELETE FROM matches WHERE match_id = ?";
$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("i", $match_id);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $response['success'] = true;
        } else {
            $response['error'] = 'Match not found or already deleted.';
        }
    } else {
        $response['error'] = 'Database execute error: ' . $stmt->error;
    }
    $stmt->close();
} else {
    $response['error'] = 'Database prepare error: ' . $conn->error;
}

echo json_encode($response);

$conn->close();
?>
