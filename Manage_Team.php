<?php
session_start();

// --- Authentication Check for Admin Dashboard ---
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

require_once 'config.php'; // Ensures $conn is available

// --- Temporarily enable error reporting for debugging ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
// --- End temporary error reporting ---

// --- Persistent storage for teams (shared with Teams.php) ---
$DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$TEAMS_JSON = $DATA_DIR . DIRECTORY_SEPARATOR . 'teams.json';

if (!is_dir($DATA_DIR)) {
    @mkdir($DATA_DIR, 0755, true);
}

function load_teams_data($path)
{
    if (file_exists($path)) {
        $json = file_get_contents($path);
        $data = json_decode($json, true);
        if (is_array($data)) {
            return $data;
        }
    }
    // Fallback initial data - RESTORED sports_events
    $seed = [
    [
        'id' => 1,
        'name' => 'College of Technology and Engineering',
        'badge' => 'COTE',
        'logo' => 'images/COTE.png',
        'description' => 'The College of Technology and Engineering is a formidable contender, known for its strategic and analytical prowess in various sports.',
        'accentColor' => '#800000',
        'dean_name' => 'Dr. Maria Santos',
        'total_students' => 2500,
        'sports_events' => ['Volleyball', 'Basketball', 'Table Tennis'], // RESTORED
        'upcoming_matches' => [
            ['opponent' => 'CAS', 'date' => 'Oct 20', 'venue' => 'Gymnasium'],
            ['opponent' => 'COMED', 'date' => 'Oct 22', 'venue' => 'Court A'],
        ],
    ],
    [
        'id' => 2,
        'name' => 'College of Arts and Sciences',
        'badge' => 'CAS',
        'logo' => 'images/CASlogo.png',
        'description' => 'The College of Arts and Sciences teams often excel in sports that require a high degree of planning and coordination, demonstrating strong intellectual and physical abilities.',
        'accentColor' => '#FFFF00',
        'dean_name' => 'Engr. Robert Tan',
        'total_students' => 1800,
        'sports_events' => ['Chess', 'E-Sports', 'Table Tennis'], // RESTORED
        'upcoming_matches' => [
            ['opponent' => 'COTE', 'date' => 'Oct 20', 'venue' => 'Gymnasium'],
            ['opponent' => 'PIT - TC', 'date' => 'Oct 23', 'venue' => 'Auditorium'],
        ],
    ],
    [
        'id' => 3,
        'name' => 'College of Maritime Education',
        'badge' => 'COMED',
        'logo' => 'images/COMED.png',
        'description' => 'Known for their discipline and resilience, the COMED teams are a force to be reckoned with, showcasing great teamwork in every competition.',
        'accentColor' => '#008000',
        'dean_name' => 'Ms. Sofia Reyes',
        'total_students' => 1500,
        'sports_events' => ['Culinary Race', 'Swimming', 'Badminton'], // RESTORED
        'upcoming_matches' => [
            ['opponent' => 'CTE', 'date' => 'Oct 21', 'venue' => 'Main Pool'],
            ['opponent' => 'COTE', 'date' => 'Oct 22', 'venue' => 'Court A'],
        ],
    ],
    [
        'id' => 4,
        'name' => 'College Teachers Education',
        'badge' => 'CTE',
        'logo' => 'images/testlogoCTE.png',
        'description' => 'The CTE department is a strong contender with well-rounded athletes participating across all sports, always bringing energy and enthusiasm to every match.',
        'accentColor' => '#87CEEB',
        'dean_name' => 'Dr. Antonio Cruz',
        'total_students' => 2200,
        'sports_events' => ['Debate', 'Track and Field', 'Soccer'], // RESTORED
        'upcoming_matches' => [
            ['opponent' => 'PIT - TC', 'date' => 'Oct 24', 'venue' => 'Field'],
            ['opponent' => 'CAS', 'date' => 'Oct 25', 'venue' => 'Auditorium'],
        ],
    ],
    [
        'id' => 5,
        'name' => 'Palompon Institute of Technology Tabango Campus',
        'badge' => 'PIT - TC',
        'logo' => 'images/PIT.png',
        'description' => 'PIT - TC teams are known for their discipline and competitive drive, aiming for the top spots in every event.',
        'accentColor' => '#0000FF',
        'dean_name' => 'Dean Richard Lim',
        'total_students' => 2100,
        'sports_events' => ['Soccer', 'Stock Market Simulation', 'Volleyball'], // RESTORED
        'upcoming_matches' => [
            ['opponent' => 'COMED', 'date' => 'Oct 21', 'venue' => 'Main Pool'],
            ['opponent' => 'CAS', 'date' => 'Oct 23', 'venue' => 'Auditorium'],
        ],
    ],
];
    // Add default medals structure for consistency if missing (now handled by database)
    foreach ($seed as &$item) {
        $item['medals'] = ['gold' => 0, 'silver' => 0, 'bronze' => 0];
    }
    unset($item);
    
    return $seed;
}

