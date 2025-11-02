<?php
// Load PHPMailer classes
require __DIR__ . '/PHPMailer-master/src/Exception.php';
require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Sends a verification email for a new email address.
 *
 * @param string $to_email The new email address to send to.
 * @param string $token The verification token.
 * @param string $full_name The user's full name for personalization.
 * @return bool|string True on success, or an error message string on failure.
 */
function sendVerificationEmail($to_email, $token, $full_name) {
    
    // --- IMPORTANT: Update this URL to your domain/path ---
    // This path is based on your previous files.
    $verification_link = "http://localhost/LOGIN_CAPSTONE/verify_new_email.php?token=" . $token;
    // --------------------------------------------------

    $mail = new PHPMailer(true);

    try {
        // Server settings from your test file
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'vbaay89@gmail.com';  // Your Gmail address
        $mail->Password   = 'vthz porq dnhj frdc';     // Your Gmail App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('vbaay89@gmail.com', 'PIT Sports Tallying');
        $mail->addAddress($to_email); // Send to the NEW email address

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Verify Your New Email Address';
        $mail->Body    = "Hello " . htmlspecialchars($full_name) . ",<br><br>"
                       . "Please click the link below to confirm your new email address:<br>"
                       . "<a href='$verification_link'>$verification_link</a><br><br>"
                       . "This link will expire in 15 minutes.<br><br>"
                       . "If you did not request this change, please ignore this email.";

        $mail->send();
        return true; // Success!

    } catch (Exception $e) {
        // Return the error message
        return "Mailer Error: {$mail->ErrorInfo}";
    }
}
?>
