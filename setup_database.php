<?php
require_once 'config.php';

// Read and execute the SQL file
$sql = file_get_contents('create_basketball_table.sql');

if ($conn->multi_query($sql)) {
    echo "Basketball matches table created successfully!";
} else {
    echo "Error creating table: " . $conn->error;
}

$conn->close();
?> 