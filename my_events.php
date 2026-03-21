<?php
session_start();
require_once 'config.php'; // Your DB connection

// This file now defines render_event_list() and helper functions
require_once 'my_events_view.php'; 

// 1. SECURITY & ACCESS CONTROL
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Tournament Manager') {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Tournament Manager';
$current_page = basename($_SERVER['PHP_SELF']);

// --- NEW: FETCH FULL NAME FROM DB ---
// 1. Prepare a query to get the full_name and username for this specific ID
$stmt = $conn->prepare("SELECT full_name, username FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id); // Bind the logged-in user's ID
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc(); // Get the data as an array
$stmt->close();

// 2. Decide which name to display
// If 'full_name' is not empty, use it. Otherwise, fallback to 'username'.
if (!empty($user_data['full_name'])) {
    $display_name = $user_data['full_name'];
} else {
    $display_name = $user_data['username'] ?? $username; // Fallback
}

// 2. FORM HANDLING (Add/Edit/Delete Category)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $alert_message = '';
        $alert_type = 'success';
        
        $current_user_id = $user_id; 

        try {
            // --- Action: Save Category (Add or Update) ---
            if ($action === 'save_category') {
                $category_name = trim($_POST['category_name']);
                $division_name = trim($_POST['division_name'] ?? '');
                $status = $_POST['status'];
                $event_id = (int)$_POST['event_id'];
                $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
                
                // --- NEW: Fetch Parent Event Name for Detailed Logging ---
                $parent_event_name = "Unknown Event";
                $stmt_evt = $conn->prepare("SELECT event_name FROM game_events WHERE event_id = ?");
                $stmt_evt->bind_param("i", $event_id);
                $stmt_evt->execute();
                if ($row_evt = $stmt_evt->get_result()->fetch_assoc()) {
                    $parent_event_name = $row_evt['event_name'];
                }
                $stmt_evt->close();

                $event_time = !empty($_POST['event_time']) ? $_POST['event_time'] : null;
                $venue = trim($_POST['venue']);

                // Security Check: Verify this manager is assigned to this event
                $stmt_check = $conn->prepare("SELECT event_id FROM tournament_manager_assignments WHERE event_id = ? AND user_id = ?");
                $stmt_check->bind_param("ii", $event_id, $current_user_id);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows == 0) {
                    throw new Exception("Permission denied. You are not assigned to this event.");
                }
                $stmt_check->close();

                if (empty($category_id)) {
                    // --- THIS IS AN ADD OPERATION ---
                    
                    // SMART CHECK: Allow category_name to be empty ONLY IF division_name has text.
                    if ((empty($category_name) && empty($division_name)) || empty($status) || empty($event_id)) {
                        throw new Exception("Please provide either a Category Name or a Division Name.");
                    }
                    
                    $stmt = $conn->prepare(
                        "INSERT INTO categories (event_id, category_name, division_name, status, event_date, event_time, venue) 
                         VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    // Note: Changed "isssssss" to "issssss" (7 parameters now)
                    $stmt->bind_param("issssss", $event_id, $category_name, $division_name, $status, $event_date, $event_time, $venue);
                    $stmt->execute();
                    
                    $new_category_id = (int)$conn->insert_id; 
                    
                    try {
                        $context = [
                            'parent_event_name' => $parent_event_name,
                            'category_name' => $category_name,
                            'division_name' => $division_name,
                            'status' => $status,
                            'event_date' => $event_date,
                            'event_time' => $event_time,
                            'venue' => $venue
                        ];
                        log_activity($conn, $current_user_id, 'CREATED_CATEGORY', $new_category_id, 'category', $event_id, 'event', $context);
                    } catch (Exception $log_e) {
                        error_log("Failed to log CREATED_CATEGORY: " . $log_e->getMessage());
                    }
                    
                    // --- SMART USER FEEDBACK (ADD) ---
                    $display_parts = [];
                    $is_main = ($category_name === 'Main Event' || $category_name === 'Main Competition' || empty($category_name));
                    
                    if (!$is_main) $display_parts[] = $category_name;
                    if (!empty($division_name)) $display_parts[] = $division_name;

                    if (empty($display_parts)) {
                        $alert_message = "Great! The event has been initialized and is ready for results.";
                    } else {
                        $display_name = implode(' - ', $display_parts);
                        // Determine the specific type for the alert
$item_type = ($has_cat && !empty($division_name)) ? "category and division" : (!empty($division_name) ? "division" : "category");
$alert_message = "Success! You added a new $item_type: <strong>" . htmlspecialchars($display_name) . "</strong>.";
                    }
                    
                } else {
                    // --- THIS IS AN UPDATE OPERATION ---
                    if ((empty($category_name) && empty($division_name)) || empty($status) || empty($event_id)) {
                        throw new Exception("Please provide either a Category Name or a Division Name.");
                    }
                    
                    // 1. Fetch old data (You already have this)
                    $old_data_stmt = $conn->prepare("SELECT status, event_date, event_time, venue FROM categories WHERE category_id = ?");
                    $old_data_stmt->bind_param("i", $category_id);
                    $old_data_stmt->execute();
                    $old_data = $old_data_stmt->get_result()->fetch_assoc();
                    $old_data_stmt->close();
                    
                    // 2. FETCH THE PARENT EVENT NAME (Add this part if missing!)
                    $parent_event_name = "Unknown Event";
                    $stmt_evt = $conn->prepare("SELECT event_name FROM game_events WHERE event_id = ?");
                    $stmt_evt->bind_param("i", $event_id);
                    $stmt_evt->execute();
                    if ($row_evt = $stmt_evt->get_result()->fetch_assoc()) {
                        $parent_event_name = $row_evt['event_name'];
                    }
                    $stmt_evt->close();

                    $stmt = $conn->prepare(
                        "UPDATE categories SET category_name = ?, division_name = ?, status = ?, event_date = ?, event_time = ?, venue = ? 
                         WHERE category_id = ? AND event_id = ?"
                    );
                    $stmt->bind_param("ssssssii", $category_name, $division_name, $status, $event_date, $event_time, $venue, $category_id, $event_id);
                    $stmt->execute();
                    
                    // 3. UPDATE THE CONTEXT (The Sticky Note)
                    try {
                        $context = [
                            'parent_event_name' => $parent_event_name, // <--- CRITICAL FIX
                            'new_category_name' => $category_name,
                            'division_name'     => $division_name,     // <--- CRITICAL FIX
                            'old_status'        => $old_data['status'] ?? $status,
                            'new_status'        => $status,
                            'old_venue'         => $old_data['venue'] ?? '',
                            'new_venue'         => $venue,
                            'category_id'       => $category_id
                        ];
                        log_activity($conn, $current_user_id, 'UPDATED_CATEGORY', $category_id, 'category', $event_id, 'event', $context);
                    } catch (Exception $log_e) {
                        error_log("Failed to log UPDATED_CATEGORY: " . $log_e->getMessage());
                    }
                }
                $stmt->close();

            } 
            // --- Action: Delete Category ---
            elseif ($action === 'delete_category') {
                $category_id = (int)$_POST['category_id'];
                
                $stmt_check = $conn->prepare("
                    SELECT c.event_id, c.category_name, c.division_name 
                    FROM categories c
                    JOIN tournament_manager_assignments ema ON c.event_id = ema.event_id
                    WHERE c.category_id = ? AND ema.user_id = ? AND (c.status = 'Upcoming' OR c.status = 'Cancelled')
                ");
                $stmt_check->bind_param("ii", $category_id, $current_user_id);
                $stmt_check->execute();
                
                $result_check = $stmt_check->get_result(); 
                if ($result_check->num_rows == 0) {
                     throw new Exception("Permission denied or event is not 'Upcoming'.");
                }
                
                $category_data = $result_check->fetch_assoc();
                $deleted_category_name = $category_data['category_name'];
                $deleted_division_name = $category_data['division_name'] ?? ''; // <--- ADDED!
                
                // Fetch Parent Event Name
                $parent_event_name = "Unknown Event";
                $stmt_evt = $conn->prepare("SELECT event_name FROM game_events WHERE event_id = ?");
                $stmt_evt->bind_param("i", $category_data['event_id']);
                $stmt_evt->execute();
                if ($row_evt = $stmt_evt->get_result()->fetch_assoc()) {
                    $parent_event_name = $row_evt['event_name'];
                }
                $stmt_evt->close();
                $stmt_check->close();
                
                // --- THE MISSING LOGIC: Actually delete the category from the database ---
                $stmt_del = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
                $stmt_del->bind_param("i", $category_id);
                $stmt_del->execute();
                // -------------------------------------------------------------------------
                
                if ($stmt_del->affected_rows > 0) {
                    
                    try {
                        $context = [
                            'parent_event_name' => $parent_event_name,
                            'deleted_category_name' => $deleted_category_name,
                            'division_name' => $deleted_division_name
                        ];
                        log_activity($conn, $current_user_id, 'DELETED_CATEGORY', $category_id, 'category', null, null, $context);
                    } catch (Exception $log_e) {
                        error_log("Failed to log DELETED_CATEGORY: " . $log_e->getMessage());
                    }
                    
                    // USER-FRIENDLY DELETE MESSAGE
                    // --- SMART USER FEEDBACK (DELETE) ---
                    $display_parts = [];
                    $is_main = ($deleted_category_name === 'Main Event' || $deleted_category_name === 'Main Competition' || empty($deleted_category_name));

                    if (!$is_main) $display_parts[] = $deleted_category_name;
                    if (!empty($deleted_division_name)) $display_parts[] = $deleted_division_name;

                    if (empty($display_parts)) {
                        $alert_message = "Success! The event has been permanently removed.";
                    } else {
                        $final_deleted_name = implode(' - ', $display_parts);
                        $alert_message = "Success! <strong>" . htmlspecialchars($final_deleted_name) . "</strong> has been permanently removed.";
                    }
                } else {
                    throw new Exception("Could not delete category.");
                }
                $stmt_del->close();
            }
        } catch (Exception $e) { 
            $alert_message = "ERROR: " . $e->getMessage();
            $alert_type = 'danger';
            error_log($e->getMessage()); 
        }
        
        if (!empty($alert_message)) {
            $_SESSION['alert_message'] = $alert_message;
            $_SESSION['alert_type'] = $alert_type;
        }
        
        header("Location: my_events.php");
        exit();
    }
}

