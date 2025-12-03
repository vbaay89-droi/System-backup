<?php
// Force PHP to show errors on the screen
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h2>Starting Connection Test...</h2>";

// --- INFINITYFREE CREDENTIALS (HARDCODED) ---
$server = "sql301.infinityfree.com";
$user   = "if0_40511752";
$pass   = "oXJwAMV5exP";
$db     = "if0_40511752_medaltally";

// Attempt Connection
$conn = new mysqli($server, $user, $pass, $db);

// Check Result
if ($conn->connect_error) {
    echo "<h3 style='color:red'>FAILED: " . $conn->connect_error . "</h3>";
} else {
    echo "<h3 style='color:green'>SUCCESS! Connected to Database.</h3>";
}
?>