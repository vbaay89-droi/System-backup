<?php
session_start(); 

// --- Temporarily enable error reporting for debugging ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
// --- End temporary error reporting --

require_once 'config.php'; // Your database connection

header('Content-Type: application/json'); // Respond with JSON

$match_id = $_GET['id'] ?? null; // Expecting match_id from the AJAX call

$response = ['success' => false, 'data' => null, 'error' => ''];

// Check if a match ID was provided
if (!$match_id) {
    $response['error'] = 'No match ID provided.';
    echo json_encode($response);
    $conn->close();
    exit();
}

// --- Fetch specific match details using the match_id ---
$sql_match_details = "
    SELECT 
        m.match_id,
        m.event_id,
        e.event_name, -- <<< === ADDED THIS LINE
        m.sport_category,
        m.team1_id,
        t1.team_name AS team1_name,
        t1.college AS team1_college,
        m.team2_id,
        t2.team_name AS team2_name,
        t2.college AS team2_college,
        m.score1,
        m.score2,
        m.match_date,
        m.match_time,
        m.venue,
        m.status,
        m.winner_team_id,
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
    LEFT JOIN
        events e ON m.event_id = e.event_id -- <<< === AND ADDED THIS JOIN
    WHERE 
        m.match_id = ?;
";
$stmt_match_details = $conn->prepare($sql_match_details);

if ($stmt_match_details) {
    $stmt_match_details->bind_param("i", $match_id); // Bind the match_id as an integer
    $stmt_match_details->execute();
    $result_match_details = $stmt_match_details->get_result();

    if ($result_match_details && $result_match_details->num_rows > 0) {
        $response['success'] = true;
        $response['data'] = $result_match_details->fetch_assoc(); // Fetch the single match row
    } else {
        $response['error'] = 'Match not found for the provided ID.'; // More specific error message
    }
    $stmt_match_details->close();
} else {
    $response['error'] = 'Database query preparation failed for match details: ' . $conn->error;
}

$conn->close(); // Close the database connection
echo json_encode($response); // Output the JSON response
?>