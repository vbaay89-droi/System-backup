<?php
session_start();
require_once '../config.php'; 

// 1. SECURITY & ACCESS CONTROL
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Sports Director') {
    header('Location: ../login.php'); 
    exit();
}

// Ensure user_id is set
if (!isset($_SESSION['user_id'])) {
    die("Session error: User ID is not set.");
}

$current_user_id = $_SESSION['user_id'];

// --- FETCH NAME LOGIC ---
$stmt_name = $conn->prepare("SELECT full_name, username FROM users WHERE id = ?");
$stmt_name->bind_param("i", $current_user_id);
$stmt_name->execute();
$result_name = $stmt_name->get_result();
$user_data = $result_name->fetch_assoc();
$stmt_name->close();

$name = !empty($user_data['full_name']) ? $user_data['full_name'] : ($user_data['username'] ?? 'Sports Director');
$current_page = basename($_SERVER['PHP_SELF']);

// --- PHP ACTIONS (Create/Update/Delete/Assign) ---

// L1 - GAMES (CREATE)
if (isset($_POST['add_game'])) {
    $game_name = $_POST['game_name'];
    $stmt = $conn->prepare("INSERT INTO games (game_name) VALUES (?)");
    $stmt->bind_param("s", $game_name);
    $stmt->execute();
    $_SESSION['message'] = "Game added successfully."; $_SESSION['message_type'] = "success"; header("Location: events.php?tab=games"); exit();
}
// L1 - GAMES (UPDATE)
if (isset($_POST['update_game'])) {
    $game_id = (int)$_POST['game_id']; $game_name = $_POST['game_name'];
    $stmt = $conn->prepare("UPDATE games SET game_name = ? WHERE game_id = ?");
    $stmt->bind_param("si", $game_name, $game_id);
    $stmt->execute();
    $_SESSION['message'] = "Game updated successfully."; $_SESSION['message_type'] = "success"; header("Location: events.php?tab=games"); exit();
}
// L1 - GAMES (DELETE)
if (isset($_POST['delete_game'])) {
    $game_id = (int)$_POST['game_id'];
    $stmt_check = $conn->prepare("SELECT COUNT(*) FROM game_events WHERE game_id = ?");
    $stmt_check->bind_param("i", $game_id); $stmt_check->execute();
    $count = 0; $stmt_check->bind_result($count); $stmt_check->fetch(); $stmt_check->close();
    if ($count > 0) { $_SESSION['message'] = "Cannot delete game. It has linked events."; $_SESSION['message_type'] = "danger"; } 
    else {
        $stmt = $conn->prepare("DELETE FROM games WHERE game_id = ?"); $stmt->bind_param("i", $game_id); $stmt->execute();
        $_SESSION['message'] = "Game deleted successfully."; $_SESSION['message_type'] = "success";
    }
    header("Location: events.php?tab=games"); exit();
}

// L2 - EVENTS (CREATE)
if (isset($_POST['add_event'])) {
    $game_id = (int)$_POST['game_id']; $event_name = trim($_POST['event_name']); // We default everything to 'Standard' since we removed the toggle
$structure_db_value = 'Standard';
    try {
        $stmt = $conn->prepare("INSERT INTO game_events (game_id, event_name, event_structure) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $game_id, $event_name, $structure_db_value); $stmt->execute(); $stmt->close();
        $msg_extra = ($structure_type === 'single') ? " (Single Mode)" : "";
        $_SESSION['message'] = "Event added successfully." . $msg_extra; $_SESSION['message_type'] = "success";
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1062) { $_SESSION['message'] = "Event '$event_name' already exists."; $_SESSION['message_type'] = "warning"; } 
        else { $_SESSION['message'] = "Error: " . $e->getMessage(); $_SESSION['message_type'] = "danger"; }
    }
    header("Location: events.php?tab=events"); exit();
}
// L2 - EVENTS (UPDATE)
if (isset($_POST['update_event'])) {
    $event_id = (int)$_POST['event_id']; $game_id = (int)$_POST['game_id']; $event_name = $_POST['event_name'];
    $stmt = $conn->prepare("UPDATE game_events SET game_id = ?, event_name = ? WHERE event_id = ?");
    $stmt->bind_param("isi", $game_id, $event_name, $event_id); $stmt->execute();
    $_SESSION['message'] = "Event updated successfully."; $_SESSION['message_type'] = "success"; header("Location: events.php?tab=events"); exit();
}
// L2 - EVENTS (DELETE)
if (isset($_POST['delete_event'])) {
    $event_id = (int)$_POST['event_id'];
    $stmt_check = $conn->prepare("SELECT COUNT(*) FROM categories WHERE event_id = ?");
    $stmt_check->bind_param("i", $event_id); $stmt_check->execute();
    $count = 0; $stmt_check->bind_result($count); $stmt_check->fetch(); $stmt_check->close();
    if ($count > 0) { $_SESSION['message'] = "Cannot delete event. It has linked categories."; $_SESSION['message_type'] = "danger"; } 
    else {
        $conn->query("DELETE FROM event_manager_assignments WHERE event_id = $event_id");
        $conn->query("DELETE FROM game_events WHERE event_id = $event_id");
        $_SESSION['message'] = "Event deleted successfully."; $_SESSION['message_type'] = "success";
    }
    header("Location: events.php?tab=events"); exit();
}

