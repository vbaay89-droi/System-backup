<?php
session_start();
require_once 'config.php';


// Load PHPMailer
require __DIR__ . '/PHPMailer-master/src/Exception.php';
require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error_message = "Please enter both username and password.";
    } else {
        try {
            // Connect to your database
            $pdo = new PDO('mysql:host=localhost;dbname=users_db;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

            // Fetch user by username
            // New Query (uses JOIN to get role_name from the roles table)
            $stmt = $pdo->prepare('
                SELECT 
                    u.id, 
                    u.username, 
                    u.email, 
                    u.password, 
                    r.role_name 
                FROM users u
                JOIN roles r ON u.role_id = r.role_id 
                WHERE u.username = ? 
                LIMIT 1
            ');
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                // Generate OTP
                $otp = random_int(100000, 999999);
                $_SESSION['otp'] = $otp;
                $_SESSION['otp_expiry'] = time() + 300; // 5 minutes
                $_SESSION['pending_user_id'] = $user['id'];
                $_SESSION['pending_username'] = $user['username'];

                $_SESSION['pending_user_role'] = $user['role_name']; 

                // Send OTP via email
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'vbaay89@gmail.com';
                    $mail->Password   = 'vthz porq dnhj frdc'; // app password
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    $mail->setFrom('vbaay89@gmail.com', 'PIT Sports Tallying');
                    $mail->addAddress($user['email']); // send to user’s email

                    $mail->isHTML(true);
                    $mail->Subject = 'Your OTP Code';
                    $mail->Body    = "Your OTP is <b>$otp</b>. It will expire in 5 minutes.";

                    $mail->send();

                    header('Location: verify_otp.php');
                    exit();
                } catch (Exception $e) {
                    $error_message = "Mailer Error: {$mail->ErrorInfo}";
                }
            } else {
                $error_message = "Invalid username or password.";
            }

        } catch (PDOException $e) {
            $error_message = "Database connection failed.";
        }
    }
}

?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login - PIT SPORTS TALLYING</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    /* Added from Tournament_Manager_page.php */
      :root {
      --primary-green: #4CAF50;
      --primary-dark: #2E7D32;
      --accent-gold: #FFD700;
      --accent-silver: #C0C0C0;
      --accent-bronze: #CD7F32;
      --bg-light: #F8F9FA;
      --bg-white: #FFFFFF;
      --text-dark: #1A1A1A;
      --text-muted: #6C757D;
      --shadow-sm: 0 2px 8px rgba(0,0,0,0.06);
      --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
      --shadow-lg: 0 8px 32px rgba(0,0,0,0.12);
      --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  }

  body {
      background: linear-gradient(to bottom,rgba(245, 16, 16, 0.32));
      margin: 0;
      padding: 0;
      min-height: 100vh;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; /* Updated font */
      display: flex;
      flex-direction: column;
  }

      /* Navbar Enhancement - Copied from Tournament_Manager_page.php */
  .navbar {
      background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important;
      box-shadow: 0 4px 20px rgba(0,0,0,0.15);
      padding: 1rem 0;
      backdrop-filter: blur(10px);
  }

  .navbar-brand {
      transition: var(--transition);
  }

  .navbar-brand:hover {
      transform: translateY(-2px);
  }

  .brand-logo {
      filter: drop-shadow(0 2px 4px rgba(255,255,255,0.1));
  }

  .brand-heading {
      font-family: 'Poppins', sans-serif;
      font-weight: 700;
      letter-spacing: -0.5px;
  }

  .nav-link {
      font-weight: 500;
      font-size: 0.95rem;
      padding: 0.5rem 1.25rem !important;
      margin: 0 0.25rem;
      border-radius: 8px;
      transition: var(--transition);
      position: relative;
  }

  .nav-link::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 50%;
      width: 0;
      height: 2px;
      background: var(--primary-green);
      transition: var(--transition);
      transform: translateX(-50%);
  }

  .nav-link:hover::after,
  .nav-link.active::after {
      width: 80%;
  }

  .nav-link:hover {
      background: rgba(255,255,255,0.1);
      color: var(--primary-green) !important;
  }
  
  /* Note: The interactive-brand CSS from the old Event.php is removed */

  .btn-danger, .btn-success {
      padding: 0.6rem 1.5rem;
      border-radius: 10px;
      font-weight: 600;
      transition: var(--transition);
      border: none;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  }

  .btn-danger:hover, .btn-success:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(0,0,0,0.2);
  }
  /* End Navbar Enhancement */

  .login-card {
    background-color: rgba(255, 255, 255, 0.95); /* Slight transparency for background blend */
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    padding: 40px;
    max-width: 450px;
    width: 100%;
    text-align: center;
    margin-top: 10px;
    margin-bottom: 50px;
  }

  .login-card .logo {
    width: 100px;
    height: 100px;
    object-fit: contain;
    margin-bottom: 25px;
  }

  .login-card .position-relative {
    margin-left: 35px;
  }

  .login-card .form-label {
    margin-left: 35px;
  }

  .login-card h2 {
    font-weight: bold;
    margin-bottom: 5px;
    color: #333;
  }

  .login-card p.login-subtitle {
    font-size: 1.5rem;
    font-weight: bold;
    color: #555;
    margin-bottom: 25px;
    position: relative;
    display: inline-block;
  }

  .login-card p.login-subtitle::after {
    content: '';
    position: absolute;
    bottom: -10px;
    left: 50%;
    transform: translateX(-50%);
    width: 60px;
    height: 3px;
    background-color: #FFD700;
    border-radius: 5px;
  }

  .login-card .form-control {
    max-width: 300px;
    width: 100%;
    padding-left: 2.5rem;
    font-size: 1rem;
    border-radius: 0.375rem;
    margin-bottom: 1rem;
  }

  .login-card .btn-login {
    width: 100%;
    max-width: 300px;
    padding: 0.5rem 1rem;
    font-size: 1rem;
    border-radius: 0.375rem;
    display: block;
    margin: 0 auto;
  }

  .form-label {
    text-align: left;
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: #555;
    font-size: 0.875rem;
  }

  .input-group-text {
    background-color: #f8f9fa;
    border-right: none;
    color: #6c757d;
  }

  .form-control:focus {
    box-shadow: none;
    border-color: #86b7fe;
  }

  .btn-login {
    background-color: #007bff;
    border-color: #007bff;
    padding: 12px 0;
    font-size: 1.1em;
    font-weight: bold;
    border-radius: 8px;
    transition: background-color 0.2s ease, border-color 0.2s ease;
  }

  .btn-login:hover {
    background-color: #0056b3;
    border-color: #0056b3;
  }

  .alert-danger {
    margin-top: 20px;
    font-size: 0.9em;
    padding: 10px;
  }

  .main-content {
    flex: 1 0 auto;
    display: flex;
    justify-content: center;
    align-items: center;
    padding-bottom: 50px;
  }

  footer {
    flex-shrink: 0;
    width: 100%;
  }

  .interactive-brand:hover .brand-heading,
  .interactive-brand:hover .brand-subheading {
    color: rgba(0, 102, 255, 0.43) !important;
  }
