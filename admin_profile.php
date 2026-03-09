<?php
session_start();
require_once 'config.php'; 
require_once 'profile_email_sender.php'; 

// Strict Role-Based Access Control
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role'])) {
    header('Location: login.php');
    exit();
}

// Ensure user_id is in session
if (!isset($_SESSION['user_id'])) {
    die("Error: User ID not found in session. Please log in again.");
}

$user_id = $_SESSION['user_id'];
$current_page = basename($_SERVER['PHP_SELF']);
$message = '';
$message_type = '';

// --- FETCH USER DATA (For Full Name) ---
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

// Display Name Logic
$name = !empty($user['full_name']) ? $user['full_name'] : $user['username'];

// --- FETCH STATS FOR SIDEBAR BADGES (Only needed for Sports Director) ---
function fetchCount($conn, $query) {
    $result = $conn->query($query);
    return ($result) ? $result->fetch_row()[0] : 0;
}

$stats = [
    'pending_requests' => 0,
    'pending_results' => 0
];

// Only fetch stats if user is Sports Director to save performance
if ($_SESSION['role'] !== 'Tournament Manager') {
    $stats['pending_requests'] = fetchCount($conn, "SELECT COUNT(*) FROM account_requests WHERE status = 'pending'");
    $stats['pending_results']  = fetchCount($conn, "SELECT COUNT(*) FROM categories WHERE status='Results Submitted'");
}

// Session Message
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// --- UPDATE LOGIC ---
if (isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);

    $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $stmt_check->bind_param("si", $username, $user_id);
    $stmt_check->execute();
    $stmt_check->store_result();
    
    if ($stmt_check->num_rows > 0) {
        $message = "Error: Username already taken.";
        $message_type = 'danger';
    } else {
        $stmt_check->close();
        $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ? WHERE id = ?");
        $stmt->bind_param("ssi", $full_name, $username, $user_id);
        
        if ($stmt->execute()) {
            $message = "Profile updated successfully.";
            $message_type = 'success';
            $_SESSION['username'] = $username;
            $name = $full_name; 
            $user['full_name'] = $full_name;
            $user['username'] = $username;
        } else {
            $message = "Error updating profile: " . $stmt->error;
            $message_type = 'danger';
        }
        $stmt->close();
    }
}

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
                $message = "Error changing password.";
                $message_type = 'danger';
            }
            $stmt_update->close();
        } else {
            $message = "Error: Incorrect current password.";
            $message_type = 'danger';
        }
        $stmt_get->close();
    }
}

