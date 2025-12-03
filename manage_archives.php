<?php
session_start();
require_once 'config.php'; 

// 1. SECURITY & ACCESS CONTROL
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Sports Director') {
    header('Location: login.php'); // Fixed Path
    exit();
}

$current_user_id = $_SESSION['user_id'];

// --- AJAX HANDLER: FETCH REPORT DATA ---
if (isset($_GET['ajax_fetch_report']) && !empty($_GET['archive_id'])) {
    header('Content-Type: application/json');
    
    $archive_id = (int)$_GET['archive_id'];
    $stmt = $conn->prepare("SELECT * FROM archived_seasons WHERE archive_id = ?");
    $stmt->bind_param("i", $archive_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode([
            'success' => true,
            'season_name' => $row['season_name'],
            'archived_at' => $row['archived_at'],
            'medals' => json_decode($row['medal_standing_json'] ?? '[]'),
            'matches' => json_decode($row['matches_json'] ?? '[]'),
            'events' => json_decode($row['events_json'] ?? '[]'),
            'teams' => json_decode($row['teams_json'] ?? '[]'),
            'officials' => json_decode($row['officials_json'] ?? '[]'),
            'stats' => json_decode($row['stats_json'] ?? '{}')
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Archive not found']);
    }
    exit();
}

// --- FETCH NAME LOGIC ---
$stmt_name = $conn->prepare("SELECT full_name, username FROM users WHERE id = ?");
$stmt_name->bind_param("i", $current_user_id);
$stmt_name->execute();
$user_data = $stmt_name->get_result()->fetch_assoc();
$stmt_name->close();
$name = !empty($user_data['full_name']) ? $user_data['full_name'] : ($user_data['username'] ?? 'Sports Director');

$current_page = basename($_SERVER['PHP_SELF']); // For sidebar active state

$alert_message = '';
$alert_type = '';

// --- 2. HANDLE ACTIONS (Archive / Reset) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    $password_input = $_POST['password_check'] ?? '';

    // Security Check
    $stmt_auth = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt_auth->bind_param("i", $current_user_id);
    $stmt_auth->execute();
    $user_auth = $stmt_auth->get_result()->fetch_assoc();
    $stmt_auth->close();

    if (!$user_auth || !password_verify($password_input, $user_auth['password'])) {
        $alert_message = "Error: Incorrect password. Security check failed.";
        $alert_type = "danger";
    } else {
        
        // --- ACTION A: ARCHIVE (Snapshot Only) ---
        if ($_POST['action'] === 'archive_season') {
            $season_name = trim($_POST['season_name']);
            
            if (empty($season_name)) {
                $alert_message = "Error: Please provide a season name.";
                $alert_type = "danger";
            } else {
                $conn->begin_transaction();
                try {
                    // Gather Data (Logic preserved from your original file)
                    $medal_tally = $conn->query("SELECT C.college_name, C.college_code, C.logo_url,
                        SUM(CASE WHEN Cat.gold_winner_college_id = C.college_id THEN Cat.gold_count ELSE 0 END) AS gold,
                        SUM(CASE WHEN Cat.silver_winner_college_id = C.college_id THEN Cat.silver_count ELSE 0 END) AS silver,
                        SUM(CASE WHEN Cat.bronze_winner_college_id = C.college_id THEN Cat.bronze_count ELSE 0 END) AS bronze,
                        (SUM(CASE WHEN Cat.gold_winner_college_id = C.college_id THEN Cat.gold_count ELSE 0 END) + SUM(CASE WHEN Cat.silver_winner_college_id = C.college_id THEN Cat.silver_count ELSE 0 END) + SUM(CASE WHEN Cat.bronze_winner_college_id = C.college_id THEN Cat.bronze_count ELSE 0 END)) AS total
                        FROM colleges C LEFT JOIN categories Cat ON (C.college_id = Cat.gold_winner_college_id OR C.college_id = Cat.silver_winner_college_id OR C.college_id = Cat.bronze_winner_college_id) AND Cat.status = 'Results Approved'
                        GROUP BY C.college_id ORDER BY total DESC, gold DESC")->fetch_all(MYSQLI_ASSOC);

                    $matches_data = $conn->query("SELECT m.match_date, m.match_time, m.venue, m.status, m.score1, m.score2, g.game_name, ge.event_name, c.category_name, t1.college_name AS team1, t2.college_name AS team2, w.college_name AS winner_name, w.logo_url AS winner_logo, COALESCE(u_override.full_name, ua.full_name, 'Unassigned') AS manager_name FROM matches m JOIN categories c ON m.category_id = c.category_id JOIN game_events ge ON c.event_id = ge.event_id JOIN games g ON ge.game_id = g.game_id LEFT JOIN colleges t1 ON m.team1_id = t1.college_id LEFT JOIN colleges t2 ON m.team2_id = t2.college_id LEFT JOIN colleges w ON m.winner_team_id = w.college_id LEFT JOIN event_manager_assignments ema ON ge.event_id = ema.event_id LEFT JOIN users ua ON ema.user_id = ua.id LEFT JOIN users u_override ON m.managed_by_user_id = u_override.id ORDER BY m.match_date DESC")->fetch_all(MYSQLI_ASSOC);
                    
                    $events_structure = $conn->query("SELECT g.game_name, ge.event_name, c.category_name, c.category_type FROM categories c JOIN game_events ge ON c.event_id = ge.event_id JOIN games g ON ge.game_id = g.game_id ORDER BY g.game_name, ge.event_name")->fetch_all(MYSQLI_ASSOC);
                    
                    $teams_data = $conn->query("SELECT college_name, college_code, logo_url, team_manager, slogan FROM colleges ORDER BY college_name")->fetch_all(MYSQLI_ASSOC);
                    
                    // Use ORDER BY FIELD to force Sports Director first, then sort names alphabetically
                    $officials_data = $conn->query("SELECT full_name, role, email FROM users WHERE role IN ('Sports Director', 'Event Manager') ORDER BY FIELD(role, 'Sports Director', 'Event Manager'), full_name")->fetch_all(MYSQLI_ASSOC);

                    $stats = [
                        'total_games' => $conn->query("SELECT COUNT(*) FROM games")->fetch_row()[0],
                        'total_events' => $conn->query("SELECT COUNT(*) FROM game_events")->fetch_row()[0],
                        'total_categories' => $conn->query("SELECT COUNT(*) FROM categories")->fetch_row()[0],
                        'total_matches' => count($matches_data)
                    ];

                    $stmt_arch = $conn->prepare("INSERT INTO archived_seasons (season_name, archived_by_user_id, medal_standing_json, matches_json, events_json, teams_json, officials_json, stats_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $json_medals = json_encode($medal_tally);
                    $json_matches = json_encode($matches_data);
                    $json_events = json_encode($events_structure);
                    $json_teams = json_encode($teams_data);
                    $json_officials = json_encode($officials_data);
                    $json_stats = json_encode($stats);

                    $stmt_arch->bind_param("sissssss", $season_name, $current_user_id, $json_medals, $json_matches, $json_events, $json_teams, $json_officials, $json_stats);
                    $stmt_arch->execute();
                    $stmt_arch->close();

                    $conn->commit();
                    $alert_message = "SUCCESS: Season '$season_name' archived. Live data remains available.";
                    $alert_type = "success";
                } catch (Exception $e) {
                    $conn->rollback();
                    $alert_message = "ERROR: Archiving failed. " . $e->getMessage();
                    $alert_type = "danger";
                }
            }
        }

        // --- ACTION B: RESET (Delete Only) ---
        // --- ACTION B: RESET (Delete Only) ---
        elseif ($_POST['action'] === 'reset_system') {
            $conn->begin_transaction();
            try {
                // 1. Delete Matches (Lowest Level)
                $conn->query("DELETE FROM matches");

                // 2. Delete Event Assignments (Remove Managers from Events)
                $conn->query("DELETE FROM event_manager_assignments");

                // 3. Delete Categories (e.g., Men's Basketball, Women's Basketball)
                $conn->query("DELETE FROM categories");

                // 4. Delete Events (e.g., Basketball, Volleyball)
                $conn->query("DELETE FROM game_events");

                // 5. Delete Games (e.g., Sports definitions)
                $conn->query("DELETE FROM games");

                // Note: We usually KEEP the 'colleges' (Teams) and 'users' for the next season.
                
                $conn->commit();
                $alert_message = "SUCCESS: System has been fully reset. All events and matches are deleted.";
                $alert_type = "warning";
            } catch (Exception $e) {
                $conn->rollback();
                $alert_message = "ERROR: Reset failed. " . $e->getMessage();
                $alert_type = "danger";
            }
        }
    }
}

// --- 3. FETCH ARCHIVES LIST ---
$archives = [];
$res_arch = $conn->query("SELECT * FROM archived_seasons ORDER BY archived_at DESC");
if($res_arch) $archives = $res_arch->fetch_all(MYSQLI_ASSOC);

// Count pending requests for sidebar badge (Fixed: Counts unapproved users)
$pending_requests_count = $conn->query("SELECT COUNT(*) FROM users WHERE is_approved = 0")->fetch_row()[0] ?? 0;
$pending_results_count = $conn->query("SELECT COUNT(*) FROM categories WHERE status='Results Submitted'")->fetch_row()[0] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Season Archives - Director Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* =========================================
           1. CORE LAYOUT STYLES (From Dashboard)
           ========================================= */
        :root { 
            --sidebar-width: 260px; 
            --header-height: 82px; 
            --transition: all 0.3s ease; 
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); 
            --bg-light: #F8F9FA; 
            --primary-gradient: linear-gradient(135deg, #2c3e50 0%, #4ca1af 100%);
            --accent-color: #1abc9c;
            --primary: #2563eb; /* Kept for Archive Cards */
            --danger: #ef4444;  /* Kept for Archive Cards */
        }
        body { background-color: var(--bg-light); margin: 0; padding: 0; min-height: 100vh; font-family: 'Inter', sans-serif; display: flex; flex-direction: column; }
        
        /* Navbar */
        .navbar { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.15); padding: 1rem 1.5rem; height: var(--header-height); position: fixed; top: 0; left: 0; right: 0; z-index: 1050; }
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; transition: var(--transition); }
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
        .user-dropdown .dropdown-toggle img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; margin-right: 10px; }
        
        /* Sidebar */
        .sidebar { width: var(--sidebar-width); position: fixed; top: var(--header-height); left: 0; height: calc(100vh - var(--header-height)); background: #2c3e50; color: white; box-shadow: 5px 0 15px rgba(0,0,0,0.2); z-index: 1040; transition: width var(--transition); overflow-y: auto; }
        .sidebar-nav { padding: 20px 0; }
        .sidebar-nav .nav-link { color: rgba(255, 255, 255, 0.7); font-size: 1.05rem; font-weight: 500; padding: 12px 25px; transition: var(--transition); border-left: 5px solid transparent; margin: 2px 0; display: flex; align-items: center; text-decoration: none; }
        .sidebar-nav .nav-link i { width: 30px; text-align: center; flex-shrink: 0; font-size: 0.95em; }
        .sidebar-nav .nav-link:hover { color: white; background: rgba(255, 255, 255, 0.05); border-left-color: var(--accent-color); }
        .sidebar-nav .nav-link.active { color: white; background: rgba(255, 255, 255, 0.1); border-left-color: #3498db; font-weight: 600; }
        .sidebar-nav .nav-title { padding: 15px 25px 5px; font-size: 0.75rem; font-weight: 700; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px; }

        /* Main Content & Footer */
        .main-content { flex: 1 0 auto; padding: 30px; margin-top: var(--header-height); margin-left: var(--sidebar-width); transition: margin-left var(--transition); min-height: calc(100vh - var(--header-height)); }
        footer { flex-shrink: 0; background: #2c3e50 !important; box-shadow: 0 -2px 10px rgba(0,0,0,0.1); padding-left: var(--sidebar-width); transition: padding-left var(--transition); position: relative; z-index: 1041; }
        
        @media (max-width: 992px) {
            .sidebar { left: -260px; }
            .sidebar.show { left: 0; }
            .main-content, footer { margin-left: 0; }
        }

        /* =========================================
           2. PAGE SPECIFIC STYLES (Archives)
           ========================================= */
        
        /* Page Header */
        .page-header {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
            border-left: 5px solid var(--accent-color);
        }
        .page-header h2 { font-size: 2rem; font-weight: 700; color: #333; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 1rem; font-family: 'Poppins', sans-serif; }
        .page-header .subtitle { color: #64748b; font-size: 1rem; font-weight: 400; }

        /* Navigation Tabs */
        .nav-tabs { border: none; background: white; padding: 0.5rem; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); margin-bottom: 2rem; display: flex; gap: 0.5rem; }
        .nav-tabs .nav-link { border: none; border-radius: 8px; padding: 1rem 1.5rem; font-weight: 600; color: #64748b; transition: all 0.3s ease; display: flex; align-items: center; gap: 0.5rem; }
        .nav-tabs .nav-link:hover { background: #f1f5f9; color: #333; transform: translateY(-2px); }
        .nav-tabs .nav-link.active { background: linear-gradient(135deg, #3498db 0%, #2980b9 100%); color: white; box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3); }
        .nav-tabs .nav-link.text-danger:hover, .nav-tabs .nav-link.text-danger.active { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white !important; }

        /* Archive Cards */
        .archive-card { background: white; border-radius: 12px; box-shadow: var(--card-shadow); transition: all 0.3s ease; border: 2px solid #e2e8f0; overflow: hidden; height: 100%; cursor: pointer; }
        .archive-card:hover { transform: translateY(-8px); box-shadow: 0 10px 30px rgba(0,0,0,0.12); border-color: #3498db; }
        .archive-card-header { background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%); padding: 1.5rem; border-bottom: 2px solid #e2e8f0; }
        .archive-card-body { padding: 1.5rem; }
        .season-title { font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 0.5rem; }
        .archive-date { font-size: 0.875rem; color: #64748b; display: flex; align-items: center; gap: 0.5rem; }
        
        .champion-badge { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); padding: 1rem; border-radius: 8px; margin: 1rem 0; border-left: 4px solid #f59e0b; }
        .champion-badge .label { font-size: 0.75rem; font-weight: 700; color: #92400e; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 0.25rem; }
        .champion-badge .value { font-size: 1.125rem; font-weight: 700; color: #78350f; display: flex; align-items: center; gap: 0.5rem; }

        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; margin: 1.5rem 0; }
        .stat-box { text-align: center; padding: 1rem; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; transition: all 0.3s ease; }
        .stat-box:hover { background: #f1f5f9; transform: scale(1.05); }
        .stat-box .number { font-size: 1.75rem; font-weight: 700; color: #3498db; display: block; }
        .stat-box .label { font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 600; margin-top: 0.25rem; }

        /* Action Cards */
        .action-card { background: white; border-radius: 12px; padding: 3rem; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; border-top: 5px solid; transition: all 0.3s ease; }
        .action-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0,0,0,0.12); }
        .action-card.archive-action { border-top-color: #3498db; }
        .action-card.reset-action { border-top-color: #ef4444; background: linear-gradient(135deg, #fff 0%, #fef2f2 100%); }
        .action-icon { font-size: 4rem; margin-bottom: 1.5rem; opacity: 0.9; }
        .action-card h3 { font-size: 1.75rem; font-weight: 700; margin-bottom: 1rem; }
        .action-card p { font-size: 1rem; color: #64748b; max-width: 600px; margin: 0 auto 2rem; line-height: 1.8; }
        .action-card .btn { padding: 1rem 3rem; font-size: 1.125rem; font-weight: 600; border-radius: 50px; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .action-card .btn:hover { transform: scale(1.05); box-shadow: 0 10px 20px rgba(0,0,0,0.15); }

        /* Empty State */
        .empty-state { background: white; padding: 5rem 2rem; border-radius: 12px; box-shadow: var(--card-shadow); text-align: center; }
        .empty-state i { font-size: 5rem; color: #cbd5e1; margin-bottom: 1.5rem; }
        .empty-state h5 { font-size: 1.5rem; font-weight: 700; color: #333; margin-bottom: 0.75rem; }
        .empty-state p { color: #64748b; font-size: 1rem; }

        /* Table Enhancements (For Modal) */
        .table-card { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); overflow: hidden; }
        .table-card-header { background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%); padding: 1.5rem; border-bottom: 2px solid #e2e8f0; }
        .table-card-header h5 { font-size: 1.25rem; font-weight: 700; color: #333; margin: 0; display: flex; align-items: center; gap: 0.75rem; }
        .rank-cell { font-weight: 700; font-size: 1.25rem; color: #333; text-align: center; width: 70px; }
        .gold-text { color: #f59e0b; font-weight: 700; font-size: 1.125rem; }
        .silver-text { color: #64748b; font-weight: 700; font-size: 1.125rem; }
        .bronze-text { color: #ea580c; font-weight: 700; font-size: 1.125rem; }
        .total-text { color: #3498db; font-weight: 800; font-size: 1.25rem; }
        
        #fullReportModal .table-responsive { overflow-x: hidden !important; }
        #fullReportModal table { width: 100%; }
    </style>
</head>
<body>
    
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="sd/sports_director_dashboard.php">
                <img src="imageslogo.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light" style="font-size: 0.75rem;">Director Panel</small>
                </div>
            </a>
            <button class="navbar-toggler d-lg-none" type="button" id="mobileToggle">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="dropdown user-dropdown ms-auto me-2 me-lg-0">
                <a href="#" class="dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle" style="font-size: 36px; margin-right: 10px;"></i>
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="admin_profile.php"><i class="fas fa-user-circle me-2"></i> Profile</a></li>
                    <li><a class="dropdown-item" href="Tournament_Manager_page.php" target="_blank"><i class="fas fa-globe me-2"></i> Public Site</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="sidebar" id="sidebar">
        <ul class="nav flex-column sidebar-nav">
            <li class="nav-item">
                <a class="nav-link" href="sd/sports_director_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                </a>
            </li>
            
            <li class="nav-item mt-3"><span class="nav-title">Tournament Mgmt</span></li>
            <li class="nav-item">
                <a class="nav-link" href="sd/colleges.php">
                    <i class="fas fa-users me-2"></i> <span>Manage Teams</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="sd/events.php">
                    <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events (L1-L3)</span>
                </a>
            </li>

            <li class="nav-item mt-3"><span class="nav-title">Administration</span></li>
            <li class="nav-item">
                <a class="nav-link" href="Manage_Users.php">
                    <i class="fas fa-users-cog me-2"></i> <span>Manage Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="Manage_Requests.php">
                    <i class="fas fa-user-plus me-2"></i> <span>Account Requests</span>
                    <?php if($pending_requests_count > 0): ?>
                        <span class="badge bg-danger ms-auto rounded-pill"><?= $pending_requests_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
             <li class="nav-item">
                <a class="nav-link" href="Manage_Viewreports.php">
                    <i class="fas fa-file-alt me-2"></i> <span>View System Reports</span>
                </a>
            </li>

            <li class="nav-item mt-3"><span class="nav-title">Tallying & Scoring</span></li>
            <li class="nav-item">
                <a class="nav-link" href="sd/results.php">
                    <i class="fas fa-check-double me-2"></i> <span>Approve Results</span>
                    <?php if($pending_results_count > 0): ?>
                        <span class="badge bg-warning text-dark ms-auto rounded-pill"><?= $pending_results_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="sd/reports.php">
                    <i class="fas fa-chart-line me-2"></i> <span>Medal Standings</span>
                </a>
            </li>

            <li class="nav-item mt-3"><span class="nav-title">Season Management</span></li>
            <li class="nav-item">
                <a class="nav-link active" href="manage_archives.php">
                    <i class="fas fa-history me-2"></i> <span>Archives & Reset</span>
                </a>
            </li>
            
            <li class="nav-item mt-auto">
                <a class="nav-link text-danger" href="logout.php">
                    <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <div class="main-content">
        <div class="container-fluid">
        
            <div class="page-header">
                <h2>
                    <i class="fas fa-archive text-primary"></i>
                    Season Archives
                </h2>
                <p class="subtitle">Manage historical data and prepare for new seasons</p>
            </div>

            <?php if ($alert_message): ?>
                <div class="alert alert-<?= $alert_type ?> alert-dismissible fade show">
                    <i class="fas fa-<?= $alert_type === 'success' ? 'check-circle' : ($alert_type === 'danger' ? 'exclamation-triangle' : 'info-circle') ?> me-2"></i>
                    <div><?= htmlspecialchars($alert_message) ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <ul class="nav nav-tabs" id="archiveTabs" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button">
                        <i class="fas fa-folder-open text-warning"></i>
                        Season History
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" id="archive-tab" data-bs-toggle="tab" data-bs-target="#archive" type="button">
                        <i class="fas fa-save text-primary"></i>
                        Create Archive
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link text-danger" id="reset-tab" data-bs-toggle="tab" data-bs-target="#reset" type="button">
                        <i class="fas fa-exclamation-circle"></i>
                        Reset System
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                
                <div class="tab-pane fade show active" id="history">
                    <?php if (empty($archives)): ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <h5>No Archived Seasons Yet</h5>
                            <p>Once you archive a season, it will appear here for future reference and analysis.</p>
                        </div>
                    <?php else: ?>
                        <div class="row g-4">
                            <?php foreach ($archives as $arch): 
                                $stats_json = $arch['stats_json'] ?? '{}';
                                $medals_json = $arch['medal_standing_json'] ?? '[]';
                                
                                $stats = json_decode($stats_json, true) ?? [];
                                $medals = json_decode($medals_json, true) ?? [];
                                
                                $champion = $medals[0]['college_name'] ?? 'Unknown';
                                $champion_logo = $medals[0]['logo_url'] ?? '';
                                
                                // Clean up path for archives (removed ../)
                                if (!empty($champion_logo)) {
                                    $champion_logo = str_replace('../', '', $champion_logo);
                                }
                                
                                $val_games = $stats['total_games'] ?? 0;
                                $val_events = $stats['total_events'] ?? 0;
                                $val_matches = $stats['total_matches'] ?? 0;
                            ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="archive-card view-archive-btn" 
                                    data-id="<?= $arch['archive_id'] ?>"
                                    data-season="<?= htmlspecialchars($arch['season_name']) ?>">
                                    <div class="archive-card-header">
                                        <div class="season-title"><?= htmlspecialchars($arch['season_name']) ?></div>
                                        <div class="archive-date">
                                            <i class="fas fa-calendar"></i>
                                            <?= date('F d, Y', strtotime($arch['archived_at'])) ?>
                                        </div>
                                    </div>
                                    <div class="archive-card-body">
                                        <div class="champion-badge">
                                            <div class="label">Season Champion</div>
                                            
                                            <div class="value d-flex align-items-center justify-content-center gap-2">
                                                <?php if (!empty($champion_logo)): ?>
                                                    <img src="<?= htmlspecialchars($champion_logo) ?>" 
                                                        alt="Logo" 
                                                        style="width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid #d97706; background: white;">
                                                <?php else: ?>
                                                    <i class="fas fa-trophy"></i>
                                                <?php endif; ?>
                                                
                                                <span class="text-start lh-sm"><?= htmlspecialchars($champion) ?></span>
                                            </div>
                                        </div>
                                        
                                        <div class="stats-grid">
                                            <div class="stat-box">
                                                <span class="number"><?= $val_games ?></span>
                                                <span class="label">Games</span>
                                            </div>
                                            <div class="stat-box">
                                                <span class="number"><?= $val_events ?></span>
                                                <span class="label">Events</span>
                                            </div>
                                            <div class="stat-box">
                                                <span class="number"><?= $val_matches ?></span>
                                                <span class="label">Matches</span>
                                            </div>
                                        </div>

                                        <button class="btn btn-primary w-100 d-flex align-items-center justify-content-center gap-2">
                                            <i class="fas fa-external-link-alt"></i>
                                            View Full Report
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="tab-pane fade" id="archive">
                    <div class="row justify-content-center">
                        <div class="col-lg-8">
                            <div class="action-card archive-action">
                                <i class="fas fa-cloud-upload-alt action-icon text-primary"></i>
                                <h3>Archive Current Season</h3>
                                <p>
                                    Create a permanent snapshot of all current games, events, matches, results, and teams. 
                                    This action preserves your data while keeping the live system active and operational.
                                </p>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#confirmArchiveModal">
                                    <i class="fas fa-save me-2"></i>
                                    Create Archive Now
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="reset">
                    <div class="row justify-content-center">
                        <div class="col-lg-8">
                            <div class="action-card reset-action">
                                <i class="fas fa-trash-restore-alt action-icon text-danger"></i>
                                <h3 class="text-danger">Reset System Data</h3>
                                <p>
                                    <strong>⚠️ Critical Action:</strong> This will permanently delete all matches, assignments, events 
                                    and reset all results and medals. This operation is irreversible and should only be used 
                                    when starting a completely new season.
                                </p>
                                <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#confirmResetModal">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    Reset System
                                </button>
                            </div>
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

    <div class="modal fade" id="confirmArchiveModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-archive me-2"></i>Confirm Archiving</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-4">
                            <label class="form-label fw-bold">Season Name / Title</label>
                            <input type="text" name="season_name" class="form-control form-control-lg" placeholder="e.g. Intramurals 2024" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Admin Password</label>
                            <input type="password" name="password_check" class="form-control form-control-lg" placeholder="Confirm your identity" required>
                        </div>
                        <input type="hidden" name="action" value="archive_season">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-check me-2"></i>Create Archive</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="confirmResetModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-skull-crossbones me-2"></i>Confirm System Reset</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="alert alert-warning border-0 mb-4">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> This action is irreversible and will delete all matches, scores, and reset event assignments permanently.
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Confirm Admin Password</label>
                            <input type="password" name="password_check" class="form-control form-control-lg" placeholder="Enter your password" required>
                        </div>
                        <input type="hidden" name="season_name" value="RESET_ACTION">
                        <input type="hidden" name="action" value="reset_system">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-check me-2"></i>Yes, Reset System</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="fullReportModal" tabindex="-1">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="reportModalTitle"><i class="fas fa-history me-2"></i>Archive Viewer</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0 d-flex flex-column" style="height: calc(100vh - 120px);">
                    <div class="bg-white shadow-sm sticky-top" style="z-index: 1020;">
                        <div class="container-fluid px-4">
                            <ul class="nav nav-tabs border-0" id="reportNav" role="tablist">
                                <li class="nav-item"><button class="nav-link active py-3 border-0" data-bs-target="#rep-medals" data-bs-toggle="pill"><i class="fas fa-medal me-2"></i>Medal Standing</button></li>
                                <li class="nav-item"><button class="nav-link py-3 border-0" data-bs-target="#rep-matches" data-bs-toggle="pill"><i class="fas fa-gamepad me-2"></i>Matches</button></li>
                                <li class="nav-item"><button class="nav-link py-3 border-0" data-bs-target="#rep-events" data-bs-toggle="pill"><i class="fas fa-calendar-day me-2"></i>Events</button></li>
                                <li class="nav-item"><button class="nav-link py-3 border-0" data-bs-target="#rep-teams" data-bs-toggle="pill"><i class="fas fa-users me-2"></i>Teams</button></li>
                                <li class="nav-item"><button class="nav-link py-3 border-0" data-bs-target="#rep-officials" data-bs-toggle="pill"><i class="fas fa-user-tie me-2"></i>Officials</button></li>
                            </ul>
                        </div>
                    </div>

                    <div class="flex-grow-1 overflow-auto p-4" style="background: #f8fafc;">
                        <div class="container-fluid">
                            <div class="tab-content">
                                <div class="tab-pane fade show active" id="rep-medals">
                                    <div class="table-card">
                                        <div class="table-card-header"><h5><i class="fas fa-medal text-warning me-2"></i>Final Medal Tally</h5></div>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0 align-middle" id="tableMedals">
                                                <thead><tr><th class="text-center">Rank</th><th>Team</th><th class="text-center">🥇 Gold</th><th class="text-center">🥈 Silver</th><th class="text-center">🥉 Bronze</th><th class="text-center">Total</th></tr></thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="rep-matches">
                                    <div class="table-card">
                                        <div class="table-card-header"><h5><i class="fas fa-gamepad text-primary me-2"></i>Match History</h5></div>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0 align-middle" id="tableMatches">
                                                <thead><tr><th>Event Details</th><th>Matchup</th><th class="text-center">Winner & Score</th><th class="text-center">Status</th><th>Venue Details</th><th>Manager</th></tr></thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="rep-events">
                                    <div class="table-card">
                                        <div class="table-card-header"><h5><i class="fas fa-calendar-day text-success me-2"></i>Tournament Events</h5></div>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0 align-middle" id="tableEvents">
                                                <thead><tr><th>Game</th><th>Event Name</th><th>Category</th><th class="text-center">Type</th></tr></thead>
                                                <tbody id="eventsTableBody"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="rep-teams">
                                    <div class="table-card">
                                        <div class="table-card-header"><h5><i class="fas fa-users text-info me-2"></i>Participating Teams</h5></div>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0 align-middle" id="tableTeams">
                                                <thead><tr><th>Logo</th><th>Team Name</th><th>Code</th><th>Team Manager</th><th>Slogan</th></tr></thead>
                                                <tbody id="teamsTableBody"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="rep-officials">
                                    <div class="table-card">
                                        <div class="table-card-header"><h5><i class="fas fa-user-tie text-secondary me-2"></i>Tournament Officials</h5></div>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0 align-middle" id="tableOfficials">
                                                <thead><tr><th>Name</th><th>Role</th><th>Email</th></tr></thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-white">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Close Report</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('mobileToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });

        // --- ### SIDEBAR/FOOTER OVERLAP FIX (From Dashboard) ### ---
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

        // --- ARCHIVE VIEWER LOGIC ---
        document.addEventListener('DOMContentLoaded', function() {
            const viewBtns = document.querySelectorAll('.view-archive-btn');
            const modal = new bootstrap.Modal(document.getElementById('fullReportModal'));
            
            viewBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const archiveId = this.dataset.id;
                    const season = this.dataset.season;
                    
                    document.getElementById('reportModalTitle').innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span> Loading ${season}...`;
                    modal.show();
                    
                    fetch(`manage_archives.php?ajax_fetch_report=1&archive_id=${archiveId}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                const archiveDate = new Date(data.archived_at).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'});
                                document.getElementById('reportModalTitle').innerHTML = `<i class="fas fa-history me-2"></i> ${data.season_name} <span class="badge bg-secondary ms-2" style="font-size: 0.75rem;">Archived: ${archiveDate}</span>`;
                                populateModal(data);
                            } else {
                                alert('Error loading report: ' + (data.message || 'Unknown error'));
                                modal.hide();
                            }
                        })
                        .catch(err => {
                            console.error('AJAX Error:', err);
                            alert('Failed to load archive data.');
                            modal.hide();
                        });
                });
            });

            function populateModal(data) {
                const pathPrefix = '';
                
                // 1. Medals (Logic from original file)
                // 1. Medal Standing
const medalBody = document.querySelector('#tableMedals tbody');
if (data.medals && data.medals.length > 0) {
    medalBody.innerHTML = data.medals.map((m, i) => {
        // Fix image path
        let rawLogo = m.logo_url || 'images/default_avatar.png';
        rawLogo = rawLogo.replace('../', ''); // Remove parent directory dots if present
        let logoSrc = pathPrefix + rawLogo;

        return `
        <tr>
            <td class="rank-cell" style="width: 60px; text-align: center; font-weight: bold;">#${i+1}</td>
            <td>
                <div class="d-flex align-items-center">
                    <img src="${logoSrc}" 
                         style="width: 45px; height: 45px; object-fit: cover; border-radius: 50%; border: 2px solid #ddd; margin-right: 15px;" 
                         onerror="this.src='${pathPrefix}images/default_avatar.png'">
                    <span class="fw-bold text-dark">${m.college_name}</span>
                </div>
            </td>
            <td class="text-center" style="color: #000000; font-weight: bold; font-size: 1.1rem;">${m.gold}</td>
            <td class="text-center" style="color: #000000; font-weight: bold; font-size: 1.1rem;">${m.silver}</td>
            <td class="text-center" style="color: #000000; font-weight: bold; font-size: 1.1rem;">${m.bronze}</td>
            <td class="text-center" style="color: #000000; font-weight: 800; font-size: 1.1rem;">${m.total}</td>
        </tr>
        `;
    }).join('');
} else {
    medalBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-5">No medal data available.</td></tr>';
}

                // 2. Matches
                const matchBody = document.querySelector('#tableMatches tbody');
                if (data.matches && data.matches.length > 0) {
                    matchBody.innerHTML = data.matches.map(m => {
                        let winnerDisplay = m.status === 'Completed' ? (m.winner_name ? `<div class="badge bg-success mb-2"><i class="fas fa-trophy me-1"></i>${m.winner_name}</div>` : '<span class="badge bg-secondary">Draw</span>') : '<span class="text-muted small fst-italic">TBD</span>';
                        let categoryDisplay = (!m.category_name || m.category_name === 'Main Event') ? '<div class="text-muted small fst-italic mt-1">(no category)</div>' : `<div class="small text-primary mt-1"><i class="fas fa-caret-right me-1"></i>${m.category_name}</div>`;
                        return `<tr><td><div class="fw-bold text-dark">${m.game_name}</div><div class="text-primary fw-semibold small">${m.event_name}</div>${categoryDisplay}</td><td><div class="fw-bold text-dark">${m.team1}</div><div class="text-muted small text-center my-1">VS</div><div class="fw-bold text-dark">${m.team2}</div></td><td class="text-center">${winnerDisplay}<div class="fw-bold" style="font-size: 1.125rem;">${m.score1} - ${m.score2}</div></td><td class="text-center"><span class="badge ${m.status === 'Completed' ? 'bg-success' : (m.status === 'Upcoming' ? 'bg-info' : 'bg-secondary')}">${m.status}</span></td><td><div class="fw-bold text-dark">${m.match_date || 'TBA'}</div><div class="small text-muted">${m.match_time || ''}</div><div class="small text-muted mt-1"><i class="fas fa-map-marker-alt me-1"></i>${m.venue || 'TBA'}</div></td><td><div class="d-flex align-items-center"><i class="fas fa-user text-muted me-2"></i><span class="small">${m.manager_name || 'Unassigned'}</span></div></td></tr>`;
                    }).join('');
                } else { matchBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-5">No match history found.</td></tr>'; }

                // 3. Events
                const eventsBody = document.getElementById('eventsTableBody');
                if (data.events && data.events.length > 0) {
                    eventsBody.innerHTML = data.events.map(ev => `<tr><td class="fw-bold text-dark">${ev.game_name}</td><td class="fw-semibold text-primary">${ev.event_name}</td><td>${ev.category_name || '<span class="text-muted fst-italic">(no category)</span>'}</td><td class="text-center"><span class="badge bg-light text-dark border">${ev.category_type}</span></td></tr>`).join('');
                } else { eventsBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-5">No events found.</td></tr>'; }

                // 4. Teams
                const teamsBody = document.getElementById('teamsTableBody');
                if (data.teams && data.teams.length > 0) {
                    teamsBody.innerHTML = data.teams.map(t => {
                        let rawLogo = t.logo_url || 'images/default_avatar.png'; rawLogo = rawLogo.replace('../', ''); let logoSrc = pathPrefix + rawLogo;
                        return `<tr><td><img src="${logoSrc}" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0;" onerror="this.src='${pathPrefix}images/default_avatar.png'"></td><td><span class="fw-bold text-dark">${t.college_name}</span></td><td><span class="badge bg-light text-dark border fw-semibold">${t.college_code}</span></td><td>${t.team_manager || '<span class="text-muted">—</span>'}</td><td class="text-muted fst-italic small">${t.slogan || ''}</td></tr>`;
                    }).join('');
                } else { teamsBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-5">No teams recorded.</td></tr>'; }

                // 5. Officials
                const officialsBody = document.querySelector('#tableOfficials tbody');
                if (data.officials && data.officials.length > 0) {
                    
                    // --- SORTING: Sports Director First ---
                    data.officials.sort((a, b) => {
                        if (a.role === 'Sports Director' && b.role !== 'Sports Director') return -1;
                        if (a.role !== 'Sports Director' && b.role === 'Sports Director') return 1;
                        return 0; 
                    });

                    officialsBody.innerHTML = data.officials.map(o => {
                        // Distinguish Badge Color: Blue for Director, Light Blue for Managers
                        const badgeClass = o.role === 'Sports Director' ? 'bg-primary text-white' : 'bg-info text-dark';
                        
                        return `
                        <tr>
                            <td><span class="fw-bold text-dark">${o.full_name}</span></td>
                            <td><span class="badge ${badgeClass} fw-semibold">${o.role}</span></td>
                            <td><span class="text-muted font-monospace small">${o.email}</span></td>
                        </tr>`;
                    }).join('');
                } else { 
                    officialsBody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-5">No officials data.</td></tr>'; 
                }
            }
        });

        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => { new bootstrap.Alert(alert).close(); });
        }, 5000);
    </script>
</body>
</html>