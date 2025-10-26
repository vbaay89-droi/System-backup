<?php
session_start();

// --- Temporarily enable error reporting for debugging ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
// --- End temporary error reporting ---

// --- Authentication Check for Admin Dashboard ---
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

require_once 'config.php'; // Make sure this path is correct

// NOTE: Obsolete column check functions removed, as the new design uses gold_count, silver_count, bronze_count.

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
    unset($_SESSION['message']); // Clear message after displaying
    unset($_SESSION['message_type']); // Clear message type after displaying
}

// --- Fetch all events and teams for dropdowns ---
$all_events = [];
$events_by_category = [
    'Ball games' => [],
    'Racket games' => [],
    'Athletics' => [],
    'Other games' => []
];

// UPDATED SQL: Selecting gold_count, silver_count, bronze_count instead of medal_count
$sql_events = "
    SELECT 
        e.event_id,
        e.event_name,
        s.sport_name,
        s.category,
        COALESCE(e.gold_count, 0) AS gold_count,
        COALESCE(e.silver_count, 0) AS silver_count,
        COALESCE(e.bronze_count, 0) AS bronze_count,
        e.event_status,
        -- Calculate total assigned count (for display purposes)
        COALESCE(mcnt.total_assigned, 0) AS assigned_count
    FROM events e
    JOIN sports s ON e.sport_id = s.sport_id
    LEFT JOIN (
        SELECT event_id, COUNT(*) AS total_assigned
        FROM medals
        GROUP BY event_id
    ) mcnt ON mcnt.event_id = e.event_id
    ORDER BY e.event_name ASC
";
$result_events = $conn->query($sql_events);

// Prepare an array to hold all event data for easy lookups in the POST logic
$event_details_lookup = []; 

if ($result_events) {
    while ($row = $result_events->fetch_assoc()) {
        $all_events[] = $row;
        $event_details_lookup[$row['event_id']] = $row; // Store for POST lookup
        
        // Group events by category from sports table
        $main_category = $row['category'];
        
        // Group events by main category
        if (array_key_exists($main_category, $events_by_category)) {
            $events_by_category[$main_category][] = $row;
        } else {
            // If category doesn't match predefined ones, put in "Other games"
            $events_by_category['Other games'][] = $row;
        }
    }
}

$all_teams = [];
// Ensure college is fetched for the team dropdowns
$sql_teams = "SELECT team_id, team_name, college FROM teams ORDER BY team_name ASC";
$result_teams = $conn->query($sql_teams);
if ($result_teams) {
    while ($row = $result_teams->fetch_assoc()) {
        $all_teams[] = $row;
    }
}

