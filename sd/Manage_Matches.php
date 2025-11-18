<?php
session_start();
// Check if the user is logged in and is an Administrator
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Administrator') {
    header('Location: ../login.php'); // Redirect to login
    exit();
}

// 1. --- DATABASE CONNECTION (MySQLi) ---
require_once '../db_connect.php'; // Note the '../' path

// Get admin's name for welcome message
$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin';
$current_page = basename($_SERVER['PHP_SELF']);

// --- This logic is for the sidebar accordion ---
$event_pages = ['Manage_Games.php', 'Manage_Game_Events.php', 'Manage_Categories.php'];
$is_event_page = in_array($current_page, $event_pages);

$management_pages = ['colleges.php', 'events.php', 'Manage_Matches.php', 'results.php', 'reports.php'];
$is_management_page = in_array($current_page, $management_pages);

// 2. --- PHP CRUD OPERATIONS ---

// --- CREATE MATCH ---
if (isset($_POST['add_match'])) {
    $category_id = $_POST['category_id'];
    $team1_id = $_POST['team1_id'];
    $team2_id = $_POST['team2_id'];
    $match_date = !empty($_POST['match_date']) ? $_POST['match_date'] : NULL;
    $match_time = !empty($_POST['match_time']) ? $_POST['match_time'] : NULL;
    $venue = $_POST['venue'];
    $managed_by = !empty($_POST['managed_by_user_id']) ? $_POST['managed_by_user_id'] : NULL;

    // Server-side validation
    if ($team1_id == $team2_id) {
        $_SESSION['message'] = "Error: Team 1 and Team 2 cannot be the same.";
        $_SESSION['msg_type'] = "danger";
    } else {
        $stmt = $conn->prepare("INSERT INTO matches (category_id, team1_id, team2_id, match_date, match_time, venue, managed_by_user_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Upcoming')");
        $stmt->bind_param("iissssi", $category_id, $team1_id, $team2_id, $match_date, $match_time, $venue, $managed_by);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "New match has been created successfully.";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['message'] = "Error creating match: " . $stmt->error;
            $_SESSION['msg_type'] = "danger";
        }
        $stmt->close();
    }
    header('Location: Manage_Matches.php');
    exit();
}

