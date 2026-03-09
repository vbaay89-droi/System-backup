<?php
session_start();

// --- LOAD PHPMAILER ---
require __DIR__ . '/PHPMailer-master/src/Exception.php';
require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- LOGIC: RESEND OTP ---
if (isset($_POST['resend_otp'])) {
    try {
        // 1. Generate NEW OTP
        $new_otp = random_int(100000, 999999);
        $_SESSION['otp'] = $new_otp;
        $_SESSION['otp_expiry'] = time() + (5 * 60); // Reset timer to 5 minutes

        // 2. Send Email
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'vbaay89@gmail.com'; // Your email
        $mail->Password   = 'vthz porq dnhj frdc'; // Your App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('vbaay89@gmail.com', 'PIT Sports Tallying');
        
        // Ensure email is available (Add this to login.php if missing: $_SESSION['email_for_otp'] = $user['email'];)
        $recipient = $_SESSION['email_for_otp'] ?? $_SESSION['pending_username']; 
        $mail->addAddress($recipient); 

        $mail->isHTML(true);
        $mail->Subject = 'New OTP Request - PIT Sports Tallying';
        $mail->Body    = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
            <h2 style="color: #2E7D32; text-align: center;">New OTP Request</h2>
            <p style="text-align: center; color: #555;">Here is your new verification code:</p>
            <div style="background: #f0fdf4; color: #2E7D32; font-size: 32px; font-weight: bold; text-align: center; padding: 15px; border: 2px dashed #4CAF50; letter-spacing: 5px; margin: 20px 0;">' . $new_otp . '</div>
            <p style="text-align: center; color: #777;">This code expires in 5 minutes.</p>
        </div>';

        $mail->send();
        $resend_success = "A new OTP has been sent to your email!";
        
    } catch (Exception $e) {
        $error_message = "Failed to send OTP. Please try again.";
    }
}

// Check if pending login session exists
if (!isset($_SESSION['pending_user_id'], $_SESSION['pending_username'], $_SESSION['pending_user_role'])) {
    header("Location: login.php");
    exit();
}

// Handle OTP submission
// Only try to verify if we are NOT resending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['resend_otp'])) {
    $userOtp = $_POST['otp'] ?? '';

    if (isset($_SESSION['otp'], $_SESSION['otp_expiry'])) {
        // OTP expiration check
        if (time() > $_SESSION['otp_expiry']) {
            $error_message = "OTP expired. Please login again.";
            session_destroy();
        } elseif ($userOtp == $_SESSION['otp']) {
            // ✅ OTP correct: log the user in
            $_SESSION['logged_in'] = true;

            // Promote pending session to active session
            $_SESSION['user_id'] = $_SESSION['pending_user_id'];
            $_SESSION['username'] = $_SESSION['pending_username'];
            $_SESSION['role'] = $_SESSION['pending_user_role']; 

            // Clear temporary OTP data
            unset(
                $_SESSION['otp'],
                $_SESSION['otp_expiry'],
                $_SESSION['pending_user_id'],
                $_SESSION['pending_username'],
                $_SESSION['pending_user_role']
            );

            // Redirect based on role
            switch ($_SESSION['role']) {
                case 'Sports Director':
                    header("Location: sd/sports_director_dashboard.php");
                    break;
                case 'Tournament Manager':
                    header("Location: tournamentmanager_dashboard.php");
                    break;
                default:
                    header("Location: index.php");
                    break;
            }
            exit();
        } else {
            $error_message = "Invalid OTP. Please try again.";
        }
    } else {
        $error_message = "No OTP found. Please login again.";
    }
}

