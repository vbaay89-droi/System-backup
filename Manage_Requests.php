<?php
session_start();
require_once 'db_connect.php'; // DB connection

// Strict Role-Based Access Control
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Administrator') {
    header('Location: login.php');
    exit();
}

$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin';
$current_page = basename($_SERVER['PHP_SELF']);
$message = '';
$message_type = '';

// --- ACTION LOGIC (APPROVE / REJECT) ---

// 1. APPROVE REQUEST
if (isset($_GET['action']) && $_GET['action'] == 'approve' && isset($_GET['id'])) {
    $request_id = (int)$_GET['id'];
    
    // Use a transaction to ensure both operations succeed or fail together
    $conn->begin_transaction();
    
    try {
        // Step 1: Get the request details
        $stmt_get = $conn->prepare("SELECT full_name, username, requested_role FROM account_requests WHERE request_id = ? AND status = 'pending'");
        $stmt_get->bind_param("i", $request_id);
        $stmt_get->execute();
        $result = $stmt_get->get_result();
        
        if ($result->num_rows == 1) {
            $request = $result->fetch_assoc();
            
            // Set a default password
            $default_password = 'password123'; // User must be told to change this
            $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);
            $status = 'active'; // Or 'pending' if you want them to confirm email, etc.
            
            // Step 2: Create the user in the 'users' table
            $stmt_create = $conn->prepare("INSERT INTO users (full_name, username, password, role, status) VALUES (?, ?, ?, ?, ?)");
            $stmt_create->bind_param("sssss", $request['full_name'], $request['username'], $hashed_password, $request['requested_role'], $status);
            $stmt_create->execute();
            
            // Step 3: Update the request status
            $stmt_update = $conn->prepare("UPDATE account_requests SET status = 'approved' WHERE request_id = ?");
            $stmt_update->bind_param("i", $request_id);
            $stmt_update->execute();
            
            // If all queries succeeded, commit the transaction
            $conn->commit();
            $_SESSION['message'] = "Request for '{$request['username']}' approved. User account created.";
            $_SESSION['message_type'] = 'success';
            
        } else {
            // Request not found or already processed
            throw new Exception("Request not found or already processed.");
        }

    } catch (Exception $e) {
        // An error occurred, roll back the transaction
        $conn->rollback();
        // Check for duplicate username error (MySQL error code 1062)
        if ($conn->errno == 1062) {
             $_SESSION['message'] = "Error: A user with that username already exists.";
             // Set the request to 'rejected' to prevent it from being processed again
             $conn->query("UPDATE account_requests SET status = 'rejected' WHERE request_id = $request_id");
        } else {
            $_SESSION['message'] = "An error occurred: " . $e->getMessage();
        }
        $_SESSION['message_type'] = 'danger';
    }
    
    header("Location: Manage_Requests.php");
    exit();
}

// 2. REJECT REQUEST
if (isset($_GET['action']) && $_GET['action'] == 'reject' && isset($_GET['id'])) {
    $request_id = (int)$_GET['id'];
    
    $stmt = $conn->prepare("UPDATE account_requests SET status = 'rejected' WHERE request_id = ? AND status = 'pending'");
    $stmt->bind_param("i", $request_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $_SESSION['message'] = "Request has been rejected.";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = "Error: Could not process rejection or request already processed.";
        $_SESSION['message_type'] = 'danger';
    }
    $stmt->close();
    header("Location: Manage_Requests.php");
    exit();
}


// 3. FETCH REQUESTS (READ)
$pending_requests = [];
$processed_requests = [];

$result_pending = $conn->query("SELECT * FROM account_requests WHERE status = 'pending' ORDER BY created_at DESC");
if ($result_pending) {
    $pending_requests = $result_pending->fetch_all(MYSQLI_ASSOC);
}

$result_processed = $conn->query("SELECT * FROM account_requests WHERE status != 'pending' ORDER BY created_at DESC LIMIT 20");
if ($result_processed) {
    $processed_requests = $result_processed->fetch_all(MYSQLI_ASSOC);
}

