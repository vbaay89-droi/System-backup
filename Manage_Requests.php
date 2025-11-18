<?php
session_start();
require_once 'db_connect.php'; // DB connection

// --- NEW: Load PHPMailer ---
require __DIR__ . '/PHPMailer-master/src/Exception.php';
require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Strict Role-Based Access Control
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Administrator') {
    header('Location: login.php');
    exit();
}

$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin';
$current_page = basename($_SERVER['PHP_SELF']);

// --- Sidebar Active State Logic (Unchanged) ---
$event_pages = ['Manage_Games.php', 'Manage_Game_Events.php', 'Manage_Categories.php'];
$is_event_page = in_array($current_page, $event_pages);

$message = '';
$message_type = '';

// --- ACTION LOGIC (APPROVE / REJECT / RESET) ---

// 1. APPROVE REQUEST (REWRITTEN)
if (isset($_GET['action']) && $_GET['action'] == 'approve' && isset($_GET['id'])) {
    $user_id = (int)$_GET['id'];
    
    $conn->begin_transaction();
    
    try {
        // Step 1: Get the user's details (from 'users' table)
        $stmt_get = $conn->prepare("SELECT full_name, email FROM users WHERE id = ? AND is_approved = 0");
        $stmt_get->bind_param("i", $user_id);
        $stmt_get->execute();
        $result = $stmt_get->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            $user_email = $user['email'];
            $user_full_name = $user['full_name'];
            
            // Step 2: Approve the user (set is_approved = 1)
            $stmt_update = $conn->prepare("UPDATE users SET is_approved = 1 WHERE id = ?");
            $stmt_update->bind_param("i", $user_id);
            $stmt_update->execute();
            
            // Step 3: Send the "Account Approved" email
            $mail = new PHPMailer(true);
            try {
                // --- Email Server Settings (Check these!) ---
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'vbaay89@gmail.com'; // Your email
                $mail->Password   = 'vthz porq dnhj frdc'; // Your App Password
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                $mail->setFrom('vbaay89@gmail.com', 'PIT Sports Tallying');
                $mail->addAddress($user_email, $user_full_name); 

                $mail->isHTML(true);
                $mail->Subject = 'Your Account has been Approved!';
                $mail->Body    = "
                    <h2>Congratulations, " . htmlspecialchars($user_full_name) . "!</h2>
                    <p>Your account for the PIT Sports Tallying system has been approved by an administrator.</p>
                    <p>You can now log in using your email and the password you created during registration.</p>
                    <p>
                        <a href='http://{$_SERVER['HTTP_HOST']}/LOGIN_CAPSTONE/login.php' style='padding: 10px 15px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;'>
                            Login Here
                        </a>
                    </p>
                    <p>Thank you,<br>The PIT Sports Tallying Team</p>
                ";

                $mail->send();
                
                // Commit DB changes *after* email is sent
                $conn->commit();
                $_SESSION['message'] = "User '{$user_email}' approved. An email notification has been sent.";
                $_SESSION['message_type'] = 'success';

            } catch (Exception $e_mail) {
                // Email failed, but we should still approve the user.
                // Commit the DB change and show a warning.
                $conn->commit();
                error_log("Mailer Error (Approval Email): {$mail->ErrorInfo}");
                $_SESSION['message'] = "User '{$user_email}' approved, but the notification email could not be sent. Please contact them manually.";
                $_SESSION['message_type'] = 'warning';
            }
            
        } else {
            // Request not found or already processed
            throw new Exception("User not found or already approved.");
        }

    } catch (Exception $e) {
        // A database error occurred
        $conn->rollback();
        $_SESSION['message'] = "A database error occurred: " . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    
    header("Location: Manage_Requests.php");
    exit();
}

// 2. REJECT REQUEST (REWRITTEN)
if (isset($_GET['action']) && $_GET['action'] == 'reject' && isset($_GET['id'])) {
    $user_id = (int)$_GET['id'];
    
    // We DELETE the user record entirely, but only if it's still pending
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND is_approved = 0");
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $_SESSION['message'] = "Request has been rejected and the user record deleted.";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = "Error: Could not process rejection or user was already processed.";
        $_SESSION['message_type'] = 'danger';
    }
    $stmt->close();
    header("Location: Manage_Requests.php");
    exit();
}