function save_teams_data($path, $data)
{
    // Filter out temporary data (like calculated points/medals if they were present)
    $clean_data = array_map(function($t) {
        // Keep only the fields managed by this file
        return [
            'id' => $t['id'],
            'name' => $t['name'],
            'badge' => $t['badge'],
            'logo' => $t['logo'],
            'description' => $t['description'] ?? '',
            'accentColor' => $t['accentColor'] ?? '#2c3e50',
            'dean_name' => $t['dean_name'],
            'total_students' => $t['total_students'],
            'sports_events' => $t['sports_events'] ?? [], // RESTORED
            'upcoming_matches' => $t['upcoming_matches'] ?? [],
        ];
    }, $data);

    $json = json_encode(array_values($clean_data), JSON_PRETTY_PRINT);
    @file_put_contents($path, $json);
}

// Load teams (initialize file on first run)
$teams_data = load_teams_data($TEAMS_JSON);
if (!file_exists($TEAMS_JSON)) {
    save_teams_data($TEAMS_JSON, $teams_data);
}

// Helper function to fetch medals from DB and merge into team data
function fetch_medals_and_merge(&$teams_data, $conn) {
    // Since your `teams_data` uses name/badge/id from JSON, we need a mapping to the DB's `team_id`.
    // ASSUMPTION: The 'id' in JSON corresponds to the 'team_id' in the DB.
    $sql_db_medals = "
        SELECT 
            m.team_id,
            SUM(CASE WHEN m.medal_type = 'Gold' THEN COALESCE(m.medal_quantity, 0) ELSE 0 END) AS gold,
            SUM(CASE WHEN m.medal_type = 'Silver' THEN COALESCE(m.medal_quantity, 0) ELSE 0 END) AS silver,
            SUM(CASE WHEN m.medal_type = 'Bronze' THEN COALESCE(m.medal_quantity, 0) ELSE 0 END) AS bronze
        FROM 
            medals m
        GROUP BY 
            m.team_id
    ";
    
    $medal_results = $conn->query($sql_db_medals);
    $db_medals = [];
    if ($medal_results) {
        while ($row = $medal_results->fetch_assoc()) {
            $db_medals[$row['team_id']] = [
                'gold' => (int)$row['gold'],
                'silver' => (int)$row['silver'],
                'bronze' => (int)$row['bronze'],
            ];
        }
    }

    // Merge medal data into $teams_data
    foreach ($teams_data as $index => $team) {
        $team_id = $team['id']; 
        $teams_data[$index]['medals'] = $db_medals[$team_id] ?? ['gold' => 0, 'silver' => 0, 'bronze' => 0];
        // Calculate total students across all teams
        $teams_data[$index]['total_students'] = (int)($team['total_students'] ?? 0);
    }
}
fetch_medals_and_merge($teams_data, $conn);


// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dataChanged = false;
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_team':
                // Logic for team name, badge, dean, students, and photo uploads
                if (isset($_POST['team_id'])) {
                    $teamId = (int)$_POST['team_id'];
                    $updatedDeanUrl = '';
                    $updatedTeamLogoUrl = '';
                    $errorMsg = '';
                    
                    foreach ($teams_data as &$td) {
                        if ($td['id'] === $teamId) {
                            $td['name'] = trim($_POST['team_name'] ?? $td['name']);
                            $td['badge'] = trim($_POST['team_badge'] ?? $td['badge']);
                            $td['dean_name'] = trim($_POST['team_dean'] ?? $td['dean_name']);
                            if (isset($_POST['team_students'])) { $td['total_students'] = (int)$_POST['team_students']; }

                            // Handle team logo upload (Logic is simplified from original for brevity)
                            if (!empty($_FILES['team_logo']) && isset($_FILES['team_logo']['tmp_name']) && is_uploaded_file($_FILES['team_logo']['tmp_name'])) {
                                $tmp = $_FILES['team_logo']['tmp_name'];
                                $imgInfo = @getimagesize($tmp);
                                if ($imgInfo !== false) {
                                    $ext = image_type_to_extension($imgInfo[2], false);
                                    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'teams';
                                    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
                                    $safeBadge = preg_replace('/[^A-Za-z0-9_-]/', '_', $td['badge']);
                                    $dest = $dir . DIRECTORY_SEPARATOR . $safeBadge . '.' . $ext;
                                    foreach (glob($dir . DIRECTORY_SEPARATOR . $safeBadge . '.*') as $old) { @unlink($old); }
                                    if (@move_uploaded_file($tmp, $dest)) {
                                        $relative = 'uploads/teams/' . $safeBadge . '.' . $ext;
                                        $td['logo'] = $relative;
                                        $updatedTeamLogoUrl = $relative . '?v=' . time();
                                    } else {
                                        $errorMsg = $errorMsg ?: 'Upload failed while saving team logo.';
                                    }
                                } else {
                                    $errorMsg = $errorMsg ?: 'Unsupported team logo image type.';
                                }
                            }

                            // Handle dean photo upload (Logic is simplified from original for brevity)
                            if (!empty($_FILES['dean_photo']) && isset($_FILES['dean_photo']['tmp_name']) && is_uploaded_file($_FILES['dean_photo']['tmp_name'])) {
                                $tmp = $_FILES['dean_photo']['tmp_name'];
                                $imgInfo = @getimagesize($tmp);
                                if ($imgInfo !== false) {
                                    $ext = image_type_to_extension($imgInfo[2], false);
                                    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'deans';
                                    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
                                    $safeBadge = preg_replace('/[^A-Za-z0-9_-]/', '_', $td['badge']);
                                    $dest = $dir . DIRECTORY_SEPARATOR . $safeBadge . '.' . $ext;
                                    foreach (glob($dir . DIRECTORY_SEPARATOR . $safeBadge . '.*') as $old) { @unlink($old); }
                                    if (@move_uploaded_file($tmp, $dest)) {
                                        $updatedDeanUrl = 'uploads/deans/' . $safeBadge . '.' . $ext . '?v=' . time();
                                    } else {
                                        $errorMsg = 'Upload failed while saving file.';
                                    }
                                } else {
                                    $errorMsg = 'Unsupported image type.';
                                }
                            }
                            $dataChanged = true;
                            break;
                        }
                    }
                    unset($td);
                }
                $message = $errorMsg ? $errorMsg : "Team information updated successfully";
                $messageType = $errorMsg ? 'danger' : 'success';

                // AJAX Response for photo upload (kept logic as in original)
                $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                    || (isset($_POST['ajax']) && $_POST['ajax'] === '1');
                if ($isAjax) {
                    if ($dataChanged) { save_teams_data($TEAMS_JSON, $teams_data); }
                    header('Content-Type: application/json');
                    echo json_encode([
                        'status' => $errorMsg ? 'error' : 'ok',
                        'message' => $message,
                        'deanPhotoUrl' => $updatedDeanUrl,
                        'teamLogoUrl' => $updatedTeamLogoUrl,
                    ]);
                    exit();
                }
                break;
            // --- REMOVED 'update_sports' case (to keep UI feature removed) ---
            case 'add_match':
                // Logic for adding a new match
                if (!isset($_POST['team_id'])) break;
                $teamId = (int)$_POST['team_id'];
                $newMatch = [
                    'opponent' => trim($_POST['match_opponent'] ?? ''),
                    'date' => trim($_POST['match_date'] ?? ''),
                    'venue' => trim($_POST['match_venue'] ?? ''),
                ];
                foreach ($teams_data as &$t) {
                    if ($t['id'] === $teamId) {
                        if (!isset($t['upcoming_matches']) || !is_array($t['upcoming_matches'])) $t['upcoming_matches'] = [];
                        $t['upcoming_matches'][] = $newMatch;
                        $dataChanged = true;
                        break;
                    }
                }
                unset($t);
                $message = 'Upcoming match added';
                $messageType = 'success';
                break;
            case 'delete_match':
                // Logic for deleting a match
                if (!isset($_POST['team_id'], $_POST['match_index'])) break;
                $teamId = (int)$_POST['team_id'];
                $idx = (int)$_POST['match_index'];
                foreach ($teams_data as &$t) {
                    if ($t['id'] === $teamId && isset($t['upcoming_matches'][$idx])) {
                        array_splice($t['upcoming_matches'], $idx, 1);
                        $dataChanged = true;
                        break;
                    }
                }
                unset($t);
                $message = 'Upcoming match removed';
                $messageType = 'success';
                break;
            case 'delete_dean_photo':
                // Logic for deleting dean photo file
                if (isset($_POST['team_id'])) {
                    $teamId = (int)$_POST['team_id'];
                    $deleted = false;
                    foreach ($teams_data as $td) {
                        if ($td['id'] === $teamId) {
                            $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'deans';
                            $safeBadge = preg_replace('/[^A-Za-z0-9_-]/', '_', $td['badge']);
                            foreach (glob($dir . DIRECTORY_SEPARATOR . $safeBadge . '.*') as $old) {
                                if (@unlink($old)) { $deleted = true; }
                            }
                            break;
                        }
                    }
                    $message = $deleted ? 'Dean photo removed' : 'No dean photo found to remove';
                    $messageType = $deleted ? 'success' : 'warning';
                }
                break;
            // --- REMOVED 'add_team' case ---
            case 'delete_team':
                // Logic for deleting a team
                $teamId = isset($_POST['team_id']) ? (int)$_POST['team_id'] : null;
                $badge = trim($_POST['badge'] ?? '');
                $before = count($teams_data);
                $teams_data = array_values(array_filter($teams_data, function($t) use ($teamId, $badge) {
                    if ($teamId !== null) { return $t['id'] !== $teamId; }
                    if ($badge !== '') { return $t['badge'] !== $badge; }
                    return true;
                }));
                if (count($teams_data) < $before) {
                    $dataChanged = true;
                    $message = 'Team deleted successfully';
                    $messageType = 'success';
                } else {
                    $message = 'Team not found';
                    $messageType = 'warning';
                }
                break;
        }
    }
    if ($dataChanged) {
        save_teams_data($TEAMS_JSON, $teams_data);
    }
    // Perform a redirect to prevent form resubmission unless it was an AJAX call
    if (!isset($_POST['ajax']) || $_POST['ajax'] !== '1') {
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $messageType;
        header('Location: Manage_Team.php');
        exit();
    }
}

