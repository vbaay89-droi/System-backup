<?php
session_start();
// Use a relative path to your main db_connect.php
require_once '../db_connect.php'; 

// 1. SECURITY & ACCESS CONTROL (from your Task 1)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Sports Director') {
    header('Location: ../login.php'); // Redirect to main login page
    exit();
}

$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Sports Director';
$current_page = basename($_SERVER['PHP_SELF']); // This will be 'sports_director_dashboard.php'

// Helper function for logging actions
// (We'll use this in the other files)
function log_activity($conn, $message) {
    $stmt = $conn->prepare("INSERT INTO system_logs (log_message) VALUES (?)");
    $stmt->bind_param("s", $message);
    $stmt->execute();
    $stmt->close();
}

// --- Page Specific PHP (from your Task 2) ---
// Fetch Stat Cards
$total_events = $conn->query("SELECT COUNT(*) FROM events")->fetch_column();
$total_teams = $conn->query("SELECT COUNT(*) FROM teams")->fetch_column();
$pending_results = $conn->query("SELECT COUNT(*) FROM results WHERE status='Pending'")->fetch_column();
$total_gold = $conn->query("SELECT COUNT(*) FROM results WHERE status='Approved' AND winner_gold_team_id IS NOT NULL")->fetch_column();

// Fetch Recent Activity
$logs = [];
$result_logs = $conn->query("SELECT * FROM system_logs ORDER BY created_at DESC LIMIT 5");
if ($result_logs) {
    $logs = $result_logs->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SD Dashboard - PIT Sports Tallying</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { 
            --sidebar-width: 260px; 
            --header-height: 82px; 
            --transition: all 0.3s ease; 
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); 
            --bg-light: #F8F9FA; 
        }
        body { 
            background-color: var(--bg-light); 
            margin: 0; 
            padding: 0; 
            min-height: 100vh; 
            font-family: 'Inter', sans-serif; 
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
        .user-dropdown .dropdown-toggle { 
            color: white; 
            display: flex; 
            align-items: center; 
            text-decoration: none; 
            padding: 8px 12px; 
            border-radius: 8px; 
        }
        .user-dropdown .dropdown-toggle img { 
            width: 36px; 
            height: 36px; 
            border-radius: 50%; 
            object-fit: cover; 
            margin-right: 10px; 
        }
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
        .sidebar-nav { padding: 20px 0; }
        .sidebar-nav .nav-link { 
            color: rgba(255, 255, 255, 0.7); 
            font-size: 1.05rem; 
            font-weight: 500; 
            padding: 12px 25px; 
            transition: var(--transition); 
            border-left: 5px solid transparent; 
            margin: 2px 0; 
            display: flex; 
            align-items: center; 
            text-decoration: none; 
        }
        .sidebar-nav .nav-link i { 
            width: 30px; 
            text-align: center; 
            flex-shrink: 0; 
            font-size: 0.95em; 
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
        .sidebar-nav .nav-title { 
            padding: 10px 25px; 
            font-size: 0.75rem; 
            font-weight: 600; 
            color: rgba(255, 255, 255, 0.4); 
            text-transform: uppercase; 
            letter-spacing: 1px; 
        }
        .main-content { 
            flex: 1 0 auto; 
            padding: 30px; 
            margin-top: var(--header-height); 
            margin-left: var(--sidebar-width); 
            transition: margin-left var(--transition); 
            min-height: calc(100vh - var(--header-height)); 
        }
        footer { 
            flex-shrink: 0; 
            background: #2c3e50 !important; 
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1); 
            margin-left: var(--sidebar-width); 
            transition: margin-left var(--transition); 
            position: relative; 
            z-index: 1041; 
        }
        .section-title { 
            font-family: 'Poppins', sans-serif; 
            font-weight: 600; 
            color: #333; 
        }
        .card { 
            border: none; 
            border-radius: 15px; 
            box-shadow: var(--card-shadow); 
        }
        .stat-card { 
            background: white; 
            border-radius: 15px; 
            padding: 20px; 
            box-shadow: var(--card-shadow); 
            transition: all 0.3s ease; 
        }
        .stat-card:hover { 
            transform: translateY(-5px); 
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1); 
        }
        .stat-icon { 
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
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="sports_director_dashboard.php">
                <img src="../imageslogo.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light" style="font-size: 0.75rem;">Sports Director Panel</small>
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

    <div class="sidebar" id="sidebar">
        <ul class="nav flex-column sidebar-nav">
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'sports_director_dashboard.php') ? 'active' : '' ?>" href="sports_director_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item mt-3"><span class="nav-title">Management</span></li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'teams.php') ? 'active' : '' ?>" href="teams.php">
                    <i class="fas fa-users me-2"></i> <span>Manage Teams</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'events.php') ? 'active' : '' ?>" href="events.php">
                    <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events (L1-L3)</span>
                </a>
            </li>
            
            <li class="nav-item mt-3"><span class="nav-title">Tallying</span></li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'results.php') ? 'active' : '' ?>" href="results.php">
                    <i class="fas fa-check-double me-2"></i> <span>Approve Results</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'reports.php') ? 'active' : '' ?>" href="reports.php">
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

    <div class="main-content">
        
        <div class="container-fluid">
            <h1 class="section-title mb-4">Sports Director Dashboard</h1>
            
            <div class="row g-4 mb-5">
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-primary"><i class="fas fa-calendar-alt"></i></div>
                            <div>
                                <div class="stat-count text-primary"><?= $total_events ?></div>
                                <small class="text-muted">Total Events (L2)</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-success"><i class="fas fa-users"></i></div>
                            <div>
                                <div class="stat-count text-success"><?= $total_teams ?></div>
                                <small class="text-muted">Participating Teams</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-warning"><i class="fas fa-gavel"></i></div>
                            <div>
                                <div class="stat-count text-warning"><?= $pending_results ?></div>
                                <small class="text-muted">Pending Results</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon" style="background-color: #FFD700;"><i class="fas fa-medal"></i></div>
                            <div>
                                <div class="stat-count text-warning"><?= $total_gold ?></div>
                                <small class="text-muted">Approved Gold Medals</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header"><h5 class="mb-0">Quick Navigation</h5></div>
                        <div class="card-body">
                            <div class="list-group">
                                <a href="results.php" class="list-group-item list-group-item-action list-group-item-warning d-flex justify-content-between align-items-center">
                                    <strong>Approve Medal Results</strong>
                                    <span class="badge bg-warning text-dark"><?= $pending_results ?> Pending</span>
                                </a>
                                <a href="events.php" class="list-group-item list-group-item-action">Manage Events & Assignments</a>
                                <a href="teams.php" class="list-group-item list-group-item-action">Manage Teams</a>
                                <a href="reports.php" class="list-group-item list-group-item-action">View Medal Standings</a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header"><h5 class="mb-0">Recent Activity</h5></div>
                        <div class="card-body">
                            <ul class="list-group list-group-flush">
                                <?php if (empty($logs)): ?>
                                    <li class="list-group-item text-muted">No recent activity.</li>
                                <?php endif; ?>
                                <?php foreach ($logs as $log): ?>
                                    <li class="list-group-item">
                                        <small class="text-muted"><?= date('M d, h:i A', strtotime($log['created_at'])) ?></small><br>
                                        <?= htmlspecialchars($log['log_message']) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
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
</body>
</html>