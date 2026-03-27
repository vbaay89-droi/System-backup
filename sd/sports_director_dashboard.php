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
    // 1. Identify Actor
    $actor = ($log['actor_user_id'] == $current_user_id) ? "<strong>You</strong>" : "<strong>" . htmlspecialchars(getUserNameById($conn, $log['actor_user_id'])) . "</strong>";
    
    // 2. Decode Context (The Sticky Note)
    $ctx = json_decode($log['log_context'], true) ?? [];
    $action = trim($log['action_type']);
    $related_id = (int)$log['related_id']; 

    // --- SMART NAME & TYPE LOGIC ---
    $parent  = trim($ctx['parent_event_name'] ?? $ctx['event_name'] ?? '');
    $raw_cat = trim($ctx['category_name'] ?? $ctx['new_category_name'] ?? $ctx['deleted_category_name'] ?? '');
    $raw_div = trim($ctx['division_name'] ?? '');
    
    $is_main = ($raw_cat === 'Main Event' || $raw_cat === 'Main Competition' || empty($raw_cat));
    $has_cat = !$is_main && !empty($raw_cat);
    $has_div = !empty($raw_div);

    // Determine Item Type
    if ($has_cat && $has_div) { $item_type = "category and division"; }
    elseif ($has_cat) { $item_type = "category"; }
    elseif ($has_div) { $item_type = "division"; }
    else { $item_type = "event"; }

    // Build the Smart Name
    $parts = [];
    if ($has_cat) $parts[] = $raw_cat;
    if ($has_div) $parts[] = $raw_div;
    $sub_details = implode(' - ', $parts);

    if (!empty($parent)) {
        $smart_name = empty($sub_details) ? $parent : "$parent ($sub_details)";
    } else {
        $smart_name = !empty($sub_details) ? $sub_details : "an event";
    }
    $smart_name = htmlspecialchars($smart_name);

    // Default values
    $msg = "Action performed.";
    $icon = "fas fa-info-circle text-muted";

    switch ($action) {
        // --- 1. Category/Division Management (Tournament Manager Actions) ---
        case 'CREATED_CATEGORY':
            $msg = "$actor added a new $item_type: <strong>\"$smart_name\"</strong>.";
            $icon = "fas fa-plus-circle text-success";
            break;

        case 'UPDATED_CATEGORY':
            $changes = [];
            if (!empty($ctx['new_status']) && ($ctx['old_status'] ?? '') !== $ctx['new_status']) {
                $changes[] = "status to <strong>" . htmlspecialchars($ctx['new_status']) . "</strong>";
            }
            // Add other change detection if needed (venue, date, etc)
            
            if (!empty($changes)) {
                $msg = "$actor updated the $item_type <strong>\"$smart_name\"</strong>: Changed " . implode(', ', $changes) . ".";
            } else {
                $msg = "$actor updated details for the $item_type <strong>\"$smart_name\"</strong>.";
            }
            $icon = "fas fa-edit text-info";
            break;

        case 'DELETED_CATEGORY':
            $msg = "$actor deleted the $item_type: <strong>\"$smart_name\"</strong>.";
            $icon = "fas fa-trash-alt text-danger";
            break;

        // --- 2. Result Actions ---
        case 'SUBMITTED_RESULTS':
            $msg = "$actor submitted official results for the $item_type <strong>\"$smart_name\"</strong>.";
            $icon = "fas fa-paper-plane text-warning"; 
            break;

        case 'APPROVED_RESULT': 
            $msg = "$actor approved the results for <strong>\"$smart_name\"</strong>."; 
            $icon = "fas fa-check-double text-success"; 
            break;
            
        case 'REJECTED_RESULT': 
            $msg = "$actor <span class='text-danger'>rejected</span> the results for <strong>\"$smart_name\"</strong>."; 
            $icon = "fas fa-times-circle text-danger"; 
            break;

        
        case 'REVOKED_RESULT': 
            $msg = "$actor <span class='text-warning'>revoked</span> the approved results for <strong>\"$smart_name\"</strong>."; 
            $icon = "fas fa-undo-alt text-warning"; 
            break;

        // --- 3. High-Level Administrative Actions ---
        case 'CREATED_GAME': 
            $msg = "$actor created a new game: <strong>" . htmlspecialchars($ctx['game_name']??'a game') . "</strong>."; 
            $icon = "fas fa-plus-circle text-success"; 
            break;
            
        case 'CREATED_EVENT': 
            $msg = "$actor created the event <strong>" . htmlspecialchars($ctx['event_name']??'an event') . "</strong>."; 
            $icon = "fas fa-calendar-plus text-success"; 
            break;

        case 'APPROVED_REQUEST': 
            $msg = "$actor approved a new account request."; 
            $icon = "fas fa-user-check text-success"; 
            break;

        default: 
            $msg = "$actor performed action: <strong>$action</strong>."; 
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
    /* =========================================
       1. CORE VARIABLES & SETUP
       ========================================= */
    :root { 
        --sidebar-width: 260px; 
        --header-height: 82px; 
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); 
        --bg-light: #f4f6f8; /* Updated to a cooler, modern gray */
        --primary-gradient: linear-gradient(135deg, #2c3e50 0%, #4ca1af 100%);
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
       2. LAYOUT (Sidebar, Navbar, Footer)
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
    }
    .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; transition: var(--transition); }
    .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
    .user-dropdown .dropdown-toggle img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; margin-right: 10px; }

    /* Sidebar */
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
    .sidebar-nav .nav-link { color: rgba(255, 255, 255, 0.7); font-size: 1.05rem; font-weight: 500; padding: 12px 25px; transition: var(--transition); border-left: 5px solid transparent; margin: 2px 0; display: flex; align-items: center; text-decoration: none; }
    .sidebar-nav .nav-link i { width: 30px; text-align: center; flex-shrink: 0; font-size: 0.95em; }
    .sidebar-nav .nav-link:hover { color: white; background: rgba(255, 255, 255, 0.05); border-left-color: var(--accent-color); }
    .sidebar-nav .nav-link.active { color: white; background: rgba(255, 255, 255, 0.1); border-left-color: #3498db; font-weight: 600; }
    .sidebar-nav .nav-title { padding: 15px 25px 5px; font-size: 0.75rem; font-weight: 700; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px; }

    /* Main Content Area */
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
    .sidebar.minimized ~ footer { padding-left: var(--sidebar-min-width); }

    /* Responsive */
    @media (max-width: 992px) {
        .sidebar { left: -260px; }
        .sidebar.show { left: 0; }
        .main-content, footer { margin-left: 0; }
    }

    /* =========================================
       3. HERO SECTION (Welcome Board)
       ========================================= */
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

    /* =========================================
       4. MODERN DASHBOARD WIDGETS (New Design)
       ========================================= */
    
    /* Section Titles */
    .section-title { 
        font-family: 'Inter', sans-serif;
        font-size: 0.85rem; 
        font-weight: 700; 
        text-transform: uppercase; 
        letter-spacing: 1px; 
        color: #8898aa; 
        margin-bottom: 1.5rem; 
    }

    /* The New Standard Card */
    .dashboard-card {
        background: white;
        border-radius: 16px;
        border: 1px solid rgba(0,0,0,0.02);
        box-shadow: 0 4px 20px rgba(0,0,0,0.03);
        padding: 24px;
        height: 100%;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }
    .dashboard-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 30px rgba(0,0,0,0.06);
    }

    /* Modern Icon Squares */
    .icon-square {
        width: 60px;
        height: 60px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.6rem;
        margin-right: 20px;
        flex-shrink: 0;
    }

    /* Theme Colors (Subtle Backgrounds) */
    .theme-blue   { background: rgba(0, 123, 255, 0.1); color: #007bff; }
    .theme-green  { background: rgba(25, 135, 84, 0.1); color: #198754; }
    .theme-cyan   { background: rgba(13, 202, 240, 0.1); color: #0dcaf0; }
    .theme-gold   { background: rgba(255, 193, 7, 0.1); color: #ffc107; }
    .theme-red    { background: rgba(220, 53, 69, 0.1); color: #dc3545; }
    .theme-orange { background: rgba(253, 126, 20, 0.1); color: #fd7e14; }

    /* Typography for Stats */
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

    /* Live Status / Action Cards */
    .action-card {
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .action-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
    }
    .pulse-dot {
        width: 10px;
        height: 10px;
        background-color: #dc3545;
        border-radius: 50%;
        display: inline-block;
        margin-right: 6px;
        animation: pulse-red 2s infinite;
    }
    @keyframes pulse-red {
        0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.4); }
        70% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
        100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
    }

    /* Soft Action Buttons */
    .btn-action-soft {
        background: #f8f9fa;
        color: #2c3e50;
        border: none;
        font-weight: 600;
        padding: 10px 15px;
        border-radius: 10px;
        width: 100%;
        text-align: center;
        text-decoration: none;
        display: inline-block;
        transition: all 0.2s;
    }
    .btn-action-soft:hover {
        background: #e9ecef;
        transform: translateY(-2px);
        color: #2c3e50;
    }
    .btn-action-soft.danger { color: #dc3545; background: rgba(220, 53, 69, 0.08); }
    .btn-action-soft.danger:hover { background: rgba(220, 53, 69, 0.15); }
    .btn-action-soft.warning { color: #d68c06; background: rgba(255, 193, 7, 0.1); }
    .btn-action-soft.warning:hover { background: rgba(255, 193, 7, 0.2); }

    /* =========================================
       5. OTHER COMPONENTS (Quick Actions, Footer)
       ========================================= */
    
    /* Quick Actions (Keep generic style for compatibility) */
    .quick-action-card { 
        text-align: center; 
        padding: 20px; 
        background: white; 
        border-radius: 15px; 
        box-shadow: var(--card-shadow); 
        transition: all 0.3s; 
        text-decoration: none; 
        color: #333; 
        display: block; 
        height: 100%; 
        border: 1px solid rgba(0,0,0,0.05); 
    }
    .quick-action-card:hover { 
        transform: translateY(-5px); 
        border-color: var(--accent-color); 
        background: #fcfcfc; 
        color: var(--accent-color); 
    }
    .quick-icon { 
        font-size: 2.5rem; 
        margin-bottom: 15px; 
        display: block; 
        transition: color 0.3s; 
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

    /* 4. COMPACT HERO SECTION */
    .hero-section {
        padding: 1.5rem !important;
        margin-bottom: 1.5rem !important;
        border-radius: 12px;
        text-align: left;
    }
    .hero-section h1 { font-size: 1.5rem !important; margin-bottom: 5px; }
    .hero-section h5 { font-size: 0.85rem !important; margin-bottom: 10px; }
    .hero-section hr { margin: 10px 0 !important; width: 100% !important; }
    
    /* Hide Extra Elements */
    .hero-section .col-lg-3, /* Big Icon */
    .welcome-badge { display: none !important; }
    
    /* Truncate Description to 2 lines */
    .hero-section p.lead {
        font-size: 0.85rem !important;
        line-height: 1.4 !important;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        margin-bottom: 0 !important;
    }

    /* 5. STATS GRID: 2 Columns Layout */
    /* This forces the 4 main stats to sit 2x2 */
    .col-md-6.col-lg-3 {
        width: 50% !important; /* Force 50% width */
        flex: 0 0 50%;
        padding: 6px !important; /* Tighter spacing */
    }
    
    /* Compact Stat Card */
    .dashboard-card {
        padding: 12px !important;
        display: flex;
        flex-direction: column; /* Stack Icon top, Text bottom */
        align-items: center;
        text-align: center;
        justify-content: center;
        min-height: 110px;
    }
    
    /* Smaller Icons */
    .icon-square {
        width: 40px !important;
        height: 40px !important;
        font-size: 1.1rem !important;
        margin-right: 0 !important;
        margin-bottom: 8px;
    }
    
    /* Smaller Text */
    .stat-value { font-size: 1.4rem !important; margin-bottom: 0 !important; }
    .stat-label { font-size: 0.65rem !important; letter-spacing: 0; }

    /* 6. ACTION CENTER (Result Approvals / Requests) */
    /* Keep these full width (stack vertically) as they need detail */
    .col-lg-6 {
        width: 100% !important;
        margin-bottom: 15px;
    }
    
    /* Compact Action Card */
    .action-card { padding: 15px !important; }
    .action-header { margin-bottom: 10px !important; }
    .action-header h6 { font-size: 0.95rem; }
    
    /* Adjust Button & Badge */
    .btn-action-soft {
        padding: 8px 12px !important;
        font-size: 0.85rem !important;
    }
    .badge {
        font-size: 0.7rem !important;
        padding: 4px 8px !important;
    }

    /* 7. FOOTER COMPACT */
    .footer-main { padding: 2rem 1rem !important; text-align: center; }
    .footer-main .footer-logo-group { justify-content: center; }
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
        <div class="dashboard-card">
            <div class="d-flex align-items-center">
                <div class="icon-square theme-blue">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div>
                    <div class="stat-value"><?= $stats['events'] ?></div>
                    <div class="stat-label">Events</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-lg-3">
        <div class="dashboard-card">
            <div class="d-flex align-items-center">
                <div class="icon-square theme-green">
                    <i class="fas fa-users"></i>
                </div>
                <div>
                    <div class="stat-value"><?= $stats['teams'] ?></div>
                    <div class="stat-label">Teams Joined</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-lg-3">
        <div class="dashboard-card">
            <div class="d-flex align-items-center">
                <div class="icon-square theme-cyan">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div>
                    <div class="stat-value"><?= $stats['categories'] ?></div>
                    <div class="stat-label">Categories</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-lg-3">
        <div class="dashboard-card">
            <div class="d-flex align-items-center">
                <div class="icon-square theme-gold">
                    <i class="fas fa-medal"></i>
                </div>
                <div>
                    <div class="stat-value"><?= $stats['total_gold'] ?></div>
                    <div class="stat-label">Gold Awarded</div>
                </div>
            </div>
        </div>
    </div>
</div>

            <h5 class="section-title">Action Center</h5>
<div class="row g-4 mb-5">
    
    <div class="col-lg-6">
        <div class="dashboard-card action-card">
            <div class="action-header">
                <div class="d-flex align-items-center">
                    <div class="icon-square theme-red" style="width: 50px; height: 50px; font-size: 1.4rem;">
                        <i class="fas fa-gavel"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 fw-bold text-dark">Result Approvals</h6>
                        <small class="text-muted">Awaiting your validation</small>
                    </div>
                </div>
                
                <span id="widget-results-badge" class="badge <?= ($stats['pending_results'] > 0) ? 'bg-danger bg-opacity-10 text-danger' : 'bg-success bg-opacity-10 text-success' ?> px-3 py-2 rounded-pill">
                    <?php if($stats['pending_results'] > 0): ?>
                        <span class="pulse-dot"></span> Action Needed
                    <?php else: ?>
                        <i class="fas fa-check me-1"></i> All Clear
                    <?php endif; ?>
                </span>
            </div>

            <div class="d-flex align-items-end justify-content-between">
                <div>
                    <div class="stat-value text-danger" id="widget-results-count"><?= $stats['pending_results'] ?></div>
                    <div class="stat-label text-muted">Pending Results</div>
                </div>
                <div style="width: 180px;">
                    <a href="results.php" class="btn-action-soft danger">
                        Review Now <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>
    </div> 

    <div class="col-lg-6">
        <div class="dashboard-card action-card">
            <div class="action-header">
                <div class="d-flex align-items-center">
                    <div class="icon-square theme-orange" style="width: 50px; height: 50px; font-size: 1.4rem;">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 fw-bold text-dark">Account Requests</h6>
                        <small class="text-muted">New staff registrations</small>
                    </div>
                </div>
                
                <span id="widget-requests-badge" class="badge <?= ($stats['pending_requests'] > 0) ? 'bg-warning bg-opacity-10 text-warning' : 'bg-secondary bg-opacity-10 text-secondary' ?> px-3 py-2 rounded-pill">
                    <?php if($stats['pending_requests'] > 0): ?>
                        Pending
                    <?php else: ?>
                        No Requests
                    <?php endif; ?>
                </span>
            </div>

            <div class="d-flex align-items-end justify-content-between">
                <div>
                    <div class="stat-value text-warning" id="widget-requests-count"><?= $stats['pending_requests'] ?></div>
                    <div class="stat-label text-muted">New Users</div>
                </div>
                <div style="width: 180px;">
                    <a href="../Manage_Requests.php" class="btn-action-soft warning">
                        Manage Users <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
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
                <small>Developed by Jayvee Baybyon</small>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {

        // ==========================================
        // 1. SIDEBAR TOGGLE & RESIZE LOGIC
        // ==========================================
        const mobileToggle = document.getElementById('mobileToggle');
        const sidebar = document.getElementById('sidebar');
        const footer = document.querySelector('footer');
        const navbar = document.querySelector('.navbar');
        const sidebarOverlay = document.getElementById('sidebarOverlay'); 

        // Toggle Click
        if (mobileToggle && sidebar) {
            mobileToggle.addEventListener('click', function() {
                sidebar.classList.toggle('show');
            });
        }

        // Auto-Hide on Resize
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                if (window.innerWidth > 992) {
                    if (sidebar) sidebar.classList.remove('show');
                    if (sidebarOverlay) sidebarOverlay.classList.remove('show');
                }
                adjustSidebarHeight(); 
            }, 250);
        });

        // Fix Sidebar/Footer Overlap
        function adjustSidebarHeight() {
            if (!sidebar || !footer || !navbar) return;

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
        setTimeout(adjustSidebarHeight, 100);

        // ==========================================
        // 2. REAL-TIME DASHBOARD UPDATER (Sidebar + Widgets)
        // ==========================================
        function updateDashboardData() {
            // Fetch latest counts from API
            fetch('../api_notifications.php?t=' + new Date().getTime())
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // A. UPDATE SIDEBAR BADGES
                        updateSidebarBadge('results.php', data.pending_results, 'bg-warning text-dark');
                        updateSidebarBadge('Manage_Requests.php', data.pending_requests, 'bg-danger');

                        // B. UPDATE ACTION CENTER WIDGETS
                        updateActionWidgets(data);
                    }
                })
                .catch(err => console.error('Dashboard update error:', err));
        }

        // Helper: Update Sidebar Link Badges
        function updateSidebarBadge(hrefKeyword, count, colorClasses) {
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

        // Helper: Update Action Center Cards
        function updateActionWidgets(data) {
            // 1. RESULT APPROVALS WIDGET
            const resCount = document.getElementById('widget-results-count');
            const resBadge = document.getElementById('widget-results-badge');
            
            if (resCount) resCount.textContent = data.pending_results;
            
            if (resBadge) {
                if (data.pending_results > 0) {
                    // Urgent State (Red)
                    resBadge.className = 'badge bg-danger bg-opacity-10 text-danger px-3 py-2 rounded-pill';
                    resBadge.innerHTML = '<span class="pulse-dot"></span> Action Needed';
                } else {
                    // Clear State (Green)
                    resBadge.className = 'badge bg-success bg-opacity-10 text-success px-3 py-2 rounded-pill';
                    resBadge.innerHTML = '<i class="fas fa-check me-1"></i> All Clear';
                }
            }

            // 2. ACCOUNT REQUESTS WIDGET
            const reqCount = document.getElementById('widget-requests-count');
            const reqBadge = document.getElementById('widget-requests-badge');

            if (reqCount) reqCount.textContent = data.pending_requests;

            if (reqBadge) {
                if (data.pending_requests > 0) {
                    // Pending State (Yellow)
                    reqBadge.className = 'badge bg-warning bg-opacity-10 text-warning px-3 py-2 rounded-pill';
                    reqBadge.textContent = 'Pending';
                } else {
                    // Empty State (Grey)
                    reqBadge.className = 'badge bg-secondary bg-opacity-10 text-secondary px-3 py-2 rounded-pill';
                    reqBadge.textContent = 'No Requests';
                }
            }
        }

        // Start Real-Time Updates (Run immediately, then every 5s)
        updateDashboardData();
        setInterval(updateDashboardData, 5000);

    });
</script>
</body>
</html>