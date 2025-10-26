<?php
/**
 * fetch_match_results.php
 * Fetches completed match results with scores and winners
 */

session_start();
require_once 'config.php';

header('Content-Type: application/json');

try {
    // Fetch completed match results
    $sql_matches = "
        SELECT 
            m.match_id,
            e.event_name,
            s.sport_name,
            s.category,
            t1.team_name AS team1_name,
            t1.college AS team1_college,
            t2.team_name AS team2_name,
            t2.college AS team2_college,
            m.score1,
            m.score2,
            tw.team_name AS winner_name,
            tw.college AS winner_college,
            m.time_finished,
            m.match_date,
            m.match_time,
            m.status
        FROM 
            matches m
        JOIN 
            events e ON m.event_id = e.event_id
        JOIN 
            sports s ON e.sport_id = s.sport_id
        JOIN 
            teams t1 ON m.team1_id = t1.team_id
        JOIN 
            teams t2 ON m.team2_id = t2.team_id
        LEFT JOIN 
            teams tw ON m.winner_team_id = tw.team_id
        WHERE 
            m.status IN ('Completed', 'Ongoing')
        ORDER BY 
            m.time_finished DESC, m.match_date DESC, m.match_time DESC
        LIMIT 50
    ";
    
    $result = $conn->query($sql_matches);
    
    if (!$result) {
        throw new Exception("Database query failed: " . $conn->error);
    }
    
    $matches = [];
    
    while ($row = $result->fetch_assoc()) {
        // Format match description
        $match_description = $row['team1_college'] . ' vs ' . $row['team2_college'];
        
        // Format time finished
        $time_finished = 'Ongoing';
        if ($row['time_finished'] && $row['time_finished'] !== '0000-00-00 00:00:00') {
            $time_finished = date('M j, Y H:i', strtotime($row['time_finished']));
        } elseif ($row['status'] === 'Completed') {
            $time_finished = 'Completed';
        }
        
        // Format scores
        $scores = '---';
        if ($row['score1'] !== null && $row['score2'] !== null) {
            $scores = $row['score1'] . ' - ' . $row['score2'];
        }
        
        // Format winner
        $winner = 'N/A';
        if ($row['winner_college']) {
            $winner = $row['winner_college'];
        }
        
        $matches[] = [
            'match_id' => $row['match_id'],
            'event_name' => $row['event_name'],
            'sport_name' => $row['sport_name'] ?? 'N/A',
            'category' => $row['category'] ?? 'N/A',
            'match_description' => $match_description,
            'team1' => $row['team1_college'],
            'team2' => $row['team2_college'],
            'scores' => $scores,
            'winner' => $winner,
            'time_finished' => $time_finished,
            'status' => $row['status']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'matches' => $matches,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>