<?php
session_start();
// Use a relative path to your main db_connect.php
require_once '../config.php'; 

// 1. SECURITY & ACCESS CONTROL
// STRICT: Only 'Sports Director' is allowed (Acting as Administrator)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Sports Director') {
    header('Location: ../login.php'); 
    exit();
}

// 2. SESSION SETUP
if (!isset($_SESSION['user_id'])) {
    die("Session error: User ID is not set.");
}
$current_user_id = $_SESSION['user_id'];
$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Sports Director';
$current_page = 'colleges.php'; // Keeps the 'Manage Teams' sidebar item active
$default_logo = 'images/default_avatar.png';

// --- DATA FETCHING ---

// 1. Validate ID
$college_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
if ($college_id === 0) {
    $_SESSION['message'] = "Invalid Team ID provided.";
    $_SESSION['message_type'] = "danger";
    header('Location: colleges.php');
    exit();
}

// 2. Fetch Basic Team Details
$stmt = $conn->prepare("SELECT * FROM colleges WHERE college_id = ?");
$stmt->bind_param("i", $college_id);
$stmt->execute();
$college = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$college) {
    $_SESSION['message'] = "No team found with that ID.";
    $_SESSION['message_type'] = "danger";
    header('Location: colleges.php');
    exit();
}

// 3. Get Overall Medal Summary
$stmt_summary = $conn->prepare("
    SELECT
      SUM(CASE WHEN c.gold_winner_college_id = ? THEN c.gold_count ELSE 0 END) AS GoldCount,
      SUM(CASE WHEN c.silver_winner_college_id = ? THEN c.silver_count ELSE 0 END) AS SilverCount,
      SUM(CASE WHEN c.bronze_winner_college_id = ? THEN c.bronze_count ELSE 0 END) AS BronzeCount
    FROM categories c
    WHERE c.status = 'Results Approved'
    AND (
      c.gold_winner_college_id = ? 
      OR c.silver_winner_college_id = ?
      OR c.bronze_winner_college_id = ?
    )
");
$stmt_summary->bind_param("iiiiii", $college_id, $college_id, $college_id, $college_id, $college_id, $college_id);
$stmt_summary->execute();
$medal_summary = $stmt_summary->get_result()->fetch_assoc();
$stmt_summary->close();

// 4. Get Medal Breakdown by Event (L2) - For "Top 5"
$event_breakdown = [];
$stmt_breakdown = $conn->prepare("
    SELECT
      ge.event_name,
      SUM(CASE WHEN c.gold_winner_college_id = ? THEN c.gold_count ELSE 0 END) AS GoldCount,
      SUM(CASE WHEN c.silver_winner_college_id = ? THEN c.silver_count ELSE 0 END) AS SilverCount,
      SUM(CASE WHEN c.bronze_winner_college_id = ? THEN c.bronze_count ELSE 0 END) AS BronzeCount
    FROM categories c
    JOIN game_events ge ON c.event_id = ge.event_id
    WHERE c.status = 'Results Approved'
    AND (
      c.gold_winner_college_id = ?
      OR c.silver_winner_college_id = ?
      OR c.bronze_winner_college_id = ?
    )
    GROUP BY ge.event_id, ge.event_name
    HAVING GoldCount > 0 OR SilverCount > 0 OR BronzeCount > 0
    ORDER BY ge.event_name
");
$stmt_breakdown->bind_param("iiiiii", $college_id, $college_id, $college_id, $college_id, $college_id, $college_id);
$stmt_breakdown->execute();
$result_breakdown = $stmt_breakdown->get_result();
while ($row = $result_breakdown->fetch_assoc()) {
    $event_breakdown[] = $row;
}
$stmt_breakdown->close();

// 5. Get Medal Breakdown by Category (L3) - For Overview Tab
$category_medal_breakdown = [];
$stmt_cat_breakdown = $conn->prepare("
    SELECT
      g.game_name,
      ge.event_name,
      c.category_name,
      c.division_name,
      SUM(CASE WHEN c.gold_winner_college_id = ? THEN c.gold_count ELSE 0 END) AS GoldCount,
      SUM(CASE WHEN c.silver_winner_college_id = ? THEN c.silver_count ELSE 0 END) AS SilverCount,
      SUM(CASE WHEN c.bronze_winner_college_id = ? THEN c.bronze_count ELSE 0 END) AS BronzeCount
    FROM categories c
    JOIN game_events ge ON c.event_id = ge.event_id
    JOIN games g ON ge.game_id = g.game_id
    WHERE c.status = 'Results Approved'
    AND (
      c.gold_winner_college_id = ?
      OR c.silver_winner_college_id = ?
      OR c.bronze_winner_college_id = ?
    )
    GROUP BY c.category_id, g.game_name, ge.event_name, c.category_name
    HAVING GoldCount > 0 OR SilverCount > 0 OR BronzeCount > 0
    ORDER BY g.game_name, ge.event_name, c.category_name
");
$stmt_cat_breakdown->bind_param("iiiiii", $college_id, $college_id, $college_id, $college_id, $college_id, $college_id);
$stmt_cat_breakdown->execute();
$result_cat_breakdown = $stmt_cat_breakdown->get_result();
while ($row = $result_cat_breakdown->fetch_assoc()) {
    $category_medal_breakdown[] = $row;
}
$stmt_cat_breakdown->close();

// 6. Get Medal Breakdown by Game (L1) - For Strengths Tab
$game_breakdown = [];
$stmt_game_breakdown = $conn->prepare("
    SELECT
      g.game_name,
      SUM(CASE WHEN c.gold_winner_college_id = ? THEN c.gold_count ELSE 0 END) AS GoldCount,
      SUM(CASE WHEN c.silver_winner_college_id = ? THEN c.silver_count ELSE 0 END) AS SilverCount,
      SUM(CASE WHEN c.bronze_winner_college_id = ? THEN c.bronze_count ELSE 0 END) AS BronzeCount
    FROM categories c
    JOIN game_events ge ON c.event_id = ge.event_id
    JOIN games g ON ge.game_id = g.game_id
    WHERE c.status = 'Results Approved'
    AND (
      c.gold_winner_college_id = ?
      OR c.silver_winner_college_id = ?
      OR c.bronze_winner_college_id = ?
    )
    GROUP BY g.game_id, g.game_name
    HAVING GoldCount > 0 OR SilverCount > 0 OR BronzeCount > 0
    ORDER BY g.game_name
");
$stmt_game_breakdown->bind_param("iiiiii", $college_id, $college_id, $college_id, $college_id, $college_id, $college_id);
$stmt_game_breakdown->execute();
$result_game_breakdown = $stmt_game_breakdown->get_result();
while ($row = $result_game_breakdown->fetch_assoc()) {
    $game_breakdown[] = $row;
}
$stmt_game_breakdown->close();

// 7. Process Top Performing Events
$top_performing_events = $event_breakdown;
usort($top_performing_events, function($a, $b) {
    if ($a['GoldCount'] != $b['GoldCount']) return $b['GoldCount'] - $a['GoldCount'];
    if ($a['SilverCount'] != $b['SilverCount']) return $b['SilverCount'] - $a['SilverCount'];
    return $b['BronzeCount'] - $a['BronzeCount'];
});
$top_performing_events = array_slice($top_performing_events, 0, 5);

// 8. [NEW] GET VICTORY GALLERY PHOTOS (Gold Wins Only)
$gallery_photos = [];
$stmt_gallery = $conn->prepare("
    SELECT
        c.podium_photo_url,
        ge.event_name,
        g.game_name,
        c.category_name,
        c.division_name,
        c.approved_at
    FROM categories c
    JOIN game_events ge ON c.event_id = ge.event_id
    JOIN games g ON ge.game_id = g.game_id
    WHERE c.status = 'Results Approved'
    AND c.gold_winner_college_id = ?
    AND c.podium_photo_url IS NOT NULL
    AND c.podium_photo_url != ''
    ORDER BY c.approved_at DESC
");
$stmt_gallery->bind_param("i", $college_id);
$stmt_gallery->execute();
$result_gallery = $stmt_gallery->get_result();
while ($row = $result_gallery->fetch_assoc()) {
    // Ensure clean path for display (prepend ../ because we are in /sd/ folder)
    // DB usually stores 'uploads/evidence/...', so we need '../uploads/evidence/...'
    $cleanPath = str_replace('../', '', $row['podium_photo_url']);
    $row['display_url'] = '../' . $cleanPath; 
    
    // Clean up category name
    if ($row['category_name'] == 'Single Division' || $row['category_name'] == '.') {
        $row['category_name'] = 'Open';
    }
    
    $gallery_photos[] = $row;
}
$stmt_gallery->close();


// Count sidebar badges
$pending_requests_count = $conn->query("SELECT COUNT(*) FROM users WHERE is_approved = 0")->fetch_row()[0] ?? 0;
$pending_results_count = $conn->query("SELECT COUNT(*) FROM categories WHERE status='Results Submitted'")->fetch_row()[0] ?? 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($college['college_name']) ?> - Team Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ... CSS Unchanged ... */
        :root { --sidebar-width: 260px; --header-height: 82px; --transition: all 0.3s ease; --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); --bg-light: #F8F9FA; }
        body { background-color: var(--bg-light); margin: 0; padding: 0; min-height: 100vh; font-family: 'Inter', sans-serif; display: flex; flex-direction: column; }
        .navbar { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.15); padding: 1rem 1.5rem; height: var(--header-height); position: fixed; top: 0; left: 0; right: 0; z-index: 1050; }
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; }
        .user-dropdown .dropdown-toggle img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; margin-right: 10px; }
        .sidebar { width: var(--sidebar-width); position: fixed; top: var(--header-height); left: 0; height: calc(100vh - var(--header-height)); background: #2c3e50; color: white; box-shadow: 5px 0 15px rgba(0,0,0,0.2); z-index: 1040; transition: width var(--transition); overflow-y: auto; }
        .sidebar-nav { padding: 20px 0; }
        .sidebar-nav .nav-link { color: rgba(255, 255, 255, 0.7); font-size: 1.05rem; font-weight: 500; padding: 12px 25px; transition: var(--transition); border-left: 5px solid transparent; margin: 2px 0; display: flex; align-items: center; text-decoration: none; }
        .sidebar-nav .nav-link i { width: 30px; text-align: center; flex-shrink: 0; font-size: 0.95em; }
        .sidebar-nav .nav-link:hover { color: white; background: rgba(255, 255, 255, 0.05); border-left-color: #1abc9c; }
        .sidebar-nav .nav-link.active { color: white; background: rgba(255, 255, 255, 0.1); border-left-color: #3498db; font-weight: 600; }
        .sidebar-nav .nav-title { padding: 10px 25px; font-size: 0.75rem; font-weight: 600; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px; }
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
        
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        .card { border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; transition: var(--transition); }
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
        .user-dropdown .dropdown-toggle .user-name { font-weight: 600; font-size: 0.95rem; }
        .navbar-profile-icon { width: 36px; height: 36px; font-size: 36px; text-align: center; line-height: 1; border-radius: 50%; margin-right: 10px; color: rgba(255,255,255,0.8); }

        .profile-header { background: #ffffff; border-radius: 15px; box-shadow: var(--card-shadow); }
        .profile-logo { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 5px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .stat-card-mini { text-align: center; padding: 1rem; border-radius: 10px; background: var(--bg-light); }
        .stat-card-mini .count { font-size: 2rem; font-weight: 700; }
        .stat-card-mini .label { font-size: 0.9rem; text-transform: uppercase; color: #6c757d; }
        .stat-gold .count { color: #ffc107; }
        .stat-silver .count { color: #6c757d; }
        .stat-bronze .count { color: #cd7f32; }

        /* GALLERY STYLES */
        .gallery-card { transition: transform 0.3s ease; border-radius: 12px; overflow: hidden; border: 0; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .gallery-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .gallery-img-wrapper { position: relative; height: 200px; overflow: hidden; }
        .gallery-img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s ease; }
        .gallery-card:hover .gallery-img { transform: scale(1.05); }
        .gallery-badge { position: absolute; top: 10px; right: 10px; background: rgba(255, 215, 0, 0.9); color: #000; font-weight: 700; padding: 5px 10px; border-radius: 50px; font-size: 0.8rem; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }

        /* STRENGTHS TAB STYLES */
.strength-card {
    border: 1px solid #eee;
    border-radius: 12px;
    padding: 15px;
    margin-bottom: 15px;
    background: #fff;
    transition: transform 0.2s;
}
.strength-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.05);
}
.progress-stacked {
    height: 12px;
    border-radius: 6px;
    background-color: #f0f0f0;
    overflow: hidden;
    display: flex;
}
.progress-bar-segment {
    height: 100%;
    transition: width 0.6s ease;
}
.leaderboard-item {
    display: flex;
    align-items: center;
    padding: 12px;
    border-bottom: 1px solid #f0f0f0;
}
.leaderboard-item:last-child { border-bottom: none; }
.rank-circle {
    width: 35px;
    height: 35px;
    background: var(--primary-dark);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    margin-right: 15px;
    font-size: 0.9rem;
}
.rank-1 { background: #FFD700; color: #000; box-shadow: 0 2px 5px rgba(255, 215, 0, 0.4); }
.rank-2 { background: #C0C0C0; color: #000; }
.rank-3 { background: #CD7F32; color: #fff; }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="sports_director_dashboard.php">
                <img src="../images/PIT.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light" style="font-size: 0.75rem;">Director Panel</small>
                </div>
            </a>
            <div class="dropdown user-dropdown ms-auto me-2 me-lg-0">
                <a href="#" class="dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle navbar-profile-icon"></i>
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="../admin_profile.php"><i class="fas fa-user-circle"></i> Profile</a></li>
                    <li><a class="dropdown-item" href="../Tournament_Manager_page.php" target="_blank"><i class="fas fa-globe"></i> View Public Site</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
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
                <a class="nav-link active" href="colleges.php">
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
                <a class="nav-link" href="reports.php">
                    <i class="fas fa-chart-line me-2"></i> <span>Medal Standings</span>
                </a>
            </li>
            
            <li class="nav-item mt-3"><span class="nav-title">Season Management</span></li>
            <li class="nav-item">
                <a class="nav-link" href="../manage_archives.php">
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
            
            <nav aria-label="breadcrumb" class="mb-2">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="sports_director_dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="colleges.php">Manage Teams</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($college['college_name']) ?></li>
              </ol>
            </nav>

            <div class="profile-header p-4 mb-4">
                <div class="row align-items-center">
                    <div class="col-md-3 col-lg-2 text-center text-md-start">
                        <?php
                            $logo_path = (!empty($college['logo_url'])) ? $college['logo_url'] : $default_logo;
                        ?>
                        <img src="../<?= htmlspecialchars($logo_path) ?>" alt="Logo" class="profile-logo"
                             onerror="this.onerror=null; this.src='../<?= $default_logo ?>'">
                    </div>
                    <div class="col-md-9 col-lg-10 text-center text-md-start mt-3 mt-md-0">
                        <h1 class="section-title mb-1"><?= htmlspecialchars($college['college_name']) ?></h1>
                        
                        <?php if (!empty($college['slogan'])): ?>
                        <p class="text-muted fst-italic mb-2">
                            "<?= htmlspecialchars($college['slogan']) ?>"
                        </p>
                        <?php endif; ?>

                        <p class="text-muted fs-5 mb-0">
                            <i class="fas fa-user-tie me-2"></i>Team Manager: 
                            <strong><?= htmlspecialchars($college['team_manager'] ?? 'N/A') ?></strong>
                        </p>
                    </div>
                </div>
                <hr>
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-mini stat-gold">
                            <div class="count"><?= $medal_summary['GoldCount'] ?? 0 ?></div>
                            <div class="label fw-bold">Gold Medals</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-mini stat-silver">
                            <div class="count"><?= $medal_summary['SilverCount'] ?? 0 ?></div>
                            <div class="label fw-bold">Silver Medals</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-mini stat-bronze">
                            <div class="count"><?= $medal_summary['BronzeCount'] ?? 0 ?></div>
                            <div class="label fw-bold">Bronze Medals</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-mini">
                            <div class="count text-primary">
                                <?= ($medal_summary['GoldCount'] ?? 0) + ($medal_summary['SilverCount'] ?? 0) + ($medal_summary['BronzeCount'] ?? 0) ?>
                            </div>
                            <div class="label fw-bold">Total Medals</div>
                        </div>
                    </div>
                </div>
            </div>

            <ul class="nav nav-tabs nav-fill mb-3" id="teamProfileTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab" aria-controls="overview" aria-selected="true">
                        <i class="fas fa-chart-pie me-2"></i>Overview & Medals
                    </button>
                </li>
                
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="strengths-tab" data-bs-toggle="tab" data-bs-target="#strengths" type="button" role="tab" aria-controls="strengths" aria-selected="false">
                        <i class="fas fa-chart-bar me-2"></i>Strengths & Participation
                    </button>
                </li>

                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="gallery-tab" data-bs-toggle="tab" data-bs-target="#gallery" type="button" role="tab" aria-controls="gallery" aria-selected="false">
                        <i class="fas fa-images me-2"></i>Victory Gallery
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="teamProfileTabsContent">
                
                <div class="tab-pane fade show active" id="overview" role="tabpanel" aria-labelledby="overview-tab">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Medal Breakdown by Category (L3)</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Game</th>
                                            <th>Event</th>
                                            <th>Category</th>
                                            <th class="text-center">Gold <i class="fas fa-medal" style="color: #ffc107;"></i></th>
                                            <th class="text-center">Silver <i class="fas fa-medal" style="color: #6c757d;"></i></th>
                                            <th class="text-center">Bronze <i class="fas fa-medal" style="color: #cd7f32;"></i></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($category_medal_breakdown)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center text-muted p-4">This team has not won any medals yet.</td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php foreach ($category_medal_breakdown as $cat): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($cat['game_name']) ?></td>
                                                <td><?= htmlspecialchars($cat['event_name']) ?></td>
                                                <td>
                                                    <span class="fw-bold d-block"><?= htmlspecialchars($cat['category_name']) ?></span>
                                                    <?php if (!empty($cat['division_name'])): ?>
                                                        <span class="text-secondary small d-block">- <?= htmlspecialchars($cat['division_name']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center fs-5"><?= $cat['GoldCount'] ?></td>
                                                <td class="text-center fs-5"><?= $cat['SilverCount'] ?></td>
                                                <td class="text-center fs-5"><?= $cat['BronzeCount'] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="tab-pane fade" id="strengths" role="tabpanel" aria-labelledby="strengths-tab">
                    <div class="row g-4">
                        
                        <div class="col-lg-7">
                            <div class="card h-100 shadow-sm border-0">
                                <div class="card-header bg-white border-0 pt-4 px-4">
                                    <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-chart-bar me-2 text-primary"></i>Medal Distribution by Game Type</h5>
                                    <small class="text-muted">Visual breakdown of medals won in each game category (L1).</small>
                                </div>
                                <div class="card-body px-4">
                                    <?php if (empty($game_breakdown)): ?>
                                        <div class="text-center text-muted py-5">
                                            <i class="fas fa-chart-area fa-3x mb-3 opacity-25"></i>
                                            <p>No medal data available yet.</p>
                                        </div>
                                    <?php endif; ?>

                                    <?php foreach ($game_breakdown as $game): 
                                        $g = (int)$game['GoldCount'];
                                        $s = (int)$game['SilverCount'];
                                        $b = (int)$game['BronzeCount'];
                                        $total = $g + $s + $b;
                                        
                                        // Calculate Percentages for the bar
                                        $g_pct = ($total > 0) ? ($g / $total) * 100 : 0;
                                        $s_pct = ($total > 0) ? ($s / $total) * 100 : 0;
                                        $b_pct = ($total > 0) ? ($b / $total) * 100 : 0;
                                    ?>
                                        <div class="strength-card">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <div class="fw-bold text-dark"><?= htmlspecialchars($game['game_name']) ?></div>
                                                <div class="fw-bold text-primary"><?= $total ?> <small class="text-muted fw-normal">Medals</small></div>
                                            </div>
                                            
                                            <div class="progress-stacked mb-2">
                                                <div class="progress-bar-segment bg-warning" style="width: <?= $g_pct ?>%" title="Gold: <?= $g ?>"></div>
                                                <div class="progress-bar-segment bg-secondary" style="width: <?= $s_pct ?>%" title="Silver: <?= $s ?>"></div>
                                                <div class="progress-bar-segment" style="width: <?= $b_pct ?>%; background-color: #cd7f32;" title="Bronze: <?= $b ?>"></div>
                                            </div>

                                            <div class="d-flex small text-muted">
                                                <div class="me-3"><i class="fas fa-circle text-warning" style="font-size: 8px;"></i> <?= $g ?> Gold</div>
                                                <div class="me-3"><i class="fas fa-circle text-secondary" style="font-size: 8px;"></i> <?= $s ?> Silver</div>
                                                <div><i class="fas fa-circle" style="color: #cd7f32; font-size: 8px;"></i> <?= $b ?> Bronze</div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-5">
                            <div class="card h-100 shadow-sm border-0">
                                <div class="card-header bg-white border-0 pt-4 px-4">
                                    <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-trophy me-2 text-warning"></i>Top Events</h5>
                                    <small class="text-muted">Best performing specific events (L2).</small>
                                </div>
                                <div class="card-body px-0">
                                    <?php if (empty($top_performing_events)): ?>
                                        <div class="text-center text-muted py-5">
                                            <p>No results yet.</p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="px-3">
                                    <?php foreach ($top_performing_events as $index => $event): 
                                        $rankClass = 'bg-light text-dark';
                                        if ($index == 0) $rankClass = 'rank-1';
                                        if ($index == 1) $rankClass = 'rank-2';
                                        if ($index == 2) $rankClass = 'rank-3';
                                    ?>
                                        <div class="leaderboard-item">
                                            <div class="rank-circle <?= $rankClass ?>">
                                                <?= $index + 1 ?>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="fw-bold text-dark"><?= htmlspecialchars($event['event_name']) ?></div>
                                                <div class="small text-muted">
                                                    <span class="text-warning fw-bold"><?= $event['GoldCount'] ?>G</span> • 
                                                    <span class="text-secondary fw-bold"><?= $event['SilverCount'] ?>S</span> • 
                                                    <span style="color: #cd7f32;" class="fw-bold"><?= $event['BronzeCount'] ?>B</span>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge bg-light text-dark border">
                                                    <?= $event['GoldCount'] + $event['SilverCount'] + $event['BronzeCount'] ?> Total
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div> 

                <div class="tab-pane fade" id="gallery" role="tabpanel" aria-labelledby="gallery-tab">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Victory Moments (Gold Medal Wins)</h5>
                        </div>
                        <div class="card-body bg-light">
                            <?php if (empty($gallery_photos)): ?>
                                <div class="text-center p-5">
                                    <i class="fas fa-camera fa-4x text-muted mb-3 opacity-50"></i>
                                    <h5 class="text-muted">No podium photos available yet.</h5>
                                    <p class="text-secondary small">Photos are added when Tournament Managers submit Gold Medal results.</p>
                                </div>
                            <?php else: ?>
                                <div class="row g-4">
                                    <?php foreach ($gallery_photos as $photo): ?>
                                        <div class="col-md-6 col-lg-4">
                                            <div class="card gallery-card h-100 border-0">
                                                <div class="gallery-img-wrapper">
                                                    <?php 
                                                        $div_text = !empty($photo['division_name']) ? ' - ' . $photo['division_name'] : ''; 
                                                        $full_caption = $photo['event_name'] . ' - ' . $photo['category_name'] . $div_text;
                                                    ?>
                                                    <img src="<?= htmlspecialchars($photo['display_url']) ?>" 
                                                         class="gallery-img zoomable-photo" 
                                                         alt="Podium Photo"
                                                         data-caption="<?= htmlspecialchars($full_caption) ?>"
                                                         style="cursor: pointer;"
                                                         title="Click to view full size">
                                                    
                                                    <div class="gallery-badge"><i class="fas fa-trophy me-1"></i>Gold</div>
                                                </div>
                                                <div class="card-body text-center p-3">
                                                    <h6 class="fw-bold mb-1"><?= htmlspecialchars($photo['event_name']) ?></h6>
                                                    <p class="text-muted small mb-1">
                                                        <?= htmlspecialchars($photo['category_name']) ?> <?= htmlspecialchars($div_text) ?>
                                                    </p>
                                                    <small class="text-secondary fst-italic" style="font-size: 0.75rem;">
                                                        <?= date('F d, Y', strtotime($photo['approved_at'])) ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            
            </div> </div> </div> 

            <div class="modal fade" id="galleryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-transparent border-0">
                <div class="modal-body p-0 text-center position-relative">
                    <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" 
                            data-bs-dismiss="modal" aria-label="Close" 
                            style="background-color: rgba(0,0,0,0.5); padding: 1rem; border-radius: 50%; z-index: 1051; box-shadow: 0 0 15px rgba(0,0,0,0.5);">
                    </button>
                    
                    <img id="galleryModalImg" src="" class="img-fluid rounded shadow-lg" 
                         style="max-height: 90vh; border: 4px solid #fff; box-shadow: 0 10px 40px rgba(0,0,0,0.5);">
                    
                    <div id="galleryModalCaption" class="mt-2 text-white fw-bold bg-dark bg-opacity-75 d-inline-block px-4 py-2 rounded-pill"></div>
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
            const sidebar = document.getElementById('sidebar');
            const footer = document.querySelector('footer');
            const navbar = document.querySelector('.navbar');

            // --- GALLERY LIGHTBOX LOGIC ---
            const galleryModalElement = document.getElementById('galleryModal');
            const galleryModalImg = document.getElementById('galleryModalImg');
            const galleryModalCaption = document.getElementById('galleryModalCaption');
            const galleryModal = new bootstrap.Modal(galleryModalElement);

            document.querySelectorAll('.zoomable-photo').forEach(img => {
                img.addEventListener('click', function() {
                    const src = this.getAttribute('src');
                    const caption = this.getAttribute('data-caption');
                    
                    if (src) {
                        galleryModalImg.src = src;
                        galleryModalCaption.textContent = caption || 'Victory Moment';
                        galleryModal.show();
                    }
                });
            });

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
            
            // Sidebar Toggle (for Mobile)
            const sidebarToggle = document.getElementById('sidebarToggle');
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                });
            }
        });
    </script>
</body>
</html>