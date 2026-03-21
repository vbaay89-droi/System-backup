<?php
session_start();
require_once 'config.php'; // Assumes this file is in the root directory

// 1. SECURITY & ACCESS CONTROL
// STRICT: Only 'Sports Director' is allowed (Acting as Super Admin)
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'Sports Director'
) {
    header('Location: login.php');
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

// Determine Name
if (!empty($user_data['full_name'])) {
    $name = $user_data['full_name'];
} else {
    $name = $user_data['username'] ?? 'Sports Director';
}

$current_page = basename($_SERVER['PHP_SELF']);
$message = '';
$message_type = '';

// Helper function to validate the email
// Helper function to validate the email
function validate_gmail($email) {
    // We only check if it is a valid email format now (works for Yahoo, Edu, Gmail, etc.)
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "Not a valid email address.";
    }
    return true;
}

/* --- C.R.U.D. LOGIC --- */

// 1. ADD USER
if (isset($_POST['add_user'])) {
    $full_name = $_POST['full_name'];
    $username_email = $_POST['username']; 
    $password  = $_POST['password'];
    $role      = $_POST['role'];
    $status    = $_POST['status'];

    $validation_result = validate_gmail($username_email);
    if ($validation_result !== true) {
        $_SESSION['message'] = "Error: " . $validation_result;
        $_SESSION['message_type'] = 'danger';
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Check if username/email exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username_email, $username_email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $_SESSION['message'] = "Error: Email '{$username_email}' already exists.";
            $_SESSION['message_type'] = 'danger';
        } else {
            $stmt->close();
            // Insert user
            // UPDATED: Explicitly set 'is_approved' to 1 so they don't appear in pending requests
            $stmt = $conn->prepare("INSERT INTO users (full_name, username, email, password, role, status, is_approved) VALUES (?, ?, ?, ?, ?, ?, 1)");
            
            // Note: We don't need to bind '1' because we hardcoded it in the SQL above.
            // So the bind_param stays the same (6 strings).
            $stmt->bind_param("ssssss", $full_name, $username_email, $username_email, $hashed_password, $role, $status);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = "User '{$full_name}' created successfully.";
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = "Error creating user: " . $stmt->error;
                $_SESSION['message_type'] = 'danger';
            }
        }
        $stmt->close();
    }
    header("Location: Manage_Users.php");
    exit();
}

// 2. UPDATE USER
if (isset($_POST['edit_user'])) {
    $user_id   = $_POST['edit_user_id'];
    $full_name = $_POST['edit_full_name'];
    $username_email = $_POST['edit_username']; 
    $role      = $_POST['edit_role'];
    $status    = $_POST['edit_status'];

    $validation_result = validate_gmail($username_email);
    if ($validation_result !== true) {
        $_SESSION['message'] = "Error: " . $validation_result;
        $_SESSION['message_type'] = 'danger';
    } else {
        // Check collision
        $stmt = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
        $stmt->bind_param("ssi", $username_email, $username_email, $user_id);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $_SESSION['message'] = "Error: Email '{$username_email}' is already taken.";
            $_SESSION['message_type'] = 'danger';
        } else {
            $stmt->close();
            // Update user
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, role = ?, status = ? WHERE id = ?");
            $stmt->bind_param("sssssi", $full_name, $username_email, $username_email, $role, $status, $user_id);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = "User '{$full_name}' updated successfully.";
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = "Error updating user: " . $stmt->error;
                $_SESSION['message_type'] = 'danger';
            }
        }
        $stmt->close();
    }
    header("Location: Manage_Users.php");
    exit();
}

// 3. DELETE USER
if (isset($_POST['delete_user'])) {
    $user_id = $_POST['delete_user_id'];

    if ($user_id == $_SESSION['user_id']) {
        $_SESSION['message'] = "Error: You cannot delete your own account.";
        $_SESSION['message_type'] = 'danger';
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $_SESSION['message'] = "User deleted successfully.";
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = "Error deleting user: " . $stmt->error;
            $_SESSION['message_type'] = 'danger';
        }
        $stmt->close();
    }
    header("Location: Manage_Users.php");
    exit();
}

// 4. FETCH DATA
$users = [];
$result = $conn->query("SELECT id AS user_id, full_name, username, email, role, status, created_at 
                        FROM users 
                        ORDER BY CASE WHEN role = 'Sports Director' THEN 1 ELSE 2 END, username ASC");
if ($result) {
    $users = $result->fetch_all(MYSQLI_ASSOC);
}

// Session Messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message'], $_SESSION['message_type']);
}

