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
        C.team_manager,  /* UPDATED COLUMN */
        C.slogan,        /* UPDATED COLUMN */
        C.logo_url,
        /* REMOVED: total_students, dean_photo_url */
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

// --- 2. CALCULATE STATS FOR HERO SECTION ---
$total_teams = count($colleges_data);
$total_medals = 0;
$topPerformersCount = 0;

foreach ($colleges_data as $college) {
    $total_medals += $college['TotalMedals'];
    if ($college['GoldCount'] > 0) {
        $topPerformersCount++;
    }
}

/**
 * Helper function to truncate text cleanly.
 */
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
        .navbar {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            padding: 1rem 0;
        }
        .navbar-brand { transition: var(--transition); }
        .navbar-brand:hover { transform: translateY(-2px); }
        .brand-logo { filter: drop-shadow(0 2px 4px rgba(255,255,255,0.1)); }
        .brand-heading { font-family: 'Poppins', sans-serif; font-weight: 700; }
        .nav-link { 
            font-weight: 500; 
            font-size: 0.95rem; 
            padding: 0.5rem 1.25rem !important; 
            margin: 0 0.25rem; 
            border-radius: 8px; 
            transition: var(--transition); 
            color: rgba(255,255,255,0.7) !important;
        }
        .nav-link:hover, .nav-link.active { 
            background: rgba(255,255,255,0.1); 
            color: var(--primary-green) !important; 
        }
        .btn-danger, .btn-success { padding: 0.6rem 1.5rem; border-radius: 10px; font-weight: 600; transition: var(--transition); border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .btn-danger:hover, .btn-success:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.2); }
        .main-content {
            flex: 1 0 auto;
            position: relative;
            z-index: 1;
            padding-top: 100px;
            padding-bottom: 60px;
        }
        .footer-main {
            flex-shrink: 0;
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            color: rgba(255,255,255,0.7);
            padding: 3rem 0 2rem 0;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.15);
        }
        .footer-main .footer-logo-group { display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; }
        .footer-main .footer-logo-group img { height: 50px; }
        .footer-main h5 { font-family: 'Poppins', sans-serif; font-weight: 700; color: #fff; margin: 0; }
        .footer-main h6 { font-family: 'Poppins', sans-serif; color: #fff; font-weight: 600; margin-bottom: 1rem; }
        .footer-main .footer-links { list-style: none; padding: 0; }
        .footer-main .footer-links a { text-decoration: none; color: rgba(255,255,255,0.7); }
        .footer-main .footer-links a:hover { color: #fff; }
        .footer-bottom { border-top: 1px solid rgba(255,255,255,0.1); padding-top: 1.5rem; margin-top: 2rem; text-align: center; font-size: 0.85rem; }

        @media (max-width: 991px) {
            .footer-main { text-align: center; }
            .footer-main .footer-logo-group { justify-content: center; }
        }
        .hero-section {
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(233,236,239,0.9) 100%);
            border-radius: 16px;
            padding: 32px;
            box-shadow: var(--shadow-md);
        }
        .stat-card-new {
            background: white;
            border-radius: 12px;
            padding: 20px 15px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            height: 100%;
        }
        .stat-card-new:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
        }
        .stat-card-new .fw-bold {
            font-family: 'Poppins', sans-serif;
            font-size: 2.25rem;
        }
        .roster-container {
            background: #fff;
            border-radius: 16px;
            padding: 32px;
            box-shadow: var(--shadow-md);
        }
        .college-card {
            background-color: var(--bg-light); 
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            height: 100%; 
            padding: 24px;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
        }
        .college-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            background-color: #fff;
        }
        
        /* Card Top Border */
        .college-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px; 
            background-color: #e9ecef; 
            transition: var(--transition);
        }
        .college-card:hover::before { height: 8px; }
        
        /* Border Colors */
        .college-card.border-cote::before { background-color: Maroon; }
        .college-card.border-cte::before { background-color: Skyblue; }
        .college-card.border-pittc::before { background-color: Blue; }
        .college-card.border-comed::before { background-color: Green; }
        .college-card.border-cas::before { background-color: Yellow; }
        .college-card.border-default::before { background-color: var(--primary-green); } 

        .college-logo {
            width: 100px;
            height: 100px;
            margin: 0 auto 10px;
            display: block;
            object-fit: cover;
            border-radius: 50%;
            padding: 5px;
            background: #e9ecef;
            border: 3px solid #e9ecef;
        }
        .college-card:hover .college-logo {
            border-color: var(--primary-green);
        }
        .college-badge {
            font-size: 0.75rem;
            font-weight: bold;
            padding: 4px 10px;
            border-radius: 50rem;
            display: inline-block;
            margin-bottom: 8px;
            background-color: #e9ecef;
            color: #495057;
        }
        .college-name {
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            font-size: 1.35rem;
            color: #2c3e50;
        }
        .college-name-link {
            text-decoration: none;
            color: inherit;
        }
        .college-name-link:hover {
            color: var(--primary-green);
        }
        .truncate-text {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            font-size: 0.95rem;
            color: var(--text-dark);
            flex-grow: 1;
        }
        .btn-full-width { width: 100%; }
        .filter-bar {
            background-color: var(--bg-white);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 2rem;
        }
        
        /* Modal Styles */
        .modal-header { border-bottom: 1px solid #dee2e6; }
        .modal-footer { border-top: 1px solid #dee2e6; }
        .modal-logo {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            box-shadow: var(--shadow-sm);
        }
        .modal-title {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
        }
        
        /* Tab Styles */
        #collegeTab .nav-link {
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            color: var(--text-dark) !important;
            border: none;
            border-bottom: 3px solid transparent;
        }
        #collegeTab .nav-link.active {
            color: var(--primary-green) !important;
            border-bottom-color: var(--primary-green);
            background-color: transparent;
        }
        .tab-content { padding-top: 1.5rem; }
        .tab-pane { min-height: 200px; }
        .loading-spinner {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 200px;
            font-size: 1.2rem;
            color: var(--text-muted);
        }
        
        /* Details Card Styles */
        .details-card {
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            background-color: var(--bg-light);
            padding: 20px;
            height: 100%;
        }
        .details-title {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 1.1rem;
            color: #2c3e50;
            margin-bottom: 15px;
            border-bottom: 1px dashed #e9ecef;
            padding-bottom: 8px;
        }
        .display-4 {
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
        }
        .medal-badge {
            font-size: 2.5rem;
            line-height: 1;
        }

    </style>
