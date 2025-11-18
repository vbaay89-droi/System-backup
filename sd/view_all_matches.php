<?php
session_start();
// 1. --- DATABASE CONNECTION ---
require_once '../db_connect.php'; 

// 2. --- SECURITY & ACCESS CONTROL ---
// This page is for the Sports Director ONLY
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Sports Director') {
    header('Location: ../login.php'); // Redirect to main login page
    exit();
}

// 3. --- SESSION & PAGE VARIABLES ---
$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Sports Director';
$current_page = 'view_all_matches.php'; // Set current page for active sidebar link

// 4. --- DATA FETCHING (Read-Only) ---
// This query is copied from Manage_Matches.php. It's perfect for this page.
$matches = [];
$sql_matches = "
    SELECT 
        m.*, -- This selects all columns from matches, including m.status
        
        -- This new CASE statement creates a final 'status' column
        -- It overrides m.status IF the parent category is rejected
        CASE 
            WHEN c.status = 'Results Rejected' THEN 'Results Rejected'
            ELSE m.status 
        END AS status,
        
        c.category_name, 
        ge.event_name, -- Get L2 Event Name
        g.game_name,   -- Get L1 Game Name
        t1.college_name AS team1_name, 
        t2.college_name AS team2_name, 
        u.username AS manager_name
    FROM matches m
    JOIN categories c ON m.category_id = c.category_id
    LEFT JOIN game_events ge ON c.event_id = ge.event_id -- Join to get L2
    LEFT JOIN games g ON ge.game_id = g.game_id     -- Join to get L1
    LEFT JOIN colleges t1 ON m.team1_id = t1.college_id
    LEFT JOIN colleges t2 ON m.team2_id = t2.college_id
    LEFT JOIN users u ON m.managed_by_user_id = u.id
    ORDER BY m.match_date DESC, m.match_time DESC
";
$result_matches = $conn->query($sql_matches);
if ($result_matches) {
    while ($row = $result_matches->fetch_assoc()) {
        $matches[] = $row;
    }
}

