<?php
session_start();
// Use a relative path to your main db_connect.php
require_once '../config.php'; 

// 1. SECURITY & ACCESS CONTROL
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Sports Director') {
    header('Location: ../login.php'); 
    exit();
}

if (!isset($_SESSION['user_id'])) {
    die("Session error: User ID is not set.");
}
$current_user_id = $_SESSION['user_id'];

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
    $profile_pic_path = '../' . $user_data['profile_picture'];
}

if (!empty($user_data['full_name'])) {
    $name = $user_data['full_name'];
} else {
    $name = $user_data['username'] ?? 'Sports Director';
}

$current_page = basename($_SERVER['PHP_SELF']);

// --- 1. FETCH SUMMARY DATA (FOR TOP TABLE) ---
$standings = [];
$sql_summary = "SELECT 
            C.college_id, C.college_name, C.logo_url,
            
            SUM(CASE WHEN CAT.gold_winner_college_id = C.college_id THEN CAT.gold_count ELSE 0 END) AS Gold,
            SUM(CASE WHEN CAT.silver_winner_college_id = C.college_id THEN CAT.silver_count ELSE 0 END) AS Silver,
            SUM(CASE WHEN CAT.bronze_winner_college_id = C.college_id THEN CAT.bronze_count ELSE 0 END) AS Bronze,
            
            (SUM(CASE WHEN CAT.gold_winner_college_id = C.college_id THEN CAT.gold_count ELSE 0 END) +
             SUM(CASE WHEN CAT.silver_winner_college_id = C.college_id THEN CAT.silver_count ELSE 0 END) +
             SUM(CASE WHEN CAT.bronze_winner_college_id = C.college_id THEN CAT.bronze_count ELSE 0 END))
            AS TotalMedals
            
        FROM colleges C
        
        LEFT JOIN categories CAT ON (
            C.college_id = CAT.gold_winner_college_id OR 
            C.college_id = CAT.silver_winner_college_id OR 
            C.college_id = CAT.bronze_winner_college_id
        ) 
        AND CAT.status = 'Results Approved'
        
        GROUP BY C.college_id, C.college_name, C.logo_url
        ORDER BY Gold DESC, Silver DESC, Bronze DESC, TotalMedals DESC, C.college_name ASC";
        
$result_summary = $conn->query($sql_summary);
if($result_summary) {
    $standings = $result_summary->fetch_all(MYSQLI_ASSOC);
} else {
    die("SQL Error (Summary): " . $conn->error);
}

// --- 2. FETCH DETAILED DATA (FOR BOTTOM TABLE) ---
$detailed_results = [];
$sql_detailed = "SELECT 
                    g.game_name, ge.event_name, c.category_name, c.division_name, 
                    c.gold_count, c.silver_count, c.bronze_count,
                    gold_col.college_name AS gold_winner,
                    silver_col.college_name AS silver_winner,
                    bronze_col.college_name AS bronze_winner
                FROM categories c
                JOIN game_events ge ON c.event_id = ge.event_id
                JOIN games g ON ge.game_id = g.game_id
                LEFT JOIN colleges gold_col ON c.gold_winner_college_id = gold_col.college_id
                LEFT JOIN colleges silver_col ON c.silver_winner_college_id = silver_col.college_id
                LEFT JOIN colleges bronze_col ON c.bronze_winner_college_id = bronze_col.college_id
                WHERE c.status = 'Results Approved'
                ORDER BY g.game_name, ge.event_name, c.category_name";

$result_detailed = $conn->query($sql_detailed);
if($result_detailed) {
    $detailed_results = $result_detailed->fetch_all(MYSQLI_ASSOC);
} else {
    die("SQL Error (Detailed): " . $conn->error);
}

