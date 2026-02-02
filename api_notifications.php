<?php
// api_notifications.php
// 1. Error Reporting (Turn off display, log only) to prevent breaking JSON
error_reporting(E_ALL);
ini_set('display_errors', 0); 

require_once 'config.php'; 
header('Content-Type: application/json');

$response = ['success' => false, 'pending_results' => 0, 'pending_requests' => 0];

try {
    if (!$conn) {
        throw new Exception("Database connection failed.");
    }

    // 1. Count Pending Results
    $sql_results = "SELECT COUNT(*) as count FROM categories WHERE status = 'Results Submitted'";
    $res_results = $conn->query($sql_results);
    
    if ($res_results) {
        $row = $res_results->fetch_assoc();
        $response['pending_results'] = (int)($row['count'] ?? 0);
    } else {
        // Log SQL error if needed, but don't crash
        error_log("SQL Error Results: " . $conn->error);
    }

    // 2. Count Pending Requests
    $sql_requests = "SELECT COUNT(*) as count FROM users WHERE is_approved = 0";
    $res_requests = $conn->query($sql_requests);

    if ($res_requests) {
        $row = $res_requests->fetch_assoc();
        $response['pending_requests'] = (int)($row['count'] ?? 0);
    } else {
        error_log("SQL Error Requests: " . $conn->error);
    }

    $response['success'] = true;
    echo json_encode($response);

} catch (Exception $e) {
    // Return a clean JSON error
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>