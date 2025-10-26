<?php
// This file is intended to be accessed by AJAX from Event.php (public side).
// It should NOT require session_start() or authentication for public events.
// The authentication check has been removed.

// --- Temporarily enable error reporting for debugging ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
// --- End temporary error reporting ---

require_once 'config.php'; // Your database connection

header('Content-Type: application/json'); // Respond with JSON

$event_id = $_GET['id'] ?? null;

$response = ['success' => false, 'data' => null, 'error' => ''];

if (!$event_id) {
    $response['error'] = 'No event ID provided.';
    echo json_encode($response);
    $conn->close(); // Close connection before exiting
    exit();
}

$event_details = null;
$medal_standings = [];
$grouped_schedule = []; // Correctly initialized for grouped data
$raw_match_schedule = []; // Correctly initialized for raw data

// --- 1. Fetch Event Details ---
$sql_event = "SELECT e.event_id, e.event_name, s.sport_name, s.category, e.event_status, e.start_date, e.end_date, e.description 
              FROM events e 
              JOIN sports s ON e.sport_id = s.sport_id 
              WHERE e.event_id = ?";
$stmt_event = $conn->prepare($sql_event);

if ($stmt_event) {
    $stmt_event->bind_param("i", $event_id);
    $stmt_event->execute();
    $result_event = $stmt_event->get_result();

    if ($result_event && $result_event->num_rows > 0) {
        $event_details = $result_event->fetch_assoc();
    } else {
        $response['error'] = 'Event not found.';
        echo json_encode($response);
        $stmt_event->close();
        $conn->close();
        exit();
    }
    $stmt_event->close();
} else {
    $response['error'] = 'Database query preparation failed for event details: ' . $conn->error;
    echo json_encode($response);
    $conn->close();
    exit();
}

// --- 2. Fetch Medal Standings for THIS EVENT ---
// This query sums medals for teams participating in matches within this specific event.
// --- 2. Fetch Medal Standings for THIS EVENT ---
/*
 * FIXED QUERY:
 * - Changed SUM to use COALESCE(m.medal_quantity, 1) to get the actual count from Manage_Medals.
 * - Changed to an INNER JOIN to only show teams that actually won a medal in this event.
 */
$sql_medals = "
    SELECT 
        t.team_id,
        t.team_name,
        t.college,
        SUM(CASE WHEN m.medal_type = 'Gold' THEN COALESCE(m.medal_quantity, 1) ELSE 0 END) AS gold,
        SUM(CASE WHEN m.medal_type = 'Silver' THEN COALESCE(m.medal_quantity, 1) ELSE 0 END) AS silver,
        SUM(CASE WHEN m.medal_type = 'Bronze' THEN COALESCE(m.medal_quantity, 1) ELSE 0 END) AS bronze
    FROM 
        teams t
    INNER JOIN 
        medals m ON t.team_id = m.team_id
    WHERE
        m.event_id = ?
    GROUP BY 
        t.team_id, t.team_name, t.college
    ORDER BY 
        gold DESC, silver DESC, bronze DESC, t.team_name ASC
";
$stmt_medals = $conn->prepare($sql_medals);
if ($stmt_medals) {
    $stmt_medals->bind_param("i", $event_id);
    $stmt_medals->execute();
    $result_medals = $stmt_medals->get_result();
    if ($result_medals) {
        while ($row = $result_medals->fetch_assoc()) {
            $row['total'] = $row['gold'] + $row['silver'] + $row['bronze'];
            $medal_standings[] = $row;
        }
    }
    $stmt_medals->close();
} else {
    error_log("Error preparing medal standings fetch statement: " . $conn->error);
    // Continue even if medals fail, as event details might still be needed
}

// --- 3. Fetch Match Schedule and Results for this Event ---
$sql_matches = "
    SELECT 
        m.match_id,
        m.sport_category,
        t1.team_name AS team1_name,
        t1.college AS team1_college,
        t2.team_name AS team2_name,
        t2.college AS team2_college,
        m.score1,
        m.score2,
        m.match_date,
        m.match_time,
        m.venue,
        m.status,
        tw.team_name AS winner_name,
        m.time_finished
    FROM 
        matches m
    JOIN 
        teams t1 ON m.team1_id = t1.team_id
    JOIN 
        teams t2 ON m.team2_id = t2.team_id
    LEFT JOIN 
        teams tw ON m.winner_team_id = tw.team_id -- LEFT JOIN because winner might be NULL
    WHERE 
        m.event_id = ?
    ORDER BY 
        m.match_date ASC, m.match_time ASC
";
$stmt_matches = $conn->prepare($sql_matches);
if ($stmt_matches) {
    $stmt_matches->bind_param("i", $event_id);
    $stmt_matches->execute();
    $result_matches = $stmt_matches->get_result();

    while ($row = $result_matches->fetch_assoc()) {
        $raw_match_schedule[] = $row; // Store raw data for results tab

        // Group for schedule tab: by date, then by sport_category
        $match_date = $row['match_date'];
        $sport_category = $row['sport_category'];

        if (!isset($grouped_schedule[$match_date])) {
            $grouped_schedule[$match_date] = [];
        }
        if (!isset($grouped_schedule[$match_date][$sport_category])) {
            $grouped_schedule[$match_date][$sport_category] = [];
        }
        $grouped_schedule[$match_date][$sport_category][] = $row;
    }
    $stmt_matches->close();
} else {
    $response['error'] = "Database prepare error (match schedule): " . $conn->error;
    echo json_encode($response);
    $conn->close();
    exit();
}

// --- Assemble Final Response ---
$response['success'] = true;
$response['data'] = [
    'event_details' => $event_details,
    'medal_standings' => $medal_standings,
    'match_schedule' => $grouped_schedule, // Send grouped schedule
    'raw_match_schedule' => $raw_match_schedule // FIXED: Now sends the correctly populated raw data
];

echo json_encode($response);

// Close database connection
$conn->close();
?>
