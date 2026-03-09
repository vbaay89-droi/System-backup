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
                $alert_message = "SUCCESS: Results rejected. The Tournament Manager has been notified.";
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

// 1. Fetch Name AND Code
$colleges_result = $conn->query("SELECT college_id, college_name, college_code FROM colleges");

$college_map = [];
$college_code_map = []; // New Map for Codes

while ($c = $colleges_result->fetch_assoc()) { 
    $college_map[$c['college_id']] = $c['college_name']; 
    $college_code_map[$c['college_id']] = $c['college_code']; // Store COTE, CAS, etc.
}

// Helper to safely get the code (e.g., COTE)
function getCollegeCode($id, $map) {
    return (!empty($id) && isset($map[$id])) ? htmlspecialchars($map[$id]) : 'N/A';
}

// Helper to safely get the full name (e.g., College of Technology...)
function getCollegeName($id, $map) {
    return (!empty($id) && isset($map[$id])) ? htmlspecialchars($map[$id]) : 'N/A';
}

// FETCH PENDING RESULTS
// Updated SQL to prioritize Full Name, fallback to Username
$sql_pending = "SELECT 
            c.category_id, c.category_name, c.division_name, c.tally_sheet_url,
            c.gold_winner_college_id, c.gold_count,
            c.silver_winner_college_id, c.silver_count,
            c.bronze_winner_college_id, c.bronze_count,
            c.updated_at AS submission_date, 
            ge.event_name, g.game_name, 
            COALESCE(u.full_name, u.username) AS submitted_by_name
        FROM categories c
        JOIN game_events ge ON c.event_id = ge.event_id
        JOIN games g ON ge.game_id = g.game_id
        LEFT JOIN tournament_manager_assignments ema ON c.event_id = ema.event_id
        LEFT JOIN users u ON ema.user_id = u.id
        WHERE c.status = 'Results Submitted'
        GROUP BY c.category_id
        ORDER BY c.updated_at DESC";
$result_pending = $conn->query($sql_pending);
$pending_results = ($result_pending) ? $result_pending->fetch_all(MYSQLI_ASSOC) : [];

