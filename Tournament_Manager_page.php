<?php
session_start();

// --- Temporarily enable error reporting for debugging ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
// --- End temporary error reporting ---

require_once 'config.php'; // Make sure this path is correct

// PHP variables for header/footer consistency
$current_page = basename($_SERVER['PHP_SELF']);
$name = isset($_SESSION['username']) ? $_SESSION['username'] : 'User'; // User name for display

// Fetch medal standings for the selected sport (or overall if no sport is selected)
$medal_tally = [];

// CORRECTED SQL QUERY: Summing medals from 'medal_type' column
$sql = "SELECT t.team_id,
                t.team_name,
                t.college,
                SUM(CASE WHEN m.medal_type = 'Gold' THEN m.medal_quantity ELSE 0 END) AS gold,
                SUM(CASE WHEN m.medal_type = 'Silver' THEN m.medal_quantity ELSE 0 END) AS silver,
                SUM(CASE WHEN m.medal_type = 'Bronze' THEN m.medal_quantity ELSE 0 END) AS bronze,
                SUM(m.medal_quantity) AS total_medals_count -- Count total individual medals
        FROM teams t
        LEFT JOIN medals m ON t.team_id = m.team_id
        GROUP BY t.team_id, t.team_name, t.college
        ORDER BY gold DESC, silver DESC, bronze DESC, t.team_name ASC";

// For the landing page, we usually want overall standings, so we don't bind a specific sport.
$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Calculate total from gold, silver, bronze for display
            $row['total'] = $row['gold'] + $row['silver'] + $row['bronze'];
            $medal_tally[] = $row;
        }
    }
    $stmt->close();
} else {
    error_log("Error preparing medal tally statement: " . $conn->error);
}

// Get latest updated_at value from the medals table
$lastUpdated = null;
$sql_last_updated = "SELECT MAX(assigned_at) AS last_updated FROM medals";
$result_last_updated = $conn->query($sql_last_updated);
if ($result_last_updated && $row_last_updated = $result_last_updated->fetch_assoc()) {
    $lastUpdated = $row_last_updated['last_updated'];
}

$formattedTime = $lastUpdated ? date("m/d/Y \a\\t h:i A", strtotime($lastUpdated)) : 'N/A';

// --- College Logo Mapping ---
// Define the mapping from full college name (as in DB) to the short code (as used in $college_logos keys)
$college_name_to_code_map = [
    'College of Technology and Engineering' => 'COTE',
    'College of Arts and Science'          => 'CAS',
    'College of Maritime Education'         => 'COMED',
    'College of Teachers Education'            => 'CTE', // Note: Check if it's 'College Teachers Education' or 'College of Teachers Education' in your DB
    'PIT-Tabango Campus'                    => 'PIT-TC',
    // Add any other full college names from your database that need mapping
    'N/A'                                   => 'default' // Handle N/A case if it's still in your DB
];

// Define the college logo filenames (using short codes as keys)
$college_logos = [
    'COTE'    => 'COTE.png',
    'CAS'     => 'CASlogo.png',
    'COMED'   => 'COMED.png',
    'CTE'     => 'CTE.png',
    'PIT-TC' => 'PIT.png',
    'default' => 'default.png' // Fallback image if no match
];


// --- REFACTORED ---
// Removed the static `$rankings` array.
// The "Overall Standings" section will now be dynamically generated
// from the `$medal_tally` array, which is sorted by the SQL query.


/**
 * Helper that returns style classes & labels based on rank
 * This is still used to apply the correct borders, backgrounds, and labels.
 */
function rankMeta(int $rank): array {
    switch ($rank) {
        case 1:
            return ['maroon-border', 'maroon-bg', 'fas fa-trophy', 'Champion'];
        case 2:
            return ['yellow-border', 'yellow-bg', 'fas fa-medal', '1st Runner-up'];
        case 3:
            return ['green-border', 'green-bg', 'fas fa-award', '2nd Runner-up'];
        case 4:
            return ['skyblue-border', 'skyblue-bg', 'fas fa-award', '3rd Runner-up'];
        case 5:
            return ['blue-border', 'blue-bg', 'fas fa-award', '4th Runner-up'];
        default:
            // Handle ranks beyond 5, if any
            return ['default-border', 'default-bg', 'fas fa-star', ($rank) . 'th Place'];
    }
}

