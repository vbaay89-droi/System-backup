<?php
session_start();

// Check if pending login session exists
if (!isset($_SESSION['pending_user_id'], $_SESSION['pending_username'], $_SESSION['pending_user_role'])) {
    header("Location: login.php");
    exit();
}

// Handle OTP submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $_SESSION['role'] = $_SESSION['pending_user_role']; // This must match your dashboard check

            // Clear temporary OTP data
            unset(
                $_SESSION['otp'],
                $_SESSION['otp_expiry'],
                $_SESSION['pending_user_id'],
                $_SESSION['pending_username'],
                $_SESSION['pending_user_role']
            );

            // Redirect based on role
            // This line checks the correct variable
                switch ($_SESSION['role']) {
                case 'Event Manager':
                    header("Location: event_manager_dashboard.php");
                    break;
                case 'Administrator':
                    header("Location: admin_dashboard.php");
                    break;
                case 'Sports Director':
                    header("Location: sd/sports_director_dashboard.php");
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
    <title>Verify OTP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
    /* --- Styles for unified header design --- */
    :root {
        --primary-green: #4CAF50;
        --primary-dark: #2E7D32;
        --accent-gold: #FFD700;
        /* Add other necessary variables from Tournament_Manager_page.php if needed, or adjust */
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    body /* Added from Tournament_Manager_page.php */
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
            /* 👇 ADD THESE LINES for centering */
            justify-content: center; 
            align-items: center;
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
        .verify-card {
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 6px 18px rgba(0,0,0,0.08);
            padding: 40px;
            width: 380px;
            text-align: center;
        }
        .icon-circle {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: #E6E6FA;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0 auto 20px;
        }
        .icon-circle i {
            font-size: 28px;
            color: #6A0DAD;
        }
        .verify-card h4 {
            color: #333;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .verify-card p {
            color: #555;
            font-size: 14px;
            margin-bottom: 30px;
        }
        .otp-inputs {
            display: flex;
            justify-content: space-between;
            margin-bottom: 25px;
        }
        .otp-inputs input {
            width: 48px;
            height: 52px;
            border-radius: 8px;
            border: 2px solid #ddd;
            text-align: center;
            font-size: 20px;
            font-weight: 600;
            color: #333;
        }
        .otp-inputs input:focus {
            border-color: #6A0DAD;
            outline: none;
        }
        .btn-confirm {
            background: #007bff;
            border: none;
            color: #fff;
            width: 100%;
            padding: 14px;
            font-size: 16px;
            border-radius: 12px;
            font-weight: 600;
        }
        .btn-confirm:hover {
            background: #580b8d;
        }
    </style>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const inputs = document.querySelectorAll(".otp-inputs input");

            // Move forward automatically
            inputs.forEach((input, index) => {
                input.addEventListener("input", () => {
                    if (input.value.length === 1 && index < inputs.length - 1) {
                        inputs[index + 1].focus();
                    }
                });
                input.addEventListener("keydown", (e) => {
                    if (e.key === "Backspace" && input.value === "" && index > 0) {
                        inputs[index - 1].focus();
                    }
                });

                // Handle paste event (spread across inputs)
                input.addEventListener("paste", (e) => {
                    e.preventDefault();
                    const pasteData = (e.clipboardData || window.clipboardData).getData("text");
                    if (/^\d+$/.test(pasteData)) {
                        pasteData.split("").forEach((char, i) => {
                            if (i < inputs.length) {
                                inputs[i].value = char;
                            }
                        });
                        // Focus last filled box
                        const last = Math.min(pasteData.length, inputs.length) - 1;
                        inputs[last].focus();
                    }
                });
            });

            // Join OTP before submit
            const form = document.getElementById("otp-form");
            form.addEventListener("submit", () => {
                const hiddenInput = document.getElementById("otp");
                hiddenInput.value = Array.from(inputs).map(i => i.value).join("");
            });
        });
         const otpExpiry = <?= $_SESSION['otp_expiry'] ?? '0' ?> * 1000; // Convert to ms
// Countdown Timer
document.addEventListener("DOMContentLoaded", () => {
    const countdownEl = document.getElementById("countdown");
    const form = document.getElementById("otp-form");
    const button = form.querySelector("button");
    
    function updateCountdown() {
        const now = new Date().getTime();
        const distance = otpExpiry - now;

        if (distance <= 0) {
            countdownEl.textContent = "OTP expired. Please login again.";
            countdownEl.style.color = "red";
            button.disabled = true;
            Array.from(form.querySelectorAll("input")).forEach(i => i.disabled = true);
            clearInterval(timer);
            return;
        }

        const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((distance % (1000 * 60)) / 1000);
        countdownEl.textContent = `OTP expires in ${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    }

    updateCountdown(); // Run immediately
    const timer = setInterval(updateCountdown, 1000);
});

    </script>
</head>
<body>
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


    <div class="verify-card">
        <div class="icon-circle">
            <i class="bi bi-envelope-fill"></i>
        </div>
        <h4>Verify Your Email</h4>
        <p>
        Please Enter The Verification Code We Sent To 
        <b><?= htmlspecialchars($_SESSION['pending_username']); ?></b>
        <p id="countdown" style="font-weight:600; color:#000;">OTP expires in 05:00</p>


        </p>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger"><?= $error_message ?></div>
        <?php endif; ?>

        <form method="POST" id="otp-form">
            <div class="otp-inputs">
                <input type="text" maxlength="1">
                <input type="text" maxlength="1">
                <input type="text" maxlength="1">
                <input type="text" maxlength="1">
                <input type="text" maxlength="1">
                <input type="text" maxlength="1">
            </div>
            <input type="hidden" name="otp" id="otp">
            <button type="submit" class="btn btn-confirm">Confirm</button>
        </form>
    </div>

    <!-- Bootstrap icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
</body>
</html>