// L3 - CATEGORIES (CREATE)
if (isset($_POST['add_category'])) {
    $event_id = (int)$_POST['event_id']; $category_name = $_POST['category_name'];
    $stmt = $conn->prepare("INSERT INTO categories (event_id, category_name) VALUES (?, ?)");
    $stmt->bind_param("is", $event_id, $category_name); $stmt->execute();
    $_SESSION['message'] = "Category added successfully."; $_SESSION['message_type'] = "success"; header("Location: events.php?tab=categories"); exit();
}
// L3 - CATEGORIES (UPDATE)
if (isset($_POST['update_category'])) {
    $category_id = (int)$_POST['category_id']; $event_id = (int)$_POST['event_id']; $category_name = $_POST['category_name'];
    $stmt = $conn->prepare("UPDATE categories SET event_id = ?, category_name = ? WHERE category_id = ?");
    $stmt->bind_param("isi", $event_id, $category_name, $category_id); $stmt->execute();
    $_SESSION['message'] = "Category updated successfully."; $_SESSION['message_type'] = "success"; header("Location: events.php?tab=categories"); exit();
}
// L3 - CATEGORIES (DELETE)
if (isset($_POST['delete_category'])) {
    $category_id = (int)$_POST['category_id'];
    $stmt = $conn->prepare("DELETE FROM categories WHERE category_id = ?"); $stmt->bind_param("i", $category_id); $stmt->execute();
    $_SESSION['message'] = "Category deleted successfully."; $_SESSION['message_type'] = "success"; header("Location: events.php?tab=categories"); exit();
}
// MANAGER ASSIGNMENT
if (isset($_POST['assign_manager'])) {
    $event_id = (int)$_POST['event_id']; $user_id = (int)$_POST['user_id'];
    $conn->query("DELETE FROM event_manager_assignments WHERE event_id = $event_id");
    if ($user_id > 0) {
        $stmt_ins = $conn->prepare("INSERT INTO event_manager_assignments (event_id, user_id) VALUES (?, ?)");
        $stmt_ins->bind_param("ii", $event_id, $user_id); $stmt_ins->execute();
    }
    $_SESSION['message'] = "Manager assignment updated."; $_SESSION['message_type'] = "success"; header("Location: events.php?tab=events"); exit();
}

