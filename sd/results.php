<?php
session_start();
require_once '../config.php'; 

// 1. SECURITY & ACCESS CONTROL
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

$name = !empty($user_data['full_name']) ? $user_data['full_name'] : ($user_data['username'] ?? 'Sports Director');
$current_page = basename($_SERVER['PHP_SELF']); 

// Alert Messages
$alert_message = '';
$alert_type = 'success';

// 2. FORM HANDLING (APPROVE / REJECT / REVOKE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    $conn->begin_transaction();
    try {
        $category_id = (int)$_POST['category_id'];

        // Action: Approve
        if ($_POST['action'] === 'approve_result') {
            $stmt_cat = $conn->prepare(
               "UPDATE categories SET 
                status = 'Results Approved',
                approved_by_user_id = ?, 
                approved_at = NOW()
                WHERE category_id = ? AND status = 'Results Submitted'"
            );
            $stmt_cat->bind_param("ii", $current_user_id, $category_id);
            $stmt_cat->execute();
            
            if ($stmt_cat->affected_rows > 0) {
                // Log Activity
                $log_context = json_encode(['category_id' => $category_id, 'action' => 'Approve']);
                $conn->query("INSERT INTO system_logs (actor_user_id, action_type, related_id, related_table, log_context) 
                              VALUES ($current_user_id, 'APPROVED_RESULT', $category_id, 'categories', '$log_context')");

                $conn->commit();
                $alert_message = "SUCCESS: Results verified and approved!";
            } else {
                throw new Exception("Could not process request. Status might have changed.");
            }
            $stmt_cat->close();

        } 
        // Action: Reject
        elseif ($_POST['action'] === 'reject_result') {
            $rejection_reason = trim($_POST['rejection_reason']) ?: 'Evidence mismatch or data error.';
            
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
                $alert_message = "SUCCESS: Results rejected. Event Manager has been notified.";
                $alert_type = "warning";
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
                $alert_message = "SUCCESS: Approval revoked. Result sent back for correction.";
                $alert_type = "warning";
            } else {
                 throw new Exception("Could not find the approved result.");
            }
            $stmt_cat->close();
        }

    } catch (Exception $e) {
        $conn->rollback();
        $alert_message = "ERROR: " . $e->getMessage();
        $alert_type = 'danger';
    }
}

// 3. FETCH DATA & HELPERS
// College Map Helper
$colleges_result = $conn->query("SELECT college_id, college_name FROM colleges");
$college_map = [];
while ($c = $colleges_result->fetch_assoc()) { $college_map[$c['college_id']] = $c['college_name']; }

function getCollegeName($id, $map) {
    return (!empty($id) && isset($map[$id])) ? htmlspecialchars($map[$id]) : '<span class="text-muted fst-italic">N/A</span>';
}

// FETCH PENDING RESULTS
// Updated SQL to prioritize Full Name, fallback to Username
$sql_pending = "SELECT 
            c.category_id, c.category_name, c.category_type, c.tally_sheet_url,
            c.gold_winner_college_id, c.gold_count,
            c.silver_winner_college_id, c.silver_count,
            c.bronze_winner_college_id, c.bronze_count,
            c.updated_at AS submission_date, 
            ge.event_name, g.game_name, 
            COALESCE(u.full_name, u.username) AS submitted_by_name
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

// FETCH APPROVED RESULTS
// Updated SQL to prioritize Full Name for Approver
$sql_approved = "SELECT 
            c.category_id, c.category_name, c.category_type, c.tally_sheet_url,
            c.gold_winner_college_id, c.gold_count,
            c.silver_winner_college_id, c.silver_count,
            c.bronze_winner_college_id, c.bronze_count,
            c.approved_at, 
            ge.event_name, g.game_name, 
            COALESCE(u.full_name, u.username) AS approved_by_name
        FROM categories c
        JOIN game_events ge ON c.event_id = ge.event_id
        JOIN games g ON ge.game_id = g.game_id
        LEFT JOIN users u ON c.approved_by_user_id = u.id
        WHERE c.status = 'Results Approved'
        ORDER BY c.approved_at DESC";
$result_approved = $conn->query($sql_approved);
$approved_results = ($result_approved) ? $result_approved->fetch_all(MYSQLI_ASSOC) : [];

