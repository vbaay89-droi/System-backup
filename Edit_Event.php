<?php
session_start();

// --- Authentication Check for Admin Dashboard ---
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

require_once 'config.php'; // Make sure this path is correct

// PHP variables for header/footer consistency
$current_page = basename($_SERVER['PHP_SELF']);
$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin'; // User name for display

$message = '';
$message_type = ''; // 'success' or 'danger'

// Get event ID from URL
$eventId = $_GET['id'] ?? null;

// Initialize form variables
$eventName = '';
$category = '';
$startDate = '';
$endDate = '';
$description = '';
$startDateTBA = false;
$endDateTBA = false;

// Suggested categories (customize these, ensure consistent with Manage_event.php)
$event_categories = ['Sports', 'Ball Games', 'Athletics', 'Racket Games', 'Others Games'];

// --- Fetch existing event data if ID is provided ---
if ($eventId) {
    $sql_fetch = "SELECT event_id, event_name, category, start_date, end_date, description FROM events WHERE event_id = ?";
    $stmt_fetch = $conn->prepare($sql_fetch);

    if ($stmt_fetch) {
        $stmt_fetch->bind_param("i", $eventId);
        $stmt_fetch->execute();
        $result_fetch = $stmt_fetch->get_result();

        if ($result_fetch && $result_fetch->num_rows > 0) {
            $eventData = $result_fetch->fetch_assoc();
            $eventName = $eventData['event_name'];
            $category = $eventData['category'];
            $startDate = $eventData['start_date'];
            $endDate = $eventData['end_date'];
            $description = $eventData['description'];

            // Determine if dates are TBA from database
            if ($startDate === 'TBA') {
                $startDateTBA = true;
                $startDate = ''; // Clear actual date for input field
            }
            if ($endDate === 'TBA') {
                $endDateTBA = true;
                $endDate = ''; // Clear actual date for input field
            }

        } else {
            $message = "Event not found.";
            $message_type = "danger";
            $eventId = null; // Invalidate eventId if not found
        }
        $stmt_fetch->close();
    } else {
        $message = "Database prepare error (fetch): " . $conn->error;
        $message_type = "danger";
    }
} else {
    $message = "No event ID provided for editing.";
    $message_type = "danger";
}

