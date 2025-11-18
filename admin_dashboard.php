<?php
session_start();
// Check if the user is logged in, if not, redirect to the login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Administrator') {
    header('Location: login.php');
    exit();
}

// 1. --- DATABASE CONNECTION (MySQLi) ---
require_once 'db_connect.php'; 

// Get admin's name for welcome message
$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin';
$current_page = basename($_SERVER['PHP_SELF']);

// --- This logic is for the sidebar accordion ---
$event_pages = ['Manage_Games.php', 'Manage_Game_Events.php', 'Manage_Categories.php'];
$is_event_page = in_array($current_page, $event_pages);

// ### MODIFIED ### - Added Manage_Matches.php to the management pages
$management_pages = ['colleges.php', 'events.php', 'Manage_Matches.php', 'results.php', 'reports.php'];
$is_management_page = in_array($current_page, $management_pages);


// 2. --- DYNAMIC STATS & WIDGETS (MySQLi) ---
$stats = [
    'total_events' => 0, 'total_colleges' => 0, 
    'total_users' => 0, 'pending_requests' => 0,
    'total_matches' => 0, 'ongoing_matches' => 0 // ### NEW ###
];

// Helper function for fetching a single count
function fetchCount($conn, $query) {
    $result = $conn->query($query);
    if ($result) {
        return $result->fetch_assoc()['count'];
    }
    return 0; // Return 0 on error
}

// Fetch live data for stat cards
$stats['total_events'] = fetchCount($conn, "SELECT COUNT(*) as count FROM categories"); // L3 categories
$stats['total_colleges'] = fetchCount($conn, "SELECT COUNT(*) as count FROM colleges"); //
$stats['total_users'] = fetchCount($conn, "SELECT COUNT(*) as count FROM users"); //
$stats['pending_requests'] = fetchCount($conn, "SELECT COUNT(*) as count FROM account_requests WHERE status = 'pending'"); //

// ### NEW ### - Fetch Match Statistics
$stats['total_matches'] = fetchCount($conn, "SELECT COUNT(*) as count FROM matches");
$stats['ongoing_matches'] = fetchCount($conn, "SELECT COUNT(*) as count FROM matches WHERE status = 'Ongoing'");


// 3. --- ### NEW ### FETCH RECENT MATCH ACTIVITY ---
$recent_matches = [];
// This query fetches the last 5 matches and joins with categories (L3) and colleges
$sql_matches = "SELECT m.match_id, m.status, m.match_date, m.match_time,
                    c.category_name as event_name,
                    t1.college_name as team1_name,
                    t2.college_name as team2_name
                FROM matches m
                JOIN categories c ON m.category_id = c.category_id
                LEFT JOIN colleges t1 ON m.team1_id = t1.college_id
                LEFT JOIN colleges t2 ON m.team2_id = t2.college_id
                ORDER BY m.match_date DESC, m.match_time DESC
                LIMIT 5";

$result_matches = $conn->query($sql_matches);
if ($result_matches) {
    while ($row = $result_matches->fetch_assoc()) {
        $recent_matches[] = $row;
    }
}

