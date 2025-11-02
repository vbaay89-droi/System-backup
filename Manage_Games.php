<?php
session_start();
require_once 'db_connect.php'; 

// Security Check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Administrator') {
    header('Location: login.php');
    exit();
}

$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin';
$current_page = basename($_SERVER['PHP_SELF']);
$message = '';
$message_type = '';

// --- FIX: Logic to keep accordion open ---
$event_pages = ['Manage_Games.php', 'Manage_Game_Events.php', 'Manage_Categories.php'];
$is_event_page = in_array($current_page, $event_pages);
// --- End Fix ---

// --- ACTION LOGIC ---
// 1. ADD GAME
if (isset($_POST['add_game'])) {
    $game_name = $_POST['game_name'];
    $stmt = $conn->prepare("INSERT INTO games (game_name) VALUES (?)");
    $stmt->bind_param("s", $game_name);
    if ($stmt->execute()) {
        $_SESSION['message'] = "Game '{$game_name}' added successfully.";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = "Error: " . $stmt->error;
        $_SESSION['message_type'] = 'danger';
    }
    $stmt->close();
    header("Location: Manage_Games.php");
    exit();
}

// 2. EDIT GAME
if (isset($_POST['edit_game'])) {
    $game_id = (int)$_POST['edit_game_id'];
    $game_name = $_POST['edit_game_name'];
    $stmt = $conn->prepare("UPDATE games SET game_name = ? WHERE game_id = ?");
    $stmt->bind_param("si", $game_name, $game_id);
    if ($stmt->execute()) {
        $_SESSION['message'] = "Game updated successfully.";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = "Error: " . $stmt->error;
        $_SESSION['message_type'] = 'danger';
    }
    $stmt->close();
    header("Location: Manage_Games.php");
    exit();
}

// 3. DELETE GAME
if (isset($_POST['delete_game'])) {
    $game_id = (int)$_POST['delete_game_id'];
    $stmt = $conn->prepare("DELETE FROM games WHERE game_id = ?");
    $stmt->bind_param("i", $game_id);
    if ($stmt->execute()) {
        $_SESSION['message'] = "Game deleted successfully.";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = "Error: " . $stmt->error;
        $_SESSION['message_type'] = 'danger';
    }
    $stmt->close();
    header("Location: Manage_Games.php");
    exit();
}

