<?php
session_start();
require_once '../db_connect.php'; // Use the main config file

// 1. SECURITY & ACCESS CONTROL
if (!isset($_SESSION['role']) || 
    ($_SESSION['role'] !== 'Sports Director' && $_SESSION['role'] !== 'Administrator')
) {
    header('Location: ../login.php'); // Redirect to main login page
    exit();
}
$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Sports Director';
$current_page = basename($_SERVER['PHP_SELF']); // This will be 'results.php'
$user_id = (int)$_SESSION['user_id']; // Current logged-in user

// --- Logic for Sidebar Accordions ---
$event_pages = ['Manage_Games.php', 'Manage_Game_Events.php', 'Manage_Categories.php'];
$is_event_page = in_array($current_page, $event_pages);
$management_pages = ['colleges.php', 'events.php', 'results.php', 'reports.php'];
$is_management_page = in_array($current_page, $management_pages);

$alert_message = '';
$alert_type = 'success';

// 2. FORM HANDLING (APPROVE / REJECT / REVOKE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    $conn->begin_transaction();
    try {
        $category_id = (int)$_POST['category_id'];
        $event_name_for_log = $_POST['event_name_for_log'] ?? 'an event';
        $context = ['event_name' => $event_name_for_log];

        // Action: Approve a 'Submitted' result
        if ($_POST['action'] === 'approve_result') {
            $stmt_cat = $conn->prepare(
               "UPDATE categories SET 
                status = 'Results Approved',
                approved_by_user_id = ?, 
                approved_at = NOW()
                WHERE category_id = ? AND status = 'Results Submitted'"
            );
            $stmt_cat->bind_param("ii", $user_id, $category_id);
            $stmt_cat->execute();
            
            if ($stmt_cat->affected_rows > 0) {
                // log_activity($conn, $user_id, 'APPROVED_RESULT', $category_id, 'category', null, null, $context);
                $conn->commit();
                $alert_message = "SUCCESS: The results have been approved and published!";
            } else {
                throw new Exception("Could not find the pending result. It might have already been processed.");
            }
            $stmt_cat->close();

        } 
        // Action: Reject a 'Submitted' result
        elseif ($_POST['action'] === 'reject_result') {
            $rejection_reason = trim($_POST['rejection_reason']) ?: 'No reason provided.';
            $context['rejection_reason'] = $rejection_reason;

            $stmt_cat = $conn->prepare(
                "UPDATE categories SET 
                 status = 'Results Rejected', 
                 notes = ? 
                 WHERE category_id = ? AND status = 'Results Submitted'"
            );
            $stmt_cat->bind_param("si", $rejection_reason, $category_id);
            $stmt_cat->execute();
            
            if ($stmt_cat->affected_rows > 0) {
                // log_activity($conn, $user_id, 'REJECTED_RESULT', $category_id, 'category', null, null, $context);
                $conn->commit();
                $alert_message = "SUCCESS: The results have been rejected and sent back to the Event Manager.";
            } else {
                 throw new Exception("Could not find the pending result. It might have already been processed.");
            }
            $stmt_cat->close();
        }
        
        // ### NEW ACTION: REVOKE an 'Approved' result ###
        elseif ($_POST['action'] === 'revoke_result') {
            $revoke_reason = trim($_POST['revoke_reason']) ?: 'Approval revoked by Director.';
            $context['revoke_reason'] = $revoke_reason;

            // Set status back to 'Rejected' so the manager MUST review it.
            // Clear approval data.
            $stmt_cat = $conn->prepare(
                "UPDATE categories SET 
                 status = 'Results Rejected', 
                 notes = ?,
                 approved_by_user_id = NULL,
                 approved_at = NULL
                 WHERE category_id = ? AND status = 'Results Approved'"
            );
            $stmt_cat->bind_param("si", $revoke_reason, $category_id);
            $stmt_cat->execute();
            
            if ($stmt_cat->affected_rows > 0) {
                // log_activity($conn, $user_id, 'REVOKED_RESULT', $category_id, 'category', null, null, $context);
                $conn->commit();
                $alert_message = "SUCCESS: The approval has been revoked. The results are now marked as 'Rejected' and sent back to the Event Manager.";
            } else {
                 throw new Exception("Could not find the approved result. It might have already been processed.");
            }
            $stmt_cat->close();
        }

    } catch (Exception $e) {
        $conn->rollback();
        $alert_message = "ERROR: " . $e->getMessage();
        $alert_type = 'danger';
        error_log($e->getMessage());
    }
}

