<?php
/* --- Database Connection (MySQLi) --- */

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'users_db';
$charset = 'utf8mb4';

$conn = null;
$db_connection_error = null;

// This line tells MySQLi to throw exceptions on errors
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // Create the MySQLi connection object
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    // Set the character set
    $conn->set_charset($charset);
    
} catch (mysqli_sql_exception $e) {
    // If connection fails, store the error message
    // This allows the dashboard to display the error
    $db_connection_error = $e->getMessage();
}

// -----------------------------------------------------------------
// --- ADD THE NEW LOG ACTIVITY FUNCTION BELOW YOUR CONNECTION ---
// -----------------------------------------------------------------

/**
 * Logs a structured activity to the system_logs table.
 *
 * @param mysqli $conn The database connection.
 * @param int $actor_id The ID of the user performing the action.
 * @param string $action_type A code for the action (e.g., 'DELETED_CATEGORY').
 * @param int|null $subject_id The ID of the primary item being acted on (e.g., event_id).
 * @param string|null $subject_type The type of the primary item (e.g., 'event').
 * @param int|null $target_id The ID of a secondary item (e.g., user_id of an assigned manager).
 * @param string|null $target_type The type of the secondary item (e.g., 'user').
 * @param array|null $context Extra data to store as JSON (e.g., deleted item's name).
 */
function log_activity($conn, $actor_id, $action_type, $subject_id = null, $subject_type = null, $target_id = null, $target_type = null, $context = null) {
    
    // Do not try to log if the database connection failed
    if ($conn === null || $conn->connect_error) {
        error_log("log_activity: Database connection is not available.");
        return;
    }

    $sql = "INSERT INTO system_logs (actor_user_id, action_type, subject_id, subject_type, target_id, target_type, log_context, log_message) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
    // Encode context as JSON
    $log_context_json = ($context) ? json_encode($context) : null;

    // Create a simple fallback message for database viewing
    $fallback_message = "$action_type by User $actor_id";

    try {
        $stmt = $conn->prepare($sql);
        
        // bind_param types: i=int, s=string
        $stmt->bind_param("isississ", 
            $actor_id, 
            $action_type, 
            $subject_id, 
            $subject_type, 
            $target_id, 
            $target_type, 
            $log_context_json, 
            $fallback_message
        );
        
        $stmt->execute();
        $stmt->close();

    } catch (mysqli_sql_exception $e) {
        // Log the error to your server's error log
        error_log("Failed to log activity: " . $e->getMessage());
    }
}
?>