// --- Handle Medal Form Submissions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_medal') {
        $event_id = $_POST['event_id'] ?? null;
        $gold_team_id = $_POST['gold_team_id'] ?? null;
        $silver_team_id = $_POST['silver_team_id'] ?? null;
        $bronze_team_id = $_POST['bronze_team_id'] ?? null;

        if (empty($event_id)) {
            $_SESSION['message'] = "Please select an event for medal assignment.";
            $_SESSION['message_type'] = "danger";
        } else {
            // --- UPDATED LOGIC FOR MEDAL QUANTITY/CAP CHECK ---
            // Fetch the event details including new medal counts
            $event = $event_details_lookup[$event_id] ?? null;
            if (!$event) {
                 $_SESSION['message'] = "Invalid event selected.";
                 $_SESSION['message_type'] = "danger";
                 header('Location: Manage_Medals.php');
                 exit();
            }

            $success_count = 0;
            $error_messages = [];
            
            // Check if any Gold medals are assigned
            $sql_check_gold = "SELECT COUNT(*) FROM medals WHERE event_id = ? AND medal_type = 'Gold'";
            $stmt_check = $conn->prepare($sql_check_gold);
            $assigned_gold = 0;
            if ($stmt_check) {
                $stmt_check->bind_param("i", $event_id);
                $stmt_check->execute();
                $stmt_check->bind_result($assigned_gold);
                $stmt_check->fetch();
                $stmt_check->close();
            }
            
            // Assign Gold medal
            if (!empty($gold_team_id)) {
                $gold_quantity = max(1, (int)($event['gold_count'] ?? 1));
                if ($assigned_gold > 0) {
                     $error_messages[] = "Gold medal is already assigned for this event. Please delete the existing assignment first.";
                } else {
                    $sql_insert = "INSERT INTO medals (event_id, team_id, medal_type, medal_quantity) VALUES (?, ?, 'Gold', ?)";
                    $stmt_insert = $conn->prepare($sql_insert);
                    if ($stmt_insert) {
                        $stmt_insert->bind_param("iii", $event_id, $gold_team_id, $gold_quantity);
                        if ($stmt_insert->execute()) {
                            $success_count++;
                        } else {
                            $error_messages[] = "Error assigning Gold medal: " . $stmt_insert->error;
                        }
                        $stmt_insert->close();
                    } else {
                        $error_messages[] = "Database prepare error for Gold medal: " . $conn->error;
                    }
                }
            }

            // Check if any Silver medals are assigned
            $sql_check_silver = "SELECT COUNT(*) FROM medals WHERE event_id = ? AND medal_type = 'Silver'";
            $stmt_check = $conn->prepare($sql_check_silver);
            $assigned_silver = 0;
            if ($stmt_check) {
                $stmt_check->bind_param("i", $event_id);
                $stmt_check->execute();
                $stmt_check->bind_result($assigned_silver);
                $stmt_check->fetch();
                $stmt_check->close();
            }

            // Assign Silver medal
            if (!empty($silver_team_id)) {
                $silver_quantity = max(1, (int)($event['silver_count'] ?? 1));
                if ($assigned_silver > 0) {
                     $error_messages[] = "Silver medal is already assigned for this event. Please delete the existing assignment first.";
                } else {
                    $sql_insert = "INSERT INTO medals (event_id, team_id, medal_type, medal_quantity) VALUES (?, ?, 'Silver', ?)";
                    $stmt_insert = $conn->prepare($sql_insert);
                    if ($stmt_insert) {
                        $stmt_insert->bind_param("iii", $event_id, $silver_team_id, $silver_quantity);
                        if ($stmt_insert->execute()) {
                            $success_count++;
                        } else {
                            $error_messages[] = "Error assigning Silver medal: " . $stmt_insert->error;
                        }
                        $stmt_insert->close();
                    } else {
                        $error_messages[] = "Database prepare error for Silver medal: " . $conn->error;
                    }
                }
            }
            
            // Check if any Bronze medals are assigned
            $sql_check_bronze = "SELECT COUNT(*) FROM medals WHERE event_id = ? AND medal_type = 'Bronze'";
            $stmt_check = $conn->prepare($sql_check_bronze);
            $assigned_bronze = 0;
            if ($stmt_check) {
                $stmt_check->bind_param("i", $event_id);
                $stmt_check->execute();
                $stmt_check->bind_result($assigned_bronze);
                $stmt_check->fetch();
                $stmt_check->close();
            }

            // Assign Bronze medal
            if (!empty($bronze_team_id)) {
                $bronze_quantity = max(1, (int)($event['bronze_count'] ?? 1));
                if ($assigned_bronze > 0) {
                     $error_messages[] = "Bronze medal is already assigned for this event. Please delete the existing assignment first.";
                } else {
                    $sql_insert = "INSERT INTO medals (event_id, team_id, medal_type, medal_quantity) VALUES (?, ?, 'Bronze', ?)";
                    $stmt_insert = $conn->prepare($sql_insert);
                    if ($stmt_insert) {
                        $stmt_insert->bind_param("iii", $event_id, $bronze_team_id, $bronze_quantity);
                        if ($stmt_insert->execute()) {
                            $success_count++;
                        } else {
                            $error_messages[] = "Error assigning Bronze medal: " . $stmt_insert->error;
                        }
                        $stmt_insert->close();
                    } else {
                        $error_messages[] = "Database prepare error for Bronze medal: " . $conn->error;
                    }
                }
            }


            if ($success_count > 0) {
                $_SESSION['message'] = "Successfully assigned {$success_count} medal(s)! Please review individual entries for specific quantities.";
                $_SESSION['message_type'] = "success";
            } else {
                $_SESSION['message'] = "No medals were assigned. Please select at least one team.";
                $_SESSION['message_type'] = "danger";
            }

            if (!empty($error_messages)) {
                $_SESSION['message'] .= " Errors: " . implode("; ", $error_messages);
                $_SESSION['message_type'] = "warning";
            }
        }
        header('Location: Manage_Medals.php');
        exit();
    } elseif ($action === 'update_medal') {
        $medal_id = $_POST['medal_id'] ?? null;
        $event_id = $_POST['event_id'] ?? null;
        $team_id = $_POST['team_id'] ?? null;
        $medal_type = $_POST['medal_type'] ?? null;

        if (empty($medal_id) || empty($event_id) || empty($team_id) || empty($medal_type)) {
            $_SESSION['message'] = "Please fill all required fields for medal update.";
            $_SESSION['message_type'] = "danger";
        } else {
            // Fetch event details to get the correct medal quantity
            $event = $event_details_lookup[$event_id] ?? null;
            if (!$event) {
                 $_SESSION['message'] = "Invalid event selected for update.";
                 $_SESSION['message_type'] = "danger";
                 header('Location: Manage_Medals.php');
                 exit();
            }
            
            // Determine the correct medal quantity based on medal_type
            $medal_quantity = 1; // Default fallback
            if ($medal_type === 'Gold') {
                $medal_quantity = max(1, (int)($event['gold_count'] ?? 1));
            } elseif ($medal_type === 'Silver') {
                $medal_quantity = max(1, (int)($event['silver_count'] ?? 1));
            } elseif ($medal_type === 'Bronze') {
                $medal_quantity = max(1, (int)($event['bronze_count'] ?? 1));
            }

            // Check for duplicate medal assignment (same event, same medal type, different medal_id)
            $sql_check_duplicate = "SELECT COUNT(*) FROM medals WHERE event_id = ? AND team_id = ? AND medal_type = ? AND medal_id != ?";
            $stmt_check = $conn->prepare($sql_check_duplicate);
            if ($stmt_check) { // Check if prepare was successful
                $stmt_check->bind_param("iisi", $event_id, $team_id, $medal_type, $medal_id);
                $stmt_check->execute();
                $stmt_check->bind_result($count);
                $stmt_check->fetch();
                $stmt_check->close();

                if ($count > 0) {
                    $_SESSION['message'] = "Duplicate entry: This team already has a {$medal_type} medal for this event.";
                    $_SESSION['message_type'] = "danger";
                } else {
                    // Update with new medal_quantity
                    $sql_update = "UPDATE medals SET event_id = ?, team_id = ?, medal_type = ?, medal_quantity = ? WHERE medal_id = ?";
                    $stmt_update = $conn->prepare($sql_update);
                    if ($stmt_update) {
                        $stmt_update->bind_param("iisii", $event_id, $team_id, $medal_type, $medal_quantity, $medal_id);
                        if ($stmt_update->execute()) {
                            $_SESSION['message'] = "Medal entry updated successfully! Team now has {$medal_quantity} {$medal_type} medal(s).";
                            $_SESSION['message_type'] = "success";
                        } else {
                            $_SESSION['message'] = "Error updating medal entry: " . $stmt_update->error;
                            $_SESSION['message_type'] = "danger";
                        }
                        $stmt_update->close();
                    } else {
                        $_SESSION['message'] = "Database prepare error (update): " . $conn->error;
                        $_SESSION['message_type'] = "danger";
                    }
                }
            } else {
                $_SESSION['message'] = "Database prepare error (check duplicate for update): " . $conn->error;
                $_SESSION['message_type'] = "danger";
            }
        }
        header('Location: Manage_Medals.php');
        exit();
    } elseif ($action === 'delete_medal') {
        $medal_id = $_POST['medal_id'] ?? null;

        if (empty($medal_id)) {
            $_SESSION['message'] = "No medal ID provided for deletion.";
            $_SESSION['message_type'] = "danger";
        } else {
            $sql_delete = "DELETE FROM medals WHERE medal_id = ?";
            $stmt_delete = $conn->prepare($sql_delete);
            if ($stmt_delete) {
                $stmt_delete->bind_param("i", $medal_id);
                if ($stmt_delete->execute()) {
                    $_SESSION['message'] = "Medal entry deleted successfully!";
                    $_SESSION['message_type'] = "success";
                } else {
                    $_SESSION['message'] = "Error deleting medal entry: " . $stmt_delete->error;
                    $_SESSION['message_type'] = "danger";
                }
                $stmt_delete->close();
            } else {
                $_SESSION['message'] = "Database prepare error (delete): " . $conn->error;
                $_SESSION['message_type'] = "danger";
            }
        }
        header('Location: Manage_Medals.php');
        exit();
    }
}