// Check for session-based alerts
$alert_message = '';
$alert_type = 'success';
if (isset($_SESSION['alert_message'])) {
    $alert_message = $_SESSION['alert_message'];
    $alert_type = $_SESSION['alert_type'];
    unset($_SESSION['alert_message']);
    unset($_SESSION['alert_type']);
}

// 3. FETCH DATA FOR DISPLAY
// A. Fetch all Colleges
$colleges = [];
$college_map = []; // For quick lookup
try {
    $college_stmt = $conn->prepare("SELECT college_id, college_name FROM colleges ORDER BY college_name");
    $college_stmt->execute(); 
    $result = $college_stmt->get_result();
    $colleges = $result->fetch_all(MYSQLI_ASSOC);
    foreach ($colleges as $college) {
        $college_map[$college['college_id']] = $college['college_name'];
    }
    $college_stmt->close();
} catch (Exception $e) { 
    if(empty($alert_message)) { 
        $alert_message = "ERROR: Could not load colleges list.";
        $alert_type = 'danger';
    }
}

// B. Fetch this Manager's ASSIGNED Events & Categories
$managed_data = [];
$results = [];
try {
    $sql = "SELECT
        g.game_name,
        ge.event_id, 
        ge.event_name,
        ge.event_structure,
        c.category_id, c.category_name, c.division_name,
        c.status,
        c.event_date, c.event_time, c.venue,
        c.gold_winner_college_id, c.gold_count,
        c.silver_winner_college_id, c.silver_count,
        c.bronze_winner_college_id, c.bronze_count,
        c.notes
    FROM tournament_manager_assignments ema
    JOIN game_events ge ON ema.event_id = ge.event_id
    JOIN games g ON ge.game_id = g.game_id
    LEFT JOIN categories c ON ge.event_id = c.event_id
    WHERE ema.user_id = ?
    ORDER BY g.game_name, ge.event_name, c.category_name";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id); 
    $stmt->execute(); 
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // C. Process and Structure Data
    if ($results) {
        foreach ($results as $row) {
            $game_name = $row['game_name'];
            $event_id = $row['event_id'];
            $event_name = $row['event_name'];

            if (!isset($managed_data[$game_name])) {
                $managed_data[$game_name] = [];
            }
            if (!isset($managed_data[$game_name][$event_id])) {
                $managed_data[$game_name][$event_id] = [
                    'event_id' => $event_id,
                    'event_name' => $event_name,
                    'event_structure' => $row['event_structure'], /* <--- Capture the data */
                    'categories' => []
                ];
            }
            if ($row['category_id']) {
                $managed_data[$game_name][$event_id]['categories'][] = $row;
            }
        }
    }
    foreach ($managed_data as $game_name => $events_by_id) {
        $managed_data[$game_name] = array_values($events_by_id);
    }
} catch (Exception $e) { 
    if(empty($alert_message)) {
        $alert_message = "ERROR: Could not load your assigned events. " . $e->getMessage();
        $alert_type = 'danger';
    }
}


