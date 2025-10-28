<?php
session_start();

// Simple check to protect the page
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Event Manager') {
    header('Location: login.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-M">
  <title>Event Manager Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
  <div class="container mt-5">
    <h1>Welcome, Event Manager (<?php echo htmlspecialchars($_SESSION['username']); ?>)!</h1>
    <p>This is your special dashboard. Only Event Managers can see this page.</p>
    <a href="login.php" class="btn btn-danger">Logout</a>
  </div>
</body>
</html>