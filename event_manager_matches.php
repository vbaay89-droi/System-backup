<?php
session_start();
require_once 'config.php'; 
require_once 'db_connect.php'; 

// 1. SECURITY & ACCESS CONTROL
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Event Manager') {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Event Manager';
$current_page = basename($_SERVER['PHP_SELF']);
$alert_message = '';
$alert_type = 'success';

// --- FETCH FULL NAME ---
$stmt = $conn->prepare("SELECT full_name, username FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id); 
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc(); 
$stmt->close();

if (!empty($user_data['full_name'])) {
    $display_name = $user_data['full_name'];
} else {
    $display_name = $user_data['username'] ?? $username; 
}

// 2. GET & VALIDATE CATEGORY ID
if (!isset($_GET['category_id'])) {
    header('Location: event_manager_dashboard.php'); 
    exit();
}
$category_id = (int)$_GET['category_id'];

// 3. SECURITY CHECK: VERIFY THIS EM OWNS THIS CATEGORY
$category_name = '';
$event_name = '';
$game_name = '';
$category_status = ''; 

$stmt_check = $conn->prepare("
    SELECT 
        c.category_name, c.status, ge.event_name, g.game_name,
        c.gold_winner_college_id, c.gold_count,
        c.silver_winner_college_id, c.silver_count,
        c.bronze_winner_college_id, c.bronze_count
    FROM categories c
    JOIN game_events ge ON c.event_id = ge.event_id
    JOIN games g ON ge.game_id = g.game_id
    JOIN event_manager_assignments ema ON ge.event_id = ema.event_id
    WHERE c.category_id = ? AND ema.user_id = ? AND c.category_type = 'Match'
");
$stmt_check->bind_param("ii", $category_id, $user_id);
$stmt_check->execute();
$result_check = $stmt_check->get_result();
if ($result_check->num_rows == 0) {
    $_SESSION['alert_message'] = "Permission denied or invalid event type.";
    $_SESSION['alert_type'] = "danger";
    header('Location: event_manager_dashboard.php');
    exit();
}
$row = $result_check->fetch_assoc();
$category_name = $row['category_name'];
$event_name = $row['event_name'];
$game_name = $row['game_name'];
$category_status = $row['status']; 

$gold_winner_id = $row['gold_winner_college_id'];
$gold_count = $row['gold_count'];
$silver_winner_id = $row['silver_winner_college_id'];
$silver_count = $row['silver_count'];
$bronze_winner_id = $row['bronze_winner_college_id'];
$bronze_count = $row['bronze_count'];

$stmt_check->close();

// LOGIC: Change "Main Event" to "No Category" for display
$display_category_name = ($category_name === 'Main Event' || $category_name === 'Main Competition') 
                         ? '<span class="text-muted fst-italic">(No Category)</span>' 
                         : htmlspecialchars($category_name);

if (strtolower($category_status) === 'results approved') {
    $category_status = 'Completed';
}

$status_lower = strtolower($category_status);
$is_locked = (in_array($status_lower, ['results submitted', 'completed']));


// 4. FORM HANDLING (Add/Edit/Delete Match)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['action'])) {
            throw new Exception("Invalid action specified.");
        }
        $action = $_POST['action'];

        // Prevent actions if locked
        if ($is_locked && $action !== 'submit_final_winners') {
            if ($action === 'add_match' || $action === 'update_match' || $action === 'delete_match') {
                 throw new Exception("Cannot modify matches, results are already submitted or approved.");
            }
        }

        // --- CREATE MATCH ---
        if ($action === 'add_match') {
            $team1_id = $_POST['team1_id'];
            $team2_id = $_POST['team2_id'];
            $match_date = !empty($_POST['match_date']) ? $_POST['match_date'] : NULL;
            $match_time = !empty($_POST['match_time']) ? $_POST['match_time'] : NULL;
            $venue = $_POST['venue'];

            if ($team1_id == $team2_id) throw new Exception("Team 1 and Team 2 cannot be the same.");

            $stmt = $conn->prepare("INSERT INTO matches (category_id, team1_id, team2_id, match_date, match_time, venue, status) VALUES (?, ?, ?, ?, ?, ?, 'Upcoming')");
            $stmt->bind_param("iissss", $category_id, $team1_id, $team2_id, $match_date, $match_time, $venue);
            $stmt->execute();
            $new_match_id = (int)$conn->insert_id; 
            $stmt->close();
            $alert_message = "Match created successfully.";

            try {
                $context = ['team1_id' => $team1_id, 'team2_id' => $team2_id, 'match_date' => $match_date, 'venue' => $venue, 'category_name' => $category_name];
                log_activity($conn, $user_id, 'CREATED_MATCH', $new_match_id, 'match', $category_id, 'category', $context);
            } catch (Exception $log_e) { error_log("Log Error: " . $log_e->getMessage()); }

        // --- UPDATE MATCH ---
        } elseif ($action === 'update_match') {
            $match_id = (int)$_POST['match_id'];
            $team1_id = $_POST['team1_id'];
            $team2_id = $_POST['team2_id'];
            $match_date = !empty($_POST['match_date']) ? $_POST['match_date'] : NULL;
            $match_time = !empty($_POST['match_time']) ? $_POST['match_time'] : NULL;
            $venue = $_POST['venue'];
            $status = $_POST['status'];
            $score1 = (int)$_POST['score1'];
            $score2 = (int)$_POST['score2'];
            $winner_team_id = !empty($_POST['winner_team_id']) ? $_POST['winner_team_id'] : NULL;
            
            if ($team1_id == $team2_id) throw new Exception("Team 1 and Team 2 cannot be the same.");
            
            if ($status == 'Completed' && $score1 != $score2 && empty($winner_team_id)) {
                $winner_team_id = ($score1 > $score2) ? $team1_id : $team2_id;
            }
            if ($score1 == $score2) {
                $winner_team_id = NULL;
            }

            $stmt = $conn->prepare("UPDATE matches SET 
                team1_id = ?, team2_id = ?, match_date = ?, match_time = ?, venue = ?, 
                status = ?, score1 = ?, score2 = ?, winner_team_id = ?
                WHERE match_id = ? AND category_id = ?"); 
            $stmt->bind_param("iissssiiiii", 
                $team1_id, $team2_id, $match_date, $match_time, $venue,
                $status, $score1, $score2, $winner_team_id, $match_id, $category_id);
            $stmt->execute();
            $stmt->close();

            $alert_message = "Match updated successfully.";
            
            try {
                $context = ['score1' => $score1, 'score2' => $score2, 'status' => $status, 'winner_team_id' => $winner_team_id, 'category_name' => $category_name];
                log_activity($conn, $user_id, 'UPDATED_MATCH', $match_id, 'match', $category_id, 'category', $context);
            } catch (Exception $log_e) { error_log("Log Error: " . $log_e->getMessage()); }

        // --- DELETE MATCH ---
        } elseif ($action === 'delete_match') {
            $match_id = (int)$_POST['match_id'];
            $stmt = $conn->prepare("DELETE FROM matches WHERE match_id = ? AND category_id = ?");
            $stmt->bind_param("ii", $match_id, $category_id);
            $stmt->execute();
            $stmt->close();
            $alert_message = "Match deleted successfully.";
            
            try {
                $context = ['category_name' => $category_name];
                log_activity($conn, $user_id, 'DELETED_MATCH', $match_id, 'match', $category_id, 'category', $context);
            } catch (Exception $log_e) { error_log("Log Error: " . $log_e->getMessage()); }
        
        // --- SUBMIT FINAL WINNERS ---
        } elseif ($action === 'submit_final_winners') {
            
            if ($is_locked) {
                throw new Exception("Results are already submitted or completed and cannot be modified.");
            }

            if (empty($_POST['gold_winner_college_id']) || empty($_POST['silver_winner_college_id']) || empty($_POST['bronze_winner_college_id'])) {
                throw new Exception("All medal winners (Gold, Silver, and Bronze) must be selected.");
            }

            $gold_id = (int)$_POST['gold_winner_college_id'];
            $silver_id = (int)$_POST['silver_winner_college_id'];
            $bronze_id = (int)$_POST['bronze_winner_college_id'];

            $winners = [$gold_id, $silver_id, $bronze_id];
            if (count($winners) !== count(array_unique($winners))) {
                throw new Exception("The same team cannot be selected for multiple medals.");
            }

            $gold_count = !empty($_POST['gold_count']) ? (int)$_POST['gold_count'] : 0;
            $silver_count = !empty($_POST['silver_count']) ? (int)$_POST['silver_count'] : 0;
            $bronze_count = !empty($_POST['bronze_count']) ? (int)$_POST['bronze_count'] : 0;
            
            $new_status = 'Results Submitted'; 

            $stmt = $conn->prepare("UPDATE categories SET
                gold_winner_college_id = ?, gold_count = ?,
                silver_winner_college_id = ?, silver_count = ?,
                bronze_winner_college_id = ?, bronze_count = ?,
                status = ?
                WHERE category_id = ?
            ");
            $stmt->bind_param("iiiisisi", 
                $gold_id, $gold_count, $silver_id, $silver_count, 
                $bronze_id, $bronze_count, $new_status, $category_id
            );
            
            if ($stmt->execute()) {
                try {
                    $context = ['category_name' => $category_name, 'type' => 'Match'];
                    log_activity($conn, $user_id, 'SUBMITTED_RESULTS', $category_id, 'category', null, null, $context);
                } catch (Exception $log_e) { error_log("Log Error: " . $log_e->getMessage()); }
                
                $_SESSION['alert_message'] = "Final winners submitted for approval!";
                $_SESSION['alert_type'] = "success";
                
                header('Location: my_events.php');
                exit();
            } else {
                throw new Exception("Failed to submit final winners.");
            }
        }
    } catch (Exception $e) {
        $alert_message = "ERROR: " . $e->getMessage();
        $alert_type = 'danger';
    }
}


// 5. FETCH DATA FOR DISPLAY
$colleges = [];
$college_map = [];
$result_col = $conn->query("SELECT college_id, college_name FROM colleges ORDER BY college_name");
while ($row = $result_col->fetch_assoc()) {
    $colleges[] = $row;
    $college_map[$row['college_id']] = $row['college_name'];
}

$matches = [];
$all_matches_completed = true; 
$sql_matches = "SELECT m.*, 
                    t1.college_name as team1_name, 
                    t2.college_name as team2_name,
                    w.college_code as winner_initialism
                FROM matches m
                LEFT JOIN colleges t1 ON m.team1_id = t1.college_id
                LEFT JOIN colleges t2 ON m.team2_id = t2.college_id
                LEFT JOIN colleges w ON m.winner_team_id = w.college_id
                WHERE m.category_id = ?
                ORDER BY m.match_date ASC, m.match_time ASC";
$stmt_matches = $conn->prepare($sql_matches);
$stmt_matches->bind_param("i", $category_id);
$stmt_matches->execute();
$result_matches = $stmt_matches->get_result();
while ($row = $result_matches->fetch_assoc()) {
    $matches[] = $row;
    if (strtolower($row['status']) != 'completed') {
        $all_matches_completed = false;
    }
}
$stmt_matches->close();

if (empty($matches)) {
    $all_matches_completed = false;
}

// --- UPDATED BADGE COLORS TO MATCH MY_EVENTS.PHP ---
function getMatchStatusBadge($status) {
    switch (strtolower($status)) {
        case 'upcoming': 
            // Matches my_events: Primary (Blue)
            return '<span class="badge bg-primary text-white rounded-pill px-3 py-2"><i class="fas fa-clock me-1"></i>Upcoming</span>';
        case 'ongoing': 
            // Matches my_events: Success (Green)
            return '<span class="badge bg-success text-white rounded-pill px-3 py-2"><i class="fas fa-play-circle me-1"></i>Ongoing</span>';
        case 'completed': 
            // Matches my_events: Info (Cyan/Teal)
            return '<span class="badge bg-info text-white rounded-pill px-3 py-2"><i class="fas fa-check-circle me-1"></i>Completed</span>';
        case 'cancelled': 
            // Matches my_events: Danger (Red)
            return '<span class="badge bg-danger text-white rounded-pill px-3 py-2"><i class="fas fa-ban me-1"></i>Cancelled</span>';
        default: 
            return '<span class="badge bg-light text-dark border rounded-pill px-3 py-2">' . htmlspecialchars($status) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Matches - PIT Tallying</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* --- GLOBAL THEME --- */
        :root { 
            --sidebar-width: 260px; 
            --header-height: 82px; 
            --transition: all 0.3s ease; 
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); 
            --bg-light: #F8F9FA; 
        }
        
        body { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            background-attachment: fixed;
            margin: 0; padding: 0; min-height: 100vh; 
            font-family: 'Inter', sans-serif; display: flex; flex-direction: column; 
        }
        
        /* Glassmorphism Background */
        body::before {
            content: ''; position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: radial-gradient(circle at 20% 50%, rgba(120, 119, 198, 0.3), transparent 50%),
                        radial-gradient(circle at 80% 80%, rgba(99, 102, 241, 0.2), transparent 50%);
            pointer-events: none; z-index: 0;
        }

        /* --- NAVBAR --- */
        .navbar { 
            background: rgba(26, 26, 26, 0.95) !important; 
            backdrop-filter: blur(10px); 
            box-shadow: 0 8px 32px rgba(0,0,0,0.2); 
            padding: 1rem 1.5rem; 
            height: var(--header-height); 
            position: fixed; top: 0; left: 0; right: 0; z-index: 1050;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .navbar-brand .brand-heading { font-family: 'Poppins', sans-serif; font-weight: 700; }
        
        .user-dropdown .dropdown-toggle { 
            color: white; display: flex; align-items: center; text-decoration: none; 
            padding: 8px 16px; border-radius: 50px; background: rgba(255, 255, 255, 0.1); 
            backdrop-filter: blur(10px); transition: all 0.3s ease; border: 1px solid rgba(255, 255, 255, 0.2); 
        }
        .user-dropdown .dropdown-toggle:hover {
            background: rgba(255, 255, 255, 0.2); transform: translateY(-2px);
        }
        .user-dropdown .dropdown-toggle img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; margin-right: 10px; }
        
        /* --- FIXED SIDEBAR --- */
        .sidebar { 
            width: var(--sidebar-width); 
            position: fixed; top: var(--header-height); left: 0; 
            height: calc(100vh - var(--header-height)); 
            background: rgba(44, 62, 80, 0.95); 
            backdrop-filter: blur(10px); color: white; 
            box-shadow: 5px 0 30px rgba(0,0,0,0.3); z-index: 1040; 
            transition: all 0.3s ease; overflow-y: auto;
        }
        
        .sidebar-nav { padding: 20px 0; }
        .sidebar-nav .nav-link { 
            color: rgba(255, 255, 255, 0.7); font-size: 1.05rem; font-weight: 500; 
            padding: 12px 25px; display: flex; align-items: center; text-decoration: none; 
            border-left: 5px solid transparent; 
        }
        .sidebar-nav .nav-link i { width: 30px; text-align: center; font-size: 1em; margin-right: 10px; }
        .sidebar-nav .nav-link:hover { color: white; background: rgba(255, 255, 255, 0.05); border-left-color: #667eea; }
        .sidebar-nav .nav-link.active { color: white; background: linear-gradient(90deg, rgba(102, 126, 234, 0.2), transparent); border-left: 4px solid #667eea; }

        /* --- MAIN CONTENT --- */
        .main-content { 
            flex: 1 0 auto; 
            margin-left: var(--sidebar-width); 
            width: calc(100% - var(--sidebar-width)); 
            padding: 30px; margin-top: var(--header-height); 
            transition: margin-left 0.3s ease; position: relative; z-index: 1; 
        }
        
        footer { 
            flex-shrink: 0; background: rgba(44, 62, 80, 0.95) !important; 
            backdrop-filter: blur(10px); padding-left: var(--sidebar-width); 
            transition: padding-left 0.3s ease; position: relative; z-index: 1041; 
        }

        /* --- PAGE SPECIFIC STYLES --- */
        .page-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }
        .page-header::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 5px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }
        .page-title {
            font-family: 'Poppins', sans-serif; font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
            margin: 0; font-size: 2rem;
        }
        
        .breadcrumb { background: transparent; padding: 0; margin-bottom: 0; }
        .breadcrumb-item a { color: #667eea; text-decoration: none; }

        /* Cards */
        .card { border: none; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); overflow: hidden; margin-bottom: 1.5rem; }
        .card-header { background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); border-bottom: 1px solid #e9ecef; padding: 1.25rem 1.5rem; }
        
        /* Table */
        .results-table-container {
            background: #ffffff; border-radius: 0 0 12px 12px; 
            overflow-x: auto; position: relative; scrollbar-width: none; 
        }
        .results-table-container::-webkit-scrollbar { display: none; }
        
        .results-table thead th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: #2c3e50; font-weight: 700; font-size: 0.9rem; 
            text-transform: uppercase; padding: 1.2rem; border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
        }
        .results-table tbody td { 
            padding: 1.2rem; vertical-align: middle; border-bottom: 1px solid #f1f3f5; font-size: 0.95rem;
        }
        .results-table tbody tr:hover { background-color: #f8f9fa; transform: scale(1.001); }

        /* Sticky Action Column */
        .sticky-col { position: sticky; right: 0; z-index: 2; background-color: #fff; box-shadow: -5px 0 10px rgba(0,0,0,0.05); }
        .results-table thead th.sticky-col { background: #e9ecef; z-index: 5; }
        .results-table tbody tr:hover .sticky-col { background-color: #f8f9fa; }
        
        /* Action Buttons */
        .action-stack { display: flex; flex-direction: column; gap: 5px; justify-content: center; }
        .action-btn { 
            width: 38px; height: 38px; padding: 0; display: inline-flex; 
            align-items: center; justify-content: center; border-radius: 8px; 
            transition: all 0.2s ease; font-size: 0.9rem; border: none; 
        }
        .action-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
        .action-btn.btn-primary { background: linear-gradient(135deg, #3498db 0%, #2980b9 100%); color: white; }
        .action-btn.btn-danger { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); color: white; }

        /* Match Styles */
        .matchup-cell { font-size: 1.05rem; color: #333; }
        .score-cell { font-size: 1.2rem; font-weight: 800; color: #2c3e50; }
        .winner-badge { font-weight: 700; color: #27ae60; background: #eafaf1; padding: 4px 8px; border-radius: 6px; font-size: 0.85rem; }
        
        .form-control, .form-select { border-radius: 10px; padding: 0.6rem 1rem; border: 1px solid #dee2e6; }
        .form-control:focus, .form-select:focus { border-color: #667eea; box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25); }

        @media (max-width: 992px) {
            .sidebar { width: 0; } 
            .main-content, footer { margin-left: 0; width: 100%; }
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items: center" href="event_manager_dashboard.php">
                <img src="imageslogo.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white brand-heading" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light" style="font-size: 0.75rem;">Event Manager Panel</small>
                </div>
            </a>
            <div class="dropdown user-dropdown ms-auto me-2 me-lg-0">
                <a href="#" class="dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle navbar-profile-icon"></i>
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($display_name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="admin_profile.php"><i class="fas fa-user-circle me-2"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="login.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="sidebar" id="sidebar">
        <ul class="nav flex-column sidebar-nav">
            <li class="nav-item">
                <a class="nav-link" href="my_events.php"> <i class="fas fa-chevron-left me-2"></i> <span>Back to My Events</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="javascript:void(0);">
                    <i class="fas fa-trophy me-2"></i> <span>Manage Matches</span>
                </a>
            </li>
            <li class="nav-item mt-auto">
                <a class="nav-link text-danger" href="login.php">
                    <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <div class="main-content">
        <div class="container-fluid">
            
            <div class="page-header">
                <nav aria-label="breadcrumb" class="mb-3">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="event_manager_dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="my_events.php">My Assigned Events</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Manage Matches</li>
                    </ol>
                </nav>
                <div class="d-flex flex-column">
                    <h1 class="page-title"><?php echo $display_category_name; ?></h1>
                    <p class="text-muted mb-0 mt-2 fs-5">
                        <i class="fas fa-gamepad me-2"></i><?php echo htmlspecialchars($game_name . " / " . $event_name); ?>
                    </p>
                </div>
            </div>
            
            <?php if (!empty($alert_message)): ?>
                <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show shadow-sm mb-4" role="alert">
                    <i class="fas fa-<?php echo $alert_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                    <?php echo htmlspecialchars($alert_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
                    <h5 class="mb-2 mb-md-0 text-dark fw-bold"><i class="fas fa-list me-2 text-primary"></i>Match List</h5>
                    <div>
                        <?php 
                        if ($status_lower == 'results submitted') {
                            echo '<button class="btn btn-warning btn-sm me-2 rounded-pill fw-bold text-white shadow-sm" disabled><i class="fas fa-paper-plane me-2"></i>Winners Submitted</button>';
                        }
                        elseif ($status_lower == 'completed') {
                            echo '<button class="btn btn-success btn-sm me-2 rounded-pill fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#finalizeWinnersModal">
                                      <i class="fas fa-eye me-2"></i>View Final Winners
                                  </button>';
                        }
                        elseif ($all_matches_completed && !$is_locked) {
                             $button_text = ($status_lower == 'results rejected') ? 'Resubmit Winners' : 'Finalize Winners';
                             $button_icon = ($status_lower == 'results rejected') ? 'fas fa-exclamation-triangle' : 'fas fa-flag-checkered';
                             echo "<button class='btn btn-success btn-sm me-2 rounded-pill fw-bold shadow-sm' data-bs-toggle='modal' data-bs-target='#finalizeWinnersModal'>
                                       <i class='$button_icon me-2'></i>$button_text
                                   </button>";
                        }
                        elseif (!$all_matches_completed && !$is_locked) {
                             echo '<span class="badge bg-light text-secondary border px-3 py-2 rounded-pill me-2"><i class="fas fa-info-circle me-1"></i>Complete matches to finalize</span>';
                        }
                        ?>
                        
                        <?php if (!$is_locked): ?>
                        <button class="btn btn-primary btn-sm rounded-pill fw-bold shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#addMatchModal">
                            <i class="fas fa-plus me-2"></i>Add Match
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="card-body p-0">
                    <div class="table-responsive results-table-container">
                        <table class="table results-table table-hover align-middle" id="matchesTable">
                            <thead>
                                <tr>
                                    <th style="min-width: 220px;">Matchup</th>
                                    <th class="text-center" style="min-width: 100px;">Score</th>
                                    <th class="text-center" style="min-width: 100px;">Winner</th>
                                    <th class="text-center" style="min-width: 120px;">Status</th>
                                    <th style="min-width: 150px;">Date & Time</th>
                                    <th style="min-width: 180px;">Venue</th>
                                    <th class="text-end sticky-col" style="min-width: 90px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($matches)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted p-5">
                                            <i class="fas fa-clipboard-list fa-3x mb-3 text-secondary opacity-50"></i>
                                            <p class="mb-0 fw-bold">No matches created yet.</p>
                                            <p class="small">Click "Add Match" to start scheduling.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($matches as $match): ?>
                                        <?php
                                            $team1_class = 'text-dark';
                                            $team2_class = 'text-dark';
                                            $winner_id = $match['winner_team_id'];
                                            
                                            if ($winner_id) {
                                                if ($winner_id == $match['team1_id']) {
                                                    $team1_class = 'text-success fw-bold';
                                                    $team2_class = 'text-muted text-decoration-line-through';
                                                } elseif ($winner_id == $match['team2_id']) {
                                                    $team2_class = 'text-success fw-bold';
                                                    $team1_class = 'text-muted text-decoration-line-through';
                                                }
                                            }
                                        ?>
                                        <tr>
                                            <td class="matchup-cell">
                                                <div class="<?php echo $team1_class; ?>"><?= htmlspecialchars($match['team1_name']) ?></div>
                                                <div class="small text-muted fw-bold my-1 text-uppercase" style="font-size: 0.7rem; letter-spacing: 1px;">VS</div>
                                                <div class="<?php echo $team2_class; ?>"><?= htmlspecialchars($match['team2_name']) ?></div>
                                            </td>
                                            <td class="text-center score-cell">
                                                <?= htmlspecialchars($match['score1']) ?> - <?= htmlspecialchars($match['score2']) ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if(!empty($match['winner_initialism'])): ?>
                                                    <span class="winner-badge"><i class="fas fa-trophy me-1"></i><?= htmlspecialchars($match['winner_initialism']) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted small">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?= getMatchStatusBadge($match['status']) ?>
                                            </td>
                                            <td>
                                                <?php if($match['match_date']): ?>
                                                    <div class="fw-bold text-dark"><?= date('M d, Y', strtotime($match['match_date'])) ?></div>
                                                    <?php if($match['match_time']): ?>
                                                        <div class="small text-muted"><i class="far fa-clock me-1"></i><?= date('g:i A', strtotime($match['match_time'])) ?></div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted small">Date TBA</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if($match['venue']): ?>
                                                    <i class="fas fa-map-marker-alt text-danger me-1"></i><?= htmlspecialchars($match['venue']) ?>
                                                <?php else: ?>
                                                    <span class="text-muted small">Venue TBA</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end sticky-col">
                                                <div class="action-stack">
                                                <?php if (!$is_locked): ?>
                                                    <button class="action-btn btn-primary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#editMatchModal"
                                                        data-match-id="<?= $match['match_id'] ?>"
                                                        data-team1-id="<?= $match['team1_id'] ?>"
                                                        data-team2-id="<?= $match['team2_id'] ?>"
                                                        data-match-date="<?= $match['match_date'] ?>"
                                                        data-match-time="<?= $match['match_time'] ?>"
                                                        data-venue="<?= htmlspecialchars($match['venue']) ?>"
                                                        data-status="<?= $match['status'] ?>"
                                                        data-score1="<?= $match['score1'] ?>"
                                                        data-score2="<?= $match['score2'] ?>"
                                                        data-winner-team-id="<?= $match['winner_team_id'] ?>"
                                                        title="Edit Match">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="action-btn btn-danger"
                                                            data-bs-toggle="modal" data-bs-target="#deleteMatchModal"
                                                            data-match-id="<?= $match['match_id'] ?>"
                                                            data-match-name="<?= htmlspecialchars($match['team1_name'] . ' vs ' . $match['team2_name']) ?>"
                                                            title="Delete Match">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-dark border"><i class="fas fa-lock me-1"></i>Locked</span>
                                                <?php endif; ?>
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

    <div class="modal fade" id="addMatchModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2 text-primary"></i>Create New Match</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_match">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Team 1 (College)</label>
                                <select class="form-select" name="team1_id" required>
                                    <option value="" disabled selected>Select Team 1</option>
                                    <?php foreach ($colleges as $college): ?>
                                        <option value="<?= $college['college_id'] ?>"><?= htmlspecialchars($college['college_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Team 2 (College)</label>
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
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4 fw-bold">Create Match</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editMatchModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2 text-primary"></i>Edit Match Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_match">
                    <input type="hidden" name="match_id" id="edit_match_id">
                    <div class="modal-body">
                        <div class="row g-4">
                            <div class="col-lg-7">
                                <h6 class="text-uppercase text-muted fw-bold mb-3 small">Match Information</h6>
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
                                </div>
                            </div>
                            
                            <div class="col-lg-5 border-start">
                                <h6 class="text-uppercase text-success fw-bold mb-3 small">Scoring & Status</h6>
                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <label class="form-label">Status</label>
                                        <select class="form-select fw-bold" id="edit_status" name="status" required>
                                            <option value="Upcoming">Upcoming</option>
                                            <option value="Ongoing">Ongoing</option>
                                            <option value="Completed">Completed</option>
                                            <option value="Cancelled">Cancelled</option>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label text-center w-100">Team 1 Score</label>
                                        <input type="number" class="form-control text-center fw-bold fs-5" id="edit_score1" name="score1" value="0" min="0" onfocus="this.select()" onblur="if(this.value===''){this.value='0'}">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label text-center w-100">Team 2 Score</label>
                                        <input type="number" class="form-control text-center fw-bold fs-5" id="edit_score2" name="score2" value="0" min="0" onfocus="this.select()" onblur="if(this.value===''){this.value='0'}">
                                    </div>
                                    <div class="col-md-12">
                                        <label class="form-label">Winner (if Completed)</label>
                                        <select class="form-select" id="edit_winner_team_id" name="winner_team_id">
                                            </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary px-4 fw-bold">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="deleteMatchModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="delete_match">
                    <input type="hidden" name="match_id" id="delete_match_id">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i>Confirm Deletion</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete this match?</p>
                        <p class="text-dark fw-bold fs-5 text-center" id="delete_match_name"></p>
                        <p class="text-danger small mb-0 text-center"><i class="fas fa-exclamation-triangle me-1"></i>This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger px-4 fw-bold">Yes, Delete Match</button>
                    </div>
                </form>
            </div> 
        </div>
    </div>

    <div class="modal fade" id="finalizeWinnersModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-award me-2 text-warning"></i>Submit Final Winners</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="submit_final_winners">
                    <div class="modal-body">
                        
                        <?php if ($status_lower == 'completed'): ?>
                            <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Results approved and locked.</div>
                        <?php elseif ($status_lower == 'results submitted'): ?>
                             <div class="alert alert-warning"><i class="fas fa-clock me-2"></i>Pending administrator approval.</div>
                        <?php else: ?>
                            <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>All matches are complete. Select the overall top 3 winners.</div>
                        <?php endif; ?>
                        
                        <div class="row mb-3 align-items-center p-2 rounded border-bottom">
                            <div class="col-md-8">
                                <label class="form-label fw-bold text-warning"><i class="fas fa-medal"></i> GOLD Winner</label>
                                <select name="gold_winner_college_id" class="form-select" <?php echo $is_locked ? 'disabled' : ''; ?> required>
                                    <option value="">Select Gold Winner</option>
                                    <?php foreach ($colleges as $college): ?>
                                        <option value="<?= $college['college_id'] ?>" <?php echo ($college['college_id'] == $gold_winner_id) ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($college['college_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Medal Count</label>
                                <input type="number" name="gold_count" class="form-control fw-bold text-center" value="<?php echo $gold_count; ?>" min="0" onfocus="this.select()" onblur="if(this.value===''){this.value='0'}" <?php echo $is_locked ? 'disabled' : ''; ?>>
                            </div>
                        </div>

                        <div class="row mb-3 align-items-center p-2 rounded border-bottom">
                            <div class="col-md-8">
                                <label class="form-label fw-bold text-secondary"><i class="fas fa-medal"></i> SILVER Winner</label>
                                <select name="silver_winner_college_id" class="form-select" <?php echo $is_locked ? 'disabled' : ''; ?> required>
                                    <option value="">Select Silver Winner</option>
                                    <?php foreach ($colleges as $college): ?>
                                        <option value="<?= $college['college_id'] ?>" <?php echo ($college['college_id'] == $silver_winner_id) ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($college['college_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Medal Count</label>
                                <input type="number" name="silver_count" class="form-control fw-bold text-center" value="<?php echo $silver_count; ?>" min="0" onfocus="this.select()" onblur="if(this.value===''){this.value='0'}" <?php echo $is_locked ? 'disabled' : ''; ?>>
                            </div>
                        </div>

                        <div class="row align-items-center p-2 rounded">
                            <div class="col-md-8">
                                <label class="form-label fw-bold" style="color: #cd7f32;"><i class="fas fa-medal"></i> BRONZE Winner</label>
                                <select name="bronze_winner_college_id" class="form-select" <?php echo $is_locked ? 'disabled' : ''; ?> required>
                                    <option value="">Select Bronze Winner</option>
                                    <?php foreach ($colleges as $college): ?>
                                        <option value="<?= $college['college_id'] ?>" <?php echo ($college['college_id'] == $bronze_winner_id) ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($college['college_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Medal Count</label>
                                <input type="number" name="bronze_count" class="form-control fw-bold text-center" value="<?php echo $bronze_count; ?>" min="0" onfocus="this.select()" onblur="if(this.value===''){this.value='0'}" <?php echo $is_locked ? 'disabled' : ''; ?>>
                            </div>
                        </div>

                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <?php if (!$is_locked): ?>
                            <button type="submit" class="btn btn-success px-4 fw-bold">Submit Final Winners</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <footer class="bg-dark text-white py-4" id="footer">
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small>
        </div>
    </footer>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            
            // --- Edit Modal Auto-population ---
            const editMatchModal = document.getElementById('editMatchModal');
            editMatchModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const allData = button.dataset;

                document.getElementById('edit_match_id').value = allData.matchId;
                document.getElementById('edit_team1_id').value = allData.team1Id;
                document.getElementById('edit_team2_id').value = allData.team2Id;
                document.getElementById('edit_match_date').value = allData.matchDate;
                document.getElementById('edit_match_time').value = allData.matchTime;
                document.getElementById('edit_venue').value = allData.venue;
                document.getElementById('edit_status').value = allData.status;
                document.getElementById('edit_score1').value = allData.score1;
                document.getElementById('edit_score2').value = allData.score2;
                
                const team1Select = document.getElementById('edit_team1_id');
                const team2Select = document.getElementById('edit_team2_id');
                const winnerSelect = document.getElementById('edit_winner_team_id');

                const team1Name = team1Select.querySelector(`option[value="${allData.team1Id}"]`).textContent;
                const team2Name = team2Select.querySelector(`option[value="${allData.team2Id}"]`).textContent;

                winnerSelect.innerHTML = '<option value="">None / Draw</option>'; // Clear
                winnerSelect.innerHTML += `<option value="${allData.team1Id}">${team1Name}</option>`;
                winnerSelect.innerHTML += `<option value="${allData.team2Id}">${team2Name}</option>`;
                
                winnerSelect.value = allData.winnerTeamId;
            });

            // --- Delete Modal Auto-population ---
            const deleteMatchModal = document.getElementById('deleteMatchModal');
            deleteMatchModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                document.getElementById('delete_match_id').value = button.dataset.matchId;
                document.getElementById('delete_match_name').textContent = button.dataset.matchName;
            });

            // --- Sidebar Logic ---
            const mobileToggle = document.getElementById('mobileToggle');
            if (mobileToggle) {
                mobileToggle.addEventListener('click', function() {
                    document.getElementById('sidebar').classList.toggle('show');
                });
            }

            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth > 992) {
                        document.getElementById('sidebar').classList.remove('show');
                    }
                }, 250);
            });

            // --- Sidebar Height Adjustment ---
            const footer = document.querySelector('footer');
            const sidebar = document.getElementById('sidebar');
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