// Sidebar Badges
// Count pending requests for sidebar badge (Fixed: Counts unapproved users)
$pending_requests_count = $conn->query("SELECT COUNT(*) FROM users WHERE is_approved = 0")->fetch_row()[0] ?? 0;
$pending_results_count = $conn->query("SELECT COUNT(*) FROM categories WHERE status='Results Submitted'")->fetch_row()[0] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medal Reports - Sports Director Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* --- Unified CSS from Dashboard --- */
        :root { 
            --sidebar-width: 260px; 
            --header-height: 82px; 
            --transition: all 0.3s ease; 
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); 
            --bg-light: #F8F9FA; 
            --primary-gradient: linear-gradient(135deg, #2c3e50 0%, #4ca1af 100%);
            --accent-color: #1abc9c;
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

        /* === ENHANCED TABLE DESIGN === */
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08); overflow: hidden; margin-bottom: 2rem; }
        .card-header { background: #ffffff !important; border-bottom: 2px solid #f1f3f5 !important; padding: 1.25rem 1.5rem !important; }
        
        .results-table-container {
            background: #ffffff;
            overflow-x: auto;
        }
        
        .results-table { margin-bottom: 0; font-size: 0.9375rem; width: 100%; border-collapse: separate; border-spacing: 0; }
        
        .results-table thead th {
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
        
        .results-table tbody td { padding: 1.125rem 1.25rem; vertical-align: middle; border-bottom: 1px solid #f1f3f5; transition: all 0.2s ease; }
        .results-table tbody tr { transition: all 0.2s ease; }
        .results-table tbody tr:hover { background-color: #f8f9fa; }

        /* Rank Column */
        .rank-cell { font-weight: 700; color: #555; font-size: 1rem; width: 60px; text-align: center; }

        /* Medal Numbers */
        .medal-number { font-family: 'Inter', sans-serif; font-weight: 600; font-size: 1.1rem; }
        .gold-text { color: #f59e0b; }
        .silver-text { color: #64748b; }
        .bronze-text { color: #ea580c; }
        .total-text { color: #2c3e50; font-weight: 700; }
        .winner-count { font-weight: 600; color: #333; font-size: 0.85em; opacity: 0.8; }

        /* Type Badges */
        .type-badge { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.4rem 0.75rem; border-radius: 6px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .type-badge.badge-match { background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%); color: #4338ca; border: 1px solid #a5b4fc; }
        .type-badge.badge-medal { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #b45309; border: 1px solid #fcd34d; }

        /* DataTables Customization */
        .dataTables_wrapper .dataTables_length, 
        .dataTables_wrapper .dataTables_filter { padding: 1rem 1.5rem; }
        .dataTables_wrapper .dataTables_info, 
        .dataTables_wrapper .dataTables_paginate { padding: 1rem 1.5rem; }
        
        /* Print Styling */
        @media print {
            .sidebar, .navbar, .btn, .dataTables_length, .dataTables_filter, .dataTables_paginate { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
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
   MOBILE REPORTS OPTIMIZATION (Medal Standings)
   ========================================= */
@media (max-width: 991.98px) {

    /* --- 1. GENERAL LAYOUT --- */
    .container-fluid {
        padding-left: 15px;
        padding-right: 15px;
    }

    /* Stack Header & Print Button */
    .d-flex.justify-content-between.align-items-center.mb-4 {
        flex-direction: column;
        align-items: stretch;
        gap: 15px;
    }
    
    /* Make Print Button & Sort Filter Full Width */
    .btn-secondary, 
    #sortFilter {
        width: 100% !important;
        padding: 10px;
    }
    
    /* Adjust Sort Filter Container Width */
    .card-header .d-flex {
        flex-direction: column;
        width: 100%;
        gap: 10px;
    }
    .card-header div[style="width: 200px;"] {
        width: 100% !important;
    }

    /* --- 2. SWIPEABLE TABS --- */
    .nav-tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
        overflow-y: hidden;
        white-space: nowrap;
        -webkit-overflow-scrolling: touch;
        border-bottom: 1px solid #dee2e6;
        padding-bottom: 5px;
    }
    .nav-tabs .nav-link {
        padding: 10px 15px;
        font-size: 0.9rem;
    }

    /* --- 3. MEDAL TABLE (Sticky Column Technique) --- */
    
    /* Container Scroll */
    .report-table-container {
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        margin-bottom: 20px;
        background: #fff;
    }

    /* Force Table Width (Prevent Squishing) */
    .report-table {
        min-width: 600px; /* Ensures enough width for numbers */
    }

    /* Cell Standardization */
    .report-table th, 
    .report-table td {
        padding: 10px 8px !important;
        font-size: 0.85rem !important; /* Smaller text for mobile */
        white-space: nowrap;
        text-align: center;
        vertical-align: middle;
    }

    /* --- COLUMN 1: RANK (Hide on very small screens) --- */
    .report-table th:first-child, 
    .report-table td:first-child {
        display: none; 
    }

    /* --- COLUMN 2: TEAM NAME (Sticky Left) --- */
    /* This keeps the college name visible while scrolling medals */
    .report-table td:nth-child(2),
    .report-table th:nth-child(2) {
        position: sticky !important;
        left: 0;
        background-color: #fff;
        z-index: 5;
        border-right: 2px solid #f0f0f0; /* Visual separator */
        text-align: left;
        min-width: 150px;
        max-width: 170px;
        white-space: normal; /* Allow text wrapping */
        line-height: 1.2;
    }

    /* Fix header background for sticky column */
    .report-table thead th:nth-child(2) { 
        background: #f8f9fa; 
        z-index: 10; 
    }
    
    /* Fix hover background for sticky column */
    .report-table tbody tr:hover td:nth-child(2) { 
        background: #f8f9fa; 
    }

    /* Adjust Team Logo Size */
    .report-table td:nth-child(2) img {
        width: 30px !important;
        height: 30px !important;
        margin-right: 8px !important;
    }

    /* Typography Adjustments */
    .report-table td:nth-child(2) .fw-bold { font-size: 0.9rem; } /* Team Code */
    .report-table td:nth-child(2) .text-muted { font-size: 0.75rem; display: block; } /* Full Name */

    /* --- MEDAL COLUMNS (Center & Bold) --- */
    .report-table td:nth-child(3), /* Gold */
    .report-table td:nth-child(4), /* Silver */
    .report-table td:nth-child(5), /* Bronze */
    .report-table td:nth-child(6) { /* Total */
        min-width: 60px;
        font-weight: 700;
        font-size: 1rem !important; /* Larger numbers */
    }

    /* --- 4. DETAILED REPORT TABLE (DataTables) --- */
    /* Handled by DataTables responsive, but we tweak the container */
    #detailedReportTable {
        min-width: 800px; /* Force scroll */
    }
    /* Make first column (Game Name) sticky in Detailed view too */
    #detailedReportTable td:first-child,
    #detailedReportTable th:first-child {
        position: sticky;
        left: 0;
        background: #fff;
        z-index: 5;
        border-right: 2px solid #f0f0f0;
        font-weight: 700;
    }
    #detailedReportTable thead th:first-child { background: #f8f9fa; z-index: 10; }
    #detailedReportTable tbody tr:hover td:first-child { background: #f8f9fa; }

    /* --- 5. CHARTS ADAPTATION --- */
    .chart-wrapper {
        height: 300px !important; /* Shorter height for mobile to see content */
        padding: 10px;
    }
    
    /* Stack charts vertically with space */
    .col-12 .card {
        margin-bottom: 20px;
    }

    
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
}
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
                
                    <?php if (!empty($profile_pic_path) && file_exists($profile_pic_path)): ?>
                        <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="Profile" style="width: 42px; height: 42px; border-radius: 50%; object-fit: cover; margin-right: 10px; border: 2px solid rgba(255,255,255,0.2);">
                    <?php else: ?>
                        <i class="fas fa-user-circle" style="font-size:36px;margin-right:10px;"></i>
                    <?php endif; ?>
                    
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
                <a class="nav-link" href="events.php">
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
                <a class="nav-link active" href="reports.php">
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
            
            <nav aria-label="breadcrumb" class="mb-4">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="sports_director_dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Medal Reports</li>
              </ol>
            </nav>

            <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="page-header mb-4">
                        <h1 class="section-title">
                            </i>Overall Team Rankings
                        </h1>
                    </div>
                <button class="btn btn-secondary" onclick="window.print()">
                    <i class="fas fa-print me-2"></i> Print Report
                </button>
            </div>

            <!-- SUMMARY CARD -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-trophy text-warning me-2"></i> Overall Team Rankings</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive results-table-container">
                        <table class="table results-table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th class="text-center">Rank</th>
                                    <th>Team</th>
                                    <th class="text-center" style="color: #f59e0b;">Gold</th>
                                    <th class="text-center" style="color: #64748b;">Silver</th>
                                    <th class="text-center" style="color: #ea580c;">Bronze</th>
                                    <th class="text-center">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($standings)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted p-4">No approved results yet.</td>
                                </tr>
                                <?php endif; ?>
                                <?php $rank = 1; foreach ($standings as $college): ?>
                                <tr>
                                    <td class="rank-cell"><?= $rank++ ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="../<?= htmlspecialchars($college['logo_url'] ?? 'images/default_avatar.png') ?>" alt="Logo" style="width: 36px; height: 36px; border-radius: 50%; object-fit: cover; margin-right: 15px; border: 1px solid #dee2e6;">
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($college['college_name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="text-center medal-number gold-text"><?= $college['Gold'] ?></td>
                                    <td class="text-center medal-number silver-text"><?= $college['Silver'] ?></td>
                                    <td class="text-center medal-number bronze-text"><?= $college['Bronze'] ?></td>
                                    <td class="text-center medal-number total-text"><?= $college['TotalMedals'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- DETAILED RESULTS CARD -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-list-ul me-2 text-primary"></i> Detailed Event Results</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive results-table-container">
                        <table id="detailedReportTable" class="table results-table table-hover align-middle" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Game</th>
                                    <th>Event</th>
                                    <th>Category & Division</th>
                                    <th><i class="fas fa-medal text-warning me-1"></i> Gold</th>
                                    <th><i class="fas fa-medal text-secondary me-1"></i> Silver</th>
                                    <th><i class="fas fa-medal text-danger me-1"></i> Bronze</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($detailed_results as $row): ?>
                                <tr>
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($row['game_name']) ?></td>
                                    <td><?= htmlspecialchars($row['event_name']) ?></td>
                                    
                                    <td>
                                        <?php 
                                        if ($row['category_name'] === 'Main Event' || $row['category_name'] === 'Main Competition') {
                                            echo '<span class="text-muted fst-italic small">(no category)</span>';
                                        } else {
                                            // Print Category
                                            echo '<span class="fw-bold text-dark d-block">' . htmlspecialchars($row['category_name']) . '</span>';
                                            
                                            // Print Division (if it exists) underneath it without the badge box
                                            if (!empty($row['division_name'])) {
                                                echo '<span class="text-secondary small d-block mt-1">' . htmlspecialchars($row['division_name']) . '</span>';
                                            }
                                        }
                                        ?>
                                    </td>

                                    <td class="gold-text fw-bold">
                                        <?= htmlspecialchars($row['gold_winner'] ?? 'N/A') ?>
                                        <?php if($row['gold_count'] > 1) echo "<span class='winner-count ms-1'>({$row['gold_count']})</span>"; ?>
                                    </td>
                                    <td class="silver-text fw-bold">
                                        <?= htmlspecialchars($row['silver_winner'] ?? 'N/A') ?>
                                        <?php if($row['silver_count'] > 1) echo "<span class='winner-count ms-1'>({$row['silver_count']})</span>"; ?>
                                    </td>
                                    <td class="bronze-text fw-bold">
                                        <?= htmlspecialchars($row['bronze_winner'] ?? 'N/A') ?>
                                        <?php if($row['bronze_count'] > 1) echo "<span class='winner-count ms-1'>({$row['bronze_count']})</span>"; ?>
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
    <script src="https://code.jquery.com/jquery-3.7.0.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            
            // Initialize DataTable with custom wrapper class for styling consistency
            $('#detailedReportTable').DataTable({
                "lengthMenu": [ [10, 25, 50, -1], [10, 25, 50, "All"] ],
                "language": {
                    "search": "_INPUT_",
                    "searchPlaceholder": "Search records...",
                    "lengthMenu": "Show _MENU_ entries"
                },
                "dom": '<"d-flex justify-content-between align-items-center m-3"lf>t<"d-flex justify-content-between align-items-center m-3"ip>'
            });

            const mobileToggle = document.getElementById('mobileToggle');
            if (mobileToggle) {
                mobileToggle.addEventListener('click', function() {
                    document.getElementById('sidebar').classList.toggle('show');
                });
            }
            
            // Dynamic Footer
            const footer = document.querySelector('footer');
            const sidebar = document.getElementById('sidebar');
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
        });
    </script>
</body>
</html>