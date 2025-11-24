<?php
session_start();
require_once 'config.php'; // Your DB connection
// This file defines the log_activity() function
require_once 'db_connect.php';
// This file now defines render_event_list() and helper functions
require_once 'my_events_view.php'; 

// 1. SECURITY & ACCESS CONTROL
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Event Manager') {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Event Manager';
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
                $status = $_POST['status'];
                $event_id = (int)$_POST['event_id'];
                $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
                
                $event_date = !empty($_POST['event_date']) ? $_POST['event_date'] : null;
                $event_time = !empty($_POST['event_time']) ? $_POST['event_time'] : null;
                $venue = trim($_POST['venue']);

                // Security Check: Verify this manager is assigned to this event
                $stmt_check = $conn->prepare("SELECT event_id FROM event_manager_assignments WHERE event_id = ? AND user_id = ?");
                $stmt_check->bind_param("ii", $event_id, $current_user_id);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows == 0) {
                    throw new Exception("Permission denied. You are not assigned to this event.");
                }
                $stmt_check->close();

                if (empty($category_id)) {
                    // --- THIS IS AN ADD OPERATION ---
                    $category_type = $_POST['category_type']; 
                    
                    if (empty($category_name) || empty($status) || empty($event_id) || empty($category_type)) {
                        throw new Exception("All fields are required.");
                    }
                    
                    $stmt = $conn->prepare(
                        "INSERT INTO categories (event_id, category_name, status, category_type, event_date, event_time, venue) 
                         VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param("issssss", $event_id, $category_name, $status, $category_type, $event_date, $event_time, $venue);
                    $stmt->execute();
                    
                    $new_category_id = (int)$conn->insert_id; 
                    
                    try {
                        $context = [
                            'category_name' => $category_name,
                            'status' => $status,
                            'category_type' => $category_type,
                            'event_date' => $event_date,
                            'event_time' => $event_time,
                            'venue' => $venue
                        ];
                        log_activity($conn, $current_user_id, 'CREATED_CATEGORY', $new_category_id, 'category', $event_id, 'event', $context);
                    } catch (Exception $log_e) {
                        error_log("Failed to log CREATED_CATEGORY: " . $log_e->getMessage());
                    }
                    
                    if ($category_name === 'Main Event' || $category_name === 'Main Competition') {
                        $alert_message = "Success! Event initialized successfully.";
                    } else {
                        $alert_message = "Success! Category '{$category_name}' created successfully.";
                    }
                    
                } else {
                    // --- THIS IS AN UPDATE OPERATION ---
                    if (empty($category_name) || empty($status) || empty($event_id)) {
                        throw new Exception("Category Name and Status are required.");
                    }
                    
                    $old_data_stmt = $conn->prepare("SELECT status, event_date, event_time, venue FROM categories WHERE category_id = ?");
                    $old_data_stmt->bind_param("i", $category_id);
                    $old_data_stmt->execute();
                    $old_data = $old_data_stmt->get_result()->fetch_assoc();
                    $old_data_stmt->close();
                    
                    $stmt = $conn->prepare(
                        "UPDATE categories SET category_name = ?, status = ?, event_date = ?, event_time = ?, venue = ? 
                         WHERE category_id = ? AND event_id = ?"
                    );
                    $stmt->bind_param("sssssii", $category_name, $status, $event_date, $event_time, $venue, $category_id, $event_id);
                    $stmt->execute();
                    
                    try {
                        $context = [
                            'new_category_name' => $category_name,
                            'old_status' => $old_data['status'] ?? $status,
                            'new_status' => $status,
                            'old_event_date' => $old_data['event_date'] ?? null,
                            'new_event_date' => $event_date,
                            'old_event_time' => $old_data['event_time'] ?? null,
                            'new_event_time' => $event_time,
                            'old_venue' => $old_data['venue'] ?? '',
                            'new_venue' => $venue,
                            'category_id' => $category_id
                        ];
                        log_activity($conn, $current_user_id, 'UPDATED_CATEGORY', $category_id, 'category', $event_id, 'event', $context);
                    } catch (Exception $log_e) {
                        error_log("Failed to log UPDATED_CATEGORY: " . $log_e->getMessage());
                    }

                    // --- IMPROVED USER FEEDBACK ---
                    // Check if this is a "Main Event" (Single Category)
                    if ($category_name === 'Main Event' || $category_name === 'Main Competition') {
                        // Friendly message for single events
                        $alert_message = "Success! Event details updated successfully.";
                    } else {
                        // Standard message for sub-categories
                        $alert_message = "Success! Category '{$category_name}' updated successfully.";
                    }
                }
                $stmt->close();

            } 
            // --- Action: Delete Category ---
            elseif ($action === 'delete_category') {
                $category_id = (int)$_POST['category_id'];
                
                $stmt_check = $conn->prepare("
                    SELECT c.event_id, c.category_name 
                    FROM categories c
                    JOIN event_manager_assignments ema ON c.event_id = ema.event_id
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
                $stmt_check->close();
                
                $stmt_del = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
                $stmt_del->bind_param("i", $category_id);
                $stmt_del->execute();
                
                if ($stmt_del->affected_rows > 0) {
                    
                    try {
                        $context = ['deleted_category_name' => $deleted_category_name];
                        log_activity($conn, $current_user_id, 'DELETED_CATEGORY', $category_id, 'category', null, null, $context);
                    } catch (Exception $log_e) {
                        error_log("Failed to log DELETED_CATEGORY: " . $log_e->getMessage());
                    }
                    
                    $alert_message = "SUCCESS: Category was deleted.";
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
try {
    $sql = "SELECT
        g.game_name,
        ge.event_id, 
        ge.event_name,
        ge.event_structure, /* <--- Now this will work! */
        c.category_id, c.category_name, 
        
        -- NEW DYNAMIC STATUS LOGIC --
        CASE
            -- 1. Handle final states first (these override everything)
            WHEN c.status IN ('Results Submitted', 'Results Rejected', 'Cancelled', 'Postponed') THEN c.status
            WHEN c.status = 'Results Approved' THEN 'Completed' 
            
            -- 2. Handle 'Medal' type (it uses its own status)
            WHEN c.category_type = 'medal' THEN c.status -- This will be 'Upcoming', 'Ongoing', 'Completed (Pending Results)'
            
            -- 3. Handle 'Match' type dynamic status
            WHEN c.category_type = 'Match' THEN
                (CASE
                    -- 3a. If any child match is 'Ongoing' OR 'Completed', the whole event is 'Ongoing'
                    WHEN EXISTS (
                        SELECT 1 FROM matches m 
                        WHERE m.category_id = c.category_id AND m.status IN ('Ongoing', 'Completed')
                    ) THEN 'Ongoing'
                    
                    -- 3b. Otherwise (all children are 'Upcoming' or no children exist), it's 'Upcoming'
                    ELSE 'Upcoming'
                END)

            -- 4. Fallback in case status is NULL or category_type is NULL
            ELSE c.status 
        END AS status,
        -- END NEW LOGIC --
        
        c.category_type,
        c.event_date, c.event_time, c.venue,
        c.gold_winner_college_id, c.gold_count,
        c.silver_winner_college_id, c.silver_count,
        c.bronze_winner_college_id, c.bronze_count,
        c.notes
    FROM event_manager_assignments ema
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
                if (empty($row['category_type'])) {
                    $row['category_type'] = 'medal';
                }
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
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'Event Manager') {
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
        /* --- GLOBAL VARIABLES --- */
        :root { 
            --sidebar-width: 260px; 
            --sidebar-collapsed-width: 80px; /* NEW: Compact width */
            --header-height: 82px; 
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); 
            --bg-light: #F8F9FA; 
            --bs-purple: #6f42c1; 
            --bs-info: #0dcaf0;
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --bs-table-bg-light-danger: #fbe9eb;
            --bs-table-border-light-danger: #f5c6cb;
        }
        
        body { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            background-attachment: fixed;
            margin: 0; 
            padding: 0; 
            min-height: 100vh;
            font-family: 'Inter', sans-serif; 
            display: flex; 
            flex-direction: column; 
        }
        
        /* Glassmorphism Background */
        body::before {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: radial-gradient(circle at 20% 50%, rgba(120, 119, 198, 0.3), transparent 50%),
                        radial-gradient(circle at 80% 80%, rgba(99, 102, 241, 0.2), transparent 50%);
            pointer-events: none;
            z-index: 0;
        }
        
        /* --- NAVBAR --- */
        .navbar { 
            background: rgba(26, 26, 26, 0.95) !important;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0,0,0,0.2); 
            padding: 1rem 1.5rem; 
            height: var(--header-height); 
            position: fixed; 
            top: 0; left: 0; right: 0; 
            z-index: 1050;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .navbar-brand .brand-heading { 
            font-family: 'Poppins', sans-serif; 
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .user-dropdown .dropdown-toggle { 
            color: white; 
            display: flex; align-items: center; 
            text-decoration: none; 
            padding: 8px 16px; 
            border-radius: 50px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .user-dropdown .dropdown-toggle:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }
        
        .user-dropdown .dropdown-toggle img { 
            width: 36px; height: 36px; 
            border-radius: 50%; object-fit: cover; margin-right: 10px;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }
        
        .navbar-profile-icon { 
            width: 36px; height: 36px; font-size: 36px; 
            text-align: center; line-height: 1; border-radius: 50%; margin-right: 10px; 
            color: rgba(255,255,255,0.8); 
        }

        /* --- EXPANDABLE SIDEBAR LOGIC --- */
        .sidebar { 
            width: var(--sidebar-collapsed-width); /* Start Compact */
            position: fixed; 
            top: var(--header-height); 
            left: 0; 
            height: calc(100vh - var(--header-height)); 
            background: rgba(44, 62, 80, 0.95);
            backdrop-filter: blur(10px);
            color: white; 
            box-shadow: 5px 0 30px rgba(0,0,0,0.3); 
            z-index: 1040; 
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            overflow-x: hidden; 
            white-space: nowrap; /* Prevent text wrapping */
            border-right: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        /* Expand on Hover */
        .sidebar:hover {
            width: var(--sidebar-width);
        }
        
        .sidebar-nav { padding: 30px 0; }
        
        .sidebar-nav .nav-link { 
            color: rgba(255, 255, 255, 0.7); 
            font-size: 1.05rem; 
            font-weight: 500; 
            padding: 15px 0; /* Adjusted padding */
            padding-left: 25px; /* Fixed left padding for icon */
            transition: all 0.2s ease; 
            border-left: 5px solid transparent; 
            margin: 2px 0; 
            display: flex; 
            align-items: center; 
            text-decoration: none;
            justify-content: flex-start;
        }
        
        .sidebar-nav .nav-link i { 
            width: 30px; 
            text-align: center; 
            flex-shrink: 0; 
            font-size: 1.1em;
            margin-right: 15px; /* Space between icon and text */
            transition: transform 0.3s ease;
        }
        
        /* Text fading logic */
        .sidebar-nav .nav-link span {
            opacity: 0;
            transition: opacity 0.2s ease;
            display: inline-block;
        }
        
        .sidebar:hover .nav-link span {
            opacity: 1;
            transition-delay: 0.1s;
        }
        
        .sidebar-nav .nav-link:hover {
            color: white; 
            background: rgba(255, 255, 255, 0.05); 
            border-left-color: #667eea;
        }
        
        .sidebar-nav .nav-link:hover i { transform: scale(1.2); }
        
        .sidebar-nav .nav-link.active { 
            color: white; 
            background: linear-gradient(90deg, rgba(102, 126, 234, 0.2), transparent); 
            border-left-color: #667eea; 
            font-weight: 600;
        }

        /* --- MAIN CONTENT & FOOTER --- */
        .main-content { 
            flex: 1 0 auto; 
            /* Fix margin to collapsed width so it doesn't jump */
            margin-left: var(--sidebar-collapsed-width); 
            width: calc(100% - var(--sidebar-collapsed-width));
            padding: 30px; 
            margin-top: var(--header-height); 
            transition: margin-left 0.3s ease; 
            position: relative;
            z-index: 1;
        }
        
        footer {
            flex-shrink: 0;
            background: rgba(44, 62, 80, 0.95) !important;
            backdrop-filter: blur(10px);
            box-shadow: 0 -2px 20px rgba(0,0,0,0.2);
            /* Fix padding to collapsed width */
            padding-left: var(--sidebar-collapsed-width);
            transition: padding-left 0.3s ease;
            position: relative;
            z-index: 1041;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* --- PAGE ELEMENTS --- */
        /* Green Game Heading (Requested) */
        .game-heading { 
            font-family: 'Poppins', sans-serif; 
            font-weight: 600; 
            color: #28a745; /* Solid Green */
            border-bottom: 3px solid #28a745; /* Solid Green Underline */
            padding-bottom: 8px; 
            display: inline-block;
            margin-bottom: 2rem;
            margin-top: 1rem;
            font-size: 2rem;
        }
        
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
        
        .page-header h1 {
            font-family: 'Poppins', sans-serif; font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
            margin: 0; font-size: 2.5rem;
        }
        
        .breadcrumb { background: transparent; padding: 0; margin-bottom: 0; }
        .breadcrumb-item a { color: #667eea; text-decoration: none; transition: all 0.3s ease; }
        .breadcrumb-item a:hover { color: #764ba2; transform: translateX(2px); }
        
        /* Help Section */
        .help-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            margin-bottom: 2rem;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(102, 126, 234, 0.2);
            overflow: hidden;
        }
        .help-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 1rem 1.5rem; cursor: pointer;
            display: flex; align-items: center; justify-content: space-between;
            color: white;
        }
        .help-header h5 { margin: 0; font-weight: 600; display: flex; align-items: center; gap: 10px; }
        .help-toggle { transition: transform 0.3s ease; }
        .help-toggle.collapsed { transform: rotate(180deg); }
        .help-content { padding: 1.5rem; background: white; }
        .help-content li { margin-bottom: 0.75rem; line-height: 1.6; }
        
        /* Global Bootstrap Overrides */
        .form-control, .form-select {
            border-radius: 10px; border: 2px solid rgba(102, 126, 234, 0.2); padding: 0.75rem 1rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: #667eea; box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .modal-content {
            border-radius: 20px; border: none; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 20px 20px 0 0;
        }
        .modal-header .btn-close { filter: brightness(0) invert(1); }
        
        /* Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: rgba(0, 0, 0, 0.1); }
        ::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; }

        /* Responsive Mobile */
        @media (max-width: 992px) {
            .sidebar { width: 0; } /* Hidden by default on mobile */
            .sidebar:hover { width: var(--sidebar-width); } /* Can slide out */
            .sidebar.show { width: var(--sidebar-width); } /* JS Class toggle */
            .sidebar.show .nav-link span { opacity: 1; }
            
            .main-content, footer { margin-left: 0; width: 100%; }
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="event_manager_dashboard.php">
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
                <a class="nav-link" href="event_manager_dashboard.php">
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
                <nav aria-label="breadcrumb" class="mb-3">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="event_manager_dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">My Assigned Events</li>
                    </ol>
                </nav>
                <h1><i class="fas fa-trophy me-3"></i>My Assigned Events</h1>
            </div>
            
            <!-- Collapsible Help Section -->
            <div class="help-section">
                <div class="help-header" onclick="toggleHelp()">
                    <h5>
                        <i class="fas fa-question-circle"></i>
                        How to Manage Your Events?
                    </h5>
                    <i class="fas fa-chevron-up help-toggle collapsed" id="helpToggle"></i>
                </div>
                
                <div class="help-content" id="helpContent" style="display: none;">
                    <p class="mb-3">The <strong>Actions</strong> column is smart. It adapts based on the event type (Medal vs. Match) and its current status.</p>
                    <ul class="list-unstyled">
                        <li class="mb-2">
                            <i class="fas fa-medal text-warning me-2"></i><strong>Medal Events (e.g., Athletics):</strong>
                            Follow the 3-step button flow: <span class="badge bg-success">Start</span> ➔ <span class="badge bg-warning text-dark">Complete</span> ➔ <span class="badge bg-primary">Submit</span>.
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-basketball-ball text-info me-2"></i><strong>Match Events (e.g., Basketball):</strong>
                            Click the <span class="badge bg-primary">Manage</span> button to open the Match Scheduler, where you can update brackets and scores.
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-layer-group text-secondary me-2"></i><strong>Single-Category Events:</strong> 
                            For events with no sub-categories, the 'Category' column is hidden. 
                            <br><small class="text-danger ms-4"><i class="fas fa-exclamation-circle me-1"></i> <strong>Note:</strong> You cannot delete this event type here. Contact the Administrator if the entire event needs removal.</small>
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-edit text-secondary me-2"></i><strong>Edit (Pencil):</strong> 
                            Update details like Venue or Date. Disabled once an event is <em>Ongoing</em> or <em>Completed</em>.
                        </li>
                        <li>
                            <i class="fas fa-trash-alt text-danger me-2"></i><strong>Delete (Trash):</strong> 
                            Only available for <strong>Upcoming</strong> sub-categories. Disabled for single-category events to prevent accidental data loss.
                        </li>
                    </ul>
                </div>
            </div>
            
            <!-- Alert Container -->
            <div id="alert-container">
            <?php if (!empty($alert_message)): ?>
                <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show" role="alert">
                    <i class="fas fa-<?php echo $alert_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                    <?php echo htmlspecialchars($alert_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
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
    
    <footer class="bg-dark text-white py-4">
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small>
        </div>
    </footer>
    
    <!-- Add/Edit Category Modal -->
    <div class="modal fade" id="categoryModal" tabindex="-1" aria-labelledby="categoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="my_events.php"> 
                    <input type="hidden" name="action" value="save_category">
                    <input type="hidden" name="event_id" id="modal_event_id">
                    <input type="hidden" name="category_id" id="modal_category_id">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="categoryModalLabel"><i class="fas fa-plus-circle me-2"></i>Add Category</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Event</label>
                            <input type="text" class="form-control" id="modal_event_name" disabled readonly>
                        </div>
                        <div class="mb-3">
                            <label for="category_name" class="form-label">Category Name</label>
                            <input type="text" class="form-control" id="modal_category_name" name="category_name" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modal_event_date" class="form-label">Date</label>
                                <input type="date" class="form-control" id="modal_event_date" name="event_date">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="modal_event_time" class="form-label">Time</label>
                                <input type="time" class="form-control" id="modal_event_time" name="event_time">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="modal_venue" class="form-label">Venue</label>
                            <input type="text" class="form-control" id="modal_venue" name="venue" placeholder="e.g., PIT Main Gymnasium">
                        </div>
                        <div class="mb-3">
                            <label for="modal_category_type" class="form-label">Event Type</label>
                            <select class="form-select" id="modal_category_type" name="category_type" required>
                                <option value="" disabled>Select a type...</option>
                                <option value="medal">Medal (Judged finals, e.g., Athletics, Swim, Quiz)</option>
                                <option value="match">Match (Team vs. Team, e.g., Basketball, Volleyball)</option>
                            </select>
                            <small class="form-text text-muted">This choice is permanent once set.</small>
                        </div>

                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="modal_category_status" name="status" required>
                                <?php foreach ($status_options as $status_opt): ?>
                                    <option value="<?php echo $status_opt; ?>"><?php echo $status_opt; ?></option>
                                <?php endforeach; ?>
                            </select>
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
                    <p class="mb-2">The administrator left the following note for: <strong id="note_category_name"></strong></p>
                    <div id="note_text" class="modal-body-note">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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
                const categoryStatusSelect = categoryModal.querySelector('#modal_category_status');
                const categoryTypeSelect = categoryModal.querySelector('#modal_category_type');
                
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
                    modalTitle.innerHTML = '<i class="fas fa-edit me-2"></i>Edit Category';
                    categoryIdInput.value = button.getAttribute('data-category-id');
                    
                    let rawName = button.getAttribute('data-category-name');
                    
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
                    categoryTypeSelect.value = button.getAttribute('data-category-type');
                    categoryTypeSelect.disabled = true; 
                    eventDateInput.value = button.getAttribute('data-event-date');
                    eventTimeInput.value = button.getAttribute('data-event-time');
                    venueInput.value = button.getAttribute('data-venue');
                    
                } else {
                    // Add Mode
                    modalTitle.innerHTML = '<i class="fas fa-plus-circle me-2"></i>Add Category to ' + eventName;
                    categoryIdInput.value = '';
                    categoryNameInput.value = '';
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

            // --- Modal logic for "Note" Modal ---
            var noteModal = document.getElementById('noteModal');
            if (noteModal) {
                noteModal.addEventListener('show.bs.modal', function (event) {
                    var button = event.relatedTarget;
                    var categoryName = button.getAttribute('data-category-name');
                    var note = button.getAttribute('data-note');
                    
                    var modalCategoryName = noteModal.querySelector('#note_category_name');
                    var modalNoteText = noteModal.querySelector('#note_text');
                    
                    modalCategoryName.textContent = categoryName;
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