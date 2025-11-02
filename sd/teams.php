<?php
session_start();
// Use a relative path to your main db_connect.php
require_once '../db_connect.php'; 

// 1. SECURITY & ACCESS CONTROL
// --- FIX: Allowed 'Administrator' to access this page as well ---
if (!isset($_SESSION['role']) || 
    ($_SESSION['role'] !== 'Sports Director' && $_SESSION['role'] !== 'Administrator')
) {
    header('Location: ../login.php'); // Redirect to main login page
    exit();
}
// -----------------------------------------------------------------

$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Sports Director';
$current_page = basename($_SERVER['PHP_SELF']);
// --- Logic for Sidebar Accordions ---
$event_pages = ['Manage_Games.php', 'Manage_Game_Events.php', 'Manage_Categories.php'];
$is_event_page = in_array($current_page, $event_pages);

$management_pages = ['teams.php', 'events.php', 'results.php', 'reports.php'];
$is_management_page = in_array($current_page, $management_pages);

// Helper function for logging actions
function log_activity($conn, $message) {
    // Only log if a user ID is set in the session
    if (!isset($_SESSION['user_id'])) {
        return; // Don't log if no user is properly logged in
    }
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("INSERT INTO system_logs (user_id, log_message) VALUES (?, ?)");
    $stmt->bind_param("is", $user_id, $message);
    $stmt->execute();
    $stmt->close();
}


// NEW: Helper function for handling file uploads
/**
 * Handles file upload and returns the database path.
 *
 * @param string $file_key The key from $_FILES (e.g., 'logo_url')
 * @param string $upload_dir The server directory to upload to (e.g., '../uploads/teams/')
 * @param string|null $current_db_path The current DB path (for edits, to delete the old file)
 * @return string|null The new database path (e.g., 'uploads/teams/file.png') or $current_db_path if no new file
 */
function handle_file_upload($file_key, $upload_dir, $current_db_path = null) {
    $default_avatar = 'images/default_avatar.png';
    
    // Check if a file was actually uploaded
    if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
        
        // Create directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_tmp_path = $_FILES[$file_key]['tmp_name'];
        $file_name = $_FILES[$file_key]['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($file_ext, $allowed_ext)) {
            $new_file_name = uniqid('', true) . '.' . $file_ext;
            $target_server_path = $upload_dir . $new_file_name;

            if (move_uploaded_file($file_tmp_path, $target_server_path)) {
                
                // NEW: Delete the old file if it exists and is not the default
                if ($current_db_path && $current_db_path !== $default_avatar && file_exists('../' . $current_db_path)) {
                    @unlink('../' . $current_db_path); // Use @ to suppress errors if file not found
                }
                
                // Return the path to be stored in the DB (without '../')
                return rtrim(str_replace('../', '', $upload_dir), '/') . '/' . $new_file_name;
            }
        }
    }
    
    // If no new file was uploaded, keep the old one
    // If no file and no current path, return default
    if (empty($current_db_path)) {
        return $default_avatar;
    }
    return $current_db_path;
}

// --- Page Specific PHP ---

// NEW: Define upload directory
$upload_dir = '../uploads/teams/';
$default_logo = 'images/default_avatar.png';

// 1. ADD TEAM (MODIFIED)
if (isset($_POST['add_team'])) {
    $team_name = $_POST['team_name'];
    $dean_name = $_POST['dean_name'];
    $total_students = (int)$_POST['total_students'];
    $description = $_POST['description']; // NEW

    // NEW: Handle file uploads
    $logo_path = handle_file_upload('logo_url', $upload_dir, $default_logo);
    $dean_photo_path = handle_file_upload('dean_photo_url', $upload_dir, $default_logo);

    $stmt = $conn->prepare("INSERT INTO teams (team_name, dean_name, total_students, description, logo_url, dean_photo_url) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssisss", $team_name, $dean_name, $total_students, $description, $logo_path, $dean_photo_path);
    
    if($stmt->execute()) {
        log_activity($conn, "Team '{$team_name}' was created.");
        $_SESSION['message'] = "Team created successfully.";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "Error: " . $stmt->error;
        $_SESSION['message_type'] = "danger";
    }
    $stmt->close();
    header("Location: teams.php");
    exit();
}


