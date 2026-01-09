<?php
session_start();
// Adjust path if necessary: assuming this file is in a subfolder (e.g., /sd/)
require_once '../config.php'; 

// --- 1. SECURITY & ACCESS CONTROL ---
// STRICT: Only 'Sports Director' is allowed.
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Sports Director') {
    header('Location: ../login.php'); 
    exit();
}

// Ensure user_id is set
if (!isset($_SESSION['user_id'])) {
    die("Session error: User ID is not set. Please log in again.");
}
$user_id = $_SESSION['user_id']; 

// --- FETCH USER FULL NAME ---
// We query the DB specifically to get the full_name to avoid showing the email/username
$stmt_name = $conn->prepare("SELECT full_name, username FROM users WHERE id = ?");
$stmt_name->bind_param("i", $user_id);
$stmt_name->execute();
$user_data = $stmt_name->get_result()->fetch_assoc();
$stmt_name->close();

// Use full_name if available, otherwise fallback to username, then default text
$name = !empty($user_data['full_name']) ? $user_data['full_name'] : ($user_data['username'] ?? 'Sports Director');

$current_page = basename($_SERVER['PHP_SELF']);

// --- 2. DATA FETCHING & LOGIC ---

/**
 * Helper: Fetch a single count value safely
 */
function fetchCount($conn, $query) {
    $result = $conn->query($query);
    return ($result) ? $result->fetch_row()[0] : 0;
}

// A. SYSTEM STATISTICS
// Aggregating data from across the entire database
// A. SYSTEM STATISTICS
// Aggregating data from across the entire database
$stats = [
    // Tournament Data
    'events'          => fetchCount($conn, "SELECT COUNT(*) FROM game_events"), // L2 Events
    'categories'      => fetchCount($conn, "SELECT COUNT(*) FROM categories"),  // L3 Categories (Specifics)
    'teams'           => fetchCount($conn, "SELECT COUNT(*) FROM colleges"),
    
    // Administrative Data
    'users'           => fetchCount($conn, "SELECT COUNT(*) FROM users"),
    'pending_requests'=> fetchCount($conn, "SELECT COUNT(*) FROM users WHERE is_approved = 0"),
    
    // Tallying Data
    'pending_results' => fetchCount($conn, "SELECT COUNT(*) FROM categories WHERE status='Results Submitted'"),
    'total_gold'      => fetchCount($conn, "SELECT SUM(gold_count) FROM categories WHERE status='Results Approved'")
];
$stats['total_gold'] = $stats['total_gold'] ?? 0; // Handle null

// B. LOGGING SYSTEM LOGIC

/**
 * Get User Name (Cached)
 */
function getUserNameById($conn, $id) {
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    
    $stmt = $conn->prepare("SELECT full_name, username FROM users WHERE id = ?"); 
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return $cache[$id] = ($res['full_name'] ?? $res['username'] ?? "Unknown User");
}

/**
 * Format Log Entries for Display
 */
