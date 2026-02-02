<?php
session_start();
require_once 'config.php'; 

// 1. SECURITY & ACCESS CONTROL
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Sports Director') {
    header('Location: login.php'); 
    exit();
}

$current_user_id = $_SESSION['user_id'];

// --- AJAX HANDLER: FETCH REPORT DATA ---
if (isset($_GET['ajax_fetch_report']) && !empty($_GET['archive_id'])) {
    header('Content-Type: application/json');
    
    $archive_id = (int)$_GET['archive_id'];
    $stmt = $conn->prepare("SELECT * FROM archived_seasons WHERE archive_id = ?");
    $stmt->bind_param("i", $archive_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Decode JSONs
        $stats = json_decode($row['stats_json'] ?? '{}', true);
        
        echo json_encode([
            'success' => true,
            'season_name' => $row['season_name'],
            'archived_at' => $row['archived_at'],
            'medals' => json_decode($row['medal_standing_json'] ?? '[]'),
            'matches' => [], // Matches removed
            'events' => json_decode($row['events_json'] ?? '[]'),
            'teams' => json_decode($row['teams_json'] ?? '[]'),
            'officials' => json_decode($row['officials_json'] ?? '[]'),
            'stats' => $stats
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Archive not found']);
    }
    exit();
}

// --- FETCH NAME LOGIC ---
$stmt_name = $conn->prepare("SELECT full_name, username FROM users WHERE id = ?");
$stmt_name->bind_param("i", $current_user_id);
$stmt_name->execute();
$user_data = $stmt_name->get_result()->fetch_assoc();
$stmt_name->close();
$name = !empty($user_data['full_name']) ? $user_data['full_name'] : ($user_data['username'] ?? 'Sports Director');

$current_page = basename($_SERVER['PHP_SELF']); 

$alert_message = '';
$alert_type = '';

// --- 2. HANDLE ACTIONS (Archive / Reset) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    $password_input = $_POST['password_check'] ?? '';

    // Security Check
    $stmt_auth = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt_auth->bind_param("i", $current_user_id);
    $stmt_auth->execute();
    $user_auth = $stmt_auth->get_result()->fetch_assoc();
    $stmt_auth->close();

    if (!$user_auth || !password_verify($password_input, $user_auth['password'])) {
        $alert_message = "Error: Incorrect password. Security check failed.";
        $alert_type = "danger";
    } else {
        
        // --- ACTION A: ARCHIVE (Snapshot Only) ---
        if ($_POST['action'] === 'archive_season') {
            $season_name = trim($_POST['season_name']);
            
            if (empty($season_name)) {
                $alert_message = "Error: Please provide a season name.";
                $alert_type = "danger";
            } else {
                $conn->begin_transaction();
                try {
                    // 1. Gather Medals Data (For Table & Bar Chart)
                    $medal_tally = $conn->query("SELECT C.college_name, C.college_code, C.logo_url,
                        SUM(CASE WHEN Cat.gold_winner_college_id = C.college_id THEN Cat.gold_count ELSE 0 END) AS gold,
                        SUM(CASE WHEN Cat.silver_winner_college_id = C.college_id THEN Cat.silver_count ELSE 0 END) AS silver,
                        SUM(CASE WHEN Cat.bronze_winner_college_id = C.college_id THEN Cat.bronze_count ELSE 0 END) AS bronze,
                        (SUM(CASE WHEN Cat.gold_winner_college_id = C.college_id THEN Cat.gold_count ELSE 0 END) + SUM(CASE WHEN Cat.silver_winner_college_id = C.college_id THEN Cat.silver_count ELSE 0 END) + SUM(CASE WHEN Cat.bronze_winner_college_id = C.college_id THEN Cat.bronze_count ELSE 0 END)) AS total
                        FROM colleges C LEFT JOIN categories Cat ON (C.college_id = Cat.gold_winner_college_id OR C.college_id = Cat.silver_winner_college_id OR C.college_id = Cat.bronze_winner_college_id) AND Cat.status = 'Results Approved'
                        GROUP BY C.college_id ORDER BY total DESC, gold DESC")->fetch_all(MYSQLI_ASSOC);

                    // 2. Gather Detailed Event Results (UPDATED TO INCLUDE COUNTS)
                    $events_structure = $conn->query("SELECT 
                            g.game_name, 
                            ge.event_name, 
                            c.category_name, 
                            c.category_type,
                            gold_col.college_name AS gold_winner, c.gold_count,
                            silver_col.college_name AS silver_winner, c.silver_count,
                            bronze_col.college_name AS bronze_winner, c.bronze_count
                        FROM categories c 
                        JOIN game_events ge ON c.event_id = ge.event_id 
                        JOIN games g ON ge.game_id = g.game_id 
                        LEFT JOIN colleges gold_col ON c.gold_winner_college_id = gold_col.college_id
                        LEFT JOIN colleges silver_col ON c.silver_winner_college_id = silver_col.college_id
                        LEFT JOIN colleges bronze_col ON c.bronze_winner_college_id = bronze_col.college_id
                        WHERE c.status = 'Results Approved'
                        ORDER BY g.game_name, ge.event_name")->fetch_all(MYSQLI_ASSOC);
                    
                    // 3. Gather Teams Data
                    $teams_data = $conn->query("SELECT college_name, college_code, logo_url, team_manager, slogan FROM colleges ORDER BY college_name")->fetch_all(MYSQLI_ASSOC);
                    
                    // 4. Gather Officials Data
                    $officials_data = $conn->query("SELECT full_name, role, email FROM users WHERE role IN ('Sports Director', 'Event Manager') ORDER BY FIELD(role, 'Sports Director', 'Event Manager'), full_name")->fetch_all(MYSQLI_ASSOC);

                    // 5. Gather Statistics & Chart Data
                    
                    // Basic Counts
                    $total_games = $conn->query("SELECT COUNT(*) FROM games")->fetch_row()[0];
                    $total_events = $conn->query("SELECT COUNT(*) FROM game_events")->fetch_row()[0];
                    
                    // Prepare Bar Chart Data (From Medal Tally)
                    $chart_labels = array_column($medal_tally, 'college_code');
                    $chart_gold = array_column($medal_tally, 'gold');
                    $chart_silver = array_column($medal_tally, 'silver');
                    $chart_bronze = array_column($medal_tally, 'bronze');

                    // Prepare Pie Chart Data (Events per Sport)
                    $sport_distribution = $conn->query("SELECT 
                        ge.event_name AS label, 
                        COUNT(c.category_id) AS value
                        FROM categories c
                        JOIN game_events ge ON c.event_id = ge.event_id
                        WHERE c.status = 'Results Approved'
                        GROUP BY ge.event_name
                        HAVING value > 0
                        ORDER BY value DESC")->fetch_all(MYSQLI_ASSOC);
                    
                    $pie_labels = array_column($sport_distribution, 'label');
                    $pie_data = array_column($sport_distribution, 'value');

                    $stats = [
                        'total_games' => $total_games,
                        'total_events' => $total_events,
                        'charts' => [
                            'bar' => [
                                'labels' => $chart_labels,
                                'gold' => $chart_gold,
                                'silver' => $chart_silver,
                                'bronze' => $chart_bronze
                            ],
                            'pie' => [
                                'labels' => $pie_labels,
                                'data' => $pie_data
                            ]
                        ]
                    ];

                    // Insert Archive
                    $stmt_arch = $conn->prepare("INSERT INTO archived_seasons (season_name, archived_by_user_id, medal_standing_json, matches_json, events_json, teams_json, officials_json, stats_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $json_medals = json_encode($medal_tally);
                    $json_matches = json_encode([]); 
                    $json_events = json_encode($events_structure);
                    $json_teams = json_encode($teams_data);
                    $json_officials = json_encode($officials_data);
                    $json_stats = json_encode($stats);

                    $stmt_arch->bind_param("sissssss", $season_name, $current_user_id, $json_medals, $json_matches, $json_events, $json_teams, $json_officials, $json_stats);
                    $stmt_arch->execute();
                    $stmt_arch->close();

                    $conn->commit();
                    $alert_message = "SUCCESS: Season '$season_name' archived successfully.";
                    $alert_type = "success";
                } catch (Exception $e) {
                    $conn->rollback();
                    $alert_message = "ERROR: Archiving failed. " . $e->getMessage();
                    $alert_type = "danger";
                }
            }
        }

        // --- ACTION B: RESET (Delete Only) ---
        elseif ($_POST['action'] === 'reset_system') {
            $conn->begin_transaction();
            try {
                $conn->query("DELETE FROM event_manager_assignments");
                $conn->query("DELETE FROM categories");
                $conn->query("DELETE FROM game_events");
                $conn->query("DELETE FROM games");
                
                $conn->commit();
                $alert_message = "SUCCESS: System has been fully reset.";
                $alert_type = "warning";
            } catch (Exception $e) {
                $conn->rollback();
                $alert_message = "ERROR: Reset failed. " . $e->getMessage();
                $alert_type = "danger";
            }
        }
    }
}

// --- 3. FETCH ARCHIVES LIST ---
$archives = [];
$res_arch = $conn->query("SELECT * FROM archived_seasons ORDER BY archived_at DESC");
if($res_arch) $archives = $res_arch->fetch_all(MYSQLI_ASSOC);

$pending_requests_count = $conn->query("SELECT COUNT(*) FROM users WHERE is_approved = 0")->fetch_row()[0] ?? 0;
$pending_results_count = $conn->query("SELECT COUNT(*) FROM categories WHERE status='Results Submitted'")->fetch_row()[0] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Season Archives - Director Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* --- CORE STYLES --- */
        :root { --sidebar-width: 260px; --header-height: 82px; --bg-light: #F8F9FA; --accent-color: #1abc9c; }
        body { background-color: var(--bg-light); margin: 0; padding: 0; min-height: 100vh; font-family: 'Inter', sans-serif; display: flex; flex-direction: column; }
        .navbar { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; height: var(--header-height); position: fixed; top: 0; left: 0; right: 0; z-index: 1050; padding: 1rem 1.5rem; }
        .sidebar { width: var(--sidebar-width); position: fixed; top: var(--header-height); left: 0; height: calc(100vh - var(--header-height)); background: #2c3e50; color: white; z-index: 1040; transition: width 0.3s ease; overflow-y: auto; }
        .sidebar-nav { padding: 20px 0; }
        .sidebar-nav .nav-link { color: rgba(255, 255, 255, 0.7); font-size: 1.05rem; padding: 12px 25px; transition: 0.3s; display: flex; align-items: center; text-decoration: none; border-left: 5px solid transparent; }
        .sidebar-nav .nav-link:hover { color: white; background: rgba(255, 255, 255, 0.05); border-left-color: var(--accent-color); }
        .sidebar-nav .nav-link.active { color: white; background: rgba(255, 255, 255, 0.1); border-left-color: #3498db; font-weight: 600; }
        .sidebar-nav .nav-title { padding: 15px 25px 5px; font-size: 0.75rem; font-weight: 700; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px; }
        .main-content { flex: 1 0 auto; padding: 30px; margin-top: var(--header-height); margin-left: var(--sidebar-width); transition: margin-left 0.3s ease; min-height: calc(100vh - var(--header-height)); }
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
        
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; }
        .user-dropdown .dropdown-toggle img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; margin-right: 10px; }

        @media (max-width: 992px) {
            .sidebar { left: -260px; }
            .sidebar.show { left: 0; }
            .main-content, footer { margin-left: 0; }
        }

        /* --- PAGE SPECIFIC --- */
        .page-header { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.08); margin-bottom: 2rem; border-left: 5px solid var(--accent-color); }
        .page-header h2 { font-size: 2rem; font-weight: 700; color: #333; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 1rem; font-family: 'Poppins', sans-serif; }
        
        .nav-tabs { border: none; background: white; padding: 0.5rem; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); margin-bottom: 2rem; display: flex; gap: 0.5rem; }
        .nav-tabs .nav-link { border: none; border-radius: 8px; padding: 1rem 1.5rem; font-weight: 600; color: #64748b; transition: all 0.3s ease; display: flex; align-items: center; gap: 0.5rem; }
        .nav-tabs .nav-link:hover { background: #f1f5f9; color: #333; transform: translateY(-2px); }
        .nav-tabs .nav-link.active { background: linear-gradient(135deg, #3498db 0%, #2980b9 100%); color: white; box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3); }
        
        .archive-card { background: white; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.08); transition: all 0.3s ease; border: 2px solid #e2e8f0; overflow: hidden; height: 100%; cursor: pointer; }
        .archive-card:hover { transform: translateY(-8px); box-shadow: 0 10px 30px rgba(0,0,0,0.12); border-color: #3498db; }
        .archive-card-header { background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%); padding: 1.5rem; border-bottom: 2px solid #e2e8f0; }
        .archive-card-body { padding: 1.5rem; }
        .season-title { font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 0.5rem; }
        .champion-badge { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); padding: 1rem; border-radius: 8px; margin: 1rem 0; border-left: 4px solid #f59e0b; }
        .stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; margin: 1.5rem 0; }
        .stat-box { text-align: center; padding: 1rem; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; }
        .stat-box .number { font-size: 1.75rem; font-weight: 700; color: #3498db; display: block; }
        .stat-box .label { font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 600; }
        .action-card { background: white; border-radius: 12px; padding: 3rem; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; border-top: 5px solid; transition: all 0.3s ease; }
        .action-card.archive-action { border-top-color: #3498db; }
        .action-card.reset-action { border-top-color: #ef4444; background: linear-gradient(135deg, #fff 0%, #fef2f2 100%); }
        .action-icon { font-size: 4rem; margin-bottom: 1.5rem; opacity: 0.9; }

        /* --- STICKY SIDEBAR REPORT STYLES --- */
        #fullReportModal .modal-body { height: calc(100vh - 65px); overflow: hidden; }
        .report-layout { display: flex; height: 100%; }
        .report-sidebar { width: 250px; background: #f8f9fa; border-right: 1px solid #dee2e6; padding: 1.5rem; overflow-y: auto; flex-shrink: 0; }
        .report-content { flex-grow: 1; overflow-y: auto; padding: 2rem; background: #fff; scroll-behavior: smooth; }
        .report-nav-link { display: block; padding: 10px 15px; color: #495057; text-decoration: none; border-radius: 8px; margin-bottom: 5px; font-weight: 500; transition: all 0.2s; }
        .report-nav-link:hover { background: #e9ecef; color: #212529; }
        .report-nav-link.active { background: #e7f1ff; color: #0d6efd; font-weight: 600; }
        .report-nav-link i { width: 25px; text-align: center; margin-right: 8px; }
        .report-section { margin-bottom: 3rem; scroll-margin-top: 2rem; }
        .report-section-title { font-size: 1.5rem; font-weight: 700; color: #343a40; border-bottom: 2px solid #e9ecef; padding-bottom: 0.5rem; margin-bottom: 1.5rem; display: flex; align-items: center; }
        .report-section-title i { margin-right: 10px; color: #6c757d; }

        /* Chart Containers */
        .chart-container { height: 400px; position: relative; margin-bottom: 2rem; }

        /* --- PRINT STYLES (Fully Updated) --- */
        @media print {
            /* 1. Global Reset for Print */
            body { 
                background: white !important; 
                height: auto !important; 
                overflow: visible !important; 
            }

            /* 2. Hide everything except the modal */
            body > *:not(#fullReportModal) { 
                display: none !important; 
            }
            
            /* 3. Modal Container Reset */
            #fullReportModal {
                display: block !important;
                position: static !important;
                width: 100% !important;
                height: auto !important;
                background: white !important;
                z-index: 1 !important;
                overflow: visible !important;
            }

            /* 4. Modal Inner Elements Reset */
            .modal-dialog, .modal-content, .modal-body {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
                box-shadow: none !important;
                position: static !important;
                height: auto !important;
                overflow: visible !important;
            }

            /* 5. Hide UI Elements (Sidebar, Header, Footer, Buttons) */
            .modal-header, .modal-footer, .report-sidebar, .btn { 
                display: none !important; 
            }

            /* 6. Layout Reset */
            .report-layout { 
                display: block !important; 
                height: auto !important; 
            }
            .report-content {
                width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
                overflow: visible !important;
                height: auto !important;
            }

            /* 7. Section Page Breaks */
            .report-section { 
                page-break-inside: avoid; 
                margin-bottom: 3rem; 
                display: block !important; 
            }
            #sec-events { page-break-before: always; }
            #sec-officials { page-break-inside: avoid; }
            #sec-charts { page-break-before: always; }

            /* 8. Table Styling for Print */
            .table { color: black !important; }
            .table-light th { background-color: #f0f0f0 !important; color: black !important; }
            
            /* 9. Badge Styling Fix (Outline for Print) */
            .badge {
                border: 1px solid #000 !important;
                color: #000 !important;
                background: transparent !important;
                font-weight: bold !important;
            }

            /* 10. --- CHART CENTERING FIX --- */
            
            /* Force the chart section to use a vertical layout */
            #sec-charts .row {
                display: block !important;
                text-align: center !important;
            }

            /* Make the chart columns full width and centered */
            #sec-charts .col-lg-6 {
                width: 100% !important;
                max-width: 90% !important;
                margin: 0 auto 40px auto !important;
                page-break-inside: avoid !important;
                display: block !important;
                float: none !important;
            }

            /* Center the chart container itself */
            .chart-container {
                margin: 0 auto !important;
                width: 80% !important;
                height: 400px !important;
                display: flex !important;
                justify-content: center !important;
                align-items: center !important;
            }

            /* Clean up the card look for print */
            .card {
                border: none !important;
                box-shadow: none !important;
            }
            .card-header {
                background: transparent !important;
                border-bottom: 2px solid #333 !important;
                text-align: center !important;
                font-size: 18pt !important;
                padding-bottom: 10px !important;
                margin-bottom: 20px !important;
            }
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
    </style>
</head>
<body>
    
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="sd/sports_director_dashboard.php">
                <img src="imageslogo.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light" style="font-size: 0.75rem;">Director Panel</small>
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
                    <li><a class="dropdown-item" href="admin_profile.php">Profile</a></li>
                    <li><a class="dropdown-item" href="Tournament_Manager_page.php" target="_blank">Public Site</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php">Logout</a></li>
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
                    <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events (L1-L3)</span>
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
                <a class="nav-link" href="Manage_Viewreports.php">
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
                <a class="nav-link active" href="manage_archives.php">
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
        
            <div class="page-header">
                <h2>
                    <i class="fas fa-archive text-primary"></i>
                    Season Archives
                </h2>
                <p class="subtitle">Manage historical data and prepare for new seasons</p>
            </div>

            <?php if ($alert_message): ?>
                <div class="alert alert-<?= $alert_type ?> alert-dismissible fade show">
                    <i class="fas fa-<?= $alert_type === 'success' ? 'check-circle' : ($alert_type === 'danger' ? 'exclamation-triangle' : 'info-circle') ?> me-2"></i>
                    <div><?= htmlspecialchars($alert_message) ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <ul class="nav nav-tabs" id="archiveTabs" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button">
                        <i class="fas fa-folder-open text-warning"></i>
                        Season History
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" id="archive-tab" data-bs-toggle="tab" data-bs-target="#archive" type="button">
                        <i class="fas fa-save text-primary"></i>
                        Create Archive
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link text-danger" id="reset-tab" data-bs-toggle="tab" data-bs-target="#reset" type="button">
                        <i class="fas fa-exclamation-circle"></i>
                        Reset System
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                
                <div class="tab-pane fade show active" id="history">
                    <?php if (empty($archives)): ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <h5>No Archived Seasons Yet</h5>
                            <p>Once you archive a season, it will appear here for future reference and analysis.</p>
                        </div>
                    <?php else: ?>
                        <div class="row g-4">
                            <?php foreach ($archives as $arch): 
                                $stats_json = $arch['stats_json'] ?? '{}';
                                $medals_json = $arch['medal_standing_json'] ?? '[]';
                                
                                $stats = json_decode($stats_json, true) ?? [];
                                $medals = json_decode($medals_json, true) ?? [];
                                
                                $champion = $medals[0]['college_name'] ?? 'Unknown';
                                $champion_logo = $medals[0]['logo_url'] ?? '';
                                if (!empty($champion_logo)) { $champion_logo = str_replace('../', '', $champion_logo); }
                                
                                $val_games = $stats['total_games'] ?? 0;
                                $val_events = $stats['total_events'] ?? 0;
                            ?>
                            <div class="col-md-6 col-xl-4">
                                <div class="archive-card view-archive-btn" 
                                    data-id="<?= $arch['archive_id'] ?>"
                                    data-season="<?= htmlspecialchars($arch['season_name']) ?>">
                                    <div class="archive-card-header">
                                        <div class="season-title"><?= htmlspecialchars($arch['season_name']) ?></div>
                                        <div class="archive-date">
                                            <i class="fas fa-calendar"></i>
                                            <?= date('F d, Y', strtotime($arch['archived_at'])) ?>
                                        </div>
                                    </div>
                                    <div class="archive-card-body">
                                        <div class="champion-badge">
                                            <div class="label">Season Champion</div>
                                            <div class="value d-flex align-items-center justify-content-center gap-2">
                                                <?php if (!empty($champion_logo)): ?>
                                                    <img src="<?= htmlspecialchars($champion_logo) ?>" alt="Logo" style="width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid #d97706; background: white;">
                                                <?php else: ?>
                                                    <i class="fas fa-trophy"></i>
                                                <?php endif; ?>
                                                <span class="text-start lh-sm"><?= htmlspecialchars($champion) ?></span>
                                            </div>
                                        </div>
                                        <div class="stats-grid">
                                            <div class="stat-box">
                                                <span class="number"><?= $val_games ?></span>
                                                <span class="label">Games</span>
                                            </div>
                                            <div class="stat-box">
                                                <span class="number"><?= $val_events ?></span>
                                                <span class="label">Events</span>
                                            </div>
                                        </div>
                                        <button class="btn btn-primary w-100 d-flex align-items-center justify-content-center gap-2">
                                            <i class="fas fa-external-link-alt"></i> View Full Report
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="tab-pane fade" id="archive">
                    <div class="row justify-content-center">
                        <div class="col-lg-8">
                            <div class="action-card archive-action">
                                <i class="fas fa-cloud-upload-alt action-icon text-primary"></i>
                                <h3>Archive Current Season</h3>
                                <p>Create a permanent snapshot of all current games, events, results, and teams.</p>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#confirmArchiveModal">
                                    <i class="fas fa-save me-2"></i> Create Archive Now
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="reset">
                    <div class="row justify-content-center">
                        <div class="col-lg-8">
                            <div class="action-card reset-action">
                                <i class="fas fa-trash-restore-alt action-icon text-danger"></i>
                                <h3 class="text-danger">Reset System Data</h3>
                                <p><strong>⚠️ Critical Action:</strong> This will delete all events, results, and assignments. Irreversible.</p>
                                <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#confirmResetModal">
                                    <i class="fas fa-exclamation-triangle me-2"></i> Reset System
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
    
    <div class="modal fade" id="confirmArchiveModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-archive me-2"></i>Confirm Archiving</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-4">
                            <label class="form-label fw-bold">Season Name / Title</label>
                            <input type="text" name="season_name" class="form-control form-control-lg" placeholder="e.g. Intramurals 2024" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Admin Password</label>
                            <input type="password" name="password_check" class="form-control form-control-lg" placeholder="Confirm your identity" required>
                        </div>
                        <input type="hidden" name="action" value="archive_season">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-check me-2"></i>Create Archive</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="confirmResetModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-skull-crossbones me-2"></i>Confirm System Reset</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="alert alert-warning border-0 mb-4">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> This action is irreversible and will delete all events and results permanently.
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Confirm Admin Password</label>
                            <input type="password" name="password_check" class="form-control form-control-lg" placeholder="Enter your password" required>
                        </div>
                        <input type="hidden" name="season_name" value="RESET_ACTION">
                        <input type="hidden" name="action" value="reset_system">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-check me-2"></i>Yes, Reset System</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="fullReportModal" tabindex="-1">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white" style="height: 65px;">
                    <h5 class="modal-title" id="reportModalTitle"><i class="fas fa-history me-2"></i>Archive Viewer</h5>
                    <div class="ms-auto">
                        <button class="btn btn-outline-light btn-sm me-2" onclick="printReport()">
                            <i class="fas fa-print me-1"></i> Print Report
                        </button>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                
                <div class="modal-body p-0">
                    <div class="report-layout">
                        <div class="report-sidebar">
                            <div class="text-muted small fw-bold text-uppercase mb-3 mt-2">Quick Jump</div>
                            <nav class="nav flex-column">
                                <a class="report-nav-link" href="#sec-medals"><i class="fas fa-medal text-warning"></i>Medal Tally</a>
                                <a class="report-nav-link" href="#sec-charts"><i class="fas fa-chart-pie text-primary"></i>Visual Reports</a>
                                <a class="report-nav-link" href="#sec-events"><i class="fas fa-calendar-day text-success"></i>Events Results</a>
                                <a class="report-nav-link" href="#sec-teams"><i class="fas fa-users text-info"></i>Participating Teams</a>
                                <a class="report-nav-link" href="#sec-officials"><i class="fas fa-user-tie text-secondary"></i>Officials</a>
                            </nav>
                        </div>
                        
                        <div class="report-content">
                            
                            <div id="sec-medals" class="report-section">
                                <div class="report-section-title"><i class="fas fa-medal"></i> Final Medal Tally</div>
                                <div class="table-responsive bg-white rounded shadow-sm border">
                                    <table class="table table-hover align-middle mb-0" id="tableMedals">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="text-center" style="width: 80px;">Rank</th>
                                                <th>College / Team</th>
                                                <th class="text-center">🥇 Gold</th>
                                                <th class="text-center">🥈 Silver</th>
                                                <th class="text-center">🥉 Bronze</th>
                                                <th class="text-center fw-bold">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>

                            <div id="sec-charts" class="report-section">
                                <div class="report-section-title"><i class="fas fa-chart-pie"></i> Visual Reports</div>
                                <div class="row g-4">
                                    <div class="col-lg-6">
                                        <div class="card h-100">
                                            <div class="card-header fw-bold text-center">Medal Distribution</div>
                                            <div class="card-body">
                                                <div class="chart-container">
                                                    <canvas id="archiveBarChart"></canvas>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="card h-100">
                                            <div class="card-header fw-bold text-center">Sport Event Distribution</div>
                                            <div class="card-body d-flex align-items-center justify-content-center">
                                                <div class="chart-container" style="width: 100%;">
                                                    <canvas id="archivePieChart"></canvas>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div id="sec-events" class="report-section">
                                <div class="report-section-title"><i class="fas fa-calendar-day"></i> Event Results</div>
                                <div class="table-responsive bg-white rounded shadow-sm border">
                                    <table class="table table-hover align-middle mb-0" id="tableEvents">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 30%;">Event Details</th>
                                                <th style="width: 20%;">Gold</th>
                                                <th style="width: 20%;">Silver</th>
                                                <th style="width: 20%;">Bronze</th>
                                            </tr>
                                        </thead>
                                        <tbody id="eventsTableBody"></tbody>
                                    </table>
                                </div>
                            </div>

                            <div id="sec-teams" class="report-section">
                                <div class="report-section-title"><i class="fas fa-users"></i> Participating Teams</div>
                                <div class="table-responsive bg-white rounded shadow-sm border">
                                    <table class="table table-hover align-middle mb-0" id="tableTeams">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 80px;">Logo</th>
                                                <th>Team Name</th>
                                                <th>Code</th>
                                                <th>Manager</th>
                                                <th>Slogan</th>
                                            </tr>
                                        </thead>
                                        <tbody id="teamsTableBody"></tbody>
                                    </table>
                                </div>
                            </div>

                            <div id="sec-officials" class="report-section">
                                <div class="report-section-title"><i class="fas fa-user-tie"></i> Tournament Officials</div>
                                <div class="table-responsive bg-white rounded shadow-sm border">
                                    <table class="table table-hover align-middle mb-0" id="tableOfficials">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Name</th>
                                                <th>Role</th>
                                                <th>Email</th>
                                            </tr>
                                        </thead>
                                        <tbody id="officialsTableBody"></tbody>
                                    </table>
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
                        <img src="imageslogo.png" alt="Logo">
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
        document.getElementById('mobileToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });

        // Sidebar Footer Fix
        const sidebar = document.getElementById('sidebar'); 
        const footer = document.querySelector('footer');
        const navbar = document.querySelector('.navbar');
        if (sidebar && footer && navbar) {
            function adjustSidebarHeight() {
                if (window.innerWidth <= 992) { sidebar.style.height = ''; return; }
                const navbarHeight = navbar.offsetHeight;
                const footerTop = footer.getBoundingClientRect().top;
                const maxSidebarHeight = window.innerHeight - navbarHeight;
                const availableHeight = footerTop - navbarHeight;
                const newHeight = Math.max(0, Math.min(maxSidebarHeight, availableHeight));
                sidebar.style.height = `${newHeight}px`;
            }
            window.addEventListener('scroll', adjustSidebarHeight, { passive: true });
            window.addEventListener('resize', adjustSidebarHeight);
            setTimeout(adjustSidebarHeight, 100);
        }

        // --- PRINT LOGIC (FIXED) ---
        function printReport() {
            const officialsBody = document.getElementById('officialsTableBody');
            
            // Wait for data load
            if (!officialsBody || officialsBody.children.length === 0 || officialsBody.innerHTML.includes('Loading')) {
                setTimeout(() => { window.print(); }, 500); 
            } else {
                window.print();
            }
        }
        window.printReport = printReport;

        // --- ARCHIVE VIEWER LOGIC ---
        document.addEventListener('DOMContentLoaded', function() {
            const viewBtns = document.querySelectorAll('.view-archive-btn');
            const modal = new bootstrap.Modal(document.getElementById('fullReportModal'));
            
            // Chart instances
            let barChartInstance = null;
            let pieChartInstance = null;

            viewBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const archiveId = this.dataset.id;
                    const season = this.dataset.season;
                    
                    document.getElementById('reportModalTitle').innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span> Loading ${season}...`;
                    
                    // Clear previous data
                    document.getElementById('officialsTableBody').innerHTML = '';
                    
                    modal.show();
                    
                    fetch(`manage_archives.php?ajax_fetch_report=1&archive_id=${archiveId}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                const archiveDate = new Date(data.archived_at).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'});
                                document.getElementById('reportModalTitle').innerHTML = `
                                    <div class="d-flex align-items-center w-100">
                                        <div class="me-auto">
                                            <i class="fas fa-history me-2"></i> ${data.season_name} 
                                            <span class="badge bg-secondary ms-2" style="font-size: 0.75rem;">Archived: ${archiveDate}</span>
                                        </div>
                                    </div>`;
                                populateModal(data);
                            } else {
                                alert('Error: ' + (data.message || 'Unknown error'));
                                modal.hide();
                            }
                        })
                        .catch(err => {
                            console.error('AJAX Error:', err);
                            alert('Failed to load archive data.');
                            modal.hide();
                        });
                });
            });

            function populateModal(data) {
                const pathPrefix = '';
                
                // 1. Medals (Updated: Lexicographical Sort - Gold > Silver > Bronze)
                const medalBody = document.querySelector('#tableMedals tbody');
                if (data.medals && data.medals.length > 0) {
                    // Apply Sort: Gold -> Silver -> Bronze
                    data.medals.sort((a, b) => {
                        const goldDiff = parseInt(b.gold) - parseInt(a.gold);
                        if (goldDiff !== 0) return goldDiff;
                        
                        const silverDiff = parseInt(b.silver) - parseInt(a.silver);
                        if (silverDiff !== 0) return silverDiff;
                        
                        return parseInt(b.bronze) - parseInt(a.bronze);
                    });

                    medalBody.innerHTML = data.medals.map((m, i) => {
                        let rawLogo = m.logo_url || 'images/default_avatar.png';
                        rawLogo = rawLogo.replace('../', '');
                        let logoSrc = pathPrefix + rawLogo;
                        
                        // Top 3 Styling
                        let rankDisplay = `#${i+1}`;
                        if (i === 0) rankDisplay = '<i class="fas fa-trophy text-warning"></i>';
                        if (i === 1) rankDisplay = '<i class="fas fa-medal text-secondary"></i>';
                        if (i === 2) rankDisplay = '<i class="fas fa-medal" style="color: #cd7f32;"></i>';

                        return `<tr>
                            <td class="text-center fw-bold fs-5">${rankDisplay}</td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <img src="${logoSrc}" style="width: 40px; height: 40px; object-fit: cover; border-radius: 50%; margin-right: 15px; border: 1px solid #dee2e6;" onerror="this.src='${pathPrefix}images/default_avatar.png'">
                                    <span class="fw-bold text-dark">${m.college_name}</span>
                                </div>
                            </td>
                            <td class="text-center fw-bold text-warning fs-5">${m.gold}</td>
                            <td class="text-center fw-bold text-secondary fs-5">${m.silver}</td>
                            <td class="text-center fw-bold fs-5" style="color: #cd7f32;">${m.bronze}</td>
                            <td class="text-center fw-bold text-primary fs-5">${m.total}</td>
                        </tr>`;
                    }).join('');
                } else {
                    medalBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-5">No medal data available.</td></tr>';
                }

                // 2. Events (UPDATED: Shows College Name + Medal Count)
                const eventsBody = document.getElementById('eventsTableBody');
                if (data.events && data.events.length > 0) {
                    eventsBody.innerHTML = data.events.map(ev => {
                        // Helper to format "College (Count)"
                        const fmt = (name, count) => {
                            if (!name) return '-';
                            return count > 0 ? `${name} <span class="text-dark">(${count})</span>` : name;
                        };

                        return `
                        <tr>
                            <td>
                                <div class="fw-bold text-dark">${ev.game_name}</div>
                                <div class="small text-primary">${ev.event_name}</div>
                                <div class="small text-muted fst-italic">${ev.category_name || ''}</div>
                            </td>
                            <td class="text-warning fw-bold small">${fmt(ev.gold_winner, ev.gold_count)}</td>
                            <td class="text-secondary fw-bold small">${fmt(ev.silver_winner, ev.silver_count)}</td>
                            <td class="text-muted fw-bold small" style="color:#cd7f32 !important;">${fmt(ev.bronze_winner, ev.bronze_count)}</td>
                        </tr>`;
                    }).join('');
                } else { 
                    eventsBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-5">No events found.</td></tr>'; 
                }

               // 3. Teams (Updated: Removed box around Code)
                const teamsBody = document.getElementById('teamsTableBody');
                if (data.teams && data.teams.length > 0) {
                    teamsBody.innerHTML = data.teams.map(t => {
                        let rawLogo = t.logo_url || 'images/default_avatar.png'; 
                        rawLogo = rawLogo.replace('../', ''); 
                        let logoSrc = pathPrefix + rawLogo;
                        
                        return `<tr>
                            <td>
                                <img src="${logoSrc}" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover; border: 1px solid #dee2e6;" onerror="this.src='${pathPrefix}images/default_avatar.png'">
                            </td>
                            <td><span class="fw-bold text-dark">${t.college_name}</span></td>
                            
                            <td><span class="fw-bold text-dark">${t.college_code}</span></td>
                            
                            <td>${t.team_manager || '<span class="text-muted">—</span>'}</td>
                            <td class="text-muted fst-italic small">${t.slogan || ''}</td>
                        </tr>`;
                    }).join('');
                } else { 
                    teamsBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-5">No teams recorded.</td></tr>'; 
                }

                // 4. Officials (Updated: Text Only for Role)
                const officialsBody = document.getElementById('officialsTableBody');
                if (data.officials && data.officials.length > 0) {
                    // Sort: Sports Director first
                    data.officials.sort((a, b) => {
                        if (a.role === 'Sports Director' && b.role !== 'Sports Director') return -1;
                        if (a.role !== 'Sports Director' && b.role === 'Sports Director') return 1;
                        return 0; 
                    });
                    
                    officialsBody.innerHTML = data.officials.map(o => {
                        // REMOVED: const badgeClass logic
                        
                        return `
                        <tr>
                            <td><span class="fw-bold text-dark">${o.full_name}</span></td>
                            <td class="text-dark">${o.role}</td>
                            <td><span class="text-muted small">${o.email}</span></td>
                        </tr>`;
                    }).join('');
                } else { 
                    officialsBody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-5">No officials data.</td></tr>'; 
                }

                // 5. Visual Reports (CHARTS)
                if (data.stats && data.stats.charts) {
                    renderCharts(data.stats.charts);
                }
            }

            function renderCharts(chartData) {
                // Destroy old charts if exist to prevent overlay
                if (barChartInstance) barChartInstance.destroy();
                if (pieChartInstance) pieChartInstance.destroy();

                // Bar Chart
                if (chartData.bar && document.getElementById('archiveBarChart')) {
                    const ctxBar = document.getElementById('archiveBarChart').getContext('2d');
                    barChartInstance = new Chart(ctxBar, {
                        type: 'bar',
                        data: {
                            labels: chartData.bar.labels,
                            datasets: [
                                { label: 'Gold', data: chartData.bar.gold, backgroundColor: '#FFD700', borderColor: '#e0c000', borderWidth: 1 },
                                { label: 'Silver', data: chartData.bar.silver, backgroundColor: '#C0C0C0', borderColor: '#a0a0a0', borderWidth: 1 },
                                { label: 'Bronze', data: chartData.bar.bronze, backgroundColor: '#CD7F32', borderColor: '#a05a2c', borderWidth: 1 }
                            ]
                        },
                        options: { responsive: true, maintainAspectRatio: false }
                    });
                }

                // Pie Chart
                if (chartData.pie && document.getElementById('archivePieChart')) {
                    const ctxPie = document.getElementById('archivePieChart').getContext('2d');
                    pieChartInstance = new Chart(ctxPie, {
                        type: 'doughnut',
                        data: {
                            labels: chartData.pie.labels,
                            datasets: [{
                                data: chartData.pie.data,
                                backgroundColor: ['#3498db', '#e74c3c', '#2ecc71', '#f1c40f', '#9b59b6', '#34495e'],
                                hoverOffset: 4
                            }]
                        },
                        options: { 
                            responsive: true, 
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { position: 'bottom' }
                            }
                        }
                    });
                }
            }

                 // ==========================================
        // 2. REAL-TIME BADGE UPDATER
        // ==========================================
        function updateSidebarBadges() {
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
        
        });

        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => { new bootstrap.Alert(alert).close(); });
        }, 5000);
    </script>
</body>
</html>