</head>
<body>
    
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="home.php">
                <img src="imageslogo.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white brand-heading" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light brand-subheading" style="font-size: 0.75rem;">Official College Tournament System</small>
                </div>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
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
            
            <div class="hero-section mb-5">
                <div class="row align-items-center">
                    <div class="col-lg-6 mb-4 mb-lg-0">
                        <h1 class="display-4 fw-bold mb-3 text-dark" style="font-family: 'Poppins', sans-serif;">
                            <i class="fas fa-users me-2"></i>
                            Participating Teams
                        </h1>
                        <p class="lead text-muted">A comprehensive look at all teams competing in the tournament.</p>
                    </div>
                    <div class="col-lg-6">
                        <div class="row g-3">
                            <div class="col-4">
                                <div class="stat-card-new text-center">
                                    <i class="fas fa-flag fs-2 text-primary"></i>
                                    <div class="fw-bold mt-2 text-primary"><?= $total_teams ?></div>
                                    <div class="text-muted small">Total Teams</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="stat-card-new text-center">
                                    <i class="fas fa-star fs-2 text-success"></i>
                                    <div class="fw-bold mt-2 text-success"><?= $topPerformersCount ?></div>
                                    <div class="text-muted small">Top Performers (w/ Gold)</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="stat-card-new text-center">
                                    <i class="fas fa-medal fs-2 text-warning"></i>
                                    <div class="fw-bold mt-2 text-warning"><?= $total_medals ?></div>
                                    <div class="text-muted small">Total Medals Awarded</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="team-container">
                <h2 class="fw-bold mb-4 text-dark" style="font-family: 'Poppins', sans-serif;">
                    <i class="fas fa-list-alt me-2 text-primary"></i> All Teams
                </h2>
                
                <div class="filter-bar">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label for="searchInput" class="form-label fw-bold">Search Team</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" id="searchInput" class="form-control" placeholder="Search by name or code (e.g., COTE)...">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="sortSelect" class="form-label fw-bold">Sort By</label>
                            <select id="sortSelect" class="form-select">
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
                        <div class="col-12">
                            <div class="alert alert-info text-center">No teams have been added to the system yet.</div>
                        </div>
                    <?php endif; ?>
                    
                    <?php foreach ($colleges_data as $index => $college): ?>
                        <?php
                            $logo_path = (!empty($college['logo_url'])) ? $college['logo_url'] : $default_logo;
                            $slogan_short = truncate_text($college['slogan'] ?? 'No slogan available.', 80);
                            
                            // Border color logic
                            $college_code = $college['college_code'];
                            $border_class = '';
                            switch (strtoupper($college_code)) {
                                case 'COTE': $border_class = 'border-cote'; break;
                                case 'CTE': $border_class = 'border-cte'; break;
                                case 'PIT-TC': $border_class = 'border-pittc'; break; 
                                case 'COMED': $border_class = 'border-comed'; break;
                                case 'CAS': $border_class = 'border-cas'; break;
                                default: $border_class = 'border-default'; break;
                            }
                        ?>
                        <div class="col-12 col-md-6 col-lg-4 d-flex college-card-wrapper" 
                             data-name="<?= htmlspecialchars(strtolower($college['college_name'])) ?>"
                             data-code="<?= htmlspecialchars(strtolower($college['college_code'])) ?>"
                             data-rank="<?= $index + 1 ?>"
                             data-total-medals="<?= $college['TotalMedals'] ?>">
                             
                            <div class="college-card d-flex flex-column w-100 <?= $border_class ?>">
                                <img src="<?= htmlspecialchars($logo_path) ?>" 
                                     alt="<?= htmlspecialchars($college['college_name']) ?> Logo" 
                                     class="college-logo"
                                     onerror="this.onerror=null; this.src='<?= $default_logo ?>'">
                                
                                <div class="text-center">
                                    <span class="college-badge"><?= htmlspecialchars($college['college_code']) ?></span>
                                    <h5 class="college-name mb-1">
                                        <a href="sd_manage/team_profile.php?team_id=<?= $college['college_id'] ?>" 
                                           class="college-name-link" 
                                           title="View Admin Profile (Admins Only)">
                                            <?= htmlspecialchars($college['college_name']) ?>
                                        </a>
                                    </h5>
                                    <p class="text-muted small truncate-text fst-italic">
                                        "<?= htmlspecialchars($slogan_short) ?>"
                                    </p>
                                </div>
                                <div class="mt-auto d-grid gap-2">
                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#detailsModal" 
                                            data-college-id="<?= $college['college_id'] ?>">
                                        <i class="fas fa-info-circle me-1"></i> Full Details
                                    </button>
                                    <a href="home.php" class="btn btn-outline-secondary btn-full-width">
                                        <i class="fas fa-chart-bar me-1"></i> View Leaderboard
                                    </a>
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
    
    <footer class="footer-main">
        <div class="container">
            <div class="row">
                <div class="col-lg-5 col-md-12 mb-4 mb-lg-0">
                    <div class="footer-logo-group">
                        <img src="imageslogo.png" alt="Logo">
                        <h5>PIT SPORTS TALLYING</h5>
                    </div>
                    <p>The official live medal tallying system for the Palompon Institute of Technology.</p>
                </div>
                <div class="col-lg-3 col-md-6 mb-4 mb-md-0">
                    <h6>Quick Links</h6>
                    <ul class="footer-links">
                        <li><a href="home.php">Home (Standings)</a></li>
                        <li><a href="Eventpage.php">Events Schedule</a></li>
                        <li><a href="college_team.php">Teams</a></li>
                    </ul>
                </div>
                <div class="col-lg-4 col-md-6">
                    <h6>Contact & Admin</h6>
                    <ul class="footer-links">
                        <li><a href="#">Sports Director's Office</a></li>
                        <li><a href="login.php">Administrator Login</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
                <small>Developed by Tsunayoshi Sawada</small>
            </div>
        </div>
    </footer>
    
    <div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
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
                            <button class="nav-link" id="results-tab" data-bs-toggle="tab" data-bs-target="#results-content" type="button" role="tab" aria-controls="results-content" aria-selected="false" data-tab-type="results">
                                <i class="fas fa-trophy me-1"></i> Match Results
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="upcoming-tab" data-bs-toggle="tab" data-bs-target="#upcoming-content" type="button" role="tab" aria-controls="upcoming-content" aria-selected="false" data-tab-type="upcoming">
                                <i class="fas fa-calendar-alt me-1"></i> Upcoming Matches
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="collegeTabContent">
                        <div class="tab-pane fade show active" id="overview-content" role="tabpanel" aria-labelledby="overview-tab"></div>
                        <div class="tab-pane fade" id="results-content" role="tabpanel" aria-labelledby="results-tab"></div>
                        <div class="tab-pane fade" id="upcoming-content" role="tabpanel" aria-labelledby="upcoming-tab"></div>
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
    document.addEventListener('DOMContentLoaded', function() {
        
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

        // --- 2. Search and Sort Functionality ---
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
        searchInput.addEventListener('keyup', filterAndSort);
        sortSelect.addEventListener('change', filterAndSort);

        // --- 3. AJAX MODAL LOGIC ---
        const detailsModal = document.getElementById('detailsModal');
        let currentCollegeId = null;

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
            modalHeader.innerHTML = `
                <div class="d-flex align-items-center gap-3">
                    <img src="${logo}" alt="${college.college_name} Logo" class="modal-logo"
                         onerror="this.onerror=null; this.src='${defaultLogo}'">
                    <div>
                        <h5 class="modal-title" id="detailsModalLabel">${college.college_name}</h5>
                        <span class="text-muted small">${college.college_code}</span>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            `;
            
            // Build Overview Tab (UPDATED: No Students, Dean -> Team Manager, Description -> Slogan)
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
                                <div class="bg-light rounded-circle p-3">
                                    <i class="fas fa-user fa-2x text-secondary"></i>
                                </div>
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
            detailsModal.querySelector('#results-content').innerHTML = '';
            detailsModal.querySelector('#upcoming-content').innerHTML = '';
            
            // Reset to Overview tab
            const tabEl = detailsModal.querySelector('#overview-tab');
            if (tabEl) new bootstrap.Tab(tabEl).show();
        });

        // --- Handle Tab Clicks for AJAX Loading (Unchanged) ---
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

        async function loadTabContent(tabType, contentPane) {
            if (!currentCollegeId) return;

            contentPane.innerHTML = `
                <div class="loading-spinner">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <span class="ms-3">Loading data...</span>
                </div>
            `;

            try {
                const response = await fetch(`get_college_details.php?team_id=${currentCollegeId}&type=${tabType}`);
                if (!response.ok) throw new Error('Network response was not ok');
                const data = await response.json();
                buildTabHTML(tabType, data, contentPane);
            } catch (error) {
                console.error('Fetch error:', error);
                contentPane.innerHTML = `<div class="alert alert-danger">Error loading data. Please try again.</div>`;
            }
        }
        
        function buildTabHTML(tabType, data, pane) {
            let html = '';
            if (tabType === 'results') {
                if (data.length === 0) {
                    html = '<div class="alert alert-info">No approved match results found involving this team.</div>';
                } else {
                    html = `
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Game</th><th>Event</th><th>Category</th>
                                        <th><i class="fas fa-medal" style="color: var(--accent-gold);"></i> Gold</th>
                                        <th><i class="fas fa-medal" style="color: var(--accent-silver);"></i> Silver</th>
                                        <th><i class="fas fa-medal" style="color: var(--accent-bronze);"></i> Bronze</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;
                    data.forEach(match => {
                        html += `
                            <tr>
                                <td>${match.game_name}</td>
                                <td>${match.event_name}</td>
                                <td>${match.category_name}</td>
                                <td><strong>${match.gold_winner_name || 'N/A'}</strong></td>
                                <td><strong>${match.silver_winner_name || 'N/A'}</strong></td>
                                <td><strong>${match.bronze_winner_name || 'N/A'}</strong></td>
                            </tr>
                        `;
                    });
                    html += '</tbody></table></div>';
                }
            } 
            else if (tabType === 'upcoming') {
                if (data.length === 0) {
                    html = '<div class="alert alert-info">No upcoming matches scheduled for this team.</div>';
                } else {
                    html = `
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Game</th><th>Event</th><th>Category</th>
                                        <th>Opponent</th><th>Date/Time</th><th>Venue</th><th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;
                    data.forEach(match => {
                        html += `
                            <tr>
                                <td>${match.game_name}</td>
                                <td>${match.event_name}</td>
                                <td>${match.category_name}</td>
                                <td><strong>${match.opponent_name}</strong></td>
                                <td>
                                    ${match.match_date_formatted}
                                    <small class="text-muted d-block">${match.match_time_formatted}</small>
                                </td>
                                <td>${match.venue}</td>
                                <td><span class="badge ${match.status_badge}">${match.status}</span></td>
                            </tr>
                        `;
                    });
                    html += '</tbody></table></div>';
                }
            }
            pane.innerHTML = html;
        }
    });
    </script>
</body>
</html>