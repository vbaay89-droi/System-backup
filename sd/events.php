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

// Ensure user_id is set in the session for logging
if (!isset($_SESSION['user_id'])) {
    // Handle error - user is logged in but ID is missing?
    die("Session error: User ID is not set.");
}
$current_user_id = $_SESSION['user_id'];

$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Sports Director';
$current_page = basename($_SERVER['PHP_SELF']);
$event_pages = ['Manage_Games.php', 'Manage_Game_Events.php', 'Manage_Categories.php'];
$is_event_page = in_array($current_page, $event_pages);

$management_pages = ['colleges.php', 'events.php', 'results.php', 'reports.php'];
$is_management_page = in_array($current_page, $management_pages);


// --- Page Specific PHP ---

// L1 - GAMES (CREATE)
if (isset($_POST['add_game'])) {
    $game_name = $_POST['game_name'];
    $stmt = $conn->prepare("INSERT INTO games (game_name) VALUES (?)");
    $stmt->bind_param("s", $game_name);
    $stmt->execute();
    
    $new_game_id = (int)$conn->insert_id;
    $context = ['game_name' => $game_name];
    log_activity($conn, $current_user_id, 'CREATED_GAME', $new_game_id, 'game', null, null, $context);
    
    $_SESSION['message'] = "Game added successfully.";
    $_SESSION['message_type'] = "success";
    header("Location: events.php?tab=games"); exit();
}

// L1 - GAMES (UPDATE)
if (isset($_POST['update_game'])) {
    $game_id = (int)$_POST['game_id'];
    $game_name = $_POST['game_name'];
    
    // Get old name for context *before* updating
    $stmt_old = $conn->prepare("SELECT game_name FROM games WHERE game_id = ?");
    $stmt_old->bind_param("i", $game_id);
    $stmt_old->execute();
    $old_game_name = $stmt_old->get_result()->fetch_assoc()['game_name'] ?? null;
    $stmt_old->close();

    $stmt = $conn->prepare("UPDATE games SET game_name = ? WHERE game_id = ?");
    $stmt->bind_param("si", $game_name, $game_id);
    $stmt->execute();
    
    $context = [
        'old_game_name' => $old_game_name,
        'new_game_name' => $game_name
    ];
    log_activity($conn, $current_user_id, 'UPDATED_GAME', $game_id, 'game', null, null, $context);
    
    $_SESSION['message'] = "Game updated successfully.";
    $_SESSION['message_type'] = "success";
    header("Location: events.php?tab=games"); exit();
}

// L1 - GAMES (DELETE)
if (isset($_POST['delete_game'])) {
    $game_id = (int)$_POST['game_id'];
    
    // Check for child events
    $stmt_check = $conn->prepare("SELECT COUNT(*) FROM game_events WHERE game_id = ?");
    $stmt_check->bind_param("i", $game_id);
    $stmt_check->execute();
    $count = 0;
    $stmt_check->bind_result($count);
    $stmt_check->fetch();
    $stmt_check->close();

    if ($count > 0) {
        $_SESSION['message'] = "Cannot delete game. It has {$count} child event(s) linked to it. Please delete them first.";
        $_SESSION['message_type'] = "danger";
    } else {
        // Get name for context *before* deleting
        $stmt_old = $conn->prepare("SELECT game_name FROM games WHERE game_id = ?");
        $stmt_old->bind_param("i", $game_id);
        $stmt_old->execute();
        $deleted_game_name = $stmt_old->get_result()->fetch_assoc()['game_name'] ?? 'Unknown';
        $stmt_old->close();

        $stmt = $conn->prepare("DELETE FROM games WHERE game_id = ?");
        $stmt->bind_param("i", $game_id);
        $stmt->execute();
        
        $context = ['deleted_game_name' => $deleted_game_name];
        log_activity($conn, $current_user_id, 'DELETED_GAME', $game_id, 'game', null, null, $context);
        
        $_SESSION['message'] = "Game deleted successfully.";
        $_SESSION['message_type'] = "success";
    }
    header("Location: events.php?tab=games"); exit();
}

