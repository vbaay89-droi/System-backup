<?php
session_start();
// Use a relative path to your main db_connect.php
require_once '../db_connect.php'; 

// 1. SECURITY & ACCESS CONTROL
if (!isset($_SESSION['role']) || 
    ($_SESSION['role'] !== 'Sports Director' && $_SESSION['role'] !== 'Administrator')
) {
    header('Location: ../login.php'); 
    exit();
}

// 2. SESSION SETUP
if (!isset($_SESSION['user_id'])) {
    die("Session error: User ID is not set.");
}
$current_user_id = $_SESSION['user_id'];
$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Sports Director';
$current_page = 'colleges.php'; // Keeps the 'Manage Teams' nav active
$default_logo = 'images/default_avatar.png';

// Sidebar Logic
$event_pages = ['Manage_Games.php', 'Manage_Game_Events.php', 'Manage_Categories.php'];
$is_event_page = in_array($current_page, $event_pages);
$management_pages = ['colleges.php', 'events.php', 'results.php', 'reports.php'];
$is_management_page = in_array($current_page, $management_pages);

// --- DATA FETCHING ---

// 1. Validate ID
$college_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
if ($college_id === 0) {
    $_SESSION['message'] = "Invalid Team ID provided.";
    $_SESSION['message_type'] = "danger";
    header('Location: colleges.php');
    exit();
}

// 2. Fetch Basic Team Details
// Note: DB table is still 'colleges', but columns have changed
$stmt = $conn->prepare("SELECT * FROM colleges WHERE college_id = ?");
$stmt->bind_param("i", $college_id);
$stmt->execute();
$college = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$college) {
    $_SESSION['message'] = "No team found with that ID.";
    $_SESSION['message_type'] = "danger";
    header('Location: colleges.php');
    exit();
}

