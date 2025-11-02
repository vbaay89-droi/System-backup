<?php
// Load PHPMailer classes
require __DIR__ . '/PHPMailer-master/src/Exception.php';
require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    // Server settings
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';    // Gmail SMTP server
    $mail->SMTPAuth   = true;
    $mail->Username   = 'vbaay89@gmail.com';  // Your Gmail address
    $mail->Password   = 'vthz porq dnhj frdc';     // Your Gmail App Password (Corrected)
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // Recipients
    $mail->setFrom('vbaay89@gmail.com', 'PIT Sports Tallying Test');
    $mail->addAddress('vbaay89@gmail.com');  // Send to yourself for testing

    // Content
    $mail->isHTML(true);
    $mail->Subject = 'PHPMailer Test';
    $mail->Body    = 'This is a <b>test email</b> sent from PHPMailer. If you received this, your settings are correct.';

    $mail->send();
    echo "✅ Test email sent successfully to vbaay89@gmail.com!";
} catch (Exception $e) {
    echo "❌ Email could not be sent. Error: {$mail->ErrorInfo}";
}
