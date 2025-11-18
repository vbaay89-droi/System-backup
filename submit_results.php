<?php
session_start();
require_once 'config.php'; // Your DB connection
require_once 'db_connect.php'; // ### ADDED: Include the logger function ###

// 1. SECURITY & ACCESS CONTROL
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Event Manager') {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Event Manager';
$current_page = 'submit_results.php'; // Manually set for active state
$alert_message = '';
$alert_type = 'success';

// 2. GET CATEGORY ID & VERIFY PERMISSION
if (!isset($_GET['category_id'])) {
    header('Location: event_manager_dashboard.php');
    exit();
}
$category_id = (int)$_GET['category_id'];

try {
    // This query is correct and fetches all info we need
    // ### FIX: Added SQL alias to standardize 'Results Approved' to 'Completed' ###
    $stmt_check = $conn->prepare("SELECT g.game_name, ge.event_name, c.category_name, 
                                  CASE 
                                      WHEN c.status = 'Results Approved' THEN 'Completed' 
                                      ELSE c.status 
                                  END AS status,
                                  c.category_type
                                  FROM categories c
                                  JOIN game_events ge ON c.event_id = ge.event_id
                                  JOIN games g ON ge.game_id = g.game_id
                                  JOIN event_manager_assignments ema ON ge.event_id = ema.event_id
                                  WHERE c.category_id = ? AND ema.user_id = ?");
    $stmt_check->bind_param("ii", $category_id, $user_id);
    $stmt_check->execute();
    $result = $stmt_check->get_result();
    if ($result->num_rows == 0) {
        $_SESSION['alert_message'] = "Permission denied or event not found.";
        $_SESSION['alert_type'] = 'danger';
        header('Location: event_manager_dashboard.php');
        exit();
    }
    $category_info = $result->fetch_assoc();
    $stmt_check->close();
} catch (Exception $e) {
    die("Error: ". $e->getMessage());
}

// This variable will disable the form if true
// Now checks for 'completed' (which includes 'results approved')
$is_locked = (strtolower($category_info['status']) == 'completed');


// 3. FORM HANDLING (Save as 'Draft' or 'Submit for Approval')
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Check lock status *before* processing POST
    if ($is_locked) {
        $alert_message = "Error: These results are already approved and locked.";
        $alert_type = 'danger';
    } else {
        try {
            // Capture Date, Time, and Venue fields
            $event_date = !empty($_POST['event_date']) ? $_POST['event_date'] : null;
            $event_time = !empty($_POST['event_time']) ? $_POST['event_time'] : null;
            $venue = !empty($_POST['venue']) ? $_POST['venue'] : null;

            // Capture Medal fields (only if it's a medal event)
            $gold_team = null;
            $gold_count = 0;
            $silver_team = null;
            $silver_count = 0;
            $bronze_team = null;
            $bronze_count = 0;

            if ($category_info['category_type'] == 'medal') {
                $gold_team = !empty($_POST['gold_winner_id']) ? (int)$_POST['gold_winner_id'] : null;
                $gold_count = !empty($_POST['gold_count']) ? (int)$_POST['gold_count'] : 0;
                
                $silver_team = !empty($_POST['silver_winner_id']) ? (int)$_POST['silver_winner_id'] : null;
                $silver_count = !empty($_POST['silver_count']) ? (int)$_POST['silver_count'] : 0;
                
                $bronze_team = !empty($_POST['bronze_winner_id']) ? (int)$_POST['bronze_winner_id'] : null;
                $bronze_count = !empty($_POST['bronze_count']) ? (int)$_POST['bronze_count'] : 0;

                // Check for duplicate winners
                $winners = array_filter([$gold_team, $silver_team, $bronze_team]);
                if (count($winners) !== count(array_unique($winners))) {
                    throw new Exception("The same team cannot win multiple medals.");
                }
            }

            // Determine status based on button pressed
            $new_status = $category_info['status']; // Start with the current status
            
            if ($action === 'submit_for_approval') {
                $new_status = 'Results Submitted'; // Set to 'Submitted'

                // =================================================================
                // ### NEW LOGIC APPLIED FROM event_manager_matches.php ###
                // This validation only runs when clicking "Submit for Approval"
                if ($category_info['category_type'] == 'medal') {
                    if (empty($gold_team) || empty($silver_team) || empty($bronze_team)) {
                        throw new Exception("All medal winners (Gold, Silver, and Bronze) must be selected to submit for approval.");
                    }
                }
                // ### END: NEW LOGIC ###
                // =================================================================

            } elseif ($action === 'save_pending') {
                // Keep the current status (e.g., 'Completed (Pending Results)' or 'Results Rejected')
                $new_status = $category_info['status']; 
            }

            // 'categories' table UPDATE query
            $stmt = $conn->prepare(
                "UPDATE categories SET 
                    event_date = ?, event_time = ?, venue = ?,
                    gold_winner_college_id = ?, gold_count = ?,
                    silver_winner_college_id = ?, silver_count = ?,
                    bronze_winner_college_id = ?, bronze_count = ?,
                    status = ?
                 WHERE category_id = ?"
            );
            $stmt->bind_param("sssiiiiissi", 
                $event_date, $event_time, $venue,
                $gold_team, $gold_count, 
                $silver_team, $silver_count, 
                $bronze_team, $bronze_count, 
                $new_status, $category_id
            );
            $stmt->execute();
            $stmt->close();

            // ### ADDED: Logging logic ###
            if ($action === 'submit_for_approval') {
                try {
                    $context = [
                        'category_name' => $category_info['category_name'], 
                        'gold_id' => $gold_team, 
                        'silver_id' => $silver_team, 
                        'bronze_id' => $bronze_team, 
                        'type' => 'Medal'
                    ];
                    log_activity($conn, $user_id, 'SUBMITTED_RESULTS', $category_id, 'category', null, null, $context);
                } catch (Exception $log_e) { 
                    error_log("Failed to log SUBMITTED_RESULTS (Medal): " . $log_e->getMessage()); 
                }

                $_SESSION['alert_message'] = "Results for '{$category_info['category_name']}' have been submitted for approval.";
                $_SESSION['alert_type'] = 'success';
                header('Location: my_events.php'); // Redirect to 'my_events' list
                exit();
                
            } elseif ($action === 'save_pending') {
                try {
                    $context = [
                        'new_category_name' => $category_info['category_name'],
                        'new_status' => $new_status,
                        'event_date' => $event_date,
                        'new_venue' => $venue
                    ];
                    // Note: This uses 'UPDATED_CATEGORY' which is already handled by the dashboard
                    log_activity($conn, $user_id, 'UPDATED_CATEGORY', $category_id, 'category', null, null, $context);
                } catch (Exception $log_e) {
                    error_log("Failed to log UPDATED_CATEGORY (Save Pending): " . $log_e->getMessage());
                }
            }
            // ### END: Logging logic ###

            $alert_message = "Changes saved successfully.";
            
        } catch (Exception $e) {
            $alert_message = "Error: " . $e->getMessage();
            $alert_type = 'danger';
        }
    }
    
    // Refresh category info after POST
    $new_status_from_db = $conn->query("SELECT CASE WHEN status = 'Results Approved' THEN 'Completed' ELSE status END FROM categories WHERE category_id = $category_id")->fetch_column();
    $category_info['status'] = $new_status_from_db;
    // Refresh the lock status
    $is_locked = (strtolower($category_info['status']) == 'completed');
}