// 2. EDIT TEAM (MODIFIED)
if (isset($_POST['edit_team'])) {
    $team_id = (int)$_POST['edit_team_id'];
    $team_name = $_POST['edit_team_name'];
    $dean_name = $_POST['edit_dean_name'];
    $total_students = (int)$_POST['edit_total_students'];
    $description = $_POST['edit_description']; // NEW

    // NEW: Get current paths from hidden fields
    $current_logo = $_POST['current_logo_url'];
    $current_dean_photo = $_POST['current_dean_photo_url'];

    // NEW: Handle file uploads (will keep current path if no new file is uploaded)
    $logo_path = handle_file_upload('edit_logo_url', $upload_dir, $current_logo);
    $dean_photo_path = handle_file_upload('edit_dean_photo_url', $upload_dir, $current_dean_photo);

    $stmt = $conn->prepare("UPDATE teams SET team_name = ?, dean_name = ?, total_students = ?, description = ?, logo_url = ?, dean_photo_url = ? WHERE team_id = ?");
    $stmt->bind_param("ssisssi", $team_name, $dean_name, $total_students, $description, $logo_path, $dean_photo_path, $team_id);
     
    if($stmt->execute()) {
        log_activity($conn, "Team '{$team_name}' (ID: {$team_id}) was updated.");
        $_SESSION['message'] = "Team updated successfully.";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "Error: " . $stmt->error;
        $_SESSION['message_type'] = "danger";
    }
    $stmt->close();
    header("Location: teams.php");
    exit();
}

// 3. DELETE TEAM (MODIFIED)
if (isset($_POST['delete_team'])) {
    $team_id = (int)$_POST['delete_team_id'];
    
    // Validation check: Is this team linked to any results?
    $stmt_check = $conn->prepare("SELECT COUNT(*) FROM results WHERE winner_gold_team_id = ? OR winner_silver_team_id = ? OR winner_bronze_team_id = ?");
    $stmt_check->bind_param("iii", $team_id, $team_id, $team_id);
    $stmt_check->execute();
    $count = $stmt_check->get_result()->fetch_row()[0];
    $stmt_check->close();
    
    if ($count > 0) {
        $_SESSION['message'] = "Error: Cannot delete team. It is linked to {$count} approved result(s).";
        $_SESSION['message_type'] = "danger";
    } else {
        
        // NEW: Get file paths *before* deleting the record
        $stmt_get_paths = $conn->prepare("SELECT logo_url, dean_photo_url FROM teams WHERE team_id = ?");
        $stmt_get_paths->bind_param("i", $team_id);
        $stmt_get_paths->execute();
        $paths = $stmt_get_paths->get_result()->fetch_assoc();
        $stmt_get_paths->close();

        $stmt = $conn->prepare("DELETE FROM teams WHERE team_id = ?");
        $stmt->bind_param("i", $team_id);
        
        if($stmt->execute()) {
            log_activity($conn, "Team (ID: {$team_id}) was deleted.");
            $_SESSION['message'] = "Team deleted successfully.";
            $_SESSION['message_type'] = "success";

            // NEW: Delete files from server
            if ($paths) {
                if ($paths['logo_url'] && $paths['logo_url'] !== $default_logo && file_exists('../' . $paths['logo_url'])) {
                    @unlink('../' . $paths['logo_url']);
                }
                if ($paths['dean_photo_url'] && $paths['dean_photo_url'] !== $default_logo && file_exists('../' . $paths['dean_photo_url'])) {
                    @unlink('../' . $paths['dean_photo_url']);
                }
            }
        } else {
            $_SESSION['message'] = "Error: " . $stmt->error;
            $_SESSION['message_type'] = "danger";
        }
        $stmt->close();
    }
    header("Location: teams.php");
    exit();
}

// --- FETCH DATA (READ) ---
$teams = [];
$result = $conn->query("SELECT * FROM teams ORDER BY team_name");
if ($result) {
    $teams = $result->fetch_all(MYSQLI_ASSOC);
}

