<?php
header('Content-Type: application/json');
require_once 'config.php'; 

try {
    // --- 1. FETCH MEDAL STANDINGS ---
    $medal_tally = [];

    $sql = "SELECT 
                C.college_name, C.logo_url, C.college_code, C.unit_color,
                
                COALESCE(SUM(CASE WHEN Cat.gold_winner_college_id = C.college_id THEN Cat.gold_count ELSE 0 END), 0) AS gold,
                COALESCE(SUM(CASE WHEN Cat.silver_winner_college_id = C.college_id THEN Cat.silver_count ELSE 0 END), 0) AS silver,
                COALESCE(SUM(CASE WHEN Cat.bronze_winner_college_id = C.college_id THEN Cat.bronze_count ELSE 0 END), 0) AS bronze,
                
                (COALESCE(SUM(CASE WHEN Cat.gold_winner_college_id = C.college_id THEN Cat.gold_count ELSE 0 END), 0) +
                 COALESCE(SUM(CASE WHEN Cat.silver_winner_college_id = C.college_id THEN Cat.silver_count ELSE 0 END), 0) +
                 COALESCE(SUM(CASE WHEN Cat.bronze_winner_college_id = C.college_id THEN Cat.bronze_count ELSE 0 END), 0)) AS total
                
            FROM colleges C
            LEFT JOIN categories Cat ON (
                C.college_id = Cat.gold_winner_college_id OR 
                C.college_id = Cat.silver_winner_college_id OR 
                C.college_id = Cat.bronze_winner_college_id
            ) AND Cat.status = 'Results Approved'
            
            GROUP BY C.college_id, C.college_name, C.logo_url, C.college_code
            
            -- OLYMPIC RANKING LOGIC: Gold > Silver > Bronze > Name
            ORDER BY gold DESC, silver DESC, bronze DESC, C.college_name ASC";

    $result = $conn->query($sql);
    
    if ($result) {
        $medal_tally = $result->fetch_all(MYSQLI_ASSOC);
    }

    // --- 2. FETCH RECENT WINNERS (FOR TICKER) - NEW! ---
    $recent_winners = [];
    $sql_ticker = "
        SELECT 
            ge.event_name, 
            c.category_name, 
            c.gold_count, c.silver_count, c.bronze_count,
            cg.college_code AS gold_code,
            cs.college_code AS silver_code,
            cb.college_code AS bronze_code
        FROM categories c
        JOIN game_events ge ON c.event_id = ge.event_id
        LEFT JOIN colleges cg ON c.gold_winner_college_id = cg.college_id
        LEFT JOIN colleges cs ON c.silver_winner_college_id = cs.college_id
        LEFT JOIN colleges cb ON c.bronze_winner_college_id = cb.college_id
        WHERE c.status = 'Results Approved' 
        ORDER BY c.approved_at DESC 
        LIMIT 1"; 

    $res_ticker = $conn->query($sql_ticker);
    if ($res_ticker) {
        $recent_winners = $res_ticker->fetch_all(MYSQLI_ASSOC);
    }

    // --- 3. FETCH LAST UPDATED TIME ---
    $lastUpdated = null;
    $sql_last_updated = "SELECT MAX(approved_at) AS last_updated FROM categories WHERE status = 'Results Approved'";
    $result_last_updated = $conn->query($sql_last_updated);
    
    if ($result_last_updated && $row_last_updated = $result_last_updated->fetch_assoc()) {
        $lastUpdated = $row_last_updated['last_updated'];
    }

    // --- 4. RETURN JSON ---
    echo json_encode([
        'success' => true,
        'medal_tally' => $medal_tally,
        'recent_winners' => $recent_winners, // <--- Added this for the ticker
        'last_updated' => $lastUpdated
    ]);

} catch (Exception $e) {
    // Handle Database Errors Gracefully
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage(),
        'medal_tally' => [],
        'last_updated' => null
    ]);
}

$conn->close();
?>