// --- UPDATE MATCH & RESULTS ---
if (isset($_POST['update_match'])) {
    $match_id = $_POST['match_id'];
    $category_id = $_POST['category_id'];
    $team1_id = $_POST['team1_id'];
    $team2_id = $_POST['team2_id'];
    $match_date = !empty($_POST['match_date']) ? $_POST['match_date'] : NULL;
    $match_time = !empty($_POST['match_time']) ? $_POST['match_time'] : NULL;
    $venue = $_POST['venue'];
    $managed_by = !empty($_POST['managed_by_user_id']) ? $_POST['managed_by_user_id'] : NULL;
    
    // Result fields
    $status = $_POST['status'];
    $score1 = (int)$_POST['score1'];
    $score2 = (int)$_POST['score2'];
    $winner_team_id = !empty($_POST['winner_team_id']) ? $_POST['winner_team_id'] : NULL;

    // Server-side validation
    if ($team1_id == $team2_id) {
        $_SESSION['message'] = "Error: Team 1 and Team 2 cannot be the same.";
        $_SESSION['msg_type'] = "danger";
    } else {
        $stmt = $conn->prepare("UPDATE matches SET 
            category_id = ?, team1_id = ?, team2_id = ?, match_date = ?, match_time = ?, venue = ?, 
            managed_by_user_id = ?, status = ?, score1 = ?, score2 = ?, winner_team_id = ?
            WHERE match_id = ?");
        $stmt->bind_param("iissssisiisi", 
            $category_id, $team1_id, $team2_id, $match_date, $match_time, $venue,
            $managed_by, $status, $score1, $score2, $winner_team_id, $match_id);

        if ($stmt->execute()) {
            $_SESSION['message'] = "Match details updated successfully.";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['message'] = "Error updating match: " . $stmt->error;
            $_SESSION['msg_type'] = "danger";
        }
        $stmt->close();
    }
    header('Location: Manage_Matches.php');
    exit();
}

// --- DELETE MATCH ---
if (isset($_GET['delete_id'])) {
    $match_id = $_GET['delete_id'];
    
    $stmt = $conn->prepare("DELETE FROM matches WHERE match_id = ?");
    $stmt->bind_param("i", $match_id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = "Match has been deleted successfully.";
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['message'] = "Error deleting match: " . $stmt->error;
        $_SESSION['msg_type'] = "danger";
    }
    $stmt->close();
    header('Location: Manage_Matches.php');
    exit();
}


// 3. --- DATA FETCHING FOR PAGE AND MODALS ---

// --- FETCH MATCHES (FIXED QUERY) ---
$matches = [];
$sql_matches = "
    SELECT 
        m.*, 
        c.category_name, 
        t1.college_name AS team1_name, 
        t2.college_name AS team2_name, 
        u.username AS manager_name
    FROM matches m
    JOIN categories c ON m.category_id = c.category_id
    LEFT JOIN colleges t1 ON m.team1_id = t1.college_id
    LEFT JOIN colleges t2 ON m.team2_id = t2.college_id
    LEFT JOIN users u ON m.managed_by_user_id = u.id
    ORDER BY m.match_date DESC, m.match_time DESC
";

$result_matches = $conn->query($sql_matches);
if ($result_matches) {
    while ($row = $result_matches->fetch_assoc()) {
        $matches[] = $row;
    }
}


// Fetch data for modal dropdowns
$categories = []; // L3 Events
$colleges = [];   // Teams
$managers = [];   // Event Managers

// Fetch Categories (L3 Events)
$result_cat = $conn->query("SELECT category_id, category_name FROM categories ORDER BY category_name");
while ($row = $result_cat->fetch_assoc()) $categories[] = $row;

// Fetch Colleges (Teams)
$result_col = $conn->query("SELECT college_id, college_name FROM colleges ORDER BY college_name");
while ($row = $result_col->fetch_assoc()) $colleges[] = $row;

// Fetch Event Managers (Users)
$result_mgr = $conn->query("SELECT id AS user_id, username FROM users WHERE role = 'Event Manager' ORDER BY username");

while ($row = $result_mgr->fetch_assoc()) $managers[] = $row;


// Helper function for status badges
function getStatusBadge($status) {
    switch (strtolower($status)) {
        case 'upcoming':
            return '<span class="badge bg-info">Upcoming</span>';
        case 'ongoing':
            return '<span class="badge bg-success">Ongoing</span>';
        case 'completed':
            return '<span class="badge bg-secondary">Completed</span>';
        case 'cancelled':
            return '<span class="badge bg-danger">Cancelled</span>';
        default:
            return '<span class="badge bg-light text-dark">' . htmlspecialchars($status) . '</span>';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Matches - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* All styles from admin_dashboard.php */
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
        
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; transition: var(--transition); }
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
        .user-dropdown .dropdown-toggle img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,0.3); margin-right: 10px; }
        .user-dropdown .dropdown-toggle .user-name { font-weight: 600; font-size: 0.95rem; }
        .user-dropdown .dropdown-menu { border: none; box-shadow: var(--shadow-md); border-radius: 10px; padding: 0.5rem 0; margin-top: 10px !important; }
        .user-dropdown .dropdown-item { display: flex; align-items: center; padding: 0.75rem 1.25rem; font-weight: 500; color: #333; font-size: 0.9rem; }
        .user-dropdown .dropdown-item i { width: 20px; margin-right: 10px; color: var(--text-muted); }

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

        .sidebar-nav .nav-link .sidebar-chevron {
            font-size: 0.7rem;
            margin-left: auto; /* Push chevron to the right */
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
            padding-left: 0; /* Remove default padding */
            margin: 0;
            list-style: none;
            background-color: rgba(0,0,0,0.15);
        }
        .sidebar-nav .sub-menu .nav-item {
            width: 100%;
        }
        .sidebar-nav .sub-menu .nav-link {
            padding: 12px 25px 12px 60px; /* Indent sub-items */
            font-size: 0.95rem;
            font-weight: 400;
            border-left: 5px solid transparent; /* Reset border */
            margin: 0;
        }
        .sidebar-nav .sub-menu .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: #1abc9c;
        }
        .sidebar-nav .sub-menu .nav-link.active {
            color: #1abc9c; /* Active color for sub-item */
            border-left-color: #1abc9c;
            background-color: rgba(0,0,0,0.1);
            font-weight: 500;
        }
        
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
        .hero-section { background: var(--primary-gradient); color: white; padding: 40px 30px; margin-bottom: 30px; border-radius: 15px; box-shadow: 0 8px 25px rgba(116, 81, 235, 0.3); position: relative; overflow: hidden; }
        .hero-title { font-size: 2rem; font-weight: 700; margin-bottom: 5px; }
        .hero-subtitle { font-size: 1rem; opacity: 0.9; font-weight: 300; }
        
        /* Footer */
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
        
        .sidebar-overlay { display: none; position: fixed; top: var(--header-height); left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1030; }
        .sidebar-overlay.show { display: block; }
        @media (max-width: 992px) {
            .sidebar { width: 260px; left: -260px; top: var(--header-height); height: calc(100vh - var(--header-height)); transition: left 0.3s ease; z-index: 1045; }
            .sidebar.show { left: 0; }
            .main-content { margin-left: 0; padding: 20px; }
            .sidebar.minimized ~ .main-content { margin-left: 0; }
            #sidebarToggle { display: none; }
            footer { padding-left: 0; }
            .sidebar.minimized ~ footer { margin-left: 0; }
        }
        @media (max-width: 576px) {
            .main-content { padding: 15px; }
            .hero-title { font-size: 1.5rem; }
            .hero-section { padding: 30px 20px; }
            .user-dropdown .dropdown-toggle .user-name { display: none; }
            .user-dropdown .dropdown-toggle img { margin-right: 0; }
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

        /* Page-specific Styles */
        .page-card {
            background: white;
            border: none;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
        }
        .page-card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            padding: 1.25rem 1.5rem;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }
        .page-card-header h4 {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
        }
        .table-responsive {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            background: white;
        }
        .table-responsive .table {
            margin-bottom: 0;
            font-size: 0.9rem;
        }
        .table-responsive thead th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            white-space: nowrap;
        }
        .table-responsive tbody tr:hover {
            background-color: #f1f3f5;
        }
        .table-responsive .badge {
            font-size: 0.8rem;
        }
        .table-action-btn {
            width: 35px;
            height: 35px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
        }
        .matchup-cell {
            line-height: 1.4;
        }
        .matchup-cell strong {
            font-size: 1rem;
            color: var(--text-dark);
        }
        .matchup-cell small {
            font-size: 0.8rem;
        }
        .score-cell {
            font-size: 1.1rem;
            font-weight: 700;
            font-family: 'Poppins', sans-serif;
            white-space: nowrap;
        }
    </style>
