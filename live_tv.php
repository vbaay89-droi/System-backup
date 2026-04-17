<?php
session_start();
require_once 'config.php'; 

// Default logo if a college doesn't have one
$default_logo = 'images/default_avatar.png'; 

// --- FETCH RECENT WINNERS FOR TICKER (LATEST ONLY) ---
$recent_winners = [];
$sql_ticker = "
    SELECT 
        ge.event_name, 
        c.category_name, 
        
        c.gold_count, 
        c.silver_count, 
        c.bronze_count,
        
        cg.college_code AS gold_code,
        cs.college_code AS silver_code,
        cb.college_code AS bronze_code
        
    FROM categories c
    JOIN game_events ge ON c.event_id = ge.event_id
    LEFT JOIN colleges cg ON c.gold_winner_college_id = cg.college_id
    LEFT JOIN colleges cs ON c.silver_winner_college_id = cs.college_id
    LEFT JOIN colleges cb ON c.bronze_winner_college_id = cb.college_id
    WHERE c.status = 'Results Approved' 
    ORDER BY c.approved_at DESC 
    LIMIT 1"; 

$res_ticker = $conn->query($sql_ticker);
if ($res_ticker) {
    $recent_winners = $res_ticker->fetch_all(MYSQLI_ASSOC);
}

// --- FETCH MEDAL TALLY ---
$sql = "SELECT 
            C.college_name, C.logo_url,
            C.college_code,
            C.unit_color, 
            
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
        GROUP BY C.college_id, C.college_name, C.logo_url, C.college_code, C.unit_color
        ORDER BY gold DESC, silver DESC, bronze DESC, C.college_name ASC";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        $medal_tally = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Get latest 'approved_at' time
$sql_last_updated = "SELECT MAX(approved_at) AS last_updated FROM categories WHERE status = 'Results Approved'";
$result_last_updated = $conn->query($sql_last_updated);
if ($result_last_updated && $row_last_updated = $result_last_updated->fetch_assoc()) {
    $lastUpdated = $row_last_updated['last_updated'];
}

$formattedTime = $lastUpdated ? date("m/d/Y \a\\t h:i A", strtotime($lastUpdated)) : 'Waiting for first result...';

