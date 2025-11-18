<?php
session_start();
require_once 'db_connect.php'; // Your MySQLi connection
require_once 'profile_email_sender.php'; // Include the new email function

// Strict Role-Based Access Control
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role'])) {
    header('Location: login.php');
    exit();
}

// Ensure user_id is in session
if (!isset($_SESSION['user_id'])) {
    // Note: Your 'users' table uses 'id', but session might use 'user_id'. 
    // We'll assume the session key is 'user_id' as per your file.
    die("Error: User ID not found in session. Please log in again.");
}

$user_id = $_SESSION['user_id'];
$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'User';
$current_page = basename($_SERVER['PHP_SELF']);
$message = '';
$message_type = '';

// Check for session messages (from this page or verify_new_email.php)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Logic to keep accordion open (for Admin sidebar)
$event_pages = ['Manage_Games.php', 'Manage_Game_Events.php', 'Manage_Categories.php'];
$is_event_page = in_array($current_page, $event_pages);
$management_pages = ['teams.php', 'events.php', 'results.php', 'reports.php'];
$is_management_page = in_array($current_page, $management_pages);


// --- ACTION LOGIC ---
// ... (All your PHP logic for update_profile, change_password, change_email is correct and unchanged) ...
// 1. UPDATE PROFILE DETAILS
if (isset($_POST['update_profile'])) {
    $full_name = $_POST['full_name'];
    $username = $_POST['username'];

    $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $stmt_check->bind_param("si", $username, $user_id);
    $stmt_check->execute();
    $stmt_check->store_result();
    
    if ($stmt_check->num_rows > 0) {
        $message = "Error: That username is already taken by another user.";
        $message_type = 'danger';
    } else {
        $stmt_check->close();
        
        $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ? WHERE id = ?");
        $stmt->bind_param("ssi", $full_name, $username, $user_id);
        
        if ($stmt->execute()) {
            $message = "Profile updated successfully.";
            $message_type = 'success';
            $_SESSION['username'] = $username;
            $name = $username; 
        } else {
            $message = "Error updating profile: " . $stmt->error;
            $message_type = 'danger';
        }
        $stmt->close();
    }
}

// 2. CHANGE PASSWORD
if (isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if ($new_password !== $confirm_password) {
        $message = "Error: New passwords do not match.";
        $message_type = 'danger';
    } else {
        $stmt_get = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt_get->bind_param("i", $user_id);
        $stmt_get->execute();
        $result = $stmt_get->get_result();
        $user_db = $result->fetch_assoc();
        
        if ($user_db && password_verify($current_password, $user_db['password'])) {
            $new_hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            $stmt_update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt_update->bind_param("si", $new_hashed_password, $user_id);
            
            if ($stmt_update->execute()) {
                $message = "Password changed successfully.";
                $message_type = 'success';
            } else {
                $message = "Error changing password: " . $stmt_update->error;
                $message_type = 'danger';
            }
            $stmt_update->close();
        } else {
            $message = "Error: Your current password was incorrect.";
            $message_type = 'danger';
        }
        $stmt_get->close();
    }
}

// 3. *** MODIFIED: CHANGE EMAIL (Saves to DB) ***
if (isset($_POST['change_email'])) {
    $new_email = trim($_POST['new_email']);
    $current_password = $_POST['current_password_for_email'];

    // Get user's current password and name for verification
    $stmt_get = $conn->prepare("SELECT password, full_name FROM users WHERE id = ?");
    $stmt_get->bind_param("i", $user_id);
    $stmt_get->execute();
    $result = $stmt_get->get_result();
    $user_db = $result->fetch_assoc();
    $stmt_get->close();

    // 3a. Verify current password
    if (!$user_db || !password_verify($current_password, $user_db['password'])) {
        $message = "Error: Your current password was incorrect.";
        $message_type = 'danger';
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $message = "Error: Invalid email format.";
        $message_type = 'danger';
    } else {
        // 3b. Check if new email is already used by ANOTHER user
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt_check->bind_param("si", $new_email, $user_id);
        $stmt_check->execute();
        $stmt_check->store_result();
        
        if ($stmt_check->num_rows > 0) {
            $message = "Error: That email address is already in use by another account.";
            $message_type = 'danger';
        } else {
            // 3c. All checks passed. Generate token and send email.
            $token = bin2hex(random_bytes(32));
            $expiry = time() + (15 * 60); // 15 minutes

            // *** NEW: Store token in the database ***
            $stmt_save_token = $conn->prepare("UPDATE users SET new_email = ?, verification_token = ?, token_expiry = ? WHERE id = ?");
            $stmt_save_token->bind_param("ssii", $new_email, $token, $expiry, $user_id);
            
            if ($stmt_save_token->execute()) {
                // 3d. Send the verification email
                $email_result = sendVerificationEmail($new_email, $token, $user_db['full_name']);

                if ($email_result === true) {
                    $message = "A verification link has been sent to " . htmlspecialchars($new_email) . ". Please check your inbox.";
                    $message_type = 'success';
                } else {
                    // Email sending failed
                    $message = $email_result; // Show the PHPMailer error
                    $message_type = 'danger';
                }
            } else {
                $message = "Error saving token to database: " . $conn->error;
                $message_type = 'danger';
            }
            $stmt_save_token->close();
        }
        $stmt_check->close();
    }
}


