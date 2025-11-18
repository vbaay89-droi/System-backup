<?php
session_start();
require_once 'config.php'; 

// PHP variables for header/footer consistency
$current_page = basename($_SERVER['PHP_SELF']);
$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'User'; 
$default_logo = 'images/default_avatar.png'; 

// --- FETCH MEDAL STANDINGS ---
$medal_tally = [];

$sql = "SELECT 
            C.college_name, C.logo_url,
            C.college_code,
            
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
        ORDER BY gold DESC, silver DESC, bronze DESC, C.college_name ASC";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        $medal_tally = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
} else {
    error_log("Error preparing medal tally statement: " . $conn->error);
}

// Get latest 'approved_at' time
$sql_last_updated = "SELECT MAX(approved_at) AS last_updated FROM categories WHERE status = 'Results Approved'";
$result_last_updated = $conn->query($sql_last_updated);
if ($result_last_updated && $row_last_updated = $result_last_updated->fetch_assoc()) {
    $lastUpdated = $row_last_updated['last_updated'];
}

$formattedTime = $lastUpdated ? date("m/d/Y \a\\t h:i A", strtotime($lastUpdated)) : 'Waiting for first result...';


// --- HELPER FUNCTIONS ---

function getCollegeStyle(?string $college_code): array {
    switch (strtoupper($college_code ?? '')) {
        case 'COTE': return ['maroon-border', 'maroon-bg'];
        case 'CAS': return ['yellow-border', 'yellow-bg'];
        case 'COMED': return ['green-border', 'green-bg'];
        case 'CTE': return ['skyblue-border', 'skyblue-bg'];
        case 'PIT-TC': return ['blue-border', 'blue-bg'];
        default: return ['default-border', 'default-bg'];
    }
}