// L2 - EVENTS (CREATE)
if (isset($_POST['add_event'])) {
    $game_id = (int)$_POST['game_id'];
    $event_name = $_POST['event_name'];
    
    $stmt = $conn->prepare("INSERT INTO game_events (game_id, event_name) VALUES (?, ?)");
    $stmt->bind_param("is", $game_id, $event_name);
    $stmt->execute();
    
    $new_event_id = (int)$conn->insert_id;

    // Get parent game name for full context
    $stmt_game = $conn->prepare("SELECT game_name FROM games WHERE game_id = ?");
    $stmt_game->bind_param("i", $game_id);
    $stmt_game->execute();
    $game_name = $stmt_game->get_result()->fetch_assoc()['game_name'] ?? 'Unknown';
    $stmt_game->close();

    $context = [
        'event_name' => $event_name,
        'parent_game_name' => $game_name,
        'parent_game_id' => $game_id
    ];
    log_activity($conn, $current_user_id, 'CREATED_EVENT', $new_event_id, 'event', null, null, $context);
    
    $_SESSION['message'] = "Event added successfully.";
    $_SESSION['message_type'] = "success";
    header("Location: events.php?tab=events"); exit();
}

// L2 - EVENTS (UPDATE)
if (isset($_POST['update_event'])) {
    $event_id = (int)$_POST['event_id'];
    $game_id = (int)$_POST['game_id'];
    $event_name = $_POST['event_name'];

    // Get old context *before* update
    $stmt_old = $conn->prepare("SELECT event_name FROM game_events WHERE event_id = ?");
    $stmt_old->bind_param("i", $event_id);
    $stmt_old->execute();
    $old_event_name = $stmt_old->get_result()->fetch_assoc()['event_name'] ?? 'Unknown';
    $stmt_old->close();

    $stmt = $conn->prepare("UPDATE game_events SET game_id = ?, event_name = ? WHERE event_id = ?");
    $stmt->bind_param("isi", $game_id, $event_name, $event_id);
    $stmt->execute();
    
    $context = [
        'old_event_name' => $old_event_name,
        'new_event_name' => $event_name,
        'new_parent_game_id' => $game_id
    ];
    log_activity($conn, $current_user_id, 'UPDATED_EVENT', $event_id, 'event', null, null, $context);
    
    $_SESSION['message'] = "Event updated successfully.";
    $_SESSION['message_type'] = "success";
    header("Location: events.php?tab=events"); exit();
}

// L2 - EVENTS (DELETE)
if (isset($_POST['delete_event'])) {
    $event_id = (int)$_POST['event_id'];

    // Check for child categories
    $stmt_check = $conn->prepare("SELECT COUNT(*) FROM categories WHERE event_id = ?");
    $stmt_check->bind_param("i", $event_id);
    $stmt_check->execute();
    $count = 0;
    $stmt_check->bind_result($count);
    $stmt_check->fetch();
    $stmt_check->close();

    if ($count > 0) {
        $_SESSION['message'] = "Cannot delete event. It has {$count} child categor(y/ies) linked. Please delete them first.";
        $_SESSION['message_type'] = "danger";
    } else {
        // Get name for context *before* deleting
        
        // ### THIS IS THE FIX ###
        // Changed "event_.name" to "event_name"
        $stmt_old = $conn->prepare("SELECT event_name FROM game_events WHERE event_id = ?");
        // #######################

        $stmt_old->bind_param("i", $event_id);
        $stmt_old->execute();
        $deleted_event_name = $stmt_old->get_result()->fetch_assoc()['event_name'] ?? 'Unknown';
        $stmt_old->close();
        
        // Delete from event_manager_assignments first
        $stmt_del_assign = $conn->prepare("DELETE FROM event_manager_assignments WHERE event_id = ?");
        $stmt_del_assign->bind_param("i", $event_id);
        $stmt_del_assign->execute();
        $stmt_del_assign->close();

        // Delete the event itself
        $stmt = $conn->prepare("DELETE FROM game_events WHERE event_id = ?");
        $stmt->bind_param("i", $event_id);
        $stmt->execute();
        
        $context = ['deleted_event_name' => $deleted_event_name];
        log_activity($conn, $current_user_id, 'DELETED_EVENT', $event_id, 'event', null, null, $context);

        $_SESSION['message'] = "Event deleted successfully.";
        $_SESSION['message_type'] = "success";
    }
    header("Location: events.php?tab=events"); exit();
}


