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
    $game_id = (int)$_POST['game_id']; 
    $event_name = trim($_POST['event_name']); 
    $structure_db_value = 'Standard';
    
    // NEW: Capture Fixed Medal Count
    $fixed_medal_count = !empty($_POST['fixed_medal_count']) ? (int)$_POST['fixed_medal_count'] : null;

    try {
        $stmt = $conn->prepare("INSERT INTO game_events (game_id, event_name, event_structure, fixed_medal_count) VALUES (?, ?, ?, ?)");
        // Note: Changed "iss" to "issi" to include the integer for fixed_medal_count
        $stmt->bind_param("issi", $game_id, $event_name, $structure_db_value, $fixed_medal_count); 
        $stmt->execute(); 
        $stmt->close();
        
        $_SESSION['message'] = "Event added successfully."; 
        $_SESSION['message_type'] = "success";
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1062) { $_SESSION['message'] = "Event '$event_name' already exists."; $_SESSION['message_type'] = "warning"; } 
        else { $_SESSION['message'] = "Error: " . $e->getMessage(); $_SESSION['message_type'] = "danger"; }
    }
    header("Location: events.php?tab=events"); exit();
}
// L2 - EVENTS (UPDATE)
if (isset($_POST['update_event'])) {
    $event_id = (int)$_POST['event_id']; 
    $game_id = (int)$_POST['game_id']; 
    $event_name = $_POST['event_name'];
    
    // NEW: Capture Fixed Medal Count
    $fixed_medal_count = !empty($_POST['fixed_medal_count']) ? (int)$_POST['fixed_medal_count'] : null;

    $stmt = $conn->prepare("UPDATE game_events SET game_id = ?, event_name = ?, fixed_medal_count = ? WHERE event_id = ?");
    // Note: Changed "isi" to "isii"
    $stmt->bind_param("isii", $game_id, $event_name, $fixed_medal_count, $event_id); 
    $stmt->execute();
    
    $_SESSION['message'] = "Event updated successfully."; 
    $_SESSION['message_type'] = "success"; 
    header("Location: events.php?tab=events"); exit();
}
// L2 - EVENTS (DELETE)
if (isset($_POST['delete_event'])) {
    $event_id = (int)$_POST['event_id'];
    
    // 1. First Check: categories (existing logic)
    $stmt_check = $conn->prepare("SELECT COUNT(*) FROM categories WHERE event_id = ?");
    $stmt_check->bind_param("i", $event_id); 
    $stmt_check->execute();
    $count = 0; 
    $stmt_check->bind_result($count); 
    $stmt_check->fetch(); 
    $stmt_check->close();

    if ($count > 0) { 
        $_SESSION['message'] = "Cannot delete event. It has linked categories (L3). Please delete them first."; 
        $_SESSION['message_type'] = "danger"; 
    } else {
        // 2. SAFE DELETE: Use Try-Catch to handle "Silent" DB errors
        $conn->begin_transaction();
        try {
            // Delete manager link first
            $conn->query("DELETE FROM tournament_manager_assignments WHERE event_id = $event_id");

            // Try to delete the event
            if (!$conn->query("DELETE FROM game_events WHERE event_id = $event_id")) {
                // If DB says no, throw an error
                throw new Exception($conn->error);
            }

            $conn->commit(); // Confirm changes
            $_SESSION['message'] = "Event deleted successfully."; 
            $_SESSION['message_type'] = "success";
            
        } catch (Exception $e) {
            $conn->rollback(); // Undo everything if it failed
            // Show the REAL error message
            $_SESSION['message'] = "Cannot delete this event. It is currently being used in Matches or Results.";
            $_SESSION['message_type'] = "danger";
        }
    }
    header("Location: events.php?tab=events"); exit();
}

