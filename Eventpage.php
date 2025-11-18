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
        
        COALESCE(u.full_name, u.username) AS manager_name
        
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
        /* --- STYLES (Identical to previous file) --- */
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
        .nav-link { font-weight: 500; font-size: 0.95rem; padding: 0.5rem 1.25rem !important; margin: 0 0.25rem; border-radius: 8px; transition: var(--transition); }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: var(--primary-green) !important; }
        
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
            padding-top: 100px; /* Space for fixed navbar */
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
        
        .hero-section {
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(233,236,239,0.9) 100%);
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.12);
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
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            padding: 24px;
            margin-bottom: 24px;
            transition: all 0.3s ease;
            height: 240px; 
            border-left: 4px solid transparent;
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
            font-size: 1.1rem;
            margin: 0;
            color: #2c3e50;
            margin-right: 8px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex-shrink: 1;
            min-width: 0;
        }
        .status-pill {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 5px 12px;
            border-radius: 20px;
            color: #fff;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            flex-shrink: 0;
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

        .podium-container {
            display: flex;
            align-items: flex-end;
            justify-content: center;
            min-height: 250px;
            padding: 2rem 1rem;
            gap: 5px;
        }
        .podium-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 33.33%;
        }
        .podium-wrapper.gold { order: 2; }
        .podium-wrapper.silver { order: 1; }
        .podium-wrapper.bronze { order: 3; }

        .podium-step {
            display: flex;
            flex-direction: column;
            justify-content: center; 
            align-items: center;
            text-align: center;
            width: 100%;
            border-radius: 8px 8px 0 0;
            padding: 1.5rem 1rem;
            background-color: #f0f0f0;
            box-shadow: 0 -4px 12px rgba(0,0,0,0.05) inset;
        }
        .podium-step.gold {
            height: 180px;
            background-color: #fffbeb;
            border: 1px solid var(--accent-gold);
        }
        .podium-step.silver {
            height: 140px;
            background-color: #f8f8f8;
            border: 1px solid var(--accent-silver);
        }
        .podium-step.bronze {
            height: 110px;
            background-color: #fdf8f4;
            border: 1px solid var(--accent-bronze);
        }
        .podium-medal {
            font-size: 3rem;
            margin-bottom: 0.5rem;
        }
        .podium-step.gold .podium-medal { color: var(--accent-gold); }
        .podium-step.silver .podium-medal { color: var(--accent-silver); }
        .podium-step.bronze .podium-medal { color: var(--accent-bronze); }
        
        .podium-winner-name {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 8px;
            text-align: center;
            width: 100%;
            padding: 0 5px; 
        }
        
        .podium-medal-count {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            opacity: 0.7;
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
            
            .podium-container {
                min-height: 200px;
                padding: 1rem 0.5rem;
            }
            .podium-step { padding: 1rem 0.5rem; }
            .podium-step.gold { height: 150px; }
            .podium-step.silver { height: 120px; }
            .podium-step.bronze { height: 90px; }
            .podium-medal { font-size: 2rem; margin-bottom: 0.5rem; }
            .podium-winner-name { font-size: 0.85rem; }
            .podium-medal-count { font-size: 1.2rem; }
        }
    </style>
