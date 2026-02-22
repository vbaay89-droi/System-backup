<?php
session_start();
// Use a relative path to your main db_connect.php
require_once '../config.php'; 

// 1. SECURITY & ACCESS CONTROL
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Sports Director') {
    header('Location: ../login.php'); 
    exit();
}

// Ensure user_id is set
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

$name = !empty($user_data['full_name']) ? $user_data['full_name'] : ($user_data['username'] ?? 'Sports Director');
$current_page = basename($_SERVER['PHP_SELF']);

// --- FILE UPLOAD CONFIGURATION (FIXED) ---
// Use absolute path for reliable server storage
$base_upload_path = dirname(__DIR__) . '/uploads/colleges/'; 
$default_logo = 'images/default_avatar.png';

// Updated Helper function with ERROR HANDLING
function handle_file_upload($file_key, $target_dir, $current_db_path = null) {
    global $default_logo; 
    
    // 1. Check if file is selected (If no file, return current/default logic)
    if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] == UPLOAD_ERR_NO_FILE) {
        return $current_db_path ?? $default_logo; 
    }

    // 2. Check for actual errors (Stop execution if error found)
    if ($_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['message'] = "Upload Failed: Error Code " . $_FILES[$file_key]['error'];
        $_SESSION['message_type'] = "danger";
        return false; // Return FALSE to signal failure
    }

    // 3. Create directory if missing
    if (!is_dir($target_dir)) {
        if (!mkdir($target_dir, 0777, true)) {
            $_SESSION['message'] = "Error: Failed to create upload folder.";
            $_SESSION['message_type'] = "danger";
            return false;
        }
    }

    $file_tmp_path = $_FILES[$file_key]['tmp_name'];
    $file_name = $_FILES[$file_key]['name'];
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    // 4. Validate Extension
    if (!in_array($file_ext, $allowed_ext)) {
        $_SESSION['message'] = "Error: Invalid file type. Only JPG, PNG, GIF allowed.";
        $_SESSION['message_type'] = "danger";
        return false;
    }

    // 5. Generate Name & Move
    $new_file_name = uniqid('team_', true) . '.' . $file_ext;
    $target_server_path = $target_dir . $new_file_name;

    if (move_uploaded_file($file_tmp_path, $target_server_path)) {
        // Delete old file if exists
        if ($current_db_path && $current_db_path !== $default_logo) {
            $old_file_absolute = dirname(__DIR__) . '/' . $current_db_path;
            if (file_exists($old_file_absolute)) {
                @unlink($old_file_absolute); 
            }
        }
        // Return relative path for DB
        return 'uploads/colleges/' . $new_file_name;
    } else {
        $_SESSION['message'] = "Error: Permission denied. Cannot save file.";
        $_SESSION['message_type'] = "danger";
        return false;
    }
}

// --- CRUD LOGIC ---

// 1. ADD TEAM
if (isset($_POST['add_college'])) {
    $college_name = $_POST['college_name'];
    $college_code = $_POST['college_code'];
    $team_manager = $_POST['team_manager']; 
    $slogan       = $_POST['slogan'];       
    $unit_color   = $_POST['unit_color'] ?? '#cccccc';

    // Handle Upload
    $logo_path = handle_file_upload('logo_url', $base_upload_path, $default_logo);

    // CHECK FOR FAILURE
    if ($logo_path === false) {
        header("Location: colleges.php");
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO colleges (college_name, college_code, team_manager, slogan, logo_url, unit_color) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $college_name, $college_code, $team_manager, $slogan, $logo_path, $unit_color);
    
    if($stmt->execute()) {
        $new_college_id = (int)$conn->insert_id;
        
        // Log activity (Simplified for clarity)
        $context = json_encode(['team_name' => $college_name]);
        $conn->query("INSERT INTO system_logs (actor_user_id, action_type, related_id, related_table, log_context) VALUES ($current_user_id, 'CREATED_COLLEGE', $new_college_id, 'colleges', '$context')");
        
        $_SESSION['message'] = "Team created successfully.";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "Database Error: " . $stmt->error;
        $_SESSION['message_type'] = "danger";
    }
    $stmt->close();
    header("Location: colleges.php");
    exit();
}

