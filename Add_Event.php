<?php
session_start();

// Authentication Check for Admin Dashboard
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

require_once 'config.php'; // Database configuration

$current_page = basename($_SERVER['PHP_SELF']);
$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin'; // For display in header

$message = '';
$message_type = ''; // 'success' or 'danger'

// Default values for form fields (useful when validation fails)
$eventName = $_POST['event_name'] ?? '';
$category = $_POST['category'] ?? ''; // NEW: Initialize category
$startDate = $_POST['start_date'] ?? '';
$endDate = $_POST['end_date'] ?? '';
$description = $_POST['description'] ?? '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventName = trim($_POST['event_name'] ?? '');
    $category = trim($_POST['category'] ?? ''); // NEW: Get category from POST
    $startDate = trim($_POST['start_date'] ?? '');
    $endDate = trim($_POST['end_date'] ?? '');
    $description = trim($_POST['description'] ?? '');

    // Server-side validation - category is now a required field
    if (empty($eventName) || empty($category) || empty($startDate) || empty($endDate)) {
        $message = "Please fill in all required fields (Event Name, Category, Start Date, End Date).";
        $message_type = "danger";
    } elseif ($startDate > $endDate) {
        $message = "End Date cannot be before Start Date.";
        $message_type = "danger";
    } else {
        // All good, insert into database - UPDATED SQL and bind_param for 'category'
        $sql = "INSERT INTO events (event_name, category, start_date, end_date, description) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);

        if ($stmt) {
            $stmt->bind_param("sssss", $eventName, $category, $startDate, $endDate, $description); // 'sssss' for 5 strings
            if ($stmt->execute()) {
                $message = "Event '<strong>" . htmlspecialchars($eventName) . "</strong>' added successfully!";
                $message_type = "success";
                // Optionally clear form fields after successful submission
                $eventName = '';
                $category = '';
                $startDate = '';
                $endDate = '';
                $description = '';
                // Optionally redirect after success
                // header('Location: Manage_event.php?status=success&msg=' . urlencode($message));
                // exit();
            } else {
                $message = "Error adding event: " . $stmt->error;
                $message_type = "danger";
            }
            $stmt->close();
        } else {
            $message = "Database prepare error: " . $conn->error;
            $message_type = "danger";
        }
    }
}

// Dummy values for college logos (for navbar)
$college_logos = [
    'COTE'    => 'COTE.png',
    'CAS'     => 'CASlogo.png',
    'COMED'   => 'COMED.png',
    'CTE'     => 'CTE.png',
    'PIT-TC' => 'PIT.png'
];

// Suggested categories (you can customize these to your needs)
$event_categories = ['Sports', 'Academics', 'Cultural', 'Community', 'Others'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Event - PIT SPORTS TALLYING</title>
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

        .add-event-form-section {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 30px;
            margin-bottom: 30px;
        }
        .form-control:focus, .form-select:focus { /* Added .form-select */
            box-shadow: none;
            border-color: #86b7fe;
        }
        .btn-primary, .btn-secondary {
            border-radius: 8px;
            padding: 10px 20px;
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

    <!-- Main Content Area for Add Event Form -->
    <div class="main-content">
        <div class="container py-4">
            <h1 class="fw-bold mb-4">Add New Event</h1>

            <div class="add-event-form-section">
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                        <?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form action="add_event.php" method="POST">
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
                            <input type="date" class="form-control" id="startDate" name="start_date" required value="<?= htmlspecialchars($startDate) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="endDate" class="form-label">End Date<span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="endDate" name="end_date" required value="<?= htmlspecialchars($endDate) ?>">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="description" class="form-label">Description (Optional)</label>
                        <textarea class="form-control" id="description" name="description" rows="5"><?= htmlspecialchars($description) ?></textarea>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <a href="Manage_event.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i>Back to Events</a>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Event</button>
                    </div>
                </form>
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
        });

        // Interactive brand reload functionality (redirects to home)
        document.querySelector('.interactive-brand').addEventListener('click', function(e) {
            e.preventDefault();
            window.location.href = 'Tournament_Manager_page.php'; // Redirect to home page
        });
    </script>
</body>
</html>
