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

// L1 - GAMES
if (isset($_POST['add_game'])) {
    $game_name = $_POST['game_name'];
    $stmt = $conn->prepare("INSERT INTO games (game_name) VALUES (?)");
    $stmt->bind_param("s", $game_name);
    $stmt->execute();
    log_activity($conn, "Game (L1) '{$game_name}' was created.");
    header("Location: events.php?tab=games"); exit();
}

// L2 - EVENTS
if (isset($_POST['add_event'])) {
    $game_id = (int)$_POST['game_id'];
    $event_name = $_POST['event_name'];
    $stmt = $conn->prepare("INSERT INTO events (game_id, event_name) VALUES (?, ?)");
    $stmt->bind_param("is", $game_id, $event_name);
    $stmt->execute();
    log_activity($conn, "Event (L2) '{$event_name}' was created.");
    header("Location: events.php?tab=events"); exit();
}

// L3 - CATEGORIES
if (isset($_POST['add_category'])) {
    $event_id = (int)$_POST['event_id'];
    $category_name = $_POST['category_name'];
    $stmt = $conn->prepare("INSERT INTO categories (event_id, category_name) VALUES (?, ?)");
    $stmt->bind_param("is", $event_id, $category_name);
    $stmt->execute();
    log_activity($conn, "Category (L3) '{$category_name}' was created.");
    header("Location: events.php?tab=categories"); exit();
}

// MANAGER ASSIGNMENT
if (isset($_POST['assign_manager'])) {
    $event_id = (int)$_POST['event_id'];
    $user_id = (int)$_POST['user_id'];

    // Delete any existing assignment for this event first (REPLACE)
    $stmt_del = $conn->prepare("DELETE FROM event_manager_assignments WHERE event_id = ?");
    $stmt_del->bind_param("i", $event_id);
    $stmt_del->execute();
    $stmt_del->close();

    if ($user_id > 0) {
        // Insert the new assignment
        $stmt_ins = $conn->prepare("INSERT INTO event_manager_assignments (event_id, user_id) VALUES (?, ?)");
        $stmt_ins->bind_param("ii", $event_id, $user_id);
        $stmt_ins->execute();
        $stmt_ins->close();
        log_activity($conn, "Manager (ID: {$user_id}) was assigned to Event (ID: {$event_id}).");
    } else {
        log_activity($conn, "Manager was unassigned from Event (ID: {$event_id}).");
    }
    
    $_SESSION['message'] = "Manager assignment updated successfully.";
    $_SESSION['message_type'] = "success";
    header("Location: events.php?tab=events"); exit();
}


// --- FETCH DATA (READ) ---
$games = $conn->query("SELECT * FROM games ORDER BY game_name")->fetch_all(MYSQLI_ASSOC);
$events = $conn->query("SELECT e.*, g.game_name FROM events e JOIN games g ON e.game_id = g.game_id ORDER BY g.game_name, e.event_name")->fetch_all(MYSQLI_ASSOC);
$categories = $conn->query("SELECT c.*, e.event_name, g.game_name FROM categories c JOIN events e ON c.event_id = e.event_id JOIN games g ON e.game_id = g.game_id ORDER BY g.game_name, e.event_name, c.category_name")->fetch_all(MYSQLI_ASSOC);

// Fetch L2 Events with their assigned managers
$events_with_managers = [];
$sql_events_managers = "SELECT e.*, g.game_name, u.full_name as manager_name, ema.user_id
                        FROM events e
                        JOIN games g ON e.game_id = g.game_id
                        LEFT JOIN event_manager_assignments ema ON e.event_id = ema.event_id
                        LEFT JOIN users u ON ema.user_id = u.id
                        ORDER BY g.game_name, e.event_name";
$result_events_managers = $conn->query($sql_events_managers);
if($result_events_managers) {
    $events_with_managers = $result_events_managers->fetch_all(MYSQLI_ASSOC);
}