</style>

</head>
<body>
  <!-- Navbar -->
 <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
    <div class="container-fluid d-flex align-items-center justify-content-between">
        <a class="navbar-brand d-flex align-items-center interactive-brand" href="Tournament_Manager_page.php" style="cursor: pointer;">
            <img src="imageslogo.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain; transition: filter 0.2s;">
            <div class="d-flex flex-column lh-sm">
                <strong class="text-white brand-heading" style="font-size: 1.25rem; transition: color 0.2s;">PIT SPORTS TALLYING</strong>
                <small class="text-light brand-subheading" style="font-size: 0.75rem; transition: color 0.2s;">Official College Tournament System</small>
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

  <!-- Login Form -->
  <div class="main-content d-flex justify-content-center align-items-center">
    <div class="login-card">
      <div class="text-center">
        <img src="images/PIT.png" alt="PIT Logo" class="logo mb-2">
        <p class="login-subtitle d-block">Admin Login</p>
      </div>

      <?php if (isset($error_message)): ?>
        <div class="alert alert-danger" role="alert"><?= $error_message ?></div>
      <?php endif; ?>

      <form action="login.php" method="POST">
        <div class="mb-4 text-start">
          <label for="username" class="form-label">Username</label>
          <div class="position-relative">
            <input type="text" class="form-control ps-5" id="username" name="username" placeholder="Enter username" required>
            <i class="fas fa-user position-absolute top-50 start-0 translate-middle-y ms-2 text-muted"></i>
          </div>
        </div>
        <div class="mb-4 text-start">
          <label for="password" class="form-label">Password</label>
          <div class="position-relative">
            <input type="password" class="form-control ps-5" id="password" name="password" placeholder="Enter password" required>
            
            <i class="fas fa-lock position-absolute top-50 start-0 translate-middle-y ms-2 text-muted"></i>
          </div>
        </div>
        <div class="d-grid">
          <button type="submit" class="btn btn-primary btn-login">
            <i class="fas fa-sign-in-alt me-2"></i> LOGIN
          </button>
        </div>
        
      </form>
    </div>
  </div>

  <!-- Footer -->
  <footer class="bg-dark text-white py-3">
    <div class="text-center">
      <small>&copy; <?= date("Y") ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
      <small>Developed by Tsunayoshi Sawada</small>
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Adjust main content padding dynamically
    document.addEventListener('DOMContentLoaded', function() {
      const navbarHeight = document.querySelector('.navbar').offsetHeight;
      document.querySelector('.main-content').style.paddingTop = `${navbarHeight + 30}px`;
    });

    // Interactive brand redirect
    document.querySelector('.interactive-brand').addEventListener('click', function(e) {
      e.preventDefault();
      window.location.href = 'Tournament_Manager_page.php';
    });
  </script>
  <script>
  document.addEventListener("DOMContentLoaded", function() {
    const pwd = document.getElementById("password");
    pwd.setAttribute("autocomplete", "new-password");
  });
</script>
</body>
</html>