// Helper function (Copied from Manage_Matches.php)
function getStatusBadge($status) {
    switch ($status) {
        case 'Upcoming':
            return '<span class="badge bg-info">Upcoming</span>';
        case 'Ongoing':
            return '<span class="badge bg-success">Ongoing</span>';
        case 'Results Submitted':
            return '<span class="badge bg-warning text-dark">Results Submitted</span>';
        case 'Results Approved':
            return '<span class="badge bg-primary">Results Approved</span>';
        
        // --- NEW STATUS ---
        case 'Results Rejected':
            return '<span class="badge bg-danger">Results Rejected</span>';

        // --- MODIFIED STATUS ---
        case 'Cancelled':
            return '<span class="badge bg-secondary">Cancelled</span>'; // Changed to grey
        
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View All Matches - SD Dashboard</title>
    <!-- Use the exact same styles as your dashboard for consistency -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { 
            --sidebar-width: 260px; 
            --header-height: 82px; 
            --transition: all 0.3s ease; 
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); 
            --bg-light: #F8F9FA; 
        }
        body { 
            background-color: var(--bg-light); 
            margin: 0; 
            padding: 0; 
            min-height: 100vh; 
            font-family: 'Inter', sans-serif; 
            display: flex; 
            flex-direction: column; 
        }
        .navbar { 
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.15); 
            padding: 1rem 1.5rem; 
            height: var(--header-height); 
            position: fixed; 
            top: 0; 
            left: 0; 
            right: 0; 
            z-index: 1050; 
        }
        .user-dropdown .dropdown-toggle { 
            color: white; 
            display: flex; 
            align-items: center; 
            text-decoration: none; 
            padding: 8px 12px; 
            border-radius: 8px; 
        }
        .user-dropdown .dropdown-toggle img { 
            width: 36px; 
            height: 36px; 
            border-radius: 50%; 
            object-fit: cover; 
            margin-right: 10px; 
        }
        .sidebar { 
            width: var(--sidebar-width); 
            position: fixed; 
            top: var(--header-height); 
            left: 0; 
            height: calc(100vh - var(--header-height)); 
            background: #2c3e50; 
            color: white; 
            box-shadow: 5px 0 15px rgba(0,0,0,0.2); 
            z-index: 1040; 
            transition: width var(--transition); 
            overflow-y: auto; 
            overflow-x: hidden; 
        }
        .sidebar-nav { padding: 20px 0; }
        .sidebar-nav .nav-link { 
            color: rgba(255, 255, 255, 0.7); 
            font-size: 1.05rem; 
            font-weight: 500; 
            padding: 12px 25px; 
            transition: var(--transition); 
            border-left: 5px solid transparent; 
            margin: 2px 0; 
            display: flex; 
            align-items: center; 
            text-decoration: none; 
        }
        .sidebar-nav .nav-link i { 
            width: 30px; 
            text-align: center; 
            flex-shrink: 0; 
            font-size: 0.95em; 
        }
        .sidebar-nav .nav-link:hover { 
            color: white; 
            background: rgba(255, 255, 255, 0.05); 
            border-left-color: #1abc9c; 
        }
        .sidebar-nav .nav-link.active { 
            color: white; 
            background: rgba(255, 255, 255, 0.1); 
            border-left-color: #3498db; 
            font-weight: 600; 
        }
        .sidebar-nav .nav-title { 
            padding: 10px 25px; 
            font-size: 0.75rem; 
            font-weight: 600; 
            color: rgba(255, 255, 255, 0.4); 
            text-transform: uppercase; 
            letter-spacing: 1px; 
        }
        .main-content { 
            flex: 1 0 auto; 
            padding: 30px; 
            margin-top: var(--header-height); 
            margin-left: var(--sidebar-width); 
            transition: margin-left var(--transition); 
            min-height: calc(100vh - var(--header-height)); 
        }
        footer { 
            flex-shrink: 0; 
            background: #2c3e50 !important; 
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1); 
            margin-left: var(--sidebar-width); 
            transition: margin-left var(--transition); 
            position: relative; 
            z-index: 1041; 
        }
        .section-title { 
            font-family: 'Poppins', sans-serif; 
            font-weight: 600; 
            color: #333; 
        }
        .page-card {
            background: white;
            border: none;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
        }
        .page-card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            padding: 1.25rem 1.5rem;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }
        .page-card-header h4 {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
        }
        .table-responsive {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            background: white;
        }
        .table-responsive .table {
            margin-bottom: 0;
            font-size: 0.9rem;
        }
        .table-responsive thead th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            white-space: nowrap;
        }
        .table-responsive tbody tr:hover {
            background-color: #f1f3f5;
        }
        .table-responsive .badge {
            font-size: 0.8rem;
            padding: 0.4em 0.6em;
        }
        .matchup-cell {
            line-height: 1.4;
        }
        .matchup-cell strong {
            font-size: 1rem;
            color: #1A1A1A;
        }
        .matchup-cell small {
            font-size: 0.8rem;
        }
        .score-cell {
            font-size: 1.1rem;
            font-weight: 700;
            font-family: 'Poppins', sans-serif;
            white-space: nowrap;
        }
        .navbar-profile-icon {
            width: 36px; 
            height: 36px; 
            font-size: 36px; 
            text-align: center;
            line-height: 1;
            border-radius: 50%; 
            margin-right: 10px; 
            color: rgba(255,255,255,0.8);
        }
    </style>