// Check for session messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Account Requests - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="css/admin_style.css" rel="stylesheet"> <style>
        /* Copy all necessary styles from admin_dashboard.php */
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
        .card { border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        .table-responsive { margin-top: 1.5rem; }
        .table .badge { font-size: 0.8rem; padding: 0.4em 0.6em; }
        .btn-action { margin-right: 5px; }
        
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
        .sidebar { width: var(--sidebar-width); position: fixed; top: var(--header-height); left: 0; height: calc(100vh - var(--header-height)); background: #2c3e50; color: white; box-shadow: 5px 0 15px rgba(0,0,0,0.2); z-index: 1040; transition: width var(--transition); overflow-y: auto; overflow-x: hidden; }
        .sidebar-nav { padding: 50px 0 20px 0; }
        .sidebar-nav .nav-link { color: rgba(255, 255, 255, 0.7); font-size: 1.05rem; font-weight: 500; padding: 15px 25px; transition: var(--transition); border-left: 5px solid transparent; margin: 2px 0; display: flex; align-items: center; text-decoration: none; }
        .sidebar-nav .nav-link i { width: 30px; text-align: center; flex-shrink: 0; font-size: 0.95em; }
        .sidebar-nav .nav-link:hover { color: white; background: rgba(255, 255, 255, 0.05); border-left-color: #1abc9c; }
        .sidebar-nav .nav-link.active { color: white; background: rgba(255, 255, 255, 0.1); border-left-color: #3498db; font-weight: 600; }
        .main-content { flex: 1 0 auto; padding: 30px; margin-top: var(--header-height); margin-left: var(--sidebar-width); transition: margin-left var(--transition); min-height: calc(100vh - var(--header-height)); }
        footer { flex-shrink: 0; background: #2c3e50 !important; box-shadow: 0 -2px 10px rgba(0,0,0,0.1); margin-left: var(--sidebar-width); transition: margin-left var(--transition); position: relative; z-index: 1041; }
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
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="sidebar" id="sidebar">
        <button id="sidebarToggle" title="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
        
        <ul class="nav flex-column sidebar-nav">
            <li class="nav-item">
                <a class="nav-link" href="admin_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                </a>
            <li class="nav-item">
                <a class="nav-link <?php if ($is_event_page) echo 'active'; ?>" data-bs-toggle="collapse" href="#eventsCollapse" role="button" aria-expanded="<?php echo $is_event_page ? 'true' : 'false'; ?>" aria-controls="eventsCollapse">
                    <i class="fas fa-calendar-alt me-2"></i> <span>Manage Events</span> <i class="fas fa-chevron-down ms-auto sidebar-chevron"></i>
                </a>
                <div class="collapse <?php if ($is_event_page) echo 'show'; ?>" id="eventsCollapse">
                    <ul class="sub-menu">
                        <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'Manage_Games.php') echo 'active'; ?>" href="Manage_Games.php">
                                <span>Games (L1)</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'Manage_Game_Events.php') echo 'active'; ?>" href="Manage_Game_Events.php">
                                <span>Game Events (L2)</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php if ($current_page == 'Manage_Categories.php') echo 'active'; ?>" href="Manage_Categories.php">
                                <span>Categories (L3)</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            
            </li>
            <li class="nav-item">
                <a class="nav-link" href="Manage_Team.php">
                    <i class="fas fa-users me-2"></i> <span>Manage Teams</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="Manage_Users.php">
                    <i class="fas fa-users-cog me-2"></i> <span>Manage Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="Manage_medals.php">
                    <i class="fas fa-medal me-2"></i> <span>Manage Medals</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="Manage_Requests.php">
                    <i class="fas fa-user-plus me-2"></i> <span>Account Requests</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="Manage_Viewreports.php">
                    <i class="fas fa-chart-line me-2"></i> <span>View Reports</span>
                </a>
            </li>
            
            <li class="nav-item mt-3"><span class="text-muted">Event Settings</span></li>
            <li class="nav-item mt-3">
                <a class="nav-link text-danger" href="logout.php">
                    <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
    <div class="main-content">
        <div class="container-fluid">
            
            <h1 class="section-title mb-4">Manage Account Requests</h1>
            
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Pending Requests</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Full Name</th>
                                    <th>Username</th>
                                    <th>Requested Role</th>
                                    <th>Date Requested</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_requests as $request): ?>
                                <tr>
                                    <td><?= htmlspecialchars($request['full_name']) ?></td>
                                    <td><?= htmlspecialchars($request['username']) ?></td>
                                    <td><span class="badge bg-info text-dark"><?= htmlspecialchars($request['requested_role']) ?></span></td>
                                    <td><?= date('M d, Y h:i A', strtotime($request['created_at'])) ?></td>
                                    <td>
                                        <a href="Manage_Requests.php?action=approve&id=<?= $request['request_id'] ?>" class="btn btn-sm btn-success btn-action" title="Approve" onclick="return confirm('Are you sure you want to approve this request and create the user account?')">
                                            <i class="fas fa-check"></i> Approve
                                        </a>
                                        <a href="Manage_Requests.php?action=reject&id=<?= $request['request_id'] ?>" class="btn btn-sm btn-danger btn-action" title="Reject" onclick="return confirm('Are you sure you want to reject this request?')">
                                            <i class="fas fa-times"></i> Reject
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($pending_requests)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted">No pending requests.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Processed Requests (Last 20)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Full Name</th>
                                    <th>Username</th>
                                    <th>Requested Role</th>
                                    <th>Date Requested</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($processed_requests as $request): ?>
                                <tr>
                                    <td><?= htmlspecialchars($request['full_name']) ?></td>
                                    <td><?= htmlspecialchars($request['username']) ?></td>
                                    <td><?= htmlspecialchars($request['requested_role']) ?></td>
                                    <td><?= date('M d, Y', strtotime($request['created_at'])) ?></td>
                                    <td>
                                        <?php if ($request['status'] == 'approved'): ?>
                                            <span class="badge bg-success">Approved</span>
                                        <?php elseif ($request['status'] == 'rejected'): ?>
                                            <span class="badge bg-danger">Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($processed_requests)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted">No processed requests found.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <footer class="bg-dark text-white py-4">
        </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Sidebar toggle logic (copied from dashboard)
            // ... (include all sidebar JS logic here) ...
        });
    </script>
</body>
</html>