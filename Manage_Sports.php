<?php
session_start();

// --- Authentication Check for Admin Dashboard ---
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

require_once 'config.php';

// PHP variables for header/footer consistency
$current_page = basename($_SERVER['PHP_SELF']);
$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin';

// Message for form submission
$message = '';
$message_type = '';

// --- Retrieve and Clear Messages from Session ---
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Categories for sports
$sport_categories = ['Ball games', 'Racket games', 'Athletics', 'Other games'];

// --- Handle Form Submissions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_sport') {
        $sport_name = trim($_POST['sport_name'] ?? '');
        $category = trim($_POST['category'] ?? '');

        if (empty($sport_name) || empty($category)) {
            $_SESSION['message'] = "Please fill in all required fields.";
            $_SESSION['message_type'] = "danger";
        } else {
            // Check if sport already exists
            $check_sql = "SELECT sport_id FROM sports WHERE sport_name = ? AND category = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("ss", $sport_name, $category);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            
            if ($result->num_rows > 0) {
                $_SESSION['message'] = "This sport already exists in this category.";
                $_SESSION['message_type'] = "danger";
            } else {
                $insert_sql = "INSERT INTO sports (sport_name, category) VALUES (?, ?)";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param("ss", $sport_name, $category);
                
                if ($insert_stmt->execute()) {
                    $_SESSION['message'] = "Sport '<strong>" . htmlspecialchars($sport_name) . "</strong>' added successfully!";
                    $_SESSION['message_type'] = "success";
                } else {
                    $_SESSION['message'] = "Error adding sport: " . $insert_stmt->error;
                    $_SESSION['message_type'] = "danger";
                }
                $insert_stmt->close();
            }
            $check_stmt->close();
        }
    } elseif ($action === 'update_sport') {
        $sport_id = $_POST['sport_id'] ?? null;
        $sport_name = trim($_POST['sport_name'] ?? '');
        $category = trim($_POST['category'] ?? '');

        if (!$sport_id || empty($sport_name) || empty($category)) {
            $_SESSION['message'] = "Please fill in all required fields.";
            $_SESSION['message_type'] = "danger";
        } else {
            // Check if sport already exists (excluding current sport)
            $check_sql = "SELECT sport_id FROM sports WHERE sport_name = ? AND category = ? AND sport_id != ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("ssi", $sport_name, $category, $sport_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            
            if ($result->num_rows > 0) {
                $_SESSION['message'] = "This sport already exists in this category.";
                $_SESSION['message_type'] = "danger";
            } else {
                $update_sql = "UPDATE sports SET sport_name = ?, category = ? WHERE sport_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ssi", $sport_name, $category, $sport_id);
                
                if ($update_stmt->execute()) {
                    $_SESSION['message'] = "Sport '<strong>" . htmlspecialchars($sport_name) . "</strong>' updated successfully!";
                    $_SESSION['message_type'] = "success";
                } else {
                    $_SESSION['message'] = "Error updating sport: " . $update_stmt->error;
                    $_SESSION['message_type'] = "danger";
                }
                $update_stmt->close();
            }
            $check_stmt->close();
        }
    } elseif ($action === 'delete_sport') {
        $sport_id = $_POST['sport_id'] ?? null;

        if (!$sport_id) {
            $_SESSION['message'] = "No sport ID provided for deletion.";
            $_SESSION['message_type'] = "danger";
        } else {
            // Check if sport is being used in events
            $check_events_sql = "SELECT COUNT(*) as event_count FROM events WHERE sport_id = ?";
            $check_events_stmt = $conn->prepare($check_events_sql);
            $check_events_stmt->bind_param("i", $sport_id);
            $check_events_stmt->execute();
            $result = $check_events_stmt->get_result();
            $event_count = $result->fetch_assoc()['event_count'];
            $check_events_stmt->close();

            if ($event_count > 0) {
                $_SESSION['message'] = "Cannot delete sport. It is being used in {$event_count} event(s). Please remove or reassign the events first.";
                $_SESSION['message_type'] = "danger";
            } else {
                $delete_sql = "DELETE FROM sports WHERE sport_id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                $delete_stmt->bind_param("i", $sport_id);
                
                if ($delete_stmt->execute()) {
                    $_SESSION['message'] = "Sport deleted successfully!";
                    $_SESSION['message_type'] = "success";
                } else {
                    $_SESSION['message'] = "Error deleting sport: " . $delete_stmt->error;
                    $_SESSION['message_type'] = "danger";
                }
                $delete_stmt->close();
            }
        }
    }
    
    header('Location: Manage_Sports.php');
    exit();
}