// L3 - CATEGORIES (CREATE)
if (isset($_POST['add_category'])) {
    $event_id = (int)$_POST['event_id']; 
    $category_name = trim($_POST['category_name']);
    $division_name = trim($_POST['division_name'] ?? ''); // <--- Added ?? '' here
    
    $stmt = $conn->prepare("INSERT INTO categories (event_id, category_name, division_name) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $event_id, $category_name, $division_name); 
    $stmt->execute();
    
    $_SESSION['message'] = "Category and Division added successfully."; 
    $_SESSION['message_type'] = "success"; 
    header("Location: events.php?tab=categories"); 
    exit();
}

// L3 - CATEGORIES (UPDATE)
if (isset($_POST['update_category'])) {
    $category_id = (int)$_POST['category_id']; 
    $event_id = (int)$_POST['event_id']; 
    $category_name = trim($_POST['category_name']);
    $division_name = trim($_POST['division_name'] ?? ''); // <--- Added ?? '' here
    
    $stmt = $conn->prepare("UPDATE categories SET event_id = ?, category_name = ?, division_name = ? WHERE category_id = ?");
    $stmt->bind_param("issi", $event_id, $category_name, $division_name, $category_id); 
    $stmt->execute();
    
    $_SESSION['message'] = "Category updated successfully."; 
    $_SESSION['message_type'] = "success"; 
    header("Location: events.php?tab=categories"); 
    exit();
}
// L3 - CATEGORIES (DELETE)
if (isset($_POST['delete_category'])) {
    $category_id = (int)$_POST['category_id'];
    $stmt = $conn->prepare("DELETE FROM categories WHERE category_id = ?"); $stmt->bind_param("i", $category_id); $stmt->execute();
    $_SESSION['message'] = "Category deleted successfully."; $_SESSION['message_type'] = "success"; header("Location: events.php?tab=categories"); exit();
}
// MANAGER ASSIGNMENT
if (isset($_POST['assign_manager'])) {
    $event_id = (int)$_POST['event_id']; 
    $user_id = (int)$_POST['user_id'];

    // 1. Check if this user is ALREADY assigned to another event (and it's not THIS event)
    if ($user_id > 0) {
        $check = $conn->query("SELECT event_id FROM tournament_manager_assignments WHERE user_id = $user_id AND event_id != $event_id");
        if ($check->num_rows > 0) {
            $_SESSION['message'] = "Error: That manager is already assigned to another event."; 
            $_SESSION['message_type'] = "danger"; 
            header("Location: events.php?tab=events"); 
            exit();
        }
    }

    // 2. Proceed with assignment
    $conn->query("DELETE FROM tournament_manager_assignments WHERE event_id = $event_id");
    
    if ($user_id > 0) {
        $stmt_ins = $conn->prepare("INSERT INTO tournament_manager_assignments (event_id, user_id) VALUES (?, ?)");
        $stmt_ins->bind_param("ii", $event_id, $user_id); 
        $stmt_ins->execute();
    }
    
    $_SESSION['message'] = "Manager assignment updated."; 
    $_SESSION['message_type'] = "success"; 
    header("Location: events.php?tab=events"); 
    exit();
}

// --- FETCH DATA ---
$games = $conn->query("SELECT * FROM games ORDER BY game_name")->fetch_all(MYSQLI_ASSOC);
$events = $conn->query("SELECT e.*, g.game_name FROM game_events e JOIN games g ON e.game_id = g.game_id ORDER BY g.game_name, e.event_name")->fetch_all(MYSQLI_ASSOC);
$categories = $conn->query("SELECT c.*, e.event_name, g.game_name, u.full_name as manager_name FROM categories c JOIN game_events e ON c.event_id = e.event_id JOIN games g ON e.game_id = g.game_id LEFT JOIN tournament_manager_assignments ema ON e.event_id = ema.event_id LEFT JOIN users u ON ema.user_id = u.id ORDER BY g.game_name, e.event_name, c.category_name")->fetch_all(MYSQLI_ASSOC);
$events_with_managers = $conn->query("SELECT e.*, g.game_name, u.full_name as manager_name, ema.user_id FROM game_events e JOIN games g ON e.game_id = g.game_id LEFT JOIN tournament_manager_assignments ema ON e.event_id = ema.event_id LEFT JOIN users u ON ema.user_id = u.id ORDER BY g.game_name, e.event_name")->fetch_all(MYSQLI_ASSOC);
$event_managers = $conn->query("SELECT id, full_name FROM users WHERE role = 'Tournament Manager' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

// [NEW] Get a simple list of User IDs that are ALREADY assigned to ANY event
$assigned_ids = [];
$assigned_q = $conn->query("SELECT user_id FROM tournament_manager_assignments");
while($row = $assigned_q->fetch_assoc()) {
    $assigned_ids[] = $row['user_id'];
}

$message = $_SESSION['message'] ?? null;
$message_type = $_SESSION['message_type'] ?? 'info';
unset($_SESSION['message'], $_SESSION['message_type']);

$grouped_events = [];
    foreach ($events as $event) {
        $grouped_events[$event['game_name']][] = $event;
    }

// Counts
$pending_requests_count = $conn->query("SELECT COUNT(*) FROM users WHERE is_approved = 0")->fetch_row()[0] ?? 0;
$pending_results_count = $conn->query("SELECT COUNT(*) FROM categories WHERE status='Results Submitted'")->fetch_row()[0] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Events - Sports Director Panel</title>
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
        /* Footer */
        footer {
            flex-shrink: 0;
            /* REMOVED background color here so .footer-main can work */
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            padding-left: var(--sidebar-width);
            transition: padding-left var(--transition);
            position: relative;
            z-index: 1041;
        }
        /* --- FOOTER STYLES (MATCHING HOME.PHP) --- */
    .footer-main {
        flex-shrink: 0;
        background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
        color: rgba(255,255,255,0.7);
        padding: 3rem 0 2rem 0;
        box-shadow: 0 -4px 20px rgba(0,0,0,0.15);
        position: relative;
        z-index: 1;
    }
    .footer-main .footer-logo-group {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 1rem;
    }
    .footer-main .footer-logo-group img {
        height: 50px !important;
        width: 50px !important;
        object-fit: contain;
    }
    .footer-main .footer-logo-group h5 {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 700;
        color: #fff;
        line-height: 1.2;
    }
    .footer-main p {
        font-size: 0.9rem;
        max-width: 400px;
    }
    .footer-main h6 {
        font-family: 'Poppins', sans-serif;
        color: #fff;
        font-weight: 600;
        margin-bottom: 1rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .footer-main .footer-links {
        list-style: none;
        padding: 0;
    }
    .footer-main .footer-links li {
        margin-bottom: 0.5rem;
    }
    .footer-main .footer-links a {
        text-decoration: none;
        color: rgba(255,255,255,0.7);
        transition: var(--transition);
    }
    .footer-main .footer-links a:hover {
        color: #fff;
        padding-left: 5px;
    }
    .footer-bottom {
        border-top: 1px solid rgba(255,255,255,0.1);
        padding-top: 1.5rem;
        margin-top: 2rem;
        text-align: center;
        font-size: 0.85rem;
    }
    @media (max-width: 767.98px) {
      .logo-container {
        gap: 1rem;
      }
      .main-logo {
        width: 80px;
        height: 80px;
      }
      .brand-title {
        font-size: 1.5rem;
      }
      .login-container h2 {
        font-size: 1.5rem;
      }
    }
    @media (max-width: 991px) {
            /* 1. Center text on smaller screens */
            .footer-main { 
                text-align: center; 
            }
            
            /* 2. Center the logo group (Image + Text) */
            .footer-main .footer-logo-group { 
                justify-content: center; 
            }
            
            /* 3. Add spacing between columns so they don't look cramped */
            .footer-main .row > div { 
                margin-bottom: 2rem; 
            }
            
            /* 4. Ensure the last column doesn't have extra margin */
            .footer-main .row > div:last-child {
                margin-bottom: 0;
            }
        }
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        .card { border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        .sortable { cursor: pointer; user-select: none; }
        .sortable:hover { background-color: rgba(0,0,0,0.02); }
        @media (max-width: 992px) { .sidebar { left: -260px; } .sidebar.show { left: 0; } .main-content, footer { margin-left: 0; } footer { padding-left: 0; } }
        .nav-pills-custom .nav-link {
        color: #6c757d;
        background: #fff;
        border: 1px solid #e9ecef;
        border-radius: 50px;
        padding: 10px 25px;
        margin-right: 10px;
        font-weight: 600;
        transition: all 0.3s ease;
        box-shadow: 0 2px 5px rgba(0,0,0,0.02);
    }
    .nav-pills-custom .nav-link.active {
        background-color: var(--primary-gradient); /* Uses your dashboard blue/teal */
        background: #2c3e50;
        color: #fff;
        border-color: #2c3e50;
        box-shadow: 0 4px 10px rgba(44, 62, 80, 0.3);
    }
    .nav-pills-custom .nav-link:hover:not(.active) {
        background-color: #f8f9fa;
        transform: translateY(-1px);
    }
    /* 2. Game Cards (Level 1) */
    .game-card {
        background: white;
        border: none;
        border-radius: 16px;
        padding: 25px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
        box-shadow: 0 4px 6px rgba(0,0,0,0.02);
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    
    .game-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; w: 4px; height: 100%;
        background: #e9ecef;
        transition: 0.3s;
    }
    
    
    .game-icon-wrapper {
        width: 50px; height: 50px;
        border-radius: 12px;
        background: rgba(26, 188, 156, 0.1);
        color: var(--accent-color);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.5rem;
        margin-bottom: 15px;
    }
    /* 3. Modern Tables (Level 2 & 3) */
    .modern-table {
        border-collapse: separate;
        border-spacing: 0 10px; /* Spacing between rows */
        width: 100%;
    }
    .modern-table thead th {
        border: none;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #8898aa;
        padding: 0 20px 10px 20px;
        background: transparent;
    }
    .modern-table tbody tr {
        background: white;
        /* CHANGE 1: Increase opacity from 0.02 to 0.08 for visibility */
        box-shadow: 0 4px 12px rgba(0,0,0,0.08); 
        
        /* CHANGE 2: Add a faint border to define edges clearly */
        border: 1px solid rgba(0,0,0,0.05);
        
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        border-radius: 10px;
    }
    .modern-table tbody tr:hover {
        transform: scale(1.005);
        box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        z-index: 2;
        position: relative;
    }
    .modern-table td {
        border: none;
        padding: 18px 20px;
        vertical-align: middle;
    }
    .modern-table td:first-child { border-top-left-radius: 10px; border-bottom-left-radius: 10px; }
    .modern-table td:last-child { border-top-right-radius: 10px; border-bottom-right-radius: 10px; }
    /* 4. Manager Avatar Badges */
    .manager-badge {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 6px 12px;
        background: #f8f9fa;
        border-radius: 30px;
        width: fit-content;
        border: 1px solid #e9ecef;
    }
    .manager-avatar {
        width: 28px; height: 28px;
        background: #2c3e50;
        color: white;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.75rem;
        font-weight: bold;
    }
    .manager-unassigned {
        background: #fff3cd;
        color: #856404;
        border-color: #ffeeba;
    }
    /* 5. Action Buttons */
    .btn-icon {
        width: 32px; height: 32px;
        border-radius: 8px;
        display: inline-flex; align-items: center; justify-content: center;
        border: none;
        transition: 0.2s;
        margin-left: 5px;
        background: #f1f3f5;
        color: #495057;
    }
    .btn-icon:hover { background: #e9ecef; color: #212529; }
    .btn-icon.edit:hover { background: rgba(13, 202, 240, 0.1); color: #0dcaf0; }
    .btn-icon.delete:hover { background: rgba(220, 53, 69, 0.1); color: #dc3545; }
    .btn-icon.assign:hover { background: rgba(25, 135, 84, 0.1); color: #198754; }
    /* Search Bar Polish */
    .search-wrapper {
        position: relative;
        margin-bottom: 20px;
    }
    .search-wrapper input {
        border-radius: 50px;
        padding-left: 45px;
        border: 1px solid #e0e0e0;
        box-shadow: 0 2px 10px rgba(0,0,0,0.03);
    }
    .search-wrapper i {
        position: absolute;
        left: 20px;
        top: 50%;
        transform: translateY(-50%);
        color: #adb5bd;
    }

    /* ========================================
    ENHANCED PAGE HEADER
    ======================================== */
    .page-header {
        position: relative;
        margin-bottom: 2rem;
    }

    .page-header .section-title {
        font-family: 'Poppins', sans-serif;
        font-weight: 800;
        font-size: 2.2rem;
        color: #2c3e50;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
    }

    .page-header .section-title i {
        background: linear-gradient(135deg, var(--accent-color) 0%, #16a085 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }


    /* ============================================
       IMPROVED MODAL DESIGN
       ============================================ */

    /* Modal Backdrop - Darker overlay for better focus */
    .modal-backdrop.show {
        opacity: 0.7;
        backdrop-filter: blur(3px);
    }

    /* Modal Dialog - Smooth animation */
    .modal.fade .modal-dialog {
        transition: transform 0.25s ease-out, opacity 0.25s ease-out;
    }

    .modal.show .modal-dialog {
        transform: none;
    }

    /* Modal Content Container */
    .modal-content {
        border: none;
        border-radius: 16px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        overflow: hidden;
    }

    /* Modal Header - Gradient style matching your navbar */
    .modal-header {
        background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
        color: white;
        padding: 1.25rem 1.5rem;
        border-bottom: none;
        position: relative;
    }

    .modal-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #3498db 0%, #1abc9c 100%);
    }

    .modal-title {
        font-family: 'Poppins', sans-serif;
        font-weight: 600;
        font-size: 1.15rem;
        color: white;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    /* Optional: Add icon styling in modal titles */
    .modal-title i {
        font-size: 1.1rem;
        color: #1abc9c;
    }

    /* Close Button */
    .modal-header .btn-close {
        background-color: rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        width: 32px;
        height: 32px;
        opacity: 0.8;
        transition: all 0.2s ease;
        filter: brightness(0) invert(1);
    }

    .modal-header .btn-close:hover {
        background-color: rgba(255, 255, 255, 0.2);
        opacity: 1;
        transform: rotate(90deg);
    }

    /* Modal Body */
    .modal-body {
        padding: 1.75rem 1.5rem;
        background: #ffffff;
        color: #2c3e50;
    }

    /* Form Labels */
    .modal-body .form-label {
        color: #2c3e50;
        font-weight: 600;
        font-size: 0.9rem;
        margin-bottom: 0.5rem;
        letter-spacing: 0.3px;
    }

    /* Form Inputs & Selects */
    .modal-body .form-control,
    .modal-body .form-select {
        border: 1.5px solid #e0e0e0;
        border-radius: 10px;
        padding: 0.65rem 1rem;
        font-size: 0.95rem;
        transition: all 0.2s ease;
        background-color: #f8f9fa;
    }

    .modal-body .form-control:focus,
    .modal-body .form-select:focus {
        border-color: #3498db;
        box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.15);
        background-color: white;
    }

    /* Form Text / Helper Text */
    .modal-body .form-text {
        font-size: 0.85rem;
        color: #7f8c8d;
        margin-top: 0.4rem;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .modal-body .form-text i {
        color: #95a5a6;
        font-size: 0.8rem;
    }

    /* Modal Footer */
    .modal-footer {
        padding: 1rem 1.5rem;
        background: #f8f9fa;
        border-top: 1px solid #e9ecef;
        gap: 10px;
    }

    /* Buttons in Modal */
    .modal-footer .btn {
        border-radius: 10px;
        padding: 0.6rem 1.5rem;
        font-weight: 600;
        font-size: 0.95rem;
        transition: all 0.25s ease;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    /* Cancel/Close Button */
    .modal-footer .btn-secondary {
        background: #95a5a6;
        color: white;
    }

    .modal-footer .btn-secondary:hover {
        background: #7f8c8d;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(149, 165, 166, 0.3);
    }

    /* Primary Action Button */
    .modal-footer .btn-primary {
        background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
        color: white;
        box-shadow: 0 4px 12px rgba(52, 152, 219, 0.25);
    }

    .modal-footer .btn-primary:hover {
        background: linear-gradient(135deg, #2980b9 0%, #21618c 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(52, 152, 219, 0.35);
    }

    /* Danger/Delete Button */
    .modal-footer .btn-danger {
        background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
        color: white;
        box-shadow: 0 4px 12px rgba(231, 76, 60, 0.25);
    }

    .modal-footer .btn-danger:hover {
        background: linear-gradient(135deg, #c0392b 0%, #a93226 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(231, 76, 60, 0.35);
    }

    /* Success Button (if used) */
    .modal-footer .btn-success {
        background: linear-gradient(135deg, #1abc9c 0%, #16a085 100%);
        color: white;
        box-shadow: 0 4px 12px rgba(26, 188, 156, 0.25);
    }

    .modal-footer .btn-success:hover {
        background: linear-gradient(135deg, #16a085 0%, #138d75 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(26, 188, 156, 0.35);
    }

    /* Delete/Warning Messages in Modal Body */
    .modal-body p.text-danger {
        background: #fff5f5;
        border-left: 4px solid #e74c3c;
        padding: 0.75rem 1rem;
        border-radius: 8px;
        margin-top: 1rem;
        font-size: 0.9rem;
    }

    /* ==========================================
   COMPACT CARD LAYOUTS (Professional Grid)
   ========================================== */

/* Games (L1) - Compact Grid Cards */
.game-card-compact {
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 20px;
    transition: all 0.3s ease;
    height: 100%;
    position: relative;
    overflow: hidden;
}

.game-card-compact::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: #e9ecef;
    transition: 0.3s;
}

.game-icon-compact {
    width: 45px;
    height: 45px;
    border-radius: 10px;
    background: rgba(26, 188, 156, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.game-icon-compact img {
    width: 24px;
    height: 24px;
    object-fit: contain;
}

/* Events (L2) - Medium Cards */
.event-card-compact {
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 18px;
    transition: all 0.3s ease;
    height: 100%;
}

.event-card-compact:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.06);
    border-color: #3498db;
}

/* Categories (L3) - List-Style Cards */
.category-card-compact {
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: 16px 20px;
    transition: all 0.2s ease;
}

.category-card-compact:hover {
    border-color: #dee2e6;
    box-shadow: 0 4px 12px rgba(0,0,0,0.04);
}

/* Compact Manager Badges */
.manager-badge-sm {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 4px 10px;
    background: #f8f9fa;
    border-radius: 20px;
    border: 1px solid #e9ecef;
    font-size: 0.85rem;
}

.manager-avatar-sm {
    width: 24px;
    height: 24px;
    background: #2c3e50;
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    font-weight: bold;
}

.manager-badge-sm.manager-unassigned {
    background: #fff3cd;
    color: #856404;
    border-color: #ffeeba;
}

/* Compact Action Buttons */
.btn-group-compact {
    display: flex;
    gap: 6px;
}

.btn-icon-sm {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    background: #f1f3f5;
    color: #495057;
    font-size: 0.8rem;
    transition: 0.2s;
    cursor: pointer;
}

.btn-icon-sm:hover {
    background: #e9ecef;
    transform: scale(1.1);
}

.btn-icon-sm.edit:hover {
    background: rgba(13, 202, 240, 0.15);
    color: #0dcaf0;
}

.btn-icon-sm.delete:hover {
    background: rgba(220, 53, 69, 0.15);
    color: #dc3545;
}

.btn-icon-sm.assign:hover {
    background: rgba(25, 135, 84, 0.15);
    color: #198754;
}

/* Mobile Optimization for New Cards */
@media (max-width: 767.98px) {
    .game-card-compact,
    .event-card-compact {
        padding: 15px;
    }
    
    .category-card-compact {
        padding: 12px 15px;
    }
    
    .btn-group-compact {
        flex-wrap: wrap;
        justify-content: flex-end;
    }
}

    .modal-body p.text-danger i {
        color: #e74c3c;
        margin-right: 8px;
    }

    /* Confirmation Text Styling */
    .modal-body p {
        color: #34495e;
        font-size: 1rem;
        line-height: 1.6;
    }

    .modal-body p strong {
        color: #2c3e50;
        font-weight: 700;
        background: #f8f9fa;
        padding: 2px 8px;
        border-radius: 4px;
    }

    /* Responsive Modal */
    @media (max-width: 576px) {
        .modal-dialog {
            margin: 0.5rem;
        }
        
        .modal-header {
            padding: 1rem 1.25rem;
        }
        
        .modal-title {
            font-size: 1rem;
        }
        
        .modal-body {
            padding: 1.25rem 1rem;
        }
        
        .modal-footer {
            padding: 0.875rem 1rem;
            flex-direction: column;
        }
        
        .modal-footer .btn {
            width: 100%;
            justify-content: center;
        }
    }

    /* Animation for modal entrance */
    @keyframes modalSlideDown {
        from {
            transform: translateY(-50px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .modal.show .modal-dialog {
        animation: modalSlideDown 0.3s ease-out;
    }

    /* Focus styles for accessibility */
    .modal-body .form-control:focus,
    .modal-body .form-select:focus {
        outline: none;
    }

    .modal-footer .btn:focus {
        outline: 2px solid;
        outline-offset: 2px;
    }

    .modal-footer .btn-primary:focus {
        outline-color: #3498db;
    }

    .modal-footer .btn-danger:focus {
        outline-color: #e74c3c;
    }

    .modal-footer .btn-secondary:focus {
        outline-color: #95a5a6;
    }

    /* =========================================
   MOBILE OPTIMIZATION (Sports Director)
   ========================================= */
@media (max-width: 991.98px) {
    
    /* 1. COMPACT NAVBAR & LAYOUT */
    .navbar {
        padding: 0.5rem 1rem !important;
        height: 60px !important; /* Fixed compact height */
        display: flex !important;
        flex-wrap: nowrap !important;
        align-items: center !important;
    }
    
    /* A. LEFT: Toggler Button */
    .navbar-toggler {
        order: 1 !important; /* First item */
        border: 1px solid rgba(255,255,255,0.1);
        padding: 4px 8px;
        font-size: 1.2rem;
        margin-right: 10px !important;
    }
    .navbar-toggler:focus { box-shadow: none; }

    /* B. LEFT/CENTER: Brand Logo */
    /* margin-right: auto PUSHES the Profile Icon to the far right */
    .navbar-brand {
        order: 2 !important; /* Second item */
        margin-right: auto !important; /* THE KEY SPACER */
        display: flex;
        align-items: center;
        max-width: 60%;
    }
    .navbar-brand img {
        height: 30px !important;
        width: 30px !important;
        margin-right: 8px !important;
    }
    .navbar-brand strong {
        font-size: 0.95rem !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .navbar-brand small { display: none !important; }

    /* C. RIGHT: Profile Menu Icon */
    .user-dropdown {
        order: 3 !important; /* Third item */
        margin-left: 0 !important; 
        position: relative;
    }
    .user-dropdown .user-name { display: none !important; } /* Hide Name */
    
    /* Icon Styling */
    .user-dropdown .dropdown-toggle i { 
        font-size: 26px !important; 
        margin: 0 !important;
        color: #fff; /* Ensure visibility */
        cursor: pointer;
    }

    /* Order 3: Brand Logo */
    .navbar-brand {
        order: 3 !important;
        display: flex;
        align-items: center;
        max-width: 55%; /* Adjust width to prevent overflow */
        margin-right: 0 !important;
    }
    .navbar-brand img {
        height: 30px !important;
        width: 30px !important;
        margin-right: 8px !important;
    }
    .navbar-brand strong {
        font-size: 0.95rem !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .navbar-brand small { display: none !important; }
    
    /* Compact Profile Menu */
    .user-dropdown .user-name { display: none !important; }
    .user-dropdown .dropdown-toggle i { font-size: 28px !important; margin: 0 !important; }

    /* 2. SIDEBAR DRAWER (Fix Gap & Animation) */
    .sidebar {
        position: fixed !important;
        top: 60px !important; /* Matches Navbar Height */
        left: -260px !important; /* Hidden */
        width: 260px !important;
        height: calc(100vh - 60px) !important;
        background-color: #2c3e50 !important;
        box-shadow: 5px 0 15px rgba(0,0,0,0.3);
        transition: left 0.3s ease-in-out !important;
        z-index: 1045;
        overflow-y: auto;
    }
    .sidebar.show { left: 0 !important; } /* Slide In */
    
    /* Prevent text cutoff in menu */
    .sidebar-nav .nav-link { 
        white-space: nowrap; 
        font-size: 0.95rem;
    }

    /* 3. MAIN CONTENT ADJUSTMENTS */
    .main-content {
        padding: 15px !important;
        margin-top: 60px !important;
        margin-left: 0 !important;
    }
}/* 1. SWIPEABLE TABS (Like an App) */
    .nav-pills-custom {
        flex-wrap: nowrap !important;
        overflow-x: auto !important;
        overflow-y: hidden;
        white-space: nowrap;
        padding-bottom: 10px;
        -webkit-overflow-scrolling: touch;
        gap: 10px; /* Space between tabs */
    }
    .nav-pills-custom::-webkit-scrollbar { display: none; } /* Hide scrollbar */

    /* 2. STACKED CONTROLS (Search + Add Button) */
    .content-controls {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 15px !important;
    }
    .content-controls .search-wrapper {
        width: 100% !important;
        margin-bottom: 0 !important;
    }
    .content-controls .btn {
        width: 100% !important; /* Full width button for easy tapping */
        padding: 12px !important;
    }

    /* 3. TRANSFORM TABLES TO CARDS (The Professional Look) */
    .modern-table {
        display: block;
        width: 100%;
    }
    
    .modern-table thead {
        display: none; /* Hide headers on mobile */
    }
    
    .modern-table tbody, 
    .modern-table tr, 
    .modern-table td {
        display: block;
        width: 100%;
    }
    
    .modern-table tr {
        margin-bottom: 20px;
        border: 1px solid #e0e0e0;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 4px 10px rgba(0,0,0,0.03);
        overflow: hidden; /* Keeps borders rounded */
    }

    /* Cell Styles */
    .modern-table td {
        text-align: left;
        padding: 12px 15px !important;
        border-bottom: 1px solid #f0f0f0;
        position: relative;
    }

    /* First Cell (Name/Title) - Make it look like a Card Header */
    .modern-table td:first-child {
        background-color: #f8f9fa;
        font-weight: 700;
        font-size: 1.1rem;
        color: #2c3e50;
        border-bottom: 2px solid #e9ecef;
    }

    /* Last Cell (Actions) - Align buttons nicely */
    .modern-table td:last-child {
        border-bottom: none;
        background-color: #fff;
        display: flex;
        justify-content: flex-end; /* Float buttons right */
        gap: 10px;
        padding-top: 15px !important;
        padding-bottom: 15px !important;
    }
    
    /* Adjust specific text inside cells for mobile */
    .modern-table td span.text-secondary {
        display: block;
        font-size: 0.85rem;
        margin-top: 2px;
    }
    
    /* Manager Badge Adjustment */
    .manager-badge {
        width: 100%;
        justify-content: flex-start; /* Left align manager info */
    }

    /* Help Button Style */
.btn-guide {
    background: #f1f5f9;
    color: #475569;
    border: none;
    padding: 10px 20px;
    border-radius: 50px;
    font-weight: 600;
    font-size: 0.9rem;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
}
.btn-guide:hover {
    background: #e2e8f0;
    color: #1e293b;
    transform: translateY(-2px);
}

</style>
</head>
<body>
    
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="sports_director_dashboard.php">
                <img src="../images/PIT.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white" style="font-size: 1.25rem;">PIT SIGLAKAS MEDAL TALLY</strong>
                    <small class="text-light" style="font-size: 0.75rem;">Sports Director Panel</small>
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
                    <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events </span>
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
    <div class="container-fluid">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <div class="page-header mb-4">
                <h1 class="section-title">
                    </i>Manage Events
                </h1>
            </div>
                <p class="text-muted mb-0">Configure games, events, and assign managers.</p>
            </div>

            <div class="page-header-actions">
                <button class="btn-guide" data-bs-toggle="modal" data-bs-target="#helpModal">
                    <i class="fas fa-book-open text-primary"></i> Guide: How to Setup Events?
                </button>
            </div>
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show mb-0 shadow-sm" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
        </div>

        <ul class="nav nav-pills-custom mb-4 d-flex align-items-center" id="eventTabs" role="tablist">
            
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="games-tab" data-bs-toggle="tab" data-bs-target="#games" type="button" role="tab">
                    <i class="fas fa-layer-group me-2"></i>Games
                </button>
            </li>
            
            <i class="fas fa-chevron-right text-muted mx-1 opacity-50" style="font-size: 0.9rem;"></i>
            
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="events-tab" data-bs-toggle="tab" data-bs-target="#events" type="button" role="tab">
                    <i class="fas fa-calendar-day me-2"></i>Events
                </button>
            </li>
            
            <i class="fas fa-chevron-right text-muted mx-1 opacity-50" style="font-size: 0.9rem;"></i>
            
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="categories-tab" data-bs-toggle="tab" data-bs-target="#categories" type="button" role="tab">
                    <i class="fas fa-tags me-2"></i>Categories & Divisions
                </button>
            </li>
            
        </ul>

        <div class="tab-content" id="eventTabsContent">
            
            <div class="tab-pane fade show active" id="games" role="tabpanel">
    
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-secondary m-0">Games</h5>
                    <button class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#addGameModal">
                        <i class="fas fa-plus me-2"></i> New Game
                    </button>
                </div>

                <div class="row g-3" id="gamesGrid">
    <?php if (empty($games)): ?>
        <div class="col-12">
            <div class="text-center text-muted py-5">
                <i class="fas fa-layer-group fa-3x mb-3 opacity-25"></i>
                <p class="mb-0">No game categories found.</p>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($games as $game): ?>
        <div class="col-lg-4 col-md-6 col-12">
            <div class="game-card-compact">
                <div class="d-flex align-items-start justify-content-between mb-3">
                    <div class="d-flex align-items-center flex-grow-1">
                        <div class="game-icon-compact me-3">
                            <img src="../images/trophy1.svg" alt="Game Icon">
                        </div>
                        <div>
                            <h6 class="mb-0 fw-bold text-dark"><?= htmlspecialchars($game['game_name']) ?></h6>
                            <small class="text-muted">Level 1 Category</small>
                        </div>
                    </div>
                    <div class="btn-group-compact">
                        <button class="btn-icon-sm edit" data-bs-toggle="modal" data-bs-target="#editGameModal"
                            data-game-id="<?= $game['game_id'] ?>" data-game-name="<?= htmlspecialchars($game['game_name']) ?>" title="Edit">
                            <i class="fas fa-pen"></i>
                        </button>
                        <button class="btn-icon-sm delete" data-bs-toggle="modal" data-bs-target="#deleteGameModal"
                            data-game-id="<?= $game['game_id'] ?>" data-game-name="<?= htmlspecialchars($game['game_name']) ?>" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
            </div>

            <div class="tab-pane fade" id="events" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="search-wrapper w-50">
                        <i class="fas fa-search"></i>
                        <input type="search" id="searchEvents" class="form-control" placeholder="Search specific events or managers...">
                    </div>
                    <button class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#addEventModal">
                        <i class="fas fa-plus me-2"></i> New Event
                    </button>
                </div>

                <div class="card shadow-sm border-0 rounded-3">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light text-secondary">
                                    <tr>
                                        <th class="ps-4 py-3" style="width: 35%;">Event Name</th>
                                        <th class="py-3 text-center" style="width: 20%;">Medal Counts</th>
                                        <th class="py-3" style="width: 25%;">Assigned Manager</th>
                                        <th class="text-end pe-4 py-3" style="width: 20%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="eventsGrid">
                                    <?php foreach ($events_with_managers as $event): ?>
                                        <tr class="event-row"> 
                                            <td class="ps-4 py-3">
                                                <div class="d-flex flex-column">
                                                    <span class="fw-bold text-dark fs-6"><?= htmlspecialchars($event['event_name']) ?></span>
                                                    <small class="text-muted mt-1">
                                                        <i class="fas fa-tag me-1 text-primary opacity-50"></i>
                                                        <?= htmlspecialchars($event['game_name']) ?>
                                                    </small>
                                                </div>
                                            </td>

                                            <td class="py-3 text-center align-middle">
                                                <?php if (!empty($event['fixed_medal_count'])): ?>
                                                    <span class="fw-bold text-dark fs-5"><?= htmlspecialchars($event['fixed_medal_count']) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted fst-italic">N/A</span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="py-3">
                                                <?php if($event['manager_name']): ?>
                                                    <div class="d-flex align-items-center">
                                                        <div class="manager-avatar-table text-white d-flex align-items-center justify-content-center me-2 shadow-sm" 
                                                            style="width: 32px; height: 32px; background-color: #2c3e50; border-radius: 50%; font-size: 12px; font-weight: bold;">
                                                            <?= strtoupper(substr($event['manager_name'], 0, 1)) ?>
                                                        </div>
                                                        <div>
                                                            <span class="d-block text-dark fw-medium small"><?= htmlspecialchars($event['manager_name']) ?></span>
                                                            
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark bg-opacity-25 border border-warning border-opacity-25 px-3 py-2 rounded-pill fw-normal">
                                                        <i class="fas fa-user-slash me-1"></i> Unassigned
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-end pe-4 py-3">
                                                <div class="btn-group">
                                                    <button class="btn btn-sm btn-light text-primary border me-1" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#assignManagerModal"
                                                            data-event-id="<?= $event['event_id'] ?>" 
                                                            data-event-name="<?= htmlspecialchars($event['event_name']) ?>"
                                                            data-user-id="<?= $event['user_id'] ?? '' ?>" 
                                                            title="Assign Manager">
                                                        <i class="fas fa-user-plus"></i>
                                                    </button>

                                                    <button class="btn btn-sm btn-light text-secondary border me-1" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editEventModal"
                                                            data-event-id="<?= $event['event_id'] ?>" 
                                                            data-event-name="<?= htmlspecialchars($event['event_name']) ?>"
                                                            data-game-id="<?= $event['game_id'] ?>" 
                                                            data-fixed-count="<?= htmlspecialchars($event['fixed_medal_count'] ?? '') ?>"
                                                            title="Edit">
                                                        <i class="fas fa-pen"></i>
                                                    </button>

                                                    <button class="btn btn-sm btn-light text-danger border" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#deleteEventModal"
                                                            data-event-id="<?= $event['event_id'] ?>" 
                                                            data-event-name="<?= htmlspecialchars($event['event_name']) ?>" 
                                                            title="Delete">
                                                        <i class="fas fa-trash"></i>
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

            <div class="tab-pane fade" id="categories" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="search-wrapper w-50">
                        <i class="fas fa-search"></i>
                        <input type="search" id="searchCategories" class="form-control" placeholder="Search categories...">
                    </div>
                    <button class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                        <i class="fas fa-plus me-2"></i> Add Event Category
                    </button>
                </div>
                
                <div class="row g-3" id="categoriesGrid">
                    <?php if (empty($categories)): ?>
                        <div class="col-12">
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-tags fa-3x mb-3 opacity-25"></i>
                                <p class="mb-0">No categories yet. These are specific divisions where medals are awarded (e.g., "Men's Division").</p>
                            </div>
                        </div>
                    <?php else: ?>
                    <?php foreach ($categories as $category): ?>
                        <div class="col-12 category-item">
                            <div class="category-card-compact">
                                <div class="row align-items-center">
                                    <div class="col-lg-4 col-md-6 mb-2 mb-lg-0">
                                        <?php 
                                            $catName = $category['category_name'];
                                            $divName = $category['division_name'] ?? '';
                                            $isDefault = ($catName === 'Single Division' || $catName === 'Open Division');
                                            $style = $isDefault ? 'text-muted fst-italic' : 'fw-bold text-dark';
                                        ?>
                                        <h6 class="mb-0 <?= $style ?>"><?= htmlspecialchars($catName) ?></h6>
                                        
                                        <?php if (!empty($divName)): ?>
                                            <small class="text-primary fw-bold"><?= htmlspecialchars($divName) ?></small>
                                        <?php else: ?>
                                            <small class="text-muted">No Division specified</small>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-lg-4 col-md-6 mb-2 mb-lg-0">
                                        <small class="text-muted d-block text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.5px;">
                                            <?= htmlspecialchars($category['game_name']) ?>
                                        </small>
                                        <span class="fw-semibold text-secondary"><?= htmlspecialchars($category['event_name']) ?></span>
                                    </div>
                                    
                                    <div class="col-lg-3 col-6 mb-2 mb-lg-0">
                                        <?php if($category['manager_name']): ?>
                                            <div class="manager-badge-sm">
                                                <div class="manager-avatar-sm"><?= substr($category['manager_name'], 0, 1) ?></div>
                                                <span class="small"><?= htmlspecialchars($category['manager_name']) ?></span>
                                            </div>
                                        <?php else: ?>
                                            <div class="manager-badge-sm manager-unassigned">
                                                <i class="fas fa-user-slash me-1"></i> Unassigned
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-lg-1 col-6 text-end">
                                        <div class="btn-group-compact">
                                            <button class="btn-icon-sm edit" data-bs-toggle="modal" data-bs-target="#editCategoryModal"
                                                data-category-id="<?= $category['category_id'] ?>" 
                                                data-category-name="<?= htmlspecialchars($category['category_name']) ?>"
                                                data-division-name="<?= htmlspecialchars($category['division_name'] ?? '') ?>" 
                                                data-event-id="<?= $category['event_id'] ?>">
                                                <i class="fas fa-pen"></i>
                                            </button>
                                            <button class="btn-icon-sm delete" data-bs-toggle="modal" data-bs-target="#deleteCategoryModal"
                                                data-category-id="<?= $category['category_id'] ?>" data-category-name="<?= htmlspecialchars($category['category_name']) ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

        <div class="modal fade" id="addGameModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-dark text-white px-4 pt-4 pb-3" style="border-bottom: none !important; box-shadow: none !important;">
                        <h5 class="modal-title fw-bold"><i class="fas fa-info-circle text-info me-2"></i>Tournament Setup Guide</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
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
                        <h5 class="modal-title"><i class="fas fa-plus-circle"></i>Add New Event</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="add_event">
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Select Game </label>
                                <select class="form-select" name="game_id" required>
                                    <option value="" disabled selected>-- Select Game --</option>
                                    <?php foreach ($games as $game): ?>
                                    <option value="<?= $game['game_id'] ?>"><?= htmlspecialchars($game['game_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Event Name</label>
                                <input type="text" class="form-control" name="event_name" placeholder="e.g. Basketball, Chess, 100m Dash" required>
                            </div>
                            
                            <div class="mb-3 border rounded p-3 bg-light">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input toggle-fixed-medal" type="checkbox" id="add_enable_fixed_medal">
                                    <label class="form-check-label fw-bold text-dark" for="add_enable_fixed_medal">Set A fixed Medal Count?</label>
                                </div>
                                <div class="fixed-medal-input-container mt-2" id="add_fixed_medal_container" style="display: none;">
                                    <label class="form-label text-muted small mb-1">Medal counts awarded per team (e.g., 5 for Basketball, 11 for Football)</label>
                                    <input type="number" class="form-control border-primary" name="fixed_medal_count" id="add_fixed_medal_count" min="1" placeholder="Enter a fixed medal count per team">
                                </div>
                                <small class="text-success d-block mt-2 fw-medium"><i class="fas fa-lock me-1"></i> Tournament managers will be locked into this exact medal count during submission.</small>
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
                        <h5 class="modal-title"><i class="fas fa-plus-circle"></i>Add Category/Division</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="add_category">
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Select Event</label>
                                <select class="form-select fw-medium" name="event_id" required>
                                    <option value="" disabled selected>-- Select Event --</option>
                                    
                                    <?php foreach ($grouped_events as $game_name => $game_events): ?>
                                        <optgroup label="<?= htmlspecialchars($game_name) ?>">
                                            
                                            <?php foreach ($game_events as $ev): ?>
                                                <option value="<?= $ev['event_id'] ?>">
                                                    <?= htmlspecialchars($ev['event_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                            
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="alert alert-info py-2 small mb-3 border-0 bg-light text-primary">
                                <strong>Please enter at least one detail below: Category, Division, or both.</strong>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Category Name</label>
                                <input type="text" class="form-control" id="add_category_name" name="category_name" placeholder="e.g. Singles, 100m Dash (Leave if Event has no Category)">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Division Name</label>
                                <input type="text" class="form-control" id="add_division_name" name="division_name" placeholder="e.g. Men's Division (Leave if Category has no Division)">
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
                        <h5 class="modal-title">Assign Tournament Manager</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="assign_manager">
                            <input type="hidden" name="event_id" id="assign_event_id">
                            <p>Assigning manager for: <strong id="assign_event_name" class="text-dark"></strong></p>
                            <div class="mb-3">
                                <label for="user_id" class="form-label fw-bold">Select Tournament Manager</label>
                                <select class="form-select" id="assign_user_id" name="user_id">
                                    <option value="0">-- Unassign --</option>
                                    
                                    <?php foreach ($event_managers as $manager): ?>
                                        <?php 
                                            // Check if this manager is busy
                                            $is_busy = in_array($manager['id'], $assigned_ids);
                                        ?>
                                        
                                        <option value="<?= $manager['id'] ?>" 
                                                <?= $is_busy ? 'disabled' : '' ?>
                                                
                                                class="<?= $is_busy ? 'text-muted fst-italic' : 'fw-bold text-dark' ?>">
                                            
                                            <?= htmlspecialchars($manager['full_name']) ?>
                                            <?= $is_busy ? ' (Already Assigned)' : '' ?>
                                        
                                        </option>
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
                        <h5 class="modal-title"><i class="fas fa-edit"></i>Edit Game Category</h5>
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
                        <h5 class="modal-title"><i class="fas fa-trash-alt"></i> Delete Game</h5>
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
                        <h5 class="modal-title"><i class="fas fa-edit"></i>Edit Event</h5>
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

                            <div class="mb-3 border rounded p-3 bg-light">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input toggle-fixed-medal" type="checkbox" id="edit_enable_fixed_medal" style="cursor: pointer;">
                                    <label class="form-check-label fw-bold text-dark" for="edit_enable_fixed_medal" style="cursor: pointer;">Set A fixed Medal Count?</label>
                                </div>
                                
                                <div class="fixed-medal-input-container mt-2" id="edit_fixed_medal_container" style="display: none;">
                                    
                                    <label class="form-label text-muted small mb-1">Medal counts awarded per team (e.g., 5 for Basketball, 11 for Football)</label>
                                    <input type="number" class="form-control border-primary" name="fixed_medal_count" id="edit_fixed_medal_count" min="1" placeholder="Enter a fixed medal count per team">

                                    <p class="text-success small fw-medium mb-2">
                                        <i class="fas fa-lock me-1"></i> Tournament managers will be locked into this exact medal count during submission.
                                    </p>
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
        
        <div class="modal fade" id="deleteEventModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-trash-alt"></i> Delete Event</h5>
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
                        <h5 class="modal-title"><i class="fas fa-edit"></i>Edit Category/Division</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="events.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="update_category">
                            <input type="hidden" name="category_id" id="edit_category_id">
                            
                            <div class="mb-3">
                                <label for="edit_event_id_select_cat" class="form-label fw-bold">Belongs to Event</label>
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
                                <label for="edit_category_name" class="form-label fw-bold">Event Category <span class="text-muted fw-normal">(Optional)</span></label>
                                <input type="text" class="form-control" id="edit_category_name" name="category_name" placeholder="Leave blank if Division only">
                            </div>
                            <div class="mb-3">
                                <label for="edit_division_name" class="form-label fw-bold">Division Name <span class="text-muted fw-normal">(Optional)</span></label>
                                <input type="text" class="form-control" id="edit_division_name" name="division_name" placeholder="Leave blank if Category only">
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
                        <h5 class="modal-title"><i class="fas fa-trash-alt"></i> Delete Division</h5>
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

        <div class="modal fade" id="helpModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content border-0 shadow-lg">
                    <div class="modal-header bg-dark text-white px-4 pt-4 pb-3" style="border-bottom: none !important; box-shadow: none !important;">
                        <h5 class="modal-title fw-bold "><i class="fas fa-info-circle me-2"></i>Tournament Setup Guide</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body p-4">
                        <p class="text-muted mb-4 border-bottom pb-3" style="font-size: 0.95rem;">
                            This module establishes the core structure of the tournament. As Sports Director, your primary role is to define top-level games, configure event rules, and delegate operational responsibilities to Tournament Managers.
                        </p>

                        <div class="d-flex align-items-start mb-4">
                            <div class="bg-secondary bg-opacity-10 text-secondary rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 me-3 mt-1" style="width: 45px; height: 45px;">
                                <h5 class="m-0 fw-bold">1</h5>
                            </div>
                            <div>
                                <h6 class="fw-bold text-dark mb-1">Establish Game Classifications <span class="badge bg-light text-secondary ms-2 border fw-normal">Games Tab</span></h6>
                                <p class="small text-muted mb-2">Create broad classifications grouping related sports.</p>
                                <p class="small text-dark fw-medium mb-0 bg-light p-2 rounded border border-light"><i class="fas fa-layer-group text-secondary me-2"></i><em>Examples: Athletics, Ball Games, or Racket Games.</em></p>
                            </div>
                        </div>

                        <div class="d-flex align-items-start mb-4">
                            <div class="bg-secondary bg-opacity-10 text-secondary rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 me-3 mt-1" style="width: 45px; height: 45px;">
                                <h5 class="m-0 fw-bold">2</h5>
                            </div>
                            <div>
                                <h6 class="fw-bold text-dark mb-1">Create Events & Configure Rules <span class="badge bg-light text-secondary ms-2 border fw-normal">Events Tab</span></h6>
                                <p class="small text-muted mb-2">Define specific sporting events and link them to respective classifications.</p>
                                <div class="bg-light border rounded p-3 shadow-sm">
                                    <p class="small text-secondary mb-0 fw-medium">
                                        <i class="fas fa-lock me-1"></i> <strong>Configuration Note:</strong> Utilize the "Fixed Medal Count" toggle for team sports. This ensures the system automatically awards the correct multiplier of medals (e.g., 5 medals for a Basketball team win) to prevent calculation discrepancies.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex align-items-start mb-4">
                            <div class="bg-secondary bg-opacity-10 text-secondary rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 me-3 mt-1" style="width: 45px; height: 45px;">
                                <h5 class="m-0 fw-bold">3</h5>
                            </div>
                            <div>
                                <h6 class="fw-bold text-dark mb-1">Assign Tournament Managers <span class="badge bg-light text-secondary ms-2 border fw-normal">Events Tab</span></h6>
                                <p class="small text-muted mb-0">Click the <span class="badge bg-light text-dark border px-2"><i class="fas fa-user-plus"></i></span> icon to assign an authorized manager. <strong>This Assigns responsibility of setting up specific divisions, conducting the match, and uploading verified tally sheets directly to them.</strong></p>
                            </div>
                        </div>

                        <div class="d-flex align-items-start">
                            <div class="bg-light text-muted rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 me-3 mt-1 border" style="width: 45px; height: 45px;">
                                <h5 class="m-0 fw-bold">4</h5>
                            </div>
                            <div>
                                <h6 class="fw-bold text-dark mb-1">Pre-define Divisions <span class="badge bg-light text-secondary ms-2 border fw-normal">Optional (Categories Tab)</span></h6>
                                <p class="small text-muted mb-2">Since assigned Tournament Managers configure their own brackets, this step is optional for the Sports Director.</p>
                                <p class="small text-muted mb-0 fst-italic"><i class="fas fa-info-circle me-1"></i> If necessary, this tab permits pre-configuring specific divisions (e.g., "Men's Division") or categories on their behalf.</p>
                            </div>
                        </div>

                    </div>
                    <div class="modal-footer border-top-0 pt-0">
                        <button type="button" class="btn btn-secondary px-4 rounded-pill" data-bs-dismiss="modal">Got it</button>
                    </div>
                </div>
            </div>
        </div>

    </div> 
    
    <footer class="footer-main">
        <div class="container">
            <div class="row">
                <div class="col-lg-5 col-md-12 mb-4 mb-lg-0">
                    <div class="footer-logo-group">
                        <img src="../images/PIT.png" alt="Logo">
                        <img src="../images/Cote.png" alt="Logo">
                        <h5> PIT SILAKAS MEDAL TALLY</h5>
                    </div>
                    <p>The official live medal tallying system for the Palompon Institute of Technology. Bringing you real-time results, event schedules, and team standings.</p>
                </div>
                <div class="col-lg-3 col-md-6 mb-4 mb-md-0">
                    <h6>Quick Links</h6>
                    <ul class="footer-links">
                        <li><a href="home.php">Home (Standings)</a></li>
                        <li><a href="Eventpage.php">Events Schedule</a></li>
                        <li><a href="college_team.php">Teams & Rosters</a></li>
                    </ul>
                </div>
                <div class="col-lg-4 col-md-6">
                    <h6>Contact Us</h6>
                    <div style="color: rgba(255,255,255,0.7); font-size: 0.9rem; line-height: 1.6;">
                        <p class="mb-1 fw-bold text-white">Palompon Institute of Technology</p>
                        <p class="mb-2">Evangelista Street, Brgy. Guiwan II,<br>Palompon, Leyte 6538</p>
                        <p class="mb-0">
                            <i class="fas fa-phone-alt me-2"></i>(053) 555-9841<br>
                            <i class="fas fa-envelope me-2"></i>op@pit.edu.ph
                        </p>
                    </div>
                </div>
            </div>
            <div class="footer-bottom">
                <small>&copy; <?php echo date("Y"); ?> PIT SILAKAS MEDAL TALLY. All rights reserved.</small><br>
                <small>Developed by Jayvee Baybayon</small>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            
            // --- JS FEATURES ---
            
            // Updated Search for Grid Cards
            function setupGridSearch(inputId, gridId, itemClass) {
                const searchInput = document.getElementById(inputId);
                const grid = document.getElementById(gridId);
                
                if (!searchInput || !grid) return;

                // Create a "No Results" row dynamically for tables
                let noResultsRow = document.createElement('tr');
                noResultsRow.innerHTML = '<td colspan="100%" class="text-center py-4 text-muted">No matches found</td>';
                noResultsRow.style.display = 'none';
                noResultsRow.id = gridId + '-no-results';
                
                // Only append if it's a table body
                if (grid.tagName === 'TBODY') {
                    grid.appendChild(noResultsRow);
                }

                searchInput.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase();
                    // Default to 'tr' if it's a table, otherwise use the class provided
                    const selector = itemClass || (grid.tagName === 'TBODY' ? 'tr:not([id*="-no-results"])' : '[class*="-item"]');
                    const items = grid.querySelectorAll(selector);
                    
                    let hasVisibleItems = false;

                    items.forEach(item => {
                        const itemText = item.textContent.toLowerCase();
                        if (itemText.includes(searchTerm)) {
                            item.style.display = ''; // Show
                            hasVisibleItems = true;
                        } else {
                            item.style.display = 'none'; // Hide
                        }
                    });

                    // Toggle "No Results" message
                    if (grid.tagName === 'TBODY') {
                        noResultsRow.style.display = hasVisibleItems ? 'none' : 'table-row';
                    }
                });
            }

                // === UPDATE YOUR CALLS HERE ===

                // 1. For the new Table (Target the specific class we added)
                setupGridSearch('searchEvents', 'eventsGrid', '.event-row');

                // 2. Keep these the same (Assuming they are still Grid/Cards)
                setupGridSearch('searchCategories', 'categoriesGrid', '.category-item');

                // JS for Assign Manager Modal
                const assignModal = document.getElementById('assignManagerModal');
                if (assignModal) {
                    assignModal.addEventListener('show.bs.modal', function(event) {
                        const button = event.relatedTarget;
                        document.getElementById('assign_event_id').value = button.dataset.eventId;
                        document.getElementById('assign_event_name').textContent = button.dataset.eventName;
                        
                        const currentUserId = button.dataset.userId || '0';
                        const selectBox = document.getElementById('assign_user_id');

                        // 1. Reset: Ensure all options respect their HTML 'disabled' state first
                        Array.from(selectBox.options).forEach(opt => {
                            // If the text contains "(Already Assigned)", keep it disabled
                            if (opt.text.includes('(Already Assigned)')) {
                                opt.disabled = true;
                            }
                        });

                        // 2. UNLOCK current user: If the manager is assigned to THIS event, enable them
                        if (currentUserId !== '0') {
                            const currentOption = selectBox.querySelector(`option[value="${currentUserId}"]`);
                            if (currentOption) {
                                currentOption.disabled = false; // Allow selecting the current person
                            }
                        }

                        // 3. Set the value
                        selectBox.value = currentUserId;
                    });
                }

                // JS: Toggle Add Modal Input
                const addToggle = document.getElementById('add_enable_fixed_medal');
                const addContainer = document.getElementById('add_fixed_medal_container');
                const addInput = document.getElementById('add_fixed_medal_count');
                
                if(addToggle) {
                    addToggle.addEventListener('change', function() {
                        if (this.checked) {
                            addContainer.style.display = 'block';
                            addInput.required = true;
                        } else {
                            addContainer.style.display = 'none';
                            addInput.required = false;
                            addInput.value = ''; // Clear value if unchecked
                        }
                    });
                }

                // JS: Toggle Edit Modal Input
                const editToggle = document.getElementById('edit_enable_fixed_medal');
                const editContainer = document.getElementById('edit_fixed_medal_container');
                const editInput = document.getElementById('edit_fixed_medal_count');
                
                if(editToggle) {
                    editToggle.addEventListener('change', function() {
                        if (this.checked) {
                            editContainer.style.display = 'block';
                            editInput.required = true;
                        } else {
                            editContainer.style.display = 'none';
                            editInput.required = false;
                            editInput.value = ''; // Clear value if unchecked
                        }
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
                        
                        // NEW: Populate the Fixed Medal Count Toggle
                        const fixedCount = button.dataset.fixedCount;
                        if (fixedCount && fixedCount !== '') {
                            editToggle.checked = true;
                            editContainer.style.display = 'block';
                            editInput.value = fixedCount;
                            editInput.required = true;
                        } else {
                            editToggle.checked = false;
                            editContainer.style.display = 'none';
                            editInput.value = '';
                            editInput.required = false;
                        }
                    });
                }

                const deleteEventModal = document.getElementById('deleteEventModal');
                if (deleteEventModal) {
                    deleteEventModal.addEventListener('shown.bs.modal', function() {
                        // 1. Auto-Focus the Delete Button so "Enter" works immediately
                        const submitBtn = deleteEventModal.querySelector('button[type="submit"]');
                        if(submitBtn) submitBtn.focus();
                    });

                    deleteEventModal.addEventListener('show.bs.modal', function(event) {
                        const button = event.relatedTarget;
                        // 2. Populate the data
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
                        
                        // NEW: Populate the Division Name
                        editCategoryModal.querySelector('#edit_division_name').value = button.dataset.divisionName || '';
                        
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

                // ==========================================
                // 2. REAL-TIME BADGE UPDATER
                // ==========================================
                function updateSidebarBadges() {
                    fetch('../api_notifications.php?t=' + new Date().getTime())
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Update "Approve Results" (Yellow)
                                updateSingleBadge('results.php', data.pending_results, 'bg-warning text-dark');

                                // Update "Account Requests" (Red)
                                updateSingleBadge('Manage_Requests.php', data.pending_requests, 'bg-danger');
                            }
                        })
                        .catch(err => console.error('Badge update error:', err));
                }

                function updateSingleBadge(hrefKeyword, count, colorClasses) {
                    const link = document.querySelector(`.sidebar-nav .nav-link[href*="${hrefKeyword}"]`);
                    if (link) {
                        let badge = link.querySelector('.badge');
                        if (count > 0) {
                            if (!badge) {
                                badge = document.createElement('span');
                                link.appendChild(badge);
                            }
                            badge.className = `badge ${colorClasses} ms-auto rounded-pill`;
                            badge.textContent = count;
                        } else {
                            if (badge) badge.remove();
                        }
                    }
                }

                // Run Badges
                updateSidebarBadges();
                setInterval(updateSidebarBadges, 5000);

                // ==========================================
                // 3. CATEGORY & DIVISION VALIDATION
                // Ensures at least one field is filled before submitting
                // ==========================================
                
                const addCategoryForm = document.querySelector('#addCategoryModal form');
                if (addCategoryForm) {
                    addCategoryForm.addEventListener('submit', function(e) {
                        const catName = document.getElementById('add_category_name').value.trim();
                        const divName = document.getElementById('add_division_name').value.trim();
                        
                        if (catName === '' && divName === '') {
                            e.preventDefault(); // Stop form submission
                            alert('Please enter either a Category Name or a Division Name (or both) to continue.');
                        }
                    });
                }

                const editCategoryForm = document.querySelector('#editCategoryModal form');
                if (editCategoryForm) {
                    editCategoryForm.addEventListener('submit', function(e) {
                        const catName = document.getElementById('edit_category_name').value.trim();
                        const divName = document.getElementById('edit_division_name').value.trim();
                        
                        if (catName === '' && divName === '') {
                            e.preventDefault(); // Stop form submission
                            alert('Please enter either a Category Name or a Division Name (or both) to continue.');
                        }
                    });
                }
        });
    </script>
</body>
</html>