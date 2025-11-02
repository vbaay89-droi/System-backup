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

// --- NEW: Handle status messages from email verification ---
$status_message = '';
$status_message_type = 'info'; // 'success' or 'danger'

if (isset($_GET['status'])) {
    switch ($_GET['status']) {
        case 'email_success':
            $status_message = "Success! Your email address has been updated. You can now log in.";
            $status_message_type = 'success';
            break;
        case 'token_expired':
            $status_message = "Your verification link has expired. Please try changing your email again.";
            $status_message_type = 'danger';
            break;
        case 'token_mismatch':
        case 'invalid_link':
        case 'no_request_found':
            $status_message = "Invalid verification link. Please try again.";
            $status_message_type = 'danger';
            break;
        case 'email_taken':
            $status_message = "That email address is already in use. Please try a different one.";
            $status_message_type = 'danger';
            break;
        case 'db_error':
            $status_message = "A database error occurred. Please try again.";
            $status_message_type = 'danger';
            break;
    }
}
// --------------------------------------------------------


// Login logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ... (rest of your existing login logic)
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $expected_role = trim($_POST['expected_role'] ?? '');

    if ($username === '' || $password === '' || $expected_role === '') {
        $error_message = "Please enter all fields and select a role.";
    } else {
        try {
            // Prepare statement without joining roles table
            $stmt = $conn->prepare('
                SELECT id, username, email, password, role 
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
                if ($user['role'] !== $expected_role) {
                    $error_message = "Access denied. You are not registered as an " . htmlspecialchars($expected_role) . ".";
                } else {
                    // OTP generation
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
                        $mail->Subject = 'Your OTP Code';
                        $mail->Body    = "Your OTP is <b>$otp</b>. It will expire in 5 minutes.";

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
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

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
      background: linear-gradient(135deg, #667eea 0%, #764ba2 25%, #f093fb 50%, #4facfe 75%, #00f2fe 100%);
      background-size: 400% 400%;
      animation: gradientShift 15s ease infinite;
      margin: 0;
      padding: 0;
      min-height: 100vh;
      font-family: 'Inter', sans-serif;
      display: flex;
      flex-direction: column;
      position: relative;
      overflow-x: hidden;
    }

    @keyframes gradientShift {
      0% { background-position: 0% 50%; }
      50% { background-position: 100% 50%; }
      100% { background-position: 0% 50%; }
    }

    /* Animated background particles */
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
      background: rgba(255, 255, 255, 0.5);
      border-radius: 50%;
      animation: float 20s infinite;
    }

    @keyframes float {
      0%, 100% { transform: translateY(0) translateX(0) rotate(0deg); opacity: 0; }
      10% { opacity: 1; }
      90% { opacity: 1; }
      100% { transform: translateY(-100vh) translateX(100px) rotate(360deg); opacity: 0; }
    }

    /* Navbar styles */
    .navbar {
        background: rgba(26, 26, 26, 0.95) !important;
        backdrop-filter: blur(20px);
        box-shadow: 0 8px 32px rgba(0,0,0,0.2);
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
        height: 2px;
        background: linear-gradient(90deg, transparent, var(--primary-green), var(--accent-gold), transparent);
        animation: borderGlow 3s ease-in-out infinite;
    }

    @keyframes borderGlow {
      0%, 100% { opacity: 0.5; }
      50% { opacity: 1; }
    }

    .navbar-brand { transition: var(--transition); }
    .navbar-brand:hover { transform: translateY(-2px) scale(1.02); }
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

    .nav-link::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(76, 175, 80, 0.3), transparent);
        transition: left 0.5s;
    }

    .nav-link:hover::before {
        left: 100%;
    }

    .nav-link:hover { 
        background: rgba(76, 175, 80, 0.2); 
        color: var(--accent-gold) !important;
        transform: translateY(-2px);
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

    .btn-danger::before, .btn-success::before {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 0;
        height: 0;
        border-radius: 50%;
        background: rgba(255,255,255,0.3);
        transform: translate(-50%, -50%);
        transition: width 0.6s, height 0.6s;
    }

    .btn-danger:hover::before, .btn-success:hover::before {
        width: 300px;
        height: 300px;
    }

    .btn-danger:hover, .btn-success:hover { 
        transform: translateY(-3px); 
        box-shadow: 0 8px 25px rgba(0,0,0,0.3); 
    }

    /* Main content */
    .main-content {
      flex: 1 0 auto;
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 2rem 0 50px;
      position: relative;
      z-index: 10;
    }

    #landing-info {
      color: white;
      padding-right: 3rem;
      animation: slideInLeft 0.8s ease-out;
    }

    @keyframes slideInLeft {
      from { opacity: 0; transform: translateX(-50px); }
      to { opacity: 1; transform: translateX(0); }
    }

    #landing-info h1 {
      font-family: 'Poppins', sans-serif;
      font-weight: 800;
      font-size: 2.8rem;
      line-height: 1.2;
      text-shadow: 0 4px 20px rgba(0,0,0,0.3);
      background: linear-gradient(135deg, #fff, #FFD700);
      -webkit-background-clip: text;
      background-clip: text;
      -webkit-text-fill-color: transparent;
      margin-bottom: 1.5rem;
    }

    .landing-sub-description {
      font-family: 'Inter', sans-serif;
      font-size: 1.15rem;
      line-height: 1.8;
      color: rgba(255,255,255,0.95);
      text-shadow: 0 2px 10px rgba(0,0,0,0.2);
      margin-top: 1.5rem;
      backdrop-filter: blur(5px);
      background: rgba(255,255,255,0.1);
      padding: 1.5rem;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,0.2);
    }

    /* Login container */
    .login-container {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(20px);
      border-radius: 24px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.3), 0 0 100px rgba(255,255,255,0.1);
      padding: 40px;
      width: 100%;
      max-width: 480px;
      margin: 0 auto;
      position: relative;
      overflow: hidden;
      animation: slideInRight 0.8s ease-out;
      border: 2px solid rgba(255,255,255,0.3);
    }

    @keyframes slideInRight {
      from { opacity: 0; transform: translateX(50px); }
      to { opacity: 1; transform: translateX(0); }
    }

    .login-container::before {
      content: '';
      position: absolute;
      top: -50%;
      left: -50%;
      width: 200%;
      height: 200%;
      background: linear-gradient(45deg, transparent, rgba(76, 175, 80, 0.1), transparent);
      animation: shine 3s infinite;
    }

    @keyframes shine {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    .login-view {
      transition: opacity 0.3s ease-in-out;
      width: 100%;
      position: relative;
      z-index: 1;
    }

    .login-container .logo {
      width: 90px;
      height: 90px;
      object-fit: contain;
      margin-bottom: 15px;
      filter: drop-shadow(0 4px 12px rgba(0,0,0,0.2));
      animation: logoFloat 3s ease-in-out infinite;
    }

    @keyframes logoFloat {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-10px); }
    }

    .login-container h2 {
      font-weight: 800;
      color: #1A1A1A;
      font-family: 'Poppins', sans-serif;
      margin-bottom: 2rem;
      position: relative;
      display: inline-block;
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

    /* Portal buttons */
    .btn-portal {
      font-size: 1.2rem;
      font-weight: 700;
      padding: 1.2rem;
      border-radius: 16px;
      width: 100%;
      margin-bottom: 1.2rem;
      transition: var(--transition);
      box-shadow: 0 8px 20px rgba(0,0,0,0.15);
      border: none;
      position: relative;
      overflow: hidden;
      transform-style: preserve-3d;
    }

    .btn-portal::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
      transition: left 0.7s;
    }

    .btn-portal:hover::before {
      left: 100%;
    }

    .btn-portal:hover { 
      transform: translateY(-5px) scale(1.02);
      box-shadow: 0 15px 35px rgba(0,0,0,0.25);
    }

    .btn-portal i {
      margin-right: 12px;
      font-size: 1.3rem;
      animation: iconBounce 2s ease-in-out infinite;
    }

    @keyframes iconBounce {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.1); }
    }

    .btn-admin { 
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
    }

    .btn-event-manager { 
      background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
      color: white;
    }

    .btn-sports-director { 
      background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
      color: white;
    }
    
    #login-form-view {
      display: none;
      opacity: 0;
      position: relative;
    }
    
    .back-link {
        font-size: 0.95rem;
        color: var(--primary-dark);
        text-decoration: none;
        font-weight: 600;
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .back-link:hover {
        color: #667eea;
        transform: translateX(-5px);
    }

    .back-link i {
      transition: transform 0.3s;
    }

    .back-link:hover i {
      transform: translateX(-3px);
    }
    
    /* Form styles */
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

    .btn-login::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
      transition: left 0.7s;
    }

    .btn-login:hover::before {
      left: 100%;
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

    /* Media queries */
    @media (max-width: 991.98px) {
      #landing-info {
        text-align: center;
        padding-right: 0;
        margin-bottom: 2rem;
      }
      #landing-info h1 {
        font-size: 2.2rem;
      }
      .login-container {
        margin: 0 auto;
      }
    }

    footer { 
      flex-shrink: 0; 
      width: 100%; 
      background: rgba(26, 26, 26, 0.95);
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
      height: 2px;
      background: linear-gradient(90deg, transparent, var(--primary-green), var(--accent-gold), transparent);
    }
  </style>
