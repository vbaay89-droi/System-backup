<?php
// This file provides live data to the home.php page's auto-refresh.
require_once 'config.php'; // Make sure this path is correct

// --- FETCH MEDAL STANDINGS (FIXED LOGIC) ---
$medal_tally = [];

// **** FIX: This query is updated to match the new ranking logic (Gold > Silver > Bronze) AND fetch college_code ****
$sql = "SELECT 
            C.college_name, C.logo_url,
            C.college_code, -- --- FIX: Added college_code
            
            SUM(CASE WHEN Cat.gold_winner_college_id = C.college_id THEN Cat.gold_count ELSE 0 END) AS gold,
            SUM(CASE WHEN Cat.silver_winner_college_id = C.college_id THEN Cat.silver_count ELSE 0 END) AS silver,
            SUM(CASE WHEN Cat.bronze_winner_college_id = C.college_id THEN Cat.bronze_count ELSE 0 END) AS bronze,
            
            -- Calculate Total Medals --
            (SUM(CASE WHEN Cat.gold_winner_college_id = C.college_id THEN Cat.gold_count ELSE 0 END) +
             SUM(CASE WHEN Cat.silver_winner_college_id = C.college_id THEN Cat.silver_count ELSE 0 END) +
             SUM(CASE WHEN Cat.bronze_winner_college_id = C.college_id THEN Cat.bronze_count ELSE 0 END)) AS total
            
        FROM colleges C
        LEFT JOIN categories Cat ON (
            C.college_id = Cat.gold_winner_college_id OR 
            C.college_id = Cat.silver_winner_college_id OR 
            C.college_id = Cat.bronze_winner_college_id
        ) AND Cat.status = 'Results Approved'
        -- --- FIX: Added college_code to GROUP BY ---
        GROUP BY C.college_id, C.college_name, C.logo_url, C.college_code
        -- --- FIX: Updated ORDER BY for the new ranking logic (Gold > Silver > Bronze) ---
        ORDER BY gold DESC, silver DESC, bronze DESC, C.college_name ASC";
// **** END OF SQL FIX ****

$stmt = $conn->prepare($sql);

$success = false;
$error_message = '';

if ($stmt) {
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) {
            $medal_tally = $result->fetch_all(MYSQLI_ASSOC);
            $success = true;
        }
        $stmt->close();
    } else {
        $error_message = "Error executing statement: " . $stmt->error;
    }
} else {
    $error_message = "Error preparing statement: " . $conn->error;
}

// Get latest 'approved_at' time from the results table
$lastUpdated = null;
$sql_last_updated = "SELECT MAX(approved_at) AS last_updated FROM results WHERE status = 'Approved'";
$result_last_updated = $conn->query($sql_last_updated);
if ($result_last_updated && $row_last_updated = $result_last_updated->fetch_assoc()) {
    $lastUpdated = $row_last_updated['last_updated'];
}

// Close the main connection
$conn->close();

// Set the content type to JSON and output the data
header('Content-Type: application/json');
if ($success) {
    echo json_encode([
        'success' => true,
        'medal_tally' => $medal_tally,
        'last_updated' => $lastUpdated
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => $error_message,
        'medal_tally' => [],
        'last_updated' => null
    ]);
}
?>