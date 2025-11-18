<?php
session_start();
require_once 'config.php'; // Your DB connection

// 1. SECURITY & ACCESS CONTROL
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Event Manager') {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Event Manager';
$current_page = basename($_SERVER['PHP_SELF']);

// --- 2. RECENT ACTIVITY & HELPER FUNCTIONS ---

/**
 * Fetches a user's display name by their ID.
 * (This is for the activity log)
 */
function getUserNameById_EM($conn, $id) {
    static $user_cache = [];
    if (isset($user_cache[$id])) {
        return $user_cache[$id];
    }
    
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?"); 
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $user_cache[$id] = $row['username'];
        return $row['username'];
    }
    
    return "Unknown User";
}

/**
 * Formats a raw log entry into a human-readable array.
 * (This is a simplified version for the Event Manager)
 */
function formatLogEntry_EM($conn, $log, $current_user_id) {
    // Determine who did the action
    // In this module, it will always be "You" since we filter by user_id
    $actor_name = "<strong>You</strong>";
   
    // Decode the saved context
    $context = json_decode($log['log_context'], true) ?? [];
    
    $message = "";
    $icon = "fas fa-info-circle text-muted"; // Default icon
    $time = date('M d, h:i A', strtotime($log['created_at']));

    switch (trim($log['action_type'])) {
        
        // --- CATEGORY (L3) ACTIONS ---
        case 'CREATED_CATEGORY':
            $cat_name = htmlspecialchars($context['category_name'] ?? 'a new category');
            $status_msg = "";
            // Add the status if it was logged
            if (!empty($context['status'])) {
                $status_msg = " (Status: " . htmlspecialchars($context['status']) . ")";
            }
            $message = "$actor_name created the category <strong>\"$cat_name\"</strong>$status_msg.";
            $icon = "fas fa-plus-circle text-success";
            break;

        case 'UPDATED_CATEGORY':
            $cat_name = htmlspecialchars($context['new_category_name'] ?? 'a category');
            $status_msg = "";
            // Add the *new* status if it was logged
            if (!empty($context['new_status'])) {
                $status_msg = " and set status to <strong>" . htmlspecialchars($context['new_status']) . "</strong>";
            }
            $message = "$actor_name updated the category <strong>\"$cat_name\"</strong>$status_msg.";
            $icon = "fas fa-pencil-alt text-info";
            break;

        case 'DELETED_CATEGORY':
            $cat_name = htmlspecialchars($context['deleted_category_name'] ?? 'a category');
            $message = "$actor_name deleted the category <strong>\"$cat_name\"</strong>.";
            $icon = "fas fa-trash-alt text-danger";
            break;

        case 'SUBMITTED_RESULTS': // A likely action from other pages
            $cat_name = htmlspecialchars($context['category_name'] ?? 'a category');
            $message = "$actor_name submitted results for <strong>\"$cat_name\"</strong>.";
            $icon = "fas fa-paper-plane text-primary";
            break;
            
        // ### ADDED: New cases for match logging ###
        case 'CREATED_MATCH':
            $cat_name = htmlspecialchars($context['category_name'] ?? 'a category');
            $message = "$actor_name created a new match in <strong>\"$cat_name\"</strong>.";
            $icon = "fas fa-plus-circle text-success";
            break;
            
        case 'UPDATED_MATCH':
            $cat_name = htmlspecialchars($context['category_name'] ?? 'a category');
            $status_msg = !empty($context['status']) ? " (Status: " . htmlspecialchars($context['status']) . ")" : "";
            $message = "$actor_name updated a match in <strong>\"$cat_name\"</strong>$status_msg.";
            $icon = "fas fa-pencil-alt text-info";
            break;
            
        case 'DELETED_MATCH':
            $cat_name = htmlspecialchars($context['category_name'] ?? 'a category');
            $message = "$actor_name deleted a match from <strong>\"$cat_name\"</strong>.";
            $icon = "fas fa-trash-alt text-danger";
            break;
        // ### END: New cases ###
            
        default:
            if (!empty($log['action_type'])) {
                 $message = "$actor_name performed an action: <strong>" . htmlspecialchars(trim($log['action_type'])) . "</strong>";
                 $icon = "fas fa-info-circle text-muted";
            } else {
                 $message = "$actor_name performed an unknown action.";
                 $icon = "fas fa-question-circle text-muted";
            }
            break;
    }

    return [
        'icon' => $icon,
        'message' => $message,
        'time' => $time
    ];
}
// Fetch this user's logs
$processed_logs = [];
$current_user_id = $user_id; 

