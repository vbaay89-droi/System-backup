<?php
/**
 * fetch_event_results.php
 * Fetches event results with medal winners and sport distribution data
 */

session_start();
require_once 'config.php';

header('Content-Type: application/json');

try {
    // Fetch event results with medal winners
    $sql_events = "
        SELECT DISTINCT
            e.event_id,
            e.event_name,
            s.sport_name,
            s.category,
            e.start_date,
            e.end_date,
            (SELECT t.team_name 
             FROM medals m 
             JOIN teams t ON m.team_id = t.team_id 
             WHERE m.event_id = e.event_id AND m.medal_type = 'Gold' 
             LIMIT 1) AS gold_winner,
            (SELECT t.team_name 
             FROM medals m 
             JOIN teams t ON m.team_id = t.team_id 
             WHERE m.event_id = e.event_id AND m.medal_type = 'Silver' 
             LIMIT 1) AS silver_winner,
            (SELECT t.team_name 
             FROM medals m 
             JOIN teams t ON m.team_id = t.team_id 
             WHERE m.event_id = e.event_id AND m.medal_type = 'Bronze' 
             LIMIT 1) AS bronze_winner
        FROM 
            events e
        JOIN 
            sports s ON e.sport_id = s.sport_id
        WHERE 
            e.event_status = 'Completed'
        ORDER BY 
            e.start_date DESC, e.event_name ASC
    ";
    
    $result = $conn->query($sql_events);
    
    if (!$result) {
        throw new Exception("Database query failed: " . $conn->error);
    }
    
    $events = [];
    
    while ($row = $result->fetch_assoc()) {
        // Format date
        $event_date = 'TBA';
        if ($row['start_date'] && $row['start_date'] !== '0000-00-00') {
            $event_date = date('Y-m-d', strtotime($row['start_date']));
        }
        
        $events[] = [
            'event_id' => $row['event_id'],
            'event_name' => $row['event_name'],
            'sport_name' => $row['sport_name'] ?? 'N/A',
            'category' => $row['category'] ?? 'N/A',
            'event_date' => $event_date,
            'gold_winner' => $row['gold_winner'] ?? 'N/A',
            'silver_winner' => $row['silver_winner'] ?? 'N/A',
            'bronze_winner' => $row['bronze_winner'] ?? 'N/A'
        ];
    }
    
    // Fetch medal distribution by sport for pie chart
    $sql_sport_distribution = "
        SELECT 
            s.category,
            COUNT(m.medal_id) AS medal_count
        FROM 
            medals m
        JOIN 
            events e ON m.event_id = e.event_id
        JOIN 
            sports sp ON e.sport_id = sp.sport_id
        JOIN 
            sports s ON sp.sport_id = s.sport_id
        GROUP BY 
            s.category
        ORDER BY 
            medal_count DESC
    ";
    
    $result_dist = $conn->query($sql_sport_distribution);
    $sport_distribution = [];
    
    if ($result_dist) {
        while ($row = $result_dist->fetch_assoc()) {
            $sport_distribution[] = [
                'category' => $row['category'],
                'count' => (int)$row['medal_count']
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'events' => $events,
        'sport_distribution' => $sport_distribution,
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