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
// --- Logic for Sidebar Accordions ---
$event_pages = ['Manage_Games.php', 'Manage_Game_Events.php', 'Manage_Categories.php'];
$is_event_page = in_array($current_page, $event_pages);

$management_pages = ['teams.php', 'events.php', 'results.php', 'reports.php'];
$is_management_page = in_array($current_page, $management_pages);
$user_id = $_SESSION['user_id'];

$alert_message = '';
$alert_type = 'success';

// 2. FORM HANDLING (APPROVE / REJECT)
// THIS LOGIC IS ALREADY PRESENT AND CORRECT. NO CHANGES NEEDED.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Use a transaction for safety
    $conn->begin_transaction();
    try {
        if ($_POST['action'] === 'approve_result') {
            $result_id = (int)$_POST['result_id'];
            $category_id = (int)$_POST['category_id'];

            // 1. Get the winner IDs from the pending result
            $stmt_get = $conn->prepare("SELECT winner_gold_team_id, winner_silver_team_id, winner_bronze_team_id FROM results WHERE result_id = ? AND status = 'Pending'");
            $stmt_get->bind_param("i", $result_id);
            $stmt_get->execute();
            $result_data = $stmt_get->get_result()->fetch_assoc();
            
            if ($result_data) {
                $gold = $result_data['winner_gold_team_id'];
                $silver = $result_data['winner_silver_team_id'];
                $bronze = $result_data['winner_bronze_team_id'];

                // 2. Update the 'results' table
                $stmt_res = $conn->prepare("UPDATE results SET status = 'Approved', approved_by_user_id = ?, approved_at = NOW() WHERE result_id = ?");
                $stmt_res->bind_param("ii", $user_id, $result_id);
                $stmt_res->execute();

                // 3. Update the 'Categories' table with the final winners and status
                $stmt_cat = $conn->prepare("UPDATE Categories SET status = 'Results Approved', gold_winner_id = ?, silver_winner_id = ?, bronze_winner_id = ? WHERE category_id = ?");
                $stmt_cat->bind_param("iiii", $gold, $silver, $bronze, $category_id);
                $stmt_cat->execute();
                
                $conn->commit();
                $alert_message = "SUCCESS: The results have been approved and published!";
            } else {
                throw new Exception("Could not find the pending result. It might have already been processed.");
            }

        } elseif ($_POST['action'] === 'reject_result') {
            $result_id = (int)$_POST['result_id'];
            $category_id = (int)$_POST['category_id'];
            $rejection_reason = trim($_POST['rejection_reason']) ?: 'No reason provided.';

            // 1. Update the 'results' table
            $stmt_res = $conn->prepare("UPDATE results SET status = 'Rejected', approved_by_user_id = ?, approved_at = NOW(), notes = ? WHERE result_id = ?");
            $stmt_res->bind_param("isi", $user_id, $rejection_reason, $result_id);
            $stmt_res->execute();

            // 2. Update the 'Categories' table status so the manager knows
            $stmt_cat = $conn->prepare("UPDATE Categories SET status = 'Results Rejected' WHERE category_id = ?");
            $stmt_cat->bind_param("i", $category_id);
            $stmt_cat->execute();
            
            $conn->commit();
            $alert_message = "SUCCESS: The results have been rejected and sent back to the Event Manager.";
        }
    } catch (Exception $e) {
        $conn->rollback();
        $alert_message = "ERROR: " . $e->getMessage();
        $alert_type = 'danger';
        error_log($e->getMessage());
    }
}

// 3. FETCH DATA FOR DISPLAY
// (Your PHP logic is unchanged)
// A. Fetch all Teams (to show names instead of IDs)
$teams_result = $conn->query("SELECT team_id, team_name FROM teams");
$teams = $teams_result->fetch_all(MYSQLI_ASSOC);
$team_map = [];
foreach ($teams as $team) {
    $team_map[$team['team_id']] = $team['team_name'];
}
// Helper function to get team name safely
function getTeamName($team_id, $team_map) {
    return isset($team_map[$team_id]) ? htmlspecialchars($team_map[$team_id]) : '<em>N/A</em>';
}

// B. Fetch all PENDING results
$pending_results = [];
$sql = "SELECT 
            r.result_id,
            r.category_id,
            r.winner_gold_team_id,
            r.winner_silver_team_id,
            r.winner_bronze_team_id,
            r.last_updated,
            c.category_name,
            ge.event_name,
            g.game_name,
            u.username AS submitted_by
        FROM results r
        JOIN categories c ON r.category_id = c.category_id
        JOIN game_events ge ON c.event_id = ge.event_id
        JOIN games g ON ge.game_id = g.game_id
        JOIN users u ON r.submitted_by_user_id = u.id
        WHERE r.status = 'Pending'
        ORDER BY r.last_updated DESC";