// --- HELPER FUNCTIONS ---
function getRankMeta(int $rank): array {
    switch ($rank) {
        case 1:
            return ['<img src="trophy1.svg" alt="Champion Trophy" style="width: 45px; height: 45px;">', 'Champion'];
        case 2:
            return ['<img src="secondplace.svg" alt="1st Runner-up" style="width: 45px; height: 45px;">', '1st Runner-up'];
        case 3:
            return ['<img src="thirdplace.svg" alt="2nd Runner-up" style="width: 45px; height: 45px;">', '2nd Runner-up'];
        default: 
            $runnerUpCount = $rank - 1;
            return [(string)$rank, $runnerUpCount . 'th Runner-up'];
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live TV Display - PIT Siglakas Medal Tally</title>
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
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('images/confetti-ezgif.gif');
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
        
        .main-content {
            flex: 1 0 auto;
            position: relative;
            z-index: 1;
            padding-top: 15px; 
            padding-bottom: 15px;
            height: 100vh; /* Force exactly one screen height */
            display: flex;
            flex-direction: column;
        }
        .container {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            max-width: 95%; /* Use more horizontal TV space */
        }
        .hero-section {
            background: var(--bg-white);
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            padding: 1.5rem 2rem; /* Reduced from 2.5rem */
            margin-bottom: 0;
            position: relative;
            overflow: hidden;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }
        .hero-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 8px; /* Slightly thicker for TV */
            background: linear-gradient(90deg, var(--primary-green), var(--accent-gold), var(--accent-silver), var(--primary-green));
            background-size: 200% 100%;
            animation: shimmerBar 3s linear infinite;
        }
        @keyframes shimmerBar {
            0% { background-position: 0% 0; }
            100% { background-position: 200% 0; }
        }
        .hero-header {
            display: flex;
            flex-direction: column; 
            align-items: center; 
            gap: 0.5rem; /* Tighter gap */
            margin-bottom: 1rem;
            border-bottom: 1px solid #eee;
            padding-bottom: 1rem;
        }
        #overall-standings-list {
            display: flex;
            flex-direction: column;
            flex-grow: 1;
            justify-content: space-evenly; /* Auto-distributes rows to fit the screen */
            gap: 0.5rem; /* Replaces large margins */
        }
        .hero-title-group {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .hero-title-group img {
            height: 80px; /* Slightly larger for TV */
            animation: float 3s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-8px); }
        }
        .hero-title-group h1 {
            font-family: 'Poppins', sans-serif;
            font-weight: 800;
            font-size: 3rem; /* Larger for TV */
            background: linear-gradient(270deg, var(--primary-green), var(--primary-dark), #81C784, var(--accent-gold));
            background-size: 400% 400%;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: gradientShift 6s ease infinite;
            margin: 0;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
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
            font-size: 1rem;
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
            font-size: 1rem;
            font-weight: 700;
            padding: 0.6rem 1.5rem;
            border-radius: 50px;
            margin-left: auto;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.25);
            display: inline-flex;
            align-items: center;
        }
        
        /* --- ENTRY STYLES --- */
        /* --- COMPACT ENTRY STYLES FOR TV --- */
        .entry {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 16px;
            padding: 0.8rem 1.5rem; /* Reduced padding */
            margin-bottom: 0; /* Let flex gap handle spacing */
            background: var(--bg-white);
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            --team-color: #cccccc; 
            border-left: 4px solid var(--team-color);
        }
        .left { display: flex; align-items: center; gap: 1rem; }
        
        .rank-icon { 
            width: 55px; /* Scaled down */
            height: 55px; 
            border-radius: 50%; 
            display: flex; align-items: center; justify-content: center; 
            font-size: 1.2rem; 
            color: white; font-weight: 800; 
            box-shadow: var(--shadow-md); position: relative; 
            background: var(--team-color);
        }
        .rank-icon::after { content: ''; position: absolute; inset: -4px; border-radius: 50%; background: inherit; opacity: 0.2; z-index: -1; }
        
        .school-logo { width: 60px; height: 60px; object-fit: cover; border-radius: 50%; border: 3px solid #f0f0f0; box-shadow: var(--shadow-sm); transition: var(--transition); }
        .college-code { font-size: 1.3rem; font-weight: 800; color: var(--text-dark); font-family: 'Poppins', sans-serif; }
        .badge { padding: 0.3rem 0.7rem; border-radius: 50px; font-weight: 600; font-size: 0.75rem; box-shadow: var(--shadow-sm); }
        
        .right { display: flex; align-items: center; gap: 2rem; }
        .medal-col { text-align: center; min-width: 65px; }
        .medal-header { display: flex; flex-direction: column; align-items: center; gap: 0.2rem; margin-bottom: 0.2rem; }
        .medal-header img { width: 28px; height: 28px; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2)); }
        .medal-label { font-weight: 700; font-size: 0.8rem; text-transform: uppercase; }
        .gold-text { color: var(--accent-gold); text-shadow: 0 1px 2px rgba(255, 215, 0, 0.3); }
        .silver-text { color: var(--accent-silver); text-shadow: 0 1px 2px rgba(192, 192, 192, 0.3); }
        .bronze-text { color: var(--accent-bronze); text-shadow: 0 1px 2px rgba(205, 127, 50, 0.3); }
        .total-text { color: var(--primary-green); font-weight: 900; }
        .medal-count { font-size: 1.8rem; font-weight: 800; color: var(--text-dark); font-family: 'Poppins', sans-serif; line-height: 1; }
        .medal-col.total .medal-count { font-size: 2.2rem; color: var(--primary-green); }

        .entry:hover { 
            transform: translateX(8px); 
            box-shadow: var(--shadow-lg);
            background-color: color-mix(in srgb, var(--team-color), white 90%);
        }
        .entry:hover .school-logo { 
            transform: scale(1.1); 
            border-color: var(--team-color); 
        }
        .medal-col:hover { 
            transform: translateY(-5px); 
        }
        .medal-col:hover .medal-header img { 
            transform: scale(1.2) rotate(10deg); 
        }

        /* --- NEWS TICKER STYLES --- */
        .news-ticker-box {
            background-color: #212529 !important; 
            color: #ffffff !important;           
            height: 55px;                        
            display: flex;
            align-items: center;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 8px 20px rgba(0,0,0,0.3); 
            border: 1px solid #343a40;
            position: relative; 
            z-index: 10000; 
            margin-bottom: 1.5rem; 
        }
        .ticker-label {
            background: #dc3545;
            color: white;
            padding: 0 25px;
            height: 100%;
            display: flex;
            align-items: center;
            font-weight: 800;
            font-size: 1rem;
            letter-spacing: 1px;
            text-transform: uppercase;
            position: relative;
            z-index: 10001; 
            white-space: nowrap;
        }
        .ticker-label::after {
            content: '';
            position: absolute;
            right: -12px; 
            top: 0;
            width: 0;
            height: 0;
            border-top: 55px solid #dc3545; 
            border-right: 12px solid transparent;
            z-index: 2;
        }
        .ticker-wrap {
            flex-grow: 1;
            overflow: hidden;
            white-space: nowrap;
            position: relative;
            mask-image: linear-gradient(to right, transparent, black 2%, black 98%, transparent);
            -webkit-mask-image: linear-gradient(to right, transparent, black 2%, black 98%, transparent);
        }
        .ticker-move {
            display: inline-block;
            white-space: nowrap;
            animation: ticker 30s linear infinite; 
        }
        .ticker-item {
            display: inline-block;
            padding: 0 40px;
            font-size: 1.1rem;
            position: relative;
            vertical-align: middle;
        }
        .ticker-item::after {
            content: '•';
            position: absolute;
            right: 0;
            color: #6c757d;
            font-size: 1.2rem;
            top: 50%;
            transform: translateY(-50%);
        }
        @keyframes ticker {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
        .ticker-move.fast-ticker {
            animation: ticker 20s linear infinite; 
        }
        
        .live-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            background: #4CAF50;
            border-radius: 50%;
            margin-right: 8px;
            animation: livePulse 1.5s ease-in-out infinite;
        }
        @keyframes livePulse {
            0%, 100% { opacity: 1; transform: scale(1); box-shadow: 0 0 0 0 rgba(76,175,80,0.6); }
            50% { opacity: 0.8; transform: scale(1.2); box-shadow: 0 0 0 6px rgba(76,175,80,0); }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="container">
            <div class="hero-section">
                <div class="news-ticker-box mb-4">
                    <div class="ticker-label">JUST IN</div>
                    <div class="ticker-wrap">
                        <div class="ticker-move <?php echo empty($recent_winners) ? 'fast-ticker' : ''; ?>">
                            <?php if (!empty($recent_winners)): ?>
                                <?php 
                                $latest = $recent_winners[0];
                                $fullEvent = htmlspecialchars($latest['event_name']);
                                if (!empty($latest['category_name']) && $latest['category_name'] !== 'Single Division' && $latest['category_name'] !== 'Main Event') {
                                    $fullEvent .= ' - ' . htmlspecialchars($latest['category_name']);
                                }
                                
                                $itemHtml = '<div class="ticker-item">';
                                $itemHtml .= '<span class="text-uppercase fw-bold me-2" style="color: #fff; opacity: 0.7; letter-spacing: 1px;">' . $fullEvent . ':</span>';
                                
                                if($latest['gold_code']) {
                                    $itemHtml .= '<span class="me-3">🥇(' . $latest['gold_count'] . ') <strong style="color: #FFD700; text-shadow: 0 0 10px rgba(255, 215, 0, 0.3);">' . htmlspecialchars($latest['gold_code']) . '</strong></span>';
                                }
                                if($latest['silver_code']) {
                                    $itemHtml .= '<span class="me-3">🥈(' . $latest['silver_count'] . ') <strong style="color: #C0C0C0; text-shadow: 0 0 10px rgba(192, 192, 192, 0.3);">' . htmlspecialchars($latest['silver_code']) . '</strong></span>';
                                }
                                if($latest['bronze_code']) {
                                    $itemHtml .= '<span class="me-3">🥉(' . $latest['bronze_count'] . ') <strong style="color: #CD7F32; text-shadow: 0 0 10px rgba(205, 127, 50, 0.3);">' . htmlspecialchars($latest['bronze_code']) . '</strong></span>';
                                }
                                $itemHtml .= '</div>';
                                
                                for ($i = 0; $i < 10; $i++) {
                                    echo $itemHtml;
                                }
                                ?>
                            <?php else: ?>
                                <?php 
                                $emptyHtml = '<div class="ticker-item"><span class="text-uppercase fw-bold" style="color: #fff; opacity: 0.7; letter-spacing: 1px;">Awaiting first official results...</span></div>';
                                for ($i = 0; $i < 10; $i++) {
                                    echo $emptyHtml;
                                }
                                ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="hero-header">
                    <div class="hero-title-group">
                        <img src="images/sealionlogo.png" alt="Logo">
                        <h1>Live Medal Standings</h1>
                    </div>
                    <div class="hero-controls">
                        <div class="last-updated" id="last-updated-display">
                            <span class="live-dot"></span>
                            <i class="far fa-clock me-2"></i><?= $formattedTime ?>
                        </div>
                        <span id="refresh-timer" class="badge bg-danger"></span>
                        </div>
                </div>

                <div id="overall-standings-list">
                    <?php $rank = 1; ?>
                    <?php foreach ($medal_tally as $tally_row): ?>
                    <?php 
                    $college_code = $tally_row['college_code'] ?? 'N/A';
                    $unit_color = !empty($tally_row['unit_color']) ? $tally_row['unit_color'] : '#cccccc';
                    [ $rank_icon_html, $label ] = getRankMeta($rank);
                    ?>
                    
                    <div class="entry" style="--team-color: <?= htmlspecialchars($unit_color) ?>;">
                        <div class="left">
                            <div class="rank-icon">
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
                                <small class="text-muted" style="font-size: 1rem;"><?= htmlspecialchars($tally_row['college_name']) ?></small>
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
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const defaultLogo = <?= json_encode($default_logo); ?>; 
    let previousMedalTally = <?= json_encode($medal_tally); ?>;

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
            default: return rank; 
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text.toString().replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
    }
    
    const REFRESH_INTERVAL = 15; 
    
    // NEW: Check if the URL has a time attached to it, otherwise default to 15
    const urlParams = new URLSearchParams(window.location.search);
    let countdown = urlParams.has('t') ? parseInt(urlParams.get('t')) : REFRESH_INTERVAL;
    
    let countdownInterval;
    let currentLastUpdated = <?= json_encode($lastUpdated); ?>;

    function playConfetti() {
        document.body.classList.add('show-confetti');
        setTimeout(() => {
            document.body.classList.remove('show-confetti');
        }, 3000);
    }

    function updateTicker(winners) {
        const tickerContainer = document.querySelector('.news-ticker-box');
        const tickerMove = document.querySelector('.ticker-move');
        
        if (!winners || winners.length === 0) {
            if (tickerMove) {
                let emptyHtml = '<div class="ticker-item"><span class="text-uppercase fw-bold" style="color: #fff; opacity: 0.7; letter-spacing: 1px;">Awaiting first official results...</span></div>';
                let finalEmptyHtml = "";
                for (let i = 0; i < 10; i++) {
                    finalEmptyHtml += emptyHtml;
                }
                tickerMove.innerHTML = finalEmptyHtml;
                tickerMove.classList.add('fast-ticker'); 
            }
            return;
        }

        if(tickerContainer) tickerContainer.style.display = 'flex';
        const latest = winners[0];
        
        let fullEvent = escapeHtml(latest.event_name);
        if (latest.category_name && latest.category_name !== 'Single Division' && latest.category_name !== 'Main Event') {
            fullEvent += ' - ' + escapeHtml(latest.category_name);
        }

        let itemHtml = '<div class="ticker-item">';
        itemHtml += '<span class="text-uppercase fw-bold me-2" style="color: #fff; opacity: 0.7; letter-spacing: 1px;">' + fullEvent + ':</span>';
        
        if (latest.gold_code) {
            itemHtml += `<span class="me-3">🥇(${latest.gold_count}) <strong style="color: #FFD700; text-shadow: 0 0 10px rgba(255, 215, 0, 0.3);">${escapeHtml(latest.gold_code)}</strong></span>`;
        }
        if (latest.silver_code) {
            itemHtml += `<span class="me-3">🥈(${latest.silver_count}) <strong style="color: #C0C0C0; text-shadow: 0 0 10px rgba(192, 192, 192, 0.3);">${escapeHtml(latest.silver_code)}</strong></span>`;
        }
        if (latest.bronze_code) {
            itemHtml += `<span class="me-3">🥉(${latest.bronze_count}) <strong style="color: #CD7F32; text-shadow: 0 0 10px rgba(205, 127, 50, 0.3);">${escapeHtml(latest.bronze_code)}</strong></span>`;
        }
        itemHtml += '</div>';

        let finalHtml = "";
        for (let i = 0; i < 10; i++) {
            finalHtml += itemHtml;
        }

        if (tickerMove) {
            tickerMove.classList.remove('fast-ticker'); 
            if (tickerMove.innerHTML !== finalHtml) {
                tickerMove.innerHTML = finalHtml;
            }
        }
    }

    function updateStandings(newMedalTally, lastUpdatedTime) {
        const overallListContainer = document.getElementById('overall-standings-list'); 
        const lastUpdatedEl = document.getElementById('last-updated-display');

        if (!overallListContainer || !lastUpdatedEl) return;

        if (lastUpdatedTime) {
            const date = new Date(lastUpdatedTime);
            const formatted = date.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' }) + 
                              ' at ' + 
                              date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true }).toUpperCase();
            lastUpdatedEl.innerHTML = `<span class="live-dot"></span><i class="far fa-clock me-2"></i>Updated on ${formatted}`;
        }

        let overallHtml = '';
        let rank = 1;
        
        if (newMedalTally.length === 0) {
            overallHtml = '<div class="entry"><p class="text-center text-muted m-0 w-100">No medal standings to display yet.</p></div>';
        } else {
            newMedalTally.forEach(tally => {
                const totalMedals = tally.total;
                const collegeCode = tally.college_code || 'DEFAULT';
                const logoUrl = tally.logo_url || defaultLogo; 
                const unitColor = tally.unit_color || '#cccccc';
                
                const label = getRankLabel(rank);
                const rankIconHtml = getRankIcon(rank);

                overallHtml += `
                    <div class="entry" style="--team-color: ${unitColor};">
                        <div class="left">
                            <div class="rank-icon">${rankIconHtml}</div>
                            <img src="${logoUrl}" alt="${tally.college_name}" class="school-logo" onerror="this.onerror=null; this.src='${defaultLogo}'">
                            <div>
                                <div>
                                    <strong class="college-code">${collegeCode}</strong>
                                    <span class="badge bg-light text-dark ms-2"><strong>${label}</strong></span>
                                </div>
                                <small class="text-muted" style="font-size: 1rem;">${tally.college_name}</small>
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
                rank++;
            });
        }
        
        overallListContainer.innerHTML = overallHtml;
    }
        
    function fetchNewStandings() {
        fetch('fetch_standings.php?_=' + Date.now())
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    if (data.medal_tally) {
                        const newTallyStr = JSON.stringify(data.medal_tally);
                        const oldTallyStr = JSON.stringify(previousMedalTally);

                        if (newTallyStr !== oldTallyStr) {
                            console.log("Medal data changed! Playing confetti.");
                            playConfetti(); 
                            previousMedalTally = data.medal_tally; 
                        }
                        updateStandings(data.medal_tally, data.last_updated);
                    }

                    if (data.recent_winners) {
                        updateTicker(data.recent_winners);
                    }

                    if (data.last_updated && data.last_updated !== currentLastUpdated) {
                        currentLastUpdated = data.last_updated;
                    }
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
        
        // Remove the line "countdown = REFRESH_INTERVAL;" from here!
        tick();
        countdownInterval = setInterval(tick, 1000);
    }

    document.addEventListener('DOMContentLoaded', function() {
        playConfetti(); 
        startCountdown();
    });
    </script>
</body>
</html>