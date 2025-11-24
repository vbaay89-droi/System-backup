<?php
session_start();
require_once '../db_connect.php'; // Use the main config file

// 1. SECURITY & ACCESS CONTROL
// STRICT: Only 'Sports Director' is allowed
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Sports Director') {
    header('Location: ../login.php'); 
    exit();
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
$user_id = (int)$_SESSION['user_id']; 

// Alert Messages
$alert_message = '';
$alert_type = 'success';

// 2. FORM HANDLING (APPROVE / REJECT / REVOKE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    $conn->begin_transaction();
    try {
        $category_id = (int)$_POST['category_id'];
        $event_name_for_log = $_POST['event_name_for_log'] ?? 'an event';

        // Action: Approve
        if ($_POST['action'] === 'approve_result') {
            $stmt_cat = $conn->prepare(
               "UPDATE categories SET 
                status = 'Results Approved',
                approved_by_user_id = ?, 
                approved_at = NOW()
                WHERE category_id = ? AND status = 'Results Submitted'"
            );
            $stmt_cat->bind_param("ii", $user_id, $category_id);
            $stmt_cat->execute();
            
            if ($stmt_cat->affected_rows > 0) {
                $conn->commit();
                $alert_message = "SUCCESS: The results have been approved and published!";
            } else {
                throw new Exception("Could not find the pending result. It might have already been processed.");
            }
            $stmt_cat->close();

        } 
        // Action: Reject
        elseif ($_POST['action'] === 'reject_result') {
            $rejection_reason = trim($_POST['rejection_reason']) ?: 'No reason provided.';
            
            $stmt_cat = $conn->prepare(
                "UPDATE categories SET 
                 status = 'Results Rejected', 
                 notes = ? 
                 WHERE category_id = ? AND status = 'Results Submitted'"
            );
            $stmt_cat->bind_param("si", $rejection_reason, $category_id);
            $stmt_cat->execute();
            
            if ($stmt_cat->affected_rows > 0) {
                $conn->commit();
                $alert_message = "SUCCESS: The results have been rejected and sent back to the Event Manager.";
            } else {
                 throw new Exception("Could not find the pending result.");
            }
            $stmt_cat->close();
        }
        
        // Action: Revoke
        elseif ($_POST['action'] === 'revoke_result') {
            $revoke_reason = trim($_POST['revoke_reason']) ?: 'Approval revoked by Director.';

            $stmt_cat = $conn->prepare(
                "UPDATE categories SET 
                 status = 'Results Rejected', 
                 notes = ?,
                 approved_by_user_id = NULL,
                 approved_at = NULL
                 WHERE category_id = ? AND status = 'Results Approved'"
            );
            $stmt_cat->bind_param("si", $revoke_reason, $category_id);
            $stmt_cat->execute();
            
            if ($stmt_cat->affected_rows > 0) {
                $conn->commit();
                $alert_message = "SUCCESS: The approval has been revoked.";
            } else {
                 throw new Exception("Could not find the approved result.");
            }
            $stmt_cat->close();
        }

    } catch (Exception $e) {
        $conn->rollback();
        $alert_message = "ERROR: " . $e->getMessage();
        $alert_type = 'danger';
        error_log($e->getMessage());
    }
}

// 3. FETCH DATA
// A. Colleges Map
$colleges_result = $conn->query("SELECT college_id, college_name FROM colleges");
$colleges = $colleges_result->fetch_all(MYSQLI_ASSOC);
$college_map = [];
foreach ($colleges as $college) {
    $college_map[$college['college_id']] = $college['college_name'];
}

function getCollegeName($college_id, $college_map) {
    if (empty($college_id) || $college_id == 0) return '<em>N/A</em>';
    return isset($college_map[$college_id]) ? htmlspecialchars($college_map[$college_id]) : '<em>Unknown</em>';
}