// 3. RESET PASSWORD (UPDATED)
if (isset($_GET['action']) && $_GET['action'] == 'reset' && isset($_GET['id'])) {
    $user_id = (int)$_GET['id']; // This is now the 'id' from the 'users' table
    
    try {
        // Step 1: Get the email (username) from the 'users' table
        $stmt_get = $conn->prepare("SELECT email FROM users WHERE id = ?");
        $stmt_get->bind_param("i", $user_id);
        $stmt_get->execute();
        $result = $stmt_get->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            $email = $user['email'];
            
            // Step 2: Generate new random password
            $new_password = bin2hex(random_bytes(8));
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Step 3: Update the password in the 'users' table
            $stmt_update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            // Bind password hash (string) and id (integer)
            $stmt_update->bind_param("si", $hashed_password, $user_id);
            $stmt_update->execute();
            
            if ($stmt_update->affected_rows == 1) {
                $_SESSION['message'] = "Password for '{$email}' reset. Their NEW one-time password is: $new_password";
                $_SESSION['message_type'] = 'success';
            } else {
                throw new Exception("Password was not changed (it might be the same as the old one).");
            }
        } else {
            throw new Exception("Could not find user.");
        }
        
    } catch (Exception $e) {
        $_SESSION['message'] = "An error occurred: " . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    
    header("Location: Manage_Requests.php");
    exit();
}
// --- END ACTION LOGIC ---


// 4. FETCH DATA (REWRITTEN)
$pending_requests = [];
$processed_requests = [];

// Get PENDING users (is_approved = 0)
$result_pending = $conn->query("SELECT id, full_name, email, role, created_at FROM users WHERE is_approved = 0 ORDER BY created_at DESC");
if ($result_pending) {
    $pending_requests = $result_pending->fetch_all(MYSQLI_ASSOC);
}

// Get PROCESSED users (is_approved = 1)
$result_processed = $conn->query("SELECT id, full_name, email, role, created_at FROM users WHERE is_approved = 1 ORDER BY created_at DESC LIMIT 20");
if ($result_processed) {
    $processed_requests = $result_processed->fetch_all(MYSQLI_ASSOC);
}