// Check for session messages
$message = null; 
$message_type = 'info'; 

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
    <title>Manage Teams - SD Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --sidebar-width: 260px; --header-height: 82px; --transition: all 0.3s ease; --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); --bg-light: #F8F9FA; }
        body { background-color: var(--bg-light); margin: 0; padding: 0; min-height: 100vh; font-family: 'Inter', sans-serif; display: flex; flex-direction: column; }
        .navbar { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.15); padding: 1rem 1.5rem; height: var(--header-height); position: fixed; top: 0; left: 0; right: 0; z-index: 1050; }
        .navbar-brand { /* ... */ }
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; }
        .user-dropdown .dropdown-toggle img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; margin-right: 10px; }
        .sidebar { width: var(--sidebar-width); position: fixed; top: var(--header-height); left: 0; height: calc(100vh - var(--header-height)); background: #2c3e50; color: white; box-shadow: 5px 0 15px rgba(0,0,0,0.2); z-index: 1040; transition: width var(--transition); overflow-y: auto; overflow-x: hidden; }
        .sidebar-nav { padding: 20px 0; }
        .sidebar-nav .nav-link { color: rgba(255, 255, 255, 0.7); font-size: 1.05rem; font-weight: 500; padding: 12px 25px; transition: var(--transition); border-left: 5px solid transparent; margin: 2px 0; display: flex; align-items: center; text-decoration: none; }
        .sidebar-nav .nav-link i { width: 30px; text-align: center; flex-shrink: 0; font-size: 0.95em; }
        .sidebar-nav .nav-link:hover { color: white; background: rgba(255, 255, 255, 0.05); border-left-color: #1abc9c; }
        .sidebar-nav .nav-link.active { color: white; background: rgba(255, 255, 255, 0.1); border-left-color: #3498db; font-weight: 600; }
        .sidebar-nav .nav-title { padding: 10px 25px; font-size: 0.75rem; font-weight: 600; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px; }
        .main-content { flex: 1 0 auto; padding: 30px; margin-top: var(--header-height); margin-left: var(--sidebar-width); transition: margin-left var(--transition); min-height: calc(100vh - var(--header-height)); }
        footer { flex-shrink: 0; background: #2c3e50 !important; box-shadow: 0 -2px 10px rgba(0,0,0,0.1); margin-left: var(--sidebar-width); transition: margin-left var(--transition); position: relative; z-index: 1041; }
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        .card { border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        /* NEW: Style for table images */
        .table-img {
            width: 50px; 
            height: 50px; 
            border-radius: 50%; 
            object-fit: cover;
        }
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
        /* --- End of Accordion Styles --- */
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items: center justify-content-between">
            <a class="navbar-brand d-flex align-items: center" href="dashboard.php">
                <img src="../imageslogo.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <!-- FIX: Show correct panel title based on role -->
                    <small class="text-light" style="font-size: 0.75rem;">
                        <?php 
                            if ($_SESSION['role'] === 'Administrator') {
                                echo 'Administrator Panel';
                            } else {
                                echo 'Sports Director Panel';
                            }
                        ?>
                    </small>
                </div>
            </a>
            <div class="dropdown user-dropdown ms-auto me-2 me-lg-0">
                <a href="#" class="dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle navbar-profile-icon"></i>
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="../admin_profile.php"><i class="fas fa-user-circle"></i> Profile</a></li>
                    <li><a class="dropdown-item" href="../Tournament_Manager_page.php" target="_blank"><i class="fas fa-globe"></i> View Public Site</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>
<?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Administrator'): ?>
    <div class="sidebar" id="sidebar">
            <button id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            
            <ul class="nav flex-column sidebar-nav">
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'admin_dashboard.php') echo 'active'; ?>" href="../admin_dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link <?php if ($is_event_page) echo 'active'; ?>" data-bs-toggle="collapse" href="#eventsCollapse" role="button" aria-expanded="<?php echo $is_event_page ? 'true' : 'false'; ?>" aria-controls="eventsCollapse">
                        <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events</span> <i class="fas fa-chevron-down ms-auto sidebar-chevron"></i>
                    </a>
                    <div class="collapse <?php if ($is_event_page) echo 'show'; ?>" id="eventsCollapse">
                        <ul class="sub-menu">
                            <li class="nav-item"> 
                                <a class="nav-link <?php if ($current_page == 'Manage_Games.php') echo 'active'; ?>" href="../Manage_Games.php">
                                    <span>Games (L1)</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php if ($current_page == 'Manage_Game_Events.php') echo 'active'; ?>" href="../Manage_Game_Events.php">
                                    <span>Game Events (L2)</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php if ($current_page == 'Manage_Categories.php') echo 'active'; ?>" href="../Manage_Categories.php">
                                    <span>Categories (L3)</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($is_management_page) echo 'active'; ?>" data-bs-toggle="collapse" href="#teamsCollapse" role="button" aria-expanded="<?php echo $is_management_page ? 'true' : 'false'; ?>" aria-controls="teamsCollapse">
                        <i class="fas fa-users me-2"></i> <span>Manage Teams</span> <i class="fas fa-chevron-down ms-auto sidebar-chevron"></i>
                    </a>
                    
                    <div class="collapse <?php if ($is_management_page) echo 'show'; ?>" id="teamsCollapse">
                        <ul class="sub-menu">
                            
                            <li class="text-muted" style="padding: 10px 25px 5px 60px; margin-top: 5px; font-size: 0.75rem; font-weight: 600; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px;">
                                Management
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php if ($current_page == 'teams.php') echo 'active'; ?>" href="teams.php">
                                    <span>Manage Teams</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php if ($current_page == 'events.php') echo 'active'; ?>" href="events.php">
                                    <span>Manage Events (L1-L3)</span>
                                </a>
                            </li>
                            
                            <li class="text-muted" style="padding: 10px 25px 5px 60px; margin-top: 10px; font-size: 0.75rem; font-weight: 600; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px;">
                                Tallying
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php if ($current_page == 'results.php') echo 'active'; ?>" href="results.php">
                                    <span>Approve Results</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php if ($current_page == 'reports.php') echo 'active'; ?>" href="reports.php">
                                    <span>Medal Reports</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'Manage_Users.php') echo 'active'; ?>" href="../Manage_Users.php">
                        <i class="fas fa-users-cog me-2"></i> <span>Manage Users</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'Manage_medals.php') echo 'active'; ?>" href="../Manage_medals.php">
                        <i class="fas fa-medal me-2"></i> <span>Manage Medals</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'Manage_Requests.php') echo 'active'; ?>" href="../Manage_Requests.php">
                        <i class="fas fa-user-plus me-2"></i> <span>Account Requests</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'Manage_Viewreports.php') echo 'active'; ?>" href="../Manage_Viewreports.php">
                        <i class="fas fa-chart-line me-2"></i> <span>View Reports</span>
                    </a>
                </li>
                
                <li class="nav-item mt-3">
                    <a class="nav-link text-danger" href="../logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
                    </a>
                </li>
            </ul>
        </div>
        <?php else: ?>

        <div class="sidebar" id="sidebar">
            <ul class="nav flex-column sidebar-nav">
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'sports_director_dashboard.php') echo 'active'; ?>" 
                       href="sports_director_dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item mt-3"><span class="nav-title">Management</span></li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'teams.php') echo 'active'; ?>" href="teams.php">
                        <i class="fas fa-users me-2"></i> <span>Manage Teams</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'events.php') echo 'active'; ?>" href="events.php">
                        <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events (L1-L3)</span>
                    </a>
                </li>
                
                <li class="nav-item mt-3"><span class="nav-title">Tallying</span></li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'results.php') echo 'active'; ?>" href="results.php">
                        <i class="fas fa-check-double me-2"></i> <span>Approve Results</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'reports.php') echo 'active'; ?>" href="reports.php">
                        <i class="fas fa-chart-line me-2"></i> <span>Medal Reports</span>
                    </a>
                </li>
                
                <li class="nav-item mt-auto">
                    <a class="nav-link text-danger" href="../logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
                    </a>
                </li>
            </ul>
        </div>

    <?php endif; ?> <div class="main-content">
        
        <div class="container-fluid">
            <h1 class="section-title mb-4">Manage Teams</h1>

            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">All Teams</h5>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTeamModal">
                        <i class="fas fa-plus me-2"></i>Add New Team
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Logo</th>
                                    <th>Team Name</th>
                                    <th>Dean</th>
                                    <th>Dean's Photo</th> <th>Students</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($teams as $team): ?>
                                <?php
                                    // NEW: Set default images if paths are null or empty
                                    $logo = (!empty($team['logo_url'])) ? $team['logo_url'] : $default_logo;
                                    $dean_photo = (!empty($team['dean_photo_url'])) ? $team['dean_photo_url'] : $default_logo;
                                ?>
                                <tr>
                                    <td>
                                        <img src="../<?= htmlspecialchars($logo) ?>" alt="Logo" class="table-img" 
                                            onerror="this.onerror=null; this.src='../<?= $default_logo ?>'">
                                    </td>
                                    <td><?= htmlspecialchars($team['team_name']) ?></td>
                                    <td><?= htmlspecialchars($team['dean_name']) ?></td>
                                    <td>
                                        <img src="../<?= htmlspecialchars($dean_photo) ?>" alt="Dean" class="table-img"
                                            onerror="this.onerror=null; this.src='../<?= $default_logo ?>'">
                                    </td>
                                    <td><?= htmlspecialchars($team['total_students']) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary edit-btn"
                                            data-bs-toggle="modal" data-bs-target="#editTeamModal"
                                            data-id="<?= $team['team_id'] ?>"
                                            data-name="<?= htmlspecialchars($team['team_name']) ?>"
                                            data-dean="<?= htmlspecialchars($team['dean_name']) ?>"
                                            data-students="<?= $team['total_students'] ?>"
                                            data-description="<?= htmlspecialchars($team['description'] ?? '') ?>" 
                                            data-logo="<?= htmlspecialchars($logo) ?>" 
                                            data-dean-photo="<?= htmlspecialchars($dean_photo) ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger delete-btn"
                                            data-bs-toggle="modal" data-bs-target="#deleteTeamModal"
                                            data-id="<?= $team['team_id'] ?>"
                                            data-name="<?= htmlspecialchars($team['team_name']) ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="addTeamModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Team</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="teams.php" method="POST" enctype="multipart/form-data">
                        <div class="modal-body">
                            <input type="hidden" name="add_team">
                            <div class="mb-3">
                                <label for="team_name" class="form-label">Team Name</label>
                                <input type="text" class="form-control" id="team_name" name="team_name" required>
                            </div>
                            <div class="mb-3">
                                <label for="dean_name" class="form-label">Dean's Name</label>
                                <input type="text" class="form-control" id="dean_name" name="dean_name">
                            </div>
                            <div class="mb-3">
                                <label for="total_students" class="form-label">Total Students</label>
                                <input type="number" class="form-control" id="total_students" name="total_students" value="0">
                            </div>
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="logo_url" class="form-label">Team Logo (Optional)</label>
                                <input type="file" class="form-control" id="logo_url" name="logo_url" accept="image/*">
                            </div>
                            <div class="mb-3">
                                <label for="dean_photo_url" class="form-label">Dean's Photo (Optional)</label>
                                <input type="file" class="form-control" id="dean_photo_url" name="dean_photo_url" accept="image/*">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Save Team</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="editTeamModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Team</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="teams.php" method="POST" enctype="multipart/form-data">
                        <div class="modal-body">
                            <input type="hidden" name="edit_team">
                            <input type="hidden" name="edit_team_id" id="edit_team_id">
                            
                            <input type="hidden" name="current_logo_url" id="current_logo_url">
                            <input type="hidden" name="current_dean_photo_url" id="current_dean_photo_url">
                            
                            <div class="mb-3">
                                <label for="edit_team_name" class="form-label">Team Name</label>
                                <input type="text" class="form-control" id="edit_team_name" name="edit_team_name" required>
                            </div>
                            <div class="mb-3">
                                <label for="edit_dean_name" class="form-label">Dean's Name</label>
                                <input type="text" class="form-control" id="edit_dean_name" name="edit_dean_name">
                            </div>
                            <div class="mb-3">
                                <label for="edit_total_students" class="form-label">Total Students</label>
                                <input type="number" class="form-control" id="edit_total_students" name="edit_total_students" value="0">
                            </div>
                            <div class="mb-3">
                                <label for="edit_description" class="form-label">Description</label>
                                <textarea class="form-control" id="edit_description" name="edit_description" rows="3"></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="edit_logo_url" class="form-label">New Team Logo (Optional)</label>
                                <input type="file" class="form-control" id="edit_logo_url" name="edit_logo_url" accept="image/*">
                                <small class="text-muted">Leave blank to keep current logo.</small>
                            </div>
                            <div class="mb-3">
                                <label for="edit_dean_photo_url" class="form-label">New Dean's Photo (Optional)</label>
                                <input type="file" class="form-control" id="edit_dean_photo_url" name="edit_dean_photo_url" accept="image/*">
                                <small class="text-muted">Leave blank to keep current photo.</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Update Team</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="deleteTeamModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Confirm Deletion</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="teams.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="delete_team">
                            <input type="hidden" name="delete_team_id" id="delete_team_id">
                            <p>Are you sure you want to delete this team: <strong id="delete_team_name"></strong>?</p>
                            <p class="text-danger">This action cannot be undone. It will only succeed if the team is not linked to any results.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Delete Team</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        </div> 
        
    <footer class="bg-dark text-white py-4" style="margin-left: var(--sidebar-width);">
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
            <small class="text-muted">Developed by Tsunayoshi Sawada</small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // MODIFIED: Edit Modal JS to include new fields
        const editTeamModal = document.getElementById('editTeamModal');
        editTeamModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            // Get data from button
            document.getElementById('edit_team_id').value = button.dataset.id;
            document.getElementById('edit_team_name').value = button.dataset.name;
            document.getElementById('edit_dean_name').value = button.dataset.dean;
            document.getElementById('edit_total_students').value = button.dataset.students;
            
            // NEW: Populate new fields
            document.getElementById('edit_description').value = button.dataset.description;
            document.getElementById('current_logo_url').value = button.dataset.logo;
            document.getElementById('current_dean_photo_url').value = button.dataset.deanPhoto;
        });

        const deleteTeamModal = document.getElementById('deleteTeamModal');
        deleteTeamModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('delete_team_id').value = button.dataset.id;
            document.getElementById('delete_team_name').textContent = button.dataset.name;
        });
    });
    </script>
</body>
</html>
