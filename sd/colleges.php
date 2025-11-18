<?php
session_start();
// Use a relative path to your main db_connect.php
require_once '../db_connect.php'; 

// 1. SECURITY & ACCESS CONTROL
if (!isset($_SESSION['role']) || 
    ($_SESSION['role'] !== 'Sports Director' && $_SESSION['role'] !== 'Administrator')
) {
    header('Location: ../login.php'); // Redirect to main login page
    exit();
}
// -----------------------------------------------------------------

// Ensure user_id is set in the session for logging
if (!isset($_SESSION['user_id'])) {
    die("Session error: User ID is not set.");
}
$current_user_id = $_SESSION['user_id'];

$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Sports Director';
$current_page = basename($_SERVER['PHP_SELF']);

// --- Logic for Sidebar Accordions ---
$event_pages = ['Manage_Games.php', 'Manage_Game_Events.php', 'Manage_Categories.php'];
$is_event_page = in_array($current_page, $event_pages);

$management_pages = ['colleges.php', 'events.php', 'results.php', 'reports.php'];
$is_management_page = in_array($current_page, $management_pages);

// Helper function for handling file uploads
function handle_file_upload($file_key, $upload_dir, $current_db_path = null) {
    $default_avatar = 'images/default_avatar.png';
    
    if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
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
                // Remove old file if it exists and isn't the default
                if ($current_db_path && $current_db_path !== $default_avatar && file_exists('../' . $current_db_path)) {
                    @unlink('../' . $current_db_path); 
                }
                return rtrim(str_replace('../', '', $upload_dir), '/') . '/' . $new_file_name;
            }
        }
    }
    
    if (empty($current_db_path)) {
        return $default_avatar;
    }
    return $current_db_path;
}

// --- Page Specific PHP ---
// Note: Kept folder name as 'colleges' to avoid breaking existing file paths on server
$upload_dir = '../uploads/colleges/'; 
$default_logo = 'images/default_avatar.png';