// Check for session messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Account Requests - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="css/admin_style.css" rel="stylesheet"> 
    <style>
        /* Your CSS is UNCHANGED */
        :root {
            --primary-gradient: linear-gradient(135deg, #7451eb 0%, #3498db 100%);
            --sidebar-width: 260px;
            --sidebar-min-width: 80px;
            --header-height: 82px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        }
        body { background-color: #F8F9FA; font-family: 'Inter', sans-serif; }
        .navbar { /* ... navbar styles ... */ }
        .sidebar { /* ... sidebar styles ... */ }
        .main-content { /* ... main-content styles ... */ }
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        .card { border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        .table-responsive { margin-top: 1.5rem; }
        .table .badge { font-size: 0.8rem; padding: 0.4em 0.6em; }
        .btn-action { margin-right: 5px; }
        
        body { background-color: var(--bg-light); margin: 0; padding: 0; min-height: 100vh; font-family: 'Inter', sans-serif; display: flex; flex-direction: column; }
        .navbar { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.15); padding: 1rem 1.5rem; height: var(--header-height); position: fixed; top: 0; left: 0; right: 0; z-index: 1050; }
        .navbar-brand { /* ... */ }
        .user-dropdown .dropdown-toggle { 
            color: white; 
            display: flex; 
            align-items: center; 
            text-decoration: none; /* This removed the underline */
            padding: 8px 12px; 
            border-radius: 8px; 
            transition: var(--transition); 
        }
        .user-dropdown .dropdown-toggle:hover { 
            background-color: rgba(255, 255, 255, 0.1); 
        }
        .user-dropdown .dropdown-toggle .user-name { 
            font-weight: 600; 
            font-size: 0.95rem; 
        }

        .navbar-profile-icon {
            width: 36px; 
            height: 36px; 
            font-size: 36px; 
            text-align: center;
            line-height: 1;
            border-radius: 50%; 
            margin-right: 10px; 
            color: rgba(255,255,255,0.8);
        }
        .sidebar { width: var(--sidebar-width); position: fixed; top: var(--header-height); left: 0; height: calc(100vh - var(--header-height)); background: #2c3e50; color: white; box-shadow: 5px 0 15px rgba(0,0,0,0.2); z-index: 1040; transition: width var(--transition); overflow-y: auto; overflow-x: hidden; }
        .sidebar-nav { padding: 50px 0 20px 0; }
        .sidebar-nav .nav-link { color: rgba(255, 255, 255, 0.7); font-size: 1.05rem; font-weight: 500; padding: 15px 25px; transition: var(--transition); border-left: 5px solid transparent; margin: 2px 0; display: flex; align-items: center; text-decoration: none; }
        .sidebar-nav .nav-link i { width: 30px; text-align: center; flex-shrink: 0; font-size: 0.95em; }
        .sidebar-nav .nav-link:hover { color: white; background: rgba(255, 255, 255, 0.05); border-left-color: #1abc9c; }
        .sidebar-nav .nav-link.active { color: white; background: rgba(255, 255, 255, 0.1); border-left-color: #3498db; font-weight: 600; }
        .main-content { flex: 1 0 auto; padding: 30px; margin-top: var(--header-height); margin-left: var(--sidebar-width); transition: margin-left var(--transition); min-height: calc(100vh - var(--header-height)); }
                footer {
            flex-shrink: 0;
            background: #2c3e50 !important;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            padding-left: var(--sidebar-width); /* <-- MODIFIED */
            transition: padding-left var(--transition); /* <-- MODIFIED */
            position: relative;
            z-index: 1041;
        }
                .sidebar.minimized ~ footer {
            padding-left: var(--sidebar-min-width); /* <-- MODIFIED */
        }

        /* --- UI FIX: Added Toggle Styles --- */
        .sidebar.toggled {
            width: var(--sidebar-min-width);
        }
        .sidebar.toggled .nav-link span,
        .sidebar.toggled .sidebar-chevron,
        .sidebar.toggled .sub-menu {
            display: none;
        }
        .sidebar.toggled .nav-link {
            justify-content: center;
        }
        .sidebar.toggled .nav-link i {
            margin-right: 0;
        }
        .main-content.toggled,
        footer.toggled {
            margin-left: var(--sidebar-min-width);
        }
        /* Mobile responsive toggle */
        @media (max-width: 991.98px) {
            .sidebar {
                width: 0;
                left: -50px; /* Hide completely */
            }
            .sidebar.mobile-show {
                width: var(--sidebar-width);
                left: 0;
            }
            .main-content, footer {
                margin-left: 0;
            }
        }
        /* --- End Fix --- */
    </style>
</head>
<body>
    
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="admin_dashboard.php">
                <img src="imageslogo.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white brand-heading" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light brand-subheading" style="font-size: 0.75rem;">Administrator Panel</small>
                </div>
            </a>
            <button class="navbar-toggler d-lg-none" type="button" id="mobileMenuToggle" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="dropdown user-dropdown ms-auto me-2 me-lg-0">
                <a href="#" class="dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle navbar-profile-icon"></i>
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="admin_profile.php"><i class="fas fa-user-circle"></i> Profile</a></li>
                    <li><a class="dropdown-item" href="Tournament_Manager_page.php" target="_blank"><i class="fas fa-globe"></i> View Public Site</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="sidebar" id="sidebar"> <button id="sidebarToggle" title="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
        
        <ul class="nav flex-column sidebar-nav">
            <li class="nav-item">
                <a class="nav-link <?php if ($current_page == 'admin_dashboard.php') echo 'active'; ?>" href="admin_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link <?php if ($is_management_page) echo 'active'; ?>" data-bs-toggle="collapse" href="#teamsCollapse" role="button" aria-expanded="<?php echo $is_management_page ? 'true' : 'false'; ?>" aria-controls="teamsCollapse">
                    <i class="fas fa-users me-2"></i> <span>Manage Colleges/Events</span> <i class="fas fa-chevron-down ms-auto sidebar-chevron"></i>
                </a>
                
                <div class="collapse <?php if ($is_management_page) echo 'show'; ?>" id="teamsCollapse">
                    <ul class="sub-menu">
                        
                        <li class="text-muted" style="padding: 10px 25px 5px 60px; margin-top: 5px; font-size: 0.75rem; font-weight: 600; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px;">
                            Management
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'colleges.php') echo 'active'; ?>" href="sd/colleges.php">
                                <span>Manage Colleges</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'events.php') echo 'active'; ?>" href="sd/events.php">
                                <span>Manage Events (L1-L3)</span>
                            </a>
                        </li>
                        
                        <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'Manage_Matches.php') echo 'active'; ?>" href="sd/Manage_Matches.php">
                                <span>Manage Matches</span>
                            </a>
                        </li>
                        
                        <li class="text-muted" style="padding: 10px 25px 5px 60px; margin-top: 10px; font-size: 0.75rem; font-weight: 600; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px;">
                            Tallying
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'results.php') echo 'active'; ?>" href="sd/results.php">
                                <span>Approve Results</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'reports.php') echo 'active'; ?>" href="sd/reports.php">
                                <span>Medal Reports</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php if ($current_page == 'Manage_Users.php') echo 'active'; ?>" href="Manage_Users.php">
                    <i class="fas fa-users-cog me-2"></i> <span>Manage Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php if ($current_page == 'Manage_medals.php') echo 'active'; ?>" href="Manage_medals.php">
                    <i class="fas fa-medal me-2"></i> <span>Manage Medals</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php if ($current_page == 'Manage_Requests.php') echo 'active'; ?>" href="Manage_Requests.php">
                    <i class="fas fa-user-plus me-2"></i> <span>Account Requests</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php if ($current_page == 'Manage_Viewreports.php') echo 'active'; ?>" href="Manage_Viewreports.php">
                    <i class="fas fa-chart-line me-2"></i> <span>View Reports</span>
                </a>
            </li>
            
            <li class="nav-item mt-3">
                <a class="nav-link text-danger" href="login.php">
                    <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
    
    <div class="main-content" id="mainContent">
        <div class="container-fluid">
            
            <h1 class="section-title mb-4">Manage Account Requests</h1>
            
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Pending Requests</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Full Name</th>
                                    <th>Email (Username)</th>
                                    <th>Role</th>
                                    <th>Date Requested</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_requests as $request): ?>
                                <tr>
                                    <td><?= htmlspecialchars($request['full_name']) ?></td>
                                    <td><?= htmlspecialchars($request['email']) ?></td>
                                    <td><span class="badge bg-info text-dark"><?= htmlspecialchars($request['role']) ?></span></td>
                                    <td><?= date('M d, Y h:i A', strtotime($request['created_at'])) ?></td>
                                    <td>
                                        <a href="Manage_Requests.php?action=approve&id=<?= $request['id'] ?>" class="btn btn-sm btn-success btn-action" title="Approve" onclick="return confirm('Are you sure you want to approve this user account?')">
                                            <i class="fas fa-check"></i> Approve
                                        </a>
                                        <a href="Manage_Requests.php?action=reject&id=<?= $request['id'] ?>" class="btn btn-sm btn-danger btn-action" title="Reject" onclick="return confirm('Are you sure you want to REJECT and DELETE this user request?')">
                                            <i class="fas fa-times"></i> Reject
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($pending_requests)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted">No pending requests.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Active Users (Last 20 Approved)</h5>
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
                                        <span class="badge bg-success">Active</span>
                                        
                                        <a href="Manage_Requests.php?action=reset&id=<?= $request['id'] ?>" 
                                           class="btn btn-sm btn-warning ms-2" 
                                           title="Reset Password" 
                                           onclick="return confirm('Are you sure you want to reset the password for this user? A new random password will be generated.')">
                                            <i class="fas fa-key"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($processed_requests)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted">No active users found.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <footer class="bg-dark text-white py-4" id="footer">
        </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- Declarations ---
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const footer = document.getElementById('footer');
            const navbar = document.querySelector('.navbar'); // Added navbar selector
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');

            // --- Desktop/Tablet Toggle ---
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('toggled');
                    mainContent.classList.toggle('toggled');
                    footer.classList.toggle('toggled');
                });
            }

            // --- Mobile Toggle ---
            if (mobileMenuToggle) {
                mobileMenuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('mobile-show');
                });
            }

            // --- Mobile Resize Logic (Unchanged) ---
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth > 992) {
                        sidebar.classList.remove('show');
                        // You were missing sidebarOverlay, I've removed the line
                    }
                }, 250);
            });

            // --- FIX SIDEBAR/FOOTER OVERLAP (Cleaned up) ---
            if (sidebar && footer && navbar) {
                
                function adjustSidebarHeight() {
                    // This logic should only apply to desktop view
                    if (window.innerWidth <= 992) {
                        sidebar.style.height = ''; // Reset to CSS default for mobile
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
        });
    </script>
</body>
</html>