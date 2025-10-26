<?php
session_start();

require_once 'config.php'; // Make sure this path is correct

// PHP variables for header/footer consistency
$current_page = basename($_SERVER['PHP_SELF']);
$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin'; // User name for display

// --- Fetch Event Data from Database ---
$events = [];
$total_events = 0;
$completed_events = 0;
$ongoing_events = 0;

$sql_fetch_events = "SELECT e.event_id, e.event_name, s.sport_name, s.category, e.event_status, e.start_date, e.end_date, e.description 
                    FROM events e 
                    JOIN sports s ON e.sport_id = s.sport_id 
                    ORDER BY e.start_date ASC";
$stmt_fetch_events = $conn->prepare($sql_fetch_events);

if ($stmt_fetch_events) {
    $stmt_fetch_events->execute();
    $result_events = $stmt_fetch_events->get_result();

    if ($result_events) {
        while ($row = $result_events->fetch_assoc()) {
            $events[] = $row;
            $total_events++;

            // Count events based on status
            $status_lower = strtolower($row['event_status']);
            if ($status_lower === 'completed') {
                $completed_events++;
            } elseif ($status_lower === 'ongoing') {
                $ongoing_events++;
            }
        }
    }
    $stmt_fetch_events->close();
} else {
    // Handle database error, though for this display page, we might just show no events
    error_log("Error preparing event fetch statement: " . $conn->error);
}

// Close database connection
$conn->close();

/**
 * Helper function to determine badge class based on the STORED status for event cards.
 * THIS IS THE PHP VERSION - DO NOT CALL FROM JAVASCRIPT DIRECTLY.
 * @param string $status The stored status string (e.g., 'Upcoming', 'Completed', 'Ongoing')
 * @return string The Bootstrap badge class
 */
function getEventCardStatusClass($status) {
    switch (strtolower($status)) {
        case 'upcoming':
            return 'bg-info'; // Blue
        case 'ongoing':
            return 'bg-success'; // Green
        case 'completed':
            return 'bg-secondary'; // Grey
        case 'cancelled':
            return 'bg-danger'; // Red
        case 'postponed':
            return 'bg-warning text-dark'; // Yellow
        case 'draft':
            return 'bg-light text-dark'; // Light grey
        default:
            return 'bg-primary'; // Default blue if status is unknown
    }
}

/**
 * Helper function to determine Iconify icon name, an optional gender-specific icon, and a color class
 * based on event name and category.
 * Prioritizes specific sport names from event_name, then falls back to category.
 * Uses Material Design Icons (mdi) from Iconify.
 * @param array $event The event data array (must contain 'event_name' and 'category')
 * @return array An associative array with 'main_icon', 'gender_icon', and 'color' classes.
 */
function getEventIconAndColor($event) {
    $event_name_lower = strtolower($event['event_name']);
    $category_lower = strtolower($event['category']);

    $main_icon_data_icon = 'mdi:calendar'; // Default generic icon (Iconify name)
    $gender_icon_data_icon = ''; // Optional: for male/female distinction
    $color_class = 'text-muted'; // Default muted color

    // Check for gender indicators
    $is_male = str_contains($event_name_lower, 'men') || str_contains($event_name_lower, 'male');
    $is_female = str_contains($event_name_lower, 'women') || str_contains($event_name_lower, 'female');

    // 1. Try to match specific sport names from event_name
    if (str_contains($event_name_lower, 'basketball')) {
        $main_icon_data_icon = 'mdi:basketball';
        $color_class = 'text-orange';
        if ($is_male) $gender_icon_data_icon = 'mdi:gender-male';
        if ($is_female) $gender_icon_data_icon = 'mdi:gender-female';
    } elseif (str_contains($event_name_lower, 'volleyball')) {
        $main_icon_data_icon = 'mdi:volleyball';
        $color_class = 'text-primary';
        if ($is_male) $gender_icon_data_icon = 'mdi:gender-male';
        if ($is_female) $gender_icon_data_icon = 'mdi:gender-female';
    } elseif (str_contains($event_name_lower, 'football') || str_contains($event_name_lower, 'soccer')) {
        $main_icon_data_icon = 'mdi:soccer';
        $color_class = 'text-success';
        if ($is_male) $gender_icon_data_icon = 'mdi:gender-male';
        if ($is_female) $gender_icon_data_icon = 'mdi:gender-female';
    } elseif (str_contains($event_name_lower, 'tennis')) {
        $main_icon_data_icon = 'mdi:tennis';
        $color_class = 'text-info';
        if ($is_male) $gender_icon_data_icon = 'mdi:gender-male';
        if ($is_female) $gender_icon_data_icon = 'mdi:gender-female';
    } elseif (str_contains($event_name_lower, 'swimming')) {
        $main_icon_data_icon = 'mdi:swim';
        $color_class = 'text-blue';
        if ($is_male) $gender_icon_data_icon = 'mdi:gender-male';
        if ($is_female) $gender_icon_data_icon = 'mdi:gender-female';
    } elseif (str_contains($event_name_lower, 'badminton')) {
        $main_icon_data_icon = 'mdi:badminton';
        $color_class = 'text-danger';
        if ($is_male) $gender_icon_data_icon = 'mdi:gender-male';
        if ($is_female) $gender_icon_data_icon = 'mdi:gender-female';
    } elseif (str_contains($event_name_lower, 'chess')) {
        $main_icon_data_icon = 'mdi:chess-king'; // Or mdi:chess-knight if preferred
        $color_class = 'text-dark';
    } elseif (str_contains($event_name_lower, 'track') || str_contains($event_name_lower, 'running')) {
        $main_icon_data_icon = 'mdi:run';
        $color_class = 'text-success';
        if ($is_male) $gender_icon_data_icon = 'mdi:gender-male';
        if ($is_female) $gender_icon_data_icon = 'mdi:gender-female';
    } elseif (str_contains($event_name_lower, 'table tennis') || str_contains($event_name_lower, 'ping pong')) {
        $main_icon_data_icon = 'mdi:table-tennis';
        $color_class = 'text-info';
        if ($is_male) $gender_icon_data_icon = 'mdi:gender-male';
        if ($is_female) $gender_icon_data_icon = 'mdi:gender-female';
    } elseif (str_contains($event_name_lower, 'esports') || str_contains($event_name_lower, 'gaming')) {
        $main_icon_data_icon = 'mdi:gamepad-variant';
        $color_class = 'text-purple'; // Custom color
    }
    // Add more sport mappings here as needed

    // 2. Fallback to general category if no specific sport match was found
    // This condition checks if the main_icon_data_icon is still the default one, meaning no specific sport was matched.
    // We also ensure no gender icon was set by a sport-specific rule.
    if ($main_icon_data_icon === 'mdi:calendar' && empty($gender_icon_data_icon)) {
        switch ($category_lower) {
            case 'sports':
                $main_icon_data_icon = 'mdi:medal'; // Generic sports medal
                $color_class = 'text-primary';
                break;
            case 'academics':
                $main_icon_data_icon = 'mdi:book-open-variant';
                $color_class = 'text-info';
                break;
            case 'cultural':
                $main_icon_data_icon = 'mdi:theater';
                $color_class = 'text-warning';
                break;
            case 'community':
                $main_icon_data_icon = 'mdi:handshake-outline';
                $color_class = 'text-success';
                break;
            case 'others':
            default:
                $main_icon_data_icon = 'mdi:dots-horizontal'; // A generic icon for 'others'
                $color_class = 'text-secondary';
                break;
        }
    }

    return ['main_icon' => $main_icon_data_icon, 'gender_icon' => $gender_icon_data_icon, 'color' => $color_class];
}

