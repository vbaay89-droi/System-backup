<?php
session_start();
require_once 'config.php'; 

// Load PHPMailer
require __DIR__ . '/PHPMailer-master/src/Exception.php';
require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Define current page for active nav-link
$current_page = basename($_SERVER['PHP_SELF']);

$message = '';
$message_type = 'danger'; 

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = $email; 
    // FIXED: Role is hardcoded to Tournament Manager
    $requested_role = 'Tournament Manager';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // --- Validation ---
    if (empty($full_name) || empty($email) || empty($password) || empty($confirm_password)) {
        $message = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format.";
    } elseif (strlen($password) < 8) {
        $message = "Password must be at least 8 characters long.";
    } elseif ($password !== $confirm_password) {
        $message = "Passwords do not match.";
    } else {
        try {
            // --- Check for existing user ---
            $stmt_check_users = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt_check_users->bind_param("s", $email);
            $stmt_check_users->execute();
            $result_check_users = $stmt_check_users->get_result();

            if ($result_check_users->num_rows > 0) {
                $message = "A user with that email already exists or is pending approval.";
            } else {
                
                // --- Create the Pending Account ---
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt_insert = $conn->prepare(
                    "INSERT INTO users (full_name, email, username, role, password, is_approved) 
                     VALUES (?, ?, ?, ?, ?, 0)"
                );
                $stmt_insert->bind_param("sssss", $full_name, $email, $username, $requested_role, $hashed_password);
                
                if (!$stmt_insert->execute()) {
                    throw new Exception("Database error: Could not save your request.");
                }
                $stmt_insert->close();
                
                // Send Confirmation Email
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'vbaay89@gmail.com';
                    $mail->Password   = 'vthz porq dnhj frdc'; 
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    $mail->setFrom('vbaay89@gmail.com', 'PIT SIGLAKAS MEDAL TALLY');
                    $mail->addAddress($email, $full_name); 

                    $mail->isHTML(true);
                    $mail->Subject = 'Account Request Received - PIT SIGLAKAS MEDAL TALLY';
                    $mail->Body    = "
                        <h2>Thank you for your request, " . htmlspecialchars($full_name) . "!</h2>
                        <p>We have received your request for an <strong>" . htmlspecialchars($requested_role) . "</strong> account.</p>
                        <p>An administrator will review your submission. You will receive another email once your account is approved.</p>
                        <p>Thank you,<br>The PIT SIGLAKAS MEDAL TALLY Team</p>
                    ";

                    $mail->send();
                    
                    // Redirect to login with success status
                    header("Location: login.php?status=success");
                    exit();

                } catch (Exception $e) {
                    error_log("Mailer Error: {$mail->ErrorInfo}");
                    // Still consider success if DB insert worked, just warn about email
                    header("Location: login.php?status=success"); 
                    exit();
                }
            }
            $stmt_check_users->close();

        } catch (Exception $e) {
            error_log("Request Account Error: {$e->getMessage()}");
            $message = "An internal error occurred. Please try again later.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Request Account - SmartScore PIT</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

  <style>
    /* --- EXACT STYLES FROM LOGIN.PHP --- */
    :root {
      --primary-green: #4CAF50;
      --primary-dark: #2E7D32;
      --accent-gold: #FFD700;
      --text-dark: #1A1A1A;
      --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      margin: 0; padding: 0; min-height: 100vh;
      font-family: 'Inter', sans-serif;
      display: flex; flex-direction: column;
      position: relative; overflow-x: hidden;
    }

    /* Background */
    body::before {
      content: ''; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
      background: url('images/featured_image.png'); background-size: cover; z-index: -2;
    }

    body::after {
      content: ''; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
      background: linear-gradient(135deg, rgba(0,0,0,0.7) 0%, rgba(0,0,0,0.5) 50%, rgba(0,0,0,0.7) 100%);
      z-index: -1;
    }

    /* Particles */
    .particles { position: fixed; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1; }
    .particle {
      position: absolute; background: rgba(255, 255, 255, 0.3);
      border-radius: 50%; animation: float 20s infinite;
    }
    @keyframes float {
      0%, 100% { transform: translateY(0) translateX(0) rotate(0deg); opacity: 0; }
      10% { opacity: 1; } 90% { opacity: 1; }
      100% { transform: translateY(-100vh) translateX(100px) rotate(360deg); opacity: 0; }
    }

    /* Navbar */
    .navbar {
        background: rgba(26, 26, 26, 0.98) !important;
        backdrop-filter: blur(20px);
        box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        padding: 1rem 0;
        position: relative;
        z-index: 1000;
    }
    .navbar::before {
        content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 3px;
        background: linear-gradient(90deg, transparent, var(--primary-green), var(--accent-gold), transparent);
        animation: borderGlow 3s ease-in-out infinite;
    }
    @keyframes borderGlow { 0%, 100% { opacity: 0.5; } 50% { opacity: 1; } }

    .navbar-brand:hover { transform: translateY(-2px) scale(1.02); }
    
    .brand-heading { 
        font-family: 'Poppins', sans-serif; font-weight: 700; letter-spacing: -0.5px;
        background: linear-gradient(90deg, #fff, #4CAF50);
        -webkit-background-clip: text; background-clip: text;
    }

    .nav-link { 
        font-weight: 500; font-size: 0.95rem; padding: 0.5rem 1.25rem !important; 
        margin: 0 0.25rem; border-radius: 12px; transition: var(--transition); position: relative; overflow: hidden;
    }
    .nav-link:hover { 
        background: rgba(76, 175, 80, 0.2); color: var(--primary-green) !important; transform: translateY(-2px);
    }
    .btn-success { padding: 0.6rem 1.5rem; border-radius: 12px; font-weight: 600; }

    /* Main Content */
    .main-content {
      flex: 1; display: flex; justify-content: center; 
      align-items: flex-start; /* Matches login.php alignment */
      padding: 3rem 0; position: relative; z-index: 10;
    }
    .login-wrapper { width: 100%; max-width: 1400px; margin: 0 auto; padding: 0 2rem; }

    /* Branding Section */
    .brand-section {
      display: flex; flex-direction: column; justify-content: center; align-items: flex-start;
      text-align: left; animation: fadeInLeft 0.8s ease-out; padding: 2rem;
    }
    @keyframes fadeInLeft { from { opacity: 0; transform: translateX(-50px); } to { opacity: 1; transform: translateX(0); } }

    .logo-container { display: flex; justify-content: flex-start; align-items: center; gap: 2rem; margin-bottom: 2rem; }

    .main-logo {
      width: 100px; height: 100px; object-fit: contain;
      filter: drop-shadow(0 5px 15px rgba(0,0,0,0.4));
      animation: logoFloat 3s ease-in-out infinite;
      transition: transform 0.3s ease;
      border-radius: 50%; background: rgba(255,255,255,0.05);
    }
    .main-logo:hover { transform: scale(1.1) rotate(5deg); filter: drop-shadow(0 8px 20px rgba(255,215,0,0.6)); }
    @keyframes logoFloat { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-15px); } }

    .brand-title {
      font-family: 'Poppins', sans-serif; font-weight: 900; 
      font-size: 3.5rem; line-height: 1.3; color: white;
      text-shadow: 0 4px 20px rgba(0,0,0,0.5), 0 0 40px rgba(255,255,255,0.3);
      margin: 0;
      background: linear-gradient(135deg, #ffffff 0%, #FFD700 50%, #4CAF50 100%);
      -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
      animation: titleGlow 3s ease-in-out infinite;
    }

    /* Form Section */
    .login-section { display: flex; justify-content: center; align-items: center; animation: fadeInRight 0.8s ease-out; }
    @keyframes fadeInRight { from { opacity: 0; transform: translateX(50px); } to { opacity: 1; transform: translateX(0); } }

    .login-container {
      background: rgba(255, 255, 255, 0.98);
      backdrop-filter: blur(30px);
      border-radius: 24px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.5), 
                  0 0 100px rgba(76, 175, 80, 0.2),
                  inset 0 0 100px rgba(255,255,255,0.1);
      padding: 2.5rem 2.5rem; /* Slightly tighter padding for taller form */
      width: 100%; max-width: 480px;
      position: relative; overflow: hidden;
      border: 2px solid rgba(255,255,255,0.3);
      margin-bottom: 2rem;
    }
    .login-container::after {
      content: ''; position: absolute; top: 0; left: 0; right: 0; height: 5px;
      background: linear-gradient(90deg, var(--primary-green), var(--accent-gold), var(--primary-green));
      background-size: 200% 100%; animation: gradientMove 3s ease infinite;
    }
    @keyframes gradientMove { 0%, 100% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } }

    .login-container h2 {
      font-weight: 800; color: #1A1A1A; font-family: 'Poppins', sans-serif; margin-bottom: 1.5rem;
      position: relative; display: inline-block; font-size: 1.8rem;
    }
    
    .form-label { text-align: left; display: block; margin-bottom: 8px; font-weight: 600; color: #333; font-size: 0.9rem; }
    
    .form-control {
      width: 100%; padding: 12px 12px 12px 3rem; font-size: 0.95rem; border-radius: 12px;
      border: 2px solid #e0e0e0; margin-bottom: 1rem; transition: var(--transition); background: #f8f9fa;
    }
    .form-control:focus {
      border-color: var(--primary-green); box-shadow: 0 0 0 4px rgba(76, 175, 80, 0.1); background: white; outline: none;
    }
    .position-relative i { color: #999; font-size: 1rem; z-index: 10; }

    .btn-login {
      width: 100%; padding: 14px 0; font-size: 1.1rem; font-weight: 700; border-radius: 12px;
      transition: var(--transition); background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      border: none; color: white; text-transform: uppercase; letter-spacing: 1px;
      box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
    }
    .btn-login:hover { transform: translateY(-3px); box-shadow: 0 12px 30px rgba(102, 126, 234, 0.5); }

    .password-message { font-size: 0.85rem; margin-top: -5px; margin-bottom: 15px; display: none; text-align: left; }

    /* Footer */
    footer { flex-shrink: 0; width: 100%; background: rgba(26, 26, 26, 0.98); backdrop-filter: blur(20px); position: relative; z-index: 100; }
    footer::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, transparent, var(--primary-green), var(--accent-gold), transparent); }

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
    
    /* Responsive */
    @media (max-width: 991.98px) {
      .main-content { align-items: center; }
      .brand-section { margin-bottom: 2rem; align-items: center; text-align: center; }
      .logo-container { gap: 1.5rem; justify-content: center; }
      .brand-title { font-size: 2rem; }
      .login-section { padding-top: 0; margin-top: 0; }
    }
  </style>
