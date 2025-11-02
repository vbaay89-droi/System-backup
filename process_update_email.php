<?php
session_start();
require_once 'db_connect.php'; // Include your database connection

// --- LOAD PHPMailer ---
require __DIR__ . '/PHPMailer-master/src/Exception.php';
require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// ------------------------


// Check if user is logged in and has a role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: login.php');
    exit();
}

// --- Determine settings page based on user role ---
// This ensures users are redirected to their correct settings page
$settings_page = 'login.php'; // Default fallback
switch ($_SESSION['role']) {
    case 'Administrator':
        $settings_page = 'admin_settings.php';
        break;
    case 'Event Manager':
        $settings_page = 'event_manager_settings.php'; // Assuming this is the name
        break;
    case 'Sports Director':
        $settings_page = 'sd/sports_director_settings.php'; // Assuming this path based on your dashboard
        break;
    default:
        // If role is unknown, log out for safety
        session_destroy();
        header('Location: login.php');
        exit();
}
// --------------------------------------------------


// Check if the form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $new_email = trim($_POST['new_email']);
    $current_password = $_POST['current_password'];
    $user_id = $_SESSION['user_id'];

    // 1. Validate input
    if (empty($new_email) || empty($current_password)) {
        $_SESSION['email_error_msg'] = "Please fill in all fields.";
        header('Location: ' . $settings_page);
        exit();
    }

    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['email_error_msg'] = "Invalid email format.";
        header('Location: ' . $settings_page);
        exit();
    }

    try {
        // 2. Get the user's current hashed password from the database
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = :id");
        $stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $user = $stmt->fetch();

        if ($user && password_verify($current_password, $user['password'])) {
            // 3. Password is correct.
            
            // (Optional but recommended) Check if new email is already taken
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email");
            $stmt->bindParam(':email', $new_email);
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                $_SESSION['email_error_msg'] = "That email address is already in use by another account.";
                header('Location: ' . $settings_page);
                exit();
            }

            // 4. Generate a secure token and expiry time
            $token = bin2hex(random_bytes(32)); // 64-character token
            $token_expiry = time() + 3600; // Token is valid for 1 hour

            // 5. Store the token, new email, and expiry in the session
            $_SESSION['email_change_token'] = $token;
            $_SESSION['email_change_new_email'] = $new_email;
            $_SESSION['email_change_expiry'] = $token_expiry;

            // 6. Send the verification email
            // --- IMPORTANT: Update this URL to your domain/path ---
            // Using http://localhost/LOGIN_CAPSTONE/ based on your original file
            $verification_link = "http://localhost/LOGIN_CAPSTONE/verify_new_email.php?token=" . $token;
            // --------------------------------------------------

            $mail = new PHPMailer(true);
            try {
                // Using the same mail settings from your login.php
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'vbaay89@gmail.com';
                $mail->Password   = 'vthz porq dnhj frdc'; // app password
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                $mail->setFrom('vbaay89@gmail.com', 'PIT Sports Tallying');
                $mail->addAddress($new_email); // Send to the NEW email address

                $mail->isHTML(true);
                $mail->Subject = 'Verify Your New Email Address';
                $mail->Body    = "Hello,<br><br>Please click the link below to confirm your new email address:<br>"
                               . "<a href='$verification_link'>$verification_link</a><br><br>"
                               . "This link will expire in 1 hour.<br><br>"
                               . "If you did not request this change, please ignore this email.";

                $mail->send();

                $_SESSION['email_success_msg'] = "Password confirmed! A verification link has been sent to " . htmlspecialchars($new_email) . ". Please check your inbox.";

            } catch (Exception $e) {
                $_SESSION['email_error_msg'] = "Password was correct, but we couldn't send the verification email. Mailer Error: {$mail->ErrorInfo}";
            }

        } else {
            // 4. Password was incorrect
            $_SESSION['email_error_msg'] = "The password you entered was incorrect.";
        }

    } catch (PDOException $e) {
        $_SESSION['email_error_msg'] = "Database error: " . $e->getMessage();
    }

} else {
    // Redirect if not a POST request
    $_SESSION['email_error_msg'] = "Invalid request method.";
}

// Always redirect back to the user's correct settings page
header('Location: ' . $settings_page);
exit();
?>
