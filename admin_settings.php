<?php
session_start();

// 1. --- SECURITY & DATABASE CONNECTION (MySQLi) ---
// This file should create a mysqli object named $conn
require_once 'config.php'; 

// Strict Role-Based Access Control
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Administrator') {
    // Redirect to login page if not a logged-in Administrator
    header('Location: login.php');
    exit();
}

// Get admin's name for welcome message
$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin';
$current_page = basename($_SERVER['PHP_SELF']);

// 2. --- DYNAMIC STATS & WIDGETS (MySQLi) ---
$stats = [
    'total_events' => 0, 'total_teams' => 0,
    'total_users' => 0, 'pending_requests' => 0
];
$recent_logs = [];
$pending_requests_list = [];

// Helper function for fetching a single count
function fetchCount($conn, $query) {
    $result = $conn->query($query);
    if ($result) {
        return $result->fetch_assoc()['count'];
    }
    return 0; // Return 0 on error
}

// Fetch live data for stat cards
$stats['total_events'] = fetchCount($conn, "SELECT COUNT(*) as count FROM events");
$stats['total_teams'] = fetchCount($conn, "SELECT COUNT(*) as count FROM teams");
$stats['total_users'] = fetchCount($conn, "SELECT COUNT(*) as count FROM users");
$stats['pending_requests'] = fetchCount($conn, "SELECT COUNT(*) as count FROM account_requests WHERE status = 'pending'");

// Fetch recent activity logs
$log_query = "SELECT log, created_at, user_username 
              FROM system_logs 
              ORDER BY created_at DESC 
              LIMIT 5";
$log_result = $conn->query($log_query);
if ($log_result) {
    $recent_logs = $log_result->fetch_all(MYSQLI_ASSOC);
}

// Fetch pending account requests
$request_query = "SELECT request_id, full_name, username, requested_role 
                  FROM account_requests 
                  WHERE status = 'pending' 
                  ORDER BY created_at DESC 
                  LIMIT 5";
$request_result = $conn->query($request_query);
if ($request_result) {
    $pending_requests_list = $request_result->fetch_all(MYSQLI_ASSOC);
}