// B. Pending Results
$sql_pending = "SELECT 
            c.category_id, c.category_name, c.category_type,
            c.gold_winner_college_id, c.gold_count,
            c.silver_winner_college_id, c.silver_count,
            c.bronze_winner_college_id, c.bronze_count,
            c.updated_at AS submission_date, 
            ge.event_name, g.game_name, u.username AS submitted_by
        FROM categories c
        JOIN game_events ge ON c.event_id = ge.event_id
        JOIN games g ON ge.game_id = g.game_id
        LEFT JOIN event_manager_assignments ema ON c.event_id = ema.event_id
        LEFT JOIN users u ON ema.user_id = u.id
        WHERE c.status = 'Results Submitted'
        GROUP BY c.category_id
        ORDER BY c.updated_at DESC";
$result_pending = $conn->query($sql_pending);
$pending_results = ($result_pending) ? $result_pending->fetch_all(MYSQLI_ASSOC) : [];

// C. Approved Results
$sql_approved = "SELECT 
            c.category_id, c.category_name, c.category_type,
            c.gold_winner_college_id, c.gold_count,
            c.silver_winner_college_id, c.silver_count,
            c.bronze_winner_college_id, c.bronze_count,
            c.approved_at, 
            ge.event_name, g.game_name, u.username AS approved_by
        FROM categories c
        JOIN game_events ge ON c.event_id = ge.event_id
        JOIN games g ON ge.game_id = g.game_id
        LEFT JOIN users u ON c.approved_by_user_id = u.id
        WHERE c.status = 'Results Approved'
        ORDER BY c.approved_at DESC";
$result_approved = $conn->query($sql_approved);
$approved_results = ($result_approved) ? $result_approved->fetch_all(MYSQLI_ASSOC) : [];