// 2. EDIT TEAM
if (isset($_POST['edit_college'])) {
    $college_id   = (int)$_POST['edit_college_id'];
    $college_name = $_POST['edit_college_name'];
    $college_code = $_POST['edit_college_code'];
    $team_manager = $_POST['edit_team_manager'];
    $slogan       = $_POST['edit_slogan'];
    $unit_color   = $_POST['edit_unit_color'];
    $current_logo = $_POST['current_logo_url'];

    // Handle Upload
    $logo_path = handle_file_upload('edit_logo_url', $base_upload_path, $current_logo);

    // CHECK FOR FAILURE (Critical Fix)
    if ($logo_path === false) {
        header("Location: colleges.php");
        exit(); // Stop script so error message is shown
    }

    $stmt = $conn->prepare("UPDATE colleges SET college_name = ?, college_code = ?, team_manager = ?, slogan = ?, logo_url = ?, unit_color = ? WHERE college_id = ?");
    $stmt->bind_param("ssssssi", $college_name, $college_code, $team_manager, $slogan, $logo_path, $unit_color, $college_id);
     
    if($stmt->execute()) {
        // Log activity
        $context = json_encode(['college_name' => $college_name]);
        $conn->query("INSERT INTO system_logs (actor_user_id, action_type, related_id, related_table, log_context) VALUES ($current_user_id, 'UPDATED_COLLEGE', $college_id, 'colleges', '$context')");
        
        $_SESSION['message'] = "Team updated successfully.";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "Database Error: " . $stmt->error;
        $_SESSION['message_type'] = "danger";
    }
    $stmt->close();
    header("Location: colleges.php");
    exit();
}

// 3. DELETE TEAM
if (isset($_POST['delete_college'])) {
    $college_id = (int)$_POST['delete_college_id'];
    
    // Check dependencies
    $check = $conn->query("SELECT COUNT(*) FROM results WHERE winner_gold_college_id = $college_id OR winner_silver_college_id = $college_id OR winner_bronze_college_id = $college_id");
    $count = $check->fetch_row()[0];
    
    if ($count > 0) {
        $_SESSION['message'] = "Cannot delete: Team is linked to $count results.";
        $_SESSION['message_type'] = "danger";
    } else {
        // Get existing logo to delete file
        $res = $conn->query("SELECT logo_url FROM colleges WHERE college_id = $college_id");
        $row = $res->fetch_assoc();

        $stmt = $conn->prepare("DELETE FROM colleges WHERE college_id = ?");
        $stmt->bind_param("i", $college_id);
        
        if($stmt->execute()) {
            // Delete file from server
            if ($row && $row['logo_url'] && $row['logo_url'] !== $default_logo) {
                $file = dirname(__DIR__) . '/' . $row['logo_url'];
                if (file_exists($file)) @unlink($file);
            }
            
            // Log
            $conn->query("INSERT INTO system_logs (actor_user_id, action_type, related_id, related_table) VALUES ($current_user_id, 'DELETED_COLLEGE', $college_id, 'colleges')");

            $_SESSION['message'] = "Team deleted successfully.";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = "Error: " . $stmt->error;
            $_SESSION['message_type'] = "danger";
        }
        $stmt->close();
    }
    header("Location: colleges.php");
    exit();
}

// --- FETCH DATA ---
$colleges = [];
$result = $conn->query("SELECT * FROM colleges ORDER BY college_name");
if ($result) {
    $colleges = $result->fetch_all(MYSQLI_ASSOC);
}

// Session Messages
$message = null; 
$message_type = 'info'; 
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Count sidebar badges
$pending_requests_count = $conn->query("SELECT COUNT(*) FROM users WHERE is_approved = 0")->fetch_row()[0] ?? 0;
$pending_results_count = $conn->query("SELECT COUNT(*) FROM categories WHERE status='Results Submitted'")->fetch_row()[0] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Teams - Sports Director Panel</title>
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

        .main-content { flex: 1 0 auto; padding: 30px; margin-top: var(--header-height); margin-left: var(--sidebar-width); transition: margin-left var(--transition); min-height: calc(100vh - var(--header-height)); }
        
        /* ========================================
   PROFESSIONAL VIEW PROFILE BUTTON
   ======================================== */