// Suggested categories (customize these) - Defines the order for display
$event_categories_ordered = ['Ball games', 'Racket games', 'Athletics', 'Other games'];
// Suggested statuses (customize these)
$event_statuses = ['Upcoming', 'Ongoing', 'Completed', 'Cancelled', 'Postponed', 'Draft'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Siglakas Events</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.iconify.design/2/2.2.1/iconify.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">


    <style>
        /* Added from Tournament_Manager_page.php */
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
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; /* Updated font */
        display: flex;
        flex-direction: column;
    }

        /* Navbar Enhancement - Copied from Tournament_Manager_page.php */
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
    
    /* Note: The interactive-brand CSS from the old Event.php is removed */

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
    /* End Navbar Enhancement */
        
        /* The rest of the original Event.php CSS follows */

        /* Overall Event card (Egg White) */
        .overall-card {
            background-color: #e9edf6;
            border: 1px solid #f5dc8f;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 24px;
        }
        .navbar {
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            width: 100%;
        }
         /* The interactive-brand hover style is kept but modified */
         /* This ensures the brand is still interactive, but uses the new design's color logic */
        .interactive-brand:hover .brand-heading,
        .interactive-brand:hover .brand-subheading {
            color: #4CAF50 !important; /* Use a color that fits the new theme, e.g., primary-green */
        }
        
        .card-stat {
            border-left: 5px solid #ccc;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            padding: 20px;
            background: white;
            border-radius: 8px;
        }
        .card-stat.completed {
            border-color: #35d305ff; /* yellow border */
        }
        .card-stat.Total-Events {
            border-color: #A020F0;
        }
        .card-stat.Ongoing {
            border-color: #ff3300e7;
        }
        .status-pill {
            font-size: 0.85rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            color: #fff;
            /* background-color is set dynamically by getEventCardStatusClass */
        }
        .filter-btns .btn {
            border-radius: 20px;
            padding: 6px 16px;
            font-weight: 500;
        }
        .filter-btns .btn.active {
            background-color: #001f3f;
            color: #fff;
        }
        .sport-card {
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            padding: 20px;
            margin-bottom: 30px;
            transition: all 0.3s ease;
            height: 220px; /* Fixed height for uniformity */
        }
        .sport-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        /* Ensure description text wraps properly and truncates */
        .sport-card p.text-muted {
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 3; /* Limit to 3 lines, adjust as needed */
            -webkit-box-orient: vertical;
            white-space: normal;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .competition-badge {
            font-size: 0.8rem;
            background-color: #d4edda;
            color: #155724;
            padding: 4px 10px;
            border-radius: 20px;
        }
        .check-event {
            font-weight: 500;
            color: #001f3f;
            text-decoration: none;
        }
        .check-event:hover {
            text-decoration: underline;
        }
        .divider {
            height: 2px;
            background-color: #f1c40f;
            margin-top: 0;
            margin-bottom: 20px;
            border-radius: 10px;
        }
        footer.bg-dark {
            margin-top: auto; /* Push footer to the bottom */
        }
        /* Styling for view modal details */
        .view-detail-item {
            display: flex;
            margin-bottom: 10px;
            align-items: baseline;
            flex-wrap: wrap;
        }
        .view-detail-label {
            font-weight: bold;
            flex-shrink: 0;
            width: 120px;
            margin-right: 15px;
            white-space: nowrap;
        }
        .view-detail-value {
            flex-grow: 1;
            white-space: normal;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        /* Custom text colors for icons if not covered by Bootstrap defaults */
        .text-orange { color: #fd7e14 !important; } /* Bootstrap's orange */
        .text-blue { color: #0d6efd !important; } /* Bootstrap's primary blue */
        .text-purple { color: #6f42c1 !important; } /* Bootstrap's purple */

        /* Iconify specific styling */
        .iconify {
            vertical-align: -0.125em; /* Adjust vertical alignment for icons */
        }
        .gender-icon {
            font-size: 0.8em; /* Make gender icon slightly smaller */
            margin-right: 5px; /* Space between main icon and gender icon */
        }

        /* Styles for category sections */
        .category-section {
            margin-bottom: 40px;
        }
        .category-section-heading {
            font-size: 1.75rem;
            font-weight: bold;
            margin-bottom: 20px;
            color: #343a40; /* Dark text for headings */
            border-bottom: 2px solid #e9ecef; /* Light border below heading */
            padding-bottom: 10px;
        }
        .no-events-in-category {
            text-align: center;
            color: #6c757d;
            padding: 20px;
            background-color: #e9ecef;
            border-radius: 8px;
            margin-bottom: 30px;
        }

        /* Styles for modal tabs */
        .modal-body .nav-tabs .nav-link {
            color: #495057;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
        }
        .modal-body .nav-tabs .nav-link.active {
            color: #fff;
            background-color: #0d6efd; /* Bootstrap primary blue */
            border-color: #0d6efd;
        }
        .modal-body .tab-content {
            padding-top: 20px;
        }
        .modal-body .medal-icon {
            font-size: 1.2em;
            margin-right: 5px;
        }
        .modal-body .gold-medal { color: gold; }
        .modal-body .silver-medal { color: silver; }
        .modal-body .bronze-medal { color: #cd7f32; }

        /* Match schedule styling within modal */
        /* Updated match-card for hover and layout */
        .modal-body .match-card {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px; /* Increased padding */
            margin-bottom: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: all 0.2s ease-in-out;
            cursor: pointer; /* Make the entire card clickable */
        }
        .modal-body .match-card:hover {
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        /* Color-coded borders based on status */
        .modal-body .match-card.completed {
            background-color: #e9ecef; /* Lighter grey for completed */
            opacity: 0.9; /* Slightly less opaque */
            border-left: 5px solid #6c757d; /* Grey border for completed */
        }
        .modal-body .match-card.ongoing {
            border-left: 5px solid #28a745; /* Green border for ongoing */
        }
        .modal-body .match-card.upcoming {
            border-left: 5px solid #17a2b8; /* Blue border for upcoming */
        }
        .modal-body .match-card.cancelled {
            border-left: 5px solid #dc3545; /* Red border for cancelled */
            text-decoration: line-through;
            opacity: 0.7;
        }
        .modal-body .match-card.postponed {
            border-left: 5px solid #ffc107; /* Yellow border for postponed */
            color: #664d03;
        }

        /* Header for match card: Status, Date, Time */
        .match-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 8px; /* More space below header */
            border-bottom: 1px solid rgba(0,0,0,0.08); /* Subtle separator */
        }
        .match-card-header .status-badge-pill {
            font-size: 0.85rem; /* Slightly larger */
            font-weight: 600;
            padding: 5px 12px; /* More padding for pill */
            border-radius: 50rem; /* Pill shape */
            color: #fff;
        }
        /* Specific colors for badges to ensure override */
        .match-card-header .status-badge-pill.bg-info { background-color: #17a2b8 !important; }
        .match-card-header .status-badge-pill.bg-success { background-color: #28a745 !important; }
        .match-card-header .status-badge-pill.bg-secondary { background-color: #6c757d !important; }
        .match-card-header .status-badge-pill.bg-danger { background-color: #dc3545 !important; }
        .match-card-header .status-badge-pill.bg-warning { background-color: #ffc107 !important; color: #333 !important; }

        .match-card-header .datetime-info {
            font-size: 0.9em; /* Muted, slightly smaller */
            color: #6c757d;
        }
        .match-card-header .datetime-info i {
            margin-right: 5px;
            color: #495057; /* Icon color */
        }

        /* Main match details: Teams and VS */
        .match-card-teams {
            margin-bottom: 10px;
            text-align: center; /* Center the whole block */
        }
        .team-name-large {
            font-weight: bold;
            font-size: 1.3em; /* More emphasis */
            color: #343a40; /* Darker text */
            line-height: 1.2; /* Tighter line height */
        }
        .college-small {
            font-size: 0.8em; /* Smaller college names */
            color: #6c757d; /* Lighter color */
            display: block; /* Ensure it's on its own line */
        }
        .team-vs-separator {
            font-weight: bold;
            font-size: 1.2em;
            margin: 0 10px; /* Space around VS */
            color: #495057;
        }

        /* Venue information */
        .match-card-venue {
            display: flex;
            align-items: center;
            margin-top: 10px;
            margin-bottom: 15px; /* More space below venue */
            font-size: 0.95em;
            color: #6c757d;
            border-top: 1px dashed rgba(0,0,0,0.1); /* Dashed separator */
            padding-top: 10px;
        }
        .match-card-venue i {
            margin-right: 8px;
            color: #495057;
        }

        /* Score / Countdown section */
        .match-score-section {
            text-align: center;
            padding-top: 10px;
            border-top: 1px dashed rgba(0,0,0,0.1); /* Dashed separator */
        }
        .match-score {
            font-size: 1.6em; /* Even larger score */
            font-weight: bold;
            color: #000; /* Black for scores */
            margin-bottom: 5px;
        }
        .winner-text {
            color: #28a745;
            font-weight: bold;
            font-size: 1.1em;
            margin-bottom: 5px;
        }
        .countdown-timer {
            font-weight: bold;
            color: #007bff;
            font-size: 1.1em;
        }
        .score-not-available {
            color: #6c757d; /* Muted for not available */
            font-style: italic;
        }

        /* Adjust vertical whitespace between section title and match cards */
        #matchScheduleContent > h5, #matchScheduleContent > h6 {
            margin-bottom: 10px !important; /* Reduce space after date/sport headers */
            margin-top: 25px !important; /* Slightly more space before new date/sport headers */
        }
        #matchScheduleContent > h5:first-child, #matchScheduleContent > h6:first-child {
             margin-top: 0 !important; /* No top margin for the very first header */
        }

        /* Responsive adjustments for team names */
        @media (max-width: 767.98px) {
            .match-card-teams .d-flex {
                flex-direction: column !important; /* Stack teams vertically on small screens */
            }
            .match-card-teams .flex-grow-1 {
                width: 100%; /* Take full width when stacked */
            }
            .team-vs-separator {
                margin: 10px 0 !important; /* Adjust margin for vertical stack */
            }
        }
        .event-title {
    font-family: 'Roboto', sans-serif;
    font-weight: 700; /* bold */
    margin: 0;
}
/* Enhanced Modal Styles */
#viewEventModal .modal-content {
    border: none;
    box-shadow: 0 10px 40px rgba(0,0,0,0.15);
}

#viewEventModal .nav-tabs {
    border-bottom: 2px solid #dee2e6;
}

#viewEventModal .nav-tabs .nav-link {
    color: #6c757d;
    border: none;
    border-bottom: 3px solid transparent;
    font-weight: 500;
    padding: 12px 20px;
    transition: all 0.2s;
}

#viewEventModal .nav-tabs .nav-link:hover {
    color: #0d6efd;
    background-color: #f8f9fa;
    border-bottom-color: #0d6efd;
}

#viewEventModal .nav-tabs .nav-link.active {
    color: #0d6efd;
    background-color: white;
    border-bottom-color: #0d6efd;
}

/* Detail rows styling */
.detail-row {
    padding-bottom: 12px;
    border-bottom: 1px solid #f0f0f0;
}

.detail-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

/* Table enhancements */
#viewEventModal .table thead th {
    font-weight: 600;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

#viewEventModal .sortable-header {
    cursor: pointer;
    user-select: none;
}

#viewEventModal .sortable-header:hover {
    background-color: rgba(0,0,0,0.05);
}

/* Responsive adjustments */
@media (max-width: 768px) {
    #viewEventModal .nav-tabs .nav-link {
        padding: 10px 12px;
        font-size: 0.9rem;
    }
    
    #viewEventModal .modal-body .row.g-2 {
        gap: 0.5rem !important;
    }
}
/* Hero Section */
.hero-section {
    background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(233,236,239,0.9) 100%);
    border-radius: 16px;
    padding: 32px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 20px 15px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.12);
}

