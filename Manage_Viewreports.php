<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. SECURITY & ACCESS CONTROL
// Use the same security check as your other admin pages
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Sports Director' && $_SESSION['role'] !== 'Administrator')) {
    header('Location: login.php'); // Redirect to main login page
    exit();
}

$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin';
$current_page = basename($_SERVER['PHP_SELF']);
require_once 'config.php'; // Main DB Connection

// --- Logic for Sidebar Accordions (from results.php) ---
$event_pages = ['Manage_Games.php', 'Manage_Game_Events.php', 'Manage_Categories.php'];
$is_event_page = in_array($current_page, $event_pages);
$management_pages = ['colleges.php', 'events.php', 'results.php', 'reports.php', 'Manage_Viewreports.php']; // Added this page
$is_management_page = in_array($current_page, $management_pages);


// --- 1. DATA FETCHING ---

// -- Get Sort Order --
$valid_sorts = [
    'gold' => 'gold DESC, silver DESC, bronze DESC',
    'silver' => 'silver DESC, gold DESC, bronze DESC',
    'bronze' => 'bronze DESC, gold DESC, silver DESC',
    'total' => 'total DESC, gold DESC, silver DESC, bronze DESC'
];
$sort_key = isset($_GET['sort']) && array_key_exists($_GET['sort'], $valid_sorts) ? $_GET['sort'] : 'total';
$order_by_sql = $valid_sorts[$sort_key];

// --- Query 1: Overall Medal Standings (Adapted from home.php) ---
// This query is correct and uses the medal counts from the categories table.
$medal_tally = [];
$sql_medals = "SELECT 
                    C.college_name, C.logo_url, C.college_code,
                    SUM(CASE WHEN Cat.gold_winner_college_id = C.college_id THEN Cat.gold_count ELSE 0 END) AS gold,
                    SUM(CASE WHEN Cat.silver_winner_college_id = C.college_id THEN Cat.silver_count ELSE 0 END) AS silver,
                    SUM(CASE WHEN Cat.bronze_winner_college_id = C.college_id THEN Cat.bronze_count ELSE 0 END) AS bronze,
                    (SUM(CASE WHEN Cat.gold_winner_college_id = C.college_id THEN Cat.gold_count ELSE 0 END) +
                     SUM(CASE WHEN Cat.silver_winner_college_id = C.college_id THEN Cat.silver_count ELSE 0 END) +
                     SUM(CASE WHEN Cat.bronze_winner_college_id = C.college_id THEN Cat.bronze_count ELSE 0 END)) AS total
                FROM colleges C
                LEFT JOIN categories Cat ON (
                    C.college_id = Cat.gold_winner_college_id OR 
                    C.college_id = Cat.silver_winner_college_id OR 
                    C.college_id = Cat.bronze_winner_college_id
                ) AND Cat.status = 'Results Approved'
                GROUP BY C.college_id, C.college_name, C.logo_url, C.college_code
                ORDER BY $order_by_sql, C.college_name ASC";

