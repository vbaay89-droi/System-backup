<?php
session_start();
// 1. --- DATABASE CONNECTION (MySQLi) ---
require_once '../db_connect.php'; 

// 1. SECURITY & ACCESS CONTROL
// STRICT: Only 'Sports Director' is allowed
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Sports Director') {
    header('Location: ../login.php'); 
    exit();
}

// Ensure user_id is set
if (!isset($_SESSION['user_id'])) {
    die("Session error: User ID is not set. Please log in again.");
}
$current_user_id = $_SESSION['user_id'];

// --- FETCH NAME LOGIC ---
$stmt_name = $conn->prepare("SELECT full_name, username FROM users WHERE id = ?");
$stmt_name->bind_param("i", $current_user_id);
$stmt_name->execute();
$result_name = $stmt_name->get_result();
$user_data = $result_name->fetch_assoc();
$stmt_name->close();

if (!empty($user_data['full_name'])) {
    $name = $user_data['full_name'];
} else {
    $name = $user_data['username'] ?? 'Sports Director';
}

$current_page = basename($_SERVER['PHP_SELF']);

// 2. --- PHP CRUD OPERATIONS ---

// --- CREATE MATCH ---
if (isset($_POST['add_match'])) {
    $category_id = $_POST['category_id'];
    $team1_id = $_POST['team1_id'];
    $team2_id = $_POST['team2_id'];
    $match_date = !empty($_POST['match_date']) ? $_POST['match_date'] : NULL;
    $match_time = !empty($_POST['match_time']) ? $_POST['match_time'] : NULL;
    $venue = $_POST['venue'];
    $managed_by = !empty($_POST['managed_by_user_id']) ? $_POST['managed_by_user_id'] : NULL;

    // Server-side validation
    if ($team1_id == $team2_id) {
        $_SESSION['message'] = "Error: Team 1 and Team 2 cannot be the same.";
        $_SESSION['msg_type'] = "danger";
    } else {
        $stmt = $conn->prepare("INSERT INTO matches (category_id, team1_id, team2_id, match_date, match_time, venue, managed_by_user_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Upcoming')");
        $stmt->bind_param("iissssi", $category_id, $team1_id, $team2_id, $match_date, $match_time, $venue, $managed_by);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "New match has been created successfully.";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['message'] = "Error creating match: " . $stmt->error;
            $_SESSION['msg_type'] = "danger";
        }
        $stmt->close();
    }
    header('Location: Manage_Matches.php');
    exit();
}