// --- FETCH DATA (READ) ---
$games = [];
$result = $conn->query("SELECT * FROM games ORDER BY game_name");
if ($result) {
    $games = $result->fetch_all(MYSQLI_ASSOC);
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
    <title>Manage Games (L1) - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #4CAF50;
            --primary-dark: #2E7D32;
            --accent-gold: #FFD700;
            --bg-light: #F8F9FA;
            --text-dark: #1A1A1A;
            --text-muted: #6C757D;
            --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --primary-gradient: linear-gradient(135deg, #7451eb 0%, #3498db 100%);
            --card-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            --card-hover-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            --sidebar-width: 260px; /* Full Width */
            --sidebar-min-width: 80px; /* Minimized Width */
            --header-height: 82px;  
        }
        body {
            background-color: var(--bg-light);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            display: flex;
            flex-direction: column;
        }
        .navbar {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            padding: 1rem 1.5rem;
            height: var(--header-height);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1050;
        }
        .navbar-brand { transition: var(--transition); }
        .navbar-brand:hover { transform: translateY(-2px); }
        .brand-logo { filter: drop-shadow(0 2px 4px rgba(255,255,255,0.1)); }
        .brand-heading { font-family: 'Poppins', sans-serif; font-weight: 700; letter-spacing: -0.5px; }
        
        /* User Dropdown */
        .user-dropdown .dropdown-toggle { color: white; display: flex; align-items: center; text-decoration: none; padding: 8px 12px; border-radius: 8px; transition: var(--transition); }
        .user-dropdown .dropdown-toggle:hover { background-color: rgba(255, 255, 255, 0.1); }
        .user-dropdown .dropdown-toggle img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,0.3); margin-right: 10px; }
        .user-dropdown .dropdown-toggle .user-name { font-weight: 600; font-size: 0.95rem; }
        .user-dropdown .dropdown-menu { border: none; box-shadow: var(--shadow-md); border-radius: 10px; padding: 0.5rem 0; margin-top: 10px !important; }
        .user-dropdown .dropdown-item { display: flex; align-items: center; padding: 0.75rem 1.25rem; font-weight: 500; color: #333; font-size: 0.9rem; }
        .user-dropdown .dropdown-item i { width: 20px; margin-right: 10px; color: var(--text-muted); }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            position: fixed;
            top: var(--header-height);
            left: 0;
            height: calc(100vh - var(--header-height));
            background: #2c3e50;
            color: white;
            box-shadow: 5px 0 15px rgba(0,0,0,0.2);
            z-index: 1040;
            transition: width var(--transition);
            overflow-y: auto;
            overflow-x: hidden;
        }
        .sidebar.minimized { width: var(--sidebar-min-width); }
        #sidebarToggle {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            font-size: 1.25rem;
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 5px;
            transition: all 0.3s ease;
            z-index: 10;
        }
        #sidebarToggle:hover { background: rgba(255, 255, 255, 0.2); transform: scale(1.05); }
        .sidebar-nav { padding: 50px 0 20px 0; }
        .sidebar-nav .nav-link {
            color: rgba(255, 255, 255, 0.7);
            font-size: 1.05rem;
            font-weight: 500;
            padding: 15px 25px;
            transition: var(--transition);
            border-left: 5px solid transparent;
            margin: 2px 0;
            display: flex;
            align-items: center;
            text-decoration: none;
        }
        .sidebar-nav .nav-link i { width: 30px; text-align: center; flex-shrink: 0; font-size: 0.95em; }
        .sidebar.minimized .sidebar-nav .nav-link span,
        .sidebar.minimized .sidebar-nav .sidebar-chevron,
        .sidebar.minimized .sidebar-nav .text-muted {
            display: none;
        }
        .sidebar.minimized .sidebar-nav .nav-link { justify-content: center; padding: 15px 0; }
        .sidebar.minimized #sidebarToggle { right: 50%; transform: translateX(50%); }
        .sidebar-nav .nav-link:hover { color: white; background: rgba(255, 255, 255, 0.05); border-left-color: #1abc9c; }
        .sidebar-nav .nav-link.active { color: white; background: rgba(255, 255, 255, 0.1); border-left-color: #3498db; font-weight: 600; }
        .sidebar-nav .text-muted { padding: 10px 25px; font-size: 0.75rem; font-weight: 600; color: rgba(255, 255, 255, 0.4); text-transform: uppercase; letter-spacing: 1px; }

        /* --- FIX: Accordion CSS (must be in all files) --- */
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
        /* --- End of Accordion Styles --- */
        
        /* Main Content */
        .main-content {
            flex: 1 0 auto;
            padding: 30px;
            margin-top: var(--header-height);
            margin-left: var(--sidebar-width);
            transition: margin-left var(--transition), opacity 0.5s ease-out, transform 0.5s ease-out;
            min-height: calc(100vh - var(--header-height));
            opacity: 0;
            transform: translateY(10px);
        }
        .sidebar.minimized ~ .main-content { margin-left: var(--sidebar-min-width); }
        .section-title { font-family: 'Poppins', sans-serif; font-weight: 600; color: #333; }
        .card { border: none; border-radius: 15px; box-shadow: var(--card-shadow); }
        .card-header-flex { display: flex; justify-content: space-between; align-items: center; }
        
        /* Footer */
        footer { flex-shrink: 0; background: #2c3e50 !important; box-shadow: 0 -2px 10px rgba(0,0,0,0.1); margin-left: var(--sidebar-width); transition: margin-left var(--transition); position: relative; z-index: 1041; }
        .sidebar.minimized ~ footer { margin-left: var(--sidebar-min-width); }
        
        /* Mobile */
        .sidebar-overlay { display: none; position: fixed; top: var(--header-height); left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1030; }
        .sidebar-overlay.show { display: block; }
        @media (max-width: 992px) {
            .sidebar { width: 260px; left: -260px; top: var(--header-height); height: calc(100vh - var(--header-height)); transition: left 0.3s ease; z-index: 1045; }
            .sidebar.show { left: 0; }
            .main-content { margin-left: 0; padding: 20px; }
            .sidebar.minimized ~ .main-content { margin-left: 0; }
            #sidebarToggle { display: none; }
            footer { margin-left: 0; }
            .sidebar.minimized ~ footer { margin-left: 0; }
        }
        @media (max-width: 576px) {
            .main-content { padding: 15px; }
            .user-dropdown .dropdown-toggle .user-name { display: none; }
            .user-dropdown .dropdown-toggle img { margin-right: 0; }
        }
    </style>
</head>
<body>
    
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid d-flex align-items: center justify-content-between">
            <a class="navbar-brand d-flex align-items: center" href="admin_dashboard.php" style="cursor: pointer;">
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
                    <img src="images/default_avatar.png" alt="User Avatar">
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
                <a class="nav-link <?php if ($current_page == 'admin_dashboard.php') echo 'active'; ?>" href="admin_dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span>
                </a>
            </li>

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
            <li class="nav-item">
                <a class="nav-link <?php if ($current_page == 'Manage_Team.php') echo 'active'; ?>" href="Manage_Team.php">
                    <i class="fas fa-users me-2"></i> <span>Manage Teams</span>
                </a>
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
                <a class="nav-link text-danger" href="logout.php">
                    <i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
    
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-content">
        <div class="container-fluid">
            
            <h1 class="section-title mb-4">Manage Games (Level 1)</h1>

            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-7">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0">All Games (e.g., Athletics, Ball Games)</h5>
                        </div>
                        <div class="card-body">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Game Name</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($games as $game): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($game['game_name']) ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary edit-btn"
                                                data-bs-toggle="modal" data-bs-target="#editGameModal"
                                                data-id="<?= $game['game_id'] ?>"
                                                data-name="<?= htmlspecialchars($game['game_name']) ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger delete-btn"
                                                data-bs-toggle="modal" data-bs-target="#deleteGameModal"
                                                data-id="<?= $game['game_id'] ?>"
                                                data-name="<?= htmlspecialchars($game['game_name']) ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Add New Game</h5>
                        </div>
                        <div class="card-body">
                            <form action="Manage_Games.php" method="POST">
                                <div class="mb-3">
                                    <label for="game_name" class="form-label">Game Name</label>
                                    <input type="text" class="form-control" id="game_name" name="game_name" required>
                                </div>
                                <button type="submit" name="add_game" class="btn btn-primary">Save Game</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editGameModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Game</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="Manage_Games.php" method="POST">
                    <input type="hidden" name="edit_game_id" id="edit_game_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_game_name" class="form-label">Game Name</label>
                            <input type="text" class="form-control" id="edit_game_name" name="edit_game_name" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="edit_game" class="btn btn-primary">Update Game</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="deleteGameModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Game</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="Manage_Games.php" method="POST">
                    <input type="hidden" name="delete_game_id" id="delete_game_id">
                    <div class="modal-body">
                        <p>Are you sure you want to delete this game: <strong id="delete_game_name"></strong>?</p>
                        <p class="text-danger">This will delete all associated events and categories.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_game" class="btn btn-danger">Delete</button>
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
                // Don't close sidebar if clicking the accordion toggle
                if (link.getAttribute('data-bs-toggle') !== 'collapse') {
                    link.addEventListener('click', function() {
                        if (window.innerWidth <= 992) {
                            sidebar.classList.remove('show');
                            sidebarOverlay.classList.remove('show');
                        }
                    });
                }
            });

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
            
            // --- Page-specific JS for this page ---
            const editGameModal = document.getElementById('editGameModal');
            if(editGameModal) {
                editGameModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    document.getElementById('edit_game_id').value = button.getAttribute('data-id');
                    document.getElementById('edit_game_name').value = button.getAttribute('data-name');
                });
            }
            
            const deleteGameModal = document.getElementById('deleteGameModal');
            if(deleteGameModal) {
                deleteGameModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    document.getElementById('delete_game_id').value = button.getAttribute('data-id');
                    document.getElementById('delete_game_name').textContent = button.getAttribute('data-name');
                });
            }
            // --- End of page-specific JS ---

        });
    </script>
</body>
</html>