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
            
            // Combine the Event Name and Academic Year
            $raw_name = trim($_POST['season_name'] ?? '');
            $acad_year = trim($_POST['season_year'] ?? '');
            $season_name = $raw_name . ' ' . $acad_year; 
            
            if (empty($raw_name)) {
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
                    $officials_data = $conn->query("SELECT full_name, role, email FROM users WHERE role IN ('Sports Director', 'Tournament Manager') ORDER BY FIELD(role, 'Sports Director', 'Tournament Manager'), full_name")->fetch_all(MYSQLI_ASSOC);

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
                    $stmt_arch = $conn->prepare("INSERT INTO archived_seasons (season_name, archived_by_user_id, medal_standing_json, events_json, teams_json, officials_json, stats_json) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    
                    $json_medals = json_encode($medal_tally);
                    $json_events = json_encode($events_structure);
                    $json_teams = json_encode($teams_data);
                    $json_officials = json_encode($officials_data);
                    $json_stats = json_encode($stats);

                    // FIX: Changed "sissssss" to "sisssss" (7 characters) 
                    // and completely removed $json_matches from the variables list
                    $stmt_arch->bind_param("sisssss", $season_name, $current_user_id, $json_medals, $json_events, $json_teams, $json_officials, $json_stats);
                    $stmt_arch->execute();
                    $stmt_arch->close();

                    // --- NEW: AUTOMATIC SYSTEM RESET AFTER ARCHIVING ---
                    $conn->query("DELETE FROM tournament_manager_assignments");
                    $conn->query("DELETE FROM categories");
                    $conn->query("DELETE FROM game_events");
                    $conn->query("DELETE FROM games");
                    // ---------------------------------------------------

                    $conn->commit();
                    $alert_message = "SUCCESS: Season '$season_name' archived and active system data has been reset.";
                    $alert_type = "success";
                } catch (Exception $e) {
                    $conn->rollback();
                    $alert_message = "ERROR: Archiving failed. " . $e->getMessage();
                    $alert_type = "danger";
                }
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
    <title>Season Archives - Sports Director Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ========================================
           1. CORE LAYOUT & NAVIGATION
           ======================================== */
        :root { --sidebar-width: 260px; --header-height: 82px; --bg-light: #F8F9FA; --accent-color: #1abc9c; }
        body { background-color: var(--bg-light); margin: 0; padding: 0; min-height: 100vh; font-family: 'Inter', sans-serif; display: flex; flex-direction: column; }
        
        .navbar { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; height: var(--header-height); position: fixed; top: 0; left: 0; right: 0; z-index: 1050; padding: 1rem 1.5rem; }
        .navbar-brand img { height: 50px; width: 48px; object-fit: contain; }
        
        .sidebar { width: var(--sidebar-width); position: fixed; top: var(--header-height); left: 0; height: calc(100vh - var(--header-height)); background: #2c3e50; color: white; z-index: 1040; transition: width 0.3s ease; overflow-y: auto; }
        .sidebar-nav { padding: 20px 0; }
        .sidebar-nav .nav-link { color: rgba(255, 255, 255, 0.7); font-size: 1.05rem; padding: 12px 25px; transition: 0.3s; display: flex; align-items: center; text-decoration: none; border-left: 5px solid transparent; }
        .sidebar-nav .nav-link:hover { color: white; background: rgba(255, 255, 255, 0.05); border-left-color: var(--accent-color); }
        .sidebar-nav .nav-link.active { color: white; background: rgba(255, 255, 255, 0.1); border-left-color: #3498db; font-weight: 600; }
        .sidebar-nav .nav-title { padding: 15px 25px 5px; font-size: 0.75rem; font-weight: 700; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px; }
        
        .main-content { flex: 1 0 auto; padding: 30px; margin-top: var(--header-height); margin-left: var(--sidebar-width); transition: margin-left 0.3s ease; min-height: calc(100vh - var(--header-height)); }
        
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; }
        .user-dropdown .dropdown-toggle img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; margin-right: 10px; }

        /* ========================================
           2. PAGE SPECIFIC (ARCHIVES DASHBOARD)
           ======================================== */
        .page-header { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.08); margin-bottom: 2rem; border-left: 5px solid var(--accent-color); }
        .page-header .section-title { font-family: 'Poppins', sans-serif; font-weight: 800; font-size: 2.2rem; color: #2c3e50; margin-bottom: 0.5rem; display: flex; align-items: center; }
        .page-header .section-title i { background: linear-gradient(135deg, var(--accent-color) 0%, #16a085 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin-right: 10px; }
        .page-header .subtitle { font-size: 1rem; color: #6c757d; }

        .nav-tabs { border: none; background: white; padding: 0.5rem; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); margin-bottom: 2rem; display: flex; gap: 0.5rem; }
        .nav-tabs .nav-link { border: none; border-radius: 8px; padding: 1rem 1.5rem; font-weight: 600; color: #64748b; transition: all 0.3s ease; display: flex; align-items: center; gap: 0.5rem; }
        .nav-tabs .nav-link:hover { background: #f1f5f9; color: #333; transform: translateY(-2px); }
        .nav-tabs .nav-link.active { background: linear-gradient(135deg, #3498db 0%, #2980b9 100%); color: white; box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3); }
        
        /* ========================================
           MODERN ROW-CARD TABLE STYLES (CLEANED)
           ======================================== */
        .table-card { background: transparent; border: none; box-shadow: none; border-radius: 0; }
        .table-card table { width: 100%; border-collapse: separate; border-spacing: 0 12px; text-align: left; }
        .table-card thead th { padding: 0 1.5rem 0.5rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: #6b7280; letter-spacing: 0.05em; border-bottom: none; background: transparent; }
        .table-card tbody tr { background-color: #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.04); transition: all 0.2s ease; }
        .table-card tbody tr:hover { transform: translateY(-3px); box-shadow: 0 8px 15px rgba(0,0,0,0.08); }
        .table-card td { padding: 1.25rem 1.5rem; border: none; border-top: 1px solid #f3f4f6; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
        .table-card td:first-child { border-left: 1px solid #f3f4f6; border-top-left-radius: 12px; border-bottom-left-radius: 12px; }
        .table-card td:last-child { border-right: 1px solid #f3f4f6; border-top-right-radius: 12px; border-bottom-right-radius: 12px; }

        .season-name { font-weight: 700; color: #111827; font-size: 1.05rem; }
        .date-archived { display: flex; align-items: center; gap: 0.5rem; color: #6b7280; font-size: 0.85rem;}

        /* UPDATED: champion-cell now prevents overflow on mobile */
        .champion-cell { display: flex; align-items: center; gap: 0.75rem; font-weight: 600; color: #111827; font-size: 1.05rem; flex-wrap: wrap; min-width: 0; }
        .logo-circle { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; border: 1px solid #e5e7eb; background: #fff; flex-shrink: 0; }
        
        .stats-cell { text-align: center; }
        .stat-number { display: block; font-size: 1.1rem; font-weight: 700; color: #2563eb; line-height: 1; }
        .stat-label { font-size: 0.65rem; font-weight: 600; text-transform: uppercase; color: #9ca3af; }
        .divider { height: 1px; background: #e5e7eb; margin: 6px auto; width: 30px; }
        
        .table-footer { padding: 1rem 0; background: transparent; display: flex; justify-content: space-between; align-items: center; border-top: none; }
        .table-footer-text { color: #6b7280; font-size: 0.875rem; }
        
        .btn-view-report { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1.25rem; font-size: 0.875rem; font-weight: 600; border-radius: 0.5rem; transition: all 0.2s; border: 1px solid #3b82f6; color: #3b82f6; background: transparent; cursor: pointer; text-decoration: none;}
        .btn-view-report:hover { background: #3b82f6; color: white; box-shadow: 0 4px 6px rgba(59, 130, 246, 0.2); }

        .action-card { background: white; border-radius: 12px; padding: 3rem 1.5rem; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; border-top: 5px solid; transition: all 0.3s ease; }
        .action-card.archive-action { border-top-color: #3498db; }
        .action-card.reset-action { border-top-color: #ef4444; background: linear-gradient(135deg, #fff 0%, #fef2f2 100%); }
        .action-icon { font-size: 4rem; margin-bottom: 1.5rem; opacity: 0.9; }
        .action-card h3 { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; }
        .action-card p { font-size: 1rem; color: #6c757d; margin-bottom: 1.5rem; }

        .empty-state { text-align: center; padding: 3rem 1rem; color: #6c757d; }
        .empty-state i { font-size: 4rem; margin-bottom: 1rem; color: #adb5bd; }
        .empty-state h5 { font-size: 1.25rem; font-weight: 600; color: #495057; }

        /* ========================================
           3. FOOTER
           ======================================== */
        footer { flex-shrink: 0; box-shadow: 0 -2px 10px rgba(0,0,0,0.1); padding-left: var(--sidebar-width); transition: padding-left 0.3s ease; position: relative; z-index: 1041; }
        .footer-main { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%); color: rgba(255,255,255,0.7); padding: 3rem 0 2rem 0; }
        .footer-main .footer-logo-group { display: flex; align-items: center; gap: 12px; margin-bottom: 1rem; }
        .footer-main .footer-logo-group img { height: 50px; width: 50px; object-fit: contain; }
        .footer-main h5 { margin: 0; font-size: 1.1rem; font-weight: 700; color: #fff; }
        .footer-main p { font-size: 0.9rem; max-width: 400px; }
        .footer-main h6 { font-family: 'Poppins', sans-serif; color: #fff; font-weight: 600; margin-bottom: 1rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .footer-main .footer-links { list-style: none; padding: 0; }
        .footer-main .footer-links li { margin-bottom: 0.5rem; }
        .footer-main .footer-links a { text-decoration: none; color: rgba(255,255,255,0.7); transition: 0.3s; }
        .footer-main .footer-links a:hover { color: #fff; padding-left: 5px; }
        .footer-bottom { border-top: 1px solid rgba(255,255,255,0.1); padding-top: 1.5rem; margin-top: 2rem; text-align: center; font-size: 0.85rem; }

        /* ========================================
           4. MOBILE RESPONSIVENESS
           ======================================== */

        /* --- SIDEBAR BACKDROP (NEW) --- */
        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            z-index: 1039;
        }
        .sidebar-backdrop.show { display: block; }

        @media (max-width: 991.98px) {
            /* 1. COMPACT NAVBAR & LAYOUT */
            .navbar {
                padding: 0.5rem 1rem !important;
                height: 60px !important;
            }
            .navbar > .container-fluid {
                display: flex !important;
                flex-wrap: nowrap !important;
                align-items: center !important;
                justify-content: space-between !important;
                gap: 10px;
            }
            .navbar-toggler {
                order: 1 !important;
                border: 1px solid rgba(255,255,255,0.1);
                padding: 4px 8px;
                font-size: 1.1rem;
                margin-right: 5px !important;
            }
            .navbar-toggler:focus { box-shadow: none; }
            .navbar-brand {
                order: 2 !important;
                margin-right: auto !important;
                display: flex;
                align-items: center;
                flex-grow: 1;
                min-width: 0;
            }
            .navbar-brand img {
                height: 28px !important;
                width: 28px !important;
                margin-right: 8px !important;
                flex-shrink: 0;
            }
            .navbar-brand .lh-sm { min-width: 0; flex-grow: 1; }
            .navbar-brand strong {
                font-size: 0.8rem !important;
                white-space: normal !important;
                line-height: 1.2 !important;
                display: block;
                word-wrap: break-word;
            }
            .navbar-brand small { display: none !important; }
            .user-dropdown {
                order: 3 !important;
                margin-left: 0 !important;
                flex-shrink: 0;
            }
            .user-dropdown .user-name { display: none !important; }
            .user-dropdown .dropdown-toggle { padding: 2px !important; }
            .user-dropdown .dropdown-toggle img {
                width: 30px !important;
                height: 30px !important;
                margin-right: 0 !important;
                border: 1px solid rgba(255,255,255,0.2) !important;
            }
            .user-dropdown .dropdown-toggle i {
                font-size: 26px !important;
                margin: 0 !important;
                color: #fff;
            }

            /* Sidebar */
            .sidebar {
                top: 60px !important;
                bottom: 0 !important;
                left: -260px;
                height: auto !important;
                padding-bottom: 20px;
                transition: left 0.3s ease;
            }
            .sidebar.show { left: 0; }
            .main-content { padding: 15px !important; margin-top: 60px !important; margin-left: 0 !important; }
            footer { padding-left: 0 !important; }

            .page-header { padding: 1.5rem !important; margin-bottom: 1.5rem !important; }
            .page-header .section-title { font-size: 1.5rem !important; }

            /* Tabs full width on mobile */
            .nav-tabs { flex-direction: column; gap: 0.5rem; padding: 0.75rem; }
            .nav-tabs .nav-link { justify-content: center; font-size: 0.9rem; width: 100%; }

            /* Search bar full width */
            .search-container { max-width: 100% !important; }
        }

        /* ========================================
           SECTION 1 FIX: TABLE COLUMNS ON MOBILE
           ======================================== */
        @media (max-width: 767px) {
            /* Hide Date Archived and Games & Events columns */
            .table-card thead th:nth-child(2),
            .table-card td:nth-child(2),
            .table-card thead th:nth-child(4),
            .table-card td:nth-child(4) {
                display: none;
            }

            /* Reduce table cell padding on mobile */
            .table-card td { padding: 1rem; }
            .table-card thead th { padding: 0 1rem 0.5rem; }

            /* Full-width view report button */
            .btn-view-report {
                width: 100%;
                justify-content: center;
                margin-top: 0.5rem;
            }
            .table-card td:last-child { text-align: left !important; }

            /* Champion cell text truncation */
            .champion-cell { font-size: 0.9rem; }
        }

        @media (max-width: 575.98px) {
            .stats-grid { grid-template-columns: 1fr; }
            .action-icon { font-size: 2.5rem; }
            .action-card { padding: 1.5rem 1rem; }
        }

        /* ========================================
           5. NEW REPORT MODAL UI (PURE CSS)
           ======================================== */
        #fullReportModal { padding-right: 0 !important; }
        #fullReportModal .modal-dialog { margin: 0; max-width: 100%; height: 100vh; }
        #fullReportModal .modal-content { height: 100%; border: none; border-radius: 0; }

        /* Close button: smaller margin on tiny screens */
        @media (max-width: 575px) {
            #fullReportModal .btn-close.position-absolute {
                margin: 0.5rem !important;
                padding: 6px !important;
            }
        }

        :root {
            --rep-primary: #1a355b;
            --rep-primary-10: rgba(26,53,91,0.1);
            --rep-primary-05: rgba(26,53,91,0.05);
            --rep-primary-20: rgba(26,53,91,0.2);
            --rep-primary-60: rgba(26,53,91,0.6);
            --rep-primary-70: rgba(26,53,91,0.7);
            --rep-bg-light: #f6f7f8;
            --rep-white: #ffffff;
            --rep-slate-50: #f8fafc;
            --rep-slate-100: #f1f5f9;
            --rep-slate-200: #e2e8f0;
            --rep-slate-400: #94a3b8;
            --rep-slate-500: #64748b;
            --rep-slate-600: #475569;
            --rep-slate-700: #334155;
            --rep-slate-900: #0f172a;
            --rep-amber-50: #fffbeb;
            --rep-amber-100: #fef3c7;
            --rep-amber-400: #fbbf24;
            --rep-amber-700: #b45309;
            --rep-orange-50: #fff7ed;
            --rep-orange-100: #ffedd5;
            --rep-orange-400: #fb923c;
            --rep-orange-700: #c2410c;
            --rep-radius: 0.25rem;
            --rep-radius-lg: 0.5rem;
            --rep-radius-xl: 0.75rem;
        }

        .custom-report-ui {
            background: var(--rep-bg-light);
            font-family: 'DM Sans', sans-serif;
            color: var(--rep-slate-900);
            overflow-y: auto;
            width: 100%;
        }

        .custom-report-ui .rep-container { max-width: 1280px; margin: 0 auto; padding: 2rem 1.5rem; }

        /* Report Header */
        .custom-report-ui .rep-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; border-bottom: 1px solid var(--rep-primary-10); padding-bottom: 1.5rem; margin-bottom: 2rem; }
        .custom-report-ui .rep-header-brand { display: flex; align-items: center; gap: 1rem; }
        .custom-report-ui .rep-brand-icon { background: var(--rep-primary); padding: 0.5rem; border-radius: var(--rep-radius-lg); color: #fff; display: flex; align-items: center; justify-content: center; }
        .custom-report-ui .rep-brand-icon i { font-size: 1.75rem; }
        .custom-report-ui .rep-brand-title { font-size: 1.25rem; font-weight: 900; letter-spacing: 0.05em; color: var(--rep-primary); text-transform: uppercase; }
        .custom-report-ui .rep-brand-sub { font-size: 0.8rem; font-weight: 500; color: var(--rep-slate-500); }
        .custom-report-ui .rep-btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; border-radius: var(--rep-radius-lg); font-weight: 700; font-size: 0.8rem; cursor: pointer; transition: 0.2s; border: none; text-decoration: none; }
        .custom-report-ui .rep-btn-primary { background: var(--rep-primary); color: #fff; }
        .custom-report-ui .rep-btn-primary:hover { opacity: 0.9; }

        /* Report Identity */
        .custom-report-ui .rep-identity { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 1rem; margin-bottom: 2rem; }
        .custom-report-ui .rep-title { font-size: 2.5rem; font-weight: 900; color: var(--rep-slate-900); line-height: 1; margin-bottom: 0.5rem; }
        .custom-report-ui .rep-meta { display: flex; align-items: center; gap: 1.5rem; color: var(--rep-slate-500); flex-wrap: wrap; }
        .custom-report-ui .rep-meta span { display: inline-flex; align-items: center; gap: 0.25rem; font-weight: 500; font-size: 0.9rem; }
        .custom-report-ui .rep-id-badge { background: var(--rep-primary-05); padding: 0.5rem 1rem; border-radius: var(--rep-radius); border: 1px solid var(--rep-primary-10); }
        .custom-report-ui .rep-id-label { font-size: 0.6rem; text-transform: uppercase; letter-spacing: 0.15em; font-weight: 700; color: var(--rep-primary-60); }
        .custom-report-ui .rep-id-value { font-family: monospace; font-size: 0.85rem; font-weight: 700; color: var(--rep-primary); }

        /* SECTION 3 FIX: Report title smaller on mobile */
        @media (max-width: 575px) {
            .custom-report-ui .rep-title { font-size: 1.6rem; }
            .custom-report-ui .rep-container { padding: 1rem; }
            .custom-report-ui .rep-identity { flex-direction: column; align-items: flex-start; }
        }

        /* Report Metrics */
        .custom-report-ui .rep-metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem; }
        .custom-report-ui .rep-metric-card { background: var(--rep-white); padding: 1.5rem; border-radius: var(--rep-radius-xl); border: 1px solid var(--rep-primary-10); box-shadow: 0 1px 3px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 1.25rem; }
        .custom-report-ui .rep-metric-icon { width: 3.5rem; height: 3.5rem; border-radius: 50%; background: var(--rep-primary-10); display: flex; align-items: center; justify-content: center; color: var(--rep-primary); flex-shrink: 0; }
        .custom-report-ui .rep-metric-icon i { font-size: 1.75rem; }
        .custom-report-ui .rep-metric-label { font-size: 0.8rem; font-weight: 500; color: var(--rep-slate-500); margin-bottom: 0.15rem; }
        .custom-report-ui .rep-metric-value { font-size: 1.875rem; font-weight: 900; color: var(--rep-slate-900); }

        /* SECTION 3 FIX: Metric cards compact on mobile */
        @media (max-width: 575px) {
            .custom-report-ui .rep-metrics-grid {
                grid-template-columns: 1fr;
                gap: 0.75rem;
                margin-bottom: 1.25rem;
            }
            .custom-report-ui .rep-metric-card { padding: 0.75rem 1rem; }
        }

        /* Report Grid & Cards */
        .custom-report-ui .rep-main-grid { display: grid; grid-template-columns: 1fr; gap: 2rem; margin-bottom: 2.5rem; }
        @media (min-width: 1024px) { .custom-report-ui .rep-main-grid { grid-template-columns: 2fr 1fr; } }
        
        .custom-report-ui .rep-card { background: var(--rep-white); border-radius: var(--rep-radius-xl); border: 1px solid var(--rep-primary-10); box-shadow: 0 1px 3px rgba(0,0,0,0.06); overflow: hidden; margin-bottom: 2rem; page-break-inside: avoid; break-inside: avoid; }
        .custom-report-ui .rep-card-header { padding: 1rem 1.5rem; border-bottom: 1px solid var(--rep-slate-100); background: var(--rep-slate-50); display: flex; justify-content: space-between; align-items: center; }
        .custom-report-ui .rep-card-title { font-weight: 700; font-size: 1.1rem; color: var(--rep-primary); }
        .custom-report-ui .rep-card-body { padding: 1.5rem; }

        /* Report Tables */
        .custom-report-ui .rep-table-wrap { overflow-x: auto; }
        .custom-report-ui table { width: 100%; border-collapse: collapse; font-size: 0.875rem; margin: 0; background: transparent; }
        .custom-report-ui thead tr { background: var(--rep-slate-50); }
        .custom-report-ui th { padding: 1rem 1.5rem; border-bottom: 1px solid var(--rep-slate-100); font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--rep-slate-600); text-align: left; }
        .custom-report-ui td { padding: 1rem 1.5rem; border-bottom: 1px solid var(--rep-slate-100); }
        .custom-report-ui th.center, .custom-report-ui td.center { text-align: center; }
        .custom-report-ui th.right, .custom-report-ui td.right { text-align: center; }
        .custom-report-ui td.rank { font-weight: 700; color: var(--rep-primary); font-size: 1.1rem;}
        .custom-report-ui td.name { font-weight: 600; display: flex; align-items: center; gap: 0.75rem; }
        .custom-report-ui td.right { font-weight: 900; color: var(--rep-slate-900); font-size: 1.1rem;}
        
        .custom-report-ui .rep-badge { display: inline-block; padding: 0.3rem 0.8rem; border-radius: var(--rep-radius); font-weight: 700; }
        .custom-report-ui .badge-gold { background: var(--rep-amber-100); color: var(--rep-amber-700); }
        .custom-report-ui .badge-silver { background: var(--rep-slate-100); color: var(--rep-slate-600); }
        .custom-report-ui .badge-bronze { background: var(--rep-orange-100); color: var(--rep-orange-700); }

        /* SECTION 3 FIX: Medal table compact on mobile */
        @media (max-width: 575px) {
            .custom-report-ui th,
            .custom-report-ui td { padding: 0.6rem 0.75rem; font-size: 0.8rem; }
            .custom-report-ui .rep-card-body { padding: 1rem; }
            .custom-report-ui .rep-card-header { padding: 0.75rem 1rem; }
        }

        /* Report Events */
        .custom-report-ui .rep-events-list { display: flex; flex-direction: column; gap: 1.5rem; }
        .custom-report-ui .rep-event-row { display: grid; grid-template-columns: 1fr; gap: 1rem; align-items: center; border-bottom: 1px solid var(--rep-slate-50); padding-bottom: 1rem; }
        @media (min-width: 768px) { .custom-report-ui .rep-event-row { grid-template-columns: 1fr 3fr; } }
        .custom-report-ui .rep-event-row:last-child { border-bottom: none; padding-bottom: 0; }
        .custom-report-ui .rep-event-name { font-weight: 700; color: var(--rep-slate-900); font-size: 0.95rem; }
        .custom-report-ui .rep-event-sub { font-size: 0.75rem; color: var(--rep-slate-500); font-weight: 500; margin-top: 0.2rem; }
        .custom-report-ui .rep-event-medals { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; }
        .custom-report-ui .rep-medal-cell { padding: 0.5rem; border-radius: var(--rep-radius); overflow: hidden; }
        .custom-report-ui .rep-medal-cell.gold { background: var(--rep-amber-50); border: 1px solid var(--rep-amber-100); }
        .custom-report-ui .rep-medal-cell.silver { background: var(--rep-slate-50); border: 1px solid var(--rep-slate-100); }
        .custom-report-ui .rep-medal-cell.bronze { background: var(--rep-orange-50); border: 1px solid var(--rep-orange-100); }
        .custom-report-ui .rep-medal-label { font-size: 0.6rem; font-weight: 900; text-transform: uppercase; margin-bottom: 0.2rem; }
        .custom-report-ui .rep-medal-cell.gold .rep-medal-label { color: var(--rep-amber-700); }
        .custom-report-ui .rep-medal-cell.silver .rep-medal-label { color: var(--rep-slate-500); }
        .custom-report-ui .rep-medal-cell.bronze .rep-medal-label { color: var(--rep-orange-700); }
        .custom-report-ui .rep-medal-team { font-size: 0.75rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--rep-slate-800);}

        /* Report Misc (Officials, Teams, Charts) */
        .custom-report-ui .rep-section-heading { font-weight: 700; font-size: 0.95rem; color: var(--rep-primary); background: var(--rep-slate-50); padding: 0.5rem; border-radius: var(--rep-radius); margin-bottom: 1rem; text-align: center; }
        .custom-report-ui .rep-officials-list { display: flex; flex-direction: column; padding: 1rem; }
        .custom-report-ui .rep-official-row { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 0; border-bottom: 1px solid var(--rep-slate-50); }
        .custom-report-ui .rep-official-row:last-child { border-bottom: none; }
        .custom-report-ui .rep-official-avatar { width: 2.5rem; height: 2.5rem; border-radius: 50%; background: var(--rep-primary-05); display: flex; align-items: center; justify-content: center; color: var(--rep-primary); flex-shrink: 0; }
        .custom-report-ui .rep-official-name { font-size: 0.85rem; font-weight: 700; line-height: 1.2; color: var(--rep-slate-800);}
        .custom-report-ui .rep-official-role { font-size: 0.65rem; color: var(--rep-slate-500); font-weight: 700; text-transform: uppercase; margin-top: 2px;}
        .custom-report-ui .rep-official-email { font-size: 0.65rem; color: var(--rep-slate-400); }
        
        .custom-report-ui .rep-team-tags { display: flex; flex-wrap: wrap; gap: 0.75rem; }

        /* SECTION 3 FIX: Team tags full-width on mobile, half on tablet, full on large desktop sidebar */
        .custom-report-ui .rep-team-tag {
            display: flex; align-items: center; gap: 0.5rem;
            background: var(--rep-slate-50); padding: 0.5rem 0.75rem;
            border-radius: var(--rep-radius-lg); border: 1px solid var(--rep-slate-100);
            width: 100%;
        }
        @media (min-width: 480px) and (max-width: 1023px) {
            .custom-report-ui .rep-team-tag { width: calc(50% - 0.5rem); }
        }
        @media (min-width: 1024px) {
            .custom-report-ui .rep-team-tag { width: 100%; }
        }

        .custom-report-ui .rep-team-logo { width: 2rem; height: 2rem; border-radius: var(--rep-radius); object-fit: cover; border: 1px solid #ddd; background: white;}
        .custom-report-ui .rep-team-name { font-size: 0.75rem; font-weight: 700; color: var(--rep-slate-700); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;}

        /* SECTION 3 FIX: Analytics chart grid single column on mobile */
        @media (max-width: 599px) {
            .custom-report-ui .rep-card-body > div[style*="grid"] {
                grid-template-columns: 1fr !important;
            }
            .chart-container { height: 220px !important; }
        }

        /* Report Footer */
        .custom-report-ui .rep-footer { margin-top: 3rem; padding-top: 2rem; border-top: 1px solid var(--rep-slate-200); display: flex; flex-wrap: wrap; justify-content: space-between; gap: 1rem; color: var(--rep-slate-400); font-size: 0.75rem; font-weight: 500; }
        .custom-report-ui .rep-footer-copy { display: flex; flex-direction: column; gap: 0.25rem; }
        .custom-report-ui .rep-footer-right { display: flex; gap: 2rem; }
        .custom-report-ui .rep-footer-block-title { font-weight: 700; color: var(--rep-slate-500); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem; font-size: 0.7rem; }
        .custom-report-ui .rep-footer-verified { display: inline-flex; align-items: center; gap: 0.25rem; color: var(--rep-primary); font-weight: 700;}
        .custom-report-ui .rep-footer-verified i { font-size: 1rem; }

        /* SECTION 3 FIX: Footer stacks on mobile */
        @media (max-width: 575px) {
            .custom-report-ui .rep-footer { flex-direction: column; }
            .custom-report-ui .rep-footer-right { flex-direction: column; gap: 0.75rem; }
        }

        /* ========================================
           6. PRINT STYLES (Full Data Capture)
           ======================================== */
        @media print {
            @page { margin: 0.5in; }
            body > *:not(#fullReportModal) { display: none !important; }
            html, body { height: auto !important; overflow: visible !important; background: white !important; }
            
            #fullReportModal, #fullReportModal .modal-dialog, #fullReportModal .modal-content, 
            .custom-report-ui, .custom-report-ui .rep-container { 
                position: relative !important; display: block !important; width: 100% !important; 
                height: auto !important; max-height: none !important; overflow: visible !important; 
                border: none !important; box-shadow: none !important; background: white !important; padding: 0 !important;
            }
            .custom-report-ui .rep-header-actions, .btn-close { display: none !important; }
            .custom-report-ui .rep-main-grid { display: block !important; }
            .custom-report-ui .rep-left-col, .custom-report-ui .rep-right-col { display: block !important; width: 100% !important; }
            .custom-report-ui .rep-header { padding-bottom: 0.25rem !important; margin-bottom: 0.5rem !important; }
            .custom-report-ui .rep-title { font-size: 1.5rem !important; margin-bottom: 0.25rem !important; }
            .custom-report-ui .rep-identity { margin-bottom: 0.75rem !important; }
            .custom-report-ui .rep-metrics-grid { gap: 0.5rem !important; margin-bottom: 1rem !important; }
            .custom-report-ui .rep-metric-card { padding: 0.5rem 1rem !important; gap: 0.75rem !important; }
            .custom-report-ui .rep-metric-icon { width: 2.5rem !important; height: 2.5rem !important; }
            .custom-report-ui .rep-metric-icon i { font-size: 1.2rem !important; }
            .custom-report-ui .rep-metric-value { font-size: 1.25rem !important; }
            .custom-report-ui .rep-metric-label { font-size: 0.7rem !important; margin-bottom: 0 !important; }
            .custom-report-ui th, .custom-report-ui td { padding: 0.5rem !important; }
            .custom-report-ui .rep-card { border: 1px solid #ddd !important; margin-bottom: 1.5rem !important; box-shadow: none !important; }
            .custom-report-ui .rep-card-header { page-break-after: avoid !important; break-after: avoid !important; padding: 0.75rem !important; }
            .custom-report-ui table tr { page-break-inside: avoid !important; break-inside: avoid !important; }
            .pdf-page-break { page-break-before: always !important; break-before: page !important; }
            .custom-report-ui .rep-table-wrap { overflow: visible !important; }
            canvas { max-width: 100% !important; }
            .chart-container { height: auto !important; min-height: 250px !important; page-break-inside: avoid !important; }
            /* Hide backdrop on print */
            .sidebar-backdrop { display: none !important; }
        }
    </style>
</head>
<body>
    
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="sd/sports_director_dashboard.php">
                <img src="images/PIT.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
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
        
            <div class="page-header mb-4">
                <h1 class="section-title">
                    </i>Manage Archives
                </h1>
                <p class="subtitle">Manage historical data and prepare for new seasons</p>
            </div>

            <?php if ($alert_message): ?>
                <div class="alert alert-<?= $alert_type ?> alert-dismissible fade show">
                    <i class="fas fa-<?= $alert_type === 'success' ? 'check-circle' : ($alert_type === 'danger' ? 'exclamation-triangle' : 'info-circle') ?> me-2"></i>
                    <div><?= htmlspecialchars($alert_message) ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
                
                <ul class="nav nav-tabs mb-0" id="archiveTabs" role="tablist" style="margin-bottom: 0 !important;">
                    <li class="nav-item">
                        <button class="nav-link active" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button">
                            <i class="fas fa-folder-open text-warning"></i>
                            History
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="archive-tab" data-bs-toggle="tab" data-bs-target="#archive" type="button">
                            <i class="fas fa-save text-primary"></i>
                            Create Archive
                        </button>
                    </li>
                </ul>

                <div class="search-container position-relative mt-3 mt-md-0" style="width: 100%; max-width: 300px;">
                    <i class="fas fa-search position-absolute text-muted" style="left: 15px; top: 50%; transform: translateY(-50%);"></i>
                    <input type="text" id="archiveSearch" class="form-control border-0" placeholder="Search seasons or dates..." 
                           style="padding-left: 40px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); height: 45px; background: white;">
                </div>

            </div>

            <div class="tab-content">
                
                <div class="tab-pane fade show active" id="history">
                    <?php if (empty($archives)): ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open"></i>
                            <h5>No Archived Seasons Yet</h5>
                            <p>Once you archive a season, it will appear here for future reference and analysis.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-card">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Season Name</th>
                                        <th>Date Archived</th>
                                        <th>Season Champion</th>
                                        <th style="text-align: center;">Games & Events</th>
                                        <th style="text-align: center;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
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
                                    <tr>
                                        <td><span class="season-name"><?= htmlspecialchars($arch['season_name']) ?></span></td>
                                        <td>
                                            <div class="date-archived">
                                                <i class="fas fa-calendar-alt"></i>
                                                <?= date('F d, Y', strtotime($arch['archived_at'])) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="champion-cell">
                                                <?php if (!empty($champion_logo)): ?>
                                                    <img src="<?= htmlspecialchars($champion_logo) ?>" alt="Logo" class="logo-circle">
                                                <?php else: ?>
                                                    <div class="logo-circle d-flex align-items-center justify-content-center bg-light" style="border-radius: 50%;">
                                                        <i class="fas fa-trophy text-warning" style="font-size: 14px;"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <?= htmlspecialchars($champion) ?>
                                            </div>
                                        </td>
                                        <td class="stats-cell">
                                            <span class="stat-number"><?= $val_games ?></span>
                                            <span class="stat-label">Games</span>
                                            <div class="divider"></div>
                                            <span class="stat-number"><?= $val_events ?></span>
                                            <span class="stat-label">Events</span>
                                        </td>
                                        <td style="text-align: right;">
                                            <button class="btn-view-report view-archive-btn" 
                                                data-id="<?= $arch['archive_id'] ?>" 
                                                data-season="<?= htmlspecialchars($arch['season_name']) ?>">
                                                View Full Report
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="table-footer">
                                <div class="table-footer-text">
                                    Showing <strong><?= count($archives) ?></strong> archived seasons
                                </div>
                            </div>
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
                                    <i class="fas fa-save me-2"></i>Archive Now
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
                        <div class="alert alert-warning border-0 mb-4 small">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Important:</strong> Archiving will save all current results and <u>automatically reset the active system</u> (deleting current events/results) to prepare for the next season.
                        </div>

                        <div class="row mb-4">
                            <div class="col-md-7">
                                <label class="form-label fw-bold">Event Name</label>
                                <input type="text" name="season_name" class="form-control form-control-lg" placeholder="e.g. Siglakas" required>
                            </div>
                            <div class="col-md-5 mt-3 mt-md-0">
                                <label class="form-label fw-bold">Academic Year</label>
                                <select name="season_year" class="form-select form-control-lg" required>
                                    <?php 
                                        $currY = date("Y");
                                        // Generates a 4-year sliding window dynamically
                                        echo "<option value='".($currY-2)."-".($currY-1)."'>".($currY-2)."-".($currY-1)."</option>";
                                        echo "<option value='".($currY-1)."-".$currY."'>".($currY-1)."-".$currY."</option>";
                                        echo "<option value='".$currY."-".($currY+1)."' selected>".$currY."-".($currY+1)."</option>";
                                        echo "<option value='".($currY+1)."-".($currY+2)."'>".($currY+1)."-".($currY+2)."</option>";
                                    ?>
                                </select>
                            </div>
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

    <div class="modal fade" id="fullReportModal" tabindex="-1">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content custom-report-ui">
                
                <button type="button" class="btn-close position-absolute top-0 end-0 m-4" data-bs-dismiss="modal" style="z-index: 1060; background-color: white; padding: 10px; border-radius: 50%; opacity: 0.8;"></button>

                <div class="rep-container">
                    <div class="rep-header">
                        
                        <div class="rep-header-brand">
                            <div class="rep-brand-icon" style="background: transparent; padding: 0;">
                                <img src="images/PIT.png" alt="PIT Logo" style="width: 50px; height: 50px; object-fit: contain;"> 
                            </div>
                            <div>
                                <div class="rep-brand-title">Siglakas Result Reports</div>
                                <div class="rep-brand-sub">Official Reporting Dashboard</div>
                            </div>
                        </div>

                        <div class="rep-header-actions">
                            <button class="rep-btn rep-btn-primary" onclick="printReport()">
                                <i class="fas fa-print me-1"></i> Print Report 
                            </button>
                        </div>
                        
                    </div>

                    <div class="rep-identity">
                        <div>
                            <div class="rep-title" id="report-season-name">Loading Report...</div>
                            <div class="rep-meta">
                                <span><i class="fas fa-calendar-alt"></i> <span id="report-date">...</span></span>
                                <span><i class="fas fa-map-marker-alt"></i> Palompon Institute of Technology</span>
                            </div>
                        </div>
                        <div class="rep-id-badge">
                            <div class="rep-id-label">Report ID</div>
                            <div class="rep-id-value" id="report-id">TRN-...</div>
                        </div>
                    </div>

                    <div class="rep-metrics-grid">
        
                        <div class="rep-metric-card">
                            <div class="rep-metric-icon"><i class="fas fa-users" style="font-size: 28px;"></i></div>
                            <div>
                                <div class="rep-metric-label">Total Teams</div>
                                <div class="rep-metric-value" id="stat-total-teams">0</div>
                            </div>
                        </div>
                        
                        <div class="rep-metric-card">
                            <div class="rep-metric-icon"><i class="fas fa-calendar-check" style="font-size: 28px;"></i></div>
                            <div>
                                <div class="rep-metric-label">Total Events</div>
                                <div class="rep-metric-value" id="stat-total-events">0</div>
                            </div>
                        </div>
                        
                        <div class="rep-metric-card">
                            <div class="rep-metric-icon"><i class="fas fa-award" style="font-size: 28px;"></i></div>
                            <div>
                                <div class="rep-metric-label">Medals Awarded</div>
                                <div class="rep-metric-value" id="stat-total-medals">0</div>
                            </div>
                        </div>
                        
                    </div>

                    <div class="rep-main-grid">

                        <div class="rep-left-col">
                            
                            <div class="rep-card">
                                <div class="rep-card-header">
                                    <div class="rep-card-title">Final Medal Tally</div>
                                </div>
                                <div class="rep-table-wrap">
                                    <table id="tableMedals">
                                        <thead>
                                            <tr>
                                                <th class="center" style="width: 60px;">Rank</th>
                                                <th>College / Team</th>
                                                <th class="center">Gold</th>
                                                <th class="center">Silver</th>
                                                <th class="center">Bronze</th>
                                                <th class="right">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="rep-card">
                                <div class="rep-card-header">
                                    <div class="rep-card-title">Event Results</div>
                                </div>
                                <div class="rep-card-body">
                                    <div class="rep-events-list" id="eventsContainer">
                                        </div>
                                </div>
                            </div>
                            
                            <div class="rep-card">
                                <div class="rep-card-header">
                                    <div class="rep-card-title">Visual Analytics</div>
                                </div>
                                <div class="rep-card-body" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
                                    
                                    <div>
                                        <div class="rep-section-heading" style="text-align:center;">Medal Distribution</div>
                                        <div class="chart-container" style="position: relative; height: 350px; width: 100%;">
                                            <canvas id="archiveBarChart"></canvas>
                                        </div>
                                    </div>

                                    <div>
                                        <div class="rep-section-heading" style="text-align:center;">Event Distribution</div>
                                        <div class="chart-container" style="position: relative; height: 350px; width: 100%;">
                                            <canvas id="archivePieChart"></canvas>
                                        </div>
                                    </div>

                                </div>
                            </div>

                        </div> <div class="rep-right-col">
                            
                            <div class="rep-card">
                                <div class="rep-card-header">
                                    <div class="rep-card-title">Participating Teams</div>
                                </div>
                                <div class="rep-card-body">
                                    <div class="rep-team-tags" id="teamsContainer">
                                        </div>
                                </div>
                            </div>

                            <div class="rep-card">
                                <div class="rep-card-header">
                                    <div class="rep-card-title">Tournament Officials</div>
                                </div>
                                <div class="rep-officials-list" id="officialsContainer">
                                    </div>
                            </div>

                        </div> 
                    </div>

                        

                           

                    <div class="rep-footer">
                        <div class="rep-footer-copy">
                            <p>© <?php echo date('Y'); ?> PIT SIGLAKAS MEDAL TALLY.</p>
                            <p>This report was automatically generated and is considered an official record.</p>
                        </div>
                        <div class="rep-footer-right">
                            <div>
                                <div class="rep-footer-block-title">Confidentiality</div>
                                <p>Internal documentation only</p>
                            </div>
                            <div>
                                <div class="rep-footer-block-title">Verification</div>
                                <p class="rep-footer-verified">
                                    <span class="material-symbols-outlined">verified</span> Digital Signature Verified
                                </p>
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
            const officialsBody = document.getElementById('officialsContainer'); // Updated to your new container ID
            
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
                    
                    // 1. Use the new ID for the title
                    document.getElementById('report-season-name').innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span> Loading ${season}...`;
                    
                    // 2. Clear previous data using the NEW container IDs
                    const oBody = document.getElementById('officialsContainer');
                    const eBody = document.getElementById('eventsContainer');
                    const mBody = document.querySelector('#tableMedals tbody');
                    const tBody = document.getElementById('teamsContainer');
                    
                    if(oBody) oBody.innerHTML = '';
                    if(eBody) eBody.innerHTML = '';
                    if(mBody) mBody.innerHTML = '';
                    if(tBody) tBody.innerHTML = '';
                    
                    modal.show();
                    
                    fetch(`manage_archives.php?ajax_fetch_report=1&archive_id=${archiveId}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // 3. The new populateModal function handles all the title formatting now!
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
                
                // --- Top Headers & Stats ---
                document.getElementById('report-season-name').textContent = data.season_name;
                document.getElementById('report-date').textContent = new Date(data.archived_at).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'});
                
                // Format ID
                let rawDate = data.archived_at || '';
                document.getElementById('report-id').textContent = 'TRN-' + rawDate.replace(/[-:\s]/g, '').substring(0, 14);
                
                // Stats
                let totalMedalsCount = 0;
                if (data.medals) data.medals.forEach(m => totalMedalsCount += parseInt(m.total || 0));
                
                document.getElementById('stat-total-teams').textContent = data.teams ? data.teams.length : 0;
                document.getElementById('stat-total-events').textContent = data.stats.total_events || 0;
                document.getElementById('stat-total-medals').textContent = totalMedalsCount;
                
                // --- 1. Medals Data ---
                const medalBody = document.querySelector('#tableMedals tbody');
                if (data.medals && data.medals.length > 0) {
                    data.medals.sort((a, b) => {
                        const goldDiff = parseInt(b.gold) - parseInt(a.gold);
                        if (goldDiff !== 0) return goldDiff;
                        const silverDiff = parseInt(b.silver) - parseInt(a.silver);
                        if (silverDiff !== 0) return silverDiff;
                        return parseInt(b.bronze) - parseInt(a.bronze);
                    });

                    medalBody.innerHTML = data.medals.map((m, i) => {
                        let logoSrc = pathPrefix + (m.logo_url || 'images/default_avatar.png').replace('../', '');
                        let rankDisplay = String(i + 1).padStart(2, '0');
                        return `<tr>
                            <td class="rank center">${rankDisplay}</td>
                            <td class="name"><img src="${logoSrc}" style="width:24px;height:24px;border-radius:50%;object-fit:cover;"> ${m.college_name}</td>
                            <td class="center"><span class="rep-badge badge-gold">${m.gold}</span></td>
                            <td class="center"><span class="rep-badge badge-silver">${m.silver}</span></td>
                            <td class="center"><span class="rep-badge badge-bronze">${m.bronze}</span></td>
                            <td class="right">${m.total}</td>
                        </tr>`;
                    }).join('');
                } else {
                    medalBody.innerHTML = '<tr><td colspan="6" class="center">No medal data available.</td></tr>';
                }

                // --- 2. Events Data ---
                const eventsContainer = document.getElementById('eventsContainer');
                if (data.events && data.events.length > 0) {
                    eventsContainer.innerHTML = data.events.map(ev => {
                        return `
                        <div class="rep-event-row">
                            <div>
                                <div class="rep-event-name">${ev.game_name}</div>
                                <div class="rep-event-sub">${ev.event_name} ${ev.category_name ? `- ${ev.category_name}` : ''}</div>
                            </div>
                            <div class="rep-event-medals">
                                <div class="rep-medal-cell gold">
                                    <div class="rep-medal-label">Gold</div>
                                    <div class="rep-medal-team" title="${ev.gold_winner}">${ev.gold_winner || '-'}</div>
                                </div>
                                <div class="rep-medal-cell silver">
                                    <div class="rep-medal-label">Silver</div>
                                    <div class="rep-medal-team" title="${ev.silver_winner}">${ev.silver_winner || '-'}</div>
                                </div>
                                <div class="rep-medal-cell bronze">
                                    <div class="rep-medal-label">Bronze</div>
                                    <div class="rep-medal-team" title="${ev.bronze_winner}">${ev.bronze_winner || '-'}</div>
                                </div>
                            </div>
                        </div>`;
                    }).join('');
                } else {
                    eventsContainer.innerHTML = '<p style="text-align:center;color:#64748b;">No events found.</p>';
                }

                // --- 3. Teams Data ---
                const teamsContainer = document.getElementById('teamsContainer');
                if (data.teams && data.teams.length > 0) {
                    teamsContainer.innerHTML = data.teams.map(t => {
                        let logoSrc = pathPrefix + (t.logo_url || 'images/default_avatar.png').replace('../', '');
                        return `
                        <div class="rep-team-tag">
                            <img src="${logoSrc}" class="rep-team-logo" onerror="this.src='images/default_avatar.png'">
                            <span class="rep-team-name">${t.college_name}</span>
                        </div>`;
                    }).join('');
                } else {
                    teamsContainer.innerHTML = '<p style="color:#64748b;font-size:0.8rem;">No teams recorded.</p>';
                }

                // --- 4. Officials Data ---
                const officialsContainer = document.getElementById('officialsContainer');
                if (data.officials && data.officials.length > 0) {
                    data.officials.sort((a, b) => {
                        if (a.role === 'Sports Director' && b.role !== 'Sports Director') return -1;
                        if (a.role !== 'Sports Director' && b.role === 'Sports Director') return 1;
                        return 0; 
                    });
                    
                    officialsContainer.innerHTML = data.officials.map(o => {
                        // UPDATED: Use FontAwesome Profile Icons instead of Material Symbols
                        let icon = o.role === 'Sports Director' ? 'fas fa-user-shield' : 'fas fa-user';
                        
                        return `
                        <div class="rep-official-row">
                            <div class="rep-official-avatar">
                                <i class="${icon}"></i>
                            </div>
                            <div>
                                <div class="rep-official-name">${o.full_name}</div>
                                <div class="rep-official-role">${o.role}</div>
                                <div class="rep-official-email">${o.email}</div>
                            </div>
                        </div>`;
                    }).join('');
                } else {
                    officialsContainer.innerHTML = '<p style="text-align:center;color:#64748b;padding:1rem;">No officials data.</p>';
                }

                // --- 5. Visual Reports (CHARTS) ---
                if (data.stats && data.stats.charts) {
                    setTimeout(() => { renderCharts(data.stats.charts); }, 150);
                }
            }

            function renderCharts(chartData) {
                // Destroy old charts to prevent overlay glitches
                if (barChartInstance) { barChartInstance.destroy(); barChartInstance = null; }
                if (pieChartInstance) { pieChartInstance.destroy(); pieChartInstance = null; }

                // OLD CLASSIC BAR CHART
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
                        options: {
                            animation: false, 
                            responsive: true, 
                            maintainAspectRatio: false 
                            // Removed the rules that hid the legend and grid lines so it looks like the old one!
                        }
                    });
                }

                // OLD CLASSIC PIE/DOUGHNUT CHART
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
                            animation: false,
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
            // NEW: SMOOTH NAVIGATION & ACTIVE STATES
            // ==========================================
            function initializeReportNavigation() {
                const reportContent = document.querySelector('.report-content');
                const navLinks = document.querySelectorAll('.report-nav-link');
                const sections = document.querySelectorAll('.report-section');

                // Click handler for smooth scroll
                navLinks.forEach(link => {
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        
                        // Remove active class from all links
                        navLinks.forEach(l => l.classList.remove('active'));
                        
                        // Add active class to clicked link
                        this.classList.add('active');
                        
                        // Smooth scroll to section
                        const targetId = this.getAttribute('href');
                        const targetSection = document.querySelector(targetId);
                        if (targetSection && reportContent) {
                            const offsetTop = targetSection.offsetTop - reportContent.offsetTop;
                            reportContent.scrollTo({
                                top: offsetTop,
                                behavior: 'smooth'
                            });
                        }
                    });
                });

                // Scroll spy - update active link on scroll
                if (reportContent) {
                    reportContent.addEventListener('scroll', function() {
                        let currentSection = '';
                        const scrollPos = reportContent.scrollTop + 150;

                        sections.forEach(section => {
                            const sectionTop = section.offsetTop - reportContent.offsetTop;
                            const sectionHeight = section.offsetHeight;
                            
                            if (scrollPos >= sectionTop && scrollPos < sectionTop + sectionHeight) {
                                currentSection = section.getAttribute('id');
                            }
                        });

                        navLinks.forEach(link => {
                            link.classList.remove('active');
                            if (link.getAttribute('href') === '#' + currentSection) {
                                link.classList.add('active');
                            }
                        });
                    });

                    // Set first link as active by default
                    if (navLinks.length > 0) {
                        navLinks[0].classList.add('active');
                    }
                }
            }

            // ==========================================
            // REAL-TIME BADGE UPDATER
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

            // ==========================================
            // NEW: LIVE TABLE SEARCH FILTER
            // ==========================================
            const searchInput = document.getElementById('archiveSearch');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const filter = this.value.toLowerCase();
                    const tableRows = document.querySelectorAll('.table-card tbody tr');
                    let visibleCount = 0;

                    tableRows.forEach(row => {
                        // We are searching by the Season Name and the Date Archived
                        const seasonName = row.querySelector('.season-name').textContent.toLowerCase();
                        const dateArchived = row.querySelector('.date-archived').textContent.toLowerCase();

                        if (seasonName.includes(filter) || dateArchived.includes(filter)) {
                            row.style.display = ''; // Show row
                            visibleCount++;
                        } else {
                            row.style.display = 'none'; // Hide row
                        }
                    });

                    // Update the "Showing X archived seasons" footer text dynamically
                    const footerText = document.querySelector('.table-footer-text strong');
                    if(footerText) {
                        footerText.textContent = visibleCount;
                    }
                });
            }
        
        });

        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => { new bootstrap.Alert(alert).close(); });
        }, 5000);
</script>
</body>
</html>