<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. SECURITY & ACCESS CONTROL
// STRICT: Only 'Sports Director' is allowed
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'Sports Director'
) {
    header('Location: login.php');
    exit();
}

require_once 'config.php'; // Main DB Connection

$current_user_id = $_SESSION['user_id'];

// --- FETCH NAME LOGIC ---
// --- FETCH NAME & PROFILE PICTURE LOGIC ---
$stmt_name = $conn->prepare("SELECT full_name, username, profile_picture FROM users WHERE id = ?");
$stmt_name->bind_param("i", $current_user_id);
$stmt_name->execute();
$result_name = $stmt_name->get_result();
$user_data = $result_name->fetch_assoc();
$stmt_name->close();

$name = !empty($user_data['full_name']) ? $user_data['full_name'] : ($user_data['username'] ?? 'Sports Director');
$current_page = basename($_SERVER['PHP_SELF']);

// Define the profile picture path (adding ../ because we are inside a subfolder)
$profile_pic_path = '';
if (!empty($user_data['profile_picture'])) {
    $profile_pic_path = $user_data['profile_picture']; 
}

// Determine Name
if (!empty($user_data['full_name'])) {
    $name = $user_data['full_name'];
} else {
    $name = $user_data['username'] ?? 'Sports Director';
}

$current_page = basename($_SERVER['PHP_SELF']);

// --- 1. DATA FETCHING FOR REPORTS ---

// -- Get Sort Order --
$valid_sorts = [
    // 1. Olympic Standard: Most Gold, then Silver, then Bronze
    'gold' => 'gold DESC, silver DESC, bronze DESC', 
    
    'silver' => 'silver DESC, gold DESC, bronze DESC',
    'bronze' => 'bronze DESC, gold DESC, silver DESC',
    'total' => 'total DESC, gold DESC, silver DESC, bronze DESC'
];

// FIX: Changed default from 'total' to 'gold'
$sort_key = isset($_GET['sort']) && array_key_exists($_GET['sort'], $valid_sorts) ? $_GET['sort'] : 'gold';

$order_by_sql = $valid_sorts[$sort_key];

// --- Query 1: Overall Medal Standings ---
$medal_tally = [];
$sql_medals = "SELECT 
                    C.college_name, C.logo_url, C.college_code,
                    SUM(CASE WHEN Cat.gold_winner_college_id = C.college_id THEN Cat.gold_count ELSE 0 END) AS gold,
                    SUM(CASE WHEN Cat.silver_winner_college_id = C.college_id THEN Cat.silver_count ELSE 0 END) AS silver,
                    SUM(CASE WHEN Cat.bronze_winner_college_id = C.college_id THEN Cat.bronze_count ELSE 0 END) AS bronze,
                    (SUM(CASE WHEN Cat.gold_winner_college_id = C.college_id THEN Cat.gold_count ELSE 0 END) +
                     SUM(CASE WHEN Cat.silver_winner_college_id = C.college_id THEN Cat.silver_count ELSE 0 END) +
                     SUM(CASE WHEN Cat.bronze_winner_college_id = C.college_id THEN Cat.bronze_count ELSE 0 END)) AS total
                FROM colleges C
                LEFT JOIN categories Cat ON (
                    C.college_id = Cat.gold_winner_college_id OR 
                    C.college_id = Cat.silver_winner_college_id OR 
                    C.college_id = Cat.bronze_winner_college_id
                ) AND Cat.status = 'Results Approved'
                GROUP BY C.college_id, C.college_name, C.logo_url, C.college_code
                ORDER BY $order_by_sql, C.college_name ASC";

