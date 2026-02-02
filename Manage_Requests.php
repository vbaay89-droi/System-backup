<?php
session_start();
require_once 'config.php'; // DB connection (Root directory)

// --- Load PHPMailer ---
require __DIR__ . '/PHPMailer-master/src/Exception.php';
require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 1. SECURITY & ACCESS CONTROL
// STRICT: Only 'Sports Director' is allowed (Acting as Super Admin)
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'Sports Director'
) {
    header('Location: login.php');
    exit();
}

$current_user_id = $_SESSION['user_id'];

// --- START: NEW NAME FETCHING LOGIC ---
$stmt_name = $conn->prepare("SELECT full_name, username FROM users WHERE id = ?");
$stmt_name->bind_param("i", $current_user_id);
$stmt_name->execute();
$result_name = $stmt_name->get_result();
$user_data = $result_name->fetch_assoc();
$stmt_name->close();

// Determine Name: Use Full Name if available, otherwise Username (Email)
if (!empty($user_data['full_name'])) {
    $name = $user_data['full_name'];
} else {
    $name = $user_data['username'] ?? 'Sports Director';
}
// --- END: NEW NAME FETCHING LOGIC ---
$current_page = basename($_SERVER['PHP_SELF']);
$message = '';
$message_type = '';

// --- ACTION LOGIC (APPROVE / REJECT / RESET) ---

// 1. APPROVE REQUEST
if (isset($_GET['action']) && $_GET['action'] == 'approve' && isset($_GET['id'])) {
    $user_id = (int)$_GET['id'];
    
    $conn->begin_transaction();
    
    try {
        // Step 1: Get user details
        $stmt_get = $conn->prepare("SELECT full_name, email FROM users WHERE id = ? AND is_approved = 0");
        $stmt_get->bind_param("i", $user_id);
        $stmt_get->execute();
        $result = $stmt_get->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            $user_email = $user['email'];
            $user_full_name = $user['full_name'];
            
            // Step 2: Approve user
            $stmt_update = $conn->prepare("UPDATE users SET is_approved = 1 WHERE id = ?");
            $stmt_update->bind_param("i", $user_id);
            $stmt_update->execute();
            
            // Step 3: Send Email
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'vbaay89@gmail.com'; // Your email
                $mail->Password   = 'vthz porq dnhj frdc'; // App Password
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                $mail->setFrom('vbaay89@gmail.com', 'PIT Sports Tallying');
                $mail->addAddress($user_email, $user_full_name); 

                $mail->isHTML(true);
                $mail->Subject = 'Your Account has been Approved!';
                $mail->Body    = "
                    <h2>Congratulations, " . htmlspecialchars($user_full_name) . "!</h2>
                    <p>Your account for the PIT Sports Tallying system has been approved by the Sports Director.</p>
                    <p>You can now log in using your email and the password you created during registration.</p>
                    <p>
                        <a href='http://{$_SERVER['HTTP_HOST']}/LOGIN_CAPSTONE/login.php' style='padding: 10px 15px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;'>
                            Login Here
                        </a>
                    </p>
                    <p>Thank you,<br>The PIT Sports Tallying Team</p>
                ";

                $mail->send();
                
                $conn->commit();
                $_SESSION['message'] = "User '{$user_email}' approved. Notification email sent.";
                $_SESSION['message_type'] = 'success';

            } catch (Exception $e_mail) {
                $conn->commit(); // Still approve even if email fails
                error_log("Mailer Error: {$mail->ErrorInfo}");
                $_SESSION['message'] = "User '{$user_email}' approved, but email failed to send.";
                $_SESSION['message_type'] = 'warning';
            }
            
        } else {
            throw new Exception("User not found or already approved.");
        }

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['message'] = "Error: " . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    
    header("Location: Manage_Requests.php");
    exit();
}

// 2. REJECT REQUEST
if (isset($_GET['action']) && $_GET['action'] == 'reject' && isset($_GET['id'])) {
    $user_id = (int)$_GET['id'];
    
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND is_approved = 0");
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $_SESSION['message'] = "Request rejected and user record deleted.";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = "Error: Could not reject request.";
        $_SESSION['message_type'] = 'danger';
    }
    $stmt->close();
    header("Location: Manage_Requests.php");
    exit();
}