// Counts
$pending_requests_count = $conn->query("SELECT COUNT(*) FROM users WHERE is_approved = 0")->fetch_row()[0] ?? 0;
$pending_results_count = count($pending_results); 
$approved_results_count = count($approved_results); // Count for history tab

// --- AJAX REAL-TIME UPDATE HANDLER ---
if (isset($_GET['ajax_update']) && $_GET['ajax_update'] == '1') {
    // 1. Fetch Pending Results
    $sql_pending = "SELECT 
            c.category_id, c.category_name, c.category_type, c.tally_sheet_url,
            c.gold_winner_college_id, c.gold_count,
            c.silver_winner_college_id, c.silver_count,
            c.bronze_winner_college_id, c.bronze_count,
            c.updated_at AS submission_date, 
            ge.event_name, g.game_name, 
            COALESCE(u.full_name, u.username) AS submitted_by_name
        FROM categories c
        JOIN game_events ge ON c.event_id = ge.event_id
        JOIN games g ON ge.game_id = g.game_id
        LEFT JOIN event_manager_assignments ema ON c.event_id = ema.event_id
        LEFT JOIN users u ON ema.user_id = u.id
        WHERE c.status = 'Results Submitted'
        GROUP BY c.category_id
        ORDER BY c.updated_at DESC";
    $pending_results = $conn->query($sql_pending)->fetch_all(MYSQLI_ASSOC);

    // 2. Fetch Approved Results
    $sql_approved = "SELECT 
            c.category_id, c.category_name, c.category_type, c.tally_sheet_url,
            c.approved_at, ge.event_name, g.game_name, 
            COALESCE(u.full_name, u.username) AS approved_by_name
        FROM categories c
        JOIN game_events ge ON c.event_id = ge.event_id
        JOIN games g ON ge.game_id = g.game_id
        LEFT JOIN users u ON c.approved_by_user_id = u.id
        WHERE c.status = 'Results Approved'
        ORDER BY c.approved_at DESC";
    $approved_results = $conn->query($sql_approved)->fetch_all(MYSQLI_ASSOC);

    // 3. Generate HTML for Pending Table
    ob_start();
    if (empty($pending_results)) {
        echo '<tr><td colspan="3" class="text-center py-5 text-muted"><i class="fas fa-inbox fa-3x mb-3 opacity-25"></i><br>All caught up! No pending results.</td></tr>';
    } else {
        foreach ($pending_results as $row) {
            // Re-use your existing logic to generate the row. 
            // NOTE: Ensure variable names match exactly what you use in the main HTML.
            $gold = getCollegeName($row['gold_winner_college_id'], $college_map);
            $silver = getCollegeName($row['silver_winner_college_id'], $college_map);
            $bronze = getCollegeName($row['bronze_winner_college_id'], $college_map);
            $evidence = !empty($row['tally_sheet_url']) ? '../' . htmlspecialchars($row['tally_sheet_url']) : '';
            
            echo '<tr>
                <td>
                    <div class="fw-bold text-dark">' . htmlspecialchars($row['game_name']) . '</div>
                    <div class="text-primary small fw-semibold">' . htmlspecialchars($row['event_name']) . '</div>
                    <div class="text-muted small">' . htmlspecialchars($row['category_name']) . '</div>
                </td>
                <td>
                    <div class="fw-bold text-dark"><i class="fas fa-user-circle me-1"></i> ' . htmlspecialchars($row['submitted_by_name'] ?? 'Unknown') . '</div>
                    <div class="small text-muted">' . date('M d, h:i A', strtotime($row['submission_date'])) . '</div>
                </td>
                <td class="text-end">
                    <button type="button" class="btn btn-primary btn-sm px-3 shadow-sm rounded-pill review-btn"
                        data-bs-toggle="modal" data-bs-target="#verificationModal"
                        data-id="' . $row['category_id'] . '"
                        data-event="' . htmlspecialchars($row['event_name'] . ' - ' . $row['category_name']) . '"
                        data-gold-name="' . $gold . '" data-gold-count="' . $row['gold_count'] . '"
                        data-silver-name="' . $silver . '" data-silver-count="' . $row['silver_count'] . '"
                        data-bronze-name="' . $bronze . '" data-bronze-count="' . $row['bronze_count'] . '"
                        data-evidence="' . $evidence . '">
                        <i class="fas fa-search me-1"></i> Review Submission
                    </button>
                </td>
            </tr>';
        }
    }
    $pending_html = ob_get_clean();

    // 4. Generate HTML for Approved Table
    ob_start();
    if (empty($approved_results)) {
        echo '<tr><td colspan="4" class="text-center py-5 text-muted">No approved results yet.</td></tr>';
    } else {
        foreach ($approved_results as $row) {
            $proof_btn = !empty($row['tally_sheet_url']) 
                ? '<a href="' . htmlspecialchars('../' . $row['tally_sheet_url']) . '" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fas fa-file-image me-1"></i> View Proof</a>'
                : '<span class="badge bg-light text-muted border">No Evidence</span>';

            echo '<tr>
                <td><span class="fw-bold">' . htmlspecialchars($row['event_name']) . '</span><br><span class="small text-muted">' . htmlspecialchars($row['category_name']) . '</span></td>
                <td><span class="fw-bold">' . htmlspecialchars($row['approved_by_name'] ?? 'System') . '</span><br><span class="small text-muted">' . date('M d, Y', strtotime($row['approved_at'])) . '</span></td>
                <td>' . $proof_btn . '</td>
                <td class="text-end">
                    <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#revokeModal"
                            data-id="' . $row['category_id'] . '" data-name="' . htmlspecialchars($row['category_name']) . '">
                        <i class="fas fa-undo"></i> Revoke
                    </button>
                </td>
            </tr>';
        }
    }
    $approved_html = ob_get_clean();

    // 5. Return JSON Response
    header('Content-Type: application/json');
    echo json_encode([
        'pending_count' => count($pending_results),
        'approved_count' => count($approved_results),
        'pending_html' => $pending_html,
        'approved_html' => $approved_html
    ]);
    exit; // Stop script execution here
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Results - Director Panel</title>
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
            --gold: #f59e0b; --silver: #64748b; --bronze: #ea580c;
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

        .main-content { flex: 1 0 auto; padding: 30px; margin-top: var(--header-height); margin-left: var(--sidebar-width); transition: margin-left var(--transition); min-height: calc(100vh - var(--header-height)); }
        
        /* Footer */
        footer {
            flex-shrink: 0;
            background: #2c3e50 !important;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            padding-left: var(--sidebar-width);
            transition: padding-left var(--transition);
            position: relative;
            z-index: 1041;
        }
        .sidebar.minimized ~ footer {
            padding-left: var(--sidebar-min-width);
        }

        @media (max-width: 992px) {
            .sidebar { left: -260px; }
            .sidebar.show { left: 0; }
            .main-content, footer { margin-left: 0; }
        }

        /* --- VERIFICATION UI STYLES --- */
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.05); overflow: hidden; margin-bottom: 2rem; }
        .card-header { background: white; border-bottom: 1px solid #f1f3f5; padding: 1.25rem 1.5rem; }
        .nav-tabs .nav-link { border: none; font-weight: 600; color: #64748b; padding: 1rem 1.5rem; }
        .nav-tabs .nav-link.active { color: #2563eb; border-bottom: 3px solid #2563eb; background: transparent; }
        
        .results-table thead th { background: #f8fafc; color: #475569; font-weight: 600; font-size: 0.8rem; text-transform: uppercase; padding: 1rem 1.25rem; border-bottom: 1px solid #e2e8f0; }
        .results-table tbody td { padding: 1.25rem; vertical-align: middle; border-bottom: 1px solid #f1f3f5; }
        
        /* Modal Split View with ZOOM */
        .evidence-col {
            background: #0f172a; 
            display: flex;
            align-items: flex-start;
            justify-content: center;
            height: 500px;
            overflow: auto; /* ENABLE SCROLLING */
            position: relative;
            border-radius: 8px 0 0 8px;
            cursor: zoom-in;
        }
        .evidence-col::-webkit-scrollbar { width: 8px; height: 8px; }
        .evidence-col::-webkit-scrollbar-track { background: #1e293b; }
        .evidence-col::-webkit-scrollbar-thumb { background: #475569; border-radius: 4px; }

        .evidence-img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            transition: all 0.3s ease; 
            margin: auto;
        }
        .evidence-img.zoomed {
            max-width: none;
            max-height: none;
            width: 200%; 
            cursor: zoom-out;
        }
        
        .data-col { padding-left: 1.5rem; }
        .winner-card { border: 1px solid #f1f3f5; border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem; display: flex; align-items: center; }
        .winner-card.gold { background: linear-gradient(to right, #fffbf0, #fff); border-left: 4px solid var(--gold); }
        .winner-card.silver { background: linear-gradient(to right, #f8f9fa, #fff); border-left: 4px solid var(--silver); }
        .winner-card.bronze { background: linear-gradient(to right, #fff7ed, #fff); border-left: 4px solid var(--bronze); }
        
        .winner-icon { font-size: 1.5rem; margin-right: 1rem; }
        .text-gold { color: var(--gold); } .text-silver { color: var(--silver); } .text-bronze { color: var(--bronze); }
        
        .verification-arrow { display: flex; align-items: center; justify-content: center; color: #2563eb; font-weight: 700; font-size: 0.9rem; margin: 1rem 0; text-transform: uppercase; letter-spacing: 1px; }
        .verification-arrow::before, .verification-arrow::after { content: ''; flex: 1; border-bottom: 1px dashed #cbd5e1; margin: 0 10px; }
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
                    <i class="fas fa-user-circle" style="font-size: 36px; margin-right: 10px;"></i>
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
                <a class="nav-link <?= ($current_page == 'sports_director_dashboard.php') ? 'active' : '' ?>" href="sports_director_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                </a>
            </li>
            
            <li class="nav-item mt-3"><span class="nav-title">Tournament Mgmt</span></li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'colleges.php') ? 'active' : '' ?>" href="colleges.php">
                    <i class="fas fa-users me-2"></i> <span>Manage Teams</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'events.php') ? 'active' : '' ?>" href="events.php">
                    <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events (L1-L3)</span>
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
                <a class="nav-link <?= ($current_page == 'reports.php') ? 'active' : '' ?>" href="reports.php">
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
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="section-title fw-bold text-dark m-0">Approve Medal Results</h2>
            </div>

            <?php if ($alert_message): ?>
                <div class="alert alert-<?= $alert_type ?> alert-dismissible fade show shadow-sm" role="alert">
                    <i class="fas fa-<?= $alert_type == 'success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i> <?= htmlspecialchars($alert_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header p-0">
                    <ul class="nav nav-tabs border-0" id="resultsTab" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pending">
                                <i class="fas fa-hourglass-half me-2 text-warning"></i>Pending Review 
                                <span class="badge bg-warning text-dark ms-2"><?= $pending_results_count ?></span>
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#approved">
                                <i class="fas fa-check-circle me-2 text-success"></i>Approved History
                                <span class="badge bg-success text-white ms-2"><?= $approved_results_count ?></span>
                            </button>
                        </li>
                    </ul>
                </div>

                <div class="tab-content">
                    <!-- PENDING TAB -->
                    <div class="tab-pane fade show active" id="pending">
                        <div class="table-responsive">
                            <table class="table results-table table-hover mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>Event Information</th>
                                        <th>Submitted By</th>
                                        <th class="text-end">Verification</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($pending_results)): ?>
                                        <tr><td colspan="3" class="text-center py-5 text-muted"><i class="fas fa-inbox fa-3x mb-3 opacity-25"></i><br>All caught up! No pending results.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($pending_results as $row): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($row['game_name']) ?></div>
                                                    <div class="text-primary small fw-semibold"><?= htmlspecialchars($row['event_name']) ?></div>
                                                    <div class="text-muted small"><?= htmlspecialchars($row['category_name']) ?></div>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark"><i class="fas fa-user-circle me-1"></i> <?= htmlspecialchars($row['submitted_by_name'] ?? 'Unknown') ?></div>
                                                    <div class="small text-muted"><?= date('M d, h:i A', strtotime($row['submission_date'])) ?></div>
                                                </td>
                                                <td class="text-end">
                                                    <!-- REVIEW BUTTON: Triggers the Evidence Modal -->
                                                    <button type="button" class="btn btn-primary btn-sm px-3 shadow-sm rounded-pill review-btn"
                                                        data-bs-toggle="modal" data-bs-target="#verificationModal"
                                                        data-id="<?= $row['category_id'] ?>"
                                                        data-event="<?= htmlspecialchars($row['event_name'] . ' - ' . $row['category_name']) ?>"
                                                        data-gold-name="<?= getCollegeName($row['gold_winner_college_id'], $college_map) ?>"
                                                        data-gold-count="<?= $row['gold_count'] ?>"
                                                        data-silver-name="<?= getCollegeName($row['silver_winner_college_id'], $college_map) ?>"
                                                        data-silver-count="<?= $row['silver_count'] ?>"
                                                        data-bronze-name="<?= getCollegeName($row['bronze_winner_college_id'], $college_map) ?>"
                                                        data-bronze-count="<?= $row['bronze_count'] ?>"
                                                        data-evidence="<?= !empty($row['tally_sheet_url']) ? '../' . htmlspecialchars($row['tally_sheet_url']) : '' ?>">
                                                        <i class="fas fa-search me-1"></i> Review Submission
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- APPROVED TAB -->
                    <div class="tab-pane fade" id="approved">
                        <div class="table-responsive">
                            <table class="table results-table table-hover mb-0 align-middle">
                                <thead><tr><th>Event</th><th>Approved By</th><th>Evidence</th><th class="text-end">Actions</th></tr></thead>
                                <tbody>
                                    <?php if (empty($approved_results)): ?>
                                        <tr><td colspan="4" class="text-center py-5 text-muted">No approved results yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($approved_results as $row): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-bold"><?= htmlspecialchars($row['event_name']) ?></span><br>
                                                    <span class="small text-muted"><?= htmlspecialchars($row['category_name']) ?></span>
                                                </td>
                                                <td>
                                                    <span class="fw-bold"><?= htmlspecialchars($row['approved_by_name'] ?? 'System') ?></span><br>
                                                    <span class="small text-muted"><?= date('M d, Y', strtotime($row['approved_at'])) ?></span>
                                                </td>
                                                <td>
                                                    <?php if(!empty($row['tally_sheet_url'])): ?>
                                                        <a href="<?= htmlspecialchars('../' . $row['tally_sheet_url']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fas fa-file-image me-1"></i> View Proof</a>
                                                    <?php else: ?>
                                                        <span class="badge bg-light text-muted border">No Evidence</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#revokeModal"
                                                            data-id="<?= $row['category_id'] ?>" data-name="<?= htmlspecialchars($row['category_name']) ?>">
                                                        <i class="fas fa-undo"></i> Revoke
                                                    </button>
                                                </td>
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
    </div>

    <!-- MAIN VERIFICATION MODAL (The Core Evidence Feature) -->
    <div class="modal fade" id="verificationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered"> <!-- Extra Large Modal for Split View -->
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="fas fa-clipboard-check me-2"></i>Verify Result Evidence</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="row g-0">
                        <!-- LEFT COL: EVIDENCE IMAGE -->
                        <div class="col-lg-7 evidence-col bg-dark">
                            <div id="evidenceContainer" class="text-center w-100 p-3">
                                <img src="" id="modalEvidenceImg" class="evidence-img shadow-lg rounded" alt="Evidence Tally Sheet">
                                <div id="noEvidenceMsg" class="text-white-50 d-none">
                                    <i class="fas fa-image fa-3x mb-3"></i><br>No digital evidence uploaded for this event.
                                </div>
                            </div>
                        </div>

                        <!-- RIGHT COL: DIGITAL DATA -->
                        <div class="col-lg-5 data-col bg-white p-4 d-flex flex-column">
                            <h6 class="text-uppercase text-muted fw-bold small mb-3">Digital Entry Comparison</h6>
                            <h4 class="fw-bold text-dark mb-4" id="modalEventTitle">Event Name</h4>

                            <div class="winner-card gold">
                                <i class="fas fa-medal winner-icon text-gold"></i>
                                <div class="flex-grow-1">
                                    <div class="small fw-bold text-muted text-uppercase">Gold Winner</div>
                                    <div class="fw-bold fs-5" id="modalGoldName">Team A</div>
                                </div>
                                <div class="badge bg-warning text-dark rounded-pill fs-6" id="modalGoldCount">0</div>
                            </div>

                            <div class="winner-card silver">
                                <i class="fas fa-medal winner-icon text-silver"></i>
                                <div class="flex-grow-1">
                                    <div class="small fw-bold text-muted text-uppercase">Silver Winner</div>
                                    <div class="fw-bold fs-5" id="modalSilverName">Team B</div>
                                </div>
                                <div class="badge bg-secondary text-white rounded-pill fs-6" id="modalSilverCount">0</div>
                            </div>

                            <div class="winner-card bronze">
                                <i class="fas fa-medal winner-icon text-bronze"></i>
                                <div class="flex-grow-1">
                                    <div class="small fw-bold text-muted text-uppercase">Bronze Winner</div>
                                    <div class="fw-bold fs-5" id="modalBronzeName">Team C</div>
                                </div>
                                <div class="badge bg-light text-dark border rounded-pill fs-6" id="modalBronzeCount">0</div>
                            </div>

                            <div class="verification-arrow">Does this match the image?</div>

                            <div class="mt-auto d-grid gap-2">
                                <form method="POST" id="approveForm">
                                    <input type="hidden" name="action" value="approve_result">
                                    <input type="hidden" name="category_id" id="approveCatId">
                                    <button type="submit" class="btn btn-success w-100 py-2 fw-bold shadow-sm">
                                        <i class="fas fa-check-circle me-2"></i> YES, Approve Result
                                    </button>
                                </form>
                                <button type="button" class="btn btn-outline-danger w-100 fw-bold" id="btnShowReject">
                                    <i class="fas fa-times-circle me-2"></i> NO, Reject (Mismatch)
                                </button>
                            </div>

                            <!-- Hidden Reject Form -->
                            <div id="rejectSection" class="d-none mt-3 p-3 bg-light rounded border border-danger">
                                <form method="POST">
                                    <input type="hidden" name="action" value="reject_result">
                                    <input type="hidden" name="category_id" id="rejectCatId">
                                    <label class="form-label text-danger fw-bold small">Reason for Rejection:</label>
                                    <textarea name="rejection_reason" class="form-control mb-2" rows="2" placeholder="e.g. Image shows Team B won Gold..." required></textarea>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-danger btn-sm flex-grow-1">Confirm Reject</button>
                                        <button type="button" class="btn btn-light btn-sm border" id="btnCancelReject">Cancel</button>
                                    </div>
                                </form>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- REVOKE MODAL -->
    <div class="modal fade" id="revokeModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-warning">
                <form method="POST">
                    <input type="hidden" name="action" value="revoke_result">
                    <input type="hidden" name="category_id" id="revoke_category_id">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title"><i class="fas fa-undo me-2"></i>Revoke Approval</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-3">Are you sure you want to unpublish the results for <strong id="revoke_category_name"></strong>?</p>
                        <textarea class="form-control" name="revoke_reason" rows="3" placeholder="Reason for revoking..." required></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Revoke & Return to Pending</button>
                    </div>
                </form>
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
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            
            // --- REVIEW / VERIFICATION MODAL LOGIC ---
            const verifyModal = document.getElementById('verificationModal');
            verifyModal.addEventListener('show.bs.modal', function(event) {
                const btn = event.relatedTarget;
                const evidenceUrl = btn.getAttribute('data-evidence');
                const catId = btn.getAttribute('data-id');

                // Populate Text
                document.getElementById('modalEventTitle').textContent = btn.getAttribute('data-event');
                document.getElementById('modalGoldName').textContent = btn.getAttribute('data-gold-name');
                document.getElementById('modalGoldCount').textContent = btn.getAttribute('data-gold-count');
                document.getElementById('modalSilverName').textContent = btn.getAttribute('data-silver-name');
                document.getElementById('modalSilverCount').textContent = btn.getAttribute('data-silver-count');
                document.getElementById('modalBronzeName').textContent = btn.getAttribute('data-bronze-name');
                document.getElementById('modalBronzeCount').textContent = btn.getAttribute('data-bronze-count');

                // Set Form IDs
                document.getElementById('approveCatId').value = catId;
                document.getElementById('rejectCatId').value = catId;

                // Handle Image
                const imgEl = document.getElementById('modalEvidenceImg');
                const noImgEl = document.getElementById('noEvidenceMsg');
                
                // --- FIX: IN-APP ZOOM LOGIC (Reset) ---
                const evidenceContainer = document.querySelector('.evidence-col');
                imgEl.classList.remove('zoomed');
                evidenceContainer.scrollTop = 0;
                evidenceContainer.scrollLeft = 0;
                evidenceContainer.style.cursor = 'zoom-in';
                
                if (evidenceUrl && evidenceUrl !== '') {
                    // Try to load image
                    imgEl.src = evidenceUrl; // Assuming relative path is correct from DB
                    imgEl.classList.remove('d-none');
                    noImgEl.classList.add('d-none');
                    
                    // --- FIX: ZOOM TOGGLE ---
                    imgEl.onclick = function() {
                        this.classList.toggle('zoomed');
                        // Toggle cursor on container
                        if (this.classList.contains('zoomed')) {
                            evidenceContainer.style.cursor = 'zoom-out';
                            this.title = "Click to zoom out";
                        } else {
                            evidenceContainer.style.cursor = 'zoom-in';
                            this.title = "Click to zoom in";
                        }
                    };
                    imgEl.title = "Click to zoom in";
                    
                } else {
                    imgEl.classList.add('d-none');
                    noImgEl.classList.remove('d-none');
                }

                // Reset Reject Section
                document.getElementById('rejectSection').classList.add('d-none');
            });

            // Toggle Reject Form
            document.getElementById('btnShowReject').addEventListener('click', function() {
                document.getElementById('rejectSection').classList.remove('d-none');
                this.scrollIntoView({ behavior: 'smooth' });
            });
            document.getElementById('btnCancelReject').addEventListener('click', function() {
                document.getElementById('rejectSection').classList.add('d-none');
            });

            // --- REVOKE MODAL ---
            const revokeModal = document.getElementById('revokeModal');
            revokeModal.addEventListener('show.bs.modal', function(event) {
                const btn = event.relatedTarget;
                document.getElementById('revoke_category_id').value = btn.getAttribute('data-id');
                document.getElementById('revoke_category_name').textContent = btn.getAttribute('data-name');
            });

            // --- SIDEBAR TOGGLE ---
            const mobileToggle = document.getElementById('mobileToggle');
            if (mobileToggle) {
                mobileToggle.addEventListener('click', function() {
                    document.getElementById('sidebar').classList.toggle('show');
                });
            }
            
            // --- DYNAMIC SIDEBAR HEIGHT ---
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

        // --- REAL-TIME UPDATES (AJAX POLLING) ---
            function fetchResultsUpdates() {
                fetch('results.php?ajax_update=1')
                    .then(response => response.json())
                    .then(data => {
                        // 1. Update Tab Badges (Inner Page)
                        const pendingTabBadge = document.querySelector('#resultsTab button[data-bs-target="#pending"] .badge');
                        const approvedTabBadge = document.querySelector('#resultsTab button[data-bs-target="#approved"] .badge');
                        
                        if(pendingTabBadge) pendingTabBadge.textContent = data.pending_count;
                        if(approvedTabBadge) approvedTabBadge.textContent = data.approved_count;

                        // 2. Update Sidebar Badge (Left Menu) - "Approve Results"
                        const sidebarLink = document.querySelector('.sidebar-nav .nav-link[href="results.php"]');
                        if (sidebarLink) {
                            let sidebarBadge = sidebarLink.querySelector('.badge');
                            
                            if (data.pending_count > 0) {
                                // If count > 0, ensure badge exists and has correct number
                                if (sidebarBadge) {
                                    sidebarBadge.textContent = data.pending_count;
                                } else {
                                    // Badge doesn't exist yet? Create it!
                                    sidebarBadge = document.createElement('span');
                                    sidebarBadge.className = 'badge bg-warning text-dark ms-auto rounded-pill';
                                    sidebarBadge.textContent = data.pending_count;
                                    sidebarLink.appendChild(sidebarBadge);
                                }
                            } else {
                                // If count is 0, remove the badge if it exists
                                if (sidebarBadge) sidebarBadge.remove();
                            }
                        }

                        // 3. Update Table Content (Only if not hovering)
                        if (!document.querySelector('.results-table:hover')) {
                            const pendingTbody = document.querySelector('#pending tbody');
                            const approvedTbody = document.querySelector('#approved tbody');
                            
                            if (pendingTbody && pendingTbody.innerHTML !== data.pending_html) {
                                pendingTbody.innerHTML = data.pending_html;
                            }
                            if (approvedTbody && approvedTbody.innerHTML !== data.approved_html) {
                                approvedTbody.innerHTML = data.approved_html;
                            }
                        }
                    })
                    .catch(err => console.error('Error fetching updates:', err));
            }

            // Start polling every 5 seconds
            setInterval(fetchResultsUpdates, 5000);
    </script>
</body>
</html>