// Close database connection at the very end of the script
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PIT Sports Tallying - Live Medal Standings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg,rgba(245, 16, 16, 0.32));
            position: relative;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            color: var(--text-dark);
            line-height: 1.6;
        }

        /* Confetti Foreground - Below Navbar */
        body::before {
            content: '';
            position: fixed;
            top: 80px; /* Start below the navbar */
            left: 0;
            width: 100%;
            height: calc(100% - 80px); /* Adjust height to account for top offset */
            background-image: url('confettitest.gif');
            background-size: 40%; /* Smaller size to reduce blur */
            background-position: center top;
            background-repeat: repeat;
            opacity: 0; /* --- MODIFIED: Hide by default --- */
            z-index: 9998;
            pointer-events: none;
            transition: opacity 0.5s ease-out; /* Optional: Adds a nice fade-out */
        }

        /* --- NEW: This class will be added by JavaScript to show the confetti --- */
        body.show-confetti::before {
            opacity: 1;
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

        /* Main Content Wrapper */
        .main-content {
            flex: 1 0 auto;
            position: relative;
            z-index: 1;
            padding-top: 100px;
        }

        /* Live Standings Section */
        .live-standings-section {
            background: var(--bg-white);
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            padding: 2.5rem;
            margin-bottom: 2.5rem;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }

        .live-standings-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-green), var(--accent-gold), var(--accent-silver));
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .section-header img {
            height: 90px;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.1));
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        .section-header h1 {
            font-family: 'Poppins', sans-serif;
            font-weight: 800;
            font-size: 2.5rem;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
        }

        /* Reverts the last-updated badge to its original centered appearance, 
        while relying on the new HTML/Flexbox for overall placement */
        .last-updated {
            color: var(--text-muted);
            font-size: 0.9rem;
            font-weight: 500;
            padding: 0.75rem 1.5rem; /* Increased padding */
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 50px;
            display: inline-flex; 
            align-items: center;
            margin: 0;
        }

        /* Ensure the badge is visible and styled */
        #refresh-timer {
            font-size: 0.8rem;
            padding: 0.4rem 0.8rem;
        }

        /* Enhanced Table Design */
        .standings-table {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }

        .standings-table thead th {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-dark)) !important;
            color: white !important;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 1.25rem 1rem;
            border: none !important;
            font-size: 0.9rem;
        }

        .standings-table tbody tr {
            transition: var(--transition);
            background: white;
        }

        .standings-table tbody tr:hover {
            background: linear-gradient(90deg, #f8f9fa, #ffffff);
            transform: scale(1.01);
            box-shadow: var(--shadow-sm);
        }

        .standings-table tbody td {
            padding: 1.25rem 1rem;
            vertical-align: middle;
            border-bottom: 1px solid #f0f0f0;
            font-weight: 500;
        }

        .standings-table tbody tr:last-child td {
            border-bottom: none;
        }

        .college-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .college-logo-table {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid #f0f0f0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: var(--transition);
        }

        .standings-table tbody tr:hover .college-logo-table {
            transform: scale(1.1) rotate(5deg);
            border-color: var(--primary-green);
        }

        .rank-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-dark));
            color: white;
            font-weight: 700;
            font-size: 1.1rem;
            box-shadow: var(--shadow-sm);
        }

        .rank-badge.top-3 {
            background: linear-gradient(135deg, #FFD700, #FFA500);
        }

        .medal-count-table {
            font-size: 1.2rem;
            font-weight: 700;
        }

        /* Print Button Enhancement */
        .print-btn {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-dark));
            border: none;
            padding: 0.9rem 2.5rem;
            font-size: 1rem;
            font-weight: 700;
            border-radius: 50px;
            color: white;
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
            transition: var(--transition);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .print-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(76, 175, 80, 0.4);
        }

        /* Overall Standings Card */
        .overall-card {
            background: var(--bg-white);
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            padding: 2.5rem;
            position: relative;
            overflow: hidden;
        }

        .overall-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #FFD700, #FFA500, #FF6347);
        }

        .overall-card h1 {
            font-family: 'Poppins', sans-serif;
            font-weight: 800;
            font-size: 2.2rem;
            color: var(--text-dark);
            margin-bottom: 2rem;
            position: relative;
            padding-bottom: 1rem;
        }

        .overall-card h1::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 80px;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-green), var(--accent-gold));
            border-radius: 2px;
        }

        /* Enhanced Entry Cards */
        .entry {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 16px;
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            background: var(--bg-white);
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .entry::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 6px;
            transition: var(--transition);
        }

        .entry:hover {
            transform: translateX(8px);
            box-shadow: var(--shadow-lg);
        }

        /* Rank-specific borders and effects */
        .maroon-border::before { background: linear-gradient(180deg, #800000, #a00000); }
        .yellow-border::before { background: linear-gradient(180deg, #FFD700, #FFA500); }
        .green-border::before { background: linear-gradient(180deg, #28a745, #20c997); }
        .skyblue-border::before { background: linear-gradient(180deg, #87CEEB, #4682B4); }
        .blue-border::before { background: linear-gradient(180deg, #0d6efd, #0a58ca); }
        .default-border::before { background: linear-gradient(180deg, #6c757d, #495057); }

        .left {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .rank-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: white;
            font-weight: 800;
            box-shadow: var(--shadow-md);
            position: relative;
        }

        .rank-icon::after {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 50%;
            background: inherit;
            opacity: 0.2;
            z-index: -1;
        }

        .maroon-bg { background: linear-gradient(135deg, #800000, #a00000); }
        .yellow-bg { background: linear-gradient(135deg, #FFD700, #FFA500); }
        .green-bg { background: linear-gradient(135deg, #28a745, #20c997); }
        .skyblue-bg { background: linear-gradient(135deg, #87CEEB, #4682B4); }
        .blue-bg { background: linear-gradient(135deg, #0d6efd, #0a58ca); }
        .default-bg { background: linear-gradient(135deg, #6c757d, #495057); }

        .school-logo {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid #f0f0f0;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .entry:hover .school-logo {
            transform: scale(1.1);
            border-color: var(--primary-green);
        }

        .college-code {
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--text-dark);
            font-family: 'Poppins', sans-serif;
        }

        .badge {
            padding: 0.4rem 0.9rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.75rem;
            box-shadow: var(--shadow-sm);
        }

        /* Enhanced Medal Display */
        .right {
            display: flex;
            align-items: center;
            gap: 2.5rem;
        }

        .medal-col {
            text-align: center;
            min-width: 70px;
            transition: var(--transition);
        }

        .medal-col:hover {
            transform: translateY(-5px);
        }

        .medal-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.4rem;
            margin-bottom: 0.6rem;
        }

        .medal-icon-wrapper {
            position: relative;
            display: inline-block;
        }

        .medal-header img {
            width: 32px;
            height: 32px;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
            transition: var(--transition);
        }

        .medal-col:hover .medal-header img {
            transform: scale(1.2) rotate(10deg);
        }

        .medal-label {
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .gold-text { 
            color: var(--accent-gold); 
            text-shadow: 0 1px 2px rgba(255, 215, 0, 0.3);
        }
        .silver-text { 
            color: var(--accent-silver);
            text-shadow: 0 1px 2px rgba(192, 192, 192, 0.3);
        }
        .bronze-text { 
            color: var(--accent-bronze);
            text-shadow: 0 1px 2px rgba(205, 127, 50, 0.3);
        }
        .total-text { 
            color: var(--primary-green);
            font-weight: 900;
            text-shadow: 0 1px 2px rgba(76, 175, 80, 0.3);
        }

        .medal-count {
            font-size: 2rem;
            font-weight: 800;
            color: var(--text-dark);
            font-family: 'Poppins', sans-serif;
            line-height: 1;
            text-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .medal-col.total .medal-count {
            font-size: 2.2rem;
            color: var(--primary-green);
        }

        /* Footer Enhancement */
        footer {
            flex-shrink: 0;
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            color: white;
            padding: 2rem 0;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.15);
            position: relative;
            z-index: 1;
        }

        footer small {
            font-weight: 500;
            opacity: 0.9;
        }

        /* Print Styles */
        .print-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.85);
            z-index: 9999;
            backdrop-filter: blur(5px);
        }
        
        .print-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 2.5rem;
            border-radius: 16px;
            max-width: 90%;
            max-height: 90%;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
        }
        
        .print-header {
            text-align: center;
            margin-bottom: 2rem;
            border-bottom: 3px solid var(--primary-green);
            padding-bottom: 1.5rem;
        }

        .print-header h1 {
            font-family: 'Poppins', sans-serif;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .print-header h2 {
            color: var(--primary-green);
            font-weight: 600;
        }
        
        .print-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
        }
        
        .print-table th,
        .print-table td {
            border: 1px solid #dee2e6;
            padding: 1rem;
            text-align: center;
        }
        
        .print-table th {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-dark));
            color: white;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
        }

        .print-table tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .print-actions {
            text-align: center;
            margin-top: 2rem;
            display: flex;
            gap: 1rem;
            justify-content: center;
        }
        
        .print-actions button {
            padding: 0.9rem 2rem;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 50px;
            border: none;
            transition: var(--transition);
        }

        .print-actions .btn-primary {
            background: linear-gradient(135deg, var(--primary-green), var(--primary-dark));
        }

        .print-actions button:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        @media print {
            .print-overlay {
                display: block !important;
                position: static;
                background: none;
            }
            
            .print-content {
                position: static;
                transform: none;
                max-width: none;
                max-height: none;
                box-shadow: none;
                overflow: visible;
            }
            
            .print-actions {
                display: none;
            }
            
            body > *:not(.print-overlay) {
                display: none;
            }
        }
        
        /* Responsive Design */
        @media (max-width: 991px) {
            .section-header {
                flex-direction: column;
            }

            .section-header h1 {
                font-size: 2rem;
                text-align: center;
            }

            .entry {
                flex-direction: column;
                align-items: flex-start;
                padding: 1.5rem;
            }

            .right {
                width: 100%;
                justify-content: space-around;
                gap: 1rem;
                margin-top: 1.5rem;
            }

            .medal-col {
                min-width: 60px;
            }

            .medal-count {
                font-size: 1.6rem;
            }

            .medal-header img {
                width: 24px;
                height: 24px;
            }

            .college-code {
                font-size: 1.1rem;
            }

            .left {
                width: 100%;
            }
        }

        @media (max-width: 576px) {
            .live-standings-section,
            .overall-card {
                padding: 1.5rem;
            }

            .section-header img {
                height: 60px;
            }

            .rank-icon {
                width: 60px;
                height: 60px;
            }

            .school-logo {
                width: 65px;
                height: 65px;
            }

            .right {
                gap: 0.75rem;
            }

            .medal-count {
                font-size: 1.4rem;
            }
        }
    </style>
</head>
<body>
    <?php
    // Get the current page name
    $current_page = basename($_SERVER['PHP_SELF']);
    ?>
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

    <div class="main-content">

        <div class="container live-standings-section">
            <div class="section-header">
                <img src="images/SIGLAKASTEST.png" alt="Logo">
                <h1>Live Medal Standings</h1>
            </div>
            <div class="d-flex justify-content-center w-100 position-relative mb-4">
                <div class="last-updated" id="last-updated-display">
                    <i class="far fa-clock me-2"></i>Updated on <?= $formattedTime ?>
                </div>
                <span id="refresh-timer" class="badge bg-danger position-absolute end-0 top-50 translate-middle-y"></span>
            </div>

            <table class="table standings-table">
                <thead>
                    <tr>
                        <th class="text-center">Rank</th>
                        <th class="text-center">College Name</th>
                        <th class="text-center">
                            <div class="d-flex justify-content-center align-items-center gap-2">
                                <img src="gold.png" alt="Gold" style="width: 22px; height: 22px;">
                                <span>Gold</span>
                            </div>
                        </th>
                        <th class="text-center">
                            <div class="d-flex justify-content-center align-items-center gap-2">
                                <img src="silver.png" alt="Silver" style="width: 22px; height: 22px;">
                                <span>Silver</span>
                            </div>
                        </th>
                        <th class="text-center">
                            <div class="d-flex justify-content-center align-items-center gap-2">
                                <img src="bronze.png" alt="Bronze" style="width: 22px; height: 22px;">
                                <span>Bronze</span>
                            </div>
                        </th>
                        <th class="text-center">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($medal_tally) === 0): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">No medal data yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php $rank = 1; ?>
                        <?php foreach ($medal_tally as $tally): 
                            $college_code_for_logo = $college_name_to_code_map[trim($tally['college'])] ?? 'default';
                            $logo_file_for_display = $college_logos[$college_code_for_logo] ?? 'default.png';
                        ?>
                            <tr>
                                <td class="text-center">
                                    <div class="rank-badge <?= $rank <= 3 ? 'top-3' : '' ?>">
                                        <?= $rank++; ?>
                                    </div>
                                </td>
                                <td class="text-start"> 
                                    <div class="college-info">
                                        <img src="images/<?= htmlspecialchars($logo_file_for_display) ?>" 
                                            alt="<?= htmlspecialchars($tally['team_name']) ?>" 
                                            class="college-logo-table">
                                        <strong><?= htmlspecialchars($tally['team_name']) ?></strong>
                                    </div>
                                </td>
                                <td class="text-center medal-count-table gold-text"><?= $tally['gold']; ?></td>
                                <td class="text-center medal-count-table silver-text"><?= $tally['silver']; ?></td>
                                <td class="text-center medal-count-table bronze-text"><?= $tally['bronze']; ?></td>
                                <td class="text-center medal-count-table" style="color: var(--primary-green); font-weight: 800;"><?= $tally['total']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="text-center mt-4">
                <button class="btn print-btn" onclick="showPrintPreview()">
                    <i class="fas fa-print me-2"></i>Print Results
                </button>
            </div>
        </div>

        <div class="container py-5 overall-card">
        <h1><i class="fas fa-trophy me-3" style="color: var(--accent-gold);"></i>Overall Standings</h1>
        
        <!-- WRAPPER DIV FOR JAVASCRIPT UPDATES -->
        <div id="overall-standings-list">
            <?php 
            $rank = 1; 
            ?>
            <?php foreach ($medal_tally as $tally_row): ?>
                <?php 
                [ $border, $bg, $icon_class, $label ] = rankMeta($rank);
                $college_code = $college_name_to_code_map[trim($tally_row['college'])] ?? 'N/A';
                $logo_file = $college_logos[$college_code] ?? 'default.png';
                ?>
                <div class="entry <?= $border ?>">

                    <div class="left">
                        <div class="rank-icon <?= $bg ?>">
                            <?php if ($rank === 1): ?>
                                <img src="trophy1.svg" alt="Champion Trophy" style="width: 45px; height: 45px;">
                            <?php elseif ($rank === 2): ?>
                                <img src="secondplace.svg" alt="1st Runner-up" style="width: 45px; height: 45px;">
                            <?php elseif ($rank === 3): ?>
                                <img src="thirdplace.svg" alt="2nd Runner-up" style="width: 45px; height: 45px;">
                            <?php elseif ($rank === 4): ?>
                                <img src="4.png" alt="3rd Runner-up" style="width: 50px; height: 50px;">
                            <?php elseif ($rank === 5): ?>
                                <img src="5htplace.svg" alt="4th Runner-up" style="width: 50px; height: 50px;">
                            <?php else: ?>
                                <?= $rank ?>
                            <?php endif; ?>
                        </div>
                        
                        <img src="images/<?= htmlspecialchars($logo_file) ?>" 
                            alt="<?= htmlspecialchars($tally_row['team_name']) ?>" 
                            class="school-logo">

                        <div>
                            <div>
                                <strong class="college-code"><?= htmlspecialchars($college_code) ?></strong>
                                <span class="badge bg-light text-dark ms-2"><?= $label ?></span>
                            </div>
                            <small class="text-muted" style="font-size: 0.9rem;"><?= htmlspecialchars($tally_row['team_name']) ?></small>
                        </div>
                    </div>

                    <div class="right">
                        <div class="medal-col gold">
                            <div class="medal-header">
                                <div class="medal-icon-wrapper">
                                    <img src="gold.png" alt="Gold">
                                </div>
                                <span class="medal-label gold-text">Gold</span>
                            </div>
                            <div class="medal-count">
                                <?= $tally_row['gold'] ?>
                            </div>
                        </div>
                        <div class="medal-col silver">
                            <div class="medal-header">
                                <div class="medal-icon-wrapper">
                                    <img src="silver.png" alt="Silver">
                                </div>
                                <span class="medal-label silver-text">Silver</span>
                            </div>
                            <div class="medal-count">
                                <?= $tally_row['silver'] ?>
                            </div>
                        </div>
                        <div class="medal-col bronze">
                            <div class="medal-header">
                                <div class="medal-icon-wrapper">
                                    <img src="bronze.png" alt="Bronze">
                                </div>
                                <span class="medal-label bronze-text">Bronze</span>
                            </div>
                            <div class="medal-count">
                                <?= $tally_row['bronze'] ?>
                            </div>
                        </div>
                        <div class="medal-col total">
                            <div class="medal-header">
                                <span class="medal-label total-text">Total</span>
                            </div>
                            <div class="medal-count">
                                <?= $tally_row['total'] ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php $rank++; ?>
            <?php endforeach; ?>

            <?php if (count($medal_tally) === 0): ?>
                <div class="entry">
                    <p class="text-center text-muted m-0 w-100">No medal standings to display yet.</p>
                </div>
            <?php endif; ?>
        </div>
        <!-- END WRAPPER DIV -->
        
    </div>
    </div>

    <div class="print-overlay" id="printOverlay">
        <div class="print-content">
            <div class="print-header">
                <h1>PIT SPORTS TALLYING</h1>
                <h2>Live Medal Standings</h2>
                <p><strong>Generated on:</strong> <?php echo date('F d, Y - g:i A'); ?></p>
            </div>
            
            <table class="print-table">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>College Name</th>
                        <th>Gold</th>
                        <th>Silver</th>
                        <th>Bronze</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($medal_tally) === 0): ?>
                        <tr>
                            <td colspan="6">No medal data available</td>
                        </tr>
                    <?php else: ?>
                        <?php $rank = 1; ?>
                        <?php foreach ($medal_tally as $tally): ?>
                            <tr>
                                <td><?php echo $rank++; ?></td>
                                <td><?php echo htmlspecialchars($tally['team_name']); ?></td>
                                <td><?php echo $tally['gold']; ?></td>
                                <td><?php echo $tally['silver']; ?></td>
                                <td><?php echo $tally['bronze']; ?></td>
                                <td><?php echo $tally['total']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div class="print-actions">
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print me-2"></i>Print
                </button>
                <button class="btn btn-secondary" onclick="hidePrintPreview()">
                    <i class="fas fa-times me-2"></i>Close
                </button>
            </div>
        </div>
    </div>

    <footer class="bg-dark text-white py-3">
        <div class="text-center">
            <small>&copy; <?php echo date("Y"); ?> PIT SPORTS TALLYING. All rights reserved.</small><br>
            <small>Developed by Tsunayoshi Sawada</small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // --- Helper functions derived from your PHP context ---
    // These need to be present in the HTML above the script block as PHP variables
    const collegeNameCodeMap = <?= json_encode($college_name_to_code_map); ?>;
    const collegeLogos = <?= json_encode($college_logos); ?>;

    function getLogoFile(collegeName) {
        const code = collegeNameCodeMap[collegeName.trim()] || 'default';
        return collegeLogos[code] || 'default.png';
    }

    function rankMeta(rank) {
        switch (rank) {
            case 1:
                return ['maroon-border', 'maroon-bg', 'fas fa-trophy', 'Champion'];
            case 2:
                return ['yellow-border', 'yellow-bg', 'fas fa-medal', '1st Runner-up'];
            case 3:
                return ['green-border', 'green-bg', 'fas fa-award', '2nd Runner-up'];
            case 4:
                return ['skyblue-border', 'skyblue-bg', 'fas fa-award', '3rd Runner-up'];
            case 5:
                return ['blue-border', 'blue-bg', 'fas fa-award', '4th Runner-up'];
            default:
                return ['default-border', 'default-bg', 'fas fa-star', (rank) + 'th Place'];
        }
    }
    
    // --- REAL-TIME REFRESH LOGIC ---
    // --- REAL-TIME REFRESH LOGIC ---
    const REFRESH_INTERVAL = 15; // seconds
    let countdown = REFRESH_INTERVAL;
    let countdownInterval;

    // --- NEW: Store the initial 'last_updated' timestamp from the server ---
    // We use the raw PHP variable $lastUpdated here
    let currentLastUpdated = <?= json_encode($lastUpdated); ?>;

    /**
     * --- NEW: Plays the confetti animation for 5 seconds ---
     */
    function playConfetti() {
        // Add the class to show the confetti
        document.body.classList.add('show-confetti');
        
        // Set a timer to remove the class after 3 seconds
        setTimeout(() => {
            document.body.classList.remove('show-confetti');
        }, 3000); // 3000 milliseconds = 3 seconds
    }

    /**
     * Updates the main medal table and the detailed overall standings section.
     * @param {Array} newMedalTally - The new medal data array.
     * @param {string} lastUpdatedTime - The timestamp of the last update.
     */
    function updateStandings(newMedalTally, lastUpdatedTime) {
        const standingsBody = document.querySelector('.standings-table tbody');
        
        // --- FIX: Corrected the ID from 'overall-standings-container' to 'overall-standings-list'
        const overallListContainer = document.getElementById('overall-standings-list'); 
        
        const lastUpdatedEl = document.getElementById('last-updated-display');

        // --- FIX: Updated the guard clause to check for the correct variable
        if (!standingsBody || !overallListContainer || !lastUpdatedEl) {
            console.error("One or more required elements for updating standings were not found.");
            return; // Stop if any critical element is missing
        }

        // --- Update Last Updated Time ---
        if (lastUpdatedTime) {
            const date = new Date(lastUpdatedTime);
            // Formatting to match your PHP format
            const formatted = date.toLocaleDateString('en-US', {
                month: '2-digit', 
                day: '2-digit', 
                year: 'numeric'
            }) + ' at ' + date.toLocaleTimeString('en-US', {
                hour: '2-digit', 
                minute: '2-digit', 
                hour12: true
            }).toUpperCase();
            
            // Update the span content
            lastUpdatedEl.innerHTML = `<i class="far fa-clock me-2"></i>Updated on ${formatted}`;
        }

        let tableHtml = '';
        let overallHtml = '';
        let rank = 1;
        
        if (newMedalTally.length === 0) {
            tableHtml = '<tr><td colspan="6" class="text-center text-muted">No medal data yet.</td></tr>';
            overallHtml = '<div class="entry"><p class="text-center text-muted m-0 w-100">No medal standings to display yet.</p></div>';
        } else {
            // Re-render both sections
            newMedalTally.forEach(tally => {
                // Ensure necessary fields are present
                tally.team_name = tally.team_name || 'N/A';
                tally.college = tally.college || 'N/A';
                tally.gold = tally.gold || 0;
                tally.silver = tally.silver || 0;
                tally.bronze = tally.bronze || 0;
                
                // --- FIX: Ensured total is calculated correctly from the fetched data ---
                // The data from fetch_standings.php already has 'total', but it's safer to recalculate
                // in case the source changes. Let's use the fetched 'total'.
                const totalMedals = tally.total; // Use the total from the JSON payload

                const collegeCode = collegeNameCodeMap[tally.college.trim()] || 'default';
                const logoFile = collegeLogos[collegeCode] || 'default.png';
                const [borderClass, bgClass, iconClass, label] = rankMeta(rank);

                // 1. Table Row HTML
                tableHtml += `
                    <tr>
                        <td class="text-center">
                            <div class="rank-badge ${rank <= 3 ? 'top-3' : ''}">${rank}</div>
                        </td>
                        <td class="text-start"> 
                            <div class="college-info">
                                <img src="images/${logoFile}" alt="${tally.team_name}" class="college-logo-table">
                                <strong>${tally.team_name}</strong>
                            </div>
                        </td>
                        <td class="text-center medal-count-table gold-text">${tally.gold}</td>
                        <td class="text-center medal-count-table silver-text">${tally.silver}</td>
                        <td class="text-center medal-count-table bronze-text">${tally.bronze}</td>
                        <td class="text-center medal-count-table" style="color: var(--primary-green); font-weight: 800;">${totalMedals}</td>
                    </tr>
                `;

                // 2. Overall Standings Card HTML
                overallHtml += `
                    <div class="entry ${borderClass}">
                        <div class="left">
                            <div class="rank-icon ${bgClass}">
                                ${rank === 1 ? '<img src="trophy1.svg" alt="Champion Trophy" style="width: 45px; height: 45px;">' :
                                  rank === 2 ? '<img src="secondplace.svg" alt="1st Runner-up" style="width: 45px; height: 45px;">' :
                                  rank === 3 ? '<img src="thirdplace.svg" alt="2nd Runner-up" style="width: 45px; height: 45px;">' :
                                  rank === 4 ? '<img src="4.png" alt="3rd Runner-up" style="width: 50px; height: 50px;">' :
                                  rank === 5 ? '<img src="5htplace.svg" alt="4th Runner-up" style="width: 50px; height: 50px;">' :
                                  rank}
                            </div>
                            <img src="images/${logoFile}" alt="${tally.team_name}" class="school-logo">
                            <div>
                                <div>
                                    <strong class="college-code">${collegeCode}</strong>
                                    <span class="badge bg-light text-dark ms-2">${label}</span>
                                </div>
                                <small class="text-muted" style="font-size: 0.9rem;">${tally.team_name}</small>
                            </div>
                        </div>

                        <div class="right">
                            <div class="medal-col gold">
                                <div class="medal-header">
                                    <div class="medal-icon-wrapper"><img src="gold.png" alt="Gold"></div>
                                    <span class="medal-label gold-text">Gold</span>
                                </div>
                                <div class="medal-count">${tally.gold}</div>
                            </div>
                            <div class="medal-col silver">
                                <div class="medal-header">
                                    <div class="medal-icon-wrapper"><img src="silver.png" alt="Silver"></div>
                                    <span class="medal-label silver-text">Silver</span>
                                </div>
                                <div class="medal-count">${tally.silver}</div>
                            </div>
                            <div class="medal-col bronze">
                                <div class="medal-header">
                                    <div class="medal-icon-wrapper"><img src="bronze.png" alt="Bronze"></div>
                                    <span class="medal-label bronze-text">Bronze</span>
                                </div>
                                <div class="medal-count">${tally.bronze}</div>
                            </div>
                            <div class="medal-col total">
                                <div class="medal-header"><span class="medal-label total-text">Total</span></div>
                                <div class="medal-count">${totalMedals}</div>
                            </div>
                        </div>
                    </div>
                `;
                rank++;
            });
        }
        
        // Update DOM elements
        standingsBody.innerHTML = tableHtml;
        
        // --- FIX: This will now correctly find the element and update its content
        overallListContainer.innerHTML = overallHtml;
    }
        
        
    /**
     * Fetches the latest medal standings data from the PHP endpoint.
     */
    function fetchNewStandings() {
        fetch('fetch_standings.php?_=' + Date.now())
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                if (data.success && data.medal_tally) {
                    
                    // --- MODIFIED: Confetti Trigger Logic ---
                    // Check if the new timestamp from the fetch is different
                    // from the one we currently have stored.
                    if (data.last_updated && data.last_updated !== currentLastUpdated) {
                        console.log("Data has been updated! Playing confetti.");
                        
                        // Play the 5-second animation
                        playConfetti(); 
                        
                        // Update our stored timestamp to the new one
                        currentLastUpdated = data.last_updated; 
                    }
                    // --- End Modification ---

                    // Update the HTML tables and timestamp display
                    updateStandings(data.medal_tally, data.last_updated);

                } else {
                    console.error('Failed to get standings data:', data.error);
                }
            })
            .catch(error => {
                console.error('Error fetching standings:', error);
            });
    }

    /**
     * Initiates the 3-second countdown timer and periodic refresh cycle.
     */
    function startCountdown() {
        const timerEl = document.getElementById('refresh-timer');
        if (!timerEl) return;
        
        clearInterval(countdownInterval); // Clear any existing interval

        // Function to update the timer display
        const tick = () => {
            if (countdown <= 0) {
                timerEl.textContent = 'Refreshing...';
                countdown = REFRESH_INTERVAL; // Reset countdown
                fetchNewStandings(); // Fetch new data
            } else {
                timerEl.textContent = `Refresh in ${countdown}s`;
                countdown--;
            }
        };
        
        // Initial call and start interval
        countdown = REFRESH_INTERVAL; // Start counting from 3
        tick();
        countdownInterval = setInterval(tick, 1000);
    }

    document.addEventListener('DOMContentLoaded', function() {
        // --- NEW: Play confetti on initial page load ---
        playConfetti();

        // 🚨 Start the auto-refresh/countdown cycle immediately after the page loads
        startCountdown();

        // --- Existing Functions ---
        function showPrintPreview() {
            // ... (rest of the function)
            document.getElementById('printOverlay').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        function hidePrintPreview() {
            document.getElementById('printOverlay').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        window.showPrintPreview = showPrintPreview;
        window.hidePrintPreview = hidePrintPreview;

        document.getElementById('printOverlay').addEventListener('click', function(e) {
            if (e.target === this) {
                hidePrintPreview();
            }
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hidePrintPreview();
            }
        });
        
        document.querySelector('.interactive-brand').addEventListener('click', function(e) {
            e.preventDefault();
            window.location.reload();
        });
    });
</script>
</body>
</html>