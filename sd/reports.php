<?php
session_start();
// Use a relative path to your main db_connect.php
require_once '../db_connect.php'; 

// 1. SECURITY & ACCESS CONTROL
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Sports Director') {
    header('Location: ../login.php'); 
    exit();
}

if (!isset($_SESSION['user_id'])) {
    die("Session error: User ID is not set.");
}
$current_user_id = $_SESSION['user_id'];

// --- FETCH NAME LOGIC ---
$stmt_name = $conn->prepare("SELECT full_name, username FROM users WHERE id = ?");
$stmt_name->bind_param("i", $current_user_id);
$stmt_name->execute();
$result_name = $stmt_name->get_result();
$user_data = $result_name->fetch_assoc();
$stmt_name->close();

if (!empty($user_data['full_name'])) {
    $name = $user_data['full_name'];
} else {
    $name = $user_data['username'] ?? 'Sports Director';
}

$current_page = basename($_SERVER['PHP_SELF']);

// --- 1. FETCH SUMMARY DATA (FOR TOP TABLE) ---
$standings = [];
$sql_summary = "SELECT 
            C.college_id, C.college_name, C.logo_url,
            
            SUM(CASE WHEN CAT.gold_winner_college_id = C.college_id THEN CAT.gold_count ELSE 0 END) AS Gold,
            SUM(CASE WHEN CAT.silver_winner_college_id = C.college_id THEN CAT.silver_count ELSE 0 END) AS Silver,
            SUM(CASE WHEN CAT.bronze_winner_college_id = C.college_id THEN CAT.bronze_count ELSE 0 END) AS Bronze,
            
            (SUM(CASE WHEN CAT.gold_winner_college_id = C.college_id THEN CAT.gold_count ELSE 0 END) +
             SUM(CASE WHEN CAT.silver_winner_college_id = C.college_id THEN CAT.silver_count ELSE 0 END) +
             SUM(CASE WHEN CAT.bronze_winner_college_id = C.college_id THEN CAT.bronze_count ELSE 0 END))
            AS TotalMedals
            
        FROM colleges C
        
        LEFT JOIN categories CAT ON (
            C.college_id = CAT.gold_winner_college_id OR 
            C.college_id = CAT.silver_winner_college_id OR 
            C.college_id = CAT.bronze_winner_college_id
        ) 
        AND CAT.status = 'Results Approved'
        
        GROUP BY C.college_id, C.college_name, C.logo_url
        ORDER BY Gold DESC, Silver DESC, Bronze DESC, TotalMedals DESC, C.college_name ASC";
        
$result_summary = $conn->query($sql_summary);
if($result_summary) {
    $standings = $result_summary->fetch_all(MYSQLI_ASSOC);
} else {
    die("SQL Error (Summary): " . $conn->error);
}

// --- 2. FETCH DETAILED DATA (FOR BOTTOM TABLE) ---
$detailed_results = [];
$sql_detailed = "SELECT 
                    g.game_name, ge.event_name, c.category_name, c.category_type,
                    c.gold_count, c.silver_count, c.bronze_count,
                    gold_col.college_name AS gold_winner,
                    silver_col.college_name AS silver_winner,
                    bronze_col.college_name AS bronze_winner
                FROM categories c
                JOIN game_events ge ON c.event_id = ge.event_id
                JOIN games g ON ge.game_id = g.game_id
                LEFT JOIN colleges gold_col ON c.gold_winner_college_id = gold_col.college_id
                LEFT JOIN colleges silver_col ON c.silver_winner_college_id = silver_col.college_id
                LEFT JOIN colleges bronze_col ON c.bronze_winner_college_id = bronze_col.college_id
                WHERE c.status = 'Results Approved'
                ORDER BY g.game_name, ge.event_name, c.category_name";

$result_detailed = $conn->query($sql_detailed);
if($result_detailed) {
    $detailed_results = $result_detailed->fetch_all(MYSQLI_ASSOC);
} else {
    die("SQL Error (Detailed): " . $conn->error);
}

