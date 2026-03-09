<?php
session_start();
// Use the same config file as your home.php
require_once 'config.php'; 

// --- Set Page Variable for Navbar ---
$current_page = 'college_team.php'; 
$default_logo = 'images/default_avatar.png'; 

// --- 1. FETCH ALL TEAM DATA + MEDAL STATS (UPDATED QUERY) ---
$colleges_data = [];

$sql = "
    SELECT
        C.college_id,
        C.college_name,
        C.college_code,
        C.team_manager,
        C.slogan,
        C.logo_url,
        C.unit_color,
        COALESCE(Medals.GoldCount, 0) AS GoldCount,
        COALESCE(Medals.SilverCount, 0) AS SilverCount,
        COALESCE(Medals.BronzeCount, 0) AS BronzeCount,
        (COALESCE(Medals.GoldCount, 0) + COALESCE(Medals.SilverCount, 0) + COALESCE(Medals.BronzeCount, 0)) AS TotalMedals
    FROM
        colleges C
    LEFT JOIN (
        SELECT
            college_id,
            SUM(GoldCount) AS GoldCount,
            SUM(SilverCount) AS SilverCount,
            SUM(BronzeCount) AS BronzeCount
        FROM (
            SELECT gold_winner_college_id AS college_id, SUM(gold_count) AS GoldCount, 0 AS SilverCount, 0 AS BronzeCount FROM categories WHERE status = 'Results Approved' AND gold_winner_college_id IS NOT NULL GROUP BY gold_winner_college_id
            UNION ALL
            SELECT silver_winner_college_id AS college_id, 0 AS GoldCount, SUM(silver_count) AS SilverCount, 0 AS BronzeCount FROM categories WHERE status = 'Results Approved' AND silver_winner_college_id IS NOT NULL GROUP BY silver_winner_college_id
            UNION ALL
            SELECT bronze_winner_college_id AS college_id, 0 AS GoldCount, 0 AS SilverCount, SUM(bronze_count) AS BronzeCount FROM categories WHERE status = 'Results Approved' AND bronze_winner_college_id IS NOT NULL GROUP BY bronze_winner_college_id
        ) AS MedalCounts
        WHERE college_id IS NOT NULL
        GROUP BY college_id
    ) AS Medals ON C.college_id = Medals.college_id
    ORDER BY
        GoldCount DESC, SilverCount DESC, BronzeCount DESC, C.college_name ASC;
";

$result = $conn->query($sql);
if ($result) {
    $colleges_data = $result->fetch_all(MYSQLI_ASSOC);
}
$conn->close();

// --- 2. CALCULATE STATS ---
$total_teams = count($colleges_data);
$total_medals = 0;
$topPerformersCount = 0;

foreach ($colleges_data as $college) {
    $total_medals += $college['TotalMedals'];
    if ($college['GoldCount'] > 0) {
        $topPerformersCount++;
    }
}

function truncate_text($text, $length = 100, $suffix = '...') {
    if (strlen($text) > $length) {
        $text = substr($text, 0, $length);
        $text = rtrim($text, " .,;"); 
        $text = substr($text, 0, strrpos($text, ' '));
        $text .= $suffix;
    }
    return $text;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Participating Teams - PIT Sports Tallying</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
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
        }
        /* NAVBAR */
        .navbar { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.15); padding: 1rem 0; }
        .navbar-brand { transition: var(--transition); }
        .navbar-brand:hover { transform: translateY(-2px); }
        .brand-logo { filter: drop-shadow(0 2px 4px rgba(255,255,255,0.1)); }
        .brand-heading { font-family: 'Poppins', sans-serif; font-weight: 700; }
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
        
        /* BUTTONS */
        .btn-danger, .btn-success { padding: 0.6rem 1.5rem; border-radius: 10px; font-weight: 600; transition: var(--transition); border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .btn-danger:hover, .btn-success:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.2); }
        
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
        /* CARDS */
        /* --- NEW HERO & WIDGET STYLES (Matches Events Page) --- */
.hero-section {
    background: linear-gradient(135deg, #ffffff 0%, #f8fcf9 100%);
    border: 1px solid rgba(0,0,0,0.04);
    /* Blue Accent Line for Teams Page */
    border-left: 5px solid #0d6efd; 
    border-radius: 16px;
    padding: 40px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.03);
    margin-bottom: 2.5rem;
}

