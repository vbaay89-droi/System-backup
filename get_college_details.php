<?php
// 1. SUPPRESS ERRORS
error_reporting(0);
ini_set('display_errors', 0);

// 2. SET CONTENT TYPE
header('Content-Type: application/json');

// 3. DATABASE CONNECTION
require_once 'config.php';

if (!isset($conn) || $conn->connect_error) {
    echo json_encode(['error' => 'Database connection failed.']);
    exit();
}

// 5. GET PARAMETERS
$college_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : '';

if ($college_id === 0) {
    echo json_encode(['error' => 'Invalid Team ID.']);
    exit();
}

$data = [];

// --- 1. MATCH RESULTS (Legacy) ---
if ($type === 'results') {
    $stmt = $conn->prepare("
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
        WHERE (c.status = 'Results Approved' OR c.status = 'Completed')
        AND (
          c.gold_winner_college_id = ?
          OR c.silver_winner_college_id = ?
          OR c.bronze_winner_college_id = ?
        )
        ORDER BY g.game_name, ge.event_name
    ");
    $stmt->bind_param("iii", $college_id, $college_id, $college_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close(); 

} 
// --- 2. MEDAL HISTORY (List of all wins) ---
elseif ($type === 'history') {
    
    $sql = "SELECT 
                c.approved_at,
                ge.event_name,
                g.game_name,
                c.category_name,
                CASE 
                    WHEN c.gold_winner_college_id = ? THEN 'Gold'
                    WHEN c.silver_winner_college_id = ? THEN 'Silver'
                    WHEN c.bronze_winner_college_id = ? THEN 'Bronze'
                END as medal_won
            FROM categories c
            JOIN game_events ge ON c.event_id = ge.event_id
            JOIN games g ON ge.game_id = g.game_id
            WHERE (c.status = 'Results Approved' OR c.status = 'Completed')
            AND (c.gold_winner_college_id = ? OR c.silver_winner_college_id = ? OR c.bronze_winner_college_id = ?)
            ORDER BY c.approved_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiiiii", $college_id, $college_id, $college_id, $college_id, $college_id, $college_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $dateVal = $row['approved_at'] ? $row['approved_at'] : 'now';
        $row['date_formatted'] = date('F d, Y', strtotime($dateVal));
        $data[] = $row;
    }
    $stmt->close();
}
// --- 3. VICTORY GALLERY (Gold wins with photos) ---
elseif ($type === 'gallery') {
    
    $sql = "SELECT 
                c.podium_photo_url,
                c.category_name,
                ge.event_name,
                g.game_name,
                c.approved_at
            FROM categories c
            JOIN game_events ge ON c.event_id = ge.event_id
            JOIN games g ON ge.game_id = g.game_id
            WHERE (c.status = 'Results Approved' OR c.status = 'Completed')
            AND c.gold_winner_college_id = ?  -- Only Gold winners usually have the main photo
            AND c.podium_photo_url IS NOT NULL 
            AND c.podium_photo_url != ''
            ORDER BY c.approved_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $college_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Clean up path if it contains dots
        $row['podium_photo_url'] = str_replace('../', '', $row['podium_photo_url']);
        $row['date_formatted'] = date('M d, Y', strtotime($row['approved_at']));
        $data[] = $row;
    }
    $stmt->close();
}

$conn->close();
echo json_encode($data);
exit();
?>