function formatLogEntry($conn, $log, $current_user_id) {
    $actor = ($log['actor_user_id'] == $current_user_id) ? "<strong>You</strong>" : "<strong>" . htmlspecialchars(getUserNameById($conn, $log['actor_user_id'])) . "</strong>";
    $ctx = json_decode($log['log_context'], true) ?? [];
    $action = trim($log['action_type']);
    $msg = "Action performed.";
    $icon = "fas fa-info-circle text-muted";

    switch ($action) {
        // --- Game/Event Actions ---
        case 'CREATED_GAME': 
            $msg = "$actor created a new game: <strong>" . htmlspecialchars($ctx['game_name']??'') . "</strong>."; 
            $icon = "fas fa-plus-circle text-success"; 
            break;
        case 'CREATED_EVENT': 
            $msg = "$actor created the event <strong>" . htmlspecialchars($ctx['event_name']??'') . "</strong>."; 
            $icon = "fas fa-calendar-plus text-success"; 
            break;
        
        // --- Team Actions ---
        case 'CREATED_COLLEGE': 
            $msg = "$actor added a new team: <strong>" . htmlspecialchars($ctx['college_name']??'') . "</strong>."; 
            $icon = "fas fa-users text-info"; 
            break;
        case 'UPDATED_COLLEGE': 
            $msg = "$actor updated team info for <strong>" . htmlspecialchars($ctx['college_name']??'') . "</strong>."; 
            $icon = "fas fa-pen text-info"; 
            break;
        
        // --- Result Actions ---
        case 'APPROVED_RESULT': 
            $msg = "$actor approved the results for <strong>" . htmlspecialchars($ctx['event_name']??'') . "</strong>."; 
            $icon = "fas fa-check-double text-success"; 
            break;
        case 'REVOKED_RESULT': 
            // FIXED: Changed **revoked** to HTML so it looks right
            $msg = "$actor <span class='text-danger fw-bold'>revoked</span> the results for <strong>" . htmlspecialchars($ctx['event_name']??'') . "</strong>."; 
            $icon = "fas fa-undo text-danger"; 
            break;
        case 'REJECTED_RESULT': 
            $msg = "$actor rejected the results for <strong>" . htmlspecialchars($ctx['event_name']??'') . "</strong>."; 
            $icon = "fas fa-times-circle text-warning"; 
            break;

        // --- Admin Actions ---
        case 'UPDATED_USER': 
            // Slightly friendlier wording
            $msg = "$actor updated a user account profile."; 
            $icon = "fas fa-user-edit text-warning"; 
            break;
        case 'APPROVED_REQUEST': 
            $msg = "$actor approved a new account request."; 
            $icon = "fas fa-user-check text-success"; 
            break;
        
        // --- Archive Actions ---
        case 'ARCHIVED_SEASON': 
            $msg = "$actor archived the season and reset the system."; 
            $icon = "fas fa-archive text-primary"; 
            break;

        // --- EVENT MANAGER ACTIONS ---
        case 'SUBMITTED_RESULTS':
            $cat_name = htmlspecialchars($ctx['category_name'] ?? 'an event');
            $msg = "$actor submitted results for <strong>$cat_name</strong>.";
            $icon = "fas fa-paper-plane text-warning"; 
            break;

        case 'CREATED_CATEGORY':
            $cat_name = htmlspecialchars($ctx['category_name'] ?? 'a new category');
            $msg = "$actor added a new category: <strong>$cat_name</strong>.";
            $icon = "fas fa-plus-circle text-success";
            break;

        case 'DELETED_CATEGORY':
            $cat_name = htmlspecialchars($ctx['deleted_category_name'] ?? 'a category');
            $msg = "$actor deleted the category <strong>$cat_name</strong>.";
            $icon = "fas fa-trash-alt text-danger";
            break;

        case 'UPDATED_CATEGORY':
             $cat_name = htmlspecialchars($ctx['new_category_name'] ?? 'a category');
             $msg = "$actor updated details for <strong>$cat_name</strong>.";
             $icon = "fas fa-edit text-info";
             break;

        // --- Default Case (Always Last) ---
        default: 
            $msg = "$actor performed <strong>$action</strong>."; 
            break;
    }
    
    return ['icon' => $icon, 'message' => $msg, 'time' => date('M d, h:i A', strtotime($log['created_at']))];
}

