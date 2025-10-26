<?php
session_start();
require_once 'db_connect.php'; // Include your database connection

// Check if user is logged in and token is in the URL
if (!isset($_SESSION['user_id']) || !isset($_GET['token'])) {
    $_SESSION['email_error_msg'] = "Invalid verification link or you are not logged in.";
    header('Location: admin_settings.php');
    exit();
}

$token_from_url = $_GET['token'];

// Check if we have the session variables we're expecting
if (!isset($_SESSION['email_change_token'], $_SESSION['email_change_new_email'], $_SESSION['email_change_expiry'])) {
    $_SESSION['email_error_msg'] = "Invalid or expired verification session. Please try again.";
    header('Location: admin_settings.php');
    exit();
}

// --- Main Verification Logic ---

try {
    // 1. Check if token is expired
    if (time() > $_SESSION['email_change_expiry']) {
        $_SESSION['email_error_msg'] = "Your verification link has expired. Please try again.";
    
    // 2. Check if token matches
    } elseif ($token_from_url !== $_SESSION['email_change_token']) {
        $_SESSION['email_error_msg'] = "Invalid verification token. Please try again.";
    
    // 3. All checks passed!
    } else {
        
        $new_email = $_SESSION['email_change_new_email'];
        $user_id = $_SESSION['user_id'];

        // (Final check) Make sure new email isn't taken by someone else
        // This is a safety check in case another user took the email
        // in the few minutes since the link was sent.
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
        $stmt->bindParam(':email', $new_email);
        $stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
             $_SESSION['email_error_msg'] = "That email address was just registered by another user. Please try a different email.";
        } else {
            // SUCCESS: Update the user's email in the database
            $update_stmt = $conn->prepare("UPDATE users SET email = :new_email WHERE id = :id");
            $update_stmt->bindParam(':new_email', $new_email);
            $update_stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
            
            if ($update_stmt->execute()) {
                $_SESSION['email_success_msg'] = "Success! Your email address has been updated to " . htmlspecialchars($new_email) . ".";
            } else {
                $_SESSION['email_error_msg'] = "An error occurred while updating your email. Please try again.";
            }
        }
    }

} catch (PDOException $e) {
    $_SESSION['email_error_msg'] = "Database error: " . $e->getMessage();
}

// --- Cleanup ---
// Always clear the temporary session variables after use
unset($_SESSION['email_change_token']);
unset($_SESSION['email_change_new_email']);
unset($_SESSION['email_change_expiry']);

// Redirect back to settings page
header('Location: admin_settings.php');
exit();
?>