</head>
<body>
    
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <div class="container-fluid d-flex align-items: center justify-content-between">
            <a class="navbar-brand d-flex align-items: center interactive-brand" href="home.php" style="cursor: pointer;">
                <img src="imageslogo.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain; transition: filter 0.2s;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white brand-heading" style="font-size: 1.25rem; transition: color 0.2s;">PIT SPORTS TALLYING</strong>
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
            <div class="overall-card">

                <div class="hero-section mb-5">
                    <div class="row align-items-center">
                        <div class="col-lg-6 mb-4 mb-lg-0">
                            <h1 class="display-4 fw-bold mb-3">
                                <img src="images/SiglakaseventIcon.png" alt="Events Icon" class="me-2" style="width: 3.5rem; height: 3.5rem; object-fit: contain;">
                                Siglakas Events
                            </h1>
                            <p class="lead text-muted">Discover and track all tournament events, matches, and standings in one place.</p>
                        </div>
                        <div class="col-lg-6">
                            <div class="row g-3">
                                <div class="col-4">
                                    <div class="stat-card text-center">
                                        <img src="images/EventsIcon.png" alt="Event Icon" style="width: 2.5em; height: 2.5em;">
                                        <div class="fw-bold fs-3 mt-2"><?= $total_events ?></div>
                                        <div class="text-muted small">Total Events</div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="stat-card text-center">
                                        <img src="images/CompleteIcon.png" alt="Complete Icon" style="width: 2.5em; height: 2.5em;">
                                        <div class="fw-bold fs-3 mt-2"><?= $completed_events ?></div>
                                        <div class="text-muted small">Completed</div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="stat-card text-center">
                                        <img src="images/OngoingIcon.png" alt="Ongoing Icon" style="width: 2.5em; height: 2.5em;">
                                        <div class="fw-bold fs-3 mt-2"><?= $ongoing_events ?></div>
                                        <div class="text-muted small">Ongoing</div>
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
                        
                        <li class="nav-item" role="presentation" data-tab-type="match">
                            <button class="nav-link rounded-top" id="schedule-tab" 
                                    data-bs-toggle="tab" data-bs-target="#tab-schedule" 
                                    type="button" role="tab" data-tab-type="match">
                                <i class="fas fa-calendar-alt me-2"></i>
                                <span class="d-none d-sm-inline">Match </span>Schedule
                            </button>
                        </li>
                        
                        <li class="nav-item" role="presentation" data-tab-type="match">
                            <button class="nav-link rounded-top" id="results-tab" 
                                    data-bs-toggle="tab" data-bs-target="#tab-results" 
                                    type="button" role="tab" data-tab-type="match">
                                <i class="fas fa-trophy me-2"></i>
                                <span class="d-none d-sm-inline">Match </span>Results
                            </button>
                        </li>

                        <li class="nav-item" role="presentation" data-tab-type="medal">
                            <button class="nav-link rounded-top" id="winners-tab" 
                                    data-bs-toggle="tab" data-bs-target="#tab-winners" 
                                    type="button" role="tab" data-tab-type="medal">
                                <i class="fas fa-award me-2"></i>
                                Medal Winners
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

                        <div class="tab-pane fade p-4" id="tab-schedule" role="tabpanel" data-tab-type="match">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-info bg-opacity-10 border-0">
                                    <h6 class="mb-0 text-dark">
                                        <i class="fas fa-calendar-check me-2"></i>Upcoming & Ongoing Matches
                                    </h6>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead>
                                                <tr class="table-light">
                                                    <th>#</th>
                                                    <th>DATE</th>
                                                    <th>TIME</th>
                                                    <th>TEAMS</th>
                                                    <th>VENUE</th>
                                                    <th class="text-center">STATUS</th>
                                                </tr>
                                            </thead>
                                            <tbody id="scheduleTableBody">
                                                <tr><td colspan="6" class="text-center text-muted py-4">Loading...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade p-4" id="tab-results" role="tabpanel" data-tab-type="match">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-success bg-opacity-10 border-0">
                                    <h6 class="mb-0 text-dark">
                                        <i class="fas fa-clipboard-check me-2"></i>Completed Match Results
                                    </h6>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead>
                                                <tr class="table-light">
                                                    <th>SPORT</th>
                                                    <th>MATCH</th>
                                                    <th class="text-center">SCORE</th>
                                                    <th>WINNER</th>
                                                </tr>
                                            </thead>
                                            <tbody id="matchResultsBody">
                                                <tr><td colspan="4" class="text-center text-muted py-4">Loading...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade p-0" id="tab-winners" role="tabpanel" data-tab-type="medal">
                            <div class="card border-0">
                                <div class="card-header bg-warning bg-opacity-10 border-0">
                                    <h6 class="mb-0 text-dark">
                                        <i class="fas fa-award me-2"></i>Event Medal Winners
                                    </h6>
                                </div>
                                <div class="card-body" id="medal-winners-body">
                                    <div class="text-center p-5 text-muted">Loading...</div>
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
    
    <footer class="bg-dark text-white p-4 text-center">
        <small>&copy; 2025 PIT Sports Tallying System. All rights reserved.</small>
    </footer>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.iconify.design/2/2.2.1/iconify.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            
            const navbarHeight = document.querySelector('.navbar').offsetHeight;
            document.querySelector('.main-content').style.paddingTop = `${navbarHeight + 30}px`;
            
            document.querySelector('.interactive-brand').addEventListener('click', function(e) {
                e.preventDefault();
                window.location.href = 'home.php'; 
            });

            const eventsGridContainer = document.getElementById('events-grid-container');
            const eventSearchInput = document.getElementById('event-search-input');
            const filterStatusSelect = document.getElementById('filter-status');
            const eventsTab = document.getElementById('eventsTab');

            const initialEventsData = <?php echo json_encode($events); ?>;
            
            let currentStatusFilter = 'all';
            let currentCategoryFilter = 'all'; 
            let currentSearchValue = '';
            
            let autoRefreshInterval = null;
            let currentEventIdForRefresh = null;

            // ### FIX: UPDATED buildEventHtml FUNCTION ###
            function buildEventHtml(event) {
                const statusClass = getEventCardStatusClassJS(event.event_status || '');
                const title = String(event.event_name || 'Untitled');
                const sportName = String(event.sport_name || 'Unknown Sport');
                const category = String(event.category || 'General'); 
                const desc = String(event.description || 'No description provided.');
                
                // Get original status for filtering and new display status for the card
                const originalStatus = event.event_status || 'Unknown';
                const displayStatus = formatStatusTextForCard(originalStatus); // Uses new helper function
                
                const managerNameHtml = event.manager_name ? 
                    `<div class="small text-muted"><i class="fas fa-user-tie me-1"></i>Manager: ${escapeHtml(event.manager_name)}</div>` : 
                    '';
                
                return `
                    <div class="col-md-4 event-card-item" 
                         data-category="${(event.sport_name||'others').toLowerCase()}" 
                         data-status="${(originalStatus).toLowerCase()}" 
                         data-search-text="${escapeHtml(title.toLowerCase())} ${escapeHtml(sportName.toLowerCase())}">
                        <div class="sport-card">
                            <div class="sport-card-body">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h5 class="mb-0 event-title" title="${escapeHtml(title)}">${escapeHtml(title)}</h5>
                                    <span class="status-pill ${statusClass}" title="${escapeHtml(originalStatus)}">${escapeHtml(displayStatus)}</span>
                                </div>
                                <div class="mb-2"> 
                                    <p class="text-muted mb-1"><strong>${escapeHtml(sportName)}</strong> - ${escapeHtml(category)}</p>
                                    ${managerNameHtml}
                                </div>
                                <p class="text-muted mb-3">${escapeHtml(desc)}</p>
                            </div>
                            <div class="d-flex justify-content-end align-items: center mt-auto">
                                <a href="#" class="check-event" 
                                   data-bs-toggle="modal" data-bs-target="#viewEventModal" 
                                   data-id="${event.event_id}" 
                                   data-category-type="${event.category_type || 'medal'}"
                                   data-status="${(originalStatus).toLowerCase()}">
                                    Check Event <span class="iconify ms-1" data-icon="mdi:arrow-right"></span>
                                </a>
                            </div>
                        </div>
                    </div>`;
            }

            function renderEvents(events) {
                if (!eventsGridContainer) return;
                
                let html = '';
                if (!events || events.length === 0) {
                    html = '<div class="col-12"><p class="text-center text-muted py-5">No events found. Please add events via the admin panel.</p></div>';
                } else {
                    html = events.map(buildEventHtml).join('');
                }
                eventsGridContainer.innerHTML = html;
            }
            
            // ### FIX: UPDATED applyAllFilters FUNCTION ###
            function applyAllFilters() {
                if (!eventsGridContainer) return;
                const cards = eventsGridContainer.querySelectorAll('.event-card-item');
                let hasVisibleEvents = false;
                
                cards.forEach(card => {
                    const ccat = card.dataset.category;
                    const cstatus = card.dataset.status; // This is the original full status
                    const ctext = card.dataset.searchText;
                    
                    const showCategory = (currentCategoryFilter === 'all' || ccat === currentCategoryFilter);
                    
                    // Logic to match the new filter dropdown
                    let showStatus = false;
                    switch (currentStatusFilter) {
                        case 'all':
                            showStatus = true;
                            break;
                        case 'completed':
                            // This ONLY shows 'Completed' (which includes 'Results Approved' via PHP)
                            showStatus = (cstatus === 'completed');
                            break;
                        case 'pending results':
                            // This shows all "pending" types
                            showStatus = (cstatus === 'completed (pending results)' || cstatus === 'results submitted');
                            break;
                        default:
                            // This handles 'upcoming', 'ongoing', 'postponed', 'cancelled'
                            showStatus = (cstatus === currentStatusFilter);
                            break;
                    }
                    
                    const showSearch = (currentSearchValue === '' || ctext.includes(currentSearchValue));
                    
                    if (showCategory && showStatus && showSearch) {
                        card.style.display = 'block';
                        hasVisibleEvents = true;
                    } else {
                        card.style.display = 'none';
                    }
                });
                
                // TODO: Add a "No results found" message if !hasVisibleEvents
            }
            
            // --- INITIAL RENDER ---
            renderEvents(initialEventsData);
            applyAllFilters();
            
            // --- ### Filter Event Listeners ### ---
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

            // --- ### VIEW EVENT MODAL LOGIC (No Changes) ### ---
            const viewEventModalElement = document.getElementById('viewEventModal');
            const viewEventModal = new bootstrap.Modal(viewEventModalElement);

            // --- Get Modal Element References ---
            const modalEventNameSpan = document.getElementById('modalEventName');
            const modalMedalStandingsBody = document.getElementById('modalMedalStandingsBody');
            const matchScheduleContent = document.getElementById('scheduleTableBody');
            const matchResultsBody = document.getElementById('matchResultsBody');
            const medalWinnersBody = document.getElementById('medal-winners-body');

            // --- Main Modal Click Handler ---
            document.body.addEventListener('click', function(e) {
                if (e.target.closest('.check-event')) {
                    e.preventDefault(); 
                    const button = e.target.closest('.check-event');
                    const eventId = button.dataset.id;
                    const categoryType = button.dataset.categoryType || 'medal'; 
                    const status = button.dataset.status;

                    currentEventIdForRefresh = eventId; // Store for auto-refresh

                    // --- 1. Show/Hide Adaptive Tabs ---
                    const allTabs = viewEventModalElement.querySelectorAll('.nav-item[data-tab-type]');
                    allTabs.forEach(tabLi => {
                        tabLi.style.display = (tabLi.dataset.tabType === categoryType) ? 'block' : 'none';
                    });
                    
                    const allPanes = viewEventModalElement.querySelectorAll('.tab-pane');
                    allPanes.forEach(pane => {
                        if (pane.id === 'tab-winners') pane.classList.remove('p-4'); // podium has its own padding
                        else pane.classList.add('p-4');
                        if (pane.dataset.tabType && pane.dataset.tabType !== categoryType) {
                            pane.classList.remove('show', 'active');
                        }
                    });

                    // --- 2. "Smart Tab" Activation Logic ---
                    let targetTabId = 'details-tab'; // Default
                    if (status === 'completed' || status === 'completed (pending results)' || status === 'results submitted') {
                        targetTabId = (categoryType === 'medal') ? 'winners-tab' : 'results-tab';
                    } else if (status === 'ongoing') {
                        targetTabId = (categoryType === 'match') ? 'schedule-tab' : 'medals-tab';
                    } else if (status === 'upcoming') {
                        targetTabId = (categoryType === 'match') ? 'schedule-tab' : 'details-tab';
                    }
                    
                    const detailsTab = document.getElementById('details-tab');
                    if (detailsTab) {
                        new bootstrap.Tab(detailsTab).show();
                    }


                    // --- 3. Show Loading Indicators ---
                    modalMedalStandingsBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Loading...</td></tr>';
                    matchScheduleContent.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Loading...</td></tr>';
                    matchResultsBody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">Loading...</td></tr>';
                    if (medalWinnersBody) medalWinnersBody.innerHTML = '<div class="text-center p-5 text-muted">Loading...</div>';
                    
                    // --- 4. Run Loaders ---
                    loadAndRender(); 
                    if (autoRefreshInterval) clearInterval(autoRefreshInterval);
                    autoRefreshInterval = setInterval(loadAndRender, 15000); // Auto-refresh modal data

                    if (categoryType === 'medal') {
                        fetchMedalWinnerResults(eventId); // This is a separate call
                    }
                }
            });
            
            viewEventModalElement.addEventListener('hidden.bs.modal', function () {
                if (autoRefreshInterval) clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
                currentEventIdForRefresh = null;
            });
            
            // --- ### FIX: ALL HELPER FUNCTIONS (RESTORED + UPDATED) ### ---

           /**
             * Main data loader for the modal.
             * This function is now correct and fetches/parses the nested JSON.
             */
            function loadAndRender() {
                if (!currentEventIdForRefresh) return;
                const eventId = currentEventIdForRefresh;
                
                fetch(`fetch_event_details_data.php?id=${eventId}&_=${Date.now()}`)
                    .then(response => response.json())
                    .then(response => { 
                        if (!response.success) throw new Error(response.message || 'Failed to load details');
                        
                        const data = response.data; // Access the nested 'data' object

                        // 1. Populate Details Tab
                        modalEventNameSpan.textContent = data.event_details.event_name || 'Event Details';
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

                        // 3. Populate Schedule/Results Tabs
                        renderScheduleTable(data.raw_match_schedule.filter(m => m.status === 'Upcoming' || m.status === 'Ongoing'));
                        populateMatchResults(data.raw_match_schedule.filter(m => m.status === 'Completed'));
                    })
                    .catch(error => {
                        console.error('Error loading event details:', error);
                        modalMedalStandingsBody.innerHTML = `<tr><td colspan="6" class="text-center text-danger py-4">Error loading data.</td></tr>`;
                        matchScheduleContent.innerHTML = `<tr><td colspan="6" class="text-center text-danger py-4">Error loading data.</td></tr>`;
                        matchResultsBody.innerHTML = `<tr><td colspan="4" class="text-center text-danger py-4">Error loading data.</td></tr>`;
                        
                        document.getElementById('viewGameName').textContent = 'Error';
                        document.getElementById('viewEventName').textContent = 'Error';
                        document.getElementById('viewCategory').textContent = 'Error';
                        document.getElementById('viewEventDate').textContent = 'Error';
                        document.getElementById('viewEventTime').textContent = 'Error';
                        document.getElementById('viewEventVenue').textContent = 'Error';
                    });
            }

            // ### FIX: UPDATED getEventCardStatusClassJS FUNCTION ###
            function getEventCardStatusClassJS(status) {
                if (!status) return 'bg-secondary'; // Default grey

                const lowerStatus = status.toLowerCase();
                
                switch (lowerStatus) {
                    case 'ongoing': 
                        return 'bg-success'; // Green - Active now

                    case 'completed':
                    case 'results approved': // 'Results Approved' is handled by PHP, but good to keep
                        return 'bg-primary'; // Blue - Finished

                    case 'postponed':
                    case 'completed (pending results)': // Correctly handles the DB status
                    case 'results submitted':
                        return 'bg-warning text-dark'; // Yellow - Wait/Caution

                    case 'upcoming': 
                        return 'bg-info'; // Light Blue - Informational
                    
                    case 'cancelled':
                    case 'results rejected':
                        return 'bg-danger'; // Red - Stopped/Error
                        
                    case 'draft':
                        return 'bg-light text-dark'; // Light Grey - Not published
                        
                    default:
                        return 'bg-secondary'; // Default grey for any other status
                }
            }

            // ### NEW: ADDED formatStatusTextForCard FUNCTION ###
            /**
             * Formats the status text for display on the event card.
             * Shortens long statuses like "Completed (pending results)" to "Pending Results".
             */
            function formatStatusTextForCard(status) {
                if (!status) return 'Unknown';
                
                const lowerStatus = status.toLowerCase();
                
                switch (lowerStatus) {
                    case 'completed (pending results)':
                    case 'results submitted':
                        return 'Pending Results';
                    
                    case 'results approved':
                        return 'Completed'; 
                        
                    default:
                        return status; // Return the original status text
                }
            }
            
            function populateMedalStandings(standingsData) {
                let html = '';
                if (!standingsData || standingsData.length === 0) {
                    html = `<tr><td colspan="6" class="text-center text-muted py-4">No approved medal standings for this event.</td></tr>`;
                } else {
                    standingsData.sort((a, b) => {
                        if (a.rank && b.rank) return a.rank - b.rank;
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
                            </tr>
                        `;
                    });
                }
                modalMedalStandingsBody.innerHTML = html;
            }
            
            function renderScheduleTable(matches) {
                if (!matches || matches.length === 0) {
                    matchScheduleContent.innerHTML = `<tr><td colspan="6" class="text-center text-muted py-4">No upcoming or ongoing matches.</td></tr>`;
                    return;
                }
                matches.sort((a,b) => new Date(a.match_date + 'T' + a.match_time) - new Date(b.match_date + 'T' + b.match_time));
                
                let rows = '';
                matches.forEach((m, idx) => {
                    const statusClass = m.status === 'Ongoing' ? 'bg-success' : (m.status === 'Upcoming' ? 'bg-info' : 'bg-secondary');
                    rows += `
                        <tr>
                            <td>${idx + 1}</td>
                            <td><i class="fas fa-calendar-alt me-1"></i>${formatDate(m.match_date)}</td>
                            <td><i class="fas fa-clock me-1"></i>${formatTime(m.match_time)}</td>
                            <td>
                                <div class="fw-bold">${escapeHtml(m.team1_name)} <span class="text-muted">vs</span> ${escapeHtml(m.team2_name)}</div>
                            </td>
                            <td><i class="fas fa-map-marker-alt me-1"></i>${escapeHtml(m.venue)}</td>
                            <td class="text-center"><span class="badge ${statusClass}">${escapeHtml(m.status)}</span></td>
                        </tr>`;
                });
                matchScheduleContent.innerHTML = rows;
            }

            function populateMatchResults(completedMatches) {
                let html = '';
                if (!completedMatches || completedMatches.length === 0) {
                    html = `<tr><td colspan="4" class="text-center text-muted py-4">No completed match results for this event yet.</td></tr>`;
                } else {
                    completedMatches.sort((a,b) => new Date(b.time_finished || b.match_date) - new Date(a.time_finished || a.match_date));
                    completedMatches.forEach(match => {
                        html += `
                            <tr>
                                <td>${escapeHtml(match.sport_category || 'N/A')}</td>
                                <td>${escapeHtml(match.team1_name)} vs ${escapeHtml(match.team2_name)}</td>
                                <td class="text-center fw-bold">${escapeHtml(String(match.score1 || '0'))} - ${escapeHtml(String(match.score2 || '0'))}</td>
                                <td>${escapeHtml(match.winner_name || 'N/A')}</td>
                            </tr>
                        `;
                    });
                }
                matchResultsBody.innerHTML = html;
            }
            
            function fetchMedalWinnerResults(categoryId) {
                if (!medalWinnersBody) return;
                medalWinnersBody.innerHTML = `<div class="text-center p-5 text-muted"><div class="spinner-border spinner-border-sm" role="status"></div><span class="ms-2">Loading Winners...</span></div>`;
                
                fetch(`event_manager_api.php?action=get_medal_results&category_id=${categoryId}`)
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            const data = result.data;
                            let html = '';
                            if (!data.gold && !data.silver && !data.bronze) {
                                html = '<div class="text-center p-5 text-muted">No medal winners have been submitted yet.</div>';
                                medalWinnersBody.innerHTML = html;
                                return;
                            }
                            html = '<div class="podium-container">';
                            html += `<div class="podium-wrapper silver"><div class="podium-winner-name">${escapeHtml(data.silver || 'N/A')}</div><div class="podium-step silver"><i class="fas fa-medal podium-medal"></i><div class="podium-medal-count">${escapeHtml(data.silver_count || '0')}</div></div></div>`;
                            html += `<div class="podium-wrapper gold"><div class="podium-winner-name">${escapeHtml(data.gold || 'N/A')}</div><div class="podium-step gold"><i class="fas fa-medal podium-medal"></i><div class="podium-medal-count">${escapeHtml(data.gold_count || '0')}</div></div></div>`;
                            html += `<div class="podium-wrapper bronze"><div class="podium-winner-name">${escapeHtml(data.bronze || 'N/A')}</div><div class="podium-step bronze"><i class="fas fa-medal podium-medal"></i><div class="podium-medal-count">${escapeHtml(data.bronze_count || '0')}</div></div></div>`;
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
                    if (isNaN(date.getTime())) return dateString; // return original if invalid
                    date.setTime(date.getTime() + date.getTimezoneOffset() * 60000); 
                    return date.toLocaleDateString('en-US', options);
                } catch (e) {
                    return dateString; // return original on error
                }
            }
            
            function formatTime(timeString) {
                if (!timeString || timeString === '00:00:00') return 'TBA';
                try {
                    const [hours, minutes] = timeString.split(':');
                    const date = new Date();
                    date.setHours(hours, minutes, 0);
                    if (isNaN(date.getTime())) return timeString; // return original if invalid
                    return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
                } catch (e) {
                    return timeString; // return original on error
                }
            }

            function escapeHtml(text) {
                if (text === null || typeof text === 'undefined') return '';
                const strText = String(text); 
                const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
                return strText.replace(/[&<>"']/g, (m) => map[m]);
            }
            
        }); // End of DOMContentLoaded
    </script>
</body>
</html>