</head>
<body>
    
    <nav class="navbar navbar-dark bg-dark"> 
        <div class="container-fluid d-flex align-items: center justify-content-between">
            <!-- Corrected paths to root -->
            <a class="navbar-brand d-flex align-items-center" href="../admin_dashboard.php" style="cursor: pointer;">
                <img src="../imageslogo.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain;">
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
                    <!-- Corrected paths to root -->
                    <li><a class="dropdown-item" href="../admin_profile.php"><i class="fas fa-user-circle"></i> Profile</a></li>
                    <li><a class="dropdown-item" href="../Tournament_Manager_page.php" target="_blank"><i class="fas fa-globe"></i> View Public Site</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../login.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="sidebar" id="sidebar">
        <button id="sidebarToggle" title="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
        
        <ul class="nav flex-column sidebar-nav">
            <!-- Corrected paths to root -->
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
                    <i class="fas fa-users me-2"></i> <span>Manage Colleges/Events</span> <i class="fas fa-chevron-down ms-auto sidebar-chevron"></i>
                </a>
                
                <div class="collapse <?php if ($is_management_page) echo 'show'; ?>" id="teamsCollapse">
                    <ul class="sub-menu">
                        
                        <li class="text-muted" style="padding: 10px 25px 5px 60px; margin-top: 5px; font-size: 0.75rem; font-weight: 600; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px;">
                            Management
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'colleges.php') echo 'active'; ?>" href="colleges.php">
                                <span>Manage Colleges</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'events.php') echo 'active'; ?>" href="events.php">
                                <span>Manage Events (L1-L3)</span>
                            </a>
                        </li>
                        
                        <!-- This is the current page, so it's active -->
                        <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'Manage_Matches.php') echo 'active'; ?>" href="Manage_Matches.php">
                                <span>Manage Matches</span>
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
            <!-- Corrected paths to root -->
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
                <a class="nav-link text-danger" href="../login.php">
                    <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
    
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-content">
        <div class="hero-section">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="hero-title">Match Management</h1>
                    <p class="hero-subtitle">Create, update, and manage all matches and results.</p>
                </div>
                <div class="d-none d-md-block text-end">
                    <i class="fas fa-trophy fa-4x" style="opacity: 0.3;"></i>
                </div>
            </div>
        </div>

        <div class="container-fluid p-0">
            
            <!-- Session Message Alerts -->
            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-<?php echo $_SESSION['msg_type']; ?> alert-dismissible fade show" role="alert">
                    <?php 
                        echo $_SESSION['message']; 
                        unset($_SESSION['message']);
                        unset($_SESSION['msg_type']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="page-card">
                <div class="page-card-header d-flex justify-content-between align-items-center">
                    <h4>All Matches</h4>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMatchModal">
                        <i class="fas fa-plus me-2"></i>Create New Match
                    </button>
                </div>
                <div class="card-body p-4">
                    <!-- Filters -->
                    <div class="row g-3 mb-4">
                        <div class="col-lg-8">
                            <label for="searchInput" class="form-label small">Search Match (Event, Team, Venue...)</label>
                            <input type="text" class="form-control" id="searchInput" placeholder="Type to search...">
                        </div>
                        <div class="col-lg-4">
                            <label for="statusFilter" class="form-label small">Filter by Status</label>
                            <select class="form-select" id="statusFilter">
                                <option value="all">All Statuses</option>
                                <option value="Upcoming">Upcoming</option>
                                <option value="Ongoing">Ongoing</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>

                    <!-- Matches Table -->
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="matchesTable">
                            <thead>
                                <tr>
                                    <th>Event (L3)</th>
                                    <th>Matchup</th>
                                    <th class="text-center">Score</th>
                                    <th class="text-center">Status</th>
                                    <th>Date & Time</th>
                                    <th>Venue</th>
                                    <th>Manager</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($matches)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted p-4">No matches found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($matches as $match): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($match['category_name']) ?></strong>
                                            </td>
                                            <td class="matchup-cell">
                                                <strong><?= htmlspecialchars($match['team1_name']) ?></strong>
                                                <small class="text-muted d-block">vs</small>
                                                <strong><?= htmlspecialchars($match['team2_name']) ?></strong>
                                            </td>
                                            <td class="text-center score-cell">
                                                <?= htmlspecialchars($match['score1']) ?> - <?= htmlspecialchars($match['score2']) ?>
                                            </td>
                                            <td class="text-center">
                                                <?= getStatusBadge($match['status']) ?>
                                            </td>
                                            <td>
                                                <?= $match['match_date'] ? htmlspecialchars(date('M d, Y', strtotime($match['match_date']))) : 'TBA' ?>
                                                <small class="text-muted d-block"><?= $match['match_time'] ? htmlspecialchars(date('g:i A', strtotime($match['match_time']))) : '' ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($match['venue']) ?></td>
                                            <td><?= htmlspecialchars($match['manager_name'] ?? 'N/A') ?></td>
                                            <td class="text-end">
                                                <button class="btn btn-sm btn-primary table-action-btn" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editMatchModal"
                                                    data-match-id="<?= $match['match_id'] ?>"
                                                    data-category-id="<?= $match['category_id'] ?>"
                                                    data-team1-id="<?= $match['team1_id'] ?>"
                                                    data-team2-id="<?= $match['team2_id'] ?>"
                                                    data-match-date="<?= $match['match_date'] ?>"
                                                    data-match-time="<?= $match['match_time'] ?>"
                                                    data-venue="<?= htmlspecialchars($match['venue']) ?>"
                                                    data-status="<?= $match['status'] ?>"
                                                    data-score1="<?= $match['score1'] ?>"
                                                    data-score2="<?= $match['score2'] ?>"
                                                    data-winner-team-id="<?= $match['winner_team_id'] ?>"
                                                    data-manager-id="<?= $match['managed_by_user_id'] ?>"
                                                    title="Edit Match & Results">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="Manage_Matches.php?delete_id=<?= $match['match_id'] ?>" 
                                                   class="btn btn-sm btn-danger table-action-btn" 
                                                   title="Delete Match"
                                                   onclick="return confirm('Are you sure you want to delete this match? This action cannot be undone.')">
                                                   <i class="fas fa-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
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

    <!-- =================================== -->
    <!-- MODALS -->
    <!-- =================================== -->

    <!-- Add Match Modal -->
    <div class="modal fade" id="addMatchModal" tabindex="-1" aria-labelledby="addMatchModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addMatchModalLabel">Create New Match</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="Manage_Matches.php">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label for="add_category_id" class="form-label">Event (L3)</label>
                                <select class="form-select" id="add_category_id" name="category_id" required>
                                    <option value="" disabled selected>Select an event</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= $category['category_id'] ?>"><?= htmlspecialchars($category['category_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="add_team1_id" class="form-label">Team 1 (College)</label>
                                <select class="form-select" id="add_team1_id" name="team1_id" required>
                                    <option value="" disabled selected>Select Team 1</option>
                                    <?php foreach ($colleges as $college): ?>
                                        <option value="<?= $college['college_id'] ?>"><?= htmlspecialchars($college['college_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="add_team2_id" class="form-label">Team 2 (College)</label>
                                <select class="form-select" id="add_team2_id" name="team2_id" required>
                                    <option value="" disabled selected>Select Team 2</option>
                                    <?php foreach ($colleges as $college): ?>
                                        <option value="<?= $college['college_id'] ?>"><?= htmlspecialchars($college['college_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="add_match_date" class="form-label">Match Date</label>
                                <input type="date" class="form-control" id="add_match_date" name="match_date">
                            </div>
                            <div class="col-md-6">
                                <label for="add_match_time" class="form-label">Match Time</label>
                                <input type="time" class="form-control" id="add_match_time" name="match_time">
                            </div>
                            <div class="col-md-12">
                                <label for="add_venue" class="form-label">Venue</label>
                                <input type="text" class="form-control" id="add_venue" name="venue" placeholder="e.g., University Gymnasium">
                            </div>
                            <div class="col-md-12">
                                <label for="add_managed_by_user_id" class="form-label">Event Manager (Optional)</label>
                                <select class="form-select" id="add_managed_by_user_id" name="managed_by_user_id">
                                    <option value="">None</option>
                                    <?php foreach ($managers as $manager): ?>
                                        <option value="<?= $manager['user_id'] ?>"><?= htmlspecialchars($manager['username']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary" name="add_match">Create Match</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Match Modal -->
    <div class="modal fade" id="editMatchModal" tabindex="-1" aria-labelledby="editMatchModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editMatchModalLabel">Edit Match & Results</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="Manage_Matches.php">
                    <input type="hidden" name="match_id" id="edit_match_id">
                    <div class="modal-body">
                        <div class="row g-4">
                            <!-- Column 1: Match Details -->
                            <div class="col-lg-7">
                                <h5>Match Details</h5>
                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <label for="edit_category_id" class="form-label">Event (L3)</label>
                                        <select class="form-select" id="edit_category_id" name="category_id" required>
                                            <?php foreach ($categories as $category): ?>
                                                <option value="<?= $category['category_id'] ?>"><?= htmlspecialchars($category['category_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="edit_team1_id" class="form-label">Team 1 (College)</label>
                                        <select class="form-select" id="edit_team1_id" name="team1_id" required>
                                            <?php foreach ($colleges as $college): ?>
                                                <option value="<?= $college['college_id'] ?>"><?= htmlspecialchars($college['college_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="edit_team2_id" class="form-label">Team 2 (College)</label>
                                        <select class="form-select" id="edit_team2_id" name="team2_id" required>
                                            <?php foreach ($colleges as $college): ?>
                                                <option value="<?= $college['college_id'] ?>"><?= htmlspecialchars($college['college_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="edit_match_date" class="form-label">Match Date</label>
                                        <input type="date" class="form-control" id="edit_match_date" name="match_date">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="edit_match_time" class="form-label">Match Time</label>
                                        <input type="time" class="form-control" id="edit_match_time" name="match_time">
                                    </div>
                                    <div class="col-md-12">
                                        <label for="edit_venue" class="form-label">Venue</label>
                                        <input type="text" class="form-control" id="edit_venue" name="venue">
                                    </div>
                                    <div class="col-md-12">
                                        <label for="edit_managed_by_user_id" class="form-label">Event Manager (Optional)</label>
                                        <select class="form-select" id="edit_managed_by_user_id" name="managed_by_user_id">
                                            <option value="">None</option>
                                            <?php foreach ($managers as $manager): ?>
                                                <option value="<?= $manager['user_id'] ?>"><?= htmlspecialchars($manager['username']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Column 2: Results -->
                            <div class="col-lg-5" style="border-left: 1px solid #dee2e6;">
                                <h5>Match Results</h5>
                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <label for="edit_status" class="form-label">Match Status</label>
                                        <select class="form-select" id="edit_status" name="status" required>
                                            <option value="Upcoming">Upcoming</option>
                                            <option value="Ongoing">Ongoing</option>
                                            <option value="Completed">Completed</option>
                                            <option value="Cancelled">Cancelled</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="edit_score1" class="form-label">Team 1 Score</label>
                                        <input type="number" class="form-control" id="edit_score1" name="score1" value="0" min="0">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="edit_score2" class="form-label">Team 2 Score</label>
                                        <input type="number" class="form-control" id="edit_score2" name="score2" value="0" min="0">
                                    </div>
                                    <div class="col-md-12">
                                        <label for="edit_winner_team_id" class="form-label">Winner (if Completed)</label>
                                        <select class="form-select" id="edit_winner_team_id" name="winner_team_id">
                                            <option value="">None / Draw</option>
                                            <!-- Options will be dynamically populated by JS based on selected teams -->
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary" name="update_match">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


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
            
            // --- Sidebar Toggle Logic (from admin_dashboard) ---
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            
            if (window.innerWidth > 992 && sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('minimized');
                });
            }
            if (mobileMenuToggle) {
                mobileMenuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                    sidebarOverlay.classList.toggle('show');
                });
            }
            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', function() {
                    sidebar.classList.remove('show');
                    sidebarOverlay.classList.remove('show');
                });
            }
            
            // --- Edit Modal Auto-population ---
            const editMatchModal = document.getElementById('editMatchModal');
            if (editMatchModal) {
                editMatchModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    
                    // Extract data from 'data-*' attributes
                    const matchId = button.dataset.matchId;
                    const categoryId = button.dataset.categoryId;
                    const team1Id = button.dataset.team1Id;
                    const team2Id = button.dataset.team2Id;
                    const matchDate = button.dataset.matchDate;
                    const matchTime = button.dataset.matchTime;
                    const venue = button.dataset.venue;
                    const status = button.dataset.status;
                    const score1 = button.dataset.score1;
                    const score2 = button.dataset.score2;
                    const winnerTeamId = button.dataset.winnerTeamId;
                    const managerId = button.dataset.managerId;

                    // Get team names from the dropdown options
                    const team1Select = document.getElementById('edit_team1_id');
                    const team2Select = document.getElementById('edit_team2_id');
                    const team1Name = team1Select.querySelector(`option[value="${team1Id}"]`)?.textContent || 'Team 1';
                    const team2Name = team2Select.querySelector(`option[value="${team2Id}"]`)?.textContent || 'Team 2';

                    // Populate the modal fields
                    document.getElementById('edit_match_id').value = matchId;
                    document.getElementById('edit_category_id').value = categoryId;
                    document.getElementById('edit_team1_id').value = team1Id;
                    document.getElementById('edit_team2_id').value = team2Id;
                    document.getElementById('edit_match_date').value = matchDate;
                    document.getElementById('edit_match_time').value = matchTime;
                    document.getElementById('edit_venue').value = venue;
                    document.getElementById('edit_status').value = status;
                    document.getElementById('edit_score1').value = score1;
                    document.getElementById('edit_score2').value = score2;
                    document.getElementById('edit_managed_by_user_id').value = managerId;
                    
                    // Dynamically populate the Winner dropdown based on the two teams
                    const winnerSelect = document.getElementById('edit_winner_team_id');
                    winnerSelect.innerHTML = '<option value="">None / Draw</option>'; // Clear existing
                    winnerSelect.innerHTML += `<option value="${team1Id}">${team1Name}</option>`;
                    winnerSelect.innerHTML += `<option value="${team2Id}">${team2Name}</option>`;
                    
                    // Set the selected winner
                    winnerSelect.value = winnerTeamId;
                });
            }

            // --- Live Search and Filter Logic ---
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const tableBody = document.getElementById('matchesTable').querySelector('tbody');
            const allRows = tableBody.querySelectorAll('tr');

            function filterTable() {
                const searchText = searchInput.value.toLowerCase();
                const statusValue = statusFilter.value;

                allRows.forEach(row => {
                    const rowText = row.textContent.toLowerCase();
                    const rowStatus = row.querySelector('td:nth-child(4) .badge').textContent.trim();
                    
                    const matchesSearch = rowText.includes(searchText);
                    const matchesStatus = (statusValue === 'all' || rowStatus === statusValue);

                    if (matchesSearch && matchesStatus) {
                        row.style.display = ''; // Show row
                    } else {
                        row.style.display = 'none'; // Hide row
                    }
                });
            }

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

            // --- ### NEW: FIX SIDEBAR/FOOTER OVERLAP ### ---
            const footer = document.querySelector('footer');
            const navbar = document.querySelector('.navbar');

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
                    
                    // 1. Calculate the max possible height (navbar top to viewport bottom)
                    const maxSidebarHeight = viewportHeight - navbarHeight;

                    // 2. Calculate the available height (navbar top to footer top)
                    const availableHeight = footerTop - navbarHeight;

                    // 3. Choose the smaller of the two heights, but never less than 0
                    const newHeight = Math.max(0, Math.min(maxSidebarHeight, availableHeight));
                    
                    // 4. Apply the new height as an inline style
                    sidebar.style.height = `${newHeight}px`;
                }

                // Add listeners for scroll and resize events
                window.addEventListener('scroll', adjustSidebarHeight, { passive: true });
                window.addEventListener('resize', adjustSidebarHeight);
                
                // Initial call to set the correct height on page load
                // Small delay to ensure all elements are rendered
                setTimeout(adjustSidebarHeight, 100);
            }

            searchInput.addEventListener('keyup', filterTable);
            statusFilter.addEventListener('change', filterTable);

        });
    </script>
</body>
</html>