// Fetch Recent Logs
$processed_logs = [];
$log_res = $conn->query("SELECT * FROM system_logs ORDER BY created_at DESC LIMIT 6");
if ($log_res) {
    while ($row = $log_res->fetch_assoc()) {
        $processed_logs[] = formatLogEntry($conn, $row, $user_id);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Director Dashboard - PIT Sports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* --- Unified & Modern CSS --- */
        :root { 
            --sidebar-width: 260px; 
            --header-height: 82px; 
            --transition: all 0.3s ease; 
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); 
            --bg-light: #F8F9FA; 
            --primary-gradient: linear-gradient(135deg, #2c3e50 0%, #4ca1af 100%);
            --accent-color: #1abc9c;
        }
        body { background-color: var(--bg-light); margin: 0; padding: 0; min-height: 100vh; font-family: 'Inter', sans-serif; display: flex; flex-direction: column; }
        
        /* Navbar */
        .navbar { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.15); padding: 1rem 1.5rem; height: var(--header-height); position: fixed; top: 0; left: 0; right: 0; z-index: 1050; }
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; transition: var(--transition); }
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
        .user-dropdown .dropdown-toggle img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; margin-right: 10px; }
        
        /* Sidebar */
        .sidebar { width: var(--sidebar-width); position: fixed; top: var(--header-height); left: 0; height: calc(100vh - var(--header-height)); background: #2c3e50; color: white; box-shadow: 5px 0 15px rgba(0,0,0,0.2); z-index: 1040; transition: width var(--transition); overflow-y: auto; }
        .sidebar-nav { padding: 20px 0; }
        .sidebar-nav .nav-link { color: rgba(255, 255, 255, 0.7); font-size: 1.05rem; font-weight: 500; padding: 12px 25px; transition: var(--transition); border-left: 5px solid transparent; margin: 2px 0; display: flex; align-items: center; text-decoration: none; }
        .sidebar-nav .nav-link i { width: 30px; text-align: center; flex-shrink: 0; font-size: 0.95em; }
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
        .sidebar.minimized ~ footer {
            padding-left: var(--sidebar-min-width);
        }
        /* Hero */
        .hero-section { background: var(--primary-gradient); color: white; padding: 40px; border-radius: 15px; margin-bottom: 30px; box-shadow: var(--card-shadow); position: relative; overflow: hidden; }
        .welcome-badge { background: rgba(255,255,255,0.2); padding: 6px 16px; border-radius: 30px; font-size: 0.85rem; font-weight: 600; display: inline-block; backdrop-filter: blur(5px); letter-spacing: 0.5px; }
        
        /* Cards */
        .stat-card { background: white; border: none; border-radius: 15px; padding: 20px; box-shadow: var(--card-shadow); transition: transform 0.3s; height: 100%; border-left: 5px solid transparent; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem; margin-right: 15px; flex-shrink: 0; }
        .stat-count { font-size: 2.2rem; font-weight: 700; line-height: 1; font-family: 'Poppins', sans-serif; color: #2c3e50; }
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; margin-bottom: 20px; }

        /* Quick Actions */
        .quick-action-card { text-align: center; padding: 20px; background: white; border-radius: 15px; box-shadow: var(--card-shadow); transition: all 0.3s; text-decoration: none; color: #333; display: block; height: 100%; border: 1px solid rgba(0,0,0,0.05); }
        .quick-action-card:hover { transform: translateY(-5px); border-color: var(--accent-color); background: #fcfcfc; color: var(--accent-color); }
        .quick-icon { font-size: 2.5rem; margin-bottom: 15px; display: block; transition: color 0.3s; }

        
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
        @media (max-width: 992px) {
            .sidebar { left: -260px; }
            .sidebar.show { left: 0; }
            .main-content, footer { margin-left: 0; }
        }
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
                    <i class="fas fa-user-circle" style="font-size: 36px; margin-right: 10px;"></i>
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
                <a class="nav-link <?= ($current_page == 'sports_director_dashboard.php') ? 'active' : '' ?>" href="sports_director_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                </a>
            </li>
            
            <li class="nav-item mt-3"><span class="nav-title">Tournament Mgmt</span></li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'colleges.php') ? 'active' : '' ?>" href="colleges.php">
                    <i class="fas fa-users me-2"></i> <span>Manage Teams</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'events.php') ? 'active' : '' ?>" href="events.php">
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
                    <?php if($stats['pending_requests'] > 0): ?>
                        <span class="badge bg-danger ms-auto rounded-pill"><?= $stats['pending_requests'] ?></span>
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
                <a class="nav-link <?= ($current_page == 'results.php') ? 'active' : '' ?>" href="results.php">
                    <i class="fas fa-check-double me-2"></i> <span>Approve Results</span>
                    <?php if($stats['pending_results'] > 0): ?>
                        <span class="badge bg-warning text-dark ms-auto rounded-pill"><?= $stats['pending_results'] ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'reports.php') ? 'active' : '' ?>" href="reports.php">
                    <i class="fas fa-chart-line me-2"></i> <span>Medal Standings</span>
                </a>
            </li>

            <!-- NEW SECTION: SEASON MANAGEMENT -->
            <li class="nav-item mt-3"><span class="nav-title">Season Management</span></li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'manage_archives.php') ? 'active' : '' ?>" href="../manage_archives.php">
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
            
            <!-- Hero Section (PROFESSIONAL WELCOME BOARD) -->
            <div class="hero-section">
                <div class="row align-items-center">
                    <div class="col-lg-9">
                        <div class="welcome-badge mb-3"><i class="fas fa-crown me-1"></i> Head Administrator</div>
                        
                        <h1 class="fw-bold mb-1">Welcome to SmartScore</h1>
                        <h5 class="fw-light mb-3 text-white-50">A Web-Based Scoring and Medal Tally Platform for Siglakas Events</h5>
                        
                        <hr class="my-4" style="border-color: rgba(255,255,255,0.15); width: 60%;">
                        
                        <p class="lead fs-6 opacity-90 mb-0" style="line-height: 1.7; font-weight: 400;">
                            Good day, <strong><?= htmlspecialchars($name) ?></strong>. You are now accessing the central command unit for the Siglakas tournament. 
                            As the Sports Director, this dashboard empowers you with the tools to oversee event progression, validate official results, and maintain the integrity of the medal tally. 
                            Please utilize the modules below to manage competition data effectively.
                        </p>
                    </div>
                    <div class="col-lg-3 text-end d-none d-lg-block">
                        <!-- Icon representing Data/Tallying/Growth -->
                        <i class="fas fa-chart-pie fa-6x opacity-25" style="transform: rotate(-10deg);"></i>
                    </div>
                </div>
            </div>

            <!-- Row 1: Key Tournament Counts --> 
            <h5 class="section-title">Tournament Overview</h5>
            <div class="row g-4 mb-4">
                <div class="col-md-6 col-lg-3">
                    <div class="stat-card" style="border-left-color: #007bff;">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-primary"><i class="fas fa-calendar-day"></i></div>
                            <div>
                                <div class="stat-count"><?= $stats['events'] ?></div>
                                <small class="text-muted">Main Events (L2)</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="stat-card" style="border-left-color: #198754;">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-success"><i class="fas fa-users"></i></div>
                            <div>
                                <div class="stat-count"><?= $stats['teams'] ?></div>
                                <small class="text-muted">Teams Registered</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="stat-card" style="border-left-color: #0dcaf0;">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-info text-white"><i class="fas fa-layer-group"></i></div>
                            <div>
                                <div class="stat-count"><?= $stats['categories'] ?></div>
                                <small class="text-muted">Total Categories (L3)</small>
                            </div>
                        </div>
                    </div>
                </div>
                 <div class="col-md-6 col-lg-3">
                    <div class="stat-card" style="border-left-color: #ffc107;">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-warning text-dark"><i class="fas fa-medal"></i></div>
                            <div>
                                <div class="stat-count"><?= $stats['total_gold'] ?></div>
                                <small class="text-muted">Gold Medals Awarded</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 2: Management & Results -->
            <h5 class="section-title">Live Status</h5>
            <div class="row g-4 mb-5">
                
                
                <div class="col-lg-4">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted text-uppercase mb-2">Result Approvals</h6>
                                <h2 class="mb-0 fw-bold text-danger"><?= $stats['pending_results'] ?></h2>
                            </div>
                            <div class="text-end">
                                <i class="fas fa-gavel fa-2x text-muted opacity-25 mb-2"></i>
                                <div class="badge bg-danger d-block">Action Needed</div>
                            </div>
                        </div>
                         <hr class="my-3 opacity-10">
                         <a href="results.php" class="btn btn-outline-danger btn-sm w-100 rounded-pill">Review Pending Results</a>
                    </div>
                </div> 

                <div class="col-lg-4">
                     <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted text-uppercase mb-2">Account Requests</h6>
                                <h2 class="mb-0 fw-bold text-warning"><?= $stats['pending_requests'] ?></h2>
                            </div>
                            <div class="text-end">
                                <i class="fas fa-user-plus fa-2x text-muted opacity-25 mb-2"></i>
                                <div class="badge bg-warning text-dark d-block">Pending</div>
                            </div>
                        </div>
                         <hr class="my-3 opacity-10">
                         <a href="../Manage_Requests.php" class="btn btn-outline-warning text-dark btn-sm w-100 rounded-pill">Manage Requests</a>
                    </div>
                </div>
            </div>
            
            <!-- Row 3: Quick Actions & Logs -->
            <div class="row g-4">
                <div class="col-lg-4">
                    <h5 class="section-title">Quick Actions</h5>
                    <div class="row g-3">
                        <div class="col-6">
                            <a href="colleges.php" class="quick-action-card">
                                <i class="fas fa-users quick-icon text-success"></i>
                                <div class="fw-bold">Teams</div>
                            </a>
                        </div>
                        <div class="col-6">
                             <a href="../Manage_Users.php" class="quick-action-card">
                                <i class="fas fa-users-cog quick-icon text-info"></i>
                                <div class="fw-bold">Users</div>
                            </a>
                        </div>
                        <div class="col-6">
                             <a href="reports.php" class="quick-action-card">
                                <i class="fas fa-print quick-icon text-secondary"></i>
                                <div class="fw-bold">Reports</div>
                            </a>
                        </div>
                         <div class="col-6">
                             <a href="events.php" class="quick-action-card">
                                <i class="fas fa-calendar-alt quick-icon text-primary"></i>
                                <div class="fw-bold">Events</div>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <h5 class="section-title">Recent System Activity</h5>
                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush rounded-4">
                                <?php if (empty($processed_logs)): ?>
                                    <div class="p-5 text-center text-muted">
                                        <i class="fas fa-history fa-2x mb-3"></i><br>No recent activity logs found.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($processed_logs as $log): ?>
                                        <div class="list-group-item d-flex align-items-center py-3 px-4 border-bottom-0 border-top">
                                            <div class="me-3">
                                                <i class="<?= $log['icon'] ?> fa-lg"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="mb-0 text-dark"><?= $log['message'] ?></div>
                                                <small class="text-muted"><i class="far fa-clock me-1"></i> <?= $log['time'] ?></small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-footer bg-white text-center py-3 border-top rounded-bottom-4">
                            <a href="../Manage_Viewreports.php" class="btn btn-sm btn-light text-muted rounded-pill px-4">View All Logs</a>
                        </div>
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
                        <img src="../imageslogo.png" alt="Logo">
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
                <small>Developed by Jayvee Baybyon</small>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('mobileToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });

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
            const sidebar = document.getElementById('sidebar'); // Ensure sidebar variable is defined
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
    </script>
</body>
</html>