// 3. Get Overall Medal Summary
$stmt_summary = $conn->prepare("
    SELECT
      SUM(CASE WHEN c.gold_winner_college_id = ? THEN c.gold_count ELSE 0 END) AS GoldCount,
      SUM(CASE WHEN c.silver_winner_college_id = ? THEN c.silver_count ELSE 0 END) AS SilverCount,
      SUM(CASE WHEN c.bronze_winner_college_id = ? THEN c.bronze_count ELSE 0 END) AS BronzeCount
    FROM categories c
    WHERE c.status = 'Results Approved'
    AND (
      c.gold_winner_college_id = ? 
      OR c.silver_winner_college_id = ?
      OR c.bronze_winner_college_id = ?
    )
");
$stmt_summary->bind_param("iiiiii", $college_id, $college_id, $college_id, $college_id, $college_id, $college_id);
$stmt_summary->execute();
$medal_summary = $stmt_summary->get_result()->fetch_assoc();
$stmt_summary->close();

// 4. Get Medal Breakdown by Event (L2) - For "Top 5"
$event_breakdown = [];
$stmt_breakdown = $conn->prepare("
    SELECT
      ge.event_name,
      SUM(CASE WHEN c.gold_winner_college_id = ? THEN c.gold_count ELSE 0 END) AS GoldCount,
      SUM(CASE WHEN c.silver_winner_college_id = ? THEN c.silver_count ELSE 0 END) AS SilverCount,
      SUM(CASE WHEN c.bronze_winner_college_id = ? THEN c.bronze_count ELSE 0 END) AS BronzeCount
    FROM categories c
    JOIN game_events ge ON c.event_id = ge.event_id
    WHERE c.status = 'Results Approved'
    AND (
      c.gold_winner_college_id = ?
      OR c.silver_winner_college_id = ?
      OR c.bronze_winner_college_id = ?
    )
    GROUP BY ge.event_id, ge.event_name
    HAVING GoldCount > 0 OR SilverCount > 0 OR BronzeCount > 0
    ORDER BY ge.event_name
");
$stmt_breakdown->bind_param("iiiiii", $college_id, $college_id, $college_id, $college_id, $college_id, $college_id);
$stmt_breakdown->execute();
$result_breakdown = $stmt_breakdown->get_result();
while ($row = $result_breakdown->fetch_assoc()) {
    $event_breakdown[] = $row;
}
$stmt_breakdown->close();

// 5. Get Medal Breakdown by Category (L3) - For Overview Tab
$category_medal_breakdown = [];
$stmt_cat_breakdown = $conn->prepare("
    SELECT
      g.game_name,
      ge.event_name,
      c.category_name,
      SUM(CASE WHEN c.gold_winner_college_id = ? THEN c.gold_count ELSE 0 END) AS GoldCount,
      SUM(CASE WHEN c.silver_winner_college_id = ? THEN c.silver_count ELSE 0 END) AS SilverCount,
      SUM(CASE WHEN c.bronze_winner_college_id = ? THEN c.bronze_count ELSE 0 END) AS BronzeCount
    FROM categories c
    JOIN game_events ge ON c.event_id = ge.event_id
    JOIN games g ON ge.game_id = g.game_id
    WHERE c.status = 'Results Approved'
    AND (
      c.gold_winner_college_id = ?
      OR c.silver_winner_college_id = ?
      OR c.bronze_winner_college_id = ?
    )
    GROUP BY c.category_id, g.game_name, ge.event_name, c.category_name
    HAVING GoldCount > 0 OR SilverCount > 0 OR BronzeCount > 0
    ORDER BY g.game_name, ge.event_name, c.category_name
");
$stmt_cat_breakdown->bind_param("iiiiii", $college_id, $college_id, $college_id, $college_id, $college_id, $college_id);
$stmt_cat_breakdown->execute();
$result_cat_breakdown = $stmt_cat_breakdown->get_result();
while ($row = $result_cat_breakdown->fetch_assoc()) {
    $category_medal_breakdown[] = $row;
}
$stmt_cat_breakdown->close();

// 6. Get Medal Breakdown by Game (L1) - For Strengths Tab
$game_breakdown = [];
$stmt_game_breakdown = $conn->prepare("
    SELECT
      g.game_name,
      SUM(CASE WHEN c.gold_winner_college_id = ? THEN c.gold_count ELSE 0 END) AS GoldCount,
      SUM(CASE WHEN c.silver_winner_college_id = ? THEN c.silver_count ELSE 0 END) AS SilverCount,
      SUM(CASE WHEN c.bronze_winner_college_id = ? THEN c.bronze_count ELSE 0 END) AS BronzeCount
    FROM categories c
    JOIN game_events ge ON c.event_id = ge.event_id
    JOIN games g ON ge.game_id = g.game_id
    WHERE c.status = 'Results Approved'
    AND (
      c.gold_winner_college_id = ?
      OR c.silver_winner_college_id = ?
      OR c.bronze_winner_college_id = ?
    )
    GROUP BY g.game_id, g.game_name
    HAVING GoldCount > 0 OR SilverCount > 0 OR BronzeCount > 0
    ORDER BY g.game_name
");
$stmt_game_breakdown->bind_param("iiiiii", $college_id, $college_id, $college_id, $college_id, $college_id, $college_id);
$stmt_game_breakdown->execute();
$result_game_breakdown = $stmt_game_breakdown->get_result();
while ($row = $result_game_breakdown->fetch_assoc()) {
    $game_breakdown[] = $row;
}
$stmt_game_breakdown->close();

// 7. Process Top Performing Events
$top_performing_events = $event_breakdown;
usort($top_performing_events, function($a, $b) {
    if ($a['GoldCount'] != $b['GoldCount']) {
        return $b['GoldCount'] - $a['GoldCount'];
    }
    if ($a['SilverCount'] != $b['SilverCount']) {
        return $b['SilverCount'] - $a['SilverCount'];
    }
    return $b['BronzeCount'] - $a['BronzeCount'];
});
$top_performing_events = array_slice($top_performing_events, 0, 5);

// 8. Get Approved Match Results
$match_results = [];
$stmt_matches = $conn->prepare("
    SELECT
      g.game_name,
      ge.event_name,
      c.category_name,
      c.gold_winner_college_id AS winner_gold_college_id,
      c.silver_winner_college_id AS winner_silver_college_id,
      c.bronze_winner_college_id AS winner_bronze_college_id,
      wg.college_name AS gold_winner_name,
      ws.college_name AS silver_winner_name,
      wb.college_name AS bronze_winner_name
    FROM categories c
    JOIN game_events ge ON c.event_id = ge.event_id
    JOIN games g ON ge.game_id = g.game_id
    LEFT JOIN colleges wg ON c.gold_winner_college_id = wg.college_id
    LEFT JOIN colleges ws ON c.silver_winner_college_id = ws.college_id
    LEFT JOIN colleges wb ON c.bronze_winner_college_id = wb.college_id
    WHERE c.status = 'Results Approved'
    AND (
      c.gold_winner_college_id = ?
      OR c.silver_winner_college_id = ?
      OR c.bronze_winner_college_id = ?
    )
    ORDER BY g.game_name, ge.event_name, c.category_name
");
$stmt_matches->bind_param("iii", $college_id, $college_id, $college_id);
$stmt_matches->execute();
$result_matches = $stmt_matches->get_result();
while ($row = $result_matches->fetch_assoc()) {
    $match_results[] = $row;
}
$stmt_matches->close(); 

// 9. Get Upcoming Matches
$upcoming_matches = [];
$stmt_upcoming = $conn->prepare("
    SELECT
        m.match_date,
        m.match_time,
        m.venue,
        m.status,
        g.game_name,
        ge.event_name,
        c.category_name,
        CASE
            WHEN m.team1_id = ? THEN t2.college_name
            ELSE t1.college_name
        END AS opponent_name
    FROM matches m
    JOIN categories c ON m.category_id = c.category_id
    JOIN game_events ge ON c.event_id = ge.event_id
    JOIN games g ON ge.game_id = g.game_id
    LEFT JOIN colleges t1 ON m.team1_id = t1.college_id
    LEFT JOIN colleges t2 ON m.team2_id = t2.college_id
    WHERE
        (m.team1_id = ? OR m.team2_id = ?)
        AND m.status IN ('Upcoming', 'Ongoing')
        AND c.category_type = 'Match'
    ORDER BY
        m.match_date ASC, m.match_time ASC
");
$stmt_upcoming->bind_param("iii", $college_id, $college_id, $college_id);
$stmt_upcoming->execute();
$result_upcoming = $stmt_upcoming->get_result();
while ($row = $result_upcoming->fetch_assoc()) {
    $upcoming_matches[] = $row;
}
$stmt_upcoming->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($college['college_name']) ?> - Team Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ... CSS Unchanged ... */
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
        footer { flex-shrink: 0; background: #2c3e50 !important; box-shadow: 0 -2px 10px rgba(0,0,0,0.1); margin-left: var(--sidebar-width); transition: margin-left var(--transition); position: relative; z-index: 1041; }
        .sidebar.minimized ~ footer { margin-left: var(--sidebar-min-width); } /* Fixed Footer Logic */
        
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        .card { border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; transition: var(--transition); }
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
        .user-dropdown .dropdown-toggle .user-name { font-weight: 600; font-size: 0.95rem; }
        .navbar-profile-icon { width: 36px; height: 36px; font-size: 36px; text-align: center; line-height: 1; border-radius: 50%; margin-right: 10px; color: rgba(255,255,255,0.8); }

        .profile-header { background: #ffffff; border-radius: 15px; box-shadow: var(--card-shadow); }
        .profile-logo { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 5px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .stat-card-mini { text-align: center; padding: 1rem; border-radius: 10px; background: var(--bg-light); }
        .stat-card-mini .count { font-size: 2rem; font-weight: 700; }
        .stat-card-mini .label { font-size: 0.9rem; text-transform: uppercase; color: #6c757d; }
        .stat-gold .count { color: #ffc107; }
        .stat-silver .count { color: #6c757d; }
        .stat-bronze .count { color: #cd7f32; }
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
            </ul>
        </div>
    <?php endif; ?> 

    <div class="main-content">
        <div class="container-fluid">
            
            <nav aria-label="breadcrumb" class="mb-2">
              <ol class="breadcrumb">
                <?php if ($_SESSION['role'] === 'Administrator'): ?>
                    <li class="breadcrumb-item"><a href="../admin_dashboard.php">Dashboard</a></li>
                <?php else: ?>
                    <li class="breadcrumb-item"><a href="sports_director_dashboard.php">Dashboard</a></li>
                <?php endif; ?>
                <li class="breadcrumb-item"><a href="colleges.php">Manage Teams</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($college['college_name']) ?></li>
              </ol>
            </nav>

            <div class="profile-header p-4 mb-4">
                <div class="row align-items-center">
                    <div class="col-md-3 col-lg-2 text-center text-md-start">
                        <?php
                            $logo_path = (!empty($college['logo_url'])) ? $college['logo_url'] : $default_logo;
                        ?>
                        <img src="../<?= htmlspecialchars($logo_path) ?>" alt="Logo" class="profile-logo"
                             onerror="this.onerror=null; this.src='../<?= $default_logo ?>'">
                    </div>
                    <div class="col-md-9 col-lg-10 text-center text-md-start mt-3 mt-md-0">
                        <h1 class="section-title mb-1"><?= htmlspecialchars($college['college_name']) ?></h1>
                        
                        <?php if (!empty($college['slogan'])): ?>
                        <p class="text-muted fst-italic mb-2">
                            "<?= htmlspecialchars($college['slogan']) ?>"
                        </p>
                        <?php endif; ?>

                        <p class="text-muted fs-5 mb-0">
                            <i class="fas fa-user-tie me-2"></i>Team Manager: 
                            <strong><?= htmlspecialchars($college['team_manager'] ?? 'N/A') ?></strong>
                        </p>
                        </div>
                </div>
                <hr>
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-mini stat-gold">
                            <div class="count"><?= $medal_summary['GoldCount'] ?? 0 ?></div>
                            <div class="label fw-bold">Gold Medals</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-mini stat-silver">
                            <div class="count"><?= $medal_summary['SilverCount'] ?? 0 ?></div>
                            <div class="label fw-bold">Silver Medals</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-mini stat-bronze">
                            <div class="count"><?= $medal_summary['BronzeCount'] ?? 0 ?></div>
                            <div class="label fw-bold">Bronze Medals</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-mini">
                            <div class="count text-primary">
                                <?= ($medal_summary['GoldCount'] ?? 0) + ($medal_summary['SilverCount'] ?? 0) + ($medal_summary['BronzeCount'] ?? 0) ?>
                            </div>
                            <div class="label fw-bold">Total Medals</div>
                        </div>
                    </div>
                </div>
            </div>

            <ul class="nav nav-tabs nav-fill mb-3" id="teamProfileTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab" aria-controls="overview" aria-selected="true">
                        <i class="fas fa-chart-pie me-2"></i>Overview & Medals
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="results-tab" data-bs-toggle="tab" data-bs-target="#results" type="button" role="tab" aria-controls="results" aria-selected="false">
                        <i class="fas fa-trophy me-2"></i>Match Results
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="upcoming-tab" data-bs-toggle="tab" data-bs-target="#upcoming" type="button" role="tab" aria-controls="upcoming" aria-selected="false">
                        <i class="fas fa-calendar-alt me-2"></i>Upcoming Matches
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="strengths-tab" data-bs-toggle="tab" data-bs-target="#strengths" type="button" role="tab" aria-controls="strengths" aria-selected="false">
                        <i class="fas fa-chart-bar me-2"></i>Strengths & Participation
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="teamProfileTabsContent">
                
                <div class="tab-pane fade show active" id="overview" role="tabpanel" aria-labelledby="overview-tab">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Medal Breakdown by Category (L3)</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Game</th>
                                            <th>Event</th>
                                            <th>Category</th>
                                            <th class="text-center">Gold <i class="fas fa-medal" style="color: #ffc107;"></i></th>
                                            <th class="text-center">Silver <i class="fas fa-medal" style="color: #6c757d;"></i></th>
                                            <th class="text-center">Bronze <i class="fas fa-medal" style="color: #cd7f32;"></i></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($category_medal_breakdown)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center text-muted p-4">This team has not won any medals yet.</td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php foreach ($category_medal_breakdown as $cat): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($cat['game_name']) ?></td>
                                                <td><?= htmlspecialchars($cat['event_name']) ?></td>
                                                <td><strong><?= htmlspecialchars($cat['category_name']) ?></strong></td>
                                                <td class="text-center fs-5"><?= $cat['GoldCount'] ?></td>
                                                <td class="text-center fs-5"><?= $cat['SilverCount'] ?></td>
                                                <td class="text-center fs-5"><?= $cat['BronzeCount'] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="results" role="tabpanel" aria-labelledby="results-tab">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Approved Match Results</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Game</th>
                                            <th>Event</th>
                                            <th>Category</th>
                                            <th>Gold Winner <i class="fas fa-medal" style="color: #ffc107;"></i></th>
                                            <th>Silver Winner <i class="fas fa-medal" style="color: #6c757d;"></i></th>
                                            <th>Bronze Winner <i class="fas fa-medal" style="color: #cd7f32;"></i></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($match_results)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center text-muted p-4">No approved results found involving this team.</td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php foreach ($match_results as $match): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($match['game_name']) ?></td>
                                            <td><?= htmlspecialchars($match['event_name']) ?></td>
                                            <td><?= htmlspecialchars($match['category_name']) ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($match['gold_winner_name'] ?? 'N/A') ?></strong>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($match['silver_winner_name'] ?? 'N/A') ?></strong>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($match['bronze_winner_name'] ?? 'N/A') ?></strong>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="upcoming" role="tabpanel" aria-labelledby="upcoming-tab">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Upcoming Matches</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Game</th>
                                            <th>Event</th>
                                            <th>Category</th>
                                            <th>Opponent</th>
                                            <th>Date/Time</th>
                                            <th>Venue</th>
                                            <th>Status</th>
                                        </tr>
                                        </thead>
                                    <tbody>
                                        <?php if (empty($upcoming_matches)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted p-4">
                                                    <p class="mb-0">No upcoming matches scheduled for this team.</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($upcoming_matches as $match): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($match['game_name']) ?></td>
                                                    <td><?= htmlspecialchars($match['event_name']) ?></td>
                                                    <td><?= htmlspecialchars($match['category_name']) ?></td>
                                                    <td><strong><?= htmlspecialchars($match['opponent_name']) ?></strong></td>
                                                    <td>
                                                        <?= $match['match_date'] ? htmlspecialchars(date('M d, Y', strtotime($match['match_date']))) : 'TBA' ?>
                                                        <small class="text-muted d-block">
                                                            <?= $match['match_time'] ? htmlspecialchars(date('g:i A', strtotime($match['match_time']))) : '' ?>
                                                        </small>
                                                    </td>
                                                    <td><?= htmlspecialchars($match['venue']) ?></td>
                                                    <td>
                                                        <?php 
                                                        $badge_class = 'bg-secondary';
                                                        $status_lower = strtolower($match['status']);
                                                        if ($status_lower == 'upcoming') {
                                                            $badge_class = 'bg-info';
                                                        } elseif ($status_lower == 'ongoing') {
                                                            $badge_class = 'bg-primary';
                                                        }
                                                        ?>
                                                        <span class="badge <?= $badge_class ?>"><?= htmlspecialchars($match['status']) ?></span>
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

                <div class="tab-pane fade" id="strengths" role="tabpanel" aria-labelledby="strengths-tab">
                    <div class="row g-4">
                        
                        <div class="col-lg-7">
                            <div class="card h-100">
                                <div class="card-header">
                                    <h5 class="mb-0">Medal Summary by Game Type (L1)</h5>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted">Shows all L1 Games where this team has won at least one medal.</p>
                                    <ul class="list-group list-group-flush">
                                        <?php if (empty($game_breakdown)): ?>
                                            <li class="list-group-item text-center text-muted">No medal-winning participation recorded yet.</li>
                                        <?php endif; ?>
                                        <?php foreach ($game_breakdown as $game): ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-start py-3">
                                                <div class="ms-2 me-auto">
                                                    <div class="fw-bold fs-5"><?= htmlspecialchars($game['game_name']) ?></div>
                                                </div>
                                                <span class="badge bg-warning rounded-pill fs-6" title="Gold"><i class="fas fa-medal me-1"></i><?= $game['GoldCount'] ?></span>
                                                <span class="badge bg-secondary rounded-pill fs-6 ms-2" title="Silver"><i class="fas fa-medal me-1"></i><?= $game['SilverCount'] ?></span>
                                                <span class="badge rounded-pill fs-6 ms-2" style="background-color: #cd7f32;" title="Bronze"><i class="fas fa-medal me-1"></i><?= $game['BronzeCount'] ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-5">
                            <div class="card h-100">
                                <div class="card-header">
                                    <h5 class="mb-0">Top 5 Performing Events (L2)</h5>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted">Ranking of specific L2 Events based on medals won.</p>
                                    <ul class="list-group list-group-flush">
                                        <?php if (empty($top_performing_events)): ?>
                                            <li class="list-group-item text-center text-muted">No medals won yet.</li>
                                        <?php endif; ?>
                                        <?php foreach ($top_performing_events as $index => $event): ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-start py-3">
                                                <div class="ms-2 me-auto">
                                                    <div class="fw-bold">
                                                        <span class="badge bg-primary rounded-pill me-2" style="font-size: 0.9em;">#<?= $index + 1 ?></span>
                                                        <?= htmlspecialchars($event['event_name']) ?>
                                                    </div>
                                                </div>
                                                <div class="d-flex ms-2">
                                                    <span class="badge bg-warning rounded-pill" title="Gold"><i class="fas fa-medal"></i> <?= $event['GoldCount'] ?></span>
                                                    <span class="badge bg-secondary rounded-pill ms-1" title="Silver"><i class="fas fa-medal"></i> <?= $event['SilverCount'] ?></span>
                                                    <span class="badge rounded-pill ms-1" style="background-color: #cd7f32;" title="Bronze"><i class="fas fa-medal"></i> <?= $event['BronzeCount'] ?></span>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>

                    </div>
                </div> 
            
            </div> </div> </div> 
    
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
            
            // Sidebar Toggle (for Mobile)
            const sidebarToggle = document.getElementById('sidebarToggle');
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                });
            }
        });
    </script>
</body>
</html>