// --- Fetch Medal Standings (Auto-Tally System) - Now uses medal_quantity ---
$medal_standings = [];
$sql_standings = "
    SELECT 
        t.team_id,
        t.team_name,
        t.college,
        SUM(CASE WHEN m.medal_type = 'Gold' THEN COALESCE(m.medal_quantity, 1) ELSE 0 END) AS gold,
        SUM(CASE WHEN m.medal_type = 'Silver' THEN COALESCE(m.medal_quantity, 1) ELSE 0 END) AS silver,
        SUM(CASE WHEN m.medal_type = 'Bronze' THEN COALESCE(m.medal_quantity, 1) ELSE 0 END) AS bronze
    FROM 
        teams t
    LEFT JOIN 
        medals m ON t.team_id = m.team_id
    GROUP BY 
        t.team_id, t.team_name, t.college
    ORDER BY 
        gold DESC, silver DESC, bronze DESC, t.team_name ASC
";
$result_standings = $conn->query($sql_standings);
if ($result_standings) {
    while ($row = $result_standings->fetch_assoc()) {
        $medal_standings[] = $row;
    }
}

// --- Fetch all individual medal entries for management ---
$individual_medals = [];
$sql_individual_medals = "
    SELECT 
        m.medal_id,
        e.event_name,
        s.sport_name,
        s.category,
        t.team_name,
        t.college,
        m.medal_type,
        COALESCE(m.medal_quantity, 1) AS medal_quantity,
        m.event_id,
        m.team_id
    FROM 
        medals m
    JOIN 
        events e ON m.event_id = e.event_id
    JOIN 
        sports s ON e.sport_id = s.sport_id
    JOIN 
        teams t ON m.team_id = t.team_id
    ORDER BY 
        e.event_name ASC, m.medal_type DESC
";
$result_individual_medals = $conn->query($sql_individual_medals);
if ($result_individual_medals) {
    while ($row = $result_individual_medals->fetch_assoc()) {
        $individual_medals[] = $row;
    }
}

// --- IMPORTANT: Close database connection at the very end of the script ---
$conn->close();