// 3. RESET PASSWORD
if (isset($_GET['action']) && $_GET['action'] == 'reset' && isset($_GET['id'])) {
    $user_id = (int)$_GET['id'];
    
    try {
        $stmt_get = $conn->prepare("SELECT email FROM users WHERE id = ?");
        $stmt_get->bind_param("i", $user_id);
        $stmt_get->execute();
        $result = $stmt_get->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            $email = $user['email'];
            
            $new_password = bin2hex(random_bytes(8));
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            $stmt_update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt_update->bind_param("si", $hashed_password, $user_id);
            $stmt_update->execute();
            
            if ($stmt_update->affected_rows == 1) {
                // FIXED: Removed <strong> tags to prevent display issues
                $_SESSION['message'] = "Password reset for '{$email}'. The new temporary password is: $new_password";
                $_SESSION['message_type'] = 'success';
            } else {
                throw new Exception("Password not changed.");
            }
        } else {
            throw new Exception("User not found.");
        }
        
    } catch (Exception $e) {
        $_SESSION['message'] = "Error: " . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    header("Location: Manage_Requests.php");
    exit();
}

// 4. FETCH DATA
$pending_requests = [];
$processed_requests = [];

$result_pending = $conn->query("SELECT id, full_name, email, role, created_at FROM users WHERE is_approved = 0 ORDER BY created_at DESC");
if ($result_pending) {
    $pending_requests = $result_pending->fetch_all(MYSQLI_ASSOC);
}

$result_processed = $conn->query("SELECT id, full_name, email, role, created_at FROM users WHERE is_approved = 1 ORDER BY created_at DESC LIMIT 20");
if ($result_processed) {
    $processed_requests = $result_processed->fetch_all(MYSQLI_ASSOC);
}

