<?php
session_start();
require_once 'config.php'; // Your DB connection

// Load PHPMailer
require __DIR__ . '/PHPMailer-master/src/Exception.php';
require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Define current page for active nav-link
$current_page = basename($_SERVER['PHP_SELF']);

$message = '';
$message_type = 'danger'; // Default to danger

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = $email; // Set username to be the email
    $requested_role = trim($_POST['requested_role'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // --- Validation ---
    if (empty($full_name) || empty($email) || empty($requested_role) || empty($password) || empty($confirm_password)) {
        $message = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format.";
    } elseif (!in_array($requested_role, ['Event Manager', 'Sports Director'])) {
        $message = "Invalid role selected.";
    } elseif (strlen($password) < 8) {
        $message = "Password must be at least 8 characters long.";
    } elseif ($password !== $confirm_password) {
        $message = "Passwords do not match.";
    } else {
        
        try {
            // --- Check for existing user ---
            // We only need to check the main 'users' table now.
            $stmt_check_users = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt_check_users->bind_param("s", $email);
            $stmt_check_users->execute();
            $result_check_users = $stmt_check_users->get_result();

            if ($result_check_users->num_rows > 0) {
                $message = "A user with that email already exists or is pending approval.";
            } else {
                
                // --- Create the Pending Account ---
                
                // 1. HASH THE PASSWORD (CRITICAL FOR SECURITY)
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // 2. Insert into 'users' table as PENDING (is_approved = 0)
                // (Assuming your column for role is named 'role')
                $stmt_insert = $conn->prepare(
                    "INSERT INTO users (full_name, email, username, role, password, is_approved) 
                     VALUES (?, ?, ?, ?, ?, 0)"
                );
                $stmt_insert->bind_param("sssss", $full_name, $email, $username, $requested_role, $hashed_password);
                
                if (!$stmt_insert->execute()) {
                    throw new Exception("Database error: Could not save your request.");
                }
                $stmt_insert->close();
                
                // 3. Send the "Request Received" confirmation email
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'vbaay89@gmail.com';
                    $mail->Password   = 'vthz porq dnhj frdc'; // Your APP PASSWORD
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    $mail->setFrom('vbaay89@gmail.com', 'PIT Sports Tallying');
                    $mail->addAddress($email, $full_name); 

                    $mail->isHTML(true);
                    $mail->Subject = 'Account Request Received - PIT Sports Tallying';
                    $mail->Body    = "
                        <h2>Thank you for your request, " . htmlspecialchars($full_name) . "!</h2>
                        <p>We have received your request for an <strong>" . htmlspecialchars($requested_role) . "</strong> account.</p>
                        <p>An administrator will review your submission. You will receive another email once your account is approved.</p>
                        <p>Thank you,<br>The PIT Sports Tallying Team</p>
                    ";

                    $mail->send();
                    
                    $message = "Success! Your request has been submitted for review. Please check your email for a confirmation message.";
                    $message_type = 'success';

                } catch (Exception $e) {
                    // Email failed, but the request is still in the system.
                    // This is OK. The admin can still approve it.
                    // We log the error.
                    error_log("Mailer Error (Request Confirmation): {$mail->ErrorInfo}");
                    
                    // Show a slightly different success message
                    $message = "Success! Your request has been submitted for review. (We couldn't send a confirmation email, but your request is in the system.)";
                    $message_type = 'success';
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
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    /* YOUR CSS IS UNCHANGED */
    :root {
      --primary-green: #4CAF50;
      --primary-dark: #2E7D32;
      --accent-gold: #FFD700;
      --text-dark: #1A1A1A;
      --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
      --shadow-lg: 0 8px 32px rgba(0,0,0,0.12);
      --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 25%, #f093fb 50%, #4facfe 75%, #00f2fe 100%);
      background-size: 400% 400%;
      animation: gradientShift 15s ease infinite;
      min-height: 100vh;
      font-family: 'Inter', sans-serif;
      display: flex;
      flex-direction: column;
      position: relative;
    }
    @keyframes gradientShift {
      0% { background-position: 0% 50%; }
      50% { background-position: 100% 50%; }
      100% { background-position: 0% 50%; }
    }
    .navbar {
        background: rgba(26, 26, 26, 0.95) !important;
        backdrop-filter: blur(20px);
        box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        padding: 1rem 0;
        position: relative;
        z-index: 1000;
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
    }
    .btn-danger, .btn-success { 
        padding: 0.6rem 1.5rem; 
        border-radius: 12px; 
        font-weight: 600; 
        transition: var(--transition); 
        border: none; 
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    }
    .main-content {
      flex: 1 0 auto;
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 2rem 0 50px;
      position: relative;
      z-index: 10;
    }
    .login-container {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(20px);
      border-radius: 24px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.3);
      padding: 40px;
      width: 100%;
      max-width: 520px;
      margin: 0 auto;
      animation: slideInRight 0.8s ease-out;
    }
    @keyframes slideInRight {
      from { opacity: 0; transform: translateX(50px); }
      to { opacity: 1; transform: translateX(0); }
    }
    .login-container .logo {
      width: 80px;
      height: 80px;
      object-fit: contain;
      margin-bottom: 15px;
    }
    .login-container h2 {
      font-weight: 800;
      color: #1A1A1A;
      font-family: 'Poppins', sans-serif;
      margin-bottom: 2rem;
    }
    .form-label {
      text-align: left;
      display: block;
      margin-bottom: 10px;
      font-weight: 600;
      color: #333;
      font-size: 0.95rem;
    }
    .form-control, .form-select {
      width: 100%;
      padding: 14px 14px 14px 3rem;
      font-size: 1rem;
      border-radius: 12px;
      border: 2px solid #e0e0e0;
      margin-bottom: 1.2rem;
      transition: var(--transition);
      background: #f8f9fa;
    }
    .form-control:focus, .form-select:focus {
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
    }
    /* New style for password validation message */
    .password-message {
      font-size: 0.875rem;
      margin-top: -15px;
      margin-bottom: 15px;
      display: none; /* Hide by default */
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
    footer { 
      flex-shrink: 0; 
      width: 100%; 
      background: rgba(26, 26, 26, 0.95);
      backdrop-filter: blur(20px);
      position: relative;
      z-index: 100;
    }
  </style>
</head>

<body>
  
  <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
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
                    <a class="nav-link" href="home.php">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="Event.php">Events</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="Teams.php">Teams</a>
                </li>
                <li class="nav-item">
                    <a href="login.php" class="btn btn-success ms-3">Admin Login</a>
                </li>
            </ul>
        </div>
    </div>
  </nav>

  <div class="main-content">
    <div class="container">
      <div class="row justify-content-center">
        <div class="col-lg-6">
          
          <div class="login-container">

            <div class="text-center">
              <img src="imageslogo.png" alt="PIT Logo" class="logo">
              <h2 id="login-view-title">Request an Account</h2>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type) ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($message_type !== 'success'): // Hide form on success ?>
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

                <div class="mb-3 text-start"> <label for="requested_role" class="form-label">I am a...</label>
                    <div class="position-relative">
                        <select class="form-select" id="requested_role" name="requested_role" required style="padding-left: 3rem;">
                            <option value="" disabled selected>Select your role</option>
                            <option value="Event Manager" <?= ($_POST['requested_role'] ?? '') == 'Event Manager' ? 'selected' : '' ?>>Event Manager</option>
                            <option value="Sports Director" <?= ($_POST['requested_role'] ?? '') == 'Sports Director' ? 'selected' : '' ?>>Sports Director</option>
                        </select>
                        <i class="fas fa-briefcase position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                    </div>
                </div>

                <div class="mb-3 text-start">
                    <label for="password" class="form-label">Password</label>
                    <div class="position-relative">
                        <input type="password" class="form-control" id="password" name="password" placeholder="Create a password (min. 8 characters)" required>
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
            <?php endif; ?>

            <p class="mt-4 text-muted text-center">
                <small>
                    <a href="login.php" style="text-decoration: none;">
                        <i class="fas fa-arrow-left me-1"></i> Back to Login
                    </a>
                </small>
            </p>

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
    // Navbar padding adjustment
    const navbarHeight = document.querySelector('.navbar').offsetHeight;
    document.querySelector('.main-content').style.paddingTop = `${navbarHeight + 30}px`;

    // --- New Password Validation Script ---
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
            // Disable submit if fields are empty (let 'required' handle it)
            submitButton.disabled = false; 
        }
    }

    // Add event listeners to check as the user types
    if (passwordInput && confirmPasswordInput) {
        passwordInput.addEventListener('keyup', validatePasswords);
        confirmPasswordInput.addEventListener('keyup', validatePasswords);
    }

    // Final check on form submit
    if (requestForm) {
        requestForm.addEventListener('submit', function(event) {
            validatePasswords();
            if (submitButton.disabled) {
                // Prevent form submission if validation failed
                event.preventDefault();
                messageElement.textContent = "Please fix the errors before submitting.";
                messageElement.style.display = 'block';
            }
        });
    }
  </script>
</body>
</html>