// Set OTP expiry only if it doesn't already exist
if (!isset($_SESSION['otp_expiry'])) {
    $_SESSION['otp_expiry'] = time() + (5 * 60); // 5 minutes
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP - SmartScore PIT</title>
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
            --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 32px rgba(0,0,0,0.12);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            margin: 0; padding: 0; min-height: 100vh;
            font-family: 'Inter', sans-serif;
            display: flex; flex-direction: column;
            position: relative; overflow-x: hidden;
        }

        /* Background with overlay */
        body::before {
            content: ''; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: url('images/featured_image.png'); background-size: cover;
            z-index: -2;
        }
        body::after {
            content: ''; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(135deg, rgba(0,0,0,0.7) 0%, rgba(0,0,0,0.5) 50%, rgba(0,0,0,0.7) 100%);
            z-index: -1;
        }

        /* Particles */
        .particles { position: fixed; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1; }
        .particle {
            position: absolute; background: rgba(255, 255, 255, 0.3); border-radius: 50%;
            animation: float 20s infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0) translateX(0) rotate(0deg); opacity: 0; }
            10% { opacity: 1; } 90% { opacity: 1; }
            100% { transform: translateY(-100vh) translateX(100px) rotate(360deg); opacity: 0; }
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
        @keyframes borderGlow { 0%, 100% { opacity: 0.5; } 50% { opacity: 1; } }

        .navbar-brand { transition: var(--transition); }
        .navbar-brand:hover { transform: translateY(-2px) scale(1.02); }

        .brand-logo { 
            filter: drop-shadow(0 4px 8px rgba(255,255,255,0.3));
            transition: filter 0.3s;
            animation: pulse 2s ease-in-out infinite;
        }

        .brand-heading { 
            font-family: 'Poppins', sans-serif; font-weight: 700; letter-spacing: -0.5px;
            transition: color 0.3s;
            background: linear-gradient(90deg, #fff, #4CAF50);
            -webkit-background-clip: text; background-clip: text;
            -webkit-text-fill-color: transparent; 
        }
        
        .nav-link { 
            font-weight: 500; font-size: 0.95rem; padding: 0.5rem 1.25rem !important; 
            margin: 0 0.25rem; border-radius: 12px; transition: var(--transition); 
            position: relative; overflow: hidden; color: rgba(255,255,255,0.8) !important;
        }
        .nav-link:hover, .nav-link.active { 
            background: rgba(76, 175, 80, 0.2); 
            color: var(--primary-green) !important;
            transform: translateY(-2px);
        }

        .btn-success { 
            padding: 0.6rem 1.5rem; border-radius: 12px; font-weight: 600; 
            transition: var(--transition); border: none; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.2); position: relative; overflow: hidden;
        }
        .btn-success:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.3); }


        /* Main Layout */
        .main-content { flex: 1; display: flex; justify-content: center; align-items: center; padding: 3rem 0; position: relative; z-index: 10; }
        .login-wrapper { width: 100%; max-width: 1400px; margin: 0 auto; padding: 0 2rem; }

        /* Verify Container (Centered Card) */
        .verify-container {
            background: rgba(255, 255, 255, 0.98); backdrop-filter: blur(30px);
            border-radius: 24px; box-shadow: 0 20px 60px rgba(0,0,0,0.5), 0 0 100px rgba(76, 175, 80, 0.2), inset 0 0 100px rgba(255,255,255,0.1);
            padding: 3rem 2.5rem; 
            width: 100%; max-width: 550px; 
            margin: 0 auto; 
            position: relative; overflow: hidden;
            border: 2px solid rgba(255,255,255,0.3); 
            animation: fadeInUp 0.8s ease-out;
        }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(50px); } to { opacity: 1; transform: translateY(0); } }
        
        /* FIX: Added pointer-events: none to prevent blocking clicks */
        .verify-container::before {
            content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
            background: linear-gradient(45deg, transparent, rgba(76, 175, 80, 0.1), transparent);
            animation: shine 4s infinite;
            pointer-events: none; 
            z-index: 1;
        }
        @keyframes shine { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        .verify-container::after {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 5px;
            background: linear-gradient(90deg, var(--primary-green), var(--accent-gold), var(--primary-green));
            background-size: 200% 100%; animation: gradientMove 3s ease infinite;
            pointer-events: none;
            z-index: 1;
        }
        @keyframes gradientMove { 0%, 100% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } }

        .verify-container h2 {
            font-weight: 800; color: #1A1A1A; font-family: 'Poppins', sans-serif; margin-bottom: 1.5rem;
            position: relative; display: inline-block; font-size: 1.8rem;
            z-index: 2; /* Ensure text is above shine */
        }
        .verify-container h2::after {
            content: ''; position: absolute; bottom: -10px; left: 50%; transform: translateX(-50%);
            width: 60px; height: 4px; background: linear-gradient(90deg, var(--primary-green), var(--accent-gold));
            border-radius: 2px; animation: expandWidth 2s ease-in-out infinite;
        }
        @keyframes expandWidth { 0%, 100% { width: 60px; } 50% { width: 100px; } }

        .verify-subtext { 
            color: #666; font-size: 0.95rem; margin-bottom: 2rem; margin-top: 0.5rem; font-weight: 500; 
            position: relative; z-index: 2;
        }

        /* Logos inside form */
        .form-logos {
            display: flex; justify-content: center; align-items: center; gap: 1.5rem; margin-bottom: 1.5rem;
            position: relative; z-index: 2;
        }
        .form-logo-img {
            width: 80px; height: 80px; object-fit: contain;
            filter: drop-shadow(0 5px 15px rgba(0,0,0,0.2));
            transition: transform 0.3s ease;
        }
        .form-logo-img:hover { transform: scale(1.1); }

        /* --- OTP SPECIFIC STYLES --- */
        .otp-inputs {
            display: flex; justify-content: center; gap: 10px; margin-bottom: 25px;
            position: relative; z-index: 10; /* FIX: Bring inputs to front */
        }
        .otp-inputs input {
            width: 50px; height: 55px; border-radius: 12px;
            border: 2px solid #e0e0e0; background: #f8f9fa;
            text-align: center; font-size: 1.25rem; font-weight: 700; color: #333;
            transition: var(--transition);
            position: relative; /* FIX: Ensure z-index applies */
        }
        .otp-inputs input:focus {
            border-color: var(--primary-green); outline: none;
            box-shadow: 0 0 0 4px rgba(76, 175, 80, 0.1); background: white; transform: translateY(-3px);
        }

        .btn-verify {
            width: 100%; padding: 15px 0; font-size: 1.15rem; font-weight: 700; border-radius: 12px;
            transition: var(--transition); background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none; color: white; text-transform: uppercase; letter-spacing: 1px;
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4); position: relative; overflow: hidden;
            z-index: 2;
        }
        .btn-verify:hover { transform: translateY(-3px); box-shadow: 0 12px 30px rgba(102, 126, 234, 0.5); }
        .btn-verify:disabled { background: #ccc; cursor: not-allowed; transform: none; box-shadow: none; }

        #countdown { 
            font-weight: 600; color: #d9534f; margin-top: 15px; font-size: 0.95rem; 
            position: relative; z-index: 2;
        }
        .alert { border-radius: 12px; border: none; animation: slideDown 0.5s ease-out; position: relative; z-index: 2;}
        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        
        /* Footer */
        footer { 
            width: 100%; background: rgba(26, 26, 26, 0.98); backdrop-filter: blur(20px);
            position: relative; z-index: 100; padding: 1rem 0; color: white;
        }
        footer::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, transparent, var(--primary-green), var(--accent-gold), transparent);
        }
    </style>

    <script>
        document.addEventListener("DOMContentLoaded", () => {
            // --- PARTICLE ANIMATION ---
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

            // --- OTP LOGIC ---
            const inputs = document.querySelectorAll(".otp-inputs input");
            const hiddenInput = document.getElementById("otp");
            const form = document.getElementById("otp-form");

            inputs.forEach((input, index) => {
                // Auto focus next input
                input.addEventListener("input", () => {
                    if (input.value.length === 1 && index < inputs.length - 1) {
                        inputs[index + 1].focus();
                    }
                });
                // Handle Backspace
                input.addEventListener("keydown", (e) => {
                    if (e.key === "Backspace" && input.value === "" && index > 0) {
                        inputs[index - 1].focus();
                    }
                });
                // Handle Paste
                input.addEventListener("paste", (e) => {
                    e.preventDefault();
                    const pasteData = (e.clipboardData || window.clipboardData).getData("text");
                    if (/^\d+$/.test(pasteData)) {
                        pasteData.split("").forEach((char, i) => {
                            if (i < inputs.length) inputs[i].value = char;
                        });
                        const last = Math.min(pasteData.length, inputs.length) - 1;
                        inputs[last].focus();
                    }
                });
            });

            form.addEventListener("submit", () => {
                hiddenInput.value = Array.from(inputs).map(i => i.value).join("");
            });

            // --- COUNTDOWN ---
            const otpExpiry = <?= $_SESSION['otp_expiry'] ?? '0' ?> * 1000;
            const countdownEl = document.getElementById("countdown");
            const button = form.querySelector("button");
            
            const timer = setInterval(() => {
                const now = new Date().getTime();
                const distance = otpExpiry - now;

                if (distance <= 0) {
                    countdownEl.textContent = "OTP expired. Please login again.";
                    button.disabled = true;
                    inputs.forEach(i => i.disabled = true);
                    clearInterval(timer);
                    return;
                }
                const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((distance % (1000 * 60)) / 1000);
                countdownEl.textContent = `Expires in ${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
            }, 1000);
        });
    </script>
</head>
<body>
    <div class="particles" id="particles"></div>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center interactive-brand" href="home.php">
                <img src="images/PIT.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white brand-heading" style="font-size: 1.25rem;">PIT SIGLAKAS MEDAL TALLY</strong>
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
                        <a class="nav-link" href="Eventpage.php">Events</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="college_team.php">Colleges</a>
                    </li>
                    <li class="nav-item">
                        <a href="login.php" class="btn btn-success ms-3">Admin Login</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="login-wrapper">
            <div class="row justify-content-center">
                
                <div class="col-lg-6 col-md-8">
                    <div class="verify-container text-center">
    <div class="form-logos">
        <img src="images/PIT.png" alt="PIT Logo" class="form-logo-img">
    </div>
    
    <h2 class="fw-bold mb-2">Enter OTP Code</h2>
    
    <p class="verify-subtext">
        We've sent a code to the email for<br>
        <strong class="text-dark"><?= htmlspecialchars($_SESSION['pending_username']); ?></strong>
    </p>
    
    <?php if (isset($resend_success)): ?>
        <div class="alert alert-success py-2 mb-4 shadow-sm">
            <i class="fas fa-check-circle me-1"></i> <?= $resend_success ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger py-2 mb-4 shadow-sm"><?= $error_message ?></div>
    <?php endif; ?>

    <form method="POST" id="otp-form">
        <div class="otp-inputs">
            <input type="text" maxlength="1" pattern="\d" required>
            <input type="text" maxlength="1" pattern="\d" required>
            <input type="text" maxlength="1" pattern="\d" required>
            <input type="text" maxlength="1" pattern="\d" required>
            <input type="text" maxlength="1" pattern="\d" required>
            <input type="text" maxlength="1" pattern="\d" required>
        </div>
        <input type="hidden" name="otp" id="otp">
        
        <button type="submit" class="btn btn-verify" id="verifyBtn">
            <i class="fas fa-shield-alt me-2"></i> Verify & Login
        </button>
    </form>

    <div id="countdown" class="mt-3 fw-bold text-danger">Expires in 05:00</div>

    <form method="POST" id="resend-form" style="display: none; margin-top: 15px;">
        <input type="hidden" name="resend_otp" value="1"> <p class="text-danger fw-bold small mb-2">Code Expired</p>
        <button type="submit" class="btn btn-success w-30 rounded-pill fw-bold">
            <i class="fas fa-sync-alt me-2"></i> Resend Code
        </button>
    </form>
</div>
                </div>

            </div>
        </div>
    </div>

    <footer class="text-center">
        <small>&copy; <?= date("Y") ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
        <small>Developed by Tsunayoshi Sawada</small>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            // --- OTP INPUT LOGIC ---
            const inputs = document.querySelectorAll(".otp-inputs input");
            const hiddenInput = document.getElementById("otp");
            const verifyForm = document.getElementById("otp-form");

            inputs.forEach((input, index) => {
                input.addEventListener("input", () => {
                    if (input.value.length === 1 && index < inputs.length - 1) inputs[index + 1].focus();
                });
                input.addEventListener("keydown", (e) => {
                    if (e.key === "Backspace" && input.value === "" && index > 0) inputs[index - 1].focus();
                });
                input.addEventListener("paste", (e) => {
                    e.preventDefault();
                    const pasteData = (e.clipboardData || window.clipboardData).getData("text");
                    if (/^\d+$/.test(pasteData)) {
                        pasteData.split("").forEach((char, i) => {
                            if (i < inputs.length) inputs[i].value = char;
                        });
                        const last = Math.min(pasteData.length, inputs.length) - 1;
                        if(last >= 0) inputs[last].focus();
                    }
                });
            });

            verifyForm.addEventListener("submit", () => {
                hiddenInput.value = Array.from(inputs).map(i => i.value).join("");
            });

            // --- COUNTDOWN & RESEND LOGIC ---
            const otpExpiry = <?= $_SESSION['otp_expiry'] ?? '0' ?> * 1000;
            const countdownEl = document.getElementById("countdown");
            const verifyBtn = document.getElementById("verifyBtn");
            const resendForm = document.getElementById("resend-form");
            
            const timer = setInterval(() => {
                const now = new Date().getTime();
                const distance = otpExpiry - now;

                if (distance <= 0) {
                    clearInterval(timer);
                    
                    // 1. Hide Countdown Text
                    countdownEl.style.display = 'none';

                    // 2. DISABLE Verify Button (Instead of hiding it)
                    if(verifyBtn) {
                        verifyBtn.disabled = true;       // Make it unclickable
                        verifyBtn.style.opacity = '0.6'; // Make it look faded/disabled
                        verifyBtn.style.cursor = 'not-allowed';
                    }

                    // 3. Disable Inputs
                    inputs.forEach(i => i.disabled = true);

                    // 4. Show Resend Form
                    if(resendForm) resendForm.style.display = 'block';
                    
                    return;
                }

                const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((distance % (1000 * 60)) / 1000);
                countdownEl.textContent = `Expires in ${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
            }, 1000);
        });
    </script>
</body>
</html>