if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Badge Counts for Sidebar
$pending_requests_count = count($pending_requests);
$pending_results_count = $conn->query("SELECT COUNT(*) FROM categories WHERE status='Results Submitted'")->fetch_row()[0] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Requests - Director Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 260px;
            --header-height: 82px;
            --transition: all 0.3s ease;
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            --bg-light: #F8F9FA;
            --primary-gradient: linear-gradient(135deg, #2c3e50 0%, #4ca1af 100%);
            --accent-color: #1abc9c;
        }
        body { background-color: var(--bg-light); margin: 0; padding: 0; min-height: 100vh; font-family: 'Inter', sans-serif; display: flex; flex-direction: column; }
        
        /* Navbar */
        .navbar { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.15); padding: 1rem 1.5rem; height: var(--header-height); position: fixed; top: 0; left: 0; right: 0; z-index: 1050; }
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; transition: var(--transition); }
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
        .user-dropdown .dropdown-toggle img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; margin-right: 10px; }
        
        /* Sidebar */
        .sidebar { width: var(--sidebar-width); position: fixed; top: var(--header-height); left: 0; height: calc(100vh - var(--header-height)); background: #2c3e50; color: white; box-shadow: 5px 0 15px rgba(0,0,0,0.2); z-index: 1040; transition: width var(--transition); overflow-y: auto; }
        .sidebar-nav { padding: 20px 0; }
        .sidebar-nav .nav-link { color: rgba(255, 255, 255, 0.7); font-size: 1.05rem; font-weight: 500; padding: 12px 25px; transition: var(--transition); border-left: 5px solid transparent; margin: 2px 0; display: flex; align-items: center; text-decoration: none; }
        .sidebar-nav .nav-link i { width: 30px; text-align: center; flex-shrink: 0; font-size: 0.95em; }
        .sidebar-nav .nav-link:hover { color: white; background: rgba(255, 255, 255, 0.05); border-left-color: var(--accent-color); }
        .sidebar-nav .nav-link.active { color: white; background: rgba(255, 255, 255, 0.1); border-left-color: #3498db; font-weight: 600; }
        .sidebar-nav .nav-title { padding: 15px 25px 5px; font-size: 0.75rem; font-weight: 700; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px; }

        /* Main Content */
        .main-content { flex: 1 0 auto; padding: 30px; margin-top: var(--header-height); margin-left: var(--sidebar-width); transition: margin-left var(--transition); min-height: calc(100vh - var(--header-height)); }
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        .card { border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        
        /* Footer */
        footer {
            flex-shrink: 0;
            /* REMOVED background color here so .footer-main can work */
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            padding-left: var(--sidebar-width);
            transition: padding-left var(--transition);
            position: relative;
            z-index: 1041;
        }

        @media (max-width: 992px) {
            .sidebar { left: -260px; }
            .sidebar.show { left: 0; }
            .main-content, footer { margin-left: 0; }
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
    
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="sd/sports_director_dashboard.php">
                <img src="imageslogo.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light" style="font-size: 0.75rem;">Director Panel</small>
                </div>
            </a>
            <button class="navbar-toggler d-lg-none" type="button" id="mobileToggle">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="dropdown user-dropdown ms-auto me-2 me-lg-0">
                <a href="#" class="dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle" style="font-size: 36px; margin-right: 10px; color: rgba(255,255,255,0.8);"></i>
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="admin_profile.php"><i class="fas fa-user-circle me-2"></i> Profile</a></li>
                    <li><a class="dropdown-item" href="Tournament_Manager_page.php" target="_blank"><i class="fas fa-globe me-2"></i> Public Site</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- UNIFIED SUPER ADMIN SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <ul class="nav flex-column sidebar-nav">
            <li class="nav-item">
                <a class="nav-link" href="sd/sports_director_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                </a>
            </li>
            
            <li class="nav-item mt-3"><span class="nav-title">Tournament Mgmt</span></li>
            <li class="nav-item">
                <a class="nav-link" href="sd/colleges.php">
                    <i class="fas fa-users me-2"></i> <span>Manage Teams</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="sd/events.php">
                    <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events (L1-L3)</span>
                </a>
            </li>

            <li class="nav-item mt-3"><span class="nav-title">Administration</span></li>
            <li class="nav-item">
                <a class="nav-link" href="Manage_Users.php">
                    <i class="fas fa-users-cog me-2"></i> <span>Manage Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="Manage_Requests.php">
                    <i class="fas fa-user-plus me-2"></i> <span>Account Requests</span>
                    <?php if($pending_requests_count > 0): ?>
                        <span class="badge bg-danger ms-auto rounded-pill"><?= $pending_requests_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
             <li class="nav-item">
                <a class="nav-link" href="Manage_Viewreports.php">
                    <i class="fas fa-file-alt me-2"></i> <span>View System Reports</span>
                </a>
            </li>

            <li class="nav-item mt-3"><span class="nav-title">Tallying & Scoring</span></li>
            <li class="nav-item">
                <a class="nav-link" href="sd/results.php">
                    <i class="fas fa-check-double me-2"></i> <span>Approve Results</span>
                    <?php if($pending_results_count > 0): ?>
                        <span class="badge bg-warning text-dark ms-auto rounded-pill"><?= $pending_results_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="sd/reports.php">
                    <i class="fas fa-chart-line me-2"></i> <span>Medal Standings</span>
                </a>
            </li>

            <!-- NEW SECTION: SEASON MANAGEMENT -->
            <li class="nav-item mt-3"><span class="nav-title">Season Management</span></li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'manage_archives.php') ? 'active' : '' ?>" href="manage_archives.php">
                    <i class="fas fa-history me-2"></i> <span>Archives & Reset</span>
                </a>
            </li>
            
            <li class="nav-item mt-auto">
                <a class="nav-link text-danger" href="logout.php">
                    <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
    
    <div class="main-content">
        <div class="container-fluid">
            
            <nav aria-label="breadcrumb" class="mb-4">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="sd/sports_director_dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Account Requests</li>
              </ol>
            </nav>

            <h1 class="section-title mb-4">Manage Account Requests</h1>
            
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <!-- Pending Requests -->
            <div class="card mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="mb-0 fw-bold text-warning"><i class="fas fa-clock me-2"></i>Pending Requests</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Full Name</th>
                                    <th>Email (Username)</th>
                                    <th>Role Requested</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_requests as $request): ?>
                                <tr>
                                    <td><?= htmlspecialchars($request['full_name']) ?></td>
                                    <td><?= htmlspecialchars($request['email']) ?></td>
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($request['role']) ?></td>
                                    <td><?= date('M d, Y h:i A', strtotime($request['created_at'])) ?></td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <a href="Manage_Requests.php?action=approve&id=<?= $request['id'] ?>" 
                                            class="btn btn-success btn-sm d-inline-flex align-items-center shadow-sm"
                                            title="Approve User"
                                            onclick="return confirm('Approve this user? An email will be sent.')">
                                                <i class="fas fa-check me-1"></i> Approve
                                            </a>

                                            <a href="Manage_Requests.php?action=reject&id=<?= $request['id'] ?>" 
                                            class="btn btn-danger btn-sm d-inline-flex align-items-center shadow-sm"
                                            title="Reject Request"
                                            onclick="return confirm('Reject and delete this request?')">
                                                <i class="fas fa-trash-alt me-1"></i> Reject
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($pending_requests)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No pending requests found.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Approved History -->
            <div class="card">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="mb-0 fw-bold text-success"><i class="fas fa-user-check me-2"></i>Recently Approved Users</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Full Name</th>
                                    <th>Email (Username)</th>
                                    <th>Role</th>
                                    <th>Date Approved</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($processed_requests as $request): ?>
                                <tr>
                                    <td><?= htmlspecialchars($request['full_name']) ?></td>
                                    <td><?= htmlspecialchars($request['email']) ?></td>
                                    <td><?= htmlspecialchars($request['role']) ?></td>
                                    <td><?= date('M d, Y', strtotime($request['created_at'])) ?></td>
                                    <td>
                                        <span class="badge bg-success me-2">Active</span>
                                        <a href="Manage_Requests.php?action=reset&id=<?= $request['id'] ?>" 
                                           class="btn btn-sm btn-outline-warning" 
                                           title="Reset Password"
                                           onclick="return confirm('Reset password for this user?')">
                                            <i class="fas fa-key"></i> Reset
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($processed_requests)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No approved users yet.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
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
                        <img src="images/Cote.png" alt="Logo">
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
            // Sidebar Toggle
            const mobileToggle = document.getElementById('mobileToggle');
            if (mobileToggle) {
                mobileToggle.addEventListener('click', function() {
                    document.getElementById('sidebar').classList.toggle('show');
                });
            }
            
            // Dynamic Footer
            const footer = document.querySelector('footer');
            const sidebar = document.getElementById('sidebar');
            const navbar = document.querySelector('.navbar');

            if (sidebar && footer && navbar) {
                function adjustSidebarHeight() {
                    if (window.innerWidth <= 992) {
                        sidebar.style.height = ''; 
                        return;
                    }
                    const navbarHeight = navbar.offsetHeight;
                    const footerTop = footer.getBoundingClientRect().top;
                    const viewportHeight = window.innerHeight;
                    const maxSidebarHeight = viewportHeight - navbarHeight;
                    const availableHeight = footerTop - navbarHeight;
                    const newHeight = Math.max(0, Math.min(maxSidebarHeight, availableHeight));
                    sidebar.style.height = `${newHeight}px`;
                }
                window.addEventListener('scroll', adjustSidebarHeight, { passive: true });
                window.addEventListener('resize', adjustSidebarHeight);
                setTimeout(adjustSidebarHeight, 100);
            }

            // ==========================================
        // 2. REAL-TIME BADGE UPDATER
        // ==========================================
        function updateSidebarBadges() {
            fetch('api_notifications.php?t=' + new Date().getTime())
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update "Approve Results" (Yellow)
                        updateSingleBadge('results.php', data.pending_results, 'bg-warning text-dark');

                        // Update "Account Requests" (Red)
                        updateSingleBadge('Manage_Requests.php', data.pending_requests, 'bg-danger');
                    }
                })
                .catch(err => console.error('Badge update error:', err));
        }

        function updateSingleBadge(hrefKeyword, count, colorClasses) {
            const link = document.querySelector(`.sidebar-nav .nav-link[href*="${hrefKeyword}"]`);
            if (link) {
                let badge = link.querySelector('.badge');
                if (count > 0) {
                    if (!badge) {
                        badge = document.createElement('span');
                        link.appendChild(badge);
                    }
                    badge.className = `badge ${colorClasses} ms-auto rounded-pill`;
                    badge.textContent = count;
                } else {
                    if (badge) badge.remove();
                }
            }
        }

        // Run Badges
        updateSidebarBadges();
        setInterval(updateSidebarBadges, 5000);
        });
    </script>
</body>
</html>