// L3 - CATEGORIES (CREATE)
if (isset($_POST['add_category'])) {
    $event_id = (int)$_POST['event_id'];
    $category_name = $_POST['category_name'];
    
    $stmt = $conn->prepare("INSERT INTO categories (event_id, category_name) VALUES (?, ?)");
    $stmt->bind_param("is", $event_id, $category_name);
    $stmt->execute();
    
    $new_category_id = (int)$conn->insert_id;
    
    // Get parent event name for context
    $stmt_evt = $conn->prepare("SELECT event_name FROM game_events WHERE event_id = ?");
    $stmt_evt->bind_param("i", $event_id);
    $stmt_evt->execute();
    $event_name = $stmt_evt->get_result()->fetch_assoc()['event_name'] ?? 'Unknown';
    $stmt_evt->close();

    $context = [
        'category_name' => $category_name,
        'parent_event_name' => $event_name,
        'parent_event_id' => $event_id
    ];
    log_activity($conn, $current_user_id, 'CREATED_CATEGORY', $new_category_id, 'category', $event_id, 'event', $context);
    
    $_SESSION['message'] = "Category added successfully.";
    $_SESSION['message_type'] = "success";
    header("Location: events.php?tab=categories"); exit();
}

// L3 - CATEGORIES (UPDATE)
if (isset($_POST['update_category'])) {
    $category_id = (int)$_POST['category_id'];
    $event_id = (int)$_POST['event_id'];
    $category_name = $_POST['category_name'];
    
    // Get old context *before* update
    $stmt_old = $conn->prepare("SELECT category_name FROM categories WHERE category_id = ?");
    $stmt_old->bind_param("i", $category_id);
    $stmt_old->execute();
    $old_category_name = $stmt_old->get_result()->fetch_assoc()['category_name'] ?? 'Unknown';
    $stmt_old->close();

    $stmt = $conn->prepare("UPDATE categories SET event_id = ?, category_name = ? WHERE category_id = ?");
    $stmt->bind_param("isi", $event_id, $category_name, $category_id);
    $stmt->execute();
    
    $context = [
        'old_category_name' => $old_category_name,
        'new_category_name' => $category_name,
        'new_parent_event_id' => $event_id
    ];
    log_activity($conn, $current_user_id, 'UPDATED_CATEGORY', $category_id, 'category', null, null, $context);
    
    $_SESSION['message'] = "Category updated successfully.";
    $_SESSION['message_type'] = "success";
    header("Location: events.php?tab=categories"); exit();
}

// L3 - CATEGORIES (DELETE)
if (isset($_POST['delete_category'])) {
    $category_id = (int)$_POST['category_id'];

    // Get name for context *before* deleting
    $stmt_old = $conn->prepare("SELECT category_name FROM categories WHERE category_id = ?");
    $stmt_old->bind_param("i", $category_id);
    $stmt_old->execute();
    $deleted_category_name = $stmt_old->get_result()->fetch_assoc()['category_name'] ?? 'Unknown';
    $stmt_old->close();

    $stmt = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    
    $context = ['deleted_category_name' => $deleted_category_name];
    log_activity($conn, $current_user_id, 'DELETED_CATEGORY', $category_id, 'category', null, null, $context);
    
    $_SESSION['message'] = "Category deleted successfully.";
    $_SESSION['message_type'] = "success";
    header("Location: events.php?tab=categories"); exit();
}