// 4. FETCH DATA FOR DISPLAY
// A. Fetch from 'colleges' table
$colleges_result = $conn->query("SELECT college_id, college_name FROM colleges");
$colleges = $colleges_result->fetch_all(MYSQLI_ASSOC);
$college_map = [];
foreach ($colleges as $college) {
    $college_map[$college['college_id']] = $college['college_name'];
}

function getCollegeName($college_id, $college_map) {
    if (empty($college_id) || $college_id == 0) {
        return '<em>N/A</em>';
    }
    return isset($college_map[$college_id]) ? htmlspecialchars($college_map[$college_id]) : '<em>Unknown</em>';
}

// B. Fetch all 'Submitted' results from the 'categories' table
$pending_results = [];
// ### FIX 1 of 4: Changed c.event_type to c.category_type ###
$sql_pending = "SELECT 
            c.category_id, c.category_name, c.category_type,
            c.gold_winner_college_id, c.gold_count,
            c.silver_winner_college_id, c.silver_count,
            c.bronze_winner_college_id, c.bronze_count,
            c.updated_at AS submission_date, 
            ge.event_name, g.game_name, u.username AS submitted_by
        FROM categories c
        JOIN game_events ge ON c.event_id = ge.event_id
        JOIN games g ON ge.game_id = g.game_id
        LEFT JOIN event_manager_assignments ema ON c.event_id = ema.event_id
        LEFT JOIN users u ON ema.user_id = u.id
        WHERE c.status = 'Results Submitted'
        GROUP BY c.category_id
        ORDER BY c.updated_at DESC";

$result_pending = $conn->query($sql_pending);
if ($result_pending) {
    $pending_results = $result_pending->fetch_all(MYSQLI_ASSOC);
} else {
    $alert_message = "ERROR: Could not fetch pending results. " . $conn->error;
    $alert_type = 'danger';
}

// C. ### NEW: Fetch all 'Approved' results from the 'categories' table ###
$approved_results = [];
// ### FIX 2 of 4: Changed c.event_type to c.category_type ###
$sql_approved = "SELECT 
            c.category_id, c.category_name, c.category_type,
            c.gold_winner_college_id, c.gold_count,
            c.silver_winner_college_id, c.silver_count,
            c.bronze_winner_college_id, c.bronze_count,
            c.approved_at, 
            ge.event_name, g.game_name, u.username AS approved_by
        FROM categories c
        JOIN game_events ge ON c.event_id = ge.event_id
        JOIN games g ON ge.game_id = g.game_id
        LEFT JOIN users u ON c.approved_by_user_id = u.id
        WHERE c.status = 'Results Approved'
        ORDER BY c.approved_at DESC";