if (isset($_POST['change_email'])) {
    $new_email = trim($_POST['new_email']);
    $current_password = $_POST['current_password_for_email'];

    $stmt_get = $conn->prepare("SELECT password, full_name FROM users WHERE id = ?");
    $stmt_get->bind_param("i", $user_id);
    $stmt_get->execute();
    $result = $stmt_get->get_result();
    $user_db = $result->fetch_assoc();
    $stmt_get->close();

    if (!$user_db || !password_verify($current_password, $user_db['password'])) {
        $message = "Error: Incorrect password.";
        $message_type = 'danger';
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $message = "Error: Invalid email format.";
        $message_type = 'danger';
    } else {
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt_check->bind_param("si", $new_email, $user_id);
        $stmt_check->execute();
        $stmt_check->store_result();
        
        if ($stmt_check->num_rows > 0) {
            $message = "Error: Email already in use.";
            $message_type = 'danger';
        } else {
            $token = bin2hex(random_bytes(32));
            $expiry = time() + (15 * 60); 
            $stmt_save_token = $conn->prepare("UPDATE users SET new_email = ?, verification_token = ?, token_expiry = ? WHERE id = ?");
            $stmt_save_token->bind_param("ssii", $new_email, $token, $expiry, $user_id);
            if ($stmt_save_token->execute()) {
                $email_result = sendVerificationEmail($new_email, $token, $user_db['full_name']);
                if ($email_result === true) {
                    $message = "Verification link sent to " . htmlspecialchars($new_email);
                    $message_type = 'success';
                } else {
                    $message = "Email failed: " . $email_result; 
                    $message_type = 'danger';
                }
            }
            $stmt_save_token->close();
        }
        $stmt_check->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - PIT Sports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* --- Unified Theme --- */
        :root { 
            --sidebar-width: 260px; 
            --header-height: 82px; 
            --transition: all 0.3s ease; 
            --card-shadow: 0 2px 12px rgba(0, 0, 0, 0.06); 
            --bg-light: #F8F9FA; 
            --primary-gradient: linear-gradient(135deg, #2c3e50 0%, #4ca1af 100%);
            --accent-color: #1abc9c;
            --label-color: #6c757d;
        }
        body { background-color: var(--bg-light); margin: 0; padding: 0; min-height: 100vh; font-family: 'Inter', sans-serif; display: flex; flex-direction: column; }
        
        /* Navbar */
        .navbar { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.15); padding: 1rem 1.5rem; height: var(--header-height); position: fixed; top: 0; left: 0; right: 0; z-index: 1050; }
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; transition: var(--transition); }
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
        .user-dropdown .dropdown-toggle img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; margin-right: 10px; border: 2px solid rgba(255,255,255,0.2); }
        
        /* Sidebar */
        .sidebar { width: var(--sidebar-width); position: fixed; top: var(--header-height); left: 0; height: calc(100vh - var(--header-height)); background: #2c3e50; color: white; box-shadow: 5px 0 15px rgba(0,0,0,0.2); z-index: 1040; transition: width var(--transition); overflow-y: auto; }
        .sidebar-nav { padding: 20px 0; }
        .sidebar-nav .nav-link { color: rgba(255, 255, 255, 0.7); font-size: 1.05rem; font-weight: 500; padding: 12px 25px; transition: var(--transition); border-left: 5px solid transparent; margin: 2px 0; display: flex; align-items: center; text-decoration: none; }
        .sidebar-nav .nav-link i { width: 30px; text-align: center; flex-shrink: 0; font-size: 0.95em; }
        .sidebar-nav .nav-link:hover { color: white; background: rgba(255, 255, 255, 0.05); border-left-color: var(--accent-color); }
        .sidebar-nav .nav-link.active { color: white; background: rgba(255, 255, 255, 0.1); border-left-color: #3498db; font-weight: 600; }
        .sidebar-nav .nav-title { padding: 15px 25px 5px; font-size: 0.75rem; font-weight: 700; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px; }

        .main-content { flex: 1 0 auto; padding: 30px; margin-top: var(--header-height); margin-left: var(--sidebar-width); transition: margin-left var(--transition); min-height: calc(100vh - var(--header-height)); }
        
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
        /* Layout & Forms */
        .card { border: none; border-radius: 12px; box-shadow: var(--card-shadow); transition: transform 0.2s; }
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; margin-bottom: 20px; }
        
        .form-label-small { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--label-color); margin-bottom: 0.4rem; }
        .form-control { padding: 0.6rem 1rem; font-size: 0.95rem; border-color: #dee2e6; border-radius: 8px; }
        .form-control:focus { border-color: #3498db; box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.15); }
        .form-control:disabled, .form-control[readonly] { background-color: #f8f9fa; color: #6c757d; opacity: 1; }

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
            <a class="navbar-brand d-flex align-items-center" href="<?php
                if ($_SESSION['role'] === 'Tournament Manager') echo 'tournamentmanager_dashboard.php';
                else echo 'sd/sports_director_dashboard.php';
            ?>">
                <img src="images/PIT.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light" style="font-size: 0.75rem;"><?php echo htmlspecialchars($_SESSION['role']); ?> Panel</small>
                </div>
            </a>
            <button class="navbar-toggler d-lg-none" type="button" id="mobileToggle">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="dropdown user-dropdown ms-auto me-2 me-lg-0">
                <a href="#" class="dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle" style="font-size: 36px; margin-right: 10px;"></i>
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item active" href="admin_profile.php"><i class="fas fa-user-circle me-2"></i> Profile</a></li>
                    <li><a class="dropdown-item" href="Tournament_Manager_page.php" target="_blank"><i class="fas fa-globe me-2"></i> Public Site</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="sidebar" id="sidebar">
        <?php if ($_SESSION['role'] === 'Tournament Manager'): ?>
            <!-- === TOURNAMENT MANAGER SIDEBAR === -->
            <ul class="nav flex-column sidebar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="tournamentmanager_dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item mt-3"><span class="nav-title">Shortcuts</span></li>
                <li class="nav-item">
                    <a class="nav-link" href="my_events.php">
                        <i class="fas fa-trophy me-2"></i> <span>My Assigned Events</span>
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

        <?php else: ?>
            <!-- === SPORTS DIRECTOR SIDEBAR (Your Custom Layout) === -->
            <ul class="nav flex-column sidebar-nav">
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == 'sports_director_dashboard.php') ? 'active' : '' ?>" href="sd/sports_director_dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                    </a>
                </li>
                
                <li class="nav-item mt-3"><span class="nav-title">Tournament Mgmt</span></li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == 'colleges.php') ? 'active' : '' ?>" href="sd/colleges.php">
                        <i class="fas fa-users me-2"></i> <span>Manage Teams</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == 'events.php') ? 'active' : '' ?>" href="sd/events.php">
                        <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events (L1-L3)</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == 'Manage_Matches.php') ? 'active' : '' ?>" href="sd/Manage_Matches.php">
                        <i class="fas fa-trophy me-2"></i> <span>Manage Matches</span>
                    </a>
                </li>

                <li class="nav-item mt-3"><span class="nav-title">Administration</span></li>
                <li class="nav-item">
                    <a class="nav-link" href="Manage_Users.php">
                        <i class="fas fa-users-cog me-2"></i> <span>Manage Users</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="Manage_Requests.php">
                        <i class="fas fa-user-plus me-2"></i> <span>Account Requests</span>
                        <?php if(isset($stats['pending_requests']) && $stats['pending_requests'] > 0): ?>
                            <span class="badge bg-danger ms-auto rounded-pill"><?= $stats['pending_requests'] ?></span>
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
                    <a class="nav-link <?= ($current_page == 'results.php') ? 'active' : '' ?>" href="sd/results.php">
                        <i class="fas fa-check-double me-2"></i> <span>Approve Results</span>
                        <?php if(isset($stats['pending_results']) && $stats['pending_results'] > 0): ?>
                            <span class="badge bg-warning text-dark ms-auto rounded-pill"><?= $stats['pending_results'] ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == 'reports.php') ? 'active' : '' ?>" href="sd/reports.php">
                        <i class="fas fa-chart-line me-2"></i> <span>Medal Standings</span>
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

    <div class="main-content">
        <div class="container-fluid">
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="section-title mb-0">My Profile</h1>
                <span class="badge bg-light text-dark border px-3 py-2 shadow-sm"><?= htmlspecialchars($user['role']) ?></span>
            </div>

            <?php if ($message): ?>
            <div class="alert alert-<?php echo ($message_type == 'danger' ? 'danger' : 'success'); ?> alert-dismissible fade show shadow-sm" role="alert">
                <?php if($message_type == 'success'): ?><i class="fas fa-check-circle me-2"></i><?php else: ?><i class="fas fa-exclamation-circle me-2"></i><?php endif; ?>
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="row g-4">
                <!-- Edit Information (Optimized Layout) -->
                <div class="col-lg-8">
                    <div class="card h-100">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center mb-4">
                                <div class="bg-primary bg-opacity-10 p-3 rounded-circle me-3 text-primary">
                                    <i class="fas fa-user-edit fa-lg"></i>
                                </div>
                                <h5 class="card-title mb-0 fw-bold text-dark">Edit Information</h5>
                            </div>
                            
                            <form action="admin_profile.php" method="POST">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="full_name" class="form-label-small">Full Name</label>
                                        <input type="text" class="form-control" id="full_name" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="username" class="form-label-small">Username</label>
                                        <input type="text" class="form-control" id="username" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label for="role" class="form-label-small">System Role</label>
                                    <input type="text" class="form-control" id="role" value="<?= htmlspecialchars($user['role']) ?>" readonly disabled>
                                    <div class="form-text small text-muted mt-1"><i class="fas fa-info-circle me-1"></i> Your role determines your system access privileges.</div>
                                </div>

                                <div class="d-flex justify-content-end">
                                    <button type="submit" name="update_profile" class="btn btn-primary px-4 fw-medium">
                                        <i class="fas fa-save me-2"></i>Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Security Settings -->
                <div class="col-lg-4">
                    <div class="card mb-4">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center mb-3">
                                <div class="bg-warning bg-opacity-10 p-2 rounded-circle me-3 text-warning">
                                    <i class="fas fa-lock"></i>
                                </div>
                                <h6 class="card-title mb-0 fw-bold">Security</h6>
                            </div>
                            <form action="admin_profile.php" method="POST">
                                <div class="mb-2">
                                    <label for="current_password" class="form-label-small">Current</label>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                </div>
                                <div class="mb-2">
                                    <label for="new_password" class="form-label-small">New Password</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required>
                                </div>
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label-small">Confirm</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                </div>
                                <button type="submit" name="change_password" class="btn btn-warning text-dark w-100 btn-sm fw-medium">Update Password</button>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body p-4">
                             <div class="d-flex align-items-center mb-3">
                                <div class="bg-info bg-opacity-10 p-2 rounded-circle me-3 text-info">
                                    <i class="fas fa-envelope"></i>
                                </div>
                                <h6 class="card-title mb-0 fw-bold">Email</h6>
                            </div>
                            <form action="admin_profile.php" method="POST">
                                <div class="mb-2">
                                    <label for="new_email" class="form-label-small">New Email</label>
                                    <input type="email" class="form-control" id="new_email" name="new_email" required>
                                </div>
                                <div class="mb-3">
                                    <label for="current_password_for_email" class="form-label-small">Password to Confirm</label>
                                    <input type="password" class="form-control" id="current_password_for_email" name="current_password_for_email" required>
                                </div>
                                <button type="submit" name="change_email" class="btn btn-info text-white w-100 btn-sm fw-medium">Update Email</button>
                            </form>
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
                        <img src="images/PIT.png" alt="Logo">
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
            const sidebar = document.getElementById('sidebar');
            const mobileToggle = document.getElementById('mobileToggle');
            const footer = document.querySelector('footer');
            const navbar = document.querySelector('.navbar');

            if (mobileToggle) {
                mobileToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                });
            }

            document.addEventListener('click', function(event) {
                if (window.innerWidth <= 992 && sidebar.classList.contains('show')) {
                    if (!sidebar.contains(event.target) && !mobileToggle.contains(event.target)) {
                        sidebar.classList.remove('show');
                    }
                }
            });

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
        });
    </script>
</body>
</html>