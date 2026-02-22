<?php
session_start();
require_once 'config.php'; // Make sure this path is correct

// PHP variables for header/footer consistency
$current_page = basename($_SERVER['PHP_SELF']);
$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Guest'; // Use Guest for non-logged-in users

// --- 1. Fetch Dynamic Event Categories (from L1 Games) ---
$event_categories_ordered = [];
$games_result = $conn->query("SELECT game_name FROM games ORDER BY game_name");
if ($games_result) {
    while ($row = $games_result->fetch_assoc()) {
        $event_categories_ordered[] = $row['game_name'];
    }
}

// --- 2. Fetch All Public Event Data (from L1, L2, L3 tables) ---
$events = [];
$total_events = 0;
$completed_events = 0;
$ongoing_events = 0;

$sql_fetch_events = "
    SELECT 
        c.category_id AS event_id,
        c.category_type,
        c.category_name AS event_name,
        
        CASE 
            WHEN c.status = 'Results Approved' THEN 'Completed' 
            ELSE c.status 
        END AS event_status,
        
        ge.event_name AS category,
        g.game_name AS sport_name,
        
        COALESCE(u.full_name, u.username) AS manager_name , c.event_date
        , c.event_time
        , c.venue
        
    FROM categories c
    JOIN game_events ge ON c.event_id = ge.event_id
    JOIN games g ON ge.game_id = g.game_id
    
    LEFT JOIN event_manager_assignments ema ON ge.event_id = ema.event_id
    LEFT JOIN users u ON ema.user_id = u.id 
    
    WHERE c.status != 'Draft' AND c.status IS NOT NULL 
    
    ORDER BY g.game_name, ge.event_name, c.category_name
";

$stmt_fetch_events = $conn->prepare($sql_fetch_events);

