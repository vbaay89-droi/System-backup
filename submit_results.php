<?php
session_start();
require_once 'config.php'; 
require_once 'db_connect.php'; 

// 1. SECURITY & ACCESS CONTROL
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Event Manager') {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Event Manager';
$current_page = 'submit_results.php'; 
$alert_message = '';
$alert_type = 'success';

// --- FETCH FULL NAME ---
$stmt = $conn->prepare("SELECT full_name, username FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id); 
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc(); 
$stmt->close();

if (!empty($user_data['full_name'])) {
    $display_name = $user_data['full_name'];
} else {
    $display_name = $user_data['username'] ?? $username; 
}

// 2. GET CATEGORY ID & VERIFY PERMISSION
if (!isset($_GET['category_id'])) {
    header('Location: event_manager_dashboard.php');
    exit();
}
$category_id = (int)$_GET['category_id'];

try {
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

$is_locked = (strtolower($category_info['status']) == 'completed');

// 3. FORM HANDLING
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($is_locked) {
        $alert_message = "Error: These results are already approved and locked.";
        $alert_type = 'danger';
    } else {
        try {
            $event_date = !empty($_POST['event_date']) ? $_POST['event_date'] : null;
            $event_time = !empty($_POST['event_time']) ? $_POST['event_time'] : null;
            $venue = !empty($_POST['venue']) ? $_POST['venue'] : null;

            $gold_team = null; $gold_count = 0;
            $silver_team = null; $silver_count = 0;
            $bronze_team = null; $bronze_count = 0;

            if ($category_info['category_type'] == 'medal') {
                $gold_team = !empty($_POST['gold_winner_id']) ? (int)$_POST['gold_winner_id'] : null;
                $gold_count = !empty($_POST['gold_count']) ? (int)$_POST['gold_count'] : 0;
                
                $silver_team = !empty($_POST['silver_winner_id']) ? (int)$_POST['silver_winner_id'] : null;
                $silver_count = !empty($_POST['silver_count']) ? (int)$_POST['silver_count'] : 0;
                
                $bronze_team = !empty($_POST['bronze_winner_id']) ? (int)$_POST['bronze_winner_id'] : null;
                $bronze_count = !empty($_POST['bronze_count']) ? (int)$_POST['bronze_count'] : 0;

                $winners = array_filter([$gold_team, $silver_team, $bronze_team]);
                if (count($winners) !== count(array_unique($winners))) {
                    throw new Exception("The same team cannot win multiple medals.");
                }
            }

            $new_status = $category_info['status']; 
            
            if ($action === 'submit_for_approval') {
                $new_status = 'Results Submitted'; 

                if ($category_info['category_type'] == 'medal') {
                    if (empty($gold_team) || empty($silver_team) || empty($bronze_team)) {
                        throw new Exception("All medal winners (Gold, Silver, and Bronze) must be selected to submit for approval.");
                    }
                }
            } elseif ($action === 'save_pending') {
                $new_status = $category_info['status']; 
            }

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
                header('Location: my_events.php'); 
                exit();
                
            } elseif ($action === 'save_pending') {
                $alert_message = "Draft saved successfully.";
            }
            
        } catch (Exception $e) {
            $alert_message = "Error: " . $e->getMessage();
            $alert_type = 'danger';
        }
    }
    
    $new_status_from_db = $conn->query("SELECT CASE WHEN status = 'Results Approved' THEN 'Completed' ELSE status END FROM categories WHERE category_id = $category_id")->fetch_column();
    $category_info['status'] = $new_status_from_db;
    $is_locked = (strtolower($category_info['status']) == 'completed');
}

