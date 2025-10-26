<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

$current_page = basename($_SERVER['PHP_SELF']);
require_once 'config.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Reports - PIT SPORTS TALLYING</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        /* Added from Tournament_Manager_page.php */
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
        background: linear-gradient(to bottom,rgba(245, 16, 16, 0.32));
        margin: 0;
        padding: 0;
        min-height: 100vh;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; /* Updated font */
        display: flex;
        flex-direction: column;
    }

        /* Navbar Enhancement - Copied from Tournament_Manager_page.php */
    .navbar {
        background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        padding: 1rem 0;
        backdrop-filter: blur(10px);
    }

    .navbar-brand {
        transition: var(--transition);
    }

    .navbar-brand:hover {
        transform: translateY(-2px);
    }

    .brand-logo {
        filter: drop-shadow(0 2px 4px rgba(255,255,255,0.1));
    }

    .brand-heading {
        font-family: 'Poppins', sans-serif;
        font-weight: 700;
        letter-spacing: -0.5px;
    }

    .nav-link {
        font-weight: 500;
        font-size: 0.95rem;
        padding: 0.5rem 1.25rem !important;
        margin: 0 0.25rem;
        border-radius: 8px;
        transition: var(--transition);
        position: relative;
    }

    .nav-link::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 50%;
        width: 0;
        height: 2px;
        background: var(--primary-green);
        transition: var(--transition);
        transform: translateX(-50%);
    }

    .nav-link:hover::after,
    .nav-link.active::after {
        width: 80%;
    }

    .nav-link:hover {
        background: rgba(255,255,255,0.1);
        color: var(--primary-green) !important;
    }
    
    /* Note: The interactive-brand CSS from the old Event.php is removed */

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
    /* End Navbar Enhancement */
        
        .interactive-brand:hover .brand-heading,
        .interactive-brand:hover .brand-subheading {
            color: rgba(0, 102, 255, 0.43) !important;
        }

        .main-content {
            flex-grow: 1;
            max-width: 1280px; 
            margin: 0 auto;
            padding: 20px;
            width: 100%;
        }

        .header-card {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            padding: 20px 25px;
            margin-bottom: 25px;
            transition: all 0.3s ease;
        }

        .header-card h2 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .header-card p {
            color: #6c757d;
            font-size: 0.95rem;
            margin-top: 0.25rem;
        }

        .report-card {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            padding: 20px;
            margin-bottom: 20px;
            scroll-margin-top: 90px; 
            transition: all 0.3s;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
            margin-bottom: 20px;
        }

        .section-header .title-group {
            display: flex;
            align-items: center;
        }

        .section-header span {
            margin-right: 8px;
            font-size: 1.3rem;
        }

        .refresh-badge {
            font-size: 0.75rem;
            padding: 4px 8px;
            background-color: #28a745;
            color: white;
            border-radius: 4px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }

        .section-description {
            font-size: 0.75rem;
            color: #6c757d;
            margin-bottom: 1rem;
        }

        .global-actions-bar {
            display: flex;
            justify-content: flex-start;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .filter-controls-sidebar {
            padding-top: 10px;
            border-top: 1px solid #eee;
            margin-top: 10px;
        }
        
        .filter-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: #495057;
            display: block;
            margin-bottom: 0.25rem;
        }
        
        .filter-select {
            padding: 6px 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 0.9rem;
            background-color: #fff;
            width: 100%;
        }

        .action-button {
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 4px;
            border: 1px solid #ccc; 
            background-color: #f7f7f7;
            transition: background-color 0.2s;
            cursor: pointer;
            color: #495057;
            text-decoration: none;
        }
        
        .action-button:hover { 
            background-color: #ededed; 
            color: #495057;
        }

        .pdf-icon { color: #dc3545; }
        .excel-icon { color: #28a745; }
        .print-icon { color: #007bff; }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            border: 1px solid #ddd; 
            border-radius: 4px;
            overflow: hidden;
        }
        
        .report-table th, 
        .report-table td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0; 
        }
        
        .report-table th {
            font-weight: 600;
            color: #555;
            font-size: 0.85rem;
            background-color: #f8f8f8;
        }

        .report-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .loading-spinner {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }

        .loading-spinner i {
            font-size: 2rem;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .chart-container {
            display: flex;
            gap: 20px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .chart-wrapper {
            flex: 1;
            min-width: 100%;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .chart-title {
            font-size: 1rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            text-align: center;
        }

        .chart-canvas {
            max-height: 300px;
        }

        .sticky-sidebar-col {
            margin-bottom: 20px;
        }
        
        @media (min-width: 992px) {
            .sticky-sidebar-col {
                position: sticky;
                top: 80px;
                align-self: flex-start;
                z-index: 100;
                margin-bottom: 0;
            }
        }

        .quick-jump-nav-card {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            padding: 20px;
            margin-bottom: 20px;
        }

        .quick-jump-link {
            display: flex;
            align-items: center;
            padding: 8px 10px;
            margin-bottom: 4px;
            border-radius: 4px;
            color: #495057;
            text-decoration: none;
            transition: background-color 0.2s, border-left 0.2s, color 0.2s;
            border-left: 4px solid transparent;
            font-size: 0.95rem;
        }

        .quick-jump-link:hover {
            background-color: #f0f0f0;
            color: #007bff;
        }

        .quick-jump-link.active-link {
            background-color: #fbe5e5;
            border-left: 4px solid #dc3545;
            font-weight: 500;
            color: #dc3545;
        }

        .quick-jump-link .fa-icon {
            margin-right: 10px;
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
        }

        .rank-circle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: #007bff;
            color: white;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .medal-icon-header {
            font-size: 1.1rem;
        }

        @media (max-width: 768px) {
            body { 
                padding-top: 100px;
            }

            .global-actions-bar {
                justify-content: center;
            }
            
            .chart-container {
                flex-direction: column;
            }

            .chart-wrapper {
                min-width: 100%;
            }
        }

        

        .medal-image-icon {
            /* Style for the custom images */
            width: 30px;
            height: 30px;
            object-fit: contain; /* Ensures image fits without cropping */
            display: inline-block;
            
        }

        /* Target all table data cells and headers within the medal summary table */
#medalSummaryTable th, 
#medalSummaryTable td {
    /* Set top/bottom padding to 0 to eliminate vertical space between rows.
       The horizontal padding (16px) is kept for space around the text. */
    padding: 0 16px; 
    
    /* Ensure no margin is incorrectly pushing the cells apart */
    margin: 0;
}

/* Adjust the table header for necessary spacing (visual balance) */
/* You may want to keep a little padding on the header row (<th>) for separation from the title. */
#medalSummaryTable th {
    /* Keeping some padding on the header looks cleaner and separates it from the title */
    padding: 10px 16px; 
}

        
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
    <div class="container-fluid d-flex align-items-center justify-content-between">
        <a class="navbar-brand d-flex align-items-center interactive-brand" href="Tournament_Manager_page.php" style="cursor: pointer;">
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
                    <a class="nav-link <?= ($current_page == 'Tournament_Manager_page.php') ? 'active' : '' ?>" href="Tournament_Manager_page.php">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == 'Event.php') ? 'active' : '' ?>" href="Event.php">Events</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == 'Teams.php') ? 'active' : '' ?>" href="Teams.php">Teams</a>
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

    <main class="main-content">
        
        <div class="header-card">
            <h2>
                <span style="color: #dc3545; font-size: 1.5rem;"><i class="fas fa-file-invoice"></i></span> View Reports
            </h2>
            <p>Comprehensive tournament reports with real-time medal summaries, event results, and performance analytics.</p>
        </div>

        <div class="global-actions-bar">
            <button class="action-button" onclick="window.print()">
                <span class="print-icon"><i class="fas fa-print"></i></span> Print Report
            </button>
            <button class="action-button" onclick="refreshAllData()">
                <i class="fas fa-sync-alt"></i> Refresh Data
            </button>
        </div>
        
        <div class="row">
            
            <div class="col-lg-3 sticky-sidebar-col">
                
                <div class="quick-jump-nav-card">
                    <h5 class="text-secondary mb-3"><i class="fas fa-link me-2"></i>Quick Jump</h5>
                    <nav class="nav flex-column" id="quick-jump-nav">
                        <a class="quick-jump-link active-link" href="#summary" data-target="summary">
                            <span class="fa-icon gold-icon"><i class="fas fa-medal"></i></span> Overall Medal Summary
                        </a>
                        <a class="quick-jump-link" href="#matches" data-target="matches">
                            <span class="fa-icon silver-icon" style="color: #777;"><i class="fas fa-bullseye"></i></span> Match Results Report
                        </a>
                        <a class="quick-jump-link" href="#events" data-target="events">
                            <span class="fa-icon pdf-icon"><i class="fas fa-list-check"></i></span> Event Results Report
                        </a>
                        <a class="quick-jump-link" href="#graphs" data-target="graphs">
                            <span class="fa-icon excel-icon"><i class="fas fa-chart-bar"></i></span> Graphical Reports
                        </a>
                    </nav>

                    <div class="filter-controls-sidebar">
                        <h5 class="text-secondary mb-3"><i class="fas fa-sliders me-2"></i> Report Filters</h5>
                        
                        <div class="mb-3">
                            <label class="filter-label">Sort By:</label>
                            <select class="filter-select" id="sortFilter">
                                <option value="total">Total Medals</option>
                                <option value="gold">Gold Medals</option>
                                <option value="silver">Silver Medals</option>
                                <option value="bronze">Bronze Medals</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-9">
                
                <!-- Overall Medal Summary -->
                <div class="report-card report-section" id="summary">
                <div class="section-header">
                    <div class="title-group">
                        <span style="color: #555;">🏅</span> Overall Medal Summary
                    </div>
                    <span class="refresh-badge" id="medalUpdateBadge">Live</span>
                </div>
                <p class="section-description">Total medals count per college, ranked by overall performance. Updates automatically.</p>
                
                <div class="table-responsive">
                    <table class="report-table" id="medalSummaryTable">
                        <thead>
                            <tr>
                                <th style="width: 50px;">Rank</th>
                                <th style="width: 40%;">College/Department</th>
                                <th style="width: 15%;"><span class="medal-icon-header gold-icon">🥇</span> Gold</th>
                                <th style="width: 15%;"><span class="medal-icon-header silver-icon">🥈</span> Silver</th>
                                <th style="width: 15%;"><span class="medal-icon-header bronze-icon">🥉</span> Bronze</th>
                                <th style="width: 15%;">Total Medals</th>
                            </tr>
                        </thead>
                        <tbody id="medalSummaryBody">
                            <tr>
                                <td colspan="6" class="loading-spinner">
                                    <i class="fas fa-spinner"></i>
                                    <p>Loading medal data...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

             <!-- Match Results Report -->
                <div class="report-card report-section" id="matches">
                    <div class="section-header">
                        <div class="title-group">
                            <span style="color: #555;"><i class="fas fa-futbol"></i></span> Match Results Report
                        </div>
                        <span class="refresh-badge" id="matchUpdateBadge">Live</span>
                    </div>
                    <p class="section-description">Detailed, match-by-match results for all completed games.</p>
                    
                    <div class="table-responsive">
                        <table class="report-table" id="matchResultsTable">
                            <thead>
                                <tr>
                                    <th style="width: 20%;">Event</th>
                                    <th style="width: 25%;">Matches</th>
                                    <th style="width: 15%;">Time Finished</th>
                                    <th style="width: 15%;">Scores</th>
                                    <th style="width: 25%;">Winner</th>
                                </tr>
                            </thead>
                            <tbody id="matchResultsBody">
                                <tr>
                                    <td colspan="5" class="loading-spinner">
                                        <i class="fas fa-spinner"></i>
                                        <p>Loading match results...</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Event Results Report -->
                <div class="report-card report-section" id="events">
                    <div class="section-header">
                        <div class="title-group">
                            <span style="color: #555;">🥇</span> Event Results Report
                        </div>
                        <span class="refresh-badge" id="eventUpdateBadge">Live</span>
                    </div>
                    <p class="section-description">Winners per event with medal placements.</p>
                    
                    <div class="table-responsive">
                        <table class="report-table" id="eventResultsTable">
                            <thead>
                                <tr>
                                    <th style="width: 20%;">Event Name</th>
                                    <th style="width: 15%;">Sport</th>
                                    <th style="width: 15%;">Date</th>
                                    <th style="width: 18%;"><span class="medal-icon-header gold-icon">🥇</span> Gold</th>
                                    <th style="width: 16%;"><span class="medal-icon-header silver-icon">🥈</span> Silver</th>
                                    <th style="width: 16%;"><span class="medal-icon-header bronze-icon">🥉</span> Bronze</th>
                                </tr>
                            </thead>
                            <tbody id="eventResultsBody">
                                <tr>
                                    <td colspan="6" class="loading-spinner">
                                        <i class="fas fa-spinner"></i>
                                        <p>Loading event results...</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
               <div class="report-card report-section" id="graphs">
                <div class="section-header">
                    <div class="title-group">
                        <span style="color: #555;">📊</span> Graphical Reports
                    </div>
                    <span class="refresh-badge" id="graphUpdateBadge">Live</span>
                </div>
                <p class="section-description">Visual representation of medal distribution and performance.</p>

                <div class="row">
                    
                    <div class="col-12 mb-4"> 
                        <div class="chart-wrapper">
                            <h3 class="chart-title">Medal Counts per College</h3>
                            <canvas id="medalBarChart" class="chart-canvas"></canvas>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="chart-wrapper">
                            <h3 class="chart-title">Medal Distribution per Sport</h3>
                            <canvas id="sportPieChart" class="chart-canvas"></canvas>
                        </div>
                    </div>
                    
                </div>
            </div>
            
        </div>
    </main>

    <footer class="bg-dark text-white py-3 mt-auto">
        <div class="text-center">
            <small>&copy; 2025 PIT SPORTS TALLYING. All rights reserved.</small><br>
            <small>Developed by Tsunayoshi Sawada</small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global chart instances
        let medalBarChart = null;
        let sportPieChart = null;
        let autoRefreshInterval = null;

        document.addEventListener('DOMContentLoaded', () => {
            // Initialize smooth scrolling and active link tracking
            initializeNavigation();
            
            // Load all data initially
            loadAllData();
            
            // Set up auto-refresh every 30 seconds
            autoRefreshInterval = setInterval(loadAllData, 30000);
            
            // Set up sort filter
            document.getElementById('sortFilter').addEventListener('change', loadMedalSummary);
        });

        // Navigation setup
        function initializeNavigation() {
            const sections = document.querySelectorAll('.report-section');
            const links = document.querySelectorAll('.quick-jump-link');
            const navContainer = document.getElementById('quick-jump-nav');

            navContainer.addEventListener('click', function(e) {
                if (e.target.closest('.quick-jump-link')) {
                    e.preventDefault();
                    const link = e.target.closest('.quick-jump-link');
                    const targetId = link.getAttribute('data-target');
                    const targetElement = document.getElementById(targetId);

                    if (targetElement) {
                        targetElement.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                }
            });

            const observerOptions = {
                root: null, 
                rootMargin: '-50% 0px -40% 0px', 
                threshold: 0 
            };

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    const targetId = entry.target.id;
                    const link = document.querySelector(`.quick-jump-link[data-target="${targetId}"]`);
                    
                    if (link && entry.isIntersecting) {
                        links.forEach(l => l.classList.remove('active-link'));
                        link.classList.add('active-link');
                    }
                });
            }, observerOptions);

            sections.forEach(section => observer.observe(section));
        }

        // Load all data
        function loadAllData() {
            loadMedalSummary();
            loadMatchResults();
            loadEventResults();
        }

        // Refresh all data manually
        function refreshAllData() {
            showRefreshAnimation();
            loadAllData();
        }

        function showRefreshAnimation() {
            ['medalUpdateBadge', 'matchUpdateBadge', 'eventUpdateBadge', 'graphUpdateBadge'].forEach(id => {
                const badge = document.getElementById(id);
                if (badge) {
                    badge.textContent = 'Updating...';
                    badge.style.backgroundColor = '#ffc107';
                    setTimeout(() => {
                        badge.textContent = 'Live';
                        badge.style.backgroundColor = '#28a745';
                    }, 1000);
                }
            });
        }

        // Utility function to escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // === START OF NEW CODE: getRankHtml Utility ===
        function getRankHtml(rank) {
            const basePath = 'assets/icons/'; // ADJUST THIS PATH IF NEEDED

            if (rank === 1) {
                // Gold Medal Image
                return `<img src="trophy1.svg" alt="Gold Medal" class="medal-image-icon">`; 
            } else if (rank === 2) {
                // Silver Medal Image
                return `<img src="secondplace.svg" alt="Silver Medal" class="medal-image-icon">`;
            } else if (rank === 3) {
                // Bronze Medal Image
                return `<img src="thirdplace.svg" alt="Bronze Medal" class="medal-image-icon">`;
            } else {
                // Standard circle for others (Ranks 4+)
                return `<span class="rank-circle">${rank}</span>`;
            }
        }
        // === END OF NEW CODE ===

        // Load Medal Summary
        async function loadMedalSummary() {
            const tbody = document.getElementById('medalSummaryBody');
            const sortBy = document.getElementById('sortFilter').value;
            
            try {
                const response = await fetch('fetch_medal_summary.php');
                const data = await response.json();
                
                if (data.success) {
                    let standings = data.standings;
                    
                    // Sort based on selected filter
                    standings.sort((a, b) => {
                        if (sortBy === 'gold') return b.gold - a.gold || b.silver - a.silver || b.bronze - a.bronze;
                        if (sortBy === 'silver') return b.silver - a.silver || b.gold - a.gold || b.bronze - a.bronze;
                        if (sortBy === 'bronze') return b.bronze - a.bronze || b.gold - a.gold || b.silver - a.silver;
                        return b.total - a.total || b.gold - a.gold || b.silver - a.silver;
                    });
                    
                    // Reassign ranks
                    standings.forEach((s, i) => s.rank = i + 1);
                    
                    if (standings.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No medal data available yet.</td></tr>';
                    } else {
                        // === MODIFIED CODE LINE HERE ===
                        tbody.innerHTML = standings.map(s => `
                            <tr>
                                <td>${getRankHtml(s.rank)}</td>
                                <td>${escapeHtml(s.college)}</td>
                                                                <td>${s.gold}</td>
                                <td>${s.silver}</td>
                                <td>${s.bronze}</td>
                                <td><strong>${s.total}</strong></td>
                            </tr>
                        `).join('');
                        // === END OF MODIFIED CODE LINE ===
                    }
                    
                    // Update charts
                    updateCharts(data.chart_data, data.sport_distribution || []);
                } else {
                    tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">Error: ${escapeHtml(data.error)}</td></tr>`;
                }
            } catch (error) {
                tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">Network error: ${escapeHtml(error.message)}</td></tr>`;
                console.error('Error loading medal summary:', error);
            }
        }

        // Load Match Results
        async function loadMatchResults() {
            const tbody = document.getElementById('matchResultsBody');
            
            try {
                const response = await fetch('fetch_match_results.php');
                const data = await response.json();
                
                if (data.success) {
                    const matches = data.matches;
                    
                    if (matches.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">No match results available yet.</td></tr>';
                    } else {
                        tbody.innerHTML = matches.map(m => `
                            <tr>
                                <td>${escapeHtml(m.event_name)}</td>
                                <td>${escapeHtml(m.match_description)}</td>
                                <td>${escapeHtml(m.time_finished)}</td>
                                <td>${escapeHtml(m.scores)}</td>
                                <td><strong>${escapeHtml(m.winner)}</strong></td>
                            </tr>
                        `).join('');
                    }
                } else {
                    tbody.innerHTML = `<tr><td colspan="5" class="text-center text-danger">Error: ${escapeHtml(data.error)}</td></tr>`;
                }
            } catch (error) {
                tbody.innerHTML = `<tr><td colspan="5" class="text-center text-danger">Network error: ${escapeHtml(error.message)}</td></tr>`;
                console.error('Error loading match results:', error);
            }
        }

        // Load Event Results
        async function loadEventResults() {
            const tbody = document.getElementById('eventResultsBody');
            
            try {
                const response = await fetch('fetch_event_results.php');
                const data = await response.json();
                
                if (data.success) {
                    const events = data.events;
                    
                    if (events.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No event results available yet.</td></tr>';
                    } else {
                        tbody.innerHTML = events.map(e => `
                            <tr>
                                <td>${escapeHtml(e.event_name)}</td>
                                <td>${escapeHtml(e.sport_name)} (${escapeHtml(e.category)})</td>
                                <td>${escapeHtml(e.event_date)}</td>
                                <td>${escapeHtml(e.gold_winner)}</td>
                                <td>${escapeHtml(e.silver_winner)}</td>
                                <td>${escapeHtml(e.bronze_winner)}</td>
                                <td><strong>${escapeHtml(e.bronze_winner)}</strong></td>
                            </tr>
                        `).join('');
                    }
                    
                    // Update sport distribution chart
                    if (data.sport_distribution) {
                        updateSportPieChart(data.sport_distribution);
                    }
                } else {
                    tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">Error: ${escapeHtml(data.error)}</td></tr>`;
                }
            } catch (error) {
                tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">Network error: ${escapeHtml(error.message)}</td></tr>`;
                console.error('Error loading event results:', error);
            }
        }

        // Update Charts
        function updateCharts(chartData, sportDistribution) {
            updateMedalBarChart(chartData);
            updateSportPieChart(sportDistribution);
        }

        // Update Medal Bar Chart
        function updateMedalBarChart(chartData) {
            const ctx = document.getElementById('medalBarChart');
            if (!ctx) return;
            
            if (medalBarChart) {
                medalBarChart.destroy();
            }
            
            medalBarChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: chartData.colleges,
                    datasets: [
                        {
                            label: 'Gold',
                            data: chartData.gold,
                            backgroundColor: 'rgba(255, 215, 0, 0.7)',
                            borderColor: 'rgba(255, 215, 0, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Silver',
                            data: chartData.silver,
                            backgroundColor: 'rgba(192, 192, 192, 0.7)',
                            borderColor: 'rgba(192, 192, 192, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Bronze',
                            data: chartData.bronze,
                            backgroundColor: 'rgba(205, 127, 50, 0.7)',
                            borderColor: 'rgba(205, 127, 50, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        title: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        }

        // Update Sport Pie Chart
        function updateSportPieChart(sportDistribution) {
            const ctx = document.getElementById('sportPieChart');
            if (!ctx) return;
            
            if (sportPieChart) {
                sportPieChart.destroy();
            }
            
            if (!sportDistribution || sportDistribution.length === 0) {
                return;
            }
            
            const labels = sportDistribution.map(s => s.category);
            const data = sportDistribution.map(s => s.count);
            const colors = [
                'rgba(255, 99, 132, 0.7)',
                'rgba(54, 162, 235, 0.7)',
                'rgba(255, 206, 86, 0.7)',
                'rgba(75, 192, 192, 0.7)',
                'rgba(153, 102, 255, 0.7)',
                'rgba(255, 159, 64, 0.7)'
            ];
            
            sportPieChart = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: colors.slice(0, labels.length),
                        borderColor: colors.slice(0, labels.length).map(c => c.replace('0.7', '1')),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                        title: {
                            display: false
                        }
                    }
                }
            });
        }

        // Clean up on page unload
        window.addEventListener('beforeunload', () => {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
            }
        });
    </script>
</body>
</html>