/* Filter Section */
.filter-section .card {
    background: white;
    border-radius: 12px;
}

.filter-btns {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.filter-btns .btn {
    flex: 1;
    min-width: 100px;
    border-radius: 8px;
    padding: 8px 16px;
    font-weight: 500;
    font-size: 0.9rem;
    transition: all 0.2s;
}

.filter-btns .btn:hover:not(.active) {
    background-color: #f8f9fa;
    transform: translateY(-2px);
}

.filter-btns .btn.active {
    background-color: #001f3f;
    color: #fff;
    box-shadow: 0 4px 8px rgba(0,31,63,0.3);
}

/* Events Grid - More spacious */
.sport-card {
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    padding: 24px;
    margin-bottom: 24px;
    transition: all 0.3s ease;
    height: 240px;
    border-left: 4px solid transparent;
}

.sport-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    border-left-color: #f1c40f;
}

/* Category Section Headers */
.category-section-heading {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 24px;
    color: #2c3e50;
    padding-left: 16px;
    border-left: 5px solid #f1c40f;
    display: flex;
    align-items: center;
}

.category-section-heading::before {
    content: '';
    display: inline-block;
    width: 8px;
    height: 8px;
    background: #f1c40f;
    border-radius: 50%;
    margin-right: 12px;
    margin-left: -24px;
}

/* Event Title Styling */
.event-title {
    font-family: 'Roboto', sans-serif;
    font-weight: 700;
    font-size: 1.1rem;
    margin: 0;
    color: #2c3e50;
}

