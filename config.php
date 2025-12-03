<?php
/* --- Database Connection (MySQLi) --- */

$whitelist = array('127.0.0.1', '::1', 'localhost');

// AUTOMATIC SWITCHER
if (in_array($_SERVER['SERVER_NAME'], $whitelist)) {
    // LOCALHOST (XAMPP)
    $db_host = 'localhost';
    $db_user = 'root';
    $db_pass = '';
    $db_name = 'users_db';
} else {
    // LIVE SERVER (InfinityFree)
    $db_host = 'sql301.infinityfree.com';
    $db_user = 'if0_40511752';
    $db_pass = 'oXJwAMV5exP';
    $db_name = 'if0_40511752_medaltally';
}

$charset = 'utf8mb4';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $conn->set_charset($charset);
} catch (mysqli_sql_exception $e) {
    // STOP SCRIPT AND SHOW ERROR
    die("<h1>Database Connection Failed</h1><br>Error Message: " . $e->getMessage());
}

/* --- GLOBAL FUNCTIONS --- */

/**
 * Log Activity Function
 * Added to fix "Call to undefined function" error.
 * Handles 8 arguments to support event_manager_matches.php structure.
 */
if (!function_exists('log_activity')) {
    function log_activity($conn, $user_id, $action_type, $related_id, $related_table, $category_id = null, $category_table = null, $context = []) {
        
        // 1. Merge the extra category info into the context array so it is saved in the JSON column
        if ($category_id !== null) {
            $context['linked_category_id'] = $category_id;
        }
        if ($category_table !== null) {
            $context['linked_table_type'] = $category_table;
        }
        
        // 2. Convert the array to JSON
        $json_context = json_encode($context);
        
        // 3. Insert into system_logs
        // We use the 5 standard columns: actor, action, id, table, context
        $stmt = $conn->prepare("INSERT INTO system_logs (actor_user_id, action_type, related_id, related_table, log_context) VALUES (?, ?, ?, ?, ?)");
        
        if ($stmt) {
            $stmt->bind_param("isiss", $user_id, $action_type, $related_id, $related_table, $json_context);
            $stmt->execute();
            $stmt->close();
        }
    }
}
?>