<?php
session_start();

// --- Authentication Check for Admin Dashboard ---
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

require_once 'config.php'; // DB connection file

// PHP variables for header/footer consistency
$current_page = basename($_SERVER['PHP_SELF']);
$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin'; // User name for display

// Message for form submission (add/edit/delete operations)
$message = '';
$message_type = ''; // 'success' or 'danger'

// --- Retrieve and Clear Messages from Session (POST-Redirect-GET pattern) ---
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']); 
    unset($_SESSION['message_type']); 
}

// --- Fetch events with sport name ---
$sql = "SELECT e.event_id, e.event_name, e.category, e.event_status, s.sport_name
        FROM events e
        LEFT JOIN sports s ON e.sport_id = s.sport_id";

$result = $conn->query($sql);
$events = []; // Array of all event details (for the event list table)
$eventCategoryMap = []; // The map required for JavaScript autofill

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // 1. Store the full row for the event list
        $events[] = $row;
        // 2. Build the map for JavaScript (Event ID => Sport Name)
        $eventCategoryMap[$row['event_id']] = $row['sport_name'] ?? '';
    }
}

// Close DB at the end of page rendering (not before fetching data!)
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - PIT SPORTS TALLYING</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* --- Styles for unified header design --- */
:root {
    --primary-green: #4CAF50;
    --primary-dark: #2E7D32;
    --accent-gold: #FFD700;
    /* Add other necessary variables from Tournament_Manager_page.php if needed, or adjust */
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
body {
    /* Update body font to match Tournament_Manager_page.php */
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }

    /* Navbar Enhancement */
    .navbar {
        background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%) !important;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        padding: 1rem 0;
        backdrop-filter: blur(10px);
    }

    .navbar-brand {
        transition: var(--transition);
    }

    .navbar-brand:hover {
        transform: translateY(-2px);
    }

    .brand-logo {
        filter: drop-shadow(0 2px 4px rgba(255,255,255,0.1));
    }

    .brand-heading {
        font-family: 'Poppins', sans-serif;
        font-weight: 700;
        letter-spacing: -0.5px;
    }

    .nav-link {
        font-weight: 500;
        font-size: 0.95rem;
        padding: 0.5rem 1.25rem !important;
        margin: 0 0.25rem;
        border-radius: 8px;
        transition: var(--transition);
        position: relative;
    }

    .nav-link::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 50%;
        width: 0;
        height: 2px;
        background: var(--primary-green);
        transition: var(--transition);
        transform: translateX(-50%);
    }

    .nav-link:hover::after,
    .nav-link.active::after {
        width: 80%;
    }

    .nav-link:hover {
        background: rgba(255,255,255,0.1);
        color: var(--primary-green) !important;
    }

    .btn-danger, .btn-success {
        padding: 0.6rem 1.5rem;
        border-radius: 10px;
        font-weight: 600;
        transition: var(--transition);
        border: none;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    .btn-danger:hover, .btn-success:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.2);
    }
    /* --- End of unified header design styles --- */
        
        /* Main Layout Container */
        .main-container {
            display: flex;
            padding-top: 76px; /* Navbar height + padding */
            min-height: 100vh;
        }
        
        /* Sticky Sidebar Styles */
        .sticky-sidebar {
            position: sticky;
            top: 76px;
            width: 280px;
            height: calc(100vh - 76px);
            background: #2c3e50;
            color: #fff;
            padding: 30px 0;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            z-index: 1020;
            transition: all 0.3s ease;
        }
        
        .sticky-sidebar .sidebar-header {
            padding: 0 25px 20px;
            border-bottom: 2px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
        }
        
        .sticky-sidebar .sidebar-header h4 {
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0;
            color: #fff;
        }
        
        .sticky-sidebar .sidebar-header p {
            font-size: 0.85rem;
            color: rgba(255,255,255,0.7);
            margin: 5px 0 0;
        }
        
        .sidebar-nav {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .sidebar-nav li {
            margin: 0;
        }
        
        .sidebar-nav a {
            display: flex;
            align-items: center;
            padding: 15px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
            font-weight: 500;
        }
        
        .sidebar-nav a:hover {
            background: rgba(255,255,255,0.1);
            color: #fff;
            border-left-color: #3498db;
        }
        
        .sidebar-nav a.active {
            background: rgba(52, 152, 219, 0.2);
            color: #fff;
            border-left-color: #3498db;
        }
        
        .sidebar-nav a i {
            width: 25px;
            margin-right: 12px;
            font-size: 1.1rem;
        }
        
        /* Main Content Area */
        .content-area {
            flex: 1;
            padding: 30px 40px;
            background: transparent;
        }
        
        .content-section {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 35px;
            margin-bottom: 40px;
            scroll-margin-top: 90px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .section-header h3 {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .section-header .btn {
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        
        /* Table Styles */
        .table {
            margin-top: 20px;
        }
        
        .table thead th {
            background-color: #4ca728;
            color: white;
            font-weight: 600;
            border: none;
            padding: 15px;
        }
        
        .table tbody tr {
            transition: background-color 0.2s ease;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .table td {
            vertical-align: middle;
            padding: 12px 15px;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
            align-items: center;
        }
        
        .action-buttons .btn {
            padding: 6px 12px;
            font-size: 0.875rem;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        
        .action-buttons .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .status-badge {
            font-size: 0.85em;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        /* Search Box */
        .search-box {
            margin-bottom: 25px;
        }
        
        .search-box input {
            border-radius: 8px;
            border: 2px solid #e9ecef;
            padding: 12px 20px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        
        .search-box input:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.15);
        }
        
        /* Toast Container */
        .toast-container {
            z-index: 1100;
        }
        
        /* Responsive Design */
        @media (max-width: 992px) {
            .sticky-sidebar {
                position: fixed;
                left: -280px;
                top: 56px;
                height: calc(100vh - 56px);
                z-index: 1030;
            }
            
            .sticky-sidebar.show {
                left: 0;
            }
            
            .content-area {
                padding: 20px;
            }
            
            .sidebar-toggle {
                position: fixed;
                top: 70px;
                left: 10px;
                z-index: 1025;
                background: #2c3e50;
                color: #fff;
                border: none;
                border-radius: 8px;
                padding: 10px 15px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            }
            
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 1025;
            }
            
            .sidebar-overlay.show {
                display: block;
            }
        }
        
        @media (min-width: 993px) {
            .sidebar-toggle {
                display: none;
            }
            
            .sidebar-overlay {
                display: none !important;
            }
        }
        
        /* Smooth Scrolling */
        html {
            scroll-behavior: smooth;
        }
        
        /* Interactive Brand Hover */
        .interactive-brand:hover .brand-heading,
        .interactive-brand:hover .brand-subheading {
            color: rgba(0, 102, 255, 0.43) !important;
        }
        
        /* Footer */
        footer {
            background: #2c3e50;
            color: #fff;
            padding: 20px 0;
            margin-top: auto;
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
                    <a class="nav-link <?= ($current_page == 'Event.php') ? 'active' : '' ?>" href="Event.php">Events</a>
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

    <!-- Sidebar Toggle Button (Mobile) -->
    <button class="sidebar-toggle" id="sidebarToggle">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar Overlay (Mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Sticky Sidebar -->
        <aside class="sticky-sidebar" id="sidebar">
            <div class="sidebar-header">
                <h4>Admin Panel</h4>
                <p>Welcome, <?= htmlspecialchars($name) ?></p>
            </div>
            <ul class="sidebar-nav">
                <li>
                    <a href="#events-section" class="nav-link active" data-section="events-section">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Event List</span>
                    </a>
                </li>
                <li>
                    <a href="#matches-section" class="nav-link" data-section="matches-section">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Matches Schedule</span>
                    </a>
                </li>
                <li>
                    <a href="#sports-section" class="nav-link" data-section="sports-section">
                        <i class="fas fa-futbol"></i>
                        <span>Manage Sports</span>
                    </a>
                </li>
            </ul>
        </aside>

        <!-- Main Content Area -->
        <div class="content-area">
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                    <?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Event List Section -->
            <section id="events-section" class="content-section">
                <div id="eventsContent">
                    <p class="text-center text-muted">Loading events...</p>
                </div>
            </section>

            <!-- Matches Schedule Section -->
            <section id="matches-section" class="content-section">
                <div id="matchesContent">
                    <p class="text-center text-muted">Loading matches schedule...</p>
                </div>
            </section>

            <!-- Manage Sports Section -->
            <section id="sports-section" class="content-section">
                <div id="sportsContent">
                    <p class="text-center text-muted">Loading sports...</p>
                </div>
            </section>
        </div>
    </div>

    <!-- Toast Container -->
    <div aria-live="polite" aria-atomic="true" class="position-relative">
        <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100;">
            <!-- Toast messages will be appended here -->
        </div>
    </div>

    <!-- Footer -->
    <footer class="text-center">
        <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
        <small>Developed by Tsunayoshi Sawada</small>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        

        document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const navLinks = document.querySelectorAll('.sidebar-nav .nav-link');
    const eventCategoryMap = <?php echo json_encode($eventCategoryMap); ?>;

    // ============================================
    // TOAST NOTIFICATION SYSTEM
    // ============================================
    function showToast(message, type = 'success') {
        const toastContainer = document.querySelector('.toast-container');
        if (!toastContainer) {
            console.error('Toast container not found!');
            return;
        }

        const toastId = `toast-${Date.now()}`;
        const bgColor = type === 'success' ? 'bg-success' : (type === 'danger' ? 'bg-danger' : 'bg-info');
        const icon = type === 'success' ? 'fas fa-check-circle' : (type === 'danger' ? 'fas fa-times-circle' : 'fas fa-info-circle');

        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-white ${bgColor} border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="${icon} me-2"></i>${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        `;

        toastContainer.insertAdjacentHTML('beforeend', toastHtml);
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, { delay: 5000 });
        toast.show();

        toastElement.addEventListener('hidden.bs.toast', function () {
            toastElement.remove();
        });
    }

    window.showToast = showToast;

    // ============================================
    // CONTENT LOADING SYSTEM
    // ============================================
    window.loadContent = function(page, containerId) {
        const container = document.getElementById(containerId);
        if (!container) {
            console.error(`Container with ID "${containerId}" not found!`);
            return;
        }

        container.innerHTML = '<p class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin me-2"></i>Loading...</p>';

        fetch(page)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.text();
            })
            .then(html => {
                container.innerHTML = html;
                
                // Re-initialize JS based on the loaded content
                setTimeout(() => {
                    if (page === 'manage_events_list.php') {
                        initializeManageEventsListJS();
                    } else if (page === 'manage_matches_schedule.php') {
                        initializeManageMatchesScheduleJS();
                    }
                }, 0);
            })
            .catch(error => {
                container.innerHTML = '<div class="alert alert-danger text-center">Error loading content: ' + error.message + '</div>';
                console.error('Error loading content:', error);
            });
    };

    // ============================================
    // SIDEBAR FUNCTIONALITY
    // ============================================
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
            sidebarOverlay.classList.toggle('show');
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('show');
            sidebarOverlay.classList.remove('show');
        });
    }

    // Sidebar navigation
    navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            
            navLinks.forEach(navLink => navLink.classList.remove('active'));
            this.classList.add('active');
            
            if (window.innerWidth <= 992) {
                sidebar.classList.remove('show');
                sidebarOverlay.classList.remove('show');
            }
            
            const targetSection = this.getAttribute('data-section');
            const section = document.getElementById(targetSection);
            if (section) {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    // Update active link on scroll
    const sections = document.querySelectorAll('.content-section');
    const observerOptions = {
        root: null,
        rootMargin: '-100px 0px -60% 0px',
        threshold: 0
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const sectionId = entry.target.getAttribute('id');
                navLinks.forEach(link => {
                    if (link.getAttribute('data-section') === sectionId) {
                        navLinks.forEach(l => l.classList.remove('active'));
                        link.classList.add('active');
                    }
                });
            }
        });
    }, observerOptions);

    sections.forEach(section => observer.observe(section));

    // Load initial content
    loadContent('manage_events_list.php', 'eventsContent');
    loadContent('manage_matches_schedule.php', 'matchesContent');
    loadContent('Manage_Sports.php', 'sportsContent');

    // Brand logo click handler
    const brandElement = document.querySelector('.interactive-brand');
    if (brandElement) {
        brandElement.addEventListener('click', function(e) {
            e.preventDefault();
            window.location.href = 'Tournament_Manager_page.php';
        });
    }

    // ============================================
    // EVENTS LIST INITIALIZATION FUNCTION
    // ============================================
    window.initializeManageEventsListJS = function() {
        
        // Helper function for TBA toggle
        function setupTbaToggle(dateInputId, tbaCheckboxId) {
            const dateInput = document.getElementById(dateInputId);
            const tbaCheckbox = document.getElementById(tbaCheckboxId);

            if (!dateInput || !tbaCheckbox) return;

            const toggleInputState = () => {
                if (tbaCheckbox.checked) {
                    dateInput.value = '';
                    dateInput.setAttribute('disabled', 'disabled');
                    dateInput.removeAttribute('required');
                } else {
                    dateInput.removeAttribute('disabled');
                    dateInput.setAttribute('required', 'required');
                }
            };
            
            toggleInputState();
            tbaCheckbox.addEventListener('change', toggleInputState);
        }

        // Helper function for subcategory toggle
        function setupSubCategoryToggle(categorySelectId, subCategoryDivId, subCategorySelectId) {
            const categorySelect = document.getElementById(categorySelectId);
            const subCategoryDiv = document.getElementById(subCategoryDivId);
            const subCategorySelect = document.getElementById(subCategorySelectId);

            if (!categorySelect || !subCategoryDiv || !subCategorySelect) return;

            const toggleSubCategory = () => {
                const selectedCategory = categorySelect.value;
                if (selectedCategory === 'Basketball' || selectedCategory === 'Volleyball') {
                    subCategoryDiv.style.display = 'block';
                    subCategorySelect.setAttribute('required', 'required');
                } else {
                    subCategoryDiv.style.display = 'none';
                    subCategorySelect.removeAttribute('required');
                    subCategorySelect.value = '';
                }
            };

            toggleSubCategory();
            categorySelect.addEventListener('change', toggleSubCategory);
        }

        // Add Event Modal initialization
        const addEventModalElement = document.getElementById('addEventModal');
        if (addEventModalElement) {
            addEventModalElement.addEventListener('shown.bs.modal', function () {
                setupTbaToggle('addStartDate', 'addStartDateTBA');
                setupTbaToggle('addEndDate', 'addEndDateTBA');
                setupSubCategoryToggle('addCategory', 'addSubCategoryDiv', 'addSubCategory');
                
                const form = document.getElementById('addEventForm');
                if (form) form.reset();
                
                const startTbaCheckbox = document.getElementById('addStartDateTBA');
                const endTbaCheckbox = document.getElementById('addEndDateTBA');
                if (startTbaCheckbox) startTbaCheckbox.dispatchEvent(new Event('change'));
                if (endTbaCheckbox) endTbaCheckbox.dispatchEvent(new Event('change'));
            });
        }

        // Edit Event Modal initialization
        const editEventModalElement = document.getElementById('editEventModal');
        if (editEventModalElement) {
            editEventModalElement.addEventListener('shown.bs.modal', function () {
                setupTbaToggle('editStartDate', 'editStartDateTBA');
                setupTbaToggle('editEndDate', 'editEndDateTBA');
                setupSubCategoryToggle('editCategory', 'editSubCategoryDiv', 'editSubCategory');
                
                const startTbaCheckbox = document.getElementById('editStartDateTBA');
                const endTbaCheckbox = document.getElementById('editEndDateTBA');
                if (startTbaCheckbox) startTbaCheckbox.dispatchEvent(new Event('change'));
                if (endTbaCheckbox) endTbaCheckbox.dispatchEvent(new Event('change'));
            });
        }

        // ============================================
        // FETCH EVENT DATA FOR EDIT/VIEW
        // ============================================
        
        // Helper function to format date for input
        function formatDateForInput(dateString) {
            if (!dateString || dateString === '0000-00-00' || dateString === 'TBA') return '';
            const date = new Date(dateString);
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

       // EDIT EVENT BUTTON HANDLER
document.querySelectorAll('.edit-event-btn').forEach(button => {
    button.addEventListener('click', function() {
        const eventId = this.dataset.id;
        const modalElement = document.getElementById('editEventModal');
        
        // 1. Get the existing Modal instance (if any) or create a new one
        let editEventModal = bootstrap.Modal.getInstance(modalElement);
        if (!editEventModal) {
            editEventModal = new bootstrap.Modal(modalElement);
        }

        // 2. Hide the modal immediately if it's visible, to reset its state
        // This is a common requirement to prevent display issues during asynchronous data loading.
        editEventModal.hide(); 
        
        // --- START DATA FETCH ---
        // Fetch event data from server
        fetch(`fetch_event_data.php?id=${eventId}`)
            .then(response => {
                // 1. HTTP Status Check (Fetch Status)
                if (!response.ok) {
                    throw new Error(`HTTP Error: ${response.status} ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                // 2. Server-side Status Check (Application Status)
                if (data.success) {
                    const event = data.data;
                    
                    // Populate all fields (UPDATED for three medal fields)
                    const fields = {
                        'editEventId': event.event_id,
                        'editEventName': event.event_name,
                        // Note: sport_id is now retrieved from fetch_event_data.php
                        'editSportId': event.sport_id || '', 
                        'editEventStatus': event.event_status || 'Upcoming',
                        'editStartDate': formatDateForInput(event.start_date), 
                        'editEndDate': formatDateForInput(event.end_date),
                        'editDescription': event.description,
                        // 👇 NEW MEDAL FIELDS
                        'editGoldCount': event.gold_count || 0,
                        'editSilverCount': event.silver_count || 0,
                        'editBronzeCount': event.bronze_count || 0
                    };
                    
                    Object.entries(fields).forEach(([id, value]) => {
                        const element = document.getElementById(id);
                        if (element) {
                            element.value = value || '';
                        }
                    });
                    
                    // Handle TBA checkboxes (Your existing logic)
                    const startDateTBA = document.getElementById('editStartDateTBA');
                    const endDateTBA = document.getElementById('editEndDateTBA');
                    
                    if (startDateTBA) {
                        startDateTBA.checked = (event.start_date === 'TBA' || event.start_date === '0000-00-00' || !event.start_date);
                        startDateTBA.dispatchEvent(new Event('change'));
                    }
                    
                    if (endDateTBA) {
                        endDateTBA.checked = (event.end_date === 'TBA' || event.end_date === '0000-00-00' || !event.end_date);
                        endDateTBA.dispatchEvent(new Event('change'));
                    }
                    
                    // Trigger category change (Your existing logic)
                    const categorySelect = document.getElementById('editCategory');
                    if (categorySelect) {
                        categorySelect.dispatchEvent(new Event('change'));
                    }
                    
                    // 3. SHOW the modal only after all data is successfully loaded.
                    editEventModal.show(); 
                    
                } else {
                    // Handle application-level error
                    showToast('Error: ' + (data.error || 'Failed to fetch event data'), 'danger');
                }
            })
            .catch(error => {
                // 4. Catch and handle errors
                showToast('Fetch Error: ' + error.message, 'danger');
                console.error('Fetch Operation Failed:', error);
            });
    });
});

        // VIEW EVENT BUTTON HANDLER
        const viewEventModal = new bootstrap.Modal(document.getElementById('viewEventModal'));
        document.querySelectorAll('.view-event-btn').forEach(button => {
            button.addEventListener('click', function() {
                const eventId = this.dataset.id;
                // Show the modal
                 
                viewEventModal.show();
                // Fetch event data from server
                fetch(`fetch_event_data.php?id=${eventId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const event = data.data;
                            
                            // Populate view modal fields (UPDATED for three medal fields)
                            const viewFields = {
                                'viewEventId': event.event_id,
                                'viewEventName': event.event_name,
                                'viewCategory': event.category,
                                'viewSportName': event.sport_name || 'N/A', // Assuming fetch_event_data.php returns this
                                'viewEventStatus': event.event_status || 'N/A', 
                                'viewStartDate': event.start_date === 'TBA' ? 'TBA' : event.start_date,
                                'viewEndDate': event.end_date === 'TBA' ? 'TBA' : event.end_date,
                                'viewDescription': event.description || 'No description provided',
                                // 👇 NEW VIEW FIELDS
                                'viewGoldCount': event.gold_count || 0,
                                'viewSilverCount': event.silver_count || 0,
                                'viewBronzeCount': event.bronze_count || 0
                            };
                            
                            // Set all view field text content
                            Object.entries(viewFields).forEach(([id, value]) => {
                                const element = document.getElementById(id);
                                if (element) {
                                    element.textContent = value;
                                }
                            });    
                            
                            
                        } else {
                            showToast('Error: ' + (data.error || 'Failed to fetch event data'), 'danger');
                        }
                        
                    })
                    .catch(error => {
                        showToast('Network error: ' + error.message, 'danger');
                        console.error('Fetch Error:', error);
                    });
            });
        });

        // DELETE EVENT BUTTON HANDLER
        document.querySelectorAll('.delete-event-btn').forEach(button => {
            button.addEventListener('click', function() {
                const eventId = this.dataset.id;
                const eventName = this.dataset.name || 'this event';
                
                if (confirm(`Are you sure you want to delete "${eventName}"? This action cannot be undone.`)) {
                    fetch('delete_event.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `id=${eventId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showToast('Event deleted successfully!', 'success');
                            // Remove the row from table
                            this.closest('tr').remove();
                        } else {
                            showToast('Error: ' + (data.error || 'Failed to delete event'), 'danger');
                        }
                    })
                    .catch(error => {
                        showToast('Network error: ' + error.message, 'danger');
                        console.error('Delete Error:', error);
                    });
                }
            });
        });

        console.log('Event List JS initialized successfully');
    };

    // ============================================
    // MATCHES SCHEDULE INITIALIZATION FUNCTION
    // ============================================
    window.initializeManageMatchesScheduleJS = function() {
        
        // ============================================
        // HELPER FUNCTIONS
        // ============================================
        function formatDateForInput(dateString) {
            if (!dateString || dateString === '0000-00-00' || dateString === 'TBA') return '';
            const date = new Date(dateString);
            return date.toISOString().split('T')[0];
        }
        
        function formatTimeForInput(timeString) {
            if (!timeString || timeString === '00:00:00' || timeString === 'TBA') return '';
            return timeString.substring(0, 5);
        }
        
        function formatDateTimeForInput(dateTimeString) {
            if (!dateTimeString || dateTimeString === '0000-00-00 00:00:00' || dateTimeString === 'N/A') return '';
            const date = new Date(dateTimeString);
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            const hours = String(date.getHours()).padStart(2, '0');
            const minutes = String(date.getMinutes()).padStart(2, '0');
            return `${year}-${month}-${day}T${hours}:${minutes}`;
        }
        
        function closeModalProperly(modalId, callback) {
            const modalElement = document.getElementById(modalId);
            if (!modalElement) return;

            // This part is correct, it gets the instance.
            let modalInstance = bootstrap.Modal.getInstance(modalElement);
            if (!modalInstance) {
                modalInstance = new bootstrap.Modal(modalElement);
            }

            modalInstance.hide();

            modalElement.addEventListener('hidden.bs.modal', function cleanupHandler() {
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
                
                const backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(backdrop => backdrop.remove());
                
                if (callback && typeof callback === 'function') {
                    callback();
                }
                
                modalElement.removeEventListener('hidden.bs.modal', cleanupHandler);
            }, { once: true });
        }
        
        window.clearMatchResults = function() {
            if (confirm('Clear all result data for this match?')) {
                const score1Input = document.getElementById('editScore1');
                const score2Input = document.getElementById('editScore2');
                const winnerSelect = document.getElementById('editWinnerTeamId');
                const timeFinishedInput = document.getElementById('editTimeFinished');
                
                if (score1Input) score1Input.value = '';
                if (score2Input) score2Input.value = '';
                if (winnerSelect) winnerSelect.value = '';
                if (timeFinishedInput) timeFinishedInput.value = '';
                
                showToast('Results cleared. Remember to save changes.', 'info');
            }
        };
        
        // ============================================
        // ADD MATCH MODAL (This logic is correct)
        // ============================================
        const addMatchModal = document.getElementById('addMatchModal');
        if (addMatchModal) {
            addMatchModal.addEventListener('shown.bs.modal', function () {
                const form = document.getElementById('addMatchForm');
                if (form) form.reset();
                
                const categoryInput = document.getElementById('addSportCategory');
                if (categoryInput) categoryInput.removeAttribute('readonly');
            });
            
            addMatchModal.addEventListener('hidden.bs.modal', function () {
                const form = document.getElementById('addMatchForm');
                if (form) form.reset();
            });
        }
        
        // Auto-fill Sport Category (This uses the 'eventCategoryMap' we fixed before)
        const addEventSelect = document.getElementById('addEventIdMatch');
        const addCategoryInput = document.getElementById('addSportCategory');
        
        if (addEventSelect && addCategoryInput) {
            addEventSelect.addEventListener('change', function() {
                const selectedEventId = this.value;
                
                // Check if the global eventCategoryMap exists
                if (typeof eventCategoryMap !== 'undefined' && eventCategoryMap[selectedEventId]) {
                    const category = eventCategoryMap[selectedEventId];
                    addCategoryInput.value = category;
                    addCategoryInput.setAttribute('readonly', 'readonly');
                } else {
                    addCategoryInput.value = '';
                    addCategoryInput.removeAttribute('readonly');
                }
            });
        }
        
        // ============================================
        // FORM SUBMISSIONS (These are fine)
        // ============================================
        
        // Add Match Form
        const addMatchForm = document.getElementById('addMatchForm');
        if (addMatchForm) {
            addMatchForm.addEventListener('submit', function(event) {
                event.preventDefault();
                const formData = new FormData(addMatchForm);
                fetch('manage_matches_schedule.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message || 'Match added successfully!', 'success');
                        closeModalProperly('addMatchModal', function() {
                            loadContent('manage_matches_schedule.php', 'matchesContent');
                        });
                    } else {
                        showToast('Error: ' + (data.error || 'Unknown error.'), 'danger');
                    }
                })
                .catch(error => {
                    showToast('Network error: ' + error.message, 'danger');
                    console.error('Error:', error);
                });
            });
        }
        
        // Add Result Buttons (This is fine)
        document.querySelectorAll('.add-result-btn').forEach(button => {
            button.addEventListener('click', function() {
                // ... (all your data attribute logic is correct) ...
                const eventName = this.dataset.eventName; 
                const matchId = this.getAttribute('data-match-id');
                const team1Id = this.getAttribute('data-team1-id');
                const team2Id = this.getAttribute('data-team2-id');
                const team1College = this.getAttribute('data-team1-college');
                const team2College = this.getAttribute('data-team2-college');
                const score1 = this.getAttribute('data-score1');
                const score2 = this.getAttribute('data-score2');
                const winnerTeamId = this.getAttribute('data-winner-team-id');
                const timeFinished = this.getAttribute('data-time-finished');
                const status = this.dataset.status;

                const matchIdInput = document.getElementById('resultMatchId');
                if (matchIdInput) matchIdInput.value = matchId;
                
                const eventNameInput = document.getElementById('resultEventName');
                const matchStatusInput = document.getElementById('resultMatchStatus');
                
                if (eventNameInput) eventNameInput.value = eventName || 'N/A';
                if (matchStatusInput) matchStatusInput.value = status || 'Upcoming';

                const labelScore1 = document.getElementById('labelScore1');
                const labelScore2 = document.getElementById('labelScore2');
                if (labelScore1) labelScore1.textContent = `Score (${team1College}):`;
                if (labelScore2) labelScore2.textContent = `Score (${team2College}):`;

                const winnerSelect = document.getElementById('resultWinnerTeamId');
                if (winnerSelect) {
                    winnerSelect.innerHTML = '<option value="">Select Winner</option>';
                    
                    const option1 = document.createElement('option');
                    option1.value = team1Id;
                    option1.textContent = team1College;
                    winnerSelect.appendChild(option1);

                    const option2 = document.createElement('option');
                    option2.value = team2Id;
                    option2.textContent = team2College;
                    winnerSelect.appendChild(option2);

                    if (winnerTeamId) {
                        winnerSelect.value = winnerTeamId;
                    }
                }

                const score1Input = document.getElementById('resultScore1');
                const score2Input = document.getElementById('resultScore2');
                if (score1Input) score1Input.value = score1 || '';
                if (score2Input) score2Input.value = score2 || '';

                const timeFinishedInput = document.getElementById('resultTimeFinished');
                if (timeFinishedInput) {
                    if (timeFinished && timeFinished !== '0000-00-00 00:00:00') {
                        timeFinishedInput.value = timeFinished.substring(0, 16).replace(' ', 'T');
                    } else {
                        const now = new Date();
                        const year = now.getFullYear();
                        const month = String(now.getMonth() + 1).padStart(2, '0');
                        const day = String(now.getDate()).padStart(2, '0');
                        const hours = String(now.getHours()).padStart(2, '0');
                        const minutes = String(now.getMinutes()).padStart(2, '0');
                        timeFinishedInput.value = `${year}-${month}-${day}T${hours}:${minutes}`;
                    }
                }
                
                const statusSelect = document.getElementById('resultStatus');
                if (statusSelect) statusSelect.value = status || 'Completed';
            });
        });

        // Add Result Form (This is fine)
        const addResultForm = document.getElementById('addResultForm');
        if (addResultForm) {
            addResultForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                
                if (!formData.get('score1') || !formData.get('score2') || !formData.get('winner_team_id')) {
                    showToast("Match ID, both scores, and a Winner Team are required.", 'danger');
                    return;
                }

                fetch('manage_matches_schedule.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        closeModalProperly('addResultModal', function() {
                            loadContent('manage_matches_schedule.php', 'matchesContent');
                        });
                    } else {
                        showToast('Error: ' + (data.error || 'Unknown error.'), 'danger');
                    }
                })
                .catch(error => {
                    showToast('AJAX Error: ' + error.message, 'danger');
                    console.error('Error:', error);
                });
            });
        }
        
        // Edit Match Form (This is fine)
        const editMatchForm = document.getElementById('editMatchForm');
        if (editMatchForm) {
            editMatchForm.addEventListener('submit', function(event) {
                event.preventDefault();
                const formData = new FormData(editMatchForm);
                fetch('manage_matches_schedule.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message || 'Match updated successfully!', 'success');
                        closeModalProperly('editMatchModal', function() {
                            loadContent('manage_matches_schedule.php', 'matchesContent');
                        });
                    } else {
                        showToast('Error: ' + (data.error || 'Unknown error.'), 'danger');
                    }
                })
                .catch(error => {
                    showToast('Network error: ' + error.message, 'danger');
                    console.error('Error:', error);
                });
            });
        }
        
        // ============================================
        // SEARCH FUNCTIONALITY (This is fine)
        // ============================================
        const matchSearchInput = document.getElementById('matchSearch');
        const matchesTableBody = document.querySelector('#matchesTable tbody');

        if (matchSearchInput && matchesTableBody) {
            matchSearchInput.addEventListener('keyup', function() {
                const searchTerm = this.value.toLowerCase();
                const matchesTableRows = matchesTableBody.querySelectorAll('tr');
                let visibleRowsCount = 0;

                matchesTableRows.forEach(row => {
                    if (row.classList.contains('text-muted') && row.querySelector('td[colspan]')) {
                        row.style.display = 'none';
                        return;
                    }
                    if (row.children.length < 7) return;
                    const rowText = Array.from(row.children).slice(0, -1).map(cell => 
                        cell.textContent.toLowerCase()
                    ).join(' ');

                    if (rowText.includes(searchTerm)) {
                        row.style.display = '';
                        visibleRowsCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });
                updateNoMatchesFoundMessage(visibleRowsCount);
            });
        }

        function updateNoMatchesFoundMessage(visibleRowsCount) {
            if (!matchesTableBody) return;
            let noMatchesRow = matchesTableBody.querySelector('.no-matches-found');
            if (visibleRowsCount === 0) {
                if (!noMatchesRow) {
                    noMatchesRow = document.createElement('tr');
                    noMatchesRow.className = 'no-matches-found';
                    noMatchesRow.innerHTML = '<td colspan="8" class="text-center text-muted py-4">No matching matches found.</td>';
                    matchesTableBody.appendChild(noMatchesRow);
                }
                noMatchesRow.style.display = '';
            } else if (noMatchesRow) {
                noMatchesRow.style.display = 'none';
            }
        }
        
        // ============================================
        // DELETE MATCH (This is fine)
        // ============================================
        document.querySelectorAll('.delete-match-btn').forEach(button => {
            button.addEventListener('click', function() {
                const matchId = this.dataset.id;
                if (confirm('Are you sure you want to delete this match? This action cannot be undone.')) {
                    fetch('delete_match.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `id=${matchId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showToast('Match deleted successfully!', 'success');
                            this.closest('tr').remove();
                            if (matchesTableBody) {
                                const visibleRows = matchesTableBody.querySelectorAll('tr:not(.text-muted):not([style*="display: none"])');
                                updateNoMatchesFoundMessage(visibleRows.length);
                            }
                        } else {
                            showToast('Error: ' + data.error, 'danger');
                        }
                    })
                    .catch(error => {
                        showToast('Network error: ' + error.message, 'danger');
                        console.error('Error:', error);
                    });
                }
            });
        });
        
        // ============================================
        // EDIT MATCH MODAL (*** THIS IS FIXED ***)
        // ============================================
        document.querySelectorAll('.edit-match-btn, .edit-match-btn-result').forEach(button => {
            button.addEventListener('click', function() {
                const matchId = this.dataset.id;
                
                fetch(`fetch_match_data.php?id=${matchId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const match = data.data;
                            
                            const fields = {
                                'editMatchId': match.match_id,
                                'editEventIdMatch': match.event_id,
                                'editSportCategory': match.sport_category,
                                'editTeam1Id': match.team1_id,
                                'editTeam2Id': match.team2_id,
                                'editMatchDate': formatDateForInput(match.match_date),
                                'editMatchTime': formatTimeForInput(match.match_time),
                                'editVenue': match.venue,
                                'editMatchStatus': match.status
                            };
                            
                            Object.entries(fields).forEach(([id, value]) => {
                                const element = document.getElementById(id);
                                if (element) element.value = value || '';
                            });
                            
                            // --- FIX ---
                            // Get the modal element
                            const editModalElement = document.getElementById('editMatchModal');
                            if (editModalElement) {
                                // Get or create the instance and show it
                                const editMatchModal = bootstrap.Modal.getOrCreateInstance(editModalElement);
                                editMatchModal.show();
                            } else {
                                showToast('Error: Edit modal element not found', 'danger');
                            }
                            
                        } else {
                            showToast('Error: ' + data.error, 'danger');
                        }
                    })
                    .catch(error => {
                        showToast('Network error: ' + error.message, 'danger');
                        console.error('Error:', error);
                    });
            });
        });
        
        // ============================================
        // VIEW MATCH MODAL (delegated for dynamic content)
        // ============================================
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.view-match-btn');
            if (!btn) return;

            const modalEl = document.getElementById('viewMatchModal');
            if (!modalEl) {
                showToast('Error: View modal not found in DOM.', 'danger');
                return;
            }
            // Ensure modal is attached to <body> to avoid stacking context/z-index issues
            if (modalEl.parentElement !== document.body) {
                document.body.appendChild(modalEl);
            }
            // Deduplicate any accidentally duplicated #viewMatchModal from re-loads
            const allViewModals = document.querySelectorAll('#viewMatchModal');
            if (allViewModals.length > 1) {
                allViewModals.forEach((el, idx) => { if (idx > 0) el.remove(); });
            }
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

            const matchId = btn.dataset.id;
            if (!matchId) {
                showToast('Error: Match ID not found', 'danger');
                return;
            }

            // Force-close any stray backdrops before showing (defensive)
            document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            modal.show();

            fetch(`fetch_match_data.php?id=${matchId}`)
                .then(r => {
                    if (!r.ok) throw new Error(`HTTP ${r.status}`);
                    return r.json();
                })
                .then(data => {
                    if (!data.success) {
                        showToast('Error: ' + (data.error || 'Failed to fetch match data'), 'danger');
                        modal.hide();
                        return;
                    }
                    const match = data.data;
                    let formattedTime = 'N/A';
                    if (match.match_time) {
                        try {
                            const [hours, minutes] = match.match_time.split(':');
                            const d = new Date();
                            d.setHours(hours, minutes, 0);
                            formattedTime = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                        } catch (_) {
                            formattedTime = match.match_time;
                        }
                    }

                    const viewFields = {
                        'viewMatchId': match.match_id,
                        'viewEventName': match.event_name || 'N/A',
                        'viewSportCategory': match.sport_category || 'N/A',
                        'viewTeams': `${match.team1_name} vs ${match.team2_name}`,
                        'viewMatchDate': match.match_date || 'N/A',
                        'viewMatchTime': formattedTime,
                        'viewVenue': match.venue || 'N/A',
                        'viewMatchStatus': match.status || 'N/A'
                    };
                    Object.entries(viewFields).forEach(([id, value]) => {
                        const el = document.getElementById(id);
                        if (el) el.textContent = value;
                    });
                })
                .catch(err => {
                    showToast('Network error: ' + err.message, 'danger');
                    modal.hide();
                });
        });
        
        // ============================================
        // MODAL CLEANUP (This is fine)
        // ============================================
        ['addMatchModal', 'addResultModal', 'editMatchModal', 'viewMatchModal'].forEach(modalId => {
            const modalElement = document.getElementById(modalId);
            if (modalElement) {
                modalElement.addEventListener('hidden.bs.modal', function() {
                    setTimeout(() => {
                        document.body.classList.remove('modal-open');
                        document.body.style.overflow = '';
                        document.body.style.paddingRight = '';
                        const backdrops = document.querySelectorAll('.modal-backdrop');
                        backdrops.forEach(backdrop => backdrop.remove());
                    }, 100);
                });
            }
        });
        
        console.log('Match Schedule JS initialized successfully');
    };

}); // End DOMContentLoaded
    
</script>
</body>
</html>