// 4. FETCH DATA
$teams = $conn->query("SELECT college_id AS team_id, college_name AS team_name FROM colleges ORDER BY college_name")->fetch_all(MYSQLI_ASSOC);
$current_submission = $conn->query("SELECT * FROM categories WHERE category_id = $category_id")->fetch_assoc();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Results - PIT Tallying</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* --- GLOBAL THEME FROM my_events.php --- */
        :root { 
            --sidebar-width: 260px; 
            --sidebar-collapsed-width: 80px; 
            --header-height: 82px; 
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08); 
            --bg-light: #F8F9FA; 
            --gold: #f59e0b;
            --silver: #64748b;
            --bronze: #ea580c;
        }
        
        body { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            background-attachment: fixed;
            margin: 0; 
            padding: 0; 
            min-height: 100vh;
            font-family: 'Inter', sans-serif; 
            display: flex; 
            flex-direction: column; 
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: radial-gradient(circle at 20% 50%, rgba(120, 119, 198, 0.3), transparent 50%),
                        radial-gradient(circle at 80% 80%, rgba(99, 102, 241, 0.2), transparent 50%);
            pointer-events: none;
            z-index: 0;
        }
        
        /* --- NAVBAR & SIDEBAR (Copied Logic) --- */
        .navbar { 
            background: rgba(26, 26, 26, 0.95) !important;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0,0,0,0.2); 
            padding: 1rem 1.5rem; 
            height: var(--header-height); 
            position: fixed; 
            top: 0; left: 0; right: 0; 
            z-index: 1050;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .navbar-brand .brand-heading { font-family: 'Poppins', sans-serif; font-weight: 700; }
        
        .user-dropdown .dropdown-toggle { 
            color: white; display: flex; align-items: center; text-decoration: none; 
            padding: 8px 16px; border-radius: 50px; background: rgba(255, 255, 255, 0.1); 
            backdrop-filter: blur(10px); transition: all 0.3s ease; border: 1px solid rgba(255, 255, 255, 0.2); 
        }
        
        .user-dropdown .dropdown-toggle:hover {
            background: rgba(255, 255, 255, 0.2); transform: translateY(-2px);
        }
        
        /* 1. Sidebar: Always Full Width (260px) */
        .sidebar { 
            width: var(--sidebar-width); /* Fixed width */
            position: fixed; 
            top: var(--header-height); 
            left: 0; 
            height: calc(100vh - var(--header-height)); 
            background: rgba(44, 62, 80, 0.95); 
            backdrop-filter: blur(10px); 
            color: white; 
            box-shadow: 5px 0 30px rgba(0,0,0,0.3); 
            z-index: 1040; 
            transition: all 0.3s ease;
            overflow-y: auto;
        }
        
        /* REMOVED: .sidebar:hover logic */
        
        .sidebar-nav { padding: 20px 0; }
        
        .sidebar-nav .nav-link { 
            color: rgba(255, 255, 255, 0.7); 
            font-size: 1.05rem; 
            font-weight: 500; 
            padding: 12px 25px; /* Standard padding */
            display: flex; 
            align-items: center; 
            text-decoration: none; 
            border-left: 5px solid transparent;
            justify-content: flex-start;
        }
        
        .sidebar-nav .nav-link i { 
            width: 30px; 
            text-align: center; 
            font-size: 1.1em; 
            margin-right: 10px; 
        }
        
        /* 2. Text Always Visible */
        .sidebar-nav .nav-link span { 
            opacity: 1; /* Always visible */
            display: inline-block; 
            transition: none;
        }
        
        /* Remove the :hover delay logic */
        
        .sidebar-nav .nav-link:hover { 
            color: white; 
            background: rgba(255, 255, 255, 0.05); 
            border-left-color: #667eea; 
        }
        
        .sidebar-nav .nav-link.active { 
            color: white; 
            background: linear-gradient(90deg, rgba(102, 126, 234, 0.2), transparent); 
            border-left-color: #667eea; 
        }

        /* 3. Main Content: Pushed over by full sidebar width */
        .main-content { 
            flex: 1 0 auto; 
            margin-left: var(--sidebar-width); /* Fixed 260px margin */
            width: calc(100% - var(--sidebar-width));
            padding: 30px; 
            margin-top: var(--header-height); 
            transition: margin-left 0.3s ease; 
            position: relative; 
            z-index: 1;
        }
        
        /* 4. Footer: Pushed over by full sidebar width */
        footer {
            flex-shrink: 0; 
            background: rgba(44, 62, 80, 0.95) !important; 
            backdrop-filter: blur(10px); 
            padding-left: var(--sidebar-width); /* Fixed 260px padding */
            transition: padding-left 0.3s ease; 
            position: relative; 
            z-index: 1041;
        }

        /* --- PAGE SPECIFIC STYLES --- */
        .page-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .page-header::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 5px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }
        
        .page-title {
            font-family: 'Poppins', sans-serif; font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
            margin: 0; font-size: 2rem;
        }

        .breadcrumb { background: transparent; padding: 0; margin-bottom: 0; }
        .breadcrumb-item a { color: #667eea; text-decoration: none; }

        /* Context Strip */
        .event-context-strip {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border-left: 5px solid #667eea;
            display: flex;
            flex-wrap: wrap;
            gap: 2rem;
            align-items: center;
        }
        
        .context-item { display: flex; flex-direction: column; }
        .context-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d; font-weight: 700; margin-bottom: 2px; }
        .context-value { font-size: 1rem; font-weight: 600; color: #2c3e50; }

        /* Cards */
        .card { border: none; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); overflow: hidden; margin-bottom: 1.5rem; }
        .card-header { background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); border-bottom: 1px solid #e9ecef; padding: 1.25rem 1.5rem; }
        .card-header h5 { color: #2c3e50; font-weight: 700; margin: 0; font-size: 1.1rem; }

        /* Medal Rows */
        .medal-row {
            padding: 1.25rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            border: 1px solid transparent;
            transition: transform 0.2s ease;
            box-shadow: 0 2px 5px rgba(0,0,0,0.03);
        }
        .medal-row:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.08); }
        
        .medal-row.gold-row { background: linear-gradient(to right, #fffbf0, #fff); border-color: #fcebb6; }
        .medal-row.silver-row { background: linear-gradient(to right, #f8f9fa, #fff); border-color: #e9ecef; }
        .medal-row.bronze-row { background: linear-gradient(to right, #fff7ed, #fff); border-color: #fed7aa; }
        
        .medal-label { display: flex; align-items: center; font-weight: 800; font-size: 1.1rem; letter-spacing: 0.5px; }
        .medal-label i { margin-right: 10px; font-size: 1.5rem; filter: drop-shadow(0 2px 2px rgba(0,0,0,0.15)); }
        
        .text-gold { color: var(--gold); }
        .text-silver { color: var(--silver); }
        .text-bronze { color: var(--bronze); }
        
        .form-control, .form-select { 
            border-radius: 10px; border: 2px solid rgba(102, 126, 234, 0.2); padding: 0.75rem 1rem; 
        }
        .form-control:focus, .form-select:focus { 
            border-color: #667eea; box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25); 
        }

        @media (max-width: 992px) {
            .sidebar { width: 0; } 
            .sidebar:hover { width: var(--sidebar-width); }
            .main-content, footer { margin-left: 0; width: 100%; }
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="event_manager_dashboard.php">
                <img src="imageslogo.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white brand-heading" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light" style="font-size: 0.75rem;">Event Manager Panel</small>
                </div>
            </a>
            <button class="navbar-toggler d-lg-none" type="button" id="mobileToggle">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="dropdown user-dropdown ms-auto me-2 me-lg-0">
                <a href="#" class="dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle" style="font-size: 36px; margin-right: 10px;"></i>
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($display_name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="admin_profile.php"><i class="fas fa-user-circle me-2"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="sidebar" id="sidebar">
        <ul class="nav flex-column sidebar-nav">
            <li class="nav-item">
                <a class="nav-link" href="my_events.php"> <i class="fas fa-chevron-left me-2"></i> <span>Back to My Events</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="javascript:void(0);">
                    <i class="fas fa-edit me-2"></i> <span>Submit Results</span>
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
                <nav aria-label="breadcrumb" class="mb-3">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="event_manager_dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="my_events.php">My Assigned Events</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Submit Results</li>
                    </ol>
                </nav>
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="page-title"><?php echo htmlspecialchars($category_info['category_name']); ?></h1>
                        <p class="text-muted mb-0 mt-2">
                            <i class="fas fa-gamepad me-1"></i> <strong><?php echo htmlspecialchars($category_info['game_name']); ?></strong> &nbsp;|&nbsp; 
                            <i class="fas fa-trophy me-1"></i> <strong><?php echo htmlspecialchars($category_info['event_name']); ?></strong>
                        </p>
                    </div>
                    <div>
                        <?php if ($is_locked): ?>
                            <span class="badge bg-success fs-6 px-3 py-2 rounded-pill shadow-sm"><i class="fas fa-check-circle me-1"></i> Approved & Locked</span>
                        <?php elseif ($category_info['status'] == 'Results Submitted'): ?>
                             <span class="badge bg-warning text-dark fs-6 px-3 py-2 rounded-pill shadow-sm"><i class="fas fa-clock me-1"></i> Pending Approval</span>
                        <?php elseif ($category_info['status'] == 'Results Rejected'): ?>
                             <span class="badge bg-danger fs-6 px-3 py-2 rounded-pill shadow-sm"><i class="fas fa-exclamation-triangle me-1"></i> Rejected</span>
                        <?php else: ?>
                            <span class="badge bg-primary fs-6 px-3 py-2 rounded-pill shadow-sm"><i class="fas fa-edit me-1"></i> Submission Mode</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($alert_message)): ?>
                <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show shadow-sm mb-4" role="alert">
                    <i class="fas fa-<?php echo $alert_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                    <?php echo htmlspecialchars($alert_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="submit_results.php?category_id=<?php echo $category_id; ?>">
                
                <input type="hidden" name="event_date" value="<?php echo htmlspecialchars($current_submission['event_date'] ?? ''); ?>">
                <input type="hidden" name="event_time" value="<?php echo htmlspecialchars($current_submission['event_time'] ?? ''); ?>">
                <input type="hidden" name="venue" value="<?php echo htmlspecialchars($current_submission['venue'] ?? ''); ?>">

                <div class="event-context-strip">
                    <div class="context-item">
                        <span class="context-label"><i class="fas fa-calendar me-1"></i> Date</span>
                        <span class="context-value">
                            <?php echo !empty($current_submission['event_date']) ? date('M d, Y', strtotime($current_submission['event_date'])) : '<span class="text-muted fw-normal">Not set</span>'; ?>
                        </span>
                    </div>
                    <div class="context-item">
                        <span class="context-label"><i class="fas fa-clock me-1"></i> Time</span>
                        <span class="context-value">
                            <?php echo !empty($current_submission['event_time']) ? date('g:i A', strtotime($current_submission['event_time'])) : '<span class="text-muted fw-normal">Not set</span>'; ?>
                        </span>
                    </div>
                    <div class="context-item">
                        <span class="context-label"><i class="fas fa-map-marker-alt me-1"></i> Venue</span>
                        <span class="context-value">
                            <?php echo !empty($current_submission['venue']) ? htmlspecialchars($current_submission['venue']) : '<span class="text-muted fw-normal">Not set</span>'; ?>
                        </span>
                    </div>
                </div>

                <div class="row g-4">
                    <?php 
                        $status_lower = strtolower($category_info['status']);
                        $show_results_card = (
                            $status_lower == 'ongoing' || 
                            $status_lower == 'completed (pending results)' || 
                            $status_lower == 'results submitted' || 
                            $status_lower == 'results rejected' ||
                            $status_lower == 'completed' 
                        );
                    ?>

                    <?php if ($category_info['category_type'] == 'medal' && $show_results_card): ?>
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-trophy me-2 text-warning"></i>Select Winners & Input Medal Counts</h5>
                            </div>
                            <div class="card-body p-4">
                                
                                <div class="medal-row gold-row row align-items-center g-3">
                                    <div class="col-md-3">
                                        <div class="medal-label text-gold"><i class="fas fa-medal"></i> GOLD</div>
                                    </div>
                                    <div class="col-md-7">
                                        <select class="form-select fw-bold" id="gold_winner_id" name="gold_winner_id" <?php if ($is_locked) echo 'disabled'; ?>>
                                            <option value="">-- Select Gold Winner --</option>
                                            <?php foreach ($teams as $team): ?>
                                                <option value="<?php echo $team['team_id']; ?>" 
                                                    <?php echo ($current_submission && $current_submission['gold_winner_college_id'] == $team['team_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($team['team_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" 
                                        class="form-control text-center fw-bold" 
                                        name="gold_count" 
                                        value="<?php echo $current_submission['gold_count'] ?? 0; ?>" 
                                        min="0" 
                                        onfocus="this.select()" 
                                        onblur="if(this.value===''){this.value='0'}" 
                                        <?php if ($is_locked) echo 'disabled'; ?>>
                                    </div>
                                </div>
                                
                                <div class="medal-row silver-row row align-items-center g-3">
                                    <div class="col-md-3">
                                        <div class="medal-label text-silver"><i class="fas fa-medal"></i> SILVER</div>
                                    </div>
                                    <div class="col-md-7">
                                        <select class="form-select fw-bold" id="silver_winner_id" name="silver_winner_id" <?php if ($is_locked) echo 'disabled'; ?>>
                                            <option value="">-- Select Silver Winner --</option>
                                            <?php foreach ($teams as $team): ?>
                                                <option value="<?php echo $team['team_id']; ?>" 
                                                    <?php echo ($current_submission && $current_submission['silver_winner_college_id'] == $team['team_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($team['team_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                            <input type="number" 
                                            class="form-control text-center fw-bold" 
                                            name="silver_count" 
                                            value="<?php echo $current_submission['silver_count'] ?? 0; ?>" 
                                            min="0" 
                                            onfocus="this.select()" 
                                            onblur="if(this.value===''){this.value='0'}" 
                                            <?php if ($is_locked) echo 'disabled'; ?>>
                                    </div>
                                </div>
                                
                                <div class="medal-row bronze-row row align-items-center g-3">
                                    <div class="col-md-3">
                                        <div class="medal-label text-bronze"><i class="fas fa-medal"></i> BRONZE</div>
                                    </div>
                                    <div class="col-md-7">
                                        <select class="form-select fw-bold" id="bronze_winner_id" name="bronze_winner_id" <?php if ($is_locked) echo 'disabled'; ?>>
                                            <option value="">-- Select Bronze Winner --</option>
                                            <?php foreach ($teams as $team): ?>
                                                <option value="<?php echo $team['team_id']; ?>" 
                                                    <?php echo ($current_submission && $current_submission['bronze_winner_college_id'] == $team['team_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($team['team_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" 
                                        class="form-control text-center fw-bold" 
                                        name="bronze_count" 
                                        value="<?php echo $current_submission['bronze_count'] ?? 0; ?>" 
                                        min="0" 
                                        onfocus="this.select()" 
                                        onblur="if(this.value===''){this.value='0'}" 
                                        <?php if ($is_locked) echo 'disabled'; ?>>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!$is_locked): ?>
                    <div class="col-12">
                        <div class="card shadow-sm border-0">
                            <div class="card-body p-4 d-flex justify-content-end gap-3 bg-white rounded">
                                <button type="submit" name="action" value="save_pending" class="btn btn-secondary px-4 fw-bold">
                                    <i class="fas fa-save me-2"></i> Save Draft
                                </button>
                                
                                <?php
                                $original_status_lower = strtolower($conn->query("SELECT status FROM categories WHERE category_id = $category_id")->fetch_column());
                                $can_submit = (
                                    $original_status_lower == 'ongoing' || 
                                    $original_status_lower == 'completed (pending results)' || 
                                    $original_status_lower == 'results submitted' || 
                                    $original_status_lower == 'results rejected'
                                );
                                ?>
                                <?php if ($category_info['category_type'] == 'medal' && $can_submit): ?>
                                <button type="submit" name="action" value="submit_for_approval" class="btn btn-success px-4 fw-bold shadow-sm" 
                                        onclick="return confirm('Are you sure you want to submit these results for final approval? This will notify the director.')">
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
    
    <footer class="bg-dark text-white py-4">
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small>
        </div>
    </footer>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const mobileToggle = document.getElementById('mobileToggle');
            if (mobileToggle) {
                mobileToggle.addEventListener('click', function() {
                    document.getElementById('sidebar').classList.toggle('show');
                });
            }
            
            const footer = document.querySelector('footer');
            const sidebar = document.getElementById('sidebar');
            if (sidebar && footer) {
                function adjustSidebarHeight() {
                    if (window.innerWidth <= 992) {
                        sidebar.style.height = ''; return;
                    }
                    const navbarHeight = 82; 
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