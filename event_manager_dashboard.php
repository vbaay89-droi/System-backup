<?php
session_start();

// 1. DATABASE CONNECTION (MySQLi)
require_once 'config.php';

// 2. SESSION CHECK (Protected Page)
if (
    !isset($_SESSION['logged_in']) || 
    $_SESSION['logged_in'] !== true || 
    !isset($_SESSION['role']) || 
    $_SESSION['role'] !== 'Event Manager'
) {
    header('Location: login.php');
    exit();
}

// 3. GET USER INFO FROM SESSION
$user_id = $_SESSION['user_id'];
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Event Manager';
$current_page = basename($_SERVER['PHP_SELF']);

// 4. FORM HANDLING (MySQLi Syntax)
$alert_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        try { // This is the 'try' from line 29
            if ($action === 'save_category') {
                $category_name = $_POST['category_name'];
                $status = $_POST['status'];
                $event_id = $_POST['event_id'];
                $category_id = $_POST['category_id']; // Empty for "add"

                if (empty($category_id)) {
                    $stmt = $conn->prepare("INSERT INTO Categories (event_id, category_name, status) VALUES (?, ?, ?)");
                    $stmt->bind_param("iss", $event_id, $category_name, $status); 
                    $stmt->execute(); 
                    $alert_message = "SUCCESS: Category '{$category_name}' was created!";
                } else {
                    $stmt = $conn->prepare("UPDATE Categories SET category_name = ?, status = ? WHERE category_id = ?");
                    $stmt->bind_param("ssi", $category_name, $status, $category_id); 
                    $stmt->execute(); 
                    $alert_message = "SUCCESS: Category '{$category_name}' was updated!";
                }
                $stmt->close(); 

            } 
            
            elseif ($action === 'submit_results_for_approval') { // Renamed action for clarity
                $gold = $_POST['gold_winner_id'] ?: null;
                $silver = $_POST['silver_winner_id'] ?: null;
                $bronze = $_POST['bronze_winner_id'] ?: null;
                $category_id = (int)$_POST['category_id'];
                $manager_user_id = (int)$_SESSION['user_id']; // The EM's ID

                // Use a transaction for safety
                $conn->begin_transaction();
                try {
                    // 1. Insert a record into the 'results' table for the SD to approve
                    // This is the correct action to perform
                    $stmt_results = $conn->prepare(
                        "INSERT INTO results 
                         (category_id, submitted_by_user_id, status, winner_gold_team_id, winner_silver_team_id, winner_bronze_team_id, last_updated) 
                         VALUES (?, ?, 'Pending', ?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE -- If they resubmit, update the pending entry
                         submitted_by_user_id = VALUES(submitted_by_user_id),
                         status = 'Pending',
                         winner_gold_team_id = VALUES(winner_gold_team_id),
                         winner_silver_team_id = VALUES(winner_silver_team_id),
                         winner_bronze_team_id = VALUES(winner_bronze_team_id),
                         last_updated = NOW(),
                         notes = NULL, -- Clear any previous rejection notes
                         approved_by_user_id = NULL,
                         approved_at = NULL"
                    );
                    $stmt_results->bind_param("iiiii", $category_id, $manager_user_id, $gold, $silver, $bronze);
                    $stmt_results->execute();
                    $stmt_results->close();

                    // 2. Update the category status to show it's awaiting approval
                    $stmt_cat = $conn->prepare("UPDATE Categories SET status = 'Pending Approval' WHERE category_id = ?");
                    $stmt_cat->bind_param("i", $category_id);
                    $stmt_cat->execute();
                    $stmt_cat->close();
                    
                    $conn->commit();
                    $alert_message = "SUCCESS: Results have been submitted for approval!";

                } catch (Exception $e) {
                    $conn->rollback();
                    $alert_message = "ERROR: Could not submit results. " . $e->getMessage();
                    error_log($e->getMessage());
                }
            }
            
            // --- START OF FIX ---
            // You were missing the 'catch' block for the 'try' on line 29
        } catch (Exception $e) { 
            $alert_message = "ERROR: Could not save data. Database error.";
            error_log($e->getMessage()); 
        }
        // You were missing the closing brace for 'if (isset($_POST['action']))'
    }