// Sidebar Badges
$pending_requests_count = $conn->query("SELECT COUNT(*) FROM account_requests WHERE status = 'pending'")->fetch_row()[0] ?? 0;
$pending_results_count = $conn->query("SELECT COUNT(*) FROM categories WHERE status='Results Submitted'")->fetch_row()[0] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medal Reports - Director Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* --- Unified CSS from Dashboard --- */
        :root { 
            --sidebar-width: 260px; 
            --header-height: 82px; 
            --transition: all 0.3s ease; 
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); 
            --bg-light: #F8F9FA; 
            --primary-gradient: linear-gradient(135deg, #2c3e50 0%, #4ca1af 100%);
            --accent-color: #1abc9c;
        }
        body { background-color: var(--bg-light); margin: 0; padding: 0; min-height: 100vh; font-family: 'Inter', sans-serif; display: flex; flex-direction: column; }
        
        /* Navbar */
        .navbar { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.15); padding: 1rem 1.5rem; height: var(--header-height); position: fixed; top: 0; left: 0; right: 0; z-index: 1050; }
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; transition: var(--transition); }
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
        .user-dropdown .dropdown-toggle img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; margin-right: 10px; }
        
        /* Sidebar */
        .sidebar { width: var(--sidebar-width); position: fixed; top: var(--header-height); left: 0; height: calc(100vh - var(--header-height)); background: #2c3e50; color: white; box-shadow: 5px 0 15px rgba(0,0,0,0.2); z-index: 1040; transition: width var(--transition); overflow-y: auto; }
        .sidebar-nav { padding: 20px 0; }
        .sidebar-nav .nav-link { color: rgba(255, 255, 255, 0.7); font-size: 1.05rem; font-weight: 500; padding: 12px 25px; transition: var(--transition); border-left: 5px solid transparent; margin: 2px 0; display: flex; align-items: center; text-decoration: none; }
        .sidebar-nav .nav-link i { width: 30px; text-align: center; flex-shrink: 0; font-size: 0.95em; }
        .sidebar-nav .nav-link:hover { color: white; background: rgba(255, 255, 255, 0.05); border-left-color: var(--accent-color); }
        .sidebar-nav .nav-link.active { color: white; background: rgba(255, 255, 255, 0.1); border-left-color: #3498db; font-weight: 600; }
        .sidebar-nav .nav-title { padding: 15px 25px 5px; font-size: 0.75rem; font-weight: 700; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px; }

        /* Main Content */
        .main-content { flex: 1 0 auto; padding: 30px; margin-top: var(--header-height); margin-left: var(--sidebar-width); transition: margin-left var(--transition); min-height: calc(100vh - var(--header-height)); }
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        
        /* Footer */
        footer { flex-shrink: 0; background: #2c3e50 !important; box-shadow: 0 -2px 10px rgba(0,0,0,0.1); padding-left: var(--sidebar-width); transition: padding-left var(--transition); position: relative; z-index: 1041; }
        @media (max-width: 992px) {
            .sidebar { left: -260px; }
            .sidebar.show { left: 0; }
            .main-content, footer { margin-left: 0; }
        }

        /* === ENHANCED TABLE DESIGN === */
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08); overflow: hidden; margin-bottom: 2rem; }
        .card-header { background: #ffffff !important; border-bottom: 2px solid #f1f3f5 !important; padding: 1.25rem 1.5rem !important; }
        
        .results-table-container {
            background: #ffffff;
            overflow-x: auto;
        }
        
        .results-table { margin-bottom: 0; font-size: 0.9375rem; width: 100%; border-collapse: separate; border-spacing: 0; }
        
        .results-table thead th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: #2c3e50;
            font-weight: 600;
            font-size: 0.8125rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 1rem 1.25rem;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
        }
        
        .results-table tbody td { padding: 1.125rem 1.25rem; vertical-align: middle; border-bottom: 1px solid #f1f3f5; transition: all 0.2s ease; }
        .results-table tbody tr { transition: all 0.2s ease; }
        .results-table tbody tr:hover { background-color: #f8f9fa; }

        /* Rank Column */
        .rank-cell { font-weight: 700; color: #555; font-size: 1rem; width: 60px; text-align: center; }

        /* Medal Numbers */
        .medal-number { font-family: 'Inter', sans-serif; font-weight: 600; font-size: 1.1rem; }
        .gold-text { color: #f59e0b; }
        .silver-text { color: #64748b; }
        .bronze-text { color: #ea580c; }
        .total-text { color: #2c3e50; font-weight: 700; }
        .winner-count { font-weight: 600; color: #333; font-size: 0.85em; opacity: 0.8; }

        /* Type Badges */
        .type-badge { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.4rem 0.75rem; border-radius: 6px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .type-badge.badge-match { background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%); color: #4338ca; border: 1px solid #a5b4fc; }
        .type-badge.badge-medal { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #b45309; border: 1px solid #fcd34d; }

        /* DataTables Customization */
        .dataTables_wrapper .dataTables_length, 
        .dataTables_wrapper .dataTables_filter { padding: 1rem 1.5rem; }
        .dataTables_wrapper .dataTables_info, 
        .dataTables_wrapper .dataTables_paginate { padding: 1rem 1.5rem; }
        
        /* Print Styling */
        @media print {
            .sidebar, .navbar, .btn, .dataTables_length, .dataTables_filter, .dataTables_paginate { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
        }
    </style>
</head>
<body>
    
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="sports_director_dashboard.php">
                <img src="../imageslogo.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
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
                    <i class="fas fa-user-circle" style="font-size: 36px; margin-right: 10px; color: rgba(255,255,255,0.8);"></i>
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="../admin_profile.php"><i class="fas fa-user-circle me-2"></i> Profile</a></li>
                    <li><a class="dropdown-item" href="../Tournament_Manager_page.php" target="_blank"><i class="fas fa-globe me-2"></i> Public Site</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
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
                <a class="nav-link" href="colleges.php">
                    <i class="fas fa-users me-2"></i> <span>Manage Teams</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="events.php">
                    <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events (L1-L3)</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="Manage_Matches.php">
                    <i class="fas fa-trophy me-2"></i> <span>Manage Matches</span>
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
                <a class="nav-link active" href="reports.php">
                    <i class="fas fa-chart-line me-2"></i> <span>Medal Standings</span>
                </a>
            </li>

            <!-- NEW SECTION: SEASON MANAGEMENT -->
            <li class="nav-item mt-3"><span class="nav-title">Season Management</span></li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'manage_archives.php') ? 'active' : '' ?>" href="../manage_archives.php">
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
            
            <nav aria-label="breadcrumb" class="mb-4">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="sports_director_dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Medal Reports</li>
              </ol>
            </nav>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="section-title mb-0">Official Medal Standings</h1>
                <button class="btn btn-secondary" onclick="window.print()">
                    <i class="fas fa-print me-2"></i> Print Report
                </button>
            </div>

            <!-- SUMMARY CARD -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-trophy text-warning me-2"></i> Overall Team Rankings</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive results-table-container">
                        <table class="table results-table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th class="text-center">Rank</th>
                                    <th>Team</th>
                                    <th class="text-center" style="color: #f59e0b;">Gold</th>
                                    <th class="text-center" style="color: #64748b;">Silver</th>
                                    <th class="text-center" style="color: #ea580c;">Bronze</th>
                                    <th class="text-center">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($standings)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted p-4">No approved results yet.</td>
                                </tr>
                                <?php endif; ?>
                                <?php $rank = 1; foreach ($standings as $college): ?>
                                <tr>
                                    <td class="rank-cell"><?= $rank++ ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <img src="../<?= htmlspecialchars($college['logo_url'] ?? 'images/default_avatar.png') ?>" alt="Logo" style="width: 36px; height: 36px; border-radius: 50%; object-fit: cover; margin-right: 15px; border: 1px solid #dee2e6;">
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($college['college_name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="text-center medal-number gold-text"><?= $college['Gold'] ?></td>
                                    <td class="text-center medal-number silver-text"><?= $college['Silver'] ?></td>
                                    <td class="text-center medal-number bronze-text"><?= $college['Bronze'] ?></td>
                                    <td class="text-center medal-number total-text"><?= $college['TotalMedals'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- DETAILED RESULTS CARD -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-list-ul me-2 text-primary"></i> Detailed Event Results</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive results-table-container">
                        <table id="detailedReportTable" class="table results-table table-hover align-middle" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Game</th>
                                    <th>Event</th>
                                    <th>Category</th>
                                    <th>Type</th>
                                    <th><i class="fas fa-medal text-warning me-1"></i> Gold</th>
                                    <th><i class="fas fa-medal text-secondary me-1"></i> Silver</th>
                                    <th><i class="fas fa-medal text-danger me-1"></i> Bronze</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($detailed_results as $row): ?>
                                <tr>
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($row['game_name']) ?></td>
                                    <td><?= htmlspecialchars($row['event_name']) ?></td>
                                    
                                    <td>
                                        <?php 
                                        if ($row['category_name'] === 'Main Event' || $row['category_name'] === 'Main Competition') {
                                            echo '<span class="text-muted fst-italic small">(no category)</span>';
                                        } else {
                                            echo '<span class="fw-semibold text-primary">' . htmlspecialchars($row['category_name']) . '</span>';
                                        }
                                        ?>
                                    </td>
                                    
                                    <td>
                                        <?php if (strtolower($row['category_type']) == 'match'): ?>
                                            <span class="type-badge badge-match"><i class="fas fa-basketball-ball"></i> Match</span>
                                        <?php else: ?>
                                            <span class="type-badge badge-medal"><i class="fas fa-medal"></i> Medal</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="gold-text fw-bold">
                                        <?= htmlspecialchars($row['gold_winner'] ?? 'N/A') ?>
                                        <?php if($row['gold_count'] > 1) echo "<span class='winner-count ms-1'>({$row['gold_count']})</span>"; ?>
                                    </td>
                                    <td class="silver-text fw-bold">
                                        <?= htmlspecialchars($row['silver_winner'] ?? 'N/A') ?>
                                        <?php if($row['silver_count'] > 1) echo "<span class='winner-count ms-1'>({$row['silver_count']})</span>"; ?>
                                    </td>
                                    <td class="bronze-text fw-bold">
                                        <?= htmlspecialchars($row['bronze_winner'] ?? 'N/A') ?>
                                        <?php if($row['bronze_count'] > 1) echo "<span class='winner-count ms-1'>({$row['bronze_count']})</span>"; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div> 
    
    <footer class="bg-dark text-white py-4">
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
            <small class="text-muted">Developed by Tsunayoshi Sawada</small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            
            // Initialize DataTable with custom wrapper class for styling consistency
            $('#detailedReportTable').DataTable({
                "lengthMenu": [ [10, 25, 50, -1], [10, 25, 50, "All"] ],
                "language": {
                    "search": "_INPUT_",
                    "searchPlaceholder": "Search records...",
                    "lengthMenu": "Show _MENU_ entries"
                },
                "dom": '<"d-flex justify-content-between align-items-center m-3"lf>t<"d-flex justify-content-between align-items-center m-3"ip>'
            });

            const mobileToggle = document.getElementById('mobileToggle');
            if (mobileToggle) {
                mobileToggle.addEventListener('click', function() {
                    document.getElementById('sidebar').classList.toggle('show');
                });
            }
            
            // Dynamic Footer
            const footer = document.querySelector('footer');
            const sidebar = document.getElementById('sidebar');
            const navbar = document.querySelector('.navbar');

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
        });
    </script>
</body>
</html>