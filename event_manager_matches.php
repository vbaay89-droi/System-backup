<?php
session_start();
require_once 'config.php'; // Your DB connection
require_once 'db_connect.php'; // ### ADDED: Include the logger function ###

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

// 2. GET & VALIDATE CATEGORY ID
if (!isset($_GET['category_id'])) {
    header('Location: event_manager_dashboard.php'); // Redirect if no ID
    exit();
}
$category_id = (int)$_GET['category_id'];

// 3. SECURITY CHECK: VERIFY THIS EM OWNS THIS CATEGORY
$category_name = '';
$event_name = '';
$game_name = '';
$category_status = ''; 

// ### CHANGE 1: Added winner columns to the query ###
// ### FIX: Changed c.event_type to c.category_type ###
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
    // Not assigned, or not a 'Match' type event.
    $_SESSION['alert_message'] = "Permission denied or invalid event type.";
    $_SESSION['alert_type'] = "danger";
    header('Location: event_manager_dashboard.php');
    exit();
}
$row = $result_check->fetch_assoc();
$category_name = $row['category_name'];
$event_name = $row['event_name'];
$game_name = $row['game_name'];
$category_status = $row['status']; // Store the category's status

// ### CHANGE 2: Store winner data in variables ###
$gold_winner_id = $row['gold_winner_college_id'];
$gold_count = $row['gold_count'];
$silver_winner_id = $row['silver_winner_college_id'];
$silver_count = $row['silver_count'];
$bronze_winner_id = $row['bronze_winner_college_id'];
$bronze_count = $row['bronze_count'];

$stmt_check->close();

// ### CHANGE 3: Apply the "Completed" rename logic ###
if (strtolower($category_status) === 'results approved') {
    $category_status = 'Completed';
}

// ### CHANGE 4: Create a "lock" variable based on status ###
$status_lower = strtolower($category_status);
$is_locked = (in_array($status_lower, ['results submitted', 'completed']));


