<?php
session_start();
require_once 'config.php'; 

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

$display_name = !empty($user_data['full_name']) ? $user_data['full_name'] : ($user_data['username'] ?? $username);

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
                                  c.category_type, c.tally_sheet_url, c.podium_photo_url
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

// --- NEW: FETCH HISTORY LOGS ---
$history_logs = [];
try {
    $sql_logs = "SELECT sl.*, u.full_name, u.username, u.role
                 FROM system_logs sl
                 LEFT JOIN users u ON sl.actor_user_id = u.id
                 WHERE sl.related_id = ? AND (sl.related_table = 'category' OR sl.related_table = 'categories')
                 ORDER BY sl.created_at DESC";
    $stmt_logs = $conn->prepare($sql_logs);
    $stmt_logs->bind_param("i", $category_id);
    $stmt_logs->execute();
    $history_logs = $stmt_logs->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_logs->close();
} catch (Exception $e) {}

// 3. FORM HANDLING
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($is_locked) {
        $alert_message = "Error: These results are already approved and locked.";
        $alert_type = 'danger';
    } else {
        try {
            $upload_dir = 'uploads/evidence/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

            // --- 3A. HANDLE FILE 1: TALLY SHEET ---
            $tally_sheet_url = $category_info['tally_sheet_url']; 
            if (isset($_FILES['tally_sheet']) && $_FILES['tally_sheet']['error'] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['tally_sheet']['tmp_name'];
                $file_ext = strtolower(pathinfo($_FILES['tally_sheet']['name'], PATHINFO_EXTENSION));
                if (!in_array($file_ext, ['jpg', 'jpeg', 'png'])) throw new Exception("Invalid Tally Sheet format. JPG/PNG only.");
                
                $new_file_name = "tally_" . $category_id . "_" . time() . "." . $file_ext;
                if (move_uploaded_file($file_tmp, $upload_dir . $new_file_name)) {
                    $tally_sheet_url = $upload_dir . $new_file_name;
                }
            }

            // --- 3B. HANDLE FILE 2: PODIUM PHOTO ---
            $podium_photo_url = $category_info['podium_photo_url']; 
            if (isset($_FILES['podium_photo']) && $_FILES['podium_photo']['error'] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['podium_photo']['tmp_name'];
                $file_ext = strtolower(pathinfo($_FILES['podium_photo']['name'], PATHINFO_EXTENSION));
                if (!in_array($file_ext, ['jpg', 'jpeg', 'png'])) throw new Exception("Invalid Podium Photo format. JPG/PNG only.");
                
                $new_file_name = "podium_" . $category_id . "_" . time() . "." . $file_ext;
                if (move_uploaded_file($file_tmp, $upload_dir . $new_file_name)) {
                    $podium_photo_url = $upload_dir . $new_file_name;
                }
            }

            // --- 3C. HANDLE INPUTS ---
            $event_date = !empty($_POST['event_date']) ? $_POST['event_date'] : null;
            $event_time = !empty($_POST['event_time']) ? $_POST['event_time'] : null;
            $venue = !empty($_POST['venue']) ? $_POST['venue'] : null;
            
            // Check Certification
            if ($action === 'submit_for_approval' && !isset($_POST['certification'])) {
                throw new Exception("You must certify the results before submitting.");
            }

            $gold_team = !empty($_POST['gold_winner_id']) ? (int)$_POST['gold_winner_id'] : null;
            $gold_count = !empty($_POST['gold_count']) ? (int)$_POST['gold_count'] : 0;
            $silver_team = !empty($_POST['silver_winner_id']) ? (int)$_POST['silver_winner_id'] : null;
            $silver_count = !empty($_POST['silver_count']) ? (int)$_POST['silver_count'] : 0;
            $bronze_team = !empty($_POST['bronze_winner_id']) ? (int)$_POST['bronze_winner_id'] : null;
            $bronze_count = !empty($_POST['bronze_count']) ? (int)$_POST['bronze_count'] : 0;

            // 1. General Check for Duplicate Teams (Happens for Draft & Submit)
            if ($category_info['category_type'] == 'medal') {
                $winners = array_filter([$gold_team, $silver_team, $bronze_team]);
                if (count($winners) !== count(array_unique($winners))) {
                    throw new Exception("The same team cannot win multiple medals.");
                }
            }

            $new_status = $category_info['status']; 
            
            // 2. Submission Specific Validations (YOUR FIX APPLIED HERE)
            if ($action === 'submit_for_approval') {
                $new_status = 'Results Submitted'; 
                
                if ($category_info['category_type'] == 'medal') {
                    // Check if teams are selected
                    if (empty($gold_team) || empty($silver_team) || empty($bronze_team)) {
                        throw new Exception("All medal winners must be selected.");
                    }

                    // Check if counts are valid
                    if ($gold_count <= 0 || $silver_count <= 0 || $bronze_count <= 0) {
                        throw new Exception("Medal counts cannot be zero. Please enter a valid value (minimum 1).");
                    }
                }
                
                if (empty($tally_sheet_url)) {
                    throw new Exception("You must upload the Official Tally Sheet as evidence.");
                }
            }

            // --- 3D. UPDATE DATABASE ---
            $stmt = $conn->prepare(
                "UPDATE categories SET 
                    event_date = ?, event_time = ?, venue = ?,
                    gold_winner_college_id = ?, gold_count = ?,
                    silver_winner_college_id = ?, silver_count = ?,
                    bronze_winner_college_id = ?, bronze_count = ?,
                    tally_sheet_url = ?, podium_photo_url = ?,
                    status = ?
                 WHERE category_id = ?"
            );
            
            $stmt->bind_param("sssiiiiiisssi", 
                $event_date, $event_time, $venue,
                $gold_team, $gold_count, 
                $silver_team, $silver_count, 
                $bronze_team, $bronze_count, 
                $tally_sheet_url, $podium_photo_url,
                $new_status, $category_id
            );
            $stmt->execute();
            $stmt->close();

            // --- 3E. POST-UPDATE ACTIONS ---
            if ($action === 'submit_for_approval') {
                try {
                    // Fetch names for logs
                    $log_gold = $log_silver = $log_bronze = 'N/A';
                    if($gold_team) { $q = $conn->query("SELECT college_name FROM colleges WHERE college_id = $gold_team"); if($q && $row = $q->fetch_assoc()) $log_gold = $row['college_name']; }
                    if($silver_team) { $q = $conn->query("SELECT college_name FROM colleges WHERE college_id = $silver_team"); if($q && $row = $q->fetch_assoc()) $log_silver = $row['college_name']; }
                    if($bronze_team) { $q = $conn->query("SELECT college_name FROM colleges WHERE college_id = $bronze_team"); if($q && $row = $q->fetch_assoc()) $log_bronze = $row['college_name']; }

                    $context = ['category_name' => $category_info['category_name'], 'status' => 'Submitted', 'gold' => $log_gold, 'silver' => $log_silver, 'bronze' => $log_bronze];
                    log_activity($conn, $user_id, 'SUBMITTED_RESULTS', $category_id, 'category', null, null, $context);
                } catch (Exception $log_e) {}

                // *** REDIRECT TO LIST (The Fix) ***
                $_SESSION['alert_message'] = "Results submitted successfully! Awaiting approval.";
                $_SESSION['alert_type'] = 'success';
                header("Location: my_events.php");
                exit();
            }
            
            // IF SAVING DRAFT, STAY HERE
            if ($action === 'save_pending') {
                $alert_message = "Draft saved successfully. You can continue editing.";
                $alert_type = 'success';
            }
        
        } catch (Exception $e) {
            $alert_message = "Error: " . $e->getMessage();
            $alert_type = 'danger';
        }
    } 

    // Refresh Data for view
    $updated_cat = $conn->query("SELECT status, tally_sheet_url, podium_photo_url FROM categories WHERE category_id = $category_id")->fetch_assoc();
    if ($updated_cat) {
        $category_info['status'] = ($updated_cat['status'] == 'Results Approved') ? 'Completed' : $updated_cat['status'];
        $category_info['tally_sheet_url'] = $updated_cat['tally_sheet_url'];
        $category_info['podium_photo_url'] = $updated_cat['podium_photo_url']; 
        $is_locked = (strtolower($category_info['status']) == 'completed');
    }
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
    /* =========================================
       1. CORE VARIABLES (SaaS Palette)
       ========================================= */
    :root { 
        --sidebar-width: 260px; 
        --header-height: 82px; 
        
        /* Clean Color Palette */
        --bg-light: #f4f6f8; 
        --text-dark: #1e293b;
        --text-muted: #64748b;
        --accent-color: #1abc9c;
        
        /* Medal Colors */
        --gold: #f59e0b; 
        --silver: #64748b; 
        --bronze: #ea580c;
        
        --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.02);
    }

    body { 
        background-color: var(--bg-light); /* Clean Gray - No Gradient */
        margin: 0; 
        padding: 0; 
        min-height: 100vh; 
        font-family: 'Inter', sans-serif; 
        display: flex; 
        flex-direction: column; 
        color: var(--text-dark);
    }

    /* REMOVED body::before (Glassmorphism Blobs) */

    /* =========================================
       2. NAVIGATION (Solid & Professional)
       ========================================= */
    
    /* Navbar */
    .navbar { 
        background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; 
        box-shadow: 0 4px 20px rgba(0,0,0,0.15); 
        padding: 1rem 1.5rem; 
        height: var(--header-height); 
        position: fixed; 
        top: 0; left: 0; right: 0; 
        z-index: 1050; 
        border-bottom: none; 
    }

    .user-dropdown .dropdown-toggle { 
        color: white; 
        display: flex; align-items: center; 
        text-decoration: none; 
        padding: 8px 12px; 
        border-radius: 8px; 
        background: transparent; /* Removed glass background */
        border: none;
        transition: var(--transition);
    }
    .user-dropdown .dropdown-toggle:hover { 
        background-color: rgba(255, 255, 255, 0.1); 
    }
    .user-dropdown .dropdown-toggle img { 
        width: 36px; height: 36px; 
        border-radius: 50%; object-fit: cover; margin-right: 10px; 
        border: 2px solid rgba(255,255,255,0.2);
    }

    /* Sidebar */
    .sidebar { 
        width: var(--sidebar-width); 
        position: fixed; 
        top: var(--header-height); 
        left: 0; 
        height: calc(100vh - var(--header-height)); 
        background: #2c3e50; /* Solid Dark Blue */
        color: white; 
        box-shadow: 5px 0 15px rgba(0,0,0,0.05); 
        z-index: 1040; 
        transition: width 0.3s ease; 
        overflow-y: auto; 
    }

    .sidebar-nav { padding: 20px 0; }
    .sidebar-nav .nav-link { 
        color: rgba(255, 255, 255, 0.7); 
        font-size: 1.05rem; 
        padding: 12px 25px; 
        display: flex; align-items: center; 
        text-decoration: none; 
        border-left: 5px solid transparent; 
        transition: var(--transition);
    }
    .sidebar-nav .nav-link:hover { 
        background: rgba(255, 255, 255, 0.05); 
        color: white; 
    }
    .sidebar-nav .nav-link.active { 
        background: rgba(255, 255, 255, 0.1); 
        border-left-color: #3498db; 
        color: white; 
        font-weight: 600; 
    }

    /* Main Content */
    .main-content { 
        margin-left: var(--sidebar-width); 
        width: calc(100% - var(--sidebar-width)); 
        padding: 30px; 
        margin-top: var(--header-height); 
        z-index: 1; 
    }

    /* Footer - Full Width Fix */
    footer { 
        margin-left: 0 !important;  /* Remove the indentation */
        width: 100% !important;     /* Force full width */
        background: #2c3e50 !important; 
        z-index: 1100;              /* Ensure it sits on top of the gap */
        border-top: none; 
        padding: 20px 0;
        position: relative;
    }

    /* =========================================
       3. PAGE CARDS & HEADERS
       ========================================= */

    /* Page Header Card */
    .page-header { 
        background: white; 
        border-radius: 16px; 
        padding: 24px 30px; 
        margin-bottom: 30px; 
        box-shadow: var(--card-shadow); 
        border: 1px solid rgba(0,0,0,0.05); 
        position: relative;
        /* Removed gradient ::before */
    }
    .page-header::before { display: none; }

    .page-title { 
        font-family: 'Inter', sans-serif; 
        font-weight: 800; 
        font-size: 1.75rem; 
        color: var(--text-dark); 
        margin: 0; 
    }

    /* General Content Cards */
    .card { 
        background: white; 
        border: 1px solid #e2e8f0; 
        border-radius: 12px; 
        box-shadow: var(--card-shadow); 
        overflow: hidden; 
        margin-bottom: 1.5rem; 
    }
    .card-header { 
        background: white; 
        border-bottom: 1px solid #f1f5f9; 
        padding: 1.25rem 1.5rem; 
    }
    .card-header h5 {
        font-weight: 700;
        color: #334155;
        margin: 0;
    }

    /* =========================================
       4. MEDAL INPUT ROWS (Clean Style)
       ========================================= */
    .medal-row { 
        padding: 1.25rem; 
        border-radius: 10px; 
        margin-bottom: 1rem; 
        border: 1px solid #e2e8f0; 
        transition: all 0.2s ease; 
        background: white;
        display: flex;
        align-items: center;
    }
    .medal-row:hover { 
        box-shadow: 0 4px 12px rgba(0,0,0,0.05); 
        transform: translateY(-2px); 
    }

    /* Colored Left Borders */
    .medal-row.gold-row { border-left: 5px solid var(--gold); }
    .medal-row.silver-row { border-left: 5px solid var(--silver); }
    .medal-row.bronze-row { border-left: 5px solid var(--bronze); }

    /* Typography */
    .medal-label { font-weight: 700; font-size: 0.9rem; width: 100px; }
    .text-gold { color: var(--gold); } 
    .text-silver { color: var(--silver); } 
    .text-bronze { color: var(--bronze); }

    /* =========================================
       5. FORMS & UPLOAD
       ========================================= */
    .upload-zone { 
        border: 2px dashed #cbd5e1; 
        border-radius: 12px; 
        padding: 2rem; 
        text-align: center; 
        transition: all 0.2s; 
        background: #f8fafc; 
        cursor: pointer;
    }
    .upload-zone:hover { 
        border-color: #94a3b8; 
        background: #f1f5f9; 
    }

    .form-control, .form-select {
        border-color: #e2e8f0;
        border-radius: 8px;
        padding: 0.6rem 1rem;
    }
    .form-control:focus, .form-select:focus { 
        border-color: var(--accent-color); 
        box-shadow: 0 0 0 3px rgba(26, 188, 156, 0.15); 
    }

    /* =========================================
       6. TIMELINE & RESPONSIVE
       ========================================= */
    .timeline { position: relative; padding-left: 10px; }
    .timeline-item { 
        position: relative; 
        padding-bottom: 1.5rem; 
        border-left: 2px solid #e9ecef; 
        padding-left: 25px; 
    }
    .timeline-item:last-child { border-left: 2px solid transparent; }
    .timeline-item::before { 
        content: ''; 
        position: absolute; 
        left: -6px; top: 5px; 
        width: 10px; height: 10px; 
        border-radius: 50%; 
        background: white; 
        border: 2px solid #cbd5e1; 
    }
    
    /* Timeline Dots */
    .timeline-item.success::before { border-color: #198754; background: #198754; }
    .timeline-item.danger::before { border-color: #dc3545; background: #dc3545; }
    .timeline-item.primary::before { border-color: #0d6efd; background: #0d6efd; }
    
    .timeline-date { font-size: 0.75rem; color: var(--text-muted); margin-bottom: 2px; }
    .timeline-content { font-size: 0.9rem; color: var(--text-dark); }

    @media (max-width: 992px) { 
        .sidebar { width: 0; } 
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
            <button class="navbar-toggler d-lg-none" type="button" id="mobileToggle"><span class="navbar-toggler-icon"></span></button>
            <div class="dropdown user-dropdown ms-auto me-2 me-lg-0">
                <a href="#" class="dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle" style="font-size: 36px; margin-right: 10px;"></i>
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($display_name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="admin_profile.php">Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="sidebar" id="sidebar">
        <ul class="nav flex-column sidebar-nav">
            <li class="nav-item"><a class="nav-link" href="my_events.php"><i class="fas fa-chevron-left me-2"></i><span>Back to My Events</span></a></li>
            <li class="nav-item"><a class="nav-link active" href="javascript:void(0);"><i class="fas fa-edit me-2"></i><span>Submit Results</span></a></li>
            <li class="nav-item mt-auto"><a class="nav-link text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i><span>Logout</span></a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="container-fluid">
            
            <div class="page-header">
                <nav aria-label="breadcrumb" class="mb-3">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="event_manager_dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="my_events.php">My Events</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Submit Results</li>
                    </ol>
                </nav>
                <div class="d-flex justify-content-between align-items-end"> <div>
        <div class="text-muted small mb-1 text-uppercase fw-bold" style="letter-spacing: 1px; font-size: 0.7rem;">
            <?php echo htmlspecialchars($category_info['game_name']); ?>
        </div>
        
        <h1 class="page-title display-6 fw-bold text-dark mb-2" style="letter-spacing: -0.5px;">
            <?php echo htmlspecialchars($category_info['event_name']); ?>
        </h1>

        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-white text-dark border px-3 py-2 rounded-pill shadow-sm fw-bold">
                <i class="fas fa-tags text-muted me-2"></i>
                <?php echo htmlspecialchars($category_info['category_name']); ?>
            </span>
        </div>
    </div>
                        <?php if ($is_locked): ?>
                            <span class="badge bg-success fs-6 px-3 py-2 rounded-pill"><i class="fas fa-check-circle me-1"></i> Approved & Locked</span>
                        <?php else: ?>
                            <span class="badge bg-primary fs-6 px-3 py-2 rounded-pill"><i class="fas fa-edit me-1"></i> Submission Mode</span>
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
            
            <form method="POST" action="submit_results.php?category_id=<?php echo $category_id; ?>" enctype="multipart/form-data">
                
                <input type="hidden" name="event_date" value="<?php echo htmlspecialchars($current_submission['event_date'] ?? ''); ?>">
                <input type="hidden" name="event_time" value="<?php echo htmlspecialchars($current_submission['event_time'] ?? ''); ?>">
                <input type="hidden" name="venue" value="<?php echo htmlspecialchars($current_submission['venue'] ?? ''); ?>">

                <div class="row g-4">
                    
                    <div class="col-lg-7">
                        <div class="card h-100">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-trophy me-2 text-warning"></i>Select Winners</h5>
                            </div>
                            <div class="card-body p-4">
                                <div class="medal-row gold-row row align-items-center g-3">
                                    <div class="col-md-3"><div class="medal-label text-gold"><i class="fas fa-medal"></i> GOLD</div></div>
                                    <div class="col-md-7">
                                        <select class="form-select fw-bold" name="gold_winner_id" <?php if ($is_locked) echo 'disabled'; ?>>
                                            <option value="">-- Select Winner --</option>
                                            <?php foreach ($teams as $team): ?>
                                                <option value="<?php echo $team['team_id']; ?>" <?php echo ($current_submission && $current_submission['gold_winner_college_id'] == $team['team_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($team['team_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" class="form-control text-center fw-bold" name="gold_count" value="<?php echo $current_submission['gold_count'] ?? 0; ?>" min="0" <?php if ($is_locked) echo 'disabled'; ?>>
                                    </div>
                                </div>
                                <div class="medal-row silver-row row align-items-center g-3">
                                    <div class="col-md-3"><div class="medal-label text-silver"><i class="fas fa-medal"></i> SILVER</div></div>
                                    <div class="col-md-7">
                                        <select class="form-select fw-bold" name="silver_winner_id" <?php if ($is_locked) echo 'disabled'; ?>>
                                            <option value="">-- Select Winner --</option>
                                            <?php foreach ($teams as $team): ?>
                                                <option value="<?php echo $team['team_id']; ?>" <?php echo ($current_submission && $current_submission['silver_winner_college_id'] == $team['team_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($team['team_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" class="form-control text-center fw-bold" name="silver_count" value="<?php echo $current_submission['silver_count'] ?? 0; ?>" min="0" <?php if ($is_locked) echo 'disabled'; ?>>
                                    </div>
                                </div>
                                <div class="medal-row bronze-row row align-items-center g-3">
                                    <div class="col-md-3"><div class="medal-label text-bronze"><i class="fas fa-medal"></i> BRONZE</div></div>
                                    <div class="col-md-7">
                                        <select class="form-select fw-bold" name="bronze_winner_id" <?php if ($is_locked) echo 'disabled'; ?>>
                                            <option value="">-- Select Winner --</option>
                                            <?php foreach ($teams as $team): ?>
                                                <option value="<?php echo $team['team_id']; ?>" <?php echo ($current_submission && $current_submission['bronze_winner_college_id'] == $team['team_id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($team['team_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" class="form-control text-center fw-bold" name="bronze_count" value="<?php echo $current_submission['bronze_count'] ?? 0; ?>" min="0" <?php if ($is_locked) echo 'disabled'; ?>>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        
                        <div class="card mb-4">
                            <div class="card-header bg-white">
                                <h5 class="mb-0 text-primary"><i class="fas fa-file-contract me-2"></i>Verification & Evidence</h5>
                            </div>
                            <div class="card-body p-4">
                                
                                <div class="mb-4">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Official Tally Sheet <span class="text-danger">*</span></label>
                                    <div class="upload-zone position-relative">
                                        <?php if (!empty($category_info['tally_sheet_url'])): ?>
                                            <div class="text-center mb-2">
                                                <img src="<?php echo htmlspecialchars($category_info['tally_sheet_url']); ?>" class="img-thumbnail shadow-sm mb-2" style="max-height: 120px;">
                                                <div class="text-success fw-bold small"><i class="fas fa-check-circle me-1"></i> Uploaded</div>
                                            </div>
                                            <p class="small text-muted mb-2">Change file:</p>
                                        <?php else: ?>
                                            <i class="fas fa-cloud-upload-alt fa-2x text-secondary mb-2"></i>
                                            <p class="small text-muted mb-2">Upload signed score sheet (JPG/PNG)</p>
                                        <?php endif; ?>
                                        <input class="form-control form-control-sm" type="file" name="tally_sheet" accept="image/*" 
                                            <?php if ($is_locked) echo 'disabled'; ?>
                                            <?php if (empty($category_info['tally_sheet_url'])) echo 'required'; ?>>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Victory / Action Photo (Optional)</label>
                                    <div class="upload-zone position-relative">
                                        <?php if (!empty($category_info['podium_photo_url'])): ?>
                                            <div class="text-center mb-2">
                                                <img src="<?php echo htmlspecialchars($category_info['podium_photo_url']); ?>" class="img-thumbnail shadow-sm mb-2" style="max-height: 120px;">
                                                <div class="text-success fw-bold small"><i class="fas fa-check-circle me-1"></i> Uploaded</div>
                                            </div>
                                            <p class="small text-muted mb-2">Change photo:</p>
                                        <?php else: ?>
                                            <i class="fas fa-camera fa-2x text-secondary mb-2"></i>
                                            <p class="small text-muted mb-2">Upload a winning moment or team photo</p>
                                        <?php endif; ?>
                                        <input class="form-control form-control-sm" type="file" name="podium_photo" accept="image/*" <?php if ($is_locked) echo 'disabled'; ?>>
                                    </div>
                                </div>

                                <div class="alert alert-light border">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="certification" id="certCheck" <?php if ($is_locked) echo 'checked disabled'; ?> required>
                                        <label class="form-check-label small" for="certCheck">
                                            I, <strong><?php echo htmlspecialchars($display_name); ?></strong>, certify that these results are final and accurate.
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="mb-0 text-secondary"><i class="fas fa-history me-2"></i>Submission History</h5>
                            </div>
                            <div class="card-body p-4" style="max-height: 300px; overflow-y: auto;">
                                <?php if (empty($history_logs)): ?>
                                    <div class="text-center text-muted small py-3">
                                        <i class="fas fa-clock fa-2x mb-2 opacity-25"></i><br>No history logs available.
                                    </div>
                                <?php else: ?>
                                    <div class="timeline">
                                        <?php foreach ($history_logs as $log): 
                                            $action = trim($log['action_type']);
                                            $actor = htmlspecialchars($log['full_name'] ?? $log['username'] ?? 'System');
                                            $date = date('M d, Y h:i A', strtotime($log['created_at']));
                                            $msg = "";
                                            $class = "secondary";

                                            if ($action === 'SUBMITTED_RESULTS') {
                                                $msg = "<strong>$actor</strong> submitted results for approval.";
                                                $class = "success";
                                            } elseif ($action === 'APPROVED_RESULT') {
                                                $msg = "<strong>$actor</strong> (Director) approved the results.";
                                                $class = "success";
                                            } elseif ($action === 'REJECTED_RESULT') {
                                                $msg = "<strong>$actor</strong> (Director) rejected the results.";
                                                $class = "danger";
                                            } elseif ($action === 'REVOKED_RESULT') {
                                                $msg = "<strong>$actor</strong> revoked the approval.";
                                                $class = "warning";
                                            } elseif ($action === 'CREATED_CATEGORY') {
                                                $msg = "<strong>$actor</strong> initialized this event.";
                                                $class = "primary"; 
                                            } elseif ($action === 'UPDATED_CATEGORY') {
                                                $msg = "<strong>$actor</strong> updated event details.";
                                                $class = "info"; 
                                            } elseif ($action === 'DELETED_CATEGORY') {
                                                $msg = "<strong>$actor</strong> deleted a category.";
                                                $class = "danger";
                                            } else {
                                                $clean_action = ucwords(strtolower(str_replace('_', ' ', $action)));
                                                $msg = "<strong>$actor</strong> - $clean_action";
                                            }
                                        ?>
                                        <div class="timeline-item <?= $class ?>">
                                            <div class="timeline-date"><?= $date ?></div>
                                            <div class="timeline-content"><?= $msg ?></div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>

                    
                    
                </div>
                <?php if (!$is_locked): ?>
        <div class="sticky-bottom bg-white border-top py-3 shadow-lg mt-4" style="z-index: 999;">
            <div class="d-flex justify-content-between align-items-center px-3">
                
                <div class="text-muted small">
                    <i class="fas fa-info-circle me-1"></i> Changes are not final until submitted.
                </div>

                <div class="d-flex gap-3">
                    <button type="submit" name="action" value="save_pending" class="btn btn-light border fw-bold px-4 rounded-pill">
                        Save Draft
                    </button>
                    <button type="submit" name="action" value="submit_for_approval" class="btn btn-primary fw-bold px-4 rounded-pill shadow-sm"
                            onclick="return confirm('Ensure the Tally Sheet is uploaded. Continue?')">
                        Submit Results <i class="fas fa-arrow-right ms-2"></i>
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>
            </form>
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
            
            // --- 1. Sidebar Toggle ---
            const mobileToggle = document.getElementById('mobileToggle');
            if (mobileToggle) {
                mobileToggle.addEventListener('click', function() {
                    document.getElementById('sidebar').classList.toggle('show');
                });
            }
            
            // --- 2. Footer/Sidebar Adjustment ---
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

            // --- 3. [NEW] AUTO-SELECT NUMBER INPUTS ---
            // This fixes the issue: clicking the box highlights the number so typing replaces it instantly.
            const numberInputs = document.querySelectorAll('input[type="number"]');
            numberInputs.forEach(input => {
                // Select text on focus (tabbing in)
                input.addEventListener('focus', function() {
                    this.select();
                });
                // Select text on click
                input.addEventListener('click', function() {
                    this.select();
                });
            });
        });
    </script>
</body>
</html>