// --- Fetch all events/sports from DB (for display in Sports Participation section)
function get_all_events_from_db($conn) {
    $sql = "SELECT e.event_name, s.sport_name, s.category 
            FROM events e 
            JOIN sports s ON e.sport_id = s.sport_id 
            ORDER BY s.sport_name, e.event_name";
    
    $result = $conn->query($sql);
    
    $events = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $events[] = [
                'sport' => $row['sport_name'],
                'event' => $row['event_name'],
                'category' => $row['category']
            ];
        }
    }
    
    return $events;
}
$allEvents = get_all_events_from_db($conn);

// --- Calculate Global Statistics (Medals) ---

// NEW: Query for global medal totals to match Tournament_Manager_page.php
// This ensures totals are based on the database 'teams' table, not the JSON file.
$totalGold = 0;
$totalSilver = 0;
$totalBronze = 0;

$sql_totals = "
    SELECT 
        SUM(CASE WHEN m.medal_type = 'Gold' THEN COALESCE(m.medal_quantity, 0) ELSE 0 END) AS total_gold,
        SUM(CASE WHEN m.medal_type = 'Silver' THEN COALESCE(m.medal_quantity, 0) ELSE 0 END) AS total_silver,
        SUM(CASE WHEN m.medal_type = 'Bronze' THEN COALESCE(m.medal_quantity, 0) ELSE 0 END) AS total_bronze
    FROM 
        teams t -- Base the count on the 'teams' table
    LEFT JOIN 
        medals m ON t.team_id = m.team_id
";

$totals_result = $conn->query($sql_totals);
if ($totals_result && $row = $totals_result->fetch_assoc()) {
    $totalGold = (int)$row['total_gold'];
    $totalSilver = (int)$row['total_silver'];
    $totalBronze = (int)$row['total_bronze'];
}

// We still get these totals from the JSON, as they are managed here
$totalTeams = count($teams_data);
$totalStudentsOverall = array_sum(array_column($teams_data, 'total_students'));

$current_page = basename($_SERVER['PHP_SELF']);

// Helper to resolve dean photo URL for a given badge
function get_dean_photo_url($badge) {
    $safeBadge = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$badge);
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'deans';
    $relBase = 'uploads/deans/';
    if (is_dir($dir)) {
        foreach (['jpg','jpeg','png','gif','webp'] as $ext) {
            $path = $dir . DIRECTORY_SEPARATOR . $safeBadge . '.' . $ext;
            if (file_exists($path)) { return $relBase . $safeBadge . '.' . $ext; }
        }
        $matches = glob($dir . DIRECTORY_SEPARATOR . $safeBadge . '.*');
        if ($matches && file_exists($matches[0])) {
            return $relBase . basename($matches[0]);
        }
    }
    return '';
}

// Build mapping badge -> dean photo URL
$badgeToDeanPhoto = [];
foreach ($teams_data as $t) {
    $url = get_dean_photo_url($t['badge']);
    if ($url) { $badgeToDeanPhoto[$t['badge']] = $url; }
}

// Close DB connection at the very end
$conn->close();

