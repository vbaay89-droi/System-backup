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

// --- FETCH FULL NAME FROM DB ---
$stmt = $conn->prepare("SELECT full_name, username FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id); 
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc(); 
$stmt->close();

if (!empty($user_data['full_name'])) {
    $display_name = $user_data['full_name'];
} else {
    $display_name = $user_data['username'] ?? $username; 
}

// --- 2. RECENT ACTIVITY & HELPER FUNCTIONS ---

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

// --- HELPER: FORMAT LOGS FOR DASHBOARD (USER-FRIENDLY VERSION) ---
function formatLogEntry_EM($conn, $log, $current_user_id) {
    $actor_name = "<strong>You</strong>"; // Since it's your dashboard, always say "You"
    $context = json_decode($log['log_context'], true) ?? [];
    $message = "";
    $icon = "fas fa-info-circle text-muted"; 
    $bg_class = "bg-light"; 
    $time = date('M d, h:i A', strtotime($log['created_at']));

    switch (trim($log['action_type'])) {
        // --- 1. ADD (CREATE) ---
        case 'CREATED_CATEGORY':
            $cat_name = htmlspecialchars($context['category_name'] ?? 'an event');
            $message = "$actor_name added a new event: <strong>\"$cat_name\"</strong>.";
            $icon = "fas fa-plus text-success";
            $bg_class = "bg-success bg-opacity-10";
            break;

        // --- 2. EDIT (UPDATE) ---
        case 'UPDATED_CATEGORY':
            $cat_name = htmlspecialchars($context['new_category_name'] ?? 'an event');
            $changes = [];

            // Check what specifically changed to make it detailed
            if (!empty($context['new_status']) && ($context['old_status'] ?? '') !== $context['new_status']) {
                $changes[] = "status to <strong>" . htmlspecialchars($context['new_status']) . "</strong>";
            }
            if (!empty($context['new_venue']) && ($context['old_venue'] ?? '') !== $context['new_venue']) {
                $changes[] = "venue to <strong>" . htmlspecialchars($context['new_venue']) . "</strong>";
            }
            if (!empty($context['new_event_date']) && ($context['old_event_date'] ?? '') !== $context['new_event_date']) {
                $changes[] = "date to <strong>" . htmlspecialchars($context['new_event_date']) . "</strong>";
            }
            if (!empty($context['new_event_time']) && ($context['old_event_time'] ?? '') !== $context['new_event_time']) {
                $changes[] = "time to <strong>" . htmlspecialchars($context['new_event_time']) . "</strong>";
            }

            if (!empty($changes)) {
                $message = "$actor_name updated <strong>\"$cat_name\"</strong>: Changed " . implode(', ', $changes) . ".";
            } else {
                $message = "$actor_name updated the details for <strong>\"$cat_name\"</strong>.";
            }
            $icon = "fas fa-edit text-info";
            $bg_class = "bg-info bg-opacity-10";
            break;

        // --- 3. DELETE ---
        case 'DELETED_CATEGORY':
            $cat_name = htmlspecialchars($context['deleted_category_name'] ?? 'a category');
            $message = "$actor_name deleted the event: <strong>\"$cat_name\"</strong>.";
            $icon = "fas fa-trash-alt text-danger";
            $bg_class = "bg-danger bg-opacity-10";
            break;

        // --- 4. SUBMIT RESULTS ---
        case 'SUBMITTED_RESULTS': 
            $cat_name = htmlspecialchars($context['category_name'] ?? 'a category');
            $winners = [];
            
            if (!empty($context['gold']) && $context['gold'] !== 'N/A') {
                $winners[] = "<span class='text-warning'>Gold: " . htmlspecialchars($context['gold']) . "</span>";
            }
            if (!empty($context['silver']) && $context['silver'] !== 'N/A') {
                $winners[] = "<span class='text-secondary'>Silver: " . htmlspecialchars($context['silver']) . "</span>";
            }
            if (!empty($context['bronze']) && $context['bronze'] !== 'N/A') {
                $winners[] = "<span class='text-danger'>Bronze: " . htmlspecialchars($context['bronze']) . "</span>";
            }
            
            $winner_text = !empty($winners) ? "<br><small class='mt-1 d-block'>" . implode(' • ', $winners) . "</small>" : "";
            
            $message = "$actor_name submitted official results for <strong>\"$cat_name\"</strong>.$winner_text";
            $icon = "fas fa-paper-plane text-primary";
            $bg_class = "bg-primary bg-opacity-10";
            break;

        // --- 5. LOGIN ---
        case 'LOGIN':
            $message = "$actor_name logged in successfully.";
            $icon = "fas fa-sign-in-alt text-secondary";
            $bg_class = "bg-secondary bg-opacity-10";
            break;
            
        default:
            $action_clean = ucwords(strtolower(str_replace('_', ' ', $log['action_type'])));
            $message = "$actor_name performed action: <strong>$action_clean</strong>";
            break;
    }

    return [
        'icon' => $icon,
        'bg_class' => $bg_class,
        'message' => $message,
        'time' => $time
    ];
}

// Fetch this user's logs
$processed_logs = [];
$current_user_id = $user_id; 

try {
    $stmt_logs = $conn->prepare("SELECT * FROM system_logs WHERE actor_user_id = ? ORDER BY created_at DESC LIMIT 6");
    $stmt_logs->bind_param("i", $current_user_id);
    $stmt_logs->execute();
    $result_logs = $stmt_logs->get_result();
    
    if ($result_logs) {
        $logs = $result_logs->fetch_all(MYSQLI_ASSOC);
        foreach ($logs as $log) {
            $processed_logs[] = formatLogEntry_EM($conn, $log, $current_user_id);
        }
    }
    $stmt_logs->close();
} catch (Exception $e) {
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
    /* =========================================
       1. CORE VARIABLES & SETUP (Director Theme)
       ========================================= */
    :root { 
        --sidebar-width: 260px; 
        --header-height: 82px; 
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); 
        --bg-light: #f4f6f8; 
        --primary-gradient: linear-gradient(135deg, #2c3e50 0%, #4ca1af 100%); /* Teal/Dark Blue */
        --accent-color: #1abc9c;
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

    /* =========================================
       2. LAYOUT & NAVIGATION
       ========================================= */
    
    /* Navbar - Dark & Sleek */
    .navbar { 
        background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; 
        box-shadow: 0 4px 20px rgba(0,0,0,0.15); 
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
        color: white !important; /* Fixed to white for Director theme */
    }

    .user-dropdown .dropdown-toggle { 
        color: white; 
        display: flex; 
        align-items: center; 
        text-decoration: none; 
        padding: 8px 12px; 
        border-radius: 8px; 
        transition: var(--transition); 
    }
    .user-dropdown .dropdown-toggle:hover { 
        background-color: rgba(255, 255, 255, 0.1); 
    }
    .user-dropdown .dropdown-toggle img { 
        width: 36px; height: 36px; 
        border-radius: 50%; object-fit: cover; margin-right: 10px; 
    }

    /* Sidebar - Solid Dark Blue */
    .sidebar { 
        width: var(--sidebar-width); 
        position: fixed; 
        top: var(--header-height); 
        left: 0; 
        height: calc(100vh - var(--header-height)); 
        background: #2c3e50; 
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
        display: flex; 
        align-items: center; 
        text-decoration: none; 
    }
    .sidebar-nav .nav-link i { width: 30px; text-align: center; flex-shrink: 0; font-size: 0.95em; }
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

    /* =========================================
       3. COMPONENTS (Cards, Icons, Hero)
       ========================================= */
    
    /* Hero Section */
    .hero-section { 
        background: var(--primary-gradient); 
        color: white; 
        padding: 40px; 
        border-radius: 16px; 
        margin-bottom: 30px; 
        box-shadow: var(--card-shadow); 
        position: relative; 
        overflow: hidden; 
    }
    .welcome-badge { 
        background: rgba(255,255,255,0.2); 
        padding: 6px 16px; 
        border-radius: 30px; 
        font-size: 0.85rem; 
        font-weight: 600; 
        display: inline-block; 
        backdrop-filter: blur(5px); 
        letter-spacing: 0.5px; 
    }

    /* Modern Dashboard Card (Replaces .stat-card) */
    .dashboard-card { 
        background: white; 
        border-radius: 16px; 
        border: 1px solid rgba(0,0,0,0.02); 
        box-shadow: 0 4px 20px rgba(0,0,0,0.03); 
        padding: 24px; 
        height: 100%; 
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
    }
    .dashboard-card:hover { 
        transform: translateY(-5px); 
        box-shadow: 0 15px 30px rgba(0,0,0,0.06); 
    }
    
    .section-title { 
        font-family: 'Inter', sans-serif; 
        font-size: 0.85rem; 
        font-weight: 700; 
        text-transform: uppercase; 
        letter-spacing: 1px; 
        color: #8898aa; 
        margin-bottom: 1.5rem; 
    }
    
    /* Icon Squares (New Style) */
    .icon-square { 
        width: 60px; height: 60px; 
        border-radius: 14px; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        font-size: 1.6rem; 
        margin-right: 20px; 
        flex-shrink: 0; 
    }
    .theme-blue { background: rgba(0, 123, 255, 0.1); color: #007bff; }
    .theme-green { background: rgba(25, 135, 84, 0.1); color: #198754; }
    .theme-orange { background: rgba(253, 126, 20, 0.1); color: #fd7e14; }
    .theme-gold { background: rgba(255, 193, 7, 0.1); color: #ffc107; }

    /* Stats Typography */
    .stat-value { 
        font-family: 'Inter', sans-serif; 
        font-size: 2.5rem; 
        font-weight: 800; 
        color: #2c3e50; 
        line-height: 1.1; 
        margin-bottom: 4px; 
        letter-spacing: -1px; 
    }
    .stat-label { 
        font-size: 0.85rem; 
        font-weight: 600; 
        color: #6c757d; 
        text-transform: uppercase; 
        letter-spacing: 0.5px; 
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
/* =========================================
   MOBILE OPTIMIZATION (Event Manager)
   ========================================= */
@media (max-width: 991.98px) {

    /* === 1. COMPACT NAVBAR === */
    .navbar { 
        padding: 0.5rem 1rem !important; 
        height: 60px !important;
        align-items: center;
    }
    
    /* Move Toggler to LEFT (Below Logo) */
    .navbar .container-fluid {
        flex-wrap: nowrap !important;
        gap: 10px;
    }
    
    /* Toggler Button - Styled & Positioned Left */
    .navbar-toggler {
        order: -1 !important;          /* Forces it to appear FIRST (leftmost) */
        width: 36px;
        height: 36px;
        background-color: rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 8px;             /* Small gap before logo */
    }
    .navbar-toggler:focus { 
        box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.2); 
    }
    
    /* === 2. SHRINK NAVBAR BRAND === */
    .navbar-brand {
        display: flex;
        align-items: center;
        max-width: 55%;                /* Shrink to fit with toggler */
        order: 0;
    }
    .navbar-brand img { 
        height: 30px !important; 
        width: 30px !important;
        margin-right: 8px !important;
    }
    .navbar-brand .brand-heading { 
        font-size: 0.85rem !important; /* Even smaller for tight fit */
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1.2;
    }
    .navbar-brand small { 
        display: none !important;      /* Hide subtitle */
    }
    
    /* === 3. USER DROPDOWN COMPACT === */
    .user-dropdown {
        order: 1;                      /* Appears last (rightmost) */
        margin-left: auto !important;
        margin-right: 0 !important;
    }
    .user-dropdown .user-name { 
        display: none !important;      /* Hide username text */
    }
    .user-dropdown .dropdown-toggle {
        padding: 6px 8px !important;
        font-size: 1.2rem;
    }
    .user-dropdown .dropdown-toggle img {
        width: 28px !important;
        height: 28px !important;
        margin-right: 0 !important;
    }

    /* === 4. SIDEBAR OPTIMIZATION === */
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

    /* === 5. MAIN CONTENT COMPACT === */
    .main-content { 
        padding: 12px !important;
        margin-top: 60px !important;
        margin-left: 0 !important;
    }

    /* === 6. HERO SECTION MOBILE === */
    .hero-section {
        padding: 20px !important;
        margin-bottom: 20px !important;
    }
    .welcome-badge {
        font-size: 0.7rem !important;
        padding: 4px 10px !important;
    }
    .hero-section h1 {
        font-size: 1.5rem !important;
        margin-bottom: 8px !important;
    }
    .hero-section h5 {
        font-size: 0.9rem !important;
        margin-bottom: 12px !important;
    }
    .hero-section .lead {
        font-size: 0.85rem !important;
        line-height: 1.5 !important;
    }
    .hero-section hr {
        width: 100% !important;
        margin: 15px 0 !important;
    }

    /* === 7. DASHBOARD CARDS COMPACT === */
    .dashboard-card {
        padding: 16px !important;
        margin-bottom: 12px;
    }
    .icon-square {
        width: 45px !important;
        height: 45px !important;
        font-size: 1.2rem !important;
        margin-right: 12px !important;
    }
    .stat-value {
        font-size: 1.8rem !important;
        margin-bottom: 2px !important;
    }
    .stat-label {
        font-size: 0.7rem !important;
    }
    .section-title {
        font-size: 0.75rem !important;
        margin-bottom: 12px !important;
    }

    /* === 8. QUICK ACTIONS LIST COMPACT === */
    .list-group-item {
        padding: 12px 16px !important;
    }
    .list-group-item .fa-lg {
        font-size: 1rem !important;
        margin-right: 10px !important;
    }
    .list-group-item .fw-bold {
        font-size: 0.9rem !important;
    }
    .list-group-item .small {
        font-size: 0.75rem !important;
    }

    /* === 9. ACTIVITY LOG COMPACT === */
    .activity-log-container .icon-square {
        width: 35px !important;
        height: 35px !important;
        font-size: 0.9rem !important;
        margin-right: 10px !important;
    }
    .activity-log-container .small {
        font-size: 0.8rem !important;
    }

    /* === 10. FOOTER ADJUSTMENT === */
    .footer-main {
        padding-left: 0 !important;
        padding: 2rem 0 1.5rem 0 !important;
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
}

/* === EXTRA SMALL PHONES (< 576px) === */
@media (max-width: 575.98px) {
    .navbar-brand .brand-heading {
        font-size: 0.75rem !important;
    }
    .hero-section h1 {
        font-size: 1.25rem !important;
    }
    .stat-value {
        font-size: 1.5rem !important;
    }
    .col-xl-3.col-md-6 {
        flex: 0 0 100% !important;
        max-width: 100% !important;
    }
}
</style>
</head>
<body>

    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="event_manager_dashboard.php">
                <img src="images/PIT.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white brand-heading" style="font-size: 1.25rem;">PIT SIGLAKAS MEDAL TALLY</strong>
                    <small class="text-light" style="font-size: 0.75rem;">Tournament Manager Panel</small>
                </div>
            </a>
            <button class="navbar-toggler d-lg-none" type="button" id="mobileToggle">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="dropdown user-dropdown ms-auto me-2 me-lg-0">
                <a href="#" class="dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle" style="font-size: 36px; margin-right: 10px;"></i>
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($display_name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="admin_profile.php"><i class="fas fa-user-circle me-2"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
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
                <a class="nav-link" href="Eventpage.php" target="_blank">
                    <i class="fas fa-globe me-2"></i> <span>View Public Events</span>
                </a>
            </li>
            <li class="nav-item mt-auto">
                <a class="nav-link text-danger" href="logout.php">
                    <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <div class="main-content">
        <div class="container-fluid">

            <div class="hero-section">
                <div class="row align-items-center">
                    <div class="col-lg-9">
                        <div class="welcome-badge mb-3"><i class="fas fa-id-badge me-1"></i> Official Event Manager</div>
                        <h1 class="fw-bold mb-1">Welcome to SmartScore</h1>
                        <h5 class="fw-light mb-3 text-white-50">Event Management & Verification Portal</h5>
                        <hr class="my-4" style="border-color: rgba(255,255,255,0.15); width: 60%;">
                        <p class="lead fs-6 opacity-90 mb-0" style="line-height: 1.7; font-weight: 400;">
                            Good day, <strong><?= htmlspecialchars($display_name) ?></strong>. You are assigned to manage specific event categories. 
                            Use this dashboard to input official results, upload tally sheet evidence, and submit data for Director approval.
                        </p>
                    </div>
                    <div class="col-lg-3 text-end d-none d-lg-block">
                        <i class="fas fa-clipboard-check fa-6x opacity-25" style="transform: rotate(-10deg);"></i>
                    </div>
                </div>
            </div>

            <h5 class="section-title">My Assignment Overview</h5>
                <div class="row g-4 mb-5">
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="dashboard-card">
                            <div class="d-flex align-items-center">
                                <div class="icon-square theme-blue">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <div>
                                    <div class="stat-value" id="stat-assigned-events">...</div>
                                    <div class="stat-label">Assigned Events</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="dashboard-card">
                            <div class="d-flex align-items-center">
                                <div class="icon-square theme-green">
                                    <i class="fas fa-layer-group"></i>
                                </div>
                                <div>
                                    <div class="stat-value" id="stat-total-categories">...</div>
                                    <div class="stat-label">Categories</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="dashboard-card">
                            <div class="d-flex align-items-center">
                                <div class="icon-square theme-orange">
                                    <i class="fas fa-hourglass-half"></i>
                                </div>
                                <div>
                                    <div class="stat-value" id="stat-pending-results">...</div>
                                    <div class="stat-label">Pending Approval</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="dashboard-card">
                            <div class="d-flex align-items-center">
                                <div class="icon-square theme-gold">
                                    <i class="fas fa-medal"></i>
                                </div>
                                <div>
                                    <div class="stat-value" id="stat-approved-medals">...</div>
                                    <div class="stat-label">Medals Awarded</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            
            <div class="row g-4">
    <div class="col-lg-6">
        <h5 class="section-title">Quick Actions</h5>
        <div class="dashboard-card p-0 overflow-hidden"> <div class="list-group list-group-flush">
                <a href="my_events.php" class="list-group-item list-group-item-action py-3 px-4 d-flex justify-content-between align-items-center border-bottom">
                    <div>
                        <i class="fas fa-trophy me-3 text-primary fa-lg"></i>
                        <span class="fw-bold text-dark">Manage My Events</span>
                        <div class="small text-muted mt-1 ps-5">Update results and upload evidence</div>
                    </div>
                    <i class="fas fa-chevron-right text-muted"></i>
                </a>
                <a href="Eventpage.php" target="_blank" class="list-group-item list-group-item-action py-3 px-4 d-flex justify-content-between align-items-center border-bottom">
                    <div>
                        <i class="fas fa-globe me-3 text-info fa-lg"></i>
                        <span class="fw-bold text-dark">Public Tally Site</span>
                    </div>
                    <i class="fas fa-external-link-alt text-muted small"></i>
                </a>
                <a href="admin_profile.php" class="list-group-item list-group-item-action py-3 px-4 d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fas fa-user-circle me-3 text-secondary fa-lg"></i>
                        <span class="fw-bold text-dark">My Profile</span>
                    </div>
                    <i class="fas fa-chevron-right text-muted"></i>
                </a>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <h5 class="section-title">Recent Activity</h5>
        <div class="dashboard-card p-0 overflow-hidden">
            <div class="activity-log-container">
                <?php if (empty($processed_logs)): ?>
                    <div class="p-4 text-center text-muted">No recent activity.</div>
                <?php else: ?>
                    <?php foreach ($processed_logs as $log_entry): ?>
                        <div class="d-flex align-items-start p-3 border-bottom">
                            <div class="icon-square rounded-circle me-3 bg-light text-muted" style="width: 40px; height: 40px; font-size: 1rem;">
                                <i class="<?= $log_entry['icon'] ?>"></i>
                            </div>
                            <div>
                                <div class="text-dark small"><?= $log_entry['message'] ?></div>
                                <div class="text-muted" style="font-size: 0.75rem; margin-top: 2px;">
                                    <i class="far fa-clock me-1"></i><?= $log_entry['time'] ?>
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
    
    // --- 1. SIDEBAR TOGGLE & SMOOTH TRANSITION ---
    const mobileToggle = document.getElementById('mobileToggle');
    const sidebar = document.getElementById('sidebar');

    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', function(e) {
            e.stopPropagation(); // Prevent immediate closing from the document listener
            sidebar.classList.toggle('show');
            
            // Optional: Prevent body scroll when menu is open on mobile
            if (window.innerWidth <= 992) {
                document.body.style.overflow = sidebar.classList.contains('show') ? 'hidden' : '';
            }
        });

        // Close sidebar when clicking anywhere outside of it
        document.addEventListener('click', function(event) {
            const isClickInsideSidebar = sidebar.contains(event.target);
            const isClickInsideToggle = mobileToggle.contains(event.target);

            if (!isClickInsideSidebar && !isClickInsideToggle && sidebar.classList.contains('show')) {
                sidebar.classList.remove('show');
                document.body.style.overflow = '';
            }
        });
    }

    // --- 2. AJAX STATS LOADER ---
    function loadDashboardStats() {
        fetch('event_manager_api.php?action=get_dashboard_stats')
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
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
            .catch(error => console.error('Error fetching stats:', error));
    }
    loadDashboardStats();

    // --- 3. DYNAMIC SIDEBAR/FOOTER ADJUSTMENT (Desktop Only) ---
    const footer = document.querySelector('footer');
    const navbar = document.querySelector('.navbar');

    if (sidebar && footer && navbar) {
        function adjustSidebarHeight() {
            // Only apply to desktop view
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