// --- 4. HANDLE PARTIAL/AJAX REQUESTS ---
// This block checks if the request is from our JavaScript (fetchEventUpdates)
if (isset($_GET['partial']) && $_GET['partial'] == '1') {
    
    // 1. Re-check session for security on AJAX requests
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'Tournament Manager') {
        http_response_code(401); // Unauthorized
        // Send a message that the JavaScript can display
        echo '<div class="alert alert-danger">Your session has expired. Please <a href="login.php" class="alert-link">log in again</a>.</div>';
        exit();
    }
    
    // 2. Render ONLY the event list HTML using the function from my_events_view.php
    render_event_list($managed_data, $college_map);
    
    // 3. Stop the script from rendering the rest of the page
    exit();
}


// --- 5. PREPARE DATA FOR FULL PAGE LOAD ---
// (Helper functions were moved to my_events_view.php)

// Status options for the modal dropdown
$status_options = [
    'Upcoming',
    'Ongoing',
    'Postponed',
    'Cancelled',
    'Completed (Pending Results)'
];

// --- NEW: SMART VENUE SUGGESTIONS ---
// 1. Your predefined official list of venues
$official_venues = [
    "Bahay Alumni Hall",
    "Basketball Court",
    "CAS Inner Ground",
    "Gymnasium/Communicators Road",
    "New Highschool Court",
    "OSDS Bldg. floor",
    "PIT CHIC",
    "PIT Cyber Library",
    "PIT Oval Ground",
    "Tennis Court",
    "Volleyball Court"
];

// 2. Fetch any *other* custom venues managers might have typed into the DB
$db_venues = [];
try {
    $venue_stmt = $conn->query("SELECT DISTINCT venue FROM categories WHERE venue IS NOT NULL AND venue != ''");
    if ($venue_stmt) {
        while ($v_row = $venue_stmt->fetch_assoc()) {
            $db_venues[] = trim($v_row['venue']);
        }
    }
} catch (Exception $e) { }

