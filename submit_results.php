<?php
session_start();
require_once 'config.php'; 

// 1. SECURITY & ACCESS CONTROL
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Tournament Manager') {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Tournament Manager';
$current_page = 'submit_results.php'; 
$alert_message = '';
$alert_type = 'success';

// --- FETCH FULL NAME ---
// --- FETCH NAME & PROFILE PICTURE LOGIC ---
$stmt_name = $conn->prepare("SELECT full_name, username, profile_picture FROM users WHERE id = ?");
$stmt_name->bind_param("i", $user_id); // <--- FIXED: Must be $user_id
$stmt_name->execute();
$result_name = $stmt_name->get_result();
$user_data = $result_name->fetch_assoc();
$stmt_name->close();

// Define the profile picture path (NO ../ because this file is in the root folder)
$profile_pic_path = '';
if (!empty($user_data['profile_picture'])) {
    $profile_pic_path = $user_data['profile_picture']; 
}

if (!empty($user_data['full_name'])) {
    $display_name = $user_data['full_name'];
} else {
    $display_name = $user_data['username'] ?? $username; 
}

// 2. GET CATEGORY ID & VERIFY PERMISSION
if (!isset($_GET['category_id'])) {
    header('Location: tournamentmanager_dashboard.php');
    exit();
}
$category_id = (int)$_GET['category_id'];