// MANAGER ASSIGNMENT
if (isset($_POST['assign_manager'])) {
    $event_id = (int)$_POST['event_id'];
    $user_id = (int)$_POST['user_id']; // This is the manager's ID

    // Get Event Name for context
    $stmt_evt = $conn->prepare("SELECT event_name FROM game_events WHERE event_id = ?");
    $stmt_evt->bind_param("i", $event_id);
    $stmt_evt->execute();
    $event_name = $stmt_evt->get_result()->fetch_assoc()['event_name'] ?? 'Unknown Event';
    $stmt_evt->close();

    // Delete any existing assignment for this event first (REPLACE)
    $stmt_del = $conn->prepare("DELETE FROM event_manager_assignments WHERE event_id = ?");
    $stmt_del->bind_param("i", $event_id);
    $stmt_del->execute();
    $stmt_del->close();

    if ($user_id > 0) {
        // --- This is an ASSIGN action ---
        // *** THIS BLOCK IS CORRECT. THE ERROR IS CAUSED BY AN OLD FILE ON YOUR SERVER. ***
        $stmt_ins = $conn->prepare("INSERT INTO event_manager_assignments (event_id, user_id) VALUES (?, ?)");
        $stmt_ins->bind_param("ii", $event_id, $user_id);
        $stmt_ins->execute();
        $stmt_ins->close();
        
        // Get Manager Name for context
        $stmt_mgr = $conn->prepare("SELECT full_name FROM users WHERE id = ?"); // Assumes 'full_name' and 'id'
        $stmt_mgr->bind_param("i", $user_id);
        $stmt_mgr->execute();
        $manager_name = $stmt_mgr->get_result()->fetch_assoc()['full_name'] ?? 'Unknown Manager';
        $stmt_mgr->close();

        $context = [
            'manager_name' => $manager_name,
            'event_name' => $event_name
        ];
        log_activity(
            $conn,
            $current_user_id,
            'ASSIGNED_MANAGER',
            $event_id,    // subject is the event
            'event',
            $user_id,     // target is the manager
            'user',
            $context
        );
    } else {
        // --- This is an UNASSIGN action ---
        // We just deleted, so the action is complete.
        $context = ['event_name' => $event_name];
        log_activity(
            $conn,
            $current_user_id,
            'UNASSIGNED_MANAGER',
            $event_id,    // subject is the event
            'event',
            null,         // no target
            null,
            $context
        );
    }
    
    $_SESSION['message'] = "Manager assignment updated successfully.";
    $_SESSION['message_type'] = "success";
    header("Location: events.php?tab=events"); exit();
}


// --- FETCH DATA (READ) ---
$games = $conn->query("SELECT * FROM games ORDER BY game_name")->fetch_all(MYSQLI_ASSOC);

// Fetch L2 Events
$events = $conn->query("SELECT e.*, g.game_name FROM game_events e JOIN games g ON e.game_id = g.game_id ORDER BY g.game_name, e.event_name")->fetch_all(MYSQLI_ASSOC);

// Fetch L3 Categories with their L2 Assigned Manager
$sql_categories = "SELECT c.*, e.event_name, g.game_name, u.full_name as manager_name
                   FROM categories c
                   JOIN game_events e ON c.event_id = e.event_id
                   JOIN games g ON e.game_id = g.game_id
                   LEFT JOIN event_manager_assignments ema ON e.event_id = ema.event_id
                   LEFT JOIN users u ON ema.user_id = u.id
                   ORDER BY g.game_name, e.event_name, c.category_name";
$categories = $conn->query($sql_categories)->fetch_all(MYSQLI_ASSOC);


// Fetch L2 Events with their assigned managers
$events_with_managers = [];
$sql_events_managers = "SELECT e.*, g.game_name, u.full_name as manager_name, ema.user_id
                        FROM game_events e
                        JOIN games g ON e.game_id = g.game_id
                        LEFT JOIN event_manager_assignments ema ON e.event_id = ema.event_id
                        LEFT JOIN users u ON ema.user_id = u.id
                        ORDER BY g.game_name, e.event_name";
$result_events_managers = $conn->query($sql_events_managers);
if($result_events_managers) {
    $events_with_managers = $result_events_managers->fetch_all(MYSQLI_ASSOC);
}

// Fetch available Event Managers (assumes users.id and users.full_name)
$event_managers = []; // Default to an empty array
$result_managers = $conn->query("SELECT id, full_name FROM users WHERE role = 'Event Manager' ORDER BY full_name");
if ($result_managers) {
    $event_managers = $result_managers->fetch_all(MYSQLI_ASSOC);
}