// --- FETCH CURRENT USER DATA (READ) ---
$stmt = $conn->prepare("SELECT full_name, username, role, email FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #4CAF50;
            --primary-dark: #2E7D32;
            --accent-gold: #FFD700;
            --bg-light: #F8F9FA;
            --text-dark: #1A1A1A;
            --text-muted: #6C757D;
            --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --primary-gradient: linear-gradient(135deg, #7451eb 0%, #3498db 100%);
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            --card-hover-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            --sidebar-width: 260px; /* Full Width */
            --sidebar-min-width: 80px; /* Minimized Width */
            --header-height: 82px;  
        }
        body {
            background-color: var(--bg-light);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            display: flex;
            flex-direction: column;
        }
        .navbar {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            padding: 1rem 1.5rem;
            height: var(--header-height);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1050;
        }
        .navbar-brand { transition: var(--transition); }
        .navbar-brand:hover { transform: translateY(-2px); }
        .brand-logo { filter: drop-shadow(0 2px 4px rgba(255,255,255,0.1)); }
        .brand-heading { font-family: 'Poppins', sans-serif; font-weight: 700; letter-spacing: -0.5px; }
        
        /* User Dropdown */
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; transition: var(--transition); }
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
        .user-dropdown .dropdown-toggle img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,0.3); margin-right: 10px; }
        .user-dropdown .dropdown-toggle .user-name { font-weight: 600; font-size: 0.95rem; }
        .user-dropdown .dropdown-menu { border: none; box-shadow: var(--shadow-md); border-radius: 10px; padding: 0.5rem 0; margin-top: 10px !important; }
        .user-dropdown .dropdown-item { display: flex; align-items: center; padding: 0.75rem 1.25rem; font-weight: 500; color: #333; font-size: 0.9rem; }
        .user-dropdown .dropdown-item i { width: 20px; margin-right: 10px; color: var(--text-muted); }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            position: fixed;
            top: var(--header-height);
            left: 0;
            height: calc(100vh - var(--header-height));
            background: #2c3e50;
            color: white;
            box-shadow: 5px 0 15px rgba(0,0,0,0.2);
            z-index: 1040;
            transition: width var(--transition);
            overflow-y: auto;
            overflow-x: hidden;
        }
        .sidebar.minimized { width: var(--sidebar-min-width); }
        #sidebarToggle {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            font-size: 1.25rem;
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 5px;
            transition: all 0.3s ease;
            z-index: 10;
        }
        #sidebarToggle:hover { background: rgba(255, 255, 255, 0.2); transform: scale(1.05); }
        .sidebar-nav { padding: 50px 0 20px 0; }
        .sidebar-nav .nav-link {
            color: rgba(255, 255, 255, 0.7);
            font-size: 1.05rem;
            font-weight: 500;
            padding: 15px 25px;
            transition: var(--transition);
            border-left: 5px solid transparent;
            margin: 2px 0;
            display: flex;
            align-items: center;
            text-decoration: none;
        }
        .sidebar-nav .nav-link i { width: 30px; text-align: center; flex-shrink: 0; font-size: 0.95em; }
        .sidebar.minimized .sidebar-nav .nav-link span,
        .sidebar.minimized .sidebar-nav .sidebar-chevron,
        .sidebar.minimized .sidebar-nav .text-muted {
            display: none;
        }
        .sidebar.minimized .sidebar-nav .nav-link { justify-content: center; padding: 15px 0; }
        .sidebar.minimized #sidebarToggle { right: 50%; transform: translateX(50%); }
        .sidebar-nav .nav-link:hover { color: white; background: rgba(255, 255, 255, 0.05); border-left-color: #1abc9c; }
        .sidebar-nav .nav-link.active { color: white; background: rgba(255, 255, 255, 0.1); border-left-color: #3498db; font-weight: 600; }
        .sidebar-nav .text-muted { padding: 10px 25px; font-size: 0.75rem; font-weight: 600; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px; }

        /* Accordion CSS */
        .sidebar-nav .nav-link .sidebar-chevron {
            font-size: 0.7rem;
            margin-left: auto;
            transition: transform 0.3s ease;
        }
        .sidebar-nav .nav-link[aria-expanded="true"] .sidebar-chevron {
            transform: rotate(180deg);
        }
        .sidebar-nav .nav-link[aria-expanded="true"] {
            color: white;
            background: rgba(255, 255, 255, 0.05);
        }
        .sidebar-nav .sub-menu {
            padding-left: 0;
            margin: 0;
            list-style: none;
            background-color: rgba(0,0,0,0.15);
        }
        .sidebar-nav .sub-menu .nav-item {
            width: 100%;
        }
        .sidebar-nav .sub-menu .nav-link {
            padding: 12px 25px 12px 60px;
            font-size: 0.95rem;
            font-weight: 400;
            border-left: 5px solid transparent;
            margin: 0;
        }
        .sidebar-nav .sub-menu .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: #1abc9c;
        }
        .sidebar-nav .sub-menu .nav-link.active {
            color: #1abc9c;
            border-left-color: #1abc9c;
            background-color: rgba(0,0,0,0.1);
            font-weight: 500;
        }
        
        /* Main Content */
        .main-content {
            flex: 1 0 auto;
            padding: 30px;
            margin-top: var(--header-height);
            margin-left: var(--sidebar-width);
            transition: margin-left var(--transition), opacity 0.5s ease-out, transform 0.5s ease-out;
            min-height: calc(100vh - var(--header-height));
            opacity: 0;
            transform: translateY(10px);
        }
        .sidebar.minimized ~ .main-content { margin-left: var(--sidebar-min-width); }
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        .card { border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        
        /* Footer */
        footer { flex-shrink: 0; background: #2c3e50 !important; box-shadow: 0 -2px 10px rgba(0,0,0,0.1); margin-left: var(--sidebar-width); transition: margin-left var(--transition); position: relative; z-index: 1041; }
        .sidebar.minimized ~ footer { margin-left: var(--sidebar-min-width); }
        
        /* Mobile */
        .sidebar-overlay { display: none; position: fixed; top: var(--header-height); left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1030; }
        .sidebar-overlay.show { display: block; }
        @media (max-width: 992px) {
            .sidebar { width: 260px; left: -260px; top: var(--header-height); height: calc(100vh - var(--header-height)); transition: left 0.3s ease; z-index: 1045; }
            .sidebar.show { left: 0; }
            .main-content { margin-left: 0; padding: 20px; }
            .sidebar.minimized ~ .main-content { margin-left: 0; }
            #sidebarToggle { display: none; }
            footer { margin-left: 0; }
            .sidebar.minimized ~ footer { margin-left: 0; }
        }
        @media (max-width: 576px) {
            .main-content { padding: 15px; }
            .user-dropdown .dropdown-toggle .user-name { display: none; }
            .user-dropdown .dropdown-toggle img { margin-right: 0; }
        }
    </style>
</head>
<body>
    
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="<?php
                if ($_SESSION['role'] === 'Administrator') echo 'admin_dashboard.php';
                elseif ($_SESSION['role'] === 'Event Manager') echo 'event_manager_dashboard.php';
                elseif ($_SESSION['role'] === 'Sports Director') echo 'sd/sports_director_dashboard.php';
                else echo 'login.php';
            ?>" style="cursor: pointer;">
                <img src="imageslogo.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white brand-heading" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light brand-subheading" style="font-size: 0.75rem;"><?php echo htmlspecialchars($_SESSION['role']); ?> Panel</small>
                </div>
            </a>
            <button class="navbar-toggler d-lg-none" type="button" id="mobileMenuToggle" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="dropdown user-dropdown ms-auto me-2 me-lg-0">
                <a href="#" class="dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle" 
                       style="width: 36px; height: 36px; font-size: 36px; text-align: center; line-height: 1; border-radius: 50%; margin-right: 10px; color: rgba(255,255,255,0.8);"></i>
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item active" href="admin_profile.php"><i class="fas fa-user-circle"></i> Profile</a></li>
                    <li><a class="dropdown-item" href="Tournament_Manager_page.php" target="_blank"><i class="fas fa-globe"></i> View Public Site</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="sidebar" id="sidebar">
        <button id="sidebarToggle" title="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
        
        <?php if ($_SESSION['role'] === 'Administrator'): ?>
            <ul class="nav flex-column sidebar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="admin_dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($is_event_page) echo 'active'; ?>" data-bs-toggle="collapse" href="#eventsCollapse" role="button" aria-expanded="<?php echo $is_event_page ? 'true' : 'false'; ?>" aria-controls="eventsCollapse">
                        <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events</span> <i class="fas fa-chevron-down ms-auto sidebar-chevron"></i>
                    </a>
                    <div class="collapse <?php if ($is_event_page) echo 'show'; ?>" id="eventsCollapse">
                        <ul class="sub-menu">
                            <li class="nav-item"><a class="nav-link" href="Manage_Games.php"><span>Games (L1)</span></a></li>
                            <li class="nav-item"><a class="nav-link" href="Manage_Game_Events.php"><span>Game Events (L2)</span></a></li>
                            <li class="nav-item"><a class="nav-link" href="Manage_Categories.php"><span>Categories (L3)</span></a></li>
                        </ul>
                    </div>
                </li>
                 <li class="nav-item">
                    <a class="nav-link <?php if ($is_management_page) echo 'active'; ?>" data-bs-toggle="collapse" href="#teamsCollapse" role="button" aria-expanded="<?php echo $is_management_page ? 'true' : 'false'; ?>" aria-controls="teamsCollapse">
                        <i class="fas fa-users me-2"></i> <span>Manage Teams</span> <i class="fas fa-chevron-down ms-auto sidebar-chevron"></i>
                    </a>
                    <div class="collapse <?php if ($is_management_page) echo 'show'; ?>" id="teamsCollapse">
                        <ul class="sub-menu">
                            <li class="text-muted" style="padding: 10px 25px 5px 60px;">Management</li>
                            <li><a class="nav-link" href="sd/teams.php"><span>Manage Teams</span></a></li>
                            <li><a class="nav-link" href="sd/events.php"><span>Manage Events (L1-L3)</span></a></li>
                            <li class="text-muted" style="padding: 10px 25px 5px 60px;">Tallying</li>
                            <li><a class="nav-link" href="sd/results.php"><span>Approve Results</span></a></li>
                            <li><a class="nav-link" href="sd/reports.php"><span>Medal Reports</span></a></li>
                        </ul>
                    </div>
                </li>
                <li class="nav-item"><a class="nav-link" href="Manage_Users.php"><i class="fas fa-users-cog me-2"></i> <span>Manage Users</span></a></li>
                <li class="nav-item"><a class="nav-link" href="Manage_medals.php"><i class="fas fa-medal me-2"></i> <span>Manage Medals</span></a></li>
                <li class="nav-item"><a class="nav-link" href="Manage_Requests.php"><i class="fas fa-user-plus me-2"></i> <span>Account Requests</span></a></li>
                <li class="nav-item"><a class="nav-link" href="Manage_Viewreports.php"><i class="fas fa-chart-line me-2"></i> <span>View Reports</span></a></li>
                <li class="nav-item mt-3"><a class="nav-link text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span></a></li>
            </ul>
        
        <?php elseif ($_SESSION['role'] === 'Event Manager'): ?>
            <ul class="nav flex-column sidebar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="event_manager_dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="Event.php" target="_blank">
                        <i class="fas fa-globe me-2"></i> <span>View Public Events</span>
                    </a>
                </li>
                <li class="nav-item mt-auto">
                    <a class="nav-link text-danger" href="logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
                    </a>
                </li>
            </ul>

        <?php elseif ($_SESSION['role'] === 'Sports Director'): ?>
            <ul class="nav flex-column sidebar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="sd/sports_director_dashboard.php"> 
                        <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item mt-3"><span class="nav-title">Management</span></li>
                <li class="nav-item">
                    <a class="nav-link" href="sd/teams.php">
                        <i class="fas fa-users me-2"></i> <span>Manage Teams</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="sd/events.php">
                        <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events (L1-L3)</span>
                    </a>
                </li>
                <li class="nav-item mt-3"><span class="nav-title">Tallying</span></li>
                <li class="nav-item">
                    <a class="nav-link" href="sd/results.php">
                        <i class="fas fa-check-double me-2"></i> <span>Approve Results</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="sd/reports.php">
                        <i class="fas fa-chart-line me-2"></i> <span>Medal Reports</span>
                    </a>
                </li>
                <li class="nav-item mt-auto">
                    <a class="nav-link text-danger" href="logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
                    </a>
                </li>
            </ul>
        <?php endif; ?>
    </div>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-content">
        <div class="container-fluid">
            
            <h1 class="section-title mb-4">My Profile</h1>

            <?php if ($message): ?>
            <div class="alert alert-<?php echo ($message_type == 'danger' ? 'danger' : 'success'); ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header"><h5 class="mb-0">Profile Information</h5></div>
                        <div class="card-body">
                            <form action="admin_profile.php" method="POST">
                                <div class="mb-3">
                                    <label for="full_name" class="form-label">Full Name</label>
                                    <input type="text" class="form-control" id="full_name" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="username" class="form-label">Username</label>
                                    <input type="text" class="form-control" id="username" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label for="role" class="form-label">Role</label>
                                    <input type="text" class="form-control" id="role" name="role" value="<?= htmlspecialchars($user['role']) ?>" readonly disabled>
                                </div>
                                <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card mb-4">
                        <div class="card-header"><h5 class="mb-0">Change Password</h5></div>
                        <div class="card-body">
                            <form action="admin_profile.php" method="POST">
                                <div class="mb-3">
                                    <label for="current_password" class="form-label">Current Password</label>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                </div>
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required>
                                </div>
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                </div>
                                <button type="submit" name="change_password" class="btn btn-warning">Change Password</button>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><h5 class="mb-0">Change Email</h5></div>
                        <div class="card-body">
                            <form action="admin_profile.php" method="POST">
                                <div class="mb-3">
                                    <label for="current_email" class="form-label">Current Email</Labe></label>
                                    <input type="email" class="form-control" id="current_email" name="current_email" value="<?= htmlspecialchars($user['email']) ?>" readonly disabled>
                                </div>
                                <div class="mb-3">
                                    <label for="new_email" class="form-label">New Email Address</label>
                                    <input type="email" class="form-control" id="new_email" name="new_email" required>
                                </div>
                                <div class="mb-3">
                                    <label for="current_password_for_email" class="form-label">Confirm with Current Password</label>
                                    <input type="password" class="form-control" id="current_password_for_email" name="current_password_for_email" required>
                                </div>
                                <button type="submit" name="change_email" class="btn btn-info">Send Verification Link</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <footer class="bg-dark text-white py-4">
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
            <small class="text-muted">Developed by Tsunayoshi Sawada</small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            
            // --- Smooth Fade-in Effect ---
            setTimeout(() => {
                const mainContent = document.querySelector('.main-content');
                if(mainContent) {
                    mainContent.style.opacity = '1';
                    mainContent.style.transform = 'translateY(0)';
                }
            }, 50);
            
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            
            // Desktop Sidebar Toggle
            if (window.innerWidth > 992 && sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('minimized');
                });
            }

            // Mobile Menu Toggle
            if (mobileMenuToggle) {
                mobileMenuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                    sidebarOverlay.classList.toggle('show');
                });
            }
            
            // Overlay Click - Close Sidebar
            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', function() {
                    sidebar.classList.remove('show');
                    sidebarOverlay.classList.remove('show');
                });
            }
            
            // Close sidebar when clicking a link on mobile
            document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
                if (link.getAttribute('data-bs-toggle') !== 'collapse') {
                    link.addEventListener('click', function() {
                        if (window.innerWidth <= 992) {
                            sidebar.classList.remove('show');
                            sidebarOverlay.classList.remove('show');
                        }
                    });
                }
            });

            // Handle Window Resize
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth > 992) {
                        sidebar.classList.remove('show');
                        sidebarOverlay.classList.remove('show');
                    }
                }, 250);
            });

        });
    </script>
</body>
</html>