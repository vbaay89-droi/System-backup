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

if (!isset($_SESSION['user_id'])) {
    die("Session error: User ID is not set.");
}
$current_user_id = $_SESSION['user_id'];

$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Sports Director';
$current_page = basename($_SERVER['PHP_SELF']);
$event_pages = ['Manage_Games.php', 'Manage_Game_Events.php', 'Manage_Categories.php'];
$is_event_page = in_array($current_page, $event_pages);

$management_pages = ['colleges.php', 'events.php', 'results.php', 'reports.php'];
$is_management_page = in_array($current_page, $management_pages);

// --- Page Specific PHP ---

// --- 1. FETCH SUMMARY DATA (FOR TOP TABLE) ---
$standings = [];
$sql_summary = "SELECT 
            C.college_id, C.college_name, C.logo_url,
            
            SUM(CASE WHEN CAT.gold_winner_college_id = C.college_id THEN CAT.gold_count ELSE 0 END) AS Gold,
            SUM(CASE WHEN CAT.silver_winner_college_id = C.college_id THEN CAT.silver_count ELSE 0 END) AS Silver,
            SUM(CASE WHEN CAT.bronze_winner_college_id = C.college_id THEN CAT.bronze_count ELSE 0 END) AS Bronze,
            
            (SUM(CASE WHEN CAT.gold_winner_college_id = C.college_id THEN CAT.gold_count ELSE 0 END) +
             SUM(CASE WHEN CAT.silver_winner_college_id = C.college_id THEN CAT.silver_count ELSE 0 END) +
             SUM(CASE WHEN CAT.bronze_winner_college_id = C.college_id THEN CAT.bronze_count ELSE 0 END))
            AS TotalMedals
            
        FROM colleges C
        
        LEFT JOIN categories CAT ON (
            C.college_id = CAT.gold_winner_college_id OR 
            C.college_id = CAT.silver_winner_college_id OR 
            C.college_id = CAT.bronze_winner_college_id
        ) 
        AND CAT.status = 'Results Approved'
        
        GROUP BY C.college_id, C.college_name, C.logo_url
        ORDER BY Gold DESC, Silver DESC, Bronze DESC, TotalMedals DESC, C.college_name ASC";
        
$result_summary = $conn->query($sql_summary);
if($result_summary) {
    $standings = $result_summary->fetch_all(MYSQLI_ASSOC);
} else {
    die("SQL Error (Summary): " . $conn->error);
}

// --- 2. ### NEW: FETCH DETAILED DATA (FOR BOTTOM TABLE) ### ---
$detailed_results = [];
// *** MODIFICATION: Added c.category_type to the SELECT statement ***
$sql_detailed = "SELECT 
                    g.game_name, ge.event_name, c.category_name, c.category_type,
                    c.gold_count, c.silver_count, c.bronze_count,
                    gold_col.college_name AS gold_winner,
                    silver_col.college_name AS silver_winner,
                    bronze_col.college_name AS bronze_winner
                FROM categories c
                JOIN game_events ge ON c.event_id = ge.event_id
                JOIN games g ON ge.game_id = g.game_id
                LEFT JOIN colleges gold_col ON c.gold_winner_college_id = gold_col.college_id
                LEFT JOIN colleges silver_col ON c.silver_winner_college_id = silver_col.college_id
                LEFT JOIN colleges bronze_col ON c.bronze_winner_college_id = bronze_col.college_id
                WHERE c.status = 'Results Approved'
                ORDER BY g.game_name, ge.event_name, c.category_name";