// --- Handle Update Event Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_event' && $eventId) {
    $eventName = trim($_POST['event_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $startDate = trim($_POST['start_date'] ?? '');
    $endDate = trim($_POST['end_date'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $startDateTBA = isset($_POST['start_date_tba']);
    $endDateTBA = isset($_POST['end_date_tba']);

    // Determine actual dates to store based on TBA checkboxes
    $finalStartDate = $startDateTBA ? 'TBA' : $startDate;
    $finalEndDate = $endDateTBA ? 'TBA' : $endDate;

    // Server-side validation for updating event
    if (empty($eventName) || empty($category) || 
        (empty($finalStartDate) && !$startDateTBA) || 
        (empty($finalEndDate) && !$endDateTBA)) {
        
        $message = "Please fill in all required fields (Event Name, Category, and either provide dates or mark as TBA).";
        $message_type = "danger";
    } elseif (!$startDateTBA && !$endDateTBA && $finalStartDate > $finalEndDate) {
        $message = "End Date cannot be before Start Date.";
        $message_type = "danger";
    } else {
        // All good, update database
        $sql_update = "UPDATE events SET event_name = ?, category = ?, start_date = ?, end_date = ?, description = ? WHERE event_id = ?";
        $stmt_update = $conn->prepare($sql_update);

        if ($stmt_update) {
            $stmt_update->bind_param("sssssi", $eventName, $category, $finalStartDate, $finalEndDate, $description, $eventId);
            if ($stmt_update->execute()) {
                $message = "Event '<strong>" . htmlspecialchars($eventName) . "</strong>' updated successfully!";
                $message_type = "success";
                // Redirect after success
                header('Location: Manage_event.php?status=success&msg=' . urlencode($message));
                exit();
            } else {
                $message = "Error updating event: " . $stmt_update->error;
                $message_type = "danger";
            }
            $stmt_update->close();
        } else {
            $message = "Database prepare error (update): " . $conn->error;
            $message_type = "danger";
        }
    }
}
$conn->close(); // Close connection after all operations
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Event - PIT SPORTS TALLYING</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="tournament.css"> <!-- Your existing custom CSS -->
    <style>
        body {
            background: linear-gradient(to bottom,rgba(250, 224, 224, 0.25),rgba(245, 16, 16, 0.32));
            margin: 0;
            padding: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
        }
        .navbar {
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            width: 100%;
        }
        
        .main-content {
            flex: 1 0 auto;
            padding-top: 90px; /* Adjust based on navbar height */
            padding-bottom: 50px;
        }
        
        footer {
            flex-shrink: 0;
            width: 100%;
        }

        .interactive-brand:hover .brand-heading,
        .interactive-brand:hover .brand-subheading {
            color:rgba(0, 102, 255, 0.43) !important;
        }

        .edit-event-form-section { /* Specific styling for edit form */
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 30px;
            margin-bottom: 30px;
        }
        .form-control:focus, .form-select:focus {
            box-shadow: none;
            border-color: #86b7fe;
        }
        .btn-primary, .btn-secondary, .btn-success {
            border-radius: 8px;
            padding: 10px 20px;
        }
        .date-input-group {
            display: flex;
            align-items: center;
        }
        .date-input-group .form-control {
            flex-grow: 1;
        }
        .date-input-group .form-check {
            margin-left: 15px;
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <!-- Header (Navbar) -->
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
                        <a class="nav-link <?= ($current_page == 'Tournament_Manager_page.php') ? 'active' : '' ?>" href="Tournament_Manager_page.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'Manage_event.php') ? 'active' : '' ?>" href="Manage_event.php">Events</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'Teams.php') ? 'active' : '' ?>" href="Teams.php">Teams</a>
                    </li>
                    <li class="nav-item">
                        <?php if (isset($_SESSION['email'])): ?>
                            <a href="logout.php" class="btn btn-danger ms-3">Logout</a>
                        <?php else: ?>
                            <a href="login.php" class="btn btn-success ms-3">Admin Login</a>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content Area for Edit Event Form -->
    <div class="main-content">
        <div class="container py-4">
            <h1 class="fw-bold mb-4">Edit Event</h1>

            <div class="edit-event-form-section">
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                        <?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($eventId): // Only show form if a valid event ID was found ?>
                <form action="edit_event.php?id=<?= htmlspecialchars($eventId) ?>" method="POST">
                    <input type="hidden" name="action" value="update_event">
                    
                    <div class="mb-3">
                        <label for="eventName" class="form-label">Event Name<span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="eventName" name="event_name" required value="<?= htmlspecialchars($eventName) ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label for="category" class="form-label">Category<span class="text-danger">*</span></label>
                        <select class="form-select" id="category" name="category" required>
                            <option value="">Select a category</option>
                            <?php foreach ($event_categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>" <?= ($category === $cat) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="startDate" class="form-label">Start Date<span class="text-danger">*</span></label>
                            <div class="date-input-group">
                                <input type="text" class="form-control" id="startDate" name="start_date" 
                                    value="<?= htmlspecialchars($startDate) ?>"
                                    <?= $startDateTBA ? 'disabled' : 'required' ?>>
                                <div class="form-check ms-3">
                                    <input class="form-check-input" type="checkbox" id="startDateTBA" name="start_date_tba" <?= $startDateTBA ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="startDateTBA">TBA</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="endDate" class="form-label">End Date<span class="text-danger">*</span></label>
                            <div class="date-input-group">
                                <input type="text" class="form-control" id="endDate" name="end_date" 
                                    value="<?= htmlspecialchars($endDate) ?>"
                                    <?= $endDateTBA ? 'disabled' : 'required' ?>>
                                <div class="form-check ms-3">
                                    <input class="form-check-input" type="checkbox" id="endDateTBA" name="end_date_tba" <?= $endDateTBA ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="endDateTBA">TBA</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="description" class="form-label">Description (Optional)</label>
                        <textarea class="form-control" id="description" name="description" rows="5"><?= htmlspecialchars($description) ?></textarea>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <a href="Manage_event.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i>Back to Events</a>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Update Event</button>
                    </div>
                </form>
                <?php else: ?>
                    <p class="text-center text-danger">Please select an event to edit from the <a href="Manage_event.php">Manage Events</a> page.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-dark text-white py-3">
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
            <small>Developed by Tsunayoshi Sawada</small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Adjust main content padding dynamically based on navbar height
        document.addEventListener('DOMContentLoaded', function() {
            const navbarHeight = document.querySelector('.navbar').offsetHeight;
            document.querySelector('.main-content').style.paddingTop = `${navbarHeight + 30}px`; // Add some extra space

            // Function to handle TBA checkbox toggling
            function setupTbaToggle(dateInputId, tbaCheckboxId) {
                const dateInput = document.getElementById(dateInputId);
                const tbaCheckbox = document.getElementById(tbaCheckboxId);

                // Initial state check
                if (tbaCheckbox.checked) {
                    dateInput.setAttribute('disabled', 'disabled');
                    dateInput.removeAttribute('required');
                } else {
                    dateInput.removeAttribute('disabled');
                    dateInput.setAttribute('required', 'required');
                }

                tbaCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        dateInput.value = ''; // Clear date input when TBA is checked
                        dateInput.setAttribute('disabled', 'disabled');
                        dateInput.removeAttribute('required');
                    } else {
                        dateInput.removeAttribute('disabled');
                        dateInput.setAttribute('required', 'required');
                    }
                });
            }

            // Setup TBA toggles for start and end dates
            setupTbaToggle('startDate', 'startDateTBA');
            setupTbaToggle('endDate', 'endDateTBA');
        });
        
        // Interactive brand reload functionality (redirects to home)
        document.querySelector('.interactive-brand').addEventListener('click', function(e) {
            e.preventDefault();
            window.location.href = 'Tournament_Manager_page.php'; // Redirect to home page
        });
    </script>
</body>
</html>
