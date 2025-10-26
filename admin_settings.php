<?php
session_start();
// Check if the user is logged in, if not, redirect to the login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// --- INCLUDE DATABASE CONNECTION ---
require_once 'db_connect.php'; // Or your actual connection file name

// --- FETCH USER DATA FROM DATABASE ---
$name = 'Admin'; // Default
$email = 'admin@example.com'; // Default
$role = 'Administrator'; // Default
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0; // Get user ID from session

if ($user_id > 0) {
    try {
        // --- NEW MODIFIED QUERY ---
        $sql = "SELECT 
                    u.name, 
                    u.email, 
                    r.role_name 
                FROM users u
                JOIN roles r ON u.role_id = r.role_id 
                WHERE u.id = :id";
        
        if ($stmt = $conn->prepare($sql)) {
            // Bind variables to the prepared statement as parameters
            $stmt->bindParam(":id", $user_id, PDO::PARAM_INT);
            
            // Attempt to execute the prepared statement
            if ($stmt->execute()) {
                // Check if the user exists
                if ($stmt->rowCount() == 1) {
                    if ($row = $stmt->fetch()) {
                        // --- UPDATED COLUMN NAMES ---
                        $name = $row['name']; // <-- CHANGED from 'username'
                        $email = $row['email'];
                        $role = $row['role_name'];
                    }
                } else {
                    // User not found
                    $fetch_error = "Error: User ID " . $user_id . " not found in database.";
                }
            } else {
                $fetch_error = "Oops! Something went wrong executing the query.";
            }

            // Close statement
            unset($stmt);
        }
    } catch(PDOException $e) {
        $fetch_error = "Database query failed: " . $e->getMessage();
    }
} else {
    $fetch_error = "Error: User ID not found in session.";
}

// Close connection
unset($conn);

// Get current page
$current_page = basename($_SERVER['PHP_SELF']); 

// --- NEW: Check for success/error flash messages from processing files ---
$email_success_msg = $_SESSION['email_success_msg'] ?? null;
$email_error_msg = $_SESSION['email_error_msg'] ?? null;
$pass_success_msg = $_SESSION['pass_success_msg'] ?? null;
$pass_error_msg = $_SESSION['pass_error_msg'] ?? null;

