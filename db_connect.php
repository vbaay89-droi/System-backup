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
?>