/* Status Pills - More refined */
.status-pill {
    font-size: 0.75rem;
    font-weight: 600;
    padding: 5px 12px;
    border-radius: 20px;
    color: #fff;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}

/* Check Event Link */
.check-event {
    font-weight: 600;
    color: #001f3f;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    transition: all 0.2s;
}

.check-event:hover {
    color: #0066ff;
    gap: 8px;
}

/* Remove old stat cards styling */
.card-stat {
    /* Remove or comment out old styling */
}

/* Responsive adjustments */
@media (max-width: 991.98px) {
    .hero-section {
        padding: 24px;
    }
    
    .filter-btns .btn {
        min-width: 80px;
        font-size: 0.85rem;
        padding: 6px 12px;
    }
    
    .sport-card {
        height: auto;
        min-height: 220px;
    }
}

@media (max-width: 767.98px) {
    .stat-card {
        margin-bottom: 12px;
    }
    
    .category-section-heading {
        font-size: 1.25rem;
    }
}

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

    <div class="container mt-5" style="padding-top: 80px;">
     <div class="overall-card">

        <h1 class="fw-bold mb-4">
    <img src="images/SiglakaseventIcon.png" alt="Events Icon" class="me-2" style="width: 3rem; height: 3rem; object-fit: contain;">
    Siglakas Events
</h1>

        <div class="hero-section mb-5">
    <div class="row align-items-center">
        <div class="col-lg-6 mb-4 mb-lg-0">
            <h1 class="display-4 fw-bold mb-3">
                <img src="images/SiglakaseventIcon.png" alt="Events Icon" class="me-2" style="width: 3.5rem; height: 3.5rem; object-fit: contain;">
                Siglakas Events
            </h1>
            <p class="lead text-muted">Discover and track all tournament events, matches, and standings in one place.</p>
        </div>
        <div class="col-lg-6">
            <div class="row g-3">
                <div class="col-4">
                    <div class="stat-card text-center">
                        <img src="images/EventsIcon.png" alt="Event Icon" style="width: 2.5em; height: 2.5em;">
                        <div class="fw-bold fs-3 mt-2"><?= $total_events ?></div>
                        <div class="text-muted small">Total Events</div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="stat-card text-center">
                        <img src="images/CompleteIcon.png" alt="Complete Icon" style="width: 2.5em; height: 2.5em;">
                        <div class="fw-bold fs-3 mt-2"><?= $completed_events ?></div>
                        <div class="text-muted small">Completed</div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="stat-card text-center">
                        <img src="images/OngoingIcon.png" alt="Ongoing Icon" style="width: 2.5em; height: 2.5em;">
                        <div class="fw-bold fs-3 mt-2"><?= $ongoing_events ?></div>
                        <div class="text-muted small">Ongoing</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
       <div class="filter-section mb-4">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="row align-items-center g-3">
                <div class="col-lg-8">
                    <label class="form-label fw-semibold mb-2">
                        <i class="fas fa-filter me-2"></i>Filter Events
                    </label>
                    <div class="btn-group filter-btns w-100" role="group">
                        <button type="button" class="btn btn-outline-dark active" data-filter="all">All</button>
                        <button type="button" class="btn btn-outline-dark" data-filter="completed">Completed</button>
                        <button type="button" class="btn btn-outline-dark" data-filter="ongoing">Ongoing</button>
                        <button type="button" class="btn btn-outline-dark" data-filter="upcoming">Upcoming</button>
                        <button type="button" class="btn btn-outline-dark" data-filter="cancelled">Cancelled</button>
                        <button type="button" class="btn btn-outline-dark" data-filter="postponed">Postponed</button>
                    </div>
                </div>
                <div class="col-lg-4">
                    <label class="form-label fw-semibold mb-2">
                        <i class="fas fa-layer-group me-2"></i>Category
                    </label>
                    <select id="categoryFilter" class="form-select">
                        <option value="all">All Categories</option>
                        <?php foreach ($event_categories_ordered as $cat): ?>
                            <option value="<?= htmlspecialchars(strtolower($cat)) ?>"><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
            </div>
        </div>


        <div class="events-header mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <h3 class="fw-bold mb-0">
            <img src="images/EventlistIcon.png" alt="Events Icon" class="me-2" style="width: 2.5rem; height: 2.5rem; object-fit: contain;">
            Events List
        </h3>
        <div class="text-muted small">
            <i class="fas fa-sync-alt me-1"></i>Auto-refreshing
        </div>
    </div>
</div>
<div class="divider mb-4"></div>


        

        <div id="eventsDynamicRoot"></div>
    </div>

    <div class="modal fade" id="viewEventModal" tabindex="-1" aria-labelledby="viewEventModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <div class="d-flex align-items-center">
                    <span class="iconify me-2 text-white fs-4" data-icon="mdi:trophy-variant"></span>
                    <div>
                        <h5 class="modal-title mb-0" id="modalEventName"></h5>
                        <small class="opacity-75">Complete Event Information</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-0">
                <div class="border-bottom bg-light p-3">
                    <div class="row g-2">
                        <div class="col-lg-8">
                            <div class="d-flex flex-wrap gap-2">
                                <div class="input-group" style="max-width:280px;">
                                    <span class="input-group-text bg-white">
                                        <i class="fas fa-search text-muted"></i>
                                    </span>
                                    <input type="text" class="form-control" id="filterTeamSearch" 
                                           placeholder="Search teams, events...">
                                </div>
                                <select class="form-select" id="filterSport" style="max-width:200px;">
                                    <option value="all">All Sports</option>
                                </select>
                                <input type="date" class="form-control" id="filterDate" 
                                       style="max-width:180px;" title="Filter by date">
                            </div>
                        </div>
                        
                        <div class="col-lg-4">
                            <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                                <button class="btn btn-sm btn-outline-secondary" id="toggleLeaderboard" 
                                        title="View medal leaderboard">
                                    <i class="fas fa-trophy me-1"></i>Leaderboard
                                </button>
                                <button class="btn btn-sm btn-outline-primary" id="printView" 
                                        title="Print this page">
                                    <i class="fas fa-print me-1"></i>Print
                                </button>
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-outline-dark dropdown-toggle" 
                                            data-bs-toggle="dropdown" title="Export data">
                                        <i class="fas fa-download me-1"></i>Export
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" href="#" id="exportStandingsCsv">
                                            <i class="fas fa-medal me-2"></i>Standings CSV
                                        </a></li>
                                        <li><a class="dropdown-item" href="#" id="exportScheduleCsv">
                                            <i class="fas fa-calendar me-2"></i>Schedule CSV
                                        </a></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <ul class="nav nav-tabs nav-fill border-bottom-0 bg-white px-3 pt-3" 
                    id="eventDetailsTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active rounded-top" id="details-tab" 
                                data-bs-toggle="tab" data-bs-target="#tab-details" 
                                type="button" role="tab">
                            <i class="fas fa-info-circle me-2"></i>
                            <span class="d-none d-sm-inline">Event </span>Details
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link rounded-top" id="medals-tab" 
                                data-bs-toggle="tab" data-bs-target="#tab-medals" 
                                type="button" role="tab">
                            <i class="fas fa-medal me-2"></i>
                            <span class="d-none d-sm-inline">Medal </span>Standings
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link rounded-top" id="schedule-tab" 
                                data-bs-toggle="tab" data-bs-target="#tab-schedule" 
                                type="button" role="tab">
                            <i class="fas fa-calendar-alt me-2"></i>
                            <span class="d-none d-sm-inline">Match </span>Schedule
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link rounded-top" id="results-tab" 
                                data-bs-toggle="tab" data-bs-target="#tab-results" 
                                type="button" role="tab">
                            <i class="fas fa-trophy me-2"></i>
                            <span class="d-none d-sm-inline">Match </span>Results
                        </button>
                    </li>
                </ul>

                <div class="tab-content p-4" id="eventDetailsTabContent">
                    <div class="tab-pane fade show active" id="tab-details" role="tabpanel">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="card border-0 shadow-sm h-100">
                                    <div class="card-header bg-primary bg-opacity-10 border-0">
                                        <h6 class="mb-0 text-primary">
                                            <i class="fas fa-info-circle me-2"></i>Basic Information
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="detail-row mb-3">
                                            <label class="text-muted small mb-1">Event ID</label>
                                            <div class="fw-semibold" id="viewEventId"></div>
                                        </div>
                                        <div class="detail-row mb-3">
                                            <label class="text-muted small mb-1">Event Name</label>
                                            <div class="fw-semibold fs-5" id="viewEventName"></div>
                                        </div>
                                        <div class="detail-row mb-3">
                                            <label class="text-muted small mb-1">Category</label>
                                            <div id="viewCategory"></div>
                                        </div>
                                        <div class="detail-row">
                                            <label class="text-muted small mb-1">Status</label>
                                            <div>
                                                <span class="badge fs-6" id="viewEventStatus"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="card border-0 shadow-sm h-100">
                                    <div class="card-header bg-success bg-opacity-10 border-0">
                                        <h6 class="mb-0 text-success">
                                            <i class="fas fa-calendar-check me-2"></i>Schedule
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="detail-row mb-3">
                                            <label class="text-muted small mb-1">
                                                <i class="fas fa-play-circle me-1"></i>Start Date
                                            </label>
                                            <div class="fw-semibold" id="viewStartDate"></div>
                                        </div>
                                        <div class="detail-row">
                                            <label class="text-muted small mb-1">
                                                <i class="fas fa-stop-circle me-1"></i>End Date
                                            </label>
                                            <div class="fw-semibold" id="viewEndDate"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-header bg-info bg-opacity-10 border-0">
                                        <h6 class="mb-0 text-info">
                                            <i class="fas fa-align-left me-2"></i>Description
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <p class="mb-0" id="viewDescription"></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tab-medals" role="tabpanel">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-warning bg-opacity-10 border-0">
            <h5 class="mb-0">
                <i class="fas fa-trophy text-warning me-2"></i>
                Live Medal Standings
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="modalMedalStandingsTable">
                    <thead class="table-light">
                        <tr>
                            <th class="sortable-header text-center" style="width: 80px;" data-sort="rank">
                                Rank
                            </th>
                            <th class="sortable-header" data-sort="team_name">
                                College / Team
                            </th>
                            <th class="text-center sortable-header" style="width: 100px;" data-sort="gold">
                                <i class="fas fa-medal gold-medal me-1"></i>Gold 
                            </th>
                            <th class="text-center sortable-header" style="width: 100px;" data-sort="silver">
                                <i class="fas fa-medal silver-medal me-1"></i>Silver 
                            </th>
                            <th class="text-center sortable-header" style="width: 100px;" data-sort="bronze">
                                <i class="fas fa-medal bronze-medal me-1"></i>Bronze 
                            </th>
                            <th class="text-center sortable-header fw-bold" style="width: 100px;" data-sort="total">
                                Total
                            </th>
                        </tr>
                    </thead>
                    <tbody id="modalMedalStandingsBody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
                    <div class="tab-pane fade" id="tab-schedule" role="tabpanel">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-info bg-opacity-10 border-0">
                                <h5 class="mb-0">
                                    <i class="fas fa-calendar-alt text-info me-2"></i>
                                    Upcoming & Ongoing Matches
                                </h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0" id="scheduleTable">
                                        <thead class="table-dark">
                                            <tr>
                                                <th style="width: 50px;">#</th>
                                                <th style="width: 140px;">Date</th>
                                                <th style="width: 100px;">Time</th>
                                                <th>Teams</th>
                                                <th style="width: 180px;">Venue</th>
                                                <th style="width: 100px;" class="text-center">Status</th>
                                                <th style="width: 150px;">Countdown</th>
                                            </tr>
                                        </thead>
                                        <tbody id="scheduleTableBody"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tab-results" role="tabpanel">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-success bg-opacity-10 border-0">
                                <h5 class="mb-0">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    Completed Match Results
                                </h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0" id="resultsTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 150px;">Sport</th>
                                                <th>Match</th>
                                                <th class="text-center" style="width: 120px;">Score</th>
                                                <th style="width: 180px;">Winner</th>
                                                <th style="width: 180px;">Time Finished</th>
                                            </tr>
                                        </thead>
                                        <tbody id="matchResultsBody"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.iconify.design/2/2.2.1/iconify.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // Adjust main content padding dynamically based on navbar height
            const navbarHeight = document.querySelector('.navbar').offsetHeight;
            document.querySelector('.container.mt-5').style.paddingTop = `${navbarHeight + 30}px`; // Adjust for page title
            

            // Handles redirect for the brand logo click
            document.querySelector('.interactive-brand').addEventListener('click', function(e) {
                e.preventDefault();
                window.location.href = 'Tournament_Manager_page.php'; 
            });

            // --- Client-side Filtering Logic (UPDATED for grouped sections and combined filters) ---
            const filterButtons = document.querySelectorAll('.filter-btns .btn');
            const categoryFilter = document.getElementById('categoryFilter');
            const eventsRoot = document.getElementById('eventsDynamicRoot');

            let currentStatusFilter = 'all'; // Keep track of the active status filter from buttons

            function buildEventHtml(event) {
                const statusClass = getEventCardStatusClassJS(event.event_status || '');
                const title = String(event.event_name || 'Untitled');
                const sportName = String(event.sport_name || 'Unknown Sport');
                const category = String(event.category || 'Other games');
                const desc = String(event.description || 'No description provided.');
                const start = event.start_date === 'TBA' ? 'TBA' : formatDate(event.start_date);
                const end = (event.end_date && event.end_date !== 'TBA' && event.end_date !== event.start_date) ? ` - ${formatDate(event.end_date)}` : '';
                return `
                    <div class="col-md-4 event-card-item" data-category="${(event.category||'others').toLowerCase()}" data-status="${(event.event_status||'').toLowerCase()}">
                        <div class="sport-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h5 class="mb-0 event-title">${escapeHtml(title)}</h5>
                                <span class="status-pill ${statusClass}">${escapeHtml(event.event_status||'')}</span>
                            </div>
                            <p class="text-muted mb-2"><strong>${escapeHtml(sportName)}</strong> - ${escapeHtml(category)}</p>
                            <p class="text-muted mb-3">${escapeHtml(desc)}</p>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">Dates: ${start}${end}</span>
                                <a href="#" class="check-event" data-bs-toggle="modal" data-bs-target="#viewEventModal" data-id="${event.event_id}">Check Event <span class="iconify ms-1" data-icon="mdi:arrow-right"></span></a>
                            </div>
                        </div>
                    </div>`;
            }

            function renderEvents(events) {
                // Group by category per configured order
                const byCat = {};
                (events||[]).forEach(ev => {
                    const cat = ev.category || 'Others';
                    if (!byCat[cat]) byCat[cat] = [];
                    byCat[cat].push(ev);
                });

                let html = '';
                const ordered = <?= json_encode($event_categories_ordered) ?>;
                ordered.forEach(catName => {
                    const list = byCat[catName] || [];
                    const sectionId = 'category-' + catName.toLowerCase().replace(/\s+/g,'-');
                    html += `<div class="category-section" id="${sectionId}">`+
                            `<h5 class="category-section-heading">${escapeHtml(catName)} Events</h5>`+
                            `<div class="row category-events-grid">`+
                            (list.length ? list.map(buildEventHtml).join('') : `<div class="col-12 no-events-in-category">No events currently listed in the ${escapeHtml(catName)} category.</div>`) +
                            `</div></div>`;
                });
                if (!events || events.length === 0) {
                    html = '<div class="col-12"><p class="text-center text-muted py-5">No events found. Please add events via the admin panel.</p></div>';
                }
                eventsRoot.innerHTML = html;
            }

            function applyAllFilters() {
                const selectedCategory = categoryFilter.value;
                const cards = eventsRoot.querySelectorAll('.event-card-item');
                const sections = eventsRoot.querySelectorAll('.category-section');
                let totalVisible = 0;
                cards.forEach(card => {
                    const ccat = card.dataset.category;
                    const cstatus = card.dataset.status;
                    const show = (selectedCategory === 'all' || ccat === selectedCategory) && (currentStatusFilter === 'all' || cstatus === currentStatusFilter);
                    card.style.display = show ? 'block' : 'none';
                    if (show) totalVisible++;
                });
                sections.forEach(sec => {
                    const anyVisible = !!sec.querySelector('.event-card-item[style*="display: block"]');
                    sec.style.display = anyVisible ? 'block' : 'none';
                });
            }

            // Add event listeners to the existing filter buttons
            filterButtons.forEach(button => {
                button.addEventListener('click', function() {
                    // Remove active class from all buttons
                    filterButtons.forEach(btn => btn.classList.remove('active'));
                    // Add active class to the clicked button
                    this.classList.add('active');
                    currentStatusFilter = this.dataset.filter; // Update the global status filter
                    applyAllFilters(); // Apply all filters
                });
            });

            // Add event listener to the new category dropdown
            categoryFilter.addEventListener('change', applyAllFilters);

            // Fetch and render events periodically
            function fetchAndRenderEvents() {
                fetch('fetch_events_data.php?_=' + Date.now())
                    .then(r => r.json())
                    .then(res => {
                        if (!res || !res.success) throw new Error(res && res.error ? res.error : 'Failed to load events');
                        renderEvents(res.data || []);
            applyAllFilters();
                    })
                    .catch(err => {
                        console.error('Events fetch error:', err);
                        eventsRoot.innerHTML = '<div class="alert alert-danger text-center">Failed to load events. Retrying...</div>';
                    });
            }
            fetchAndRenderEvents();
            setInterval(fetchAndRenderEvents, 5000);

            // --- View Event Details Modal Logic (ENHANCED) ---
            const viewEventModalElement = document.getElementById('viewEventModal');
            const viewEventModal = new bootstrap.Modal(viewEventModalElement); // This line initializes the Bootstrap modal JS

            // Elements for Event Details Tab
            const modalEventNameSpan = document.getElementById('modalEventName');
            const viewEventIdSpan = document.getElementById('viewEventId');
            const viewEventNameSpan = document.getElementById('viewEventName');
            const viewCategorySpan = document.getElementById('viewCategory');
            const viewEventStatusSpan = document.getElementById('viewEventStatus');
            const viewStartDateSpan = document.getElementById('viewStartDate');
            const viewEndDateSpan = document.getElementById('viewEndDate');
            const viewDescriptionSpan = document.getElementById('viewDescription');

            // Elements for Medal Standings Tab
            const modalMedalStandingsBody = document.getElementById('modalMedalStandingsBody');
            let currentMedalStandingsData = []; // To hold data for sorting
            let autoRefreshInterval = null;
            let currentEventIdForRefresh = null;

            // Elements for Match Schedule Tab
            const matchScheduleContent = document.getElementById('matchScheduleContent');
            
            // Elements for Match Results Tab
            const matchResultsBody = document.getElementById('matchResultsBody');

            // --- JAVASCRIPT VERSION OF getEventCardStatusClass ---
            // This function is needed because the PHP function cannot be called from JS.
            function getEventCardStatusClassJS(status) {
                switch (status.toLowerCase()) {
                    case 'upcoming':
                        return 'bg-info';
                    case 'ongoing':
                        return 'bg-success';
                    case 'completed':
                        return 'bg-secondary';
                    case 'cancelled':
                        return 'bg-danger';
                    case 'postponed':
                        return 'bg-warning text-dark';
                    case 'draft':
                        return 'bg-light text-dark';
                    default:
                        return 'bg-primary';
                }
            }


            // Function to populate Medal Standings Table
            function populateMedalStandings(standingsData) {
                let html = '';
                if (standingsData.length === 0) {
                    html = `<tr><td colspan="6" class="text-center text-muted py-4">No medal standings available for this event.</td></tr>`;
                } else {
                    // Sort by Gold > Silver > Bronze > Total
                    standingsData.sort((a, b) => {
                        const ag = parseInt(a.gold) || 0, bg = parseInt(b.gold) || 0;
                        if (bg !== ag) return bg - ag;
                        const as = parseInt(a.silver) || 0, bs = parseInt(b.silver) || 0;
                        if (bs !== as) return bs - as;
                        const ab = parseInt(a.bronze) || 0, bb = parseInt(b.bronze) || 0;
                        if (bb !== ab) return bb - ab;
                        const at = parseInt(a.total) || 0, bt = parseInt(b.total) || 0;
                        return bt - at;
                    });

                    // Re-calculate rank after sorting
                    standingsData.forEach((standing, index) => {
                        html += `
                            <tr>
                                <td class="text-center fw-bold">${index + 1}</td>
                                <td>${escapeHtml(standing.team_name)} (${escapeHtml(standing.college || 'N/A')})</td>
                                <td class="text-center">${escapeHtml(String(standing.gold))}</td>
                                <td class="text-center">${escapeHtml(String(standing.silver))}</td>
                                <td class="text-center">${escapeHtml(String(standing.bronze))}</td>
                                <td class="text-center fw-bold">${escapeHtml(String(standing.total))}</td>
                            </tr>
                        `;
                    });
                }
                modalMedalStandingsBody.innerHTML = html;
            }


            // Function to populate Match Schedule
            function renderScheduleTable(matches) {
                const tbody = document.getElementById('scheduleTableBody');
                if (!matches || matches.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-4">No upcoming or ongoing matches.</td></tr>`;
                    return;
                }
                // Sort by date/time asc
                matches.sort((a,b)=>{
                    const ad = new Date(a.match_date + 'T' + a.match_time).getTime();
                    const bd = new Date(b.match_date + 'T' + b.match_time).getTime();
                    return ad - bd;
                });
                let rows = '';
                matches.forEach((m, idx) => {
                    const dt = new Date(m.match_date + 'T' + m.match_time);
                    const statusClass = m.status === 'Ongoing' ? 'bg-success' : (m.status === 'Upcoming' ? 'bg-info' : 'bg-secondary');
                    const countdownId = `cd-${m.match_id}`;
                    rows += `
                        <tr>
                            <td>${idx + 1}</td>
                            <td><i class="fas fa-calendar-alt me-1"></i>${formatDate(m.match_date)}</td>
                            <td><i class="fas fa-clock me-1"></i>${formatTime(m.match_time)}</td>
                            <td>
                                <div class="fw-bold">${escapeHtml(m.team1_name)} <span class="text-muted">vs</span> ${escapeHtml(m.team2_name)}</div>
                                <div class="small text-muted">${escapeHtml(m.team1_college || 'N/A')} · ${escapeHtml(m.team2_college || 'N/A')}</div>
                            </td>
                            <td><i class="fas fa-map-marker-alt me-1"></i>${escapeHtml(m.venue)}</td>
                            <td><span class="badge ${statusClass}">${escapeHtml(m.status)}</span></td>
                            <td>${m.status === 'Upcoming' ? `<strong class="countdown-timer" id="${countdownId}" data-match-datetime="${dt.toISOString()}"></strong>` : (m.status === 'Ongoing' ? '<span class="text-success fw-bold">Live</span>' : '<span class="text-muted">—</span>')}</td>
                        </tr>`;
                });
                tbody.innerHTML = rows;
                updateCountdown();
            }

            // Function to populate Match Results
            function populateMatchResults(rawMatchScheduleData) {
                let html = '';
                const completedMatches = rawMatchScheduleData.filter(match => match.status === 'Completed');

                if (completedMatches.length === 0) {
                    html = `<tr><td colspan="5" class="text-center text-muted py-4">No completed match results for this event yet.</td></tr>`;
                } else {
                    // Sort by finished time desc if available
                    completedMatches.sort((a,b)=>{
                        const at = new Date(a.time_finished || a.match_date + 'T' + a.match_time).getTime();
                        const bt = new Date(b.time_finished || b.match_date + 'T' + b.match_time).getTime();
                        return bt - at;
                    });
                    completedMatches.forEach(match => {
                        html += `
                            <tr>
                                <td>${escapeHtml(match.sport_category)}</td>
                                <td>${escapeHtml(match.team1_name)} vs ${escapeHtml(match.team2_name)}</td>
                                <td class="text-center fw-bold">${escapeHtml(String(match.score1 || 'N/A'))} - ${escapeHtml(String(match.score2 || 'N/A'))}</td>
                                <td>${escapeHtml(match.winner_name || 'N/A')}</td>
                                <td>${formatDateTime(match.time_finished)}</td>
                            </tr>
                        `;
                    });
                }
                matchResultsBody.innerHTML = html;
            }

            // Helper functions for formatting and escaping
            function formatDate(dateString) {
                const options = { year: 'numeric', month: 'long', day: 'numeric' };
                // Ensure dateString is valid before creating Date object
                if (!dateString || dateString === '0000-00-00') return 'TBA'; // Handle 'TBA' or empty date
                const date = new Date(dateString + 'T00:00:00');
                if (isNaN(date.getTime())) return 'Invalid Date'; // Check for invalid date
                return date.toLocaleDateString('en-US', options);
            }

            function formatTime(timeString) {
                if (!timeString || timeString === '00:00:00') return 'TBA'; // Handle 'TBA' or empty time
                const [hours, minutes, seconds] = timeString.split(':');
                const date = new Date();
                date.setHours(hours, minutes, seconds);
                if (isNaN(date.getTime())) return 'Invalid Time'; // Check for invalid time
                return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
            }

            function formatDateTime(dateTimeString) {
                if (!dateTimeString || dateTimeString === '0000-00-00 00:00:00') return 'N/A';
                const date = new Date(dateTimeString);
                if (isNaN(date.getTime())) return 'Invalid DateTime'; // Check for invalid date/time
                const options = { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true };
                return date.toLocaleDateString('en-US', options);
            }

            function escapeHtml(text) {
                // Ensure text is converted to string before calling replace
                const strText = String(text); 
                const map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return strText.replace(/[&<>"']/g, function(m) { return map[m]; });
            }

            // --- Countdown Timers for Upcoming Matches ---
            let countdownInterval = null; // Store interval ID to clear it

            function updateCountdown() {
                document.querySelectorAll('.countdown-timer').forEach(timerElement => {
                    const matchDatetimeStr = timerElement.dataset.matchDatetime;
                    const matchDatetime = new Date(matchDatetimeStr);
                    const now = new Date();

                    const diff = matchDatetime.getTime() - now.getTime(); // Difference in milliseconds

                    if (diff <= 0) {
                        timerElement.innerText = "Match has started!";
                        // Optionally, trigger a refresh or change status visually
                        // For a full system, you'd update status via AJAX here
                    } else {
                        const days = Math.floor(diff / (1000 * 60 * 60 * 24));
                        const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                        const seconds = Math.floor((diff % (1000 * 60)) / 1000);

                        let countdownText = "";
                        if (days > 0) countdownText += `${days}d `;
                        if (hours > 0 || days > 0) countdownText += `${hours}h `;
                        if (minutes > 0 || hours > 0 || days > 0) countdownText += `${minutes}m `;
                        countdownText += `${seconds}s`;

                        timerElement.innerText = `Starts in: ${countdownText}`;
                    }
                });
            }

            // Clear previous interval if modal is opened multiple times
            viewEventModalElement.addEventListener('hidden.bs.modal', function () {
                if (countdownInterval) {
                    clearInterval(countdownInterval);
                    countdownInterval = null;
                }
                // Ensure aria-hidden is true when modal is hidden
                viewEventModalElement.setAttribute('aria-hidden', 'true');
                if (autoRefreshInterval) {
                    clearInterval(autoRefreshInterval);
                    autoRefreshInterval = null;
                    currentEventIdForRefresh = null;
                }
                // Force cleanup of any lingering backdrops and modal-open class
                document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('padding-right');
            });

            // Start countdown when modal is shown
            viewEventModalElement.addEventListener('shown.bs.modal', function () {
                if (!countdownInterval) { // Only start if not already running
                    countdownInterval = setInterval(updateCountdown, 1000);
                    updateCountdown(); // Initial call
                }
                // Ensure aria-hidden is false when modal is shown
                viewEventModalElement.setAttribute('aria-hidden', 'false');
            });


            // --- Fetch Data and Populate Modal ---
            document.body.addEventListener('click', function(e) {
                if (e.target.closest('.check-event')) {
                    e.preventDefault(); // Prevent default link behavior
                    const button = e.target.closest('.check-event');
                    const eventId = button.dataset.id;
                    
                    // Reset tab to default (Details) when opening modal
                    const defaultTab = document.getElementById('details-tab');
                    const defaultTabPane = document.getElementById('tab-details');
                    const otherTabs = document.querySelectorAll('#eventDetailsTab .nav-link:not(#details-tab)');
                    const otherTabPanes = document.querySelectorAll('#eventDetailsTabContent .tab-pane:not(#tab-details)');

                    defaultTab.classList.add('active');
                    defaultTabPane.classList.add('show', 'active');
                    otherTabs.forEach(tab => tab.classList.remove('active'));
                    otherTabPanes.forEach(pane => pane.classList.remove('show', 'active'));

                    // Show loading indicators (optional, but good for UX)
                    modalMedalStandingsBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Loading medal standings...</td></tr>';
                    document.getElementById('scheduleTableBody').innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Loading match schedule...</td></tr>';
                    matchResultsBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Loading match results...</td></tr>';

                    const loadAndRender = () => fetch(`fetch_event_details_data.php?id=${eventId}`)
                        .then(response => {
                            if (!response.ok) {
                                return response.json().then(errorData => {
                                    throw new Error(errorData.error || `HTTP error! status: ${response.status}`);
                                });
                            }
                            return response.json();
                        })
                        .then(data => {
                            if (data.success) {
                                const event = data.data.event_details;
                                const medalStandings = data.data.medal_standings;
                                const matchSchedule = data.data.match_schedule;
                                const rawMatchSchedule = data.data.raw_match_schedule; // Use raw for results filtering

                                // Populate Event Details Tab
                                modalEventNameSpan.textContent = event.event_name; // For modal header
                                viewEventIdSpan.textContent = event.event_id;
                                viewEventNameSpan.textContent = event.event_name;
                                viewCategorySpan.textContent = event.category || 'N/A';
                                viewEventStatusSpan.textContent = event.event_status;
                                viewStartDateSpan.textContent = event.start_date === 'TBA' ? 'TBA' : formatDate(event.start_date);
                                viewEndDateSpan.textContent = event.end_date === 'TBA' ? 'TBA' : formatDate(event.end_date);
                                viewDescriptionSpan.textContent = event.description || 'No description provided.';

                                // Apply status badge class
                                viewEventStatusSpan.className = `badge fs-6 ${getEventCardStatusClassJS(event.event_status || '')}`;

                                // Populate Medal Standings Tab
                                populateMedalStandings(medalStandings);
                                currentMedalStandingsData = medalStandings; // Store for export/sorting

                                // Build sport filter options
                                const sportSet = new Set();
                                rawMatchSchedule.forEach(m => { if (m.sport_category) sportSet.add(m.sport_category); });
                                const sportSelect = document.getElementById('filterSport');
                                sportSelect.innerHTML = `<option value="all">All Sports</option>` + Array.from(sportSet).sort().map(s=>`<option value="${escapeHtml(s)}">${escapeHtml(s)}</option>`).join('');

                                // Apply filters to schedule and results
                                const teamQuery = (document.getElementById('filterTeamSearch').value || '').toLowerCase();
                                const sportFilter = document.getElementById('filterSport').value;
                                const dateFilter = document.getElementById('filterDate').value; // yyyy-mm-dd
                                const matchesFiltered = rawMatchSchedule.filter(m => {
                                    const matchesStatus = (m.status === 'Upcoming' || m.status === 'Ongoing');
                                    const matchesSport = (sportFilter === 'all' || (m.sport_category || '').toLowerCase() === sportFilter.toLowerCase());
                                    const matchesDate = (!dateFilter || m.match_date === dateFilter);
                                    const nameBlob = `${m.team1_name} ${m.team2_name} ${m.team1_college || ''} ${m.team2_college || ''} ${m.sport_category || ''}`.toLowerCase();
                                    const matchesTeam = (!teamQuery || nameBlob.includes(teamQuery));
                                    return matchesStatus && matchesSport && matchesDate && matchesTeam;
                                });
                                renderScheduleTable(matchesFiltered);

                                // Populate Match Results Tab
                                populateMatchResults(rawMatchSchedule);

                                viewEventModal.show(); // Show the modal after data is loaded
                            } else {
                                alert('Error fetching event details: ' + data.error);
                            }
                        })
                        .catch(error => {
                            console.error('Fetch Event Details Error:', error);
                            alert('Network error while fetching event details. Please check console for details.');
                        });

                    // Initial load
                    loadAndRender();
                    // Auto refresh every 15s while open
                    currentEventIdForRefresh = eventId;
                    if (autoRefreshInterval) clearInterval(autoRefreshInterval);
                    autoRefreshInterval = setInterval(() => {
                        if (document.getElementById('viewEventModal').getAttribute('aria-hidden') === 'false') {
                            loadAndRender();
                        }
                    }, 15000);
                }
            });

            // Filter handlers
            const debounced = (fn, delay=300) => { let t; return (...args)=>{ clearTimeout(t); t=setTimeout(()=>fn(...args), delay); }; };
            const applyFilters = debounced(()=>{
                if (!currentEventIdForRefresh) return;
                // Trigger a refresh cycle which will read filters
                // The issue here is that dispatching a 'click' event on the button re-initializes the full modal logic,
                // including clearing out the modal. A better way for filtering *inside* the modal is to refetch 
                // the full details and re-render only the schedule/results part, or have an AJAX endpoint for just matches.
                // For simplicity, let's keep the existing implementation which triggers a full load:
                
                // 1. Manually hide the modal to prevent flicker/re-init issues if it's visible, then manually re-open
                //    This is tricky. Let's simplify: just call the data fetch/render part directly
                
                const eventId = currentEventIdForRefresh;
                
                const reRenderOnly = () => fetch(`fetch_event_details_data.php?id=${eventId}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                const rawMatchSchedule = data.data.raw_match_schedule;
                                // Apply filters to schedule and results
                                const teamQuery = (document.getElementById('filterTeamSearch').value || '').toLowerCase();
                                const sportFilter = document.getElementById('filterSport').value;
                                const dateFilter = document.getElementById('filterDate').value; // yyyy-mm-dd
                                const matchesFiltered = rawMatchSchedule.filter(m => {
                                    const matchesStatus = (m.status === 'Upcoming' || m.status === 'Ongoing');
                                    const matchesSport = (sportFilter === 'all' || (m.sport_category || '').toLowerCase() === sportFilter.toLowerCase());
                                    const matchesDate = (!dateFilter || m.match_date === dateFilter);
                                    const nameBlob = `${m.team1_name} ${m.team2_name} ${m.team1_college || ''} ${m.team2_college || ''} ${m.sport_category || ''}`.toLowerCase();
                                    const matchesTeam = (!teamQuery || nameBlob.includes(teamQuery));
                                    return matchesStatus && matchesSport && matchesDate && matchesTeam;
                                });
                                renderScheduleTable(matchesFiltered);
                                populateMatchResults(rawMatchSchedule); // Re-render results too, though they only need status=Completed
                            }
                        })
                        .catch(error => {
                             console.error('Filter Re-render Error:', error);
                        });
                reRenderOnly();
                
            }, 250);
            document.getElementById('filterTeamSearch').addEventListener('input', applyFilters);
            document.getElementById('filterSport').addEventListener('change', applyFilters);
            document.getElementById('filterDate').addEventListener('change', applyFilters);

            // Leaderboard-only toggle
            document.getElementById('toggleLeaderboard').addEventListener('click', function(){
                const tabs = document.querySelectorAll('#eventDetailsTab .nav-link');
                const panes = document.querySelectorAll('#eventDetailsTabContent .tab-pane');
                tabs.forEach(t=>t.classList.remove('active'));
                panes.forEach(p=>p.classList.remove('show','active'));
                document.getElementById('medals-tab').classList.add('active');
                document.getElementById('tab-medals').classList.add('show','active');
            });

            // Print
            document.getElementById('printView').addEventListener('click', ()=>window.print());

            // Export CSV helpers
            function downloadCsv(filename, csv) {
                const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url; a.download = filename; a.click(); URL.revokeObjectURL(url);
            }
            document.getElementById('exportStandingsCsv').addEventListener('click', function(e){
                e.preventDefault();
                if (!currentMedalStandingsData || currentMedalStandingsData.length===0) return;
                const rows = [['Rank','Team','Gold','Silver','Bronze','Total']];
                const sorted = [...currentMedalStandingsData].sort((a,b)=>{
                    const ag=parseInt(a.gold)||0,bg=parseInt(b.gold)||0; if (bg!==ag) return bg-ag;
                    const as=parseInt(a.silver)||0,bs=parseInt(b.silver)||0; if (bs!==as) return bs-as;
                    const ab=parseInt(a.bronze)||0,bb=parseInt(b.bronze)||0; if (bb!==ab) return bb-ab;
                    const at=parseInt(a.total)||0,bt=parseInt(b.total)||0; return bt-at;
                });
                sorted.forEach((s,i)=>rows.push([i+1, `${s.team_name} (${s.college||'N/A'})`, s.gold, s.silver, s.bronze, s.total]));
                const csv = rows.map(r=>r.map(v=>`"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
                downloadCsv('medal_standings.csv', csv);
            });
            document.getElementById('exportScheduleCsv').addEventListener('click', function(e){
                e.preventDefault();
                // Build from current visible schedule table
                const rows = [['#','Date','Time','Teams','Venue','Status']];
                document.querySelectorAll('#scheduleTableBody tr').forEach(tr=>{
                    const cols = Array.from(tr.querySelectorAll('td')).slice(0,6).map(td=>td.innerText.trim());
                    rows.push(cols);
                });
                const csv = rows.map(r=>r.map(v=>`"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
                downloadCsv('match_schedule.csv', csv);
            });
        }); // End of DOMContentLoaded
    </script>
</body>
</html>