// Note: In a real app, you'd add more robust error handling for these queries.
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
        /* --- PIXEL SENSE: TWO-COLUMN LAYOUT STRUCTURE & STYLING --- */
        :root {
            /* GLOBAL VARIABLES */
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
                    
            /* DASHBOARD SPECIFIC VARIABLES */
            --primary-gradient: linear-gradient(135deg, #7451eb 0%, #3498db 100%);
            --secondary-gradient: #1abc9c;
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            --card-hover-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            --sidebar-width: 260px; /* Full Width */
            --sidebar-min-width: 80px; /* Minimized Width */
            
            /* Navbar height variable for alignment */
            --header-height: 82px;  
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: var(--bg-light); /* Changed gradient to solid light bg for cleanliness */
            margin: 0;
            padding: 0;
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            display: flex;
            flex-direction: column;
        }

        /* --- 3. REFINED NAVBAR --- */
        .navbar {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            padding: 1rem 1.5rem; /* Added horizontal padding */
            backdrop-filter: blur(10px);
            height: var(--header-height);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1050;
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
        
        /* NEW: User Dropdown Styles */
        .user-dropdown .dropdown-toggle {
            color: white;
            display: flex;
            align-items: center;
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 8px;
            transition: var(--transition);
        }
        
        .user-dropdown .dropdown-toggle:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .user-dropdown .dropdown-toggle img {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,0.3);
            margin-right: 10px;
        }
        
        .user-dropdown .dropdown-toggle .user-name {
            font-weight: 600;
            font-size: 0.95rem;
        }

        .user-dropdown .dropdown-menu {
            border: none;
            box-shadow: var(--shadow-md);
            border-radius: 10px;
            padding: 0.5rem 0;
            margin-top: 10px !important;
        }

        .user-dropdown .dropdown-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.25rem;
            font-weight: 500;
            color: #333;
            font-size: 0.9rem;
        }
        
        .user-dropdown .dropdown-item i {
            width: 20px;
            margin-right: 10px;
            color: var(--text-muted);
        }

        .user-dropdown .dropdown-item:hover {
            background-color: #f1f1f1;
            color: var(--primary-dark);
        }
        
        .user-dropdown .dropdown-item:hover i {
            color: var(--primary-dark);
        }

        .user-dropdown .dropdown-divider {
            margin: 0.5rem 0;
        }

        .user-dropdown .dropdown-item.text-danger:hover {
            background-color: #fff1f1;
            color: #d9534f;
        }
        
        /* --- Sidebar Navigation --- */
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

        .sidebar.minimized {
            width: var(--sidebar-min-width);
        }
        
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

        #sidebarToggle:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.05);
        }

        .sidebar-nav {
            padding: 50px 0 20px 0;
        }

        .sidebar-nav .nav-link {
            color: rgba(255, 255, 255, 0.7);
            font-size: 1.05rem; /* Slightly reduced font size */
            font-weight: 500;
            padding: 15px 25px;
            transition: var(--transition);
            border-left: 5px solid transparent;
            margin: 2px 0; /* Reduced margin */
            display: flex;
            align-items: center;
            text-decoration: none;
        }

        .sidebar-nav .nav-link i {
            width: 30px;
            text-align: center;
            flex-shrink: 0;
            font-size: 0.95em; /* Icon size adjustment */
        }
        
        .sidebar.minimized .sidebar-nav .nav-link span {
            display: none;
        }
        
        .sidebar.minimized .sidebar-nav .nav-link {
            justify-content: center;
            padding: 15px 0;
        }

        .sidebar.minimized #sidebarToggle {
            right: 50%;
            transform: translateX(50%);
        }

        .sidebar-nav .nav-link:hover {
            color: white;
            background: rgba(255, 255, 255, 0.05);
            border-left-color: #1abc9c;
        }

        .sidebar-nav .nav-link.active {
            color: white;
            background: rgba(255, 255, 255, 0.1);
            border-left-color: #3498db;
            font-weight: 600;
        }

        /* Main Content Area */
        .main-content {
            flex: 1 0 auto;
            padding: 30px;
            margin-top: var(--header-height);
            margin-left: var(--sidebar-width);
            transition: margin-left var(--transition);
            min-height: calc(100vh - var(--header-height));
        }
        
        .sidebar.minimized ~ .main-content {
            margin-left: var(--sidebar-min-width);
        }
        
        /* Section Title */
        .section-title {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            color: #333;
        }

        /* --- Hero Section --- */
        .hero-section {
            background: var(--primary-gradient);
            color: white;
            padding: 40px 30px; 
            margin-bottom: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(116, 81, 235, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .hero-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .hero-subtitle {
            font-size: 1rem;
            opacity: 0.9;
            font-weight: 300;
        }
        
        .welcome-badge {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-block;
            margin-bottom: 10px;
        }
        
        /* --- Dashboard Stats Cards --- */
        .clickable-card-link {
            text-decoration: none;
            display: block;
            height: 100%;
            color: inherit; /* Inherit text color */
        }
        
        .stat-card {
            background: white;
            border: none;
            border-radius: 15px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            height: 100%;
        }

        .clickable-card-link:hover .stat-card {
            transform: translateY(-5px);
            box-shadow: var(--card-hover-shadow);
        }

        .stat-icon-wrapper {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            margin-right: 15px;
            flex-shrink: 0; /* Prevent icon from shrinking */
        }
        
        .stat-count {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 5px;
            font-family: 'Poppins', sans-serif;
        }

        /* Updated Stat Card Colors */
        .stat-events .stat-icon-wrapper { background: linear-gradient(45deg, #3498db, #2980b9); }
        .stat-teams .stat-icon-wrapper { background: linear-gradient(45deg, #e74c3c, #c0392b); }
        .stat-users .stat-icon-wrapper { background: linear-gradient(45deg, #2ecc71, #27ae60); }
        .stat-requests .stat-icon-wrapper { background: linear-gradient(45deg, #f1c40f, #f39c12); }
        
        /* --- Quick Actions --- */
        .quick-action-card {
            border: none;
            border-radius: 15px;
            transition: var(--transition);
            box-shadow: var(--card-shadow);
            background: white;
            height: 100%;
            text-align: center;
            padding: 20px;
        }

        .quick-action-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-hover-shadow);
        }

        .quick-action-card a {
            text-decoration: none;
        }

        .quick-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .icon-events-quick { color: #3498db; }
        .icon-teams-quick { color: #e74c3c; }
        .icon-users-quick { color: #2ecc71; } /* New */
        .icon-reports-quick { color: #9b55b6; }
        
        /* --- NEW: Activity & Request Widgets --- */
        .widget-card {
            background: var(--bg-white);
            border: none;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
            height: 100%;
        }
        
        .widget-card .card-header {
            background-color: transparent;
            border-bottom: 1px solid #eee;
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 1.1rem;
            padding: 1.25rem;
        }
        
        .widget-card .card-body {
            padding: 1.25rem;
            max-height: 400px; /* Set max height for scroll */
            overflow-y: auto;
        }
        
        .activity-list {
            list-style: none;
            padding-left: 0;
            margin-bottom: 0;
        }
        
        .activity-list li {
            padding: 12px 5px;
            border-bottom: 1px solid #f5f5f5;
            display: flex;
            align-items: flex-start;
        }
        
        .activity-list li:last-child {
            border-bottom: none;
        }
        
        .activity-list .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            background-color: var(--text-muted);
            margin-right: 15px;
            flex-shrink: 0;
            font-size: 0.9rem;
        }
        
        .activity-list .activity-content {
            flex-grow: 1;
        }
        
        .activity-list .activity-text {
            display: block;
            color: var(--text-dark);
            font-weight: 500;
            line-height: 1.4;
            margin-bottom: 2px;
        }
        
        .activity-list .activity-sub {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .request-list {
            list-style: none;
            padding-left: 0;
            margin-bottom: 0;
        }
        
        .request-list li {
            padding: 12px 5px;
            border-bottom: 1px solid #f5f5f5;
        }
        .request-list li:last-child { border-bottom: none; }
        
        .request-info {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        
        .request-details .name {
            font-weight: 600;
            color: var(--text-dark);
        }
        .request-details .role {
            font-size: 0.9rem;
            color: var(--text-muted);
            display: block;
        }
        
        .request-actions {
            flex-shrink: 0;
            margin-left: 10px;
        }
        
        .request-actions .btn-sm {
            padding: 0.2rem 0.6rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .no-data-message {
            padding: 2rem 0;
            text-align: center;
            color: var(--text-muted);
        }
        
        /* --- Footer --- */
        footer {
            flex-shrink: 0;
            background: #2c3e50 !important;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            margin-left: var(--sidebar-width);
            transition: margin-left var(--transition);
            position: relative; 
            z-index: 1041;
        }

        .sidebar.minimized ~ footer {
            margin-left: var(--sidebar-min-width);
        }

        /* Sidebar Overlay for Mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: var(--header-height);
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1030;
        }

        .sidebar-overlay.show {
            display: block;
        }

        /* --- Responsive Adjustments --- */
        @media (max-width: 992px) {
            .navbar {
                padding: 1rem;
            }
            .sidebar {
                width: 260px;
                left: -260px;
                top: var(--header-height);
                height: calc(100vh - var(--header-height));
                transition: left 0.3s ease;
                z-index: 1045;
            }

            .sidebar.show {
                left: 0;
            }
            
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            
            .sidebar.minimized ~ .main-content {
                margin-left: 0;
            }
            
            #sidebarToggle {
                display: none;
            }

            footer {
                margin-left: 0;
            }

            .sidebar.minimized ~ footer {
                margin-left: 0;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }
            .hero-title { font-size: 1.5rem; }
            .hero-section { padding: 30px 20px; }
            .stat-card .d-flex {
                flex-direction: column;
                align-items: center !important;
                text-align: center;
            }
            .stat-icon-wrapper {
                margin-right: 0;
                margin-bottom: 10px;
            }
            .stat-count { font-size: 2rem; }
            .user-dropdown .dropdown-toggle .user-name {
                display: none; /* Hide name on mobile navbar */
            }
            .user-dropdown .dropdown-toggle img {
                margin-right: 0;
            }
        }
        
        .hero-settings-link {
            text-decoration: none;
            color: white;
            opacity: 0.5;
            transition: var(--transition);
            display: inline-block; 
        }
        .hero-settings-link:hover {
            opacity: 1;
            transform: scale(1.1);
        }
        .hero-settings-link .fa-users-cog {
             transition: var(--transition);
        }
        .hero-settings-link:hover .fa-users-cog {
             transform: rotate(15deg);
        }
    </style>
</head>
<body>
    
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            
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
                    <img src="images/default_avatar.png" alt="User Avatar">
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li>
                        <a class="dropdown-item" href="admin_profile.php">
                            <i class="fas fa-user-circle"></i> Profile
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="Tournament_Manager_page.php" target="_blank">
                            <i class="fas fa-globe"></i> View Public Site
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item text-danger" href="logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>

        </div>
    </nav>

    <div class="sidebar" id="sidebar">
        <button id="sidebarToggle" title="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
        
        <ul class="nav flex-column sidebar-nav">
            <li class="nav-item">
                <a class="nav-link active" href="admin_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="Manage_Event.php">
                    <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="Manage_Team.php">
                    <i class="fas fa-users me-2"></i> <span>Manage Teams</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="Manage_Users.php">
                    <i class="fas fa-users-cog me-2"></i> <span>Manage Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="results.php">
                    <i class="fas fa-medal me-2"></i> <span>Manage Medals</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="Manage_Requests.php">
                    <i class="fas fa-user-plus me-2"></i> <span>Account Requests</span>
                    <?php if ($stats['pending_requests'] > 0): ?>
                        <span class="badge bg-danger ms-auto"><?= $stats['pending_requests'] ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="Manage_Viewreports.php">
                    <i class="fas fa-chart-line me-2"></i> <span>View Reports</span>
                </a>
            </li>
            <li class="nav-item">
            <a class="nav-link" href="system_config.php">
                <i class="fas fa-cogs me-2"></i> <span>System Config</span>
            </a>
            </li>
            <li class="nav-item mt-3">
                <a class="nav-link text-danger" href="logout.php">
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
                    <div class="welcome-badge">
                        <i class="fas fa-shield-alt me-2"></i>Administrator Access
                    </div>
                    <h1 class="hero-title">Welcome Back, <?= htmlspecialchars($name); ?>!</h1>
                    <p class="hero-subtitle">Your central hub for tournament oversight and management.</p>
                </div>
                <div class="d-none d-md-block text-end">
                    <a href="admin_profile.php" class="hero-settings-link" title="Your Profile">
                        <i class="fas fa-user-cog fa-4x"></i> </a>
                </div>
            </div>
        </div>

        <div class="container-fluid p-0">
            
            <h2 class="section-title mb-4">Live Tournament Snapshot</h2>
            
            <div class="row g-4 mb-5">
                
                <div class="col-lg-3 col-md-6">
                    <a href="Manage_Event.php" class="clickable-card-link">
                        <div class="stat-card stat-events">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon-wrapper">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <div>
                                    <div class="stat-count text-primary"><?= $stats['total_events'] ?></div>
                                    <small class="text-muted">Total Events</small>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="col-lg-3 col-md-6">
                    <a href="Manage_Team.php" class="clickable-card-link">
                        <div class="stat-card stat-teams">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon-wrapper">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div>
                                    <div class="stat-count text-danger"><?= $stats['total_teams'] ?></div>
                                    <small class="text-muted">Participating Teams</small>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="col-lg-3 col-md-6">
                    <a href="Manage_Users.php" class="clickable-card-link">
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

                <div class="col-lg-3 col-md-6">
                    <a href="Manage_Requests.php" class="clickable-card-link">
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
            </div>
            
            <h2 class="section-title mb-4">Quick Actions & Navigation</h2>
            <div class="row g-4 mb-5">
                
                <div class="col-lg-3 col-md-6 col-sm-6">
                    <div class="quick-action-card">
                        <a href="Manage_Event.php">
                            <i class="fas fa-calendar-alt quick-icon icon-events-quick"></i>
                            <h5 class="fw-bold mb-1 text-dark">Events</h5>
                            <small class="text-muted">Create & Schedule</small>
                        </a>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6 col-sm-6">
                    <div class="quick-action-card">
                        <a href="Manage_Team.php">
                            <i class="fas fa-users quick-icon icon-teams-quick"></i>
                            <h5 class="fw-bold mb-1 text-dark">Teams</h5>
                            <small class="text-muted">Roster & Details</Gsmall>
                        </a>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 col-sm-6">
                    <div class="quick-action-card">
                        <a href="Manage_Users.php">
                            <i class="fas fa-users-cog quick-icon icon-users-quick"></i>
                            <h5 class="fw-bold mb-1 text-dark">Users</h5>
                            <small class="text-muted">Create & Manage</small>
                        </a>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 col-sm-6">
                    <div class="quick-action-card">
                        <a href="Manage_Viewreports.php">
                            <i class="fas fa-chart-line quick-icon icon-reports-quick"></i>
                            <h5 class="fw-bold mb-1 text-dark">Reports</h5>
                            <small class="text-muted">Insights & Analytics</small>
                        </a>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-7">
                    <h2 class="section-title mb-4">Recent Activity</h2>
                    <div class="card widget-card">
                        <div class="card-header">
                            Latest System Logs
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_logs)): ?>
                                <p class="no-data-message">No recent log entries found.</p>
                            <?php else: ?>
                                <ul class="activity-list">
                                    <?php foreach ($recent_logs as $log): ?>
                                    <li>
                                        <div class="activity-icon"><i class="fas fa-bolt"></i></div>
                                        <div class="activity-content">
                                            <span class="activity-text"><?= htmlspecialchars($log['log']) ?></span>
                                            <span class="activity-sub">
                                                by <?= htmlspecialchars($log['user_username']) ?> | <?= date('M d, Y h:i A', strtotime($log['created_at'])) ?>
                                            </span>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-5">
                    <h2 class="section-title mb-4">Pending Account Requests</h2>
                    <div class="card widget-card">
                        <div class="card-header">
                            Awaiting Approval
                        </div>
                        <div class="card-body">
                             <?php if (empty($pending_requests_list)): ?>
                                <p class="no-data-message">No pending requests.</p>
                            <?php else: ?>
                                <ul class="request-list">
                                    <?php foreach ($pending_requests_list as $request): ?>
                                    <li>
                                        <div class="request-info">
                                            <div class="request-details">
                                                <span class="name"><?= htmlspecialchars($request['full_name']) ?></span>
                                                <span class="role">@<?= htmlspecialchars($request['username']) ?> (Role: <?= htmlspecialchars($request['requested_role']) ?>)</span>
                                            </div>
                                            <div class="request-actions">
                                                <a href="approve_request.php?id=<?= $request['request_id'] ?>" class="btn btn-success btn-sm"><i class="fas fa-check"></i></a>
                                                <a href="reject_request.php?id=<?= $request['request_id'] ?>" class="btn btn-danger btn-sm"><i class="fas fa-times"></i></a>
                                            </div>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
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
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 992) {
                        sidebar.classList.remove('show');
                        sidebarOverlay.classList.remove('show');
                    }
                });
            });

            // Active Link Logic for Sidebar (except for the dashboard link, which is hard-coded)
            const currentPage = window.location.pathname.split('/').pop();
            if (currentPage !== 'admin_dashboard.php' && currentPage !== '') {
                // Remove active from dashboard
                const dashboardLink = document.querySelector('.sidebar-nav .nav-link[href="admin_dashboard.php"]');
                if(dashboardLink) dashboardLink.classList.remove('active');

                // Add active to current page link
                document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
                    if (link.href.includes(currentPage)) {
                        link.classList.add('active');
                    } else {
                        link.classList.remove('active');
                    }
                });
            } else {
                 // We are on the dashboard, make sure all others are not active
                 document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
                    if (!link.href.includes('admin_dashboard.php')) {
                        link.classList.remove('active');
                    }
                 });
            }

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
            
            // Initialize Bootstrap Dropdowns
            var dropdownElementList = [].slice.call(document.querySelectorAll('.dropdown-toggle'))
            var dropdownList = dropdownElementList.map(function (dropdownToggleEl) {
              return new bootstrap.Dropdown(dropdownToggleEl)
            })
        });
    </script>
</body>
</html>