// FETCH APPROVED RESULTS
// Updated SQL to prioritize Full Name for Approver
$sql_approved = "SELECT 
            c.category_id, c.category_name, c.division_name, c.tally_sheet_url,
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
            c.category_id, c.category_name, c.division_name, c.tally_sheet_url,
            c.gold_winner_college_id, c.gold_count,
            c.silver_winner_college_id, c.silver_count,
            c.bronze_winner_college_id, c.bronze_count,
            c.updated_at AS submission_date, 
            ge.event_name, g.game_name, 
            COALESCE(u.full_name, u.username) AS submitted_by_name
        FROM categories c
        JOIN game_events ge ON c.event_id = ge.event_id
        JOIN games g ON ge.game_id = g.game_id
        LEFT JOIN tournament_manager_assignments ema ON c.event_id = ema.event_id
        LEFT JOIN users u ON ema.user_id = u.id
        WHERE c.status = 'Results Submitted'
        GROUP BY c.category_id
        ORDER BY c.updated_at DESC";
    $pending_results = $conn->query($sql_pending)->fetch_all(MYSQLI_ASSOC);

    // 2. Fetch Approved Results
    $sql_approved = "SELECT 
            c.category_id, c.category_name, c.division_name, c.tally_sheet_url,
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
    // 3. Generate HTML for Pending (RICH CARDS)
    ob_start();
    if (empty($pending_results)) {
        echo '<div class="text-center py-5 text-muted"><i class="fas fa-inbox fa-3x mb-3 opacity-25"></i><br>All caught up! No pending results.</div>';
    } else {
        foreach ($pending_results as $row) {
            // 1. Full Names (For the Modal/Data Attributes)
$gold = getCollegeName($row['gold_winner_college_id'], $college_map);
$silver = getCollegeName($row['silver_winner_college_id'], $college_map);
$bronze = getCollegeName($row['bronze_winner_college_id'], $college_map);

// 2. Initialisms/Codes (For the Result Preview Card)
$gold_code = getCollegeCode($row['gold_winner_college_id'], $college_code_map);
$silver_code = getCollegeCode($row['silver_winner_college_id'], $college_code_map);
$bronze_code = getCollegeCode($row['bronze_winner_college_id'], $college_code_map);
            $evidence = !empty($row['tally_sheet_url']) ? '../' . htmlspecialchars($row['tally_sheet_url']) : '';
            
            // --- SMART ICON LOGIC ---
$icon = 'fas fa-trophy'; // Default fallback

// 1. ATHLETICS (Running, Dash, Relay)
if (stripos($row['game_name'], 'Athletics') !== false || stripos($row['game_name'], 'Run') !== false) {
    $icon = 'fas fa-running';
} 
// 2. BALL GAMES (Basketball, Volleyball, Sepak Takraw)
elseif (stripos($row['game_name'], 'Ball') !== false || stripos($row['event_name'], 'Ball') !== false || stripos($row['event_name'], 'Takraw') !== false) {
    $icon = 'fas fa-basketball-ball';
} 
// 3. WATER SPORTS (Swimming)
elseif (stripos($row['game_name'], 'Swim') !== false) {
    $icon = 'fas fa-swimmer';
} 
// 4. RACKET GAMES (Badminton, Table Tennis, Lawn Tennis)
elseif (stripos($row['game_name'], 'Racket') !== false || stripos($row['event_name'], 'Badminton') !== false || stripos($row['event_name'], 'Tennis') !== false) {
    $icon = 'fas fa-table-tennis-paddle-ball';
} 
// 5. MIND & BOARD GAMES (Chess, Scrabble, Word Factory) -> PUZZLE PIECE
elseif (stripos($row['event_name'], 'Chess') !== false || stripos($row['event_name'], 'Scrabble') !== false || stripos($row['event_name'], 'Word') !== false || stripos($row['event_name'], 'Board') !== false) {
    $icon = 'fas fa-puzzle-piece'; 
} 
// 6. E-SPORTS (Mobile Legends, Valorant, etc.) -> GAMEPAD
elseif (stripos($row['event_name'], 'E-sports') !== false || stripos($row['event_name'], 'Mobile') !== false || stripos($row['event_name'], 'Game') !== false) {
    $icon = 'fas fa-gamepad';
}
// 7. CULTURAL / ARTS (Dance, Vocal, Pageants) -> MUSIC/STAR
elseif (stripos($row['event_name'], 'Dance') !== false || stripos($row['event_name'], 'Vocal') !== false || stripos($row['event_name'], 'Sing') !== false) {
    $icon = 'fas fa-music';
}
// 8. CATCH-ALL FOR "OTHER GAMES" (If it doesn't match above)
elseif (stripos($row['game_name'], 'Other') !== false) {
    $icon = 'fas fa-medal'; // A generic medal icon for miscellaneous events
}

            echo '
            <div class="card mb-3 shadow-sm border-0" style="border-left: 5px solid #198754 !important; border-radius: 10px;">
    <div class="card-body d-flex align-items-center justify-content-between p-4 flex-wrap gap-3">
        
        <div class="d-flex align-items-center" style="flex: 1; min-width: 250px;">
            <div class="bg-success bg-opacity-10 rounded-circle p-3 me-3 d-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                <i class="'.$icon.' fa-lg text-success"></i>
            </div>
                        <div>
                            <h6 class="mb-1 text-uppercase text-muted small fw-bold" style="letter-spacing: 1px;">'.htmlspecialchars($row['game_name']).'</h6>
                            <h5 class="mb-1 fw-bold text-dark">'.htmlspecialchars($row['event_name']).'</h5>
                            <div class="d-flex align-items-center mt-1">
                                <small class="text-secondary fw-bold">'.htmlspecialchars($row['category_name']).'</small>
                                ' . (!empty($row['division_name']) ? '<small class="text-muted ms-1 fw-semibold"> - '.htmlspecialchars($row['division_name']).'</small>' : '') . '
                            </div>
                    </div>

                    <div class="d-flex flex-column justify-content-center border-start border-end px-4 d-none d-xl-flex" style="flex: 1;">
                        <small class="text-muted text-uppercase fw-bold mb-2" style="font-size: 0.7rem;">Result Preview</small>
                        <div class="d-flex gap-3">
    <div class="d-flex align-items-center" title="Gold">
        <i class="fas fa-medal text-warning me-2"></i> <span class="fw-bold text-dark">'.$gold_code.'</span>
    </div>
    <div class="d-flex align-items-center" title="Silver">
        <i class="fas fa-medal text-secondary me-2"></i> <span class="fw-bold text-muted">'.$silver_code.'</span>
    </div>
    <div class="d-flex align-items-center" title="Bronze">
        <i class="fas fa-medal me-2" style="color: #cd7f32;"></i> <span class="fw-bold text-muted">'.$bronze_code.'</span>
    </div>
</div>
                    </div>

                    <div class="d-flex align-items-center justify-content-end gap-3" style="flex: 1; min-width: 280px;">
                        <div class="text-end me-4" style="min-width: 150px;">
    <div class="text-uppercase text-muted fw-bold mb-1" style="font-size: 0.65rem; letter-spacing: 1px;">Submitted By</div>
    <div class="fw-bold text-dark text-truncate mb-1" 
     style="font-size: 1rem; max-width: 150px; margin-left: auto;" 
     title="'.htmlspecialchars($row['submitted_by_name'] ?? 'Unknown').'">
    '.htmlspecialchars($row['submitted_by_name'] ?? 'Unknown').'
</div>
    <div class="text-secondary small" style="font-size: 0.75rem;">
        <i class="far fa-clock me-1"></i> '.date('M d, h:i A', strtotime($row['submission_date'])).'
    </div>
</div>
                        
                        <button type="button" class="btn btn-primary px-4 py-2 rounded-pill shadow-sm fw-bold review-btn"
    data-bs-toggle="modal" data-bs-target="#verificationModal"
    data-id="' . $row['category_id'] . '"
    data-event="' . htmlspecialchars($row['event_name'] . ' - ' . $row['category_name'] . (!empty($row['division_name']) ? ' (' . $row['division_name'] . ')' : '')) . '"
    data-submitted-by="' . htmlspecialchars($row['submitted_by_name'] ?? 'Unknown') . '"
    data-gold-name="' . $gold . '" data-gold-count="' . $row['gold_count'] . '"
    data-silver-name="' . $silver . '" data-silver-count="' . $row['silver_count'] . '"
    data-bronze-name="' . $bronze . '" data-bronze-count="' . $row['bronze_count'] . '"
    data-evidence="' . $evidence . '">
    Review <i class="fas fa-arrow-right ms-2"></i>
</button>
                    </div>

                </div>
            </div>';
        }
    }
    $pending_html = ob_get_clean();

    // 4. Generate HTML for Approved Table (Modern Grid)
    ob_start();
    if (empty($approved_results)) {
        echo '<tr><td colspan="4" class="text-center py-5 text-muted"><i class="fas fa-history fa-2x mb-3 opacity-25"></i><br>No approved results yet.</td></tr>';
    } else {
        foreach ($approved_results as $row) {
    // 1. Re-use the Icon Logic (Copy this from the Pending section)
    $icon = 'fas fa-trophy'; // Default
    if (stripos($row['game_name'], 'Athletics') !== false || stripos($row['game_name'], 'Run') !== false) { $icon = 'fas fa-running'; } 
    elseif (stripos($row['game_name'], 'Ball') !== false) { $icon = 'fas fa-basketball-ball'; } 
    elseif (stripos($row['game_name'], 'Swim') !== false) { $icon = 'fas fa-swimmer'; } 
    elseif (stripos($row['game_name'], 'Racket') !== false || stripos($row['event_name'], 'Badminton') !== false) { $icon = 'fas fa-table-tennis-paddle-ball'; } 
    elseif (stripos($row['event_name'], 'Chess') !== false) { $icon = 'fas fa-puzzle-piece'; } 
    elseif (stripos($row['event_name'], 'E-sports') !== false || stripos($row['event_name'], 'Mobile') !== false) { $icon = 'fas fa-gamepad'; }
    elseif (stripos($row['event_name'], 'Dance') !== false || stripos($row['event_name'], 'Vocal') !== false) { $icon = 'fas fa-music'; }

    $proof_btn = !empty($row['tally_sheet_url']) 
        ? '<a href="' . htmlspecialchars('../' . $row['tally_sheet_url']) . '" target="_blank" class="btn btn-sm btn-outline-secondary border-0 bg-light"><i class="fas fa-file-image me-1"></i> View Proof</a>'
        : '<span class="badge bg-light text-muted fw-normal">No Proof</span>';

    echo '<tr class="align-middle approved-row" style="background: white; border-bottom: 1px solid #f1f3f5;">
        <td class="ps-0 py-3" style="border-left: 5px solid #198754;">
            <div class="d-flex align-items-center ps-3">
                <div class="me-3 d-flex align-items-center justify-content-center text-success bg-success bg-opacity-10 rounded-circle" style="width: 40px; height: 40px;">
                    <i class="'.$icon.'"></i>
                </div>
                <div class="d-flex flex-column">
                    <small class="text-uppercase text-muted fw-bold" style="font-size:0.7rem;">' . htmlspecialchars($row['game_name']) . '</small>
                    <span class="fw-bold text-dark text-capitalize">' . htmlspecialchars($row['event_name']) . '</span>
                    <div class="mt-1 d-flex align-items-center">
                        <small class="text-secondary fw-bold">' . htmlspecialchars($row['category_name']) . '</small>
                        ' . (!empty($row['division_name']) ? '<small class="text-secondary ms-1 fw-semibold"> - ' . htmlspecialchars($row['division_name']) . '</small>' : '') . '
                    </div>
                </div>
            </div>
        </td>
        
        <td>
            <div class="d-flex flex-column">
                <span class="fw-bold text-dark small"><i class="fas fa-user-check text-success me-1"></i> ' . htmlspecialchars($row['approved_by_name'] ?? 'System') . '</span>
                <small class="text-muted ps-3">' . date('M d, h:i A', strtotime($row['approved_at'])) . '</small>
            </div>
        </td>

        <td class="text-center">' . $proof_btn . '</td>

        <td class="text-end pe-3">
            <button type="button" class="btn btn-light btn-sm text-danger hover-shadow" 
                    data-bs-toggle="modal" data-bs-target="#revokeModal"
                    data-id="' . $row['category_id'] . '" 
                    data-name="' . htmlspecialchars($row['event_name']) . '"
                    title="Revoke Approval">
                <i class="fas fa-undo-alt"></i>
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
    <title>Verify Results - Sports Director Panel</title>
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
            /* REMOVED background color here so .footer-main can work */
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            padding-left: var(--sidebar-width);
            transition: padding-left var(--transition);
            position: relative;
            z-index: 1041;
        }   z-index: 1041;
        
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

    /* =========================================
   MOBILE OPTIMIZATION (Sports Director)
   ========================================= */
@media (max-width: 991.98px) {
    
    /* 1. COMPACT NAVBAR & LAYOUT */
    .navbar {
        padding: 0.5rem 1rem !important;
        height: 60px !important; /* Fixed compact height */
        display: flex !important;
        flex-wrap: nowrap !important;
        align-items: center !important;
    }
    
    /* A. LEFT: Toggler Button */
    .navbar-toggler {
        order: 1 !important; /* First item */
        border: 1px solid rgba(255,255,255,0.1);
        padding: 4px 8px;
        font-size: 1.2rem;
        margin-right: 10px !important;
    }
    .navbar-toggler:focus { box-shadow: none; }

    /* B. LEFT/CENTER: Brand Logo */
    /* margin-right: auto PUSHES the Profile Icon to the far right */
    .navbar-brand {
        order: 2 !important; /* Second item */
        margin-right: auto !important; /* THE KEY SPACER */
        display: flex;
        align-items: center;
        max-width: 60%;
    }
    .navbar-brand img {
        height: 30px !important;
        width: 30px !important;
        margin-right: 8px !important;
    }
    .navbar-brand strong {
        font-size: 0.95rem !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .navbar-brand small { display: none !important; }

    /* C. RIGHT: Profile Menu Icon */
    .user-dropdown {
        order: 3 !important; /* Third item */
        margin-left: 0 !important; 
        position: relative;
    }
    .user-dropdown .user-name { display: none !important; } /* Hide Name */
    
    /* Icon Styling */
    .user-dropdown .dropdown-toggle i { 
        font-size: 26px !important; 
        margin: 0 !important;
        color: #fff; /* Ensure visibility */
        cursor: pointer;
    }

    /* Order 3: Brand Logo */
    .navbar-brand {
        order: 3 !important;
        display: flex;
        align-items: center;
        max-width: 55%; /* Adjust width to prevent overflow */
        margin-right: 0 !important;
    }
    .navbar-brand img {
        height: 30px !important;
        width: 30px !important;
        margin-right: 8px !important;
    }
    .navbar-brand strong {
        font-size: 0.95rem !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .navbar-brand small { display: none !important; }
    
    /* Compact Profile Menu */
    .user-dropdown .user-name { display: none !important; }
    .user-dropdown .dropdown-toggle i { font-size: 28px !important; margin: 0 !important; }

    /* 2. SIDEBAR DRAWER (Fix Gap & Animation) */
    .sidebar {
        position: fixed !important;
        top: 60px !important; /* Matches Navbar Height */
        left: -260px !important; /* Hidden */
        width: 260px !important;
        height: calc(100vh - 60px) !important;
        background-color: #2c3e50 !important;
        box-shadow: 5px 0 15px rgba(0,0,0,0.3);
        transition: left 0.3s ease-in-out !important;
        z-index: 1045;
        overflow-y: auto;
    }
    .sidebar.show { left: 0 !important; } /* Slide In */
    
    /* Prevent text cutoff in menu */
    .sidebar-nav .nav-link { 
        white-space: nowrap; 
        font-size: 0.95rem;
    }

    /* 3. MAIN CONTENT ADJUSTMENTS */
    .main-content {
        padding: 15px !important;
        margin-top: 60px !important;
        margin-left: 0 !important;
    }
}

/* =========================================
   MOBILE VERIFICATION & RESULTS OPTIMIZATION
   ========================================= */
@media (max-width: 991.98px) {

    /* --- 1. GENERAL LAYOUT --- */
    .container-fluid {
        padding-left: 15px;
        padding-right: 15px;
    }
    
    /* Header Stacking */
    .d-flex.justify-content-between.align-items-center.mb-4 {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }

    /* --- 2. PENDING REQUEST CARDS (The Complex Part) --- */
    
    /* Target the Card Body inside the pending loop */
    #pending .card-body {
        flex-direction: column !important; /* Stack everything vertically */
        align-items: center !important;
        text-align: center;
        padding: 1.5rem !important;
    }

    /* Icon & Title Section */
    #pending .card-body > div:first-child {
        flex-direction: column; /* Stack Icon above Text */
        width: 100%;
        margin-bottom: 15px;
    }

    /* Icon Circle */
    #pending .card-body .bg-success.rounded-circle {
        margin-right: 0 !important; /* Remove side margin */
        margin-bottom: 10px; /* Add bottom margin */
        width: 70px !important;
        height: 70px !important;
    }

    /* Text Alignment */
    #pending .card-body h6, 
    #pending .card-body h5, 
    #pending .card-body small {
        text-align: center;
        width: 100%;
    }

    /* Submitted By & Button Section */
    #pending .card-body > div:last-child {
        width: 100%;
        flex-direction: column; /* Stack "Submitted By" above "Button" */
        gap: 15px !important;
    }

    /* "Submitted By" Text */
    #pending .card-body .text-end {
        text-align: center !important; /* Center align instead of right */
        margin-right: 0 !important;
    }
    #pending .card-body .text-end .fw-bold {
        margin-left: 0 !important; /* Reset margin */
        max-width: 100% !important; /* Allow full width */
    }

    /* Review Button */
    #pending .review-btn {
        width: 100%; /* Full width button for easy tapping */
        padding: 12px !important;
    }

    /* --- 3. APPROVED HISTORY TABLE (Sticky Scroll) --- */
    
    /* Allow scrolling */
    .table-responsive {
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch;
        border: 1px solid #f0f0f0;
        border-radius: 8px;
    }

    /* Force width to prevent squishing */
    .table { min-width: 750px; }

    /* Sticky First Column (Event Details) */
    .approved-row td:first-child {
        position: sticky;
        left: 0;
        background: #fff;
        z-index: 5;
        border-right: 2px solid #f0f0f0;
        min-width: 180px;
        max-width: 200px;
    }
    
    /* Hide "Approved By" column on small screens to save space */
    .approved-row td:nth-child(2),
    .table thead th:nth-child(2) {
        display: none;
    }

    /* --- 4. VERIFICATION MODAL (The Split View) --- */
    
    /* Remove the grid layout, stack them */
    .modal-body .row.g-0 {
        display: flex;
        flex-direction: column;
    }

    /* Evidence Image Container (Top) */
    .evidence-col {
        width: 100%;
        height: 350px; /* Reduced height for mobile */
        border-radius: 8px 8px 0 0; /* Rounded top only */
        border-bottom: 4px solid #1e293b;
    }

    /* Data Form Container (Bottom) */
    .data-col {
        width: 100%;
        padding: 20px;
        max-height: 50vh; /* Allow scrolling if form is long */
        overflow-y: auto;
    }

    /* Adjust Modal Title */
    .modal-header h5 {
        font-size: 1rem;
    }
    
    /* Fix Image Zoom behavior on mobile */
    .evidence-img.zoomed {
        width: 250%; /* Larger zoom for small screens */
    }
    
    /* Winner Cards in Modal */
    .winner-card {
        padding: 0.75rem;
    }
    .winner-icon {
        font-size: 1.2rem;
        margin-right: 0.5rem;
    }
    
    /* Modal Actions */
    #approveForm button, 
    #btnShowReject {
        padding: 12px;
        font-size: 1rem;
    }

    /* --- 5. FORCE TABS HORIZONTAL (50/50 Split) --- */
    #resultsTab {
        display: flex !important;
        flex-wrap: nowrap !important; /* Prevent stacking */
        width: 100% !important;
    }
    
    #resultsTab .nav-item {
        flex: 1 !important;        /* Grow to fill space equally */
        width: 50% !important;     /* Force exactly half width */
        text-align: center;
    }
    
    #resultsTab .nav-link {
        width: 100% !important;    /* Fill the item */
        display: flex !important;
        justify-content: center !important;
        align-items: center !important;
        padding: 12px 2px !important; /* Reduce padding to fit text */
        font-size: 0.85rem !important; /* Slightly smaller text */
        white-space: nowrap !important; /* Keep text on one line */
    }

    /* Adjust the inner badges (numbers) to fit better */
    #resultsTab .nav-link .badge {
        margin-left: 6px !important;
        font-size: 0.7rem !important;
        padding: 4px 6px !important;
    }
    
    /* Optional: Hide icons on very small screens to save space */
    @media (max-width: 360px) {
        #resultsTab .nav-link i { display: none !important; }
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
            <a class="navbar-brand d-flex align-items-center" href="sports_director_dashboard.php">
                <img src="../images/PIT.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light" style="font-size: 0.75rem;">Sports Director Panel</small>
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
                        <div id="pending-container" class="p-4" style="background: #f8f9fa;">
                            <?php if (empty($pending_results)): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-inbox fa-3x mb-3 opacity-25"></i><br>All caught up! No pending results.
                                </div>
                            <?php else: ?>
            <?php foreach ($pending_results as $row): 
                // Copy the EXACT Logic from Part 1 here for the initial load
                // 1. Full Names
                $gold = getCollegeName($row['gold_winner_college_id'], $college_map);
                $silver = getCollegeName($row['silver_winner_college_id'], $college_map);
                $bronze = getCollegeName($row['bronze_winner_college_id'], $college_map);

                // 2. Codes for Preview
                $gold_code = getCollegeCode($row['gold_winner_college_id'], $college_code_map);
                $silver_code = getCollegeCode($row['silver_winner_college_id'], $college_code_map);
                $bronze_code = getCollegeCode($row['bronze_winner_college_id'], $college_code_map);
                $evidence = !empty($row['tally_sheet_url']) ? '../' . htmlspecialchars($row['tally_sheet_url']) : '';
                
                // --- SMART ICON LOGIC ---
                    $icon = 'fas fa-trophy'; // Default fallback

                    // 1. ATHLETICS (Running, Dash, Relay)
                    if (stripos($row['game_name'], 'Athletics') !== false || stripos($row['game_name'], 'Run') !== false) {
                        $icon = 'fas fa-running';
                    } 
                    // 2. BALL GAMES (Basketball, Volleyball, Sepak Takraw)
                    elseif (stripos($row['game_name'], 'Ball') !== false || stripos($row['event_name'], 'Ball') !== false || stripos($row['event_name'], 'Takraw') !== false) {
                        $icon = 'fas fa-basketball-ball';
                    } 
                    // 3. WATER SPORTS (Swimming)
                    elseif (stripos($row['game_name'], 'Swim') !== false) {
                        $icon = 'fas fa-swimmer';
                    } 
                    // 4. RACKET GAMES (Badminton, Table Tennis, Lawn Tennis)
                    elseif (stripos($row['game_name'], 'Racket') !== false || stripos($row['event_name'], 'Badminton') !== false || stripos($row['event_name'], 'Tennis') !== false) {
                        $icon = 'fas fa-table-tennis-paddle-ball';
                    } 
                    // 5. MIND & BOARD GAMES (Chess, Scrabble, Word Factory) -> PUZZLE PIECE
                    elseif (stripos($row['event_name'], 'Chess') !== false || stripos($row['event_name'], 'Scrabble') !== false || stripos($row['event_name'], 'Word') !== false || stripos($row['event_name'], 'Board') !== false) {
                        $icon = 'fas fa-puzzle-piece'; 
                    } 
                    // 6. E-SPORTS (Mobile Legends, Valorant, etc.) -> GAMEPAD
                    elseif (stripos($row['event_name'], 'E-sports') !== false || stripos($row['event_name'], 'Mobile') !== false || stripos($row['event_name'], 'Game') !== false) {
                        $icon = 'fas fa-gamepad';
                    }
                    // 7. CULTURAL / ARTS (Dance, Vocal, Pageants) -> MUSIC/STAR
                    elseif (stripos($row['event_name'], 'Dance') !== false || stripos($row['event_name'], 'Vocal') !== false || stripos($row['event_name'], 'Sing') !== false) {
                        $icon = 'fas fa-music';
                    }
                    // 8. CATCH-ALL FOR "OTHER GAMES" (If it doesn't match above)
                    elseif (stripos($row['game_name'], 'Other') !== false) {
                        $icon = 'fas fa-medal'; // A generic medal icon for miscellaneous events
                    }
            ?>
            <div class="card mb-3 shadow-sm border-0" style="border-left: 5px solid #198754 !important; border-radius: 10px;">
                <div class="card-body d-flex align-items-center justify-content-between p-4 flex-wrap gap-3">
                    
                    <div class="d-flex align-items-center" style="flex: 1; min-width: 250px;">
                        <div class="bg-success bg-opacity-10 rounded-circle p-3 me-3 d-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                            <i class="<?= $icon ?> fa-lg text-success"></i>
                        </div>
                        <div>
                            <h6 class="mb-1 text-uppercase text-muted small fw-bold" style="letter-spacing: 1px;"><?= htmlspecialchars($row['game_name']) ?></h6>
                            <h5 class="mb-1 fw-bold text-dark"><?= htmlspecialchars($row['event_name']) ?></h5>
                            <div class="d-flex align-items-center mt-1">
                                <small class="text-secondary fw-bold"><?= htmlspecialchars($row['category_name']) ?></small>
                                <?= (!empty($row['division_name']) ? '<small class="text-muted ms-1 fw-semibold"> - ' . htmlspecialchars($row['division_name']) . '</small>' : '') ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-column justify-content-center border-start border-end px-4 d-none d-xl-flex" style="flex: 1;">
                        <small class="text-muted text-uppercase fw-bold mb-2" style="font-size: 0.7rem;">Result Preview</small>
                        <div class="d-flex gap-3">
                            <div class="d-flex align-items-center" title="Gold">
                                <i class="fas fa-medal text-warning me-2"></i> <span class="fw-bold text-dark"><?= $gold_code ?></span>
                            </div>
                            <div class="d-flex align-items-center" title="Silver">
                                <i class="fas fa-medal text-secondary me-2"></i> <span class="fw-bold text-muted"><?= $silver_code ?></span>
                            </div>
                            <div class="d-flex align-items-center" title="Bronze">
                                <i class="fas fa-medal me-2" style="color: #cd7f32;"></i> <span class="fw-bold text-muted"><?= $bronze_code ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex align-items-center justify-content-end gap-3" style="flex: 1; min-width: 280px;">
                        <div class="text-end me-4" style="min-width: 150px;">
                            <div class="text-uppercase text-muted fw-bold mb-1" style="font-size: 0.65rem; letter-spacing: 1px;">Submitted By</div>
                        <div class="fw-bold text-dark text-truncate mb-1" 
                            style="font-size: 1rem; max-width: 150px; margin-left: auto;" 
                            title="<?= htmlspecialchars($row['submitted_by_name'] ?? 'Unknown') ?>">
                            <?= htmlspecialchars($row['submitted_by_name'] ?? 'Unknown') ?>
                        </div>
                            <div class="text-secondary small" style="font-size: 0.75rem;">
                                <i class="far fa-clock me-1"></i> <?= date('M d, h:i A', strtotime($row['submission_date'])) ?>
                            </div>
                        </div>
                                                
                                                <button type="button" class="btn btn-primary px-4 py-2 rounded-pill shadow-sm fw-bold review-btn"
                            data-bs-toggle="modal" data-bs-target="#verificationModal"
                            data-id="<?= $row['category_id'] ?>"
                            data-event="<?= htmlspecialchars($row['event_name'] . ' - ' . $row['category_name']) ?>"
                            data-submitted-by="<?= htmlspecialchars($row['submitted_by_name'] ?? 'Unknown') ?>"
                            data-gold-name="<?= $gold ?>" data-gold-count="<?= $row['gold_count'] ?>"
                            data-silver-name="<?= $silver ?>" data-silver-count="<?= $row['silver_count'] ?>"
                            data-bronze-name="<?= $bronze ?>" data-bronze-count="<?= $row['bronze_count'] ?>"
                            data-evidence="<?= $evidence ?>">
                            Review <i class="fas fa-arrow-right ms-2"></i>
                        </button>
                                            </div>

                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>


                    <!-- APPROVED TAB -->
                    <div class="tab-pane fade" id="approved">
                        <div class="table-responsive">
                            <div class="d-flex justify-content-between align-items-center p-3 border-bottom bg-white">
                        <div class="text-muted small fw-bold text-uppercase">
                            <i class="fas fa-list me-2"></i>Approved Records
                        </div>
                        
                        <div class="input-group" style="width: 250px;">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-search text-secondary small"></i>
                            </span>
                            <input type="text" id="approvedSearch" class="form-control bg-light border-start-0 small" 
                                placeholder="Search event, team..." 
                                style="font-size: 0.9rem;"
                                onkeyup="filterHistory()">
                        </div>
                    </div>
                        <table class="table table-hover mb-0 align-middle" style="border-collapse: separate; border-spacing: 0 8px;">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-3 text-uppercase text-muted small fw-bold border-0">Event Details</th>
                                    <th class="text-uppercase text-muted small fw-bold border-0">Approved By</th>
                                    <th class="text-center text-uppercase text-muted small fw-bold border-0">Evidence</th>
                                    <th class="text-end pe-3 text-uppercase text-muted small fw-bold border-0">Action</th>
                                </tr>
                            </thead>
            <tbody class="border-top-0">
                <?php if (empty($approved_results)): ?>
                    <tr><td colspan="4" class="text-center py-5 text-muted"><i class="fas fa-history fa-2x mb-3 opacity-25"></i><br>No approved results yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($approved_results as $row): 
                        // 1. Icon Logic: Determine which icon to show based on the Game/Event Name
                        $icon = 'fas fa-trophy'; // Default fallback
                        if (stripos($row['game_name'], 'Athletics') !== false || stripos($row['game_name'], 'Run') !== false) { $icon = 'fas fa-running'; } 
                        elseif (stripos($row['game_name'], 'Ball') !== false) { $icon = 'fas fa-basketball-ball'; } 
                        elseif (stripos($row['game_name'], 'Swim') !== false) { $icon = 'fas fa-swimmer'; } 
                        elseif (stripos($row['game_name'], 'Racket') !== false || stripos($row['event_name'], 'Badminton') !== false) { $icon = 'fas fa-table-tennis-paddle-ball'; } 
                        elseif (stripos($row['event_name'], 'Chess') !== false) { $icon = 'fas fa-puzzle-piece'; } 
                        elseif (stripos($row['event_name'], 'E-sports') !== false || stripos($row['event_name'], 'Mobile') !== false) { $icon = 'fas fa-gamepad'; }
                        elseif (stripos($row['event_name'], 'Dance') !== false || stripos($row['event_name'], 'Vocal') !== false) { $icon = 'fas fa-music'; }

                        // 2. Proof Button Logic
                        $proof_btn = !empty($row['tally_sheet_url']) 
                            ? '<a href="' . htmlspecialchars('../' . $row['tally_sheet_url']) . '" target="_blank" class="btn btn-sm btn-outline-secondary border-0 bg-light"><i class="fas fa-file-image me-1"></i> View Proof</a>'
                            : '<span class="badge bg-light text-muted fw-normal">No Proof</span>';
                    ?>
                    <tr class="align-middle approved-row" style="background: white; border-bottom: 1px solid #f1f3f5;">
                        
                        <td class="ps-0 py-3 rounded-start" style="border-left: 5px solid #198754;">
                            <div class="d-flex align-items-center ps-3">
                                <div class="me-3 d-flex align-items-center justify-content-center text-success bg-success bg-opacity-10 rounded-circle" style="width: 40px; height: 40px;">
                                    <i class="<?= $icon ?>"></i>
                                </div>
                                <div class="d-flex flex-column">
                    <div class="d-flex flex-column">
                    <small class="text-uppercase text-muted fw-bold" style="font-size:0.7rem;"><?= htmlspecialchars($row['game_name']) ?></small>
                    <span class="fw-bold text-dark text-capitalize"><?= htmlspecialchars($row['event_name']) ?></span>
                    <div class="mt-1 d-flex align-items-center">
                        <small class="text-secondary fw-bold"><?= htmlspecialchars($row['category_name']) ?></small>
                        <?= (!empty($row['division_name']) ? '<small class="text-muted ms-1 fw-semibold"> - ' . htmlspecialchars($row['division_name']) . '</small>' : '') ?>
                    </div>
                </div>
                            </div>
                        </td>
                        
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="bg-success bg-opacity-10 rounded-circle p-2 me-2 d-flex justify-content-center align-items-center" style="width:35px; height:35px;">
                                    <i class="fas fa-user-check text-success small"></i>
                                </div>
                                <div class="d-flex flex-column">
                                    <span class="fw-bold text-dark small"><?= htmlspecialchars($row['approved_by_name'] ?? 'System') ?></span>
                                    <small class="text-muted" style="font-size:0.75rem;"><?= date('M d, h:i A', strtotime($row['approved_at'])) ?></small>
                                </div>
                            </div>
                        </td>

                        <td class="text-center">
                            <?= $proof_btn ?>
                        </td>

                        <td class="text-end pe-3 rounded-end">
                            <button type="button" class="btn btn-light btn-sm text-danger hover-shadow" 
                                    data-bs-toggle="modal" data-bs-target="#revokeModal"
                                    data-id="<?= $row['category_id'] ?>" 
                                    data-name="<?= htmlspecialchars($row['event_name'] . ' - ' . $row['category_name']) ?>"
                                    title="Revoke Approval">
                                <i class="fas fa-undo-alt"></i>
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
    
    <div class="mb-4 border-bottom pb-3">
        <h6 class="text-uppercase text-muted fw-bold small mb-2" style="letter-spacing: 1px;">Digital Entry Comparison</h6>
        <h4 class="fw-bold text-dark mb-2" id="modalEventTitle">Event Name</h4>
        
        <div class="d-flex align-items-center text-muted small">
            <i class="fas fa-user-circle me-2 text-primary opacity-50"></i>
            <span class="me-1">Submitted by:</span>
            <span class="fw-bold text-dark" id="modalSubmittedBy">Loading...</span>
        </div>
    </div>

    <div class="winner-card gold mb-3">
        <i class="fas fa-medal winner-icon text-gold fa-2x"></i>
        <div class="flex-grow-1 ps-3">
            <div class="small fw-bold text-warning text-uppercase" style="font-size: 0.7rem;">Gold Winner</div>
            <div class="fw-bold text-dark fs-6" id="modalGoldName">Team A</div>
        </div>
        <div class="text-end ps-2">
            <div class="badge bg-warning text-dark rounded-pill mb-1" id="modalGoldCount" style="min-width: 40px;">0</div>
            <div class="text-muted fw-bold text-uppercase" style="font-size: 0.6rem;">Medals</div>
        </div>
    </div>

    <div class="winner-card silver mb-3">
        <i class="fas fa-medal winner-icon text-silver fa-2x"></i>
        <div class="flex-grow-1 ps-3">
            <div class="small fw-bold text-secondary text-uppercase" style="font-size: 0.7rem;">Silver Winner</div>
            <div class="fw-bold text-dark fs-6" id="modalSilverName">Team B</div>
        </div>
        <div class="text-end ps-2">
            <div class="badge bg-secondary text-white rounded-pill mb-1" id="modalSilverCount" style="min-width: 40px;">0</div>
            <div class="text-muted fw-bold text-uppercase" style="font-size: 0.6rem;">Medals</div>
        </div>
    </div>

    <div class="winner-card bronze mb-4">
        <i class="fas fa-medal winner-icon fa-2x" style="color: #cd7f32;"></i>
        <div class="flex-grow-1 ps-3">
            <div class="small fw-bold text-uppercase" style="font-size: 0.7rem; color: #cd7f32;">Bronze Winner</div>
            <div class="fw-bold text-dark fs-6" id="modalBronzeName">Team C</div>
        </div>
        <div class="text-end ps-2">
            <div class="badge bg-light text-dark border rounded-pill mb-1" id="modalBronzeCount" style="min-width: 40px;">0</div>
            <div class="text-muted fw-bold text-uppercase" style="font-size: 0.6rem;">Medals</div>
        </div>
    </div>

    <div class="verification-arrow mb-3 text-primary bg-light py-2 rounded fw-bold small">
        Does the data match the evidence?
    </div>

    <div class="mt-auto d-grid gap-2">
        <form method="POST" id="approveForm">
            <input type="hidden" name="action" value="approve_result">
            <input type="hidden" name="category_id" id="approveCatId">
            <button type="submit" class="btn btn-success w-100 py-2 fw-bold shadow-sm text-uppercase" style="letter-spacing: 0.5px;">
                <i class="fas fa-check-circle me-2"></i> YES, Approve Result
            </button>
        </form>
        <button type="button" class="btn btn-outline-danger w-100 fw-bold text-uppercase" id="btnShowReject" style="letter-spacing: 0.5px;">
            <i class="fas fa-times-circle me-2"></i> NO, Reject (Mismatch)
        </button>
    </div>

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
    
    <footer class="footer-main">
        <div class="container">
            <div class="row">
                <div class="col-lg-5 col-md-12 mb-4 mb-lg-0">
                    <div class="footer-logo-group">
                        <img src="../images/PIT.png" alt="Logo">
                        <img src="../images/Cote.png" alt="Logo">
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
    document.addEventListener('DOMContentLoaded', function() {

        // ==========================================
        // 1. UI & LAYOUT LOGIC
        // ==========================================
        
        // --- Sidebar Toggle ---
        const mobileToggle = document.getElementById('mobileToggle');
        if (mobileToggle) {
            mobileToggle.addEventListener('click', function() {
                document.getElementById('sidebar').classList.toggle('show');
            });
        }

        // --- Dynamic Sidebar Height (Footer Overlap Fix) ---
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

        // --- Search Filter Logic ---
        // Attaching listener via JS is cleaner than inline onkeyup=""
        const searchInput = document.getElementById('approvedSearch');
        if (searchInput) {
            searchInput.addEventListener('keyup', filterHistory);
        }

        function filterHistory() {
            if (!searchInput) return;
            const filter = searchInput.value.toLowerCase();
            const rows = document.querySelectorAll('.approved-row');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        }


        // ==========================================
        // 2. MODAL & FORM LOGIC
        // ==========================================

        // --- Verification Modal (Populate & Zoom) ---
        const verifyModal = document.getElementById('verificationModal');
        if (verifyModal) {
            verifyModal.addEventListener('show.bs.modal', function(event) {
                const btn = event.relatedTarget;
                const evidenceUrl = btn.getAttribute('data-evidence');
                const catId = btn.getAttribute('data-id');

                // Populate Text Fields
                document.getElementById('modalEventTitle').textContent = btn.getAttribute('data-event');
                document.getElementById('modalSubmittedBy').textContent = btn.getAttribute('data-submitted-by');
                document.getElementById('modalGoldName').textContent = btn.getAttribute('data-gold-name');
                document.getElementById('modalGoldCount').textContent = btn.getAttribute('data-gold-count');
                document.getElementById('modalSilverName').textContent = btn.getAttribute('data-silver-name');
                document.getElementById('modalSilverCount').textContent = btn.getAttribute('data-silver-count');
                document.getElementById('modalBronzeName').textContent = btn.getAttribute('data-bronze-name');
                document.getElementById('modalBronzeCount').textContent = btn.getAttribute('data-bronze-count');

                // Set Hidden Form IDs
                document.getElementById('approveCatId').value = catId;
                document.getElementById('rejectCatId').value = catId;

                // Handle Image & Zoom
                const imgEl = document.getElementById('modalEvidenceImg');
                const noImgEl = document.getElementById('noEvidenceMsg');
                const evidenceContainer = document.querySelector('.evidence-col');

                // Reset Zoom State
                imgEl.classList.remove('zoomed');
                evidenceContainer.scrollTop = 0;
                evidenceContainer.scrollLeft = 0;
                evidenceContainer.style.cursor = 'zoom-in';

                if (evidenceUrl && evidenceUrl !== '') {
                    imgEl.src = evidenceUrl;
                    imgEl.classList.remove('d-none');
                    noImgEl.classList.add('d-none');

                    // Zoom Click Handler
                    imgEl.onclick = function() {
                        this.classList.toggle('zoomed');
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

                // Reset Reject Section Visibility
                document.getElementById('rejectSection').classList.add('d-none');
            });
        }

        // --- Reject Form Toggles ---
        const btnShowReject = document.getElementById('btnShowReject');
        const btnCancelReject = document.getElementById('btnCancelReject');
        const rejectSection = document.getElementById('rejectSection');

        if (btnShowReject) {
            btnShowReject.addEventListener('click', function() {
                rejectSection.classList.remove('d-none');
                this.scrollIntoView({ behavior: 'smooth' });
            });
        }
        if (btnCancelReject) {
            btnCancelReject.addEventListener('click', function() {
                rejectSection.classList.add('d-none');
            });
        }

        // --- Revoke Modal ---
        const revokeModal = document.getElementById('revokeModal');
        if (revokeModal) {
            revokeModal.addEventListener('show.bs.modal', function(event) {
                const btn = event.relatedTarget;
                document.getElementById('revoke_category_id').value = btn.getAttribute('data-id');
                document.getElementById('revoke_category_name').textContent = btn.getAttribute('data-name');
            });
        }


        // ==========================================
        // 3. REAL-TIME DATA UPDATERS
        // ==========================================

        /**
         * Function 1: Updates Page Content (Tables & Inner Tab Badges)
         * Fetches from results.php
         */
        function updatePageContent() {
            fetch('results.php?ajax_update=1')
                .then(response => response.json())
                .then(data => {
                    // A. Update Inner Tab Badges
                    const pendingTabBadge = document.querySelector('#resultsTab button[data-bs-target="#pending"] .badge');
                    const approvedTabBadge = document.querySelector('#resultsTab button[data-bs-target="#approved"] .badge');

                    if (pendingTabBadge) pendingTabBadge.textContent = data.pending_count;
                    if (approvedTabBadge) approvedTabBadge.textContent = data.approved_count;

                    // B. Update Table Content (Only if NOT hovering)
                    const isHovering = document.querySelector('#pending-container:hover') || document.querySelector('#approved .results-table:hover');

                    if (!isHovering) {
                        const pendingContainer = document.getElementById('pending-container');
                        const approvedTbody = document.querySelector('#approved tbody');

                        // Update Pending Cards
                        if (pendingContainer && pendingContainer.innerHTML !== data.pending_html) {
                            pendingContainer.innerHTML = data.pending_html;
                        }

                        // Update Approved Table
                        if (approvedTbody && approvedTbody.innerHTML !== data.approved_html) {
                            approvedTbody.innerHTML = data.approved_html;
                            // Re-apply search filter so results don't vanish
                            filterHistory();
                        }
                    }
                })
                .catch(err => console.error('Content update error:', err));
        }

        /**
         * Function 2: Updates Sidebar Badges (Global Navigation)
         * Fetches from api_notifications.php to get Red (Requests) and Yellow (Results) badges
         */
        function updateSidebarBadges() {
            fetch('../api_notifications.php?t=' + new Date().getTime())
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update "Approve Results" (Yellow)
                        updateSingleBadge('results.php', data.pending_results, 'bg-warning text-dark');

                        // Update "Account Requests" (Red)
                        updateSingleBadge('Manage_Requests.php', data.pending_requests, 'bg-danger');
                    }
                })
                .catch(err => console.error('Sidebar badge error:', err));
        }

        /**
         * Helper to create or update a specific badge in the sidebar
         */
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


        // ==========================================
        // 4. INITIALIZATION
        // ==========================================
        
        // Run immediately on load
        updatePageContent();
        updateSidebarBadges();

        // Start Intervals (Every 5 seconds)
        setInterval(updatePageContent, 5000);
        setInterval(updateSidebarBadges, 5000);

    });
</script>
</body>
</html>