$stmt_medals = $conn->prepare($sql_medals);
if ($stmt_medals) {
    $stmt_medals->execute();
    $result = $stmt_medals->get_result();
    if ($result) {
        $medal_tally = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt_medals->close();
} else {
    error_log("Error preparing medal tally statement: " . $conn->error);
}

// --- Query 2: Match Results Report (MODIFIED) ---
// Uses logic from view_all_matches.php (CASE statement)
// Filters for 'Results Approved' as requested ("complete" status)
$match_results = [];
$sql_matches = "SELECT 
                    CONCAT(t1.college_name, ' vs ', t2.college_name) AS matchup,
                    CONCAT(m.score1, ' - ', m.score2) AS scores,
                    w.college_name AS winner_name,
                    
                    -- This is the logic from view_all_matches.php
                    CASE 
                        WHEN c.status = 'Results Rejected' THEN 'Results Rejected'
                        ELSE m.status 
                    END AS status,

                    DATE_FORMAT(m.match_date, '%Y-%m-%d') AS date_formatted,
                    DATE_FORMAT(m.match_time, '%h:%i %p') AS time_formatted,
                    m.venue,
                    ge.event_name, 
                    c.category_name,
                    g.game_name
                FROM matches m
                LEFT JOIN categories c ON m.category_id = c.category_id
                LEFT JOIN game_events ge ON c.event_id = ge.event_id
                LEFT JOIN games g ON ge.game_id = g.game_id
                LEFT JOIN colleges t1 ON m.team1_id = t1.college_id
                LEFT JOIN colleges t2 ON m.team2_id = t2.college_id
                LEFT JOIN colleges w ON m.winner_team_id = w.college_id
                
                -- This is the filter requested by the user
                -- We filter on the *final* status from the CASE statement.
                -- We interpret 'complete' status as 'Results Approved'.
                HAVING status = 'Completed'
                
                ORDER BY m.match_date DESC, m.match_time DESC
                LIMIT 50";

$result_matches = $conn->query($sql_matches);
if ($result_matches) {
    $match_results = $result_matches->fetch_all(MYSQLI_ASSOC);
} else {
    error_log("Error fetching match results: " . $conn->error);
}

// --- Query 3: Event Results Report (NEW - Based on results.php) ---
// This query is now built for your requested table structure.
$event_results = [];
$sql_events = "SELECT 
                    g.game_name,
                    ge.event_name, 
                    c.category_name,
                    DATE_FORMAT(c.event_date, '%Y-%m-%d') AS event_date_formatted,
                    gold_col.college_name AS gold_winner,
                    silver_col.college_name AS silver_winner,
                    bronze_col.college_name AS bronze_winner
                FROM categories c
                LEFT JOIN game_events ge ON c.event_id = ge.event_id
                LEFT JOIN games g ON ge.game_id = g.game_id
                LEFT JOIN colleges gold_col ON c.gold_winner_college_id = gold_col.college_id
                LEFT JOIN colleges silver_col ON c.silver_winner_college_id = silver_col.college_id
                LEFT JOIN colleges bronze_col ON c.bronze_winner_college_id = bronze_col.college_id
                WHERE c.status = 'Results Approved'
                ORDER BY g.game_name, ge.event_name, c.category_name";

$result_events = $conn->query($sql_events);
if ($result_events) {
    $event_results = $result_events->fetch_all(MYSQLI_ASSOC);
} else {
    error_log("Error fetching event results: " . $conn->error);
}

// --- Query 4: Game Distribution (for Pie Chart) ---
// Updated to group by Game, as seen in results.php
$sport_distribution = [];
$sql_sports_pie = "SELECT 
                        g.game_name, 
                        COUNT(c.category_id) AS event_count
                    FROM categories c
                    JOIN game_events ge ON c.event_id = ge.event_id
                    JOIN games g ON ge.game_id = g.game_id
                    WHERE c.status = 'Results Approved'
                    GROUP BY g.game_name
                    HAVING event_count > 0
                    ORDER BY g.game_name";

$result_sports_pie = $conn->query($sql_sports_pie);
if ($result_sports_pie) {
    $sport_distribution = $result_sports_pie->fetch_all(MYSQLI_ASSOC);
} else {
    error_log("Error fetching sport distribution: " . $conn->error);
}


// --- 2. CHART DATA PREPARATION ---
$chart_labels = json_encode(array_column($medal_tally, 'college_code'));
$chart_gold = json_encode(array_column($medal_tally, 'gold'));
$chart_silver = json_encode(array_column($medal_tally, 'silver'));
$chart_bronze = json_encode(array_column($medal_tally, 'bronze'));

$pie_labels = json_encode(array_column($sport_distribution, 'game_name'));
$pie_data = json_encode(array_column($sport_distribution, 'event_count'));

$conn->close();

// --- Helper Function for Ranks ---
function getRankHtml(int $rank): string {
    switch ($rank) {
        case 1:
            return '<img src="trophy1.svg" alt="Champion Trophy" class="medal-image-icon" style="width: 30px; height: 30px;">';
        case 2:
            return '<img src="secondplace.svg" alt="1st Runner-up" class="medal-image-icon" style="width: 30px; height: 30px;">';
        case 3:
            return '<img src="thirdplace.svg" alt="2nd Runner-up" class="medal-image-icon" style="width: 30px; height: 30px;">';
        default:
            return '<span class="rank-circle">' . $rank . '</span>';
    }
}

// --- NEW Helper Function (from view_all_matches.php) ---
function getStatusBadge($status) {
    switch ($status) {
        case 'Upcoming':
            return '<span class="badge bg-info">Upcoming</span>';
        case 'Ongoing':
            return '<span class="badge bg-success">Ongoing</span>';
        case 'Results Submitted':
            return '<span class="badge bg-warning text-dark">Results Submitted</span>';
        case 'Results Approved':
            return '<span class="badge bg-primary">Results Approved</span>';
        case 'Results Rejected':
            return '<span class="badge bg-danger">Results Rejected</span>';
        case 'Cancelled':
            return '<span class="badge bg-secondary">Cancelled</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Reports - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        /* Admin Panel Styles (from results.php) */
        :root { 
            --sidebar-width: 260px; 
            --header-height: 82px; 
            --transition: all 0.3s ease; 
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); 
            --bg-light: #F8F9FA; 
            --primary-green: #4CAF50;
            --primary-dark: #2E7D32;
            --accent-gold: #FFD700;
            --accent-silver: #C0C0C0;
            --accent-bronze: #CD7F32;
            --text-dark: #1A1A1A;
            --text-muted: #6C757D;
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
        footer {
            flex-shrink: 0;
            background: #2c3e50 !important;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            padding-left: var(--sidebar-width);
            transition: padding-left var(--transition);
            position: relative;
            z-index: 1041;
            padding-top: 1rem;
            padding-bottom: 1rem;
        }
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        .navbar-profile-icon { width: 36px; height: 36px; font-size: 36px; text-align: center; line-height: 1; border-radius: 50%; margin-right: 10px; color: rgba(255,255,255,0.8); }
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; transition: var(--transition); }
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
        .user-dropdown .dropdown-toggle .user-name { font-weight: 600; font-size: 0.95rem; }
        
        /* Sub-menu styles from results.php */
        .sidebar-nav .sub-menu {
            padding-left: 30px; /* Indent */
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
            list-style: none;
            background: rgba(0,0,0,0.1);
        }
        .sidebar-nav .sub-menu a {
            padding-top: 10px;
            padding-bottom: 10px;
            font-size: 0.95rem;
            padding-left: 35px; /* Further indent */
        }
        .sidebar-nav .sub-menu a:hover {
            background: rgba(255,255,255,0.1);
        }
        .sidebar-nav .collapse.show .sub-menu {
            max-height: 500px; /* Show the submenu */
        }
        .sidebar-nav .sidebar-chevron {
            transition: transform 0.3s ease;
            font-size: 0.7em;
        }
        .sidebar-nav a[aria-expanded="true"] .sidebar-chevron {
            transform: rotate(180deg);
        }

        /* Report Card Styles */
        .report-card {
            background-color: #FFFFFF;
            border: 1px solid #dee2e6;
            border-top: none; /* Removed to connect with tabs */
            border-radius: 0 0 16px 16px; /* Adjusted radius */
            box-shadow: var(--card-shadow);
            padding: 2rem;
            margin-bottom: 2rem;
            scroll-margin-top: 100px; 
        }
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 1.5rem;
            font-weight: 700;
            font-family: 'Poppins', sans-serif;
            color: var(--text-dark);
            padding-bottom: 1rem;
            border-bottom: 2px solid #eee;
            margin-bottom: 1.5rem;
        }
        .section-header .title-group { display: flex; align-items: center; gap: 0.75rem; }
        .section-description { font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1.5rem; }
        .report-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; border-radius: 8px; overflow: hidden; border: 1px solid #dee2e6; }
        .report-table th, .report-table td { padding: 1rem; text-align: left; border-bottom: 1px solid #dee2e6; }
        .report-table th { font-weight: 600; color: var(--text-dark); font-size: 0.85rem; background-color: var(--bg-light); text-transform: uppercase; letter-spacing: 0.5px; }
        .report-table tbody tr:nth-child(even) { background-color: var(--bg-light); }
        .report-table tbody tr:hover { background-color: #e9ecef; }
        .chart-wrapper { background: #FFFFFF; border-radius: 8px; padding: 2rem; box-shadow: var(--card-shadow); }
        .chart-title { font-size: 1.2rem; font-weight: 600; font-family: 'Poppins', sans-serif; color: var(--text-dark); margin-bottom: 1.5rem; text-align: center; }
        .chart-canvas { max-height: 350px; width: 100% !important; }
        .rank-circle { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 50%; background-color: var(--primary-green); color: white; font-weight: 600; font-size: 0.9rem; }
        .medal-icon-header { font-size: 1.1rem; }
        .gold-icon { color: var(--accent-gold); }
        .silver-icon { color: var(--accent-silver); }
        .bronze-icon { color: var(--accent-bronze); }
        
        /* New Tab Styles */
        .nav-tabs {
            border-bottom: 1px solid #dee2e6;
        }
        .nav-tabs .nav-link {
            border: 1px solid transparent;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            color: var(--text-muted);
            font-weight: 600;
            padding: 0.75rem 1.25rem;
        }
        .nav-tabs .nav-link.active {
            color: var(--primary-dark);
            background-color: #FFFFFF;
            border-color: #dee2e6 #dee2e6 #FFFFFF;
            border-bottom: 1px solid #FFFFFF;
            position: relative;
            top: 1px;
        }
        .tab-content .report-card {
            border-top-left-radius: 0; /* Connects to active tab */
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items: center justify-content-between">
            <a class="navbar-brand d-flex align-items: center" href="admin_dashboard.php">
                <img src="imageslogo.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light" style="font-size: 0.75rem;">Admin Panel</small>
                </div>
            </a>
            <div class="dropdown user-dropdown ms-auto me-2 me-lg-0">
                <a href="#" class="dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle navbar-profile-icon"></i>
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="admin_profile.php"><i class="fas fa-user-circle"></i> Profile</a></li>
                    <li><a class="dropdown-item" href="home.php" target="_blank"><i class="fas fa-globe"></i> View Public Site</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="login.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Administrator'): ?>
        <div class="sidebar" id="sidebar">
            <ul class="nav flex-column sidebar-nav">
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'admin_dashboard.php') echo 'active'; ?>" href="admin_dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($is_management_page) echo 'active'; ?>" data-bs-toggle="collapse" href="#teamsCollapse" role="button" aria-expanded="<?php echo $is_management_page ? 'true' : 'false'; ?>">
                        <i class="fas fa-users me-2"></i> <span>Manage Colleges</span> <i class="fas fa-chevron-down ms-auto sidebar-chevron"></i>
                    </a>
                    <div class="collapse <?php if ($is_management_page) echo 'show'; ?>" id="teamsCollapse">
                        <ul class="sub-menu">
                            <li class="text-muted" style="padding: 10px 25px 5px 60px;">Management</li>
                            <li><a class="nav-link <?php if ($current_page == 'colleges.php') echo 'active'; ?>" href="sd/colleges.php"><span>Manage Colleges</span></a></li>
                            <li><a class="nav-link <?php if ($current_page == 'events.php') echo 'active'; ?>" href="sd/events.php"><span>Manage Events (L1-L3)</span></a></li>
                            <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'Manage_Matches.php') echo 'active'; ?>" href="sd/Manage_Matches.php">
                                <span>Manage Matches</span>
                            </a>
                        </li>
                            <li class="text-muted" style="padding: 10px 25px 5px 60px;">Tallying</li>
                            <li><a class="nav-link <?php if ($current_page == 'results.php') echo 'active'; ?>" href="sd/results.php"><span>Approve Results</span></a></li>
                            <li><a class="nav-link <?php if ($current_page == 'reports.php') echo 'active'; ?>" href="sd/reports.php"><span>Medal Reports</span></a></li>
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
    <?php else: // Sports Director Sidebar ?>
        <div class="sidebar" id="sidebar">
            <ul class="nav flex-column sidebar-nav">
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'sports_director_dashboard.php') echo 'active'; ?>" 
                       href="sd/sports_director_dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item mt-3"><span class="nav-title">Management</span></li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'colleges.php') echo 'active'; ?>" href="sd/colleges.php">
                        <i class="fas fa-users me-2"></i> <span>Manage Colleges</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'events.php') echo 'active'; ?>" href="sd/events.php">
                        <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events (L1-L3)</span>
                    </a>
                </li>
                <!-- === THIS IS THE NEW LINK === -->
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'view_all_matches.php') ? 'active' : '' ?>" href="sd/view_all_matches.php">
                    <i class="fas fa-trophy me-2"></i> <span>View All Matches</span>
                </a>
            </li>
            <!-- === END OF NEW LINK === -->


                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'Manage_Viewreports.php') echo 'active'; ?>" href="Manage_Viewreports.php">
                        <i class="fas fa-chart-line me-2"></i> <span>View Reports</span>
                    </a>
                </li>
                
                <li class="nav-item mt-3"><span class="nav-title">Tallying</span></li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'results.php') echo 'active'; ?>" href="sd/results.php">
                        <i class="fas fa-check-double me-2"></i> <span>Approve Results</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'reports.php') echo 'active'; ?>" href="sd/reports.php">
                        <i class="fas fa-chart-line me-2"></i> <span>Medal Reports</span>
                    </a>
                </li>
                 
                <li class="nav-item mt-auto">
                    <a class="nav-link text-danger" href="login.php">
                        <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
                    </a>
                </li>
            </ul>
        </div>
    <?php endif; ?> 
    
    <main class="main-content">
        
        <h1 class="section-title mb-4">View Reports</h1>
        
        <ul class="nav nav-tabs" id="reportTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="summary-tab" data-bs-toggle="tab" data-bs-target="#summary" type="button" role="tab" aria-controls="summary" aria-selected="true">
                    <i class="fas fa-medal me-2 gold-icon"></i> Overall Medal Summary
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="matches-tab" data-bs-toggle="tab" data-bs-target="#matches" type="button" role="tab" aria-controls="matches" aria-selected="false">
                    <i class="fas fa-bullseye me-2 text-muted"></i> Match Results
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="events-tab" data-bs-toggle="tab" data-bs-target="#events" type="button" role="tab" aria-controls="events" aria-selected="false">
                    <i class="fas fa-list-check me-2 text-primary"></i> Event Results
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="graphs-tab" data-bs-toggle="tab" data-bs-target="#graphs" type="button" role="tab" aria-controls="graphs" aria-selected="false">
                    <i class="fas fa-chart-bar me-2 text-success"></i> Graphical Reports
                </button>
            </li>
        </ul>

        <div class="tab-content" id="reportTabContent">
            
            <div class="tab-pane fade show active" id="summary" role="tabpanel" aria-labelledby="summary-tab">
                <div class="report-card">
                    <div class="section-header">
                        <div class="title-group">
                            <span class="fa-icon gold-icon"><i class="fas fa-medal"></i></span> Overall Medal Summary
                        </div>
                        <div style="width: 300px;">
                            <label for="sortFilter" class="form-label fw-bold small mb-1">Sort By:</label>
                            <select class="form-select form-select-sm" id="sortFilter" onchange="window.location.href = 'Manage_Viewreports.php?sort=' + this.value;">
                                <option value="total" <?= ($sort_key == 'total') ? 'selected' : '' ?>>Total Medals</option>
                                <option value="gold" <?= ($sort_key == 'gold') ? 'selected' : '' ?>>Gold Medals</option>
                                <option value="silver" <?= ($sort_key == 'silver') ? 'selected' : '' ?>>Silver Medals</option>
                                <option value="bronze" <?= ($sort_key == 'bronze') ? 'selected' : '' ?>>Bronze Medals</option>
                            </select>
                        </div>
                    </div>
                    <p class="section-description">Total medals count per college, ranked by your selection. Updates automatically.</p>
                
                    <div class="table-responsive">
                        <table class="report-table" id="medalSummaryTable">
                            <thead>
                                <tr>
                                    <th style="width: 10%;">Rank</th>
                                    <th style="width: 40%;">College/Department</th>
                                    <th style="width: 12%;"><span class="medal-icon-header gold-icon"><i class="fas fa-medal"></i></span> Gold</th>
                                    <th style="width: 12%;"><span class="medal-icon-header silver-icon"><i class="fas fa-medal"></i></span> Silver</th>
                                    <th style="width: 12%;"><span class="medal-icon-header bronze-icon"><i class="fas fa-medal"></i></span> Bronze</th>
                                    <th style="width: 14%;">Total</th>
                                </tr>
                            </thead>
                            <tbody id="medalSummaryBody">
                                <?php if (empty($medal_tally)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">No medal data available yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($medal_tally as $index => $row): ?>
                                        <tr>
                                            <td><?= getRankHtml($index + 1) ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($row['college_code']) ?></strong>
                                                <br>
                                                <small class="text-muted"><?= htmlspecialchars($row['college_name']) ?></small>
                                            </td>
                                            <td><?= $row['gold'] ?></td>
                                            <td><?= $row['silver'] ?></td>
                                            <td><?= $row['bronze'] ?></td>
                                            <td><strong><?= $row['total'] ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="matches" role="tabpanel" aria-labelledby="matches-tab">
                 <div class="report-card">
                    <div class="section-header">
                        <div class="title-group">
                            <span class="fa-icon text-muted"><i class="fas fa-futbol"></i></span> Match Results Report
                        </div>
                    </div>
                    <p class="section-description">Detailed, match-by-match results for all approved games.</p>
                    
                    <div class="table-responsive">
                        <table class="report-table" id="matchResultsTable">
                            <thead>
                                <tr>
                                    <th>Matchup</th>
                                    <th>Score</th>
                                    <th>Winner</th>
                                    <th>Status</th>
                                    <th>Date & Time</th>
                                    <th>Venue</th>
                                </tr>
                            </thead>
                            <tbody id="matchResultsBody">
                                <?php if (empty($match_results)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">
                                            No approved match results available yet.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($match_results as $match): ?>
                                        <tr>
                                            <td>
    <strong><?= htmlspecialchars($match['matchup'] ?? 'Invalid Matchup') ?></strong>
    <br>
    <small class="text-muted"><?= htmlspecialchars($match['event_name'] ?? 'N/A') ?> (<?= htmlspecialchars($match['category_name'] ?? 'N/A') ?>)</small>
</td>
                                            <td><strong><?= htmlspecialchars($match['scores'] ?? 'N/A') ?></strong></td>
                                            <td><strong><?= htmlspecialchars($match['winner_name'] ?? 'N/A') ?></strong></td>
                                            
                                            <td><?= getStatusBadge($match['status']) ?></td>
                                            
                                            <td>
                                                <?= htmlspecialchars($match['date_formatted']) ?>
                                                <br>
                                                <small class="text-muted"><?= htmlspecialchars($match['time_formatted']) ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($match['venue'] ?? 'N/A') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="events" role="tabpanel" aria-labelledby="events-tab">
                <div class="report-card">
                    <div class="section-header">
                        <div class="title-group">
                            <span class="fa-icon text-primary"><i class="fas fa-list-check"></i></span> Event Results Report
                        </div>
                    </div>
                    <p class="section-description">Final winners for each game, event, and category.</p>
                    
                    <div class="table-responsive">
                        <table class="report-table" id="eventResultsTable">
                        <thead>
                            <tr>
                                <th>Game</th>
                                <th>Event</th>
                                <th>Categories</th>
                                <th>Date</th>
                                <th>🥇 Gold</th>
                                <th>🥈 Silver</th>
                                <th>🥉 Bronze</th>
                            </tr>
                        </thead>
                            <tbody id="eventResultsBody">
                                <?php if (empty($event_results)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            No approved event results available yet.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($event_results as $event): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($event['game_name']) ?></strong></td>
                                            <td><small class="text-muted"><?= htmlspecialchars($event['event_name']) ?></small></td>
                                            <td><strong><?= htmlspecialchars($event['category_name']) ?></strong></td>
                                            <td><?= htmlspecialchars($event['event_date_formatted'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($event['gold_winner'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($event['silver_winner'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($event['bronze_winner'] ?? 'N/A') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="tab-pane fade" id="graphs" role="tabpanel" aria-labelledby="graphs-tab">
                <div class="report-card">
                    <div class="section-header">
                        <div class="title-group">
                            <span class="fa-icon text-success"><i class="fas fa-chart-bar"></i></span> Graphical Reports
                        </div>
                    </div>
                    <p class="section-description">Visual representation of medal distribution and performance.</p>

                    <div class="row g-4">
                        <div class="col-12"> 
                            <div class="chart-wrapper">
                                <h3 class="chart-title">Medal Counts per College</h3>
                                <canvas id="medalBarChart" class="chart-canvas"></canvas>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="chart-wrapper">
                                <h3 class="chart-title">Category Distribution per Game</h3>
                                <canvas id="sportPieChart" class="chart-canvas"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        
        </div> </main>

    <footer class="bg-dark text-white py-4">
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
            <small class="text-muted">Developed by Tsunayoshi Sawada</small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <script>
        // --- MODIFIED JAVASCRIPT ---
        
        // Store chart instances
        let barChartInstance = null;
        let pieChartInstance = null;

        // --- NEW: Register the datalabels plugin globally ---
        Chart.register(ChartDataLabels);

        document.addEventListener('DOMContentLoaded', () => {
            
            const graphsTabEl = document.querySelector('#graphs-tab');
            
            if (graphsTabEl) {
                // Add event listener to initialize charts when tab is shown
                graphsTabEl.addEventListener('shown.bs.tab', event => {
                    console.log('Graphs tab shown, initializing charts...');
                    if (!barChartInstance) {
                        barChartInstance = initializeBarChart();
                    }
                    if (!pieChartInstance) {
                        pieChartInstance = initializePieChart();
                    }
                });
            }

            // Check if the active tab on load is *already* the graphs tab
            // (e.g., if loaded via URL hash #graphs)
            if (document.querySelector('.tab-pane.active#graphs')) {
                 if (!barChartInstance) {
                    barChartInstance = initializeBarChart();
                }
                if (!pieChartInstance) {
                    pieChartInstance = initializePieChart();
                }
            }
        });

        // Initialize Medal Bar Chart
        function initializeBarChart() {
            const ctx = document.getElementById('medalBarChart');
            if (!ctx) return null;
            
            // Safety check: destroy old chart if it exists
            const existingChart = Chart.getChart(ctx);
            if (existingChart) {
                existingChart.destroy();
            }
            
            return new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?= $chart_labels; ?>,
                    datasets: [
                        {
                            label: 'Gold',
                            data: <?= $chart_gold; ?>,
                            backgroundColor: 'rgba(255, 215, 0, 0.7)',
                            borderColor: 'rgba(255, 215, 0, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Silver',
                            data: <?= $chart_silver; ?>,
                            backgroundColor: 'rgba(192, 192, 192, 0.7)',
                            borderColor: 'rgba(192, 192, 192, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Bronze',
                            data: <?= $chart_bronze; ?>,
                            backgroundColor: 'rgba(205, 127, 50, 0.7)',
                            borderColor: 'rgba(205, 127, 50, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        // Disable datalabels for the bar chart
                        datalabels: {
                            display: false,
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1 }
                        },
                        x: {
                            stacked: false,
                            // --- ADD THIS 'ticks' OBJECT ---
                            ticks: {
                                font: {
                                    weight: 'bold',
                                    size: 12 // You can also adjust the size if you want
                                }
                            }
                            // --- END OF ADDED CODE ---
                        }
                    }
                }
            });
        }

// --- UPDATED Pie Chart Function ---
        function initializePieChart() {
            const ctx = document.getElementById('sportPieChart');
            if (!ctx) return null;

            // Safety check: destroy old chart if it exists
            const existingChart = Chart.getChart(ctx);
            if (existingChart) {
                existingChart.destroy();
            }

            // 1. Get the raw data from PHP (which are strings)
            const pieDataRaw = <?= $pie_data; ?>;
            
            // 2. NEW: Convert all items in the array from strings to numbers
            const pieData = pieDataRaw.map(Number);

            // 3. This 'reduce' function will now use the new 'pieData' array of
            //    NUMBERS, and the addition will work correctly.
            const total = pieData.reduce((acc, val) => acc + val, 0);

            if (pieData.length === 0) {
                ctx.parentElement.innerHTML = '<p class="text-center text-muted">No game data available for chart.</p>';
                return null;
            }
            
            const colors = [
                'rgba(255, 99, 132, 0.7)', 'rgba(54, 162, 235, 0.7)', 'rgba(255, 206, 86, 0.7)',
                'rgba(75, 192, 192, 0.7)', 'rgba(153, 102, 255, 0.7)', 'rgba(255, 159, 64, 0.7)',
                'rgba(201, 203, 207, 0.7)', 'rgba(99, 255, 132, 0.7)'
            ];

            return new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: <?= $pie_labels; ?>,
                    datasets: [{
                        data: pieData,
                        backgroundColor: colors.slice(0, pieData.length),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                        // --- THIS IS THE UPDATED CONFIGURATION ---
                        datalabels: {
                            // 1. Only display when the slice is 'active' (hovered)
                            display: function(context) {
                                // context.active is true when hovered
                                return context.active; 
                            },
                            
                            // 2. This formatter CORRECTLY calculates the percentage
                            formatter: (value, context) => {
                                if (total === 0) {
                                    return '0%';
                                }
                                // Calculates the true percentage
                                const percentage = (value / total * 100).toFixed(1) + '%';
                                return percentage;
                            },
                            
                            // 3. Style the label (same as before)
                            color: '#fff', 
                            font: {
                                weight: 'bold',
                                size: 14,
                            },
                            backgroundColor: 'rgba(0, 0, 0, 0.7)',
                            borderRadius: 4,
                            padding: 6
                        }
                    }
                }
            });
        }
        

    </script>
</body>
</html>