</head>

<body>
  <div class="particles" id="particles"></div>

  <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid d-flex align-items-center justify-content-between">
        <a class="navbar-brand d-flex align-items-center interactive-brand" href="home.php" style="cursor: pointer;">
            <img src="images/PIT.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain;">
            <div class="d-flex flex-column lh-sm">
                <strong class="text-white brand-heading" style="font-size: 1.25rem;">PIT SIGLAKAS MEDAL TALLY</strong>
                <small class="text-light brand-subheading" style="font-size: 0.75rem;">Official College Medal Tally System</small>
            </div>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item"><a class="nav-link" href="home.php">Home</a></li>
                <li class="nav-item"><a class="nav-link" href="Eventpage.php">Events</a></li>
                <li class="nav-item"><a class="nav-link" href="college_team.php">Colleges</a></li>
                <li class="nav-item"><a href="login.php" class="btn btn-success ms-3">Login</a></li>
            </ul>
        </div>
    </div>
  </nav>

  <div class="main-content">
    <div class="login-wrapper">
      <!-- Align Items Start to match login.php structure -->
      <div class="row align-items-start">

        <div class="col-lg-7 brand-section">
          <div class="logo-container">
            <img src="images/PIT.png" alt="PIT Logo" class="main-logo">
            <img src="images/COte.png" alt="Siglakas Logo" class="main-logo">
          </div>
          <h1 class="brand-title">
            SmartScore: A Web-Based Scoring and Medal Tally Platform for Siglakas Events
          </h1>
        </div>

        <!-- Added Top Padding to align visually with logo on desktop -->
        <div class="col-lg-5 login-section pt-lg-5 mt-lg-4">
          <div class="login-container">
            <div class="text-center">
              <h2>Request Account</h2>
              <p class="text-muted mb-4" style="font-size: 0.9rem;">Join as an Tournament Manager</p>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type) ?> alert-dismissible fade show" role="alert">
                    <i class="fas <?= $message_type == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> me-2"></i>
                    <small><?= htmlspecialchars($message) ?></small>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form action="request_account.php" method="POST" id="requestForm">
                
                <div class="mb-3 text-start">
                    <label for="full_name" class="form-label">Full Name</label>
                    <div class="position-relative">
                        <input type="text" class="form-control" id="full_name" name="full_name" placeholder="Enter your full name" required value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                        <i class="fas fa-user-tag position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                    </div>
                </div>

                <div class="mb-3 text-start">
                    <label for="email" class="form-label">Email</label>
                    <div class="position-relative">
                        <input type="email" class="form-control" id="email" name="email" placeholder="Enter your email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        <i class="fas fa-envelope position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                    </div>
                </div>

                <!-- ROLE INPUT REMOVED - AUTOMATICALLY HANDLED IN PHP -->

                <div class="mb-3 text-start">
                    <label for="password" class="form-label">Password</label>
                    <div class="position-relative">
                        <input type="password" class="form-control" id="password" name="password" placeholder="Create a password (min. 8 chars)" required>
                        <i class="fas fa-lock position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                    </div>
                </div>

                <div class="mb-3 text-start">
                    <label for="confirm_password" class="form-label">Confirm Password</label>
                    <div class="position-relative">
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                        <i class="fas fa-lock position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                    </div>
                </div>
                
                <div id="passwordMessage" class="password-message text-danger"></div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-login" id="submitButton">
                        <i class="fas fa-paper-plane me-2"></i> Submit Request
                    </button>
                </div>
            </form>

            <p class="mt-4 text-muted text-center">
                <small>
                    <a href="login.php" style="text-decoration: none; color: var(--primary-green); font-weight: 600;">
                        <i class="fas fa-arrow-left me-1"></i> Back to Login
                    </a>
                </small>
            </p>

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
                        <img src="images/PIT.png" alt="Logo">
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

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Particles
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

      // Password Validation
      const passwordInput = document.getElementById('password');
      const confirmPasswordInput = document.getElementById('confirm_password');
      const messageElement = document.getElementById('passwordMessage');
      const submitButton = document.getElementById('submitButton');
      const requestForm = document.getElementById('requestForm');

      function validatePasswords() {
        const password = passwordInput.value;
        const confirmPassword = confirmPasswordInput.value;
        
        if (password.length > 0 && password.length < 8) {
            messageElement.textContent = "Password must be at least 8 characters long.";
            messageElement.style.display = 'block';
            submitButton.disabled = true;
        } else if (password.length > 0 && confirmPassword.length > 0) {
            if (password !== confirmPassword) {
                messageElement.textContent = "Passwords do not match.";
                messageElement.style.display = 'block';
                submitButton.disabled = true;
            } else {
                messageElement.textContent = "";
                messageElement.style.display = 'none';
                submitButton.disabled = false;
            }
        } else {
            messageElement.textContent = "";
            messageElement.style.display = 'none';
            submitButton.disabled = false; 
        }
      }

      if (passwordInput && confirmPasswordInput) {
          passwordInput.addEventListener('keyup', validatePasswords);
          confirmPasswordInput.addEventListener('keyup', validatePasswords);
      }

      if (requestForm) {
          requestForm.addEventListener('submit', function(event) {
              validatePasswords();
              if (submitButton.disabled) {
                  event.preventDefault();
                  messageElement.textContent = "Please fix the errors before submitting.";
                  messageElement.style.display = 'block';
              }
          });
      }
      
      // Input Focus Animation
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