if ($stmt_fetch_events) {
    $stmt_fetch_events->execute();
    $result_events = $stmt_fetch_events->get_result();

    if ($result_events) {
        while ($row = $result_events->fetch_assoc()) {
            $row['description'] = $row['description'] ?? 'No description provided.';
            $row['start_date'] = $row['start_date'] ?? 'TBA';
            $row['end_date'] = $row['end_date'] ?? 'TBA';
            
            $events[] = $row;
            $total_events++;

            $status_lower = strtolower($row['event_status']);
            if (str_contains($status_lower, 'completed') || str_contains($status_lower, 'approved')) {
                $completed_events++;
            } elseif ($status_lower === 'ongoing') {
                $ongoing_events++;
            }
        }
    }
    $stmt_fetch_events->close();
} else {
    error_log("Error preparing event fetch statement: " . $conn->error);
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Siglakas Events</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.iconify.design/2/2.2.1/iconify.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">


    <style>
        /* --- STYLES --- */
        :root {
            --primary-green: #4CAF50;
            --primary-dark: #2E7D32;
            --accent-gold: #FFD700;
            --accent-silver: #C0C0C0;
            --accent-bronze: #CD7F32;
            --bg-light: #F8F9FA;
            --bg-white: #FFFFFF;
            --text-dark: #1A1A1A;
            --text-muted: #6C757D;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 32px rgba(0,0,0,0.12);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: var(--bg-light); 
            position: relative;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            color: var(--text-dark);
            line-height: 1.6;
        }

        .navbar {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            padding: 1rem 0;
            backdrop-filter: blur(10px);
        }
        .navbar-brand { transition: var(--transition); }
        .navbar-brand:hover { transform: translateY(-2px); }
        .brand-logo { filter: drop-shadow(0 2px 4px rgba(255,255,255,0.1)); }
        .brand-heading { font-family: 'Poppins', sans-serif; font-weight: 700; letter-spacing: -0.5px; }
        .nav-link { 
            font-weight: 500; 
            font-size: 0.95rem; 
            padding: 0.5rem 1.25rem !important; 
            margin: 0 0.25rem; 
            transition: var(--transition); 
            /* Remove border-radius so the line is straight */
            border-bottom: 3px solid transparent; 
        }

        .nav-link:hover, .nav-link.active { 
            /* Remove the background box */
            background: transparent !important; 
            
            /* Change text color */
            color: var(--primary-green) !important; 
            
            /* Add the Underline */
            border-bottom: 3px solid var(--primary-green); 
        }
        
        .btn-danger, .btn-success { 
            padding: 0.6rem 1.5rem; 
            border-radius: 10px; 
            font-weight: 600; 
            transition: var(--transition); 
            border: none; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.15); 
        }
        .btn-danger:hover, .btn-success:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 6px 20px rgba(0,0,0,0.2); 
        }

        .main-content {
            flex: 1 0 auto;
            position: relative;
            z-index: 1;
            padding-top: 100px;
        }
        
        .overall-card {
            background: var(--bg-white);
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            padding: 2.5rem;
            margin-bottom: 2.5rem;
            position: relative;
            overflow: hidden;
        }
        
        /* --- NEW HERO & STATS STYLES --- */
        .hero-section {
            background: linear-gradient(135deg, #ffffff 0%, #f8fcf9 100%);
            border: 1px solid rgba(0,0,0,0.04);
            border-radius: 20px;
            padding: 3rem 2.5rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.04);
            position: relative;
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(76, 175, 80, 0.08) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        /* Enhanced Hero Title */
        .hero-title-wrapper {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            margin-bottom: 1.25rem;
        }

        .hero-icon-circle {
            width: 70px;
            height: 70px;
            min-width: 70px;
            background: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 20px rgba(76, 175, 80, 0.25);
            transform: rotate(-5deg);
            transition: transform 0.3s ease;
        }

        .hero-icon-circle:hover {
            transform: rotate(0deg) scale(1.05);
        }

        .hero-icon-circle img {
            width: 45px;
            height: 45px;
            object-fit: contain;
            filter: drop-shadow(0 2px 4px rgba(255,255,255,0.3)); /* Subtle white glow */
        }

        .hero-title-text h1 {
            font-family: 'Poppins', sans-serif;
            font-weight: 800;
            font-size: 2.75rem;
            color: #1a1a1a;
            margin: 0;
            line-height: 1.1;
            letter-spacing: -1.5px;
        }

        .hero-subtitle-badge {
            display: inline-block;
            background: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%);
            color: white;
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
        }

        .hero-description {
            font-size: 1.05rem;
            line-height: 1.8;
            color: #5a6c7d;
            margin: 0;
            max-width: 600px;
        }

                .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.75rem 1.5rem;
            border: 1px solid rgba(0,0,0,0.06);
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            height: 100%;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(180deg, #4CAF50 0%, #2E7D32 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 32px rgba(0,0,0,0.1);
            border-color: rgba(76, 175, 80, 0.3);
        }

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-icon-circle {
            width: 64px;
            height: 64px;
            min-width: 64px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1.25rem;
            transition: transform 0.3s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .stat-card:hover .stat-icon-circle {
            transform: scale(1.1) rotate(5deg);
        }

        .stat-theme-total { 
            background: linear-gradient(135deg, #f1f3f5 0%, #e9ecef 100%);
        }

        .stat-theme-completed { 
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
        }

        .stat-theme-ongoing { 
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
        }

        .stat-content .display-6 {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 0.25rem;
        }

        .stat-content small {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            font-weight: 700;
        }

        /* Color Themes for Stats */
        .stat-theme-total { background: rgba(33, 37, 41, 0.05); }   /* Dark/Gray */
        .stat-theme-completed { background: rgba(13, 110, 253, 0.1); } /* Blue */
        .stat-theme-ongoing { background: rgba(25, 135, 84, 0.1); }    /* Green */

        /* Premium LIVE Badge */
        .live-badge-wrapper {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            padding: 0.5rem 1.25rem;
            border-radius: 25px;
            box-shadow: 0 4px 16px rgba(40, 167, 69, 0.4);
            animation: pulse-live 2s infinite;
            position: relative;
        }

        .live-badge-wrapper::before {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: 25px;
            background: linear-gradient(135deg, #28a745, #20c997);
            z-index: -1;
            filter: blur(8px);
            opacity: 0.6;
            animation: pulse-glow 2s infinite;
        }

        .live-indicator-dot {
            width: 10px;
            height: 10px;
            background: white;
            border-radius: 50%;
            animation: blink-dot 1.5s infinite;
        }

        @keyframes pulse-live {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        @keyframes pulse-glow {
            0%, 100% { opacity: 0.6; }
            50% { opacity: 0.8; }
        }

        @keyframes blink-dot {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        
        .events-nav-tabs {
            border-bottom: 2px solid #dee2e6;
            margin-bottom: 1.5rem;
        }
        .events-nav-tabs .nav-link {
            border: none;
            border-bottom: 3px solid transparent;
            color: var(--text-muted);
            font-weight: 600;
            padding: 0.75rem 0.25rem;
            margin-right: 1.5rem;
            transition: var(--transition);
        }
        .events-nav-tabs .nav-link:hover {
            color: var(--text-dark);
        }
        .events-nav-tabs .nav-link.active {
            color: var(--primary-dark);
            border-bottom-color: var(--primary-dark);
            background-color: transparent;
        }
        
        .events-filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .event-search-input {
            flex-grow: 1;
            flex-basis: 300px;
            position: relative;
        }
        .event-search-input .form-control {
            padding-left: 2.5rem;
            border-radius: 10px;
        }
        .event-search-input .search-icon {
            position: absolute;
            left: 0.85rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
        }
        .event-filter-status {
            flex-grow: 1;
            flex-basis: 200px;
        }
        .event-filter-status .form-select {
            border-radius: 10px;
        }

        .sport-card {
            border-radius: 16px; /* Slightly rounder for a modern look */
            background: #fff;
            
            /* NEW: Stronger Shadow for visibility */
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12); 
            
            /* NEW: Light border to separate it from the white background */
            border: 1px solid rgba(0, 0, 0, 0.05);

            padding: 24px;
            margin-bottom: 24px;
            transition: all 0.3s ease;
            
            height: 100%;           
            min-height: 220px;      
            
            border-left: 5px solid transparent; /* Slightly thicker accent line */
            display: flex;
            flex-direction: column;
        }

        .sport-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            border-left-color: var(--primary-green);
        }
        .sport-card-body {
            flex-grow: 1;
        }
        .sport-card p.text-muted {
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
        }
        
        .event-title {
            font-family: 'Roboto', sans-serif;
            font-weight: 700;
            font-size: 1.25rem;       /* Increased size slightly for impact */
            color: #2c3e50;
            margin: 0;
            line-height: 1.2;
            padding-top: 2px;
            
            /* FIX: Professional Wrapping */
            white-space: normal;
            word-break: normal;       /* Stops "Basketb all" */
            overflow-wrap: break-word; /* Wraps "Chess(INDIVIDUAL)" naturally */
        }
        
        .check-event {
            font-weight: 600;
            color: #001f3f;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            transition: all 0.2s;
        }
        .check-event:hover {
            color: #0066ff;
        }

        footer.bg-dark {
            margin-top: auto; 
        }
        .iconify {
            vertical-align: -0.125em;
        }
        
        #viewEventModal .modal-content {
            border: none;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        }
        #viewEventModal .nav-tabs {
            border-bottom: 2px solid #dee2e6;
        }
        #viewEventModal .nav-tabs .nav-link {
            color: #6c757d;
            border: none;
            border-bottom: 3px solid transparent;
            font-weight: 500;
            padding: 12px 20px;
            transition: all 0.2s;
        }
        #viewEventModal .nav-tabs .nav-link:hover {
            color: #0d6efd;
            background-color: #f8f9fa;
            border-bottom-color: #0d6efd;
        }
        #viewEventModal .nav-tabs .nav-link.active {
            color: #0d6efd;
            background-color: white;
            border-bottom-color: #0d6efd;
        }
        .detail-row {
            padding-bottom: 12px;
            border-bottom: 1px solid #f0f0f0;
        }
        .detail-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        #viewEventModal .table thead th {
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .modal-body .medal-icon {
            font-size: 1.2em;
            margin-right: 5px;
        }
        .modal-body .gold-medal { color: var(--accent-gold); }
        .modal-body .silver-medal { color: var(--accent-silver); }
        .modal-body .bronze-medal { color: var(--accent-bronze); }

        /* --- MODERN 3D PODIUM DESIGN --- */
        .podium-container {
            display: flex;
            align-items: flex-end;
            justify-content: center;
            min-height: 320px; /* Taller for better impact */
            padding: 3rem 1rem 1rem;
            gap: 15px; /* More spacing between steps */
            background: radial-gradient(circle at center bottom, rgba(255, 215, 0, 0.05) 0%, transparent 70%); /* Subtle gold glow from bottom */
        }

        .podium-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 30%; /* Slightly narrower columns */
            position: relative;
            transition: transform 0.3s ease;
        }

        .podium-wrapper:hover {
            transform: translateY(-5px); /* Slight lift on hover */
        }

        /* Order: Silver(1) - Gold(2) - Bronze(3) */
        .podium-wrapper.gold { order: 2; width: 35%; z-index: 10; } /* Gold is wider and on top */
        .podium-wrapper.silver { order: 1; }
        .podium-wrapper.bronze { order: 3; }

        /* WINNER NAME CARDS */
        .podium-winner-name {
            font-size: 1rem;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 15px;
            text-align: center;
            width: 100%;
            background: white;
            padding: 8px 5px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            border: 1px solid rgba(0,0,0,0.05);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .podium-wrapper.gold .podium-winner-name {
            font-size: 1.2rem;
            color: #b78a02;
            border-bottom: 3px solid #FFD700;
        }

        /* THE STEPS (Pillars) */
        .podium-step {
            display: flex;
            flex-direction: column;
            justify-content: flex-start; 
            align-items: center;
            text-align: center;
            width: 100%;
            border-radius: 12px 12px 0 0;
            padding-top: 20px;
            position: relative;
            box-shadow: 
                inset 0 0 20px rgba(0,0,0,0.05), /* Inner Depth */
                0 10px 20px rgba(0,0,0,0.1); /* Drop Shadow */
            color: white; 
        }

        /* GOLD PILLAR */
        .podium-step.gold {
            height: 220px;
            background: linear-gradient(135deg, #FFD700 0%, #FDB931 50%, #d4af37 100%);
            border: none;
        }

        /* SILVER PILLAR */
        .podium-step.silver {
            height: 160px;
            background: linear-gradient(135deg, #E0E0E0 0%, #BDBDBD 50%, #9E9E9E 100%);
            border: none;
        }

        /* BRONZE PILLAR */
        .podium-step.bronze {
            height: 120px;
            background: linear-gradient(135deg, #FFAF7B 0%, #D78957 50%, #A0522D 100%);
            border: none;
        }

        /* MEDAL ICONS */
        .podium-medal {
            font-size: 2.5rem;
            margin-bottom: 5px;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
            color: white !important; 
        }

        /* MEDAL COUNT (The Score) */
        .podium-medal-count {
            font-size: 2rem;
            font-weight: 900;
            color: rgba(255,255,255,0.95);
            line-height: 1;
        }

        .podium-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            opacity: 0.8;
            margin-top: 5px;
            font-weight: 600;
        }

        /* RANK BADGE (1, 2, 3) */
        .rank-badge-podium {
            width: 40px;
            height: 40px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.2rem;
            margin-top: auto; 
            margin-bottom: 20px;
            border: 1px solid rgba(255,255,255,0.4);
        }

        @media (max-width: 991.98px) {
            .hero-section {
                padding: 24px;
            }
            .sport-card {
                height: auto;
                min-height: 220px;
            }
        }
        
        @media (max-width: 767.98px) {
            .overall-card {
                padding: 1.5rem;
            }
            .stat-card {
                margin-bottom: 12px;
            }
            .events-filter-bar {
                flex-direction: column;
            }
            .event-search-input,
            .event-filter-status {
                flex-basis: auto;
                width: 100%;
            }
            .events-nav-tabs .nav-link {
                margin-right: 1rem;
                padding: 0.75rem 0.1rem;
            }
            
            /* ADJUST PODIUM FOR PHONES */
            .podium-container {
                min-height: 220px; 
                padding: 1rem 0.5rem;
                align-items: flex-end; 
            }
            
            .podium-wrapper {
                width: 32%; 
            }
            .podium-wrapper.gold { width: 36%; } 

            /* Make pillars shorter for mobile */
            .podium-step { padding: 10px 2px; }
            .podium-step.gold { height: 160px; }
            .podium-step.silver { height: 120px; }
            .podium-step.bronze { height: 90px; }

            /* Resize text/icons */
            .podium-medal { font-size: 1.5rem; margin-bottom: 2px; }
            .podium-medal-count { font-size: 1.2rem; }
            .podium-label { font-size: 0.6rem; margin-top: 2px; }
            
            /* Hide the "1, 2, 3" badge on very small screens */
            .rank-badge-podium {
                width: 25px; height: 25px; font-size: 0.8rem; margin-bottom: 5px;
            }
            
            .podium-winner-name {
                font-size: 0.75rem;
                margin-bottom: 8px;
                padding: 4px 2px;
            }
        }
        
        .status-pill {
    font-size: 0.7rem;          /* Slightly smaller text to fit long statuses */
    font-weight: 800;
    padding: 6px 10px;
    border-radius: 30px;
    color: #fff;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.15);
    border: 1px solid rgba(255,255,255,0.2);
    
    /* FIX: Set a fixed minimum width and center text */
    min-width: 130px;           /* Ensures all badges are at least this wide */
    text-align: center;         /* Centers the text inside the badge */
    display: inline-block;      /* Required for width to work */
    white-space: nowrap;        /* Keeps status text on one line */
}

        /* 1. ONGOING (Active/Green) - With Pulse Animation */
        .status-ongoing {
            background: linear-gradient(135deg, #28a745, #218838) !important;
            box-shadow: 0 0 8px rgba(40, 167, 69, 0.6);
            animation: pulse-green 2s infinite;
        }

        /* 2. COMPLETED (Finished/Blue) */
        .status-completed {
            background: linear-gradient(135deg, #0d6efd, #0b5ed7) !important;
        }

        /* 3. PENDING (Waiting/Orange) */
        .status-pending {
            background: linear-gradient(135deg, #fd7e14, #e36b09) !important; /* Bright Orange */
            color: white !important;
        }

        /* 4. UPCOMING (Future/Teal) */
        .status-upcoming {
            background: linear-gradient(135deg, #17a2b8, #138496) !important;
        }
        
        /* 5. CANCELLED (Red) */
        .status-cancelled {
            background: linear-gradient(135deg, #dc3545, #c82333) !important;
        }

        /* Animation for Ongoing */
        @keyframes pulse-green {
            0% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.7); }
            70% { box-shadow: 0 0 0 6px rgba(40, 167, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0); }
        }

        /* PODIUM LOGO STYLES */
        .podium-logo {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            background-color: white;
            margin-bottom: -15px; /* Pull it down slightly to overlap the name card */
            position: relative;
            z-index: 2;
            transition: transform 0.3s ease;
        }

        .podium-wrapper:hover .podium-logo {
            transform: scale(1.1); /* Pop effect on hover */
        }

        /* Make Gold Logo Bigger */
        .podium-wrapper.gold .podium-logo {
            width: 80px;
            height: 80px;
            border: 4px solid #FFD700; /* Gold border */
        }

        /* Adjust winner name to accommodate the logo above it */
        .podium-winner-name {
            padding-top: 15px; /* Make space for the logo overlap */
            margin-top: 5px;
        }

        /* --- PROFESSIONAL PHOTO FRAME --- */
        .winning-photo-frame {
            width: 100%;           /* Fill the available width */
            max-width: 500px;      /* Prevent it from getting too huge on big screens */
            height: 320px;         /* FIXED HEIGHT: This ensures consistency */
            margin: 0 auto;        /* Center the frame */
            background-color: #f8f9fa; /* Light gray background */
            border-radius: 12px;   /* Smooth corners */
            overflow: hidden;      /* Cut off any image overflow */
            border: 4px solid #fff; 
            box-shadow: 0 8px 20px rgba(0,0,0,0.15); /* Nice depth */
            position: relative;
        }

        .winning-photo-frame img {
            width: 100%;
            height: 100%;
            object-fit: cover;     /* THE KEY: Crops image to fill the box perfectly */
            object-position: center top; /* Focus on faces/top part of image */
            cursor: pointer;
            transition: transform 0.4s ease;
        }

        .winning-photo-frame:hover img {
            transform: scale(1.05); /* Subtle zoom effect on hover */
        }

        /* Optional: "Expand" icon overlay on hover */
        .winning-photo-frame::after {
            content: '\f00e'; /* FontAwesome Zoom Icon */
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 2rem;
            opacity: 0;
            transition: opacity 0.3s ease;
            text-shadow: 0 2px 10px rgba(0,0,0,0.5);
            pointer-events: none;
        }

        .winning-photo-frame:hover::after {
            opacity: 1;
        }

        /* --- FOOTER & PRINT --- */
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
            gap: 1rem;
            margin-bottom: 1rem;
        }
        /* --- FOOTER LOGO FIX --- */
        .footer-main .footer-logo-group {
            display: flex;              /* Forces items to sit in a row */
            align-items: center;        /* Vertically centers them */
            gap: 12px;                  /* Space between logos and text */
            margin-bottom: 1rem;
        }

        .footer-main .footer-logo-group img {
            height: 50px !important;    /* Force height */
            width: 50px !important;     /* Force width */
            object-fit: contain;        /* Keep logo shape correct */
        }

        .footer-main .footer-logo-group h5 {
            margin: 0;                  /* Remove default spacing that pushes it down */
            font-size: 1.1rem;          /* Adjust text size */
            font-weight: 700;
            color: #fff;
            line-height: 1.2;           /* Tighter line spacing */
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

       /* =========================================
   MOBILE OPTIMIZATION (Eventpage.php)
   ========================================= */

/* --- 1. COMPACT NAVBAR (Horizontal Layout) --- */
@media (max-width: 991px) {
    /* Reduce Header Height */
    .navbar {
        padding: 0.5rem 1rem !important;
        min-height: 60px;
    }

    /* Shrink Logo & Text */
    .brand-logo {
        height: 36px !important;
        width: 36px !important;
    }
    .brand-heading {
        font-size: 1rem !important;
    }
    .brand-subheading {
        font-size: 0.65rem !important;
    }

    /* Force Horizontal Menu (Side-by-Side) */
    .navbar-collapse {
        margin-top: 0;
        padding-bottom: 0;
        border-top: none;
    }

    .navbar-nav {
        flex-direction: row !important; /* Forces horizontal row */
        align-items: center !important;
        justify-content: flex-end;      /* Aligns items to the right */
        gap: 10px;                      /* Spacing between items */
        width: 100%;
        padding: 5px 0;
    }

    /* Compact Links */
    .nav-link {
        padding: 0.4rem 0.6rem !important;
        font-size: 0.85rem !important;
    }

    /* Compact Login Button (Placed Beside Links) */
    .nav-item .btn {
        margin: 0 !important;
        padding: 0.3rem 0.8rem !important;
        font-size: 0.8rem !important;
        line-height: 1.2;
    }
}

/* --- 2. COMPACT HERO SECTION (Mobile Phones Only) --- */
@media (max-width: 767.98px) {
    /* Shrink Hero Container */
    .hero-section {
        padding: 1.25rem !important;
        margin-bottom: 1.5rem !important;
        border-radius: 12px;
    }

    /* Compact Title Area */
    .hero-title-wrapper {
        gap: 10px;
        margin-bottom: 0.5rem;
    }

    .hero-icon-circle {
        width: 45px;
        height: 45px;
        min-width: 45px;
        border-radius: 12px;
    }
    .hero-icon-circle img {
        width: 24px;
        height: 24px;
    }

    /* Shrink Title Fonts */
    .hero-title-text h1 {
        font-size: 1.5rem !important;
        margin-bottom: 0;
        line-height: 1.2;
    }
    .hero-subtitle-badge {
        font-size: 0.6rem;
        padding: 0.2rem 0.6rem;
        margin-top: 2px;
    }

    /* Clamp Description (Max 2 lines) */
    .hero-description {
        font-size: 0.85rem;
        line-height: 1.4;
        margin-bottom: 1.25rem;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    /* Compact Stat Cards (Widgets) */
    .stat-card {
        padding: 12px 15px;
        min-height: auto;
    }

    /* Shrink Widget Icons */
    .stat-icon-circle {
        width: 40px;
        height: 40px;
        min-width: 40px;
        margin-right: 12px;
    }
    .stat-icon-circle img {
        width: 20px !important;
        height: 20px !important;
    }

    /* Smaller Numbers */
    .stat-card .display-6 {
        font-size: 1.5rem !important;
        margin-bottom: 0;
    }
    .stat-card small {
        font-size: 0.65rem;
    }

    /* Adjust 'LIVE NOW' Badge */
    .stat-card .badge {
        padding: 0.25em 0.6em !important;
        font-size: 0.6rem !important;
    }
}

        /* --- MOBILE ORGANIZATION: COMPACT 3-COLUMN GRID --- */
        @media (max-width: 767px) {
            
            /* 1. Header: Justify (Left vs Right) */
            .events-header .d-flex {
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
                width: 100%;
            }

            /* 2. Tabs: Organize into a Tighter 3-column Grid */
            .events-nav-tabs {
                display: grid !important;
                /* Change: Create 3 equal columns instead of 2 */
                grid-template-columns: repeat(3, 1fr); 
                gap: 0px;                         /* Slightly tighter gap */
                border-bottom: 1px solid #dee2e6;
                padding-bottom: 15px;
                margin-right: 0 !important;
            }

            /* 3. Tab Links: Compact and Centered */
            .events-nav-tabs .nav-item {
                width: 100%;
                text-align: center;
            }

            .events-nav-tabs .nav-link {
                margin: 0 !important;
                padding: 8px 2px;   /* Reduce side padding to fit text */
                width: 100%;
                justify-content: center;
                font-size: 0.85rem; /* Slightly smaller text to prevent wrapping */
                white-space: nowrap; /* Keep text on one line */
                overflow: hidden;
                text-overflow: ellipsis; /* Add dots (...) if text is too long */
            }
        }
    </style>
</head>
<body>
    
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items: center interactive-brand" href="home.php" style="cursor: pointer;">
                <img src="images/PIT.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain; transition: filter 0.2s;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white brand-heading" style="font-size: 1.25rem; transition: color 0.2s;">PIT SIGLAKAS MEDAL TALLY</strong>
                    <small class="text-light brand-subheading" style="font-size: 0.75rem; transition: color 0.2s;">Official College Tournament System</small>
                </div>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
                aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'home.php') ? 'active' : '' ?>" href="home.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'Eventpage.php') ? 'active' : '' ?>" href="Eventpage.php">Events</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'college_team.php') ? 'active' : '' ?>" href="college_team.php">Teams</a>
                    </li>
                    <li class="nav-item">
                        <?php if (isset($_SESSION['email'])): ?>
                            <a href="logout.php" class="btn btn-danger ms-3">Logout</a>
                        <?php else: ?>
                            <a href="login.php" class="btn btn-success ms-3">Admin Login</a>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="main-content">
        <div class="container">
            <div class="overall-card">

                <div class="hero-section mb-5">
                        <div class="row align-items-center">
                            <div class="col-lg-6 mb-4 mb-lg-0">
                                <div class="hero-title-wrapper">
                                    <div class="hero-icon-circle">
                                        <img src="images/EventpageIcon.png" alt="Events Icon">
                                    </div>
                                    <div class="hero-title-text">
                                        <h1>Siglakas Events</h1>
                                        <span class="hero-subtitle-badge">Live Tournament Hub</span>
                                    </div>
                                </div>
                                <p class="hero-description">
                                    Your centralized hub for the Siglakas Tournament. Track live results, view official schedules, and check the latest medal standings in real-time.
                                </p>
                            </div>

                        <div class="col-lg-6">
                            <div class="row g-3">
                                
                                <div class="col-md-6 col-12"> <div class="stat-card">
                                        <div class="stat-icon-circle stat-theme-total">
                                            <img src="images/EventsIcon.png" alt="Total" style="width: 32px; height: 32px; opacity: 0.8;">
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark display-6 mb-0" id="stat-total" style="line-height: 1;"><?= $total_events ?></div>
                                            <small class="text-uppercase text-muted fw-bold" style="font-size: 0.7rem; letter-spacing: 1px;">Total Events</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6 col-12">
                                    <div class="stat-card">
                                        <div class="stat-icon-circle stat-theme-completed">
                                            <img src="images/completeEvent-ezgif.gif" alt="Completed" style="width: 70px; height: 70px;">
                                        </div>
                                        <div>
                                            <div class="fw-bold text-primary display-6 mb-0" id="stat-completed" style="line-height: 1;"><?= $completed_events ?></div>
                                            <small class="text-uppercase text-primary fw-bold opacity-75" style="font-size: 0.7rem; letter-spacing: 1px;">Completed</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <div class="stat-card">
                                        <div class="stat-icon-circle stat-theme-ongoing">
                                            <img src="images/versus-ezgif.gif" alt="Ongoing" style="width: 70px; height: 70px;">
                                        </div>
                                        <div class="stat-content flex-grow-1">
                                            <div class="d-flex align-items-center justify-content-between">
                                                <div class="fw-bold text-success display-6 mb-0" id="stat-ongoing" style="line-height: 1;"><?= $ongoing_events ?></div>
                                                <span class="badge bg-success text-white px-3 py-2 rounded-pill shadow-sm d-flex align-items-center animation-blink" style="font-size: 0.75rem; letter-spacing: 1px;">
                                                    <i class="fas fa-circle me-2 text-white" style="font-size: 8px;"></i> LIVE NOW
                                                </span>
                                            </div>
                                            <small class="text-uppercase text-success fw-bold opacity-75 mt-1 d-block" style="font-size: 0.7rem; letter-spacing: 1px;">Ongoing Matches</small>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                <div class="events-header mb-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="fw-bold mb-0">
                            <img src="images/EventlistIcon.png" alt="Events Icon" class="me-2" style="width: 2.5rem; height: 2.5rem; object-fit: contain;">
                            Events List
                        </h3>
                        <div class="text-muted small">
                            <i class="fas fa-sync-alt me-1"></i>Auto-refreshing
                        </div>
                    </div>
                </div>
                
                <ul class="nav events-nav-tabs" id="eventsTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-all-games" 
                                data-category-filter="all" type="button">
                            All Games
                        </button>
                    </li>
                    <?php foreach ($event_categories_ordered as $category_name): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-<?= strtolower(preg_replace('/[^a-z0-9]+/', '-', $category_name)) ?>" 
                                    data-category-filter="<?= strtolower(htmlspecialchars($category_name)) ?>" type="button">
                                <?= htmlspecialchars($category_name) ?>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div class="events-filter-bar">
                    <div class="event-search-input">
                        <i class="fas fa-search search-icon"></i>
                        <input type="search" id="event-search-input" class="form-control" placeholder="Search events by name or sport...">
                    </div>
                    
                    <div class="event-filter-status">
                        <select id="filter-status" class="form-select">
                            <option value="all" selected>All Statuses</option>
                            <option value="upcoming">Upcoming</option>
                            <option value="ongoing">Ongoing</option>
                            <option value="postponed">Postponed</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="completed">Completed</option>
                            <option value="pending results">Pending Results</option>
                        </select>
                    </div>
                </div>

                <div id="events-grid-container" class="row g-4">
                    <div class="col-12 text-center p-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading events...</span>
                        </div>
                        <p class="text-muted mt-2">Loading Events...</p>
                    </div>
                </div>

            </div> </div> </div> <div class="modal fade" id="viewEventModal" tabindex="-1" aria-labelledby="viewEventModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <div class="d-flex align-items-center">
                        <span class="iconify me-2 text-white fs-4" data-icon="mdi:trophy-variant"></span>
                        <div>
                            <h5 class="modal-title mb-0" id="modalEventName">Event Details</h5>
                            <small class="opacity-75">Complete Event Information</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-0">
                    
                    <ul class="nav nav-tabs nav-fill border-bottom-0 bg-white px-3 pt-3" 
                        id="eventDetailsTab" role="tablist">
                        
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active rounded-top" id="details-tab" 
                                    data-bs-toggle="tab" data-bs-target="#tab-details" 
                                    type="button" role="tab">
                                <i class="fas fa-info-circle me-2"></i>
                                <span class="d-none d-sm-inline">Game </span>Details
                            </button>
                        </li>
                        
                        <li class="nav-item" role="presentation">
                            <button class="nav-link rounded-top" id="medals-tab" 
                                    data-bs-toggle="tab" data-bs-target="#tab-medals" 
                                    type="button" role="tab">
                                <i class="fas fa-medal me-2"></i>
                                <span class="d-none d-sm-inline">Medal </span>Standings
                            </button>
                        </li>
                        

                        <li class="nav-item" role="presentation">
    <button class="nav-link rounded-top" id="result-tab" 
            data-bs-toggle="tab" data-bs-target="#tab-result" 
            type="button" role="tab">
        <i class="fas fa-trophy me-2"></i>
        Event Result
    </button>
</li>

<li class="nav-item" role="presentation">
    <button class="nav-link rounded-top" id="moment-tab" 
            data-bs-toggle="tab" data-bs-target="#tab-moment" 
            type="button" role="tab">
        <i class="fas fa-camera-retro me-2"></i>
        Winning Moment
    </button>
</li>
                    </ul>

                    <div class="tab-content" id="eventDetailsTabContent">
                        
                        <div class="tab-pane fade show active p-4" id="tab-details" role="tabpanel">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-header bg-primary bg-opacity-10 border-0">
                                        <h6 class="mb-0 text-primary">
                                            <i class="fas fa-info-circle me-2"></i>Game Information
                                        </h6>
                                    </div>
                                    
                                    <div class="card-body">
                                        <div class="row">
                                            
                                            <div class="col-md-6">
                                                <div class="detail-row mb-3">
                                                    <label class="text-muted small mb-1">Game (L1)</label>
                                                    <div class="fw-semibold fs-5" id="viewGameName">Loading...</div>
                                                </div>
                                                <div class="detail-row mb-3">
                                                    <label class="text-muted small mb-1">Event (L2)</label>
                                                    <div class="fw-semibold" id="viewEventName">Loading...</div>
                                                </div>
                                                <div class="detail-row mb-3"> <label class="text-muted small mb-1">Category (L3)</label>
                                                    <div class="fw-semibold" id="viewCategory">Loading...</div>
                                                </div>
                                                <div class="detail-row mb-3 mb-md-0"> <label class="text-muted small mb-1">Status</label>
                                                    <div>
                                                        <span class="badge fs-6 bg-light text-dark" id="viewEventStatus">Loading...</span>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <div class="detail-row mb-3">
                                                    <label class="text-muted small mb-1">Date</label>
                                                    <div class="fw-semibold" id="viewEventDate">Loading...</div>
                                                </div>
                                                <div class="detail-row mb-3">
                                                    <label class="text-muted small mb-1">Time</label>
                                                    <div class="fw-semibold" id="viewEventTime">Loading...</div>
                                                </div>
                                                <div class="detail-row"> <label class="text-muted small mb-1">Venue</label>
                                                    <div class="fw-semibold" id="viewEventVenue">Loading...</div>
                                                </div>
                                            </div>
                                            
                                        </div>
                                    </div>
                                    </div>
                            </div>

                        <div class="tab-pane fade p-4" id="tab-medals" role="tabpanel">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-warning bg-opacity-10 border-0">
                                    <h6 class="mb-0 text-dark">
                                        <i class="fas fa-medal me-2"></i>Approved Medal Standings (All Teams)
                                    </h6>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead>
                                                <tr class="table-light">
                                                    <th class="text-center">RANK</th>
                                                    <th>COLLEGE / TEAM</th>
                                                    <th class="text-center"><i class="fas fa-medal gold-medal"></i> GOLD</th>
                                                    <th class="text-center"><i class="fas fa-medal silver-medal"></i> SILVER</th>
                                                    <th class="text-center"><i class="fas fa-medal bronze-medal"></i> BRONZE</th>
                                                    <th class="text-center">TOTAL</th>
                                                </tr>
                                            </thead>
                                            <tbody id="modalMedalStandingsBody">
                                                <tr><td colspan="6" class="text-center text-muted py-4">Loading...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>


                        <div class="tab-pane fade p-0" id="tab-result" role="tabpanel">
    <div class="card border-0">
        <div class="card-header bg-warning bg-opacity-10 border-0">
            <h6 class="mb-0 text-dark">
                <i class="fas fa-award me-2"></i>Official Medal Winners
            </h6>
        </div>
        <div class="card-body">
            <div id="medal-winners-body">
                <div class="text-center p-5 text-muted">Loading...</div>
            </div>
        </div>
    </div>
</div>

<div class="tab-pane fade p-0" id="tab-moment" role="tabpanel">
    <div class="card border-0">
        <div class="card-header bg-success bg-opacity-10 border-0">
            <h6 class="mb-0 text-dark">
                <i class="fas fa-image me-2"></i>Captured Moment
            </h6>
        </div>
        <div class="card-body text-center p-4">
            
            <div id="podiumPhotoContainer" class="d-none">
                <div class="winning-photo-frame mx-auto">
                    <img id="podiumPhotoImg" src="" alt="Winning Moment" title="Click to view full size">
                </div>
                <div class="small text-muted mt-2">
                    <i class="fas fa-search-plus me-1"></i>Click image to expand
                </div>
            </div>

            <div id="noPhotoMessage" class="py-5 text-muted" style="display:none;">
                <i class="fas fa-camera-slash mb-2 fs-3 opacity-50"></i><br>
                No winning moment photo available for this event.
            </div>

        </div>
    </div>
</div>

                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-transparent border-0">
                <div class="modal-body p-0 text-center position-relative">
                    <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" 
                            data-bs-dismiss="modal" aria-label="Close" 
                            style="background-color: rgba(0,0,0,0.5); padding: 1rem; border-radius: 50%; z-index: 1051;">
                    </button>
                    <img id="previewImageFull" src="" class="img-fluid rounded shadow-lg" 
                         style="max-height: 90vh; border: 2px solid rgba(255,255,255,0.2);">
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
                        <img src="images/COTE.png" alt="Logo">
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


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.iconify.design/2/2.2.1/iconify.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // --- NEW: LIGHTBOX LOGIC ---
            const podiumImg = document.getElementById('podiumPhotoImg');
            const previewModalEl = document.getElementById('imagePreviewModal');
            const previewImageFull = document.getElementById('previewImageFull');
            // Initialize the Bootstrap modal
            const imagePreviewModal = new bootstrap.Modal(previewModalEl);

            if (podiumImg) {
                podiumImg.addEventListener('click', function() {
                    // Only open if there is actually an image source
                    if (this.src && this.src !== '' && !this.src.endsWith('html')) {
                        previewImageFull.src = this.src;
                        imagePreviewModal.show();
                    }
                });
                
                // Add a hover zoom effect via JS (Optional, or handled by CSS)
                podiumImg.addEventListener('mouseenter', () => podiumImg.style.transform = 'scale(1.02)');
                podiumImg.addEventListener('mouseleave', () => podiumImg.style.transform = 'scale(1)');
            }
            
            // 1. ADJUST PADDING FOR FIXED NAVBAR
            const navbarHeight = document.querySelector('.navbar').offsetHeight;
            document.querySelector('.main-content').style.paddingTop = `${navbarHeight + 30}px`;
            
            // 2. INTERACTIVE BRAND CLICK
            document.querySelector('.interactive-brand').addEventListener('click', function(e) {
                e.preventDefault();
                window.location.href = 'home.php'; 
            });

            // 3. DOM ELEMENTS
            const eventsGridContainer = document.getElementById('events-grid-container');
            const eventSearchInput = document.getElementById('event-search-input');
            const filterStatusSelect = document.getElementById('filter-status');
            const eventsTab = document.getElementById('eventsTab');

            // 4. INITIAL DATA (Passed from PHP)
            const initialEventsData = <?php echo json_encode($events); ?>;
            
            // 5. FILTER STATE
            let currentStatusFilter = 'all';
            let currentCategoryFilter = 'all'; 
            let currentSearchValue = '';
            
            // 6. AUTO-REFRESH VARIABLES
            let autoRefreshInterval = null;
            let currentEventIdForRefresh = null;

            /**
             * BUILD EVENT CARD HTML
             * Corrects hierarchy:
             * - Title: Event Name (e.g., Basketball)
             * - Badge: Category Name (e.g., Single Division)
             * - Subtitle: Game Name (e.g., Ball Games)
             */
            function buildEventHtml(event) {
                const statusClass = getEventCardStatusClassJS(event.event_status || '');
                
                // Logic: The API returns 'category' as the Event Name (L2) and 'event_name' as Category (L3)
                // We map them correctly for display here:
                const mainTitle = String(event.category || 'Untitled'); // e.g., "Basketball"
                const subCategory = String(event.event_name || 'General'); // e.g., "Men's Division"
                const sportName = String(event.sport_name || 'Unknown Sport'); // e.g., "Ball Games"
                // 1. Get Data
                const eventDate = formatDate(event.event_date); // Uses your existing helper
                const eventTime = formatTime(event.event_time); // Uses your existing helper
                const venue = event.venue || 'Venue TBA';
                
                const originalStatus = event.event_status || 'Unknown';
                const displayStatus = formatStatusTextForCard(originalStatus);
                
                const managerNameHtml = event.manager_name ? 
                    `<div class="small text-muted mt-2 border-top pt-2"><i class="fas fa-user-tie me-1"></i>Manager: ${escapeHtml(event.manager_name)}</div>` : '';
                
                // Get the icon using our new helper
                const iconClass = getEventIconJS(sportName, mainTitle);

                return `
                    <div class="col-md-4 col-sm-6 mb-4 event-card-item" 
                        data-category="${(event.sport_name||'others').toLowerCase()}" 
                        data-status="${(originalStatus).toLowerCase()}" 
                        data-search-text="${escapeHtml(mainTitle.toLowerCase())} ${escapeHtml(sportName.toLowerCase())}">
                        
                        <div class="sport-card h-100">
                            <div class="sport-card-body d-flex flex-column">
                                
                                <div class="d-flex align-items-start mb-3">
                                    <div class="me-3 flex-shrink-0 d-flex align-items-center justify-content-center bg-success bg-opacity-10 rounded-circle text-success shadow-sm" 
                                        style="width: 50px; height: 50px;">
                                        <i class="${iconClass} fa-lg"></i>
                                    </div>

                                    <div class="flex-grow-1" style="min-width: 0;">
                                        
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="text-uppercase text-primary fw-bold small" style="font-size: 0.7rem; letter-spacing: 0.5px;">
                                                ${escapeHtml(sportName)}
                                            </span>
                                            <span class="status-pill ${statusClass}" style="transform: scale(0.85); transform-origin: right center;">
                                                ${escapeHtml(displayStatus)}
                                            </span>
                                        </div>

                                        <h5 class="event-title text-dark">
                                            ${escapeHtml(mainTitle)}
                                        </h5>
                                    </div>
                                </div>
                         
                                <div class="mb-3 ps-1">
                                    <div class="d-flex align-items-center text-dark fw-bolder text-uppercase" style="font-size: 0.85rem; letter-spacing: 0.5px;">
                                        <i class="fas fa-tag me-2 text-primary opacity-75"></i>
                                        <span>${escapeHtml(subCategory)}</span>
                                    </div>
                                </div>

                                <div class="mb-3 ps-1 flex-grow-1">
    
                                    <div class="d-flex align-items-center mb-2">
                                        <div class="d-flex align-items-center justify-content-center bg-light rounded-circle me-2" style="width: 28px; height: 28px;">
                                            <i class="fas fa-calendar-alt text-secondary" style="font-size: 0.8rem;"></i>
                                        </div>
                                        <span class="text-dark small fw-medium">
                                            ${eventDate} <span class="mx-1 text-muted">•</span> ${eventTime}
                                        </span>
                                    </div>

                                    <div class="d-flex align-items-center">
                                        <div class="d-flex align-items-center justify-content-center bg-light rounded-circle me-2" style="width: 28px; height: 28px;">
                                            <i class="fas fa-map-marker-alt text-danger" style="font-size: 0.8rem;"></i>
                                        </div>
                                        <span class="text-dark small fw-medium">
                                            ${escapeHtml(venue)}
                                        </span>
                                    </div>

                                </div>
                                ${managerNameHtml}
                            </div>

                            <div class="d-flex justify-content-end align-items-center mt-3 pt-3 border-top">
                                <a href="#" class="check-event btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold" 
                                data-bs-toggle="modal" data-bs-target="#viewEventModal" 
                                data-id="${event.event_id}" 
                                data-category-type="${event.category_type || 'medal'}"
                                data-status="${(originalStatus).toLowerCase()}">
                                    View Details <i class="fas fa-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>`;
                        }

            function renderEvents(events) {
                if (!eventsGridContainer) return;
                let html = (events && events.length > 0) 
                    ? events.map(buildEventHtml).join('') 
                    : '<div class="col-12"><p class="text-center text-muted py-5">No events found.</p></div>';
                eventsGridContainer.innerHTML = html;
            }
            
            function applyAllFilters() {
                if (!eventsGridContainer) return;
                const cards = eventsGridContainer.querySelectorAll('.event-card-item');
                let hasVisibleEvents = false;
                
                cards.forEach(card => {
                    const ccat = card.dataset.category;
                    const cstatus = card.dataset.status; 
                    const ctext = card.dataset.searchText;
                    
                    const showCategory = (currentCategoryFilter === 'all' || ccat === currentCategoryFilter);
                    
                    let showStatus = false;
                    switch (currentStatusFilter) {
                        case 'all': showStatus = true; break;
                        case 'completed': showStatus = (cstatus === 'completed' || cstatus === 'results approved'); break;
                        case 'pending results': showStatus = (cstatus === 'completed (pending results)' || cstatus === 'results submitted'); break;
                        default: showStatus = (cstatus === currentStatusFilter); break;
                    }
                    
                    const showSearch = (currentSearchValue === '' || ctext.includes(currentSearchValue));
                    
                    if (showCategory && showStatus && showSearch) {
                        card.style.display = 'block';
                        hasVisibleEvents = true;
                    } else {
                        card.style.display = 'none';
                    }
                });
            }
            
            // --- INITIALIZE PAGE ---
            renderEvents(initialEventsData);
            applyAllFilters();
            
            // --- EVENT LISTENERS ---
            if (eventsTab) {
                const tabs = eventsTab.querySelectorAll('.nav-link');
                tabs.forEach(tab => {
                    tab.addEventListener('click', function(e) {
                        e.preventDefault();
                        tabs.forEach(t => t.classList.remove('active'));
                        this.classList.add('active');
                        currentCategoryFilter = this.dataset.categoryFilter;
                        applyAllFilters();
                    });
                });
            }

            if (filterStatusSelect) {
                filterStatusSelect.addEventListener('change', function() {
                    currentStatusFilter = this.value;
                    applyAllFilters();
                });
            }
            
            if (eventSearchInput) {
                eventSearchInput.addEventListener('input', function() {
                    currentSearchValue = this.value.toLowerCase().trim();
                    applyAllFilters();
                });
            }

            // --- MODAL LOGIC ---
            const viewEventModalElement = document.getElementById('viewEventModal');
            const viewEventModal = new bootstrap.Modal(viewEventModalElement);

            const modalEventNameSpan = document.getElementById('modalEventName');
            const modalMedalStandingsBody = document.getElementById('modalMedalStandingsBody');
            const medalWinnersBody = document.getElementById('medal-winners-body');

            document.body.addEventListener('click', function(e) {
                if (e.target.closest('.check-event')) {
                    e.preventDefault(); 
                    const button = e.target.closest('.check-event');
                    const eventId = button.dataset.id;
                    const categoryType = button.dataset.categoryType || 'medal'; 
                    const status = button.dataset.status;

                    currentEventIdForRefresh = eventId;


                    // 2. Smart Tab Activation
                    let targetTabId = 'details-tab';
                    if (status === 'completed' || status === 'completed (pending results)' || status === 'results submitted' || status === 'results approved') {
                        targetTabId = 'result-tab'; // Show Podium for completed events
                    }
                    
                    const tabToActivate = document.getElementById(targetTabId);
                    if (tabToActivate) new bootstrap.Tab(tabToActivate).show();

                    // 3. Reset Loading States
                    if(modalMedalStandingsBody) modalMedalStandingsBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Loading...</td></tr>';
                    if (medalWinnersBody) medalWinnersBody.innerHTML = '<div class="text-center p-5 text-muted">Loading...</div>';
                    
                    // 4. Load Data
                    loadAndRender(); 
                    if (autoRefreshInterval) clearInterval(autoRefreshInterval);
                    autoRefreshInterval = setInterval(loadAndRender, 15000); 

                    fetchMedalWinnerResults(eventId); 
                }
            });
            
            viewEventModalElement.addEventListener('hidden.bs.modal', function () {
                if (autoRefreshInterval) clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
                currentEventIdForRefresh = null;
            });
            
            /**
             * LOAD AND RENDER FUNCTION
             * Fetches data from API and populates the modal fields.
             */
            function loadAndRender() {
                if (!currentEventIdForRefresh) return;
                const eventId = currentEventIdForRefresh;
                
                fetch(`fetch_event_details_data.php?id=${eventId}&_=${Date.now()}`)
                    .then(response => response.json())
                    .then(response => { 
                        if (!response.success) throw new Error(response.message || 'Failed to load details');
                        
                        const data = response.data; 

                        // 1. Populate Details Tab
                        document.getElementById('modalEventName').textContent = data.event_details.event_name || 'Event Details';
                        document.getElementById('viewGameName').textContent = data.event_details.sport_name || 'N/A';
                        document.getElementById('viewEventName').textContent = data.event_details.category || 'N/A';
                        document.getElementById('viewCategory').textContent = data.event_details.event_name || 'N/A';
                        
                        document.getElementById('viewEventDate').textContent = formatDate(data.event_details.event_date);
                        document.getElementById('viewEventTime').textContent = formatTime(data.event_details.event_time);
                        document.getElementById('viewEventVenue').textContent = data.event_details.venue || 'TBA';
                        
                        const statusBadge = document.getElementById('viewEventStatus');
                        statusBadge.textContent = data.event_details.event_status || 'Unknown';
                        statusBadge.className = `badge fs-6 ${getEventCardStatusClassJS(data.event_details.event_status)}`;

                        // 2. Populate Standings Tab
                        populateMedalStandings(data.medal_standings);

                        // 3. Handle Podium Photo
                        const photoContainer = document.getElementById('podiumPhotoContainer');
                        const photoImg = document.getElementById('podiumPhotoImg');
                        
                        if (data.event_details.podium_photo_url) {
                            // Clean path (remove '../' if present)
                            let cleanUrl = data.event_details.podium_photo_url.replace('../', '');
                            photoImg.src = cleanUrl;
                            photoContainer.classList.remove('d-none');
                        } else {
                            photoContainer.classList.add('d-none');
                            photoImg.src = '';
                        }

                    })
                    .catch(error => {
                        console.error('Error loading event details:', error);
                        const standingsBody = document.getElementById('modalMedalStandingsBody');
                        if (standingsBody) standingsBody.innerHTML = `<tr><td colspan="6" class="text-center text-danger py-4">Error loading data.</td></tr>`;
                        
                        ['viewGameName', 'viewEventName', 'viewCategory'].forEach(id => {
                            const el = document.getElementById(id);
                            if(el) el.textContent = 'Error';
                        });
                    });
            }

            // Helper: Status Class
            // Helper: Get Status Class (Updated for Visibility)
            function getEventCardStatusClassJS(status) {
                if (!status) return 'bg-secondary'; 
                const lowerStatus = status.toLowerCase();
                
                if (lowerStatus === 'ongoing') {
                    return 'status-ongoing'; // Custom Green Pulse
                }
                if (lowerStatus === 'completed' || lowerStatus === 'results approved') {
                    return 'status-completed'; // Solid Blue
                }
                if (lowerStatus.includes('pending') || lowerStatus.includes('submitted')) {
                    return 'status-pending'; // Bright Orange (Was Yellow)
                }
                if (lowerStatus === 'upcoming') {
                    return 'status-upcoming'; // Teal
                }
                if (lowerStatus === 'cancelled' || lowerStatus === 'results rejected') {
                    return 'status-cancelled'; // Red
                }
                
                return 'bg-secondary'; // Grey fallback
            }

            // Helper: Status Text
            function formatStatusTextForCard(status) {
                if (!status) return 'Unknown';
                const lowerStatus = status.toLowerCase();
                if (lowerStatus.includes('pending') || lowerStatus.includes('submitted')) return 'Pending Results';
                if (lowerStatus === 'results approved') return 'Completed'; 
                return status; 
            }
            
            // Helper: Populate Medal Table
            function populateMedalStandings(standingsData) {
                const container = document.getElementById('modalMedalStandingsBody');
                if (!container) return;

                let html = '';
                if (!standingsData || standingsData.length === 0) {
                    html = `<tr><td colspan="6" class="text-center text-muted py-4">No approved medal standings for this event.</td></tr>`;
                } else {
                    standingsData.sort((a, b) => {
                        if (a.rank && b.rank) return a.rank - b.rank;
                        // Sorting logic: Gold > Silver > Bronze
                        const ag = parseInt(a.gold) || 0, bg = parseInt(b.gold) || 0;
                        if (bg !== ag) return bg - ag;
                        const as = parseInt(a.silver) || 0, bs = parseInt(b.silver) || 0;
                        if (bs !== as) return bs - as;
                        const ab = parseInt(a.bronze) || 0, bb = parseInt(b.bronze) || 0;
                        return bb - ab;
                    });

                    standingsData.forEach((standing, index) => {
                        html += `
                            <tr>
                                <td class="text-center fw-bold">${standing.rank || (index + 1)}</td>
                                <td>${escapeHtml(standing.team_name)} (${escapeHtml(standing.college || 'N/A')})</td>
                                <td class="text-center">${escapeHtml(String(standing.gold))}</td>
                                <td class="text-center">${escapeHtml(String(standing.silver))}</td>
                                <td class="text-center">${escapeHtml(String(standing.bronze))}</td>
                                <td class="text-center fw-bold">${escapeHtml(String(standing.total))}</td>
                            </tr>`;
                    });
                }
                container.innerHTML = html;
            }
            
            // Helper: Fetch Podium Data (Winners Tab)
            function fetchMedalWinnerResults(categoryId) {
                if (!medalWinnersBody) return;
                medalWinnersBody.innerHTML = `<div class="text-center p-5 text-muted"><div class="spinner-border spinner-border-sm" role="status"></div><span class="ms-2">Loading Winners...</span></div>`;
                
                fetch(`event_manager_api.php?action=get_medal_results&category_id=${categoryId}`)
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            const data = result.data;
                            if (!data.gold && !data.silver && !data.bronze) {
                                medalWinnersBody.innerHTML = '<div class="text-center p-5 text-muted">No medal winners have been submitted yet.</div>';
                                return;
                            }
                            // Default logo if missing
                            const defaultLogo = 'images/default_avatar.png'; 

                            // Render Podium
                            let html = '<div class="podium-container">';
                            
                            // SILVER (Left)
                            html += `
                            <div class="podium-wrapper silver">
                                <img src="${escapeHtml(data.silver_logo || defaultLogo)}" class="podium-logo" onerror="this.src='${defaultLogo}'">
                                <div class="podium-winner-name">${escapeHtml(data.silver || 'N/A')}</div>
                                <div class="podium-step silver">
                                    <i class="fas fa-medal podium-medal"></i>
                                    <div class="podium-medal-count">${escapeHtml(data.silver_count || '0')}</div>
                                    <div class="podium-label">Medals</div>
                                    <div class="rank-badge-podium">2</div>
                                </div>
                            </div>`;
                            
                            // GOLD (Center)
                            html += `
                            <div class="podium-wrapper gold">
                                <img src="${escapeHtml(data.gold_logo || defaultLogo)}" class="podium-logo" onerror="this.src='${defaultLogo}'">
                                <div class="podium-winner-name">${escapeHtml(data.gold || 'N/A')}</div>
                                <div class="podium-step gold">
                                    <i class="fas fa-trophy podium-medal mb-2"></i>
                                    <div class="podium-medal-count">${escapeHtml(data.gold_count || '0')}</div>
                                    <div class="podium-label">Medals</div>
                                    <div class="rank-badge-podium">1</div>
                                </div>
                            </div>`;
                            
                            // BRONZE (Right)
                            html += `
                            <div class="podium-wrapper bronze">
                                <img src="${escapeHtml(data.bronze_logo || defaultLogo)}" class="podium-logo" onerror="this.src='${defaultLogo}'">
                                <div class="podium-winner-name">${escapeHtml(data.bronze || 'N/A')}</div>
                                <div class="podium-step bronze">
                                    <i class="fas fa-medal podium-medal"></i>
                                    <div class="podium-medal-count">${escapeHtml(data.bronze_count || '0')}</div>
                                    <div class="podium-label">Medals</div>
                                    <div class="rank-badge-podium">3</div>
                                </div>
                            </div>`;
                            
                            html += '</div>';
                            medalWinnersBody.innerHTML = html;
                        } else {
                            medalWinnersBody.innerHTML = `<div class="alert alert-warning m-3">${escapeHtml(result.message || 'No results found.')}</div>`;
                        }
                    })
                    .catch(error => {
                        console.error('Fetch Medal Winners error:', error);
                        medalWinnersBody.innerHTML = '<div class="alert alert-danger m-3">Could not load winner results.</div>';
                    });
            }

            function formatDate(dateString) {
                if (!dateString || dateString === '0000-00-00' || dateString === 'TBA') return 'TBA';
                try {
                    const options = { year: 'numeric', month: 'long', day: 'numeric' };
                    const date = new Date(dateString);
                    if (isNaN(date.getTime())) return dateString; 
                    date.setTime(date.getTime() + date.getTimezoneOffset() * 60000); 
                    return date.toLocaleDateString('en-US', options);
                } catch (e) { return dateString; }
            }
            
            function formatTime(timeString) {
                if (!timeString || timeString === '00:00:00') return 'TBA';
                try {
                    const [hours, minutes] = timeString.split(':');
                    const date = new Date();
                    date.setHours(hours, minutes, 0);
                    if (isNaN(date.getTime())) return timeString; 
                    return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
                } catch (e) { return timeString; }
            }

            function escapeHtml(text) {
                if (text === null || typeof text === 'undefined') return '';
                const strText = String(text); 
                const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
                return strText.replace(/[&<>"']/g, (m) => map[m]);
            }

            // --- REAL-TIME UPDATES ENGINE ---
            function startRealTimeUpdates() {
                setInterval(() => {
                    // 1. Fetch latest data from the API you created
                    fetch('fetch_all_events_api.php')
                        .then(response => response.json())
                        .then(newEventsData => {
                            
                            // 2. Update the Grid
                            // We re-use your existing render function!
                            renderEvents(newEventsData);
                            
                            // 3. Re-apply user's filters
                            // (So if they searched for "Basketball", it stays filtered!)
                            applyAllFilters();
                            
                            // 4. Update the Top Counter Stats
                            updateStatsCounters(newEventsData);
                        })
                        .catch(err => console.error('Auto-refresh error:', err));
                }, 5000); // Runs every 5 seconds
            }

            function updateStatsCounters(events) {
                let total = events.length;
                let completed = 0;
                let ongoing = 0;

                events.forEach(e => {
                    const s = (e.event_status || '').toLowerCase();
                    if (s.includes('completed') || s.includes('approved')) {
                        completed++;
                    } else if (s === 'ongoing') {
                        ongoing++;
                    }
                });

                // Update the HTML numbers safely
                const totalEl = document.getElementById('stat-total');
                const compEl = document.getElementById('stat-completed');
                const onEl = document.getElementById('stat-ongoing');

                if (totalEl) totalEl.textContent = total;
                if (compEl) compEl.textContent = completed;
                if (onEl) onEl.textContent = ongoing;
            }

            // START THE ENGINE!
            startRealTimeUpdates();

            // --- 1. NEW HELPER: GET ICON CLASS (Matches Director Panel) ---
            function getEventIconJS(gameName, eventName) {
                // Combine text to search safely
                const text = (String(gameName) + ' ' + String(eventName)).toLowerCase();
                
                if (text.includes('athletics') || text.includes('run')) return 'fas fa-running';
                if (text.includes('ball') || text.includes('takraw')) return 'fas fa-basketball-ball';
                if (text.includes('swim')) return 'fas fa-swimmer';
                if (text.includes('racket') || text.includes('badminton') || text.includes('tennis')) return 'fas fa-table-tennis-paddle-ball';
                if (text.includes('chess') || text.includes('scrabble') || text.includes('word') || text.includes('board')) return 'fas fa-puzzle-piece';
                if (text.includes('e-sports') || text.includes('mobile') || text.includes('game') || text.includes('valorant')) return 'fas fa-gamepad';
                if (text.includes('dance') || text.includes('vocal') || text.includes('sing') || text.includes('cultural')) return 'fas fa-music';
                
                return 'fas fa-trophy'; // Default fallback
            }
                    
                }); 
    </script>
</body>
</html>