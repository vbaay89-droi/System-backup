<?php
header('Content-Type: application/json');
require_once 'config.php'; 

try {
    // --- 1. FETCH MEDAL STANDINGS ---
    $medal_tally = [];

    // FIX: Uses COALESCE to ensure '0' is returned instead of NULL for empty medals
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
            ) AND Cat.status = 'Results Approved' -- Fixed Status
            
            GROUP BY C.college_id, C.college_name, C.logo_url, C.college_code
            
            -- OLYMPIC RANKING LOGIC: Gold > Silver > Bronze > Name
            ORDER BY gold DESC, silver DESC, bronze DESC, C.college_name ASC";

    $result = $conn->query($sql);
    
    if ($result) {
        $medal_tally = $result->fetch_all(MYSQLI_ASSOC);
    }

    // --- 2. FETCH LAST UPDATED TIME ---
    // FIX: Changed table from 'results' to 'categories' to match your schema
    $lastUpdated = null;
    $sql_last_updated = "SELECT MAX(approved_at) AS last_updated FROM categories WHERE status = 'Results Approved'";
    $result_last_updated = $conn->query($sql_last_updated);
    
    if ($result_last_updated && $row_last_updated = $result_last_updated->fetch_assoc()) {
        $lastUpdated = $row_last_updated['last_updated'];
    }

    // --- 3. RETURN JSON ---
    echo json_encode([
        'success' => true,
        'medal_tally' => $medal_tally,
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