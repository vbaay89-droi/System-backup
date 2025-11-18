<?php
// 1. SUPPRESS ERRORS
// This is important for a JSON endpoint.
// We don't want a PHP "Notice" or "Warning" to break our JSON output.
error_reporting(0);
ini_set('display_errors', 0);

// 2. SET CONTENT TYPE
// Tell the browser this file will *only* return JSON text.
header('Content-Type: application/json');

// 3. DATABASE CONNECTION
// This assumes 'config.php' is in the SAME folder (LOGIN_CAPSTONE).
// This is the most likely correct path.
require_once 'config.php';

// 4. CONNECTION CHECK
// A good practice check to see if the $conn variable was created.
if (!isset($conn) || $conn->connect_error) {
    // If connection fails, send a clean JSON error
    echo json_encode(['error' => 'Database connection failed. Check config.php']);
    exit();
}

// 5. GET AND VALIDATE PARAMETERS
$college_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : '';

// If parameters are missing, send a clean JSON error
if ($college_id === 0 || ($type !== 'results' && $type !== 'upcoming')) {
    echo json_encode(['error' => 'Invalid parameters.']);
    exit();
}

// This is the array we will fill with data
$data = [];

// 6. RUN THE CORRECT QUERY BASED ON THE 'type' PARAMETER

if ($type === 'results') {
    // --- GET MATCH RESULTS (APPROVED) ---
    // This query is from your team_profile.php
    $stmt_matches = $conn->prepare("
        SELECT
          g.game_name,
          ge.event_name,
          c.category_name,
          wg.college_name AS gold_winner_name,
          ws.college_name AS silver_winner_name,
          wb.college_name AS bronze_winner_name
        FROM categories c
        JOIN game_events ge ON c.event_id = ge.event_id
        JOIN games g ON ge.game_id = g.game_id
        LEFT JOIN colleges wg ON c.gold_winner_college_id = wg.college_id
        LEFT JOIN colleges ws ON c.silver_winner_college_id = ws.college_id
        LEFT JOIN colleges wb ON c.bronze_winner_college_id = wb.college_id
        WHERE c.status = 'Results Approved'
        AND (
          c.gold_winner_college_id = ?
          OR c.silver_winner_college_id = ?
          OR c.bronze_winner_college_id = ?
        )
        ORDER BY g.game_name, ge.event_name, c.category_name
    ");
    // Bind the college_id three times
    $stmt_matches->bind_param("iii", $college_id, $college_id, $college_id);
    $stmt_matches->execute();
    $result = $stmt_matches->get_result();
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt_matches->close(); 

} 
elseif ($type === 'upcoming') {
    // --- GET UPCOMING MATCHES ---
    // This query is also from your team_profile.php
    $stmt_upcoming = $conn->prepare("
        SELECT
            m.match_date,
            m.match_time,
            m.venue,
            m.status,
            g.game_name,
            ge.event_name,
            c.category_name,
            CASE
                WHEN m.team1_id = ? THEN t2.college_name
                ELSE t1.college_name
            END AS opponent_name
        FROM matches m
        JOIN categories c ON m.category_id = c.category_id
        JOIN game_events ge ON c.event_id = ge.event_id
        JOIN games g ON ge.game_id = g.game_id
        LEFT JOIN colleges t1 ON m.team1_id = t1.college_id
        LEFT JOIN colleges t2 ON m.team2_id = t2.college_id
        WHERE
            (m.team1_id = ? OR m.team2_id = ?)
            AND m.status IN ('Upcoming', 'Ongoing')
            AND c.category_type = 'Match'
        ORDER BY
            m.match_date ASC, m.match_time ASC
    ");
    // Bind the college_id three times
    $stmt_upcoming->bind_param("iii", $college_id, $college_id, $college_id);
    $stmt_upcoming->execute();
    $result = $stmt_upcoming->get_result();
    while ($row = $result->fetch_assoc()) {
        
        // Add the formatting that the JavaScript expects
        $row['match_date_formatted'] = $row['match_date'] ? date('M d, Y', strtotime($row['match_date'])) : 'TBA';
        $row['match_time_formatted'] = $row['match_time'] ? date('g:i A', strtotime($row['match_time'])) : '';
        
        $status_lower = strtolower($row['status']);
        $badge_class = 'bg-secondary';
        if ($status_lower == 'upcoming') $badge_class = 'bg-info';
        if ($status_lower == 'ongoing') $badge_class = 'bg-primary';
        $row['status_badge'] = $badge_class;

        $data[] = $row;
    }
    $stmt_upcoming->close();
}

// 7. CLOSE CONNECTION
$conn->close();

// 8. RETURN THE FINAL DATA
// This echoes the $data array as a clean JSON string.
echo json_encode($data);
exit();
?>