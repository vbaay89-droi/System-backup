<?php
// This is the API file that populates the "Game Details" tab in your public modal.
header('Content-Type: application/json');
require_once 'config.php'; // Ensure this path is correct

// 1. Get the ID from the URL (e.g., ...?id=12)
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'No event ID provided.']);
    exit();
}
$category_id = (int)$_GET['id'];

// Main data structure to return
$response = [
    'success' => true,
    'data' => [
        'event_details' => [],
        'medal_standings' => []
        // Note: 'raw_match_schedule' is removed because Matches feature is deleted
    ]
];

try {
    // --- Query 1: Get Event Details (from L1, L2, L3 tables) ---
    // --- Query 1: Get Event Details (from L1, L2, L3 tables) ---
    $sql_details = "
        SELECT 
            c.category_id AS event_id,
            c.category_name AS event_name,
            c.division_name,
            c.status AS event_status,
             
            c.notes AS description, 
            
            c.event_date,
            c.event_time,
            c.venue,
            c.podium_photo_url, /* ADDED THIS */
            
            ge.event_name AS category,
            g.game_name AS sport_name,
            
            COALESCE(u.full_name, u.username) AS manager_name
            
        FROM categories c
        JOIN game_events ge ON c.event_id = ge.event_id
        JOIN games g ON ge.game_id = g.game_id
        LEFT JOIN tournament_manager_assignments ema ON ge.event_id = ema.event_id
        LEFT JOIN users u ON ema.user_id = u.id
        WHERE c.category_id = ?
        LIMIT 1
    ";
    
    $stmt_details = $conn->prepare($sql_details);
    if ($stmt_details === false) {
        throw new Exception("SQL prepare error (Query 1): " . $conn->error);
    }
    $stmt_details->bind_param("i", $category_id);
    $stmt_details->execute();
    $result_details = $stmt_details->get_result();
    
    if ($result_details->num_rows > 0) {
        $response['data']['event_details'] = $result_details->fetch_assoc();
        // Add defaults for fields your JS expects
        $response['data']['event_details']['start_date'] = 'TBA';
        $response['data']['event_details']['end_date'] = 'TBA';
    } else {
        throw new Exception("Event not found.");
    }
    $stmt_details->close();

    // --- Query 2: Get Medal Standings (Approved Winners for THIS Category) ---
    $sql_medals = "
        SELECT 
            c.gold_winner_college_id, c.gold_count,
            c.silver_winner_college_id, c.silver_count,
            c.bronze_winner_college_id, c.bronze_count,
            col_gold.college_name AS gold_name,
            col_silver.college_name AS silver_name,
            col_bronze.college_name AS bronze_name
        FROM categories c
        LEFT JOIN colleges col_gold ON c.gold_winner_college_id = col_gold.college_id
        LEFT JOIN colleges col_silver ON c.silver_winner_college_id = col_silver.college_id
        LEFT JOIN colleges col_bronze ON c.bronze_winner_college_id = col_bronze.college_id
        WHERE c.category_id = ? 
          AND (c.status = 'Results Approved' OR c.status = 'Completed')
        LIMIT 1
    ";

    $stmt_medals = $conn->prepare($sql_medals);
    if ($stmt_medals === false) {
        throw new Exception("SQL prepare error (Query 2): " . $conn->error);
    }
    $stmt_medals->bind_param("i", $category_id);
    $stmt_medals->execute();
    $medal_data = $stmt_medals->get_result()->fetch_assoc();

    if ($medal_data) {
        // Format data for the modal's table
        if ($medal_data['gold_winner_college_id']) {
            $response['data']['medal_standings'][] = [
                'team_name' => $medal_data['gold_name'],
                'college' => $medal_data['gold_name'],
                'gold' => $medal_data['gold_count'],
                'silver' => 0,
                'bronze' => 0,
                'total' => $medal_data['gold_count']
            ];
        }
        if ($medal_data['silver_winner_college_id']) {
            $response['data']['medal_standings'][] = [
                'team_name' => $medal_data['silver_name'],
                'college' => $medal_data['silver_name'],
                'gold' => 0,
                'silver' => $medal_data['silver_count'],
                'bronze' => 0,
                'total' => $medal_data['silver_count']
            ];
        }
        if ($medal_data['bronze_winner_college_id']) {
            $response['data']['medal_standings'][] = [
                'team_name' => $medal_data['bronze_name'],
                'college' => $medal_data['bronze_name'],
                'gold' => 0,
                'silver' => 0,
                'bronze' => $medal_data['bronze_count'],
                'total' => $medal_data['bronze_count']
            ];
        }
    }
    $stmt_medals->close();

} catch (Exception $e) {
    // Send a detailed error message back as JSON
    $response['success'] = false;
    $response['error'] = $e->getMessage();
}

$conn->close();
echo json_encode($response);
?>