// Sidebar Badges
$pending_requests_count = $conn->query("SELECT COUNT(*) FROM account_requests WHERE status = 'pending'")->fetch_row()[0] ?? 0;
$pending_results_count = count($pending_results); 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Results - Director Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
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
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08); overflow: hidden; }
        .card-header { background: #ffffff !important; border-bottom: 2px solid #f1f3f5 !important; padding: 0 !important; }
        
        /* Modern Table Container */
        .results-table-container {
            background: #ffffff;
            border-radius: 0 0 12px 12px;
            overflow-x: auto; /* Ensure scroll happens here */
            position: relative;
        }
        
        .results-table { margin-bottom: 0; font-size: 0.9375rem; border-collapse: separate; border-spacing: 0; width: 100%; }
        
        /* Enhanced Table Header */
        .results-table thead th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: #2c3e50;
            font-weight: 600;
            font-size: 0.8125rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 1.125rem 1.25rem;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
        }
        
        .results-table tbody td { padding: 1.25rem 1.25rem; vertical-align: middle; border-bottom: 1px solid #f1f3f5; transition: all 0.2s ease; }
        .results-table tbody tr { transition: all 0.2s ease; }
        .results-table tbody tr:hover { background-color: #f8f9fa; transform: none; /* Removed transform as it breaks sticky in some browsers */ }
        
        /* === UI FIX: STICKY ACTION COLUMN === */
        /* This ensures the last column stays visible when scrolling horizontally */
        .sticky-col {
            position: sticky;
            right: 0;
            z-index: 2;
            background-color: #fff; /* Default bg */
            box-shadow: -5px 0 10px rgba(0,0,0,0.05); /* Shadow to indicate scroll depth */
        }

        /* Specific header background to match gradient */
        .results-table thead th.sticky-col {
            background: #e9ecef; 
            z-index: 5; /* Higher z-index for header */
        }

        /* Ensure row hover color effects the sticky column too */
        .results-table tbody tr:hover .sticky-col {
            background-color: #f8f9fa;
        }
        /* ==================================== */

        /* Event Information Column */
        .event-info-cell { line-height: 1.6; }
        .event-game-name { font-weight: 700; color: #1e293b; font-size: 1rem; margin-bottom: 0.25rem; }
        .event-event-name { color: #64748b; font-size: 0.875rem; margin-bottom: 0.25rem; }
        .event-category-name { color: #1e293b; font-weight: 600; font-size: 0.875rem; }
        .event-no-category { color: #94a3b8; font-style: italic; font-size: 0.8125rem; }
        
        /* Type Badge Enhancement */
        .type-badge { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.5rem 0.875rem; border-radius: 8px; font-size: 0.8125rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08); }
        .type-badge.badge-match { background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%); color: #4338ca; border: 1px solid #a5b4fc; }
        .type-badge.badge-medal { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #b45309; border: 1px solid #fcd34d; }
        
        /* Winners Column - Enhanced Medal Display */
        .winners-column { display: flex; flex-direction: column; gap: 0.625rem; }
        .winner-row { display: flex; align-items: center; gap: 0.625rem; padding: 0.5rem 0.75rem; border-radius: 8px; transition: all 0.2s ease; border-left: 3px solid transparent; }
        .winner-row:hover { transform: translateX(3px); }
        .winner-row.gold-winner { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-left-color: #f59e0b; }
        .winner-row.silver-winner { background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%); border-left-color: #94a3b8; }
        .winner-row.bronze-winner { background: linear-gradient(135deg, #fed7aa 0%, #fdba74 100%); border-left-color: #ea580c; }
        
        .medal-icon.gold { color: #f59e0b; }
        .medal-icon.silver { color: #64748b; }
        .medal-icon.bronze { color: #ea580c; }
        
        .winner-college-name { flex: 1; font-weight: 600; color: #1e293b; font-size: 0.9375rem; white-space: normal; /* Allow text wrap */ }
        .winner-count { font-weight: 700; color: #1e293b; font-size: 0.875rem; background: rgba(255, 255, 255, 0.8); padding: 0.125rem 0.5rem; border-radius: 12px; min-width: 32px; text-align: center; }
        
        /* Submitted/Approved By Column */
        .submitter-info { display: flex; flex-direction: column; gap: 0.25rem; }
        .submitter-name { font-weight: 600; color: #1e293b; font-size: 0.9375rem; display: flex; align-items: center; gap: 0.375rem; }
        .submitter-name i { color: #64748b; font-size: 0.875rem; }
        .submission-date { color: #64748b; font-size: 0.8125rem; display: flex; align-items: center; gap: 0.375rem; }
        
        /* Action Buttons Enhancement */
        .action-buttons-group { display: flex; gap: 0.5rem; justify-content: flex-end; align-items: center; }
        .action-btn { width: 38px; height: 38px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; transition: all 0.2s ease; font-size: 0.875rem; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08); border: 2px solid transparent; }
        .action-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0, 0, 0, 0.12); }
        .action-btn.btn-success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-color: #059669; color: white; }
        .action-btn.btn-danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); border-color: #dc2626; color: white; }
        .action-btn.btn-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); border-color: #d97706; color: white; }
        
        /* STACKED BUTTONS STYLE */
        .action-stack {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            align-items: flex-end;
            justify-content: center;
        }
        .action-stack .btn {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }

        /* Empty State */
        .empty-state { padding: 4rem 2rem; text-align: center; background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); }
        .empty-state-icon { font-size: 4rem; color: #cbd5e1; margin-bottom: 1.5rem; opacity: 0.5; }
        .empty-state-title { font-size: 1.25rem; font-weight: 600; color: #475569; margin-bottom: 0.5rem; }
        .empty-state-text { font-size: 0.9375rem; color: #94a3b8; }
        
        /* Tab Navigation */
        .card-header-tabs { margin-bottom: -1rem; margin-left: 0; margin-right: 0; border-bottom: 0; }
        .nav-tabs .nav-link { border: none; border-bottom: 3px solid transparent; font-weight: 600; color: #6c757d; padding: 1rem 1.5rem; transition: all 0.2s ease; }
        .nav-tabs .nav-link:hover { color: #334155; border-color: #cbd5e1; }
        .nav-tabs .nav-link.active { border-bottom-color: #0d6efd; color: #0d6efd; background: none; }
        
        /* Animations */
        .results-table tbody tr { animation: fadeInUp 0.4s ease-out backwards; }
        .results-table tbody tr:nth-child(1) { animation-delay: 0.05s; }
        .results-table tbody tr:nth-child(2) { animation-delay: 0.1s; }
        .results-table tbody tr:nth-child(3) { animation-delay: 0.15s; }
        .results-table tbody tr:nth-child(4) { animation-delay: 0.2s; }
        .results-table tbody tr:nth-child(5) { animation-delay: 0.25s; }
        
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* Modal Enhancements */
        .modal-content { border-radius: 12px; border: none; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15); }
        .modal-header { border-top-left-radius: 12px; border-top-right-radius: 12px; padding: 1.25rem 1.5rem; }
        .modal-body { padding: 1.5rem; }
        .modal-footer { padding: 1rem 1.5rem; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>

    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="sports_director_dashboard.php">
                <img src="../imageslogo.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain;">
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

    <!-- UNIFIED SIDEBAR -->
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
                <a class="nav-link active" href="results.php">
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
                <li class="breadcrumb-item active" aria-current="page">Approve Results</li>
              </ol>
            </nav>

            <h1 class="section-title mb-4">Approve Medal Results</h1>
            
            <?php if (!empty($alert_message)): ?>
                <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($alert_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header bg-white pt-3">
                    <ul class="nav nav-tabs card-header-tabs" id="resultsTab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab">
                                <i class="fas fa-hourglass-half me-1"></i> Pending Submissions
                                <span class="badge bg-warning text-dark ms-1"><?php echo count($pending_results); ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="approved-tab" data-bs-toggle="tab" data-bs-target="#approved" type="button" role="tab">
                                <i class="fas fa-check-circle me-1"></i> Approved Results
                                <span class="badge bg-success ms-1"><?php echo count($approved_results); ?></span>
                            </button>
                        </li>
                    </ul>
                </div>
                
                <div class="tab-content" id="resultsTabContent">
                    
                    <!-- Pending Tab -->
                    <div class="tab-pane fade show active" id="pending" role="tabpanel">
                        <div class="card-body p-0">
                            <div class="table-responsive results-table-container">
                                <table class="table results-table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <!-- RESIZED: Reduced from 280px to 200px -->
                                            <th style="min-width: 200px;">Event Details</th>
                                            
                                            <!-- RESIZED: Reduced from 120px to 100px -->
                                            <th style="min-width: 100px;">Type</th>
                                            
                                            <th style="min-width: 320px;">Submitted Winners</th>
                                            
                                            <!-- RESIZED: Reduced from 180px to 160px -->
                                            <th style="min-width: 160px;">Submitted By</th>
                                            
                                            <!-- RESIZED: Reduced from 140px to 90px & Stacked -->
                                            <th class="text-end sticky-col" style="min-width: 90px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($pending_results)): ?>
                                            <tr>
                                                <td colspan="5" class="p-0">
                                                    <div class="empty-state">
                                                        <i class="fas fa-inbox empty-state-icon"></i>
                                                        <div class="empty-state-title">No Pending Submissions</div>
                                                        <div class="empty-state-text">All results have been reviewed. New submissions will appear here.</div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                        
                                        <?php foreach ($pending_results as $row): ?>
                                            <tr>
                                                <!-- Event Details Column -->
                                                <td class="event-info-cell">
                                                    <div class="event-game-name"><?php echo htmlspecialchars($row['game_name']); ?></div>
                                                    <div class="event-event-name"><?php echo htmlspecialchars($row['event_name']); ?></div>
                                                    <?php 
                                                    if ($row['category_name'] === 'Main Event' || $row['category_name'] === 'Main Competition') {
                                                        echo '<div class="event-no-category">(no category)</div>';
                                                    } else {
                                                        echo '<div class="event-category-name">' . htmlspecialchars($row['category_name']) . '</div>';
                                                    }
                                                    ?>
                                                </td>
                                                
                                                <!-- Type Column -->
                                                <td>
                                                    <?php if (strtolower($row['category_type']) == 'match'): ?>
                                                        <span class="type-badge badge-match">
                                                            <i class="fas fa-trophy"></i>
                                                            Match
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="type-badge badge-medal">
                                                            <i class="fas fa-medal"></i>
                                                            Medal
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                
                                                <!-- Winners Column -->
                                                <td>
                                                    <div class="winners-column">
                                                        <div class="winner-row gold-winner">
                                                            <i class="fas fa-medal medal-icon gold"></i>
                                                            <span class="winner-college-name">
                                                                <?php echo getCollegeName($row['gold_winner_college_id'], $college_map); ?>
                                                            </span>
                                                            <span class="winner-count"><?php echo (int)$row['gold_count']; ?></span>
                                                        </div>
                                                        <div class="winner-row silver-winner">
                                                            <i class="fas fa-medal medal-icon silver"></i>
                                                            <span class="winner-college-name">
                                                                <?php echo getCollegeName($row['silver_winner_college_id'], $college_map); ?>
                                                            </span>
                                                            <span class="winner-count"><?php echo (int)$row['silver_count']; ?></span>
                                                        </div>
                                                        <div class="winner-row bronze-winner">
                                                            <i class="fas fa-medal medal-icon bronze"></i>
                                                            <span class="winner-college-name">
                                                                <?php echo getCollegeName($row['bronze_winner_college_id'], $college_map); ?>
                                                            </span>
                                                            <span class="winner-count"><?php echo (int)$row['bronze_count']; ?></span>
                                                        </div>
                                                    </div>
                                                </td>
                                                
                                                <!-- Submitted By Column -->
                                                <td>
                                                    <div class="submitter-info">
                                                        <div class="submitter-name">
                                                            <i class="fas fa-user-circle"></i>
                                                            <?php echo htmlspecialchars($row['submitted_by'] ?? 'N/A'); ?>
                                                        </div>
                                                        <div class="submission-date">
                                                            <i class="fas fa-clock"></i>
                                                            <?php echo date('M d, h:i A', strtotime($row['submission_date'])); ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                
                                                <!-- Actions Column: Stacked Layout -->
                                                <td class="text-end sticky-col">
                                                    <div class="action-stack">
                                                        <form method="POST" action="results.php" class="w-100">
                                                            <input type="hidden" name="action" value="approve_result">
                                                            <input type="hidden" name="category_id" value="<?php echo $row['category_id']; ?>">
                                                            <button type="submit" class="btn btn-success btn-sm" title="Approve">
                                                                <i class="fas fa-check me-1"></i> Approve
                                                            </button>
                                                        </form>
                                                        <button type="button" class="btn btn-danger btn-sm w-100" title="Reject"
                                                                data-bs-toggle="modal" data-bs-target="#rejectModal"
                                                                data-id="<?php echo $row['category_id']; ?>"
                                                                data-name="<?php echo htmlspecialchars($row['category_name']); ?>">
                                                            <i class="fas fa-times me-1"></i> Reject
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Approved Tab -->
                    <div class="tab-pane fade" id="approved" role="tabpanel">
                        <div class="card-body p-0">
                            <div class="table-responsive results-table-container">
                                <table class="table results-table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th style="min-width: 200px;">Event Details</th>
                                            <th style="min-width: 100px;">Type</th>
                                            <th style="min-width: 320px;">Winners</th>
                                            <th style="min-width: 160px;">Approved By</th>
                                            <th class="text-end sticky-col" style="min-width: 90px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($approved_results)): ?>
                                            <tr>
                                                <td colspan="5" class="p-0">
                                                    <div class="empty-state">
                                                        <i class="fas fa-check-circle empty-state-icon"></i>
                                                        <div class="empty-state-title">No Approved Results Yet</div>
                                                        <div class="empty-state-text">Approved results will be displayed here for your review.</div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                        
                                        <?php foreach ($approved_results as $row): ?>
                                            <tr>
                                                <!-- Event Details Column -->
                                                <td class="event-info-cell">
                                                    <div class="event-game-name"><?php echo htmlspecialchars($row['game_name']); ?></div>
                                                    <div class="event-event-name"><?php echo htmlspecialchars($row['event_name']); ?></div>
                                                    <?php 
                                                    if ($row['category_name'] === 'Main Event' || $row['category_name'] === 'Main Competition') {
                                                        echo '<div class="event-no-category">(no category)</div>';
                                                    } else {
                                                        echo '<div class="event-category-name">' . htmlspecialchars($row['category_name']) . '</div>';
                                                    }
                                                    ?>
                                                </td>
                                                
                                                <!-- Type Column -->
                                                <td>
                                                    <?php if (strtolower($row['category_type']) == 'match'): ?>
                                                        <span class="type-badge badge-match">
                                                            <i class="fas fa-trophy"></i>
                                                            Match
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="type-badge badge-medal">
                                                            <i class="fas fa-medal"></i>
                                                            Medal
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                
                                                <!-- Winners Column -->
                                                <td>
                                                    <div class="winners-column">
                                                        <div class="winner-row gold-winner">
                                                            <i class="fas fa-medal medal-icon gold"></i>
                                                            <span class="winner-college-name">
                                                                <?php echo getCollegeName($row['gold_winner_college_id'], $college_map); ?>
                                                            </span>
                                                            <span class="winner-count"><?php echo (int)$row['gold_count']; ?></span>
                                                        </div>
                                                        <div class="winner-row silver-winner">
                                                            <i class="fas fa-medal medal-icon silver"></i>
                                                            <span class="winner-college-name">
                                                                <?php echo getCollegeName($row['silver_winner_college_id'], $college_map); ?>
                                                            </span>
                                                            <span class="winner-count"><?php echo (int)$row['silver_count']; ?></span>
                                                        </div>
                                                        <div class="winner-row bronze-winner">
                                                            <i class="fas fa-medal medal-icon bronze"></i>
                                                            <span class="winner-college-name">
                                                                <?php echo getCollegeName($row['bronze_winner_college_id'], $college_map); ?>
                                                            </span>
                                                            <span class="winner-count"><?php echo (int)$row['bronze_count']; ?></span>
                                                        </div>
                                                    </div>
                                                </td>
                                                
                                                <!-- Approved By Column -->
                                                <td>
                                                    <div class="submitter-info">
                                                        <div class="submitter-name">
                                                            <i class="fas fa-user-shield"></i>
                                                            <?php echo htmlspecialchars($row['approved_by'] ?? 'N/A'); ?>
                                                        </div>
                                                        <div class="submission-date">
                                                            <i class="fas fa-check-circle"></i>
                                                            <?php echo date('M d, h:i A', strtotime($row['approved_at'])); ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                
                                                <!-- Actions Column: Stacked -->
                                                <td class="text-end sticky-col">
                                                    <div class="action-stack">
                                                        <!-- FIXED: Removed data-bs-toggle="tooltip" to allow modal to work -->
                                                        <button type="button" class="btn btn-warning btn-sm w-100" title="Revoke Approval" 
                                                                data-bs-toggle="modal" data-bs-target="#revokeModal"
                                                                data-id="<?php echo $row['category_id']; ?>"
                                                                data-name="<?php echo htmlspecialchars($row['category_name']); ?>">
                                                            <i class="fas fa-undo me-1"></i> Revoke
                                                        </button>
                                                    </div>
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
        </div>
    </div>

    <footer class="bg-dark text-white py-4">
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
            <small class="text-muted">Developed by Tsunayoshi Sawada</small>
        </div>
    </footer>

    <!-- Reject Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="results.php">
                    <input type="hidden" name="action" value="reject_result">
                    <input type="hidden" name="category_id" id="reject_category_id">
                    
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">Reject Result</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Reject result for: <strong id="reject_category_name"></strong>?</p>
                        <div class="mb-3">
                            <label class="form-label">Reason (Optional):</label>
                            <textarea class="form-control" name="rejection_reason" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Revoke Modal -->
    <div class="modal fade" id="revokeModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="results.php">
                    <input type="hidden" name="action" value="revoke_result">
                    <input type="hidden" name="category_id" id="revoke_category_id">
                    
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title">Revoke Approval</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Revoke approval for: <strong id="revoke_category_name"></strong>?</p>
                        <p class="text-muted small">This will unpublish the result and send it back to pending.</p>
                        <div class="mb-3">
                            <label class="form-label">Reason (Optional):</label>
                            <textarea class="form-control" name="revoke_reason" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Revoke</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Reject Modal Data
            const rejectModal = document.getElementById('rejectModal');
            if(rejectModal) {
                rejectModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    document.getElementById('reject_category_id').value = button.getAttribute('data-id');
                    document.getElementById('reject_category_name').textContent = button.getAttribute('data-name');
                });
            }

            // Revoke Modal Data
            const revokeModal = document.getElementById('revokeModal');
            if(revokeModal) {
                revokeModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    document.getElementById('revoke_category_id').value = button.getAttribute('data-id');
                    document.getElementById('revoke_category_name').textContent = button.getAttribute('data-name');
                });
            }

            // Sidebar Toggle
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