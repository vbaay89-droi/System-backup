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

$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Sports Director';
$current_page = basename($_SERVER['PHP_SELF']);
$event_pages = ['Manage_Games.php', 'Manage_Game_Events.php', 'Manage_Categories.php'];
$is_event_page = in_array($current_page, $event_pages);

$management_pages = ['teams.php', 'events.php', 'results.php', 'reports.php'];
$is_management_page = in_array($current_page, $management_pages);

// Helper function for logging actions
function log_activity($conn, $message) {
    $stmt = $conn->prepare("INSERT INTO system_logs (log_message) VALUES (?)");
    $stmt->bind_param("s", $message);
    $stmt->execute();
    $stmt->close();
}

// --- Page Specific PHP ---

// --- FETCH DATA (READ) ---
$standings = [];
// This is the query from your plan (Task 7), modified to use the new `results` table
$sql = "SELECT 
            T.team_name, T.logo_url,
            SUM(CASE WHEN R.winner_gold_team_id = T.team_id THEN 1 ELSE 0 END) AS Gold,
            SUM(CASE WHEN R.winner_silver_team_id = T.team_id THEN 1 ELSE 0 END) AS Silver,
            SUM(CASE WHEN R.winner_bronze_team_id = T.team_id THEN 1 ELSE 0 END) AS Bronze,
            (SUM(CASE WHEN R.winner_gold_team_id = T.team_id THEN 1 ELSE 0 END) * 5) +  -- 5 points for gold
            (SUM(CASE WHEN R.winner_silver_team_id = T.team_id THEN 1 ELSE 0 END) * 3) + -- 3 points for silver
            (SUM(CASE WHEN R.winner_bronze_team_id = T.team_id THEN 1 ELSE 0 END) * 1) AS TotalPoints -- 1 point for bronze
        FROM teams T
        LEFT JOIN results R ON (
            T.team_id = R.winner_gold_team_id OR 
            T.team_id = R.winner_silver_team_id OR 
            T.team_id = R.winner_bronze_team_id
        ) AND R.status = 'Approved'
        GROUP BY T.team_id, T.team_name, T.logo_url
        ORDER BY TotalPoints DESC, Gold DESC, Silver DESC, Bronze DESC";
        
$result = $conn->query($sql);
if($result) {
    $standings = $result->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medal Reports - SD Panel</title>
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
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
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
            <h1 class="section-title mb-4">Official Medal Standings</h1>
            <p class="text-muted">This report shows the real-time medal tally based *only* on results you have approved.</p>

            <div class="card">
                <div class="card-header"><h5 class="mb-0">Overall Team Rankings</h5></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Rank</th>
                                    <th>Team</th>
                                    <th><i class="fas fa-medal text-warning"></i> Gold</th>
                                    <th><i class="fas fa-medal text-secondary"></i> Silver</th>
                                    <th><i class="fas fa-medal" style="color:#CD7F32;"></i> Bronze</th>
                                    <th>Total Points</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($standings)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted p-4">No approved results yet.</td>
                                </tr>
                                <?php endif; ?>
                                <?php foreach ($standings as $index => $team): ?>
                                <tr>
                                    <td><h5><?= $index + 1 ?></h5></td>
                                    <td>
                                        <img src="../<?= htmlspecialchars($team['logo_url'] ?? 'images/default_avatar.png') ?>" alt="Logo" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; margin-right: 10px;">
                                        <strong><?= htmlspecialchars($team['team_name']) ?></strong>
                                    </td>
                                    <td><?= $team['Gold'] ?></td>
                                    <td><?= $team['Silver'] ?></td>
                                    <td><?= $team['Bronze'] ?></td>
                                    <td><strong><?= $team['TotalPoints'] ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        </div> <footer class="bg-dark text-white py-4" style="margin-left: var(--sidebar-width);">
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
            <small class="text-muted">Developed by Tsunayoshi Sawada</small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>