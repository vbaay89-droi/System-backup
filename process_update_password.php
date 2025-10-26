<?php
session_start();
require_once 'db_connect.php'; // Include your database connection

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Check if the form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $user_id = $_SESSION['user_id'];

    // 1. Validate input
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $_SESSION['pass_error_msg'] = "Please fill in all fields.";
        header('Location: admin_settings.php');
        exit();
    }

    if ($new_password !== $confirm_password) {
        $_SESSION['pass_error_msg'] = "New passwords do not match.";
        header('Location: admin_settings.php');
        exit();
    }

    if (strlen($new_password) < 8) {
        $_SESSION['pass_error_msg'] = "New password must be at least 8 characters long.";
        header('Location: admin_settings.php');
        exit();
    }

    try {
        // 2. Get the user's current hashed password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = :id");
        $stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $user = $stmt->fetch();

        if ($user && password_verify($current_password, $user['password'])) {
            // 3. Password is correct, hash the new password
            $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // 4. Update the password in the database
            $update_stmt = $conn->prepare("UPDATE users SET password = :new_password WHERE id = :id");
            $update_stmt->bindParam(':new_password', $hashed_new_password);
            $update_stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
            
            if ($update_stmt->execute()) {
                $_SESSION['pass_success_msg'] = "Your password has been updated successfully!";
            } else {
                $_SESSION['pass_error_msg'] = "An error occurred. Please try again.";
            }

        } else {
            // 5. Password was incorrect
            $_SESSION['pass_error_msg'] = "The current password you entered was incorrect.";
        }

    } catch (PDOException $e) {
        $_SESSION['pass_error_msg'] = "Database error: " . $e->getMessage();
    }

} else {
    // Redirect if not a POST request
    $_SESSION['pass_error_msg'] = "Invalid request method.";
}

// Always redirect back to the settings page
header('Location: admin_settings.php');
exit();
?>