.team-card .btn-outline-dark {
    border: 2px solid #2c3e50;
    color: #2c3e50;
    background: white;
    font-weight: 700;
    letter-spacing: 0.5px;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.team-card .btn-outline-dark::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, #2c3e50 0%, var(--accent-color) 100%);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    z-index: -1;
}

.team-card .btn-outline-dark:hover {
    color: white;
    border-color: var(--accent-color);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(26, 188, 156, 0.3);
}

.team-card .btn-outline-dark:hover::before {
    left: 0;
}

.team-card .btn-outline-dark:active {
    transform: translateY(0px);
}

        /* The colored top banner using unit_color */
        .team-banner {
            height: 80px;
            width: 100%;
            position: relative;
        }

        /* Overlapping Logo */
        .team-logo-wrapper {
            width: 90px;
            height: 90px;
            margin: -45px auto 15px; /* Pulls logo up into banner */
            position: relative;
            z-index: 2;
            background: #fff;
            border-radius: 50%;
            padding: 4px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .team-logo-large {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
            background: #f8f9fa;
        }

        /* Typography */
        .team-name {
            font-weight: 800;
            font-size: 1.1rem;
            color: #2c3e50;
            margin-bottom: 5px;
            font-family: 'Poppins', sans-serif;
        }

        .team-code-badge {
            background: #2c3e50;
            color: #fff;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        /* Action Buttons (Edit/Delete) floating top right */
        .team-actions {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 3;
            opacity: 0; /* Hidden by default */
            transition: opacity 0.2s ease;
        }

        .team-card:hover .team-actions {
            opacity: 1; /* Show on hover */
        }

        .action-btn-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.9);
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #555;
            margin-left: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: all 0.2s;
        }

        .action-btn-circle:hover { transform: scale(1.1); color: #000; background: #fff; }
        .action-btn-circle.delete:hover { color: #dc3545; }
        .action-btn-circle.edit:hover { color: #0d6efd; }

        /* ========================================
        ENHANCED PAGE HEADER
        ======================================== */
        .page-header {
            position: relative;
            margin-bottom: 2rem;
        }

        .page-header .section-title {
            font-family: 'Poppins', sans-serif;
            font-weight: 800;
            font-size: 2.2rem;
            color: #2c3e50;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
        }

        .page-header .section-title i {
            background: linear-gradient(135deg, var(--accent-color) 0%, #16a085 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }


        /* ========================================
        ENHANCED MODAL STYLING
        ======================================== */
        .modal-content {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 1.5rem 2rem;
            border-bottom: none;
        }

        .modal-header .modal-title {
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            font-size: 1.4rem;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
            transition: all 0.2s;
        }

        .modal-header .btn-close:hover {
            opacity: 1;
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 2rem;
            background: #f8f9fa;
        }

        .modal-body .form-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        .modal-body .form-control,
        .modal-body .form-select {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .modal-body .form-control:focus,
        .modal-body .form-select:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(26, 188, 156, 0.1);
            background: white;
        }

        .modal-body .form-control-color {
            height: 45px;
            border-radius: 10px 0 0 10px;
            border: 2px solid #e0e0e0;
        }

        .modal-body .input-group .form-control {
            border-radius: 0 10px 10px 0;
        }

        .modal-body .form-text {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }

        .modal-footer {
            padding: 1.25rem 2rem;
            background: white;
            border-top: 1px solid #e9ecef;
        }

        .modal-footer .btn {
            padding: 0.65rem 2rem;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.9rem;
        }

        .modal-footer .btn-primary {
            background: linear-gradient(135deg, #1abc9c 0%, #16a085 100%);
            border: none;
            box-shadow: 0 4px 15px rgba(26, 188, 156, 0.3);
        }

        .modal-footer .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(26, 188, 156, 0.4);
        }

        .modal-footer .btn-danger {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            border: none;
            box-shadow: 0 4px 15px rgba(231, 76, 60, 0.3);
        }

        .modal-footer .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(231, 76, 60, 0.4);
        }

        .modal-footer .btn-secondary {
            background: #95a5a6;
            border: none;
        }

        .modal-footer .btn-secondary:hover {
            background: #7f8c8d;
            transform: translateY(-2px);
        }

        /* Delete Modal Warning Styling */
        #deleteCollegeModal .modal-body p.text-danger {
            background: #fff5f5;
            border-left: 4px solid #e74c3c;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
        }

        #deleteCollegeModal .modal-body strong {
            color: #2c3e50;
            font-size: 1.1rem;
        }

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
                .sidebar.minimized ~ footer {
            padding-left: var(--sidebar-min-width); 
        }

        /* Page Specific */
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        .card { border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        .table-img { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; }
        
        /* [NEW] Unit Color Swatch */
        .color-swatch {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            border: 1px solid rgba(0,0,0,0.1);
            display: inline-block;
        }

        /* --- CUSTOM TEAM LINK STYLE --- */
.team-name-link {
    color: #2c3e50; /* Dark text by default (matches your table) */
    text-decoration: none; /* No underline */
    font-weight: 700; /* Bold */
    transition: all 0.2s ease; /* Smooth transition */
    display: inline-block;
    cursor: pointer;
}

.team-name-link:hover {
    color: var(--accent-color) !important; /* Turns Teal/Green on hover */
    transform: translateX(5px); /* subtle slide to the right */
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
                <a class="nav-link" href="sports_director_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                </a>
            </li>
            
            <li class="nav-item mt-3"><span class="nav-title">Tournament Mgmt</span></li>
            <li class="nav-item">
                <a class="nav-link active" href="colleges.php">
                    <i class="fas fa-users me-2"></i> <span>Manage Teams</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="events.php">
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
                <a class="nav-link" href="results.php">
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
                <li class="breadcrumb-item active" aria-current="page">Manage Teams</li>
              </ol>
            </nav>

            <div class="page-header mb-4">
                <h1 class="section-title">
                    </i>Manage Teams
                </h1>
            </div>

            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 gap-3">
                <div class="position-relative w-100" style="max-width: 400px;">
                        <i class="fas fa-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                        <input type="text" id="teamSearch" class="form-control ps-5 rounded-pill border-0 shadow-sm" placeholder="Search teams, codes, or managers...">
                    </div>
                    <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addCollegeModal">
                        <i class="fas fa-plus me-2"></i>Add New Team
                    </button>
                </div>
                <div class="row g-4" id="teamGrid">
                    <?php foreach ($colleges as $college): ?>
                    <?php
                        $logo = (!empty($college['logo_url'])) ? $college['logo_url'] : $default_logo;
                        // Ensure we have a valid color, default to gray
                        $color = !empty($college['unit_color']) ? $college['unit_color'] : '#cccccc';
                    ?>
                    <div class="col-xl-3 col-lg-4 col-md-6 team-item">
                        <div class="card team-card h-100">
                            
                            <div class="team-banner" style="background-color: <?= htmlspecialchars($color) ?>;">
                                <div class="team-actions">
                                    <button class="action-btn-circle edit" 
                                        data-bs-toggle="modal" data-bs-target="#editCollegeModal"
                                        data-id="<?= $college['college_id'] ?>"
                                        data-name="<?= htmlspecialchars($college['college_name']) ?>"
                                        data-code="<?= htmlspecialchars($college['college_code'] ?? '') ?>" 
                                        data-manager="<?= htmlspecialchars($college['team_manager'] ?? '') ?>"
                                        data-slogan="<?= htmlspecialchars($college['slogan'] ?? '') ?>" 
                                        data-logo="<?= htmlspecialchars($logo) ?>"
                                        data-color="<?= htmlspecialchars($color) ?>"
                                        title="Edit Team">
                                        <i class="fas fa-pen fa-xs"></i>
                                    </button>
                                    <button class="action-btn-circle delete" 
                                        data-bs-toggle="modal" data-bs-target="#deleteCollegeModal"
                                        data-id="<?= $college['college_id'] ?>"
                                        data-name="<?= htmlspecialchars($college['college_name']) ?>"
                                        title="Delete Team">
                                        <i class="fas fa-trash fa-xs"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="card-body text-center pt-0 d-flex flex-column">
                                <div class="team-logo-wrapper">
                                    <img src="../<?= htmlspecialchars($logo) ?>" alt="Logo" class="team-logo-large"
                                        onerror="this.onerror=null; this.src='../<?= $default_logo ?>'">
                                </div>
                                
                                <h5 class="team-name text-truncate" title="<?= htmlspecialchars($college['college_name']) ?>">
                                    <?= htmlspecialchars($college['college_name']) ?>
                                </h5>
                                
                                <div class="mb-3">
                                    <span class="team-code-badge"><?= htmlspecialchars($college['college_code'] ?? 'N/A') ?></span>
                                </div>

                                <div class="mt-auto text-muted small">
                                    <?php if(!empty($college['team_manager'])): ?>
                                        <div class="mb-1"><i class="fas fa-user-tie me-1 text-primary"></i> <?= htmlspecialchars($college['team_manager']) ?></div>
                                    <?php else: ?>
                                        <div class="mb-1 fst-italic text-secondary">No Manager Assigned</div>
                                    <?php endif; ?>
                                    
                                    <?php if(!empty($college['slogan'])): ?>
                                        <div class="text-truncate fst-italic opacity-75" title="<?= htmlspecialchars($college['slogan']) ?>">
                                            "<?= htmlspecialchars($college['slogan']) ?>"
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- NEW CODE: -->
                                <a href="team_profile.php?team_id=<?= $college['college_id'] ?>" class="btn btn-outline-dark btn-sm rounded-pill mt-3 w-100 fw-bold">
                                    </i>View Profile
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($colleges)): ?>
                    <div class="text-center py-5">
                        <img src="../images/no_data.svg" style="width: 150px; opacity: 0.5;">
                        <p class="text-muted mt-3">No teams found. Click "Add New Team" to start.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="modal fade" id="addCollegeModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Team</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="colleges.php" method="POST" enctype="multipart/form-data">
                        <div class="modal-body">
                            <input type="hidden" name="add_college">
                            <div class="mb-3">
                                <label for="college_name" class="form-label">Team Name</label>
                                <input type="text" class="form-control" id="college_name" name="college_name" required>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="college_code" class="form-label">Team Code</label>
                                    <input type="text" class="form-control" id="college_code" name="college_code" required placeholder="e.g., COTE">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Unit Color</label>
                                    <div class="input-group">
                                        <input type="color" class="form-control form-control-color" id="add_color_picker" value="#cccccc" title="Pick a color">
                                        <input type="text" class="form-control" id="add_unit_color" name="unit_color" value="#cccccc" placeholder="#RRGGBB">
                                    </div>
                                    <div class="form-text text-muted small">Pick a color OR type a hex code.</div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="team_manager" class="form-label">Team Manager</label>
                                <input type="text" class="form-control" id="team_manager" name="team_manager">
                            </div>
                            <div class="mb-3">
                                <label for="slogan" class="form-label">Team Slogan</label>
                                <textarea class="form-control" id="slogan" name="slogan" rows="2" placeholder="Enter team slogan..."></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="logo_url" class="form-label">Team Logo (Optional)</label>
                                <input type="file" class="form-control" id="logo_url" name="logo_url" accept="image/*">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Save Team</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="editCollegeModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Team</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="colleges.php" method="POST" enctype="multipart/form-data">
                        <div class="modal-body">
                            <input type="hidden" name="edit_college">
                            <input type="hidden" name="edit_college_id" id="edit_college_id">
                            <input type="hidden" name="current_logo_url" id="current_logo_url">
                            
                            <div class="mb-3">
                                <label for="edit_college_name" class="form-label">Team Name</label>
                                <input type="text" class="form-control" id="edit_college_name" name="edit_college_name" required>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="edit_college_code" class="form-label">Team Code</label>
                                    <input type="text" class="form-control" id="edit_college_code" name="edit_college_code" required placeholder="e.g., COTE">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Unit Color</label>
                                    <div class="input-group">
                                        <input type="color" class="form-control form-control-color" id="edit_color_picker" title="Pick a color">
                                        <input type="text" class="form-control" id="edit_unit_color" name="edit_unit_color" placeholder="#RRGGBB">
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="edit_team_manager" class="form-label">Team Manager</label>
                                <input type="text" class="form-control" id="edit_team_manager" name="edit_team_manager">
                            </div>
                            <div class="mb-3">
                                <label for="edit_slogan" class="form-label">Team Slogan</label>
                                <textarea class="form-control" id="edit_slogan" name="edit_slogan" rows="2"></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="edit_logo_url" class="form-label">New Team Logo (Optional)</label>
                                <input type="file" class="form-control" id="edit_logo_url" name="edit_logo_url" accept="image/*">
                                <small class="text-muted">Leave blank to keep current logo.</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Update Team</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="deleteCollegeModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Confirm Deletion</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form action="colleges.php" method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="delete_college">
                            <input type="hidden" name="delete_college_id" id="delete_college_id">
                            <p>Are you sure you want to delete this team: <strong id="delete_college_name"></strong>?</p>
                            <p class="text-danger">This action cannot be undone. It will only succeed if the team is not linked to any results.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Delete Team</button>
                        </div>
                    </form>
                </div>
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

        // SEARCH FILTER FOR GRID
        const searchInput = document.getElementById('teamSearch');
        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const filter = this.value.toLowerCase();
                const items = document.querySelectorAll('.team-item');

                items.forEach(function(item) {
                    const text = item.textContent.toLowerCase();
                    if (text.includes(filter)) {
                        item.style.display = ''; // Show
                    } else {
                        item.style.display = 'none'; // Hide
                    }
                });
            });
        }
        
        // [NEW] Function to sync Color Picker and Text Input
        function setupColorSync(pickerId, textId) {
            const picker = document.getElementById(pickerId);
            const text = document.getElementById(textId);
            
            if(!picker || !text) return;

            // 1. Picker changes -> Update Text
            picker.addEventListener('input', function() {
                text.value = this.value;
            });

            // 2. Text changes -> Update Picker (only if valid hex)
            text.addEventListener('input', function() {
                const val = this.value;
                // Simple Hex Check: starts with #, then 6 chars (0-9, A-F)
                if (/^#[0-9A-F]{6}$/i.test(val)) {
                    picker.value = val;
                }
            });
        }

        // Initialize Sync for Add Modal
        setupColorSync('add_color_picker', 'add_unit_color');
        // Initialize Sync for Edit Modal
        setupColorSync('edit_color_picker', 'edit_unit_color');


        // EDIT MODAL SCRIPT
        const editCollegeModal = document.getElementById('editCollegeModal');
        editCollegeModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            
            document.getElementById('edit_college_id').value = button.dataset.id;
            document.getElementById('edit_college_name').value = button.dataset.name;
            document.getElementById('edit_college_code').value = button.dataset.code;
            document.getElementById('edit_team_manager').value = button.dataset.manager;
            document.getElementById('edit_slogan').value = button.dataset.slogan;
            
            // [NEW] Populate Color
            const color = button.dataset.color || '#cccccc';
            document.getElementById('edit_unit_color').value = color;
            document.getElementById('edit_color_picker').value = color; // Sync picker too
            
            document.getElementById('current_logo_url').value = button.dataset.logo;
        });

        // DELETE MODAL SCRIPT
        const deleteCollegeModal = document.getElementById('deleteCollegeModal');
        deleteCollegeModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('delete_college_id').value = button.dataset.id;
            document.getElementById('delete_college_name').textContent = button.dataset.name;
        });

        // SIDEBAR TOGGLE
        const mobileToggle = document.getElementById('mobileToggle');
        if(mobileToggle) {
            mobileToggle.addEventListener('click', function() {
                document.getElementById('sidebar').classList.toggle('show');
            });
        }

        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                if (window.innerWidth > 992) {
                    const sidebar = document.getElementById('sidebar');
                    if(sidebar) sidebar.classList.remove('show');
                }
            }, 250);
        });

        const footer = document.querySelector('footer');
        const navbar = document.querySelector('.navbar');
        const sidebar = document.getElementById('sidebar');

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

        // ==========================================
        // 2. REAL-TIME BADGE UPDATER
        // ==========================================
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
                .catch(err => console.error('Badge update error:', err));
        }

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

        // Run Badges
        updateSidebarBadges();
        setInterval(updateSidebarBadges, 5000);
    });
    </script>
</body>
</html>