</head>
<body>
    
    <!-- Header (Copied from your dashboard) -->
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items: center justify-content-between">
            <a class="navbar-brand d-flex align-items: center" href="sports_director_dashboard.php">
                <img src="../imageslogo.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light" style="font-size: 0.75rem;">Sports Director Panel</small>
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

    <!-- Sidebar (Copied from your dashboard + New Link) -->
    <div class="sidebar" id="sidebar">
        <ul class="nav flex-column sidebar-nav">
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'sports_director_dashboard.php') ? 'active' : '' ?>" href="sports_director_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item mt-3"><span class="nav-title">Management</span></li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'colleges.php') ? 'active' : '' ?>" href="colleges.php">
                    <i class="fas fa-users me-2"></i> <span>Manage Colleges</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'events.php') ? 'active' : '' ?>" href="events.php">
                    <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events (L1-L3)</span>
                </a>
            </li>
            
            <!-- === THIS IS THE NEW LINK === -->
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'view_all_matches.php') ? 'active' : '' ?>" href="view_all_matches.php">
                    <i class="fas fa-trophy me-2"></i> <span>View All Matches</span>
                </a>
            </li>
            <!-- === END OF NEW LINK === -->
             <li class="nav-item">
                    <a class="nav-link <?php if ($current_page == 'Manage_Viewreports.php') echo 'active'; ?>" href="../Manage_Viewreports.php">
                        <i class="fas fa-chart-line me-2"></i> <span>View Reports</span>
                    </a>
                </li>
            
            <li class="nav-item mt-3"><span class="nav-title">Tallying</span></li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'results.php') ? 'active' : '' ?>" href="results.php">
                    <i class="fas fa-check-double me-2"></i> <span>Approve Results</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'reports.php') ? 'active' : '' ?>" href="reports.php">
                    <i class="fas fa-chart-line me-2"></i> <span>Medal Reports</span>
                </a>
            </li>
            
            <li class="nav-item mt-auto">
                <a class="nav-link text-danger" href="../logout.php">
                    <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
            
            <!-- Hero Title -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="section-title mb-0">View All Matches</h1>
                <i class="fas fa-trophy fa-3x text-muted" style="opacity: 0.5;"></i>
            </div>
            <p class="text-muted mb-4">Monitor the schedule, status, and results of all matches in the tournament.</p>

            <!-- Card for Table -->
            <div class="page-card">
                <div class="page-card-header d-flex justify-content-between align-items-center">
                    <h4>All Matches List</h4>
                    <!-- Removed the "Create Match" button -->
                </div>
                <div class="card-body p-4">
                    <!-- Filters (Copied from Manage_Matches.php) -->
                    <div class="row g-3 mb-4">
                        <div class="col-lg-8">
                            <label for="searchInput" class="form-label small">Search Match (Event, Team, Venue...)</label>
                            <input type="text" class="form-control" id="searchInput" placeholder="Type to search...">
                        </div>
                        <div class="col-lg-4">
                            <label for="statusFilter" class="form-label small">Filter by Status</label>
                            <select class="form-select" id="statusFilter">
                                <option value="all">All Statuses</option>
                                <option value="Upcoming">Upcoming</option>
                                <option value="Ongoing">Ongoing</option>
                                <option value="Results Submitted">Results Submitted</option>
                                <option value="Results Rejected">Results Rejected</option> <option value="Results Approved">Results Approved</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>

                    <!-- Matches Table (Read-Only) -->
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="matchesTable">
                            <thead>
                                <tr>
                                    <th>Event (L1-L3)</th>
                                    <th>Matchup</th>
                                    <th class="text-center">Score</th>
                                    <th class="text-center">Status</th>
                                    <th>Date & Time</th>
                                    <th>Venue</th>
                                    <th>Manager</th>
                                    <!-- Removed "Actions" column -->
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($matches)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted p-4">No matches found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($matches as $match): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($match['category_name']) ?></strong>
                                                <small class="text-muted d-block"><?= htmlspecialchars($match['event_name']) ?> (<?= htmlspecialchars($match['game_name']) ?>)</small>
                                            </td>
                                            <td class="matchup-cell">
                                                <strong><?= htmlspecialchars($match['team1_name'] ?? 'N/A') ?></strong>
                                                <small class="text-muted d-block">vs</small>
                                                <strong><?= htmlspecialchars($match['team2_name'] ?? 'N/A') ?></strong>
                                            </td>
                                            <td class="text-center score-cell">
                                                <?= htmlspecialchars($match['score1']) ?> - <?= htmlspecialchars($match['score2']) ?>
                                            </td>
                                            <td class="text-center">
                                                <?= getStatusBadge($match['status']) ?>
                                            </td>
                                            <td>
                                                <?= $match['match_date'] ? htmlspecialchars(date('M d, Y', strtotime($match['match_date']))) : 'TBA' ?>
                                                <small class="text-muted d-block"><?= $match['match_time'] ? htmlspecialchars(date('g:i A', strtotime($match['match_time']))) : '' ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($match['venue']) ?></td>
                                            <td><?= htmlspecialchars($match['manager_name'] ?? 'N/A') ?></td>
                                            <!-- Removed action buttons (Edit/Delete) -->
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer (Copied from your dashboard) -->
    <footer class="bg-dark text-white py-4" style="margin-left: var(--sidebar-width);">
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
            <small class="text-muted">Developed by Tsunayoshi Sawada</small>
        </div>
    </footer>

    <!-- Modals have been completely removed -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            
            // --- Live Search and Filter Logic (Copied from Manage_Matches.php) ---
            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const tableBody = document.getElementById('matchesTable').querySelector('tbody');
            const allRows = tableBody.querySelectorAll('tr');

            function filterTable() {
                const searchText = searchInput.value.toLowerCase();
                const statusValue = statusFilter.value;

                allRows.forEach(row => {
                    const rowText = row.textContent.toLowerCase();
                    // Updated to find the badge text correctly
                    const statusCell = row.querySelector('td:nth-child(4)');
                    const rowStatus = statusCell ? statusCell.textContent.trim() : '';
                    
                    const matchesSearch = rowText.includes(searchText);
                    const matchesStatus = (statusValue === 'all' || rowStatus === statusValue);

                    if (matchesSearch && matchesStatus) {
                        row.style.display = ''; // Show row
                    } else {
                        row.style.display = 'none'; // Hide row
                    }
                });
            }

            if (searchInput) {
                searchInput.addEventListener('keyup', filterTable);
            }
            if (statusFilter) {
                statusFilter.addEventListener('change', filterTable);
            }

            // Script for modal population is removed as there are no modals.
        });
    </script>
</body>
</html>