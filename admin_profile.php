<?php
session_start();
require_once 'config.php'; 
require_once 'profile_email_sender.php'; 

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role'])) {
    header('Location: login.php');
    exit();
}

if (!isset($_SESSION['user_id'])) {
    die("Error: User ID not found in session. Please log in again.");
}

$user_id = $_SESSION['user_id'];
$current_page = basename($_SERVER['PHP_SELF']);
$message = '';
$message_type = '';

$stmt = $conn->prepare("SELECT full_name, username, role, email, profile_picture FROM users WHERE id = ?");
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

$name = !empty($user['full_name']) ? $user['full_name'] : $user['username'];

function fetchCount($conn, $query) {
    $result = $conn->query($query);
    return ($result) ? $result->fetch_row()[0] : 0;
}

$stats = [
    'pending_requests' => 0,
    'pending_results' => 0
];

if ($_SESSION['role'] !== 'Tournament Manager') {
    $stats['pending_requests'] = fetchCount($conn, "SELECT COUNT(*) FROM account_requests WHERE status = 'pending'");
    $stats['pending_results']  = fetchCount($conn, "SELECT COUNT(*) FROM categories WHERE status='Results Submitted'");
}

if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

if (isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $profile_picture = $user['profile_picture']; // Keep existing picture by default

    // Handle File Upload
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/profiles/';
        
        // Create directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $file_ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($file_ext, $allowed_exts)) {
            // Create a unique filename to prevent overwriting
            $new_filename = 'user_' . $user_id . '_' . time() . '.' . $file_ext;
            $dest_path = $upload_dir . $new_filename;

            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $dest_path)) {
                $profile_picture = $dest_path; // Update path for database
            } else {
                $message = "Error: Failed to move uploaded file.";
                $message_type = 'danger';
            }
        } else {
            $message = "Error: Invalid file type. Only JPG, PNG, and GIF are allowed.";
            $message_type = 'danger';
        }
    }

    // Only proceed to database update if there wasn't a file upload error
    if (empty($message_type) || $message_type !== 'danger') {
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt_check->bind_param("si", $username, $user_id);
        $stmt_check->execute();
        $stmt_check->store_result();
        
        if ($stmt_check->num_rows > 0) {
            $message = "Error: Username already taken.";
            $message_type = 'danger';
        } else {
            $stmt_check->close();
            // Update query now includes profile_picture
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, profile_picture = ? WHERE id = ?");
            $stmt->bind_param("sssi", $full_name, $username, $profile_picture, $user_id);
            
            if ($stmt->execute()) {
                $message = "Profile updated successfully.";
                $message_type = 'success';
                $_SESSION['username'] = $username;
                $name = $full_name; 
                $user['full_name'] = $full_name;
                $user['username'] = $username;
                $user['profile_picture'] = $profile_picture; // Update local user array
            } else {
                $message = "Error updating profile: " . $stmt->error;
                $message_type = 'danger';
            }
            $stmt->close();
        }
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

// Build initials for avatar
$initials = 'U';
if (!empty($user['full_name'])) {
    $parts = explode(' ', trim($user['full_name']));
    $initials = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) $initials .= strtoupper(substr(end($parts), 0, 1));
} elseif (!empty($user['username'])) {
    $initials = strtoupper(substr($user['username'], 0, 2));
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
        :root { 
            --sidebar-width: 260px; 
            --header-height: 82px; 
            --transition: all 0.3s ease; 
            --card-shadow: 0 2px 12px rgba(0,0,0,0.06); 
            --bg-light: #F8F9FA; 
            --accent-color: #1abc9c;
        }
        body { background-color: var(--bg-light); margin: 0; padding: 0; min-height: 100vh; font-family: 'Inter', sans-serif; display: flex; flex-direction: column; }

        /* Navbar */
        .navbar { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.15); padding: 1rem 1.5rem; height: var(--header-height); position: fixed; top: 0; left: 0; right: 0; z-index: 1050; }
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; transition: var(--transition); }
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255,255,255,0.1); }

        /* Sidebar */
        .sidebar { width: var(--sidebar-width); position: fixed; top: var(--header-height); left: 0; height: calc(100vh - var(--header-height)); background: #2c3e50; color: white; box-shadow: 5px 0 15px rgba(0,0,0,0.2); z-index: 1040; transition: width var(--transition); overflow-y: auto; }
        .sidebar-nav { padding: 20px 0; }
        .sidebar-nav .nav-link { color: rgba(255,255,255,0.7); font-size: 1.05rem; font-weight: 500; padding: 12px 25px; transition: var(--transition); border-left: 5px solid transparent; margin: 2px 0; display: flex; align-items: center; text-decoration: none; }
        .sidebar-nav .nav-link i { width: 30px; text-align: center; flex-shrink: 0; font-size: 0.95em; }
        .sidebar-nav .nav-link:hover { color: white; background: rgba(255,255,255,0.05); border-left-color: var(--accent-color); }
        .sidebar-nav .nav-link.active { color: white; background: rgba(255,255,255,0.1); border-left-color: #3498db; font-weight: 600; }
        .sidebar-nav .nav-title { padding: 15px 25px 5px; font-size: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 1px; }

        .main-content { flex: 1 0 auto; padding: 36px 32px; margin-top: var(--header-height); margin-left: var(--sidebar-width); transition: margin-left var(--transition); min-height: calc(100vh - var(--header-height)); }

        /* Footer */
        footer { flex-shrink: 0; box-shadow: 0 -2px 10px rgba(0,0,0,0.1); padding-left: var(--sidebar-width); transition: padding-left var(--transition); position: relative; z-index: 1041; }
        .footer-main { flex-shrink: 0; background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%); color: rgba(255,255,255,0.7); padding: 3rem 0 2rem 0; box-shadow: 0 -4px 20px rgba(0,0,0,0.15); position: relative; z-index: 1; }
        .footer-main .footer-logo-group { display: flex; align-items: center; gap: 12px; margin-bottom: 1rem; }
        .footer-main .footer-logo-group img { height: 50px !important; width: 50px !important; object-fit: contain; }
        .footer-main .footer-logo-group h5 { margin: 0; font-size: 1.1rem; font-weight: 700; color: #fff; line-height: 1.2; }
        .footer-main p { font-size: 0.9rem; max-width: 400px; }
        .footer-main h6 { font-family: 'Poppins', sans-serif; color: #fff; font-weight: 600; margin-bottom: 1rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .footer-main .footer-links { list-style: none; padding: 0; }
        .footer-main .footer-links li { margin-bottom: 0.5rem; }
        .footer-main .footer-links a { text-decoration: none; color: rgba(255,255,255,0.7); transition: var(--transition); }
        .footer-main .footer-links a:hover { color: #fff; padding-left: 5px; }
        .footer-bottom { border-top: 1px solid rgba(255,255,255,0.1); padding-top: 1.5rem; margin-top: 2rem; text-align: center; font-size: 0.85rem; }

        /* ── Profile Page Specific ── */
        .profile-page-header { margin-bottom: 2rem; }
        .profile-page-header .breadcrumb-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: #9ca3af; margin: 0 0 4px; }
        .profile-page-header h1 { font-size: 1.5rem; font-weight: 600; color: #1f2937; margin: 0; }

        .profile-role-badge { font-size: 11px; background: #fff; color: #6b7280; border: 1px solid #e5e7eb; padding: 5px 16px; border-radius: 999px; letter-spacing: 0.03em; }

        /* Identity card */
        .identity-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 1.5rem; margin-bottom: 16px; display: flex; align-items: center; gap: 20px; }
        .identity-avatar { width: 68px; height: 68px; border-radius: 50%; background: #dbeafe; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .identity-avatar span { font-size: 22px; font-weight: 600; color: #1d4ed8; }
        .identity-info .identity-name { font-size: 1.1rem; font-weight: 600; color: #1f2937; margin: 0 0 3px; }
        .identity-info .identity-email { font-size: 13px; color: #6b7280; margin: 0 0 2px; }
        .identity-info .identity-meta { font-size: 12px; color: #9ca3af; margin: 0; }

        /* Section cards */
        .profile-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 1.5rem; }
        .profile-card .card-section-label { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.08em; color: #9ca3af; font-weight: 600; margin: 0 0 1.25rem; }

        /* Form elements */
        .pf-label { display: block; font-size: 12px; color: #6b7280; margin-bottom: 6px; font-weight: 500; }
        .pf-input { width: 100%; box-sizing: border-box; font-size: 14px; padding: 9px 12px; border-radius: 8px; border: 1px solid #e5e7eb; background: #f9fafb; color: #1f2937; transition: border-color 0.15s, box-shadow 0.15s; outline: none; font-family: 'Inter', sans-serif; }
        .pf-input:focus { border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(147,197,253,0.25); background: #fff; }
        .pf-input:disabled { color: #9ca3af; cursor: not-allowed; }
        .pf-hint { font-size: 11px; color: #9ca3af; margin: 5px 0 0; }

        .role-display { display: flex; align-items: center; gap: 8px; padding: 9px 12px; border-radius: 8px; background: #f9fafb; border: 1px solid #e5e7eb; }
        .role-display i { font-size: 12px; color: #9ca3af; }
        .role-display span { font-size: 14px; color: #6b7280; }

        /* Buttons */
        .pf-btn { width: 100%; padding: 9px 16px; font-size: 13px; font-weight: 500; border-radius: 8px; border: 1px solid #d1d5db; background: #fff; color: #374151; cursor: pointer; transition: background 0.15s, border-color 0.15s; font-family: 'Inter', sans-serif; }
        .pf-btn:hover { background: #f3f4f6; border-color: #9ca3af; }
        .pf-btn:active { background: #e5e7eb; }

        /* Alert */
        .pf-alert { border-radius: 10px; font-size: 14px; padding: 12px 16px; border: none; margin-bottom: 1.5rem; }
        .pf-alert-success { background: #f0fdf4; color: #166534; border-left: 3px solid #22c55e; }
        .pf-alert-danger  { background: #fef2f2; color: #991b1b; border-left: 3px solid #ef4444; }

        /* Divider between right-col cards */
        .right-col-gap { height: 16px; }

        @media (max-width: 992px) {
            .sidebar { left: -260px; }
            .sidebar.show { left: 0; }
            .main-content, footer { margin-left: 0; }
            .profile-grid { grid-template-columns: 1fr !important; }
            .identity-card { flex-direction: column; text-align: center; }
        }
        @media (max-width: 991px) {
            .footer-main { text-align: center; }
            .footer-main .footer-logo-group { justify-content: center; }
            .footer-main .row > div { margin-bottom: 2rem; }
            .footer-main .row > div:last-child { margin-bottom: 0; }
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
            <img src="images/PIT.png" alt="Logo" class="me-2" style="height:50px;width:48px;object-fit:contain;">
            <div class="d-flex flex-column lh-sm">
                <strong class="text-white" style="font-size:1.25rem;">PIT SPORTS TALLYING</strong>
                <small class="text-light" style="font-size:0.75rem;"><?php echo htmlspecialchars($_SESSION['role']); ?> Panel</small>
            </div>
        </a>
        <button class="navbar-toggler d-lg-none" type="button" id="mobileToggle">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="dropdown user-dropdown ms-auto me-2 me-lg-0">
            <a href="#" class="dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                <?php if (!empty($user['profile_picture']) && file_exists($user['profile_picture'])): ?>
                    <img src="<?= htmlspecialchars($user['profile_picture']) ?>" alt="Profile" style="width: 42px; height: 42px; border-radius: 50%; object-fit: cover; margin-right: 10px; border: 2px solid rgba(255,255,255,0.2);">
                <?php else: ?>
                    <i class="fas fa-user-circle" style="font-size:36px;margin-right:10px;"></i>
                <?php endif; ?>
                
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

<!-- ═══════════════════════════════════════════════
     MAIN CONTENT — REDESIGNED
════════════════════════════════════════════════ -->
<div class="main-content">
    <div class="container-fluid" style="max-width: 960px;">

        <!-- Page header -->
        <div class="profile-page-header d-flex align-items-center justify-content-between">
            <div>
                <p class="breadcrumb-label">Settings</p>
                <h1>My profile</h1>
            </div>
            <span class="profile-role-badge"><?= htmlspecialchars($user['role']) ?></span>
        </div>

        <!-- Alert -->
        <?php if ($message): ?>
        <div class="pf-alert <?= $message_type === 'success' ? 'pf-alert-success' : 'pf-alert-danger' ?> d-flex align-items-center gap-2 alert-dismissible fade show" role="alert">
            <i class="fas <?= $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
            <span><?= htmlspecialchars($message) ?></span>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close" style="font-size:12px;"></button>
        </div>
        <?php endif; ?>

        <!-- Identity card -->
        <div class="identity-card">
            <div class="identity-avatar" style="overflow: hidden;">
            <?php if (!empty($user['profile_picture']) && file_exists($user['profile_picture'])): ?>
                <img src="<?= htmlspecialchars($user['profile_picture']) ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
            <?php else: ?>
                <span><?= htmlspecialchars($initials) ?></span>
            <?php endif; ?>
        </div>
            <div class="identity-info">
                <p class="identity-name"><?= htmlspecialchars($name) ?></p>
                <p class="identity-email"><?= htmlspecialchars($user['email'] ?? 'No email set') ?></p>
                <p class="identity-meta">@<?= htmlspecialchars($user['username']) ?></p>
            </div>
        </div>

        <!-- Two-column grid -->
        <div class="profile-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; align-items:start;">

            <!-- Left: Account info -->
            <div class="profile-card" style="grid-row: span 2;">
                <p class="card-section-label">Account info</p>

                <form action="admin_profile.php" method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="full_name" class="pf-label">Full name</label>
                        <input type="text" class="pf-input" id="full_name" name="full_name"
                               value="<?= htmlspecialchars($user['full_name']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="username" class="pf-label">Username</label>
                        <input type="text" class="pf-input" id="username" name="username"
                               value="<?= htmlspecialchars($user['username']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="profile_picture" class="pf-label">Profile Picture</label>
                        <input type="file" class="pf-input" id="profile_picture" name="profile_picture" accept="image/png, image/jpeg, image/gif" style="background: #fff; padding: 6px 12px;">
                        <p class="pf-hint">Recommended size: 250x250px. Max size: 2MB.</p>
                    </div>

                    <div class="mb-4">
                        <label class="pf-label">System role</label>
                        <div class="role-display">
                            <i class="fas fa-shield-alt"></i>
                            <span><?= htmlspecialchars($user['role']) ?></span>
                        </div>
                        <p class="pf-hint"><i class="fas fa-info-circle me-1"></i>Role cannot be changed. Contact admin for updates.</p>
                    </div>

                    <button type="submit" name="update_profile" class="pf-btn">
                        Save changes
                    </button>
                </form>
            </div>

            <!-- Right top: Change password -->
            <div class="profile-card">
                <p class="card-section-label">Change password</p>

                <form action="admin_profile.php" method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="current_password" class="pf-label">Current password</label>
                        <input type="password" class="pf-input" id="current_password" name="current_password" placeholder="••••••••" required>
                    </div>
                    <div class="mb-3">
                        <label for="new_password" class="pf-label">New password</label>
                        <input type="password" class="pf-input" id="new_password" name="new_password" placeholder="••••••••" required>
                    </div>
                    <div class="mb-4">
                        <label for="confirm_password" class="pf-label">Confirm new password</label>
                        <input type="password" class="pf-input" id="confirm_password" name="confirm_password" placeholder="••••••••" required>
                    </div>
                    <button type="submit" name="change_password" class="pf-btn">Update password</button>
                </form>
            </div>

            <!-- Right bottom: Update email -->
            <div class="profile-card">
                <p class="card-section-label">Update email</p>

                <form action="admin_profile.php" method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="new_email" class="pf-label">New email address</label>
                        <input type="email" class="pf-input" id="new_email" name="new_email" placeholder="new@email.com" required>
                    </div>
                    <div class="mb-3">
                        <label for="current_password_for_email" class="pf-label">Password to confirm</label>
                        <input type="password" class="pf-input" id="current_password_for_email" name="current_password_for_email" placeholder="••••••••" required>
                    </div>
                    <p class="pf-hint mb-3"><i class="fas fa-envelope me-1"></i>A verification link will be sent to your new address.</p>
                    <button type="submit" name="change_email" class="pf-btn">Send verification</button>
                </form>
            </div>

        </div><!-- end grid -->

    </div>
</div>
<!-- ═══════════════════════════════════════════════ -->

<footer class="footer-main">
    <div class="container">
        <div class="row">
            <div class="col-lg-5 col-md-12 mb-4 mb-lg-0">
                <div class="footer-logo-group">
                    <img src="images/PIT.png" alt="Logo">
                    <img src="images/Cote.png" alt="Logo">
                    <h5>PIT SILAKAS MEDAL TALLY</h5>
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
                <div style="color:rgba(255,255,255,0.7);font-size:0.9rem;line-height:1.6;">
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
            <small>Developed by Jayvee Baybayon</small>
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
                if (window.innerWidth <= 992) { sidebar.style.height = ''; return; }
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