// Dummy values for college logos if not fetched from a global config (for navbar)
$college_logos = [
    'COTE'    => 'COTE.png',
    'CAS'     => 'CASlogo.png',
    'COMED'   => 'COMED.png',
    'CTE'     => 'CTE.png',
    'PIT-TC' => 'PIT.png'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Medals - PIT SPORTS TALLYING</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
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
        
        /* Custom brand interaction */
        .interactive-brand:hover .brand-heading,
        .interactive-brand:hover .brand-subheading {
            color: rgba(0, 102, 255, 0.43) !important;
        }

        /* Main Content Layout */
        .main-content {
            flex-grow: 1;
            max-width: 1400px; 
            margin: 0 auto;
            padding: 20px;
            width: 100%;
        }

        /* Page Header Card Styling */
        .header-card {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            padding: 20px 25px;
            margin-bottom: 25px;
            transition: all 0.3s ease;
        }

        .header-card h2 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .header-card p {
            color: #6c757d;
            font-size: 0.95rem;
            margin-top: 0.25rem;
        }

        /* Report Card / Section Card */
        .report-card {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            padding: 20px;
            margin-bottom: 20px;
            scroll-margin-top: 90px; 
            transition: all 0.3s;
        }

        /* Section Header */
        .section-header {
            display: flex;
            align-items: center;
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
            padding-bottom: 10px;
            border-bottom: 1px solid #08CB00;
            margin-bottom: 20px;
        }

        .section-header span {
            margin-right: 8px;
            font-size: 1.3rem;
        }

        .section-description {
            font-size: 0.875rem;
            color: #6c757d;
            margin-bottom: 1rem;
        }

        /* Sticky Sidebar Styles for Desktop */
        .sticky-sidebar-col {
            margin-bottom: 20px;
        }
        
        @media (min-width: 992px) {
            .sticky-sidebar-col {
                position: sticky;
                top: 80px;
                align-self: flex-start;
                z-index: 100;
                margin-bottom: 0;
            }
        }

        /* Quick Jump Navigation Styling */
        .quick-jump-nav-card {
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            padding: 20px;
            margin-bottom: 20px;
        }

        .quick-jump-link {
            display: flex;
            align-items: center;
            padding: 8px 10px;
            margin-bottom: 4px;
            border-radius: 4px;
            color: #495057;
            text-decoration: none;
            transition: background-color 0.2s, border-left 0.2s, color 0.2s;
            border-left: 4px solid transparent;
            font-size: 0.95rem;
        }

        .quick-jump-link:hover {
            background-color: #f0f0f0;
            color: #007bff;
        }

        .quick-jump-link.active-link {
            background-color: #fbe5e5;
            border-left: 4px solid #dc3545;
            font-weight: 500;
            color: #dc3545;
        }

        .quick-jump-link .fa-icon {
            margin-right: 10px;
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
        }

        /* Medal Icon Colors */
        .medal-icon {
            font-size: 1.2em;
            margin-right: 5px;
        }
        .gold-medal, .gold-icon { color: gold; }
        .silver-medal, .silver-icon { color: silver; }
        .bronze-medal, .bronze-icon { color: #cd7f32; }

        /* Table Styling */
        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            border: 1px solid #ddd; 
            border-radius: 4px;
            overflow: hidden;
        }
        
        .report-table th, 
        .report-table td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0; 
        }
        
        .report-table th {
            font-weight: 600;
            color: white;
            font-size: 0.85rem;
            background-color: #4ca728;
        }

        .report-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: center;
            align-items: center;
            flex-wrap: nowrap;
        }

        .action-buttons .btn {
            flex-shrink: 0;
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }

        /* Event Tabs for Assign Medals */
        .event-tabs {
            display: flex;
            gap: 18px;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 10px;
        }
        .event-tab {
            appearance: none;
            background: transparent;
            border: none;
            padding: 10px 2px;
            color: #374151;
            font-weight: 600;
            position: relative;
            cursor: pointer;
        }
        .event-tab.active { color: #2a7f7a; }
        .event-tab.active:after {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            bottom: -1px;
            height: 3px;
            background-color: #2a7f7a;
            border-radius: 2px 2px 0 0;
        }

        /* Event Item Selected State */
        .event-item {
            transition: background-color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, transform 0.1s ease;
            position: relative;
            border: 1px solid #e5e7eb;
        }
        .event-item:hover { background-color: #f8fafc; }
        .event-item.selected {
            background-color: #87CEFA ;
            border-color: #2a7f7a;
            box-shadow: 0 1px 0 rgba(0,0,0,0.02);
        }
        .event-item .event-title { font-weight: 600; }
        .event-item.selected .event-title { font-weight: 800; }
        .event-item .selected-badge {
            display: none;
            position: absolute;
            top: 8px;
            right: 10px;
            background-color: #2a7f7a;
            color: #ffffff;
            border-radius: 12px;
            padding: 2px 8px;
            font-size: 0.75rem;
        }
        .event-item.selected .selected-badge { display: inline-block; }
        .event-item .check-accent { display: none; color: #2a7f7a; margin-left: 8px; }
        .event-item.selected .check-accent { display: inline-block; }

        /* Category-based borders */
        .athletics-event { border: 2px solid red !important; border-radius: 6px; }
        .ballgames-event { border: 2px solid green !important; border-radius: 6px; }
        .racketgames-event { border: 2px solid blue !important; border-radius: 6px; }
        .othergames-event { border: 2px solid purple !important; border-radius: 6px; }

        /* Category selection styling */
        .category-btn {
            transition: all 0.3s ease;
            border-radius: 8px !important;
        }
        .category-btn:hover {
            background-color: #0d6efd;
            color: white;
        }
        .category-btn.active {
            background-color: #0d6efd;
            color: white;
        }
        .category-icon {
            transition: transform 0.3s ease;
        }
        .category-btn.active .category-icon {
            transform: rotate(90deg);
        }
        .events-list {
            border-left: 3px solid #0d6efd;
            margin-left: 15px;
            padding-left: 15px;
        }
        .event-option {
            border-radius: 6px !important;
            margin-bottom: 5px;
            transition: all 0.2s ease;
        }
        .event-option:hover {
            background-color: #e9ecef;
            transform: translateX(5px);
        }
        .event-option.selected {
            background-color: #0d6efd;
            color: white;
        }
        .event-option.selected:hover {
            background-color: #0b5ed7;
        }

        .category-selection {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            background-color: #f8f9fa;
        }

        /* Back Button Styling */
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

        /* Responsive */
        @media (max-width: 768px) {
            body { 
                padding-top: 100px;
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

    <main class="main-content">
        
        <div class="header-card" style="padding-top: 20px;">
            <h2>
                <span style="color: var(--primary-dark); font-size: 1.5rem;"><i class="fas fa-trophy"></i></span> Manage Medals
            </h2>
            <p>Assign medals to teams, track medal standings, and manage individual medal entries for all tournament events.</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            
            <div class="col-lg-3 sticky-sidebar-col">
                
                <div class="quick-jump-nav-card">
                    <h5 class="text-secondary mb-3"><i class="fas fa-link me-2"></i>Quick Jump</h5>
                    <nav class="nav flex-column" id="quick-jump-nav">
                        <a class="quick-jump-link active-link" href="#assign-medals" data-target="assign-medals">
                            <span class="fa-icon"><i class="fas fa-plus-circle"></i></span> Assign Medals
                        </a>
                        <a class="quick-jump-link" href="#medal-standings" data-target="medal-standings">
                            <span class="fa-icon gold-icon"><i class="fas fa-medal"></i></span> Medal Standings
                        </a>
                        <a class="quick-jump-link" href="#individual-entries" data-target="individual-entries">
                            <span class="fa-icon"><i class="fas fa-list"></i></span> Individual Entries
                        </a>
                    </nav>
                </div>

                <div class="d-grid">
                    <a href="admin_dashboard.php" class="btn btn-outline-secondary back-button">
                        <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                    </a>
                </div>
            </div>

            <div class="col-lg-9">
                
                <div class="report-card report-section" id="assign-medals">
                    <div class="section-header">
                        <span style="color: #555;">🎖️</span> Assign Medals
                    </div>
                    <p class="section-description">Assign Gold, Silver, and Bronze medals to teams for specific events. Each medal assignment follows the event's medal count settings.</p>

                    <form id="addMedalForm" action="Manage_Medals.php" method="POST">
                        <input type="hidden" name="action" value="add_medal">
                        
                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <label class="form-label fw-bold">Event Category<span class="text-danger">*</span></label>
                                <div class="event-tabs" id="assignEventTabs">
                                    <?php foreach ($events_by_category as $category_name => $events): ?>
                                        <button type="button" class="event-tab" data-category="<?= htmlspecialchars($category_name) ?>">
                                            <?= htmlspecialchars($category_name) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>

                                <div class="list-group" id="assignEventsList"></div>
                                <small class="text-muted">Tip: Click an event to select it. Selected item will be highlighted.</small>

                                <input type="hidden" id="addEventId" name="event_id" required>
                                <div class="form-text" id="eventMedalInfo">Select a category and then choose an event to see medal count information.</div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="card border-warning">
                                    <div class="card-header bg-warning text-dark">
                                        <h5 class="mb-0"><i class="fas fa-medal gold-medal me-2"></i>Gold Medal</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label for="goldTeamId" class="form-label">Team / College</label>
                                            <select class="form-select" id="goldTeamId" name="gold_team_id">
                                                <option value="">Select Team (Optional)</option>
                                                <?php foreach ($all_teams as $team): ?>
                                                    <option value="<?= htmlspecialchars($team['team_id']) ?>">
                                                        <?= htmlspecialchars($team['team_name']) ?> (<?= htmlspecialchars($team['college'] ?? 'N/A') ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="alert alert-info mb-0">
                                            <small><i class="fas fa-info-circle me-1"></i>Medal type is fixed to Gold</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="card border-secondary">
                                    <div class="card-header bg-secondary text-white">
                                        <h5 class="mb-0"><i class="fas fa-medal silver-medal me-2"></i>Silver Medal</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label for="silverTeamId" class="form-label">Team / College</label>
                                            <select class="form-select" id="silverTeamId" name="silver_team_id">
                                                <option value="">Select Team (Optional)</option>
                                                <?php foreach ($all_teams as $team): ?>
                                                    <option value="<?= htmlspecialchars($team['team_id']) ?>">
                                                        <?= htmlspecialchars($team['team_name']) ?> (<?= htmlspecialchars($team['college'] ?? 'N/A') ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="alert alert-info mb-0">
                                            <small><i class="fas fa-info-circle me-1"></i>Medal type is fixed to Silver</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="card border-danger">
                                    <div class="card-header bg-danger text-white">
                                        <h5 class="mb-0"><i class="fas fa-medal bronze-medal me-2"></i>Bronze Medal</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label for="bronzeTeamId" class="form-label">Team / College</label>
                                            <select class="form-select" id="bronzeTeamId" name="bronze_team_id">
                                                <option value="">Select Team (Optional)</option>
                                                <?php foreach ($all_teams as $team): ?>
                                                    <option value="<?= htmlspecialchars($team['team_id']) ?>">
                                                        <?= htmlspecialchars($team['team_name']) ?> (<?= htmlspecialchars($team['college'] ?? 'N/A') ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="alert alert-info mb-0">
                                            <small><i class="fas fa-info-circle me-1"></i>Medal type is fixed to Bronze</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mt-4">
                            <div class="col-12">
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>Note:</strong> All three medal types (Gold, Silver, Bronze) are assigned at once. The quantity for each type is based on the specific count set in Manage Events (e.g., Event X awards 3 Gold, 2 Silver, 1 Bronze).
                                </div>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-trophy me-2"></i>Assign All Medals</button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="report-card report-section" id="medal-standings">
                    <div class="section-header">
                        <span style="color: #555;">🏆</span> Medal Standings
                    </div>
                    <p class="section-description">Overall medal tally for all teams, ranked by total medals earned.</p>
                    
                    <div class="table-responsive">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>College / Team</th>
                                    <th class="text-center">Gold <i class="fas fa-medal gold-medal"></i></th>
                                    <th class="text-center">Silver <i class="fas fa-medal silver-medal"></i></th>
                                    <th class="text-center">Bronze <i class="fas fa-medal bronze-medal"></i></th>
                                    <th class="text-center">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($medal_standings)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">No medal standings available. Assign some medals!</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($medal_standings as $standing): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($standing['team_name']) ?> (<?= htmlspecialchars($standing['college'] ?? 'N/A') ?>)</td>
                                            <td class="text-center"><?= htmlspecialchars($standing['gold']) ?></td>
                                            <td class="text-center"><?= htmlspecialchars($standing['silver']) ?></td>
                                            <td class="text-center"><?= htmlspecialchars($standing['bronze']) ?></td>
                                            <td class="text-center fw-bold"><?= $standing['gold'] + $standing['silver'] + $standing['bronze'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="report-card report-section" id="individual-entries">
                    <div class="section-header">
                        <span style="color: #555;">📋</span> Individual Medal Entries
                    </div>
                    <p class="section-description">Manage all individual medal assignments with options to edit or delete entries.</p>
                    
                    <div class="table-responsive">
                        <table class="report-table" id="individualMedalsTable">
                            <thead>
                                <tr>
                                    <th>Event</th>
                                    <th>Category</th>
                                    <th>Team</th>
                                    <th>College</th>
                                    <th>Medal Type</th>
                                    <th class="text-center">Quantity</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($individual_medals)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">No individual medal entries found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($individual_medals as $medal): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($medal['event_name']) ?></td>
                                            <td><?= htmlspecialchars($medal['sport_name'] ?? 'N/A') ?> (<?= htmlspecialchars($medal['category'] ?? 'N/A') ?>)</td>
                                            <td><?= htmlspecialchars($medal['team_name']) ?></td>
                                            <td><?= htmlspecialchars($medal['college'] ?? 'N/A') ?></td>
                                            <td>
                                                <i class="fas fa-medal medal-icon 
                                                    <?php 
                                                        if ($medal['medal_type'] == 'Gold') echo 'gold-medal'; 
                                                        else if ($medal['medal_type'] == 'Silver') echo 'silver-medal'; 
                                                        else if ($medal['medal_type'] == 'Bronze') echo 'bronze-medal'; 
                                                    ?>"></i>
                                                <?= htmlspecialchars($medal['medal_type']) ?>
                                            </td>
                                            <td class="text-center fw-bold"><?= htmlspecialchars($medal['medal_quantity']) ?></td>
                                            <td class="action-buttons">
                                                <button type="button" class="btn btn-sm btn-warning edit-medal-btn" 
                                                        data-bs-toggle="modal" data-bs-target="#editMedalModal" 
                                                        data-id="<?= $medal['medal_id'] ?>" 
                                                        data-event-id="<?= $medal['event_id'] ?>"
                                                        data-team-id="<?= $medal['team_id'] ?>"
                                                        data-medal-type="<?= $medal['medal_type'] ?>"
                                                        title="Edit Medal"><i class="fas fa-edit"></i></button>
                                                <button type="button" class="btn btn-sm btn-danger delete-medal-btn" 
                                                        data-id="<?= $medal['medal_id'] ?>" title="Delete Medal"><i class="fas fa-trash-alt"></i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
            
        </div>
        </main>

    <div class="modal fade" id="editMedalModal" tabindex="-1" aria-labelledby="editMedalModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="editMedalModalLabel"><i class="fas fa-edit me-2"></i>Edit Medal Entry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="editMedalForm" action="Manage_Medals.php" method="POST">
                    <input type="hidden" name="action" value="update_medal">
                    <input type="hidden" id="editMedalId" name="medal_id">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-1"></i>
                            The medal quantity will be automatically updated based on the **selected event's specific medal counts (Gold/Silver/Bronze)**.
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Event Category<span class="text-danger">*</span></label>
                            <div class="category-selection mb-3">
                                <?php foreach ($events_by_category as $category_name => $events): ?>
                                    <div class="category-group mb-3">
                                        <button type="button" class="btn btn-outline-primary category-btn w-100 text-start" 
                                                data-category="<?= htmlspecialchars($category_name) ?>"
                                                data-target="#edit-events-<?= htmlspecialchars(strtolower(str_replace(' ', '-', $category_name))) ?>">
                                            <i class="fas fa-chevron-right me-2 category-icon"></i>
                                            <?= htmlspecialchars($category_name) ?>
                                            <span class="badge bg-secondary ms-2"><?= count($events) ?></span>
                                        </button>
                                        <div class="events-list collapse" id="edit-events-<?= htmlspecialchars(strtolower(str_replace(' ', '-', $category_name))) ?>">
                                            <?php if (!empty($events)): ?>
                                                <div class="list-group mt-2">
                                                    <?php foreach ($events as $event): ?>
                                                        <button type="button" class="list-group-item list-group-item-action edit-event-option" 
                                                                data-event-id="<?= htmlspecialchars($event['event_id']) ?>"
                                                                data-category="<?= htmlspecialchars(strtolower($event['category'] ?? '')) ?>"
                                                                data-gold-count="<?= htmlspecialchars($event['gold_count']) ?>"
                                                                data-silver-count="<?= htmlspecialchars($event['silver_count']) ?>"
                                                                data-bronze-count="<?= htmlspecialchars($event['bronze_count']) ?>"
                                                                data-assigned-count="<?= htmlspecialchars($event['assigned_count']) ?>">
                                                            <div class="d-flex justify-content-between align-items-center">
                                                                <div>
                                                                    <strong><?= htmlspecialchars($event['event_name']) ?></strong>
                                                                    <br>
                                                                    <small class="text-muted"><?= htmlspecialchars($event['sport_name'] ?? 'N/A') ?> - <?= htmlspecialchars($event['category'] ?? 'N/A') ?></small>
                                                                </div>
                                                                <div class="text-end">
                                                                    <small class="text-muted">
                                                                        <?= htmlspecialchars($event['assigned_count']) ?> assigned
                                                                    </small>
                                                                </div>
                                                            </div>
                                                        </button>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="alert alert-info mt-2 mb-0">
                                                    <i class="fas fa-info-circle me-2"></i>
                                                    No events available in this category. Add events in <strong>Manage Events</strong> first.
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" id="editEventId" name="event_id" required>
                        </div>
                        <div class="mb-3">
                            <label for="editTeamId" class="form-label">Team / College<span class="text-danger">*</span></label>
                            <select class="form-select" id="editTeamId" name="team_id" required>
                                <option value="">Select Team</option>
                                <?php foreach ($all_teams as $team): ?>
                                    <option value="<?= htmlspecialchars($team['team_id']) ?>">
                                        <?= htmlspecialchars($team['team_name']) ?> (<?= htmlspecialchars($team['college'] ?? 'N/A') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="editMedalType" class="form-label">Medal Type<span class="text-danger">*</span></label>
                            <select class="form-select" id="editMedalType" name="medal_type" required>
                                <option value="">Select Medal Type</option>
                                <option value="Gold">Gold</option>
                                <option value="Silver">Silver</option>
                                <option value="Bronze">Bronze</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-warning"><i class="fas fa-save me-2"></i>Update Medal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="confirmationModalLabel">Confirm Deletion</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Are you sure you want to delete this medal entry? This action cannot be undone.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form id="deleteMedalForm" action="Manage_Medals.php" method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="delete_medal">
                        <input type="hidden" id="deleteMedalId" name="medal_id">
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <footer class="bg-dark text-white py-3 mt-auto">
        <div class="container text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
            <small>Developed by Tsunayoshi Sawada</small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Quick Jump Navigation - Smooth Scrolling and Active Link Tracking
            const sections = document.querySelectorAll('.report-section');
            const links = document.querySelectorAll('.quick-jump-link');
            const navContainer = document.getElementById('quick-jump-nav');

            // Smooth Scrolling
            navContainer.addEventListener('click', function(e) {
                if (e.target.closest('.quick-jump-link')) {
                    e.preventDefault();
                    const link = e.target.closest('.quick-jump-link');
                    const targetId = link.getAttribute('data-target');
                    const targetElement = document.getElementById(targetId);

                    if (targetElement) {
                        targetElement.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                }
            });

            // Intersection Observer for Active State
            const observerOptions = {
                root: null, 
                rootMargin: '-50% 0px -40% 0px', 
                threshold: 0 
            };

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    const targetId = entry.target.id;
                    const link = document.querySelector(`.quick-jump-link[data-target="${targetId}"]`);
                    
                    if (link) {
                        if (entry.isIntersecting) {
                            links.forEach(l => l.classList.remove('active-link'));
                            link.classList.add('active-link');
                        }
                    }
                });
            }, observerOptions);

            sections.forEach(section => {
                observer.observe(section);
            });
            // Assign Medals: Tabs + Alphabetical Event List
            const tabs = document.getElementById('assignEventTabs');
            const list = document.getElementById('assignEventsList');
            const hiddenEventId = document.getElementById('addEventId');
            const eventInfo = document.getElementById('eventMedalInfo');
            
            // Pass PHP event data to JS
            const byCategory = <?php echo json_encode($events_by_category, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            const eventDetails = {};
            <?php foreach($all_events as $event) {
                echo "eventDetails[{$event['event_id']}] = " . json_encode($event) . ";\n";
            } ?>
            

            function sortByEventName(a, b){
                const an = (a.event_name || '').toLowerCase();
                const bn = (b.event_name || '').toLowerCase();
                if (an < bn) return -1; if (an > bn) return 1; return 0;
            }

            const categoryKeys = Object.keys(byCategory);
            let activeCat = categoryKeys[0] || null;

            function renderTabs(){
                if (!tabs) return;
                Array.from(tabs.querySelectorAll('.event-tab')).forEach(btn => {
                    if (btn.dataset.category === activeCat) btn.classList.add('active');
                    else btn.classList.remove('active');
                });
            }

            function renderEvents(){
                if (!list) return;
                list.innerHTML = '';
                const items = (byCategory[activeCat] || []).slice().sort(sortByEventName);
                if (!items.length) {
                    const empty = document.createElement('div');
                    empty.className = 'list-group-item d-flex justify-content-between align-items-center text-muted';
                    empty.textContent = 'No Events in this category.';
                    list.appendChild(empty);
                    return;
                }
                items.forEach(ev => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center event-item';
                    
                    const totalMedals = parseInt(ev.gold_count || '0', 10) + parseInt(ev.silver_count || '0', 10) + parseInt(ev.bronze_count || '0', 10);
                    
                    btn.innerHTML = `<span><strong class="event-title">${ev.event_name}</strong><br><small class="text-muted">${ev.sport_name || ''} - ${ev.category || ''}</small></span><span class="d-flex align-items-center"><span class="selected-badge">Selected</span><small class="text-muted ms-2">${ev.assigned_count} assigned</small><i class="fa-solid fa-check check-accent ms-2"></i></span>`;
                        // Add category-based border class
                        if (ev.category === "Athletics") {
                            btn.classList.add("athletics-event");
                        } else if (ev.category === "Ball games") {
                            btn.classList.add("ballgames-event");
                        } else if (ev.category === "Racket games") {
                            btn.classList.add("racketgames-event");
                        } else if (ev.category === "Other games") {
                            btn.classList.add("othergames-event");
                        }
                        
                    // Attach the new data attributes for medal counts
                    btn.setAttribute('data-event-id', ev.event_id);
                    btn.setAttribute('data-gold-count', ev.gold_count);
                    btn.setAttribute('data-silver-count', ev.silver_count);
                    btn.setAttribute('data-bronze-count', ev.bronze_count);
                    btn.setAttribute('data-assigned-count', ev.assigned_count);

                    btn.addEventListener('click', () => {
                        // clear previous selected
                        list.querySelectorAll('.event-item.selected').forEach(n => n.classList.remove('selected'));
                        // mark current
                        btn.classList.add('selected');
                        if (hiddenEventId) hiddenEventId.value = ev.event_id;
                        
                        // Update medal info
                        updateMedalInfo(btn);
                    });
                    list.appendChild(btn);
                });
            }

            if (tabs) {
                tabs.addEventListener('click', (e) => {
                    const b = e.target.closest('.event-tab');
                    if (!b) return;
                    activeCat = b.dataset.category;
                    renderTabs();
                    renderEvents();
                });
            }

            renderTabs();
            renderEvents();
            // Adjust main content padding dynamically based on navbar height
            const navbarHeight = document.querySelector('.navbar').offsetHeight;
            document.querySelector('.main-content').style.paddingTop = `${navbarHeight + 30}px`; 

            // Handles redirect for the brand logo click
            document.querySelector('.interactive-brand').addEventListener('click', function(e) {
                e.preventDefault();
                window.location.href = 'Tournament_Manager_page.php'; 
            });

            // --- Edit Medal Modal Logic ---
            const editMedalModal = new bootstrap.Modal(document.getElementById('editMedalModal'));
            const editMedalIdInput = document.getElementById('editMedalId');
            const editEventIdInput = document.getElementById('editEventId');
            const editTeamIdSelect = document.getElementById('editTeamId');
            const editMedalTypeSelect = document.getElementById('editMedalType');

            document.querySelectorAll('.edit-medal-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const medalId = this.dataset.id;
                    const eventId = this.dataset.eventId;
                    const teamId = this.dataset.teamId;
                    const medalType = this.dataset.medalType;

                    editMedalIdInput.value = medalId;
                    editEventIdInput.value = eventId;
                    editTeamIdSelect.value = teamId;
                    editMedalTypeSelect.value = medalType;
                    
                    // Reset and select the appropriate event in the edit modal
                    resetEditModalSelection();
                    selectEditEvent(eventId);
                    
                    editMedalModal.show();
                });
            });
            
            // Handle edit modal category button clicks
            document.querySelectorAll('#editMedalModal .category-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const targetId = this.dataset.target;
                    const targetElement = document.querySelector(targetId);
                    
                    // Toggle active state
                    this.classList.toggle('active');
                    
                    // Toggle collapse
                    if (targetElement) {
                        const bsCollapse = new bootstrap.Collapse(targetElement, {
                            toggle: true
                        });
                    }
                });
            });
            
            // Handle edit modal event option clicks
            document.querySelectorAll('.edit-event-option').forEach(button => {
                button.addEventListener('click', function() {
                    // Remove selected class from all edit event options
                    document.querySelectorAll('.edit-event-option').forEach(opt => opt.classList.remove('selected'));
                    
                    // Add selected class to clicked option
                    this.classList.add('selected');
                    
                    // Set the hidden input value
                    editEventIdInput.value = this.dataset.eventId;
                });
            });
            
            function resetEditModalSelection() {
                // Reset all category buttons
                document.querySelectorAll('#editMedalModal .category-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                
                // Collapse all event lists
                document.querySelectorAll('#editMedalModal .events-list').forEach(list => {
                    const bsCollapse = new bootstrap.Collapse(list, { toggle: false });
                    bsCollapse.hide();
                });
                
                // Remove selected class from all event options
                document.querySelectorAll('.edit-event-option').forEach(opt => opt.classList.remove('selected'));
            }
            
            function selectEditEvent(eventId) {
                // Find the event option with the matching event ID
                const eventOption = document.querySelector(`.edit-event-option[data-event-id="${eventId}"]`);
                if (eventOption) {
                    // Find the parent category and expand it
                    const categoryGroup = eventOption.closest('.category-group');
                    const categoryBtn = categoryGroup.querySelector('.category-btn');
                    const eventsList = categoryGroup.querySelector('.events-list');
                    
                    if (categoryBtn && eventsList) {
                        categoryBtn.classList.add('active');
                        const bsCollapse = new bootstrap.Collapse(eventsList, { toggle: false });
                        bsCollapse.show();
                    }
                    
                    // Select the event option
                    eventOption.classList.add('selected');
                }
            }

            // --- Delete Medal Confirmation Logic ---
            const confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
            const deleteMedalIdInput = document.getElementById('deleteMedalId');

            document.querySelectorAll('.delete-medal-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const medalId = this.dataset.id;
                    deleteMedalIdInput.value = medalId; // Set the ID in the hidden input of the delete form
                    confirmationModal.show(); // Show the confirmation modal
                });
            });

            // --- Category and Event Selection Logic (Add Medal Form) ---
            const addEventIdInput = document.getElementById('addEventId');
            
            function updateMedalInfo(eventElement) {
                if (!eventElement || !eventElement.dataset.goldCount) {
                    eventMedalInfo.textContent = 'Select a category and then choose an event to see medal count information.';
                    return;
                }
                const goldCount = parseInt(eventElement.dataset.goldCount || '0', 10);
                const silverCount = parseInt(eventElement.dataset.silverCount || '0', 10);
                const bronzeCount = parseInt(eventElement.dataset.bronzeCount || '0', 10);
                const assigned = parseInt(eventElement.dataset.assignedCount || '0', 10);
                
                let infoText = `<strong>Medal Quantities:</strong> Gold (${goldCount}), Silver (${silverCount}), Bronze (${bronzeCount}).`;
                if (assigned > 0) {
                     infoText += ` | <span class="text-danger fw-bold">${assigned} medal assignments already exist. You must delete them before re-assigning all three.</span>`;
                }
                eventMedalInfo.innerHTML = infoText;
            }

            // Form validation for medal assignment
            document.getElementById('addMedalForm').addEventListener('submit', function(e) {
                const eventId = document.getElementById('addEventId').value;
                const goldTeamId = document.getElementById('goldTeamId').value;
                const silverTeamId = document.getElementById('silverTeamId').value;
                const bronzeTeamId = document.getElementById('bronzeTeamId').value;

                if (!eventId) {
                    e.preventDefault();
                    alert('Please select an event first.');
                    return;
                }
                
                const eventElement = document.querySelector(`.event-item[data-event-id="${eventId}"]`);
                const assignedCount = parseInt(eventElement.dataset.assignedCount || '0', 10);
                
                if (assignedCount > 0) {
                    e.preventDefault();
                    alert('Medals are already assigned for this event. Please delete existing assignments from the "Individual Entries" section before assigning new ones.');
                    return;
                }


                if (!goldTeamId && !silverTeamId && !bronzeTeamId) {
                    e.preventDefault();
                    alert('Please select at least one team for medal assignment.');
                    return;
                }
            });
        });
    </script>
</body>
</html>