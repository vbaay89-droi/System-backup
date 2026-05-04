<?php
// fetch_all_events_api.php

// 1. TURN ON ERROR REPORTING (Temporary, for debugging)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 2. CHECK IF CONFIG EXISTS
if (!file_exists('config.php')) {
    die("CRITICAL ERROR: 'config.php' was not found in this folder. Make sure this file is in the same folder as config.php.");
}

require_once 'config.php';

// 3. CHECK DATABASE CONNECTION
if (!isset($conn)) {
    die("CRITICAL ERROR: The database variable '$conn' is missing. Check your config.php file.");
}

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

// 4. PREPARE THE QUERY
header('Content-Type: application/json');

$events = [];

$sql = "
    SELECT 
        c.category_id AS event_id,
        
        c.category_name AS event_name,
        c.division_name,
        c.notes AS description,  /* Changed 'description' to 'notes' based on your other file */
        c.event_date,
        c.event_time,
        c.venue,
        
        CASE 
            WHEN c.status = 'Results Approved' THEN 'Completed' 
            ELSE c.status 
        END AS event_status,
        
        ge.event_name AS category,
        g.game_name AS sport_name,
        
        COALESCE(u.full_name, u.username) AS manager_name,
        u.profile_picture AS manager_photo
        
    FROM categories c
    JOIN game_events ge ON c.event_id = ge.event_id
    JOIN games g ON ge.game_id = g.game_id
    
    LEFT JOIN tournament_manager_assignments ema ON ge.event_id = ema.event_id
    LEFT JOIN users u ON ema.user_id = u.id
    
    WHERE c.status != 'Draft' AND c.status IS NOT NULL 
    
    ORDER BY g.game_name, ge.event_name, c.category_name
";

$result = $conn->query($sql);

if (!$result) {
    // If query fails, show the SQL error
    die("SQL ERROR: " . $conn->error);
}

while ($row = $result->fetch_assoc()) {
        // Sanitize null values
        $row['description'] = $row['description'] ?? 'No description provided.';
        $row['event_date'] = $row['event_date'] ?? 'TBA';
        $row['event_time'] = $row['event_time'] ?? 'TBA';
        $row['manager_name'] = $row['manager_name'] ?? null;
        $row['manager_photo'] = $row['manager_photo'] ?? null; // <-- Added this line
        
        $events[] = $row;
    }

// Return the data
echo json_encode($events);

$conn->close();
?>