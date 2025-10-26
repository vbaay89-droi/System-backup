<?php
session_start();
// Check if the user is logged in, if not, redirect to the login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// PHP variables that were included in your tournament.php for header/footer consistency
$current_page = basename($_SERVER['PHP_SELF']); // This will be 'admin_dashboard.php'
$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin'; // Get username for display

// Dummy values for statistics (Replace with actual DB fetches)
$stats = [
    'total_events' => 12,
    'total_teams' => 6,
    'pending_matches' => 45,
    'last_login' => date('M d, Y h:i A', strtotime('-1 hour'))
];
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
            --header-height: 70px; 
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(to bottom, rgba(245, 16, 16, 0.32));
            margin: 0;
            padding: 0;
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            display: flex;
            flex-direction: column;
        }

        /* Navbar Enhancement - FIXED POSITIONING */
        .navbar {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            padding: 1rem 0;
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

        .nav-link {
            font-weight: 500;
            font-size: 0.95rem;
            padding: 0.5rem 1.25rem !important;
            margin: 0 0.25rem;
            border-radius: 8px;
            transition: var(--transition);
            position: relative;
        }

        .nav-link::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            width: 0;
            height: 2px;
            background: var(--primary-green);
            transition: var(--transition);
            transform: translateX(-50%);
        }

        .nav-link:hover::after,
        .nav-link.active::after {
            width: 80%;
        }

        .nav-link:hover {
            background: rgba(255,255,255,0.1);
            color: var(--primary-green) !important;
        }

        .btn-danger, .btn-success {
            padding: 0.6rem 1.5rem;
            border-radius: 10px;
            font-weight: 600;
            transition: var(--transition);
            border: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .btn-danger:hover, .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
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

        /* Minimized State for Sidebar */
        .sidebar.minimized {
            width: var(--sidebar-min-width);
        }
        
        
        /* Hamburger Icon Styling */
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
            font-size: 1.1rem;
            font-weight: 500;
            padding: 15px 25px;
            transition: var(--transition);
            border-left: 5px solid transparent;
            margin: 5px 0;
            display: flex;
            align-items: center;
            text-decoration: none;
        }

        .sidebar-nav .nav-link i {
            width: 30px;
            text-align: center;
            flex-shrink: 0;
        }
        
        /* Hide text labels in minimized state */
        .sidebar.minimized .sidebar-nav .nav-link span {
            display: none;
        }
        
        /* Center icons in minimized state */
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

        .stat-card:hover {
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
        }
        
        .stat-count {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 5px;
            font-family: 'Poppins', sans-serif;
        }

        .stat-events .stat-icon-wrapper { background: linear-gradient(45deg, #3498db, #2980b9); }
        .stat-teams .stat-icon-wrapper { background: linear-gradient(45deg, #e74c3c, #c0392b); }
        .stat-matches .stat-icon-wrapper { background: linear-gradient(45deg, #f1c40f, #f39c12); }
        .stat-login .stat-icon-wrapper { background: linear-gradient(45deg, #9b59b6, #8e44ad); }

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
        .icon-medals-quick { color: #f39c12; }
        .icon-reports-quick { color: #9b55b6; }
        
        /* --- Footer --- */
        footer {
            flex-shrink: 0;
            background: #2c3e50 !important;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            margin-left: var(--sidebar-width);
            transition: margin-left var(--transition);
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
            
            .hero-title {
                font-size: 1.5rem;
            }
            
            .hero-section {
                padding: 30px 20px;
            }
            
            .stat-card .d-flex {
                flex-direction: column;
                align-items: center !important;
                text-align: center;
            }
            
            .stat-icon-wrapper {
                margin-right: 0;
                margin-bottom: 10px;
            }
            
            .stat-count {
                font-size: 2rem;
            }
        }
        /* --- ADD THIS --- */
        .hero-settings-link {
            text-decoration: none;
            color: white;
            opacity: 0.5;
            transition: var(--transition);
            display: inline-block; /* Added for transform */
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
        /* --- END OF ADDITION --- */
    </style>
</head>
<body>
    
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="Tournament_Manager_page.php" style="cursor: pointer;">
                <img src="imageslogo.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white brand-heading" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light brand-subheading" style="font-size: 0.75rem;">Official College Tournament System</small>
                </div>
            </a>

            <button class="navbar-toggler" type="button" id="mobileMenuToggle" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'Tournament_Manager_page.php') ? 'active' : '' ?>" href="Tournament_Manager_page.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'Event.php') ? 'active' : '' ?>" href="Event.php">Events</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'Teams.php') ? 'active' : '' ?>" href="Teams.php">Teams</a>
                    </li>
                    <li class="nav-item">
                        <?php if (isset($_SESSION['email'])): ?>
                            <a href="logout.php" class="btn btn-danger ms-3">Logout</a>
                        <?php else: ?>
                            <a href="login.php" class="btn btn-success ms-3">Admin Login</a>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo-text">Admin Panel</div>
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
                <a class="nav-link" href="Manage_medals.php">
                    <i class="fas fa-medal me-2"></i> <span>Manage Medals</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="Manage_Viewreports.php">
                    <i class="fas fa-chart-line me-2"></i> <span>View Reports</span>
                </a>
            </li>
            <li class="nav-item">
            <a class="nav-link" href="admin_settings.php">
                <i class="fas fa-cog me-2"></i> <span>Settings</span>
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

    <!-- Main Content -->
    <div class="main-content">
        <div class="hero-section">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="welcome-badge">
                        <i class="fas fa-shield-alt me-2"></i>Admin Access
                    </div>
                    <h1 class="hero-title">Welcome Back, <?= htmlspecialchars($name); ?>!</h1>
                    <p class="hero-subtitle">Your central hub for tournament oversight and management.</p>
                </div>
                <div class="d-none d-md-block text-end">
                    <a href="admin_settings.php" class="hero-settings-link" title="Account Settings">
                        <i class="fas fa-users-cog fa-4x"></i>
                    </a>
                </div>
            </div>
        </div>

        <div class="container-fluid p-0">
            
            <h2 class="section-title mb-4">Live Tournament Snapshot</h2>
            <div class="row g-4 mb-5">
                
                <div class="col-lg-3 col-md-6">
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
                </div>

                <div class="col-lg-3 col-md-6">
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
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="stat-card stat-matches">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon-wrapper">
                                <i class="fas fa-futbol"></i>
                            </div>
                            <div>
                                <div class="stat-count text-warning"><?= $stats['pending_matches'] ?></div>
                                <small class="text-muted">Pending Matches</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <div class="stat-card stat-login">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon-wrapper">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div>
                                <div class="stat-count" style="font-size: 1.1rem;"><?= $stats['last_login'] ?></div>
                                <small class="text-muted">Last System Login</small>
                            </div>
                        </div>
                    </div>
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
                            <small class="text-muted">Roster & Details</small>
                        </a>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 col-sm-6">
                    <div class="quick-action-card">
                        <a href="Manage_medals.php">
                            <i class="fas fa-medal quick-icon icon-medals-quick"></i>
                            <h5 class="fw-bold mb-1 text-dark">Medals</h5>
                            <small class="text-muted">Tally & Assign</small>
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

            <h2 class="section-title mb-4">Recent Activity</h2>
            <div class="card p-4 shadow-sm" style="border-radius: 15px;">
                <p class="text-muted mb-0">No recent log entries available. (Integration with backend logging system pending)</p>
            </div>
        </div>
    </div>

    <!-- Footer -->
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

            // Active Link Logic for Sidebar
            const currentPage = window.location.pathname.split('/').pop();
            document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
                if (link.href.includes(currentPage)) {
                    link.classList.add('active');
                } else {
                    link.classList.remove('active');
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