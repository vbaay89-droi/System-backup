<?php
/* --- Database Connection --- */

// --- UPDATE THESE 4 VARIABLES ---
$db_host = 'localhost';
$db_name = 'users_db'; // <-- Put your database name here
$db_user = 'root'; // Your database username (often 'root' for XAMPP)
$db_pass = ''; // Your database password (often empty for XAMPP)
// ------------------------------

$dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";

try {
    // Create the PDO connection object
    $conn = new PDO($dsn, $db_user, $db_pass);
    
    // Set PDO attributes for better error handling
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    
} catch (PDOException $e) {
    // If connection fails, stop the script and show an error
    die("Connection failed: " . $e->getMessage());
}
?>