// ### NEW ### - Helper function for status badges
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
    <title>Admin Dashboard - PIT SPORTS TALLYING</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ... (Your existing CSS) ... */
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

        /* --- Accordion CSS --- */
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
        /* --- End of Accordion Styles --- */
        
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
        .hero-section { background: var(--primary-gradient); color: white; padding: 40px 30px; margin-bottom: 30px; border-radius: 15px; box-shadow: 0 8px 25px rgba(116, 81, 235, 0.3); position: relative; overflow: hidden; }
        .hero-title { font-size: 2rem; font-weight: 700; margin-bottom: 5px; }
        .hero-subtitle { font-size: 1rem; opacity: 0.9; font-weight: 300; }
        .welcome-badge { background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(5px); border: 1px solid rgba(255, 255, 255, 0.2); padding: 4px 10px; border-radius: 20px; font-weight: 600; font-size: 0.85rem; display: inline-block; margin-bottom: 10px; }
        .clickable-card-link { text-decoration: none; display: block; height: 100%; color: inherit; }
        .stat-card { background: white; border: none; border-radius: 15px; padding: 20px; box-shadow: var(--card-shadow); transition: var(--transition); position: relative; overflow: hidden; height: 100%; }
        .clickable-card-link:hover .stat-card { transform: translateY(-5px); box-shadow: var(--card-hover-shadow); }
        .stat-icon-wrapper { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem; margin-right: 15px; flex-shrink: 0; }
        .stat-count { font-size: 2.5rem; font-weight: 700; line-height: 1; margin-bottom: 5px; font-family: 'Poppins', sans-serif; }
        .stat-events .stat-icon-wrapper { background: linear-gradient(45deg, #3498db, #2980b9); }
        .stat-teams .stat-icon-wrapper { background: linear-gradient(45deg, #e74c3c, #c0392b); }
        .stat-users .stat-icon-wrapper { background: linear-gradient(45deg, #2ecc71, #27ae60); }
        .stat-requests .stat-icon-wrapper { background: linear-gradient(45deg, #f1c40f, #f39c12); }
        .quick-action-card { border: none; border-radius: 15px; transition: var(--transition); box-shadow: var(--card-shadow); background: white; height: 100%; text-align: center; padding: 20px; }
        .quick-action-card:hover { transform: translateY(-5px); box-shadow: var(--card-hover-shadow); }
        .quick-action-card a { text-decoration: none; }
        .quick-icon { font-size: 2rem; margin-bottom: 10px; }
        .icon-teams-quick { color: #e74c3c; }
        .icon-users-quick { color: #2ecc71; }
        .icon-reports-quick { color: #9b55b6; }
        .icon-requests-quick { color: #f39c12; }
        
        /* Footer */
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
        
        /* Mobile */
        .sidebar-overlay { display: none; position: fixed; top: var(--header-height); left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1030; }
        .sidebar-overlay.show { display: block; }
        @media (max-width: 992px) {
            .sidebar { width: 260px; left: -260px; top: var(--header-height); height: calc(100vh - var(--header-height)); transition: left 0.3s ease; z-index: 1045; }
            .sidebar.show { left: 0; }
            .main-content { margin-left: 0; padding: 20px; }
            .sidebar.minimized ~ .main-content { margin-left: 0; }
            #sidebarToggle { display: none; }
            footer { padding-left: 0;
            .sidebar.minimized ~ footer { margin-left: 0; }
        }}
        @media (max-width: 576px) {
            .main-content { padding: 15px; }
            .hero-title { font-size: 1.5rem; }
            .hero-section { padding: 30px 20px; }
            .stat-card .d-flex { flex-direction: column; align-items: center !important; text-align: center; }
            .stat-icon-wrapper { margin-right: 0; margin-bottom: 10px; }
            .stat-count { font-size: 2rem; }
            .user-dropdown .dropdown-toggle .user-name { display: none; }
            .user-dropdown .dropdown-toggle img { margin-right: 0; }
        }
        .hero-settings-link { text-decoration: none; color: white; opacity: 0.5; transition: var(--transition); display: inline-block; }
        .hero-settings-link:hover { opacity: 1; transform: scale(1.1); }
        .hero-settings-link .fa-users-cog { transition: var(--transition); }
        .hero-settings-link:hover .fa-users-cog { transform: rotate(15deg); }

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

        /* ### NEW ### - Style for the recent matches table */
        .table-responsive {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            background: white;
        }
        .table-responsive .table {
            margin-bottom: 0; /* Remove default bottom margin */
        }
        .table-responsive thead th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .table-responsive tbody tr:hover {
            background-color: #f1f3f5;
        }
        .table-responsive .badge {
            font-size: 0.8rem;
        }
        /* ### NEW ### - New stat card color */
        .stat-matches .stat-icon-wrapper { background: linear-gradient(45deg, #5e72e4, #324cdd); }
        .stat-ongoing .stat-icon-wrapper { background: linear-gradient(45deg, #2dce89, #2dce89); }
        .icon-matches-quick { color: #5e72e4; }
    </style>
</head>
<body>
    
    <nav class="navbar navbar-dark bg-dark"> <div class="container-fluid d-flex align-items: center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="admin_dashboard.php" style="cursor: pointer;">
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
                    <li><a class="dropdown-item text-danger" href="login.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
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
    
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-content"> <div class="hero-section"> <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="welcome-badge">
                        <i class="fas fa-shield-alt me-2"></i>Admin Access
                    </div>
                    <h1 class="hero-title">Welcome Back, <?= htmlspecialchars($name); ?>!</h1>
                    <p class="hero-subtitle">Your central hub for tournament oversight and management.</p>
                </div>
                <div class="d-none d-md-block text-end">
                    <a href="admin_profile.php" class="hero-settings-link" title="Account Settings">
                        <i class="fas fa-users-cog fa-4x"></i>
                    </a>
                </div>
            </div>
        </div>

        <div class="container-fluid p-0">
            
            <h2 class="section-title mb-4">Live Tournament Snapshot</h2>
            
            <div class="row g-4 mb-4">
                
                <div class="col-lg-4 col-md-6"> <a href="Manage_Categories.php" class="clickable-card-link">
                        <div class="stat-card stat-events">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon-wrapper">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <div>
                                    <div class="stat-count text-primary"><?= $stats['total_events'] ?></div>
                                    <small class="text-muted">Total Events (L3)</small>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="col-lg-4 col-md-6"> <a href="sd/colleges.php" class="clickable-card-link">
                        <div class="stat-card stat-teams">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon-wrapper">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div>
                                    <div class="stat-count text-danger"><?= $stats['total_colleges'] ?></div>
                                    <small class="text-muted">Participating Colleges</small>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="col-lg-4 col-md-6"> <a href="Manage_Users.php" class="clickable-card-link">
                        <div class="stat-card stat-users">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon-wrapper">
                                    <i class="fas fa-users-cog"></i>
                                </div>
                                <div>
                                    <div class="stat-count text-success"><?= $stats['total_users'] ?></div>
                                    <small class="text-muted">Total Users</small>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            </div> <div class="row g-4 mb-5">
                <div class="col-lg-4 col-md-6"> <a href="Manage_Requests.php" class="clickable-card-link">
                        <div class="stat-card stat-requests">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon-wrapper">
                                    <i class="fas fa-user-plus"></i>
                                </div>
                                <div>
                                    <div class="stat-count text-warning"><?= $stats['pending_requests'] ?></div>
                                    <small class="text-muted">Pending Requests</small>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="col-lg-4 col-md-6">
                    <a href="sd/Manage_Matches.php" class="clickable-card-link">
                        <div class="stat-card stat-matches">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon-wrapper">
                                    <i class="fas fa-trophy"></i>
                                </div>
                                <div>
                                    <div class="stat-count text-primary"><?= $stats['total_matches'] ?></div>
                                    <small class="text-muted">Total Matches</small>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="col-lg-4 col-md-6">
                    <a href="sd/Manage_Matches.php" class="clickable-card-link">
                        <div class="stat-card stat-ongoing">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon-wrapper">
                                    <i class="fas fa-broadcast-tower"></i>
                                </div>
                                <div>
                                    <div class="stat-count text-success"><?= $stats['ongoing_matches'] ?></div>
                                    <small class="text-muted">Ongoing Matches</small>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            </div>
            
            <h2 class="section-title mb-4">Quick Actions & Navigation</h2>
            
            <div class="row g-4 mb-5 row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-5">
                
                <div class="col"> <div class="quick-action-card">
                        <a href="sd/colleges.php">
                            <i class="fas fa-users quick-icon icon-teams-quick"></i>
                            <h5 class="fw-bold mb-1 text-dark">Colleges</h5>
                            <small class="text-muted">Roster & Details</small>
                        </a>
                    </div>
                </div>
                
                <div class="col">
                    <div class="quick-action-card">
                        <a href="sd/Manage_Matches.php">
                            <i class="fas fa-trophy quick-icon icon-matches-quick"></i>
                            <h5 class="fw-bold mb-1 text-dark">Matches</h5>
                            <small class="text-muted">Create & Update</small>
                        </a>
                    </div>
                </div>
                
                <div class="col"> <div class="quick-action-card">
                        <a href="Manage_Users.php">
                            <i class="fas fa-users-cog quick-icon icon-users-quick"></i>
                            <h5 class="fw-bold mb-1 text-dark">Users</h5>
                            <small class="text-muted">Create & Manage</small>
                        </a>
                    </div>
                </div>
                
                <div class="col"> <div class="quick-action-card">
                        <a href="Manage_Requests.php">
                            <i class="fas fa-user-plus quick-icon icon-requests-quick"></i>
                            <h5 class="fw-bold mb-1 text-dark">Requests</h5>
                            <small class="text-muted">Approve & Deny</small>
                        </a>
                    </div>
                </div>
                
                <div class="col"> <div class="quick-action-card">
                        <a href="Manage_Viewreports.php">
                            <i class="fas fa-chart-line quick-icon icon-reports-quick"></i>
                            <h5 class="fw-bold mb-1 text-dark">Reports</h5>
                            <small class="text-muted">Insights & Analytics</small>
                        </a>
                    </div>
                </div>
            </div>
            
            <h2 class="section-title mb-4">Recent Match Activity</h2>
            <div class="row">
                <div class="col-12">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Event (L3)</th>
                                    <th>Matchup</th>
                                    <th class="text-center">Status</th>
                                    <th>Date & Time</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_matches)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted p-4">
                                            No matches found.
                                            <a href="sd/Manage_Matches.php" class="btn btn-primary btn-sm ms-3">Create a Match</a>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_matches as $match): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($match['event_name']) ?></strong>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($match['team1_name']) ?>
                                                <span class="text-muted mx-1">vs</span>
                                                <?= htmlspecialchars($match['team2_name']) ?>
                                            </td>
                                            <td class="text-center">
                                                <?= getStatusBadge($match['status']) ?>
                                            </td>
                                            <td>
                                                <?= $match['match_date'] ? htmlspecialchars(date('M d, Y', strtotime($match['match_date']))) : 'TBA' ?>
                                                <small class="text-muted d-block"><?= $match['match_time'] ? htmlspecialchars(date('g:i A', strtotime($match['match_time']))) : '' ?></small>
                                            </td>
                                            <td>
                                                <a href="sd/Manage_Matches.php?edit_id=<?= $match['match_id'] ?>" class="btn btn-sm btn-outline-primary">
                                                    Manage
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

            </div> </div>

    <footer class="bg-dark text-white py-4"> <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
            <small class="text-muted">Developed by Tsunayoshi Sawada</small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            
            // --- NEW: Smooth Fade-in Effect ---
            setTimeout(() => {
                const mainContent = document.querySelector('.main-content');
                if(mainContent) {
                    mainContent.style.opacity = '1';
                    mainContent.style.transform = 'translateY(0)';
                }
            }, 50); // 50ms delay
            
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
                // Don't close sidebar if clicking the accordion toggle
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
            // --- ### END OF FIX ### ---

        });
        
    </script>
</body>
</html>