// --- Fetch all sports ---
$sports = [];
$sql_sports = "SELECT sport_id, sport_name, category FROM sports ORDER BY category, sport_name";
$result_sports = $conn->query($sql_sports);
if ($result_sports) {
    while ($row = $result_sports->fetch_assoc()) {
        $sports[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Sports - PIT SPORTS TALLYING</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(to bottom, rgba(250, 224, 224, 0.25), rgba(245, 16, 16, 0.32));
            margin: 0;
            padding: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
        }
        .navbar {
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            width: 100%;
        }
        .content-section {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 30px;
            margin-bottom: 30px;
        }
        .table thead th {
            background-color: rgb(76, 167, 40);
            color: white;
        }
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: center;
            align-items: center;
        }
        .action-buttons .btn {
            flex-shrink: 0;
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        .category-badge {
            font-size: 0.8em;
            padding: 0.3em 0.6em;
        }
        .back-button {
            transition: all 0.3s ease;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 500;
        }
        .back-button:hover {
            transform: translateX(-3px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
    </style>
</head>
<body>
    <!-- Header (Navbar) -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <a class="navbar-brand d-flex align-items-center" href="Manage_Event.php">
                <img src="imageslogo.png" alt="Logo" class="me-2" style="height: 50px; width: 48px; object-fit: contain;">
                <div class="d-flex flex-column lh-sm">
                    <strong class="text-white" style="font-size: 1.25rem;">PIT SPORTS TALLYING</strong>
                    <small class="text-light" style="font-size: 0.75rem;">Official College Tournament System</small>
                </div>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item">
                        <a class="nav-link" href="Manage_Event.php">Admin Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="Event.php">Events</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="Teams.php">Teams</a>
                    </li>
                    <li class="nav-item">
                        <a href="logout.php" class="btn btn-danger ms-3">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    

        <h1 class="fw-bold mb-4">Manage Sports</h1>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Add New Sport Button -->
        <div class="d-flex justify-content-end mb-4">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSportModal">
                <i class="fas fa-plus-circle me-2"></i>Add New Sport
            </button>
        </div>

        <!-- Sports Table -->
        <div class="content-section">
            <div class="table-responsive">
                <table class="table table-bordered table-hover shadow-sm">
                    <thead>
                        <tr>
                            <th>Sport Name</th>
                            <th>Category</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sports)): ?>
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">No sports found. Add a new sport to get started!</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sports as $sport): ?>
                                <tr>
                                    <td><?= htmlspecialchars($sport['sport_name']) ?></td>
                                    <td>
                                        <span class="badge category-badge bg-primary">
                                            <?= htmlspecialchars($sport['category']) ?>
                                        </span>
                                    </td>
                                    <td class="action-buttons">
                                        <button type="button" class="btn btn-sm btn-warning edit-sport-btn" 
                                                data-bs-toggle="modal" data-bs-target="#editSportModal" 
                                                data-id="<?= $sport['sport_id'] ?>" 
                                                data-name="<?= htmlspecialchars($sport['sport_name']) ?>"
                                                data-category="<?= htmlspecialchars($sport['category']) ?>"
                                                title="Edit Sport">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger delete-sport-btn" 
                                                data-id="<?= $sport['sport_id'] ?>" 
                                                data-name="<?= htmlspecialchars($sport['sport_name']) ?>"
                                                title="Delete Sport">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add Sport Modal -->
    <div class="modal fade" id="addSportModal" tabindex="-1" aria-labelledby="addSportModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addSportModalLabel"><i class="fas fa-plus-circle me-2"></i>Add New Sport</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="Manage_Sports.php" method="POST">
                    <input type="hidden" name="action" value="add_sport">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="addSportName" class="form-label">Sport Name<span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="addSportName" name="sport_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="addCategory" class="form-label">Category<span class="text-danger">*</span></label>
                            <select class="form-select" id="addCategory" name="category" required>
                                <option value="">Select a category</option>
                                <?php foreach ($sport_categories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Add Sport</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Sport Modal -->
    <div class="modal fade" id="editSportModal" tabindex="-1" aria-labelledby="editSportModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="editSportModalLabel"><i class="fas fa-edit me-2"></i>Edit Sport</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="Manage_Sports.php" method="POST">
                    <input type="hidden" name="action" value="update_sport">
                    <input type="hidden" id="editSportId" name="sport_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="editSportName" class="form-label">Sport Name<span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="editSportName" name="sport_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="editCategory" class="form-label">Category<span class="text-danger">*</span></label>
                            <select class="form-select" id="editCategory" name="category" required>
                                <option value="">Select a category</option>
                                <?php foreach ($sport_categories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Sport Modal -->
    <div class="modal fade" id="deleteSportModal" tabindex="-1" aria-labelledby="deleteSportModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteSportModalLabel"><i class="fas fa-trash-alt me-2"></i>Delete Sport</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="Manage_Sports.php" method="POST">
                    <input type="hidden" name="action" value="delete_sport">
                    <input type="hidden" id="deleteSportId" name="sport_id">
                    <div class="modal-body">
                        <p>Are you sure you want to delete the sport "<strong id="deleteSportName"></strong>"?</p>
                        <p class="text-danger"><small>This action cannot be undone. Make sure no events are using this sport.</small></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-trash-alt me-2"></i>Delete Sport</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Edit Sport Modal
            document.querySelectorAll('.edit-sport-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const sportId = this.dataset.id;
                    const sportName = this.dataset.name;
                    const category = this.dataset.category;
                    
                    document.getElementById('editSportId').value = sportId;
                    document.getElementById('editSportName').value = sportName;
                    document.getElementById('editCategory').value = category;
                });
            });

            // Delete Sport Modal
            document.querySelectorAll('.delete-sport-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const sportId = this.dataset.id;
                    const sportName = this.dataset.name;
                    
                    document.getElementById('deleteSportId').value = sportId;
                    document.getElementById('deleteSportName').textContent = sportName;
                    
                    const deleteModal = new bootstrap.Modal(document.getElementById('deleteSportModal'));
                    deleteModal.show();
                });
            });
        });
    </script>
</body>
</html>
