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

// --- ACTION LOGIC ---

// 1. UPDATE MEDAL POINTS
if (isset($_POST['update_points'])) {
    $gold = (int)$_POST['points_gold'];
    $silver = (int)$_POST['points_silver'];
    $bronze = (int)$_POST['points_bronze'];

    // Use prepared statements to update each setting
    $stmt_gold = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'medal_points_gold'");
    $stmt_gold->bind_param("s", $gold);
    $stmt_gold->execute();
    
    $stmt_silver = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'medal_points_silver'");
    $stmt_silver->bind_param("s", $silver);
    $stmt_silver->execute();
    
    $stmt_bronze = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'medal_points_bronze'");
    $stmt_bronze->bind_param("s", $bronze);
    $stmt_bronze->execute();

    $_SESSION['message'] = "Medal points updated successfully.";
    $_SESSION['message_type'] = 'success';
    header("Location: system_config.php");
    exit();
}

// 2. ADD GAME
if (isset($_POST['add_game'])) {
    $game_name = $_POST['game_name'];
    $stmt = $conn->prepare("INSERT INTO games (game_name) VALUES (?)");
    $stmt->bind_param("s", $game_name);
    if ($stmt->execute()) {
        $_SESSION['message'] = "Game '{$game_name}' added successfully.";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = ($conn->errno == 1062) ? "Error: That game name already exists." : "Error: " . $stmt->error;
        $_SESSION['message_type'] = 'danger';
    }
    $stmt->close();
    header("Location: system_config.php");
    exit();
}

// 3. EDIT GAME
if (isset($_POST['edit_game'])) {
    $game_id = (int)$_POST['edit_game_id'];
    $game_name = $_POST['edit_game_name'];
    
    $stmt = $conn->prepare("UPDATE games SET game_name = ? WHERE game_id = ?");
    $stmt->bind_param("si", $game_name, $game_id);
    if ($stmt->execute()) {
        $_SESSION['message'] = "Game updated successfully.";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = ($conn->errno == 1062) ? "Error: That game name already exists." : "Error: " . $stmt->error;
        $_SESSION['message_type'] = 'danger';
    }
    $stmt->close();
    header("Location: system_config.php");
    exit();
}

// 4. DELETE GAME
if (isset($_POST['delete_game'])) {
    $game_id = (int)$_POST['delete_game_id'];
    
    $stmt = $conn->prepare("DELETE FROM games WHERE game_id = ?");
    $stmt->bind_param("i", $game_id);
    if ($stmt->execute()) {
        $_SESSION['message'] = "Game deleted successfully.";
        $_SESSION['message_type'] = 'success';
    } else {
        // Handle foreign key constraint error (if events are linked to this game)
        $_SESSION['message'] = ($conn->errno == 1451) ? "Error: Cannot delete game. It is linked to one or more events." : "Error: " . $stmt->error;
        $_SESSION['message_type'] = 'danger';
    }
    $stmt->close();
    header("Location: system_config.php");
    exit();
}


