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
                    
                    $alert_message = "SUCCESS: Category '{$category_name}' was created!";
                    
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

                    $alert_message = "SUCCESS: Category '{$category_name}' was updated!";
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
        ge.event_id, ge.event_name,
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
        :root { 
            --sidebar-width: 260px; 
            --header-height: 82px; 
            --transition: all 0.3s ease; 
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); 
            --bg-light: #F8F9FA; 
            --bs-purple: #6f42c1; 
            --bs-info: #0dcaf0;
            
            --bs-table-bg-light-danger: #fbe9eb;
            --bs-table-border-light-danger: #f5c6cb;
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
        footer {
            flex-shrink: 0;
            background: #2c3e50 !important;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            padding-left: var(--sidebar-width);
            transition: padding-left var(--transition);
            position: relative;
            z-index: 1041;
        }
        .sidebar.minimized ~ footer {
            padding-left: var(--sidebar-min-width);
        }
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        .card { border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        
        .game-heading { font-family: 'Poppins', sans-serif; font-weight: 600; color: var(--bs-success, #198754); border-bottom: 2px solid var(--bs-success, #198754); padding-bottom: 8px; display: inline-block; }
        .winner-icon { font-size: 1.1em; margin-right: 4px; opacity: 0.9; }
        .gold { color: #FFD700; }
        .silver { color: #C0C0C0; }
        .bronze { color: #CD7F32; }
        .winner-count { font-weight: 600; color: #333; }
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
        .navbar-profile-icon { width: 36px; height: 36px; font-size: 36px; text-align: center; line-height: 1; border-radius: 50%; margin-right: 10px; color: rgba(255,255,255,0.8); }
        
        .text-purple { color: var(--bs-purple) !important; }
        .text-bg-purple { color: #fff !important; background-color: var(--bs-purple) !important; }
        
        .dropdown-item.disabled, .dropdown-item:disabled { pointer-events: auto; }
        
        .table-danger-light {
            --bs-table-bg: var(--bs-table-bg-light-danger);
            --bs-table-border-color: var(--bs-table-border-light-danger);
            --bs-table-striped-bg: #f7e0e3;
            --bs-table-hover-bg: #f3d4d9;
        }
        .popover-header {
            font-weight: 600;
            color: var(--bs-danger);
        }
        .dropdown-menu {
            z-index: 1042;
        }

        /* --- MODIFICATION: Style for the NOTE MODAL --- */
        #noteModal .modal-body-note {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            white-space: pre-wrap; /* This respects newlines in the note */
            word-wrap: break-word;
            font-family: monospace;
            max-height: 300px;
            overflow-y: auto;
        }
        /* --- ADD THIS NEW RULE --- */
        .winner-item-line {
            white-space: nowrap;
            overflow: hidden; /* Optional: hides text if it's too long */
            text-overflow: ellipsis; /* Optional: adds '...' if too long */
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
            
            <nav aria-label="breadcrumb" class="mb-2">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="event_manager_dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">My Assigned Events</li>
                </ol>
            </nav>
            <h1 class="section-title mb-4">My Assigned Events</h1>
            
            <div class="alert alert-info d-flex align-items-center" role="alert">
    <i class="fas fa-info-circle fa-2x me-3" style="opacity: 0.8;"></i>
    <div>
        <h5 class="alert-heading mb-1" style="font-weight: 600;">How to Manage Your Events</h5>
        The 'Actions' column is smart. It shows different buttons based on the event's <strong>Type</strong> (Medal vs. Match) and its current <strong>Status</strong>.
        <ul class="mb-0 mt-2" style="padding-left: 1.2rem;">
            <li>
                <strong>`Medal` Events (e.g., Javelin):</strong>
                Follow a 3-step process on this page using the main button:
                <strong>Start</strong> ➔ <strong>Complete</strong> ➔ <strong>Submit</strong>.
            </li>
            <li>
                <strong>`Match` Events (e.g., Badminton):</strong>
                Use the <strong>Manage</strong> button. This takes you to a separate page to set up schedules, update scores, and finalize winners.
            </li>
            <li>
                The <strong>Edit</strong> button (pencil icon) lets you change details like the event name or venue, but not on events that are in-progress.
            </li>
            <li>
                The <strong>Delete</strong> button (trash icon) is only available for <strong>Upcoming</strong> or <strong>Cancelled</strong> events.
            </li>
        </ul>
    </div>
</div>
            
            <!-- This container holds session-based alerts (from POST redirects) -->
            <div id="alert-container">
            <?php if (!empty($alert_message)): ?>
                <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($alert_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            </div>

            <!-- 
              This is the new wrapper div that our JavaScript will target.
              On initial page load, we call render_event_list() to show the data.
              On AJAX polls, the JavaScript will replace the contents of this div.
            -->
            <div id="events-container">
                <?php
                    // This renders the initial list on page load
                    // The function is defined in 'my_events_view.php'
                    render_event_list($managed_data, $college_map);
                ?>
            </div>

        </div> <!-- End of .container-fluid -->
    </div> <!-- End of .main-content -->
    
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
                        <h5 class="modal-title" id="categoryModalLabel">Add Category</h5>
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
                        <button type="submit" class="btn btn-primary">Save Category</button>
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
                        <h5 class="modal-title" id="deleteModalLabel">Confirm Deletion</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete: <strong id="delete_category_name" class="text-dark"></strong>?</p>
                        <p class="text-danger mb-0">This will permanently delete the category. This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Yes, Delete Category</button>
                    </div>
                </form>
            </div> 
        </div>
    </div>
    
    <!-- Start Event Modal -->
    <div class="modal fade" id="startModal" tabindex="-1" aria-labelledby="startModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header bg-success text-white">
            <h5 class="modal-title" id="startModalLabel">Confirm Event Start</h5>
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
                <button type="submit" class="btn btn-success">Yes, Start Event</button>
              </div>
          </form>
        </div>
      </div>
    </div>
    
    <!-- Complete Event Modal -->
    <div class="modal fade" id="completeModal" tabindex="-1" aria-labelledby="completeModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header bg-success text-white">
            <h5 class="modal-title" id="completeModalLabel">Confirm Event Completion</h5>
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
                <button type="submit" class="btn btn-success">Yes, Mark as Completed</button>
              </div>
          </form>
        </div>
      </div>
    </div>

    <!-- --- MODIFICATION: Added Rejection Note Modal BACK --- -->
    <div class="modal fade" id="noteModal" tabindex="-1" aria-labelledby="noteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <!-- MODIFICATION: Changed header to bg-danger -->
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="noteModalLabel"><i class="fas fa-exclamation-triangle me-2"></i>Rejection Note</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">The administrator left the following note for: <strong id="note_category_name"></strong></p>
                    <div id="note_text" class="modal-body-note">
                        <!-- Note content will be injected here by JavaScript -->
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
                    trigger: 'focus' // This makes it dismissible when you click away
                });
            });
        }

        /**
         * Fetches the latest event data from the server and updates the DOM.
         */
        async function fetchEventUpdates() {
            // If an update is already in progress, skip this one.
            if (isFetchingUpdates) {
                console.log('Update fetch already in progress, skipping.');
                return;
            }
            
            isFetchingUpdates = true;

            try {
                // We add a timestamp (cache-buster) to prevent browser caching
                // And partial=1 to tell the server we only want the event list
                const response = await fetch(`my_events.php?partial=1&t=${Date.now()}`);
                
                // Find the containers
                const container = document.getElementById('events-container');
                const alertContainer = document.getElementById('alert-container');

                if (!response.ok) {
                    console.error('Failed to fetch updates, server responded with:', response.status);
                    
                    if(response.status === 401) { // Unauthorized
                        const html = await response.text();
                        if (alertContainer) {
                            // Show the "session expired" message
                            alertContainer.innerHTML = html;
                        }
                        // Stop polling if session is dead
                        stopPolling(); 
                    }
                    return;
                }
                
                const html = await response.text();
                
                if (container) {
                    // Replace the content of the events container
                    container.innerHTML = html;
                    // Re-initialize all tooltips and popovers inside the new content
                    initDynamicComponents(container);
                }
            } catch (error) {
                console.error('Error fetching real-time updates:', error);
            } finally {
                // Always set the flag to false when done
                isFetchingUpdates = false;
            }
        }
        
        /**
         * Starts the polling interval
         */
        function startPolling() {
            // Clear any existing interval just in case
            stopPolling(); 
            // Start a new one (e.g., every 10 seconds)
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
                // User switched tabs or minimized
                stopPolling();
            } else if (document.visibilityState === 'visible') {
                // User came back
                console.log('Page is visible. Fetching immediate update...');
                // 1. Fetch updates immediately
                fetchEventUpdates();
                // 2. Restart the regular polling
                startPolling();
            }
        }


        // --- MAIN EXECUTION ON PAGE LOAD ---
        document.addEventListener('DOMContentLoaded', function () {
            
            const eventsContainer = document.getElementById('events-container');
            if (eventsContainer) {
                // Initial call to set up tooltips/popovers on page load
                initDynamicComponents(eventsContainer);
            }

            // Start polling for the first time
            startPolling();
            
            // Add the Page Visibility API listener
            document.addEventListener('visibilitychange', handleVisibilityChange, false);
            
            // --- Modal logic for Add/Edit Category ---
            const categoryModal = document.getElementById('categoryModal');
            
            categoryModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget; 
                if (!button || button.disabled) {
                    event.preventDefault();
                    return;
                }
                
                const action = button.getAttribute('data-action');
                
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
                
                const eventId = button.getAttribute('data-event-id');
                const eventName = button.getAttribute('data-event-name');
                
                eventIdInput.value = eventId;
                eventNameInput.value = eventName;

                if (action === 'edit') {
                    modalTitle.textContent = 'Edit Category';
                    categoryIdInput.value = button.getAttribute('data-category-id');
                    categoryNameInput.value = button.getAttribute('data-category-name');
                    let status = button.getAttribute('data-status');
                    categoryStatusSelect.value = status; 
                    categoryTypeSelect.value = button.getAttribute('data-category-type');
                    categoryTypeSelect.disabled = true; 
                    eventDateInput.value = button.getAttribute('data-event-date');
                    eventTimeInput.value = button.getAttribute('data-event-time');
                    venueInput.value = button.getAttribute('data-venue');
                } else {
                    modalTitle.textContent = 'Add Category to ' + eventName;
                    categoryIdInput.value = '';
                    categoryNameInput.value = '';
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

            // --- MODIFICATION: Added listener for "Note" Modal BACK ---
            var noteModal = document.getElementById('noteModal');
            if (noteModal) {
                noteModal.addEventListener('show.bs.modal', function (event) {
                    var button = event.relatedTarget;
                    var categoryName = button.getAttribute('data-category-name');
                    var note = button.getAttribute('data-note');
                    
                    var modalCategoryName = noteModal.querySelector('#note_category_name');
                    var modalNoteText = noteModal.querySelector('#note_text');
                    
                    modalCategoryName.textContent = categoryName;
                    modalNoteText.textContent = note; // Using .textContent preserves newlines
                });
            }

            // Helper function to show a session-based alert
            function showAlert(message, type, container = '#alert-container') {
                const alertContainer = document.querySelector(container);
                if (!alertContainer) return;
                const alert = `
                    <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                        ${message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                `;
                alertContainer.insertAdjacentHTML('afterbegin', alert);
            }
            
            // --- Sidebar Height Adjustment Logic ---
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth > 992) {
                        // This logic is mostly for mobile toggling, can be expanded if needed
                    }
                    // Always adjust height on resize
                    adjustSidebarHeight();
                }, 250);
            });
            
            const sidebar = document.getElementById('sidebar');
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
                setTimeout(adjustSidebarHeight, 100);
            }
        });
    </script>
</body>
</html>