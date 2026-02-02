<?php
session_start();
require_once 'config.php'; 

// Define current page for active nav-link
$current_page = basename($_SERVER['PHP_SELF']);

// Load PHPMailer
require __DIR__ . '/PHPMailer-master/src/Exception.php';
require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- Handle status messages from email verification ---
$status_message = '';
$status_message_type = 'info'; 

if (isset($_GET['status'])) {
    if ($_GET['status'] === 'success') {
        $status_message = "Account request submitted! Please wait for approval.";
        $status_message_type = "success";
    }
}

// Login logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error_message = "Please enter both username and password.";
    } else {
        try {
            $stmt = $conn->prepare('
                SELECT id, username, email, password, role, status 
                FROM users 
                WHERE username = ? 
                LIMIT 1
            ');

            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user['password'])) {
                
                // --- NEW CHECK START ---
                if ($user['status'] === 'inactive') {
                    // If user is Inactive, STOP here and show error
                    $error_message = "Your account is currently inactive. Please contact the Sports Director.";
                } else {
                $otp = random_int(100000, 999999);
                $_SESSION['otp'] = $otp;
                $_SESSION['otp_expiry'] = time() + 300;
                $_SESSION['pending_user_id'] = $user['id'];
                $_SESSION['pending_username'] = $user['username'];
                $_SESSION['pending_user_role'] = $user['role'];

                // Send OTP via PHPMailer
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'vbaay89@gmail.com';
                    $mail->Password   = 'vthz porq dnhj frdc';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    $mail->setFrom('vbaay89@gmail.com', 'PIT Sports Tallying');
                    $mail->addAddress($user['email']); 

                    $mail->isHTML(true);
                    $mail->Subject = 'Verify Your Login - PIT Sports Tallying';
                    $mail->Body    = '
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <style>
                            body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
                            .email-wrapper { width: 100%; background-color: #f4f4f4; padding: 20px 0; }
                            .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.1); border-top: 5px solid #4CAF50; }
                            .header { background-color: #1a1a1a; padding: 30px; text-align: center; }
                            .header h1 { margin: 0; color: #ffffff; font-size: 20px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; }
                            .content { padding: 40px 30px; text-align: center; color: #333333; }
                            .otp-box { background-color: #f0fdf4; border-radius: 8px; display: inline-block; padding: 15px 40px; margin: 25px 0; font-size: 32px; font-weight: bold; letter-spacing: 6px; color: #2E7D32; }
                            .warning-text { font-size: 14px; color: #757575; margin-top: 10px; }
                            .footer { background-color: #eeeeee; padding: 20px; text-align: center; font-size: 12px; color: #888888; }
                            .footer p { margin: 5px 0; }
                        </style>
                    </head>
                    <body>
                        <div class="email-wrapper">
                            <div class="email-container">
                                <div class="header">
                                    <h1>PIT Siglakas Medal Tallying</h1>
                                </div>
                                
                                <div class="content">
                                    <h2 style="color: #333; margin-top: 0;">Login Verification</h2>
                                    <p style="font-size: 16px; color: #555;">Hello,</p>
                                    <p style="font-size: 16px; color: #555;">To complete your login, please use the One-Time Password (OTP) below.</p>
                                    
                                    <div class="otp-box">' . $otp . '</div>
                                    
                                    <p class="warning-text">This code is valid for <b>5 minutes</b>.<br>If you did not request this code, please ignore this email.</p>
                                </div>
                                
                                <div class="footer">
                                    <p>&copy; ' . date("Y") . ' PIT Sports Tallying System. All rights reserved.</p>
                                    <p>Palompon Institute of Technology</p>
                                </div>
                            </div>
                        </div>
                    </body>
                    </html>';

                    $mail->send();
                    header('Location: verify_otp.php');
                    exit();
                } catch (Exception $e) {
                    error_log("Mailer Error: {$mail->ErrorInfo}");
                    $error_message = "An error occurred while sending the OTP. Please try again later.";
                }
              }
            } else {
                $error_message = "Invalid username or password.";
            }
        } catch (Exception $e) {
            error_log("Database Error: {$e->getMessage()}");
            $error_message = "An internal error occurred. Please try again later.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login - SmartScore PIT</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

  <style>
    :root {
      --primary-green: #4CAF50;
      --primary-dark: #2E7D32;
      --accent-gold: #FFD700;
      --text-dark: #1A1A1A;
      --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
      --shadow-lg: 0 8px 32px rgba(0,0,0,0.12);
      --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    * { 
      margin: 0; 
      padding: 0; 
      box-sizing: border-box; 
    }

    body {
      margin: 0;
      padding: 0;
      min-height: 100vh;
      font-family: 'Inter', sans-serif;
      display: flex;
      flex-direction: column;
      position: relative;
      overflow-x: hidden;
    }

    /* Background with overlay */
    body::before {
      content: '';
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: url('images/featured_image.png');
      background-size: cover;
      z-index: -2;
    }

    body::after {
      content: '';
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: linear-gradient(135deg, rgba(0,0,0,0.7) 0%, rgba(0,0,0,0.5) 50%, rgba(0,0,0,0.7) 100%);
      z-index: -1;
    }

    /* Animated particles */
    .particles {
      position: fixed;
      top: 0; 
      left: 0; 
      width: 100%; 
      height: 100%;
      pointer-events: none; 
      z-index: 1;
    }

    .particle {
      position: absolute;
      background: rgba(255, 255, 255, 0.3);
      border-radius: 50%;
      animation: float 20s infinite;
    }

    @keyframes float {
      0%, 100% { 
        transform: translateY(0) translateX(0) rotate(0deg); 
        opacity: 0; 
      }
      10% { opacity: 1; } 
      90% { opacity: 1; }
      100% { 
        transform: translateY(-100vh) translateX(100px) rotate(360deg); 
        opacity: 0; 
      }
    }

    /* Navbar Styles */
    .navbar {
        background: rgba(26, 26, 26, 0.98) !important;
        backdrop-filter: blur(20px);
        box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        padding: 1rem 0;
        position: relative;
        z-index: 1000;
    }

    .navbar::before {
        content: ''; 
        position: absolute; 
        bottom: 0; 
        left: 0; 
        right: 0; 
        height: 3px;
        background: linear-gradient(90deg, transparent, var(--primary-green), var(--accent-gold), transparent);
        animation: borderGlow 3s ease-in-out infinite;
    }

    @keyframes borderGlow { 
      0%, 100% { opacity: 0.5; } 
      50% { opacity: 1; } 
    }

    .navbar-brand { 
      transition: var(--transition); 
    }

    .navbar-brand:hover { 
      transform: translateY(-2px) scale(1.02); 
    }

    .brand-logo { 
        filter: drop-shadow(0 4px 8px rgba(255,255,255,0.3));
        transition: filter 0.3s;
        animation: pulse 2s ease-in-out infinite;
    }

    @keyframes pulse { 
      0%, 100% { filter: drop-shadow(0 4px 8px rgba(255,255,255,0.3)); } 
      50% { filter: drop-shadow(0 6px 12px rgba(255,255,255,0.5)); } 
    }

    .brand-heading { 
        font-family: 'Poppins', sans-serif; 
        font-weight: 700; 
        letter-spacing: -0.5px;
        transition: color 0.3s;
        background: linear-gradient(90deg, #fff, #4CAF50);
        -webkit-background-clip: text; 
        background-clip: text;
    }

    .nav-link { 
        font-weight: 500; 
        font-size: 0.95rem; 
        padding: 0.5rem 1.25rem !important; 
        margin: 0 0.25rem; 
        border-radius: 12px; 
        transition: var(--transition); 
        position: relative; 
        overflow: hidden;
    }

    .nav-link { 
            font-weight: 500; 
            font-size: 0.95rem; 
            padding: 0.5rem 1.25rem !important; 
            margin: 0 0.25rem; 
            transition: var(--transition); 
            /* Remove border-radius so the line is straight */
            border-bottom: 3px solid transparent; 
        }

        .nav-link:hover, .nav-link.active { 
            /* Remove the background box */
            background: transparent !important; 
            
            /* Change text color */
            color: var(--primary-green) !important; 
            
            /* Add the Underline */
            border-bottom: 3px solid var(--primary-green); 
        }

    .btn-danger, .btn-success { 
        padding: 0.6rem 1.5rem; 
        border-radius: 12px; 
        font-weight: 600; 
        transition: var(--transition); 
        border: none; 
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        position: relative; 
        overflow: hidden;
    }

    .btn-danger:hover, .btn-success:hover { 
      transform: translateY(-3px); 
      box-shadow: 0 8px 25px rgba(0,0,0,0.3); 
    }

    /* Main Content */
    .main-content {
      flex: 1; 
      display: flex; 
      justify-content: center; 
      align-items: flex-start; 
      padding: 3rem 0; 
      position: relative; 
      z-index: 10;
    }

    .login-wrapper {
      width: 100%;
      max-width: 1400px;
      margin: 0 auto;
      padding: 0 2rem;
    }

    /* Left Side - Branding */
    .brand-section {
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: flex-start; 
      text-align: left; 
      animation: fadeInLeft 0.8s ease-out;
      padding: 2rem;
    }

    @keyframes fadeInLeft { 
      from { opacity: 0; transform: translateX(-50px); } 
      to { opacity: 1; transform: translateX(0); } 
    }

    .logo-container {
      display: flex;
      justify-content: flex-start; 
      align-items: center;
      gap: 2rem;
      margin-bottom: 2rem;
    }

    .main-logo {
      width: 120px; /* Increased slightly since background is gone */
      height: 120px;
      object-fit: contain;
      filter: drop-shadow(0 5px 15px rgba(0,0,0,0.4)); 
      animation: logoFloat 3s ease-in-out infinite;
      transition: transform 0.3s ease;
      /* removed border-radius and background */
    }

    .main-logo:hover {
      transform: scale(1.1) rotate(5deg);
      filter: drop-shadow(0 8px 20px rgba(255,215,0,0.6)); 
    }

    @keyframes logoFloat { 
      0%, 100% { transform: translateY(0); } 
      50% { transform: translateY(-15px); } 
    }

    .brand-title {
      font-family: 'Poppins', sans-serif;
      font-weight: 900;
      font-size: 3.5rem;
      line-height: 1.3;
      color: white;
      text-shadow: 0 4px 20px rgba(0,0,0,0.5), 0 0 40px rgba(255,255,255,0.3);
      margin: 0;
      background: linear-gradient(135deg, #ffffff 0%, #FFD700 50%, #4CAF50 100%);
      -webkit-background-clip: text;
      background-clip: text;
      -webkit-text-fill-color: transparent;
      animation: titleGlow 3s ease-in-out infinite;
    }

    @keyframes titleGlow {
      0%, 100% { text-shadow: 0 4px 20px rgba(0,0,0,0.5), 0 0 40px rgba(255,255,255,0.3); }
      50% { text-shadow: 0 4px 30px rgba(0,0,0,0.7), 0 0 60px rgba(255,215,0,0.6); }
    }

    /* Right Side - Login Form */
    .login-section {
      display: flex;
      justify-content: center;
      align-items: center;
      animation: fadeInRight 0.8s ease-out;
    }

    @keyframes fadeInRight { 
      from { opacity: 0; transform: translateX(50px); } 
      to { opacity: 1; transform: translateX(0); } 
    }

    .login-container {
      background: rgba(255, 255, 255, 0.98);
      backdrop-filter: blur(30px);
      border-radius: 24px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.5), 
                  0 0 100px rgba(76, 175, 80, 0.2),
                  inset 0 0 100px rgba(255,255,255,0.1);
      padding: 3rem 2.5rem;
      width: 100%;
      max-width: 480px;
      position: relative;
      overflow: hidden;
      border: 2px solid rgba(255,255,255,0.3);
      margin-bottom: 2rem; 
    }

    .login-container::before {
      content: '';
      position: absolute;
      top: -50%;
      left: -50%;
      width: 200%;
      height: 200%;
      background: linear-gradient(45deg, transparent, rgba(76, 175, 80, 0.1), transparent);
      animation: shine 4s infinite;
    }

    @keyframes shine { 
      0% { transform: rotate(0deg); } 
      100% { transform: rotate(360deg); } 
    }

    .login-container::after {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 5px;
      background: linear-gradient(90deg, var(--primary-green), var(--accent-gold), var(--primary-green));
      background-size: 200% 100%;
      animation: gradientMove 3s ease infinite;
    }

    @keyframes gradientMove {
      0%, 100% { background-position: 0% 50%; }
      50% { background-position: 100% 50%; }
    }

    .login-container h2 {
      font-weight: 800;
      color: #1A1A1A;
      font-family: 'Poppins', sans-serif;
      margin-bottom: 2rem;
      position: relative;
      display: inline-block;
      font-size: 1.8rem;
    }

    .login-container h2::after {
      content: '';
      position: absolute;
      bottom: -10px;
      left: 50%;
      transform: translateX(-50%);
      width: 60px;
      height: 4px;
      background: linear-gradient(90deg, var(--primary-green), var(--accent-gold));
      border-radius: 2px;
      animation: expandWidth 2s ease-in-out infinite;
    }

    @keyframes expandWidth { 
      0%, 100% { width: 60px; } 
      50% { width: 100px; } 
    }
    
    .form-label { 
      text-align: left; 
      display: block; 
      margin-bottom: 10px; 
      font-weight: 600; 
      color: #333; 
      font-size: 0.95rem; 
    }

    .form-control {
      width: 100%;
      padding: 14px 14px 14px 3rem;
      font-size: 1rem;
      border-radius: 12px;
      border: 2px solid #e0e0e0;
      margin-bottom: 1.2rem;
      transition: var(--transition);
      background: #f8f9fa;
    }

    .form-control:focus { 
      border-color: var(--primary-green); 
      box-shadow: 0 0 0 4px rgba(76, 175, 80, 0.1); 
      background: white; 
      outline: none; 
    }

    .position-relative i { 
      color: #999; 
      font-size: 1.1rem; 
      z-index: 10; 
    }

    .btn-login {
      width: 100%;
      padding: 15px 0;
      font-size: 1.15rem;
      font-weight: 700;
      border-radius: 12px;
      transition: var(--transition);
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      border: none;
      color: white;
      text-transform: uppercase;
      letter-spacing: 1px;
      box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
      position: relative;
      overflow: hidden;
    }

    .btn-login:hover { 
      transform: translateY(-3px); 
      box-shadow: 0 12px 30px rgba(102, 126, 234, 0.5); 
    }

    .alert { 
      border-radius: 12px; 
      border: none; 
      padding: 1rem 1.25rem; 
      animation: slideDown 0.5s ease-out; 
    }

    @keyframes slideDown { 
      from { opacity: 0; transform: translateY(-20px); } 
      to { opacity: 1; transform: translateY(0); } 
    }

    /* Request Account Link Styling */
    .request-account-link {
      color: var(--primary-green);
      font-weight: 600;
      text-decoration: none;
      position: relative;
      transition: var(--transition);
      display: inline-block;
    }

    .request-account-link::after {
      content: '';
      position: absolute;
      width: 0;
      height: 2px;
      bottom: -2px;
      left: 0;
      background: linear-gradient(90deg, var(--primary-green), var(--accent-gold));
      transition: width 0.3s ease;
    }

    .request-account-link:hover {
      color: var(--primary-dark);
      transform: translateX(3px);
    }

    .request-account-link:hover::after {
      width: 100%;
    }

    /* Footer */
    footer { 
      flex-shrink: 0; 
      width: 100%; 
      background: rgba(26, 26, 26, 0.98); 
      backdrop-filter: blur(20px); 
      position: relative; 
      z-index: 100; 
    }

    footer::before { 
      content: ''; 
      position: absolute; 
      top: 0; 
      left: 0; 
      right: 0; 
      height: 3px; 
      background: linear-gradient(90deg, transparent, var(--primary-green), var(--accent-gold), transparent); 
    }

    /* Responsive Design */
    @media (max-width: 991.98px) {
      .brand-section {
        margin-bottom: 2rem;
        align-items: center; 
        text-align: center; 
      }

      .logo-container {
        gap: 1.5rem;
        justify-content: center; 
      }

      .main-logo {
        width: 100px;
        height: 100px;
      }

      .brand-title {
        font-size: 1.8rem;
      }

      .login-container {
        padding: 2rem 1.5rem;
      }

      .main-content {
        align-items: center;
      }
    }

    /* --- FOOTER STYLES (MATCHING HOME.PHP) --- */
    .footer-main {
        flex-shrink: 0;
        background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
        color: rgba(255,255,255,0.7);
        padding: 3rem 0 2rem 0;
        box-shadow: 0 -4px 20px rgba(0,0,0,0.15);
        position: relative;
        z-index: 1;
    }

    .footer-main .footer-logo-group {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 1rem;
    }

    .footer-main .footer-logo-group img {
        height: 50px !important;
        width: 50px !important;
        object-fit: contain;
    }

    .footer-main .footer-logo-group h5 {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 700;
        color: #fff;
        line-height: 1.2;
    }

    .footer-main p {
        font-size: 0.9rem;
        max-width: 400px;
    }

    .footer-main h6 {
        font-family: 'Poppins', sans-serif;
        color: #fff;
        font-weight: 600;
        margin-bottom: 1rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .footer-main .footer-links {
        list-style: none;
        padding: 0;
    }

    .footer-main .footer-links li {
        margin-bottom: 0.5rem;
    }

    .footer-main .footer-links a {
        text-decoration: none;
        color: rgba(255,255,255,0.7);
        transition: var(--transition);
    }

    .footer-main .footer-links a:hover {
        color: #fff;
        padding-left: 5px;
    }

    .footer-bottom {
        border-top: 1px solid rgba(255,255,255,0.1);
        padding-top: 1.5rem;
        margin-top: 2rem;
        text-align: center;
        font-size: 0.85rem;
    }

    @media (max-width: 767.98px) {
      .logo-container {
        gap: 1rem;
      }
      .main-logo {
        width: 80px;
        height: 80px;
      }
      .brand-title {
        font-size: 1.5rem;
      }
      .login-container h2 {
        font-size: 1.5rem;
      }
    }

    @media (max-width: 991px) {
            /* 1. Center text on smaller screens */
            .footer-main { 
                text-align: center; 
            }
            
            /* 2. Center the logo group (Image + Text) */
            .footer-main .footer-logo-group { 
                justify-content: center; 
            }
            
            /* 3. Add spacing between columns so they don't look cramped */
            .footer-main .row > div { 
                margin-bottom: 2rem; 
            }
            
            /* 4. Ensure the last column doesn't have extra margin */
            .footer-main .row > div:last-child {
                margin-bottom: 0;
            }
        }
  </style>
</head>

<body>
  <div class="particles" id="particles"></div>

  <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid d-flex align-items-center justify-content-between">
        <a class="navbar-brand d-flex align-items-center interactive-brand" href="home.php" style="cursor: pointer;">
            <img src="imageslogo.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain;">
            <div class="d-flex flex-column lh-sm">
                <strong class="text-white brand-heading" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                <small class="text-light brand-subheading" style="font-size: 0.75rem;">Official College Tournament System</small>
            </div>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
            aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == 'home.php') ? 'active' : '' ?>" href="home.php">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == 'Eventpage.php') ? 'active' : '' ?>" href="Eventpage.php">Events</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == 'college_team.php') ? 'active' : '' ?>" href="college_team.php">Teams</a>
                </li>
                <li class="nav-item">
                    <?php if (isset($_SESSION['email'])): ?>
                        <a href="logout.php" class="btn btn-danger ms-3">Logout</a>
                    <?php else: ?>
                        <a href="login.php" class="btn btn-success ms-3">Admin Login</a>
                    <?php endif; ?>
                </li>
            </ul>
        </div>
    </div>
  </nav>

  <div class="main-content">
    <div class="login-wrapper">
      <div class="row align-items-start">

        <div class="col-lg-7 brand-section">
          <div class="logo-container">
            <img src="imageslogo.png" alt="PIT Logo" class="main-logo">
            <img src="images/COTE.png" alt="Siglakas Logo" class="main-logo">
          </div>
          <h1 class="brand-title">
            SmartScore: A Web-Based Medal Tally Platform for Siglakas Events
          </h1>
        </div>

        <div class="col-lg-5 login-section pt-lg-5 mt-lg-4">
          <div class="login-container">

            <div class="text-center">
              <h2>Login to Smartscore</h2>
            </div>

            <?php if (!empty($status_message)): ?>
                <div class="alert alert-<?= htmlspecialchars($status_message_type) ?> alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <?= htmlspecialchars($status_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="login-view" style="opacity: 1; display: block;">
              
              <?php if (isset($error_message)): ?>
                <div class="alert alert-danger" role="alert">
                  <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
                </div>
              <?php endif; ?>

              <form action="login.php" method="POST">
                <div class="mb-4 text-start">
                  <label for="username" class="form-label">Username</label>
                  <div class="position-relative">
                    <input type="text" class="form-control" id="username" name="username" placeholder="Enter username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    <i class="fas fa-user position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                  </div>
                </div>
                <div class="mb-4 text-start">
                  <label for="password" class="form-label">Password</label>
                  <div class="position-relative">
                    <input type="password" class="form-control" id="password" name="password" placeholder="Enter password" required>
                    <i class="fas fa-lock position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                  </div>
                </div>

                <div class="d-flex justify-content-end mb-3" style="margin-top: -10px; position: relative; z-index: 10;">
                    <a href="javascript:void(0);" 
                      class="small text-decoration-none fw-semibold" 
                      style="color: var(--primary-green); transition: color 0.3s ease;" 
                      onmouseover="this.style.color='var(--primary-dark)'" 
                      onmouseout="this.style.color='var(--primary-green)'"
                      data-bs-toggle="modal" data-bs-target="#forgotPasswordModal">Forgot Password?</a>
                </div>

                <div class="d-grid">
                  <button type="submit" class="btn btn-primary btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i> LOGIN
                  </button>
                </div>
              </form>

              <p class="mt-4 text-muted text-center">
                <small>
                  <i class="fas fa-info-circle me-1"></i> New Manager? 
                  <a href="request_account.php" class="request-account-link">Request an account here.</a>
                </small>
              </p>
            </div>

          </div>
        </div>
      </div>
    </div>
  </div>

  <footer class="footer-main">
        <div class="container">
            <div class="row">
                <div class="col-lg-5 col-md-12 mb-4 mb-lg-0">
                    <div class="footer-logo-group">
                        <img src="imageslogo.png" alt="Logo">
                        <img src="images/COte.png" alt="Logo">
                        <h5> PIT SILAKAS MEDAL TALLY</h5>
                    </div>
                    <p>The official live medal tallying system for the Palompon Institute of Technology. Bringing you real-time results, event schedules, and team standings.</p>
                </div>
                <div class="col-lg-3 col-md-6 mb-4 mb-md-0">
                    <h6>Quick Links</h6>
                    <ul class="footer-links">
                        <li><a href="home.php">Home (Standings)</a></li>
                        <li><a href="Eventpage.php">Events Schedule</a></li>
                        <li><a href="college_team.php">Teams & Rosters</a></li>
                    </ul>
                </div>
                <div class="col-lg-4 col-md-6">
                    <h6>Contact Us</h6>
                    <div style="color: rgba(255,255,255,0.7); font-size: 0.9rem; line-height: 1.6;">
                        <p class="mb-1 fw-bold text-white">Palompon Institute of Technology</p>
                        <p class="mb-2">Evangelista Street, Brgy. Guiwan II,<br>Palompon, Leyte 6538</p>
                        <p class="mb-0">
                            <i class="fas fa-phone-alt me-2"></i>(053) 555-9841<br>
                            <i class="fas fa-envelope me-2"></i>op@pit.edu.ph
                        </p>
                    </div>
                </div>
            </div>
            <div class="footer-bottom">
                <small>&copy; <?php echo date("Y"); ?> PIT SILAKAS MEDAL TALLY. All rights reserved.</small><br>
                <small>Developed by Jayvee Baybyon</small>
            </div>
        </div>
    </footer>

    <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
      <div class="modal-header text-white" style="background: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%); border-radius: 20px 20px 0 0;">
        <h5 class="modal-title"><i class="fas fa-lock me-2"></i>Account Recovery</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4 text-center">
        <i class="fas fa-user-shield fa-3x text-secondary mb-3 opacity-50"></i>
        <p class="mb-3">For security purposes, automatic password resets are disabled for administrative accounts.</p>
        <div class="alert alert-light border text-start d-inline-block w-100">
            <p class="mb-1 fw-bold"><i class="fas fa-building me-2 text-success"></i>Visit the Sports Coordinator Office</p>
            <p class="mb-0 small text-muted ms-4">Cas Building, Physical Education Department</p>
            <hr class="my-2">
            <p class="mb-1 fw-bold"><i class="fas fa-envelope me-2 text-success"></i>Email Support</p>
            <p class="mb-0 small text-muted ms-4">op@pit.edu.ph</p>
        </div>
      </div>
      <div class="modal-footer border-0 justify-content-center pb-4">
        <button type="button" class="btn btn-secondary px-4 rounded-pill" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Create animated particles
      const particlesContainer = document.getElementById('particles');
      for (let i = 0; i < 40; i++) {
        const particle = document.createElement('div');
        particle.className = 'particle';
        particle.style.left = Math.random() * 100 + '%';
        particle.style.width = Math.random() * 6 + 2 + 'px';
        particle.style.height = particle.style.width;
        particle.style.animationDelay = Math.random() * 20 + 's';
        particle.style.animationDuration = (Math.random() * 10 + 15) + 's';
        particlesContainer.appendChild(particle);
      }

      // Navbar brand click
      const brandElement = document.querySelector('.interactive-brand');
      if (brandElement) {
        brandElement.addEventListener('click', function(e) {
          e.preventDefault();
          window.location.href = 'home.php';
        });
      }

      // Add input focus animation
      const inputs = document.querySelectorAll('.form-control');
      inputs.forEach(input => {
        input.addEventListener('focus', function() {
          this.parentElement.style.transform = 'scale(1.02)';
          this.parentElement.style.transition = 'transform 0.3s ease';
        });
        
        input.addEventListener('blur', function() {
          this.parentElement.style.transform = 'scale(1)';
        });
      });

      // Add typing effect for brand title
      const brandTitle = document.querySelector('.brand-title');
      if (brandTitle) {
        brandTitle.style.opacity = '0';
        setTimeout(() => {
          brandTitle.style.transition = 'opacity 1s ease-in';
          brandTitle.style.opacity = '1';
        }, 500);
      }

      // Add glow effect on logo hover
      const logos = document.querySelectorAll('.main-logo');
      logos.forEach(logo => {
        logo.addEventListener('mouseenter', function() {
          this.style.filter = 'drop-shadow(0 10px 40px rgba(255,215,0,0.8))';
        });
        logo.addEventListener('mouseleave', function() {
          this.style.filter = 'drop-shadow(0 10px 30px rgba(255,255,255,0.4))';
        });
      });

      // Add smooth hover effect to request account link
      const requestLink = document.querySelector('.request-account-link');
      if (requestLink) {
        requestLink.addEventListener('click', function(e) {
          // Optional: Add a loading animation when clicked
          this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Redirecting...';
        });
      }
    });
  </script>
</body>
</html>