// 4. FETCH DATA FOR DISPLAY
// A. Fetch all Teams (for dropdown)
$teams = $conn->query("SELECT college_id AS team_id, college_name AS team_name FROM colleges ORDER BY college_name")->fetch_all(MYSQLI_ASSOC);

// B. Fetch existing entry from 'categories' table
$current_submission = $conn->query("SELECT * FROM categories WHERE category_id = $category_id")->fetch_assoc();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Category - PIT Tallying</title>
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
        body { background-color: var(--bg-light); margin: 0; padding: 0; min-height: 100vh; font-family: 'Inter', sans-serif; display: flex; flex-direction: column; }
        .navbar { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.15); padding: 1rem 1.5rem; height: var(--header-height); position: fixed; top: 0; left: 0; right: 0; z-index: 1050; }
        .navbar-brand .brand-heading { font-family: 'Poppins', sans-serif; font-weight: 700; }
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; }
        .user-dropdown .dropdown-toggle img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; margin-right: 10px; }
        .sidebar { width: var(--sidebar-width); position: fixed; top: var(--header-height); left: 0; height: calc(100vh - var(--header-height)); background: #2c3e50; color: white; box-shadow: 5px 0 15px rgba(0,0,0,0.2); z-index: 1040; transition: width var(--transition); overflow-y: auto; overflow-x: hidden; }
        .sidebar-nav { padding: 50px 0 20px 0; }
        .sidebar-nav .nav-link { color: rgba(255, 255, 255, 0.7); font-size: 1.05rem; font-weight: 500; padding: 15px 25px; transition: var(--transition); border-left: 5px solid transparent; margin: 2px 0; display: flex; align-items: center; text-decoration: none; }
        .sidebar-nav .nav-link i { width: 30px; text-align: center; flex-shrink: 0; font-size: 0.95em; }
        .sidebar-nav .nav-link:hover { color: white; background: rgba(255, 255, 255, 0.05); border-left-color: #1abc9c; }
        .sidebar-nav .nav-link.active { color: white; background: rgba(255, 255, 255, 0.1); border-left-color: #3498db; font-weight: 600; }
        .main-content { flex: 1 0 auto; padding: 30px; margin-top: var(--header-height); margin-left: var(--sidebar-width); transition: margin-left var(--transition); min-height: calc(100vh - var(--header-height)); }
                /* Footer */
        footer {
            flex-shrink: 0;
            background: #2c3e50 !important;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            padding-left: var(--sidebar-width); /* <-- MODIFIED */
            transition: padding-left var(--transition); /* <-- MODIFIED */
            position: relative;
            z-index: 1041;
        }
                .sidebar.minimized ~ footer {
            padding-left: var(--sidebar-min-width); /* <-- MODIFIED */
        }
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        .card { border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        .gold { color: #FFD700; }
        .silver { color: #C0C0C0; }
        .bronze { color: #CD7F32; }
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
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
        
        .form-control:disabled, .form-select:disabled {
            background-color: #e9ecef;
            opacity: 1;
            cursor: not-allowed;
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items: center" href="event_manager_dashboard.php">
                <img src="imageslogo.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white brand-heading" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light" style="font-size: 0.75rem;">Event Manager Panel</small>
                </div>
            </a>
            <div class="dropdown user-dropdown ms-auto me-2 me-lg-0">
                <a href="#" class="dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle navbar-profile-icon"></i>
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($username); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="admin_profile.php"><i class="fas fa-user-circle"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="login.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="sidebar" id="sidebar">
        <ul class="nav flex-column sidebar-nav">
            <li class="nav-item">
                <a class="nav-link" href="event_manager_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="my_events.php">
                    <i class="fas fa-trophy me-2"></i> <span>My Assigned Events</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="Eventpage.php" target="_blank">
                    <i class="fas fa-globe me-2"></i> <span>View Public Events</span>
                </a>
            </li>
            <li class="nav-item mt-auto">
                <a class="nav-link text-danger" href="login.php">
                    <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <div class="main-content">
        <div class="container-fluid">
            
            <nav aria-label="breadcrumb" class="mb-2">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="event_manager_dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="my_events.php">My Assigned Events</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Manage Category</li>
                </ol>
            </nav>
            <h1 class="section-title mb-2">Manage Category</h1>
            <p class="lead mb-4">
                For Event: <strong><?php echo htmlspecialchars($category_info['event_name']); ?></strong> / 
                Category: <strong><?php echo htmlspecialchars($category_info['category_name']); ?></strong>
            </p>
            
            <?php if (!empty($alert_message)): ?>
                <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($alert_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($is_locked): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> These results have been approved and are locked.
                </div>
            <?php elseif ($category_info['status'] == 'Results Submitted'): ?>
                 <div class="alert alert-info">
                    <i class="fas fa-paper-plane"></i> These results are pending approval. You can still save changes.
                </div>
            <?php elseif ($category_info['status'] == 'Results Rejected'): ?>
                 <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> These results were rejected. Please correct and resubmit.
                </div>
            <?php endif; ?>

            <form method="POST" action="submit_results.php?category_id=<?php echo $category_id; ?>">
                <div class="row g-4">
                
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Event Schedule & Details</h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label for="event_date" class="form-label">Event Date</label>
                                        <input type="date" class="form-control" id="event_date" name="event_date"
                                               value="<?php echo htmlspecialchars($current_submission['event_date'] ?? ''); ?>" 
                                               <?php if ($is_locked) echo 'disabled'; ?>>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="event_time" class="form-label">Event Time</label>
                                        <input type="time" class="form-control" id="event_time" name="event_time"
                                               value="<?php echo htmlspecialchars($current_submission['event_time'] ?? ''); ?>"
                                               <?php if ($is_locked) echo 'disabled'; ?>>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="venue" class="form-label">Venue</label>
                                        <input type="text" class="form-control" id="venue" name="venue"
                                               placeholder="e.g., PIT Main Gymnasium"
                                               value="<?php echo htmlspecialchars($current_submission['venue'] ?? ''); ?>"
                                               <?php if ($is_locked) echo 'disabled'; ?>>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php 
                        $status_lower = strtolower($category_info['status']);
                        // ### FIX: Show this card if results are pending OR if they are already completed/approved ###
                        $show_results_card = (
                            $status_lower == 'ongoing' || // <-- ADD THIS LINE
                            $status_lower == 'completed (pending results)' || 
                            $status_lower == 'results submitted' || 
                            $status_lower == 'results rejected' ||
                            $status_lower == 'completed' // This ensures it displays when locked
                        );
                    ?>

                    <?php if ($category_info['category_type'] == 'medal' && $show_results_card): ?>
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Select Winners & Input Medal Counts</h5>
                            </div>
                            <div class="card-body">
                                
                                <div class="mb-3 row align-items-center">
                                    <label for="gold_winner_id" class="col-sm-2 col-form-label fs-5 fw-bold gold"><i class="fas fa-medal"></i> Gold</label>
                                    <div class="col-sm-7">
                                        <select class="form-select" id="gold_winner_id" name="gold_winner_id" <?php if ($is_locked) echo 'disabled'; ?>>
                                            <option value="">-- Select Gold Winner --</option>
                                            <?php foreach ($teams as $team): ?>
                                                <option value="<?php echo $team['team_id']; ?>" 
                                                    <?php echo ($current_submission && $current_submission['gold_winner_college_id'] == $team['team_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($team['team_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <label for="gold_count" class="col-sm-1 col-form-label">Count:</label>
                                    <div class="col-sm-2">
                                        <input type="number" class="form-control" name="gold_count" value="<?php echo $current_submission['gold_count'] ?? 1; ?>" min="0" <?php if ($is_locked) echo 'disabled'; ?>>
                                    </div>
                                </div>
                                
                                <div class="mb-3 row align-items-center">
                                    <label for="silver_winner_id" class="col-sm-2 col-form-label fs-5 fw-bold silver"><i class="fas fa-medal"></i> Silver</label>
                                    <div class="col-sm-7">
                                        <select class="form-select" id="silver_winner_id" name="silver_winner_id" <?php if ($is_locked) echo 'disabled'; ?>>
                                            <option value="">-- Select Silver Winner --</option>
                                            <?php foreach ($teams as $team): ?>
                                                <option value="<?php echo $team['team_id']; ?>" 
                                                    <?php echo ($current_submission && $current_submission['silver_winner_college_id'] == $team['team_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($team['team_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <label for="silver_count" class="col-sm-1 col-form-label">Count:</label>
                                    <div class="col-sm-2">
                                        <input type="number" class="form-control" name="silver_count" value="<?php echo $current_submission['silver_count'] ?? 1; ?>" min="0" <?php if ($is_locked) echo 'disabled'; ?>>
                                    </div>
                                </div>
                                
                                <div class="mb-3 row align-items-center">
                                    <label for="bronze_winner_id" class="col-sm-2 col-form-label fs-5 fw-bold bronze"><i class="fas fa-medal"></i> Bronze</label>
                                    <div class="col-sm-7">
                                        <select class="form-select" id="bronze_winner_id" name="bronze_winner_id" <?php if ($is_locked) echo 'disabled'; ?>>
                                            <option value="">-- Select Bronze Winner --</option>
                                            <?php foreach ($teams as $team): ?>
                                                <option value="<?php echo $team['team_id']; ?>" 
                                                    <?php echo ($current_submission && $current_submission['bronze_winner_college_id'] == $team['team_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($team['team_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <label for="bronze_count" class="col-sm-1 col-form-label">Count:</label>
                                    <div class="col-sm-2">
                                        <input type="number" class="form-control" name="bronze_count" value="<?php echo $current_submission['bronze_count'] ?? 1; ?>" min="0" <?php if ($is_locked) echo 'disabled'; ?>>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!$is_locked): ?>
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body d-flex justify-content-end gap-2">
                                <button type="submit" name="action" value="save_pending" class="btn btn-secondary">
                                    <i class="fas fa-save me-1"></i> Save Changes
                                </button>
                                
                                <?php
                                // We need to check the original status *before* the alias
                                $original_status_lower = strtolower($conn->query("SELECT status FROM categories WHERE category_id = $category_id")->fetch_column());
                                $can_submit = (
                                    $original_status_lower == 'ongoing' || // <-- ADD THIS LINE
                                    $original_status_lower == 'completed (pending results)' || 
                                    $original_status_lower == 'results submitted' || 
                                    $original_status_lower == 'results rejected'
                                );
                                ?>
                                <?php if ($category_info['category_type'] == 'medal' && $can_submit): ?>
                                <button type="submit" name="action" value="submit_for_approval" class="btn btn-success" 
                                        onclick="return confirm('Are you sure you want to submit these results for final approval?')">
                                    <i class="fas fa-paper-plane me-2"></i> Submit for Approval
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                </div>
            </form>
        </div>
    </div>
    
    <footer class="bg-dark text-white py-4"> <div class="text-center">
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small>
        </div>
    </footer>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle Window Resize
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth > 992) {
                        sidebar.classList.remove('show');
                        sidebarOverlay.classList.remove('show');
                    }
                }, 250);
            });

            // --- ### NEW: FIX SIDEBAR/FOOTER OVERLAP ### ---
            const footer = document.querySelector('footer');
            const navbar = document.querySelector('.navbar');

            if (sidebar && footer && navbar) {
                function adjustSidebarHeight() {
                    // This logic should only apply to desktop view
                    if (window.innerWidth <= 992) {
                        sidebar.style.height = ''; // Reset to CSS default for mobile
                        return;
                    }

                    const navbarHeight = navbar.offsetHeight;
                    const footerTop = footer.getBoundingClientRect().top;
                    const viewportHeight = window.innerHeight;
                    
                    // 1. Calculate the max possible height (navbar top to viewport bottom)
                    const maxSidebarHeight = viewportHeight - navbarHeight;

                    // 2. Calculate the available height (navbar top to footer top)
                    const availableHeight = footerTop - navbarHeight;

                    // 3. Choose the smaller of the two heights, but never less than 0
                    const newHeight = Math.max(0, Math.min(maxSidebarHeight, availableHeight));
                    
                    // 4. Apply the new height as an inline style
                    sidebar.style.height = `${newHeight}px`;
                }

                // Add listeners for scroll and resize events
                window.addEventListener('scroll', adjustSidebarHeight, { passive: true });
                window.addEventListener('resize', adjustSidebarHeight);
                
                // Initial call to set the correct height on page load
                // Small delay to ensure all elements are rendered
                setTimeout(adjustSidebarHeight, 100);
            }
    </script>
</body>
</html>