// Check for session messages
$message = null;
$message_type = 'info';

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
        
        /* --- NEW STYLES --- */
        .sortable { cursor: pointer; user-select: none; }
        .sortable:hover { background-color: rgba(0,0,0,0.02); }
        .sortable i { margin-left: 5px; color: #999; }
        .table-hover th.sortable:hover i { color: #333; }
        .table .text-end { white-space: nowrap; width: 1%; }
        /* --- End of Accordion Styles --- */
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items: center justify-content-between">
            <a class="navbar-brand d-flex align-items: center" href="dashboard.php">
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
                    <a class="nav-link <?php if ($is_management_page) echo 'active'; ?>" data-bs-toggle="collapse" href="#collegesCollapse" role="button" aria-expanded="<?php echo $is_management_page ? 'true' : 'false'; ?>" aria-controls="collegesCollapse">
                        <i class="fas fa-users me-2"></i> <span>Manage Colleges</span> <i class="fas fa-chevron-down ms-auto sidebar-chevron"></i>
                    </a>
                    
                    <div class="collapse <?php if ($is_management_page) echo 'show'; ?>" id="collegesCollapse">
                        <ul class="sub-menu">
                            
                            <li class="text-muted" style="padding: 10px 25px 5px 60px; margin-top: 5px; font-size: 0.75rem; font-weight: 600; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px;">
                                Management
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php if ($current_page == 'colleges.php') echo 'active'; ?>" href="colleges.php">
                                    <span>Manage college</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php if ($current_page == 'events.php') echo 'active'; ?>" href="events.php">
                                    <span>Manage Events (L1-L3)</span>
                                </a>
                            </li>
                            <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'Manage_Matches.php') echo 'active'; ?>" href="Manage_Matches.php">
                                <span>Manage Matches</span>
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
                    <a class="nav-link <?php if ($current_page == 'colleges.php') echo 'active'; ?>" href="colleges.php">
                        <i class="fas fa-users me-2"></i> <span>Manage Colleges</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'events.php') echo 'active'; ?>" href="events.php">
                        <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events (L1-L3)</span>
                    </a>
                </li>
                <!-- === THIS IS THE NEW LINK YOU ASKED FOR === -->
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == 'view_all_matches.php') ? 'active' : '' ?>" href="view_all_matches.php">
                        <i class="fas fa-trophy me-2"></i> <span>View All Matches</span>
                    </a>
                </li>
                <!-- === END OF NEW LINK === -->
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
                                <thead>
                                    <tr>
                                        <th>Game Name</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($games as $game): ?>
                                    <tr>
                                        <td class="align-middle"><?= htmlspecialchars($game['game_name']) ?></td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-secondary edit-game-btn"
                                                data-bs-toggle="modal" data-bs-target="#editGameModal"
                                                data-game-id="<?= $game['game_id'] ?>"
                                                data-game-name="<?= htmlspecialchars($game['game_name']) ?>">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger delete-game-btn"
                                                data-bs-toggle="modal" data-bs-target="#deleteGameModal"
                                                data-game-id="<?= $game['game_id'] ?>"
                                                data-game-name="<?= htmlspecialchars($game['game_name']) ?>">
                                                <i class="fas fa-trash"></i> Delete
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
                        
                        <div class="card-body border-top">
                             <input type="search" id="searchEvents" class="form-control" placeholder="Search events, games, or managers...">
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th class="sortable" data-sort-dir="asc">Game (L1) <i class="fas fa-sort fa-xs"></i></th>
                                        <th class="sortable" data-sort-dir="asc">Event (L2) <i class="fas fa-sort fa-xs"></i></th>
                                        <th class="sortable" data-sort-dir="asc">Assigned Manager <i class="fas fa-sort fa-xs"></i></th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="eventsTableBody">
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
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary assign-btn"
                                                data-bs-toggle="modal" data-bs-target="#assignManagerModal"
                                                data-event-id="<?= $event['event_id'] ?>"
                                                data-event-name="<?= htmlspecialchars($event['event_name']) ?>"
                                                data-user-id="<?= $event['user_id'] ?? '' ?>">
                                                <i class="fas fa-user-plus"></i> Assign
                                            </button>
                                            <button class="btn btn-sm btn-outline-secondary edit-event-btn"
                                                data-bs-toggle="modal" data-bs-target="#editEventModal"
                                                data-event-id="<?= $event['event_id'] ?>"
                                                data-event-name="<?= htmlspecialchars($event['event_name']) ?>"
                                                data-game-id="<?= $event['game_id'] ?>">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger delete-event-btn"
                                                data-bs-toggle="modal" data-bs-target="#deleteEventModal"
                                                data-event-id="<?= $event['event_id'] ?>"
                                                data-event-name="<?= htmlspecialchars($event['event_name']) ?>">
                                                <i class="fas fa-trash"></i> Delete
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
                        
                        <div class="card-body border-top">
                             <input type="search" id="searchCategories" class="form-control" placeholder="Search categories, events, games, or managers...">
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th class="sortable" data-sort-dir="asc">Game (L1) <i class="fas fa-sort fa-xs"></i></th>
                                        <th class="sortable" data-sort-dir="asc">Event (L2) <i class="fas fa-sort fa-xs"></i></th>
                                        <th class="sortable" data-sort-dir="asc">Category (L3) <i class="fas fa-sort fa-xs"></i></th>
                                        <th class="sortable" data-sort-dir="asc">Assigned Manager <i class="fas fa-sort fa-xs"></i></th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="categoriesTableBody">
                                    <?php foreach ($categories as $category): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($category['game_name']) ?></td>
                                        <td><?= htmlspecialchars($category['event_name']) ?></td>
                                        <td><?= htmlspecialchars($category['category_name']) ?></td>
                                        <td>
                                            <?php if($category['manager_name']): ?>
                                                <span class="badge bg-success"><?= htmlspecialchars($category['manager_name']) ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-secondary edit-category-btn"
                                                data-bs-toggle="modal" data-bs-target="#editCategoryModal"
                                                data-category-id="<?= $category['category_id'] ?>"
                                                data-category-name="<?= htmlspecialchars($category['category_name']) ?>"
                                                data-event-id="<?= $category['event_id'] ?>">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger delete-category-btn"
                                                data-bs-toggle="modal" data-bs-target="#deleteCategoryModal"
                                                data-category-id="<?= $category['category_id'] ?>"
                                                data-category-name="<?= htmlspecialchars($category['category_name']) ?>">
                                                <i class="fas fa-trash"></i> Delete
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

        <div class="modal fade" id="editGameModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Game (L1)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="update_game">
                            <input type="hidden" name="game_id" id="edit_game_id">
                            <div class="mb-3">
                                <label for="edit_game_name" class="form-label">Game Name</label>
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
                        <h5 class="modal-title">Delete Game (L1)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="delete_game">
                            <input type="hidden" name="game_id" id="delete_game_id">
                            <p>Are you sure you want to delete the game <strong id="delete_game_name"></strong>?</p>
                            <p class="text-danger"><small>This action cannot be undone. You cannot delete a game that has events linked to it.</small></p>
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
                        <h5 class="modal-title">Edit Event (L2)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="update_event">
                            <input type="hidden" name="event_id" id="edit_event_id">
                            
                            <div class="mb-3">
                                <label for="edit_game_id_select" class="form-label">Parent Game (L1)</label>
                                <select class="form-select" id="edit_game_id_select" name="game_id" required>
                                    <?php foreach ($games as $game): ?>
                                    <option value="<?= $game['game_id'] ?>"><?= htmlspecialchars($game['game_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="edit_event_name" class="form-label">Event Name</label>
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
                        <h5 class="modal-title">Delete Event (L2)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="delete_event">
                            <input type="hidden" name="event_id" id="delete_event_id">
                            <p>Are you sure you want to delete the event <strong id="delete_event_name"></strong>?</p>
                            <p class="text-danger"><small>This action cannot be undone. You cannot delete an event that has categories linked to it.</small></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Delete Event</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="editCategoryModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Category (L3)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="update_category">
                            <input type="hidden" name="category_id" id="edit_category_id">
                            
                            <div class="mb-3">
                                <label for="edit_event_id_select_cat" class="form-label">Parent Event (L2)</label>
                                <select class="form-select" id="edit_event_id_select_cat" name="event_id" required>
                                    <option value="" disabled>-- Select Event --</option>
                                    <?php foreach ($events as $event): ?>
                                    <option value="<?= $event['event_id'] ?>">
                                        <?= htmlspecialchars($event['game_name']) ?> - <?= htmlspecialchars($event['event_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="edit_category_name" class="form-label">Category Name</label>
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
                        <h5 class="modal-title">Delete Category (L3)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="delete_category">
                            <input type="hidden" name="category_id" id="delete_category_id">
                            <p>Are you sure you want to delete the category <strong id="delete_category_name"></strong>?</p>
                            <p class="text-danger"><small>This action cannot be undone.</small></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Delete Category</button>
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
        
        // --- EXISTING JS ---
        
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
        
        // --- NEW JS FEATURES ---

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

        // Feature 2: Action Button Modals
        
        // Edit Game Modal
        const editGameModal = document.getElementById('editGameModal');
        if (editGameModal) {
            editGameModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const gameId = button.dataset.gameId;
                const gameName = button.dataset.gameName;
                
                editGameModal.querySelector('#edit_game_id').value = gameId;
                
                // ############ THIS IS THE FIX ############
                // Corrected `editGameMogit add .dal` to `editGameModal`
                editGameModal.querySelector('#edit_game_name').value = gameName;
                // #########################################
            });
        }
        
        // Delete Game Modal
        const deleteGameModal = document.getElementById('deleteGameModal');
        if (deleteGameModal) {
            deleteGameModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const gameId = button.dataset.gameId;
                const gameName = button.dataset.gameName;
                
                deleteGameModal.querySelector('#delete_game_id').value = gameId;
                deleteGameModal.querySelector('#delete_game_name').textContent = gameName;
            });
        }
        
        // Edit Event Modal
        const editEventModal = document.getElementById('editEventModal');
        if (editEventModal) {
            editEventModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                editEventModal.querySelector('#edit_event_id').value = button.dataset.eventId;
                editEventModal.querySelector('#edit_event_name').value = button.dataset.eventName;
                editEventModal.querySelector('#edit_game_id_select').value = button.dataset.gameId;
            });
        }

        // Delete Event Modal
        const deleteEventModal = document.getElementById('deleteEventModal');
        if (deleteEventModal) {
            deleteEventModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                deleteEventModal.querySelector('#delete_event_id').value = button.dataset.eventId;
                deleteEventModal.querySelector('#delete_event_name').textContent = button.dataset.eventName;
            });
        }

        // Edit Category Modal
        // *** THIS CODE WILL NOW WORK BECAUSE THE ERROR ABOVE IS FIXED ***
        const editCategoryModal = document.getElementById('editCategoryModal');
        if (editCategoryModal) {
            editCategoryModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                editCategoryModal.querySelector('#edit_category_id').value = button.dataset.categoryId;
                editCategoryModal.querySelector('#edit_category_name').value = button.dataset.categoryName;
                editCategoryModal.querySelector('#edit_event_id_select_cat').value = button.dataset.eventId;
            });
        }

        // Delete Category Modal
        const deleteCategoryModal = document.getElementById('deleteCategoryModal');
        if (deleteCategoryModal) {
            deleteCategoryModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                deleteCategoryModal.querySelector('#delete_category_id').value = button.dataset.categoryId;
                deleteCategoryModal.querySelector('#delete_category_name').textContent = button.dataset.categoryName;
            });
        }

        
        
        // Feature 4: Interactive Table Sorting
        function setupTableSorting() {
            document.querySelectorAll('.sortable').forEach(header => {
                header.addEventListener('click', function() {
                    const table = this.closest('table');
                    const tbody = table.querySelector('tbody');
                    if (!tbody) return;
                    
                    const colIndex = Array.from(this.parentElement.children).indexOf(this);
                    const sortDir = this.dataset.sortDir === 'asc' ? 'desc' : 'asc';
                    
                    // Reset other header icons
                    table.querySelectorAll('th.sortable').forEach(th => {
                        if (th !== this) {
                            th.dataset.sortDir = 'asc';
                            th.querySelector('i').className = 'fas fa-sort fa-xs';
                        }
                    });
                    
                    // Set this header's icon and direction
                    this.dataset.sortDir = sortDir;
                    this.querySelector('i').className = sortDir === 'asc' ? 'fas fa-sort-up fa-xs' : 'fas fa-sort-down fa-xs';

                    const rows = Array.from(tbody.querySelectorAll('tr'));
                    
                    const sortedRows = rows.sort((a, b) => {
                        const aVal = a.querySelector(`td:nth-child(${colIndex + 1})`).textContent.trim().toLowerCase();
                        const bVal = b.querySelector(`td:nth-child(${colIndex + 1})`).textContent.trim().toLowerCase();
                        
                        let comparison = aVal.localeCompare(bVal, undefined, {numeric: true});
                        
                        return sortDir === 'asc' ? comparison : -comparison;
                    });
                    
                    // Re-append sorted rows
                    sortedRows.forEach(row => tbody.appendChild(row));
                });
            });
        }
        setupTableSorting();

    // --- NEW: Auto-dismiss success alerts ---
    // Find the alert message
    const autoDismissAlert = document.querySelector('.alert-dismissible');

    // If an alert is found on the page
    if (autoDismissAlert) {
        // Wait for 5 seconds
        setTimeout(() => {
            // Get the Bootstrap 5 alert instance
            const bsAlert = new bootstrap.Alert(autoDismissAlert);
            
            // Call the 'close' method, which triggers the fade-out animation
            bsAlert.close();
        }, 5000); // 5000 milliseconds = 5 seconds
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