// 1. ADD TEAM (Formerly College)
if (isset($_POST['add_college'])) {
    $college_name = $_POST['college_name'];
    $college_code = $_POST['college_code'];
    $team_manager = $_POST['team_manager']; // Changed from dean_name
    $slogan       = $_POST['slogan'];       // Changed from description
    // Removed total_students

    $logo_path = handle_file_upload('logo_url', $upload_dir, $default_logo);
    // Removed dean_photo_upload

    // Insert into DB (Columns updated based on your request)
    $stmt = $conn->prepare("INSERT INTO colleges (college_name, college_code, team_manager, slogan, logo_url) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $college_name, $college_code, $team_manager, $slogan, $logo_path);
    
    if($stmt->execute()) {
        $new_college_id = (int)$conn->insert_id;
        $context = [
            'team_name' => $college_name,
            'team_code' => $college_code
        ];
        // Log activity (assuming log_activity function exists in db_connect or included file)
        if(function_exists('log_activity')) {
            log_activity($conn, $current_user_id, 'CREATED_TEAM', $new_college_id, 'college', null, null, $context);
        }
        
        $_SESSION['message'] = "Team created successfully.";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "Error: " . $stmt->error;
        $_SESSION['message_type'] = "danger";
    }
    $stmt->close();
    header("Location: colleges.php");
    exit();
}


// 2. EDIT TEAM
if (isset($_POST['edit_college'])) {
    $college_id   = (int)$_POST['edit_college_id'];
    $college_name = $_POST['edit_college_name'];
    $college_code = $_POST['edit_college_code'];
    $team_manager = $_POST['edit_team_manager']; // Changed
    $slogan       = $_POST['edit_slogan'];       // Changed

    $current_logo = $_POST['current_logo_url'];

    $logo_path = handle_file_upload('edit_logo_url', $upload_dir, $current_logo);

    // Update DB
    $stmt = $conn->prepare("UPDATE colleges SET college_name = ?, college_code = ?, team_manager = ?, slogan = ?, logo_url = ? WHERE college_id = ?");
    $stmt->bind_param("sssssi", $college_name, $college_code, $team_manager, $slogan, $logo_path, $college_id);
     
    if($stmt->execute()) {
        $context = [
            'team_name' => $college_name,
            'team_code' => $college_code
        ];
        if(function_exists('log_activity')) {
            log_activity($conn, $current_user_id, 'UPDATED_TEAM', $college_id, 'college', null, null, $context);
        }
        
        $_SESSION['message'] = "Team updated successfully.";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "Error: " . $stmt->error;
        $_SESSION['message_type'] = "danger";
    }
    $stmt->close();
    header("Location: colleges.php");
    exit();
}

// 3. DELETE TEAM
if (isset($_POST['delete_college'])) {
    $college_id = (int)$_POST['delete_college_id'];
    
    // Check dependencies in results table
    $stmt_check = $conn->prepare("SELECT COUNT(*) FROM results WHERE winner_gold_college_id = ? OR winner_silver_college_id = ? OR winner_bronze_college_id = ?");
    $stmt_check->bind_param("iii", $college_id, $college_id, $college_id);
    $stmt_check->execute();
    $count = $stmt_check->get_result()->fetch_row()[0];
    $stmt_check->close();
    
    if ($count > 0) {
        $_SESSION['message'] = "Error: Cannot delete Team. It is linked to {$count} approved result(s).";
        $_SESSION['message_type'] = "danger";
    } else {
        
        // Get data for logging/cleanup before deleting
        $stmt_get_data = $conn->prepare("SELECT college_name, logo_url FROM colleges WHERE college_id = ?");
        $stmt_get_data->bind_param("i", $college_id);
        $stmt_get_data->execute();
        $college_data = $stmt_get_data->get_result()->fetch_assoc();
        $stmt_get_data->close();
        $deleted_team_name = $college_data['college_name'] ?? 'Unknown';

        // Delete from DB
        $stmt = $conn->prepare("DELETE FROM colleges WHERE college_id = ?");
        $stmt->bind_param("i", $college_id);
        
        if($stmt->execute()) {
            $context = ['deleted_team_name' => $deleted_team_name];
            if(function_exists('log_activity')) {
                log_activity($conn, $current_user_id, 'DELETED_TEAM', $college_id, 'college', null, null, $context);
            }
            
            $_SESSION['message'] = "Team deleted successfully.";
            $_SESSION['message_type'] = "success";

            // Delete logo file
            if ($college_data) {
                if ($college_data['logo_url'] && $college_data['logo_url'] !== $default_logo && file_exists('../' . $college_data['logo_url'])) {
                    @unlink('../' . $college_data['logo_url']);
                }
            }
        } else {
            $_SESSION['message'] = "Error: " . $stmt->error;
            $_SESSION['message_type'] = "danger";
        }
        $stmt->close();
    }
    header("Location: colleges.php");
    exit();
}

// --- FETCH DATA (READ) ---
$colleges = [];
$result = $conn->query("SELECT * FROM colleges ORDER BY college_name");
if ($result) {
    $colleges = $result->fetch_all(MYSQLI_ASSOC);
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
        /* ... (Your CSS is unchanged) ... */
        :root { --sidebar-width: 260px; --header-height: 82px; --transition: all 0.3s ease; --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); --bg-light: #F8F9FA; }
        body { background-color: var(--bg-light); margin: 0; padding: 0; min-height: 100vh; font-family: 'Inter', sans-serif; display: flex; flex-direction: column; }
        .navbar { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.15); padding: 1rem 1.5rem; height: var(--header-height); position: fixed; top: 0; left: 0; right: 0; z-index: 1050; }
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
        
        /* Footer */
        footer {
            flex-shrink: 0;
            background: #2c3e50 !important;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            padding-left: var(--sidebar-width);
            transition: padding-left var(--transition);
            position: relative;
            z-index: 1041;
        }
        .sidebar.minimized ~ footer { padding-left: var(--sidebar-min-width); }
        
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        .card { border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        .table-img { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; }
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; transition: var(--transition); }
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
        .user-dropdown .dropdown-toggle .user-name { font-weight: 600; font-size: 0.95rem; }
        .navbar-profile-icon { width: 36px; height: 36px; font-size: 36px; text-align: center; line-height: 1; border-radius: 50%; margin-right: 10px; color: rgba(255,255,255,0.8); }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <img src="../imageslogo.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
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
                    <li><a class="dropdown-item text-danger" href="../login.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
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
                            <li><a class="nav-link <?php if ($current_page == 'Manage_Games.php') echo 'active'; ?>" href="../Manage_Games.php"><span>Games (L1)</span></a></li>
                            <li><a class="nav-link <?php if ($current_page == 'Manage_Game_Events.php') echo 'active'; ?>" href="../Manage_Game_Events.php"><span>Game Events (L2)</span></a></li>
                            <li><a class="nav-link <?php if ($current_page == 'Manage_Categories.php') echo 'active'; ?>" href="../Manage_Categories.php"><span>Categories (L3)</span></a></li>
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
                            <li><a class="nav-link <?php if ($current_page == 'colleges.php') echo 'active'; ?>" href="colleges.php"><span>Manage Teams</span></a></li>
                            <li><a class="nav-link <?php if ($current_page == 'events.php') echo 'active'; ?>" href="events.php"><span>Manage Events (L1-L3)</span></a></li>
                            <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'Manage_Matches.php') echo 'active'; ?>" href="Manage_Matches.php">
                                <span>Manage Matches</span>
                            </a>
                        </li>
                            <li class="text-muted" style="padding: 10px 25px 5px 60px;">Tallying</li>
                            <li><a class="nav-link <?php if ($current_page == 'results.php') echo 'active'; ?>" href="results.php"><span>Approve Results</span></a></li>
                            <li><a class="nav-link <?php if ($current_page == 'reports.php') echo 'active'; ?>" href="reports.php"><span>Medal Reports</span></a></li>
                        </ul>
                    </div>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'Manage_Users.php') echo 'active'; ?>" href="../Manage_Users.php">
                        <i class="fas fa-users-cog me-2"></i> <span>Manage Users</span>
                    </a>
                </li>
                <li class="nav-item">
                <a class="nav-link <?php if ($current_page == 'Manage_medals.php') echo 'active'; ?>" href="Manage_medals.php">
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
                    <a class="nav-link <?php if ($current_page == 'colleges.php') echo 'active'; ?>" href="colleges.php">
                        <i class="fas fa-users me-2"></i> <span>Manage Teams</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'events.php') echo 'active'; ?>" href="events.php">
                        <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events (L1-L3)</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == 'view_all_matches.php') ? 'active' : '' ?>" href="view_all_matches.php">
                        <i class="fas fa-trophy me-2"></i> <span>View All Matches</span>
                    </a>
                </li>
                 <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'Manage_Viewreports.php') echo 'active'; ?>" href="../Manage_Viewreports.php">
                        <i class="fas fa-chart-line me-2"></i> <span>View Reports</span>
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
    <?php endif; ?> 

    <div class="main-content">
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
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCollegeModal">
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
                                    <th>Code</th> 
                                    <th>Team Manager</th>
                                    <th>Slogan</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($colleges as $college): ?>
                                <?php
                                    $logo = (!empty($college['logo_url'])) ? $college['logo_url'] : $default_logo;
                                    // NOTE: Dean's Photo and Students removed from table
                                ?>
                                <tr>
                                    <td>
                                        <img src="../<?= htmlspecialchars($logo) ?>" alt="Logo" class="table-img" 
                                            onerror="this.onerror=null; this.src='../<?= $default_logo ?>'">
                                    </td>
                                    <td>
                                        <a href="team_profile.php?team_id=<?= $college['college_id'] ?>" 
                                        title="View Profile for <?= htmlspecialchars($college['college_name']) ?>">
                                            <strong><?= htmlspecialchars($college['college_name']) ?></strong>
                                        </a>
                                    </td>
                                    <td><strong><?= htmlspecialchars($college['college_code'] ?? 'N/A') ?></strong></td>
                                    <td><?= htmlspecialchars($college['team_manager'] ?? 'N/A') ?></td>
                                    <td class="text-muted"><small><?= htmlspecialchars($college['slogan'] ?? '') ?></small></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary edit-btn"
                                            data-bs-toggle="modal" data-bs-target="#editCollegeModal"
                                            data-id="<?= $college['college_id'] ?>"
                                            data-name="<?= htmlspecialchars($college['college_name']) ?>"
                                            data-code="<?= htmlspecialchars($college['college_code'] ?? '') ?>" 
                                            data-manager="<?= htmlspecialchars($college['team_manager'] ?? '') ?>"
                                            data-slogan="<?= htmlspecialchars($college['slogan'] ?? '') ?>" 
                                            data-logo="<?= htmlspecialchars($logo) ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger delete-btn"
                                            data-bs-toggle="modal" data-bs-target="#deleteCollegeModal"
                                            data-id="<?= $college['college_id'] ?>"
                                            data-name="<?= htmlspecialchars($college['college_name']) ?>">
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

        <div class="modal fade" id="addCollegeModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Team</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="colleges.php" method="POST" enctype="multipart/form-data">
                        <div class="modal-body">
                            <input type="hidden" name="add_college">
                            <div class="mb-3">
                                <label for="college_name" class="form-label">Team Name</label>
                                <input type="text" class="form-control" id="college_name" name="college_name" required>
                            </div>
                            <div class="mb-3">
                                <label for="college_code" class="form-label">Team Code / Initialism</label>
                                <input type="text" class="form-control" id="college_code" name="college_code" required placeholder="e.g., COTE, CAS">
                            </div>
                            <div class="mb-3">
                                <label for="team_manager" class="form-label">Team Manager</label>
                                <input type="text" class="form-control" id="team_manager" name="team_manager">
                            </div>
                            <div class="mb-3">
                                <label for="slogan" class="form-label">Team Slogan</label>
                                <textarea class="form-control" id="slogan" name="slogan" rows="2" placeholder="Enter team slogan..."></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="logo_url" class="form-label">Team Logo (Optional)</label>
                                <input type="file" class="form-control" id="logo_url" name="logo_url" accept="image/*">
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

        <div class="modal fade" id="editCollegeModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Team</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="colleges.php" method="POST" enctype="multipart/form-data">
                        <div class="modal-body">
                            <input type="hidden" name="edit_college">
                            <input type="hidden" name="edit_college_id" id="edit_college_id">
                            <input type="hidden" name="current_logo_url" id="current_logo_url">
                            
                            <div class="mb-3">
                                <label for="edit_college_name" class="form-label">Team Name</label>
                                <input type="text" class="form-control" id="edit_college_name" name="edit_college_name" required>
                            </div>
                            <div class="mb-3">
                                <label for="edit_college_code" class="form-label">Team Code / Initialism</label>
                                <input type="text" class="form-control" id="edit_college_code" name="edit_college_code" required placeholder="e.g., COTE, CAS">
                            </div>
                            <div class="mb-3">
                                <label for="edit_team_manager" class="form-label">Team Manager</label>
                                <input type="text" class="form-control" id="edit_team_manager" name="edit_team_manager">
                            </div>
                            <div class="mb-3">
                                <label for="edit_slogan" class="form-label">Team Slogan</label>
                                <textarea class="form-control" id="edit_slogan" name="edit_slogan" rows="2"></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="edit_logo_url" class="form-label">New Team Logo (Optional)</label>
                                <input type="file" class="form-control" id="edit_logo_url" name="edit_logo_url" accept="image/*">
                                <small class="text-muted">Leave blank to keep current logo.</small>
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

        <div class="modal fade" id="deleteCollegeModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Confirm Deletion</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="colleges.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="delete_college">
                            <input type="hidden" name="delete_college_id" id="delete_college_id">
                            <p>Are you sure you want to delete this team: <strong id="delete_college_name"></strong>?</p>
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
        
    <footer class="bg-dark text-white py-4">
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
            <small class="text-muted">Developed by Tsunayoshi Sawada</small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // EDIT MODAL SCRIPT
        const editCollegeModal = document.getElementById('editCollegeModal');
        editCollegeModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            
            document.getElementById('edit_college_id').value = button.dataset.id;
            document.getElementById('edit_college_name').value = button.dataset.name;
            document.getElementById('edit_college_code').value = button.dataset.code;
            
            // Updated to use new data attributes
            document.getElementById('edit_team_manager').value = button.dataset.manager;
            document.getElementById('edit_slogan').value = button.dataset.slogan;
            
            document.getElementById('current_logo_url').value = button.dataset.logo;
        });

        // DELETE MODAL SCRIPT
        const deleteCollegeModal = document.getElementById('deleteCollegeModal');
        deleteCollegeModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('delete_college_id').value = button.dataset.id;
            document.getElementById('delete_college_name').textContent = button.dataset.name;
        });

        // SIDEBAR/FOOTER LOGIC
        const sidebar = document.getElementById('sidebar');
        if (sidebar) { // Only run if sidebar exists (admin/SD)
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth > 992) {
                        sidebar.classList.remove('show');
                    }
                }, 250);
            });

            const footer = document.querySelector('footer');
            const navbar = document.querySelector('.navbar');

            if (footer && navbar) {
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
        }
    });
    </script>
</body>
</html>