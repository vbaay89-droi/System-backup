<?php
// This file outputs medal standings for *only one* event.
// It's used by the Event.php page to show event-specific rankings.

require_once 'config.php'; // Ensure your DB connection is available

header('Content-Type: application/json');

// Get the specific event_id from the URL (e.g., ?event_id=5)
$event_id = $_GET['event_id'] ?? 0;

if (empty($event_id)) {
    // If no event ID is provided, return an empty list.
    echo json_encode(['success' => true, 'event_standings' => []]);
    exit();
}

$event_standings = [];

/*
* This query is special. It lists ALL teams, but the LEFT JOIN
* is filtered by the event_id.
*
* This ensures that teams with 0 medals *in this event*
* still appear on the list, which is what we want.
*/
$sql = "SELECT
            t.team_id,
            t.team_name,
            t.college,
            COALESCE(SUM(CASE WHEN m.medal_type = 'Gold' THEN m.medal_quantity ELSE 0 END), 0) AS gold,
            COALESCE(SUM(CASE WHEN m.medal_type = 'Silver' THEN m.medal_quantity ELSE 0 END), 0) AS silver,
            COALESCE(SUM(CASE WHEN m.medal_type = 'Bronze' THEN m.medal_quantity ELSE 0 END), 0) AS bronze
        FROM teams t
        LEFT JOIN medals m ON t.team_id = m.team_id AND m.event_id = ? 
        GROUP BY t.team_id, t.team_name, t.college
        ORDER BY gold DESC, silver DESC, bronze DESC, t.team_name ASC";

$stmt = $conn->prepare($sql);

if ($stmt) {
    // Bind the event_id to the '?' in the SQL query
    $stmt->bind_param("i", $event_id);
    
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Calculate total and ensure numeric types for JSON
            $row['gold'] = (int)$row['gold'];
            $row['silver'] = (int)$row['silver'];
            $row['bronze'] = (int)$row['bronze'];
            $row['total'] = $row['gold'] + $row['silver'] + $row['bronze'];
            
            // We only include teams that have medals OR are part of the standings
            // Let's filter out teams with 0 total medals *for this event*
            // To show ALL teams (like your screenshot), comment out this "if" block.
            if ($row['total'] > 0) {
                 $event_standings[] = $row;
            }

            // --- To show ALL teams (even with 0 medals), uncomment this line: ---
            // $event_standings[] = $row;
            // --- and comment out the "if ($row['total'] > 0)" block above. ---
        }
    }
    $stmt->close();
}

$conn->close();

// Output the JSON data
echo json_encode([
    'success' => true,
    'event_standings' => $event_standings
]);


// DEDELETE IINI LATER TESTING PLAA

exit();
?>