// 4. FORM HANDLING (Add/Edit/Delete Match for THIS category)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['action'])) {
            throw new Exception("Invalid action specified.");
        }
        $action = $_POST['action'];

        // --- Prevent actions if locked ---
        if ($is_locked && $action !== 'submit_final_winners') {
             // We allow 'submit_final_winners' to be caught, but we'll block it inside
             // This is to prevent adding/editing/deleting matches
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
            $new_match_id = (int)$conn->insert_id; // ### ADDED: Get new ID ###
            $stmt->close();
            $alert_message = "Match created successfully.";

            // ### ADDED: Log this action ###
            try {
                $context = ['team1_id' => $team1_id, 'team2_id' => $team2_id, 'match_date' => $match_date, 'venue' => $venue, 'category_name' => $category_name];
                log_activity($conn, $user_id, 'CREATED_MATCH', $new_match_id, 'match', $category_id, 'category', $context);
            } catch (Exception $log_e) { 
                error_log("Failed to log CREATED_MATCH: " . $log_e->getMessage()); 
            }

        // --- UPDATE MATCH & RESULTS ---
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
            
            // ### ADDED: Log this action ###
            try {
                $context = ['score1' => $score1, 'score2' => $score2, 'status' => $status, 'winner_team_id' => $winner_team_id, 'category_name' => $category_name];
                log_activity($conn, $user_id, 'UPDATED_MATCH', $match_id, 'match', $category_id, 'category', $context);
            } catch (Exception $log_e) { 
                error_log("Failed to log UPDATED_MATCH: " . $log_e->getMessage()); 
            }

        // --- DELETE MATCH ---
        } elseif ($action === 'delete_match') {
            $match_id = (int)$_POST['match_id'];
            $stmt = $conn->prepare("DELETE FROM matches WHERE match_id = ? AND category_id = ?");
            $stmt->bind_param("ii", $match_id, $category_id);
            $stmt->execute();
            $stmt->close();
            $alert_message = "Match deleted successfully.";
            
            // ### ADDED: Log this action ###
            try {
                $context = ['category_name' => $category_name];
                log_activity($conn, $user_id, 'DELETED_MATCH', $match_id, 'match', $category_id, 'category', $context);
            } catch (Exception $log_e) { 
                error_log("Failed to log DELETED_MATCH: " . $log_e->getMessage()); 
            }
        
        // --- ACTION: SUBMIT FINAL WINNERS ---
        } elseif ($action === 'submit_final_winners') {
            
            // ### CHANGE 5: Block submission if already locked ###
            if ($is_locked) {
                throw new Exception("Results are already submitted or completed and cannot be modified.");
            }

            // ### NEW: Server-side validation ###
            if (empty($_POST['gold_winner_college_id']) || empty($_POST['silver_winner_college_id']) || empty($_POST['bronze_winner_college_id'])) {
                throw new Exception("All medal winners (Gold, Silver, and Bronze) must be selected.");
            }

            $gold_id = (int)$_POST['gold_winner_college_id'];
            $silver_id = (int)$_POST['silver_winner_college_id'];
            $bronze_id = (int)$_POST['bronze_winner_college_id'];

            // Optional: Check for duplicate winners
            $winners = [$gold_id, $silver_id, $bronze_id];
            if (count($winners) !== count(array_unique($winners))) {
                throw new Exception("The same team cannot be selected for multiple medals.");
            }
            // ### END: Server-side validation ###

            $gold_count = !empty($_POST['gold_count']) ? (int)$_POST['gold_count'] : 0;
            $silver_count = !empty($_POST['silver_count']) ? (int)$_POST['silver_count'] : 0;
            $bronze_count = !empty($_POST['bronze_count']) ? (int)$_POST['bronze_count'] : 0;
            
            // New status to send to Admin
            $new_status = 'Results Submitted'; 

            $stmt = $conn->prepare("UPDATE categories SET
                gold_winner_college_id = ?,
                gold_count = ?,
                silver_winner_college_id = ?,
                silver_count = ?,
                bronze_winner_college_id = ?,
                bronze_count = ?,
                status = ?
                WHERE category_id = ?
            ");
            $stmt->bind_param("iiiisisi", 
                $gold_id, $gold_count, $silver_id, $silver_count, 
                $bronze_id, $bronze_count, $new_status, $category_id
            );
            
            if ($stmt->execute()) {
                
                // ### ADDED: Log this action ###
                try {
                    $context = ['category_name' => $category_name, 'gold_id' => $gold_id, 'silver_id' => $silver_id, 'bronze_id' => $bronze_id, 'type' => 'Match'];
                    log_activity($conn, $user_id, 'SUBMITTED_RESULTS', $category_id, 'category', null, null, $context);
                } catch (Exception $log_e) { 
                    error_log("Failed to log SUBMITTED_RESULTS (Match): " . $log_e->getMessage()); 
                }
                
                $_SESSION['alert_message'] = "Final winners submitted for approval!";
                $_SESSION['alert_type'] = "success";
                
                // ### FIX: Redirect to my_events.php ###
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


// 5. FETCH DATA FOR THIS PAGE
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

function getMatchStatusBadge($status) {
    switch (strtolower($status)) {
        case 'upcoming': return '<span class="badge bg-info">Upcoming</span>';
        case 'ongoing': return '<span class="badge bg-primary">Ongoing</span>';
        case 'completed': return '<span class="badge bg-success">Completed</span>';
        case 'cancelled': return '<span class="badge bg-danger">Cancelled</span>';
        default: return '<span class="badge bg-light text-dark">' . htmlspecialchars($status) . '</span>';
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
        :root { 
            --sidebar-width: 260px; 
            --header-height: 82px; 
            --transition: all 0.3s ease; 
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); 
            --bg-light: #F8F9FA; 
        }
        body { background-color: var(--bg-light); margin: 0; padding: 0; min-height: 100vh; font-family: 'Inter', sans-serif; display: flex; flex-direction: column; }
        .navbar { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.15); padding: 1rem 1.5rem; height: var(--header-height); position: fixed; top: 0; left: 0; right: 0; z-index: 1050; }
        .navbar-brand .brand-heading { font-family: 'Poppins', sans-serif; font-weight: 700; }
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; }
        .user-dropdown .dropdown-toggle img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; margin-right: 10px; }
        .sidebar { width: var(--sidebar-width); position: fixed; top: var(--header-height); left: 0; height: calc(100vh - var(--header-height)); background: #2c3e50; color: white; box-shadow: 5px 0 15px rgba(0,0,0,0.2); z-index: 1040; transition: width var(--transition); overflow-y: auto; overflow-x: hidden; }
        .sidebar-nav { padding: 50px 0 20px 0; }
        .sidebar-nav .nav-link { color: rgba(255, 255, 255, 0.7); font-size: 1.05rem; font-weight: 500; padding: 15px 25px; transition: var(--transition); border-left: 5px solid transparent; margin: 2px 0; display: flex; align-items: center; text-decoration: none; }
        .sidebar-nav .nav-link i { width: 30px; text-align: center; flex-shrink: 0; font-size: 0.95em; }
        .sidebar-nav .nav-link:hover { color: white; background: rgba(255, 255, 255, 0.05); border-left-color: #1abc9c; }
        .sidebar-nav .nav-link.active { color: white; background: rgba(255, 255, 255, 0.1); border-left-color: #3498db; font-weight: 600; }
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
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
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
        
        /* Page-specific Styles */
        .page-card { background: white; border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        .page-card-header { background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; padding: 1.25rem 1.5rem; border-top-left-radius: 15px; border-top-right-radius: 15px; }
        .page-card-header h4 { margin: 0; font-family: 'Poppins', sans-serif; font-weight: 600; }
        .table-responsive { border-radius: 10px; }
        .table thead th { background-color: #f8f9fa; }
        .table-action-btn { width: 35px; height: 35px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }
        .matchup-cell strong { font-size: 1rem; color: #333; }
        .matchup-cell small { font-size: 0.8rem; }
        .score-cell { font-size: 1.1rem; font-weight: 700; }
        
        .matchup-cell .winner {
            font-weight: 700;
            color: #198754; 
        }
        .matchup-cell .loser {
            color: #6c757d; 
            opacity: 0.8;
            text-decoration: line-through;
        }
        .winner-initialism {
            font-weight: 700;
            color: #198754;
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
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($username); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="admin_profile.php"><i class="fas fa-user-circle"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="login.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
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
                <a class="nav-link active" href="">
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
            
            <nav aria-label="breadcrumb" class="mb-2">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="event_manager_dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="my_events.php">My Assigned Events</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Manage Matches</li>
                </ol>
            </nav>
            <h1 class="section-title mb-1"><?php echo htmlspecialchars($category_name); ?></h1>
            <p class="text-muted fs-5"><?php echo htmlspecialchars($game_name . " / " . $event_name); ?></p>
            
            <?php if (!empty($alert_message)): ?>
                <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($alert_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="page-card">
                <div class="page-card-header d-flex flex-wrap justify-content-between align-items-center">
                    <h4 class="mb-2 mb-md-0">All Matches</h4>
                    <div>
                        <?php 
                        // ### CHANGE 6: Updated button logic ###
                        
                        // SCENARIO 1: Results are SUBMITTED
                        if ($status_lower == 'results submitted') {
                            echo '<button class="btn btn-warning me-2" disabled><i class="fas fa-paper-plane me-2"></i>Winners Submitted</button>';
                        }
                        // SCENARIO 2: Results are COMPLETED
                        elseif ($status_lower == 'completed') {
                            echo '<button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#finalizeWinnersModal">
                                      <i class="fas fa-eye me-2"></i>View Final Winners
                                  </button>';
                        }
                        // SCENARIO 3: Ready to SUBMIT (all matches done, status is 'pending' or 'rejected')
                        elseif ($all_matches_completed && !$is_locked) {
                             $button_text = ($status_lower == 'results rejected') ? 'Resubmit Winners' : 'Finalize Winners';
                             $button_icon = ($status_lower == 'results rejected') ? 'fas fa-exclamation-triangle' : 'fas fa-flag-checkered';
                             echo "<button class='btn btn-success me-2' data-bs-toggle='modal' data-bs-target='#finalizeWinnersModal'>
                                       <i class='$button_icon me-2'></i>$button_text
                                   </button>";
                        }
                        // SCENARIO 4: Not ready to submit (matches incomplete)
                        elseif (!$all_matches_completed && !$is_locked) {
                             echo '<span class="text-muted fst-italic me-2">Mark all matches as "Completed" to finalize winners.</span>';
                        }
                        ?>
                        
                        <?php // ### CHANGE 7: Hide "Add Match" button if locked ### ?>
                        <?php if (!$is_locked): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMatchModal">
                            <i class="fas fa-plus me-2"></i>Add Match
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="matchesTable">
                            <thead>
                                <tr>
                                    <th>Matchup</th>
                                    <th class="text-center">Score</th>
                                    <th class="text-center">Winner</th>
                                    <th class="text-center">Status</th>
                                    <th>Date & Time</th>
                                    <th>Venue</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($matches)): ?>
                                    <tr><td colspan="7" class="text-center text-muted p-4">No matches created yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($matches as $match): ?>
                                        <?php
                                            $team1_class = '';
                                            $team2_class = '';
                                            $winner_id = $match['winner_team_id'];
                                            
                                            if ($winner_id) {
                                                if ($winner_id == $match['team1_id']) {
                                                    $team1_class = 'winner';
                                                    $team2_class = 'loser';
                                                } elseif ($winner_id == $match['team2_id']) {
                                                    $team2_class = 'winner';
                                                    $team1_class = 'loser';
                                                }
                                            }
                                        ?>
                                        <tr>
                                            <td class="matchup-cell">
                                                <strong class="<?php echo $team1_class; ?>"><?= htmlspecialchars($match['team1_name']) ?></strong>
                                                <small class="text-muted d-block">vs</small>
                                                <strong class="<?php echo $team2_class; ?>"><?= htmlspecialchars($match['team2_name']) ?></strong>
                                            </td>
                                            <td class="text-center score-cell">
                                                <span class="<?php echo $team1_class; ?>"><?= htmlspecialchars($match['score1']) ?></span> - 
                                                <span class="<?php echo $team2_class; ?>"><?= htmlspecialchars($match['score2']) ?></span>
                                            </td>
                                            <td class="text-center winner-initialism">
                                                <?php echo htmlspecialchars($match['winner_initialism'] ?? '---'); ?>
                                            </td>
                                            <td class="text-center">
                                                <?= getMatchStatusBadge($match['status']) ?>
                                            </td>
                                            <td>
                                                <?= $match['match_date'] ? htmlspecialchars(date('M d, Y', strtotime($match['match_date']))) : 'TBA' ?>
                                                <small class="text-muted d-block"><?= $match['match_time'] ? htmlspecialchars(date('g:i A', strtotime($match['match_time']))) : '' ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($match['venue']) ?></td>
                                            <td class="text-end">
                                                <?php // ### CHANGE 8: Hide edit/delete buttons if locked ### ?>
                                                <?php if (!$is_locked): ?>
                                                    <button class="btn btn-sm btn-primary table-action-btn" 
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
                                                        title="Edit Match & Results">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-danger table-action-btn"
                                                            data-bs-toggle="modal" data-bs-target="#deleteMatchModal"
                                                            data-match-id="<?= $match['match_id'] ?>"
                                                            data-match-name="<?= htmlspecialchars($match['team1_name'] . ' vs ' . $match['team2_name']) ?>"
                                                            title="Delete Match">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-dark">Locked</span>
                                                <?php endif; ?>
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
                    <h5 class="modal-title">Create New Match</h5>
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
                                <label class="form-label">Match Date</label>
                                <input type="date" class="form-control" name="match_date">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Match Time</label>
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
                        <button type="submit" class="btn btn-primary">Create Match</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editMatchModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Match & Results</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_match">
                    <input type="hidden" name="match_id" id="edit_match_id">
                    <div class="modal-body">
                        <div class="row g-4">
                            <div class="col-lg-7">
                                <h5>Match Details</h5>
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
                                        <label class="form-label">Match Date</label>
                                        <input type="date" class="form-control" id="edit_match_date" name="match_date">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Match Time</label>
                                        <input type="time" class="form-control" id="edit_match_time" name="match_time">
                                    </div>
                                    <div class="col-md-12">
                                        <label class="form-label">Venue</label>
                                        <input type="text" class="form-control" id="edit_venue" name="venue">
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-5" style="border-left: 1px solid #dee2e6;">
                                <h5>Match Results</h5>
                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <label class="form-label">Match Status</label>
                                        <select class="form-select" id="edit_status" name="status" required>
                                            <option value="Upcoming">Upcoming</option>
                                            <option value="Ongoing">Ongoing</option>
                                            <option value="Completed">Completed</option>
                                            <option value="Cancelled">Cancelled</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Team 1 Score</label>
                                        <input type="number" class="form-control" id="edit_score1" name="score1" value="0" min="0">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Team 2 Score</label>
                                        <input type="number" class="form-control" id="edit_score2" name="score2" value="0" min="0">
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
                        <button type="submit" class="btn btn-primary">Save Changes</button>
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
                        <h5 class="modal-title">Confirm Deletion</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete this match?</p>
                        <p class="text-dark fw-bold" id="delete_match_name"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Yes, Delete</button>
                    </div>
                </form>
            </div> 
        </div>
    </div>

    <div class="modal fade" id="finalizeWinnersModal" tabindex="-1" aria-labelledby="finalizeWinnersModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="finalizeWinnersModalLabel">Submit Final Winners for <?php echo htmlspecialchars($category_name); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="submit_final_winners">
                    <div class="modal-body">
                        
                        <?php if ($status_lower == 'completed'): ?>
                            <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>These results have been approved and are locked.</div>
                        <?php elseif ($status_lower == 'results submitted'): ?>
                             <div class="alert alert-warning"><i class="fas fa-paper-plane me-2"></i>These results are pending administrator approval.</div>
                        <?php else: ?>
                            <p class="text-muted">All matches are marked 'Completed'. You can now submit the final 1st, 2nd, and 3rd place winners and their **Medal Counts** to the administrator for approval.</p>
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label"><i class="fas fa-medal" style="color: gold;"></i> Gold Medal Winner</label>
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
                                <label class="form-label">Medal Count (Gold)</label>
                                <input type="number" name="gold_count" class="form-control" value="<?php echo $gold_count; ?>" <?php echo $is_locked ? 'disabled' : ''; ?>>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label"><i class="fas fa-medal" style="color: silver;"></i> Silver Medal Winner</label>
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
                                <label class="form-label">Medal Count (Silver)</label>
                                <input type="number" name="silver_count" class="form-control" value="<?php echo $silver_count; ?>" <?php echo $is_locked ? 'disabled' : ''; ?>>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label"><i class="fas fa-medal" style="color: #cd7f32;"></i> Bronze Medal Winner</label>
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
S                                <label class="form-label">Medal Count (Bronze)</label>
                                <input type="number" name="bronze_count" class="form-control" value="<?php echo $bronze_count; ?>" <?php echo $is_locked ? 'disabled' : ''; ?>>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <?php // Hide submit button if locked ?>
                        <?php if (!$is_locked): ?>
                            <button type="submit" class="btn btn-success">Submit Final Winners</button>
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

            // --- ### NEW: FIX SIDEBAR/FOOTER OVERLAP ### ---
            const footer = document.querySelector('footer');
            const navbar = document.querySelector('.navbar');

            if (sidebar && footer && navbar) {
                function adjustSidebarHeight() {
                    // This logic should only apply to desktop view
                    if (window.innerWidth <= 992) {
                        sidebar.style.height = ''; // Reset to CSS default for mobile
                        return;
                    }

                    const navbarHeight = navbar.offsetHeight;
                    const footerTop = footer.getBoundingClientRect().top;
                    const viewportHeight = window.innerHeight;
                    
                    // 1. Calculate the max possible height (navbar top to viewport bottom)
                    const maxSidebarHeight = viewportHeight - navbarHeight;

                    // 2. Calculate the available height (navbar top to footer top)
                    const availableHeight = footerTop - navbarHeight;

                    // 3. Choose the smaller of the two heights, but never less than 0
                    const newHeight = Math.max(0, Math.min(maxSidebarHeight, availableHeight));
                    
                    // 4. Apply the new height as an inline style
                    sidebar.style.height = `${newHeight}px`;
                }

                // Add listeners for scroll and resize events
                window.addEventListener('scroll', adjustSidebarHeight, { passive: true });
                window.addEventListener('resize', adjustSidebarHeight);
                
                // Initial call to set the correct height on page load
                // Small delay to ensure all elements are rendered
                setTimeout(adjustSidebarHeight, 100);
            }
        });
    </script>
</body>
</html>