$result = $conn->query($sql);
if ($result) {
    $pending_results = $result->fetch_all(MYSQLI_ASSOC);
} else {
    $alert_message = "ERROR: Could not fetch pending results. " . $conn->error;
    $alert_type = 'danger';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- ... Your head content is unchanged ... -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Results - SD Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Your original styles are unchanged */
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
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        .card { border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        .medal-icon { font-size: 1.2em; }
        .gold { color: #FFD700; }
        .silver { color: #C0C0C0; }
        .bronze { color: #CD7F32; }
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
    </style>
</head>
<body>

    <nav class="navbar navbar-dark bg-dark">
        <!-- ... Your Unchanged Navbar ... -->
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
            
            <h1 class="section-title mb-4">Approve Medal Results</h1>
            
            <?php if (!empty($alert_message)): ?>
                <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($alert_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Pending Submissions</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Game / Event / Category</th>
                                    
                                    <!-- FIX 1: Added inline style to prevent wrapping -->
                                    <th style="white-space: nowrap;"><i class="fas fa-medal medal-icon gold"></i> Gold</th>
                                    <th style="white-space: nowrap;"><i class="fas fa-medal medal-icon silver"></i> Silver</th>
                                    <th style="white-space: nowrap;"><i class="fas fa-medal medal-icon bronze"></i> Bronze</th>
                                    
                                    <th>Submitted By</th>
                                    <th>Date</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pending_results)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted p-4">
                                            No pending results found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                
                                <?php foreach ($pending_results as $row): ?>
                                    <tr>
                                        <td>
                                            <!-- Fixed typo class_name -> class -->
                                            <div class="fw-bold"><?php echo htmlspecialchars($row['game_name']); ?></div>
                                            <div class="text-muted small"><?php echo htmlspecialchars($row['event_name']); ?></div>
                                            <strong><?php echo htmlspecialchars($row['category_name']); ?></strong>
                                        </td>
                                        <td><?php echo getTeamName($row['winner_gold_team_id'], $team_map); ?></td>
                                        <td><?php echo getTeamName($row['winner_silver_team_id'], $team_map); ?></td>
                                        <td><?php echo getTeamName($row['winner_bronze_team_id'], $team_map); ?></td>
                                        <td><?php echo htmlspecialchars($row['submitted_by']); ?></td>
                                        <td><?php echo date('M d, Y h:i A', strtotime($row['last_updated'])); ?></td>
                                        
                                        <!-- FIX 2: Wrapped buttons in a flex div -->
                                        <td class="text-end">
                                            <div style="display: flex; gap: 5px; justify-content: flex-end;">
                                                <form method="POST" action="results.php">
                                                    <input type="hidden" name="action" value="approve_result">
                                                    <input type="hidden" name="result_id" value="<?php echo $row['result_id']; ?>">
                                                    <input type="hidden" name="category_id" value="<?php echo $row['category_id']; ?>">
                                                    <button type="submit" class="btn btn-success btn-sm" title="Approve">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </form>
                                                <button type="button" class="btn btn-danger btn-sm" title="Reject"
                                                        data-bs-toggle="modal" data-bs-target="#rejectModal"
                                                        data-result-id="<?php echo $row['result_id']; ?>"
                                                        data-category-id="<?php echo $row['category_id']; ?>"
                                                        data-category-name="<?php echo htmlspecialchars($row['category_name']); ?>">
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

        </div>
    </div> 
    
    <footer class="bg-dark text-white py-4" style="margin-left: var(--sidebar-width);">
        <!-- ... Your Unchanged Footer ... -->
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
            <small class="text-muted">Developed by Tsunayoshi Sawada</small>
        </div>
    </footer>

    <div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
        <!-- ... Your Unchanged Modal ... -->
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="results.php">
                    <input type="hidden" name="action" value="reject_result">
                    <input type="hidden" name="result_id" id="reject_result_id">
                    <input type="hidden" name="category_id" id="reject_category_id">
                    
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="rejectModalLabel">Confirm Rejection</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to reject the results for: <br><strong id="reject_category_name"></strong>?</p>
                        <div class="mb-3">
                            <label for="rejection_reason" class="form-label">Reason (Optional):</label>
                            <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" placeholder="e.g., Incorrect winner submitted for Silver..."></textarea>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ... Your Unchanged Modal JS ...
        document.addEventListener('DOMContentLoaded', function () {
            var rejectModal = document.getElementById('rejectModal');
            rejectModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                
                var resultId = button.getAttribute('data-result-id');
                var categoryId = button.getAttribute('data-category-id');
                var categoryName = button.getAttribute('data-category-name');
                
                var modalResultId = rejectModal.querySelector('#reject_result_id');
                var modalCategoryId = rejectModal.querySelector('#reject_category_id');
                var modalCategoryName = rejectModal.querySelector('#reject_category_name');
                
                modalResultId.value = resultId;
                modalCategoryId.value = categoryId;
                modalCategoryName.textContent = categoryName;
            });
        });
    </script>
</body>
</html>