</head>

<body>
  <!-- Animated particles -->
  <div class="particles" id="particles"></div>

  <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
    <div class="container-fluid d-flex align-items-center justify-content-between">
        <a class="navbar-brand d-flex align-items-center interactive-brand" href="Tournament_Manager_page.php" style="cursor: pointer;">
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
                    <a class="nav-link <?= ($current_page == 'Tournament_Manager_page.php') ? 'active' : '' ?>" href="Tournament_Manager_page.php">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == 'Event.php') ? 'active' : '' ?>" href="Event.php">Events</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == 'Teams.php') ? 'active' : '' ?>" href="Teams.php">Teams</a>
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
    <div class="container">
      <div class="row align-items-center">

        <div class="col-lg-7" id="landing-info">
          <h1 class="mb-3">SmartScore: A Web-Based Scoring and Medal Tally Platform for Siglakas Events</h1>
          <p class="landing-sub-description">
            SmartScore is a web-based scoring and medal tally platform designed to streamline the management of Siglakas events. It provides real-time score recording, automatic medal computation, and live ranking updates across all participating teams and sports categories. Built with a user-friendly interface and robust backend logic, SmartScore allows organizers, officials, and participants to efficiently track results through any connected device. The platform promotes transparency, reduces manual errors, and enhances the overall competition experience by delivering accurate and instant score reports.
          </p>
        </div>

        <div class="col-lg-5">
          
          <div class="login-container">

            <div class="text-center">
              <img src="imageslogo.png" alt="PIT Logo" class="logo">
              <img src="images/SIGLAKASTEST.png" alt="Siglakas Logo" class="logo">
              <h2 id="login-view-title">Select Your Role</h2>
            </div>

            <!--
            /***************************************************
             * NEW: ADDED STATUS MESSAGE BLOCK HERE
             ***************************************************/
            -->
            <?php if (!empty($status_message)): ?>
                <div class="alert alert-<?= htmlspecialchars($status_message_type) ?> alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <?= htmlspecialchars($status_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <!--
            /***************************************************
             * END NEW BLOCK
             ***************************************************/
            -->


            <div id="login-view-content">

              <div id="role-select-view" class="login-view" style="opacity: 1; display: block;">
                <div class="d-grid gap-3">
                  <button class="btn btn-portal btn-admin" data-role="Administrator">
                    <i class="fas fa-user-shield"></i> Administrator
                  </button>
                  <button class="btn btn-portal btn-event-manager" data-role="Event Manager">
                    <i class="fas fa-calendar-alt"></i> Event Manager
                  </button>
                  <button class="btn btn-portal btn-sports-director" data-role="Sports Director">
                    <i class="fas fa-trophy"></i> Sports Director
                  </button>
                </div>
              </div>

              <div id="login-form-view" class="login-view">
                
                <a href="#" id="back-to-roles" class="back-link mb-4 d-inline-block">
                  <i class="fas fa-arrow-left"></i> Back to role selection
                </a>

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

                  <input type="hidden" id="expected_role" name="expected_role" value="">

                  <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-login">
                      <i class="fas fa-sign-in-alt me-2"></i> LOGIN
                    </button>
                  </div>
                </form>

                <p class="mt-4 text-muted text-center">
                  <small><i class="fas fa-info-circle me-1"></i>New user? Please see system administrator for your username and password.</small>
                </p>
              </div>

            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <footer class="bg-dark text-white py-4">
    <div class="text-center">
      <small>&copy; <?= date("Y") ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
      <small>Developed by Tsunayoshi Sawada</small>
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Create animated particles
      const particlesContainer = document.getElementById('particles');
      for (let i = 0; i < 30; i++) {
        const particle = document.createElement('div');
        particle.className = 'particle';
        particle.style.left = Math.random() * 100 + '%';
        particle.style.width = Math.random() * 5 + 2 + 'px';
        particle.style.height = particle.style.width;
        particle.style.animationDelay = Math.random() * 20 + 's';
        particle.style.animationDuration = (Math.random() * 10 + 15) + 's';
        particlesContainer.appendChild(particle);
      }

      // Navbar padding adjustment
      const navbarHeight = document.querySelector('.navbar').offsetHeight;
      document.querySelector('.main-content').style.paddingTop = `${navbarHeight + 30}px`;

      // Navbar brand click
      document.querySelector('.interactive-brand').addEventListener('click', function(e) {
        e.preventDefault();
        window.location.href = 'Tournament_Manager_page.php';
      });

      // View switching logic
      const roleSelectView = document.getElementById('role-select-view');
      const loginFormView = document.getElementById('login-form-view');
      const roleButtons = document.querySelectorAll('.btn-portal');
      const backButton = document.getElementById('back-to-roles');
      const loginViewTitle = document.getElementById('login-view-title');
      const expectedRoleInput = document.getElementById('expected_role');
      const transitionTime = 300; 

      function showLoginForm(role) {
        loginViewTitle.textContent = role + ' Login';
        expectedRoleInput.value = role;

        roleSelectView.style.opacity = '0';
        setTimeout(() => {
          roleSelectView.style.display = 'none';
          loginFormView.style.display = 'block';
          setTimeout(() => {
            loginFormView.style.opacity = '1';
          }, 50); 
        }, transitionTime); 
      }

      function showRoleSelect() {
        loginViewTitle.textContent = 'Select Your Role';
        loginFormView.style.opacity = '0';
        setTimeout(() => {
          loginFormView.style.display = 'none';
          roleSelectView.style.display = 'block';
          setTimeout(() => {
            roleSelectView.style.opacity = '1';
          }, 50);
        }, transitionTime);
      }

      roleButtons.forEach(button => {
        button.addEventListener('click', function() {
          const role = this.getAttribute('data-role');
          showLoginForm(role);
        });
      });

      backButton.addEventListener('click', function(e) {
        e.preventDefault();
        showRoleSelect();
      });

      // Handle PHP error - show login form with error
      <?php if (isset($error_message) || !empty($status_message)): ?>
        // ^-- MODIFIED this condition to also trigger on status messages
        const failedRole = '<?= htmlspecialchars($_POST['expected_role'] ?? 'User') ?>';
        
        // Only set this if there's no status message, otherwise it's confusing
        <?php if (isset($error_message)): ?>
        loginViewTitle.textContent = failedRole + ' Login';
        expectedRoleInput.value = failedRole;
        <?php endif; ?>

        roleSelectView.style.display = 'none';
        roleSelectView.style.opacity = '0';
        loginFormView.style.display = 'block';
        loginFormView.style.opacity = '1';
      <?php endif; ?>

      // Add smooth scroll effect
      document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
          e.preventDefault();
          const target = document.querySelector(this.getAttribute('href'));
          if (target) {
            target.scrollIntoView({ behavior: 'smooth' });
          }
        });
      });

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
    });
  </script>
</body>
</html>