$result_detailed = $conn->query($sql_detailed);
if($result_detailed) {
    $detailed_results = $result_detailed->fetch_all(MYSQLI_ASSOC);
} else {
    die("SQL Error (Detailed): " . $conn->error);
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
    
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { 
            --sidebar-width: 260px; 
            --header-height: 82px; 
            --transition: all 0.3s ease; 
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); 
            --bg-light: #F8F9FA; 
        }
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
            padding-left: var(--sidebar-width); /* <-- MODIFIED */
            transition: padding-left var(--transition); /* <-- MODIFIED */
            position: relative;
            z-index: 1041;
        }
        .sidebar.minimized ~ footer {
            padding-left: var(--sidebar-min-width); /* <-- MODIFIED */
        }
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        .card { border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; transition: var(--transition); }
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
        .user-dropdown .dropdown-toggle .user-name { font-weight: 600; font-size: 0.95rem; }
        .navbar-profile-icon { width: 36px; height: 36px; font-size: 36px; text-align: center; line-height: 1; border-radius: 50%; margin-right: 10px; color: rgba(255,255,255,0.8); }
        
        .gold-count { font-size: 1.1rem; font-weight: 700; color: #B8860B; }
        .silver-count { font-size: 1.1rem; font-weight: 700; color: #71706E; }
        .bronze-count { font-size: 1.1rem; font-weight: 700; color: #8C7853; }
        .total-count { font-size: 1.2rem; font-weight: 700; color: #000; }
        
        /* === ADDED: CSS for Type Badges === */
        :root { 
            --bs-purple: #6f42c1;
            --bs-info: #0dcaf0;
        }
        .badge.bg-purple { background-color: var(--bs-purple) !important; color: white; }
        .badge.bg-info { background-color: var(--bs-info) !important; color: #000; }
        /* === END OF ADDED CSS === */

        /* ### NEW: Print styles ### */
        @media print {
            body {
                background-color: #fff;
            }
            .navbar, .sidebar, footer, .btn, .no-print,
            /* === ADDED: Hide DataTables UI on print === */
            .dataTables_length, .dataTables_filter, .dataTables_info, .dataTables_paginate {
                display: none !important;
            }
            .main-content {
                margin-left: 0;
                padding: 0;
                margin-top: 0;
            }
            .card {
                box-shadow: none;
                border: 1px solid #dee2e6;
            }
        }
        .winner-count {
            font-weight: 700; /* or 'bold' */
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark no-print">
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
                    <li><a class="dropdown-item text-danger" href="../login.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Administrator'): ?>
    <div class="sidebar" id="sidebar no-print">
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
                        <i class="fas fa-users me-2"></i> <span>Manage Colleges</span> <i class="fas fa-chevron-down ms-auto sidebar-chevron"></i>
                    </a>
                    <div class="collapse <?php if ($is_management_page) echo 'show'; ?>" id="teamsCollapse">
                        <ul class="sub-menu">
                            <li class="text-muted" style="padding: 10px 25px 5px 60px;">Management</li>
                            <li><a class="nav-link <?php if ($current_page == 'colleges.php') echo 'active'; ?>" href="colleges.php"><span>Manage Colleges</span></a></li>
                            <li><a class="nav-link <?php if ($current_page == 'events.php') echo 'active'; ?>" href="events.php"><span>Manage Events (L1-L3)</span></a></li>
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
                    <a class="nav-link text-danger" href="../logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
                    </a>
                </li>
            </ul>
        </div>
        <?php else: ?>
        <div class="sidebar" id="sidebar no-print">
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
                        <i class="fas fa-users me-2"></i> <span>Manage Colleges</span>
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
            <h1 class="section-title mb-4">Official Medal Standings</h1>
            <p class="text-muted">This report shows the real-time medal tally based only on results you have approved.</p>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Overall College Rankings</h5>
                    <button class="btn btn-sm btn-outline-secondary no-print" onclick="window.print()">
                        <i class="fas fa-print me-1"></i> Print Report
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Rank</th>
                                    <th>College</th>
                                    <th class="text-center"><i class="fas fa-medal text-warning"></i> Gold</th>
                                    <th class="text-center"><i class="fas fa-medal text-secondary"></i> Silver</th>
                                    <th class="text-center"><i class="fas fa-medal" style="color:#CD7F32;"></i> Bronze</th>
                                    <th class="text-center">Total Medals</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($standings)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted p-4">No approved results yet.</td>
                                </tr>
                                <?php endif; ?>
                                <?php foreach ($standings as $index => $college): ?>
                                <tr>
                                    <td><h5 class="mb-0"><?= $index + 1 ?></h5></td>
                                    <td>
                                        <img src="../<?= htmlspecialchars($college['logo_url'] ?? 'images/default_avatar.png') ?>" alt="Logo" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; margin-right: 10px;">
                                        <strong><?= htmlspecialchars($college['college_name']) ?></strong>
                                    </td>
                                    <td class="text-center gold-count"><?= $college['Gold'] ?></td>
                                    <td class="text-center silver-count"><?= $college['Silver'] ?></td>
                                    <td class="text-center bronze-count"><?= $college['Bronze'] ?></td>
                                    <td class="text-center total-count"><?= $college['TotalMedals'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0">Detailed Report: Approved Events Breakdown</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="detailedReportTable" class="table table-striped align-middle" style="width:100%">
                            <thead class="table-light">
                                <tr>
                                    <th>Game</th>
                                    <th>Event</th>
                                    <th>Category</th>
                                    <th>Type</th> 
                                    <th><i class="fas fa-medal text-warning"></i> Gold Winner (Count)</th>
                                    <th><i class="fas fa-medal text-secondary"></i> Silver Winner (Count)</th>
                                    <th><i class="fas fa-medal" style="color:#CD7F32;"></i> Bronze Winner (Count)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($detailed_results)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted p-4">No approved results to detail.</td>
                                </tr>
                                <?php endif; ?>
                                <?php foreach ($detailed_results as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['game_name']) ?></td>
                                    <td><?= htmlspecialchars($row['event_name']) ?></td>
                                    <td><strong><?= htmlspecialchars($row['category_name']) ?></strong></td>
                                    
                                    <td>
                                        <?php if (strtolower($row['category_type']) == 'match'): ?>
                                            <span class="badge bg-purple"><i class="fas fa-trophy me-1"></i> Match</span>
                                        <?php else: ?>
                                            <span class="badge bg-info text-dark"><i class="fas fa-medal me-1"></i> Medal</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($row['gold_winner'] ?? 'N/A') ?>
                                        ( <span class="winner-count"><?= $row['gold_count'] ?></span> )
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($row['silver_winner'] ?? 'N/A') ?>
                                        ( <span class="winner-count"><?= $row['silver_count'] ?></span> )
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($row['bronze_winner'] ?? 'N/A') ?>
                                        ( <span class="winner-count"><?= $row['bronze_count'] ?></span> )
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div> 
    
    <footer class="bg-dark text-white py-4" id="footer">
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
            <small class="text-muted">Developed by Tsunayoshi Sawada</small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script src="https://code.jquery.com/jquery-3.7.0.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            
            // === ADDED: DataTables Initializer ===
            // This finds the table with id 'detailedReportTable' and applies the features.
            new DataTable('#detailedReportTable', {
                "lengthMenu": [ [10, 25, 50, -1], [10, 25, 50, "All"] ], // Adds "Show 10, 25, 50, All" entries
                "language": {
                    "search": "Filter report:" // Customizes the search box label
                }
            });

            // --- Existing Sidebar/Footer overlap fix ---
            const sidebar = document.getElementById('sidebar');
            const footer = document.querySelector('footer');
            const navbar = document.querySelector('.navbar');

            if (sidebar && footer && navbar) {
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
        });
    </script>
</body>
</html>