// You were missing the closing brace for 'if ($_SERVER['REQUEST_METHOD'] === 'POST')'
} 
// --- END OF FIX ---


// 5. FETCH REAL DATA FROM DATABASE (MySQLi Syntax)

// A. Fetch all Teams (for medal dropdowns)
$teams = [];
try {
    
    // --- FIX #1: Changed 'college' to 'dean_name' ---
    // This query was failing because the 'college' column does not exist.
    // We are now fetching 'dean_name' which exists in your 'teams' table.
    $team_stmt = $conn->prepare("SELECT team_id, team_name, dean_name FROM teams ORDER BY team_name");
    $team_stmt->execute(); 
    
    $result = $team_stmt->get_result();
    $teams = $result->fetch_all(MYSQLI_ASSOC); 
    $team_stmt->close();

} catch (Exception $e) { 
    $alert_message = "ERROR: Could not load teams list.";
    error_log($e->getMessage());
}

// B. Fetch this Manager's ASSIGNED data
$managed_data = [];
try {
    $sql = "SELECT
        g.game_name,
        ge.event_id, ge.event_name,
        c.category_id, c.category_name, c.status,
        c.gold_winner_id, c.silver_winner_id, c.bronze_winner_id
    FROM games g
    JOIN game_events ge ON g.game_id = ge.game_id
    LEFT JOIN categories c ON ge.event_id = c.event_id
    WHERE ge.assigned_user_id = ?
    ORDER BY g.game_name, ge.event_name, c.category_name";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
}

    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id); 
    $stmt->execute(); 
    
    $result = $stmt->get_result();
    $results = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($results) {
        foreach ($results as $row) {
            $game_name = $row['game_name'];
            $event_id = $row['event_id'];
            $event_name = $row['event_name'];

            if (!isset($managed_data[$game_name])) {
                $managed_data[$game_name] = [];
            }
            if (!isset($managed_data[$game_name][$event_id])) {
                $managed_data[$game_name][$event_id] = [
                    'event_id' => $event_id,
                    'event_name' => $event_name,
                    'categories' => []
                ];
            }
            if ($row['category_id']) {
                $managed_data[$game_name][$event_id]['categories'][] = [
                    'category_id' => $row['category_id'],
                    'category_name' => $row['category_name'],
                    'status' => $row['status'],
                    'gold_winner_id' => $row['gold_winner_id'],
                    'silver_winner_id' => $row['silver_winner_id'],
                    'bronze_winner_id' => $row['bronze_winner_id']
                ];
            }
        }
    }

    foreach ($managed_data as $game_name => $events_by_id) {
        $managed_data[$game_name] = array_values($events_by_id);
    }

} catch (Exception $e) { 
    $alert_message = "ERROR: Could not load your assigned events.";
    error_log($e->getMessage());
}


// --- HELPER FUNCTIONS (Unchanged) ---
function getTeamName($team_id, $teams) {
    if ($team_id === null) return 'N/A';
    foreach ($teams as $team) {
        if ($team['team_id'] == $team_id) {
            return htmlspecialchars($team['team_name']);
        }
    }
    return 'Unknown';
}

