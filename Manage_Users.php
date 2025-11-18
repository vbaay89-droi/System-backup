<?php
session_start();
require_once 'db_connect.php'; // DB connection

// Strict Role-Based Access Control
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'Administrator'
) {
    header('Location: login.php');
    exit();
}

$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin';
$current_page = basename($_SERVER['PHP_SELF']);
$message = '';
$message_type = '';

// NEW: Helper function to validate the email
function validate_gmail($email) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "Not a valid email address.";
    }
    if (substr($email, -10) !== '@gmail.com') {
        return "Only @gmail.com addresses are allowed.";
    }
    return true;
}

/* --- C.R.U.D. LOGIC --- */

// 1. ADD USER (CREATE) - MODIFIED
if (isset($_POST['add_user'])) {
    $full_name = $_POST['full_name'];
    $username_email = $_POST['username']; // This is the @gmail.com input
    $password  = $_POST['password'];
    $role      = $_POST['role'];
    $status    = $_POST['status'];

    // NEW: Validation
    $validation_result = validate_gmail($username_email);
    if ($validation_result !== true) {
        $_SESSION['message'] = "Error: " . $validation_result;
        $_SESSION['message_type'] = 'danger';
    } else {
        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Check if username or email already exists (since they are the same)
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username_email, $username_email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $_SESSION['message'] = "Error: Email '{$username_email}' already exists.";
            $_SESSION['message_type'] = 'danger';
        } else {
            $stmt->close();
            // MODIFIED: Insert the email into BOTH username and email columns
            $stmt = $conn->prepare("INSERT INTO users (full_name, username, email, password, role, status) VALUES (?, ?, ?, ?, ?, ?)");
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

// 2. UPDATE USER (UPDATE) - MODIFIED
if (isset($_POST['edit_user'])) {
    $user_id   = $_POST['edit_user_id'];
    $full_name = $_POST['edit_full_name'];
    $username_email = $_POST['edit_username']; // This is the @gmail.com input
    $role      = $_POST['edit_role'];
    $status    = $_POST['edit_status'];

    // NEW: Validation
    $validation_result = validate_gmail($username_email);
    if ($validation_result !== true) {
        $_SESSION['message'] = "Error: " . $validation_result;
        $_SESSION['message_type'] = 'danger';
    } else {
        // Check for collision (username OR email on a DIFFERENT user)
        $stmt = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
        $stmt->bind_param("ssi", $username_email, $username_email, $user_id);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $_SESSION['message'] = "Error: Email '{$username_email}' is already taken by another user.";
            $_SESSION['message_type'] = 'danger';
        } else {
            $stmt->close();
            // MODIFIED: Update BOTH username and email columns
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

// 3. DELETE USER (DELETE) - Unchanged
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

// 4. FETCH ALL USERS (READ) - Unchanged
$users = [];
$result = $conn->query("
    SELECT id AS user_id, full_name, username, email, role, status, created_at
    FROM users
    ORDER BY username
");

if ($result) {
    $users = $result->fetch_all(MYSQLI_ASSOC);
}

// Session message handling
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message'], $_SESSION['message_type']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="css/admin_style.css" rel="stylesheet"> <style>
        /* Add styles from admin_dashboard.php here or link to a shared stylesheet */
        :root {
            --primary-gradient: linear-gradient(135deg, #7451eb 0%, #3498db 100%);
            --sidebar-width: 260px;
            --sidebar-min-width: 80px;
            --header-height: 82px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        }
        body { background-color: #F8F9FA; font-family: 'Inter', sans-serif; }
        .navbar { /* ... navbar styles ... */ }
        .sidebar { /* ... sidebar styles ... */ }
        .main-content { /* ... main-content styles ... */ }
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        .card-header-flex { display: flex; justify-content: space-between; align-items: center; }
        .card { border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        .table-responsive { margin-top: 1.5rem; }
        .table thead th { font-weight: 600; }
        .table .badge { font-size: 0.8rem; padding: 0.4em 0.6em; }
        .btn-action { margin-right: 5px; }
        
        /* Copy all necessary styles from admin_dashboard.php */
        /* This is just a minimal placeholder set */
        body { background-color: var(--bg-light); margin: 0; padding: 0; min-height: 100vh; font-family: 'Inter', sans-serif; display: flex; flex-direction: column; }
        .navbar { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.15); padding: 1rem 1.5rem; height: var(--header-height); position: fixed; top: 0; left: 0; right: 0; z-index: 1050; }
        .navbar-brand { /* ... */ }
        .user-dropdown .dropdown-toggle { 
            color: white; 
            display: flex; 
            align-items: center; 
            text-decoration: none; /* This removed the underline */
            padding: 8px 12px; 
            border-radius: 8px; 
            transition: var(--transition); 
        }
        .user-dropdown .dropdown-toggle:hover { 
            background-color: rgba(255, 255, 255, 0.1); 
        }
        .user-dropdown .dropdown-toggle .user-name { 
            font-weight: 600; 
            font-size: 0.95rem; 
        }
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
        /* ... other styles from dashboard ... */

        /* --- NEW: Accordion Sidebar Styles --- */
        .sidebar-nav .nav-link {
            display: flex;
            align-items: center;
        }
        .sidebar-nav .text-muted {
            padding: 10px 25px;
            font-size: 0.75rem;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.4);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .sidebar.minimized .sidebar-nav .text-muted {
            display: none;
        }
        .sidebar-nav .sub-menu {
            padding-left: 0; /* Remove default padding */
            margin: 0;
            list-style: none;
            background-color: rgba(0,0,0,0.15);
        }
        .sidebar-nav .sub-menu .nav-item {
            width: 100%;
        }
        .sidebar-nav .sub-menu .nav-link {
            padding: 12px 25px 12px 60px; /* Indent sub-items */
            font-size: 0.95rem;
            font-weight: 400;
            border-left: 5px solid transparent; /* Reset border */
            margin: 0;
        }
        .sidebar-nav .sub-menu .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: #1abc9c;
        }
        .sidebar-nav .sub-menu .nav-link.active {
            color: #1abc9c; /* Active color for sub-item */
            border-left-color: #1abc9c;
            background-color: rgba(0,0,0,0.1);
            font-weight: 500;
        }
        .sidebar-nav .nav-link .sidebar-chevron {
            font-size: 0.7rem;
            margin-left: auto; /* Push chevron to the right */
            transition: transform 0.3s ease;
        }
        .sidebar-nav .nav-link[aria-expanded="true"] .sidebar-chevron {
            transform: rotate(180deg);
        }
        .sidebar-nav .nav-link[aria-expanded="true"] {
            color: white;
            background: rgba(255, 255, 255, 0.05);
        }
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
        /* --- End of Accordion Styles --- */
    </style>
</head>
<body>
    
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="admin_dashboard.php">
                <img src="imageslogo.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white brand-heading" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light brand-subheading" style="font-size: 0.75rem;">Administrator Panel</small>
                </div>
            </a>
            <button class="navbar-toggler d-lg-none" type="button" id="mobileMenuToggle" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="dropdown user-dropdown ms-auto me-2 me-lg-0">
                <a href="#" class="dropdown-toggle" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle navbar-profile-icon"></i>
                    <span class="user-name d-none d-lg-inline"><?= htmlspecialchars($name); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="admin_profile.php"><i class="fas fa-user-circle"></i> Profile</a></li>
                    <li><a class="dropdown-item" href="Tournament_Manager_page.php" target="_blank"><i class="fas fa-globe"></i> View Public Site</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="login.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="sidebar" id="sidebar"> <button id="sidebarToggle" title="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
        
        <ul class="nav flex-column sidebar-nav">
            <li class="nav-item">
                <a class="nav-link <?php if ($current_page == 'admin_dashboard.php') echo 'active'; ?>" href="admin_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link <?php if ($is_management_page) echo 'active'; ?>" data-bs-toggle="collapse" href="#teamsCollapse" role="button" aria-expanded="<?php echo $is_management_page ? 'true' : 'false'; ?>" aria-controls="teamsCollapse">
                    <i class="fas fa-users me-2"></i> <span>Manage Colleges/Events</span> <i class="fas fa-chevron-down ms-auto sidebar-chevron"></i>
                </a>
                
                <div class="collapse <?php if ($is_management_page) echo 'show'; ?>" id="teamsCollapse">
                    <ul class="sub-menu">
                        
                        <li class="text-muted" style="padding: 10px 25px 5px 60px; margin-top: 5px; font-size: 0.75rem; font-weight: 600; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px;">
                            Management
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'colleges.php') echo 'active'; ?>" href="sd/colleges.php">
                                <span>Manage Colleges</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'events.php') echo 'active'; ?>" href="sd/events.php">
                                <span>Manage Events (L1-L3)</span>
                            </a>
                        </li>
                        
                        <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'Manage_Matches.php') echo 'active'; ?>" href="sd/Manage_Matches.php">
                                <span>Manage Matches</span>
                            </a>
                        </li>
                        
                        <li class="text-muted" style="padding: 10px 25px 5px 60px; margin-top: 10px; font-size: 0.75rem; font-weight: 600; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px;">
                            Tallying
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'results.php') echo 'active'; ?>" href="sd/results.php">
                                <span>Approve Results</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'reports.php') echo 'active'; ?>" href="sd/reports.php">
                                <span>Medal Reports</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php if ($current_page == 'Manage_Users.php') echo 'active'; ?>" href="Manage_Users.php">
                    <i class="fas fa-users-cog me-2"></i> <span>Manage Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php if ($current_page == 'Manage_medals.php') echo 'active'; ?>" href="Manage_medals.php">
                    <i class="fas fa-medal me-2"></i> <span>Manage Medals</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php if ($current_page == 'Manage_Requests.php') echo 'active'; ?>" href="Manage_Requests.php">
                    <i class="fas fa-user-plus me-2"></i> <span>Account Requests</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php if ($current_page == 'Manage_Viewreports.php') echo 'active'; ?>" href="Manage_Viewreports.php">
                    <i class="fas fa-chart-line me-2"></i> <span>View Reports</span>
                </a>
            </li>
            
            <li class="nav-item mt-3">
                <a class="nav-link text-danger" href="login.php">
                    <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
    
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-content">
        <div class="container-fluid">
            
            <h1 class="section-title mb-4">Manage Users</h1>
            
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header card-header-flex">
                    <h5 class="mb-0">All System Users</h5>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="fas fa-plus me-2"></i>Add New User
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Full Name</th>
                                    <th>Email / Username</th> <th>Role</th>
                                    <th>Status</th>
                                    <th>Created On</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $index => $user): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($user['full_name']) ?></td>
                                    <td><?= htmlspecialchars($user['username']) ?></td> <td><?= htmlspecialchars($user['role']) ?></td>
                                    <td>
                                        <?php if ($user['status'] == 'active'): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php elseif ($user['status'] == 'inactive'): ?>
                                            <span class="badge bg-warning text-dark">Inactive</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($user['status']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('M d, Y', strtotime($user['created_at'])) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary btn-action edit-btn"
                                            data-bs-toggle="modal" data-bs-target="#editUserModal"
                                            data-id="<?= $user['user_id'] ?>"
                                            data-name="<?= htmlspecialchars($user['full_name']) ?>"
                                            data-username="<?= htmlspecialchars($user['username']) ?>" data-role="<?= $user['role'] ?>"
                                            data-status="<?= $user['status'] ?>"
                                            title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                        <button class="btn btn-sm btn-outline-danger btn-action delete-btn"
                                            data-bs-toggle="modal" data-bs-target="#deleteUserModal"
                                            data-id="<?= $user['user_id'] ?>"
                                            data-name="<?= htmlspecialchars($user['full_name']) ?>"
                                            title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">No users found.</td> </tr>
                                <?php endif; ?>
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

    <div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addUserModalLabel">Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="Manage_Users.php" method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="username" class="form-label">Email Address (@gmail.com only)</label>
                            <input type="email" class="form-control" id="username" name="username" required 
                                   pattern=".+@gmail\.com" title="Please enter a valid @gmail.com address."
                                   placeholder="example@gmail.com">
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label for="role" class="form-label">Role</label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="Event Manager">Event Manager</option>
                                <option value="Sports Director">Sports Director</option>
                                <option value="Administrator">Administrator</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
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

    <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editUserModalLabel">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="Manage_Users.php" method="POST">
                    <input type="hidden" name="edit_user_id" id="edit_user_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="edit_full_name" name="edit_full_name" required>
                        </div>

                        <div class="mb-3">
                            <label for="edit_username" class="form-label">Email Address (@gmail.com only)</label>
                            <input type="email" class="form-control" id="edit_username" name="edit_username" required
                                   pattern=".+@gmail\.com" title="Please enter a valid @gmail.com address.">
                        </div>

                        <div class="mb-3">
                            <label for="edit_role" class="form-label">Role</label>
                            <select class="form-select" id="edit_role" name="edit_role" required>
                                <option value="Event Manager">Event Manager</option>
                                <option value="Sports Director">Sports Director</option>
                                <option value="Administrator">Administrator</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_status" class="form-label">Status</label>
                            <select class="form-select" id="edit_status" name="edit_status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                        <small class="text-muted">Password can be changed by the user or reset via a separate feature.</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="edit_user" class="btn btn-primary">Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteUserModalLabel">Delete User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="Manage_Users.php" method="POST">
                    <input type="hidden" name="delete_user_id" id="delete_user_id">
                    <div class="modal-body">
                        <p>Are you sure you want to delete this user: <strong id="delete_user_name"></strong>?</p>
                        <p class="text-danger">This action is irreversible.</p>
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
            
            // --- NEW: Smooth Fade-in Effect ---
            setTimeout(() => {
                const mainContent = document.querySelector('.main-content');
                if(mainContent) {
                    mainContent.style.opacity = '1';
                    mainContent.style.transform = 'translateY(0)';
                }
            }, 50); // 50ms delay
            
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            
            // Desktop Sidebar Toggle
            if (window.innerWidth > 992 && sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('minimized');
                });
            }

            // Mobile Menu Toggle
            if (mobileMenuToggle) {
                mobileMenuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                    sidebarOverlay.classList.toggle('show');
                });
            }
            
            // Overlay Click - Close Sidebar
            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', function() {
                    sidebar.classList.remove('show');
                    sidebarOverlay.classList.remove('show');
                });
            }
            
            // Close sidebar when clicking a link on mobile
            document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 992) {
                        sidebar.classList.remove('show');
                        sidebarOverlay.classList.remove('show');
                    }
                });
            });

            // --- NEW: Accordion & Active Link Logic ---
            const currentPage = window.location.pathname.split('/').pop();
            const eventPages = ['Manage_Games.php', 'Manage_Game_Events.php', 'Manage_Categories.php'];
            const eventsCollapse = document.getElementById('eventsCollapse');
            const eventsToggleLink = document.querySelector('a[href="#eventsCollapse"]');

            if (eventPages.includes(currentPage)) {
                // 1. Show the collapse menu
                if(eventsCollapse) {
                    eventsCollapse.classList.add('show');
                }
                // 2. Set the main "Manage Events" link to active
                if(eventsToggleLink) {
                    eventsToggleLink.classList.add('active');
                    eventsToggleLink.setAttribute('aria-expanded', 'true');
                }
                // 3. Set the specific sub-page link to active
                const activeSubLink = document.querySelector(`.sub-menu .nav-link[href="${currentPage}"]`);
                if(activeSubLink) {
                    activeSubLink.classList.add('active');
                }
            } else {
                // 4. Handle all other top-level links
                document.querySelectorAll('.sidebar-nav > .nav-item > .nav-link').forEach(link => {
                    // Check if link.href exists and ends with the currentPage
                    if (link.href && link.href.endsWith(currentPage)) {
                        link.classList.add('active');
                    } else if (link !== eventsToggleLink) { 
                        link.classList.remove('active');
                    }
                });
            }
            // --- End of New Logic ---

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

            // --- START: MODAL SCRIPT FIX ---
            // This section is corrected to target 'editUserModal' and 'deleteUserModal'
            
            // 1. Edit User Modal Handler
            const editUserModal = document.getElementById('editUserModal');
            if (editUserModal) {
                editUserModal.addEventListener('show.bs.modal', function (event) {
                    // Button that triggered the modal
                    const button = event.relatedTarget;
                    
                    // Extract info from data-* attributes
                    const id = button.getAttribute('data-id');
                    const name = button.getAttribute('data-name');
                    const username = button.getAttribute('data-username');
                    const role = button.getAttribute('data-role');
                    const status = button.getAttribute('data-status');

                    // Update the modal's form fields
                    document.getElementById('edit_user_id').value = id;
                    document.getElementById('edit_full_name').value = name;
                    document.getElementById('edit_username').value = username;
                    document.getElementById('edit_role').value = role;
                    document.getElementById('edit_status').value = status;
                });
            }

            // 2. Delete User Modal Handler
            const deleteUserModal = document.getElementById('deleteUserModal');
            if (deleteUserModal) {
                deleteUserModal.addEventListener('show.bs.modal', function (event) {
                    // Button that triggered the modal
                    const button = event.relatedTarget;
                    
                    // Extract info from data-* attributes
                    const id = button.getAttribute('data-id');
                    const name = button.getAttribute('data-name');

                    // Update the modal's content
                    document.getElementById('delete_user_id').value = id;
                    document.getElementById('delete_user_name').textContent = name;
                });
            }
            // --- END: MODAL SCRIPT FIX ---

        });
    </script>
</body>
</html>