// --- Retrieve and Clear Messages from Session (POST-Redirect-GET pattern) ---
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['message_type'];
    unset($_SESSION['message']); 
    unset($_SESSION['message_type']); 
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Team Dashboard - PIT Sports Tallying</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        /* --- Styles for unified header design (Copied from Manage_Event.php for consistency) --- */
        :root {
            --primary-green: #4CAF50;
            --primary-dark: #2E7D32;
            --accent-gold: #FFD700;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(to bottom,rgba(245, 16, 16, 0.32));
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

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

        /* Main Layout Container (Copied from Manage_Event.php) */
        .main-container {
            display: flex;
            padding-top: 76px; 
            min-height: 100vh;
            flex-grow: 1; /* Allows main content to fill space */
            width: 100%;
        }
        
        /* Sticky Sidebar Styles (Copied from Manage_Event.php) */
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
        
        .section-header-title {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 700;
            color: #2c3e50;
        }

        .table thead th {
            background-color: #4ca728;
            color: white;
            font-weight: 600;
            border: none;
            padding: 15px;
        }

        .team-logo-table {
            width: 60px;
            height: 60px;
            object-fit: cover;
        }
        .dean-avatar { width: 80px; height: 80px; object-fit: cover; border-radius: 50%; }

        /* NEW STATS CARD STYLING */
        .stats-grid .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            padding: 20px;
            height: 100%;
        }

        .stats-grid .card-title {
            font-size: 1rem;
            font-weight: 600;
            color: #6c757d;
        }

        .stats-grid .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
            line-height: 1.1;
        }

        .stats-grid .gold-value { color: gold; }
        .stats-grid .silver-value { color: silver; }
        .stats-grid .bronze-value { color: #cd7f32; }
        .stats-grid .students-value { color: #3498db; }
        .stats-grid .teams-value { color: #27ae60; }
        .stats-grid .total-medals-value { color: #2c3e50; }

        /* Responsive styles from Manage_Event.php are omitted for brevity but should be included in a real project */
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

    <button class="sidebar-toggle" id="sidebarToggle">
        <i class="fas fa-bars"></i>
    </button>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-container">
        <aside class="sticky-sidebar" id="sidebar">
            <div class="sidebar-header">
                <h4>Team Manager</h4>
                <p>Welcome, <?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></p>
            </div>
            <ul class="sidebar-nav">
                <li>
                    <a href="#teams-section" class="nav-link active" data-section="teams-section">
                        <i class="fas fa-users"></i>
                        <span>Team Roster</span>
                    </a>
                </li>
                <li>
                    <a href="#management-section" class="nav-link" data-section="management-section">
                        <i class="fas fa-tools"></i>
                        <span>Team Management</span>
                    </a>
                </li>
                <li>
                    <a href="#sports-section" class="nav-link" data-section="sports-section">
                        <i class="fas fa-futbol"></i>
                        <span>Sports/Events List</span>
                    </a>
                </li>
            </ul>
        </aside>

        <div class="content-area">
            
            <h1 class="section-header-title mb-4">Team Administration Dashboard</h1>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                    <?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row g-4 mb-5 stats-grid">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-title"><i class="fas fa-users me-1"></i> Total Teams</div>
                        <div class="stat-value teams-value"><?= $totalTeams ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-title"><i class="fas fa-trophy me-1"></i> Total Gold</div>
                        <div class="stat-value gold-value"><?= $totalGold ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-title"><i class="fas fa-medal me-1"></i> Total Silver</div>
                        <div class="stat-value silver-value"><?= $totalSilver ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-title"><i class="fas fa-award me-1"></i> Total Bronze</div>
                        <div class="stat-value bronze-value"><?= $totalBronze ?></div>
                    </div>
                </div>
            </div>

            <section id="teams-section" class="content-section">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3 class="section-header-title"><i class="fas fa-users me-2"></i> Team Roster</h3>
                    </div>
                <p class="text-muted">Overview of all participating teams and their essential details. Use the actions column for management.</p>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Logo</th>
                                <th>Name / Badge</th>
                                <th>Students</th>
                                <th>Dean</th>
                                <th>Medals</th>
                                <th style="width: 15%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teams_data as $team): ?>
                                <tr>
                                    <td><img src="<?= htmlspecialchars($team['logo']) ?>" alt="<?= htmlspecialchars($team['badge']) ?>" class="team-logo-table"></td>
                                    <td>
                                        <strong><?= htmlspecialchars($team['name']) ?></strong> 
                                        <br>
                                        <span class="badge bg-secondary"><?= htmlspecialchars($team['badge']) ?></span>
                                    </td>
                                    <td><?= number_format($team['total_students'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars($team['dean_name'] ?? 'N/A') ?></td>
                                    <td>
                                        <span class="badge bg-warning text-dark">🥇<?= $team['medals']['gold'] ?? 0 ?></span>
                                        <span class="badge bg-secondary">🥈<?= $team['medals']['silver'] ?? 0 ?></span>
                                        <span class="badge bg-dark">🥉<?= $team['medals']['bronze'] ?? 0 ?></span>
                                    </td>
                                    <td class="action-buttons">
                                        <button class="btn btn-sm btn-outline-primary" 
                                                onclick="editTeam(<?= $team['id'] ?>, '<?= htmlspecialchars($team['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($team['badge'], ENT_QUOTES) ?>', '<?= htmlspecialchars($team['dean_name'], ENT_QUOTES) ?>', <?= (int)($team['total_students'] ?? 0) ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" 
                                                onclick="deleteTeam(<?= $team['id'] ?>, '<?= htmlspecialchars($team['name'], ENT_QUOTES) ?>')">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section id="management-section" class="content-section">
                <h3 class="section-header-title mb-4"><i class="fas fa-tools me-2"></i> Team Specific Management</h3>
                <p class="text-muted">Use these tools to manage the event participation and schedule for specific teams.</p>
                
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <i class="fas fa-calendar-alt me-2"></i> Upcoming Matches
                            </div>
                            <div class="card-body">
                                <p class="card-text text-muted">Add, remove, or edit matches for a team's schedule.</p>
                                <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#selectTeamMatchesModal">
                                    Manage Matches
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <i class="fas fa-graduation-cap me-2"></i> Dean Photo Management
                            </div>
                            <div class="card-body">
                                <p class="card-text text-muted">Quickly manage Dean photos (upload/remove).</p>
                                <button class="btn btn-info w-100" data-bs-toggle="modal" data-bs-target="#selectTeamDeanPhotoModal">
                                    Manage Dean Photos
                                </button>
                            </div>
                        </div>
                    </div>
                    </div>
            </section>

            <section id="sports-section" class="content-section">
                <h3 class="section-header-title mb-4"><i class="fas fa-list-alt me-2"></i> Events participation List</h3>
                <p class="text-muted">A consolidated list of all available sports and events from the database (for reference).</p>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Sport Name</th>
                                <th>Event Name</th>
                                <th>Category</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($allEvents)): ?>
                                <?php foreach ($allEvents as $evt): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($evt['sport']) ?></strong></td>
                                        <td><?= htmlspecialchars($evt['event']) ?></td>
                                        <td>
                                            <?php if ($evt['category']): ?>
                                                <span class="badge bg-secondary"><?= htmlspecialchars($evt['category']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted">No sports events available</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

    <div class="modal fade" id="editTeamModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Team</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="editTeamForm" method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_team">
                        <input type="hidden" name="team_id" id="editTeamId">
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="editTeamName" name="team_name" required>
                            <label for="editTeamName">Team Name</label>
                        </div>
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="editTeamBadge" name="team_badge" required>
                            <label for="editTeamBadge">Badge</label>
                        </div>
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="editTeamDean" name="team_dean" required>
                            <label for="editTeamDean">Dean</label>
                        </div>
                        <div class="form-floating mb-3">
                            <input type="number" class="form-control" id="editTeamStudents" name="team_students" min="0" required>
                            <label for="editTeamStudents">Total Students</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="editTeamLogo">Team Logo (optional)</label>
                            <input type="file" class="form-control" id="editTeamLogo" name="team_logo" accept="image/*">
                            <div id="teamLogoPreviewWrap" class="d-flex align-items-center gap-3 mt-2" style="display:none;">
                                <img id="teamLogoPreview" src="" alt="Team Logo" class="border" style="width:80px;height:80px;object-fit:contain;border-radius:8px;">
                            </div>
                            <div id="teamLogoUploadFeedback" class="mt-2"></div>
                        </div>
                        <div class="mb-3 d-none">
                            <input type="file" name="dean_photo" accept="image/*" id="editDeanPhoto">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="selectTeamMatchesModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-calendar-alt me-2"></i>Select Team to Manage Matches</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <select class="form-select" id="selectTeamMatch">
                        <option value="">-- Choose Team --</option>
                        <?php foreach ($teams_data as $team): ?>
                        <option value="<?= $team['id'] ?>" data-matches="<?= htmlspecialchars(json_encode($team['upcoming_matches'] ?? []), ENT_QUOTES) ?>" data-name="<?= htmlspecialchars($team['name'], ENT_QUOTES) ?>"><?= htmlspecialchars($team['name']) ?> (<?= htmlspecialchars($team['badge']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="goManageMatches" disabled>Go to Management</button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="selectTeamDeanPhotoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-graduation-cap me-2"></i>Select Team for Dean Photo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <select class="form-select" id="selectTeamDeanPhoto">
                        <option value="">-- Choose Team --</option>
                        <?php foreach ($teams_data as $team): ?>
                        <option value="<?= $team['id'] ?>" data-badge="<?= htmlspecialchars($team['badge'], ENT_QUOTES) ?>" data-name="<?= htmlspecialchars($team['name'], ENT_QUOTES) ?>"><?= htmlspecialchars($team['name']) ?> (<?= htmlspecialchars($team['badge']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-info" id="goManageDeanPhoto" disabled>Manage Photo</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="manageMatchesModal" tabindex="-1">
        <div class="modal-dialog modal-lg"><div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-calendar me-2"></i>Upcoming Matches – <span id="matchesTeamName"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="addMatchForm" method="POST" class="mb-3">
                    <input type="hidden" name="action" value="add_match">
                    <input type="hidden" name="team_id" id="matchesTeamId">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <div class="form-floating">
                                <input type="text" class="form-control" name="match_opponent" required>
                                <label>Opponent</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating">
                                <input type="text" class="form-control" name="match_date" placeholder="Oct 25" required>
                                <label>Date</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating">
                                <input type="text" class="form-control" name="match_venue" required>
                                <label>Venue</label>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 d-flex justify-content-end">
                        <button type="submit" class="btn btn-info"><i class="fas fa-plus me-2"></i>Add Match</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Opponent</th><th>Date</th><th>Venue</th><th style="width:1%"></th>
                            </tr>
                        </thead>
                        <tbody id="matchesTableBody">
                        </tbody>
                    </table>
                </div>

                <form id="deleteMatchForm" method="POST" class="d-none">
                    <input type="hidden" name="action" value="delete_match">
                    <input type="hidden" name="team_id" id="deleteMatchTeamId">
                    <input type="hidden" name="match_index" id="deleteMatchIndex">
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div></div>
    </div>
    
    <div class="modal fade" id="manageDeanPhotoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-graduation-cap me-2"></i>Manage Dean Photo – <span id="deanPhotoTeamName"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Current Photo:</p>
                    <div id="currentDeanPhotoWrap" class="d-flex align-items-center gap-3 mb-3">
                        <img id="currentDeanPhoto" src="https://placehold.co/80x80/6c757d/ffffff?text=Dean" alt="Dean Photo" class="dean-avatar border">
                        <button type="button" class="btn btn-sm btn-outline-danger" id="removeDeanPhotoBtnModal">
                            <i class="fas fa-times me-1"></i>Remove Photo
                        </button>
                    </div>

                    <p class="text-muted">Upload New Photo:</p>
                    <form id="deanPhotoUploadForm" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_team">
                        <input type="hidden" name="team_id" id="deanPhotoTeamIdUpload">
                        <input type="hidden" name="team_name" id="deanPhotoTeamNameInput">
                        <input type="hidden" name="team_badge" id="deanPhotoTeamBadgeInput">
                        <input type="file" class="form-control" id="deanPhotoInput" name="dean_photo" accept="image/*" required>
                        <div id="deanUploadFeedbackModal" class="mt-2"></div>
                        <div class="mt-3 d-flex justify-content-end">
                            <button type="submit" class="btn btn-info"><i class="fas fa-upload me-2"></i>Upload Photo</button>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="deleteTeamConfirmationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-trash-alt me-2"></i>Confirm Deletion</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to permanently delete team **<span id="deleteTeamNameDisplay" class="fw-bold"></span>**?</p>
                    <p class="text-danger">This action cannot be undone and will affect related data.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form id="deleteTeamForm" method="POST" class="d-inline">
                        <input type="hidden" name="action" value="delete_team">
                        <input type="hidden" name="team_id" id="deleteTeamIdInput">
                        <input type="hidden" name="badge" id="deleteTeamBadgeInput">
                        <button type="submit" class="btn btn-danger">Delete Team</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <div aria-live="polite" aria-atomic="true" class="position-relative">
        <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100;">
            </div>
    </div>

    <footer class="bg-dark text-white py-3 mt-auto w-100">
        <div class="container text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
            <small>Developed by Tsunayoshi Sawada</small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const teamsData = <?= json_encode($teams_data) ?>;
        const badgeToDeanPhoto = <?= json_encode($badgeToDeanPhoto) ?>;
        
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
            const bgColor = type === 'success' ? 'bg-success' : (type === 'danger' ? 'bg-danger' : (type === 'warning' ? 'bg-warning' : 'bg-info'));
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
        
        // ============================================
        // INITIAL MESSAGE DISPLAY (POST-Redirect-GET)
        // ============================================
        <?php if (!empty($message)): ?>
            showToast('<?= addslashes($message) ?>', '<?= $messageType ?>');
        <?php endif; ?>

        // ============================================
        // SIDEBAR & SCROLL LOGIC
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            const navLinks = document.querySelectorAll('.sidebar-nav .nav-link');
            
            // Toggle sidebar for mobile
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

            // Sidebar navigation and scrolling
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
        });

        // ============================================
        // CORE ACTION HANDLERS (GLOBAL FUNCTIONS)
        // ============================================
        
        // EDIT TEAM (Called from Roster table)
        window.editTeam = function(teamId, name, badge, deanName, totalStudents) {
            document.getElementById('editTeamId').value = teamId;
            document.getElementById('editTeamName').value = name;
            document.getElementById('editTeamBadge').value = badge;
            document.getElementById('editTeamDean').value = deanName;
            document.getElementById('editTeamStudents').value = totalStudents;
            
            // Get current logo URL from the table row
            const teamRowImg = document.querySelector(`img[alt="${CSS.escape(badge)}"]`);
            const currentLogoUrl = teamRowImg ? teamRowImg.src : '';
            
            const logoPreviewWrap = document.getElementById('teamLogoPreviewWrap');
            const logoPreviewImg = document.getElementById('teamLogoPreview');
            
            if (currentLogoUrl) {
                logoPreviewImg.src = currentLogoUrl;
                logoPreviewWrap.style.display = 'flex';
            } else {
                logoPreviewWrap.style.display = 'none';
                logoPreviewImg.src = '';
            }

            const modal = new bootstrap.Modal(document.getElementById('editTeamModal'));
            modal.show();
        }
        
        // DELETE TEAM (Called from Roster table)
        window.deleteTeam = function(teamId, teamName) {
            document.getElementById('deleteTeamIdInput').value = teamId;
            document.getElementById('deleteTeamNameDisplay').textContent = teamName;
            const team = teamsData.find(t => t.id === teamId);
            document.getElementById('deleteTeamBadgeInput').value = team ? team.badge : '';

            const modal = new bootstrap.Modal(document.getElementById('deleteTeamConfirmationModal'));
            modal.show();
        }

        // ============================================
        // SELECTOR MODAL HANDLERS
        // ============================================
        
        // Generic function to set up selector logic
        function setupSelector(selectId, buttonId, callback) {
            const select = document.getElementById(selectId);
            const button = document.getElementById(buttonId);
            
            if (!select || !button) return;

            select.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const teamId = selectedOption.value;
                
                button.disabled = !teamId;
            });
            
            button.addEventListener('click', function() {
                const selectedOption = select.options[select.selectedIndex];
                const teamId = selectedOption.value;
                
                if (teamId) {
                    const teamData = {
                        id: parseInt(teamId),
                        name: selectedOption.getAttribute('data-name'),
                        badge: selectedOption.getAttribute('data-badge')
                    };
                    // Pass specific data attributes to the callback
                    callback(teamData, selectedOption.dataset);
                }
            });
        }
        
        // 1. Manage Matches Selector
        setupSelector('selectTeamMatch', 'goManageMatches', function(team, data) {
            const matchesJson = JSON.parse(data.matches || '[]');
            bootstrap.Modal.getInstance(document.getElementById('selectTeamMatchesModal')).hide();
            
            // Open the actual management modal
            manageMatches(team.id, matchesJson, team.name);
        });

        // 2. Manage Sports Selector (REMOVED)
        
        // 3. Manage Dean Photo Selector
        setupSelector('selectTeamDeanPhoto', 'goManageDeanPhoto', function(team, data) {
            const badge = data.badge;
            const photoUrl = badgeToDeanPhoto[badge] || 'https://placehold.co/80x80/6c757d/ffffff?text=Dean';
            
            bootstrap.Modal.getInstance(document.getElementById('selectTeamDeanPhotoModal')).hide();
            
            // Open the actual management modal
            manageDeanPhoto(team.id, team.name, badge, photoUrl);
        });
        
        // 4. Delete Team Selector (REMOVED)
        
        // ============================================
        // DEDICATED MANAGEMENT MODAL HANDLERS
        // ============================================
        
        // Manage Sports (REMOVED)

        // Manage Matches (Called from selector)
        window.manageMatches = function(teamId, matchesJson, teamName) {
            document.getElementById('matchesTeamId').value = teamId;
            document.getElementById('matchesTeamName').textContent = teamName;
            const tbody = document.getElementById('matchesTableBody');
            tbody.innerHTML = '';
            
            // Find the team to get the original badge for delete match logic
            const team = teamsData.find(t => t.id === teamId);
            
            matchesJson.forEach((m, i) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="fw-bold">${m.opponent ?? ''}</td>
                    <td>${m.date ?? ''}</td>
                    <td>${m.venue ?? ''}</td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteMatch(${teamId}, ${i})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>`;
                tbody.appendChild(tr);
            });
            new bootstrap.Modal(document.getElementById('manageMatchesModal')).show();
        };

        // Manage Dean Photo (Called from selector)
        window.manageDeanPhoto = function(teamId, teamName, badge, currentPhotoUrl) {
            document.getElementById('deanPhotoTeamName').textContent = teamName;
            document.getElementById('deanPhotoTeamIdUpload').value = teamId;
            document.getElementById('deanPhotoTeamNameInput').value = teamName;
            document.getElementById('deanPhotoTeamBadgeInput').value = badge;
            
            const photoImg = document.getElementById('currentDeanPhoto');
            const removeBtn = document.getElementById('removeDeanPhotoBtnModal');
            
            photoImg.src = currentPhotoUrl;
            
            removeBtn.onclick = function() {
                if (!confirm('Are you sure you want to remove the Dean photo?')) return;
                
                // Create a dynamic form to submit the deletion request
                const deletePhotoForm = document.createElement('form');
                deletePhotoForm.method = 'POST';
                deletePhotoForm.style.display = 'none';
                deletePhotoForm.innerHTML = `
                    <input type="hidden" name="action" value="delete_dean_photo">
                    <input type="hidden" name="team_id" value="${teamId}">
                `;
                document.body.appendChild(deletePhotoForm);
                deletePhotoForm.submit();
            };
            
            // AJAX submission for new Dean Photo upload
            const uploadForm = document.getElementById('deanPhotoUploadForm');
            uploadForm.onsubmit = function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('ajax', '1');
                
                fetch('', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(res => {
                        if (res.status === 'ok') {
                            showToast('Dean photo uploaded successfully.', 'success');
                            // Update photo preview immediately
                            document.getElementById('currentDeanPhoto').src = res.deanPhotoUrl;
                            // Re-fetch current data or simple reload for clean state
                            setTimeout(() => location.reload(), 500); 
                        } else {
                            showToast(res.message || 'Upload failed.', 'danger');
                        }
                    })
                    .catch(() => showToast('Network error during photo upload.', 'danger'));
            };
            
            new bootstrap.Modal(document.getElementById('manageDeanPhotoModal')).show();
        };

        // Delete Match (Called from manageMatchesModal)
        window.deleteMatch = function(teamId, idx) {
            if (!confirm('Remove this match?')) return;
            document.getElementById('deleteMatchTeamId').value = teamId;
            document.getElementById('deleteMatchIndex').value = idx;
            
            const deleteMatchForm = document.getElementById('deleteMatchForm');
            // This form is now submitted directly, which will trigger the POST-Redirect-GET pattern
            deleteMatchForm.submit();
        };


        // Final submit handler for Edit Team form
        document.getElementById('editTeamForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('ajax', '1'); // Ensure it's treated as AJAX
            
            // Send team and logo/dean data via AJAX for cleaner UX
            fetch('', { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'ok') {
                        showToast(res.message, 'success');
                        // Hide modal and refresh the entire page to reflect all changes
                        bootstrap.Modal.getInstance(document.getElementById('editTeamModal')).hide();
                        setTimeout(() => location.reload(), 500);
                    } else {
                        showToast(res.message, 'danger');
                    }
                })
                .catch(() => showToast('Network error while saving team details.', 'danger'));
        });

    </script>
</body>
</html>