// Unset them so they don't show again on refresh
unset($_SESSION['email_success_msg'], $_SESSION['email_error_msg'], $_SESSION['pass_success_msg'], $_SESSION['pass_error_msg']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings - PIT SPORTS TALLYING</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ... All your CSS <style> rules remain exactly the same ... */
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
        
        .btn-primary {
             background-color: var(--primary-dark);
             border-color: var(--primary-dark);
        }

        .btn-danger:hover, .btn-success:hover, .btn-primary:hover {
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
        
        /* Settings Page Card Styles */
        .settings-card {
            border: none;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
            background: white;
        }
        
        .settings-card .card-header {
            background: white;
            border-bottom: 1px solid #eee;
            border-radius: 15px 15px 0 0;
            padding: 1.25rem 1.5rem;
        }
        
        .settings-card .card-body {
            padding: 1.5rem;
        }

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
        }
    </style>
</head>
<body>
    
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
                        <?php if (isset($_SESSION['user_id'])): // Check for user_id or logged_in ?>
                            <a href="logout.php" class="btn btn-danger ms-3">Logout</a>
                        <?php else: ?>
                            <a href="login.php" class="btn btn-success ms-3">Admin Login</a>
                        <?php endif; ?>
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
                <a class="nav-link" href="admin_dashboard.php"> <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
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
            <li class="nav-item"> <a class="nav-link active" href="admin_settings.php"> <i class="fas fa-cog me-2"></i> <span>Settings</span>
                </a>
            </li>
            <li class="nav-item mt-3">
                <a class="nav-link text-danger" href="logout.php"> <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
    
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-content">
        <div class="hero-section">
            <h1 class="hero-title">Account Settings</h1>
            <p class="hero-subtitle">Manage your profile, email, and password.</p>
        </div>

        <div class="container-fluid p-0">
            <div class="row">
                <div class="col-lg-8 mx-auto">
                    
                    <?php if (isset($fetch_error)): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($fetch_error); ?></div>
                    <?php endif; ?>

                    <div class="card settings-card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0 fw-bold"><i class="fas fa-user-circle me-2 text-muted"></i>User Profile</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <strong class="form-label">Name:</strong>
                                <p class="text-muted mb-0"><?= htmlspecialchars($name); ?></p>
                            </div>
                            <div>
                                <strong class="form-label">Role:</strong>
                                <p class="text-muted mb-0"><?= htmlspecialchars($role); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="card settings-card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0 fw-bold"><i class="fas fa-envelope me-2 text-muted"></i>Change Email</h5>
                        </div>
                        <div class="card-body">
                            
                            <?php if ($email_success_msg): ?>
                                <div class="alert alert-success"><?= htmlspecialchars($email_success_msg); ?></div>
                            <?php endif; ?>
                            <?php if ($email_error_msg): ?>
                                <div class="alert alert-danger"><?= htmlspecialchars($email_error_msg); ?></div>
                            <?php endif; ?>

                            <form action="process_update_email.php" method="POST">
                                <div class="mb-3">
                                    <label for="currentEmail" class="form-label">Current Email</label>
                                    <input type="email" class="form-control" id="currentEmail" value="<?= htmlspecialchars($email); ?>" readonly disabled>
                                </div>
                                <div class="mb-3">
                                    <label for="newEmail" class="form-label">New Email Address</label>
                                    <input type="email" class="form-control" id="newEmail" name="new_email" placeholder="Enter your new email" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="currentPasswordForEmail" class="form-label">Current Password</label>
                                    <input type="password" class="form-control" id="currentPasswordForEmail" name="current_password" placeholder="Enter password to confirm" required>
                                </div>
                                
                                <button type="submit" class="btn btn-primary btn-success">Save Email Changes</button>
                            </form>
                        </div>
                    </div>

                    <div class="card settings-card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0 fw-bold"><i class="fas fa-key me-2 text-muted"></i>Change Password</h5>
                        </div>
                        <div class="card-body">
                            
                            <?php if ($pass_success_msg): ?>
                                <div class="alert alert-success"><?= htmlspecialchars($pass_success_msg); ?></div>
                            <?php endif; ?>
                            <?php if ($pass_error_msg): ?>
                                <div class="alert alert-danger"><?= htmlspecialchars($pass_error_msg); ?></div>
                            <?php endif; ?>

                            <form action="process_update_password.php" method="POST">
                                <div class="mb-3">
                                    <label for="currentPassword" class="form-label">Current Password</label>
                                    <input type="password" class="form-control" id="currentPassword" name="current_password" required>
                                </div>
                                <div class="mb-3">
                                    <label for="newPassword" class="form-label">New Password</label>
                                    <input type="password" class="form-control" id="newPassword" name="new_password" required>
                                </div>
                                <div class="mb-3">
                                    <label for="confirmPassword" class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirmPassword" name="confirm_password" required>
                                </div>
                                <button type="submit" class="btn btn-primary btn-success">Update Password</button>
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
            // ... All your JavaScript remains exactly the same ...
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
            // This script automatically finds the link matching the current page and makes it active.
            const currentPage = window.location.pathname.split('/').pop();
            
            // Fix for active link: Make admin_settings.php active
            if (currentPage === 'admin_settings.php') {
                 document.querySelector('.sidebar-nav .nav-link[href="admin_settings.php"]').classList.add('active');
            } else {
                document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
                    if (link.href.includes(currentPage) && currentPage !== "") {
                        link.classList.add('active');
                    } else if (link.href.includes(currentPage) && currentPage === "" && link.href.includes('admin_dashboard.php')) {
                        // Fallback for root/dashboard
                        link.classList.add('active');
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
        });
    </script>
</body>
</html>