function getCategoryStatusClass($status) {
    switch (strtolower($status)) {
        case 'upcoming': return 'bg-info';
        case 'ongoing': return 'bg-success';
        case 'completed': return 'bg-secondary';
        case 'cancelled': return 'bg-danger';
        case 'postponed': return 'bg-warning text-dark';
        default: return 'bg-primary';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Event Manager Dashboard</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.iconify.design/2/2.2.1/iconify.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-green: #4CAF50;
            --primary-dark: #2E7D32;
            --accent-gold: #FFD700;
            --accent-silver: #C0C0C0;
            --accent-bronze: #CD7F32;
            --bg-light: #F8F9FA;
            --bg-white: #FFFFFF;
            --text-dark: #1A1A1A;
            --text-muted: #6C757D;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 32px rgba(0,0,0,0.12);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        body {
            background: linear-gradient(to bottom,rgba(245, 16, 16, 0.32));
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
            padding: 1rem 0;
            backdrop-filter: blur(10px);
        }
        .navbar-brand { transition: var(--transition); }
        .navbar-brand:hover { transform: translateY(-2px); }
        .brand-logo { filter: drop-shadow(0 2px 4px rgba(255,255,255,0.1)); }
        .brand-heading { font-family: 'Poppins', sans-serif; font-weight: 700; letter-spacing: -0.5px; }
        .nav-link { font-weight: 500; font-size: 0.95rem; padding: 0.5rem 1.25rem !important; margin: 0 0.25rem; border-radius: 8px; transition: var(--transition); position: relative; }
        .nav-link::after { content: ''; position: absolute; bottom: 0; left: 50%; width: 0; height: 2px; background: var(--primary-green); transition: var(--transition); transform: translateX(-50%); }
        .nav-link:hover::after, .nav-link.active::after { width: 80%; }
        .nav-link:hover { background: rgba(255,255,255,0.1); color: var(--primary-green) !important; }
        .btn-danger, .btn-success { padding: 0.6rem 1.5rem; border-radius: 10px; font-weight: 600; transition: var(--transition); border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .btn-danger:hover, .btn-success:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.2); }
        .overall-card { background-color: #e9edf6; border: 1px solid #f5dc8f; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 24px; }
        .interactive-brand:hover .brand-heading, .interactive-brand:hover .brand-subheading { color: #4CAF50 !important; }
        .accordion-button:not(.collapsed) { background-color: #001f3f; color: white; box-shadow: inset 0 -1px 0 rgba(0,0,0,.125); }
        .accordion-button:not(.collapsed)::after { filter: brightness(0) invert(1); }
        .accordion-button { font-weight: 600; font-size: 1.2rem; }
        .table-hover tbody tr:hover { background-color: #f8f9fa; }
        .medal-icon { font-size: 1.1em; margin-right: 4px; }
        .gold-medal { color: gold; text-shadow: 0 0 2px #333; }
        .silver-medal { color: silver; text-shadow: 0 0 2px #333; }
        .bronze-medal { color: #cd7f32; text-shadow: 0 0 2px #333; }
        .table-responsive-lg { display: block; width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center interactive-brand" href="Tournament_Manager_page.php" style="cursor: pointer;">
                <img src="imageslogo.png" alt="Logo" class="me-2 brand-logo" style="height: 50px; width: 48px; object-fit: contain; transition: filter 0.2s;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white brand-heading" style="font-size: 1.25rem; transition: color 0.2s;">PIT SPORTS TALLYING</strong>
                    <small class="text-light brand-subheading" style="font-size: 0.75rem; transition: color 0.2s;">Official College Tournament System</small>
                </div>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
                aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'event_manager_dashboard.php') ? 'active' : '' ?>" href="event_manager_dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="Event.php">View All Events</a>
                    </li>
                    <li class="nav-item">
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <a href="login.php" class="btn btn-danger ms-3">Logout</a>
                        <?php else: ?>
                            <a href="login.php" class="btn btn-success ms-3">Admin Login</a>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-5" style="padding-top: 80px;">
        <div class="overall-card">
        
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="fw-bold">
                        <i class="fas fa-tasks me-2"></i>Event Manager Dashboard
                    </h1>
                    <p class="lead text-muted">Welcome, <?php echo htmlspecialchars($username); ?>! Manage your assigned events and categories.</p>
                </div>
            </div>
            
            <?php if (!empty($alert_message)): ?>
                <div class="alert <?php echo strpos($alert_message, 'ERROR') === 0 ? 'alert-danger' : 'alert-success'; ?> alert-dismissible fade show" role="alert">
                    <?php echo $alert_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="accordion" id="gamesAccordion">
                
                <?php if (empty($managed_data)): ?>
                    <div class="alert alert-info text-center">
                        You have not been assigned to any events yet. Please contact the Sports Director.
                    </div>
                <?php endif; ?>

                <?php foreach ($managed_data as $game_name => $events): ?>
                    <div class="accordion-item shadow-sm mb-3 border-0 rounded">
                        <h2 class="accordion-header" id="heading-<?php echo preg_replace('/[^a-z0-9]/i', '', $game_name); ?>">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-<?php echo preg_replace('/[^a-z0-9]/i', '', $game_name); ?>" aria-expanded="false" aria-controls="collapse-<?php echo preg_replace('/[^a-z0-9]/i', '', $game_name); ?>">
                                <?php echo htmlspecialchars($game_name); ?>
                            </button>
                        </h2>
                        <div id="collapse-<?php echo preg_replace('/[^a-z0-9]/i', '', $game_name); ?>" class="accordion-collapse collapse" aria-labelledby="heading-<?php echo preg_replace('/[^a-z0-9]/i', '', $game_name); ?>" data-bs-parent="#gamesAccordion">
                            <div class="accordion-body">
                                
                                <?php foreach ($events as $event): ?>
                                    <div class="card mb-4 shadow-sm border-0">
                                        <div class="card-header bg-light d-flex flex-wrap justify-content-between align-items-center">
                                            <h5 class="mb-0 fw-semibold">
                                                <i class="fas fa-trophy me-2 text-primary"></i>
                                                <?php echo htmlspecialchars($event['event_name']); ?>
                                            </h5>
                                            <button class="btn btn-primary btn-sm" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#categoryModal"
                                                    data-bs-action="add"
                                                    data-bs-event-id="<?php echo $event['event_id']; ?>"
                                                    data-bs-event-name="<?php echo htmlspecialchars($event['event_name']); ?>">
                                                <i class="fas fa-plus me-1"></i> Add Category
                                            </button>
                                        </div>
                                        <div class="card-body p-0">
                                            <div class="table-responsive-lg">
                                                <table class="table table-hover align-middle mb-0">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th scope="col" class="ps-3">Category Name</th>
                                                            <th scope="col" class="text-center">Status</th>
                                                            <th scope="col">Current Winners</th>
                                                            <th scope="col" class="text-end pe-3">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if (empty($event['categories'])): ?>
                                                            <tr>
                                                                <td colspan="4" class="text-center text-muted p-4">
                                                                    No categories created for this event yet.
                                                                </td>
                                                            </tr>
                                                        <?php endif; ?>
                                                        
                                                        <?php foreach ($event['categories'] as $category): ?>
                                                            <tr>
                                                                <td class="ps-3 fw-medium">
                                                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                                                </td>
                                                                <td class="text-center">
                                                                    <span class="badge <?php echo getCategoryStatusClass($category['status']); ?>">
                                                                        <?php echo htmlspecialchars($category['status']); ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <span class="medal-icon gold-medal" title="Gold: <?php echo getTeamName($category['gold_winner_id'], $teams); ?>">
                                                                        <i class="fas fa-medal"></i>
                                                                    </span>
                                                                    <span class="medal-icon silver-medal" title="Silver: <?php echo getTeamName($category['silver_winner_id'], $teams); ?>">
                                                                        <i class="fas fa-medal"></i>
                                                                    </span>
                                                                    <span class="medal-icon bronze-medal" title="Bronze: <?php echo getTeamName($category['bronze_winner_id'], $teams); ?>">
                                                                        <i class="fas fa-medal"></i>
                                                                    </span>
                                                                </td>
                                                                <td class="text-end pe-3">
                                                                    <button class="btn btn-success btn-sm me-1"
                                                                            title="Award Medals"
                                                                            data-bs-toggle="modal" 
                                                                            data-bs-target="#awardModal"
                                                                            data-bs-category-id="<?php echo $category['category_id']; ?>"
                                                                            data-bs-category-name="<?php echo htmlspecialchars($category['category_name']); ?>"
                                                                            data-bs-gold-id="<?php echo $category['gold_winner_id']; ?>"
                                                                            data-bs-silver-id="<?php echo $category['silver_winner_id']; ?>"
                                                                            data-bs-bronze-id="<?php echo $category['bronze_winner_id']; ?>">
                                                                        <i class="fas fa-medal"></i>
                                                                    </button>
                                                                    <button class="btn btn-warning btn-sm me-1"
                                                                            title="Edit Category"
                                                                            data-bs-toggle="modal" 
                                                                            data-bs-target="#categoryModal"
                                                                            data-bs-action="edit"
                                                                            data-bs-event-id="<?php echo $event['event_id']; ?>"
                                                                            data-bs-event-name="<?php echo htmlspecialchars($event['event_name']); ?>"
                                                                            data-bs-category-id="<?php echo $category['category_id']; ?>"
                                                                            data-bs-category-name="<?php echo htmlspecialchars($category['category_name']); ?>"
                                                                            data-bs-status="<?php echo htmlspecialchars($category['status']); ?>">
                                                                        <i class="fas fa-edit"></i>
                                                                    </button>
                                                                    <button class="btn btn-danger btn-sm"
                                                                            title="Delete Category"
                                                                            data-bs-toggle="modal" 
                                                                            data-bs-target="#deleteModal"
                                                                            data-bs-category-id="<?php echo $category['category_id']; ?>"
                                                                            data-bs-category-name="<?php echo htmlspecialchars($category['category_name']); ?>">
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
                                <?php endforeach; ?>
                                
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    </div>
    
    <div class="modal fade" id="categoryModal" tabindex="-1" aria-labelledby="categoryModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="event_manager_dashboard.php">
                    <input type="hidden" name="action" value="save_category">
                    <input type="hidden" name="event_id" id="modal_event_id">
                    <input type="hidden" name="category_id" id="modal_category_id">
                    
                    <div class="modal-header">
                        <h5 class="modal-title" id="categoryModalLabel">Add Category</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Event</label>
                            <input type="text" class="form-control" id="modal_event_name" disabled>
                        </div>
                        <div class="mb-3">
                            <label for="category_name" class="form-label">Category Name</label>
                            <input type="text" class="form-control" id="modal_category_name" name="category_name" placeholder="e.g., Men's Singles" required>
                        </div>
                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="modal_category_status" name="status" required>
                                <option value="Upcoming">Upcoming</option>
                                <option value="Ongoing">Ongoing</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                                <option value="Postponed">Postponed</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Save Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal fade" id="awardModal" tabindex="-1" aria-labelledby="awardModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="event_manager_dashboard.php">
                    <input type="hidden" name="action" value="submit_results_for_approval"> <input type="hidden" name="category_id" id="award_category_id">
                    
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title" id="awardModalLabel">Award Medals</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Awarding results for: <strong id="award_category_name"></strong></p>
                        
                        <div class="mb-3">
                            <label for="gold_winner_id" class="form-label">
                                <i class="fas fa-medal gold-medal me-1"></i> Gold Medal
                            </label>
                            <select class="form-select" id="gold_winner_id" name="gold_winner_id">
                                <option value="">-- Select Winner --</option>
                                <?php foreach ($teams as $team): ?>
                                    <option value="<?php echo $team['team_id']; ?>">
                                        <!-- --- FIX #2: Changed '$team['college']' to '$team['dean_name']' --- -->
                                        <!-- This was trying to display a column that doesn't exist. -->
                                        <?php echo htmlspecialchars($team['team_name'] . ' (' . $team['dean_name'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="silver_winner_id" class="form-label">
                                <i class="fas fa-medal silver-medal me-1"></i> Silver Medal
                            </label>
                            <select class="form-select" id="silver_winner_id" name="silver_winner_id">
                                <option value="">-- Select Winner --</option>
                                <?php foreach ($teams as $team): ?>
                                    <option value="<?php echo $team['team_id']; ?>">
                                        <!-- --- FIX #2: Changed '$team['college']' to '$team['dean_name']' --- -->
                                        <?php echo htmlspecialchars($team['team_name'] . ' (' . $team['dean_name'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="bronze_winner_id" class="form-label">
                                <i class="fas fa-medal bronze-medal me-1"></i> Bronze Medal
                            </label>
                            <select class="form-select" id="bronze_winner_id" name="bronze_winner_id">
                                <option value="">-- Select Winner --</option>
                                <?php foreach ($teams as $team): ?>
                                    <option value="<?php echo $team['team_id']; ?>">
                                        <!-- --- FIX #2: Changed '$team['college']' to '$team['dean_name']' --- -->
                                        <?php echo htmlspecialchars($team['team_name'] . ' (' . $team['dean_name'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-success">Submit for Approval</button> 
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="event_manager_dashboard.php">
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="category_id" id="delete_category_id">
                    
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="deleteModalLabel">Confirm Deletion</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete the category: <strong id="delete_category_name"></strong>?</p>
                        <p class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i> This action cannot be undone and will remove all associated data.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Yes, Delete Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.iconify.design/2/2.2.1/iconify.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            
            // --- 1. Handle Add/Edit Category Modal ---
            const categoryModal = document.getElementById('categoryModal');
            categoryModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const action = button.getAttribute('data-bs-action');
                const eventId = button.getAttribute('data-bs-event-id');
                const eventName = button.getAttribute('data-bs-event-name');
                
                const modalTitle = categoryModal.querySelector('.modal-title');
                const eventIdInput = categoryModal.querySelector('#modal_event_id');
                const eventNameInput = categoryModal.querySelector('#modal_event_name');
                const categoryIdInput = categoryModal.querySelector('#modal_category_id');
                const categoryNameInput = categoryModal.querySelector('#modal_category_name');
                const categoryStatusSelect = categoryModal.querySelector('#modal_category_status');
                
                eventIdInput.value = eventId;
                eventNameInput.value = eventName;

                if (action === 'edit') {
                    const categoryId = button.getAttribute('data-bs-category-id');
                    const categoryName = button.getAttribute('data-bs-category-name');
                    const status = button.getAttribute('data-bs-status');
                    
                    modalTitle.textContent = 'Edit Category';
                    categoryIdInput.value = categoryId;
                    categoryNameInput.value = categoryName;
                    categoryStatusSelect.value = status;
                    
                } else {
                    modalTitle.textContent = 'Add Category to ' + eventName;
                    categoryIdInput.value = '';
                    categoryNameInput.value = '';
                    categoryStatusSelect.value = 'Upcoming';
                }
            });

            // --- 2. Handle Award Medals Modal ---
            const awardModal = document.getElementById('awardModal');
            awardModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                
                const categoryId = button.getAttribute('data-bs-category-id');
                const categoryName = button.getAttribute('data-bs-category-name');
                const goldId = button.getAttribute('data-bs-gold-id');
                const silverId = button.getAttribute('data-bs-silver-id');
                const bronzeId = button.getAttribute('data-bs-bronze-id');
                
                awardModal.querySelector('#award_category_id').value = categoryId;
                awardModal.querySelector('#award_category_name').textContent = categoryName;
                awardModal.querySelector('#gold_winner_id').value = goldId || '';
                awardModal.querySelector('#silver_winner_id').value = silverId || '';
                awardModal.querySelector('#bronze_winner_id').value = bronzeId || '';
            });
            
            // --- 3. Handle Delete Modal ---
            const deleteModal = document.getElementById('deleteModal');
            deleteModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                
                const categoryId = button.getAttribute('data-bs-category-id');
                const categoryName = button.getAttribute('data-bs-category-name');
                
                deleteModal.querySelector('#delete_category_id').value = categoryId;
                deleteModal.querySelector('#delete_category_name').textContent = categoryName;
            });

            // Auto-open the first accordion item
            const accordions = document.querySelectorAll('.accordion-item');
            if (accordions.length === 1) {
                 new bootstrap.Collapse(accordions[0].querySelector('.accordion-collapse'), {
                     toggle: true
                 });
            }
        });
    </script>
</body>
</html>

