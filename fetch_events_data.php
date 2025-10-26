<?php
header('Content-Type: application/json');
require_once 'config.php';

$events = [];
$sql = "SELECT e.event_id, e.event_name, s.sport_name, s.category, e.event_status, e.start_date, e.end_date, e.description 
        FROM events e 
        JOIN sports s ON e.sport_id = s.sport_id 
        ORDER BY e.start_date ASC";
$stmt = $conn->prepare($sql);
if ($stmt && $stmt->execute()) {
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $events[] = $row;
        }
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'error' => $conn->error ?: 'Query failed']);
    $conn->close();
    exit;
}

$conn->close();
echo json_encode(['success' => true, 'data' => $events]);
exit;
?>

