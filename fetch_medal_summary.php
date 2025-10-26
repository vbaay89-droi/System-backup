<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

try {
    // Fetch medal standings with medal_quantity support
    $sql_standings = "
        SELECT 
            t.team_id,
            t.team_name,
            t.college,
            SUM(CASE WHEN m.medal_type = 'Gold' THEN COALESCE(m.medal_quantity, 1) ELSE 0 END) AS gold,
            SUM(CASE WHEN m.medal_type = 'Silver' THEN COALESCE(m.medal_quantity, 1) ELSE 0 END) AS silver,
            SUM(CASE WHEN m.medal_type = 'Bronze' THEN COALESCE(m.medal_quantity, 1) ELSE 0 END) AS bronze
        FROM 
            teams t
        LEFT JOIN 
            medals m ON t.team_id = m.team_id
        GROUP BY 
            t.team_id, t.team_name, t.college
        HAVING 
            (gold + silver + bronze) > 0
        ORDER BY 
            gold DESC, silver DESC, bronze DESC, t.team_name ASC
    ";
    
    $result = $conn->query($sql_standings);
    
    if (!$result) {
        throw new Exception("Database query failed: " . $conn->error);
    }
    
    $standings = [];
    $rank = 1;
    
    while ($row = $result->fetch_assoc()) {
        $standings[] = [
            'rank' => $rank++,
            'team_name' => $row['team_name'],
            'college' => $row['college'] ?? 'N/A',
            'gold' => (int)$row['gold'],
            'silver' => (int)$row['silver'],
            'bronze' => (int)$row['bronze'],
            'total' => (int)$row['gold'] + (int)$row['silver'] + (int)$row['bronze']
        ];
    }
    
    // Also fetch chart data for visualization
    $chart_data = [
        'colleges' => [],
        'gold' => [],
        'silver' => [],
        'bronze' => []
    ];
    
    foreach ($standings as $standing) {
        $chart_data['colleges'][] = $standing['college'];
        $chart_data['gold'][] = $standing['gold'];
        $chart_data['silver'][] = $standing['silver'];
        $chart_data['bronze'][] = $standing['bronze'];
    }
    
    echo json_encode([
        'success' => true,
        'standings' => $standings,
        'chart_data' => $chart_data,
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