// --- FETCH DATA (READ) ---
$settings = [];
$result_settings = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($result_settings) {
    while ($row = $result_settings->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}
// Set defaults if not in DB
$points_gold = $settings['medal_points_gold'] ?? 5;
$points_silver = $settings['medal_points_silver'] ?? 3;
$points_bronze = $settings['medal_points_bronze'] ?? 1;

$games = [];
$result_games = $conn->query("SELECT * FROM games ORDER BY game_name");
if ($result_games) {
    $games = $result_games->fetch_all(MYSQLI_ASSOC);
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
    <title>System Configuration - Admin Dashboard</title>
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
        .card-header-flex { display: flex; justify-content: space-between; align-items: center; }
        
        body { background-color: var(--bg-light); margin: 0; padding: 0; min-height: 100vh; font-family: 'Inter', sans-serif; display: flex; flex-direction: column; }
        .navbar { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.15); padding: 1rem 1.5rem; height: var(--header-height); position: fixed; top: 0; left: 0; right: 0; z-index: 1050; }
        .navbar-brand { /* ... */ }
        .user-dropdown { /* ... */ }
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
        <button id="sidebarToggle" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <ul class="nav flex-column sidebar-nav">
            <li class="nav-item"><a class="nav-link" href="admin_dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> <span>Dashboard</span></a></li>
            <li class="nav-item"><a class="nav-link" href="Manage_Event.php"><i class="fas fa-calendar-alt me-2"></i> <span>Manage Events</span></a></li>
            <li class="nav-item"><a class="nav-link" href="Manage_Team.php"><i class="fas fa-users me-2"></i> <span>Manage Teams</span></a></li>
            <li class="nav-item"><a class="nav-link" href="Manage_Users.php"><i class="fas fa-users-cog me-2"></i> <span>Manage Users</span></a></li>
            <li class="nav-item"><a class="nav-link" href="Manage_medals.php"><i class="fas fa-medal me-2"></i> <span>Manage Medals</span></a></li>
            <li class="nav-item"><a class="nav-link" href="Manage_Requests.php"><i class="fas fa-user-plus me-2"></i> <span>Account Requests</span></a></li>
            <li class="nav-item"><a class="nav-link" href="Manage_Viewreports.php"><i class="fas fa-chart-line me-2"></i> <span>View Reports</span></a></li>
            <li class="nav-item"><a class="nav-link active" href="system_config.php"><i class="fas fa-cogs me-2"></i> <span>System Config</span></a></li>
            <li class="nav-item mt-3"><a class="nav-link text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> <span>Logout</span></a></li>
        </ul>
    </div>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-content">
        <div class="container-fluid">
            
            <h1 class="section-title mb-4">System Configuration</h1>

            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Medal Point Values</h5>
                        </div>
                        <div class="card-body">
                            <form action="system_config.php" method="POST">
                                <div class="mb-3">
                                    <label for="points_gold" class="form-label"><i class="fas fa-medal text-warning me-2"></i>Gold Medal Points</label>
                                    <input type="number" class="form-control" id="points_gold" name="points_gold" value="<?= htmlspecialchars($points_gold) ?>" min="0" required>
                                </div>
                                <div class="mb-3">
                                    <label for="points_silver" class="form-label"><i class="fas fa-medal text-secondary me-2"></i>Silver Medal Points</label>
                                    <input type="number" class="form-control" id="points_silver" name="points_silver" value="<?= htmlspecialchars($points_silver) ?>" min="0" required>
                                </div>
                                <div class="mb-3">
                                    <label for="points_bronze" class="form-label"><i class="fas fa-medal text-danger me-2"></i>Bronze Medal Points</label>
                                    <input type="number" class="form-control" id="points_bronze" name="points_bronze" value="<?= htmlspecialchars($points_bronze) ?>" min="0" required>
                                </div>
                                <button type="submit" name="update_points" class="btn btn-primary w-100">Save Point Settings</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header card-header-flex">
                            <h5 class="mb-0">Manage Games</h5>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addGameModal">
                                <i class="fas fa-plus me-2"></i>Add Game
                            </button>
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
                                            <button class="btn btn-sm btn-outline-primary btn-action edit-game-btn"
                                                data-bs-toggle="modal" data-bs-target="#editGameModal"
                                                data-id="<?= $game['game_id'] ?>"
                                                data-name="<?= htmlspecialchars($game['game_name']) ?>"
                                                title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger btn-action delete-game-btn"
                                                data-bs-toggle="modal" data-bs-target="#deleteGameModal"
                                                data-id="<?= $game['game_id'] ?>"
                                                data-name="<?= htmlspecialchars($game['game_name']) ?>"
                                                title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($games)): ?>
                                    <tr>
                                        <td colspan="2" class="text-center text-muted">No games defined.</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <footer class="bg-dark text-white py-4">
        </footer>

    <div class="modal fade" id="addGameModal" tabindex="-1" aria-labelledby="addGameModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addGameModalLabel">Add New Game</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="system_config.php" method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="game_name" class="form-label">Game Name</label>
                            <input type="text" class="form-control" id="game_name" name="game_name" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="add_game" class="btn btn-primary">Save Game</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="editGameModal" tabindex="-1" aria-labelledby="editGameModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editGameModalLabel">Edit Game</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="system_config.php" method="POST">
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

    <div class="modal fade" id="deleteGameModal" tabindex="-1" aria-labelledby="deleteGameModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteGameModalLabel">Delete Game</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="system_config.php" method="POST">
                    <input type="hidden" name="delete_game_id" id="delete_game_id">
                    <div class="modal-body">
                        <p>Are you sure you want to delete this game: <strong id="delete_game_name"></strong>?</p>
                        <p class="text-danger">This may fail if the game is already linked to events.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_game" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Sidebar toggle logic (copied from dashboard)
            // ... (include all sidebar JS logic here) ...

            // Edit Game Modal
            const editGameModal = document.getElementById('editGameModal');
            editGameModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                document.getElementById('edit_game_id').value = button.getAttribute('data-id');
                document.getElementById('edit_game_name').value = button.getAttribute('data-name');
            });

            // Delete Game Modal
            const deleteGameModal = document.getElementById('deleteGameModal');
            deleteGameModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                document.getElementById('delete_game_id').value = button.getAttribute('data-id');
                document.getElementById('delete_game_name').textContent = button.getAttribute('data-name');
            });
        });
    </script>
</body>
</html>