// --- UPDATE MATCH & RESULTS ---
if (isset($_POST['update_match'])) {
    $match_id = $_POST['match_id'];
    $category_id = $_POST['category_id']; 
    $team1_id = $_POST['team1_id'];
    $team2_id = $_POST['team2_id'];
    $match_date = !empty($_POST['match_date']) ? $_POST['match_date'] : NULL;
    $match_time = !empty($_POST['match_time']) ? $_POST['match_time'] : NULL;
    $venue = $_POST['venue'];
    $managed_by = !empty($_POST['managed_by_user_id']) ? $_POST['managed_by_user_id'] : NULL;
    
    // Result fields
    $status = $_POST['status'];
    $score1 = (int)$_POST['score1'];
    $score2 = (int)$_POST['score2'];
    $winner_team_id = !empty($_POST['winner_team_id']) ? $_POST['winner_team_id'] : NULL;

    // Auto-determine winner logic
    if ($status === 'Completed' && empty($winner_team_id) && $score1 != $score2) {
        $winner_team_id = ($score1 > $score2) ? $team1_id : $team2_id;
    }

    if ($team1_id == $team2_id) {
        $_SESSION['message'] = "Error: Team 1 and Team 2 cannot be the same.";
        $_SESSION['msg_type'] = "danger";
    } else {
        $stmt = $conn->prepare("UPDATE matches SET 
            team1_id = ?, team2_id = ?, match_date = ?, match_time = ?, venue = ?, 
            managed_by_user_id = ?, status = ?, score1 = ?, score2 = ?, winner_team_id = ?
            WHERE match_id = ?");
        $stmt->bind_param("iissssiiisi", 
            $team1_id, $team2_id, $match_date, $match_time, $venue,
            $managed_by, $status, $score1, $score2, $winner_team_id, $match_id);

        if ($stmt->execute()) {
            $_SESSION['message'] = "Match details updated successfully.";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['message'] = "Error updating match: " . $stmt->error;
            $_SESSION['msg_type'] = "danger";
        }
        $stmt->close();
    }
    header('Location: Manage_Matches.php');
    exit();
}

// --- DELETE MATCH ---
if (isset($_GET['delete_id'])) {
    $match_id = $_GET['delete_id'];
    
    $stmt = $conn->prepare("DELETE FROM matches WHERE match_id = ?");
    $stmt->bind_param("i", $match_id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = "Match has been deleted successfully.";
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['message'] = "Error deleting match: " . $stmt->error;
        $_SESSION['msg_type'] = "danger";
    }
    $stmt->close();
    header('Location: Manage_Matches.php');
    exit();
}


// 3. --- DATA FETCHING ---

// A. HIERARCHY DATA (Games -> Events -> Categories)
$hierarchy = [];
$sql_h = "SELECT 
    g.game_id, g.game_name,
    ge.event_id, ge.event_name,
    c.category_id, c.category_name
    FROM games g
    JOIN game_events ge ON g.game_id = ge.game_id
    JOIN categories c ON ge.event_id = c.event_id
    WHERE c.category_type = 'match' OR c.category_type IS NULL OR c.category_type = ''
    ORDER BY g.game_name, ge.event_name, c.category_name";

$res_h = $conn->query($sql_h);
if($res_h) {
    while($row = $res_h->fetch_assoc()) {
        $g_id = $row['game_id'];
        $e_id = $row['event_id'];
        $c_id = $row['category_id'];
        
        if(!isset($hierarchy[$g_id])) {
            $hierarchy[$g_id] = ['id' => $g_id, 'name' => $row['game_name'], 'events' => []];
        }
        if(!isset($hierarchy[$g_id]['events'][$e_id])) {
            $hierarchy[$g_id]['events'][$e_id] = ['id' => $e_id, 'name' => $row['event_name'], 'categories' => []];
        }
        $hierarchy[$g_id]['events'][$e_id]['categories'][] = ['id' => $c_id, 'name' => $row['category_name']];
    }
}
foreach ($hierarchy as &$game) { $game['events'] = array_values($game['events']); }
$hierarchy = array_values($hierarchy);
$hierarchy_json = json_encode($hierarchy);


// B. FETCH MATCHES LIST
$matches = [];
$sql_matches = "
    SELECT 
        m.*, 
        c.category_name, 
        ge.event_name, ge.event_id,
        g.game_name, g.game_id,
        t1.college_name AS team1_name, t1.college_code AS team1_code,
        t2.college_name AS team2_name, t2.college_code AS team2_code,
        w.college_name AS winner_name, w.college_code AS winner_code,
        ua.full_name AS assigned_manager_name,
        u_override.full_name AS override_manager_name
    FROM matches m
    JOIN categories c ON m.category_id = c.category_id
    JOIN game_events ge ON c.event_id = ge.event_id
    JOIN games g ON ge.game_id = g.game_id
    LEFT JOIN colleges t1 ON m.team1_id = t1.college_id
    LEFT JOIN colleges t2 ON m.team2_id = t2.college_id
    LEFT JOIN colleges w ON m.winner_team_id = w.college_id
    LEFT JOIN event_manager_assignments ema ON ge.event_id = ema.event_id
    LEFT JOIN users ua ON ema.user_id = ua.id
    LEFT JOIN users u_override ON m.managed_by_user_id = u_override.id
    GROUP BY m.match_id
    ORDER BY m.match_date DESC, m.match_time DESC
";

$result_matches = $conn->query($sql_matches);
if ($result_matches) {
    while ($row = $result_matches->fetch_assoc()) {
        $matches[] = $row;
    }
}

// C. TEAMS & MANAGERS
$colleges = $conn->query("SELECT college_id, college_name FROM colleges ORDER BY college_name")->fetch_all(MYSQLI_ASSOC);
$managers = $conn->query("SELECT id AS user_id, full_name FROM users WHERE role = 'Event Manager' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

// Sidebar Badges
$pending_requests_count = $conn->query("SELECT COUNT(*) FROM account_requests WHERE status = 'pending'")->fetch_row()[0] ?? 0;
$pending_results_count = $conn->query("SELECT COUNT(*) FROM categories WHERE status='Results Submitted'")->fetch_row()[0] ?? 0;


function getStatusBadge($status) {
    switch (strtolower($status)) {
        case 'upcoming': return '<span class="badge bg-info rounded-pill text-dark"><i class="fas fa-clock me-1"></i>Upcoming</span>';
        case 'ongoing': return '<span class="badge bg-primary rounded-pill"><i class="fas fa-play-circle me-1"></i>Ongoing</span>';
        case 'completed': return '<span class="badge bg-success rounded-pill"><i class="fas fa-check-circle me-1"></i>Completed</span>';
        case 'cancelled': return '<span class="badge bg-danger rounded-pill"><i class="fas fa-ban me-1"></i>Cancelled</span>';
        default: return '<span class="badge bg-light text-dark border rounded-pill">' . htmlspecialchars($status) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Matches - Director Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* --- Unified CSS Theme --- */
        :root { 
            --sidebar-width: 260px; 
            --header-height: 82px; 
            --transition: all 0.3s ease; 
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); 
            --bg-light: #F8F9FA; 
            --primary-gradient: linear-gradient(135deg, #2c3e50 0%, #4ca1af 100%);
            --accent-color: #1abc9c;
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

        /* Main Content */
        .main-content { flex: 1 0 auto; padding: 30px; margin-top: var(--header-height); margin-left: var(--sidebar-width); transition: margin-left var(--transition); min-height: calc(100vh - var(--header-height)); }
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        
        /* Footer */
        footer { flex-shrink: 0; background: #2c3e50 !important; box-shadow: 0 -2px 10px rgba(0,0,0,0.1); padding-left: var(--sidebar-width); transition: padding-left var(--transition); position: relative; z-index: 1041; }

        @media (max-width: 992px) {
            .sidebar { left: -260px; }
            .sidebar.show { left: 0; }
            .main-content, footer { margin-left: 0; }
        }

        /* === ENHANCED TABLE DESIGN === */
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08); overflow: hidden; margin-bottom: 1.5rem; }
        .card-header { background: #ffffff !important; border-bottom: 2px solid #f1f3f5 !important; padding: 1.25rem 1.5rem !important; }
        .card-header h5 { font-family: 'Poppins', sans-serif; font-weight: 600; color: #2c3e50; margin-bottom: 0; font-size: 1.1rem; }

        /* Modern Table Container */
        .results-table-container {
            background: #ffffff;
            border-radius: 0 0 12px 12px;
            overflow-x: auto;
            position: relative;
        }
        
        .results-table { margin-bottom: 0; font-size: 0.9375rem; width: 100%; border-collapse: separate; border-spacing: 0; }
        
        /* Enhanced Table Header */
        .results-table thead th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: #2c3e50;
            font-weight: 600;
            font-size: 0.8125rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 1rem 1.25rem;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
        }
        
        .results-table tbody td { padding: 1.125rem 1.25rem; vertical-align: middle; border-bottom: 1px solid #f1f3f5; transition: all 0.2s ease; }
        .results-table tbody tr { transition: all 0.2s ease; }
        .results-table tbody tr:hover { background-color: #f8f9fa; transform: translateX(2px); box-shadow: -3px 0 0 0 #0d6efd inset; }
        
        /* Sticky Action Column */
        .sticky-col {
            position: sticky;
            right: 0;
            z-index: 2;
            background-color: #fff;
            box-shadow: -5px 0 10px rgba(0,0,0,0.05);
        }
        .results-table thead th.sticky-col { background: #e9ecef; z-index: 5; }
        .results-table tbody tr:hover .sticky-col { background-color: #f8f9fa; }

        /* Action Buttons */
        .action-btn { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; transition: all 0.2s ease; font-size: 0.875rem; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08); border: 2px solid transparent; }
        .action-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0, 0, 0, 0.12); }
        .action-btn.btn-primary { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); border-color: #2563eb; color: white; }
        .action-btn.btn-danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); border-color: #dc2626; color: white; }

        /* Badges and Text */
        .winner-badge { font-weight: 700; color: #198754; background: rgba(25, 135, 84, 0.1); padding: 4px 8px; border-radius: 20px; font-size: 0.75rem; display: inline-block; margin-bottom: 4px; }
        .score-display { font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 1rem; color: #2c3e50; }
        .event-subtext { font-size: 0.8rem; color: #6c757d; display: block; margin-top: 2px; }
        .manager-avatar { width: 24px; height: 24px; background: #e9ecef; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; margin-right: 8px; color: #6c757d; }

        /* Modal Styling */
        .modal-content { border-radius: 12px; border: none; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15); }
        .modal-header { border-top-left-radius: 12px; border-top-right-radius: 12px; padding: 1.25rem 1.5rem; }
        .modal-body { padding: 1.5rem; }
        .modal-footer { padding: 1rem 1.5rem; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="sd/sports_director_dashboard.php">
                <img src="../imageslogo.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain;">
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
                    <i class="fas fa-user-circle" style="font-size: 36px; margin-right: 10px; color: rgba(255,255,255,0.8);"></i>
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="../admin_profile.php"><i class="fas fa-user-circle me-2"></i> Profile</a></li>
                    <li><a class="dropdown-item" href="../Tournament_Manager_page.php" target="_blank"><i class="fas fa-globe me-2"></i> Public Site</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- UNIFIED SUPER ADMIN SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <ul class="nav flex-column sidebar-nav">
            <li class="nav-item">
                <a class="nav-link" href="sports_director_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                </a>
            </li>
            
            <li class="nav-item mt-3"><span class="nav-title">Tournament Mgmt</span></li>
            <li class="nav-item">
                <a class="nav-link" href="colleges.php">
                    <i class="fas fa-users me-2"></i> <span>Manage Teams</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="events.php">
                    <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events (L1-L3)</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="Manage_Matches.php">
                    <i class="fas fa-trophy me-2"></i> <span>Manage Matches</span>
                </a>
            </li>

            <li class="nav-item mt-3"><span class="nav-title">Administration</span></li>
            <li class="nav-item">
                <a class="nav-link" href="../Manage_Users.php">
                    <i class="fas fa-users-cog me-2"></i> <span>Manage Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="../Manage_Requests.php">
                    <i class="fas fa-user-plus me-2"></i> <span>Account Requests</span>
                    <?php if($pending_requests_count > 0): ?>
                        <span class="badge bg-danger ms-auto rounded-pill"><?= $pending_requests_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
             <li class="nav-item">
                <a class="nav-link" href="../Manage_Viewreports.php">
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

            <!-- NEW SECTION: SEASON MANAGEMENT -->
            <li class="nav-item mt-3"><span class="nav-title">Season Management</span></li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'manage_archives.php') ? 'active' : '' ?>" href="../manage_archives.php">
                    <i class="fas fa-history me-2"></i> <span>Archives & Reset</span>
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
            
            <nav aria-label="breadcrumb" class="mb-4">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="sd/sports_director_dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Manage Matches</li>
              </ol>
            </nav>

            <h1 class="section-title mb-4">Manage Matches</h1>
            
            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-<?php echo $_SESSION['msg_type']; ?> alert-dismissible fade show shadow-sm" role="alert">
                    <?php echo $_SESSION['message']; unset($_SESSION['message']); unset($_SESSION['msg_type']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-calendar-check me-2 text-primary"></i>Match Schedule</h5>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addMatchModal">
                        <i class="fas fa-plus me-2"></i>Create New Match
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive results-table-container">
                        <table class="table results-table table-hover align-middle mb-0" id="matchesTable">
                            <thead>
                                <tr>
                                    <th style="min-width: 200px;">Event Details</th>
                                    <th style="min-width: 180px;">Matchup</th>
                                    <th class="text-center" style="min-width: 150px;">Winner & Score</th>
                                    <th class="text-center" style="min-width: 120px;">Status</th>
                                    <th style="min-width: 180px;">Venue Details</th>
                                    <th style="min-width: 150px;">Manager</th>
                                    <th class="text-end sticky-col" style="min-width: 100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($matches)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted p-5">
                                            <i class="fas fa-clipboard-list fa-3x mb-3 text-secondary"></i>
                                            <p class="mb-0">No matches created yet.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($matches as $match): ?>
                                        <?php
                                            $display_manager = "Unassigned";
                                            if (!empty($match['assigned_manager_name'])) {
                                                $display_manager = htmlspecialchars($match['assigned_manager_name']);
                                            } elseif (!empty($match['override_manager_name'])) {
                                                $display_manager = htmlspecialchars($match['override_manager_name']) . ' <i class="fas fa-info-circle text-muted small" title="Manual"></i>';
                                            }
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold text-dark"><?= htmlspecialchars($match['game_name']) ?></div>
                                                <div class="event-subtext"><?= htmlspecialchars($match['event_name']) ?></div>
                                                <?php 
                                                    // Check if category is generic or empty
                                                    if($match['category_name'] !== 'Main Event' && $match['category_name'] !== 'Main Competition' && !empty($match['category_name'])): 
                                                ?>
                                                    <div class="event-subtext text-primary"><i class="fas fa-caret-right me-1"></i><?= htmlspecialchars($match['category_name']) ?></div>
                                                <?php else: ?>
                                                    <div class="event-subtext text-muted small fst-italic mt-1">(no category)</div>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <div class="fw-bold text-dark"><?= htmlspecialchars($match['team1_name']) ?></div>
                                                <div class="text-muted small fw-bold text-uppercase my-1">VS</div>
                                                <div class="fw-bold text-dark"><?= htmlspecialchars($match['team2_name']) ?></div>
                                            </td>

                                            <td class="text-center">
                                                <?php if (!empty($match['winner_name'])): ?>
                                                    <div class="winner-badge">
                                                        <i class="fas fa-trophy me-1"></i><?= htmlspecialchars($match['winner_name']) ?>
                                                    </div>
                                                    <div class="score-display">
                                                        <?= htmlspecialchars($match['score1']) ?> - <?= htmlspecialchars($match['score2']) ?>
                                                    </div>
                                                <?php else: ?>
                                                    <?php if(strtolower($match['status']) == 'upcoming'): ?>
                                                        <span class="text-muted small fst-italic">TBD</span>
                                                    <?php else: ?>
                                                        <div class="score-display">
                                                            <?= htmlspecialchars($match['score1']) ?> - <?= htmlspecialchars($match['score2']) ?>
                                                        </div>
                                                        <span class="text-muted small">Draw/Pending</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-center">
                                                <?= getStatusBadge($match['status']) ?>
                                            </td>

                                            <td>
                                                <div class="fw-bold text-dark mb-1">
                                                    <?= $match['match_date'] ? htmlspecialchars(date('M d, Y', strtotime($match['match_date']))) : '<span class="text-muted">Date TBA</span>' ?>
                                                </div>
                                                <div class="small text-muted">
                                                    <?= $match['match_time'] ? '<i class="far fa-clock me-1"></i>' . htmlspecialchars(date('g:i A', strtotime($match['match_time']))) : '' ?>
                                                </div>
                                                <div class="small text-muted mt-1">
                                                    <i class="fas fa-map-marker-alt me-1 text-secondary"></i><?= htmlspecialchars($match['venue'] ?? 'TBA') ?>
                                                </div>
                                            </td>

                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="manager-avatar"><i class="fas fa-user"></i></div>
                                                    <div class="small text-dark"><?= $display_manager ?></div>
                                                </div>
                                            </td>

                                            <td class="text-end sticky-col">
                                                <div class="d-flex gap-2 justify-content-end">
                                                    <button class="action-btn btn-primary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#editMatchModal"
                                                        data-match-id="<?= $match['match_id'] ?>"
                                                        data-game-id="<?= $match['game_id'] ?>"
                                                        data-event-id="<?= $match['event_id'] ?>"
                                                        data-category-id="<?= $match['category_id'] ?>"
                                                        data-team1-id="<?= $match['team1_id'] ?>"
                                                        data-team2-id="<?= $match['team2_id'] ?>"
                                                        data-match-date="<?= $match['match_date'] ?>"
                                                        data-match-time="<?= $match['match_time'] ?>"
                                                        data-venue="<?= htmlspecialchars($match['venue']) ?>"
                                                        data-status="<?= $match['status'] ?>"
                                                        data-score1="<?= $match['score1'] ?>"
                                                        data-score2="<?= $match['score2'] ?>"
                                                        data-winner-team-id="<?= $match['winner_team_id'] ?>"
                                                        data-manager-id="<?= $match['managed_by_user_id'] ?>"
                                                        title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="Manage_Matches.php?delete_id=<?= $match['match_id'] ?>" 
                                                       class="action-btn btn-danger text-decoration-none" 
                                                       title="Delete"
                                                       onclick="return confirm('Are you sure you want to delete this match?')">
                                                       <i class="fas fa-trash-alt"></i>
                                                    </a>
                                                </div>
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
    </div>

    <footer class="bg-dark text-white py-4">
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small>
        </div>
    </footer>

    <!-- Add Match Modal -->
    <div class="modal fade" id="addMatchModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Create New Match</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="Manage_Matches.php">
                    <div class="modal-body">
                        <div class="alert alert-light border text-muted small mb-3">
                            <i class="fas fa-info-circle me-1"></i> The Event Manager assigned to the selected Event will be automatically linked.
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-uppercase">1. Game</label>
                                <select class="form-select" id="add_game_id" required>
                                    <option value="" disabled selected>Select Game</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-uppercase">2. Event</label>
                                <select class="form-select" id="add_event_id" disabled required>
                                    <option value="" disabled selected>Select Event</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-uppercase">3. Category</label>
                                <select class="form-select" id="add_category_id" name="category_id" disabled required>
                                    <option value="" disabled selected>Select Category</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Team 1</label>
                                <select class="form-select" name="team1_id" required>
                                    <option value="" disabled selected>Select Team 1</option>
                                    <?php foreach ($colleges as $college): ?>
                                        <option value="<?= $college['college_id'] ?>"><?= htmlspecialchars($college['college_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Team 2</label>
                                <select class="form-select" name="team2_id" required>
                                    <option value="" disabled selected>Select Team 2</option>
                                    <?php foreach ($colleges as $college): ?>
                                        <option value="<?= $college['college_id'] ?>"><?= htmlspecialchars($college['college_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Date</label>
                                <input type="date" class="form-control" name="match_date">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Time</label>
                                <input type="time" class="form-control" name="match_time">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">Venue</label>
                                <input type="text" class="form-control" name="venue" placeholder="e.g., University Gymnasium">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary" name="add_match">Create Match</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Match Modal -->
    <div class="modal fade" id="editMatchModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Edit Match Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="Manage_Matches.php">
                    <input type="hidden" name="match_id" id="edit_match_id">
                    <input type="hidden" name="category_id" id="edit_category_id_hidden">

                    <div class="modal-body">
                        <div class="row g-4">
                            <div class="col-lg-6">
                                <h6 class="text-primary mb-3 border-bottom pb-2">Match Information</h6>
                                <div class="mb-3">
                                    <label class="text-muted small text-uppercase">Event Context</label>
                                    <div id="edit_hierarchy_display" class="fw-bold text-dark"></div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Team 1</label>
                                        <select class="form-select" id="edit_team1_id" name="team1_id" required>
                                            <?php foreach ($colleges as $college): ?>
                                                <option value="<?= $college['college_id'] ?>"><?= htmlspecialchars($college['college_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Team 2</label>
                                        <select class="form-select" id="edit_team2_id" name="team2_id" required>
                                            <?php foreach ($colleges as $college): ?>
                                                <option value="<?= $college['college_id'] ?>"><?= htmlspecialchars($college['college_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Date</label>
                                        <input type="date" class="form-control" id="edit_match_date" name="match_date">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Time</label>
                                        <input type="time" class="form-control" id="edit_match_time" name="match_time">
                                    </div>
                                    <div class="col-md-12">
                                        <label class="form-label">Venue</label>
                                        <input type="text" class="form-control" id="edit_venue" name="venue">
                                    </div>
                                    <div class="col-md-12">
                                         <label class="form-label">Manager Override <small class="text-muted">(Optional)</small></label>
                                         <select class="form-select" id="edit_managed_by_user_id" name="managed_by_user_id">
                                            <option value="">Use Assigned Event Manager</option>
                                            <?php foreach ($managers as $manager): ?>
                                                <option value="<?= $manager['user_id'] ?>"><?= htmlspecialchars($manager['full_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-6 border-start">
                                <h6 class="text-success mb-3 border-bottom pb-2">Results & Scoring</h6>
                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <label class="form-label">Status</label>
                                        <select class="form-select" id="edit_status" name="status" required>
                                            <option value="Upcoming">Upcoming</option>
                                            <option value="Ongoing">Ongoing</option>
                                            <option value="Completed">Completed</option>
                                            <option value="Cancelled">Cancelled</option>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label text-center w-100 fw-bold">Team 1 Score</label>
                                        <input type="number" class="form-control form-control-lg text-center fw-bold" id="edit_score1" name="score1" min="0" value="0">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label text-center w-100 fw-bold">Team 2 Score</label>
                                        <input type="number" class="form-control form-control-lg text-center fw-bold" id="edit_score2" name="score2" min="0" value="0">
                                    </div>
                                    <div class="col-md-12">
                                        <label class="form-label">Winner</label>
                                        <select class="form-select bg-light" id="edit_winner_team_id" name="winner_team_id">
                                            <option value="">None / Draw</option>
                                        </select>
                                        <div class="form-text">Select the winner if the match is Completed.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary" name="update_match">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const hierarchyData = <?php echo $hierarchy_json; ?>;

        document.addEventListener('DOMContentLoaded', function() {
            // Sidebar Toggle
            const mobileToggle = document.getElementById('mobileToggle');
            if (mobileToggle) {
                mobileToggle.addEventListener('click', function() {
                    document.getElementById('sidebar').classList.toggle('show');
                });
            }
            
            // Dynamic Footer
            const footer = document.querySelector('footer');
            const navbar = document.querySelector('.navbar');
            const sidebar = document.getElementById('sidebar');
            if (sidebar && footer && navbar) {
                function adjustSidebarHeight() {
                    if (window.innerWidth <= 992) {
                        sidebar.style.height = ''; return;
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

            // --- ADD MODAL HIERARCHY ---
            const gameSel = document.getElementById('add_game_id');
            const evtSel = document.getElementById('add_event_id');
            const catSel = document.getElementById('add_category_id');

            hierarchyData.forEach(g => gameSel.add(new Option(g.name, g.id)));

            gameSel.addEventListener('change', function() {
                evtSel.innerHTML = '<option value="" disabled selected>Select Event</option>';
                catSel.innerHTML = '<option value="" disabled selected>Select Category</option>';
                evtSel.disabled = true; catSel.disabled = true;
                
                const game = hierarchyData.find(g => g.id == this.value);
                if(game && game.events.length) {
                    game.events.forEach(e => evtSel.add(new Option(e.name, e.id)));
                    evtSel.disabled = false;
                }
            });

            evtSel.addEventListener('change', function() {
                catSel.innerHTML = '<option value="" disabled selected>Select Category</option>';
                catSel.disabled = true; 
                
                const game = hierarchyData.find(g => g.id == gameSel.value);
                const evt = game.events.find(e => e.id == this.value);
                
                if(evt && evt.categories.length) {
                    if(evt.categories.length === 1 && evt.categories[0].name === 'Main Event') {
                        catSel.add(new Option(evt.categories[0].name, evt.categories[0].id, true, true));
                        catSel.disabled = false;
                    } else {
                        evt.categories.forEach(c => catSel.add(new Option(c.name, c.id)));
                        catSel.disabled = false;
                    }
                }
            });

            // --- EDIT MODAL LOGIC ---
            const editModal = document.getElementById('editMatchModal');
            editModal.addEventListener('show.bs.modal', function(e) {
                const btn = e.relatedTarget;
                
                document.getElementById('edit_match_id').value = btn.dataset.matchId;
                document.getElementById('edit_category_id_hidden').value = btn.dataset.categoryId;
                document.getElementById('edit_team1_id').value = btn.dataset.team1Id;
                document.getElementById('edit_team2_id').value = btn.dataset.team2Id;
                document.getElementById('edit_match_date').value = btn.dataset.matchDate;
                document.getElementById('edit_match_time').value = btn.dataset.matchTime;
                document.getElementById('edit_venue').value = btn.dataset.venue;
                document.getElementById('edit_status').value = btn.dataset.status;
                document.getElementById('edit_score1').value = btn.dataset.score1;
                document.getElementById('edit_score2').value = btn.dataset.score2;
                document.getElementById('edit_managed_by_user_id').value = btn.dataset.managerId;

                const row = btn.closest('tr');
                const evtCell = row.querySelector('.event-details-cell');
                const gameTxt = evtCell.querySelector('h6').textContent; // Fixed selector
                const evtTxt = evtCell.querySelector('.event-name').textContent;
                const catEl = evtCell.querySelector('.category-name');
                const catTxt = catEl ? catEl.textContent.trim() : '';
                document.getElementById('edit_hierarchy_display').innerHTML = `${gameTxt} <i class="fas fa-angle-right small"></i> ${evtTxt} ${catTxt ? '<i class="fas fa-angle-right small"></i> ' + catTxt : ''}`;

                updateWinnerOptions(btn.dataset.winnerTeamId);
            });

            function updateWinnerOptions(selectedWinnerId = null) {
                const t1 = document.getElementById('edit_team1_id');
                const t2 = document.getElementById('edit_team2_id');
                const winSel = document.getElementById('edit_winner_team_id');
                
                if(selectedWinnerId === null) selectedWinnerId = winSel.value;

                winSel.innerHTML = '<option value="">None / Draw</option>';
                winSel.add(new Option(t1.options[t1.selectedIndex].text, t1.value));
                winSel.add(new Option(t2.options[t2.selectedIndex].text, t2.value));

                if(selectedWinnerId == t1.value || selectedWinnerId == t2.value) {
                    winSel.value = selectedWinnerId;
                }
            }

            document.getElementById('edit_team1_id').addEventListener('change', () => updateWinnerOptions());
            document.getElementById('edit_team2_id').addEventListener('change', () => updateWinnerOptions());
        });
    </script>
</body>
</html>