try {
    $stmt_logs = $conn->prepare("SELECT * FROM system_logs WHERE actor_user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt_logs->bind_param("i", $current_user_id);
    $stmt_logs->execute();
    $result_logs = $stmt_logs->get_result();
    
    if ($result_logs) {
        $logs = $result_logs->fetch_all(MYSQLI_ASSOC);
        foreach ($logs as $log) {
            // Process each log into a human-readable format
            $processed_logs[] = formatLogEntry_EM($conn, $log, $current_user_id);
        }
    }
    $stmt_logs->close();
} catch (Exception $e) {
    // If system_logs table doesn't exist, just show an empty log
    $processed_logs = [];
    error_log("Failed to fetch system logs: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Manager Dashboard - PIT Tallying</title>
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
        .card { border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        .stat-card { background: white; border-radius: 15px; padding: 20px; box-shadow: var(--card-shadow); }
        .stat-icon-wrapper { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem; margin-right: 15px; flex-shrink: 0; }
        .stat-count { font-size: 2.5rem; font-weight: 700; line-height: 1; }
        .stat-events .stat-icon-wrapper { background: linear-gradient(45deg, #3498db, #2980b9); }
        .stat-categories .stat-icon-wrapper { background: linear-gradient(45deg, #2ecc71, #27ae60); }
        .stat-pending .stat-icon-wrapper { background: linear-gradient(45deg, #f1c40f, #f39c12); }
        .stat-medals .stat-icon-wrapper { background: linear-gradient(45deg, #FFD700, #f39c12); }
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
        .navbar-profile-icon {
            width: 36px; height: 36px; font-size: 36px; text-align: center;
            line-height: 1; border-radius: 50%; margin-right: 10px; color: rgba(255,255,255,0.8);
        }
        
        /* ### NEW: Activity Log Styles ### */
        .activity-log-item {
            display: flex;
            align-items: start;
            padding: 0.75rem 0.25rem;
            border-bottom: 1px solid #eee;
        }
        .activity-log-item:last-child {
            border-bottom: none;
        }
        .activity-log-icon {
            width: 30px;
            text-align: center;
            flex-shrink: 0;
            font-size: 1.1rem;
            margin-top: 2px;
        }
        .activity-log-content {
            flex-grow: 1;
            line-height: 1.4;
        }
        .activity-log-time {
            font-size: 0.8rem;
            color: #6c757d; /* text-muted */
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items: center justify-content-between">
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
                <a class="nav-link active" href="event_manager_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="my_events.php">
                    <i class="fas fa-trophy me-2"></i> <span>My Assigned Events</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="Event.php" target="_blank">
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
                    <li class="breadcrumb-item active" aria-current="page">Dashboard</li>
                </ol>
            </nav>
            <h1 class="section-title mb-4">Event Manager Dashboard</h1>

            <div class="row g-4 mb-4">
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card stat-events">
                        <div class="d-flex align-items: center">
                            <div class="stat-icon-wrapper"><i class="fas fa-trophy"></i></div>
                            <div>
                                <div class="stat-count text-primary" id="stat-assigned-events">...</div>
                                <small class="text-muted">Assigned Events</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card stat-categories">
                        <div class="d-flex align-items: center">
                            <div class="stat-icon-wrapper"><i class="fas fa-sitemap"></i></div>
                            <div>
                                <div class="stat-count text-success" id="stat-total-categories">...</div>
                                <small class="text-muted">Total Categories</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card stat-pending">
                        <div class="d-flex align-items: center">
                            <div class="stat-icon-wrapper"><i class="fas fa-hourglass-half"></i></div>
                            <div>
                                <div class="stat-count text-warning" id="stat-pending-results">...</div>
                                <small class="text-muted">Pending Results</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card stat-medals">
                        <div class="d-flex align-items: center">
                            <div class="stat-icon-wrapper"><i class="fas fa-medal"></i></div>
                            <div>
                                <div class="stat-count text-warning" id="stat-approved-medals">...</div>
                                <small class="text-muted">Approved Medals (My Events)</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row g-4 mt-2">
            
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header"><h5 class="mb-0"><i class="fas fa-compass me-2"></i>Quick Navigation</h5></div>
                        <div class="card-body">
                            <div class="list-group">
                                <a href="my_events.php" class="list-group-item list-group-item-action list-group-item-primary d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="fas fa-trophy me-2"></i>
                                        <strong>Manage My Assigned Events</strong>
                                        <small class="d-block text-muted">View, add, and edit categories and results.</small>

                                    </div>
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                                <a href="Event.php" target="_blank" class="list-group-item list-group-item-action">
                                    <i class="fas fa-globe me-2"></i>
                                    View Public Tallying Site
                                </a>
                                <a href="admin_profile.php" class="list-group-item list-group-item-action">
                                    <i class="fas fa-user-circle me-2"></i>
                                    View My Profile
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-history me-2"></i>My Recent Activity</h5>
                        </div>
                        <div class="card-body" style="padding: 0.5rem 1rem;">
                            <ul class="list-unstyled mb-0">
                                <?php if (empty($processed_logs)): ?>
                                    <li class="activity-log-item text-muted">
                                        <i class="activity-log-icon fas fa-info-circle"></i>
                                        <div class="activity-log-content">
                                            No recent activity to display.
                                        </div>
                                    </li>
                                <?php endif; ?>
                                
                                <?php foreach ($processed_logs as $log_entry): ?>
                                    <li class="activity-log-item">
                                        <i class="activity-log-icon <?= $log_entry['icon'] ?>"></i>
                                        <div class="activity-log-content">
                                            <?= $log_entry['message'] ?> 
                                            <div class="activity-log-time"><?= $log_entry['time'] ?></div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                
                </div>
            </div>
        </div>
    </div>
    
    <footer class="bg-dark text-white py-4">
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small>
        </div>
    </footer>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            
            // --- AJAX STATS LOADER ---
            function loadDashboardStats() {
                // This assumes your API file is accessible from this path
                fetch('event_manager_api.php?action=get_dashboard_stats')
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            document.getElementById('stat-assigned-events').textContent = data.stats.assigned_events;
                            document.getElementById('stat-total-categories').textContent = data.stats.total_categories;
                            document.getElementById('stat-pending-results').textContent = data.stats.pending_results;
                            document.getElementById('stat-approved-medals').textContent = data.stats.approved_medals;
                        } else {
                            console.error('Failed to load stats:', data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching stats:', error);
                    });
            }
            loadDashboardStats();

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