try {
    $stmt_check = $conn->prepare("SELECT g.game_name, ge.event_name, ge.fixed_medal_count, c.category_name, c.division_name,
                                  CASE 
                                      WHEN c.status = 'Results Approved' THEN 'Completed' 
                                      ELSE c.status 
                                  END AS status,
                                    c.tally_sheet_url, c.podium_photo_url
                                  FROM categories c
                                  JOIN game_events ge ON c.event_id = ge.event_id
                                  JOIN games g ON ge.game_id = g.game_id
                                  JOIN tournament_manager_assignments ema ON ge.event_id = ema.event_id
                                  WHERE c.category_id = ? AND ema.user_id = ?");
    $stmt_check->bind_param("ii", $category_id, $user_id);
    $stmt_check->execute();
    $result = $stmt_check->get_result();
    if ($result->num_rows == 0) {
        $_SESSION['alert_message'] = "Permission denied or event not found.";
        $_SESSION['alert_type'] = 'danger';
        header('Location: tournamentmanager_dashboard.php');
        exit();
    }
    $category_info = $result->fetch_assoc();
    $stmt_check->close();

    // This is the variable we will use for logging
    $parent_event_name = $category_info['event_name'];

    // NEW: Store the fixed count (null if it doesn't exist)
    $fixed_count = $category_info['fixed_medal_count'];

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
            $silver_team = !empty($_POST['silver_winner_id']) ? (int)$_POST['silver_winner_id'] : null;
            $bronze_team = !empty($_POST['bronze_winner_id']) ? (int)$_POST['bronze_winner_id'] : null;

            // SMART LOGIC: If a fixed count exists, force it! Otherwise, use the typed input.
            if ($fixed_count !== null) {
                // If team is selected, give them the fixed amount. If not selected, give 0.
                $gold_count = $gold_team ? $fixed_count : 0;
                $silver_count = $silver_team ? $fixed_count : 0;
                $bronze_count = $bronze_team ? $fixed_count : 0;
            } else {
                $gold_count = !empty($_POST['gold_count']) ? (int)$_POST['gold_count'] : 0;
                $silver_count = !empty($_POST['silver_count']) ? (int)$_POST['silver_count'] : 0;
                $bronze_count = !empty($_POST['bronze_count']) ? (int)$_POST['bronze_count'] : 0;
            }

            $new_status = $category_info['status']; 
            
            // 2. Submission Specific Validations (YOUR FIX APPLIED HERE)
            if ($action === 'submit_for_approval') {
                $new_status = 'Results Submitted'; 
                
                // Check if teams are selected
                if (empty($gold_team) || empty($silver_team) || empty($bronze_team)) {
                    throw new Exception("All medal winners must be selected.");
                }

                // Check if counts are valid
                if ($gold_count <= 0 || $silver_count <= 0 || $bronze_count <= 0) {
                    throw new Exception("Medal counts cannot be zero. Please enter a valid value (minimum 1).");
                }
                
                // Check for evidence
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

                    // --- UPDATED: Smart Context for Submission ---
                    $context = [
                        'parent_event_name' => $parent_event_name, // Added
                        'category_name'     => $category_info['category_name'],
                        'division_name'     => $category_info['division_name'], // Added
                        'status'            => 'Submitted', 
                        'gold'              => $log_gold, 
                        'silver'            => $log_silver, 
                        'bronze'            => $log_bronze
                    ];
                    
                    // We also add the Event ID (null replaced with $category_info['event_id']) 
                    // Use the event_id from category_info to link the log to the parent event
                    log_activity($conn, $user_id, 'SUBMITTED_RESULTS', $category_id, 'category', $category_info['event_id'], 'event', $context);
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
        .main-content { flex: 1 0 auto; padding: 30px; margin-top: var(--header-height); margin-left: var(--sidebar-width); transition: margin-left var(--transition); min-height: calc(100vh - var(--header-height)); }
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        
        /* Footer */
        footer {
            flex-shrink: 0;
            /* REMOVED background color here so .footer-main can work */
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            padding-left: var(--sidebar-width);
            transition: padding-left var(--transition);
            position: relative;
            z-index: 1041;
        }
        @media (max-width: 992px) {
            .sidebar { left: -260px; }
            .sidebar.show { left: 0; }
            .main-content, footer { margin-left: 0; }
        }

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
    /* =========================================
   6. GLASSMORPHISM CARDS
   ========================================= */
.card { 
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 20px; 
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
    overflow: hidden; 
    margin-bottom: 1.5rem;
    transition: all 0.3s;
}

.card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 48px rgba(0, 0, 0, 0.12);
}

.card-header { 
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
    border-bottom: 2px solid rgba(102, 126, 234, 0.1);
    padding: 1.5rem; 
}

.card-header h5 {
    font-family: 'Poppins', sans-serif;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.card-header h5 i {
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-size: 0.9rem;
}

    /* =========================================
       4. PREMIUM MEDAL CARDS (From Image Design)
       ========================================= */
    .medal-card {
        background: #ffffff;
        border-radius: 16px;
        padding: 1.5rem;
        margin-bottom: 1.25rem;
        border: 2px solid transparent;
        transition: all 0.3s ease;
        position: relative;
    }
    
    /* Specific Card Borders & Shadows */
    .medal-card.gold-card { border-color: #fef08a; box-shadow: 0 10px 25px rgba(250, 204, 21, 0.08); }
    .medal-card.silver-card { border-color: #e2e8f0; box-shadow: 0 10px 25px rgba(148, 163, 184, 0.08); }
    .medal-card.bronze-card { border-color: #ffedd5; box-shadow: 0 10px 25px rgba(249, 115, 22, 0.08); }

    /* Left Side Icon Boxes */
    .medal-icon-box {
        width: 70px; height: 70px;
        border-radius: 16px;
        display: flex; align-items: center; justify-content: center;
        font-size: 2rem;
    }
    .gold-icon { background: #fef9c3; color: #eab308; }
    .silver-icon { background: #f1f5f9; color: #94a3b8; }
    .bronze-icon { background: #ffedd5; color: #ea580c; }

    /* Top Right Pill Badges */
    .place-badge {
        padding: 4px 12px; 
        border-radius: 20px; 
        font-size: 0.65rem; 
        font-weight: 800; 
        letter-spacing: 1px;
    }
    .gold-badge { background: #fef9c3; color: #eab308; }
    .silver-badge { background: #f1f5f9; color: #94a3b8; }
    .bronze-badge { background: #ffedd5; color: #ea580c; }
    
    /* Tiny Labels above inputs */
    .medal-input-group label {
        font-size: 0.65rem;
        font-weight: 800;
        color: #94a3b8;
        letter-spacing: 1px;
        text-transform: uppercase;
        margin-bottom: 6px;
        display: block;
    }
    
    /* Input Styling overrides */
    .medal-card .form-select, .medal-card .form-control {
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 0.75rem 1rem;
        color: #1e293b;
    }
    .medal-card .form-select:focus, .medal-card .form-control:focus {
        border-color: #94a3b8;
        box-shadow: none;
    }

/* =========================================
   MEDAL ICONS
   ========================================= */
.medal-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    position: relative;
    z-index: 1;
    transition: transform 0.3s;
}


.medal-row.gold-row .medal-icon { 
    background: linear-gradient(135deg, var(--gold) 0%, #d97706 100%);
    box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);
}
.medal-row.silver-row .medal-icon { 
    background: linear-gradient(135deg, var(--silver) 0%, #475569 100%);
    box-shadow: 0 4px 15px rgba(100, 116, 139, 0.4);
}
.medal-row.bronze-row .medal-icon { 
    background: linear-gradient(135deg, var(--bronze) 0%, #c2410c 100%);
    box-shadow: 0 4px 15px rgba(234, 88, 12, 0.4);
}

/* Typography */
.medal-label { 
    font-weight: 800; 
    font-size: 0.95rem; 
    letter-spacing: 1px;
    position: relative;
    z-index: 1;
}

.text-gold { color: var(--gold); text-shadow: 0 2px 10px var(--gold-glow); } 
.text-silver { color: var(--silver); text-shadow: 0 2px 10px var(--silver-glow); } 
.text-bronze { color: var(--bronze); text-shadow: 0 2px 10px var(--bronze-glow); }

/* Enhanced Select & Input */
.medal-row select,
.medal-row input {
    position: relative;
    z-index: 1;
    border: 2px solid #e2e8f0;
    transition: all 0.3s;
}

.medal-row select:focus,
.medal-row input:focus {
    border-color: var(--accent-primary);
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
    transform: scale(1.02);
}

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
                <img src="images/PIT.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white brand-heading" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light" style="font-size: 0.75rem;">Tournament Manager Panel</small>
                </div>
            </a>
            <button class="navbar-toggler d-lg-none" type="button" id="mobileToggle"><span class="navbar-toggler-icon"></span></button>
            <div class="dropdown user-dropdown ms-auto me-2 me-lg-0">
                <a href="#" class="dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                
                    <?php if (!empty($profile_pic_path)): ?>
                        <img src="<?= htmlspecialchars($profile_pic_path) ?>" alt="Profile" 
                             style="width: 42px; height: 42px; border-radius: 50%; object-fit: cover; margin-right: 10px; border: 2px solid rgba(255,255,255,0.2);"
                             onerror="this.onerror=null; this.outerHTML='<i class=\'fas fa-user-circle\' style=\'font-size:36px;margin-right:10px;\'></i>';">
                    <?php else: ?>
                        <i class="fas fa-user-circle" style="font-size:36px;margin-right:10px;"></i>
                    <?php endif; ?>
                    
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

                        <div class="d-flex align-items-center mt-1 text-secondary fs-5">
                            <i class="fas fa-tags opacity-50 me-2 fs-6"></i>
                            <span class="fw-medium text-dark">
                                <?php echo htmlspecialchars($category_info['category_name']); ?>
                            </span>
                            
                            <?php if (!empty($category_info['division_name'])): ?>
                                <i class="fas fa-chevron-right opacity-25 mx-2" style="font-size: 0.8rem;"></i>
                                <span class="text-muted">
                                    <?php echo htmlspecialchars($category_info['division_name']); ?>
                                </span>
                            <?php endif; ?>
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
                        <div class="card shadow-sm border-0 mb-4">
                            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-trophy me-2 text-warning"></i>Select Winners</h5>
                                <span class="fw-bold text-muted small text-uppercase" style="letter-spacing: 1px;">Medal Counts</span>
                            </div>
                            <div class="card-body p-4">
                                
                                <!-- GOLD MEDAL -->
                                <div class="medal-card gold-card">
                                    <div class="d-flex gap-4">
                                        <div class="flex-shrink-0">
                                            <div class="medal-icon-box gold-icon"><i class="fas fa-award"></i></div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                <div>
                                                    <h5 class="fw-bold mb-1 text-dark" style="font-size: 1.1rem;">Gold Medalist <span class="text-danger">*</span></h5>
                                                    <p class="text-muted small mb-0">Select the overall tournament champion</p>
                                                </div>
                                                <div class="place-badge gold-badge"><i class="fas fa-star me-1"></i> 1ST PLACE</div>
                                            </div>
                                            <div class="row g-3 align-items-end">
                                                <div class="col-md-9">
                                                    <select class="form-select fw-medium" name="gold_winner_id" required <?php if ($is_locked) echo 'disabled'; ?>>
                                                        <option value="" class="text-muted">Select participating team...</option>
                                                        <?php foreach ($teams as $team): ?>
                                                            <option value="<?php echo $team['team_id']; ?>" <?php echo ($current_submission && $current_submission['gold_winner_college_id'] == $team['team_id']) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($team['team_name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="medal-input-group">
                                                        <label>Medal Count <span class="text-danger">*</span></label>
                                                        <?php if ($fixed_count !== null): ?>
                                                            <div class="form-control text-center fw-bold bg-light text-muted d-flex justify-content-center align-items-center gap-2">
                                                                <?php echo $fixed_count; ?> <i class="fas fa-lock small"></i>
                                                            </div>
                                                        <?php else: ?>
                                                            <input type="number" class="form-control text-center fw-bold" name="gold_count" value="<?php echo $current_submission['gold_count'] ?? 0; ?>" min="0" placeholder="0" required <?php if ($is_locked) echo 'disabled'; ?>>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- SILVER MEDAL -->
                                <div class="medal-card silver-card">
                                    <div class="d-flex gap-4">
                                        <div class="flex-shrink-0">
                                            <div class="medal-icon-box silver-icon"><i class="fas fa-award"></i></div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                <div>
                                                    <h5 class="fw-bold mb-1 text-dark" style="font-size: 1.1rem;">Silver Medalist <span class="text-danger">*</span></h5>
                                                    <p class="text-muted small mb-0">Select the tournament runner-up</p>
                                                </div>
                                                <div class="place-badge silver-badge">2ND PLACE</div>
                                            </div>
                                            <div class="row g-3 align-items-end">
                                                <div class="col-md-9">
                                                    <select class="form-select fw-medium" name="silver_winner_id" required <?php if ($is_locked) echo 'disabled'; ?>>
                                                        <option value="" class="text-muted">Select participating team...</option>
                                                        <?php foreach ($teams as $team): ?>
                                                            <option value="<?php echo $team['team_id']; ?>" <?php echo ($current_submission && $current_submission['silver_winner_college_id'] == $team['team_id']) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($team['team_name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="medal-input-group">
                                                        <label>Medal Count <span class="text-danger">*</span></label>
                                                        <?php if ($fixed_count !== null): ?>
                                                            <div class="form-control text-center fw-bold bg-light text-muted d-flex justify-content-center align-items-center gap-2">
                                                                <?php echo $fixed_count; ?> <i class="fas fa-lock small"></i>
                                                            </div>
                                                        <?php else: ?>
                                                            <input type="number" class="form-control text-center fw-bold" name="silver_count" value="<?php echo $current_submission['silver_count'] ?? 0; ?>" min="0" placeholder="0" required <?php if ($is_locked) echo 'disabled'; ?>>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- BRONZE MEDAL -->
                                <div class="medal-card bronze-card">
                                    <div class="d-flex gap-4">
                                        <div class="flex-shrink-0">
                                            <div class="medal-icon-box bronze-icon"><i class="fas fa-award"></i></div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                <div>
                                                    <h5 class="fw-bold mb-1 text-dark" style="font-size: 1.1rem;">Bronze Medalist <span class="text-danger">*</span></h5>
                                                    <p class="text-muted small mb-0">Select the third-place finisher</p>
                                                </div>
                                                <div class="place-badge bronze-badge"><i class="fas fa-lock me-1 opacity-50"></i> 3RD PLACE</div>
                                            </div>
                                            <div class="row g-3 align-items-end">
                                                <div class="col-md-9">
                                                    <select class="form-select fw-medium" name="bronze_winner_id" required <?php if ($is_locked) echo 'disabled'; ?>>
                                                        <option value="" class="text-muted">Select participating team...</option>
                                                        <?php foreach ($teams as $team): ?>
                                                            <option value="<?php echo $team['team_id']; ?>" <?php echo ($current_submission && $current_submission['bronze_winner_college_id'] == $team['team_id']) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($team['team_name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="medal-input-group">
                                                        <label>Medal Count <span class="text-danger">*</span></label>
                                                        <?php if ($fixed_count !== null): ?>
                                                            <div class="form-control text-center fw-bold bg-light text-muted d-flex justify-content-center align-items-center gap-2">
                                                                <?php echo $fixed_count; ?> <i class="fas fa-lock small"></i>
                                                            </div>
                                                        <?php else: ?>
                                                            <input type="number" class="form-control text-center fw-bold" name="bronze_count" value="<?php echo $current_submission['bronze_count'] ?? 0; ?>" min="0" placeholder="0" required <?php if ($is_locked) echo 'disabled'; ?>>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <!-- History Card Starts Here -->
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

                    <!-- Verification Card -->
                    <div class="col-lg-5">
                        <div class="card mb-4">
                            <div class="card-header bg-white">
                                <h5 class="mb-0 text-primary"><i class="fas fa-file-contract me-2"></i>Verification & Evidence</h5>
                            </div>
                            <div class="card-body p-4">
                                
                                <div class="mb-4">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Official Tally Sheet <span class="text-danger">*</span></label>
                                    <div class="upload-zone position-relative text-center">
                                        <div id="tally_preview_box" class="<?php echo empty($category_info['tally_sheet_url']) ? 'd-none' : ''; ?> mb-2">
                                            <img id="tally_image_preview" src="<?php echo htmlspecialchars($category_info['tally_sheet_url'] ?? ''); ?>" class="img-thumbnail shadow-sm mb-2" style="max-height: 120px; object-fit: contain;">
                                            <div class="text-success fw-bold small"><i class="fas fa-check-circle me-1"></i> Uploaded</div>
                                        </div>

                                        <div id="tally_default_ui" class="<?php echo !empty($category_info['tally_sheet_url']) ? 'd-none' : ''; ?>">
                                            <i class="fas fa-cloud-upload-alt fa-2x text-secondary mb-2"></i>
                                            <p class="small text-muted mb-2">Upload signed score sheet (JPG/PNG)</p>
                                        </div>

                                        <p id="tally_change_text" class="small text-muted mb-2 <?php echo empty($category_info['tally_sheet_url']) ? 'd-none' : ''; ?>">Change file:</p>
                                        
                                        <input class="form-control form-control-sm" type="file" name="tally_sheet" id="tally_input" accept="image/*" 
                                            <?php if ($is_locked) echo 'disabled'; ?>
                                            <?php if (empty($category_info['tally_sheet_url'])) echo 'required'; ?>>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Victory / Action Photo (Optional)</label>
                                    <div class="upload-zone position-relative text-center">
                                        <div id="podium_preview_box" class="<?php echo empty($category_info['podium_photo_url']) ? 'd-none' : ''; ?> mb-2">
                                            <img id="podium_image_preview" src="<?php echo htmlspecialchars($category_info['podium_photo_url'] ?? ''); ?>" class="img-thumbnail shadow-sm mb-2" style="max-height: 120px; object-fit: contain;">
                                            <div class="text-success fw-bold small"><i class="fas fa-check-circle me-1"></i> Uploaded</div>
                                        </div>

                                        <div id="podium_default_ui" class="<?php echo !empty($category_info['podium_photo_url']) ? 'd-none' : ''; ?>">
                                            <i class="fas fa-camera fa-2x text-secondary mb-2"></i>
                                            <p class="small text-muted mb-2">Upload a winning moment or team photo</p>
                                        </div>

                                        <p id="podium_change_text" class="small text-muted mb-2 <?php echo empty($category_info['podium_photo_url']) ? 'd-none' : ''; ?>">Change photo:</p>

                                        <input class="form-control form-control-sm" type="file" name="podium_photo" id="podium_input" accept="image/*" <?php if ($is_locked) echo 'disabled'; ?>>
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
                    </div>
                </div>
                <?php if (!$is_locked): ?>
                <div class="sticky-bottom py-4 mt-5" style="background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); border-top: 2px solid rgba(102, 126, 234, 0.2); box-shadow: 0 -8px 32px rgba(0, 0, 0, 0.08); z-index: 999;">
                    <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center">
            
            <div class="d-flex align-items-center gap-2">
                <div style="width: 8px; height: 8px; background: #10b981; border-radius: 50%; animation: pulse 2s infinite;"></div>
                <span class="text-muted small fw-bold">
                    <i class="fas fa-shield-alt me-1"></i> Auto-saving enabled
                </span>
            </div>

            <div class="d-flex gap-3">
                <button type="submit" name="action" value="save_pending" 
                        class="btn btn-light border-2 fw-bold px-5 py-2 rounded-pill shadow-sm"
                        style="border-color: #e2e8f0; transition: all 0.3s;">
                    <i class="fas fa-save me-2"></i>Save Draft
                </button>
                <button type="submit" name="action" value="submit_for_approval" 
                        class="btn btn-primary fw-bold px-5 py-2 rounded-pill shadow-lg"
                        style="background: linear-gradient(green; border: none; transition: all 0.3s;"
                        onclick="return confirm('Ensure the Tally Sheet is uploaded. Continue?')">
                    <i class="fas fa-paper-plane me-2"></i>Submit for Approval
                </button>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
</style>
<?php endif; ?>
            </form>
        </div>
    </div>
    
    <footer class="footer-main">
        <div class="container">
            <div class="row">
                <div class="col-lg-5 col-md-12 mb-4 mb-lg-0">
                    <div class="footer-logo-group">
                        <img src="images/PIT.png" alt="Logo">
                        <img src="images/Cote.png" alt="Logo">
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
                <small>Developed by Jayvee Baybayon</small>
            </div>
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

            // --- 4. [NEW] LIVE IMAGE PREVIEW ---
            // Function to handle live preview using FileReader
            function setupLivePreview(inputId, previewBoxId, imagePreviewId, defaultUiId, changeTextId) {
                const input = document.getElementById(inputId);
                const previewBox = document.getElementById(previewBoxId);
                const imagePreview = document.getElementById(imagePreviewId);
                const defaultUi = document.getElementById(defaultUiId);
                const changeText = document.getElementById(changeTextId);

                if (input) {
                    input.addEventListener('change', function(event) {
                        const file = event.target.files[0];
                        if (file) {
                            // FileReader reads the file locally on the user's computer
                            const reader = new FileReader();
                            reader.onload = function(e) {
                                // Set the image source to the local file data
                                imagePreview.src = e.target.result;
                                
                                // Hide default UI and show the new preview
                                previewBox.classList.remove('d-none');
                                if (defaultUi) defaultUi.classList.add('d-none');
                                if (changeText) changeText.classList.remove('d-none');
                            }
                            reader.readAsDataURL(file); // Trigger the read process
                        }
                    });
                }
            }

            // Initialize it for both upload inputs
            setupLivePreview('tally_input', 'tally_preview_box', 'tally_image_preview', 'tally_default_ui', 'tally_change_text');
            setupLivePreview('podium_input', 'podium_preview_box', 'podium_image_preview', 'podium_default_ui', 'podium_change_text');
        });
    </script>
</body>
</html>