function getRankMeta(int $rank): array {
    switch ($rank) {
        case 1:
            return ['<img src="trophy1.svg" alt="Champion Trophy" style="width: 45px; height: 45px;">', 'Champion'];
        case 2:
            return ['<img src="secondplace.svg" alt="1st Runner-up" style="width: 45px; height: 45px;">', '1st Runner-up'];
        case 3:
            return ['<img src="thirdplace.svg" alt="2nd Runner-up" style="width: 45px; height: 45px;">', '2nd Runner-up'];
        case 4:
            return ['<img src="4.png" alt="3rd Runner-up" style="width: 50px; height: 50px;">', '3rd Runner-up'];
        case 5:
            return ['<img src="5htplace.svg" alt="4th Runner-up" style="width: 50px; height: 50px;">', '4th Runner-up'];
        default:
            return [(string)$rank, ($rank) . 'th Place'];
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PIT Sports Tallying - Live Medal Standings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
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
        body::before {
            content: '';
            position: fixed;
            top: 80px;
            left: 0;
            width: 100%;
            height: calc(100% - 80px);
            background-image: url('confettitest.gif');
            background-size: 40%;
            background-position: center top;
            background-repeat: repeat;
            opacity: 0;
            z-index: 9998;
            pointer-events: none;
            transition: opacity 0.5s ease-out;
        }
        body.show-confetti::before {
            opacity: 1;
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
        .nav-link { font-weight: 500; font-size: 0.95rem; padding: 0.5rem 1.25rem !important; margin: 0 0.25rem; border-radius: 8px; transition: var(--transition); }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: var(--primary-green) !important; }
        .btn-danger, .btn-success { padding: 0.6rem 1.5rem; border-radius: 10px; font-weight: 600; transition: var(--transition); border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .btn-danger:hover, .btn-success:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.2); }
        
        .main-content {
            flex: 1 0 auto;
            position: relative;
            z-index: 1;
            padding-top: 100px;
        }
        .hero-section {
            background: var(--bg-white);
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            padding: 2.5rem;
            margin-bottom: 2.5rem;
            position: relative;
            overflow: hidden;
        }
        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-green), var(--accent-gold), var(--accent-silver));
        }
        .hero-header {
            display: flex;
            flex-direction: column; 
            align-items: center; 
            gap: 1rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid #eee;
            padding-bottom: 1.5rem;
        }
        .hero-title-group {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .hero-title-group img {
            height: 70px;
            animation: float 3s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-8px); }
        }
        .hero-title-group h1 {
            font-family: 'Poppins', sans-serif;
            font-weight: 800;
            font-size: 2.5rem;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
        }
        .hero-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            width: 100%;
            position: relative; 
    		justify-content: flex-end;
        }
        .last-updated {
			color: var(--text-muted);
			font-size: 0.9rem;
			font-weight: 500;
			padding: 0.5rem 1rem;
			background: #f8f9fa;
			border: 1px solid #e9ecef;
			border-radius: 50px;
			width: fit-content;
			position: absolute;
			left: 50%;
			transform: translateX(-50%);
			margin-left: 0;
			margin-right: 0;
		}
        #refresh-timer {
            font-size: 0.8rem;
            padding: 0.4rem 0.8rem;
            border-radius: 50px;
            margin-left: auto;
        }
        .print-btn {
            background: var(--primary-dark);
            border: none;
            font-weight: 600;
            border-radius: 50px;
            color: white;
            transition: var(--transition);
        }
        .print-btn:hover {
            background: var(--primary-green);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        /* --- ENTRY STYLES --- */
        .entry {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 16px;
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            background: var(--bg-white);
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        .entry::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 6px; transition: var(--transition); }
        .entry:hover { transform: translateX(8px); box-shadow: var(--shadow-lg); }

        /* --- COLLEGE COLOR STYLES --- */
        .maroon-border::before { background: linear-gradient(180deg, #800000, #a00000); }
        .maroon-bg { background: linear-gradient(135deg, #800000, #a00000); }
        
        .yellow-border::before { background: linear-gradient(180deg, #FFD700, #FFA500); }
        .yellow-bg { background: linear-gradient(135deg, #FFD700, #FFA500); }
        
        .green-border::before { background: linear-gradient(180deg, #28a745, #20c997); }
        .green-bg { background: linear-gradient(135deg, #28a745, #20c997); }
        
        .skyblue-border::before { background: linear-gradient(180deg, #87CEEB, #4682B4); }
        .skyblue-bg { background: linear-gradient(135deg, #87CEEB, #4682B4); }
        
        .blue-border::before { background: linear-gradient(180deg, #0d6efd, #0a58ca); }
        .blue-bg { background: linear-gradient(135deg, #0d6efd, #0a58ca); }
        
        .default-border::before { background: linear-gradient(180deg, #6c757d, #495057); }
        .default-bg { background: linear-gradient(135deg, #6c757d, #495057); }
        
        /* --- HOVER STYLES --- */
        .entry.maroon-border:hover { background-color: #fff5f5; }
        .entry.yellow-border:hover { background-color: #fffcf2; }
        .entry.green-border:hover { background-color: #f4fcf4; }
        .entry.skyblue-border:hover { background-color: #f4fcff; }
        .entry.blue-border:hover { background-color: #f4f7ff; }
        .entry.default-border:hover { background-color: #f9f9f9; }

        .left { display: flex; align-items: center; gap: 1.5rem; }
        .rank-icon { width: 70px; height: 70px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: white; font-weight: 800; box-shadow: var(--shadow-md); position: relative; }
        .rank-icon::after { content: ''; position: absolute; inset: -4px; border-radius: 50%; background: inherit; opacity: 0.2; z-index: -1; }
        
        .school-logo { width: 80px; height: 80px; object-fit: cover; border-radius: 50%; border: 4px solid #f0f0f0; box-shadow: var(--shadow-sm); transition: var(--transition); }
        .entry:hover .school-logo { transform: scale(1.1); border-color: var(--primary-green); }
        .college-code { font-size: 1.3rem; font-weight: 800; color: var(--text-dark); font-family: 'Poppins', sans-serif; }
        .badge { padding: 0.4rem 0.9rem; border-radius: 50px; font-weight: 600; font-size: 0.75rem; box-shadow: var(--shadow-sm); }
        .right { display: flex; align-items: center; gap: 2.5rem; }
        .medal-col { text-align: center; min-width: 70px; transition: var(--transition); }
        .medal-col:hover { transform: translateY(-5px); }
        .medal-header { display: flex; flex-direction: column; align-items: center; gap: 0.4rem; margin-bottom: 0.6rem; }
        .medal-header img { width: 32px; height: 32px; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2)); transition: var(--transition); }
        .medal-col:hover .medal-header img { transform: scale(1.2) rotate(10deg); }
        .medal-label { font-weight: 700; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .gold-text { color: var(--accent-gold); text-shadow: 0 1px 2px rgba(255, 215, 0, 0.3); }
        .silver-text { color: var(--accent-silver); text-shadow: 0 1px 2px rgba(192, 192, 192, 0.3); }
        .bronze-text { color: var(--accent-bronze); text-shadow: 0 1px 2px rgba(205, 127, 50, 0.3); }
        .total-text { color: var(--primary-green); font-weight: 900; text-shadow: 0 1px 2px rgba(76, 175, 80, 0.3); }
        .medal-count { font-size: 2rem; font-weight: 800; color: var(--text-dark); font-family: 'Poppins', sans-serif; line-height: 1; text-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .medal-col.total .medal-count { font-size: 2.2rem; color: var(--primary-green); }
        
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
        .footer-main .footer-logo-group img {
            height: 50px;
        }
        .footer-main .footer-logo-group h5 {
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            color: #fff;
            margin: 0;
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
        .print-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.85); z-index: 9999; backdrop-filter: blur(5px); }
        .print-content { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 2.5rem; border-radius: 16px; max-width: 90%; max-height: 90%; overflow-y: auto; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4); }
        .print-header { text-align: center; margin-bottom: 2rem; border-bottom: 3px solid var(--primary-green); padding-bottom: 1.5rem; }
        .print-header h1 { font-family: 'Poppins', sans-serif; font-weight: 800; color: var(--text-dark); margin-bottom: 0.5rem; }
        .print-header h2 { color: var(--primary-green); font-weight: 600; }
        .print-table { width: 100%; border-collapse: collapse; margin-bottom: 2rem; }
        .print-table th, .print-table td { border: 1px solid #dee2e6; padding: 1rem; text-align: center; }
        .print-table th { background: linear-gradient(135deg, var(--primary-green), var(--primary-dark)); color: white; font-weight: 700; text-transform: uppercase; font-size: 0.9rem; letter-spacing: 0.5px; }
        .print-table tbody tr:nth-child(even) { background-color: #f8f9fa; }
        .print-actions { text-align: center; margin-top: 2rem; display: flex; gap: 1rem; justify-content: center; }
        .print-actions button { padding: 0.9rem 2rem; font-size: 1rem; font-weight: 600; border-radius: 50px; border: none; transition: var(--transition); }
        .print-actions .btn-primary { background: linear-gradient(135deg, var(--primary-green), var(--primary-dark)); }
        .print-actions button:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        
        /* --- MEDIA QUERIES --- */
        @media print {
            .print-overlay { display: block !important; position: static; background: none; }
            .print-content { position: static; transform: none; max-width: none; max-height: none; box-shadow: none; overflow: visible; }
            .print-actions { display: none; }
            body > *:not(.print-overlay) { display: none; }
        }
        @media (max-width: 991px) {
            .hero-header { flex-direction: column; text-align: center; }
            .hero-title-group h1 { font-size: 2rem; }
            .entry { flex-direction: column; align-items: flex-start; padding: 1.5rem; }
            .right { width: 100%; justify-content: space-around; gap: 1rem; margin-top: 1.5rem; }
            .medal-col { min-width: 60px; }
            .medal-count { font-size: 1.6rem; }
            .medal-header img { width: 24px; height: 24px; }
            .college-code { font-size: 1.1rem; }
            .left { width: 100%; }
            .footer-main { text-align: center; }
            .footer-main .footer-logo-group { justify-content: center; }
            .footer-main .row > div { margin-bottom: 2rem; }
        }
        @media (max-width: 576px) {
            .hero-section { padding: 1.5rem; }
            .hero-title-group img { height: 60px; }
            .rank-icon { width: 60px; height: 60px; }
            .school-logo { width: 65px; height: 65px; }
            .right { gap: 0.75rem; }
            .medal-count { font-size: 1.4rem; }
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
                        <a class="nav-link <?= ($current_page == 'college_team.php') ? 'active' : '' ?>" href="college_team.php">Colleges</a>
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
            <div class="hero-section">
                <div class="hero-header">
                    <div class="hero-title-group">
                        <img src="images/SIGLAKASTEST.png" alt="Logo">
                        <h1>Live Medal Standings</h1>
                    </div>
                    <div class="hero-controls">
                        <div class="last-updated" id="last-updated-display">
                            <i class="far fa-clock me-2"></i><?= $formattedTime ?>
                        </div>
                        <span id="refresh-timer" class="badge bg-danger"></span>
                        <button class="btn btn-primary print-btn" onclick="showPrintPreview()">
                            <i class="fas fa-print me-2"></i>Print
                        </button>
                    </div>
                </div>

                <div id="overall-standings-list">
                    <?php 
                    $rank = 1; 
                    ?>
                    <?php foreach ($medal_tally as $tally_row): ?>
                        <?php 
                        $college_code = $tally_row['college_code'] ?? 'N/A';
                        [ $border, $bg ] = getCollegeStyle($college_code);
                        [ $rank_icon_html, $label ] = getRankMeta($rank);
                        ?>
                        
                        <div class="entry <?= $border ?>">
                            <div class="left">
                                <div class="rank-icon <?= $bg ?>">
                                    <?= $rank_icon_html ?>
                                </div>
                                
                                <img src="<?= htmlspecialchars($tally_row['logo_url'] ?? $default_logo) ?>" 
                                    alt="<?= htmlspecialchars($tally_row['college_name']) ?>" 
                                    class="school-logo"
                                    onerror="this.onerror=null; this.src='<?= $default_logo ?>'">

                                <div>
                                    <div>
                                        <strong class="college-code"><?= htmlspecialchars($college_code) ?></strong>
                                        <span class="badge bg-light text-dark ms-2"><strong><?= $label ?></strong></span>
                                    </div>
                                    <small class="text-muted" style="font-size: 0.9rem;"><?= htmlspecialchars($tally_row['college_name']) ?></small>
                                </div>
                            </div>

                            <div class="right">
                                <div class="medal-col gold">
                                    <div class="medal-header">
                                        <div class="medal-icon-wrapper"><img src="gold.png" alt="Gold"></div>
                                        <span class="medal-label gold-text">Gold</span>
                                    </div>
                                    <div class="medal-count"><?= $tally_row['gold'] ?></div>
                                </div>
                                <div class="medal-col silver">
                                    <div class="medal-header">
                                        <div class="medal-icon-wrapper"><img src="silver.png" alt="Silver"></div>
                                        <span class="medal-label silver-text">Silver</span>
                                    </div>
                                    <div class="medal-count"><?= $tally_row['silver'] ?></div>
                                </div>
                                <div class="medal-col bronze">
                                    <div class="medal-header">
                                        <div class="medal-icon-wrapper"><img src="bronze.png" alt="Bronze"></div>
                                        <span class="medal-label bronze-text">Bronze</span>
                                    </div>
                                    <div class="medal-count"><?= $tally_row['bronze'] ?></div>
                                </div>
                                <div class="medal-col total">
                                    <div class="medal-header"><span class="medal-label total-text">Total</span></div>
                                    <div class="medal-count"><?= $tally_row['total'] ?></div>
                                </div>
                            </div>
                        </div>
                        <?php $rank++; ?>
                    <?php endforeach; ?>

                    <?php if (count($medal_tally) === 0): ?>
                        <div class="entry">
                            <p class="text-center text-muted m-0 w-100">No medal standings to display yet.</p>
                        </div>
                    <?php endif; ?>
                </div> </div> </div> </div> <div class="print-overlay" id="printOverlay">
        <div class="print-content">
            <div class="print-header">
                <h1>PIT SPORTS TALLYING</h1>
                <h2>Live Medal Standings</h2>
                <p><strong>Generated on:</strong> <?php echo date('F d, Y - g:i A'); ?></p>
            </div>
            
            <table class="print-table">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>College Name</th>
                        <th>Gold</th>
                        <th>Silver</th>
                        <th>Bronze</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody id="print-table-body"> 
                </tbody>
            </table>
            
            <div class="print-actions">
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print me-2"></i>Print
                </button>
                <button class="btn btn-secondary" onclick="hidePrintPreview()">
                    <i class="fas fa-times me-2"></i>Close
                </button>
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
                    <p>The official live medal tallying system for the Palompon Institute of Technology. Bringing you real-time results, event schedules, and team standings.</p>
                </div>
                <div class="col-lg-3 col-md-6 mb-4 mb-md-0">
                    <h6>Quick Links</h6>
                    <ul class="footer-links">
                        <li><a href="home.php">Home (Standings)</a></li>
                        <li><a href="Eventpage.php">Events Schedule</a></li>
                        <li><a href="colleges.php">Teams & Rosters</a></li>
                    </ul>
                </div>
                <div class="col-lg-4 col-md-6">
                    <h6>Contact & Admin</h6>
                    <ul class="footer-links">
                        <li><a href="#">Sports Director's Office</a></li>
                        <li><a href="#">Report an Issue</a></li>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    const defaultLogo = <?= json_encode($default_logo); ?>; 
    // FIX: We now store the initial data to compare against later
    let previousMedalTally = <?= json_encode($medal_tally); ?>;

    // --- HELPER FUNCTIONS ---

    function getCollegeStyle(collegeCode) {
        if (!collegeCode) collegeCode = 'DEFAULT';
        switch (collegeCode.toUpperCase()) {
            case 'COTE': return ['maroon-border', 'maroon-bg'];
            case 'CAS': return ['yellow-border', 'yellow-bg'];
            case 'COMED': return ['green-border', 'green-bg'];
            case 'CTE': return ['skyblue-border', 'skyblue-bg'];
            case 'PIT-TC': return ['blue-border', 'blue-bg'];
            default: return ['default-border', 'default-bg'];
        }
    }

    function getRankLabel(rank) {
        switch (rank) {
            case 1: return 'Champion';
            case 2: return '1st Runner-up';
            case 3: return '2nd Runner-up';
            case 4: return '3rd Runner-up';
            case 5: return '4th Runner-up';
            default: return (rank) + 'th Place';
        }
    }
    
    function getRankIcon(rank) {
         switch (rank) {
            case 1: return '<img src="trophy1.svg" alt="Champion Trophy" style="width: 45px; height: 45px;">';
            case 2: return '<img src="secondplace.svg" alt="1st Runner-up" style="width: 45px; height: 45px;">';
            case 3: return '<img src="thirdplace.svg" alt="2nd Runner-up" style="width: 45px; height: 45px;">';
            case 4: return '<img src="4.png" alt="3rd Runner-up" style="width: 50px; height: 50px;">';
            case 5: return '<img src="5htplace.svg" alt="4th Runner-up" style="width: 50px; height: 50px;">';
            default: return rank;
        }
    }
    
    // --- REAL-TIME REFRESH LOGIC ---
    const REFRESH_INTERVAL = 15; 
    let countdown = REFRESH_INTERVAL;
    let countdownInterval;
    let currentLastUpdated = <?= json_encode($lastUpdated); ?>;

    function playConfetti() {
        document.body.classList.add('show-confetti');
        setTimeout(() => {
            document.body.classList.remove('show-confetti');
        }, 3000);
    }

    function updateStandings(newMedalTally, lastUpdatedTime) {
        const overallListContainer = document.getElementById('overall-standings-list'); 
        const printTableBody = document.getElementById('print-table-body');
        const lastUpdatedEl = document.getElementById('last-updated-display');

        if (!overallListContainer || !lastUpdatedEl || !printTableBody) {
            console.error("Required elements not found.");
            return;
        }

        if (lastUpdatedTime) {
            const date = new Date(lastUpdatedTime);
            const formatted = date.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' }) + 
                              ' at ' + 
                              date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true }).toUpperCase();
            lastUpdatedEl.innerHTML = `<i class="far fa-clock me-2"></i>Updated on ${formatted}`;
        }

        let overallHtml = '';
        let printHtml = '';
        let rank = 1;
        
        if (newMedalTally.length === 0) {
            overallHtml = '<div class="entry"><p class="text-center text-muted m-0 w-100">No medal standings to display yet.</p></div>';
            printHtml = '<tr><td colspan="6" class="text-center text-muted">No medal data available</td></tr>';
        } else {
            newMedalTally.forEach(tally => {
                const totalMedals = tally.total;
                const collegeCode = tally.college_code || 'DEFAULT';
                const logoUrl = tally.logo_url || defaultLogo; 
                
                const [borderClass, bgClass] = getCollegeStyle(collegeCode);
                const label = getRankLabel(rank);
                const rankIconHtml = getRankIcon(rank);

                overallHtml += `
                    <div class="entry ${borderClass}">
                        <div class="left">
                            <div class="rank-icon ${bgClass}">${rankIconHtml}</div>
                            <img src="${logoUrl}" alt="${tally.college_name}" class="school-logo" onerror="this.onerror=null; this.src='${defaultLogo}'">
                            <div>
                                <div>
                                    <strong class="college-code">${collegeCode}</strong>
                                    <span class="badge bg-light text-dark ms-2"><strong>${label}</strong></span>
                                </div>
                                <small class="text-muted" style="font-size: 0.9rem;">${tally.college_name}</small>
                            </div>
                        </div>
                        <div class="right">
                            <div class="medal-col gold">
                                <div class="medal-header">
                                    <div class="medal-icon-wrapper"><img src="gold.png" alt="Gold"></div>
                                    <span class="medal-label gold-text">Gold</span>
                                </div>
                                <div class="medal-count">${tally.gold}</div>
                            </div>
                            <div class="medal-col silver">
                                <div class="medal-header">
                                    <div class="medal-icon-wrapper"><img src="silver.png" alt="Silver"></div>
                                    <span class="medal-label silver-text">Silver</span>
                                </div>
                                <div class="medal-count">${tally.silver}</div>
                            </div>
                            <div class="medal-col bronze">
                                <div class="medal-header">
                                    <div class="medal-icon-wrapper"><img src="bronze.png" alt="Bronze"></div>
                                    <span class="medal-label bronze-text">Bronze</span>
                                </div>
                                <div class="medal-count">${tally.bronze}</div>
                            </div>
                            <div class="medal-col total">
                                <div class="medal-header"><span class="medal-label total-text">Total</span></div>
                                <div class="medal-count">${totalMedals}</div>
                            </div>
                        </div>
                    </div>
                `;

                printHtml += `
                    <tr>
                        <td>${rank}</td>
                        <td>${tally.college_name}</td>
                        <td>${tally.gold}</td>
                        <td>${tally.silver}</td>
                        <td>${tally.bronze}</td>
                        <td>${tally.total}</td>
                    </tr>
                `;
                rank++;
            });
        }
        
        overallListContainer.innerHTML = overallHtml;
        printTableBody.innerHTML = printHtml;
    }
        
    function fetchNewStandings() {
        fetch('fetch_standings.php?_=' + Date.now())
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                if (data.success && data.medal_tally) {
                    // FIX: Compare actual data instead of just timestamp
                    const newTallyStr = JSON.stringify(data.medal_tally);
                    const oldTallyStr = JSON.stringify(previousMedalTally);

                    if (newTallyStr !== oldTallyStr) {
                        console.log("Medal data changed! Playing confetti.");
                        playConfetti(); 
                        previousMedalTally = data.medal_tally; // Update our copy of data
                    }

                    // Optional: Keep timestamp logic for the "Updated on" text
                    if (data.last_updated && data.last_updated !== currentLastUpdated) {
                        currentLastUpdated = data.last_updated;
                    }

                    updateStandings(data.medal_tally, data.last_updated);
                } else {
                    console.error('Failed to get standings data:', data.error);
                }
            })
            .catch(error => {
                console.error('Error fetching standings:', error);
            });
    }

    function startCountdown() {
        const timerEl = document.getElementById('refresh-timer');
        if (!timerEl) return;
        clearInterval(countdownInterval); 

        const tick = () => {
            if (countdown <= 0) {
                timerEl.textContent = 'Refreshing...';
                countdown = REFRESH_INTERVAL;
                fetchNewStandings();
            } else {
                timerEl.textContent = `Refresh in ${countdown}s`;
                countdown--;
            }
        };
        
        countdown = REFRESH_INTERVAL;
        tick();
        countdownInterval = setInterval(tick, 1000);
    }

    document.addEventListener('DOMContentLoaded', function() {
        playConfetti(); 
        startCountdown();
    });

    function showPrintPreview() {
        const printTableBody = document.getElementById('print-table-body');
        const medalTally = <?php echo json_encode($medal_tally); ?>;
        let printHtml = '';
        let rank = 1;
        if (medalTally.length > 0) {
             medalTally.forEach(tally => {
                printHtml += `
                    <tr>
                        <td>${rank}</td>
                        <td>${tally.college_name}</td>
                        <td>${tally.gold}</td>
                        <td>${tally.silver}</td>
                        <td>${tally.bronze}</td>
                        <td>${tally.total}</td>
                    </tr>
                `;
                rank++;
            });
        } else {
            printHtml = '<tr><td colspan="6" class="text-center text-muted">No medal data available</td></tr>';
        }
        printTableBody.innerHTML = printHtml;
        document.getElementById('printOverlay').style.display = 'block';
        document.body.style.overflow = 'hidden';
    }
    
    function hidePrintPreview() {
        document.getElementById('printOverlay').style.display = 'none';
        document.body.style.overflow = 'auto';
    }
    
    window.showPrintPreview = showPrintPreview;
    window.hidePrintPreview = hidePrintPreview;
</script>
</body>
</html>