// Fetch available Event Managers (assumes users.id)
$event_managers = $conn->query("SELECT id, full_name FROM users WHERE role = 'EventManager' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

// Check for session messages
$message = null; // <-- ADD THIS LINE
$message_type = 'info'; // <-- ADD THIS LINE

if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Events - SD Panel</title>
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
            <h1 class="section-title mb-4">Manage Events & Assignments</h1>

            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <ul class="nav nav-tabs" id="eventTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="games-tab" data-bs-toggle="tab" data-bs-target="#games" type="button" role="tab">Games (Level 1)</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="events-tab" data-bs-toggle="tab" data-bs-target="#events" type="button" role="tab">Game Events & Assignments (Level 2)</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="categories-tab" data-bs-toggle="tab" data-bs-target="#categories" type="button" role="tab">Categories (Level 3)</button>
                </li>
            </ul>

            <div class="tab-content" id="eventTabsContent">
                
                <div class="tab-pane fade show active" id="games" role="tabpanel">
                    <div class="card mt-3">
                        <div class="card-header"><h5 class="mb-0">Add New Game (L1)</h5></div>
                        <div class="card-body">
                            <form action="events.php" method="POST" class="row g-3">
                                <input type="hidden" name="add_game">
                                <div class="col-md-8">
                                    <label for="game_name" class="form-label">Game Name (e.g., Athletics)</label>
                                    <input type="text" class="form-control" id="game_name" name="game_name" required>
                                </div>
                                <div class="col-md-4 align-self-end">
                                    <button type="submit" class="btn btn-primary w-100">Add Game</button>
                                </div>
                            </form>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead><tr><th>Game Name</th><th>Actions</th></tr></thead>
                                <tbody>
                                    <?php foreach ($games as $game): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($game['game_name']) ?></td>
                                        <td></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="events" role="tabpanel">
                    <div class="card mt-3">
                        <div class="card-header"><h5 class="mb-0">Add New Event (L2)</h5></div>
                        <div class="card-body">
                            <form action="events.php" method="POST" class="row g-3">
                                <input type="hidden" name="add_event">
                                <div class="col-md-6">
                                    <label for="game_id" class="form-label">Parent Game (L1)</label>
                                    <select class="form-select" id="game_id" name="game_id" required>
                                        <option value="" disabled selected>-- Select Game --</option>
                                        <?php foreach ($games as $game): ?>
                                        <option value="<?= $game['game_id'] ?>"><?= htmlspecialchars($game['game_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="event_name" class="form-label">Event Name (e.g., Runs & Jumps)</label>
                                    <input type="text" class="form-control" id="event_name" name="event_name" required>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">Add Event</button>
                                </div>
                            </form>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead><tr><th>Game (L1)</th><th>Event (L2)</th><th>Assigned Manager</th><th>Actions</th></tr></thead>
                                <tbody>
                                    <?php foreach ($events_with_managers as $event): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($event['game_name']) ?></td>
                                        <td><?= htmlspecialchars($event['event_name']) ?></td>
                                        <td>
                                            <?php if($event['manager_name']): ?>
                                                <span class="badge bg-success"><?= htmlspecialchars($event['manager_name']) ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary assign-btn"
                                                data-bs-toggle="modal" data-bs-target="#assignManagerModal"
                                                data-event-id="<?= $event['event_id'] ?>"
                                                data-event-name="<?= htmlspecialchars($event['event_name']) ?>"
                                                data-user-id="<?= $event['user_id'] ?? '' ?>">
                                                <i class="fas fa-user-plus"></i> Assign
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="categories" role="tabpanel">
                    <div class="card mt-3">
                        <div class="card-header"><h5 class="mb-0">Add New Category (L3)</h5></div>
                        <div class="card-body">
                            <form action="events.php" method="POST" class="row g-3">
                                <input type="hidden" name="add_category">
                                <div class="col-md-6">
                                    <label for="event_id" class="form-label">Parent Event (L2)</label>
                                    <select class="form-select" id="event_id" name="event_id" required>
                                        <option value="" disabled selected>-- Select Event --</option>
                                        <?php foreach ($events as $event): ?>
                                        <option value="<?= $event['event_id'] ?>"><?= htmlspecialchars($event['game_name']) ?> - <?= htmlspecialchars($event['event_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="category_name" class="form-label">Category Name (e.g., 100m Dash)</label>
                                    <input type="text" class="form-control" id="category_name" name="category_name" required>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">Add Category</button>
                                </div>
                            </form>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead><tr><th>Game (L1)</th><th>Event (L2)</th><th>Category (L3)</th><th>Actions</th></tr></thead>
                                <tbody>
                                    <?php foreach ($categories as $category): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($category['game_name']) ?></td>
                                        <td><?= htmlspecialchars($category['event_name']) ?></td>
                                        <td><?= htmlspecialchars($category['category_name']) ?></td>
                                        <td></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="assignManagerModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="assignManagerModalLabel">Assign Manager</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="assign_manager">
                            <input type="hidden" name="event_id" id="assign_event_id">
                            <p>Assigning manager for event: <strong id="assign_event_name"></strong></p>
                            <div class="mb-3">
                                <label for="user_id" class="form-label">Event Manager</label>
                                <select class="form-select" id="assign_user_id" name="user_id">
                                    <option value="0">-- Unassign --</option>
                                    <?php foreach ($event_managers as $manager): ?>
                                    <option value="<?= $manager['id'] ?>"><?= htmlspecialchars($manager['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Save Assignment</button>
                        </div>
                    </form>
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
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // JS for Assign Manager Modal
        const assignModal = document.getElementById('assignManagerModal');
        assignModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('assign_event_id').value = button.dataset.eventId;
            document.getElementById('assign_event_name').textContent = button.dataset.eventName;
            document.getElementById('assign_user_id').value = button.dataset.userId || '0';
        });

        // JS to keep the correct tab active after page reload
        const urlParams = new URLSearchParams(window.location.search);
        const tab = urlParams.get('tab');
        if (tab) {
            const tabElement = document.querySelector('#' + tab + '-tab');
            if (tabElement) {
                new bootstrap.Tab(tabElement).show();
            }
        }
    });
    </script>
</body>
</html>