// Sidebar Badges
// Count pending requests for sidebar badge (Fixed: Counts unapproved users)
$pending_requests_count = $conn->query("SELECT COUNT(*) FROM users WHERE is_approved = 0")->fetch_row()[0] ?? 0;
$pending_results_count = $conn->query("SELECT COUNT(*) FROM categories WHERE status='Results Submitted'")->fetch_row()[0] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - SPorts Director Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* --- Unified CSS Theme --- */
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

        /* === ENHANCED TABLE DESIGN === */
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08); overflow: hidden; margin-bottom: 1.5rem; }
        .card-header { background: #ffffff !important; border-bottom: 2px solid #f1f3f5 !important; padding: 1.25rem 1.5rem !important; }
        .card-header h5 { font-family: 'Poppins', sans-serif; font-weight: 600; color: #2c3e50; margin-bottom: 0; font-size: 1.1rem; }

        /* Modern Table Container */
        .results-table-container {
            background: #ffffff;
            border-radius: 0 0 12px 12px;
            overflow-x: auto;
            position: relative;
        }
        
        .results-table { margin-bottom: 0; font-size: 0.9375rem; width: 100%; border-collapse: separate; border-spacing: 0; }
        
        /* Enhanced Table Header */
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
        .results-table tbody tr:hover { background-color: #f8f9fa; transform: translateX(2px); box-shadow: -3px 0 0 0 #0d6efd inset; }
        
        /* Sticky Action Column */
        .sticky-col {
            position: sticky;
            right: 0;
            z-index: 2;
            background-color: #fff;
            box-shadow: -5px 0 10px rgba(0,0,0,0.05);
        }
        .results-table thead th.sticky-col { background: #e9ecef; z-index: 5; }
        .results-table tbody tr:hover .sticky-col { background-color: #f8f9fa; }

        /* Action Buttons */
        .action-btn { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; transition: all 0.2s ease; font-size: 0.875rem; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08); border: 2px solid transparent; }
        .action-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0, 0, 0, 0.12); }
        .action-btn.btn-primary { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); border-color: #2563eb; color: white; }
        .action-btn.btn-danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); border-color: #dc2626; color: white; }

        /* Modal Styling */
        .modal-content { border-radius: 12px; border: none; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15); }
        .modal-header { border-top-left-radius: 12px; border-top-right-radius: 12px; padding: 1.25rem 1.5rem; }
        .modal-body { padding: 1.5rem; }
        .modal-footer { padding: 1rem 1.5rem; border-top: 1px solid #e5e7eb; }

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

        .page-header .page-subtitle {
            color: #7f8c8d;
            font-size: 0.95rem;
            margin: 0;
            font-weight: 400;
        }

        /* Professional Alert Styling */
        .alert {
            border: none;
            border-radius: 10px;
            padding: 1rem 1.25rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            font-size: 0.95rem;
            border-left: 4px solid transparent;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border-left-color: #28a745;
        }

        .alert-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffe69c 100%);
            color: #856404;
            border-left-color: #ffc107;
        }

        .alert-danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-left-color: #dc3545;
        }

        /* Enhanced Card Header */
        .card-header .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border: none;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .card-header .btn-primary:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
            transform: translateY(-2px);
        }

        /* Modern Badge Design */
        .badge {
            padding: 0.4rem 0.75rem;
            font-weight: 600;
            font-size: 0.75rem;
            letter-spacing: 0.3px;
            border-radius: 6px;
        }

        .badge.bg-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
            box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);
        }

        .badge.bg-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important;
            box-shadow: 0 2px 6px rgba(245, 158, 11, 0.3);
        }

        .badge.bg-secondary {
            background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%) !important;
            box-shadow: 0 2px 6px rgba(107, 114, 128, 0.3);
        }

        /* Empty State Styling */
        .empty-state {
            padding: 2rem 0;
        }

        .empty-state i {
            opacity: 0.5;
        }

        .empty-state p {
            font-weight: 500;
            color: #64748b;
        }

        .empty-state small {
            color: #94a3b8;
            font-size: 0.875rem;
        }

        /* Enhanced Modal Design */
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        }

        .modal-header {
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
            padding: 1.25rem 1.5rem;
            border-bottom: 2px solid rgba(255, 255, 255, 0.1);
        }

        .modal-header.bg-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%) !important;
        }

        .modal-header.bg-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%) !important;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e5e7eb;
        }

        .modal-footer .btn-secondary {
            background-color: #6b7280;
            border: none;
        }

        .modal-footer .btn-secondary:hover {
            background-color: #4b5563;
        }

        .modal-footer .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border: none;
        }

        .modal-footer .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border: none;
        }

        /* Modern Form Controls */
        .form-label {
            font-weight: 600;
            color: #374151;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
            transition: all 0.2s ease;
            font-size: 0.9375rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .alert-light {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
        }

        /* =========================================
   MOBILE OPTIMIZATION (Sports Director)
   ========================================= */
/* =========================================
   MOBILE OPTIMIZATION (Manage Users)
   ========================================= */
@media (max-width: 991.98px) {
    
    /* 1. NAVBAR & LAYOUT (Standard Drawer) */
    .navbar {
        padding: 0.5rem 1rem !important;
        height: 60px !important;
        display: flex !important;
        flex-wrap: nowrap !important;
        align-items: center !important;
    }
    
    .navbar-toggler {
        order: 1 !important;
        border: 1px solid rgba(255,255,255,0.1);
        padding: 4px 8px;
        font-size: 1.2rem;
        margin-right: 10px !important;
    }
    .navbar-toggler:focus { box-shadow: none; }

    .navbar-brand {
        order: 2 !important;
        margin-right: auto !important;
        display: flex;
        align-items: center;
        max-width: 60%;
    }
    .navbar-brand img { height: 30px !important; width: 30px !important; margin-right: 8px !important; }
    .navbar-brand strong { font-size: 0.95rem !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .navbar-brand small { display: none !important; }

    .user-dropdown {
        order: 3 !important;
        margin-left: 0 !important;
    }
    .user-dropdown .user-name { display: none !important; }
    .user-dropdown .dropdown-toggle i { font-size: 26px !important; margin: 0 !important; color: #fff; }

    /* 2. SIDEBAR DRAWER */
    .sidebar {
        position: fixed !important;
        top: 60px !important;
        left: -260px !important;
        width: 260px !important;
        height: calc(100vh - 60px) !important;
        background-color: #2c3e50 !important;
        box-shadow: 5px 0 15px rgba(0,0,0,0.3);
        transition: left 0.3s ease-in-out !important;
        z-index: 1045;
        overflow-y: auto;
    }
    .sidebar.show { left: 0 !important; }
    .sidebar-nav .nav-link { white-space: nowrap; font-size: 0.95rem; }

    /* 3. MAIN CONTENT */
    .main-content {
        padding: 15px !important;
        margin-top: 60px !important;
        margin-left: 0 !important;
    }

    /* 4. RESPONSIVE TABLE (Scrollable) */
    .results-table-container {
        width: 100%;
        overflow-x: auto; /* Enables horizontal scrolling */
        -webkit-overflow-scrolling: touch; /* Smooth scroll on iOS */
        margin-bottom: 15px;
        border-radius: 12px;
        border: 1px solid #e9ecef;
    }

    .results-table {
        margin-bottom: 0;
        width: 100%;
        min-width: 800px; /* Force table to be wide enough to look good */
    }

    /* Keep text on one line for readability */
    .results-table th, 
    .results-table td {
        white-space: nowrap; 
        vertical-align: middle;
        padding: 12px 15px; /* Comfortable touch padding */
        font-size: 0.9rem;
    }

    /* Sticky Action Column (Optional: Keeps buttons visible while scrolling) */
    /* Remove this block if you want the actions to scroll away with the table */
    .results-table td:last-child, 
    .results-table th:last-child {
        position: sticky;
        right: 0;
        background: #fff; /* Opaque background to cover scrolling content */
        border-left: 1px solid #e9ecef;
        box-shadow: -2px 0 5px rgba(0,0,0,0.05);
        z-index: 5;
    }
    .results-table th:last-child { background: #f8f9fa; } /* Match header background */

    
    
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
    </style>
</head>
<body>
    
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="sd/sports_director_dashboard.php">
                <img src="images/PIT.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain;">
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
                    <i class="fas fa-user-circle" style="font-size: 36px; margin-right: 10px; color: rgba(255,255,255,0.8);"></i>
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="admin_profile.php"><i class="fas fa-user-circle me-2"></i> Profile</a></li>
                    <li><a class="dropdown-item" href="Tournament_Manager_page.php" target="_blank"><i class="fas fa-globe me-2"></i> Public Site</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- UNIFIED SUPER ADMIN SIDEBAR -->
    <div class="sidebar" id="sidebar">
        <ul class="nav flex-column sidebar-nav">
            <li class="nav-item">
                <a class="nav-link" href="sd/sports_director_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                </a>
            </li>
            
            <li class="nav-item mt-3"><span class="nav-title">Tournament Mgmt</span></li>
            <li class="nav-item">
                <a class="nav-link" href="sd/colleges.php">
                    <i class="fas fa-users me-2"></i> <span>Manage Teams</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="sd/events.php">
                    <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events </span>
                </a>
            </li>

            <li class="nav-item mt-3"><span class="nav-title">Administration</span></li>
            <li class="nav-item">
                <a class="nav-link active" href="Manage_Users.php">
                    <i class="fas fa-users-cog me-2"></i> <span>Manage Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="Manage_Requests.php">
                    <i class="fas fa-user-plus me-2"></i> <span>Account Requests</span>
                    <?php if($pending_requests_count > 0): ?>
                        <span class="badge bg-danger ms-auto rounded-pill"><?= $pending_requests_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
             <li class="nav-item">
                <a class="nav-link" href="Manage_Viewreports.php">
                    <i class="fas fa-file-alt me-2"></i> <span>View System Reports</span>
                </a>
            </li>

            <li class="nav-item mt-3"><span class="nav-title">Tallying & Scoring</span></li>
            <li class="nav-item">
                <a class="nav-link" href="sd/results.php">
                    <i class="fas fa-check-double me-2"></i> <span>Approve Results</span>
                    <?php if($pending_results_count > 0): ?>
                        <span class="badge bg-warning text-dark ms-auto rounded-pill"><?= $pending_results_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="sd/reports.php">
                    <i class="fas fa-chart-line me-2"></i> <span>Medal Standings</span>
                </a>
            </li>

            <!-- NEW SECTION: SEASON MANAGEMENT -->
            <li class="nav-item mt-3"><span class="nav-title">Season Management</span></li>
            <li class="nav-item">
                <a class="nav-link <?= ($current_page == 'manage_archives.php') ? 'active' : '' ?>" href="manage_archives.php">
                    <i class="fas fa-history me-2"></i> <span>Archives & Reset</span>
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
                
                <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="sd/sports_director_dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Manage Users</li>
                </ol>
                </nav>

                <div class="page-header">
        <h1 class="section-title">
            </i>
            Manage Users
        </h1>
        <p class="page-subtitle">Create, edit, and manage system user accounts</p>
    </div>
            
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show shadow-sm" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-users-cog me-2 text-primary"></i>User Accounts</h5>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="fas fa-user-plus me-2"></i>Add New User
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive results-table-container">
                        <table class="table results-table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="min-width: 50px;">#</th>
                                    <th style="min-width: 180px;">Full Name</th>
                                    <th style="min-width: 200px;">Email / Username</th> 
                                    <th style="min-width: 140px;">Role</th>
                                    <th style="min-width: 100px;">Status</th>
                                    <th style="min-width: 120px;">Created On</th>
                                    <th class="text-end sticky-col" style="min-width: 100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $index => $user): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($user['full_name']) ?></div>
                                    </td>
                                    <td>
                                        <div class="text-muted small"><i class="fas fa-envelope me-1"></i> <?= htmlspecialchars($user['username']) ?></div>
                                    </td> 
                                    <td>
                                        <?php 
                                            if ($user['role'] === 'Sports Director') {
                                                // Changed text-primary to text-dark for bold black text
                                                echo '<span class="fw-bold text-dark" style="font-size: 0.9rem;">
                                                        <i class="fas fa-user-shield me-1"></i>Sports Director
                                                    </span>';
                                            } else {
                                                echo '<span class="fw-bold text-secondary" style="font-size: 0.9rem;">
                                                        <i class="fas fa-user-tie me-1"></i>Tournament Manager
                                                    </span>';
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($user['status'] == 'active'): ?>
                                            <span class="badge bg-success rounded-pill">Active</span>
                                        <?php elseif ($user['status'] == 'inactive'): ?>
                                            <span class="badge bg-warning text-dark rounded-pill">Inactive</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary rounded-pill"><?= htmlspecialchars($user['status']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small class="text-muted"><?= date('M d, Y', strtotime($user['created_at'])) ?></small></td>
                                    <td class="text-end sticky-col">
                                        <div class="d-flex gap-2 justify-content-end">
                                            <button class="action-btn btn-primary"
                                                data-bs-toggle="modal" data-bs-target="#editUserModal"
                                                data-id="<?= $user['user_id'] ?>"
                                                data-name="<?= htmlspecialchars($user['full_name']) ?>"
                                                data-username="<?= htmlspecialchars($user['username']) ?>" 
                                                data-role="<?= $user['role'] ?>"
                                                data-status="<?= $user['status'] ?>"
                                                title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                            <button class="action-btn btn-danger"
                                                data-bs-toggle="modal" data-bs-target="#deleteUserModal"
                                                data-id="<?= $user['user_id'] ?>"
                                                data-name="<?= htmlspecialchars($user['full_name']) ?>"
                                                title="Delete">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        <div class="empty-state">
                                            <i class="fas fa-users-slash fa-4x mb-3" style="color: #cbd5e1;"></i>
                                            <p class="text-muted mb-0 fs-5">No users found</p>
                                            <small class="text-muted">Click "Add New User" to create your first user account</small>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
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
                <small>Developed by Jayvee Baybyon</small>
            </div>
        </div>
    </footer>

    <!-- ADD USER MODAL -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Add New User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="Manage_Users.php" method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="username" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="username" name="username" required 
                                title="Please enter a valid email address."
                                placeholder="name@example.com">
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="role" class="form-label">Role</label>
                                <select class="form-select" id="role" name="role" required>
                                    <option value="Tournament Manager">Tournament Manager</option>
                                    <option value="Sports Director">Sports Director</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="add_user" class="btn btn-primary">Save User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- EDIT USER MODAL -->
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Edit User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="Manage_Users.php" method="POST">
                    <input type="hidden" name="edit_user_id" id="edit_user_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="edit_full_name" name="edit_full_name" required>
                        </div>

                        <div class="mb-3">
                            <label for="edit_username" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="edit_username" name="edit_username" required
                                title="Please enter a valid email address.">
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_role" class="form-label">Role</label>
                                <select class="form-select" id="edit_role" name="edit_role" required>
                                    <option value="Tournament Manager">Tournament Manager</option>
                                    <option value="Sports Director">Sports Director</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_status" class="form-label">Status</label>
                                <select class="form-select" id="edit_status" name="edit_status" required>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="alert alert-light border mb-0">
                            <small class="text-muted"><i class="fas fa-info-circle me-1"></i> Passwords cannot be changed here.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="edit_user" class="btn btn-primary">Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- DELETE USER MODAL -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form action="Manage_Users.php" method="POST">
                    <input type="hidden" name="delete_user_id" id="delete_user_id">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">Confirm Deletion</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to permanently delete this user?</p>
                        <h5 class="text-center fw-bold my-3 text-danger" id="delete_user_name"></h5>
                        <div class="alert alert-warning d-flex align-items-start mb-0" style="border-left: 4px solid #f59e0b;">
    <i class="fas fa-exclamation-triangle me-3 mt-1" style="font-size: 1.25rem;"></i>
    <div>
        <strong>Warning!</strong><br>
        <small>This action cannot be undone. All data associated with this user will be permanently deleted.</small>
    </div>
</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_user" class="btn btn-danger">Delete User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            
            // Sidebar Toggle
            const mobileToggle = document.getElementById('mobileToggle');
            if (mobileToggle) {
                mobileToggle.addEventListener('click', function() {
                    document.getElementById('sidebar').classList.toggle('show');
                });
            }
            
            // Edit User Modal Population
            const editUserModal = document.getElementById('editUserModal');
            if (editUserModal) {
                editUserModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    
                    document.getElementById('edit_user_id').value = button.getAttribute('data-id');
                    document.getElementById('edit_full_name').value = button.getAttribute('data-name');
                    document.getElementById('edit_username').value = button.getAttribute('data-username');
                    document.getElementById('edit_role').value = button.getAttribute('data-role');
                    document.getElementById('edit_status').value = button.getAttribute('data-status');
                });
            }

            // Delete User Modal Population
            const deleteUserModal = document.getElementById('deleteUserModal');
            if (deleteUserModal) {
                deleteUserModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    document.getElementById('delete_user_id').value = button.getAttribute('data-id');
                    document.getElementById('delete_user_name').textContent = button.getAttribute('data-name');
                });
            }
            
            // Dynamic Footer Adjustment
            const footer = document.querySelector('footer');
            const navbar = document.querySelector('.navbar');
            const sidebar = document.getElementById('sidebar');
            if (sidebar && footer && navbar) {
                function adjustSidebarHeight() {
                    if (window.innerWidth <= 992) {
                        sidebar.style.height = ''; return;
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
            fetch('api_notifications.php?t=' + new Date().getTime())
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