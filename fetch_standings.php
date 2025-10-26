<?php
// This file outputs only the medal standings data in JSON format for AJAX/Fetch calls.
// It avoids rendering the entire HTML page.

require_once 'config.php'; // Ensure your DB connection is available

// Fetch medal standings (overall)
$medal_tally = [];

// In fetch_standings.php

$sql = "SELECT t.team_id,
                t.team_name,
                t.college,
                COALESCE(SUM(CASE WHEN m.medal_type = 'Gold' THEN m.medal_quantity ELSE 0 END), 0) AS gold,
                COALESCE(SUM(CASE WHEN m.medal_type = 'Silver' THEN m.medal_quantity ELSE 0 END), 0) AS silver,
                COALESCE(SUM(CASE WHEN m.medal_type = 'Bronze' THEN m.medal_quantity ELSE 0 END), 0) AS bronze
        FROM teams t
        LEFT JOIN medals m ON t.team_id = m.team_id
        GROUP BY t.team_id, t.team_name, t.college
        ORDER BY gold DESC, silver DESC, bronze DESC, t.team_name ASC";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Calculate total and ensure numeric types for JSON
            $row['gold'] = (int)$row['gold'];
            $row['silver'] = (int)$row['silver'];
            $row['bronze'] = (int)$row['bronze'];
            $row['total'] = $row['gold'] + $row['silver'] + $row['bronze'];
            $medal_tally[] = $row;
        }
    }
    $stmt->close();
}

// Get latest updated_at value from the medals table
$lastUpdated = null;
$sql_last_updated = "SELECT MAX(assigned_at) AS last_updated FROM medals";
$result_last_updated = $conn->query($sql_last_updated);
if ($result_last_updated && $row_last_updated = $result_last_updated->fetch_assoc()) {
    $lastUpdated = $row_last_updated['last_updated'];
}

$conn->close();

// Output JSON data
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'medal_tally' => $medal_tally,
    'last_updated' => $lastUpdated
]);

exit();
?>