// 3. Merge both lists, remove any duplicates, and sort alphabetically!
$all_venues = array_unique(array_merge($official_venues, $db_venues));
sort($all_venues);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assigned Events - PIT Tallying</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
    /* =========================================
       1. CORE VARIABLES & SETUP
       ========================================= */
    :root { 
        --sidebar-width: 260px; 
        --header-height: 82px; 
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
        --card-shadow: 0 4px 12px rgba(0, 0, 0, 0.05); 
        --bg-light: #f4f6f8; /* Director's clean gray */
        --text-dark: #2c3e50;
        --accent-color: #1abc9c;
        --primary-gradient: linear-gradient(135deg, #2c3e50 0%, #4ca1af 100%);
    }

    body { 
        background-color: var(--bg-light); 
        margin: 0; 
        padding: 0; 
        min-height: 100vh; 
        font-family: 'Inter', sans-serif; 
        display: flex; 
        flex-direction: column; 
    }

    /* Remove the old glassmorphism background effect */
    /* body::before { display: none; } */

    /* =========================================
       2. NAVBAR & SIDEBAR (Director Style)
       ========================================= */
    
    /* Navbar */
    .navbar { 
        background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; 
        box-shadow: 0 4px 20px rgba(0,0,0,0.15); 
        padding: 1rem 1.5rem; 
        height: var(--header-height); 
        position: fixed; 
        top: 0; left: 0; right: 0; 
        z-index: 1050; 
        border-bottom: none;
    }

    .user-dropdown .dropdown-toggle { 
        color: white; 
        display: flex; align-items: center; 
        text-decoration: none; 
        padding: 8px 12px; 
        border-radius: 8px; 
        transition: var(--transition);
        background: transparent; 
        border: none;
    }
    .user-dropdown .dropdown-toggle:hover { 
        background-color: rgba(255, 255, 255, 0.1); 
    }
    .user-dropdown .dropdown-toggle img { 
        width: 36px; height: 36px; 
        border-radius: 50%; object-fit: cover; margin-right: 10px; 
        border: none;
    }

    /* --- CLEAN HEADER CARD STYLE --- */
.page-header {
    background: white;
    border-radius: 16px;
    padding: 24px 30px;
    margin-bottom: 30px;
    box-shadow: var(--card-shadow);
    border: 1px solid rgba(0,0,0,0.05);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
}

.page-header-content nav {
    margin-bottom: 5px;
}

.page-header-content h2 {
    font-family: 'Inter', sans-serif;
    font-weight: 800;
    font-size: 1.75rem;
    color: var(--text-dark);
    margin: 0;
    letter-spacing: -0.5px;
}

.page-header-content p {
    color: #64748b;
    margin: 5px 0 0 0;
    font-size: 0.95rem;
}

/* Breadcrumb Tweaks */
.breadcrumb-item a { color: #64748b; text-decoration: none; font-weight: 500; font-size: 0.85rem; }
.breadcrumb-item.active { font-size: 0.85rem; color: #94a3b8; }
.breadcrumb-item + .breadcrumb-item::before { color: #cbd5e1; }

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

    /* Sidebar */
    .sidebar { 
        width: var(--sidebar-width); 
        position: fixed; 
        top: var(--header-height); 
        left: 0; 
        height: calc(100vh - var(--header-height)); 
        background: #2c3e50; /* Solid Dark Blue */
        color: white; 
        box-shadow: 5px 0 15px rgba(0,0,0,0.2); 
        z-index: 1040; 
        transition: width var(--transition); 
        overflow-y: auto; 
    }

    .sidebar-nav { padding: 20px 0; }
    .sidebar-nav .nav-link { 
        color: rgba(255, 255, 255, 0.7); 
        font-size: 1.05rem; 
        font-weight: 500; 
        padding: 12px 25px; 
        transition: var(--transition); 
        border-left: 5px solid transparent; 
        margin: 2px 0; 
        display: flex; align-items: center; 
        text-decoration: none; 
    }
    .sidebar-nav .nav-link i { 
        width: 30px; text-align: center; flex-shrink: 0; font-size: 0.95em; margin-right: 10px;
    }
    .sidebar-nav .nav-link:hover { 
        color: white; 
        background: rgba(255, 255, 255, 0.05); 
        border-left-color: var(--accent-color); 
    }
    .sidebar-nav .nav-link.active { 
        color: white; 
        background: rgba(255, 255, 255, 0.1); 
        border-left-color: #3498db; 
        font-weight: 600; 
    }

    /* Main Content */
    .main-content { 
        flex: 1 0 auto; 
        padding: 30px; 
        margin-top: var(--header-height); 
        margin-left: var(--sidebar-width); 
        transition: margin-left var(--transition); 
        min-height: calc(100vh - var(--header-height)); 
    }

    /* Footer */
    footer {
        flex-shrink: 0;
        box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
        padding-left: var(--sidebar-width);
        transition: padding-left var(--transition);
        position: relative;
        z-index: 1041;
    }
    /* Main Footer Styles */
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
    .footer-main p { font-size: 0.9rem; max-width: 400px; }
    .footer-main h6 {
        font-family: 'Poppins', sans-serif;
        color: #fff;
        font-weight: 600;
        margin-bottom: 1rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .footer-main .footer-links { list-style: none; padding: 0; }
    .footer-main .footer-links li { margin-bottom: 0.5rem; }
    .footer-main .footer-links a { text-decoration: none; color: rgba(255,255,255,0.7); transition: var(--transition); }
    .footer-main .footer-links a:hover { color: #fff; padding-left: 5px; }
    .footer-bottom {
        border-top: 1px solid rgba(255,255,255,0.1);
        padding-top: 1.5rem;
        margin-top: 2rem;
        text-align: center;
        font-size: 0.85rem;
    }

    /* Mobile Tweaks */
    @media (max-width: 991px) {
        .footer-main { text-align: center; }
        .footer-main .footer-logo-group { justify-content: center; }
        .footer-main .row > div { margin-bottom: 2rem; }
        .footer-main .row > div:last-child { margin-bottom: 0; }
    }

    @media (max-width: 992px) { 
        .sidebar { left: -260px; } 
        .sidebar.show { left: 0; } 
        .main-content, footer { margin-left: 0; } 
    }

    /* Responsive */
    @media (max-width: 992px) {
        .sidebar { left: -260px; }
        .sidebar.show { left: 0; }
        .main-content, footer { margin-left: 0; }
    }

    /* =========================================
       3. MODERN EVENT CARDS (New Interface)
       ========================================= */
    
    /* The Main Event Card */
    .event-card-modern {
        background: white;
        border-radius: 16px;
        border: 1px solid rgba(0,0,0,0.05);
        box-shadow: var(--card-shadow);
        margin-bottom: 20px;
        overflow: hidden;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .event-card-modern:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    }

    /* Card Header */
    .event-card-header {
        padding: 20px;
        border-bottom: 1px solid #f1f3f5;
        display: flex;
        align-items: center;
        gap: 15px;
        background: white;
    }

    /* Icon Box */
    .event-icon-box {
        width: 50px; height: 50px;
        border-radius: 12px;
        background: rgba(26, 188, 156, 0.1); /* Light Teal */
        color: var(--accent-color);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.5rem;
        flex-shrink: 0;
    }

    /* Category Rows */
    .category-row {
        padding: 15px 20px;
        border-bottom: 1px solid #f8f9fa;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: background 0.2s;
    }
    .category-row:hover {
        background: #fdfdfd;
    }
    .category-row:last-child { 
        border-bottom: none; 
    }

    /* Status Badges */
    .badge-modern {
        font-weight: 500;
        padding: 5px 12px;
        border-radius: 30px;
        font-size: 0.75rem;
        border: 1px solid transparent;
    }
    .badge-modern.upcoming { background: #f1f3f5; color: #6c757d; border-color: #e9ecef; }
    .badge-modern.ongoing { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
    .badge-modern.completed { background: #fff7ed; color: #9a3412; border-color: #ffedd5; }

    /* Bootstrap Overrides for Cleaner Look */
    .btn-outline-primary {
        color: var(--accent-color);
        border-color: var(--accent-color);
    }
    .btn-outline-primary:hover {
        background-color: var(--accent-color);
        border-color: var(--accent-color);
        color: white;
    }
    
    .form-control, .form-select {
        border-radius: 8px;
        border: 1px solid #dee2e6;
        padding: 0.6rem 1rem;
    }
    .form-control:focus {
        border-color: var(--accent-color);
        box-shadow: 0 0 0 0.2rem rgba(26, 188, 156, 0.25);
    }
    
    .modal-content {
        border-radius: 16px;
        border: none;
        box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    }
    .modal-header {
        background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
        color: white;
        border-radius: 16px 16px 0 0;
    }
    .modal-header .btn-close { filter: brightness(0) invert(1); }

    /* =========================================
   MOBILE OPTIMIZATION (My Events Page)
   ========================================= */
@media (max-width: 991.98px) {

    /* === 1. NAVBAR COMPACT === */
    .navbar { 
        padding: 0.5rem 1rem !important; 
        height: 60px !important;
        align-items: center;
    }
    
    .navbar .container-fluid {
        flex-wrap: nowrap !important;
        gap: 8px;
    }
    
    /* Brand Shrink */
    .navbar-brand {
        max-width: 60%;
    }
    .navbar-brand img { 
        height: 30px !important; 
        width: 30px !important;
        margin-right: 6px !important;
    }
    .navbar-brand .brand-heading { 
        font-size: 0.8rem !important;
        line-height: 1.2;
    }
    .navbar-brand small { 
        display: none !important;
    }
    
    /* User Dropdown Compact */
    .user-dropdown .user-name { 
        display: none !important;
    }
    .user-dropdown .dropdown-toggle {
        padding: 6px !important;
    }

    /* === 2. SIDEBAR === */
    .sidebar { 
        position: fixed !important;
        left: -260px !important;
        top: 60px !important;
        width: 260px !important;
        height: calc(100vh - 60px) !important;
        background-color: #2c3e50 !important;
        transition: left 0.3s ease-in-out !important;
        z-index: 1045 !important;
        box-shadow: 5px 0 15px rgba(0,0,0,0.3);
        overflow-y: auto;
    }
    .sidebar.show { 
        left: 0 !important; 
    }

    /* === 3. MAIN CONTENT === */
    .main-content { 
        padding: 12px !important;
        margin-top: 60px !important;
        margin-left: 0 !important;
    }

    /* === 4. PAGE HEADER MOBILE === */
    .page-header {
        padding: 16px !important;
        margin-bottom: 16px !important;
        flex-direction: column;
        align-items: flex-start !important;
        gap: 12px !important;
    }
    
    /* Breadcrumb Compact */
    .breadcrumb {
        margin-bottom: 8px !important;
        font-size: 0.75rem !important;
    }
    
    .page-header h2 {
        font-size: 1.35rem !important;
        margin-bottom: 4px !important;
    }
    
    .page-header p {
        font-size: 0.8rem !important;
        margin-bottom: 0 !important;
    }
    
    /* Help Button Full Width */
    .btn-guide {
        width: 100%;
        justify-content: center;
        font-size: 0.85rem !important;
        padding: 8px 16px !important;
    }

    /* === 5. EVENT CARDS MOBILE === */
    .event-card-modern {
        margin-bottom: 16px !important;
        border-radius: 12px !important;
    }
    
    .event-card-header {
        padding: 14px !important;
        flex-direction: column;
        align-items: flex-start !important;
        gap: 10px !important;
    }
    
    /* Icon Box Smaller */
    .event-icon-box {
        width: 40px !important;
        height: 40px !important;
        font-size: 1.2rem !important;
        margin-bottom: 8px;
    }
    
    /* Event Title */
    .event-card-header h5 {
        font-size: 1rem !important;
        margin: 0 !important;
    }
    
    .event-card-header .text-muted {
        font-size: 0.75rem !important;
    }

    /* === 6. CATEGORY ROWS MOBILE === */
    .category-row {
        padding: 12px 14px !important;
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 10px;
    }
    
    /* Category Info Section */
    .category-row > div:first-child {
        width: 100%;
    }
    
    .category-row h6 {
        font-size: 0.9rem !important;
        margin-bottom: 6px !important;
    }
    
    .category-row small {
        font-size: 0.75rem !important;
    }
    
    /* Badges Stack */
    .category-row .badge-modern {
        font-size: 0.7rem !important;
        padding: 4px 10px !important;
        margin-bottom: 6px;
    }
    
    /* Action Buttons: Horizontal Scroll */
    .category-row .btn-group,
    .category-row > div:last-child {
        width: 100%;
        display: flex !important;
        flex-wrap: nowrap !important;
        gap: 6px;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        padding-bottom: 4px;
    }
    
    /* Compact Buttons */
    .category-row .btn {
        font-size: 0.75rem !important;
        padding: 6px 10px !important;
        white-space: nowrap;
        flex-shrink: 0;
    }
    
    /* Hide Button Text, Keep Icons Only */
    .category-row .btn-sm span {
        display: none !important;
    }
    
    /* Make Icons Bigger When Text is Hidden */
    .category-row .btn-sm i {
        font-size: 1rem !important;
        margin: 0 !important;
    }
    
    /* Add Button Tooltips Hint (Optional Visual) */
    .category-row .btn-sm[title]::after {
        content: attr(title);
        position: absolute;
        bottom: -20px;
        left: 50%;
        transform: translateX(-50%);
        background: rgba(0,0,0,0.8);
        color: white;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 0.65rem;
        white-space: nowrap;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.2s;
    }
    .category-row .btn-sm:hover::after {
        opacity: 1;
    }

    /* === 7. EMPTY STATE === */
    .alert {
        font-size: 0.85rem !important;
        padding: 12px !important;
    }
    
    .text-center i.fa-5x {
        font-size: 3rem !important;
    }

    /* === 8. MODALS MOBILE === */
    .modal-dialog {
        margin: 10px !important;
    }
    
    .modal-content {
        border-radius: 12px !important;
    }
    
    .modal-header {
        padding: 14px 16px !important;
    }
    
    .modal-title {
        font-size: 1rem !important;
    }
    
    .modal-body {
        padding: 16px !important;
    }
    
    .modal-body .form-label {
        font-size: 0.85rem !important;
        margin-bottom: 6px !important;
    }
    
    .modal-body .form-control,
    .modal-body .form-select {
        font-size: 0.9rem !important;
        padding: 8px 12px !important;
    }
    
    .modal-footer {
        padding: 12px 16px !important;
        gap: 8px;
    }
    
    .modal-footer .btn {
        font-size: 0.85rem !important;
        padding: 8px 16px !important;
    }

    /* === 9. FOOTER === */
    .footer-main {
        padding: 2rem 0 1.5rem 0 !important;
        padding-left: 0 !important;
    }
    
    .footer-main .footer-logo-group img {
        height: 35px !important;
        width: 35px !important;
    }
    
    .footer-main .footer-logo-group h5 {
        font-size: 0.9rem !important;
    }
    
    .footer-main p,
    .footer-main .footer-links a {
        font-size: 0.8rem !important;
    }

    /* === 10. HELP MODAL MOBILE === */
    #helpModal .modal-lg {
        max-width: 95% !important;
    }
    
    #helpModal .modal-body {
        padding: 16px !important;
    }
    
    #helpModal .col-md-6 {
        margin-bottom: 12px !important;
    }
    
    #helpModal h6 {
        font-size: 0.9rem !important;
    }
    
    #helpModal p {
        font-size: 0.8rem !important;
    }
}

/* === EXTRA SMALL PHONES (< 576px) === */
@media (max-width: 575.98px) {
    
    /* Ultra Compact Header */
    .navbar-brand .brand-heading {
        font-size: 0.7rem !important;
    }
    
    .page-header h2 {
        font-size: 1.1rem !important;
    }
    
    /* Single Column Buttons */
    .category-row .btn-group,
    .category-row > div:last-child {
        flex-direction: column !important;
        overflow-x: visible;
    }
    
    .category-row .btn {
        width: 100% !important;
    }
    
    /* Show Button Text Again on Tiny Screens */
    .category-row .btn-sm span {
        display: inline !important;
    }
    
    .category-row .btn-sm i {
        font-size: 0.85rem !important;
        margin-right: 6px !important;
    }
}

</style>
</head>
<body>

    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="tournamentmanager_dashboard.php">
                <img src="images/PIT.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white brand-heading" style="font-size: 1.25rem;">PIT SIGLAKAS MEDAL TALLY</strong>
                    <small class="text-light" style="font-size: 0.75rem;">Tournament Manager Panel</small>
                </div>
            </a>
            <div class="dropdown user-dropdown ms-auto me-2 me-lg-0">
                <a href="#" class="dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle" style="font-size: 36px; margin-right: 10px;"></i>
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
                <a class="nav-link" href="tournamentmanager_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="my_events.php">
                    <i class="fas fa-trophy me-2"></i> <span>My Assigned Events</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="Eventpage.php" target="_blank">
                    <i class="fas fa-globe me-2"></i> <span>View Public Events</span>
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
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-content">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="tournamentmanager_dashboard.php">Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">My Assigned Events</li>
                        </ol>
                    </nav>
                    <h2>My Assigned Events</h2>
                    <p>Manage categories, start competitions, and submit official results.</p>
                </div>
                
                <div class="page-header-actions">
                    <button class="btn-guide" data-bs-toggle="modal" data-bs-target="#helpModal">
                        <i class="fas fa-book-open text-primary"></i> Guide: How to Manage?
                    </button>
                </div>
            </div>
            
            <div id="alert-container">
            <?php if (!empty($alert_message)): ?>
                <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show" role="alert">
                    <i class="fas fa-<?php echo $alert_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                    <?php echo $alert_message; ?> <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            </div>

            <!-- Events Container -->
            <div id="events-container">
                <?php
                    render_event_list($managed_data, $college_map);
                ?>
            </div>

        </div>
    </div>
    
    <footer class="footer-main">
        <div class="container">
            <div class="row">
                <div class="col-lg-5 col-md-12 mb-4 mb-lg-0">
                    <div class="footer-logo-group">
                        <img src="images/PIT.png" alt="Logo">
                        <img src="images/Cote.png" alt="Logo">
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
                <small>Developed by Jayvee Baybyon</small>
            </div>
        </div>
    </footer>
    
    <!-- Add/Edit Category Modal -->
    <div class="modal fade" id="categoryModal" tabindex="-1" aria-labelledby="categoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" action="my_events.php"> 
                    <input type="hidden" name="action" value="save_category">
                    <input type="hidden" name="event_id" id="modal_event_id">
                    <input type="hidden" name="category_id" id="modal_category_id">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="categoryModalLabel"><i class="fas fa-plus-circle me-2"></i>Add Category / Division</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Event</label>
                            <input type="text" class="form-control bg-light" id="modal_event_name" disabled readonly>
                        </div>

                        <div class="alert alert-info py-2 small mb-3 border-0 bg-light text-primary">
                            <strong>Please enter at least one detail below: Category, Division, or both.</strong>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modal_category_name" class="form-label fw-bold">Category Name</label>
                                <input type="text" class="form-control" id="modal_category_name" name="category_name" placeholder="Leave  if Division only">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="modal_division_name" class="form-label fw-bold">Division Name</label>
                                <input type="text" class="form-control" id="modal_division_name" name="division_name" placeholder="Leave  if Category only">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modal_event_date" class="form-label fw-bold">Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="modal_event_date" name="event_date" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="modal_event_time" class="form-label fw-bold">Time <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="modal_event_time" name="event_time" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modal_venue" class="form-label fw-bold">Venue <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="modal_venue" name="venue" list="venueList" placeholder="Search or type a venue..." autocomplete="off" required>
                                
                                <datalist id="venueList">
                                    <?php if (!empty($all_venues)): ?>
                                        <?php foreach ($all_venues as $v): ?>
                                            <option value="<?= htmlspecialchars($v) ?>"></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </datalist>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="modal_category_status" class="form-label fw-bold">Status <span class="text-danger">*</span></label>
                                <select class="form-select" id="modal_category_status" name="status" required>
                                    <?php foreach ($status_options as $status_opt): ?>
                                        <option value="<?php echo $status_opt; ?>"><?php echo $status_opt; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Category Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="my_events.php"> 
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="category_id" id="delete_category_id">
                    
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="deleteModalLabel"><i class="fas fa-trash-alt me-2"></i>Confirm Deletion</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete: <strong id="delete_category_name" class="text-dark"></strong>?</p>
                        <p class="text-danger mb-0"><i class="fas fa-exclamation-triangle me-2"></i>This will permanently delete the category. This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-trash-alt me-2"></i>Yes, Delete Category</button>
                    </div>
                </form>
            </div> 
        </div>
    </div>
    
    <!-- Start Event Modal -->
    <div class="modal fade" id="startModal" tabindex="-1" aria-labelledby="startModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header bg-success text-white">
            <h5 class="modal-title" id="startModalLabel"><i class="fas fa-play-circle me-2"></i>Confirm Event Start</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="update_category_status.php" method="POST">
              <div class="modal-body">
                <p>Are you sure you want to start this event? This will change the status to 'Ongoing'.</p>
                <p class="mb-0"><strong>Event: </strong><span id="startCategoryName"></span></p>
              </div>
              <div class="modal-footer">
                <input type="hidden" id="startCategoryId" name="category_id">
                <input type="hidden" name="new_status" value="Ongoing">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-play me-2"></i>Yes, Start Event</button>
              </div>
          </form>
        </div>
      </div>
    </div>
    
    <!-- Complete Event Modal -->
    <div class="modal fade" id="completeModal" tabindex="-1" aria-labelledby="completeModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header bg-success text-white">
            <h5 class="modal-title" id="completeModalLabel"><i class="fas fa-check-circle me-2"></i>Confirm Event Completion</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form action="update_category_status.php" method="POST">
              <div class="modal-body">
                <p>Are you sure you want to mark this event as completed? This will allow you to submit the results.</p>
                <p class="mb-0"><strong>Event: </strong><span id="completeCategoryName"></span></p>
              </div>
              <div class="modal-footer">
                <input type="hidden" id="completeCategoryId" name="category_id">
                <input type="hidden" name="new_status" value="Completed (Pending Results)">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-check me-2"></i>Yes, Mark as Completed</button>
              </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Rejection Note Modal -->
    <div class="modal fade" id="noteModal" tabindex="-1" aria-labelledby="noteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="noteModalLabel"><i class="fas fa-exclamation-triangle me-2"></i>Rejection Note</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3 text-muted small">
                        <i class="fas fa-info-circle me-1"></i> The administrator rejected this submission for: 
                        <strong id="note_category_name" class="text-dark"></strong>
                    </p>
                    
                    <div class="p-3 bg-light rounded border d-flex align-items-start">
                        <i class="fas fa-comment-dots text-secondary me-3 mt-1 fs-5"></i>
                        <div>
                            <h6 class="fw-bold text-dark mb-1">Administrator's Note:</h6>
                            <p id="note_text" class="mb-0 text-secondary small"></p>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div> 
        </div>
    </div>
    

    <div class="modal fade" id="helpModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-dark text-white border-bottom-0">
                    <h5 class="modal-title fw-bold"></i>Event Management Guide</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    
                    <h6 class="fw-bold text-secondary mb-3 text-uppercase" style="letter-spacing: 1px; font-size: 0.85rem;">Phase 1: Pre-Game Setup</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded h-100 border border-primary border-opacity-25">
                                <h6 class="fw-bold text-primary mb-2"><i class="fas fa-layer-group me-2"></i>Setup Divisions & Venues</h6>
                                <p class="small text-muted mb-0">Use the <strong>Add Category / Division</strong> or <strong>Edit</strong> buttons to define specific divisions (e.g., Men's, Lightweight) and set the official Date, Time, and Venue.</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded h-100 border border-primary border-opacity-25">
                                <h6 class="fw-bold text-primary mb-2"><i class="fas fa-print me-2"></i>Print Tally Sheets</h6>
                                <p class="small text-muted mb-0">Before the game begins, click the <strong>Print</strong> icon to generate the Official Tally Sheet for your referees to use and sign.</p>
                            </div>
                        </div>
                    </div>

                    <h6 class="fw-bold text-secondary mb-3 text-uppercase" style="letter-spacing: 1px; font-size: 0.85rem;">Phase 2: Game Day Execution</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded h-100 border">
                                <h6 class="fw-bold text-success mb-2"><i class="fas fa-play-circle me-2"></i>1. Start Event</h6>
                                <p class="small text-muted mb-0">When the competition begins, click <span class="badge bg-success">Start</span>. This updates the status to 'Ongoing' so the public knows the match is live.</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded h-100 border">
                                <h6 class="fw-bold text-warning text-dark mb-2"><i class="fas fa-flag-checkered me-2"></i>2. Finish Event</h6>
                                <p class="small text-muted mb-0">Once the final whistle blows, click <span class="badge bg-warning text-dark">Finish</span>. This locks the match status and activates the results submission form.</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded h-100 border">
                                <h6 class="fw-bold text-primary mb-2"><i class="fas fa-medal me-2"></i>3. Submit Results</h6>
                                <p class="small text-muted mb-0">Click <strong>Results</strong> to declare the medal winners. You <strong>must</strong> upload a clear photo of the signed Official Tally Sheet as proof.</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded h-100 border">
                                <h6 class="fw-bold text-danger mb-2"><i class="fas fa-shield-alt me-2"></i>4. Verification</h6>
                                <p class="small text-muted mb-0">The Sports Director will review your submitted evidence. If rejected, click the red <strong>Note</strong> button to read their feedback and resubmit.</p>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer border-top-0 pt-0 bg-white justify-content-center">
                    <button type="button" class="btn btn-secondary px-4 rounded-pill" data-bs-dismiss="modal">I understand</button>
                </div>
            </div>
        </div>
    </div>
</div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Help Section Toggle
        function toggleHelp() {
            const helpContent = document.getElementById('helpContent');
            const helpToggle = document.getElementById('helpToggle');
            
            if (helpContent.style.display === 'none') {
                helpContent.style.display = 'block';
                helpToggle.classList.remove('collapsed');
            } else {
                helpContent.style.display = 'none';
                helpToggle.classList.add('collapsed');
            }
        }
        
        // Store old tooltip/popover instances to clean them up
        let currentTooltips = [];
        let currentPopovers = [];
        
        // Store the interval ID so we can stop/start it
        let eventPollingInterval = null;
        
        // Add a "loading" flag to prevent concurrent fetches
        let isFetchingUpdates = false;

        /**
         * Initializes all Bootstrap dynamic components (tooltips, popovers)
         * within a given container. Also cleans up old instances.
         * @param {HTMLElement} container The element to search within.
         */
        function initDynamicComponents(container) {
            // 1. Clean up old tooltips
            currentTooltips.forEach(t => t.dispose());
            currentTooltips = [];
            
            // 2. Clean up old popovers
            currentPopovers.forEach(p => p.dispose());
            currentPopovers = [];

            // 3. Initialize new Tooltips
            var tooltipTriggerList = [].slice.call(container.querySelectorAll('[data-bs-toggle="tooltip"]'))
            currentTooltips = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });

            // 4. Initialize new Popovers
            var popoverTriggerList = [].slice.call(container.querySelectorAll('[data-bs-toggle="popover"]'))
            currentPopovers = popoverTriggerList.map(function (popoverTriggerEl) {
                return new bootstrap.Popover(popoverTriggerEl, {
                    trigger: 'focus'
                });
            });
        }

        /**
         * Fetches the latest event data from the server and updates the DOM.
         */
        async function fetchEventUpdates() {
            if (isFetchingUpdates) {
                console.log('Update fetch already in progress, skipping.');
                return;
            }
            
            isFetchingUpdates = true;

            try {
                const response = await fetch(`my_events.php?partial=1&t=${Date.now()}`);
                
                const container = document.getElementById('events-container');
                const alertContainer = document.getElementById('alert-container');

                if (!response.ok) {
                    console.error('Failed to fetch updates, server responded with:', response.status);
                    
                    if(response.status === 401) {
                        const html = await response.text();
                        if (alertContainer) {
                            alertContainer.innerHTML = html;
                        }
                        stopPolling(); 
                    }
                    return;
                }
                
                const html = await response.text();
                
                if (container) {
                    container.innerHTML = html;
                    initDynamicComponents(container);
                }
            } catch (error) {
                console.error('Error fetching real-time updates:', error);
            } finally {
                isFetchingUpdates = false;
            }
        }
        
        /**
         * Starts the polling interval
         */
        function startPolling() {
            stopPolling(); 
            eventPollingInterval = setInterval(fetchEventUpdates, 10000);
            console.log('Event polling started (10s interval).');
        }

        /**
         * Stops the polling interval
         */
        function stopPolling() {
            if (eventPollingInterval) {
                clearInterval(eventPollingInterval);
                eventPollingInterval = null;
                console.log('Event polling stopped.');
            }
        }
        
        /**
         * Handles changes in the page's visibility
         */
        function handleVisibilityChange() {
            if (document.visibilityState === 'hidden') {
                stopPolling();
            } else if (document.visibilityState === 'visible') {
                console.log('Page is visible. Fetching immediate update...');
                fetchEventUpdates();
                startPolling();
            }
        }


        // --- MAIN EXECUTION ON PAGE LOAD ---
        document.addEventListener('DOMContentLoaded', function () {
            
            const eventsContainer = document.getElementById('events-container');
            if (eventsContainer) {
                initDynamicComponents(eventsContainer);
            }

            startPolling();
            
            document.addEventListener('visibilitychange', handleVisibilityChange, false);
            
            // --- Modal logic for Add/Edit Category (UPDATED) ---
            const categoryModal = document.getElementById('categoryModal');
            
            categoryModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget; 
                if (!button || button.disabled) {
                    event.preventDefault();
                    return;
                }
                
                const action = button.getAttribute('data-action');
                
                // Form Elements
                const modalForm = categoryModal.querySelector('form');
                const modalTitle = categoryModal.querySelector('.modal-title');
                const eventIdInput = categoryModal.querySelector('#modal_event_id');
                const eventNameInput = categoryModal.querySelector('#modal_event_name');
                const categoryIdInput = categoryModal.querySelector('#modal_category_id');
                const categoryNameInput = categoryModal.querySelector('#modal_category_name');
                const divisionNameInput = categoryModal.querySelector('#modal_division_name');
                const categoryStatusSelect = categoryModal.querySelector('#modal_category_status');
                
                
                const eventDateInput = categoryModal.querySelector('#modal_event_date');
                const eventTimeInput = categoryModal.querySelector('#modal_event_time');
                const venueInput = categoryModal.querySelector('#modal_venue');
                
                // Get Data
                const eventId = button.getAttribute('data-event-id');
                const eventName = button.getAttribute('data-event-name');
                
                eventIdInput.value = eventId;
                eventNameInput.value = eventName;
                
                // Clean up previous hidden inputs if any exist
                const oldHidden = document.getElementById('hidden_main_event_name');
                if(oldHidden) oldHidden.remove();

                if (action === 'edit') {
                    modalTitle.innerHTML = '<i class="fas fa-edit me-2"></i>Add Category / Division';
                    categoryIdInput.value = button.getAttribute('data-category-id');
                    
                    let rawName = button.getAttribute('data-category-name');

                    // NEW: Populate Division Name
                    divisionNameInput.value = button.getAttribute('data-division-name') || '';
                    
                    // --- LOGIC TO HANDLE "NO CATEGORY" ---
                    // Check if this is a "Main Event" (Single Category)
                    if (rawName === 'Main Event' || rawName === 'Main Competition') {
                        // 1. VISUAL: Show "No Category" to the user
                        categoryNameInput.value = "(No Category Event)";
                        
                        // 2. DISABLE: Prevent editing
                        categoryNameInput.disabled = true;
                        categoryNameInput.classList.add('bg-light', 'text-muted'); // Style to look read-only
                        
                        // 3. INTERNAL: Create a hidden input so the real name "Main Event" is still saved
                        // (Otherwise PHP receives nothing and throws an error)
                        const hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = 'category_name';
                        hiddenInput.value = rawName; // Keep 'Main Event'
                        hiddenInput.id = 'hidden_main_event_name';
                        modalForm.appendChild(hiddenInput);
                        
                    } else {
                        // Normal Category Logic
                        categoryNameInput.value = rawName;
                        categoryNameInput.disabled = false;
                        categoryNameInput.classList.remove('bg-light', 'text-muted');
                    }
                    // -------------------------------------

                    let status = button.getAttribute('data-status');
                    categoryStatusSelect.value = status; 
                    
                    // FIXED: Just set the value. No need to disable a hidden input.
                    if (categoryTypeSelect) {
                        categoryTypeSelect.value = button.getAttribute('data-category-type') || 'medal';
                    }
                    
                    eventDateInput.value = button.getAttribute('data-event-date');
                    eventTimeInput.value = button.getAttribute('data-event-time');
                    venueInput.value = button.getAttribute('data-venue');
                    
                } else {
                    // Add Mode
                    modalTitle.innerHTML = '<i class="fas fa-plus-circle me-2"></i>Add Category / Division ';
                    categoryIdInput.value = '';
                    categoryNameInput.value = '';
                    divisionNameInput.value = ''; // <--- Clear the division name
                    categoryNameInput.disabled = false; // Reset disabled state
                    categoryNameInput.classList.remove('bg-light', 'text-muted');
                    
                    categoryStatusSelect.value = 'Upcoming';
                    categoryTypeSelect.value = ''; 
                    categoryTypeSelect.disabled = false;
                    eventDateInput.value = '';
                    eventTimeInput.value = '';
                    venueInput.value = '';
                }
            });

            // ==========================================
            // NEW: CATEGORY & DIVISION VALIDATION
            // Ensures at least one field is filled before submitting
            // ==========================================
            const modalForm = categoryModal.querySelector('form');
            if (modalForm) {
                modalForm.addEventListener('submit', function(e) {
                    const catName = document.getElementById('modal_category_name').value.trim();
                    const divName = document.getElementById('modal_division_name').value.trim();
                    
                    // If they are BOTH blank, stop the form and show an error
                    if (catName === '' && divName === '') {
                        e.preventDefault(); // Stop form submission
                        alert('Please enter either a Category Name or a Division Name (or both) to continue.');
                    }
                });
            }

            // --- Modal logic for Delete Category ---
            const deleteModal = document.getElementById('deleteModal');
            deleteModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                if (!button || button.disabled) {
                    event.preventDefault();
                    return;
                }
                deleteModal.querySelector('#delete_category_id').value = button.getAttribute('data-category-id');
                deleteModal.querySelector('#delete_category_name').textContent = button.getAttribute('data-category-name');
            });

            // --- Modal logic for "Complete" Modal ---
            const completeModal = document.getElementById('completeModal');
            if (completeModal) {
                completeModal.addEventListener('show.bs.modal', function (event) {
                    var button = event.relatedTarget;
                    var categoryId = button.getAttribute('data-category-id');
                    var categoryName = button.getAttribute('data-category-name');
                    
                    var modalCategoryName = completeModal.querySelector('#completeCategoryName');
                    var modalCategoryId = completeModal.querySelector('#completeCategoryId');
                    
                    modalCategoryName.textContent = categoryName;
                    modalCategoryId.value = categoryId;
                });
            }

            // --- Modal logic for "Start" Modal ---
            var startModal = document.getElementById('startModal');
            if (startModal) {
                startModal.addEventListener('show.bs.modal', function (event) {
                    var button = event.relatedTarget;
                    var categoryId = button.getAttribute('data-category-id');
                    var categoryName = button.getAttribute('data-category-name');
                    
                    var modalCategoryName = startModal.querySelector('#startCategoryName');
                    var modalCategoryId = startModal.querySelector('#startCategoryId');
                    
                    modalCategoryName.textContent = categoryName;
                    modalCategoryId.value = categoryId;
                });
            }

            // --- Modal logic for "Note" Modal (UPDATED) ---
            var noteModal = document.getElementById('noteModal');
            if (noteModal) {
                noteModal.addEventListener('show.bs.modal', function (event) {
                    var button = event.relatedTarget;
                    
                    // 1. Get Data
                    var categoryName = button.getAttribute('data-category-name');
                    var eventName = button.getAttribute('data-event-name'); // New Attribute
                    var note = button.getAttribute('data-note');
                    
                    // 2. Format the Title: "Cricket - Men's"
                    var fullTitle = categoryName;
                    if (eventName) {
                        fullTitle = eventName + ' - ' + categoryName;
                    }

                    // 3. Update Modal Content
                    var modalCategoryName = noteModal.querySelector('#note_category_name');
                    var modalNoteText = noteModal.querySelector('#note_text');
                    
                    modalCategoryName.textContent = fullTitle;
                    modalNoteText.textContent = note;
                });
            }
            
            // --- Sidebar Height Adjustment Logic ---
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth > 992) {
                    }
                    adjustSidebarHeight();
                }, 250);
            });
            
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
        });
    </script>
</body>
</html>