// --- FETCH DATA ---
$games = $conn->query("SELECT * FROM games ORDER BY game_name")->fetch_all(MYSQLI_ASSOC);
$events = $conn->query("SELECT e.*, g.game_name FROM game_events e JOIN games g ON e.game_id = g.game_id ORDER BY g.game_name, e.event_name")->fetch_all(MYSQLI_ASSOC);
$categories = $conn->query("SELECT c.*, e.event_name, g.game_name, u.full_name as manager_name FROM categories c JOIN game_events e ON c.event_id = e.event_id JOIN games g ON e.game_id = g.game_id LEFT JOIN event_manager_assignments ema ON e.event_id = ema.event_id LEFT JOIN users u ON ema.user_id = u.id ORDER BY g.game_name, e.event_name, c.category_name")->fetch_all(MYSQLI_ASSOC);
$events_with_managers = $conn->query("SELECT e.*, g.game_name, u.full_name as manager_name, ema.user_id FROM game_events e JOIN games g ON e.game_id = g.game_id LEFT JOIN event_manager_assignments ema ON e.event_id = ema.event_id LEFT JOIN users u ON ema.user_id = u.id ORDER BY g.game_name, e.event_name")->fetch_all(MYSQLI_ASSOC);
$event_managers = $conn->query("SELECT id, full_name FROM users WHERE role = 'Event Manager' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

$message = $_SESSION['message'] ?? null;
$message_type = $_SESSION['message_type'] ?? 'info';
unset($_SESSION['message'], $_SESSION['message_type']);

// Counts
$pending_requests_count = $conn->query("SELECT COUNT(*) FROM users WHERE is_approved = 0")->fetch_row()[0] ?? 0;
$pending_results_count = $conn->query("SELECT COUNT(*) FROM categories WHERE status='Results Submitted'")->fetch_row()[0] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Events - Director Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* --- Unified CSS --- */
        :root { --sidebar-width: 260px; --header-height: 82px; --transition: all 0.3s ease; --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); --bg-light: #F8F9FA; --primary-gradient: linear-gradient(135deg, #2c3e50 0%, #4ca1af 100%); --accent-color: #1abc9c; }
        body { background-color: var(--bg-light); margin: 0; padding: 0; min-height: 100vh; font-family: 'Inter', sans-serif; display: flex; flex-direction: column; }
        .navbar { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.15); padding: 1rem 1.5rem; height: var(--header-height); position: fixed; top: 0; left: 0; right: 0; z-index: 1050; }
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; transition: var(--transition); }
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
        .user-dropdown .dropdown-toggle img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; margin-right: 10px; }
        .sidebar { width: var(--sidebar-width); position: fixed; top: var(--header-height); left: 0; height: calc(100vh - var(--header-height)); background: #2c3e50; color: white; box-shadow: 5px 0 15px rgba(0,0,0,0.2); z-index: 1040; transition: width var(--transition); overflow-y: auto; }
        .sidebar-nav { padding: 20px 0; }
        .sidebar-nav .nav-link { color: rgba(255, 255, 255, 0.7); font-size: 1.05rem; font-weight: 500; padding: 12px 25px; transition: var(--transition); border-left: 5px solid transparent; margin: 2px 0; display: flex; align-items: center; text-decoration: none; }
        .sidebar-nav .nav-link:hover { color: white; background: rgba(255, 255, 255, 0.05); border-left-color: var(--accent-color); }
        .sidebar-nav .nav-link.active { color: white; background: rgba(255, 255, 255, 0.1); border-left-color: #3498db; font-weight: 600; }
        .sidebar-nav .nav-title { padding: 15px 25px 5px; font-size: 0.75rem; font-weight: 700; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px; }
        .main-content { flex: 1 0 auto; padding: 30px; margin-top: var(--header-height); margin-left: var(--sidebar-width); transition: margin-left var(--transition); min-height: calc(100vh - var(--header-height)); }
        footer { flex-shrink: 0; background: #2c3e50 !important; box-shadow: 0 -2px 10px rgba(0,0,0,0.1); padding-left: var(--sidebar-width); transition: padding-left var(--transition); position: relative; z-index: 1041; }
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        .card { border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        .sortable { cursor: pointer; user-select: none; }
        .sortable:hover { background-color: rgba(0,0,0,0.02); }
        @media (max-width: 992px) { .sidebar { left: -260px; } .sidebar.show { left: 0; } .main-content, footer { margin-left: 0; } footer { padding-left: 0; } }
    </style>
</head>
<body>
    
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="sports_director_dashboard.php">
                <img src="../imageslogo.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
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
                <a class="nav-link active" href="events.php">
                    <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events (L1-L3)</span>
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
                <a class="nav-link" href="results.php">
                    <i class="fas fa-check-double me-2"></i> <span>Approve Results</span>
                    <?php if($pending_results_count > 0): ?>
                        <span class="badge bg-warning text-dark ms-auto rounded-pill"><?= $pending_results_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="reports.php">
                    <i class="fas fa-chart-line me-2"></i> <span>Medal Standings</span>
                </a>
            </li>
            
            <li class="nav-item mt-3"><span class="nav-title">Season Management</span></li>
            <li class="nav-item">
                <a class="nav-link" href="../manage_archives.php">
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
                    <button class="nav-link" id="events-tab" data-bs-toggle="tab" data-bs-target="#events" type="button" role="tab">Game Events (Level 2)</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="categories-tab" data-bs-toggle="tab" data-bs-target="#categories" type="button" role="tab">Categories (Level 3)</button>
                </li>
            </ul>

            <div class="tab-content" id="eventTabsContent">
                
                <div class="tab-pane fade show active" id="games" role="tabpanel">
                    <div class="card mt-3">
                        <div class="card-header d-flex justify-content-between align-items-center bg-white py-3">
                            <h5 class="mb-0 fw-bold">Games List</h5>
                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addGameModal">
                                <i class="fas fa-plus me-1"></i> Add New Game
                            </button>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Game Name</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($games as $game): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($game['game_name']) ?></strong></td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary edit-game-btn me-1"
                                                data-bs-toggle="modal" data-bs-target="#editGameModal"
                                                data-game-id="<?= $game['game_id'] ?>"
                                                data-game-name="<?= htmlspecialchars($game['game_name']) ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger delete-game-btn"
                                                data-bs-toggle="modal" data-bs-target="#deleteGameModal"
                                                data-game-id="<?= $game['game_id'] ?>"
                                                data-game-name="<?= htmlspecialchars($game['game_name']) ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="events" role="tabpanel">
                    <div class="card mt-3">
                        <div class="card-header d-flex justify-content-between align-items-center bg-white py-3">
                            <h5 class="mb-0 fw-bold">Game Events (L2)</h5>
                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addEventModal">
                                <i class="fas fa-plus me-1"></i> Add New Event
                            </button>
                        </div>
                        
                        <div class="p-3 border-bottom bg-white">
                             <input type="search" id="searchEvents" class="form-control" placeholder="Search events, games, or managers...">
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th class="sortable" data-sort-dir="asc">Game (L1) <i class="fas fa-sort fa-xs"></i></th>
                                        <th class="sortable" data-sort-dir="asc">Event (L2) <i class="fas fa-sort fa-xs"></i></th>
                                        <th class="sortable" data-sort-dir="asc">Assigned Manager <i class="fas fa-sort fa-xs"></i></th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="eventsTableBody">
                                    <?php foreach ($events_with_managers as $event): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($event['game_name']) ?></td>
                                        <td><strong><?= htmlspecialchars($event['event_name']) ?></strong></td>
                                        <td class="fw-bold">
                                            <?php if($event['manager_name']): ?>
                                                <span class="text-dark"><?= htmlspecialchars($event['manager_name']) ?></span>
                                            <?php else: ?>
                                                <span class="text-secondary opacity-50">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-info assign-btn me-1"
                                                data-bs-toggle="modal" data-bs-target="#assignManagerModal"
                                                data-event-id="<?= $event['event_id'] ?>"
                                                data-event-name="<?= htmlspecialchars($event['event_name']) ?>"
                                                data-user-id="<?= $event['user_id'] ?? '' ?>">
                                                <i class="fas fa-user-plus"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-primary edit-event-btn me-1"
                                                data-bs-toggle="modal" data-bs-target="#editEventModal"
                                                data-event-id="<?= $event['event_id'] ?>"
                                                data-event-name="<?= htmlspecialchars($event['event_name']) ?>"
                                                data-game-id="<?= $event['game_id'] ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger delete-event-btn"
                                                data-bs-toggle="modal" data-bs-target="#deleteEventModal"
                                                data-event-id="<?= $event['event_id'] ?>"
                                                data-event-name="<?= htmlspecialchars($event['event_name']) ?>">
                                                <i class="fas fa-trash"></i>
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
                        <div class="card-header d-flex justify-content-between align-items-center bg-white py-3">
                            <h5 class="mb-0 fw-bold">Specific Categories (L3)</h5>
                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                                <i class="fas fa-plus me-1"></i> Add Category
                            </button>
                        </div>
                        
                        <div class="p-3 border-bottom bg-white">
                             <input type="search" id="searchCategories" class="form-control" placeholder="Search categories...">
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th class="sortable" data-sort-dir="asc">Game (L1) <i class="fas fa-sort fa-xs"></i></th>
                                        <th class="sortable" data-sort-dir="asc">Event (L2) <i class="fas fa-sort fa-xs"></i></th>
                                        <th class="sortable" data-sort-dir="asc">Category (L3) <i class="fas fa-sort fa-xs"></i></th>
                                        <th class="sortable" data-sort-dir="asc">Assigned Manager <i class="fas fa-sort fa-xs"></i></th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="categoriesTableBody">
                                    <?php foreach ($categories as $category): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($category['game_name']) ?></td>
                                        <td><?= htmlspecialchars($category['event_name']) ?></td>
                                        <td>
                                        <?php 
                                            $catName = $category['category_name'];
                                            
                                            // Check if it is Single or Open Division
                                            if ($catName === 'Single Division' || $catName === 'Open Division') {
                                                // Display just the text (I kept <strong> to match the style of other categories)
                                                echo '<strong>Single Division</strong>';
                                            } else {
                                                // Display specific category name
                                                echo '<strong>' . htmlspecialchars($catName) . '</strong>';
                                            }
                                            ?>
                                        </td>
                                        <td class="fw-bold">
                                            <?php if($category['manager_name']): ?>
                                                <span class="text-dark"><?= htmlspecialchars($category['manager_name']) ?></span>
                                            <?php else: ?>
                                                <span class="text-secondary opacity-50">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary edit-category-btn me-1"
                                                data-bs-toggle="modal" data-bs-target="#editCategoryModal"
                                                data-category-id="<?= $category['category_id'] ?>"
                                                data-category-name="<?= htmlspecialchars($category['category_name']) ?>"
                                                data-event-id="<?= $category['event_id'] ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger delete-category-btn"
                                                data-bs-toggle="modal" data-bs-target="#deleteCategoryModal"
                                                data-category-id="<?= $category['category_id'] ?>"
                                                data-category-name="<?= htmlspecialchars($category['category_name']) ?>">
                                                <i class="fas fa-trash"></i>
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

        <div class="modal fade" id="addGameModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Game Category</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="add_game">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Game Name</label>
                                <input type="text" class="form-control" name="game_name" placeholder="e.g. Ball Games, Board Games, Athletics" required>
                                <div class="form-text text-muted">This groups multiple Events together (e.g., all Ball Games).</div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Game</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="addEventModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Sport / Event</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="add_event">
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Select Game Category</label>
                                <select class="form-select" name="game_id" required>
                                    <option value="" disabled selected>-- Select Category --</option>
                                    <?php foreach ($games as $game): ?>
                                    <option value="<?= $game['game_id'] ?>"><?= htmlspecialchars($game['game_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Sport Name</label>
                                <input type="text" class="form-control" name="event_name" placeholder="e.g. Basketball, Chess, 100m Dash" required>
                            </div>
                            
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Create Event</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="addCategoryModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Specific Division</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="add_category">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Select Event</label>
                                <select class="form-select" name="event_id" required>
                                    <option value="" disabled selected>-- Select Event --</option>
                                    <?php foreach ($events as $event): ?>
                                    <option value="<?= $event['event_id'] ?>">
                                        <?= htmlspecialchars($event['game_name']) ?> &raquo; <?= htmlspecialchars($event['event_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Division / Category Name</label>
                                <input type="text" class="form-control" name="category_name" placeholder="e.g. Men's Division, Lightweight" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Category</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="assignManagerModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Assign Event Manager</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="assign_manager">
                            <input type="hidden" name="event_id" id="assign_event_id">
                            <p>Assigning manager for: <strong id="assign_event_name" class="text-primary"></strong></p>
                            <div class="mb-3">
                                <label for="user_id" class="form-label fw-bold">Select Manager</label>
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

        <div class="modal fade" id="editGameModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Game Category</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="update_game">
                            <input type="hidden" name="game_id" id="edit_game_id">
                            <div class="mb-3">
                                <label for="edit_game_name" class="form-label fw-bold">Game Name</label>
                                <input type="text" class="form-control" id="edit_game_name" name="game_name" required>
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

        <div class="modal fade" id="deleteGameModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Delete Game</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="delete_game">
                            <input type="hidden" name="game_id" id="delete_game_id">
                            <p>Are you sure you want to delete <strong id="delete_game_name"></strong>?</p>
                            <p class="text-danger"><small><i class="fas fa-exclamation-triangle"></i> This action cannot be undone. You cannot delete a game that contains events/events.</small></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Delete Game</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="modal fade" id="editEventModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Sport / Event</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="update_event">
                            <input type="hidden" name="event_id" id="edit_event_id">
                            
                            <div class="mb-3">
                                <label for="edit_game_id_select" class="form-label fw-bold">Game Category</label>
                                <select class="form-select" id="edit_game_id_select" name="game_id" required>
                                    <?php foreach ($games as $game): ?>
                                    <option value="<?= $game['game_id'] ?>"><?= htmlspecialchars($game['game_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="edit_event_name" class="form-label fw-bold">Event Name</label>
                                <input type="text" class="form-control" id="edit_event_name" name="event_name" required>
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
        
        <div class="modal fade" id="deleteEventModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Delete Sport</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="delete_event">
                            <input type="hidden" name="event_id" id="delete_event_id">
                            <p>Are you sure you want to delete <strong id="delete_event_name"></strong>?</p>
                            <p class="text-danger"><small><i class="fas fa-exclamation-triangle"></i> This action cannot be undone. You cannot delete a sport that has active divisions/categories.</small></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Delete Sport</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="editCategoryModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Division</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="update_category">
                            <input type="hidden" name="category_id" id="edit_category_id">
                            
                            <div class="mb-3">
                                <label for="edit_event_id_select_cat" class="form-label fw-bold">Belongs to Sport</label>
                                <select class="form-select" id="edit_event_id_select_cat" name="event_id" required>
                                    <option value="" disabled>-- Select Sport --</option>
                                    <?php foreach ($events as $event): ?>
                                    <option value="<?= $event['event_id'] ?>">
                                        <?= htmlspecialchars($event['game_name']) ?> &raquo; <?= htmlspecialchars($event['event_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="edit_category_name" class="form-label fw-bold">Division Name</label>
                                <input type="text" class="form-control" id="edit_category_name" name="category_name" required>
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

        <div class="modal fade" id="deleteCategoryModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Delete Division</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="delete_category">
                            <input type="hidden" name="category_id" id="delete_category_id">
                            <p>Are you sure you want to delete <strong id="delete_category_name"></strong>?</p>
                            <p class="text-danger"><small><i class="fas fa-exclamation-triangle"></i> This action cannot be undone.</small></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Delete Division</button>
                        </div>
                    </form>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        
        // --- JS FEATURES ---
        
        // Feature 1: Search Functionality
        function setupTableSearch(inputId, tableBodyId) {
            const searchInput = document.getElementById(inputId);
            const tableBody = document.getElementById(tableBodyId);
            if (!searchInput || !tableBody) return;
            
            searchInput.addEventListener('keyup', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = tableBody.getElementsByTagName('tr');
                
                Array.from(rows).forEach(row => {
                    const rowText = row.textContent.toLowerCase();
                    row.style.display = rowText.includes(searchTerm) ? '' : 'none';
                });
            });
        }
        setupTableSearch('searchEvents', 'eventsTableBody');
        setupTableSearch('searchCategories', 'categoriesTableBody');

        // JS for Assign Manager Modal
        const assignModal = document.getElementById('assignManagerModal');
        if (assignModal) {
            assignModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                document.getElementById('assign_event_id').value = button.dataset.eventId;
                document.getElementById('assign_event_name').textContent = button.dataset.eventName;
                document.getElementById('assign_user_id').value = button.dataset.userId || '0';
            });
        }

        // JS to keep the correct tab active after page reload
        const urlParams = new URLSearchParams(window.location.search);
        const tab = urlParams.get('tab');
        if (tab) {
            const tabElement = document.querySelector('#' + tab + '-tab');
            if (tabElement) {
                new bootstrap.Tab(tabElement).show();
            }
        }

        // Edit/Delete Modals JS...
        const editGameModal = document.getElementById('editGameModal');
        if (editGameModal) {
            editGameModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                editGameModal.querySelector('#edit_game_id').value = button.dataset.gameId;
                editGameModal.querySelector('#edit_game_name').value = button.dataset.gameName;
            });
        }
        
        const deleteGameModal = document.getElementById('deleteGameModal');
        if (deleteGameModal) {
            deleteGameModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                deleteGameModal.querySelector('#delete_game_id').value = button.dataset.gameId;
                deleteGameModal.querySelector('#delete_game_name').textContent = button.dataset.gameName;
            });
        }
        
        const editEventModal = document.getElementById('editEventModal');
        if (editEventModal) {
            editEventModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                editEventModal.querySelector('#edit_event_id').value = button.dataset.eventId;
                editEventModal.querySelector('#edit_event_name').value = button.dataset.eventName;
                editEventModal.querySelector('#edit_game_id_select').value = button.dataset.gameId;
            });
        }

        const deleteEventModal = document.getElementById('deleteEventModal');
        if (deleteEventModal) {
            deleteEventModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                deleteEventModal.querySelector('#delete_event_id').value = button.dataset.eventId;
                deleteEventModal.querySelector('#delete_event_name').textContent = button.dataset.eventName;
            });
        }

        const editCategoryModal = document.getElementById('editCategoryModal');
        if (editCategoryModal) {
            editCategoryModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                editCategoryModal.querySelector('#edit_category_id').value = button.dataset.categoryId;
                editCategoryModal.querySelector('#edit_category_name').value = button.dataset.categoryName;
                editCategoryModal.querySelector('#edit_event_id_select_cat').value = button.dataset.eventId;
            });
        }

        const deleteCategoryModal = document.getElementById('deleteCategoryModal');
        if (deleteCategoryModal) {
            deleteCategoryModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                deleteCategoryModal.querySelector('#delete_category_id').value = button.dataset.categoryId;
                deleteCategoryModal.querySelector('#delete_category_name').textContent = button.dataset.categoryName;
            });
        }

        // Table Sorting
        function setupTableSorting() {
            document.querySelectorAll('.sortable').forEach(header => {
                header.addEventListener('click', function() {
                    const table = this.closest('table');
                    const tbody = table.querySelector('tbody');
                    if (!tbody) return;
                    
                    const colIndex = Array.from(this.parentElement.children).indexOf(this);
                    const sortDir = this.dataset.sortDir === 'asc' ? 'desc' : 'asc';
                    
                    table.querySelectorAll('th.sortable').forEach(th => {
                        if (th !== this) {
                            th.dataset.sortDir = 'asc';
                            th.querySelector('i').className = 'fas fa-sort fa-xs';
                        }
                    });
                    
                    this.dataset.sortDir = sortDir;
                    this.querySelector('i').className = sortDir === 'asc' ? 'fas fa-sort-up fa-xs' : 'fas fa-sort-down fa-xs';

                    const rows = Array.from(tbody.querySelectorAll('tr'));
                    
                    const sortedRows = rows.sort((a, b) => {
                        const aVal = a.querySelector(`td:nth-child(${colIndex + 1})`).textContent.trim().toLowerCase();
                        const bVal = b.querySelector(`td:nth-child(${colIndex + 1})`).textContent.trim().toLowerCase();
                        let comparison = aVal.localeCompare(bVal, undefined, {numeric: true});
                        return sortDir === 'asc' ? comparison : -comparison;
                    });
                    
                    sortedRows.forEach(row => tbody.appendChild(row));
                });
            });
        }
        setupTableSorting();

        // Auto-dismiss alerts
        const autoDismissAlert = document.querySelector('.alert-dismissible');
        if (autoDismissAlert) {
            setTimeout(() => {
                new bootstrap.Alert(autoDismissAlert).close();
            }, 5000);
        }

        // Sidebar Toggle
        const mobileToggle = document.getElementById('mobileToggle');
        if(mobileToggle) {
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

        // Sidebar/Footer Fix
        const footer = document.querySelector('footer');
        const navbar = document.querySelector('.navbar');
        const sidebar = document.getElementById('sidebar');

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