.stat-card-widget {
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

.stat-card-widget::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: linear-gradient(180deg, #0d6efd 0%, #0a58ca 100%);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.stat-card-widget:hover {
    transform: translateY(-8px);
    box-shadow: 0 12px 32px rgba(0,0,0,0.1);
    border-color: rgba(13, 110, 253, 0.3);
}

.stat-card-widget:hover::before {
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
    font-size: 1.75rem;
}

.stat-card-widget:hover .stat-icon-circle {
    transform: scale(1.1) rotate(5deg);
}

/* Theme Colors */
.stat-theme-teams { 
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    color: #0d6efd;
}

.stat-theme-performers { 
    background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    color: #198754;
}

.stat-theme-medals { 
    background: linear-gradient(135deg, #fff9e6 0%, #ffe69c 100%);
    color: #ffc107;
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

/* Gold Official Tally Badge */
.live-badge-wrapper-gold {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: linear-gradient(135deg, #ffc107 0%, #ffb300 100%);
    padding: 0.5rem 1rem;
    border-radius: 25px;
    box-shadow: 0 4px 16px rgba(255, 193, 7, 0.4);
    color: #000;
}

/* Theme Colors */
.stat-theme-teams { background: rgba(13, 110, 253, 0.1); color: #0d6efd; }       /* Blue */
.stat-theme-performers { background: rgba(25, 135, 84, 0.1); color: #198754; }   /* Green */
.stat-theme-medals { background: rgba(255, 193, 7, 0.1); color: #ffc107; }       /* Gold */

/* Hero Title Components */
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
    background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 20px rgba(13, 110, 253, 0.25);
    transform: rotate(-5deg);
    transition: transform 0.3s ease;
    color: white;
    font-size: 2rem;
}

.hero-icon-circle:hover {
    transform: rotate(0deg) scale(1.05);
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
    background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
    color: white;
    padding: 0.4rem 1rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    box-shadow: 0 4px 12px rgba(13, 110, 253, 0.3);
}

.hero-description {
    font-size: 1.05rem;
    line-height: 1.8;
    color: #5a6c7d;
    margin: 0;
    max-width: 600px;
}

.hero-icon-circle img {
    width: 45px;
    height: 45px;
    object-fit: contain;
    /* Removed filter to preserve original icon colors */
}
        
        /* COLLEGE CARD */
        .college-card { background-color: var(--bg-light); border-radius: 12px; box-shadow: var(--shadow-sm); transition: var(--transition); height: 100%; padding: 24px; position: relative; overflow: hidden; border: 1px solid rgba(0,0,0,0.05); display: flex; flex-direction: column; --team-color: var(--primary-green); }
        .college-card:hover { transform: translateY(-5px); box-shadow: var(--shadow-lg); background-color: #fff; }
        .college-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 6px; background-color: var(--team-color); transition: var(--transition); }
        .college-card:hover::before { height: 8px; }
        .college-logo { width: 100px; height: 100px; margin: 0 auto 10px; display: block; object-fit: cover; border-radius: 50%; padding: 5px; background: #e9ecef; border: 3px solid #e9ecef; }
        .college-card:hover .college-logo { border-color: var(--team-color); }
        .college-badge { font-size: 0.75rem; font-weight: bold; padding: 4px 10px; border-radius: 50rem; display: inline-block; margin-bottom: 8px; background-color: #e9ecef; color: #495057; }
        .college-name { font-family: 'Poppins', sans-serif; font-weight: 700; font-size: 1.35rem; color: #2c3e50; }
        .college-name-link { text-decoration: none; color: inherit; }
        .college-name-link:hover { color: var(--team-color); }
        .truncate-text { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; font-size: 0.95rem; color: var(--text-dark); flex-grow: 1; }
        .btn-full-width { width: 100%; }
        .filter-bar {
    background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
    padding: 1.75rem;
    border-radius: 16px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.04);
    margin-bottom: 2rem;
    border: 1px solid rgba(0,0,0,0.06);
}

.filter-bar label {
    font-size: 0.875rem;
    color: #495057;
    font-weight: 600;
}

.filter-bar .input-group {
    border-radius: 10px;
    overflow: hidden;
    border: 2px solid #e9ecef;
    transition: all 0.3s ease;
}

.filter-bar .input-group:focus-within {
    border-color: #0d6efd;
    box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1) !important;
}

.filter-bar .form-control,
.filter-bar .form-select {
    border: 2px solid #e9ecef;
    border-radius: 10px;
    padding: 0.75rem 1rem;
    transition: all 0.3s ease;
    font-size: 0.95rem;
}

.filter-bar .form-control:focus,
.filter-bar .form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
}
        
        /* MODAL & TABS */
        .modal-header { border-bottom: 1px solid #dee2e6; }
        .modal-footer { border-top: 1px solid #dee2e6; }
        .modal-logo { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; box-shadow: var(--shadow-sm); }
        .modal-title { font-family: 'Poppins', sans-serif; font-weight: 600; }
        #collegeTab .nav-link { font-family: 'Poppins', sans-serif; font-weight: 500; color: var(--text-dark) !important; border: none; border-bottom: 3px solid transparent; }
        #collegeTab .nav-link.active { color: var(--primary-green) !important; border-bottom-color: var(--primary-green); background-color: transparent; }
        .tab-content { padding-top: 1.5rem; }
        .tab-pane { min-height: 200px; }
        .loading-spinner { display: flex; align-items: center; justify-content: center; min-height: 200px; font-size: 1.2rem; color: var(--text-muted); }
        
        /* DETAILS */
        .details-card { border-radius: 12px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05); background-color: var(--bg-light); padding: 20px; height: 100%; }
        .details-title { font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 1.1rem; color: #2c3e50; margin-bottom: 15px; border-bottom: 1px dashed #e9ecef; padding-bottom: 8px; }
        .medal-badge { font-size: 2.5rem; line-height: 1; }

        /* CUSTOM BRONZE */
        .bg-bronze { background-color: #CD7F32 !important; }
        .border-bronze { border-color: #A0522D !important; }
        .text-bronze { color: #cd7f32 !important; }

        /* GALLERY & LIGHTBOX */
        .gallery-card { transition: transform 0.3s ease; border-radius: 12px; overflow: hidden; border: 0; box-shadow: 0 4px 10px rgba(0,0,0,0.05); cursor: pointer; }
        .gallery-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .gallery-img-wrapper { position: relative; height: 200px; overflow: hidden; }
        .gallery-img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s ease; }
        .gallery-card:hover .gallery-img { transform: scale(1.05); }
        .gallery-badge { position: absolute; top: 10px; right: 10px; background: rgba(255, 215, 0, 0.9); color: #000; font-weight: 700; padding: 5px 10px; border-radius: 50px; font-size: 0.8rem; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        
        /* LIGHTBOX OVERLAY */
        #lightboxOverlay {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.9);
            backdrop-filter: blur(5px);
        }
        .lightbox-content {
            margin: auto;
            display: block;
            max-width: 90%;
            max-height: 80vh;
            animation: zoom 0.3s;
        }
        .lightbox-caption {
            margin: auto;
            display: block;
            width: 80%;
            max-width: 700px;
            text-align: center;
            color: #ccc;
            padding: 10px 0;
            height: 150px;
        }
        .close-lightbox {
            position: absolute;
            top: 20px;
            right: 35px;
            color: #f1f1f1;
            font-size: 40px;
            font-weight: bold;
            transition: 0.3s;
            cursor: pointer;
        }
        .close-lightbox:hover, .close-lightbox:focus { color: #bbb; text-decoration: none; cursor: pointer; }
        @keyframes zoom { from {transform:scale(0)} to {transform:scale(1)} }
        /* PODIUM STYLE CARDS */
        .college-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 0;
            border-top: 5px solid var(--team-color); /* Default strip */
        }



        /* Rank Badge on Card */
        .rank-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            font-weight: 800;
            font-size: 0.85rem;
            padding: 5px 10px;
            border-radius: 50px;
            background: #f8f9fa;
            color: #6c757d;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .rank-1-card .rank-badge { background: #ffd700; color: #000; }
        .rank-2-card .rank-badge { background: #c0c0c0; color: #fff; }
        .rank-3-card .rank-badge { background: #cd7f32; color: #fff; }

        /* Mini Medal Counter on Card */
        .medal-mini-stat {
            display: flex;
            justify-content: space-around;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 8px;
            margin: 10px 0;
            font-size: 0.9rem;
            font-weight: 700;
        }
        .medal-mini-stat div { text-align: center; }
        .medal-mini-stat span { font-size: 1.1rem; display: block; }

        /* --- NEW WHITE HERO SECTION (Matches Siglakas Events) --- */
        .hero-section {
            background: linear-gradient(135deg, #ffffff 0%, #f8fcf9 100%);
            border: 1px solid rgba(0,0,0,0.04);
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.03);
            margin-bottom: 2.5rem;
            position: relative;
            overflow: hidden;
            color: #333; /* Dark text for white background */
        }

        /* Remove the old dark glow effect */
        .hero-section::before { display: none; }

        /* --- WIDGET CARDS (Clean White Style) --- */
        .stat-card-widget {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid rgba(0,0,0,0.06);
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
            transition: all 0.3s ease;
            height: 100%;
            display: flex; /* Horizontal Layout */
            align-items: center; 
            text-align: left;
        }

        .stat-card-widget:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.08);
            border-color: rgba(13, 110, 253, 0.3); /* Blue glow */
        }

        /* Icon Circles */
        .stat-icon-circle {
            width: 64px;
            height: 64px;
            min-width: 64px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1.25rem;
            font-size: 1.75rem;
        }

        /* Theme Colors for Icons */
        .stat-theme-blue { background: rgba(13, 110, 253, 0.1); color: #0d6efd; }
        .stat-theme-green { background: rgba(25, 135, 84, 0.1); color: #198754; }
        .stat-theme-gold  { background: rgba(255, 193, 7, 0.1); color: #ffc107; }

        /* --- DIGITAL VICTORY CARD (Fallback for Silver/Bronze) --- */
        .digital-victory-card {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            /* Uses the team color via inline style */
            background-color: var(--card-bg); 
        }

        /* Glassy Texture Overlay */
        .digital-victory-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.3) 0%, rgba(0,0,0,0.1) 100%);
            z-index: 1;
        }

        /* Centered Floating Logo */
        .digital-victory-card img {
            width: 55%;
            height: 55%;
            object-fit: contain;
            filter: drop-shadow(0 10px 20px rgba(0,0,0,0.3));
            z-index: 2;
            transition: transform 0.4s ease;
        }

        /* Hover Effect */
        .gallery-card:hover .digital-victory-card img {
            transform: scale(1.15) rotate(5deg);
        }

/* --- MOBILE OPTIMIZATION: HERO & NAVBAR --- */

/* 1. Compact Navbar (Horizontal Layout) */
@media (max-width: 991px) {
    /* Reduce Header Height */
    .navbar {
        padding: 0.5rem 1rem !important;
        min-height: 60px;
    }

    /* Shrink Logo & Text */
    .brand-logo { height: 36px !important; width: 36px !important; }
    .brand-heading { font-size: 1rem !important; }
    .brand-subheading { font-size: 0.65rem !important; }

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

    /* Compact Button */
    .nav-item .btn {
        margin: 0 !important;
        padding: 0.3rem 0.8rem !important;
        font-size: 0.8rem !important;
        line-height: 1.2;
    }
}

/* 2. Compact Hero Section (From previous request) */
@media (max-width: 767.98px) {
    .hero-section {
        padding: 1.25rem !important;
        margin-bottom: 1.5rem !important;
    }
    .hero-title-wrapper { gap: 10px; margin-bottom: 0.5rem; }
    .hero-icon-circle { width: 45px; height: 45px; min-width: 45px; }
    .hero-icon-circle img { width: 24px; height: 24px; }
    .hero-title-text h1 { font-size: 1.4rem; margin-bottom: 0; }
    .hero-subtitle-badge { font-size: 0.6rem; padding: 0.2rem 0.6rem; }
    
    /* Clamp Description to 2 lines */
    .hero-description {
        font-size: 0.85rem;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        margin-bottom: 1.25rem;
    }

    /* Smaller Widgets */
    .stat-card-widget { padding: 12px 15px; }
    .stat-icon-circle { width: 40px; height: 40px; min-width: 40px; margin-right: 12px; }
    .stat-content .display-6 { font-size: 1.5rem; margin-bottom: 0; }
    .stat-content small { font-size: 0.65rem; }
    
    /* Smaller 'Official Tally' Badge */
    .live-badge-wrapper-gold { padding: 0.2rem 0.5rem; gap: 4px; }
    .live-badge-wrapper-gold i { font-size: 0.7rem; }
    .live-badge-wrapper-gold span { font-size: 0.55rem !important; }
}
    </style>
</head>
<body>
    
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <div class="container-fluid d-flex align-items center justify-content-between">
            <a class="navbar-brand d-flex align-items-center interactive-brand" href="home.php" style="cursor: pointer;">
                <img src="images/PIT.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white brand-heading" style="font-size: 1.25rem;">PIT SIGLAKAS MEDAL TALLY</strong>
                    <small class="text-light brand-subheading" style="font-size: 0.75rem;">Official College Tournament System</small>
                </div>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item"><a class="nav-link" href="home.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="Eventpage.php">Events</a></li>
                    <li class="nav-item"><a class="nav-link active" href="college_team.php">Teams</a></li>
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
            
            <div class="hero-section mb-5">
    <div class="row align-items-center">
        
        <div class="col-lg-6 mb-4 mb-lg-0">
            <div class="hero-title-wrapper">
                <div class="hero-icon-circle">
                    <img src="images/teamRoster.png" alt="Team Icon">
                </div>
                <div class="hero-title-text">
                    <h1>Participating Teams</h1>
                    <span class="hero-subtitle-badge">Competition Roster</span>
                </div>
            </div>
            <p class="hero-description">
                A comprehensive look at all teams competing in the Siglakas. Track rosters, view medal history, and see the top performing colleges.
            </p>
        </div>

        <div class="col-lg-6">
            <div class="row g-3">
                
                <!-- Total Teams -->
                <div class="col-md-6 col-12">
                    <div class="stat-card-widget">
                        <div class="stat-icon-circle stat-theme-teams">
                            <img src="images/teamIcon.png" alt="Team Icon" style="width: 50px; height: 50px; object-fit: contain;">
                        </div>
                        <div class="stat-content">
                            <div class="fw-bold text-dark display-6" id="hero-total-teams"><?= $total_teams ?></div>
                            <small class="text-primary">Total Teams</small>
                        </div>
                    </div>
                </div>

                <!-- Top Performers -->
                <div class="col-md-6 col-12">
                    <div class="stat-card-widget">
                        <div class="stat-icon-circle stat-theme-performers">
                            <i class="fas fa-star"></i>
                        </div>
                        <div class="stat-content">
                            <div class="fw-bold text-dark display-6" id="hero-top-performers"><?= $topPerformersCount ?></div>
                            <small class="text-success">Top Performers</small>
                        </div>
                    </div>
                </div>

                <!-- Medals Awarded -->
                <div class="col-12">
                    <div class="stat-card-widget">
                        <div class="stat-icon-circle stat-theme-medals">
                            <i class="fas fa-medal"></i>
                        </div>
                        <div class="stat-content flex-grow-1">
                            <div class="d-flex align-items-center justify-content-between">
                                <div class="fw-bold text-dark display-6" id="hero-total-medals"><?= $total_medals ?></div>
                                <div class="live-badge-wrapper-gold">
                                    <i class="fas fa-trophy me-2"></i>
                                    <span class="text-dark fw-bold" style="font-size: 0.7rem; letter-spacing: 1px;">OFFICIAL TALLY</span>
                                </div>
                            </div>
                            <small class="text-warning">Medals Awarded</small>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>
            
            <div class="team-container">
                <div class="filter-bar">
    <div class="row g-3 align-items-end">
        <div class="col-md-8">
            <label for="searchInput" class="form-label fw-bold text-dark mb-2">
                <i class="fas fa-search me-2 text-primary"></i>Search Team
            </label>
            <div class="input-group shadow-sm">
                <span class="input-group-text bg-white border-end-0">
                    <i class="fas fa-search text-muted"></i>
                </span>
                <input type="text" id="searchInput" class="form-control border-start-0 ps-0" 
                       placeholder="Search by name or code (e.g., COTE)...">
            </div>
        </div>
        <div class="col-md-4">
            <label for="sortSelect" class="form-label fw-bold text-dark mb-2">
                <i class="fas fa-sort me-2 text-primary"></i>Sort By
            </label>
            <select id="sortSelect" class="form-select shadow-sm">
                <option value="rank">Medal Rank (Default)</option>
                <option value="name-asc">Name (A-Z)</option>
                <option value="name-desc">Name (Z-A)</option>
                <option value="medals-desc">Total Medals (High-Low)</option>
                <option value="medals-asc">Total Medals (Low-High)</option>
            </select>
        </div>
    </div>
</div>

                <div id="college-roster-grid" class="row g-4">
                    <?php if (empty($colleges_data)): ?>
                        <div class="col-12"><div class="alert alert-info text-center">No teams have been added yet.</div></div>
                    <?php endif; ?>
                    
                    <?php foreach ($colleges_data as $index => $college): ?>
                        <?php
                            $rank = $index + 1;
                            $logo_path = (!empty($college['logo_url'])) ? $college['logo_url'] : $default_logo;
                            $slogan_short = truncate_text($college['slogan'] ?? 'No slogan.', 60);
                            $unit_color = !empty($college['unit_color']) ? $college['unit_color'] : '#cccccc';
                            
                            // --- PODIUM LOGIC ---
                            $card_special_class = '';
                            $rank_icon = "#" . $rank;
                            
                            if ($rank == 1) {
                                $card_special_class = 'rank-1-card';
                                $rank_icon = '<i class="fas fa-trophy"></i> Champion';
                            } elseif ($rank == 2) {
                                $card_special_class = 'rank-2-card';
                                $rank_icon = '<i class="fas fa-medal"></i> 2nd Place';
                            } elseif ($rank == 3) {
                                $card_special_class = 'rank-3-card';
                                $rank_icon = '<i class="fas fa-medal"></i> 3rd Place';
                            }
                        ?>
                        
                        <div class="col-12 col-md-6 col-lg-4 d-flex college-card-wrapper" 
                             data-name="<?= htmlspecialchars(strtolower($college['college_name'])) ?>"
                             data-code="<?= htmlspecialchars(strtolower($college['college_code'])) ?>"
                             data-rank="<?= $rank ?>"
                             data-total-medals="<?= $college['TotalMedals'] ?>">
                             
                            <div class="college-card d-flex flex-column w-100 <?= $card_special_class ?>" style="--team-color: <?= htmlspecialchars($unit_color) ?>;">
                                
                                <!-- Rank Badge -->
                                <div class="rank-badge"><?= $rank_icon ?></div>

                                <img src="<?= htmlspecialchars($logo_path) ?>" 
                                     alt="Logo" 
                                     class="college-logo mt-3"
                                     onerror="this.onerror=null; this.src='<?= $default_logo ?>'">
                                
                                <div class="text-center mt-2">
                                    <h5 class="college-name mb-1 text-dark"><?= htmlspecialchars($college['college_name']) ?></h5>
                                    <span class="badge bg-light text-dark border mb-2"><?= htmlspecialchars($college['college_code']) ?></span>
                                    <p class="text-muted small truncate-text fst-italic mb-2" style="height: 40px;">
                                        "<?= htmlspecialchars($slogan_short) ?>"
                                    </p>
                                </div>

                                <div class="mt-auto d-grid gap-2">
                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#detailsModal"

                                            data-college-id="<?= $college['college_id'] ?>">

                                        <i class="fas fa-info-circle me-1"></i> Full Details

                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div id="no-results-message" class="col-12 text-center" style="display: none;">
                        <div class="alert alert-warning">No teams match your search criteria.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div id="lightboxOverlay">
        <span class="close-lightbox" onclick="closeLightbox()">&times;</span>
        <img class="lightbox-content" id="lightboxImg">
        <div id="caption" class="lightbox-caption"></div>
    </div>

    <div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs nav-fill" id="collegeTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview-content" type="button" role="tab" aria-controls="overview-content" aria-selected="true">
                            <i class="fas fa-info-circle me-1"></i> Overview
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history-content" type="button" role="tab" aria-controls="history-content" aria-selected="false" data-tab-type="history">
                            <i class="fas fa-history me-1"></i> Medal History
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="gallery-tab" data-bs-toggle="tab" data-bs-target="#gallery-content" type="button" role="tab" aria-controls="gallery-content" aria-selected="false" data-tab-type="gallery">
                            <i class="fas fa-images me-1"></i> Victory Gallery
                        </button>
                    </li>
                </ul> 
                <div class="tab-content pt-3" id="collegeTabContent">
                    <div class="tab-pane fade show active" id="overview-content" role="tabpanel"></div>
                    <div class="tab-pane fade" id="history-content" role="tabpanel"></div>
                    <div class="tab-pane fade" id="gallery-content" role="tabpanel"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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
                        <img src="images/COte.png" alt="Logo">
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
    // LIGHTBOX FUNCTIONS
    function openLightbox(src, captionText) {
        const lightbox = document.getElementById('lightboxOverlay');
        const img = document.getElementById('lightboxImg');
        const caption = document.getElementById('caption');
        
        lightbox.style.display = "flex";
        lightbox.style.alignItems = "center";
        lightbox.style.justifyContent = "center";
        img.src = src;
        caption.innerHTML = captionText ? `<h5 class="text-white mt-3">${captionText}</h5>` : '';
    }
    
    function closeLightbox() {
        document.getElementById('lightboxOverlay').style.display = "none";
    }

    document.addEventListener('DOMContentLoaded', function() {
        // --- FIX: ADJUST PADDING FOR FIXED NAVBAR ---
        const navbar = document.querySelector('.navbar');
        const mainContent = document.querySelector('.main-content');
        if (navbar && mainContent) {
            const navbarHeight = navbar.offsetHeight;
            // Add extra buffer (30px) so it doesn't look cramped
            mainContent.style.paddingTop = `${navbarHeight + 30}px`;
        }
        
        // --- 1. DATA FROM PHP ---
        const collegesData = <?php 
            $escaped_data = [];
            foreach ($colleges_data as $college) {
                if (isset($college['slogan'])) {
                    $college['slogan'] = addslashes(str_replace(["\r", "\n"], ' ', $college['slogan']));
                }
                $escaped_data[] = $college;
            }
            echo json_encode($escaped_data); 
        ?>;
        const defaultLogo = '<?= $default_logo ?>';

        // --- 2. Search and Sort ---
        const searchInput = document.getElementById('searchInput');
        const sortSelect = document.getElementById('sortSelect');
        const grid = document.getElementById('college-roster-grid');
        const allCards = Array.from(grid.querySelectorAll('.college-card-wrapper'));
        const noResultsMessage = document.getElementById('no-results-message');

        function filterAndSort() {
            const searchTerm = searchInput.value.toLowerCase();
            const sortValue = sortSelect.value;
            let filteredCards = allCards;
            
            if (searchTerm) {
                filteredCards = allCards.filter(card => {
                    const name = card.dataset.name || '';
                    const code = card.dataset.code || '';
                    return name.includes(searchTerm) || code.includes(searchTerm);
                });
            }
            
            let sortedCards = [...filteredCards]; 
            sortedCards.sort((a, b) => {
                switch (sortValue) {
                    case 'name-asc': return a.dataset.name.localeCompare(b.dataset.name);
                    case 'name-desc': return b.dataset.name.localeCompare(a.dataset.name);
                    case 'medals-desc': return parseInt(b.dataset.totalMedals) - parseInt(a.dataset.totalMedals);
                    case 'medals-asc': return parseInt(a.dataset.totalMedals) - parseInt(b.dataset.totalMedals);
                    default: return parseInt(a.dataset.rank) - parseInt(b.dataset.rank);
                }
            });
            
            allCards.forEach(card => card.style.display = 'none');
            
            if (sortedCards.length === 0) {
                noResultsMessage.style.display = 'block';
            } else {
                noResultsMessage.style.display = 'none';
                sortedCards.forEach(card => {
                    card.style.display = 'flex';
                    grid.appendChild(card); 
                });
            }
            grid.appendChild(noResultsMessage);
        }
        
        if(searchInput) searchInput.addEventListener('keyup', filterAndSort);
        if(sortSelect) sortSelect.addEventListener('change', filterAndSort);

        // --- 3. AJAX MODAL LOGIC ---
        const detailsModal = document.getElementById('detailsModal');
        let currentCollegeId = null;

        if(detailsModal) {
            detailsModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const collegeId = button.getAttribute('data-college-id');
                if (!collegeId) return;
                
                currentCollegeId = collegeId;
                const college = collegesData.find(c => c.college_id == collegeId);
                if (!college) return;
                
                const modalHeader = detailsModal.querySelector('.modal-header');
                const overviewTab = detailsModal.querySelector('#overview-content');

                // Build Modal Header
                const logo = college.logo_url || defaultLogo;
                const cleanLogo = logo.replace('../', '');
                
                modalHeader.innerHTML = `
                    <div class="d-flex align-items-center gap-3">
                        <img src="${cleanLogo}" alt="${college.college_name} Logo" class="modal-logo"
                             onerror="this.onerror=null; this.src='${defaultLogo}'">
                        <div>
                            <h5 class="modal-title" id="detailsModalLabel">${college.college_name}</h5>
                            <span class="text-muted small">${college.college_code}</span>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                `;
                
                // Build Overview Tab
                overviewTab.innerHTML = `
                    <div class="row g-4">
                        <div class="col-md-12">
                            <div class="details-card text-center">
                                 <h5 class="details-title"><i class="fas fa-quote-left me-2 text-primary"></i>Team Slogan</h5>
                                 <p class="text-dark fst-italic fs-5 mb-0">"${college.slogan || 'No slogan provided.'}"</p>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="details-card">
                                <h5 class="details-title"><i class="fas fa-user-tie me-2 text-info"></i>Team Manager</h5>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="bg-light rounded-circle p-3"><i class="fas fa-user fa-2x text-secondary"></i></div>
                                    <span class="fw-bold fs-4">${college.team_manager || 'N/A'}</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="details-card">
                                <h5 class="details-title"><i class="fas fa-trophy me-2 text-warning"></i>Medal Summary</h5>
                                <div class="d-flex justify-content-around align-items-center text-center my-3">
                                    <div><span class="medal-badge" style="color: var(--accent-gold);">🥇</span><br><span class="fw-bold fs-3">${college.GoldCount}</span><br><span class="text-muted">Gold</span></div>
                                    <div><span class="medal-badge" style="color: var(--accent-silver);">🥈</span><br><span class="fw-bold fs-3">${college.SilverCount}</span><br><span class="text-muted">Silver</span></div>
                                    <div><span class="medal-badge" style="color: var(--accent-bronze);">🥉</span><br><span class="fw-bold fs-3">${college.BronzeCount}</span><br><span class="text-muted">Bronze</span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                // Clear other tabs
                const historyTab = detailsModal.querySelector('#history-content');
                if(historyTab) historyTab.innerHTML = '';
                const galleryTab = detailsModal.querySelector('#gallery-content');
                if(galleryTab) galleryTab.innerHTML = '';
                
                // Reset to Overview tab
                const tabEl = detailsModal.querySelector('#overview-tab');
                if (tabEl) new bootstrap.Tab(tabEl).show();
            });

            // --- Handle Tab Clicks ---
            const ajaxTabs = detailsModal.querySelectorAll('button[data-tab-type]');
            ajaxTabs.forEach(tab => {
                tab.addEventListener('show.bs.tab', function(event) {
                    const tabType = event.target.dataset.tabType;
                    const contentPaneId = event.target.getAttribute('data-bs-target');
                    const contentPane = detailsModal.querySelector(contentPaneId);

                    if (contentPane && contentPane.innerHTML.trim() === '') {
                        loadTabContent(tabType, contentPane);
                    }
                });
            });
        }

        async function loadTabContent(tabType, contentPane) {
            if (!currentCollegeId) return;

            contentPane.innerHTML = `<div class="loading-spinner"><div class="spinner-border text-primary"></div><span class="ms-3">Loading data...</span></div>`;

            try {
                const response = await fetch(`get_college_details.php?team_id=${currentCollegeId}&type=${tabType}`);
                if (!response.ok) throw new Error('Network response was not ok');
                const data = await response.json();
                buildTabHTML(tabType, data, contentPane);
            } catch (error) {
                console.error('Fetch error:', error);
                contentPane.innerHTML = `<div class="alert alert-danger">Error loading data.</div>`;
            }
        }
        
        function buildTabHTML(tabType, data, pane) {
            let html = '';

            // --- MEDAL HISTORY TAB ---
            if (tabType === 'history') {
                if (data.length === 0) {
                    html = '<div class="alert alert-info text-center m-3">No medal history recorded yet.</div>';
                } else {
                    html = `
                        <div class="table-responsive">
                            <table class="table table-hover align-middle table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-center" style="width: 15%;">Medal</th>
                                        <th style="width: 20%;">Game</th>
                                        <th style="width: 25%;">Event</th>
                                        <th style="width: 25%;">Category</th>
                                        <th class="text-center" style="width: 15%;">Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;
                    data.forEach(row => {
                        let badgeClass = 'bg-secondary';
                        let customStyle = '';
                        let medalType = (row.medal_won || '').toLowerCase();

                        if(medalType === 'gold') badgeClass = 'bg-warning text-dark';
                        else if(medalType === 'silver') badgeClass = 'bg-secondary text-white';
                        else if(medalType === 'bronze') {
                             badgeClass = 'text-white';
                             customStyle = 'background-color: #cd7f32 !important; border: 1px solid #a05a2c;';
                        }

                        let categoryText = '<span class="text-muted fst-italic">-</span>';
                        if (row.category_name && row.category_name !== '.' && row.category_name !== '-') {
                            categoryText = row.category_name;
                        }

                        html += `
                            <tr>
                                <td class="text-center">
                                    <span class="badge ${badgeClass} w-100 py-2" style="${customStyle}">
                                        ${row.medal_won}
                                    </span>
                                </td>
                                <td class="fw-bold text-primary">${row.game_name}</td>
                                <td class="fw-semibold">${row.event_name}</td>
                                <td class="text-dark">${categoryText}</td>
                                <td class="text-end small text-muted">${row.date_formatted || '-'}</td>
                            </tr>
                        `;
                    });
                    html += '</tbody></table></div>';
                }
            }
            // --- VICTORY GALLERY TAB (UPDATED) ---
            else if (tabType === 'gallery') {
                if (data.length === 0) {
                    html = '<div class="alert alert-light text-center m-3 border"><i class="fas fa-camera fa-3x text-muted mb-3 opacity-50"></i><br>No victories recorded yet.</div>';
                } else {
                    html = '<div class="row g-3 p-3">';
                    
                    // 1. Get current team info for the Digital Card
                    const college = collegesData.find(c => c.college_id == currentCollegeId);
                    const teamColor = college ? college.unit_color : '#ccc';
                    const teamLogo = college ? (college.logo_url || defaultLogo) : defaultLogo;

                    data.forEach(row => {
                        let categoryText = (row.category_name && row.category_name !== '.') ? row.category_name : 'Open Division';
                        let caption = `${row.event_name} - ${categoryText}`;
                        let medal = (row.medal_won || 'Gold').toLowerCase();
                        let hasPhoto = (row.podium_photo_url && row.podium_photo_url.trim() !== '');

                        // --- BADGE LOGIC ---
                        let badgeColor = '#FFD700'; // Gold
                        let badgeLabel = 'Champion';
                        let badgeText = 'text-dark';

                        if (medal === 'silver') { 
                            badgeColor = '#C0C0C0'; badgeLabel = '2nd Place'; badgeText = 'text-dark';
                        } else if (medal === 'bronze') { 
                            badgeColor = '#CD7F32'; badgeLabel = '3rd Place'; badgeText = 'text-white';
                        }

                        // --- DISPLAY LOGIC (Hybrid Approach) ---
                        let visualContent = '';
                        let clickAction = '';

                        // Show Photo ONLY if it's Gold AND a photo exists
                        if (medal === 'gold' && hasPhoto) {
                            visualContent = `<img src="${row.podium_photo_url}" class="gallery-img" alt="Victory Photo">`;
                            clickAction = `onclick="openLightbox('${row.podium_photo_url}', '${escapeHtml(caption)}')"`
                        } 
                        // Otherwise, show Digital Victory Card
                        else {
                            visualContent = `
                                <div class="digital-victory-card" style="--card-bg: ${teamColor}">
                                    <img src="${teamLogo}" alt="Team Logo">
                                </div>
                            `;
                            // No lightbox for digital cards (it's just a logo)
                            clickAction = 'style="cursor: default;"'; 
                        }
                        
                        html += `
                            <div class="col-md-6">
                                <div class="card gallery-card h-100 border-0" ${clickAction}>
                                    <div class="gallery-img-wrapper">
                                        ${visualContent}
                                        <div class="gallery-badge shadow-sm" style="background: ${badgeColor};" title="${badgeLabel}">
                                            <span class="${badgeText} fw-bold"><i class="fas fa-trophy me-1"></i> ${badgeLabel}</span>
                                        </div>
                                    </div>
                                    <div class="card-body p-3 text-center">
                                        <h6 class="card-title fw-bold mb-1 text-dark">${row.event_name}</h6>
                                        <p class="card-text text-muted small mb-1 text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 1px;">
                                            ${row.game_name}
                                        </p>
                                        <div class="badge bg-light text-secondary border px-2 py-1 mt-1">${categoryText}</div>
                                        <div class="mt-2 text-secondary fst-italic" style="font-size: 0.75rem;">
                                            <i class="far fa-calendar-alt me-1"></i> ${row.date_formatted || 'Date TBD'}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    html += '</div>';
                }
            }

            pane.innerHTML = html;
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            return text.toString().replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
        }

        // --- REAL-TIME UPDATES FOR TEAMS ---
        function startTeamUpdates() {
            setInterval(() => {
                fetch('fetch_teams_api.php')
                    .then(response => response.json())
                    .then(newTeamData => {
                        // 1. Update Hero Stats
                        updateHeroStats(newTeamData);

                        // 2. Re-render the Team Cards
                        renderTeamCards(newTeamData);

                        // 3. Re-initialize Search/Sort (Vital!)
                        // We must update the 'allCards' list because the HTML elements just changed
                        allCards = Array.from(grid.querySelectorAll('.college-card-wrapper'));
                        filterAndSort(); // Re-apply current search if any
                    })
                    .catch(err => console.error('Team update error:', err));
            }, 5000); // 5 Seconds
        }

        function updateHeroStats(data) {
            let totalMedals = 0;
            let topPerformers = 0;

            data.forEach(team => {
                let total = parseInt(team.TotalMedals) || 0;
                totalMedals += total;
                if (parseInt(team.GoldCount) > 0) topPerformers++;
            });

            const elTeams = document.getElementById('hero-total-teams');
            const elPerf = document.getElementById('hero-top-performers');
            const elMedals = document.getElementById('hero-total-medals');

            if (elTeams) elTeams.textContent = data.length;
            if (elPerf) elPerf.textContent = topPerformers;
            if (elMedals) elMedals.textContent = totalMedals;
        }

        function renderTeamCards(data) {
            const grid = document.getElementById('college-roster-grid');
            const noRes = document.getElementById('no-results-message'); // Keep reference
            
            // Build HTML for all cards
            let html = '';
            
            if (data.length === 0) {
                html = '<div class="col-12"><div class="alert alert-info text-center">No teams have been added yet.</div></div>';
            } else {
                data.forEach((college, index) => {
                    const rank = index + 1;
                    const logo = college.logo_url || 'images/default_avatar.png';
                    const slogan = (college.slogan || 'No slogan.').substring(0, 60) + '...';
                    const unitColor = college.unit_color || '#cccccc';

                    // Rank Logic
                    let rankClass = '';
                    let rankIcon = `#${rank}`;
                    if (rank === 1) { rankClass = 'rank-1-card'; rankIcon = '<i class="fas fa-trophy"></i> Champion'; }
                    else if (rank === 2) { rankClass = 'rank-2-card'; rankIcon = '<i class="fas fa-medal"></i> 2nd Place'; }
                    else if (rank === 3) { rankClass = 'rank-3-card'; rankIcon = '<i class="fas fa-medal"></i> 3rd Place'; }

                    html += `
                    <div class="col-12 col-md-6 col-lg-4 d-flex college-card-wrapper" 
                            data-name="${(college.college_name || '').toLowerCase()}"
                            data-code="${(college.college_code || '').toLowerCase()}"
                            data-rank="${rank}"
                            data-total-medals="${college.TotalMedals}">
                            
                        <div class="college-card d-flex flex-column w-100 ${rankClass}" style="--team-color: ${escapeHtml(unitColor)};">
                            
                            <div class="rank-badge">${rankIcon}</div>

                            <img src="${escapeHtml(logo)}" class="college-logo mt-3" onerror="this.src='images/default_avatar.png'">
                            
                            <div class="text-center mt-2">
                                <h5 class="college-name mb-1 text-dark">${escapeHtml(college.college_name)}</h5>
                                <span class="badge bg-light text-dark border mb-2">${escapeHtml(college.college_code)}</span>
                                <p class="text-muted small truncate-text fst-italic mb-2" style="height: 40px;">"${escapeHtml(slogan)}"</p>
                            </div>

                            <div class="mt-auto d-grid gap-2">
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#detailsModal" data-college-id="${college.college_id}">
                                    <i class="fas fa-info-circle me-1"></i> Full Details
                                </button>
                            </div>
                        </div>
                    </div>`;
                });
            }

            // Update the grid content
            // We append the 'no-results-message' div back at the end because we wiped it out
            grid.innerHTML = html;
            if (noRes) grid.appendChild(noRes);
        }

        // START
        startTeamUpdates();
    });
    </script>