$stmt_medals = $conn->prepare($sql_medals);
if ($stmt_medals) {
    $stmt_medals->execute();
    $result = $stmt_medals->get_result();
    if ($result) {
        $medal_tally = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt_medals->close();
}

// --- Query 3: Event Results Report ---
$event_results = [];
$sql_events = "SELECT 
                    g.game_name,
                    ge.event_name, 
                    c.category_name,
                    c.division_name,
                    c.event_date,
                    c.event_time,
                    gold_col.college_name AS gold_winner,
                    silver_col.college_name AS silver_winner,
                    bronze_col.college_name AS bronze_winner,
                    c.gold_count, c.silver_count, c.bronze_count
                    
                FROM categories c
                LEFT JOIN game_events ge ON c.event_id = ge.event_id
                LEFT JOIN games g ON ge.game_id = g.game_id
                LEFT JOIN colleges gold_col ON c.gold_winner_college_id = gold_col.college_id
                LEFT JOIN colleges silver_col ON c.silver_winner_college_id = silver_col.college_id
                LEFT JOIN colleges bronze_col ON c.bronze_winner_college_id = bronze_col.college_id
                WHERE c.status = 'Results Approved'
                ORDER BY g.game_name, ge.event_name, c.category_name";

$result_events = $conn->query($sql_events);
if ($result_events) {
    $event_results = $result_events->fetch_all(MYSQLI_ASSOC);
}

// --- Query 4: Events Breakdown (Pie Chart) ---
$sport_distribution = [];
$sql_sports_pie = "SELECT 
                        ge.event_name, 
                        COUNT(c.category_id) AS category_count
                    FROM categories c
                    JOIN game_events ge ON c.event_id = ge.event_id
                    WHERE c.status = 'Results Approved'
                    GROUP BY ge.event_name
                    HAVING category_count > 0
                    ORDER BY category_count DESC";

$result_sports_pie = $conn->query($sql_sports_pie);
if ($result_sports_pie) {
    $sport_distribution = $result_sports_pie->fetch_all(MYSQLI_ASSOC);
}

// --- 2. CHART DATA PREPARATION ---
$chart_labels = json_encode(array_column($medal_tally, 'college_code'));
$chart_gold = json_encode(array_column($medal_tally, 'gold'));
$chart_silver = json_encode(array_column($medal_tally, 'silver'));
$chart_bronze = json_encode(array_column($medal_tally, 'bronze'));

// Updated Labels for Pie Chart (Events)
$pie_labels = json_encode(array_column($sport_distribution, 'event_name'));
$pie_data = json_encode(array_column($sport_distribution, 'category_count'));

// Fetch Sidebar Counts
// Count pending requests for sidebar badge (Fixed: Counts unapproved users)
$pending_requests_count = $conn->query("SELECT COUNT(*) FROM users WHERE is_approved = 0")->fetch_row()[0] ?? 0;
$pending_results_count = $conn->query("SELECT COUNT(*) FROM categories WHERE status='Results Submitted'")->fetch_row()[0] ?? 0;

$conn->close();

// --- Helper Functions ---
function getRankHtml(int $rank): string {
    switch ($rank) {
        case 1: return '<img src="trophy1.svg" alt="1" style="width: 28px; height: 28px;">';
        case 2: return '<img src="secondplace.svg" alt="2" style="width: 28px; height: 28px;">';
        case 3: return '<img src="thirdplace.svg" alt="3" style="width: 28px; height: 28px;">';
        default: return '<span class="fw-bold text-muted" style="font-size: 1rem;">' . $rank . '</span>';
    }
}

function getStatusBadge($status) {
    $status = $status ?? '';
    switch ($status) {
        case 'Upcoming': return '<span class="status-badge bg-info text-white">Upcoming</span>';
        case 'Ongoing': return '<span class="status-badge bg-primary text-white">Ongoing</span>';
        case 'Results Submitted': return '<span class="status-badge bg-warning text-dark">Submitted</span>';
        case 'Results Approved': return '<span class="status-badge bg-success text-white">Approved</span>';
        case 'Completed': return '<span class="status-badge bg-success text-white">Completed</span>';
        case 'Results Rejected': return '<span class="status-badge bg-danger text-white">Rejected</span>';
        case 'Cancelled': return '<span class="status-badge bg-secondary text-white">Cancelled</span>';
        default: return '<span class="status-badge bg-light text-dark border">' . htmlspecialchars($status) . '</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Reports - Sports Director Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">

    <style>
        /* --- Unified CSS Theme --- */
        :root { 
            --sidebar-width: 260px; 
            --header-height: 82px; 
            --transition: all 0.3s ease; 
            --card-shadow: 0 4px 16px rgba(0, 0, 0, 0.08); 
            --bg-light: #F8F9FA; 
            --primary-gradient: linear-gradient(135deg, #2c3e50 0%, #4ca1af 100%);
            --accent-color: #1abc9c;
            
            /* Report Colors */
            --gold: #f59e0b;
            --silver: #64748b;
            --bronze: #ea580c;
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

        /* Main Content */
        .main-content { flex: 1 0 auto; padding: 30px; margin-top: var(--header-height); margin-left: var(--sidebar-width); transition: margin-left var(--transition); min-height: calc(100vh - var(--header-height)); }
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }


        
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
        @media (max-width: 992px) {
            .sidebar { left: -260px; }
            .sidebar.show { left: 0; }
            .main-content, footer { margin-left: 0; }
        }

        /* === ENHANCED DESIGN === */
        
        /* Card Styling */
        .card { 
            border: none; 
            border-radius: 12px; 
            box-shadow: var(--card-shadow); 
            overflow: hidden; 
            margin-bottom: 1.5rem; 
        }
        .card-header { 
            background: #ffffff !important; 
            border-bottom: 2px solid #f1f3f5 !important; 
            padding: 1.25rem 1.5rem !important; 
        }
        .card-header h5 { 
            font-family: 'Poppins', sans-serif; 
            font-weight: 600; 
            color: #2c3e50; 
            margin-bottom: 0; 
            font-size: 1.1rem; 
        }
        
        /* NEW: Default desktop width for the dropdown wrapper */
        .sort-select-wrapper { 
            width: 200px; 
        }
        /* Table Container */
        .report-table-container { 
            background: #ffffff; 
            border-radius: 0 0 12px 12px; 
            overflow-x: auto; 
        }
        
        .report-table { 
            margin-bottom: 0; 
            font-size: 0.9375rem; 
            width: 100%; 
            border-collapse: separate; 
            border-spacing: 0; 
        }
        
        /* Gradient Header */
        .report-table thead th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: #2c3e50;
            font-weight: 600;
            font-size: 0.8125rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 1rem 1.25rem;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
        }
        
        /* Table Rows */
        .report-table tbody td { 
            padding: 1.125rem 1.25rem; 
            vertical-align: middle; 
            border-bottom: 1px solid #f1f3f5; 
            transition: all 0.2s ease; 
        }
        .report-table tbody tr { transition: all 0.2s ease; }
        .report-table tbody tr:hover { 
            background-color: #f8f9fa; 
            transform: translateX(2px); 
            box-shadow: -3px 0 0 0 #0d6efd inset; 
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.4rem 0.875rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        /* Type Badges */
        .type-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .type-badge.badge-match { background-color: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .type-badge.badge-medal { background-color: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        
        /* Typography for Winners */
        .winner-text { font-weight: 600; font-size: 0.9rem; }
        .gold-text { color: var(--gold); }
        .silver-text { color: var(--silver); }
        .bronze-text { color: var(--bronze); }
        .total-text { color: #2c3e50; font-weight: 700; }
        
        .winner-count-pill {
            display: inline-block;
            background: #f1f3f5;
            color: #495057;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.1rem 0.4rem;
            border-radius: 10px;
            margin-left: 4px;
            vertical-align: middle;
        }

        /* Tabs Customization */
        .nav-tabs { border-bottom: 2px solid #e9ecef; margin-bottom: 1.5rem; }
        .nav-tabs .nav-link {
            border: none;
            border-bottom: 3px solid transparent;
            color: #64748b;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            transition: all 0.2s ease;
        }
        .nav-tabs .nav-link:hover { color: #334155; }
        .nav-tabs .nav-link.active {
            background: transparent;
            color: #0d6efd;
            border-bottom-color: #0d6efd;
        }
        
        /* Charts Container */
        .chart-wrapper {
            padding: 1.5rem;
            height: 450px; /* Increased height for vertical layout */
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        /* Empty State */
        .empty-state-icon { font-size: 3rem; color: #dee2e6; margin-bottom: 1rem; }
        
        /* Print Styles */
        @media print {
            .sidebar, .navbar, .btn, .dataTables_filter, .dataTables_length, .dataTables_paginate { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
            body { background-color: white; }
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

    /* =========================================
   MOBILE OPTIMIZATION (Sports Director)
   ========================================= */
@media (max-width: 991.98px) {
            
    /* --- UPDATE GLOBAL VARIABLE FOR MOBILE --- */
    :root { 
        --header-height: 60px; 
    }
    
    /* 1. COMPACT NAVBAR & LAYOUT */
    .navbar {
        padding: 0.5rem 1rem !important;
        height: var(--header-height) !important; 
    }
    
    .navbar > .container-fluid {
        display: flex !important;
        flex-wrap: nowrap !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 10px; /* Clean spacing between elements */
    }
    
    /* A. LEFT: Toggler Button */
    .navbar-toggler {
        order: 1 !important; /* First item */
        border: 1px solid rgba(255,255,255,0.1);
        padding: 4px 8px;
        font-size: 1.1rem;
        margin-right: 5px !important;
    }
    .navbar-toggler:focus { box-shadow: none; }

    /* B. CENTER: Brand Logo & Text */
    .navbar-brand {
        order: 2 !important; /* Second item */
        margin-right: auto !important; 
        display: flex;
        align-items: center;
        flex-grow: 1; /* Take up remaining middle space */
        min-width: 0; /* CRITICAL: Allows text-overflow to work in Flexbox */
    }
    .navbar-brand img {
        height: 28px !important; /* Minimized PIT logo */
        width: 28px !important;
        margin-right: 8px !important;
        flex-shrink: 0; /* Prevents logo from squishing */
    }
    .navbar-brand .lh-sm {
        min-width: 0; /* CRITICAL for ellipsis */
        flex-grow: 1;
    }
    .navbar-brand strong {
        font-size: 0.8rem !important; /* Slightly smaller for mobile */
        white-space: normal !important; /* CRITICAL: Allows text to wrap to a second line */
        line-height: 1.2 !important; /* Keeps the wrapped text tight and clean */
        display: block;
        word-wrap: break-word;
    }
    .navbar-brand small { display: none !important; } /* Hide subtext */

    /* C. RIGHT: Profile Menu Icon */
    .user-dropdown {
        order: 3 !important; /* Third item */
        margin-left: 0 !important; 
        flex-shrink: 0; /* Prevents profile pic from squishing */
    }
    .user-dropdown .user-name { display: none !important; }
    
    /* Minimized Profile Image */
    .user-dropdown .dropdown-toggle {
        padding: 2px !important; /* Remove bulky padding */
    }
    .user-dropdown .dropdown-toggle img {
        width: 30px !important; /* Minimized profile photo */
        height: 30px !important;
        margin-right: 0 !important;
        border: 1px solid rgba(255,255,255,0.2) !important;
    }
    /* Fallback Icon */
    .user-dropdown .dropdown-toggle i { 
        font-size: 26px !important; 
        margin: 0 !important;
        color: #fff;
    }


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
}
            /* 1. Center text on smaller screens */
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
        /* =========================================
   MOBILE REPORTS OPTIMIZATION
   ========================================= */
@media (max-width: 991.98px) {

    /* 1. Header & Controls Stacking */
    .d-flex.justify-content-between.align-items-center.mb-4 {
        flex-direction: column;
        align-items: stretch !important;
        gap: 15px;
    }
    
    /* Make the Sort Dropdown and Print Button full width for easy tapping */
    .card-header .d-flex,
    .page-header + button {
        width: 100%;
    }
    
    .form-select, .btn {
        width: 100%;
        padding: 10px;
    }

    /* 2. Swipeable Navigation Tabs */
    .nav-tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
        overflow-y: hidden;
        white-space: nowrap;
        -webkit-overflow-scrolling: touch;
        padding-bottom: 5px;
        border-bottom: 1px solid #dee2e6;
    }
    .nav-tabs .nav-link {
        font-size: 0.9rem;
        padding: 10px 15px;
    }

    /* 3. RESPONSIVE TABLES (Horizontal Scroll + Sticky First Column) */
    
    /* Allow scrolling */
    .report-table-container {
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch;
        border: 1px solid #f0f0f0;
    }

    /* Force table width so it doesn't squish */
    .report-table {
        min-width: 700px; 
    }

    /* Standardize Cell Padding */
    .report-table th, 
    .report-table td {
        padding: 12px 10px !important;
        white-space: nowrap; /* Keep rows single line */
    }

    /* --- MEDAL TABLE SPECIFICS --- */
    
    /* Hide Rank Column on very small screens to save space */
    .report-table th:first-child, 
    .report-table td:first-child {
        display: none; 
    }

    /* Make Team Name (Column 2) Sticky */
    .report-table td:nth-child(2),
    .report-table th:nth-child(2) {
        position: sticky;
        left: 0;
        background-color: #fff;
        z-index: 5;
        border-right: 2px solid #f0f0f0;
        min-width: 160px;
        max-width: 180px;
        white-space: normal; /* Allow team name to wrap */
        line-height: 1.2;
    }
    
    /* Fix header background for sticky column */
    .report-table thead th:nth-child(2) { 
        background: #f8f9fa; 
        z-index: 10; 
    }
    /* Fix row hover background */
    .report-table tbody tr:hover td:nth-child(2) { 
        background: #f8f9fa; 
    }

    /* Center Medal Numbers */
    .winner-text {
        font-size: 1rem;
        font-weight: 700;
    }

    /* --- EVENT RESULTS TABLE SPECIFICS --- */
    
    /* Sticky Event Name Column */
    #eventResultsTable td:first-child,
    #eventResultsTable th:first-child {
        position: sticky;
        left: 0;
        background: #fff;
        z-index: 5;
        border-right: 2px solid #f0f0f0;
        max-width: 150px;
        white-space: normal; /* Allow text wrapping */
        line-height: 1.2;
    }
    
    #eventResultsTable thead th:first-child { background: #f8f9fa; z-index: 10; }
    #eventResultsTable tbody tr:hover td:first-child { background: #f8f9fa; }

    /* Hide Date Column on Mobile (Less Critical) */
    #eventResultsTable th:nth-child(2),
    #eventResultsTable td:nth-child(2) {
        display: none;
    }

    /* 4. VISUAL REPORTS (Charts) */
    .chart-wrapper {
        height: 300px !important; /* Smaller height for mobile */
        padding: 10px;
    }
    
    /* Stack Chart Cards */
    .row.g-4 > .col-12 {
        margin-bottom: 15px;
    }
}

/* === PHONES & SMALL TABLETS (< 768px) === */
        @media (max-width: 767.98px) {
            /* Allow winner names to wrap to save horizontal space */
            #eventResultsTable td:nth-child(3),
            #eventResultsTable td:nth-child(4),
            #eventResultsTable td:nth-child(5) {
                min-width: 110px !important;
                white-space: normal !important; /* Forces text to wrap */
                line-height: 1.3;
                font-size: 0.85rem;
            }
            #eventResultsTable th:nth-child(3),
            #eventResultsTable th:nth-child(4),
            #eventResultsTable th:nth-child(5) {
                min-width: 110px !important;
            }
            /* Reduce winner count pill size */
            .winner-count-pill { 
                font-size: 0.65rem; 
                padding: 0 3px; 
            }
        }

/* === EXTRA SMALL PHONES (< 576px) === */
        @media (max-width: 575.98px) {
            
            /* 1. Print Button Full Width */
            .btn-print-report { 
                width: 100%; 
                padding: 12px; 
            }
            
            /* 2. Tabs Compact Fix */
            .nav-tabs .nav-link { 
                font-size: 0.75rem !important; 
                padding: 8px 10px !important; 
                white-space: nowrap !important;
            }
            .nav-tabs .nav-link i { 
                display: none !important; 
            }

            /* 3. Stack the Card Header & stretch the dropdown */
            .card-header.d-flex { 
                flex-direction: column; 
                align-items: stretch !important;
                gap: 12px;
            }
            .sort-select-wrapper { 
                width: 100%; 
            }

            /* 4. MEDAL TABLE COMPACT FIX */
            /* Shrink team logo */
            .report-table td:nth-child(2) img {
                width: 28px !important;
                height: 28px !important;
                margin-right: 8px !important;
            }
            
            /* Hide full college name, keep short code */
            .report-table td:nth-child(2) small {
                display: none !important;
            }
            
            /* Tighten medal number columns */
            .report-table td:nth-child(3),
            .report-table td:nth-child(4),
            .report-table td:nth-child(5),
            .report-table td:nth-child(6),
            .report-table th:nth-child(3),
            .report-table th:nth-child(4),
            .report-table th:nth-child(5),
            .report-table th:nth-child(6) {
                padding: 8px 6px !important;
                font-size: 0.9rem !important;
            }
        }

        /* === ULTRA SMALL PHONES (< 400px) === */
        @media (max-width: 400px) {
            /* Squeeze the outer container padding */
            .main-content { 
                padding: 10px 8px !important; 
            }
            
            /* Squeeze the inner card header padding and text */
            .card-header { 
                padding: 0.75rem 1rem !important; 
            }
            
            .card-header h5 { 
                font-size: 0.95rem; 
            }
            
            /* Optional: Make the print button a tiny bit more compact here too */
            .btn-print-report {
                padding: 10px;
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
    
    <div id="sidebarOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1044; backdrop-filter: blur(2px);"></div>
    
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="sd/sports_director_dashboard.php">
                <img src="images/PIT.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain;">
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
                
                    <?php if (!empty($profile_pic_path) && file_exists($profile_pic_path)): ?>
                        <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="Profile" style="width: 42px; height: 42px; border-radius: 50%; object-fit: cover; margin-right: 10px; border: 2px solid rgba(255,255,255,0.2);">
                    <?php else: ?>
                        <i class="fas fa-user-circle" style="font-size:36px;margin-right:10px;"></i>
                    <?php endif; ?>
                    
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="admin_profile.php"><i class="fas fa-user-circle me-2"></i> Profile</a></li>
                    <li><a class="dropdown-item" href="Tournament_Manager_page.php" target="_blank"><i class="fas fa-globe me-2"></i> Public Site</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="sidebar" id="sidebar">
        <ul class="nav flex-column sidebar-nav">
            <li class="nav-item">
                <a class="nav-link" href="sd/sports_director_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                </a>
            </li>
            
            <li class="nav-item mt-3"><span class="nav-title">Tournament Mgmt</span></li>
            <li class="nav-item">
                <a class="nav-link" href="sd/colleges.php">
                    <i class="fas fa-users me-2"></i> <span>Manage Teams</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="sd/events.php">
                    <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events </span>
                </a>
            </li>

            <li class="nav-item mt-3"><span class="nav-title">Administration</span></li>
            <li class="nav-item">
                <a class="nav-link" href="Manage_Users.php">
                    <i class="fas fa-users-cog me-2"></i> <span>Manage Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="Manage_Requests.php">
                    <i class="fas fa-user-plus me-2"></i> <span>Account Requests</span>
                    <?php if($pending_requests_count > 0): ?>
                        <span class="badge bg-danger ms-auto rounded-pill"><?= $pending_requests_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
             <li class="nav-item">
                <a class="nav-link active" href="Manage_Viewreports.php">
                    <i class="fas fa-file-alt me-2"></i> <span>View System Reports</span>
                </a>
            </li>

            <li class="nav-item mt-3"><span class="nav-title">Tallying & Scoring</span></li>
            <li class="nav-item">
                <a class="nav-link" href="sd/results.php">
                    <i class="fas fa-check-double me-2"></i> <span>Approve Results</span>
                    <?php if($pending_results_count > 0): ?>
                        <span class="badge bg-warning text-dark ms-auto rounded-pill"><?= $pending_results_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="sd/reports.php">
                    <i class="fas fa-chart-line me-2"></i> <span>Medal Standings</span>
                </a>
            </li>

            <li class="nav-item mt-3"><span class="nav-title">Season Management</span></li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'manage_archives.php') ? 'active' : '' ?>" href="manage_archives.php">
                    <i class="fas fa-history me-2"></i> <span>Archives & Reset</span>
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
            
            <nav aria-label="breadcrumb" class="mb-4">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="sd/sports_director_dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">System Reports</li>
              </ol>
            </nav>

            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center mb-4 gap-3">
    
                <div class="page-header mb-0 w-100 w-sm-auto"> 
                    <h1 class="section-title mb-0">
                        <i class="fas fa-file-alt text-primary me-2"></i>View Reports
                    </h1>
                </div>

                <button class="btn btn-secondary btn-print-report" onclick="window.open('print_official_report.php', '_blank')">
                    <i class="fas fa-print me-2"></i> Print Official Report
                </button>
            </div>
            
            <ul class="nav nav-tabs" id="reportTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="summary-tab" data-bs-toggle="tab" data-bs-target="#summary" type="button" role="tab" aria-controls="summary" aria-selected="true">
                        <i class="fas fa-medal me-2 text-warning"></i> Overall Medal Summary
                    </button>
                </li>
                
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="events-tab" data-bs-toggle="tab" data-bs-target="#events" type="button" role="tab" aria-controls="events" aria-selected="false">
                        <i class="fas fa-list-check me-2 text-primary"></i> Event Results
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="graphs-tab" data-bs-toggle="tab" data-bs-target="#graphs" type="button" role="tab" aria-controls="graphs" aria-selected="false">
                        <i class="fas fa-chart-bar me-2 text-success"></i> Visual Reports
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="reportTabContent">
                
                <div class="tab-pane fade show active" id="summary" role="tabpanel" aria-labelledby="summary-tab">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-trophy text-warning me-2"></i>Overall Team Rankings</h5>
                            <div class="sort-select-wrapper">
                                <select class="form-select form-select-sm" id="sortFilter" onchange="window.location.href = 'Manage_Viewreports.php?sort=' + this.value;">
                                    <option value="gold" <?= ($sort_key == 'gold') ? 'selected' : '' ?>>Standard Rank (Gold First)</option>
                                    <option value="total" <?= ($sort_key == 'total') ? 'selected' : '' ?>>Sort by Total Medals</option>
                                    <option value="silver" <?= ($sort_key == 'silver') ? 'selected' : '' ?>>Sort by Silver</option>
                                    <option value="bronze" <?= ($sort_key == 'bronze') ? 'selected' : '' ?>>Sort by Bronze</option>
                                </select>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="report-table-container">
                                <table class="table report-table table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th class="text-center" style="width: 80px;">Rank</th>
                                            <th style="width: 40%;">Team</th>
                                            <th class="text-center gold-text">Gold</th>
                                            <th class="text-center silver-text">Silver</th>
                                            <th class="text-center bronze-text">Bronze</th>
                                            <th class="text-center">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($medal_tally)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center text-muted p-5">
                                                    <i class="fas fa-chart-bar empty-state-icon"></i>
                                                    <p class="mb-0">No medal standings available.</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($medal_tally as $index => $row): ?>
                                            <?php 
                                                // FIX: Removed '../' because this file is in the root folder.
                                                // If DB has 'uploads/colleges/logo.png', we use it directly.
                                                $logo_path = !empty($row['logo_url']) ? $row['logo_url'] : 'images/default_avatar.png';
                                            ?>
                                            <tr>
                                                <td class="text-center"><?= getRankHtml($index + 1) ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <img src="<?= htmlspecialchars($logo_path) ?>" 
                                                             onerror="this.src='images/default_avatar.png'"
                                                             style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; margin-right: 15px; border: 1px solid #dee2e6;">
                                                        <div>
                                                            <div class="fw-bold text-dark"><?= htmlspecialchars($row['college_code']) ?></div>
                                                            <small class="text-muted"><?= htmlspecialchars($row['college_name']) ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                    <td class="text-center winner-text gold-text"><?= $row['gold'] ?></td>
                                                    <td class="text-center winner-text silver-text"><?= $row['silver'] ?></td>
                                                    <td class="text-center winner-text bronze-text"><?= $row['bronze'] ?></td>
                                                    <td class="text-center winner-text total-text"><?= $row['total'] ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="events" role="tabpanel" aria-labelledby="events-tab">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-list-check text-info me-2"></i> Event Results Report</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="report-table-container">
                                <table class="table report-table table-hover align-middle" id="eventResultsTable">
                                    <thead>
                                        <tr>
                                            <th style="min-width: 200px;">Event Details</th>
                                            <th style="min-width: 180px;">Date & Time</th>
                                            <th style="min-width: 160px;"><i class="fas fa-medal gold-text"></i> Gold</th>
                                            <th style="min-width: 160px;"><i class="fas fa-medal silver-text"></i> Silver</th>
                                            <th style="min-width: 160px;"><i class="fas fa-medal bronze-text"></i> Bronze</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($event_results as $event): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($event['game_name']) ?></div>
                                                    <div class="small text-muted"><?= htmlspecialchars($event['event_name']) ?></div>
                                                    <?php 
                                                    if ($event['category_name'] === 'Main Event' || $event['category_name'] === 'Main Competition') {
                                                        echo '<div class="small text-muted fst-italic">(No specific category)</div>';
                                                    } else {
                                                        // 1. Display Category
                                                        echo '<div class="fw-bolder text-dark" style="font-size: 0.9rem;">' . htmlspecialchars($event['category_name']) . '</div>';
                                                        
                                                        // 2. Display Division (if it exists)
                                                        if (!empty($event['division_name'])) {
                                                            echo '<div class="text-secondary small fw-semibold mt-1">- ' . htmlspecialchars($event['division_name']) . '</div>';
                                                        }
                                                    }
                                                    ?>
                                                </td>
                                                
                                                <td>
                                                    <?php if(!empty($event['event_date'])): ?>
                                                        <div class="small fw-semibold"><?= date('M d, Y', strtotime($event['event_date'])) ?></div>
                                                        <?php if(!empty($event['event_time'])): ?>
                                                            <div class="small text-muted"><?= date('h:i A', strtotime($event['event_time'])) ?></div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted small">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                
                                                <td class="winner-text gold-text">
                                                    <?= htmlspecialchars($event['gold_winner'] ?? 'N/A') ?>
                                                    <?php if($event['gold_count'] > 0) echo "<span class='winner-count-pill'>{$event['gold_count']}</span>"; ?>
                                                </td>
                                                <td class="winner-text silver-text">
                                                    <?= htmlspecialchars($event['silver_winner'] ?? 'N/A') ?>
                                                    <?php if($event['silver_count'] > 0) echo "<span class='winner-count-pill'>{$event['silver_count']}</span>"; ?>
                                                </td>
                                                <td class="winner-text bronze-text">
                                                    <?= htmlspecialchars($event['bronze_winner'] ?? 'N/A') ?>
                                                    <?php if($event['bronze_count'] > 0) echo "<span class='winner-count-pill'>{$event['bronze_count']}</span>"; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="tab-pane fade" id="graphs" role="tabpanel" aria-labelledby="graphs-tab">
                    <div class="row g-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header"><h5 class="mb-0"><i class="fas fa-chart-bar text-success me-2"></i>Medal Distribution</h5></div>
                                <div class="card-body chart-wrapper">
                                    <canvas id="medalBarChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header"><h5 class="mb-0"><i class="fas fa-chart-pie text-danger me-2"></i>Events Breakdown</h5></div>
                                <div class="card-body chart-wrapper d-flex align-items-center justify-content-center">
                                    <div style="width: 100%; max-width: 500px;">
                                        <canvas id="sportPieChart"></canvas>
                                    </div>
                                </div>
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
                <small>Developed by Jayvee Baybayon</small>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            
            // Initialize DataTables with Custom Empty Message Logic
            // Initialize DataTables with Custom Empty Message Logic
            const tableOptions = {
                "lengthMenu": [ [10, 25, 50, -1], [10, 25, 50, "All"] ],
                "language": { 
                    "search": "", 
                    "searchPlaceholder": "Search records...",
                    "zeroRecords": `<div class="text-center text-muted p-5">
                                        <i class="fas fa-clipboard-list empty-state-icon" style="font-size: 3rem; color: #dee2e6; margin-bottom: 1rem;"></i>
                                        <p class="mb-0">No records found.</p>
                                    </div>`,
                    "emptyTable": `<div class="text-center text-muted p-5">
                                        <i class="fas fa-clipboard-list empty-state-icon" style="font-size: 3rem; color: #dee2e6; margin-bottom: 1rem;"></i>
                                        <p class="mb-0">No data available.</p>
                                   </div>`
                },
                // CHANGED: Added flex-column, flex-sm-row, and gap-2 to make the Search/Length controls stack on mobile!
                "dom": '<"d-flex flex-column flex-sm-row justify-content-between align-items-center p-3 gap-2"lf>t<"d-flex flex-column flex-sm-row justify-content-between align-items-center p-3 gap-2"ip>'
            };
            $('#matchResultsTable').DataTable(tableOptions);
            $('#eventResultsTable').DataTable(tableOptions);

            const sidebar = document.getElementById('sidebar');
        const mobileToggle = document.getElementById('mobileToggle');
        const overlay = document.getElementById('sidebarOverlay'); // Grab the new overlay
        const footer = document.querySelector('footer');
        const navbar = document.querySelector('.navbar');

        // 1. Toggle Menu & Overlay together
        if (mobileToggle && sidebar && overlay) {
            mobileToggle.addEventListener('click', function() {
                const isOpen = sidebar.classList.toggle('show');
                overlay.style.display = isOpen ? 'block' : 'none';
            });
        }

        // 2. Click Overlay to Close Menu
        if (overlay && sidebar) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('show');
                overlay.style.display = 'none';
            });
        }

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
            
            // Initialize Charts on Tab Click (Lazy Load)
            const graphsTab = document.getElementById('graphs-tab');
            let chartsInitialized = false;
            
            graphsTab.addEventListener('shown.bs.tab', function() {
                if (!chartsInitialized) {
                    initCharts();
                    chartsInitialized = true;
                }
            });
        });

        // ==========================================
        // 2. NEW: REAL-TIME BADGE UPDATER
        // ==========================================
        function updateSidebarBadges() {
            // NOTE: Check if you need '../api_notifications.php' or just 'api_notifications.php'
            fetch('api_notifications.php?t=' + new Date().getTime())
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

    

        function initCharts() {
            // Bar Chart
            new Chart(document.getElementById('medalBarChart'), {
                type: 'bar',
                data: {
                    labels: <?= $chart_labels; ?>,
                    datasets: [
                        { label: 'Gold', data: <?= $chart_gold; ?>, backgroundColor: '#FFD700', borderColor: '#e0c000', borderWidth: 1 },
                        { label: 'Silver', data: <?= $chart_silver; ?>, backgroundColor: '#C0C0C0', borderColor: '#a0a0a0', borderWidth: 1 },
                        { label: 'Bronze', data: <?= $chart_bronze; ?>, backgroundColor: '#CD7F32', borderColor: '#a05a2c', borderWidth: 1 }
                    ]
                },
                // CHANGED: Expanded options to fix X-axis label overlapping on mobile
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { boxWidth: 12, padding: 10, font: { size: 11 } }
                        }
                    },
                    scales: {
                        x: {
                            ticks: {
                                maxRotation: 45, /* Allows labels to tilt when squished */
                                minRotation: 0,
                                font: { size: 10 } /* Smaller text for mobile fit */
                            }
                        },
                        y: {
                            ticks: { font: { size: 10 } }
                        }
                    }
                }
            });

            // Pie Chart (Updated with Percentages)
            new Chart(document.getElementById('sportPieChart'), {
                type: 'doughnut',
                data: {
                    labels: <?= $pie_labels; ?>,
                    datasets: [{
                        data: <?= $pie_data; ?>,
                        backgroundColor: ['#3498db', '#e74c3c', '#2ecc71', '#f1c40f', '#9b59b6', '#34495e'],
                        hoverOffset: 4
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    // 1. Get the current value and the dataset
                                    let value = context.raw;
                                    let total = context.chart._metasets[context.datasetIndex].total;
                                    
                                    // 2. Calculate Percentage
                                    let percentage = Math.round((value / total) * 100) + "%";
                                    
                                    // 3. Return the formatted string (e.g., "Basketball: 25%")
                                    return context.label + ': ' + percentage;
                                }
                            }
                        },
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                padding: 20
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>