$result_approved = $conn->query($sql_approved);
if ($result_approved) {
    $approved_results = $result_approved->fetch_all(MYSQLI_ASSOC);
} else {
    $alert_message = "ERROR: Could not fetch approved results. " . $conn->error;
    $alert_type = 'danger';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Results - SD Dashboard</title>
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
            --bs-purple: #6f42c1;
            --bs-info: #0dcaf0;
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
        .medal-icon { font-size: 1.2em; }
        .gold { color: #FFD700; }
        .silver { color: #C0C0C0; }
        .bronze { color: #CD7F32; }
        .navbar-profile-icon { width: 36px; height: 36px; font-size: 36px; text-align: center; line-height: 1; border-radius: 50%; margin-right: 10px; color: rgba(255,255,255,0.8); }
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; transition: var(--transition); }
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
        .user-dropdown .dropdown-toggle .user-name { font-weight: 600; font-size: 0.95rem; }
        .winner-count { font-weight: 600; color: #333; }
        
        .badge.bg-purple { background-color: var(--bs-purple) !important; color: white; }
        .badge.bg-info { background-color: var(--bs-info) !important; color: #000; }

        /* ### NEW: Styles for the tabs ### */
        .card-header-tabs {
            margin: -0.5rem -1rem; /* Adjust to align with card padding */
        }
        .nav-tabs .nav-link {
            border: none;
            border-bottom: 3px solid transparent;
            font-weight: 600;
            color: #6c757d;
        }
        .nav-tabs .nav-link.active {
            border-bottom-color: #0d6efd;
            color: #0d6efd;
            background: none;
        }
        
    </style>
</head>
<body>

    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items: center justify-content-between">
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

    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Administrator'): ?>
        <div class="sidebar" id="sidebar">
            <ul class="nav flex-column sidebar-nav">
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'admin_dashboard.php') echo 'active'; ?>" href="../admin_dashboard.php">
                        <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($is_event_page) echo 'active'; ?>" data-bs-toggle="collapse" href="#eventsCollapse" role="button" aria-expanded="<?php echo $is_event_page ? 'true' : 'false'; ?>">
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
                    <a class="nav-link <?php if ($is_management_page) echo 'active'; ?>" data-bs-toggle="collapse" href="#teamsCollapse" role="button" aria-expanded="<?php echo $is_management_page ? 'true' : 'false'; ?>">
                        <i class="fas fa-users me-2"></i> <span>Manage Colleges</span> <i class="fas fa-chevron-down ms-auto sidebar-chevron"></i>
                    </a>
                    <div class="collapse <?php if ($is_management_page) echo 'show'; ?>" id="teamsCollapse">
                        <ul class="sub-menu">
                            <li class="text-muted" style="padding: 10px 25px 5px 60px;">Management</li>
                            <li><a class="nav-link <?php if ($current_page == 'colleges.php') echo 'active'; ?>" href="colleges.php"><span>Manage Colleges</span></a></li>
                            <li><a class="nav-link <?php if ($current_page == 'events.php') echo 'active'; ?>" href="events.php"><span>Manage Events (L1-L3)</span></a></li>
                            <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'Manage_Matches.php') echo 'active'; ?>" href="Manage_Matches.php">
                                <span>Manage Matches</span>
                            </a>
                        </li>
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
            
            <h1 class="section-title mb-4">Approve Medal Results</h1>
            
            <?php if (!empty($alert_message)): ?>
                <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($alert_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="resultsTab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab" aria-controls="pending" aria-selected="true">
                                <i class="fas fa-hourglass-half me-1"></i> Pending Submissions
                                <span class="badge bg-warning text-dark ms-1"><?php echo count($pending_results); ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="approved-tab" data-bs-toggle="tab" data-bs-target="#approved" type="button" role="tab" aria-controls="approved" aria-selected="false">
                                <i class="fas fa-check-circle me-1"></i> Approved Results
                                <span class="badge bg-success ms-1"><?php echo count($approved_results); ?></span>
                            </button>
                        </li>
                    </ul>
                </div>
                
                <div class="tab-content" id="resultsTabContent">
                    
                    <div class="tab-pane fade show active" id="pending" role="tabpanel" aria-labelledby="pending-tab">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="min-width: 250px;">Game / Event / Category</th>
                                            <th>Type</th>
                                            <th style="min-width: 280px;">Submitted Winners (Count)</th>
                                            <th>Submission Details</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($pending_results)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted p-4">
                                                    No pending results found.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                        
                                        <?php foreach ($pending_results as $row): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($row['game_name']); ?></div>
                                                    <div class="text-muted small"><?php echo htmlspecialchars($row['event_name']); ?></div>
                                                    <strong><?php echo htmlspecialchars($row['category_name']); ?></strong>
                                                </td>
                                                
                                                <td>
                                                    <?php if (strtolower($row['category_type']) == 'match'): ?>
                                                        <span class="badge bg-purple"><i class="fas fa-trophy me-1"></i> Match</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-info text-dark"><i class="fas fa-medal me-1"></i> Medal</span>
                                                    <?php endif; ?>
                                                </td>
                                                
                                                <td>
                                                    <div>
                                                        <i class="fas fa-medal medal-icon gold"></i> 
                                                        <?php echo getCollegeName($row['gold_winner_college_id'], $college_map); ?>
                                                        ( <span class="winner-count"><?php echo $row['gold_count']; ?></span> )
                                                    </div>
                                                    <div>
                                                        <i class="fas fa-medal medal-icon silver"></i> 
                                                        <?php echo getCollegeName($row['silver_winner_college_id'], $college_map); ?>
                                                        ( <span class="winner-count"><?php echo $row['silver_count']; ?></span> )
                                                    </div>
                                                    <div>
                                                        <i class="fas fa-medal medal-icon bronze"></i> 
                                                        <?php echo getCollegeName($row['bronze_winner_college_id'], $college_map); ?>
                                                        ( <span class="winner-count"><?php echo $row['bronze_count']; ?></span> )
                                                    </div>
                                                </td>
                                                
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($row['submitted_by'] ?? 'N/A'); ?></div>
                                                    <small class="text-muted"><?php echo date('M d, Y h:i A', strtotime($row['submission_date'])); ?></small>
                                                </td>
                                                
                                                <td class="text-end">
                                                    <div style="display: flex; gap: 5px; justify-content: flex-end;">
                                                        <form method="POST" action="results.php" style="display: inline-block;">
                                                            <input type="hidden" name="action" value="approve_result">
                                                            <input type="hidden" name="category_id" value="<?php echo $row['category_id']; ?>">
                                                            <input type="hidden" name="event_name_for_log" value="<?php echo htmlspecialchars($row['event_name']); ?>">
                                                            <button type="submit" class="btn btn-success btn-sm" title="Approve">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                        </form>
                                                        <button type="button" class="btn btn-danger btn-sm" title="Reject"
                                                                data-bs-toggle="modal" data-bs-target="#rejectModal"
                                                                data-category-id="<?php echo $row['category_id']; ?>"
                                                                data-category-name="<?php echo htmlspecialchars($row['category_name']); ?>"
                                                                data-event-name="<?php echo htmlspecialchars($row['event_name']); ?>">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="tab-pane fade" id="approved" role="tabpanel" aria-labelledby="approved-tab">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="min-width: 250px;">Game / Event / Category</th>
                                        <th>Type</th>
                                        <th style="min-width: 280px;">Winners (Gold, Silver, Bronze)</th>
                                        <th>Approval Details</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($approved_results)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted p-4">
                                                No results have been approved yet.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    
                                    <?php foreach ($approved_results as $row): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($row['game_name']); ?></div>
                                                <div class="text-muted small"><?php echo htmlspecialchars($row['event_name']); ?></div>
                                                <strong><?php echo htmlspecialchars($row['category_name']); ?></strong>
                                            </td>
                                            
                                            <td>
                                                <?php if (strtolower($row['category_type']) == 'match'): ?>
                                                    <span class="badge bg-purple"><i class="fas fa-trophy me-1"></i> Match</span>
                                                <?php else: ?>
                                                    <span class="badge bg-info text-dark"><i class="fas fa-medal me-1"></i> Medal</span>
                                                <?php endif; ?>
                                            </td>
                                            
                                            <td>
                                                <div>
                                                    <i class="fas fa-medal medal-icon gold"></i> 
                                                    <?php echo getCollegeName($row['gold_winner_college_id'], $college_map); ?>
                                                    (<?php echo $row['gold_count']; ?>)
                                                </div>
                                                <div>
                                                    <i class="fas fa-medal medal-icon silver"></i> 
                                                    <?php echo getCollegeName($row['silver_winner_college_id'], $college_map); ?>
                                                    (<?php echo $row['silver_count']; ?>)
                                                </div>
                                                <div>
                                                    <i class="fas fa-medal medal-icon bronze"></i> 
                                                    <?php echo getCollegeName($row['bronze_winner_college_id'], $college_map); ?>
                                                    (<?php echo $row['bronze_count']; ?>)
                                                </div>
                                            </td>
                                            
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($row['approved_by'] ?? 'N/A'); ?></div>
                                                <small class="text-muted"><?php echo date('M d, Y h:i A', strtotime($row['approved_at'])); ?></small>
                                            </td>
                                            
                                            <td class="text-end">
                                                <button type="button" class="btn btn-danger btn-sm" title="Revoke Approval"
                                                        data-bs-toggle="modal" data-bs-target="#revokeModal"
                                                        data-category-id="<?php echo $row['category_id']; ?>"
                                                        data-category-name="<?php echo htmlspecialchars($row['category_name']); ?>"
                                                        data-event-name="<?php echo htmlspecialchars($row['event_name']); ?>">
                                                    <i class="fas fa-undo me-1"></i> Revoke
                                                </button>
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

        </div>
    </div> 
    
    <footer class="bg-dark text-white py-4" id="footer">
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small>
        </div>
    </footer>

    <div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="results.php">
                    <input type="hidden" name="action" value="reject_result">
                    <input type="hidden" name="category_id" id="reject_category_id">
                    <input type="hidden" name="event_name_for_log" id="reject_event_name">
                    
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="rejectModalLabel">Confirm Rejection</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to reject the results for: <br><strong id="reject_category_name"></strong>?</p>
                        <div class="mb-3">
                            <label for="rejection_reason" class="form-label">Reason (Optional):</label>
                            <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" placeholder="e.g., Incorrect winner submitted..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Yes, Reject Results</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="revokeModal" tabindex="-1" aria-labelledby="revokeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="results.php">
                    <input type="hidden" name="action" value="revoke_result">
                    <input type="hidden" name="category_id" id="revoke_category_id">
                    <input type="hidden" name="event_name_for_log" id="revoke_event_name">
                    
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="revokeModalLabel">Confirm Approval Revocation</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to **revoke** the approval for: <br><strong id="revoke_category_name"></strong>?</p>
                        <p>This action will **unpublish** the results from the medal tally and send them back to the Event Manager as 'Rejected' for correction.</p>
                        <div class="mb-3">
                            <label for="revoke_reason" class="form-label">Reason for Revocation (Optional):</label>
                            <textarea class="form-control" id="revoke_reason" name="revoke_reason" rows="3" placeholder="e.g., Mistake in medal count..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Yes, Revoke Approval</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // JS for Reject Modal
            var rejectModal = document.getElementById('rejectModal');
            if(rejectModal) {
                rejectModal.addEventListener('show.bs.modal', function (event) {
                    var button = event.relatedTarget;
                    var categoryId = button.getAttribute('data-category-id');
                    var categoryName = button.getAttribute('data-category-name');
                    var eventName = button.getAttribute('data-event-name');
                    
                    rejectModal.querySelector('#reject_category_id').value = categoryId;
                    rejectModal.querySelector('#reject_category_name').textContent = categoryName;
                    rejectModal.querySelector('#reject_event_name').value = eventName;
                });
            }

            // ### NEW: JS for Revoke Modal ###
            var revokeModal = document.getElementById('revokeModal');
            if(revokeModal) {
                revokeModal.addEventListener('show.bs.modal', function (event) {
                    var button = event.relatedTarget;
                    var categoryId = button.getAttribute('data-category-id');
                    var categoryName = button.getAttribute('data-category-name');
                    var eventName = button.getAttribute('data-event-name');
                    
                    revokeModal.querySelector('#revoke_category_id').value = categoryId;
                    revokeModal.querySelector('#revoke_category_name').textContent = categoryName;
                    revokeModal.querySelector('#revoke_event_name').value = eventName;
                });
            }

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
            const sidebar = document.getElementById('sidebar'); // Make sure sidebar is defined

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